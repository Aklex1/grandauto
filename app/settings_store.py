"""Глобальные настройки в БД (ключ-значение) с типизированным доступом."""
from __future__ import annotations

import json
from typing import Any

from sqlalchemy import select
from sqlalchemy.orm import Session

from .models import Setting

DEFAULTS: dict[str, Any] = {
    "kie_api_key": "",
    "default_chat_model": "gemini-3-8-flash-openai",
    "default_video_model": "pixverse-v6/text-to-video",
    "default_image_model": "nano-banana-2",
    "default_tts_model": "elevenlabs/text-to-speech-multilingual-v2",
    "tts_fallback_model": "google/gemini-3-1-flash-tts",
    "tts_fallback_voice": "Charon",
    "tts_allow_fallback": "1",
    "prices_updated_at": "",
    "models_updated_at": "",
    "credits_balance": "",
    "credits_checked_at": "",
    "auto_run_schedule": "1",
    "scene_concurrency": "3",
    "usd_per_credit": "0.005",
    # бета: пакетный импорт футажей из бесплатных стоков
    "pexels_api_key": "",
    "pixabay_api_key": "",
    "stock_provider": "pexels",
    "stock_min_duration": "6",
    "stock_per_page": "24",
    # неснижаемый остаток на диске: ниже него загрузка футажей останавливается
    "disk_min_free_gb": "5",
}


def get(session: Session, key: str, default: Any = None) -> str:
    row = session.get(Setting, key)
    if row is None:
        return DEFAULTS.get(key, default) if default is None else default
    return row.value


def get_int(session: Session, key: str, default: int = 0) -> int:
    try:
        return int(str(get(session, key, default)).strip())
    except (TypeError, ValueError):
        return default


def get_float(session: Session, key: str, default: float = 0.0) -> float:
    try:
        return float(str(get(session, key, default)).strip())
    except (TypeError, ValueError):
        return default


def get_bool(session: Session, key: str, default: bool = False) -> bool:
    raw = str(get(session, key, "1" if default else "0")).strip().lower()
    return raw in ("1", "true", "yes", "on")


def set_value(session: Session, key: str, value: Any) -> None:
    if isinstance(value, (dict, list)):
        value = json.dumps(value, ensure_ascii=False)
    row = session.get(Setting, key)
    if row is None:
        session.add(Setting(key=key, value=str(value)))
        session.flush()  # чтобы повторный вызов в той же транзакции нашёл запись
    else:
        row.value = str(value)


def all_settings(session: Session) -> dict[str, str]:
    data = dict(DEFAULTS)
    for row in session.execute(select(Setting)).scalars():
        data[row.key] = row.value
    return data


def ensure_defaults(session: Session) -> None:
    existing = {row.key for row in session.execute(select(Setting)).scalars()}
    added = False
    for key, value in DEFAULTS.items():
        if key not in existing:
            session.add(Setting(key=key, value=str(value)))
            added = True
    if added:
        session.flush()
