"""Обвязка кадра: шапка канала, плашка «сейчас играет», полоса хода.

Почему картинками, а не фильтрами ffmpeg. Шапка и плашка — это типографика с
разрядкой, скруглениями и пилюлей: drawtext разрядку не умеет, а drawbox не
умеет скруглять. Рисуем их один раз на канал в PNG и накладываем готовыми —
получается ровно как в макете, и на каждый ролик это ничего не стоит.

Меняющийся текст — название трека, исполнитель, «что дальше» — остаётся за
drawtext: он разный в каждый момент, и картинкой его не положишь.
"""
from __future__ import annotations

import logging
from pathlib import Path
from typing import Optional

from PIL import Image, ImageDraw, ImageFont

log = logging.getLogger("cf.chrome")

TEAL = (0x7F, 0xE3, 0xD4)
PINK = (0xF2, 0x9A, 0xB8)
WHITE = (0xEC, 0xF2, 0xFA)
MUTED = (0x9A, 0xA6, 0xBC)
CARD_BG = (0x0E, 0x14, 0x20)

BOLD = "/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf"
PLAIN = "/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf"


def _font(path: str, size: int) -> ImageFont.FreeTypeFont:
    return ImageFont.truetype(path, size)


def _spaced(draw: ImageDraw.ImageDraw, xy: tuple, text: str,
            font: ImageFont.FreeTypeFont, fill: tuple, tracking: float) -> int:
    """Текст с разрядкой: Pillow её не умеет, кладём по букве. Возвращает ширину."""
    x, y = xy
    start = x
    for char in text:
        draw.text((x, y), char, font=font, fill=fill)
        x += draw.textlength(char, font=font) + tracking
    return int(x - start)


def header(dst: Path, size: tuple[int, int], *, name: str, tagline: str,
           badge: str = "LIVE 24/7", logo: Optional[Path] = None) -> Path:
    """Шапка канала: знак, название, пилюля и подпись с разрядкой."""
    w, h = size
    layer = Image.new("RGBA", (w, h), (0, 0, 0, 0))
    draw = ImageDraw.Draw(layer)

    pad_x, pad_y = int(w * 0.038), int(h * 0.055)
    mark = int(h * 0.072)
    text_x = pad_x
    if logo is not None and logo.is_file():
        try:
            icon = Image.open(logo).convert("RGBA")
            icon = icon.resize((mark, mark), Image.LANCZOS)
            layer.alpha_composite(icon, (pad_x, pad_y))
            text_x = pad_x + mark + int(w * 0.016)
        except Exception as exc:  # noqa: BLE001 — без знака шапка всё равно нужна
            log.warning("Знак канала не наложен: %s", exc)

    title_font = _font(BOLD, int(h * 0.042))
    _spaced(draw, (text_x, pad_y - int(h * 0.004)), name.upper(), title_font, WHITE,
            tracking=int(h * 0.004))

    row_y = pad_y + int(h * 0.046)
    small = _font(BOLD, int(h * 0.019))
    label = f"● {badge}".upper()
    pill_w = int(draw.textlength(label, font=small) + len(label) * 1.6 + h * 0.028)
    pill_h = int(h * 0.034)
    draw.rounded_rectangle((text_x, row_y, text_x + pill_w, row_y + pill_h),
                           radius=pill_h // 2, fill=PINK + (255,))
    _spaced(draw, (text_x + int(h * 0.012), row_y + int(pill_h * 0.24)), label,
            small, (0x1A, 0x10, 0x18), tracking=1.4)

    tag_font = _font(PLAIN, int(h * 0.020))
    _spaced(draw, (text_x + pill_w + int(w * 0.013), row_y + int(pill_h * 0.22)),
            tagline.upper(), tag_font, MUTED, tracking=int(h * 0.0035))
    layer.save(dst)
    return dst


def card(dst: Path, size: tuple[int, int], box: tuple[int, int, int, int], *,
         bar: tuple[int, int, int, int], with_next: bool = True) -> Path:
    """Плашка «сейчас играет» со всем, что на ней не меняется.

    Скругление, подпись «NOW PLAYING», подложка полосы хода и метка «UP NEXT» —
    всё это одинаково в каждом кадре, поэтому место им в картинке, а не в графе
    фильтров: там они стоили бы лишних проходов на каждом кадре.
    """
    w, h = size
    x, y, bw, bh = box
    layer = Image.new("RGBA", (w, h), (0, 0, 0, 0))
    draw = ImageDraw.Draw(layer)
    radius = int(bh * 0.14)
    draw.rounded_rectangle((x, y, x + bw, y + bh), radius=radius,
                           fill=CARD_BG + (200,), outline=(0x2A, 0x36, 0x4E, 170),
                           width=2)

    pad = int(bh * 0.17)
    _spaced(draw, (x + pad, y + pad), "NOW PLAYING", _font(BOLD, int(bh * 0.125)),
            TEAL, tracking=int(bh * 0.022))

    bx, by, bw2, bh2 = bar
    draw.rounded_rectangle((bx, by, bx + bw2, by + bh2), radius=bh2 // 2,
                           fill=MUTED + (90,))

    if with_next:
        right = w - int(w * 0.038)
        label = _font(BOLD, int(h * 0.016))
        text = "UP NEXT"
        width = sum(draw.textlength(c, font=label) for c in text) + len(text) * 2.4
        _spaced(draw, (right - width, y + int(bh * 0.52)), text, label, MUTED,
                tracking=2.4)
    layer.save(dst)
    return dst


def progress_steps(folder: Path, width: int, height: int, steps: int) -> list[Path]:
    """Полоса хода переливом: по картинке на каждую ступень заполнения.

    Перелив нельзя нарисовать drawbox'ом, а менять ширину на лету ffmpeg не даёт:
    размер кадра внутри графа должен быть постоянным. Поэтому готовим ступени
    заранее — одни и те же для всех композиций, ведь доля заполнения у них общая.
    """
    folder.mkdir(parents=True, exist_ok=True)
    full = Image.new("RGBA", (width, height), (0, 0, 0, 0))
    pixels = full.load()
    left, right = TEAL, (0x9B, 0x8C, 0xE8)
    for px in range(width):
        k = px / max(1, width - 1)
        color = tuple(int(left[i] + (right[i] - left[i]) * k) for i in range(3))
        for py in range(height):
            pixels[px, py] = color + (255,)

    out: list[Path] = []
    for step in range(1, steps + 1):
        cut = max(2, int(width * step / steps))
        piece = full.crop((0, 0, cut, height))
        path = folder / f"bar{step:02d}.png"
        piece.save(path)
        out.append(path)
    return out


def eq_comb(dst: Path, size: tuple[int, int], bars: int = 13) -> Path:
    """Трафарет для эквалайзера: отдельные столбики со скруглёнными концами.

    showfreqs рисует сплошной спектр, а на макете — отдельные столбики с
    промежутками. Поэтому спектр потом умножается на этот трафарет: что вне
    столбиков, становится прозрачным, и остаётся ровно нужная графика.
    """
    w, h = size
    layer = Image.new("RGBA", (w, h), (0, 0, 0, 0))
    draw = ImageDraw.Draw(layer)
    gap = w / bars * 0.38
    bar_w = (w - gap * (bars - 1)) / bars
    x = 0.0
    for _ in range(bars):
        draw.rounded_rectangle((x, 0, x + bar_w, h), radius=bar_w / 2,
                               fill=(255, 255, 255, 255))
        x += bar_w + gap
    layer.save(dst)
    return dst
