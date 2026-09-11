"""
Промо-посты про сервисы сайта с живым примером в каждом.

К посту прикладывается не заготовленная картинка, а пример, сгенерированный
через API прямо перед публикацией: озвучка голосом, музыкальный фрагмент или
иллюстрация. Так читатель видит результат, а не обещание.

Сервисы идут по кругу, в базе отмечается, какой выходил последним. Если
генерация примера не удалась, пост всё равно выйдет — с запасной картинкой,
которая тоже генерируется, но более простой моделью.
"""

import asyncio
import json
import logging
import os
import sqlite3
from contextlib import closing
from dataclasses import dataclass, field
from datetime import datetime, timezone
from html import escape
from typing import List, Optional

import httpx
from aiogram import Bot
from aiogram.types import URLInputFile

logger = logging.getLogger("promo")

KIE_TASK_URL = "https://api.kie.ai/api/v1/jobs/createTask"
KIE_RECORD_URL = "https://api.kie.ai/api/v1/jobs/recordInfo"


def _env_int(name: str, default: int) -> int:
    try:
        return int(os.getenv(name, str(default)))
    except (TypeError, ValueError):
        return default


def _env_flag(name: str, default: str = "0") -> bool:
    return os.getenv(name, default).strip().lower() in ("1", "true", "yes", "on")


ENABLED = _env_flag("PROMO_ENABLED")
# Раз в сколько дней выходит промо-пост
EVERY_DAYS = _env_int("PROMO_EVERY_DAYS", 3)
# Сколько ждать готовности примера, секунд
SAMPLE_WAIT = _env_int("PROMO_SAMPLE_WAIT", 240)
POLL_STEP = 10


@dataclass
class Service:
    key: str
    title: str
    url: str
    pitch: str                  # чем полезен
    details: List[str]          # короткие пункты
    sample_kind: str            # audio | music | image
    sample_payload: dict = field(default_factory=dict)
    sample_caption: str = ""    # подпись к примеру


SERVICES: List[Service] = [
    Service(
        key="tts",
        title="Озвучка текста онлайн",
        url="https://genius-bot.ru/tts-dashboard/",
        pitch="Превращает любой текст в живую речь. Голос звучит естественно — "
              "с паузами, ударениями и интонацией, а не как робот из навигатора.",
        details=[
            "Несколько голосов и языков, включая русский",
            "Форматы MP3, WAV и Opus",
            "История генераций и доступ по API",
            "Подходит для роликов, подкастов и озвучки курсов",
        ],
        sample_kind="audio",
        sample_payload={
            "text": "Этот голос синтезирован нейросетью. Обратите внимание на паузы "
                    "и интонацию: текст читается так, как прочитал бы человек.",
            "voice": "Bella",
        },
        sample_caption="🔊 Пример: текст, озвученный нейросетью прямо сейчас",
    ),
    Service(
        key="sfx",
        title="Генератор звуков и спецэффектов",
        url="https://genius-bot.ru/sound-generator/",
        pitch="Собирает звук по описанию словами. Нужен шум дождя, гул города "
              "или музыкальная подложка — описываете, и звук готов.",
        details=[
            "Звуковые эффекты и фоновая музыка",
            "Описание обычными словами, без нот и редакторов",
            "Готовые дорожки для роликов, игр и подкастов",
        ],
        sample_kind="music",
        sample_payload={
            "prompt": "спокойный атмосферный эмбиент, мягкое пианино, лёгкий фон",
            "instrumental": True,
            "model": "V4",
        },
        sample_caption="🎵 Пример: музыкальный фрагмент по описанию из одной строки",
    ),
    Service(
        key="avatar",
        title="Говорящий аватар из фото",
        url="https://genius-bot.ru/govoryashchiy-avatar/",
        pitch="Оживляет фотографию: человек со снимка произносит ваш текст, "
              "губы синхронизированы с речью.",
        details=[
            "Нужны только фото и запись голоса или текст",
            "Синхронизация губ с речью",
            "Готовое видео для приветствия, рекламы или обучения",
        ],
        sample_kind="image",
        sample_payload={
            "prompt": "Фотореалистичный портрет человека, говорящего в камеру, "
                      "студийный свет, нейтральный фон, кадр как из видео, 85mm",
            "image_size": "3:4",
        },
        sample_caption="🖼 Пример кадра: из такого фото получается говорящее видео",
    ),
    Service(
        key="denoise",
        title="Убрать шум из аудио",
        url="https://genius-bot.ru/ubrat-shum/",
        pitch="Отделяет голос от фонового гула, эха и уличного шума. "
              "Запись с телефона начинает звучать как студийная.",
        details=[
            "Убирает гул, эхо и шум улицы",
            "Голос остаётся живым, без металлического призвука",
            "Спасает интервью и созвоны, записанные на что попало",
        ],
        sample_kind="audio",
        sample_payload={
            "text": "Так звучит чистая речь без фонового шума: ровный голос, "
                    "тишина между фразами, ничего лишнего.",
            "voice": "Rachel",
        },
        sample_caption="🔊 Пример чистой дорожки без постороннего шума",
    ),
    Service(
        key="vocal",
        title="Убрать вокал из песни",
        url="https://genius-bot.ru/ubrat-vokal/",
        pitch="Разделяет песню на две дорожки: голос отдельно, инструментал "
              "отдельно. Минусовка готова за пару минут.",
        details=[
            "На выходе две дорожки: минус и вокал",
            "Подходит для караоке, каверов и репетиций",
            "Работает с обычными MP3 без подготовки",
        ],
        sample_kind="music",
        sample_payload={
            "prompt": "инструментальный трек без вокала, гитара и лёгкая перкуссия",
            "instrumental": True,
            "model": "V4",
        },
        sample_caption="🎵 Пример инструментальной дорожки без голоса",
    ),
    Service(
        key="neurohub",
        title="NeuroHub: нейросети в одном месте",
        url="https://genius-bot.ru/neurohub/",
        pitch="Фото, видео, музыка и озвучка — в одном кабинете, без десятка "
              "подписок на разные сервисы.",
        details=[
            "Генерация и редактирование изображений",
            "Видео из фото и из текста",
            "Музыка, звуки и озвучка",
            "Оплата за результат, а не помесячно",
        ],
        sample_kind="image",
        sample_payload={
            "prompt": "Фотореалистичный портрет молодой женщины у панорамного окна, "
                      "тёплый утренний свет, мягкие тени, 85mm, малая глубина резкости",
            "image_size": "3:4",
        },
        sample_caption="🖼 Пример: изображение, сгенерированное по одному описанию",
    ),
]


# --- Учёт публикаций -------------------------------------------------------

def _db_path():
    import news_autopost

    return news_autopost.DB_PATH


def _connect() -> sqlite3.Connection:
    path = _db_path()
    path.parent.mkdir(parents=True, exist_ok=True)
    conn = sqlite3.connect(path, timeout=30)
    conn.row_factory = sqlite3.Row
    return conn


def init_db() -> None:
    with closing(_connect()) as conn:
        conn.execute("""
            CREATE TABLE IF NOT EXISTS promo_posts (
                id           INTEGER PRIMARY KEY AUTOINCREMENT,
                service      TEXT NOT NULL,
                sample_url   TEXT,
                message_id   INTEGER,
                published_at TEXT NOT NULL
            )
        """)
        conn.commit()


def next_service() -> Service:
    """Сервисы идут по кругу: берём тот, что дольше всех не выходил."""
    with closing(_connect()) as conn:
        rows = conn.execute(
            "SELECT service, MAX(published_at) AS last FROM promo_posts GROUP BY service"
        ).fetchall()
    last_seen = {r["service"]: r["last"] for r in rows}
    return min(SERVICES, key=lambda s: last_seen.get(s.key, ""))


def days_since_last() -> Optional[float]:
    with closing(_connect()) as conn:
        row = conn.execute("SELECT MAX(published_at) AS last FROM promo_posts").fetchone()
    if not row or not row["last"]:
        return None
    try:
        last = datetime.fromisoformat(row["last"])
    except ValueError:
        return None
    return (datetime.now(timezone.utc) - last).total_seconds() / 86400


def remember(service: str, sample_url: str = "", message_id: Optional[int] = None) -> None:
    with closing(_connect()) as conn:
        conn.execute(
            "INSERT INTO promo_posts (service, sample_url, message_id, published_at) "
            "VALUES (?, ?, ?, ?)",
            (service, sample_url, message_id,
             datetime.now(timezone.utc).isoformat(timespec="seconds")),
        )
        conn.commit()


# --- Генерация примера -----------------------------------------------------

async def _run_kie_task(payload: dict) -> Optional[str]:
    """Ставит задачу в Kie AI и ждёт готовую ссылку на результат."""
    from config import KIE_API_KEY

    headers = {"Authorization": f"Bearer {KIE_API_KEY}", "Content-Type": "application/json"}
    async with httpx.AsyncClient(timeout=90) as client:
        try:
            resp = await client.post(KIE_TASK_URL, headers=headers, json=payload)
            task_id = ((resp.json() or {}).get("data") or {}).get("taskId")
        except Exception as e:
            logger.error("[промо] задача не создана: %s", e)
            return None
        if not task_id:
            logger.error("[промо] Kie не вернул taskId: %s", resp.text[:200])
            return None

        for _ in range(SAMPLE_WAIT // POLL_STEP):
            await asyncio.sleep(POLL_STEP)
            try:
                info = await client.get(KIE_RECORD_URL, params={"taskId": task_id},
                                        headers=headers)
                data = (info.json() or {}).get("data") or {}
            except Exception:
                continue

            state = (data.get("state") or data.get("status") or "").lower()
            if state in ("success", "succeeded", "completed"):
                result = data.get("resultJson")
                if isinstance(result, str):
                    try:
                        result = json.loads(result)
                    except Exception:
                        result = {}
                result = result or {}

                # Картинки и часть моделей отдают список ссылок
                for key in ("resultUrls", "audio_urls", "images"):
                    urls = result.get(key)
                    if isinstance(urls, list) and urls:
                        return str(urls[0])

                # Suno кладёт треки в data: [{audio_url: ...}, ...]
                tracks = result.get("data")
                if isinstance(tracks, list):
                    for track in tracks:
                        url = (track or {}).get("audio_url") or (track or {}).get("stream_audio_url")
                        if url:
                            return str(url)

                logger.error("[промо] результат без ссылки: %s", str(result)[:200])
                return None
            if state in ("fail", "failed", "error"):
                logger.warning("[промо] генерация примера не удалась: %s",
                               str(data.get("failMsg"))[:120])
                return None
    logger.warning("[промо] пример не готов за %s секунд", SAMPLE_WAIT)
    return None


async def generate_sample(service: Service) -> tuple:
    """Пример для поста. Возвращает (тип, ссылка) либо (None, None)."""
    if service.sample_kind == "audio":
        payload = {"model": "elevenlabs/text-to-speech-multilingual-v2",
                   "input": service.sample_payload}
    elif service.sample_kind == "music":
        payload = {"model": "suno", "input": service.sample_payload}
    else:
        payload = {"model": "google/nano-banana",
                   "input": {**service.sample_payload, "output_format": "png"}}

    url = await _run_kie_task(payload)
    if url:
        return service.sample_kind, url

    # Запасной путь: картинка проще и надёжнее любой другой генерации
    if service.sample_kind != "image":
        logger.info("[промо] %s: пробуем запасную иллюстрацию", service.key)
        fallback = await _run_kie_task({
            "model": "google/nano-banana",
            "input": {
                "prompt": f"Минималистичная иллюстрация к теме «{service.title}», "
                          "мягкие градиенты, современный стиль, без текста",
                "output_format": "png", "image_size": "16:9",
            },
        })
        if fallback:
            return "image", fallback
    return None, None


# --- Оформление и публикация ----------------------------------------------

def build_text(service: Service) -> str:
    parts = [f"<b>{escape(service.title)}</b>", escape(service.pitch)]
    if service.details:
        parts.append("\n".join(f"• {escape(d)}" for d in service.details))
    parts.append(f'<a href="{escape(service.url)}">Попробовать</a>')
    return "\n\n".join(parts)


async def publish(bot: Bot, chat_id: int, service: Service) -> bool:
    kind, sample_url = await generate_sample(service)
    text = build_text(service)

    try:
        if kind == "image" and sample_url:
            await bot.send_photo(chat_id=chat_id, photo=URLInputFile(sample_url),
                                 caption=text[:1024], parse_mode="HTML")
        elif kind in ("audio", "music") and sample_url:
            await bot.send_message(chat_id=chat_id, text=text, parse_mode="HTML",
                                   disable_web_page_preview=True)
            await bot.send_audio(chat_id=chat_id, audio=URLInputFile(sample_url),
                                 caption=service.sample_caption)
        else:
            await bot.send_message(chat_id=chat_id, text=text, parse_mode="HTML",
                                   disable_web_page_preview=True)
    except Exception as e:
        logger.error("[промо] публикация %s не удалась: %s", service.key, e)
        return False

    remember(service.key, sample_url or "")
    logger.info("[промо] опубликован пост про «%s» (пример: %s)", service.title, kind or "нет")
    return True


async def publish_next(bot: Bot, chat_id: int) -> bool:
    init_db()
    return await publish(bot, chat_id, next_service())


# --- Фоновый цикл ----------------------------------------------------------

async def promo_worker(bot: Bot) -> None:
    """Раз в PROMO_EVERY_DAYS дней публикует промо-пост про очередной сервис."""
    if not ENABLED:
        logger.info("[промо] выключено (PROMO_ENABLED=0)")
        return

    import news_autopost

    chat_id = news_autopost.CHAT_ID
    if not chat_id:
        logger.error("[промо] не задан NEWS_CHAT_ID — публиковать некуда")
        return

    init_db()
    logger.info("[промо] запущено: раз в %s дня, сервисов %s", EVERY_DAYS, len(SERVICES))

    while True:
        try:
            elapsed = days_since_last()
            if elapsed is None or elapsed >= EVERY_DAYS:
                await publish_next(bot, chat_id)
        except Exception as e:
            logger.error("[промо] ошибка: %s", e, exc_info=True)
        await asyncio.sleep(3600)
