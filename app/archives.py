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

import hashlib

from . import config, media, music, storage, subtitles, tts
from . import settings_store as st
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

# Насколько кадр наезжает к концу ролика. Наезд начинается только после того,
# как обложка отработала: до этого она должна стоять ровно как нарисована.
COVER_ZOOM = 1.08

# Хвост после последнего слова: под него доигрывает музыка.
TAIL_SECONDS = 1.2

# Насколько короче ожидаемого может быть сохранённая озвучка, чтобы ей ещё
# верить. Ниже — провайдер оборвал текст, и такой файл нельзя подставлять при
# пересборке: иначе брак закрепится навсегда.
VOICE_MIN_RATIO = 0.75

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


def resolve(batch: Optional[ArchiveBatch], channel: Channel) -> dict:
    """Настройки сборки: своё у архива, иначе как у канала.

    Пустая строка и ноль в архиве значат «не переопределяю»: архив не обязан
    описывать всё подряд, а канал остаётся общим знаменателем. Один словарь на
    всю сборку удобнее десятка `batch.x or channel.x` по коду — иначе правило
    «пусто значит как у канала» пришлось бы помнить в каждой строке.
    """
    def pick(name: str, fallback):
        """Пусто и ноль — «как у канала»: у голоса и громкости ноль смысла не имеет."""
        value = getattr(batch, name, None) if batch is not None else None
        if value in (None, "", 0, 0.0):
            return fallback
        return value

    def number(name: str, fallback: float, low: float, high: float) -> float:
        """То же для чисел, у которых ноль — законное значение.

        Притемнение 0 означает «не гасить вовсе», и подменять его значением по
        умолчанию нельзя: тогда выключить притемнение было бы нечем. За «не
        задано» здесь отвечает выход за границы, а не ноль.
        """
        value = getattr(batch, name, None) if batch is not None else None
        if value is None or not (low <= float(value) <= high):
            return fallback
        return float(value)

    def mode(name: str, fallback: bool) -> bool:
        value = (getattr(batch, name, "") or "") if batch is not None else ""
        if value == "on":
            return True
        if value == "off":
            return False
        return fallback

    outro_on = mode("outro_mode", bool(channel.outro_enabled))
    return {
        "cover_mode": (batch.cover_mode if batch else "uploaded") or "uploaded",
        "cover_dim": number("cover_dim", COVER_DIM, 0.0, 0.85),
        "cover_hold": number("cover_hold", COVER_HOLD, 0.0, 6.0),
        "cover_zoom": number("cover_zoom", COVER_ZOOM, 1.0, 1.4),
        "tail_sec": number("tail_sec", TAIL_SECONDS, 0.0, 8.0),
        "subtitles": mode("subtitles_mode", bool(channel.burn_subtitles)),
        "subtitle_style": pick("subtitle_style", channel.subtitle_style or "shorts"),
        "title_font": pick("title_font", channel.title_font or ""),
        "music": mode("music_mode", bool(channel.background_music)),
        "music_volume_db": float(pick("music_volume_db", channel.music_volume_db or -20.0)),
        "tts_model": pick("tts_model", channel.tts_model),
        "voice_id": pick("voice_id", channel.voice_id),
        "voice_name": pick("voice_name", channel.voice_name),
        "voice_speed": float(pick("voice_speed", channel.voice_speed or 1.0)),
        "outro": outro_on,
        "outro_url": pick("outro_url", channel.outro_url or ""),
        "outro_title": pick("outro_title", channel.outro_title or channel.name or ""),
        "outro_about": pick("outro_about", channel.outro_about or ""),
        "outro_source": pick("outro_source", channel.outro_source or "builtin"),
        "outro_text": pick("outro_text", channel.outro_text or ""),
        # Пусто — Georgia из пакета и выравнивание из его же раскладки.
        "caption_font": caption_font_choice(pick("caption_font", "")),
        "caption_align": pick("caption_align", ""),
    }


def voice_cache_path(channel: Channel, item: ArchiveItem,
                     setup: Optional[dict] = None) -> Path:
    """Куда кладём озвучку серии, чтобы не платить за неё дважды.

    Имя считается от текста и голоса: тот же текст тем же голосом звучит
    одинаково, и переозвучивать его при повторной загрузке архива или при
    пересборке — выброшенные деньги. Поменяется текст или голос — поменяется и
    имя, старый файл просто не найдётся.
    """
    setup = setup or resolve(None, channel)
    key = "|".join([
        (item.narration or "").strip(),
        setup["tts_model"] or "", setup["voice_id"] or "",
        f"{setup['voice_speed']:.2f}", f"{channel.voice_stability:.2f}",
        f"{channel.voice_similarity:.2f}",
    ])
    digest = hashlib.sha1(key.encode("utf-8")).hexdigest()[:20]
    folder = config.MEDIA_DIR / "_archives" / (channel.slug or "channel") / "voices"
    folder.mkdir(parents=True, exist_ok=True)
    return folder / f"{digest}.m4a"


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


# --- пакет mens_circle.production.v2 -----------------------------------------
# Второй формат архива. Отличается принципиально: речь лежит отдельным файлом и
# является канонической (её нельзя брать из описания для площадки), кадры уже
# нарисованы и разложены по сценам, а генерировать при импорте запрещено вообще
# что-либо. Поэтому это не «ещё один разбор папки», а другая ветка сборки.
MANIFEST_NAME = "production_import.json"
PACKAGE_V2 = "mens_circle.production.v2"
PRESET_STORY = "story_v2"
PRESET_LEGACY = "legacy"

PRESETS = {
    PRESET_LEGACY: "Обложка фоном под титрами — первый формат архива",
    PRESET_STORY: "Сюжетные кадры из пакета production.v2",
}

# Сколько обложка держится в пакете v2: контракт пакета, не наша настройка.
# Кадр 01 лежит под ней с нулевой секунды, речь идёт непрерывно.
V2_COVER_OPAQUE_MS = 820
V2_COVER_GONE_MS = 1000
V2_SCENE_FADE_MS = 180


def read_manifest(root: Path) -> Optional[dict]:
    """Корневой манифест пакета v2. None — если это архив прежнего формата."""
    for candidate in sorted(root.rglob(MANIFEST_NAME)):
        data = _read_json(candidate)
        if str(data.get("packageFormat") or "").strip() == PACKAGE_V2:
            data["_root"] = candidate.parent
            return data
    return None


def _script_name(language: str) -> str:
    return f"script_{(language or 'en').split('-')[0].lower()}.txt"


def _clean_script(path: Path) -> str:
    """Канонический текст речи — байт в байт, только с нормализацией UTF-8.

    Ни сокращать, ни дополнять нельзя: озвучивается ровно он, и по нему же потом
    сверяется распознавание.
    """
    text = path.read_text(encoding="utf-8-sig")
    # Переводы строк внутри абзаца речи не значат ничего, но двойной перевод —
    # это пауза между абзацами, и её мы сохраняем.
    return "\n".join(line.strip() for line in text.splitlines()).strip()


def _scenes_from(storyboard: dict, folder: Path, tokens: list[dict]) -> list[dict]:
    """Сцены серии: их собственные ID, кадр и сколько токенов речи приходится.

    ID берём из поля `scene` и не трогаем: в пакете оно своё у каждой серии и
    бывает нулевым (у 001 сцены 0–3), а перенумерация ломает связь с токенами
    титров. Токены считаем по раскладке титров — это точная мера речи; если
    раскладки нет, считаем слова в beats.
    """
    by_scene: dict = {}
    for token in tokens:
        key = token.get("scene")
        by_scene[key] = by_scene.get(key, 0) + 1

    scenes: list[dict] = []
    raw = storyboard.get("scenes") or storyboard.get("items") or []
    for index, scene in enumerate(raw):
        if not isinstance(scene, dict):
            continue
        asset = (scene.get("asset") or scene.get("image") or scene.get("background")
                 or scene.get("assetPath") or "")
        if isinstance(asset, dict):
            asset = asset.get("path") or asset.get("file") or ""
        scene_id = scene.get("scene", scene.get("id", scene.get("sceneId", index)))
        words = 0
        for beat in scene.get("beats") or []:
            text = (beat.get("text") if isinstance(beat, dict) else str(beat)) or ""
            words += len(text.split())
        scenes.append({
            "id": scene_id,
            "asset": str(asset),
            "tokens": by_scene.get(scene_id, 0) or words or 1,
            "exists": bool(asset) and (folder / str(asset)).exists(),
        })
    return scenes


def _captions_from(layout: dict) -> dict:
    """Готовая раскладка титров: зона, кегль и страницы с разбитыми строками.

    Переносы в пакете посчитаны по метрикам Georgia под ширину зоны. Считать их
    заново своими правилами — значит получить другие строки, а вместе с ними
    другое число строк на странице и другую высоту блока.
    """
    tokens = layout.get("tokens") or []
    if not tokens:
        return {}
    order = {str(t.get("id")): i for i, t in enumerate(tokens)}
    display = {str(t.get("id")): str(t.get("display") or t.get("spoken") or "")
               for t in tokens}

    pages: list[dict] = []
    for page in layout.get("pages") or []:
        lines: list[str] = []
        indexes: list[int] = []
        for line in page.get("lines") or []:
            words = [display.get(str(tid), "") for tid in line]
            indexes += [order[str(tid)] for tid in line if str(tid) in order]
            text = " ".join(w for w in words if w).strip()
            if text:
                lines.append(text)
        if not lines or not indexes:
            continue
        pages.append({"lines": lines, "first": min(indexes), "last": max(indexes),
                      "scene": page.get("scene")})
    if not pages:
        return {}
    return {
        "zone": layout.get("zone") or {},
        "font_px": float(layout.get("fontSizePx") or 64),
        "line_px": float(layout.get("lineHeightPx") or 88),
        "canvas_h": 1920.0,
        "total": len(tokens),
        "mode": str(layout.get("mode") or ""),
        "pages": pages,
    }


def read_reel_v2(folder: Path, language: str) -> Optional[dict]:
    """Одна папка `reels/NNN` пакета v2."""
    meta = _read_json(folder / META_NAME) if (folder / META_NAME).exists() else {}
    lang = (meta.get("language") or language or "en").strip()[:10]
    script = folder / _script_name(lang)
    if not script.exists():
        # Язык в манифесте и язык файла могут разойтись — ищем любой script_*.txt,
        # но молча подменять канонический файл описанием площадки нельзя.
        found = sorted(folder.glob("script_*.txt"))
        if not found:
            return None
        script = found[0]
        lang = script.stem.split("_", 1)[-1][:10]

    narration = _clean_script(script)
    if not narration:
        return None

    pub = _read_json(folder / "publication_metadata.json")
    copy = _read_json(folder / COPY_NAME) if (folder / COPY_NAME).exists() else {}
    storyboard = _read_json(folder / "storyboard.json")
    layout_path = folder / "captions.layout.json"
    layout = _read_json(layout_path) if layout_path.exists() else {}
    captions = _captions_from(layout)
    scenes = _scenes_from(storyboard, folder, layout.get("tokens") or [])
    montage_path = folder / "montage.json"
    montage = _read_json(montage_path) if montage_path.exists() else {}
    cover_plan = montage.get("cover") or {}
    raw_canvas = montage.get("canvas") or {}
    canvas = [int(raw_canvas.get("width") or 1080), int(raw_canvas.get("height") or 1920)]

    # fontFile в раскладке указан относительно папки серии: ../../shared/Georgia.ttf
    font_dir = None
    font_ref = str(layout.get("fontFile") or "")
    if font_ref:
        candidate = (folder / font_ref).resolve()
        if candidate.exists():
            font_dir = candidate.parent
    if font_dir is None:
        for up in (folder.parent.parent, folder.parent, folder):
            shared = up / "shared"
            if (shared / "Georgia.ttf").exists():
                font_dir = shared
                break
    cover = next((folder / name for name in COVER_NAMES if (folder / name).exists()), None)

    platforms = pub.get("platforms") or pub
    caption = (platforms.get("instagram") or {}).get("caption") or ""
    return {
        "folder": folder.name,
        "language": lang,
        "title": title_from({"platforms": platforms}, copy, folder.name),
        "narration": narration,
        "hook": (copy.get("hook") or "").strip(),
        "caption": caption.strip(),
        "hashtags": hashtags_from({"platforms": platforms}),
        "cover": cover,
        "scenes": scenes,
        "captions": captions,
        # Тайминг обложки и переходов задаёт сам пакет, а не наши константы:
        # у 001–002 переход 700 мс, у остальных 180.
        "cover_hold_ms": float(cover_plan.get("holdUntilMs") or V2_COVER_OPAQUE_MS),
        "cover_gone_ms": float(cover_plan.get("clearAtMs") or V2_COVER_GONE_MS),
        "scene_fade_ms": float(montage.get("sceneTransitionMs") or V2_SCENE_FADE_MS),
        # Холст задаёт пакет: 1080×1920. Брать разрешение канала нельзя — вся
        # раскладка титров посчитана под эти пиксели, и 720p её ужимает.
        "canvas": canvas,
        # Шрифт приложен к пакету. Без него libass подставит свой, буквы станут
        # шире, готовые строки перестанут помещаться в зону и libass переверстает
        # их сам — отсюда и «слова прыгают на другую строку».
        "font_dir": str(font_dir) if font_dir else "",
        "dir": folder,
    }


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

    # Формат определяем ДО разбора: у пакета v2 своя речь, свои кадры и запрет на
    # любую генерацию, и подсовывать ему логику прежнего архива нельзя.
    manifest = read_manifest(root)
    if manifest is not None:
        reel_root = manifest["_root"]
        lang = str(manifest.get("language") or "en")[:10]
        folders = sorted((reel_root / "reels").glob("*"),
                         key=lambda p: (_folder_order(p.name), p.name)) \
            if (reel_root / "reels").is_dir() else _series_dirs(root)
        folders = [f for f in folders if f.is_dir()]
        parsed = [(f, read_reel_v2(f, lang)) for f in folders]
        preset, package = PRESET_STORY, PACKAGE_V2
    else:
        lang = ""
        folders = _series_dirs(root)
        parsed = [(f, read_folder(f)) for f in folders]
        preset, package = PRESET_LEGACY, ""

    good = [(folder, data_) for folder, data_ in parsed if data_]
    if not good:
        shutil.rmtree(root, ignore_errors=True)
        raise ArchiveError(
            "в архиве не нашлось ни одной папки серии — ждём либо пакет "
            f"{PACKAGE_V2} с {MANIFEST_NAME} и reels/NNN/script_*.txt, либо "
            "прежний формат с metadata.json и cover_copy.json в каждой папке")

    batch = ArchiveBatch(
        channel_id=channel.id, name=(name or root.name)[:200], path=storage.rel(root),
        cover_mode=cover_mode if cover_mode in COVER_MODES else "uploaded",
        per_day=max(0, min(50, per_day)), preset=preset, package_format=package,
        language=lang)
    if preset == PRESET_STORY:
        # Пакет запрещает подменять свои материалы — это ставим сразу, чтобы
        # человеку не пришлось помнить про каждый запрет.
        batch.cover_mode = "uploaded"
        batch.outro_mode = "off"
        batch.subtitle_style = "story"
        # Музыку спецификация пакета тоже запрещает, но это решение владельца
        # канала, а не свойство материалов: фон не подменяет ни речь, ни кадры.
        # Поэтому оставляем как у канала — выключить можно тут же в настройках.
        batch.music_mode = ""
    session.add(batch)
    session.commit()

    dates = schedule_dates(len(good), batch.per_day)
    for (folder, info), when in zip(good, dates):
        session.add(ArchiveItem(
            batch_id=batch.id, channel_id=channel.id, folder=info["folder"],
            idx=_folder_order(info["folder"]), title=info["title"],
            language=info["language"], narration=info["narration"],
            hook=info["hook"], caption=info["caption"], hashtags=info["hashtags"],
            cover_path=storage.rel(info["cover"]) if info["cover"] else "",
            source_dir=storage.rel(info.get("dir") or folder),
            scenes_json=json.dumps({
                "scenes": info.get("scenes") or [],
                "cover_hold_ms": info.get("cover_hold_ms"),
                "cover_gone_ms": info.get("cover_gone_ms"),
                "scene_fade_ms": info.get("scene_fade_ms"),
                "canvas": info.get("canvas"),
                "font_dir": info.get("font_dir"),
            } if info.get("scenes") else [], ensure_ascii=False),
            captions_json=json.dumps(info.get("captions") or {}, ensure_ascii=False),
            scheduled_date=when))
    batch.total = len(good)
    session.commit()

    skipped = len(parsed) - len(good)
    session.add(Event(level="info", stage="archive",
                      message=f"Архив «{batch.name}» ({PRESETS.get(preset, preset)}): "
                              f"принято серий {len(good)}"
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


def build_item(item_id: int) -> Optional[Path]:
    """Собираем ролик одной серии.

    Возвращает путь к готовому файлу или None, если серии уже нет.
    """
    from . import pipeline

    with session_scope() as session:
        item = session.get(ArchiveItem, item_id)
        if item is None:
            # Архив убрали, пока его серии стояли в очереди. Это не поломка:
            # человек так и останавливал сборку, и сыпать красными задачами на
            # каждую оставшуюся серию незачем.
            log.info("Серия %s уже удалена — задание пропускаю", item_id)
            return None
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

        setup = resolve(batch, channel)
        try:
            # 1. Голос. Текст уже на нужном языке — переводить нечего.
            lang = _voice_language(item)
            cached = voice_cache_path(channel, item, setup)
            voice: Path
            reuse = _usable_voice(cached, item.narration, setup["voice_speed"])
            if reuse is not None:
                # Тот же текст тем же голосом уже озвучен: при повторной загрузке
                # архива и при пересборке платить второй раз незачем.
                voice, duration = cached, reuse
                item.credits = 0.0
                log.info("Серия %s: озвучка взята с диска (%.1f с)", item.folder, duration)
            else:
                spoken = tts.synthesize(
                    client, item.narration, workdir / "voice.m4a",
                    model=setup["tts_model"], voice_id=setup["voice_id"],
                    stability=channel.voice_stability, similarity=channel.voice_similarity,
                    speed=setup["voice_speed"],
                    voice_profile=VOICE_PROFILES.get(lang, VOICE_PROFILES["en"]))
                duration = spoken.duration or storage.media_duration(spoken.path)
                if duration <= 0:
                    raise ArchiveError("озвучка не получилась")
                shutil.copyfile(spoken.path, cached)
                voice = cached
                item.credits = float(spoken.credits or 0.0)

            # 2. Фон. Обложка из архива — бесплатно; генерация только если просят.
            # Пакет v2 генерацию запрещает прямо, поэтому там её не спрашиваем.
            if batch is not None and batch.preset == PRESET_STORY:
                cover = storage.abspath(item.cover_path) if item.cover_path else None
            else:
                cover = _cover_for(session, client, channel, batch, item, workdir)

            # 3. Видеоряд. У пакета v2 кадры уже нарисованы и разложены по
            # сценам — там обложка уходит к секунде и дальше идёт сюжет; у
            # прежнего формата единственная картинка и есть фон.
            story = (batch is not None and batch.preset == PRESET_STORY)
            _, plan = _scene_plan(item)
            if story:
                # Холст задаёт пакет: вся раскладка титров посчитана под него.
                canvas = plan.get("canvas") or []
                if len(canvas) == 2 and canvas[0] > 0 and canvas[1] > 0:
                    size = (int(canvas[0]), int(canvas[1]))
                hold = float(plan.get("cover_gone_ms") or V2_COVER_GONE_MS) / 1000.0
            else:
                hold = setup["cover_hold"]

            cues = _cues_for(item, voice, duration)
            marks = _token_marks(item, cues, duration) if story else None
            if story:
                raw = _build_story_v2(session, item, setup, voice, duration, size,
                                      workdir, marks=marks)
            else:
                total = duration + setup["tail_sec"]
                raw = workdir / "raw.mp4"
                media.build_cover_scene(
                    cover, voice, raw, size, total, workdir,
                    hold=setup["cover_hold"], fade=COVER_FADE, dim=setup["cover_dim"],
                    zoom_end=setup["cover_zoom"])

            # 4. Титры поверх, но не поверх обложки.
            cues = _shift_past_cover(cues, hold=hold)
            with_subs = raw
            pages = _story_pages(item, cues, duration, hold=hold if story else 0.0,
                                 marks=marks)
            if setup["subtitles"] and pages:
                # У пакета строки уже разбиты — выводим как есть, своей вёрстки
                # не навязываем.
                ass = workdir / "subs.ass"
                layout = json.loads(item.captions_json or "{}")
                canvas = plan.get("canvas") or [1080, 1920]
                subtitles.write_story_pages(
                    pages, ass, size=size, zone=layout.get("zone") or {},
                    font_px=float(layout.get("font_px") or 64),
                    canvas_h=float(canvas[1] or 1920),
                    canvas_w=float(canvas[0] or 1080),
                    align=setup["caption_align"],
                    font=setup["caption_font"] or _story_font())
                with_subs = workdir / "subs.mp4"
                media.burn_subtitles(raw, ass, with_subs,
                                     fontsdir=_caption_fonts_dir(setup, plan))
            elif setup["subtitles"] and cues:
                ass = workdir / "subs.ass"
                subtitles.write_ass(cues, ass, size=size, vertical=True,
                                    style=setup["subtitle_style"])
                with_subs = workdir / "subs.mp4"
                media.burn_subtitles(raw, ass, with_subs)

            # 4б. Рекламная концовка: та же, что у шортсов, — озвученный призыв,
            # название канала и ссылка поверх последнего кадра.
            with_subs = _append_outro(session, client, channel, item, setup,
                                      with_subs, size, workdir)

            # 5. Музыка — вторая и последняя статья расхода API.
            final_src = with_subs
            if setup["music"]:
                # Именно pick_track, а не ensure_track: тот держит один трек на
                # канал (так нужно длинному ролику со сквозной музыкой), а сотня
                # шортсов с одинаковым фоном сливается в ленте.
                track = music.pick_track(session, client, channel.id,
                                         channel.topic or channel.name,
                                         style_hint=channel.music_style or "",
                                         target=st.get_int(session,
                                                           "music_library_target", 5))
                if track is not None:
                    mixed = workdir / "mixed.mp4"
                    media.mix_background_music(
                        with_subs, storage.abspath(track.path), mixed,
                        music_db=setup["music_volume_db"],
                        fade_out=setup["tail_sec"])
                    final_src = mixed

            # 6. Общий уровень громкости: провайдер отдаёт разную громкость от
            # запроса к запросу, и в ленте из ста роликов это слышно.
            leveled = workdir / "leveled.mp4"
            try:
                media.normalize_loudness(final_src, leveled)
                final_src = leveled
            except Exception as exc:  # noqa: BLE001 — ролик важнее выравнивания
                log.warning("Серия %s: громкость не выровнена: %s", item.folder, exc)

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


def _outro_lines(setup: dict) -> list[str]:
    """Реплики концовки. Разные, чтобы сотня серий не звучала одинаково."""
    from . import pipeline

    title = (setup["outro_title"] or "").strip()
    mine = pipeline._split_variants(setup["outro_text"] or "")
    if setup["outro_source"] == "custom" and mine:
        return mine
    # Источник «модель» здесь не зовём: платить за реплику на каждую из сотни
    # серий незачем. Если её уже составили в шортсах, она лежит готовой — берём.
    if mine:
        return mine
    return [text.format(title=title) for text in pipeline.OUTRO_FALLBACK]


def _outro_voice(client, text: str, channel: Channel, setup: dict,
                 lang: str, workdir: Path) -> Optional[tuple[Path, float, float]]:
    """Озвучка призыва. Кэшируется так же, как и основная: реплик всего несколько."""
    key = "|".join([text.strip(), setup["tts_model"] or "", setup["voice_id"] or "",
                    f"{setup['voice_speed']:.2f}"])
    digest = hashlib.sha1(key.encode("utf-8")).hexdigest()[:20]
    folder = config.MEDIA_DIR / "_archives" / (channel.slug or "channel") / "voices"
    folder.mkdir(parents=True, exist_ok=True)
    cached = folder / f"outro_{digest}.m4a"
    if cached.exists() and storage.media_duration(cached) > 0.3:
        return cached, storage.media_duration(cached), 0.0
    try:
        result = tts.synthesize(
            client, text, workdir / "outro_voice.m4a",
            model=setup["tts_model"], voice_id=setup["voice_id"],
            stability=channel.voice_stability, similarity=channel.voice_similarity,
            speed=setup["voice_speed"],
            voice_profile=VOICE_PROFILES.get(lang, VOICE_PROFILES["en"]))
    except Exception as exc:  # noqa: BLE001 — без озвучки концовка всё равно нужна
        log.warning("Концовка не озвучена: %s", exc)
        return None
    shutil.copyfile(result.path, cached)
    return cached, result.duration, float(result.credits or 0.0)


def _append_outro(session: Session, client, channel: Channel, item: ArchiveItem,
                  setup: dict, video: Path, size: tuple[int, int],
                  workdir: Path) -> Path:
    """Приклеиваем концовку к ролику. Без неё ролик остаётся как был."""
    from . import fonts, pipeline

    if not setup["outro"]:
        return video
    link = (setup["outro_url"] or "").strip()
    title = (setup["outro_title"] or "").strip()
    if not link and not title:
        return video

    try:
        lines = _outro_lines(setup)
        # Реплику выбираем по номеру серии: у соседних роликов концовка разная.
        text = lines[item.idx % len(lines)] if lines else ""
        spoken = _outro_voice(client, text, channel, setup,
                              _voice_language(item), workdir) if text else None
        if spoken is not None:
            voice, spoken_sec, credits = spoken
            item.credits = float(item.credits or 0.0) + credits
            duration = media.OUTRO_LEAD_IN + spoken_sec + pipeline.OUTRO_PAD
        else:
            voice, duration = None, pipeline.OUTRO_SILENT_SECONDS

        # Последний кадр ролика уходит фоном концовки, чтобы она не выглядела
        # приклеенной из другого видео.
        frame = workdir / "outro_bg.jpg"
        media.frame_grab(video, frame, at=max(0.0, storage.media_duration(video) - 0.3))
        tail = workdir / "outro.mp4"
        media.build_outro(tail, size, duration, voice, title, link,
                          fonts.font_path(setup["title_font"]) or "", workdir,
                          background=frame if frame.exists() else None)
        joined = workdir / "joined.mp4"
        media.concat_scenes([video, tail], joined, workdir / "join")
        return joined
    except Exception as exc:  # noqa: BLE001 — ролик важнее концовки
        log.warning("Серия %s: концовка не добавлена: %s", item.folder, exc)
        session.add(Event(level="warn", stage="archive",
                          message=f"Серия {item.folder}: концовка не добавлена ({exc})"))
        session.commit()
        return video


def _scene_plan(item: ArchiveItem) -> tuple[list, dict]:
    """Сцены серии и тайминги пакета.

    Первые импорты клали сюда голый список сцен, потом к нему добавились времена
    обложки и переходов. Читаем оба вида, чтобы старые серии не пришлось
    переимпортировать.
    """
    try:
        raw = json.loads(item.scenes_json or "[]")
    except ValueError:
        return [], {}
    if isinstance(raw, list):
        return raw, {}
    return raw.get("scenes") or [], raw


def scene_frames(item: ArchiveItem, duration: float,
                 marks: Optional[list] = None,
                 total_span: Optional[float] = None) -> list[tuple[Path, float]]:
    """Кадры серии с длительностями, разложенными по речи.

    Сцены живут на той же шкале, что и титры: у каждой известно, сколько токенов
    речи она занимает, а `marks` говорит, когда каждый токен звучит. Поэтому
    кадр меняется ровно на первом слове своей сцены.

    Раньше время делилось пропорционально на всю длину ролика вместе с хвостом
    после речи — и каждая следующая сцена отставала всё сильнее: к концу
    полуминутного ролика набегала секунда. Хвост речи не содержит, поэтому он
    достаётся последнему кадру целиком.
    """
    scenes, _ = _scene_plan(item)
    base = storage.abspath(item.source_dir) if item.source_dir else None
    usable: list[dict] = []
    for scene in scenes:
        asset = str(scene.get("asset") or "")
        if not asset or base is None:
            continue
        path = base / asset
        if path.exists():
            weight = scene.get("tokens") or scene.get("words") or 1
            usable.append({"path": path, "weight": max(1, int(weight))})
    if not usable:
        return []

    span = total_span if total_span is not None else duration
    total_weight = sum(s["weight"] for s in usable)

    # Начало каждой сцены — момент её первого слова.
    starts: list[float] = []
    if marks:
        index = 0
        for scene in usable:
            at = marks[min(index, len(marks) - 1)]
            starts.append(float(at))
            index += scene["weight"]
    else:
        at = 0.0
        for scene in usable:
            starts.append(at)
            at += duration * scene["weight"] / total_weight
    starts[0] = 0.0

    frames: list[tuple[Path, float]] = []
    for index, scene in enumerate(usable):
        # Последнему кадру достаётся и хвост после речи: там слов уже нет.
        end = starts[index + 1] if index + 1 < len(starts) else span
        frames.append((scene["path"], max(end - starts[index], 0.4)))
    return frames


def _usable_voice(path: Path, text: str, speed: float) -> Optional[float]:
    """Годится ли сохранённая озвучка. Возвращает её длину или None.

    Кэш экономит деньги, но он же умеет закреплять брак: если провайдер оборвал
    текст на полуслове, обрезанный файл будет подставляться при каждой
    пересборке, и ролик никогда не починится. Поэтому перед тем как взять
    готовое, сверяем длину с ожидаемой по тексту: короче трёх четвертей — значит
    озвучено не всё, и надо просить заново.
    """
    if not path.exists():
        return None
    duration = storage.media_duration(path)
    if duration <= 0.5:
        return None
    expected = tts.expected_seconds(text or "", speed or 1.0)
    if expected > 0 and duration < expected * VOICE_MIN_RATIO:
        log.warning("Озвучка в кэше короче текста (%.1f с при ожидаемых %.1f) — "
                    "озвучиваю заново", duration, expected)
        path.unlink(missing_ok=True)
        return None
    return duration


def _story_font() -> str:
    """Имя шрифта титров по умолчанию. Файл берётся из пакета через fontsdir."""
    return "Georgia"


def caption_font_choice(key: str) -> str:
    """Название семейства для ASS по выбору в панели. Пусто — Georgia пакета."""
    from . import fonts

    if not key:
        return ""
    return fonts.font_family(key)


def _caption_fonts_dir(setup: dict, plan: dict) -> Optional[Path]:
    """Где лежит файл шрифта титров.

    libass ищет шрифты через fontconfig и файлы вне системных каталогов сам не
    находит: ни Georgia из пакета, ни скачанные шрифты завода. Поэтому каталог
    передаём явно — свой у каждого случая.
    """
    from . import fonts

    if setup.get("caption_font"):
        return Path(fonts.FONTS_DIR)
    font_dir = plan.get("font_dir") or ""
    if font_dir and Path(font_dir).exists():
        return Path(font_dir)
    return None


def _token_marks(item: ArchiveItem, cues: list, duration: float) -> list:
    """Когда звучит каждый токен серии. Общая шкала для кадров и титров."""
    try:
        layout = json.loads(item.captions_json or "{}")
    except ValueError:
        return []
    total = int(layout.get("total") or 0)
    if total <= 0:
        # Раскладки нет — считаем по словам самого текста.
        total = len((item.narration or "").split())
    if total <= 0:
        return []
    return subtitles.token_times(cues, total, duration)


def _story_pages(item: ArchiveItem, cues: list, duration: float,
                 hold: float = 0.0, marks: Optional[list] = None) -> list[dict]:
    """Страницы титров пакета со временем показа.

    Страницы приходят готовыми, а времени у них нет — оно появляется только
    после озвучки. Токены разложены по речи, поэтому страница берёт время своего
    первого и последнего токена.
    """
    try:
        layout = json.loads(item.captions_json or "{}")
    except ValueError:
        return []
    pages = layout.get("pages") or []
    total = int(layout.get("total") or 0)
    if not pages or total <= 0:
        return []

    marks = marks or subtitles.token_times(cues, total, duration)
    if not marks:
        return []
    out: list[dict] = []
    for page in pages:
        first = max(0, min(int(page.get("first") or 0), total - 1))
        last = max(first, min(int(page.get("last") or first), total - 1))
        start = marks[first]
        end = marks[min(last + 1, total)]
        # Пока висит обложка, титров не видно — но страницу крючка пропускать
        # нельзя: контракт пакета прямо требует показать её остаток после ухода
        # обложки, а не потерять вместе с её началом.
        if start < hold:
            start = hold
        # Время каждого слова страницы — из него собирается набор текста.
        words = [max(marks[i], start) for i in range(first, min(last + 1, total))]
        out.append({"start": start, "end": max(end, start + 0.4),
                    "lines": page.get("lines") or [], "words": words})
    return [p for p in out if p["end"] > p["start"] + 0.05]


def _build_story_v2(session: Session, item: ArchiveItem, setup: dict,
                    voice: Path, duration: float, size: tuple[int, int],
                    workdir: Path, marks: Optional[list] = None) -> Path:
    """Видеоряд пакета v2: обложка секунду, дальше сюжетные кадры по сценам."""
    total = duration + setup["tail_sec"]
    frames = scene_frames(item, duration, marks=marks, total_span=total)
    if not frames:
        raise ArchiveError("в серии нет ни одного сюжетного кадра — проверьте "
                           "storyboard.json и папку assets")
    cover = storage.abspath(item.cover_path) if item.cover_path else None
    _, plan = _scene_plan(item)
    raw = workdir / "raw.mp4"
    media.build_story_scene(
        frames, cover if cover and cover.exists() else None, voice, raw, size,
        total, workdir,
        cover_opaque=float(plan.get("cover_hold_ms") or V2_COVER_OPAQUE_MS) / 1000.0,
        cover_gone=float(plan.get("cover_gone_ms") or V2_COVER_GONE_MS) / 1000.0,
        fade=float(plan.get("scene_fade_ms") or V2_SCENE_FADE_MS) / 1000.0)
    log.info("Серия %s: собрана из %s сюжетных кадров", item.folder, len(frames))
    return raw


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
    """Серии, которым пора собираться. Архивы на паузе пропускаем."""
    today = today or dt.date.today()
    paused = {b.id for b in session.execute(
        select(ArchiveBatch).where(ArchiveBatch.paused.is_(True))).scalars()}
    rows = session.execute(
        select(ArchiveItem).where(ArchiveItem.status == "planned")
        .order_by(ArchiveItem.idx, ArchiveItem.id)).scalars().all()
    return [r for r in rows
            if r.batch_id not in paused
            and (r.scheduled_date is None or r.scheduled_date <= today)]


def stop_batch(session: Session, batch_id: int) -> tuple[int, int]:
    """Остановить архив: снять очередь и вернуть серии в план.

    Задачу, которая уже считается прямо сейчас, не обрываем — она доделает свою
    серию и остановится сама. Обрывать её на середине смысла нет: озвучка за неё
    уже оплачена.
    """
    from .models import Job

    batch = session.get(ArchiveBatch, batch_id)
    if batch is None:
        return 0, 0
    batch.paused = True
    rows = session.execute(
        select(ArchiveItem).where(ArchiveItem.batch_id == batch_id,
                                  ArchiveItem.status == "queued")).scalars().all()
    ids = {r.id for r in rows}
    jobs = 0
    for job in session.execute(
            select(Job).where(Job.kind == "archive_item",
                              Job.status == "pending")).scalars():
        try:
            payload = json.loads(job.payload or "{}")
        except ValueError:
            continue
        if int(payload.get("item_id") or 0) in ids:
            job.status = "cancelled"
            jobs += 1
    for row in rows:
        row.status = "planned"
    session.commit()
    log.info("Архив %s остановлен: снято задач %s, серий в план %s",
             batch_id, jobs, len(rows))
    return jobs, len(rows)


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
