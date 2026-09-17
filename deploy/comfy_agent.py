#!/usr/bin/env python3
"""Агент ComfyUI: запускается на домашнем компьютере рядом с ComfyUI.

Зачем он нужен. Завод стоит на сервере, а видеокарта — дома. Чтобы сервер сам
ходил в ComfyUI, пришлось бы выставить его в интернет: туннель, проброс порта,
белый IP — и вместе с этим чужие глаза на вашей машине. Агент переворачивает
связь: он сам спрашивает у завода работу и сам приносит результат. Наружу не
открывается ничего, потому что соединение всегда начинается изнутри.

Что делает по кругу:
  1. спрашивает у завода задание   GET  /api/comfy/next
  2. отдаёт граф в локальный ComfyUI POST /prompt
  3. ждёт и забирает файл           GET  /history, GET /view
  4. отправляет клип на сервер      POST /api/comfy/<id>/result
Дальше ffmpeg на сервере собирает из кадров шортс — как и с облачной генерацией.

Запуск:
    python comfy_agent.py --server https://завод --token ТОКЕН

Ничего ставить не нужно: только Python 3.9+, всё остальное — из стандартной
поставки. Граф приходит с сервера уже с подставленными значениями, поэтому
скрипт не надо обновлять, когда меняются правила подстановки.
"""
from __future__ import annotations

import argparse
import json
import mimetypes
import os
import socket
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
import uuid
from pathlib import Path

# Под какими ключами ComfyUI отдаёт результат. Видео-ноды исторически кладут
# файлы в "gifs" независимо от формата, поэтому смотрим все варианты.
OUTPUT_KEYS = ("videos", "gifs", "images")
VIDEO_EXT = (".mp4", ".webm", ".mov", ".mkv", ".gif")

# Пауза между опросами, когда работы нет. Чаще незачем: сервер всё равно ждёт
# кадр минутами, а лишние запросы только греют журнал.
IDLE_POLL = 5.0
# Пауза между опросами ComfyUI, пока он считает кадр.
BUSY_POLL = 3.0
# Сколько ждём один кадр от ComfyUI, прежде чем считать, что он завис.
RENDER_TIMEOUT = 1800.0
# После сетевой ошибки ждём дольше: сервер мог перезапускаться.
ERROR_PAUSE = 15.0
# Как часто говорить заводу «кадр ещё считается». Без этого он через полчаса
# решит, что нас выключили, и отдаст задание заново.
HEARTBEAT_EVERY = 60.0


def log(message: str) -> None:
    print(f"[{time.strftime('%H:%M:%S')}] {message}", flush=True)


# --------------------------------------------------------------------- сеть

def get_json(url: str, *, timeout: float = 60.0) -> dict:
    req = urllib.request.Request(url, headers={"Accept": "application/json"})
    with urllib.request.urlopen(req, timeout=timeout) as resp:
        return json.loads(resp.read().decode("utf-8") or "{}")


def post_json(url: str, body: dict, *, timeout: float = 60.0) -> dict:
    data = json.dumps(body).encode("utf-8")
    req = urllib.request.Request(url, data=data, method="POST",
                                 headers={"Content-Type": "application/json"})
    with urllib.request.urlopen(req, timeout=timeout) as resp:
        return json.loads(resp.read().decode("utf-8") or "{}")


def post_form(url: str, fields: dict, *, file: Path = None,
              timeout: float = 900.0) -> dict:
    """multipart/form-data руками — чтобы не тянуть requests на домашний ПК."""
    boundary = f"----cfagent{uuid.uuid4().hex}"
    body = bytearray()
    for name, value in fields.items():
        body += f"--{boundary}\r\n".encode()
        body += f'Content-Disposition: form-data; name="{name}"\r\n\r\n'.encode()
        body += f"{value}\r\n".encode()
    if file is not None:
        ctype = mimetypes.guess_type(file.name)[0] or "application/octet-stream"
        body += f"--{boundary}\r\n".encode()
        body += (f'Content-Disposition: form-data; name="file"; '
                 f'filename="{file.name}"\r\n').encode()
        body += f"Content-Type: {ctype}\r\n\r\n".encode()
        body += file.read_bytes()
        body += b"\r\n"
    body += f"--{boundary}--\r\n".encode()

    req = urllib.request.Request(url, data=bytes(body), method="POST", headers={
        "Content-Type": f"multipart/form-data; boundary={boundary}",
        "Content-Length": str(len(body)),
    })
    with urllib.request.urlopen(req, timeout=timeout) as resp:
        return json.loads(resp.read().decode("utf-8") or "{}")


def explain(exc: Exception) -> str:
    """Понятная строка вместо голого traceback — её же видно в панели."""
    if isinstance(exc, urllib.error.HTTPError):
        try:
            detail = exc.read().decode("utf-8", "replace")[:400]
        except Exception:  # noqa: BLE001
            detail = ""
        return f"HTTP {exc.code} {exc.reason} {detail}".strip()
    if isinstance(exc, urllib.error.URLError):
        return f"нет связи: {exc.reason}"
    if isinstance(exc, RuntimeError):
        # Свои ошибки уже написаны по-человечески — название класса тут лишнее.
        return str(exc)
    return f"{type(exc).__name__}: {exc}"


# ------------------------------------------------------------------ ComfyUI

# Ноды-загрузчики, по которым интересно посмотреть, какие модели вообще стоят.
MODEL_FIELDS = (
    ("CheckpointLoaderSimple", "ckpt_name", "чекпойнты"),
    ("UNETLoader", "unet_name", "UNET"),
    ("LoraLoader", "lora_name", "LoRA"),
    ("VAELoader", "vae_name", "VAE"),
    ("CLIPLoader", "clip_name", "CLIP"),
    ("CLIPVisionLoader", "clip_name", "CLIP Vision"),
)


class Comfy:
    def __init__(self, base: str) -> None:
        self.base = base.rstrip("/")
        self.client_id = str(uuid.uuid4())
        self._objects = None

    def url(self, path: str) -> str:
        return f"{self.base}/{path.lstrip('/')}"

    def ping(self) -> dict:
        return get_json(self.url("/system_stats"), timeout=20.0)

    def object_info(self) -> dict:
        """Что вообще установлено в этой сборке ComfyUI: ноды и их поля.

        Ответ большой (мегабайты) и за время работы не меняется, поэтому
        спрашиваем один раз и держим у себя.
        """
        if self._objects is None:
            self._objects = get_json(self.url("/object_info"), timeout=180.0) or {}
        return self._objects

    @staticmethod
    def _choices(spec: dict) -> dict:
        """Поля ноды, у которых значение выбирается из списка (модели, сэмплеры)."""
        found = {}
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

    def models(self) -> dict:
        """Какие модели видит ComfyUI — по типам загрузчиков."""
        known = self.object_info()
        out = {}
        for class_type, field, label in MODEL_FIELDS:
            spec = known.get(class_type)
            if not spec:
                continue
            options = self._choices(spec).get(field)
            if options:
                out[label] = options
        return out

    def preflight(self, graph: dict) -> tuple:
        """Сверяем граф с тем, что стоит: (чего нет совсем, что вызывает сомнения).

        Смысл — объяснить причину до запуска, а не после. ComfyUI и сам отвергнет
        такой граф, но его ответ надо ещё расшифровать, а сюда попадает понятная
        строка, которая уходит в панель завода как причина неудачи.
        """
        try:
            known = self.object_info()
        except Exception as exc:  # noqa: BLE001 — проверка не должна мешать работе
            log(f"  список нод не прочитан ({explain(exc)}) — проверку пропускаю")
            return [], []
        if not known:
            return [], []

        missing, doubts = [], []
        for node_id, node in (graph or {}).items():
            if not isinstance(node, dict):
                continue
            class_type = node.get("class_type")
            spec = known.get(class_type)
            if spec is None:
                missing.append(f"ноды «{class_type}» нет в этой сборке "
                               f"(узел {node_id})")
                continue
            choices = self._choices(spec)
            for name, value in (node.get("inputs") or {}).items():
                options = choices.get(name)
                # Связь с другой нодой приходит списком [узел, слот] — это не
                # значение поля, проверять нечего.
                if not options or not isinstance(value, str):
                    continue
                if value not in options:
                    near = ", ".join(options[:5]) or "ничего"
                    doubts.append(f"у «{class_type}» (узел {node_id}) в поле "
                                  f"{name} стоит «{value}», а есть: {near}")
        return missing, doubts

    def submit(self, graph: dict) -> str:
        data = post_json(self.url("/prompt"),
                         {"prompt": graph, "client_id": self.client_id})
        prompt_id = data.get("prompt_id")
        if not prompt_id:
            raise RuntimeError(f"ComfyUI не вернул prompt_id: {json.dumps(data)[:300]}")
        return str(prompt_id)

    def wait(self, prompt_id: str, *, timeout: float = RENDER_TIMEOUT,
             heartbeat=None) -> dict:
        deadline = time.time() + timeout
        last_beat = time.time()
        while time.time() < deadline:
            if heartbeat and time.time() - last_beat >= HEARTBEAT_EVERY:
                last_beat = time.time()
                heartbeat()
            data = get_json(self.url(f"/history/{prompt_id}")) or {}
            entry = data.get(prompt_id)
            if entry:
                status = entry.get("status") or {}
                if status.get("status_str") == "error":
                    messages = json.dumps(status.get("messages") or [],
                                          ensure_ascii=False)[:600]
                    raise RuntimeError(f"ComfyUI сообщил об ошибке: {messages}")
                if entry.get("outputs"):
                    return entry
            time.sleep(BUSY_POLL)
        raise RuntimeError(f"ComfyUI не закончил за {timeout:.0f} с")

    @staticmethod
    def outputs(entry: dict) -> list:
        found = []
        for node in (entry.get("outputs") or {}).values():
            for key in OUTPUT_KEYS:
                for item in node.get(key) or []:
                    if isinstance(item, dict) and item.get("filename"):
                        found.append(item)
        return found

    def download(self, item: dict, dest_dir: Path) -> Path:
        params = urllib.parse.urlencode({
            "filename": item.get("filename", ""),
            "subfolder": item.get("subfolder", ""),
            "type": item.get("type", "output"),
        })
        dest_dir.mkdir(parents=True, exist_ok=True)
        dest = dest_dir / Path(str(item.get("filename"))).name
        with urllib.request.urlopen(self.url(f"/view?{params}"), timeout=900) as resp:
            expected = int(resp.headers.get("content-length") or 0)
            got = 0
            with open(dest, "wb") as fh:
                while True:
                    chunk = resp.read(1 << 20)
                    if not chunk:
                        break
                    fh.write(chunk)
                    got += len(chunk)
        # Оборванная закачка выглядит целым файлом, и обрезанный клип уехал бы
        # в монтаж — лучше сразу признать неудачу.
        if expected and got < expected:
            dest.unlink(missing_ok=True)
            raise RuntimeError(f"файл скачан не полностью: {got} из {expected} байт")
        if got == 0:
            dest.unlink(missing_ok=True)
            raise RuntimeError("ComfyUI отдал пустой файл")
        return dest


# -------------------------------------------------------------------- агент

class Agent:
    def __init__(self, server: str, token: str, comfy: Comfy, *, name: str,
                 work_dir: Path, keep: bool) -> None:
        self.server = server.rstrip("/")
        self.token = token
        self.comfy = comfy
        self.name = name
        self.work_dir = work_dir
        self.keep = keep

    def next_task(self, *, peek: bool = False) -> dict:
        query = urllib.parse.urlencode({"token": self.token, "agent": self.name,
                                        "peek": 1 if peek else 0})
        data = get_json(f"{self.server}/api/comfy/next?{query}")
        if peek:
            # При peek завод ничего не выдаёт, только говорит, есть ли работа.
            return {"waiting": bool(data.get("waiting"))}
        return data.get("task") or {}

    def send_result(self, task_id: int, path: Path) -> None:
        post_form(f"{self.server}/api/comfy/{task_id}/result",
                  {"token": self.token}, file=path)

    def send_ping(self, task_id: int) -> None:
        """Кадр ещё считается — иначе завод решит, что нас выключили."""
        try:
            post_form(f"{self.server}/api/comfy/{task_id}/ping",
                      {"token": self.token}, timeout=30.0)
        except Exception as exc:  # noqa: BLE001 — из-за сердцебиения кадр не бросаем
            log(f"  сердцебиение не дошло: {explain(exc)}")

    def send_error(self, task_id: int, message: str) -> None:
        try:
            post_form(f"{self.server}/api/comfy/{task_id}/error",
                      {"token": self.token, "error": message[:2000]})
        except Exception as exc:  # noqa: BLE001 — об ошибке об ошибке только в журнал
            log(f"не удалось сообщить об ошибке: {explain(exc)}")

    def run_task(self, task: dict) -> None:
        task_id = int(task["id"])
        log(f"задание #{task_id}: {task.get('seconds')} с, "
            f"{task.get('width')}x{task.get('height')}, граф «{task.get('workflow')}»")
        log(f"  промпт: {str(task.get('prompt') or '')[:120]}")
        try:
            graph = task.get("graph") or {}
            missing, doubts = self.comfy.preflight(graph)
            for line in doubts:
                log(f"  под вопросом: {line}")
            if missing:
                raise RuntimeError("граф не совпал с вашей сборкой ComfyUI: "
                                   + "; ".join(missing[:4]))
            prompt_id = self.comfy.submit(graph)
            log(f"  ComfyUI принял: {prompt_id}")
            entry = self.comfy.wait(prompt_id,
                                    heartbeat=lambda: self.send_ping(task_id))
            items = self.comfy.outputs(entry)
            if not items:
                raise RuntimeError("ComfyUI отработал, но не отдал ни одного файла — "
                                   "проверьте, что в графе есть нода сохранения видео")
            # Некоторые графы попутно сохраняют превью-картинку, поэтому видео
            # выбираем явно, а не берём первое попавшееся.
            videos = [i for i in items
                      if str(i.get("filename", "")).lower().endswith(VIDEO_EXT)]
            path = self.comfy.download((videos or items)[0], self.work_dir)
            size_mb = path.stat().st_size / (1 << 20)
            log(f"  готово: {path.name}, {size_mb:.1f} МБ — отправляю на сервер")
            self.send_result(task_id, path)
            log(f"  задание #{task_id} закрыто")
        except Exception as exc:  # noqa: BLE001 — падать из-за одного кадра незачем
            message = explain(exc)
            log(f"  задание #{task_id} не вышло: {message}")
            self.send_error(task_id, message)
        finally:
            if not self.keep:
                # Клип уже на сервере — держать копию незачем, диск дома не резиновый.
                for leftover in self.work_dir.glob("*"):
                    if leftover.is_file():
                        leftover.unlink(missing_ok=True)

    def loop(self, *, once: bool = False) -> int:
        log(f"агент «{self.name}» на связи: завод {self.server}, "
            f"ComfyUI {self.comfy.base}")
        idle_said = False
        while True:
            try:
                task = self.next_task()
            except Exception as exc:  # noqa: BLE001
                log(f"завод не ответил: {explain(exc)} — повторю через {ERROR_PAUSE:.0f} с")
                time.sleep(ERROR_PAUSE)
                continue

            if not task:
                if not idle_said:
                    log("работы нет, жду")
                    idle_said = True
                if once:
                    return 0
                time.sleep(IDLE_POLL)
                continue

            idle_said = False
            self.run_task(task)
            if once:
                return 0


def main(argv: list) -> int:
    parser = argparse.ArgumentParser(
        description="Агент ComfyUI для контент-завода",
        formatter_class=argparse.RawDescriptionHelpFormatter,
        epilog="Токен берётся на странице «Локальный ComfyUI» в панели завода.")
    parser.add_argument("--server", default=os.environ.get("CF_SERVER", ""),
                        help="адрес завода, например https://factory.example.com")
    parser.add_argument("--token", default=os.environ.get("CF_TOKEN", ""),
                        help="токен агента из панели")
    parser.add_argument("--comfy", default=os.environ.get("CF_COMFY", "http://127.0.0.1:8188"),
                        help="адрес локального ComfyUI (по умолчанию %(default)s)")
    parser.add_argument("--name", default=socket.gethostname(),
                        help="как подписывать себя в журнале завода")
    parser.add_argument("--work-dir", default="", help="куда складывать клипы перед отправкой")
    parser.add_argument("--keep", action="store_true",
                        help="не удалять скачанные клипы после отправки")
    parser.add_argument("--once", action="store_true",
                        help="взять одно задание и выйти — удобно для проверки")
    parser.add_argument("--check", action="store_true",
                        help="только проверить связь с ComfyUI и заводом")
    parser.add_argument("--inspect", action="store_true",
                        help="показать, какие ноды и модели стоят в вашем ComfyUI")
    args = parser.parse_args(argv)

    # Осмотр своей же сборки — дело локальное, завод для него не нужен.
    if not args.inspect and (not args.server or not args.token):
        parser.error("нужны --server и --token (или переменные CF_SERVER и CF_TOKEN)")

    comfy = Comfy(args.comfy)
    work_dir = Path(args.work_dir) if args.work_dir else \
        Path.home() / ".contentfactory" / "comfy"

    if args.inspect:
        # Ровно тот список, по которому ComfyUI сверяет граф: имена нод и
        # значения полей-выпадашек. Из него и берутся названия для графа.
        try:
            known = comfy.object_info()
        except Exception as exc:  # noqa: BLE001
            log(f"ComfyUI недоступен по адресу {comfy.base}: {explain(exc)}")
            return 1
        log(f"нод установлено: {len(known)}")
        for label, options in comfy.models().items():
            log(f"{label} ({len(options)}): {', '.join(options[:12])}"
                + (" …" if len(options) > 12 else ""))
        if args.work_dir:
            dump = Path(args.work_dir) / "object_info.json"
            dump.parent.mkdir(parents=True, exist_ok=True)
            dump.write_text(json.dumps(known, ensure_ascii=False, indent=1),
                            encoding="utf-8")
            log(f"полный список нод сохранён: {dump}")
        return 0

    if args.check:
        ok = True
        try:
            stats = comfy.ping()
            device = (stats.get("devices") or [{}])[0].get("name", "?")
            log(f"ComfyUI отвечает, видеокарта: {device}")
        except Exception as exc:  # noqa: BLE001
            log(f"ComfyUI недоступен по адресу {comfy.base}: {explain(exc)}")
            ok = False
        try:
            agent = Agent(args.server, args.token, comfy, name=args.name,
                          work_dir=work_dir, keep=args.keep)
            # Смотрим очередь, не забирая задание: проверка не должна съедать
            # кадр, который потом ждёт монтаж.
            queue = agent.next_task(peek=True)
            log("завод отвечает, токен принят"
                + (", в очереди есть работа" if queue.get("waiting") else ", очередь пуста"))
        except Exception as exc:  # noqa: BLE001
            log(f"завод недоступен: {explain(exc)}")
            ok = False
        return 0 if ok else 1

    agent = Agent(args.server, args.token, comfy, name=args.name,
                  work_dir=work_dir, keep=args.keep)
    try:
        return agent.loop(once=args.once)
    except KeyboardInterrupt:
        log("остановлен вручную")
        return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
