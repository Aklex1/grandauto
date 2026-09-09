"""Конвейер производства ролика: сценарий → озвучка → видеоряд → субтитры → сборка → метаданные."""
from __future__ import annotations

import json
import logging
import shutil
import threading
import traceback
from concurrent.futures import ThreadPoolExecutor
from pathlib import Path
from typing import Callable, Optional

from sqlalchemy import select
from sqlalchemy.orm import Session

from . import config, fonts, footage, media, music, prompts, storage, subtitles, tts
from .db import session_scope
from .kie import KieClient, KieError, extract_urls, video_input
from .models import (Bridge as BridgeModel, Channel, Event, Footage as FootageModel, PlanItem,
                     Scene, Short, Video, utcnow)
from . import settings_store as st

log = logging.getLogger("cf.pipeline")

# Мастер-ролик со вшитыми субтитрами лежит в video.mp4, чистая версия — рядом.
CLEAN_NAME = "video_clean.mp4"

STAGE_PROGRESS = {
    "script": 10,
    "voice": 35,
    "visuals": 65,
    "assemble": 80,
    "subtitles": 88,
    "metadata": 90,
    "thumbnail": 93,
    "scenes_ready": 95,
    "done": 100,
}


class PipelineCancelled(RuntimeError):
    """Пользователь отменил сборку из интерфейса."""


class AlreadyBuilding(RuntimeError):
    """Ролик уже собирается другим воркером."""


# Один ролик собирается строго одним воркером: параллельные сборки дублируют
# запросы к платным API и портят выходные файлы.
_build_locks_guard = threading.Lock()
_build_locks: dict[int, threading.Lock] = {}


def _video_lock(video_id: int) -> threading.Lock:
    with _build_locks_guard:
        lock = _build_locks.get(video_id)
        if lock is None:
            lock = threading.Lock()
            _build_locks[video_id] = lock
        return lock


def log_event(session: Session, video_id: Optional[int], message: str, *,
              stage: str = "", level: str = "info") -> None:
    session.add(Event(video_id=video_id, stage=stage, level=level, message=message[:4000]))
    session.commit()


def client_for(session: Session) -> KieClient:
    key = st.get(session, "kie_api_key", "") or config.KIE_API_KEY
    return KieClient(api_key=key)


def _set_stage(session: Session, video: Video, stage: str, message: str = "") -> None:
    video.stage = stage
    video.status = stage if stage in ("done", "failed") else stage
    video.progress = STAGE_PROGRESS.get(stage, video.progress)
    video.updated_at = utcnow()
    session.commit()
    if message:
        log_event(session, video.id, message, stage=stage)


def _check_cancelled(session: Session, video_id: int) -> None:
    session.expire_all()
    current = session.get(Video, video_id)
    if current is not None and current.status == "cancelled":
        raise PipelineCancelled("сборка отменена пользователем")


# --------------------------------------------------------------------------- этапы

def generate_script(session: Session, client: KieClient, video: Video, channel: Channel) -> float:
    _set_stage(session, video, "script", f"Пишу сценарий по книге «{video.book_title}»")
    plan_item = session.get(PlanItem, video.plan_item_id) if video.plan_item_id else None
    key_points = ""
    angle = ""
    if plan_item:
        angle = plan_item.angle or ""
        key_points = plan_item.key_points or ""

    messages = prompts.script(
        channel_name=channel.name, topic=channel.topic, book_title=video.book_title,
        book_author=video.book_author, angle=angle, key_points=key_points,
        scene_count=channel.scene_count, target_minutes=channel.target_minutes,
        script_style=channel.script_style, visual_style=channel.visual_style,
    )
    data, credits = client.chat_json(channel.chat_model, messages, temperature=0.8)
    scenes = data.get("scenes") or []
    if not scenes:
        raise RuntimeError("модель вернула сценарий без сцен")

    video.title = (data.get("title") or video.title or video.book_title)[:300]
    video.hook = data.get("hook") or ""
    video.script = "\n\n".join((s.get("narration") or "").strip() for s in scenes)

    for scene in list(video.scenes):
        session.delete(scene)
    session.flush()

    # Чередование форматов шортсов: полный видеоряд достаётся каждой третьей сцене,
    # остальные собираются из одного кадра. Смещение по номеру ролика — чтобы у
    # соседних роликов канала рисунок чередования не совпадал.
    rotate = bool(getattr(channel, "rotate_formats", True))
    for idx, scene in enumerate(scenes):
        fmt = FORMAT_CYCLE[(idx + video.id) % len(FORMAT_CYCLE)] if rotate else "full"
        session.add(Scene(
            video_id=video.id, idx=idx,
            heading=(scene.get("heading") or f"Сцена {idx + 1}")[:300],
            narration=(scene.get("narration") or "").strip(),
            visual_prompt=(scene.get("visual_prompt") or "").strip(),
            short_format=fmt,
        ))
    session.commit()

    # Вторая сеть на случай, если модель закрыла JSON, но текст оборвала: сцена,
    # не заканчивающаяся знаком конца предложения, почти наверняка обрезана.
    cut = [i + 1 for i, sc in enumerate(scenes)
           if not _looks_complete((sc.get("narration") or "").strip())]
    if cut:
        log_event(session, video.id,
                  f"Текст обрывается на полуслове в сценах: {', '.join(map(str, cut))}. "
                  f"Поправьте текст в карточке сцены и пересоберите её",
                  stage="script", level="warn")

    log_event(session, video.id, f"Сценарий готов: {len(scenes)} сцен, "
                                 f"{len(video.script)} символов", stage="script")
    return credits


SENTENCE_END = ".!?…\"»)"


def _looks_complete(text: str) -> bool:
    """Закончен ли текст сцены. Обрыв по лимиту модели виден по последнему символу."""
    text = (text or "").strip()
    if len(text) < 8:          # ниже этого сцены не бывает — считаем пустой
        return False
    return text[-1] in SENTENCE_END


# Русская речь диктора — примерно 15 символов в секунду. Точность тут не нужна:
# порог служит только для того, чтобы заметить обрыв, а не измерить темп.
CHARS_PER_SECOND = 15.0


def _check_audio_length(session: Session, video: Video, scene_idx: int, text: str,
                        duration: float) -> None:
    """Предупреждаем, если озвучка заметно короче текста — значит её обрезало."""
    expected = len((text or "").strip()) / CHARS_PER_SECOND
    if expected < 3 or duration <= 0:
        return
    if duration < expected * 0.7:
        log_event(session, video.id,
                  f"Сцена {scene_idx + 1}: озвучка {duration:.0f} с при тексте на "
                  f"~{expected:.0f} с — часть текста не озвучена, субтитры разъедутся",
                  stage="voice", level="warn")


def voice_scenes(session: Session, client: KieClient, video: Video, channel: Channel,
                 out_dir: Path) -> float:
    _set_stage(session, video, "voice", "Озвучиваю сцены")
    allow_fallback = st.get_bool(session, "tts_allow_fallback", True)
    fallback_model = st.get(session, "tts_fallback_model", "google/gemini-3-1-flash-tts")
    fallback_voice = st.get(session, "tts_fallback_voice", "Charon")
    concurrency = max(1, st.get_int(session, "scene_concurrency", 3))

    # В потоки отдаём только простые данные: ORM-сессия не потокобезопасна.
    todo = [(s.id, s.idx, s.narration) for s in video.scenes if not _scene_audio_ok(s)]
    if not todo:
        return 0.0
    audio_dir = out_dir / "audio"
    audio_dir.mkdir(parents=True, exist_ok=True)
    voice_settings = {
        "model": channel.tts_model, "voice_id": channel.voice_id,
        "stability": channel.voice_stability, "similarity": channel.voice_similarity,
        "speed": channel.voice_speed, "fallback_model": fallback_model,
        "fallback_voice": fallback_voice, "allow_fallback": allow_fallback,
    }

    def work(item: tuple[int, int, str]):
        scene_id, idx, narration = item
        try:
            result = tts.synthesize(client, narration, audio_dir / f"scene_{idx:02d}",
                                    **voice_settings)
            return scene_id, result, ""
        except Exception as exc:  # noqa: BLE001
            log.warning("Сцена %s не озвучена: %s", idx, exc)
            return scene_id, None, str(exc)[:500]

    credits = 0.0
    done = 0
    with ThreadPoolExecutor(max_workers=concurrency) as pool:
        for scene_id, result, error in pool.map(work, todo):
            scene = session.get(Scene, scene_id)
            if result is None:
                scene.status = "voice_failed"
                scene.error = error
            else:
                scene.audio_path = storage.rel(result.path)
                scene.audio_sec = result.duration
                scene.status = "voiced"
                scene.error = ""
                credits += result.credits
                done += 1
                _check_audio_length(session, video, scene.idx, scene.narration,
                                    result.duration)
            session.commit()

    if done == 0:
        raise RuntimeError("не удалось озвучить ни одной сцены")
    total = sum(s.audio_sec for s in video.scenes)
    log_event(session, video.id,
              f"Озвучка готова: {done} из {len(todo)} сцен, {total / 60:.1f} мин", stage="voice")
    return credits


def generate_visuals(session: Session, client: KieClient, video: Video, channel: Channel,
                     out_dir: Path) -> float:
    _set_stage(session, video, "visuals", "Готовлю видеоряд")
    concurrency = max(1, st.get_int(session, "scene_concurrency", 3))
    clips_dir = out_dir / "clips"
    clips_dir.mkdir(parents=True, exist_ok=True)
    resolution = channel.resolution if channel.resolution in ("480p", "720p") else "720p"
    size = media.target_size(channel.resolution, channel.aspect_ratio)

    scenes = list(video.scenes)

    coverage = max(6, int(getattr(channel, "clip_coverage_sec", 20) or 20))

    # Сколько уникальных клипов нужно каждой сцене, чтобы кадр не «залипал».
    scenes = [sc for sc in scenes if not _scene_clips_ok(sc)]
    if not scenes:
        return 0.0
    scene_ids = [sc.id for sc in scenes]

    # Часть кадров можно не генерировать, а нарезать из загруженной библиотеки —
    # это бесплатно и добавляет живого материала.
    library = footage.available(session, channel.id)
    picker = footage.SegmentPicker(library, seed=video.id)
    source_mode = getattr(channel, "visual_source", "generate") or "generate"
    share = int(getattr(channel, "library_share", 50) or 0)
    if source_mode != "generate" and not library:
        log_event(session, video.id,
                  "Библиотека футажей пуста — весь видеоряд будет сгенерирован",
                  stage="visuals", level="warn")

    tasks: list[tuple[int, int, str, str]] = []
    for scene in scenes:
        base_prompt = scene.visual_prompt or scene.heading or video.title
        count = media.clips_needed(scene.audio_sec or coverage, coverage,
                                   int(getattr(channel, "max_clips_per_scene", 8) or 8))
        plan = footage.plan_sources(count, source_mode, share, bool(library))
        for part in range(count):
            prompt = base_prompt if part == 0 else f"{base_prompt}. Alternative angle {part + 1}"
            tasks.append((scene.id, part, prompt, plan[part]))

    # Нарезку из библиотеки делаем заранее в один поток: это локальный ffmpeg,
    # параллелить его вместе с сетевыми запросами смысла нет.
    library_clips: dict[tuple[int, int], Path] = {}
    for scene in scenes:
        hint = f"{scene.heading} {scene.visual_prompt}"
        for scene_id, part, _prompt, origin in tasks:
            if origin != "library" or scene_id != scene.id:
                continue
            chosen = picker.pick(float(channel.clip_duration), hint)
            if chosen is None:
                continue
            item, start, end = chosen
            dest = clips_dir / f"scene_{scene_id:04d}_{part:02d}_lib.mp4"
            try:
                footage.cut_segment(storage.abspath(item.path), dest, start, end - start, size)
                library_clips[(scene_id, part)] = dest
                row = session.get(FootageModel, item.id)
                if row is not None:
                    row.used_count += 1
            except Exception as exc:  # noqa: BLE001 — не смогли вырезать, сгенерируем
                log.warning("Фрагмент из библиотеки не вырезан: %s", exc)
    session.commit()
    if library_clips:
        log_event(session, video.id,
                  f"Из библиотеки нарезано фрагментов: {len(library_clips)}", stage="visuals")

    def work(task: tuple[int, int, str, str]):
        scene_id, part, prompt, origin = task
        ready = library_clips.get((scene_id, part))
        if ready is not None:
            return scene_id, part, ready, 0.0, "", "library"
        payload = video_input(channel.video_model, prompt=prompt,
                              aspect_ratio=channel.aspect_ratio, resolution=resolution,
                              duration=int(channel.clip_duration))
        try:
            result = client.run_task(channel.video_model, payload, timeout=1800, poll=6)
            urls = extract_urls(result)
            video_url = next((u for u in urls if u.split("?")[0].lower().endswith(
                (".mp4", ".mov", ".webm"))), None) or (urls[0] if urls else None)
            if not video_url:
                raise KieError("в ответе нет ссылки на видео")
            dest = clips_dir / f"scene_{scene_id:04d}_{part:02d}{storage.guess_ext(video_url, '.mp4')}"
            storage.download(video_url, dest)
            return scene_id, part, dest, float(result.get("_credits") or 0), "", "generated"
        except Exception as exc:  # noqa: BLE001 — одна сцена не должна ронять весь ролик
            log.warning("Клип %s/%s не сгенерирован: %s", scene_id, part, exc)
            return scene_id, part, None, 0.0, str(exc)[:500], "generated"

    expected: dict[int, int] = {}
    for scene_id, _part, _prompt, _origin in tasks:
        expected[scene_id] = expected.get(scene_id, 0) + 1

    credits = 0.0
    # part -> путь/происхождение: держим по номеру части, чтобы порядок кадров
    # в сцене совпадал с порядком генерации, а не с порядком завершения задач.
    by_scene: dict[int, dict[int, Path]] = {}
    origins: dict[int, dict[int, str]] = {}
    errors: dict[int, str] = {}
    seen: dict[int, int] = {}
    ok = 0

    def flush(scene_id: int) -> int:
        """Записываем сцену в БД, как только готовы все её клипы — чтобы прогресс был виден."""
        row = session.get(Scene, scene_id)
        parts = sorted(by_scene.get(scene_id, {}))
        paths = [by_scene[scene_id][part] for part in parts]
        if paths:
            row.clip_path = storage.rel(paths[0])
            row.clip_paths = json.dumps([storage.rel(p) for p in paths], ensure_ascii=False)
            row.clip_sources = json.dumps(
                [origins.get(scene_id, {}).get(part, "generated") for part in parts],
                ensure_ascii=False)
            row.clip_sec = sum(storage.media_duration(p) for p in paths)
            row.status = "ready"
            row.error = ""
            session.commit()
            return 1
        row.status = "clip_failed"
        row.error = errors.get(scene_id, "клип не сгенерирован")
        session.commit()
        return 0

    with ThreadPoolExecutor(max_workers=concurrency) as pool:
        for scene_id, part, path, cost, error, origin in pool.map(work, tasks):
            credits += cost
            if path is not None:
                by_scene.setdefault(scene_id, {})[part] = path
                origins.setdefault(scene_id, {})[part] = origin
            elif error:
                errors[scene_id] = error
            seen[scene_id] = seen.get(scene_id, 0) + 1
            if seen[scene_id] >= expected[scene_id]:
                ok += flush(scene_id)

    for scene_id in scene_ids:
        if seen.get(scene_id, 0) < expected.get(scene_id, 0):
            ok += flush(scene_id)

    if ok == 0:
        raise RuntimeError("не удалось сгенерировать ни одного видеоклипа")
    generated = sum(1 for d in origins.values() for o in d.values() if o == "generated")
    from_library = sum(1 for d in origins.values() for o in d.values() if o == "library")
    log_event(session, video.id,
              f"Видеоряд готов: {len(tasks)} клипов для {ok} из {len(scenes)} сцен "
              f"(сгенерировано {generated}, из библиотеки {from_library})",
              stage="visuals")
    return credits


def _scene_audio_ok(scene: Scene) -> bool:
    """Озвучка считается готовой, только если файл реально лежит на диске."""
    return bool(scene.audio_path) and storage.abspath(scene.audio_path).exists()


def _scene_clips_ok(scene: Scene) -> bool:
    paths = _scene_clips(scene)
    return bool(paths) and all(storage.abspath(p).exists() for p in paths)


def _scene_clips(scene: Scene) -> list[str]:
    """Все клипы сцены: новый формат — список в clip_paths, старый — одиночный clip_path."""
    raw = getattr(scene, "clip_paths", "") or ""
    if raw:
        try:
            paths = json.loads(raw)
            if isinstance(paths, list) and paths:
                return [str(p) for p in paths]
        except ValueError:
            pass
    return [scene.clip_path] if scene.clip_path else []


def _fallback_clip_for(scene: Scene, scenes: list[Scene]) -> Optional[Path]:
    """Если у сцены нет клипа — берём ближайший удачный."""
    ready = [s for s in scenes if s.clip_path]
    if not ready:
        return None
    nearest = min(ready, key=lambda s: abs(s.idx - scene.idx))
    return storage.abspath(nearest.clip_path)


def build_scene_pieces(session: Session, video: Video, channel: Channel, workdir: Path,
                       out_dir: Path) -> int:
    """Собираем каждую сцену в самостоятельный ролик с озвучкой и субтитрами.

    Длинный ролик не склеивается автоматически: в интерфейсе можно отметить нужные
    сцены и собрать из них итоговый ролик в любом составе и порядке.
    """
    _set_stage(session, video, "assemble", "Собираю сцены по отдельности")
    size = media.target_size(channel.resolution, channel.aspect_ratio)
    pieces_dir = out_dir / "scenes"
    pieces_dir.mkdir(parents=True, exist_ok=True)
    scenes = list(video.scenes)
    ready = 0

    # Трек нужен уже здесь: версия сцены с музыкой кладётся рядом с чистой.
    track_path = None
    if channel.background_music:
        track = _background_track(session, video, channel)
        if track is not None and track.path:
            candidate = storage.abspath(track.path)
            track_path = candidate if candidate.exists() else None

    for scene in scenes:
        if not scene.audio_path:
            continue
        if scene.piece_path and storage.abspath(scene.piece_path).exists():
            ready += 1
            continue
        ok, _cues = assemble_scene_piece(session, video, channel, scene, workdir,
                                         pieces_dir, track_path, size, fallback_from=scenes)
        if ok:
            ready += 1

    if ready == 0:
        raise RuntimeError("не удалось собрать ни одной сцены")
    log_event(session, video.id, f"Сцены собраны: {ready} из {len(scenes)}", stage="assemble")
    return ready


def assemble_scene_piece(session: Session, video: Video, channel: Channel, scene: Scene,
                         workdir: Path, pieces_dir: Path, track_path: Optional[Path],
                         size: tuple[int, int],
                         fallback_from: Optional[list[Scene]] = None
                         ) -> tuple[bool, list[subtitles.Cue]]:
    """Один законченный кусок: видеоряд под озвучку, субтитры, копия с музыкой.

    Общая для первой сборки и для пересборки отдельной сцены, чтобы кусок после
    ручной перегенерации был устроен ровно так же, как после конвейера.
    """
    audio = storage.abspath(scene.audio_path)
    piece = pieces_dir / f"scene_{scene.idx:02d}.mp4"
    clean_piece = pieces_dir / f"scene_{scene.idx:02d}_clean.mp4"

    clips = [storage.abspath(p) for p in _scene_clips(scene)]
    clips = [c for c in clips if c.exists()]
    if not clips:
        fallback = _fallback_clip_for(scene, fallback_from or list(video.scenes))
        if fallback is None or not Path(fallback).exists():
            log_event(session, video.id, f"Сцена {scene.idx + 1}: нет видеоряда, пропускаю",
                      stage="assemble", level="warn")
            return False, []
        clips = [Path(fallback)]

    workdir.mkdir(parents=True, exist_ok=True)
    raw = workdir / f"scene_{scene.idx:02d}_raw.mp4"
    media.build_scene(clips, audio, raw, size, duration=scene.audio_sec,
                      workdir=workdir / f"w{scene.idx:02d}")

    # Субтитры сцены: текст берём из сценария, тайминг — из распознавания этой сцены.
    cues = _scene_cues(session, video, channel, scene, audio)
    if cues and channel.burn_subtitles:
        ass = workdir / f"scene_{scene.idx:02d}.ass"
        subtitles.write_ass(cues, ass, size=size,
                            vertical=channel.aspect_ratio == "9:16",
                            style=_subtitle_style(channel))
        try:
            media.burn_subtitles(raw, ass, piece)
            # Чистую сцену храним отдельно: из неё собирается мастер для нарезки
            # шортсов, иначе горизонтальные субтитры поедут при кропе в вертикаль.
            shutil.copyfile(raw, clean_piece)
        except RuntimeError as exc:
            log_event(session, video.id, f"Сцена {scene.idx + 1}: субтитры не вшиты ({exc})",
                      stage="assemble", level="warn")
            shutil.copyfile(raw, piece)
            clean_piece.unlink(missing_ok=True)
    else:
        shutil.copyfile(raw, piece)
        clean_piece.unlink(missing_ok=True)

    scene.piece_path = storage.rel(piece)
    scene.piece_music_path = _add_music_copy(session, video, channel, piece, track_path)
    scene.piece_sec = storage.media_duration(piece)
    scene.status = "piece_ready"
    session.commit()
    return True, cues


def _track_file(session: Session, video: Video, channel: Channel) -> Optional[Path]:
    if not channel.background_music:
        return None
    track = _background_track(session, video, channel)
    if track is None or not track.path:
        return None
    candidate = storage.abspath(track.path)
    return candidate if candidate.exists() else None


def _scene_cover(session: Session, video: Video, channel: Channel, scene: Scene,
                 title: str, source: Path, workdir: Path, thumb: Path,
                 ready_image: Optional[Path] = None) -> None:
    """Обложка шортса: картинка от nano banana плюс заголовок своим шрифтом.

    Если генератор не ответил, берём кадр из самой сцены — обложка нужна всегда,
    а ролик из-за неё падать не должен.
    """
    # Форматы «бюст» и «абзац» уже сгенерировали кадр — рисуем заголовок поверх
    # него, вместо того чтобы платить за вторую картинку с тем же смыслом.
    if ready_image is not None and ready_image.exists():
        media.make_thumbnail(ready_image, thumb, size=media.VERTICAL, headline=title)
        return

    raw: Optional[Path] = None
    try:
        client = client_for(session)
        prompt = prompts.short_cover(channel.name, channel.topic, scene.heading,
                                     scene.narration, channel.thumb_style)
        result = client.run_task(channel.image_model, {
            "prompt": prompt,
            "aspect_ratio": "9:16",
            "resolution": "1K",
            "output_format": "png",
        }, timeout=900, poll=5)
        urls = extract_urls(result)
        if not urls:
            raise KieError("в ответе нет ссылки на изображение")
        raw = workdir / f"cover_{scene.idx:02d}{storage.guess_ext(urls[0], '.png')}"
        storage.download(urls[0], raw)
    except Exception as exc:  # noqa: BLE001
        log_event(session, video.id,
                  f"Сцена {scene.idx + 1}: генератор обложек не сработал ({exc}), "
                  f"беру кадр из сцены", stage="assemble", level="warn")
        raw = None

    if raw is None or not raw.exists():
        raw = workdir / f"cover_{scene.idx:02d}.jpg"
        # Кадр берём из ЧИСТОГО источника, а не из готового шортса: там заголовок
        # и субтитры уже вшиты, и поверх них лёг бы второй заголовок.
        at = min(1.5, max(0.5, (scene.piece_sec or 4.0) * 0.15))
        media.frame_grab(source, raw, at=at)

    media.make_thumbnail(raw, thumb, size=media.VERTICAL, headline=title)
    raw.unlink(missing_ok=True)


# Порядок чередования: на три сцены приходится один полный видеоряд.
FORMAT_CYCLE = ("full", "bust", "paragraph")

SHORT_FORMATS = {
    "full": "Полный видеоряд — генерация клипов, дороже всего",
    "bust": "Бюст — один кадр с дымкой, титры крупным шрифтом",
    "paragraph": "Абзац — текст построчно на тёмном фоне, дешевле всего",
}


def normalize_format(value: str) -> str:
    return value if value in SHORT_FORMATS else "full"


def _scene_background(session: Session, video: Video, channel: Channel, scene: Scene,
                      fmt: str, workdir: Path) -> Optional[Path]:
    """Фоновый кадр для форматов «бюст» и «абзац» — одна картинка на весь шортс."""
    if fmt == "bust":
        prompt = prompts.bust_background(channel.topic, scene.heading, channel.thumb_style)
    else:
        prompt = prompts.paragraph_background(channel.topic, scene.heading)
    try:
        client = client_for(session)
        result = client.run_task(channel.image_model, {
            "prompt": prompt, "aspect_ratio": "9:16",
            "resolution": "1K", "output_format": "png",
        }, timeout=900, poll=5)
        urls = extract_urls(result)
        if not urls:
            raise KieError("в ответе нет ссылки на изображение")
        dest = workdir / f"bg_{scene.idx:02d}{storage.guess_ext(urls[0], '.png')}"
        storage.download(urls[0], dest)
        return dest
    except Exception as exc:  # noqa: BLE001
        log_event(session, video.id,
                  f"Сцена {scene.idx + 1}: фон для формата «{fmt}» не сгенерирован ({exc})",
                  stage="assemble", level="warn")
        return None


def _scene_tags(session: Session, video: Video, channel: Channel, scene: Scene) -> None:
    """Теги и описание для публикации этого шортса.

    В запрос идёт и сценарий ролика целиком, и текст именно этой сцены: теги
    должны связывать шортс с остальными роликами канала и одновременно приводить
    зрителя по конкретной мысли куска.
    """
    if scene.short_tags:
        return
    try:
        client = client_for(session)
        messages = prompts.short_metadata(
            channel.name, channel.topic, video.title, video.book_title,
            scene.heading, scene.narration, (video.script or "")[:4000])
        data, credits = client.chat_json(channel.chat_model, messages, temperature=0.7)
        tags = [str(t).strip().lstrip("#") for t in (data.get("tags") or []) if str(t).strip()]
        # убираем повторы, сохраняя порядок от точных к общим
        seen, ordered = set(), []
        for tag in tags:
            low = tag.lower()
            if low not in seen:
                seen.add(low)
                ordered.append(tag)
        scene.short_tags = ", ".join(ordered[:30])
        scene.short_description = (data.get("description") or "").strip()[:1500]
        video.cost_credits = (video.cost_credits or 0) + credits
        session.commit()
        log_event(session, video.id,
                  f"Сцена {scene.idx + 1}: подобрано тегов {len(ordered[:30])}",
                  stage="assemble")
    except Exception as exc:  # noqa: BLE001 — без тегов шортс всё равно готов
        log_event(session, video.id, f"Сцена {scene.idx + 1}: теги не подобраны ({exc})",
                  stage="assemble", level="warn")


def build_scene_short(session: Session, video: Video, channel: Channel, scene: Scene,
                      workdir: Path, pieces_dir: Path, track_path: Optional[Path],
                      cues: Optional[list[subtitles.Cue]] = None) -> bool:
    """Вертикальная версия сцены, готовая к публикации: заголовок в кадре и обложка.

    Лежит отдельным файлом от piece_path: заголовок нужен шортсу, но в каждой
    сцене длинного ролика он выглядел бы нелепо.
    """
    # Источник — чистый кусок без вшитых субтитров: у горизонтального канала
    # кроп в вертикаль срезал бы subtitles по краям вместе с картинкой.
    # Функция пишет сюда при любом формате, а на коротком пути каталога может
    # ещё не быть: сборку куска сцены мы пропустили.
    pieces_dir.mkdir(parents=True, exist_ok=True)

    clean = pieces_dir / f"scene_{scene.idx:02d}_clean.mp4"
    source = clean if clean.exists() else (
        storage.abspath(scene.piece_path) if scene.piece_path else clean)

    # Форматам «бюст» и «абзац» готовый кусок не нужен — они строятся из кадра и
    # озвучки, поэтому проверяем исходник только там, где он действительно нужен.
    if normalize_format(scene.short_format or "full") == "full" and not source.exists():
        raise RuntimeError("нет исходного куска сцены")

    duration = scene.piece_sec or (
        storage.media_duration(source) if source.exists() else scene.audio_sec)
    title = (scene.short_title or scene.heading or video.title or "").strip()

    if cues is None and scene.audio_path:
        cues = _scene_cues(session, video, channel, scene, storage.abspath(scene.audio_path))
    cues = cues or []

    workdir.mkdir(parents=True, exist_ok=True)
    fmt = normalize_format(scene.short_format or "full")
    raw = workdir / f"short_{scene.idx:02d}_raw.mp4"
    audio = storage.abspath(scene.audio_path) if scene.audio_path else None

    background: Optional[Path] = None
    if fmt in ("bust", "paragraph") and audio and audio.exists():
        background = _scene_background(session, video, channel, scene, fmt, workdir)
        if background is None:
            log_event(session, video.id,
                      f"Сцена {scene.idx + 1}: формат «{fmt}» без фона — собираю обычным",
                      stage="assemble", level="warn")
            fmt = "full"
        elif fmt == "paragraph":
            # Текст вшивается прямо в кадр построчно, ASS-субтитры здесь не нужны.
            chars = max(18, int(media.VERTICAL[0] / 26))
            media.build_paragraph_scene(
                background, audio, raw, media.VERTICAL, duration,
                media.paragraph_lines(cues, chars), fonts.font_path(channel.title_font) or "",
                workdir, signature=channel.name)
        else:
            still = workdir / f"still_{scene.idx:02d}.mp4"
            # Полоса под титрами в верхней трети: на сгенерированном кадре фон
            # непредсказуем, а поверх ровной заливки текст читается всегда.
            media.build_still_scene(background, audio, still, media.VERTICAL, duration,
                                    workdir, band_top=0.06, band_height=0.30)
            ass = workdir / f"short_{scene.idx:02d}.ass"
            head_seconds = min(4.5, max(2.5, duration * 0.18))
            subtitles.write_ass(cues, ass, size=media.VERTICAL, vertical=True,
                                title=title, title_seconds=head_seconds,
                                style=_subtitle_style(channel),
                                font=fonts.font_family(channel.title_font),
                                position="top")
            media.burn_subtitles(still, ass, raw, fontsdir=fonts.FONTS_DIR)

    if fmt == "full":
        ass = None
        if cues or title:
            ass = workdir / f"short_{scene.idx:02d}.ass"
            # заголовок висит первые секунды — дальше он мешал бы читать субтитры
            head_seconds = min(4.5, max(2.5, duration * 0.18))
            subtitles.write_ass(cues, ass, size=media.VERTICAL, vertical=True,
                                title=title, title_seconds=head_seconds,
                                style=_subtitle_style(channel))
        media.cut_short(source, raw, 0.0, duration, ass=ass)

    dest = pieces_dir / f"scene_{scene.idx:02d}_short.mp4"
    if track_path is not None:
        try:
            media.mix_background_music(raw, track_path, dest,
                                       music_db=channel.music_volume_db or -20.0)
        except RuntimeError as exc:
            log_event(session, video.id,
                      f"Сцена {scene.idx + 1}: музыка в шортс не легла ({exc})",
                      stage="assemble", level="warn")
            shutil.copyfile(raw, dest)
    else:
        shutil.copyfile(raw, dest)

    # Обложка с тем же заголовком — чтобы шортс можно было выложить как есть.
    thumb = pieces_dir / f"scene_{scene.idx:02d}_thumb.jpg"
    try:
        _scene_cover(session, video, channel, scene, title, source, workdir, thumb,
                     ready_image=background)
        scene.thumb_path = storage.rel(thumb)
    except Exception as exc:  # noqa: BLE001 — шортс важнее обложки
        log_event(session, video.id, f"Сцена {scene.idx + 1}: обложка не сделана ({exc})",
                  stage="assemble", level="warn")
        scene.thumb_path = ""

    scene.short_path = storage.rel(dest)
    scene.short_title = title
    session.commit()

    _scene_tags(session, video, channel, scene)
    log_event(session, video.id,
              f"Сцена {scene.idx + 1}: шортс готов — {duration:.0f} с, заголовок «{title}»",
              stage="assemble")
    return True


def _add_music_copy(session: Session, video: Video, channel: Channel, piece: Path,
                    track_path: Optional[Path]) -> str:
    """Кладём рядом с куском версию с фоновой музыкой — её и показываем в карточке.

    Сам кусок остаётся чистым: из чистых собирается длинный ролик, поэтому музыка
    не накладывается дважды и не рвётся на стыках.
    """
    if track_path is None:
        return ""
    dest = piece.with_name(piece.stem + "_music.mp4")
    try:
        media.mix_background_music(piece, track_path, dest,
                                   music_db=channel.music_volume_db or -20.0)
        return storage.rel(dest)
    except RuntimeError as exc:
        log_event(session, video.id, f"Музыку в кусок {piece.stem} добавить не удалось: {exc}",
                  stage="assemble", level="warn")
        dest.unlink(missing_ok=True)
        return ""


def _subtitle_style(channel: Channel) -> str:
    return subtitles.normalize_style(getattr(channel, "subtitle_style", "shorts") or "shorts")


def _scene_cues(session: Session, video: Video, channel: Channel, scene: Scene,
                audio: Path) -> list[subtitles.Cue]:
    """Реплики одной сцены: слова из сценария, тайминг из распознавания её озвучки."""
    raw: list[subtitles.Cue] = []
    if config.WHISPER_ENABLED:
        try:
            raw = subtitles.transcribe(audio, language=channel.language)
        except Exception as exc:  # noqa: BLE001
            log.warning("Whisper не сработал на сцене %s: %s", scene.idx, exc)
    pair = [(scene.narration, scene.audio_sec or storage.media_duration(audio))]
    if raw:
        return subtitles.align_script(raw, pair)
    return subtitles.cues_from_scenes(pair)


def ensure_bridge(session: Session, client: KieClient, video: Video, channel: Channel,
                  prev: Scene, nxt: Scene, out_dir: Path, workdir: Path) -> Optional[BridgeModel]:
    """Досоздаём короткую связку между несмежными сценами.

    Когда из ролика берут не подряд идущие куски, стык звучит рвано. Связка — это
    отдельная мини-сцена на пару предложений со своей озвучкой и видеорядом.
    Готовые связки переиспользуются: одна и та же пара сцен не генерируется дважды.
    """
    existing = session.execute(
        select(BridgeModel).where(BridgeModel.video_id == video.id,
                                  BridgeModel.from_scene_id == prev.id,
                                  BridgeModel.to_scene_id == nxt.id)
    ).scalars().first()
    if existing and existing.piece_path and storage.abspath(existing.piece_path).exists():
        return existing

    bridge = existing or BridgeModel(video_id=video.id, from_scene_id=prev.id,
                                     to_scene_id=nxt.id)
    if existing is None:
        session.add(bridge)
        session.commit()

    bridges_dir = out_dir / "bridges"
    bridges_dir.mkdir(parents=True, exist_ok=True)
    size = media.target_size(channel.resolution, channel.aspect_ratio)
    tail = " ".join((prev.narration or "").split()[-45:])
    head = " ".join((nxt.narration or "").split()[:45])

    try:
        data, _credits = client.chat_json(
            channel.chat_model,
            prompts.bridge(channel.name, channel.topic, prev.heading, tail,
                           nxt.heading, head),
            temperature=0.7)
        bridge.narration = str(data.get("narration") or "").strip()
        bridge.visual_prompt = str(data.get("visual_prompt") or "").strip()
        if not bridge.narration:
            raise RuntimeError("модель вернула пустую связку")

        result = tts.synthesize(
            client, bridge.narration, bridges_dir / f"bridge_{bridge.id:03d}",
            model=channel.tts_model, voice_id=channel.voice_id,
            stability=channel.voice_stability, similarity=channel.voice_similarity,
            speed=channel.voice_speed,
            fallback_model=st.get(session, "tts_fallback_model", "google/gemini-3-1-flash-tts"),
            fallback_voice=st.get(session, "tts_fallback_voice", "Charon"),
            allow_fallback=st.get_bool(session, "tts_allow_fallback", True))
        bridge.audio_path = storage.rel(result.path)
        session.commit()

        clip = _bridge_clip(session, client, video, channel, bridge, bridges_dir, size)
        if clip is None:
            raise RuntimeError("не удалось получить видеоряд для связки")

        raw = workdir / f"bridge_{bridge.id:03d}_raw.mp4"
        media.build_scene([clip], result.path, raw, size, duration=result.duration,
                          workdir=workdir / f"b{bridge.id:03d}")

        piece = bridges_dir / f"bridge_{bridge.id:03d}.mp4"
        clean = bridges_dir / f"bridge_{bridge.id:03d}_clean.mp4"
        cues = subtitles.cues_from_scenes([(bridge.narration, result.duration)])
        if cues and channel.burn_subtitles:
            ass = workdir / f"bridge_{bridge.id:03d}.ass"
            subtitles.write_ass(cues, ass, size=size,
                                vertical=channel.aspect_ratio == "9:16",
                                style=_subtitle_style(channel))
            media.burn_subtitles(raw, ass, piece)
            shutil.copyfile(raw, clean)
        else:
            shutil.copyfile(raw, piece)
            shutil.copyfile(raw, clean)

        track = None
        if channel.background_music:
            row = _background_track(session, video, channel)
            if row is not None and row.path:
                candidate = storage.abspath(row.path)
                track = candidate if candidate.exists() else None
        bridge.piece_path = storage.rel(piece)
        bridge.piece_music_path = _add_music_copy(session, video, channel, piece, track)
        bridge.clean_path = storage.rel(clean)
        bridge.piece_sec = storage.media_duration(piece)
        bridge.status = "ready"
        bridge.error = ""
        session.commit()
        log_event(session, video.id,
                  f"Создана связка между сценами {prev.idx + 1} и {nxt.idx + 1} "
                  f"({bridge.piece_sec:.0f} с)", stage="assemble")
        return bridge
    except Exception as exc:  # noqa: BLE001 — без связки просто склеим встык
        bridge.status = "failed"
        bridge.error = str(exc)[:1000]
        session.commit()
        log_event(session, video.id,
                  f"Связку между сценами {prev.idx + 1} и {nxt.idx + 1} создать не удалось: {exc}",
                  stage="assemble", level="warn")
        return None


def _bridge_clip(session: Session, client: KieClient, video: Video, channel: Channel,
                 bridge: BridgeModel, bridges_dir: Path, size: tuple[int, int]) -> Optional[Path]:
    """Видеоряд для связки: сперва пробуем библиотеку, иначе генерируем один клип."""
    library = footage.available(session, channel.id)
    if library and getattr(channel, "visual_source", "generate") != "generate":
        picker = footage.SegmentPicker(library, seed=bridge.id)
        chosen = picker.pick(float(channel.clip_duration), bridge.visual_prompt)
        if chosen is not None:
            item, start, end = chosen
            dest = bridges_dir / f"bridge_{bridge.id:03d}_clip.mp4"
            try:
                footage.cut_segment(storage.abspath(item.path), dest, start, end - start, size)
                bridge.clip_path = storage.rel(dest)
                session.commit()
                return dest
            except Exception as exc:  # noqa: BLE001
                log.warning("Фрагмент библиотеки для связки не вырезан: %s", exc)

    payload = video_input(
        channel.video_model,
        prompt=bridge.visual_prompt or bridge.narration[:200],
        aspect_ratio=channel.aspect_ratio,
        resolution=channel.resolution if channel.resolution in ("480p", "720p") else "720p",
        duration=int(channel.clip_duration))
    try:
        result = client.run_task(channel.video_model, payload, timeout=1800, poll=6)
        urls = extract_urls(result)
        url = next((u for u in urls if u.split("?")[0].lower().endswith(
            (".mp4", ".mov", ".webm"))), None) or (urls[0] if urls else None)
        if not url:
            return None
        dest = bridges_dir / f"bridge_{bridge.id:03d}_clip{storage.guess_ext(url, '.mp4')}"
        storage.download(url, dest)
        bridge.clip_path = storage.rel(dest)
        session.commit()
        return dest
    except Exception as exc:  # noqa: BLE001
        log.warning("Клип для связки не сгенерирован: %s", exc)
        return None


def assemble_selected(session: Session, video: Video, channel: Channel,
                      scene_ids: Optional[list[int]] = None,
                      with_bridges: bool = True) -> Path:
    """Склеиваем выбранные куски в готовый ролик, достраивая связки между несмежными."""
    _set_stage(session, video, "assemble", "Собираю длинный ролик из выбранных сцен")
    out_dir = storage.video_dir(channel.slug, video.id)
    workdir = config.TMP_DIR / f"assemble_{video.id}"
    shutil.rmtree(workdir, ignore_errors=True)
    workdir.mkdir(parents=True, exist_ok=True)

    scenes = [sc for sc in video.scenes if sc.piece_path
              and storage.abspath(sc.piece_path).exists()]
    if scene_ids:
        wanted = list(dict.fromkeys(scene_ids))  # сохраняем порядок выбора
        by_id = {sc.id: sc for sc in scenes}
        scenes = [by_id[i] for i in wanted if i in by_id]
    else:
        scenes = [sc for sc in scenes if sc.include]
    if not scenes:
        raise RuntimeError("не выбрано ни одной готовой сцены")

    for sc in video.scenes:
        sc.include = sc in scenes
    session.commit()

    # Между несмежными кусками вставляем связки, чтобы переход не был рваным.
    ordered: list[tuple[str, object]] = []
    client = client_for(session)
    for position, scene in enumerate(scenes):
        if position > 0 and with_bridges:
            prev = scenes[position - 1]
            if scene.idx - prev.idx > 1:
                bridge = ensure_bridge(session, client, video, channel, prev, scene,
                                       out_dir, workdir)
                if bridge is not None and bridge.piece_path:
                    ordered.append(("bridge", bridge))
        ordered.append(("scene", scene))

    pieces = [storage.abspath(item.piece_path) for _kind, item in ordered]
    raw = workdir / "full_raw.mp4"
    media.concat_scenes(pieces, raw, workdir)

    # Мастер без вшитых субтитров — источник для нарезки шортсов.
    clean_pieces = [
        storage.abspath(item.clean_path) if kind == "bridge" and item.clean_path
        else storage.abspath(item.piece_path).with_name(
            storage.abspath(item.piece_path).stem + "_clean.mp4")
        for kind, item in ordered
    ]
    raw_clean = None
    if all(p.exists() for p in clean_pieces):
        raw_clean = workdir / "full_clean.mp4"
        media.concat_scenes(clean_pieces, raw_clean, workdir / "clean")

    # Субтитры целого ролика собираем из сцен в выбранном порядке.
    cues: list[subtitles.Cue] = []
    offset = 0.0
    for kind, item in ordered:
        length = item.piece_sec or getattr(item, "audio_sec", 0.0)
        piece_cues = subtitles.cues_from_scenes([(item.narration, length)])
        for cue in piece_cues:
            cues.append(subtitles.Cue(start=cue.start + offset, end=cue.end + offset,
                                      text=cue.text))
        offset += length
    if cues:
        subtitles.write_srt(cues, out_dir / "subtitles.srt")
        subtitles.write_vtt(cues, out_dir / "subtitles.vtt")
        video.srt_path = storage.rel(out_dir / "subtitles.srt")
        video.vtt_path = storage.rel(out_dir / "subtitles.vtt")

    final = out_dir / "video.mp4"
    clean_final = out_dir / CLEAN_NAME
    music_applied = False
    if channel.background_music:
        track = _background_track(session, video, channel)
        if track is not None:
            try:
                volume = channel.music_volume_db or -20.0
                mixed = workdir / "with_music.mp4"
                media.mix_background_music(raw, storage.abspath(track.path), mixed,
                                           music_db=volume)
                shutil.move(str(mixed), str(final))
                # Тот же трек кладём и в чистый мастер, чтобы шортсы звучали
                # так же, как длинный ролик.
                if raw_clean is not None:
                    mixed_clean = workdir / "clean_with_music.mp4"
                    media.mix_background_music(raw_clean, storage.abspath(track.path),
                                               mixed_clean, music_db=volume)
                    shutil.move(str(mixed_clean), str(clean_final))
                track.used_count += 1
                session.commit()
                music_applied = True
                log_event(session, video.id, f"Наложена фоновая музыка: {track.title}",
                          stage="assemble")
            except RuntimeError as exc:
                log_event(session, video.id, f"Музыку наложить не удалось: {exc}",
                          stage="assemble", level="warn")
    if not music_applied:
        shutil.copyfile(raw, final)
        if raw_clean is not None:
            shutil.copyfile(raw_clean, clean_final)
    if raw_clean is None:
        clean_final.unlink(missing_ok=True)

    video.video_path = storage.rel(final)
    video.duration_sec = storage.media_duration(final)
    video.file_size = final.stat().st_size
    video.status = "done"
    video.stage = "done"
    video.progress = 100
    video.finished_at = utcnow()
    session.commit()
    shutil.rmtree(workdir, ignore_errors=True)
    bridge_count = sum(1 for kind, _ in ordered if kind == "bridge")
    note = f" и {bridge_count} связок" if bridge_count else ""
    log_event(session, video.id,
              f"Ролик собран из {len(scenes)} кусков{note}, {video.duration_sec / 60:.1f} мин",
              stage="done")
    return final


def _background_track(session: Session, video: Video, channel: Channel):
    try:
        # client_for внутри try: без ключа KIE это «нет музыки», а не падение сборки
        client = client_for(session)
        return music.ensure_track(session, client, channel.id, channel.topic,
                                  channel.music_style)
    except Exception as exc:  # noqa: BLE001
        log_event(session, video.id, f"Фоновая музыка недоступна: {exc}",
                  stage="assemble", level="warn")
        return None


def make_thumbnail(session: Session, client: KieClient, video: Video, channel: Channel,
                   out_dir: Path, source_video: Optional[Path] = None) -> float:
    _set_stage(session, video, "thumbnail", "Рисую обложку")
    prompt = prompts.thumbnail(channel.name, video.title, video.book_title, channel.thumb_style)
    credits = 0.0
    try:
        result = client.run_task(channel.image_model, {
            "prompt": prompt,
            "aspect_ratio": "16:9" if channel.aspect_ratio != "9:16" else "9:16",
            "resolution": "1K",
            "output_format": "png",
        }, timeout=900, poll=5)
        credits = float(result.get("_credits") or 0)
        urls = extract_urls(result)
        if not urls:
            raise KieError("в ответе нет ссылки на изображение")
        raw = out_dir / f"thumb_raw{storage.guess_ext(urls[0], '.png')}"
        storage.download(urls[0], raw)
        thumb = out_dir / "thumbnail.jpg"
        media.make_thumbnail(raw, thumb, headline=_thumb_headline(video))
        raw.unlink(missing_ok=True)
    except Exception as exc:  # noqa: BLE001
        log_event(session, video.id, f"Генератор обложек не сработал ({exc}), беру кадр из ролика",
                  stage="thumbnail", level="warn")
        thumb = out_dir / "thumbnail.jpg"
        fallback = source_video or _any_scene_piece(video)
        if fallback is None:
            raise
        grabbed = out_dir / "thumb_frame.jpg"
        media.frame_grab(fallback, grabbed, at=3.0)
        media.make_thumbnail(grabbed, thumb, headline=_thumb_headline(video))
        grabbed.unlink(missing_ok=True)

    video.thumb_path = storage.rel(thumb)
    session.commit()
    return credits


def _thumb_headline(video: Video) -> str:
    """Короткая фраза для обложки: берём подготовленную моделью либо начало заголовка."""
    if video.thumb_text:
        return video.thumb_text
    words = (video.title or video.book_title or "").split()
    return " ".join(words[:4])


def _any_scene_piece(video: Video) -> Optional[Path]:
    for scene in video.scenes:
        if scene.piece_path:
            path = storage.abspath(scene.piece_path)
            if path.exists():
                return path
    return None


def make_metadata(session: Session, client: KieClient, video: Video, channel: Channel) -> float:
    _set_stage(session, video, "metadata", "Готовлю заголовок, описание и теги")
    messages = prompts.metadata(channel.name, channel.topic, video.book_title,
                                video.book_author, video.script)
    try:
        data, credits = client.chat_json(channel.chat_model, messages, temperature=0.7)
    except Exception as exc:  # noqa: BLE001
        log_event(session, video.id, f"Метаданные не сгенерированы: {exc}",
                  stage="metadata", level="warn")
        return 0.0
    if data.get("title"):
        video.title = str(data["title"])[:300]
    video.description = str(data.get("description") or "")
    video.tags = ", ".join(data.get("tags") or [])
    video.title_variants = json.dumps(data.get("title_variants") or [], ensure_ascii=False)
    if data.get("thumb_text"):
        video.thumb_text = str(data["thumb_text"])[:120]
    if data.get("pinned_comment"):
        log_event(session, video.id, f"Закреплённый комментарий: {data['pinned_comment']}",
                  stage="metadata")
    session.commit()
    return credits


# --------------------------------------------------------------------------- шортсы

def make_shorts(session: Session, client: KieClient, video: Video, channel: Channel,
                count: Optional[int] = None) -> float:
    """Нарезаем вертикальные шортсы из готового ролика."""
    if not video.video_path:
        raise RuntimeError("ролик ещё не собран")
    master = storage.abspath(video.video_path)
    clean = master.parent / CLEAN_NAME
    # Режем из версии без вшитых субтитров, если она есть.
    source = clean if clean.exists() else master
    burn_own_subs = source is clean or not channel.burn_subtitles
    out_dir = master.parent / "shorts"
    out_dir.mkdir(parents=True, exist_ok=True)
    count = count or channel.shorts_count or 3

    cues = []
    if video.srt_path and storage.abspath(video.srt_path).exists():
        cues = _read_srt(storage.abspath(video.srt_path))
    if not cues:
        cues = subtitles.cues_from_scenes(
            [(s.narration, s.audio_sec) for s in video.scenes if s.audio_path])

    transcript = subtitles.transcript_with_timestamps(cues)
    messages = prompts.shorts(video.title, count, transcript)
    credits = 0.0
    try:
        picks, credits = client.chat_json(channel.chat_model, messages, temperature=0.6)
    except Exception as exc:  # noqa: BLE001
        log_event(session, video.id, f"Модель не выбрала фрагменты ({exc}), режу равномерно",
                  stage="shorts", level="warn")
        picks = _even_picks(video.duration_sec, count)

    for old in list(video.shorts):
        session.delete(old)
    session.commit()

    made = 0
    for idx, pick in enumerate(picks[:count]):
        try:
            start = float(pick.get("start", 0))
            end = float(pick.get("end", start + 45))
        except (TypeError, ValueError):
            continue
        end = min(end, video.duration_sec)
        if end - start < 10:
            continue
        short = Short(
            video_id=video.id, idx=idx,
            title=str(pick.get("title") or f"Шортс {idx + 1}")[:300],
            caption=str(pick.get("caption") or ""),
            hashtags=", ".join(pick.get("hashtags") or []),
            start_sec=start, end_sec=end, status="processing",
        )
        session.add(short)
        session.commit()
        try:
            ass = None
            piece_cues = subtitles.shift_cues(cues, start, end) if burn_own_subs else []
            # Заголовок держим в кадре первые секунды — он и цепляет зрителя,
            # и не мешает читать субтитры дальше.
            head_seconds = min(4.5, max(2.5, (end - start) * 0.18))
            if piece_cues or short.title:
                ass = out_dir / f"short_{idx:02d}.ass"
                subtitles.write_ass(piece_cues, ass, size=media.VERTICAL, vertical=True,
                                    title=short.title, title_seconds=head_seconds,
                                    style=_subtitle_style(channel))
            dst = out_dir / f"short_{idx:02d}.mp4"
            media.cut_short(source, dst, start, end, ass=ass)
            short.path = storage.rel(dst)
            short.status = "ready"
            made += 1
        except Exception as exc:  # noqa: BLE001
            short.status = "failed"
            short.error = str(exc)[:1000]
        session.commit()

    log_event(session, video.id, f"Готово шортсов: {made}", stage="shorts")
    return credits


def _even_picks(duration: float, count: int) -> list[dict]:
    if duration <= 0:
        return []
    step = duration / max(count, 1)
    out = []
    for i in range(count):
        start = i * step + 2
        out.append({"title": f"Фрагмент {i + 1}", "start": start,
                    "end": min(start + 50, duration), "caption": "", "hashtags": []})
    return out


def _read_srt(path: Path) -> list[subtitles.Cue]:
    cues: list[subtitles.Cue] = []
    blocks = path.read_text(encoding="utf-8").strip().split("\n\n")
    for block in blocks:
        lines = [ln for ln in block.splitlines() if ln.strip()]
        if len(lines) < 2:
            continue
        stamp = next((ln for ln in lines if "-->" in ln), None)
        if not stamp:
            continue
        try:
            left, right = [s.strip() for s in stamp.split("-->")]
            cues.append(subtitles.Cue(start=_parse_ts(left), end=_parse_ts(right),
                                      text=" ".join(lines[lines.index(stamp) + 1:])))
        except (ValueError, IndexError):
            continue
    return cues


def _parse_ts(value: str) -> float:
    value = value.replace(",", ".")
    parts = value.split(":")
    hours, minutes, seconds = (parts + ["0", "0", "0"])[:3]
    return int(hours) * 3600 + int(minutes) * 60 + float(seconds)


# --------------------------------------------------------------------------- оркестрация

def build_video(video_id: int, on_progress: Optional[Callable[[str], None]] = None) -> None:
    """Полный цикл производства ролика. Вызывается воркером очереди."""
    lock = _video_lock(video_id)
    if not lock.acquire(blocking=False):
        raise AlreadyBuilding(f"ролик {video_id} уже собирается")
    try:
        _build_video_locked(video_id)
    finally:
        lock.release()


def _build_video_locked(video_id: int) -> None:
    with session_scope() as session:
        video = session.get(Video, video_id)
        if video is None:
            raise RuntimeError(f"ролик {video_id} не найден")
        channel = session.get(Channel, video.channel_id)
        client = client_for(session)
        out_dir = storage.video_dir(channel.slug, video.id)
        workdir = config.TMP_DIR / f"video_{video.id}"
        if workdir.exists():
            shutil.rmtree(workdir, ignore_errors=True)
        workdir.mkdir(parents=True, exist_ok=True)
        credits = 0.0
        try:
            video.error = ""
            session.commit()

            if not video.script or not video.scenes:
                credits += generate_script(session, client, video, channel)
            _check_cancelled(session, video_id)

            if any(not _scene_audio_ok(s) for s in video.scenes):
                credits += voice_scenes(session, client, video, channel, out_dir)
            _check_cancelled(session, video_id)

            if any(not _scene_clips_ok(s) for s in video.scenes):
                credits += generate_visuals(session, client, video, channel, out_dir)
            _check_cancelled(session, video_id)

            build_scene_pieces(session, video, channel, workdir, out_dir)
            _check_cancelled(session, video_id)

            credits += make_metadata(session, client, video, channel)
            credits += make_thumbnail(session, client, video, channel, out_dir)

            video.cost_credits = round((video.cost_credits or 0) + credits, 3)
            # Длинный ролик не склеиваем автоматически: состав сцен выбирается в панели.
            video.status = "scenes_ready"
            video.stage = "scenes_ready"
            video.progress = 95
            session.commit()
            log_event(session, video.id,
                      f"Сцены готовы к сборке. Потрачено кредитов: {video.cost_credits:.2f}",
                      stage="scenes_ready")

            if video.plan_item_id:
                item = session.get(PlanItem, video.plan_item_id)
                if item:
                    item.status = "done"
                    session.commit()

        except PipelineCancelled:
            video.status = "cancelled"
            video.stage = "cancelled"
            session.commit()
            log_event(session, video.id, "Сборка отменена", stage="cancelled", level="warn")
        except Exception as exc:  # noqa: BLE001
            video.status = "failed"
            video.error = f"{exc}\n{traceback.format_exc()[-2000:]}"
            video.cost_credits = round((video.cost_credits or 0) + credits, 3)
            session.commit()
            log_event(session, video.id, f"Ошибка: {exc}", stage=video.stage, level="error")
            if video.plan_item_id:
                item = session.get(PlanItem, video.plan_item_id)
                if item:
                    item.status = "failed"
                    session.commit()
            raise
        finally:
            shutil.rmtree(workdir, ignore_errors=True)


def assemble_job(video_id: int, scene_ids: Optional[list[int]] = None,
                 with_bridges: bool = True) -> None:
    """Фоновая задача: собрать длинный ролик из выбранных сцен."""
    lock = _video_lock(video_id)
    if not lock.acquire(blocking=False):
        raise AlreadyBuilding(f"ролик {video_id} уже собирается")
    try:
        with session_scope() as session:
            video = session.get(Video, video_id)
            if video is None:
                raise RuntimeError(f"ролик {video_id} не найден")
            channel = session.get(Channel, video.channel_id)
            try:
                assemble_selected(session, video, channel, scene_ids, with_bridges)
            except Exception as exc:  # noqa: BLE001
                video.status = "failed"
                video.error = str(exc)[:2000]
                session.commit()
                log_event(session, video.id, f"Сборка не удалась: {exc}",
                          stage="assemble", level="error")
                raise
            if channel.make_shorts and not video.shorts:
                from .queue import enqueue

                enqueue(session, "make_shorts", video_id=video.id)
    finally:
        lock.release()


def build_shorts_job(video_id: int) -> None:
    with session_scope() as session:
        video = session.get(Video, video_id)
        if video is None:
            raise RuntimeError(f"ролик {video_id} не найден")
        channel = session.get(Channel, video.channel_id)
        client = client_for(session)
        make_shorts(session, client, video, channel)


def _regen_clips(session: Session, client: KieClient, video: Video, channel: Channel,
                 scene: Scene, prompt: str, count: int, model: str,
                 clips_dir: Path) -> tuple[list[Path], float, list[str]]:
    """Генерируем видеоряд одной сцены выбранной моделью.

    Каждый кадр получает свой вариант промпта: одинаковый запрос даёт похожие
    планы, а сцену нужно закрыть разными кадрами, а не одним и тем же.
    """
    clips_dir.mkdir(parents=True, exist_ok=True)
    resolution = channel.resolution if channel.resolution in ("480p", "720p") else "720p"
    concurrency = max(1, st.get_int(session, "scene_concurrency", 3))
    stamp = int(utcnow().timestamp())

    def work(part: int) -> tuple[int, Optional[Path], float, str]:
        text = prompt if part == 0 else f"{prompt}. Alternative angle {part + 1}"
        payload = video_input(model, prompt=text, aspect_ratio=channel.aspect_ratio,
                              resolution=resolution, duration=int(channel.clip_duration))
        try:
            result = client.run_task(model, payload, timeout=1800, poll=6)
            urls = extract_urls(result)
            url = next((u for u in urls if u.split("?")[0].lower().endswith(
                (".mp4", ".mov", ".webm"))), None) or (urls[0] if urls else None)
            if not url:
                raise KieError("в ответе нет ссылки на видео")
            dest = clips_dir / (f"scene_{scene.id:04d}_r{stamp}_{part:02d}"
                                f"{storage.guess_ext(url, '.mp4')}")
            storage.download(url, dest)
            return part, dest, float(result.get("_credits") or 0), ""
        except Exception as exc:  # noqa: BLE001 — один кадр не должен ронять пересборку
            log.warning("Пересборка сцены %s: кадр %s не сгенерирован: %s", scene.id, part, exc)
            return part, None, 0.0, str(exc)[:300]

    by_part: dict[int, Path] = {}
    credits = 0.0
    errors: list[str] = []
    with ThreadPoolExecutor(max_workers=concurrency) as pool:
        for part, path, cost, error in pool.map(work, range(count)):
            credits += cost
            if path is not None:
                by_part[part] = path
            elif error:
                errors.append(error)
    # порядок кадров — по номеру части, а не по тому, кто первым ответил
    return [by_part[p] for p in sorted(by_part)], credits, errors


def regen_scene_job(scene_id: int, options: Optional[dict] = None) -> None:
    """Фоновая задача: пересобрать одну сцену со своим промптом и своей моделью."""
    options = options or {}
    with session_scope() as session:
        scene = session.get(Scene, scene_id)
        if scene is None:
            raise RuntimeError(f"сцена {scene_id} не найдена")
        video_id = scene.video_id

    lock = _video_lock(video_id)
    if not lock.acquire(blocking=False):
        raise AlreadyBuilding(
            f"ролик {video_id} сейчас собирается — дождитесь окончания и повторите")
    try:
        _regen_scene_locked(scene_id, options)
    finally:
        lock.release()


def _regen_scene_locked(scene_id: int, options: dict) -> None:
    with session_scope() as session:
        scene = session.get(Scene, scene_id)
        video = session.get(Video, scene.video_id)
        channel = session.get(Channel, video.channel_id)
        out_dir = storage.video_dir(channel.slug, video.id)
        workdir = config.TMP_DIR / f"regen_{scene.id}"
        shutil.rmtree(workdir, ignore_errors=True)
        workdir.mkdir(parents=True, exist_ok=True)

        prompt = (options.get("visual_prompt") or scene.visual_prompt or "").strip()
        narration = (options.get("narration") or scene.narration or "").strip()
        video_model = (options.get("video_model") or channel.video_model or "").strip()
        tts_model = (options.get("tts_model") or channel.tts_model or "").strip()
        voice_id = (options.get("voice_id") or channel.voice_id or "").strip()

        # Озвучку трогаем, только если изменился текст, голос или модель речи —
        # иначе это лишние деньги и лишнее ожидание.
        make_short = bool(options.get("make_short"))
        short_title = (options.get("short_title") or scene.short_title
                       or scene.heading or "").strip()[:200]
        fmt = normalize_format(options.get("short_format") or scene.short_format or "full")
        chosen_font = fonts.normalize(options.get("title_font") or channel.title_font or "")
        if chosen_font and chosen_font != channel.title_font:
            channel.title_font = chosen_font

        redo_voice = bool(options.get("redo_voice"))
        if narration != (scene.narration or ""):
            redo_voice = True
        if tts_model != (channel.tts_model or "") or voice_id != (channel.voice_id or ""):
            redo_voice = True
        if not _scene_audio_ok(scene):
            redo_voice = True

        if options.get("only_short"):
            scene.short_title = short_title
            scene.short_format = fmt
            session.commit()
            log_event(session, video.id,
                      f"Сцена {scene.idx + 1}: собираю шортс из готового куска",
                      stage="regen")
            try:
                build_scene_short(session, video, channel, scene, workdir,
                                  out_dir / "scenes", _track_file(session, video, channel))
            finally:
                shutil.rmtree(workdir, ignore_errors=True)
            return

        # клиент нужен только дальше, где идут реальные вызовы KIE
        client = client_for(session)

        if narration != (scene.narration or ""):
            # текст изменился — прежние теги к нему больше не относятся
            scene.short_tags = ""
            scene.short_description = ""
        scene.visual_prompt = prompt
        scene.narration = narration
        scene.status = "regenerating"
        scene.error = ""
        session.commit()

        label = f"Сцена {scene.idx + 1}"
        log_event(session, video.id,
                  f"{label}: пересборка запущена, модель видео {video_model}"
                  + (f", озвучка заново ({tts_model})" if redo_voice else ", озвучка прежняя"),
                  stage="regen")

        credits = 0.0
        try:
            if redo_voice:
                audio_dir = out_dir / "audio"
                audio_dir.mkdir(parents=True, exist_ok=True)
                # В имени — метка времени, а не номер сцены: при повторной пересборке
                # одинаковое имя означало бы, что синтез перезапишет прежний файл,
                # а следом строка «удалить старую озвучку» снесёт только что созданный.
                stamp = int(utcnow().timestamp())
                result = tts.synthesize(
                    client, narration, audio_dir / f"scene_{scene.idx:02d}_r{stamp}",
                    model=tts_model, voice_id=voice_id,
                    stability=channel.voice_stability, similarity=channel.voice_similarity,
                    speed=channel.voice_speed,
                    fallback_model=st.get(session, "tts_fallback_model",
                                          "google/gemini-3-1-flash-tts"),
                    fallback_voice=st.get(session, "tts_fallback_voice", "Charon"),
                    allow_fallback=st.get_bool(session, "tts_allow_fallback", True),
                )
                previous_audio = scene.audio_path
                scene.audio_path = storage.rel(result.path)
                # страховка на случай совпадения имён: не удаляем то, что сейчас используем
                if previous_audio and previous_audio != scene.audio_path:
                    _drop_file(previous_audio)
                scene.audio_sec = result.duration
                credits += result.credits
                _check_audio_length(session, video, scene.idx, narration, result.duration)
                session.commit()

            # Форматам «бюст» и «абзац» видеоряд не нужен: они строятся из одного
            # кадра поверх готовой озвучки. Генерировать для них клипы и пересобирать
            # кусок сцены — выбрасывать деньги, поэтому идём коротким путём.
            if fmt in ("bust", "paragraph"):
                scene.short_format = fmt
                scene.short_title = short_title
                scene.status = "piece_ready"
                scene.error = ""
                video.cost_credits = (video.cost_credits or 0) + credits
                session.commit()
                log_event(session, video.id,
                          f"{label}: формат «{fmt}» — клипы не генерирую, "
                          f"собираю шортс из одного кадра", stage="regen")
                build_scene_short(session, video, channel, scene, workdir,
                                  out_dir / "scenes", _track_file(session, video, channel))
                return

            coverage = max(6, int(getattr(channel, "clip_coverage_sec", 20) or 20))
            cap = int(getattr(channel, "max_clips_per_scene", 8) or 8)
            count = int(options.get("clips") or 0) or media.clips_needed(
                scene.audio_sec or coverage, coverage, cap)
            count = max(1, min(16, count))

            clips, clip_credits, errors = _regen_clips(
                session, client, video, channel, scene, prompt, count, video_model,
                out_dir / "clips")
            credits += clip_credits
            if not clips:
                raise RuntimeError("не удалось сгенерировать ни одного кадра: "
                                   + ("; ".join(errors[:2]) or "модель не вернула видео"))
            if errors:
                log_event(session, video.id,
                          f"{label}: часть кадров не сгенерировалась ({len(errors)} из {count})",
                          stage="regen", level="warn")

            fresh = {storage.rel(c) for c in clips}
            for old in _scene_clips(scene):
                if old not in fresh:
                    _drop_file(old)
            scene.clip_paths = json.dumps([storage.rel(c) for c in clips], ensure_ascii=False)
            scene.clip_sources = json.dumps(["generated"] * len(clips), ensure_ascii=False)
            scene.clip_path = storage.rel(clips[0])
            scene.clip_sec = storage.media_duration(clips[0])
            session.commit()

            # Старый кусок удаляем, иначе сборка увидит готовый файл и ничего не сделает.
            # Имя куска от номера сцены и не меняется, поэтому файл будет перезаписан —
            # удалять его безопасно только ДО сборки, что здесь и происходит.
            _drop_file(scene.piece_path)
            _drop_file(scene.piece_music_path)
            scene.piece_path = ""
            scene.piece_music_path = ""
            session.commit()

            track_path = _track_file(session, video, channel)

            size = media.target_size(channel.resolution, channel.aspect_ratio)
            pieces_dir = out_dir / "scenes"
            pieces_dir.mkdir(parents=True, exist_ok=True)
            ok, cues = assemble_scene_piece(session, video, channel, scene, workdir,
                                            pieces_dir, track_path, size)
            if not ok:
                raise RuntimeError("кусок сцены не собрался")

            if make_short:
                scene.short_title = short_title
                scene.short_format = fmt
                session.commit()
                try:
                    build_scene_short(session, video, channel, scene, workdir, pieces_dir,
                                      track_path, cues)
                except Exception as exc:  # noqa: BLE001 — кусок уже готов, шортс вторичен
                    log_event(session, video.id,
                              f"{label}: шортс не собрался — {exc}",
                              stage="regen", level="warn")

            video.cost_credits = (video.cost_credits or 0) + credits
            session.commit()
            log_event(session, video.id,
                      f"{label}: пересобрана — {scene.piece_sec:.0f} с, кадров {len(clips)}, "
                      f"{credits:.1f} кредитов",
                      stage="regen")
        except Exception as exc:  # noqa: BLE001
            scene.status = "regen_failed"
            scene.error = str(exc)[:500]
            session.commit()
            log_event(session, video.id, f"{label}: пересборка не удалась — {exc}",
                      stage="regen", level="error")
            raise
        finally:
            shutil.rmtree(workdir, ignore_errors=True)


def _drop_file(rel_path: str) -> None:
    """Удаляем файл прошлой версии, чтобы на диске не копились неиспользуемые дубли."""
    if not rel_path:
        return
    try:
        storage.abspath(rel_path).unlink(missing_ok=True)
    except OSError as exc:
        log.warning("Не удалось удалить %s: %s", rel_path, exc)
