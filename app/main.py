"""Веб-приложение контент-завода (FastAPI + серверный рендеринг)."""
from __future__ import annotations

import datetime as dt
import json
import hmac
import logging
import shutil
import secrets
from pathlib import Path
from typing import Optional

from fastapi import Depends, FastAPI, Form, HTTPException, Request
from fastapi.responses import (FileResponse, HTMLResponse, JSONResponse,
                               RedirectResponse, Response)
from fastapi.staticfiles import StaticFiles
from fastapi.templating import Jinja2Templates
from starlette.datastructures import UploadFile
from sqlalchemy import func, select
from sqlalchemy.orm import Session

from . import (bootstrap, config, estimate, fonts, footage, pipeline, planner, prompts,
               queue, scheduler, stock, storage, subtitles, sync, webutil)
from . import settings_store as st
from . import archives, comfy, references
from .db import get_session, session_scope
from .kie import KieClient
from .models import (ArchiveBatch, ArchiveItem, Channel, ComfyWorkflow, Event, Footage, Job,
                     ModelPath, PlanItem, PriceItem,
                     ScheduleRule, Scene, Short, Video, Voice, utcnow)
from .security import make_session, read_session, verify_password

logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s %(levelname)s %(name)s: %(message)s",
)
# Опрос статусов задач KIE идёт каждые несколько секунд — не засоряем журнал.
logging.getLogger("httpx").setLevel(logging.WARNING)
logging.getLogger("httpcore").setLevel(logging.WARNING)
logging.getLogger("apscheduler.executors.default").setLevel(logging.WARNING)
log = logging.getLogger("cf.web")

BASE_DIR = Path(__file__).parent
templates = Jinja2Templates(directory=str(BASE_DIR / "templates"))

app = FastAPI(title="Контент-завод", docs_url=None, redoc_url=None)
app.mount("/static", StaticFiles(directory=str(BASE_DIR / "static")), name="static")
# Проверка владения доменом для Let's Encrypt. Каталог пустой в обычное время;
# certbot кладёт туда файл на время выпуска. Без авторизации — иначе проверяющий
# сервер её не пройдёт; отдаются только файлы, положенные самим certbot.
app.mount("/.well-known/acme-challenge",
          StaticFiles(directory=str(config.ACME_DIR), check_dir=False),
          name="acme")

WEEKDAYS = ["Пн", "Вт", "Ср", "Чт", "Пт", "Сб", "Вс"]
STATUS_LABELS = {
    "queued": "в очереди", "script": "сценарий", "voice": "озвучка", "visuals": "видеоряд",
    "assemble": "сборка", "subtitles": "субтитры", "thumbnail": "обложка",
    "metadata": "метаданные", "shorts": "шортсы", "done": "готов", "failed": "ошибка",
    "cancelled": "отменён", "planned": "в плане", "in_progress": "в работе", "skipped": "пропущено",
    "pending": "ожидает", "running": "выполняется", "ready": "готов", "processing": "обработка",
    "voiced": "озвучена", "voice_failed": "нет озвучки", "piece_ready": "сцена готова",
    "scenes_ready": "сцены готовы",
    "clip_failed": "нет видеоряда",
    "regenerating": "пересборка", "regen_failed": "пересборка не удалась",
}


# --------------------------------------------------------------------------- жизненный цикл

@app.on_event("startup")
def on_startup() -> None:
    info = bootstrap.run()
    if info.get("admin_password"):
        log.warning("СГЕНЕРИРОВАН ПАРОЛЬ АДМИНИСТРАТОРА: %s", info["admin_password"])
    # правила разбора моделей меняются вместе с кодом — пересчитываем типы у
    # сохранённых записей, иначе в списке останутся модели, которые не заработают
    try:
        sync.reclassify_models()
    except Exception:  # noqa: BLE001 — справочник не должен мешать старту
        log.exception("Не удалось пересчитать типы моделей")
    queue.recover_stuck_jobs()
    queue.start_workers()
    scheduler.start()
    with session_scope() as session:
        if sync.prices_are_stale(session):
            queue.enqueue(session, "sync_prices")
            queue.enqueue(session, "sync_models")


@app.on_event("shutdown")
def on_shutdown() -> None:
    scheduler.shutdown()
    queue.stop_workers()


# --------------------------------------------------------------------------- авторизация

def current_user(request: Request) -> Optional[str]:
    return read_session(request.cookies.get(config.SESSION_COOKIE))


def require_user(request: Request) -> str:
    user = current_user(request)
    if not user:
        raise HTTPException(status_code=307, headers={"Location": "/login"})
    return user


@app.exception_handler(HTTPException)
async def http_exception_handler(request: Request, exc: HTTPException):
    if exc.status_code == 307 and exc.headers and "Location" in exc.headers:
        return RedirectResponse(exc.headers["Location"], status_code=307)
    if request.url.path.startswith("/api/"):
        return JSONResponse({"error": exc.detail}, status_code=exc.status_code)
    return templates.TemplateResponse(
        "error.html", {"request": request, "code": exc.status_code, "detail": exc.detail},
        status_code=exc.status_code)


@app.get("/login", response_class=HTMLResponse)
def login_form(request: Request, error: str = ""):
    if current_user(request):
        return RedirectResponse("/", status_code=303)
    return templates.TemplateResponse("login.html", {"request": request, "error": error})


@app.post("/login")
def login(request: Request, username: str = Form(...), password: str = Form(...),
          session: Session = Depends(get_session)):
    expected_user = st.get(session, "admin_user", config.ADMIN_USER)
    hashed = st.get(session, "admin_password_hash", "")
    if username.strip() != expected_user or not verify_password(password, hashed):
        return templates.TemplateResponse(
            "login.html", {"request": request, "error": "Неверный логин или пароль"},
            status_code=401)
    response = RedirectResponse("/", status_code=303)
    response.set_cookie(config.SESSION_COOKIE, make_session(username.strip()),
                        max_age=config.SESSION_MAX_AGE, httponly=True, samesite="lax")
    return response


@app.get("/logout")
def logout():
    response = RedirectResponse("/login", status_code=303)
    response.delete_cookie(config.SESSION_COOKIE)
    return response


# --------------------------------------------------------------------------- общие данные шаблонов

def base_context(request: Request, session: Session, **extra) -> dict:
    channels = session.execute(
        select(Channel).order_by(Channel.position, Channel.id)).scalars().all()
    from . import musicchannels as _mch

    ctx = {
        "request": request,
        "channels": channels,
        "music_channels": _mch.channels(session),
        "weekdays": WEEKDAYS,
        "labels": STATUS_LABELS,
        "settings": st.all_settings(session),
        "human_size": storage.human_size,
        "disk_free_global": storage.free_bytes(),
        "now": utcnow(),
    }
    ctx.update(extra)
    return ctx


def _channel_or_404(session: Session, channel_id: int) -> Channel:
    channel = session.get(Channel, channel_id)
    if channel is None:
        raise HTTPException(status_code=404, detail="Канал не найден")
    return channel


def _video_or_404(session: Session, video_id: int) -> Video:
    video = session.get(Video, video_id)
    if video is None:
        raise HTTPException(status_code=404, detail="Ролик не найден")
    return video


# --------------------------------------------------------------------------- дашборд

@app.get("/", response_class=HTMLResponse)
def dashboard(request: Request, session: Session = Depends(get_session),
              _user: str = Depends(require_user)):
    total = session.execute(select(func.count(Video.id))).scalar() or 0
    done = session.execute(
        select(func.count(Video.id)).where(Video.status == "done")).scalar() or 0
    failed = session.execute(
        select(func.count(Video.id)).where(Video.status == "failed")).scalar() or 0
    active = session.execute(
        select(func.count(Video.id)).where(
            Video.status.notin_(["done", "failed", "cancelled"]))).scalar() or 0
    spent = session.execute(select(func.sum(Video.cost_credits))).scalar() or 0.0
    shorts_total = session.execute(select(func.count(Short.id))).scalar() or 0
    recent = session.execute(
        select(Video).order_by(Video.created_at.desc()).limit(12)).scalars().all()
    jobs = session.execute(
        select(Job).where(Job.status.in_(["pending", "running"])).order_by(Job.id)).scalars().all()
    upcoming = session.execute(
        select(PlanItem).where(PlanItem.status == "planned",
                               PlanItem.scheduled_date.isnot(None))
        .order_by(PlanItem.scheduled_date).limit(10)).scalars().all()

    return templates.TemplateResponse("dashboard.html", base_context(
        request, session, stats={
            "total": total, "done": done, "failed": failed, "active": active,
            "spent": round(float(spent), 2), "shorts": shorts_total,
        }, recent=recent, jobs=jobs, upcoming=upcoming, disk=storage.disk_usage()))


# --------------------------------------------------------------------------- каналы

# --------------------------------------------------------------- агент ComfyUI
# Второй способ работы: агент на домашнем компьютере сам спрашивает работу и
# приносит результат. Ничего открывать наружу не нужно — соединение идёт изнутри.

def _agent_token(session: Session) -> str:
    return st.get(session, "comfy_agent_token", "").strip()


def _check_agent(session: Session, token: str) -> None:
    """Пускаем только со своим токеном.

    Ручки агента живут вне сессии администратора — иначе скрипту пришлось бы
    держать куки. Значит проверка тут обязательна и своя.
    """
    expected = _agent_token(session)
    if not expected:
        raise HTTPException(status_code=503, detail="Агент не настроен: нет токена")
    # Сравнение постоянного времени: по скорости ответа иначе можно подобрать
    # токен посимвольно.
    if not hmac.compare_digest(token or "", expected):
        raise HTTPException(status_code=403, detail="Неверный токен агента")


@app.get("/api/comfy/next")
def comfy_agent_next(session: Session = Depends(get_session), token: str = "",
                     agent: str = "", peek: int = 0):
    """Следующее задание для агента. Пусто — значит работы нет.

    С peek=1 только смотрим очередь и ничего не забираем: так агент проверяет
    связь, не съедая чужое задание.
    """
    from .models import ComfyTask, ComfyWorkflow

    _check_agent(session, token)
    # Задание, зависшее у агента, возвращаем в очередь: агент мог выключиться.
    stale = utcnow() - dt.timedelta(minutes=30)
    for row in session.execute(select(ComfyTask).where(
            ComfyTask.status == "taken", ComfyTask.taken_at < stale)).scalars():
        row.status = "pending"
        row.error = "агент не ответил за 30 минут, задание вернулось в очередь"
    session.commit()

    task = session.execute(select(ComfyTask).where(ComfyTask.status == "pending")
                           .order_by(ComfyTask.id)).scalars().first()
    if task is None:
        return JSONResponse({"task": None})
    if peek:
        return JSONResponse({"task": None, "waiting": True, "id": task.id})

    workflow = session.get(ComfyWorkflow, task.workflow_id or 0)
    if workflow is None or not workflow.is_active:
        task.status = "failed"
        task.error = "граф ComfyUI не найден"
        session.commit()
        return JSONResponse({"task": None})

    try:
        graph = json.loads(workflow.graph or "{}")
    except ValueError as exc:
        task.status = "failed"
        task.error = f"граф не разобран: {exc}"[:2000]
        session.commit()
        return JSONResponse({"task": None})

    task.status = "taken"
    task.taken_at = utcnow()
    task.attempts = (task.attempts or 0) + 1
    task.agent = (agent or "")[:120]
    session.commit()
    # Метки подставляем здесь, а не в агенте: правила подстановки живут в одном
    # месте, и скрипт на домашнем компьютере не надо обновлять при их правке.
    return JSONResponse({"task": {
        "id": task.id, "prompt": task.prompt, "seconds": task.seconds,
        "width": task.width, "height": task.height,
        "fps": workflow.fps or 30, "workflow": workflow.name,
        "graph": comfy.fill(graph, prompt=task.prompt or "",
                            seconds=float(task.seconds or 5.0),
                            width=int(task.width or 720),
                            height=int(task.height or 1280),
                            fps=int(workflow.fps or 30),
                            frame_step=int(workflow.frame_step or 1)),
    }})


@app.post("/api/comfy/{task_id}/result")
async def comfy_agent_result(task_id: int, request: Request,
                             session: Session = Depends(get_session)):
    """Агент принёс готовый клип."""
    from .models import ComfyTask

    form = await request.form()
    _check_agent(session, str(form.get("token") or ""))
    task = session.get(ComfyTask, task_id)
    if task is None:
        raise HTTPException(status_code=404, detail="Задание не найдено")

    upload = form.get("file")
    if not isinstance(upload, UploadFile) or not upload.filename:
        raise HTTPException(status_code=400, detail="Нет файла")
    data = await upload.read()
    if not data:
        raise HTTPException(status_code=400, detail="Пустой файл")

    dest = config.MEDIA_DIR / "_comfy" / f"task_{task.id:06d}{Path(upload.filename).suffix or '.mp4'}"
    dest.parent.mkdir(parents=True, exist_ok=True)
    dest.write_bytes(data)
    # Файл от агента приходит без проверки со стороны сети, поэтому смотрим, что
    # это вообще читаемое видео, а не обрезок.
    if storage.media_duration(dest) <= 0:
        dest.unlink(missing_ok=True)
        task.status = "failed"
        task.error = "агент прислал файл, который не читается как видео"
        session.commit()
        raise HTTPException(status_code=400, detail=task.error)

    task.result_path = storage.rel(dest)
    task.status = "done"
    task.error = ""
    task.finished_at = utcnow()
    session.commit()
    return JSONResponse({"ok": True, "size": len(data)})


@app.post("/api/comfy/{task_id}/ping")
async def comfy_agent_ping(task_id: int, request: Request,
                           session: Session = Depends(get_session)):
    """Сердцебиение: кадр ещё считается.

    Без него сервер через полчаса счёл бы агента мёртвым и вернул задание в
    очередь — а на небыстрой видеокарте полчаса на кадр это норма работы, а не
    поломка. Заодно это единственный признак жизни, по которому сборка отличает
    «агент думает» от «агента выключили».
    """
    from .models import ComfyTask

    form = await request.form()
    _check_agent(session, str(form.get("token") or ""))
    task = session.get(ComfyTask, task_id)
    if task is None:
        raise HTTPException(status_code=404, detail="Задание не найдено")
    if task.status == "taken":
        task.taken_at = utcnow()
        session.commit()
    return JSONResponse({"ok": True, "status": task.status})


@app.post("/api/comfy/{task_id}/error")
async def comfy_agent_error(task_id: int, request: Request,
                            session: Session = Depends(get_session)):
    """Агент не смог — записываем причину, чтобы она была видна в панели."""
    from .models import ComfyTask

    form = await request.form()
    _check_agent(session, str(form.get("token") or ""))
    task = session.get(ComfyTask, task_id)
    if task is None:
        raise HTTPException(status_code=404, detail="Задание не найдено")
    task.status = "failed"
    task.error = str(form.get("error") or "")[:2000]
    task.finished_at = utcnow()
    session.commit()
    return JSONResponse({"ok": True})


@app.post("/comfy/agent-token")
def comfy_agent_token_set(session: Session = Depends(get_session),
                          _user: str = Depends(require_user)):
    """Выдать новый токен агента. Старый сразу перестаёт работать."""
    st.set_value(session, "comfy_agent_token", secrets.token_urlsafe(32))
    session.commit()
    return RedirectResponse("/comfy", status_code=303)


AGENT_SCRIPT = Path(__file__).resolve().parent.parent / "deploy" / "comfy_agent.py"


@app.get("/comfy/agent.py")
def comfy_agent_script():
    """Скрипт агента одним файлом — чтобы его можно было забрать curl-ом."""
    if not AGENT_SCRIPT.exists():
        raise HTTPException(status_code=404, detail="Скрипт агента не найден")
    return FileResponse(AGENT_SCRIPT, media_type="text/x-python",
                        filename="comfy_agent.py")


@app.get("/comfy", response_class=HTMLResponse)
def comfy_page(request: Request, session: Session = Depends(get_session),
               _user: str = Depends(require_user)):
    """Локальный ComfyUI: способ связи, адрес, графы, очередь агента."""
    from .models import ComfyTask, ComfyWorkflow

    rows = session.execute(select(ComfyWorkflow).where(ComfyWorkflow.is_active.is_(True))
                           .order_by(ComfyWorkflow.id.desc())).scalars().all()
    channels = session.execute(select(Channel).order_by(Channel.id)).scalars().all()
    tasks = session.execute(select(ComfyTask).order_by(ComfyTask.id.desc())
                            .limit(15)).scalars().all()
    waiting = session.execute(select(func.count(ComfyTask.id))
                              .where(ComfyTask.status == "pending")).scalar() or 0
    # Что каждый граф просит из установленного — чтобы было с чем сверять вывод
    # «--inspect» на домашнем компьютере.
    wf_nodes = {}
    for row in rows:
        try:
            wf_nodes[row.id] = comfy.node_types(json.loads(row.graph or "{}"))
        except ValueError:
            wf_nodes[row.id] = []
    return templates.TemplateResponse("comfy.html", base_context(
        request, session, workflows=rows, comfy_channels=channels,
        placeholders=comfy.PLACEHOLDERS, wf_nodes=wf_nodes,
        comfy_url=st.get(session, "comfy_url", ""),
        comfy_mode=pipeline.comfy_mode(session), comfy_modes=pipeline.COMFY_MODES,
        agent_token=_agent_token(session), comfy_tasks=tasks, comfy_waiting=waiting,
        server_url=str(request.base_url).rstrip("/"),
        video_sources=pipeline.VIDEO_SOURCES))


@app.post("/comfy/settings")
def comfy_settings(session: Session = Depends(get_session), _user: str = Depends(require_user),
                   comfy_url: str = Form(""), comfy_timeout: float = Form(1800.0),
                   comfy_mode: str = Form("agent")):
    st.set_value(session, "comfy_url", comfy_url.strip().rstrip("/")[:300])
    st.set_value(session, "comfy_timeout", max(60.0, min(7200.0, comfy_timeout)))
    if comfy_mode in pipeline.COMFY_MODES:
        st.set_value(session, "comfy_mode", comfy_mode)
    # set_value только флашит запись — без коммита настройка не переживёт запрос.
    session.commit()
    return RedirectResponse("/comfy", status_code=303)


@app.post("/comfy/check")
def comfy_check(session: Session = Depends(get_session), _user: str = Depends(require_user)):
    """Проверка связи с ComfyUI — самый частый вопрос «а видит ли он его вообще»."""
    url = st.get(session, "comfy_url", "").strip()
    if not url:
        return RedirectResponse("/comfy?check=no-url", status_code=303)
    try:
        client = comfy.ComfyClient(url, timeout=20.0)
        stats = client.ping()
        device = ((stats.get("devices") or [{}])[0].get("name") or "?")[:80]
        try:
            installed = len(client.object_info())
        except Exception:  # noqa: BLE001 — связь есть, а список нод не обязателен
            installed = 0
        session.add(Event(level="info", stage="comfy",
                          message=f"ComfyUI отвечает: {device}"
                                  + (f", нод установлено {installed}" if installed else "")))
        session.commit()
        return RedirectResponse("/comfy?check=ok", status_code=303)
    except Exception as exc:  # noqa: BLE001
        session.add(Event(level="warn", stage="comfy",
                          message=f"ComfyUI недоступен по {url}: {str(exc)[:300]}"))
        session.commit()
        return RedirectResponse("/comfy?check=fail", status_code=303)


@app.post("/comfy/workflows")
async def comfy_workflow_add(request: Request, session: Session = Depends(get_session),
                             _user: str = Depends(require_user)):
    """Загрузка графа в API-формате (Save (API Format) в самом ComfyUI)."""
    from .models import ComfyWorkflow

    form = await request.form()
    name = str(form.get("name") or "").strip()[:200]
    note = str(form.get("note") or "").strip()[:2000]
    fps = int(float(form.get("fps") or 30))
    frame_step = int(float(form.get("frame_step") or 1))
    raw = str(form.get("graph") or "").strip()

    upload = form.get("file")
    if isinstance(upload, UploadFile) and upload.filename:
        raw = (await upload.read()).decode("utf-8", errors="replace")
        name = name or Path(upload.filename).stem[:200]

    try:
        graph = json.loads(raw or "{}")
    except ValueError as exc:
        return RedirectResponse(f"/comfy?error=bad-json&detail={str(exc)[:80]}",
                                status_code=303)
    if not isinstance(graph, dict) or not graph:
        return RedirectResponse("/comfy?error=empty", status_code=303)
    # В UI-формате графа есть ключ "nodes" со списком; API-формат — это словарь
    # нод по их номерам. Перепутать легко, а ошибка всплывёт только при запуске.
    if "nodes" in graph and isinstance(graph.get("nodes"), list):
        return RedirectResponse("/comfy?error=ui-format", status_code=303)

    marks = comfy.used_placeholders(graph)
    if "%PROMPT%" not in marks:
        return RedirectResponse("/comfy?error=no-prompt", status_code=303)

    row = ComfyWorkflow(
        name=name or f"Граф {len(graph)} нод", note=note,
        graph=json.dumps(graph, ensure_ascii=False),
        placeholders=", ".join(marks)[:300], fps=max(1, min(60, fps)),
        frame_step=max(1, min(16, frame_step)))
    session.add(row)
    session.commit()
    session.add(Event(level="info", stage="comfy",
                      message=f"Добавлен граф ComfyUI «{row.name}» ({len(graph)} нод, "
                              f"метки: {row.placeholders})"))
    session.commit()
    return RedirectResponse("/comfy?added=1", status_code=303)


@app.post("/comfy/workflows/{workflow_id}/drop")
def comfy_workflow_drop(workflow_id: int, session: Session = Depends(get_session),
                        _user: str = Depends(require_user)):
    from .models import ComfyWorkflow

    row = session.get(ComfyWorkflow, workflow_id)
    if row is not None:
        row.is_active = False
        session.commit()
    return RedirectResponse("/comfy", status_code=303)


@app.get("/channels", response_class=HTMLResponse)
def channels_page(request: Request, session: Session = Depends(get_session),
                  _user: str = Depends(require_user)):
    """Общая вкладка каналов: что уже есть и как завести новый."""
    rows = session.execute(select(Channel).order_by(Channel.position, Channel.id)).scalars().all()
    stats = {}
    for ch in rows:
        stats[ch.id] = {
            "plan": session.execute(select(func.count(PlanItem.id)).where(
                PlanItem.channel_id == ch.id, PlanItem.status == "planned")).scalar() or 0,
            "videos": session.execute(select(func.count(Video.id)).where(
                Video.channel_id == ch.id)).scalar() or 0,
            "done": session.execute(select(func.count(Video.id)).where(
                Video.channel_id == ch.id, Video.status == "done")).scalar() or 0,
        }
    return templates.TemplateResponse("channels.html", base_context(
        request, session, channel_rows=rows, channel_stats=stats,
        content_sources=prompts.CONTENT_SOURCES,
        archive_cover_modes=archives.COVER_MODES))


@app.post("/channels/create")
async def channel_create(request: Request, session: Session = Depends(get_session),
                         _user: str = Depends(require_user),
                         name: str = Form(...), topic: str = Form(""),
                         description: str = Form(""),
                         audience: str = Form(""), content_source: str = Form("books"),
                         language: str = Form("ru")):
    name = name.strip()[:200]
    if not name:
        return RedirectResponse("/channels?error=no-name", status_code=303)

    base = storage.slugify(name, 60) or "channel"
    slug, n = base, 1
    # Slug уникален в базе: без проверки создание второго канала с похожим именем
    # падало бы ошибкой уникальности прямо в лицо пользователю.
    while session.execute(select(Channel).where(Channel.slug == slug)).scalars().first():
        n += 1
        slug = f"{base}-{n}"

    position = (session.execute(select(func.max(Channel.position))).scalar() or 0) + 1
    channel = Channel(
        name=name, slug=slug, topic=topic.strip(), description=description.strip(),
        audience=audience.strip()[:300], language=(language or "ru")[:10],
        content_source=content_source if content_source in prompts.CONTENT_SOURCES else "books",
        position=position,
        chat_model=st.get(session, "default_chat_model", "") or Channel.chat_model.default.arg,
        video_model=st.get(session, "default_video_model", "") or Channel.video_model.default.arg,
        image_model=st.get(session, "default_image_model", "") or Channel.image_model.default.arg,
        tts_model=st.get(session, "default_tts_model", "") or Channel.tts_model.default.arg,
    )
    session.add(channel)
    session.commit()
    session.add(Event(level="info", stage="каналы",
                      message=f"Создан канал «{channel.name}» ({channel.slug})"))
    session.commit()

    # Архив при создании канала — необязательный: с ним канал сразу получает
    # список будущих роликов, без него всё как раньше.
    form = await request.form()
    upload = form.get("archive")
    if isinstance(upload, UploadFile) and upload.filename:
        try:
            archives.import_zip(session, channel, await upload.read(),
                                name=upload.filename,
                                cover_mode=str(form.get("cover_mode") or "uploaded"),
                                per_day=int(float(form.get("per_day") or 0)))
        except archives.ArchiveError as exc:
            session.add(Event(level="warn", stage="archive",
                              message=f"Архив к каналу «{channel.name}» не принят: {exc}"))
            session.commit()
            return RedirectResponse(
                f"/channels/{channel.id}?tab=archive&error=bad-zip&detail={str(exc)[:120]}",
                status_code=303)
        return RedirectResponse(f"/channels/{channel.id}?tab=archive", status_code=303)
    return RedirectResponse(f"/channels/{channel.id}?tab=settings", status_code=303)


@app.post("/channels/{channel_id}/archives")
async def channel_archive_add(channel_id: int, request: Request,
                              session: Session = Depends(get_session),
                              _user: str = Depends(require_user)):
    """Загрузка архива с готовыми материалами: папка на серию."""
    from . import archives

    channel = _channel_or_404(session, channel_id)
    form = await request.form()
    upload = form.get("file")
    if not isinstance(upload, UploadFile) or not upload.filename:
        return RedirectResponse(f"/channels/{channel_id}?tab=archive&error=no-file",
                                status_code=303)
    try:
        batch = archives.import_zip(
            session, channel, await upload.read(), name=upload.filename,
            cover_mode=str(form.get("cover_mode") or "uploaded"),
            per_day=int(float(form.get("per_day") or 0)))
    except archives.ArchiveError as exc:
        return RedirectResponse(
            f"/channels/{channel_id}?tab=archive&error=bad-zip&detail={str(exc)[:120]}",
            status_code=303)
    # Без расписания архив собирается разом — но не молча: кнопку всё равно
    # нажимает человек, иначе сотня серий уедет в работу по одной загрузке файла.
    return RedirectResponse(f"/channels/{channel_id}?tab=archive&added={batch.total}",
                            status_code=303)


@app.post("/archives/{batch_id}/run")
def archive_run(batch_id: int, session: Session = Depends(get_session),
                _user: str = Depends(require_user), scope: str = Form("due")):
    """Запуск вручную: всё разом или только то, чему пришёл срок."""
    from . import archives
    from .models import ArchiveBatch, ArchiveItem

    batch = session.get(ArchiveBatch, batch_id)
    if batch is None:
        raise HTTPException(status_code=404, detail="Архив не найден")
    # «Пересобрать готовые» нужна, когда поправили саму сборку: озвучка при этом
    # берётся с диска, так что пересборка сотни серий не стоит ничего.
    wanted = (("done",) if scope == "redo"
              else ("planned", "failed"))
    rows = session.execute(
        select(ArchiveItem).where(ArchiveItem.batch_id == batch_id,
                                  ArchiveItem.status.in_(wanted))
        .order_by(ArchiveItem.idx)).scalars().all()
    if scope == "due":
        today = dt.date.today()
        rows = [r for r in rows if r.scheduled_date is None or r.scheduled_date <= today]
    if scope == "redo":
        for row in rows:
            row.status = "planned"
        session.commit()
    sent = archives.queue_items(session, rows)
    return RedirectResponse(f"/channels/{batch.channel_id}?tab=archive&queued={sent}",
                            status_code=303)


@app.post("/archive-items/{item_id}/run")
def archive_item_run(item_id: int, session: Session = Depends(get_session),
                     _user: str = Depends(require_user)):
    from . import archives
    from .models import ArchiveItem

    item = session.get(ArchiveItem, item_id)
    if item is None:
        raise HTTPException(status_code=404, detail="Серия не найдена")
    # Готовую серию тоже можно запустить снова: это пересборка, и она не должна
    # упираться в то, что файл уже есть.
    if item.status == "done":
        item.status = "planned"
        session.commit()
    archives.queue_items(session, [item])
    return RedirectResponse(f"/channels/{item.channel_id}?tab=archive", status_code=303)


class _ZipSink:
    """Приёмник для zipfile, который отдаёт байты наружу кусками.

    Сотня готовых роликов — это гигабайты: в память такой архив не соберёшь, а
    писать его во временный файл значит требовать вдвое больше места на диске.
    Поэтому zip собирается на лету и сразу уходит в ответ.
    """

    def __init__(self) -> None:
        self.buffer = bytearray()
        self.offset = 0

    def write(self, data: bytes) -> int:
        self.buffer += data
        return len(data)

    def tell(self) -> int:
        return self.offset + len(self.buffer)

    def flush(self) -> None:
        return None

    def take(self) -> bytes:
        chunk = bytes(self.buffer)
        self.offset += len(chunk)
        self.buffer.clear()
        return chunk


def _zip_stream(files: list):
    """Генератор кусков zip-архива из списка (имя в архиве, путь на диске)."""
    import zipfile

    sink = _ZipSink()
    # ZIP_STORED без сжатия: mp4 уже сжат, а повторное сжатие только греет
    # процессор и ничего не экономит.
    with zipfile.ZipFile(sink, "w", zipfile.ZIP_STORED) as archive:
        for arcname, path in files:
            with archive.open(arcname, "w") as target, open(path, "rb") as source:
                while True:
                    chunk = source.read(1 << 20)
                    if not chunk:
                        break
                    target.write(chunk)
                    data = sink.take()
                    if data:
                        yield data
            data = sink.take()
            if data:
                yield data
    tail = sink.take()
    if tail:
        yield tail


@app.get("/archives/{batch_id}/download")
def archive_download_all(batch_id: int, session: Session = Depends(get_session),
                         _user: str = Depends(require_user)):
    """Все готовые ролики архива одним zip."""
    from fastapi.responses import StreamingResponse

    from .models import ArchiveBatch, ArchiveItem

    batch = session.get(ArchiveBatch, batch_id)
    if batch is None:
        raise HTTPException(status_code=404, detail="Архив не найден")
    rows = session.execute(
        select(ArchiveItem).where(ArchiveItem.batch_id == batch_id,
                                  ArchiveItem.status == "done")
        .order_by(ArchiveItem.idx)).scalars().all()

    files = []
    for item in rows:
        path = storage.abspath(item.video_path) if item.video_path else None
        if path is None or not path.exists():
            continue
        name = f"{item.folder}_{storage.slugify(item.title, 50) or 'reel'}{path.suffix}"
        files.append((name, path))
    if not files:
        raise HTTPException(status_code=404, detail="Готовых роликов пока нет")

    stem = storage.slugify(Path(batch.name).stem, 60) or f"archive{batch.id}"
    total = sum(path.stat().st_size for _, path in files)
    log.info("Архив %s: отдаю %s роликов одним файлом (%.1f ГБ)",
             batch_id, len(files), total / (1 << 30))
    return StreamingResponse(
        _zip_stream(files), media_type="application/zip",
        headers={"Content-Disposition": f'attachment; filename="{stem}_videos.zip"',
                 "X-Reels-Count": str(len(files))})


@app.post("/archives/{batch_id}/settings")
async def archive_settings(batch_id: int, request: Request,
                           session: Session = Depends(get_session),
                           _user: str = Depends(require_user)):
    """Как собирать ролики этого архива: обложка, титры, голос, концовка."""
    from .models import ArchiveBatch

    batch = session.get(ArchiveBatch, batch_id)
    if batch is None:
        raise HTTPException(status_code=404, detail="Архив не найден")
    form = await request.form()

    def num(name: str, low: float, high: float, default: float) -> float:
        try:
            return max(low, min(high, float(form.get(name) or default)))
        except (TypeError, ValueError):
            return default

    def mode(name: str) -> str:
        value = str(form.get(name) or "")
        return value if value in ("on", "off") else ""

    if str(form.get("cover_mode") or "") in archives.COVER_MODES:
        batch.cover_mode = str(form.get("cover_mode"))
    batch.cover_dim = num("cover_dim", 0.0, 0.85, archives.COVER_DIM)
    batch.cover_hold = num("cover_hold", 0.0, 6.0, archives.COVER_HOLD)
    batch.cover_zoom = num("cover_zoom", 1.0, 1.4, archives.COVER_ZOOM)
    batch.tail_sec = num("tail_sec", 0.0, 8.0, archives.TAIL_SECONDS)

    batch.subtitles_mode = mode("subtitles_mode")
    style = str(form.get("subtitle_style") or "")
    batch.subtitle_style = style if style in subtitles.SUBTITLE_STYLES else ""
    batch.title_font = fonts.normalize(str(form.get("title_font") or "")) \
        if form.get("title_font") else ""
    caption_font = str(form.get("caption_font") or "")
    batch.caption_font = fonts.normalize(caption_font) if caption_font else ""
    caption_align = str(form.get("caption_align") or "")
    batch.caption_align = (caption_align
                           if caption_align in ("left", "center", "right") else "")
    batch.music_mode = mode("music_mode")
    batch.music_volume_db = num("music_volume_db", -40.0, 0.0, 0.0)

    batch.tts_model = str(form.get("tts_model") or "").strip()[:120]
    batch.voice_id = str(form.get("voice_id") or "").strip()[:120]
    batch.voice_name = str(form.get("voice_name") or "").strip()[:120]
    batch.voice_speed = num("voice_speed", 0.0, 1.2, 0.0)

    batch.outro_mode = mode("outro_mode")
    batch.outro_url = str(form.get("outro_url") or "").strip()[:300]
    batch.outro_title = str(form.get("outro_title") or "").strip()[:120]
    batch.outro_about = str(form.get("outro_about") or "").strip()[:2000]
    source = str(form.get("outro_source") or "")
    batch.outro_source = source if source in pipeline.OUTRO_SOURCES else ""
    batch.outro_text = str(form.get("outro_text") or "").strip()[:4000]
    session.commit()
    return RedirectResponse(f"/channels/{batch.channel_id}?tab=archive&saved=1",
                            status_code=303)


@app.post("/archives/{batch_id}/stop")
def archive_stop(batch_id: int, session: Session = Depends(get_session),
                 _user: str = Depends(require_user)):
    """Остановить архив: снять очередь, вернуть серии в план, поставить на паузу."""
    from . import archives
    from .models import ArchiveBatch

    batch = session.get(ArchiveBatch, batch_id)
    if batch is None:
        raise HTTPException(status_code=404, detail="Архив не найден")
    jobs, items = archives.stop_batch(session, batch_id)
    session.add(Event(level="warn", stage="archive",
                      message=f"Архив «{batch.name}» остановлен: снято задач {jobs}, "
                              f"серий вернулось в план {items}"))
    session.commit()
    return RedirectResponse(f"/channels/{batch.channel_id}?tab=archive&stopped={items}",
                            status_code=303)


@app.post("/archives/{batch_id}/resume")
def archive_resume(batch_id: int, session: Session = Depends(get_session),
                   _user: str = Depends(require_user)):
    from .models import ArchiveBatch

    batch = session.get(ArchiveBatch, batch_id)
    if batch is None:
        raise HTTPException(status_code=404, detail="Архив не найден")
    batch.paused = False
    session.commit()
    return RedirectResponse(f"/channels/{batch.channel_id}?tab=archive", status_code=303)


@app.post("/archives/{batch_id}/schedule")
def archive_reschedule(batch_id: int, session: Session = Depends(get_session),
                       _user: str = Depends(require_user), per_day: int = Form(0)):
    """Пересчёт расписания: столько-то серий в день, начиная с сегодня."""
    from . import archives
    from .models import ArchiveBatch, ArchiveItem

    batch = session.get(ArchiveBatch, batch_id)
    if batch is None:
        raise HTTPException(status_code=404, detail="Архив не найден")
    batch.per_day = max(0, min(50, per_day))
    rows = session.execute(
        select(ArchiveItem).where(ArchiveItem.batch_id == batch_id,
                                  ArchiveItem.status == "planned")
        .order_by(ArchiveItem.idx)).scalars().all()
    for item, when in zip(rows, archives.schedule_dates(len(rows), batch.per_day)):
        item.scheduled_date = when
    session.commit()
    return RedirectResponse(f"/channels/{batch.channel_id}?tab=archive", status_code=303)


@app.post("/archives/{batch_id}/drop")
def archive_drop(batch_id: int, session: Session = Depends(get_session),
                 _user: str = Depends(require_user)):
    from .models import ArchiveBatch

    batch = session.get(ArchiveBatch, batch_id)
    if batch is None:
        raise HTTPException(status_code=404, detail="Архив не найден")
    channel_id = batch.channel_id
    session.delete(batch)
    session.commit()
    return RedirectResponse(f"/channels/{channel_id}?tab=archive", status_code=303)


@app.post("/channels/{channel_id}/references")
async def channel_reference_add(channel_id: int, request: Request,
                                session: Session = Depends(get_session),
                                _user: str = Depends(require_user)):
    """Загрузка своих образцов стиля."""
    from . import references as refs

    channel = _channel_or_404(session, channel_id)
    form = await request.form()
    kind = str(form.get("kind") or "style")
    note = str(form.get("note") or "")
    added, skipped = 0, []
    for upload in form.getlist("files"):
        if not isinstance(upload, UploadFile) or not upload.filename:
            continue
        data = await upload.read()
        row = refs.add(session, channel.id, channel.slug, upload.filename, data,
                       kind=kind, note=note)
        if row is None:
            skipped.append(upload.filename)
        else:
            added += 1
    message = f"Канал «{channel.name}»: добавлено референсов {added}"
    if skipped:
        message += ". Пропущены (неподходящий тип): " + ", ".join(skipped[:5])
    session.add(Event(level="warn" if skipped else "info", stage="каналы",
                      message=message[:2000]))
    session.commit()
    return RedirectResponse(f"/channels/{channel_id}?tab=references", status_code=303)


@app.post("/channels/{channel_id}/references/{ref_id}/drop")
def channel_reference_drop(channel_id: int, ref_id: int,
                           session: Session = Depends(get_session),
                           _user: str = Depends(require_user),
                           delete_file: str = Form("")):
    from . import references as refs

    refs.drop(session, ref_id, delete_file=bool(delete_file))
    return RedirectResponse(f"/channels/{channel_id}?tab=references", status_code=303)


@app.get("/channels/{channel_id}", response_class=HTMLResponse)
def channel_page(channel_id: int, request: Request, tab: str = "plan",
                 q: str = "", provider: str = "", orientation: str = "",
                 min_duration: float = -1.0, page: int = 1,
                 session: Session = Depends(get_session), _user: str = Depends(require_user)):
    channel = _channel_or_404(session, channel_id)
    plan = session.execute(
        select(PlanItem).where(PlanItem.channel_id == channel.id)
        .order_by(PlanItem.position)).scalars().all()
    videos = session.execute(
        select(Video).where(Video.channel_id == channel.id)
        .order_by(Video.created_at.desc())).scalars().all()
    rules = {r.weekday: r for r in session.execute(
        select(ScheduleRule).where(ScheduleRule.channel_id == channel.id)
    ).scalars().all()}
    voices = session.execute(select(Voice).order_by(Voice.provider, Voice.name)).scalars().all()
    models = session.execute(select(ModelPath).order_by(ModelPath.path)).scalars().all()
    clips = session.execute(
        select(Footage).where((Footage.channel_id == channel.id) | (Footage.channel_id.is_(None)))
        .order_by(Footage.id.desc())).scalars().all()
    lib_bytes, lib_sec = footage.library_size(clips)
    lib_stats = footage.stats(session, channel.id)
    lib_events = session.execute(
        select(Event).where(Event.stage == "библиотека")
        .order_by(Event.id.desc()).limit(8)).scalars().all()
    # показываем и незавершённые задачи, и упавшие: иначе причина сбоя не видна
    lib_jobs = session.execute(
        select(Job).where(Job.kind == "stock_import",
                          Job.status.in_(("pending", "running", "failed")))
        .order_by(Job.id.desc()).limit(5)).scalars().all()

    stock_ctx = _stock_context(session, channel, tab, q=q, provider=provider,
                               orientation=orientation, min_duration=min_duration, page=page)

    return templates.TemplateResponse("channel.html", base_context(
        request, session, channel=channel, plan=plan, videos=videos, rules=rules,
        voices=voices, models=models, tab=tab,
        plan_done=sum(1 for p in plan if p.status == "done"),
        plan_left=sum(1 for p in plan if p.status == "planned"),
        clips=clips, lib_bytes=lib_bytes, lib_sec=lib_sec, lib_stats=lib_stats,
        lib_events=lib_events, lib_jobs=lib_jobs,
        cleanup_modes=footage.CLEANUP_MODES,
        subtitle_styles=subtitles.SUBTITLE_STYLES, font_list=fonts.available(),
        short_formats=pipeline.SHORT_FORMATS, **stock_ctx,
        content_sources=prompts.CONTENT_SOURCES,
        video_sources=pipeline.VIDEO_SOURCES,
        comfy_workflows=session.execute(
            select(ComfyWorkflow).where(ComfyWorkflow.is_active.is_(True))
            .order_by(ComfyWorkflow.id.desc())).scalars().all(),
        archive_batches=session.execute(
            select(ArchiveBatch).where(ArchiveBatch.channel_id == channel.id)
            .order_by(ArchiveBatch.id.desc())).scalars().all(),
        archive_items=session.execute(
            select(ArchiveItem).where(ArchiveItem.channel_id == channel.id)
            .order_by(ArchiveItem.batch_id.desc(), ArchiveItem.idx)).scalars().all(),
        archive_cover_modes=archives.COVER_MODES,
        archive_presets=archives.PRESETS,
        archive_defaults={"cover_dim": archives.COVER_DIM,
                          "cover_hold": archives.COVER_HOLD,
                          "cover_zoom": archives.COVER_ZOOM,
                          "tail_sec": archives.TAIL_SECONDS},
        outro_sources=pipeline.OUTRO_SOURCES,
        reference_kinds=references.KINDS,
        reference_limit=references.MAX_INPUT_IMAGES,
        references_list=references.for_channel(session, channel.id),
        plan_max_items=PLAN_MAX_ITEMS,
        **estimate.channel_estimate_context(session, channel)))


def _stock_context(session: Session, channel: Channel, tab: str, *, q: str, provider: str,
                   orientation: str, min_duration: float, page: int) -> dict:
    """Готовим вкладку стоков: настройки формы и, если задан запрос, живую выдачу."""
    chosen = provider if provider in stock.PROVIDERS else st.get(session, "stock_provider", "pexels")
    if chosen not in stock.PROVIDERS:
        chosen = "pexels"
    if min_duration < 0:
        min_duration = st.get_float(session, "stock_min_duration", 6.0)
    ctx = {
        "disk_free": storage.free_bytes(),
        "disk_reserve": footage.min_free_bytes(session),
        "stock_providers": stock.PROVIDERS,
        "stock_configured": stock.configured(session),
        "stock_provider": chosen,
        "stock_query": q.strip(),
        "stock_orientation": orientation or stock.orientation_for_channel(channel.aspect_ratio),
        "stock_min_duration": min_duration,
        "stock_page": max(1, page),
        "stock_result": None,
        "stock_error": "",
    }
    if tab != "stock" or not q.strip():
        return ctx
    try:
        result = stock.search(session, chosen, q,
                              per_page=st.get_int(session, "stock_per_page", 24),
                              page=ctx["stock_page"],
                              orientation=ctx["stock_orientation"],
                              min_duration=min_duration)
        ctx["stock_result"] = result
    except stock.StockError as exc:
        ctx["stock_error"] = str(exc)
    except Exception as exc:  # noqa: BLE001 — поиск не должен ронять страницу канала
        log.exception("Поиск в стоке провалился")
        ctx["stock_error"] = f"Сбой поиска: {exc}"
    return ctx


@app.post("/channels/{channel_id}/settings")
def channel_settings(channel_id: int, request: Request, session: Session = Depends(get_session),
                     _user: str = Depends(require_user),
                     name: str = Form(...), topic: str = Form(""), description: str = Form(""),
                     audience: str = Form(""), content_source: str = Form("books"),
                     posts_per_day: float = Form(1.0),
                     video_source: str = Form("kie"), comfy_workflow_id: int = Form(0),
                     chat_model: str = Form(...), video_model: str = Form(...),
                     image_model: str = Form(...), tts_model: str = Form(...),
                     voice_id: str = Form(...), voice_name: str = Form(""),
                     voice_stability: float = Form(0.45), voice_similarity: float = Form(0.8),
                     voice_speed: float = Form(1.0), aspect_ratio: str = Form("16:9"),
                     resolution: str = Form("720p"), clip_duration: int = Form(5),
                     clip_coverage_sec: int = Form(20), max_clips_per_scene: int = Form(8),
                     visual_source: str = Form("generate"), library_share: int = Form(50),
                     background_music: str = Form(""), music_style: str = Form(""),
                     music_volume_db: float = Form(-24.0),
                     target_minutes: float = Form(8.0), scene_count: int = Form(8),
                     visual_style: str = Form(""), script_style: str = Form(""),
                     thumb_style: str = Form(""), burn_subtitles: str = Form(""),
                     subtitle_style: str = Form("shorts"), title_font: str = Form(""),
                     rotate_formats: str = Form(""),
                     make_shorts: str = Form(""), shorts_count: int = Form(3),
                     is_active: str = Form("")):
    channel = _channel_or_404(session, channel_id)
    channel.name = name.strip()
    channel.topic = topic
    channel.audience = audience.strip()[:300]
    channel.content_source = (content_source
                              if content_source in prompts.CONTENT_SOURCES else 'books')
    channel.video_source = video_source if video_source in pipeline.VIDEO_SOURCES else "kie"
    channel.comfy_workflow_id = comfy_workflow_id or None
    channel.posts_per_day = max(0.1, min(10.0, posts_per_day))
    channel.description = description
    channel.chat_model = chat_model.strip()
    channel.video_model = video_model.strip()
    channel.image_model = image_model.strip()
    channel.tts_model = tts_model.strip()
    channel.voice_id = voice_id.strip()
    channel.voice_name = voice_name.strip()
    channel.voice_stability = max(0.0, min(1.0, voice_stability))
    channel.voice_similarity = max(0.0, min(1.0, voice_similarity))
    channel.voice_speed = max(0.7, min(1.2, voice_speed))
    channel.aspect_ratio = aspect_ratio
    channel.resolution = resolution
    channel.clip_duration = max(4, min(15, clip_duration))
    channel.clip_coverage_sec = max(6, min(90, clip_coverage_sec))
    channel.max_clips_per_scene = max(1, min(16, max_clips_per_scene))
    channel.visual_source = visual_source if visual_source in ("generate", "library", "mix") else "generate"
    channel.library_share = max(0, min(100, library_share))
    channel.background_music = bool(background_music)
    channel.music_style = music_style
    channel.music_volume_db = max(-40.0, min(-6.0, music_volume_db))
    channel.target_minutes = max(1.0, min(30.0, target_minutes))
    channel.scene_count = max(3, min(30, scene_count))
    channel.visual_style = visual_style
    channel.script_style = script_style
    channel.thumb_style = thumb_style
    channel.burn_subtitles = bool(burn_subtitles)
    channel.subtitle_style = subtitles.normalize_style(subtitle_style)
    channel.title_font = fonts.normalize(title_font) or channel.title_font
    channel.rotate_formats = bool(rotate_formats)
    channel.make_shorts = bool(make_shorts)
    channel.shorts_count = max(0, min(10, shorts_count))
    channel.is_active = bool(is_active)
    session.commit()
    return RedirectResponse(f"/channels/{channel_id}?tab=settings", status_code=303)


@app.post("/channels/{channel_id}/schedule")
async def channel_schedule(channel_id: int, request: Request,
                           session: Session = Depends(get_session),
                           _user: str = Depends(require_user)):
    channel = _channel_or_404(session, channel_id)
    form = await request.form()
    existing = {r.weekday: r for r in session.execute(
        select(ScheduleRule).where(ScheduleRule.channel_id == channel.id)
    ).scalars().all()}
    for weekday in range(7):
        try:
            count = int(form.get(f"count_{weekday}", 0) or 0)
        except ValueError:
            count = 0
        run_at = str(form.get(f"run_at_{weekday}") or "04:00")[:5]
        enabled = bool(form.get(f"enabled_{weekday}"))
        rule = existing.get(weekday)
        if rule is None:
            rule = ScheduleRule(channel_id=channel.id, weekday=weekday)
            session.add(rule)
        rule.count = max(0, min(10, count))
        rule.run_at = run_at
        rule.enabled = enabled
    session.commit()

    if form.get("redistribute"):
        per_day = {wd: (existing.get(wd).count if existing.get(wd) else 0) for wd in range(7)}
        planner.assign_dates(session, channel.id, dt.date.today(), per_day)
    return RedirectResponse(f"/channels/{channel_id}?tab=schedule", status_code=303)


@app.post("/channels/{channel_id}/plan/add")
def plan_add(channel_id: int, session: Session = Depends(get_session),
             _user: str = Depends(require_user),
             book_title: str = Form(...), book_author: str = Form(""),
             angle: str = Form(""), video_title: str = Form(""),
             scheduled_date: str = Form("")):
    channel = _channel_or_404(session, channel_id)
    max_pos = session.execute(
        select(func.max(PlanItem.position)).where(PlanItem.channel_id == channel.id)).scalar() or 0
    date_value = None
    if scheduled_date:
        try:
            date_value = dt.date.fromisoformat(scheduled_date)
        except ValueError:
            date_value = None
    session.add(PlanItem(channel_id=channel.id, position=max_pos + 1,
                         book_title=book_title.strip(), book_author=book_author.strip(),
                         angle=angle, video_title=video_title.strip(),
                         scheduled_date=date_value, status="planned"))
    session.commit()
    return RedirectResponse(f"/channels/{channel_id}?tab=plan", status_code=303)


@app.post("/plan/{item_id}/delete")
def plan_delete(item_id: int, session: Session = Depends(get_session),
                _user: str = Depends(require_user)):
    item = session.get(PlanItem, item_id)
    if item is None:
        raise HTTPException(status_code=404, detail="Пункт плана не найден")
    channel_id = item.channel_id
    session.delete(item)
    session.commit()
    return RedirectResponse(f"/channels/{channel_id}?tab=plan", status_code=303)


@app.post("/plan/{item_id}/generate")
def plan_generate(item_id: int, session: Session = Depends(get_session),
                  _user: str = Depends(require_user)):
    item = session.get(PlanItem, item_id)
    if item is None:
        raise HTTPException(status_code=404, detail="Пункт плана не найден")
    video = planner.create_video_from_item(session, item)
    return RedirectResponse(f"/videos/{video.id}", status_code=303)


# Сколько дней в единице периода — из этого и частоты постинга считается,
# сколько пунктов плана заказывать у модели.
PERIOD_DAYS = {"days": 1, "weeks": 7, "months": 30}
# Потолок за один заход: длинный план модель начинает повторять и разбавлять.
PLAN_MAX_ITEMS = 60


@app.post("/channels/{channel_id}/plan/extend")
def plan_extend(channel_id: int, session: Session = Depends(get_session),
                _user: str = Depends(require_user), count: int = Form(0),
                period: int = Form(0), period_unit: str = Form("days"),
                posts_per_day: float = Form(0.0)):
    """Дописываем контент-план через текстовую модель.

    План заказывается либо прямо числом пунктов, либо периодом: «на N месяцев»
    при заданной частоте постинга превращается в число роликов.
    """
    channel = _channel_or_404(session, channel_id)
    if posts_per_day > 0:
        channel.posts_per_day = max(0.1, min(10.0, posts_per_day))
        session.commit()
    if period > 0:
        days = max(1, period) * PERIOD_DAYS.get(period_unit, 1)
        rate = channel.posts_per_day or 1.0
        count = int(round(days * rate))
    count = max(5, min(PLAN_MAX_ITEMS, count or 30))

    existing = [p.book_title for p in session.execute(
        select(PlanItem).where(PlanItem.channel_id == channel.id)).scalars().all()]
    client = KieClient(api_key=st.get(session, "kie_api_key") or None)
    audience = channel.audience.strip() or (
        "мужчины 25–45 лет" if "муж" in channel.name.lower() else "женщины 25–45 лет")
    from . import references as refs

    messages = prompts.content_plan(
        channel.name, channel.topic, count, audience, existing,
        source=channel.content_source, description=channel.description,
        style_hint=refs.style_hint(session, channel.id))
    try:
        items, _credits = client.chat_json(channel.chat_model, messages, temperature=0.8)
    except Exception as exc:  # noqa: BLE001
        raise HTTPException(status_code=502, detail=f"Модель не ответила: {exc}")

    max_pos = session.execute(
        select(func.max(PlanItem.position)).where(PlanItem.channel_id == channel.id)).scalar() or 0
    known = {t.strip().lower() for t in existing}
    added = 0
    last_date = session.execute(
        select(func.max(PlanItem.scheduled_date)).where(
            PlanItem.channel_id == channel.id)).scalar()
    cursor = (last_date or dt.date.today())
    for entry in items:
        # Модель нередко отдаёт больше, чем просили. Лимит применяем и к ответу,
        # иначе план распухает и расписание уезжает на месяцы вперёд.
        if added >= count:
            break
        title = str(entry.get("book_title") or "").strip()
        if not title or title.lower() in known:
            continue
        known.add(title.lower())
        added += 1
        max_pos += 1
        cursor = cursor + dt.timedelta(days=1)
        session.add(PlanItem(
            channel_id=channel.id, position=max_pos, book_title=title,
            book_author=str(entry.get("book_author") or "").strip(),
            video_title=str(entry.get("video_title") or "").strip()[:300],
            angle=str(entry.get("angle") or ""),
            key_points="; ".join(entry.get("key_points") or []),
            scheduled_date=cursor, status="planned"))
    session.commit()
    return RedirectResponse(f"/channels/{channel_id}?tab=plan", status_code=303)


@app.post("/channels/{channel_id}/run-now")
def channel_run_now(channel_id: int, session: Session = Depends(get_session),
                    _user: str = Depends(require_user), count: int = Form(1)):
    channel = _channel_or_404(session, channel_id)
    items = planner.next_items(session, channel.id, max(1, min(5, count)))
    if not items:
        raise HTTPException(status_code=400, detail="В контент-плане не осталось запланированных тем")
    created = [planner.create_video_from_item(session, item).id for item in items]
    if len(created) == 1:
        return RedirectResponse(f"/videos/{created[0]}", status_code=303)
    return RedirectResponse(f"/channels/{channel_id}?tab=videos", status_code=303)


# --------------------------------------------------------------------------- ролики

@app.post("/channels/{channel_id}/footage/upload")
async def footage_upload(channel_id: int, request: Request,
                         session: Session = Depends(get_session),
                         _user: str = Depends(require_user)):
    """Загрузка своих видео в библиотеку канала (файлами или по прямым ссылкам)."""
    channel = _channel_or_404(session, channel_id)
    form = await request.form()
    shared = bool(form.get("shared"))
    tags = str(form.get("tags") or "")
    title = str(form.get("title") or "")
    target_channel = None if shared else channel.id
    slug = None if shared else channel.slug

    added, problems = 0, []
    for upload in form.getlist("files"):
        if not isinstance(upload, UploadFile) or not upload.filename:
            continue
        try:
            footage.add_from_upload(session, upload.file, upload.filename,
                                    channel_id=target_channel, channel_slug=slug,
                                    title=title, tags=tags)
            added += 1
        except Exception as exc:  # noqa: BLE001
            problems.append(f"{upload.filename}: {exc}")

    for raw in str(form.get("urls") or "").splitlines():
        url = raw.strip()
        if not url:
            continue
        try:
            footage.add_from_url(session, url, channel_id=target_channel, channel_slug=slug,
                                 title=title, tags=tags)
            added += 1
        except Exception as exc:  # noqa: BLE001
            problems.append(f"{url}: {exc}")

    message = f"Добавлено футажей: {added}"
    if problems:
        message += ". Не удалось: " + "; ".join(problems[:5])
    session.add(Event(level="warn" if problems else "info", stage="библиотека", message=message[:4000]))
    session.commit()
    return RedirectResponse(f"/channels/{channel_id}?tab=footage", status_code=303)


@app.post("/channels/{channel_id}/footage/cleanup")
def footage_cleanup(channel_id: int, session: Session = Depends(get_session),
                    _user: str = Depends(require_user), mode: str = Form("unused")):
    """Освобождение места в библиотеке видео."""
    channel = _channel_or_404(session, channel_id)
    try:
        removed, freed = footage.cleanup(session, mode, channel_id=channel.id)
    except footage.FootageError as exc:
        session.add(Event(level="warn", stage="библиотека", message=str(exc)[:4000]))
        session.commit()
        return RedirectResponse(f"/channels/{channel_id}?tab=footage", status_code=303)

    session.add(Event(
        level="info", stage="библиотека",
        message=f"Очистка библиотеки ({footage.CLEANUP_MODES[mode]}): удалено {removed}, "
                f"освобождено {storage.human_size(freed)}, "
                f"свободно на диске {storage.human_size(storage.free_bytes())}"))
    session.commit()
    return RedirectResponse(f"/channels/{channel_id}?tab=footage", status_code=303)


@app.post("/channels/{channel_id}/stock/import")
async def stock_import(channel_id: int, request: Request,
                       session: Session = Depends(get_session),
                       _user: str = Depends(require_user)):
    """Бета: ставим в очередь скачивание отмеченных роликов из стока."""
    channel = _channel_or_404(session, channel_id)
    form = await request.form()
    shared = bool(form.get("shared"))
    query = str(form.get("query") or "")
    extra_tags = str(form.get("tags") or "")

    items = []
    for raw in form.getlist("item"):
        try:
            items.append(json.loads(str(raw)))
        except json.JSONDecodeError:
            continue
    if not items:
        session.add(Event(level="warn", stage="библиотека",
                          message="Импорт из стока: ни один ролик не отмечен"))
        session.commit()
        return RedirectResponse(f"/channels/{channel_id}?tab=stock", status_code=303)

    queue.enqueue(session, "stock_import", payload={
        "channel_id": None if shared else channel.id,
        "channel_slug": None if shared else channel.slug,
        "query": query,
        "extra_tags": extra_tags,
        "items": items,
    })
    session.add(Event(level="info", stage="библиотека",
                      message=f"Импорт из стока: в очередь поставлено {len(items)} роликов "
                              f"по запросу «{query}»"))
    session.commit()
    return RedirectResponse(f"/channels/{channel_id}?tab=footage", status_code=303)


@app.post("/footage/{footage_id}/delete")
def footage_delete(footage_id: int, session: Session = Depends(get_session),
                   _user: str = Depends(require_user)):
    item = session.get(Footage, footage_id)
    if item is None:
        raise HTTPException(status_code=404, detail="Футаж не найден")
    channel_id = item.channel_id
    footage.delete(session, item)
    target = f"/channels/{channel_id}?tab=footage" if channel_id else "/settings"
    return RedirectResponse(target, status_code=303)


@app.post("/footage/{footage_id}/update")
def footage_update(footage_id: int, session: Session = Depends(get_session),
                   _user: str = Depends(require_user),
                   title: str = Form(""), tags: str = Form(""), is_active: str = Form("")):
    item = session.get(Footage, footage_id)
    if item is None:
        raise HTTPException(status_code=404, detail="Футаж не найден")
    item.title = title.strip()[:300] or item.title
    item.tags = tags.strip()
    item.is_active = bool(is_active)
    session.commit()
    target = f"/channels/{item.channel_id}?tab=footage" if item.channel_id else "/settings"
    return RedirectResponse(target, status_code=303)


@app.get("/font-preview/{key}.png")
def font_preview(key: str, _user: str = Depends(require_user)):
    """Картинка-образец шрифта для выбора в интерфейсе."""
    path = fonts.preview_png(key)
    if path is None or not path.exists():
        raise HTTPException(status_code=404, detail="Образец недоступен")
    return FileResponse(path, media_type="image/png")


@app.post("/scenes/{scene_id}/short")
def scene_make_short(scene_id: int, session: Session = Depends(get_session),
                     _user: str = Depends(require_user), short_title: str = Form(""),
                     short_format: str = Form(""), title_font: str = Form(""),
                     add_outro: str = Form(""), outro_url: str = Form(""),
                     outro_title: str = Form(""), outro_text: str = Form(""),
                     outro_about: str = Form(""), outro_source: str = Form("")):
    """Собрать публикуемый шортс из уже готового куска — без новой генерации кадров."""
    scene = session.get(Scene, scene_id)
    if scene is None:
        raise HTTPException(status_code=404, detail="Сцена не найдена")
    if not scene.piece_path:
        raise HTTPException(status_code=400, detail="Сцена ещё не собрана")
    queue.enqueue(session, "regen_scene", video_id=scene.video_id,
                  payload={"scene_id": scene.id,
                           "options": {"only_short": True,
                                       "short_title": short_title.strip()[:200],
                                       "short_format": pipeline.normalize_format(short_format),
                                       "title_font": fonts.normalize(title_font),
                                       "add_outro": bool(add_outro),
                                       "outro_url": outro_url.strip()[:300],
                                       "outro_title": outro_title.strip()[:120],
                                       "outro_text": outro_text.strip()[:1000],
                                       "outro_about": outro_about.strip()[:2000],
                                       "outro_source": outro_source.strip()}})
    session.add(Event(video_id=scene.video_id, level="info", stage="regen",
                      message=f"Сцена {scene.idx + 1}: сборка шортса поставлена в очередь"))
    session.commit()
    return RedirectResponse(f"/videos/{scene.video_id}#scene-{scene.id}", status_code=303)


@app.get("/videos/{video_id}", response_class=HTMLResponse)
def video_page(video_id: int, request: Request, session: Session = Depends(get_session),
               _user: str = Depends(require_user)):
    video = _video_or_404(session, video_id)
    channel = session.get(Channel, video.channel_id)
    events = session.execute(
        select(Event).where(Event.video_id == video.id)
        .order_by(Event.id.desc()).limit(120)).scalars().all()
    variants = []
    if video.title_variants:
        try:
            variants = json.loads(video.title_variants)
        except ValueError:
            variants = []
    clean_path = ""
    if video.video_path:
        candidate = storage.abspath(video.video_path).parent / "video_clean.mp4"
        if candidate.exists():
            clean_path = storage.rel(candidate)
    models = session.execute(select(ModelPath).order_by(ModelPath.path)).scalars().all()
    voices = session.execute(select(Voice).order_by(Voice.provider, Voice.name)).scalars().all()
    regen_jobs = session.execute(
        select(Job).where(Job.kind == "regen_scene",
                          Job.status.in_(("pending", "running", "failed", "skipped")))
        .order_by(Job.id.desc()).limit(10)).scalars().all()
    busy_scenes = {json.loads(j.payload or "{}").get("scene_id")
                   for j in regen_jobs if j.status in ("pending", "running")}
    return templates.TemplateResponse("video.html", base_context(
        request, session, video=video, channel=channel, events=events, variants=variants,
        clean_path=clean_path, models=models, voices=voices,
        regen_jobs=regen_jobs, busy_scenes=busy_scenes,
        short_formats=pipeline.SHORT_FORMATS, font_list=fonts.available(),
        video_sources=pipeline.VIDEO_SOURCES,
        # Для агента адрес не нужен — нужен токен; для прямого режима наоборот.
        comfy_ready=bool(
            (_agent_token(session) if pipeline.comfy_mode(session) == "agent"
             else st.get(session, "comfy_url", "").strip())
            and session.execute(select(ComfyWorkflow).where(
                ComfyWorkflow.is_active.is_(True))).scalars().first()),
        outro_sources=pipeline.OUTRO_SOURCES,
        outro_builtin=pipeline.OUTRO_FALLBACK))


@app.post("/scenes/{scene_id}/regenerate")
def scene_regenerate(scene_id: int, session: Session = Depends(get_session),
                     _user: str = Depends(require_user),
                     visual_prompt: str = Form(""), narration: str = Form(""),
                     video_model: str = Form(""), tts_model: str = Form(""),
                     voice_id: str = Form(""), clips: int = Form(0),
                     redo_voice: str = Form(""), make_short: str = Form(""),
                     short_title: str = Form(""), short_format: str = Form("full"),
                     title_font: str = Form(""), video_source: str = Form(""),
                     add_outro: str = Form(""),
                     outro_url: str = Form(""), outro_title: str = Form(""),
                     outro_text: str = Form(""), outro_about: str = Form(""),
                     outro_source: str = Form("")):
    """Пересборка одной сцены: свой промпт, своя модель, полный кусок на выходе."""
    scene = session.get(Scene, scene_id)
    if scene is None:
        raise HTTPException(status_code=404, detail="Сцена не найдена")
    video = session.get(Video, scene.video_id)
    if video is None:
        raise HTTPException(status_code=404, detail="Ролик не найден")

    options = {
        "visual_prompt": visual_prompt.strip(),
        "narration": narration.strip(),
        "video_model": video_model.strip(),
        "tts_model": tts_model.strip(),
        "voice_id": voice_id.strip(),
        "clips": max(0, min(16, clips)),
        "redo_voice": bool(redo_voice),
        "make_short": bool(make_short),
        "short_title": short_title.strip()[:200],
        "short_format": pipeline.normalize_format(short_format),
        "title_font": fonts.normalize(title_font),
        "video_source": video_source.strip(),
        "add_outro": bool(add_outro),
        "outro_url": outro_url.strip()[:300],
        "outro_title": outro_title.strip()[:120],
        "outro_text": outro_text.strip()[:1000],
        "outro_about": outro_about.strip()[:2000],
        "outro_source": outro_source.strip(),
    }
    queue.enqueue(session, "regen_scene", video_id=video.id,
                  payload={"scene_id": scene.id, "options": options})
    session.add(Event(video_id=video.id, level="info", stage="regen",
                      message=f"Сцена {scene.idx + 1}: пересборка поставлена в очередь"))
    session.commit()
    return RedirectResponse(f"/videos/{video.id}#scene-{scene.id}", status_code=303)


@app.get("/library", response_class=HTMLResponse)
def library(request: Request, channel_id: int = 0, session: Session = Depends(get_session),
            _user: str = Depends(require_user)):
    query = select(Video).where(Video.status == "done").order_by(Video.finished_at.desc())
    if channel_id:
        query = query.where(Video.channel_id == channel_id)
    videos = session.execute(query).scalars().all()
    return templates.TemplateResponse("library.html", base_context(
        request, session, videos=videos, selected_channel=channel_id))


@app.get("/loops", response_class=HTMLResponse)
def loops_page(request: Request, session: Session = Depends(get_session),
               _user: str = Depends(require_user)):
    from . import loops as loops_mod

    rows = {row.fmt: row for row in loops_mod.library(session) if row.is_active}
    channels = session.execute(select(Channel).order_by(Channel.id)).scalars().all()
    # Лупу нужна модель, оживляющая картинку: у канала для сцен стоит генератор
    # из текста, и его набор полей для этой задачи не подходит.
    video_models = [m.path for m in session.execute(
        select(ModelPath).where(ModelPath.kind == "video_input")
        .order_by(ModelPath.path)).scalars()
        if loops_mod.is_image_model(m.path)]
    if loops_mod.DEFAULT_VIDEO_MODEL not in video_models:
        video_models.insert(0, loops_mod.DEFAULT_VIDEO_MODEL)
    return templates.TemplateResponse("loops.html", base_context(
        request, session, loop_rows=rows, still_formats=pipeline.STILL_FORMATS,
        loop_channels=channels, loop_seconds=loops_mod.LOOP_SECONDS,
        loop_quality=loops_mod.LOOP_QUALITY, loop_models=video_models,
        loop_default_model=loops_mod.DEFAULT_VIDEO_MODEL,
        loop_motion=loops_mod.LOOP_MOTION))


@app.post("/loops/build")
def loops_build(session: Session = Depends(get_session), _user: str = Depends(require_user),
                channel_id: int = Form(...), formats: list[str] = Form(default=[]),
                seconds: int = Form(8), quality: str = Form("720p"),
                video_model: str = Form(""), skip_existing: str = Form("")):
    from . import loops as loops_mod

    wanted = [f for f in formats if f in pipeline.STILL_FORMATS]
    if not wanted:
        return RedirectResponse("/loops?error=no-formats", status_code=303)
    queue.enqueue(session, "build_loops", payload={
        "channel_id": channel_id, "formats": wanted,
        "seconds": max(1, min(15, seconds)),
        "quality": quality if quality in ("360p", "540p", "720p", "1080p") else "720p",
        "video_model": loops_mod.to_image_model(video_model),
        "skip_existing": bool(skip_existing),
    })
    return RedirectResponse(f"/loops?queued={len(wanted)}", status_code=303)


@app.post("/loops/{loop_id}/drop")
def loops_drop(loop_id: int, session: Session = Depends(get_session),
               _user: str = Depends(require_user)):
    from .models import LoopClip

    row = session.get(LoopClip, loop_id)
    if row is not None:
        # Файл оставляем на диске: запись убираем из выдачи, а чистка хранилища —
        # отдельная операция со своим подтверждением.
        row.is_active = False
        session.commit()
    return RedirectResponse("/loops", status_code=303)


@app.get("/music", response_class=HTMLResponse)
def music_page(request: Request, tab: str = "new", session: Session = Depends(get_session),
               _user: str = Depends(require_user)):
    from . import musicvideo as mv
    from . import youtube as yt

    tab = tab if tab in ("new", "archive", "library") else "new"
    rows = mv.library(session)
    return templates.TemplateResponse("music.html", base_context(
        request, session, tab=tab, mixes=rows,
        mv_styles=[mv.STYLES[k] for k in mv.STYLE_ORDER],
        mv_minutes=mv.MINUTES_CHOICES, mv_default_minutes=mv.DEFAULT_MINUTES,
        mv_models=mv.SUNO_MODELS, mv_default_model=mv.DEFAULT_SUNO_MODEL,
        mv_stages=mv.STAGES, mv_backdrops={row.fmt[len(mv.FMT_PREFIX):]: row
                                           for row in mv.backdrops(session)},
        mv_timecode=mv.timecode, mv_min_track=int(mv.MIN_TRACK_SEC),
        yt_ready=yt.configured(session), yt_privacy=yt.PRIVACY,
        yt_privacy_default=st.get(session, "youtube_privacy", "private")))


@app.post("/music/create")
def music_create(session: Session = Depends(get_session), _user: str = Depends(require_user),
                 style: str = Form(...), minutes: int = Form(30),
                 suno_model: str = Form(""), title: str = Form(""),
                 language: str = Form("en"), new_backdrop: str = Form(""),
                 brief: str = Form("")):
    from . import musicvideo as mv

    if style not in mv.STYLES:
        return RedirectResponse("/music?error=style", status_code=303)
    video = mv.create(session, style=style, minutes=minutes, suno_model=suno_model,
                      title=title, language=language, brief=brief)
    queue.enqueue(session, "music_video", payload={
        "video_id": video.id, "language": "ru" if language == "ru" else "en",
        # Заставка жанра переиспользуется: перегенерация — отдельная галочка,
        # иначе каждый микс платил бы за одну и ту же картинку заново.
        "reuse_backdrop": not bool(new_backdrop),
    })
    return RedirectResponse(f"/music?tab=library&queued={video.id}", status_code=303)


@app.post("/music/import")
async def music_import(request: Request, session: Session = Depends(get_session),
                       _user: str = Depends(require_user)):
    """Микс из архива с готовыми материалами: чего не хватает — догенерируется."""
    from . import musicvideo as mv

    form = await request.form()
    upload = form.get("file")
    if not isinstance(upload, UploadFile) or not upload.filename:
        return RedirectResponse("/music?error=no-file", status_code=303)

    # Пишем на диск по кускам: в таком архиве музыка, и держать её целиком в
    # памяти не нужно — на большом файле это съело бы оперативку сервера.
    config.TMP_DIR.mkdir(parents=True, exist_ok=True)
    tmp = config.TMP_DIR / f"mv_upload_{utcnow().strftime('%Y%m%d_%H%M%S_%f')}.zip"
    size = 0
    try:
        with open(tmp, "wb") as fh:
            while True:
                chunk = await upload.read(1 << 20)
                if not chunk:
                    break
                size += len(chunk)
                fh.write(chunk)
        if not size:
            return RedirectResponse("/music?error=no-file", status_code=303)
        video = mv.import_archive(
            session, tmp, name=upload.filename,
            style=str(form.get("style") or ""),
            minutes=int(float(form.get("minutes") or 0)),
            suno_model=str(form.get("suno_model") or mv.DEFAULT_SUNO_MODEL),
            language=str(form.get("language") or "en"),
            brief=str(form.get("brief") or ""))
    except mv.ImportError_ as exc:
        return RedirectResponse(f"/music?error=bad-zip&detail={str(exc)[:160]}",
                                status_code=303)
    finally:
        tmp.unlink(missing_ok=True)

    tracks = len(video.tracks)
    queue.enqueue(session, "music_video", payload={
        "video_id": video.id, "language": video.language or "en",
        "reuse_backdrop": True,
    })
    return RedirectResponse(
        f"/music?tab=library&queued={video.id}&imported={tracks}", status_code=303)


@app.post("/music/{video_id}/run")
def music_run(video_id: int, session: Session = Depends(get_session),
              _user: str = Depends(require_user), new_backdrop: str = Form("")):
    """Дособрать или пересобрать микс: уже скачанные треки не оплачиваются заново."""
    from .models import MusicVideo

    video = session.get(MusicVideo, video_id)
    if video is None:
        raise HTTPException(status_code=404, detail="Микс не найден")
    video.status = "queued"
    video.stage = "queued"
    video.error = ""
    session.commit()
    # Язык описания у микса свой — пересборка его не меняет.
    queue.enqueue(session, "music_video", payload={
        "video_id": video_id, "language": video.language or "en",
        "reuse_backdrop": not bool(new_backdrop),
    })
    return RedirectResponse(f"/music?tab=library&queued={video_id}", status_code=303)


@app.post("/music/{video_id}/meta")
def music_meta(video_id: int, session: Session = Depends(get_session),
               _user: str = Depends(require_user), yt_title: str = Form(""),
               description: str = Form(""), tags: str = Form("")):
    from .models import MusicVideo

    video = session.get(MusicVideo, video_id)
    if video is None:
        raise HTTPException(status_code=404, detail="Микс не найден")
    video.yt_title = yt_title.strip()[:300]
    video.description = description
    video.tags = tags.strip()
    session.commit()
    return RedirectResponse(f"/music?tab=library&saved={video_id}", status_code=303)


@app.post("/music/{video_id}/drop")
def music_drop(video_id: int, session: Session = Depends(get_session),
               _user: str = Depends(require_user), with_files: str = Form("")):
    from . import musicvideo as mv

    mv.drop(session, video_id, with_files=bool(with_files))
    return RedirectResponse("/music?tab=library", status_code=303)


@app.get("/music/{video_id}/description.txt")
def music_description(video_id: int, session: Session = Depends(get_session),
                      _user: str = Depends(require_user)):
    """Описание с тайм-кодом отдельным файлом — его удобно вставлять на YouTube."""
    from .models import MusicVideo

    video = session.get(MusicVideo, video_id)
    if video is None:
        raise HTTPException(status_code=404, detail="Микс не найден")
    body = f"{video.yt_title}\n\n{video.description}\n"
    return Response(content=body, media_type="text/plain; charset=utf-8", headers={
        "Content-Disposition": f'attachment; filename="mix{video_id}_description.txt"'})


def _mchannel_or_404(session: Session, slug: str):
    from .models import MusicChannel

    row = session.execute(
        select(MusicChannel).where(MusicChannel.slug == slug)).scalars().first()
    if row is None:
        raise HTTPException(status_code=404, detail="Канал не найден")
    return row


@app.get("/music/c/{slug}", response_class=HTMLResponse)
def mchannel_page(slug: str, request: Request, tab: str = "new",
                  session: Session = Depends(get_session),
                  _user: str = Depends(require_user)):
    from . import musicchannels as mch
    from . import musicvideo as mv
    from . import youtube as yt
    from .models import MusicVideo

    channel = _mchannel_or_404(session, slug)
    tab = tab if tab in ("new", "library", "look") else "new"
    mixes = list(session.execute(
        select(MusicVideo).where(MusicVideo.channel_id == channel.id)
        .order_by(MusicVideo.id.desc())).scalars())
    return templates.TemplateResponse("mchannel.html", base_context(
        request, session, tab=tab, channel=channel, mixes=mixes,
        style=mv.style_of(channel.style),
        intro=mch.one(session, channel.id, "intro"),
        outro=mch.one(session, channel.id, "outro"),
        loops=mch.assets(session, channel.id, "loop"),
        kinds=mch.KINDS,
        mv_minutes=mv.MINUTES_CHOICES, mv_stages=mv.STAGES, mv_timecode=mv.timecode,
        yt_ready=yt.configured(session), yt_privacy=yt.PRIVACY,
        yt_privacy_default=st.get(session, "youtube_privacy", "private")))


@app.post("/music/c/{slug}/create")
def mchannel_create(slug: str, session: Session = Depends(get_session),
                    _user: str = Depends(require_user), minutes: int = Form(0),
                    title: str = Form("")):
    """Трек в канале: из выбора только длительность, остальное у канала своё."""
    from . import musicchannels as mch
    from . import musicvideo as mv

    channel = _mchannel_or_404(session, slug)
    if not mch.assets(session, channel.id, "loop"):
        return RedirectResponse(f"/music/c/{slug}?tab=look&error=no-loops",
                                status_code=303)
    video = mv.create_for_channel(session, channel, minutes=minutes, title=title)
    queue.enqueue(session, "music_video", payload={
        "video_id": video.id, "language": channel.language or "en",
        "reuse_backdrop": True})
    return RedirectResponse(f"/music/c/{slug}?tab=library&queued={video.id}",
                            status_code=303)


@app.post("/music/c/{slug}/assets")
async def mchannel_asset_add(slug: str, request: Request,
                             session: Session = Depends(get_session),
                             _user: str = Depends(require_user)):
    """Загрузка клипа оформления: интро, заставка или оутро."""
    from . import musicchannels as mch

    channel = _mchannel_or_404(session, slug)
    form = await request.form()
    upload = form.get("file")
    kind = str(form.get("kind") or "loop")
    if not isinstance(upload, UploadFile) or not upload.filename:
        return RedirectResponse(f"/music/c/{slug}?tab=look&error=no-file",
                                status_code=303)
    config.TMP_DIR.mkdir(parents=True, exist_ok=True)
    suffix = Path(upload.filename).suffix.lower() or ".mp4"
    tmp = config.TMP_DIR / f"asset_{utcnow().strftime('%Y%m%d_%H%M%S_%f')}{suffix}"
    try:
        # Пишем по кускам: клипы бывают на сотни мегабайт, и в памяти им делать
        # нечего.
        with open(tmp, "wb") as fh:
            while True:
                chunk = await upload.read(1 << 20)
                if not chunk:
                    break
                fh.write(chunk)
        mch.add_file(session, channel, kind, tmp,
                     title=Path(upload.filename).stem, source="uploaded")
    except Exception as exc:  # noqa: BLE001 — показать причину, а не пятисотку
        return RedirectResponse(
            f"/music/c/{slug}?tab=look&error=bad-clip&detail={str(exc)[:160]}",
            status_code=303)
    finally:
        tmp.unlink(missing_ok=True)
    return RedirectResponse(f"/music/c/{slug}?tab=look&added=1", status_code=303)


@app.post("/music/c/{slug}/loops")
def mchannel_loops(slug: str, session: Session = Depends(get_session),
                   _user: str = Depends(require_user), count: int = Form(4)):
    """Догенерировать набор заставок канала. Платно и делается один раз."""
    channel = _mchannel_or_404(session, slug)
    queue.enqueue(session, "music_loops", payload={
        "channel_id": channel.id, "count": max(1, min(10, count))})
    return RedirectResponse(f"/music/c/{slug}?tab=look&generating={count}",
                            status_code=303)


@app.post("/music/c/{slug}/settings")
def mchannel_settings(slug: str, session: Session = Depends(get_session),
                      _user: str = Depends(require_user), name: str = Form(""),
                      minutes: int = Form(0), titles: str = Form(""),
                      subtitle: str = Form(""), brief: str = Form(""),
                      language: str = Form("en"), equalizer: str = Form(""),
                      now_playing: str = Form("")):
    channel = _mchannel_or_404(session, slug)
    if name.strip():
        channel.name = name.strip()[:200]
    if minutes:
        channel.minutes = max(5, min(180, minutes))
    channel.titles = titles.strip()[:4000]
    channel.subtitle = subtitle.strip()[:200]
    channel.brief = brief.strip()[:20000]
    channel.language = "ru" if language == "ru" else "en"
    channel.equalizer = bool(equalizer)
    channel.now_playing = bool(now_playing)
    session.commit()
    return RedirectResponse(f"/music/c/{slug}?tab=look&saved=1", status_code=303)


@app.post("/music/assets/{asset_id}/drop")
def mchannel_asset_drop(asset_id: int, session: Session = Depends(get_session),
                        _user: str = Depends(require_user), slug: str = Form("")):
    from . import musicchannels as mch

    mch.drop_asset(session, asset_id)
    return RedirectResponse(f"/music/c/{slug}?tab=look", status_code=303)


@app.get("/youtube", response_class=HTMLResponse)
def youtube_page(request: Request, session: Session = Depends(get_session),
                 _user: str = Depends(require_user)):
    from . import youtube as yt

    client_id = st.get(session, "youtube_client_id", "").strip()
    return templates.TemplateResponse("youtube.html", base_context(
        request, session, yt_client_id=client_id,
        yt_has_secret=bool(st.get(session, "youtube_client_secret", "").strip()),
        yt_connected=yt.configured(session),
        yt_redirect=yt.REDIRECT_URI, yt_scope=yt.SCOPE,
        yt_consent=yt.consent_url(client_id) if client_id else "",
        yt_privacy=yt.PRIVACY,
        yt_privacy_default=st.get(session, "youtube_privacy", "private")))


@app.post("/youtube/app")
def youtube_app(session: Session = Depends(get_session), _user: str = Depends(require_user),
                client_id: str = Form(""), client_secret: str = Form("")):
    """Ключи приложения Google. Секрет перезаписываем только если прислали новый."""
    if client_id.strip():
        st.set_value(session, "youtube_client_id", client_id.strip())
    if client_secret.strip():
        st.set_value(session, "youtube_client_secret", client_secret.strip())
    session.commit()
    return RedirectResponse("/youtube?saved=1", status_code=303)


@app.post("/youtube/code")
def youtube_code(session: Session = Depends(get_session), _user: str = Depends(require_user),
                 code: str = Form("")):
    """Код со страницы разрешения доступа → токен обновления."""
    from . import youtube as yt

    client_id = st.get(session, "youtube_client_id", "").strip()
    client_secret = st.get(session, "youtube_client_secret", "").strip()
    if not (client_id and client_secret):
        return RedirectResponse("/youtube?error=no-app", status_code=303)
    cleaned = code.strip()
    # Люди вставляют весь адрес из строки браузера — достаём код сами, это
    # честнее, чем требовать аккуратности от человека.
    if "code=" in cleaned:
        from urllib.parse import parse_qs, urlparse

        found = parse_qs(urlparse(cleaned).query).get("code")
        cleaned = found[0] if found else cleaned
    if not cleaned:
        return RedirectResponse("/youtube?error=no-code", status_code=303)
    try:
        token = yt.exchange_code(client_id, client_secret, cleaned)
    except yt.YouTubeError as exc:
        return RedirectResponse(f"/youtube?error=exchange&detail={str(exc)[:200]}",
                                status_code=303)
    st.set_value(session, "youtube_refresh_token", token)
    session.commit()
    return RedirectResponse("/youtube?connected=1", status_code=303)


@app.post("/youtube/forget")
def youtube_forget(session: Session = Depends(get_session), _user: str = Depends(require_user)):
    """Забыть токен обновления: доступ перестаёт работать до нового разрешения."""
    st.set_value(session, "youtube_refresh_token", "")
    session.commit()
    return RedirectResponse("/youtube?forgotten=1", status_code=303)


@app.post("/music/{video_id}/publish")
def music_publish(video_id: int, session: Session = Depends(get_session),
                  _user: str = Depends(require_user), privacy: str = Form("private")):
    """Поставить микс в очередь на публикацию."""
    from . import youtube as yt
    from .models import MusicVideo

    video = session.get(MusicVideo, video_id)
    if video is None:
        raise HTTPException(status_code=404, detail="Микс не найден")
    if not video.video_path:
        return RedirectResponse("/music?tab=library&error=not-built", status_code=303)
    if not yt.configured(session):
        return RedirectResponse("/music?tab=library&error=no-youtube", status_code=303)
    privacy = privacy if privacy in yt.PRIVACY else "private"
    st.set_value(session, "youtube_privacy", privacy)
    video.youtube_state = "queued"
    video.youtube_error = ""
    session.commit()
    queue.enqueue(session, "youtube_upload",
                  payload={"video_id": video_id, "privacy": privacy})
    return RedirectResponse(f"/music?tab=library&publishing={video_id}", status_code=303)


@app.post("/videos/{video_id}/action")
def video_action(video_id: int, session: Session = Depends(get_session),
                 _user: str = Depends(require_user), action: str = Form(...)):
    video = _video_or_404(session, video_id)
    if action == "cancel":
        video.status = "cancelled"
        video.stage = "cancelled"
        session.commit()
    elif action == "retry":
        video.status = "queued"
        video.stage = "queued"
        video.error = ""
        video.progress = 0
        session.commit()
        queue.enqueue(session, "build_video", video_id=video.id)
    elif action == "rebuild_full":
        for scene in list(video.scenes):
            session.delete(scene)
        video.script = ""
        video.status = "queued"
        video.stage = "queued"
        video.error = ""
        video.progress = 0
        session.commit()
        queue.enqueue(session, "build_video", video_id=video.id)
    elif action == "shorts":
        queue.enqueue(session, "make_shorts", video_id=video.id)
    elif action == "assemble_all":
        ready = [sc.id for sc in sorted(video.scenes, key=lambda x: x.idx) if sc.piece_path]
        if not ready:
            raise HTTPException(status_code=400, detail="Готовых сцен пока нет")
        video.status = "assemble"
        video.stage = "assemble"
        video.error = ""
        session.commit()
        queue.enqueue(session, "assemble_final", video_id=video.id,
                      payload={"scene_ids": ready, "with_bridges": False})
    elif action == "delete":
        channel = session.get(Channel, video.channel_id)
        folder = config.MEDIA_DIR / channel.slug / f"{video.id:06d}"
        shutil.rmtree(folder, ignore_errors=True)
        session.delete(video)
        session.commit()
        return RedirectResponse(f"/channels/{channel.id}?tab=videos", status_code=303)
    return RedirectResponse(f"/videos/{video_id}", status_code=303)


@app.post("/videos/{video_id}/assemble")
async def video_assemble(video_id: int, request: Request,
                         session: Session = Depends(get_session),
                         _user: str = Depends(require_user)):
    """Сборка длинного ролика из отмеченных сцен."""
    video = _video_or_404(session, video_id)
    form = await request.form()
    raw_ids = form.getlist("scene_ids")
    scene_ids: list[int] = []
    for value in raw_ids:
        try:
            scene_ids.append(int(value))
        except (TypeError, ValueError):
            continue
    ready = {sc.id for sc in video.scenes if sc.piece_path}
    scene_ids = [i for i in scene_ids if i in ready]
    if not scene_ids:
        raise HTTPException(status_code=400, detail="Не отмечена ни одна готовая сцена")

    with_bridges = bool(form.get("with_bridges"))
    video.status = "assemble"
    video.stage = "assemble"
    video.error = ""
    session.commit()
    queue.enqueue(session, "assemble_final", video_id=video.id,
                  payload={"scene_ids": scene_ids, "with_bridges": with_bridges})
    return RedirectResponse(f"/videos/{video_id}", status_code=303)


@app.post("/videos/{video_id}/meta")
def video_meta(video_id: int, session: Session = Depends(get_session),
               _user: str = Depends(require_user),
               title: str = Form(""), description: str = Form(""), tags: str = Form("")):
    video = _video_or_404(session, video_id)
    video.title = title.strip()[:300]
    video.description = description
    video.tags = tags
    session.commit()
    return RedirectResponse(f"/videos/{video_id}", status_code=303)


# --------------------------------------------------------------------------- цены, настройки, очередь

@app.get("/prices", response_class=HTMLResponse)
def prices_page(request: Request, q: str = "", kind: str = "",
                session: Session = Depends(get_session), _user: str = Depends(require_user)):
    query = select(PriceItem).order_by(PriceItem.interface_type, PriceItem.model_description)
    if q:
        query = query.where(PriceItem.model_description.ilike(f"%{q}%"))
    if kind:
        query = query.where(PriceItem.interface_type == kind)
    items = session.execute(query).scalars().all()
    kinds = [row[0] for row in session.execute(
        select(PriceItem.interface_type).distinct().order_by(PriceItem.interface_type)).all()]
    return templates.TemplateResponse("prices.html", base_context(
        request, session, items=items, kinds=kinds, q=q, kind=kind,
        usd_per_credit=st.get_float(session, "usd_per_credit", 0.005)))


@app.post("/prices/refresh")
def prices_refresh(session: Session = Depends(get_session), _user: str = Depends(require_user)):
    queue.enqueue(session, "sync_prices")
    queue.enqueue(session, "sync_models")
    return RedirectResponse("/prices", status_code=303)


@app.get("/settings", response_class=HTMLResponse)
def settings_page(request: Request, session: Session = Depends(get_session),
                  _user: str = Depends(require_user)):
    voices = session.execute(select(Voice).order_by(Voice.provider, Voice.name)).scalars().all()
    models = session.execute(select(ModelPath).order_by(ModelPath.kind, ModelPath.path)).scalars().all()
    return templates.TemplateResponse("settings.html", base_context(
        request, session, voices=voices, models=models, disk=storage.disk_usage()))


@app.post("/settings")
def settings_save(session: Session = Depends(get_session), _user: str = Depends(require_user),
                  kie_api_key: str = Form(""), default_chat_model: str = Form(""),
                  default_video_model: str = Form(""), default_image_model: str = Form(""),
                  default_tts_model: str = Form(""), tts_fallback_model: str = Form(""),
                  tts_fallback_voice: str = Form(""), tts_allow_fallback: str = Form(""),
                  auto_run_schedule: str = Form(""), auto_retry: str = Form(""),
                  scene_concurrency: int = Form(3),
                  music_library_target: int = Form(6), short_outro_sec: float = Form(4.0),
                  usd_per_credit: float = Form(0.005), new_password: str = Form(""),
                  pexels_api_key: str = Form(""), pixabay_api_key: str = Form(""),
                  stock_min_duration: float = Form(6.0), stock_per_page: int = Form(24),
                  disk_min_free_gb: float = Form(5.0)):
    if kie_api_key.strip():
        st.set_value(session, "kie_api_key", kie_api_key.strip())
    for key, value in (("default_chat_model", default_chat_model),
                       ("default_video_model", default_video_model),
                       ("default_image_model", default_image_model),
                       ("default_tts_model", default_tts_model),
                       ("tts_fallback_model", tts_fallback_model),
                       ("tts_fallback_voice", tts_fallback_voice)):
        if value.strip():
            st.set_value(session, key, value.strip())
    st.set_value(session, "tts_allow_fallback", "1" if tts_allow_fallback else "0")
    st.set_value(session, "auto_run_schedule", "1" if auto_run_schedule else "0")
    st.set_value(session, "auto_retry", "1" if auto_retry else "0")
    st.set_value(session, "scene_concurrency", max(1, min(8, scene_concurrency)))
    st.set_value(session, "music_library_target", max(1, min(20, music_library_target)))
    st.set_value(session, "short_outro_sec", max(0.0, min(15.0, short_outro_sec)))
    st.set_value(session, "usd_per_credit", usd_per_credit)
    # ключи стоков: пустое поле не затирает сохранённый ключ, слово "-" очищает
    for key, value in (("pexels_api_key", pexels_api_key), ("pixabay_api_key", pixabay_api_key)):
        value = value.strip()
        if value == "-":
            st.set_value(session, key, "")
        elif value:
            st.set_value(session, key, value)
    st.set_value(session, "stock_min_duration", max(0.0, min(60.0, stock_min_duration)))
    st.set_value(session, "stock_per_page", max(3, min(50, stock_per_page)))
    st.set_value(session, "disk_min_free_gb", max(0.0, min(500.0, disk_min_free_gb)))
    if new_password.strip():
        from .security import hash_password

        st.set_value(session, "admin_password_hash", hash_password(new_password.strip()))
    session.commit()
    return RedirectResponse("/settings", status_code=303)


@app.post("/settings/cleanup")
def settings_cleanup(session: Session = Depends(get_session), _user: str = Depends(require_user),
                     mode: str = Form("clips")):
    """Освобождение места: удаляем промежуточные файлы готовых роликов."""
    freed = 0
    removed = 0
    videos = session.execute(select(Video).where(Video.status == "done")).scalars().all()
    for video in videos:
        if not video.video_path:
            continue
        folder = storage.abspath(video.video_path).parent
        targets = []
        if mode in ("clips", "all"):
            targets.append(folder / "clips")
        if mode in ("audio", "all"):
            targets.append(folder / "audio")
        for target in targets:
            if not target.exists():
                continue
            freed += storage.dir_size(target)
            shutil.rmtree(target, ignore_errors=True)
            removed += 1
        if mode == "all":
            clean = folder / "video_clean.mp4"
            if clean.exists():
                freed += clean.stat().st_size
                clean.unlink(missing_ok=True)
    session.add(Event(level="info", stage="обслуживание",
                      message=f"Очистка ({mode}): освобождено {storage.human_size(freed)} "
                              f"в {removed} папках"))
    session.commit()
    return RedirectResponse("/settings", status_code=303)


@app.get("/queue", response_class=HTMLResponse)
def queue_page(request: Request, session: Session = Depends(get_session),
               _user: str = Depends(require_user)):
    jobs = session.execute(select(Job).order_by(Job.id.desc()).limit(120)).scalars().all()
    events = session.execute(select(Event).order_by(Event.id.desc()).limit(150)).scalars().all()
    return templates.TemplateResponse("queue.html", base_context(
        request, session, jobs=jobs, events=events))


@app.post("/queue/run-schedule")
def queue_run_schedule(session: Session = Depends(get_session), _user: str = Depends(require_user)):
    created = planner.run_schedule(ignore_time=True)
    return RedirectResponse("/queue" if not created else f"/videos/{created[0]}", status_code=303)


# --------------------------------------------------------------------------- медиа и API

@app.post("/api/suno-callback")
async def suno_callback(request: Request):
    """Приёмник уведомлений Suno. Результат мы забираем опросом, но адрес обязателен."""
    try:
        payload = await request.json()
    except Exception:  # noqa: BLE001
        payload = {}
    log.info("Suno callback: %s", str(payload)[:300])
    return {"code": 200, "msg": "ok"}


@app.head("/media/{path:path}")
@app.get("/media/{path:path}")
def media(path: str, request: Request, download: int = 0, _user: str = Depends(require_user)):
    target = webutil.safe_media_path(config.MEDIA_DIR, path)
    name = target.name if download else None
    return webutil.media_response(request, target, download_name=name)


@app.get("/api/status")
def api_status(session: Session = Depends(get_session), _user: str = Depends(require_user)):
    videos = session.execute(
        select(Video).where(Video.status.notin_(["done", "failed", "cancelled"]))
        .order_by(Video.id)).scalars().all()
    jobs = session.execute(
        select(func.count(Job.id)).where(Job.status.in_(["pending", "running"]))).scalar() or 0
    return {
        "jobs_active": jobs,
        "videos": [{"id": v.id, "title": v.title, "status": v.status, "stage": v.stage,
                    "progress": v.progress} for v in videos],
        "credits": st.get(session, "credits_balance", ""),
    }


@app.get("/api/video/{video_id}")
def api_video(video_id: int, session: Session = Depends(get_session),
              _user: str = Depends(require_user)):
    video = _video_or_404(session, video_id)
    return {
        "id": video.id, "title": video.title, "status": video.status, "stage": video.stage,
        "progress": video.progress, "error": video.error,
        "duration": video.duration_sec, "cost": video.cost_credits,
        "video_url": f"/media/{video.video_path}" if video.video_path else "",
        "scenes": [{"idx": s.idx, "heading": s.heading, "status": s.status,
                    "audio": bool(s.audio_path), "clip": bool(s.clip_path)}
                   for s in video.scenes],
        "shorts": [{"idx": s.idx, "title": s.title, "status": s.status,
                    "url": f"/media/{s.path}" if s.path else ""} for s in video.shorts],
    }


@app.post("/api/credits/refresh")
def api_credits(session: Session = Depends(get_session), _user: str = Depends(require_user)):
    try:
        balance = sync.sync_credits()
    except Exception as exc:  # noqa: BLE001
        raise HTTPException(status_code=502, detail=str(exc))
    return {"credits": balance}


