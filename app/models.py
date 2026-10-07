"""Схема БД контент-завода."""
from __future__ import annotations

import datetime as dt
import json
from typing import Optional

from sqlalchemy import (
    Boolean, Date, DateTime, Float, ForeignKey, Integer, String, Text, UniqueConstraint,
)
from sqlalchemy.orm import Mapped, mapped_column, relationship

from .db import Base


def utcnow() -> dt.datetime:
    return dt.datetime.now(dt.timezone.utc).replace(tzinfo=None)


class JSONMixin:
    """Хелперы для полей, где JSON лежит текстом (переносимо между SQLite и Postgres)."""

    @staticmethod
    def loads(raw: Optional[str], default):
        if not raw:
            return default
        try:
            return json.loads(raw)
        except (ValueError, TypeError):
            return default

    @staticmethod
    def dumps(value) -> str:
        return json.dumps(value, ensure_ascii=False)


class Setting(Base):
    """Глобальные настройки (ключ-значение)."""

    __tablename__ = "settings"
    key: Mapped[str] = mapped_column(String(120), primary_key=True)
    value: Mapped[str] = mapped_column(Text, default="")
    updated_at: Mapped[dt.datetime] = mapped_column(DateTime, default=utcnow, onupdate=utcnow)


class Channel(Base):
    """YouTube-канал: тематика, пресеты генерации, расписание."""

    __tablename__ = "channels"
    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    slug: Mapped[str] = mapped_column(String(80), unique=True)
    name: Mapped[str] = mapped_column(String(200))
    topic: Mapped[str] = mapped_column(Text, default="")
    description: Mapped[str] = mapped_column(Text, default="")
    language: Mapped[str] = mapped_column(String(10), default="ru")
    is_active: Mapped[bool] = mapped_column(Boolean, default=True)
    position: Mapped[int] = mapped_column(Integer, default=0)

    # --- пресеты генерации ---
    chat_model: Mapped[str] = mapped_column(String(120), default="gemini-3-8-flash-openai")
    video_model: Mapped[str] = mapped_column(String(120), default="bytedance/seedance-1.5-pro")
    image_model: Mapped[str] = mapped_column(String(120), default="nano-banana-2")
    tts_model: Mapped[str] = mapped_column(String(120), default="elevenlabs/text-to-speech-multilingual-v2")
    voice_id: Mapped[str] = mapped_column(String(120), default="nPczCjzI2devNBz1zQrb")
    voice_name: Mapped[str] = mapped_column(String(120), default="Brian")
    voice_stability: Mapped[float] = mapped_column(Float, default=0.45)
    voice_similarity: Mapped[float] = mapped_column(Float, default=0.8)
    voice_speed: Mapped[float] = mapped_column(Float, default=1.0)

    aspect_ratio: Mapped[str] = mapped_column(String(16), default="16:9")
    resolution: Mapped[str] = mapped_column(String(16), default="720p")
    clip_duration: Mapped[int] = mapped_column(Integer, default=5)
    clip_coverage_sec: Mapped[int] = mapped_column(Integer, default=20)
    # потолок числа разных кадров на одну сцену: больше — разнообразнее и дороже
    max_clips_per_scene: Mapped[int] = mapped_column(Integer, default=8)
    # generate — только генерация, library — только загруженные футажи, mix — смешанный
    visual_source: Mapped[str] = mapped_column(String(16), default="generate")
    library_share: Mapped[int] = mapped_column(Integer, default=50)
    target_minutes: Mapped[float] = mapped_column(Float, default=8.0)
    scene_count: Mapped[int] = mapped_column(Integer, default=8)

    visual_style: Mapped[str] = mapped_column(Text, default="")
    script_style: Mapped[str] = mapped_column(Text, default="")
    thumb_style: Mapped[str] = mapped_column(Text, default="")

    burn_subtitles: Mapped[bool] = mapped_column(Boolean, default=True)
    # оформление субтитров: shorts | shorts_green | shorts_plain | classic
    subtitle_style: Mapped[str] = mapped_column(String(20), default="shorts")
    # шрифт титров в форматах «бюст» и «абзац»
    title_font: Mapped[str] = mapped_column(String(32), default="playfair")
    # чередование форматов при работе по расписанию
    rotate_formats: Mapped[bool] = mapped_column(Boolean, default=True)
    # Концовка шортса: призыв подписаться со ссылкой и названием канала.
    outro_enabled: Mapped[bool] = mapped_column(Boolean, default=False)
    outro_url: Mapped[str] = mapped_column(String(300), default="")
    outro_title: Mapped[str] = mapped_column(String(120), default="")
    # Чем именно завлекаем. Из этого текста модель собирает короткий призыв —
    # пересказывать весь функционал в концовке шортса незачем.
    outro_about: Mapped[str] = mapped_column(Text, default="")
    # Откуда брать реплики: "builtin" — готовый набор, "custom" — свои,
    # "model" — составит чат-модель (это единственный платный вариант).
    outro_source: Mapped[str] = mapped_column(String(20), default="builtin")

    # На основе чего строить контент-план: книги, темы, свои материалы, тренды.
    content_source: Mapped[str] = mapped_column(String(20), default="books")
    # Модель, принимающая картинки-референсы на вход. Обычный генератор их не
    # берёт: у него в схеме нет поля под изображения.
    image_edit_model: Mapped[str] = mapped_column(
        String(120), default="google/nano-banana-edit")
    # Чем считать видеоряд: "kie" — облако, "comfy" — локальный ComfyUI.
    # Озвучка, обложки и сценарий в обоих случаях идут через KIE.
    video_source: Mapped[str] = mapped_column(String(16), default="kie")
    comfy_workflow_id: Mapped[Optional[int]] = mapped_column(Integer, nullable=True)
    # Кому канал адресован — идёт в промпт плана вместо догадки по названию.
    audience: Mapped[str] = mapped_column(String(300), default="")
    # Сколько роликов в день выпускаем: из этого считается длина плана на период.
    posts_per_day: Mapped[float] = mapped_column(Float, default=1.0)
    # Готовый текст призыва. Кэшируем, чтобы не платить за генерацию на каждый
    # шортс и чтобы его можно было поправить руками.
    outro_text: Mapped[str] = mapped_column(Text, default="")
    make_shorts: Mapped[bool] = mapped_column(Boolean, default=True)
    shorts_count: Mapped[int] = mapped_column(Integer, default=3)
    background_music: Mapped[bool] = mapped_column(Boolean, default=False)
    music_style: Mapped[str] = mapped_column(Text, default="")
    music_volume_db: Mapped[float] = mapped_column(Float, default=-20.0)

    created_at: Mapped[dt.datetime] = mapped_column(DateTime, default=utcnow)

    plan_items: Mapped[list["PlanItem"]] = relationship(back_populates="channel", cascade="all, delete-orphan")
    videos: Mapped[list["Video"]] = relationship(back_populates="channel", cascade="all, delete-orphan")
    schedule_rules: Mapped[list["ScheduleRule"]] = relationship(back_populates="channel", cascade="all, delete-orphan")


class PlanItem(Base):
    """Пункт контент-плана канала — одна книга/тема = один ролик."""

    __tablename__ = "plan_items"
    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    channel_id: Mapped[int] = mapped_column(ForeignKey("channels.id", ondelete="CASCADE"), index=True)
    position: Mapped[int] = mapped_column(Integer, default=0)
    book_title: Mapped[str] = mapped_column(String(300))
    book_author: Mapped[str] = mapped_column(String(200), default="")
    angle: Mapped[str] = mapped_column(Text, default="")
    key_points: Mapped[str] = mapped_column(Text, default="")
    video_title: Mapped[str] = mapped_column(String(300), default="")
    scheduled_date: Mapped[Optional[dt.date]] = mapped_column(Date, nullable=True, index=True)
    # planned | queued | in_progress | done | failed | skipped
    status: Mapped[str] = mapped_column(String(24), default="planned", index=True)
    notes: Mapped[str] = mapped_column(Text, default="")
    created_at: Mapped[dt.datetime] = mapped_column(DateTime, default=utcnow)

    channel: Mapped[Channel] = relationship(back_populates="plan_items")
    videos: Mapped[list["Video"]] = relationship(back_populates="plan_item")


class Video(Base):
    """Ролик: от сценария до собранного mp4."""

    __tablename__ = "videos"
    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    channel_id: Mapped[int] = mapped_column(ForeignKey("channels.id", ondelete="CASCADE"), index=True)
    plan_item_id: Mapped[Optional[int]] = mapped_column(ForeignKey("plan_items.id"), nullable=True, index=True)

    title: Mapped[str] = mapped_column(String(300), default="")
    book_title: Mapped[str] = mapped_column(String(300), default="")
    book_author: Mapped[str] = mapped_column(String(200), default="")

    # queued | scripting | voicing | visuals | subtitles | assembling | metadata | shorts | done | failed | cancelled
    status: Mapped[str] = mapped_column(String(24), default="queued", index=True)
    # Автоматическая догенерация: сколько раз завод уже пробовал дожать ролик
    # после срыва и когда попробует снова. Срыв обычно временный — кончились
    # кредиты, провайдер ответил 500, — и ролик достраивается сам, как только
    # причина уходит.
    retry_count: Mapped[int] = mapped_column(Integer, default=0)
    retry_at: Mapped[Optional[dt.datetime]] = mapped_column(DateTime, nullable=True)
    stage: Mapped[str] = mapped_column(String(80), default="")
    progress: Mapped[int] = mapped_column(Integer, default=0)
    error: Mapped[str] = mapped_column(Text, default="")

    script: Mapped[str] = mapped_column(Text, default="")
    hook: Mapped[str] = mapped_column(Text, default="")
    description: Mapped[str] = mapped_column(Text, default="")
    tags: Mapped[str] = mapped_column(Text, default="")
    title_variants: Mapped[str] = mapped_column(Text, default="")
    thumb_text: Mapped[str] = mapped_column(String(120), default="")

    video_path: Mapped[str] = mapped_column(String(500), default="")
    audio_path: Mapped[str] = mapped_column(String(500), default="")
    thumb_path: Mapped[str] = mapped_column(String(500), default="")
    srt_path: Mapped[str] = mapped_column(String(500), default="")
    ass_path: Mapped[str] = mapped_column(String(500), default="")
    vtt_path: Mapped[str] = mapped_column(String(500), default="")

    duration_sec: Mapped[float] = mapped_column(Float, default=0.0)
    file_size: Mapped[int] = mapped_column(Integer, default=0)
    cost_credits: Mapped[float] = mapped_column(Float, default=0.0)

    created_at: Mapped[dt.datetime] = mapped_column(DateTime, default=utcnow, index=True)
    updated_at: Mapped[dt.datetime] = mapped_column(DateTime, default=utcnow, onupdate=utcnow)
    finished_at: Mapped[Optional[dt.datetime]] = mapped_column(DateTime, nullable=True)

    channel: Mapped[Channel] = relationship(back_populates="videos")
    plan_item: Mapped[Optional[PlanItem]] = relationship(back_populates="videos")
    scenes: Mapped[list["Scene"]] = relationship(back_populates="video", cascade="all, delete-orphan",
                                                 order_by="Scene.idx")
    shorts: Mapped[list["Short"]] = relationship(back_populates="video", cascade="all, delete-orphan",
                                                 order_by="Short.idx")
    events: Mapped[list["Event"]] = relationship(back_populates="video", cascade="all, delete-orphan",
                                                 order_by="Event.id")
    bridges: Mapped[list["Bridge"]] = relationship(cascade="all, delete-orphan",
                                                   order_by="Bridge.id")


class Scene(Base):
    """Сцена ролика: кусок закадрового текста + сгенерированный видеоряд."""

    __tablename__ = "scenes"
    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    video_id: Mapped[int] = mapped_column(ForeignKey("videos.id", ondelete="CASCADE"), index=True)
    idx: Mapped[int] = mapped_column(Integer, default=0)
    heading: Mapped[str] = mapped_column(String(300), default="")
    narration: Mapped[str] = mapped_column(Text, default="")
    visual_prompt: Mapped[str] = mapped_column(Text, default="")
    audio_path: Mapped[str] = mapped_column(String(500), default="")
    piece_path: Mapped[str] = mapped_column(String(500), default="")  # готовая сцена-ролик
    piece_music_path: Mapped[str] = mapped_column(String(500), default="")  # она же с музыкой
    piece_sec: Mapped[float] = mapped_column(Float, default=0.0)
    # Готовая к публикации вертикальная версия сцены: заголовок в кадре, субтитры,
    # музыка. Лежит отдельно от piece_path, потому что заголовок в каждой сцене
    # длинного ролика выглядел бы нелепо.
    short_title: Mapped[str] = mapped_column(String(200), default="")
    # формат шортса: full — сгенерированный видеоряд, bust — оживлённый кадр,
    # paragraph — текст на тёмном фоне
    short_format: Mapped[str] = mapped_column(String(16), default="full")
    # метаданные публикации: теги и описание конкретно этого шортса
    short_tags: Mapped[str] = mapped_column(Text, default="")
    short_description: Mapped[str] = mapped_column(Text, default="")
    short_path: Mapped[str] = mapped_column(String(500), default="")
    thumb_path: Mapped[str] = mapped_column(String(500), default="")
    include: Mapped[bool] = mapped_column(Boolean, default=True)      # войдёт в длинный ролик
    clip_path: Mapped[str] = mapped_column(String(500), default="")
    clip_paths: Mapped[str] = mapped_column(Text, default="")
    clip_sources: Mapped[str] = mapped_column(Text, default="")  # generated | library
    audio_sec: Mapped[float] = mapped_column(Float, default=0.0)
    # Сколько раз сцену переозвучивали из-за обрыва на полуслове. Ограничитель:
    # если и переозвучка вышла резкой, повторять бесконечно нельзя — это деньги.
    voice_retries: Mapped[int] = mapped_column(Integer, default=0)
    clip_sec: Mapped[float] = mapped_column(Float, default=0.0)
    status: Mapped[str] = mapped_column(String(24), default="pending")
    error: Mapped[str] = mapped_column(Text, default="")

    video: Mapped[Video] = relationship(back_populates="scenes")


class Bridge(Base):
    """Связка между несмежными сценами: досоздаётся при сборке длинного ролика."""

    __tablename__ = "bridges"
    __table_args__ = (UniqueConstraint("video_id", "from_scene_id", "to_scene_id",
                                       name="uq_bridge_pair"),)
    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    video_id: Mapped[int] = mapped_column(ForeignKey("videos.id", ondelete="CASCADE"), index=True)
    from_scene_id: Mapped[int] = mapped_column(Integer)
    to_scene_id: Mapped[int] = mapped_column(Integer)
    narration: Mapped[str] = mapped_column(Text, default="")
    visual_prompt: Mapped[str] = mapped_column(Text, default="")
    audio_path: Mapped[str] = mapped_column(String(500), default="")
    clip_path: Mapped[str] = mapped_column(String(500), default="")
    piece_path: Mapped[str] = mapped_column(String(500), default="")
    piece_music_path: Mapped[str] = mapped_column(String(500), default="")
    clean_path: Mapped[str] = mapped_column(String(500), default="")
    piece_sec: Mapped[float] = mapped_column(Float, default=0.0)
    status: Mapped[str] = mapped_column(String(24), default="pending")
    error: Mapped[str] = mapped_column(Text, default="")
    created_at: Mapped[dt.datetime] = mapped_column(DateTime, default=utcnow)


class Short(Base):
    """Вертикальный шортс, нарезанный из готового ролика."""

    __tablename__ = "shorts"
    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    video_id: Mapped[int] = mapped_column(ForeignKey("videos.id", ondelete="CASCADE"), index=True)
    idx: Mapped[int] = mapped_column(Integer, default=0)
    title: Mapped[str] = mapped_column(String(300), default="")
    caption: Mapped[str] = mapped_column(Text, default="")
    hashtags: Mapped[str] = mapped_column(Text, default="")
    start_sec: Mapped[float] = mapped_column(Float, default=0.0)
    end_sec: Mapped[float] = mapped_column(Float, default=0.0)
    path: Mapped[str] = mapped_column(String(500), default="")
    status: Mapped[str] = mapped_column(String(24), default="pending")
    error: Mapped[str] = mapped_column(Text, default="")
    created_at: Mapped[dt.datetime] = mapped_column(DateTime, default=utcnow)

    video: Mapped[Video] = relationship(back_populates="shorts")


class ArchiveBatch(Base):
    """Загруженный архив с готовыми материалами: папка на серию.

    Архив приносит всё, кроме звука: обложку, тексты и описания для площадок.
    Заводу остаётся озвучить, разложить титры и собрать ролик, поэтому API
    тратится только на голос и фоновую музыку.
    """

    __tablename__ = "archive_batches"
    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    channel_id: Mapped[int] = mapped_column(
        ForeignKey("channels.id", ondelete="CASCADE"), index=True)
    name: Mapped[str] = mapped_column(String(200), default="")
    path: Mapped[str] = mapped_column(String(500), default="")
    # uploaded — берём обложку из архива, generate — рисуем свою,
    # auto — из архива, а если её там нет, то рисуем.
    cover_mode: Mapped[str] = mapped_column(String(16), default="uploaded")
    # Как собирать: "legacy" — обложка фоном под титрами (первый формат архива),
    # "story_v2" — пакет mens_circle.production.v2 с готовыми сюжетными кадрами,
    # раскадровкой и каноническим текстом речи.
    preset: Mapped[str] = mapped_column(String(24), default="legacy")
    package_format: Mapped[str] = mapped_column(String(64), default="")
    language: Mapped[str] = mapped_column(String(10), default="")
    # Сколько роликов в день выпускать. Ноль — все разом, без расписания.
    per_day: Mapped[int] = mapped_column(Integer, default=0)
    total: Mapped[int] = mapped_column(Integer, default=0)
    # Пауза: расписание такой архив пропускает, а нажатое вручную снимается с
    # очереди. Нужна, когда сотня серий уже поехала, а остановить её нечем.
    paused: Mapped[bool] = mapped_column(Boolean, default=False)

    # --- как собирать ролики этого архива ---
    # Пустая строка и ноль означают «как у канала»: архив не обязан переопределять
    # всё подряд, а настройки канала остаются общими по умолчанию.
    cover_dim: Mapped[float] = mapped_column(Float, default=0.42)
    cover_hold: Mapped[float] = mapped_column(Float, default=1.0)
    cover_zoom: Mapped[float] = mapped_column(Float, default=1.08)
    tail_sec: Mapped[float] = mapped_column(Float, default=1.2)

    subtitle_style: Mapped[str] = mapped_column(String(20), default="")
    title_font: Mapped[str] = mapped_column(String(32), default="")
    # Шрифт титров на весь архив. Пусто — Georgia из самого пакета.
    caption_font: Mapped[str] = mapped_column(String(64), default="")
    # Выравнивание строк: пусто — как записано в раскладке пакета.
    caption_align: Mapped[str] = mapped_column(String(10), default="")
    # "" — как у канала, "on" — включить, "off" — выключить
    subtitles_mode: Mapped[str] = mapped_column(String(8), default="")
    music_mode: Mapped[str] = mapped_column(String(8), default="")
    music_volume_db: Mapped[float] = mapped_column(Float, default=0.0)

    tts_model: Mapped[str] = mapped_column(String(120), default="")
    voice_id: Mapped[str] = mapped_column(String(120), default="")
    voice_name: Mapped[str] = mapped_column(String(120), default="")
    voice_speed: Mapped[float] = mapped_column(Float, default=0.0)

    outro_mode: Mapped[str] = mapped_column(String(8), default="")
    outro_url: Mapped[str] = mapped_column(String(300), default="")
    outro_title: Mapped[str] = mapped_column(String(120), default="")
    outro_about: Mapped[str] = mapped_column(Text, default="")
    outro_source: Mapped[str] = mapped_column(String(20), default="")
    outro_text: Mapped[str] = mapped_column(Text, default="")
    created_at: Mapped[dt.datetime] = mapped_column(DateTime, default=utcnow)

    items: Mapped[list["ArchiveItem"]] = relationship(
        back_populates="batch", cascade="all, delete-orphan")


class ArchiveItem(Base):
    """Одна папка архива — один будущий ролик."""

    __tablename__ = "archive_items"
    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    batch_id: Mapped[int] = mapped_column(
        ForeignKey("archive_batches.id", ondelete="CASCADE"), index=True)
    channel_id: Mapped[int] = mapped_column(Integer, index=True)
    folder: Mapped[str] = mapped_column(String(80), default="")
    idx: Mapped[int] = mapped_column(Integer, default=0, index=True)

    title: Mapped[str] = mapped_column(String(300), default="")
    # Язык материалов. Озвучка идёт на нём же: переводить нечего, текст готов.
    language: Mapped[str] = mapped_column(String(10), default="en")
    narration: Mapped[str] = mapped_column(Text, default="")
    hook: Mapped[str] = mapped_column(Text, default="")
    caption: Mapped[str] = mapped_column(Text, default="")
    hashtags: Mapped[str] = mapped_column(Text, default="")
    cover_path: Mapped[str] = mapped_column(String(500), default="")
    # Папка серии внутри распакованного архива: раскадровку, кадры и раскладку
    # титров читаем при сборке, а не тащим в базу целиком.
    source_dir: Mapped[str] = mapped_column(String(500), default="")
    # Сцены: [{"id": 0, "asset": "assets/01.png", "tokens": 15}, …]
    scenes_json: Mapped[str] = mapped_column(Text, default="")
    # Готовая раскладка титров пакета: зона, кегль и страницы с уже разбитыми
    # строками. Переносы считал автор пакета по метрикам Georgia — пересчитывать
    # их своими значит получить другие строки.
    captions_json: Mapped[str] = mapped_column(Text, default="")

    # planned — ждёт своей даты, queued/running — в работе, done, failed
    status: Mapped[str] = mapped_column(String(24), default="planned", index=True)
    scheduled_date: Mapped[Optional[dt.date]] = mapped_column(Date, nullable=True, index=True)
    video_path: Mapped[str] = mapped_column(String(500), default="")
    duration_sec: Mapped[float] = mapped_column(Float, default=0.0)
    credits: Mapped[float] = mapped_column(Float, default=0.0)
    error: Mapped[str] = mapped_column(Text, default="")
    created_at: Mapped[dt.datetime] = mapped_column(DateTime, default=utcnow)
    finished_at: Mapped[Optional[dt.datetime]] = mapped_column(DateTime, nullable=True)

    batch: Mapped[ArchiveBatch] = relationship(back_populates="items")


class Event(Base):
    """Лог событий по ролику — видно в веб-интерфейсе."""

    __tablename__ = "events"
    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    video_id: Mapped[Optional[int]] = mapped_column(ForeignKey("videos.id", ondelete="CASCADE"),
                                                    nullable=True, index=True)
    level: Mapped[str] = mapped_column(String(16), default="info")
    stage: Mapped[str] = mapped_column(String(80), default="")
    message: Mapped[str] = mapped_column(Text, default="")
    created_at: Mapped[dt.datetime] = mapped_column(DateTime, default=utcnow, index=True)

    video: Mapped[Optional[Video]] = relationship(back_populates="events")


class Job(Base):
    """Очередь фоновых задач."""

    __tablename__ = "jobs"
    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    kind: Mapped[str] = mapped_column(String(40), index=True)  # build_video | make_shorts | sync_prices
    video_id: Mapped[Optional[int]] = mapped_column(ForeignKey("videos.id", ondelete="CASCADE"),
                                                    nullable=True, index=True)
    payload: Mapped[str] = mapped_column(Text, default="{}")
    # pending | running | done | failed | cancelled
    status: Mapped[str] = mapped_column(String(16), default="pending", index=True)
    attempts: Mapped[int] = mapped_column(Integer, default=0)
    error: Mapped[str] = mapped_column(Text, default="")
    created_at: Mapped[dt.datetime] = mapped_column(DateTime, default=utcnow, index=True)
    started_at: Mapped[Optional[dt.datetime]] = mapped_column(DateTime, nullable=True)
    finished_at: Mapped[Optional[dt.datetime]] = mapped_column(DateTime, nullable=True)


class ScheduleRule(Base):
    """Сколько роликов и в какой день недели генерировать для канала."""

    __tablename__ = "schedule_rules"
    __table_args__ = (UniqueConstraint("channel_id", "weekday", name="uq_channel_weekday"),)
    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    channel_id: Mapped[int] = mapped_column(ForeignKey("channels.id", ondelete="CASCADE"), index=True)
    weekday: Mapped[int] = mapped_column(Integer)  # 0 = понедельник
    count: Mapped[int] = mapped_column(Integer, default=1)
    run_at: Mapped[str] = mapped_column(String(5), default="04:00")
    enabled: Mapped[bool] = mapped_column(Boolean, default=True)

    channel: Mapped[Channel] = relationship(back_populates="schedule_rules")


class PriceItem(Base):
    """Кэш прайс-листа KIE (обновляется раз в сутки)."""

    __tablename__ = "price_items"
    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    model_description: Mapped[str] = mapped_column(String(400), index=True)
    interface_type: Mapped[str] = mapped_column(String(40), default="", index=True)
    provider: Mapped[str] = mapped_column(String(120), default="")
    credit_price: Mapped[str] = mapped_column(String(40), default="")
    credit_unit: Mapped[str] = mapped_column(String(80), default="")
    usd_price: Mapped[str] = mapped_column(String(40), default="")
    discount_rate: Mapped[float] = mapped_column(Float, default=0.0)
    updated_at: Mapped[dt.datetime] = mapped_column(DateTime, default=utcnow, index=True)


class ModelPath(Base):
    """Список доступных моделей KIE (для выпадающих списков в UI)."""

    __tablename__ = "model_paths"
    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    path: Mapped[str] = mapped_column(String(200), unique=True)
    kind: Mapped[str] = mapped_column(String(20), default="other", index=True)  # video|image|chat|tts|other
    updated_at: Mapped[dt.datetime] = mapped_column(DateTime, default=utcnow)


class Footage(Base):
    """Загруженное видео для нарезки видеоряда (своя библиотека футажей)."""

    __tablename__ = "footage"
    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    # None — общий футаж, доступный всем каналам
    channel_id: Mapped[Optional[int]] = mapped_column(
        ForeignKey("channels.id", ondelete="CASCADE"), nullable=True, index=True)
    title: Mapped[str] = mapped_column(String(300), default="")
    tags: Mapped[str] = mapped_column(Text, default="")
    path: Mapped[str] = mapped_column(String(500), default="")
    source_url: Mapped[str] = mapped_column(String(600), default="")
    # откуда приехал футаж: "" — загружен вручную, иначе pexels/pixabay
    provider: Mapped[str] = mapped_column(String(40), default="")
    author: Mapped[str] = mapped_column(String(200), default="")
    license_note: Mapped[str] = mapped_column(String(300), default="")
    page_url: Mapped[str] = mapped_column(String(600), default="")
    duration_sec: Mapped[float] = mapped_column(Float, default=0.0)
    width: Mapped[int] = mapped_column(Integer, default=0)
    height: Mapped[int] = mapped_column(Integer, default=0)
    file_size: Mapped[int] = mapped_column(Integer, default=0)
    used_count: Mapped[int] = mapped_column(Integer, default=0)
    is_active: Mapped[bool] = mapped_column(Boolean, default=True)
    error: Mapped[str] = mapped_column(Text, default="")
    created_at: Mapped[dt.datetime] = mapped_column(DateTime, default=utcnow, index=True)

    channel: Mapped[Optional[Channel]] = relationship()


class MusicTrack(Base):
    """Фоновая музыка, сгенерированная Suno (переиспользуется между роликами канала)."""

    __tablename__ = "music_tracks"
    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    channel_id: Mapped[Optional[int]] = mapped_column(
        ForeignKey("channels.id", ondelete="CASCADE"), nullable=True, index=True)
    title: Mapped[str] = mapped_column(String(200), default="")
    style: Mapped[str] = mapped_column(Text, default="")
    path: Mapped[str] = mapped_column(String(500), default="")
    source_url: Mapped[str] = mapped_column(String(600), default="")
    duration_sec: Mapped[float] = mapped_column(Float, default=0.0)
    file_size: Mapped[int] = mapped_column(Integer, default=0)
    used_count: Mapped[int] = mapped_column(Integer, default=0)
    is_active: Mapped[bool] = mapped_column(Boolean, default=True)
    created_at: Mapped[dt.datetime] = mapped_column(DateTime, default=utcnow)

    channel: Mapped[Optional[Channel]] = relationship()


class ComfyWorkflow(Base):
    """Сохранённый граф ComfyUI в API-формате.

    Храним как есть, вместе с текстом: править его удобнее в панели, а не
    перезаливая файл после каждой мелкой правки.
    """

    __tablename__ = "comfy_workflows"
    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    name: Mapped[str] = mapped_column(String(200), default="")
    note: Mapped[str] = mapped_column(Text, default="")
    graph: Mapped[str] = mapped_column(Text, default="{}")
    # Какие метки нашлись в графе — показываем в панели, чтобы было видно,
    # что именно завод сможет подставить.
    placeholders: Mapped[str] = mapped_column(String(300), default="")
    fps: Mapped[int] = mapped_column(Integer, default=30)
    # Кратность длины: видеомодели принимают не любое число кадров. WAN и
    # Hunyuan хотят 4n+1, LTX — 8n+1, и 120 кадров вместо 121 такую модель либо
    # роняет, либо тихо меняет длину клипа. Единица — ограничения нет.
    frame_step: Mapped[int] = mapped_column(Integer, default=1)
    is_active: Mapped[bool] = mapped_column(Boolean, default=True)
    created_at: Mapped[dt.datetime] = mapped_column(DateTime, default=utcnow)


class ComfyTask(Base):
    """Задание на кадр для локального агента.

    Второй способ работы с ComfyUI. В прямом режиме сервер сам ходит на ваш
    компьютер, и для этого ComfyUI приходится открывать наружу. Здесь наоборот:
    агент на компьютере сам спрашивает у сервера работу и приносит результат,
    поэтому ничего открывать не нужно — соединение идёт изнутри.
    """

    __tablename__ = "comfy_tasks"
    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    video_id: Mapped[Optional[int]] = mapped_column(
        ForeignKey("videos.id", ondelete="CASCADE"), nullable=True, index=True)
    scene_id: Mapped[Optional[int]] = mapped_column(Integer, nullable=True, index=True)
    part: Mapped[int] = mapped_column(Integer, default=0)
    workflow_id: Mapped[Optional[int]] = mapped_column(Integer, nullable=True)

    prompt: Mapped[str] = mapped_column(Text, default="")
    seconds: Mapped[float] = mapped_column(Float, default=5.0)
    width: Mapped[int] = mapped_column(Integer, default=1080)
    height: Mapped[int] = mapped_column(Integer, default=1920)

    # pending — ждёт агента, taken — агент взял, done — готово, failed — не вышло
    status: Mapped[str] = mapped_column(String(16), default="pending", index=True)
    result_path: Mapped[str] = mapped_column(String(500), default="")
    error: Mapped[str] = mapped_column(Text, default="")
    agent: Mapped[str] = mapped_column(String(120), default="")
    attempts: Mapped[int] = mapped_column(Integer, default=0)

    created_at: Mapped[dt.datetime] = mapped_column(DateTime, default=utcnow)
    taken_at: Mapped[Optional[dt.datetime]] = mapped_column(DateTime, nullable=True)
    finished_at: Mapped[Optional[dt.datetime]] = mapped_column(DateTime, nullable=True)


class Reference(Base):
    """Свой референс канала: образец стиля для генераций.

    Генераторы изображений не принимают «сделай как здесь» без самой картинки,
    поэтому у референса две стороны: файл, который можно отдать модели входом, и
    словесное описание, которое подмешивается в промпт.
    """

    __tablename__ = "references"
    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    channel_id: Mapped[int] = mapped_column(
        ForeignKey("channels.id", ondelete="CASCADE"), index=True)
    # cover — обложки, background — фоны сцен, loop — исходник для зацикленного
    # клипа, style — общий стиль канала
    kind: Mapped[str] = mapped_column(String(20), default="style", index=True)
    title: Mapped[str] = mapped_column(String(200), default="")
    # Чем именно этот образец хорош — эта фраза и уходит в промпт.
    note: Mapped[str] = mapped_column(Text, default="")
    path: Mapped[str] = mapped_column(String(500), default="")
    thumb_path: Mapped[str] = mapped_column(String(500), default="")
    media_type: Mapped[str] = mapped_column(String(20), default="image")
    # Ссылка на файл в хранилище KIE. Генератор берёт картинки по HTTP, а панель
    # может стоять за туннелем и наружу не смотреть, поэтому файл заливается им.
    remote_url: Mapped[str] = mapped_column(String(600), default="")
    width: Mapped[int] = mapped_column(Integer, default=0)
    height: Mapped[int] = mapped_column(Integer, default=0)
    file_size: Mapped[int] = mapped_column(Integer, default=0)
    is_active: Mapped[bool] = mapped_column(Boolean, default=True)
    created_at: Mapped[dt.datetime] = mapped_column(DateTime, default=utcnow)

    channel: Mapped[Channel] = relationship()


class LoopClip(Base):
    """Зацикленный видеофон для формата шортса.

    Один клип на формат: генерируется однажды через pixverse и дальше
    переиспользуется во всех шортсах этого формата. Это дешевле процедурной
    анимации по качеству движения и дешевле генерации видеоряда по деньгам —
    платим один раз, а не за каждую сцену.
    """

    __tablename__ = "loop_clips"
    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    fmt: Mapped[str] = mapped_column(String(40), index=True, default="")
    title: Mapped[str] = mapped_column(String(200), default="")
    path: Mapped[str] = mapped_column(String(500), default="")
    poster_path: Mapped[str] = mapped_column(String(500), default="")
    prompt: Mapped[str] = mapped_column(Text, default="")
    image_prompt: Mapped[str] = mapped_column(Text, default="")
    model: Mapped[str] = mapped_column(String(120), default="")
    source_url: Mapped[str] = mapped_column(String(600), default="")
    duration_sec: Mapped[float] = mapped_column(Float, default=0.0)
    width: Mapped[int] = mapped_column(Integer, default=0)
    height: Mapped[int] = mapped_column(Integer, default=0)
    file_size: Mapped[int] = mapped_column(Integer, default=0)
    credits: Mapped[float] = mapped_column(Float, default=0.0)
    is_active: Mapped[bool] = mapped_column(Boolean, default=True)
    created_at: Mapped[dt.datetime] = mapped_column(DateTime, default=utcnow)


class Voice(Base):
    """Каталог голосов озвучки."""

    __tablename__ = "voices"
    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    provider: Mapped[str] = mapped_column(String(40), default="elevenlabs", index=True)
    voice_id: Mapped[str] = mapped_column(String(120), index=True)
    name: Mapped[str] = mapped_column(String(120))
    description: Mapped[str] = mapped_column(String(300), default="")
    gender: Mapped[str] = mapped_column(String(16), default="")
    preview_url: Mapped[str] = mapped_column(String(400), default="")
    is_favorite: Mapped[bool] = mapped_column(Boolean, default=False)


class MusicVideo(Base):
    """Длинное музыкальное видео: сшитый микс поверх зацикленной заставки.

    Отличие от шортса принципиальное: здесь нет ни сценария, ни озвучки, ни
    контент-плана. Единица работы — один микс на 25–35 минут: Suno выдаёт
    несколько инструментальных треков в выбранном жанре, сервер сшивает их
    мягкими переходами, а картинка — один зацикленный клип, который крутится по
    кругу всё время. Поэтому платим за музыку и одну заставку, а не за минуты
    видео: тридцатиминутный ролик стоит столько же, сколько восьмисекундный луп.
    """

    __tablename__ = "music_videos"
    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    title: Mapped[str] = mapped_column(String(300), default="")
    style: Mapped[str] = mapped_column(String(60), default="", index=True)
    style_label: Mapped[str] = mapped_column(String(200), default="")
    # Канал музыкальных видео, если трек сделан в нём: от канала берутся жанр,
    # исследование и оформление (интро, заставка, оутро).
    channel_id: Mapped[int] = mapped_column(Integer, default=0, index=True)
    intro_path: Mapped[str] = mapped_column(String(500), default="")
    outro_path: Mapped[str] = mapped_column(String(500), default="")
    minutes: Mapped[int] = mapped_column(Integer, default=30)
    suno_model: Mapped[str] = mapped_column(String(40), default="")
    # Язык заголовка и описания. Хранится у микса, чтобы пересборка не
    # переписала русское описание английским.
    language: Mapped[str] = mapped_column(String(8), default="en")
    status: Mapped[str] = mapped_column(String(24), default="queued", index=True)
    stage: Mapped[str] = mapped_column(String(40), default="queued")
    # Распакованный архив с готовыми материалами, если микс собирается из него.
    # Пустая строка — обычная генерация с нуля.
    source_dir: Mapped[str] = mapped_column(String(500), default="")
    # Тайм-код, пришедший в архиве. Он важнее нашего счёта: мастер сведён не
    # нами, и длины сырья к нему не сходятся.
    chapters_src: Mapped[str] = mapped_column(Text, default="")
    # В архиве пришла сведённая дорожка. Тогда музыка не генерируется совсем и
    # длина микса равна длине мастера: дописывать к готовой работе чужое — брак.
    master_ready: Mapped[bool] = mapped_column(Boolean, default=False)
    # Текстовое исследование: какая нужна композиция, какие инструменты, как она
    # развивается, какой бэк-вокал. Задаёт, что именно заказывать у Suno, вместо
    # общего описания жанра.
    brief: Mapped[str] = mapped_column(Text, default="")
    # Готовые задания на композиции, выжатые из исследования. Храним, чтобы
    # пересборка не просила модель пересказывать то же самое заново.
    plan_json: Mapped[str] = mapped_column(Text, default="")
    # Заставка из архива: готовый клип или картинка. Если она есть, генерировать
    # заставку не нужно — это самая дорогая часть, и платить за неё незачем.
    backdrop_src: Mapped[str] = mapped_column(String(500), default="")
    # Заставка: своя запись в библиотеке лупов, чтобы один клип обслуживал все
    # миксы этого жанра. Здесь — только путь к файлу, который реально взят.
    loop_id: Mapped[int] = mapped_column(Integer, default=0)
    loop_path: Mapped[str] = mapped_column(String(500), default="")
    poster_path: Mapped[str] = mapped_column(String(500), default="")
    audio_path: Mapped[str] = mapped_column(String(500), default="")
    video_path: Mapped[str] = mapped_column(String(500), default="")
    duration_sec: Mapped[float] = mapped_column(Float, default=0.0)
    width: Mapped[int] = mapped_column(Integer, default=0)
    height: Mapped[int] = mapped_column(Integer, default=0)
    file_size: Mapped[int] = mapped_column(Integer, default=0)
    crossfade_sec: Mapped[float] = mapped_column(Float, default=0.0)
    # Только цена заставки, и только если её генерировали для этого микса: Suno
    # в ответе стоимость заявки не возвращает, а заставка из библиотеки уже
    # оплачена другим миксом.
    # Готовая обвязка для публикации: заголовок, описание, теги и тайм-код.
    yt_title: Mapped[str] = mapped_column(String(300), default="")
    description: Mapped[str] = mapped_column(Text, default="")
    tags: Mapped[str] = mapped_column(Text, default="")
    tracklist: Mapped[str] = mapped_column(Text, default="")
    credits: Mapped[float] = mapped_column(Float, default=0.0)
    error: Mapped[str] = mapped_column(Text, default="")
    # Публикация. Пустой youtube_id значит «не выкладывали»; youtube_state
    # отличает «в очереди» и «не вышло» от просто незаполненного.
    # Обложка для публикации: рисуется по просьбе при сборке и ничего не стоит.
    cover_path: Mapped[str] = mapped_column(String(500), default="")
    want_cover: Mapped[bool] = mapped_column(Boolean, default=False)
    youtube_id: Mapped[str] = mapped_column(String(40), default="")
    youtube_url: Mapped[str] = mapped_column(String(200), default="")
    youtube_privacy: Mapped[str] = mapped_column(String(20), default="")
    youtube_state: Mapped[str] = mapped_column(String(20), default="")
    youtube_error: Mapped[str] = mapped_column(Text, default="")
    created_at: Mapped[dt.datetime] = mapped_column(DateTime, default=utcnow)
    started_at: Mapped[Optional[dt.datetime]] = mapped_column(DateTime, nullable=True)
    finished_at: Mapped[Optional[dt.datetime]] = mapped_column(DateTime, nullable=True)

    tracks: Mapped[list["MusicVideoTrack"]] = relationship(
        back_populates="video", cascade="all, delete-orphan",
        order_by="MusicVideoTrack.idx")
    shorts: Mapped[list["MusicShort"]] = relationship(
        cascade="all, delete-orphan", order_by="MusicShort.id.desc()")

    @property
    def plan(self) -> list:
        """Задания на композиции списком — для показа в панели."""
        rows = JSONMixin.loads(self.plan_json, [])
        return [str(row) for row in rows] if isinstance(rows, list) else []


class MusicVideoTrack(Base):
    """Один трек микса.

    Треки лежат отдельными записями, а не списком в JSON, чтобы сорвавшуюся
    генерацию можно было добрать по одной штуке: Suno падает нередко, а
    переплачивать за уже скачанные треки не за что.
    """

    __tablename__ = "music_video_tracks"
    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    video_id: Mapped[int] = mapped_column(
        ForeignKey("music_videos.id", ondelete="CASCADE"), index=True)
    idx: Mapped[int] = mapped_column(Integer, default=0)
    title: Mapped[str] = mapped_column(String(300), default="")
    prompt: Mapped[str] = mapped_column(Text, default="")
    model: Mapped[str] = mapped_column(String(40), default="")
    path: Mapped[str] = mapped_column(String(500), default="")
    source_url: Mapped[str] = mapped_column(String(600), default="")
    duration_sec: Mapped[float] = mapped_column(Float, default=0.0)
    # Секунда, с которой трек слышно в готовом миксе: с учётом перекрытий
    # переходов, иначе тайм-код в описании разъезжается к концу.
    start_sec: Mapped[float] = mapped_column(Float, default=0.0)
    credits: Mapped[float] = mapped_column(Float, default=0.0)
    created_at: Mapped[dt.datetime] = mapped_column(DateTime, default=utcnow)

    video: Mapped[MusicVideo] = relationship(back_populates="tracks")


class MusicChannel(Base):
    """Канал музыкальных видео: один жанр, своя библиотека клипов.

    Отличие от обычного канала завода принципиальное: здесь нет ни контент-плана,
    ни сценариев, ни озвучки. Канал — это постоянное оформление: интро, набор
    зацикленных заставок и оутро, которые делаются ОДИН раз и дальше обслуживают
    все треки. Жанр у канала один и в форме не выбирается — создание трека
    сводится к выбору длительности.
    """

    __tablename__ = "music_channels"
    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    name: Mapped[str] = mapped_column(String(200))
    slug: Mapped[str] = mapped_column(String(80), unique=True, index=True)
    style: Mapped[str] = mapped_column(String(60), default="chillstep")
    minutes: Mapped[int] = mapped_column(Integer, default=30)
    language: Mapped[str] = mapped_column(String(8), default="en")
    suno_model: Mapped[str] = mapped_column(String(40), default="")
    # Исследование канала: оно задаёт заказ музыки для всех его треков.
    brief: Mapped[str] = mapped_column(Text, default="")
    # Заголовки для заставок, по одному в строке. Сцену рисует генератор без
    # единой буквы, а типографику наносим сами — и потому её можно менять, не
    # трогая сцену.
    titles: Mapped[str] = mapped_column(Text, default="")
    subtitle: Mapped[str] = mapped_column(String(200), default="")
    # Столбики частот по центру кадра. Строятся из самой музыки, поэтому ролик
    # приходится перекодировать целиком — это дороже по времени, но нарисованный
    # «эквалайзер», живущий своей жизнью, зритель раскусывает мгновенно.
    equalizer: Mapped[bool] = mapped_column(Boolean, default=True)
    # Карточка «сейчас играет» в левом нижнем углу: что звучит в этот момент,
    # с ходом композиции. Границы берутся из тайм-кода, поэтому не врут.
    now_playing: Mapped[bool] = mapped_column(Boolean, default=True)
    position: Mapped[int] = mapped_column(Integer, default=0)
    is_active: Mapped[bool] = mapped_column(Boolean, default=True)
    created_at: Mapped[dt.datetime] = mapped_column(DateTime, default=utcnow)

    assets: Mapped[list["MusicAsset"]] = relationship(
        back_populates="channel", cascade="all, delete-orphan",
        order_by="MusicAsset.kind, MusicAsset.id")


class MusicAsset(Base):
    """Клип оформления канала: интро, зацикленная заставка или оутро.

    Хранятся одинаково, откуда бы ни пришли — загружены файлом или сгенерированы.
    Заставок у канала несколько: при сборке трека берётся случайная, и лента
    перестаёт выглядеть одним роликом, переклеенным сто раз. Платим за них один
    раз на канал, дальше траты только на музыку.
    """

    __tablename__ = "music_assets"
    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    channel_id: Mapped[int] = mapped_column(
        ForeignKey("music_channels.id", ondelete="CASCADE"), index=True)
    # intro | loop | outro
    kind: Mapped[str] = mapped_column(String(16), default="loop", index=True)
    title: Mapped[str] = mapped_column(String(200), default="")
    path: Mapped[str] = mapped_column(String(500), default="")
    poster_path: Mapped[str] = mapped_column(String(500), default="")
    prompt: Mapped[str] = mapped_column(Text, default="")
    image_prompt: Mapped[str] = mapped_column(Text, default="")
    model: Mapped[str] = mapped_column(String(120), default="")
    # uploaded | generated | bundled (приехал вместе с кодом)
    source: Mapped[str] = mapped_column(String(16), default="uploaded")
    duration_sec: Mapped[float] = mapped_column(Float, default=0.0)
    width: Mapped[int] = mapped_column(Integer, default=0)
    height: Mapped[int] = mapped_column(Integer, default=0)
    file_size: Mapped[int] = mapped_column(Integer, default=0)
    credits: Mapped[float] = mapped_column(Float, default=0.0)
    used_count: Mapped[int] = mapped_column(Integer, default=0)
    is_active: Mapped[bool] = mapped_column(Boolean, default=True)
    created_at: Mapped[dt.datetime] = mapped_column(DateTime, default=utcnow)

    channel: Mapped[MusicChannel] = relationship(back_populates="assets")


class MusicShort(Base):
    """Вертикальный отрывок музыкального ролика для шортсов.

    Делается из уже собранного микса, поэтому не стоит ничего: берётся кусок
    готового звука и та же заставка, развёрнутая под 9:16. Отрывков у микса
    может быть несколько — каждый со своей секунды.
    """

    __tablename__ = "music_shorts"
    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    video_id: Mapped[int] = mapped_column(
        ForeignKey("music_videos.id", ondelete="CASCADE"), index=True)
    title: Mapped[str] = mapped_column(String(300), default="")
    path: Mapped[str] = mapped_column(String(500), default="")
    poster_path: Mapped[str] = mapped_column(String(500), default="")
    # Вертикальная обложка 1080×1920 — рисуется из кадра самого отрывка.
    cover_path: Mapped[str] = mapped_column(String(500), default="")
    start_sec: Mapped[float] = mapped_column(Float, default=0.0)
    duration_sec: Mapped[float] = mapped_column(Float, default=0.0)
    file_size: Mapped[int] = mapped_column(Integer, default=0)
    created_at: Mapped[dt.datetime] = mapped_column(DateTime, default=utcnow)
