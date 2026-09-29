"""
Автопостинг в канал про нейросети: свои посты по плану плюс новости.

За сутки выходит четыре поста:
* один из контент-плана на три месяца (content_plan.json);
* один разбор «как это применить» — собрал бота, автоматизировал рутину,
  заработал на нейросетях;
* два — свежие новости про ИИ с русскоязычных порталов.

Порядок постов задаётся в NEWS_SLOTS, часы — в NEWS_SCHEDULE_HOURS.

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
from datetime import datetime, timedelta, timezone
from html import escape
from pathlib import Path
from typing import List, Optional

from aiogram import Bot
from aiogram.types import URLInputFile

import news_sources
import news_writer

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
# Разборы «как это применить»: собрал бота, автоматизировал рутину, заработал
CASES_PER_DAY = _env_int("NEWS_CASES_PER_DAY", 1)

# Часы публикаций (UTC). По умолчанию 07:00, 12:00 и 16:00 UTC —
# это 10:00, 15:00 и 19:00 по Москве
SCHEDULE_HOURS = [
    int(h) for h in os.getenv("NEWS_SCHEDULE_HOURS", "7,12,16,19").split(",")
    if h.strip().isdigit()
]
# Что выходит в каждый час расписания. Кейс ставим в середину дня — такие
# посты читают внимательнее, чем новости
SLOTS = [s.strip() for s in os.getenv("NEWS_SLOTS", "plan,news,case,news").split(",")
         if s.strip() in ("plan", "news", "case")]
# Как часто проверять расписание, секунды
TICK_INTERVAL = _env_int("NEWS_TICK_INTERVAL", 300)
# Сколько новостей показать отбору, прежде чем сдаться. Отбраковка — норма:
# в лентах хватает раундов инвестиций и бенчмарков, которые нашему читателю
# не нужны, поэтому за один слот проверяем несколько материалов подряд
WRITER_CANDIDATES = _env_int("NEWS_WRITER_CANDIDATES", 6)

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
# Партнёрские ссылки, которые в постах разрешены (аренда хостинга под проект).
# Их мы ставим сами в практических постах — не чужие ссылки из лент
AFFILIATE_HOSTS = [h.strip().lower() for h in
                   os.getenv("NEWS_AFFILIATE_HOSTS", "beget.com").split(",") if h.strip()]

# Заголовок рубрики над разбором — читатель сразу видит, что это не новость
CASE_HEADER = os.getenv("NEWS_CASE_HEADER", "🛠 Как это применить")

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
    if any(host in lowered for host in AFFILIATE_HOSTS):
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

def shorten(text: str, limit: int = 450) -> str:
    """Короткое описание для поста. Режем по концу предложения — обрыв на
    середине фразы («...поэтому у меня получилась вот такая схема: Т.е...»)
    выглядит неряшливо."""
    text = (text or "").strip()
    if len(text) <= limit:
        return text

    cut = text[:limit]
    end = max(cut.rfind(". "), cut.rfind("! "), cut.rfind("? "),
              cut.rfind(".\n"), cut.rfind("…"))
    if end > limit // 2:
        return cut[: end + 1].strip()
    return cut.rsplit(" ", 1)[0].rstrip(" ,;:—-") + "..."


def build_news_text(item: news_sources.NewsItem, with_cta: bool = False) -> str:
    """Текст новости. Ссылок на сторонние сайты не ставим: источник
    указывается словом, чтобы не уводить читателя из канала.
    Ссылка на бота добавляется только там, где её ждёт очередь (with_cta)."""
    summary = shorten(item.summary)

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


def build_case_text(item: news_sources.NewsItem, with_cta: bool = False,
                    article_url: Optional[str] = None) -> str:
    """Разбор применения: та же вёрстка, что у новости, но с рубрикой сверху,
    ссылкой на подробную статью и партнёрской ссылкой на хостинг."""
    import blog_publisher

    summary = shorten(item.summary)

    parts = [f"<b>{escape(CASE_HEADER)}</b>", f"<b>{escape(item.title)}</b>"]
    if summary:
        parts.append(escape(summary))
    if SHOW_SOURCE_NAME and item.source:
        parts.append(f"<i>Источник: {escape(item.source)}</i>")

    # Ссылка на подробную статью-инструкцию на сайте — призывом
    if article_url:
        parts.append(f'📖 <a href="{escape(article_url)}">'
                     f'{escape(blog_publisher.ARTICLE_CTA)}</a>')

    # Партнёрской ссылки на хостинг в посте канала нет намеренно. Она стоит
    # в самой статье, куда ведёт призыв выше, и там она к месту: человек уже
    # читает инструкцию по запуску. В посте же она была третьей ссылкой
    # подряд, и лента превращалась в рекламный блок.

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

async def _page_image(link: str) -> Optional[str]:
    """Картинка со страницы материала — запасной вариант для публикации."""
    try:
        import httpx

        async with httpx.AsyncClient(timeout=25, follow_redirects=True) as client:
            return await news_sources.fetch_og_image(client, link)
    except Exception as e:
        logger.debug("[новости] картинка со страницы недоступна: %s", e)
        return None


def dress_draft(text: str, with_cta: bool = False, article_url: str = "") -> str:
    """Готовый черновик от редактора: добавляем ссылку на разбор, ссылку на
    бота и подвал. Сам текст не трогаем — он уже собран по шаблону канала."""
    parts = [text.strip()]
    if article_url:
        import blog_publisher

        parts.append(f'📖 <a href="{escape(article_url)}">'
                     f'{escape(blog_publisher.ARTICLE_CTA)}</a>')
    if with_cta and BOT_URL and BOT_CTA:
        parts.append(f'<a href="{escape(BOT_URL)}">{escape(BOT_CTA)}</a>')
    if FOOTER:
        parts.append(FOOTER)
    return "\n\n".join(p for p in parts if p)


async def case_article_url(item: news_sources.NewsItem) -> str:
    """Адрес подробной статьи-инструкции на сайте под тему разбора.

    Статья одна на тему, а не одна на кейс: раньше каждый разбор заводил на
    сайте новую запись с тем же заголовком, и в блоге накопилось восемь
    «Как собрать телеграм-бота». Теперь издатель возвращает адрес уже
    существующей статьи, если она есть."""
    import blog_publisher

    try:
        return await blog_publisher.publish_article(item.title, item.summary) or ""
    except Exception as e:
        logger.warning("[кейсы] статью на сайт опубликовать не вышло: %s", e)
        return ""


async def publish_item(bot: Bot, item: news_sources.NewsItem, kind: str = "news",
                       draft_text: str = "") -> bool:
    """Публикует материал из ленты: новость или разбор «как это применить».
    Различаются только вёрсткой текста и пометкой в журнале.

    draft_text — готовый пост от редактора (news_writer). Если он есть,
    пересказ ленты не собираем: в нём нет ни мнения, ни пользы читателю."""
    label = {"case": "кейсы", "rubric": "рубрики"}.get(kind, "новости")
    with_cta = cta_due()

    if draft_text:
        text = strip_external_links(dress_draft(
            draft_text, with_cta=with_cta,
            article_url=(await case_article_url(item) if kind == "case" else "")))
    elif kind == "case":
        text = strip_external_links(build_case_text(
            item, with_cta=with_cta, article_url=await case_article_url(item)))
    else:
        text = strip_external_links(build_news_text(item, with_cta=with_cta))
    sent = None

    async def send_with_photo(url: str):
        return await bot.send_photo(
            chat_id=CHAT_ID, photo=URLInputFile(url),
            caption=_trim(text, CAPTION_LIMIT), parse_mode="HTML",
        )

    async def send_plain():
        return await bot.send_message(
            chat_id=CHAT_ID, text=_trim(text, MESSAGE_LIMIT), parse_mode="HTML",
            disable_web_page_preview=True,
        )

    if item.image:
        try:
            sent = await send_with_photo(item.image)
        except Exception as e:
            logger.warning("[%s] картинка из ленты не подошла (%s)", label, e)

    # Адрес из ленты Telegram берёт не всегда: у 3DNews картинка лежит на
    # cdn-домене, а в ленте указан основной. Пробуем картинку со страницы
    if sent is None and item.link:
        alternative = await _page_image(item.link)
        if alternative and alternative != item.image:
            try:
                sent = await send_with_photo(alternative)
                logger.info("[%s] помогла картинка со страницы материала", label)
            except Exception as e:
                logger.warning("[%s] картинка со страницы тоже не подошла: %s", label, e)

    if sent is None:
        try:
            sent = await send_plain()
        except Exception as e:
            logger.error("[%s] публикация не удалась: %s", label, e)
            return False

    remember(kind, url=item.link, title=item.title, source=item.source,
             message_id=getattr(sent, "message_id", None))
    log_publication(kind, item.title, with_cta=with_cta,
                    message_id=getattr(sent, "message_id", None))
    logger.info("[%s] опубликовано: %.60s (%s), ссылка на бота: %s",
                label, item.title, item.source, "да" if with_cta else "нет")
    return True


async def publish_news(bot: Bot, item: news_sources.NewsItem, draft_text: str = "") -> bool:
    return await publish_item(bot, item, "news", draft_text=draft_text)


async def publish_case(bot: Bot, item: news_sources.NewsItem) -> bool:
    return await publish_item(bot, item, "case")


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


async def pick_fresh(limit: int = 1, kind: str = "news") -> List[news_sources.NewsItem]:
    """Свежие материалы, которых ещё не было в канале."""
    items = (await news_sources.fetch_cases() if kind == "case"
             else await news_sources.fetch_all())
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


async def pick_fresh_news(limit: int = 1) -> List[news_sources.NewsItem]:
    return await pick_fresh(limit, "news")


async def pick_fresh_cases(limit: int = 1) -> List[news_sources.NewsItem]:
    return await pick_fresh(limit, "case")


async def publish_one_news(bot: Bot) -> bool:
    """
    Одна новость в канал.

    При включённом редакторе (NEWS_WRITER_ENABLED) новость сначала проходит
    отбор: полезна ли она обычному человеку. Отбракованную помечаем как
    просмотренную — иначе она будет всплывать каждый час и каждый раз стоить
    запроса к модели. Если редактор выключен или не ответил, публикуем
    по-старому: пересказ ленты хуже поста по шаблону, но лучше тишины.
    """
    limit = WRITER_CANDIDATES if news_writer.ENABLED else 1
    items = await pick_fresh(limit=limit, kind="news")
    if not items:
        logger.info("[новости] свежих материалов не нашлось — все уже выходили")
        return False

    if not news_writer.ENABLED:
        return await publish_news(bot, items[0])

    for item in items:
        try:
            draft = await news_writer.write(item.title, item.summary, item.source)
        except Exception as e:
            logger.warning("[новости] редактор не сработал (%s) — публикуем как есть", e)
            return await publish_news(bot, item)

        if not draft.useful:
            remember("skip", url=item.link, title=item.title, source=item.source)
            continue
        if not draft.text:
            logger.info("[новости] редактор не дал текста (%s) — публикуем как есть",
                        draft.reason)
            return await publish_news(bot, item)

        # Предпросмотр: черновик от модели сначала смотрит человек. Слот
        # считается занятым — иначе бот в тот же час возьмёт следующую
        # новость и завалит модератора черновиками.
        import news_moderation

        if news_moderation.enabled():
            news_moderation.expire_old()
            if await news_moderation.send_for_review(bot, item, draft.text, "news"):
                return True
            logger.warning("[новости] черновик не доставлен модераторам — публикуем сами")

        return await publish_news(bot, item, draft_text=draft.text)

    logger.info("[новости] отбор не пропустил ни одной из %d новостей", len(items))
    return False


async def publish_one_case(bot: Bot) -> bool:
    """
    Разбор «как это применить». Если подходящего нет — отдаём слот новости,
    чтобы час расписания не пропал впустую.

    Разбор проходит тот же путь, что и новость: отбор на пользу, черновик
    редактора, предпросмотр у модератора. Раньше кейс шёл мимо редактора и
    выходил пересказом ленты с тремя ссылками подряд — правки по качеству
    его просто не касались.
    """
    limit = WRITER_CANDIDATES if news_writer.ENABLED else 1
    items = await pick_fresh(limit=limit, kind="case")
    if not items:
        logger.info("[кейсы] новых разборов не нашлось — публикуем новость")
        return await publish_one_news(bot)

    if not news_writer.ENABLED:
        return await publish_case(bot, items[0])

    for item in items:
        try:
            draft = await news_writer.write(item.title, item.summary, item.source)
        except Exception as e:
            logger.warning("[кейсы] редактор не сработал (%s) — публикуем как есть", e)
            return await publish_case(bot, item)

        if not draft.useful:
            remember("skip", url=item.link, title=item.title, source=item.source)
            continue
        if not draft.text:
            logger.info("[кейсы] редактор не дал текста (%s) — публикуем как есть",
                        draft.reason)
            return await publish_case(bot, item)

        import news_moderation

        if news_moderation.enabled():
            news_moderation.expire_old()
            if await news_moderation.send_for_review(bot, item, draft.text, "case"):
                return True
            logger.warning("[кейсы] черновик не доставлен модераторам — публикуем сами")

        return await publish_item(bot, item, "case", draft_text=draft.text)

    logger.info("[кейсы] отбор не пропустил ни одного из %d разборов", len(items))
    return False


# Какая рубрика у какого дня недели. Ключевое слово ищем в названии рубрики
# поста: в плане они с эмодзи и уточнениями («🎯 Челлендж недели»), и
# сравнивать строки целиком было бы хрупко.
WEEKDAY_RUBRICS = {
    0: "главное за неделю",
    1: "промпт дня",
    2: "проверил сам",
    3: "батл",
    4: "кейс",
    5: "нейрофейл",
    6: "челлендж",
}


def published_plan_indexes() -> set:
    """Номера постов плана, которые уже выходили."""
    with closing(_connect()) as conn:
        rows = conn.execute(
            "SELECT plan_index FROM news_posts WHERE kind = 'plan' AND plan_index IS NOT NULL"
        ).fetchall()
    return {int(r["plan_index"]) for r in rows}


def pick_plan_post(plan: List[dict], weekday: Optional[int] = None) -> Optional[tuple]:
    """
    Пост плана на сегодня: (индекс, пост).

    Сначала ищем невышедший пост с рубрикой этого дня недели — понедельник
    открывается главным за неделю, воскресенье закрывается челленджем.
    Если такого нет (рубрика исчерпана), берём ближайший невышедший по
    порядку: пустой слот хуже, чем пост не по дню.
    """
    done = published_plan_indexes()
    if weekday is None:
        weekday = datetime.now(timezone.utc).weekday()
    wanted = WEEKDAY_RUBRICS.get(weekday, "")

    if wanted:
        for index, post in enumerate(plan):
            if index in done:
                continue
            if wanted in str(post.get("rubric", "")).lower():
                return index, post

    for index, post in enumerate(plan):
        if index not in done:
            return index, post
    return None


def recent_rubric_topics(rubric: str, days: int = 21) -> List[str]:
    """
    О чём эта рубрика уже выходила за последние недели.

    Без этого списка модель раз за разом выдаёт одну и ту же тему: «промпт
    дня» пять раз подряд оказывался про конспект. Материала рубрике не
    нужно, новизне взяться неоткуда — значит, её нужно задать явно.
    """
    since = (datetime.now(timezone.utc) - timedelta(days=days)).date().isoformat()
    with closing(_connect()) as conn:
        rows = conn.execute(
            "SELECT title FROM news_posts WHERE kind IN ('rubric', 'plan') "
            "AND published_at >= ? ORDER BY published_at DESC LIMIT 40",
            (since,),
        ).fetchall()
    out = []
    for row in rows:
        title = (row["title"] or "").strip()
        if rubric and rubric.lower() not in title.lower():
            continue
        # В заголовке лежит «рубрика: тема» — интересна вторая половина.
        topic = title.split(":", 1)[-1].strip()
        if topic and topic not in out:
            out.append(topic)
    return out[:12]


async def publish_generated_rubric(bot: Bot) -> bool:
    """
    Рубричный пост, собранный редактором из свежего материала.

    Нужен, когда контент-план на этот день исчерпан: рубрика у дня остаётся
    (воскресенье — челлендж, вторник — промпт), а готового поста в плане уже
    нет. Материал берём из лент по числу, которое рубрике нужно: дайджесту
    «главное за неделю» — пять новостей, «проверил сам» — одну, а промпту и
    челленджу материал не нужен вовсе.

    Использованные новости помечаем вышедшими: иначе они уйдут ещё раз
    отдельным постом.
    """
    if not news_writer.ENABLED:
        return False

    weekday = datetime.now(timezone.utc).weekday()
    rubric = WEEKDAY_RUBRICS.get(weekday, "")
    spec = news_writer.RUBRIC_SPECS.get(rubric)
    if not spec:
        return False

    need = int(spec.get("items", 0))
    items = await pick_fresh(limit=need, kind="news") if need else []
    if need and not items:
        logger.info("[рубрики] для «%s» нет свежего материала", rubric)
        return False

    draft = await news_writer.write_rubric(
        rubric,
        [{"title": i.title, "summary": i.summary, "source": i.source} for i in items],
        avoid=recent_rubric_topics(rubric),
    )
    if not draft.useful or not draft.text:
        logger.info("[рубрики] «%s» не собралась: %s", rubric, draft.reason)
        return False

    # Рубричный пост идёт без картинки из ленты: она к нему не относится.
    carrier = items[0] if items else None
    item = news_sources.NewsItem(
        title=f"{rubric}: {draft.text.splitlines()[0][:80]}",
        summary="", source=carrier.source if carrier else "",
        link=carrier.link if carrier else "", image="", published=None,
    )

    import news_moderation

    if news_moderation.enabled():
        news_moderation.expire_old()
        if await news_moderation.send_for_review(bot, item, draft.text):
            for used in items:
                remember("news", url=used.link, title=used.title, source=used.source)
            logger.info("[рубрики] «%s» ушла на проверку", rubric)
            return True

    published = await publish_item(bot, item, kind="rubric", draft_text=draft.text)
    if published:
        for used in items:
            remember("news", url=used.link, title=used.title, source=used.source)
        logger.info("[рубрики] «%s» опубликована редактором (%s)", rubric, draft.model)
    return published


async def publish_one_plan(bot: Bot) -> bool:
    plan = load_plan()
    chosen = pick_plan_post(plan) if plan else None
    if chosen is None:
        if plan:
            logger.info("[новости] контент-план закончился (%s постов) — собираем рубрику сами",
                        len(plan))
        return await publish_generated_rubric(bot)
    index, post = chosen
    logger.info("[новости] пост плана №%s, рубрика «%s»", index, post.get("rubric", ""))
    return await publish_plan_post(bot, post, index)


# --- Расписание ------------------------------------------------------------

def _slot_plan() -> List[str]:
    """Что публикуем в каждый час расписания. Порядок берём из NEWS_SLOTS,
    а если он не задан — собираем из суточных лимитов."""
    slots = list(SLOTS) or (["plan"] * PLAN_PER_DAY + ["case"] * CASES_PER_DAY
                            + ["news"] * NEWS_PER_DAY)
    if len(slots) < len(SCHEDULE_HOURS):
        slots += ["news"] * (len(SCHEDULE_HOURS) - len(slots))
    return slots[: len(SCHEDULE_HOURS)]


def _daily_limit(kind: str) -> int:
    """Сколько постов этого вида должно выйти за сутки."""
    from_slots = _slot_plan().count(kind)
    if from_slots:
        return from_slots
    return {"plan": PLAN_PER_DAY, "case": CASES_PER_DAY}.get(kind, NEWS_PER_DAY)


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
        "[новости] запущено: канал %s, расписание %s UTC, слоты %s",
        CHAT_ID, SCHEDULE_HOURS, slots,
    )

    while True:
        try:
            now = datetime.now(timezone.utc)
            for position, hour in enumerate(SCHEDULE_HOURS):
                if now.hour != hour:
                    continue

                kind = slots[position] if position < len(slots) else "news"
                # Черновик на проверке — это уже занятый слот. Иначе за час
                # ожидания решения бот собирает дюжину почти одинаковых
                # постов: расписание проверяется каждые пять минут, а
                # счётчик публикаций стоит на месте.
                waiting = 0
                try:
                    import news_moderation

                    if news_moderation.enabled():
                        waiting = news_moderation.pending_today(kind)
                except Exception as e:
                    logger.warning("[новости] не посчитать черновики на проверке: %s", e)
                if posted_today(kind) + waiting >= _daily_limit(kind):
                    continue

                if kind == "plan":
                    await publish_one_plan(bot)
                elif kind == "case":
                    await publish_one_case(bot)
                else:
                    await publish_one_news(bot)
                break
        except Exception as e:
            logger.error("[новости] ошибка расписания: %s", e, exc_info=True)

        await asyncio.sleep(TICK_INTERVAL)
