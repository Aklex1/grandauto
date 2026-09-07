"""Первичная инициализация: настройки, каналы, контент-планы, голоса, админ."""
from __future__ import annotations

import datetime as dt
import logging
import secrets

from sqlalchemy import select
from sqlalchemy.orm import Session

from . import config, seed_data, sync
from .db import init_db, session_scope
from .models import Channel, PlanItem, ScheduleRule, Setting
from . import settings_store as st
from .security import hash_password

log = logging.getLogger("cf.bootstrap")


def _make_channel(session: Session, preset: dict, books: list[tuple[str, str, str]],
                  position: int) -> Channel:
    channel = session.execute(
        select(Channel).where(Channel.slug == preset["slug"])
    ).scalars().first()
    if channel is not None:
        return channel

    channel = Channel(
        slug=preset["slug"],
        name=preset["name"],
        topic=preset["topic"],
        description=preset["description"],
        position=position,
        voice_id=preset["voice_id"],
        voice_name=preset["voice_name"],
        voice_stability=preset.get("voice_stability", 0.45),
        script_style=preset["script_style"],
        visual_style=preset["visual_style"],
        thumb_style=preset["thumb_style"],
        chat_model=st.get(session, "default_chat_model"),
        video_model=st.get(session, "default_video_model"),
        image_model=st.get(session, "default_image_model"),
        tts_model=st.get(session, "default_tts_model"),
    )
    session.add(channel)
    session.flush()

    for idx, (title, author, angle) in enumerate(books):
        session.add(PlanItem(
            channel_id=channel.id, position=idx, book_title=title, book_author=author,
            angle=angle, video_title="", status="planned",
        ))

    # По умолчанию — один ролик в день, запуск в 04:00 UTC.
    for weekday in range(7):
        session.add(ScheduleRule(channel_id=channel.id, weekday=weekday, count=1,
                                 run_at="04:00", enabled=True))
    session.commit()
    log.info("Создан канал %s с %s пунктами плана", channel.slug, len(books))
    return channel


def schedule_plan_dates(session: Session, channel: Channel, start: dt.date) -> None:
    """Раскладываем пункты плана по дням — по одному на день."""
    items = session.execute(
        select(PlanItem).where(PlanItem.channel_id == channel.id).order_by(PlanItem.position)
    ).scalars().all()
    for offset, item in enumerate(items):
        if item.scheduled_date is None:
            item.scheduled_date = start + dt.timedelta(days=offset)
    session.commit()


def run(admin_password: str | None = None) -> dict:
    """Полная инициализация системы. Идемпотентна."""
    init_db()
    result: dict = {}
    with session_scope() as session:
        st.ensure_defaults(session)
        if config.KIE_API_KEY and not st.get(session, "kie_api_key"):
            st.set_value(session, "kie_api_key", config.KIE_API_KEY)

        password = admin_password or config.ADMIN_PASSWORD
        if not session.get(Setting, "admin_password_hash"):
            if not password:
                password = secrets.token_urlsafe(12)
            st.set_value(session, "admin_password_hash", hash_password(password))
            st.set_value(session, "admin_user", config.ADMIN_USER)
            result["admin_password"] = password
        session.commit()

        men = _make_channel(session, seed_data.MEN_CHANNEL, seed_data.MEN_BOOKS, 0)
        women = _make_channel(session, seed_data.WOMEN_CHANNEL, seed_data.WOMEN_BOOKS, 1)
        today = dt.date.today()
        schedule_plan_dates(session, men, today)
        schedule_plan_dates(session, women, today)
        result["channels"] = [men.slug, women.slug]

    try:
        added = sync.seed_voices()
        result["voices"] = added
    except Exception as exc:  # noqa: BLE001
        log.warning("Каталог голосов не заполнен: %s", exc)

    return result
