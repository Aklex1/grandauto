#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Сборка лендингов ассистентов в HTML, готовый для вставки в страницу WordPress.

Вёрстка самодостаточная: стили scoped под .gaL, поэтому тема сайта её не ломает
и она не ломает тему. Палитра взята со страниц микросервисов.

    python3 tools/build_landings.py
    python3 tools/build_landings.py --img https://genius-bot.ru/wp-content/uploads/2026/09

Плейсхолдер {IMG} заменяется на базовый URL картинок при публикации.
"""
from __future__ import annotations

import argparse
import html
import importlib.util
import json
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
OUT = ROOT / "content" / "landings"

spec = importlib.util.spec_from_file_location("lc", ROOT / "tools" / "landing_content.py")
lc = importlib.util.module_from_spec(spec)
spec.loader.exec_module(lc)

E = html.escape

STYLE = """
.gaL{--bg:#070b14;--panel:#0f172a;--panel2:#131c2e;--line:#1e293b;--text:#f1f5f9;
  --muted:#94a3b8;--dim:#64748b;--accent:%(accent)s;--accent2:%(accent2)s;
  color:var(--text);background:var(--bg);font-family:Rubik,-apple-system,BlinkMacSystemFont,
  "Segoe UI",Roboto,Arial,sans-serif;line-height:1.6;font-size:16px;
  margin:0 calc(50%% - 50vw);padding:0;width:100vw;overflow-x:hidden}
.gaL *,.gaL *::before,.gaL *::after{box-sizing:border-box}
.gaL h1,.gaL h2,.gaL h3{color:#fff;margin:0;line-height:1.18;text-wrap:balance;font-weight:700}
.gaL p{margin:0}
.gaL__in{width:100%%;max-width:1120px;margin:0 auto;padding-left:20px;padding-right:20px}

.gaL__hero{position:relative;padding-block:clamp(56px,9vw,104px);overflow:hidden;
  border-bottom:1px solid var(--line)}
.gaL__heroBg{position:absolute;inset:0;background-size:cover;background-position:center;
  opacity:.5}
.gaL__heroBg::after{content:"";position:absolute;inset:0;
  background:linear-gradient(105deg,#070b14 12%%,rgba(7,11,20,.82) 48%%,rgba(7,11,20,.35) 100%%)}
.gaL__heroIn{position:relative;max-width:660px}
.gaL__eyebrow{display:inline-flex;align-items:center;gap:8px;padding:5px 13px;border-radius:999px;
  background:rgba(255,255,255,.06);border:1px solid var(--line);color:var(--accent);
  font-size:12.5px;font-weight:600;letter-spacing:.04em;text-transform:uppercase}
.gaL__hero h1{margin:18px 0 14px;font-size:clamp(31px,5.4vw,52px);letter-spacing:-.02em}
.gaL__lead{font-size:clamp(16px,2vw,19px);color:#cbd5e1;max-width:56ch}
.gaL__cta{display:flex;flex-wrap:wrap;gap:12px;margin-top:28px}
.gaL__btn{display:inline-flex;align-items:center;gap:8px;padding:14px 26px;border-radius:14px;
  font-weight:600;font-size:15px;text-decoration:none;border:1px solid transparent;transition:.15s}
.gaL__btn--main{background:var(--accent);color:#070b14}
.gaL__btn--main:hover{background:var(--accent2);color:#070b14}
.gaL__btn--ghost{background:rgba(255,255,255,.05);border-color:var(--line);color:#e2e8f0}
.gaL__btn--ghost:hover{border-color:var(--accent);color:#fff}

.gaL__stats{display:flex;flex-wrap:wrap;gap:12px;margin-top:34px}
.gaL__stat{flex:1 1 150px;padding:14px 16px;border-radius:14px;background:rgba(15,23,42,.72);
  border:1px solid var(--line);backdrop-filter:blur(6px)}
.gaL__statV{display:block;font-size:24px;font-weight:700;color:#fff;line-height:1.1}
.gaL__statL{display:block;font-size:12.5px;color:var(--muted);margin-top:3px}

.gaL__sec{padding-block:clamp(44px,6.5vw,76px)}
.gaL__sec--alt{background:var(--panel);border-block:1px solid var(--line)}
.gaL__h2{font-size:clamp(23px,3.4vw,34px);margin-bottom:10px;letter-spacing:-.015em}
.gaL__sub{color:var(--muted);max-width:60ch;margin-bottom:30px}

.gaL__grid{display:grid;gap:16px;grid-template-columns:repeat(auto-fit,minmax(272px,1fr))}
.gaL__feat{padding:22px 20px;border-radius:16px;background:var(--panel2);border:1px solid var(--line);
  display:flex;flex-direction:column;gap:8px}
.gaL__feat i{font-style:normal;font-size:26px;line-height:1}
.gaL__feat h3{font-size:17px}
.gaL__feat p{color:var(--muted);font-size:14.5px}

.gaL__demo{display:grid;gap:18px;grid-template-columns:minmax(0,1fr) minmax(0,1.25fr);align-items:start}
.gaL__ask,.gaL__ans{border-radius:16px;border:1px solid var(--line);padding:18px 20px}
.gaL__ask{background:var(--panel2)}
.gaL__ans{background:#080d18}
.gaL__tag{display:block;font-size:11.5px;letter-spacing:.09em;text-transform:uppercase;
  color:var(--dim);font-weight:600;margin-bottom:9px}
.gaL__ask p{font-size:15px;color:#e2e8f0}
.gaL__ans pre{margin:0;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:13px;
  line-height:1.72;color:#cbd5e1;white-space:pre-wrap;word-break:break-word}

.gaL__why{display:grid;gap:20px;grid-template-columns:minmax(0,1fr) minmax(0,1fr);align-items:center}
.gaL__whyImg{border-radius:18px;border:1px solid var(--line);width:100%%;height:auto;display:block;
  aspect-ratio:4/3;object-fit:cover;background:var(--panel2)}
.gaL__whyList{display:flex;flex-direction:column;gap:18px}
.gaL__whyItem h3{font-size:17px;margin-bottom:5px}
.gaL__whyItem p{color:var(--muted);font-size:14.5px}

.gaL__faq{display:flex;flex-direction:column;gap:10px;max-width:820px}
.gaL__q{border-radius:14px;border:1px solid var(--line);background:var(--panel2);
  padding:15px 18px}
.gaL__q summary{cursor:pointer;font-weight:600;color:#fff;font-size:15.5px;list-style:none}
.gaL__q summary::-webkit-details-marker{display:none}
.gaL__q summary::after{content:"+";float:right;color:var(--accent);font-weight:700}
.gaL__q[open] summary::after{content:"–"}
.gaL__q p{margin-top:10px;color:var(--muted);font-size:14.5px}

.gaL__end{text-align:center;padding-block:clamp(48px,7vw,84px);
  background:radial-gradient(ellipse at 50%% 0%%,rgba(129,140,248,.14),transparent 62%%)}
.gaL__end h2{font-size:clamp(24px,3.6vw,36px);margin-bottom:12px}
.gaL__end p{color:var(--muted);max-width:52ch;margin:0 auto 26px}
.gaL__end .gaL__cta{justify-content:center}

@media (max-width:820px){
  .gaL__demo,.gaL__why{grid-template-columns:1fr}
  .gaL__why{gap:16px}
}
@media (prefers-reduced-motion:reduce){.gaL__btn{transition:none}}
"""


def render(item: dict, img_base: str) -> str:
    a = {"accent": item["accent"], "accent2": item["accent2"]}
    css = (STYLE % a).strip()
    slug = item["slug"]
    bot = lc.BRAND["bot"]

    stats = "".join(
        f'<div class="gaL__stat"><span class="gaL__statV">{E(v)}</span>'
        f'<span class="gaL__statL">{E(l)}</span></div>'
        for v, l in item["stats"])

    feats = "".join(
        f'<article class="gaL__feat"><i aria-hidden="true">{E(ic)}</i>'
        f'<h3>{E(t)}</h3><p>{E(d)}</p></article>'
        for ic, t, d in item["features"])

    whys = "".join(
        f'<div class="gaL__whyItem"><h3>{E(t)}</h3><p>{E(d)}</p></div>'
        for t, d in item["why"])

    faqs = "".join(
        f'<details class="gaL__q"><summary>{E(q)}</summary><p>{E(ans)}</p></details>'
        for q, ans in item["faq"])

    demo_out = E("\n".join(item["demo_out"]))

    faq_ld = json.dumps({
        "@context": "https://schema.org",
        "@type": "FAQPage",
        "mainEntity": [
            {"@type": "Question", "name": q,
             "acceptedAnswer": {"@type": "Answer", "text": ans}}
            for q, ans in item["faq"]
        ],
    }, ensure_ascii=False, indent=1)

    app_ld = json.dumps({
        "@context": "https://schema.org",
        "@type": "WebApplication",
        "name": item["h1"],
        "description": item["description"],
        "applicationCategory": "BusinessApplication",
        "operatingSystem": "Web, Telegram",
        "url": "https://genius-bot.ru" + item["url"],
        "offers": {"@type": "Offer", "price": "0", "priceCurrency": "RUB",
                   "description": "Бесплатный дневной лимит, далее с общего баланса Genius"},
    }, ensure_ascii=False, indent=1)

    return f"""<!-- Лендинг ассистента «{E(item['h1'])}». Собрано tools/build_landings.py — правьте tools/landing_content.py, не этот файл. -->
<style>{css}</style>

<div class="gaL">

  <section class="gaL__hero">
    <div class="gaL__heroBg" style="background-image:url('{img_base}/{slug}-hero.webp')"></div>
    <div class="gaL__in gaL__heroIn">
      <span class="gaL__eyebrow">{E(item['emoji'])} {E(item['eyebrow'])}</span>
      <h1>{E(item['h1'])}</h1>
      <p class="gaL__lead">{E(item['lead'])}</p>
      <div class="gaL__cta">
        <a class="gaL__btn gaL__btn--main" href="#probovat">Попробовать здесь</a>
        <a class="gaL__btn gaL__btn--ghost" href="https://t.me/{bot}?start={slug}"
           target="_blank" rel="noopener">Открыть в Telegram</a>
      </div>
      <div class="gaL__stats">{stats}</div>
    </div>
  </section>

  <section class="gaL__sec" id="probovat">
    <div class="gaL__in">
      <h2 class="gaL__h2">Попробуйте прямо сейчас</h2>
      <p class="gaL__sub">Без регистрации. Вход — почтой, через VK или Telegram, тем же аккаунтом,
         что и остальные сервисы Genius: баланс один на всё.</p>
      [genius_assistant slug="{slug}"]
    </div>
  </section>

  <section class="gaL__sec gaL__sec--alt">
    <div class="gaL__in">
      <h2 class="gaL__h2">{E(item['features_title'])}</h2>
      <div class="gaL__grid">{feats}</div>
    </div>
  </section>

  <section class="gaL__sec">
    <div class="gaL__in">
      <h2 class="gaL__h2">{E(item['demo_title'])}</h2>
      <p class="gaL__sub">Реальный запрос и реальный ответ, без монтажа.</p>
      <div class="gaL__demo">
        <div class="gaL__ask">
          <span class="gaL__tag">Запрос</span>
          <p>{E(item['demo_in'])}</p>
        </div>
        <div class="gaL__ans">
          <span class="gaL__tag">Ответ ассистента</span>
          <pre>{demo_out}</pre>
        </div>
      </div>
    </div>
  </section>

  <section class="gaL__sec gaL__sec--alt">
    <div class="gaL__in">
      <h2 class="gaL__h2">{E(item['why_title'])}</h2>
      <div class="gaL__why">
        <img class="gaL__whyImg" src="{img_base}/{slug}-card.webp" alt="" loading="lazy"
             width="1200" height="896">
        <div class="gaL__whyList">{whys}</div>
      </div>
    </div>
  </section>

  <section class="gaL__sec">
    <div class="gaL__in">
      <h2 class="gaL__h2">Частые вопросы</h2>
      <div class="gaL__faq">{faqs}</div>
    </div>
  </section>

  <section class="gaL__end">
    <div class="gaL__in">
      <h2>Начните с одной задачи</h2>
      <p>Первые сообщения бесплатны. Понравится — останетесь, нет — ничего не потеряете.</p>
      <div class="gaL__cta">
        <a class="gaL__btn gaL__btn--main" href="#probovat">Попробовать здесь</a>
        <a class="gaL__btn gaL__btn--ghost" href="https://t.me/{bot}?start={slug}"
           target="_blank" rel="noopener">Открыть в Telegram</a>
      </div>
    </div>
  </section>

</div>

<script type="application/ld+json">{app_ld}</script>
<script type="application/ld+json">{faq_ld}</script>
"""


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--img", default="{IMG}", help="базовый URL картинок")
    args = parser.parse_args()
    OUT.mkdir(parents=True, exist_ok=True)
    for item in lc.LANDINGS:
        path = OUT / f"{item['slug']}.html"
        path.write_text(render(item, args.img), encoding="utf-8")
        meta = OUT / f"{item['slug']}.json"
        meta.write_text(json.dumps({
            "slug": item["slug"], "url": item["url"], "title": item["title"],
            "description": item["description"], "h1": item["h1"],
        }, ensure_ascii=False, indent=1), encoding="utf-8")
        print(f"  {item['slug']}: {len(path.read_text(encoding='utf-8')) // 1024} КБ -> {path.name}")


if __name__ == "__main__":
    main()
