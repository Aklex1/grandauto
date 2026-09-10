"""Синтез речи. Основной провайдер — ElevenLabs через KIE, запасной — Gemini TTS."""
from __future__ import annotations

import json
import logging
import threading
import time
from dataclasses import dataclass
from pathlib import Path

from . import config, storage
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


# Провайдеры молча обрезают длинный текст по своему внутреннему лимиту: ответ
# приходит успешный, аудио целое, но фраза заканчивается на полуслове. Поэтому
# длинный текст режем сами по границам предложений и склеиваем — так обрезать
# нечего. Лимит взят заметно ниже известных провайдерских.
CHUNK_CHARS = 600

# Провайдеры обрывают текст на разной длине, и заявленные лимиты не помогают:
# gemini-tts срезал текст на ~330 знаках, хотя в кусок 600 он уложился целиком.
# Поэтому при неполной озвучке режем мельче и пробуем снова.
CHUNK_STEPS = (600, 320, 180)

# Сколько раз повторяем у того же провайдера при временной ошибке. 500 Internal
# Error у синтеза — обычное дело, и со второго раза он чаще всего проходит.
RETRY_ATTEMPTS = 2

# Русская речь диктора — примерно 15 знаков в секунду. Точность не нужна: порог
# служит только для того, чтобы заметить обрыв, а не измерить темп.
CHARS_PER_SECOND = 15.0

# Ниже этой доли ожидаемой длительности считаем озвучку оборванной.
# 0.6 стоял слишком низко и пропускал явный обрыв: 22 секунды из 36 — это 0.61,
# то есть треть текста не прозвучала, а проверка молчала. Порог разделяет случаи:
# быстрый голос (20 знаков в секунду вместо 15) даёт 0.75 и проходит, а обрыв
# на трети текста — 0.62 и отсекается.
SHORT_AUDIO_RATIO = 0.7

SENTENCE_END = ".!?…"

# Пауза между склеенными кусками, чтобы стык не звучал скороговоркой.
CHUNK_GAP = 0.28


def split_for_tts(text: str, limit: int = CHUNK_CHARS) -> list[str]:
    """Режем текст на куски по границам предложений, не разрывая слова."""
    text = (text or "").strip()
    if len(text) <= limit:
        return [text] if text else []

    # Сначала предложения, затем — если предложение само длиннее лимита — слова.
    sentences: list[str] = []
    current = ""
    for word in text.split():
        current = f"{current} {word}".strip()
        if word[-1] in SENTENCE_END:
            sentences.append(current)
            current = ""
    if current:
        sentences.append(current)

    chunks: list[str] = []
    buf = ""
    for sentence in sentences:
        while len(sentence) > limit:
            # Предложение без точек длиннее лимита — отрезаем по последнему пробелу.
            cut = sentence.rfind(" ", 0, limit)
            if cut <= 0:
                cut = limit
            head, sentence = sentence[:cut].strip(), sentence[cut:].strip()
            if buf:
                chunks.append(buf)
                buf = ""
            chunks.append(head)
        if not sentence:
            continue
        if buf and len(buf) + 1 + len(sentence) > limit:
            chunks.append(buf)
            buf = sentence
        else:
            buf = f"{buf} {sentence}".strip()
    if buf:
        chunks.append(buf)
    return [c for c in chunks if c]


def trim_to_sentence(text: str) -> str:
    """Обрезаем хвост, оборванный на полуслове, до последнего целого предложения.

    Модель, упёршаяся в лимит ответа, отдаёт сцену без конца фразы. Озвучить
    такой текст — значит выпустить шортс, где голос обрывается ни на чём.
    Лучше закончить на предыдущем предложении, чем на середине слова.
    """
    text = (text or "").strip()
    if not text or text[-1] in SENTENCE_END:
        return text
    cut = max(text.rfind(ch) for ch in SENTENCE_END)
    # Если целых предложений почти не остаётся, обрезать нечего — вернём как есть.
    if cut <= 0 or cut < len(text) * 0.6:
        return text
    return text[:cut + 1].strip()


# Файл озвучки, обрезанный провайдером, кончается прямо на звуке: последнее слово
# рубится посреди гласной. Нормальная фраза затухает и оставляет тишину. Поэтому
# обрыв ловится не по длительности (недоговорённые полтора слова в минутной сцене
# в неё укладываются), а по громкости самого хвоста файла.
# Окно намеренно короткое: на 160 мс под него попадает и нормальное затухание
# фразы, и получается ложная тревога. На 40 мс разделение чистое — измерено на
# обрыве (-21 дБ), затухании за 120 мс (-35 дБ) и тишине (-91 дБ).
ABRUPT_WINDOW = 0.04
ABRUPT_DB = -30.0         # громче этого хвост быть не должен


def _parse_mean_db(text: str) -> float:
    for line in (text or "").splitlines():
        if "mean_volume" in line:
            try:
                return float(line.split(":")[-1].replace("dB", "").strip())
            except ValueError:
                return -999.0
    return -999.0


def ends_abruptly(path: Path) -> bool:
    """Обрывается ли озвучка на полуслове."""
    import subprocess

    duration = storage.media_duration(path)
    if duration <= ABRUPT_WINDOW * 2:
        return False
    proc = subprocess.run([config.FFMPEG, "-hide_banner", "-nostats",
                           "-ss", f"{duration - ABRUPT_WINDOW:.3f}", "-i", str(path),
                           "-af", "volumedetect", "-f", "null", "-"],
                          capture_output=True, text=True, timeout=180)
    tail = _parse_mean_db(proc.stderr)
    if tail <= -900:
        return False
    return tail > ABRUPT_DB


def expected_seconds(text: str, speed: float = 1.0) -> float:
    return len((text or "").strip()) / (CHARS_PER_SECOND * max(speed, 0.5))


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


def _is_truncation(exc: Exception) -> bool:
    """Обрыв текста провайдером — в отличие от временного сбоя."""
    text = str(exc)
    return "не полностью" in text or "обрывается" in text


def _try_provider(client: KieClient, tts_model: str, payload: dict, text: str,
                  dest: Path, speed: float) -> tuple[Path, float, float]:
    """Одна попытка у одного провайдера. Возвращает (файл, длительность, кредиты)."""
    result = client.run_task(tts_model, payload, timeout=900, poll=4)
    urls = extract_urls(result)
    audio = next((u for u in urls if u.split("?")[0].lower().endswith(
        (".mp3", ".wav", ".m4a", ".ogg", ".flac"))), None) or (urls[0] if urls else None)
    if not audio:
        raise TTSError(f"{tts_model}: в ответе нет ссылки на аудио")

    path = dest.with_suffix(storage.guess_ext(audio, ".mp3"))
    storage.download(audio, path)
    duration = storage.media_duration(path)
    if duration <= 0:
        path.unlink(missing_ok=True)
        raise TTSError(f"{tts_model}: скачан пустой аудиофайл")

    # Провайдер мог отдать успешный ответ, озвучив только начало текста. Считаем
    # это отказом: лучше отдать работу другому, чем выпустить шортс с голосом,
    # оборванным на полуслове.
    want = expected_seconds(text, speed)
    if want >= 3 and duration < want * SHORT_AUDIO_RATIO:
        path.unlink(missing_ok=True)
        raise TTSError(f"{tts_model}: озвучка {duration:.0f} с при тексте на "
                       f"~{want:.0f} с — текст озвучен не полностью")
    # Недоговорённые полтора слова в длину не заметны, поэтому смотрим ещё и на
    # хвост файла: обрезанная фраза кончается на полной громкости.
    if ends_abruptly(path):
        path.unlink(missing_ok=True)
        raise TTSError(f"{tts_model}: озвучка обрывается на полуслове")

    return path, duration, float(result.get("_credits") or 0)


def _synthesize_one(client: KieClient, text: str, dest: Path, *, model: str, voice_id: str,
                    stability: float, similarity: float, speed: float,
                    fallback_model: str, fallback_voice: str,
                    voice_profile: str, allow_fallback: bool) -> TTSResult:
    """Один кусок текста. При ошибке основного провайдера переключается на запасной."""
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

        for retry in range(RETRY_ATTEMPTS):
            try:
                result = _try_provider(client, tts_model, payload, text, dest, speed)
                _breaker_ok(tts_model)
                return TTSResult(path=result[0], duration=result[1],
                                 credits=result[2], provider=provider)
            except (KieError, TTSError, OSError) as exc:
                log.warning("TTS %s не сработал (попытка %d/%d): %s",
                            tts_model, retry + 1, RETRY_ATTEMPTS, exc)
                # Обрыв текста повтором не лечится — там нужен кусок помельче,
                # этим займётся вызывающая сторона. А вот 500 у провайдера
                # обычно разовый, и вторая попытка проходит.
                if _is_truncation(exc) or retry == RETRY_ATTEMPTS - 1:
                    _breaker_fail(tts_model)
                    errors.append(f"{tts_model}: {exc}")
                    break

    raise TTSError("Озвучка не удалась. " + " | ".join(errors))


def _concat_audio(parts: list[Path], dest: Path) -> Path:
    """Склейка кусков озвучки с короткой паузой на стыках."""
    if not parts:
        raise TTSError("нечего склеивать")

    args: list[str] = []
    for part in parts:
        args += ["-i", str(part)]
    # Тишина для каждого стыка отдельным входом: один и тот же поток фильтр
    # потребить дважды не может.
    for _ in range(len(parts) - 1):
        args += ["-f", "lavfi", "-t", f"{CHUNK_GAP}", "-i", "anullsrc=r=44100:cl=mono"]

    fmt = "aformat=sample_rates=44100:channel_layouts=mono"
    chain = "".join(f"[{i}:a]{fmt}[a{i}];" for i in range(len(parts)))
    chain += "".join(f"[{len(parts) + i}:a]{fmt}[g{i}];" for i in range(len(parts) - 1))

    order = ""
    for i in range(len(parts)):
        if i:
            order += f"[g{i - 1}]"
        order += f"[a{i}]"
    inputs = len(parts) * 2 - 1
    chain += f"{order}concat=n={inputs}:v=0:a=1[out]"

    storage.run_ff([config.FFMPEG, "-hide_banner", "-loglevel", "error", "-y", *args,
                    "-filter_complex", chain, "-map", "[out]",
                    "-c:a", "libmp3lame", "-q:a", "2", str(dest)], timeout=900)
    return dest


def synthesize(client: KieClient, text: str, dest: Path, *, model: str, voice_id: str,
               stability: float = 0.45, similarity: float = 0.8, speed: float = 1.0,
               fallback_model: str = "google/gemini-3-1-flash-tts",
               fallback_voice: str = "Charon",
               voice_profile: str = "Спокойный уверенный голос рассказчика, русский язык",
               allow_fallback: bool = True) -> TTSResult:
    """Озвучивает текст целиком.

    Длинный текст режется по предложениям и озвучивается кусками: провайдеры
    молча обрезают всё, что длиннее их внутреннего лимита, и до этого голос
    обрывался на полуслове в середине сцены.
    """
    text = (text or "").strip()
    if not text:
        raise TTSError("пустой текст для озвучки")

    common = dict(model=model, voice_id=voice_id, stability=stability, similarity=similarity,
                  speed=speed, fallback_model=fallback_model, fallback_voice=fallback_voice,
                  voice_profile=voice_profile, allow_fallback=allow_fallback)

    errors: list[str] = []
    for limit in CHUNK_STEPS:
        try:
            return _synthesize_whole(client, text, dest, limit, common)
        except TTSError as exc:
            errors.append(f"куски по {limit}: {exc}")
            # Мельче резать имеет смысл только если провайдер именно ОБРЫВАЕТ.
            # Если он вовсе недоступен, дробление ничего не изменит.
            if "не полностью" not in str(exc) and "обрывается" not in str(exc):
                raise
            log.warning("Озвучка кусками по %d не удалась (%s) — режу мельче", limit, exc)

    raise TTSError("Озвучка не удалась даже мелкими кусками. " + " | ".join(errors))


def _synthesize_whole(client: KieClient, text: str, dest: Path, limit: int,
                      common: dict) -> TTSResult:
    """Озвучка целиком при заданном размере куска."""
    chunks = split_for_tts(text, limit)
    if len(chunks) <= 1:
        return _synthesize_one(client, text, dest, **common)

    log.info("Текст на %d знаков озвучиваем %d кусками по %d", len(text), len(chunks), limit)
    parts: list[Path] = []
    duration = credits = 0.0
    provider = ""
    try:
        for i, chunk in enumerate(chunks):
            piece = _synthesize_one(client, chunk, dest.with_name(f"{dest.stem}_p{i:02d}"),
                                    **common)
            parts.append(piece.path)
            duration += piece.duration
            credits += piece.credits
            provider = provider or piece.provider
        final = dest.with_suffix(".mp3")
        _concat_audio(parts, final)
        total = storage.media_duration(final)
        if total <= 0:
            raise TTSError("склейка кусков озвучки дала пустой файл")
        return TTSResult(path=final, duration=total, credits=credits, provider=provider)
    finally:
        for part in parts:
            part.unlink(missing_ok=True)
