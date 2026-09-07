"""Субтитры: распознавание речи (faster-whisper) и генерация SRT/VTT/ASS."""
from __future__ import annotations

import logging
import re
import threading
from dataclasses import dataclass
from pathlib import Path
from typing import Iterable, Optional

from . import config

log = logging.getLogger("cf.subs")

_model_lock = threading.Lock()
_model = None


@dataclass
class Cue:
    start: float
    end: float
    text: str


def _fmt_ts(seconds: float, sep: str = ",") -> str:
    seconds = max(0.0, seconds)
    hours, rem = divmod(int(seconds), 3600)
    minutes, secs = divmod(rem, 60)
    ms = int(round((seconds - int(seconds)) * 1000))
    if ms == 1000:
        ms, secs = 0, secs + 1
    return f"{hours:02d}:{minutes:02d}:{secs:02d}{sep}{ms:03d}"


def _fmt_ass_ts(seconds: float) -> str:
    seconds = max(0.0, seconds)
    hours, rem = divmod(int(seconds), 3600)
    minutes, secs = divmod(rem, 60)
    cs = int(round((seconds - int(seconds)) * 100))
    if cs == 100:
        cs, secs = 0, secs + 1
    return f"{hours:d}:{minutes:02d}:{secs:02d}.{cs:02d}"


def get_model():
    """Ленивая загрузка модели Whisper (модель кэшируется в памяти процесса)."""
    global _model
    if _model is not None:
        return _model
    with _model_lock:
        if _model is None:
            from faster_whisper import WhisperModel  # импорт тяжёлый — только по требованию

            log.info("Загружаю Whisper %s (%s/%s)", config.WHISPER_MODEL,
                     config.WHISPER_DEVICE, config.WHISPER_COMPUTE)
            _model = WhisperModel(
                config.WHISPER_MODEL,
                device=config.WHISPER_DEVICE,
                compute_type=config.WHISPER_COMPUTE,
                download_root=str(config.DATA_DIR / "models"),
            )
    return _model


def transcribe(audio: Path, language: str = "ru") -> list[Cue]:
    """Распознаём речь и получаем реплики с таймкодами."""
    model = get_model()
    segments, _info = model.transcribe(
        str(audio),
        language=language,
        vad_filter=True,
        vad_parameters={"min_silence_duration_ms": 400},
        beam_size=5,
        word_timestamps=False,
    )
    cues: list[Cue] = []
    for seg in segments:
        text = (seg.text or "").strip()
        if text:
            cues.append(Cue(start=float(seg.start), end=float(seg.end), text=text))
    return refine_cues(cues)


def refine_cues(cues: list[Cue], *, max_chars: int = 90, max_dur: float = 6.5,
                max_gap: float = 0.6) -> list[Cue]:
    """Whisper режет по паузам и рвёт фразы. Склеиваем куски в цельные реплики."""
    if not cues:
        return []
    merged: list[Cue] = []
    for cue in cues:
        text = re.sub(r"\s+", " ", (cue.text or "").strip())
        if not text:
            continue
        if merged:
            prev = merged[-1]
            ends_sentence = prev.text.rstrip().endswith((".", "!", "?", "…", ":"))
            fits = (len(prev.text) + len(text) + 1 <= max_chars
                    and cue.end - prev.start <= max_dur
                    and cue.start - prev.end <= max_gap)
            if fits and not ends_sentence:
                prev.text = f"{prev.text} {text}".strip()
                prev.end = cue.end
                continue
        merged.append(Cue(start=cue.start, end=cue.end, text=text))

    # Слишком длинные реплики режем по словам, распределяя время пропорционально.
    out: list[Cue] = []
    for cue in merged:
        if len(cue.text) <= max_chars:
            out.append(cue)
            continue
        chunks = split_text(cue.text, max_chars)
        total = sum(len(c) for c in chunks) or 1
        cursor = cue.start
        span = cue.end - cue.start
        for chunk in chunks:
            share = span * (len(chunk) / total)
            out.append(Cue(start=cursor, end=cursor + share, text=chunk))
            cursor += share
    return out


def cues_from_scenes(scene_texts: Iterable[tuple[str, float]], max_chars: int = 84) -> list[Cue]:
    """Запасной вариант без ASR: раскидываем текст сцены по её длительности."""
    cues: list[Cue] = []
    offset = 0.0
    for text, duration in scene_texts:
        chunks = split_text(text, max_chars)
        total_chars = sum(len(c) for c in chunks) or 1
        cursor = offset
        for chunk in chunks:
            share = duration * (len(chunk) / total_chars)
            cues.append(Cue(start=cursor, end=cursor + share, text=chunk))
            cursor += share
        offset += duration
    return cues


def split_text(text: str, max_chars: int = 84) -> list[str]:
    """Режем текст на строки субтитров по границам предложений и слов."""
    text = re.sub(r"\s+", " ", (text or "").strip())
    if not text:
        return []
    sentences = re.split(r"(?<=[.!?…])\s+", text)
    out: list[str] = []
    for sentence in sentences:
        if len(sentence) <= max_chars:
            if sentence:
                out.append(sentence)
            continue
        words, line = sentence.split(), ""
        for word in words:
            if len(line) + len(word) + 1 > max_chars and line:
                out.append(line)
                line = word
            else:
                line = f"{line} {word}".strip()
        if line:
            out.append(line)
    return out


def split_cues_to_fit(cues: list[Cue], max_chars: int) -> list[Cue]:
    """Режем слишком длинные реплики, чтобы каждая помещалась в две строки кадра."""
    out: list[Cue] = []
    for cue in cues:
        text = (cue.text or "").strip()
        if not text:
            continue
        if len(text) <= max_chars:
            out.append(cue)
            continue
        chunks = split_text(text, max_chars)
        total = sum(len(c) for c in chunks) or 1
        cursor = cue.start
        span = max(0.1, cue.end - cue.start)
        for chunk in chunks:
            share = span * (len(chunk) / total)
            out.append(Cue(start=cursor, end=cursor + share, text=chunk))
            cursor += share
    return out


def wrap_lines(text: str, width: int = 42, max_lines: int = 2) -> str:
    """Переносим текст по словам и склеиваем ASS-переносом."""
    words = (text or "").split()
    lines, line = [], ""
    for word in words:
        if len(line) + len(word) + 1 > width and line:
            lines.append(line)
            line = word
        else:
            line = f"{line} {word}".strip()
    if line:
        lines.append(line)
    return "\\N".join(lines[:max_lines])


def write_srt(cues: list[Cue], dst: Path) -> Path:
    parts = []
    for i, cue in enumerate(cues, 1):
        parts.append(f"{i}\n{_fmt_ts(cue.start)} --> {_fmt_ts(cue.end)}\n{cue.text}\n")
    dst.write_text("\n".join(parts), encoding="utf-8")
    return dst


def write_vtt(cues: list[Cue], dst: Path) -> Path:
    parts = ["WEBVTT", ""]
    for cue in cues:
        parts.append(f"{_fmt_ts(cue.start, '.')} --> {_fmt_ts(cue.end, '.')}")
        parts.append(cue.text)
        parts.append("")
    dst.write_text("\n".join(parts), encoding="utf-8")
    return dst


ASS_HEADER = """[Script Info]
ScriptType: v4.00+
PlayResX: {w}
PlayResY: {h}
WrapStyle: 0
ScaledBorderAndShadow: yes
YCbCr Matrix: TV.709

[V4+ Styles]
Format: Name, Fontname, Fontsize, PrimaryColour, SecondaryColour, OutlineColour, BackColour, Bold, Italic, Underline, StrikeOut, ScaleX, ScaleY, Spacing, Angle, BorderStyle, Outline, Shadow, Alignment, MarginL, MarginR, MarginV, Encoding
Style: Main,{font},{size},&H00FFFFFF,&H000000FF,&H00000000,&HA0000000,-1,0,0,0,100,100,{spacing},0,3,{outline},0,2,{margin_h},{margin_h},{margin_v},1
Style: Title,{font},{title_size},&H00FFFFFF,&H000000FF,&H00101010,&HB4000000,-1,0,0,0,100,100,0,0,3,{title_outline},0,8,{margin_h},{margin_h},{title_margin},1

[Events]
Format: Layer, Start, End, Style, Name, MarginL, MarginR, MarginV, Effect, Text
"""

# Средняя ширина символа DejaVu Sans относительно кегля — по ней подбираем размер шрифта.
CHAR_WIDTH_RATIO = 0.55


def _style_params(size: tuple[int, int], vertical: bool, font: str) -> tuple[dict, int]:
    """Единые параметры оформления для всех роликов: кегль от ширины кадра, подложка."""
    w, h = size
    chars_per_line = 26 if vertical else 42
    margin_h = int(w * 0.06)
    usable = w - 2 * margin_h
    font_size = int(usable / (chars_per_line * CHAR_WIDTH_RATIO))
    font_size = max(20, min(font_size, int(h * 0.06)))
    title_size = int(font_size * (1.15 if vertical else 1.0))
    params = {
        "w": w, "h": h, "font": font, "size": font_size,
        "outline": max(6, int(font_size * 0.28)),   # BorderStyle 3 — это ширина подложки
        "spacing": 0,
        "margin_h": margin_h,
        "margin_v": int(h * (0.09 if not vertical else 0.20)),
        "title_size": title_size,
        "title_outline": max(8, int(title_size * 0.32)),
        "title_margin": int(h * (0.05 if not vertical else 0.08)),
    }
    return params, chars_per_line


def write_ass(cues: list[Cue], dst: Path, *, size: tuple[int, int] = (1280, 720),
              vertical: bool = False, font: str = "DejaVu Sans",
              title: str = "", title_seconds: float = 0.0) -> Path:
    """ASS-субтитры для вшивания.

    Кегль считается от ШИРИНЫ кадра и числа символов в строке, иначе в вертикальном
    формате текст вылезает за края. Под текстом рисуется полупрозрачная подложка
    (BorderStyle 3), чтобы буквы читались на любом фоне.
    """
    params, chars_per_line = _style_params(size, vertical, font)
    lines = [ASS_HEADER.format(**params)]

    if title:
        end = title_seconds if title_seconds > 0 else 4.0
        head = wrap_lines(title, int(chars_per_line * 0.85), max_lines=3)
        if head:
            lines.append(
                f"Dialogue: 1,{_fmt_ass_ts(0)},{_fmt_ass_ts(end)},Title,,0,0,0,,{head}")

    # Реплика длиннее двух строк не влезает в кадр — режем её на несколько,
    # распределяя время пропорционально длине кусков.
    for cue in split_cues_to_fit(cues, chars_per_line * 2):
        text = wrap_lines(cue.text, chars_per_line, max_lines=2)
        if not text:
            continue
        lines.append(
            f"Dialogue: 0,{_fmt_ass_ts(cue.start)},{_fmt_ass_ts(cue.end)},Main,,0,0,0,,{text}"
        )
    dst.write_text("\n".join(lines) + "\n", encoding="utf-8")
    return dst


def shift_cues(cues: list[Cue], start: float, end: float) -> list[Cue]:
    """Обрезаем и сдвигаем реплики под фрагмент шортса."""
    out: list[Cue] = []
    for cue in cues:
        if cue.end <= start or cue.start >= end:
            continue
        out.append(Cue(start=max(0.0, cue.start - start),
                       end=min(end, cue.end) - start,
                       text=cue.text))
    return out


def transcript_with_timestamps(cues: list[Cue]) -> str:
    """Текст с таймкодами — скармливаем модели для выбора фрагментов под шортсы."""
    return "\n".join(f"[{cue.start:.1f}-{cue.end:.1f}] {cue.text}" for cue in cues)


def build_all(cues: list[Cue], base: Path, size: tuple[int, int]) -> dict[str, Optional[Path]]:
    return {
        "srt": write_srt(cues, base.with_suffix(".srt")),
        "vtt": write_vtt(cues, base.with_suffix(".vtt")),
        "ass": write_ass(cues, base.with_suffix(".ass"), size=size),
    }
