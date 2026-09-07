"""Планировщик контента: превращает контент-план и расписание в задачи на сборку роликов."""
from __future__ import annotations

import datetime as dt
import logging
from typing import Optional

from sqlalchemy import select
from sqlalchemy.orm import Session

from .db import session_scope
from .models import Channel, PlanItem, ScheduleRule, Video, utcnow
from .queue import enqueue

log = logging.getLogger("cf.planner")

ACTIVE_STATUSES = ("queued", "script", "voice", "visuals", "assemble", "subtitles",
                   "thumbnail", "metadata")


def create_video_from_item(session: Session, item: PlanItem) -> Video:
    """Создаём ролик из пункта контент-плана и ставим его в очередь."""
    video = Video(
        channel_id=item.channel_id,
        plan_item_id=item.id,
        title=item.video_title or item.book_title,
        book_title=item.book_title,
        book_author=item.book_author,
        status="queued",
        stage="queued",
    )
    session.add(video)
    item.status = "queued"
    session.commit()
    enqueue(session, "build_video", video_id=video.id)
    return video


def next_items(session: Session, channel_id: int, count: int,
               on_date: Optional[dt.date] = None) -> list[PlanItem]:
    """Что берём в работу: сперва запланированное на дату, затем — по порядку плана."""
    picked: list[PlanItem] = []
    if on_date is not None:
        dated = session.execute(
            select(PlanItem)
            .where(PlanItem.channel_id == channel_id,
                   PlanItem.scheduled_date == on_date,
                   PlanItem.status == "planned")
            .order_by(PlanItem.position)
        ).scalars().all()
        picked.extend(dated)

    if len(picked) < count:
        seen = {p.id for p in picked}
        rest = session.execute(
            select(PlanItem)
            .where(PlanItem.channel_id == channel_id, PlanItem.status == "planned")
            .order_by(PlanItem.position)
        ).scalars().all()
        for item in rest:
            if item.id in seen:
                continue
            picked.append(item)
            if len(picked) >= count:
                break
    return picked[:count]


def active_video_count(session: Session, channel_id: int) -> int:
    return len(session.execute(
        select(Video).where(Video.channel_id == channel_id, Video.status.in_(ACTIVE_STATUSES))
    ).scalars().all())


def run_schedule(force_date: Optional[str] = None, ignore_time: bool = False) -> list[int]:
    """Запускает генерацию по расписанию. Возвращает id созданных роликов."""
    today = dt.date.fromisoformat(force_date) if force_date else dt.date.today()
    now_hm = utcnow().strftime("%H:%M")
    created: list[int] = []

    with session_scope() as session:
        channels = session.execute(
            select(Channel).where(Channel.is_active.is_(True)).order_by(Channel.position)
        ).scalars().all()
        for channel in channels:
            rule = session.execute(
                select(ScheduleRule).where(ScheduleRule.channel_id == channel.id,
                                           ScheduleRule.weekday == today.weekday())
            ).scalars().first()
            if rule is None or not rule.enabled or rule.count <= 0:
                continue
            if not ignore_time and rule.run_at > now_hm:
                continue

            already = session.execute(
                select(Video).where(Video.channel_id == channel.id,
                                    Video.created_at >= dt.datetime.combine(today, dt.time.min))
            ).scalars().all()
            need = rule.count - len(already)
            if need <= 0:
                continue

            for item in next_items(session, channel.id, need, on_date=today):
                video = create_video_from_item(session, item)
                created.append(video.id)
                log.info("Канал %s: поставлен ролик %s (%s)", channel.slug, video.id, item.book_title)
    return created


def assign_dates(session: Session, channel_id: int, start: dt.date,
                 per_day_by_weekday: dict[int, int]) -> int:
    """Раскладываем пункты плана по календарю согласно недельному расписанию."""
    items = session.execute(
        select(PlanItem)
        .where(PlanItem.channel_id == channel_id, PlanItem.status == "planned")
        .order_by(PlanItem.position)
    ).scalars().all()
    if not items:
        return 0
    day = start
    index = 0
    guard = 0
    while index < len(items) and guard < 4000:
        guard += 1
        slots = per_day_by_weekday.get(day.weekday(), 0)
        for _ in range(slots):
            if index >= len(items):
                break
            items[index].scheduled_date = day
            index += 1
        day += dt.timedelta(days=1)
    session.commit()
    return index
