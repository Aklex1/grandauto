"""Библиотека зацикленных видеофонов для форматов шортса.

Форматы «живого кадра» до сих пор оживляли одну картинку средствами ffmpeg:
наезд, дрейф размытой копии, мерцание. Это бесплатно, но движение остаётся
нарисованным — вода не течёт, огонь не горит, трава не гнётся.

Здесь фон делается один раз по-настоящему: nano banana рисует кадр, pixverse
превращает его в короткий зацикленный клип, клип ложится в библиотеку и дальше
переиспользуется во ВСЕХ шортсах этого формата. Платим один раз за формат, а не
за каждую сцену, — в этом вся разница с полным видеорядом.
"""
from __future__ import annotations

import logging
from pathlib import Path
from typing import Optional

from sqlalchemy import select
from sqlalchemy.orm import Session

from . import config, prompts, storage
from .kie import KieClient, KieError, extract_urls, image_to_video_input
from .models import LoopClip

log = logging.getLogger("cf.loops")

# Длина клипа. Короткий стоит дешевле, но чаще повторяется; на восьми секундах
# повтор в четырнадцатисекундной сцене происходит один раз и не бросается в глаза.
LOOP_SECONDS = 8
LOOP_QUALITY = "720p"

# Что просим у pixverse. Главное здесь — требование замкнуть движение: клип,
# который не сходится концом с началом, на стыке дёргается, и весь смысл лупа
# теряется. Плюс запрет на смену плана: любой монтажный стык внутри восьми
# секунд делает бесшовное повторение невозможным.
LOOP_MOTION: dict[str, str] = {
    "bust":   "Very slow push in on the statue while thin smoke drifts across the frame",
    "sea":    "Ocean swells roll slowly and continuously, foam and haze drifting",
    "sky":    "Clouds drift steadily across the frame, slow and continuous",
    "flight": "The eagle glides forward with slow steady wingbeats, camera holding behind it",
    "fire":   "Embers glow and pulse, sparks rise slowly, smoke drifts upward",
    "rain":   "Raindrops run down the glass continuously, bokeh shifting gently behind",
    "stars":  "The starfield rotates very slowly around the pole, stars twinkling faintly",
    "road":   "The camera moves forward along the road at a steady speed, headlights raking",
    "candle": "The candle flame flickers and sways gently, smoke curling above it",
    "snow":   "Snowflakes fall steadily and continuously through the dark air",
    "field":  "Wind moves through the grass in long continuous waves",
    "deep":   "Light shafts sway slowly underwater, particles drifting upward",
}

LOOP_RULES = ("Seamless loop: the last frame must match the first so the clip can repeat "
              "endlessly without a visible jump. One continuous shot, no cuts, no camera "
              "changes, no people entering the frame. No text, no letters, no watermark.")


# Модель канала — это генератор видео ИЗ ТЕКСТА (pixverse-v6/text-to-video), а лупу
# нужна модель, оживляющая картинку. Их наборы полей несовместимы: text-to-video
# требует aspect_ratio и не знает image_urls, поэтому запрос с картинкой падал
# с «This field is required». Приводим модель к парному варианту сами.
T2V_TO_I2V = (
    ("/text-to-video", "/image-to-video"),
    ("/text2video", "/image2video"),
    ("-text-to-video", "-image-to-video"),
    ("/t2v", "/i2v"),
)


def to_image_model(model: str) -> str:
    """Парная image-to-video модель для той, что выбрана у канала."""
    low = (model or "").strip()
    if not low:
        return DEFAULT_VIDEO_MODEL
    if is_image_model(low):
        return low
    for t2v, i2v in T2V_TO_I2V:
        if t2v in low.lower():
            # Замена по позиции: имя модели может отличаться регистром.
            at = low.lower().index(t2v)
            return low[:at] + i2v + low[at + len(t2v):]
    # Пары не нашлось — берём проверенный вариант, иначе луп гарантированно упадёт.
    log.warning("Для модели %s нет парной image-to-video, беру %s", model, DEFAULT_VIDEO_MODEL)
    return DEFAULT_VIDEO_MODEL


def is_image_model(model: str) -> bool:
    low = (model or "").lower()
    return any(m in low for m in ("image-to-video", "image2video", "img2video", "/i2v"))


DEFAULT_VIDEO_MODEL = "pixverse-v6/image-to-video"


def library_dir() -> Path:
    path = config.MEDIA_DIR / "_loops"
    path.mkdir(parents=True, exist_ok=True)
    return path


def motion_prompt(fmt: str) -> str:
    return f"{LOOP_MOTION.get(fmt, LOOP_MOTION['bust'])}. {LOOP_RULES}"


def for_format(session: Session, fmt: str) -> Optional[LoopClip]:
    """Готовый луп для формата, если он есть в библиотеке и файл на месте."""
    rows = session.execute(
        select(LoopClip).where(LoopClip.fmt == fmt, LoopClip.is_active.is_(True))
        .order_by(LoopClip.id.desc())
    ).scalars()
    for row in rows:
        if row.path and storage.abspath(row.path).exists():
            return row
    return None


def library(session: Session) -> list[LoopClip]:
    return list(session.execute(
        select(LoopClip).order_by(LoopClip.fmt, LoopClip.id.desc())).scalars())


def generate(session: Session, client: KieClient, fmt: str, *, topic: str, heading: str,
             image_model: str, video_model: str, thumb_style: str = "",
             seconds: int = LOOP_SECONDS, quality: str = LOOP_QUALITY) -> LoopClip:
    """Кадр -> зацикленный клип -> библиотека. Возвращает запись библиотеки.

    Ссылку на картинку берём ту, что вернул генератор: pixverse принимает
    image_urls по HTTP, и своего публичного хостинга для этого не нужно — это
    важно, потому что панель может стоять за туннелем и наружу не смотреть.
    """
    # Модель приводим к image-to-video ДО генерации кадра: если делать это после,
    # неподходящая модель обнаружится уже после оплаченной картинки.
    video_model = to_image_model(video_model)
    image_prompt = prompts.still_background(fmt, topic, heading, thumb_style)
    result = client.run_task(image_model, {
        "prompt": image_prompt, "aspect_ratio": "9:16",
        "resolution": "1K", "output_format": "png",
    }, timeout=900, poll=5)
    image_urls = extract_urls(result)
    if not image_urls:
        raise KieError(f"{image_model}: в ответе нет ссылки на изображение")
    credits = float(result.get("_credits") or 0)

    prompt = motion_prompt(fmt)
    payload = image_to_video_input(video_model, prompt=prompt, image_urls=image_urls[:1],
                                   resolution=quality, duration=seconds)
    clip = client.run_task(video_model, payload, timeout=1800, poll=8)
    video_urls = extract_urls(clip)
    if not video_urls:
        raise KieError(f"{video_model}: в ответе нет ссылки на видео")
    credits += float(clip.get("_credits") or 0)

    dest = library_dir() / f"loop_{fmt}{storage.guess_ext(video_urls[0], '.mp4')}"
    if dest.exists():
        dest.unlink()
    storage.download(video_urls[0], dest)
    duration = storage.media_duration(dest)
    if duration <= 0:
        dest.unlink(missing_ok=True)
        raise KieError(f"{video_model}: скачан пустой файл")

    poster = library_dir() / f"loop_{fmt}.jpg"
    try:
        from . import media
        media.frame_grab(dest, poster, at=min(1.0, duration * 0.2))
    except Exception as exc:  # noqa: BLE001 — без превью библиотека всё равно работает
        log.warning("Превью лупа %s не снято: %s", fmt, exc)
        poster = None

    width, height = _dimensions(dest)
    # Прежние клипы этого формата убираем из выдачи, но файлы не трогаем: их
    # мог кто-то скачать, а перезапись библиотеки — не повод удалять с диска.
    for old in session.execute(
            select(LoopClip).where(LoopClip.fmt == fmt, LoopClip.is_active.is_(True))
    ).scalars():
        old.is_active = False

    row = LoopClip(
        fmt=fmt, title=f"Луп «{fmt}»", path=storage.rel(dest),
        poster_path=storage.rel(poster) if poster else "",
        prompt=prompt[:2000], image_prompt=image_prompt[:2000], model=video_model,
        source_url=video_urls[0][:600], duration_sec=duration,
        width=width, height=height, file_size=dest.stat().st_size, credits=credits,
    )
    session.add(row)
    session.commit()
    log.info("Луп для формата %s готов: %.1f с, %dx%d", fmt, duration, width, height)
    return row


def _dimensions(path: Path) -> tuple[int, int]:
    try:
        out = storage.run_ff([config.FFPROBE, "-v", "error", "-select_streams", "v:0",
                              "-show_entries", "stream=width,height", "-of", "csv=p=0",
                              str(path)], timeout=60).strip()
        width, height = out.split(",")[:2]
        return int(width), int(height)
    except Exception:  # noqa: BLE001
        return 0, 0
