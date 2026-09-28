"""
Антиспам в группе: удаление сообщений со ссылками от обычных участников.

Бот — администратор группы. Когда участник (не админ) пишет сообщение со
ссылкой — URL, t.me, приглашение или голый домен — бот удаляет это
сообщение. Так лента не забивается спамом после открытия публикаций.

Не трогаем: администраторов и владельца, автопересылки постов из
привязанного канала, сообщения самого бота. По желанию нарушителю уходит
короткое предупреждение (ANTISPAM_WARN).
"""

import logging
import os
import re
import time
from typing import Set

from aiogram import Bot, Dispatcher, F
from aiogram.types import Message

logger = logging.getLogger("antispam")


def _parse_ids(raw: str):
    ids = []
    for chunk in re.split(r"[,\s]+", raw or ""):
        chunk = chunk.strip()
        if not chunk:
            continue
        try:
            ids.append(int(chunk))
        except ValueError:
            continue
    return ids


ENABLED = os.getenv("ANTISPAM_ENABLED", "1").strip().lower() in ("1", "true", "yes", "on")
# Группы, где работает антиспам. По умолчанию — группа «Нейросети»
CHAT_IDS = set(_parse_ids(os.getenv("ANTISPAM_CHAT_IDS", "-1001711115341")))
# Короткое предупреждение нарушителю (самоудаляется). Пусто — молча удалять
WARN_TEXT = os.getenv("ANTISPAM_WARN", "🚫 Ссылки в группе запрещены — сообщение удалено.")
# Через сколько секунд убрать предупреждение
WARN_TTL = int(os.getenv("ANTISPAM_WARN_TTL", "8") or 8)

# Ссылки: тег-сущности ловятся отдельно, здесь — голые адреса в тексте
_LINK_RE = re.compile(
    r"(https?://|tg://|www\.)\S+"
    r"|t\.me/\S+"
    r"|@[A-Za-z0-9_]{4,}"
    r"|(?<![\w@.])(?:[a-z0-9-]+\.)+(?:ru|com|org|net|io|ai|dev|me|info|biz|online|"
    r"site|xyz|shop|store|app|club|link|top|рф)(?:/\S*)?",
    re.I,
)

# Кэш админов по чатам: {chat_id: (set_admin_ids, время_обновления)}
_admin_cache: dict = {}
_ADMIN_TTL = 300


def _has_link_entities(message: Message) -> bool:
    for entities in (message.entities, message.caption_entities):
        for ent in (entities or []):
            if ent.type in ("url", "text_link", "mention"):
                return True
    return False


def message_has_link(message: Message) -> bool:
    if _has_link_entities(message):
        return True
    text = message.text or message.caption or ""
    return bool(_LINK_RE.search(text))


async def _admin_ids(bot: Bot, chat_id: int) -> Set[int]:
    cached = _admin_cache.get(chat_id)
    if cached and (time.time() - cached[1] < _ADMIN_TTL):
        return cached[0]
    try:
        admins = await bot.get_chat_administrators(chat_id)
        ids = {a.user.id for a in admins if a.user}
        _admin_cache[chat_id] = (ids, time.time())
        return ids
    except Exception as e:
        logger.warning("[антиспам] не получить список админов %s: %s", chat_id, e)
        return cached[0] if cached else set()


def setup(dp: Dispatcher, bot: Bot) -> None:
    """Регистрирует антиспам-обработчик."""
    if not ENABLED or not CHAT_IDS:
        logger.info("[антиспам] выключен (ANTISPAM_ENABLED=%s, чатов=%s)",
                    ENABLED, len(CHAT_IDS))
        return

    async def _is_spam(message: Message) -> bool:
        """Фильтр: сообщение подлежит удалению. Обработчик срабатывает только
        тогда — обычные сообщения проходят дальше к своим обработчикам."""
        if message.is_automatic_forward:          # пост из привязанного канала
            return False
        user = message.from_user
        if not user or user.is_bot:               # анонимный админ / канал / бот
            return False
        if not message_has_link(message):
            return False
        if user.id in await _admin_ids(bot, message.chat.id):
            return False
        return True

    @dp.message(F.chat.id.in_(CHAT_IDS), _is_spam)
    async def delete_spam(message: Message):
        user = message.from_user
        try:
            await bot.delete_message(message.chat.id, message.message_id)
            logger.info("[антиспам] удалено сообщение со ссылкой от %s (@%s) в %s",
                        user.id, user.username, message.chat.id)
        except Exception as e:
            logger.warning("[антиспам] не удалить сообщение %s в %s: %s",
                           message.message_id, message.chat.id, e)
            return

        if WARN_TEXT:
            try:
                warn = await bot.send_message(message.chat.id, WARN_TEXT)
                if WARN_TTL > 0:
                    import asyncio

                    async def _cleanup(mid: int):
                        await asyncio.sleep(WARN_TTL)
                        try:
                            await bot.delete_message(message.chat.id, mid)
                        except Exception:
                            pass

                    asyncio.create_task(_cleanup(warn.message_id))
            except Exception:
                pass

    logger.info("[антиспам] включён для чатов: %s", CHAT_IDS)
