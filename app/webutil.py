"""Вспомогательное для веб-слоя: отдача медиа с поддержкой Range (перемотка видео)."""
from __future__ import annotations

import mimetypes
import os
import re
from pathlib import Path

from fastapi import HTTPException, Request
from fastapi.responses import FileResponse, StreamingResponse

RANGE_RE = re.compile(r"bytes=(\d*)-(\d*)")
CHUNK = 1 << 20


def media_response(request: Request, path: Path, *, download_name: str | None = None):
    """Отдаём файл; при заголовке Range — частичный ответ, чтобы работала перемотка."""
    if not path.exists() or not path.is_file():
        raise HTTPException(status_code=404, detail="Файл не найден")

    file_size = path.stat().st_size
    media_type = mimetypes.guess_type(str(path))[0] or "application/octet-stream"
    headers = {"accept-ranges": "bytes"}
    if download_name:
        headers["content-disposition"] = f'attachment; filename*=UTF-8\'\'{download_name}'

    range_header = request.headers.get("range")
    if not range_header:
        return FileResponse(path, media_type=media_type, headers=headers)

    match = RANGE_RE.match(range_header)
    if not match:
        return FileResponse(path, media_type=media_type, headers=headers)

    start_raw, end_raw = match.groups()
    start = int(start_raw) if start_raw else 0
    end = int(end_raw) if end_raw else file_size - 1
    start = max(0, min(start, file_size - 1))
    end = max(start, min(end, file_size - 1))
    length = end - start + 1

    def iterator():
        with open(path, "rb") as fh:
            fh.seek(start)
            remaining = length
            while remaining > 0:
                chunk = fh.read(min(CHUNK, remaining))
                if not chunk:
                    break
                remaining -= len(chunk)
                yield chunk

    headers.update({
        "content-range": f"bytes {start}-{end}/{file_size}",
        "content-length": str(length),
    })
    return StreamingResponse(iterator(), status_code=206, media_type=media_type, headers=headers)


def safe_media_path(root: Path, relative: str) -> Path:
    """Защита от выхода за пределы хранилища."""
    candidate = (root / relative).resolve()
    root_resolved = root.resolve()
    if not str(candidate).startswith(str(root_resolved) + os.sep) and candidate != root_resolved:
        raise HTTPException(status_code=403, detail="Недопустимый путь")
    return candidate
