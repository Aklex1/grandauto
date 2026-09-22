"""Готовые материалы из архива: папка на серию — ролик на выходе.

Архив приносит всё, кроме звука: обложку, тексты, описания для площадок. Это
переворачивает обычную экономику завода. Ничего генерировать не нужно — ни
сценарий, ни видеоряд, ни обложку, — поэтому API тратится только на голос и
фоновую музыку, а это копейки против генерации кадров.

Что делаем с обложкой. Она сделана как обложка: крупный текст на весь верх, и
поверх неё титры читаться не будут. Поэтому она показывается как есть первую
секунду — ровно тот момент, на который её и рисовали, — а потом плавно
притемняется и дальше работает фоном, по которому едут титры. Заодно кадр не
стоит мёртво: та же механика «живого кадра», что и в формате «бюст».
"""
from __future__ import annotations

import datetime as dt
import json
import logging
import re
import shutil
import zipfile
from pathlib import Path
from typing import Optional

from sqlalchemy import func, select
from sqlalchemy.orm import Session

from . import config, media, music, storage, subtitles, tts
from .db import session_scope
from .kie import KieClient
from .models import ArchiveBatch, ArchiveItem, Channel, Event, utcnow

log = logging.getLogger("cf.archives")

# Как поступать с обложкой.
COVER_MODES = {
    "uploaded": "Из архива — берём готовую, ничего не генерируем",
    "auto": "Из архива, а если её там нет — сгенерировать",
    "generate": "Всегда генерировать свою (платно)",
}

# Сколько обложка висит нетронутой в начале ролика и за сколько притемняется.
# Секунда — то, на что рассчитан сам макет: успеть прочитать заголовок.
COVER_HOLD = 1.0
COVER_FADE = 0.5
# Насколько гасим обложку под титры. Меньше — текст обложки лезет в глаза,
# больше — от картинки остаётся чёрный прямоугольник.
COVER_DIM = 0.42

# Хвост после последнего слова: под него доигрывает музыка.
TAIL_SECONDS = 1.2

# Файлы, которые мы понимаем внутри папки.
COVER_NAMES = ("cover.png", "cover.jpg", "cover.jpeg", "cover.webp")
META_NAME = "metadata.json"
COPY_NAME = "cover_copy.json"

# Голосовой профиль под язык: gemini-tts слушает описание голоса, и русское
# описание при английском тексте даёт заметный акцент.
VOICE_PROFILES = {
    "ru": "Спокойный уверенный голос рассказчика, русский язык",
    "en": "Calm confident male narrator, natural American English",
}


class ArchiveError(RuntimeError):
    pass


def _read_json(path: Path) -> dict:
    try:
        return json.loads(path.read_text(encoding="utf-8-sig"))
    except Exception as exc:  # noqa: BLE001 — папка без метаданных не должна ронять импорт
        log.warning("Не разобран %s: %s", path, exc)
        return {}


def _strip_hashtags(text: str) -> str:
    """Подпись для площадки годится под озвучку, но хэштеги вслух не читают."""
    lines = [ln for ln in (text or "").splitlines()
             if not ln.strip().startswith("#")]
    return "\n".join(lines).strip()


def narration_from(meta: dict, copy: dict) -> str:
    """Что диктор произносит.

    Берём описание для YouTube: оно написано связным текстом и уже начинается с
    того же крючка, что на обложке. Подпись для Instagram — запасной вариант, но
    из неё надо убрать хэштеги.
    """
    platforms = meta.get("platforms") or {}
    youtube = (platforms.get("youtube") or {}).get("description") or ""
    if youtube.strip():
        return youtube.strip()
    instagram = _strip_hashtags((platforms.get("instagram") or {}).get("caption") or "")
    if instagram:
        return instagram
    hook = (copy.get("hook") or "").strip()
    return hook


def title_from(meta: dict, copy: dict, folder: str) -> str:
    platforms = meta.get("platforms") or {}
    for key in ("youtube", "vk", "ok"):
        title = (platforms.get(key) or {}).get("title") or ""
        if title.strip():
            return title.strip()[:300]
    hook = (copy.get("hook") or "").strip()
    return (hook[:120] or f"Серия {folder}")[:300]


def hashtags_from(meta: dict) -> str:
    platforms = meta.get("platforms") or {}
    tags = (platforms.get("youtube") or {}).get("hashtags") or []
    if not tags:
        tags = (platforms.get("instagram") or {}).get("hashtags") or []
    return " ".join(str(t) for t in tags)[:500]


def read_folder(folder: Path) -> Optional[dict]:
    """Разбираем одну папку архива. None — если это не папка серии."""
    meta = _read_json(folder / META_NAME) if (folder / META_NAME).exists() else {}
    copy = _read_json(folder / COPY_NAME) if (folder / COPY_NAME).exists() else {}
    if not meta and not copy:
        return None

    cover = next((folder / name for name in COVER_NAMES if (folder / name).exists()), None)
    narration = narration_from(meta, copy)
    if not narration:
        return None

    language = (meta.get("language") or copy.get("language") or "en").strip()[:10]
    platforms = meta.get("platforms") or {}
    caption = (platforms.get("instagram") or {}).get("caption") or ""
    return {
        "folder": folder.name,
        "language": language,
        "title": title_from(meta, copy, folder.name),
        "narration": narration,
        "hook": (copy.get("hook") or "").strip(),
        "caption": caption.strip(),
        "hashtags": hashtags_from(meta),
        "cover": cover,
    }


def _folder_order(name: str) -> int:
    """Номер серии из имени папки: 001 → 1. Без номера — в конец."""
    digits = re.findall(r"\d+", name)
    return int(digits[0]) if digits else 10**6


def _series_dirs(root: Path) -> list[Path]:
    """Папки серий внутри распакованного архива.

    Архив часто упакован с одной внешней папкой (ENGLISH_100/001/…), а часто и
    без неё. Поэтому ищем не по глубине, а по содержимому: папка серии — та, где
    лежат метаданные.
    """
    found: list[Path] = []
    for path in sorted(root.rglob("*")):
        if not path.is_dir() or path.name.startswith("__"):
            continue
        if (path / META_NAME).exists() or (path / COPY_NAME).exists():
            found.append(path)
    return sorted(found, key=lambda p: (_folder_order(p.name), p.name))


def schedule_dates(count: int, per_day: int,
                   start: dt.date = None) -> list[Optional[dt.date]]:
    """Даты выпуска. per_day = 0 — без расписания, всё разом."""
    if per_day <= 0:
        return [None] * count
    start = start or dt.date.today()
    dates: list[Optional[dt.date]] = []
    for i in range(count):
        dates.append(start + dt.timedelta(days=i // per_day))
    return dates


def import_zip(session: Session, channel: Channel, data: bytes, *, name: str = "",
               cover_mode: str = "uploaded", per_day: int = 0) -> ArchiveBatch:
    """Распаковываем архив и заводим по ролику на папку."""
    if not data:
        raise ArchiveError("пустой файл")

    base = config.MEDIA_DIR / "_archives" / channel.slug
    base.mkdir(parents=True, exist_ok=True)
    stamp = utcnow().strftime("%Y%m%d_%H%M%S")
    title = storage.slugify(Path(name).stem, 60) or "archive"
    root = base / f"{title}_{stamp}"
    root.mkdir(parents=True, exist_ok=True)

    tmp = root / "_upload.zip"
    tmp.write_bytes(data)
    try:
        with zipfile.ZipFile(tmp) as zf:
            # Путь из архива никуда не деваем как есть: имя вроде ../../etc
            # распаковалось бы за пределы папки.
            for member in zf.infolist():
                target = (root / member.filename).resolve()
                if not str(target).startswith(str(root.resolve())):
                    raise ArchiveError(f"подозрительный путь в архиве: {member.filename}")
            zf.extractall(root)
    except zipfile.BadZipFile as exc:
        shutil.rmtree(root, ignore_errors=True)
        raise ArchiveError(f"это не zip-архив: {exc}") from exc
    finally:
        tmp.unlink(missing_ok=True)

    folders = _series_dirs(root)
    if not folders:
        shutil.rmtree(root, ignore_errors=True)
        raise ArchiveError("в архиве не нашлось ни одной папки серии — "
                           "внутри каждой ждём metadata.json или cover_copy.json")

    batch = ArchiveBatch(
        channel_id=channel.id, name=(name or root.name)[:200], path=storage.rel(root),
        cover_mode=cover_mode if cover_mode in COVER_MODES else "uploaded",
        per_day=max(0, min(50, per_day)))
    session.add(batch)
    session.commit()

    parsed = [(folder, read_folder(folder)) for folder in folders]
    good = [(folder, data_) for folder, data_ in parsed if data_]
    dates = schedule_dates(len(good), batch.per_day)

    for (folder, info), when in zip(good, dates):
        session.add(ArchiveItem(
            batch_id=batch.id, channel_id=channel.id, folder=info["folder"],
            idx=_folder_order(info["folder"]), title=info["title"],
            language=info["language"], narration=info["narration"],
            hook=info["hook"], caption=info["caption"], hashtags=info["hashtags"],
            cover_path=storage.rel(info["cover"]) if info["cover"] else "",
            scheduled_date=when))
    batch.total = len(good)
    session.commit()

    skipped = len(parsed) - len(good)
    session.add(Event(level="info", stage="archive",
                      message=f"Архив «{batch.name}»: принято серий {len(good)}"
                              + (f", пропущено {skipped} (нет текста)" if skipped else "")))
    session.commit()
    log.info("Архив %s: серий %s, пропущено %s", batch.name, len(good), skipped)
    return batch


# ------------------------------------------------------------------- сборка

def _voice_language(item: ArchiveItem) -> str:
    return (item.language or "en").split("-")[0].lower()


def _cues_for(item: ArchiveItem, audio: Path, duration: float) -> list[subtitles.Cue]:
    """Титры: слова из готового текста, тайминг из распознавания озвучки."""
    lang = _voice_language(item)
    raw: list[subtitles.Cue] = []
    if config.WHISPER_ENABLED:
        try:
            raw = subtitles.transcribe(audio, language=lang)
        except Exception as exc:  # noqa: BLE001 — без ASR разложим по длине текста
            log.warning("Whisper не сработал на серии %s: %s", item.folder, exc)

    text = item.narration
    if raw:
        # Провайдер мог оборвать длинный текст, аккуратно затухнув в конце файла.
        # Тогда титры надо раскладывать только по прозвучавшему, иначе они уедут
        # вперёд голоса.
        if not subtitles.tail_matches(raw, text):
            text = subtitles.trim_to_spoken(text, raw)
        return subtitles.align_script(raw, [(text, duration)])
    return subtitles.cues_from_scenes([(text, duration)])


def _shift_past_cover(cues: list[subtitles.Cue],
                      hold: float = COVER_HOLD) -> list[subtitles.Cue]:
    """Пока висит обложка, титры не показываем.

    Обложка — это крупный текст во весь верх кадра. Титры поверх неё превращают
    начало ролика в кашу, а озвучка при этом идёт с нуля и обрывать её нельзя.
    """
    out: list[subtitles.Cue] = []
    for cue in cues:
        if cue.end <= hold:
            continue
        out.append(subtitles.Cue(start=max(cue.start, hold), end=cue.end,
                                 text=cue.text))
    return out


def build_item(item_id: int) -> Path:
    """Собираем ролик одной серии. Возвращает путь к готовому файлу."""
    from . import pipeline

    with session_scope() as session:
        item = session.get(ArchiveItem, item_id)
        if item is None:
            raise ArchiveError(f"серия {item_id} не найдена")
        channel = session.get(Channel, item.channel_id)
        if channel is None:
            raise ArchiveError("канал серии не найден")
        batch = session.get(ArchiveBatch, item.batch_id)
        item.status = "running"
        item.error = ""
        session.commit()

        client = pipeline.client_for(session)
        size = media.target_size(channel.resolution, "9:16")
        workdir = config.MEDIA_DIR / "_archives" / channel.slug / "work" / f"{item.id:06d}"
        shutil.rmtree(workdir, ignore_errors=True)
        workdir.mkdir(parents=True, exist_ok=True)
        out_dir = config.MEDIA_DIR / channel.slug / "_archive"
        out_dir.mkdir(parents=True, exist_ok=True)

        try:
            # 1. Голос. Текст уже на нужном языке — переводить нечего.
            lang = _voice_language(item)
            audio = workdir / "voice.m4a"
            spoken = tts.synthesize(
                client, item.narration, audio,
                model=channel.tts_model, voice_id=channel.voice_id,
                stability=channel.voice_stability, similarity=channel.voice_similarity,
                speed=channel.voice_speed,
                voice_profile=VOICE_PROFILES.get(lang, VOICE_PROFILES["en"]))
            duration = spoken.duration or storage.media_duration(spoken.path)
            if duration <= 0:
                raise ArchiveError("озвучка не получилась")
            item.credits = float(spoken.credits or 0.0)

            # 2. Фон. Обложка из архива — бесплатно; генерация только если просят.
            cover = _cover_for(session, client, channel, batch, item, workdir)

            # 3. Ролик: обложка секунду как есть, дальше притемнённая под титры.
            total = duration + TAIL_SECONDS
            raw = workdir / "raw.mp4"
            media.build_still_scene(
                cover, spoken.path, raw, size, total, workdir,
                motion=channel_motion(channel), dim=COVER_DIM,
                dim_start=COVER_HOLD, dim_span=COVER_FADE)

            # 4. Титры поверх, но не поверх обложки.
            cues = _shift_past_cover(_cues_for(item, spoken.path, duration))
            with_subs = raw
            if channel.burn_subtitles and cues:
                ass = workdir / "subs.ass"
                subtitles.write_ass(cues, ass, size=size, vertical=True,
                                    style=channel.subtitle_style or "shorts")
                with_subs = workdir / "subs.mp4"
                media.burn_subtitles(raw, ass, with_subs)

            # 5. Музыка — вторая и последняя статья расхода API.
            final_src = with_subs
            if channel.background_music:
                track = music.ensure_track(session, client, channel.id,
                                           channel.topic or channel.name,
                                           hint=channel.music_style or "")
                if track is not None:
                    mixed = workdir / "mixed.mp4"
                    media.mix_background_music(
                        with_subs, storage.abspath(track.path), mixed,
                        music_db=channel.music_volume_db, fade_out=TAIL_SECONDS)
                    final_src = mixed

            final = out_dir / f"{item.idx:03d}_{storage.slugify(item.title, 50) or 'reel'}.mp4"
            shutil.copyfile(final_src, final)

            item.video_path = storage.rel(final)
            item.duration_sec = storage.media_duration(final)
            item.status = "done"
            item.finished_at = utcnow()
            item.error = ""
            session.commit()
            session.add(Event(level="info", stage="archive",
                              message=f"Серия {item.folder} собрана: "
                                      f"{item.duration_sec:.0f} с, {item.title[:60]}"))
            session.commit()
            shutil.rmtree(workdir, ignore_errors=True)
            return final
        except Exception as exc:  # noqa: BLE001 — причина нужна в панели
            item.status = "failed"
            item.error = str(exc)[:2000]
            item.finished_at = utcnow()
            session.commit()
            session.add(Event(level="error", stage="archive",
                              message=f"Серия {item.folder} не собралась: {str(exc)[:200]}"))
            session.commit()
            raise


def channel_motion(channel: Channel) -> str:
    """Каким движением оживлять обложку. Пресеты общие с форматом «бюст»."""
    preset = (channel.visual_style or "").strip().lower()
    return preset if preset in media.MOTION_PRESETS else "bust"


def _cover_for(session: Session, client: KieClient, channel: Channel,
               batch: Optional[ArchiveBatch], item: ArchiveItem, workdir: Path) -> Path:
    """Фоновая картинка серии: из архива или сгенерированная."""
    from . import pipeline

    mode = (batch.cover_mode if batch else "uploaded") or "uploaded"
    ready = storage.abspath(item.cover_path) if item.cover_path else None
    if mode != "generate" and ready is not None and ready.exists():
        return ready
    if mode == "uploaded":
        raise ArchiveError("в папке серии нет обложки, а генерировать запрещено "
                           "настройкой архива")

    # Своя обложка: описываем кадр по крючку — текст на ней рисовать не надо,
    # он и так прозвучит и появится титрами.
    prompt = (f"Cinematic vertical 9:16 photograph illustrating: "
              f"{item.hook or item.title}. Moody natural light, shallow depth of "
              f"field, no text, no logos, no watermarks.")
    result = pipeline._image_task(session, client, channel, prompt, "cover")
    from .kie import extract_urls

    urls = extract_urls(result)
    if not urls:
        raise ArchiveError("генератор не вернул обложку")
    dest = workdir / f"cover{storage.guess_ext(urls[0], '.png')}"
    storage.download(urls[0], dest)
    item.credits = float(item.credits or 0.0) + float(result.get("_credits") or 0.0)
    session.commit()
    return dest


# --------------------------------------------------------------- расписание

def due_items(session: Session, today: dt.date = None) -> list[ArchiveItem]:
    """Серии, которым пора собираться."""
    today = today or dt.date.today()
    rows = session.execute(
        select(ArchiveItem).where(ArchiveItem.status == "planned")
        .order_by(ArchiveItem.idx, ArchiveItem.id)).scalars().all()
    return [r for r in rows
            if r.scheduled_date is None or r.scheduled_date <= today]


def run_due(limit: int = 50) -> int:
    """Ставит в очередь всё, чему пришёл срок. Возвращает число серий."""
    from . import queue as queue_mod

    sent = 0
    with session_scope() as session:
        for item in due_items(session)[:limit]:
            item.status = "queued"
            session.commit()
            queue_mod.enqueue(session, "archive_item",
                              payload={"item_id": item.id})
            sent += 1
    if sent:
        log.info("Архивных серий отправлено в сборку: %s", sent)
    return sent


def queue_items(session: Session, items: list[ArchiveItem]) -> int:
    """Поставить выбранные серии в очередь вручную."""
    from . import queue as queue_mod

    sent = 0
    for item in items:
        if item.status in ("queued", "running"):
            continue
        item.status = "queued"
        item.error = ""
        session.commit()
        queue_mod.enqueue(session, "archive_item", payload={"item_id": item.id})
        sent += 1
    return sent


def batch_stats(session: Session, batch_id: int) -> dict:
    rows = session.execute(
        select(ArchiveItem.status, func.count(ArchiveItem.id))
        .where(ArchiveItem.batch_id == batch_id)
        .group_by(ArchiveItem.status)).all()
    return {status: count for status, count in rows}
