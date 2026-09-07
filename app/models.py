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
    target_minutes: Mapped[float] = mapped_column(Float, default=8.0)
    scene_count: Mapped[int] = mapped_column(Integer, default=8)

    visual_style: Mapped[str] = mapped_column(Text, default="")
    script_style: Mapped[str] = mapped_column(Text, default="")
    thumb_style: Mapped[str] = mapped_column(Text, default="")

    burn_subtitles: Mapped[bool] = mapped_column(Boolean, default=True)
    make_shorts: Mapped[bool] = mapped_column(Boolean, default=True)
    shorts_count: Mapped[int] = mapped_column(Integer, default=3)
    background_music: Mapped[bool] = mapped_column(Boolean, default=False)

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
    stage: Mapped[str] = mapped_column(String(80), default="")
    progress: Mapped[int] = mapped_column(Integer, default=0)
    error: Mapped[str] = mapped_column(Text, default="")

    script: Mapped[str] = mapped_column(Text, default="")
    hook: Mapped[str] = mapped_column(Text, default="")
    description: Mapped[str] = mapped_column(Text, default="")
    tags: Mapped[str] = mapped_column(Text, default="")
    title_variants: Mapped[str] = mapped_column(Text, default="")

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
    clip_path: Mapped[str] = mapped_column(String(500), default="")
    audio_sec: Mapped[float] = mapped_column(Float, default=0.0)
    clip_sec: Mapped[float] = mapped_column(Float, default=0.0)
    status: Mapped[str] = mapped_column(String(24), default="pending")
    error: Mapped[str] = mapped_column(Text, default="")

    video: Mapped[Video] = relationship(back_populates="scenes")


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
