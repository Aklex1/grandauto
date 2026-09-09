"""Веб-приложение контент-завода (FastAPI + серверный рендеринг)."""
from __future__ import annotations

import datetime as dt
import json
import logging
import shutil
from pathlib import Path
from typing import Optional

from fastapi import Depends, FastAPI, Form, HTTPException, Request
from fastapi.responses import FileResponse, HTMLResponse, JSONResponse, RedirectResponse
from fastapi.staticfiles import StaticFiles
from fastapi.templating import Jinja2Templates
from starlette.datastructures import UploadFile
from sqlalchemy import func, select
from sqlalchemy.orm import Session

from . import (bootstrap, config, estimate, fonts, footage, pipeline, planner, prompts,
               queue, scheduler, stock, storage, subtitles, sync, webutil)
from . import settings_store as st
from .db import get_session, session_scope
from .kie import KieClient
from .models import (Channel, Event, Footage, Job, ModelPath, PlanItem, PriceItem,
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
    ctx = {
        "request": request,
        "channels": channels,
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


@app.post("/channels/{channel_id}/plan/extend")
def plan_extend(channel_id: int, session: Session = Depends(get_session),
                _user: str = Depends(require_user), count: int = Form(30)):
    """Дописываем контент-план через текстовую модель."""
    channel = _channel_or_404(session, channel_id)
    existing = [p.book_title for p in session.execute(
        select(PlanItem).where(PlanItem.channel_id == channel.id)).scalars().all()]
    client = KieClient(api_key=st.get(session, "kie_api_key") or None)
    audience = ("мужчины 25–45 лет" if "муж" in channel.name.lower() else "женщины 25–45 лет")
    messages = prompts.content_plan(channel.name, channel.topic, max(5, min(60, count)),
                                    audience, existing)
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
                     short_format: str = Form(""), title_font: str = Form("")):
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
                                       "title_font": fonts.normalize(title_font)}})
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
        short_formats=pipeline.SHORT_FORMATS, font_list=fonts.available()))


@app.post("/scenes/{scene_id}/regenerate")
def scene_regenerate(scene_id: int, session: Session = Depends(get_session),
                     _user: str = Depends(require_user),
                     visual_prompt: str = Form(""), narration: str = Form(""),
                     video_model: str = Form(""), tts_model: str = Form(""),
                     voice_id: str = Form(""), clips: int = Form(0),
                     redo_voice: str = Form(""), make_short: str = Form(""),
                     short_title: str = Form(""), short_format: str = Form("full"),
                     title_font: str = Form("")):
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
                  auto_run_schedule: str = Form(""), scene_concurrency: int = Form(3),
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
    st.set_value(session, "scene_concurrency", max(1, min(8, scene_concurrency)))
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


