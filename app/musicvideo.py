"""Длинные музыкальные видео: микс из треков Suno поверх зацикленной заставки.

Почему это отдельный модуль, а не ещё один формат шортса. Здесь нет ни
сценария, ни озвучки, ни контент-плана: единица работы — микс на 25–35 минут,
где всё содержание — музыка одного жанра, а картинка не меняется вообще.
Экономика у такого ролика совсем другая: платим за несколько треков и ОДНУ
восьмисекундную заставку, которая потом крутится по кругу полчаса и
переиспользуется всеми последующими миксами того же жанра. Минута готового
видео не стоит ничего — тем и живёт весь формат.

Что делает сервер. Просит у Suno несколько инструментальных треков в выбранном
жанре (и забирает ВСЕ варианты из каждой заявки — они оплачены), сшивает их
мягкими переходами до нужной длины, один раз генерирует фоновый клип, повторяет
его склейкой без перекодирования и сразу выдаёт готовую обвязку для публикации:
заголовок, описание, теги и тайм-код, где какая композиция играет.
"""
from __future__ import annotations

import json
import logging
import math
import re
import shutil
import zipfile
from concurrent.futures import ThreadPoolExecutor
from dataclasses import dataclass, field
from pathlib import Path
from typing import Optional

from sqlalchemy import select
from sqlalchemy.orm import Session

from . import config, kie, media, music, storage
from . import settings_store as st
from .db import session_scope
from .kie import KieClient, KieError, extract_urls
from .models import Event, LoopClip, MusicVideo, MusicVideoTrack, utcnow

log = logging.getLogger("cf.musicvideo")


# ---------------------------------------------------------------- каталог жанров


@dataclass
class Style:
    """Жанр микса: чем он живёт на площадке и что заказывать генераторам."""

    key: str
    label: str            # как называется в панели
    use: str              # зачем его слушают — от этого зависит и заголовок, и теги
    suno: str             # описание музыки для Suno
    moods: tuple[str, ...]  # оттенки, чтобы треки одной заявки не были близнецами
    image: str            # промпт заставки
    motion: str           # что на заставке должно двигаться
    tags: tuple[str, ...] = field(default_factory=tuple)
    minutes: int = 30     # привычная длина для этого жанра
    # Можно ли повторять заставку «туда-обратно». Зеркальный проход делает
    # повтор бесшовным при любом клипе, но только если движению всё равно, в
    # какую сторону идти: туман, облака, пыль — да; дождь и дорога — нет.
    pingpong: bool = False


# Общие требования к заставке. Главное — замкнутое движение: клип, у которого
# конец не сходится с началом, при повторе дёргается каждые восемь секунд, и
# полчаса смотреть это невозможно. Второе — отсутствие текста: любые буквы,
# которые домыслит генератор, попадут в кадр на весь ролик.
LOOP_RULES = ("Seamless loop: the last frame must match the first so the clip repeats "
              "endlessly with no visible jump. One continuous locked-off shot, no cuts, "
              "no camera moves, no people walking in or out of frame. "
              "No text, no letters, no logo, no watermark.")

IMAGE_RULES = ("Cinematic still, 16:9 widescreen, rich but restrained colour, soft film "
               "grain, no text, no letters, no logo, no watermark, no people facing the "
               "camera.")


STYLES: dict[str, Style] = {
    "lofi_jazz": Style(
        key="lofi_jazz", label="Lo-fi джаз (учёба, работа)",
        use="study and focus",
        suno=("lo-fi jazz hip hop instrumental, dusty vinyl crackle, mellow Rhodes piano, "
              "soft muted trumpet, laid-back swung drums around 75 bpm, warm upright bass, "
              "no vocals, long continuous take, calm and unobtrusive, for studying"),
        moods=("rainy late evening, sparse and sleepy",
               "sunlit afternoon, brighter chords and light brushes",
               "deep night, muted and distant, almost ambient",
               "warm and nostalgic, tape saturation and soft wow-flutter"),
        image=("A small desk by a rain-streaked window at night, warm lamp light, open "
               "notebook and a cup of coffee, bookshelf blurred behind, city lights out of "
               "focus beyond the glass, cosy and quiet"),
        motion=("Rain runs steadily down the window glass, the lamp light flickers very "
                "faintly, steam drifts from the cup, bokeh city lights shimmer gently"),
        tags=("lofi", "lofi hip hop", "study music", "jazz lofi", "beats to study to"),
    ),
    "jazz_cafe": Style(
        key="jazz_cafe", label="Джаз-кафе (smooth jazz)",
        use="a slow morning or a quiet evening",
        suno=("smooth jazz instrumental for a quiet cafe, warm tenor saxophone lead, "
              "brushed drums, walking double bass, soft piano comping, relaxed swing around "
              "90 bpm, no vocals, long continuous performance, elegant and unhurried"),
        moods=("morning cafe, light and airy, piano forward",
               "late evening bar, smoky saxophone and sparse brushes",
               "slow ballad tempo, lush chords and soft vibraphone",
               "bossa-leaning groove, gentle nylon guitar"),
        image=("Interior of an empty jazz cafe at dusk, brass lamps over marble tables, a "
               "grand piano in the corner, tall windows with rain outside, warm amber light, "
               "polished wood and velvet"),
        motion=("Rain falls past the tall windows, candle flames sway gently on the tables, "
                "warm light slowly pulses, faint steam rises from a cup"),
        tags=("smooth jazz", "jazz music", "cafe music", "relaxing jazz", "coffee shop jazz"),
    ),
    "soul": Style(
        key="soul", label="Соул и нео-соул",
        use="an easy evening at home",
        suno=("instrumental neo-soul, warm Fender Rhodes, fat analog bass, loose live drums "
              "with a deep pocket around 80 bpm, lush seventh chords, tasteful electric "
              "guitar licks, strings pad underneath, no vocals, long continuous groove, "
              "warm and romantic"),
        moods=("slow and sensual, Rhodes and strings",
               "brighter mid-tempo groove, clavinet and horns",
               "late-night and intimate, sparse and dubby",
               "gospel-tinged, organ swells and tambourine"),
        image=("A vinyl record player on a low wooden sideboard in a warm living room at "
               "night, soft orange lamp, plants in shadow, record sleeves leaning against "
               "the wall, amber bokeh"),
        motion=("The record turns slowly and continuously on the platter, lamp light pulses "
                "very faintly, dust motes drift through the warm light"),
        tags=("neo soul", "soul music", "instrumental soul", "rnb instrumental",
              "relaxing soul"),
    ),
    "chillstep": Style(
        key="chillstep", label="Chillstep",
        use="focus and long drives",
        suno=("chillstep instrumental, wide atmospheric pads, airy female-like vocal chops "
              "used as texture, deep sub bass, halftime drums around 140 bpm with a relaxed "
              "feel, glassy plucks, long builds and soft drops, no lyrics, cinematic and "
              "uplifting, continuous mix energy"),
        moods=("melancholic and spacious, piano motif",
               "brighter and hopeful, arpeggios forward",
               "darker and deeper, heavy sub and sparse percussion",
               "euphoric, wide supersaw pads"),
        image=("A lone figure seen from behind standing on a cliff above an endless sea of "
               "clouds at dawn, pale pink and teal sky, vast negative space, soft volumetric "
               "light"),
        motion=("The cloud sea drifts slowly and continuously, light haze moves across the "
                "frame, the sky gradient shifts almost imperceptibly"),
        tags=("chillstep", "chillstep mix", "melodic dubstep", "study music",
              "beautiful chillstep"),
        pingpong=True,
    ),
    "chill_house": Style(
        key="chill_house", label="Chill house",
        use="work, a cafe or a sunset",
        suno=("chill house instrumental, warm four-on-the-floor groove at 118 bpm, deep "
              "round bassline, filtered chords, soft shakers and claps, dreamy pads, subtle "
              "organic percussion, no vocals, long continuous DJ-style flow, sunny and "
              "relaxed"),
        moods=("sunset terrace, warm filtered chords",
               "deeper night groove, dubby stabs",
               "beachy and organic, hand percussion and marimba",
               "nu-disco leaning, plucky bass and strings"),
        image=("A rooftop terrace at golden hour above a hazy coastal city, low rattan "
               "furniture, palms in silhouette, the sun melting into the sea, warm haze"),
        motion=("Palm leaves sway slowly in the breeze, the haze drifts across the city, "
                "sun glitter moves gently on the water"),
        tags=("chill house", "deep house mix", "house music", "summer mix", "chillout"),
        pingpong=True,
    ),
    "deep_house": Style(
        key="deep_house", label="Deep и melodic house",
        use="work and night driving",
        suno=("melodic deep house instrumental, hypnotic arpeggio, deep rolling bassline at "
              "122 bpm, wide analog pads, tight muted kick, long evolving builds, emotional "
              "minor chords, no vocals, continuous club-style flow, dark and elegant"),
        moods=("dark and hypnotic, long arpeggio",
               "emotional and melodic, piano and strings",
               "driving and percussive, tribal drums",
               "spacious afterhours, dubby chords"),
        image=("An empty rain-slicked city highway at night shot from a low angle, neon "
               "reflections on wet asphalt, distant skyline, deep blue and magenta light, "
               "light fog"),
        motion=("Fog drifts slowly across the road, neon reflections shimmer on the wet "
                "asphalt, distant lights twinkle faintly"),
        tags=("deep house", "melodic house", "melodic techno", "house mix", "night drive"),
        pingpong=True,
    ),
    "trance": Style(
        key="trance", label="Uplifting trance",
        use="workouts and long drives",
        suno=("uplifting trance instrumental, soaring supersaw lead, rolling 16th bassline "
              "at 138 bpm, huge emotional breakdown with piano and strings, long build and "
              "euphoric drop, classic Dutch trance sound, no vocals, continuous energy"),
        moods=("classic euphoric, piano breakdown",
               "progressive and hypnotic, long build",
               "darker and driving, acid-tinged bass",
               "epic and orchestral, choir pads"),
        image=("A vast starfield over a desert salt flat at night, the Milky Way arcing "
               "overhead, thin clouds, a single distant light on the horizon, deep blues and "
               "violets"),
        motion=("The starfield rotates very slowly, thin clouds drift across the stars, the "
                "stars twinkle faintly"),
        tags=("trance", "uplifting trance", "trance mix", "classic trance", "edm mix"),
    ),
    "ambient_sleep": Style(
        key="ambient_sleep", label="Ambient для сна",
        use="sleep and deep rest",
        suno=("ambient instrumental for sleep, very slow evolving pads, deep warm drones, "
              "almost no percussion, distant soft piano notes, gentle low-pass filtering, "
              "extremely calm and spacious, no vocals, one continuous unbroken piece"),
        moods=("deep and dark, low drone only",
               "warmer and softer, distant piano",
               "airy and weightless, high shimmering pads",
               "oceanic, slow swells"),
        image=("A calm moonlit lake surrounded by dark pine forest at night, mist on the "
               "water, a faint aurora in the sky, almost monochrome deep blue"),
        motion=("Mist drifts slowly over the still water, the aurora shifts very slowly, "
                "faint ripples move across the lake"),
        tags=("sleep music", "ambient music", "relaxing music", "deep sleep", "calm music"),
        minutes=60,
        pingpong=True,
    ),
    "synthwave": Style(
        key="synthwave", label="Synthwave и retrowave",
        use="night driving and coding",
        suno=("synthwave instrumental, analog gated drums at 100 bpm, fat retro bass "
              "arpeggio, wide chorused pads, soaring lead synth, tape saturation, eighties "
              "sci-fi soundtrack feel, no vocals, long continuous drive"),
        moods=("outrun and driving, bright lead",
               "darksynth, heavier and moodier",
               "dreamwave, slow and romantic",
               "spacey and cinematic, wide pads"),
        image=("A long straight desert road at night seen from a car, neon pink grid horizon, "
               "huge retro sunset gradient, palm silhouettes, chrome reflections, eighties "
               "sci-fi poster look"),
        motion=("The road rushes forward at a steady speed, the neon grid scrolls toward the "
                "horizon, stars twinkle above"),
        tags=("synthwave", "retrowave", "outrun", "80s music", "night drive music"),
    ),
    "bossa": Style(
        key="bossa", label="Bossa nova",
        use="a sunny morning and a cafe",
        suno=("bossa nova instrumental, soft nylon string guitar with classic bossa comping, "
              "brushed drums, warm double bass, light flute and vibraphone melodies, around "
              "100 bpm, no vocals, long continuous set, sunny and elegant"),
        moods=("sunny morning, bright guitar",
               "evening and smoky, flute lead",
               "slow and romantic, strings",
               "samba-leaning, livelier percussion"),
        image=("A balcony table with coffee and tropical plants overlooking a sunlit bay in "
               "the morning, white linen, dappled light, pastel buildings on the hillside"),
        motion=("Plant leaves sway gently in the sea breeze, dappled light shifts on the "
                "table, steam rises from the coffee, water glitters in the bay"),
        tags=("bossa nova", "jazz bossa", "morning music", "cafe music", "brazilian jazz"),
        pingpong=True,
    ),
    "piano_focus": Style(
        key="piano_focus", label="Нео-классика, фортепиано",
        use="reading, writing and study",
        suno=("neoclassical solo piano instrumental, intimate felt piano, close microphones "
              "with soft hammer noise, slow melancholic melodies, subtle string pad "
              "underneath, rubato and unhurried, no percussion, no vocals, one long "
              "continuous performance"),
        moods=("melancholic and sparse, felt piano alone",
               "warmer with soft strings",
               "brighter and hopeful, flowing arpeggios",
               "very slow and spacious, long pauses"),
        image=("An old grand piano in an empty room with tall windows, pale winter light, "
               "dust in the air, bare wooden floor, muted greys and warm beige"),
        motion=("Dust motes drift slowly through the shafts of light, sheer curtains breathe "
                "gently, the light shifts almost imperceptibly"),
        tags=("piano music", "neoclassical", "study music", "relaxing piano",
              "reading music"),
        pingpong=True,
    ),
    "cinematic": Style(
        key="cinematic", label="Кинематографичный эпик",
        use="work, writing and gaming",
        suno=("epic cinematic instrumental, wide orchestral strings, deep brass swells, "
              "hybrid pulsing synth bass, taiko and cinematic percussion, long builds to a "
              "powerful climax, heroic and emotional, no vocals, continuous score"),
        moods=("dark and tense, low strings and pulses",
               "heroic and bright, full brass",
               "emotional and restrained, solo cello",
               "vast and atmospheric, choir pads"),
        image=("A vast mountain range above a sea of clouds at sunrise, low sun breaking "
               "between peaks, snow drifting from the ridges, epic scale, cold blue and warm "
               "gold"),
        motion=("Clouds roll slowly between the peaks, snow drifts off the ridge lines, "
                "light slowly creeps across the mountains"),
        tags=("epic music", "cinematic music", "orchestral music", "focus music",
              "work music"),
        pingpong=True,
    ),
}

STYLE_ORDER = tuple(STYLES.keys())

# Длина микса. Двадцать пять минут — низ полезного диапазона: ролик уже
# засчитывается как «долгий просмотр», но ещё собирается из четырёх-пяти треков.
MINUTES_CHOICES = (25, 30, 35, 45, 60, 90)
DEFAULT_MINUTES = 30

# Модели Suno. Чем новее, тем длиннее трек: на V4_5 и выше одна заявка даёт
# пяти-восьмиминутные куски, и микс собирается из четырёх заявок вместо восьми.
SUNO_MODELS = ("V5", "V4_5PLUS", "V4_5", "V4")
DEFAULT_SUNO_MODEL = "V4_5PLUS"

# Сколько заявок к Suno держим одновременно. Одна заявка идёт около десяти минут,
# и последовательно получасовой микс собирался бы час; две в параллель — вдвое
# быстрее и не выглядит для API наплывом.
BATCH_CONCURRENCY = 2

# Предохранитель от бесконечной стройки: если Suno раз за разом отдаёт короткие
# куски, остановимся на этом числе заявок и соберём то, что есть.
MAX_BATCHES = 14

# Пока длина треков неизвестна, считаем так. Заниженная оценка безопаснее
# завышенной: лишний круг генерации дешевле, чем недобор до нужной длины.
ASSUMED_TRACK_SEC = 180.0

LOOP_SECONDS = 8
LOOP_QUALITY = "1080p"

# Сколько места занимает секунда готового ролика. FullHD при crf 20 на почти
# статичной картинке укладывается в полмегабайта в секунду; берём с запасом,
# потому что отказ по месту на последнем шаге дороже лишней осторожности.
BYTES_PER_SECOND = 800 * 1024
FMT_PREFIX = "mv:"

STAGES = {
    "queued": "в очереди",
    "music": "генерируем музыку",
    "backdrop": "делаем заставку",
    "stitch": "сшиваем микс",
    "render": "собираем видео",
    "meta": "пишем описание",
    "done": "готово",
    "failed": "ошибка",
}


def style_of(key: str) -> Style:
    return STYLES.get(key) or STYLES[STYLE_ORDER[0]]


def work_dir(video_id: int) -> Path:
    path = config.MEDIA_DIR / "_musicvideo" / str(video_id)
    path.mkdir(parents=True, exist_ok=True)
    return path


def backdrop_dir() -> Path:
    path = config.MEDIA_DIR / "_musicvideo" / "_backdrops"
    path.mkdir(parents=True, exist_ok=True)
    return path


def suno_prompt(style: Style, index: int) -> str:
    """Описание музыки для одной заявки: жанр плюс оттенок.

    С одинаковым промптом Suno выдаёт почти неотличимые куски, и микс начинает
    звучать как один трек, зацикленный восемь раз. Оттенок меняет материал, но
    оставляет жанр — переходы между треками остаются мягкими.
    """
    mood = style.moods[index % len(style.moods)]
    return (f"{style.suno}. Variation: {mood}. "
            f"Make it as long as possible, one continuous instrumental piece.")


def image_prompt(style: Style) -> str:
    return f"{style.image}. {IMAGE_RULES}"


def motion_prompt(style: Style) -> str:
    return f"{style.motion}. {LOOP_RULES}"


# ---------------------------------------------------------------- заставка


def backdrop_for(session: Session, style_key: str) -> Optional[LoopClip]:
    """Готовая заставка жанра, если файл на месте."""
    rows = session.execute(
        select(LoopClip).where(LoopClip.fmt == FMT_PREFIX + style_key,
                               LoopClip.is_active.is_(True))
        .order_by(LoopClip.id.desc())).scalars()
    for row in rows:
        if row.path and storage.abspath(row.path).exists():
            return row
    return None


def backdrops(session: Session) -> list[LoopClip]:
    """Все заставки музыкальных миксов — для библиотеки в панели."""
    return [row for row in session.execute(
        select(LoopClip).where(LoopClip.fmt.like(FMT_PREFIX + "%"),
                               LoopClip.is_active.is_(True))
        .order_by(LoopClip.id.desc())).scalars()]


def ensure_backdrop(session: Session, client: KieClient, style_key: str, *,
                    image_model: str, video_model: str, reuse: bool = True,
                    seconds: int = LOOP_SECONDS,
                    quality: str = LOOP_QUALITY) -> tuple[LoopClip, bool]:
    """Зацикленная заставка жанра: картинка плюс оживление. Делается один раз.

    Это главная экономия формата: заставка живёт в библиотеке лупов и достаётся
    всем последующим миксам того же жанра бесплатно. Перегенерация — только по
    явной просьбе.

    Второй элемент ответа — генерировали ли сейчас. По нему видно, относить ли
    цену заставки на этот микс: взятая из библиотеки уже оплачена другим.
    """
    if reuse:
        existing = backdrop_for(session, style_key)
        if existing is not None:
            return existing, False

    style = style_of(style_key)
    from . import loops as loops_mod

    video_model = loops_mod.to_image_model(video_model or loops_mod.DEFAULT_VIDEO_MODEL)
    prompt_image = image_prompt(style)
    shot = client.run_task(image_model, kie.image_input_payload(
        image_model, prompt=prompt_image, aspect_ratio="16:9",
        resolution="2K", output_format="png"), timeout=900, poll=5)
    image_urls = extract_urls(shot)
    if not image_urls:
        raise KieError(f"{image_model}: в ответе нет ссылки на изображение")
    credits = float(shot.get("_credits") or 0)

    prompt_motion = motion_prompt(style)
    clip = client.run_task(video_model, kie.image_to_video_input(
        video_model, prompt=prompt_motion, image_urls=image_urls[:1],
        resolution=quality, duration=seconds), timeout=1800, poll=8)
    video_urls = extract_urls(clip)
    if not video_urls:
        raise KieError(f"{video_model}: в ответе нет ссылки на видео")
    credits += float(clip.get("_credits") or 0)

    dest = backdrop_dir() / f"{style_key}_{int(utcnow().timestamp())}" \
                            f"{storage.guess_ext(video_urls[0], '.mp4')}"
    storage.download(video_urls[0], dest)
    duration = storage.media_duration(dest)
    if duration <= 0:
        dest.unlink(missing_ok=True)
        raise KieError(f"{video_model}: скачан пустой файл заставки")

    poster: Optional[Path] = backdrop_dir() / f"{dest.stem}.jpg"
    try:
        media.frame_grab(dest, poster, at=min(1.0, duration * 0.2))
    except Exception as exc:  # noqa: BLE001 — без превью библиотека работает
        log.warning("Превью заставки %s не снято: %s", style_key, exc)
        poster = None

    width, height = _dimensions(dest)
    # Прежнюю заставку жанра убираем из выдачи, но файл не трогаем: её мог
    # использовать уже выложенный ролик.
    for old in session.execute(
            select(LoopClip).where(LoopClip.fmt == FMT_PREFIX + style_key,
                                   LoopClip.is_active.is_(True))).scalars():
        old.is_active = False

    row = LoopClip(
        fmt=FMT_PREFIX + style_key, title=f"Заставка «{style.label}»",
        path=storage.rel(dest), poster_path=storage.rel(poster) if poster else "",
        prompt=prompt_motion[:2000], image_prompt=prompt_image[:2000], model=video_model,
        source_url=video_urls[0][:600], duration_sec=duration,
        width=width, height=height, file_size=dest.stat().st_size, credits=credits)
    session.add(row)
    session.commit()
    log.info("Заставка жанра %s готова: %.1f с, %dx%d", style_key, duration, width, height)
    return row, True


def _dimensions(path: Path) -> tuple[int, int]:
    try:
        out = storage.run_ff([config.FFPROBE, "-v", "error", "-select_streams", "v:0",
                              "-show_entries", "stream=width,height", "-of", "csv=p=0",
                              str(path)], timeout=60).strip()
        width, height = out.split(",")[:2]
        return int(width), int(height)
    except Exception:  # noqa: BLE001
        return 0, 0


# ---------------------------------------------------------------- музыка


def effective_duration(durations: list[float], fade: float) -> float:
    """Длина микса с учётом того, что каждый переход съедает свою длину."""
    if not durations:
        return 0.0
    return max(0.0, sum(durations) - fade * (len(durations) - 1))


def _tracks_of(session: Session, video_id: int) -> list[MusicVideoTrack]:
    """Треки микса, у которых файл реально лежит на диске."""
    rows = session.execute(
        select(MusicVideoTrack).where(MusicVideoTrack.video_id == video_id)
        .order_by(MusicVideoTrack.idx)).scalars().all()
    return [row for row in rows
            if row.path and storage.abspath(row.path).exists()
            and row.duration_sec > 1]


def _fetch_batch(client: KieClient, style: Style, *, model: str, index: int,
                 dest_dir: Path) -> list[dict]:
    """Одна заявка к Suno: сгенерировать, скачать все варианты, измерить.

    Выполняется в отдельном потоке и НЕ трогает базу — только сеть и диск.
    Запись в базу делает вызывающий в своей транзакции: общая сессия
    SQLAlchemy на несколько потоков уже однажды обошлась нам сломанной сборкой.
    """
    prompt = suno_prompt(style, index)
    pairs = music.generate_tracks(client, prompt, model=model, timeout=1800)
    out: list[dict] = []
    for number, (url, title) in enumerate(pairs):
        dest = dest_dir / f"{index:02d}_{number}{storage.guess_ext(url, '.mp3')}"
        try:
            storage.download(url, dest)
        except Exception as exc:  # noqa: BLE001 — один вариант не ломает заявку
            log.warning("Трек не скачался: %s", exc)
            continue
        duration = storage.media_duration(dest)
        if duration <= 1:
            dest.unlink(missing_ok=True)
            continue
        out.append({"title": title, "prompt": prompt, "model": model,
                    "path": storage.rel(dest), "source_url": url[:600],
                    "duration_sec": duration})
    if not out:
        raise music.MusicError("Suno отдал заявку, но ни один вариант не скачался")
    return out


def ensure_tracks(video_id: int, *, target_sec: float, model: str,
                  fade: float = media.CROSSFADE_SEC) -> list[MusicVideoTrack]:
    """Добираем музыку до нужной длины. Уже скачанное не генерируем заново.

    Поэтому «дособрать» после сорвавшейся генерации ничего не стоит: функция
    видит готовые треки на диске и просит у Suno только недостающее.
    """
    with session_scope() as session:
        video = session.get(MusicVideo, video_id)
        if video is None:
            raise RuntimeError(f"микс #{video_id} не найден")
        style = style_of(video.style)
        have = _tracks_of(session, video_id)
        next_idx = max([row.idx for row in have], default=-1) + 1
        client = KieClient(api_key=st.get(session, "kie_api_key", "") or config.KIE_API_KEY)

    dest_dir = work_dir(video_id) / "tracks"
    dest_dir.mkdir(parents=True, exist_ok=True)
    lengths = [row.duration_sec for row in have]
    batches = 0
    batch_no = next_idx
    failures: list[str] = []

    while effective_duration(lengths, fade) < target_sec and batches < MAX_BATCHES:
        # Сколько заявок ещё нужно: пока длина треков неизвестна, берём
        # осторожную оценку, а со второго круга — уже измеренную среднюю.
        per_batch = (sum(lengths) / max(1, batches)) if batches else ASSUMED_TRACK_SEC * 2
        left = target_sec - effective_duration(lengths, fade)
        want = max(1, math.ceil(left / max(60.0, per_batch)))
        wave = min(BATCH_CONCURRENCY, want, MAX_BATCHES - batches)

        with ThreadPoolExecutor(max_workers=wave) as pool:
            jobs = [pool.submit(_fetch_batch, client, style, model=model,
                                index=batch_no + offset, dest_dir=dest_dir)
                    for offset in range(wave)]
            results = []
            for job in jobs:
                try:
                    results.append(job.result())
                except Exception as exc:  # noqa: BLE001 — одна заявка не ломает микс
                    log.warning("Заявка к Suno не удалась: %s", exc)
                    failures.append(str(exc))

        batches += wave
        if not results:
            if len(failures) >= 3 and not lengths:
                raise RuntimeError("Suno не отдал ни одного трека: " + failures[-1])
            continue

        with session_scope() as session:
            for group in results:
                for item in group:
                    session.add(MusicVideoTrack(video_id=video_id, idx=next_idx, **item))
                    next_idx += 1
                    lengths.append(item["duration_sec"])
            session.commit()
        batch_no += wave

    with session_scope() as session:
        rows = _tracks_of(session, video_id)
        if not rows:
            raise RuntimeError("музыки нет: ни один трек не сгенерировался")
        if failures:
            log.info("Микс #%s: заявок не удалось %s, собираем из %s треков",
                     video_id, len(failures), len(rows))
        session.expunge_all()
        return rows


# ---------------------------------------------------------------- обвязка для публикации


def timecode(seconds: float) -> str:
    """0:00 или 1:02:03 — как принято в тайм-кодах YouTube."""
    total = int(max(0.0, seconds))
    hours, rest = divmod(total, 3600)
    minutes, secs = divmod(rest, 60)
    if hours:
        return f"{hours}:{minutes:02d}:{secs:02d}"
    return f"{minutes}:{secs:02d}"


def tracklist_text(tracks: list[MusicVideoTrack]) -> str:
    """Тайм-код: где какая композиция начинается в готовом миксе."""
    return "\n".join(f"{timecode(row.start_sec)} {row.title}" for row in tracks)


def fallback_meta(style: Style, minutes: int, language: str) -> tuple[str, str]:
    """Заголовок и описание без модели — если чат не ответил.

    Генерация текста может не удаться (кончились кредиты, шлюз молчит), но ролик
    при этом уже собран и оплачен. Остаться из-за описания без заголовка нельзя.
    """
    if language == "ru":
        return (f"{style.label} — {minutes} минут",
                f"{minutes} минут непрерывной музыки в жанре «{style.label}». "
                f"Подходит для {style.use}. Музыка создана с помощью ИИ.")
    return (f"{style.label.split('(')[0].strip()} Mix — {minutes} Minutes",
            f"{minutes} minutes of continuous {style.label.split('(')[0].strip().lower()} "
            f"for {style.use}. All music is AI-generated and free to listen to here.")


def make_meta(session: Session, video: MusicVideo, tracks: list[MusicVideoTrack],
              language: str = "en") -> tuple[str, str, list[str]]:
    """Заголовок, описание и теги. Тайм-код подставляем свой — он точный."""
    style = style_of(video.style)
    minutes = int(round(video.duration_sec / 60)) or video.minutes
    title, description = fallback_meta(style, minutes, language)
    tags = list(style.tags)

    model = st.get(session, "default_chat_model", "")
    if model:
        tongue = "русском" if language == "ru" else "английском"
        ask = (
            f"Ты ведёшь YouTube-канал с длинными музыкальными миксами. "
            f"Жанр микса: {style.label} ({style.suno}). "
            f"Длительность: {minutes} минут. Слушают ради: {style.use}.\n"
            f"Придумай обвязку для публикации на {tongue} языке. "
            f"Верни строго JSON: "
            f'{{"title": "...", "description": "...", "tags": ["...", "..."]}}\n'
            f"Требования: заголовок до 95 знаков, с указанием длительности и жанра, "
            f"без emoji и без КАПСА; описание 3–5 коротких абзацев — для чего "
            f"включать, что внутри, честное упоминание, что музыка создана ИИ; "
            f"10–14 тегов без решёток. Тайм-код не пиши — он будет добавлен отдельно."
        )
        try:
            client = KieClient(api_key=st.get(session, "kie_api_key", "")
                               or config.KIE_API_KEY)
            data, _credits = client.chat_json(
                model, [{"role": "user", "content": ask}], temperature=0.8)
            if isinstance(data, dict):
                title = str(data.get("title") or title)[:300]
                description = str(data.get("description") or description)
                raw_tags = data.get("tags")
                if isinstance(raw_tags, list) and raw_tags:
                    tags = [str(t).lstrip("#").strip()[:60] for t in raw_tags if str(t).strip()]
        except Exception as exc:  # noqa: BLE001 — текст вторичен, ролик уже готов
            log.warning("Описание микса #%s не сгенерировано: %s", video.id, exc)

    head = "Тайм-код:" if language == "ru" else "Tracklist:"
    description = f"{description.strip()}\n\n{head}\n{tracklist_text(tracks)}"
    if tags:
        description += "\n\n" + " ".join("#" + t.replace(" ", "") for t in tags[:14])
    return title, description, tags


# ---------------------------------------------------------------- архив с материалами

# Разбор идёт по типам файлов, а не по именам и не по манифесту. Причина простая:
# архивы приходят из разных мест и раскладка в них каждый раз своя, а вот то, что
# .mp3 — это музыка, а .mp4 — видео, верно всегда. Манифест, если он есть,
# читается сверху как подсказка, но ничего не требует.
AUDIO_EXT = {".mp3", ".wav", ".m4a", ".aac", ".flac", ".ogg", ".opus", ".aiff", ".aif"}
VIDEO_EXT = {".mp4", ".mov", ".webm", ".mkv", ".m4v"}
IMAGE_EXT = {".png", ".jpg", ".jpeg", ".webp", ".bmp"}
TEXT_EXT = {".json", ".txt", ".md"}

# Служебное добро, которое кладут рядом macOS и архиваторы.
SKIP_PARTS = ("__MACOSX", ".DS_STORE")

# Короткая звуковая вставка — не композиция микса: это джингл, отбивка или
# пример. Берём только то, что тянет на трек.
MIN_TRACK_SEC = 45.0


class ImportError_(RuntimeError):
    """Архив разобрать не удалось."""


def _natural(name: str) -> tuple:
    """Порядок как у человека: 2 раньше 10, а не наоборот."""
    digits = re.findall(r"\d+", name)
    return (int(digits[0]) if digits else 10 ** 9, name.lower())


def _usable(path: Path) -> bool:
    if not path.is_file() or path.name.startswith("._"):
        return False
    upper = str(path).upper()
    return not any(part in upper for part in SKIP_PARTS)


def _collect(root: Path) -> dict[str, list[Path]]:
    """Раскладываем всё, что есть в архиве, по видам."""
    found: dict[str, list[Path]] = {"audio": [], "video": [], "image": [], "text": []}
    for path in sorted(root.rglob("*"), key=lambda p: _natural(p.name)):
        if not _usable(path):
            continue
        ext = path.suffix.lower()
        if ext in AUDIO_EXT:
            found["audio"].append(path)
        elif ext in VIDEO_EXT:
            found["video"].append(path)
        elif ext in IMAGE_EXT:
            found["image"].append(path)
        elif ext in TEXT_EXT:
            found["text"].append(path)
    return found


META_KEYS = {
    "title": ("title", "yt_title", "youtube_title", "name", "heading"),
    "description": ("description", "desc", "summary", "about", "body"),
    "style": ("style", "genre", "mood", "preset"),
    "language": ("language", "lang", "locale"),
    "minutes": ("minutes", "duration_minutes", "target_minutes", "length_minutes"),
}


def _dig(node, names: tuple[str, ...]):
    """Находим значение по любому из имён на любой глубине JSON."""
    if isinstance(node, dict):
        for key, value in node.items():
            if str(key).lower() in names and isinstance(value, (str, int, float)):
                return value
            found = _dig(value, names)
            if found is not None:
                return found
    elif isinstance(node, list):
        for item in node:
            found = _dig(item, names)
            if found is not None:
                return found
    return None


def read_meta(files: list[Path]) -> dict:
    """Подсказки из текстовых файлов архива: заголовок, описание, жанр, язык.

    Ничего не требуем: архив без описания — это нормально, обвязку мы и так
    умеем писать сами. Поэтому любая ошибка разбора просто пропускается.
    """
    meta: dict = {}
    for path in files:
        try:
            raw = path.read_text(encoding="utf-8", errors="replace")[:200_000]
        except OSError:
            continue
        if path.suffix.lower() == ".json":
            try:
                data = json.loads(raw)
            except ValueError:
                continue
            for field, names in META_KEYS.items():
                if field not in meta:
                    value = _dig(data, names)
                    if value is not None and str(value).strip():
                        meta[field] = value
        else:
            stem = path.stem.lower()
            if "description" in stem and "description" not in meta:
                meta["description"] = raw.strip()
            elif "title" in stem and "title" not in meta:
                meta["title"] = raw.strip().splitlines()[0] if raw.strip() else ""
    return meta


def guess_style(*hints: str) -> str:
    """Жанр по названию архива и подсказкам из него.

    Имя вроде soul-notes-007 говорит о жанре прямо, и переспрашивать человека
    об очевидном не стоит. Не угадали — вернётся пустая строка, и жанр возьмётся
    из формы.
    """
    text = " ".join(str(h or "") for h in hints).lower().replace("_", " ").replace("-", " ")
    # Сначала составные имена, иначе «chill house» поймается как «chill».
    pairs = (
        ("lofi_jazz", ("lofi jazz", "lo fi jazz", "lofi", "lo fi", "lofi hip hop")),
        ("jazz_cafe", ("smooth jazz", "jazz cafe", "cafe jazz", "jazz")),
        ("chill_house", ("chill house", "chillhouse", "beach house")),
        ("deep_house", ("deep house", "melodic house", "melodic techno", "house")),
        ("chillstep", ("chillstep", "chill step", "melodic dubstep")),
        ("ambient_sleep", ("sleep", "ambient", "drone", "meditation")),
        ("piano_focus", ("neoclassical", "piano")),
        ("synthwave", ("synthwave", "retrowave", "outrun", "darksynth")),
        ("bossa", ("bossa", "samba")),
        ("trance", ("uplifting trance", "trance")),
        ("cinematic", ("cinematic", "epic", "orchestral")),
        ("soul", ("neo soul", "neosoul", "soul", "rnb", "r&b")),
    )
    for key, words in pairs:
        if any(word in text for word in words):
            return key
    return ""


def import_archive(session: Session, zip_path: Path, *, name: str = "", style: str = "",
                   minutes: int = 0, suno_model: str = DEFAULT_SUNO_MODEL,
                   language: str = "en") -> MusicVideo:
    """Собрать микс из архива с готовыми материалами.

    Архив передаётся путём к файлу, а не содержимым: в таком архиве лежит
    музыка, и десятки минут звука целиком в памяти держать незачем.

    Что берём: музыку — в треки микса, видео — в заставку как есть, картинку —
    в заставку с медленным наездом (это бесплатно), текст — как подсказку для
    заголовка и описания. Чего в архиве нет, то догенерируется на общих
    основаниях: не хватает музыки до заказанной длины — Suno допишет, нет ни
    видео, ни картинки — заставка закажется.
    """
    zip_path = Path(zip_path)
    if not zip_path.is_file() or zip_path.stat().st_size == 0:
        raise ImportError_("пустой файл")

    base = config.MEDIA_DIR / "_musicvideo" / "_uploads"
    base.mkdir(parents=True, exist_ok=True)
    stamp = utcnow().strftime("%Y%m%d_%H%M%S")
    slug = storage.slugify(Path(name or zip_path.name).stem, 60) or "mix"
    root = base / f"{slug}_{stamp}"
    root.mkdir(parents=True, exist_ok=True)

    try:
        with zipfile.ZipFile(zip_path) as zf:
            for member in zf.infolist():
                target = (root / member.filename).resolve()
                # Имя вроде ../../etc/passwd распаковалось бы за пределы папки.
                if not str(target).startswith(str(root.resolve())):
                    raise ImportError_(f"подозрительный путь в архиве: {member.filename}")
            zf.extractall(root)
    except zipfile.BadZipFile as exc:
        shutil.rmtree(root, ignore_errors=True)
        raise ImportError_(f"это не zip-архив: {exc}") from exc

    found = _collect(root)
    meta = read_meta(found["text"])
    style_key = style if style in STYLES else (
        guess_style(name, meta.get("style"), meta.get("title"), root.name)
        or STYLE_ORDER[0])
    style_row = style_of(style_key)

    want_minutes = int(minutes or 0)
    if not want_minutes:
        try:
            want_minutes = int(float(meta.get("minutes") or 0))
        except (TypeError, ValueError):
            want_minutes = 0

    tracks: list[dict] = []
    for path in found["audio"]:
        span = storage.media_duration(path)
        if span < MIN_TRACK_SEC:
            # Короткие вставки пропускаем молча — это не композиции.
            continue
        tracks.append({"title": path.stem[:300], "path": storage.rel(path),
                       "duration_sec": span, "prompt": "", "model": "",
                       "source_url": ""})

    # Длительность по умолчанию: столько, сколько музыки принесли, но не меньше
    # привычной для жанра. Иначе архив на час собрался бы в тридцатиминутный
    # микс, а половина материалов осталась бы лежать без дела.
    have_sec = effective_duration([t["duration_sec"] for t in tracks], media.CROSSFADE_SEC)
    if not want_minutes:
        want_minutes = max(style_row.minutes, int(have_sec // 60))

    backdrop = ""
    if found["video"]:
        # Самый длинный клип: короткие в таких архивах обычно превью.
        backdrop = storage.rel(max(found["video"], key=lambda p: storage.media_duration(p)))
    elif found["image"]:
        backdrop = storage.rel(max(found["image"], key=lambda p: p.stat().st_size))

    title = str(meta.get("title") or "").strip()[:300]
    video = MusicVideo(
        title=title or Path(name).stem[:300], style=style_row.key,
        style_label=style_row.label, minutes=max(5, min(180, want_minutes)),
        suno_model=suno_model if suno_model in SUNO_MODELS else DEFAULT_SUNO_MODEL,
        language="ru" if str(meta.get("language") or language).startswith("ru") else "en",
        source_dir=storage.rel(root), backdrop_src=backdrop,
        description=str(meta.get("description") or "")[:20000],
        status="queued", stage="queued")
    session.add(video)
    session.commit()

    for index, item in enumerate(tracks):
        session.add(MusicVideoTrack(video_id=video.id, idx=index, **item))
    session.commit()
    log.info("Архив %s разобран: треков %s (%.0f мин), заставка %s, жанр %s",
             root.name, len(tracks), have_sec / 60, backdrop or "нет", style_row.key)
    return video


def import_zip(session: Session, data: bytes, **kw) -> MusicVideo:
    """Тот же импорт, но архив передан содержимым: пишем во временный файл."""
    if not data:
        raise ImportError_("пустой файл")
    config.TMP_DIR.mkdir(parents=True, exist_ok=True)
    tmp = config.TMP_DIR / f"mv_{utcnow().strftime('%Y%m%d_%H%M%S_%f')}.zip"
    tmp.write_bytes(data)
    try:
        return import_archive(session, tmp, **kw)
    finally:
        tmp.unlink(missing_ok=True)


def import_report(video: MusicVideo, tracks: int) -> str:
    """Короткая сводка для панели: что взято из архива, что будет сгенерировано."""
    parts = [f"жанр {style_of(video.style).label}"]
    parts.append(f"музыки из архива: {tracks} шт." if tracks else "музыки в архиве нет")
    if video.backdrop_src:
        kind = "клип" if Path(video.backdrop_src).suffix.lower() in VIDEO_EXT else "картинка"
        parts.append(f"заставка из архива ({kind}) — генерировать не нужно")
    else:
        parts.append("заставки в архиве нет — будет сгенерирована")
    parts.append(f"заказано {video.minutes} мин")
    return ", ".join(parts)


# ---------------------------------------------------------------- сборка


def backdrop_from_archive(video_id: int, src: str, size: tuple[int, int],
                          *, seconds: float = 12.0) -> tuple[Path, bool]:
    """Заставка из архива. Возвращает (клип, сделан ли он из картинки).

    Готовый клип берём как есть. Картинку оживляем медленным наездом средствами
    ffmpeg — это бесплатно, а в паре с обратным проходом наезд туда-обратно
    выглядит ровно так, как и должна выглядеть бесконечная заставка.
    """
    path = storage.abspath(src)
    if not path.exists():
        raise RuntimeError(f"заставка из архива не найдена: {src}")
    if path.suffix.lower() in VIDEO_EXT:
        if storage.media_duration(path) <= 0.5:
            raise RuntimeError(f"заставка из архива пустая: {src}")
        return path, False

    dest = work_dir(video_id) / "backdrop_from_image.mp4"
    media.still_to_clip(path, dest, size, seconds, zoom=1.10)
    if storage.media_duration(dest) <= 0.5:
        raise RuntimeError(f"из картинки {src} не получилось заставки")
    return dest, True


def _stage(video_id: int, stage: str, *, error: str = "") -> None:
    with session_scope() as session:
        video = session.get(MusicVideo, video_id)
        if video is None:
            return
        video.stage = stage
        video.status = stage if stage in ("done", "failed") else "running"
        if error:
            video.error = error[:4000]
        if stage == "done":
            video.finished_at = utcnow()
        session.commit()


def _note(level: str, message: str) -> None:
    with session_scope() as session:
        session.add(Event(level=level, stage="музыка", message=message[:4000]))
        session.commit()


def build(video_id: int, *, reuse_backdrop: bool = True, language: str = "",
          force_backdrop: bool = False) -> None:
    """Собрать музыкальное видео целиком. Повторный вызов не платит дважды.

    Порядок шагов выбран по цене: сначала музыка (самое дорогое и самое хрупкое),
    потом заставка (её чаще всего вообще не надо генерировать), и только потом
    сборка — она бесплатна и повторяется сколько угодно раз.

    force_backdrop заставляет заказать заставку даже когда она пришла в архиве —
    на случай, если принесённая картинка не годится.
    """
    with session_scope() as session:
        video = session.get(MusicVideo, video_id)
        if video is None:
            raise RuntimeError(f"микс #{video_id} не найден")
        video.started_at = video.started_at or utcnow()
        video.error = ""
        session.commit()
        style_key, minutes = video.style, video.minutes
        backdrop_src = video.backdrop_src
        # Язык берём у микса, если вызов его не назвал: пересборка не должна
        # менять язык уже написанного описания.
        language = language or video.language or "en"
        suno_model = video.suno_model or DEFAULT_SUNO_MODEL
        image_model = st.get(session, "default_image_model", "nano-banana-2")
        video_model = st.get(session, "default_video_model", "")

    style = style_of(style_key)
    target_sec = max(60.0, minutes * 60.0)
    folder = work_dir(video_id)

    try:
        _stage(video_id, "music")
        tracks = ensure_tracks(video_id, target_sec=target_sec, model=suno_model)

        _stage(video_id, "backdrop")
        size = media.target_size("1080p", "16:9")
        from_image = False
        if backdrop_src and not force_backdrop:
            # Заставка пришла в архиве — это самая дорогая часть, и заказывать
            # её заново незачем.
            loop_file, from_image = backdrop_from_archive(video_id, backdrop_src, size)
            loop_id, loop_path, loop_poster, loop_credits = 0, storage.rel(loop_file), "", 0.0
        else:
            with session_scope() as session:
                client = KieClient(api_key=st.get(session, "kie_api_key", "")
                                   or config.KIE_API_KEY)
                loop, fresh = ensure_backdrop(session, client, style_key,
                                              image_model=image_model,
                                              video_model=video_model,
                                              reuse=reuse_backdrop)
                loop_id, loop_path, loop_poster = loop.id, loop.path, loop.poster_path
                loop_credits = loop.credits if fresh else 0.0
            loop_file = storage.abspath(loop_path)

        _stage(video_id, "stitch")
        paths = [storage.abspath(row.path) for row in tracks]
        raw_mix = folder / "mix_raw.m4a"
        fade = media.stitch_music(paths, raw_mix)
        mix = folder / "mix.m4a"
        try:
            media.normalize_audio(raw_mix, mix)
        except Exception as exc:  # noqa: BLE001 — уровень не критичен, микс важнее
            log.warning("Микс #%s: громкость не выровнена: %s", video_id, exc)
            mix = raw_mix
        duration = storage.media_duration(mix)
        if duration <= 10:
            raise RuntimeError("микс получился пустым")
        if mix != raw_mix:
            raw_mix.unlink(missing_ok=True)

        _stage(video_id, "render")
        out = folder / "video.mp4"
        # Полчаса FullHD — это порядка гигабайта, и в момент сборки на диске
        # лежат и промежуточная дорожка, и готовый файл. Упереться в место на
        # последнем шаге — значит потерять уже оплаченную музыку, поэтому
        # проверяем заранее.
        need = int(duration * BYTES_PER_SECOND * 2.2)
        free = storage.free_bytes()
        if free < need:
            raise RuntimeError(
                f"на диске {storage.human_size(free)}, для сборки нужно около "
                f"{storage.human_size(need)} — освободите место и нажмите "
                f"«Дособрать»: музыка уже скачана и второй раз не оплатится")
        # Наезд на картинку обязательно гоняем туда-обратно: вернуться рывком
        # к началу наезда заметнее любого другого стыка.
        media.build_music_video(loop_file, mix, out, size, duration, folder / "render",
                                pingpong=style.pingpong or from_image)
        poster = folder / "poster.jpg"
        try:
            media.frame_grab(out, poster, at=min(5.0, duration * 0.1))
        except Exception as exc:  # noqa: BLE001
            log.warning("Превью микса #%s не снято: %s", video_id, exc)
            poster = None

        _stage(video_id, "meta")
        with session_scope() as session:
            video = session.get(MusicVideo, video_id)
            rows = session.execute(
                select(MusicVideoTrack).where(MusicVideoTrack.video_id == video_id)
                .order_by(MusicVideoTrack.idx)).scalars().all()
            alive = [row for row in rows if row.path and storage.abspath(row.path).exists()
                     and row.duration_sec > 1]
            # Начало каждого трека: сумма предыдущих минус перекрытия переходов.
            # Без вычета перекрытий тайм-код к концу получасового микса врёт на
            # минуту — на длинном ролике это сразу видно.
            at = 0.0
            for position, row in enumerate(alive):
                row.start_sec = at
                at += row.duration_sec - (fade if position < len(alive) - 1 else 0.0)

            video.loop_id = loop_id
            video.loop_path = loop_path
            video.poster_path = storage.rel(poster) if poster else loop_poster
            video.audio_path = storage.rel(mix)
            video.video_path = storage.rel(out)
            video.duration_sec = duration
            video.width, video.height = size
            video.file_size = out.stat().st_size
            video.crossfade_sec = fade
            # Пересборка на той же заставке не должна обнулять уже записанную
            # трату: loop_credits приходит только тогда, когда заставку
            # генерировали именно сейчас.
            tracks_cost = sum(row.credits for row in alive)
            backdrop_cost = loop_credits or max(0.0, video.credits - tracks_cost)
            video.credits = round(tracks_cost + backdrop_cost, 2)
            title, description, tags = make_meta(session, video, alive, language)
            video.yt_title = title[:300]
            video.description = description
            video.tags = ", ".join(tags)
            video.tracklist = tracklist_text(alive)
            if not video.title:
                video.title = title[:300]
            session.commit()

        _stage(video_id, "done")
        _note("info", f"Музыкальный микс #{video_id} готов: {style.label}, "
                      f"{duration / 60:.0f} мин, треков {len(tracks)}")
    except Exception as exc:  # noqa: BLE001 — задача очереди не должна падать молча
        log.exception("Микс #%s не собрался", video_id)
        _stage(video_id, "failed", error=str(exc))
        _note("warn", f"Музыкальный микс #{video_id} не собрался: {exc}")
        raise


def library(session: Session) -> list[MusicVideo]:
    return list(session.execute(
        select(MusicVideo).order_by(MusicVideo.id.desc())).scalars())


def create(session: Session, *, style: str, minutes: int = DEFAULT_MINUTES,
           suno_model: str = DEFAULT_SUNO_MODEL, title: str = "",
           language: str = "en") -> MusicVideo:
    style_row = style_of(style)
    video = MusicVideo(
        title=title.strip()[:300], style=style_row.key, style_label=style_row.label,
        # Ниже пяти минут заказывать нечего: один кусок Suno и так длиннее, а
        # формат живёт на долгом просмотре. Сверху — три часа, дальше упираемся
        # в размер файла и время кодирования.
        minutes=max(5, min(180, int(minutes or DEFAULT_MINUTES))),
        suno_model=suno_model if suno_model in SUNO_MODELS else DEFAULT_SUNO_MODEL,
        language="ru" if language == "ru" else "en",
        status="queued", stage="queued")
    session.add(video)
    session.commit()
    return video


def drop(session: Session, video_id: int, *, with_files: bool = False) -> None:
    """Убрать микс. Файлы по умолчанию остаются — их могли уже выложить."""
    video = session.get(MusicVideo, video_id)
    if video is None:
        return
    if with_files:
        folder = config.MEDIA_DIR / "_musicvideo" / str(video_id)
        if folder.exists():
            shutil.rmtree(folder, ignore_errors=True)
        # Распакованный архив лежит отдельно от рабочей папки микса, и без этого
        # он остался бы на диске навсегда — а это десятки минут музыки.
        if video.source_dir:
            source = storage.abspath(video.source_dir)
            uploads = (config.MEDIA_DIR / "_musicvideo" / "_uploads").resolve()
            if source.is_dir() and str(source.resolve()).startswith(str(uploads)):
                shutil.rmtree(source, ignore_errors=True)
    session.delete(video)
    session.commit()
