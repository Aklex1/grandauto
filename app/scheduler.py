"""Периодические задачи: суточное обновление цен и запуск генерации по расписанию."""
from __future__ import annotations

import logging

from apscheduler.schedulers.background import BackgroundScheduler
from apscheduler.triggers.cron import CronTrigger

from .db import session_scope
from . import settings_store as st

log = logging.getLogger("cf.scheduler")

_scheduler: BackgroundScheduler | None = None


def _job_sync_prices() -> None:
    from . import sync

    log.info("Плановое обновление прайса KIE")
    sync.daily_sync()


def _job_run_schedule() -> None:
    from . import planner

    with session_scope() as session:
        if not st.get_bool(session, "auto_run_schedule", True):
            return
    created = planner.run_schedule()
    if created:
        log.info("Расписание поставило роликов: %s", len(created))


def start() -> BackgroundScheduler:
    global _scheduler
    if _scheduler is not None:
        return _scheduler
    sched = BackgroundScheduler(timezone="UTC")
    # Прайс и справочники — раз в сутки.
    sched.add_job(_job_sync_prices, CronTrigger(hour=3, minute=20), id="sync_prices",
                  replace_existing=True, misfire_grace_time=3600)
    # Расписание каналов проверяем каждые 15 минут.
    sched.add_job(_job_run_schedule, CronTrigger(minute="*/15"), id="run_schedule",
                  replace_existing=True, misfire_grace_time=900)
    sched.start()
    _scheduler = sched
    log.info("Планировщик запущен")
    return sched


def shutdown() -> None:
    global _scheduler
    if _scheduler is not None:
        _scheduler.shutdown(wait=False)
        _scheduler = None
