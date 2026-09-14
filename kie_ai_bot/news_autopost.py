"""
Автопостинг в канал про нейросети: свои посты по плану плюс новости.

За сутки выходит три поста:
* один из контент-плана на три месяца (content_plan.json);
* два — свежие новости про ИИ с русскоязычных порталов.

Ссылок на сторонние сайты в постах нет — источник называется словом.
Ссылка на бота с призывом к генерации ставится в одном посте из трёх
(NEWS_BOT_CTA_EVERY), чтобы призыв не примелькался.

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
# Призыв со ссылкой на свой бот под новостью; пустая строка — без него
BOT_CTA = os.getenv("NEWS_BOT_CTA", "Сделать фото или видео нейросетью")
# Ссылка на бота идёт не под каждым постом, а под одним из трёх: в ленте,
# где призыв стоит в каждой записи, читатель перестаёт его замечать.
# 1 — ставить в каждый пост, 0 — не ставить нигде.
BOT_CTA_EVERY = _env_int("NEWS_BOT_CTA_EVERY", 3)
# Название источника словом. Ссылку на сторонний сайт не ставим никогда
SHOW_SOURCE_NAME = _env_flag("NEWS_SHOW_SOURCE_NAME", "1")

# Свои адреса: их в постах оставляем, всё остальное вырезаем
OWN_DOMAINS = [d.strip().lower() for d in
               os.getenv("NEWS_OWN_DOMAINS", "genius-bot.ru").split(",") if d.strip()]

CAPTION_LIMIT = 1024
MESSAGE_LIMIT = 4096

# Ссылка тегом и голый адрес в тексте: Telegram делает кликабельным и второе,
# поэтому вырезать нужно оба вида
_LINK_TAG_RE = re.compile(r'<a\s[^>]*href="([^"]*)"[^>]*>(.*?)</a>', re.I | re.S)
_BARE_URL_RE = re.compile(
    r"https?://[^\s<>\"]+"
    r"|(?<![\w@.])(?:[a-z0-9-]+\.)+(?:ru|com|org|net|io|ai|dev|me|info|biz|online|site|xyz|рф)"
    r"(?:/[^\s<>\"]*)?",
    re.I,
)


def _is_own_link(url: str) -> bool:
    """Ссылка на свой бот или свой сайт — такие оставляем."""
    lowered = (url or "").strip().lower()
    if not lowered:
        return False
    if BOT_URL and lowered.startswith(BOT_URL.lower().rstrip("/")):
        return True
    return any(domain in lowered for domain in OWN_DOMAINS)


def strip_external_links(text: str) -> str:
    """В группе допускается единственная ссылка — на свой бот (и свой сайт).
    Чужие адреса убираем: ресурс называется словом, уводить читателя незачем."""
    kept: List[str] = []

    def handle_tag(match: "re.Match") -> str:
        href, inner = match.group(1), match.group(2)
        if _is_own_link(href):
            kept.append(match.group(0))
            return f"\x00{len(kept) - 1}\x00"
        return inner                      # ссылка становится обычным текстом

    text = _LINK_TAG_RE.sub(handle_tag, text)
    text = _BARE_URL_RE.sub(lambda m: m.group(0) if _is_own_link(m.group(0)) else "", text)

    # После вырезанного адреса остаётся висячий предлог: «Читать на» —
    # убираем и его, иначе фраза выглядит оборванной
    text = re.sub(r"\s+(?:на|в|во|по|с|со|из|от|у|для|при|через)\s*(?=[.,;:!?)]|$)",
                  "", text, flags=re.I | re.M)
    text = re.sub(r"[ \t]{2,}", " ", text)
    text = re.sub(r" +([.,;:!?])", r"\1", text)
    text = re.sub(r"\n{3,}", "\n\n", text)
    text = text.strip()

    for index, tag in enumerate(kept):
        text = text.replace(f"\x00{index}\x00", tag)
    return text


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
        # Журнал публикаций группы: по одной строке на каждый вышедший пост.
        # Нужен для очереди ссылки на бота — в отличие от news_posts, где
        # один материал хранится под двумя отпечатками, а промо-посты про
        # один и тот же сервис повторяются.
        conn.execute("""
            CREATE TABLE IF NOT EXISTS channel_posts (
                id           INTEGER PRIMARY KEY AUTOINCREMENT,
                kind         TEXT NOT NULL,           -- news | plan | promo
                title        TEXT,
                with_cta     INTEGER NOT NULL DEFAULT 0,
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


def log_publication(kind: str, title: str = "", *, with_cta: bool = False,
                    message_id: Optional[int] = None) -> None:
    """Отмечает вышедший пост в журнале группы — новость, пост плана или промо."""
    now = datetime.now(timezone.utc).isoformat(timespec="seconds")
    try:
        with closing(_connect()) as conn:
            conn.execute(
                "INSERT INTO channel_posts (kind, title, with_cta, message_id, published_at) "
                "VALUES (?, ?, ?, ?, ?)",
                (kind, title[:300], 1 if with_cta else 0, message_id, now),
            )
            conn.commit()
    except Exception as e:
        logger.warning("[новости] журнал публикаций недоступен: %s", e)


def published_total() -> int:
    """Сколько постов всего вышло в группе — новости, посты плана и промо."""
    with closing(_connect()) as conn:
        row = conn.execute("SELECT COUNT(*) AS n FROM channel_posts").fetchone()
    return int(row["n"] if row else 0)


def cta_due() -> bool:
    """Пора ли ставить ссылку на бота: один пост из BOT_CTA_EVERY."""
    if not BOT_URL or BOT_CTA_EVERY <= 0:
        return False
    if BOT_CTA_EVERY == 1:
        return True
    try:
        return published_total() % BOT_CTA_EVERY == 0
    except Exception as e:
        logger.warning("[новости] счётчик постов недоступен (%s), призыв пропускаем", e)
        return False


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

def build_news_text(item: news_sources.NewsItem, with_cta: bool = False) -> str:
    """Текст новости. Ссылок на сторонние сайты не ставим: источник
    указывается словом, чтобы не уводить читателя из канала.
    Ссылка на бота добавляется только там, где её ждёт очередь (with_cta)."""
    summary = item.summary
    if len(summary) > 450:
        summary = summary[:447].rsplit(" ", 1)[0] + "..."

    parts = [f"<b>{escape(item.title)}</b>"]
    if summary:
        parts.append(escape(summary))
    if SHOW_SOURCE_NAME and item.source:
        parts.append(f"<i>Источник: {escape(item.source)}</i>")
    if with_cta and BOT_URL and BOT_CTA:
        parts.append(f'<a href="{escape(BOT_URL)}">{escape(BOT_CTA)}</a>')
    if FOOTER:
        parts.append(FOOTER)
    return "\n\n".join(parts)


def build_plan_text(post: dict, with_cta: bool = False) -> str:
    parts = []
    if post.get("rubric"):
        parts.append(f"<b>{escape(post['rubric'])}</b>")
    if post.get("title"):
        parts.append(f"<b>{escape(post['title'])}</b>")
    if post.get("text"):
        parts.append(escape(post["text"]))
    if with_cta and post.get("cta") and BOT_URL:
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
    with_cta = cta_due()
    text = strip_external_links(build_news_text(item, with_cta=with_cta))
    sent = None

    try:
        if item.image:
            sent = await bot.send_photo(
                chat_id=CHAT_ID, photo=URLInputFile(item.image),
                caption=_trim(text, CAPTION_LIMIT), parse_mode="HTML",
            )
        else:
            sent = await bot.send_message(
                chat_id=CHAT_ID, text=_trim(text, MESSAGE_LIMIT), parse_mode="HTML",
                disable_web_page_preview=True,
            )
    except Exception as e:
        logger.warning("[новости] с картинкой не вышло (%s), публикуем текстом", e)
        try:
            sent = await bot.send_message(
                chat_id=CHAT_ID, text=_trim(text, MESSAGE_LIMIT), parse_mode="HTML",
                disable_web_page_preview=True,
            )
        except Exception as e2:
            logger.error("[новости] публикация не удалась: %s", e2)
            return False

    remember("news", url=item.link, title=item.title, source=item.source,
             message_id=getattr(sent, "message_id", None))
    log_publication("news", item.title, with_cta=with_cta,
                    message_id=getattr(sent, "message_id", None))
    logger.info("[новости] опубликовано: %.60s (%s), ссылка на бота: %s",
                item.title, item.source, "да" if with_cta else "нет")
    return True


async def publish_plan_post(bot: Bot, post: dict, index: int) -> bool:
    with_cta = cta_due()
    text = strip_external_links(build_plan_text(post, with_cta=with_cta))
    try:
        sent = await bot.send_message(
            chat_id=CHAT_ID, text=_trim(text, MESSAGE_LIMIT), parse_mode="HTML",
            disable_web_page_preview=True,
        )
    except Exception as e:
        logger.error("[новости] пост плана %s не опубликован: %s", index, e)
        return False

    remember("plan", title=post.get("title", f"план {index}"), plan_index=index,
             message_id=getattr(sent, "message_id", None))
    log_publication("plan", post.get("title", f"план {index}"), with_cta=with_cta,
                    message_id=getattr(sent, "message_id", None))
    logger.info("[новости] опубликован пост плана %s: %.50s, ссылка на бота: %s",
                index, post.get("title", ""), "да" if with_cta else "нет")
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
