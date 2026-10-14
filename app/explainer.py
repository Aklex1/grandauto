"""Ролики-объяснялки: анимированные сцены, озвучка, субтитры.

Чем это отличается от музыкального конвейера. Там картинка одна на весь ролик,
а смысл в звуке. Здесь наоборот: говорит диктор, а кадр должен показывать то,
о чём речь, — карточку сервиса, пункт договора, замену формулировки. Поэтому
кадры рисуются покадрово на Pillow и отдаются ffmpeg потоком, без промежуточных
файлов: сорок секунд вертикального ролика — это полторы тысячи кадров, и писать
их на диск незачем.

Озвучка идёт через тот же KIE, что и весь завод (ElevenLabs, запасной — Gemini).
Если ключа нет — берётся локальный голос piper, чтобы ролик можно было собрать
и посмотреть без трат.

Субтитры отдаются файлом .ass и вжигаются ffmpeg'ом — тем же способом, что и в
остальном заводе, через фильтр ass. Там, где libass не собран, подписи можно
нарисовать прямо в кадре: captions="draw".
"""
from __future__ import annotations

import logging
import math
import shutil
import subprocess
from dataclasses import dataclass, field
from pathlib import Path
from typing import Callable, Optional, Sequence

from PIL import Image, ImageDraw, ImageFilter, ImageFont

log = logging.getLogger("cf.explainer")

# Палитра взята с сайта сервиса: тёмно-синий фон, карточка чуть светлее,
# янтарная плитка со знаком, белый заголовок и приглушённый текст.
BG_TOP = (14, 21, 38)
BG_BOTTOM = (10, 15, 28)
CARD = (24, 34, 54)
CARD_LINE = (38, 50, 74)
WHITE = (238, 243, 250)
MUTED = (142, 155, 179)
AMBER = (232, 185, 49)
INK = (26, 22, 8)
RED = (226, 96, 96)
GREEN = (86, 198, 142)

BOLD = "/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf"
PLAIN = "/usr/share/fonts/truetype/liberation/LiberationSans-Regular.ttf"
FPS = 30


@dataclass
class Scene:
    """Одна сцена: что говорит диктор и что при этом на экране."""

    kind: str                      # intro | talk | card | clause | faq | offer | outro
    voice: str = ""                # текст озвучки; он же идёт в субтитры
    title: str = ""
    sub: str = ""
    bullets: Sequence[str] = ()
    bad: str = ""                  # формулировка против вас
    good: str = ""                 # чем её заменить
    badge: str = ""
    glyph: str = "doc"
    hold: float = 0.6              # пауза после реплики, секунды
    seconds: float = 0.0           # задаётся при сборке, из длины озвучки


def _font(path: str, size: int) -> ImageFont.FreeTypeFont:
    return ImageFont.truetype(path, size)


def _ease(t: float) -> float:
    t = max(0.0, min(1.0, t))
    return 1 - (1 - t) ** 3


def _wrap(draw: ImageDraw.ImageDraw, text: str, fnt, width: float) -> list[str]:
    out, line = [], ""
    for word in text.split():
        probe = f"{line} {word}".strip()
        if draw.textlength(probe, font=fnt) <= width or not line:
            line = probe
        else:
            out.append(line)
            line = word
    if line:
        out.append(line)
    return out


def _backdrop(size: tuple[int, int], phase: float) -> Image.Image:
    """Фон: градиент плюс два медленно дышащих пятна света.

    Совсем статичный фон на видео читается как картинка, а не как ролик.
    Движение намеренно слабое: оно не должно спорить с текстом.
    """
    w, h = size
    img = Image.new("RGB", (w, h))
    draw = ImageDraw.Draw(img)
    for y in range(h):
        k = y / max(1, h - 1)
        draw.line((0, y, w, y), fill=(
            int(BG_TOP[0] + (BG_BOTTOM[0] - BG_TOP[0]) * k),
            int(BG_TOP[1] + (BG_BOTTOM[1] - BG_TOP[1]) * k),
            int(BG_TOP[2] + (BG_BOTTOM[2] - BG_TOP[2]) * k)))

    small = (max(1, w // 5), max(1, h // 5))
    glow = Image.new("RGB", small, (0, 0, 0))
    gd = ImageDraw.Draw(glow)
    gw, gh = small
    for cx, cy, rel, colour in (
            (0.24 + 0.05 * math.sin(phase), 0.20 + 0.04 * math.cos(phase * 0.7),
             0.40, (30, 52, 104)),
            (0.80 + 0.04 * math.cos(phase * 0.8), 0.80 + 0.05 * math.sin(phase),
             0.34, (48, 36, 84))):
        x, y = int(gw * cx), int(gh * cy)
        rad = max(4, int(min(gw, gh) * rel))
        for step in range(rad, 0, -3):
            k = 1 - step / rad
            gd.ellipse((x - step, y - step, x + step, y + step),
                       fill=tuple(int(c * k * k) for c in colour))
    glow = glow.filter(ImageFilter.GaussianBlur(10)).resize((w, h), Image.BILINEAR)
    return Image.blend(img, glow, 0.5).point(lambda v: min(255, int(v * 1.35)))


def _card(img: Image.Image, box, *, radius: int = 28, fill=CARD, line=CARD_LINE,
          alpha: int = 255) -> None:
    layer = Image.new("RGBA", img.size, (0, 0, 0, 0))
    d = ImageDraw.Draw(layer)
    d.rounded_rectangle(box, radius=radius, fill=fill + (alpha,),
                        outline=line + (alpha,), width=2)
    img.paste(Image.alpha_composite(img.convert("RGBA"), layer).convert("RGB"), (0, 0))


def _tile(img: Image.Image, xy, size: int, *, glyph: str = "doc", colour=AMBER) -> None:
    """Плитка со знаком — как на сайте."""
    x, y = xy
    layer = Image.new("RGBA", img.size, (0, 0, 0, 0))
    d = ImageDraw.Draw(layer)
    d.rounded_rectangle((x, y, x + size, y + size), radius=size // 4, fill=colour + (255,))
    pad = size * 0.26
    wide = max(2, size // 20)
    if glyph == "doc":
        d.rounded_rectangle((x + pad, y + pad * 0.75, x + size - pad, y + size - pad * 0.75),
                            radius=4, outline=INK + (255,), width=wide)
        for i in range(3):
            yy = y + pad * 1.25 + i * size * 0.14
            d.line((x + pad * 1.45, yy, x + size - pad * 1.45, yy), fill=INK + (255,),
                   width=max(2, size // 26))
    elif glyph == "lens":
        r = size * 0.18
        cx, cy = x + size * 0.44, y + size * 0.42
        d.ellipse((cx - r, cy - r, cx + r, cy + r), outline=INK + (255,), width=wide)
        d.line((cx + r * 0.72, cy + r * 0.72, x + size - pad * 0.85, y + size - pad * 0.85),
               fill=INK + (255,), width=max(3, size // 16))
    elif glyph == "check":
        d.line((x + size * 0.28, y + size * 0.52, x + size * 0.44, y + size * 0.69),
               fill=INK + (255,), width=max(3, size // 13))
        d.line((x + size * 0.44, y + size * 0.69, x + size * 0.74, y + size * 0.32),
               fill=INK + (255,), width=max(3, size // 13))
    elif glyph == "warn":
        d.polygon([(x + size * 0.5, y + pad * 0.7), (x + size - pad * 0.7, y + size - pad),
                   (x + pad * 0.7, y + size - pad)], outline=INK + (255,), width=wide)
        d.line((x + size * 0.5, y + size * 0.42, x + size * 0.5, y + size * 0.63),
               fill=INK + (255,), width=wide)
        d.ellipse((x + size * 0.47, y + size * 0.70, x + size * 0.53, y + size * 0.76),
                  fill=INK + (255,))
    img.paste(Image.alpha_composite(img.convert("RGBA"), layer).convert("RGB"), (0, 0))


# ---------------------------------------------------------------- сцены


def _draw_scene(scene: Scene, t: float, size: tuple[int, int], brand: str,
                *, over_video: bool = False) -> Image.Image:
    """Кадр сцены на момент t (секунды от её начала).

    over_video=True — рисуем только надписи и карточки на прозрачном слое: фоном
    пойдёт сгенерированный клип. Поверх него кладём затемнение, иначе белый
    текст на светлом кадре пропадает.
    """
    w, h = size
    tall = h > w
    unit = w / (1080.0 if tall else 1920.0)
    if over_video:
        img = Image.new("RGB", size, (8, 12, 22))
        veil = Image.new("L", size, 150)
        vd = ImageDraw.Draw(veil)
        # Снизу темнее — там подписи и призыв.
        for y in range(h):
            k = y / max(1, h - 1)
            vd.line((0, y, w, y), fill=int(120 + 110 * k ** 1.6))
        mask = veil
    else:
        img = _backdrop(size, t * 0.35)
        mask = None
    draw = ImageDraw.Draw(img)
    pad = int(w * 0.075)
    appear = _ease(t / 0.45)          # общий вход карточек
    box_w = w - pad * 2

    def head(text: str, y: int, px: int, colour=WHITE, fnt_path=BOLD) -> int:
        fnt = _font(fnt_path, int(px * unit))
        for line in _wrap(draw, text, fnt, box_w):
            draw.text((pad, y), line, font=fnt, fill=colour)
            y += int(fnt.size * 1.18)
        return y

    if scene.kind == "intro":
        # Заставка: знак, название сервиса, тонкая линия, которая выезжает.
        side = int(w * (0.20 if tall else 0.12))
        top = int(h * (0.32 if tall else 0.30))
        _tile(img, ((w - side) // 2, top), side, glyph="lens")
        fnt = _font(BOLD, int((78 if tall else 64) * unit))
        name = brand.upper()
        draw.text(((w - draw.textlength(name, font=fnt)) / 2, top + side + int(40 * unit)),
                  name, font=fnt, fill=WHITE)
        sub = _font(PLAIN, int((36 if tall else 30) * unit))
        if scene.sub:
            draw.text(((w - draw.textlength(scene.sub, font=sub)) / 2,
                       top + side + int(130 * unit)), scene.sub, font=sub, fill=MUTED)
        line_w = int(box_w * 0.5 * _ease(t / 0.9))
        y = top + side + int(200 * unit)
        draw.rounded_rectangle(((w - line_w) // 2, y, (w + line_w) // 2, y + int(6 * unit)),
                               radius=int(3 * unit), fill=AMBER)

    elif scene.kind == "talk":
        y = int(h * (0.30 if tall else 0.26))
        if over_video:
            fnt = _font(PLAIN, int((38 if tall else 32) * unit))
            rows = len(_wrap(draw, scene.sub, fnt, box_w)) if scene.sub else 0
            head_rows = len(_wrap(draw, scene.title, _font(BOLD, int((76 if tall else 64)
                                                                    * unit)), box_w))
            block = int((head_rows * (76 if tall else 64) * 1.2
                         + rows * fnt.size * 1.4 + (90 if scene.badge else 0) + 80) * unit)
            _card(img, (pad - int(28 * unit), y - int(28 * unit),
                        w - pad + int(28 * unit), y + block), radius=int(30 * unit))
        y += int((1 - appear) * 40 * unit)
        if scene.badge:
            bf = _font(BOLD, int(30 * unit))
            bw = int(draw.textlength(scene.badge, font=bf) + 44 * unit)
            draw.rounded_rectangle((pad, y, pad + bw, y + int(56 * unit)),
                                   radius=int(28 * unit), fill=AMBER)
            draw.text((pad + int(22 * unit), y + int(12 * unit)), scene.badge,
                      font=bf, fill=INK)
            y += int(90 * unit)
        y = head(scene.title, y, 76 if tall else 64)
        if scene.sub:
            y += int(16 * unit)
            fnt = _font(PLAIN, int((38 if tall else 32) * unit))
            for line in _wrap(draw, scene.sub, fnt, box_w):
                draw.text((pad, y), line, font=fnt, fill=MUTED)
                y += int(fnt.size * 1.3)

    elif scene.kind == "card":
        # Карточка как на сайте: плитка со знаком, заголовок, пояснение,
        # и список, который проявляется по одному пункту.
        top = int(h * (0.26 if tall else 0.20))
        card_h = int(h * (0.42 if tall else 0.56))
        _card(img, (pad, top, w - pad, top + card_h), radius=int(32 * unit),
              alpha=int(255 * appear))
        side = int(96 * unit)
        _tile(img, (pad + int(44 * unit), top + int(44 * unit)), side, glyph=scene.glyph)
        y = top + int(44 * unit) + side + int(36 * unit)
        fnt = _font(BOLD, int((46 if tall else 40) * unit))
        for line in _wrap(draw, scene.title, fnt, box_w - int(88 * unit)):
            draw.text((pad + int(44 * unit), y), line, font=fnt, fill=WHITE)
            y += int(fnt.size * 1.2)
        y += int(14 * unit)
        fnt = _font(PLAIN, int((34 if tall else 28) * unit))
        for line in _wrap(draw, scene.sub, fnt, box_w - int(88 * unit)):
            draw.text((pad + int(44 * unit), y), line, font=fnt, fill=MUTED)
            y += int(fnt.size * 1.35)
        for index, item in enumerate(scene.bullets):
            start = 0.5 + index * 0.45
            k = _ease((t - start) / 0.4)
            if k <= 0:
                continue
            y += int(10 * unit)
            dot = int(10 * unit)
            cy = y + int(fnt.size * 0.55)
            draw.ellipse((pad + int(50 * unit), cy - dot, pad + int(50 * unit) + dot * 2,
                          cy + dot), fill=AMBER)
            shade = tuple(int(MUTED[i] + (WHITE[i] - MUTED[i]) * k) for i in range(3))
            draw.text((pad + int(92 * unit), y), item, font=fnt, fill=shade)
            y += int(fnt.size * 1.5)

    elif scene.kind == "clause":
        # Пункт договора: сначала красная подсветка «против вас», потом
        # зелёная замена выезжает снизу.
        top = int(h * (0.22 if tall else 0.16))
        fnt = _font(PLAIN, int((32 if tall else 27) * unit))
        bad_lines = _wrap(draw, scene.bad, fnt, box_w - int(80 * unit))
        bad_h = int(len(bad_lines) * fnt.size * 1.35 + 150 * unit)
        _card(img, (pad, top, w - pad, top + bad_h), radius=int(26 * unit),
              fill=(44, 26, 32), line=(92, 44, 52), alpha=int(255 * appear))
        label = _font(BOLD, int(26 * unit))
        draw.rounded_rectangle((pad + int(34 * unit), top + int(30 * unit),
                                pad + int(34 * unit) + int(draw.textlength(
                                    "ПРОТИВ ВАС", font=label) + 40 * unit),
                                top + int(30 * unit) + int(48 * unit)),
                               radius=int(24 * unit), fill=RED)
        draw.text((pad + int(54 * unit), top + int(41 * unit)), "ПРОТИВ ВАС",
                  font=label, fill=(255, 240, 240))
        y = top + int(104 * unit)
        # Текст проявляется словами — как будто его читают.
        total = sum(len(line) for line in bad_lines)
        shown = int(total * min(1.0, max(0.0, (t - 0.3) / 1.1)))
        used = 0
        for line in bad_lines:
            part = line[:max(0, shown - used)]
            used += len(line)
            draw.text((pad + int(40 * unit), y), part, font=fnt, fill=(255, 226, 226))
            y += int(fnt.size * 1.35)

        if scene.good:
            start = 1.7
            k = _ease((t - start) / 0.5)
            if k > 0:
                gy = top + bad_h + int(44 * unit) + int((1 - k) * 60 * unit)
                good_lines = _wrap(draw, scene.good, fnt, box_w - int(80 * unit))
                good_h = int(len(good_lines) * fnt.size * 1.35 + 150 * unit)
                _card(img, (pad, gy, w - pad, gy + good_h), radius=int(26 * unit),
                      fill=(20, 44, 36), line=(38, 92, 72), alpha=int(255 * k))
                draw.rounded_rectangle((pad + int(34 * unit), gy + int(30 * unit),
                                        pad + int(34 * unit) + int(draw.textlength(
                                            "ЗАМЕНА", font=label) + 40 * unit),
                                        gy + int(30 * unit) + int(48 * unit)),
                                       radius=int(24 * unit), fill=GREEN)
                draw.text((pad + int(54 * unit), gy + int(41 * unit)), "ЗАМЕНА",
                          font=label, fill=(8, 30, 22))
                yy = gy + int(104 * unit)
                for line in good_lines:
                    draw.text((pad + int(40 * unit), yy), line, font=fnt,
                              fill=(222, 255, 240))
                    yy += int(fnt.size * 1.35)

    elif scene.kind == "faq":
        top = int(h * (0.26 if tall else 0.22))
        side = int(84 * unit)
        if over_video:
            # На снятом фоне белый текст без подложки теряется — кладём карточку.
            fnt = _font(PLAIN, int((34 if tall else 29) * unit))
            rows = len(_wrap(draw, scene.sub, fnt, box_w - int(80 * unit)))
            block = side + int((150 + rows * fnt.size * 1.35) * 1.0)
            _card(img, (pad - int(28 * unit), top - int(28 * unit),
                        w - pad + int(28 * unit), top + block),
                  radius=int(30 * unit))
        _tile(img, (pad, top), side, glyph="warn" if scene.badge else "doc")
        y = top + side + int(34 * unit)
        y = head(scene.title, y, 50 if tall else 44)
        y += int(18 * unit)
        fnt = _font(PLAIN, int((34 if tall else 29) * unit))
        shown = int(len(scene.sub) * min(1.0, max(0.0, (t - 0.25) / 1.4)))
        for line in _wrap(draw, scene.sub[:shown], fnt, box_w):
            draw.text((pad, y), line, font=fnt, fill=MUTED)
            y += int(fnt.size * 1.35)

    elif scene.kind == "offer":
        top = int(h * (0.30 if tall else 0.26))
        side = int(110 * unit)
        _tile(img, (pad, top), side, glyph="check", colour=GREEN)
        y = top + side + int(40 * unit)
        big = _font(BOLD, int((120 if tall else 96) * unit))
        number = scene.badge or "5"
        draw.text((pad, y), number, font=big, fill=AMBER)
        nx = pad + int(draw.textlength(number, font=big) + 26 * unit)
        fnt = _font(BOLD, int((54 if tall else 46) * unit))
        draw.text((nx, y + int(big.size * 0.32)), scene.title, font=fnt, fill=WHITE)
        y += int(big.size * 1.1)
        fnt = _font(PLAIN, int((36 if tall else 30) * unit))
        for line in _wrap(draw, scene.sub, fnt, box_w):
            draw.text((pad, y), line, font=fnt, fill=MUTED)
            y += int(fnt.size * 1.35)

    elif scene.kind == "outro":
        y = int(h * (0.30 if tall else 0.28))
        y = head(scene.title, y, 70 if tall else 60)
        y += int(24 * unit)
        fnt = _font(PLAIN, int((36 if tall else 31) * unit))
        for line in _wrap(draw, scene.sub, fnt, box_w):
            draw.text((pad, y), line, font=fnt, fill=MUTED)
            y += int(fnt.size * 1.35)
        # Стрелка вниз — к описанию. Пульсирует, чтобы взгляд её поймал.
        if tall:
            cx = w // 2
            ay = int(h * 0.74) + int(math.sin(t * 3.2) * 10 * unit)
            arm = int(46 * unit)
            draw.line((cx, ay - arm, cx, ay + arm), fill=AMBER, width=int(8 * unit))
            draw.line((cx - arm * 0.7, ay + arm * 0.3, cx, ay + arm), fill=AMBER,
                      width=int(8 * unit))
            draw.line((cx + arm * 0.7, ay + arm * 0.3, cx, ay + arm), fill=AMBER,
                      width=int(8 * unit))
        bf = _font(BOLD, int((34 if tall else 30) * unit))
        if scene.badge:
            bw = int(draw.textlength(scene.badge, font=bf) + 60 * unit)
            bx = (w - bw) // 2 if tall else pad
            by = int(h * (0.82 if tall else 0.74))
            draw.rounded_rectangle((bx, by, bx + bw, by + int(72 * unit)),
                                   radius=int(36 * unit), fill=AMBER)
            draw.text((bx + int(30 * unit), by + int(18 * unit)), scene.badge,
                      font=bf, fill=INK)

    # Знак канала в углу — кроме заставки, где он и так крупно.
    if scene.kind != "intro":
        mark = _font(BOLD, int(26 * unit))
        draw.text((pad, int(h * 0.055)), brand.upper(), font=mark,
                  fill=(150, 165, 190) if mask is not None else (96, 110, 134))
    if mask is not None:
        # Там, где мы ничего не рисовали, слой должен быть полупрозрачным, а под
        # карточками и текстом — плотным. Берём яркость нарисованного как добавку
        # к общей вуали.
        from PIL import ImageChops

        drawn = img.convert("L").point(lambda v: 255 if v > 26 else 0)
        alpha = ImageChops.lighter(mask, drawn.filter(ImageFilter.GaussianBlur(6)))
        out = img.convert("RGBA")
        out.putalpha(alpha)
        return out
    return img


# ---------------------------------------------------------------- субтитры


def _ass_time(seconds: float) -> str:
    seconds = max(0.0, seconds)
    hours, rest = divmod(int(seconds), 3600)
    minutes, secs = divmod(rest, 60)
    cs = int(round((seconds - int(seconds)) * 100))
    return f"{hours}:{minutes:02d}:{secs:02d}.{cs:02d}"


def _sentences(text: str) -> list[str]:
    import re

    parts = [part.strip() for part in re.split(r"(?<=[.!?…])\s+", text) if part.strip()]
    return parts or ([text] if text.strip() else [])


def write_ass(scenes: Sequence[Scene], dst: Path, size: tuple[int, int]) -> Path:
    """Субтитры под вжигание ffmpeg'ом (фильтр ass), по предложениям.

    Делим реплику на предложения и раздаём им время пропорционально длине: без
    распознавания речи это самая честная раскладка, а для диктора, читающего
    ровно наш текст, она попадает в такт.
    """
    w, h = size
    tall = h > w
    size_pt = 54 if tall else 40
    margin_v = int(h * (0.12 if tall else 0.08))
    head = (
        "[Script Info]\nScriptType: v4.00+\nWrapStyle: 2\n"
        f"PlayResX: {w}\nPlayResY: {h}\nScaledBorderAndShadow: yes\n\n"
        "[V4+ Styles]\n"
        "Format: Name, Fontname, Fontsize, PrimaryColour, SecondaryColour, OutlineColour,"
        " BackColour, Bold, Italic, Underline, StrikeOut, ScaleX, ScaleY, Spacing, Angle,"
        " BorderStyle, Outline, Shadow, Alignment, MarginL, MarginR, MarginV, Encoding\n"
        f"Style: Sub,Liberation Sans,{size_pt},&H00FFFFFF,&H00FFFFFF,&H00120A05,"
        f"&H96000000,-1,0,0,0,100,100,0,0,3,4,0,2,{int(w * 0.07)},{int(w * 0.07)},"
        f"{margin_v},1\n\n"
        "[Events]\nFormat: Layer, Start, End, Style, Name, MarginL, MarginR, MarginV,"
        " Effect, Text\n")
    rows: list[str] = []
    clock = 0.0
    for scene in scenes:
        said = max(0.1, scene.seconds - scene.hold)
        parts = _sentences(scene.voice)
        total = sum(len(p) for p in parts) or 1
        at = clock
        for part in parts:
            span = said * len(part) / total
            rows.append(f"Dialogue: 0,{_ass_time(at)},{_ass_time(at + span)},Sub,,0,0,0,,"
                        + part.replace("\n", " "))
            at += span
        clock += scene.seconds
    dst.write_text(head + "\n".join(rows) + "\n", encoding="utf-8")
    return dst


# ---------------------------------------------------------------- озвучка


def kie_voice(client, *, model: str, voice_id: str, speed: float = 1.0) -> Callable:
    """Озвучка через KIE — тот же путь, что у остального завода."""
    from . import tts

    def speak(text: str, dest: Path) -> Path:
        result = tts.synthesize(client, text, dest, model=model, voice_id=voice_id,
                                speed=speed)
        return Path(result.path) if hasattr(result, "path") else dest

    return speak


def piper_voice(model_path: Path) -> Callable:
    """Запасной локальный голос: нужен, чтобы собрать и посмотреть ролик без трат."""

    def speak(text: str, dest: Path) -> Path:
        wav = dest.with_suffix(".wav")
        subprocess.run(["python3", "-m", "piper", "-m", str(model_path), "-f", str(wav)],
                       input=text.encode("utf-8"), check=True,
                       stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        return wav

    return speak


def _duration(path: Path) -> float:
    out = subprocess.run(["ffprobe", "-v", "error", "-show_entries", "format=duration",
                          "-of", "csv=p=0", str(path)], capture_output=True, text=True,
                         check=True).stdout.strip()
    return float(out or 0.0)


# ---------------------------------------------------------------- сборка


def _background(clips: Sequence[Path], scenes: Sequence[Scene], size: tuple[int, int],
                workdir: Path, fps: int) -> Path:
    """Фон из сгенерированных клипов: каждому эпизоду свой, по кругу.

    Клипы короткие (несколько секунд), поэтому каждый зацикливается на длину
    своей сцены. Кадр подгоняем с обрезкой по центру: растянутый выглядит браком.
    """
    w, h = size
    parts: list[Path] = []
    for index, scene in enumerate(scenes):
        clip = clips[index % len(clips)]
        part = workdir / f"bg_{index:02d}.mp4"
        subprocess.run([
            "ffmpeg", "-hide_banner", "-loglevel", "error", "-y",
            "-stream_loop", "-1", "-i", str(clip), "-t", f"{scene.seconds:.3f}",
            "-vf", (f"scale={w}:{h}:force_original_aspect_ratio=increase,"
                    f"crop={w}:{h},fps={fps},format=yuv420p"),
            "-an", "-c:v", "libx264", "-preset", "veryfast", "-crf", "20", str(part),
        ], check=True)
        parts.append(part)
    listing = workdir / "bg.txt"
    listing.write_text("".join(f"file '{p.as_posix()}'\n" for p in parts), encoding="utf-8")
    joined = workdir / "bg.mp4"
    subprocess.run(["ffmpeg", "-hide_banner", "-loglevel", "error", "-y", "-f", "concat",
                    "-safe", "0", "-i", str(listing), "-c", "copy", str(joined)], check=True)
    return joined


def build(scenes: list[Scene], dst: Path, *, size: tuple[int, int], workdir: Path,
          speak: Callable, brand: str = "GENIUS", music: Optional[Path] = None,
          captions: str = "ass", fps: int = FPS,
          clips: Optional[Sequence[Path]] = None) -> Path:
    """Собрать ролик: озвучка, кадры, субтитры, музыкальная подложка.

    Кадры не пишутся на диск — идут в ffmpeg сырым потоком. Для вертикального
    ролика это полторы тысячи кадров, и промежуточные PNG только тратили бы
    время и место.
    """
    workdir.mkdir(parents=True, exist_ok=True)
    voices: list[Path] = []
    for index, scene in enumerate(scenes):
        if not scene.voice.strip():
            scene.seconds = max(scene.seconds, 2.0)
            continue
        ready = sorted(workdir.glob(f"voice_{index:02d}.*"))
        ready = [path for path in ready if path.suffix in (".mp3", ".wav")]
        stamp = workdir / f"voice_{index:02d}.txt"
        same = stamp.is_file() and stamp.read_text(encoding="utf-8") == scene.voice
        if ready and same:
            part = ready[0]
            log.info("Реплика %s уже озвучена — беру готовую", index + 1)
        else:
            part = speak(scene.voice, workdir / f"voice_{index:02d}.mp3")
            stamp.write_text(scene.voice, encoding="utf-8")
        voices.append(part)
        scene.seconds = _duration(part) + scene.hold
        log.info("Сцена %s (%s): %.1f с", index + 1, scene.kind, scene.seconds)

    total = sum(scene.seconds for scene in scenes)
    if total <= 0:
        raise RuntimeError("нечего собирать: все сцены пустые")

    # Дорожка: реплики подряд, с паузой после каждой.
    # Склейку делаем фильтром, а не демультиплексором concat. Демультиплексор
    # требует одинаковых параметров у всех кусков и берёт их у первого: реплики
    # приходят 24 кГц моно, тишина строилась 48 кГц стерео, и дорожка речи
    # выходила вдвое длиннее — голос звучал замедленным, а конец обрезало по
    # длине видео. Здесь каждый кусок приводится к 48 кГц стерео и получает свою
    # паузу через apad.
    speech = workdir / "speech.m4a"
    holds = [scene.hold for scene in scenes if scene.voice.strip()]
    inputs: list[str] = []
    chain: list[str] = []
    for index, part in enumerate(voices):
        inputs += ["-i", str(part)]
        chain.append(f"[{index}:a]aresample=48000,"
                     f"aformat=sample_fmts=fltp:channel_layouts=stereo,"
                     f"apad=pad_dur={holds[index]:.3f}[a{index}]")
    graph = ";".join(chain) + ";" + "".join(f"[a{i}]" for i in range(len(voices))) \
        + f"concat=n={len(voices)}:v=0:a=1[out]"
    subprocess.run(["ffmpeg", "-hide_banner", "-loglevel", "error", "-y", *inputs,
                    "-filter_complex", graph, "-map", "[out]", "-c:a", "aac",
                    "-b:a", "192k", "-ar", "48000", "-ac", "2", str(speech)], check=True)

    # Сверяем длины: расхождение значит, что звук и картинка разъедутся, а
    # поймать это на слух можно и через неделю после публикации.
    made = _duration(speech)
    if abs(made - total) > 0.5:
        raise RuntimeError(f"дорожка речи {made:.1f} с, а ролик {total:.1f} с — "
                           f"звук разъедется с картинкой")

    if music is not None and music.is_file():
        mixed = workdir / "mixed.m4a"
        subprocess.run(["ffmpeg", "-hide_banner", "-loglevel", "error", "-y",
                        "-i", str(speech), "-stream_loop", "-1", "-i", str(music),
                        "-filter_complex",
                        "[1:a]volume=-22dB[m];[0:a][m]amix=inputs=2:duration=first:"
                        "dropout_transition=0,loudnorm=I=-16:TP=-1.5:LRA=11[a]",
                        "-map", "[a]", "-c:a", "aac", "-b:a", "192k", str(mixed)],
                       check=True)
        speech = mixed

    silent = workdir / "silent.mp4"
    over_video = bool(clips)
    if over_video:
        bg = _background(clips, scenes, size, workdir, fps)
        args = ["ffmpeg", "-hide_banner", "-loglevel", "error", "-y",
                "-i", str(bg),
                "-f", "rawvideo", "-pix_fmt", "rgba", "-s", f"{size[0]}x{size[1]}",
                "-r", str(fps), "-i", "pipe:0",
                "-filter_complex", "[0:v][1:v]overlay=shortest=1,format=yuv420p",
                "-c:v", "libx264", "-preset", "medium", "-crf", "20", str(silent)]
    else:
        args = ["ffmpeg", "-hide_banner", "-loglevel", "error", "-y",
                "-f", "rawvideo", "-pix_fmt", "rgb24", "-s", f"{size[0]}x{size[1]}",
                "-r", str(fps), "-i", "pipe:0",
                "-c:v", "libx264", "-preset", "medium", "-crf", "20",
                "-pix_fmt", "yuv420p", str(silent)]
    proc = subprocess.Popen(args, stdin=subprocess.PIPE)
    try:
        for scene in scenes:
            frames = max(1, int(round(scene.seconds * fps)))
            for number in range(frames):
                frame = _draw_scene(scene, number / fps, size, brand,
                                    over_video=over_video)
                if captions == "draw":
                    _caption(frame, scene, number / fps, size)
                proc.stdin.write(frame.tobytes())
    finally:
        proc.stdin.close()
        proc.wait()
    if proc.returncode:
        raise RuntimeError(f"ffmpeg не собрал кадры: код {proc.returncode}")

    body = workdir / "body.mp4"
    subprocess.run(["ffmpeg", "-hide_banner", "-loglevel", "error", "-y", "-i", str(silent),
                    "-i", str(speech), "-map", "0:v:0", "-map", "1:a:0", "-shortest",
                    "-c:v", "copy", "-c:a", "aac", "-b:a", "192k",
                    "-movflags", "+faststart", str(body)], check=True)

    ass = write_ass(scenes, workdir / "subs.ass", size)
    if captions == "ass":
        escaped = str(ass).replace("\\", "/").replace(":", r"\:").replace("'", r"\'")
        subprocess.run(["ffmpeg", "-hide_banner", "-loglevel", "error", "-y", "-i", str(body),
                        "-vf", f"ass='{escaped}'", "-c:v", "libx264", "-preset", "medium",
                        "-crf", "20", "-pix_fmt", "yuv420p", "-c:a", "copy",
                        "-movflags", "+faststart", str(dst)], check=True)
    else:
        shutil.copy(body, dst)
    shutil.copy(ass, dst.with_suffix(".ass"))
    log.info("Ролик собран: %s, %.0f с", dst.name, total)
    return dst


def _caption(img: Image.Image, scene: Scene, t: float, size: tuple[int, int]) -> None:
    """Подпись прямо в кадре — там, где libass не собран."""
    if not scene.voice.strip():
        return
    w, h = size
    tall = h > w
    unit = w / (1080.0 if tall else 1920.0)
    said = max(0.1, scene.seconds - scene.hold)
    parts = _sentences(scene.voice)
    total = sum(len(p) for p in parts) or 1
    at = 0.0
    current = parts[-1]
    for part in parts:
        span = said * len(part) / total
        if t < at + span:
            current = part
            break
        at += span
    draw = ImageDraw.Draw(img)
    fnt = _font(BOLD, int((46 if tall else 36) * unit))
    lines = _wrap(draw, current, fnt, w * 0.86)
    line_h = int(fnt.size * 1.26)
    block = len(lines) * line_h
    y = int(h * (0.86 if tall else 0.90)) - block
    for line in lines:
        x = (w - draw.textlength(line, font=fnt)) / 2
        draw.text((x, y), line, font=fnt, fill=WHITE,
                  stroke_width=max(3, int(5 * unit)), stroke_fill=(10, 12, 20))
        y += line_h
