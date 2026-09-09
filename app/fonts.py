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
