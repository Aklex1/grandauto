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
# Как глубоко уходить в историю, если под свежими постами промпта нет
MAX_LOOKBACK = _env_int("TELETHON_MAX_LOOKBACK", 60)
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


def _store(source_msg_id: int, photo_path: Optional[str], prompt: str, source_caption: str) -> None:
    status = "ready" if prompt else "waiting_prompt"
    with closing(_connect()) as conn:
        conn.execute(
            "INSERT OR IGNORE INTO autopost_posts "
            "(source_msg_id, photo_path, prompt, source_caption, status, created_at) "
            "VALUES (?, ?, ?, ?, ?, ?)",
            (source_msg_id, photo_path, prompt or None, source_caption or "", status, _now()),
        )
        conn.commit()


async def _download_photo(message) -> Optional[str]:
    MEDIA_DIR.mkdir(parents=True, exist_ok=True)
    path = await message.download_media(file=str(MEDIA_DIR / f"post_{message.id}.jpg"))
    if not path:
        logger.warning("[telethon] пост %s: фото не скачалось", message.id)
        return None
    return str(path)


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


def _needed_count() -> int:
    """Сколько постов с промптом имеет смысл найти за этот проход."""
    free = autopost.DAILY_LIMIT - autopost._published_today()
    with closing(_connect()) as conn:
        ready = conn.execute(
            "SELECT COUNT(*) AS n FROM autopost_posts WHERE status = 'ready'"
        ).fetchone()["n"]
    return max(0, free - int(ready))


async def _recheck_waiting(client, channel) -> int:
    """Посты, у которых промпта ещё не было: комментарий мог появиться позже."""
    with closing(_connect()) as conn:
        rows = conn.execute(
            "SELECT source_msg_id FROM autopost_posts WHERE status = 'waiting_prompt'"
        ).fetchall()

    found = 0
    for row in rows:
        try:
            message = await client.get_messages(channel, ids=row["source_msg_id"])
        except Exception:
            continue
        if not message:
            continue
        prompt = await _pick_prompt(client, channel, message)
        if prompt:
            photo_path = await _download_photo(message)
            if not photo_path:
                continue
            with closing(_connect()) as conn:
                conn.execute(
                    "UPDATE autopost_posts SET prompt = ?, photo_path = ?, status = 'ready' "
                    "WHERE source_msg_id = ?",
                    (prompt, photo_path, row["source_msg_id"]),
                )
                conn.commit()
            found += 1
            logger.info("[telethon] пост %s: промпт появился в комментариях", row["source_msg_id"])
    return found


async def _scan_once(client) -> int:
    """Идёт по постам от новых к старым, пока не наберёт нужное число постов
    с промптом. Пост без промпта не останавливает обход — переходим к более
    раннему; такие посты остаются в очереди и перепроверяются позже."""
    from telethon.tl.types import MessageMediaPhoto

    channel = await client.get_entity(autopost.SOURCE_CHAT_ID)

    found = await _recheck_waiting(client, channel)
    needed = _needed_count() - found
    if needed <= 0:
        return found

    known = _known_ids()
    if not known:
        # На самом первом запуске не набираем больше, чем backfill
        needed = min(needed, BACKFILL_LIMIT)
    limit = MAX_LOOKBACK

    seen = 0
    async for message in client.iter_messages(channel, limit=limit):
        seen += 1
        if message.id in known:
            continue
        if not isinstance(message.media, MessageMediaPhoto):
            continue

        prompt = await _pick_prompt(client, channel, message)

        photo_path = None
        if prompt:
            photo_path = await _download_photo(message)
            if not photo_path:
                continue

        _store(message.id, photo_path, prompt, message.message or "")

        if prompt:
            found += 1
            logger.info(
                "[telethon] пост %s взят в работу (промпт: %s символов)",
                message.id, len(prompt),
            )
            if found >= needed:
                break
        else:
            logger.info(
                "[telethon] пост %s: промпта в комментариях нет, смотрим более ранний",
                message.id,
            )

    if not found:
        logger.info("[telethon] промптов не найдено, просмотрено постов: %s", seen)
    return found


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
