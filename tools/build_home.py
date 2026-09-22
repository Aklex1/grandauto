#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Сборка новой главной genius-bot.ru и современной шапки.

    python3 tools/build_home.py [--img https://genius-bot.ru/wp-content/uploads/2026/09]

Результат:
  content/landings/home.html    — содержимое главной страницы
  content/landings/header.html  — шапка с меню (отдельный блок для Header Builder темы)
"""
from __future__ import annotations

import argparse
import html
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
OUT = ROOT / "content" / "landings"
E = html.escape

MENU = [
    ("ИИ-ассистенты", "/ai-pomoshnik/", [
        ("📘 Для учителя", "/ai-pomoshnik/dlya-uchitelya/"),
        ("🎒 Для учёбы", "/ai-pomoshnik/dlya-ucheby/"),
        ("⚖️ Документы и договоры", "/ai-pomoshnik/yurist/"),
        ("💼 Консультант для бизнеса", "/ai-pomoshnik/dlya-biznesa/"),
    ]),
    ("Сервисы", "/sounds-catalog/", [
        ("🎙 Озвучка текста", "/tts-pricing/"),
        ("📝 Аудио в текст", "/audio-v-tekst/"),
        ("🎬 Слайды нейросетью", "/sozdat-prezentaciyu/"),
        ("🎵 Создать музыку", "/sozdat-muzyku/"),
        ("🗣 Говорящий аватар", "/govoryashchiy-avatar/"),
    ]),
    ("Боты под ключ", "/zakazat/", []),
    ("Цены", "/tts-pricing/", []),
    ("Блог", "/blog/", []),
]

SERVICES = [
    ("🎙", "Озвучка текста", "67 голосов, живая интонация, экспорт в MP3.", "/tts-pricing/"),
    ("📝", "Аудио в текст", "Расшифровка записи, лекции или созвона.", "/audio-v-tekst/"),
    ("🎬", "Презентации", "Слайды по теме за минуты, аналог Gamma.", "/sozdat-prezentaciyu/"),
    ("🎵", "Музыка и песни", "Трек с вокалом или фон без авторских прав.", "/sozdat-muzyku/"),
    ("🗣", "Говорящий аватар", "Видео из фотографии с озвучкой.", "/govoryashchiy-avatar/"),
    ("🔇", "Убрать вокал и шум", "Минусовка и чистая речь из любой записи.", "/ubrat-vokal/"),
]

STEPS = [
    ("Выберите ассистента", "Каждый заточен под свою работу: урок, задача, договор, клиент. "
                            "Универсальный чат проигрывает специалисту на каждой из этих задач."),
    ("Напишите задачу словами", "Без промптов и без обучения. «Конспект по теме Х для 6 класса» — "
                                "этого достаточно, остальное ассистент знает сам."),
    ("Заберите результат", "Готовый документ, который можно копировать и сдавать. "
                           "В Telegram и на сайте — один аккаунт и один баланс."),
]

HEADER_CSS = """
.gaH{--bg:rgba(7,11,20,.88);--line:#1e293b;--text:#f1f5f9;--muted:#94a3b8;--accent:#818cf8;
  --accent2:#22d3ee;position:sticky;top:0;z-index:900;background:var(--bg);
  backdrop-filter:saturate(140%) blur(14px);border-bottom:1px solid var(--line);
  font-family:Rubik,-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif;
  margin:0 calc(50% - 50vw);width:100vw}
.gaH *,.gaH *::before,.gaH *::after{box-sizing:border-box}
.gaH__in{max-width:1180px;margin:0 auto;padding:0 20px;height:64px;display:flex;align-items:center;gap:22px}
.gaH__logo{display:flex;align-items:center;gap:9px;font-weight:700;font-size:17px;color:var(--text);
  text-decoration:none;white-space:nowrap}
.gaH__logo b{background:linear-gradient(92deg,var(--accent),var(--accent2));
  -webkit-background-clip:text;background-clip:text;color:transparent}
.gaH__nav{display:flex;align-items:center;gap:3px;flex:1}
.gaH__item{position:relative}
.gaH__link{display:inline-flex;align-items:center;gap:6px;padding:9px 13px;border-radius:10px;
  color:var(--muted);text-decoration:none;font-size:14.5px;font-weight:500;white-space:nowrap;
  transition:color .15s,background .15s}
.gaH__link:hover,.gaH__item:focus-within .gaH__link{color:var(--text);background:rgba(255,255,255,.06)}
.gaH__item--has::after{content:"";width:5px;height:5px;border-right:1.5px solid currentColor;
  border-bottom:1.5px solid currentColor;transform:rotate(45deg) translateY(-2px);
  display:inline-block;margin-left:-6px;color:var(--muted);pointer-events:none}
.gaH__drop{position:absolute;top:calc(100% + 8px);left:0;min-width:262px;padding:8px;
  border-radius:14px;background:#0f172a;border:1px solid var(--line);
  box-shadow:0 22px 48px -24px #000;display:none;flex-direction:column;gap:2px}
.gaH__item:hover .gaH__drop,.gaH__item:focus-within .gaH__drop{display:flex}
.gaH__drop a{padding:9px 12px;border-radius:9px;color:#cbd5e1;text-decoration:none;font-size:14.5px}
.gaH__drop a:hover{background:rgba(129,140,248,.14);color:#fff}
.gaH__right{display:flex;align-items:center;gap:10px;margin-left:auto}
.gaH__ghost{padding:9px 16px;border-radius:10px;border:1px solid var(--line);color:#cbd5e1;
  text-decoration:none;font-size:14.5px;font-weight:500;background:none;cursor:pointer;
  font-family:inherit}
.gaH__ghost:hover{border-color:var(--accent);color:#fff}
.gaH__main{padding:9px 18px;border-radius:10px;text-decoration:none;font-size:14.5px;font-weight:600;
  background:linear-gradient(92deg,var(--accent),var(--accent2));color:#070b14}
.gaH__burger{display:none;width:42px;height:42px;border-radius:10px;border:1px solid var(--line);
  background:none;cursor:pointer;color:var(--text);font-size:20px;line-height:1;margin-left:auto}
@media (max-width:1000px){
  .gaH__nav,.gaH__right{display:none}
  .gaH__burger{display:block}
  .gaH__in{gap:12px}
  .gaH.is-open .gaH__nav{display:flex;position:absolute;top:64px;left:0;right:0;flex-direction:column;
    align-items:stretch;gap:2px;padding:12px;background:#0b1220;border-bottom:1px solid var(--line)}
  .gaH.is-open .gaH__drop{position:static;display:flex;border:none;background:none;box-shadow:none;
    padding:0 0 6px 14px}
  .gaH.is-open .gaH__right{display:flex;position:absolute;top:auto;left:0;right:0;padding:0 12px 14px;
    background:#0b1220;transform:translateY(100%);bottom:0}
}
"""

HOME_CSS = """
.gaP{--bg:#070b14;--panel:#0f172a;--panel2:#131c2e;--line:#1e293b;--text:#f1f5f9;
  --muted:#94a3b8;--accent:#818cf8;--accent2:#22d3ee;background:var(--bg);color:var(--text);
  font-family:Rubik,-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif;
  line-height:1.6;font-size:16px;margin:0 calc(50% - 50vw);width:100vw;overflow-x:hidden}
.gaP *,.gaP *::before,.gaP *::after{box-sizing:border-box}
.gaP h1,.gaP h2,.gaP h3{color:#fff;margin:0;line-height:1.16;text-wrap:balance;font-weight:700}
.gaP p{margin:0}
.gaP__in{max-width:1180px;margin:0 auto;padding-left:20px;padding-right:20px}
.gaP__sec{padding-block:clamp(46px,6.5vw,82px)}
.gaP__sec--alt{background:var(--panel);border-block:1px solid var(--line)}
.gaP__h2{font-size:clamp(24px,3.6vw,36px);letter-spacing:-.015em;margin-bottom:10px}
.gaP__sub{color:var(--muted);max-width:62ch;margin-bottom:32px}

.gaP__hero{position:relative;overflow:hidden;padding-block:clamp(60px,9.5vw,116px);
  border-bottom:1px solid var(--line)}
.gaP__heroBg{position:absolute;inset:0;background-size:cover;background-position:center;opacity:.55}
.gaP__heroBg::after{content:"";position:absolute;inset:0;
  background:linear-gradient(100deg,#070b14 10%,rgba(7,11,20,.8) 52%,rgba(7,11,20,.3) 100%)}
.gaP__heroIn{position:relative;max-width:690px}
.gaP__badge{display:inline-flex;align-items:center;gap:8px;padding:6px 14px;border-radius:999px;
  background:rgba(129,140,248,.12);border:1px solid rgba(129,140,248,.4);color:#c7d2fe;
  font-size:13px;font-weight:600}
.gaP__hero h1{margin:20px 0 16px;font-size:clamp(33px,6vw,58px);letter-spacing:-.025em}
.gaP__hero h1 em{font-style:normal;background:linear-gradient(92deg,var(--accent),var(--accent2));
  -webkit-background-clip:text;background-clip:text;color:transparent}
.gaP__lead{font-size:clamp(16px,2.1vw,20px);color:#cbd5e1;max-width:58ch}
.gaP__cta{display:flex;flex-wrap:wrap;gap:12px;margin-top:30px}
.gaP__btn{display:inline-flex;align-items:center;gap:8px;padding:15px 28px;border-radius:14px;
  font-weight:600;font-size:15.5px;text-decoration:none;border:1px solid transparent;transition:.15s}
.gaP__btn--main{background:linear-gradient(92deg,var(--accent),var(--accent2));color:#070b14}
.gaP__btn--main:hover{filter:brightness(1.07)}
.gaP__btn--ghost{background:rgba(255,255,255,.05);border-color:var(--line);color:#e2e8f0}
.gaP__btn--ghost:hover{border-color:var(--accent);color:#fff}
.gaP__trust{margin-top:26px;color:#64748b;font-size:13.5px}

.gaP__steps{display:grid;gap:16px;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));
  counter-reset:s}
.gaP__step{position:relative;padding:24px 20px 20px;border-radius:16px;background:var(--panel2);
  border:1px solid var(--line);counter-increment:s}
.gaP__step::before{content:counter(s);position:absolute;top:-14px;left:20px;width:30px;height:30px;
  display:grid;place-items:center;border-radius:9px;
  background:linear-gradient(92deg,var(--accent),var(--accent2));color:#070b14;font-weight:700;
  font-size:14px}
.gaP__step h3{font-size:17px;margin:6px 0 7px}
.gaP__step p{color:var(--muted);font-size:14.5px}

.gaP__svcs{display:grid;gap:14px;grid-template-columns:repeat(auto-fit,minmax(232px,1fr))}
.gaP__svc{display:flex;flex-direction:column;gap:6px;padding:18px;border-radius:14px;
  background:var(--panel2);border:1px solid var(--line);text-decoration:none;color:var(--text);
  transition:border-color .15s,transform .15s}
.gaP__svc:hover{border-color:var(--accent2);transform:translateY(-2px)}
.gaP__svc i{font-style:normal;font-size:23px;line-height:1}
.gaP__svc strong{font-size:15.5px}
.gaP__svc span{color:var(--muted);font-size:13.5px}

.gaP__dev{display:grid;gap:26px;grid-template-columns:minmax(0,1.1fr) minmax(0,.9fr);align-items:center}
.gaP__devList{display:flex;flex-direction:column;gap:12px;margin:18px 0 26px;padding:0;list-style:none}
.gaP__devList li{padding-left:26px;position:relative;color:#cbd5e1;font-size:15px}
.gaP__devList li::before{content:"";position:absolute;left:0;top:.55em;width:9px;height:9px;
  border-radius:3px;background:var(--accent2)}
.gaP__devCard{padding:26px 24px;border-radius:18px;background:var(--panel2);border:1px solid var(--line)}
.gaP__devCard .gaP__price{font-size:30px;font-weight:700;color:#fff;line-height:1.1}
.gaP__devCard p{color:var(--muted);font-size:14.5px;margin-top:8px}

.gaP__blog{display:grid;gap:16px;grid-template-columns:repeat(auto-fit,minmax(268px,1fr))}

@media (max-width:820px){.gaP__dev{grid-template-columns:1fr}}
@media (prefers-reduced-motion:reduce){.gaP__svc,.gaP__btn{transition:none}}
"""


def header_html() -> str:
    items = []
    for label, url, children in MENU:
        cls = "gaH__item gaH__item--has" if children else "gaH__item"
        drop = ""
        if children:
            links = "".join(f'<a href="{E(u)}">{E(t)}</a>' for t, u in children)
            drop = f'<div class="gaH__drop">{links}</div>'
        items.append(f'<div class="{cls}"><a class="gaH__link" href="{E(url)}">{E(label)}</a>{drop}</div>')
    nav = "".join(items)
    return f"""<!-- Шапка genius-bot.ru. Вставлять в Header Builder темы Impreza элементом HTML,
     родное меню темы при этом скрыть. Самодостаточна: свои стили, свой мобильный режим. -->
<style>{HEADER_CSS.strip()}</style>

<header class="gaH" id="gaHeader">
  <div class="gaH__in">
    <a class="gaH__logo" href="/"><span aria-hidden="true">🤖</span> Genius<b>bot</b></a>
    <nav class="gaH__nav" aria-label="Основное меню">{nav}</nav>
    <div class="gaH__right">
      <button type="button" class="gaH__ghost kie-auth-open-trigger">Войти</button>
      <a class="gaH__main" href="/ai-pomoshnik/">Открыть ассистентов</a>
    </div>
    <button type="button" class="gaH__burger" aria-label="Меню" aria-expanded="false">☰</button>
  </div>
</header>

<script>
(function () {{
  var head = document.getElementById('gaHeader');
  if (!head) return;
  var burger = head.querySelector('.gaH__burger');
  burger.addEventListener('click', function () {{
    var open = head.classList.toggle('is-open');
    burger.setAttribute('aria-expanded', open ? 'true' : 'false');
    burger.textContent = open ? '✕' : '☰';
  }});
}})();
</script>
"""


def home_html(img_base: str) -> str:
    steps = "".join(
        f'<article class="gaP__step"><h3>{E(t)}</h3><p>{E(d)}</p></article>' for t, d in STEPS)
    svcs = "".join(
        f'<a class="gaP__svc" href="{E(u)}"><i aria-hidden="true">{E(ic)}</i>'
        f'<strong>{E(t)}</strong><span>{E(d)}</span></a>'
        for ic, t, d, u in SERVICES)

    return f"""<!-- Главная genius-bot.ru. Собрано tools/build_home.py — правьте скрипт, не этот файл. -->
<style>{HOME_CSS.strip()}</style>

<div class="gaP">

  <section class="gaP__hero">
    <div class="gaP__heroBg" style="background-image:url('{img_base}/home-hero.webp')"></div>
    <div class="gaP__in gaP__heroIn">
      <span class="gaP__badge">Первая волна · 4 ассистента уже работают</span>
      <h1>ИИ-ассистенты, которые <em>делают работу</em>, а не отвечают на вопросы</h1>
      <p class="gaP__lead">Конспект урока, разбор задачи, проверка договора, ответ клиенту.
         Каждый ассистент заточен под свою задачу и работает в Telegram и на сайте —
         один аккаунт, один баланс, никаких подписок на каждый сервис.</p>
      <div class="gaP__cta">
        <a class="gaP__btn gaP__btn--main" href="#assistenty">Выбрать ассистента</a>
        <a class="gaP__btn gaP__btn--ghost" href="/zakazat/">Заказать своего бота</a>
      </div>
      <p class="gaP__trust">Первые сообщения бесплатны · Вход почтой, через VK или Telegram ·
         Оплата картой и ЮMoney</p>
    </div>
  </section>

  <section class="gaP__sec" id="assistenty">
    <div class="gaP__in">
      <h2 class="gaP__h2">Ассистенты</h2>
      <p class="gaP__sub">Один универсальный чат проигрывает специалисту на каждой конкретной
         задаче. Поэтому у нас не один бот на всё, а несколько — каждый со своими сценариями.</p>
      [genius_assistants_gallery]
    </div>
  </section>

  <section class="gaP__sec gaP__sec--alt">
    <div class="gaP__in">
      <h2 class="gaP__h2">Как это работает</h2>
      <p class="gaP__sub">Три шага, без обучения промптам.</p>
      <div class="gaP__steps">{steps}</div>
    </div>
  </section>

  <section class="gaP__sec">
    <div class="gaP__in">
      <h2 class="gaP__h2">И ещё сервисы на том же балансе</h2>
      <p class="gaP__sub">Деньги на счёте общие: пополнили один раз — пользуетесь всем.</p>
      <div class="gaP__svcs">{svcs}</div>
    </div>
  </section>

  <section class="gaP__sec gaP__sec--alt">
    <div class="gaP__in">
      <div class="gaP__dev">
        <div>
          <h2 class="gaP__h2">Нужен свой бот, а не коробка</h2>
          <p class="gaP__sub" style="margin-bottom:0">Разработка телеграм-ботов и чат-ботов
             для ВКонтакте — наш основной профиль с 2021 года.</p>
          <ul class="gaP__devList">
            <li>Консультант по вашей базе знаний, с передачей заявки менеджеру</li>
            <li>Запись, оплата и напоминания клиентам</li>
            <li>Интеграция с amoCRM, Битрикс24 или вашим вебхуком</li>
            <li>Закрытые каналы с платной подпиской</li>
          </ul>
          <a class="gaP__btn gaP__btn--main" href="/zakazat/">Обсудить задачу</a>
        </div>
        <div class="gaP__devCard">
          <span class="gaP__price">от 30 000 ₽</span>
          <p>Срок типового бота — 10–14 дней. Смета после короткого разговора о задаче,
             без брифов на двадцать вопросов.</p>
          <p style="margin-top:14px"><strong style="color:#fff">Коробка — 2 900 ₽/мес.</strong>
             Если задача типовая, начните с неё и не платите за разработку.</p>
        </div>
      </div>
    </div>
  </section>

  <section class="gaP__sec">
    <div class="gaP__in">
      <h2 class="gaP__h2">Блог</h2>
      <p class="gaP__sub">Разборы, инструкции и промты — то, что мы сами используем в работе.</p>
      <div class="gaP__blog">
        [us_grid post_type="post" items_quantity="6" columns="3" orderby="date"
                 items_layout="blog_classic_1" pagination="none"]
      </div>
      <p style="margin-top:22px"><a class="gaP__btn gaP__btn--ghost" href="/blog/">Все статьи</a></p>
    </div>
  </section>

</div>
"""


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--img", default="{IMG}")
    args = parser.parse_args()
    OUT.mkdir(parents=True, exist_ok=True)
    (OUT / "home.html").write_text(home_html(args.img), encoding="utf-8")
    (OUT / "header.html").write_text(header_html(), encoding="utf-8")
    print("  home.html и header.html собраны")


if __name__ == "__main__":
    main()
