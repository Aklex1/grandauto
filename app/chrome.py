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

try:
    from PIL import Image, ImageDraw, ImageFont
except ImportError as exc:  # pragma: no cover — зависимость ставится отдельно
    raise ImportError(
        "Нужен Pillow: на сервере выполните "
        "cd /opt/contentfactory && venv/bin/pip install -r requirements.txt "
        "&& systemctl restart contentfactory"
    ) from exc

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


COVER_SIZE = (1280, 720)
COVER_VERTICAL = (1080, 1920)

# Цвета пилюли с длительностью. Яркое пятно нужно, чтобы обложка цепляла в
# ленте: тёмная сцена с белым текстом теряется среди таких же тёмных.
BADGE_COLORS = (
    ((0xB9, 0xA6, 0xF0), (0x1A, 0x14, 0x2E)),
    ((0xF8, 0xC9, 0x7A), (0x2A, 0x1C, 0x10)),
    ((0x7F, 0xE3, 0xD4), (0x10, 0x26, 0x24)),
    ((0xF2, 0x9A, 0xB8), (0x2A, 0x10, 0x1C)),
)


def _fit(draw: ImageDraw.ImageDraw, text: str, path: str, width: int,
         start: int) -> ImageFont.FreeTypeFont:
    """Самый крупный кегль, при котором строка влезает в ширину."""
    size = start
    while size > 18:
        font = _font(path, size)
        if draw.textlength(text, font=font) <= width:
            return font
        size -= 4
    return _font(path, 18)


def cover(dst: Path, scene: Path, *, title: str, note: str = "", badge: str = "",
          logo: Optional[Path] = None, accent: int = 0,
          size: tuple[int, int] = COVER_SIZE) -> Path:
    """Обложка ролика: кадр сцены, крупный заголовок, пилюля и знак канала.

    Рисуем сами по той же причине, что и заставки: генераторы картинок пишут
    кривые буквы, а здесь заголовок — главное. Сцену берём из того же ролика,
    поэтому обложка и видео выглядят одним целым.

    Цвет и контраст поднимаем намеренно: в ленте обложка соседствует с десятком
    таких же тёмных ночных картинок, и неподнятая теряется среди них.

    Работает и на горизонтальной обложке, и на вертикальной для шортса: кегли
    считаются от ширины, а отступы — от высоты, поэтому пропорции не ломаются.
    """
    from PIL import ImageEnhance

    w, h = size
    # Кегли от ширины: на вертикальной обложке высота втрое больше, и привязка
    # к ней дала бы буквы во весь кадр.
    unit = w / 1280.0
    base = Image.open(scene).convert("RGB")
    # Кадр подгоняем с обрезкой по центру: растянутый выглядит браком.
    ratio_src = base.width / base.height
    ratio_dst = w / h
    if ratio_src > ratio_dst:
        cut = int(base.height * ratio_dst)
        base = base.crop(((base.width - cut) // 2, 0,
                          (base.width + cut) // 2, base.height))
    else:
        cut = int(base.width / ratio_dst)
        base = base.crop((0, (base.height - cut) // 2,
                          base.width, (base.height + cut) // 2))
    base = base.resize((w, h), Image.LANCZOS)
    base = ImageEnhance.Color(base).enhance(1.45)
    base = ImageEnhance.Contrast(base).enhance(1.12)
    base = ImageEnhance.Brightness(base).enhance(1.06)
    layer = base.convert("RGBA")

    # Затемнение снизу под текст: иначе белые буквы на светлых горах пропадают.
    shade = Image.new("L", (w, h), 0)
    sd = ImageDraw.Draw(shade)
    for step in range(h):
        sd.line((0, step, w, step), fill=int(150 * max(0.0, (step / h - 0.18)) ** 1.2))
    layer.paste(Image.new("RGB", (w, h), (0x08, 0x0C, 0x18)), (0, 0), shade)

    draw = ImageDraw.Draw(layer)
    pad = int(w * 0.055)
    top = int(h * (0.07 if ratio_dst > 1 else 0.10))
    if logo is not None and logo.is_file():
        try:
            mark = int(unit * 62)
            icon = Image.open(logo).convert("RGBA").resize((mark, mark), Image.LANCZOS)
            layer.alpha_composite(icon, (pad, top))
            _spaced(draw, (pad + mark + int(unit * 18), top + int(mark * 0.28)),
                    "LUMEN DRIFT", _font(BOLD, int(unit * 30)), WHITE,
                    tracking=int(unit * 3))
        except Exception as exc:  # noqa: BLE001 — обложка нужна и без знака
            log.warning("Знак на обложку не лёг: %s", exc)

    # Заголовок в две строки, если он длинный: одна строка мельчает до нечитаемого.
    words = title.upper().split()
    lines = [title.upper()]
    if len(words) > 1:
        half = (len(words) + 1) // 2
        lines = [" ".join(words[:half]), " ".join(words[half:])]
    box = w - pad * 2
    font = _fit(draw, max(lines, key=len), BOLD, box, int(unit * 150))
    y = int(h * (0.30 if ratio_dst > 1 else 0.40))
    for line in lines:
        draw.text((pad, y), line, font=font, fill=WHITE,
                  stroke_width=max(2, int(unit * 3)), stroke_fill=(0, 0, 0, 90))
        y += int(font.size * 1.02)

    fill, ink = BADGE_COLORS[accent % len(BADGE_COLORS)]
    row_y = y + int(unit * 26)
    if badge.strip():
        small = _font(BOLD, int(unit * 29))
        text = badge.strip().upper()
        width = int(draw.textlength(text, font=small) + len(text) * 2.2 + unit * 40)
        height = int(unit * 54)
        draw.rounded_rectangle((pad, row_y, pad + width, row_y + height),
                               radius=height // 2, fill=fill + (255,))
        _spaced(draw, (pad + int(unit * 20), row_y + int(height * 0.22)), text,
                small, ink, tracking=2.2)
        note_x = pad + width + int(unit * 26)
    else:
        note_x = pad
    if note.strip():
        _spaced(draw, (note_x, row_y + int(unit * 15)), note.strip().upper(),
                _font(BOLD, int(unit * 23)), WHITE, tracking=int(unit * 4))

    layer.convert("RGB").save(dst, quality=92)
    return dst
