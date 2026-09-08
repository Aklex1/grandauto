"""
Автопостинг: каналы-источники -> Kie AI -> целевой канал.

Схема работы
------------
1. В канале-источнике берётся пост с фотографией (первое фото, если это альбом).
2. Промпт берётся из комментариев к посту. Комментария нет — пост пропускается.
3. Фото поста и референсное фото девушки уходят в Kie AI (nano-banana-edit)
   вместе с промптом.
4. Результат публикуется в целевой канал в оформлении канала, а текст промпта
   отправляется комментарием под этим постом (в связанную группу обсуждений).

За сутки обрабатывается не больше AUTOPOST_DAILY_LIMIT постов, остальные ждут
в очереди и уходят на следующий день.

Источники читает telethon_source.py (чужие каналы Bot API не отдаёт), либо,
если бот администратор в канале-источнике, посты ловятся в реальном времени.
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


def _env_flag(name: str, default: str = "0") -> bool:
    return os.getenv(name, default).strip().lower() in ("1", "true", "yes", "on")


def _parse_chat_ids(raw: str) -> list:
    """Список ID каналов через запятую, пробел или перевод строки."""
    ids = []
    for chunk in re.split(r"[,\s]+", raw or ""):
        chunk = chunk.strip()
        if not chunk:
            continue
        try:
            ids.append(int(chunk))
        except ValueError:
            logger.warning("[autopost] не похоже на ID канала: %r", chunk)
    return ids


# --- Настройки ---------------------------------------------------------------

ENABLED = _env_flag("AUTOPOST_ENABLED")

# Каналы-источники: список в AUTOPOST_SOURCE_CHAT_IDS, старое имя тоже понимается
SOURCE_CHAT_IDS = _parse_chat_ids(
    os.getenv("AUTOPOST_SOURCE_CHAT_IDS") or os.getenv("AUTOPOST_SOURCE_CHAT_ID", "")
)
TARGET_CHAT_ID = _env_int("AUTOPOST_TARGET_CHAT_ID", 0)
# Группа обсуждений целевого канала; 0 — определить автоматически при старте
DISCUSSION_CHAT_ID = _env_int("AUTOPOST_DISCUSSION_CHAT_ID", 0)

REFERENCE_IMAGE = Path(os.getenv("AUTOPOST_REFERENCE_IMAGE", "/opt/refer/refer.jpg"))
MEDIA_DIR = Path(os.getenv("AUTOPOST_MEDIA_DIR", "/opt/kie_ai_bot/autopost_media"))
DB_PATH = Path(os.getenv("AUTOPOST_DB", "/opt/kie_ai_bot/autopost.db"))

# Сколько новых постов брать с каждого канала за сутки
PER_CHANNEL_DAILY = _env_int("AUTOPOST_PER_CHANNEL_DAILY", 2)
# Суточный потолок публикаций. По умолчанию — по PER_CHANNEL_DAILY с каждого канала
DAILY_LIMIT = _env_int("AUTOPOST_DAILY_LIMIT", PER_CHANNEL_DAILY * max(1, len(SOURCE_CHAT_IDS)))
# Растягивать публикации равномерно по суткам, а не выкладывать пачкой
SPREAD_OVER_DAY = _env_flag("AUTOPOST_SPREAD_OVER_DAY", "1")
WORKER_INTERVAL = _env_int("AUTOPOST_WORKER_INTERVAL", 120)
IMAGE_SIZE = os.getenv("AUTOPOST_IMAGE_SIZE", "auto")
OUTPUT_FORMAT = os.getenv("AUTOPOST_OUTPUT_FORMAT", "png")

# --- Оформление поста (как в целевом канале) ---
BOT_URL = os.getenv("AUTOPOST_BOT_URL", "https://t.me/Neuro_HubAI_bot?start=Sv_lana0707")
SITE_URL = os.getenv("AUTOPOST_SITE_URL", "https://genius-bot.ru/neurohub/?ref=sv07")
MAX_URL = os.getenv("AUTOPOST_MAX_URL", "")
DEFAULT_HASHTAGS = os.getenv("AUTOPOST_HASHTAGS", "#Женский")
FOOTER = os.getenv("AUTOPOST_FOOTER", "⚜️⚜️⚜️⚜️⚜️⚜️⚜️⚜️")

CAPTION_LIMIT = 1024
MESSAGE_LIMIT = 4096
# Сколько ждать, пока пост долетит до группы обсуждений, секунд
DISCUSSION_WAIT = _env_int("AUTOPOST_DISCUSSION_WAIT", 20)

# Как отдавать картинки в Kie AI:
# 1 — загружать в файловое хранилище Kie (публичный адрес боту не нужен),
# 0 — отдавать ссылками на собственный FastAPI (нужен доступный CALLBACK_BASE_URL).
UPLOAD_VIA_KIE = _env_flag("AUTOPOST_UPLOAD_VIA_KIE", "1")
KIE_UPLOAD_URL = os.getenv("KIE_UPLOAD_URL", "https://kieai.redpandaai.co/api/file-base64-upload")
POLL_AFTER_MINUTES = _env_int("AUTOPOST_POLL_AFTER_MINUTES", 5)
KIE_RECORD_URL = "https://api.kie.ai/api/v1/jobs/recordInfo"

CALLBACK_PATH = "/autopost-callback"

# Соответствие «пост в канале -> его сообщение в группе обсуждений».
# Заполняется обработчиком автопересылок, нужно чтобы отвечать комментарием.
_discussion_map: dict = {}


# --- Хранилище (отдельный SQLite, схему основной БД не трогаем) ---------------

def _connect() -> sqlite3.Connection:
    DB_PATH.parent.mkdir(parents=True, exist_ok=True)
    conn = sqlite3.connect(DB_PATH, timeout=30)
    conn.row_factory = sqlite3.Row
    return conn


def init_db() -> None:
    with closing(_connect()) as conn:
        # Схема до появления нескольких источников: ключом был только id поста
        existing = conn.execute(
            "SELECT name FROM sqlite_master WHERE type='table' AND name='autopost_posts'"
        ).fetchone()
        if existing:
            cols = {r["name"] for r in conn.execute("PRAGMA table_info(autopost_posts)")}
            if "source_chat_id" not in cols:
                conn.execute("ALTER TABLE autopost_posts RENAME TO autopost_posts_v1")
                logger.info("[autopost] старая таблица сохранена как autopost_posts_v1")

        conn.execute(
            """
            CREATE TABLE IF NOT EXISTS autopost_posts (
                id              INTEGER PRIMARY KEY AUTOINCREMENT,
                source_chat_id  INTEGER NOT NULL,
                source_msg_id   INTEGER NOT NULL,
                photo_path      TEXT,
                prompt          TEXT,
                source_caption  TEXT,
                task_id         TEXT,
                result_url      TEXT,
                status          TEXT NOT NULL DEFAULT 'ready',
                error           TEXT,
                created_at      TEXT NOT NULL,
                sent_at         TEXT,
                published_at    TEXT,
                UNIQUE(source_chat_id, source_msg_id)
            )
            """
        )
        conn.execute("CREATE INDEX IF NOT EXISTS idx_autopost_status ON autopost_posts(status)")
        conn.execute("CREATE INDEX IF NOT EXISTS idx_autopost_task ON autopost_posts(task_id)")
        conn.commit()


def _now() -> str:
    return datetime.now(timezone.utc).isoformat(timespec="seconds")


def _update(row_id: int, **fields) -> None:
    if not fields:
        return
    sets = ", ".join(f"{k} = ?" for k in fields)
    with closing(_connect()) as conn:
        conn.execute(f"UPDATE autopost_posts SET {sets} WHERE id = ?", (*fields.values(), row_id))
        conn.commit()


def get_post(source_chat_id: int, source_msg_id: int) -> Optional[sqlite3.Row]:
    with closing(_connect()) as conn:
        return conn.execute(
            "SELECT * FROM autopost_posts WHERE source_chat_id = ? AND source_msg_id = ?",
            (source_chat_id, source_msg_id),
        ).fetchone()


def add_post(source_chat_id: int, source_msg_id: int, prompt: str,
             source_caption: str = "", photo_path: Optional[str] = None,
             status: str = "ready") -> Optional[int]:
    """Кладёт пост в очередь. Возвращает id записи или None, если уже был."""
    with closing(_connect()) as conn:
        cur = conn.execute(
            "INSERT OR IGNORE INTO autopost_posts "
            "(source_chat_id, source_msg_id, photo_path, prompt, source_caption, status, created_at) "
            "VALUES (?, ?, ?, ?, ?, ?, ?)",
            (source_chat_id, source_msg_id, photo_path, prompt, source_caption, status, _now()),
        )
        conn.commit()
        return cur.lastrowid if cur.rowcount else None


def known_msg_ids(source_chat_id: int) -> set:
    with closing(_connect()) as conn:
        return {
            r["source_msg_id"]
            for r in conn.execute(
                "SELECT source_msg_id FROM autopost_posts WHERE source_chat_id = ?",
                (source_chat_id,),
            )
        }


def _get_by_task(task_id: str) -> Optional[sqlite3.Row]:
    with closing(_connect()) as conn:
        return conn.execute(
            "SELECT * FROM autopost_posts WHERE task_id = ?", (task_id,)
        ).fetchone()


def _sent_today() -> int:
    """Сколько постов уже ушло в генерацию за текущие сутки (UTC)."""
    today = datetime.now(timezone.utc).date().isoformat()
    with closing(_connect()) as conn:
        return int(conn.execute(
            "SELECT COUNT(*) AS n FROM autopost_posts WHERE sent_at IS NOT NULL AND sent_at >= ?",
            (today,),
        ).fetchone()["n"])


def free_slots() -> int:
    """Сколько постов ещё можно взять в работу сегодня."""
    with closing(_connect()) as conn:
        ready = int(conn.execute(
            "SELECT COUNT(*) AS n FROM autopost_posts WHERE status = 'ready'"
        ).fetchone()["n"])
    return max(0, DAILY_LIMIT - _sent_today() - ready)


def taken_today(source_chat_id: int) -> int:
    """Сколько постов уже взято из этого канала за текущие сутки."""
    today = datetime.now(timezone.utc).date().isoformat()
    with closing(_connect()) as conn:
        return int(conn.execute(
            "SELECT COUNT(*) AS n FROM autopost_posts "
            "WHERE source_chat_id = ? AND created_at >= ?",
            (source_chat_id, today),
        ).fetchone()["n"])


def channel_quota(source_chat_id: int) -> int:
    """Сколько ещё постов можно взять из этого канала сегодня."""
    return max(0, PER_CHANNEL_DAILY - taken_today(source_chat_id))


def _seconds_since_last_send() -> Optional[float]:
    """Сколько секунд прошло с последней отправки в генерацию."""
    with closing(_connect()) as conn:
        row = conn.execute(
            "SELECT MAX(sent_at) AS last FROM autopost_posts WHERE sent_at IS NOT NULL"
        ).fetchone()
    if not row or not row["last"]:
        return None
    try:
        last = datetime.fromisoformat(row["last"])
    except ValueError:
        return None
    return (datetime.now(timezone.utc) - last).total_seconds()


def publish_interval() -> float:
    """Пауза между публикациями, чтобы растянуть суточный лимит на сутки."""
    return 86400.0 / max(1, DAILY_LIMIT)


# --- Оформление поста --------------------------------------------------------

def _hashtags_from(source_caption: Optional[str]) -> str:
    """Забирает хештеги из исходного поста, иначе берёт значение по умолчанию."""
    tags = re.findall(r"#[^\s#]+", source_caption or "")
    return " ".join(tags) if tags else DEFAULT_HASHTAGS


def build_caption(source_caption: Optional[str] = None) -> str:
    """Подпись к фото — в том же виде, что и остальные посты канала."""
    lines = [_hashtags_from(source_caption)]
    if BOT_URL:
        lines.append(f'<a href="{BOT_URL}">БОТ</a> через который можно сделать фото. ')
    if SITE_URL:
        lines.append(f'<a href="{SITE_URL}">САЙТ</a> через который можно сделать фото. ')
    if MAX_URL:
        lines.append(f'<a href="{MAX_URL}">Канал с промтами в MAX</a> 📱')
    if FOOTER:
        lines.append(FOOTER)
    return "\n".join(lines)


def _visible_len(caption_html: str) -> int:
    """Telegram считает лимит подписи по видимому тексту, а не по разметке."""
    return len(html.unescape(re.sub(r"<[^>]+>", "", caption_html)))


def build_caption_with_prompt(header: str, prompt: str) -> str:
    """Подпись вместе с промптом, если он туда влезает."""
    if not prompt:
        return header
    full = f"{header}\n{build_comment(prompt)}"
    return full if _visible_len(full) <= CAPTION_LIMIT else header


def build_comment(prompt: str) -> str:
    """Комментарий с промптом — цитатой моноширинным шрифтом, копируется по тапу."""
    text = prompt.strip()
    if len(text) > MESSAGE_LIMIT - 100:
        text = text[: MESSAGE_LIMIT - 103] + "..."
    return f"<blockquote><code>{html.escape(text)}</code></blockquote>"


# --- Загрузка картинок в Kie AI ----------------------------------------------

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


def _public_url(path: str) -> str:
    return f"{CALLBACK_BASE_URL}{path}"


# --- Отправка задачи в Kie AI -------------------------------------------------

async def submit_to_kie(row: sqlite3.Row) -> bool:
    from kie_api import create_nano_banana_task

    row_id = row["id"]
    prompt = (row["prompt"] or "").strip()

    if not REFERENCE_IMAGE.exists():
        msg = f"нет референсного фото {REFERENCE_IMAGE}"
        logger.error("[autopost] запись %s: %s", row_id, msg)
        _update(row_id, status="error", error=msg)
        return False

    # В Kie AI уходит только референсное фото с сервера: картинку исходного
    # поста не используем, генерация идёт по промпту поверх нашей модели.
    if UPLOAD_VIA_KIE:
        ref_url = await _upload_to_kie(REFERENCE_IMAGE)
        if not ref_url:
            _update(row_id, status="error", error="не удалось загрузить референс в Kie AI")
            return False
        image_urls = [ref_url]
    else:
        image_urls = [_public_url("/autopost/reference.jpg")]

    logger.info("[autopost] запись %s -> Kie AI, промпт: %.80s", row_id, prompt)
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
        logger.error("[autopost] запись %s: ошибка запроса к Kie: %s", row_id, e)
        _update(row_id, status="error", error=f"запрос к Kie: {e}")
        return False

    task_id = (response.get("data") or {}).get("taskId") or (response.get("data") or {}).get("task_id")
    if not task_id:
        logger.error("[autopost] запись %s: Kie не вернул taskId: %s", row_id, response)
        _update(row_id, status="error", error=f"Kie не вернул taskId: {response}")
        return False

    _update(row_id, status="generating", task_id=str(task_id), sent_at=_now(), error=None)
    logger.info("[autopost] запись %s: задача %s создана", row_id, task_id)
    return True


# --- Публикация результата ----------------------------------------------------

async def _resolve_discussion_chat(bot: Bot) -> int:
    """Группа обсуждений целевого канала — туда уходят комментарии."""
    global DISCUSSION_CHAT_ID
    if DISCUSSION_CHAT_ID:
        return DISCUSSION_CHAT_ID
    try:
        chat = await bot.get_chat(TARGET_CHAT_ID)
        DISCUSSION_CHAT_ID = getattr(chat, "linked_chat_id", None) or 0
        if DISCUSSION_CHAT_ID:
            logger.info("[autopost] группа обсуждений целевого канала: %s", DISCUSSION_CHAT_ID)
        else:
            logger.warning("[autopost] у целевого канала нет группы обсуждений — промпт пойдёт в подпись")
    except Exception as e:
        logger.error("[autopost] не удалось определить группу обсуждений: %s", e)
    return DISCUSSION_CHAT_ID


async def _comment_with_prompt(bot: Bot, channel_msg_id: int, prompt: str) -> bool:
    """Отправляет промпт комментарием под опубликованным постом.

    Чтобы ответить в группу обсуждений, нужен id поста уже внутри неё.
    Bot API такого соответствия не отдаёт, поэтому спрашиваем у Telethon;
    если он недоступен, ждём автопересылку, которую ловит обработчик."""
    discussion_chat = await _resolve_discussion_chat(bot)
    if not discussion_chat:
        return False

    discussion_msg_id = None
    try:
        import telethon_source

        discussion_msg_id = await telethon_source.get_discussion_message_id(
            TARGET_CHAT_ID, channel_msg_id
        )
    except Exception as e:
        logger.warning("[autopost] Telethon не подсказал id обсуждения: %s", e)

    if not discussion_msg_id:
        # Пост долетает до группы обсуждений не мгновенно
        deadline = time.time() + DISCUSSION_WAIT
        while time.time() < deadline and not discussion_msg_id:
            discussion_msg_id = _discussion_map.get(channel_msg_id)
            if not discussion_msg_id:
                await asyncio.sleep(1)

    if not discussion_msg_id:
        logger.warning(
            "[autopost] пост %s не найден в группе обсуждений — комментарий не отправлен",
            channel_msg_id,
        )
        return False

    try:
        await bot.send_message(
            chat_id=discussion_chat,
            text=build_comment(prompt),
            parse_mode="HTML",
            reply_to_message_id=discussion_msg_id,
        )
        logger.info("[autopost] промпт добавлен комментарием к посту %s", channel_msg_id)
        return True
    except Exception as e:
        logger.error("[autopost] не удалось отправить комментарий: %s", e)
        return False


async def publish(bot: Bot, row: sqlite3.Row, result_url: str) -> None:
    """Публикует картинку вместе с промптом: в подписи, если он туда влезает,
    иначе комментарием под постом. На два поста публикация не разбивается."""
    row_id = row["id"]
    prompt = (row["prompt"] or "").strip()
    header = build_caption(row["source_caption"])

    caption = build_caption_with_prompt(header, prompt)
    prompt_in_caption = caption != header

    async def _send(photo):
        return await bot.send_photo(
            chat_id=TARGET_CHAT_ID, photo=photo, caption=caption, parse_mode="HTML"
        )

    try:
        sent = await _send(URLInputFile(result_url))
    except Exception as e:
        # Резервный путь: телеграм иногда не может забрать картинку по ссылке
        logger.warning("[autopost] запись %s: send_photo по URL не прошёл (%s), пробуем файлом", row_id, e)
        try:
            MEDIA_DIR.mkdir(parents=True, exist_ok=True)
            dest = MEDIA_DIR / f"result_{row_id}.png"
            async with httpx.AsyncClient(timeout=180) as client:
                resp = await client.get(result_url)
                resp.raise_for_status()
                dest.write_bytes(resp.content)
            sent = await _send(FSInputFile(str(dest)))
        except Exception as e2:
            logger.error("[autopost] запись %s: публикация не удалась: %s", row_id, e2)
            _update(row_id, status="error", error=f"публикация: {e2}")
            return

    _update(row_id, status="published", result_url=result_url, published_at=_now(), error=None)
    logger.info("[autopost] запись %s опубликована в %s", row_id, TARGET_CHAT_ID)

    if prompt and not prompt_in_caption:
        posted = await _comment_with_prompt(bot, sent.message_id, prompt)
        if not posted:
            # Комментарий не ушёл — промпт всё равно должен быть виден,
            # поэтому дописываем его в подпись, обрезав до лимита.
            await _append_prompt_to_caption(bot, sent.message_id, header, prompt)


async def _append_prompt_to_caption(bot: Bot, message_id: int, header: str, prompt: str) -> None:
    room = CAPTION_LIMIT - _visible_len(header) - 20
    if room < 100:
        logger.error("[autopost] промпт не поместился в подпись и комментарий не ушёл")
        return
    text = prompt if len(prompt) <= room else prompt[: room - 3] + "..."
    try:
        await bot.edit_message_caption(
            chat_id=TARGET_CHAT_ID,
            message_id=message_id,
            caption=f"{header}\n{build_comment(text)}",
            parse_mode="HTML",
        )
        logger.info("[autopost] промпт добавлен в подпись поста %s", message_id)
    except Exception as e:
        logger.error("[autopost] не удалось дописать промпт в подпись: %s", e)


def _extract_result_url(task_data: dict) -> Optional[str]:
    """Достаёт ссылку на готовое изображение из ответа Kie AI."""
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


# --- Резервный путь: результат опросом, если callback не пришёл ----------------

async def _poll_stuck_tasks(bot: Bot) -> None:
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
            logger.error("[autopost] запись %s: опрос статуса не удался: %s", row["id"], e)
            continue

        state = (task_data.get("state") or task_data.get("status") or "").lower()
        if state in ("success", "succeeded", "completed"):
            result_url = _extract_result_url(task_data)
            if result_url:
                logger.info("[autopost] запись %s: результат получен опросом", row["id"])
                await publish(bot, row, result_url)
            else:
                _update(row["id"], status="error", error="Kie вернул успех без ссылки")
        elif state in ("fail", "failed", "error"):
            _update(row["id"], status="error",
                    error=task_data.get("failMsg") or task_data.get("msg") or "генерация не удалась")
            logger.error("[autopost] запись %s: генерация не удалась", row["id"])


# --- Фоновый воркер: очередь и суточный лимит ---------------------------------

async def autopost_worker(bot: Bot) -> None:
    if not ENABLED:
        logger.info("[autopost] выключен (AUTOPOST_ENABLED=0)")
        return
    if not SOURCE_CHAT_IDS or not TARGET_CHAT_ID:
        logger.error("[autopost] не заданы каналы-источники или целевой канал — модуль не работает")
        return

    init_db()
    logger.info(
        "[autopost] запущен: источников=%s, по %s поста с канала, лимит %s постов/сутки "
        "(публикация раз в %.0f мин), цель=%s, референс=%s",
        len(SOURCE_CHAT_IDS), PER_CHANNEL_DAILY, DAILY_LIMIT,
        publish_interval() / 60 if SPREAD_OVER_DAY else 0,
        TARGET_CHAT_ID, REFERENCE_IMAGE,
    )
    await _resolve_discussion_chat(bot)

    while True:
        try:
            available = DAILY_LIMIT - _sent_today()
            if available > 0:
                # Равномерная выдача: не чаще одной публикации за publish_interval
                batch = available
                if SPREAD_OVER_DAY:
                    elapsed = _seconds_since_last_send()
                    interval = publish_interval()
                    batch = 1 if elapsed is None or elapsed >= interval else 0
                    if batch == 0:
                        logger.debug(
                            "[autopost] до следующей публикации %.0f мин",
                            (interval - elapsed) / 60,
                        )

                if batch:
                    with closing(_connect()) as conn:
                        rows = conn.execute(
                            "SELECT * FROM autopost_posts WHERE status = 'ready' ORDER BY id LIMIT ?",
                            (batch,),
                        ).fetchall()
                    for row in rows:
                        await submit_to_kie(row)

            await _poll_stuck_tasks(bot)
        except Exception as e:
            logger.error("[autopost] ошибка воркера: %s", e, exc_info=True)

        await asyncio.sleep(WORKER_INTERVAL)


# --- Обработчики Telegram -----------------------------------------------------

def setup_autopost(dp: Dispatcher, bot: Bot) -> None:
    """Регистрирует обработчики: автопересылки в группе обсуждений и, если бот
    администратор в канале-источнике, посты этого канала в реальном времени."""
    if not ENABLED:
        return
    if not SOURCE_CHAT_IDS or not TARGET_CHAT_ID:
        logger.error("[autopost] не заданы ID чатов — обработчики не зарегистрированы")
        return

    init_db()

    @dp.message(F.is_automatic_forward.is_(True))
    async def on_auto_forward(message: Message):
        """Пост целевого канала долетел до группы обсуждений — запоминаем id,
        чтобы ответить на него комментарием с промптом."""
        origin = message.forward_from_chat
        if not origin or origin.id != TARGET_CHAT_ID:
            return
        if message.forward_from_message_id:
            _discussion_map[message.forward_from_message_id] = message.message_id

    @dp.channel_post(F.chat.id.in_(set(SOURCE_CHAT_IDS)), F.photo)
    async def on_source_post(message: Message):
        """Новый пост в канале-источнике, где бот администратор.
        Промпт придёт комментарием — пост подхватит telethon_source или
        обработчик комментариев ниже."""
        if get_post(message.chat.id, message.message_id):
            return
        logger.info(
            "[autopost] новый пост %s в канале %s, ждём промпт в комментариях",
            message.message_id, message.chat.id,
        )

    logger.info("[autopost] обработчики зарегистрированы, источников: %s", len(SOURCE_CHAT_IDS))


def setup_autopost_routes(app, bot: Bot) -> None:
    """HTTP-маршруты: отдача картинок для Kie AI и приём результата."""
    if not ENABLED:
        return

    @app.get("/autopost/reference.jpg")
    async def autopost_reference():
        if not REFERENCE_IMAGE.exists():
            return JSONResponse({"error": "reference image not found"}, status_code=404)
        return FileResponse(str(REFERENCE_IMAGE), media_type="image/jpeg")

    @app.get("/autopost/media/{filename}")
    async def autopost_media(filename: str):
        path = MEDIA_DIR / Path(filename).name  # только имя файла, без выхода из каталога
        if not path.exists():
            return JSONResponse({"error": "not found"}, status_code=404)
        return FileResponse(str(path))

    @app.post(CALLBACK_PATH)
    async def autopost_callback(request: Request):
        data = await request.json()
        logger.info("[autopost] callback: %s", str(data)[:300])

        task_data = data.get("data") or {}
        task_id = task_data.get("taskId") or task_data.get("task_id")
        if not task_id:
            return {"status": "received"}

        row = _get_by_task(str(task_id))
        if not row or row["status"] == "published":
            return {"status": "received"}

        state = (task_data.get("state") or "").lower()
        if data.get("code") == 200 and state in ("success", ""):
            result_url = _extract_result_url(task_data)
            if result_url:
                await publish(bot, row, result_url)
                return {"status": "received"}

        error = data.get("msg") or f"state={state}"
        logger.error("[autopost] запись %s: генерация не удалась: %s", row["id"], error)
        _update(row["id"], status="error", error=str(error))
        return {"status": "received"}
