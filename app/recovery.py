"""Догенерация: завод сам дожимает ролики, сорвавшиеся не по своей вине.

Кончились кредиты, провайдер ответил 500, оборвалась сеть — ролик встаёт с
ошибкой и ждёт человека. Но причина почти всегда временная: баланс пополнят,
провайдер поднимется. Поэтому завод возвращается к таким роликам сам.

Две вещи, из-за которых это не превращается в дорогую карусель. Первое:
повторная сборка не платит за готовое — конвейер продолжает с места срыва, а
готовые сцены, озвучка и кадры остаются на диске. Второе: пауза между попытками
растёт, так что ролик, чья беда затянулась, не долбится в API каждые десять
минут, а заглядывает раз в несколько часов.
"""
from __future__ import annotations

import datetime as dt
import logging

from sqlalchemy import select
from sqlalchemy.orm import Session

from .db import session_scope
from .models import Event, Video, utcnow
from . import settings_store as st

log = logging.getLogger("cf.recovery")

# Что считаем временным. Список нарочно щедрый: срыв на полпути почти всегда
# внешний, а лишняя попытка бесплатна — она либо продолжит сборку, либо упрётся
# в ту же стену и отложится на дольше.
TRANSIENT = (
    "402", "credits", "insufficient", "balance", "кредит", "баланс",
    "429", "rate limit", "too many",
    "500", "502", "503", "504", "internal error", "bad gateway", "unavailable",
    "timeout", "timed out", "таймаут", "не ответил", "не дождал",
    "connection", "network", "соединен", "сеть", "temporarily", "temporary",
    "провалена", "не удалось скачать", "скачан не полностью",
    "озвучка не удалась", "не удалось сгенерировать",
    "api-ключ не задан", "агент молчит", "агент не принёс",
)

# А это не лечится повтором: настроено неправильно, и пока человек не поправит,
# каждая попытка будет падать так же.
PERMANENT = (
    "this field is required", "invalid model", "модель не найдена",
    "не найден", "нет ни одной сцены", "граф не совпал",
    "ноды «", "не разобран",
)

# Паузы между попытками, минуты. Дальше — по последней, с потолком.
BACKOFF_MIN = (10, 20, 40, 80, 160, 320)
BACKOFF_CAP_MIN = 360

# Сколько раз пробуем, прежде чем оставить ролик человеку.
MAX_ATTEMPTS = 12


def is_transient(error: str) -> bool:
    """Стоит ли пробовать снова.

    Сначала смотрим на то, что повтором точно не лечится: сообщение про
    кончившиеся кредиты и сообщение про кривой граф выглядят одинаково
    «страшно», но первое пройдёт само, а второе — никогда.
    """
    text = (error or "").lower()
    if not text.strip():
        return False
    if any(mark in text for mark in PERMANENT):
        return False
    return any(mark in text for mark in TRANSIENT)


def next_delay(attempt: int) -> dt.timedelta:
    """Пауза перед попыткой номер attempt (считая с единицы)."""
    idx = max(0, attempt - 1)
    minutes = BACKOFF_MIN[idx] if idx < len(BACKOFF_MIN) else BACKOFF_CAP_MIN
    return dt.timedelta(minutes=min(minutes, BACKOFF_CAP_MIN))


def due(session: Session, now: dt.datetime = None) -> list[Video]:
    """Ролики, к которым пора вернуться."""
    now = now or utcnow()
    rows = session.execute(
        select(Video).where(Video.status == "failed").order_by(Video.id)).scalars().all()
    ready: list[Video] = []
    for video in rows:
        if (video.retry_count or 0) >= MAX_ATTEMPTS:
            continue
        if not is_transient(video.error or ""):
            continue
        # Первый срыв: ждём паузу от момента падения, а не бросаемся сразу —
        # провайдеру надо дать время подняться.
        when = video.retry_at
        if when is None:
            base = video.finished_at or video.created_at or now
            when = base + next_delay(1)
        if when <= now:
            ready.append(video)
    return ready


def run_retries() -> int:
    """Одна волна догенерации. Возвращает, сколько роликов отправлено дожиматься."""
    from . import queue as queue_mod

    sent = 0
    with session_scope() as session:
        if not st.get_bool(session, "auto_retry", True):
            return 0
        for video in due(session):
            attempt = (video.retry_count or 0) + 1
            video.retry_count = attempt
            video.retry_at = utcnow() + next_delay(attempt + 1)
            video.status = "queued"
            video.stage = "queued"
            # Причину не стираем зря: если сборка снова упадёт на том же месте,
            # в журнале будет видно, что это то же самое, а не новая беда.
            reason = (video.error or "")[:200]
            video.error = ""
            session.add(Event(video_id=video.id, level="info", stage="retry",
                              message=f"Догенерация, попытка {attempt} из {MAX_ATTEMPTS}: "
                                      f"сорвалось на «{reason}»"))
            session.commit()
            queue_mod.enqueue(session, "build_video", video_id=video.id)
            sent += 1
            log.info("Ролик %s отправлен на догенерацию (попытка %s)", video.id, attempt)

        # Тем, у кого попытки кончились, говорим об этом один раз.
        for video in session.execute(
                select(Video).where(Video.status == "failed")).scalars():
            if (video.retry_count or 0) < MAX_ATTEMPTS or video.retry_at is None:
                continue
            video.retry_at = None
            session.add(Event(video_id=video.id, level="warn", stage="retry",
                              message=f"Догенерация остановлена: {MAX_ATTEMPTS} попыток "
                                      f"подряд не помогли, нужен человек"))
            session.commit()
    return sent
