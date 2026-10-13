"""Сторож зависших задач.

Зачем он нужен. Отдельные шаги защищены своими сроками — заявка к Suno падает
через пятнадцать минут, ffmpeg через час, — но у задачи целиком предела нет.
Если она всё-таки встанет намертво, то будет держать слот воркера до тех пор,
пока человек не заметит и не перезапустит службу руками. Именно это и случилось:
две сборки висели в «выполняется» несколько часов, и понять это можно было
только по глазам.

Как он отличает «висит» от «долго работает». По файлам. Любая живая сборка
что-то пишет на диск непрерывно: скачивает треки, кодирует видео, рисует
обложку. Поэтому признак жизни — самый свежий файл в рабочем каталоге задачи.
Способ грубый, зато не требует расставлять отметки по всему конвейеру и не
соврёт на часовом кодировании, где в журнале тишина, а файл растёт.

Почему перезапуск, а не «снять задачу». Поток, застрявший в системном вызове,
изнутри Python остановить нельзя: thread нечем прервать. Вернуть задачу в
очередь, оставив поток жить, — худший вариант: та же работа пойдёт вторым
кругом и второй раз за деньги. Поэтому сторож делает единственное, что
действительно освобождает слот: пишет в журнал и просит службу перезапуститься.
systemd поднимает её снова, а recover_stuck_jobs возвращает незаконченные
задачи в очередь — сборка продолжается с того места, где встала, уже скачанное
и оплаченное не теряется.

Зацикливания не будет: каждый заход прибавляет задаче попытку, и на третьей
recover_stuck_jobs пометит её провалившейся вместо того, чтобы запускать снова.
"""
from __future__ import annotations

import json
import logging
import os
import signal
import threading
from pathlib import Path
from typing import Optional

from sqlalchemy import select

from . import config
from .db import session_scope
from .models import Event, Job, utcnow

log = logging.getLogger("cf.watchdog")

_stop = threading.Event()
_thread: Optional[threading.Thread] = None
# По каким задачам уже сказано в журнале: повторять одно и то же каждые пять
# минут незачем.
_warned: set[int] = set()


def work_dir_of(kind: str, payload: dict) -> Optional[Path]:
    """Где задача этого рода держит файлы. None — следить не по чему."""
    from . import config as cfg

    video_id = payload.get("video_id")
    if kind in ("music_video", "music_short") and video_id:
        return cfg.MEDIA_DIR / "_musicvideo" / str(int(video_id))
    if kind in ("build_video", "assemble_final", "regen_scene", "make_shorts") and video_id:
        return cfg.MEDIA_DIR / str(int(video_id))
    return None


def last_touch(folder: Path) -> float:
    """Время самого свежего файла внутри. 0 — каталога нет или он пуст."""
    newest = 0.0
    if not folder.is_dir():
        return 0.0
    for path in folder.rglob("*"):
        try:
            if path.is_file():
                newest = max(newest, path.stat().st_mtime)
        except OSError:  # файл исчез между обходом и stat — не беда
            continue
    return newest


def _note(message: str, level: str = "warn") -> None:
    with session_scope() as session:
        session.add(Event(level=level, stage="сторож", message=message[:4000]))
        session.commit()


def stalled_jobs(now: float) -> list[tuple[int, str, float]]:
    """Задачи без движения дольше срока: [(id, род, минут без движения)]."""
    limit = config.STUCK_MINUTES * 60.0
    out: list[tuple[int, str, float]] = []
    with session_scope() as session:
        rows = session.execute(
            select(Job).where(Job.status == "running")).scalars().all()
        jobs = [(row.id, row.kind, json.loads(row.payload or "{}"),
                 row.started_at) for row in rows]

    for job_id, kind, payload, started_at in jobs:
        folder = work_dir_of(kind, payload)
        # Отсчитываем от последней записи на диск, а если файлов ещё нет —
        # от начала задачи: заявка к Suno тоже не вечная.
        moved = last_touch(folder) if folder is not None else 0.0
        if not moved and started_at is not None:
            moved = started_at.timestamp()
        if not moved:
            continue
        idle = now - moved
        if idle > limit:
            out.append((job_id, kind, idle / 60.0))
    return out


def check(now: Optional[float] = None) -> list[tuple[int, str, float]]:
    """Один обход. Возвращает зависшие задачи — для сторожа и для тестов."""
    import time

    found = stalled_jobs(now if now is not None else time.time())
    for job_id, kind, minutes in found:
        if job_id in _warned:
            continue
        _warned.add(job_id)
        message = (f"Задача #{job_id} ({kind}) без движения {minutes:.0f} мин — "
                   f"ни один файл не менялся. ")
        message += ("Перезапускаю службу: задача вернётся в очередь и продолжит "
                    "с того места, где встала." if config.STUCK_RESTART
                    else "Перезапуск выключен, задача оставлена как есть.")
        log.warning(message)
        _note(message)
    return found


def _loop() -> None:
    import time

    while not _stop.wait(config.WATCHDOG_EVERY):
        try:
            found = check()
        except Exception:  # noqa: BLE001 — сторож не должен ронять службу
            log.exception("Сторож споткнулся на обходе")
            continue
        if found and config.STUCK_RESTART:
            log.warning("Сторож просит перезапуск из-за задач: %s",
                        ", ".join(f"#{job_id}" for job_id, _, _ in found))
            # SIGINT, а не kill: uvicorn корректно отработает shutdown, воркеры
            # получат сигнал остановки, и systemd поднимет службу заново.
            time.sleep(1.0)
            os.kill(os.getpid(), signal.SIGINT)
            return


def start() -> None:
    """Запустить сторожа. Выключается нулём в CF_STUCK_MINUTES."""
    global _thread

    if config.STUCK_MINUTES <= 0:
        log.info("Сторож выключен (CF_STUCK_MINUTES=0)")
        return
    if _thread is not None and _thread.is_alive():
        return
    _stop.clear()
    _thread = threading.Thread(target=_loop, name="watchdog", daemon=True)
    _thread.start()
    log.info("Сторож запущен: предел без движения %s мин, обход раз в %s с, "
             "перезапуск %s", config.STUCK_MINUTES, config.WATCHDOG_EVERY,
             "включён" if config.STUCK_RESTART else "выключен")


def stop() -> None:
    _stop.set()
    if _thread is not None:
        _thread.join(timeout=2)
