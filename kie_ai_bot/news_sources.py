"""
Источники новостей про ИИ и разбор RSS.

Профильные ленты берутся целиком, общетехнические — только материалы про
искусственный интеллект, по ключевым словам в заголовке и описании.
Картинка ищется в самой ленте, а если её там нет — в og:image страницы.

Все ленты проверены на отдачу и наличие картинок.
"""

import logging
import os
import re
import xml.etree.ElementTree as ET
from dataclasses import dataclass
from datetime import datetime, timezone
from html import unescape
from typing import List, Optional

import httpx

logger = logging.getLogger("news.sources")

UA = "Mozilla/5.0 (compatible; NeuroHubBot/1.0)"

MEDIA_NS = "{http://search.yahoo.com/mrss/}"
CONTENT_NS = "{http://purl.org/rss/1.0/modules/content/}"


@dataclass
class Source:
    name: str        # как подписываем в посте
    url: str         # адрес ленты
    profile: bool    # профильная лента про ИИ: брать всё подряд


# Профильные ленты берём целиком, общие — фильтруем по теме
SOURCES: List[Source] = [
    Source("Хабр", "https://habr.com/ru/rss/hubs/artificial_intelligence/articles/?fl=ru", True),
    Source("Хабр", "https://habr.com/ru/rss/hubs/machine_learning/articles/?fl=ru", True),
    Source("NeuroHive", "https://neurohive.io/ru/feed/", True),
    Source("3DNews", "https://3dnews.ru/news/rss/", False),
    Source("iXBT", "https://www.ixbt.com/export/news.rss", False),
    Source("CNews", "https://www.cnews.ru/inc/rss/news.xml", False),
    Source("Ferra", "https://www.ferra.ru/exports/rss.xml", False),
    Source("vc.ru", "https://vc.ru/rss/all", False),
    Source("RB.RU", "https://rb.ru/feeds/all/", False),
    Source("Tproger", "https://tproger.ru/feed/", False),
    Source("Naked Science", "https://naked-science.ru/?feed=rss", False),
]

# Сильные признаки темы: слово должно означать именно ИИ, а не что угодно
# рядом с ним. Общие «ai», «обучение», «языковой» убраны намеренно — по ним
# в ленту лезли обзоры процессоров Ryzen AI и статьи про курсы.
AI_KEYWORDS = [
    "нейросет", "нейронн", "нейросеть", "искусственный интеллект",
    "искусственного интеллекта", "искусственным интеллектом",
    " ии ", " ии,", " ии.", "ии-", "ии:", "chatgpt", "gpt-", "gpt ",
    "llm", "языкова модел", "языковой модел", "языковые модел",
    "машинное обучение", "машинного обучения", "генеративн",
    "openai", "anthropic", "deepseek", "midjourney", "stable diffusion",
    "gemini", "claude", "нейроарт", "промпт", "дипфейк", "deepfake",
    "ai-агент", "ии-агент", "ии-модел", "ai-модел", "чат-бот", "чатбот",
]

# Если заголовок про железо или гаджеты — это не новость про ИИ, даже когда
# в названии продукта есть «AI». Такие материалы отсекаются до проверки темы.
HARDWARE_STOP_WORDS = [
    "ноутбук", "мини-пк", "минипк", "смартфон", "планшет", "видеокарт",
    "процессор", "монитор", "наушник", "ssd", "материнск", "блок питания",
    "клавиатур", "мышь", "роутер", "телевизор", "часы", "пылесос",
    "холодильник", "камера", "объектив", "консол", "гб озу", "тб ssd",
    "ryzen", "core ultra", "geforce", "radeon", "snapdragon", "ifa 20",
    "распродаж", "скидк", "цена упала", "подешевел", "вышел в продажу",
]


def _text(el: Optional[ET.Element]) -> str:
    return (el.text or "").strip() if el is not None else ""


def _strip_html(text: str) -> str:
    text = re.sub(r"<[^>]+>", " ", text or "")
    return re.sub(r"\s+", " ", unescape(text)).strip()


def _image_from_item(item: ET.Element) -> Optional[str]:
    """Картинка из самой ленты: enclosure, media:content или первый img."""
    for tag in ("enclosure", f"{MEDIA_NS}content", f"{MEDIA_NS}thumbnail"):
        el = item.find(tag)
        if el is not None:
            url = (el.get("url") or "").strip()
            if url:
                return url

    for tag in ("description", f"{CONTENT_NS}encoded"):
        el = item.find(tag)
        if el is not None and el.text:
            m = re.search(r'<img[^>]+src="([^"]+)"', el.text)
            if m:
                return m.group(1)
    return None


async def fetch_og_image(client: httpx.AsyncClient, url: str) -> Optional[str]:
    """Картинка со страницы материала — там, где лента её не отдаёт."""
    try:
        resp = await client.get(url, headers={"User-Agent": UA}, timeout=25,
                                follow_redirects=True)
        html = resp.text[:300000]
    except Exception as e:
        logger.debug("[новости] страница %s недоступна: %s", url, e)
        return None

    for pattern in (
        r'<meta[^>]+property="og:image"[^>]+content="([^"]+)"',
        r'<meta[^>]+content="([^"]+)"[^>]+property="og:image"',
        r'<meta[^>]+name="twitter:image"[^>]+content="([^"]+)"',
    ):
        m = re.search(pattern, html, re.I)
        if m:
            return unescape(m.group(1))
    return None


# Материал из общей ленты берём, только если тема заявлена в заголовке.
# Упоминание ИИ в описании ещё ничего не значит: так проходят обзоры техники,
# где нейросети названы одной строкой среди прочего.
STRICT_TITLE_MATCH = os.getenv(
    "NEWS_STRICT_TITLE_MATCH", "1"
).strip().lower() in ("1", "true", "yes", "on")


def looks_like_hardware(title: str) -> bool:
    """Заголовок про железо, гаджет или распродажу — не наша тема."""
    lowered = f" {title} ".lower()
    return any(word in lowered for word in HARDWARE_STOP_WORDS)


def is_about_ai(title: str, summary: str) -> bool:
    """Материал действительно про искусственный интеллект."""
    if looks_like_hardware(title):
        return False

    haystack = f" {title} ".lower() if STRICT_TITLE_MATCH else f" {title} {summary} ".lower()
    return any(word in haystack for word in AI_KEYWORDS)


@dataclass
class NewsItem:
    title: str
    link: str
    summary: str
    image: Optional[str]
    source: str
    published: Optional[datetime]


def _parse_date(value: str) -> Optional[datetime]:
    for fmt in ("%a, %d %b %Y %H:%M:%S %z", "%a, %d %b %Y %H:%M:%S %Z",
                "%Y-%m-%dT%H:%M:%S%z", "%Y-%m-%dT%H:%M:%SZ"):
        try:
            parsed = datetime.strptime(value.strip(), fmt)
            return parsed if parsed.tzinfo else parsed.replace(tzinfo=timezone.utc)
        except (ValueError, AttributeError):
            continue
    return None


async def fetch_source(client: httpx.AsyncClient, source: Source, limit: int = 20) -> List[NewsItem]:
    """Материалы одной ленты, уже отфильтрованные по теме."""
    try:
        resp = await client.get(source.url, headers={"User-Agent": UA},
                                timeout=30, follow_redirects=True)
        resp.raise_for_status()
        root = ET.fromstring(resp.content)
    except Exception as e:
        logger.warning("[новости] лента %s недоступна: %s", source.name, e)
        return []

    items = []
    for item in (root.findall(".//item") or [])[:limit]:
        title = _strip_html(_text(item.find("title")))
        link = _text(item.find("link"))
        summary = _strip_html(_text(item.find("description")))[:600]
        if not title or not link:
            continue
        if not source.profile and not is_about_ai(title, summary):
            continue

        items.append(NewsItem(
            title=title,
            link=link,
            summary=summary,
            image=_image_from_item(item),
            source=source.name,
            published=_parse_date(_text(item.find("pubDate"))),
        ))
    return items


async def fetch_all(limit_per_source: int = 20) -> List[NewsItem]:
    """Свежие материалы про ИИ со всех лент, новые сверху."""
    collected: List[NewsItem] = []
    async with httpx.AsyncClient(timeout=30, follow_redirects=True) as client:
        for source in SOURCES:
            items = await fetch_source(client, source, limit_per_source)
            if items:
                logger.info("[новости] %s: подходящих материалов %s", source.name, len(items))
            collected.extend(items)

    collected.sort(key=lambda i: i.published or datetime.min.replace(tzinfo=timezone.utc),
                   reverse=True)
    return collected
