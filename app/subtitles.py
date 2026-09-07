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


def wrap_two_lines(text: str, width: int = 42) -> str:
    words = text.split()
    lines, line = [], ""
    for word in words:
        if len(line) + len(word) + 1 > width and line:
            lines.append(line)
            line = word
        else:
            line = f"{line} {word}".strip()
    if line:
        lines.append(line)
    return "\\N".join(lines[:2]) if len(lines) > 1 else (lines[0] if lines else "")


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
WrapStyle: 2
ScaledBorderAndShadow: yes

[V4+ Styles]
Format: Name, Fontname, Fontsize, PrimaryColour, SecondaryColour, OutlineColour, BackColour, Bold, Italic, Underline, StrikeOut, ScaleX, ScaleY, Spacing, Angle, BorderStyle, Outline, Shadow, Alignment, MarginL, MarginR, MarginV, Encoding
Style: Main,{font},{size},&H00FFFFFF,&H000000FF,&H00101010,&H80000000,-1,0,0,0,100,100,0,0,1,{outline},{shadow},2,{margin_h},{margin_h},{margin_v},1

[Events]
Format: Layer, Start, End, Style, Name, MarginL, MarginR, MarginV, Effect, Text
"""


def write_ass(cues: list[Cue], dst: Path, *, size: tuple[int, int] = (1280, 720),
              vertical: bool = False, font: str = "DejaVu Sans") -> Path:
    """ASS-субтитры для вшивания: крупные, с обводкой, по центру внизу."""
    w, h = size
    font_size = int(h * (0.055 if not vertical else 0.045))
    header = ASS_HEADER.format(
        w=w, h=h, font=font, size=font_size,
        outline=max(2, int(font_size * 0.09)),
        shadow=1,
        margin_h=int(w * 0.07),
        margin_v=int(h * (0.09 if not vertical else 0.22)),
    )
    width = 34 if vertical else 42
    lines = [header]
    for cue in cues:
        text = wrap_two_lines(cue.text, width)
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
