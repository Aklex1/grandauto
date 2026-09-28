"""
Подарок за подписку: 50 промптов и три бесплатные генерации.

В закрепе группы «Нейросети» стоит ссылка вида
https://t.me/<бот>?start=pin. По ней бот присылает файл с пятьюдесятью
готовыми промптами и начисляет на баланс три генерации фото — иначе закреп
обещает подарок, которого никто не получает.

Начисляем один раз на пользователя: метка о выдаче лежит в отдельной
таблице, а не в теге пользователя, потому что тег в боте один и его
перетирают рекламные кампании. Промпты при повторном переходе отдаём снова
— файл не жалко, а человек мог его потерять.

Источник перехода запоминаем из самой ссылки: pin — закреп, pin_post —
кнопка под постом, и так далее. По нему потом видно, что сработало.
"""

import logging
import os
from typing import Optional, Tuple

from aiogram import Bot, Dispatcher, F
from aiogram.types import (BufferedInputFile, CallbackQuery, InlineKeyboardButton,
                           InlineKeyboardMarkup, Message)

from database import get_connection, update_balance

logger = logging.getLogger("pin_gift")

# Параметр ссылки. «pin» — закреп; всё, что начинается на «pin_», — другие
# места размещения той же ссылки.
PAYLOAD = "pin"
PAYLOAD_PREFIX = "pin_"
# Дополнительные метки, которые тоже наши. «promt» нужен, чтобы в канале
# промптов ссылка читалась по-человечески, а не «pin_promt».
EXTRA_PAYLOADS = {p.strip() for p in os.getenv("PIN_GIFT_PAYLOADS", "promt,group").split(",")
                  if p.strip()}

# Одна генерация фото стоит 5 ₽, значит три — пятнадцать.
GENERATION_PRICE = float(os.getenv("PIN_GIFT_PRICE", "5") or 5)
GENERATIONS = int(os.getenv("PIN_GIFT_GENERATIONS", "3") or 3)
BONUS = GENERATION_PRICE * GENERATIONS

FILE_NAME = "50-promptov-genius-bot.txt"

# Куда смотреть за подпиской. Ссылка одна, а мест размещения несколько, и
# требовать от читателя канала промптов подписку на группу — значит терять
# его на пустом месте. Поэтому канал привязан к метке ссылки.
#
# PIN_GIFT_CHANNELS="pin:-1001711115341|Нейросети|https://t.me/…;promt:@promtnanobanana7|Промты для нейросетей"
#   метка : чат [| название | ссылка]
# Метки, которых нет в списке, проверяются по PIN_GIFT_CHANNEL — общей
# настройке. Пусто и там — подписку не проверяем вовсе.
CHANNEL = os.getenv("PIN_GIFT_CHANNEL", "").strip()
CHANNEL_TITLE = os.getenv("PIN_GIFT_CHANNEL_TITLE", "нашем канале").strip()
CHANNEL_URL = os.getenv("PIN_GIFT_CHANNEL_URL", "").strip()

# Подписан ли — это членство в чате. Эти статусы считаем подпиской.
_MEMBER_STATUSES = ("creator", "administrator", "member")


def _public_url(chat: str) -> str:
    """Ссылка на чат по его @имени. У числового id ссылки нет — только инвайт."""
    chat = (chat or "").strip()
    if chat.startswith("@"):
        return "https://t.me/" + chat[1:]
    return ""


def _parse_channels(raw: str) -> dict:
    out = {}
    for piece in (raw or "").split(";"):
        piece = piece.strip()
        if not piece or ":" not in piece:
            continue
        source, spec = piece.split(":", 1)
        parts = [p.strip() for p in spec.split("|")]
        chat = parts[0]
        if not source.strip() or not chat:
            continue
        out[source.strip()] = {
            "chat": chat,
            "title": parts[1] if len(parts) > 1 and parts[1] else "нашем канале",
            "url": parts[2] if len(parts) > 2 and parts[2] else _public_url(chat),
        }
    return out


CHANNELS = _parse_channels(os.getenv("PIN_GIFT_CHANNELS", ""))

# Что делать, когда подписку проверить не удалось: бот не админ чата, чат
# указан неверно, Телеграм не ответил. 1 — просим подписаться (подарок не
# уходит), 0 — выдаём. По умолчанию строго: подарок за подписку, выданный
# без подписки, подписчиков не приносит, а раздаёт файлы даром.
STRICT = os.getenv("PIN_GIFT_STRICT", "1").strip().lower() in ("1", "true", "yes", "on")


def channel_for(source: str) -> Optional[dict]:
    """
    Чат, подписку на который проверяем для этой метки.

    None — проверять нечего: ни своей записи, ни общей настройки.
    """
    if source in CHANNELS:
        return CHANNELS[source]
    if CHANNEL:
        return {
            "chat": CHANNEL,
            "title": CHANNEL_TITLE,
            "url": CHANNEL_URL or _public_url(CHANNEL),
        }
    # Метка не описана, а проверка вообще настроена — берём запись метки
    # «pin», иначе первую. Иначе подарок забирают по выдуманной ссылке вида
    # ?start=pin_whatever, и подписка перестаёт быть условием.
    if CHANNELS:
        return CHANNELS.get(PAYLOAD) or next(iter(CHANNELS.values()))
    return None

PROMPTS_HEADER = """50 РАБОЧИХ ПРОМПТОВ ДЛЯ НЕЙРОСЕТЕЙ
Подарок подписчикам канала «Нейросети» — genius-bot.ru

Как пользоваться: откройте бота @Neuro_HubAI_bot, выберите «Генерация
изображения», вставьте промпт и, если нужно, замените слова в квадратных
скобках на свои. Промпты работают и в других генераторах.
"""

PROMPTS = [
    # Портрет и соцсети
    ("Портрет и соцсети", [
        "Фотореалистичный портрет [мужчины 35 лет] в деловой рубашке, нейтральный серый фон, мягкий свет слева, 85 мм, резкость по глазам, вертикальный кадр.",
        "Аватар для рабочих мессенджеров: поясной портрет, спокойное лицо, лёгкая улыбка, однотонный фон цвета слоновой кости, ровный рассеянный свет.",
        "Фото для резюме: [женщина 28 лет] в тёмном пиджаке, белый фон, свет как в фотостудии, без тени на лице, взгляд в камеру.",
        "Портрет в осеннем парке: золотая листва, мягкий контровой свет, боке, тёплые естественные цвета, 50 мм, вертикальный кадр.",
        "Чёрно-белый портрет в стиле репортажа: жёсткий боковой свет, глубокие тени, зерно как на плёнке ISO 400.",
        "Обложка для канала: абстрактный градиент синего и фиолетового, тонкие световые линии, место слева под текст, горизонтальный формат 16:9.",
    ]),
    # Товары и продажи
    ("Товары и карточки для продаж", [
        "Предметная съёмка: [керамическая кружка] на светлом бетоне, боковой мягкий свет, мягкая тень справа, минимализм, квадратный кадр.",
        "Карточка товара для маркетплейса: [кроссовки] на белом фоне, ровный студийный свет, без теней на фоне, товар по центру, запас по краям.",
        "Товар в жизни: [термокружка] в руках человека на утренней улице, размытый город на фоне, тёплый свет, ощущение движения.",
        "Раскладка flat lay: [набор канцелярии] сверху на светлом дереве, аккуратная сетка, ровный свет, пастельные цвета.",
        "Фото для рекламы еды: [чизкейк] на тарелке крупным планом, капли соуса, контровой свет, лёгкий пар, аппетитная текстура.",
        "Упаковка на полке магазина: [банка кофе] среди других товаров, фокус на нашей упаковке, остальное в лёгком расфокусе.",
    ]),
    # Работа и документы
    ("Работа, учёба, документы", [
        "Иллюстрация для презентации: команда обсуждает график у большого экрана, светлый офис, сдержанные цвета, без текста на картинке.",
        "Схема процесса без подписей: четыре блока слева направо, соединённые стрелками, плоский стиль, синяя гамма, белый фон.",
        "Обложка для учебного курса: раскрытая тетрадь, ноутбук и чашка на столе, вид сверху, тёплый свет, место под заголовок сверху.",
        "Иллюстрация к статье про налоги: документы, калькулятор и ручка на столе, строгий свет, без лиц, приглушённые цвета.",
        "Фон для вебинара: светлая переговорная с растениями, расфокус, нейтральные цвета, горизонтальный кадр 16:9.",
        "Инфографика-заготовка: пустые рамки под три графика и четыре иконки, плоский стиль, много воздуха, белый фон.",
    ]),
    # Семья и праздники
    ("Семья и праздники", [
        "Фотореалистичное фото [мамы и дочери], гуляют по осеннему парку под руку, жёлтые листья, мягкий контровой свет, 50 мм, вертикальный кадр.",
        "Семейный кадр за столом: три поколения, тёплый свет лампы, естественные позы, лёгкий беспорядок на столе, никакой парадности.",
        "Детский день рождения: торт со свечами, лица в тёплом свете, шарики на фоне, короткая выдержка, живые эмоции.",
        "Свадебный кадр: пара в поле на закате, ветер в платье, контровой свет, длинные тени, вертикальный формат.",
        "Открытка к Новому году: ель, гирлянда, окно с морозным узором, тёплый жёлтый свет, место под поздравление снизу.",
        "Фото с питомцем: [кот] на подоконнике в утреннем свете, мягкие тени, пастельные цвета, вид на уровне глаз животного.",
    ]),
    # Интерьер и ремонт
    ("Интерьер, ремонт, недвижимость", [
        "Интерьер кухни в скандинавском стиле: белые фасады, дерево, растения, утренний свет из окна, широкий угол, ровная перспектива.",
        "Спальня перед сдачей квартиры: нейтральные тона, аккуратная постель, ровный свет, ничего личного в кадре.",
        "Вариант отделки: одна и та же комната в двух цветах стен — тёплый бежевый и холодный серый, одинаковый свет и ракурс.",
        "Фото дома для объявления: фасад с улицы, ясный день, без машин и людей, прямые вертикали, горизонтальный кадр.",
        "Рабочий уголок: стол у окна, монитор, лампа, стеллаж с книгами, вечерний свет, уютная атмосфера.",
        "Санузел после ремонта: светлая плитка, стекло душевой, зеркало без отражения фотографа, ровный свет, прямые линии.",
    ]),
    # Редактирование своих фото
    ("Редактирование своих фото", [
        "Убери фон и поставь однотонный светло-серый, сохрани естественные границы волос и тени под предметом.",
        "Замени фон на офис с расфокусом, свет на человеке оставь как был, добавь мягкую тень под ним.",
        "Сделай из фото карандашный портрет: мягкая штриховка, белая бумага, без цветных пятен.",
        "Приведи снимок к деловому виду: ровный свет на лице, спокойный фон, одежда без складок, без пластиковой кожи.",
        "Верни старому фото цвет и резкость: убери царапины и выцветание, сохрани зерно и черты лица без изменений.",
        "Одень человека в [светлую рубашку], позу и лицо не меняй, свет и тени пересчитай под новую одежду.",
        "Добавь в кадр [второго человека справа], совпадающий по свету и масштабу, естественная тень на полу.",
        "Расширь кадр по бокам, домысли фон логично: продолжи стену и пол, ничего нового не добавляй.",
    ]),
    # Стили и кино
    ("Стили и кино", [
        "Кадр в стиле кино: ночная улица под дождём, неоновые отражения на асфальте, контровой свет, широкий формат 21:9.",
        "Стиль плёночной фотографии 90-х: тёплый оттенок, лёгкая засветка по краю, зерно, слегка приглушённые тени.",
        "Акварельная иллюстрация: [городская улица], мягкие размытые края, белые пропуски бумаги, ограниченная палитра.",
        "Мультяшный стиль: [семья из трёх человек], простые формы, чистые линии, плоские цвета, дружелюбные лица.",
        "Изометрия: [маленький офис] в разрезе, аккуратные объёмы, пастельные цвета, белый фон, без текста.",
        "Постер в стиле минимализма: один предмет по центру, крупный контраст, две краски, много пустого места.",
    ]),
    # Что писать, чтобы получилось
    ("Приёмы, которые улучшают любой промпт", [
        "Добавьте в конец: «фотореалистично, естественный свет, без искажений лица и рук, резкость по главному объекту».",
        "Назовите оптику и кадр: «85 мм, f/2, вертикальный кадр» — модель перестанет угадывать композицию.",
        "Скажите, чего не надо: «без текста на картинке, без логотипов, без лишних предметов на столе».",
        "Задайте свет словами: «мягкий свет из окна слева, тень на правой стороне лица» вместо «красивый свет».",
        "Опишите эмоцию через действие: «смеётся, откинув голову» работает лучше, чем «весёлый».",
        "Правьте по одному пункту за раз: поменяли свет — посмотрели результат, потом одежду. Пять правок сразу модель смешивает.",
    ]),
]

FOOTER = """
Ещё промпты и разборы — в канале «Нейросети».
Инструменты, которыми всё это делается: genius-bot.ru
"""


def build_file() -> bytes:
    """Файл с промптами. Собираем на ходу, чтобы не таскать его в репозитории."""
    lines = [PROMPTS_HEADER]
    number = 0
    for title, items in PROMPTS:
        lines.append("")
        lines.append("=" * 58)
        lines.append(title.upper())
        lines.append("=" * 58)
        for text in items:
            number += 1
            lines.append("")
            lines.append("%d. %s" % (number, text))
    lines.append(FOOTER)
    return ("\n".join(lines)).encode("utf-8")


def total_prompts() -> int:
    return sum(len(items) for _, items in PROMPTS)


def parse_payload(param: str) -> Optional[str]:
    """Источник перехода, если это наша ссылка. Иначе None."""
    if not param:
        return None
    value = param.strip()
    if value == PAYLOAD or value in EXTRA_PAYLOADS:
        return value[:64]
    if value.startswith(PAYLOAD_PREFIX) and len(value) > len(PAYLOAD_PREFIX):
        return value[:64]
    return None


def _ensure_table() -> None:
    conn = get_connection()
    try:
        cur = conn.cursor()
        cur.execute(
            """
            CREATE TABLE IF NOT EXISTS pin_gift_claims (
                telegram_id BIGINT NOT NULL PRIMARY KEY,
                source VARCHAR(64) DEFAULT NULL,
                tokens DECIMAL(10,2) NOT NULL DEFAULT 0,
                granted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            """
        )
        conn.commit()
    finally:
        conn.close()


def claim(telegram_id: int, source: str) -> Tuple[bool, float]:
    """
    Отмечаем выдачу подарка и начисляем баланс.

    Возвращает (начислено ли сейчас, сколько рублей). Вторая попытка того же
    человека ничего не начисляет: запись в таблице одна на telegram_id, и
    вставка со второй попытки просто не проходит.
    """
    try:
        _ensure_table()
        conn = get_connection()
        try:
            cur = conn.cursor()
            cur.execute(
                "INSERT IGNORE INTO pin_gift_claims (telegram_id, source, tokens) VALUES (%s, %s, %s)",
                (telegram_id, source, BONUS),
            )
            conn.commit()
            fresh = cur.rowcount == 1
        finally:
            conn.close()
    except Exception as e:
        # Без базы подарок всё равно отдаём файлом, но баланс не трогаем:
        # начислить, не записав выдачу, — это бесконечный бонус по одной ссылке.
        logger.error("[pin_gift] не удалось записать выдачу для %s: %s", telegram_id, e)
        return False, 0.0

    if not fresh:
        return False, 0.0

    try:
        update_balance(telegram_id, BONUS)
    except Exception as e:
        logger.error("[pin_gift] баланс не начислен для %s: %s", telegram_id, e)
        return False, 0.0
    return True, BONUS


def _keyboard() -> InlineKeyboardMarkup:
    """
    Кнопки под подарком.

    Ведём на «menu_generate» — это единственная точка входа в генерацию,
    которая работает без состояния. Кнопка прямо на «nano_mode_generate»
    выглядела бы короче, но её обработчик привязан к состоянию выбора
    режима: у человека, только что открывшего бота, она не сработала бы.
    """
    return InlineKeyboardMarkup(
        inline_keyboard=[
            [InlineKeyboardButton(text="🚀 Создать первое изображение", callback_data="menu_generate")],
            [InlineKeyboardButton(text="📋 Меню бота", callback_data="open_menu")],
        ]
    )


async def is_subscribed(bot: Bot, user_id: int, source: str = PAYLOAD) -> Optional[bool]:
    """
    Подписан ли человек на чат, который отвечает за эту метку ссылки.

    None означает «проверить не смогли»: бот не админ чата, чат указан
    неверно или Телеграм не ответил. В этом случае подарок выдаём — терять
    человека из-за нашей же настройки хуже, чем выдать лишний файл.
    """
    place = channel_for(source)
    if not place:
        return True
    try:
        member = await bot.get_chat_member(place["chat"], user_id)
    except Exception as e:
        logger.warning("[pin_gift] подписку %s проверить не удалось (%s): %s",
                       user_id, place["chat"], e)
        return None
    status = getattr(member, "status", "")
    status = getattr(status, "value", status)   # aiogram отдаёт enum
    return str(status) in _MEMBER_STATUSES


def _subscribe_keyboard(source: str) -> InlineKeyboardMarkup:
    """Кнопки для неподписавшегося: сначала канал, потом повторная проверка."""
    place = channel_for(source) or {}
    rows = []
    if place.get("url"):
        rows.append([InlineKeyboardButton(text="📣 Подписаться", url=place["url"])])
    rows.append([InlineKeyboardButton(text="✅ Я подписался — забрать подарок",
                                      callback_data=f"pin_sub_{source}"[:64])])
    return InlineKeyboardMarkup(inline_keyboard=rows)


async def _ask_to_subscribe(message: Message, source: str, unknown: bool = False) -> None:
    place = channel_for(source) or {}
    title = place.get("title") or "нашем канале"
    tail = ("Подпишитесь и нажмите «Я подписался» — файл и бонус придут сразу."
            if not unknown else
            "Проверить подписку сейчас не получилось. Нажмите «Я подписался» — "
            "попробуем ещё раз.")
    await message.answer(
        "🎁 <b>Подарок ждёт вас</b>\n\n"
        f"{total_prompts()} промптов на каждый день и {GENERATIONS} бесплатные генерации "
        f"изображений — за подписку на «{title}».\n\n" + tail,
        parse_mode="HTML", reply_markup=_subscribe_keyboard(source))


async def give(message: Message, source: str, user_id: int) -> None:
    """
    Выдача подарка: файл всегда, баланс — один раз на человека.

    user_id передаём отдельно: при нажатии кнопки message принадлежит боту,
    и message.from_user там — сам бот, а не человек.
    """
    granted, bonus = claim(user_id, source)

    document = BufferedInputFile(build_file(), filename=FILE_NAME)
    if granted:
        caption = (
            "🎁 <b>Подарок за подписку</b>\n\n"
            f"В файле — {total_prompts()} готовых промптов: портреты, товары для продаж, "
            "работа и учёба, семейные кадры, интерьеры и приёмы редактирования своих фото.\n\n"
            f"И на ваш баланс уже зачислено <b>{bonus:.0f} ₽</b> — это "
            f"{GENERATIONS} бесплатные генерации изображений.\n\n"
            "Как попробовать: нажмите «Создать изображение», вставьте любой промпт из файла "
            "и замените слова в квадратных скобках на свои."
        )
    else:
        caption = (
            "🎁 <b>Файл с промптами</b>\n\n"
            f"{total_prompts()} промптов на каждый день — держите.\n\n"
            "Бонусные генерации по этой ссылке уже начислялись раньше, "
            "поэтому только файл. Баланс виден в разделе «Профиль»."
        )

    try:
        await message.answer_document(document, caption=caption, parse_mode="HTML",
                                      reply_markup=_keyboard())
    except Exception as e:
        # Телеграм может не принять документ (редко, но бывает) — тогда хотя бы
        # объясняем человеку, что бонус начислен, и не молчим.
        logger.error("[pin_gift] файл не отправлен %s: %s", user_id, e)
        await message.answer(caption, parse_mode="HTML", reply_markup=_keyboard())

    logger.info("[pin_gift] подарок: user=%s source=%s начислено=%s", user_id, source, granted)


async def handle(message: Message, param: str) -> bool:
    """
    Переход по ссылке из закрепа. True — подарок обработан, приветствие не нужно.

    Если задан канал, сначала проверяем подписку: подарок за подписку,
    который выдают без подписки, подписчиков не приносит.
    """
    source = parse_payload(param)
    if source is None:
        return False

    subscribed = await is_subscribed(message.bot, message.from_user.id, source)
    if subscribed is False or (subscribed is None and STRICT):
        await _ask_to_subscribe(message, source, unknown=subscribed is None)
        return True

    await give(message, source, message.from_user.id)
    return True


def setup(dp: Dispatcher, bot: Bot) -> None:
    """Кнопка «Я подписался»: проверяем ещё раз и выдаём."""

    @dp.callback_query(F.data.startswith("pin_sub_"))
    async def recheck(callback: CallbackQuery) -> None:
        source = (callback.data or "")[len("pin_sub_"):] or PAYLOAD
        subscribed = await is_subscribed(bot, callback.from_user.id, source)
        if subscribed is False or (subscribed is None and STRICT):
            await callback.answer(
                "Подписки пока не видно. Подпишитесь и нажмите ещё раз."
                if subscribed is False else
                "Не удалось проверить подписку. Попробуйте через минуту или напишите нам.",
                show_alert=True)
            return
        await callback.answer("Спасибо! Отправляю подарок")
        try:
            await callback.message.edit_reply_markup(reply_markup=None)
        except Exception:
            pass
        await give(callback.message, source, callback.from_user.id)

    places = ", ".join("%s → %s" % (k, v["chat"]) for k, v in CHANNELS.items())
    if places or CHANNEL:
        logger.info("[pin_gift] проверка подписки: %s (строго: %s)",
                    places or CHANNEL, "да" if STRICT else "нет")
    else:
        # Молчать здесь нельзя: подарок будет уходить всем, а выглядеть это
        # будет как сломанная проверка.
        logger.error("[pin_gift] канал не настроен (PIN_GIFT_CHANNELS) — "
                     "подарок выдаётся без проверки подписки")
