"""Фоновая музыка через Suno API внутри KIE: генерация и повторное использование треков."""
from __future__ import annotations

import logging
import time
from pathlib import Path
from typing import Optional

from sqlalchemy import select
from sqlalchemy.orm import Session

from . import config, storage
from .kie import KieClient, KieError
from .models import MusicTrack

log = logging.getLogger("cf.music")

GENERATE = "/api/v1/generate"
RECORD_INFO = "/api/v1/generate/record-info"
DEFAULT_MODEL = "V4_5"

TERMINAL_OK = {"SUCCESS", "FIRST_SUCCESS"}
TERMINAL_FAIL = {"CREATE_TASK_FAILED", "GENERATE_AUDIO_FAILED", "CALLBACK_EXCEPTION",
                 "SENSITIVE_WORD_ERROR", "FAILED"}


class MusicError(RuntimeError):
    pass


def style_for_channel(topic: str, hint: str = "") -> str:
    """Описание музыки под тематику канала — Suno работает по текстовому описанию."""
    if hint.strip():
        return hint.strip()
    low = (topic or "").lower()
    if "женск" in low:
        return ("warm cinematic ambient background music, soft piano and strings, "
                "gentle and hopeful, calm tempo, no vocals, subtle, for a talking-head video")
    return ("cinematic ambient background music, deep low strings and slow pulse, "
            "focused and confident mood, restrained, no vocals, for a motivational voiceover video")


def generate_track(client: KieClient, style: str, *, model: str = DEFAULT_MODEL,
                   timeout: float = 900.0, poll: float = 8.0) -> tuple[str, str, float]:
    """Создаём инструментальный трек. Возвращает (ссылка, заголовок, потраченные кредиты)."""
    # Suno требует callBackUrl даже когда результат забирается опросом статуса.
    callback = (config.PUBLIC_URL + "/api/suno-callback") if config.PUBLIC_URL \
        else "https://example.com/suno-callback"
    body = {
        "prompt": style[:1000],
        "customMode": False,
        "instrumental": True,
        "model": model,
        "callBackUrl": callback,
    }
    data = client._request("POST", GENERATE, json_body=body, timeout=120)
    if data.get("code") != 200:
        raise MusicError(f"Suno createTask: {data.get('code')} {data.get('msg')}")
    task_id = (data.get("data") or {}).get("taskId")
    if not task_id:
        raise MusicError("Suno не вернул taskId")

    deadline = time.time() + timeout
    while time.time() < deadline:
        info = client._request("GET", RECORD_INFO, params={"taskId": task_id}, timeout=60)
        payload = info.get("data") or {}
        status = (payload.get("status") or "").upper()
        if status in TERMINAL_OK:
            tracks = ((payload.get("response") or {}).get("sunoData") or [])
            for track in tracks:
                url = track.get("audioUrl") or track.get("streamAudioUrl") or track.get("audio_url")
                if url:
                    return url, str(track.get("title") or "Фоновый трек"), 0.0
            raise MusicError("Suno вернул задачу без ссылки на аудио")
        if status in TERMINAL_FAIL:
            raise MusicError(f"Suno: {status} {payload.get('errorMessage') or ''}")
        time.sleep(poll)
    raise MusicError(f"Suno не завершил задачу за {timeout:.0f} с")


def library_dir() -> Path:
    path = config.MEDIA_DIR / "_music"
    path.mkdir(parents=True, exist_ok=True)
    return path


def ensure_track(session: Session, client: KieClient, channel_id: int, topic: str,
                 style_hint: str = "", force_new: bool = False) -> Optional[MusicTrack]:
    """Берём готовый трек канала, а если его нет — генерируем и сохраняем на диск.

    Треки переиспользуются: одна генерация стоит кредитов, а фон в роликах одного
    канала всё равно должен быть узнаваемым.
    """
    if not force_new:
        existing = session.execute(
            select(MusicTrack).where(
                MusicTrack.is_active.is_(True),
                (MusicTrack.channel_id == channel_id) | (MusicTrack.channel_id.is_(None)),
            ).order_by(MusicTrack.used_count, MusicTrack.id)
        ).scalars().first()
        if existing and existing.path and storage.abspath(existing.path).exists():
            return existing

    style = style_for_channel(topic, style_hint)
    try:
        url, title, _credits = generate_track(client, style)
    except (MusicError, KieError) as exc:
        log.warning("Музыка не сгенерирована: %s", exc)
        return None

    dest = library_dir() / f"channel{channel_id}_{int(time.time())}{storage.guess_ext(url, '.mp3')}"
    try:
        storage.download(url, dest)
        duration = storage.media_duration(dest)
    except Exception as exc:  # noqa: BLE001
        log.warning("Трек не скачался: %s", exc)
        return None

    track = MusicTrack(
        channel_id=channel_id, title=title[:200], style=style[:500],
        path=storage.rel(dest), source_url=url, duration_sec=duration,
        file_size=dest.stat().st_size,
    )
    session.add(track)
    session.commit()
    log.info("Фоновый трек добавлен: %s (%.0f с)", track.title, duration)
    return track
