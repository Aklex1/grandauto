"""
Чтение чужого канала-источника через пользовательскую сессию Telethon.

Bot API не отдаёт боту содержимое каналов, где он не администратор, поэтому
посты и комментарии забираются от имени обычного аккаунта. Найденные посты
складываются в ту же очередь (autopost.db), что и в режиме Bot API, — дальше
работает общий конвейер: суточный лимит, генерация в Kie AI и публикация.

Авторизация выполняется один раз скриптом deploy/telethon_login.py,
после чего на диске лежит файл сессии и вход больше не требуется.
"""

import asyncio
import logging
import os
from contextlib import closing
from pathlib import Path
from typing import Optional

import autopost
from autopost import MEDIA_DIR, _connect, _now, init_db

logger = logging.getLogger("autopost.telethon")


def _env_int(name: str, default: int) -> int:
    try:
        return int(os.getenv(name, str(default)))
    except (TypeError, ValueError):
        return default


ENABLED = os.getenv("TELETHON_ENABLED", "0").strip() in ("1", "true", "yes", "on")
API_ID = _env_int("TELETHON_API_ID", 0)
API_HASH = os.getenv("TELETHON_API_HASH", "").strip()
SESSION_PATH = os.getenv("TELETHON_SESSION", "/opt/kie_ai_bot/telethon.session")

# Как часто проверять канал на новые посты, секунды
POLL_INTERVAL = _env_int("TELETHON_POLL_INTERVAL", 600)
# Сколько последних постов посмотреть при самом первом запуске
BACKFILL_LIMIT = _env_int("TELETHON_BACKFILL_LIMIT", 3)
# Сколько постов просматривать за один проход
SCAN_LIMIT = _env_int("TELETHON_SCAN_LIMIT", 20)
# Комментарий короче этого считается болтовнёй, а не промптом
MIN_PROMPT_LEN = _env_int("TELETHON_MIN_PROMPT_LEN", 40)
# Сколько комментариев просматривать под постом
COMMENTS_LIMIT = _env_int("TELETHON_COMMENTS_LIMIT", 30)


def _known_ids() -> set:
    with closing(_connect()) as conn:
        return {r["source_msg_id"] for r in conn.execute("SELECT source_msg_id FROM autopost_posts")}


def _max_known_id() -> int:
    with closing(_connect()) as conn:
        row = conn.execute("SELECT MAX(source_msg_id) AS m FROM autopost_posts").fetchone()
        return int(row["m"] or 0)


def _store(source_msg_id: int, photo_path: str, prompt: str, source_caption: str) -> None:
    status = "ready" if prompt else "waiting_prompt"
    with closing(_connect()) as conn:
        conn.execute(
            "INSERT OR IGNORE INTO autopost_posts "
            "(source_msg_id, photo_path, prompt, source_caption, status, created_at) "
            "VALUES (?, ?, ?, ?, ?, ?)",
            (source_msg_id, photo_path, prompt or None, source_caption or "", status, _now()),
        )
        conn.commit()


async def _pick_prompt(client, channel, message) -> str:
    """Промпт из комментариев к посту; если их нет — из подписи самого поста."""
    candidates = []
    try:
        async for comment in client.iter_messages(channel, reply_to=message.id, limit=COMMENTS_LIMIT):
            text = (comment.message or "").strip()
            if text:
                candidates.append(text)
    except Exception as e:
        # У поста может не быть обсуждения вовсе — это не ошибка
        logger.debug("[telethon] пост %s: комментарии недоступны: %s", message.id, e)

    # Промпт — обычно самый длинный осмысленный комментарий
    long_ones = [t for t in candidates if len(t) >= MIN_PROMPT_LEN]
    if long_ones:
        return max(long_ones, key=len)
    if candidates:
        return max(candidates, key=len)
    return (message.message or "").strip()


async def _scan_once(client) -> int:
    from telethon.tl.types import MessageMediaPhoto

    channel = await client.get_entity(autopost.SOURCE_CHAT_ID)
    known = _known_ids()
    first_run = not known
    limit = BACKFILL_LIMIT if first_run else SCAN_LIMIT
    min_id = 0 if first_run else _max_known_id()

    added = 0
    async for message in client.iter_messages(channel, limit=limit):
        if message.id in known or message.id <= min_id:
            continue
        if not isinstance(message.media, MessageMediaPhoto):
            continue

        prompt = await _pick_prompt(client, channel, message)
        MEDIA_DIR.mkdir(parents=True, exist_ok=True)
        photo_path = await message.download_media(file=str(MEDIA_DIR / f"post_{message.id}.jpg"))
        if not photo_path:
            logger.warning("[telethon] пост %s: фото не скачалось", message.id)
            continue

        _store(message.id, str(photo_path), prompt, message.message or "")
        added += 1
        logger.info(
            "[telethon] пост %s добавлен в очередь (промпт: %s символов)",
            message.id, len(prompt),
        )

    return added


async def telethon_worker() -> None:
    """Фоновый цикл: периодически забирает новые посты из канала-источника."""
    if not ENABLED:
        return
    if not autopost.ENABLED:
        logger.warning("[telethon] AUTOPOST_ENABLED=0 — источник читать некуда, выключено")
        return
    if not API_ID or not API_HASH:
        logger.error("[telethon] не заданы TELETHON_API_ID / TELETHON_API_HASH")
        return
    if not Path(SESSION_PATH).exists():
        logger.error(
            "[telethon] нет файла сессии %s — выполните: "
            "python deploy/telethon_login.py", SESSION_PATH,
        )
        return

    try:
        from telethon import TelegramClient
    except ImportError:
        logger.error("[telethon] не установлен пакет telethon (pip install telethon)")
        return

    init_db()
    client = TelegramClient(SESSION_PATH, API_ID, API_HASH)
    await client.start()  # сессия уже есть, интерактивного ввода не будет
    me = await client.get_me()
    logger.info(
        "[telethon] запущен под аккаунтом %s, источник=%s, опрос каждые %s с",
        getattr(me, "username", None) or me.id, autopost.SOURCE_CHAT_ID, POLL_INTERVAL,
    )

    while True:
        try:
            added = await _scan_once(client)
            if added:
                logger.info("[telethon] новых постов: %s", added)
        except Exception as e:
            logger.error("[telethon] ошибка обхода канала: %s", e, exc_info=True)
        await asyncio.sleep(POLL_INTERVAL)
