#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Новая главная genius-bot.ru и шапка с меню по группам.

Три секции: ИИ-помощники плиткой, микросервисы крупными SVG-иконками
по группам (Фото/Видео/Звук) с анимацией при наведении, статьи блога.
Старого контента на главной нет — это полная замена.

    python3 tools/build_home.py [--img BASE_URL]
"""
from __future__ import annotations

import argparse
import html
import importlib.util
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
icons = _load("icons")
sd = _load("services_data")
lc = _load("landing_content")

ASSISTANTS = [
    ("uchitel", "📘", "Ассистент учителя", "Образование",
     "Конспект урока, техкарта, проверочная и характеристика — за минуты.", "#818cf8"),
    ("ucheba", "🎒", "Ассистент ученика", "Образование",
     "Разбор задачи по шагам, подготовка к контрольной, конспект лекции.", "#38bdf8"),
    ("yurist", "⚖️", "Юрист-ассистент", "Документы",
     "Проверка договора, поиск рискованных пунктов, претензия и заявление.", "#fbbf24"),
    ("biznes", "💼", "Консультант для бизнеса", "Бизнес",
     "Отвечает клиентам в Telegram и на сайте по вашей базе знаний.", "#22d3ee"),
]

# Меню: группы микросервисов + отдельные пункты
MENU_GROUPS = [
    ("assistants", "ИИ-помощники", "/ai-pomoshnik/", [
        (a[1] + " " + a[2], "/ai-pomoshnik/" + {"uchitel": "dlya-uchitelya", "ucheba": "dlya-ucheby",
         "yurist": "yurist", "biznes": "dlya-biznesa"}[a[0]] + "/") for a in ASSISTANTS
    ]),
]


def css() -> str:
    return f""":root{{{theme.TOKENS}}}
{theme.THEME_FIX}
.gp *,.gp *::before,.gp *::after{{box-sizing:border-box}}
.gp{{background:var(--bg);color:var(--text);font-family:{theme.FONT};line-height:1.6;
  font-size:16px;margin:0 calc(50% - 50vw);width:100vw;overflow-x:hidden}}
.gp h1,.gp h2,.gp h3{{color:#fff;margin:0;line-height:1.16;text-wrap:balance;font-weight:700}}
.gp p{{margin:0}}
.gp a{{color:inherit;text-decoration:none}}
.gp__in{{max-width:1200px;margin:0 auto;padding-left:20px;padding-right:20px}}
.gp__sec{{padding-block:clamp(48px,7vw,86px)}}
.gp__head{{max-width:64ch;margin-bottom:34px}}
.gp__ey{{display:inline-block;font-size:12.5px;font-weight:600;letter-spacing:.11em;
  text-transform:uppercase;color:var(--accent);margin-bottom:10px}}
.gp__h2{{font-size:clamp(25px,3.7vw,38px);letter-spacing:-.015em}}
.gp__sub{{color:var(--muted);margin-top:10px;font-size:16.5px}}

/* hero */
.gp__hero{{position:relative;overflow:hidden;padding-top:clamp(96px,11vw,132px);padding-bottom:clamp(56px,8vw,110px);
  border-bottom:1px solid var(--line2)}}
.gp__heroBg{{position:absolute;inset:0;background-size:cover;background-position:center;opacity:.42}}
.gp__heroBg::after{{content:"";position:absolute;inset:0;
  background:linear-gradient(103deg,var(--bg) 14%,rgba(22,31,54,.72) 52%,rgba(22,31,54,.25) 100%)}}
.gp__heroIn{{position:relative;max-width:700px}}
.gp__badge{{display:inline-flex;align-items:center;gap:8px;padding:6px 14px;border-radius:999px;
  background:rgba(129,140,248,.14);border:1px solid rgba(129,140,248,.42);color:#c7d2fe;
  font-size:13px;font-weight:600}}
.gp__hero h1{{margin:20px 0 16px;font-size:clamp(34px,6vw,60px);letter-spacing:-.025em}}
.gp__hero h1 em{{font-style:normal;background:linear-gradient(92deg,var(--accent),var(--accent2));
  -webkit-background-clip:text;background-clip:text;color:transparent}}
.gp__lead{{font-size:clamp(16px,2.1vw,20px);color:#d4dcec;max-width:58ch}}
.gp__cta{{display:flex;flex-wrap:wrap;gap:12px;margin-top:30px}}
.gp__btn{{display:inline-flex;align-items:center;gap:8px;padding:15px 28px;border-radius:14px;
  font-weight:600;font-size:15.5px;border:1px solid transparent;transition:.15s;cursor:pointer}}
.gp__btn--main{{background:linear-gradient(92deg,var(--accent),var(--accent2));color:var(--ink)}}
.gp__btn--main:hover{{filter:brightness(1.08)}}
.gp__btn--ghost{{background:rgba(255,255,255,.05);border-color:var(--line2);color:#e6ebf6}}
.gp__btn--ghost:hover{{border-color:var(--accent);color:#fff}}
.gp__trust{{margin-top:24px;color:var(--dim);font-size:13.5px}}

/* плитки ассистентов */
.gp__tiles{{display:grid;gap:16px;grid-template-columns:repeat(auto-fit,minmax(330px,1fr))}}
.a-tile{{position:relative;display:flex;flex-direction:row;align-items:flex-start;gap:16px;
  padding:22px 20px;border-radius:20px;background:linear-gradient(150deg,var(--panel) 0%,var(--bg2) 100%);
  border:1px solid var(--line2);overflow:hidden;transition:transform .2s,border-color .2s,box-shadow .2s}}
.a-tile::before{{content:"";position:absolute;inset:-45% 55% 55% -45%;border-radius:50%;
  background:radial-gradient(circle,var(--tint) 0%,transparent 70%);opacity:.2;transition:opacity .2s}}
.a-tile:hover{{transform:translateY(-4px);border-color:var(--tint);
  box-shadow:0 22px 46px -26px var(--tint)}}
.a-tile:hover::before{{opacity:.34}}
.a-tile__body{{display:flex;flex-direction:column;gap:7px;min-width:0}}
.a-tile__ic{{flex-shrink:0;width:52px;height:52px;display:grid;place-items:center;border-radius:14px;font-size:27px;
  background:color-mix(in srgb,var(--tint) 18%,transparent);border:1px solid color-mix(in srgb,var(--tint) 40%,transparent)}}
.a-tile__cat{{font-size:11.5px;letter-spacing:.09em;text-transform:uppercase;color:var(--tint);font-weight:600}}
.a-tile__nm{{font-size:18.5px;font-weight:700;color:#fff}}
.a-tile__tag{{color:var(--muted);font-size:14px;flex:1}}
.a-tile__go{{color:var(--tint);font-weight:600;font-size:14px}}

/* микросервисы */
.gp__grp{{margin-bottom:34px}}
.gp__grpHead{{display:flex;align-items:baseline;gap:12px;margin-bottom:16px;flex-wrap:wrap}}
.gp__grpHead h3{{font-size:20px}}
.gp__grpHead span{{color:var(--muted);font-size:14px}}
.svc-grid{{display:grid;gap:14px;grid-template-columns:repeat(auto-fit,minmax(228px,1fr))}}
.svc-card{{display:flex;flex-direction:column;gap:12px;padding:20px;border-radius:16px;
  background:var(--panel);border:1px solid var(--line2);transition:transform .2s,border-color .2s,box-shadow .2s}}
.svc-card:hover{{transform:translateY(-3px);border-color:var(--accent2);
  box-shadow:0 18px 40px -26px var(--accent2)}}
.svc-card__ic{{width:52px;height:52px;padding:11px;border-radius:14px;
  background:linear-gradient(150deg,rgba(129,140,248,.16),rgba(34,211,238,.1));
  border:1px solid var(--line2)}}
.svc-card__nm{{font-size:16.5px;font-weight:600;color:#fff}}
.svc-card__ab{{color:var(--muted);font-size:14px;line-height:1.5}}
{theme.ICON_CSS}

/* блог — карточки строго одинаковой высоты, без дыр грида */
.gp__blog{{display:grid;gap:16px;grid-template-columns:repeat(auto-fit,minmax(280px,1fr))}}
.gp-post{{display:flex;flex-direction:column;height:100%;border-radius:16px;overflow:hidden;
  background:var(--panel);border:1px solid var(--line2);transition:transform .2s,border-color .2s,box-shadow .2s}}
.gp-post:hover{{transform:translateY(-3px);border-color:var(--accent2);box-shadow:0 18px 40px -26px var(--accent2)}}
.gp-post__top{{flex-shrink:0;height:166px;overflow:hidden;background:var(--panel2)}}
.gp-post__img{{width:100%;height:100%;object-fit:cover;display:block}}
.gp-post__ph{{width:100%;height:100%;display:grid;place-items:center;
  background:linear-gradient(150deg,rgba(129,140,248,.14),rgba(34,211,238,.08))}}
.gp-post__ph svg{{width:56px;height:56px}}
.gp-post__body{{display:flex;flex-direction:column;gap:8px;padding:16px 18px;flex:1}}
.gp-post__title{{font-size:15.5px;font-weight:600;color:#fff;line-height:1.35;
  display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}}
.gp-post__ex{{font-size:13.5px;color:var(--muted);line-height:1.5;
  display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}}
.gp__sec--soft{{background:var(--bg2);border-block:1px solid var(--line2)}}

@media (prefers-reduced-motion:reduce){{.a-tile,.svc-card,.gp__btn{{transition:none}}}}
"""


def hero(img):
    return f"""
  <section class="gp__hero">
    <div class="gp__heroBg" style="background-image:url('{img}/home-hero.webp')"></div>
    <div class="gp__in gp__heroIn">
      <span class="gp__badge">🤖 ИИ-помощники и нейросети на одном балансе</span>
      <h1>Нейросети Genius: <em>помощники и сервисы</em> в Telegram и на сайте</h1>
      <p class="gp__lead">Ассистенты, которые делают работу, и полтора десятка нейро-сервисов
         для фото, видео и звука. Один вход, один баланс, оплата картой и ЮMoney.</p>
      <div class="gp__cta">
        <a class="gp__btn gp__btn--main" href="#pomoshniki">ИИ-помощники</a>
        <a class="gp__btn gp__btn--ghost" href="#servisy">Все сервисы</a>
      </div>
      <p class="gp__trust">Первые запросы бесплатны · Вход почтой, через VK или Telegram</p>
    </div>
  </section>"""


def assistants_section():
    tiles = ""
    for slug, emoji, name, cat, tag, tint in ASSISTANTS:
        url = "/ai-pomoshnik/" + {"uchitel": "dlya-uchitelya", "ucheba": "dlya-ucheby",
                                  "yurist": "yurist", "biznes": "dlya-biznesa"}[slug] + "/"
        tiles += f"""
        <a class="a-tile" href="{url}" style="--tint:{tint}">
          <span class="a-tile__ic">{emoji}</span>
          <span class="a-tile__body">
            <span class="a-tile__cat">{E(cat)}</span>
            <span class="a-tile__nm">{E(name)}</span>
            <span class="a-tile__tag">{E(tag)}</span>
            <span class="a-tile__go">Открыть →</span>
          </span>
        </a>"""
    return f"""
  <section class="gp__sec" id="pomoshniki">
    <div class="gp__in">
      <div class="gp__head">
        <span class="gp__ey">ИИ-помощники</span>
        <h2 class="gp__h2">Ассистенты под конкретную работу</h2>
        <p class="gp__sub">Каждый заточен под свою задачу и работает в боте и на сайте.
           Универсальный чат проигрывает специалисту на каждой из них.</p>
      </div>
      <div class="gp__tiles">{tiles}</div>
    </div>
  </section>"""


def services_section():
    groups_html = ""
    for gid, gname, gdesc in sd.GROUPS:
        cards = ""
        for key, title, about, group, icon, url, link in sd.by_group(gid):
            cards += f"""
          <a class="svc-card" href="{E(link)}">
            <span class="svc-card__ic">{icons.svg(icon)}</span>
            <span class="svc-card__nm">{E(title)}</span>
            <span class="svc-card__ab">{E(about)}</span>
          </a>"""
        groups_html += f"""
      <div class="gp__grp">
        <div class="gp__grpHead"><h3>{E(gname)}</h3><span>{E(gdesc)}</span></div>
        <div class="svc-grid">{cards}</div>
      </div>"""
    return f"""
  <section class="gp__sec gp__sec--soft" id="servisy">
    <div class="gp__in">
      <div class="gp__head">
        <span class="gp__ey">Нейро-сервисы</span>
        <h2 class="gp__h2">Полтора десятка сервисов на одном балансе</h2>
        <p class="gp__sub">Наведите на карточку — иконка оживает. Всё работает и в Telegram-боте,
           и в браузере.</p>
      </div>
      {groups_html}
    </div>
  </section>"""


def _blog_cards():
    f = ROOT / "content" / "landings" / "_blog.html"
    if f.exists() and f.read_text(encoding="utf-8").strip():
        return f.read_text(encoding="utf-8")
    return ('[us_grid post_type="post" items_quantity="6" columns="3" orderby="date" '
            'items_layout="blog_classic_1" pagination="none"]')


def blog_section():
    return """
  <section class="gp__sec" id="blog">
    <div class="gp__in">
      <div class="gp__head">
        <span class="gp__ey">Блог</span>
        <h2 class="gp__h2">Разборы, инструкции и промты</h2>
        <p class="gp__sub">То, что мы сами используем в работе.</p>
      </div>
      <div class="gp__blog">{BLOG_CARDS}</div>
      <p style="margin-top:24px">
        <a class="gp__btn gp__btn--ghost" href="/blog/">Все статьи</a>
      </p>
    </div>
  </section>"""


def home_html(img):
    return f"""<!-- Главная genius-bot.ru. Собрано tools/build_home.py — правьте скрипт, не этот файл. -->
<style>{css()}</style>
<div class="gp">
{hero(img)}
{assistants_section()}
{services_section()}
{blog_section().replace("{BLOG_CARDS}", _blog_cards())}
</div>"""


# --------------------------------------------------------------------------- шапка

HEADER_CSS = f""":root{{{theme.TOKENS}}}
.gh *,.gh *::before,.gh *::after{{box-sizing:border-box}}
.gh{{position:sticky;top:0;z-index:900;background:rgba(17,26,46,.9);
  backdrop-filter:saturate(140%) blur(14px);border-bottom:1px solid var(--line2);
  font-family:{theme.FONT};margin:0 calc(50% - 50vw);width:100vw}}
.gh__in{{max-width:1200px;margin:0 auto;padding:0 20px;height:66px;display:flex;align-items:center;gap:20px}}
.gh__logo{{display:flex;align-items:center;gap:9px;font-weight:700;font-size:18px;color:#fff;white-space:nowrap}}
.gh__logo b{{background:linear-gradient(92deg,var(--accent),var(--accent2));
  -webkit-background-clip:text;background-clip:text;color:transparent}}
.gh__nav{{display:flex;align-items:center;gap:2px;flex:1}}
.gh__item{{position:relative}}
.gh__link{{display:inline-flex;align-items:center;gap:6px;padding:9px 13px;border-radius:10px;
  color:var(--muted);font-size:14.5px;font-weight:500;white-space:nowrap;cursor:pointer;
  transition:color .15s,background .15s;background:none;border:none;font-family:inherit}}
.gh__link:hover,.gh__item:focus-within .gh__link{{color:#fff;background:rgba(255,255,255,.06)}}
.gh__item--has>.gh__link::after{{content:"";width:5px;height:5px;border-right:1.6px solid currentColor;
  border-bottom:1.6px solid currentColor;transform:rotate(45deg) translateY(-2px);margin-left:2px}}
.gh__drop{{position:absolute;top:calc(100% + 8px);left:0;min-width:288px;padding:8px;border-radius:16px;
  background:var(--panel);border:1px solid var(--line2);box-shadow:0 26px 54px -24px #000;
  display:none;flex-direction:column;gap:2px}}
.gh__item:hover .gh__drop,.gh__item:focus-within .gh__drop{{display:flex}}
.gh__di{{display:flex;align-items:center;gap:11px;padding:9px 11px;border-radius:11px;color:#d4dcec;
  font-size:14.5px}}
.gh__di:hover{{background:rgba(129,140,248,.15);color:#fff}}
.gh__di .svc-card__ic,.gh__di .gh__ic{{width:36px;height:36px;padding:7px;flex-shrink:0;border-radius:10px;
  background:linear-gradient(150deg,rgba(129,140,248,.16),rgba(34,211,238,.1));border:1px solid var(--line2)}}
.gh__di small{{display:block;color:var(--dim);font-size:12px;margin-top:1px}}
.gh__right{{display:flex;align-items:center;gap:10px;margin-left:auto}}
.gh__ghost{{padding:9px 16px;border-radius:10px;border:1px solid var(--line2);color:#e6ebf6;
  font-size:14.5px;font-weight:500;background:none;cursor:pointer;font-family:inherit}}
.gh__ghost:hover{{border-color:var(--accent);color:#fff}}
.gh__main{{padding:9px 18px;border-radius:10px;font-size:14.5px;font-weight:600;
  background:linear-gradient(92deg,var(--accent),var(--accent2));color:var(--ink)}}
.gh__burger{{display:none;width:44px;height:44px;border-radius:10px;border:1px solid var(--line2);
  background:none;cursor:pointer;color:#fff;font-size:20px;margin-left:auto}}
{theme.ICON_CSS}
.gh__di:hover .svc-ico{{transform:scale(1.12);color:var(--accent2)}}
@media (max-width:1040px){{
  .gh__nav,.gh__right{{display:none}}
  .gh__burger{{display:block}}
  .gh.is-open .gh__nav{{display:flex;position:absolute;top:66px;left:0;right:0;flex-direction:column;
    align-items:stretch;gap:2px;padding:12px;background:var(--panel);border-bottom:1px solid var(--line2);
    max-height:calc(100vh - 66px);overflow-y:auto}}
  .gh.is-open .gh__drop{{position:static;display:flex;border:none;background:rgba(0,0,0,.2);
    box-shadow:none;margin:2px 0 6px}}
  .gh.is-open .gh__item--has>.gh__link::after{{margin-left:auto}}
}}
"""


def menu_item(label, url, children):
    if not children:
        return f'<div class="gh__item"><a class="gh__link" href="{E(url)}">{E(label)}</a></div>'
    di = ""
    for child in children:
        if len(child) == 3:  # с иконкой и подписью
            ico, ctitle, curl = child
            di += (f'<a class="gh__di" href="{E(curl)}"><span class="svc-card__ic">{ico}</span>'
                   f'<span><strong style="font-weight:600">{E(ctitle)}</strong></span></a>')
        else:
            ctitle, curl = child
            di += f'<a class="gh__di" href="{E(curl)}"><span>{E(ctitle)}</span></a>'
    return (f'<div class="gh__item gh__item--has"><a class="gh__link" href="{E(url)}">{E(label)}</a>'
            f'<div class="gh__drop">{di}</div></div>')


def header_html():
    # ИИ-помощники
    a_children = [(a[1], a[2], "/ai-pomoshnik/" + {"uchitel": "dlya-uchitelya", "ucheba": "dlya-ucheby",
                   "yurist": "yurist", "biznes": "dlya-biznesa"}[a[0]] + "/") for a in ASSISTANTS]
    items = [f'<div class="gh__item gh__item--has"><a class="gh__link" href="/ai-pomoshnik/">ИИ-помощники</a>'
             f'<div class="gh__drop">' +
             "".join(f'<a class="gh__di" href="{E(u)}"><span class="gh__ic" style="display:grid;place-items:center;font-size:18px">{e}</span><span><strong style="font-weight:600">{E(n)}</strong></span></a>'
                     for e, n, u in a_children) + '</div></div>']
    # группы сервисов
    for gid, gname, gdesc in sd.GROUPS:
        children = [(icons.svg(s[4], "svc-ico"), s[1], s[6]) for s in sd.by_group(gid)]
        items.append(menu_item(gname, "#servisy", children))
    # отдельные пункты
    items.append(menu_item("Презентации", sd.EXTRA["slides"][1], []))
    items.append(menu_item("Фоновая музыка", sd.EXTRA["bg_music"][1], []))
    items.append(menu_item("Каталог звуков", sd.EXTRA["catalog"][1], []))
    items.append(menu_item("Блог", sd.EXTRA["blog"][1], []))
    nav = "".join(items)
    return f"""<!-- Шапка genius-bot.ru. В Header Builder темы Impreza вставить элементом HTML,
     родное меню темы скрыть. Самодостаточна: свои стили и мобильный режим. -->
<style>{HEADER_CSS}</style>
<header class="gh" id="ghHeader">
  <div class="gh__in">
    <a class="gh__logo" href="/"><span aria-hidden="true">🤖</span> Genius<b>bot</b></a>
    <nav class="gh__nav" aria-label="Основное меню">{nav}</nav>
    <div class="gh__right">
      <button type="button" class="gh__ghost kie-auth-open-trigger">Войти</button>
      <a class="gh__main" href="/ai-pomoshnik/">Открыть ассистентов</a>
    </div>
    <button type="button" class="gh__burger" aria-label="Меню" aria-expanded="false">☰</button>
  </div>
</header>
<script>
(function(){{var h=document.getElementById('ghHeader');if(!h)return;
var b=h.querySelector('.gh__burger');
b.addEventListener('click',function(){{var o=h.classList.toggle('is-open');
b.setAttribute('aria-expanded',o?'true':'false');b.textContent=o?'✕':'☰';}});
h.querySelectorAll('.gh__item--has>.gh__link').forEach(function(l){{
  l.addEventListener('click',function(e){{
    if(window.matchMedia('(max-width:1040px)').matches){{e.preventDefault();
      l.parentNode.classList.toggle('is-exp');
      var d=l.nextElementSibling;if(d)d.style.display=l.parentNode.classList.contains('is-exp')?'flex':'';}}
  }});}});}})();
</script>"""


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--img", default="{IMG}")
    args = ap.parse_args()
    OUT.mkdir(parents=True, exist_ok=True)
    (OUT / "home.html").write_text(home_html(args.img), encoding="utf-8")
    (OUT / "header.html").write_text(header_html(), encoding="utf-8")
    print(f"  home.html {len((OUT/'home.html').read_text(encoding='utf-8'))//1024} КБ, "
          f"header.html {len((OUT/'header.html').read_text(encoding='utf-8'))//1024} КБ")


if __name__ == "__main__":
    main()
