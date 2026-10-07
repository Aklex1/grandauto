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
import random
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

# Главное требование к сцене — ни одной буквы. Логотип и заголовок наносятся
# поверх уже готового клипа средствами ffmpeg: у генераторов картинок текст
# выходит кривым и бессмысленным, это их известное слабое место, и никакой
# промпт его не лечит. Зато нанесённый нами заголовок можно менять, не трогая
# сцену, и он всегда читается.
IMAGE_RULES = ("Cinematic still, 16:9 widescreen, rich but restrained colour, soft film "
               "grain, no people facing the camera. ABSOLUTELY NO TEXT of any kind: no "
               "letters, no words, no numbers, no title card, no track name, no artist "
               "name, no caption, no subtitle, no typography, no logo, no watermark, no "
               "signage, no handwriting. Leave the centre of the frame visually calm so "
               "a title can be placed over it later.")


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


def _fetch_batch(client: KieClient, prompt: str, *, model: str,
                 dest_dir: Path, index: int) -> list[dict]:
    """Одна заявка к Suno: сгенерировать, скачать все варианты, измерить.

    Выполняется в отдельном потоке и НЕ трогает базу — только сеть и диск.
    Запись в базу делает вызывающий в своей транзакции: общая сессия
    SQLAlchemy на несколько потоков уже однажды обошлась нам сломанной сборкой.
    """
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

    # Исследование из архива важнее общего описания жанра: человек написал, какие
    # нужны инструменты, как развивается вещь и нужен ли бэк-вокал — заказываем
    # именно это. Задания считаются один раз и запоминаются у микса.
    left_sec = max(0.0, target_sec - effective_duration(lengths, fade))
    plan = ensure_plan(video_id, count=math.ceil(left_sec / (ASSUMED_TRACK_SEC * 2)) + 1)
    if plan:
        log.info("Микс #%s: заказ идёт по исследованию, заданий %s", video_id, len(plan))

    while effective_duration(lengths, fade) < target_sec and batches < MAX_BATCHES:
        # Сколько заявок ещё нужно: пока длина треков неизвестна, берём
        # осторожную оценку, а со второго круга — уже измеренную среднюю.
        per_batch = (sum(lengths) / max(1, batches)) if batches else ASSUMED_TRACK_SEC * 2
        left = target_sec - effective_duration(lengths, fade)
        want = max(1, math.ceil(left / max(60.0, per_batch)))
        wave = min(BATCH_CONCURRENCY, want, MAX_BATCHES - batches)

        with ThreadPoolExecutor(max_workers=wave) as pool:
            jobs = []
            for offset in range(wave):
                slot = batch_no + offset
                prompt = plan[slot] if slot < len(plan) else suno_prompt(style, slot)
                jobs.append(pool.submit(_fetch_batch, client, prompt, model=model,
                                        dest_dir=dest_dir, index=slot))
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
              language: str = "en", chapters: str = "") -> tuple[str, str, list[str]]:
    """Заголовок, описание и теги. Тайм-код свой, если архив не принёс готовый."""
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

    # Теги собираем сами, а подсказанное моделью добавляем сверху: модель хорошо
    # придумывает формулировки, но про лимит площадки и про то, как эти миксы
    # ищут, знает плохо.
    from . import tags as tags_mod

    series = ""
    if " — " in title:
        series = title.split(" — ", 1)[0].strip()
    rows = tags_mod.build(style_tags=style.tags, series=series, genre=style.key,
                          minutes=minutes, use=style.use, extra=tags)

    head = "Тайм-код:" if language == "ru" else "Tracklist:"
    body = chapters.strip() or tracklist_text(tracks)
    # Хештеги в самом верху: первые три площадка показывает над заголовком.
    top = " ".join("#" + h for h in tags_mod.hashtags(rows))
    description = f"{top}\n\n{description.strip()}\n\n{head}\n{body}" if top \
        else f"{description.strip()}\n\n{head}\n{body}"
    return title, description, rows


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

# Папки с производным материалом. Их содержимое звучит как музыка и весит как
# музыка, но в микс ему нельзя: шортсы — это нарезка из него же, тесты —
# прослушки на 20–80 секунд, qa — контрольные картинки. Взять их значит склеить
# микс с собственными обрезками. Текстовые файлы отсюда читаем всё равно: в
# specs и provenance лежат названия и порядок.
SKIP_MEDIA_DIRS = {"shorts", "short", "tests", "test", "samples", "sample",
                   "preview", "previews", "qa", "tools", "policy", "research",
                   "node_modules", "cache", "tmp"}

# Готовый мастер. Если в пакете есть сведённая дорожка, она и есть микс: сшивать
# сырьё заново — значит выбросить работу, которая уже сделана и оплачена.
MASTER_DIRS = {"master", "masters", "final", "mix"}
MASTER_WORDS = ("master", "-full", "_full", "full-", "full_")

# Варианты одной композиции. Suno отдаёт на заявку несколько дублей, и в архиве
# они лежат рядом: variation-01 и variation-02 — это ОДНА вещь в двух версиях.
# Поставить их подряд значит проиграть одну композицию дважды.
VARIATION = re.compile(r"(variation|variant|take|version|alt)[-_ ]*(\d+)", re.I)

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


def _derived(path: Path, root: Path) -> bool:
    """Лежит ли файл в папке с производным материалом."""
    parts = {part.lower() for part in path.relative_to(root).parts[:-1]}
    return bool(parts & SKIP_MEDIA_DIRS)


def _collect(root: Path) -> dict[str, list[Path]]:
    """Раскладываем всё, что есть в архиве, по видам.

    Медиа из папок с производным материалом не берём, а текст берём откуда
    угодно: названия и порядок часто лежат именно в служебных папках.
    """
    found: dict[str, list[Path]] = {"audio": [], "video": [], "image": [], "text": []}
    for path in sorted(root.rglob("*"), key=lambda p: _natural(p.name)):
        if not _usable(path):
            continue
        ext = path.suffix.lower()
        if ext in TEXT_EXT:
            found["text"].append(path)
            continue
        if _derived(path, root):
            continue
        if ext in AUDIO_EXT:
            found["audio"].append(path)
        elif ext in VIDEO_EXT:
            found["video"].append(path)
        elif ext in IMAGE_EXT:
            found["image"].append(path)
    return found


def _is_master(path: Path, root: Path) -> bool:
    rel = path.relative_to(root)
    if {part.lower() for part in rel.parts[:-1]} & MASTER_DIRS:
        return True
    low = path.stem.lower()
    return any(word in low for word in MASTER_WORDS)


def pick_music(paths: list[Path], root: Path) -> tuple[list[Path], bool]:
    """Что из звука пакета действительно составляет микс.

    Возвращает (файлы, это ли готовый мастер). Порядок решений важен: сначала
    ищем сведённую дорожку, и только если её нет — собираем из сырья, по одному
    варианту на композицию.
    """
    masters = [p for p in paths if _is_master(p, root)]
    if masters:
        # Мастеров может лежать несколько версий — берём самый длинный.
        best = max(masters, key=lambda p: storage.media_duration(p))
        return [best], True

    # Дубли одной композиции: группируем по папке плюс имя без номера варианта.
    groups: dict[tuple, list[tuple[int, Path]]] = {}
    plain: list[Path] = []
    for path in paths:
        match = VARIATION.search(path.stem)
        if not match:
            plain.append(path)
            continue
        key = (path.parent.parent if path.parent.name.lower() == "raw" else path.parent,
               VARIATION.sub("", path.stem).strip("-_ ").lower())
        groups.setdefault(key, []).append((int(match.group(2)), path))

    chosen = list(plain)
    for key in groups:
        # Первый вариант — тот, который слушали при приёмке.
        chosen.append(min(groups[key], key=lambda pair: pair[0])[1])
    chosen.sort(key=lambda p: (_natural(str(p.parent)), _natural(p.name)))
    return chosen, False


def _plate_score(path: Path, root: Path) -> tuple:
    """Насколько картинка годится в заставку длинного ролика.

    Решает не вес файла, а пропорции и назначение: вертикальная плашка для
    шортса может быть тяжелее горизонтальной, но в ролик 16:9 она не подходит.
    """
    rel = str(path.relative_to(root)).lower()
    width, height = _dimensions(path)
    landscape = 2 if width and height and width > height else 0
    named = 3 if ("master" in rel or "landscape" in rel) else 0
    version = max([int(num) for num in re.findall(r"v(\d+)", rel)] or [0])
    return (landscape + named, version, width * height, path.stat().st_size)


def _active_plate(texts: list[Path], images: list[Path]) -> Optional[Path]:
    """Картинка, названная в архиве действующей.

    Пакеты часто держат несколько версий плашек и отдельный файл с указанием,
    какая из них в работе. Такое указание важнее любых наших догадок.
    """
    names: list[str] = []
    for path in texts:
        if "active" not in path.stem.lower() or path.suffix.lower() != ".json":
            continue
        try:
            data = json.loads(path.read_text(encoding="utf-8", errors="replace"))
        except (OSError, ValueError):
            continue

        def walk(node):
            if isinstance(node, str):
                if Path(node).suffix.lower() in IMAGE_EXT:
                    names.append(Path(node).name.lower())
            elif isinstance(node, dict):
                for value in node.values():
                    walk(value)
            elif isinstance(node, list):
                for item in node:
                    walk(item)

        walk(data)
    for name in names:
        for image in images:
            if image.name.lower() == name:
                return image
    return None


CHAPTER_LINE = re.compile(r"^\s*((?:\d{1,2}:)?\d{1,2}:\d{2})\s+(\S.*)$")
TIME_KEYS = ("start", "start_sec", "startsec", "start_ms", "startms", "offset",
             "offset_sec", "at", "at_sec", "time", "timecode", "position", "from")
NAME_KEYS = ("title", "name", "track", "composition", "label", "piece")


def _seconds(value) -> Optional[float]:
    """Секунды из чего угодно: числа, миллисекунд, строки 1:02:03."""
    if isinstance(value, (int, float)):
        number = float(value)
        # Миллисекунды выдают себя величиной: 300000 — это не пять суток.
        return number / 1000.0 if number > 36000 else number
    if isinstance(value, str):
        text = value.strip()
        if re.fullmatch(r"\d+(\.\d+)?", text):
            return _seconds(float(text))
        parts = text.split(":")
        if 2 <= len(parts) <= 3 and all(part.strip().isdigit() for part in parts):
            total = 0.0
            for part in parts:
                total = total * 60 + int(part)
            return total
    return None


def read_chapters(files: list[Path]) -> list[tuple[float, str]]:
    """Готовый тайм-код из архива: [(секунда, название)].

    Если пакет сам знает, где какая композиция начинается, его слово важнее
    нашего счёта: мастер сведён не нами, и длины сырья к нему не сходятся.
    """
    best: list[tuple[float, str]] = []
    for path in sorted(files, key=lambda p: _natural(p.name)):
        rows: list[tuple[float, str]] = []
        try:
            raw = path.read_text(encoding="utf-8", errors="replace")[:400_000]
        except OSError:
            continue
        if path.suffix.lower() == ".json":
            try:
                data = json.loads(raw)
            except ValueError:
                continue

            def walk(node):
                if isinstance(node, list):
                    found: list[tuple[float, str]] = []
                    for item in node:
                        if not isinstance(item, dict):
                            continue
                        lower = {str(k).lower(): v for k, v in item.items()}
                        when = next((_seconds(lower[k]) for k in TIME_KEYS
                                     if k in lower and _seconds(lower[k]) is not None), None)
                        title = next((str(lower[k]) for k in NAME_KEYS
                                      if lower.get(k) and isinstance(lower[k], str)), "")
                        if when is not None and title:
                            found.append((when, title.strip()[:200]))
                    if len(found) >= 2:
                        rows.extend(found)
                    for item in node:
                        walk(item)
                elif isinstance(node, dict):
                    for value in node.values():
                        walk(value)

            walk(data)
        else:
            for line in raw.splitlines():
                match = CHAPTER_LINE.match(line)
                if match:
                    when = _seconds(match.group(1))
                    if when is not None:
                        rows.append((when, match.group(2).strip()[:200]))
        rows = sorted({(round(w, 2), t) for w, t in rows})
        if len(rows) > len(best):
            best = rows
    # Тайм-код обязан начинаться с нуля — иначе это не главы, а что-то другое.
    return best if best and best[0][0] <= 2.0 else []


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
                   language: str = "en", brief: str = "",
           want_cover: bool = False) -> MusicVideo:
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
    archive_brief = read_brief(found["text"])
    chapters = read_chapters(found["text"])
    style_key = style if style in STYLES else (
        guess_style(name, meta.get("style"), meta.get("title"), root.name,
                    brief[:2000], archive_brief[:2000])
        or STYLE_ORDER[0])
    style_row = style_of(style_key)

    want_minutes = int(minutes or 0)
    if not want_minutes:
        try:
            want_minutes = int(float(meta.get("minutes") or 0))
        except (TypeError, ValueError):
            want_minutes = 0

    music_files, is_master = pick_music(found["audio"], root)
    tracks: list[dict] = []
    for path in music_files:
        span = storage.media_duration(path)
        if span < MIN_TRACK_SEC:
            # Короткие вставки пропускаем молча — это не композиции.
            continue
        tracks.append({"title": path.stem[:300], "path": storage.rel(path),
                       "duration_sec": span, "prompt": "", "model": "",
                       "source_url": ""})

    have_sec = effective_duration([t["duration_sec"] for t in tracks], media.CROSSFADE_SEC)
    if is_master:
        # Мастер сведён и принят — его длина и есть длина ролика. Ни привычная
        # для жанра длительность, ни заказанная в форме здесь не применяются:
        # дописать к готовой работе двадцать минут чужой музыки — это брак, а не
        # исполнение заказа.
        minutes_final = max(1, min(180, int(round(have_sec / 60))))
    else:
        # Иначе: столько, сколько музыки принесли, но не меньше привычной для
        # жанра. Без нижней границы архив на три минуты остался бы трёхминутным
        # роликом, хотя формат живёт на долгом просмотре.
        if not want_minutes:
            want_minutes = max(style_row.minutes, int(have_sec // 60))
        minutes_final = max(5, min(180, want_minutes))

    backdrop = ""
    if found["video"]:
        # Самый длинный клип: короткие в таких архивах обычно превью.
        backdrop = storage.rel(max(found["video"], key=lambda p: storage.media_duration(p)))
    elif found["image"]:
        plate = _active_plate(found["text"], found["image"]) \
            or max(found["image"], key=lambda p: _plate_score(p, root))
        backdrop = storage.rel(plate)

    title = str(meta.get("title") or "").strip()[:300]
    video = MusicVideo(
        title=title or Path(name).stem[:300], style=style_row.key,
        style_label=style_row.label, minutes=minutes_final,
        suno_model=suno_model if suno_model in SUNO_MODELS else DEFAULT_SUNO_MODEL,
        language="ru" if str(meta.get("language") or language).startswith("ru") else "en",
        source_dir=storage.rel(root), backdrop_src=backdrop,
        description=str(meta.get("description") or "")[:20000],
        chapters_src="\n".join(f"{timecode(at)} {title}" for at, title in chapters),
        master_ready=is_master,
        # Исследование из формы идёт первым: его человек написал сейчас и под эту
        # задачу, а файл в архиве мог остаться с прошлого раза.
        brief="\n\n".join(part for part in (brief.strip(), archive_brief) if part)[:20000],
        status="queued", stage="queued")
    session.add(video)
    session.commit()

    for index, item in enumerate(tracks):
        session.add(MusicVideoTrack(video_id=video.id, idx=index, **item))
    session.commit()
    log.info("Архив %s разобран: %s (%.0f мин), заставка %s, жанр %s, тайм-код %s",
             root.name,
             "готовый мастер" if is_master else f"композиций {len(tracks)}",
             have_sec / 60, backdrop or "нет", style_row.key,
             f"из архива, {len(chapters)} глав" if chapters else "посчитаем сами")
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
    if video.chapters_src:
        parts.append(f"тайм-код из архива ({len(video.chapters_src.splitlines())} глав)")
    if video.brief:
        parts.append("заказ пойдёт по исследованию из архива")
    if video.backdrop_src:
        kind = "клип" if Path(video.backdrop_src).suffix.lower() in VIDEO_EXT else "картинка"
        parts.append(f"заставка из архива ({kind}) — генерировать не нужно")
    else:
        parts.append("заставки в архиве нет — будет сгенерирована")
    if video.master_ready:
        parts.append(f"готовый мастер — музыка не генерируется, длина {video.minutes} мин")
    else:
        parts.append(f"заказано {video.minutes} мин")
    return ", ".join(parts)


# ---------------------------------------------------------------- исследование и задания

# Файлы с общим описанием замысла: чего хотим от музыки в целом.
BRIEF_WORDS = ("brief", "concept", "recipe", "development", "research", "idea",
               "direction", "treatment", "start_here", "start here", "readme")

# Папки и имена с заданиями на отдельные композиции. В таких пакетах их обычно
# нумеруют: 01-a-instrumental.json, 02-b-wordless-female.json и так далее.
SPEC_DIRS = {"specs", "spec", "tracks", "compositions", "pieces"}
SPEC_NAME = re.compile(r"^\d{1,3}[-_. ]")

# Сколько текста исследования имеет смысл тащить. Suno принимает около тысячи
# знаков описания, и в них надо уложить и общий замысел, и задание на вещь.
BRIEF_BUDGET = 1800
SPEC_BUDGET = 700
PROMPT_LIMIT = 980


def _plain(text: str) -> str:
    """Убираем разметку: решётки заголовков, звёздочки, ссылки, длинные пустоты."""
    text = re.sub(r"```.*?```", " ", text, flags=re.S)
    text = re.sub(r"!?\[([^\]]*)\]\([^)]*\)", r"\1", text)
    text = re.sub(r"[#*_>`|]+", " ", text)
    text = re.sub(r"\s+", " ", text)
    return text.strip()


def _flatten(node, depth: int = 0) -> list[str]:
    """JSON в строки «ключ: значение» — Suno читает их как обычное описание."""
    if depth > 4:
        return []
    out: list[str] = []
    if isinstance(node, dict):
        for key, value in node.items():
            if isinstance(value, (str, int, float, bool)):
                out.append(f"{key}: {value}")
            else:
                nested = _flatten(value, depth + 1)
                if nested:
                    out.append(f"{key}: " + "; ".join(nested))
    elif isinstance(node, list):
        for item in node:
            if isinstance(item, (str, int, float, bool)):
                out.append(str(item))
            else:
                out.extend(_flatten(item, depth + 1))
    elif node is not None:
        out.append(str(node))
    return [part for part in out if part.strip()]


def read_brief(files: list[Path]) -> str:
    """Общее исследование из архива: что за музыка нужна и куда она развивается."""
    chunks: list[str] = []
    for path in sorted(files, key=lambda p: _natural(p.name)):
        stem = path.stem.lower().replace("-", "_")
        if not any(word in stem for word in BRIEF_WORDS):
            continue
        try:
            raw = path.read_text(encoding="utf-8", errors="replace")[:60_000]
        except OSError:
            continue
        if path.suffix.lower() == ".json":
            try:
                raw = "; ".join(_flatten(json.loads(raw)))
            except ValueError:
                continue
        text = _plain(raw)
        if len(text) > 40:
            chunks.append(f"{path.stem}: {text}")
    return "\n".join(chunks)[:20000]


def read_specs(files: list[Path]) -> list[str]:
    """Задания на отдельные композиции, по одному на вещь, в порядке номеров."""
    out: list[tuple[tuple, str]] = []
    for path in files:
        parts = {part.lower() for part in path.parts[:-1]}
        if not (parts & SPEC_DIRS or SPEC_NAME.match(path.name)):
            continue
        if any(word in path.stem.lower() for word in ("manifest", "receipt", "report",
                                                      "inventory", "index")):
            continue
        try:
            raw = path.read_text(encoding="utf-8", errors="replace")[:40_000]
        except OSError:
            continue
        if path.suffix.lower() == ".json":
            try:
                text = "; ".join(_flatten(json.loads(raw)))
            except ValueError:
                continue
        else:
            text = _plain(raw)
        if len(text) > 30:
            out.append((_natural(path.name), f"{path.stem}: {text}"[:4000]))
    out.sort(key=lambda pair: pair[0])
    return [text for _key, text in out]


def _condense(text: str, limit: int) -> str:
    """Укоротить до предела, не обрывая слово посередине."""
    text = _plain(text)
    if len(text) <= limit:
        return text
    cut = text[:limit]
    space = cut.rfind(" ")
    return (cut[:space] if space > limit * 0.6 else cut).strip()


def plan_from_brief(session: Session, style: Style, brief: str, specs: list[str],
                    *, count: int) -> list[str]:
    """Задания на композиции по исследованию: по одному описанию для Suno.

    Сначала пробуем переложить исследование в описания чат-моделью: человек пишет
    бриф прозой и по-русски, а Suno нужен сжатый английский список признаков.
    Если модель недоступна или молчит, обрезаем текст сами — хуже по складности,
    но заказ всё равно пойдёт по исследованию, а не по общему описанию жанра.
    """
    if not brief and not specs:
        return []

    model = st.get(session, "default_chat_model", "")
    # Заданий делаем не меньше, чем композиций в исследовании: иначе часть
    # замысла просто не дойдёт до генератора.
    want = max(1, min(20, max(count, len(specs), 1)))
    if model:
        source = ""
        if brief:
            source += "ОБЩЕЕ ИССЛЕДОВАНИЕ:\n" + brief[:12000] + "\n\n"
        if specs:
            source += "ЗАДАНИЯ НА КОМПОЗИЦИИ:\n" + "\n".join(
                f"{index + 1}. {spec[:1500]}" for index, spec in enumerate(specs[:want]))
        ask = (
            f"Ниже исследование для музыкального релиза. Жанр: {style.label}.\n\n"
            f"{source}\n\n"
            f"Составь {want} описаний для генератора инструментальной музыки Suno — "
            f"по одному на композицию, в том же порядке, что в исследовании. "
            f"Каждое описание: на английском, одной строкой, до 400 знаков, "
            f"перечислением признаков через запятую — инструменты, темп, тональность "
            f"или лад, развитие, характер, бэк-вокал если он нужен. "
            f"Не пиши слов песни и не повторяй описания друг за другом. "
            f"Верни строго JSON: {{\"prompts\": [\"...\", \"...\"]}}"
        )
        try:
            client = KieClient(api_key=st.get(session, "kie_api_key", "")
                               or config.KIE_API_KEY)
            data, _credits = client.chat_json(
                model, [{"role": "user", "content": ask}], temperature=0.6)
            rows = (data or {}).get("prompts") if isinstance(data, dict) else data
            if isinstance(rows, list):
                clean = [_condense(str(row), PROMPT_LIMIT) for row in rows
                         if str(row).strip()]
                if clean:
                    log.info("Исследование переложено в %s заданий", len(clean))
                    return clean
        except Exception as exc:  # noqa: BLE001 — обрежем сами, заказ не сорвётся
            log.warning("Исследование не переложено моделью: %s", exc)

    head = _condense(brief, BRIEF_BUDGET)
    if specs:
        return [_condense(f"{style.suno}. {head}. {spec}", PROMPT_LIMIT)
                for spec in specs[:want]]
    # Исследование без разбивки на вещи: одно описание на все заявки, но с
    # оттенками жанра — иначе Suno выдаст несколько почти одинаковых треков.
    return [_condense(f"{suno_prompt(style, index)}. {head}", PROMPT_LIMIT)
            for index in range(want)]


def ensure_plan(video_id: int, *, count: int) -> list[str]:
    """Задания на композиции для этого микса. Считаются один раз и запоминаются."""
    with session_scope() as session:
        video = session.get(MusicVideo, video_id)
        if video is None:
            return []
        stored = video.plan_json
        if stored:
            try:
                rows = json.loads(stored)
                if isinstance(rows, list) and rows:
                    return [str(row) for row in rows]
            except ValueError:
                pass
        specs = _specs_of(video)
        if not video.brief and not specs:
            return []
        plan = plan_from_brief(session, style_of(video.style), video.brief, specs,
                               count=count)
        if plan:
            video.plan_json = json.dumps(plan, ensure_ascii=False)
            session.commit()
        return plan


def _specs_of(video: MusicVideo) -> list[str]:
    """Задания на композиции из распакованного архива этого микса."""
    if not video.source_dir:
        return []
    root = storage.abspath(video.source_dir)
    if not root.is_dir():
        return []
    return read_specs([path for path in root.rglob("*")
                       if _usable(path) and path.suffix.lower() in TEXT_EXT])


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


def playing_cards(tracks, fade: float, *, offset: float = 0.0,
                  total: float = 0.0, chapters: str = "") -> list[dict]:
    """Что звучит в каждый момент: [{start, end, title}].

    Границы берём те же, что идут в тайм-код, — иначе карточка и главы в
    описании говорили бы разное. Если архив принёс готовый тайм-код, он главнее:
    мастер сведён не нами, и по длинам сырья его не восстановить.
    """
    rows: list[dict] = []
    if chapters.strip():
        parsed: list[tuple[float, str]] = []
        for line in chapters.splitlines():
            parts = line.strip().split(" ", 1)
            if len(parts) != 2:
                continue
            chunks = parts[0].split(":")
            if not all(chunk.isdigit() for chunk in chunks) or not 2 <= len(chunks) <= 3:
                continue
            seconds = 0.0
            for chunk in chunks:
                seconds = seconds * 60 + int(chunk)
            parsed.append((seconds + offset, parts[1].strip()))
        for index, (start, title) in enumerate(parsed):
            end = parsed[index + 1][0] if index + 1 < len(parsed) else (total or start + 600)
            rows.append({"start": start, "end": end, "title": title})
        return rows

    at = offset
    for index, track in enumerate(tracks):
        span = float(track.duration_sec or 0.0)
        last = index == len(tracks) - 1
        end = at + span - (0.0 if last else fade)
        rows.append({"start": at, "end": (total or end) if last else end,
                     "title": track.title or f"Track {index + 1}"})
        at = end
    return rows


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
        master_ready = bool(video.master_ready)
        channel_id = int(video.channel_id or 0)
    equalizer = False
    now_playing = False
    channel_name = ""
    tagline = ""
    mascot_dir = None
    logo_file = None
    if channel_id:
        from .models import MusicChannel

        with session_scope() as session:
            row = session.get(MusicChannel, channel_id)
            if row is not None:
                equalizer = bool(row.equalizer)
                now_playing = bool(row.now_playing)
                channel_name = row.name
                tagline = row.subtitle
                mascot_dir = Path(__file__).resolve().parent.parent / "assets" / "mascot"
                logo_row = None
                from . import musicchannels as _mch

                logo_row = _mch.one(session, channel_id, "logo")
                logo_file = storage.abspath(logo_row.path) if logo_row else None
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
        if master_ready:
            # Готовая дорожка из архива: генерировать нечего, досбор не нужен.
            with session_scope() as session:
                tracks = _tracks_of(session, video_id)
                session.expunge_all()
            if not tracks:
                raise RuntimeError("мастер из архива не найден на диске")
        else:
            tracks = ensure_tracks(video_id, target_sec=target_sec, model=suno_model)

        _stage(video_id, "backdrop")
        size = media.target_size("1080p", "16:9")
        from_image = False
        intro_file = outro_file = None
        channel_loop = ""
        if channel_id:
            # У канала своё оформление: случайная заставка из набора плюс
            # постоянные интро и оутро. Генерировать тут нечего — всё это
            # сделано один раз при заведении канала.
            from . import musicchannels as mch

            with session_scope() as session:
                loop_row = mch.pick_loop(session, channel_id)
                intro_row = mch.one(session, channel_id, "intro")
                outro_row = mch.one(session, channel_id, "outro")
                channel_loop = loop_row.path if loop_row else ""
                intro_file = storage.abspath(intro_row.path) if intro_row else None
                outro_file = storage.abspath(outro_row.path) if outro_row else None
                if loop_row is not None:
                    log.info("Микс #%s: заставка канала «%s»", video_id, loop_row.title)

        if channel_loop:
            loop_file = storage.abspath(channel_loop)
            loop_id, loop_path, loop_poster, loop_credits = 0, channel_loop, "", 0.0
        elif backdrop_src and not force_backdrop:
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
        body = folder / "body.mp4" if (intro_file or outro_file) else out
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
        # Карточку считаем ДО сборки: границы композиций нужны самому рендеру, и
        # они же потом идут в тайм-код — иначе карточка и главы в описании
        # говорили бы разное.
        intro_span = storage.media_duration(intro_file) if intro_file else 0.0
        with session_scope() as session:
            chapters_src = (session.get(MusicVideo, video_id).chapters_src or "")
        cards = playing_cards(tracks, fade, offset=intro_span,
                              total=intro_span + duration,
                              chapters=chapters_src) if now_playing else None
        media.build_music_video(loop_file, mix, body, size, duration, folder / "render",
                                pingpong=style.pingpong or from_image,
                                equalizer=equalizer, cards=cards,
                                artist=channel_name,
                                header_text=(f"{channel_name} RADIO"
                                             if channel_name else ""),
                                tagline=tagline, logo=logo_file,
                                mascot=mascot_dir, seed=video_id)
        if body != out:
            media.wrap_video(body, out, intro=intro_file, outro=outro_file,
                             size=size, workdir=folder / "wrap")
            # Длина ролика теперь с обрамлением, а тайм-код композиций — нет:
            # сдвигаем метки на длину интро, иначе главы разъедутся с первой же.
            duration = storage.media_duration(out)
            body.unlink(missing_ok=True)
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
            # Интро идёт перед музыкой, поэтому первая композиция начинается не с
            # нуля: без этого сдвига главы разъезжаются с самой первой метки.
            marks = playing_cards(alive, fade, offset=intro_span)
            for row, mark in zip(alive, marks):
                row.start_sec = mark["start"]

            video.loop_id = loop_id
            video.loop_path = loop_path
            video.intro_path = storage.rel(intro_file) if intro_file else ""
            video.outro_path = storage.rel(outro_file) if outro_file else ""
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
            title, description, tags = make_meta(session, video, alive, language,
                                                 chapters=video.chapters_src)
            if channel_id:
                # Серия канала выбирается случайно — так лента не превращается в
                # «Deep Focus 1…50», а набор заставок и серий перемешивается сам.
                from . import musicchannels as mch
                from .models import MusicChannel as _MC

                channel_row = session.get(_MC, channel_id)
                if channel_row is not None:
                    series, note = mch.pick_title(channel_row)
                    minutes_done = int(round(duration / 60)) or video.minutes
                    title = f"{series} — {minutes_done} Minutes"
                    if note:
                        title += f" · {note}"
                    title = title[:100]
            video.yt_title = title[:300]
            video.description = description
            video.tags = ", ".join(tags)
            video.tracklist = video.chapters_src.strip() or tracklist_text(alive)
            if not video.title:
                video.title = title[:300]
            session.commit()

        # Обложка: кадр той же сцены, крупный заголовок, пилюля с длительностью.
        # Ничего не генерируется — рисуем, поэтому она бесплатна.
        with session_scope() as session:
            video = session.get(MusicVideo, video_id)
            if video is not None and video.want_cover and video.video_path:
                from . import chrome

                try:
                    shot = folder / "cover_scene.png"
                    media.frame_grab(storage.abspath(video.video_path), shot,
                                     at=min(12.0, duration * 0.2))
                    series, note = (video.yt_title.split(" — ", 1) + [""])[:2]
                    minutes_done = int(round(duration / 60)) or video.minutes
                    cover = folder / "cover.jpg"
                    chrome.cover(cover, shot, title=series.strip() or style.label,
                                 note=note.split("·")[-1].strip(),
                                 badge=f"{minutes_done} min", logo=logo_file,
                                 accent=video_id)
                    video.cover_path = storage.rel(cover)
                    session.commit()
                    shot.unlink(missing_ok=True)
                except Exception as exc:  # noqa: BLE001 — ролик важнее обложки
                    log.warning("Обложка микса #%s не нарисована: %s", video_id, exc)

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


def create_for_channel(session: Session, channel, *, minutes: int = 0,
                       title: str = "", want_cover: bool = False) -> MusicVideo:
    """Трек в канале: жанр, язык, исследование и модель берутся у канала.

    Поэтому создание и сводится к длительности — выбирать больше нечего, всё
    остальное у канала постоянное.
    """
    video = create(session, style=channel.style,
                   minutes=minutes or channel.minutes,
                   suno_model=channel.suno_model or DEFAULT_SUNO_MODEL,
                   title=title, language=channel.language or "en",
                   brief=channel.brief or "")
    video.channel_id = channel.id
    video.want_cover = bool(want_cover)
    session.commit()
    return video


def create(session: Session, *, style: str, minutes: int = DEFAULT_MINUTES,
           suno_model: str = DEFAULT_SUNO_MODEL, title: str = "",
           language: str = "en", brief: str = "") -> MusicVideo:
    style_row = style_of(style)
    video = MusicVideo(
        title=title.strip()[:300], style=style_row.key, style_label=style_row.label,
        # Ниже пяти минут заказывать нечего: один кусок Suno и так длиннее, а
        # формат живёт на долгом просмотре. Сверху — три часа, дальше упираемся
        # в размер файла и время кодирования.
        minutes=max(5, min(180, int(minutes or DEFAULT_MINUTES))),
        suno_model=suno_model if suno_model in SUNO_MODELS else DEFAULT_SUNO_MODEL,
        language="ru" if language == "ru" else "en",
        brief=brief.strip()[:20000], want_cover=bool(want_cover),
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


# ---------------------------------------------------------------- публикация


def publish(video_id: int, *, privacy: str = "private") -> None:
    """Выложить готовый микс на YouTube.

    Обвязка уже написана при сборке: заголовок, описание с тайм-кодом и теги
    берём как есть, правленные руками — тоже, потому что они лежат в тех же
    полях. Обложку отдаём ту, что снята с ролика.
    """
    from . import youtube

    with session_scope() as session:
        video = session.get(MusicVideo, video_id)
        if video is None:
            raise RuntimeError(f"микс #{video_id} не найден")
        if not video.video_path:
            raise RuntimeError("микс ещё не собран — выкладывать нечего")
        path = storage.abspath(video.video_path)
        poster = storage.abspath(video.poster_path) if video.poster_path else None
        title = (video.yt_title or video.title or style_of(video.style).label)
        description = video.description or ""
        tags = [tag.strip() for tag in (video.tags or "").split(",") if tag.strip()]
        video.youtube_state = "running"
        video.youtube_error = ""
        session.commit()
        creds = youtube.credentials(session)

    if not path.is_file():
        _youtube_failed(video_id, "файл ролика не найден")
        raise RuntimeError("файл ролика не найден")

    try:
        # Загрузка идёт без открытой сессии базы: гигабайтный файл уходит
        # минутами, и держать транзакцию всё это время незачем.
        result = youtube.upload(
            creds, path, title=title, description=description, tags=tags,
            privacy=privacy,
            thumbnail=poster if poster and poster.is_file() else None)
    except Exception as exc:  # noqa: BLE001 — причину надо показать в панели
        log.warning("Микс #%s не выложен: %s", video_id, exc)
        _youtube_failed(video_id, str(exc))
        _note("warn", f"Микс #{video_id} не выложен на YouTube: {exc}")
        raise

    with session_scope() as session:
        video = session.get(MusicVideo, video_id)
        if video is not None:
            video.youtube_id = result["id"]
            video.youtube_url = result["url"]
            video.youtube_privacy = result.get("privacy") or privacy
            video.youtube_state = "done"
            video.youtube_error = ""
            session.commit()
    _note("info", f"Микс #{video_id} выложен: {result['url']} "
                  f"({youtube.PRIVACY.get(result.get('privacy') or privacy, privacy)})")
    log.info("Микс #%s выложен: %s", video_id, result["url"])


def _youtube_failed(video_id: int, reason: str) -> None:
    with session_scope() as session:
        video = session.get(MusicVideo, video_id)
        if video is not None:
            video.youtube_state = "failed"
            video.youtube_error = reason[:4000]
            session.commit()


# ---------------------------------------------------------------- вертикальный отрывок


def short_window(duration: float, tracks: list, seed: int = 0) -> tuple[float, float]:
    """Откуда резать отрывок: (начало, длина).

    Не с самого начала и не с конца: там интро и оутро, а шортс из титров —
    это шортс ни о чём. По возможности попадаем в середину одной композиции,
    чтобы отрывок не пришёлся на стык с переходом.
    """
    span = min(media.SHORT_MAX, max(media.SHORT_MIN, duration * 0.12))
    safe_from = min(duration * 0.12, 60.0)
    safe_to = max(safe_from + 1.0, duration - span - min(duration * 0.08, 40.0))
    rnd = random.Random(seed or 1)

    middles = []
    for track in tracks or []:
        start = float(getattr(track, "start_sec", 0.0) or 0.0)
        length = float(getattr(track, "duration_sec", 0.0) or 0.0)
        if length >= span + 8:
            # Берём с отступом от краёв композиции — там переходы.
            middles.append((start + 4.0, start + length - span - 4.0))
    middles = [(a, b) for a, b in middles if b > a and a >= safe_from and b <= safe_to]
    if middles:
        low, high = rnd.choice(middles)
        return round(rnd.uniform(low, high), 2), round(span, 2)
    return round(rnd.uniform(safe_from, safe_to), 2), round(span, 2)


def make_short(video_id: int, *, seed: int = 0) -> int:
    """Собрать вертикальный отрывок готового микса. Возвращает номер записи.

    Ничего не генерируется заново: звук берётся из собранного микса, картинка —
    из той же заставки. Поэтому отрывков можно делать сколько угодно и даром.
    """
    from .models import MusicShort

    with session_scope() as session:
        video = session.get(MusicVideo, video_id)
        if video is None:
            raise RuntimeError(f"микс #{video_id} не найден")
        if not video.audio_path or not video.loop_path:
            raise RuntimeError("микс ещё не собран — резать нечего")
        audio = storage.abspath(video.audio_path)
        loop = storage.abspath(video.loop_path)
        duration = storage.media_duration(audio)
        rows = session.execute(
            select(MusicVideoTrack).where(MusicVideoTrack.video_id == video_id)
            .order_by(MusicVideoTrack.idx)).scalars().all()
        channel_name = ""
        logo = None
        if video.channel_id:
            from . import musicchannels as mch
            from .models import MusicChannel

            channel = session.get(MusicChannel, video.channel_id)
            channel_name = channel.name if channel else ""
            logo_row = mch.one(session, video.channel_id, "logo")
            logo = storage.abspath(logo_row.path) if logo_row else None
        made = len(session.execute(
            select(MusicShort).where(MusicShort.video_id == video_id)).scalars().all())

    if not audio.is_file() or not loop.is_file():
        raise RuntimeError("файлы микса не найдены на диске")

    start, span = short_window(duration, rows, seed=seed or (video_id * 31 + made))
    # Название берём у композиции, на которую пришёлся отрывок: в шортсе должно
    # стоять то, что в нём звучит, а не заголовок всего микса.
    caption = ""
    for row in rows:
        if row.start_sec <= start < row.start_sec + row.duration_sec:
            caption = row.title
            break
    caption = caption or (rows[0].title if rows else "")

    folder = work_dir(video_id) / "shorts"
    folder.mkdir(parents=True, exist_ok=True)
    dest = folder / f"short_{made + 1:02d}.mp4"
    media.build_short(loop, audio, dest, start=start, span=span, title=caption,
                      artist=channel_name, workdir=folder / "work", logo=logo)

    poster = dest.with_suffix(".jpg")
    try:
        media.frame_grab(dest, poster, at=min(2.0, span * 0.2))
    except Exception as exc:  # noqa: BLE001 — без превью отрывок всё равно годен
        log.warning("Превью отрывка не снято: %s", exc)
        poster = None

    with session_scope() as session:
        row = MusicShort(video_id=video_id, title=caption[:300],
                         path=storage.rel(dest),
                         poster_path=storage.rel(poster) if poster else "",
                         start_sec=start, duration_sec=storage.media_duration(dest),
                         file_size=dest.stat().st_size)
        session.add(row)
        session.commit()
        short_id = row.id
    _note("info", f"Отрывок микса #{video_id}: «{caption}» с {timecode(start)}, "
                  f"{span:.0f} с")
    return short_id
