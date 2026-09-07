# kie_api_veo3.py
from task_map import get_chat_by_task, get_task_info
import httpx
from typing import Optional, Dict, Any
import json
import logging
import math
from fastapi import FastAPI, Request
from config import KIE_API_KEY, GENERATION_MARKUP, TELEGRAM_BOT_TOKEN, SPEECH_TO_TEXT_MARKUP
from database import deduct_balance
from aiogram import Bot
from aiogram.client.bot import DefaultBotProperties
from typing import Optional, Dict, Any
import asyncio
app = FastAPI()

bot = Bot(token=TELEGRAM_BOT_TOKEN, default=DefaultBotProperties(parse_mode="HTML"))

BASE_URL_VEO = "https://api.kie.ai/api/v1/veo/"

# Цены моделей 1 кредит = $0.0055 (округленно)
BASE_PRICES = {
"Veo 3": {"fast": 0.4, "quality": 1.6},  # Цены в долларах для VEO 3.1 (60 кредитов = $0.33, 250 кредитов = $1.37)
"SORA 2": {"text": 0.45, "image": 0.45},  # Цены в долларах для SORA 2 (30 кредитов = $0.45 за Text/Image → Video)
"SORA 2 Pro": {
        "standard_10s": 0.65,  # 90 кредитов = $0.45
        "standard_15s": 0.85,  # 135 кредитов = $0.675
        "hd_10s": 1.30,        # 200 кредитов = $1.00
        "hd_15s": 2.50         # 400 кредитов = $2.00
},
"ElevenLabs TTS": {"multilingual_v2": 0.1},  # Цены в долларах для ElevenLabs TTS Multilingual V2 (12 кредитов за 1000 символов ≈ $0.06)
"Grok Imagine": {"image_to_video_6s": 0.10}  # $0.10 за 6-секундный ролик
}

# Маппинг моделей для API
MODEL_MAPPING = {
"veo3": "veo-3-quality",
"suno": "suno",
"midjourney": "midjourney"
}

MODELS_AVAILABLE = {
"Veo 3": {"quality": "veo-3-quality", "fast": "veo-3-fast", "price": 300},
"Suno": {"id": "suno", "price": 15},
"Midjourney": {"id": "midjourney", "price": 200},
"Nano Banana": {"id": "nano_banana", "price": 5},
}


def _price_rub(base: float) -> int:
    return int(round(float(base) * (1 + GENERATION_MARKUP / 100)))


def _norm_duration_key(duration: str) -> str:
    return str(duration).rstrip("s")


def _normalize_runway_quality(quality: str) -> str:
    q = (quality or "720p").lower()
    if q in ("standard", "std", "720"):
        return "720p"
    if q in ("high", "pro", "1080", "1080p"):
        return "1080p"
    return q if q in ("720p", "1080p") else "720p"


async def send_telegram_message(chat_id: int, text: str):
        try:
            print(f"send_telegram_message: Отправляем в chat_id {chat_id}: {text}")
            result = await bot.send_message(chat_id=chat_id, text=text)
            print(f"send_telegram_message: Успешно отправлено, message_id: {result.message_id}")
            return result
        except Exception as e:
            print(f"Ошибка при отправке сообщения Telegram в chat_id {chat_id}: {e}")
            return None


async def create_video_task(
    model: str,
    prompt: str,
    aspect_ratio: str = "16:9",
    image_urls: Optional[list] = None,
    callback_url: Optional[str] = None,
    watermark: Optional[str] = None,
    seeds: Optional[int] = None,
    enable_fallback: Optional[bool] = None,
    generation_type: Optional[str] = None
) -> Dict[str, Any]:
    """
    Создает задачу генерации видео через API KIE VEO 3.1.
    Возвращает полный JSON-ответ API для отладки.
    
    Args:
        generation_type: Тип генерации - "TEXT_2_VIDEO", "FIRST_AND_LAST_FRAMES_2_VIDEO", или "REFERENCE_2_VIDEO"
                        Если не указан, система автоматически определит по наличию imageUrls
    """
    headers = {
        "Authorization": f"Bearer {KIE_API_KEY}",
        "Content-Type": "application/json"
    }

    # Согласно актуальной документации API
    payload: Dict[str, Any] = {
        "prompt": prompt,
        "model": model,  # "veo3" для quality, "veo3_fast" для fast
        "aspectRatio": aspect_ratio  # "16:9", "9:16" или "Auto"
    }

    if image_urls:
        payload["imageUrls"] = image_urls
    if generation_type:
        payload["generationType"] = generation_type
    if callback_url:
        payload["callBackUrl"] = callback_url
    if watermark:
        payload["watermark"] = watermark
    if seeds is not None:
        payload["seeds"] = seeds
    if enable_fallback is not None:
        payload["enableFallback"] = enable_fallback
    
    # Логирование для отладки (после формирования полного payload)
    print(f"🔍 [create_video_task] Payload: {json.dumps(payload, indent=2, ensure_ascii=False)}")

    async with httpx.AsyncClient(timeout=300) as client:
        try:
            response = await client.post("https://api.kie.ai/api/v1/veo/generate", headers=headers, json=payload)
            response.raise_for_status()
            result = response.json()

            # --- Отладка ---
            print("HTTP Status:", response.status_code)
            print("Response JSON:", result)
            # --- Конец отладки ---

            return result

        except httpx.HTTPStatusError as e:
            print(f"HTTP ошибка: {e.response.status_code}, {e.response.text}")
            return {"error": str(e), "status_code": e.response.status_code}
        except Exception as e:
            print(f"Ошибка запроса к API VEO3: {e}")
            return {"error": str(e)}


async def create_sora2_video_task(
        model: str,
        prompt: str,
        aspect_ratio: str = "landscape",
        image_urls: Optional[list] = None,
        n_frames: Optional[str] = None,
        size: Optional[str] = None,
        remove_watermark: bool = True,
        callback_url: Optional[str] = None,
) -> Dict[str, Any]:
        """
        Создает задачу генерации видео через SORA 2 API KIE.
        Использует правильный эндпоинт /api/v1/jobs/createTask согласно документации.
        """
        headers = {
            "Authorization": f"Bearer {KIE_API_KEY}",
            "Content-Type": "application/json"
        }

        # Структура запроса согласно документации KIE API
        payload: Dict[str, Any] = {
            "model": model,  # Например: "sora-2-image-to-video"
        }
        
        if callback_url:
            payload["callBackUrl"] = callback_url
        
        # Параметры input согласно документации
        input_params = {
            "prompt": prompt,
            "aspect_ratio": aspect_ratio,
            "remove_watermark": remove_watermark
        }
        
        if image_urls:
            input_params["image_urls"] = image_urls
        if n_frames:
            input_params["n_frames"] = n_frames
        if size:
            input_params["size"] = size
        
        payload["input"] = input_params

        async with httpx.AsyncClient(timeout=300) as client:
            try:
                response = await client.post("https://api.kie.ai/api/v1/jobs/createTask", headers=headers, json=payload)
                response.raise_for_status()
                result = response.json()

                # --- Отладка ---
                print("HTTP Status:", response.status_code)
                print("Response JSON:", result)
                # --- Конец отладки ---

                return result

            except httpx.HTTPStatusError as e:
                print(f"HTTP ошибка: {e.response.status_code}, {e.response.text}")
                return {"error": str(e), "status_code": e.response.status_code}
            except Exception as e:
                print(f"Ошибка запроса к API SORA2: {e}")
                return {"error": str(e)}


async def create_suno_music_task(
        prompt: str,
        model: str = "V3_5",
        custom_mode: bool = False,
        instrumental: bool = False,
        style: Optional[str] = None,
        title: Optional[str] = None,
        callback_url: Optional[str] = None,
) -> Dict[str, Any]:
        """
        Создает задачу генерации музыки через Suno API.
        """
        headers = {
            "Authorization": f"Bearer {KIE_API_KEY}",
            "Content-Type": "application/json"
        }

        payload: Dict[str, Any] = {
            "prompt": prompt,
            "model": model,
            "customMode": custom_mode,
            "instrumental": instrumental
        }
        
        if callback_url:
            payload["callBackUrl"] = callback_url
        if style:
            payload["style"] = style
        if title:
            payload["title"] = title

        async with httpx.AsyncClient(timeout=300) as client:
            try:
                response = await client.post("https://api.kie.ai/api/v1/generate", headers=headers, json=payload)
                response.raise_for_status()
                result = response.json()

                # --- Отладка ---
                print("Suno HTTP Status:", response.status_code)
                print("Suno Response JSON:", result)
                # --- Конец отладки ---

                return result

            except httpx.HTTPStatusError as e:
                print(f"Suno HTTP ошибка: {e.response.status_code}, {e.response.text}")
                return {"error": str(e), "status_code": e.response.status_code}
            except Exception as e:
                print(f"Ошибка запроса к Suno API: {e}")
                return {"error": str(e)}


async def create_grok_imagine_video_task(
        image_urls: list,
        prompt: str = "",
        callback_url: Optional[str] = None,
        model: str = "grok-imagine/image-to-video"
) -> Dict[str, Any]:
        """
        Создает задачу генерации видео через Grok Imagine (image-to-video).
        Документация: https://kie.ai/ru/grok-imagine
        POST /api/v1/jobs/createTask с телом:
        {
          "model": "grok-imagine/image-to-video",
          "callBackUrl": "...",
          "input": { "image_urls": [..], "prompt": "..." }
        }
        """
        headers = {
            "Authorization": f"Bearer {KIE_API_KEY}",
            "Content-Type": "application/json"
        }
        payload: Dict[str, Any] = {
            "model": model
        }
        if callback_url:
            payload["callBackUrl"] = callback_url
        payload["input"] = {
            "image_urls": image_urls
        }
        if prompt:
            payload["input"]["prompt"] = prompt

        async with httpx.AsyncClient(timeout=300) as client:
            try:
                response = await client.post("https://api.kie.ai/api/v1/jobs/createTask", headers=headers, json=payload)
                response.raise_for_status()
                return response.json()
            except httpx.HTTPStatusError as e:
                return {"error": str(e), "status_code": e.response.status_code, "response_text": e.response.text}
            except Exception as e:
                return {"error": str(e)}

async def create_nano_banana_task(
        mode: str,
        prompt: Optional[str] = None,
        image_urls: Optional[list] = None,
        output_format: str = "png",
        image_size: str = "auto",
        scale: Optional[float] = None,
        face_enhance: bool = False,
        callback_url: Optional[str] = None,
) -> Dict[str, Any]:
        """
        Создает задачу через Nano Banana API.
        mode: "generate", "edit", "upscale"
        """
        headers = {
            "Authorization": f"Bearer {KIE_API_KEY}",
            "Content-Type": "application/json"
        }

        # Определяем модель и базовый URL на основе режима
        if mode == "generate":
            model = "google/nano-banana"
        elif mode == "edit":
            model = "google/nano-banana-edit"
        elif mode == "upscale":
            model = "google/nano-banana-upscale"
        else:
            return {"error": f"Неизвестный режим: {mode}"}

        payload: Dict[str, Any] = {
            "model": model
        }
        
        if callback_url:
            payload["callBackUrl"] = callback_url

        # Параметры input на основе режима
        input_params = {}
        
        if mode in ["generate", "edit"] and prompt:
            input_params["prompt"] = prompt
        
        if mode == "edit" and image_urls:
            input_params["image_urls"] = image_urls
        
        if mode == "upscale" and image_urls:
            # Для upscale согласно документации используем параметр "image" (не image_urls)
            input_params["image"] = image_urls[0] if image_urls else None
            if scale:
                input_params["scale"] = scale
            input_params["face_enhance"] = face_enhance
        
        if mode in ["generate", "edit"]:
            input_params["output_format"] = output_format
            # Согласно документации API принимает значения: 1:1, 9:16, 16:9, 3:4, 4:3, 3:2, 2:3, 5:4, 4:5, 21:9, auto
            if image_size:
                input_params["image_size"] = image_size

        payload["input"] = input_params
        
        print(f"Nano Banana запрос: payload = {payload}")

        async with httpx.AsyncClient(timeout=300) as client:
            try:
                response = await client.post("https://api.kie.ai/api/v1/jobs/createTask", headers=headers, json=payload)
                response.raise_for_status()
                result = response.json()

                print("Nano Banana HTTP Status:", response.status_code)
                print("Nano Banana Response JSON:", result)

                return result

            except httpx.HTTPStatusError as e:
                print(f"Nano Banana HTTP ошибка: {e.response.status_code}, {e.response.text}")
                return {"error": str(e), "status_code": e.response.status_code}
            except Exception as e:
                print(f"Ошибка запроса к Nano Banana API: {e}")
                return {"error": str(e)}


async def create_nano_banana_pro_task(
        prompt: str,
        aspect_ratio: str,
        resolution: str,
        output_format: str,
        image_input: Optional[list] = None,
        callback_url: Optional[str] = None,
) -> Dict[str, Any]:
        headers = {
            "Authorization": f"Bearer {KIE_API_KEY}",
            "Content-Type": "application/json",
        }
        payload: Dict[str, Any] = {
            "model": "nano-banana-pro",
            "input": {
                "prompt": prompt,
                "aspect_ratio": aspect_ratio,
                "resolution": resolution,
                "output_format": output_format,
            },
        }
        if image_input:
            payload["input"]["image_input"] = image_input
        if callback_url:
            payload["callBackUrl"] = callback_url
        async with httpx.AsyncClient(timeout=300) as client:
            try:
                r = await client.post(
                    "https://api.kie.ai/api/v1/jobs/createTask",
                    headers=headers,
                    json=payload,
                )
                r.raise_for_status()
                return r.json()
            except httpx.HTTPStatusError as e:
                return {
                    "error": str(e),
                    "status_code": e.response.status_code,
                    "response_text": e.response.text,
                }
            except Exception as e:
                return {"error": str(e)}


async def create_topaz_upscale_task(
        image_url: str,
        upscale_factor,
        callback_url: Optional[str] = None,
) -> Dict[str, Any]:
        headers = {
            "Authorization": f"Bearer {KIE_API_KEY}",
            "Content-Type": "application/json",
        }
        fac = str(upscale_factor).strip()
        if fac.isdigit():
            fac = str(int(fac))
        if fac not in ("1", "2", "4", "8"):
            fac = "2"
        payload: Dict[str, Any] = {
            "model": "topaz/image-upscale",
            "input": {"image_url": image_url, "upscale_factor": fac},
        }
        if callback_url:
            payload["callBackUrl"] = callback_url
        async with httpx.AsyncClient(timeout=300) as client:
            try:
                r = await client.post(
                    "https://api.kie.ai/api/v1/jobs/createTask",
                    headers=headers,
                    json=payload,
                )
                r.raise_for_status()
                return r.json()
            except httpx.HTTPStatusError as e:
                return {
                    "error": str(e),
                    "status_code": e.response.status_code,
                    "response_text": e.response.text,
                }
            except Exception as e:
                return {"error": str(e)}


async def create_seedance_task(
        image_url: Optional[str],
        prompt: str,
        resolution: str,
        duration: str,
        callback_url: Optional[str] = None,
) -> Dict[str, Any]:
        headers = {
            "Authorization": f"Bearer {KIE_API_KEY}",
            "Content-Type": "application/json",
        }
        try:
            dur = int(_norm_duration_key(duration))
        except (TypeError, ValueError):
            dur = 5
        res = (resolution or "720p").lower()
        inp: Dict[str, Any] = {
            "prompt": prompt,
            "aspect_ratio": "16:9",
            "generate_audio": False,
        }
        if res == "1080p":
            model = "bytedance/seedance-1.5-pro"
            inp["resolution"] = "1080p"
            inp["duration"] = 12 if dur >= 10 else 8
            inp["nsfw_checker"] = False
            if image_url:
                inp["input_urls"] = [image_url]
        else:
            model = "bytedance/seedance-2-fast"
            inp["resolution"] = "720p"
            inp["duration"] = min(15, max(4, dur))
            inp["web_search"] = False
            inp["nsfw_checker"] = False
            if image_url:
                inp["first_frame_url"] = image_url
        payload: Dict[str, Any] = {"model": model, "input": inp}
        if callback_url:
            payload["callBackUrl"] = callback_url
        async with httpx.AsyncClient(timeout=300) as client:
            try:
                r = await client.post(
                    "https://api.kie.ai/api/v1/jobs/createTask",
                    headers=headers,
                    json=payload,
                )
                r.raise_for_status()
                return r.json()
            except httpx.HTTPStatusError as e:
                return {
                    "error": str(e),
                    "status_code": e.response.status_code,
                    "response_text": e.response.text,
                }
            except Exception as e:
                return {"error": str(e)}


async def create_runway_video_task(
        prompt: str,
        duration: str,
        quality: str,
        aspect_ratio: str,
        image_url: Optional[str] = None,
        callback_url: Optional[str] = None,
) -> Dict[str, Any]:
        headers = {
            "Authorization": f"Bearer {KIE_API_KEY}",
            "Content-Type": "application/json",
        }
        q = _normalize_runway_quality(quality)
        try:
            dur = int(_norm_duration_key(duration))
        except (TypeError, ValueError):
            dur = 5
        body: Dict[str, Any] = {
            "prompt": prompt,
            "duration": dur,
            "quality": q,
            "aspectRatio": aspect_ratio,
            "waterMark": "",
        }
        if image_url:
            body["imageUrl"] = image_url
        if callback_url:
            body["callBackUrl"] = callback_url
        async with httpx.AsyncClient(timeout=300) as client:
            try:
                r = await client.post(
                    "https://api.kie.ai/api/v1/runway/generate",
                    headers=headers,
                    json=body,
                )
                r.raise_for_status()
                return r.json()
            except httpx.HTTPStatusError as e:
                return {
                    "error": str(e),
                    "status_code": e.response.status_code,
                    "response_text": e.response.text,
                }
            except Exception as e:
                return {"error": str(e)}


async def extend_runway_video_task(
        task_id: str,
        prompt: str,
        quality: str,
        callback_url: Optional[str] = None,
) -> Dict[str, Any]:
        headers = {
            "Authorization": f"Bearer {KIE_API_KEY}",
            "Content-Type": "application/json",
        }
        q = _normalize_runway_quality(quality)
        body: Dict[str, Any] = {
            "taskId": task_id,
            "prompt": prompt,
            "quality": q,
        }
        if callback_url:
            body["callBackUrl"] = callback_url
        async with httpx.AsyncClient(timeout=300) as client:
            try:
                r = await client.post(
                    "https://api.kie.ai/api/v1/runway/extend",
                    headers=headers,
                    json=body,
                )
                r.raise_for_status()
                return r.json()
            except httpx.HTTPStatusError as e:
                return {
                    "error": str(e),
                    "status_code": e.response.status_code,
                    "response_text": e.response.text,
                }
            except Exception as e:
                return {"error": str(e)}


async def create_aleph_video_task(
        prompt: str,
        video_url: str,
        callback_url: Optional[str] = None,
) -> Dict[str, Any]:
        headers = {
            "Authorization": f"Bearer {KIE_API_KEY}",
            "Content-Type": "application/json",
        }
        body: Dict[str, Any] = {
            "prompt": prompt,
            "videoUrl": video_url,
        }
        if callback_url:
            body["callBackUrl"] = callback_url
        async with httpx.AsyncClient(timeout=300) as client:
            try:
                r = await client.post(
                    "https://api.kie.ai/api/v1/aleph/generate",
                    headers=headers,
                    json=body,
                )
                r.raise_for_status()
                return r.json()
            except httpx.HTTPStatusError as e:
                return {
                    "error": str(e),
                    "status_code": e.response.status_code,
                    "response_text": e.response.text,
                }
            except Exception as e:
                return {"error": str(e)}


async def create_kling_motion_control_task(
        prompt: str,
        input_urls: list,
        video_urls: list,
        mode: str,
        character_orientation: str = "image",
        callback_url: Optional[str] = None,
) -> Dict[str, Any]:
        headers = {
            "Authorization": f"Bearer {KIE_API_KEY}",
            "Content-Type": "application/json",
        }
        payload: Dict[str, Any] = {
            "model": "kling-3.0/motion-control",
            "input": {
                "prompt": prompt,
                "input_urls": input_urls,
                "video_urls": video_urls,
                "mode": mode,
                "character_orientation": character_orientation,
            },
        }
        if callback_url:
            payload["callBackUrl"] = callback_url
        async with httpx.AsyncClient(timeout=300) as client:
            try:
                r = await client.post(
                    "https://api.kie.ai/api/v1/jobs/createTask",
                    headers=headers,
                    json=payload,
                )
                r.raise_for_status()
                return r.json()
            except httpx.HTTPStatusError as e:
                return {
                    "error": str(e),
                    "status_code": e.response.status_code,
                    "response_text": e.response.text,
                }
            except Exception as e:
                return {"error": str(e)}


async def create_seedream_edit_task(
        image_urls: list,
        prompt: str,
        aspect_ratio: str,
        quality: str,
        callback_url: Optional[str] = None,
) -> Dict[str, Any]:
        headers = {
            "Authorization": f"Bearer {KIE_API_KEY}",
            "Content-Type": "application/json",
        }
        q = (quality or "basic").lower()
        if q not in ("basic", "high"):
            q = "basic"
        payload: Dict[str, Any] = {
            "model": "seedream/4.5-edit",
            "input": {
                "prompt": prompt,
                "image_urls": image_urls,
                "aspect_ratio": aspect_ratio,
                "quality": q,
            },
        }
        if callback_url:
            payload["callBackUrl"] = callback_url
        async with httpx.AsyncClient(timeout=300) as client:
            try:
                r = await client.post(
                    "https://api.kie.ai/api/v1/jobs/createTask",
                    headers=headers,
                    json=payload,
                )
                r.raise_for_status()
                return r.json()
            except httpx.HTTPStatusError as e:
                return {
                    "error": str(e),
                    "status_code": e.response.status_code,
                    "response_text": e.response.text,
                }
            except Exception as e:
                return {"error": str(e)}


_SEEDANCE_BASE_RUB = {
    ("720p", "5"): 45.0,
    ("720p", "10"): 85.0,
    ("1080p", "5"): 95.0,
    ("1080p", "10"): 160.0,
}

_RUNWAY_BASE_RUB = {
    ("720p", "5"): 42.0,
    ("720p", "10"): 78.0,
    ("1080p", "5"): 92.0,
}


def get_seedance_price(resolution: str, duration: str) -> int:
    key = (resolution, _norm_duration_key(duration))
    base = _SEEDANCE_BASE_RUB.get(key, 50.0)
    return _price_rub(base)


def get_seedance_price_options() -> Dict[str, int]:
    return {
        f"{res[0]}_{res[1]}s": _price_rub(b)
        for res, b in _SEEDANCE_BASE_RUB.items()
    }


def get_runway_price(quality: str, duration: str) -> int:
    q = quality if quality in ("720p", "1080p") else _normalize_runway_quality(quality)
    key = (q, _norm_duration_key(duration))
    base = _RUNWAY_BASE_RUB.get(key, 50.0)
    return _price_rub(base)


def get_runway_price_options() -> Dict[str, int]:
    return {}


def get_runway_extend_price(quality: str) -> int:
    q = quality if quality in ("720p", "1080p") else _normalize_runway_quality(quality)
    base = 62.0 if q == "720p" else 98.0
    return _price_rub(base)


def get_aleph_price() -> int:
    return _price_rub(115.0)


def get_topaz_upscale_cost(upscale_factor) -> int:
    try:
        f = int(upscale_factor)
    except (TypeError, ValueError):
        f = 2
    base = {1: 18.0, 2: 32.0, 4: 55.0, 8: 95.0}.get(f, 25.0)
    return _price_rub(base)


def get_seedream_edit_cost(aspect_ratio: str, quality: str) -> int:
    _ = aspect_ratio
    q = (quality or "basic").lower()
    if q not in ("basic", "high"):
        q = "basic"
    base = 38.0 if q == "basic" else 72.0
    return _price_rub(base)


async def get_kling_motion_control_cost(mode: str) -> int:
    m = mode if mode in ("720p", "1080p") else "720p"
    base = 88.0 if m == "720p" else 135.0
    return _price_rub(base)


async def create_tts_task(
        text: str,
        voice: str = "Rachel",
        stability: Optional[float] = None,
        similarity_boost: Optional[float] = None,
        style: Optional[float] = None,
        speed: Optional[float] = None,
        timestamps: bool = False,
        previous_text: Optional[str] = None,
        next_text: Optional[str] = None,
        language_code: Optional[str] = None,
        callback_url: Optional[str] = None,
) -> Dict[str, Any]:
        """
        Создает задачу генерации речи через ElevenLabs TTS API.
        """
        headers = {
            "Authorization": f"Bearer {KIE_API_KEY}",
            "Content-Type": "application/json"
        }

        # Используем структуру как в других API
        payload: Dict[str, Any] = {
            "model": "elevenlabs/text-to-speech-multilingual-v2"
        }
        
        if callback_url:
            payload["callBackUrl"] = callback_url

        # Параметры input
        input_params = {
            "text": text,
            "voice": voice
        }
        
        if stability is not None:
            input_params["stability"] = stability
        if similarity_boost is not None:
            input_params["similarity_boost"] = similarity_boost
        if style is not None:
            input_params["style"] = style
        if speed is not None:
            input_params["speed"] = speed
        if timestamps:
            input_params["timestamps"] = timestamps
        if previous_text:
            input_params["previous_text"] = previous_text
        if next_text:
            input_params["next_text"] = next_text
        if language_code:
            input_params["language_code"] = language_code
        
        payload["input"] = input_params

        async with httpx.AsyncClient(timeout=300) as client:
            try:
                logging.info(f"[TTS] Отправка запроса к API: https://api.kie.ai/api/v1/jobs/createTask")
                logging.info(f"[TTS] Payload: {payload}")
                
                response = await client.post("https://api.kie.ai/api/v1/jobs/createTask", headers=headers, json=payload)
                
                logging.info(f"[TTS] HTTP Status: {response.status_code}")
                logging.info(f"[TTS] Response text: {response.text}")
                
                result = response.json()
                logging.info(f"[TTS] Response JSON: {result}")

                return result

            except httpx.HTTPStatusError as e:
                logging.error(f"[TTS] HTTP ошибка: {e.response.status_code}")
                logging.error(f"[TTS] Response text: {e.response.text}")
                error_response = {"error": str(e), "status_code": e.response.status_code, "response_text": e.response.text}
                logging.error(f"[TTS] Возвращаем ошибку: {error_response}")
                return error_response
            except Exception as e:
                logging.error(f"[TTS] Общая ошибка запроса к TTS API: {e}", exc_info=True)
                return {"error": str(e)}


async def get_video_status(task_id: str):
        """
        Проверка статуса задачи видео согласно VEO 3.1 API.
        """
        headers = {"Authorization": f"Bearer {KIE_API_KEY}"}
        url = f"https://api.kie.ai/api/v1/veo/record-info?taskId={task_id}"
        async with httpx.AsyncClient() as client:
            try:
                response = await client.get(url, headers=headers)
                response.raise_for_status()
                return response.json()
            except Exception as e:
                print(f"Ошибка проверки статуса: {e}")
                return None

@app.post("/veo3-callback")
async def veo3_callback(request: Request):
        data = await request.json()
        print("Veo3 Callback received:", data)
        
        code = data.get("code")
        msg = data.get("msg")
        task_data = data.get("data", {})
        task_id = task_data.get("taskId")

        print(f"=== Veo3 CALLBACK === Получен Veo3 callback: {task_id}, статус: {code}")
        print(f"Veo3 callback data: {data}")
        
        # Получаем chat_id и стоимость для отправки уведомления
        task_info = get_task_info(task_id)
        if not task_info:
            print(f"⚠️ Не найдена информация о задаче taskId: {task_id}")
            return {"status": "received"}
        
        chat_id, cost = task_info
        
        if code == 200:
            # Проверяем статус задачи согласно документации VEO 3.1
            success_flag = task_data.get("successFlag")
            error_code = task_data.get("errorCode")
            error_message = task_data.get("errorMessage", "")
            
            # Если successFlag отсутствует, но есть code=200 и msg='Video generated successfully'
            if success_flag is None and msg == "Video generated successfully.":
                # Обрабатываем как успешную генерацию
                info_data = task_data.get("info", {})
                result_urls = info_data.get("resultUrls", [])
                resolution = info_data.get("resolution", "")
                fallback_flag = task_data.get("fallbackFlag", False)
                
                if isinstance(result_urls, str):
                    try:
                        video_urls = json.loads(result_urls)
                    except json.JSONDecodeError:
                        print("Ошибка декодирования resultUrls")
                        video_urls = []
                else:
                    video_urls = result_urls
                
                if video_urls:
                    print(f"Veo3: Найдено {len(video_urls)} видео URL: {video_urls}")
                    
                    # Списываем баланс только при успешной генерации
                    try:
                        deduct_balance(chat_id, cost)
                        print(f"Veo3: Списан баланс {cost} с chat_id {chat_id}")
                    except Exception as e:
                        print(f"Veo3: Ошибка списания баланса: {e}")
                        # Продолжаем выполнение даже если не удалось списать баланс
                    
                    # Обновляем статус в логах
                    try:
                        from database import update_request_status
                        update_request_status(task_id, "completed")
                    except Exception as e:
                        print(f"Ошибка обновления статуса: {e}")
                    
                    # Формируем сообщение с информацией о качестве
                    quality_info = ""
                    if fallback_flag:
                        quality_info = " (720p, fallback модель)"
                    elif resolution:
                        quality_info = f" ({resolution})"
                    
                    for url in video_urls:
                        message_text = f"✅ Видео готово{quality_info}: {url}"
                        print(f"Veo3: Отправляем сообщение chat_id {chat_id}: {message_text}")
                        asyncio.create_task(send_telegram_message(
                            chat_id=chat_id, 
                            text=message_text
                        ))
                else:
                    print(f"Veo3: video_urls пустой или None")
                    # Возвращаем средства при отсутствии результата
                    try:
                        from database import refund_balance, update_request_status
                        # refund_balance(chat_id, cost)  # НЕ возвращаем средства, так как изначально не списывали
                        update_request_status(task_id, "failed", "Отсутствуют URL результатов")
                    except Exception as e:
                        print(f"Ошибка возврата средств: {e}")
                    asyncio.create_task(send_telegram_message(
                        chat_id=chat_id, 
                        text="❌ Видео не сгенерировано: отсутствуют URL результатов. Средства возвращены на баланс."
                    ))
            
            elif success_flag == 1:  # Успешно
                # Согласно документации VEO 3.1, результат находится в data.info.resultUrls
                info_data = task_data.get("info", {})
                result_urls = info_data.get("resultUrls", [])
                resolution = info_data.get("resolution", "")
                fallback_flag = task_data.get("fallbackFlag", False)
                
                if isinstance(result_urls, str):
                    try:
                        video_urls = json.loads(result_urls)
                    except json.JSONDecodeError:
                        print("Ошибка декодирования resultUrls")
                        video_urls = []
                else:
                    video_urls = result_urls
                
                if video_urls:
                    print(f"Veo3: Найдено {len(video_urls)} видео URL: {video_urls}")
                    
                    # Списываем баланс только при успешной генерации
                    try:
                        deduct_balance(chat_id, cost)
                        print(f"Veo3: Списан баланс {cost} с chat_id {chat_id}")
                    except Exception as e:
                        print(f"Veo3: Ошибка списания баланса: {e}")
                        # Продолжаем выполнение даже если не удалось списать баланс
                    
                    # Обновляем статус в логах
                    try:
                        from database import update_request_status
                        update_request_status(task_id, "completed")
                    except Exception as e:
                        print(f"Ошибка обновления статуса: {e}")
                    
                    # Формируем сообщение с информацией о качестве
                    quality_info = ""
                    if fallback_flag:
                        quality_info = " (720p, fallback модель)"
                    elif resolution:
                        quality_info = f" ({resolution})"
                    
                    for url in video_urls:
                        message_text = f"✅ Видео готово{quality_info}: {url}"
                        print(f"Veo3: Отправляем сообщение chat_id {chat_id}: {message_text}")
                        asyncio.create_task(send_telegram_message(
                            chat_id=chat_id, 
                            text=message_text
                        ))
                else:
                    print(f"Veo3: video_urls пустой или None")
                    # Возвращаем средства при отсутствии результата
                    try:
                        from database import refund_balance, update_request_status
                        # refund_balance(chat_id, cost)  # НЕ возвращаем средства, так как изначально не списывали
                        update_request_status(task_id, "failed", "Отсутствуют URL результатов")
                    except Exception as e:
                        print(f"Ошибка возврата средств: {e}")
                    asyncio.create_task(send_telegram_message(
                        chat_id=chat_id, 
                        text="❌ Видео не сгенерировано: отсутствуют URL результатов. Средства возвращены на баланс."
                    ))
                    
            elif success_flag == 2:  # Неудача
                # Возвращаем средства при неудаче
                try:
                    from database import refund_balance, update_request_status
                    # refund_balance(chat_id, cost)  # НЕ возвращаем средства, так как изначально не списывали
                    update_request_status(task_id, "failed", error_message)
                except Exception as e:
                    print(f"Ошибка возврата средств при неудаче: {e}")
                
                error_text = f"❌ Генерация видео не удалась. Средства возвращены на баланс."
                if error_message:
                    error_text += f"\n\nДетали ошибки: {error_message}"
                if error_code:
                    error_text += f"\nКод ошибки: {error_code}"
                asyncio.create_task(send_telegram_message(chat_id=chat_id, text=error_text))
                
            elif success_flag == 3:  # Генерация не удалась
                # Возвращаем средства при неудаче
                try:
                    from database import refund_balance, update_request_status
                    # refund_balance(chat_id, cost)  # НЕ возвращаем средства, так как изначально не списывали
                    update_request_status(task_id, "failed", error_message)
                except Exception as e:
                    print(f"Ошибка возврата средств при неудаче: {e}")
                
                error_text = f"❌ Задача создана, но генерация не удалась. Средства возвращены на баланс."
                if error_message:
                    error_text += f"\n\nДетали ошибки: {error_message}"
                asyncio.create_task(send_telegram_message(chat_id=chat_id, text=error_text))
                
            else:  # success_flag == 0 или другое значение - в процессе
                print(f"Задача {task_id} еще выполняется (successFlag: {success_flag})")
                
        else:
            # Обработка HTTP кодов ошибок согласно документации VEO 3.1
            # Возвращаем средства при ошибке API
            try:
                from database import refund_balance, update_request_status
                # refund_balance(chat_id, cost)  # НЕ возвращаем средства, так как изначально не списывали
                update_request_status(task_id, "failed", f"API ошибка: {msg}")
            except Exception as e:
                print(f"Ошибка возврата средств при API ошибке: {e}")
            
            error_text = f"❌ Ошибка генерации видео. Средства возвращены на баланс."
            
            if code == 400:
                error_text += "\n\nПричина: Промпт нарушает политику контента или изображение недоступно"
            elif code == 401:
                error_text += "\n\nПричина: Неверный API ключ"
            elif code == 404:
                error_text += "\n\nПричина: Задача не найдена"
            elif code == 422:
                error_text += "\n\nПричина: Ошибка валидации параметров или запись старше 14 дней"
            elif code == 451:
                error_text += "\n\nПричина: Не удалось загрузить изображение"
            elif code == 455:
                error_text += "\n\nПричина: Сервис недоступен (техническое обслуживание)"
            elif code == 500:
                error_text += "\n\nПричина: Внутренняя ошибка сервера"
            else:
                error_text += f"\n\nКод ошибки: {code}"
                
            if msg:
                error_text += f"\nДополнительная информация: {msg}"
                
            asyncio.create_task(send_telegram_message(chat_id=chat_id, text=error_text))
            print(f"Генерация видео не удалась: {msg}")

        return {"status": "received"}


@app.post("/sora2-callback")
async def sora2_callback(request: Request):
        data = await request.json()
        print("SORA 2 Callback received:", data)
        
        code = data.get("code")
        msg = data.get("msg")
        task_data = data.get("data", {})
        task_id = task_data.get("taskId")

        print(f"=== SORA2 CALLBACK === Получен SORA 2 callback: {task_id}, статус: {code}")
        print(f"SORA2 callback data: {data}")
        
        # Получаем chat_id и стоимость для отправки уведомления
        task_info = get_task_info(task_id)
        if not task_info:
            print(f"⚠️ Не найдена информация о SORA 2 задаче taskId: {task_id}")
            return {"status": "received"}
        
        chat_id, cost = task_info
        print(f"SORA2: chat_id = {chat_id}, cost = {cost}")
        
        if code == 200:
            # Для SORA 2 проверяем поле state вместо successFlag
            state = task_data.get("state")
            print(f"SORA2: state = {state}")
            
            if state == "success":  # Успешно
                # Для SORA 2 URL находятся в resultJson
                result_json_str = task_data.get("resultJson", "{}")
                print(f"SORA2: resultJson = {result_json_str}")
                
                try:
                    result_json = json.loads(result_json_str)
                    result_urls = result_json.get("resultUrls", [])
                    print(f"SORA2: resultUrls из JSON = {result_urls}")
                except json.JSONDecodeError as e:
                    print(f"SORA2: Ошибка декодирования resultJson: {e}")
                    result_urls = []
                
                if result_urls:
                    print(f"SORA2: Найдено {len(result_urls)} видео URL: {result_urls}")
                    
                    # Списываем баланс только при успешной генерации
                    try:
                        deduct_balance(chat_id, cost)
                        print(f"SORA2: Списан баланс {cost} с chat_id {chat_id}")
                    except Exception as e:
                        print(f"SORA2: Ошибка списания баланса: {e}")
                        # Продолжаем выполнение даже если не удалось списать баланс
                    
                    # Обновляем статус в логах
                    try:
                        from database import update_request_status, update_partner_commission
                        update_request_status(task_id, "completed")
                        
                        # Начисляем комиссию партнеру
                        print(f"🔄 [KIE_API] Вызываем update_partner_commission для user_id={chat_id}, cost={cost}")
                        update_partner_commission(chat_id, cost)
                    except Exception as e:
                        print(f"Ошибка обновления статуса: {e}")
                    
                    # Для SORA 2 не используем fallback/resolution info
                    quality_info = ""
                    
                    for url in result_urls:
                        message_text = f"✅ SORA 2 видео готово{quality_info}: {url}"
                        print(f"SORA2: Отправляем сообщение chat_id {chat_id}: {message_text}")
                        asyncio.create_task(send_telegram_message(
                            chat_id=chat_id, 
                            text=message_text
                        ))
                else:
                    print(f"SORA2: result_urls пустой или None")
                    asyncio.create_task(send_telegram_message(
                        chat_id=chat_id, 
                        text="❌ SORA 2 видео не сгенерировано: отсутствуют URL результатов"
                    ))
                    
            elif state == "failed":  # Неудача
                error_text = f"❌ Генерация SORA 2 видео не удалась"
                asyncio.create_task(send_telegram_message(chat_id=chat_id, text=error_text))
                
            else:  # Другие состояния - в процессе или неизвестно
                print(f"SORA 2 задача {task_id} состояние: {state}")
                
        else:
            # Обработка HTTP кодов ошибок согласно документации
            error_text = f"❌ Ошибка генерации SORA 2 видео"
            
            if code == 400:
                error_text += ": Промпт нарушает политику контента или изображение недоступно"
            elif code == 401:
                error_text += ": Неверный API ключ"
            elif code == 404:
                error_text += ": Задача не найдена"
            elif code == 422:
                error_text += ": Ошибка валидации параметров или запись старше 14 дней"
            elif code == 451:
                error_text += ": Не удалось загрузить изображение"
            elif code == 455:
                error_text += ": Сервис недоступен (техническое обслуживание)"
            elif code == 500:
                error_text += ": Внутренняя ошибка сервера"
            else:
                error_text += f" (код: {code})"
                
            if msg:
                error_text += f": {msg}"
                
            asyncio.create_task(send_telegram_message(chat_id=chat_id, text=error_text))
            print(f"Генерация SORA 2 видео не удалась: {msg}")

        return {"status": "received"}


@app.post("/suno-callback")
async def suno_callback(request: Request):
        data = await request.json()
        print("=== SUNO CALLBACK === Получен Suno callback:", data)
        
        code = data.get("code")
        msg = data.get("msg")
        task_data = data.get("data", {})
        task_id = task_data.get("task_id")  # Исправлено: task_id вместо taskId

        print(f"Получен Suno callback: {task_id}, статус: {code}")
        print(f"task_data keys: {task_data.keys()}")
        print(f"task_data: {task_data}")
        
        # Получаем chat_id и стоимость для отправки уведомления
        task_info = get_task_info(task_id)
        if not task_info:
            print(f"⚠️ Не найдена информация о Suno задаче taskId: {task_id}")
            return {"status": "received"}
        
        chat_id, cost = task_info
        print(f"Suno: chat_id = {chat_id}, cost = {cost}")
        
        if code == 200:
            # Для Suno проверяем callbackType и status
            callback_type = task_data.get("callbackType")  # Исправлено: task_data вместо data
            print(f"Suno: callback_type = {callback_type}")
            
            # Извлекаем данные о треках
            tracks_data = task_data.get("data", [])
            print(f"Suno: callback_type = {callback_type}, найдено {len(tracks_data)} треков")
            
            if callback_type in ["text", "first"]:
                # Промежуточные этапы - не отправляем пользователю, только логируем
                print(f"Suno: Промежуточный этап {callback_type}, ждем завершения")
                
            elif callback_type == "complete":
                # Финальный этап - отправляем результаты пользователю
                print(f"Suno: Генерация завершена, обрабатываем результаты")
                
                if tracks_data:
                    # Списываем баланс только при успешной генерации
                    try:
                        deduct_balance(chat_id, cost)
                        print(f"Suno: Списан баланс {cost} с chat_id {chat_id}")
                    except Exception as e:
                        print(f"Suno: Ошибка списания баланса: {e}")
                        # Продолжаем выполнение даже если не удалось списать баланс
                    
                    # Обновляем статус в логах
                    try:
                        from database import update_request_status
                        update_request_status(task_id, "completed")
                    except Exception as e:
                        print(f"Ошибка обновления статуса: {e}")
                    
                    for track in tracks_data:
                        title = track.get("title", "Без названия")
                        audio_url = track.get("audio_url")
                        duration = track.get("duration", 0)
                        
                        print(f"Suno: Трек {title}, audio_url: {audio_url}")
                        
                        if audio_url:
                            message_text = f"🎵 Музыка готова!\n\n🎶 Название: {title}\n⏱ Длительность: {duration:.1f}с\n🔗 Ссылка: {audio_url}"
                            print(f"Suno: Отправляем сообщение chat_id {chat_id}: {title}")
                            asyncio.create_task(send_telegram_message(
                                chat_id=chat_id, 
                                text=message_text
                            ))
                        else:
                            print(f"Suno: Пустой audio_url для трека {title}")
                else:
                    print(f"Suno: tracks_data пустой при завершении")
                    asyncio.create_task(send_telegram_message(
                        chat_id=chat_id, 
                        text="❌ Музыка не сгенерирована: отсутствуют данные"
                    ))
            else:
                # Неизвестный или ошибочный callback_type
                error_msg = f"Неизвестный callback_type: {callback_type}"
                print(f"Suno ошибка: {error_msg}")
                asyncio.create_task(send_telegram_message(
                    chat_id=chat_id, 
                    text=f"❌ Ошибка генерации музыки: {error_msg}"
                ))
                
        else:
            # Обработка HTTP кодов ошибок
            if task_info:  # Проверяем, что task_info найден
                error_text = f"❌ Ошибка генерации музыки"
                
                if code == 400:
                    error_text += ": Проблемы с контентом или параметрами"
                elif code == 401:
                    error_text += ": Неверный API ключ"
                elif code == 402:
                    error_text += ": Недостаточно кредитов"
                elif code == 429:
                    error_text += ": Превышен лимит запросов"
                else:
                    error_text += f" (код: {code})"
                    
                if msg:
                    error_text += f": {msg}"
                    
                asyncio.create_task(send_telegram_message(chat_id=chat_id, text=error_text))
            print(f"Генерация музыки не удалась: {msg}")

        return {"status": "received"}


@app.post("/grok-imagine-callback")
async def grok_imagine_callback(request: Request):
        data = await request.json()
        print("GROK IMAGINE Callback received:", data)

        code = data.get("code")
        msg = data.get("msg")
        task_data = data.get("data", {})
        task_id = task_data.get("taskId") or task_data.get("task_id")

        print(f"=== GROK CALLBACK === taskId: {task_id}, status code: {code}")

        task_info = get_task_info(task_id)
        if not task_info:
            print(f"⚠️ Не найдена информация о GROK задаче taskId: {task_id}")
            return {"status": "received"}

        chat_id, cost = task_info

        if code == 200:
            state = task_data.get("state")
            if state == "success":
                # resultJson: '{"resultUrls":["https://..."]}'
                result_json_str = task_data.get("resultJson", "{}")
                try:
                    result_json = json.loads(result_json_str) if isinstance(result_json_str, str) else result_json_str
                    result_urls = result_json.get("resultUrls", [])
                except json.JSONDecodeError:
                    result_urls = []

                if result_urls:
                    try:
                        deduct_balance(chat_id, cost)
                    except Exception as e:
                        print(f"GROK: Ошибка списания баланса: {e}")

                    try:
                        from database import update_request_status, update_partner_commission
                        update_request_status(task_id, "completed")
                        update_partner_commission(chat_id, cost)
                    except Exception as e:
                        print(f"GROK: Ошибка обновления статуса/комиссии: {e}")

                    for url in result_urls:
                        asyncio.create_task(send_telegram_message(
                            chat_id=chat_id,
                            text=f"✅ Видео готово: {url}"
                        ))
                else:
                    asyncio.create_task(send_telegram_message(
                        chat_id=chat_id,
                        text="❌ Видео не сгенерировано: отсутствуют URL результатов"
                    ))
            elif state == "failed":
                asyncio.create_task(send_telegram_message(
                    chat_id=chat_id,
                    text="❌ Генерация Grok Imagine не удалась"
                ))
            else:
                print(f"GROK задача {task_id} состояние: {state}")
        else:
            error_text = "❌ Ошибка генерации Grok Imagine"
            if msg:
                error_text += f": {msg}"
            asyncio.create_task(send_telegram_message(chat_id=chat_id, text=error_text))

        return {"status": "received"}




# Цены моделей в долларах (удалено дублирующее определение)

DOLLAR_RATE = 81.25  # 1$ = 81.25₽ (fallback)

async def get_current_prices() -> dict:
    """Получение актуальных цен в рублях с учетом текущего курса доллара"""
    try:
        current_rate = await get_current_dollar_rate()
        print(f"💰 Текущий курс доллара: {current_rate}₽")
        
        prices = {}
        
        # VEO 3 цены
        veo3_prices = {}
        for variant, usd_price in BASE_PRICES["Veo 3"].items():
            rub_price = usd_price * current_rate
            final_price = rub_price * (1 + GENERATION_MARKUP / 100)
            veo3_prices[variant] = int(round(final_price))
        prices["veo3"] = veo3_prices
        
        # SORA 2 цены
        sora2_prices = {}
        for variant, usd_price in BASE_PRICES["SORA 2"].items():
            rub_price = usd_price * current_rate
            final_price = rub_price * (1 + GENERATION_MARKUP / 100)
            sora2_prices[variant] = int(round(final_price))
        prices["sora2"] = sora2_prices
        
        # SORA 2 Pro цены
        sora2_pro_prices = {}
        for variant, usd_price in BASE_PRICES["SORA 2 Pro"].items():
            rub_price = usd_price * current_rate
            final_price = rub_price * (1 + GENERATION_MARKUP / 100)
            sora2_pro_prices[variant] = int(round(final_price))
        prices["sora2_pro"] = sora2_pro_prices
        
        # ElevenLabs TTS цены (за 1000 символов)
        tts_prices = {}
        for variant, usd_price in BASE_PRICES["ElevenLabs TTS"].items():
            rub_price = usd_price * current_rate
            final_price = rub_price * (1 + GENERATION_MARKUP / 100)
            tts_prices[variant] = round(final_price, 2)
        prices["tts"] = tts_prices
        
        # Другие модели (фиксированные цены)
        prices["suno"] = 15
        prices["nano_banana"] = 5
        # Grok Imagine — 18₽ за 6-секундный ролик
        prices["grok_imagine"] = 18
        
        return prices
        
    except Exception as e:
        print(f"❌ Ошибка получения актуальных цен: {e}")
        # Возвращаем fallback цены
        return {
            "veo3": {"fast": 35, "quality": 145},
            "sora2": {"text": 45, "image": 45},
            "sora2_pro": {"standard_10s": 47, "standard_15s": 71, "hd_10s": 106, "hd_15s": 212},
            "tts": {"multilingual_v2": 6.35},
            "suno": 15,
            "nano_banana": 5,
            "grok_imagine": 18
        }

async def get_current_dollar_rate() -> float:
    """Получение актуального курса доллара к рублю"""
    try:
        # Пробуем получить курс с разных источников
        sources = [
            "https://api.exchangerate-api.com/v4/latest/USD",
            "https://api.fixer.io/latest?base=USD&symbols=RUB",
            "https://api.currencylayer.com/live?access_key=free&currencies=RUB&source=USD"
        ]
        
        async with httpx.AsyncClient(timeout=5) as client:
            for url in sources:
                try:
                    response = await client.get(url)
                    if response.status_code == 200:
                        data = response.json()
                        
                        # Разные API возвращают данные в разных форматах
                        if "rates" in data and "RUB" in data["rates"]:
                            return float(data["rates"]["RUB"])
                        elif "quotes" in data and "USDRUB" in data["quotes"]:
                            return float(data["quotes"]["USDRUB"])
                        elif "RUB" in data:
                            return float(data["RUB"])
                            
                except Exception as e:
                    print(f"Ошибка получения курса с {url}: {e}")
                    continue
                    
        # Если все источники недоступны, используем fallback
        print("Все источники курса недоступны, используем fallback")
        return DOLLAR_RATE
        
    except Exception as e:
        print(f"Ошибка получения курса доллара: {e}")
        return DOLLAR_RATE


async def get_generation_cost(model_key: str, variant: str = None) -> int:
        """
        Возвращает стоимость генерации в токенах.
        1 токен = 1₽.
        """
        base_cost = 0.0
        if model_key == "Veo 3":
            if variant not in BASE_PRICES["Veo 3"]:
                raise ValueError(f"Неизвестный вариант Veo: {variant}")
            # Для VEO 3 цены в долларах, переводим в рубли и применяем наценку
            base_cost_usd = BASE_PRICES["Veo 3"][variant]
            current_rate = await get_current_dollar_rate()
            base_cost_rub = base_cost_usd * current_rate
            final_cost = base_cost_rub * (1 + GENERATION_MARKUP / 100)
            return int(round(final_cost))
        elif model_key == "SORA 2":
            if variant not in BASE_PRICES["SORA 2"]:
                raise ValueError(f"Неизвестный вариант SORA 2: {variant}")
            # Для SORA 2 цены в долларах, переводим в рубли и применяем наценку
            base_cost_usd = BASE_PRICES["SORA 2"][variant]
            current_rate = await get_current_dollar_rate()
            base_cost_rub = base_cost_usd * current_rate
            final_cost = base_cost_rub * (1 + GENERATION_MARKUP / 100)
            return int(round(final_cost))
        else:
            base_cost = MODELS_AVAILABLE.get(model_key, {}).get("price", 100)
            # применяем наценку как % для других моделей
            final_cost = base_cost * (1 + GENERATION_MARKUP / 100)
            return int(round(final_cost))


async def get_sora2_pro_cost(duration: str, quality: str) -> int:
        """
        Возвращает стоимость генерации SORA 2 Pro в рублях.
        """
        # Создаем ключ для поиска в BASE_PRICES
        price_key = f"{quality}_{duration}s"
        print(f"get_sora2_pro_cost: price_key = {price_key}")
        print(f"get_sora2_pro_cost: BASE_PRICES keys = {list(BASE_PRICES.keys())}")
        print(f"get_sora2_pro_cost: SORA 2 Pro keys = {list(BASE_PRICES.get('SORA 2 Pro', {}).keys())}")
        
        if price_key not in BASE_PRICES["SORA 2 Pro"]:
            raise ValueError(f"Неизвестная комбинация SORA 2 Pro: {quality}_{duration}s")
        
        # Получаем стоимость в долларах, переводим в рубли и применяем наценку
        base_cost_usd = BASE_PRICES["SORA 2 Pro"][price_key]
        current_rate = await get_current_dollar_rate()
        base_cost_rub = base_cost_usd * current_rate
        final_cost = base_cost_rub * (1 + GENERATION_MARKUP / 100)
        
        return int(round(final_cost))


async def get_tts_cost(text: str) -> int:
        """
        Возвращает стоимость генерации TTS в рублях на основе количества символов.
        Минимум 12 рублей до 1000 знаков, от 1000 до 2000 - 24 рубля, 
        от 2000 до 3000 - 36 рублей и так далее (каждые 1000 знаков +12 рублей).
        """
        text_length = len(text)
        
        # Рассчитываем количество полных блоков по 1000 символов
        # Используем округление вверх: каждые 1000 знаков = 12 рублей
        blocks = math.ceil(text_length / 1000)
        
        # Минимум 12 рублей (даже для 0 символов, но обычно не будет 0)
        cost = blocks * 12
        
        return max(12, cost)


def get_grok_imagine_cost() -> int:
    """
    Стоимость Grok Imagine: 18₽ за 6-секундный ролик (с учетом наценки).
    """
    return 18

async def kie_jobs_task_callback(request: Request, media_kind: str = "image"):
        data = await request.json()
        label = "KIE jobs"
        print(f"=== {label} CALLBACK ({media_kind}) ===", data)

        code = data.get("code")
        msg = data.get("msg")
        task_data = data.get("data") or {}
        task_id = task_data.get("taskId") or task_data.get("task_id")

        print(f"{label} callback: task_id={task_id}, code={code}")

        task_info = get_task_info(task_id)
        if not task_info:
            print(f"⚠️ Нет task_map для task_id={task_id}")
            return {"status": "received"}

        chat_id, cost = task_info
        media_ok = "🎬 Видео готово!" if media_kind == "video" else "🖼️ Изображение готово!"
        media_fail = "видео" if media_kind == "video" else "изображения"

        if code == 200:
            state = (task_data.get("state") or "").lower()
            print(f"{label}: state = {state}")

            if state == "success":
                result_json_data = task_data.get("resultJson", {})
                print(f"{label}: resultJson = {result_json_data}")

                try:
                    if isinstance(result_json_data, str):
                        result_json = json.loads(result_json_data)
                    else:
                        result_json = result_json_data or {}
                    result_urls = list(result_json.get("resultUrls") or [])
                    if not result_urls:
                        result_urls = list(result_json.get("images") or [])
                    if not result_urls and isinstance(result_json.get("result"), str):
                        result_urls = [result_json.get("result")]
                    if not result_urls:
                        raw_urls = task_data.get("resultUrls")
                        if isinstance(raw_urls, list):
                            result_urls = list(raw_urls)

                    print(f"{label}: URLs count={len(result_urls)} {result_urls}")

                    if result_urls:
                        try:
                            deduct_balance(chat_id, cost)
                            print(f"{label}: списан баланс {cost} user={chat_id}")
                        except Exception as e:
                            print(f"{label}: ошибка списания: {e}")

                        from database import update_request_status, update_partner_commission

                        update_request_status(task_id, "completed")
                        update_partner_commission(chat_id, cost)

                        for url in result_urls:
                            message_text = f"{media_ok}\n\n🔗 Ссылка: {url}"
                            asyncio.create_task(
                                send_telegram_message(chat_id=chat_id, text=message_text)
                            )
                    else:
                        asyncio.create_task(
                            send_telegram_message(
                                chat_id=chat_id,
                                text=f"❌ Не удалось получить URL результата ({media_fail}).",
                            )
                        )

                except json.JSONDecodeError as e:
                    print(f"{label}: JSON error: {e}")
                    asyncio.create_task(
                        send_telegram_message(
                            chat_id=chat_id,
                            text="❌ Ошибка разбора результата от KIE.",
                        )
                    )

            elif state == "failed":
                error_msg = task_data.get("errorMessage", "Неизвестная ошибка")
                asyncio.create_task(
                    send_telegram_message(
                        chat_id=chat_id,
                        text=f"❌ Ошибка генерации ({media_fail}): {error_msg}",
                    )
                )
            else:
                print(f"{label}: промежуточное состояние {state!r}")

        else:
            error_text = f"❌ Ошибка генерации ({media_fail})"
            if code == 400:
                error_text += ": контент или параметры"
            elif code == 401:
                error_text += ": неверный API ключ"
            elif code == 402:
                error_text += ": недостаточно кредитов"
            elif code == 429:
                error_text += ": лимит запросов"
            else:
                error_text += f" (код: {code})"
            if msg:
                error_text += f": {msg}"
            asyncio.create_task(send_telegram_message(chat_id=chat_id, text=error_text))

        return {"status": "received"}


@app.post("/nano-banana-callback")
async def nano_banana_callback(request: Request):
        return await kie_jobs_task_callback(request, "image")


@app.post("/topaz-upscale-callback")
async def topaz_upscale_callback(request: Request):
        return await kie_jobs_task_callback(request, "image")


@app.post("/seedance-callback")
async def seedance_callback(request: Request):
        return await kie_jobs_task_callback(request, "video")


@app.post("/seedream-edit-callback")
async def seedream_edit_callback(request: Request):
        return await kie_jobs_task_callback(request, "image")


@app.post("/kling-motion-control-callback")
async def kling_motion_control_callback(request: Request):
        return await kie_jobs_task_callback(request, "video")


@app.post("/runway-callback")
@app.post("/aleph-callback")
async def runway_aleph_video_callback(request: Request):
        data = await request.json()
        print("=== RUNWAY / ALEPH CALLBACK ===", data)
        code = data.get("code")
        msg = data.get("msg")
        d = data.get("data") or {}
        task_id = d.get("task_id")
        video_url = (d.get("video_url") or "").strip()

        task_info = get_task_info(task_id)
        if not task_info:
            print(f"⚠️ runway/aleph: нет task_map для {task_id}")
            return {"status": "received"}
        chat_id, cost = task_info

        if code == 200 and video_url:
            try:
                deduct_balance(chat_id, cost)
            except Exception as e:
                print(f"runway/aleph: списание: {e}")
            from database import update_request_status, update_partner_commission

            update_request_status(task_id, "completed")
            update_partner_commission(chat_id, cost)
            asyncio.create_task(
                send_telegram_message(
                    chat_id=chat_id,
                    text=f"🎬 Видео готово!\n\n🔗 Ссылка: {video_url}",
                )
            )
        else:
            err = msg or "ошибка генерации"
            asyncio.create_task(
                send_telegram_message(
                    chat_id=chat_id,
                    text=f"❌ Видео не готово: {err}",
                )
            )
        return {"status": "received"}


@app.post("/tts-callback")
async def tts_callback(request: Request):
        data = await request.json()
        print("=== TTS CALLBACK === Получен TTS callback:", data)
        
        code = data.get("code")
        msg = data.get("msg")
        task_data = data.get("data", {})
        task_id = task_data.get("taskId")

        print(f"Получен TTS callback: {task_id}, статус: {code}")
        
        # Получаем chat_id и стоимость для отправки уведомления
        task_info = get_task_info(task_id)
        if not task_info:
            print(f"⚠️ Не найдена информация о TTS задаче taskId: {task_id}")
            return {"status": "received"}
        
        chat_id, cost = task_info
        print(f"TTS: chat_id = {chat_id}, cost = {cost}")
        
        if code == 200:
            # Для TTS проверяем state
            state = task_data.get("state")
            print(f"TTS: state = {state}")
            
            if state == "success":
                # Извлекаем URL аудио из resultJson
                result_json_data = task_data.get("resultJson", {})
                print(f"TTS: resultJson = {result_json_data}")
                
                try:
                    # resultJson может быть как строкой, так и объектом
                    if isinstance(result_json_data, str):
                        result_json = json.loads(result_json_data)
                    else:
                        result_json = result_json_data
                    
                    # Для TTS URL обычно находится в resultUrls или audio_url
                    result_urls = result_json.get("resultUrls", [])
                    if not result_urls:
                        result_urls = result_json.get("audio_url", [])
                        if not result_urls and isinstance(result_json.get("result"), str):
                            result_urls = [result_json.get("result")]
                    
                    print(f"TTS: найдено {len(result_urls)} аудио файлов: {result_urls}")
                    
                    if result_urls:
                        # Списываем баланс только при успешной генерации
                        try:
                            deduct_balance(chat_id, cost)
                            print(f"TTS: Списан баланс {cost} с chat_id {chat_id}")
                        except Exception as e:
                            print(f"TTS: Ошибка списания баланса: {e}")
                            # Продолжаем выполнение даже если не удалось списать баланс
                        
                        # Обновляем статус в логах
                        from database import update_request_status, update_partner_commission
                        update_request_status(task_id, "completed")
                        
                        # Начисляем комиссию партнеру
                        print(f"🔄 [KIE_API] Вызываем update_partner_commission для user_id={chat_id}, cost={cost}")
                        update_partner_commission(chat_id, cost)
                        
                        for url in result_urls:
                            message_text = f"🎙️ Аудио готово!\n\n🔗 Ссылка: {url}"
                            print(f"TTS: Отправляем сообщение chat_id {chat_id}: {url}")
                            asyncio.create_task(send_telegram_message(
                                chat_id=chat_id, 
                                text=message_text
                            ))
                    else:
                        print(f"TTS: result_urls пустой")
                        asyncio.create_task(send_telegram_message(
                            chat_id=chat_id, 
                            text="❌ Аудио не сгенерировано: отсутствуют URL результатов"
                        ))
                        
                except json.JSONDecodeError as e:
                    print(f"TTS: Ошибка декодирования resultJson: {e}")
                    asyncio.create_task(send_telegram_message(
                        chat_id=chat_id, 
                        text="❌ Ошибка обработки результата генерации аудио"
                    ))
                    
            elif state == "failed":
                error_msg = task_data.get("failMsg", task_data.get("errorMessage", "Неизвестная ошибка"))
                fail_code = task_data.get("failCode", "")
                print(f"TTS ошибка: {error_msg}, код: {fail_code}")
                error_text = f"❌ Ошибка генерации аудио"
                if fail_code:
                    error_text += f" (код: {fail_code})"
                if error_msg:
                    error_text += f"\n\nПричина: {error_msg}"
                asyncio.create_task(send_telegram_message(
                    chat_id=chat_id, 
                    text=error_text
                ))
                
            else:
                print(f"TTS задача {task_id} состояние: {state}")
                
        else:
            # Обработка HTTP кодов ошибок
            error_text = f"❌ Ошибка генерации аудио"
            
            if code == 400:
                error_text += ": Проблемы с контентом или параметрами"
            elif code == 401:
                error_text += ": Неверный API ключ"
            elif code == 402:
                error_text += ": Недостаточно кредитов"
            elif code == 429:
                error_text += ": Превышен лимит запросов"
            else:
                error_text += f" (код: {code})"
                
            if msg:
                error_text += f": {msg}"
                
            asyncio.create_task(send_telegram_message(chat_id=chat_id, text=error_text))
            print(f"Генерация аудио не удалась: {msg}")

        return {"status": "received"}


@app.get("/api/v1/pricing")
async def get_pricing():
    """API endpoint для получения текущих цен"""
    try:
        # Получаем актуальный курс доллара
        current_rate = await get_current_dollar_rate()
        
        # Рассчитываем цены в рублях с наценкой
        pricing_data = {
            "dollar_rate": current_rate,
            "markup_percent": GENERATION_MARKUP,
            "prices": {}
        }
        
        # VEO 3.1 цены
        veo3_prices = {}
        for variant, usd_price in BASE_PRICES["Veo 3"].items():
            rub_price = usd_price * current_rate
            final_price = rub_price * (1 + GENERATION_MARKUP / 100)
            veo3_prices[variant] = {
                "usd": usd_price,
                "rub_base": round(rub_price, 2),
                "rub_final": round(final_price, 2)
            }
        pricing_data["prices"]["veo3"] = veo3_prices
        
        # SORA 2 цены
        sora2_prices = {}
        for variant, usd_price in BASE_PRICES["SORA 2"].items():
            rub_price = usd_price * current_rate
            final_price = rub_price * (1 + GENERATION_MARKUP / 100)
            sora2_prices[variant] = {
                "usd": usd_price,
                "rub_base": round(rub_price, 2),
                "rub_final": round(final_price, 2)
            }
        pricing_data["prices"]["sora2"] = sora2_prices
        
        # SORA 2 Pro цены
        sora2_pro_prices = {}
        for variant, usd_price in BASE_PRICES["SORA 2 Pro"].items():
            rub_price = usd_price * current_rate
            final_price = rub_price * (1 + GENERATION_MARKUP / 100)
            sora2_pro_prices[variant] = {
                "usd": usd_price,
                "rub_base": round(rub_price, 2),
                "rub_final": round(final_price, 2)
            }
        pricing_data["prices"]["sora2_pro"] = sora2_pro_prices
        
        # ElevenLabs TTS цены (за 1000 символов)
        tts_prices = {}
        for variant, usd_price in BASE_PRICES["ElevenLabs TTS"].items():
            rub_price = usd_price * current_rate
            final_price = rub_price * (1 + GENERATION_MARKUP / 100)
            tts_prices[variant] = {
                "usd": usd_price,
                "rub_base": round(rub_price, 2),
                "rub_final": round(final_price, 2)
            }
        pricing_data["prices"]["tts"] = tts_prices
        
        # Другие модели (фиксированные цены в рублях)
        other_prices = {
            "suno": {
                "all_models": {
                    "rub_final": 15
                }
            },
            "nano_banana": {
                "all_modes": {
                    "rub_final": 5
                }
            },
            "grok_imagine": {
                "image_to_video_6s": {
                    "rub_final": 18
                }
            }
        }
        pricing_data["prices"]["other"] = other_prices
        
        return pricing_data
        
    except Exception as e:
        return {"error": f"Ошибка получения цен: {str(e)}"}


# === МОНИТОРИНГ PENDING ЗАДАЧ ===

async def check_pending_tasks():
    """Проверка pending задач и обновление их статуса"""
    try:
        from database import get_connection, update_request_status
        
        conn = get_connection()
        cursor = conn.cursor()
        
        # Получаем все pending задачи
        cursor.execute("""
            SELECT id, user_telegram_id, request_type, task_id, cost, model_name, prompt
            FROM user_requests_log 
            WHERE status = 'pending' 
            AND task_id IS NOT NULL
            AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
            ORDER BY created_at DESC
            LIMIT 50
        """)
        
        pending_tasks = cursor.fetchall()
        conn.close()
        
        if not pending_tasks:
            print("Нет pending задач для проверки")
            return
        
        print(f"Проверяем {len(pending_tasks)} pending задач...")
        
        for task in pending_tasks:
            task_id = task['task_id']
            user_id = task['user_telegram_id']
            request_type = task['request_type']
            cost = task['cost']
            
            try:
                # Проверяем статус через API в зависимости от типа
                if request_type == 'sora2':
                    status_data = await check_sora2_status(task_id)
                elif request_type == 'veo3':
                    status_data = await check_veo3_status(task_id)
                elif request_type == 'suno':
                    status_data = await check_suno_status(task_id)
                elif request_type == 'nano_banana':
                    status_data = await check_nano_banana_status(task_id)
                else:
                    continue
                
                if status_data:
                    await process_task_result(task, status_data)
                    
            except Exception as e:
                print(f"Ошибка проверки задачи {task_id}: {e}")
                continue
                
    except Exception as e:
        print(f"Ошибка мониторинга pending задач: {e}")


async def check_sora2_status(task_id: str) -> dict:
    """Проверка статуса SORA 2 задачи"""
    try:
        async with httpx.AsyncClient(timeout=30) as client:
            response = await client.get(
                f"https://api.kie.ai/api/v1/sora2/record-info?taskId={task_id}",
                headers={"Authorization": f"Bearer {KIE_API_KEY}"}
            )
            
            if response.status_code == 200:
                return response.json()
            else:
                print(f"SORA2 API ошибка для {task_id}: {response.status_code}")
                return None
                
    except Exception as e:
        print(f"Ошибка проверки SORA2 статуса {task_id}: {e}")
        return None


async def check_veo3_status(task_id: str) -> dict:
    """Проверка статуса VEO 3 задачи"""
    try:
        async with httpx.AsyncClient(timeout=30) as client:
            response = await client.get(
                f"https://api.kie.ai/api/v1/veo/record-info?taskId={task_id}",
                headers={"Authorization": f"Bearer {KIE_API_KEY}"}
            )
            
            if response.status_code == 200:
                return response.json()
            else:
                print(f"VEO3 API ошибка для {task_id}: {response.status_code}")
                return None
                
    except Exception as e:
        print(f"Ошибка проверки VEO3 статуса {task_id}: {e}")
        return None


async def check_suno_status(task_id: str) -> dict:
    """Проверка статуса Suno задачи"""
    try:
        async with httpx.AsyncClient(timeout=30) as client:
            response = await client.get(
                f"https://api.kie.ai/api/v1/suno/record-info?taskId={task_id}",
                headers={"Authorization": f"Bearer {KIE_API_KEY}"}
            )
            
            if response.status_code == 200:
                return response.json()
            else:
                print(f"Suno API ошибка для {task_id}: {response.status_code}")
                return None
                
    except Exception as e:
        print(f"Ошибка проверки Suno статуса {task_id}: {e}")
        return None


async def check_nano_banana_status(task_id: str) -> dict:
    """Проверка статуса Nano Banana задачи"""
    try:
        async with httpx.AsyncClient(timeout=30) as client:
            response = await client.get(
                f"https://api.kie.ai/api/v1/jobs/recordInfo?taskId={task_id}",
                headers={"Authorization": f"Bearer {KIE_API_KEY}"}
            )
            
            if response.status_code == 200:
                return response.json()
            else:
                print(f"Nano Banana API ошибка для {task_id}: {response.status_code}")
                return None
                
    except Exception as e:
        print(f"Ошибка проверки Nano Banana статуса {task_id}: {e}")
        return None


async def process_task_result(task: dict, status_data: dict):
    """Обработка результата задачи"""
    task_id = task['task_id']
    user_id = task['user_telegram_id']
    request_type = task['request_type']
    cost = task['cost']
    
    try:
        code = status_data.get('code')
        msg = status_data.get('msg', '')
        data = status_data.get('data', {})
        
        # KIE jobs/recordInfo: статус в data.state или data.status; URL часто в data.resultJson
        task_status = (data.get('status') or data.get('state') or '').lower()
        result_urls = list(data.get('resultUrls') or [])
        if not result_urls:
            result_json_data = data.get('resultJson')
            if isinstance(result_json_data, str):
                try:
                    result_json_data = json.loads(result_json_data)
                except json.JSONDecodeError:
                    result_json_data = {}
            if isinstance(result_json_data, dict):
                result_urls = list(
                    result_json_data.get('resultUrls')
                    or result_json_data.get('images')
                    or []
                )
                if not result_urls and isinstance(result_json_data.get('result'), str):
                    result_urls = [result_json_data['result']]
        has_result_urls = bool(result_urls)
        
        print(f"Задача {task_id}: status='{task_status}', has_result_urls={has_result_urls}, code={code}")
        
        in_progress = {
            'pending', 'processing', 'waiting', 'queued', 'queue',
            'running', 'generating', 'working',
        }
        if task_status in in_progress:
            print(f"Задача {task_id} ещё в работе ({task_status})")
            return
            
        elif task_status == 'failed':
            # Задача завершилась с ошибкой
            from database import refund_balance, update_request_status
            refund_balance(user_id, cost)
            update_request_status(task_id, "failed", f"Задача завершилась с ошибкой: {msg}")
            
            error_text = f"❌ Генерация завершилась с ошибкой. Средства возвращены на баланс."
            if msg:
                error_text += f"\n\nПричина: {msg}"
                
            await send_telegram_message(chat_id=user_id, text=error_text)
            return
            
        elif not task_status and code == 200 and not has_result_urls:
            # Задача еще выполняется (нет статуса, нет результатов)
            print(f"Задача {task_id} еще выполняется (нет статуса, нет результатов)")
            return  # Не обновляем статус в БД, ждем следующую проверку
            
        elif task_status in ('completed', 'success') or (code == 200 and has_result_urls):
            # Успешная генерация (KIE nano-banana: state=success + resultJson)
            if request_type == 'sora2':
                if result_urls:
                    # Списываем баланс
                    from database import deduct_balance, update_request_status, update_partner_commission
                    deduct_balance(user_id, cost)
                    update_request_status(task_id, "completed")
                    
                    # Начисляем комиссию партнеру
                    print(f"🔄 [KIE_API] Вызываем update_partner_commission для user_id={user_id}, cost={cost}")
                    update_partner_commission(user_id, cost)
                    
                    # Отправляем результат пользователю
                    for url in result_urls:
                        await send_telegram_message(
                            chat_id=user_id,
                            text=f"✅ Видео готово: {url}"
                        )
                else:
                    # Нет результата
                    from database import refund_balance, update_request_status
                    refund_balance(user_id, cost)
                    update_request_status(task_id, "failed", "Отсутствуют URL результатов")
                    
                    await send_telegram_message(
                        chat_id=user_id,
                        text="❌ Видео не сгенерировано: отсутствуют URL результатов. Средства возвращены на баланс."
                    )
            elif request_type == 'veo3':
                # Аналогично для VEO3
                if result_urls:
                    from database import deduct_balance, update_request_status, update_partner_commission
                    deduct_balance(user_id, cost)
                    update_request_status(task_id, "completed")
                    
                    # Начисляем комиссию партнеру
                    print(f"🔄 [KIE_API] Вызываем update_partner_commission для user_id={user_id}, cost={cost}")
                    update_partner_commission(user_id, cost)
                    
                    for url in result_urls:
                        await send_telegram_message(
                            chat_id=user_id,
                            text=f"✅ Видео готово: {url}"
                        )
                else:
                    from database import refund_balance, update_request_status
                    refund_balance(user_id, cost)
                    update_request_status(task_id, "failed", "Отсутствуют URL результатов")
                    
                    await send_telegram_message(
                        chat_id=user_id,
                        text="❌ Видео не сгенерировано: отсутствуют URL результатов. Средства возвращены на баланс."
                    )
            elif request_type == 'suno':
                # Обработка Suno
                if result_urls:
                    from database import deduct_balance, update_request_status, update_partner_commission
                    deduct_balance(user_id, cost)
                    update_request_status(task_id, "completed")
                    
                    # Начисляем комиссию партнеру
                    print(f"🔄 [KIE_API] Вызываем update_partner_commission для user_id={user_id}, cost={cost}")
                    update_partner_commission(user_id, cost)
                    
                    for url in result_urls:
                        await send_telegram_message(
                            chat_id=user_id,
                            text=f"✅ Музыка готова: {url}"
                        )
                else:
                    from database import refund_balance, update_request_status
                    refund_balance(user_id, cost)
                    update_request_status(task_id, "failed", "Отсутствуют URL результатов")
                    
                    await send_telegram_message(
                        chat_id=user_id,
                        text="❌ Музыка не сгенерирована: отсутствуют URL результатов. Средства возвращены на баланс."
                    )
            elif request_type == 'nano_banana':
                # Обработка Nano Banana
                if result_urls:
                    from database import deduct_balance, update_request_status, update_partner_commission
                    deduct_balance(user_id, cost)
                    update_request_status(task_id, "completed")
                    
                    # Начисляем комиссию партнеру
                    print(f"🔄 [KIE_API] Вызываем update_partner_commission для user_id={user_id}, cost={cost}")
                    update_partner_commission(user_id, cost)
                    
                    for url in result_urls:
                        await send_telegram_message(
                            chat_id=user_id,
                            text=f"✅ Изображение готово: {url}"
                        )
                else:
                    from database import refund_balance, update_request_status
                    refund_balance(user_id, cost)
                    update_request_status(task_id, "failed", "Отсутствуют URL результатов")
                    
                    await send_telegram_message(
                        chat_id=user_id,
                        text="❌ Изображение не сгенерировано: отсутствуют URL результатов. Средства возвращены на баланс."
                    )
            
        else:
            # Неизвестный статус или ошибка API
            if task_status:
                print(f"Неизвестный статус задачи {task_id}: {task_status}")
                return  # Не обновляем статус, ждем следующую проверку
            # Ошибка генерации
            from database import refund_balance, update_request_status
            refund_balance(user_id, cost)
            update_request_status(task_id, "failed", f"API ошибка: {msg}")
            
            error_text = f"❌ Ошибка генерации. Средства возвращены на баланс."
            if code == 400:
                error_text += "\n\nПричина: Промпт нарушает политику контента"
            elif code == 401:
                error_text += "\n\nПричина: Неверный API ключ"
            elif code == 422:
                error_text += "\n\nПричина: Ошибка валидации параметров"
            else:
                error_text += f"\n\nКод ошибки: {code}"
                
            await send_telegram_message(chat_id=user_id, text=error_text)
            
    except Exception as e:
        print(f"Ошибка обработки результата задачи {task_id}: {e}")


# Периодическая проверка каждую минуту
import asyncio
from datetime import datetime

async def start_task_monitor():
    """Запуск мониторинга задач"""
    while True:
        try:
            await check_pending_tasks()
            print(f"[{datetime.now()}] Мониторинг pending задач выполнен")
        except Exception as e:
            print(f"Ошибка мониторинга: {e}")
        
        # Ждем 1 минуту
        await asyncio.sleep(60)

# --- Голос в текст ---
def get_speech_to_text_price(duration_sec):
    '''
    duration_sec — длительность аудио в секундах
    '''
    # API ElevenLabs Scribe: 3.5 credits/min ~ $0.0175 за минуту
    CREDITS_PER_MIN = 3.5
    USD_PER_CREDIT = 0.005  # 1 credit = $0.005
    RUB_PER_USD = 91  # Курс
    MARKUP = SPEECH_TO_TEXT_MARKUP  # 0.3 = +30%. 
    min_per_audio = duration_sec / 60
    price_usd = min_per_audio * CREDITS_PER_MIN * USD_PER_CREDIT
    price_rub = price_usd * RUB_PER_USD
    price_with_markup = round(price_rub * (1 + MARKUP), 2)
    return price_with_markup

async def create_speech_to_text_task(audio_url: str, language_code: str = None, tag_audio_events: bool = False, diarize: bool = False, callback_url: str = None, model: str = "elevenlabs/speech-to-text") -> dict:
    '''
    Создаёт задачу транскрибации через ElevenLabs Scribe API (POST /api/v1/jobs/createTask)
    model example: 'elevenlabs/speech-to-text' (по-умолчанию) или другая, если понадобится.
    '''
    headers = {
        "Authorization": f"Bearer {KIE_API_KEY}",
        "Content-Type": "application/json"
    }
    payload = {
        "model": model or "elevenlabs/speech-to-text"
    }
    if callback_url:
        payload["callBackUrl"] = callback_url
    input_params = {
        "audio_url": audio_url,
        "tag_audio_events": tag_audio_events,
        "diarize": diarize
    }
    if language_code:
        input_params["language_code"] = language_code
    payload["input"] = input_params
    async with httpx.AsyncClient(timeout=300) as client:
        try:
            response = await client.post("https://api.kie.ai/api/v1/jobs/createTask", headers=headers, json=payload)
            response.raise_for_status()
            result = response.json()
            return result
        except httpx.HTTPStatusError as e:
            return {"error": str(e), "status_code": e.response.status_code, "response_text": e.response.text}
        except Exception as e:
            return {"error": str(e)}

@app.post("/speech-to-text-callback")
async def speech_to_text_callback(request: Request):
    data = await request.json()
    print("=== SPEECH-TO-TEXT CALLBACK === Получен callback:", data)
    code = data.get("code")
    msg = data.get("msg")
    task_data = data.get("data", {})
    task_id = task_data.get("taskId") or task_data.get("task_id")
    if not task_id:
        print("SPEECH-TO-TEXT CALLBACK: Нет task_id!")
        return {"status": "error", "reason": "no task_id"}
    # Получаем chat_id
    task_info = get_task_info(task_id)
    if not task_info:
        print(f"⚠️ Не найдена информация о S2T/VOICE2TEXT задаче taskId: {task_id}")
        return {"status": "received"}
    chat_id = task_info[0] if isinstance(task_info, (tuple, list)) else task_info
    print(f"Speech2Text callback: chat_id = {chat_id}, code = {code}")
    # Проверка состояния
    state = task_data.get("state")
    if code == 200 and state == "success":
        result_json_str = task_data.get("resultJson")
        print(f"S2T: resultJson = {result_json_str}")
        try:
            result_json = json.loads(result_json_str) if isinstance(result_json_str, str) else result_json_str
        except Exception as e:
            result_json = None
            print(f"S2T: Ошибка декодирования JSON: {e}")
        if not result_json:
            await send_telegram_message(chat_id, text="❌ Ошибка декодирования распознанного текста (нет JSON)")
            return {"status": "done"}
        # Поддержка разных структур resultJson (list of words, text, resultObject)
        text_parts = []
        # Пробуем разные структуры распознанного текста
        if "text" in result_json:
            text_parts.append(result_json.get("text"))
        elif "resultObject" in result_json and isinstance(result_json["resultObject"], dict):
            obj = result_json["resultObject"]
            if "text" in obj:
                text_parts.append(obj["text"])
            elif "words" in obj and isinstance(obj["words"], list):
                text = " ".join([w.get("text", "") for w in obj["words"]])
                text_parts.append(text)
        elif "words" in result_json and isinstance(result_json["words"], list):
            text = " ".join([w.get("text", "") for w in result_json["words"]])
            text_parts.append(text)
        # Склеиваем
        final_text = "\n".join(t for t in text_parts if t and t.strip())
        if not final_text:
            final_text = "❌ Результат не распознан или пуст."
        await send_telegram_message(chat_id, text=f"🗣️ Результат распознавания:\n\n{final_text}")
        print(f"Speech2Text отправил результат в чат_id {chat_id}")
    else:
        error_msg = msg or "Ошибка генерации текста из голоса"
        await send_telegram_message(chat_id, text=f"❌ Распознавание не удалось! {error_msg}")
        print(f"Speech2Text fail: {task_id} code {code} {error_msg}")
    return {"status": "done"}

# Удаляем старую переменную CREDIT_RUB и определяем глобальные константы для пересчёта в меню
USD_RUB = 91  # текущий курс USD→RUB
PRICE_MULT = 1.3  # множитель для пользовательских цен

# Возвращает RUB-цены для меню/отображения с учетом курса и коэффициента. Все округлены до 2 знаков.
def get_all_user_prices():
    prices = {}
    # Veo 3
    fast_usd = BASE_PRICES['Veo 3']['fast']
    quality_usd = BASE_PRICES['Veo 3']['quality']
    prices['veo3_fast'] = round(fast_usd * USD_RUB * PRICE_MULT, 2)
    prices['veo3_quality'] = round(quality_usd * USD_RUB * PRICE_MULT, 2)
    # SORA 2
    sora2_text_usd = BASE_PRICES['SORA 2']['text']
    sora2_image_usd = BASE_PRICES['SORA 2']['image']
    prices['sora2_text'] = round(sora2_text_usd * USD_RUB * PRICE_MULT, 2)
    prices['sora2_image'] = round(sora2_image_usd * USD_RUB * PRICE_MULT, 2)
    # SORA 2 Pro
    prices['sora2pro_standard_10s'] = round(BASE_PRICES['SORA 2 Pro']['standard_10s'] * USD_RUB * PRICE_MULT, 2)
    prices['sora2pro_standard_15s'] = round(BASE_PRICES['SORA 2 Pro']['standard_15s'] * USD_RUB * PRICE_MULT, 2)
    prices['sora2pro_hd_10s'] = round(BASE_PRICES['SORA 2 Pro']['hd_10s'] * USD_RUB * PRICE_MULT, 2)
    prices['sora2pro_hd_15s'] = round(BASE_PRICES['SORA 2 Pro']['hd_15s'] * USD_RUB * PRICE_MULT, 2)
    # Suno — примерная цена в $; при наличии точных значений подставьте их
    prices['suno'] = round(0.17 * USD_RUB * PRICE_MULT, 2)
    # Nano Banana — примерная цена в $; при наличии точных значений подставьте их
    prices['nano_banana'] = round(0.055 * USD_RUB * PRICE_MULT, 2)
    # TTS
    tts_usd = BASE_PRICES['ElevenLabs TTS']['multilingual_v2']
    prices['tts'] = round(tts_usd * USD_RUB * PRICE_MULT, 2)
    # STT (Scribe): 3.5 credits × $0.0055 per credit = $0.01925 per minute
    scribe_usd = 3.5 * 0.0055
    prices['stt'] = round(scribe_usd * USD_RUB * PRICE_MULT, 2)
    # Grok Imagine — фикс 18₽ за 6с
    prices['grok_imagine'] = 18
    return prices

async def get_all_user_prices_async() -> dict:
    current_rate = await get_current_dollar_rate()
    multiplier = 1 + (GENERATION_MARKUP / 100)
    prices = {}
    # Veo 3
    prices['veo3_fast'] = round(BASE_PRICES['Veo 3']['fast'] * current_rate * multiplier, 2)
    prices['veo3_quality'] = round(BASE_PRICES['Veo 3']['quality'] * current_rate * multiplier, 2)
    # SORA 2
    prices['sora2_text'] = round(BASE_PRICES['SORA 2']['text'] * current_rate * multiplier, 2)
    prices['sora2_image'] = round(BASE_PRICES['SORA 2']['image'] * current_rate * multiplier, 2)
    # SORA 2 Pro
    prices['sora2pro_standard_10s'] = round(BASE_PRICES['SORA 2 Pro']['standard_10s'] * current_rate * multiplier, 2)
    prices['sora2pro_standard_15s'] = round(BASE_PRICES['SORA 2 Pro']['standard_15s'] * current_rate * multiplier, 2)
    prices['sora2pro_hd_10s'] = round(BASE_PRICES['SORA 2 Pro']['hd_10s'] * current_rate * multiplier, 2)
    prices['sora2pro_hd_15s'] = round(BASE_PRICES['SORA 2 Pro']['hd_15s'] * current_rate * multiplier, 2)
    # Suno & Nano Banana — использовать их $-цены, при необходимости уточнить
    prices['suno'] = round(0.17 * current_rate * multiplier, 2)
    prices['nano_banana'] = round(0.055 * current_rate * multiplier, 2)
    # TTS (за 1000 символов)
    prices['tts'] = round(BASE_PRICES['ElevenLabs TTS']['multilingual_v2'] * current_rate * multiplier, 2)
    # STT (Scribe) — 3.5 кред/мин × $0.0055
    scribe_usd = 3.5 * 0.0055
    prices['stt'] = round(scribe_usd * current_rate * multiplier, 2)
    # Grok Imagine — фикс 18₽ за 6с
    prices['grok_imagine'] = 18
    return prices

# Единый расчет цен для меню, используя те же хелперы, что и при списании
async def get_menu_prices_unified() -> dict:
    prices: Dict[str, Any] = {}
    try:
        # Veo 3 (используем тот же helper списания)
        prices['veo3_fast'] = await get_generation_cost("Veo 3", "fast")
        prices['veo3_quality'] = await get_generation_cost("Veo 3", "quality")
        # SORA 2
        prices['sora2_text'] = await get_generation_cost("SORA 2", "text")
        prices['sora2_image'] = await get_generation_cost("SORA 2", "image")
        # SORA 2 Pro
        prices['sora2pro_standard_10s'] = await get_sora2_pro_cost("10", "standard")
        prices['sora2pro_standard_15s'] = await get_sora2_pro_cost("15", "standard")
        prices['sora2pro_hd_10s'] = await get_sora2_pro_cost("10", "hd")
        prices['sora2pro_hd_15s'] = await get_sora2_pro_cost("15", "hd")
        # Suno и Nano Banana через общий helper (если модель поддерживается)
        try:
            prices['suno'] = await get_generation_cost("Suno")
        except Exception:
            prices['suno'] = await get_generation_cost("Suno")
        # Если для Nano Banana нет записи в MODELS_AVAILABLE, используем безопасный дефолт
        try:
            prices['nano_banana'] = await get_generation_cost("Nano Banana")
        except Exception:
            # вернем прошлое меню значение на основе USD курса
            current_rate = await get_current_dollar_rate()
            prices['nano_banana'] = int(round(0.055 * current_rate * (1 + GENERATION_MARKUP / 100)))
        # TTS (за 1000 символов)
        prices['tts'] = await get_tts_cost("x" * 1000)
        # STT (за 1 минуту) — используем тот же helper, что и при списании
        prices['stt'] = int(round(get_speech_to_text_price(60)))
        # Grok Imagine — 18₽ за ролик 6с
        prices['grok_imagine'] = 18
    except Exception:
        # В случае любой ошибки не валим вызов, а возвращаем пустой dict, чтобы меню не падало
        pass
    return prices
