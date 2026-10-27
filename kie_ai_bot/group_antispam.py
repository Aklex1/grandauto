"""
Антиспам в группе: удаление ссылок, пересылок и объявлений о заработке.

Бот — администратор группы. Сообщение обычного участника удаляется, если
в нём есть одно из трёх:

  ссылки   — URL, t.me, приглашение, упоминание или голый домен;
  пересылка — любое сообщение, переправленное из другого чата или канала;
  слова    — реклама VPN и прокси, объявления о заработке и наборе людей.

Не трогаем: администраторов и владельца, автопересылки постов из
привязанного канала, сообщения самого бота.

Удаляем молча. Служебное «ссылки запрещены» в ответ на каждый спам —
второе уведомление за то же самое: нарушитель и так видит, что его
сообщение исчезло, а остальным участникам приходит пустой звон.

Правки сообщений смотрим тоже: чистое сообщение, дописанное рекламой
через минуту, иначе остаётся в группе навсегда.

Список запрещённых слов расширяется через ANTISPAM_WORDS, без правки
кода: несколько подстрок через запятую. ANTISPAM_DEBUG=1 пишет в журнал
разбор каждого сообщения группы — чтобы понять, почему пропустили.
"""

import logging
import os
import re
import time
from typing import Optional, Set

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


def _flag(name: str, default: str = "1") -> bool:
    return os.getenv(name, default).strip().lower() in ("1", "true", "yes", "on")


ENABLED = _flag("ANTISPAM_ENABLED")
# Группы, где работает антиспам. По умолчанию — группа «Нейросети»
CHAT_IDS = set(_parse_ids(os.getenv("ANTISPAM_CHAT_IDS", "-1001711115341")))
# Каждое правило можно выключить отдельно
LINKS_ON = _flag("ANTISPAM_LINKS")
FORWARDS_ON = _flag("ANTISPAM_FORWARDS")
WORDS_ON = _flag("ANTISPAM_WORDS_ON")
# Разбор каждого сообщения в журнал: включается, когда что-то пролетело
# мимо и надо понять, на каком шаге
DEBUG_ON = _flag("ANTISPAM_DEBUG", "0")

# Ссылки: тег-сущности ловятся отдельно, здесь — голые адреса в тексте
_LINK_RE = re.compile(
    r"(https?://|tg://|www\.)\S+"
    r"|t\.me/\S+"
    r"|@[A-Za-z0-9_]{4,}"
    r"|(?<![\w@.])(?:[a-z0-9-]+\.)+(?:ru|com|org|net|io|ai|dev|me|info|biz|online|"
    r"site|xyz|shop|store|app|club|link|top|рф)(?:/\S*)?",
    re.I,
)

# Запрещённые слова.
#
# Отдельно стоящее «работа» намеренно не ловим: в группе про нейросети это
# обычное слово — «как работает модель», «не работает кнопка». Ловим
# связки, по которым узнаётся объявление, а не разговор.
_WORD_PATTERNS = [
    # VPN и обход блокировок
    r"\bvpn\b", r"\bвпн\b", r"\bprox(?:y|ies)\b", r"\bпрокси\b",
    r"\bvless\b", r"\bshadowsocks\b", r"\boutline\b.{0,20}\bключ",
    # Заработок
    r"заработ(?:ок|ка|ке|ать|аешь|аем)", r"подработк", r"пассивн\w*\s+доход",
    r"доход\w*\s+(?:от|до)\s*\d", r"от\s*\d[\d\s]*(?:000|к|тыс)\w*\s*(?:руб|₽|\$|€)?"
    r"\s*(?:в|за)\s*(?:день|сутки|недел|месяц)",
    r"\bденьги\s+(?:каждый|за)\s+день", r"\bвывод\s+(?:средств|денег)\b",
    # Набор людей
    r"ищу\s+(?:люд|человек|сотрудник|партн|помощник)",
    r"нужны\s+(?:люди|сотрудник|помощник)", r"требуются\s+(?:люди|сотрудник)",
    r"набира\w+\s+(?:команд|людей)", r"набор\s+(?:в\s+команду|людей)",
    r"нужны\s+права\b",
    # Работа — только в связках, по которым узнаётся объявление
    r"работа\s+(?:на\s+дому|в\s+интернете|удал[её]нн\w*|без\s+опыта|от\s*\d)",
    r"(?:ищу|предлагаю|есть)\s+работ[уа]\b", r"удал[её]нн\w*\s+работ",
    r"ваканси", r"\bподработ",
    # Частые спутники таких объявлений
    r"\bказино\b", r"ставки\s+на\s+спорт", r"крипто\w*\s*(?:обмен|кошел)",
    r"пиши(?:те)?\s+в\s+(?:лс|личк)",
]
_EXTRA_WORDS = [w.strip() for w in re.split(r"[,\n]+", os.getenv("ANTISPAM_WORDS", "")) if w.strip()]
_WORDS_RE = re.compile(
    "|".join(_WORD_PATTERNS + [re.escape(w) for w in _EXTRA_WORDS]),
    re.I | re.U,
)

# Кэш админов по чатам: {chat_id: (set_admin_ids, время_обновления)}
_admin_cache: dict = {}
_ADMIN_TTL = 300


def _text_of(message: Message) -> str:
    return message.text or message.caption or ""


def _has_link_entities(message: Message) -> bool:
    for entities in (message.entities, message.caption_entities):
        for ent in (entities or []):
            if ent.type in ("url", "text_link", "mention"):
                return True
    return False


def message_has_link(message: Message) -> bool:
    if _has_link_entities(message):
        return True
    return bool(_LINK_RE.search(_text_of(message)))


def message_is_forward(message: Message) -> bool:
    """Пересылка из другого чата, канала или от человека.

    В новых версиях Bot API это forward_origin, в старых — отдельные поля.
    Проверяем всё, что есть: библиотека может быть любой версии.
    """
    if message.is_automatic_forward:   # пост из привязанного канала — не спам
        return False
    for attr in ("forward_origin", "forward_from", "forward_from_chat",
                 "forward_sender_name", "forward_date"):
        if getattr(message, attr, None):
            return True
    return False


def message_has_banned_words(message: Message) -> bool:
    text = _text_of(message)
    return bool(text) and bool(_WORDS_RE.search(text))


def spam_reason(message: Message) -> Optional[str]:
    """Почему сообщение подлежит удалению. None — сообщение в порядке."""
    if FORWARDS_ON and message_is_forward(message):
        return "пересылка"
    if LINKS_ON and message_has_link(message):
        return "ссылка"
    if WORDS_ON and message_has_banned_words(message):
        return "слова"
    return None


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

    async def _is_spam(message: Message):
        """Фильтр: сообщение подлежит удалению. Обработчик срабатывает только
        тогда — обычные сообщения проходят дальше к своим обработчикам.

        Причину возвращаем словарём: aiogram передаст её обработчику
        отдельным доводом, и считать второй раз не придётся."""
        if message.is_automatic_forward:          # пост из привязанного канала
            return False

        # Сообщение «от имени канала»: отправителем Telegram подставляет
        # служебного бота, и по старой проверке такое пролетало мимо.
        # Анонимный админ пишет от имени самой группы — его не трогаем,
        # а чужой канал в группе это ровно тот спам, ради которого всё.
        sender_chat = getattr(message, "sender_chat", None)
        from_channel = bool(sender_chat) and sender_chat.id != message.chat.id
        from_group = bool(sender_chat) and sender_chat.id == message.chat.id

        user = message.from_user
        # Свои сообщения не трогаем, чужих ботов — трогаем: реклама
        # приходит и от них, а раньше любой бот был неприкасаем.
        own = bool(user) and user.id == bot.id
        reason = spam_reason(message)

        if DEBUG_ON:
            logger.info(
                "[антиспам] разбор: чат=%s от=%s бот=%s канал=%s группа=%s "
                "пересылка=%s ссылка=%s слова=%s причина=%s текст=%.60r",
                message.chat.id,
                getattr(user, "id", None), getattr(user, "is_bot", None),
                getattr(sender_chat, "id", None) if from_channel else None,
                from_group, message_is_forward(message), message_has_link(message),
                message_has_banned_words(message), reason, _text_of(message),
            )

        if own or from_group:
            return False
        if not from_channel and not user:
            return False
        if not reason:
            return False
        if from_channel:
            return {"reason": reason}
        if user.id in await _admin_ids(bot, message.chat.id):
            return False
        return {"reason": reason}

    async def delete_spam(message: Message, reason: str = "ссылка"):
        user = message.from_user
        sender_chat = getattr(message, "sender_chat", None)
        who = ("канал %s (@%s)" % (sender_chat.id, sender_chat.username)) if sender_chat \
            else ("%s (@%s)" % (user.id, user.username) if user else "неизвестно")
        try:
            await bot.delete_message(message.chat.id, message.message_id)
            logger.info("[антиспам] удалено (%s) от %s в %s", reason, who, message.chat.id)
        except Exception as e:
            logger.warning("[антиспам] не удалить сообщение %s в %s: %s",
                           message.message_id, message.chat.id, e)

    dp.message.register(delete_spam, F.chat.id.in_(CHAT_IDS), _is_spam)
    # Правку смотрим тем же фильтром: сообщение, дописанное рекламой после
    # отправки, обработчик обычных сообщений уже не увидит.
    dp.edited_message.register(delete_spam, F.chat.id.in_(CHAT_IDS), _is_spam)

    logger.info("[антиспам] включён для чатов: %s (ссылки=%s, пересылки=%s, слова=%s%s)",
                CHAT_IDS, LINKS_ON, FORWARDS_ON, WORDS_ON,
                ", своих слов: %d" % len(_EXTRA_WORDS) if _EXTRA_WORDS else "")
