"""Синхронизация справочников KIE: прайс-лист, список моделей, баланс, голоса."""
from __future__ import annotations

import datetime as dt
import logging

from sqlalchemy import delete, select

from .db import session_scope
from .kie import KieClient
from .models import ModelPath, PriceItem, Voice, utcnow
from . import settings_store as st
from . import tts

log = logging.getLogger("cf.sync")

VIDEO_HINTS = ("video", "seedance", "kling", "sora", "veo", "hailuo", "wan", "runway", "grok-imagine/")
IMAGE_HINTS = ("image", "banana", "seedream", "flux", "imagen", "ideogram", "qwen", "recraft", "topaz", "z-image")
TTS_HINTS = ("tts", "text-to-speech", "speech", "elevenlabs", "audio")
CHAT_HINTS = ("gpt", "claude", "gemini", "chat", "grok-4", "codex", "deepseek", "qwen3-max")

# Модели, которым на вход нужна готовая картинка или видео. Конвейер генерирует
# видеоряд из текста, подавать им нечего — они всегда отвечают «This field is
# required», поэтому в списке выбора видеомодели им не место.
NEEDS_INPUT_MEDIA = (
    "image-to-video", "img2video", "i2v", "video-to-video", "reference-to-video",
    "speech-to-video", "motion-control", "transformation", "extend", "upscal",
    "avatar", "lip", "-edit", "edit-", "flf", "animate",
)


def needs_input_media(path: str) -> bool:
    low = (path or "").lower()
    return any(marker in low for marker in NEEDS_INPUT_MEDIA)


def classify(path: str) -> str:
    low = path.lower()
    if any(h in low for h in TTS_HINTS):
        return "tts"
    if any(h in low for h in VIDEO_HINTS):
        # выделяем отдельным типом, чтобы не предлагать их для генерации из текста
        return "video_input" if needs_input_media(path) else "video"
    if any(h in low for h in IMAGE_HINTS):
        return "image"
    if any(h in low for h in CHAT_HINTS):
        return "chat"
    return "other"


def _client(session) -> KieClient:
    key = st.get(session, "kie_api_key", "")
    return KieClient(api_key=key or None)


def sync_prices() -> int:
    """Тянем актуальный прайс KIE (запускается по расписанию раз в сутки)."""
    with session_scope() as session:
        client = _client(session)
        records = client.pricing()
        session.execute(delete(PriceItem))
        now = utcnow()
        for rec in records:
            session.add(PriceItem(
                model_description=str(rec.get("modelDescription") or "")[:400],
                interface_type=str(rec.get("interfaceType") or "")[:40],
                provider=str(rec.get("provider") or "")[:120],
                credit_price=str(rec.get("creditPrice") or "")[:40],
                credit_unit=str(rec.get("creditUnit") or "")[:80],
                usd_price=str(rec.get("usdPrice") or "")[:40],
                discount_rate=float(rec.get("discountRate") or 0),
                updated_at=now,
            ))
        st.set_value(session, "prices_updated_at", now.isoformat(timespec="seconds"))
        session.commit()
        log.info("Прайс KIE обновлён: %s позиций", len(records))
        return len(records)


def sync_models() -> int:
    """Обновляем список доступных моделей KIE для выпадающих списков."""
    with session_scope() as session:
        client = _client(session)
        paths = client.model_paths()
        existing = {row.path: row for row in session.execute(select(ModelPath)).scalars()}
        now = utcnow()
        for path in paths:
            clean = path.strip()
            if not clean:
                continue
            row = existing.get(clean)
            if row is None:
                session.add(ModelPath(path=clean, kind=classify(clean), updated_at=now))
            else:
                row.kind = classify(clean)
                row.updated_at = now
        st.set_value(session, "models_updated_at", now.isoformat(timespec="seconds"))
        session.commit()
        log.info("Список моделей KIE обновлён: %s", len(paths))
        return len(paths)


def reclassify_models() -> int:
    """Пересчитываем тип у сохранённых моделей.

    Нужно после обновления правил разбора: иначе в выпадающем списке остались бы
    модели, выбор которых гарантированно роняет сборку. Запросов к KIE не делает.
    """
    with session_scope() as session:
        changed = 0
        for row in session.execute(select(ModelPath)).scalars():
            kind = classify(row.path)
            if row.kind != kind:
                row.kind = kind
                changed += 1
        if changed:
            session.commit()
            log.info("Типы моделей пересчитаны: изменено %s", changed)
        return changed


def sync_credits() -> float:
    with session_scope() as session:
        client = _client(session)
        balance = client.credits()
        st.set_value(session, "credits_balance", f"{balance:.2f}")
        st.set_value(session, "credits_checked_at", utcnow().isoformat(timespec="seconds"))
        session.commit()
        return balance


def seed_voices() -> int:
    """Заполняем каталог голосов (ElevenLabs из документации + голоса Gemini TTS)."""
    with session_scope() as session:
        have = {(v.provider, v.voice_id) for v in session.execute(select(Voice)).scalars()}
        added = 0
        for item in tts.load_voice_catalog():
            key = ("elevenlabs", item["voice_id"])
            if key in have:
                continue
            session.add(Voice(
                provider="elevenlabs", voice_id=item["voice_id"], name=item["name"],
                description=item.get("description", ""),
                preview_url=f"https://static.aiquickdraw.com/elevenlabs/voice/{item['voice_id']}.mp3",
            ))
            added += 1
        for name, description, gender in tts.GEMINI_VOICES:
            key = ("gemini", name)
            if key in have:
                continue
            session.add(Voice(provider="gemini", voice_id=name, name=name,
                              description=description, gender=gender))
            added += 1
        session.commit()
        return added


def daily_sync() -> None:
    """Ежесуточная задача: цены + модели + баланс."""
    for fn in (sync_prices, sync_models, sync_credits):
        try:
            fn()
        except Exception as exc:  # noqa: BLE001
            log.warning("Синхронизация %s не удалась: %s", fn.__name__, exc)


def prices_are_stale(session, max_age_hours: int = 26) -> bool:
    raw = st.get(session, "prices_updated_at", "")
    if not raw:
        return True
    try:
        when = dt.datetime.fromisoformat(raw)
    except ValueError:
        return True
    return (utcnow() - when).total_seconds() > max_age_hours * 3600
