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
import re
import tempfile
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

# Слова, после которых идёт сам промпт: «Промпт: ...», «промт — ...», «prompt»
PROMPT_MARKERS = [
    w.strip() for w in os.getenv("AUTOPOST_PROMPT_MARKERS", "промпт,промт,prompt").split(",")
    if w.strip()
]
# Обороты, с которых промпт обычно начинается — текст берётся вместе с ними
PROMPT_START_HINTS = [
    w.strip() for w in os.getenv(
        "AUTOPOST_PROMPT_HINTS",
        "сохрани внешность,создай изображение,создай фото,фотореалистич,фотореализм",
    ).split(",") if w.strip()
]
# Подпись без маркера считается промптом только начиная с такой длины
CAPTION_MIN_LEN = _env_int("AUTOPOST_CAPTION_MIN_LEN", 200)

# Слова, по которым пост считается рекламным и в работу не берётся
AD_STOP_WORDS = [
    w.strip().lower() for w in os.getenv(
        "AUTOPOST_AD_STOP_WORDS",
        # обучение и инфопродукты
        "курс,курсы,обучени,обуча,наставнич,вебинар,марафон,интенсив,мастер-класс,"
        "мастеркласс,воркшоп,тренинг,запишись,записывайся,запись на,мест осталось,"
        "успей купить,покупк,оплати,оплата,стоимость,скидк,промокод,тариф,"
        "рассрочк,ученик,ученики,разбор за,приходи учиться,продаю,продажа,"
        "бесплатный вебинар,бесплатный урок,гайд за,подписка за,"
        # прямая реклама и продажа услуг
        "реклам,advertis,по вопросам рекламы,для заказов,orders,прайс,услуги,"
        "продвижен,накрутк,трафик,сотрудничеств,vpn,впн,telegram premium,"
        "тг премиум,подписка от,в наличии,заказать,на заказ,пиши в лс,писать в лс",
    ).split(",") if w.strip()
]
# Пропускать посты, если на исходном фото есть текст (обычно это реклама)
SKIP_IMAGES_WITH_TEXT = os.getenv(
    "AUTOPOST_SKIP_IMAGES_WITH_TEXT", "1"
).strip().lower() in ("1", "true", "yes", "on")
# Сколько распознанных букв на картинке уже считается текстом.
# Любая надпись поверх фото — признак рекламного креатива, поэтому порог низкий;
# пара символов оставлена, чтобы шум распознавания не отсекал чистые фото.
IMAGE_TEXT_MIN_CHARS = _env_int("AUTOPOST_IMAGE_TEXT_MIN_CHARS", 4)
# Не брать посты, пока OCR не работает (иначе реклама с надписями пройдёт фильтр)
REQUIRE_OCR = os.getenv("AUTOPOST_REQUIRE_OCR", "0").strip().lower() in ("1", "true", "yes", "on")

_MARKER_RE = re.compile(
    r"(?:" + "|".join(re.escape(w) + r"\w*" for w in PROMPT_MARKERS) + r")\s*[:\-—–>»]*\s*",
    re.IGNORECASE,
) if PROMPT_MARKERS else None

_HINT_RE = re.compile(
    "|".join(re.escape(w) for w in PROMPT_START_HINTS), re.IGNORECASE
) if PROMPT_START_HINTS else None


def _trim_lead_in(text: str) -> str:
    """Убирает подводку перед промптом: «для вас 👇», стрелки, пустые строки."""
    text = text.strip(" \n\t:—–->»👇⤵️✨🔥")
    # Если сразу за подводкой идёт типичное начало промпта — режем по нему
    if _HINT_RE:
        m = _HINT_RE.search(text[:120])
        if m and m.start() > 0:
            return text[m.start():].strip()
    return text


def extract_prompt(text: str) -> str:
    """Достаёт промпт из текста.

    Сначала ищет слово-маркер («промпт», «промт», «prompt») и берёт всё, что
    идёт после него. Если маркера нет — ищет типичное начало промпта
    («Сохрани внешность...») и берёт текст с этого места."""
    text = (text or "").strip()
    if not text:
        return ""

    if _MARKER_RE:
        best = ""
        for m in _MARKER_RE.finditer(text):
            tail = text[m.end():].strip()
            if len(tail) > len(best):
                best = tail
        if len(best) >= MIN_PROMPT_LEN:
            return _trim_lead_in(best)

    if _HINT_RE:
        m = _HINT_RE.search(text)
        if m:
            tail = text[m.start():].strip()
            if len(tail) >= MIN_PROMPT_LEN:
                return tail

    return ""


_client = None
_client_lock = asyncio.Lock()


async def get_client():
    """Общий клиент на весь процесс: файл сессии нельзя открывать дважды,
    иначе SQLite отвечает «database is locked»."""
    global _client
    async with _client_lock:
        if _client is not None:
            if _client.is_connected():
                return _client
            # Клиент уже создан, но связь оборвалась — переподключаем его,
            # а не создаём второй поверх того же файла сессии
            try:
                await _client.connect()
                return _client
            except Exception as e:
                logger.warning("[telethon] переподключение не удалось: %s", e)
                try:
                    await _client.disconnect()
                except Exception:
                    pass
                _client = None

        _client = await make_client()
        return _client


async def get_discussion_message_id(channel_id: int, message_id: int, attempts: int = 3):
    """id поста внутри связанной группы обсуждений — по нему бот отвечает
    комментарием. Через Bot API это не узнать, а Telethon отдаёт напрямую.

    Обсуждение под свежим постом создаётся не сразу, поэтому запрос
    повторяется несколько раз с паузой."""
    client = await get_client()
    if not client:
        logger.warning("[telethon] клиент недоступен — id обсуждения не получить")
        return None

    from telethon.tl.functions.messages import GetDiscussionMessageRequest

    last_error = None
    for attempt in range(1, attempts + 1):
        try:
            result = await client(GetDiscussionMessageRequest(
                peer=await client.get_entity(channel_id), msg_id=message_id
            ))
            messages = getattr(result, "messages", None) or []
            if messages:
                return messages[0].id
            last_error = "обсуждение под постом ещё не создано"
        except Exception as e:
            last_error = e

        if attempt < attempts:
            await asyncio.sleep(3 * attempt)

    logger.warning(
        "[telethon] пост %s в группе обсуждений не найден за %s попыток: %s",
        message_id, attempts, last_error,
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


def looks_like_ad(*texts) -> str:
    """Возвращает найденное стоп-слово, если пост рекламный, иначе пустую строку.

    Отсеиваются призывы купить курс, записаться на обучение и подобное —
    такие посты в работу не берутся."""
    haystack = " ".join(t.lower() for t in texts if t)
    for word in AD_STOP_WORDS:
        if word and word in haystack:
            return word
    return ""


_ocr_checked = False
_ocr_ok = False


def _ocr_available() -> bool:
    """Готов ли OCR. Проверяется один раз, о неготовности сообщается в лог:
    без него посты с рекламным текстом на картинке пройдут фильтр."""
    global _ocr_checked, _ocr_ok
    if _ocr_checked:
        return _ocr_ok

    _ocr_checked = True
    try:
        import pytesseract
        from PIL import Image  # noqa: F401

        pytesseract.get_tesseract_version()
        _ocr_ok = True
        logger.info("[telethon] проверка текста на картинках включена")
    except Exception as e:
        _ocr_ok = False
        logger.error(
            "[telethon] ПРОВЕРКА ТЕКСТА НА КАРТИНКАХ НЕ РАБОТАЕТ (%s). "
            "Рекламные креативы с надписями будут проходить фильтр. "
            "Установите: apt-get install -y tesseract-ocr tesseract-ocr-rus "
            "&& venv/bin/pip install pytesseract", e,
        )
    return _ocr_ok


async def image_has_text(client, message) -> bool:
    """Есть ли на картинке заметный текст. Надписи поверх фото почти всегда
    означают рекламный креатив, а не пример работы промпта.

    Требует tesseract и pytesseract; без них проверка молча пропускается."""
    if not SKIP_IMAGES_WITH_TEXT:
        return False
    if not _ocr_available():
        # Без OCR надписи на картинке не видны. Строгий режим лучше пропустит
        # пост, чем опубликует чужую рекламу.
        return REQUIRE_OCR

    import pytesseract
    from PIL import Image

    tmp_dir = Path(tempfile.gettempdir())
    path = None
    try:
        path = await message.download_media(file=str(tmp_dir / f"ocr_{message.id}.jpg"))
        if not path:
            return False
        with Image.open(path) as image:
            text = pytesseract.image_to_string(image, lang="rus+eng")
        letters = re.sub(r"[^0-9A-Za-zА-Яа-яЁё]", "", text or "")
        if len(letters) >= IMAGE_TEXT_MIN_CHARS:
            logger.info(
                "[telethon] пост %s: на фото надпись (%s символов: %.40s) — пропускаем",
                message.id, len(letters), " ".join((text or "").split()),
            )
            return True
        return False
    except Exception as e:
        logger.debug("[telethon] пост %s: распознать текст на фото не вышло: %s", message.id, e)
        return False
    finally:
        if path:
            try:
                Path(path).unlink()
            except OSError:
                pass


async def pick_prompt(client, channel, message) -> str:
    """Промпт к посту: сначала из комментариев, затем из подписи самого поста.

    В обоих местах сперва ищется текст после слова-маркера («Промпт: ...»),
    и только если маркера нигде нет, берётся самый длинный осмысленный
    комментарий или достаточно длинная подпись."""
    comments = []
    try:
        async for comment in client.iter_messages(channel, reply_to=message.id, limit=COMMENTS_LIMIT):
            text = (comment.message or "").strip()
            if text:
                comments.append(text)
    except Exception as e:
        # У поста может не быть обсуждения вовсе — это не ошибка
        logger.debug("[telethon] пост %s: комментарии недоступны: %s", message.id, e)

    caption = (message.message or "").strip()

    # 1. Явный маркер — сначала в комментариях, потом в подписи
    for source, texts in (("комментарий", comments), ("подпись", [caption] if caption else [])):
        for text in texts:
            found = extract_prompt(text)
            if found:
                logger.debug("[telethon] пост %s: промпт найден по маркеру (%s)", message.id, source)
                return found

    # 2. Маркера нет — самый длинный осмысленный комментарий
    long_comments = [t for t in comments if len(t) >= MIN_PROMPT_LEN]
    if long_comments:
        return max(long_comments, key=len)

    # 3. Ни того ни другого — подпись, если она достаточно длинная для промпта
    if len(caption) >= CAPTION_MIN_LEN:
        return caption

    return max(comments, key=len) if comments else ""


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

        ad_word = looks_like_ad(message.message, prompt)
        if ad_word:
            logger.info(
                "[telethon] канал %s, пост %s: рекламный («%s») — пропускаем",
                source_chat_id, message.id, ad_word,
            )
            continue

        if await image_has_text(client, message):
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
