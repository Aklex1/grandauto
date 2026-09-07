"""Бета: пакетный импорт футажей из бесплатных стоков (Pexels, Pixabay).

Оба сервиса отдают видео по лицензии, разрешающей коммерческое использование,
поэтому такой видеоряд безопасно ставить в монетизируемые ролики — в отличие от
скачанного с YouTube. Модуль умеет только две вещи: искать кандидатов и скачивать
выбранные в библиотеку канала.
"""
from __future__ import annotations

import logging
from dataclasses import asdict, dataclass, field
from typing import Optional

import httpx
from sqlalchemy.orm import Session

from . import footage
from . import settings_store as st

log = logging.getLogger("cf.stock")

PEXELS_SEARCH = "https://api.pexels.com/videos/search"
PIXABAY_SEARCH = "https://pixabay.com/api/videos/"

PROVIDERS = {
    "pexels": {
        "title": "Pexels",
        "key_setting": "pexels_api_key",
        "key_url": "https://www.pexels.com/api/new/",
        "license": "Pexels License — свободно, в том числе коммерчески",
    },
    "pixabay": {
        "title": "Pixabay",
        "key_setting": "pixabay_api_key",
        "key_url": "https://pixabay.com/api/docs/",
        "license": "Pixabay Content License — свободно, в том числе коммерчески",
    },
}

# Ограничение по короткой стороне, а не по высоте: у вертикального ролика 1080x1920
# «высота» равна 1920, и проверка по высоте выбросила бы годный Full HD.
MAX_SHORT_SIDE = 1080
PREFERRED_SHORT_SIDE = 720
TIMEOUT = 45.0


class StockError(RuntimeError):
    """Стоку нечего ответить: нет ключа, лимит, сеть."""


@dataclass
class StockItem:
    """Один кандидат из выдачи стока — то, что показываем в панели и можем скачать."""

    provider: str
    external_id: str
    title: str
    download_url: str
    preview_url: str = ""
    page_url: str = ""
    author: str = ""
    tags: str = ""
    duration_sec: float = 0.0
    width: int = 0
    height: int = 0
    size_bytes: int = 0

    def as_dict(self) -> dict:
        return asdict(self)

    @property
    def orientation(self) -> str:
        if not self.width or not self.height:
            return "?"
        return "вертикальное" if self.height > self.width else "горизонтальное"


@dataclass
class SearchResult:
    items: list[StockItem] = field(default_factory=list)
    total: int = 0
    page: int = 1
    provider: str = ""
    query: str = ""


def api_key(session: Session, provider: str) -> str:
    meta = PROVIDERS.get(provider)
    if meta is None:
        raise StockError(f"Неизвестный сток: {provider}")
    return (st.get(session, meta["key_setting"], "") or "").strip()


def configured(session: Session) -> list[str]:
    """Стоки, для которых уже сохранён ключ."""
    return [name for name in PROVIDERS if api_key(session, name)]


def orientation_for_channel(aspect_ratio: str) -> str:
    return "portrait" if str(aspect_ratio or "").startswith("9:") else "landscape"


def _short_side(row: dict) -> int:
    """Короткая сторона кадра — по ней и оцениваем качество, независимо от ориентации."""
    width, height = int(row.get("width") or 0), int(row.get("height") or 0)
    if not width or not height:
        return max(width, height)
    return min(width, height)


def _pick_pexels_file(files: list[dict]) -> Optional[dict]:
    """Берём mp4 максимального качества в пределах 1080p, не ниже 720p по возможности."""
    usable = [f for f in files
              if (f.get("file_type") or "").endswith("mp4") and f.get("link")
              and _short_side(f) <= MAX_SHORT_SIDE]
    if not usable:
        usable = [f for f in files if f.get("link")]
    if not usable:
        return None
    good = [f for f in usable if _short_side(f) >= PREFERRED_SHORT_SIDE]
    pool = good or usable
    return max(pool, key=_short_side)


def _search_pexels(key: str, query: str, *, per_page: int, page: int,
                   orientation: str) -> SearchResult:
    params = {"query": query, "per_page": per_page, "page": page}
    if orientation in ("landscape", "portrait"):
        params["orientation"] = orientation
    try:
        with httpx.Client(timeout=TIMEOUT) as client:
            resp = client.get(PEXELS_SEARCH, params=params, headers={"Authorization": key})
            if resp.status_code == 401:
                raise StockError("Pexels отклонил ключ — проверьте его в настройках")
            if resp.status_code == 429:
                raise StockError("Pexels: исчерпан часовой лимит запросов, попробуйте позже")
            resp.raise_for_status()
            data = resp.json()
    except httpx.HTTPError as exc:
        raise StockError(f"Pexels недоступен: {exc}") from exc

    items: list[StockItem] = []
    for row in data.get("videos") or []:
        best = _pick_pexels_file(row.get("video_files") or [])
        if not best:
            continue
        user = row.get("user") or {}
        items.append(StockItem(
            provider="pexels",
            external_id=str(row.get("id") or ""),
            title=(row.get("alt") or "").strip() or f"Pexels {row.get('id')}",
            download_url=best.get("link") or "",
            preview_url=row.get("image") or "",
            page_url=row.get("url") or "",
            author=(user.get("name") or "").strip(),
            duration_sec=float(row.get("duration") or 0),
            width=int(best.get("width") or row.get("width") or 0),
            height=int(best.get("height") or row.get("height") or 0),
        ))
    return SearchResult(items=items, total=int(data.get("total_results") or 0),
                        page=page, provider="pexels", query=query)


def _pick_pixabay_file(videos: dict) -> Optional[dict]:
    """От большого к малому: первый вариант, который влезает в 1080p по короткой стороне."""
    order = ("large", "medium", "small", "tiny")
    for name in order:
        row = videos.get(name) or {}
        if row.get("url") and _short_side(row) <= MAX_SHORT_SIDE:
            return row
    # всё крупнее 1080p — берём самый мелкий из доступных, ffmpeg всё равно ужмёт
    for name in reversed(order):
        row = videos.get(name) or {}
        if row.get("url"):
            return row
    return None


def _pixabay_thumb(row: dict) -> str:
    """Pixabay кладёт превью в любой из вариантов видео — берём первое, что нашлось."""
    for name in ("large", "medium", "small", "tiny"):
        thumb = ((row.get("videos") or {}).get(name) or {}).get("thumbnail")
        if thumb:
            return thumb
    return ""


def _search_pixabay(key: str, query: str, *, per_page: int, page: int,
                    orientation: str) -> SearchResult:
    params = {"key": key, "q": query, "per_page": per_page, "page": page,
              "safesearch": "true"}
    try:
        with httpx.Client(timeout=TIMEOUT) as client:
            resp = client.get(PIXABAY_SEARCH, params=params)
            if resp.status_code in (400, 401, 403):
                raise StockError("Pixabay отклонил ключ — проверьте его в настройках")
            if resp.status_code == 429:
                raise StockError("Pixabay: исчерпан лимит запросов, попробуйте позже")
            resp.raise_for_status()
            data = resp.json()
    except httpx.HTTPError as exc:
        raise StockError(f"Pixabay недоступен: {exc}") from exc

    items: list[StockItem] = []
    for row in data.get("hits") or []:
        best = _pick_pixabay_file(row.get("videos") or {})
        if not best:
            continue
        width, height = int(best.get("width") or 0), int(best.get("height") or 0)
        # у Pixabay нет параметра ориентации — отсеиваем сами
        if width and height:
            if orientation == "portrait" and height <= width:
                continue
            if orientation == "landscape" and height > width:
                continue
        items.append(StockItem(
            provider="pixabay",
            external_id=str(row.get("id") or ""),
            title=(row.get("tags") or "").strip() or f"Pixabay {row.get('id')}",
            download_url=best.get("url") or "",
            preview_url=_pixabay_thumb(row),
            page_url=row.get("pageURL") or "",
            author=(row.get("user") or "").strip(),
            tags=(row.get("tags") or "").strip(),
            duration_sec=float(row.get("duration") or 0),
            width=width,
            height=height,
            size_bytes=int(best.get("size") or 0),
        ))
    return SearchResult(items=items, total=int(data.get("totalHits") or 0),
                        page=page, provider="pixabay", query=query)


def search(session: Session, provider: str, query: str, *, per_page: int = 24, page: int = 1,
           orientation: str = "landscape", min_duration: float = 0.0) -> SearchResult:
    """Ищем кандидатов в стоке. Ничего не скачиваем — только метаданные и превью."""
    query = (query or "").strip()
    if not query:
        raise StockError("Пустой запрос")
    key = api_key(session, provider)
    if not key:
        meta = PROVIDERS[provider]
        raise StockError(f"Не задан ключ {meta['title']}. Получите бесплатно: {meta['key_url']}")

    per_page = max(3, min(50, per_page))
    page = max(1, page)
    if provider == "pexels":
        result = _search_pexels(key, query, per_page=per_page, page=page, orientation=orientation)
    else:
        result = _search_pixabay(key, query, per_page=per_page, page=page, orientation=orientation)

    if min_duration > 0:
        result.items = [i for i in result.items if i.duration_sec >= min_duration]
    log.info("Сток %s: «%s» — %s кандидатов", provider, query, len(result.items))
    return result


def suggest_tags(query: str, item: StockItem) -> str:
    """Теги для библиотеки: слова запроса плюс то, что отдал сток."""
    parts = [p.strip() for p in (query or "").replace(",", " ").split() if len(p.strip()) > 2]
    parts += [p.strip() for p in (item.tags or "").split(",") if p.strip()]
    seen, out = set(), []
    for part in parts:
        low = part.lower()
        if low not in seen:
            seen.add(low)
            out.append(low)
    return ", ".join(out[:12])


def import_items(session: Session, items: list[dict], *, channel_id: Optional[int],
                 channel_slug: Optional[str], query: str = "",
                 extra_tags: str = "") -> tuple[int, list[str]]:
    """Скачиваем выбранные ролики в библиотеку. Возвращаем сколько добавлено и что не вышло."""
    added, problems = 0, []
    for raw in items:
        item = StockItem(**{k: v for k, v in raw.items() if k in StockItem.__annotations__})
        if not item.download_url:
            problems.append(f"{item.title}: нет ссылки на файл")
            continue
        tags = ", ".join(t for t in (suggest_tags(query, item), extra_tags.strip()) if t)
        meta = PROVIDERS.get(item.provider, {})
        try:
            footage.add_from_url(
                session, item.download_url,
                channel_id=channel_id, channel_slug=channel_slug,
                title=item.title[:200], tags=tags,
                provider=item.provider,
                author=item.author,
                license_note=meta.get("license", ""),
                page_url=item.page_url,
            )
            added += 1
        except Exception as exc:  # noqa: BLE001 — один битый ролик не должен рушить пачку
            problems.append(f"{item.title}: {exc}")
    log.info("Импорт из стока: добавлено %s, ошибок %s", added, len(problems))
    return added, problems
