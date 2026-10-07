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


def _busy(image, box: tuple[int, int, int, int]) -> float:
    """Насколько кусок кадра занят деталями.

    Лицо, руль и листва дают много перепадов яркости, небо и вода — почти
    ничего. По этому и выбираем, где заголовку не мешать картинке.
    """
    from PIL import ImageFilter

    crop = image.crop(box).convert("L").resize((64, 64))
    edges = crop.filter(ImageFilter.FIND_EDGES)
    data = list(edges.getdata())
    return sum(data) / len(data)


def _text_column(image, w: int, h: int, pad: int, box: int) -> int:
    """Где начать колонку с текстом: слева, по центру или справа.

    Жёстко прибитая к левому краю колонка однажды легла героине прямо на лицо —
    в том кадре она сидела за рулём слева. Поэтому колонку примеряем в трёх
    местах и берём ту, где картинка спокойнее.
    """
    top, bottom = int(h * 0.24), int(h * 0.96)
    spots = [pad, max(pad, (w - box) // 2), max(pad, w - pad - box)]
    best, best_cost = spots[0], None
    for x in spots:
        cost = _busy(image, (x, top, min(w, x + box), bottom))
        if best_cost is None or cost < best_cost - 0.5:
            best, best_cost = x, cost
    return best


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
    # Цвет поднимаем умеренно: сцена теперь снимок, а не рисунок, и прежняя
    # прибавка в полтора раза делала из солнца и моря кислотную открытку.
    base = ImageEnhance.Color(base).enhance(1.18)
    base = ImageEnhance.Contrast(base).enhance(1.08)
    layer = base.convert("RGBA")

    # Затемнение снизу под текст: иначе белые буквы на светлом песке пропадают.
    shade = Image.new("L", (w, h), 0)
    sd = ImageDraw.Draw(shade)
    for step in range(h):
        sd.line((0, step, w, step), fill=int(150 * max(0.0, (step / h - 0.18)) ** 1.2))
    layer.paste(Image.new("RGB", (w, h), (0x08, 0x0C, 0x18)), (0, 0), shade)

    # Где встанет текст, решаем по самой картинке: колонка идёт туда, где кадр
    # спокойнее. Ширина колонки та же, что у заголовка ниже.
    pad = int(w * 0.055)
    box = int((w - pad * 2) * (0.64 if ratio_dst > 1 else 1.0))
    text_x = _text_column(base, w, h, pad, box) if ratio_dst > 1 else pad

    # Затемнение под колонкой: на солнечной сцене одного нижнего мало —
    # заголовок ложится на блики воды и теряется.
    column = Image.new("L", (w, h), 0)
    cd = ImageDraw.Draw(column)
    if ratio_dst > 1:
        edge = int(w * 0.68)
        from_right = text_x > (w - text_x - box)
        for step in range(edge):
            k = int(135 * (1 - step / edge) ** 1.3)
            x = w - 1 - step if from_right else step
            cd.line((x, 0, x, h), fill=k)
    else:
        edge = int(h * 0.42)
        for step in range(edge):
            y = h - 1 - step
            cd.line((0, y, w, y), fill=int(120 * (1 - step / edge) ** 1.3))
    layer.paste(Image.new("RGB", (w, h), (0x06, 0x0A, 0x14)), (0, 0), column)

    # И полоска сверху под знак канала: на ярком небе белые буквы пропадали.
    cap = Image.new("L", (w, h), 0)
    capd = ImageDraw.Draw(cap)
    top_edge = int(h * (0.22 if ratio_dst > 1 else 0.14))
    for step in range(top_edge):
        capd.line((0, step, w, step), fill=int(95 * (1 - step / top_edge) ** 1.4))
    layer.paste(Image.new("RGB", (w, h), (0x06, 0x0A, 0x14)), (0, 0), cap)

    draw = ImageDraw.Draw(layer)
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
    # Ширина колонки посчитана выше вместе с её местом: на горизонтальной
    # обложке заголовок во всю ширину налезал бы на героя.
    font = _fit(draw, max(lines, key=len), BOLD, box, int(unit * 150))
    # Блок считаем целиком и ставим от низа: на вертикальной обложке заголовок,
    # прибитый к доле высоты, оставлял под собой пустую треть кадра.
    block = int(len(lines) * font.size * 1.02) + int(unit * 80)
    y = int(h * 0.30) if ratio_dst > 1 else max(int(h * 0.42), int(h * 0.80) - block)
    for line in lines:
        draw.text((text_x, y), line, font=font, fill=WHITE,
                  stroke_width=max(2, int(unit * 3)), stroke_fill=(0, 0, 0, 90))
        y += int(font.size * 1.02)

    fill, ink = BADGE_COLORS[accent % len(BADGE_COLORS)]
    row_y = y + int(unit * 26)
    if badge.strip():
        small = _font(BOLD, int(unit * 29))
        text = badge.strip().upper()
        width = int(draw.textlength(text, font=small) + len(text) * 2.2 + unit * 40)
        height = int(unit * 54)
        draw.rounded_rectangle((text_x, row_y, text_x + width, row_y + height),
                               radius=height // 2, fill=fill + (255,))
        _spaced(draw, (text_x + int(unit * 20), row_y + int(height * 0.22)), text,
                small, ink, tracking=2.2)
        note_x = text_x + width + int(unit * 26)
    else:
        note_x = text_x
    if note.strip():
        # Подпись держим в той же колонке, что и заголовок: уехав вправо, она
        # ложится на героя картинки.
        room = max(int(unit * 120), text_x + box - note_x)
        _spaced(draw, (note_x, row_y + int(unit * 15)), note.strip().upper(),
                _fit(draw, note.strip().upper(), BOLD, room, int(unit * 23)),
                WHITE, tracking=int(unit * 4))

    layer.convert("RGB").save(dst, quality=92)
    return dst
