# REST API для мобильного приложения RuStore (Прилка AI)
# Запуск: uvicorn app_api:app --host 0.0.0.0 --port 8011

import os
import json
import asyncio
import logging
from typing import Optional, List
from fastapi import FastAPI, Header, HTTPException, Depends, UploadFile, File, Request
from fastapi.middleware.cors import CORSMiddleware
from pydantic import BaseModel

logger = logging.getLogger(__name__)

# Импорты из существующего бота
from config import KIE_API_KEY, GENERATION_MARKUP, YOOMONEY_SUCCESS_URL
from database import (
    get_or_create_app_user,
    get_user_by_vk_id,
    get_user_by_device_id,
    get_user_by_id,
    get_user_by_email,
    get_user_by_app_id,
    create_user_email,
    get_balance_by_vk_id,
    get_balance_by_app_id,
    deduct_balance_by_app_id,
    ensure_referral_code,
    ensure_referral_code_app,
    ensure_referral_code_by_user_id,
    add_app_referral,
    get_partner_stats_by_vk,
    save_app_task,
    update_app_task_result,
    get_app_task,
    update_partner_commission_by_vk,
    get_user_generation_history,
)
from payment import generate_yoomoney_link_for_app, APP_TOKEN_PACKAGES
from config import JWT_SECRET, JWT_ALGORITHM, JWT_EXPIRE_DAYS

# Шаблоны редактирования изображений (все по 35 токенов)
IMAGE_TEMPLATES = [
    {"id": "face_swap", "name": "Замена лица", "description": "Подставить одно лицо на другое фото", "cost": 35, "inputs": 2, "prompt_hint": "Описание результата (необязательно)"},
    {"id": "try_on", "name": "Примерка одежды", "description": "Примерка одежды на человека", "cost": 35, "inputs": 2, "prompt_hint": "Одежда + фото человека"},
    {"id": "combine", "name": "Соединить объекты/людей", "description": "Объединить несколько фото в одно", "cost": 35, "inputs": -1, "prompt_hint": "Опишите, как совместить"},
    {"id": "upscale", "name": "Улучшить качество", "description": "Увеличить разрешение и детализацию", "cost": 35, "inputs": 1, "prompt_hint": ""},
    {"id": "background_replace", "name": "Замена фона", "description": "Заменить фон на фото", "cost": 35, "inputs": 1, "prompt_hint": "Описание нового фона"},
    {"id": "style_transfer", "name": "Перенос стиля", "description": "Применить стиль одного изображения к другому", "cost": 35, "inputs": 2, "prompt_hint": "Стиль + контент"},
    {"id": "generate_art", "name": "Генерация по описанию", "description": "Создать изображение по текстовому описанию", "cost": 35, "inputs": 0, "prompt_hint": "Опишите изображение"},
    {"id": "remove_background", "name": "Удаление фона", "description": "Убрать фон, оставить объект", "cost": 35, "inputs": 1, "prompt_hint": ""},
    {"id": "object_remove", "name": "Удалить объект", "description": "Удалить выбранный объект с фото", "cost": 35, "inputs": 1, "prompt_hint": "Что удалить"},
]

# Стоимости видео рассчитываются динамически на основе цен из бота
# Используются те же функции расчета, что и в Telegram боте

app = FastAPI(title="Прилка AI — API приложения", version="1.0.0")
app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)


def get_vk_user_id(x_vk_user_id: Optional[str] = Header(None, alias="X-VK-User-Id")) -> str:
    if not x_vk_user_id:
        raise HTTPException(status_code=401, detail="Требуется заголовок X-VK-User-Id")
    return x_vk_user_id.strip()


def _decode_jwt_token(authorization: Optional[str]) -> Optional[dict]:
    if not authorization or not authorization.startswith("Bearer "):
        return None
    import jwt
    try:
        payload = jwt.decode(
            authorization[7:].strip(),
            JWT_SECRET,
            algorithms=[JWT_ALGORITHM],
        )
        return payload
    except Exception:
        return None


def get_current_user(
    x_vk_user_id: Optional[str] = Header(None, alias="X-VK-User-Id"),
    x_device_id: Optional[str] = Header(None, alias="X-Device-Id"),
    authorization: Optional[str] = Header(None),
) -> dict:
    """Текущий пользователь: Bearer JWT (email), X-VK-User-Id или X-Device-Id."""
    if authorization:
        payload = _decode_jwt_token(authorization)
        if payload and payload.get("user_id"):
            user = get_user_by_id(int(payload["user_id"]))
            if user:
                user["_app_id"] = str(user["id"])
                return user
            raise HTTPException(status_code=404, detail="Пользователь не найден")
    if x_vk_user_id and x_vk_user_id.strip():
        user = get_user_by_vk_id(x_vk_user_id.strip())
        if user:
            user["_app_id"] = user["vk_user_id"]
            return user
        raise HTTPException(status_code=404, detail="Пользователь не найден")
    if x_device_id and x_device_id.strip():
        user = get_user_by_device_id(x_device_id.strip())
        if user:
            user["_app_id"] = user["device_id"]
            return user
        raise HTTPException(status_code=404, detail="Пользователь не найден")
    raise HTTPException(status_code=401, detail="Требуется авторизация: Bearer токен, X-VK-User-Id или X-Device-Id")


# --- Модели запросов ---
class AuthRequest(BaseModel):
    vk_user_id: str
    display_name: Optional[str] = None
    referral_code: Optional[str] = None


class AuthRegisterRequest(BaseModel):
    email: str
    password: str


class AuthLoginRequest(BaseModel):
    email: str
    password: str


class PasswordResetRequest(BaseModel):
    email: str


class PasswordResetConfirmRequest(BaseModel):
    token: str
    new_password: str


class PaymentCreateRequest(BaseModel):
    amount: float


class GenerateImageRequest(BaseModel):
    template_id: str
    prompt: Optional[str] = None
    image_urls: List[str] = []


class GenerateVideoRequest(BaseModel):
    prompt: str
    image_url: Optional[str] = None
    model: Optional[str] = None  # ID модели нейросети (veo3_fast, veo3_quality, sora2_text, sora2_image, etc.)


# --- Auth ---
@app.post("/api/v1/auth/vk")
def auth_vk(body: AuthRequest):
    """Регистрация/вход по VK ID. При referral_code привязывает к партнёру."""
    user, created = get_or_create_app_user(body.vk_user_id, body.display_name or "")
    if body.referral_code and body.referral_code.strip():
        from database import get_connection
        conn = get_connection()
        cur = conn.cursor()
        cur.execute("SELECT vk_user_id FROM users WHERE referral_code = %s LIMIT 1", (body.referral_code.strip(),))
        row = cur.fetchone()
        conn.close()
        ref_owner = row.get("vk_user_id") if row else None
        if ref_owner and str(ref_owner) != str(body.vk_user_id):
            add_app_referral(str(ref_owner), str(body.vk_user_id))
    referral_code = ensure_referral_code(body.vk_user_id)
    balance = get_balance_by_vk_id(body.vk_user_id)
    return {
        "user_id": user.get("id"),
        "vk_user_id": body.vk_user_id,
        "display_name": user.get("app_display_name") or body.display_name or "",
        "balance": balance,
        "referral_code": referral_code,
        "is_new": created,
    }


def _hash_password(password: str) -> str:
    import bcrypt
    # Bcrypt ограничение: пароль не более 72 байт
    # Обрезаем до 72 байт точно
    password_bytes = password.encode('utf-8')
    if len(password_bytes) > 72:
        password_bytes = password_bytes[:72]
    # Используем bcrypt напрямую для избежания проблем совместимости
    salt = bcrypt.gensalt()
    hashed = bcrypt.hashpw(password_bytes, salt)
    return hashed.decode('utf-8')


def _verify_password(plain: str, hashed: str) -> bool:
    import bcrypt
    # Bcrypt ограничение: пароль не более 72 байт
    # Обрезаем до 72 байт для проверки (как при хешировании)
    password_bytes = plain.encode('utf-8')
    if len(password_bytes) > 72:
        password_bytes = password_bytes[:72]
    # Используем bcrypt напрямую
    try:
        return bcrypt.checkpw(password_bytes, hashed.encode('utf-8'))
    except Exception:
        return False


def _create_token(user_id: int, email: str) -> str:
    import jwt
    from datetime import datetime, timedelta
    expire = datetime.utcnow() + timedelta(days=JWT_EXPIRE_DAYS)
    return jwt.encode(
        {"user_id": user_id, "email": email, "exp": expire},
        JWT_SECRET,
        algorithm=JWT_ALGORITHM,
    )


@app.post("/api/v1/auth/register")
def auth_register(body: AuthRegisterRequest):
    """Регистрация по email и паролю. После регистрации возвращается auth_token для последующих запросов."""
    import logging
    logger = logging.getLogger(__name__)
    
    email = (body.email or "").strip().lower()
    if not email or "@" not in email:
        raise HTTPException(status_code=400, detail="Введите корректный email")
    if not body.password or len(body.password) < 6:
        raise HTTPException(status_code=400, detail="Пароль не менее 6 символов")
    if get_user_by_email(email):
        raise HTTPException(status_code=400, detail="Пользователь с таким email уже зарегистрирован")
    user = create_user_email(email, _hash_password(body.password))
    referral_code = ensure_referral_code_by_user_id(user["id"])
    token = _create_token(user["id"], email)
    
    # Отправка приветственного письма (в фоне, не блокируем ответ)
    try:
        from email_service import send_welcome_email
        display_name = user.get("app_display_name") or email.split("@")[0]
        email_sent = send_welcome_email(email, display_name)
        if email_sent:
            logger.info(f"✅ Приветственное письмо успешно отправлено на {email}")
        else:
            logger.warning(f"⚠️ Не удалось отправить приветственное письмо на {email}")
    except Exception as e:
        logger.error(f"❌ Ошибка отправки приветственного письма на {email}: {e}", exc_info=True)
        # Не прерываем регистрацию, если письмо не отправилось
    
    return {
        "user_id": user["id"],
        "email": user["email"],
        "display_name": user.get("app_display_name") or "",
        "balance": 0,
        "referral_code": referral_code,
        "auth_token": token,
    }


@app.post("/api/v1/auth/login")
def auth_login(body: AuthLoginRequest):
    """Вход по email и паролю. Возвращает auth_token."""
    email = (body.email or "").strip().lower()
    if not email or not body.password:
        raise HTTPException(status_code=400, detail="Введите email и пароль")
    user = get_user_by_email(email)
    if not user or not user.get("password_hash"):
        raise HTTPException(status_code=401, detail="Неверный email или пароль")
    if not _verify_password(body.password, user["password_hash"]):
        raise HTTPException(status_code=401, detail="Неверный email или пароль")
    referral_code = ensure_referral_code_by_user_id(user["id"])
    balance = float(user.get("balance") or 0)
    token = _create_token(user["id"], user["email"])
    return {
        "user_id": user["id"],
        "email": user["email"],
        "display_name": user.get("app_display_name") or "",
        "balance": balance,
        "referral_code": referral_code,
        "auth_token": token,
    }


@app.post("/api/v1/auth/password/reset")
def password_reset_request(body: PasswordResetRequest):
    """Запрос на восстановление пароля. Отправляет письмо со ссылкой для сброса."""
    import logging
    import secrets
    logger = logging.getLogger(__name__)
    
    email = (body.email or "").strip().lower()
    if not email or "@" not in email:
        raise HTTPException(status_code=400, detail="Введите корректный email")
    
    user = get_user_by_email(email)
    if not user:
        # Не раскрываем, существует ли пользователь (безопасность)
        return {"message": "Если пользователь с таким email существует, письмо отправлено"}
    
    # Генерируем токен
    reset_token = secrets.token_urlsafe(32)
    
    # Сохраняем токен в БД
    from database import create_password_reset_token
    if create_password_reset_token(user["id"], reset_token, expires_in_hours=1):
        # Отправляем письмо
        try:
            from email_service import send_password_reset_email
            email_sent = send_password_reset_email(email, reset_token)
            if email_sent:
                logger.info(f"✅ Письмо для восстановления пароля успешно отправлено на {email}")
            else:
                logger.error(f"❌ Не удалось отправить письмо для восстановления пароля на {email}")
                raise HTTPException(status_code=500, detail="Ошибка отправки письма. Проверьте настройки SMTP.")
        except HTTPException:
            raise
        except Exception as e:
            logger.error(f"❌ Ошибка отправки письма для восстановления пароля на {email}: {e}", exc_info=True)
            raise HTTPException(status_code=500, detail=f"Ошибка отправки письма: {str(e)}")
    else:
        logger.error(f"❌ Не удалось создать токен восстановления пароля для {email}")
        raise HTTPException(status_code=500, detail="Ошибка создания токена восстановления")
    
    return {"message": "Если пользователь с таким email существует, письмо отправлено"}


@app.post("/api/v1/auth/password/reset/confirm")
def password_reset_confirm(body: PasswordResetConfirmRequest):
    """Подтверждение восстановления пароля по токену."""
    import logging
    logger = logging.getLogger(__name__)
    
    if not body.token or len(body.token) < 10:
        raise HTTPException(status_code=400, detail="Неверный токен")
    
    if not body.new_password or len(body.new_password) < 6:
        raise HTTPException(status_code=400, detail="Пароль не менее 6 символов")
    
    # Проверяем токен
    from database import get_password_reset_token, mark_password_reset_token_as_used, update_user_password
    token_data = get_password_reset_token(body.token)
    
    if not token_data:
        raise HTTPException(status_code=400, detail="Неверный или истекший токен")
    
    user_id = token_data["user_id"]
    
    # Обновляем пароль
    new_password_hash = _hash_password(body.new_password)
    if update_user_password(user_id, new_password_hash):
        # Отмечаем токен как использованный
        mark_password_reset_token_as_used(body.token)
        logger.info(f"Пароль успешно изменен для user_id={user_id}")
        return {"message": "Пароль успешно изменен"}
    else:
        raise HTTPException(status_code=500, detail="Ошибка обновления пароля")


@app.get("/api/v1/me")
def get_me(user: dict = Depends(get_current_user)):
    """Профиль и баланс (email, VK или гость)."""
    if user.get("email"):
        referral_code = ensure_referral_code_by_user_id(user["id"])
    else:
        referral_code = ensure_referral_code_app(user["_app_id"])
    return {
        "user_id": user["id"],
        "email": user.get("email"),
        "vk_user_id": user.get("vk_user_id"),
        "device_id": user.get("device_id"),
        "display_name": user.get("app_display_name") or "",
        "balance": float(user.get("balance") or 0),
        "referral_code": referral_code,
    }


@app.get("/api/v1/balance")
def get_balance(user: dict = Depends(get_current_user)):
    return {"balance": get_balance_by_app_id(user["_app_id"])}


# --- Платежи ---
@app.post("/api/v1/payments/create")
def create_payment(body: PaymentCreateRequest, user: dict = Depends(get_current_user)):
    """Создать ссылку на пополнение (YooMoney)."""
    amount = round(body.amount)
    if amount not in APP_TOKEN_PACKAGES:
        raise HTTPException(
            status_code=400,
            detail=f"Доступные суммы: {list(APP_TOKEN_PACKAGES.keys())} руб.",
        )
    if user.get("email"):
        link, label = generate_yoomoney_link_for_app(user_id=user["id"], amount=float(amount))
    else:
        link, label = generate_yoomoney_link_for_app(
            vk_user_id=user.get("vk_user_id"),
            device_id=user.get("device_id"),
            amount=float(amount),
        )
    return {"payment_url": link, "label": label, "amount": amount, "tokens": APP_TOKEN_PACKAGES[amount]}


# --- Партнёрская программа ---
@app.get("/api/v1/partner/stats")
def partner_stats(user: dict = Depends(get_current_user)):
    """Для гостей возвращаются нули; партнёрка по VK ID."""
    if user.get("vk_user_id"):
        return get_partner_stats_by_vk(user["vk_user_id"])
    return {"total_referrals": 0, "total_purchases": 0, "total_commission": 0, "available_for_withdrawal": 0}


@app.get("/api/v1/partner/link")
def partner_link(user: dict = Depends(get_current_user)):
    code = ensure_referral_code_app(user["_app_id"])
    return {"referral_code": code, "referral_url": f"https://www.rustore.ru/...?ref={code}"}


# --- Шаблоны изображений ---
@app.get("/api/v1/templates/image")
def list_image_templates():
    """Список шаблонов генерации изображений (без карточек маркетплейса)."""
    return {"templates": IMAGE_TEMPLATES}

# Нейросети для редактирования фото (как в Telegram боте)
IMAGE_EDITING_MODELS = [
    {
        "id": "nano_banana_edit",
        "name": "Nano Banana Edit",
        "description": "Базовое редактирование изображений: замена лица, примерка одежды, объединение фото, замена фона, перенос стиля, удаление фона/объекта",
        "cost": 35,
        "inputs": 1,
        "prompt_hint": "Опишите, что нужно изменить",
        "neural_network": "Nano Banana"
    },
    {
        "id": "seedream_edit",
        "name": "Seedream 4.5 Edit",
        "description": "Продвинутое редактирование изображений с высоким качеством. Поддерживает выбор качества (Basic 2K или High 4K) и соотношения сторон",
        "cost": 25,  # Минимальная стоимость (Basic)
        "cost_high": 40,  # High качество
        "inputs": 1,
        "prompt_hint": "Опишите, что нужно изменить",
        "neural_network": "Seedream 4.5",
        "supports_quality": True,
        "supports_aspect_ratio": True
    },
    {
        "id": "topaz_upscale",
        "name": "Topaz Image Upscale",
        "description": "Увеличение разрешения и улучшение детализации изображения",
        "cost": 50,
        "inputs": 1,
        "prompt_hint": "",
        "neural_network": "Topaz"
    }
]

@app.get("/api/v1/models/image-editing")
def list_image_editing_models():
    """Список нейросетей для редактирования изображений (как в Telegram боте)."""
    return {"models": IMAGE_EDITING_MODELS, "templates": IMAGE_TEMPLATES}

# Нейросети для генерации фото (как в Telegram боте)
IMAGE_GENERATION_MODELS = [
    {
        "id": "nano_banana_generate",
        "name": "Nano Banana",
        "description": "Генерация изображений из текста. Быстрое и качественное создание изображений по описанию.",
        "cost": None,  # Рассчитывается динамически на основе курса доллара
        "inputs": 0,
        "prompt_hint": "Опишите изображение, которое хотите создать",
        "neural_network": "Nano Banana"
    },
    {
        "id": "nano_banana_pro",
        "name": "Nano Banana Pro",
        "description": "Генерация изображений с расширенными параметрами на базе Gemini 3.0 Pro Image. Поддержка aspect_ratio, resolution, image_input (до 8 изображений).",
        "cost": 18,
        "inputs": 0,  # Может принимать до 8 изображений опционально
        "prompt_hint": "Опишите изображение, которое хотите создать",
        "neural_network": "Nano Banana Pro"
    }
]

@app.get("/api/v1/models/image-generation")
async def list_image_generation_models():
    """Список нейросетей для генерации изображений (как в Telegram боте)."""
    from kie_api import get_all_user_prices_async
    prices = await get_all_user_prices_async()
    
    # Обновляем цену для nano_banana_generate динамически
    models = []
    for model in IMAGE_GENERATION_MODELS:
        model_copy = model.copy()
        if model["id"] == "nano_banana_generate":
            # Используем динамическую цену из prices
            nano_price = prices.get("nano_banana", 5)  # Fallback на 5 если цена не найдена
            model_copy["cost"] = int(round(nano_price))
        models.append(model_copy)
    
    return {"models": models}

# Нейросети для генерации видео (как в Telegram боте)
VIDEO_GENERATION_MODELS = [
    {
        "id": "veo3_fast",
        "name": "Veo 3 Fast",
        "description": "Быстрая генерация видео из текста. Высокое качество за короткое время.",
        "cost": None,  # Рассчитывается динамически
        "neural_network": "Veo 3",
        "supports_image": False,
        "supports_text": True
    },
    {
        "id": "veo3_quality",
        "name": "Veo 3 Quality",
        "description": "Генерация видео высочайшего качества из текста. Максимальная детализация.",
        "cost": None,  # Рассчитывается динамически
        "neural_network": "Veo 3",
        "supports_image": False,
        "supports_text": True
    },
    {
        "id": "sora2_text",
        "name": "SORA 2 Text-to-Video",
        "description": "Генерация видео из текстового описания. Отличное качество и реалистичность.",
        "cost": None,  # Рассчитывается динамически
        "neural_network": "SORA 2",
        "supports_image": False,
        "supports_text": True
    },
    {
        "id": "sora2_image",
        "name": "SORA 2 Image-to-Video",
        "description": "Генерация видео из изображения и текстового описания. Анимирует статичное изображение.",
        "cost": None,  # Рассчитывается динамически
        "neural_network": "SORA 2",
        "supports_image": True,
        "supports_text": True
    },
    {
        "id": "sora2_pro",
        "name": "SORA 2 Pro",
        "description": "Профессиональная генерация видео с расширенными параметрами (длительность, качество HD).",
        "cost": None,  # Рассчитывается динамически (зависит от параметров)
        "neural_network": "SORA 2 Pro",
        "supports_image": True,
        "supports_text": True,
        "supports_options": True  # Поддержка выбора длительности и качества
    },
    {
        "id": "seedance",
        "name": "Seedance 1.0 Pro Fast",
        "description": "Быстрая генерация видео из изображения. Поддерживает выбор разрешения и длительности.",
        "cost": None,  # Рассчитывается динамически
        "neural_network": "Seedance",
        "supports_image": True,
        "supports_text": True
    },
    {
        "id": "runway",
        "name": "Runway",
        "description": "Генерация видео из текста или изображения. Продолжительность 5-10 секунд.",
        "cost": None,  # Рассчитывается динамически
        "neural_network": "Runway",
        "supports_image": True,
        "supports_text": True
    },
    {
        "id": "grok_imagine",
        "name": "Grok Imagine",
        "description": "Генерация короткого видео (6 секунд) из изображения.",
        "cost": None,  # Рассчитывается динамически
        "neural_network": "Grok Imagine",
        "supports_image": True,
        "supports_text": False
    }
]

@app.get("/api/v1/models/video-generation")
async def list_video_generation_models():
    """Список нейросетей для генерации видео (как в Telegram боте)."""
    from kie_api import get_generation_cost, get_sora2_pro_cost, get_seedance_price_options
    
    # Рассчитываем актуальные цены
    models_with_prices = []
    for model in VIDEO_GENERATION_MODELS:
        model_copy = model.copy()
        try:
            if model["id"] == "veo3_fast":
                cost = await get_generation_cost("Veo 3", "fast")
                model_copy["cost"] = cost
            elif model["id"] == "veo3_quality":
                cost = await get_generation_cost("Veo 3", "quality")
                model_copy["cost"] = cost
            elif model["id"] == "sora2_text":
                cost = await get_generation_cost("SORA 2", "text")
                model_copy["cost"] = cost
            elif model["id"] == "sora2_image":
                cost = await get_generation_cost("SORA 2", "image")
                model_copy["cost"] = cost
            elif model["id"] == "sora2_pro":
                # Минимальная цена для SORA 2 Pro
                cost = await get_sora2_pro_cost("10", "standard")
                model_copy["cost"] = cost
            elif model["id"] == "seedance":
                seedance_prices = get_seedance_price_options()
                if seedance_prices:
                    model_copy["cost"] = min(seedance_prices.values())
                    model_copy["cost_range"] = f"{min(seedance_prices.values())}-{max(seedance_prices.values())}"
            elif model["id"] == "runway":
                cost = await get_generation_cost("Runway")
                model_copy["cost"] = cost
            elif model["id"] == "grok_imagine":
                from kie_api import get_grok_imagine_cost
                model_copy["cost"] = get_grok_imagine_cost()
        except Exception as e:
            # Если не удалось рассчитать цену, оставляем None
            pass
        models_with_prices.append(model_copy)
    
    return {"models": models_with_prices}


# --- Генерация изображения ---
@app.post("/api/v1/generate/image")
async def generate_image(
    body: GenerateImageRequest,
    user: dict = Depends(get_current_user),
):
    """Запуск генерации изображения по шаблону или нейросети."""
    import logging
    logger = logging.getLogger(__name__)
    
    app_id = user["_app_id"]
    user_id = user.get("id")
    user_email = user.get("email")
    
    logger.info(f"[generate_image] Запрос генерации: app_id={app_id}, user_id={user_id}, email={user_email}, template_id={body.template_id}")
    
    # Проверяем, это шаблон редактирования, нейросеть редактирования или нейросеть генерации
    template = next((t for t in IMAGE_TEMPLATES if t["id"] == body.template_id), None)
    editing_model = next((m for m in IMAGE_EDITING_MODELS if m["id"] == body.template_id), None)
    generation_model = next((m for m in IMAGE_GENERATION_MODELS if m["id"] == body.template_id), None)
    
    if not template and not editing_model and not generation_model:
        raise HTTPException(status_code=400, detail="Неизвестный шаблон или нейросеть")
    
    # Определяем стоимость и требования
    if template:
        cost = template["cost"]
        inputs_required = template["inputs"]
        prompt_required = template["id"] == "generate_art"
    elif editing_model:
        cost = editing_model["cost"]
        inputs_required = editing_model["inputs"]
        prompt_required = False
    else:  # generation_model
        # Для генерации фото цена может быть динамической
        if generation_model["cost"] is not None:
            cost = generation_model["cost"]
        else:
            # Получаем динамическую цену
            from kie_api import get_all_user_prices_async
            prices = await get_all_user_prices_async()
            cost = int(round(prices.get("nano_banana", 5)))
        inputs_required = generation_model["inputs"]
        prompt_required = True  # Для генерации фото промпт обязателен
    
    # Получаем баланс с логированием
    logger.info(f"[generate_image] Получение баланса для app_id={app_id}")
    balance = get_balance_by_app_id(app_id)
    logger.info(f"[generate_image] Баланс получен: balance={balance}, cost={cost}, user_id={user_id}")
    
    # Дополнительная проверка: если баланс 0, попробуем получить напрямую по user_id
    if balance == 0.0 and user_id:
        logger.warning(f"[generate_image] Баланс через app_id равен 0, проверяю напрямую по user_id={user_id}")
        from database import get_balance_by_user_id
        balance_direct = get_balance_by_user_id(user_id)
        logger.info(f"[generate_image] Баланс напрямую по user_id: {balance_direct}")
        if balance_direct > 0:
            balance = balance_direct
    
    if balance < cost:
        logger.error(f"[generate_image] Недостаточно средств: balance={balance}, cost={cost}, app_id={app_id}, user_id={user_id}")
        raise HTTPException(status_code=402, detail=f"Недостаточно средств. Нужно {cost} токенов.")
    
    if prompt_required and not (body.prompt and body.prompt.strip()):
        raise HTTPException(status_code=400, detail="Для шаблона «Генерация по описанию» укажите промпт (текст описания изображения).")
    
    # Для шаблонов разрешаем загрузку нескольких фото (2, 3 или более)
    if template:
        # Для шаблонов проверяем только что есть хотя бы минимальное количество фото
        if inputs_required > 0 and (not body.image_urls or len(body.image_urls) < inputs_required):
            raise HTTPException(status_code=400, detail=f"Нужно минимум {inputs_required} изображений")
        # Для шаблонов с inputs = -1 (combine) разрешаем любое количество фото >= 2
        if inputs_required == -1 and (not body.image_urls or len(body.image_urls) < 2):
            raise HTTPException(status_code=400, detail="Нужно минимум 2 изображения")
    else:
        # Для нейросетей проверяем точное количество
        if inputs_required != 0 and not body.image_urls:
            raise HTTPException(status_code=400, detail="Загрузите нужное количество изображений")
        if inputs_required > 0 and len(body.image_urls) < inputs_required:
            raise HTTPException(status_code=400, detail=f"Нужно минимум {inputs_required} изображений")

    # Вызов Neuro Hub через существующие функции kie_api
    from kie_api import create_nano_banana_task, create_nano_banana_pro_task, create_topaz_upscale_task
    from kie_api import create_seedream_edit_task
    import httpx

    callback_base = os.getenv("APP_API_BASE_URL", "http://techscore.ru:8011").rstrip("/")
    # Используем тот же callback endpoint что и в телеграм боте для совместимости
    # Callback handlers в kie_api.py будут обрабатывать и задачи от приложения
    # Для nano_banana_pro используем тот же callback что и для nano_banana
    nano_callback_url = f"{callback_base}/nano-banana-callback"
    callback_url = nano_callback_url  # По умолчанию для нейросетей
    
    # Логируем URL изображений для отладки
    if body.image_urls:
        logger.info(f"[generate_image] Передаем в KIE API image_urls: {body.image_urls}")

    task_result = None
    
    # Обработка нейросетей генерации фото
    if generation_model:
        if generation_model["id"] == "nano_banana_generate":
            # Nano Banana - генерация из текста
            if not body.prompt:
                raise HTTPException(status_code=400, detail="Для генерации укажите промпт")
            task_result = await create_nano_banana_task(
                mode="generate",
                prompt=body.prompt,
                callback_url=nano_callback_url,
            )
        elif generation_model["id"] == "nano_banana_pro":
            # Nano Banana Pro - генерация с расширенными параметрами
            if not body.prompt:
                raise HTTPException(status_code=400, detail="Для генерации укажите промпт")
            
            # Проверяем доступность URL изображений перед передачей в KIE API
            if body.image_urls:
                import httpx
                available_urls = []
                for url in body.image_urls:
                    try:
                        async with httpx.AsyncClient(timeout=30.0, verify=False) as client:
                            # Используем GET вместо HEAD, так как некоторые серверы не поддерживают HEAD
                            # verify=False для поддержки самоподписанных сертификатов (если используется HTTPS)
                            response = await client.get(url, follow_redirects=True)
                            if response.status_code == 200:
                                available_urls.append(url)
                                logger.info(f"[generate_image] URL доступен: {url}, размер: {len(response.content)} байт, протокол: {'HTTPS' if url.startswith('https') else 'HTTP'}")
                            else:
                                logger.warning(f"[generate_image] URL недоступен (статус {response.status_code}): {url}")
                    except httpx.ConnectError as e:
                        logger.error(f"[generate_image] Ошибка подключения к URL {url}: {e}. Возможно, нужен HTTPS вместо HTTP.")
                    except Exception as e:
                        logger.error(f"[generate_image] Ошибка проверки URL {url}: {e}")
                
                if available_urls:
                    logger.info(f"[generate_image] Передаем в KIE API {len(available_urls)} доступных URL из {len(body.image_urls)}")
                    # Логируем предупреждение, если используется HTTP вместо HTTPS
                    http_urls = [url for url in available_urls if url.startswith('http://')]
                    if http_urls:
                        logger.warning(f"[generate_image] ВНИМАНИЕ: Используются HTTP URL вместо HTTPS. KIE API может не принять их: {http_urls}")
                    image_input = available_urls
                else:
                    logger.error(f"[generate_image] Ни один URL не доступен! Всего URL: {len(body.image_urls)}")
                    raise HTTPException(status_code=400, detail="Загруженные изображения недоступны. Попробуйте загрузить заново.")
            else:
                image_input = None
            
            task_result = await create_nano_banana_pro_task(
                prompt=body.prompt,
                aspect_ratio="1:1",
                image_input=image_input,
                callback_url=nano_callback_url,
            )
    # Обработка нейросетей редактирования
    elif editing_model:
        if editing_model["id"] == "nano_banana_edit":
            prompt = body.prompt or "улучшить изображение"
            # Используем тот же callback endpoint что и в телеграм боте
            nano_callback_url = f"{callback_base}/nano-banana-callback"
            task_result = await create_nano_banana_task(
                mode="edit",
                prompt=prompt,
                image_urls=body.image_urls,
                callback_url=nano_callback_url,
            )
        elif editing_model["id"] == "seedream_edit":
            # Seedream Edit требует промпт и изображение
            if not body.prompt:
                raise HTTPException(status_code=400, detail="Для Seedream 4.5 Edit укажите промпт")
            # Используем тот же callback endpoint что и в телеграм боте
            seedream_callback_url = f"{callback_base}/seedream-edit-callback"
            task_result = await create_seedream_edit_task(
                image_urls=body.image_urls,
                prompt=body.prompt,
                aspect_ratio="1:1",  # По умолчанию, можно добавить параметры в запрос
                quality="basic",  # По умолчанию, можно добавить параметры в запрос
                callback_url=seedream_callback_url,
            )
        elif editing_model["id"] == "topaz_upscale":
            if not body.image_urls:
                raise HTTPException(status_code=400, detail="Нужно одно изображение")
            upscale_callback_url = f"{callback_base}/topaz-upscale-callback"
            task_result = await create_topaz_upscale_task(
                image_url=body.image_urls[0],
                upscale_factor="2",
                callback_url=upscale_callback_url,
            )
    # Обработка шаблонов - используем nano_banana_pro для всех шаблонов
    elif template:
        if body.template_id == "upscale":
            if not body.image_urls:
                raise HTTPException(status_code=400, detail="Нужно одно изображение")
            upscale_callback_url = f"{callback_base}/topaz-upscale-callback"
            task_result = await create_topaz_upscale_task(
                image_url=body.image_urls[0],
                upscale_factor="2",
                callback_url=upscale_callback_url,
            )
        elif body.template_id == "generate_art":
            # Генерация по описанию - только промпт
            task_result = await create_nano_banana_pro_task(
                prompt=(body.prompt or "").strip() or "красивое изображение",
                aspect_ratio="1:1",
                callback_url=nano_callback_url,
            )
        else:
            # Все остальные шаблоны используют nano_banana_pro с несколькими фото
            prompt = body.prompt or _default_prompt_for_template(body.template_id)
            # Используем nano_banana_pro для всех шаблонов редактирования
            # create_nano_banana_pro_task принимает image_input (список URL), а не image_urls
            task_result = await create_nano_banana_pro_task(
                prompt=prompt,
                image_input=body.image_urls if body.image_urls else None,
                aspect_ratio="1:1",
                callback_url=nano_callback_url,
            )

    # Обработка ошибок от Neuro Hub
    if not task_result:
        logger.error(f"[generate_image] Neuro Hub вернул пустой ответ")
        raise HTTPException(status_code=502, detail="API генерации не ответил")
    
    if task_result.get("error"):
        error_msg = task_result.get("error") or task_result.get("message") or "Неизвестная ошибка API"
        logger.error(f"[generate_image] Ошибка Neuro Hub: {error_msg}")
        raise HTTPException(status_code=502, detail=f"Ошибка API генерации: {error_msg}")
    
    # Проверяем код ответа, если есть
    if task_result.get("code") and task_result.get("code") != 200:
        error_msg = task_result.get("msg") or task_result.get("message") or "Ошибка API"
        logger.error(f"[generate_image] Neuro Hub вернул код ошибки: {task_result.get('code')}, сообщение: {error_msg}")
        raise HTTPException(status_code=502, detail=f"Ошибка API генерации: {error_msg}")

    task_id = task_result.get("taskId") or task_result.get("data", {}).get("taskId") or task_result.get("id") or task_result.get("task_id")
    if not task_id:
        logger.error(f"[generate_image] Neuro Hub не вернул taskId. Ответ: {task_result}")
        raise HTTPException(status_code=502, detail="API не вернул taskId")

    # Нормализуем task_id (убираем пробелы, приводим к строке)
    task_id = str(task_id).strip()
    logger.info(f"[generate_image] Получен task_id от KIE API: {task_id} (тип: {type(task_id)})")
    logger.info(f"[generate_image] Полный ответ от KIE API: {task_result}")

    # Списываем баланс перед сохранением задачи
    if not deduct_balance_by_app_id(app_id, cost):
        logger.error(f"[generate_image] Не удалось списать баланс: app_id={app_id}, cost={cost}")
        raise HTTPException(status_code=402, detail="Не удалось списать средства")
    
    # Сохраняем задачу с правильным идентификатором пользователя
    device_id = user.get("device_id")
    vk_user_id = user.get("vk_user_id")
    logger.info(f"[generate_image] Сохраняем задачу: task_id={task_id}, app_id={app_id}, cost={cost}, callback_url={callback_url}")
    success = save_app_task(str(task_id), app_id, "image", body.template_id, cost, user_id=user_id, device_id=device_id, vk_user_id=vk_user_id)
    
    if success:
        # Проверяем, что задача сохранилась
        from database import get_app_task
        saved_task = get_app_task(task_id)
        if saved_task:
            logger.info(f"[generate_image] Задача успешно сохранена: task_id={task_id}, status={saved_task.get('status')}")
        else:
            logger.error(f"[generate_image] ОШИБКА: Задача не найдена после сохранения! task_id={task_id}")
    else:
        logger.error(f"[generate_image] ОШИБКА: Не удалось сохранить задачу! task_id={task_id}")
    
    # Начисляем комиссию партнеру при генерации (если есть партнер)
    # Комиссия начисляется только для VK пользователей
    if user.get("vk_user_id"):
        try:
            update_partner_commission_by_vk(user["vk_user_id"], float(cost))
        except Exception as e:
            import logging
            logger = logging.getLogger(__name__)
            logger.warning(f"[generate_image] Ошибка начисления комиссии партнеру: {e}")

    return {"task_id": task_id, "cost": cost, "status": "pending"}


def _default_prompt_for_template(template_id: str) -> str:
    prompts = {
        "face_swap": "естественная замена лица, сохранить освещение и ракурс",
        "try_on": "примерка одежды на человека, реалистично",
        "combine": "объединить объекты в одну сцену естественно",
        "background_replace": "заменить фон, сохранить объект на переднем плане",
        "style_transfer": "применить стиль первого изображения к содержанию второго",
        "remove_background": "удалить фон, оставить только главный объект",
        "object_remove": "удалить указанный объект, заполнить фон естественно",
    }
    return prompts.get(template_id, "качественная обработка изображения")


# --- Генерация видео ---
@app.post("/api/v1/generate/video")
async def generate_video(
    body: GenerateVideoRequest,
    user: dict = Depends(get_current_user),
):
    """Запуск генерации видео (текст или картинка -> видео)."""
    import logging
    logger = logging.getLogger(__name__)
    
    try:
        app_id = user["_app_id"]
        
        # Определяем модель по умолчанию или из запроса
        model_id = body.model or ("sora2_image" if body.image_url else "veo3_fast")
        
        # Находим модель в списке
        selected_model = next((m for m in VIDEO_GENERATION_MODELS if m["id"] == model_id), None)
        if not selected_model:
            raise HTTPException(status_code=400, detail=f"Неизвестная модель: {model_id}")
        
        # Проверяем требования модели
        if selected_model["supports_image"] == False and body.image_url:
            raise HTTPException(status_code=400, detail=f"Модель {selected_model['name']} не поддерживает генерацию из изображения")
        if selected_model["supports_text"] == False and not body.prompt:
            raise HTTPException(status_code=400, detail=f"Модель {selected_model['name']} требует текстовый промпт")
        
        # Рассчитываем стоимость динамически, как в Telegram боте
        from kie_api import get_generation_cost, get_sora2_pro_cost, get_seedance_price_options, get_grok_imagine_cost
        
        cost = None
        if model_id == "veo3_fast":
            cost = await get_generation_cost("Veo 3", "fast")
        elif model_id == "veo3_quality":
            cost = await get_generation_cost("Veo 3", "quality")
        elif model_id == "sora2_text":
            cost = await get_generation_cost("SORA 2", "text")
        elif model_id == "sora2_image":
            cost = await get_generation_cost("SORA 2", "image")
        elif model_id == "sora2_pro":
            # Для SORA 2 Pro используем минимальную цену (можно расширить для выбора параметров)
            cost = await get_sora2_pro_cost("10", "standard")
        elif model_id == "seedance":
            seedance_prices = get_seedance_price_options()
            if seedance_prices:
                cost = min(seedance_prices.values())  # Минимальная цена
            else:
                cost = 25  # Fallback
        elif model_id == "runway":
            cost = await get_generation_cost("Runway")
        elif model_id == "grok_imagine":
            cost = get_grok_imagine_cost()
        else:
            # Fallback на старую логику
            if body.image_url:
                cost = await get_generation_cost("SORA 2", "image")
            else:
                cost = await get_generation_cost("Veo 3", "fast")
        
        balance = get_balance_by_app_id(app_id)
        
        logger.info(f"[generate_video] user_id={user.get('id')}, app_id={app_id}, model={model_id}, balance={balance}, cost={cost}")
        
        if balance < cost:
            raise HTTPException(status_code=402, detail=f"Недостаточно средств. Нужно {cost} токенов. Ваш баланс: {balance} токенов.")

        from kie_api import create_video_task, create_sora2_video_task, create_seedance_task, create_runway_video_task, create_grok_imagine_video_task

        # Используем callback URLs на том же сервере что и API приложения
        # Callback handlers в app_api.py уже добавлены и импортируют функции из kie_api.py
        # Базовый URL API приложения (где находятся callback endpoints)
        callback_base = os.getenv("APP_API_BASE_URL", "http://techscore.ru:8011").rstrip("/")
        
        # Определяем специфичный callback URL для каждой модели
        if model_id in ["veo3_fast", "veo3_quality"]:
            callback_url = f"{callback_base}/veo3-callback"
        elif model_id in ["sora2_text", "sora2_image", "sora2_pro"]:
            callback_url = f"{callback_base}/sora2-callback"
        elif model_id == "grok_imagine":
            callback_url = f"{callback_base}/grok-imagine-callback"
        elif model_id == "seedance":
            callback_url = f"{callback_base}/seedance-callback"
        elif model_id == "runway":
            callback_url = f"{callback_base}/runway-callback"
        else:
            # Fallback на единый callback
            callback_url = f"{callback_base}/api/v1/callback/generation"
        
        logger.info(f"[generate_video] Используется callback URL: {callback_url} для модели {model_id}")

        task_result = None
        
        if model_id == "veo3_fast":
            task_result = await create_video_task(
                model="veo-3-fast",
                prompt=body.prompt,
                aspect_ratio="16:9",
                image_urls=[body.image_url] if body.image_url else None,
                callback_url=callback_url,
            )
        elif model_id == "veo3_quality":
            task_result = await create_video_task(
                model="veo-3-quality",
                prompt=body.prompt,
                aspect_ratio="16:9",
                image_urls=[body.image_url] if body.image_url else None,
                callback_url=callback_url,
            )
        elif model_id == "sora2_text":
            task_result = await create_sora2_video_task(
                prompt=body.prompt,
                model="sora-2-text-to-video",
                callback_url=callback_url,
            )
        elif model_id == "sora2_image":
            if not body.image_url:
                raise HTTPException(status_code=400, detail="Для SORA 2 Image-to-Video требуется изображение")
            task_result = await create_sora2_video_task(
                prompt=body.prompt,
                model="sora-2-image-to-video",
                image_urls=[body.image_url],
                callback_url=callback_url,
            )
        elif model_id == "sora2_pro":
            # SORA 2 Pro - используем стандартные параметры (можно расширить)
            if body.image_url:
                task_result = await create_sora2_video_task(
                    prompt=body.prompt,
                    model="sora-2-pro-image-to-video",
                    image_urls=[body.image_url],
                    callback_url=callback_url,
                )
            else:
                task_result = await create_sora2_video_task(
                    prompt=body.prompt,
                    model="sora-2-pro-text-to-video",
                    callback_url=callback_url,
                )
        elif model_id == "seedance":
            if not body.image_url:
                raise HTTPException(status_code=400, detail="Для Seedance требуется изображение")
            task_result = await create_seedance_task(
                image_url=body.image_url,
                prompt=body.prompt or "анимировать изображение",
                resolution="720p",  # По умолчанию
                duration="5",  # По умолчанию
                callback_url=callback_url,
            )
        elif model_id == "runway":
            from kie_api import create_runway_video_task
            task_result = await create_runway_video_task(
                prompt=body.prompt,
                duration="5",  # По умолчанию 5 секунд
                quality="standard",  # По умолчанию стандартное качество
                aspect_ratio="16:9",
                image_url=body.image_url,
                callback_url=callback_url,
            )
        elif model_id == "grok_imagine":
            if not body.image_url:
                raise HTTPException(status_code=400, detail="Для Grok Imagine требуется изображение")
            try:
                task_result = await create_grok_imagine_video_task(
                    image_urls=[body.image_url],
                    prompt=body.prompt or "",
                    callback_url=callback_url,
                    model="grok-imagine/image-to-video"
                )
            except Exception as e:
                logger.error(f"[generate_video] Ошибка при создании задачи Grok Imagine: {e}")
                import traceback
                logger.error(f"[generate_video] Traceback: {traceback.format_exc()}")
                raise HTTPException(status_code=500, detail=f"Ошибка создания задачи: {str(e)}")
        else:
            # Fallback на старую логику
            if body.image_url:
                task_result = await create_sora2_video_task(
                    prompt=body.prompt,
                    model="sora-2-image-to-video",
                    image_urls=[body.image_url],
                    callback_url=callback_url,
                )
            else:
                task_result = await create_video_task(
                    model="veo-3-fast",
                    prompt=body.prompt,
                    aspect_ratio="16:9",
                    image_urls=None,
                    callback_url=callback_url,
                )

        # Обработка ошибок от Neuro Hub
        if not task_result:
            logger.error(f"[generate_video] Neuro Hub вернул пустой ответ")
            raise HTTPException(status_code=502, detail="API генерации не ответил")
        
        if task_result.get("error"):
            error_msg = task_result.get("error") or task_result.get("message") or "Неизвестная ошибка API"
            logger.error(f"[generate_video] Ошибка Neuro Hub: {error_msg}")
            raise HTTPException(status_code=502, detail=f"Ошибка API генерации: {error_msg}")
        
        # Проверяем код ответа, если есть
        if task_result.get("code") and task_result.get("code") != 200:
            error_msg = task_result.get("msg") or task_result.get("message") or "Ошибка API"
            logger.error(f"[generate_video] Neuro Hub вернул код ошибки: {task_result.get('code')}, сообщение: {error_msg}")
            raise HTTPException(status_code=502, detail=f"Ошибка API генерации: {error_msg}")

        task_id = task_result.get("taskId") or task_result.get("data", {}).get("taskId") or task_result.get("id") or task_result.get("task_id")
        if not task_id:
            logger.error(f"[generate_video] Neuro Hub не вернул taskId. Ответ: {task_result}")
            raise HTTPException(status_code=502, detail="API не вернул taskId")

        # Списываем баланс перед сохранением задачи
        if not deduct_balance_by_app_id(app_id, cost):
            logger.error(f"[generate_video] Не удалось списать баланс: app_id={app_id}, cost={cost}")
            raise HTTPException(status_code=402, detail="Не удалось списать средства")
        
        # Сохраняем задачу с правильным идентификатором пользователя
        user_id = user.get("id")
        device_id = user.get("device_id")
        vk_user_id = user.get("vk_user_id")
        save_app_task(str(task_id), app_id, "video", None, cost, user_id=user_id, device_id=device_id, vk_user_id=vk_user_id)
        
        # Начисляем комиссию партнеру при генерации (если есть партнер)
        # Комиссия начисляется только для VK пользователей
        if user.get("vk_user_id"):
            try:
                update_partner_commission_by_vk(user["vk_user_id"], float(cost))
            except Exception as e:
                logger.warning(f"[generate_video] Ошибка начисления комиссии партнеру: {e}")

        return {"task_id": task_id, "cost": cost, "status": "pending"}
    except HTTPException:
        # Пробрасываем HTTP исключения как есть
        raise
    except Exception as e:
        # Логируем все остальные ошибки
        logger.error(f"[generate_video] Неожиданная ошибка: {e}")
        import traceback
        logger.error(f"[generate_video] Traceback: {traceback.format_exc()}")
        raise HTTPException(status_code=500, detail=f"Внутренняя ошибка сервера: {str(e)}")


# --- Статус задачи ---
@app.get("/api/v1/tasks/{task_id}")
async def get_task_status(task_id: str, user: dict = Depends(get_current_user)):
    """Получение статуса задачи. Поддерживает JWT (user_id), VK ID и device_id.
    Если задача в статусе pending, дополнительно опрашивает KIE API для обновления статуса."""
    import logging
    logger = logging.getLogger(__name__)
    
    app_id = user["_app_id"]
    user_id = user.get("id")
    vk_user_id = user.get("vk_user_id")
    device_id = user.get("device_id")
    
    logger.info(f"[get_task_status] Запрос статуса: task_id={task_id}, app_id={app_id}, user_id={user_id}, vk_user_id={vk_user_id}, device_id={device_id}")
    
    row = get_app_task(task_id)
    if not row:
        logger.warning(f"[get_task_status] Задача не найдена: task_id={task_id}")
        return {"task_id": task_id, "status": "unknown", "result_url": None, "error_message": None, "cost": 0.0}
    
    # Проверка доступа: сравниваем с vk_user_id, device_id или user_id из задачи
    task_vk_user_id = row.get("vk_user_id")
    task_device_id = row.get("device_id")
    task_user_id = row.get("user_id")
    
    has_access = False
    if vk_user_id and task_vk_user_id and str(task_vk_user_id) == str(vk_user_id):
        has_access = True
    elif device_id and task_device_id and str(task_device_id) == str(device_id):
        has_access = True
    elif user_id and task_user_id and int(task_user_id) == int(user_id):
        has_access = True
    elif app_id.isdigit() and task_user_id and int(app_id) == int(task_user_id):
        has_access = True
    elif str(app_id) == str(task_vk_user_id) or str(app_id) == str(task_device_id):
        has_access = True
    
    if not has_access:
        logger.warning(f"[get_task_status] Нет доступа: task_id={task_id}, app_id={app_id}, task_vk={task_vk_user_id}, task_device={task_device_id}, task_user={task_user_id}")
        raise HTTPException(status_code=403, detail="Нет доступа к задаче")
    
    status = row.get("status", "unknown")
    result_url = row.get("result_url")
    error_message = row.get("error_message")
    cost = float(row.get("cost") or 0)
    task_type = row.get("task_type", "image")
    
    # Если задача в статусе pending, опрашиваем KIE API для обновления статуса
    if status == "pending":
        logger.info(f"[get_task_status] Задача в статусе pending, опрашиваем KIE API: task_id={task_id}")
        
        try:
            # Используем единый endpoint /api/v1/jobs/recordInfo (как в документации KIE API)
            import httpx
            from config import KIE_API_KEY
            
            async with httpx.AsyncClient(timeout=30) as client:
                response = await client.get(
                    f"https://api.kie.ai/api/v1/jobs/recordInfo?taskId={task_id}",
                    headers={"Authorization": f"Bearer {KIE_API_KEY}"}
                )
                
                logger.info(f"[get_task_status] KIE API ответ: status_code={response.status_code}, task_id={task_id}")
                
                if response.status_code == 200:
                    kie_status = response.json()
                    logger.info(f"[get_task_status] Получен ответ от KIE API: code={kie_status.get('code')}, message={kie_status.get('message')}")
                else:
                    logger.warning(f"[get_task_status] KIE API вернул код {response.status_code} для task_id={task_id}: {response.text[:200]}")
                    kie_status = None
            
            if kie_status:
                # Обрабатываем ответ от KIE API согласно документации: /api/v1/jobs/recordInfo
                code = kie_status.get("code")
                message = kie_status.get("message", "")
                data = kie_status.get("data", {})
                
                logger.info(f"[get_task_status] KIE API ответ: code={code}, message={message}, state={data.get('state')}")
                
                if code == 200:
                    # Проверяем state согласно документации: waiting, queuing, generating, success, fail
                    state = data.get("state", "").lower()
                    
                    if state == "success":
                        # Задача завершена успешно - извлекаем URL из resultJson
                        result_json_str = data.get("resultJson", "{}")
                        try:
                            if isinstance(result_json_str, str):
                                result_json = json.loads(result_json_str)
                            else:
                                result_json = result_json_str
                            
                            # Извлекаем URL из resultUrls (согласно документации)
                            result_urls = result_json.get("resultUrls", [])
                            
                            if result_urls:
                                result_url_str = result_urls[0] if isinstance(result_urls[0], str) else str(result_urls[0])
                                success = update_app_task_result(task_id, "completed", result_url=result_url_str)
                                logger.info(f"[get_task_status] Задача обновлена: task_id={task_id}, status=completed, result_url={result_url_str}")
                                
                                # Перечитываем данные из БД
                                if success:
                                    row = get_app_task(task_id)
                                    if row:
                                        status = row.get("status", "unknown")
                                        result_url = row.get("result_url")
                                        error_message = row.get("error_message")
                            else:
                                logger.warning(f"[get_task_status] state=success, но resultUrls пустой: {result_json}")
                        except json.JSONDecodeError as e:
                            logger.error(f"[get_task_status] Ошибка парсинга resultJson: {e}, resultJson={result_json_str}")
                    
                    elif state == "fail":
                        # Задача завершилась с ошибкой
                        fail_msg = data.get("failMsg", "")
                        error_msg = fail_msg or message or "Ошибка генерации"
                        success = update_app_task_result(task_id, "failed", error_message=error_msg)
                        logger.info(f"[get_task_status] Задача обновлена: task_id={task_id}, status=failed, error={error_msg}")
                        
                        # Перечитываем данные из БД
                        if success:
                            row = get_app_task(task_id)
                            if row:
                                status = row.get("status", "unknown")
                                result_url = row.get("result_url")
                                error_message = row.get("error_message")
                    
                    # Для состояний waiting, queuing, generating - оставляем pending
                    elif state in ["waiting", "queuing", "generating"]:
                        logger.debug(f"[get_task_status] Задача еще выполняется: state={state}")
                    else:
                        logger.debug(f"[get_task_status] Неизвестное состояние: state={state}")
                
                elif code != 200:
                    # Ошибка от KIE API
                    error_msg = message or f"Ошибка KIE API (код {code})"
                    success = update_app_task_result(task_id, "failed", error_message=error_msg)
                    logger.warning(f"[get_task_status] KIE API вернул ошибку: task_id={task_id}, code={code}, error={error_msg}")
                    
                    if success:
                        row = get_app_task(task_id)
                        if row:
                            status = row.get("status", "unknown")
                            error_message = row.get("error_message")
            else:
                logger.debug(f"[get_task_status] KIE API не вернул статус для task_id={task_id}")
        except Exception as e:
            logger.error(f"[get_task_status] Ошибка при опросе KIE API: {e}", exc_info=True)
            # Продолжаем с данными из БД
    
    # Если задача не в pending, но все равно проверяем, не обновилась ли она через callback
    # (на случай если callback пришел между запросами)
    if status != "pending":
        # Перечитываем данные из БД на всякий случай
        row = get_app_task(task_id)
        if row:
            db_status = row.get("status", "unknown")
            db_result_url = row.get("result_url")
            db_error_message = row.get("error_message")
            
            # Если статус в БД изменился, используем новые данные
            if db_status != status or db_result_url != result_url or db_error_message != error_message:
                logger.info(f"[get_task_status] Обнаружено изменение статуса в БД: было status={status}, стало status={db_status}")
                status = db_status
                result_url = db_result_url
                error_message = db_error_message
    
    # Проверяем тип result_url и конвертируем в строку если нужно
    if result_url is not None and not isinstance(result_url, str):
        result_url = str(result_url)
        logger.warning(f"[get_task_status] result_url был не строкой, конвертирован: {result_url}")
    
    # Убеждаемся, что result_url не пустая строка
    if result_url == "" or (result_url and result_url.strip() == ""):
        result_url = None
        logger.warning(f"[get_task_status] result_url была пустой строкой, установлен в None")
    
    logger.info(f"[get_task_status] Финальный статус задачи: task_id={task_id}, status={status}, result_url={result_url}, has_result={result_url is not None}")
    logger.info(f"[get_task_status] Полная строка из БД перед возвратом: {row if 'row' in locals() else get_app_task(task_id)}")
    
    return {
        "task_id": task_id,
        "status": status,
        "result_url": result_url,
        "error_message": error_message,
        "cost": cost,
    }


# --- Callback от Neuro Hub (webhook по завершении задачи) ---
@app.post("/api/v1/callback/generation")
async def callback_generation(request: Request):
    """Вызывается Neuro Hub при завершении задачи. Обновляем app_generation_tasks."""
    import logging
    logger = logging.getLogger(__name__)
    
    try:
        data = await request.json()
        logger.info(f"[callback_generation] Получен callback: {data}")
    except Exception as e:
        logger.error(f"[callback_generation] Ошибка парсинга JSON: {e}")
        data = {}
    
    try:
        # Различные форматы task_id от Neuro Hub
        task_id = (
            data.get("taskId") 
            or data.get("task_id")
            or data.get("id")
            or data.get("data", {}).get("taskId")
            or data.get("data", {}).get("task_id")
            or data.get("data", {}).get("id")
        )
        
        if not task_id:
            logger.warning(f"[callback_generation] Нет task_id в callback: {data}")
            return {"ok": False, "error": "No task_id"}
        
        # Определяем статус по разным форматам ответов Neuro Hub
        code = data.get("code")
        success_flag = data.get("successFlag")
        success = data.get("success")
        state = data.get("data", {}).get("state") or data.get("state")
        
        status = "pending"
        if code == 200 or success_flag == 1 or success is True or state == "success":
            status = "completed"
        elif code and code != 200:
            status = "failed"
        elif success_flag == 0 or success is False or state == "failed":
            status = "failed"
        
        # Извлекаем URL результата из разных форматов
        result_url = None
        if status == "completed":
            # Различные форматы URL от Neuro Hub
            # Для Veo 3: info.resultUrls в data.info.resultUrls
            # Для SORA 2: resultJson в data.resultJson или data.data.resultJson
            # Для Grok: resultJson в data.data.resultJson
            # Для Runway: result_video_url или video_url
            # Для Seedance: resultUrls в data.resultUrls
            
            task_data = data.get("data", {}) or {}
            info_data = task_data.get("info", {}) or data.get("info", {}) or {}
            
            logger.info(f"[callback_generation] Извлечение URL: task_data={task_data}, info_data={info_data}")
            
            urls = (
                # Veo 3 формат: info.resultUrls
                (info_data.get("resultUrls") if isinstance(info_data.get("resultUrls"), list) else None)
                # SORA 2, Grok формат: resultJson (может быть строка или объект)
                or task_data.get("resultJson")
                or data.get("resultJson")
                # Общие форматы
                or (task_data.get("output", {}) or {}).get("image_url")
                or (task_data.get("output", {}) or {}).get("video_url")
                or task_data.get("image_url")
                or task_data.get("video_url")
                or (task_data.get("resultUrls") if isinstance(task_data.get("resultUrls"), list) else None)
                or data.get("imageUrl")
                or data.get("videoUrl")
                or data.get("result_video_url")
                or data.get("result_image_url")
                or (data.get("resultUrls") if isinstance(data.get("resultUrls"), list) else None)
            )
            
            logger.info(f"[callback_generation] Найденные URLs: {urls}")
            
            # Обработка resultUrls (может быть список или JSON строка)
            if isinstance(urls, str):
                try:
                    import json
                    urls_json = json.loads(urls)
                    if isinstance(urls_json, list) and urls_json:
                        result_url = urls_json[0]
                    elif isinstance(urls_json, dict):
                        # Извлекаем из resultJson объекта
                        result_urls_list = urls_json.get("resultUrls", [])
                        if result_urls_list and isinstance(result_urls_list, list):
                            result_url = result_urls_list[0]
                        else:
                            result_url = urls_json.get("image_url") or urls_json.get("video_url") or urls_json.get("videoUrl")
                    else:
                        result_url = urls
                    logger.info(f"[callback_generation] Распарсен JSON URL: {result_url}")
                except Exception as e:
                    logger.warning(f"[callback_generation] Ошибка парсинга JSON URL: {e}, используем как строку")
                    result_url = urls
            elif isinstance(urls, list) and urls:
                result_url = urls[0] if isinstance(urls[0], str) else str(urls[0])
                logger.info(f"[callback_generation] Извлечен URL из списка: {result_url}")
            elif urls:
                result_url = str(urls)
                logger.info(f"[callback_generation] Использован URL как строка: {result_url}")
            
            if not result_url:
                logger.warning(f"[callback_generation] Не удалось извлечь URL из данных: {data}")
        
        # Извлекаем сообщение об ошибке
        error_message = None
        if status == "failed":
            error_message = (
                data.get("msg")
                or data.get("message")
                or data.get("error")
                or data.get("data", {}).get("msg")
                or data.get("data", {}).get("message")
                or "Unknown error"
            )
        
        import json
        logger.info(f"[callback_generation] Обновление задачи: task_id={task_id}, status={status}, result_url={result_url}, error={error_message}")
        logger.info(f"[callback_generation] Тип result_url: {type(result_url)}, значение: {repr(result_url)}")
        logger.info(f"[callback_generation] Полные данные callback: {json.dumps(data, indent=2, ensure_ascii=False)}")
        
        if task_id:
            # Проверяем, существует ли задача в базе данных
            existing_task = get_app_task(str(task_id))
            if existing_task:
                # Убеждаемся, что result_url - строка
                result_url_str = str(result_url) if result_url is not None else None
                if result_url_str == "None":
                    result_url_str = None
                
                logger.info(f"[callback_generation] Обновление с result_url_str: {result_url_str}")
                success = update_app_task_result(str(task_id), status, result_url=result_url_str, error_message=error_message)
                if success:
                    logger.info(f"[callback_generation] Задача успешно обновлена: task_id={task_id}, status={status}, result_url={result_url_str}")
                    
                    # Проверяем, что данные действительно сохранились
                    verify_task = get_app_task(str(task_id))
                    if verify_task:
                        logger.info(f"[callback_generation] Проверка после обновления: status={verify_task.get('status')}, result_url={verify_task.get('result_url')}")
                else:
                    logger.error(f"[callback_generation] Не удалось обновить задачу в БД: task_id={task_id}")
            else:
                logger.warning(f"[callback_generation] Задача не найдена в БД: task_id={task_id}")
                # Все равно возвращаем успех, чтобы KIE API не повторял callback
        else:
            logger.warning(f"[callback_generation] Не удалось извлечь task_id из данных")
            
    except Exception as e:
        logger.error(f"[callback_generation] Ошибка обработки callback: {e}", exc_info=True)
        return {"ok": False, "error": str(e)}
    
    return {"ok": True}


# --- История генераций ---
@app.get("/api/v1/history")
def get_generation_history(user: dict = Depends(get_current_user), limit: int = 100):
    """Получение истории генераций пользователя. Возвращает только завершенные задачи с результатом."""
    import logging
    logger = logging.getLogger(__name__)
    
    app_id = user["_app_id"]
    user_id = user.get("id")
    vk_user_id = user.get("vk_user_id")
    device_id = user.get("device_id")
    
    logger.info(f"[get_generation_history] Запрос истории: app_id={app_id}, user_id={user_id}, vk_user_id={vk_user_id}, device_id={device_id}")
    
    try:
        history = get_user_generation_history(
            user_id=user_id,
            vk_user_id=vk_user_id,
            device_id=device_id,
            limit=limit
        )
        
        # Преобразуем результат в формат для API
        items = []
        for row in history:
            items.append({
                "task_id": row.get("task_id"),
                "task_type": row.get("task_type"),  # "image" или "video"
                "template_id": row.get("template_id"),
                "status": row.get("status"),
                "result_url": row.get("result_url"),
                "cost": float(row.get("cost", 0)),
                "created_at": row.get("created_at").isoformat() if row.get("created_at") else None,
                "completed_at": row.get("completed_at").isoformat() if row.get("completed_at") else None,
            })
        
        logger.info(f"[get_generation_history] Возвращено {len(items)} элементов истории")
        return {"items": items, "total": len(items)}
    except Exception as e:
        logger.error(f"[get_generation_history] Ошибка получения истории: {e}", exc_info=True)
        raise HTTPException(status_code=500, detail=f"Ошибка получения истории: {str(e)}")


# --- Загрузка изображений (возврат URL) ---
# В продакшене изображения загружаются на ваш CDN или S3; здесь возвращаем заглушку или сохраняем во временное хранилище
UPLOAD_DIR = os.path.join(os.path.dirname(__file__), "app_uploads")
os.makedirs(UPLOAD_DIR, exist_ok=True)


@app.post("/api/v1/upload/image")
async def upload_image(
    file: UploadFile = File(...),
    user: dict = Depends(get_current_user),
):
    """Загрузить изображение в WordPress и получить URL."""
    import httpx
    
    app_id = user["_app_id"]
    
    # Определяем тип файла
    filename = file.filename or 'image.jpg'
    content_type = file.content_type
    
    # Если content_type не указан, пытаемся определить по расширению
    if not content_type or not content_type.startswith("image/"):
        ext = filename.split('.')[-1].lower() if '.' in filename else 'jpg'
        mime_types = {
            'jpg': 'image/jpeg',
            'jpeg': 'image/jpeg',
            'png': 'image/png',
            'gif': 'image/gif',
            'webp': 'image/webp',
            'bmp': 'image/bmp'
        }
        content_type = mime_types.get(ext, 'image/jpeg')
        logger.info(f"[upload_image] Content-Type не указан, определен по расширению: {content_type}")
    
    if not content_type.startswith("image/"):
        raise HTTPException(status_code=400, detail="Только изображения")
    
    # WordPress API endpoint
    wordpress_url = os.getenv("WORDPRESS_API_URL", "https://genius-bot.ru/wp-json/neurohub/v1/upload-image")
    wordpress_api_key = os.getenv("WORDPRESS_API_KEY", "dfg34353gadb$%@ghdf")
    
    logger.info(f"[upload_image] Загрузка в WordPress: app_id={app_id}, url={wordpress_url}, filename={filename}, content_type={content_type}")
    
    # Читаем файл
    file_content = await file.read()
    file_size = len(file_content)
    
    # Проверка размера (максимум 10MB)
    if file_size > 10 * 1024 * 1024:
        raise HTTPException(status_code=400, detail="Файл слишком большой. Максимум 10MB.")
    
    # Подготавливаем данные для загрузки
    # httpx требует кортеж (filename, content, content_type) для files
    # Важно: filename должен быть без пути, только имя файла
    safe_filename = os.path.basename(filename) if filename else 'image.jpg'
    files = {
        'file': (safe_filename, file_content, content_type)
    }
    headers = {
        'X-App-ID': str(app_id)
    }
    if wordpress_api_key:
        headers['X-API-Key'] = wordpress_api_key
    
    logger.info(f"[upload_image] Отправка в WordPress: url={wordpress_url}, filename={file.filename}, size={file_size}, content_type={file.content_type}")
    
    try:
        async with httpx.AsyncClient(timeout=60.0, follow_redirects=True) as client:
            response = await client.post(
                wordpress_url, 
                files=files, 
                headers=headers
            )
            
            logger.info(f"[upload_image] Ответ WordPress: status={response.status_code}, headers={dict(response.headers)}")
            
            if response.status_code != 200:
                error_text = response.text[:500]  # Первые 500 символов ошибки
                logger.error(f"[upload_image] WordPress вернул ошибку {response.status_code}: {error_text}")
                raise HTTPException(
                    status_code=500,
                    detail=f"WordPress вернул ошибку {response.status_code}: {error_text}"
                )
            
            try:
                result = response.json()
                logger.info(f"[upload_image] Ответ WordPress (JSON): {result}")
            except Exception as json_error:
                logger.error(f"[upload_image] Ошибка парсинга JSON ответа: {json_error}, текст ответа: {response.text[:500]}")
                raise HTTPException(
                    status_code=500,
                    detail=f"WordPress вернул невалидный JSON: {response.text[:200]}"
                )
            
            if 'url' not in result:
                logger.error(f"[upload_image] WordPress не вернул URL в ответе: {result}")
                raise HTTPException(
                    status_code=500,
                    detail="WordPress не вернул URL файла в ответе"
                )
            
            logger.info(f"[upload_image] Файл успешно загружен в WordPress: {result.get('url')}")
            return {"url": result["url"]}
            
    except httpx.HTTPStatusError as e:
        error_text = e.response.text[:1000] if hasattr(e.response, 'text') and e.response.text else str(e)
        logger.error(f"[upload_image] Ошибка загрузки в WordPress: {e.response.status_code} - {error_text}")
        logger.error(f"[upload_image] Заголовки ответа: {dict(e.response.headers) if hasattr(e.response, 'headers') else 'N/A'}")
        raise HTTPException(
            status_code=500, 
            detail=f"Ошибка загрузки изображения в WordPress: {e.response.status_code}. {error_text[:200]}"
        )
    except httpx.TimeoutException:
        logger.error(f"[upload_image] Таймаут при загрузке в WordPress")
        raise HTTPException(status_code=500, detail="Таймаут при загрузке изображения")
    except Exception as e:
        logger.error(f"[upload_image] Неожиданная ошибка: {e}", exc_info=True)
        raise HTTPException(status_code=500, detail=f"Ошибка загрузки изображения: {str(e)}")


# Раздача загруженных файлов (в продакшене лучше через nginx/CDN)
from fastapi.responses import FileResponse


@app.get("/api/v1/files/{filename}")
def get_uploaded_file(
    filename: str,
    authorization: Optional[str] = Header(None),
    x_vk_user_id: Optional[str] = Header(None, alias="X-VK-User-Id"),
    x_device_id: Optional[str] = Header(None, alias="X-Device-Id"),
):
    """
    Раздача загруженных файлов.
    Публичный endpoint для доступа KIE API, но проверяет авторизацию если она есть.
    """
    import logging
    logger = logging.getLogger(__name__)
    
    path = os.path.join(UPLOAD_DIR, filename)
    logger.info(f"[get_uploaded_file] Запрос файла: {filename}, путь: {path}")
    
    if not os.path.isfile(path):
        logger.error(f"[get_uploaded_file] Файл не найден: {path}")
        raise HTTPException(status_code=404, detail="Файл не найден")
    
    # Опциональная проверка авторизации (для логирования)
    # Но не блокируем доступ, так как KIE API не может авторизоваться
    try:
        user = get_current_user(x_vk_user_id=x_vk_user_id, x_device_id=x_device_id, authorization=authorization)
        logger.info(f"[get_uploaded_file] Авторизованный запрос от пользователя: {user.get('_app_id')}")
        
        # Проверяем, что файл принадлежит пользователю (опционально)
        file_owner_id = filename.split("_")[0] if "_" in filename else None
        if file_owner_id and file_owner_id != user.get("_app_id"):
            logger.warning(f"[get_uploaded_file] Попытка доступа к чужому файлу: пользователь {user.get('_app_id')}, файл {filename}")
            # Не блокируем, так как KIE API может запрашивать файлы от имени пользователя
    except HTTPException:
        # Если авторизация не прошла, все равно отдаем файл (для KIE API)
        logger.info(f"[get_uploaded_file] Неавторизованный запрос (возможно, от KIE API)")
    
    logger.info(f"[get_uploaded_file] Отдаем файл: {path}, размер: {os.path.getsize(path)} байт")
    return FileResponse(path, media_type="image/jpeg")


# --- Callback handlers для разных нейросетей ---
# Эти маршруты должны совпадать с callback_url, используемыми в generate_image и generate_video

@app.post("/nano-banana-callback")
async def nano_banana_callback_app(request: Request):
    """Callback для Nano Banana (используется также для Topaz Upscale)"""
    import logging
    logger = logging.getLogger(__name__)
    try:
        data = await request.json()
        logger.info(f"[nano-banana-callback-app] Получен callback: {data}")
        from kie_api import nano_banana_callback
        result = await nano_banana_callback(request)
        logger.info(f"[nano-banana-callback-app] Callback обработан, результат: {result}")
        return result
    except Exception as e:
        logger.error(f"[nano-banana-callback-app] Ошибка обработки callback: {e}", exc_info=True)
        return {"status": "error", "message": str(e)}

@app.post("/seedream-edit-callback")
async def seedream_edit_callback_app(request: Request):
    """Callback для Seedream Edit"""
    import logging
    logger = logging.getLogger(__name__)
    try:
        data = await request.json()
        logger.info(f"[seedream-edit-callback-app] Получен callback: {data}")
        from kie_api import seedream_edit_callback
        result = await seedream_edit_callback(request)
        logger.info(f"[seedream-edit-callback-app] Callback обработан, результат: {result}")
        return result
    except Exception as e:
        logger.error(f"[seedream-edit-callback-app] Ошибка обработки callback: {e}", exc_info=True)
        return {"status": "error", "message": str(e)}

@app.post("/topaz-upscale-callback")
async def topaz_upscale_callback_app(request: Request):
    """Callback для Topaz Upscale (использует тот же handler что и Nano Banana)"""
    from kie_api import nano_banana_callback
    return await nano_banana_callback(request)

# --- Callback handlers для видео ---
@app.post("/veo3-callback")
async def veo3_callback_app(request: Request):
    """Callback для Veo 3"""
    from kie_api import veo3_callback
    return await veo3_callback(request)

@app.post("/sora2-callback")
async def sora2_callback_app(request: Request):
    """Callback для SORA 2"""
    from kie_api import sora2_callback
    return await sora2_callback(request)

@app.post("/grok-imagine-callback")
async def grok_imagine_callback_app(request: Request):
    """Callback для Grok Imagine"""
    from kie_api import grok_imagine_callback
    return await grok_imagine_callback(request)

@app.post("/seedance-callback")
async def seedance_callback_app(request: Request):
    """Callback для Seedance"""
    from kie_api import seedance_callback
    return await seedance_callback(request)

@app.post("/runway-callback")
async def runway_callback_app(request: Request):
    """Callback для Runway"""
    from kie_api import runway_callback
    return await runway_callback(request)


if __name__ == "__main__":
    import uvicorn
    uvicorn.run(app, host="0.0.0.0", port=8011)
