"""Почему очередь ComfyUI пуста.

Запускать на сервере: venv/bin/python deploy/diag_comfy.py

Показывает всё, от чего зависит локальная генерация: режим связи, токен, графы,
настройки каналов, состояние очереди и последние события. Ничего не меняет и
ничего не запускает — только читает.
"""
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))
from sqlalchemy import func, select
from app.db import session_scope
from app.models import Channel, ComfyTask, ComfyWorkflow, Event, Job
from app import pipeline
from app import settings_store as st

with session_scope() as s:
    print("=== связь")
    print("  режим:", pipeline.comfy_mode(s))
    print("  токен агента:", "есть" if st.get(s, "comfy_agent_token", "").strip() else "НЕТ")
    print("  адрес для прямого режима:", st.get(s, "comfy_url", "") or "не задан")

    print("=== графы")
    rows = s.execute(select(ComfyWorkflow).where(ComfyWorkflow.is_active.is_(True))).scalars().all()
    if not rows:
        print("  НИ ОДНОГО — без графа локальная ветка не включится")
    for w in rows:
        step = getattr(w, "frame_step", 1) or 1
        print(f"  #{w.id} «{w.name}»: метки [{w.placeholders}], fps {w.fps}, "
              f"длина {'любая' if step <= 1 else f'{step}n+1'}")

    print("=== каналы")
    for ch in s.execute(select(Channel)).scalars():
        use, setup = pipeline.comfy_plan(s, ch)
        where = "локально" if use else "в облаке"
        if (ch.video_source or "kie") == "comfy" and not use:
            where = "В ОБЛАКЕ — просит локально, но не настроено"
        print(f"  #{ch.id} «{ch.name}»: источник={ch.video_source}, "
              f"граф={ch.comfy_workflow_id} → считает {where}")

    print("=== задания ComfyUI")
    counts = s.execute(select(ComfyTask.status, func.count(ComfyTask.id))
                       .group_by(ComfyTask.status)).all()
    print("  по состояниям:", dict(counts) or "пусто")
    for t in s.execute(select(ComfyTask).order_by(ComfyTask.id.desc()).limit(5)).scalars():
        print(f"  #{t.id} {t.status}: агент «{t.agent}», попыток {t.attempts}, "
              f"{(t.error or '')[:90]}")

    print("=== очередь задач завода")
    for j in s.execute(select(Job).order_by(Job.id.desc()).limit(5)).scalars():
        print(f"  #{j.id} {j.kind} → {j.status}: {(j.error or '')[:120]}")

    print("=== последние события")
    for e in s.execute(select(Event).order_by(Event.id.desc()).limit(20)).scalars():
        print(f"  {e.created_at:%d.%m %H:%M} [{e.level}/{e.stage}] {e.message[:150]}")
