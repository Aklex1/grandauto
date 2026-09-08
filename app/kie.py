"""Клиент KIE API: задачи генерации, чат-модели, прайс-лист, загрузка файлов."""
from __future__ import annotations

import json
import logging
import time
from typing import Any, Optional

import httpx

from . import config

log = logging.getLogger("cf.kie")

JOBS_CREATE = "/api/v1/jobs/createTask"
JOBS_INFO = "/api/v1/jobs/recordInfo"
CREDIT = "/api/v1/chat/credit"
PRICING = "/client/v1/model-pricing/page"
MODEL_PATHS = "/api/v1/playground/model-paths"
DOWNLOAD_URL = "/api/v1/common/download-url"
UPLOAD_BASE64 = "/api/file-base64-upload"
UPLOAD_URL = "/api/file-url-upload"

TERMINAL_OK = {"success"}
TERMINAL_FAIL = {"fail", "failed", "error"}


class KieError(RuntimeError):
    """Ошибка вызова KIE API."""


def _api_key(override: Optional[str] = None) -> str:
    key = (override or config.KIE_API_KEY or "").strip()
    if not key:
        raise KieError("KIE API-ключ не задан")
    return key


class KieClient:
    def __init__(self, api_key: Optional[str] = None, base: Optional[str] = None, timeout: float = 120.0):
        self.api_key = _api_key(api_key)
        self.base = (base or config.KIE_BASE).rstrip("/")
        self.timeout = timeout

    # ------------------------------------------------------------------ низкий уровень
    def _headers(self) -> dict:
        return {"Authorization": f"Bearer {self.api_key}", "Content-Type": "application/json"}

    def _request(self, method: str, path: str, *, json_body: Any = None, params: dict | None = None,
                 timeout: float | None = None) -> dict:
        url = path if path.startswith("http") else f"{self.base}{path}"
        last: Exception | None = None
        for attempt in range(3):
            try:
                with httpx.Client(timeout=timeout or self.timeout) as client:
                    resp = client.request(method, url, headers=self._headers(), json=json_body, params=params)
                if resp.status_code == 429:
                    time.sleep(3 * (attempt + 1))
                    last = KieError("429 rate limit")
                    continue
                if resp.status_code >= 500:
                    last = KieError(f"HTTP {resp.status_code}: {resp.text[:200]}")
                    time.sleep(2 * (attempt + 1))
                    continue
                if resp.status_code >= 400:
                    raise KieError(f"HTTP {resp.status_code}: {resp.text[:300]}")
                return resp.json()
            except httpx.HTTPError as exc:
                last = exc
                time.sleep(2 * (attempt + 1))
        raise KieError(f"Запрос к {url} не удался: {last}")

    # ------------------------------------------------------------------ аккаунт и справочники
    def credits(self) -> float:
        data = self._request("GET", CREDIT, timeout=30)
        if data.get("code") != 200:
            raise KieError(f"credit: {data.get('msg')}")
        return float(data.get("data") or 0)

    def model_paths(self) -> list[str]:
        data = self._request("GET", MODEL_PATHS, timeout=45)
        return list(data.get("data") or [])

    def pricing(self, page_size: int = 100) -> list[dict]:
        """Полный прайс-лист KIE (постранично, лимит страницы — 100)."""
        out: list[dict] = []
        page = 1
        while page <= 40:
            data = self._request("POST", PRICING, json_body={
                "pageNum": page, "pageSize": page_size, "modelDescription": "", "interfaceType": "",
            }, timeout=60)
            payload = data.get("data") or {}
            records = payload.get("records") or []
            out.extend(records)
            if page >= int(payload.get("pages") or 1):
                break
            page += 1
        return out

    # ------------------------------------------------------------------ задачи генерации
    def create_task(self, model: str, payload: dict, callback_url: str | None = None) -> str:
        body: dict[str, Any] = {"model": model, "input": payload}
        if callback_url:
            body["callBackUrl"] = callback_url
        data = self._request("POST", JOBS_CREATE, json_body=body)
        if data.get("code") != 200:
            raise KieError(f"createTask({model}): {data.get('code')} {data.get('msg')}")
        task_id = (data.get("data") or {}).get("taskId")
        if not task_id:
            raise KieError(f"createTask({model}): нет taskId в ответе")
        return task_id

    def task_info(self, task_id: str) -> dict:
        data = self._request("GET", JOBS_INFO, params={"taskId": task_id}, timeout=45)
        return data.get("data") or {}

    def wait_task(self, task_id: str, *, poll: float = 5.0, timeout: float = 1800.0,
                  on_tick=None) -> dict:
        """Ждём завершения задачи. Возвращает распарсенный resultJson."""
        deadline = time.time() + timeout
        while time.time() < deadline:
            info = self.task_info(task_id)
            state = (info.get("state") or "").lower()
            if on_tick:
                on_tick(state, info)
            if state in TERMINAL_OK:
                raw = info.get("resultJson") or "{}"
                try:
                    result = json.loads(raw) if isinstance(raw, str) else raw
                except ValueError:
                    result = {"raw": raw}
                result["_credits"] = float(info.get("creditsConsumed") or 0)
                return result
            if state in TERMINAL_FAIL:
                raise KieError(f"Задача {task_id} провалена: {info.get('failCode')} {info.get('failMsg')}")
            time.sleep(poll)
        raise KieError(f"Задача {task_id} не завершилась за {timeout:.0f}с")

    def run_task(self, model: str, payload: dict, *, timeout: float = 1800.0, poll: float = 5.0) -> dict:
        return self.wait_task(self.create_task(model, payload), poll=poll, timeout=timeout)

    # ------------------------------------------------------------------ чат-модели
    def chat(self, model: str, messages: list[dict], *, temperature: float | None = None,
             max_tokens: int | None = None, timeout: float = 300.0) -> tuple[str, float]:
        """OpenAI-совместимый вызов чат-модели. Возвращает (текст, потраченные кредиты)."""
        body: dict[str, Any] = {"model": model, "messages": messages}
        if temperature is not None:
            body["temperature"] = temperature
        if max_tokens is not None:
            body["max_tokens"] = max_tokens
        path = f"{self.base}/{model.strip('/')}/v1/chat/completions"
        data = self._request("POST", path, json_body=body, timeout=timeout)
        choices = data.get("choices") or []
        if not choices:
            raise KieError(f"chat({model}): пустой ответ {json.dumps(data)[:300]}")
        log.debug("chat(%s): finish_reason=%s", model, choices[0].get("finish_reason"))
        text = (choices[0].get("message") or {}).get("content") or ""
        return text, float(data.get("credits_consumed") or 0)

    def chat_json(self, model: str, messages: list[dict], *, temperature: float | None = None,
                  timeout: float = 300.0, attempts: int = 3) -> tuple[Any, float]:
        """Как chat(), но парсит JSON. Модель иногда отдаёт пустой ответ — повторяем."""
        total = 0.0
        last: Exception | None = None
        for attempt in range(attempts):
            try:
                text, credits = self.chat(model, messages, temperature=temperature, timeout=timeout)
            except KieError as exc:
                # Шлюз KIE иногда отвечает 524/пустым телом — повторяем.
                last = exc
                log.warning("chat_json(%s): попытка %s — %s", model, attempt + 1, exc)
                time.sleep(3 * (attempt + 1))
                continue
            total += credits
            if text and text.strip():
                try:
                    return parse_json_block(text), total
                except KieError as exc:
                    last = exc
            else:
                last = KieError("модель вернула пустой ответ")
            log.warning("chat_json(%s): попытка %s неудачна (%s)", model, attempt + 1, last)
            time.sleep(2 * (attempt + 1))
        raise last or KieError("chat_json: не удалось получить JSON")

    # ------------------------------------------------------------------ файлы
    def upload_base64(self, data_b64: str, filename: str, upload_path: str = "images/user-uploads") -> str:
        body = {"base64Data": data_b64, "uploadPath": upload_path, "fileName": filename}
        data = self._request("POST", UPLOAD_BASE64, json_body=body, timeout=180)
        payload = data.get("data") or {}
        url = payload.get("downloadUrl") or payload.get("fileUrl") or payload.get("url")
        if not url:
            raise KieError(f"upload: нет ссылки в ответе {json.dumps(data)[:200]}")
        return url


# Разные видеомодели KIE ждут РАЗНЫЕ наборы полей, и схему API не публикует.
# Ниже — то, что установлено проверкой обязательных полей на живом API.
PIXVERSE_QUALITY = ("360p", "540p", "720p", "1080p")
# Документация pixverse называет 5 и 8 секунд, но проверка на живом API показала,
# что проходит и 3 — значит длительность не из фиксированного набора. Поэтому не
# округляем к ближайшему из двух значений (это молча ломало бы настройку канала),
# а только не даём выйти за верхнюю границу.
PIXVERSE_MAX_DURATION = 8


def _pixverse_quality(resolution: str) -> str:
    """У нас разрешения 480p/720p/1080p, у pixverse — 360p/540p/720p/1080p."""
    if resolution in PIXVERSE_QUALITY:
        return resolution
    return {"480p": "540p"}.get(resolution, "720p")


def video_input(model: str, *, prompt: str, aspect_ratio: str, resolution: str,
                duration: int) -> dict:
    """Поля запроса на генерацию видео под конкретное семейство моделей.

    seedance принимает resolution и generate_audio, а pixverse вместо них требует
    quality и не знает про звук; общий набор полей давал у него «This field is
    required» и сцена падала целиком.
    """
    low = (model or "").lower()

    if "pixverse" in low:
        return {
            "prompt": prompt,
            "aspect_ratio": aspect_ratio,
            "duration": max(1, min(PIXVERSE_MAX_DURATION, int(duration))),
            "quality": _pixverse_quality(resolution),
        }

    # seedance и совместимые с ним — набор, проверенный в работе
    return {
        "prompt": prompt,
        "aspect_ratio": aspect_ratio,
        "resolution": resolution,
        "duration": int(duration),
        "generate_audio": False,
    }


def parse_json_block(text: str):
    """Достаём JSON из ответа модели, даже если он в ```-блоке или с мусором вокруг."""
    if text is None:
        raise KieError("пустой ответ модели")
    s = text.strip()
    if s.startswith("```"):
        s = s.split("\n", 1)[1] if "\n" in s else s
        if s.rstrip().endswith("```"):
            s = s.rstrip()[:-3]
    s = s.strip()
    try:
        return json.loads(s)
    except ValueError:
        pass
    for opener, closer in (("{", "}"), ("[", "]")):
        start = s.find(opener)
        end = s.rfind(closer)
        if start != -1 and end > start:
            candidate = s[start:end + 1]
            try:
                return json.loads(candidate)
            except ValueError:
                continue
    raise KieError(f"Не удалось разобрать JSON из ответа модели: {text[:300]}")


def extract_urls(result: dict) -> list[str]:
    """Достаём ссылки на результат из resultJson — формат отличается между моделями."""
    urls: list[str] = []

    def walk(node):
        if isinstance(node, str):
            if node.startswith("http") and not node.endswith((".json",)):
                urls.append(node)
        elif isinstance(node, list):
            for item in node:
                walk(item)
        elif isinstance(node, dict):
            for key in ("resultUrls", "urls", "resultUrl", "url", "videoUrl", "audioUrl",
                        "imageUrl", "originUrl", "result_urls"):
                if key in node:
                    walk(node[key])
            for key, value in node.items():
                if key.startswith("_"):
                    continue
                if key not in ("resultUrls", "urls", "resultUrl", "url", "videoUrl", "audioUrl",
                               "imageUrl", "originUrl", "result_urls"):
                    walk(value)

    walk(result)
    seen, out = set(), []
    for u in urls:
        if u not in seen:
            seen.add(u)
            out.append(u)
    return out
