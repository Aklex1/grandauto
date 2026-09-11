"""
Служба бесплатной озвучки и извлечения звука из роликов.

Плагин сайта ходит сюда за двумя вещами: за бесплатными голосами и за
звуковой дорожкой из видео. Старая служба перестала отвечать, поэтому
здесь она собрана заново — с тем же набором запросов и тем же форматом
ответов, чтобы на стороне сайта ничего не менять.

Запросы:
    GET  /health          — проверка живости
    GET  /voices          — список бесплатных голосов
    POST /tts             — озвучить текст
    POST /youtube-audio   — достать дорожку из ролика
    GET  /files/<имя>     — готовые файлы

Ключ доступа передаётся заголовком X-API-Key, если он задан в настройках.
"""

import asyncio
import os
import re
import shutil
import subprocess
import time
import uuid
from pathlib import Path
from typing import Optional

from fastapi import FastAPI, Header, HTTPException
from fastapi.responses import JSONResponse
from fastapi.staticfiles import StaticFiles
from pydantic import BaseModel

API_KEY = os.environ.get("MEDIA_API_KEY", "").strip()
PUBLIC_BASE = os.environ.get("PUBLIC_BASE", "").rstrip("/")
FILES_DIR = Path(os.environ.get("FILES_DIR", "files")).resolve()
KEEP_HOURS = int(os.environ.get("KEEP_HOURS", "24"))
MAX_MINUTES = int(os.environ.get("MAX_MINUTES", "90"))

FILES_DIR.mkdir(parents=True, exist_ok=True)

app = FastAPI(title="Genius media service", version="1.0")
app.mount("/files", StaticFiles(directory=str(FILES_DIR)), name="files")

# Голоса по умолчанию, когда плагин присылает просто «male» или «female».
DEFAULT_VOICES = {
    "male": "ru-RU-DmitryNeural",
    "female": "ru-RU-SvetlanaNeural",
}
# Старые идентификаторы RHVoice остались в сохранённых настройках
# пользователей — переводим их на близкие голоса, чтобы ничего не падало.
RHVOICE_FALLBACK = {
    "aleksandr": "ru-RU-DmitryNeural",
    "anna": "ru-RU-SvetlanaNeural",
    "elena": "ru-RU-SvetlanaNeural",
    "irina": "ru-RU-SvetlanaNeural",
}

FORMATS = {
    "mp3": ("mp3", ["-codec:a", "libmp3lame", "-b:a", "128k"]),
    "opus": ("opus", ["-codec:a", "libopus", "-b:a", "64k"]),
    "wav": ("wav", ["-codec:a", "pcm_s16le", "-ar", "44100"]),
}


def check_key(provided: Optional[str]) -> None:
    if API_KEY and (provided or "").strip() != API_KEY:
        raise HTTPException(status_code=401, detail="Неверный ключ доступа")


def public_url(name: str) -> str:
    if PUBLIC_BASE:
        return f"{PUBLIC_BASE}/files/{name}"
    return f"/files/{name}"


def cleanup() -> None:
    """Старые файлы копятся быстро — подчищаем их при каждом обращении."""
    deadline = time.time() - KEEP_HOURS * 3600
    for item in FILES_DIR.glob("*"):
        try:
            if item.is_file() and item.stat().st_mtime < deadline:
                item.unlink()
        except OSError:
            pass


def resolve_voice(raw: str) -> str:
    value = (raw or "").strip()
    if not value:
        return DEFAULT_VOICES["male"]
    low = value.lower()
    if low in DEFAULT_VOICES:
        return DEFAULT_VOICES[low]
    if low.startswith("edge:"):
        return value.split(":", 1)[1]
    if low.startswith("rhvoice:"):
        name = low.split(":", 1)[1]
        return RHVOICE_FALLBACK.get(name, DEFAULT_VOICES["male"])
    return value


def convert(source: Path, fmt: str) -> Path:
    ext, args = FORMATS.get(fmt, FORMATS["mp3"])
    target = source.with_suffix("." + ext)
    if source == target:
        return source
    result = subprocess.run(
        ["ffmpeg", "-y", "-i", str(source), *args, str(target)],
        capture_output=True,
    )
    if result.returncode != 0 or not target.exists():
        raise HTTPException(status_code=500, detail="Не удалось перекодировать файл")
    source.unlink(missing_ok=True)
    return target


class TtsRequest(BaseModel):
    text: str
    voice: str = "male"
    output_format: str = "mp3"
    style: Optional[str] = None


class YoutubeRequest(BaseModel):
    url: str
    format: str = "mp3"


@app.get("/health")
def health():
    return {
        "status": "ok",
        "ffmpeg": shutil.which("ffmpeg") is not None,
        "ytdlp": shutil.which("yt-dlp") is not None,
        "files": len(list(FILES_DIR.glob("*"))),
    }


@app.get("/voices")
async def voices(x_api_key: Optional[str] = Header(default=None)):
    check_key(x_api_key)
    import edge_tts

    try:
        catalog = await edge_tts.list_voices()
    except Exception as error:  # сеть до поставщика голосов
        raise HTTPException(status_code=502, detail=f"Не получен список голосов: {error}")

    out = []
    for item in catalog:
        short = item.get("ShortName", "")
        if not short:
            continue
        out.append({
            "id": "edge:" + short,
            "name": item.get("FriendlyName", short),
            "gender": (item.get("Gender", "") or "").lower(),
            "locale": item.get("Locale", ""),
        })
    out.sort(key=lambda v: (not v["locale"].startswith("ru"), v["locale"], v["name"]))
    return {"voices": out}


@app.post("/tts")
async def tts(payload: TtsRequest, x_api_key: Optional[str] = Header(default=None)):
    check_key(x_api_key)
    cleanup()

    text = (payload.text or "").strip()
    if not text:
        raise HTTPException(status_code=400, detail="Пустой текст")
    if len(text) > 20000:
        raise HTTPException(status_code=400, detail="Текст длиннее двадцати тысяч знаков")

    import edge_tts

    voice = resolve_voice(payload.voice)
    name = f"tts-{uuid.uuid4().hex[:16]}.mp3"
    target = FILES_DIR / name

    try:
        communicate = edge_tts.Communicate(text, voice)
        await communicate.save(str(target))
    except Exception as error:
        # Недописанный файл оставлять нельзя — он потом отдастся как готовый.
        target.unlink(missing_ok=True)
        raise HTTPException(status_code=502, detail=f"Синтез не удался: {error}")

    if not target.exists() or target.stat().st_size < 512:
        target.unlink(missing_ok=True)
        raise HTTPException(status_code=502, detail="Пустой ответ синтеза")

    fmt = (payload.output_format or "mp3").lower()
    if fmt != "mp3":
        target = convert(target, fmt)

    return {
        "audio_url": public_url(target.name),
        "voice": voice,
        "format": target.suffix.lstrip("."),
        "size": target.stat().st_size,
    }


@app.post("/youtube-audio")
def youtube_audio(payload: YoutubeRequest, x_api_key: Optional[str] = Header(default=None)):
    check_key(x_api_key)
    cleanup()

    url = (payload.url or "").strip()
    if not re.match(r"^https?://", url):
        raise HTTPException(status_code=400, detail="Нужна ссылка на ролик")

    fmt = (payload.format or "mp3").lower()
    ext, _ = FORMATS.get(fmt, FORMATS["mp3"])
    stem = f"yt-{uuid.uuid4().hex[:16]}"
    template = str(FILES_DIR / (stem + ".%(ext)s"))

    command = [
        "yt-dlp",
        "-x", "--audio-format", ext,
        "--audio-quality", "0",
        "--no-playlist",
        "--match-filter", f"duration < {MAX_MINUTES * 60}",
        "--retries", "3",
        "-o", template,
        url,
    ]
    cookies = os.environ.get("YTDLP_COOKIES", "")
    if cookies and Path(cookies).exists():
        # Ролики с ограничением по возрасту и регионам требуют входа.
        command[1:1] = ["--cookies", cookies]

    result = subprocess.run(command, capture_output=True, text=True, timeout=600)
    produced = sorted(FILES_DIR.glob(stem + ".*"))
    if result.returncode != 0 or not produced:
        message = (result.stderr or "").strip().splitlines()
        detail = message[-1] if message else "Не удалось получить дорожку"
        raise HTTPException(status_code=502, detail=detail[:300])

    target = produced[0]
    title = ""
    duration = 0
    info = subprocess.run(
        ["yt-dlp", "--no-playlist", "--print", "%(title)s|%(duration)s", "--skip-download", url],
        capture_output=True, text=True, timeout=120,
    )
    if info.returncode == 0 and "|" in info.stdout:
        raw_title, _, raw_duration = info.stdout.strip().partition("|")
        title = raw_title.strip()
        duration = int(float(raw_duration)) if raw_duration.strip().replace(".", "").isdigit() else 0

    return {
        "audio_url": public_url(target.name),
        "title": title,
        "duration": duration,
        "format": target.suffix.lstrip("."),
        "size": target.stat().st_size,
    }


@app.exception_handler(HTTPException)
def http_error(request, exc: HTTPException):
    # Плагин читает поле detail — держим единый формат ошибки.
    return JSONResponse(status_code=exc.status_code, content={"detail": exc.detail})
