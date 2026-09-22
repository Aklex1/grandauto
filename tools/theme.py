# -*- coding: utf-8 -*-
"""Единая палитра. «В стиле микросервисов, но чуть светлее»: та же связка
индиго #818cf8 + циан #22d3ee и шрифт Rubik, но база поднята с почти-чёрного
#0a0f1a до более светлого сине-стального.
"""

TOKENS = """
  --bg:#161f36; --bg2:#111a2e; --panel:#1f2942; --panel2:#27324f; --panel3:#2d3956;
  --line:#33406230; --line2:#3a4568; --text:#eef2fb; --muted:#a3b0cd; --dim:#7c8aab;
  --accent:#818cf8; --accent2:#22d3ee; --accent3:#a78bfa; --ink:#0b1220;
"""

FONT = ('Rubik,-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif')

# Анимация SVG-иконок при наведении на карточку сервиса.
ICON_CSS = """
.svc-ico{width:100%;height:100%;color:var(--accent);transition:color .2s ease,transform .35s cubic-bezier(.2,.8,.2,1)}
.svc-ico *{transform-box:fill-box;transform-origin:center}
.svc-card:hover .svc-ico,.svc-tile:hover .svc-ico{transform:scale(1.09);color:var(--accent2)}
.gb-wave{stroke-dasharray:44;stroke-dashoffset:0}
@media (prefers-reduced-motion:no-preference){
  .svc-card:hover .gb-spark,.svc-tile:hover .gb-spark{animation:gb-pulse 1s ease-in-out infinite alternate}
  .svc-card:hover .gb-play,.svc-tile:hover .gb-play{animation:gb-nudge .8s ease-in-out infinite alternate}
  .svc-card:hover .gb-wave,.svc-tile:hover .gb-wave{animation:gb-draw 1.1s ease forwards}
  .svc-card:hover .gb-zoom,.svc-tile:hover .gb-zoom{animation:gb-zoom 1s ease-in-out infinite alternate}
  .svc-card:hover .gb-bar,.svc-tile:hover .gb-bar{animation:gb-eq .7s ease-in-out infinite alternate}
}
@keyframes gb-pulse{from{opacity:.4;transform:scale(.8)}to{opacity:1;transform:scale(1.15)}}
@keyframes gb-nudge{from{transform:translateX(-1.5px)}to{transform:translateX(2.5px)}}
@keyframes gb-draw{from{stroke-dashoffset:44}to{stroke-dashoffset:0}}
@keyframes gb-zoom{from{transform:scale(.82)}to{transform:scale(1.04)}}
@keyframes gb-eq{from{transform:scaleY(.72)}to{transform:scaleY(1.12)}}
"""

# Интеграция с темой Impreza. Через :has() применяется ТОЛЬКО на страницах,
# где есть наш контент (.gp/.gaL), другие страницы не затрагиваются.
THEME_FIX = """
body:has(.gp),body:has(.gaL){background:#161f36!important}
.l-canvas:has(.gp),.l-main:has(.gp),#page-content:has(.gp),
.l-canvas:has(.gaL),.l-main:has(.gaL),#page-content:has(.gaL){background:#161f36!important}
.l-section:has(.gp),.l-section:has(.gaL){padding:0!important;min-height:0!important;border:0!important;background:transparent!important}
.l-section:has(.gp)>.l-section-h,.l-section:has(.gaL)>.l-section-h{max-width:none!important;padding:0!important;margin:0!important;width:100%!important}
.l-section:has(.gp) p:empty,.l-section:has(.gaL) p:empty{display:none!important}
.l-section:has(.gp) h1,.l-section:has(.gp) h2,.l-section:has(.gp) h3,.l-section:has(.gp) p,.l-section:has(.gaL) h1,.l-section:has(.gaL) h2,.l-section:has(.gaL) h3,.l-section:has(.gaL) p{text-align:left!important}
.l-main:has(.gp) .l-section+.l-section,.l-main:has(.gaL) .l-section+.l-section{margin-top:0!important}
/* тёмная шапка темы под наш дизайн */
body:has(.gp) .l-header,body:has(.gaL) .l-header{background:rgba(17,26,46,.94)!important;border-bottom:1px solid #3a4568!important;-webkit-backdrop-filter:saturate(140%) blur(12px);backdrop-filter:saturate(140%) blur(12px)}
body:has(.gp) .l-header .w-nav-anchor,body:has(.gaL) .l-header .w-nav-anchor{color:#cdd6e8!important;font-weight:500}
body:has(.gp) .l-header .w-nav-anchor:hover,body:has(.gp) .l-header .current-menu-item>.w-nav-anchor,body:has(.gp) .l-header .current-menu-ancestor>.w-nav-anchor,body:has(.gaL) .l-header .w-nav-anchor:hover{color:#fff!important}
body:has(.gp) .l-header .w-nav-list.level_2,body:has(.gaL) .l-header .w-nav-list.level_2{background:#1f2942!important;border:1px solid #3a4568!important;border-radius:14px!important;box-shadow:0 24px 50px -24px #000!important}
body:has(.gp) .l-header .w-nav-list.level_2 .w-nav-anchor:hover,body:has(.gaL) .l-header .w-nav-list.level_2 .w-nav-anchor:hover{background:rgba(129,140,248,.16)!important}
body:has(.gp) .l-header .w-text-value,body:has(.gaL) .l-header .w-text-value{color:#fff!important}
body:has(.gp) .l-header .l-subheader,body:has(.gaL) .l-header .l-subheader,body:has(.gp) .l-header.sticky .l-subheader,body:has(.gaL) .l-header.sticky .l-subheader{background:transparent!important}
body:has(.gp) .l-header .w-btn,body:has(.gaL) .l-header .w-btn{color:#fff!important}
"""
