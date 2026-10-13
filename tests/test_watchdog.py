"""Сторож зависших задач: видит стоящую сборку и не трогает работающую."""
import json
import os
import sys
import time

sys.path.insert(0, "/home/user/grandauto")
from pathlib import Path

from app import bootstrap; bootstrap.run()
from app import config, watchdog
from app.db import session_scope
from app.models import Event, Job

OUT = Path(os.environ["OUT"]); OUT.mkdir(parents=True, exist_ok=True)
now = time.time()


def make_job(kind: str, video_id: int) -> int:
    with session_scope() as session:
        job = Job(kind=kind, payload=json.dumps({"video_id": video_id}),
                  status="running", attempts=1)
        session.add(job)
        session.commit()
        return job.id


def touch(video_id: int, minutes_ago: float) -> Path:
    """Файл в рабочем каталоге микса — как будто его писали столько минут назад."""
    folder = config.MEDIA_DIR / "_musicvideo" / str(video_id) / "render"
    folder.mkdir(parents=True, exist_ok=True)
    path = folder / "body.mp4"
    path.write_bytes(b"x" * 1024)
    stamp = now - minutes_ago * 60
    os.utime(path, (stamp, stamp))
    return path


config.STUCK_MINUTES = 45
config.WATCHDOG_EVERY = 300
config.STUCK_RESTART = True

# --- 1. живая сборка: файл обновлялся только что ---------------------------------
alive = make_job("music_video", 101)
touch(101, minutes_ago=2)
found = watchdog.check(now=now)
assert not found, f"сторож тронул живую сборку: {found}"
print("1. сборка, которая пишет на диск, сторожем не трогается")

# Долгое кодирование — это тоже жизнь: в журнале тишина, а файл растёт.
touch(101, minutes_ago=44)
assert not watchdog.check(now=now), "44 минуты — ещё в пределах"
print("2. сорок четыре минуты без записи — ещё не повод (предел 45)")

# --- 2. вставшая сборка ------------------------------------------------------------
stuck = make_job("music_video", 102)
touch(102, minutes_ago=90)
found = watchdog.check(now=now)
assert [job_id for job_id, _, _ in found] == [stuck], found
minutes = found[0][2]
assert 89 <= minutes <= 91, minutes
print(f"3. сборка без единой записи {minutes:.0f} мин — сторож её нашёл")

# Повторный обход не сыплет одно и то же в журнал.
again = watchdog.check(now=now)
assert [job_id for job_id, _, _ in again] == [stuck], again
with session_scope() as session:
    notes = session.execute(
        Event.__table__.select().where(Event.stage == "сторож")).all()
assert len(notes) == 1, f"записей в журнале {len(notes)}, а должна быть одна"
assert f"#{stuck}" in notes[0].message and "без движения" in notes[0].message
print("4. в журнал сказано один раз, а не каждые пять минут")

# --- 3. задача без файлов считается от старта -------------------------------------
import datetime as dt

with session_scope() as session:
    job = session.get(Job, make_job("music_video", 103))
    job.started_at = dt.datetime.utcfromtimestamp(now - 120 * 60)
    session.commit()
    old_id = job.id
found = watchdog.check(now=now)
assert old_id in [job_id for job_id, _, _ in found], found
print("5. задача, не создавшая ни одного файла, считается от начала")

# --- 4. завершённые задачи сторожу не интересны -----------------------------------
with session_scope() as session:
    done = Job(kind="music_video", payload=json.dumps({"video_id": 104}),
               status="done", attempts=1)
    session.add(done)
    session.commit()
    done_id = done.id
touch(104, minutes_ago=600)
assert done_id not in [job_id for job_id, _, _ in watchdog.check(now=now)]
print("6. выполненные задачи не трогаем, как бы давно они ни писали файлы")

# --- 5. выключатель ----------------------------------------------------------------
config.STUCK_MINUTES = 0
watchdog._thread = None
watchdog.start()
assert watchdog._thread is None, "сторож запустился при нуле"
print("7. CF_STUCK_MINUTES=0 выключает сторожа совсем")

print("ВСЁ ПРОШЛО")
