"""Своя библиотека видео: загрузка футажей и нарезка из них фрагментов видеоряда."""
from __future__ import annotations

import json
import logging
import random
import shutil
import subprocess
import uuid
from pathlib import Path
from typing import Iterable, Optional

import httpx
from sqlalchemy import select
from sqlalchemy.orm import Session

from . import config, media, storage
from .models import Footage

log = logging.getLogger("cf.footage")

LIBRARY_DIRNAME = "_library"
ALLOWED_SUFFIXES = {".mp4", ".mov", ".mkv", ".webm", ".m4v", ".avi"}
MIN_USABLE_SEC = 2.0


class FootageError(RuntimeError):
    """Ошибка загрузки или нарезки футажа."""


def library_dir(channel_slug: Optional[str]) -> Path:
    path = config.MEDIA_DIR / LIBRARY_DIRNAME / (channel_slug or "_global")
    path.mkdir(parents=True, exist_ok=True)
    return path


def _safe_name(name: str) -> str:
    stem = Path(name or "footage").stem
    suffix = Path(name or "").suffix.lower()
    if suffix not in ALLOWED_SUFFIXES:
        suffix = ".mp4"
    return f"{storage.slugify(stem, 50)}-{uuid.uuid4().hex[:8]}{suffix}"


def probe(path: Path) -> dict:
    """Длительность и размер кадра — нужны, чтобы понимать, откуда можно резать."""
    out = storage.run_ff([
        config.FFPROBE, "-v", "error",
        "-select_streams", "v:0",
        "-show_entries", "stream=width,height",
        "-show_entries", "format=duration",
        "-of", "json", str(path),
    ], timeout=120)
    data = json.loads(out or "{}")
    stream = (data.get("streams") or [{}])[0]
    fmt = data.get("format") or {}
    try:
        duration = float(fmt.get("duration") or 0)
    except (TypeError, ValueError):
        duration = 0.0
    return {
        "duration": duration,
        "width": int(stream.get("width") or 0),
        "height": int(stream.get("height") or 0),
    }


def _register(session: Session, path: Path, *, channel_id: Optional[int], channel_slug: Optional[str],
              title: str, tags: str, source_url: str = "") -> Footage:
    info = probe(path)
    if info["duration"] < MIN_USABLE_SEC:
        path.unlink(missing_ok=True)
        raise FootageError(f"Видео короче {MIN_USABLE_SEC:.0f} секунд — резать нечего")
    if not info["width"]:
        path.unlink(missing_ok=True)
        raise FootageError("В файле нет видеодорожки")

    item = Footage(
        channel_id=channel_id,
        title=title.strip() or path.stem,
        tags=tags.strip(),
        path=storage.rel(path),
        source_url=source_url,
        duration_sec=info["duration"],
        width=info["width"],
        height=info["height"],
        file_size=path.stat().st_size,
    )
    session.add(item)
    session.commit()
    log.info("Футаж добавлен: %s (%.1f с, %sx%s)", item.title, item.duration_sec,
             item.width, item.height)
    return item


def add_from_upload(session: Session, fileobj, filename: str, *, channel_id: Optional[int],
                    channel_slug: Optional[str], title: str = "", tags: str = "") -> Footage:
    """Сохраняем загруженный через панель файл в библиотеку."""
    suffix = Path(filename or "").suffix.lower()
    if suffix and suffix not in ALLOWED_SUFFIXES:
        raise FootageError(f"Формат {suffix} не поддерживается. "
                           f"Допустимы: {', '.join(sorted(ALLOWED_SUFFIXES))}")
    dest = library_dir(channel_slug) / _safe_name(filename)
    with open(dest, "wb") as out:
        shutil.copyfileobj(fileobj, out, length=1 << 20)
    if dest.stat().st_size == 0:
        dest.unlink(missing_ok=True)
        raise FootageError("Пустой файл")
    try:
        return _register(session, dest, channel_id=channel_id, channel_slug=channel_slug,
                         title=title or Path(filename).stem, tags=tags)
    except Exception:
        dest.unlink(missing_ok=True)
        raise


def add_from_url(session: Session, url: str, *, channel_id: Optional[int],
                 channel_slug: Optional[str], title: str = "", tags: str = "") -> Footage:
    """Скачиваем футаж по прямой ссылке на видеофайл."""
    url = (url or "").strip()
    if not url.startswith(("http://", "https://")):
        raise FootageError("Нужна прямая ссылка на видеофайл (http/https)")
    name = Path(url.split("?", 1)[0]).name or "footage.mp4"
    dest = library_dir(channel_slug) / _safe_name(name)
    try:
        with httpx.Client(timeout=600, follow_redirects=True) as client:
            with client.stream("GET", url) as resp:
                resp.raise_for_status()
                with open(dest, "wb") as out:
                    for chunk in resp.iter_bytes(chunk_size=1 << 20):
                        out.write(chunk)
    except httpx.HTTPError as exc:
        dest.unlink(missing_ok=True)
        raise FootageError(f"Не удалось скачать: {exc}") from exc
    try:
        return _register(session, dest, channel_id=channel_id, channel_slug=channel_slug,
                         title=title or Path(name).stem, tags=tags, source_url=url)
    except Exception:
        dest.unlink(missing_ok=True)
        raise


def available(session: Session, channel_id: int) -> list[Footage]:
    """Футажи канала плюс общие, у которых файл реально на месте."""
    rows = session.execute(
        select(Footage).where(
            Footage.is_active.is_(True),
            (Footage.channel_id == channel_id) | (Footage.channel_id.is_(None)),
        ).order_by(Footage.id)
    ).scalars().all()
    return [r for r in rows if r.path and storage.abspath(r.path).exists()]


def _keywords(text: str) -> set[str]:
    return {w for w in storage.slugify(text or "", 200).split("-") if len(w) > 3}


def rank_for_scene(items: list[Footage], hint: str) -> list[Footage]:
    """Сначала футажи, чьи теги пересекаются с описанием сцены, затем наименее использованные."""
    wanted = _keywords(hint)

    def score(item: Footage) -> tuple[int, int]:
        overlap = len(wanted & _keywords(f"{item.tags} {item.title}")) if wanted else 0
        return (-overlap, item.used_count)

    return sorted(items, key=score)


class SegmentPicker:
    """Выдаёт непересекающиеся фрагменты, чтобы в одном ролике не было повторов."""

    def __init__(self, items: list[Footage], seed: Optional[int] = None):
        self.items = list(items)
        self.taken: dict[int, list[tuple[float, float]]] = {}
        self.random = random.Random(seed)

    def _free_start(self, item: Footage, length: float) -> Optional[float]:
        usable = item.duration_sec - length
        if usable <= 0:
            return 0.0 if item.duration_sec >= MIN_USABLE_SEC else None
        used = self.taken.get(item.id, [])
        for _ in range(24):
            start = self.random.uniform(0, usable)
            if all(start + length <= s or start >= e for s, e in used):
                return start
        return None

    def pick(self, length: float, hint: str = "") -> Optional[tuple[Footage, float, float]]:
        for item in rank_for_scene(self.items, hint):
            start = self._free_start(item, length)
            if start is None:
                continue
            end = min(item.duration_sec, start + length)
            if end - start < MIN_USABLE_SEC:
                continue
            self.taken.setdefault(item.id, []).append((start, end))
            return item, start, end
        return None


def cut_segment(source: Path, dest: Path, start: float, length: float,
                size: tuple[int, int]) -> Path:
    """Режем фрагмент и приводим его к формату ролика."""
    w, h = size
    dest.parent.mkdir(parents=True, exist_ok=True)
    args = [
        config.FFMPEG, "-hide_banner", "-loglevel", "error", "-y",
        "-ss", f"{start:.3f}", "-i", str(source), "-t", f"{length:.3f}",
        "-vf", (f"scale={w}:{h}:force_original_aspect_ratio=increase,"
                f"crop={w}:{h},fps={media.FPS},format=yuv420p"),
        "-an", "-c:v", "libx264", "-preset", "veryfast", "-crf", "20", str(dest),
    ]
    proc = subprocess.run(args, capture_output=True, text=True, timeout=1800)
    if proc.returncode != 0:
        raise FootageError(f"ffmpeg не смог вырезать фрагмент: {(proc.stderr or '')[-400:]}")
    return dest


def plan_sources(total: int, source_mode: str, library_share: int,
                 library_ready: bool) -> list[str]:
    """Раскладываем слоты видеоряда между генерацией и библиотекой.

    Возвращает список из «generated» / «library» длиной total; чередование делает
    так, чтобы кадры из библиотеки и сгенерированные шли вперемешку, а не блоками.
    """
    if not library_ready or source_mode == "generate":
        return ["generated"] * total
    if source_mode == "library":
        return ["library"] * total

    share = max(0, min(100, library_share))
    from_library = round(total * share / 100)
    from_library = max(0, min(total, from_library))
    plan: list[str] = []
    generated = total - from_library
    # равномерно перемешиваем два потока
    lib_left, gen_left = from_library, generated
    while lib_left or gen_left:
        if lib_left and (not gen_left or lib_left * generated >= gen_left * from_library):
            plan.append("library")
            lib_left -= 1
        else:
            plan.append("generated")
            gen_left -= 1
    return plan


def delete(session: Session, item: Footage) -> None:
    if item.path:
        storage.abspath(item.path).unlink(missing_ok=True)
    session.delete(item)
    session.commit()


def library_size(items: Iterable[Footage]) -> tuple[int, float]:
    total_bytes = 0
    total_sec = 0.0
    for item in items:
        total_bytes += item.file_size or 0
        total_sec += item.duration_sec or 0
    return total_bytes, total_sec
