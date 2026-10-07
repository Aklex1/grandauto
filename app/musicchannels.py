"""Каналы музыкальных видео: постоянное оформление и библиотека клипов.

Зачем канал, когда есть вкладка «Музыка». Там выбираются жанр и длительность
под каждый трек — это удобно, пока треки разные. Канал решает другую задачу:
у него жанр один и оформление постоянное, поэтому создание трека сводится к
выбору длительности, а всё остальное канал знает сам.

Главное здесь — библиотека клипов. Интро, несколько зацикленных заставок и
оутро делаются ОДИН раз: загружаются готовыми или генерируются кнопкой. Дальше
каждый трек берёт случайную заставку из набора и обрамляется тем же интро и
оутро, и платить приходится только за музыку. Несколько заставок вместо одной —
чтобы лента не выглядела одним роликом, переклеенным сто раз.
"""
from __future__ import annotations

import logging
import random
from pathlib import Path
from typing import Optional

from sqlalchemy import select
from sqlalchemy.orm import Session

from . import config, media, storage
from .models import MusicAsset, MusicChannel, utcnow

log = logging.getLogger("cf.mchannel")

KINDS = {"intro": "интро", "loop": "заставка", "outro": "оутро",
         "logo": "логотип"}

# Заголовки для заставок по умолчанию: ровно те, что уже есть в оформлении
# канала. Их можно переписать в настройках канала.
DEFAULT_TITLES = ("Aurora Drive", "Deep Focus", "Snow Fall", "Night Rain",
                  "Close Your Eyes", "Drift Away")

# Канал, который просили завести. Создаётся один раз и только если его нет.
DEFAULT_CHANNEL = {"name": "LUMEN DRIFT", "slug": "lumen-drift",
                   "style": "chillstep", "minutes": 30,
                   "titles": "\n".join(("Aurora Drive", "Deep Focus", "Snow Fall",
                                        "Night Rain", "Close Your Eyes",
                                        "Drift Away")),
                   "subtitle": "chillstep · night ambience · deep focus"}

# Оттенки для набора заставок. Промпт жанра задаёт сцену, а это — чем один клип
# отличается от другого: время суток, погода, точка съёмки. С одинаковым
# описанием генератор выдаёт пять почти неотличимых клипов, и вся затея с
# набором теряет смысл.
BACKDROP_VARIANTS = (
    "at the blue hour just after sunset, cold light, deep shadows",
    "at dawn with low warm sun breaking through haze",
    "at night under a clear starfield, moonlight only",
    "in heavy drifting fog, almost monochrome, distant light sources",
    "on an overcast day, soft flat light, muted desaturated palette",
    "under a faint aurora, cold green and violet in the sky",
    "from a low vantage point close to the ground, wide sky above",
    "from a high distant vantage point, vast scale, tiny details below",
)


def library_dir(channel: MusicChannel) -> Path:
    path = config.MEDIA_DIR / "_mchannels" / channel.slug
    path.mkdir(parents=True, exist_ok=True)
    return path


def channels(session: Session, *, only_active: bool = True) -> list[MusicChannel]:
    query = select(MusicChannel).order_by(MusicChannel.position, MusicChannel.id)
    if only_active:
        query = query.where(MusicChannel.is_active.is_(True))
    return list(session.execute(query).scalars())


# Клипы оформления, которые едут вместе с кодом. Канал с ними работает сразу
# после обновления — загружать ничего не надо, а добавить своё можно поверх.
BUNDLED = Path(__file__).resolve().parent.parent / "assets"


def ensure_default(session: Session) -> Optional[MusicChannel]:
    """Завести канал по умолчанию и положить в него клипы из поставки.

    Вызывается при каждом запуске и ничего не делает, если всё уже на месте:
    канал заводится, только когда каналов нет совсем, а клипы кладутся, только
    когда у канала их нет. Так обновление завода не плодит дубликаты и не
    затирает то, что человек загрузил сам.
    """
    row = session.execute(
        select(MusicChannel).where(MusicChannel.slug == DEFAULT_CHANNEL["slug"])
    ).scalars().first()
    if row is None:
        if session.execute(select(MusicChannel.id).limit(1)).first():
            # Каналы есть, но другие — своего мнения о них не имеем.
            return None
        row = MusicChannel(**DEFAULT_CHANNEL)
        session.add(row)
        session.commit()
        log.info("Заведён канал музыкальных видео «%s»", row.name)

    if assets(session, row.id):
        return row
    folder = BUNDLED / row.slug
    if not folder.is_dir():
        return row
    for path in sorted(folder.glob("*.*")):
        suffix = path.suffix.lower()
        if suffix not in (".mp4", ".mov", ".webm", ".mkv", ".png", ".webp", ".jpg"):
            continue
        stem = path.stem.lower()
        if suffix in (".png", ".webp", ".jpg"):
            kind = "logo"
        else:
            kind = "intro" if "intro" in stem else "outro" if "outro" in stem else "loop"
        try:
            add_file(session, row, kind, path, title=path.stem, source="bundled")
        except Exception as exc:  # noqa: BLE001 — один клип не ломает запуск
            log.warning("Клип %s не добавлен: %s", path.name, exc)
    return row


def assets(session: Session, channel_id: int, kind: str = "") -> list[MusicAsset]:
    query = select(MusicAsset).where(MusicAsset.channel_id == channel_id,
                                     MusicAsset.is_active.is_(True))
    if kind:
        query = query.where(MusicAsset.kind == kind)
    return list(session.execute(query.order_by(MusicAsset.id)).scalars())


def one(session: Session, channel_id: int, kind: str) -> Optional[MusicAsset]:
    """Единственный клип такого рода — для интро и оутро."""
    rows = [row for row in assets(session, channel_id, kind)
            if row.path and storage.abspath(row.path).exists()]
    return rows[-1] if rows else None


def pick_loop(session: Session, channel_id: int) -> Optional[MusicAsset]:
    """Случайная заставка из набора канала.

    Случайная, но не совсем: выбираем среди наименее использованных. Чистый
    случай на пяти клипах легко даёт три одинаковых подряд, а так набор
    проходится по кругу, оставаясь непредсказуемым внутри круга.
    """
    rows = [row for row in assets(session, channel_id, "loop")
            if row.path and storage.abspath(row.path).exists()]
    if not rows:
        return None
    fewest = min(row.used_count for row in rows)
    choice = random.choice([row for row in rows if row.used_count == fewest])
    choice.used_count += 1
    session.commit()
    return choice


def add_file(session: Session, channel: MusicChannel, kind: str, src: Path, *,
             title: str = "", source: str = "uploaded", prompt: str = "",
             image_prompt: str = "", model: str = "",
             credits: float = 0.0) -> MusicAsset:
    """Положить клип в библиотеку канала. Файл копируется к себе.

    Копируем намеренно: загруженный файл лежит во временном каталоге, а клип
    нужен каждому следующему треку — ссылаться на чужое место значит однажды
    собрать ролик без заставки.
    """
    kind = kind if kind in KINDS else "loop"
    if kind == "logo":
        # Логотип — картинка, и мерить у него нечего.
        if src.suffix.lower() not in (".png", ".webp", ".jpg", ".jpeg"):
            raise ValueError("логотип нужен картинкой: png, webp или jpg")
        duration = 0.0
    else:
        duration = storage.media_duration(src)
        if duration <= 0.1:
            raise ValueError("это не видео или оно пустое")

    dest = library_dir(channel) / f"{kind}_{int(utcnow().timestamp() * 1000)}{src.suffix.lower()}"
    dest.write_bytes(src.read_bytes())

    poster: Optional[Path] = None
    if kind != "logo":
        poster = dest.with_suffix(".jpg")
        try:
            media.frame_grab(dest, poster, at=min(1.0, duration * 0.2))
        except Exception as exc:  # noqa: BLE001 — без превью библиотека работает
            log.warning("Превью клипа не снято: %s", exc)
            poster = None

    width, height = _dimensions(dest)
    # Интро и оутро у канала по одному: прежнее убираем из выдачи, файл не
    # трогаем — его мог использовать уже выложенный ролик.
    if kind in ("intro", "outro", "logo"):
        for old in assets(session, channel.id, kind):
            old.is_active = False

    row = MusicAsset(
        channel_id=channel.id, kind=kind, title=(title or src.stem)[:200],
        path=storage.rel(dest), poster_path=storage.rel(poster) if poster else "",
        prompt=prompt[:2000], image_prompt=image_prompt[:2000], model=model[:120],
        source=source, duration_sec=duration, width=width, height=height,
        file_size=dest.stat().st_size, credits=credits)
    session.add(row)
    session.commit()
    log.info("Канал %s: добавлен %s «%s» (%.1f с, %dx%d)",
             channel.slug, KINDS[kind], row.title, duration, width, height)
    return row


def drop_asset(session: Session, asset_id: int) -> None:
    row = session.get(MusicAsset, asset_id)
    if row is not None:
        # Файл остаётся на диске: он мог войти в уже выложенный ролик.
        row.is_active = False
        session.commit()


def _dimensions(path: Path) -> tuple[int, int]:
    try:
        out = storage.run_ff([config.FFPROBE, "-v", "error", "-select_streams", "v:0",
                              "-show_entries", "stream=width,height", "-of", "csv=p=0",
                              str(path)], timeout=60).strip()
        width, height = out.split(",")[:2]
        return int(width), int(height)
    except Exception:  # noqa: BLE001
        return 0, 0


def backdrop_prompts(style, count: int, *, skip: int = 0) -> list[tuple[str, str]]:
    """Описания картинки и движения для набора заставок: [(картинка, движение)]."""
    from . import musicvideo as mv

    out: list[tuple[str, str]] = []
    for index in range(count):
        variant = BACKDROP_VARIANTS[(skip + index) % len(BACKDROP_VARIANTS)]
        out.append((f"{style.image}, {variant}. {mv.IMAGE_RULES}",
                    mv.motion_prompt(style)))
    return out


def generate_loops(channel_id: int, count: int = 5) -> None:
    """Сгенерировать набор заставок канала. Делается один раз.

    Каждая заставка — своя транзакция: сорвавшийся клип не должен отменять уже
    сгенерированные и оплаченные.
    """
    from . import kie, musicvideo as mv
    from . import settings_store as st
    from .db import session_scope
    from .kie import KieClient, extract_urls
    from .models import Event

    done, failed = [], []
    for index in range(max(1, min(10, count))):
        with session_scope() as session:
            channel = session.get(MusicChannel, channel_id)
            if channel is None:
                raise RuntimeError("канал не найден")
            style = mv.style_of(channel.style)
            have = len(assets(session, channel_id, "loop"))
            prompt_image, prompt_motion = backdrop_prompts(style, 1, skip=have)[0]
            client = KieClient(api_key=st.get(session, "kie_api_key", "")
                               or config.KIE_API_KEY)
            image_model = st.get(session, "default_image_model", "nano-banana-2")
            video_model = st.get(session, "default_video_model", "")
            slug = channel.slug

        try:
            from . import loops as loops_mod

            video_model = loops_mod.to_image_model(video_model
                                                   or loops_mod.DEFAULT_VIDEO_MODEL)
            shot = client.run_task(image_model, kie.image_input_payload(
                image_model, prompt=prompt_image, aspect_ratio="16:9",
                resolution="2K", output_format="png"), timeout=900, poll=5)
            image_urls = extract_urls(shot)
            if not image_urls:
                raise RuntimeError(f"{image_model}: нет ссылки на изображение")
            credits = float(shot.get("_credits") or 0)

            clip = client.run_task(video_model, kie.image_to_video_input(
                video_model, prompt=prompt_motion, image_urls=image_urls[:1],
                resolution=mv.LOOP_QUALITY, duration=mv.LOOP_SECONDS),
                timeout=1800, poll=8)
            video_urls = extract_urls(clip)
            if not video_urls:
                raise RuntimeError(f"{video_model}: нет ссылки на видео")
            credits += float(clip.get("_credits") or 0)

            tmp = config.TMP_DIR / f"loop_{slug}_{index}" \
                                   f"{storage.guess_ext(video_urls[0], '.mp4')}"
            storage.download(video_urls[0], tmp)

            # Типографику наносим сами: буквы у генераторов картинок выходят
            # кривыми, и никакой промпт этого не лечит. Поэтому модель рисует
            # сцену без единой буквы, а логотип и заголовок ложатся сверху
            # ровно такими, какими задуманы.
            with session_scope() as session:
                channel = session.get(MusicChannel, channel_id)
                caption = title_for(channel, have)
                logo_row = one(session, channel_id, "logo")
                logo = storage.abspath(logo_row.path) if logo_row else None
                subtitle = channel.subtitle
            branded = config.TMP_DIR / f"branded_{slug}_{index}.mp4"
            try:
                media.brand_clip(tmp, branded, size=media.target_size("1080p", "16:9"),
                                 title=caption, subtitle=subtitle, logo=logo)
                ready = branded
            except Exception as exc:  # noqa: BLE001 — клип важнее надписи
                log.warning("Надпись на заставку не нанесена: %s", exc)
                ready = tmp

            with session_scope() as session:
                channel = session.get(MusicChannel, channel_id)
                row = add_file(session, channel, "loop", ready,
                               title=caption or f"Заставка {have + 1}",
                               source="generated", prompt=prompt_motion,
                               image_prompt=prompt_image, model=video_model,
                               credits=credits)
                done.append(f"{row.title} ({row.duration_sec:.0f} с)")
            tmp.unlink(missing_ok=True)
            branded.unlink(missing_ok=True)
        except Exception as exc:  # noqa: BLE001 — одна заставка не ломает набор
            log.warning("Заставка %s не сделана: %s", index + 1, exc)
            failed.append(str(exc))

    with session_scope() as session:
        message = f"Заставки канала: готово {len(done)}"
        if done:
            message += " — " + ", ".join(done)
        if failed:
            message += ". Не удалось: " + "; ".join(failed[:3])
        session.add(Event(level="warn" if failed else "info", stage="музыка",
                          message=message[:4000]))
        session.commit()
    if failed and not done:
        raise RuntimeError("; ".join(failed[:3]))


def title_for(channel: MusicChannel, index: int) -> str:
    """Заголовок очередной заставки из списка канала."""
    rows = [line.strip() for line in (channel.titles or "").splitlines() if line.strip()]
    if not rows:
        rows = list(DEFAULT_TITLES)
    return rows[index % len(rows)]
