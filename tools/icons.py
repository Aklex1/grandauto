# -*- coding: utf-8 -*-
"""Линейные SVG-иконки микросервисов. 48×48, stroke=currentColor.
Анимация — через CSS по классам: .gb-bar (высота), .gb-spark (пульс),
.gb-play (сдвиг), .gb-wave (прорисовка). Иконка наследует цвет от карточки."""

_ICONS = {
    "image": """
      <rect x="7" y="10" width="34" height="28" rx="4"/>
      <circle class="gb-spark" cx="17" cy="19" r="3.2"/>
      <path d="M9 33l9-9 6 6 5-5 10 10"/>""",
    "edit": """
      <rect x="7" y="10" width="30" height="28" rx="4"/>
      <path d="M9 32l7-7 5 5-7 7"/>
      <path class="gb-spark" d="M33 8l2.4 4.6L40 15l-4.6 2.4L33 22l-2.4-4.6L26 15l4.6-2.4z"/>""",
    "upscale": """
      <rect class="gb-zoom" x="15" y="15" width="18" height="18" rx="3"/>
      <path d="M9 17V9h8M39 17V9h-8M9 31v8h8M39 31v8h-8"/>""",
    "revive": """
      <rect x="7" y="9" width="34" height="26" rx="4"/>
      <circle cx="16" cy="18" r="3"/>
      <path d="M9 30l8-7 6 5"/>
      <path class="gb-play" d="M27 20l11 6-11 6z" fill="currentColor" stroke="none"/>""",
    "video": """
      <rect x="7" y="12" width="34" height="24" rx="4"/>
      <path d="M7 19h34M14 12l-3 7M23 12l-3 7M32 12l-3 7"/>
      <path class="gb-play" d="M21 24l8 4-8 4z" fill="currentColor" stroke="none"/>""",
    "avatar": """
      <circle cx="19" cy="17" r="6"/>
      <path d="M9 39c0-6 5-10 10-10s10 4 10 10"/>
      <path class="gb-wave" d="M34 18c2 3 2 9 0 12"/>
      <path class="gb-wave" d="M39 14c4 6 4 14 0 20"/>""",
    "clipmusic": """
      <rect x="7" y="12" width="34" height="24" rx="4"/>
      <path d="M7 19h34M13 12l-2 7M22 12l-2 7"/>
      <circle class="gb-play" cx="22" cy="30" r="3"/>
      <path d="M25 30v-7l7-2v7"/>
      <circle cx="29" cy="28" r="3"/>""",
    "tts": """
      <path d="M9 20v8h6l9 7V13l-9 7z"/>
      <path class="gb-wave" d="M30 18c3 4 3 12 0 16"/>
      <path class="gb-wave" d="M35 14c5 6 5 18 0 24"/>""",
    "stt": """
      <path class="gb-bar" d="M8 24v-5M13 27v-11M18 30V14M23 26v-8"/>
      <path d="M29 16h11M29 22h11M29 28h8M29 34h11"/>""",
    "music": """
      <path d="M20 30V12l16-4v18"/>
      <circle class="gb-play" cx="15" cy="32" r="5"/>
      <circle cx="31" cy="28" r="5"/>""",
    "lyrics": """
      <circle class="gb-play" cx="13" cy="31" r="4"/>
      <path d="M17 31V15l8-2v14"/>
      <circle cx="21" cy="29" r="4"/>
      <path d="M30 16h10M30 23h10M30 30h7"/>""",
    "vocal": """
      <rect x="19" y="8" width="10" height="19" rx="5"/>
      <path d="M14 22a10 10 0 0 0 20 0M24 32v6M18 40h12"/>
      <path class="gb-spark" d="M37 12l2 3 3 2-3 2-2 3-2-3-3-2 3-2z"/>""",
    "denoise": """
      <path class="gb-bar" d="M9 24v-4M14 27v-10M19 30V14M24 27v-10M29 24v-4"/>
      <path class="gb-spark" d="M37 10l1.6 3.4L42 15l-3.4 1.6L37 20l-1.6-3.4L32 15l3.4-1.6z"/>
      <path d="M34 30h8"/>""",
    "sfx": """
      <circle class="gb-play" cx="24" cy="24" r="5"/>
      <path class="gb-wave" d="M24 12V6M24 42v-6M12 24H6M42 24h-6M15 15l-4-4M37 37l-4-4M33 15l4-4M11 37l4-4"/>""",
    "ytaudio": """
      <rect x="7" y="10" width="26" height="18" rx="4"/>
      <path class="gb-play" d="M17 15l7 4-7 4z" fill="currentColor" stroke="none"/>
      <path d="M24 33c0 0 4 5 9 5" fill="none"/>
      <path d="M35 24v11" />
      <circle class="gb-play" cx="32" cy="37" r="3.4"/>
      <path d="M35 24l6-2v11"/>
      <circle cx="38" cy="35" r="3.4"/>""",
}


def svg(key: str, cls: str = "svc-ico") -> str:
    inner = _ICONS.get(key, _ICONS["sfx"]).strip()
    return (f'<svg class="{cls}" viewBox="0 0 48 48" fill="none" '
            f'stroke="currentColor" stroke-width="2.2" stroke-linecap="round" '
            f'stroke-linejoin="round" aria-hidden="true">{inner}</svg>')


# Иконки-эмодзи ассистентов уже в пресетах; для плиток на главной берём их же.
