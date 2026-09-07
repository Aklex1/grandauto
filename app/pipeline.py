"""Конвейер производства ролика: сценарий → озвучка → видеоряд → субтитры → сборка → метаданные."""
from __future__ import annotations

import json
import logging
import shutil
import traceback
from concurrent.futures import ThreadPoolExecutor
from pathlib import Path
from typing import Callable, Optional

from sqlalchemy.orm import Session

from . import config, media, prompts, storage, subtitles, tts
from .db import session_scope
from .kie import KieClient, KieError, extract_urls
from .models import Channel, Event, PlanItem, Scene, Short, Video, utcnow
from . import settings_store as st

log = logging.getLogger("cf.pipeline")

STAGE_PROGRESS = {
    "script": 10,
    "voice": 35,
    "visuals": 65,
    "assemble": 80,
    "subtitles": 88,
    "thumbnail": 93,
    "metadata": 97,
    "done": 100,
}


class PipelineCancelled(RuntimeError):
    """Пользователь отменил сборку из интерфейса."""


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

    for idx, scene in enumerate(scenes):
        session.add(Scene(
            video_id=video.id, idx=idx,
            heading=(scene.get("heading") or f"Сцена {idx + 1}")[:300],
            narration=(scene.get("narration") or "").strip(),
            visual_prompt=(scene.get("visual_prompt") or "").strip(),
        ))
    session.commit()
    log_event(session, video.id, f"Сценарий готов: {len(scenes)} сцен, "
                                 f"{len(video.script)} символов", stage="script")
    return credits


def voice_scenes(session: Session, client: KieClient, video: Video, channel: Channel,
                 workdir: Path) -> float:
    _set_stage(session, video, "voice", "Озвучиваю сцены")
    allow_fallback = st.get_bool(session, "tts_allow_fallback", True)
    fallback_model = st.get(session, "tts_fallback_model", "google/gemini-3-1-flash-tts")
    fallback_voice = st.get(session, "tts_fallback_voice", "Charon")
    concurrency = max(1, st.get_int(session, "scene_concurrency", 3))

    scenes = list(video.scenes)
    audio_dir = workdir / "audio"
    audio_dir.mkdir(parents=True, exist_ok=True)

    def work(scene: Scene):
        result = tts.synthesize(
            client, scene.narration, audio_dir / f"scene_{scene.idx:02d}",
            model=channel.tts_model, voice_id=channel.voice_id,
            stability=channel.voice_stability, similarity=channel.voice_similarity,
            speed=channel.voice_speed, fallback_model=fallback_model,
            fallback_voice=fallback_voice, allow_fallback=allow_fallback,
        )
        return scene.id, result

    credits = 0.0
    with ThreadPoolExecutor(max_workers=concurrency) as pool:
        for scene_id, result in pool.map(work, scenes):
            scene = session.get(Scene, scene_id)
            scene.audio_path = storage.rel(result.path)
            scene.audio_sec = result.duration
            scene.status = "voiced"
            credits += result.credits
            session.commit()

    total = sum(s.audio_sec for s in video.scenes)
    log_event(session, video.id,
              f"Озвучка готова: {len(scenes)} файлов, {total / 60:.1f} мин", stage="voice")
    return credits


def generate_visuals(session: Session, client: KieClient, video: Video, channel: Channel,
                     workdir: Path) -> float:
    _set_stage(session, video, "visuals", "Генерирую видеоряд")
    concurrency = max(1, st.get_int(session, "scene_concurrency", 3))
    clips_dir = workdir / "clips"
    clips_dir.mkdir(parents=True, exist_ok=True)
    resolution = channel.resolution if channel.resolution in ("480p", "720p") else "720p"

    scenes = list(video.scenes)

    def work(scene: Scene):
        prompt = scene.visual_prompt or scene.heading or video.title
        payload = {
            "prompt": prompt,
            "aspect_ratio": channel.aspect_ratio,
            "resolution": resolution,
            "duration": int(channel.clip_duration),
            "generate_audio": False,
        }
        try:
            result = client.run_task(channel.video_model, payload, timeout=1800, poll=6)
            urls = extract_urls(result)
            video_url = next((u for u in urls if u.split("?")[0].lower().endswith(
                (".mp4", ".mov", ".webm"))), None) or (urls[0] if urls else None)
            if not video_url:
                raise KieError("в ответе нет ссылки на видео")
            dest = clips_dir / f"scene_{scene.idx:02d}{storage.guess_ext(video_url, '.mp4')}"
            storage.download(video_url, dest)
            return scene.id, dest, float(result.get("_credits") or 0), ""
        except Exception as exc:  # noqa: BLE001 — сцена не должна ронять весь ролик
            log.warning("Клип для сцены %s не сгенерирован: %s", scene.idx, exc)
            return scene.id, None, 0.0, str(exc)[:500]

    credits = 0.0
    ok = 0
    with ThreadPoolExecutor(max_workers=concurrency) as pool:
        for scene_id, path, cost, error in pool.map(work, scenes):
            scene = session.get(Scene, scene_id)
            credits += cost
            if path is not None:
                scene.clip_path = storage.rel(path)
                scene.clip_sec = storage.media_duration(path)
                scene.status = "ready"
                ok += 1
            else:
                scene.status = "clip_failed"
                scene.error = error
            session.commit()

    if ok == 0:
        raise RuntimeError("не удалось сгенерировать ни одного видеоклипа")
    log_event(session, video.id, f"Видеоряд готов: {ok} из {len(scenes)} клипов", stage="visuals")
    return credits


def _fallback_clip_for(scene: Scene, scenes: list[Scene]) -> Optional[Path]:
    """Если у сцены нет клипа — берём ближайший удачный."""
    ready = [s for s in scenes if s.clip_path]
    if not ready:
        return None
    nearest = min(ready, key=lambda s: abs(s.idx - scene.idx))
    return storage.abspath(nearest.clip_path)


def assemble(session: Session, video: Video, channel: Channel, workdir: Path,
             out_dir: Path) -> Path:
    _set_stage(session, video, "assemble", "Собираю ролик")
    size = media.target_size(channel.resolution, channel.aspect_ratio)
    scenes = list(video.scenes)
    scene_files: list[Path] = []

    for scene in scenes:
        if not scene.audio_path:
            continue
        audio = storage.abspath(scene.audio_path)
        clip = storage.abspath(scene.clip_path) if scene.clip_path else _fallback_clip_for(scene, scenes)
        if clip is None or not Path(clip).exists():
            log_event(session, video.id, f"Сцена {scene.idx + 1}: нет видеоряда, пропускаю",
                      stage="assemble", level="warn")
            continue
        dst = workdir / f"scene_{scene.idx:02d}_full.mp4"
        media.build_scene([Path(clip)], audio, dst, size,
                          duration=scene.audio_sec, workdir=workdir / f"w{scene.idx:02d}")
        scene_files.append(dst)

    if not scene_files:
        raise RuntimeError("нет ни одной готовой сцены для сборки")

    raw = workdir / "full_raw.mp4"
    media.concat_scenes(scene_files, raw, workdir)
    final = out_dir / "video.mp4"
    shutil.copyfile(raw, final)
    video.duration_sec = storage.media_duration(final)
    video.video_path = storage.rel(final)
    video.file_size = final.stat().st_size
    session.commit()
    log_event(session, video.id,
              f"Черновая сборка готова: {video.duration_sec / 60:.1f} мин", stage="assemble")
    return final


def make_subtitles(session: Session, video: Video, channel: Channel, source: Path,
                   out_dir: Path) -> Path:
    _set_stage(session, video, "subtitles", "Распознаю речь и делаю субтитры")
    size = media.target_size(channel.resolution, channel.aspect_ratio)
    cues = []
    if config.WHISPER_ENABLED:
        try:
            cues = subtitles.transcribe(source, language=channel.language)
        except Exception as exc:  # noqa: BLE001 — падать из-за ASR нельзя
            log_event(session, video.id, f"Whisper недоступен ({exc}), считаю тайминги по сценам",
                      stage="subtitles", level="warn")
    if not cues:
        cues = subtitles.cues_from_scenes(
            [(s.narration, s.audio_sec) for s in video.scenes if s.audio_path])

    files = subtitles.build_all(cues, out_dir / "subtitles", size)
    video.srt_path = storage.rel(files["srt"])
    video.vtt_path = storage.rel(files["vtt"])
    video.ass_path = storage.rel(files["ass"])
    session.commit()

    result = source
    if channel.burn_subtitles:
        burned = out_dir / "video_subbed.mp4"
        try:
            media.burn_subtitles(source, files["ass"], burned)
            shutil.move(str(burned), str(source))
            result = source
        except RuntimeError as exc:
            log_event(session, video.id, f"Не удалось вшить субтитры: {exc}",
                      stage="subtitles", level="warn")
    video.duration_sec = storage.media_duration(result)
    video.file_size = Path(result).stat().st_size
    session.commit()
    log_event(session, video.id, f"Субтитры готовы: {len(cues)} реплик", stage="subtitles")
    return result


def make_thumbnail(session: Session, client: KieClient, video: Video, channel: Channel,
                   out_dir: Path, source_video: Path) -> float:
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
        media.make_thumbnail(raw, thumb)
        raw.unlink(missing_ok=True)
    except Exception as exc:  # noqa: BLE001
        log_event(session, video.id, f"Генератор обложек не сработал ({exc}), беру кадр из ролика",
                  stage="thumbnail", level="warn")
        thumb = out_dir / "thumbnail.jpg"
        media.frame_grab(source_video, thumb, at=min(5.0, max(1.0, video.duration_sec / 3)))

    video.thumb_path = storage.rel(thumb)
    session.commit()
    return credits


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
    source = storage.abspath(video.video_path)
    out_dir = source.parent / "shorts"
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
            piece_cues = subtitles.shift_cues(cues, start, end)
            ass = out_dir / f"short_{idx:02d}.ass"
            subtitles.write_ass(piece_cues, ass, size=media.VERTICAL, vertical=True)
            dst = out_dir / f"short_{idx:02d}.mp4"
            media.cut_short(source, dst, start, end, ass=ass if piece_cues else None)
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

            if any(not s.audio_path for s in video.scenes):
                credits += voice_scenes(session, client, video, channel, workdir)
            _check_cancelled(session, video_id)

            if any(not s.clip_path for s in video.scenes):
                credits += generate_visuals(session, client, video, channel, workdir)
            _check_cancelled(session, video_id)

            final = assemble(session, video, channel, workdir, out_dir)
            _check_cancelled(session, video_id)

            final = make_subtitles(session, video, channel, final, out_dir)
            credits += make_thumbnail(session, client, video, channel, out_dir, final)
            credits += make_metadata(session, client, video, channel)

            video.cost_credits = round((video.cost_credits or 0) + credits, 3)
            video.status = "done"
            video.stage = "done"
            video.progress = 100
            video.finished_at = utcnow()
            session.commit()
            log_event(session, video.id,
                      f"Ролик готов. Потрачено кредитов: {video.cost_credits:.2f}", stage="done")

            if video.plan_item_id:
                item = session.get(PlanItem, video.plan_item_id)
                if item:
                    item.status = "done"
                    session.commit()

            if channel.make_shorts:
                from .queue import enqueue  # локальный импорт: избегаем цикла

                enqueue(session, "make_shorts", video_id=video.id)

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


def build_shorts_job(video_id: int) -> None:
    with session_scope() as session:
        video = session.get(Video, video_id)
        if video is None:
            raise RuntimeError(f"ролик {video_id} не найден")
        channel = session.get(Channel, video.channel_id)
        client = client_for(session)
        make_shorts(session, client, video, channel)
