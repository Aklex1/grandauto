"""Локальный ComfyUI как генератор видеокадров вместо облачного KIE.

Завод шлёт в ComfyUI готовый workflow, ждёт результат и забирает файл — дальше
всё как обычно: ffmpeg сшивает кадры, накладывает озвучку и субтитры. Озвучка,
обложки и сценарий остаются на KIE, локально считается только видеоряд.

Про подстановку промпта. Угадывать, в какую ноду workflow класть текст, —
гиблое дело: у каждого свои графы, и номера нод меняются при любой правке.
Поэтому подстановка идёт по плейсхолдерам: пользователь ставит в своём
workflow `%PROMPT%` там, где нужен текст, и мы заменяем его. Это работает с
любым графом и не ломается при перенумерации нод.
"""
from __future__ import annotations

import json
import logging
import random
import time
import uuid
from pathlib import Path
from typing import Any, Optional

import httpx

log = logging.getLogger("cf.comfy")

# Что подставляем в workflow. Пользователь расставляет эти метки сам.
PLACEHOLDERS = {
    "%PROMPT%": "текст промпта для кадра",
    "%NEGATIVE%": "негативный промпт",
    "%SECONDS%": "длительность клипа в секундах",
    "%FRAMES%": "длительность в кадрах (секунды × fps)",
    "%WIDTH%": "ширина кадра",
    "%HEIGHT%": "высота кадра",
    "%SEED%": "случайное зерно",
}

# Ключи, под которыми ComfyUI отдаёт результат. Видео-ноды исторически кладут
# файлы в "gifs" независимо от формата, поэтому смотрим все варианты.
OUTPUT_KEYS = ("videos", "gifs", "images")

VIDEO_EXT = (".mp4", ".webm", ".mov", ".mkv", ".gif")


class ComfyError(RuntimeError):
    pass


def frame_count(seconds: float, fps: int, step: int = 1) -> int:
    """Сколько кадров просить у модели.

    Видеомодели принимают не любую длину: WAN и Hunyuan хотят 4n+1, LTX — 8n+1.
    Просто округлить секунды на fps мало — 120 кадров вместо 121 такая модель
    либо отвергнет, либо молча посчитает другой отрезок.
    """
    raw = max(1, int(round(seconds * max(1, fps))))
    if step <= 1:
        return raw
    return max(1, int(round((raw - 1) / step)) * step + 1)


def fill(workflow: Any, *, prompt: str, seconds: float, width: int, height: int,
         fps: int = 30, negative: str = "", seed: Optional[int] = None,
         frame_step: int = 1) -> Any:
    """Подставляем значения вместо плейсхолдеров во всём графе.

    Обходим структуру целиком: метка может стоять и в строке, и внутри числа
    (тогда строку приводим к числу — ComfyUI строгий к типам своих входов).
    """
    values = {
        "%PROMPT%": prompt,
        "%NEGATIVE%": negative,
        "%SECONDS%": seconds,
        "%FRAMES%": frame_count(seconds, fps, frame_step),
        "%WIDTH%": int(width),
        "%HEIGHT%": int(height),
        "%SEED%": int(seed if seed is not None else random.randint(1, 2**31 - 1)),
    }

    def walk(node: Any) -> Any:
        if isinstance(node, dict):
            return {k: walk(v) for k, v in node.items()}
        if isinstance(node, list):
            return [walk(v) for v in node]
        if isinstance(node, str):
            # Строка целиком равна метке — отдаём значение своим типом, иначе
            # число уедет в ноду строкой и ComfyUI откажется её принимать.
            stripped = node.strip()
            if stripped in values:
                return values[stripped]
            out = node
            for mark, value in values.items():
                if mark in out:
                    out = out.replace(mark, str(value))
            return out
        return node

    return walk(workflow)


def node_types(workflow: Any) -> list[str]:
    """Какие ноды просит граф — чтобы в панели было видно, что должно стоять."""
    if not isinstance(workflow, dict):
        return []
    seen: list[str] = []
    for node in workflow.values():
        if isinstance(node, dict):
            name = str(node.get("class_type") or "").strip()
            if name and name not in seen:
                seen.append(name)
    return seen


def used_placeholders(workflow: Any) -> list[str]:
    """Какие метки реально встречаются в workflow — для подсказки в панели."""
    blob = json.dumps(workflow, ensure_ascii=False)
    return [mark for mark in PLACEHOLDERS if mark in blob]


class ComfyClient:
    """Минимальный клиент ComfyUI: отправить граф, дождаться, забрать файл."""

    def __init__(self, base_url: str, *, timeout: float = 60.0,
                 client_id: str = "") -> None:
        self.base = (base_url or "").rstrip("/")
        if not self.base:
            raise ComfyError("не задан адрес ComfyUI")
        self.timeout = timeout
        self.client_id = client_id or str(uuid.uuid4())
        self._objects: Optional[dict] = None

    def _url(self, path: str) -> str:
        return f"{self.base}/{path.lstrip('/')}"

    def ping(self) -> dict:
        """Проверка доступности. Возвращает сведения о системе."""
        with httpx.Client(timeout=min(self.timeout, 20.0)) as client:
            resp = client.get(self._url("/system_stats"))
            resp.raise_for_status()
            return resp.json()

    def object_info(self) -> dict:
        """Что установлено в этой сборке ComfyUI: ноды и их поля.

        Ответ большой и за время сборки не меняется — спрашиваем один раз.
        """
        if self._objects is None:
            with httpx.Client(timeout=max(self.timeout, 180.0)) as client:
                resp = client.get(self._url("/object_info"))
                resp.raise_for_status()
                self._objects = resp.json() or {}
        return self._objects

    @staticmethod
    def _choices(spec: dict) -> dict:
        """Поля ноды, значение которых выбирается из списка (модели, сэмплеры)."""
        found: dict[str, list[str]] = {}
        inputs = spec.get("input") or {}
        for group in ("required", "optional"):
            for name, decl in (inputs.get(group) or {}).items():
                options = None
                if isinstance(decl, list) and decl:
                    if isinstance(decl[0], list):
                        options = decl[0]
                    elif isinstance(decl[0], dict):
                        options = decl[0].get("options")
                if options:
                    found[name] = [str(o) for o in options]
        return found

    def preflight(self, workflow: Any) -> tuple[list[str], list[str]]:
        """Сверяем граф с установленным: (чего нет совсем, что под вопросом).

        Смысл — объяснить причину до запуска. ComfyUI и сам отвергнет такой граф,
        но его ответ надо расшифровывать, а тут получается понятная строка.
        """
        try:
            known = self.object_info()
        except Exception as exc:  # noqa: BLE001 — проверка не должна мешать работе
            log.warning("Список нод ComfyUI не прочитан, проверку пропускаю: %s", exc)
            return [], []
        if not known or not isinstance(workflow, dict):
            return [], []

        missing: list[str] = []
        doubts: list[str] = []
        for node_id, node in workflow.items():
            if not isinstance(node, dict):
                continue
            class_type = node.get("class_type")
            spec = known.get(class_type)
            if spec is None:
                missing.append(f"ноды «{class_type}» нет в этой сборке (узел {node_id})")
                continue
            choices = self._choices(spec)
            for name, value in (node.get("inputs") or {}).items():
                options = choices.get(name)
                # Связь с другой нодой приходит списком [узел, слот] — не значение.
                if not options or not isinstance(value, str):
                    continue
                if value not in options:
                    near = ", ".join(options[:5]) or "ничего"
                    doubts.append(f"у «{class_type}» (узел {node_id}) в поле {name} "
                                  f"стоит «{value}», а есть: {near}")
        return missing, doubts

    def submit(self, workflow: Any) -> str:
        body = {"prompt": workflow, "client_id": self.client_id}
        with httpx.Client(timeout=self.timeout) as client:
            resp = client.post(self._url("/prompt"), json=body)
            if resp.status_code >= 400:
                # ComfyUI объясняет отказ подробно — эта часть ответа и нужна.
                raise ComfyError(f"ComfyUI отклонил граф: {resp.status_code} "
                                 f"{resp.text[:600]}")
            data = resp.json()
        prompt_id = data.get("prompt_id")
        if not prompt_id:
            raise ComfyError(f"ComfyUI не вернул prompt_id: {json.dumps(data)[:300]}")
        return str(prompt_id)

    def history(self, prompt_id: str) -> dict:
        with httpx.Client(timeout=self.timeout) as client:
            resp = client.get(self._url(f"/history/{prompt_id}"))
            resp.raise_for_status()
            return resp.json() or {}

    def wait(self, prompt_id: str, *, timeout: float = 1800.0,
             poll: float = 3.0) -> dict:
        """Ждём, пока граф отработает. Возвращает запись истории."""
        deadline = time.time() + timeout
        while time.time() < deadline:
            data = self.history(prompt_id)
            entry = data.get(prompt_id)
            if entry:
                status = (entry.get("status") or {})
                if status.get("status_str") == "error" or status.get("completed") is False:
                    messages = json.dumps(status.get("messages") or [],
                                          ensure_ascii=False)[:600]
                    raise ComfyError(f"ComfyUI сообщил об ошибке: {messages}")
                if entry.get("outputs"):
                    return entry
            time.sleep(poll)
        raise ComfyError(f"ComfyUI не закончил за {timeout:.0f} с")

    def outputs(self, entry: dict) -> list[dict]:
        """Файлы из результата: [{filename, subfolder, type}, …]."""
        found: list[dict] = []
        for node in (entry.get("outputs") or {}).values():
            for key in OUTPUT_KEYS:
                for item in node.get(key) or []:
                    if isinstance(item, dict) and item.get("filename"):
                        found.append(item)
        return found

    def download(self, item: dict, dest: Path) -> Path:
        params = {"filename": item.get("filename", ""),
                  "subfolder": item.get("subfolder", ""),
                  "type": item.get("type", "output")}
        dest.parent.mkdir(parents=True, exist_ok=True)
        with httpx.Client(timeout=max(self.timeout, 300.0)) as client:
            with client.stream("GET", self._url("/view"), params=params) as resp:
                resp.raise_for_status()
                expected = int(resp.headers.get("content-length") or 0)
                got = 0
                with open(dest, "wb") as fh:
                    for chunk in resp.iter_bytes(chunk_size=1 << 20):
                        fh.write(chunk)
                        got += len(chunk)
        # Та же беда, что и с облачными файлами: оборванная закачка выглядит
        # целым файлом, и обрезанный клип уезжает в монтаж.
        if expected and got < expected:
            dest.unlink(missing_ok=True)
            raise ComfyError(f"файл скачан не полностью: {got} из {expected} байт")
        if got == 0:
            dest.unlink(missing_ok=True)
            raise ComfyError("ComfyUI отдал пустой файл")
        return dest

    def render(self, workflow: Any, dest: Path, *, prompt: str, seconds: float,
               width: int, height: int, fps: int = 30, negative: str = "",
               seed: Optional[int] = None, timeout: float = 1800.0,
               poll: float = 3.0, frame_step: int = 1) -> Path:
        """Полный цикл: подставить, отправить, дождаться, скачать видео."""
        graph = fill(workflow, prompt=prompt, seconds=seconds, width=width,
                     height=height, fps=fps, negative=negative, seed=seed,
                     frame_step=frame_step)
        missing, doubts = self.preflight(graph)
        for line in doubts:
            log.warning("ComfyUI, под вопросом: %s", line)
        if missing:
            raise ComfyError("граф не совпал с вашей сборкой ComfyUI: "
                             + "; ".join(missing[:4]))
        prompt_id = self.submit(graph)
        log.info("ComfyUI принял задачу %s (%.1f с, %dx%d)", prompt_id, seconds,
                 width, height)
        entry = self.wait(prompt_id, timeout=timeout, poll=poll)
        items = self.outputs(entry)
        if not items:
            raise ComfyError("ComfyUI отработал, но не отдал ни одного файла — "
                             "проверьте, что в графе есть нода сохранения видео")

        # Предпочитаем видео: некоторые графы попутно сохраняют превью-картинку.
        videos = [i for i in items
                  if str(i.get("filename", "")).lower().endswith(VIDEO_EXT)]
        item = (videos or items)[0]
        suffix = Path(str(item.get("filename"))).suffix or ".mp4"
        return self.download(item, dest.with_suffix(suffix))
