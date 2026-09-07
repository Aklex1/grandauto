"""
Автопостинг: канал-источник -> KIE AI -> целевая группа.

Схема работы
------------
1. В канале-источнике появляется пост с фотографией.
2. В комментариях к этому посту (связанная группа обсуждений) лежит промпт.
3. Модуль берёт фото поста + референсное фото девушки (локальный файл) и отправляет
   их вместе с промптом в KIE AI (nano-banana-edit).
4. Готовое изображение публикуется в целевую группу вместе с текстом промпта.

За сутки обрабатывается не больше AUTOPOST_DAILY_LIMIT постов (по умолчанию 3),
остальные ждут в очереди и уходят на следующий день.

Требования
----------
* Бот должен быть администратором в канале-источнике (иначе не увидит посты)
  и в связанной группе обсуждений (иначе не увидит комментарии).
* CALLBACK_BASE_URL должен быть доступен из интернета: по нему KIE забирает
  исходные картинки и присылает результат.
"""

import asyncio
import html
import logging
import os
import re
import sqlite3
import time
from contextlib import closing
from datetime import datetime, timezone
from pathlib import Path
from typing import Optional

import httpx
from aiogram import Bot, Dispatcher, F
from aiogram.types import FSInputFile, Message, URLInputFile
from fastapi import Request
from fastapi.responses import FileResponse, JSONResponse

from config import CALLBACK_BASE_URL

logger = logging.getLogger("autopost")


def _env_int(name: str, default: int) -> int:
    try:
        return int(os.getenv(name, str(default)))
    except (TypeError, ValueError):
        return default


# --- Настройки ---------------------------------------------------------------

ENABLED = os.getenv("AUTOPOST_ENABLED", "0").strip() in ("1", "true", "yes", "on")
SOURCE_CHAT_ID = _env_int("AUTOPOST_SOURCE_CHAT_ID", 0)
TARGET_CHAT_ID = _env_int("AUTOPOST_TARGET_CHAT_ID", 0)
# Группа обсуждений источника. 0 — принимать комментарии из любой связанной группы.
DISCUSSION_CHAT_ID = _env_int("AUTOPOST_DISCUSSION_CHAT_ID", 0)

REFERENCE_IMAGE = Path(os.getenv("AUTOPOST_REFERENCE_IMAGE", "/opt/refer/refer.jpg"))
MEDIA_DIR = Path(os.getenv("AUTOPOST_MEDIA_DIR", "/opt/kie_ai_bot/autopost_media"))
DB_PATH = Path(os.getenv("AUTOPOST_DB", "/opt/kie_ai_bot/autopost.db"))

DAILY_LIMIT = _env_int("AUTOPOST_DAILY_LIMIT", 3)
# Как часто разгребается очередь (секунды)
WORKER_INTERVAL = _env_int("AUTOPOST_WORKER_INTERVAL", 120)
# Сколько ждать промпт в комментариях, прежде чем считать пост брошенным (часы)
PROMPT_WAIT_HOURS = _env_int("AUTOPOST_PROMPT_WAIT_HOURS", 48)
# Соотношение сторон результата: auto / 1:1 / 9:16 / 16:9 ...
IMAGE_SIZE = os.getenv("AUTOPOST_IMAGE_SIZE", "auto")
OUTPUT_FORMAT = os.getenv("AUTOPOST_OUTPUT_FORMAT", "png")
# Публиковать ли текст промпта вместе с картинкой
PUBLISH_PROMPT = os.getenv("AUTOPOST_PUBLISH_PROMPT", "1").strip() in ("1", "true", "yes", "on")

# --- Оформление поста (как в целевом канале) ---
BOT_URL = os.getenv("AUTOPOST_BOT_URL", "https://t.me/Neuro_HubAI_bot?start=Sv_lana0707")
SITE_URL = os.getenv("AUTOPOST_SITE_URL", "https://genius-bot.ru/neurohub/?ref=sv07")
MAX_URL = os.getenv("AUTOPOST_MAX_URL", "")
# Хештег по умолчанию, если в исходном посте своих нет
DEFAULT_HASHTAGS = os.getenv("AUTOPOST_HASHTAGS", "#Женский")
FOOTER = os.getenv("AUTOPOST_FOOTER", "⚜️⚜️⚜️⚜️⚜️⚜️⚜️⚜️")

CAPTION_LIMIT = 1024

# Как отдавать картинки в Kie AI:
# 1 — загружать в файловое хранилище Kie (не требует публичного адреса у бота),
# 0 — отдавать ссылками на собственный FastAPI (нужен доступный снаружи CALLBACK_BASE_URL).
UPLOAD_VIA_KIE = os.getenv("AUTOPOST_UPLOAD_VIA_KIE", "1").strip() in ("1", "true", "yes", "on")
KIE_UPLOAD_URL = os.getenv("KIE_UPLOAD_URL", "https://kieai.redpandaai.co/api/file-base64-upload")
# Если callback от Kie не пришёл за столько минут — узнаём результат опросом
POLL_AFTER_MINUTES = _env_int("AUTOPOST_POLL_AFTER_MINUTES", 5)
KIE_RECORD_URL = "https://api.kie.ai/api/v1/jobs/recordInfo"

CALLBACK_PATH = "/autopost-callback"


# --- Хранилище (отдельный SQLite, схему основной БД не трогаем) ---------------

def _connect() -> sqlite3.Connection:
    DB_PATH.parent.mkdir(parents=True, exist_ok=True)
    conn = sqlite3.connect(DB_PATH, timeout=30)
    conn.row_factory = sqlite3.Row
    return conn


def init_db() -> None:
    with closing(_connect()) as conn:
        conn.execute(
            """
            CREATE TABLE IF NOT EXISTS autopost_posts (
                source_msg_id   INTEGER PRIMARY KEY,
                photo_file_id   TEXT,
                source_caption  TEXT,
                photo_path      TEXT,
                prompt          TEXT,
                task_id         TEXT,
                result_url      TEXT,
                status          TEXT NOT NULL DEFAULT 'waiting_prompt',
                error           TEXT,
                created_at      TEXT NOT NULL,
                sent_at         TEXT,
                published_at    TEXT
            )
            """
        )
        # Миграция для баз, созданных до появления колонки
        cols = {r["name"] for r in conn.execute("PRAGMA table_info(autopost_posts)")}
        if "source_caption" not in cols:
            conn.execute("ALTER TABLE autopost_posts ADD COLUMN source_caption TEXT")
        conn.execute("CREATE INDEX IF NOT EXISTS idx_autopost_status ON autopost_posts(status)")
        conn.execute("CREATE INDEX IF NOT EXISTS idx_autopost_task ON autopost_posts(task_id)")
        conn.commit()


def _now() -> str:
    return datetime.now(timezone.utc).isoformat(timespec="seconds")


def _update(source_msg_id: int, **fields) -> None:
    if not fields:
        return
    sets = ", ".join(f"{k} = ?" for k in fields)
    with closing(_connect()) as conn:
        conn.execute(
            f"UPDATE autopost_posts SET {sets} WHERE source_msg_id = ?",
            (*fields.values(), source_msg_id),
        )
        conn.commit()


def _get(source_msg_id: int) -> Optional[sqlite3.Row]:
    with closing(_connect()) as conn:
        cur = conn.execute("SELECT * FROM autopost_posts WHERE source_msg_id = ?", (source_msg_id,))
        return cur.fetchone()


def _get_by_task(task_id: str) -> Optional[sqlite3.Row]:
    with closing(_connect()) as conn:
        cur = conn.execute("SELECT * FROM autopost_posts WHERE task_id = ?", (task_id,))
        return cur.fetchone()


def _published_today() -> int:
    """Сколько постов уже отправлено в генерацию за текущие сутки (UTC)."""
    today = datetime.now(timezone.utc).date().isoformat()
    with closing(_connect()) as conn:
        cur = conn.execute(
            "SELECT COUNT(*) AS n FROM autopost_posts WHERE sent_at IS NOT NULL AND sent_at >= ?",
            (today,),
        )
        return int(cur.fetchone()["n"])


# --- Работа с картинками ------------------------------------------------------

def _public_url(path: str) -> str:
    return f"{CALLBACK_BASE_URL}{path}"


async def _download_photo(bot: Bot, file_id: str, source_msg_id: int) -> Path:
    """Скачивает фото поста на диск, чтобы отдать KIE ссылку без токена бота."""
    MEDIA_DIR.mkdir(parents=True, exist_ok=True)
    tg_file = await bot.get_file(file_id)
    suffix = Path(tg_file.file_path or "photo.jpg").suffix or ".jpg"
    dest = MEDIA_DIR / f"post_{source_msg_id}{suffix}"
    await bot.download_file(tg_file.file_path, destination=str(dest))
    return dest


# --- Отправка задачи в KIE ----------------------------------------------------

async def _upload_to_kie(path: Path) -> Optional[str]:
    """Кладёт файл в файловое хранилище Kie AI и возвращает публичную ссылку."""
    import base64

    from config import KIE_API_KEY

    mime = "image/png" if path.suffix.lower() == ".png" else "image/jpeg"
    payload = {
        "base64Data": f"data:{mime};base64," + base64.b64encode(path.read_bytes()).decode(),
        "uploadPath": "images/autopost",
        "fileName": path.name,
    }
    try:
        async with httpx.AsyncClient(timeout=180) as client:
            resp = await client.post(
                KIE_UPLOAD_URL,
                headers={"Authorization": f"Bearer {KIE_API_KEY}", "Content-Type": "application/json"},
                json=payload,
            )
        resp.raise_for_status()
        url = ((resp.json() or {}).get("data") or {}).get("downloadUrl")
        if not url:
            logger.error("[autopost] загрузка %s: в ответе нет downloadUrl: %s", path.name, resp.text[:200])
        return url
    except Exception as e:
        logger.error("[autopost] загрузка %s в Kie не удалась: %s", path.name, e)
        return None



async def _submit_to_kie(bot: Bot, row: sqlite3.Row) -> bool:
    from kie_api import create_nano_banana_task

    source_msg_id = row["source_msg_id"]
    prompt = (row["prompt"] or "").strip()

    if not REFERENCE_IMAGE.exists():
        msg = f"нет референсного фото {REFERENCE_IMAGE}"
        logger.error("[autopost] пост %s: %s", source_msg_id, msg)
        _update(source_msg_id, status="error", error=msg)
        return False

    photo_path = row["photo_path"]
    if not photo_path or not Path(photo_path).exists():
        try:
            photo_path = str(await _download_photo(bot, row["photo_file_id"], source_msg_id))
            _update(source_msg_id, photo_path=photo_path)
        except Exception as e:
            logger.error("[autopost] пост %s: не удалось скачать фото: %s", source_msg_id, e)
            _update(source_msg_id, status="error", error=f"скачивание фото: {e}")
            return False

    if UPLOAD_VIA_KIE:
        post_url = await _upload_to_kie(Path(photo_path))
        ref_url = await _upload_to_kie(REFERENCE_IMAGE)
        if not post_url or not ref_url:
            _update(source_msg_id, status="error", error="не удалось загрузить картинки в Kie AI")
            return False
        image_urls = [post_url, ref_url]
    else:
        image_urls = [
            _public_url(f"/autopost/media/{Path(photo_path).name}"),
            _public_url("/autopost/reference.jpg"),
        ]

    logger.info("[autopost] пост %s -> KIE, промпт: %.80s", source_msg_id, prompt)
    try:
        response = await create_nano_banana_task(
            mode="edit",
            prompt=prompt,
            image_urls=image_urls,
            output_format=OUTPUT_FORMAT,
            image_size=IMAGE_SIZE,
            callback_url=_public_url(CALLBACK_PATH),
        )
    except Exception as e:
        logger.error("[autopost] пост %s: ошибка запроса к KIE: %s", source_msg_id, e)
        _update(source_msg_id, status="error", error=f"запрос к KIE: {e}")
        return False

    data = response.get("data") or {}
    task_id = data.get("taskId") or data.get("task_id")
    if not task_id:
        logger.error("[autopost] пост %s: KIE не вернул taskId: %s", source_msg_id, response)
        _update(source_msg_id, status="error", error=f"KIE не вернул taskId: {response}")
        return False

    _update(source_msg_id, status="generating", task_id=str(task_id), sent_at=_now(), error=None)
    logger.info("[autopost] пост %s: задача %s создана", source_msg_id, task_id)
    return True


# --- Оформление поста -------------------------------------------------------

def _hashtags_from(source_caption: Optional[str]) -> str:
    """Забирает хештеги из исходного поста, иначе берёт значение по умолчанию."""
    tags = re.findall(r"#[^\s#]+", source_caption or "")
    return " ".join(tags) if tags else DEFAULT_HASHTAGS


def build_caption(prompt: str, source_caption: Optional[str] = None) -> str:
    """Подпись в том же виде, что и остальные посты канала."""
    lines = [_hashtags_from(source_caption)]
    if BOT_URL:
        lines.append(f'<a href="{BOT_URL}">БОТ</a> через который можно сделать фото. ')
    if SITE_URL:
        lines.append(f'<a href="{SITE_URL}">САЙТ</a> через который можно сделать фото. ')
    if MAX_URL:
        lines.append(f'<a href="{MAX_URL}">Канал с промтами в MAX</a> 📱')
    header = "\n".join(lines)

    body = f"<blockquote><code>{html.escape(prompt.strip())}</code></blockquote>" if prompt.strip() else ""
    parts = [header]
    if body:
        parts.append(body)
    if FOOTER:
        parts.append(FOOTER)
    return "\n".join(parts)


def _visible_len(caption_html: str) -> int:
    """Telegram считает лимит подписи по видимому тексту, а не по HTML-разметке."""
    return len(html.unescape(re.sub(r"<[^>]+>", "", caption_html)))


def build_header_only() -> str:
    """Шапка без промпта — если промпт не влезает в подпись к фото."""
    return build_caption("")


# --- Публикация результата ----------------------------------------------------

async def _publish(bot: Bot, row: sqlite3.Row, result_url: str) -> None:
    source_msg_id = row["source_msg_id"]
    prompt = (row["prompt"] or "").strip()
    source_caption = row["source_caption"] if "source_caption" in row.keys() else None

    caption = build_caption(prompt, source_caption) if PUBLISH_PROMPT else build_header_only()
    # Промпты бывают длиннее лимита подписи — тогда шапка идёт с фото,
    # а промпт отдельным сообщением следом.
    prompt_as_separate_message = None
    if _visible_len(caption) > CAPTION_LIMIT:
        caption = build_header_only()
        prompt_as_separate_message = f"<blockquote><code>{html.escape(prompt)}</code></blockquote>"
        logger.info("[autopost] пост %s: промпт длинный, уйдёт отдельным сообщением", source_msg_id)

    async def _send(photo):
        return await bot.send_photo(
            chat_id=TARGET_CHAT_ID,
            photo=photo,
            caption=caption,
            parse_mode="HTML",
        )

    try:
        sent = await _send(URLInputFile(result_url))
    except Exception as e:
        # Резервный путь: телеграм иногда не может забрать картинку по ссылке
        logger.warning("[autopost] пост %s: send_photo по URL не прошёл (%s), пробуем файлом", source_msg_id, e)
        try:
            MEDIA_DIR.mkdir(parents=True, exist_ok=True)
            dest = MEDIA_DIR / f"result_{source_msg_id}.png"
            async with httpx.AsyncClient(timeout=120) as client:
                resp = await client.get(result_url)
                resp.raise_for_status()
                dest.write_bytes(resp.content)
            sent = await _send(FSInputFile(str(dest)))
        except Exception as e2:
            logger.error("[autopost] пост %s: публикация не удалась: %s", source_msg_id, e2)
            _update(source_msg_id, status="error", error=f"публикация: {e2}")
            return

    if prompt_as_separate_message:
        try:
            await bot.send_message(
                chat_id=TARGET_CHAT_ID,
                text=prompt_as_separate_message,
                parse_mode="HTML",
                reply_to_message_id=sent.message_id,
            )
        except Exception as e:
            logger.error("[autopost] пост %s: промпт отдельным сообщением не ушёл: %s", source_msg_id, e)

    _update(source_msg_id, status="published", result_url=result_url, published_at=_now(), error=None)
    logger.info("[autopost] пост %s опубликован в %s", source_msg_id, TARGET_CHAT_ID)


def _extract_result_url(task_data: dict) -> Optional[str]:
    """Достаёт ссылку на готовое изображение из тела callback-а KIE."""
    import json

    result_json = task_data.get("resultJson")
    if isinstance(result_json, str):
        try:
            result_json = json.loads(result_json)
        except Exception:
            result_json = {}
    result_json = result_json or {}

    for key in ("resultUrls", "images"):
        urls = result_json.get(key)
        if isinstance(urls, list) and urls:
            return str(urls[0])
    if isinstance(result_json.get("result"), str):
        return result_json["result"]
    urls = task_data.get("resultUrls")
    if isinstance(urls, list) and urls:
        return str(urls[0])
    return None


# --- Резервный путь: узнаём результат опросом, если callback не пришёл ---------

async def _poll_stuck_tasks(bot: Bot) -> None:
    """Kie присылает callback на CALLBACK_BASE_URL; если он недоступен снаружи,
    результат всё равно заберётся опросом recordInfo."""
    from config import KIE_API_KEY

    deadline = time.time() - POLL_AFTER_MINUTES * 60
    with closing(_connect()) as conn:
        rows = conn.execute(
            "SELECT * FROM autopost_posts WHERE status = 'generating' AND task_id IS NOT NULL"
        ).fetchall()

    for row in rows:
        try:
            sent = datetime.fromisoformat(row["sent_at"]).timestamp()
        except Exception:
            continue
        if sent > deadline:
            continue  # ещё ждём callback

        try:
            async with httpx.AsyncClient(timeout=60) as client:
                resp = await client.get(
                    KIE_RECORD_URL,
                    params={"taskId": row["task_id"]},
                    headers={"Authorization": f"Bearer {KIE_API_KEY}"},
                )
            task_data = (resp.json() or {}).get("data") or {}
        except Exception as e:
            logger.error("[autopost] пост %s: опрос статуса не удался: %s", row["source_msg_id"], e)
            continue

        state = (task_data.get("state") or task_data.get("status") or "").lower()
        if state in ("success", "succeeded", "completed"):
            result_url = _extract_result_url(task_data)
            if result_url:
                logger.info("[autopost] пост %s: результат получен опросом", row["source_msg_id"])
                await _publish(bot, row, result_url)
            else:
                _update(row["source_msg_id"], status="error", error="Kie вернул успех без ссылки")
        elif state in ("fail", "failed", "error"):
            _update(row["source_msg_id"], status="error",
                    error=task_data.get("failMsg") or task_data.get("msg") or "генерация не удалась")
            logger.error("[autopost] пост %s: генерация не удалась", row["source_msg_id"])


# --- Фоновый воркер: очередь и суточный лимит ---------------------------------

async def autopost_worker(bot: Bot) -> None:
    if not ENABLED:
        logger.info("[autopost] выключен (AUTOPOST_ENABLED=0)")
        return
    if not SOURCE_CHAT_ID or not TARGET_CHAT_ID:
        logger.error("[autopost] не задан AUTOPOST_SOURCE_CHAT_ID / AUTOPOST_TARGET_CHAT_ID — модуль не работает")
        return

    init_db()
    logger.info(
        "[autopost] запущен: источник=%s, цель=%s, лимит=%s постов/сутки, референс=%s",
        SOURCE_CHAT_ID, TARGET_CHAT_ID, DAILY_LIMIT, REFERENCE_IMAGE,
    )

    while True:
        try:
            free_slots = DAILY_LIMIT - _published_today()
            if free_slots > 0:
                with closing(_connect()) as conn:
                    rows = conn.execute(
                        "SELECT * FROM autopost_posts WHERE status = 'ready' "
                        "ORDER BY source_msg_id LIMIT ?",
                        (free_slots,),
                    ).fetchall()
                for row in rows:
                    await _submit_to_kie(bot, row)

            await _poll_stuck_tasks(bot)

            # Посты, которые слишком долго ждут промпт в комментариях
            deadline = time.time() - PROMPT_WAIT_HOURS * 3600
            with closing(_connect()) as conn:
                stale = conn.execute(
                    "SELECT source_msg_id, created_at FROM autopost_posts WHERE status = 'waiting_prompt'"
                ).fetchall()
            for row in stale:
                try:
                    created = datetime.fromisoformat(row["created_at"]).timestamp()
                except Exception:
                    continue
                if created < deadline:
                    _update(row["source_msg_id"], status="expired", error="промпт в комментариях не появился")
                    logger.info("[autopost] пост %s снят: промпт так и не появился", row["source_msg_id"])
        except Exception as e:
            logger.error("[autopost] ошибка воркера: %s", e, exc_info=True)

        await asyncio.sleep(WORKER_INTERVAL)


# --- Обработчики Telegram -----------------------------------------------------

def setup_autopost(dp: Dispatcher, bot: Bot) -> None:
    """Регистрирует обработчики канала-источника и комментариев."""
    if not ENABLED:
        return
    if not SOURCE_CHAT_ID or not TARGET_CHAT_ID:
        logger.error("[autopost] не заданы ID чатов — обработчики не зарегистрированы")
        return

    init_db()

    @dp.channel_post(F.chat.id == SOURCE_CHAT_ID, F.photo)
    async def on_source_post(message: Message):
        """Новый пост с фото в канале-источнике."""
        if _get(message.message_id):
            return
        photo = message.photo[-1]  # самое большое разрешение
        with closing(_connect()) as conn:
            conn.execute(
                "INSERT OR IGNORE INTO autopost_posts "
                "(source_msg_id, photo_file_id, source_caption, status, created_at) "
                "VALUES (?, ?, ?, 'waiting_prompt', ?)",
                (message.message_id, photo.file_id, message.caption or "", _now()),
            )
            conn.commit()
        logger.info("[autopost] новый пост %s, ждём промпт в комментариях", message.message_id)

    @dp.message(F.reply_to_message)
    async def on_discussion_comment(message: Message):
        """Комментарий в группе обсуждений — источник промпта."""
        reply = message.reply_to_message
        origin_chat = reply.forward_from_chat or getattr(reply, "sender_chat", None)
        if not origin_chat or origin_chat.id != SOURCE_CHAT_ID:
            return
        if DISCUSSION_CHAT_ID and message.chat.id != DISCUSSION_CHAT_ID:
            return

        source_msg_id = reply.forward_from_message_id
        if not source_msg_id:
            return

        prompt = (message.text or message.caption or "").strip()
        if not prompt:
            return

        row = _get(source_msg_id)
        if not row:
            logger.info("[autopost] комментарий к неизвестному посту %s — пропуск", source_msg_id)
            return
        if row["status"] not in ("waiting_prompt",):
            return  # промпт уже взят, повторные комментарии игнорируем

        _update(source_msg_id, prompt=prompt, status="ready")
        logger.info("[autopost] пост %s: промпт получен (%s символов)", source_msg_id, len(prompt))


def setup_autopost_routes(app, bot: Bot) -> None:
    """Регистрирует HTTP-маршруты: отдача картинок для KIE и приём результата."""
    if not ENABLED:
        return

    @app.get("/autopost/reference.jpg")
    async def autopost_reference():
        if not REFERENCE_IMAGE.exists():
            return JSONResponse({"error": "reference image not found"}, status_code=404)
        return FileResponse(str(REFERENCE_IMAGE), media_type="image/jpeg")

    @app.get("/autopost/media/{filename}")
    async def autopost_media(filename: str):
        # только имя файла, без выхода за пределы каталога
        safe = Path(filename).name
        path = MEDIA_DIR / safe
        if not path.exists():
            return JSONResponse({"error": "not found"}, status_code=404)
        return FileResponse(str(path))

    @app.post(CALLBACK_PATH)
    async def autopost_callback(request: Request):
        data = await request.json()
        logger.info("[autopost] callback: %s", data)

        task_data = data.get("data") or {}
        task_id = task_data.get("taskId") or task_data.get("task_id")
        if not task_id:
            return {"status": "received"}

        row = _get_by_task(str(task_id))
        if not row:
            return {"status": "received"}

        code = data.get("code")
        state = (task_data.get("state") or "").lower()

        if code == 200 and state in ("success", ""):
            result_url = _extract_result_url(task_data)
            if result_url:
                await _publish(bot, row, result_url)
                return {"status": "received"}

        error = data.get("msg") or f"state={state}"
        logger.error("[autopost] пост %s: генерация не удалась: %s", row["source_msg_id"], error)
        _update(row["source_msg_id"], status="error", error=str(error))
        return {"status": "received"}
