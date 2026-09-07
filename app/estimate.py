"""Оценка стоимости ролика по актуальному прайсу KIE."""
from __future__ import annotations

import math
import re
from dataclasses import dataclass, field

from sqlalchemy import select
from sqlalchemy.orm import Session

from .models import Channel, PriceItem
from . import settings_store as st

WORDS_PER_MINUTE = 145
CHARS_PER_WORD = 6.5


@dataclass
class CostLine:
    label: str
    detail: str
    credits: float


@dataclass
class CostEstimate:
    lines: list[CostLine] = field(default_factory=list)
    warnings: list[str] = field(default_factory=list)

    @property
    def credits(self) -> float:
        return round(sum(line.credits for line in self.lines), 2)

    def usd(self, rate: float) -> float:
        return round(self.credits * rate, 3)


def _num(value: str) -> float | None:
    match = re.search(r"[\d]+(?:[.,]\d+)?", value or "")
    if not match:
        return None
    try:
        return float(match.group(0).replace(",", "."))
    except ValueError:
        return None


def _model_key(model: str) -> str:
    """«bytedance/seedance-1.5-pro» → «seedance 1 5 pro» — для нечёткого поиска в прайсе."""
    tail = (model or "").split("/")[-1]
    return re.sub(r"[^a-z0-9]+", " ", tail.lower()).strip()


IGNORE_TOKENS = {"openai", "api", "preview"}
# Слова-варианты модели: если их нет в запрошенном имени, строка прайса относится
# к другой модификации («nano-banana-2» против «nano-banana-2-lite»).
VARIANT_TOKENS = {"lite", "pro", "mini", "fast", "turbo", "max", "ultra", "plus", "standard"}


def _find(rows: list[PriceItem], model: str, must: list[str] = (),
          prefer: list[str] = ()) -> PriceItem | None:
    """Нечёткий поиск строки прайса по имени модели.

    Прайс ведётся человеческими описаниями («Google nano banana 2, 1K»), поэтому сверяем
    по токенам и выбираем самое близкое совпадение, а не первое попавшееся: иначе
    «nano-banana-2» матчится на «nano-banana-2-lite».
    """
    tokens = [t for t in _model_key(model).split() if t and t not in IGNORE_TOKENS]
    if not tokens:
        return None

    best: tuple[float, PriceItem] | None = None
    for row in rows:
        text = row.model_description.lower()
        norm_tokens = [t for t in re.sub(r"[^a-z0-9]+", " ", text).split() if t]
        norm = " ".join(norm_tokens)
        if not all(t in norm_tokens for t in tokens):
            continue
        if any(m.lower() not in text for m in must):
            continue
        # Чем меньше «лишних» слов в описании, тем точнее попадание.
        outsiders = [t for t in norm_tokens if t not in tokens]
        variants = [t for t in outsiders if t in VARIANT_TOKENS]
        score = (sum(2.0 for p in prefer if p.lower() in text)
                 - len(outsiders) * 0.35 - len(variants) * 3.0)
        if best is None or score > best[0]:
            best = (score, row)
    return best[1] if best else None


def estimate_channel(session: Session, channel: Channel) -> CostEstimate:
    """Сколько примерно стоит один ролик этого канала при текущих настройках."""
    rows = session.execute(select(PriceItem)).scalars().all()
    est = CostEstimate()
    if not rows:
        est.warnings.append("Прайс KIE ещё не загружен — обновите его на вкладке «Цены KIE».")
        return est

    seconds = channel.target_minutes * 60.0
    chars = channel.target_minutes * WORDS_PER_MINUTE * CHARS_PER_WORD

    # --- видеоряд ---
    coverage = max(6, int(getattr(channel, "clip_coverage_sec", 20) or 20))
    per_scene = seconds / max(channel.scene_count, 1)
    clips = channel.scene_count * max(1, min(6, math.ceil(per_scene / coverage)))
    clip_seconds = clips * channel.clip_duration
    video_row = _find(rows, channel.video_model,
                      prefer=[channel.resolution, "no video", "without audio"])
    if video_row is None:
        est.warnings.append(f"В прайсе нет строки для модели видео «{channel.video_model}».")
    else:
        rate = _num(video_row.credit_price) or 0.0
        unit = (video_row.credit_unit or "").lower()
        amount = rate * (clip_seconds if "second" in unit else clips)
        est.lines.append(CostLine(
            "Видеоряд", f"{clips} клипов × {channel.clip_duration}с — {video_row.model_description}",
            amount))

    # --- озвучка ---
    tts_row = _find(rows, channel.tts_model)
    if tts_row is None:
        est.warnings.append(f"В прайсе нет строки для озвучки «{channel.tts_model}».")
    else:
        rate = _num(tts_row.credit_price) or 0.0
        unit = (tts_row.credit_unit or "").lower()
        if "1000 characters" in unit or "1000 character" in unit:
            amount = rate * chars / 1000.0
        elif "million" in unit:
            amount = rate * (seconds * 30) / 1_000_000.0  # ~30 аудио-токенов в секунду
        else:
            amount = rate
        est.lines.append(CostLine("Озвучка", f"≈{chars:.0f} знаков — {tts_row.model_description}",
                                  amount))

    # --- обложка ---
    image_row = _find(rows, channel.image_model, prefer=["1k"])
    if image_row is not None:
        est.lines.append(CostLine("Обложка", image_row.model_description,
                                  _num(image_row.credit_price) or 0.0))

    # --- текстовая модель (сценарий + метаданные + шортсы) ---
    chat_in = _find(rows, channel.chat_model, must=["input"])
    chat_out = _find(rows, channel.chat_model, must=["output"])
    if chat_in is not None and chat_out is not None:
        in_tokens = 4_000
        out_tokens = chars / 3.0 + 2_000
        amount = ((_num(chat_in.credit_price) or 0) * in_tokens
                  + (_num(chat_out.credit_price) or 0) * out_tokens) / 1_000_000.0
        est.lines.append(CostLine("Тексты", "сценарий, метаданные, отбор шортсов", amount))

    return est


def channel_estimate_context(session: Session, channel: Channel) -> dict:
    est = estimate_channel(session, channel)
    rate = st.get_float(session, "usd_per_credit", 0.005)
    return {"estimate": est, "estimate_usd": est.usd(rate), "usd_per_credit": rate}
