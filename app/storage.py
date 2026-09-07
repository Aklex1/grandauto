"""Работа с файловым хранилищем на NVMe-диске сервера."""
from __future__ import annotations

import mimetypes
import os
import re
import shutil
import subprocess
from pathlib import Path
from typing import Optional

import httpx

from . import config

SAFE = re.compile(r"[^a-zA-Z0-9а-яА-ЯёЁ._-]+")


def slugify(value: str, maxlen: int = 60) -> str:
    translit = {
        "а": "a", "б": "b", "в": "v", "г": "g", "д": "d", "е": "e", "ё": "e", "ж": "zh", "з": "z",
        "и": "i", "й": "y", "к": "k", "л": "l", "м": "m", "н": "n", "о": "o", "п": "p", "р": "r",
        "с": "s", "т": "t", "у": "u", "ф": "f", "х": "h", "ц": "c", "ч": "ch", "ш": "sh", "щ": "sch",
        "ъ": "", "ы": "y", "ь": "", "э": "e", "ю": "yu", "я": "ya",
    }
    low = (value or "").lower()
    out = "".join(translit.get(ch, ch) for ch in low)
    out = re.sub(r"[^a-z0-9]+", "-", out).strip("-")
    return (out or "item")[:maxlen]


def video_dir(channel_slug: str, video_id: int) -> Path:
    path = config.MEDIA_DIR / channel_slug / f"{video_id:06d}"
    path.mkdir(parents=True, exist_ok=True)
    return path


def rel(path: os.PathLike | str) -> str:
    """Путь относительно хранилища — его храним в БД и отдаём в URL."""
    p = Path(path)
    try:
        return str(p.relative_to(config.MEDIA_DIR))
    except ValueError:
        return str(p)


def abspath(relative: str) -> Path:
    return config.MEDIA_DIR / relative


def download(url: str, dest: Path, *, timeout: float = 600.0) -> Path:
    """Скачиваем результат генерации на локальный диск."""
    dest.parent.mkdir(parents=True, exist_ok=True)
    tmp = dest.with_suffix(dest.suffix + ".part")
    with httpx.Client(timeout=timeout, follow_redirects=True) as client:
        with client.stream("GET", url) as resp:
            resp.raise_for_status()
            with open(tmp, "wb") as fh:
                for chunk in resp.iter_bytes(chunk_size=1 << 20):
                    fh.write(chunk)
    tmp.replace(dest)
    return dest


def guess_ext(url: str, default: str) -> str:
    clean = url.split("?", 1)[0]
    ext = Path(clean).suffix
    if ext and len(ext) <= 6:
        return ext
    guessed = mimetypes.guess_extension(mimetypes.guess_type(clean)[0] or "") or default
    return guessed


def dir_size(path: Path) -> int:
    total = 0
    for root, _dirs, files in os.walk(path):
        for name in files:
            try:
                total += os.path.getsize(os.path.join(root, name))
            except OSError:
                pass
    return total


def disk_usage() -> dict:
    usage = shutil.disk_usage(config.DATA_DIR)
    return {
        "total": usage.total,
        "used": usage.used,
        "free": usage.free,
        "media": dir_size(config.MEDIA_DIR),
    }


def human_size(num: Optional[int]) -> str:
    if not num:
        return "0 B"
    step = 1024.0
    for unit in ("B", "KB", "MB", "GB", "TB"):
        if num < step:
            return f"{num:.0f} {unit}" if unit == "B" else f"{num:.1f} {unit}"
        num /= step
    return f"{num:.1f} PB"


def run_ff(args: list[str], *, timeout: float = 3600.0) -> str:
    """Запуск ffmpeg/ffprobe с понятной ошибкой."""
    proc = subprocess.run(args, capture_output=True, text=True, timeout=timeout)
    if proc.returncode != 0:
        tail = (proc.stderr or "")[-1500:]
        raise RuntimeError(f"{args[0]} завершился с кодом {proc.returncode}: {tail}")
    return proc.stdout


def media_duration(path: os.PathLike | str) -> float:
    out = run_ff([
        config.FFPROBE, "-v", "error", "-show_entries", "format=duration",
        "-of", "default=noprint_wrappers=1:nokey=1", str(path),
    ], timeout=120)
    try:
        return float(out.strip())
    except ValueError:
        return 0.0
