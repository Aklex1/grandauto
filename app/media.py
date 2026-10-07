"""Сборка видео через ffmpeg: нормализация клипов, сцены, склейка, субтитры, шортсы."""
from __future__ import annotations

import logging
import math
from pathlib import Path
from typing import Optional

from . import config, fonts, storage

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


# Насколько результат склейки может отличаться от суммы кусков.
CONCAT_TOLERANCE = 0.5
# И насколько видео может разойтись со звуком внутри готового файла.
CONCAT_SYNC_TOLERANCE = 0.35


def _frame_size(path: Path) -> tuple[int, int] | None:
    try:
        out = storage.run_ff([config.FFPROBE, "-v", "error", "-select_streams", "v:0",
                              "-show_entries", "stream=width,height", "-of", "csv=p=0",
                              str(path)], timeout=60).strip().split(",")
        return int(out[0]), int(out[1])
    except Exception:  # noqa: BLE001
        return None


def _stream_duration(path: Path, kind: str) -> float:
    try:
        out = storage.run_ff([config.FFPROBE, "-v", "error",
                              "-select_streams", kind, "-show_entries", "stream=duration",
                              "-of", "csv=p=0", str(path)], timeout=60).strip()
        return float(out.splitlines()[0])
    except Exception:  # noqa: BLE001
        return 0.0


def _concat_sane(dst: Path, expected: float) -> bool:
    """Сошлась ли склейка: и по общей длине, и по расхождению видео со звуком."""
    total = storage.media_duration(dst)
    if expected > 0 and abs(total - expected) > CONCAT_TOLERANCE:
        log.warning("Склейка дала %.2f с вместо %.2f с", total, expected)
        return False
    video, audio = _stream_duration(dst, "v:0"), _stream_duration(dst, "a:0")
    if video > 0 and audio > 0 and abs(video - audio) > CONCAT_SYNC_TOLERANCE:
        log.warning("В склейке видео %.2f с, звук %.2f с — потоки разъехались",
                    video, audio)
        return False
    return True


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

    # Сумма длительностей — то, что должно получиться. Сверяем с результатом:
    # concat с копированием потоков не падает при несовпадении параметров кусков
    # (разные fps или частота дискретизации), а молча выдаёт растянутое видео и
    # разъехавшийся с ним звук. Проверки «файл создан и непустой» тут мало.
    expected = sum(storage.media_duration(p) for p in scenes)
    try:
        _ff([
            "-f", "concat", "-safe", "0", "-i", str(listing),
            "-c", "copy", "-fflags", "+genpts", "-movflags", "+faststart", str(dst),
        ], timeout=900)
        if dst.exists() and dst.stat().st_size > 0 and _concat_sane(dst, expected):
            return dst
        log.info("Склейка копированием разъехалась по длительности, перекодирую")
    except RuntimeError as exc:
        log.info("Склейка копированием не прошла (%s), перекодирую", exc)

    # Куски подаём отдельными входами и приводим каждый к общим параметрам ДО
    # склейки. Через concat-демуксер это не лечится: он уже смешал кадры в
    # timebase первого файла, и никакой fps на выходе исходную длину не вернёт.
    size = _frame_size(scenes[0]) or VERTICAL
    w, h = size
    args: list[str] = []
    for path in scenes:
        args += ["-i", str(path)]
    chain = ""
    for i in range(len(scenes)):
        chain += (f"[{i}:v]fps={FPS},scale={w}:{h}:force_original_aspect_ratio=increase,"
                  f"crop={w}:{h},setsar=1,format=yuv420p[v{i}];"
                  f"[{i}:a]aformat=sample_rates=48000:channel_layouts=stereo,"
                  f"asetpts=N/SR/TB[a{i}];")
    chain += "".join(f"[v{i}][a{i}]" for i in range(len(scenes)))
    chain += f"concat=n={len(scenes)}:v=1:a=1[v][a]"

    _ff(args + [
        "-filter_complex", chain, "-map", "[v]", "-map", "[a]",
        "-c:v", "libx264", "-preset", "veryfast", "-crf", "21", "-pix_fmt", "yuv420p",
        "-c:a", "aac", "-b:a", "160k", "-ar", "48000", "-ac", "2",
        "-movflags", "+faststart", str(dst),
    ], timeout=3600)
    return dst


def mix_background_music(video: Path, music: Path, dst: Path, music_db: float = -24.0,
                         fade_out: float = 0.0) -> Path:
    """Подмешиваем фон под голос.

    amix по умолчанию нормализует входы и приглушает речь, поэтому normalize=0:
    голос сохраняет исходный уровень, а музыка добавляется ровно на заданной громкости.
    Лёгкий sidechain-компрессор дополнительно притапливает фон, когда звучит голос.

    fade_out — длина хвоста после речи. Первую четверть его музыка звучит ровно,
    остальное уходит в тишину. На хвосте голоса уже нет, поэтому гаснет ровно
    музыка: ролик заканчивается спадом, а не обрывом.
    """
    chain = (f"[1:a]volume={music_db}dB,aformat=sample_fmts=fltp:sample_rates=48000:"
             f"channel_layouts=stereo[bg];"
             f"[0:a]aformat=sample_fmts=fltp:sample_rates=48000:channel_layouts=stereo,"
             f"asplit[voice][key];"
             f"[bg][key]sidechaincompress=threshold=0.03:ratio=6:attack=15:release=350[duck];"
             f"[voice][duck]amix=inputs=2:duration=first:dropout_transition=0:normalize=0")
    total = storage.media_duration(video) if fade_out > 0 else 0.0
    if fade_out > 0 and total > fade_out:
        # Гасим не весь хвост: первую четверть музыка держится ровно, и только
        # потом уходит. Если начинать спад сразу после последнего слова, музыка
        # не успевает прозвучать и хвост воспринимается как затянутый обрыв.
        hold = fade_out * 0.25
        span = fade_out - hold
        chain += f",afade=t=out:st={total - span:.3f}:d={span:.3f}"
    chain += "[a]"
    _ff([
        "-i", str(video), "-stream_loop", "-1", "-i", str(music),
        "-filter_complex", chain,
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


HEADLINE_CHARS = 14
# Отступ заголовка обложки от верхнего края, доля высоты кадра.
THUMB_TITLE_TOP = 0.12


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
        # Кегль подбираем по реальным ширинам глифов: раньше он считался по
        # средней доле кегля на знак, и заголовок из широких Ж, Ш, М вылезал.
        # Ширина строки линейна по кеглю, поэтому хватает одного измерения.
        per_unit = max(fonts.text_width(line, font, 100) for line in lines) / 100 or 1.0
        font_size = max(24, min(int(usable / per_unit), int(h * 0.13)))
        line_gap = int(font_size * 1.14)
        block_h = line_gap * len(lines)
        # Заголовок ставим сверху: в ленте у превью обрезается низ, да и палец
        # зрителя на телефоне закрывает именно нижнюю часть обложки. Но не вплотную
        # к краю — иначе текст выглядит приклеенным к рамке.
        top = int(h * THUMB_TITLE_TOP)

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
              size: tuple[int, int] = VERTICAL, tail: float = 0.0) -> Path:
    """Нарезаем вертикальный шортс: кроп по центру 9:16 + опциональные субтитры.

    tail — хвост после конца речи: последний кадр замирает, голос добивается
    тишиной. Музыку на этот хвост кладёт mix_background_music, она же её и
    гасит; без хвоста ролик обрывался ровно на последнем слове.
    """
    w, h = size
    duration = max(1.0, end - start)
    chain = (f"scale={w}:{h}:force_original_aspect_ratio=increase,crop={w}:{h},fps={FPS}")
    if ass is not None:
        escaped = str(ass).replace("\\", "/").replace(":", r"\:").replace("'", r"\'")
        chain += f",ass='{escaped}'"
    if tail > 0:
        # tpad держит последний кадр, apad — тишину той же длины.
        chain += f",tpad=stop_mode=clone:stop_duration={tail:.3f}"
        duration += tail
    chain += ",format=yuv420p"
    args = ["-ss", f"{start:.3f}", "-i", str(video), "-t", f"{duration:.3f}", "-vf", chain]
    if tail > 0:
        args += ["-af", "apad"]
    _ff(args + [
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


def paragraph_font_size(width: int) -> int:
    """Кегль текста в формате «абзац»."""
    return max(18, int(width * 0.034))


def paragraph_margin(width: int) -> int:
    """Боковое поле: за него строка выходить не должна."""
    return int(width * 0.09)


def paragraph_lines(cues, font_file, size_px: float, max_px: float) -> list[tuple[str, float]]:
    """Реплики -> физические строки с временем появления.

    Длинная реплика разбивается на несколько строк, и все они проявляются
    одновременно: дробить время внутри фразы незачем, читается она целиком.

    Перенос считается по реальным ширинам глифов, а не по числу знаков: строка
    из «Ш» и «М» втрое шире строки из «і», и по счёту символов часть строк
    вылезала за правый край кадра.
    """
    out: list[tuple[str, float]] = []
    for cue in cues:
        for line in fonts.wrap_to_width(cue.text, font_file, size_px, max_px):
            out.append((line, cue.start))
    return out


def build_paragraph_scene(background: Path, audio: Path, dst: Path, size: tuple[int, int],
                          duration: float, lines: list[tuple[str, float]], font: str,
                          workdir: Path, signature: str = "",
                          lines_per_block: int = 7, dim: float = 0.55) -> Path:
    """Формат «абзац»: текст проявляется построчно на затемнённом фоне.

    Появившиеся строки остаются до конца блока, затем блок плавно гаснет и идёт
    следующий. Видео не генерируется вовсе — только один статичный кадр, поэтому
    такой шортс стоит одну картинку вместо десятка клипов.

    Строка не просто включается: она всплывает снизу с замедлением и так же
    уходит вверх, когда блок сменяется. Резкое включение и мгновенное исчезание
    выдавали автоматическую сборку.
    """
    w, h = size
    workdir.mkdir(parents=True, exist_ok=True)
    if not lines:
        raise RuntimeError("нет текста для формата «абзац»")

    font_arg = font.replace(":", r"\:") if font else ""
    size_main = paragraph_font_size(w)
    step = int(size_main * 1.62)
    margin = paragraph_margin(w)

    # Плавность появления и ухода: подобрано так, чтобы глаз успевал за строкой,
    # но текст не отставал от голоса.
    fade_in, fade_out = 0.50, 0.55
    slide = int(size_main * 0.55)      # на столько строка всплывает снизу
    rise = int(size_main * 0.45)       # и на столько уходит вверх, когда гаснет

    # Блоки: строки копятся, пока не наберётся lines_per_block, потом экран чистится.
    blocks: list[list[tuple[str, float]]] = []
    for i in range(0, len(lines), lines_per_block):
        blocks.append(lines[i:i + lines_per_block])

    block_top = int(h * 0.30)
    parts = [f"scale={w}:{h}:force_original_aspect_ratio=increase", f"crop={w}:{h}",
             f"eq=brightness=-{dim:.2f}", f"fps={FPS}"]

    for bi, block in enumerate(blocks):
        # блок держится до появления первой строки следующего; последний гаснет
        # на самом конце ролика, а не обрывается вместе с кадром
        block_end = blocks[bi + 1][0][1] if bi + 1 < len(blocks) else duration
        for li, (text, start) in enumerate(block):
            start = min(start, max(0.0, block_end - 0.2))
            y = block_top + li * step
            # На коротком блоке длинные фазы не помещаются — ужимаем их.
            span = max(block_end - start, 0.2)
            fi = max(0.12, min(fade_in, span * 0.35))
            fo = max(0.12, min(fade_out, span * 0.35))
            # Появление с замедлением (ease-out), уход по сглаженной ступеньке.
            ein = f"(1-pow(1-clip((t-{start:.2f})/{fi:.2f},0,1),3))"
            pout = f"clip(({block_end:.2f}-t)/{fo:.2f},0,1)"
            eout = f"({pout}*{pout}*(3-2*{pout}))"
            parts.append(
                f"drawtext=fontfile='{font_arg}':text='{_escape_drawtext(text)}'"
                f":fontcolor=white:fontsize={size_main}"
                f":x={margin}:y='{y}+{slide}*(1-{ein})-{rise}*(1-{eout})'"
                f":alpha='min({ein},{eout})'"
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
        "-af", "apad",
        "-map", "[v]", "-map", "1:a:0", "-t", f"{duration:.3f}",
        "-c:v", "libx264", "-preset", "veryfast", "-crf", "21", "-pix_fmt", "yuv420p",
        "-c:a", "aac", "-b:a", "160k", "-ar", "48000", "-ac", "2",
        "-movflags", "+faststart", str(dst),
    ], timeout=2400)
    return dst


# Пресеты «живого кадра»: одна сгенерированная картинка + процедурное движение
# средствами ffmpeg. Генерация видео не нужна вовсе, поэтому такой шортс стоит
# ровно одну картинку. Формат — это данные, а не отдельная ветка кода.
#
# ВАЖНО про периоды. Они заданы в ДОЛЯХ длительности ролика, а не в секундах:
# период 1.0 — один полный цикл на весь шортс. Абсолютные секунды здесь не
# работают — на восьмисекундном ролике качание с периодом 20 с незаметно, и
# формат выглядит стоп-кадром. Ровно на это жаловались в «бюсте».
#
#   zoom        во сколько раз кадр наезжает за ролик (1.0 — без наезда)
#   base_over   запас базового слоя сверх кадра: без запаса некуда панорамировать
#   pan_x/pan_y амплитуда качания базы, доля запаса (0.5 — весь запас)
#   period_*    период качания базы, в долях длительности ролика
#   blur        размытие второго слоя (из него делается дымка, блики, облака)
#   bright/sat  осветление и насыщенность второго слоя
#   opacity     насколько сильно второй слой подмешан
#   blend       режим наложения второго слоя
#   drift_*     периоды дрейфа второго слоя, в долях длительности ролика
#   shimmer     амплитуда мерцания яркости базы (огонь, блики на воде)
#   rotate      поворот кадра за весь ролик, градусы (по умолчанию 0)
MOTION_PRESETS: dict[str, dict] = {
    # Бюст: медленный наезд и дрейфующий пар — движение держится на наезде.
    "bust":   dict(zoom=1.22, base_over=1.00, pan_x=0.0,  pan_y=0.0,
                   period_x=1.2, period_y=1.6, blur=40, bright=0.10, sat=0.2,
                   opacity=0.34, blend="screen", drift_x=0.55, drift_y=0.85, shimmer=0.0),
    # Море: длинный горизонтальный ход и короткая зыбь по вертикали, сверху блики.
    "sea":    dict(zoom=1.08, base_over=1.18, pan_x=0.45, pan_y=0.10,
                   period_x=1.3, period_y=0.30, blur=28, bright=0.14, sat=0.5,
                   opacity=0.30, blend="screen", drift_x=0.70, drift_y=0.32, shimmer=0.020),
    # Небо: облака ползут, кадр медленно идёт вбок.
    "sky":    dict(zoom=1.10, base_over=1.20, pan_x=0.40, pan_y=0.18,
                   period_x=1.6, period_y=1.1, blur=46, bright=0.16, sat=0.3,
                   opacity=0.36, blend="screen", drift_x=0.90, drift_y=0.55, shimmer=0.0),
    # Полёт: непрерывный ход вперёд плюс лёгкое покачивание, как за птицей.
    "flight": dict(zoom=1.55, base_over=1.14, pan_x=0.22, pan_y=0.14,
                   period_x=0.65, period_y=0.45, blur=34, bright=0.10, sat=0.4,
                   opacity=0.24, blend="screen", drift_x=0.60, drift_y=0.90, shimmer=0.012),
    # Огонь: мерцание яркости и всплывающее свечение — самый «дёрганый» пресет.
    "fire":   dict(zoom=1.14, base_over=1.10, pan_x=0.12, pan_y=0.10,
                   period_x=0.9, period_y=0.6, blur=52, bright=0.20, sat=0.7,
                   opacity=0.40, blend="screen", drift_x=0.28, drift_y=0.22, shimmer=0.045),
    # Дождь по стеклу: размытый слой сползает вниз, кадр почти неподвижен.
    "rain":   dict(zoom=1.06, base_over=1.12, pan_x=0.10, pan_y=0.35,
                   period_x=1.4, period_y=0.55, blur=36, bright=0.06, sat=0.3,
                   opacity=0.28, blend="screen", drift_x=0.95, drift_y=0.26, shimmer=0.018),
    # Звёздное небо: единственный пресет с поворотом — небо вращается вокруг полюса.
    # Запас базового слоя больше обычного, иначе поворот обнажит углы кадра.
    "stars":  dict(zoom=1.05, base_over=1.45, pan_x=0.06, pan_y=0.06,
                   period_x=1.7, period_y=1.3, blur=30, bright=0.12, sat=0.4,
                   opacity=0.26, blend="screen", drift_x=1.1, drift_y=0.8,
                   shimmer=0.022, rotate=5.0),
    # Ночная дорога: непрерывный ход вперёд, как у полёта, но без покачивания вбок.
    "road":   dict(zoom=1.60, base_over=1.10, pan_x=0.08, pan_y=0.06,
                   period_x=1.1, period_y=0.7, blur=38, bright=0.12, sat=0.5,
                   opacity=0.30, blend="screen", drift_x=0.5, drift_y=0.9, shimmer=0.016),
    # Свеча: кадр почти стоит, живёт только пламя — сильное мерцание, малый дрейф.
    "candle": dict(zoom=1.10, base_over=1.06, pan_x=0.05, pan_y=0.05,
                   period_x=1.3, period_y=0.9, blur=48, bright=0.18, sat=0.6,
                   opacity=0.38, blend="screen", drift_x=0.22, drift_y=0.18, shimmer=0.050),
    # Снегопад: слой сползает вниз быстрее дождя, кадр слегка ведёт вбок.
    "snow":   dict(zoom=1.08, base_over=1.16, pan_x=0.22, pan_y=0.30,
                   period_x=1.5, period_y=0.45, blur=32, bright=0.14, sat=0.2,
                   opacity=0.34, blend="screen", drift_x=0.75, drift_y=0.20, shimmer=0.0),
    # Поле на ветру: длинная волна вбок и короткая рябь — как ход травы.
    "field":  dict(zoom=1.12, base_over=1.20, pan_x=0.38, pan_y=0.12,
                   period_x=1.2, period_y=0.28, blur=26, bright=0.10, sat=0.6,
                   opacity=0.26, blend="screen", drift_x=0.55, drift_y=0.30, shimmer=0.014),
    # Глубина океана: медленный подъём и столбы света, качающиеся сверху.
    "deep":   dict(zoom=1.16, base_over=1.22, pan_x=0.14, pan_y=0.34,
                   period_x=1.6, period_y=1.0, blur=44, bright=0.16, sat=0.4,
                   opacity=0.34, blend="screen", drift_x=0.85, drift_y=0.40, shimmer=0.024),
}

# Границы периода в секундах: короче — дёрганье, длиннее — стоп-кадр.
MOTION_PERIOD_MIN = 1.3
MOTION_PERIOD_MAX = 40.0


def build_still_scene(background: Path, audio: Path, dst: Path, size: tuple[int, int],
                      duration: float, workdir: Path, motion: str = "bust",
                      band_top: float = 0.0, band_height: float = 0.0,
                      band_color: str = "0x0b0d10", dim: float = 0.0,
                      dim_start: float = 0.0, dim_span: float = 0.5) -> Path:
    """«Живой кадр»: оживляем одну картинку без генерации видео.

    Базовый слой едет и наезжает, поверх него режимом «экран» ложится сильно
    размытая копия той же картинки с собственным дрейфом — получается движение
    пара, бликов, облаков или огня, смотря какой пресет. Стоит это ноль:
    генерируется только исходная картинка.
    """
    w, h = size
    workdir.mkdir(parents=True, exist_ok=True)
    span = max(duration, 0.1)
    cfg = MOTION_PRESETS.get(motion) or MOTION_PRESETS["bust"]

    def even(value: float) -> int:
        return int(value) // 2 * 2

    base_w, base_h = even(w * cfg["base_over"]), even(h * cfg["base_over"])
    over_w, over_h = even(w * 1.35), even(h * 1.35)
    tau = 6.28318

    # Наезд: к концу ролика кадр увеличен в zoom раз.
    grow = cfg["zoom"] - 1
    zoom_expr = f"(1+{grow:.3f}*min(t/{span:.3f},1))" if grow > 0.001 else "1"

    def period(share: float) -> float:
        """Период из доли ролика — чтобы движение было видно на любой длине."""
        return max(MOTION_PERIOD_MIN, min(MOTION_PERIOD_MAX, span * share))

    # Панорама: доля запаса, который база проходит туда-обратно.
    px = f"(0.5+{cfg['pan_x']:.3f}*sin({tau}*t/{period(cfg['period_x']):.2f}))"
    py = f"(0.5+{cfg['pan_y']:.3f}*sin({tau}*t/{period(cfg['period_y']):.2f}))"

    base = (f"[0:v]scale={base_w}:{base_h}:force_original_aspect_ratio=increase,"
            f"crop={base_w}:{base_h}")
    if abs(cfg.get("rotate", 0.0)) > 0.01:
        # Поворот делаем ДО кропа: он вращает весь кадр, и обнажённые углы должны
        # остаться за границей вырезаемой области, иначе они попадут в шортс.
        turn = math.radians(cfg["rotate"])
        base += f",rotate=a='{turn:.5f}*t/{span:.3f}':c=black@0:ow=iw:oh=ih"
    base += (f",crop=w='trunc(iw/{zoom_expr}/2)*2':h='trunc(ih/{zoom_expr}/2)*2'"
             f":x='(iw-ow)*{px}':y='(ih-oh)*{py}',scale={w}:{h}")
    if dim > 0.001:
        # Готовая обложка сделана как обложка: крупный текст во весь верх. Её
        # показывают как есть ровно тот миг, на который она рассчитана, а потом
        # гасят — иначе титры поверх неё не прочитать. Гасим не скачком, а за
        # dim_span секунд: резкая смена яркости бьёт по глазам.
        ramp = (f"min(max((t-{dim_start:.3f})/{max(dim_span, 0.05):.3f},0),1)")
        base += f",eq=eval=frame:brightness='-{dim:.3f}*{ramp}'"
    if cfg["shimmer"] > 0:
        # Мерцание яркости — для огня и бликов на воде. eval=frame, иначе
        # выражение посчитается один раз и движения не будет.
        base += (f",eq=eval=frame:brightness='{cfg['shimmer']:.3f}"
                 f"*sin({tau}*t/1.7)+{cfg['shimmer'] * 0.6:.3f}*sin({tau}*t/0.9)'")
    base += f",fps={FPS}[base];"

    layer = (f"[1:v]scale={over_w}:{over_h}:force_original_aspect_ratio=increase,"
             f"crop={over_w}:{over_h},boxblur={cfg['blur']}:2,"
             f"eq=brightness={cfg['bright']:.2f}:saturation={cfg['sat']:.2f},"
             f"crop={w}:{h}:"
             f"x='(in_w-out_w)*(0.5+0.5*sin({tau}*t/{period(cfg['drift_x']):.2f}))':"
             f"y='(in_h-out_h)*(0.5+0.5*sin({tau}*t/{period(cfg['drift_y']):.2f}))',"
             f"fps={FPS}[layer];")

    graph = (base + layer +
             f"[base][layer]blend=all_mode={cfg['blend']}:"
             f"all_opacity={cfg['opacity']:.2f}[mix]")

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
        "-af", "apad",
        "-map", "[v]", "-map", "2:a:0", "-t", f"{duration:.3f}",
        "-c:v", "libx264", "-preset", "veryfast", "-crf", "21", "-pix_fmt", "yuv420p",
        "-c:a", "aac", "-b:a", "160k", "-ar", "48000", "-ac", "2",
        "-movflags", "+faststart", str(dst),
    ], timeout=2400)
    return dst


# Целевая громкость по EBU R128. −16 LUFS — то, к чему приводят звук площадки
# вертикальных роликов: тише звучит вяло, громче они прижмут сами.
LOUDNESS_LUFS = -16.0
LOUDNESS_PEAK = -1.5


def normalize_loudness(src: Path, dst: Path, target: float = LOUDNESS_LUFS) -> Path:
    """Приводим звук к общему уровню.

    Провайдер озвучки отдаёт разную громкость от запроса к запросу, и в ленте из
    ста роликов это слышно: один приходится делать тише, следующий громче.
    loudnorm считает воспринимаемую громкость, а не пик, поэтому выравнивает
    именно то, что слышит ухо.
    """
    _ff([
        "-i", str(src),
        "-af", f"loudnorm=I={target}:TP={LOUDNESS_PEAK}:LRA=11",
        "-c:v", "copy", "-c:a", "aac", "-b:a", "192k", "-ar", "48000", "-ac", "2",
        "-movflags", "+faststart", str(dst),
    ], timeout=1800)
    return dst


def build_story_scene(frames: list[tuple[Path, float]], cover: Optional[Path],
                      audio: Path, dst: Path, size: tuple[int, int],
                      duration: float, workdir: Path, *,
                      cover_opaque: float = 0.82, cover_gone: float = 1.0,
                      fade: float = 0.18) -> Path:
    """Ролик из готовых сюжетных кадров с обложкой в начале.

    Кадры уже нарисованы под вертикаль и разложены по сценам, поэтому ничего не
    дорисовываем: каждый показывается свой отрезок, смена — короткий кроссфейд.
    Обложка лежит СВЕРХУ первого кадра и уходит к `cover_gone`: так первый кадр
    уже на месте, когда она исчезает, и провала в чёрное не возникает.

    frames — список (файл, длительность в секундах) в порядке сцен.
    """
    w, h = size
    workdir.mkdir(parents=True, exist_ok=True)
    if not frames:
        raise RuntimeError("нет ни одного кадра для сборки")

    fit = (f"scale={w}:{h}:force_original_aspect_ratio=increase,crop={w}:{h},"
           f"setsar=1,fps={FPS},format=yuv420p")

    inputs: list[str] = []
    parts: list[str] = []
    for index, (path, seconds) in enumerate(frames):
        span = max(seconds, 0.1)
        # Кроссфейд съедает время у следующего куска, поэтому каждый кадр, кроме
        # первого, держим на длину перехода дольше.
        hold = span + (fade if index else 0.0)
        inputs += ["-loop", "1", "-t", f"{hold:.3f}", "-i", str(path)]
        parts.append(f"[{index}:v]{fit}[f{index}];")

    graph = "".join(parts)
    current = "f0"
    offset = frames[0][1]
    for index in range(1, len(frames)):
        nxt = f"x{index}"
        graph += (f"[{current}][f{index}]xfade=transition=fade:duration={fade:.3f}:"
                  f"offset={max(offset - fade, 0.05):.3f}[{nxt}];")
        current = nxt
        offset += frames[index][1]

    audio_index = len(frames)
    inputs += ["-i", str(audio)]

    if cover is not None and cover.exists():
        cover_index = audio_index + 1
        inputs += ["-loop", "1", "-t", f"{max(cover_gone + 0.2, 0.3):.3f}",
                   "-i", str(cover)]
        # Обложку не режем: она свёрстана, и наезд срезал бы ей заголовок.
        graph += (f"[{cover_index}:v]scale={w}:{h}:force_original_aspect_ratio=decrease,"
                  f"pad={w}:{h}:(ow-iw)/2:(oh-ih)/2:color=black,setsar=1,fps={FPS},"
                  f"format=yuva420p,"
                  f"fade=t=out:st={cover_opaque:.3f}:d={max(cover_gone - cover_opaque, 0.05):.3f}"
                  f":alpha=1[cov];")
        graph += f"[{current}][cov]overlay=0:0:eof_action=pass[v]"
    else:
        graph += f"[{current}]null[v]"

    script = workdir / "story.filter"
    script.write_text(graph, encoding="utf-8")
    _ff([
        *inputs,
        "-filter_complex_script", str(script),
        "-af", "apad",
        "-map", "[v]", f"-map", f"{audio_index}:a:0", "-t", f"{duration:.3f}",
        "-c:v", "libx264", "-preset", "veryfast", "-crf", "21", "-pix_fmt", "yuv420p",
        "-c:a", "aac", "-b:a", "160k", "-ar", "48000", "-ac", "2",
        "-movflags", "+faststart", str(dst),
    ], timeout=2400)
    return dst


def build_cover_scene(cover: Path, audio: Path, dst: Path, size: tuple[int, int],
                     duration: float, workdir: Path, hold: float = 1.0,
                     fade: float = 0.5, dim: float = 0.42,
                     zoom_end: float = 1.08) -> Path:
    """Ролик поверх готовой обложки.

    От «живого кадра» отличается одним, но решающим: обложку нельзя обрезать.
    Там кадр генерировался под вертикаль и наезд ему только на пользу, а тут
    макет свёрстан — заголовок стоит вплотную к верхнему краю, и наезд в первую
    же секунду срезает ему шапку. Поэтому картинка вписывается целиком, а пустые
    поля (если пропорции не совпали) закрывает её же размытая копия.

    Движение начинается только после того, как обложка отработала: первую
    секунду она стоит ровно такой, какой её нарисовали, а дальше, уже под
    титрами и притемнённая, медленно наезжает — чтобы кадр не выглядел мёртвым.
    """
    w, h = size
    workdir.mkdir(parents=True, exist_ok=True)
    span = max(duration, 0.1)
    # Наезд размазываем по остатку ролика: если обложка держится дольше самого
    # ролика, наезжать уже негде.
    move = max(span - hold, 0.1)
    grow = max(zoom_end - 1.0, 0.0)
    zoom = (f"(1+{grow:.3f}*min(max((t-{hold:.3f})/{move:.3f},0),1))"
            if grow > 0.001 else "1")

    backdrop = (f"[0:v]scale={w}:{h}:force_original_aspect_ratio=increase,"
                f"crop={w}:{h},boxblur=24:2,eq=brightness=-0.25,fps={FPS}[bg];")
    # Вписываем целиком: ни одного пикселя макета не теряем.
    front = (f"[1:v]scale={w}:{h}:force_original_aspect_ratio=decrease,fps={FPS},"
             f"crop=w='trunc(iw/{zoom}/2)*2':h='trunc(ih/{zoom}/2)*2':"
             f"x='(iw-ow)/2':y='(ih-oh)/2',"
             f"scale={w}:{h}:force_original_aspect_ratio=decrease[fg];")
    graph = backdrop + front + f"[bg][fg]overlay=(W-w)/2:(H-h)/2[ov];"

    if dim > 0.001:
        # Обложка — крупный текст во весь верх: поверх неё титры не прочитать.
        # Гасим не скачком, а за fade секунд: резкая смена яркости бьёт по глазам.
        ramp = f"min(max((t-{hold:.3f})/{max(fade, 0.05):.3f},0),1)"
        graph += (f"[ov]eq=eval=frame:brightness='-{dim:.3f}*{ramp}',"
                  f"format=yuv420p[v]")
    else:
        graph += "[ov]format=yuv420p[v]"

    script = workdir / "cover.filter"
    script.write_text(graph, encoding="utf-8")
    _ff([
        "-loop", "1", "-i", str(cover),
        "-loop", "1", "-i", str(cover),
        "-i", str(audio),
        "-filter_complex_script", str(script),
        "-af", "apad",
        "-map", "[v]", "-map", "2:a:0", "-t", f"{duration:.3f}",
        "-c:v", "libx264", "-preset", "veryfast", "-crf", "21", "-pix_fmt", "yuv420p",
        "-c:a", "aac", "-b:a", "160k", "-ar", "48000", "-ac", "2",
        "-movflags", "+faststart", str(dst),
    ], timeout=2400)
    return dst


def build_loop_scene(loop: Path, audio: Path, dst: Path, size: tuple[int, int],
                     duration: float, workdir: Path, band_top: float = 0.0,
                     band_height: float = 0.0, band_color: str = "0x0b0d10") -> Path:
    """Сцена поверх готового зацикленного клипа.

    Клип короче озвучки, поэтому крутим его по кругу через -stream_loop. Это не
    то отматывание назад, от которого мы избавлялись в видеоряде: клип для того
    и генерировался, чтобы конец сходился с началом, и шов не виден.
    """
    w, h = size
    workdir.mkdir(parents=True, exist_ok=True)
    chain = (f"scale={w}:{h}:force_original_aspect_ratio=increase,crop={w}:{h},"
             f"fps={FPS}")
    if band_height > 0:
        y, bh = int(h * band_top), int(h * band_height)
        chain += f",drawbox=x=0:y={y}:w={w}:h={bh}:color={band_color}@0.92:t=fill"
    chain += ",format=yuv420p"

    _ff([
        "-stream_loop", "-1", "-i", str(loop),
        "-i", str(audio),
        "-vf", chain, "-af", "apad",
        "-map", "0:v:0", "-map", "1:a:0", "-t", f"{duration:.3f}",
        "-c:v", "libx264", "-preset", "veryfast", "-crf", "21", "-pix_fmt", "yuv420p",
        "-c:a", "aac", "-b:a", "160k", "-ar", "48000", "-ac", "2",
        "-movflags", "+faststart", str(dst),
    ], timeout=2400)
    return dst


# Ссылка в концовке. Крупнее заголовочного кегля вдвое против прежнего: адрес
# зритель разбирает посимвольно, и мелкий пропорциональный набор на видео читался
# плохо. Шрифт моноширинный жирный — ровные ширины знаков читаются на бегу лучше.
OUTRO_LINK_RATIO = 0.058
OUTRO_LINK_MAX_WIDTH = 0.88


# Пауза перед призывом. Без неё реклама начинается ровно там, где кончилась
# мысль ролика: два разных текста склеиваются встык и звучат как один сбивчивый.
OUTRO_LEAD_IN = 0.8


def build_outro(dst: Path, size: tuple[int, int], duration: float, audio: Path | None,
                title: str, link: str, font: str, workdir: Path,
                background: Path | None = None, link_font: str | None = None,
                lead_in: float = OUTRO_LEAD_IN) -> Path:
    """Концовка шортса: название канала крупно и ссылка под ним.

    Фоном берём последний кадр ролика, приглушённый и с наездом, — так концовка
    не выглядит приклеенной из другого ролика. Если кадра нет, рисуем ровный
    тёмный фон.
    """
    w, h = size
    workdir.mkdir(parents=True, exist_ok=True)
    span = max(duration, 0.1)
    font_arg = font.replace(":", r"\:") if font else ""

    # Ссылку рисуем своим шрифтом: моноширинным жирным, а не заголовочным.
    link_file = link_font if link_font is not None else (fonts.mono_font() or font)
    link_arg = (link_file or "").replace(":", r"\:")

    title_size = max(28, int(w * 0.085))
    link_size = max(18, int(w * OUTRO_LINK_RATIO))
    if link:
        # Длинный адрес не должен вылезать за поля — ужимаем по реальным метрикам.
        limit = w * OUTRO_LINK_MAX_WIDTH
        per_unit = fonts.text_width(link, link_file, 100) / 100 or 1.0
        link_size = max(18, min(link_size, int(limit / per_unit)))
    title_y = int(h * 0.40)
    link_y = title_y + int(title_size * 1.5)

    # Появление с замедлением: резкое включение выдаёт склейку.
    # Проявление идёт под паузу: к моменту, когда диктор начинает говорить,
    # название уже на экране.
    ease = f"(1-pow(1-clip((t-{lead_in * 0.25:.2f})/0.6,0,1),3))"
    link_ease = f"(1-pow(1-clip((t-{lead_in * 0.25 + 0.45:.2f})/0.6,0,1),3))"

    parts = []
    if background is not None and background.exists():
        parts += [f"scale={w}:{h}:force_original_aspect_ratio=increase", f"crop={w}:{h}",
                  # приглушаем, чтобы текст читался поверх любого кадра
                  "eq=brightness=-0.45:saturation=0.5", "boxblur=12:1",
                  f"crop=w='trunc(iw/(1+0.08*min(t/{span:.3f},1))/2)*2'"
                  f":h='trunc(ih/(1+0.08*min(t/{span:.3f},1))/2)*2'"
                  f":x='(iw-ow)/2':y='(ih-oh)/2'", f"scale={w}:{h}", f"fps={FPS}"]
    else:
        parts += [f"fps={FPS}"]

    if title:
        parts.append(
            f"drawtext=fontfile='{font_arg}':text='{_escape_drawtext(title.upper())}'"
            f":fontcolor=white:fontsize={title_size}"
            f":x=(w-text_w)/2:y='{title_y}+{int(title_size * 0.5)}*(1-{ease})'"
            f":alpha='{ease}'"
        )
    if link:
        parts.append(
            f"drawtext=fontfile='{link_arg}':text='{_escape_drawtext(link)}'"
            f":fontcolor=white:fontsize={link_size}"
            # Тень вместо обводки: обводка на жирном моноширинном забивает просветы
            # внутри букв, и адрес превращается в кашу.
            f":shadowcolor=black@0.75:shadowx={max(2, int(link_size * 0.05))}"
            f":shadowy={max(2, int(link_size * 0.05))}"
            f":x=(w-text_w)/2:y='{link_y}+{int(link_size * 0.6)}*(1-{link_ease})'"
            f":alpha='{link_ease}'"
        )
    parts.append("format=yuv420p")

    graph = workdir / "outro.filter"
    graph.write_text("[0:v]" + ",".join(parts) + "[v]", encoding="utf-8")

    args = []
    if background is not None and background.exists():
        args += ["-loop", "1", "-i", str(background)]
    else:
        args += ["-f", "lavfi", "-i", f"color=0x0b0d10:size={w}x{h}:rate={FPS}"]
    if audio is not None and audio.exists():
        # adelay сдвигает призыв, apad добивает тишиной до конца куска.
        delay = max(0, int(lead_in * 1000))
        args += ["-i", str(audio),
                 "-af", f"adelay={delay}:all=1,apad"]
        maps = ["-map", "[v]", "-map", "1:a:0"]
    else:
        args += ["-f", "lavfi", "-i", "anullsrc=r=48000:cl=stereo"]
        maps = ["-map", "[v]", "-map", "1:a:0"]

    _ff(args + ["-filter_complex_script", str(graph)] + maps + [
        "-t", f"{duration:.3f}",
        "-c:v", "libx264", "-preset", "veryfast", "-crf", "21", "-pix_fmt", "yuv420p",
        "-c:a", "aac", "-b:a", "160k", "-ar", "48000", "-ac", "2",
        "-movflags", "+faststart", str(dst),
    ], timeout=1200)
    return dst


def clips_needed(audio_sec: float, coverage_sec: float, max_clips: int = 8) -> int:
    """Сколько уникальных клипов нужно, чтобы закрыть сцену без явного повтора."""
    coverage = max(4.0, coverage_sec)
    return max(1, min(max(1, max_clips), math.ceil(audio_sec / coverage)))


# ---------------------------------------------------------------- музыкальный микс

# Громкость музыкального микса. Выше, чем у разговорного ролика (-16): там
# уровень задаёт речь с паузами, а здесь звук идёт сплошняком, и -14 LUFS —
# то, к чему приводит музыку сам YouTube. Берём его сразу, чтобы площадка
# ничего не трогала.
MUSIC_LUFS = -14.0

# Длина перехода между треками. Шесть секунд — это примерно четыре такта на
# спокойном темпе: достаточно, чтобы один трек ушёл незаметно, и мало, чтобы
# два разных гармонических центра успели столкнуться.
CROSSFADE_SEC = 6.0


def normalize_audio(src: Path, dst: Path, target: float = MUSIC_LUFS) -> Path:
    """Выровнять громкость звуковой дорожки (без видео)."""
    _ff([
        "-i", str(src),
        "-af", f"loudnorm=I={target}:TP={LOUDNESS_PEAK}:LRA=11",
        "-vn", "-c:a", "aac", "-b:a", "256k", "-ar", "48000", "-ac", "2", str(dst),
    ], timeout=3600)
    return dst


def stitch_music(tracks: list[Path], dst: Path, *, crossfade: float = CROSSFADE_SEC) -> float:
    """Сшить треки в один микс мягкими переходами. Возвращает длину перехода.

    acrossfade уводит хвост одного трека и одновременно поднимает начало
    следующего, поэтому стык не слышен совсем — в отличие от простой склейки,
    где между композициями получается щелчок и провал в тишину.

    Переход съедает по своей длине на каждом стыке, поэтому итоговая длина
    микса меньше суммы треков на (N-1) * crossfade — это учтено в тайм-коде.
    """
    if not tracks:
        raise ValueError("нечего сшивать: список треков пуст")

    # Переход не может быть длиннее самого короткого трека, иначе ffmpeg
    # отказывается строить фильтр. Берём с запасом — не больше трети.
    shortest = min((_stream_duration(t, "a") or 0.0) for t in tracks)
    fade = max(0.5, min(crossfade, shortest / 3.0)) if shortest > 2 else 0.0

    inputs: list[str] = []
    for track in tracks:
        inputs += ["-i", str(track)]

    fmt = "aformat=sample_fmts=fltp:sample_rates=48000:channel_layouts=stereo"
    if len(tracks) == 1 or fade <= 0:
        # Один трек или треки слишком короткие для перехода: просто приводим к
        # общему формату, чтобы дальше всё работало одинаково. Формат выравниваем
        # и здесь: concat отказывается склеивать дорожки с разной частотой.
        steps = [f"[{i}:a]{fmt}[a{i}]" for i in range(len(tracks))]
        parts = "".join(f"[a{i}]" for i in range(len(tracks)))
        steps.append(f"{parts}concat=n={len(tracks)}:v=0:a=1[out]"
                     if len(tracks) > 1 else "[a0]anull[out]")
        chain = ";".join(steps)
    else:
        steps = [f"[{i}:a]{fmt}[a{i}]" for i in range(len(tracks))]
        current = "[a0]"
        for index in range(1, len(tracks)):
            label = f"[x{index}]"
            steps.append(f"{current}[a{index}]acrossfade=d={fade:.3f}:"
                         f"c1=tri:c2=tri{label}")
            current = label
        steps.append(f"{current}anull[out]")
        chain = ";".join(steps)

    _ff([*inputs, "-filter_complex", chain, "-map", "[out]",
         "-c:a", "aac", "-b:a", "256k", "-ar", "48000", "-ac", "2", str(dst)],
        timeout=7200)
    return fade


# Во сколько раз разрыв на стыке может превышать обычный шаг между кадрами,
# чтобы повтор ещё считался незаметным. На живых клипах pixverse разрыв выходит
# в десятки раз больше — отсюда и порог с запасом.
SEAM_OK_RATIO = 4.0

# И абсолютный пол к нему. Одного отношения мало: у очень плавной рисованной
# анимации соседние кадры отличаются на сотые доли, и тогда даже невидимый
# разрыв в треть единицы яркости из 255 выходит «в девять раз больше обычного».
# Чинить такой стык — значит отрезать секунду клипа без всякой причины.
SEAM_MIN_ABS = 2.0

# Длина самосклейки. Секунда — достаточно, чтобы увести рывок, и мало, чтобы
# наплыв читался как монтажный приём.
LOOP_OVERLAP = 1.0


def loop_seam(path: Path, *, grid: tuple[int, int] = (64, 36)) -> tuple[float, float]:
    """Насколько рваный стык у клипа. Возвращает (разрыв, обычный шаг).

    Меряем по самим кадрам, а не по обещаниям генератора: модели берут требование
    замкнуть движение к сведению, но выполнять его нечем, и клип уезжает в одну
    сторону. Сравниваем последний кадр с первым и смотрим, насколько это больше
    обычной разницы между соседними кадрами.
    """
    width, height = grid
    raw = storage.run_ff_bytes([
        config.FFMPEG, "-v", "error", "-i", str(path),
        "-vf", f"scale={width}:{height},format=gray", "-f", "rawvideo", "-"],
        timeout=600)
    size = width * height
    frames = [raw[at:at + size] for at in range(0, len(raw) - size + 1, size)]
    if len(frames) < 3:
        return (0.0, 1.0)

    def gap(a: bytes, b: bytes) -> float:
        return sum(abs(x - y) for x, y in zip(a, b)) / size

    steps = [gap(frames[i], frames[i + 1]) for i in range(len(frames) - 1)]
    normal = max(0.01, sum(steps) / len(steps))
    return (gap(frames[-1], frames[0]), normal)


def close_loop(src: Path, dst: Path, *, overlap: float = LOOP_OVERLAP) -> Path:
    """Замкнуть клип самосклейкой: хвост наплывом переходит в собственное начало.

    Берём клип без первых overlap секунд и наплывом сводим его конец с этим же
    началом. Последний кадр результата совпадает с первым по построению, поэтому
    повтор замкнут при любом исходном материале — и, в отличие от зеркального
    прохода, движение никуда не разворачивается. Цена — короткий наплыв раз в
    период и потеря overlap секунд длины.
    """
    span = _stream_duration(src, "v") or 0.0
    fade = max(0.3, min(overlap, span / 4.0))
    if span <= fade * 3:
        raise RuntimeError(f"клип {span:.1f} с слишком короткий для самосклейки")
    graph = (f"[0:v]trim=start={fade:.3f},setpts=PTS-STARTPTS[body];"
             f"[0:v]trim=end={fade:.3f},setpts=PTS-STARTPTS[head];"
             f"[body][head]xfade=transition=fade:duration={fade:.3f}:"
             f"offset={span - 2 * fade:.3f}[out]")
    _ff(["-i", str(src), "-filter_complex", graph, "-map", "[out]",
         "-an", "-c:v", "libx264", "-preset", "veryfast", "-crf", "20",
         "-pix_fmt", "yuv420p", str(dst)], timeout=1800)
    return dst


def _mem_available() -> int:
    """Сколько памяти реально доступно прямо сейчас, байт. 0 — узнать не удалось."""
    try:
        for line in Path("/proc/meminfo").read_text(encoding="utf-8").splitlines():
            if line.startswith("MemAvailable:"):
                return int(line.split()[1]) * 1024
    except Exception:  # noqa: BLE001 — не на Linux или /proc закрыт
        return 0
    return 0


def can_reverse(duration: float, size: tuple[int, int]) -> bool:
    """Хватит ли памяти развернуть клип фильтром reverse.

    reverse держит ВСЕ кадры в памяти: восемь секунд FullHD — это больше
    гигабайта (измерено). На сервере с двумя воркерами и сборкой шортсов рядом
    такой запрос может кончиться убитым процессом, поэтому спрашиваем заранее.
    """
    w, h = size
    frames = max(1.0, duration * FPS)
    need = int(frames * w * h * 1.5 * 1.5)
    free = _mem_available()
    if free <= 0:
        return False
    return free > need * 2


def build_music_video(loop: Path, audio: Path, dst: Path, size: tuple[int, int],
                      duration: float, workdir: Path, *, pingpong: bool = False,
                      equalizer: bool = False) -> Path:
    """Собрать длинный ролик: заставка по кругу плюс готовый микс.

    Полчаса FullHD кодировать целиком не нужно и незачем: заставка — это один и
    тот же клип. Кодируем его ОДИН раз, а дальше повторяем файл склейкой без
    перекодирования. Разница в скорости — десятки раз, а картинка на выходе
    побитово та же.

    pingpong добавляет к клипу его же обратный проход. Это решает задачу
    бесшовности окончательно: конец зеркального куска совпадает с началом
    прямого кадр в кадр, какой бы клип ни выдал генератор. Годится только для
    ненаправленного движения — туман, облака, пылинки: льющийся дождь, поехав
    обратно, выглядит ошибкой.
    """
    w, h = size
    workdir.mkdir(parents=True, exist_ok=True)
    segment = workdir / "backdrop.mp4"
    chain = (f"scale={w}:{h}:force_original_aspect_ratio=increase,"
             f"crop={w}:{h},fps={FPS},format=yuv420p")
    span_in = _stream_duration(loop, "v") or 0.0

    # Замкнут ли клип на самом деле. Требование «seamless loop» генераторы видео
    # принимают к сведению, но выполнять им его нечем: клип уезжает в одну
    # сторону, и на живых примерах разрыв на стыке выходит в десятки раз больше
    # обычного шага между кадрами. Поэтому не верим, а меряем.
    try:
        seam, normal = loop_seam(loop)
    except Exception as exc:  # noqa: BLE001 — не смогли измерить, идём как раньше
        log.warning("Стык заставки не измерен: %s", exc)
        seam, normal = 0.0, 1.0
    ratio = seam / normal
    broken = ratio > SEAM_OK_RATIO and seam > SEAM_MIN_ABS
    if broken:
        log.info("Стык заставки рваный: разрыв %.1f из 255, в %.0f раз больше "
                 "обычного шага", seam, ratio)
    elif ratio > SEAM_OK_RATIO:
        log.info("Стык заставки формально выделяется (в %.0f раз), но разрыв "
                 "%.2f из 255 — глазу его нет, оставляю как есть", ratio, seam)

    if pingpong and broken and not can_reverse(span_in, size):
        # Памяти на разворот не хватит — починим наплывом, он почти ничего не
        # требует. Отказываться от починки нельзя: рывок каждые восемь секунд
        # в получасовом ролике повторится больше двухсот раз.
        log.warning("Зеркальное повторение пропущено: мало памяти (доступно %s), "
                    "замыкаю самосклейкой", storage.human_size(_mem_available()))
        pingpong = False

    if broken and not pingpong:
        # Движение однонаправленное (дождь, дорога) или памяти нет: разворачивать
        # нельзя, а наплыв хвоста на собственное начало замыкает клип при любом
        # материале и ничего не отматывает назад.
        try:
            closed = workdir / "loop_closed.mp4"
            workdir.mkdir(parents=True, exist_ok=True)
            close_loop(loop, closed)
            loop = closed
            span_in = _stream_duration(loop, "v") or span_in
            log.info("Заставка замкнута самосклейкой")
        except Exception as exc:  # noqa: BLE001 — хуже рывка это не сделает
            log.warning("Самосклейка не удалась: %s", exc)
    elif pingpong and not broken:
        # Клип и так замкнут — разворот только удвоил бы период без нужды.
        pingpong = False

    if pingpong:
        graph = (f"[0:v]{chain},split[fwd][rev];"
                 f"[rev]reverse[back];[fwd][back]concat=n=2:v=1:a=0[out]")
        args = ["-i", str(loop), "-filter_complex", graph, "-map", "[out]"]
    else:
        args = ["-i", str(loop), "-vf", chain]
    _ff([
        *args,
        "-an", "-c:v", "libx264", "-preset", "veryfast", "-crf", "20",
        # Ключевой кадр каждую секунду: при повторе файла склейкой стык
        # приходится на границу GOP, и декодер его не замечает.
        "-g", str(FPS), "-keyint_min", str(FPS), "-sc_threshold", "0",
        str(segment),
    ], timeout=1800)

    span = _stream_duration(segment, "v") or 0.0
    if span <= 0:
        raise RuntimeError("заставка не закодировалась")
    repeats = max(1, int(duration / span) + 2)

    listing = workdir / "backdrop.txt"
    listing.write_text("".join(f"file '{segment.as_posix()}'\n" for _ in range(repeats)),
                       encoding="utf-8")
    long_video = workdir / "backdrop_long.mp4"
    _ff(["-f", "concat", "-safe", "0", "-i", str(listing), "-c", "copy", str(long_video)],
        timeout=3600)

    if equalizer:
        # Со столбиками частот картинку приходится перекодировать целиком: поверх
        # неё ложится дорожка, меняющаяся каждый кадр. Это дороже по времени, но
        # эквалайзер, не связанный со звуком, зритель раскусывает мгновенно.
        build_music_video_eq(long_video, audio, dst, duration)
    else:
        _ff([
            "-i", str(long_video), "-i", str(audio),
            "-map", "0:v:0", "-map", "1:a:0", "-t", f"{duration:.3f}",
            "-c:v", "copy", "-c:a", "aac", "-b:a", "256k", "-ar", "48000", "-ac", "2",
            "-movflags", "+faststart", str(dst),
        ], timeout=3600)

    # Промежуточную дорожку без звука держать нельзя: она той же длины, что и
    # готовый ролик, и каждый получасовой микс занимал бы диск дважды.
    for leftover in (long_video, segment, listing):
        leftover.unlink(missing_ok=True)
    return dst


# ---------------------------------------------------------------- обрамление ролика

# Параметры, к которым приводится всё, что склеивается встык. Они совпадают с
# тем, что выдаёт build_music_video: только при полном совпадении склейка идёт
# без перекодирования, а перекодировать получасовой ролик ради пятисекундного
# интро — потерянные минуты и лишняя потеря качества.
PART_CRF = "20"
PART_AUDIO_BITRATE = "256k"


def normalize_part(src: Path, dst: Path, size: tuple[int, int]) -> Path:
    """Привести клип к параметрам основного ролика, добавив тишину, если звука нет.

    Склейка встык требует одинакового набора потоков у всех кусков. Интро часто
    приходит немым, и без этой тишины склейка либо отказывается работать, либо
    роняет звук у всего остального.
    """
    w, h = size
    chain = (f"scale={w}:{h}:force_original_aspect_ratio=increase,"
             f"crop={w}:{h},fps={FPS},format=yuv420p")
    has_audio = (_stream_duration(src, "a") or 0.0) > 0.01
    args = ["-i", str(src)]
    if not has_audio:
        args += ["-f", "lavfi", "-i",
                 "anullsrc=channel_layout=stereo:sample_rate=48000"]
    args += ["-vf", chain, "-map", "0:v:0",
             "-map", ("0:a:0" if has_audio else "1:a:0")]
    if not has_audio:
        args += ["-shortest"]
    _ff([*args,
         "-c:v", "libx264", "-preset", "veryfast", "-crf", PART_CRF,
         "-g", str(FPS), "-keyint_min", str(FPS), "-sc_threshold", "0",
         "-c:a", "aac", "-b:a", PART_AUDIO_BITRATE, "-ar", "48000", "-ac", "2",
         "-movflags", "+faststart", str(dst)], timeout=1800)
    return dst


def wrap_video(body: Path, dst: Path, *, intro: Optional[Path] = None,
               outro: Optional[Path] = None, size: tuple[int, int],
               workdir: Path) -> Path:
    """Обрамить готовый ролик интро и оутро.

    Куски приводятся к параметрам основного ролика и склеиваются БЕЗ
    перекодирования: тридцать минут картинки трогать незачем. Если склейка
    копированием дала негодный результат, пересобираем через фильтр — медленно,
    зато наверняка.
    """
    if intro is None and outro is None:
        return body
    workdir.mkdir(parents=True, exist_ok=True)

    parts: list[Path] = []
    if intro is not None:
        parts.append(normalize_part(intro, workdir / "intro.mp4", size))
    parts.append(body)
    if outro is not None:
        parts.append(normalize_part(outro, workdir / "outro.mp4", size))

    expected = sum(_stream_duration(part, "v") or 0.0 for part in parts)
    listing = workdir / "parts.txt"
    listing.write_text("".join(f"file '{part.as_posix()}'\n" for part in parts),
                       encoding="utf-8")
    _ff(["-f", "concat", "-safe", "0", "-i", str(listing), "-c", "copy",
         "-movflags", "+faststart", str(dst)], timeout=3600)
    if dst.exists() and dst.stat().st_size > 0 and _concat_sane(dst, expected):
        return dst

    log.warning("Склейка копированием не сошлась, пересобираю через фильтр")
    inputs: list[str] = []
    for part in parts:
        inputs += ["-i", str(part)]
    chain = "".join(f"[{i}:v:0][{i}:a:0]" for i in range(len(parts)))
    chain += f"concat=n={len(parts)}:v=1:a=1[v][a]"
    _ff([*inputs, "-filter_complex", chain, "-map", "[v]", "-map", "[a]",
         "-c:v", "libx264", "-preset", "veryfast", "-crf", PART_CRF,
         "-c:a", "aac", "-b:a", PART_AUDIO_BITRATE, "-ar", "48000", "-ac", "2",
         "-movflags", "+faststart", str(dst)], timeout=7200)
    return dst


# ---------------------------------------------------------------- оформление канала

# Эквалайзер по центру. Он строится из самой музыки, а не нарисован: столбики
# ходят под то, что звучит. Нарисованный «эквалайзер», живущий своей жизнью,
# зритель считывает мгновенно — и это хуже, чем вообще без него.
EQ_WIDTH = 900
EQ_HEIGHT = 140
EQ_ALPHA = 0.45
EQ_COLOR = "0x9fe8ff"


def equalizer_filter(width: int = EQ_WIDTH, height: int = EQ_HEIGHT,
                     alpha: float = EQ_ALPHA, color: str = EQ_COLOR) -> str:
    """Кусок графа фильтров: [aviz] -> [eq] со столбиками частот."""
    return (f"[aviz]showfreqs=s={width}x{height}:mode=bar:ascale=log:fscale=log:"
            f"win_size=2048:colors={color},format=rgba,"
            f"colorchannelmixer=aa={alpha:.2f}[eq]")


def build_music_video_eq(loop_long: Path, audio: Path, dst: Path, duration: float,
                         *, width: int = EQ_WIDTH, height: int = EQ_HEIGHT,
                         alpha: float = EQ_ALPHA, color: str = EQ_COLOR,
                         y_offset: float = 0.0) -> Path:
    """Собрать ролик с эквалайзером поверх картинки.

    Этот путь дороже обычного: картинку приходится перекодировать целиком, потому
    что поверх неё ложится дорожка, меняющаяся каждый кадр. Полчаса FullHD на
    veryfast — это минуты, а не часы, но «скопировать и склеить» тут уже нельзя.
    """
    offset = f"(H-h)/2{'+' if y_offset >= 0 else '-'}{abs(int(y_offset))}"
    graph = ("[1:a]asplit=2[aout][aviz];"
             + equalizer_filter(width, height, alpha, color)
             + f";[0:v][eq]overlay=(W-w)/2:{offset}[v]")
    _ff([
        "-i", str(loop_long), "-i", str(audio),
        "-filter_complex", graph, "-map", "[v]", "-map", "[aout]",
        "-t", f"{duration:.3f}",
        "-c:v", "libx264", "-preset", "veryfast", "-crf", "21", "-pix_fmt", "yuv420p",
        "-g", str(FPS), "-keyint_min", str(FPS), "-sc_threshold", "0",
        "-c:a", "aac", "-b:a", "256k", "-ar", "48000", "-ac", "2",
        "-movflags", "+faststart", str(dst),
    ], timeout=14400)
    return dst


def _escape_text(value: str) -> str:
    """Экранирование для drawtext: двоеточие и апостроф ломают разбор фильтра."""
    return (value.replace("\\", "\\\\").replace(":", r"\:")
            .replace("'", r"\'").replace("%", r"\%"))


def brand_clip(src: Path, dst: Path, *, size: tuple[int, int], title: str = "",
               subtitle: str = "", logo: Optional[Path] = None,
               font: Optional[str] = None, color: str = "0xE8EEF8") -> Path:
    """Нанести на клип логотип и заголовок.

    Почему текст рисуем мы, а не генератор картинок: буквы у него выходят
    кривыми и бессмысленными — это известное слабое место, и никакой промпт его
    не лечит. Поэтому модель делает сцену без единой буквы, а типографика
    ложится сверху ровно такой, какой задумана, и её можно менять, не трогая
    сцену.
    """
    w, h = size
    chain = (f"scale={w}:{h}:force_original_aspect_ratio=increase,"
             f"crop={w}:{h},fps={FPS}")
    inputs = ["-i", str(src)]
    graph = f"[0:v]{chain}[base]"
    current = "base"

    if logo is not None and logo.is_file():
        inputs += ["-i", str(logo)]
        # Логотип в левом верхнем углу, высотой в 5% кадра — он метка канала, а
        # не содержание кадра.
        graph += (f";[1:v]scale=-1:{int(h * 0.05)}[logo]"
                  f";[{current}][logo]overlay={int(w * 0.035)}:{int(h * 0.055)}[branded]")
        current = "branded"

    if title.strip():
        family = font or "/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf"
        size_px = int(h * 0.13)
        graph += (f";[{current}]drawtext=fontfile='{family}':"
                  f"text='{_escape_text(title.strip().upper())}':"
                  f"fontcolor={color}:fontsize={size_px}:"
                  f"x=(w-text_w)/2:y=(h-text_h)/2-{int(h * 0.14)}:"
                  f"shadowcolor=0x00000080:shadowx=0:shadowy={max(2, int(h * 0.004))}[titled]")
        current = "titled"

    if subtitle.strip():
        family = font or "/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf"
        size_px = int(h * 0.032)
        graph += (f";[{current}]drawtext=fontfile='{family}':"
                  f"text='{_escape_text(subtitle.strip().upper())}':"
                  f"fontcolor={color}@0.8:fontsize={size_px}:"
                  f"x=(w-text_w)/2:y=(h-text_h)/2-{int(h * 0.02)}[subtitled]")
        current = "subtitled"

    if current == "base" and logo is None:
        # Наносить нечего — только приводим к формату.
        _ff(["-i", str(src), "-vf", chain + ",format=yuv420p", "-an",
             "-c:v", "libx264", "-preset", "veryfast", "-crf", "20", str(dst)],
            timeout=1800)
        return dst

    graph += f";[{current}]format=yuv420p[v]"
    _ff([*inputs, "-filter_complex", graph, "-map", "[v]", "-an",
         "-c:v", "libx264", "-preset", "veryfast", "-crf", "20",
         "-g", str(FPS), "-keyint_min", str(FPS), "-sc_threshold", "0", str(dst)],
        timeout=1800)
    return dst
