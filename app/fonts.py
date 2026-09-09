"""Библиотека шрифтов для титров.

Шрифты лежат файлами в DATA_DIR/fonts и скачиваются скриптом deploy/fonts.sh.
Системных DejaVu и Liberation хватает для читаемости, но не для оформления:
формат «бюст» держится на строгом засечном шрифте, и без выбора начертания
все ролики выглядят одинаково.
"""
from __future__ import annotations

import logging
import subprocess
from pathlib import Path
from typing import Optional

from . import config

log = logging.getLogger("cf.fonts")

FONTS_DIR = config.DATA_DIR / "fonts"

# ключ -> (название для интерфейса, файл, короткое описание характера)
CATALOG: dict[str, tuple[str, str, str]] = {
    "playfair":   ("Playfair Display", "PlayfairDisplay.ttf", "засечный строгий — под античность"),
    "ptserif":    ("PT Serif",         "PTSerif.ttf",         "засечный жирный, хорошо читается"),
    "montserrat": ("Montserrat",       "Montserrat.ttf",      "геометричный без засечек"),
    "oswald":     ("Oswald",           "Oswald.ttf",          "узкий плакатный, крупные заголовки"),
    "rubik":      ("Rubik",            "Rubik.ttf",           "округлый без засечек"),
    "unbounded":  ("Unbounded",        "Unbounded.ttf",       "широкий современный"),
}

# Запасной вариант: есть в любой системе, кириллицу знает.
SYSTEM_FALLBACKS = (
    "/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf",
    "/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf",
    "/usr/share/fonts/TTF/DejaVuSans-Bold.ttf",
)


def fallback_font() -> Optional[str]:
    for path in SYSTEM_FALLBACKS:
        if Path(path).exists():
            return path
    return None


def font_path(key: str) -> Optional[str]:
    """Путь к файлу шрифта. Если выбранного нет на диске — системный запасной."""
    entry = CATALOG.get(key or "")
    if entry:
        path = FONTS_DIR / entry[1]
        if path.exists():
            return str(path)
        log.warning("Шрифт «%s» выбран, но файла нет: %s", key, path)
    return fallback_font()


def font_family(key: str) -> str:
    """Название семейства для ASS: libass подбирает шрифт по имени, не по файлу."""
    entry = CATALOG.get(key or "")
    if entry and (FONTS_DIR / entry[1]).exists():
        return entry[0]
    return "DejaVu Sans"


def available() -> list[dict]:
    """Шрифты, реально лежащие на диске, — только их и предлагаем в интерфейсе."""
    out = []
    for key, (title, filename, note) in CATALOG.items():
        if (FONTS_DIR / filename).exists():
            out.append({"key": key, "title": title, "note": note})
    return out


def normalize(key: str) -> str:
    """Проверяем выбор: неизвестный или неустановленный шрифт заменяем первым доступным."""
    have = {f["key"] for f in available()}
    if key in have:
        return key
    return next(iter(have), "")


# ------------------------------------------------------------------ образцы

PREVIEW_TEXT = "Защити своё имя"
PREVIEW_DIR = config.DATA_DIR / "font-previews"


def preview_png(key: str) -> Optional[Path]:
    """Картинка-образец начертания. Рисуется один раз и переиспользуется."""
    entry = CATALOG.get(key or "")
    if entry is None:
        return None
    path = font_path(key)
    if not path:
        return None

    PREVIEW_DIR.mkdir(parents=True, exist_ok=True)
    dest = PREVIEW_DIR / f"{key}.png"
    if dest.exists():
        return dest

    escaped = path.replace(":", r"\:")
    try:
        subprocess.run([
            config.FFMPEG, "-hide_banner", "-loglevel", "error", "-y",
            "-f", "lavfi", "-i", "color=0x14161a:size=520x84",
            "-vf", (f"drawtext=fontfile='{escaped}':text='{PREVIEW_TEXT}':"
                    f"fontcolor=white:fontsize=38:x=(w-tw)/2:y=(h-th)/2"),
            "-frames:v", "1", str(dest),
        ], check=True, capture_output=True, timeout=60)
        return dest
    except Exception as exc:  # noqa: BLE001 — без образца интерфейс всё равно работает
        log.warning("Образец шрифта %s не отрисован: %s", key, exc)
        return None


# ------------------------------------------------------------------- метрики

# Раньше строки в формате «абзац» переносились по количеству символов, а ширина
# кадра делилась на «примерно столько-то пикселей на знак». Кириллица неровная:
# строка из «Ш», «Ж» и «М» шире строки из «і» и «л» в полтора раза, поэтому
# часть строк вылезала за правый край. Ширину надо не угадывать, а считать по
# самому файлу шрифта — advance-ширины лежат в таблице hmtx.

_metrics_cache: dict[str, Optional[tuple[int, dict[int, int], int]]] = {}

# Запасная оценка, когда файла шрифта нет (drawtext рисует встроенным):
# доля кегля на знак, взятая с запасом, чтобы строка скорее не добрала, чем вылезла.
FALLBACK_ADVANCE = 0.60


def _tables(blob: bytes) -> dict[bytes, tuple[int, int]]:
    import struct
    if len(blob) < 12:
        raise ValueError("файл короче заголовка")
    num = struct.unpack(">H", blob[4:6])[0]
    out: dict[bytes, tuple[int, int]] = {}
    for i in range(num):
        rec = 12 + i * 16
        if rec + 16 > len(blob):
            break
        tag = blob[rec:rec + 4]
        offset, length = struct.unpack(">II", blob[rec + 8:rec + 16])
        out[tag] = (offset, length)
    return out


def _parse_cmap(blob: bytes, start: int) -> dict[int, int]:
    """Символ -> номер глифа. Берём юникодную подтаблицу, формат 4 или 12."""
    import struct
    count = struct.unpack(">H", blob[start + 2:start + 4])[0]
    best = None
    for i in range(count):
        rec = start + 4 + i * 8
        plat, enc, off = struct.unpack(">HHI", blob[rec:rec + 8])
        # 3/10 и 3/1 — юникод Windows, 0/x — юникод Apple. Полный набор лучше BMP.
        rank = {(3, 10): 3, (0, 4): 3, (0, 6): 3, (3, 1): 2}.get((plat, enc),
                                                                 1 if plat == 0 else 0)
        if rank and (best is None or rank > best[0]):
            best = (rank, start + off)
    if best is None:
        return {}

    table = best[1]
    fmt = struct.unpack(">H", blob[table:table + 2])[0]
    out: dict[int, int] = {}
    if fmt == 4:
        seg2 = struct.unpack(">H", blob[table + 6:table + 8])[0]
        segs = seg2 // 2
        ends = table + 14
        starts = ends + seg2 + 2
        deltas = starts + seg2
        ranges = deltas + seg2
        for s in range(segs):
            end = struct.unpack(">H", blob[ends + s * 2:ends + s * 2 + 2])[0]
            begin = struct.unpack(">H", blob[starts + s * 2:starts + s * 2 + 2])[0]
            delta = struct.unpack(">h", blob[deltas + s * 2:deltas + s * 2 + 2])[0]
            range_off = struct.unpack(">H", blob[ranges + s * 2:ranges + s * 2 + 2])[0]
            if begin > end or end == 0xFFFF and begin == 0xFFFF:
                continue
            for code in range(begin, min(end, 0xFFFE) + 1):
                if range_off == 0:
                    glyph = (code + delta) & 0xFFFF
                else:
                    pos = ranges + s * 2 + range_off + (code - begin) * 2
                    if pos + 2 > len(blob):
                        continue
                    glyph = struct.unpack(">H", blob[pos:pos + 2])[0]
                    if glyph:
                        glyph = (glyph + delta) & 0xFFFF
                if glyph:
                    out[code] = glyph
    elif fmt == 12:
        groups = struct.unpack(">I", blob[table + 12:table + 16])[0]
        for g in range(min(groups, 10000)):
            rec = table + 16 + g * 12
            if rec + 12 > len(blob):
                break
            begin, end, glyph = struct.unpack(">III", blob[rec:rec + 12])
            for code in range(begin, min(end, begin + 5000) + 1):
                out[code] = glyph + (code - begin)
    return out


def _metrics(path: str) -> Optional[tuple[int, dict[int, int], int]]:
    """(unitsPerEm, символ -> advance в единицах шрифта, advance по умолчанию)."""
    if path in _metrics_cache:
        return _metrics_cache[path]
    result = None
    try:
        import struct
        blob = Path(path).read_bytes()
        tables = _tables(blob)
        head, hhea, hmtx, cmap = (tables.get(t) for t in (b"head", b"hhea", b"hmtx", b"cmap"))
        if not (head and hhea and hmtx and cmap):
            raise ValueError("в шрифте нет нужных таблиц")
        upem = struct.unpack(">H", blob[head[0] + 18:head[0] + 20])[0] or 1000
        long_metrics = struct.unpack(">H", blob[hhea[0] + 34:hhea[0] + 36])[0] or 1

        advances: list[int] = []
        for i in range(long_metrics):
            pos = hmtx[0] + i * 4
            if pos + 2 > len(blob):
                break
            advances.append(struct.unpack(">H", blob[pos:pos + 2])[0])
        if not advances:
            raise ValueError("пустая таблица hmtx")

        charmap = _parse_cmap(blob, cmap[0])
        if not charmap:
            raise ValueError("не разобрана таблица cmap")
        last = advances[-1]
        widths = {code: (advances[glyph] if glyph < len(advances) else last)
                  for code, glyph in charmap.items()}
        default = widths.get(ord(" "), last)
        result = (upem, widths, default)
    except Exception as exc:  # noqa: BLE001 — без метрик работаем по оценке
        log.warning("Метрики шрифта %s не прочитаны: %s", path, exc)
    _metrics_cache[path] = result
    return result


def text_width(text: str, font_file: Optional[str], size_px: float) -> float:
    """Ширина строки в пикселях при данном кегле."""
    if not text:
        return 0.0
    data = _metrics(font_file) if font_file else None
    if data is None:
        return len(text) * size_px * FALLBACK_ADVANCE
    upem, widths, default = data
    total = sum(widths.get(ord(ch), default) for ch in text)
    return total * size_px / upem


def wrap_to_width(text: str, font_file: Optional[str], size_px: float,
                  max_px: float) -> list[str]:
    """Перенос по словам так, чтобы строка гарантированно влезла в max_px."""
    words = (text or "").split()
    lines: list[str] = []
    line = ""
    for word in words:
        candidate = f"{line} {word}".strip()
        if line and text_width(candidate, font_file, size_px) > max_px:
            lines.append(line)
            line = word
        else:
            line = candidate
        # Слово длиннее строки целиком — режем по символам, иначе оно вылезет.
        while text_width(line, font_file, size_px) > max_px and len(line) > 1:
            cut = len(line) - 1
            while cut > 1 and text_width(line[:cut], font_file, size_px) > max_px:
                cut -= 1
            lines.append(line[:cut])
            line = line[cut:]
    if line:
        lines.append(line)
    return lines
