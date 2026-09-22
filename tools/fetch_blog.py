#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Тянет 6 свежих постов и рисует равномерные карточки блога.
У постов без обложки — SVG-заглушка (градиент + глиф), чтобы плитка не прыгала.

    python3 tools/fetch_blog.py > content/landings/_blog.html
"""
import html, json, re, urllib.request
from pathlib import Path

E = html.escape
SITE = "https://genius-bot.ru"
ACCENTS = [("#818cf8", "#a5b4fc"), ("#22d3ee", "#67e8f9"), ("#a78bfa", "#c4b5fd"),
           ("#38bdf8", "#7dd3fc"), ("#fbbf24", "#fcd34d"), ("#34d399", "#6ee7b7")]

# глифы-заглушки (line SVG), по одному на карточку по кругу
GLYPHS = [
    'M14 10h20v28H14zM19 18h10M19 24h10M19 30h6',                       # документ
    'M12 24c4-8 20-8 24 0-4 8-20 8-24 0zM24 20a4 4 0 1 0 0 8 4 4 0 0 0 0-8z',  # идея/глаз
    'M10 32l8-10 6 7 5-6 9 11zM17 16a3 3 0 1 0 0 6 3 3 0 0 0 0-6z',    # картинка
    'M24 8v20M16 20l8 8 8-8M12 34h24',                                  # загрузка
    'M14 14h20v20H14zM14 22h20M22 14v20',                              # сетка
    'M24 10a14 14 0 1 0 0 28 14 14 0 0 0 0-28zM24 16v8l6 4',           # часы
]


def api(path):
    with urllib.request.urlopen(f"{SITE}{path}", timeout=40) as r:
        return json.loads(r.read().decode())


def placeholder(i):
    a, b = ACCENTS[i % len(ACCENTS)]
    glyph = GLYPHS[i % len(GLYPHS)]
    return (
        f'<span class="gp-post__ph">'
        f'<svg viewBox="0 0 48 48" fill="none" stroke="url(#pg{i})" stroke-width="2" '
        f'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
        f'<defs><linearGradient id="pg{i}" x1="0" y1="0" x2="1" y2="1">'
        f'<stop offset="0" stop-color="{a}"/><stop offset="1" stop-color="{b}"/></linearGradient></defs>'
        f'<path d="{glyph}"/></svg></span>')


def main():
    posts = api("/wp-json/wp/v2/posts?per_page=6&_embed=1"
                "&_fields=title,link,excerpt,date,_embedded,_links")
    cards = []
    for i, p in enumerate(posts):
        title = re.sub("<[^>]+>", "", p["title"]["rendered"]).strip()
        excerpt = re.sub("<[^>]+>", "", p["excerpt"]["rendered"]).strip()
        excerpt = re.sub(r"\s+", " ", excerpt)
        if len(excerpt) > 120:
            excerpt = excerpt[:117].rstrip() + "…"
        link = p["link"]
        media = (p.get("_embedded", {}).get("wp:featuredmedia") or [{}])[0]
        img = media.get("source_url", "")
        # берём средний размер, если есть
        sizes = (media.get("media_details", {}) or {}).get("sizes", {})
        if sizes.get("medium_large"):
            img = sizes["medium_large"]["source_url"]
        elif sizes.get("large"):
            img = sizes["large"]["source_url"]
        top = (f'<img class="gp-post__img" src="{E(img)}" alt="" loading="lazy">'
               if img else placeholder(i))
        cards.append(
            f'<a class="gp-post" href="{E(link)}">'
            f'<span class="gp-post__top">{top}</span>'
            f'<span class="gp-post__body"><span class="gp-post__title">{E(title)}</span>'
            f'<span class="gp-post__ex">{E(excerpt)}</span></span></a>')
    print("".join(cards))


if __name__ == "__main__":
    main()
