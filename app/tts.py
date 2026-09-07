"""Синтез речи. Основной провайдер — ElevenLabs через KIE, запасной — Gemini TTS."""
from __future__ import annotations

import json
import logging
import threading
import time
from dataclasses import dataclass
from pathlib import Path

from . import storage
from .kie import KieClient, KieError, extract_urls

log = logging.getLogger("cf.tts")

# Предохранитель: если провайдер стабильно падает, временно перестаём его дёргать —
# иначе каждая сцена ждёт таймаут впустую.
_BREAKER_THRESHOLD = 2
_BREAKER_COOLDOWN = 600.0
_breaker_lock = threading.Lock()
_breaker: dict[str, tuple[int, float]] = {}


def _breaker_open(model: str) -> bool:
    with _breaker_lock:
        fails, until = _breaker.get(model, (0, 0.0))
        if until and time.time() < until:
            return True
        if until and time.time() >= until:
            _breaker.pop(model, None)
        return False


def _breaker_fail(model: str) -> None:
    with _breaker_lock:
        fails, _until = _breaker.get(model, (0, 0.0))
        fails += 1
        until = time.time() + _BREAKER_COOLDOWN if fails >= _BREAKER_THRESHOLD else 0.0
        _breaker[model] = (fails, until)
        if until:
            log.warning("Провайдер озвучки %s временно отключён на %.0f мин",
                        model, _BREAKER_COOLDOWN / 60)


def _breaker_ok(model: str) -> None:
    with _breaker_lock:
        _breaker.pop(model, None)


def breaker_state() -> dict[str, tuple[int, float]]:
    with _breaker_lock:
        return dict(_breaker)

ELEVEN_MODELS = {
    "elevenlabs/text-to-speech-multilingual-v2",
    "elevenlabs/text-to-speech-turbo-2-5",
}
GEMINI_TTS_MODELS = {
    "google/gemini-3-1-flash-tts",
    "google/gemini-2-5-pro-tts",
}

# Голоса Gemini TTS (используются как запасные и как самостоятельный пресет).
GEMINI_VOICES = [
    ("Charon", "Мужской, спокойный и уверенный", "male"),
    ("Fenrir", "Мужской, низкий и весомый", "male"),
    ("Orus", "Мужской, тёплый рассказчик", "male"),
    ("Puck", "Мужской, энергичный", "male"),
    ("Enceladus", "Мужской, мягкий и вдумчивый", "male"),
    ("Iapetus", "Мужской, нейтральный дикторский", "male"),
    ("Kore", "Женский, чёткий и деловой", "female"),
    ("Aoede", "Женский, тёплый и дружелюбный", "female"),
    ("Leda", "Женский, лёгкий и молодой", "female"),
    ("Autonoe", "Женский, спокойный рассказчик", "female"),
    ("Despina", "Женский, мягкий и доверительный", "female"),
    ("Vindemiatrix", "Женский, зрелый и уверенный", "female"),
]

GEMINI_STYLES = ["Empathetic", "Newscaster", "Vocal Smile", "Deadpan", "Promo/Hype", "Whisper"]
GEMINI_PACES = ["Natural", "Rapid Fire", "The Drift", "Staccato"]


@dataclass
class TTSResult:
    path: Path
    duration: float
    credits: float
    provider: str


class TTSError(RuntimeError):
    pass


def load_voice_catalog() -> list[dict]:
    """Каталог голосов ElevenLabs, выдернутый из документации KIE."""
    path = Path(__file__).with_name("voices_catalog.json")
    if not path.exists():
        return []
    return json.loads(path.read_text(encoding="utf-8"))


def _eleven_payload(text: str, voice_id: str, *, stability: float, similarity: float,
                    speed: float) -> dict:
    # Внимание: KIE ждёт ключ "voice" (в него кладётся voiceId), а не "voice_id".
    return {
        "text": text,
        "voice": voice_id,
        "stability": max(0.0, min(1.0, stability)),
        "similarity_boost": max(0.0, min(1.0, similarity)),
        "speed": max(0.7, min(1.2, speed)),
    }


def _gemini_payload(text: str, voice_name: str, *, style: str, pace: str, profile: str) -> dict:
    return {
        "temperature": 1,
        "speakers": [{
            "speaker_id": "Speaker 1",
            "voice_name": voice_name if voice_name in {v[0] for v in GEMINI_VOICES} else "Charon",
            "audio_profile": profile,
            "accent": "Neutral",
            "style": style if style in GEMINI_STYLES else "Empathetic",
            "pace": pace if pace in GEMINI_PACES else "Natural",
        }],
        "dialogue_turns": [{"speaker_id": "Speaker 1", "text": text}],
    }


def synthesize(client: KieClient, text: str, dest: Path, *, model: str, voice_id: str,
               stability: float = 0.45, similarity: float = 0.8, speed: float = 1.0,
               fallback_model: str = "google/gemini-3-1-flash-tts",
               fallback_voice: str = "Charon",
               voice_profile: str = "Спокойный уверенный голос рассказчика, русский язык",
               allow_fallback: bool = True) -> TTSResult:
    """Озвучивает текст. При ошибке основного провайдера переключается на запасной."""
    text = (text or "").strip()
    if not text:
        raise TTSError("пустой текст для озвучки")

    attempts: list[tuple[str, dict, str]] = []
    if model in ELEVEN_MODELS:
        attempts.append((model, _eleven_payload(text, voice_id, stability=stability,
                                                similarity=similarity, speed=speed), "elevenlabs"))
    elif model in GEMINI_TTS_MODELS:
        attempts.append((model, _gemini_payload(text, voice_id or fallback_voice,
                                                style="Empathetic", pace="Natural",
                                                profile=voice_profile), "gemini"))
    else:  # незнакомая модель — пробуем как ElevenLabs
        attempts.append((model, _eleven_payload(text, voice_id, stability=stability,
                                                similarity=similarity, speed=speed), "custom"))

    if allow_fallback and fallback_model and fallback_model != model:
        attempts.append((fallback_model, _gemini_payload(text, fallback_voice, style="Empathetic",
                                                         pace="Natural", profile=voice_profile),
                         "gemini-fallback"))

    errors: list[str] = []
    for index, (tts_model, payload, provider) in enumerate(attempts):
        # Последнюю попытку делаем всегда: иначе озвучивать будет нечем.
        if index < len(attempts) - 1 and _breaker_open(tts_model):
            errors.append(f"{tts_model}: временно отключён после серии ошибок")
            continue
        try:
            result = client.run_task(tts_model, payload, timeout=900, poll=4)
            urls = extract_urls(result)
            audio = next((u for u in urls if u.split("?")[0].lower().endswith(
                (".mp3", ".wav", ".m4a", ".ogg", ".flac"))), None) or (urls[0] if urls else None)
            if not audio:
                raise TTSError(f"{tts_model}: в ответе нет ссылки на аудио")
            ext = storage.guess_ext(audio, ".mp3")
            path = dest.with_suffix(ext)
            storage.download(audio, path)
            duration = storage.media_duration(path)
            if duration <= 0:
                raise TTSError(f"{tts_model}: скачан пустой аудиофайл")
            _breaker_ok(tts_model)
            return TTSResult(path=path, duration=duration,
                             credits=float(result.get("_credits") or 0), provider=provider)
        except (KieError, TTSError, OSError) as exc:
            log.warning("TTS %s не сработал: %s", tts_model, exc)
            _breaker_fail(tts_model)
            errors.append(f"{tts_model}: {exc}")

    raise TTSError("Озвучка не удалась. " + " | ".join(errors))
