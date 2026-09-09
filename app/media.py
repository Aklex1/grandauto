"""Сборка видео через ffmpeg: нормализация клипов, сцены, склейка, субтитры, шортсы."""
from __future__ import annotations

import logging
import math
from pathlib import Path

from . import config, storage

log = logging.getLogger("cf.media")

RESOLUTIONS = {
    "480p": (854, 480),
    "720p": (1280, 720),
    "1080p": (1920, 1080),
}
VERTICAL = (1080, 1920)
FPS = 30


def target_size(resolution: str, aspect: str = "16:9") -> tuple[int, int]:
    w, h = RESOLUTIONS.get(resolution, RESOLUTIONS["720p"])
    if aspect == "9:16":
        return h, w
    if aspect == "1:1":
        return h, h
    return w, h


def _ff(args: list[str], timeout: float = 3600.0) -> None:
    storage.run_ff([config.FFMPEG, "-hide_banner", "-loglevel", "error", "-y", *args], timeout=timeout)


def normalize_clip(src: Path, dst: Path, size: tuple[int, int]) -> Path:
    w, h = size
    _ff([
        "-i", str(src),
        "-vf", (f"scale={w}:{h}:force_original_aspect_ratio=increase,"
                f"crop={w}:{h},fps={FPS},format=yuv420p"),
        "-an", "-c:v", "libx264", "-preset", "veryfast", "-crf", "20", str(dst),
    ], timeout=1200)
    return dst


def still_to_clip(image: Path, dst: Path, size: tuple[int, int], duration: float,
                  zoom: float = 1.12) -> Path:
    """Медленный наезд на статичный кадр (эффект Кена Бёрнса) — запасной видеоряд."""
    w, h = size
    frames = max(int(duration * FPS), 1)
    _ff([
        "-loop", "1", "-i", str(image), "-t", f"{duration:.2f}",
        "-vf", (f"scale={w * 2}:{h * 2}:force_original_aspect_ratio=increase,crop={w * 2}:{h * 2},"
                f"zoompan=z='min(zoom+{(zoom - 1) / max(frames, 1):.6f},{zoom})':d={frames}:"
                f"x='iw/2-(iw/zoom/2)':y='ih/2-(ih/zoom/2)':s={w}x{h}:fps={FPS},format=yuv420p"),
        "-c:v", "libx264", "-preset", "veryfast", "-crf", "20", "-an", str(dst),
    ], timeout=1200)
    return dst


# Проходы, которыми добираем длительность, если сгенерированных кадров не хватило.
# Реверса здесь намеренно нет: отмотка назад читается зрителем как брак.
VARIANTS = ("mirror", "zoom_in", "pan_right", "zoom_out", "mirror_zoom", "pan_left")
MAX_SEGMENTS = 24


def variant_pass(src: Path, dst: Path, size: tuple[int, int], variant: str,
                 duration: float) -> Path:
    """Ещё один проход по тому же кадру, но визуально другой.

    Нужен, когда озвучка длиннее суммы клипов. Зеркало, наезд, отъезд и панорама
    дают новое движение в кадре — в отличие от реверса, который выглядит как
    перемотка назад и сразу выдаёт машинную сборку.
    """
    w, h = size
    span = max(duration, 0.1)

    # zoompan на видеовходе врёт с длительностью (проверено: 5 с превращались в 6 и
    # даже в 2560), поэтому наезд и отъезд делаем кропом с переменным размером окна —
    # он даёт кадр в кадр ту же длину, что и исходник.
    def _zoom(expr: str) -> str:
        return (f"crop=w='trunc(iw/({expr})/2)*2':h='trunc(ih/({expr})/2)*2':"
                f"x='(iw-ow)/2':y='(ih-oh)/2',scale={w}:{h}")

    zoom_in = _zoom(f"1+0.18*min(t/{span:.3f},1)")
    zoom_out = _zoom(f"1.18-0.18*min(t/{span:.3f},1)")
    over_w, over_h = int(w * 1.18) // 2 * 2, int(h * 1.18) // 2 * 2
    pan_right = (f"scale={over_w}:{over_h},"
                 f"crop={w}:{h}:x='(in_w-out_w)*min(t/{span:.3f},1)':y='(in_h-out_h)/2'")
    pan_left = (f"scale={over_w}:{over_h},"
                f"crop={w}:{h}:x='(in_w-out_w)*(1-min(t/{span:.3f},1))':y='(in_h-out_h)/2'")
    chains = {
        "mirror": "hflip",
        "zoom_in": zoom_in,
        "zoom_out": zoom_out,
        "pan_right": pan_right,
        "pan_left": pan_left,
        "mirror_zoom": f"hflip,{zoom_in}",
    }
    chain = chains.get(variant, "hflip")
    try:
        _ff([
            "-i", str(src),
            "-vf", f"{chain},scale={w}:{h},fps={FPS},format=yuv420p",
            "-an", "-c:v", "libx264", "-preset", "veryfast", "-crf", "20", str(dst),
        ], timeout=1200)
        return dst
    except RuntimeError as exc:
        # zoompan/crop с выражениями капризны на нестандартных входах — не роняем сцену
        log.warning("Вариация «%s» не удалась (%s), беру зеркало", variant, exc)
        _ff([
            "-i", str(src), "-vf", f"hflip,scale={w}:{h},fps={FPS},format=yuv420p",
            "-an", "-c:v", "libx264", "-preset", "veryfast", "-crf", "20", str(dst),
        ], timeout=1200)
        return dst


def build_scene(clips: list[Path], audio: Path, dst: Path, size: tuple[int, int],
                duration: float, workdir: Path) -> Path:
    """Собираем сцену: закрываем длительность озвучки разными кадрами.

    Сначала идут все сгенерированные и нарезанные клипы, каждый ровно один раз.
    Если их суммарной длины не хватает, недостаток добирается ВАРИАЦИЯМИ прохода
    (зеркало, наезд, отъезд, панорама), а не отмоткой назад и не зацикливанием
    одного и того же куска.
    """
    workdir.mkdir(parents=True, exist_ok=True)
    if not clips:
        raise RuntimeError("нет ни одного клипа для сцены")

    prepared: list[Path] = []
    for i, clip in enumerate(clips):
        norm = workdir / f"norm_{i}.mp4"
        normalize_clip(clip, norm, size)
        prepared.append(norm)

    segments = list(prepared)
    covered = sum(storage.media_duration(p) for p in prepared)

    variant_no = 0
    while covered < duration - 0.2 and len(segments) < MAX_SEGMENTS:
        source = prepared[variant_no % len(prepared)]
        variant = VARIANTS[variant_no % len(VARIANTS)]
        seg = workdir / f"var_{variant_no:02d}.mp4"
        variant_pass(source, seg, size, variant, storage.media_duration(source))
        segments.append(seg)
        covered += storage.media_duration(seg)
        variant_no += 1

    if variant_no:
        log.info("Сцена: %s клипов на %.1f с озвучки, добрано %s вариаций прохода",
                 len(prepared), duration, variant_no)

    if len(segments) == 1:
        base = segments[0]
    else:
        listing = workdir / "list.txt"
        listing.write_text("".join(f"file '{p}'\n" for p in segments), encoding="utf-8")
        base = workdir / "base.mp4"
        _ff(["-f", "concat", "-safe", "0", "-i", str(listing), "-c", "copy", str(base)])

    # -stream_loop остаётся страховкой на случай, когда вариаций не хватило
    # (очень длинная озвучка при единственном коротком клипе) — сцена не должна падать.
    loop_args = [] if covered >= duration - 0.2 else ["-stream_loop", "-1"]
    _ff([
        *loop_args, "-i", str(base),
        "-i", str(audio),
        "-map", "0:v:0", "-map", "1:a:0",
        "-t", f"{duration:.3f}",
        "-c:v", "libx264", "-preset", "veryfast", "-crf", "21", "-pix_fmt", "yuv420p",
        "-c:a", "aac", "-b:a", "160k", "-ar", "48000", "-ac", "2",
        "-shortest", "-fflags", "+genpts", str(dst),
    ], timeout=2400)
    return dst


def concat_scenes(scenes: list[Path], dst: Path, workdir: Path) -> Path:
    """Склейка кусков.

    Куски делает этот же конвейер, поэтому кодек и параметры у них одинаковые —
    сначала пробуем склеить копированием потоков: это секунды вместо минут
    перекодирования. Если копирование не проходит (разные параметры у части файлов),
    молча падаем на перекодирование.
    """
    workdir.mkdir(parents=True, exist_ok=True)
    listing = workdir / "scenes.txt"
    listing.write_text("".join(f"file '{p}'\n" for p in scenes), encoding="utf-8")

    try:
        _ff([
            "-f", "concat", "-safe", "0", "-i", str(listing),
            "-c", "copy", "-fflags", "+genpts", "-movflags", "+faststart", str(dst),
        ], timeout=900)
        if dst.exists() and dst.stat().st_size > 0:
            return dst
    except RuntimeError as exc:
        log.info("Склейка копированием не прошла (%s), перекодирую", exc)

    _ff([
        "-f", "concat", "-safe", "0", "-i", str(listing),
        "-c:v", "libx264", "-preset", "veryfast", "-crf", "21", "-pix_fmt", "yuv420p",
        "-c:a", "aac", "-b:a", "160k", "-ar", "48000", "-ac", "2",
        "-movflags", "+faststart", str(dst),
    ], timeout=3600)
    return dst


def mix_background_music(video: Path, music: Path, dst: Path, music_db: float = -24.0) -> Path:
    """Подмешиваем фон под голос.

    amix по умолчанию нормализует входы и приглушает речь, поэтому normalize=0:
    голос сохраняет исходный уровень, а музыка добавляется ровно на заданной громкости.
    Лёгкий sidechain-компрессор дополнительно притапливает фон, когда звучит голос.
    """
    _ff([
        "-i", str(video), "-stream_loop", "-1", "-i", str(music),
        "-filter_complex",
        (f"[1:a]volume={music_db}dB,aformat=sample_fmts=fltp:sample_rates=48000:"
         f"channel_layouts=stereo[bg];"
         f"[0:a]aformat=sample_fmts=fltp:sample_rates=48000:channel_layouts=stereo,asplit[voice][key];"
         f"[bg][key]sidechaincompress=threshold=0.03:ratio=6:attack=15:release=350[duck];"
         f"[voice][duck]amix=inputs=2:duration=first:dropout_transition=0:normalize=0[a]"),
        "-map", "0:v", "-map", "[a]", "-c:v", "copy", "-c:a", "aac", "-b:a", "192k",
        "-ar", "48000", "-ac", "2", "-movflags", "+faststart", str(dst),
    ], timeout=2400)
    return dst


def burn_subtitles(video: Path, ass: Path, dst: Path,
                   fontsdir: Path | None = None) -> Path:
    """Вшиваем субтитры в картинку.

    fontsdir нужен для скачанных шрифтов: libass ищет их по имени через
    fontconfig и файлы вне системных каталогов сам не находит.
    """
    escaped = str(ass).replace("\\", "/").replace(":", r"\:").replace("'", r"\'")
    chain = f"ass='{escaped}'"
    if fontsdir is not None and Path(fontsdir).exists():
        safe = str(fontsdir).replace("\\", "/").replace(":", r"\:").replace("'", r"\'")
        chain += f":fontsdir='{safe}'"
    _ff([
        "-i", str(video), "-vf", chain,
        "-c:v", "libx264", "-preset", "veryfast", "-crf", "21", "-pix_fmt", "yuv420p",
        "-c:a", "copy", "-movflags", "+faststart", str(dst),
    ], timeout=3600)
    return dst


FONT_BOLD_CANDIDATES = (
    "/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf",
    "/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf",
    "/usr/share/fonts/TTF/DejaVuSans-Bold.ttf",
)


def bold_font() -> str | None:
    for path in FONT_BOLD_CANDIDATES:
        if Path(path).exists():
            return path
    return None


def _escape_drawtext(text: str) -> str:
    """Экранируем спецсимволы фильтра drawtext."""
    out = text.replace("\\", "\\\\")
    for ch in (":", "'", "%", "[", "]", ",", ";"):
        out = out.replace(ch, "\\" + ch)
    return out


def wrap_headline(text: str, width: int = 18, max_lines: int = 3) -> list[str]:
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
    return lines[:max_lines]


# Средняя ширина прописной буквы жирного шрифта относительно кегля. Взята с запасом:
# у кириллических прописных DejaVu Sans Bold широкие Ж, Ш, Щ, М доходят до 0.87 em,
# и заниженная оценка выносила заголовок за края кадра.
CAPS_WIDTH_RATIO = 0.80
HEADLINE_CHARS = 14


def make_thumbnail(src: Path, dst: Path, size: tuple[int, int] = (1280, 720),
                   headline: str = "") -> Path:
    """Обложка: кадр нужного размера плюс крупный заголовок по теме ролика.

    Кегль считается от ШИРИНЫ кадра, а не от высоты: в вертикальном формате
    9:16 высота вдвое больше ширины, и размер по высоте выносил текст за края.
    """
    w, h = size
    chain = f"scale={w}:{h}:force_original_aspect_ratio=increase,crop={w}:{h}"

    font = bold_font()
    lines = wrap_headline(headline.strip().upper(), width=HEADLINE_CHARS,
                          max_lines=3) if headline else []
    if lines and font:
        usable = w * 0.90
        font_size = int(usable / (HEADLINE_CHARS * CAPS_WIDTH_RATIO))
        font_size = max(24, min(font_size, int(h * 0.13)))
        line_gap = int(font_size * 1.14)
        block_h = line_gap * len(lines)
        bottom_pad = int(h * 0.07)
        top = h - bottom_pad - block_h

        # Затемняем подложку ровно под текстовым блоком, а не фиксированную треть:
        # в вертикальном кадре треть — это половина экрана.
        box_top = max(0, top - int(font_size * 0.45))
        box_h = min(h - box_top, block_h + int(font_size * 0.9))
        chain += f",drawbox=x=0:y={box_top}:w={w}:h={box_h}:color=black@0.5:t=fill"

        for i, line in enumerate(lines):
            y = top + i * line_gap
            chain += (
                f",drawtext=fontfile='{font}':text='{_escape_drawtext(line)}'"
                f":fontcolor=white:fontsize={font_size}"
                f":borderw={max(3, int(font_size * 0.07))}:bordercolor=black@0.9"
                f":x=(w-text_w)/2:y={y}"      # по центру, иначе длинная строка уходит за край
            )

    _ff(["-i", str(src), "-vf", chain, "-q:v", "2", str(dst)], timeout=300)
    return dst


def frame_grab(video: Path, dst: Path, at: float = 3.0) -> Path:
    _ff(["-ss", f"{at:.2f}", "-i", str(video), "-frames:v", "1", "-q:v", "2", str(dst)], timeout=300)
    return dst


def cut_short(video: Path, dst: Path, start: float, end: float, ass: Path | None = None,
              size: tuple[int, int] = VERTICAL) -> Path:
    """Нарезаем вертикальный шортс: кроп по центру 9:16 + опциональные субтитры."""
    w, h = size
    duration = max(1.0, end - start)
    chain = (f"scale={w}:{h}:force_original_aspect_ratio=increase,crop={w}:{h},fps={FPS}")
    if ass is not None:
        escaped = str(ass).replace("\\", "/").replace(":", r"\:").replace("'", r"\'")
        chain += f",ass='{escaped}'"
    chain += ",format=yuv420p"
    _ff([
        "-ss", f"{start:.3f}", "-i", str(video), "-t", f"{duration:.3f}",
        "-vf", chain,
        "-c:v", "libx264", "-preset", "veryfast", "-crf", "21",
        "-c:a", "aac", "-b:a", "160k", "-ar", "48000", "-ac", "2",
        "-movflags", "+faststart", str(dst),
    ], timeout=1800)
    return dst


# --------------------------------------------------------------- текстовые форматы

def wrap_plain(text: str, width: int) -> list[str]:
    """Перенос по словам без служебных символов — для drawtext."""
    words = (text or "").split()
    lines, line = [], ""
    for word in words:
        if line and len(line) + 1 + len(word) > width:
            lines.append(line)
            line = word
        else:
            line = f"{line} {word}".strip()
    if line:
        lines.append(line)
    return lines


def paragraph_lines(cues, chars_per_line: int) -> list[tuple[str, float]]:
    """Реплики -> физические строки с временем появления.

    Длинная реплика разбивается на несколько строк, и все они проявляются
    одновременно: дробить время внутри фразы незачем, читается она целиком.
    """
    out: list[tuple[str, float]] = []
    for cue in cues:
        for line in wrap_plain(cue.text, chars_per_line):
            out.append((line, cue.start))
    return out


def build_paragraph_scene(background: Path, audio: Path, dst: Path, size: tuple[int, int],
                          duration: float, lines: list[tuple[str, float]], font: str,
                          workdir: Path, signature: str = "",
                          lines_per_block: int = 7, dim: float = 0.55) -> Path:
    """Формат «абзац»: текст проявляется построчно на затемнённом фоне.

    Появившиеся строки остаются до конца блока, затем экран очищается и идёт
    следующий блок. Видео не генерируется вовсе — только один статичный кадр,
    поэтому такой шортс стоит одну картинку вместо десятка клипов.
    """
    w, h = size
    workdir.mkdir(parents=True, exist_ok=True)
    if not lines:
        raise RuntimeError("нет текста для формата «абзац»")

    font_arg = font.replace(":", r"\:") if font else ""
    chars = max(18, int(w / 26))
    size_main = max(20, int(w * 0.040))
    step = int(size_main * 1.62)
    margin = int(w * 0.09)

    # Блоки: строки копятся, пока не наберётся lines_per_block, потом экран чистится.
    blocks: list[list[tuple[str, float]]] = []
    for i in range(0, len(lines), lines_per_block):
        blocks.append(lines[i:i + lines_per_block])

    block_top = int(h * 0.30)
    parts = [f"scale={w}:{h}:force_original_aspect_ratio=increase", f"crop={w}:{h}",
             f"eq=brightness=-{dim:.2f}", f"fps={FPS}"]

    for bi, block in enumerate(blocks):
        # блок держится до появления первой строки следующего блока
        block_end = blocks[bi + 1][0][1] if bi + 1 < len(blocks) else duration + 1
        for li, (text, start) in enumerate(block):
            y = block_top + li * step
            parts.append(
                f"drawtext=fontfile='{font_arg}':text='{_escape_drawtext(text)}'"
                f":fontcolor=white:fontsize={size_main}"
                f":x={margin}:y={y}"
                f":alpha='min(1,(t-{start:.2f})/0.35)'"
                f":enable='between(t,{start:.2f},{block_end:.2f})'"
            )

    if signature:
        parts.append(
            f"drawtext=fontfile='{font_arg}':text='{_escape_drawtext(signature)}'"
            f":fontcolor=white@0.75:fontsize={int(size_main * 0.78)}"
            f":x=(w-text_w)/2:y=h-{int(h * 0.08)}"
        )
    parts.append("format=yuv420p")

    # Фильтров десятки, в командной строке они не помещаются — отдаём файлом.
    graph = workdir / "paragraph.filter"
    graph.write_text("[0:v]" + ",".join(parts) + "[v]", encoding="utf-8")

    _ff([
        "-loop", "1", "-i", str(background), "-i", str(audio),
        "-filter_complex_script", str(graph),
        "-map", "[v]", "-map", "1:a:0", "-t", f"{duration:.3f}",
        "-c:v", "libx264", "-preset", "veryfast", "-crf", "21", "-pix_fmt", "yuv420p",
        "-c:a", "aac", "-b:a", "160k", "-ar", "48000", "-ac", "2",
        "-movflags", "+faststart", str(dst),
    ], timeout=2400)
    return dst


def build_still_scene(background: Path, audio: Path, dst: Path, size: tuple[int, int],
                      duration: float, workdir: Path, zoom: float = 1.22,
                      haze: float = 0.34, band_top: float = 0.0,
                      band_height: float = 0.0, band_color: str = "0x0b0d10") -> Path:
    """Формат «бюст»: оживляем один кадр без генерации видео.

    Медленный наезд плюс дрейфующая дымка, собранная из размытой копии самого
    кадра и наложенная режимом «экран». Выглядит как лёгкое движение пара, при
    этом стоит ноль: генерируется только исходная картинка.
    """
    w, h = size
    workdir.mkdir(parents=True, exist_ok=True)
    span = max(duration, 0.1)
    over_w, over_h = int(w * 1.35) // 2 * 2, int(h * 1.35) // 2 * 2

    graph = (
        # основа: кадр нужного размера с медленным наездом
        f"[0:v]scale={w}:{h}:force_original_aspect_ratio=increase,crop={w}:{h},"
        f"crop=w='trunc(iw/(1+{zoom - 1:.3f}*min(t/{span:.3f},1))/2)*2':"
        f"h='trunc(ih/(1+{zoom - 1:.3f}*min(t/{span:.3f},1))/2)*2':"
        f"x='(iw-ow)/2':y='(ih-oh)/2',scale={w}:{h},fps={FPS}[base];"
        # дымка: сильно размытая и осветлённая копия, шире кадра — есть куда ехать
        f"[1:v]scale={over_w}:{over_h}:force_original_aspect_ratio=increase,"
        f"crop={over_w}:{over_h},boxblur=40:2,eq=brightness=0.10:saturation=0.2,"
        f"crop={w}:{h}:x='(in_w-out_w)*(0.5+0.5*sin(t/3.5))':"
        f"y='(in_h-out_h)*(0.5+0.5*sin(t/5.5))',fps={FPS}[haze];"
        f"[base][haze]blend=all_mode=screen:all_opacity={haze:.2f}[mix]"
    )
    if band_height > 0:
        # Однотонная полоса под титры: на сгенерированном кадре фон непредсказуем,
        # а поверх ровной заливки текст читается всегда.
        y = int(h * band_top)
        bh = int(h * band_height)
        graph += (f";[mix]drawbox=x=0:y={y}:w={w}:h={bh}:"
                  f"color={band_color}@0.92:t=fill,format=yuv420p[v]")
    else:
        graph += ";[mix]format=yuv420p[v]"
    script = workdir / "still.filter"
    script.write_text(graph, encoding="utf-8")

    _ff([
        "-loop", "1", "-i", str(background),
        "-loop", "1", "-i", str(background),
        "-i", str(audio),
        "-filter_complex_script", str(script),
        "-map", "[v]", "-map", "2:a:0", "-t", f"{duration:.3f}",
        "-c:v", "libx264", "-preset", "veryfast", "-crf", "21", "-pix_fmt", "yuv420p",
        "-c:a", "aac", "-b:a", "160k", "-ar", "48000", "-ac", "2",
        "-movflags", "+faststart", str(dst),
    ], timeout=2400)
    return dst


def clips_needed(audio_sec: float, coverage_sec: float, max_clips: int = 8) -> int:
    """Сколько уникальных клипов нужно, чтобы закрыть сцену без явного повтора."""
    coverage = max(4.0, coverage_sec)
    return max(1, min(max(1, max_clips), math.ceil(audio_sec / coverage)))
