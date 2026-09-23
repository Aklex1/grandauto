#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Ставит лонгриды из content/longreads/ в расписание публикаций WordPress
как отложенные посты (status=future). По умолчанию 3 поста в неделю (Пн/Ср/Пт),
старт — ближайшая дата через пару дней, 10:00 по МСК.

    WP_USER=bot1 WP_APP_PASSWORD='...' python3 tools/schedule_posts.py [--dry]
"""
import base64, datetime as dt, json, os, re, sys, urllib.request, urllib.error
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
SITE = "https://genius-bot.ru"
USER = os.environ["WP_USER"]; PW = os.environ["WP_APP_PASSWORD"]
DRY = "--dry" in sys.argv

def call(method, path, body=None):
    req = urllib.request.Request(f"{SITE}/wp-json/{path}",
        data=json.dumps(body, ensure_ascii=False).encode() if body else None, method=method,
        headers={"Authorization": "Basic " + base64.b64encode(f"{USER}:{PW}".encode()).decode(),
                 "Content-Type": "application/json"})
    with urllib.request.urlopen(req, timeout=90) as r:
        return json.loads(r.read().decode())

def frontmatter(text):
    m = re.match(r"^---\n(.*?)\n---\n(.*)$", text, re.S)
    meta, body = {}, text
    if m:
        body = m.group(2)
        for line in m.group(1).splitlines():
            if ":" in line:
                k, v = line.split(":", 1)
                meta[k.strip()] = v.strip().strip('"')
    return meta, body

def md_to_html(md):
    md = re.sub(r"^#\s+.*$", "", md, count=1, flags=re.M)  # убрать H1 (это заголовок поста)
    lines = md.split("\n")
    out, i = [], 0
    while i < len(lines):
        ln = lines[i].rstrip()
        if not ln.strip():
            i += 1; continue
        if ln.startswith("### "):
            out.append(f"<h3>{inline(ln[4:])}</h3>")
        elif ln.startswith("## "):
            out.append(f"<h2>{inline(ln[3:])}</h2>")
        elif re.match(r"^\d+\.\s", ln):
            items = []
            while i < len(lines) and re.match(r"^\d+\.\s", lines[i].strip()):
                clean = re.sub(r'^\d+\.\s', '', lines[i].strip())
                items.append("<li>" + inline(clean) + "</li>")
                i += 1
            out.append("<ol>" + "".join(items) + "</ol>"); continue
        elif ln.startswith("- "):
            items = []
            while i < len(lines) and lines[i].strip().startswith("- "):
                items.append(f"<li>{inline(lines[i].strip()[2:])}</li>")
                i += 1
            out.append("<ul>" + "".join(items) + "</ul>"); continue
        else:
            para = [ln]
            i += 1
            while i < len(lines) and lines[i].strip() and not re.match(r"^(#|-|\d+\.)", lines[i].strip()):
                para.append(lines[i].rstrip()); i += 1
            out.append(f"<p>{inline(' '.join(para))}</p>"); continue
        i += 1
    return "\n".join(out)

def inline(t):
    t = re.sub(r"\*\*(.+?)\*\*", r"<strong>\1</strong>", t)
    t = re.sub(r"\[([^\]]+)\]\(([^)]+)\)", r'<a href="\2">\1</a>', t)
    return t

# порядок публикации: по частоте (сначала ёмкие), потом остальные
ORDER = ["ii-pomoshniki-kakie-est","ii-konsultant-na-sajt","ii-agent-dlya-biznesa",
         "kak-sdelat-ii-pomoshnika","rejting-ii-pomoshnikov","ii-pomoshnik-po-matematike",
         "ii-pomoshnik-dlya-buhgaltera","ii-pomoshnik-dlya-sozdaniya-video","lichnyj-ii-pomoshnik",
         "ii-pomoshnik-rukovoditelya","nejroset-dlya-uchitelya","ii-pomoshnik-dlya-rezyume",
         "nejroset-dlya-dokumentov"]

# расписание: Пн/Ср/Пт, 10:00 МСК, старт послезавтра
def slots(n, start):
    d = start; got = []
    while len(got) < n:
        if d.weekday() in (0, 2, 4):  # Пн, Ср, Пт
            got.append(d)
        d += dt.timedelta(days=1)
    return got

start = dt.date.today() + dt.timedelta(days=2)
dates = slots(len(ORDER), start)

for slug, when in zip(ORDER, dates):
    f = ROOT / "content" / "longreads" / f"{slug}.md"
    if not f.exists():
        print("нет файла:", slug); continue
    meta, body = frontmatter(f.read_text(encoding="utf-8"))
    date_local = f"{when.isoformat()}T10:00:00"
    payload = {
        "title": meta.get("title", slug),
        "slug": slug,
        "content": md_to_html(body),
        "excerpt": meta.get("meta_description", ""),
        "status": "draft" if DRY else "future",
        "date": date_local,
    }
    if DRY:
        print(f"[dry] {when} 10:00 · {payload['title'][:50]} · {len(payload['content'])} симв")
        continue
    # не плодим дубли: ищем по слагу
    found = call("GET", f"wp/v2/posts?slug={slug}&status=any,draft,future,publish")
    if isinstance(found, list) and found:
        r = call("POST", f"wp/v2/posts/{found[0]['id']}", payload); act = "обновлён"
    else:
        r = call("POST", "wp/v2/posts", payload); act = "создан"
    print(f"  {when} 10:00 · {act} · {r.get('link')}")
