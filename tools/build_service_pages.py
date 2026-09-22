#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Страницы сервисов, у которых нет своей посадочной. Карточка на главной ведёт
сюда, а кнопка в Telegram-бота стоит уже на самой странице.

    python3 tools/build_service_pages.py
"""
from __future__ import annotations

import html, importlib.util, json
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
OUT = ROOT / "content" / "landings"
E = html.escape


def _load(name):
    spec = importlib.util.spec_from_file_location(name, ROOT / "tools" / f"{name}.py")
    m = importlib.util.module_from_spec(spec); spec.loader.exec_module(m); return m

theme = _load("theme"); icons = _load("icons")
BOT = "https://t.me/Neuro_HubAI_bot?start=web"

# slug, url, icon, accent, h1, lead, что умеет [3], как работает [3]
PAGES = [
 ("kartinka-neyrosetyu","/kartinka-neyrosetyu/","image","#818cf8",
  "Картинка по описанию нейросетью",
  "Опишите словами, что нужно нарисовать, — нейросеть создаст изображение. "
  "Для карточек товара, обложек, иллюстраций и постов. Без фотостоков и дизайнера.",
  [("Для чего","Карточки маркетплейсов, обложки статей и видео, иллюстрации, баннеры, аватары."),
   ("Что на входе","Текстовое описание на русском: объект, стиль, фон, настроение, формат."),
   ("Что на выходе","Готовое изображение в хорошем разрешении, можно сгенерировать варианты.")],
  [("Опишите картинку","«Кружка кофе на деревянном столе, тёплый свет, вид сверху»."),
   ("Нейросеть рисует","Несколько секунд — и изображение готово, при желании перегенерируйте."),
   ("Заберите файл","Скачайте результат и используйте где угодно.")]),
 ("izmenit-foto-neyrosetyu","/izmenit-foto-neyrosetyu/","edit","#22d3ee",
  "Изменить фото по описанию",
  "Замена фона и одежды, удаление лишних объектов, реставрация старых снимков — "
  "всё словами, без графического редактора и навыков ретуши.",
  [("Что меняем","Фон, одежду, цвет, убираем людей и предметы, восстанавливаем повреждённые фото."),
   ("Что на входе","Ваша фотография и короткое описание, что именно поправить."),
   ("Что на выходе","Обработанное фото; исходник остаётся у вас нетронутым.")],
  [("Загрузите фото","Пришлите снимок, который нужно изменить."),
   ("Скажите, что поправить","«Убери фон», «замени футболку на рубашку», «убери человека справа»."),
   ("Готово","Нейросеть вносит правки и присылает результат.")]),
 ("uluchshit-kachestvo-foto","/uluchshit-kachestvo-foto/","upscale","#a78bfa",
  "Увеличить качество фото",
  "Апскейл вдвое с восстановлением деталей: для старых снимков, мелких картинок "
  "и кадров из видео. Резче, чётче, крупнее — без мыла.",
  [("Кому нужно","Старые семейные фото, мелкие картинки из интернета, кадры из видео, сканы."),
   ("Что на входе","Изображение любого размера, даже сильно сжатое."),
   ("Что на выходе","Увеличенная копия с восстановленными деталями и резкостью.")],
  [("Загрузите картинку","Даже маленькую или размытую."),
   ("Нейросеть улучшает","Достраивает детали и повышает разрешение."),
   ("Скачайте результат","Крупное и чёткое изображение, готовое к печати или публикации.")]),
 ("video-neyrosetyu","/video-neyrosetyu/","video","#38bdf8",
  "Видео по описанию нейросетью",
  "Ролик из одного текста: сцена, движение и работа камеры — без исходной картинки. "
  "Для заставок, фонов, коротких роликов и тестов идей.",
  [("Для чего","Заставки, фоновые ролики, короткие сцены, визуализация идеи до съёмок."),
   ("Что на входе","Текстовое описание сцены: место, объекты, движение, настроение."),
   ("Что на выходе","Короткий видеоролик, готовый к монтажу.")],
  [("Опишите сцену","«Волны накатывают на берег на закате, медленный проезд камеры»."),
   ("Нейросеть снимает","Генерирует ролик по описанию."),
   ("Заберите видео","Скачайте и вставляйте в проект.")]),
]


def css(accent):
    return (":root{" + theme.TOKENS + "}\n" + theme.THEME_FIX + "\n" + f"""
.gsv{{--acc:{accent};background:var(--bg);color:var(--text);font-family:{theme.FONT};
  line-height:1.6;font-size:16px;margin:0 calc(50% - 50vw);width:100vw;overflow-x:hidden}}
.gsv *,.gsv *::before,.gsv *::after{{box-sizing:border-box}}
.gsv h1,.gsv h2,.gsv h3{{color:#fff;margin:0;line-height:1.16;text-wrap:balance;font-weight:700}}
.gsv p{{margin:0}} .gsv a{{color:inherit;text-decoration:none}}
.gsv__in{{max-width:1000px;margin:0 auto;padding:0 20px}}
.gsv__hero{{padding-block:clamp(52px,8vw,92px);border-bottom:1px solid var(--line2);text-align:center}}
.gsv__ic{{width:88px;height:88px;margin:0 auto 22px;padding:20px;border-radius:22px;color:var(--acc);
  background:linear-gradient(150deg,rgba(129,140,248,.16),rgba(34,211,238,.1));border:1px solid var(--line2)}}
.gsv__ic .svc-ico{{width:100%;height:100%}}
.gsv__hero h1{{font-size:clamp(30px,5vw,48px);letter-spacing:-.02em;margin-bottom:14px}}
.gsv__lead{{font-size:clamp(16px,2vw,19px);color:#d4dcec;max-width:60ch;margin:0 auto 28px}}
.gsv__btn{{display:inline-flex;align-items:center;gap:9px;padding:15px 30px;border-radius:14px;
  font-weight:700;font-size:16px;background:linear-gradient(92deg,var(--acc),#22d3ee);color:var(--ink)}}
.gsv__btn:hover{{filter:brightness(1.08)}}
.gsv__note{{margin-top:16px;color:var(--dim);font-size:13.5px}}
.gsv__sec{{padding-block:clamp(40px,6vw,72px)}}
.gsv__sec--soft{{background:var(--bg2);border-block:1px solid var(--line2)}}
.gsv__h2{{font-size:clamp(22px,3.2vw,30px);text-align:center;margin-bottom:30px}}
.gsv__grid{{display:grid;gap:16px;grid-template-columns:repeat(auto-fit,minmax(250px,1fr))}}
.gsv__card{{padding:22px 20px;border-radius:16px;background:var(--panel);border:1px solid var(--line2)}}
.gsv__card h3{{font-size:17px;margin-bottom:8px}}
.gsv__card p{{color:var(--muted);font-size:14.5px}}
.gsv__steps{{counter-reset:s;display:grid;gap:16px;grid-template-columns:repeat(auto-fit,minmax(250px,1fr))}}
.gsv__step{{position:relative;padding:24px 20px 20px;border-radius:16px;background:var(--panel);
  border:1px solid var(--line2);counter-increment:s}}
.gsv__step::before{{content:counter(s);position:absolute;top:-14px;left:20px;width:30px;height:30px;
  display:grid;place-items:center;border-radius:9px;background:linear-gradient(92deg,var(--acc),#22d3ee);
  color:var(--ink);font-weight:700;font-size:14px}}
.gsv__step h3{{font-size:16px;margin:6px 0 7px}} .gsv__step p{{color:var(--muted);font-size:14px}}
.gsv__end{{text-align:center;padding-block:clamp(44px,6vw,76px);
  background:radial-gradient(ellipse at 50% 0%,color-mix(in srgb,var(--acc) 18%,transparent),transparent 62%)}}
""")


def render(slug, url, icon, accent, h1, lead, does, steps):
    cards = "".join(f'<div class="gsv__card"><h3>{E(t)}</h3><p>{E(d)}</p></div>' for t, d in does)
    stp = "".join(f'<div class="gsv__step"><h3>{E(t)}</h3><p>{E(d)}</p></div>' for t, d in steps)
    ld = json.dumps({"@context":"https://schema.org","@type":"WebApplication","name":h1,
        "description":lead,"applicationCategory":"MultimediaApplication","operatingSystem":"Web, Telegram",
        "url":"https://genius-bot.ru"+url,"offers":{"@type":"Offer","price":"0","priceCurrency":"RUB"}},
        ensure_ascii=False)
    return f"""<style>{css(accent)}</style>
<div class="gsv">
  <section class="gsv__hero"><div class="gsv__in">
    <div class="gsv__ic">{icons.svg(icon)}</div>
    <h1>{E(h1)}</h1>
    <p class="gsv__lead">{E(lead)}</p>
    <a class="gsv__btn" href="{BOT}" target="_blank" rel="noopener">Открыть в Telegram-боте</a>
    <p class="gsv__note">Сервис работает в нашем боте. Вход тот же, что на сайте — баланс общий.</p>
  </div></section>
  <section class="gsv__sec"><div class="gsv__in">
    <h2 class="gsv__h2">Что умеет</h2>
    <div class="gsv__grid">{cards}</div>
  </div></section>
  <section class="gsv__sec gsv__sec--soft"><div class="gsv__in">
    <h2 class="gsv__h2">Как это работает</h2>
    <div class="gsv__steps">{stp}</div>
  </div></section>
  <section class="gsv__end"><div class="gsv__in">
    <h2 class="gsv__h2" style="margin-bottom:18px">Попробуйте прямо в Telegram</h2>
    <a class="gsv__btn" href="{BOT}" target="_blank" rel="noopener">Открыть бота</a>
  </div></section>
</div>
<script type="application/ld+json">{ld}</script>"""


def main():
    OUT.mkdir(parents=True, exist_ok=True)
    for slug, url, icon, accent, h1, lead, does, steps in PAGES:
        (OUT / f"svc-{slug}.html").write_text(render(slug, url, icon, accent, h1, lead, does, steps),
                                              encoding="utf-8")
        (OUT / f"svc-{slug}.json").write_text(json.dumps(
            {"slug": slug, "url": url, "title": h1 + " — Genius", "h1": h1, "description": lead},
            ensure_ascii=False), encoding="utf-8")
        print(f"  {slug}: {len((OUT / f'svc-{slug}.html').read_text(encoding='utf-8'))//1024} КБ")


if __name__ == "__main__":
    main()
