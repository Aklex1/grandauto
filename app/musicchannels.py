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

# Серии канала: заголовок и пояснение через тире. Заставка берётся случайная,
# и заголовок к ней — тоже: серии не привязаны к сценам, иначе «Snowfall» вечно
# шёл бы со снегом, а набор заставок обесценился бы.
DEFAULT_TITLES = (
    "Snowfall Sessions — soft winter mixes for sleep & study",
    "Aurora Drive — melodic night-drive chillstep",
    "Deep Focus — long sets for work and concentration",
    "24/7 Radio — chillstep, all night, every night",
)


def split_title(line: str) -> tuple[str, str]:
    """«Название — пояснение» в (название, пояснение). Тире может и не быть."""
    for dash in ("—", "–", " - "):
        if dash in line:
            head, tail = line.split(dash, 1)
            return head.strip(), tail.strip()
    return line.strip(), ""

# Канал, который просили завести. Создаётся один раз и только если его нет.
DEFAULT_CHANNEL = {"name": "LUMEN DRIFT", "slug": "lumen-drift",
                   "style": "chillstep", "minutes": 30,
                   "titles": "", "subtitle": "chillstep · night ambience · deep focus"}

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

    folder = BUNDLED / row.slug
    if not folder.is_dir():
        return row

    # Сверяем по именам, а не по факту «есть хоть что-то»: иначе канал, в
    # котором уже лежит один клип, никогда не получит новые из поставки. Берём
    # и снятые с выдачи — человек мог убрать клип намеренно, и возвращать его
    # при каждом запуске было бы навязчиво.
    known = {
        r.title for r in session.execute(
            select(MusicAsset).where(MusicAsset.channel_id == row.id,
                                     MusicAsset.source == "bundled")).scalars()
    }
    added = 0
    for path in sorted(folder.glob("*.*")):
        suffix = path.suffix.lower()
        if suffix not in (".mp4", ".mov", ".webm", ".mkv", ".png", ".webp", ".jpg"):
            continue
        if path.stem in known:
            continue
        stem = path.stem.lower()
        if suffix in (".png", ".webp", ".jpg"):
            kind = "logo"
        else:
            kind = "intro" if "intro" in stem else "outro" if "outro" in stem else "loop"
        try:
            add_file(session, row, kind, path, title=path.stem, source="bundled")
            added += 1
        except Exception as exc:  # noqa: BLE001 — один клип не ломает запуск
            log.warning("Клип %s не добавлен: %s", path.name, exc)
    if added:
        log.info("Канал %s: добавлено из поставки %s клипов", row.slug, added)

    # Чего в поставке больше нет, то убираем из выдачи: клип могли изъять
    # намеренно — скажем, потому что в нём вшита обвязка, которую завод теперь
    # накладывает сам, и она пошла бы вторым слоем. Файл на диске остаётся.
    present = {path.stem for path in folder.glob("*.*")}
    gone = 0
    for asset in session.execute(
            select(MusicAsset).where(MusicAsset.channel_id == row.id,
                                     MusicAsset.source == "bundled",
                                     MusicAsset.is_active.is_(True))).scalars():
        if asset.title not in present:
            asset.is_active = False
            gone += 1
    if gone:
        session.commit()
        log.info("Канал %s: убрано из выдачи %s клипов, которых больше нет в поставке",
                 row.slug, gone)
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


# Наборы, из которых завод рисует заставки канала. Жанр сцены к серии не
# привязан намеренно: иначе «Snowfall» вечно шёл бы со снегом, и набор
# обесценился бы до одной заставки на серию.
SCENE_PRESETS = ("aurora", "snowfall", "road", "planet", "deep", "ember", "snow")


def generate_loops(channel_id: int, count: int = 4, *, seconds: float = 30.0) -> None:
    """Дорисовать заставки канала. Бесплатно: рисует сервер, генераторы не нужны.

    Раньше здесь заказывались картинка и оживление у KIE. Для этого канала так
    не годится: визуальный язык плоский векторный, а фотогенератор отвечает на
    него фотореализмом с кривыми буквами — присланный клип это и показал. Плюс
    каждая заставка стоила денег, а теперь не стоит ничего.

    Каждая заставка — своя транзакция: сорвавшаяся не отменяет уже готовые.
    """
    import random as _random

    from . import backdrops
    from .db import session_scope
    from .models import Event

    done, failed = [], []
    for _ in range(max(1, min(10, count))):
        with session_scope() as session:
            channel = session.get(MusicChannel, channel_id)
            if channel is None:
                raise RuntimeError("канал не найден")
            have = len(assets(session, channel_id, "loop"))
            slug = channel.slug

        preset = SCENE_PRESETS[have % len(SCENE_PRESETS)]
        seed = _random.Random(f"{slug}:{have}").randrange(10 ** 6)
        tmp = config.TMP_DIR / f"scene_{slug}_{have}.mp4"
        try:
            backdrops.render(tmp, preset, seconds, seed)
            if storage.media_duration(tmp) <= 1:
                raise RuntimeError("сцена не нарисовалась")
            with session_scope() as session:
                channel = session.get(MusicChannel, channel_id)
                row = add_file(session, channel, "loop", tmp,
                               title=f"{preset} {have + 1}", source="drawn",
                               prompt=f"{preset}, seed {seed}")
                done.append(f"{row.title} ({row.duration_sec:.0f} с)")
        except Exception as exc:  # noqa: BLE001 — одна сцена не ломает набор
            log.warning("Заставка %s не нарисовалась: %s", preset, exc)
            failed.append(f"{preset}: {exc}")
        finally:
            tmp.unlink(missing_ok=True)

    with session_scope() as session:
        message = f"Заставки канала: нарисовано {len(done)}"
        if done:
            message += " — " + ", ".join(done)
        if failed:
            message += ". Не удалось: " + "; ".join(failed[:3])
        session.add(Event(level="warn" if failed else "info", stage="музыка",
                          message=message[:4000]))
        session.commit()
    if failed and not done:
        raise RuntimeError("; ".join(failed[:3]))


def titles_of(channel: MusicChannel) -> list[str]:
    rows = [line.strip() for line in (channel.titles or "").splitlines() if line.strip()]
    return rows or list(DEFAULT_TITLES)


def title_for(channel: MusicChannel, index: int) -> tuple[str, str]:
    """Серия по кругу: (название, пояснение)."""
    rows = titles_of(channel)
    return split_title(rows[index % len(rows)])


def pick_title(channel: MusicChannel) -> tuple[str, str]:
    """Случайная серия — для заголовка готового ролика."""
    return split_title(random.choice(titles_of(channel)))
