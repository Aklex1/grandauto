"""
Задание недели в теме группы.

Кабинет и чат живут порознь: в кабинете задания, в чате люди. Эта
связка переносит задание туда, где они уже сидят. В понедельник бот
публикует задание недели, в пятницу напоминает показать результат —
и так по кругу, восемь заданий по порядку модулей курса.

Задания нарочно маленькие: одно действие на неделю, которое можно
сделать за вечер и показать. Большое задание в чате не делает никто.
"""

import asyncio
import logging
import os
import sqlite3
from datetime import datetime, timedelta, timezone
from typing import Optional

from aiogram import Bot

logger = logging.getLogger("group_task")


def _env_int(name: str, default: int) -> int:
    try:
        return int(os.getenv(name, str(default)))
    except (TypeError, ValueError):
        return default


def _flag(name: str, default: str = "0") -> bool:
    return os.getenv(name, default).strip().lower() in ("1", "true", "yes", "on")


ENABLED = _flag("WEEKTASK_ENABLED")
CHAT_ID = _env_int("WEEKTASK_CHAT_ID", -1004407841852)
# Тема обсуждения: там же, где показывают свои работы.
THREAD_ID = _env_int("WEEKTASK_THREAD_ID", 3)
TICK = _env_int("WEEKTASK_TICK", 1800)
TZ_OFFSET = _env_int("WEEKTASK_TZ_OFFSET", 3)
# Понедельник — задание, пятница — напоминание показать результат.
TASK_DAY = _env_int("WEEKTASK_DAY", 0)
TASK_HOUR = _env_int("WEEKTASK_HOUR", 11)
WRAP_DAY = _env_int("WEEKTASK_WRAP_DAY", 4)
WRAP_HOUR = _env_int("WEEKTASK_WRAP_HOUR", 18)


TASKS = (
    ("Карточка товара",
     "Возьмите любой товар — свой, знакомого или с маркетплейса — и сделайте главный кадр для карточки.\n\n"
     "Условия: ровный свет, чистый фон, предмет занимает большую часть кадра, сверху место под надпись.\n\n"
     "Покажите здесь кадр и напишите, сколько попыток ушло."),
    ("Серия в одном стиле",
     "Сделайте к своему кадру три дополнительных так, чтобы они смотрелись комплектом: тот же свет, тот же фон, другой ракурс.\n\n"
     "Хитрость в том, чтобы править первый удачный кадр, а не генерировать заново.\n\n"
     "Покажите все четыре одной сеткой — выпадающий виден только рядом с остальными."),
    ("Текст, который продаёт",
     "Напишите карточку товара: заголовок до 60 знаков и описание до 1000.\n\n"
     "Правила: никакой превосходной степени без подтверждения, первые пять слов цепляют, характеристики списком.\n\n"
     "Выкладывайте текст сюда — разберём, что в нём работает, а что вода."),
    ("Скрипт озвучки",
     "Напишите закадровый текст на 30 секунд: короткие фразы, числа словами, пометки пауз.\n\n"
     "Прочитайте вслух и засеките время. Не уложились — режьте, а не ускоряйте.\n\n"
     "Покажите скрипт и напишите, сколько получилось по секундам."),
    ("Ролик на 15 секунд",
     "Соберите вертикальный ролик: один товар, три плана, звук и субтитры.\n\n"
     "Перед публикацией четыре проверки: не обрезано по краям, слышно на динамике телефона, субтитры не под интерфейсом, файл открывается.\n\n"
     "Выкладывайте ролик — смотрим первые две секунды."),
    ("Обложка для трека",
     "Сделайте три разные обложки под один трек: портрет, предметную сцену и графику.\n\n"
     "Главное требование: читается в размере ногтя и есть место под название.\n\n"
     "Покажите все три — выберем вместе, какую бы взял заказчик."),
    ("Прайс и портфолио",
     "Соберите прайс из трёх пакетов и шесть работ с подписями: задача, что сделали, сколько заняло.\n\n"
     "Прайс без себестоимости не считается — посчитайте, сколько стоит ваш час.\n\n"
     "Покажите прайс здесь: скажем, где цена звучит неуверенно."),
    ("Двадцать предложений",
     "Отправьте двадцать сообщений заказчикам по трём каналам. Шестьдесят слов, польза в первой строке, один пример работы.\n\n"
     "Записывайте, что отвечали: без этого статистики нет, а отказы на первых десяти — норма.\n\n"
     "В пятницу напишите, сколько ответов и чем закончилось."),
)


def _db_path() -> str:
    return os.getenv("NEWS_DB", "/opt/kie_ai_bot/news.db")


def _connect() -> sqlite3.Connection:
    conn = sqlite3.connect(_db_path(), timeout=30)
    conn.row_factory = sqlite3.Row
    return conn


def init_db() -> None:
    with _connect() as conn:
        conn.execute("""
            CREATE TABLE IF NOT EXISTS group_tasks (
                mark TEXT PRIMARY KEY,
                kind TEXT NOT NULL,
                published_at TEXT NOT NULL,
                message_id INTEGER
            )
        """)


def done(mark: str) -> bool:
    with _connect() as conn:
        return conn.execute("SELECT 1 FROM group_tasks WHERE mark = ?", (mark,)).fetchone() is not None


def remember(mark: str, kind: str, message_id: Optional[int]) -> None:
    with _connect() as conn:
        conn.execute(
            "INSERT OR REPLACE INTO group_tasks (mark, kind, published_at, message_id) VALUES (?, ?, ?, ?)",
            (mark, kind, datetime.now(timezone.utc).isoformat(), message_id),
        )


def weeks_passed() -> int:
    """Сколько заданий уже выходило — по нему берём следующее по кругу."""
    with _connect() as conn:
        row = conn.execute("SELECT COUNT(*) AS n FROM group_tasks WHERE kind = 'task'").fetchone()
        return int(row["n"] if row else 0)


def local_now() -> datetime:
    return datetime.now(timezone.utc).astimezone(timezone(timedelta(hours=TZ_OFFSET)))


def week_mark(now: datetime, kind: str) -> str:
    year, week, _ = now.isocalendar()
    return "%04d-%02d-%s" % (year, week, kind)


def task_text(index: int) -> str:
    title, body = TASKS[index % len(TASKS)]
    return ("<b>Задание недели: %s</b>\n\n%s\n\nПоказывайте работы прямо в этой теме. "
            "Разбираем всё, что выложат, — в пятницу подведём итог." % (title, body))


def wrap_text(index: int) -> str:
    title, _ = TASKS[index % len(TASKS)]
    return ("<b>Пятница: показываем результат</b>\n\nЗадание недели было — %s.\n\n"
            "Выкладывайте, что получилось, даже если считаете, что вышло плохо: "
            "по неудачному кадру видно причину, а по удачному — только удачу. "
            "Разберём каждую работу." % title.lower())


async def publish_due(bot: Bot) -> bool:
    now = local_now()
    index = weeks_passed()

    if now.weekday() == TASK_DAY and now.hour >= TASK_HOUR:
        mark = week_mark(now, "task")
        if not done(mark):
            sent = await bot.send_message(CHAT_ID, task_text(index),
                                          message_thread_id=THREAD_ID or None,
                                          parse_mode="HTML", disable_web_page_preview=True)
            remember(mark, "task", getattr(sent, "message_id", None))
            logger.info("[задание недели] опубликовано задание №%s", index + 1)
            return True

    if now.weekday() == WRAP_DAY and now.hour >= WRAP_HOUR:
        mark = week_mark(now, "wrap")
        # Итог без задания на этой неделе не имеет смысла.
        if not done(mark) and done(week_mark(now, "task")):
            sent = await bot.send_message(CHAT_ID, wrap_text(index - 1 if index else 0),
                                          message_thread_id=THREAD_ID or None,
                                          parse_mode="HTML", disable_web_page_preview=True)
            remember(mark, "wrap", getattr(sent, "message_id", None))
            logger.info("[задание недели] напоминание о результатах")
            return True

    return False


async def task_worker(bot: Bot) -> None:
    if not ENABLED:
        logger.info("[задание недели] выключено (WEEKTASK_ENABLED=0)")
        return
    if not CHAT_ID:
        logger.error("[задание недели] не задан WEEKTASK_CHAT_ID — публиковать некуда")
        return

    init_db()
    logger.info("[задание недели] запущено: чат %s, тема %s, заданий %s, вышло %s",
                CHAT_ID, THREAD_ID, len(TASKS), weeks_passed())

    while True:
        try:
            await publish_due(bot)
        except Exception as e:
            logger.error("[задание недели] ошибка: %s", e, exc_info=True)
        await asyncio.sleep(TICK)
