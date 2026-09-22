#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Лендинги ассистентов. Светлая тема в стиле микросервисов, те же картинки.

    python3 tools/build_landings.py [--img BASE_URL]
Контент правится в tools/landing_content.py, вёрстка — здесь.
"""
from __future__ import annotations

import argparse
import html
import importlib.util
import json
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
OUT = ROOT / "content" / "landings"
E = html.escape


def _load(name):
    spec = importlib.util.spec_from_file_location(name, ROOT / "tools" / f"{name}.py")
    mod = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(mod)
    return mod

theme = _load("theme")
lc = _load("landing_content")


def style(accent, accent2):
    return (":root{" + theme.TOKENS + "}\n" + f"""
.gaL{{--acc:{accent};--acc2:{accent2};background:var(--bg);color:var(--text);
  font-family:{theme.FONT};line-height:1.6;font-size:16px;margin:0 calc(50% - 50vw);
  width:100vw;overflow-x:hidden}}
.gaL *,.gaL *::before,.gaL *::after{{box-sizing:border-box}}
.gaL h1,.gaL h2,.gaL h3{{color:#fff;margin:0;line-height:1.16;text-wrap:balance;font-weight:700}}
.gaL p{{margin:0}}
.gaL a{{color:inherit;text-decoration:none}}
.gaL__in{{max-width:1120px;margin:0 auto;padding-left:20px;padding-right:20px}}
.gaL__sec{{padding-block:clamp(46px,6.6vw,80px)}}
.gaL__sec--soft{{background:var(--bg2);border-block:1px solid var(--line2)}}
.gaL__h2{{font-size:clamp(24px,3.5vw,35px);letter-spacing:-.015em;margin-bottom:10px}}
.gaL__sub{{color:var(--muted);max-width:60ch;margin-bottom:30px;font-size:16.5px}}
.gaL__ey{{display:inline-block;font-size:12.5px;font-weight:600;letter-spacing:.11em;
  text-transform:uppercase;color:var(--acc);margin-bottom:10px}}

/* hero */
.gaL__hero{{position:relative;overflow:hidden;padding-block:clamp(58px,9vw,108px);
  border-bottom:1px solid var(--line2)}}
.gaL__heroBg{{position:absolute;inset:0;background-size:cover;background-position:center;opacity:.4}}
.gaL__heroBg::after{{content:"";position:absolute;inset:0;
  background:linear-gradient(104deg,var(--bg) 12%,rgba(22,31,54,.74) 50%,rgba(22,31,54,.28) 100%)}}
.gaL__heroIn{{position:relative;max-width:660px}}
.gaL__pill{{display:inline-flex;align-items:center;gap:8px;padding:6px 14px;border-radius:999px;
  background:color-mix(in srgb,var(--acc) 16%,transparent);
  border:1px solid color-mix(in srgb,var(--acc) 42%,transparent);color:#fff;font-size:12.5px;
  font-weight:600;letter-spacing:.03em}}
.gaL__hero h1{{margin:18px 0 14px;font-size:clamp(31px,5.4vw,52px);letter-spacing:-.02em}}
.gaL__lead{{font-size:clamp(16px,2vw,19px);color:#d4dcec;max-width:56ch}}
.gaL__cta{{display:flex;flex-wrap:wrap;gap:12px;margin-top:28px}}
.gaL__btn{{display:inline-flex;align-items:center;gap:8px;padding:14px 26px;border-radius:14px;
  font-weight:600;font-size:15px;border:1px solid transparent;transition:.15s;cursor:pointer}}
.gaL__btn--main{{background:linear-gradient(92deg,var(--acc),var(--acc2));color:var(--ink)}}
.gaL__btn--main:hover{{filter:brightness(1.08)}}
.gaL__btn--ghost{{background:rgba(255,255,255,.05);border-color:var(--line2);color:#e6ebf6}}
.gaL__btn--ghost:hover{{border-color:var(--acc);color:#fff}}
.gaL__stats{{display:flex;flex-wrap:wrap;gap:12px;margin-top:32px}}
.gaL__stat{{flex:1 1 148px;padding:15px 17px;border-radius:15px;background:rgba(31,41,66,.72);
  border:1px solid var(--line2);backdrop-filter:blur(6px)}}
.gaL__statV{{display:block;font-size:25px;font-weight:700;color:#fff;line-height:1.1}}
.gaL__statL{{display:block;font-size:12.5px;color:var(--muted);margin-top:3px}}

/* features */
.gaL__grid{{display:grid;gap:16px;grid-template-columns:repeat(auto-fit,minmax(274px,1fr))}}
.gaL__feat{{padding:22px 20px;border-radius:16px;background:var(--panel);border:1px solid var(--line2);
  display:flex;flex-direction:column;gap:8px;transition:transform .2s,border-color .2s}}
.gaL__feat:hover{{transform:translateY(-3px);border-color:var(--acc)}}
.gaL__feat i{{font-style:normal;font-size:26px;line-height:1}}
.gaL__feat h3{{font-size:17px}}
.gaL__feat p{{color:var(--muted);font-size:14.5px}}

/* demo */
.gaL__demo{{display:grid;gap:18px;grid-template-columns:minmax(0,1fr) minmax(0,1.25fr);align-items:start}}
.gaL__ask,.gaL__ans{{border-radius:16px;border:1px solid var(--line2);padding:18px 20px}}
.gaL__ask{{background:var(--panel)}}
.gaL__ans{{background:var(--bg2)}}
.gaL__tag{{display:block;font-size:11.5px;letter-spacing:.09em;text-transform:uppercase;
  color:var(--dim);font-weight:600;margin-bottom:9px}}
.gaL__ask p{{font-size:15px;color:#e6ebf6}}
.gaL__ans pre{{margin:0;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:13px;
  line-height:1.72;color:#cdd6e8;white-space:pre-wrap;word-break:break-word}}

/* why */
.gaL__why{{display:grid;gap:22px;grid-template-columns:minmax(0,1fr) minmax(0,1fr);align-items:center}}
.gaL__whyImg{{border-radius:18px;border:1px solid var(--line2);width:100%;height:auto;display:block;
  aspect-ratio:4/3;object-fit:cover;background:var(--panel)}}
.gaL__whyList{{display:flex;flex-direction:column;gap:18px}}
.gaL__whyItem h3{{font-size:17px;margin-bottom:5px}}
.gaL__whyItem p{{color:var(--muted);font-size:14.5px}}

/* faq */
.gaL__faq{{display:flex;flex-direction:column;gap:10px;max-width:820px}}
.gaL__q{{border-radius:14px;border:1px solid var(--line2);background:var(--panel);padding:15px 18px}}
.gaL__q summary{{cursor:pointer;font-weight:600;color:#fff;font-size:15.5px;list-style:none}}
.gaL__q summary::-webkit-details-marker{{display:none}}
.gaL__q summary::after{{content:"+";float:right;color:var(--acc);font-weight:700}}
.gaL__q[open] summary::after{{content:"–"}}
.gaL__q p{{margin-top:10px;color:var(--muted);font-size:14.5px}}

/* end */
.gaL__end{{text-align:center;padding-block:clamp(48px,7vw,84px);
  background:radial-gradient(ellipse at 50% 0%,color-mix(in srgb,var(--acc) 18%,transparent),transparent 62%)}}
.gaL__end h2{{font-size:clamp(24px,3.6vw,36px);margin-bottom:12px}}
.gaL__end p{{color:var(--muted);max-width:52ch;margin:0 auto 26px}}
.gaL__end .gaL__cta{{justify-content:center}}

@media (max-width:820px){{.gaL__demo,.gaL__why{{grid-template-columns:1fr}}}}
@media (prefers-reduced-motion:reduce){{.gaL__btn,.gaL__feat{{transition:none}}}}
""")


def render(item, img):
    slug = item["slug"]
    bot = lc.BRAND["bot"]
    stats = "".join(f'<div class="gaL__stat"><span class="gaL__statV">{E(v)}</span>'
                    f'<span class="gaL__statL">{E(l)}</span></div>' for v, l in item["stats"])
    feats = "".join(f'<article class="gaL__feat"><i aria-hidden="true">{E(ic)}</i>'
                    f'<h3>{E(t)}</h3><p>{E(d)}</p></article>' for ic, t, d in item["features"])
    whys = "".join(f'<div class="gaL__whyItem"><h3>{E(t)}</h3><p>{E(d)}</p></div>'
                   for t, d in item["why"])
    faqs = "".join(f'<details class="gaL__q"><summary>{E(q)}</summary><p>{E(a)}</p></details>'
                   for q, a in item["faq"])
    demo_out = E("\n".join(item["demo_out"]))

    faq_ld = json.dumps({"@context": "https://schema.org", "@type": "FAQPage",
        "mainEntity": [{"@type": "Question", "name": q,
                        "acceptedAnswer": {"@type": "Answer", "text": a}} for q, a in item["faq"]]},
        ensure_ascii=False)
    app_ld = json.dumps({"@context": "https://schema.org", "@type": "WebApplication",
        "name": item["h1"], "description": item["description"],
        "applicationCategory": "BusinessApplication", "operatingSystem": "Web, Telegram",
        "url": "https://genius-bot.ru" + item["url"],
        "offers": {"@type": "Offer", "price": "0", "priceCurrency": "RUB"}}, ensure_ascii=False)

    return f"""<!-- Лендинг «{E(item['h1'])}». Собрано tools/build_landings.py — правьте tools/landing_content.py. -->
<style>{style(item['accent'], item['accent2'])}</style>
<div class="gaL">
  <section class="gaL__hero">
    <div class="gaL__heroBg" style="background-image:url('{img}/{slug}-hero.webp')"></div>
    <div class="gaL__in gaL__heroIn">
      <span class="gaL__pill">{E(item['emoji'])} {E(item['eyebrow'])}</span>
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
      <span class="gaL__ey">Демо</span>
      <h2 class="gaL__h2">Попробуйте прямо сейчас</h2>
      <p class="gaL__sub">Без регистрации. Вход — почтой, через VK или Telegram, тем же аккаунтом,
         что и остальные сервисы Genius: баланс один на всё.</p>
      [genius_assistant slug="{slug}"]
    </div>
  </section>

  <section class="gaL__sec gaL__sec--soft">
    <div class="gaL__in">
      <span class="gaL__ey">Возможности</span>
      <h2 class="gaL__h2">{E(item['features_title'])}</h2>
      <div class="gaL__grid" style="margin-top:6px">{feats}</div>
    </div>
  </section>

  <section class="gaL__sec">
    <div class="gaL__in">
      <span class="gaL__ey">Пример</span>
      <h2 class="gaL__h2">{E(item['demo_title'])}</h2>
      <p class="gaL__sub">Реальный запрос и реальный ответ, без монтажа.</p>
      <div class="gaL__demo">
        <div class="gaL__ask"><span class="gaL__tag">Запрос</span><p>{E(item['demo_in'])}</p></div>
        <div class="gaL__ans"><span class="gaL__tag">Ответ ассистента</span><pre>{demo_out}</pre></div>
      </div>
    </div>
  </section>

  <section class="gaL__sec gaL__sec--soft">
    <div class="gaL__in">
      <span class="gaL__ey">Почему это работает</span>
      <h2 class="gaL__h2">{E(item['why_title'])}</h2>
      <div class="gaL__why" style="margin-top:6px">
        <img class="gaL__whyImg" src="{img}/{slug}-card.webp" alt="" loading="lazy"
             width="1200" height="896">
        <div class="gaL__whyList">{whys}</div>
      </div>
    </div>
  </section>

  <section class="gaL__sec">
    <div class="gaL__in">
      <span class="gaL__ey">Вопросы</span>
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


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--img", default="{IMG}")
    args = ap.parse_args()
    OUT.mkdir(parents=True, exist_ok=True)
    for item in lc.LANDINGS:
        slug = item["slug"]
        page = OUT / f"{slug}.html"
        page.write_text(render(item, args.img), encoding="utf-8")
        (OUT / f"{slug}.json").write_text(json.dumps({
            "slug": slug, "url": item["url"], "title": item["title"],
            "description": item["description"], "h1": item["h1"]}, ensure_ascii=False, indent=1),
            encoding="utf-8")
        print(f"  {slug}: {len(page.read_text(encoding='utf-8'))//1024} КБ")


if __name__ == "__main__":
    main()
