"""
Чтение каналов-источников через пользовательскую сессию Telethon.

Bot API не отдаёт содержимое каналов, где бот не администратор, поэтому посты
и комментарии забираются от имени обычного аккаунта. Найденные посты попадают
в общую очередь (autopost.db), дальше работает конвейер из autopost.py:
суточный лимит, генерация в Kie AI и публикация.

Правила отбора:
* берётся первое фото поста (у альбома — первая картинка);
* промпт берётся из комментариев к посту;
* комментария нет — пост пропускается, идём к более раннему.

Авторизация выполняется один раз скриптом deploy/telethon_login.py.
"""

import asyncio
import logging
import os
from pathlib import Path
from typing import Optional

import autopost
from autopost import add_post, channel_quota, free_slots, init_db, known_msg_ids

logger = logging.getLogger("autopost.telethon")


def _env_int(name: str, default: int) -> int:
    try:
        return int(os.getenv(name, str(default)))
    except (TypeError, ValueError):
        return default


ENABLED = os.getenv("TELETHON_ENABLED", "0").strip().lower() in ("1", "true", "yes", "on")
API_ID = _env_int("TELETHON_API_ID", 0)
API_HASH = os.getenv("TELETHON_API_HASH", "").strip()
SESSION_PATH = os.getenv("TELETHON_SESSION", "/opt/kie_ai_bot/telethon.session")

POLL_INTERVAL = _env_int("TELETHON_POLL_INTERVAL", 600)
# Как глубоко уходить в историю канала, если под свежими постами нет промпта
MAX_LOOKBACK = _env_int("TELETHON_MAX_LOOKBACK", 60)
# Комментарий короче этого считается болтовнёй, а не промптом
MIN_PROMPT_LEN = _env_int("TELETHON_MIN_PROMPT_LEN", 40)
COMMENTS_LIMIT = _env_int("TELETHON_COMMENTS_LIMIT", 30)


_client = None
_client_lock = asyncio.Lock()


async def get_client():
    """Общий клиент на весь процесс: файл сессии нельзя открывать дважды."""
    global _client
    async with _client_lock:
        if _client is not None and _client.is_connected():
            return _client
        _client = await make_client()
        return _client


async def get_discussion_message_id(channel_id: int, message_id: int):
    """id поста внутри связанной группы обсуждений — по нему бот отвечает
    комментарием. Через Bot API это не узнать, а Telethon отдаёт напрямую."""
    client = await get_client()
    if not client:
        return None
    try:
        from telethon.tl.functions.messages import GetDiscussionMessageRequest

        result = await client(GetDiscussionMessageRequest(
            peer=await client.get_entity(channel_id), msg_id=message_id
        ))
        messages = getattr(result, "messages", None) or []
        return messages[0].id if messages else None
    except Exception as e:
        logger.warning(
            "[telethon] не удалось найти пост %s в группе обсуждений: %s", message_id, e
        )
        return None


async def make_client():
    """Готовый к работе клиент или None, если модуль не настроен."""
    if not API_ID or not API_HASH:
        logger.error("[telethon] не заданы TELETHON_API_ID / TELETHON_API_HASH")
        return None
    if not Path(SESSION_PATH).exists():
        logger.error(
            "[telethon] нет файла сессии %s — выполните: venv/bin/python deploy/telethon_login.py",
            SESSION_PATH,
        )
        return None
    try:
        from telethon import TelegramClient
    except ImportError:
        logger.error("[telethon] не установлен пакет telethon (pip install telethon)")
        return None

    client = TelegramClient(SESSION_PATH, API_ID, API_HASH)
    await client.start()  # сессия уже есть, интерактивного ввода не будет
    return client


async def pick_prompt(client, channel, message) -> str:
    """Промпт из комментариев к посту. Пустая строка — комментариев нет."""
    candidates = []
    try:
        async for comment in client.iter_messages(channel, reply_to=message.id, limit=COMMENTS_LIMIT):
            text = (comment.message or "").strip()
            if text:
                candidates.append(text)
    except Exception as e:
        # У поста может не быть обсуждения вовсе — это не ошибка
        logger.debug("[telethon] пост %s: комментарии недоступны: %s", message.id, e)
        return ""

    long_ones = [t for t in candidates if len(t) >= MIN_PROMPT_LEN]
    if long_ones:
        return max(long_ones, key=len)
    return max(candidates, key=len) if candidates else ""


async def find_posts(client, source_chat_id: int, needed: int = 1, skip_known: bool = True) -> list:
    """Идёт по постам канала от новых к старым и возвращает до needed штук,
    у которых есть фото и промпт в комментариях. Пост без комментария
    пропускается — смотрим более ранний."""
    from telethon.tl.types import MessageMediaPhoto

    channel = await client.get_entity(source_chat_id)
    known = known_msg_ids(source_chat_id) if skip_known else set()

    found = []
    seen_albums = set()
    scanned = 0

    async for message in client.iter_messages(channel, limit=MAX_LOOKBACK):
        scanned += 1
        if message.id in known:
            continue
        if not isinstance(message.media, MessageMediaPhoto):
            continue
        # У альбома берём только первую картинку
        if message.grouped_id:
            if message.grouped_id in seen_albums:
                continue
            seen_albums.add(message.grouped_id)

        prompt = await pick_prompt(client, channel, message)
        if not prompt:
            logger.info(
                "[telethon] канал %s, пост %s: промпта в комментариях нет — пропускаем",
                source_chat_id, message.id,
            )
            continue

        # Само фото поста не скачиваем: в Kie AI уходит только референсное фото
        found.append({
            "source_chat_id": source_chat_id,
            "source_msg_id": message.id,
            "prompt": prompt,
            "source_caption": message.message or "",
        })
        logger.info(
            "[telethon] канал %s, пост %s взят (промпт: %s символов)",
            source_chat_id, message.id, len(prompt),
        )
        if len(found) >= needed:
            break

    if not found:
        logger.info(
            "[telethon] канал %s: подходящих постов нет, просмотрено %s",
            source_chat_id, scanned,
        )
    return found


async def scan_all(client) -> int:
    """Обход всех каналов-источников. С каждого берём не больше
    AUTOPOST_PER_CHANNEL_DAILY новых постов за сутки; уже обработанные посты
    пропускаются — они есть в базе."""
    added = 0
    for source_chat_id in autopost.SOURCE_CHAT_IDS:
        quota = channel_quota(source_chat_id)
        if quota <= 0:
            logger.debug("[telethon] канал %s: суточная норма выбрана", source_chat_id)
            continue
        if free_slots() <= 0:
            logger.debug("[telethon] очередь заполнена, обход остановлен")
            break

        try:
            posts = await find_posts(client, source_chat_id, needed=min(quota, free_slots()))
        except Exception as e:
            logger.error("[telethon] канал %s: обход не удался: %s", source_chat_id, e)
            continue

        for post in posts:
            if add_post(**post):
                added += 1
    return added


async def telethon_worker() -> None:
    """Фоновый цикл: периодически забирает новые посты из каналов-источников."""
    if not ENABLED:
        return
    if not autopost.ENABLED:
        logger.warning("[telethon] AUTOPOST_ENABLED=0 — читать источники некуда, выключено")
        return

    client = await get_client()
    if not client:
        return

    init_db()
    me = await client.get_me()
    logger.info(
        "[telethon] запущен под аккаунтом %s, каналов-источников: %s, опрос каждые %s с",
        getattr(me, "username", None) or me.id, len(autopost.SOURCE_CHAT_IDS), POLL_INTERVAL,
    )

    while True:
        try:
            added = await scan_all(client)
            if added:
                logger.info("[telethon] новых постов в очереди: %s", added)
        except Exception as e:
            logger.error("[telethon] ошибка обхода: %s", e, exc_info=True)
        await asyncio.sleep(POLL_INTERVAL)
