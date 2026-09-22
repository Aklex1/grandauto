#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Публикация лендингов и главной на genius-bot.ru через REST API WordPress.

Требует пароль приложения и установленного mu-плагина
wordpress/mu-plugins/000-rest-basic-auth-fix.php — без него nginx + php-fpm
не отдают ядру PHP_AUTH_USER, и WordPress отвечает rest_not_logged_in даже с верным паролем.

    export WP_USER=bot
    export WP_APP_PASSWORD='xxxx xxxx xxxx xxxx xxxx xxxx'
    python3 tools/wp_publish.py --check          # только проверить доступ
    python3 tools/wp_publish.py --images         # залить картинки в медиатеку
    python3 tools/wp_publish.py --pages          # создать/обновить страницы
    python3 tools/wp_publish.py --images --pages

Страницы создаются черновиками. Публикует человек — чтобы полуготовая главная
не уехала в индекс.
"""
from __future__ import annotations

import argparse
import base64
import json
import mimetypes
import os
import sys
import urllib.error
import urllib.parse
import urllib.request
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
SITE = os.environ.get("WP_SITE", "https://genius-bot.ru").rstrip("/")
USER = os.environ.get("WP_USER", "")
PASSWORD = os.environ.get("WP_APP_PASSWORD", "")

PAGES = [
    ("home", "ИИ-ассистенты Genius — помощники для учителя, учёбы, документов и бизнеса",
     "ai-pomoshnik", None),
    ("uchitel", None, "dlya-uchitelya", "ai-pomoshnik"),
    ("ucheba", None, "dlya-ucheby", "ai-pomoshnik"),
    ("yurist", None, "yurist", "ai-pomoshnik"),
    ("biznes", None, "dlya-biznesa", "ai-pomoshnik"),
]


def auth_header() -> str:
    raw = f"{USER}:{PASSWORD}".encode()
    return "Basic " + base64.b64encode(raw).decode()


def call(method: str, path: str, *, body=None, raw: bytes | None = None,
         headers: dict | None = None, params: dict | None = None):
    url = f"{SITE}/wp-json/{path.lstrip('/')}"
    if params:
        url += "?" + urllib.parse.urlencode(params)
    head = {"Authorization": auth_header()}
    data = raw
    if body is not None:
        data = json.dumps(body, ensure_ascii=False).encode()
        head["Content-Type"] = "application/json"
    head.update(headers or {})
    req = urllib.request.Request(url, data=data, method=method, headers=head)
    try:
        with urllib.request.urlopen(req, timeout=180) as resp:
            return json.loads(resp.read().decode() or "{}")
    except urllib.error.HTTPError as exc:
        detail = exc.read().decode(errors="replace")[:400]
        raise SystemExit(f"{method} {path} -> HTTP {exc.code}\n{detail}")


def check() -> dict:
    me = call("GET", "wp/v2/users/me", params={"context": "edit"})
    print(f"Вошли как {me.get('name')} (id {me.get('id')}), роли: {', '.join(me.get('roles', []))}")
    return me


def upload_images() -> dict[str, str]:
    urls = {}
    for path in sorted((ROOT / "content" / "images").glob("*.webp")):
        mime = mimetypes.guess_type(path.name)[0] or "image/webp"
        result = call("POST", "wp/v2/media", raw=path.read_bytes(), headers={
            "Content-Type": mime,
            "Content-Disposition": f'attachment; filename="{path.name}"',
        })
        urls[path.stem] = result["source_url"]
        print(f"  {path.name} -> {result['source_url']}")
    return urls


def image_base(urls: dict[str, str]) -> str:
    if not urls:
        return f"{SITE}/wp-content/uploads"
    any_url = next(iter(urls.values()))
    return any_url.rsplit("/", 1)[0]


def find_page(slug: str, parent: int = 0) -> int:
    found = call("GET", "wp/v2/pages", params={
        "slug": slug, "status": "any,draft,publish", "per_page": 5,
        **({"parent": parent} if parent else {}),
    })
    return int(found[0]["id"]) if found else 0


def publish_pages(img_base: str) -> None:
    parents: dict[str, int] = {}
    for name, title, slug, parent_slug in PAGES:
        source = ROOT / "content" / "landings" / f"{name}.html"
        content = source.read_text(encoding="utf-8").replace("{IMG}", img_base)

        meta_path = source.with_suffix(".json")
        meta = json.loads(meta_path.read_text(encoding="utf-8")) if meta_path.exists() else {}
        page_title = title or meta.get("title") or name

        parent_id = parents.get(parent_slug or "", 0)
        page_id = find_page(slug, parent_id)
        payload = {
            "title": page_title,
            "slug": slug,
            "content": content,
            "status": "draft",
            "excerpt": meta.get("description", ""),
        }
        if parent_id:
            payload["parent"] = parent_id

        if page_id:
            result = call("POST", f"wp/v2/pages/{page_id}", body=payload)
            action = "обновлена"
        else:
            result = call("POST", "wp/v2/pages", body=payload)
            action = "создана"
        parents[slug] = int(result["id"])
        print(f"  {slug}: {action}, черновик — {result.get('link')}")

    print("\nСтраницы созданы черновиками. Проверьте и опубликуйте вручную.")
    print("Шапку из content/landings/header.html вставьте в Header Builder темы Impreza "
          "элементом HTML и скройте родное меню.")


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--check", action="store_true")
    parser.add_argument("--images", action="store_true")
    parser.add_argument("--pages", action="store_true")
    parser.add_argument("--img-base", default="")
    args = parser.parse_args()

    if not USER or not PASSWORD:
        sys.exit("Задайте WP_USER и WP_APP_PASSWORD в окружении.")

    check()
    if args.check and not (args.images or args.pages):
        return

    urls = upload_images() if args.images else {}
    if args.pages:
        base = args.img_base or image_base(urls)
        print(f"Базовый URL картинок: {base}")
        publish_pages(base)


if __name__ == "__main__":
    main()
