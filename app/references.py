"""Свои референсы канала — образцы стиля для генераций.

Просьба «делай как на этой картинке» генератору сама по себе ничего не говорит:
он видит только текст промпта. Поэтому у референса две стороны. Словесное
описание («что здесь хорошо») подмешивается в промпт и работает всегда. Сам файл
нужен там, где модель принимает картинку входом — например, при оживлении кадра
в зацикленный клип.
"""
from __future__ import annotations

import logging
from pathlib import Path
from typing import Optional

from sqlalchemy import select
from sqlalchemy.orm import Session

from . import config, storage
from .models import Reference

log = logging.getLogger("cf.references")

KINDS: dict[str, str] = {
    "style": "Общий стиль канала — подмешивается во все промпты",
    "cover": "Обложки — как должны выглядеть превью шортсов",
    "background": "Фоны сцен — для форматов из одного кадра",
    "loop": "Исходник для зацикленного клипа",
}

IMAGE_EXT = {".jpg", ".jpeg", ".png", ".webp"}
VIDEO_EXT = {".mp4", ".mov", ".webm", ".mkv"}

# Сколько описаний подмешиваем в один промпт: длинный хвост из образцов начинает
# перебивать саму задачу.
MAX_IN_PROMPT = 3


def library_dir(channel_slug: str) -> Path:
    path = config.MEDIA_DIR / "_references" / (channel_slug or "channel")
    path.mkdir(parents=True, exist_ok=True)
    return path


def media_type(filename: str) -> Optional[str]:
    ext = Path(filename or "").suffix.lower()
    if ext in IMAGE_EXT:
        return "image"
    if ext in VIDEO_EXT:
        return "video"
    return None


def for_channel(session: Session, channel_id: int,
                kind: str = "") -> list[Reference]:
    query = select(Reference).where(Reference.channel_id == channel_id,
                                    Reference.is_active.is_(True))
    if kind:
        query = query.where(Reference.kind == kind)
    rows = session.execute(query.order_by(Reference.id.desc())).scalars()
    return [r for r in rows if r.path and storage.abspath(r.path).exists()]


def style_hint(session: Session, channel_id: int, kind: str = "") -> str:
    """Описания образцов одной строкой — то, что уходит в промпт.

    Берём и профильные образцы, и общие: «стиль канала» касается всего.
    """
    rows = for_channel(session, channel_id, kind)
    if kind:
        rows = rows + [r for r in for_channel(session, channel_id, "style")
                       if r.id not in {x.id for x in rows}]
    # Повторы убираем: одну и ту же заметку часто ставят сразу нескольким файлам
    # одной загрузкой, и в промпт она уходила бы дважды.
    seen: set[str] = set()
    notes: list[str] = []
    for row in rows:
        note = row.note.strip()
        if note and note.lower() not in seen:
            seen.add(note.lower())
            notes.append(note)
    return "; ".join(notes[:MAX_IN_PROMPT])


def add(session: Session, channel_id: int, channel_slug: str, filename: str,
        data: bytes, *, kind: str = "style", title: str = "",
        note: str = "") -> Optional[Reference]:
    """Сохраняем загруженный файл в библиотеку референсов канала."""
    mtype = media_type(filename)
    if mtype is None:
        log.warning("Референс %s пропущен: неизвестный тип файла", filename)
        return None
    if not data:
        log.warning("Референс %s пропущен: пустой файл", filename)
        return None

    dest = library_dir(channel_slug) / f"ref_{channel_id}_{len(data)}_{storage.slugify(Path(filename).stem, 40)}{Path(filename).suffix.lower()}"
    dest.write_bytes(data)

    width = height = 0
    thumb = ""
    try:
        out = storage.run_ff([config.FFPROBE, "-v", "error", "-select_streams", "v:0",
                              "-show_entries", "stream=width,height", "-of", "csv=p=0",
                              str(dest)], timeout=60).strip().split(",")
        width, height = int(out[0]), int(out[1])
    except Exception as exc:  # noqa: BLE001 — размеры не критичны
        log.warning("Размер референса %s не прочитан: %s", dest.name, exc)

    if mtype == "video":
        # Для видео кладём кадр-превью: в карточке иначе нечего показать.
        poster = dest.with_suffix(".jpg")
        try:
            storage.run_ff([config.FFMPEG, "-hide_banner", "-loglevel", "error", "-y",
                            "-ss", "0.5", "-i", str(dest), "-frames:v", "1",
                            "-q:v", "3", str(poster)], timeout=120)
            thumb = storage.rel(poster)
        except Exception as exc:  # noqa: BLE001
            log.warning("Превью референса %s не снято: %s", dest.name, exc)

    row = Reference(
        channel_id=channel_id, kind=kind if kind in KINDS else "style",
        title=(title or Path(filename).stem)[:200], note=note.strip()[:2000],
        path=storage.rel(dest), thumb_path=thumb, media_type=mtype,
        width=width, height=height, file_size=len(data),
    )
    session.add(row)
    session.commit()
    log.info("Референс добавлен: %s (%s, %dx%d)", row.title, row.kind, width, height)
    return row


def drop(session: Session, ref_id: int, *, delete_file: bool = False) -> bool:
    row = session.get(Reference, ref_id)
    if row is None:
        return False
    row.is_active = False
    if delete_file and row.path:
        storage.abspath(row.path).unlink(missing_ok=True)
        if row.thumb_path:
            storage.abspath(row.thumb_path).unlink(missing_ok=True)
    session.commit()
    return True
