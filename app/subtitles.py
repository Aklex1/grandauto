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
Style: Main,{font},{size},&H00FFFFFF,&H000000FF,&H00000000,{back},-1,0,0,0,100,100,{spacing},0,{border},{outline},{shadow},{align},{margin_h},{margin_h},{margin_v},1
Style: Title,{font},{title_size},&H00FFFFFF,&H000000FF,&H00000000,{title_back},-1,0,0,0,100,100,0,0,{border},{title_outline},{title_shadow},8,{margin_h},{margin_h},{title_margin},1

[Events]
Format: Layer, Start, End, Style, Name, MarginL, MarginR, MarginV, Effect, Text
"""

# Оформление субтитров. «Шортсы» — как в популярных вертикальных роликах: крупный
# жирный текст без плашки, толстая обводка с тенью, короткие реплики и подсветка
# слова, которое звучит прямо сейчас. «Классический» — прежняя плашка под текстом.
SUBTITLE_STYLES = {
    "shorts": "Шортсы — чистый текст с мягкой тенью, подсветка жёлтым",
    "shorts_green": "Шортсы — чистый текст, подсветка салатовым",
    "shorts_plain": "Шортсы — чистый текст без подсветки слова",
    "shorts_outline": "Шортсы с обводкой — для пёстрого фона",
    "classic": "Классический — полупрозрачная плашка под текстом",
}
ACCENTS = {
    "shorts": "&H0000E6FF&",       # ASS хранит цвет как BGR: это насыщенный жёлтый
    "shorts_green": "&H0080FF80&",
    "shorts_outline": "&H0000E6FF&",
}
# Стили без чёрного контура вокруг букв: читаемость держится на мягкой тени.
NO_OUTLINE = ("shorts", "shorts_green", "shorts_plain")
WHITE = "&H00FFFFFF&"


def normalize_style(style: str) -> str:
    return style if style in SUBTITLE_STYLES else "shorts"


# Средняя ширина символа DejaVu Sans относительно кегля — по ней подбираем размер шрифта.
CHAR_WIDTH_RATIO = 0.55


def _style_params(size: tuple[int, int], vertical: bool, font: str,
                  style: str = "shorts") -> tuple[dict, int]:
    """Параметры оформления. Кегль считается от ШИРИНЫ кадра и числа символов в строке,
    иначе в вертикальном формате текст вылезает за края."""
    w, h = size
    shorts = style != "classic"

    if shorts:
        # Короткие реплики и крупный кегль — так подписи читаются с телефона
        # и не закрывают половину кадра.
        chars_per_line = 16 if vertical else 30
        max_share = 0.075 if vertical else 0.085
    else:
        chars_per_line = 26 if vertical else 42
        max_share = 0.06

    margin_h = int(w * 0.06)
    usable = w - 2 * margin_h
    font_size = int(usable / (chars_per_line * CHAR_WIDTH_RATIO))
    font_size = max(20, min(font_size, int(h * max_share)))
    title_size = int(font_size * (1.15 if vertical else 1.0))

    if shorts:
        # Без обводки буквы читаются за счёт тени: она даёт отрыв от фона, но не
        # обводит текст чёрным контуром.
        clean = style in NO_OUTLINE
        params = {
            "border": 1,                                  # обводка/тень, без плашки
            "outline": 0 if clean else max(4, int(font_size * 0.14)),
            "shadow": max(3, int(font_size * (0.10 if clean else 0.06))),
            "back": "&H70000000" if clean else "&H90000000",   # цвет тени
            "spacing": 0,
            # подписи стоят в нижней трети, а не у самого края кадра
            "margin_v": int(h * (0.28 if vertical else 0.12)),
            "title_outline": 0 if clean else max(5, int(title_size * 0.16)),
            "title_shadow": max(3, int(title_size * (0.11 if clean else 0.07))),
            "title_back": "&H70000000" if clean else "&H90000000",
            "title_margin": int(h * (0.06 if vertical else 0.05)),
        }
    else:
        params = {
            "border": 3,                                  # BorderStyle 3 — плашка
            "outline": max(6, int(font_size * 0.28)),
            "shadow": 0,
            "back": "&HA0000000",
            "spacing": 0,
            "margin_v": int(h * (0.20 if vertical else 0.09)),
            "title_outline": max(8, int(title_size * 0.32)),
            "title_shadow": 0,
            "title_back": "&HB4000000",
            "title_margin": int(h * (0.08 if vertical else 0.05)),
        }

    params.update({"w": w, "h": h, "font": font, "size": font_size,
                   "margin_h": margin_h, "title_size": title_size, "align": 2})
    return params, chars_per_line


def word_windows(cue: Cue) -> list[tuple[float, float, int]]:
    """Раздаём время реплики по словам пропорционально их длине.

    Точных таймингов слов у нас нет — распознавание даёт границы реплик, а слова
    подставляются из сценария. Пропорция по длине попадает достаточно близко, чтобы
    подсветка шла в такт речи, и не требует второго прохода Whisper.
    """
    words = (cue.text or "").split()
    if not words:
        return []
    span = max(0.15, cue.end - cue.start)
    weights = [len(word) + 2 for word in words]
    total = sum(weights) or 1
    out: list[tuple[float, float, int]] = []
    cursor = cue.start
    for i, weight in enumerate(weights):
        end = cue.end if i == len(words) - 1 else cursor + span * weight / total
        out.append((cursor, max(end, cursor + 0.05), i))
        cursor = end
    return out


def _wrap_tokens(plain: list[str], decorated: list[str], width: int,
                 max_lines: int = 2) -> str:
    """Перенос по словам с учётом ТОЛЬКО видимой длины — теги оформления не считаем."""
    lines: list[str] = []
    cur_plain, cur_dec = "", []
    for word, token in zip(plain, decorated):
        if cur_plain and len(cur_plain) + 1 + len(word) > width:
            lines.append(" ".join(cur_dec))
            cur_plain, cur_dec = word, [token]
        else:
            cur_plain = f"{cur_plain} {word}".strip()
            cur_dec.append(token)
    if cur_dec:
        lines.append(" ".join(cur_dec))
    return "\\N".join(lines[:max_lines])


def write_ass(cues: list[Cue], dst: Path, *, size: tuple[int, int] = (1280, 720),
              vertical: bool = False, font: str = "DejaVu Sans",
              title: str = "", title_seconds: float = 0.0,
              style: str = "shorts", position: str = "bottom") -> Path:
    """ASS-субтитры для вшивания.

    В стиле «шортсы» каждое слово получает свой кадр показа: реплика висит целиком,
    а звучащее слово подсвечивается акцентным цветом — так подписи читаются в такт
    речи, как в популярных вертикальных роликах. В классическом стиле реплика
    показывается целиком на полупрозрачной плашке.
    """
    style = normalize_style(style)
    params, chars_per_line = _style_params(size, vertical, font, style)
    # Alignment 8 — верх по центру: в формате «бюст» титры идут по верхней трети,
    # где под ними лежит ровная заливка.
    if position == "top":
        params["align"] = 8
        # Заголовок тоже прижат к верху, поэтому субтитры опускаем ниже — иначе
        # первая реплика наезжает на него. 0.17 хватало только на однострочный
        # заголовок; двухстрочный доходит до 0.16h и почти касался субтитров.
        params["margin_v"] = int(size[1] * 0.21)
    accent = ACCENTS.get(style, "")
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
        words = (cue.text or "").split()
        if not words:
            continue

        if not accent:
            text = wrap_lines(cue.text, chars_per_line, max_lines=2)
            if text:
                lines.append(f"Dialogue: 0,{_fmt_ass_ts(cue.start)},"
                             f"{_fmt_ass_ts(cue.end)},Main,,0,0,0,,{text}")
            continue

        for start, end, active in word_windows(cue):
            decorated = [
                f"{{\\c{accent}}}{word}{{\\c{WHITE}}}" if i == active else word
                for i, word in enumerate(words)
            ]
            text = _wrap_tokens(words, decorated, chars_per_line, max_lines=2)
            if not text:
                continue
            # мягкое появление только на первом слове реплики, иначе текст мигал бы
            prefix = "{\\fad(90,0)}" if active == 0 else ""
            lines.append(f"Dialogue: 0,{_fmt_ass_ts(start)},{_fmt_ass_ts(end)},"
                         f"Main,,0,0,0,,{prefix}{text}")

    dst.write_text("\n".join(lines) + "\n", encoding="utf-8")
    return dst


def spoken_words(cues: list[Cue]) -> int:
    """Сколько слов распознавание реально услышало в озвучке."""
    return sum(len(c.text.split()) for c in cues)


def speech_coverage(cues: list[Cue], text: str) -> float:
    """Какая доля текста сцены прозвучала. 1.0 — озвучено всё.

    Провайдер может оборвать длинный текст, не оборвав при этом сам файл: он
    аккуратно затухает, и по хвосту обрыв не поймать. Зато видно по словам:
    распознавание слышит заметно меньше, чем написано в сценарии.
    """
    total = len((text or "").split())
    if not total:
        return 1.0
    return min(1.0, spoken_words(cues) / total)


def trim_to_spoken(text: str, cues: list[Cue], slack: float = 1.12) -> str:
    """Обрезаем текст до того, что реально прозвучало.

    Иначе align_script утрамбует весь сценарий в те секунды, где речь есть, и
    титры поедут вперёд голоса — на длинной сцене это уход на десяток секунд.
    """
    words = (text or "").split()
    heard = spoken_words(cues)
    if not words or not heard:
        return text
    keep = int(heard * slack)
    if keep >= len(words):
        return text
    return " ".join(words[:keep])


def align_script(cues: list[Cue], scenes: list[tuple[str, float]], max_chars: int = 90) -> list[Cue]:
    """Текст берём из сценария, тайминг — из распознавания.

    Whisper иногда искажает слова, а точный текст у нас уже есть. Поэтому реплики
    распознавания используются только как временной скелет.

    Каждый сегмент речи разбирается ОТДЕЛЬНО: сколько символов распознавание
    услышало в этом окне — столько же текста сценария в него и кладётся. Раньше
    доля считалась одной пропорцией на всю сцену, и погрешность не сбрасывалась
    на границах сегментов, а копилась: к концу субтитры заметно обгоняли голос.
    """
    out: list[Cue] = []
    offset = 0.0
    for narration, duration in scenes:
        window_start, window_end = offset, offset + duration
        offset = window_end
        words = (narration or "").split()
        if not words:
            continue

        speech = [c for c in cues
                  if window_start <= (c.start + c.end) / 2 < window_end and c.end > c.start]
        if not speech:
            # ASR ничего не нашёл в этом окне — раскладываем равномерно
            for chunk in split_text(narration, max_chars):
                share = duration * (len(chunk) / (len(narration) or 1))
                out.append(Cue(start=window_start, end=window_start + share, text=chunk))
                window_start += share
            continue

        speech.sort(key=lambda c: c.start)
        weights = [max(len(c.text.strip()), 1) for c in speech]
        total_weight = sum(weights)

        # Раздаём слова сценария по сегментам пропорционально тому, сколько текста
        # распознавание услышало в каждом. Границы сегментов — якоря: сдвиг в одном
        # не переносится на следующий.
        counts: list[int] = []
        assigned = 0
        for i, weight in enumerate(weights):
            if i == len(weights) - 1:
                counts.append(len(words) - assigned)
            else:
                share = round(len(words) * weight / total_weight)
                share = max(0, min(share, len(words) - assigned))
                counts.append(share)
                assigned += share

        cursor = 0
        for segment, count in zip(speech, counts):
            if count <= 0:
                continue
            chunk_words = words[cursor:cursor + count]
            cursor += count
            text = " ".join(chunk_words)
            if not text:
                continue
            seg_start = max(window_start, segment.start)
            seg_end = min(window_end, max(segment.end, seg_start + 0.3))
            # Длинный сегмент режем на читаемые куски ВНУТРИ его же окна —
            # за границы сегмента текст не выходит.
            pieces = split_text(text, max_chars) or [text]
            span = seg_end - seg_start
            chars_total = sum(len(p) for p in pieces) or 1
            piece_start = seg_start
            for piece in pieces:
                piece_span = span * (len(piece) / chars_total)
                out.append(Cue(start=piece_start,
                               end=min(seg_end, piece_start + piece_span),
                               text=piece))
                piece_start += piece_span

        # Хвост, если округление недодало слов последнему сегменту.
        if cursor < len(words):
            tail = " ".join(words[cursor:])
            last_end = min(window_end, speech[-1].end)
            out.append(Cue(start=max(window_start, last_end - 1.0), end=last_end, text=tail))

    return out


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
