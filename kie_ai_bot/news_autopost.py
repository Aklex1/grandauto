"""
Автопостинг в канал про нейросети: свои посты по плану плюс новости.

За сутки выходит три поста:
* один из контент-плана на три месяца (content_plan.json);
* два — свежие новости про ИИ с русскоязычных порталов.

Повторов не бывает: каждая новость запоминается по ссылке и по отпечатку
заголовка, поэтому один и тот же материал не выйдет ни со второй ленты,
ни после перезапуска бота. Посты плана тоже идут по одному разу, по порядку.
"""

import asyncio
import hashlib
import json
import logging
import os
import re
import sqlite3
from contextlib import closing
from datetime import datetime, timezone
from html import escape
from pathlib import Path
from typing import List, Optional

from aiogram import Bot
from aiogram.types import URLInputFile

import news_sources

logger = logging.getLogger("news")


def _env_int(name: str, default: int) -> int:
    try:
        return int(os.getenv(name, str(default)))
    except (TypeError, ValueError):
        return default


def _env_flag(name: str, default: str = "0") -> bool:
    return os.getenv(name, default).strip().lower() in ("1", "true", "yes", "on")


ENABLED = _env_flag("NEWS_ENABLED")
CHAT_ID = _env_int("NEWS_CHAT_ID", 0)
DB_PATH = Path(os.getenv("NEWS_DB", "/opt/kie_ai_bot/news.db"))
PLAN_PATH = Path(os.getenv("NEWS_PLAN", "/opt/kie_ai_bot/content_plan.json"))

# Сколько чего выходит за сутки
NEWS_PER_DAY = _env_int("NEWS_PER_DAY", 2)
PLAN_PER_DAY = _env_int("NEWS_PLAN_PER_DAY", 1)

# Часы публикаций (UTC). По умолчанию 07:00, 12:00 и 16:00 UTC —
# это 10:00, 15:00 и 19:00 по Москве
SCHEDULE_HOURS = [
    int(h) for h in os.getenv("NEWS_SCHEDULE_HOURS", "7,12,16").split(",") if h.strip().isdigit()
]
# Как часто проверять расписание, секунды
TICK_INTERVAL = _env_int("NEWS_TICK_INTERVAL", 300)

FOOTER = os.getenv("NEWS_FOOTER", "")
BOT_URL = os.getenv("NEWS_BOT_URL", "https://t.me/Neuro_HubAI_bot")

CAPTION_LIMIT = 1024
MESSAGE_LIMIT = 4096


# --- Хранилище -------------------------------------------------------------

def _connect() -> sqlite3.Connection:
    DB_PATH.parent.mkdir(parents=True, exist_ok=True)
    conn = sqlite3.connect(DB_PATH, timeout=30)
    conn.row_factory = sqlite3.Row
    return conn


def init_db() -> None:
    with closing(_connect()) as conn:
        conn.execute("""
            CREATE TABLE IF NOT EXISTS news_posts (
                id           INTEGER PRIMARY KEY AUTOINCREMENT,
                kind         TEXT NOT NULL,           -- news | plan
                url          TEXT,
                fingerprint  TEXT NOT NULL UNIQUE,    -- отпечаток: защита от повторов
                title        TEXT,
                source       TEXT,
                plan_index   INTEGER,
                message_id   INTEGER,
                published_at TEXT NOT NULL
            )
        """)
        conn.execute("CREATE INDEX IF NOT EXISTS idx_news_kind ON news_posts(kind)")
        conn.execute("CREATE INDEX IF NOT EXISTS idx_news_url ON news_posts(url)")
        conn.commit()


def _normalize_url(url: str) -> str:
    """Ссылка без utm-меток и якорей — иначе один материал выглядит как разный."""
    url = (url or "").strip().split("#")[0]
    url = re.sub(r"[?&](utm_[^=]+|from|source|ref)=[^&]*", "", url)
    return url.rstrip("?&/").lower()


def _title_key(title: str) -> str:
    """Отпечаток заголовка: одну и ту же новость разные порталы пишут по-разному,
    но набор значимых слов у них совпадает."""
    words = re.findall(r"[a-zа-яё0-9]+", (title or "").lower())
    significant = sorted(w for w in words if len(w) > 3)[:12]
    return " ".join(significant)


def fingerprint(url: str, title: str) -> str:
    base = _normalize_url(url) or _title_key(title)
    return hashlib.sha256(base.encode("utf-8")).hexdigest()[:32]


def title_fingerprint(title: str) -> str:
    return hashlib.sha256(_title_key(title).encode("utf-8")).hexdigest()[:32]


def already_posted(url: str, title: str) -> bool:
    """Материал уже выходил — по ссылке или по смыслу заголовка."""
    with closing(_connect()) as conn:
        for fp in (fingerprint(url, title), title_fingerprint(title)):
            if conn.execute("SELECT 1 FROM news_posts WHERE fingerprint = ?", (fp,)).fetchone():
                return True
        if url:
            normalized = _normalize_url(url)
            if conn.execute("SELECT 1 FROM news_posts WHERE url = ?", (normalized,)).fetchone():
                return True
    return False


def remember(kind: str, *, url: str = "", title: str = "", source: str = "",
             plan_index: Optional[int] = None, message_id: Optional[int] = None) -> None:
    """Запоминает опубликованное. Пишем оба отпечатка — по ссылке и по заголовку."""
    now = datetime.now(timezone.utc).isoformat(timespec="seconds")
    with closing(_connect()) as conn:
        for fp in {fingerprint(url, title), title_fingerprint(title)}:
            conn.execute("""
                INSERT OR IGNORE INTO news_posts
                    (kind, url, fingerprint, title, source, plan_index, message_id, published_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            """, (kind, _normalize_url(url), fp, title, source, plan_index, message_id, now))
        conn.commit()


def posted_today(kind: str) -> int:
    today = datetime.now(timezone.utc).date().isoformat()
    with closing(_connect()) as conn:
        row = conn.execute(
            "SELECT COUNT(DISTINCT COALESCE(url, title)) AS n FROM news_posts "
            "WHERE kind = ? AND published_at >= ?",
            (kind, today),
        ).fetchone()
    return int(row["n"] if row else 0)


def next_plan_index() -> int:
    with closing(_connect()) as conn:
        row = conn.execute(
            "SELECT MAX(plan_index) AS last FROM news_posts WHERE kind = 'plan'"
        ).fetchone()
    return int(row["last"] + 1) if row and row["last"] is not None else 0


# --- Контент-план ----------------------------------------------------------

def load_plan() -> List[dict]:
    if not PLAN_PATH.exists():
        logger.error("[новости] контент-план не найден: %s", PLAN_PATH)
        return []
    try:
        with open(PLAN_PATH, encoding="utf-8") as f:
            return json.load(f).get("posts", [])
    except Exception as e:
        logger.error("[новости] контент-план не прочитан: %s", e)
        return []


# --- Оформление ------------------------------------------------------------

def build_news_text(item: news_sources.NewsItem) -> str:
    summary = item.summary
    if len(summary) > 450:
        summary = summary[:447].rsplit(" ", 1)[0] + "..."

    parts = [f"<b>{escape(item.title)}</b>"]
    if summary:
        parts.append(escape(summary))
    parts.append(f'<a href="{escape(item.link)}">Источник: {escape(item.source)}</a>')
    if FOOTER:
        parts.append(FOOTER)
    return "\n\n".join(parts)


def build_plan_text(post: dict) -> str:
    parts = []
    if post.get("rubric"):
        parts.append(f"<b>{escape(post['rubric'])}</b>")
    if post.get("title"):
        parts.append(f"<b>{escape(post['title'])}</b>")
    if post.get("text"):
        parts.append(escape(post["text"]))
    if post.get("cta"):
        parts.append(f'<a href="{escape(BOT_URL)}">{escape(post["cta"])}</a>')
    if FOOTER:
        parts.append(FOOTER)
    return "\n\n".join(parts)


def _trim(text: str, limit: int) -> str:
    if len(re.sub(r"<[^>]+>", "", text)) <= limit:
        return text
    plain = re.sub(r"<[^>]+>", "", text)
    return plain[: limit - 3] + "..."


# --- Публикация ------------------------------------------------------------

async def publish_news(bot: Bot, item: news_sources.NewsItem) -> bool:
    text = build_news_text(item)

    try:
        if item.image:
            await bot.send_photo(
                chat_id=CHAT_ID, photo=URLInputFile(item.image),
                caption=_trim(text, CAPTION_LIMIT), parse_mode="HTML",
            )
        else:
            await bot.send_message(
                chat_id=CHAT_ID, text=_trim(text, MESSAGE_LIMIT), parse_mode="HTML",
                disable_web_page_preview=False,
            )
    except Exception as e:
        logger.warning("[новости] с картинкой не вышло (%s), публикуем текстом", e)
        try:
            await bot.send_message(
                chat_id=CHAT_ID, text=_trim(text, MESSAGE_LIMIT), parse_mode="HTML",
            )
        except Exception as e2:
            logger.error("[новости] публикация не удалась: %s", e2)
            return False

    remember("news", url=item.link, title=item.title, source=item.source)
    logger.info("[новости] опубликовано: %.60s (%s)", item.title, item.source)
    return True


async def publish_plan_post(bot: Bot, post: dict, index: int) -> bool:
    text = build_plan_text(post)
    try:
        await bot.send_message(
            chat_id=CHAT_ID, text=_trim(text, MESSAGE_LIMIT), parse_mode="HTML",
            disable_web_page_preview=True,
        )
    except Exception as e:
        logger.error("[новости] пост плана %s не опубликован: %s", index, e)
        return False

    remember("plan", title=post.get("title", f"план {index}"), plan_index=index)
    logger.info("[новости] опубликован пост плана %s: %.50s", index, post.get("title", ""))
    return True


async def pick_fresh_news(limit: int = 1) -> List[news_sources.NewsItem]:
    """Свежие материалы, которых ещё не было в канале."""
    items = await news_sources.fetch_all()
    chosen: List[news_sources.NewsItem] = []
    seen_in_batch = set()

    import httpx

    async with httpx.AsyncClient(timeout=25, follow_redirects=True) as client:
        for item in items:
            if len(chosen) >= limit:
                break
            key = title_fingerprint(item.title)
            if key in seen_in_batch or already_posted(item.link, item.title):
                continue
            if not item.image:
                item.image = await news_sources.fetch_og_image(client, item.link)
            seen_in_batch.add(key)
            chosen.append(item)

    return chosen


async def publish_one_news(bot: Bot) -> bool:
    items = await pick_fresh_news(limit=1)
    if not items:
        logger.info("[новости] свежих материалов не нашлось — все уже выходили")
        return False
    return await publish_news(bot, items[0])


async def publish_one_plan(bot: Bot) -> bool:
    plan = load_plan()
    if not plan:
        return False
    index = next_plan_index()
    if index >= len(plan):
        logger.info("[новости] контент-план закончился (%s постов)", len(plan))
        return False
    return await publish_plan_post(bot, plan[index], index)


# --- Расписание ------------------------------------------------------------

def _slot_plan() -> List[str]:
    """Что публикуем в каждый час расписания: сначала пост плана, затем новости."""
    slots = ["plan"] * PLAN_PER_DAY + ["news"] * NEWS_PER_DAY
    return slots[: len(SCHEDULE_HOURS)] or slots


async def news_worker(bot: Bot) -> None:
    """Следит за расписанием и публикует посты в назначенные часы."""
    if not ENABLED:
        logger.info("[новости] выключено (NEWS_ENABLED=0)")
        return
    if not CHAT_ID:
        logger.error("[новости] не задан NEWS_CHAT_ID — публиковать некуда")
        return

    init_db()
    slots = _slot_plan()
    logger.info(
        "[новости] запущено: канал %s, расписание %s UTC, за сутки %s из плана и %s новостей",
        CHAT_ID, SCHEDULE_HOURS, PLAN_PER_DAY, NEWS_PER_DAY,
    )

    while True:
        try:
            now = datetime.now(timezone.utc)
            for position, hour in enumerate(SCHEDULE_HOURS):
                if now.hour != hour:
                    continue

                kind = slots[position] if position < len(slots) else "news"
                limit = PLAN_PER_DAY if kind == "plan" else NEWS_PER_DAY
                if posted_today(kind) >= limit:
                    continue

                if kind == "plan":
                    await publish_one_plan(bot)
                else:
                    await publish_one_news(bot)
                break
        except Exception as e:
            logger.error("[новости] ошибка расписания: %s", e, exc_info=True)

        await asyncio.sleep(TICK_INTERVAL)
