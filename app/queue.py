"""Очередь фоновых задач: воркеры разбирают таблицу jobs."""
from __future__ import annotations

import json
import logging
import threading
from typing import Optional

from sqlalchemy import select, update
from sqlalchemy.orm import Session

from . import config
from .db import session_scope
from .models import Job, Video, utcnow

log = logging.getLogger("cf.queue")

_stop = threading.Event()
_threads: list[threading.Thread] = []


def enqueue(session: Session, kind: str, *, video_id: Optional[int] = None,
            payload: Optional[dict] = None) -> Job:
    job = Job(kind=kind, video_id=video_id, payload=json.dumps(payload or {}, ensure_ascii=False))
    session.add(job)
    session.commit()
    log.info("Задача поставлена: %s (video=%s, id=%s)", kind, video_id, job.id)
    return job


_claim_lock = threading.Lock()


def _claim_job() -> Optional[int]:
    """Атомарно забираем одну задачу: UPDATE ... RETURNING, чтобы два воркера не взяли одну."""
    with _claim_lock:
        with session_scope() as session:
            subquery = (
                select(Job.id).where(Job.status == "pending")
                .order_by(Job.id).limit(1).scalar_subquery()
            )
            result = session.execute(
                update(Job)
                .where(Job.id == subquery, Job.status == "pending")
                .values(status="running", attempts=Job.attempts + 1, started_at=utcnow())
                .returning(Job.id)
                .execution_options(synchronize_session=False)
            )
            row = result.first()
            session.commit()
            return int(row[0]) if row else None


def _run_job(job_id: int) -> None:
    from . import pipeline, sync  # локальные импорты: тяжёлые зависимости

    with session_scope() as session:
        job = session.get(Job, job_id)
        kind, video_id = job.kind, job.video_id
        payload = json.loads(job.payload or "{}")

    error = ""
    try:
        if kind == "build_video":
            pipeline.build_video(video_id)
        elif kind == "make_shorts":
            pipeline.build_shorts_job(video_id)
        elif kind == "sync_prices":
            sync.sync_prices()
        elif kind == "sync_models":
            sync.sync_models()
        elif kind == "plan_day":
            from . import planner

            planner.run_schedule(force_date=payload.get("date"))
        else:
            raise RuntimeError(f"неизвестный тип задачи: {kind}")
        status = "done"
    except Exception as exc:  # noqa: BLE001 — воркер не должен умирать
        log.exception("Задача %s (%s) провалилась", job_id, kind)
        status = "failed"
        error = str(exc)[:4000]

    with session_scope() as session:
        job = session.get(Job, job_id)
        if job is not None:
            job.status = status
            job.error = error
            job.finished_at = utcnow()
            session.commit()


def _worker_loop(index: int) -> None:
    log.info("Воркер %s запущен", index)
    while not _stop.is_set():
        try:
            job_id = _claim_job()
            if job_id is None:
                _stop.wait(3.0)
                continue
            _run_job(job_id)
        except Exception:  # noqa: BLE001
            log.exception("Сбой воркера %s", index)
            _stop.wait(5.0)
    log.info("Воркер %s остановлен", index)


def start_workers(count: Optional[int] = None) -> None:
    count = count or config.WORKERS
    if _threads:
        return
    _stop.clear()
    for i in range(count):
        thread = threading.Thread(target=_worker_loop, args=(i + 1,), daemon=True,
                                  name=f"cf-worker-{i + 1}")
        thread.start()
        _threads.append(thread)


def stop_workers() -> None:
    _stop.set()
    for thread in _threads:
        thread.join(timeout=2)
    _threads.clear()


def recover_stuck_jobs() -> None:
    """После рестарта сервиса возвращаем зависшие задачи в очередь."""
    with session_scope() as session:
        stuck = session.execute(select(Job).where(Job.status == "running")).scalars().all()
        for job in stuck:
            if job.attempts >= 3:
                job.status = "failed"
                job.error = "прервано рестартом сервиса"
            else:
                job.status = "pending"
        videos = session.execute(
            select(Video).where(Video.status.notin_(["done", "failed", "cancelled", "queued"]))
        ).scalars().all()
        for video in videos:
            video.status = "queued"
            video.stage = "queued"
        session.commit()
        if stuck or videos:
            log.info("Восстановлено после рестарта: задач %s, роликов %s", len(stuck), len(videos))
