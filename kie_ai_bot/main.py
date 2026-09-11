# main.py
import asyncio
import json
import logging
import re
import uvicorn
from typing import Optional
import httpx
from aiogram import Bot, Dispatcher, F
from aiogram.exceptions import TelegramBadRequest, TelegramForbiddenError
from aiogram.types import Message, CallbackQuery, InlineKeyboardButton, InlineKeyboardMarkup, KeyboardButton, ReplyKeyboardMarkup
from aiogram.filters import Command
from aiogram.fsm.storage.memory import MemoryStorage
from aiogram.fsm.storage.redis import RedisStorage
from aiogram.fsm.context import FSMContext
from aiogram.fsm.state import StatesGroup, State
from kie_api import (
    create_video_task,
    app,
    get_generation_cost,
    get_sora2_pro_cost,
    create_sora2_video_task,
    create_suno_music_task,
    create_nano_banana_task,
    create_nano_banana_pro_task,
    create_topaz_upscale_task,
    get_topaz_upscale_cost,
    create_tts_task,
    get_tts_cost,
    get_speech_to_text_price,
    create_speech_to_text_task,
    create_seedance_task,
    get_seedance_price_options,
    get_seedance_price,
    create_runway_video_task,
    extend_runway_video_task,
    create_aleph_video_task,
    get_runway_price,
    get_runway_price_options,
    get_runway_extend_price,
    get_aleph_price,
    create_kling_motion_control_task,
    get_kling_motion_control_cost,
    create_seedream_edit_task,
    get_seedream_edit_cost,
)
from kie_api import create_grok_imagine_video_task, get_grok_imagine_cost
import kie_api
from config import TELEGRAM_BOT_TOKEN, CALLBACK_BASE_URL, is_admin as check_admin
from autopost import setup_autopost, setup_autopost_routes, autopost_worker
from telethon_source import telethon_worker
from autopost_test import setup_autopost_test
from autopost_fix import setup_autopost_fix
from admin_links import setup_admin_links
from news_autopost import news_worker
from database import (
    create_tables,
    add_user,
    get_balance,
    get_all_users,
    set_user_blocked,
    update_balance,
    deduct_balance,
    create_partner_tables,
    add_referral,
    get_partner_stats,
    update_partner_commission,
    create_withdrawal_request,
    update_withdrawal_status,
    get_partner_payment_history,
    get_user_tag,
    set_user_tag,
    create_campaign_tag,
    delete_campaign_tag,
    get_campaign_tag_by_value,
    get_campaign_tag_overview,
    log_tag_event,
    has_completed_payments,
    get_tts_free_usage,
    increment_tts_free_usage,
    create_folder,
    update_folder,
    delete_folder,
    get_all_folders,
    get_folder_by_id,
    add_channel_to_folder,
    remove_channel_from_folder,
    get_folder_channels,
    subscribe_user_to_folder,
    is_user_subscribed_to_folder,
    get_user_folder_subscriptions,
)
from payment import generate_yoomoney_link
from task_map import save_task_chat, get_task_info
import math
from datetime import datetime
import io
import httpx
import os
from PIL import Image, ImageDraw, ImageFont
logging.basicConfig(level=logging.INFO)

# Пути к фото моделей для примерки одежды
# Фото моделей должны быть загружены в папку models/ на сервере
# Или можно использовать URL к фото моделей, загруженных на сервер/хостинг
MODELS_DIR = "models"
MODEL_IMAGES = {
    "man": "models/man.jpg",  # Фото мужчины-модели (полный рост, нейтральный фон)
    "woman": "models/woman.jpg",  # Фото женщины-модели (полный рост, нейтральный фон)
    "teen_girl": "models/teen_girl.jpg",  # Фото подростка-девочки (полный рост, нейтральный фон)
    "teen_boy": "models/teen_boy.jpg",  # Фото подростка-мальчика (полный рост, нейтральный фон)
}

# Функция для получения URL фото модели
# Если модели загружены на сервер, можно использовать прямые URL
# Или загрузить файл и получить URL
async def get_model_image_url(model_type: str, base_url: str = None) -> Optional[str]:
    """
    Возвращает URL к фото модели.
    Использует внешние ссылки с genius-bot.ru вместо локального сервера.
    
    Возвращает None, если модель не найдена.
    """
    # Используем прямые ссылки на фото моделей с внешнего сервера
    model_urls = {
        "man": "https://genius-bot.ru/wp-content/uploads/2026/01/man.jpg",
        "woman": "https://genius-bot.ru/wp-content/uploads/2026/01/woman.jpg",
        "teen_girl": "https://genius-bot.ru/wp-content/uploads/2026/01/teen_girl.jpg",
        "teen_boy": "https://genius-bot.ru/wp-content/uploads/2026/01/teen_boy.jpg"
    }
    
    model_url = model_urls.get(model_type)
    
    if model_url:
        logging.info(f"[get_model_image_url] Возвращаем URL модели {model_type}: {model_url}")
        return model_url
    
    logging.warning(f"[get_model_image_url] Модель {model_type} не найдена")
    return None

# --- TTS constants ---
TTS_SUBSCRIPTION_CHAT_ID = -1001711115341
TTS_SUBSCRIPTION_LINK = "https://t.me/group_number_0001"
TTS_FREE_DAILY_LIMIT = 1000

# --- FSM Storage ---
# Используем Redis storage если доступен, иначе fallback на MemoryStorage
try:
    from redis.asyncio import Redis
    from config import REDIS_HOST, REDIS_PORT, REDIS_DB, REDIS_PASSWORD
    redis_client = Redis(
        host=REDIS_HOST,
        port=REDIS_PORT,
        db=REDIS_DB,
        password=REDIS_PASSWORD if REDIS_PASSWORD else None,
        decode_responses=True
    )
    storage = RedisStorage(redis=redis_client)
    logging.info("✅ Используется Redis storage для FSM")
except (ImportError, Exception) as e:
    storage = MemoryStorage()
    logging.warning(f"⚠️ Redis недоступен, используется MemoryStorage: {e}")

# --- Bot & Dispatcher ---
# Увеличенный таймаут: с сервера связь до api.telegram.org бывает медленной
from aiogram.client.session.aiohttp import AiohttpSession
bot = Bot(token=TELEGRAM_BOT_TOKEN, session=AiohttpSession(timeout=120))
dp = Dispatcher(storage=storage)

# Rate Limiting Middleware
try:
    from middlewares.rate_limiting import RateLimitMiddleware
    dp.message.middleware(RateLimitMiddleware(max_requests=20, time_window=60))
    dp.callback_query.middleware(RateLimitMiddleware(max_requests=30, time_window=60))
    logging.info("✅ Rate limiting middleware подключен")
except Exception as e:
    logging.warning(f"⚠️ Rate limiting недоступен: {e}")

# Metrics Middleware
try:
    from middlewares.metrics_middleware import MetricsMiddleware
    dp.message.middleware(MetricsMiddleware())
    dp.callback_query.middleware(MetricsMiddleware())
    logging.info("✅ Metrics middleware подключен")
except Exception as e:
    logging.warning(f"⚠️ Metrics middleware недоступен: {e}")

# Prometheus Metrics
try:
    from monitoring.metrics import (
        init_metrics_server,
        bot_commands_total,
        bot_messages_total,
        bot_callbacks_total,
        generation_requests_total,
        active_users,
        total_users
    )
    # Запускаем сервер метрик на порту 8001
    init_metrics_server(port=8001)
    logging.info("✅ Prometheus metrics server запущен на порту 8001")
except Exception as e:
    logging.warning(f"⚠️ Prometheus metrics недоступны: {e}")
    # Создаем заглушки для метрик
    bot_commands_total = None
    bot_messages_total = None
    bot_callbacks_total = None
    generation_requests_total = None
    active_users = None
    total_users = None

# Словарь для хранения активных платежей (chat_id: label)
active_payments = {}
class VideoGenStates(StatesGroup):
    choose_content_type = State()  # Выбор типа контента (видео/фото/музыка/другое)
    choose_ai = State()       # Шаг выбора ИИ
    choose_model = State()    # Шаг выбора модели Veo 3
    input_prompt = State()    # Шаг ввода промпта
class GenerationStates(StatesGroup):
    GET_FINAL_PROMPT = State()
    GET_VEO_IMAGE = State()  # <-- добавляем новое состояние
    # --- Шаг 1: Состояния ---

    
    input_prompt = State()
    

class AdminTagStates(StatesGroup):
    waiting_for_tag = State()

# --- FSM для Seedream 4.5 Edit ---
class SeedreamEditStates(StatesGroup):
    get_image = State()         # Получение изображения
    choose_aspect_ratio = State()  # Выбор соотношения сторон
    choose_quality = State()    # Выбор качества (basic/high)
    input_prompt = State()      # Ввод промпта для редактирования

# --- FSM для маркетплейсов ---
class MarketsStates(StatesGroup):
    choose_product_category = State()  # Выбор категории (одежда/обувь/аксессуары или другой товар)
    get_photo = State()              # Получение фото товара
    input_title = State()            # Ввод заголовка
    input_advantages = State()       # Ввод преимуществ (3-4)
    choose_model = State()           # Выбор модели (для одежды/обуви/аксессуаров)
    get_custom_model = State()       # Получение фото своей модели
    input_additional_prompt = State() # Ввод дополнительного промпта для генерации
    get_video_image = State()        # Получение изображения для видеообложки
    input_video_prompt = State()     # Ввод промпта для видеообложки
    get_upscale_image = State()      # Получение изображения для улучшения качества
    # Новые состояния для замены фона
    choose_background_type = State()  # Выбор типа замены фона
    get_product_for_bg_replace = State()  # Получение фото товара для замены фона
    get_background_reference = State()  # Получение фото референса для фона
    input_solid_color = State()      # Ввод однотонного цвета
    input_gradient_prompt = State()  # Ввод градиента
    choose_gradient_shape = State()  # Выбор формы градиента
    # Генерация фона
    input_background_prompt = State()  # Ввод промпта для генерации фона
    # Генерация нейро модели
    choose_model_gender = State()    # Выбор пола модели
    choose_model_age = State()       # Выбор возраста модели
    # Совмещение фото товара и фона
    get_product_for_composite = State()  # Получение фото товара для совмещения
    get_background_for_composite = State()  # Получение фона для совмещения

# --- Helper ---
def cancel_keyboard() -> InlineKeyboardMarkup:
    return InlineKeyboardMarkup(
        inline_keyboard=[
            [InlineKeyboardButton(text="❌ Отменить", callback_data="cancel")]
        ]
    )

def get_or_create_user(telegram_id, username):
    add_user(telegram_id, username)
    balance = get_balance(telegram_id)
    return {"telegram_id": telegram_id, "username": username, "balance": balance}

# --- Меню кнопок ---
def menu_button() -> InlineKeyboardMarkup:
    return InlineKeyboardMarkup(
        inline_keyboard=[
            [InlineKeyboardButton(text="Меню", callback_data="open_menu")]
        ]
    )


async def is_user_subscribed_to_tts_channel(user_id: int) -> bool:
    """Проверяет, подписан ли пользователь на обязательный канал TTS"""
    try:
        member = await bot.get_chat_member(TTS_SUBSCRIPTION_CHAT_ID, user_id)
        return member.status in {"creator", "administrator", "member"}
    except TelegramBadRequest as exc:
        logging.warning(f"[TTS] Не удалось проверить подписку пользователя {user_id}: {exc}")
        return False
    except TelegramForbiddenError as exc:
        logging.error(f"[TTS] Бот не имеет доступа к каналу для проверки подписки: {exc}")
        return False


async def start_seedance_flow(callback: CallbackQuery, state: FSMContext):
    price_options = get_seedance_price_options()
    option_buttons = [
        InlineKeyboardButton(
            text=f"720p · 5с ({price_options.get('720p_5s', 0)}₽)",
            callback_data="seedance_720p_5",
        ),
        InlineKeyboardButton(
            text=f"720p · 10с ({price_options.get('720p_10s', 0)}₽)",
            callback_data="seedance_720p_10",
        ),
        InlineKeyboardButton(
            text=f"1080p · 5с ({price_options.get('1080p_5s', 0)}₽)",
            callback_data="seedance_1080p_5",
        ),
        InlineKeyboardButton(
            text=f"1080p · 10с ({price_options.get('1080p_10s', 0)}₽)",
            callback_data="seedance_1080p_10",
        ),
    ]
    keyboard = InlineKeyboardMarkup(
        inline_keyboard=[
            [option_buttons[0], option_buttons[1]],
            [option_buttons[2], option_buttons[3]],
            [InlineKeyboardButton(text="❌ Отменить", callback_data="cancel")],
        ]
    )
    await callback.message.answer(
        "Выберите параметры Seedance 1.5 Pro Fast (изображение → видео):",
        reply_markup=keyboard,
    )
    await callback.answer()
    await state.set_state(SeedanceStates.choose_settings)


async def build_campaign_tags_view():
    """Формирование текста и клавиатуры для админского раздела с тегами кампаний"""
    data = get_campaign_tag_overview()
    tags = data.get("tags", [])
    no_tag_stats = data.get("no_tag")

    try:
        bot_info = await bot.get_me()
        bot_username = bot_info.username
    except Exception:
        bot_username = "vpn_fury_bot"

    lines = ["🎯 <b>Теги рекламных кампаний</b>\n"]
    if not tags:
        lines.append("Пока нет тегов. Нажмите «Добавить тег», чтобы создать первый источник.")
    else:
        for idx, tag in enumerate(tags, start=1):
            description = f" — {tag['description']}" if tag.get("description") else ""
            link = f"https://t.me/{bot_username}?start={tag['tag']}"
            lines.append(
                f"{idx}. <b>#{tag['tag']}</b>{description}\n"
                f"   • Переходы: {tag['total_transitions']} (сегодня: {tag['today_transitions']})\n"
                f"   • Пополнения сегодня: {tag['today_count']} / {tag['today_amount']:.2f}₽\n"
                f"   • Пополнения месяц: {tag['month_count']} / {tag['month_amount']:.2f}₽\n"
                f"   • Пользователей с тегом: {tag['users_count']}\n"
                f"   • Ссылка: <code>{link}</code>\n"
            )

    if no_tag_stats and any(value > 0 for value in no_tag_stats.values()):
        lines.append(
            "\n⚠️ <b>Платежи без тега</b>\n"
            f"   • Сегодня: {no_tag_stats['today_count']} / {no_tag_stats['today_amount']:.2f}₽\n"
            f"   • Текущий месяц: {no_tag_stats['month_count']} / {no_tag_stats['month_amount']:.2f}₽"
        )

    keyboard_rows = []
    for tag in tags:
        keyboard_rows.append([
            InlineKeyboardButton(
                text=f"🗑 Удалить #{tag['tag']}",
                callback_data=f"admin_campaign_tag_delete_{tag['id']}"
            )
        ])

    keyboard_rows.append([InlineKeyboardButton(text="➕ Добавить тег", callback_data="admin_campaign_tag_add")])
    keyboard_rows.append([InlineKeyboardButton(text="🔄 Обновить", callback_data="admin_campaign_tags")])
    keyboard_rows.append([InlineKeyboardButton(text="🔙 Админ меню", callback_data="admin_menu")])

    return "\n".join(lines), InlineKeyboardMarkup(inline_keyboard=keyboard_rows)


async def update_campaign_tags_message(chat_id: int, message_id: int):
    """Обновление сообщения с тегами кампаний"""
    text, keyboard = await build_campaign_tags_view()
    try:
        await bot.edit_message_text(
            text=text,
            chat_id=chat_id,
            message_id=message_id,
            reply_markup=keyboard,
            parse_mode="HTML",
            disable_web_page_preview=True
        )
    except TelegramBadRequest as exc:
        if "message is not modified" in str(exc).lower():
            return
        raise


def main_reply_keyboard() -> ReplyKeyboardMarkup:
    """Постоянная клавиатура внизу экрана для мобильных устройств"""
    keyboard = ReplyKeyboardMarkup(
        keyboard=[
            [
                KeyboardButton(text="🎬 Видео"),
                KeyboardButton(text="🖼️ Фото")
            ],
            [
                KeyboardButton(text="🎵 Музыка"),
                KeyboardButton(text="🎙️ Текст в голос")
            ]
        ],
        resize_keyboard=True,
        persistent=True
    )
    return keyboard


def markets_reply_keyboard() -> ReplyKeyboardMarkup:
    """Специальная клавиатура для пользователей маркетплейсов"""
    keyboard = ReplyKeyboardMarkup(
        keyboard=[
            [
                KeyboardButton(text="📦 Карточка товара"),
                KeyboardButton(text="🎬 Видеообложка")
            ],
            [
                KeyboardButton(text="📱 Меню")
            ]
        ],
        resize_keyboard=True,
        persistent=True
    )
    return keyboard
def main_menu_keyboard(user_tag: str = None) -> InlineKeyboardMarkup:
    """Главное меню, для tts-пользователей первые два пункта меняются"""
    if user_tag == "tts":
        return InlineKeyboardMarkup(
            inline_keyboard=[
                [InlineKeyboardButton(text="🎙️ Озвучить текст в голос", callback_data="content_tts")],
                [InlineKeyboardButton(text="🗣️ Перевод голоса в текст", callback_data="content_speech_to_text")],
                [InlineKeyboardButton(text="👤 Профиль", callback_data="menu_profile")],
                [InlineKeyboardButton(text="🌐 Авторизация на сайте", callback_data="wp_auth_link")],
                [InlineKeyboardButton(text="🚀 Начать генерацию", callback_data="menu_generate")],
                [InlineKeyboardButton(text="💰 Пополнить баланс", callback_data="menu_topup")],
                [InlineKeyboardButton(text="💼 Партнёрская программа", callback_data="menu_partner")],
                [InlineKeyboardButton(text="ℹ️ Тарифы / справка", callback_data="menu_help")],
            ]
        )
    elif user_tag == "markets":
        return InlineKeyboardMarkup(
            inline_keyboard=[
                [InlineKeyboardButton(text="📦 Создать карточку товара по фото", callback_data="markets_create_card")],
                [InlineKeyboardButton(text="🎬 Создать видеообложку", callback_data="markets_create_video")],
                [InlineKeyboardButton(text="✨ Улучшить качество фото", callback_data="markets_upscale")],
                [InlineKeyboardButton(text="🖼️ Замена фона", callback_data="markets_replace_background")],
                [InlineKeyboardButton(text="🎨 Сгенерировать фон по промпту", callback_data="markets_generate_background")],
                [InlineKeyboardButton(text="👤 Сгенерировать нейро модель", callback_data="markets_generate_model")],
                [InlineKeyboardButton(text="🔗 Совместить фото товара и фон", callback_data="markets_composite_photo")],
                [InlineKeyboardButton(text="👤 Профиль", callback_data="menu_profile")],
                [InlineKeyboardButton(text="💰 Пополнить баланс", callback_data="menu_topup")],
                [InlineKeyboardButton(text="💼 Партнёрская программа", callback_data="menu_partner")],
                [InlineKeyboardButton(text="ℹ️ Тарифы", callback_data="menu_help")],
            ]
        )
    else:
        return InlineKeyboardMarkup(
            inline_keyboard=[
                [InlineKeyboardButton(text="👤 Профиль", callback_data="menu_profile")],
                [InlineKeyboardButton(text="🌐 Авторизация на сайте", callback_data="wp_auth_link")],
                [InlineKeyboardButton(text="🚀 Начать генерацию", callback_data="menu_generate")],
                [InlineKeyboardButton(text="💰 Пополнить баланс", callback_data="menu_topup")],
                [InlineKeyboardButton(text="💼 Партнёрская программа", callback_data="menu_partner")],
            [InlineKeyboardButton(text="ℹ️ Тарифы / справка", callback_data="menu_help")],
            ]
        )

def admin_menu_keyboard() -> InlineKeyboardMarkup:
    return InlineKeyboardMarkup(
        inline_keyboard=[
            [InlineKeyboardButton(text="📊 Статистика пользователей", callback_data="admin_users_stats")],
            [InlineKeyboardButton(text="🎬 Статистика генераций", callback_data="admin_generations_stats")],
            [InlineKeyboardButton(text="💰 Статистика платежей", callback_data="admin_payments_stats")],
            [InlineKeyboardButton(text="💲 Текущие цены", callback_data="admin_pricing")],
            [InlineKeyboardButton(text="🎯 Теги кампаний", callback_data="admin_campaign_tags")],
            [InlineKeyboardButton(text="🔗 Ссылки", callback_data="links_menu")],
            [InlineKeyboardButton(text="📁 Папки с каналами", callback_data="admin_folders")],
            [InlineKeyboardButton(text="📢 Сделать рассылку", callback_data="admin_broadcast")],
            [InlineKeyboardButton(text="🔙 Главное меню", callback_data="menu_main")],
        ]
    )
    
    
# --- Коллбеки ---
# --- Обработчик кнопки "Тарифы / справка" ---
@dp.callback_query(F.data == "menu_help")
async def menu_help(callback: CallbackQuery):
    user_id = callback.from_user.id
    user_tag = get_user_tag(user_id)
    
    # Специальные тарифы для маркетплейсов
    if user_tag == "markets":
        text = (
            "🛍️ <b>Тарифы для маркетплейсов:</b>\n\n"
            f"📦 <b>Карточка товара по фото</b> → 85₽\n"
            f"  • Профессиональное изображение товара на модели\n"
            f"  • Автоматическое добавление заголовка и преимуществ\n"
            f"  • Готово для публикации на Wildberries и Ozon\n\n"
            f"🎬 <b>Видеообложка товара</b> → 160₽\n"
            f"  • Создание динамичной видеообложки из изображения\n"
            f"  • Привлечение внимания покупателей\n"
            f"  • Увеличение конверсии продаж\n\n"
            f"✨ <b>Улучшение качества фото</b> → 8₽ / 16₽ / 27₽\n"
            f"  • 1x-2x → 8₽\n"
            f"  • 4x → 16₽\n"
            f"  • 8x → 27₽\n\n"
            f"🖼️ <b>Замена фона</b> → 40₽\n"
            f"  • На фон из референса\n"
            f"  • На градиент\n\n"
            f"🎨 <b>Генерация фона по промпту</b> → 20₽\n\n"
            f"👤 <b>Генерация нейро модели</b> → 60₽\n\n"
            f"🔗 <b>Совмещение фото товара и фона</b> → 75₽\n"
        )
        keyboard = InlineKeyboardMarkup(
            inline_keyboard=[
                [InlineKeyboardButton(text="📦 Создать карточку товара", callback_data="markets_create_card")],
                [InlineKeyboardButton(text="🎬 Создать видеообложку", callback_data="markets_create_video")],
                [InlineKeyboardButton(text="✨ Улучшить качество фото", callback_data="markets_upscale")],
                [InlineKeyboardButton(text="🔙 Меню", callback_data="open_menu")]
            ]
        )
        await callback.message.edit_text(text, reply_markup=keyboard, parse_mode="HTML")
        await callback.answer()
        return
    
    # Для остальных пользователей показываем общие тарифы
    # Для меню используем те же хелперы, что и при списании, чтобы совпадали суммы
    if hasattr(kie_api, 'get_menu_prices_unified'):
        prices = await kie_api.get_menu_prices_unified()
    elif hasattr(kie_api, 'get_all_user_prices_async'):
        prices = await kie_api.get_all_user_prices_async()
    else:
        prices = kie_api.get_all_user_prices()
    text = (
        "📌 Тарифы генерации:\n\n"
        f"🎬 Veo 3.1:\n"
        f"▫️ Fast → {prices['veo3_fast']}₽\n"
        f"▫️ Quality → {prices['veo3_quality']}₽\n\n"
        f"🎬 SORA 2:\n"
        f"▫️ Text → Video → {prices['sora2_text']}₽\n"
        f"▫️ Image → Video → {prices['sora2_image']}₽\n\n"
            f"🎬 Grok Imagine:\n"
            f"▫️ Image → Video 6s → {prices['grok_imagine']}₽\n\n"
            f"🎬 Seedance 1.5 Pro Fast:\n"
            f"▫️ 720p · 5с → {prices.get('seedance_720p_5s', '—')}₽\n"
            f"▫️ 720p · 10с → {prices.get('seedance_720p_10s', '—')}₽\n"
            f"▫️ 1080p · 5с → {prices.get('seedance_1080p_5s', '—')}₽\n"
            f"▫️ 1080p · 10с → {prices.get('seedance_1080p_10s', '—')}₽\n\n"
            f"🎬 Runway:\n"
            f"▫️ 5с · 720p → {prices.get('runway_720p_5s', '—')}₽\n"
            f"▫️ 5с · 1080p → {prices.get('runway_1080p_5s', '—')}₽\n"
            f"▫️ 10с · 720p → {prices.get('runway_720p_10s', '—')}₽\n"
            f"▫️ Дополнить 5с · 720p → {prices.get('runway_extend_720p_5s', '—')}₽\n"
            f"▫️ Дополнить 5с · 1080p → {prices.get('runway_extend_1080p_5s', '—')}₽\n"
            f"▫️ Aleph (Видео → Видео) → {prices.get('runway_aleph', '—')}₽\n"
            f"  💡 Трансформация видео: изменение стиля, эффектов, объектов\n\n"
            f"🎬 Kling 2.6 Motion Control:\n"
            f"▫️ 720p → {prices.get('kling_motion_control_720p', '—')}₽\n"
            f"▫️ 1080p → {prices.get('kling_motion_control_1080p', '—')}₽\n"
            f"  💡 Перенос движений с референс-видео на персонажа\n\n"
        f"⭐ SORA 2 Pro:\n"
        f"▫️ Standard 10s → {prices['sora2pro_standard_10s']}₽\n"
        f"▫️ Standard 15s → {prices['sora2pro_standard_15s']}₽\n"
        f"▫️ HD 10s → {prices['sora2pro_hd_10s']}₽\n"
        f"▫️ HD 15s → {prices['sora2pro_hd_15s']}₽\n\n"
        f"🎵 Suno (Все модели):\n"
        f"▫️ V3.5 → {prices['suno']}₽\n"
        f"▫️ V4 → {prices['suno']}₽\n"
        f"▫️ V4.5 → {prices['suno']}₽\n"
        f"▫️ V4.5PLUS → {prices['suno']}₽\n"
        f"▫️ V5 → {prices['suno']}₽\n\n"
        f"🖼️ Nano Banana:\n"
        f"▫️ Генерация → {prices['nano_banana']}₽\n"
        f"▫️ Редактирование → {prices['nano_banana']}₽\n"
        f"▫️ Увеличение (Topaz):\n"
        f"  • 1x-2x → 8₽\n"
        f"  • 4x → 16₽\n"
        f"  • 8x → 27₽\n"
        f"▫️ PRO (расширенные параметры) → 18₽\n\n"
        f"🎨 Seedream 4.5 Edit:\n"
        f"▫️ Basic (2K) → {prices.get('seedream_edit_basic', 25)}₽\n"
        f"▫️ High (4K) → {prices.get('seedream_edit_high', 40)}₽\n\n"
        f"🎙️ Текст в речь (ElevenLabs TTS):\n"
        f"▫️ До 1000 знаков → 12₽\n"
        f"▫️ От 1000 до 2000 → 24₽\n"
        f"▫️ От 2000 до 3000 → 36₽\n"
        f"▫️ Каждые 1000 знаков +12₽\n\n"
        f"🗣️ Голос в текст (ElevenLabs Scribe):\n"
        f"▫️ 1 минута аудио → <b>{prices['stt']}₽</b>\n"
    )
    keyboard = InlineKeyboardMarkup(
        inline_keyboard=[
            [InlineKeyboardButton(text="Начать Seedance", callback_data="ai_seedance")],
            [InlineKeyboardButton(text="Меню", callback_data="open_menu")]
        ]
    )
    await callback.message.edit_text(text, reply_markup=keyboard, parse_mode="HTML")
    await callback.answer()

@dp.callback_query(F.data=="open_menu")
async def open_menu_callback(callback: CallbackQuery):
    user_id = callback.from_user.id
    user_tag = get_user_tag(user_id)
    
    logging.info(f"[open_menu_callback] user_id={user_id}, user_tag={user_tag}")
    
    if user_tag == "tts":
        menu_text = (
            "🤖 Главное меню\n\n"
            "Перед началом генерации прослушайте примеры озвучки для выбора нейро голоса:\n"
            "<a href=\"https://genius-bot.ru/nejroset-golos-primery-golosov-dlya-ozvuchki/\">Прослушать голоса</a>\n\n"
            "Выберите действие:"
        )
    elif user_tag == "markets":
        menu_text = (
            "🛍️ <b>Меню маркетплейсов</b>\n\n"
            "Создавайте профессиональные карточки товаров для Wildberries и Ozon."
        )
    else:
        menu_text = "🤖 Главное меню\n\nВыберите действие:"
    
    # Убеждаемся, что user_tag передается правильно (может быть None)
    await callback.message.answer(
        menu_text, 
        reply_markup=main_menu_keyboard(user_tag=user_tag), 
        parse_mode="HTML" if user_tag in ("tts", "markets") else None
    )
    await callback.answer()

# --- Обработчик партнерской программы ---
@dp.callback_query(F.data == "menu_partner")
async def menu_partner_callback(callback: CallbackQuery):
    user_id = callback.from_user.id
    username = callback.from_user.username or "user"
    
    # Получаем статистику партнера
    stats = get_partner_stats(user_id)
    
    # Генерируем партнерскую ссылку
    try:
        bot_info = await callback.bot.get_me()
        bot_username = bot_info.username
    except:
        bot_username = "vpn_fury_bot"  # Fallback если не удается получить username
    
    partner_link = f"https://t.me/{bot_username}?start={username}"
    
    text = (
        f"💼 Партнёрская программа\n\n"
        f"🔗 Ваша ссылка: {partner_link}\n"
        f"Клиентов перешло (/start): {stats['total_referrals']}\n"
        f"Получили тестовый ключ: {stats['test_keys']}\n\n"
        f"Приобретено ключей на: {stats['total_purchases']:.2f}₽\n"
        f"Заработок партнёра (10%): {stats['total_commission']:.2f}₽\n"
        f"Выведено: {stats['total_withdrawn']:.2f}₽\n"
        f"Осталось на вывод: {stats['available_for_withdrawal']:.2f}₽"
    )
    
    keyboard = InlineKeyboardMarkup(
        inline_keyboard=[
            [InlineKeyboardButton(text="💸 Запросить вывод", callback_data="partner_withdrawal")],
            [InlineKeyboardButton(text="📊 История выплат", callback_data="partner_history")],
            [InlineKeyboardButton(text="ℹ️ Как это работает", callback_data="partner_help")],
        ]
    )
    
    # Добавляем кнопку "Меню" внизу
    keyboard.inline_keyboard.extend(menu_button().inline_keyboard)
    
    await callback.message.edit_text(text, reply_markup=keyboard)
    await callback.answer()

# --- Функция для наложения текста на изображение ---
async def overlay_text_on_image(image_url: str, title: str, advantages: list) -> bytes:
    """
    Накладывает заголовок и преимущества на изображение с фоновой подложкой.
    Возвращает bytes изображения.
    """
    # Загружаем изображение
    async with httpx.AsyncClient() as client:
        response = await client.get(image_url)
        response.raise_for_status()
        image_data = response.content
    
    # Открываем изображение
    img = Image.open(io.BytesIO(image_data))
    # Конвертируем в RGB если нужно
    if img.mode != 'RGB':
        img = img.convert('RGB')
    
    # Создаем копию для рисования
    draw = ImageDraw.Draw(img)
    
    # Пытаемся загрузить шрифт, если не получается - используем стандартный
    try:
        title_font = ImageFont.truetype("arial.ttf", 60)
        advantage_font = ImageFont.truetype("arial.ttf", 40)
    except:
        try:
            title_font = ImageFont.truetype("/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf", 60)
            advantage_font = ImageFont.truetype("/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf", 40)
        except:
            title_font = ImageFont.load_default()
            advantage_font = ImageFont.load_default()
    
    width, height = img.size
    
    # Рисуем заголовок вверху с фоновой подложкой
    if title:
        # Вычисляем размер текста
        bbox = draw.textbbox((0, 0), title, font=title_font)
        text_width = bbox[2] - bbox[0]
        text_height = bbox[3] - bbox[1]
        
        # Прямоугольник для фона заголовка
        padding = 20
        rect_x1 = (width - text_width) // 2 - padding
        rect_y1 = 20
        rect_x2 = (width + text_width) // 2 + padding
        rect_y2 = 20 + text_height + padding * 2
        
        # Рисуем полупрозрачный фон
        overlay = Image.new('RGBA', img.size, (0, 0, 0, 0))
        overlay_draw = ImageDraw.Draw(overlay)
        overlay_draw.rectangle([rect_x1, rect_y1, rect_x2, rect_y2], fill=(0, 0, 0, 200))
        img = Image.alpha_composite(img.convert('RGBA'), overlay).convert('RGB')
        draw = ImageDraw.Draw(img)
        
        # Рисуем текст заголовка
        text_x = (width - text_width) // 2
        text_y = 20 + padding
        draw.text((text_x, text_y), title, fill=(255, 255, 255), font=title_font)
    
    # Рисуем преимущества внизу
    if advantages:
        y_start = height - 200  # Начинаем с отступом от низа
        for i, advantage in enumerate(advantages[:4]):  # Максимум 4 преимущества
            # Вычисляем размер текста
            bbox = draw.textbbox((0, 0), advantage, font=advantage_font)
            text_width = bbox[2] - bbox[0]
            text_height = bbox[3] - bbox[1]
            
            # Прямоугольник для фона
            padding = 15
            rect_x1 = (width - text_width) // 2 - padding
            rect_y1 = y_start - text_height - padding
            rect_x2 = (width + text_width) // 2 + padding
            rect_y2 = y_start + padding
            
            # Рисуем полупрозрачный фон
            overlay = Image.new('RGBA', img.size, (0, 0, 0, 0))
            overlay_draw = ImageDraw.Draw(overlay)
            overlay_draw.rectangle([rect_x1, rect_y1, rect_x2, rect_y2], fill=(0, 0, 0, 180))
            img = Image.alpha_composite(img.convert('RGBA'), overlay).convert('RGB')
            draw = ImageDraw.Draw(img)
            
            # Рисуем текст преимущества
            text_x = (width - text_width) // 2
            text_y = y_start - text_height
            draw.text((text_x, text_y), advantage, fill=(255, 255, 255), font=advantage_font)
            
            y_start -= (text_height + padding * 2 + 10)  # Отступ между преимуществами
    
    # Сохраняем в bytes
    output = io.BytesIO()
    img.save(output, format='JPEG', quality=95)
    output.seek(0)
    return output.getvalue()

# --- Обработчики для маркетплейсов ---
@dp.callback_query(F.data == "markets_create_card")
async def markets_create_card_callback(callback: CallbackQuery, state: FSMContext):
    """Обработчик создания карточки товара"""
    try:
        await callback.answer()
    except TelegramBadRequest as e:
        if "query is too old" in str(e).lower() or "query id is invalid" in str(e).lower():
            logging.warning(f"[markets_create_card_callback] Callback query устарел: {e}")
            return
        raise
    
    user_id = callback.from_user.id
    user_tag = get_user_tag(user_id)
    
    if user_tag != "markets":
        await callback.message.answer("❌ Эта функция доступна только для пользователей маркетплейсов.")
        return
    
    # Спрашиваем категорию товара
    category_buttons = [
        {"text": "👕 Одежда / 👟 Обувь / ⌚ Аксессуары", "callback_data": "markets_category_fashion"},
        {"text": "📦 Другой товар", "callback_data": "markets_category_other"},
    ]
    keyboard = InlineKeyboardMarkup(
        inline_keyboard=[[InlineKeyboardButton(**btn)] for btn in category_buttons] + 
        [[InlineKeyboardButton(text="❌ Отменить", callback_data="cancel")]]
    )
    
    await callback.message.answer(
        "📦 <b>Создание карточки товара</b>\n\n"
        "🤖 <b>Как это работает:</b>\n"
        "1️⃣ Выберите категорию товара ниже\n"
        "2️⃣ Отправьте фото вашего товара\n"
        "3️⃣ Добавьте заголовок и преимущества товара\n"
        "4️⃣ При необходимости укажите дополнительные условия (стиль, фон, композицию)\n"
        "5️⃣ ИИ автоматически создаст профессиональную карточку товара\n\n"
        "✨ <b>Что будет создано:</b>\n"
        "• Оптимизированное изображение для маркетплейса\n"
        "• Улучшенный фон и качество фото\n"
        "• Готовое изображение для Wildberries и Ozon\n\n"
        "📋 <b>Выберите категорию товара:</b>",
        reply_markup=keyboard,
        parse_mode="HTML"
    )
    await state.set_state(MarketsStates.choose_product_category)

@dp.callback_query(MarketsStates.choose_product_category, F.data.startswith("markets_category_"))
async def markets_choose_product_category_callback(callback: CallbackQuery, state: FSMContext):
    """Обработчик выбора категории товара"""
    try:
        await callback.answer()
    except TelegramBadRequest as e:
        if "query is too old" in str(e).lower() or "query id is invalid" in str(e).lower():
            logging.warning(f"[markets_choose_product_category_callback] Callback query устарел: {e}")
            return
        raise
    
    category = callback.data.split("_")[-1]  # fashion, other
    
    # Очищаем предыдущие данные и устанавливаем новую категорию
    await state.update_data(product_category=category)
    logging.info(f"[markets_choose_product_category_callback] Установлена категория: {category} для user_id={callback.from_user.id}")
    
    # Для обеих категорий сразу запрашиваем фото
    category_name = "Одежда / Обувь / Аксессуары" if category == "fashion" else "Другой товар"
    await callback.message.answer(
        f"📦 <b>Создание карточки товара</b>\n\n"
        f"✅ Выбрана категория: <b>{category_name}</b>\n\n"
        f"📸 <b>Что делать дальше:</b>\n"
        f"Отправьте фото вашего товара в этом чате.\n\n"
        f"📋 <b>Требования к изображению:</b>\n"
        f"• Минимум 700×900 пикселей (Wildberries)\n"
        f"• Минимум 900×1200 пикселей (Ozon)\n"
        f"• Формат: JPEG или PNG\n"
        f"• Чистый фон, товар в фокусе\n"
        f"• Хорошее освещение\n\n"
        f"⏱️ <b>Время обработки:</b> 1-3 минуты\n\n"
        f"💡 <b>Совет:</b> Чем лучше качество исходного фото, тем лучше результат!",
        reply_markup=cancel_keyboard(),
        parse_mode="HTML"
    )
    await state.set_state(MarketsStates.get_photo)

@dp.callback_query(F.data == "markets_create_video")
async def markets_create_video_callback(callback: CallbackQuery, state: FSMContext):
    """Обработчик создания видеообложки"""
    try:
        await callback.answer()
    except TelegramBadRequest as e:
        if "query is too old" in str(e).lower() or "query id is invalid" in str(e).lower():
            logging.warning(f"[markets_create_video_callback] Callback query устарел: {e}")
            return
        raise
    
    user_id = callback.from_user.id
    user_tag = get_user_tag(user_id)
    
    if user_tag != "markets":
        await callback.message.answer("❌ Эта функция доступна только для пользователей маркетплейсов.")
        return
    
    await callback.message.answer(
        "🎬 <b>Создание видеообложки</b>\n\n"
        "Отправьте изображение, из которого нужно создать видеообложку.\n\n"
        "Видео будет создаваться от 2 до 10 минут. Желательно не указывать текст, наоборот указать в промпт 'Не накладывать текст' или убрать текст",
        reply_markup=cancel_keyboard(),
        parse_mode="HTML"
    )
    await state.set_state(MarketsStates.get_video_image)

@dp.callback_query(F.data == "markets_upscale")
async def markets_upscale_callback(callback: CallbackQuery, state: FSMContext):
    """Обработчик улучшения качества фото"""
    try:
        await callback.answer()
    except TelegramBadRequest as e:
        if "query is too old" in str(e).lower() or "query id is invalid" in str(e).lower():
            logging.warning(f"[markets_upscale_callback] Callback query устарел: {e}")
            return
        raise
    
    user_id = callback.from_user.id
    user_tag = get_user_tag(user_id)
    
    if user_tag != "markets":
        await callback.message.answer("❌ Эта функция доступна только для пользователей маркетплейсов.")
        return
    
    await callback.message.answer(
        "✨ <b>Улучшение качества фото</b>\n\n"
        "Отправьте фото, качество которого нужно улучшить.\n\n"
        "Изображение будет обработано с помощью Topaz Upscale.",
        reply_markup=cancel_keyboard(),
        parse_mode="HTML"
    )
    await state.set_state(MarketsStates.get_upscale_image)

@dp.callback_query(F.data=="menu_profile")
async def profile_callback(callback: CallbackQuery):
    user_id = callback.from_user.id
    balance = get_balance(user_id)
    
    # Добавляем кнопку для авторизации на сайте
    profile_keyboard = InlineKeyboardMarkup(
        inline_keyboard=[
            [InlineKeyboardButton(text="🌐 Авторизация на сайте", callback_data="wp_auth_link")],
            [InlineKeyboardButton(text="🔙 Меню", callback_data="open_menu")]
        ]
    )
    
    await callback.message.edit_text(
        f"👤 Профиль пользователя:\n\n"
        f"Имя: {callback.from_user.full_name}\n"
        f"Баланс: {balance:.2f} токенов",
        reply_markup=profile_keyboard
    )
    await callback.answer()
    
# Callback для генерации ссылки авторизации
@dp.callback_query(F.data == "wp_auth_link")
async def wp_auth_link_callback(callback: CallbackQuery):
    """Генерация ссылки для авторизации на WordPress сайте"""
    from config import WP_SITE_URL, WP_AUTH_PAGE, TELEGRAM_BOT_TOKEN
    import hashlib
    import urllib.parse
    
    user_id = callback.from_user.id
    username = callback.from_user.username or ''
    first_name = callback.from_user.first_name or ''
    last_name = callback.from_user.last_name or ''
    
    # Генерируем hash для проверки авторизации
    secret_key = TELEGRAM_BOT_TOKEN.split(':')[1]  # Берем часть после двоеточия
    auth_string = f"{user_id}{secret_key}"
    auth_hash = hashlib.sha256(auth_string.encode()).hexdigest()[:16]  # Первые 16 символов
    
    # Формируем параметры для авторизации
    auth_params = {
        'telegram_auth': '1',
        'id': str(user_id),
        'hash': auth_hash,
        'first_name': first_name,
        'username': username
    }
    
    if last_name:
        auth_params['last_name'] = last_name
    
    # Формируем URL
    base_url = WP_SITE_URL + WP_AUTH_PAGE
    auth_url = base_url + '?' + urllib.parse.urlencode(auth_params)
    
    # Создаем кнопку с ссылкой
    auth_keyboard = InlineKeyboardMarkup(
        inline_keyboard=[
            [InlineKeyboardButton(text="🔗 Перейти на сайт для авторизации", url=auth_url)],
            [InlineKeyboardButton(text="🔙 Назад", callback_data="menu_profile")]
        ]
    )
    
    await callback.message.edit_text(
        f"🌐 <b>Авторизация на сайте</b>\n\n"
        f"Нажмите кнопку ниже, чтобы перейти на сайт и автоматически авторизоваться:\n\n"
        f"<code>{auth_url}</code>\n\n"
        f"Или скопируйте ссылку вручную.",
        reply_markup=auth_keyboard,
        parse_mode="HTML"
    )
    await callback.answer()

# Callback для показа ссылки
@dp.callback_query(F.data == "show_auth_link")
async def show_auth_link_callback(callback: CallbackQuery):
    """Показ ссылки для копирования"""
    from config import WP_SITE_URL, WP_AUTH_PAGE, TELEGRAM_BOT_TOKEN
    import hashlib
    import urllib.parse
    
    user_id = callback.from_user.id
    username = callback.from_user.username or ''
    first_name = callback.from_user.first_name or ''
    last_name = callback.from_user.last_name or ''
    
    # Генерируем hash для проверки авторизации
    secret_key = TELEGRAM_BOT_TOKEN.split(':')[1]
    auth_string = f"{user_id}{secret_key}"
    auth_hash = hashlib.sha256(auth_string.encode()).hexdigest()[:16]
    
    # Формируем параметры для авторизации
    auth_params = {
        'telegram_auth': '1',
        'id': str(user_id),
        'hash': auth_hash,
        'first_name': first_name,
        'username': username
    }
    
    if last_name:
        auth_params['last_name'] = last_name
    
    # Формируем URL
    base_url = WP_SITE_URL + WP_AUTH_PAGE
    auth_url = base_url + '?' + urllib.parse.urlencode(auth_params)
    
    await callback.answer(f"Ссылка: {auth_url}", show_alert=True)
    
 # --- Шаг 1: Нажатие кнопки "Генерация" ---
@dp.callback_query(F.data == "menu_generate")
async def menu_generate_callback(callback: CallbackQuery, state: FSMContext):
    await callback.answer()
    
    # Новая структура меню
    content_buttons = [
        {"text": "🎬 Видео", "callback_data": "content_video"},
        {"text": "🖼️ Фото", "callback_data": "content_photo"},
        {"text": "🎵 Музыка или песня", "callback_data": "content_music"},
        {"text": "🎤 Голос в текст", "callback_data": "content_speech_to_text"},
        {"text": "🎙️ Текст в речь", "callback_data": "content_tts"},
        {"text": "🔧 Остальные функции Suno", "callback_data": "content_suno_other"},
        {"text": "📋 Показать все нейросети", "callback_data": "content_all_ai"},
    ]
    keyboard = InlineKeyboardMarkup(
        inline_keyboard=[[InlineKeyboardButton(**btn)] for btn in content_buttons]
    )
    await callback.message.answer("Что вы хотите создать?", reply_markup=keyboard)
    await state.set_state(VideoGenStates.choose_content_type)

# --- Обработчик выбора типа контента ---
# Универсальный обработчик для content_tts из любого места (включая главное меню)
# Регистрируем БЕЗ декоратора, чтобы точно контролировать порядок регистрации
async def content_tts_handler(callback: CallbackQuery, state: FSMContext):
    """Обработчик кнопки 'Озвучить текст в голос' из главного меню или меню генерации"""
    await callback.answer()
    logging.info(f"[TTS] content_tts_handler вызван из callback.data={callback.data}")
    # Сначала показываем сообщение со ссылкой на примеры голосов
    text = (
        "🎙️ Текст в речь\n\n"
        "Перед выбором голоса прослушайте примеры озвучки:\n"
        "<a href=\"https://genius-bot.ru/nejroset-golos-primery-golosov-dlya-ozvuchki/\">Прослушать голоса</a>\n\n"
        "Выберите голос для генерации:"
    )
    keyboard = InlineKeyboardMarkup(
        inline_keyboard=[
            [InlineKeyboardButton(text="🎙️ Выбрать голос для генерации", callback_data="tts_show_voices")]
        ]
    )
    await callback.message.answer(text, reply_markup=keyboard, parse_mode="Markdown")
    await state.set_state(TTSStates.choose_voice_info)

@dp.callback_query(VideoGenStates.choose_content_type)
async def choose_content_type_callback(callback: CallbackQuery, state: FSMContext):
    await callback.answer()
    content_type = callback.data
    if content_type == "content_video":
        # Выбор ИИ для видео
        seedance_prices_preview = get_seedance_price_options()
        seedance_min_price = min(seedance_prices_preview.values()) if seedance_prices_preview else 0
        # Получаем цены для Kling Motion Control
        kling_720p_price = await get_kling_motion_control_cost("720p")
        ai_buttons = [
            {"text": "Veo 3.1", "callback_data": "ai_veo3"},
            {"text": "SORA 2", "callback_data": "ai_sora2"},
            {"text": f"Seedance 1.5 Pro Fast (от {seedance_min_price}₽)", "callback_data": "ai_seedance"},
            {"text": "Runway (5-10 секунд)", "callback_data": "ai_runway"},
            {"text": "Grok Imagine", "callback_data": "ai_grok"},
            {"text": f"Kling 2.6 Motion Control (от {kling_720p_price}₽)", "callback_data": "ai_kling_motion"},
        ]
        keyboard = InlineKeyboardMarkup(
            inline_keyboard=[[InlineKeyboardButton(**btn)] for btn in ai_buttons]
        )
        await callback.message.answer("Выберите ИИ для генерации видео:", reply_markup=keyboard)
        await state.set_state(VideoGenStates.choose_ai)
        return
    elif content_type == "content_speech_to_text":
        # Информация о Speech-to-Text ElevenLabs
        minute_price = get_speech_to_text_price(60)
        description = (
            "🎤 Голос в текст — Точная транскрипция аудио (ElevenLabs Scribe v1) с поддержкой нескольких языков, спикер-диаризацией и разметкой событий.\n\n"
            f"Стоимость: <b>{minute_price}₽</b> за 1 минуту аудио (с учетом наценки).\n\n"
            "Максимальный размер файла — 200 МБ. Поддерживаемые форматы: MPEG, WAV, AAC, MP4, OGG и др.\n\n"
            "Отправьте ссылку или загрузите аудиофайл, чтобы начать."
        )
        keyboard = InlineKeyboardMarkup(
            inline_keyboard=[
                [InlineKeyboardButton(text="🚀 Начать распознавание", callback_data="speech_to_text_start")],
                *menu_button().inline_keyboard
            ]
        )
        await callback.message.answer(description, reply_markup=keyboard, parse_mode="HTML")
        return
    elif content_type == "content_music":
        # Переход к выбору модели Suno для музыки
        model_buttons = [
            {"text": "V3.5 - Структурированные песни (15₽)", "callback_data": "suno_model_V3_5"},
            {"text": "V4 - Улучшенный вокал (15₽)", "callback_data": "suno_model_V4"},
            {"text": "V4.5 - Умные промпты (15₽)", "callback_data": "suno_model_V4_5"},
            {"text": "V4.5PLUS - Богатое звучание (15₽)", "callback_data": "suno_model_V4_5PLUS"},
            {"text": "V5 - Быстрая генерация (15₽)", "callback_data": "suno_model_V5"},
        ]
        keyboard = InlineKeyboardMarkup(
            inline_keyboard=[[InlineKeyboardButton(**btn)] for btn in model_buttons]
        )
        await callback.message.answer("Выберите модель Suno для генерации музыки:", reply_markup=keyboard)
        await state.set_state(SunoStates.choose_model)
        return
    elif content_type == "content_photo":
        # Переход к выбору режима Nano Banana или Seedream Edit
        mode_buttons = [
            {"text": "🖼️ Генерация изображения (5₽)", "callback_data": "nano_mode_generate"},
            {"text": "✏️ Редактирование изображения (5₽)", "callback_data": "nano_mode_edit"},
            {"text": "🎨 Seedream 4.5 Edit (от 25₽)", "callback_data": "ai_seedream_edit"},
            {"text": "🔍 Увеличение разрешения (от 8₽)", "callback_data": "nano_mode_upscale"},
            {"text": "⭐ PRO - Генерация с расширенными параметрами (18₽)", "callback_data": "nano_mode_pro"},
        ]
        keyboard = InlineKeyboardMarkup(
            inline_keyboard=[[InlineKeyboardButton(**btn)] for btn in mode_buttons]
        )
        await callback.message.answer(
            "Выберите режим генерации изображений:\n\n"
            "🖼️ Генерация/✏️ Редактирование: <b>Nano Banana</b>\n"
            "🎨 Редактирование: <b>Seedream 4.5 Edit</b>\n"
            "⭐ PRO: <b>Nano Banana Pro</b> на базе Gemini 3.0 Pro Image\n"
            "🔍 Увеличение: <b>Topaz Image Upscale</b>",
            reply_markup=keyboard,
            parse_mode="HTML"
        )
        await state.set_state(NanoBananaStates.choose_mode)
        return
    elif content_type == "content_tts":
        # Обработка через универсальный обработчик content_tts_handler
        # Просто перенаправляем на него
        await content_tts_handler(callback, state)
        return
    elif content_type == "content_suno_other":
        await callback.message.answer("🔧 Остальные функции Suno (расширение, обработка аудио) будут добавлены позже.")
        return
    elif content_type == "content_all_ai":
        # Показать все доступные нейросети
        seedance_prices_preview = get_seedance_price_options()
        seedance_min_price = min(seedance_prices_preview.values()) if seedance_prices_preview else 0
        kling_720p_price = await get_kling_motion_control_cost("720p")
        all_ai_buttons = [
            {"text": "🎬 Veo 3.1 - Видео", "callback_data": "ai_veo3"},
            {"text": "🎬 SORA 2 - Видео", "callback_data": "ai_sora2"},
            {"text": f"🎬 Seedance 1.5 Pro Fast (от {seedance_min_price}₽)", "callback_data": "ai_seedance"},
            {"text": "🎬 Runway - Видео", "callback_data": "ai_runway"},
            {"text": "🎬 Grok Imagine - Видео", "callback_data": "ai_grok"},
            {"text": f"🎬 Kling 2.6 Motion Control (от {kling_720p_price}₽)", "callback_data": "ai_kling_motion"},
            {"text": "🎵 Suno - Музыка", "callback_data": "content_music"},
            {"text": "🖼️ Nano Banana - Изображения", "callback_data": "content_photo"},
            {"text": "🎨 Seedream 4.5 Edit - Редактирование", "callback_data": "ai_seedream_edit"},
            {"text": "🎙️ Текст в речь", "callback_data": "content_tts"},
        ]
        keyboard = InlineKeyboardMarkup(
            inline_keyboard=[[InlineKeyboardButton(**btn)] for btn in all_ai_buttons]
        )
        await callback.message.answer("📋 Все доступные нейросети:", reply_markup=keyboard)
        await state.set_state(VideoGenStates.choose_ai)
        return
    await state.set_state(VideoGenStates.choose_ai)

# --- Seedream 4.5 Edit: начало потока ---
async def start_seedream_edit_flow(callback: CallbackQuery, state: FSMContext):
    """Начинает поток редактирования изображения через Seedream 4.5 Edit"""
    await callback.message.answer(
        "🖼️ Отправьте изображение для редактирования (PNG/JPG/WEBP до 10 МБ):",
        reply_markup=cancel_keyboard()
    )
    await state.set_state(SeedreamEditStates.get_image)
    await callback.answer()


@dp.message(SeedreamEditStates.get_image)
async def seedream_edit_get_image(message: Message, state: FSMContext):
    """Получает изображение от пользователя"""
    file_id = None
    if message.photo:
        file_id = message.photo[-1].file_id
    elif message.document and message.document.mime_type.startswith("image/"):
        file_id = message.document.file_id
    else:
        await message.answer(
            "⚠️ Пожалуйста, отправьте фото или изображение в формате PNG/JPG/WEBP.",
            reply_markup=cancel_keyboard()
        )
        return

    file_info = await bot.get_file(file_id)
    file_url = f"https://api.telegram.org/file/bot{TELEGRAM_BOT_TOKEN}/{file_info.file_path}"
    await state.update_data(seedream_image_url=file_url)
    
    # Показываем выбор соотношения сторон
    aspect_ratio_buttons = [
        {"text": "1:1", "callback_data": "seedream_aspect_1:1"},
        {"text": "4:3", "callback_data": "seedream_aspect_4:3"},
        {"text": "3:4", "callback_data": "seedream_aspect_3:4"},
        {"text": "16:9", "callback_data": "seedream_aspect_16:9"},
        {"text": "9:16", "callback_data": "seedream_aspect_9:16"},
        {"text": "2:3", "callback_data": "seedream_aspect_2:3"},
        {"text": "3:2", "callback_data": "seedream_aspect_3:2"},
        {"text": "21:9", "callback_data": "seedream_aspect_21:9"},
    ]
    keyboard = InlineKeyboardMarkup(
        inline_keyboard=[[InlineKeyboardButton(**btn)] for btn in aspect_ratio_buttons] + 
        [[InlineKeyboardButton(text="❌ Отмена", callback_data="cancel")]]
    )
    await message.answer(
        "✅ Изображение получено! Выберите соотношение сторон:",
        reply_markup=keyboard
    )
    await state.set_state(SeedreamEditStates.choose_aspect_ratio)


@dp.callback_query(SeedreamEditStates.choose_aspect_ratio)
async def seedream_edit_choose_aspect_ratio(callback: CallbackQuery, state: FSMContext):
    """Обрабатывает выбор соотношения сторон"""
    if callback.data == "cancel":
        await cancel_callback(callback, state)
        return
    
    if not callback.data.startswith("seedream_aspect_"):
        await callback.answer("Выберите вариант из списка", show_alert=True)
        return
    
    aspect_ratio = callback.data.replace("seedream_aspect_", "")
    await state.update_data(seedream_aspect_ratio=aspect_ratio)
    
    # Показываем выбор качества
    quality_buttons = [
        {"text": "Basic (2K) - 25₽", "callback_data": "seedream_quality_basic"},
        {"text": "High (4K) - 40₽", "callback_data": "seedream_quality_high"},
    ]
    keyboard = InlineKeyboardMarkup(
        inline_keyboard=[[InlineKeyboardButton(**btn)] for btn in quality_buttons] + 
        [[InlineKeyboardButton(text="❌ Отмена", callback_data="cancel")]]
    )
    await callback.message.answer(
        "Выберите качество изображения:",
        reply_markup=keyboard
    )
    await state.set_state(SeedreamEditStates.choose_quality)
    await callback.answer()


@dp.callback_query(SeedreamEditStates.choose_quality)
async def seedream_edit_choose_quality(callback: CallbackQuery, state: FSMContext):
    """Обрабатывает выбор качества"""
    if callback.data == "cancel":
        await cancel_callback(callback, state)
        return
    
    if not callback.data.startswith("seedream_quality_"):
        await callback.answer("Выберите вариант из списка", show_alert=True)
        return
    
    quality = callback.data.replace("seedream_quality_", "")
    cost = get_seedream_edit_cost(
        aspect_ratio=(await state.get_data()).get("seedream_aspect_ratio", "1:1"),
        quality=quality
    )
    await state.update_data(seedream_quality=quality, seedream_cost=cost)
    
    await callback.message.answer(
        "✅ Настройки выбраны! Теперь введите описание изменений, которые нужно внести в изображение (до 3000 символов):",
        reply_markup=cancel_keyboard()
    )
    await state.set_state(SeedreamEditStates.input_prompt)
    await callback.answer()


@dp.message(SeedreamEditStates.input_prompt)
async def seedream_edit_input_prompt(message: Message, state: FSMContext):
    """Обрабатывает ввод промпта для редактирования"""
    user_data = await state.get_data()
    image_url = user_data.get("seedream_image_url")
    aspect_ratio = user_data.get("seedream_aspect_ratio", "1:1")
    quality = user_data.get("seedream_quality", "basic")
    cost = user_data.get("seedream_cost")

    if not image_url:
        await message.answer("⚠️ Ошибка состояния. Попробуйте начать заново.", reply_markup=menu_button())
        await state.clear()
        return

    prompt = (message.text or "").strip()
    if not prompt:
        await message.answer("⚠️ Пожалуйста, введите описание изменений.", reply_markup=cancel_keyboard())
        return
    
    if len(prompt) > 3000:
        await message.answer("⚠️ Промпт слишком длинный (максимум 3000 символов).", reply_markup=cancel_keyboard())
        return

    user_id = message.from_user.id
    username = message.from_user.username or "user"
    first_name = message.from_user.first_name or "Пользователь"
    cost = cost or get_seedream_edit_cost(aspect_ratio, quality)
    balance = get_balance(user_id)

    if balance < cost:
        await message.answer(
            f"💸 Недостаточно средств для редактирования.\n"
            f"Ваш баланс: {balance:.2f}₽, требуется: {cost:.2f}₽",
            reply_markup=menu_button()
        )
        await state.clear()
        return

    try:
        callback_url = f"{CALLBACK_BASE_URL}/seedream-edit-callback"
        api_response = await create_seedream_edit_task(
            image_urls=[image_url],
            prompt=prompt,
            aspect_ratio=aspect_ratio,
            quality=quality,
            callback_url=callback_url,
        )
    except Exception as e:
        api_response = {"error": str(e)}

    error_msg = None
    if api_response is None:
        error_msg = "Ошибка подключения к API."
    elif "error" in api_response:
        error_msg = api_response.get("error", "Неизвестная ошибка API")
        status_code = api_response.get("status_code")
        if status_code:
            error_msg += f" (HTTP {status_code})"
    elif api_response.get("code") != 200:
        error_msg = api_response.get("msg", "Неизвестная ошибка API")
    elif "data" not in api_response or "taskId" not in api_response["data"]:
        error_msg = "Неверный формат ответа API (отсутствует taskId)."

    from database import log_user_request

    if error_msg:
        log_user_request(
            user_telegram_id=user_id,
            user_username=username,
            user_first_name=first_name,
            request_type="seedream_edit",
            model_name="seedream/4.5-edit",
            prompt=prompt,
            image_urls=image_url,
            cost=cost,
            api_response=str(api_response),
        )
        await message.answer(f"❌ Ошибка при создании задачи:\n{error_msg}")
        await state.clear()
        return

    task_id = api_response["data"]["taskId"]
    if not deduct_balance(user_id, cost):
        logging.error(f"Seedream Edit: не удалось списать {cost}₽ у пользователя {user_id} после успешного создания задачи")
        await message.answer(
            "⚠️ Ошибка при списании средств. Пожалуйста, свяжитесь с поддержкой.",
            reply_markup=menu_button()
        )
        await state.clear()
        return

    save_task_chat(task_id, user_id, cost)
    log_user_request(
        user_telegram_id=user_id,
        user_username=username,
        user_first_name=first_name,
        request_type="seedream_edit",
        model_name="seedream/4.5-edit",
        prompt=prompt,
        image_urls=image_url,
        task_id=task_id,
        cost=cost,
        api_response=str(api_response)
    )

    await message.answer(
        f"✅ Задача Seedream Edit отправлена!\nTask ID: <code>{task_id}</code>\n"
        f"После завершения обработки изображение придет в чат автоматически.\n"
        f"Баланс будет списан только при успешной обработке.",
        reply_markup=menu_button(),
        parse_mode="HTML"
    )
    await state.clear()


# --- Seedream Edit: общий вход вне FSM ---
@dp.callback_query(F.data == "ai_seedream_edit")
async def ai_seedream_edit_entry(callback: CallbackQuery, state: FSMContext):
    await callback.answer()
    await start_seedream_edit_flow(callback, state)


# --- Seedance: общий вход вне FSM ---
@dp.callback_query(F.data == "ai_seedance")
async def ai_seedance_entry(callback: CallbackQuery, state: FSMContext):
    current_state = await state.get_state()
    if current_state == VideoGenStates.choose_ai.state:
        return  # обработает state-specific handler
    await callback.answer()
    await start_seedance_flow(callback, state)

# --- Шаг 2: Выбор ИИ ---
@dp.callback_query(VideoGenStates.choose_ai)
async def choose_ai_callback(callback: CallbackQuery, state: FSMContext):
    await callback.answer()
    selected_ai = callback.data
    await state.update_data(selected_ai=selected_ai)

    if selected_ai == "admin_menu":
        if not check_admin(callback.from_user.id, callback.from_user.username or ""):
            await callback.answer("❌ У вас нет прав для выполнения этого действия", show_alert=True)
            return
        await state.clear()
        await admin_menu_callback(callback)
        return

    if selected_ai == "ai_veo3":
        # Veo 3.1 — выбираем модель согласно актуальной API
        model_buttons = [
            {"text": "Veo 3 Quality (120₽)", "callback_data": "veo3"},
            {"text": "Veo 3 Fast (60₽)", "callback_data": "veo3_fast"},
        ]
        keyboard = InlineKeyboardMarkup(
            inline_keyboard=[[InlineKeyboardButton(**btn)] for btn in model_buttons]
        )
        await callback.message.answer("Выберите модель Veo 3.1:", reply_markup=keyboard)
        await state.set_state(VideoGenStates.choose_model)
    elif selected_ai == "ai_sora2":
        # SORA 2 — выбираем тип генерации
        sora_buttons = [
            {"text": "📄 Текст → Видео (54₽)", "callback_data": "sora2_text"},
            {"text": "🖼️ Картинка → Видео (54₽)", "callback_data": "sora2_image"},
            {"text": "⭐ SORA 2 Pro", "callback_data": "sora2_pro"},
        ]
        keyboard = InlineKeyboardMarkup(
            inline_keyboard=[[InlineKeyboardButton(**btn)] for btn in sora_buttons]
        )
        await callback.message.answer("Выберите тип SORA 2 генерации:", reply_markup=keyboard)
        await state.set_state(VideoGenStates.choose_model)
    elif selected_ai == "ai_seedance":
        await start_seedance_flow(callback, state)
    elif selected_ai == "ai_runway":
        runway_buttons = [
            {"text": "📝 Текст → Видео", "callback_data": "runway_mode_text"},
            {"text": "🖼️ Фото → Видео", "callback_data": "runway_mode_image"},
            {"text": "➕ Продлить видео", "callback_data": "runway_mode_extend"},
            {"text": "🎞️ Aleph (Видео → Видео)", "callback_data": "runway_mode_aleph"},
        ]
        keyboard = InlineKeyboardMarkup(
            inline_keyboard=[[InlineKeyboardButton(**btn)] for btn in runway_buttons]
        )
        await callback.message.answer("Выберите режим Runway:", reply_markup=keyboard)
        await state.set_state(RunwayStates.choose_mode)
        return
    elif selected_ai == "ai_grok":
        # Grok Imagine — простая ветка: Картинка → Видео 6s
        await state.update_data(grok_model="grok-imagine/image-to-video")
        await callback.message.answer(
            "🖼️ Отправьте картинку для генерации 6-секундного видео (Grok Imagine):",
            reply_markup=cancel_keyboard()
        )
        await state.set_state(GrokStates.get_image)
    elif selected_ai == "ai_kling_motion":
        # Kling 2.6 Motion Control — выбор разрешения
        kling_720p_price = await get_kling_motion_control_cost("720p")
        kling_1080p_price = await get_kling_motion_control_cost("1080p")
        mode_buttons = [
            {"text": f"720p ({kling_720p_price}₽)", "callback_data": "kling_mode_720p"},
            {"text": f"1080p ({kling_1080p_price}₽)", "callback_data": "kling_mode_1080p"},
        ]
        keyboard = InlineKeyboardMarkup(
            inline_keyboard=[[InlineKeyboardButton(**btn)] for btn in mode_buttons]
        )
        await callback.message.answer(
            "🎬 Kling 2.6 Motion Control\n\n"
            "Выберите разрешение видео:",
            reply_markup=keyboard
        )
        await state.set_state(KlingMotionControlStates.choose_mode)
    elif selected_ai == "content_music":
        # Переход к выбору модели Suno для музыки
        model_buttons = [
            {"text": "V3.5 - Структурированные песни (15₽)", "callback_data": "suno_model_V3_5"},
            {"text": "V4 - Улучшенный вокал (15₽)", "callback_data": "suno_model_V4"},
            {"text": "V4.5 - Умные промпты (15₽)", "callback_data": "suno_model_V4_5"},
            {"text": "V4.5PLUS - Богатое звучание (15₽)", "callback_data": "suno_model_V4_5PLUS"},
            {"text": "V5 - Быстрая генерация (15₽)", "callback_data": "suno_model_V5"},
        ]
        keyboard = InlineKeyboardMarkup(
            inline_keyboard=[[InlineKeyboardButton(**btn)] for btn in model_buttons]
        )
        await callback.message.answer("Выберите модель Suno для генерации музыки:", reply_markup=keyboard)
        await state.set_state(SunoStates.choose_model)
    elif selected_ai == "content_photo":
        # Переход к выбору режима Nano Banana или Seedream Edit
        mode_buttons = [
            {"text": "🖼️ Генерация изображения (5₽)", "callback_data": "nano_mode_generate"},
            {"text": "✏️ Редактирование изображения (5₽)", "callback_data": "nano_mode_edit"},
            {"text": "🎨 Seedream 4.5 Edit (от 25₽)", "callback_data": "ai_seedream_edit"},
            {"text": "🔍 Увеличение разрешения (от 8₽)", "callback_data": "nano_mode_upscale"},
            {"text": "⭐ PRO - Генерация с расширенными параметрами (18₽)", "callback_data": "nano_mode_pro"},
        ]
        keyboard = InlineKeyboardMarkup(
            inline_keyboard=[[InlineKeyboardButton(**btn)] for btn in mode_buttons]
        )
        await callback.message.answer(
            "Выберите режим генерации изображений:\n\n"
            "🖼️ Генерация/✏️ Редактирование: <b>Nano Banana</b>\n"
            "🎨 Редактирование: <b>Seedream 4.5 Edit</b>\n"
            "⭐ PRO: <b>Nano Banana Pro</b> на базе Gemini 3.0 Pro Image\n"
            "🔍 Увеличение: <b>Topaz Image Upscale</b>",
            reply_markup=keyboard,
            parse_mode="HTML"
        )
        await state.set_state(NanoBananaStates.choose_mode)
    elif selected_ai == "content_tts":
        # Сначала показываем сообщение со ссылкой на примеры голосов
        text = (
            "🎙️ Текст в речь\n\n"
            "Перед выбором голоса прослушайте примеры озвучки:\n"
            "<a href=\"https://genius-bot.ru/nejroset-golos-primery-golosov-dlya-ozvuchki/\">Прослушать голоса</a>\n\n"
            "Выберите голос для генерации:"
        )
        keyboard = InlineKeyboardMarkup(
            inline_keyboard=[
                [InlineKeyboardButton(text="🎙️ Выбрать голос для генерации", callback_data="tts_show_voices")]
            ]
        )
        await callback.message.answer(text, reply_markup=keyboard, parse_mode="Markdown")
        await state.set_state(TTSStates.choose_voice_info)
    elif selected_ai.startswith("nano_mode_"):
        # Обработка callback'ов Nano Banana, которые попали не в тот обработчик
        mode = selected_ai.split("_")[2]  # generate, edit, upscale
        await state.update_data(nano_mode=mode)
        
        # Выбор формата
        format_buttons = [
            {"text": "PNG", "callback_data": "nano_format_png"},
            {"text": "JPEG", "callback_data": "nano_format_jpeg"},
        ]
        keyboard = InlineKeyboardMarkup(
            inline_keyboard=[[InlineKeyboardButton(**btn)] for btn in format_buttons]
        )
        await callback.message.answer("Выберите формат изображения:", reply_markup=keyboard)
        await state.set_state(NanoBananaStates.choose_format)
    elif selected_ai.startswith("suno_model_"):
        # Обработка callback'ов Suno, которые попали не в тот обработчик
        model_data = selected_ai  # suno_model_V3_5, etc.
        model = model_data.split("_")[2]  # V3_5, V4, etc.
        await state.update_data(suno_model=model)
        
        # Выбор режима
        mode_buttons = [
            {"text": "🎵 Простой режим (промпт до 500 символов)", "callback_data": "suno_mode_simple"},
            {"text": "🎼 Расширенный режим (длинный промпт + стиль + название)", "callback_data": "suno_mode_custom"},
        ]
        keyboard = InlineKeyboardMarkup(
            inline_keyboard=[[InlineKeyboardButton(**btn)] for btn in mode_buttons]
        )
        await callback.message.answer("Выберите режим генерации:", reply_markup=keyboard)
        await state.set_state(SunoStates.choose_mode)
    elif selected_ai.startswith("tts_voice_"):
        # Обработка callback'ов TTS голосов, которые попали не в тот обработчик
        voice_data = selected_ai  # tts_voice_Rachel
        voice = voice_data.split("_")[2]  # Rachel
        
        await state.update_data(tts_voice=voice)
        
        await callback.message.answer(
            f"🎙️ Выбран голос: {voice}\n\n"
            f"💡 Стоимость генерации:\n"
            f"▫️ До 1000 знаков → 12₽\n"
            f"▫️ От 1000 до 2000 → 24₽\n"
            f"▫️ От 2000 до 3000 → 36₽\n"
            f"▫️ Каждые 1000 знаков +12₽\n\n"
            f"Введите текст для генерации речи:",
            reply_markup=cancel_keyboard()
        )
        await state.set_state(TTSStates.input_text)
    else:
        # Заглушка для остальных AI
        await callback.message.answer("⚠️ Этот ИИ пока не поддерживается. Пожалуйста, выберите Veo 3 или SORA 2.")
        # Не сбрасываем состояние, чтобы пользователь мог выбрать поддерживаемый ИИ

# --- FSM для Veo 3 ---
class Veo3States(StatesGroup):
    choose_model = State()      # Выбор модели Veo 3
    choose_task = State()       # Выбор типа генерации (текст/картинка/первый-последний кадр)
    choose_image_generation_type = State()  # Выбор типа генерации для одного фото
    choose_aspect_ratio = State()  # Выбор соотношения сторон (16:9 или 9:16)
    get_image = State()         # Получение картинки (если нужно)
    get_first_image = State()   # Получение первого кадра (для FIRST_AND_LAST_FRAMES_2_VIDEO)
    get_last_image = State()   # Получение последнего кадра (для FIRST_AND_LAST_FRAMES_2_VIDEO)
    input_prompt = State()      # Ввод текстового промпта

# --- FSM для SORA 2 ---
class Sora2States(StatesGroup):
    choose_pro_model = State()  # Выбор модели SORA 2 Pro
    choose_aspect_ratio = State()  # Выбор соотношения сторон
    choose_duration = State()   # Выбор длительности (10s/15s)
    choose_quality = State()    # Выбор качества (Standard/HD)
    get_image = State()         # Получение картинки (если нужно)
    input_prompt = State()      # Ввод текстового промпта

# --- FSM для Suno ---
class SunoStates(StatesGroup):
    choose_model = State()      # Выбор модели Suno (V3_5, V4, V4_5, V4_5PLUS, V5)
    choose_mode = State()       # Выбор режима (custom/simple)
    choose_instrumental = State()  # Инструментальная или с вокалом
    input_style = State()       # Ввод стиля (для custom mode)
    input_title = State()       # Ввод названия (для custom mode)
    input_prompt = State()      # Ввод промпта

# --- FSM для Nano Banana ---
class NanoBananaStates(StatesGroup):
    choose_mode = State()       # Выбор режима (generate/edit/upscale/pro)
    choose_format = State()     # Выбор формата (PNG/JPEG)
    choose_size = State()       # Выбор размера изображения
    get_image = State()         # Получение изображения (для edit/upscale/pro)
    input_prompt = State()      # Ввод промпта (для generate/edit/pro)
    choose_upscale_factor = State()  # Выбор коэффициента увеличения (для upscale: 1x, 2x, 4x, 8x)
    # PRO версия
    choose_pro_aspect_ratio = State()  # Выбор aspect ratio для PRO
    choose_pro_resolution = State()    # Выбор resolution для PRO
    get_pro_images = State()           # Получение изображений для PRO (до 8)

# --- FSM для TTS ---
class TTSStates(StatesGroup):
    choose_voice_info = State() # Показано сообщение со ссылкой, ожидается нажатие кнопки
    choose_voice = State()      # Выбор голоса
    input_text = State()        # Ввод текста
    choose_settings = State()   # Выбор настроек (опционально)

# --- FSM для Seedance 1.0 Pro Fast ---
class SeedanceStates(StatesGroup):
    choose_settings = State()   # Выбор разрешения и длительности
    get_image = State()         # Получение изображения
    input_prompt = State()      # Ввод промпта

# --- FSM для Grok Imagine ---
class GrokStates(StatesGroup):
    get_image = State()         # Получение изображения
    input_prompt = State()      # Ввод промпта

class RunwayStates(StatesGroup):
    choose_mode = State()           # Выбор режима (text/image)
    choose_aspect_ratio = State()   # Выбор aspect ratio для text-to-video
    choose_duration = State()       # Выбор длительности (5 или 10 секунд)
    choose_quality = State()        # Выбор качества (720p / 1080p)
    get_image = State()             # Получение референс-картинки
    input_prompt = State()          # Ввод промпта

class RunwayExtendStates(StatesGroup):
    input_task_id = State()         # Ввод taskId оригинального видео
    choose_quality = State()        # Выбор качества продолжения
    input_prompt = State()          # Промпт для продолжения

class AlephStates(StatesGroup):
    get_video = State()             # Получение ссылки на исходное видео
    input_prompt = State()          # Ввод промпта трансформации

# --- FSM для Kling 2.6 Motion Control ---
class KlingMotionControlStates(StatesGroup):
    choose_mode = State()           # Выбор разрешения (720p или 1080p)
    get_image = State()             # Получение изображения персонажа
    get_video = State()             # Получение видео-референса
    input_prompt = State()          # Ввод промпта

# --- FSM для партнерской программы ---
class SpeechToTextStates(StatesGroup):
    SEND_AUDIO = State()     # Ожидание аудио или ссылки для распознавания


class PartnerStates(StatesGroup):
    withdrawal_amount = State()     # Ввод суммы для вывода
    withdrawal_payment = State()    # Ввод реквизитов для вывода

class BroadcastStates(StatesGroup):
    choose_type = State()           # Выбор типа рассылки (всем или конкретным)
    input_message = State()         # Ввод сообщения для рассылки
    input_chat_ids = State()        # Ввод chat_id для конкретных пользователей

class AdminFolderStates(StatesGroup):
    input_folder_name = State()
    input_folder_description = State()
    input_folder_price = State()
    input_channel_username = State()
    input_channel_link = State()
    input_channel_title = State()
    edit_folder_name = State()
    edit_folder_description = State()
    edit_folder_price = State()

# --- Шаг 2: Выбор модели Veo 3 или SORA 2 ---
@dp.callback_query(VideoGenStates.choose_model)
async def choose_model_callback(callback: CallbackQuery, state: FSMContext):
    await callback.answer()
    selected_model = callback.data  # "veo3", "veo3_fast", "sora2_text", "sora2_image", "sora2_pro"
    
    user_data = await state.get_data()
    selected_ai = user_data.get("selected_ai")
    
    if selected_ai == "ai_veo3":
        # Veo 3 логика
        variant = "quality" if selected_model == "veo3" else "fast"
        await state.update_data(selected_model=selected_model, veo_variant=variant)
        logging.info(f"[choose_model_callback] selected_model={selected_model}, variant={variant}")

        # --- Новый шаг: выбор типа генерации ---
        keyboard = InlineKeyboardMarkup(
            inline_keyboard=[
                [InlineKeyboardButton(text="📄 Текст → Видео", callback_data="veo_task_text")],
                [InlineKeyboardButton(text="🖼️ Одно фото → Видео", callback_data="veo_task_image")],
                [InlineKeyboardButton(text="🎬 Два кадра → Видео (первый и последний)", callback_data="veo_task_first_last")]
            ]
        )
        await callback.message.answer("Выберите тип генерации:", reply_markup=keyboard)
        await state.set_state(Veo3States.choose_task)  # устанавливаем новое состояние
        
    elif selected_ai == "ai_sora2":
        # SORA 2 логика
        if selected_model == "sora2_pro":
            # Выбор типа SORA 2 Pro
            pro_buttons = [
                {"text": "📄 SORA 2 Pro Text → Video", "callback_data": "sora2_pro_text"},
                {"text": "🖼️ SORA 2 Pro Image → Video", "callback_data": "sora2_pro_image"},
            ]
            keyboard = InlineKeyboardMarkup(
                inline_keyboard=[[InlineKeyboardButton(**btn)] for btn in pro_buttons]
            )
            await callback.message.answer("Выберите тип SORA 2 Pro генерации:", reply_markup=keyboard)
            await state.set_state(Sora2States.choose_pro_model)
        else:
            # Базовые SORA 2 модели
            task_type = "text" if selected_model == "sora2_text" else "image"
            await state.update_data(sora_model=selected_model, task_type=task_type)
            
            if task_type == "image":
                await callback.message.answer(
                    "🖼️ Отправьте картинку для генерации видео:",
                    reply_markup=cancel_keyboard()
                )
                await state.set_state(Sora2States.get_image)
            else:
                await callback.message.answer(
                    "Введите текстовый промпт для генерации видео:",
                    reply_markup=cancel_keyboard()
                )
                await state.set_state(Sora2States.input_prompt)

# --- Обработчик выбора типа SORA 2 Pro ---
@dp.callback_query(Sora2States.choose_pro_model)
async def choose_sora2_pro_model_callback(callback: CallbackQuery, state: FSMContext):
    await callback.answer()
    selected_pro_model = callback.data  # "sora2_pro_text" или "sora2_pro_image"
    
    task_type = "text" if selected_pro_model == "sora2_pro_text" else "image"
    await state.update_data(sora_pro_model=selected_pro_model, task_type=task_type)
    
    # Выбор соотношения сторон
    aspect_buttons = [
        {"text": "📱 Portrait (9:16)", "callback_data": "sora2_aspect_portrait"},
        {"text": "🖥️ Landscape (16:9)", "callback_data": "sora2_aspect_landscape"},
    ]
    keyboard = InlineKeyboardMarkup(
        inline_keyboard=[[InlineKeyboardButton(**btn)] for btn in aspect_buttons]
    )
    await callback.message.answer("Выберите соотношение сторон:", reply_markup=keyboard)
    await state.set_state(Sora2States.choose_aspect_ratio)

# --- Обработчик выбора соотношения сторон SORA 2 Pro ---
@dp.callback_query(Sora2States.choose_aspect_ratio)
async def choose_sora2_aspect_ratio_callback(callback: CallbackQuery, state: FSMContext):
    await callback.answer()
    aspect_ratio = callback.data.split("_")[2]  # "portrait" или "landscape"
    await state.update_data(aspect_ratio=aspect_ratio)
    
    # Выбор длительности
    duration_buttons = [
        {"text": "⏱️ 10 секунд", "callback_data": "sora2_duration_10"},
        {"text": "⏱️ 15 секунд", "callback_data": "sora2_duration_15"},
    ]
    keyboard = InlineKeyboardMarkup(
        inline_keyboard=[[InlineKeyboardButton(**btn)] for btn in duration_buttons]
    )
    await callback.message.answer("Выберите длительность видео:", reply_markup=keyboard)
    await state.set_state(Sora2States.choose_duration)

# --- Обработчик выбора длительности SORA 2 Pro ---
@dp.callback_query(Sora2States.choose_duration)
async def choose_sora2_duration_callback(callback: CallbackQuery, state: FSMContext):
    await callback.answer()
    duration = callback.data.split("_")[2]  # "10" или "15"
    await state.update_data(duration=duration)
    
    # Выбор качества
    quality_buttons = [
        {"text": "📺 Standard", "callback_data": "sora2_quality_standard"},
        {"text": "🎬 HD", "callback_data": "sora2_quality_high"},
    ]
    keyboard = InlineKeyboardMarkup(
        inline_keyboard=[[InlineKeyboardButton(**btn)] for btn in quality_buttons]
    )
    await callback.message.answer("Выберите качество видео:", reply_markup=keyboard)
    await state.set_state(Sora2States.choose_quality)

# --- Обработчик выбора качества SORA 2 Pro ---
@dp.callback_query(Sora2States.choose_quality)
async def choose_sora2_quality_callback(callback: CallbackQuery, state: FSMContext):
    await callback.answer()
    quality = callback.data.split("_")[2]  # "standard" или "high"
    await state.update_data(quality=quality)
    
    user_data = await state.get_data()
    task_type = user_data.get("task_type")
    
    if task_type == "image":
        await callback.message.answer(
            "🖼️ Отправьте картинку для генерации видео:",
            reply_markup=cancel_keyboard()
        )
        await state.set_state(Sora2States.get_image)
    else:
        await callback.message.answer(
            "Введите текстовый промпт для генерации видео:",
            reply_markup=cancel_keyboard()
        )
        await state.set_state(Sora2States.input_prompt)

# --- Обработчик получения картинки для SORA 2 ---
@dp.message(Sora2States.get_image)
async def get_sora2_image(message: Message, state: FSMContext):
    logging.info(f"[get_sora2_image] Received message: {message}")

    file_id = None

    if message.photo:
        file_id = message.photo[-1].file_id
    elif message.document and message.document.mime_type.startswith("image/"):
        file_id = message.document.file_id
    else:
        logging.warning(f"[get_sora2_image] Message does not contain photo or image document: {message.text}")
        await message.answer(
            "⚠️ Пожалуйста, отправьте фото или изображение в формате PNG/JPG.",
            reply_markup=cancel_keyboard()
        )
        return

    file_info = await bot.get_file(file_id)
    file_url = f"https://api.telegram.org/file/bot{TELEGRAM_BOT_TOKEN}/{file_info.file_path}"
    await state.update_data(image_urls=[file_url])
    logging.info(f"[get_sora2_image] Saved image URL: {file_url}")

    await message.answer(
        "Фото принято! Пожалуйста, введите текстовый промпт для видео:",
        reply_markup=cancel_keyboard()
    )
    await state.set_state(Sora2States.input_prompt)

# --- Seedance 1.0 Pro Fast: выбор параметров ---
@dp.callback_query(SeedanceStates.choose_settings)
async def seedance_choose_settings_callback(callback: CallbackQuery, state: FSMContext):
    data = callback.data
    if data == "cancel":
        await cancel_callback(callback, state)
        return
    if not data.startswith("seedance_"):
        await callback.answer("Выберите вариант из списка", show_alert=True)
        return

    _, resolution, duration = data.split("_")
    try:
        cost = get_seedance_price(resolution, duration)
    except ValueError as e:
        await callback.answer(str(e), show_alert=True)
        return

    await state.update_data(
        seedance_resolution=resolution,
        seedance_duration=duration,
        seedance_cost=cost,
    )
    await callback.message.answer(
        "🖼️ Отправьте изображение (PNG/JPG/WEBP до 10 МБ) для генерации Seedance:",
        reply_markup=cancel_keyboard(),
    )
    await state.set_state(SeedanceStates.get_image)
    await callback.answer()


@dp.message(SeedanceStates.get_image)
async def seedance_get_image(message: Message, state: FSMContext):
    file_id = None
    if message.photo:
        file_id = message.photo[-1].file_id
    elif message.document and message.document.mime_type.startswith("image/"):
        file_id = message.document.file_id
    else:
        await message.answer(
            "⚠️ Пожалуйста, отправьте фото или изображение в формате PNG/JPG/WEBP.",
            reply_markup=cancel_keyboard()
        )
        return

    file_info = await bot.get_file(file_id)
    file_url = f"https://api.telegram.org/file/bot{TELEGRAM_BOT_TOKEN}/{file_info.file_path}"
    await state.update_data(seedance_image_url=file_url)
    await message.answer(
        "✅ Изображение получено! Теперь введите текстовый промпт для видео (до 10000 символов):",
        reply_markup=cancel_keyboard()
    )
    await state.set_state(SeedanceStates.input_prompt)


@dp.message(SeedanceStates.input_prompt)
async def seedance_input_prompt(message: Message, state: FSMContext):
    user_data = await state.get_data()
    image_url = user_data.get("seedance_image_url")
    resolution = user_data.get("seedance_resolution")
    duration = user_data.get("seedance_duration")
    cost = user_data.get("seedance_cost")

    if not image_url or not resolution or not duration:
        await message.answer("⚠️ Ошибка состояния. Попробуйте начать заново.", reply_markup=menu_button())
        await state.clear()
        return

    prompt = (message.text or "").strip()
    if not prompt:
        await message.answer("⚠️ Пожалуйста, введите текстовый промпт.", reply_markup=cancel_keyboard())
        return

    user_id = message.from_user.id
    username = message.from_user.username or "user"
    first_name = message.from_user.first_name or "Пользователь"
    cost = cost or get_seedance_price(resolution, duration)
    balance = get_balance(user_id)

    if balance < cost:
        await message.answer(
            f"💸 Недостаточно средств для генерации Seedance.\n"
            f"Ваш баланс: {balance:.2f}₽, требуется: {cost:.2f}₽",
            reply_markup=menu_button()
        )
        await state.clear()
        return

    try:
        callback_url = f"{CALLBACK_BASE_URL}/seedance-callback"
        api_response = await create_seedance_task(
            image_url=image_url,
            prompt=prompt,
            resolution=resolution,
            duration=duration,
            callback_url=callback_url,
        )
    except Exception as e:
        api_response = {"error": str(e)}

    error_msg = None
    if api_response is None:
        error_msg = "Ошибка подключения к API."
    elif "error" in api_response:
        error_msg = api_response.get("error", "Неизвестная ошибка API")
        status_code = api_response.get("status_code")
        if status_code:
            error_msg += f" (HTTP {status_code})"
    elif api_response.get("code") != 200:
        error_msg = api_response.get("msg", "Неизвестная ошибка API")
    elif "data" not in api_response or "taskId" not in api_response["data"]:
        error_msg = "Неверный формат ответа API (отсутствует taskId)."

    from database import log_user_request

    if error_msg:
        log_user_request(
            user_telegram_id=user_id,
            user_username=username,
            user_first_name=first_name,
            request_type="seedance",
            model_name=f"{resolution}_{duration}s",
            prompt=prompt,
            image_urls=image_url,
            cost=cost,
            api_response=str(api_response),
        )
        await message.answer(f"❌ Ошибка при создании задачи:\n{error_msg}")
        await state.clear()
        return

    task_id = api_response["data"]["taskId"]
    if not deduct_balance(user_id, cost):
        logging.error(f"Seedance: не удалось списать {cost}₽ у пользователя {user_id} после успешного создания задачи")
        await message.answer(
            "⚠️ Ошибка при списании средств. Пожалуйста, свяжитесь с поддержкой.",
            reply_markup=menu_button()
        )
        await state.clear()
        return

    save_task_chat(task_id, user_id, cost)
    log_user_request(
        user_telegram_id=user_id,
        user_username=username,
        user_first_name=first_name,
        request_type="seedance",
        model_name=f"{resolution}_{duration}s",
        prompt=prompt,
        image_urls=image_url,
        task_id=task_id,
        cost=cost,
        api_response=str(api_response),
    )

    await message.answer(
        f"✅ Задача Seedance отправлена!\nTask ID: <code>{task_id}</code>\n"
        "После завершения видео придет в чат автоматически.\n"
        "Баланс будет списан только при успешной генерации.",
        parse_mode="HTML"
    )
    await state.clear()

# --- Runway обработчики ---
@dp.callback_query(RunwayStates.choose_mode)
async def runway_choose_mode_callback(callback: CallbackQuery, state: FSMContext):
    await callback.answer()
    mode = callback.data  # runway_mode_text, runway_mode_image, runway_mode_extend, runway_mode_aleph
    
    if mode == "runway_mode_text":
        # Текст → Видео: выбор aspect ratio
        aspect_buttons = [
            {"text": "16:9 (Горизонтальное)", "callback_data": "runway_aspect_16:9"},
            {"text": "4:3 (Классическое)", "callback_data": "runway_aspect_4:3"},
            {"text": "1:1 (Квадратное)", "callback_data": "runway_aspect_1:1"},
            {"text": "3:4 (Вертикальное)", "callback_data": "runway_aspect_3:4"},
            {"text": "9:16 (Сторис)", "callback_data": "runway_aspect_9:16"},
        ]
        keyboard = InlineKeyboardMarkup(
            inline_keyboard=[[InlineKeyboardButton(**btn)] for btn in aspect_buttons]
        )
        await callback.message.answer("Выберите соотношение сторон:", reply_markup=keyboard)
        await state.set_state(RunwayStates.choose_aspect_ratio)
    elif mode == "runway_mode_image":
        # Фото → Видео: получение изображения
        await callback.message.answer(
            "🖼️ Отправьте изображение для генерации видео:",
            reply_markup=cancel_keyboard()
        )
        await state.set_state(RunwayStates.get_image)
    elif mode == "runway_mode_extend":
        # Продлить видео: ввод task_id
        await callback.message.answer(
            "➕ Введите taskId оригинального видео Runway для продолжения:",
            reply_markup=cancel_keyboard()
        )
        await state.set_state(RunwayExtendStates.input_task_id)
    elif mode == "runway_mode_aleph":
        # Aleph: получение видео
        aleph_price = get_aleph_price()
        await callback.message.answer(
            f"🎞️ Runway Aleph — трансформация видео с помощью ИИ\n\n"
            f"💡 Этот режим позволяет изменять стиль, движение и содержание существующего видео по текстовому описанию.\n"
            f"Примеры: изменить время суток, добавить эффекты, трансформировать объекты в видео.\n\n"
            f"💰 Стоимость: {aleph_price}₽\n\n"
            f"📹 Отправьте публичную ссылку на видео или загрузите видеофайл:",
            reply_markup=cancel_keyboard()
        )
        await state.set_state(AlephStates.get_video)

@dp.callback_query(RunwayStates.choose_aspect_ratio)
async def runway_choose_aspect_ratio_callback(callback: CallbackQuery, state: FSMContext):
    await callback.answer()
    aspect_ratio = callback.data.split("_")[2]  # 16:9, 4:3, etc.
    await state.update_data(runway_aspect_ratio=aspect_ratio)
    
    # Выбор длительности
    duration_buttons = [
        {"text": "5 секунд", "callback_data": "runway_duration_5"},
        {"text": "10 секунд", "callback_data": "runway_duration_10"},
    ]
    keyboard = InlineKeyboardMarkup(
        inline_keyboard=[[InlineKeyboardButton(**btn)] for btn in duration_buttons]
    )
    await callback.message.answer("Выберите длительность видео:", reply_markup=keyboard)
    await state.set_state(RunwayStates.choose_duration)

@dp.callback_query(RunwayStates.choose_duration)
async def runway_choose_duration_callback(callback: CallbackQuery, state: FSMContext):
    await callback.answer()
    duration = callback.data.split("_")[2]  # 5 или 10
    await state.update_data(runway_duration=duration)
    
    # Выбор качества (1080p доступно только для 5 секунд)
    quality_buttons = [
        {"text": "720p", "callback_data": "runway_quality_720p"},
    ]
    if duration == "5":
        quality_buttons.append({"text": "1080p", "callback_data": "runway_quality_1080p"})
    
    keyboard = InlineKeyboardMarkup(
        inline_keyboard=[[InlineKeyboardButton(**btn)] for btn in quality_buttons]
    )
    await callback.message.answer("Выберите качество видео:", reply_markup=keyboard)
    await state.set_state(RunwayStates.choose_quality)

@dp.callback_query(RunwayStates.choose_quality)
async def runway_choose_quality_callback(callback: CallbackQuery, state: FSMContext):
    await callback.answer()
    quality = callback.data.split("_")[2]  # 720p или 1080p
    user_data = await state.get_data()
    duration = user_data.get("runway_duration")
    
    cost = get_runway_price(quality, duration)
    await state.update_data(runway_quality=quality, runway_cost=cost)
    
    await callback.message.answer(
        f"✅ Настройки выбраны:\n"
        f"▫️ Качество: {quality}\n"
        f"▫️ Длительность: {duration} секунд\n"
        f"▫️ Стоимость: {cost}₽\n\n"
        f"📝 Введите текстовый промпт для генерации видео (до 1800 символов):",
        reply_markup=cancel_keyboard()
    )
    await state.set_state(RunwayStates.input_prompt)

@dp.message(RunwayStates.get_image)
async def runway_get_image(message: Message, state: FSMContext):
    file_id = None
    if message.photo:
        file_id = message.photo[-1].file_id
    elif message.document and message.document.mime_type.startswith("image/"):
        file_id = message.document.file_id
    else:
        await message.answer(
            "⚠️ Пожалуйста, отправьте фото или изображение в формате PNG/JPG.",
            reply_markup=cancel_keyboard()
        )
        return
    
    file_info = await bot.get_file(file_id)
    file_url = f"https://api.telegram.org/file/bot{TELEGRAM_BOT_TOKEN}/{file_info.file_path}"
    await state.update_data(runway_image_url=file_url)
    
    # Выбор aspect ratio для image-to-video (опционально, но предлагаем выбрать)
    aspect_buttons = [
        {"text": "16:9 (Горизонтальное)", "callback_data": "runway_aspect_16:9"},
        {"text": "4:3 (Классическое)", "callback_data": "runway_aspect_4:3"},
        {"text": "1:1 (Квадратное)", "callback_data": "runway_aspect_1:1"},
        {"text": "3:4 (Вертикальное)", "callback_data": "runway_aspect_3:4"},
        {"text": "9:16 (Сторис)", "callback_data": "runway_aspect_9:16"},
    ]
    keyboard = InlineKeyboardMarkup(
        inline_keyboard=[[InlineKeyboardButton(**btn)] for btn in aspect_buttons]
    )
    await message.answer(
        "✅ Изображение получено!\n\n"
        "Выберите соотношение сторон для видео (изображение будет адаптировано под выбранный формат):",
        reply_markup=keyboard
    )
    await state.set_state(RunwayStates.choose_aspect_ratio)

@dp.message(RunwayStates.input_prompt)
async def runway_input_prompt(message: Message, state: FSMContext):
    user_data = await state.get_data()
    user_id = message.from_user.id
    username = message.from_user.username or "user"
    first_name = message.from_user.first_name or "Пользователь"
    
    prompt = (message.text or "").strip()
    if not prompt:
        await message.answer("⚠️ Пожалуйста, введите текстовый промпт.", reply_markup=cancel_keyboard())
        return
    
    if len(prompt) > 1800:
        await message.answer("⚠️ Промпт слишком длинный (максимум 1800 символов).", reply_markup=cancel_keyboard())
        return
    
    aspect_ratio = user_data.get("runway_aspect_ratio")
    image_url = user_data.get("runway_image_url")
    duration = user_data.get("runway_duration")
    quality = user_data.get("runway_quality")
    cost = user_data.get("runway_cost")
    
    if not duration or not quality:
        await message.answer("⚠️ Ошибка состояния. Попробуйте начать заново.", reply_markup=menu_button())
        await state.clear()
        return
    
    if not cost:
        cost = get_runway_price(quality, duration)
    
    balance = get_balance(user_id)
    if balance < cost:
        await message.answer(
            f"💸 Недостаточно средств.\n"
            f"Ваш баланс: {balance:.2f}₽, требуется: {cost:.2f}₽",
            reply_markup=menu_button()
        )
        await state.clear()
        return
    
    await message.answer("⏳ Генерация видео запущена, ждите...")
    
    try:
        callback_url = f"{CALLBACK_BASE_URL}/runway-callback"
        api_response = await create_runway_video_task(
            prompt=prompt,
            duration=duration,
            quality=quality,
            aspect_ratio=aspect_ratio,
            image_url=image_url,
            callback_url=callback_url,
        )
    except Exception as e:
        api_response = {"error": str(e)}
    
    error_msg = None
    if api_response is None:
        error_msg = "Ошибка подключения к API."
    elif "error" in api_response:
        error_msg = api_response.get("error", "Неизвестная ошибка API")
        status_code = api_response.get("status_code")
        if status_code:
            error_msg += f" (HTTP {status_code})"
    elif api_response.get("code") != 200:
        error_msg = api_response.get("msg", "Неизвестная ошибка API")
    elif "data" not in api_response or "taskId" not in api_response.get("data", {}):
        error_msg = "Неверный формат ответа API (отсутствует taskId)."
    
    from database import log_user_request
    
    if error_msg:
        log_user_request(
            user_telegram_id=user_id,
            user_username=username,
            user_first_name=first_name,
            request_type="runway",
            model_name=f"{quality}_{duration}s",
            prompt=prompt,
            image_urls=image_url,
            cost=cost,
            api_response=str(api_response),
        )
        await message.answer(f"❌ Ошибка при создании задачи:\n{error_msg}")
        await state.clear()
        return
    
    task_id = api_response["data"]["taskId"]
    save_task_chat(task_id, user_id, cost)
    log_user_request(
        user_telegram_id=user_id,
        user_username=username,
        user_first_name=first_name,
        request_type="runway",
        model_name=f"{quality}_{duration}s",
        prompt=prompt,
        image_urls=image_url,
        task_id=task_id,
        cost=cost,
        api_response=str(api_response),
    )
    
    await message.answer(
        f"✅ Задача Runway отправлена!\nTask ID: <code>{task_id}</code>\n"
        "После завершения видео придет в чат автоматически.\n"
        "Баланс будет списан только при успешной генерации.",
        parse_mode="HTML"
    )
    await state.clear()

# --- Runway Extend обработчики ---
@dp.message(RunwayExtendStates.input_task_id)
async def runway_extend_input_task_id(message: Message, state: FSMContext):
    task_id = (message.text or "").strip()
    if not task_id:
        await message.answer("⚠️ Пожалуйста, введите taskId.", reply_markup=cancel_keyboard())
        return
    
    await state.update_data(runway_extend_task_id=task_id)
    
    # Выбор качества
    quality_buttons = [
        {"text": "720p", "callback_data": "runway_extend_quality_720p"},
        {"text": "1080p", "callback_data": "runway_extend_quality_1080p"},
    ]
    keyboard = InlineKeyboardMarkup(
        inline_keyboard=[[InlineKeyboardButton(**btn)] for btn in quality_buttons]
    )
    await message.answer("Выберите качество для продолжения видео:", reply_markup=keyboard)
    await state.set_state(RunwayExtendStates.choose_quality)

@dp.callback_query(RunwayExtendStates.choose_quality)
async def runway_extend_choose_quality_callback(callback: CallbackQuery, state: FSMContext):
    await callback.answer()
    quality = callback.data.split("_")[3]  # 720p или 1080p
    cost = get_runway_extend_price(quality)
    await state.update_data(runway_extend_quality=quality, runway_extend_cost=cost)
    
    await callback.message.answer(
        f"✅ Качество выбрано: {quality}\n"
        f"▫️ Стоимость: {cost}₽\n\n"
        f"📝 Введите промпт для продолжения видео (до 1800 символов):",
        reply_markup=cancel_keyboard()
    )
    await state.set_state(RunwayExtendStates.input_prompt)

@dp.message(RunwayExtendStates.input_prompt)
async def runway_extend_input_prompt(message: Message, state: FSMContext):
    user_data = await state.get_data()
    user_id = message.from_user.id
    username = message.from_user.username or "user"
    first_name = message.from_user.first_name or "Пользователь"
    
    prompt = (message.text or "").strip()
    if not prompt:
        await message.answer("⚠️ Пожалуйста, введите промпт.", reply_markup=cancel_keyboard())
        return
    
    if len(prompt) > 1800:
        await message.answer("⚠️ Промпт слишком длинный (максимум 1800 символов).", reply_markup=cancel_keyboard())
        return
    
    task_id = user_data.get("runway_extend_task_id")
    quality = user_data.get("runway_extend_quality")
    cost = user_data.get("runway_extend_cost")
    
    if not task_id or not quality:
        await message.answer("⚠️ Ошибка состояния. Попробуйте начать заново.", reply_markup=menu_button())
        await state.clear()
        return
    
    if not cost:
        cost = get_runway_extend_price(quality)
    
    balance = get_balance(user_id)
    if balance < cost:
        await message.answer(
            f"💸 Недостаточно средств.\n"
            f"Ваш баланс: {balance:.2f}₽, требуется: {cost:.2f}₽",
            reply_markup=menu_button()
        )
        await state.clear()
        return
    
    await message.answer("⏳ Продление видео запущено, ждите...")
    
    try:
        callback_url = f"{CALLBACK_BASE_URL}/runway-callback"
        api_response = await extend_runway_video_task(
            task_id=task_id,
            prompt=prompt,
            quality=quality,
            callback_url=callback_url,
        )
    except Exception as e:
        api_response = {"error": str(e)}
    
    error_msg = None
    if api_response is None:
        error_msg = "Ошибка подключения к API."
    elif "error" in api_response:
        error_msg = api_response.get("error", "Неизвестная ошибка API")
        status_code = api_response.get("status_code")
        if status_code:
            error_msg += f" (HTTP {status_code})"
    elif api_response.get("code") != 200:
        error_msg = api_response.get("msg", "Неизвестная ошибка API")
    elif "data" not in api_response or "taskId" not in api_response.get("data", {}):
        error_msg = "Неверный формат ответа API (отсутствует taskId)."
    
    from database import log_user_request
    
    if error_msg:
        log_user_request(
            user_telegram_id=user_id,
            user_username=username,
            user_first_name=first_name,
            request_type="runway_extend",
            model_name=quality,
            prompt=prompt,
            image_urls=None,
            cost=cost,
            api_response=str(api_response),
        )
        await message.answer(f"❌ Ошибка при создании задачи:\n{error_msg}")
        await state.clear()
        return
    
    extend_task_id = api_response["data"]["taskId"]
    save_task_chat(extend_task_id, user_id, cost)
    log_user_request(
        user_telegram_id=user_id,
        user_username=username,
        user_first_name=first_name,
        request_type="runway_extend",
        model_name=quality,
        prompt=prompt,
        image_urls=None,
        task_id=extend_task_id,
        cost=cost,
        api_response=str(api_response),
    )
    
    await message.answer(
        f"✅ Задача продления Runway отправлена!\nTask ID: <code>{extend_task_id}</code>\n"
        "После завершения видео придет в чат автоматически.\n"
        "Баланс будет списан только при успешной генерации.",
        parse_mode="HTML"
    )
    await state.clear()

# --- Aleph обработчики ---
@dp.message(AlephStates.get_video)
async def aleph_get_video(message: Message, state: FSMContext):
    video_url = None
    
    if message.video:
        file_id = message.video.file_id
        file_info = await bot.get_file(file_id)
        video_url = f"https://api.telegram.org/file/bot{TELEGRAM_BOT_TOKEN}/{file_info.file_path}"
    elif message.text and message.text.startswith("http"):
        video_url = message.text.strip()
    elif message.document and message.document.mime_type.startswith("video/"):
        file_id = message.document.file_id
        file_info = await bot.get_file(file_id)
        video_url = f"https://api.telegram.org/file/bot{TELEGRAM_BOT_TOKEN}/{file_info.file_path}"
    else:
        await message.answer(
            "⚠️ Пожалуйста, отправьте публичную ссылку на видео или загрузите видеофайл.",
            reply_markup=cancel_keyboard()
        )
        return
    
    await state.update_data(aleph_video_url=video_url)
    aleph_price = get_aleph_price()
    await message.answer(
        f"✅ Видео получено!\n"
        f"💰 Стоимость трансформации: {aleph_price}₽\n\n"
        f"📝 Введите текстовый промпт для трансформации видео (до 1800 символов):\n\n"
        f"💡 Примеры: \"изменить время суток на закат\", \"добавить эффект дождя\", \"трансформировать объекты\"",
        reply_markup=cancel_keyboard()
    )
    await state.set_state(AlephStates.input_prompt)

@dp.message(AlephStates.input_prompt)
async def aleph_input_prompt(message: Message, state: FSMContext):
    user_data = await state.get_data()
    user_id = message.from_user.id
    username = message.from_user.username or "user"
    first_name = message.from_user.first_name or "Пользователь"
    
    prompt = (message.text or "").strip()
    if not prompt:
        await message.answer("⚠️ Пожалуйста, введите промпт для трансформации.", reply_markup=cancel_keyboard())
        return
    
    if len(prompt) > 1800:
        await message.answer("⚠️ Промпт слишком длинный (максимум 1800 символов).", reply_markup=cancel_keyboard())
        return
    
    video_url = user_data.get("aleph_video_url")
    if not video_url:
        await message.answer("⚠️ Ошибка состояния. Попробуйте начать заново.", reply_markup=menu_button())
        await state.clear()
        return
    
    cost = get_aleph_price()
    balance = get_balance(user_id)
    if balance < cost:
        await message.answer(
            f"💸 Недостаточно средств.\n"
            f"Ваш баланс: {balance:.2f}₽, требуется: {cost:.2f}₽",
            reply_markup=menu_button()
        )
        await state.clear()
        return
    
    await message.answer("⏳ Трансформация видео запущена, ждите...")
    
    try:
        callback_url = f"{CALLBACK_BASE_URL}/aleph-callback"
        api_response = await create_aleph_video_task(
            prompt=prompt,
            video_url=video_url,
            callback_url=callback_url,
        )
    except Exception as e:
        api_response = {"error": str(e)}
    
    error_msg = None
    if api_response is None:
        error_msg = "Ошибка подключения к API."
    elif "error" in api_response:
        error_msg = api_response.get("error", "Неизвестная ошибка API")
        status_code = api_response.get("status_code")
        if status_code:
            error_msg += f" (HTTP {status_code})"
    elif api_response.get("code") != 200:
        error_msg = api_response.get("msg", "Неизвестная ошибка API")
    elif "data" not in api_response or "taskId" not in api_response.get("data", {}):
        error_msg = "Неверный формат ответа API (отсутствует taskId)."
    
    from database import log_user_request
    
    if error_msg:
        log_user_request(
            user_telegram_id=user_id,
            user_username=username,
            user_first_name=first_name,
            request_type="runway_aleph",
            model_name="aleph",
            prompt=prompt,
            image_urls=video_url,
            cost=cost,
            api_response=str(api_response),
        )
        await message.answer(f"❌ Ошибка при создании задачи:\n{error_msg}")
        await state.clear()
        return
    
    task_id = api_response["data"]["taskId"]
    save_task_chat(task_id, user_id, cost)
    log_user_request(
        user_telegram_id=user_id,
        user_username=username,
        user_first_name=first_name,
        request_type="runway_aleph",
        model_name="aleph",
        prompt=prompt,
        image_urls=video_url,
        task_id=task_id,
        cost=cost,
        api_response=str(api_response),
    )
    
    await message.answer(
        f"✅ Задача Runway Aleph отправлена!\nTask ID: <code>{task_id}</code>\n"
        "После завершения трансформации видео придет в чат автоматически.\n"
        "Баланс будет списан только при успешной генерации.",
        parse_mode="HTML"
    )
    await state.clear()

# --- Kling 2.6 Motion Control обработчики ---
@dp.callback_query(KlingMotionControlStates.choose_mode)
async def kling_motion_choose_mode_callback(callback: CallbackQuery, state: FSMContext):
    await callback.answer()
    mode = callback.data.split("_")[2]  # 720p или 1080p
    await state.update_data(kling_mode=mode)
    
    cost = await get_kling_motion_control_cost(mode)
    await state.update_data(kling_cost=cost)
    
    await callback.message.answer(
        f"✅ Режим выбран: {mode}\n"
        f"💰 Стоимость: {cost}₽\n\n"
        f"🖼️ Отправьте изображение персонажа (JPEG, PNG, WEBP, макс 10MB):\n"
        f"Изображение должно четко показывать голову, плечи и торс персонажа.",
        reply_markup=cancel_keyboard()
    )
    await state.set_state(KlingMotionControlStates.get_image)

@dp.message(KlingMotionControlStates.get_image)
async def kling_motion_get_image(message: Message, state: FSMContext):
    file_id = None
    
    if message.photo:
        file_id = message.photo[-1].file_id
    elif message.document and message.document.mime_type.startswith("image/"):
        file_id = message.document.file_id
    else:
        await message.answer(
            "⚠️ Пожалуйста, отправьте фото или изображение в формате JPEG/PNG/WEBP.",
            reply_markup=cancel_keyboard()
        )
        return
    
    file_info = await bot.get_file(file_id)
    file_url = f"https://api.telegram.org/file/bot{TELEGRAM_BOT_TOKEN}/{file_info.file_path}"
    await state.update_data(kling_image_url=file_url)
    
    await message.answer(
        "✅ Изображение получено!\n\n"
        "📹 Теперь отправьте видео-референс (MP4, QUICKTIME, X-MATROSKA, макс 100MB, 3-30 секунд):\n"
        "Видео должно четко показывать голову, плечи и торс персонажа с нужными движениями.",
        reply_markup=cancel_keyboard()
    )
    await state.set_state(KlingMotionControlStates.get_video)

@dp.message(KlingMotionControlStates.get_video)
async def kling_motion_get_video(message: Message, state: FSMContext):
    video_url = None
    
    if message.video:
        file_id = message.video.file_id
        file_info = await bot.get_file(file_id)
        video_url = f"https://api.telegram.org/file/bot{TELEGRAM_BOT_TOKEN}/{file_info.file_path}"
    elif message.text:
        # Проверяем, является ли текст URL
        text = message.text.strip()
        if text.startswith(("http://", "https://")):
            video_url = text
        else:
            await message.answer(
                "⚠️ Пожалуйста, отправьте видеофайл или публичную ссылку на видео.",
                reply_markup=cancel_keyboard()
            )
            return
    else:
        await message.answer(
            "⚠️ Пожалуйста, отправьте видеофайл или публичную ссылку на видео.",
            reply_markup=cancel_keyboard()
        )
        return
    
    await state.update_data(kling_video_url=video_url)
    
    await message.answer(
        "✅ Видео-референс получен!\n\n"
        "📝 Теперь введите текстовый промпт для описания сцены (до 2500 символов):\n"
        "Промпт описывает детали сцены, фон и окружение.",
        reply_markup=cancel_keyboard()
    )
    await state.set_state(KlingMotionControlStates.input_prompt)

@dp.message(KlingMotionControlStates.input_prompt)
async def kling_motion_input_prompt(message: Message, state: FSMContext):
    user_data = await state.get_data()
    user_id = message.from_user.id
    username = message.from_user.username or "user"
    first_name = message.from_user.first_name or "Пользователь"
    
    prompt = (message.text or "").strip()
    if not prompt:
        await message.answer("⚠️ Пожалуйста, введите текстовый промпт.", reply_markup=cancel_keyboard())
        return
    
    if len(prompt) > 2500:
        await message.answer("⚠️ Промпт слишком длинный (максимум 2500 символов).", reply_markup=cancel_keyboard())
        return
    
    mode = user_data.get("kling_mode")
    cost = user_data.get("kling_cost")
    image_url = user_data.get("kling_image_url")
    video_url = user_data.get("kling_video_url")
    
    # Валидация данных
    if not image_url:
        await message.answer("❌ Ошибка: изображение не найдено. Начните заново.", reply_markup=cancel_keyboard())
        await state.clear()
        return
    
    if not video_url:
        await message.answer("❌ Ошибка: видео-референс не найден. Начните заново.", reply_markup=cancel_keyboard())
        await state.clear()
        return
    
    if get_balance(user_id) < cost:
        await message.answer(
            f"💸 Недостаточно средств. Ваш баланс: {get_balance(user_id):.2f}₽\n"
            f"Требуется: {cost}₽",
            reply_markup=cancel_keyboard()
        )
        await state.clear()
        return
    
    await message.answer("⏳ Генерация видео запущена, ждите...")
    
    try:
        # Логирование параметров
        logging.info(f"[KLING] Параметры генерации:")
        logging.info(f"  - mode: {mode}")
        logging.info(f"  - prompt length: {len(prompt)}")
        logging.info(f"  - image_url: {image_url[:50]}...")
        logging.info(f"  - video_url: {video_url[:50]}...")
        logging.info(f"  - cost: {cost}")
        
        # Убеждаемся, что URL не пустые
        if not image_url or not image_url.strip():
            await message.answer("❌ Ошибка: URL изображения пустой")
            await state.clear()
            return
        
        if not video_url or not video_url.strip():
            await message.answer("❌ Ошибка: URL видео пустой")
            await state.clear()
            return
        
        api_response = await create_kling_motion_control_task(
            prompt=prompt,
            input_urls=[image_url],
            video_urls=[video_url],
            mode=mode,
            character_orientation="image",  # "image" для макс 10с или "video" для макс 30с
            callback_url=f"{CALLBACK_BASE_URL}/kling-motion-control-callback",
        )
        
        # Проверка ответа API с детальным логированием
        error_msg = None
        logging.info(f"[KLING] Получен ответ API: {api_response}")
        
        if api_response is None:
            error_msg = "Ошибка подключения к API"
        elif "error" in api_response:
            error_msg = api_response.get("error", "Неизвестная ошибка")
            if "status_code" in api_response:
                error_msg += f" (HTTP {api_response['status_code']})"
            if "response_text" in api_response:
                error_msg += f"\n{api_response['response_text']}"
            if "error_details" in api_response:
                error_details = api_response["error_details"]
                if isinstance(error_details, dict):
                    error_msg += f"\nДетали: {json.dumps(error_details, indent=2, ensure_ascii=False)}"
        elif api_response.get("code") != 200:
            error_msg = api_response.get("message") or api_response.get("msg") or "Неизвестная ошибка API"
            if api_response.get("code"):
                error_msg = f"Код {api_response['code']}: {error_msg}"
            # Добавляем полную информацию об ошибке для диагностики
            if "response_text" in api_response:
                error_msg += f"\nОтвет сервера: {api_response['response_text']}"
        elif "data" not in api_response or "taskId" not in api_response.get("data", {}):
            error_msg = "Неверный формат ответа API (отсутствует taskId)"
            logging.error(f"[KLING] Неверный формат ответа: {api_response}")
        
        if error_msg:
            # Логируем ошибку
            from database import log_user_request
            log_user_request(
                user_telegram_id=user_id,
                user_username=username,
                user_first_name=first_name,
                request_type="kling_motion_control",
                model_name=f"kling-2.6-motion-control-{mode}",
                prompt=prompt,
                image_urls=image_url,
                cost=cost,
                api_response=str(api_response)
            )
            await message.answer(f"❌ Ошибка при создании задачи:\n{error_msg}")
            await state.clear()
            return
        
        task_id = api_response["data"]["taskId"]
        save_task_chat(task_id, user_id, cost)
        
        # Логируем успешный запрос
        from database import log_user_request
        log_user_request(
            user_telegram_id=user_id,
            user_username=username,
            user_first_name=first_name,
            request_type="kling_motion_control",
            model_name=f"kling-2.6-motion-control-{mode}",
            prompt=prompt,
            image_urls=image_url,
            task_id=task_id,
            cost=cost,
            api_response=str(api_response)
        )
        
        await message.answer(
            f"✅ Задача отправлена!\nTask ID: <code>{task_id}</code>\n"
            "После завершения видео придет в чат автоматически.\n"
            "Баланс будет списан только при успешной генерации.",
            parse_mode="HTML"
        )
    except Exception as e:
        logging.error(f"[KLING] Исключение при создании задачи: {e}", exc_info=True)
        await message.answer(f"❌ Ошибка при создании задачи:\n{e}")
    
    await state.clear()

# --- Grok Imagine: получить изображение ---
@dp.message(GrokStates.get_image)
async def get_grok_image(message: Message, state: FSMContext):
    file_id = None
    if message.photo:
        file_id = message.photo[-1].file_id
    elif message.document and message.document.mime_type.startswith("image/"):
        file_id = message.document.file_id
    else:
        await message.answer(
            "⚠️ Пожалуйста, отправьте фото или изображение в формате PNG/JPG.",
            reply_markup=cancel_keyboard()
        )
        return

    file_info = await bot.get_file(file_id)
    file_url = f"https://api.telegram.org/file/bot{TELEGRAM_BOT_TOKEN}/{file_info.file_path}"
    await state.update_data(image_urls=[file_url])
    await message.answer(
        "Фото принято! Введите текстовый промпт для анимации (опционально):",
        reply_markup=cancel_keyboard()
    )
    await state.set_state(GrokStates.input_prompt)

# --- Grok Imagine: ввод промпта и запуск задачи ---
@dp.message(GrokStates.input_prompt)
async def input_grok_prompt_handler(message: Message, state: FSMContext):
    user_data = await state.get_data()
    user_id = message.from_user.id
    username = message.from_user.username or "user"
    first_name = message.from_user.first_name or "Пользователь"
    prompt = message.text or ""

    image_urls = user_data.get("image_urls")
    model_name = user_data.get("grok_model", "grok-imagine/image-to-video")
    cost = get_grok_imagine_cost()
    balance = get_balance(user_id)

    if balance < cost:
        await message.answer(
            f"💸 Недостаточно средств для генерации Grok Imagine.\n"
            f"Ваш баланс: {balance:.2f}₽, требуется: {cost:.2f}₽",
            reply_markup=menu_button()
        )
        await state.clear()
        return

    # Создаём задачу
    try:
        callback_url = f"{CALLBACK_BASE_URL}/grok-imagine-callback"
        api_response = await create_grok_imagine_video_task(
            image_urls=image_urls,
            prompt=prompt,
            callback_url=callback_url,
            model=model_name
        )
    except Exception as e:
        api_response = {"error": str(e)}

    # Обработка ответа
    error_msg = None
    if api_response is None:
        error_msg = "Ошибка подключения к API"
    elif "error" in api_response:
        error_msg = api_response.get("error", "Неизвестная ошибка")
        if "status_code" in api_response:
            error_msg += f" (HTTP {api_response['status_code']})"
    elif api_response.get("code") != 200:
        error_msg = api_response.get("msg", "Неизвестная ошибка API")
    elif "data" not in api_response or "taskId" not in api_response.get("data", {}):
        error_msg = "Неверный формат ответа API (отсутствует taskId)"

    from database import log_user_request
    if error_msg:
        log_user_request(
            user_telegram_id=user_id,
            user_username=username,
            user_first_name=first_name,
            request_type="grok_imagine",
            model_name=model_name,
            prompt=prompt,
            image_urls=",".join(image_urls) if image_urls else None,
            cost=cost,
            api_response=str(api_response)
        )
        await message.answer(f"❌ Ошибка при создании задачи:\n{error_msg}")
        await state.clear()
        return

    task_id = api_response["data"]["taskId"]
    if not deduct_balance(user_id, cost):
        logging.error(f"[Grok Imagine] Не удалось списать {cost}₽ у пользователя {user_id} после успешного создания задачи")
        await message.answer(
            "⚠️ Ошибка при списании средств. Пожалуйста, свяжитесь с поддержкой.",
            reply_markup=menu_button()
        )
        await state.clear()
        return

    save_task_chat(task_id, user_id, cost)
    log_user_request(
        user_telegram_id=user_id,
        user_username=username,
        user_first_name=first_name,
        request_type="grok_imagine",
        model_name=model_name,
        prompt=prompt,
        image_urls=",".join(image_urls) if image_urls else None,
        task_id=task_id,
        cost=cost,
        api_response=str(api_response)
    )
    await message.answer(
        f"✅ Задача отправлена!\nTask ID: <code>{task_id}</code>\n"
        "После завершения видео придет в чат автоматически.\n"
        "Баланс будет списан только при успешной генерации.",
        parse_mode="HTML"
    )
    await state.clear()

# --- Шаг 3: Выбор типа генерации ---
@dp.callback_query(Veo3States.choose_task)
async def choose_task_callback(callback: CallbackQuery, state: FSMContext):
    await callback.answer()
    selected_task = callback.data  # "veo_task_text", "veo_task_image" или "veo_task_first_last"

    if selected_task == "veo_task_text":
        task_type = "text"
        generation_type = "TEXT_2_VIDEO"
        await state.update_data(task=task_type, generation_type=generation_type)
        
        # Для текста сразу выбираем соотношение сторон
        aspect_buttons = [
            {"text": "🖥️ Landscape (16:9)", "callback_data": "veo_aspect_16:9"},
            {"text": "📱 Portrait (9:16)", "callback_data": "veo_aspect_9:16"},
            {"text": "🔄 Auto (автоматически)", "callback_data": "veo_aspect_Auto"}
        ]
        keyboard = InlineKeyboardMarkup(
            inline_keyboard=[[InlineKeyboardButton(**btn)] for btn in aspect_buttons]
        )
        await callback.message.answer("Выберите соотношение сторон:", reply_markup=keyboard)
        await state.set_state(Veo3States.choose_aspect_ratio)
        
    elif selected_task == "veo_task_image":
        task_type = "image"
        generation_type = None  # Для одного изображения API сам определит тип
        await state.update_data(task=task_type, generation_type=generation_type)
        
        # Для одного фото сразу выбираем соотношение сторон
        aspect_buttons = [
            {"text": "🖥️ Landscape (16:9)", "callback_data": "veo_aspect_16:9"},
            {"text": "📱 Portrait (9:16)", "callback_data": "veo_aspect_9:16"},
            {"text": "🔄 Auto (автоматически)", "callback_data": "veo_aspect_Auto"}
        ]
        keyboard = InlineKeyboardMarkup(
            inline_keyboard=[[InlineKeyboardButton(**btn)] for btn in aspect_buttons]
        )
        await callback.message.answer("Выберите соотношение сторон:", reply_markup=keyboard)
        await state.set_state(Veo3States.choose_aspect_ratio)
        
    else:  # veo_task_first_last
        task_type = "first_last"
        generation_type = "FIRST_AND_LAST_FRAMES_2_VIDEO"  # Для двух изображений обязательно указываем
        await state.update_data(task=task_type, generation_type=generation_type)
        
        # Для двух кадров сразу выбираем соотношение сторон
        aspect_buttons = [
            {"text": "🖥️ Landscape (16:9)", "callback_data": "veo_aspect_16:9"},
            {"text": "📱 Portrait (9:16)", "callback_data": "veo_aspect_9:16"},
            {"text": "🔄 Auto (автоматически)", "callback_data": "veo_aspect_Auto"}
        ]
        keyboard = InlineKeyboardMarkup(
            inline_keyboard=[[InlineKeyboardButton(**btn)] for btn in aspect_buttons]
        )
        await callback.message.answer("Выберите соотношение сторон:", reply_markup=keyboard)
        await state.set_state(Veo3States.choose_aspect_ratio)

# --- Обработчик выбора соотношения сторон ---
@dp.callback_query(Veo3States.choose_aspect_ratio)
async def choose_veo_aspect_ratio_callback(callback: CallbackQuery, state: FSMContext):
    await callback.answer()
    aspect_data = callback.data  # "veo_aspect_16:9", "veo_aspect_9:16" или "veo_aspect_Auto"
    aspect_ratio = aspect_data.split("_")[2]  # "16:9", "9:16" или "Auto"
    
    await state.update_data(aspect_ratio=aspect_ratio)
    print(f"🔍 [VEO3] Выбрано соотношение сторон: {aspect_ratio}")
    
    user_data = await state.get_data()
    
    task_type = user_data.get("task")
    
    if task_type == "text":
        # Для текстовой генерации сразу запрашиваем промпт
        await callback.message.answer(
            "Введите текстовый промпт для генерации видео (желательно на английском. Текст озвучки который нужно добавить, поместите в двойные кавычки)",
            reply_markup=cancel_keyboard()
        )
        await state.set_state(Veo3States.input_prompt)
    elif task_type == "image":
        # Для генерации из одного изображения
        await callback.message.answer(
            "🖼️ Отправьте картинку для генерации видео:",
            reply_markup=cancel_keyboard()
        )
        await state.set_state(Veo3States.get_image)
    else:  # first_last
        # Для генерации из первого и последнего кадра
        await callback.message.answer(
            "🎬 Отправьте первый кадр (начальное изображение):",
            reply_markup=cancel_keyboard()
        )
        await state.set_state(Veo3States.get_first_image)

# --- Шаг 4: Получение картинки ---
@dp.message(Veo3States.get_image)
async def get_veo_image(message: Message, state: FSMContext):
    logging.info(f"[get_veo_image] Received message: {message}")

    file_id = None

    if message.photo:
        file_id = message.photo[-1].file_id
    elif message.document and message.document.mime_type.startswith("image/"):
        file_id = message.document.file_id
    else:
        logging.warning(f"[get_veo_image] Message does not contain photo or image document: {message.text}")
        await message.answer(
            "⚠️ Пожалуйста, отправьте фото или изображение в формате PNG/JPG.",
            reply_markup=cancel_keyboard()
        )
        return

    file_info = await bot.get_file(file_id)
    file_url = f"https://api.telegram.org/file/bot{TELEGRAM_BOT_TOKEN}/{file_info.file_path}"
    await state.update_data(image_urls=[file_url])
    logging.info(f"[get_veo_image] Saved image URL: {file_url}")

    await message.answer(
        "Фото принято! Пожалуйста, введите текстовый промпт для видео:",
        reply_markup=cancel_keyboard()  # кнопка отмены
    )
    await state.set_state(Veo3States.input_prompt)

# --- Получение первого кадра для FIRST_AND_LAST_FRAMES_2_VIDEO ---
@dp.message(Veo3States.get_first_image)
async def get_veo_first_image(message: Message, state: FSMContext):
    logging.info(f"[get_veo_first_image] Received message: {message}")

    file_id = None

    if message.photo:
        file_id = message.photo[-1].file_id
    elif message.document and message.document.mime_type.startswith("image/"):
        file_id = message.document.file_id
    else:
        logging.warning(f"[get_veo_first_image] Message does not contain photo or image document: {message.text}")
        await message.answer(
            "⚠️ Пожалуйста, отправьте фото или изображение в формате PNG/JPG.",
            reply_markup=cancel_keyboard()
        )
        return

    file_info = await bot.get_file(file_id)
    file_url = f"https://api.telegram.org/file/bot{TELEGRAM_BOT_TOKEN}/{file_info.file_path}"
    await state.update_data(first_image_url=file_url)
    logging.info(f"[get_veo_first_image] Saved first image URL: {file_url}")

    await message.answer(
        "✅ Первый кадр принят! Теперь отправьте последний кадр (конечное изображение):",
        reply_markup=cancel_keyboard()
    )
    await state.set_state(Veo3States.get_last_image)

# --- Получение последнего кадра для FIRST_AND_LAST_FRAMES_2_VIDEO ---
@dp.message(Veo3States.get_last_image)
async def get_veo_last_image(message: Message, state: FSMContext):
    logging.info(f"[get_veo_last_image] Received message: {message}")

    file_id = None

    if message.photo:
        file_id = message.photo[-1].file_id
    elif message.document and message.document.mime_type.startswith("image/"):
        file_id = message.document.file_id
    else:
        logging.warning(f"[get_veo_last_image] Message does not contain photo or image document: {message.text}")
        await message.answer(
            "⚠️ Пожалуйста, отправьте фото или изображение в формате PNG/JPG.",
            reply_markup=cancel_keyboard()
        )
        return

    file_info = await bot.get_file(file_id)
    file_url = f"https://api.telegram.org/file/bot{TELEGRAM_BOT_TOKEN}/{file_info.file_path}"
    
    user_data = await state.get_data()
    first_image_url = user_data.get("first_image_url")
    
    # Сохраняем оба изображения в image_urls
    await state.update_data(image_urls=[first_image_url, file_url])
    logging.info(f"[get_veo_last_image] Saved both image URLs: {first_image_url}, {file_url}")

    await message.answer(
        "✅ Оба кадра приняты! Теперь введите текстовый промпт для генерации видео (опционально, можно описать переход между кадрами):",
        reply_markup=cancel_keyboard()
    )
    await state.set_state(Veo3States.input_prompt)


@dp.callback_query(F.data == "cancel")
async def cancel_callback(callback: CallbackQuery, state: FSMContext):
    """Обработчик отмены операции"""
    # Отвечаем на callback сразу, чтобы избежать таймаута
    await callback.answer()
    
    user_id = callback.from_user.id
    user_tag = get_user_tag(user_id)
    
    await state.clear()  # сброс состояния FSM
    
    # Определяем, какое меню показать пользователю
    if user_tag == "markets":
        # Для markets пользователей показываем специальное меню
        await callback.message.edit_text(
            "❌ Генерация отменена.\n\n"
            "Выберите действие:",
            reply_markup=main_menu_keyboard(user_tag="markets"),
            parse_mode="HTML"
        )
    else:
        # Для обычных пользователей показываем стандартное меню
        await callback.message.edit_text("❌ Генерация отменена.", reply_markup=None)
        await callback.message.answer("Главное меню:", reply_markup=main_menu_keyboard())
    
    await callback.answer()
    
# --- Шаг 5: Ввод текста и вызов API ---
@dp.message(Veo3States.input_prompt)
async def input_prompt_handler(message: Message, state: FSMContext):
    user_data = await state.get_data()
    selected_model = user_data.get("selected_model")      # veo3 или veo3_fast
    task_type = user_data.get("task")                     # "text", "image" или "first_last"
    image_urls = user_data.get("image_urls", None)
    aspect_ratio = user_data.get("aspect_ratio", "16:9")  # Получаем выбранное соотношение сторон
    generation_type = user_data.get("generation_type")     # Получаем тип генерации
    prompt = message.text
    user_id = message.from_user.id
    username = message.from_user.username or "user"
    first_name = message.from_user.first_name or "Пользователь"

    # Расчет стоимости
    variant = user_data.get("veo_variant")
    cost = await get_generation_cost("Veo 3", variant)
    if get_balance(user_id) < cost:
        await message.answer(f"💸 Недостаточно средств. Ваш баланс: {get_balance(user_id):.2f} токенов")
        await state.clear()
        return

    await message.answer("⏳ Генерация видео запущена, ждите...")

    try:
        # Автоматически определяем generation_type только если не указан и есть два изображения
        if image_urls and len(image_urls) == 2 and not generation_type:
            # Для двух изображений всегда используем FIRST_AND_LAST_FRAMES_2_VIDEO
            generation_type = "FIRST_AND_LAST_FRAMES_2_VIDEO"
            await state.update_data(generation_type=generation_type)
        # Для одного изображения generation_type остается None - API сам определит
        
        # Логирование для отладки
        print(f"🔍 [VEO3] Параметры генерации:")
        print(f"  - model: {selected_model}")
        print(f"  - aspect_ratio: {aspect_ratio}")
        print(f"  - generation_type: {generation_type}")
        print(f"  - task_type: {task_type}")
        print(f"  - image_urls count: {len(image_urls) if image_urls else 0}")
        print(f"  - prompt: {prompt[:50]}...")
        
        # Формируем параметры для вызова API
        api_params = {
            "model": selected_model,
            "prompt": prompt,
            "aspect_ratio": aspect_ratio,
            "callback_url": f"{CALLBACK_BASE_URL}/veo3-callback",
        }
        
        if image_urls:
            api_params["image_urls"] = image_urls
        
        # Передаем generation_type только если он указан (не None)
        if generation_type:
            api_params["generation_type"] = generation_type
        
        print(f"🔍 [VEO3] Вызов create_video_task с параметрами: {list(api_params.keys())}")
        print(f"🔍 [VEO3] Параметры: {api_params}")
        
        # Проверяем, что функция поддерживает generation_type
        import inspect
        sig = inspect.signature(create_video_task)
        print(f"🔍 [VEO3] Сигнатура функции create_video_task: {list(sig.parameters.keys())}")
        
        try:
            api_response = await create_video_task(**api_params)
        except TypeError as e:
            error_msg = str(e)
            if "generation_type" in error_msg:
                # Если проблема с generation_type, пробуем вызвать без него
                print(f"⚠️ [VEO3] Ошибка с generation_type, пробуем без него: {e}")
                api_params.pop("generation_type", None)
                api_response = await create_video_task(**api_params)
            else:
                raise
        print(f"🔍 [VEO3] Ответ API: {api_response}")

        # Проверяем наличие ошибок
        if api_response is None:
            error_msg = "Ошибка подключения к API"
        elif "error" in api_response:
            error_msg = api_response.get("error", "Неизвестная ошибка")
            if "status_code" in api_response:
                error_msg += f" (HTTP {api_response['status_code']})"
        elif api_response.get("code") != 200:
            error_msg = api_response.get("msg", "Неизвестная ошибка API")
        elif "data" not in api_response or "taskId" not in api_response.get("data", {}):
            error_msg = "Неверный формат ответа API (отсутствует taskId)"
        else:
            error_msg = None
        
        if error_msg:
            # Логируем ошибку
            from database import log_user_request
            log_user_request(
                user_telegram_id=user_id,
                user_username=username,
                user_first_name=first_name,
                request_type="veo3",
                model_name=selected_model,
            prompt=prompt,
                image_urls=",".join(image_urls) if image_urls else None,
                cost=cost,
                api_response=str(api_response)
            )
            
            await message.answer(f"❌ Ошибка при создании задачи:\n{error_msg}\n\nПроверьте логи для деталей.")
            await state.clear()
            return

        task_id = api_response["data"]["taskId"]
        save_task_chat(task_id, user_id, cost)
        
        # Логируем успешный запрос
        from database import log_user_request
        log_user_request(
            user_telegram_id=user_id,
            user_username=username,
            user_first_name=first_name,
            request_type="veo3",
            model_name=selected_model,
            prompt=prompt,
            image_urls=",".join(image_urls) if image_urls else None,
            task_id=task_id,
            cost=cost,
            api_response=str(api_response)
        )
        
        # НЕ списываем баланс сразу - только после получения видео

        await message.answer(
            f"✅ Задача отправлена!\nTask ID: <code>{task_id}</code>\n"
            "После завершения видео придет в чат автоматически.\n"
            "Баланс будет списан только при успешной генерации."
        )

    except Exception as e:
        await message.answer(f"❌ Ошибка при создании задачи:\n{e}")

    await state.clear()

# --- Обработчик ввода промпта для SORA 2 ---
@dp.message(Sora2States.input_prompt)
async def input_sora2_prompt_handler(message: Message, state: FSMContext):
    user_data = await state.get_data()
    user_id = message.from_user.id
    username = message.from_user.username or "user"
    first_name = message.from_user.first_name or "Пользователь"
    prompt = message.text
    
    # Определяем тип модели SORA 2
    sora_model = user_data.get("sora_model")  # "sora2_text" или "sora2_image"
    sora_pro_model = user_data.get("sora_pro_model")  # "sora2_pro_text" или "sora2_pro_image"
    
    # Отладочная информация
    logging.info(f"[input_sora2_prompt_handler] user_data: {user_data}")
    logging.info(f"[input_sora2_prompt_handler] sora_model: {sora_model}, sora_pro_model: {sora_pro_model}")
    
    if sora_pro_model:
        # SORA 2 Pro - рассчитываем стоимость
        duration = user_data.get("duration")  # "10" или "15"
        quality = user_data.get("quality")    # "standard" или "high"
        aspect_ratio = user_data.get("aspect_ratio")  # "portrait" или "landscape"
        task_type = user_data.get("task_type")  # "text" или "image"
        
        print(f"SORA2 Pro: duration={duration}, quality={quality}")
        cost = await get_sora2_pro_cost(duration, quality)
        print(f"SORA2 Pro: рассчитанная стоимость = {cost}")
        # Правильные названия моделей для KIE API
        if quality == "standard":
            model_name = "sora-2-pro-text-to-video" if task_type == "text" else "sora-2-pro-image-to-video"
        else:  # high
            model_name = "sora-2-pro-text-to-video" if task_type == "text" else "sora-2-pro-image-to-video"
        
        # Параметры для API
        api_params = {
            "aspect_ratio": aspect_ratio,
            "n_frames": duration,
            "size": quality,
            "remove_watermark": True
        }
    elif sora_model:
        # Базовые SORA 2 модели
        cost = 54  # 40₽ × 1.35 наценка
        if sora_model == "sora2_text":
            model_name = "sora-2-text-to-video"
            api_params = {
                "aspect_ratio": "landscape",
                "remove_watermark": True
            }
        else:  # sora2_image
            model_name = "sora-2-image-to-video"
            api_params = {
                "aspect_ratio": "landscape", 
                "remove_watermark": True
            }
    else:
        # Если ни одна модель не определена - ошибка
        logging.error(f"[input_sora2_prompt_handler] Не определена модель SORA 2: sora_model={sora_model}, sora_pro_model={sora_pro_model}")
        await message.answer("❌ Ошибка: не определена модель SORA 2. Попробуйте начать генерацию заново.")
        await state.clear()
        return
    
    # Отладочная информация перед отправкой
    logging.info(f"[input_sora2_prompt_handler] model_name: {model_name}")
    logging.info(f"[input_sora2_prompt_handler] cost: {cost}")
    logging.info(f"[input_sora2_prompt_handler] api_params: {api_params}")
    
    # Проверка баланса
    if get_balance(user_id) < cost:
        await message.answer(f"💸 Недостаточно средств. Ваш баланс: {get_balance(user_id):.2f} токенов")
        await state.clear()
        return

    await message.answer("⏳ Генерация видео запущена, ждите...")

    try:
        image_urls = user_data.get("image_urls")
        api_response = await create_sora2_video_task(
            model=model_name,
            prompt=prompt,
            image_urls=image_urls,
            **api_params,
            callback_url=f"{CALLBACK_BASE_URL}/sora2-callback",
        )

        if api_response is None or api_response.get("code") != 200:
            # Логируем ошибку
            from database import log_user_request
            log_user_request(
                user_telegram_id=user_id,
                user_username=username,
                user_first_name=first_name,
                request_type="sora2",
                model_name=model_name,
                prompt=prompt,
                cost=cost,
                api_response=str(api_response)
            )
            
            await message.answer(f"❌ Не удалось создать задачу:\n<pre>{api_response}</pre>")
            await state.clear()
            return

        task_id = api_response["data"]["taskId"]
        save_task_chat(task_id, user_id, cost)
        
        # Логируем успешный запрос
        from database import log_user_request
        log_user_request(
            user_telegram_id=user_id,
            user_username=username,
            user_first_name=first_name,
            request_type="sora2",
            model_name=model_name,
            prompt=prompt,
            task_id=task_id,
            cost=cost,
            api_response=str(api_response)
        )
        
        # НЕ списываем баланс сразу - только после получения видео

        await message.answer(
            f"✅ Задача отправлена!\nTask ID: <code>{task_id}</code>\n"
            "После завершения видео придет в чат автоматически.\n"
            "Баланс будет списан только при успешной генерации."
        )

    except Exception as e:
        await message.answer(f"❌ Ошибка при создании задачи:\n{e}")

    await state.clear()

# --- Обработчики партнерской программы ---
@dp.callback_query(F.data == "partner_withdrawal")
async def partner_withdrawal_callback(callback: CallbackQuery, state: FSMContext):
    user_id = callback.from_user.id
    stats = get_partner_stats(user_id)
    
    if stats['available_for_withdrawal'] < 500:
        await callback.message.edit_text(
            f"❌ Минимальная сумма для вывода: 500₽\n"
            f"Доступно к выводу: {stats['available_for_withdrawal']:.2f}₽",
            reply_markup=menu_button()
        )
        await callback.answer()
        return
    
    await callback.message.edit_text(
        f"💸 Запрос на вывод средств\n\n"
        f"Доступно к выводу: {stats['available_for_withdrawal']:.2f}₽\n"
        f"Минимальная сумма: 500₽\n\n"
        f"Введите сумму для вывода:",
        reply_markup=cancel_keyboard()
    )
    await state.set_state(PartnerStates.withdrawal_amount)
    await callback.answer()

@dp.message(PartnerStates.withdrawal_amount)
async def partner_withdrawal_amount_handler(message: Message, state: FSMContext):
    try:
        amount = float(message.text)
        user_id = message.from_user.id
        stats = get_partner_stats(user_id)
        
        if amount < 500:
            await message.answer("❌ Минимальная сумма для вывода: 500₽")
            return
        
        if amount > stats['available_for_withdrawal']:
            await message.answer(f"❌ Недостаточно средств. Доступно: {stats['available_for_withdrawal']:.2f}₽")
            return
        
        await state.update_data(withdrawal_amount=amount)
        
        await message.answer(
            f"💳 Введите реквизиты для вывода {amount:.2f}₽\n\n"
            f"Укажите:\n"
            f"• Номер СБП или карты\n"
            f"• ФИО получателя\n"
            f"• Банк (если карта)\n\n"
            f"Пример: 1234 5678 9012 3456, Иванов Иван Иванович, Сбербанк",
            reply_markup=cancel_keyboard()
        )
        await state.set_state(PartnerStates.withdrawal_payment)
        
    except ValueError:
        await message.answer("❌ Введите корректную сумму (например: 1000)")

@dp.message(PartnerStates.withdrawal_payment)
async def partner_withdrawal_payment_handler(message: Message, state: FSMContext):
    user_data = await state.get_data()
    amount = user_data['withdrawal_amount']
    payment_details = message.text
    user_id = message.from_user.id
    
    # Создаем запрос на вывод
    success = create_withdrawal_request(user_id, amount, "СБП/Карта", payment_details)
    
    if success:
        # Отправляем уведомление админу с кнопкой
        admin_id = 367692958
        admin_message = (
            f"🔔 Новый запрос на вывод средств\n\n"
            f"👤 Партнер: @{message.from_user.username or 'user'} (ID: {user_id})\n"
            f"💰 Сумма: {amount:.2f}₽\n"
            f"💳 Реквизиты: {payment_details}\n"
            f"📅 Дата: {message.date.strftime('%d.%m.%Y %H:%M')}"
        )
        
        # Создаем клавиатуру для админа
        admin_keyboard = InlineKeyboardMarkup(
            inline_keyboard=[
                [InlineKeyboardButton(text="✅ Платеж выполнен", callback_data=f"admin_approve_{user_id}_{amount}")],
                [InlineKeyboardButton(text="❌ Отклонить", callback_data=f"admin_reject_{user_id}_{amount}")]
            ]
        )
        
        try:
            await bot.send_message(admin_id, admin_message, reply_markup=admin_keyboard)
        except Exception as e:
            print(f"Ошибка отправки уведомления админу: {e}")
        
        await message.answer(
            f"✅ Запрос на вывод {amount:.2f}₽ отправлен!\n\n"
            f"Ожидайте обработки в течение 24 часов.\n"
            f"Реквизиты: {payment_details}",
            reply_markup=menu_button()
        )
    else:
        await message.answer(
            "❌ Ошибка при создании запроса. Попробуйте позже.",
            reply_markup=menu_button()
        )
    
    await state.clear()

@dp.callback_query(F.data == "partner_history")
async def partner_history_callback(callback: CallbackQuery):
    user_id = callback.from_user.id
    
    # Получаем историю выплат
    history = get_partner_payment_history(user_id, 10)
    
    if not history:
        text = "📊 История выплат\n\nУ вас пока нет транзакций."
    else:
        text = "📊 История выплат\n\n"
        
        for i, transaction in enumerate(history, 1):
            amount = transaction['amount']
            date = transaction['transaction_date'].strftime('%d.%m.%Y %H:%M')
            description = transaction['description']
            payment_type = transaction['payment_type']
            
            # Определяем иконку и тип операции
            if payment_type == 'commission':
                icon = "💰"
                operation = f"+{amount:.2f}₽"
            elif payment_type == 'withdrawal':
                icon = "💸"
                operation = f"-{amount:.2f}₽"
            elif payment_type == 'withdrawal_request':
                icon = "📤"
                status = transaction.get('status', 'pending')
                if status == 'approved':
                    operation = f"-{amount:.2f}₽ ✅"
                elif status == 'rejected':
                    operation = f"{amount:.2f}₽ ❌"
                else:
                    operation = f"{amount:.2f}₽ ⏳"
            else:
                icon = "📄"
                operation = f"{amount:.2f}₽"
            
            text += f"{i}. {icon} {operation}\n"
            text += f"   {description}\n"
            text += f"   📅 {date}\n\n"
    
    keyboard = InlineKeyboardMarkup(
        inline_keyboard=[
            [InlineKeyboardButton(text="🔄 Обновить", callback_data="partner_history")],
            [InlineKeyboardButton(text="🔙 Назад", callback_data="menu_partner")]
        ]
    )
    
    await callback.message.edit_text(text, reply_markup=keyboard)
    await callback.answer()

@dp.callback_query(F.data == "partner_help")
async def partner_help_callback(callback: CallbackQuery):
    text = (
        "ℹ️ Как работает партнерская программа\n\n"
        "🎯 Приводите клиентов по своей ссылке\n"
        "💰 Получайте 10% с каждой покупки\n"
        "💸 Выводите заработанные средства\n\n"
        "📋 Условия:\n"
        "• Минимальная сумма вывода: 500₽\n"
        "• Выплаты в течение 24 часов\n"
        "• Комиссия начисляется автоматически\n\n"
        "🔗 Поделитесь своей ссылкой с друзьями!"
    )
    
    keyboard = InlineKeyboardMarkup(
        inline_keyboard=[
            [InlineKeyboardButton(text="🔙 Назад", callback_data="menu_partner")]
        ]
    )
    
    await callback.message.edit_text(text, reply_markup=keyboard)
    await callback.answer()

# --- Обработчики для админа ---
@dp.callback_query(F.data.startswith("admin_approve_"))
async def admin_approve_withdrawal(callback: CallbackQuery):
    # Отвечаем на callback сразу, чтобы избежать таймаута
    try:
        await callback.answer()
    except Exception:
        pass  # Игнорируем ошибки, если callback уже обработан
    
    # Проверяем, что это админ
    if not check_admin(callback.from_user.id, callback.from_user.username or ""):
        try:
            await callback.answer("❌ У вас нет прав для выполнения этого действия")
        except Exception:
            pass
        return
    
    # Парсим данные из callback_data
    parts = callback.data.split("_")
    partner_id = int(parts[2])
    amount = float(parts[3])
    
    # Обновляем статус на "approved"
    success = update_withdrawal_status(partner_id, amount, "approved", "Одобрено админом")
    
    if success:
        # Уведомляем партнера
        try:
            await bot.send_message(
                partner_id,
                f"✅ Ваш запрос на вывод {amount:.2f}₽ одобрен!\n\n"
                f"Средства будут переведены в течение 24 часов."
            )
        except Exception as e:
            print(f"Ошибка отправки уведомления партнеру: {e}")
        
        await callback.message.edit_text(
            f"✅ Запрос на вывод {amount:.2f}₽ от пользователя {partner_id} одобрен!\n\n"
            f"Партнер уведомлен о статусе.",
            reply_markup=None
        )
        await callback.answer("✅ Запрос одобрен!")
    else:
        await callback.answer("❌ Ошибка при обновлении статуса")
        await callback.message.edit_text(
            f"❌ Ошибка при одобрении запроса на вывод {amount:.2f}₽ от пользователя {partner_id}",
            reply_markup=None
        )

@dp.callback_query(F.data.startswith("admin_reject_"))
async def admin_reject_withdrawal(callback: CallbackQuery):
    # Отвечаем на callback сразу, чтобы избежать таймаута
    try:
        await callback.answer()
    except Exception:
        pass  # Игнорируем ошибки, если callback уже обработан
    
    # Проверяем, что это админ
    if not check_admin(callback.from_user.id, callback.from_user.username or ""):
        try:
            await callback.answer("❌ У вас нет прав для выполнения этого действия")
        except Exception:
            pass
        return
    
    # Парсим данные из callback_data
    parts = callback.data.split("_")
    partner_id = int(parts[2])
    amount = float(parts[3])
    
    # Обновляем статус на "rejected"
    success = update_withdrawal_status(partner_id, amount, "rejected", "Отклонено админом")
    
    if success:
        # Уведомляем партнера
        try:
            await bot.send_message(
                partner_id,
                f"❌ Ваш запрос на вывод {amount:.2f}₽ отклонен.\n\n"
                f"Средства возвращены на баланс."
            )
        except Exception as e:
            print(f"Ошибка отправки уведомления партнеру: {e}")
        
        await callback.message.edit_text(
            f"❌ Запрос на вывод {amount:.2f}₽ от пользователя {partner_id} отклонен!\n\n"
            f"Партнер уведомлен о статусе.",
            reply_markup=None
        )
        await callback.answer("❌ Запрос отклонен!")
    else:
        await callback.answer("❌ Ошибка при обновлении статуса")
        await callback.message.edit_text(
            f"❌ Ошибка при отклонении запроса на вывод {amount:.2f}₽ от пользователя {partner_id}",
            reply_markup=None
        )

# --- Обработчики для Suno ---
@dp.callback_query(SunoStates.choose_model, F.data.startswith("suno_model_"))
async def choose_suno_model_callback(callback: CallbackQuery, state: FSMContext):
    await callback.answer()
    model_data = callback.data  # suno_model_V3_5, etc.
    model = model_data.split("_")[2]  # V3_5, V4, etc.
    
    await state.update_data(suno_model=model)
    
    # Выбор режима
    mode_buttons = [
        {"text": "🎵 Простой режим (промпт до 500 символов)", "callback_data": "suno_mode_simple"},
        {"text": "🎼 Расширенный режим (длинный промпт + стиль + название)", "callback_data": "suno_mode_custom"},
    ]
    keyboard = InlineKeyboardMarkup(
        inline_keyboard=[[InlineKeyboardButton(**btn)] for btn in mode_buttons]
    )
    await callback.message.answer("Выберите режим генерации:", reply_markup=keyboard)
    await state.set_state(SunoStates.choose_mode)

@dp.callback_query(SunoStates.choose_mode)
async def choose_suno_mode_callback(callback: CallbackQuery, state: FSMContext):
    await callback.answer()
    mode_data = callback.data  # suno_mode_simple или suno_mode_custom
    custom_mode = mode_data == "suno_mode_custom"
    
    await state.update_data(custom_mode=custom_mode)
    
    # Выбор инструментальная или с вокалом
    instrumental_buttons = [
        {"text": "🎵 Инструментальная музыка", "callback_data": "suno_instrumental_true"},
        {"text": "🎤 С вокалом и текстом", "callback_data": "suno_instrumental_false"},
    ]
    keyboard = InlineKeyboardMarkup(
        inline_keyboard=[[InlineKeyboardButton(**btn)] for btn in instrumental_buttons]
    )
    await callback.message.answer("Выберите тип музыки:", reply_markup=keyboard)
    await state.set_state(SunoStates.choose_instrumental)

@dp.callback_query(SunoStates.choose_instrumental)
async def choose_suno_instrumental_callback(callback: CallbackQuery, state: FSMContext):
    await callback.answer()
    instrumental_data = callback.data  # suno_instrumental_true или false
    instrumental = instrumental_data == "suno_instrumental_true"
    
    await state.update_data(instrumental=instrumental)
    
    user_data = await state.get_data()
    custom_mode = user_data.get("custom_mode")
    
    if custom_mode:
        await callback.message.answer(
            "Введите стиль музыки (например: Pop, Rock, Jazz, Electronic):",
            reply_markup=cancel_keyboard()
        )
        await state.set_state(SunoStates.input_style)
    else:
        await callback.message.answer(
            "Введите описание музыки, которую хотите создать:",
            reply_markup=cancel_keyboard()
        )
        await state.set_state(SunoStates.input_prompt)

@dp.message(SunoStates.input_style)
async def input_suno_style_handler(message: Message, state: FSMContext):
    style = message.text
    await state.update_data(style=style)
    
    await message.answer(
        "Введите название для вашей музыки:",
        reply_markup=cancel_keyboard()
    )
    await state.set_state(SunoStates.input_title)

@dp.message(SunoStates.input_title)
async def input_suno_title_handler(message: Message, state: FSMContext):
    title = message.text
    await state.update_data(title=title)
    
    await message.answer(
        "Введите детальное описание музыки, которую хотите создать:",
        reply_markup=cancel_keyboard()
    )
    await state.set_state(SunoStates.input_prompt)

@dp.message(SunoStates.input_prompt)
async def input_suno_prompt_handler(message: Message, state: FSMContext):
    user_data = await state.get_data()
    user_id = message.from_user.id
    username = message.from_user.username or "user"
    first_name = message.from_user.first_name or "Пользователь"
    prompt = message.text
    
    model = user_data.get("suno_model")
    custom_mode = user_data.get("custom_mode", False)
    instrumental = user_data.get("instrumental", False)
    style = user_data.get("style")
    title = user_data.get("title")
    
    # Фиксированная стоимость для всех Suno операций
    cost = 15
    
    # Проверка баланса
    if get_balance(user_id) < cost:
        await message.answer(f"💸 Недостаточно средств. Ваш баланс: {get_balance(user_id):.2f} токенов")
        await state.clear()
        return

    # Проверка длины промпта для non-custom режима
    if not custom_mode and len(prompt) > 500:
        await message.answer(
            f"⚠️ В простом режиме промпт не может превышать 500 символов.\n"
            f"Ваш промпт: {len(prompt)} символов.\n\n"
            f"Пожалуйста, сократите описание или выберите расширенный режим для длинных промптов."
        )
        return

    await message.answer("⏳ Генерация музыки запущена, ждите...")

    try:
        api_response = await create_suno_music_task(
            prompt=prompt,
            model=model,
            custom_mode=custom_mode,
            instrumental=instrumental,
            style=style,
            title=title,
            callback_url=f"{CALLBACK_BASE_URL}/suno-callback",
        )

        if api_response is None or api_response.get("code") != 200:
            # Логируем ошибку
            from database import log_user_request
            log_user_request(
                user_telegram_id=user_id,
                user_username=username,
                user_first_name=first_name,
                request_type="suno",
                model_name=model,
                prompt=prompt,
                cost=cost,
                api_response=str(api_response)
            )
            
            error_msg = api_response.get("msg", "Неизвестная ошибка") if api_response else "Ошибка подключения к API"
            if "prompt words" in error_msg and "500 characters" in error_msg:
                await message.answer(
                    "⚠️ В простом режиме промпт не может превышать 500 символов.\n"
                    "Пожалуйста, сократите описание или выберите расширенный режим для длинных промптов."
                )
            else:
                await message.answer(f"❌ Не удалось создать задачу:\n{error_msg}")
            await state.clear()
            return

        task_id = api_response["data"]["taskId"]
        save_task_chat(task_id, user_id, cost)
        
        # Логируем успешный запрос
        from database import log_user_request
        log_user_request(
            user_telegram_id=user_id,
            user_username=username,
            user_first_name=first_name,
            request_type="suno",
            model_name=model,
            prompt=prompt,
            task_id=task_id,
            cost=cost,
            api_response=str(api_response)
        )
        
        # НЕ списываем баланс сразу - только после получения музыки

        await message.answer(
            f"✅ Задача отправлена!\nTask ID: <code>{task_id}</code>\n"
            "После завершения музыка придет в чат автоматически.\n"
            "Баланс будет списан только при успешной генерации."
        )

    except Exception as e:
        await message.answer(f"❌ Ошибка при создании задачи:\n{e}")

    await state.clear()

# --- Обработчики для TTS ---
# Обработчик кнопки "Выбрать голос для генерации"
@dp.callback_query(TTSStates.choose_voice_info, F.data == "tts_show_voices")
async def tts_show_voices_callback(callback: CallbackQuery, state: FSMContext):
    await callback.answer()
    # Показываем список голосов
    voice_buttons = [
        {"text": "Rachel (женский)", "callback_data": "tts_voice_Rachel"},
        {"text": "Aria (женский)", "callback_data": "tts_voice_Aria"},
        {"text": "Roger (мужской)", "callback_data": "tts_voice_Roger"},
        {"text": "Sarah (женский)", "callback_data": "tts_voice_Sarah"},
        {"text": "Laura (женский)", "callback_data": "tts_voice_Laura"},
        {"text": "Charlie (мужской)", "callback_data": "tts_voice_Charlie"},
        {"text": "George (мужской)", "callback_data": "tts_voice_George"},
        {"text": "Callum (мужской)", "callback_data": "tts_voice_Callum"},
        {"text": "River (мужской)", "callback_data": "tts_voice_River"},
        {"text": "Liam (мужской)", "callback_data": "tts_voice_Liam"},
        {"text": "Charlotte (женский)", "callback_data": "tts_voice_Charlotte"},
        {"text": "Alice (женский)", "callback_data": "tts_voice_Alice"},
        {"text": "Matilda (женский)", "callback_data": "tts_voice_Matilda"},
        {"text": "Will (мужской)", "callback_data": "tts_voice_Will"},
        {"text": "Jessica (женский)", "callback_data": "tts_voice_Jessica"},
        {"text": "Eric (мужской)", "callback_data": "tts_voice_Eric"},
        {"text": "Chris (мужской)", "callback_data": "tts_voice_Chris"},
        {"text": "Brian (мужской)", "callback_data": "tts_voice_Brian"},
        {"text": "Daniel (мужской)", "callback_data": "tts_voice_Daniel"},
        {"text": "Lily (женский)", "callback_data": "tts_voice_Lily"},
        {"text": "Bill (мужской)", "callback_data": "tts_voice_Bill"},
    ]
    keyboard = InlineKeyboardMarkup(
        inline_keyboard=[[InlineKeyboardButton(**btn)] for btn in voice_buttons]
    )
    await callback.message.answer("🎙️ Выберите голос для генерации речи:", reply_markup=keyboard)
    await state.set_state(TTSStates.choose_voice)

@dp.callback_query(TTSStates.choose_voice, F.data.startswith("tts_voice_"))
async def choose_tts_voice_callback(callback: CallbackQuery, state: FSMContext):
    await callback.answer()
    voice_data = callback.data  # tts_voice_Rachel
    voice = voice_data.split("_")[2]  # Rachel
    
    user_id = callback.from_user.id
    username = callback.from_user.username or "user"
    
    logging.info(f"[TTS] Пользователь {user_id} (@{username}) выбрал голос: {voice}")
    
    await state.update_data(tts_voice=voice)
    
    await callback.message.answer(
        f"🎙️ Выбран голос: {voice}\n\n"
        f"💡 Стоимость генерации:\n"
        f"▫️ До 1000 знаков → 12₽\n"
        f"▫️ От 1000 до 2000 → 24₽\n"
        f"▫️ От 2000 до 3000 → 36₽\n"
        f"▫️ Каждые 1000 знаков +12₽\n\n"
        f"Введите текст для генерации речи:",
        reply_markup=cancel_keyboard()
    )
    await state.set_state(TTSStates.input_text)

@dp.message(TTSStates.input_text)
async def input_tts_text_handler(message: Message, state: FSMContext):
    user_data = await state.get_data()
    user_id = message.from_user.id
    username = message.from_user.username or "user"
    first_name = message.from_user.first_name or "Пользователь"
    text = message.text or ""
    voice = user_data.get("tts_voice", "Rachel")
    text_length = len(text)
    user_tag = get_user_tag(user_id)

    logging.info(f"[TTS] Пользователь {user_id} (@{username}) ввел текст: {text_length} символов, голос: {voice}")

    is_subscribed = await is_user_subscribed_to_tts_channel(user_id)
    if not is_subscribed:
        logging.info(f"[TTS] Пользователь {user_id} не подписан на канал. Генерация отклонена.")
        subscribe_keyboard = InlineKeyboardMarkup(
            inline_keyboard=[
                [InlineKeyboardButton(text="📢 Подписаться на канал", url=TTS_SUBSCRIPTION_LINK)],
                [InlineKeyboardButton(text="🔙 Меню", callback_data="open_menu")]
            ]
        )
        await message.answer(
            "Чтобы пользоваться озвучкой, подпишитесь на сообщество и повторите попытку.",
            reply_markup=subscribe_keyboard
        )
        await state.clear()
        return

    cost = await get_tts_cost(text)
    logging.info(f"[TTS] Рассчитана стоимость: {cost}₽ для {text_length} символов")

    balance = get_balance(user_id)
    logging.info(f"[TTS] Баланс пользователя {user_id}: {balance}₽")

    has_payments = has_completed_payments(user_id)
    free_usage_used = get_tts_free_usage(user_id)
    is_free_usage = False
    if balance < cost:
        if user_tag == "tts" and not has_payments:
            if free_usage_used > 0:
                logging.info(f"[TTS] Бесплатная генерация уже использована сегодня пользователем {user_id}")
                await message.answer(
                    "Сегодня вы уже использовали бесплатную генерацию. "
                    "Попробуйте завтра или пополните баланс для безлимитного использования."
                )
                await state.clear()
                return
            if text_length > TTS_FREE_DAILY_LIMIT:
                logging.info(
                    f"[TTS] Текст превышает бесплатный лимит для пользователя {user_id}: {text_length} > {TTS_FREE_DAILY_LIMIT}"
                )
                await message.answer(
                    f"В бесплатном режиме можно озвучить до {TTS_FREE_DAILY_LIMIT} знаков за одну генерацию. "
                    "Сократите текст или пополните баланс, чтобы снять ограничение."
                )
                await state.clear()
                return
            if text_length == 0:
                await message.answer("Отправьте текст для озвучки (до 1000 знаков в бесплатном режиме).")
                await state.clear()
                return
            is_free_usage = True
            logging.info(
                f"[TTS] Применяем бесплатную генерацию для пользователя {user_id}: {text_length} символов из лимита {TTS_FREE_DAILY_LIMIT}"
            )
        else:
            logging.warning(
                f"[TTS] Недостаточно средств у пользователя {user_id}: баланс {balance}₽, требуется {cost}₽"
            )
            await message.answer(
                f"💸 Недостаточно средств. Ваш баланс: {balance:.2f} токенов\n"
                f"Стоимость генерации: {cost} токенов"
            )
            await state.clear()
            return

    actual_cost = 0 if is_free_usage else cost

    blocks = math.ceil(text_length / 1000) if text_length else 1
    if blocks == 1:
        range_text = "до 1000 знаков"
    elif blocks == 2:
        range_text = "от 1000 до 2000"
    elif blocks == 3:
        range_text = "от 2000 до 3000"
    else:
        range_text = f"{blocks * 1000 - 1000}+ знаков"

    if is_free_usage:
        await message.answer(
            "⏳ Генерация речи запущена, ждите...\n\n"
            "📊 Бесплатный режим активирован:\n"
            f"▫️ Текст: {text_length} символов\n"
            f"▫️ Лимит: до {TTS_FREE_DAILY_LIMIT} символов, 1 генерация в сутки\n"
            "💡 Пополните баланс, чтобы убрать ограничения."
        )
    else:
        await message.answer(
            f"⏳ Генерация речи запущена, ждите...\n\n"
            f"📊 Информация о стоимости:\n"
            f"▫️ Текст: {text_length} символов\n"
            f"▫️ Диапазон: {range_text}\n"
            f"▫️ Итоговая стоимость: {actual_cost}₽\n"
            f"💡 Стоимость: {blocks} блок(ов) × 12₽"
        )

    logging.info(f"[TTS] Отправка запроса на генерацию для пользователя {user_id}")

    try:
        api_response = await create_tts_task(
            text=text,
            voice=voice,
            callback_url=f"{CALLBACK_BASE_URL}/tts-callback",
        )

        logging.info(f"[TTS] Получен ответ от API: {api_response}")

        if api_response is None or api_response.get("code") != 200:
            logging.error(f"[TTS] Ошибка API для пользователя {user_id}")
            logging.error(f"[TTS] Полный ответ API: {api_response}")

            print(f"[TTS ERROR] Пользователь {user_id}: API вернул ошибку")
            print(f"[TTS ERROR] Response: {api_response}")
            if api_response and "response_text" in api_response:
                print(f"[TTS ERROR] Response text: {api_response['response_text']}")
            from database import log_user_request
            log_user_request(
                user_telegram_id=user_id,
                user_username=username,
                user_first_name=first_name,
                request_type="tts",
                model_name=voice,
                prompt=text,
                cost=actual_cost,
                api_response=str(api_response)
            )

            if api_response and "response_text" in api_response:
                error_msg = f"HTTP {api_response.get('status_code', 'unknown')}: {api_response['response_text']}"
            elif api_response and "msg" in api_response:
                error_msg = api_response["msg"]
            elif api_response and "error" in api_response:
                error_msg = str(api_response["error"])
            else:
                error_msg = "Неизвестная ошибка. Проверьте логи."

            await message.answer(f"❌ Не удалось создать задачу:\n{error_msg}")
            await state.clear()
            return

        task_id = api_response["data"]["taskId"]
        logging.info(f"[TTS] Создана задача с task_id: {task_id} для пользователя {user_id}")
        save_task_chat(task_id, user_id, actual_cost)

        logging.info(f"[TTS] Логирование запроса в БД для пользователя {user_id}, task_id: {task_id}")
        from database import log_user_request
        log_user_request(
            user_telegram_id=user_id,
            user_username=username,
            user_first_name=first_name,
            request_type="tts",
            model_name=voice,
            prompt=text,
            task_id=task_id,
            cost=actual_cost,
            api_response=str(api_response)
        )

        if is_free_usage:
            increment_tts_free_usage(user_id, text_length)

        logging.info(f"[TTS] Генерация успешно запущена для пользователя {user_id}, task_id: {task_id}, голос: {voice}, текст: {text_length} символов")

        await message.answer(
            f"✅ Задача отправлена!\nTask ID: <code>{task_id}</code>\n"
            "После завершения аудио придет в чат автоматически.\n"
            "Баланс будет списан только при успешной генерации."
        )

    except Exception as e:
        logging.error(f"[TTS] Критическая ошибка при создании задачи для пользователя {user_id}: {e}", exc_info=True)
        await message.answer(f"❌ Ошибка при создании задачи:\n{e}")

    await state.clear()

# --- Обработчики для Nano Banana ---
@dp.callback_query(NanoBananaStates.choose_mode, F.data.startswith("nano_mode_"))
async def choose_nano_mode_callback(callback: CallbackQuery, state: FSMContext):
    await callback.answer()
    mode_data = callback.data  # nano_mode_generate/edit/upscale/pro
    mode = mode_data.split("_")[2]  # generate, edit, upscale, pro
    
    logging.info(f"[choose_nano_mode_callback] Received callback: {mode_data}, mode: {mode}")
    await state.update_data(nano_mode=mode)
    
    # Для PRO версии сразу переходим к выбору aspect ratio
    if mode == "pro":
        aspect_ratio_buttons = [
            {"text": "1:1 (Квадрат)", "callback_data": "nano_pro_aspect_1:1"},
            {"text": "2:3 (Портрет)", "callback_data": "nano_pro_aspect_2:3"},
            {"text": "3:2 (Альбом)", "callback_data": "nano_pro_aspect_3:2"},
            {"text": "3:4 (Портрет)", "callback_data": "nano_pro_aspect_3:4"},
            {"text": "4:3 (Альбом)", "callback_data": "nano_pro_aspect_4:3"},
            {"text": "4:5 (Портрет)", "callback_data": "nano_pro_aspect_4:5"},
            {"text": "5:4 (Альбом)", "callback_data": "nano_pro_aspect_5:4"},
            {"text": "9:16 (Портрет)", "callback_data": "nano_pro_aspect_9:16"},
            {"text": "16:9 (Альбом)", "callback_data": "nano_pro_aspect_16:9"},
            {"text": "21:9 (Широкий)", "callback_data": "nano_pro_aspect_21:9"},
        ]
        keyboard = InlineKeyboardMarkup(
            inline_keyboard=[[InlineKeyboardButton(**btn)] for btn in aspect_ratio_buttons]
        )
        await callback.message.answer("Выберите соотношение сторон (aspect ratio):", reply_markup=keyboard)
        await state.set_state(NanoBananaStates.choose_pro_aspect_ratio)
        return
    
    # Выбор формата для обычных режимов
    format_buttons = [
        {"text": "PNG", "callback_data": "nano_format_png"},
        {"text": "JPEG", "callback_data": "nano_format_jpeg"},
    ]
    keyboard = InlineKeyboardMarkup(
        inline_keyboard=[[InlineKeyboardButton(**btn)] for btn in format_buttons]
    )
    await callback.message.answer("Выберите формат изображения:", reply_markup=keyboard)
    await state.set_state(NanoBananaStates.choose_format)

@dp.callback_query(NanoBananaStates.choose_format, F.data.startswith("nano_format_"))
async def choose_nano_format_callback(callback: CallbackQuery, state: FSMContext):
    await callback.answer()
    format_data = callback.data  # nano_format_png/jpeg
    format_type = format_data.split("_")[2]  # png, jpeg
    
    await state.update_data(output_format=format_type)
    
    user_data = await state.get_data()
    mode = user_data.get("nano_mode")
    
    if mode == "upscale":
        # Для upscale сразу запрашиваем изображение
        await callback.message.answer(
            "Отправьте изображение для увеличения разрешения:",
            reply_markup=cancel_keyboard()
        )
        await state.set_state(NanoBananaStates.get_image)
    else:
        # Для generate/edit выбираем размер
        size_buttons = [
            {"text": "1:1 (Квадрат)", "callback_data": "nano_size_1:1"},
            {"text": "9:16 (Портрет)", "callback_data": "nano_size_9:16"},
            {"text": "16:9 (Альбом)", "callback_data": "nano_size_16:9"},
            {"text": "3:4 (Портрет)", "callback_data": "nano_size_3:4"},
            {"text": "4:3 (Альбом)", "callback_data": "nano_size_4:3"},
            {"text": "Авто", "callback_data": "nano_size_auto"},
        ]
        keyboard = InlineKeyboardMarkup(
            inline_keyboard=[[InlineKeyboardButton(**btn)] for btn in size_buttons]
        )
        await callback.message.answer("Выберите размер изображения:", reply_markup=keyboard)
        await state.set_state(NanoBananaStates.choose_size)

@dp.callback_query(NanoBananaStates.choose_size, F.data.startswith("nano_size_"))
async def choose_nano_size_callback(callback: CallbackQuery, state: FSMContext):
    await callback.answer()
    size_data = callback.data  # nano_size_1:1, etc.
    size = size_data.split("_")[2]  # 1:1, 9:16, etc.
    
    await state.update_data(image_size=size)
    
    user_data = await state.get_data()
    mode = user_data.get("nano_mode")
    
    if mode == "edit":
        await callback.message.answer(
            "Отправьте изображение для редактирования:",
            reply_markup=cancel_keyboard()
        )
        await state.set_state(NanoBananaStates.get_image)
    else:  # generate
        await callback.message.answer(
            "Введите описание изображения, которое хотите создать:",
            reply_markup=cancel_keyboard()
        )
        await state.set_state(NanoBananaStates.input_prompt)

# --- Обработчики для PRO версии ---
@dp.callback_query(NanoBananaStates.choose_pro_aspect_ratio, F.data.startswith("nano_pro_aspect_"))
async def choose_pro_aspect_ratio_callback(callback: CallbackQuery, state: FSMContext):
    await callback.answer()
    aspect_data = callback.data  # nano_pro_aspect_1:1, etc.
    aspect_ratio = aspect_data.split("_")[3]  # 1:1, 2:3, etc.
    
    await state.update_data(pro_aspect_ratio=aspect_ratio)
    
    # Выбор разрешения
    resolution_buttons = [
        {"text": "1K", "callback_data": "nano_pro_resolution_1K"},
        {"text": "2K", "callback_data": "nano_pro_resolution_2K"},
        {"text": "4K", "callback_data": "nano_pro_resolution_4K"},
    ]
    keyboard = InlineKeyboardMarkup(
        inline_keyboard=[[InlineKeyboardButton(**btn)] for btn in resolution_buttons]
    )
    await callback.message.answer("Выберите разрешение изображения:", reply_markup=keyboard)
    await state.set_state(NanoBananaStates.choose_pro_resolution)
    await state.set_state(NanoBananaStates.choose_pro_resolution)

@dp.callback_query(NanoBananaStates.choose_pro_resolution, F.data.startswith("nano_pro_resolution_"))
async def choose_pro_resolution_callback(callback: CallbackQuery, state: FSMContext):
    await callback.answer()
    resolution_data = callback.data  # nano_pro_resolution_1K, etc.
    resolution = resolution_data.split("_")[3]  # 1K, 2K, 4K
    
    await state.update_data(pro_resolution=resolution)
    
    # Выбор формата
    format_buttons = [
        {"text": "PNG", "callback_data": "nano_pro_format_png"},
        {"text": "JPG", "callback_data": "nano_pro_format_jpg"},
    ]
    keyboard = InlineKeyboardMarkup(
        inline_keyboard=[[InlineKeyboardButton(**btn)] for btn in format_buttons]
    )
    await callback.message.answer("Выберите формат изображения:", reply_markup=keyboard)

@dp.callback_query(F.data.startswith("nano_pro_format_"))
async def choose_pro_format_callback(callback: CallbackQuery, state: FSMContext):
    await callback.answer()
    format_data = callback.data  # nano_pro_format_png/jpg
    format_type = format_data.split("_")[3]  # png, jpg
    
    await state.update_data(pro_output_format=format_type)
    
    # Опциональная загрузка изображений (до 8)
    keyboard = InlineKeyboardMarkup(
        inline_keyboard=[
            [InlineKeyboardButton(text="📷 Загрузить изображения (опционально, до 8)", callback_data="nano_pro_add_images")],
            [InlineKeyboardButton(text="⏭️ Пропустить", callback_data="nano_pro_skip_images")],
        ]
    )
    await callback.message.answer(
        "Вы можете загрузить до 8 изображений для использования в качестве референса (опционально):",
        reply_markup=keyboard
    )

@dp.callback_query(F.data == "nano_pro_add_images")
async def pro_add_images_callback(callback: CallbackQuery, state: FSMContext):
    await callback.answer()
    await state.update_data(pro_images=[])  # Инициализируем список изображений
    await callback.message.answer(
        "Отправьте изображения (до 8 штук). После отправки всех изображений нажмите 'Готово'.",
        reply_markup=InlineKeyboardMarkup(
            inline_keyboard=[[InlineKeyboardButton(text="✅ Готово", callback_data="nano_pro_images_done")]]
        )
    )
    await state.set_state(NanoBananaStates.get_pro_images)

@dp.callback_query(F.data == "nano_pro_skip_images")
async def pro_skip_images_callback(callback: CallbackQuery, state: FSMContext):
    await callback.answer()
    await state.update_data(pro_images=[])
    await callback.message.answer(
        "Введите описание изображения, которое хотите создать:",
        reply_markup=cancel_keyboard()
    )
    await state.set_state(NanoBananaStates.input_prompt)

@dp.callback_query(F.data == "nano_pro_images_done")
async def pro_images_done_callback(callback: CallbackQuery, state: FSMContext):
    await callback.answer()
    user_data = await state.get_data()
    images = user_data.get("pro_images", [])
    if len(images) == 0:
        await callback.message.answer("⚠️ Вы не загрузили изображения. Пропускаем этот шаг.")
    await callback.message.answer(
        "Введите описание изображения, которое хотите создать:",
        reply_markup=cancel_keyboard()
    )
    await state.set_state(NanoBananaStates.input_prompt)

@dp.message(NanoBananaStates.get_pro_images)
async def get_pro_images_handler(message: Message, state: FSMContext):
    user_data = await state.get_data()
    images = user_data.get("pro_images", [])
    
    if len(images) >= 8:
        await message.answer("⚠️ Максимум 8 изображений. Нажмите 'Готово' для продолжения.")
        return
    
    file_id = None
    if message.photo:
        file_id = message.photo[-1].file_id
    elif message.document and message.document.mime_type.startswith("image/"):
        file_id = message.document.file_id
    else:
        await message.answer(
            "⚠️ Пожалуйста, отправьте фото или изображение в формате PNG/JPG/WEBP.",
            reply_markup=InlineKeyboardMarkup(
                inline_keyboard=[[InlineKeyboardButton(text="✅ Готово", callback_data="nano_pro_images_done")]]
            )
        )
        return
    
    try:
        file = await bot.get_file(file_id)
        file_url = f"https://api.telegram.org/file/bot{TELEGRAM_BOT_TOKEN}/{file.file_path}"
        images.append(file_url)
        await state.update_data(pro_images=images)
        
        remaining = 8 - len(images)
        await message.answer(
            f"✅ Изображение добавлено ({len(images)}/8). Осталось мест: {remaining}.\n"
            "Отправьте еще изображения или нажмите 'Готово'.",
            reply_markup=InlineKeyboardMarkup(
                inline_keyboard=[[InlineKeyboardButton(text="✅ Готово", callback_data="nano_pro_images_done")]]
            )
        )
    except Exception as e:
        logging.error(f"[get_pro_images_handler] Ошибка получения файла: {e}")
        await message.answer("❌ Ошибка при обработке изображения. Попробуйте еще раз.")

@dp.message(NanoBananaStates.get_image)
async def get_nano_image(message: Message, state: FSMContext):
    logging.info(f"[get_nano_image] Received message: {message}")

    file_id = None

    if message.photo:
        file_id = message.photo[-1].file_id
    elif message.document and message.document.mime_type.startswith("image/"):
        file_id = message.document.file_id
    else:
        await message.answer(
            "⚠️ Пожалуйста, отправьте фото или изображение в формате PNG/JPG.",
            reply_markup=cancel_keyboard()
        )
        return

    try:
        # Получаем URL файла
        file = await bot.get_file(file_id)
        file_url = f"https://api.telegram.org/file/bot{TELEGRAM_BOT_TOKEN}/{file.file_path}"
        
        user_data = await state.get_data()
        await state.update_data(image_urls=[file_url])
        
        mode = user_data.get("nano_mode")
        
        if mode == "upscale":
            # Выбор коэффициента увеличения для Topaz Upscale
            upscale_buttons = [
                {"text": "1x (8₽)", "callback_data": "upscale_factor_1"},
                {"text": "2x (8₽)", "callback_data": "upscale_factor_2"},
                {"text": "4x (16₽)", "callback_data": "upscale_factor_4"},
                {"text": "8x (27₽)", "callback_data": "upscale_factor_8"},
            ]
            keyboard = InlineKeyboardMarkup(
                inline_keyboard=[[InlineKeyboardButton(**btn)] for btn in upscale_buttons]
            )
            await message.answer("Выберите коэффициент увеличения:", reply_markup=keyboard)
            await state.set_state(NanoBananaStates.choose_upscale_factor)
        elif user_data.get("preset_prompt"):
            # Промпт пришёл из канала — спрашивать описание не нужно
            await state.set_state(NanoBananaStates.input_prompt)
            await input_nano_prompt_handler(message, state)
        else:  # edit
            await message.answer(
                "Введите описание изменений, которые хотите внести в изображение:",
                reply_markup=cancel_keyboard()
            )
            await state.set_state(NanoBananaStates.input_prompt)

    except Exception as e:
        logging.error(f"[get_nano_image] Ошибка получения файла: {e}")
        await message.answer("❌ Ошибка при обработке изображения. Попробуйте еще раз.")

@dp.callback_query(NanoBananaStates.choose_upscale_factor, F.data.startswith("upscale_factor_"))
async def choose_upscale_factor_callback(callback: CallbackQuery, state: FSMContext):
    await callback.answer()
    factor_data = callback.data  # upscale_factor_1, upscale_factor_2, etc.
    upscale_factor = factor_data.split("_")[2]  # 1, 2, 4, 8
    
    await state.update_data(upscale_factor=upscale_factor)
    
    user_data = await state.get_data()
    user_id = callback.from_user.id
    username = callback.from_user.username or "user"
    first_name = callback.from_user.first_name or "Пользователь"
    
    # Получаем стоимость в зависимости от коэффициента
    cost = get_topaz_upscale_cost(upscale_factor)
    
    # Проверка баланса
    if get_balance(user_id) < cost:
        await callback.message.answer(f"💸 Недостаточно средств. Ваш баланс: {get_balance(user_id):.2f} токенов")
        await state.clear()
        return

    image_urls = user_data.get("image_urls")
    if not image_urls or len(image_urls) == 0:
        await callback.message.answer("❌ Ошибка: изображение не найдено")
        await state.clear()
        return
    
    image_url = image_urls[0]  # Topaz принимает один URL
    
    await callback.message.answer("⏳ Увеличение разрешения запущено, ждите...")

    try:
        logging.info(f"[choose_upscale_factor_callback] Создаем задачу Topaz Upscale: factor={upscale_factor}, image_url={image_url[:50]}...")
        
        api_response = await create_topaz_upscale_task(
            image_url=image_url,
            upscale_factor=upscale_factor,
            callback_url=f"{CALLBACK_BASE_URL}/nano-banana-callback",
        )
        
        logging.info(f"[choose_upscale_factor_callback] Ответ API: {api_response}")

        if api_response is None or api_response.get("code") != 200:
            # Логируем ошибку
            from database import log_user_request
            log_user_request(
                user_telegram_id=user_id,
                user_username=username,
                user_first_name=first_name,
                request_type="nano_banana",
                model_name="topaz_upscale",
                prompt=None,
                image_urls=image_url,
                cost=cost,
                api_response=str(api_response)
            )
            
            error_msg = api_response.get("msg", "Неизвестная ошибка") if api_response else "Ошибка подключения к API"
            if api_response and "error" in api_response:
                error_msg = api_response["error"]
            await callback.message.answer(f"❌ Не удалось создать задачу:\n{error_msg}")
            await state.clear()
            return

        # Проверяем наличие taskId в ответе
        if "data" not in api_response or "taskId" not in api_response["data"]:
            await callback.message.answer(f"❌ Неверный ответ от API: отсутствует taskId\n<pre>{api_response}</pre>")
            await state.clear()
            return
            
        task_id = api_response["data"]["taskId"]
        save_task_chat(task_id, user_id, cost)
        
        # Логируем успешный запрос
        from database import log_user_request
        log_user_request(
            user_telegram_id=user_id,
            user_username=username,
            user_first_name=first_name,
            request_type="nano_banana",
            model_name="topaz_upscale",
            prompt=None,
            image_urls=image_url,
            task_id=task_id,
            cost=cost,
            api_response=str(api_response)
        )
        
        await callback.message.answer(
            f"✅ Задача отправлена!\nTask ID: <code>{task_id}</code>\n"
            f"Коэффициент увеличения: {upscale_factor}x\n"
            f"Стоимость: {cost}₽\n\n"
            "После завершения изображение придет в чат автоматически.\n"
            "Баланс будет списан только при успешной генерации."
        )

    except Exception as e:
        logging.error(f"[choose_upscale_factor_callback] Ошибка: {e}")
        await callback.message.answer(f"❌ Ошибка при создании задачи:\n{e}")

    await state.clear()

@dp.message(NanoBananaStates.input_prompt)
async def input_nano_prompt_handler(message: Message, state: FSMContext):
    user_data = await state.get_data()
    user_id = message.from_user.id
    username = message.from_user.username or "user"
    first_name = message.from_user.first_name or "Пользователь"
    # Промпт мог прийти из канала по кнопке «Повторить это фото»
    prompt = user_data.get("preset_prompt") or message.text
    
    mode = user_data.get("nano_mode")
    
    # Обработка PRO версии
    if mode == "pro":
        aspect_ratio = user_data.get("pro_aspect_ratio")
        resolution = user_data.get("pro_resolution")
        output_format = user_data.get("pro_output_format", "png")
        image_input = user_data.get("pro_images", [])
        
        cost = 18  # Стоимость PRO версии
        
        # Проверка баланса
        if get_balance(user_id) < cost:
            await message.answer(f"💸 Недостаточно средств. Ваш баланс: {get_balance(user_id):.2f} токенов")
            await state.clear()
            return

        await message.answer("⏳ Генерация изображения PRO запущена, ждите...")

        try:
            logging.info(f"[input_nano_prompt_handler PRO] Создаем задачу: prompt='{prompt[:50]}...', aspect_ratio={aspect_ratio}, resolution={resolution}, image_input={len(image_input)} изображений")
            
            api_response = await create_nano_banana_pro_task(
                prompt=prompt,
                aspect_ratio=aspect_ratio,
                resolution=resolution,
                output_format=output_format,
                image_input=image_input if image_input else None,
                callback_url=f"{CALLBACK_BASE_URL}/nano-banana-callback",
            )
            
            logging.info(f"[input_nano_prompt_handler PRO] Ответ API: {api_response}")

            if api_response is None or api_response.get("code") != 200:
                # Логируем ошибку
                from database import log_user_request
                log_user_request(
                    user_telegram_id=user_id,
                    user_username=username,
                    user_first_name=first_name,
                    request_type="nano_banana",
                    model_name="pro",
                    prompt=prompt,
                    image_urls=",".join(image_input) if image_input else None,
                    cost=cost,
                    api_response=str(api_response)
                )
                
                error_msg = api_response.get("msg", "Неизвестная ошибка") if api_response else "Ошибка подключения к API"
                if api_response and "error" in api_response:
                    error_msg = api_response["error"]
                await message.answer(f"❌ Не удалось создать задачу:\n{error_msg}")
                await state.clear()
                return

            # Проверяем наличие taskId в ответе
            if "data" not in api_response or "taskId" not in api_response["data"]:
                await message.answer(f"❌ Неверный ответ от API: отсутствует taskId\n<pre>{api_response}</pre>")
                await state.clear()
                return
                
            task_id = api_response["data"]["taskId"]
            save_task_chat(task_id, user_id, cost)
            
            # Логируем успешный запрос в БД
            from database import log_user_request
            log_user_request(
                user_telegram_id=user_id,
                user_username=username,
                user_first_name=first_name,
                request_type="nano_banana",
                model_name="pro",
                prompt=prompt,
                image_urls=",".join(image_input) if image_input else None,
                task_id=task_id,
                cost=cost,
                api_response=str(api_response)
            )
            
            logging.info(f"[input_nano_prompt_handler PRO] Задача создана: task_id={task_id}, user_id={user_id}, cost={cost}")
            
            await message.answer(
                f"✅ Задача отправлена!\nTask ID: <code>{task_id}</code>\n"
                "После завершения изображение придет в чат автоматически.\n"
                "Баланс будет списан только при успешной генерации."
            )
            await state.clear()
            return
            
        except Exception as e:
            logging.error(f"[input_nano_prompt_handler PRO] Ошибка: {e}")
            await message.answer(f"❌ Ошибка при создании задачи:\n{e}")
            await state.clear()
            return
    
    # Обработка обычных режимов (generate/edit)
    output_format = user_data.get("output_format", "png")
    image_size = user_data.get("image_size", "auto")  # По умолчанию auto согласно документации
    image_urls = user_data.get("image_urls")
    
    # Проверяем, что размер входит в допустимые значения согласно документации API
    valid_sizes = ["1:1", "9:16", "16:9", "3:4", "4:3", "3:2", "2:3", "5:4", "4:5", "21:9", "auto"]
    if image_size not in valid_sizes:
        image_size = "auto"  # Fallback к auto если размер невалидный
    
    cost = 5  # Фиксированная стоимость
    
    # Проверка баланса
    if get_balance(user_id) < cost:
        await message.answer(f"💸 Недостаточно средств. Ваш баланс: {get_balance(user_id):.2f} токенов")
        await state.clear()
        return

    await message.answer("⏳ Генерация изображения запущена, ждите...")

    try:
        logging.info(f"[input_nano_prompt_handler] Создаем задачу: mode={mode}, prompt='{prompt[:50]}...', image_urls={image_urls}")
        
        api_response = await create_nano_banana_task(
            mode=mode,
            prompt=prompt,
            image_urls=image_urls,
            output_format=output_format,
            image_size=image_size,
            callback_url=f"{CALLBACK_BASE_URL}/nano-banana-callback",
        )
        
        logging.info(f"[input_nano_prompt_handler] Ответ API: {api_response}")

        if api_response is None or api_response.get("code") != 200:
            # Логируем ошибку
            from database import log_user_request
            log_user_request(
                user_telegram_id=user_id,
                user_username=username,
                user_first_name=first_name,
                request_type="nano_banana",
                model_name=mode,
                prompt=prompt,
                image_urls=",".join(image_urls) if image_urls else None,
                cost=cost,
                api_response=str(api_response)
            )
            
            error_msg = api_response.get("msg", "Неизвестная ошибка") if api_response else "Ошибка подключения к API"
            if api_response and "error" in api_response:
                error_msg = api_response["error"]
            await message.answer(f"❌ Не удалось создать задачу:\n{error_msg}")
            await state.clear()
            return

        # Проверяем наличие taskId в ответе
        if "data" not in api_response or "taskId" not in api_response["data"]:
            await message.answer(f"❌ Неверный ответ от API: отсутствует taskId\n<pre>{api_response}</pre>")
            await state.clear()
            return
            
        task_id = api_response["data"]["taskId"]
        save_task_chat(task_id, user_id, cost)
        
        # Логируем успешный запрос
        from database import log_user_request
        log_user_request(
            user_telegram_id=user_id,
            user_username=username,
            user_first_name=first_name,
            request_type="nano_banana",
            model_name=mode,
            prompt=prompt,
            image_urls=",".join(image_urls) if image_urls else None,
            task_id=task_id,
            cost=cost,
            api_response=str(api_response)
        )

        await message.answer(
            f"✅ Задача отправлена!\nTask ID: <code>{task_id}</code>\n"
            "После завершения изображение придет в чат автоматически.\n"
            "Баланс будет списан только при успешной генерации."
        )

    except Exception as e:
        await message.answer(f"❌ Ошибка при создании задачи:\n{e}")

    await state.clear()

async def start_generation_from_message(message: Message, state: FSMContext):
    # Реиспользуем логику с выбором ИИ
    ai_buttons = [
        {"text": "Veo 3", "callback_data": "ai_veo3"},
        {"text": "SORA 2", "callback_data": "ai_sora2"},
        {"text": "Suno", "callback_data": "ai_suno"},
        {"text": "Midjourney", "callback_data": "ai_midjourney"},
    ]
    keyboard = InlineKeyboardMarkup(
        inline_keyboard=[[InlineKeyboardButton(**btn)] for btn in ai_buttons]
    )
    await message.answer("Выберите ИИ для генерации:", reply_markup=keyboard)
    await state.set_state(VideoGenStates.choose_ai)

# --- Обработчики для маркетплейсов ---
@dp.message(MarketsStates.get_photo)
async def markets_get_photo_handler(message: Message, state: FSMContext):
    """Обработчик получения фото для карточки товара"""
    # Игнорируем команду /start
    if message.text and message.text.startswith('/start'):
        await state.clear()
        return
    
    user_id = message.from_user.id
    user_tag = get_user_tag(user_id)
    
    if user_tag != "markets":
        await message.answer("❌ Эта функция доступна только для пользователей маркетплейсов.")
        await state.clear()
        return
    
    # Проверяем наличие фото
    if not message.photo and not (message.document and message.document.mime_type and message.document.mime_type.startswith('image/')):
        await message.answer("❌ Пожалуйста, отправьте изображение.", reply_markup=cancel_keyboard())
        return
    
    try:
        # Получаем URL файла
        if message.photo:
            file_id = message.photo[-1].file_id
        else:
            file_id = message.document.file_id
        
        file = await bot.get_file(file_id)
        file_url = f"https://api.telegram.org/file/bot{TELEGRAM_BOT_TOKEN}/{file.file_path}"
        
        await state.update_data(photo_url=file_url)
        
        await message.answer(
            "✅ Фото получено!\n\n"
            "📝 <b>Шаг 2: Добавьте заголовок</b>\n\n"
            "Введите заголовок товара, который будет отображаться в верхней части изображения.\n\n"
            "💡 <b>Советы по оформлению:</b>\n"
            "• Используйте короткий и запоминающийся текст (до 50 символов)\n"
            "• Выделите главное преимущество или название товара\n"
            "• Избегайте длинных фраз - текст будет наложен поверх изображения\n\n"
            "📋 <b>Примеры хороших заголовков:</b>\n"
            "• \"Стильная куртка с капюшоном\"\n"
            "• \"Удобные кроссовки для спорта\"\n"
            "• \"Премиум качество по доступной цене\"\n\n"
            "Введите заголовок:",
            reply_markup=cancel_keyboard(),
            parse_mode="HTML"
        )
        await state.set_state(MarketsStates.input_title)
    except Exception as e:
        logging.error(f"[markets_get_photo_handler] Ошибка: {e}", exc_info=True)
        await message.answer(
            "❌ Ошибка при обработке изображения. Попробуйте еще раз.",
            reply_markup=main_menu_keyboard(user_tag="markets")
        )
        await state.clear()


@dp.message(MarketsStates.input_title)
async def markets_input_title_handler(message: Message, state: FSMContext):
    """Обработчик ввода заголовка"""
    # Игнорируем команду /start
    if message.text and message.text.startswith('/start'):
        return
    
    user_id = message.from_user.id
    user_tag = get_user_tag(user_id)
    
    if user_tag != "markets":
        await message.answer("❌ Эта функция доступна только для пользователей маркетплейсов.")
        await state.clear()
        return
    
    title = message.text
    if not title:
        await message.answer("❌ Пожалуйста, введите заголовок.", reply_markup=cancel_keyboard())
        return
    
    await state.update_data(title=title)
    
    await message.answer(
        "✅ Заголовок сохранен!\n\n"
        "📝 <b>Шаг 3: Добавьте преимущества товара</b>\n\n"
        "Введите 3-4 преимущества товара, которые будут отображаться в нижней части изображения.\n\n"
        "💡 <b>Как оформить текст:</b>\n"
        "• Каждое преимущество с новой строки ИЛИ через запятую\n"
        "• Используйте короткие фразы (до 40 символов)\n"
        "• Выделите ключевые достоинства товара\n"
        "• Максимум 4 преимущества\n\n"
        "📋 <b>Примеры оформления:</b>\n"
        "<code>Быстрая доставка\nГарантия качества\nЭкологичные материалы</code>\n\n"
        "или\n\n"
        "<code>Быстрая доставка, Гарантия качества, Экологичные материалы</code>\n\n"
        "Введите преимущества:",
        reply_markup=cancel_keyboard(),
        parse_mode="HTML"
    )
    await state.set_state(MarketsStates.input_advantages)

@dp.message(MarketsStates.input_advantages)
async def markets_input_advantages_handler(message: Message, state: FSMContext):
    """Обработчик ввода преимуществ и запуск генерации"""
    # Игнорируем команду /start
    if message.text and message.text.startswith('/start'):
        return
    
    user_id = message.from_user.id
    username = message.from_user.username or "user"
    first_name = message.from_user.first_name or "Пользователь"
    user_tag = get_user_tag(user_id)
    
    if user_tag != "markets":
        await message.answer("❌ Эта функция доступна только для пользователей маркетплейсов.")
        await state.clear()
        return
    
    advantages_text = message.text
    if not advantages_text:
        await message.answer("❌ Пожалуйста, введите преимущества товара.", reply_markup=cancel_keyboard())
        return
    
    # Парсим преимущества (разделяем по переносам строк или запятым)
    advantages = [a.strip() for a in advantages_text.replace('\n', ',').split(',') if a.strip()]
    advantages = advantages[:4]  # Максимум 4 преимущества
    
    if not advantages:
        await message.answer("❌ Не удалось распознать преимущества. Попробуйте еще раз.", reply_markup=cancel_keyboard())
        return
    
    await state.update_data(advantages=advantages)
    
    user_data = await state.get_data()
    product_category = user_data.get("product_category")  # fashion, other
    
    # Логируем для диагностики
    logging.info(f"[markets_input_advantages_handler] user_id={user_id}, product_category={product_category}, advantages={advantages}")
    
    # Если product_category не установлена, устанавливаем "other" по умолчанию
    if not product_category:
        logging.warning(f"[markets_input_advantages_handler] product_category не установлена для user_id={user_id}, устанавливаем 'other' по умолчанию")
        product_category = "other"
        await state.update_data(product_category=product_category)
    
    # Если это модная категория (одежда/обувь/аксессуары), предлагаем выбрать модель
    if product_category == "fashion":
        model_buttons = [
            {"text": "👩 Женщина", "callback_data": "markets_model_woman"},
            {"text": "👨 Мужчина", "callback_data": "markets_model_man"},
            {"text": "👧 Подросток девушка", "callback_data": "markets_model_teen_girl"},
            {"text": "👦 Подросток парень", "callback_data": "markets_model_teen_boy"},
            {"text": "📷 Загрузить свою модель", "callback_data": "markets_model_custom"},
        ]
        keyboard = InlineKeyboardMarkup(
            inline_keyboard=[[InlineKeyboardButton(**btn)] for btn in model_buttons] + 
            [[InlineKeyboardButton(text="❌ Отменить", callback_data="cancel")]]
        )
        
        await message.answer(
            "✅ Преимущества сохранены!\n\n"
            "Выберите модель для примерки:",
            reply_markup=keyboard
        )
        await state.set_state(MarketsStates.choose_model)
        return
    
    # Для другого товара запрашиваем дополнительный промпт
    await message.answer(
        "✅ Преимущества сохранены!\n\n"
        "💡 <b>Шаг 4: Дополнительные условия (необязательно)</b>\n\n"
        "Вы можете указать дополнительные пожелания к оформлению карточки товара.\n\n"
        "🎨 <b>Что можно указать:</b>\n"
        "• <b>Стиль фона:</b> \"белый фон\", \"нейтральный фон\", \"градиент\"\n"
        "• <b>Цветовая гамма:</b> \"пастельные тона\", \"яркие цвета\", \"черно-белое\"\n"
        "• <b>Композиция:</b> \"товар по центру\", \"товар слева\", \"с тенью\"\n"
        "• <b>Освещение:</b> \"мягкий свет\", \"яркое освещение\", \"естественный свет\"\n"
        "• <b>Другие детали:</b> \"минималистичный стиль\", \"премиум вид\"\n\n"
        "📋 <b>Примеры:</b>\n"
        "<code>Белый фон, товар по центру, мягкое освещение</code>\n"
        "<code>Градиент от белого к серому, премиум стиль</code>\n"
        "<code>Нейтральный фон, естественное освещение, минимализм</code>\n\n"
        "Или отправьте /skip чтобы пропустить этот шаг и использовать стандартные настройки.",
        reply_markup=cancel_keyboard(),
        parse_mode="HTML"
    )
    await state.set_state(MarketsStates.input_additional_prompt)

# Функция запуска генерации карточки товара
async def markets_start_generation(message_or_callback, state: FSMContext):
    """Запуск генерации карточки товара"""
    # Определяем тип объекта (Message или CallbackQuery)
    obj_type = type(message_or_callback).__name__
    logging.info(f"[markets_start_generation] Получен объект типа: {obj_type}, hasattr('text'): {hasattr(message_or_callback, 'text')}")
    
    if hasattr(message_or_callback, 'text'):
        # Это Message
        user_id_raw = message_or_callback.from_user.id
        username = message_or_callback.from_user.username or "user"
        first_name = message_or_callback.from_user.first_name or "Пользователь"
        message = message_or_callback
        logging.info(f"[markets_start_generation] Это Message, from_user.id={user_id_raw}, from_user.username={username}")
    else:
        # Это CallbackQuery
        user_id_raw = message_or_callback.from_user.id
        username = message_or_callback.from_user.username or "user"
        first_name = message_or_callback.from_user.first_name or "Пользователь"
        message = message_or_callback.message
        logging.info(f"[markets_start_generation] Это CallbackQuery, callback.from_user.id={user_id_raw}, callback.from_user.username={username}")
        # Дополнительная проверка: если message есть, проверяем его from_user
        if hasattr(message_or_callback, 'message') and message_or_callback.message:
            msg_user_id = message_or_callback.message.from_user.id if message_or_callback.message.from_user else None
            logging.info(f"[markets_start_generation] callback.message.from_user.id={msg_user_id}")
    
    # Преобразуем user_id в int и логируем для диагностики
    user_id = int(user_id_raw)
    logging.info(f"[markets_start_generation] Финальный user_id: {user_id} (raw={user_id_raw}), username={username}, first_name={first_name}")
    
    # Проверяем и создаем пользователя, если его нет в БД
    balance = get_balance(user_id)
    if balance == 0.0:
        # Проверяем, существует ли пользователь в БД
        from database import get_connection
        conn = get_connection()
        cursor = conn.cursor()
        cursor.execute("SELECT telegram_id FROM users WHERE telegram_id = %s", (user_id,))
        user_exists = cursor.fetchone()
        conn.close()
        
        if not user_exists:
            # Пользователь не найден в БД, создаем его
            logging.warning(f"[markets_start_generation] Пользователь {user_id} не найден в БД, создаем...")
            user_tag = get_user_tag(user_id)
            if user_tag == "markets":
                add_user(user_id, username, tag="markets")
            else:
                add_user(user_id, username)
            balance = get_balance(user_id)
            logging.info(f"[markets_start_generation] Пользователь {user_id} создан в БД, баланс: {balance}")
    
    user_data = await state.get_data()
    photo_url = user_data.get("photo_url")
    title = user_data.get("title")
    advantages = user_data.get("advantages", [])
    product_category = user_data.get("product_category")  # fashion, other
    model_type = user_data.get("model_type")  # man, woman, teen_girl, teen_boy
    is_custom_model = user_data.get("is_custom_model", False)
    custom_model_url = user_data.get("custom_model_url")
    additional_prompt = user_data.get("additional_prompt")  # Дополнительный промпт от пользователя
    
    # Валидация обязательных данных
    if not photo_url:
        logging.error(f"[markets_start_generation] Отсутствует photo_url для user_id={user_id}")
        await message.answer("❌ Ошибка: не найдено фото товара. Начните заново.", reply_markup=main_menu_keyboard(user_tag="markets"))
        await state.clear()
        return
    
    if not title:
        logging.error(f"[markets_start_generation] Отсутствует title для user_id={user_id}")
        await message.answer("❌ Ошибка: не найден заголовок. Начните заново.", reply_markup=main_menu_keyboard(user_tag="markets"))
        await state.clear()
        return
    
    if not advantages or len(advantages) == 0:
        logging.error(f"[markets_start_generation] Отсутствуют advantages для user_id={user_id}")
        await message.answer("❌ Ошибка: не найдены преимущества товара. Начните заново.", reply_markup=main_menu_keyboard(user_tag="markets"))
        await state.clear()
        return
    
    # Проверка для fashion категории: должна быть выбрана модель или загружена своя
    if product_category == "fashion":
        if not is_custom_model and not model_type:
            logging.error(f"[markets_start_generation] Для fashion категории не выбрана модель для user_id={user_id}")
            await message.answer("❌ Ошибка: не выбрана модель для примерки. Начните заново.", reply_markup=main_menu_keyboard(user_tag="markets"))
            await state.clear()
            return
        if is_custom_model and not custom_model_url:
            logging.error(f"[markets_start_generation] Выбрана custom модель, но отсутствует custom_model_url для user_id={user_id}")
            await message.answer("❌ Ошибка: не загружено фото модели. Начните заново.", reply_markup=main_menu_keyboard(user_tag="markets"))
            await state.clear()
            return
    
    # Расширяем промпт для генерации карточки товара с текстом
    advantages_text = "\n".join([f"• {adv}" for adv in advantages])
    
    # Формируем список изображений для загрузки
    image_input_list = [photo_url] if photo_url else []
    
    # Если это модная категория и выбрана модель, добавляем фото модели
    model_prompt_part = ""
    environment_prompt = ""
    
    if product_category == "fashion" and (model_type or is_custom_model):
        if is_custom_model and custom_model_url:
            # Используем загруженную пользователем модель
            image_input_list.append(custom_model_url)
            model_prompt_part = "Примерь этот товар на модель из загруженного фото. "
            logging.info(f"[markets] Используется загруженная пользователем модель: {custom_model_url}")
        elif model_type:
            model_names = {
                "man": "мужчина-модель",
                "woman": "женщина-модель",
                "teen_girl": "подросток-девочка",
                "teen_boy": "подросток-мальчик"
            }
            model_prompt_part = f"Примерь этот товар на {model_names[model_type]}. "
            
            # Пытаемся получить URL фото модели
            model_image_url = await get_model_image_url(model_type)
            if model_image_url:
                image_input_list.append(model_image_url)
                logging.info(f"[markets] Добавлено фото модели {model_type}: {model_image_url}")
            else:
                logging.warning(f"[markets] Фото модели {model_type} не найдено, используем только промпт")
        
        # Базовый environment_prompt, который может быть переопределен пользователем
        default_environment_prompt = "Помести модель в профессиональный шоурум или студию с хорошим освещением, белым или нейтральным фоном. "
        
        # Если пользователь указал дополнительный промпт, используем его вместо стандартного окружения
        if additional_prompt:
            environment_prompt = additional_prompt + " "
            logging.info(f"[markets_start_generation] Используется пользовательский промпт для окружения: {additional_prompt[:100]}...")
        else:
            environment_prompt = default_environment_prompt
    else:
        environment_prompt = ""
    
    # Базовый промпт
    base_prompt = (
        f"Создай профессиональное изображение для карточки товара маркетплейса. "
    )
    
    # Добавляем информацию о примерке для модной категории
    if product_category == "fashion" and (model_type or is_custom_model):
        base_prompt += model_prompt_part + environment_prompt
    
    # Добавляем требования
    # Если пользователь указал дополнительный промпт с описанием окружения, делаем требования более гибкими
    if additional_prompt and product_category == "fashion":
        base_prompt += (
            f"Требования: высокое качество, товар в фокусе, без водяных знаков и логотипов. "
            f"Изображение должно соответствовать требованиям маркетплейсов Wildberries и Ozon. "
            f"Разрешение минимум 1000x1000 пикселей, формат квадратный или вертикальный.\n\n"
        )
    else:
        base_prompt += (
            f"Требования: чистое изображение товара на белом или нейтральном фоне, "
            f"высокое качество, товар в фокусе, без водяных знаков и логотипов. "
            f"Изображение должно соответствовать требованиям маркетплейсов Wildberries и Ozon. "
            f"Разрешение минимум 1000x1000 пикселей, формат квадратный или вертикальный.\n\n"
        )
    
    # Добавляем текст
    expanded_prompt = base_prompt + (
        f"ВАЖНО: Добавь на изображение текст на русском языке:\n"
        f"Заголовок (вверху изображения с полупрозрачной темной подложкой): {title}\n"
        f"Преимущества товара (внизу изображения с полупрозрачной темной подложкой, каждое на отдельной строке):\n{advantages_text}\n"
        f"Текст должен быть четким, читаемым, белого цвета на темной полупрозрачной подложке. "
        f"Заголовок размести в верхней части изображения, преимущества - в нижней части."
    )
    
    # Для категории "other" добавляем дополнительный промпт пользователя, если он указан
    # (для fashion он уже добавлен в environment_prompt выше)
    if product_category == "other" and additional_prompt:
        expanded_prompt += f"\n\nДополнительные требования пользователя: {additional_prompt}"
        logging.info(f"[markets_start_generation] Добавлен дополнительный промпт для other категории: {additional_prompt[:100]}...")
    
    # Логируем финальный промпт для отладки
    logging.info(f"[markets_start_generation] Финальный промпт (первые 500 символов): {expanded_prompt[:500]}...")
    logging.info(f"[markets_start_generation] Длина промпта: {len(expanded_prompt)} символов")
    logging.info(f"[markets_start_generation] product_category={product_category}, additional_prompt={additional_prompt}, model_type={model_type}, is_custom_model={is_custom_model}")
    
    # Стоимость генерации карточки товара для маркетплейсов
    base_cost = 85  # Базовая стоимость 85₽
    cost = base_cost
    
    # Используем стандартную функцию get_balance для получения баланса
    # Она правильно обрабатывает все типы данных и преобразования
    # user_id уже преобразован в int выше (строка 4542)
    balance = get_balance(user_id)
    
    logging.info(f"[markets_start_generation] user_id={user_id}, balance={balance} (из get_balance), cost={cost}, base_cost={base_cost}")
    
    # Проверка баланса
    if balance < cost:
        await message.answer(f"💸 Недостаточно средств. Ваш баланс: {balance:.2f} токенов. Требуется: {cost:.2f} токенов")
        await state.clear()
        return
    
    # Логируем информацию об изображениях для диагностики
    logging.info(f"[markets_start_generation] Отправляем {len(image_input_list)} изображений: {image_input_list}")
    
    await message.answer("⏳ Генерация изображения запущена, ждите...")
    
    try:
        # Создаем задачу nano banana pro для лучшего качества
        api_response = await create_nano_banana_pro_task(
            prompt=expanded_prompt,
            aspect_ratio="1:1",  # Квадратный формат для маркетплейсов
            resolution="2K",  # Высокое разрешение
            output_format="jpg",
            image_input=image_input_list if image_input_list else None,
            callback_url=f"{CALLBACK_BASE_URL}/nano-banana-callback",
        )
        
        if api_response is None or api_response.get("code") != 200:
            error_msg = api_response.get("msg", "Неизвестная ошибка") if api_response else "Нет ответа от API"
            await message.answer(f"❌ Ошибка при создании задачи:\n{error_msg}")
            await state.clear()
            return
        
        task_id = api_response["data"]["taskId"]
        save_task_chat(task_id, user_id, cost)
        
        # Сохраняем данные для последующей обработки (наложение текста)
        await state.update_data(
            task_id=task_id,
            cost=cost
        )
        
        # Сохраняем title и advantages в JSON для последующей обработки
        import json
        markets_data = {
            "title": title,
            "advantages": advantages,
        }
        markets_data_json = json.dumps(markets_data, ensure_ascii=False)
        
        # Логируем запрос
        from database import log_user_request
        log_user_request(
            user_telegram_id=user_id,
            user_username=username,
            user_first_name=first_name,
            request_type="markets_card",
            model_name="nano_banana_pro",
            prompt=markets_data_json,  # Сохраняем JSON с title и advantages
            image_urls=photo_url,
            task_id=task_id,
            cost=cost,
            api_response=str(api_response)
        )
        
        await message.answer(
            f"✅ Задача отправлена!\nTask ID: <code>{task_id}</code>\n"
            "После завершения генерации изображение будет обработано и на него будут наложены заголовок и преимущества.\n"
            "Баланс будет списан только при успешной генерации.",
            parse_mode="HTML"
        )
        await state.clear()
        
    except Exception as e:
        logging.error(f"[markets_start_generation] Ошибка: {e}", exc_info=True)
        await message.answer(
            "❌ Ошибка при создании задачи. Попробуйте еще раз.",
            reply_markup=main_menu_keyboard(user_tag="markets")
        )
        await state.clear()

# Обработчик выбора модели
@dp.callback_query(MarketsStates.choose_model, F.data.startswith("markets_model_"))
async def markets_choose_model_callback(callback: CallbackQuery, state: FSMContext):
    """Обработчик выбора модели"""
    try:
        await callback.answer()
    except TelegramBadRequest as e:
        if "query is too old" in str(e).lower() or "query id is invalid" in str(e).lower():
            logging.warning(f"[markets_choose_model_callback] Callback query устарел: {e}")
            return
        raise
    
    model_data = callback.data.split("_")[-1]  # woman, man, teen_girl, teen_boy, custom
    
    if model_data == "custom":
        # Запрашиваем фото своей модели
        await callback.message.answer(
            "📷 <b>Загрузка своей модели</b>\n\n"
            "Отправьте фото модели (полный рост, нейтральный фон):",
            reply_markup=cancel_keyboard(),
            parse_mode="HTML"
        )
        await state.set_state(MarketsStates.get_custom_model)
        return
    
    # Сохраняем выбранную модель
    await state.update_data(model_type=model_data, is_custom_model=False)
    
    # Запрашиваем дополнительный промпт перед генерацией
    await callback.message.answer(
        "✅ Модель выбрана!\n\n"
        "💡 <b>Шаг 4: Дополнительные условия (необязательно)</b>\n\n"
        "Вы можете указать дополнительные пожелания к оформлению карточки товара.\n\n"
        "🎨 <b>Что можно указать:</b>\n"
        "• <b>Стиль фона:</b> \"белый фон\", \"нейтральный фон\", \"градиент\"\n"
        "• <b>Цветовая гамма:</b> \"пастельные тона\", \"яркие цвета\", \"черно-белое\"\n"
        "• <b>Композиция:</b> \"товар по центру\", \"товар слева\", \"с тенью\"\n"
        "• <b>Освещение:</b> \"мягкий свет\", \"яркое освещение\", \"естественный свет\"\n"
        "• <b>Другие детали:</b> \"минималистичный стиль\", \"премиум вид\"\n\n"
        "📋 <b>Примеры:</b>\n"
        "<code>Белый фон, товар по центру, мягкое освещение</code>\n"
        "<code>Градиент от белого к серому, премиум стиль</code>\n"
        "<code>Нейтральный фон, естественное освещение, минимализм</code>\n\n"
        "Или отправьте /skip чтобы пропустить этот шаг и использовать стандартные настройки.",
        reply_markup=cancel_keyboard(),
        parse_mode="HTML"
    )
    await state.set_state(MarketsStates.input_additional_prompt)

@dp.message(MarketsStates.get_custom_model)
async def markets_get_custom_model_handler(message: Message, state: FSMContext):
    """Обработчик получения фото своей модели"""
    # Игнорируем команду /start
    if message.text and message.text.startswith('/start'):
        await state.clear()
        return
    
    user_id = message.from_user.id
    user_tag = get_user_tag(user_id)
    
    if user_tag != "markets":
        await message.answer("❌ Эта функция доступна только для пользователей маркетплейсов.")
        await state.clear()
        return
    
    # Проверяем наличие фото
    if not message.photo and not (message.document and message.document.mime_type and message.document.mime_type.startswith('image/')):
        await message.answer("❌ Пожалуйста, отправьте изображение модели.", reply_markup=cancel_keyboard())
        return
    
    try:
        # Получаем URL файла
        if message.photo:
            file_id = message.photo[-1].file_id
        else:
            file_id = message.document.file_id
        
        file = await bot.get_file(file_id)
        file_url = f"https://api.telegram.org/file/bot{TELEGRAM_BOT_TOKEN}/{file.file_path}"
        
        await state.update_data(custom_model_url=file_url, is_custom_model=True)
        
        # Запрашиваем дополнительный промпт перед генерацией
        await message.answer(
            "✅ Фото модели получено!\n\n"
            "💡 <b>Шаг 4: Дополнительные условия (необязательно)</b>\n\n"
            "Вы можете указать дополнительные пожелания к оформлению карточки товара.\n\n"
            "🎨 <b>Что можно указать:</b>\n"
            "• <b>Стиль фона:</b> \"белый фон\", \"нейтральный фон\", \"градиент\"\n"
            "• <b>Цветовая гамма:</b> \"пастельные тона\", \"яркие цвета\", \"черно-белое\"\n"
            "• <b>Композиция:</b> \"товар по центру\", \"товар слева\", \"с тенью\"\n"
            "• <b>Освещение:</b> \"мягкий свет\", \"яркое освещение\", \"естественный свет\"\n"
            "• <b>Другие детали:</b> \"минималистичный стиль\", \"премиум вид\"\n\n"
            "📋 <b>Примеры:</b>\n"
            "<code>Белый фон, товар по центру, мягкое освещение</code>\n"
            "<code>Градиент от белого к серому, премиум стиль</code>\n"
            "<code>Нейтральный фон, естественное освещение, минимализм</code>\n\n"
            "Или отправьте /skip чтобы пропустить этот шаг и использовать стандартные настройки.",
            reply_markup=cancel_keyboard(),
            parse_mode="HTML"
        )
        await state.set_state(MarketsStates.input_additional_prompt)
    except Exception as e:
        logging.error(f"[markets_get_custom_model_handler] Ошибка: {e}", exc_info=True)
        await message.answer(
            "❌ Ошибка при обработке изображения модели. Попробуйте еще раз.",
            reply_markup=main_menu_keyboard(user_tag="markets")
        )
        await state.clear()

# --- Функции выполнения операций после дополнительного промпта ---
async def execute_bg_replace_reference(message: Message, state: FSMContext, additional_prompt: str = None):
    """Выполнение замены фона на референс"""
    user_id = message.from_user.id
    user_data = await state.get_data()
    product_photo_url = user_data.get("product_photo_url")
    background_reference_url = user_data.get("background_reference_url")
    
    cost = 40
    balance = get_balance(user_id)
    
    if balance < cost:
        await message.answer(f"💸 Недостаточно средств. Ваш баланс: {balance:.2f}₽. Требуется: {cost:.2f}₽")
        await state.clear()
        return
    
    await message.answer("⏳ Замена фона запущена, ждите...")
    
    # Формируем базовый промпт
    prompt = "Замени фон на изображение из референса, сохранив товар на переднем плане. Товар должен быть четко виден и в фокусе."
    
    # Добавляем дополнительный промпт, если указан
    if additional_prompt:
        prompt += f" {additional_prompt}"
    
    try:
        api_response = await create_nano_banana_task(
            mode="edit",
            prompt=prompt,
            image_urls=[product_photo_url, background_reference_url],
            callback_url=f"{CALLBACK_BASE_URL}/nano-banana-callback",
        )
        
        if api_response is None or api_response.get("code") != 200:
            error_msg = api_response.get("msg", "Неизвестная ошибка") if api_response else "Нет ответа от API"
            await message.answer(f"❌ Ошибка при создании задачи:\n{error_msg}")
            await state.clear()
            return
        
        task_id = api_response["data"]["taskId"]
        save_task_chat(task_id, user_id, cost)
        
        from database import log_user_request
        log_user_request(
            user_telegram_id=user_id,
            user_username=message.from_user.username or "user",
            user_first_name=message.from_user.first_name or "Пользователь",
            request_type="markets_bg_replace",
            model_name="nano_banana_edit",
            prompt=prompt,
            image_urls=f"{product_photo_url},{background_reference_url}",
            task_id=task_id,
            cost=cost,
            api_response=str(api_response)
        )
        
        await message.answer(
            f"✅ Задача отправлена!\nTask ID: <code>{task_id}</code>\n"
            "После завершения обработки изображение придет в чат автоматически.\n"
            "Баланс будет списан только при успешной обработке."
        )
        await state.clear()
    except Exception as e:
        logging.error(f"[execute_bg_replace_reference] Ошибка: {e}", exc_info=True)
        await message.answer("❌ Ошибка при создании задачи. Попробуйте еще раз.", reply_markup=cancel_keyboard())
        await state.clear()

async def execute_bg_replace_solid(message: Message, state: FSMContext, additional_prompt: str = None):
    """Выполнение замены фона на однотонный цвет"""
    user_id = message.from_user.id
    user_data = await state.get_data()
    product_photo_url = user_data.get("product_photo_url")
    background_color = user_data.get("background_color")
    
    cost = 40
    balance = get_balance(user_id)
    
    if balance < cost:
        await message.answer(f"💸 Недостаточно средств. Ваш баланс: {balance:.2f}₽. Требуется: {cost:.2f}₽")
        await state.clear()
        return
    
    await message.answer("⏳ Замена фона запущена, ждите...")
    
    prompt = f"Замени фон на однотонный цвет: {background_color}. Товар должен остаться четко видимым на новом фоне."
    if additional_prompt:
        prompt += f" {additional_prompt}"
    
    try:
        api_response = await create_nano_banana_task(
            mode="edit",
            prompt=prompt,
            image_urls=[product_photo_url],
            callback_url=f"{CALLBACK_BASE_URL}/nano-banana-callback",
        )
        
        if api_response is None or api_response.get("code") != 200:
            error_msg = api_response.get("msg", "Неизвестная ошибка") if api_response else "Нет ответа от API"
            await message.answer(f"❌ Ошибка при создании задачи:\n{error_msg}")
            await state.clear()
            return
        
        task_id = api_response["data"]["taskId"]
        save_task_chat(task_id, user_id, cost)
        
        from database import log_user_request
        log_user_request(
            user_telegram_id=user_id,
            user_username=message.from_user.username or "user",
            user_first_name=message.from_user.first_name or "Пользователь",
            request_type="markets_bg_replace",
            model_name="nano_banana_edit",
            prompt=prompt,
            image_urls=product_photo_url,
            task_id=task_id,
            cost=cost,
            api_response=str(api_response)
        )
        
        await message.answer(
            f"✅ Задача отправлена!\nTask ID: <code>{task_id}</code>\n"
            "После завершения обработки изображение придет в чат автоматически.\n"
            "Баланс будет списан только при успешной обработке."
        )
        await state.clear()
    except Exception as e:
        logging.error(f"[execute_bg_replace_solid] Ошибка: {e}", exc_info=True)
        await message.answer("❌ Ошибка при создании задачи. Попробуйте еще раз.", reply_markup=cancel_keyboard())
        await state.clear()

async def execute_bg_replace_gradient(message: Message, state: FSMContext, additional_prompt: str = None):
    """Выполнение замены фона на градиент"""
    user_id = message.from_user.id
    user_data = await state.get_data()
    product_photo_url = user_data.get("product_photo_url")
    gradient_prompt = user_data.get("gradient_prompt")
    gradient_shape = user_data.get("gradient_shape")
    
    cost = 40
    balance = get_balance(user_id)
    
    if balance < cost:
        await message.answer(f"💸 Недостаточно средств. Ваш баланс: {balance:.2f}₽. Требуется: {cost:.2f}₽")
        await state.clear()
        return
    
    await message.answer("⏳ Замена фона запущена, ждите...")
    
    shape_names = {
        "radial": "радиальный",
        "horizontal": "горизонтальный",
        "vertical": "вертикальный"
    }
    prompt = f"Замени фон на {shape_names.get(gradient_shape, gradient_shape)} градиент: {gradient_prompt}. Товар должен остаться четко видимым на новом фоне."
    if additional_prompt:
        prompt += f" {additional_prompt}"
    
    try:
        api_response = await create_nano_banana_task(
            mode="edit",
            prompt=prompt,
            image_urls=[product_photo_url],
            callback_url=f"{CALLBACK_BASE_URL}/nano-banana-callback",
        )
        
        if api_response is None or api_response.get("code") != 200:
            error_msg = api_response.get("msg", "Неизвестная ошибка") if api_response else "Нет ответа от API"
            await message.answer(f"❌ Ошибка при создании задачи:\n{error_msg}")
            await state.clear()
            return
        
        task_id = api_response["data"]["taskId"]
        save_task_chat(task_id, user_id, cost)
        
        from database import log_user_request
        log_user_request(
            user_telegram_id=user_id,
            user_username=message.from_user.username or "user",
            user_first_name=message.from_user.first_name or "Пользователь",
            request_type="markets_bg_replace",
            model_name="nano_banana_edit",
            prompt=prompt,
            image_urls=product_photo_url,
            task_id=task_id,
            cost=cost,
            api_response=str(api_response)
        )
        
        await message.answer(
            f"✅ Задача отправлена!\nTask ID: <code>{task_id}</code>\n"
            "После завершения обработки изображение придет в чат автоматически.\n"
            "Баланс будет списан только при успешной обработке."
        )
        await state.clear()
    except Exception as e:
        logging.error(f"[execute_bg_replace_gradient] Ошибка: {e}", exc_info=True)
        await message.answer("❌ Ошибка при создании задачи. Попробуйте еще раз.", reply_markup=cancel_keyboard())
        await state.clear()

async def execute_bg_generate(message: Message, state: FSMContext, additional_prompt: str = None):
    """Выполнение генерации фона по промпту"""
    user_id = message.from_user.id
    user_data = await state.get_data()
    background_prompt = user_data.get("background_prompt")
    
    cost = 20
    balance = get_balance(user_id)
    
    if balance < cost:
        await message.answer(f"💸 Недостаточно средств. Ваш баланс: {balance:.2f}₽. Требуется: {cost:.2f}₽")
        await state.clear()
        return
    
    await message.answer("⏳ Генерация фона запущена, ждите...")
    
    prompt = background_prompt
    if additional_prompt:
        prompt += f" {additional_prompt}"
    
    try:
        api_response = await create_nano_banana_task(
            mode="generate",
            prompt=prompt,
            image_urls=None,
            callback_url=f"{CALLBACK_BASE_URL}/nano-banana-callback",
        )
        
        if api_response is None or api_response.get("code") != 200:
            error_msg = api_response.get("msg", "Неизвестная ошибка") if api_response else "Нет ответа от API"
            await message.answer(f"❌ Ошибка при создании задачи:\n{error_msg}")
            await state.clear()
            return
        
        task_id = api_response["data"]["taskId"]
        save_task_chat(task_id, user_id, cost)
        
        from database import log_user_request
        log_user_request(
            user_telegram_id=user_id,
            user_username=message.from_user.username or "user",
            user_first_name=message.from_user.first_name or "Пользователь",
            request_type="markets_bg_generate",
            model_name="nano_banana_generate",
            prompt=prompt,
            image_urls=None,
            task_id=task_id,
            cost=cost,
            api_response=str(api_response)
        )
        
        await message.answer(
            f"✅ Задача отправлена!\nTask ID: <code>{task_id}</code>\n"
            "После завершения генерации фон придет в чат автоматически.\n"
            "Баланс будет списан только при успешной генерации."
        )
        await state.clear()
    except Exception as e:
        logging.error(f"[execute_bg_generate] Ошибка: {e}", exc_info=True)
        await message.answer("❌ Ошибка при создании задачи. Попробуйте еще раз.", reply_markup=cancel_keyboard())
        await state.clear()

async def execute_model_generate(message: Message, state: FSMContext, additional_prompt: str = None):
    """Выполнение генерации нейро модели"""
    user_id = message.from_user.id
    user_data = await state.get_data()
    model_age_type = user_data.get("model_age_type")
    
    cost = 60
    balance = get_balance(user_id)
    
    if balance < cost:
        await message.answer(f"💸 Недостаточно средств. Ваш баланс: {balance:.2f}₽. Требуется: {cost:.2f}₽")
        await state.clear()
        return
    
    await message.answer("⏳ Генерация модели запущена, ждите...")
    
    age_names = {
        "man": "мужчина",
        "woman": "женщина",
        "boy": "парень",
        "girl": "девушка",
        "child_boy": "мальчик",
        "child_girl": "девочка",
        "baby": "младенец"
    }
    prompt = f"Создай профессиональное фото {age_names.get(model_age_type, model_age_type)}-модели, полный рост, нейтральный фон, студийное освещение, высокое качество. Модель должна быть готова для примерки одежды."
    if additional_prompt:
        prompt += f" {additional_prompt}"
    
    try:
        api_response = await create_nano_banana_task(
            mode="generate",
            prompt=prompt,
            image_urls=None,
            callback_url=f"{CALLBACK_BASE_URL}/nano-banana-callback",
        )
        
        if api_response is None or api_response.get("code") != 200:
            error_msg = api_response.get("msg", "Неизвестная ошибка") if api_response else "Нет ответа от API"
            await message.answer(f"❌ Ошибка при создании задачи:\n{error_msg}")
            await state.clear()
            return
        
        task_id = api_response["data"]["taskId"]
        save_task_chat(task_id, user_id, cost)
        
        from database import log_user_request
        log_user_request(
            user_telegram_id=user_id,
            user_username=message.from_user.username or "user",
            user_first_name=message.from_user.first_name or "Пользователь",
            request_type="markets_model_generate",
            model_name="nano_banana_generate",
            prompt=prompt,
            image_urls=None,
            task_id=task_id,
            cost=cost,
            api_response=str(api_response)
        )
        
        await message.answer(
            f"✅ Задача отправлена!\nTask ID: <code>{task_id}</code>\n"
            "После завершения генерации модель придет в чат автоматически.\n"
            "Баланс будет списан только при успешной генерации."
        )
        await state.clear()
    except Exception as e:
        logging.error(f"[execute_model_generate] Ошибка: {e}", exc_info=True)
        await message.answer("❌ Ошибка при создании задачи. Попробуйте еще раз.", reply_markup=cancel_keyboard())
        await state.clear()

async def execute_composite(message: Message, state: FSMContext, additional_prompt: str = None):
    """Выполнение совмещения фото товара и фона"""
    user_id = message.from_user.id
    user_data = await state.get_data()
    product_url = user_data.get("composite_product_url")
    background_url = user_data.get("composite_background_url")
    
    cost = 75
    balance = get_balance(user_id)
    
    if balance < cost:
        await message.answer(f"💸 Недостаточно средств. Ваш баланс: {balance:.2f}₽. Требуется: {cost:.2f}₽")
        await state.clear()
        return
    
    await message.answer("⏳ Совмещение изображений запущено, ждите...")
    
    prompt = "Размести товар на новом фоне. Товар должен быть четко виден и естественно вписан в фон. Сохрани реалистичность и качество обоих изображений."
    if additional_prompt:
        prompt += f" {additional_prompt}"
    
    try:
        api_response = await create_nano_banana_task(
            mode="edit",
            prompt=prompt,
            image_urls=[product_url, background_url],
            callback_url=f"{CALLBACK_BASE_URL}/nano-banana-callback",
        )
        
        if api_response is None or api_response.get("code") != 200:
            error_msg = api_response.get("msg", "Неизвестная ошибка") if api_response else "Нет ответа от API"
            await message.answer(f"❌ Ошибка при создании задачи:\n{error_msg}")
            await state.clear()
            return
        
        task_id = api_response["data"]["taskId"]
        save_task_chat(task_id, user_id, cost)
        
        from database import log_user_request
        log_user_request(
            user_telegram_id=user_id,
            user_username=message.from_user.username or "user",
            user_first_name=message.from_user.first_name or "Пользователь",
            request_type="markets_composite",
            model_name="nano_banana_edit",
            prompt=prompt,
            image_urls=f"{product_url},{background_url}",
            task_id=task_id,
            cost=cost,
            api_response=str(api_response)
        )
        
        await message.answer(
            f"✅ Задача отправлена!\nTask ID: <code>{task_id}</code>\n"
            "После завершения обработки изображение придет в чат автоматически.\n"
            "Баланс будет списан только при успешной обработке."
        )
        await state.clear()
    except Exception as e:
        logging.error(f"[execute_composite] Ошибка: {e}", exc_info=True)
        await message.answer("❌ Ошибка при создании задачи. Попробуйте еще раз.", reply_markup=cancel_keyboard())
        await state.clear()

@dp.message(MarketsStates.input_additional_prompt)
async def markets_input_additional_prompt_handler(message: Message, state: FSMContext):
    """Обработчик ввода дополнительного промпта"""
    # Игнорируем команду /start
    if message.text and message.text.startswith('/start'):
        return
    
    user_id = message.from_user.id
    user_tag = get_user_tag(user_id)
    
    if user_tag != "markets":
        await message.answer("❌ Эта функция доступна только для пользователей маркетплейсов.")
        await state.clear()
        return
    
    # Получаем тип операции из state
    user_data = await state.get_data()
    operation_type = user_data.get("operation_type")
    
    # Проверяем, пропущен ли шаг
    additional_prompt = None
    if message.text and message.text.strip().lower() in ['/skip', 'skip', 'пропустить', 'пропустить шаг', 'пропустить этот шаг']:
        await state.update_data(additional_prompt=None)
        await message.answer("⏭️ Шаг пропущен, запускаем генерацию...")
    elif message.text and message.text.strip():
        # Сохраняем промпт
        additional_prompt = message.text.strip()
        await state.update_data(additional_prompt=additional_prompt)
        preview = additional_prompt[:100] + "..." if len(additional_prompt) > 100 else additional_prompt
        await message.answer(f"✅ Дополнительные условия сохранены:\n{preview}\n\n⏳ Запускаем генерацию...")
    else:
        # Пустое сообщение - тоже пропускаем
        await state.update_data(additional_prompt=None)
        await message.answer("⏭️ Дополнительные условия не указаны, запускаем генерацию...")
    
    # В зависимости от типа операции вызываем соответствующий обработчик
    if operation_type:
        if operation_type == "bg_replace_reference":
            await execute_bg_replace_reference(message, state, additional_prompt)
        elif operation_type == "bg_replace_solid":
            await execute_bg_replace_solid(message, state, additional_prompt)
        elif operation_type == "bg_replace_gradient":
            await execute_bg_replace_gradient(message, state, additional_prompt)
        elif operation_type == "bg_generate":
            await execute_bg_generate(message, state, additional_prompt)
        elif operation_type == "model_generate":
            await execute_model_generate(message, state, additional_prompt)
        elif operation_type == "composite":
            await execute_composite(message, state, additional_prompt)
        else:
            # Стандартная генерация карточки товара
            await markets_start_generation(message, state)
    else:
        # Стандартная генерация карточки товара (если operation_type не указан)
        await markets_start_generation(message, state)

@dp.message(MarketsStates.get_video_image)
async def markets_get_video_image_handler(message: Message, state: FSMContext):
    """Обработчик получения изображения для видеообложки"""
    # Игнорируем команду /start
    if message.text and message.text.startswith('/start'):
        await state.clear()
        return
    
    user_id = message.from_user.id
    user_tag = get_user_tag(user_id)
    
    if user_tag != "markets":
        await message.answer("❌ Эта функция доступна только для пользователей маркетплейсов.")
        await state.clear()
        return
    
    # Проверяем наличие фото
    if not message.photo and not (message.document and message.document.mime_type and message.document.mime_type.startswith('image/')):
        await message.answer("❌ Пожалуйста, отправьте изображение.", reply_markup=cancel_keyboard())
        return
    
    try:
        # Получаем URL файла
        if message.photo:
            file_id = message.photo[-1].file_id
        else:
            file_id = message.document.file_id
        
        file = await bot.get_file(file_id)
        file_url = f"https://api.telegram.org/file/bot{TELEGRAM_BOT_TOKEN}/{file.file_path}"
        
        await state.update_data(video_image_url=file_url)
        
        await message.answer(
            "✅ Изображение получено!\n\n"
            "Теперь введите промпт для создания видеообложки:",
            reply_markup=cancel_keyboard()
        )
        await state.set_state(MarketsStates.input_video_prompt)
    except Exception as e:
        logging.error(f"[markets_get_video_image_handler] Ошибка: {e}", exc_info=True)
        await message.answer(
            "❌ Ошибка при обработке изображения. Попробуйте еще раз.",
            reply_markup=main_menu_keyboard(user_tag="markets")
        )
        await state.clear()

@dp.message(MarketsStates.input_video_prompt)
async def markets_input_video_prompt_handler(message: Message, state: FSMContext):
    """Обработчик ввода промпта для видеообложки"""
    # Игнорируем команду /start
    if message.text and message.text.startswith('/start'):
        return
    
    user_id_raw = message.from_user.id
    username = message.from_user.username or "user"
    first_name = message.from_user.first_name or "Пользователь"
    
    # Преобразуем user_id в int сразу
    user_id = int(user_id_raw)
    user_tag = get_user_tag(user_id)
    
    logging.info(f"[markets_input_video_prompt_handler] Получен user_id: raw={user_id_raw}, int={user_id}")
    
    if user_tag != "markets":
        await message.answer("❌ Эта функция доступна только для пользователей маркетплейсов.")
        await state.clear()
        return
    
    prompt = message.text
    if not prompt:
        await message.answer("❌ Пожалуйста, введите промпт.", reply_markup=cancel_keyboard())
        return
    
    user_data = await state.get_data()
    image_url = user_data.get("video_image_url")
    
    # Проверка наличия изображения
    if not image_url:
        await message.answer("❌ Ошибка: не найдено изображение для генерации видео. Начните заново.", reply_markup=cancel_keyboard())
        await state.clear()
        return
    
    # Стоимость генерации видеообложки для маркетплейсов
    base_cost = 160  # Базовая стоимость 160₽
    cost = base_cost
    
    # Используем стандартную функцию get_balance для получения баланса
    balance = get_balance(user_id)
    
    logging.info(f"[markets_input_video_prompt_handler] user_id={user_id}, balance={balance} (из get_balance), cost={cost}, base_cost={base_cost}")
    
    # Проверка баланса
    if balance < cost:
        await message.answer(f"💸 Недостаточно средств. Ваш баланс: {balance:.2f} токенов. Требуется: {cost:.2f} токенов")
        await state.clear()
        return
    
    await message.answer("⏳ Генерация видеообложки запущена, ждите...")
    
    try:
        # Используем Grok Imagine для создания видео
        api_response = await create_grok_imagine_video_task(
            image_urls=[image_url],
            prompt=prompt,
            callback_url=f"{CALLBACK_BASE_URL}/grok-imagine-callback",
            model="grok-imagine/image-to-video"
        )
        
        if api_response is None or api_response.get("code") != 200:
            error_msg = api_response.get("msg", "Неизвестная ошибка") if api_response else "Нет ответа от API"
            await message.answer(f"❌ Ошибка при создании задачи:\n{error_msg}")
            await state.clear()
            return
        
        task_id = api_response["data"]["taskId"]
        save_task_chat(task_id, user_id, cost)
        
        # Логируем запрос
        from database import log_user_request
        log_user_request(
            user_telegram_id=user_id,
            user_username=username,
            user_first_name=first_name,
            request_type="markets_video",
            model_name="grok_imagine",
            prompt=prompt,
            image_urls=image_url,
            task_id=task_id,
            cost=cost,
            api_response=str(api_response)
        )
        
        await message.answer(
            f"✅ Задача отправлена!\nTask ID: <code>{task_id}</code>\n"
            "После завершения видео придет в чат автоматически.\n"
            "Баланс будет списан только при успешной генерации."
        )
        await state.clear()
        
    except Exception as e:
        logging.error(f"[markets_input_video_prompt_handler] Ошибка: {e}", exc_info=True)
        await message.answer(
            "❌ Ошибка при создании задачи. Попробуйте еще раз.",
            reply_markup=main_menu_keyboard(user_tag="markets")
        )
        await state.clear()

@dp.message(MarketsStates.get_upscale_image)
async def markets_get_upscale_image_handler(message: Message, state: FSMContext):
    """Обработчик получения изображения для улучшения качества"""
    user_id_raw = message.from_user.id
    username = message.from_user.username or "user"
    first_name = message.from_user.first_name or "Пользователь"
    
    # Преобразуем user_id в int сразу
    user_id = int(user_id_raw)
    user_tag = get_user_tag(user_id)
    
    logging.info(f"[markets_get_upscale_image_handler] Получен user_id: raw={user_id_raw}, int={user_id}")
    
    if user_tag != "markets":
        await message.answer("❌ Эта функция доступна только для пользователей маркетплейсов.")
        await state.clear()
        return
    
    # Проверяем наличие фото
    if not message.photo and not (message.document and message.document.mime_type and message.document.mime_type.startswith('image/')):
        await message.answer("❌ Пожалуйста, отправьте изображение.", reply_markup=cancel_keyboard())
        return
    
    try:
        # Получаем URL файла
        if message.photo:
            file_id = message.photo[-1].file_id
        else:
            file_id = message.document.file_id
        
        file = await bot.get_file(file_id)
        file_url = f"https://api.telegram.org/file/bot{TELEGRAM_BOT_TOKEN}/{file.file_path}"
        
        # Получаем базовую стоимость upscale и увеличиваем на 25%
        base_cost = 8  # Базовая стоимость upscale 1x
        cost = base_cost * 1.25  # +25%
        
        # Используем стандартную функцию get_balance для получения баланса
        balance = get_balance(user_id)
        
        logging.info(f"[markets_get_upscale_image_handler] user_id={user_id}, balance={balance} (из get_balance), cost={cost}, base_cost={base_cost}")
        
        # Проверка баланса
        if balance < cost:
            await message.answer(f"💸 Недостаточно средств. Ваш баланс: {balance:.2f} токенов. Требуется: {cost:.2f} токенов")
            await state.clear()
            return
        
        await message.answer("⏳ Улучшение качества запущено, ждите...")
        
        # Используем Topaz Upscale с коэффициентом 1x
        api_response = await create_topaz_upscale_task(
            image_url=file_url,
            upscale_factor=1,
            callback_url=f"{CALLBACK_BASE_URL}/topaz-upscale-callback",
        )
        
        if api_response is None or api_response.get("code") != 200:
            error_msg = api_response.get("msg", "Неизвестная ошибка") if api_response else "Нет ответа от API"
            await message.answer(f"❌ Ошибка при создании задачи:\n{error_msg}")
            await state.clear()
            return
        
        task_id = api_response["data"]["taskId"]
        save_task_chat(task_id, user_id, cost)
        
        # Логируем запрос
        from database import log_user_request
        log_user_request(
            user_telegram_id=user_id,
            user_username=username,
            user_first_name=first_name,
            request_type="markets_upscale",
            model_name="topaz_upscale",
            prompt=None,
            image_urls=file_url,
            task_id=task_id,
            cost=cost,
            api_response=str(api_response)
        )
        
        await message.answer(
            f"✅ Задача отправлена!\nTask ID: <code>{task_id}</code>\n"
            "После завершения обработки изображение придет в чат автоматически.\n"
            "Баланс будет списан только при успешной обработке."
        )
        await state.clear()
        
    except Exception as e:
        logging.error(f"[markets_get_upscale_image_handler] Ошибка: {e}", exc_info=True)
        await message.answer(
            "❌ Ошибка при создании задачи. Попробуйте еще раз.",
            reply_markup=main_menu_keyboard(user_tag="markets")
        )
        await state.clear()
    
# --- Обработчики для замены фона ---
@dp.callback_query(F.data == "markets_replace_background")
async def markets_replace_background_callback(callback: CallbackQuery, state: FSMContext):
    """Обработчик выбора замены фона"""
    try:
        await callback.answer()
    except TelegramBadRequest as e:
        if "query is too old" in str(e).lower() or "query id is invalid" in str(e).lower():
            logging.warning(f"[markets_replace_background_callback] Callback query устарел: {e}")
            return
        raise
    
    user_id = callback.from_user.id
    user_tag = get_user_tag(user_id)
    
    if user_tag != "markets":
        await callback.message.answer("❌ Эта функция доступна только для пользователей маркетплейсов.")
        return
    
    background_type_buttons = [
        {"text": "📷 Заменить на фон из референса", "callback_data": "markets_bg_reference"},
        {"text": "🎨 Заменить на однотонный цвет", "callback_data": "markets_bg_solid"},
        {"text": "🌈 Заменить на градиент", "callback_data": "markets_bg_gradient"},
    ]
    keyboard = InlineKeyboardMarkup(
        inline_keyboard=[[InlineKeyboardButton(**btn)] for btn in background_type_buttons] + 
        [[InlineKeyboardButton(text="❌ Отменить", callback_data="cancel")]]
    )
    
    await callback.message.answer(
        "🖼️ <b>Замена фона на изображении</b>\n\n"
        "Выберите тип замены фона:",
        reply_markup=keyboard,
        parse_mode="HTML"
    )
    await state.set_state(MarketsStates.choose_background_type)

@dp.callback_query(MarketsStates.choose_background_type, F.data.startswith("markets_bg_"))
async def markets_choose_background_type_callback(callback: CallbackQuery, state: FSMContext):
    """Обработчик выбора типа замены фона"""
    try:
        await callback.answer()
    except TelegramBadRequest as e:
        if "query is too old" in str(e).lower() or "query id is invalid" in str(e).lower():
            logging.warning(f"[markets_choose_background_type_callback] Callback query устарел: {e}")
            return
        raise
    
    bg_type = callback.data.split("_")[-1]  # reference, solid, gradient
    
    await state.update_data(background_type=bg_type)
    
    if bg_type == "reference":
        await callback.message.answer(
            "📷 <b>Замена фона на референс</b>\n\n"
            "1️⃣ <b>Шаг 1:</b> Отправьте фото товара, у которого нужно заменить фон.\n\n"
            "Требования к фото товара:\n"
            "• Товар должен быть четко виден на текущем фоне\n"
            "• Желательно контрастный фон для лучшего определения границ товара\n"
            "• Формат: JPEG или PNG",
            reply_markup=cancel_keyboard(),
            parse_mode="HTML"
        )
        await state.set_state(MarketsStates.get_product_for_bg_replace)
    elif bg_type == "solid":
        await callback.message.answer(
            "🎨 <b>Замена фона на однотонный цвет</b>\n\n"
            "1️⃣ <b>Шаг 1:</b> Отправьте фото товара, у которого нужно заменить фон.\n\n"
            "Требования к фото товара:\n"
            "• Товар должен быть четко виден на текущем фоне\n"
            "• Желательно контрастный фон для лучшего определения границ товара\n"
            "• Формат: JPEG или PNG",
            reply_markup=cancel_keyboard(),
            parse_mode="HTML"
        )
        await state.set_state(MarketsStates.get_product_for_bg_replace)
    elif bg_type == "gradient":
        await callback.message.answer(
            "🌈 <b>Замена фона на градиент</b>\n\n"
            "1️⃣ <b>Шаг 1:</b> Отправьте фото товара, у которого нужно заменить фон.\n\n"
            "Требования к фото товара:\n"
            "• Товар должен быть четко виден на текущем фоне\n"
            "• Желательно контрастный фон для лучшего определения границ товара\n"
            "• Формат: JPEG или PNG",
            reply_markup=cancel_keyboard(),
            parse_mode="HTML"
        )
        await state.set_state(MarketsStates.get_product_for_bg_replace)

# Обработчик получения фото товара для замены фона (первый шаг для всех типов)
@dp.message(MarketsStates.get_product_for_bg_replace)
async def markets_get_product_for_bg_replace_handler(message: Message, state: FSMContext):
    """Обработчик получения фото товара для замены фона"""
    if message.text and message.text.startswith('/start'):
        return
    
    user_id = message.from_user.id
    user_tag = get_user_tag(user_id)
    
    if user_tag != "markets":
        await message.answer("❌ Эта функция доступна только для пользователей маркетплейсов.")
        await state.clear()
        return
    
    if not message.photo and not (message.document and message.document.mime_type and message.document.mime_type.startswith('image/')):
        await message.answer("❌ Пожалуйста, отправьте изображение товара.", reply_markup=cancel_keyboard())
        return
    
    try:
        if message.photo:
            file_id = message.photo[-1].file_id
        else:
            file_id = message.document.file_id
        
        file = await bot.get_file(file_id)
        file_url = f"https://api.telegram.org/file/bot{TELEGRAM_BOT_TOKEN}/{file.file_path}"
        
        await state.update_data(product_photo_url=file_url)
        
        user_data = await state.get_data()
        bg_type = user_data.get("background_type")
        
        if bg_type == "reference":
            await message.answer(
                "✅ Фото товара получено!\n\n"
                "2️⃣ <b>Шаг 2:</b> Отправьте фото референса фона.\n\n"
                "Требования к референсу фона:\n"
                "• Фон должен быть без людей и объектов\n"
                "• Подойдет пейзаж, текстура, интерьер\n"
                "• Разрешение: минимум 1000x1000 пикселей\n"
                "• Формат: JPEG или PNG",
                reply_markup=cancel_keyboard(),
                parse_mode="HTML"
            )
            await state.set_state(MarketsStates.get_background_reference)
        elif bg_type == "solid":
            await message.answer(
                "✅ Фото товара получено!\n\n"
                "2️⃣ <b>Шаг 2:</b> Введите название цвета для фона.\n\n"
                "💡 <b>Примеры:</b>\n"
                "• Белый\n"
                "• Черный\n"
                "• Красный\n"
                "• #FF5733 (HEX код)\n"
                "• RGB(255, 87, 51)\n\n"
                "Или отправьте название цвета словами.",
                reply_markup=cancel_keyboard(),
                parse_mode="HTML"
            )
            await state.set_state(MarketsStates.input_solid_color)
        elif bg_type == "gradient":
            await message.answer(
                "✅ Фото товара получено!\n\n"
                "2️⃣ <b>Шаг 2:</b> Опишите градиент для фона.\n\n"
                "💡 <b>Примеры:</b>\n"
                "• С красного на оранжевый\n"
                "• От синего к фиолетовому\n"
                "• От белого к серому\n"
                "• Градиент от #FF0000 до #FFA500\n\n"
                "Введите описание градиента:",
                reply_markup=cancel_keyboard(),
                parse_mode="HTML"
            )
            await state.set_state(MarketsStates.input_gradient_prompt)
    except Exception as e:
        logging.error(f"[markets_get_product_for_bg_replace_handler] Ошибка: {e}", exc_info=True)
        await message.answer("❌ Ошибка при обработке изображения.", reply_markup=cancel_keyboard())
        await state.clear()

# Обработчики получения фото товара для разных типов замены фона
@dp.message(MarketsStates.get_background_reference)
async def markets_get_background_reference_handler(message: Message, state: FSMContext):
    """Обработчик получения фото для замены фона (2 шаг - референс)"""
    if message.text and message.text.startswith('/start'):
        return
    
    user_id = message.from_user.id
    user_tag = get_user_tag(user_id)
    
    if user_tag != "markets":
        await message.answer("❌ Эта функция доступна только для пользователей маркетплейсов.")
        await state.clear()
        return
    
    if not message.photo and not (message.document and message.document.mime_type and message.document.mime_type.startswith('image/')):
        await message.answer("❌ Пожалуйста, отправьте изображение референса фона.", reply_markup=cancel_keyboard())
        return
    
    try:
        if message.photo:
            file_id = message.photo[-1].file_id
        else:
            file_id = message.document.file_id
        
        file = await bot.get_file(file_id)
        file_url = f"https://api.telegram.org/file/bot{TELEGRAM_BOT_TOKEN}/{file.file_path}"
        
        user_data = await state.get_data()
        product_photo_url = user_data.get("product_photo_url")
        background_type = user_data.get("background_type")
        
        if not product_photo_url:
            await message.answer("❌ Ошибка: фото товара не найдено. Начните заново.", reply_markup=cancel_keyboard())
            await state.clear()
            return
        
        # Стоимость замены фона
        cost = 40  # 40₽
        balance = get_balance(user_id)
        
        if balance < cost:
            await message.answer(f"💸 Недостаточно средств. Ваш баланс: {balance:.2f}₽. Требуется: {cost:.2f}₽")
            await state.clear()
            return
        
        # Сохраняем URL референса и тип операции
        await state.update_data(background_reference_url=file_url, operation_type="bg_replace_reference")
        
        # Запрашиваем дополнительный промпт
        await message.answer(
            "💡 <b>Дополнительные условия (необязательно)</b>\n\n"
            "Если хотите добавить дополнительные условия к замене фона (например, стиль обработки, освещение, композицию), введите их сейчас.\n\n"
            "Или отправьте /skip чтобы пропустить этот шаг.",
            reply_markup=cancel_keyboard(),
            parse_mode="HTML"
        )
        await state.set_state(MarketsStates.input_additional_prompt)
    except Exception as e:
        logging.error(f"[markets_get_background_reference_handler] Ошибка: {e}", exc_info=True)
        await message.answer("❌ Ошибка при обработке изображения.", reply_markup=cancel_keyboard())
        await state.clear()

@dp.message(MarketsStates.input_solid_color)
async def markets_input_solid_color_handler(message: Message, state: FSMContext):
    """Обработчик ввода однотонного цвета"""
    if message.text and message.text.startswith('/start'):
        return
    
    user_id = message.from_user.id
    user_tag = get_user_tag(user_id)
    
    if user_tag != "markets":
        await message.answer("❌ Эта функция доступна только для пользователей маркетплейсов.")
        await state.clear()
        return
    
    # Это текст с цветом (второй шаг - фото товара уже получено через get_product_for_bg_replace)
    color = message.text.strip() if message.text else ""
    if not color:
        await message.answer("❌ Пожалуйста, введите цвет для фона.", reply_markup=cancel_keyboard())
        return
    
    user_data = await state.get_data()
    product_photo_url = user_data.get("product_photo_url")
    
    if not product_photo_url:
        await message.answer("❌ Ошибка: фото товара не найдено. Начните заново.", reply_markup=cancel_keyboard())
        await state.clear()
        return
    
    # Стоимость замены фона
    cost = 40  # 40₽
    balance = get_balance(user_id)
    
    if balance < cost:
        await message.answer(f"💸 Недостаточно средств. Ваш баланс: {balance:.2f}₽. Требуется: {cost:.2f}₽")
        await state.clear()
        return
    
    # Сохраняем цвет и тип операции
    await state.update_data(background_color=color, operation_type="bg_replace_solid")
    
    # Запрашиваем дополнительный промпт
    await message.answer(
        "💡 <b>Дополнительные условия (необязательно)</b>\n\n"
        "Если хотите добавить дополнительные условия к замене фона (например, стиль обработки, освещение, композицию), введите их сейчас.\n\n"
        "Или отправьте /skip чтобы пропустить этот шаг.",
        reply_markup=cancel_keyboard(),
        parse_mode="HTML"
    )
    await state.set_state(MarketsStates.input_additional_prompt)
    
    try:
        api_response = await create_nano_banana_task(
            mode="edit",
            prompt=prompt,
            image_urls=[product_photo_url],
            callback_url=f"{CALLBACK_BASE_URL}/nano-banana-callback",
        )
        
        if api_response is None or api_response.get("code") != 200:
            error_msg = api_response.get("msg", "Неизвестная ошибка") if api_response else "Нет ответа от API"
            await message.answer(f"❌ Ошибка при создании задачи:\n{error_msg}")
            await state.clear()
            return
        
        task_id = api_response["data"]["taskId"]
        save_task_chat(task_id, user_id, cost)
        
        from database import log_user_request
        log_user_request(
            user_telegram_id=user_id,
            user_username=message.from_user.username or "user",
            user_first_name=message.from_user.first_name or "Пользователь",
            request_type="markets_bg_replace",
            model_name="nano_banana_edit",
            prompt=prompt,
            image_urls=product_photo_url,
            task_id=task_id,
            cost=cost,
            api_response=str(api_response)
        )
        
        await message.answer(
            f"✅ Задача отправлена!\nTask ID: <code>{task_id}</code>\n"
            "После завершения обработки изображение придет в чат автоматически.\n"
            "Баланс будет списан только при успешной обработке."
        )
        await state.clear()
    except Exception as e:
        logging.error(f"[markets_input_solid_color_handler] Ошибка: {e}", exc_info=True)
        await message.answer("❌ Ошибка при создании задачи. Попробуйте еще раз.", reply_markup=cancel_keyboard())
        await state.clear()

@dp.message(MarketsStates.input_gradient_prompt)
async def markets_input_gradient_prompt_handler(message: Message, state: FSMContext):
    """Обработчик ввода градиента"""
    if message.text and message.text.startswith('/start'):
        return
    
    user_id = message.from_user.id
    user_tag = get_user_tag(user_id)
    
    if user_tag != "markets":
        await message.answer("❌ Эта функция доступна только для пользователей маркетплейсов.")
        await state.clear()
        return
    
    # Это текст с градиентом (второй шаг - фото товара уже получено через get_product_for_bg_replace)
    gradient = message.text.strip() if message.text else ""
    if not gradient:
        await message.answer("❌ Пожалуйста, опишите градиент.", reply_markup=cancel_keyboard())
        return
    
    await state.update_data(gradient_prompt=gradient)
    
    gradient_shape_buttons = [
        {"text": "⭕ Радиальный", "callback_data": "gradient_radial"},
        {"text": "↔️ Горизонтальный", "callback_data": "gradient_horizontal"},
        {"text": "↕️ Вертикальный", "callback_data": "gradient_vertical"},
    ]
    keyboard = InlineKeyboardMarkup(
        inline_keyboard=[[InlineKeyboardButton(**btn)] for btn in gradient_shape_buttons] + 
        [[InlineKeyboardButton(text="❌ Отменить", callback_data="cancel")]]
    )
    
    await message.answer(
        f"✅ Градиент сохранен: {gradient}\n\n"
        "3️⃣ <b>Шаг 3:</b> Выберите форму градиента:",
        reply_markup=keyboard,
        parse_mode="HTML"
    )
    await state.set_state(MarketsStates.choose_gradient_shape)

@dp.callback_query(MarketsStates.choose_gradient_shape, F.data.startswith("gradient_"))
async def markets_choose_gradient_shape_callback(callback: CallbackQuery, state: FSMContext):
    """Обработчик выбора формы градиента"""
    try:
        await callback.answer()
    except TelegramBadRequest as e:
        if "query is too old" in str(e).lower():
            return
        raise
    
    shape = callback.data.split("_")[-1]  # radial, horizontal, vertical
    
    user_id = callback.from_user.id
    user_data = await state.get_data()
    product_photo_url = user_data.get("product_photo_url")
    gradient_prompt = user_data.get("gradient_prompt")
    
    if not product_photo_url:
        await callback.message.answer("❌ Ошибка: фото товара не найдено. Начните заново.", reply_markup=cancel_keyboard())
        await state.clear()
        return
    
    # Стоимость замены фона
    cost = 40  # 40₽
    balance = get_balance(user_id)
    
    if balance < cost:
        await callback.message.answer(f"💸 Недостаточно средств. Ваш баланс: {balance:.2f}₽. Требуется: {cost:.2f}₽")
        await state.clear()
        return
    
    # Сохраняем форму градиента и тип операции
    await state.update_data(gradient_shape=shape, operation_type="bg_replace_gradient")
    
    # Запрашиваем дополнительный промпт
    await callback.message.answer(
        "💡 <b>Дополнительные условия (необязательно)</b>\n\n"
        "Если хотите добавить дополнительные условия к замене фона (например, стиль обработки, освещение, композицию), введите их сейчас.\n\n"
        "Или отправьте /skip чтобы пропустить этот шаг.",
        reply_markup=cancel_keyboard(),
        parse_mode="HTML"
    )
    await state.set_state(MarketsStates.input_additional_prompt)

# --- Генерация фона по промпту ---
@dp.callback_query(F.data == "markets_generate_background")
async def markets_generate_background_callback(callback: CallbackQuery, state: FSMContext):
    """Обработчик генерации фона по промпту"""
    try:
        await callback.answer()
    except TelegramBadRequest as e:
        if "query is too old" in str(e).lower():
            return
        raise
    
    user_id = callback.from_user.id
    user_tag = get_user_tag(user_id)
    
    if user_tag != "markets":
        await callback.message.answer("❌ Эта функция доступна только для пользователей маркетплейсов.")
        return
    
    await callback.message.answer(
        "🎨 <b>Генерация фона по промпту</b>\n\n"
        "Опишите фон, который хотите сгенерировать.\n\n"
        "💡 <b>Примеры:</b>\n"
        "• Пляж с пальмами на закате\n"
        "• Современный офис с белыми стенами\n"
        "• Лесная тропинка в солнечный день\n"
        "• Абстрактная текстура синего цвета\n\n"
        "Введите описание фона:",
        reply_markup=cancel_keyboard(),
        parse_mode="HTML"
    )
    await state.set_state(MarketsStates.input_background_prompt)

@dp.message(MarketsStates.input_background_prompt)
async def markets_input_background_prompt_handler(message: Message, state: FSMContext):
    """Обработчик ввода промпта для генерации фона"""
    if message.text and message.text.startswith('/start'):
        return
    
    user_id = message.from_user.id
    user_tag = get_user_tag(user_id)
    
    if user_tag != "markets":
        await message.answer("❌ Эта функция доступна только для пользователей маркетплейсов.")
        await state.clear()
        return
    
    prompt = message.text.strip() if message.text else ""
    if not prompt:
        await message.answer("❌ Пожалуйста, опишите фон для генерации.", reply_markup=cancel_keyboard())
        return
    
    # Стоимость генерации фона
    cost = 20  # 20₽
    balance = get_balance(user_id)
    
    if balance < cost:
        await message.answer(f"💸 Недостаточно средств. Ваш баланс: {balance:.2f}₽. Требуется: {cost:.2f}₽")
        await state.clear()
        return
    
    # Сохраняем промпт и тип операции
    await state.update_data(background_prompt=prompt, operation_type="bg_generate")
    
    # Запрашиваем дополнительный промпт
    await message.answer(
        "💡 <b>Дополнительные условия (необязательно)</b>\n\n"
        "Если хотите добавить дополнительные условия к генерации фона (например, детали, стиль, атмосферу), введите их сейчас.\n\n"
        "Или отправьте /skip чтобы пропустить этот шаг.",
        reply_markup=cancel_keyboard(),
        parse_mode="HTML"
    )
    await state.set_state(MarketsStates.input_additional_prompt)

# --- Генерация нейро модели ---
@dp.callback_query(F.data == "markets_generate_model")
async def markets_generate_model_callback(callback: CallbackQuery, state: FSMContext):
    """Обработчик генерации нейро модели"""
    try:
        await callback.answer()
    except TelegramBadRequest as e:
        if "query is too old" in str(e).lower():
            return
        raise
    
    user_id = callback.from_user.id
    user_tag = get_user_tag(user_id)
    
    if user_tag != "markets":
        await callback.message.answer("❌ Эта функция доступна только для пользователей маркетплейсов.")
        return
    
    gender_buttons = [
        {"text": "👨 Мужчина", "callback_data": "model_gender_man"},
        {"text": "👩 Женщина", "callback_data": "model_gender_woman"},
    ]
    keyboard = InlineKeyboardMarkup(
        inline_keyboard=[[InlineKeyboardButton(**btn)] for btn in gender_buttons] + 
        [[InlineKeyboardButton(text="❌ Отменить", callback_data="cancel")]]
    )
    
    await callback.message.answer(
        "👤 <b>Генерация нейро модели</b>\n\n"
        "1️⃣ <b>Шаг 1:</b> Выберите пол модели:",
        reply_markup=keyboard,
        parse_mode="HTML"
    )
    await state.set_state(MarketsStates.choose_model_gender)

@dp.callback_query(MarketsStates.choose_model_gender, F.data.startswith("model_gender_"))
async def markets_choose_model_gender_callback(callback: CallbackQuery, state: FSMContext):
    """Обработчик выбора пола модели"""
    try:
        await callback.answer()
    except TelegramBadRequest as e:
        if "query is too old" in str(e).lower():
            return
        raise
    
    gender = callback.data.split("_")[-1]  # man, woman
    await state.update_data(model_gender=gender)
    
    age_buttons = [
        {"text": "👨 Мужчина", "callback_data": "model_age_man"},
        {"text": "👩 Женщина", "callback_data": "model_age_woman"},
        {"text": "👦 Парень", "callback_data": "model_age_boy"},
        {"text": "👧 Девушка", "callback_data": "model_age_girl"},
        {"text": "🧒 Мальчик", "callback_data": "model_age_child_boy"},
        {"text": "👶 Девочка", "callback_data": "model_age_child_girl"},
        {"text": "🍼 Младенец", "callback_data": "model_age_baby"},
    ]
    keyboard = InlineKeyboardMarkup(
        inline_keyboard=[[InlineKeyboardButton(**btn)] for btn in age_buttons] + 
        [[InlineKeyboardButton(text="❌ Отменить", callback_data="cancel")]]
    )
    
    await callback.message.answer(
        f"✅ Пол выбран: {'Мужской' if gender == 'man' else 'Женский'}\n\n"
        "2️⃣ <b>Шаг 2:</b> Выберите тип модели:",
        reply_markup=keyboard,
        parse_mode="HTML"
    )
    await state.set_state(MarketsStates.choose_model_age)

@dp.callback_query(MarketsStates.choose_model_age, F.data.startswith("model_age_"))
async def markets_choose_model_age_callback(callback: CallbackQuery, state: FSMContext):
    """Обработчик выбора возраста модели"""
    try:
        await callback.answer()
    except TelegramBadRequest as e:
        if "query is too old" in str(e).lower():
            return
        raise
    
    age_type = callback.data.replace("model_age_", "")  # man, woman, boy, girl, child_boy, child_girl, baby
    
    age_names = {
        "man": "мужчина",
        "woman": "женщина",
        "boy": "парень",
        "girl": "девушка",
        "child_boy": "мальчик",
        "child_girl": "девочка",
        "baby": "младенец"
    }
    
    user_id = callback.from_user.id
    await state.update_data(model_age_type=age_type)
    
    # Стоимость генерации нейро модели
    cost = 60  # 60₽
    balance = get_balance(user_id)
    
    if balance < cost:
        await callback.message.answer(f"💸 Недостаточно средств. Ваш баланс: {balance:.2f}₽. Требуется: {cost:.2f}₽")
        await state.clear()
        return
    
    # Сохраняем тип модели и тип операции
    await state.update_data(model_age_type=age_type, operation_type="model_generate")
    
    # Запрашиваем дополнительный промпт
    await callback.message.answer(
        "💡 <b>Дополнительные условия (необязательно)</b>\n\n"
        "Если хотите добавить дополнительные условия к генерации модели (например, позу, стиль одежды, фон, освещение), введите их сейчас.\n\n"
        "Или отправьте /skip чтобы пропустить этот шаг.",
        reply_markup=cancel_keyboard(),
        parse_mode="HTML"
    )
    await state.set_state(MarketsStates.input_additional_prompt)

# --- Совмещение фото товара и фона ---
@dp.callback_query(F.data == "markets_composite_photo")
async def markets_composite_photo_callback(callback: CallbackQuery, state: FSMContext):
    """Обработчик совмещения фото товара и фона"""
    try:
        await callback.answer()
    except TelegramBadRequest as e:
        if "query is too old" in str(e).lower():
            return
        raise
    
    user_id = callback.from_user.id
    user_tag = get_user_tag(user_id)
    
    if user_tag != "markets":
        await callback.message.answer("❌ Эта функция доступна только для пользователей маркетплейсов.")
        return
    
    await callback.message.answer(
        "🔗 <b>Совмещение фото товара и фона</b>\n\n"
        "1️⃣ <b>Шаг 1:</b> Отправьте фото товара, который нужно разместить на фоне.\n\n"
        "Требования к фото товара:\n"
        "• Товар должен быть четко виден\n"
        "• Желательно товар на однотонном или контрастном фоне\n"
        "• Формат: JPEG или PNG",
        reply_markup=cancel_keyboard(),
        parse_mode="HTML"
    )
    await state.set_state(MarketsStates.get_product_for_composite)

@dp.message(MarketsStates.get_product_for_composite)
async def markets_get_product_for_composite_handler(message: Message, state: FSMContext):
    """Обработчик получения фото товара для совмещения"""
    if message.text and message.text.startswith('/start'):
        return
    
    user_id = message.from_user.id
    user_tag = get_user_tag(user_id)
    
    if user_tag != "markets":
        await message.answer("❌ Эта функция доступна только для пользователей маркетплейсов.")
        await state.clear()
        return
    
    if not message.photo and not (message.document and message.document.mime_type and message.document.mime_type.startswith('image/')):
        await message.answer("❌ Пожалуйста, отправьте изображение товара.", reply_markup=cancel_keyboard())
        return
    
    try:
        if message.photo:
            file_id = message.photo[-1].file_id
        else:
            file_id = message.document.file_id
        
        file = await bot.get_file(file_id)
        file_url = f"https://api.telegram.org/file/bot{TELEGRAM_BOT_TOKEN}/{file.file_path}"
        
        await state.update_data(composite_product_url=file_url)
        
        await message.answer(
            "✅ Фото товара получено!\n\n"
            "2️⃣ <b>Шаг 2:</b> Отправьте фото фона, на который нужно разместить товар.\n\n"
            "Требования к фону:\n"
            "• Фон должен быть без людей и других товаров\n"
            "• Подойдет пейзаж, интерьер, текстура\n"
            "• Разрешение: минимум 1000x1000 пикселей\n"
            "• Формат: JPEG или PNG",
            reply_markup=cancel_keyboard(),
            parse_mode="HTML"
        )
        await state.set_state(MarketsStates.get_background_for_composite)
    except Exception as e:
        logging.error(f"[markets_get_product_for_composite_handler] Ошибка: {e}", exc_info=True)
        await message.answer("❌ Ошибка при обработке изображения.", reply_markup=cancel_keyboard())
        await state.clear()

@dp.message(MarketsStates.get_background_for_composite)
async def markets_get_background_for_composite_handler(message: Message, state: FSMContext):
    """Обработчик получения фона для совмещения"""
    if message.text and message.text.startswith('/start'):
        return
    
    user_id = message.from_user.id
    user_tag = get_user_tag(user_id)
    
    if user_tag != "markets":
        await message.answer("❌ Эта функция доступна только для пользователей маркетплейсов.")
        await state.clear()
        return
    
    if not message.photo and not (message.document and message.document.mime_type and message.document.mime_type.startswith('image/')):
        await message.answer("❌ Пожалуйста, отправьте изображение фона.", reply_markup=cancel_keyboard())
        return
    
    try:
        if message.photo:
            file_id = message.photo[-1].file_id
        else:
            file_id = message.document.file_id
        
        file = await bot.get_file(file_id)
        file_url = f"https://api.telegram.org/file/bot{TELEGRAM_BOT_TOKEN}/{file.file_path}"
        
        user_data = await state.get_data()
        product_url = user_data.get("composite_product_url")
        
        if not product_url:
            await message.answer("❌ Ошибка: фото товара не найдено. Начните заново.", reply_markup=cancel_keyboard())
            await state.clear()
            return
        
        await state.update_data(composite_background_url=file_url)
        
        # Стоимость совмещения фото товара и фона
        cost = 75  # 75₽
        balance = get_balance(user_id)
        
        if balance < cost:
            await message.answer(f"💸 Недостаточно средств. Ваш баланс: {balance:.2f}₽. Требуется: {cost:.2f}₽")
            await state.clear()
            return
        
        # Сохраняем URL фона и тип операции
        await state.update_data(composite_background_url=file_url, operation_type="composite")
        
        # Запрашиваем дополнительный промпт
        await message.answer(
            "💡 <b>Дополнительные условия (необязательно)</b>\n\n"
            "Если хотите добавить дополнительные условия к совмещению (например, позиционирование товара, освещение, стиль композиции), введите их сейчас.\n\n"
            "Или отправьте /skip чтобы пропустить этот шаг.",
            reply_markup=cancel_keyboard(),
            parse_mode="HTML"
        )
        await state.set_state(MarketsStates.input_additional_prompt)
    except Exception as e:
        logging.error(f"[markets_get_background_for_composite_handler] Ошибка: {e}", exc_info=True)
        await message.answer("❌ Ошибка при обработке изображения.", reply_markup=cancel_keyboard())
        await state.clear()

# --- Команда /start ---
@dp.message(Command("start"))
async def start_handler(message: Message, state: FSMContext):
    # Очищаем состояние FSM при команде /start
    await state.clear()
    
    user_id = message.from_user.id
    username = message.from_user.username or "user"
    first_name = message.from_user.first_name or "друг"
    
    logging.info(f"[start_handler] Получена команда /start от пользователя {user_id} (@{username})")
    
    try:
        text_parts = (message.text or "").split()
        param = text_parts[1] if len(text_parts) > 1 else None
        
        logging.info(f"[start_handler] Параметр: {param}")

        campaign_tag_record = None
        campaign_tag_value = None
        referral_code = None

        if param:
            if param == "auth":
                from config import WP_SITE_URL, WP_AUTH_PAGE, TELEGRAM_BOT_TOKEN
                import hashlib
                import urllib.parse

                user_first_name = message.from_user.first_name or ''
                last_name = message.from_user.last_name or ''

                secret_key = TELEGRAM_BOT_TOKEN.split(':')[1]
                auth_string = f"{user_id}{secret_key}"
                auth_hash = hashlib.sha256(auth_string.encode()).hexdigest()[:16]

                auth_params = {
                    'telegram_auth': '1',
                    'id': str(user_id),
                    'hash': auth_hash,
                    'first_name': user_first_name,
                    'username': username
                }

                if last_name:
                    auth_params['last_name'] = last_name

                base_url = WP_SITE_URL + WP_AUTH_PAGE
                auth_url = base_url + '?' + urllib.parse.urlencode(auth_params)

                auth_keyboard = InlineKeyboardMarkup(
                    inline_keyboard=[
                        [InlineKeyboardButton(text="🔗 Перейти на сайт для авторизации", url=auth_url)],
                        [InlineKeyboardButton(text="🔙 Меню", callback_data="open_menu")]
                    ]
                )

                await message.answer(
                    f"🌐 <b>Авторизация на сайте</b>\n\n"
                    f"Нажмите кнопку ниже, чтобы перейти на сайт и автоматически авторизоваться:\n\n"
                    f"<code>{auth_url}</code>",
                    reply_markup=auth_keyboard,
                    parse_mode="HTML"
                )
                return

            if param == "seedance":
                campaign_tag_value = "seedance"
            elif param == "Sv_lana0707":
                # Специальный параметр для дополнительных опций пополнения
                campaign_tag_value = "start_Sv_lana0707"
            elif param.startswith("market"):
                # Специальный параметр для маркетплейсов (все параметры начинающиеся с "market")
                campaign_tag_value = "markets"
                # Ищем тег в базе для логирования событий
                campaign_tag_record = get_campaign_tag_by_value("markets")
            else:
                campaign_tag_record = get_campaign_tag_by_value(param)
                if campaign_tag_record:
                    campaign_tag_value = campaign_tag_record["tag"]
                else:
                    referral_code = param

        # Создаем/обновляем пользователя в базе данных
        # Это гарантирует, что пользователь будет в базе перед любыми проверками
        if campaign_tag_value:
            add_user(user_id, username, tag=campaign_tag_value)
            set_user_tag(user_id, campaign_tag_value)
            # Если campaign_tag_record не найден, пытаемся найти или создать тег
            if not campaign_tag_record:
                campaign_tag_record = get_campaign_tag_by_value(campaign_tag_value)
                # Если тег все еще не найден, создаем его
                if not campaign_tag_record:
                    success, error = create_campaign_tag(campaign_tag_value, f"Рекламная кампания {campaign_tag_value}")
                    if success:
                        campaign_tag_record = get_campaign_tag_by_value(campaign_tag_value)
            # Логируем событие если тег найден или создан
            if campaign_tag_record:
                result = log_tag_event(campaign_tag_record["id"], user_id)
                if not result:
                    logging.warning(f"[start_handler] log_tag_event returned False for tag_id={campaign_tag_record['id']}, user_id={user_id}")
            else:
                logging.warning(f"[start_handler] campaign_tag_record is None for tag_value={campaign_tag_value}, user_id={user_id}")
        else:
            add_user(user_id, username)
        
        logging.info(f"[start_handler] Пользователь создан/обновлен в БД: user_id={user_id}, tag={campaign_tag_value}")

        partner_result = None
        if referral_code:
            from database import get_connection
            conn = get_connection()
            cursor = conn.cursor()
            cursor.execute("SELECT telegram_id FROM users WHERE username = %s", (referral_code,))
            partner_result = cursor.fetchone()
            conn.close()
            if partner_result:
                partner_id = partner_result["telegram_id"]
                add_referral(partner_id, referral_code, user_id, username)

        # Получаем тег пользователя из БД после создания/обновления
        # Пользователь уже должен быть в базе благодаря вызову add_user выше
        user_tag = get_user_tag(user_id)
        
        # Если по какой-то причине пользователя нет в базе (не должно произойти),
        # создаем его еще раз без тега
        if user_tag is None and campaign_tag_value is None:
            logging.warning(f"[start_handler] Пользователь {user_id} не найден в БД после add_user, создаем повторно")
            add_user(user_id, username)
            user_tag = get_user_tag(user_id)
        
        # Переход из канала по кнопке «Повторить это фото»: промпт уже готов,
        # обычное приветствие показывать не нужно
        if param:
            from channel_deeplink import start_with_prompt

            if await start_with_prompt(message, state, param, NanoBananaStates):
                return

        # Ссылка для вебмастеров: закрепляем повышенный тариф партнёрки
        if param:
            from partner_tiers import describe, get_tier, parse_webmaster_payload, set_tier

            source = parse_webmaster_payload(param)
            if source:
                set_tier(user_id, username, tier="webmaster", source=source)
                tier = get_tier(user_id)
                await message.answer(
                    "🤝 <b>Вы подключены к партнёрской программе для вебмастеров</b>\n\n"
                    f"Ваши условия: <b>{describe(tier)}</b>\n\n"
                    "Ссылка для привлечения — в разделе «Профиль». Комиссия начисляется "
                    "автоматически, вывод от 500₽ в течение суток.",
                    parse_mode="HTML",
                )

        # Проверка админа через функцию из config
        is_admin = check_admin(user_id, username)
        
        logging.info(f"[start_handler] user_tag={user_tag}, campaign_tag_value={campaign_tag_value}, is_admin={is_admin}")

        # Обработка специального меню для маркетплейсов
        # Проверяем тег пользователя из БД, а не только campaign_tag_value
        if user_tag == "markets" or campaign_tag_value == "markets":
            logging.info(f"[start_handler] Обработка пользователя с тегом markets")
            # Убеждаемся, что пользователь создан/обновлен с тегом markets
            if campaign_tag_value == "markets":
                add_user(user_id, username, tag="markets")
                set_user_tag(user_id, "markets")
                # Обновляем user_tag после установки тега
                user_tag = get_user_tag(user_id)
                logging.info(f"[start_handler] Тег markets установлен, user_tag={user_tag}")
            
            await message.answer(
                f"👋 Привет, {first_name}!\n\n"
                "🛍️ <b>Добро пожаловать в генератор карточек товаров для маркетплейсов!</b>\n\n"
                "Создавайте профессиональные карточки товаров для Wildberries и Ozon:\n"
                "• 📦 Карточка товара по фото\n"
                "• 🎬 Видеообложка\n"
                "• ✨ Улучшение качества фото\n\n"
                "Все изображения соответствуют требованиям маркетплейсов.",
                reply_markup=main_menu_keyboard(user_tag="markets"),
                parse_mode="HTML"
            )
            # Отправляем специальную нижнюю клавиатуру для маркетплейсов
            await message.answer("📱 Основные функции доступны внизу экрана", reply_markup=markets_reply_keyboard())
            logging.info(f"[start_handler] Отправлено сообщение для пользователя с тегом markets")
            return

        # Обработка для seedance
        if campaign_tag_value == "seedance" and not partner_result:
            price_options = get_seedance_price_options()
            min_price = min(price_options.values()) if price_options else 0
            seedance_keyboard = InlineKeyboardMarkup(
                inline_keyboard=[
                    [InlineKeyboardButton(text="🚀 Начать Seedance", callback_data="ai_seedance")],
                    [InlineKeyboardButton(text="Меню", callback_data="open_menu")]
                ]
            )
            await message.answer(
                "👋 Привет! Добро пожаловать в Seedance 1.5 Pro Fast.\n\n"
                "Отправьте изображение и мы превратим его в видео:\n"
                f"• 720p · 5 с — {price_options.get('720p_5s', min_price)}₽\n"
                f"• 720p · 10 с — {price_options.get('720p_10s', min_price)}₽\n"
                f"• 1080p · 5 с — {price_options.get('1080p_5s', min_price)}₽\n"
                f"• 1080p · 10 с — {price_options.get('1080p_10s', min_price)}₽\n\n"
                "📢 <b>Ежедневные промпты!</b>\nГотовые промпты для трендовых фото и видео\n👉 <a href=\"https://t.me/promtnanobanana7\">Подписаться</a>",
                reply_markup=seedance_keyboard,
                parse_mode="HTML"
            )
            get_or_create_user(user_id, username)
            return

        if partner_result:
            if is_admin:
                text = (
                    f"👋 Привет, {first_name}!\n"
                    f"Вы перешли по ссылке партнёра @{referral_code}!\n\n"
                    "📢 <b>Ежедневные промпты!</b>\nГотовые промпты для трендовых фото и видео\n👉 <a href=\"https://t.me/promtnanobanana7\">Подписаться</a>\n\n"
                    "Выберите действие:"
                )
                admin_keyboard = InlineKeyboardMarkup(
                    inline_keyboard=[
                        [InlineKeyboardButton(text="👤 Профиль", callback_data="menu_profile")],
                        [InlineKeyboardButton(text="🚀 Начать генерацию", callback_data="menu_generate")],
                        [InlineKeyboardButton(text="💰 Пополнить баланс", callback_data="menu_topup")],
                        [InlineKeyboardButton(text="💼 Партнёрская программа", callback_data="menu_partner")],
                        [InlineKeyboardButton(text="ℹ️ Тарифы / справка", callback_data="menu_help")],
                        [InlineKeyboardButton(text="🔧 Админ меню", callback_data="admin_menu")],
                    ]
                )
                await message.answer(text, reply_markup=admin_keyboard, parse_mode="HTML")
            else:
                if user_tag == "tts":
                    await message.answer(
                        f"👋 Привет, {first_name}!\n"
                        f"Вы перешли по ссылке партнёра @{referral_code}!\n\n"
                        "Перед началом генерации прослушайте примеры озвучки для выбора нейро голоса:\n"
                        "<a href=\"https://genius-bot.ru/nejroset-golos-primery-golosov-dlya-ozvuchki/\">Прослушать голоса</a>\n\n"
                        "📢 <b>Ежедневные промпты!</b>\nГотовые промпты для трендовых фото и видео\n👉 <a href=\"https://t.me/promtnanobanana7\">Подписаться</a>\n\n"
                        "Выберите действие:",
                        reply_markup=main_menu_keyboard(user_tag="tts"),
                        parse_mode="HTML"
                    )
                    await message.answer("📱 Меню всегда доступно внизу экрана", reply_markup=main_reply_keyboard())
                else:
                    await message.answer(
                        f"👋 Привет, {first_name}!\n"
                        f"Вы перешли по ссылке партнёра @{referral_code}!\n\n"
                        "📢 <b>Ежедневные промпты!</b>\nГотовые промпты для трендовых фото и видео\n👉 <a href=\"https://t.me/promtnanobanana7\">Подписаться</a>\n\n"
                        "Нажмите кнопку ниже, чтобы открыть меню:",
                        reply_markup=menu_button(),
                        parse_mode="HTML"
                    )
                get_or_create_user(user_id, username)
                return

        if user_tag == "tts":
            if is_admin:
                text = (
                    "🤖 Добро пожаловать в NeuroHub AI!\n\n"
                    "Перед началом генерации прослушайте примеры озвучки для выбора нейро голоса:\n"
                    "<a href=\"https://genius-bot.ru/nejroset-golos-primery-golosov-dlya-ozvuchki/\">Прослушать голоса</a>\n\n"
                    "📢 <b>Ежедневные промпты!</b>\nГотовые промпты для трендовых фото и видео\n👉 <a href=\"https://t.me/promtnanobanana7\">Подписаться</a>\n\n"
                    "Выберите действие:"
                )
                admin_keyboard = InlineKeyboardMarkup(
                    inline_keyboard=[
                        [InlineKeyboardButton(text="🎙️ Озвучить текст в голос", callback_data="content_tts")],
                        [InlineKeyboardButton(text="🗣️ Перевод голоса в текст", callback_data="content_speech_to_text")],
                        [InlineKeyboardButton(text="👤 Профиль", callback_data="menu_profile")],
                        [InlineKeyboardButton(text="🚀 Начать генерацию", callback_data="menu_generate")],
                        [InlineKeyboardButton(text="💰 Пополнить баланс", callback_data="menu_topup")],
                        [InlineKeyboardButton(text="💼 Партнёрская программа", callback_data="menu_partner")],
                        [InlineKeyboardButton(text="ℹ️ Тарифы / справка", callback_data="menu_help")],
                        [InlineKeyboardButton(text="🔧 Админ меню", callback_data="admin_menu")],
                    ]
                )
                await message.answer(text, reply_markup=admin_keyboard, parse_mode="HTML")
            else:
                await message.answer(
                    f"👋 Привет, {first_name}!\n\n"
                    "Перед началом генерации прослушайте примеры озвучки для выбора нейро голоса:\n"
                    "<a href=\"https://genius-bot.ru/nejroset-golos-primery-golosov-dlya-ozvuchki/\">Прослушать голоса</a>\n\n"
                    "📢 <b>Ежедневные промпты!</b>\nГотовые промпты для трендовых фото и видео\n👉 <a href=\"https://t.me/promtnanobanana7\">Подписаться</a>\n\n"
                    "Выберите действие:",
                    reply_markup=main_menu_keyboard(user_tag="tts"),
                    parse_mode="HTML"
                )
                await message.answer("📱 Меню всегда доступно внизу экрана", reply_markup=main_reply_keyboard())
        else:
            if is_admin:
                text = (
                    "🤖 Добро пожаловать в NeuroHub AI!\n\n"
                    "📢 <b>Ежедневные промпты!</b>\nГотовые промпты для трендовых фото и видео\n👉 <a href=\"https://t.me/promtnanobanana7\">Подписаться</a>\n\n"
                    "Выберите действие:"
                )
                admin_keyboard = InlineKeyboardMarkup(
                    inline_keyboard=[
                        [InlineKeyboardButton(text="👤 Профиль", callback_data="menu_profile")],
                        [InlineKeyboardButton(text="🚀 Начать генерацию", callback_data="menu_generate")],
                        [InlineKeyboardButton(text="💰 Пополнить баланс", callback_data="menu_topup")],
                        [InlineKeyboardButton(text="💼 Партнёрская программа", callback_data="menu_partner")],
                        [InlineKeyboardButton(text="ℹ️ Тарифы / справка", callback_data="menu_help")],
                        [InlineKeyboardButton(text="🔧 Админ меню", callback_data="admin_menu")],
                    ]
                )
                await message.answer(text, reply_markup=admin_keyboard, parse_mode="HTML")
            elif campaign_tag_value:
                # Для start_Sv_lana0707 не показываем сообщение о кампании
                if campaign_tag_value == "start_Sv_lana0707":
                    await message.answer(
                        f"👋 Привет, {first_name}!\n\n"
                        "📢 <b>Ежедневные промпты!</b>\nГотовые промпты для трендовых фото и видео\n👉 <a href=\"https://t.me/promtnanobanana7\">Подписаться</a>\n\n"
                        "Выберите действие:",
                        reply_markup=main_menu_keyboard(user_tag=None),
                        parse_mode="HTML"
                    )
                else:
                    await message.answer(
                        f"👋 Привет, {first_name}!\n"
                        f"Вы пришли по кампании #{campaign_tag_value}.\n\n"
                        "📢 <b>Ежедневные промпты!</b>\nГотовые промпты для трендовых фото и видео\n👉 <a href=\"https://t.me/promtnanobanana7\">Подписаться</a>\n\n"
                        "Выберите действие:",
                        reply_markup=main_menu_keyboard(user_tag=None),
                        parse_mode="HTML"
                    )
                await message.answer("📱 Меню всегда доступно внизу экрана", reply_markup=main_reply_keyboard())
            else:
                await message.answer(
                    f"👋 Привет, {first_name}!\n\n"
                    "📢 <b>Ежедневные промпты!</b>\nГотовые промпты для трендовых фото и видео\n👉 <a href=\"https://t.me/promtnanobanana7\">Подписаться</a>\n\n"
                    "Выберите действие:",
                    reply_markup=main_menu_keyboard(user_tag=None),
                    parse_mode="HTML"
                )
                await message.answer("📱 Меню всегда доступно внизу экрана", reply_markup=main_reply_keyboard())
                logging.info(f"[start_handler] Отправлено сообщение для обычного пользователя")

        get_or_create_user(user_id, username)
        logging.info(f"[start_handler] Завершение обработки команды /start для пользователя {user_id}")
    except Exception as e:
        logging.error(f"[start_handler] Критическая ошибка при обработке /start для пользователя {user_id} (@{username}): {e}", exc_info=True)
        # Пытаемся добавить пользователя в БД даже при ошибке
        try:
            add_user(user_id, username)
            logging.info(f"[start_handler] Пользователь {user_id} добавлен в БД после ошибки")
        except Exception as db_error:
            logging.error(f"[start_handler] Ошибка при добавлении пользователя в БД: {db_error}", exc_info=True)
        
        # Отправляем сообщение пользователю об ошибке
        try:
            await message.answer(
                "❌ Произошла ошибка при обработке команды. Попробуйте еще раз через несколько секунд.\n\n"
                "Если проблема сохраняется, обратитесь в поддержку.",
                reply_markup=menu_button()
            )
        except Exception as send_error:
            logging.error(f"[start_handler] Ошибка при отправке сообщения об ошибке: {send_error}", exc_info=True)

# Команда /auth - генерация ссылки для авторизации на сайте
@dp.message(Command("auth"))
async def auth_command_handler(message: Message):
    """Генерация ссылки для авторизации на WordPress сайте"""
    from config import WP_SITE_URL, WP_AUTH_PAGE, TELEGRAM_BOT_TOKEN
    import hashlib
    import urllib.parse
    
    user_id = message.from_user.id
    username = message.from_user.username or ''
    first_name = message.from_user.first_name or ''
    last_name = message.from_user.last_name or ''
    
    # Генерируем hash для проверки авторизации
    # Используем упрощенную версию на основе user_id и bot token
    secret_key = TELEGRAM_BOT_TOKEN.split(':')[1]  # Берем часть после двоеточия
    auth_string = f"{user_id}{secret_key}"
    auth_hash = hashlib.sha256(auth_string.encode()).hexdigest()[:16]  # Первые 16 символов
    
    # Формируем параметры для авторизации
    auth_params = {
        'telegram_auth': '1',
        'id': str(user_id),
        'hash': auth_hash,
        'first_name': first_name,
        'username': username
    }
    
    if last_name:
        auth_params['last_name'] = last_name
    
    # Формируем URL
    base_url = WP_SITE_URL + WP_AUTH_PAGE
    auth_url = base_url + '?' + urllib.parse.urlencode(auth_params)
    
    # Создаем кнопку с ссылкой
    auth_keyboard = InlineKeyboardMarkup(
        inline_keyboard=[
            [InlineKeyboardButton(text="🔗 Перейти на сайт для авторизации", url=auth_url)],
            [InlineKeyboardButton(text="📋 Показать ссылку", callback_data="show_auth_link")]
        ]
    )
    
    await message.answer(
        f"🌐 <b>Авторизация на сайте</b>\n\n"
        f"Нажмите кнопку ниже, чтобы перейти на сайт и автоматически авторизоваться:\n\n"
        f"<code>{auth_url}</code>",
        reply_markup=auth_keyboard,
        parse_mode="HTML"
    )
    
    # --- Callback для меню пополнения ---
    
# Команда /menu - открытие главного меню
@dp.message(Command("menu"))
async def menu_command_handler(message: Message):
    user_id = message.from_user.id
    user_tag = get_user_tag(user_id)
    
    if check_admin(user_id, message.from_user.username or ""):
        # Админское меню
        text = "🤖 Главное меню\n\nВыберите действие:"
        admin_keyboard = InlineKeyboardMarkup(
            inline_keyboard=[
                [InlineKeyboardButton(text="👤 Профиль", callback_data="menu_profile")],
                [InlineKeyboardButton(text="🚀 Начать генерацию", callback_data="menu_generate")],
                [InlineKeyboardButton(text="💰 Пополнить баланс", callback_data="menu_topup")],
                [InlineKeyboardButton(text="💼 Партнёрская программа", callback_data="menu_partner")],
                [InlineKeyboardButton(text="ℹ️ Тарифы / справка", callback_data="menu_help")],
                [InlineKeyboardButton(text="🔧 Админ меню", callback_data="admin_menu")],
            ]
        )
        await message.answer(text, reply_markup=admin_keyboard)
    else:
        # Обычное меню с учетом user_tag
        if user_tag == "tts":
            menu_text = (
                "🤖 Главное меню\n\n"
                "Перед началом генерации прослушайте примеры озвучки для выбора нейро голоса:\n"
                "<a href=\"https://genius-bot.ru/nejroset-golos-primery-golosov-dlya-ozvuchki/\">Прослушать голоса</a>\n\n"
                "Выберите действие:"
            )
        else:
            menu_text = "🤖 Главное меню\n\nВыберите действие:"
        await message.answer(menu_text, reply_markup=main_menu_keyboard(user_tag=user_tag), parse_mode="HTML" if user_tag == "tts" else None)
    
# Обработчик кнопки "📱 Меню" из ReplyKeyboard
@dp.message(F.text == "📱 Меню")
async def reply_menu_handler(message: Message):
    user_id = message.from_user.id
    user_tag = get_user_tag(user_id)
    
    if user_tag == "tts":
        menu_text = (
            "🤖 Главное меню\n\n"
            "Перед началом генерации прослушайте примеры озвучки для выбора нейро голоса:\n"
            "<a href=\"https://genius-bot.ru/nejroset-golos-primery-golosov-dlya-ozvuchki/\">Прослушать голоса</a>\n\n"
            "Выберите действие:"
        )
    elif user_tag == "markets":
        menu_text = (
            "🛍️ <b>Меню маркетплейсов</b>\n\n"
            "Создавайте профессиональные карточки товаров для Wildberries и Ozon."
        )
    else:
        menu_text = "🤖 Главное меню\n\nВыберите действие:"
    
    await message.answer(
        menu_text,
        reply_markup=main_menu_keyboard(user_tag=user_tag),
        parse_mode="HTML" if user_tag in ("tts", "markets") else None
    )

# Обработчики кнопок генерации из ReplyKeyboard
@dp.message(F.text == "🎬 Видео")
async def reply_video_handler(message: Message, state: FSMContext):
    """Обработчик кнопки 'Видео' из нижней клавиатуры"""
    await state.set_state(VideoGenStates.choose_content_type)
    # Эмулируем callback для content_video — показываем все видео-нейросети
    seedance_prices_preview = get_seedance_price_options()
    seedance_min_price = min(seedance_prices_preview.values()) if seedance_prices_preview else 0

    ai_buttons = [
        [{"text": "Veo 3.1", "callback_data": "ai_veo3"}],
        [{"text": "SORA 2", "callback_data": "ai_sora2"}],
        [{"text": f"Seedance 1.0 Pro Fast (от {seedance_min_price}₽)", "callback_data": "ai_seedance"}],
        [{"text": "Grok Imagine", "callback_data": "ai_grok"}],
    ]
    keyboard = InlineKeyboardMarkup(inline_keyboard=[[InlineKeyboardButton(**btn[0])] for btn in ai_buttons])

    await message.answer("Выберите ИИ для генерации видео:", reply_markup=keyboard)
    await state.set_state(VideoGenStates.choose_ai)

@dp.message(F.text == "🖼️ Фото")
async def reply_photo_handler(message: Message, state: FSMContext):
    """Обработчик кнопки 'Фото' из нижней клавиатуры"""
    await state.set_state(VideoGenStates.choose_content_type)
    # Эмулируем callback для content_photo
    mode_buttons = [
        {"text": "🖼️ Генерация изображения (5₽)", "callback_data": "nano_mode_generate"},
        {"text": "✏️ Редактирование изображения (5₽)", "callback_data": "nano_mode_edit"},
        {"text": "🔍 Увеличение разрешения (от 8₽)", "callback_data": "nano_mode_upscale"},
        {"text": "⭐ PRO - Генерация с расширенными параметрами (18₽)", "callback_data": "nano_mode_pro"},
    ]
    keyboard = InlineKeyboardMarkup(
        inline_keyboard=[[InlineKeyboardButton(**btn)] for btn in mode_buttons]
    )
    await message.answer(
        "Выберите режим генерации изображений:\n\n"
        "🖼️ Генерация/✏️ Редактирование: <b>Nano Banana</b>\n"
        "⭐ PRO: <b>Nano Banana Pro</b> на базе Gemini 3.0 Pro Image\n"
        "🔍 Увеличение: <b>Topaz Image Upscale</b>",
        reply_markup=keyboard,
        parse_mode="HTML"
    )
    await state.set_state(NanoBananaStates.choose_mode)

@dp.message(F.text == "🎵 Музыка")
async def reply_music_handler(message: Message, state: FSMContext):
    """Обработчик кнопки 'Музыка' из нижней клавиатуры"""
    await state.set_state(VideoGenStates.choose_content_type)
    # Эмулируем callback для content_music
    model_buttons = [
        {"text": "V3.5 - Структурированные песни (15₽)", "callback_data": "suno_model_V3_5"},
        {"text": "V4 - Улучшенный вокал (15₽)", "callback_data": "suno_model_V4"},
        {"text": "V4.5 - Умные промпты (15₽)", "callback_data": "suno_model_V4_5"},
        {"text": "V4.5PLUS - Богатое звучание (15₽)", "callback_data": "suno_model_V4_5PLUS"},
        {"text": "V5 - Быстрая генерация (15₽)", "callback_data": "suno_model_V5"},
    ]
    keyboard = InlineKeyboardMarkup(
        inline_keyboard=[[InlineKeyboardButton(**btn)] for btn in model_buttons]
    )
    await message.answer("Выберите модель Suno для генерации музыки:", reply_markup=keyboard)
    await state.set_state(SunoStates.choose_model)

@dp.message(F.text == "🎙️ Текст в голос")
async def reply_tts_handler(message: Message, state: FSMContext):
    """Обработчик кнопки 'Текст в голос' из нижней клавиатуры"""
    await state.set_state(VideoGenStates.choose_content_type)
    # Эмулируем callback для content_tts
    text = (
        "🎙️ Текст в речь\n\n"
        "Перед выбором голоса прослушайте примеры озвучки:\n"
        "<a href=\"https://genius-bot.ru/nejroset-golos-primery-golosov-dlya-ozvuchki/\">Прослушать голоса</a>\n\n"
        "Выберите голос для генерации:"
    )
    keyboard = InlineKeyboardMarkup(
        inline_keyboard=[
            [InlineKeyboardButton(text="🎙️ Выбрать голос для генерации", callback_data="tts_show_voices")]
        ]
    )
    await message.answer(text, reply_markup=keyboard, parse_mode="Markdown")
    await state.set_state(TTSStates.choose_voice_info)

# Обработчики кнопок для маркетплейсов
@dp.message(F.text == "📦 Карточка товара")
async def reply_markets_card_handler(message: Message, state: FSMContext):
    """Обработчик кнопки 'Карточка товара' из нижней клавиатуры"""
    user_id = message.from_user.id
    user_tag = get_user_tag(user_id)
    
    if user_tag != "markets":
        await message.answer("❌ Эта функция доступна только для пользователей маркетплейсов.")
        return
    
    # Эмулируем нажатие inline-кнопки markets_create_card
    await state.set_state(MarketsStates.choose_product_category)
    
    # Спрашиваем категорию товара
    category_buttons = [
        {"text": "👕 Одежда / 👟 Обувь / ⌚ Аксессуары", "callback_data": "markets_category_fashion"},
        {"text": "📦 Другой товар", "callback_data": "markets_category_other"},
    ]
    keyboard = InlineKeyboardMarkup(
        inline_keyboard=[[InlineKeyboardButton(**btn)] for btn in category_buttons] + 
        [[InlineKeyboardButton(text="❌ Отмена", callback_data="cancel_generation")]]
    )
    await message.answer(
        "📦 <b>Создание карточки товара</b>\n\n"
        "🤖 <b>Как это работает:</b>\n"
        "1️⃣ Выберите категорию товара ниже\n"
        "2️⃣ Отправьте фото вашего товара\n"
        "3️⃣ Добавьте заголовок и преимущества товара\n"
        "4️⃣ При необходимости укажите дополнительные условия (стиль, фон, композицию)\n"
        "5️⃣ ИИ автоматически создаст профессиональную карточку товара\n\n"
        "✨ <b>Что будет создано:</b>\n"
        "• Оптимизированное изображение для маркетплейса\n"
        "• Улучшенный фон и качество фото\n"
        "• Готовое изображение для Wildberries и Ozon\n\n"
        "📋 <b>Выберите категорию товара:</b>",
        reply_markup=keyboard,
        parse_mode="HTML"
    )

@dp.message(F.text == "🎬 Видеообложка")
async def reply_markets_video_handler(message: Message, state: FSMContext):
    """Обработчик кнопки 'Видеообложка' из нижней клавиатуры"""
    user_id = message.from_user.id
    user_tag = get_user_tag(user_id)
    
    if user_tag != "markets":
        await message.answer("❌ Эта функция доступна только для пользователей маркетплейсов.")
        return
    
    # Эмулируем нажатие inline-кнопки markets_create_video
    await message.answer(
        "🎬 <b>Создание видеообложки</b>\n\n"
        "Отправьте фото товара, и мы создадим для вас видеообложку для маркетплейса.",
        parse_mode="HTML"
    )
    await state.set_state(MarketsStates.get_video_image)
    
# Профиль
@dp.message(F.text == "👤 Профиль")
async def reply_profile(message: Message, state: FSMContext):
    user_id = message.from_user.id
    balance = get_balance(user_id)
    
    # Добавляем кнопку для авторизации на сайте
    profile_keyboard = InlineKeyboardMarkup(
        inline_keyboard=[
            [InlineKeyboardButton(text="🌐 Авторизация на сайте", callback_data="wp_auth_link")],
            [InlineKeyboardButton(text="🔙 Меню", callback_data="open_menu")]
        ]
    )
    
    await message.answer(
        f"👤 Профиль пользователя:\n\nИмя: {message.from_user.full_name}\nБаланс: {balance:.2f} токенов",
        reply_markup=profile_keyboard
    )

# Начать генерацию
@dp.message(F.text == "🚀 Начать генерацию")
async def reply_start_generation(message: Message, state: FSMContext):
    # Используем новое меню выбора типа контента
    content_buttons = [
        {"text": "🎬 Видео", "callback_data": "content_video"},
        {"text": "🖼️ Фото", "callback_data": "content_photo"},
        {"text": "🎵 Музыка или песня", "callback_data": "content_music"},
        {"text": "🎤 Голос в текст", "callback_data": "content_speech_to_text"},
        {"text": "🎙️ Текст в речь", "callback_data": "content_tts"},
        {"text": "🔧 Остальные функции Suno", "callback_data": "content_suno_other"},
        {"text": "📋 Показать все нейросети", "callback_data": "content_all_ai"},
    ]
    keyboard = InlineKeyboardMarkup(
        inline_keyboard=[[InlineKeyboardButton(**btn)] for btn in content_buttons]
    )
    await message.answer(
        "Выберите тип контента для генерации:",
        reply_markup=keyboard
    )
    await state.set_state(VideoGenStates.choose_content_type)

# Пополнить баланс
@dp.message(F.text == "💰 Пополнить баланс")
async def reply_topup(message: Message, state: FSMContext):
    chat_id = message.from_user.id
    balance = get_balance(chat_id)
    kb = InlineKeyboardMarkup(
        inline_keyboard=[
            [InlineKeyboardButton(text="Пополнить 250₽", callback_data="topup_250")],
            [InlineKeyboardButton(text="Пополнить 500₽", callback_data="topup_500")],
            [InlineKeyboardButton(text="Пополнить 1000₽", callback_data="topup_1000")]
        ]
    )
    await message.answer(
        f"💰 Ваш баланс: {balance:.2f} токенов\nВыберите сумму для пополнения:",
        reply_markup=kb
    )    
    
@dp.callback_query(F.data == "menu_topup")
async def menu_topup_callback(callback: CallbackQuery):
    chat_id = callback.from_user.id
    balance = get_balance(chat_id)
    user_tag = get_user_tag(chat_id)

    # Основная клавиатура с вариантами пополнения
    buttons = []
    
    # Для пользователей с параметром start=Sv_lana0707 добавляем 200₽, 200₽ и 300₽
    if user_tag == "start_Sv_lana0707":
        buttons.append([InlineKeyboardButton(text="Пополнить 200₽", callback_data="topup_200")])
           
    # Для tts-пользователей первым пунктом добавляем 200₽
    if user_tag == "tts":
        buttons.append([InlineKeyboardButton(text="Пополнить 200₽", callback_data="topup_200")])
    
    # Остальные пункты для всех пользователей
    buttons.extend([
        [InlineKeyboardButton(text="Пополнить 250₽", callback_data="topup_250")],
        [InlineKeyboardButton(text="Пополнить 500₽", callback_data="topup_500")],
        [InlineKeyboardButton(text="Пополнить 1000₽", callback_data="topup_1000")],
        [InlineKeyboardButton(text="Пополнить 2000₽", callback_data="topup_2000")],
        [InlineKeyboardButton(text="Пополнить 5000₽", callback_data="topup_5000")]
    ])
    
    keyboard = InlineKeyboardMarkup(inline_keyboard=buttons)

    # Добавляем кнопку "Меню" внизу
    keyboard.inline_keyboard.extend(menu_button().inline_keyboard)

    # Текст сообщения
    text = f"💰 Ваш баланс: {balance:.2f} токенов\nВыберите сумму для пополнения:"

    # Отправляем сообщение с клавиатурой
    await callback.message.edit_text(text=text, reply_markup=keyboard)
    await callback.answer()
    
# @dp.callback_query(F.data == "menu_topup")
# async def menu_topup_callback(callback: CallbackQuery):
#     chat_id = callback.from_user.id
#     balance = get_balance(chat_id)
#     kb = InlineKeyboardMarkup(
#         inline_keyboard=[
#             [InlineKeyboardButton(text="Пополнить 500 токенов", callback_data="topup_500")],
#             [InlineKeyboardButton(text="Пополнить 1000 токенов", callback_data="topup_1000")],
#             [InlineKeyboardButton(text="Пополнить 2000 токенов", callback_data="topup_2000")],
#             [InlineKeyboardButton(text="Пополнить 5000 токенов", callback_data="topup_5000")]
#         ]
#     )
#     await callback.message.answer(
#         f"💰 Ваш баланс: {balance:.2f} токенов\nВыберите сумму для пополнения:",
#         reply_markup=kb
#     )
#     keyboard.inline_keyboard.extend(menu_button().inline_keyboard)
#     await callback.message.edit_text(text, reply_markup=keyboard)
#     await callback.answer()

# --- Callback для выбора конкретной суммы ---
@dp.callback_query(F.data.startswith("topup_"))
async def topup_amount_callback(callback: CallbackQuery):
    chat_id = callback.from_user.id
    amount_str = callback.data.split("_")[1]
    amount = int(amount_str)  # сумма в рублях
    
    # Генерируем ссылку и label
    payment_link, label = generate_yoomoney_link(chat_id, amount)
    
    # Сохраняем label для проверки платежа позже
    active_payments[chat_id] = label
    
    # Создаем клавиатуру с кнопкой оплаты
    payment_keyboard = InlineKeyboardMarkup(
        inline_keyboard=[
            [InlineKeyboardButton(text=f"Оплатить {amount}₽", url=payment_link)]
        ]
    )
    
    await callback.message.answer(
        text=f"Для пополнения баланса {amount}₽ нажмите кнопку ниже:",
        reply_markup=payment_keyboard
    )
    await callback.answer("Ссылка для оплаты создана ✅")


# === ПОЛЬЗОВАТЕЛЬСКИЙ ФУНКЦИОНАЛ ПАПОК С КАНАЛАМИ ===
@dp.callback_query(F.data == "user_folders")
async def user_folders_callback(callback: CallbackQuery):
    user_id = callback.from_user.id
    folders = get_all_folders(include_inactive=False)
    user_subscriptions = get_user_folder_subscriptions(user_id)
    subscribed_folder_ids = {sub['id'] for sub in user_subscriptions}
    
    if not folders:
        text = "📁 <b>Папки с каналами</b>\n\nДоступных папок пока нет."
        keyboard = InlineKeyboardMarkup(
            inline_keyboard=[
                [InlineKeyboardButton(text="🔙 Меню", callback_data="open_menu")]
            ]
        )
    else:
        lines = ["📁 <b>Доступные папки с каналами</b>\n"]
        keyboard_rows = []
        
        for folder in folders:
            is_subscribed = folder['id'] in subscribed_folder_ids
            status = "✅ Подключено" if is_subscribed else "💰"
            lines.append(
                f"{status} <b>{folder['name']}</b>\n"
                f"   💰 Цена: {folder['price']:.2f}₽\n"
            )
            if is_subscribed:
                keyboard_rows.append([
                    InlineKeyboardButton(
                        text=f"✅ {folder['name']} (подключено)",
                        callback_data=f"user_folder_view_{folder['id']}"
                    )
                ])
            else:
                keyboard_rows.append([
                    InlineKeyboardButton(
                        text=f"📂 {folder['name']} ({folder['price']:.2f}₽)",
                        callback_data=f"user_folder_view_{folder['id']}"
                    )
                ])
        
        keyboard_rows.append([InlineKeyboardButton(text="🔙 Меню", callback_data="open_menu")])
        
        text = "\n".join(lines)
        keyboard = InlineKeyboardMarkup(inline_keyboard=keyboard_rows)
    
    await callback.message.edit_text(text, reply_markup=keyboard, parse_mode="HTML")
    await callback.answer()


@dp.callback_query(F.data.startswith("user_folder_view_"))
async def user_folder_view_callback(callback: CallbackQuery):
    user_id = callback.from_user.id
    folder_id = int(callback.data.split("_")[-1])
    folder = get_folder_by_id(folder_id)
    
    if not folder or not folder['is_active']:
        await callback.answer("❌ Папка не найдена или недоступна")
        return
    
    channels = get_folder_channels(folder_id)
    is_subscribed = is_user_subscribed_to_folder(user_id, folder_id)
    balance = get_balance(user_id)
    
    lines = [
        f"📂 <b>{folder['name']}</b>\n",
        f"💰 Цена подключения: {folder['price']:.2f}₽\n",
        f"📊 Каналов в папке: {len(channels)}\n"
    ]
    
    if folder['description']:
        lines.append(f"\n📝 {folder['description']}\n")
    
    if channels:
        lines.append("\n<b>Каналы в папке:</b>")
        for idx, channel in enumerate(channels, 1):
            link = channel['channel_link'] or f"https://t.me/{channel['channel_username']}"
            title = channel['channel_title'] or channel['channel_username']
            lines.append(f"{idx}. {title}")
            lines.append(f"   🔗 {link}")
    
    keyboard_rows = []
    
    if is_subscribed:
        lines.append("\n✅ <b>Вы уже подключены к этой папке!</b>")
        keyboard_rows.append([
            InlineKeyboardButton(text="📋 Показать каналы", callback_data=f"user_folder_channels_{folder_id}")
        ])
    else:
        lines.append(f"\n💳 Ваш баланс: {balance:.2f}₽")
        if balance >= folder['price']:
            keyboard_rows.append([
                InlineKeyboardButton(
                    text=f"✅ Подключиться за {folder['price']:.2f}₽",
                    callback_data=f"user_folder_subscribe_{folder_id}"
                )
            ])
        else:
            lines.append(f"\n❌ Недостаточно средств для подключения")
            keyboard_rows.append([
                InlineKeyboardButton(
                    text="💰 Пополнить баланс",
                    callback_data="menu_topup"
                )
            ])
    
    keyboard_rows.append([InlineKeyboardButton(text="🔙 Все папки", callback_data="user_folders")])
    
    await callback.message.edit_text("\n".join(lines), reply_markup=InlineKeyboardMarkup(inline_keyboard=keyboard_rows), parse_mode="HTML")
    await callback.answer()


@dp.callback_query(F.data.startswith("user_folder_subscribe_"))
async def user_folder_subscribe_callback(callback: CallbackQuery):
    user_id = callback.from_user.id
    folder_id = int(callback.data.split("_")[-1])
    folder = get_folder_by_id(folder_id)
    
    if not folder or not folder['is_active']:
        await callback.answer("❌ Папка не найдена или недоступна")
        return
    
    if is_user_subscribed_to_folder(user_id, folder_id):
        await callback.answer("✅ Вы уже подключены к этой папке!")
        return
    
    balance = get_balance(user_id)
    
    if balance < folder['price']:
        await callback.answer(f"❌ Недостаточно средств. Нужно {folder['price']:.2f}₽, у вас {balance:.2f}₽")
        return
    
    # Списываем средства
    if deduct_balance(user_id, folder['price']):
        # Подписываем пользователя
        if subscribe_user_to_folder(user_id, folder_id, folder['price']):
            channels = get_folder_channels(folder_id)
            
            lines = [
                f"✅ <b>Вы успешно подключились к папке!</b>\n",
                f"📂 {folder['name']}\n",
                f"💰 С вашего баланса списано: {folder['price']:.2f}₽\n",
                f"💳 Остаток на балансе: {get_balance(user_id):.2f}₽\n"
            ]
            
            if channels:
                lines.append("\n<b>Каналы в папке:</b>")
                for idx, channel in enumerate(channels, 1):
                    link = channel['channel_link'] or f"https://t.me/{channel['channel_username']}"
                    title = channel['channel_title'] or channel['channel_username']
                    lines.append(f"{idx}. {title}")
                    lines.append(f"   🔗 {link}")
            
            keyboard = InlineKeyboardMarkup(
                inline_keyboard=[
                    [InlineKeyboardButton(text="🔙 Все папки", callback_data="user_folders")]
                ]
            )
            
            await callback.message.edit_text("\n".join(lines), reply_markup=keyboard, parse_mode="HTML")
            await callback.answer("✅ Подключение успешно!")
        else:
            # Возвращаем средства при ошибке
            update_balance(user_id, folder['price'])
            await callback.answer("❌ Ошибка при подключении. Средства возвращены.")
    else:
        await callback.answer("❌ Ошибка при списании средств")


@dp.callback_query(F.data.startswith("user_folder_channels_"))
async def user_folder_channels_callback(callback: CallbackQuery):
    user_id = callback.from_user.id
    folder_id = int(callback.data.split("_")[-1])
    folder = get_folder_by_id(folder_id)
    
    if not folder:
        await callback.answer("❌ Папка не найдена")
        return
    
    if not is_user_subscribed_to_folder(user_id, folder_id):
        await callback.answer("❌ Вы не подключены к этой папке")
        return
    
    channels = get_folder_channels(folder_id)
    
    if not channels:
        await callback.answer("❌ В папке нет каналов")
        return
    
    lines = [
        f"📂 <b>{folder['name']}</b>\n",
        f"📊 Каналов: {len(channels)}\n",
        f"\n<b>Каналы в папке:</b>\n"
    ]
    
    keyboard_rows = []
    for channel in channels:
        link = channel['channel_link'] or f"https://t.me/{channel['channel_username']}"
        title = channel['channel_title'] or channel['channel_username']
        lines.append(f"• {title}")
        keyboard_rows.append([
            InlineKeyboardButton(
                text=f"🔗 {title}",
                url=link
            )
        ])
    
    keyboard_rows.append([InlineKeyboardButton(text="🔙 Назад", callback_data=f"user_folder_view_{folder_id}")])
    
    await callback.message.edit_text("\n".join(lines), reply_markup=InlineKeyboardMarkup(inline_keyboard=keyboard_rows), parse_mode="HTML")
    await callback.answer()


# Обработчик успешных платежей
@dp.message(F.successful_payment)
async def handle_successful_payment(message: Message):
    """Обработка успешных платежей от YooMoney"""
    if message.successful_payment:
        payment = message.successful_payment
        user_id = message.from_user.id
        username = message.from_user.username or "user"
        first_name = message.from_user.first_name or "Пользователь"
        
        # Получаем информацию о платеже
        amount = payment.total_amount / 100  # YooMoney возвращает сумму в копейках
        currency = payment.currency
        invoice_payload = payment.invoice_payload
        
        # Обновляем баланс пользователя
        tokens_to_add = int(amount)  # 1₽ = 1 токен
        update_balance(user_id, tokens_to_add)
        
        # Отправляем уведомление админу
        await send_admin_payment_notification(user_id, username, first_name, amount, tokens_to_add)
        
        # Уведомляем пользователя
        await message.answer(
            f"✅ Платеж успешно обработан!\n\n"
            f"💰 Сумма: {amount}₽\n"
            f"🎫 Получено токенов: {tokens_to_add}\n"
            f"💳 Текущий баланс: {get_balance(user_id)}₽",
            reply_markup=menu_button()
        )

# Определяем callback
async def balance_callback(callback: CallbackQuery):
    chat_id = callback.from_user.id
    balance = get_balance(chat_id)
    kb = InlineKeyboardMarkup(
        inline_keyboard=[
            [InlineKeyboardButton(text="Пополнить 250₽", callback_data="topup_250")],
            [InlineKeyboardButton(text="Пополнить 500₽", callback_data="topup_500")],
            [InlineKeyboardButton(text="Пополнить 1000₽", callback_data="topup_1000")]
        ]
    )
    await callback.message.answer(f"💰 Ваш баланс: {balance:.2f} токенов", reply_markup=kb)
    await callback.answer()
async def topup_callback(callback: CallbackQuery):
    chat_id = callback.from_user.id
    amount_rub = int(callback.data.split("_")[1])
    tokens = amount_rub * 0.1
    update_balance(chat_id, tokens)
    await callback.message.answer(
        f"✅ Баланс пополнен на {tokens:.1f} токенов! Текущий баланс: {get_balance(chat_id):.1f} токенов"
    )
    await callback.answer()


# --- Generation ---
async def start_generation(callback: CallbackQuery, state: FSMContext):
    await callback.answer()
    # Используем новое меню выбора типа контента
    content_buttons = [
        {"text": "🎬 Видео", "callback_data": "content_video"},
        {"text": "🖼️ Фото", "callback_data": "content_photo"},
        {"text": "🎵 Музыка или песня", "callback_data": "content_music"},
        {"text": "🎤 Голос в текст", "callback_data": "content_speech_to_text"},
        {"text": "🎙️ Текст в речь", "callback_data": "content_tts"},
        {"text": "🔧 Остальные функции Suno", "callback_data": "content_suno_other"},
        {"text": "📋 Показать все нейросети", "callback_data": "content_all_ai"},
    ]
    keyboard = InlineKeyboardMarkup(
        inline_keyboard=[[InlineKeyboardButton(**btn)] for btn in content_buttons]
    )
    await callback.message.edit_text(
        "Выберите тип контента для генерации:",
        reply_markup=keyboard
    )
    await state.set_state(VideoGenStates.choose_content_type)

async def select_model(callback: CallbackQuery, state: FSMContext):
    await callback.answer()
    model_key = callback.data.split("_")[1]
    await state.update_data(model_key=model_key, params={})

    if model_key == "Veo 3":
        keyboard = [
            [InlineKeyboardButton(text="💎 Quality", callback_data="veo_variant_quality")],
            [InlineKeyboardButton(text="⚡ Fast", callback_data="veo_variant_fast")]
        ]
        await callback.message.edit_text("Выберите вариант Veo 3:", reply_markup=InlineKeyboardMarkup(keyboard))
        await state.set_state(GenerationStates.SELECT_VEO_VARIANT)
    else:
        # model_id = MODELS_AVAILABLE[model_key]['id']  # Закомментировано - используется старая архитектура
        model_id = "unknown"
        await state.update_data(model_id=model_id)
        cost = await get_generation_cost(model_key)
        await callback.message.edit_text(
            f"Вы выбрали **{model_key}**. Стоимость: `{cost}` токенов.\n\n📝 Отправьте ваш промпт.",
            parse_mode="Markdown"
        )
        await state.set_state(GenerationStates.GET_FINAL_PROMPT)

# --- Veo 3 Steps ---
async def select_veo_variant(callback: CallbackQuery, state: FSMContext):
    await callback.answer()
    variant = callback.data.split("_")[2]
    data = await state.get_data()
    model_key = data['model_key']
    # await state.update_data(veo_variant=variant, model_id=MODELS_AVAILABLE[model_key][variant])  # Закомментировано - используется старая архитектура
    await state.update_data(veo_variant=variant, model_id="unknown")

    keyboard = [
        [InlineKeyboardButton(text="📄 Текст → Видео", callback_data="veo_task_text_to_video")],
        [InlineKeyboardButton(text="🖼️ Картинка → Видео", callback_data="veo_task_image_to_video")]
    ]
    await callback.message.edit_text("Выберите тип генерации:", reply_markup=InlineKeyboardMarkup(keyboard))
    await state.set_state(GenerationStates.SELECT_VEO_TASK)

async def select_veo_task(callback: CallbackQuery, state: FSMContext):
    await callback.answer()
    task = callback.data.split("_")[2]
    await state.update_data(task=task)

    if task == "image_to_video":
        await callback.message.edit_text("🖼️ Отправьте картинку для генерации")
        await state.set_state(GenerationStates.GET_VEO_IMAGE)
    else:
        keyboard = [
            [InlineKeyboardButton(text="Landscape (16:9)", callback_data="veo_aspect_16:9"),
             InlineKeyboardButton(text="Portrait (9:16)", callback_data="veo_aspect_9:16")],
            [InlineKeyboardButton(text="Square (1:1)", callback_data="veo_aspect_1:1"),
             InlineKeyboardButton(text="Photo (4:5)", callback_data="veo_aspect_4:5")]
        ]
        await callback.message.edit_text("Шаг 1/3: Выберите соотношение сторон", reply_markup=InlineKeyboardMarkup(keyboard))
        await state.set_state(GenerationStates.SET_VEO_ASPECT_RATIO)

# async def get_veo_image(message: Message, state: FSMContext):
#     photo = message.photo[-1]  # берём наибольшую версию
#     file_info = await bot.get_file(photo.file_id)
#     file_url = f"https://api.telegram.org/file/bot{TELEGRAM_BOT_TOKEN}/{file_info.file_path}"
    
#     await state.update_data(image_urls=[file_url])  # передаем URL в create_video_task

#     keyboard = [
#         [InlineKeyboardButton(text="Landscape (16:9)", callback_data="veo_aspect_16:9"),
#          InlineKeyboardButton(text="Portrait (9:16)", callback_data="veo_aspect_9:16")],
#         [InlineKeyboardButton(text="Square (1:1)", callback_data="veo_aspect_1:1"),
#          InlineKeyboardButton(text="Photo (4:5)", callback_data="veo_aspect_4:5")]
#     ]
#     await message.answer("🖼️ Картинка принята! Выберите соотношение сторон:", reply_markup=InlineKeyboardMarkup(keyboard))
#     await state.set_state(GenerationStates.SET_VEO_ASPECT_RATIO)

# --- Common Veo Steps ---
async def set_veo_aspect_ratio(callback: CallbackQuery, state: FSMContext):
    await callback.answer()
    await state.update_data(params={**(await state.get_data()).get("params", {}), "aspect_ratio": callback.data.split("_")[2]})
    keyboard = [
        [InlineKeyboardButton(text="Cinematic", callback_data="veo_style_cinematic"),
         InlineKeyboardButton(text="Anime", callback_data="veo_style_anime")],
        [InlineKeyboardButton(text="Photorealistic", callback_data="veo_style_photorealistic")]
    ]
    await callback.message.edit_text("Шаг 2/3: Выберите стиль", reply_markup=InlineKeyboardMarkup(keyboard))
    await state.set_state(GenerationStates.SET_VEO_STYLE)

async def set_veo_style(callback: CallbackQuery, state: FSMContext):
    await callback.answer()
    data = await state.get_data()
    params = data.get("params", {})
    params["style"] = callback.data.split("_")[2]
    await state.update_data(params=params)
    keyboard = [
        [InlineKeyboardButton(text="Low", callback_data="veo_motion_low"),
         InlineKeyboardButton(text="Medium", callback_data="veo_motion_medium"),
         InlineKeyboardButton(text="High", callback_data="veo_motion_high")]
    ]
    await callback.message.edit_text("Шаг 3/3: Выберите силу движения", reply_markup=InlineKeyboardMarkup(keyboard))
    await state.set_state(GenerationStates.SET_VEO_MOTION)

async def set_veo_motion(callback: CallbackQuery, state: FSMContext):
    await callback.answer()
    data = await state.get_data()
    params = data.get("params", {})
    params["motion_strength"] = callback.data.split("_")[2]
    await state.update_data(params=params)

    cost = await get_generation_cost(data['model_key'], data.get('veo_variant'))
    await callback.message.edit_text(f"Настройки завершены. Стоимость: `{cost}` токенов.\nОтправьте промпт.", parse_mode="Markdown")
    await state.set_state(GenerationStates.GET_FINAL_PROMPT)

async def get_final_prompt(message: Message, state: FSMContext):
    data = await state.get_data()
    user_id = message.from_user.id
    prompt = message.text

    cost = await get_generation_cost(data['model_key'], data.get('veo_variant'))
    if get_balance(user_id) < cost:
        await message.answer(f"💸 Недостаточно средств. Ваш баланс: {get_balance(user_id):.2f}₽")
        await state.clear()
        return

    deduct_balance(user_id, cost)
    await message.answer("⏳ Генерация запущена...")

    # result_url = get_generation(data['model_key'], prompt, data.get('params'), data.get('image_path'))  # Функция не импортирована - старая архитектура
    result_url = "http://example.com"
    await message.answer(f"✅ Готово! [Скачать результат]({result_url})", disable_web_page_preview=True)
    await state.clear()


async def start_api():
    config = uvicorn.Config(app, host="0.0.0.0", port=8010, log_level="info")
    server = uvicorn.Server(config)
    await server.serve()
    
async def start_bot():
    await dp.start_polling(bot)

# === АДМИНСКОЕ МЕНЮ ===

@dp.callback_query(F.data == "admin_campaign_tags")
async def admin_campaign_tags_callback(callback: CallbackQuery):
    if callback.from_user.id != 367692958:
        await callback.answer("❌ У вас нет прав для выполнения этого действия")
        return
    await update_campaign_tags_message(callback.message.chat.id, callback.message.message_id)
    await callback.answer()


@dp.callback_query(F.data == "admin_campaign_tag_add")
async def admin_campaign_tag_add(callback: CallbackQuery, state: FSMContext):
    if callback.from_user.id != 367692958:
        await callback.answer("❌ У вас нет прав для выполнения этого действия")
        return
    keyboard = InlineKeyboardMarkup(
        inline_keyboard=[
            [InlineKeyboardButton(text="❌ Отмена", callback_data="admin_campaign_tag_cancel")]
        ]
    )
    prompt_message = await callback.message.answer(
        "Введите новый тег кампании.\n\n"
        "Формат: <code>tag описание</code>\n"
        "• tag — латиница, цифры, символы _ и - (до 50 символов)\n"
        "• описание (необязательно) — текст для вашего удобства",
        reply_markup=keyboard,
        parse_mode="HTML"
    )
    await state.update_data(
        campaign_tags_message_id=callback.message.message_id,
        campaign_tags_chat_id=callback.message.chat.id,
        campaign_tags_prompt_message_id=prompt_message.message_id
    )
    await state.set_state(AdminTagStates.waiting_for_tag)
    await callback.answer()


@dp.callback_query(F.data == "admin_campaign_tag_cancel")
async def admin_campaign_tag_cancel(callback: CallbackQuery, state: FSMContext):
    if callback.from_user.id != 367692958:
        await callback.answer("❌ У вас нет прав для выполнения этого действия")
        return

    data = await state.get_data()
    await state.clear()

    message_id = data.get("campaign_tags_message_id")
    chat_id = data.get("campaign_tags_chat_id") or callback.message.chat.id
    prompt_message_id = data.get("campaign_tags_prompt_message_id")

    if prompt_message_id:
        try:
            await bot.delete_message(chat_id=chat_id, message_id=prompt_message_id)
        except TelegramBadRequest:
            try:
                await bot.edit_message_text(
                    text="Добавление тега отменено.",
                    chat_id=chat_id,
                    message_id=prompt_message_id
                )
            except TelegramBadRequest:
                pass
    else:
        try:
            await callback.message.edit_text("Добавление тега отменено.")
        except TelegramBadRequest:
            pass

    if message_id and chat_id:
        await update_campaign_tags_message(chat_id, message_id)

    await callback.answer("Добавление отменено")


@dp.callback_query(F.data.startswith("admin_campaign_tag_delete_"))
async def admin_campaign_tag_delete(callback: CallbackQuery):
    if callback.from_user.id != 367692958:
        await callback.answer("❌ У вас нет прав для выполнения этого действия")
        return

    try:
        tag_id = int(callback.data.split("_")[-1])
    except (ValueError, IndexError):
        await callback.answer("Некорректный идентификатор тега", show_alert=True)
        return

    success = delete_campaign_tag(tag_id)
    await update_campaign_tags_message(callback.message.chat.id, callback.message.message_id)

    if success:
        await callback.answer("Тег удалён")
    else:
        await callback.answer("Не удалось удалить тег", show_alert=True)


@dp.message(AdminTagStates.waiting_for_tag)
async def admin_campaign_tag_add_message(message: Message, state: FSMContext):
    if not check_admin(message.from_user.id, message.from_user.username or ""):
        await message.answer("❌ У вас нет прав для выполнения этого действия.")
        return

    text = (message.text or "").strip()
    if not text:
        await message.answer("❌ Тег не может быть пустым. Введите значение ещё раз.")
        return

    parts = text.split(maxsplit=1)
    tag_value = parts[0].strip().lower()
    description = parts[1].strip() if len(parts) > 1 else None

    if not re.fullmatch(r"[a-z0-9_\-]{1,50}", tag_value):
        await message.answer("❌ Тег должен содержать латинские буквы, цифры, символы _ или - и быть длиной до 50 символов.")
        return

    success, error = create_campaign_tag(tag_value, description)
    if not success:
        if error == "duplicate":
            await message.answer(f"⚠️ Тег #{tag_value} уже существует.")
        elif error == "empty":
            await message.answer("❌ Тег не может быть пустым. Попробуйте снова.")
        else:
            await message.answer(f"❌ Ошибка при создании тега: {error}")
        return

    data = await state.get_data()
    chat_id = data.get("campaign_tags_chat_id") or message.chat.id
    prompt_message_id = data.get("campaign_tags_prompt_message_id")

    if prompt_message_id:
        try:
            await bot.delete_message(chat_id=chat_id, message_id=prompt_message_id)
        except TelegramBadRequest:
            try:
                await bot.edit_message_text(
                    chat_id=chat_id,
                    message_id=prompt_message_id,
                    text=f"✅ Тег #{tag_value} успешно добавлен.",
                    reply_markup=None
                )
            except TelegramBadRequest:
                pass

    await message.answer(f"✅ Тег #{tag_value} успешно добавлен.")

    message_id = data.get("campaign_tags_message_id")
    if message_id and chat_id:
        await update_campaign_tags_message(chat_id, message_id)
    else:
        summary_text, summary_keyboard = await build_campaign_tags_view()
        await message.answer(summary_text, reply_markup=summary_keyboard, parse_mode="HTML", disable_web_page_preview=True)
    await state.clear()


@dp.callback_query(F.data == "admin_users_stats")
async def admin_users_stats_callback(callback: CallbackQuery):
    if callback.from_user.id != 367692958:
        await callback.answer("❌ У вас нет прав для выполнения этого действия")
        return
    
    from database import get_users_stats
    
    # Статистика за день
    day_stats = get_users_stats("day")
    week_stats = get_users_stats("week")
    month_stats = get_users_stats("month")
    
    text = (
        f"📊 Статистика пользователей\n\n"
        f"📅 За сегодня:\n"
        f"• Новых пользователей: {day_stats['new_users']}\n"
        f"• Активных пользователей: {day_stats['active_users']}\n\n"
        f"📅 За неделю:\n"
        f"• Новых пользователей: {week_stats['new_users']}\n"
        f"• Активных пользователей: {week_stats['active_users']}\n\n"
        f"📅 За месяц:\n"
        f"• Новых пользователей: {month_stats['new_users']}\n"
        f"• Активных пользователей: {month_stats['active_users']}\n\n"
        f"👥 Всего пользователей: {month_stats['total_users']}"
    )
    
    keyboard = InlineKeyboardMarkup(
        inline_keyboard=[
            [InlineKeyboardButton(text="🔄 Обновить", callback_data="admin_users_stats")],
            [InlineKeyboardButton(text="🔙 Админ меню", callback_data="admin_menu")]
        ]
    )
    
    await callback.message.edit_text(text, reply_markup=keyboard)
    await callback.answer()


@dp.callback_query(F.data == "admin_generations_stats")
async def admin_generations_stats_callback(callback: CallbackQuery):
    if callback.from_user.id != 367692958:
        await callback.answer("❌ У вас нет прав для выполнения этого действия")
        return
    
    from database import get_generations_stats
    
    # Статистика за день
    day_stats = get_generations_stats("day")
    
    text = f"🎬 Статистика генераций за сегодня\n\n"
    text += f"📊 Общая статистика:\n"
    text += f"• Всего генераций: {day_stats['total_generations']}\n"
    text += f"• Общий доход: {day_stats['total_revenue']:.2f}₽\n\n"
    
    text += f"📈 По типам нейросетей:\n"
    for gen in day_stats['generations_by_type']:
        request_type = gen['request_type']
        count = gen['count']
        cost = gen['total_cost'] or 0
        
        # Переводим типы в читаемые названия
        type_names = {
            'veo3': 'VEO 3.1',
            'sora2': 'SORA 2',
            'suno': 'Suno',
            'nano_banana': 'Nano Banana'
        }
        
        type_name = type_names.get(request_type, request_type)
        text += f"• {type_name}: {count} генераций ({cost:.2f}₽)\n"
    
    keyboard = InlineKeyboardMarkup(
        inline_keyboard=[
            [InlineKeyboardButton(text="🔄 Обновить", callback_data="admin_generations_stats")],
            [InlineKeyboardButton(text="🔙 Админ меню", callback_data="admin_menu")]
        ]
    )
    
    await callback.message.edit_text(text, reply_markup=keyboard)
    await callback.answer()


@dp.callback_query(F.data == "admin_payments_stats")
async def admin_payments_stats_callback(callback: CallbackQuery):
    if callback.from_user.id != 367692958:
        await callback.answer("❌ У вас нет прав для выполнения этого действия")
        return
    
    from database import get_payments_stats
    
    # Статистика за день
    day_stats = get_payments_stats("day")
    
    text = f"💰 Статистика платежей за сегодня\n\n"
    text += f"📊 Общая статистика:\n"
    text += f"• Количество платежей: {day_stats['total_payments']}\n"
    text += f"• Общая сумма: {day_stats['total_amount']:.2f}₽\n"
    text += f"• Выдано токенов: {day_stats['total_tokens']}\n\n"
    
    if day_stats['recent_payments']:
        text += f"💳 Последние платежи:\n"
        for payment in day_stats['recent_payments'][:5]:
            username = payment.get('username', 'Без username')
            first_name = payment.get('first_name', 'Пользователь')
            amount = payment['amount']
            tokens = payment['tokens']
            created_at = payment['created_at'].strftime('%H:%M')
            
            text += f"• {first_name} (@{username}): {amount}₽ → {tokens} токенов ({created_at})\n"
    
    keyboard = InlineKeyboardMarkup(
        inline_keyboard=[
            [InlineKeyboardButton(text="🔄 Обновить", callback_data="admin_payments_stats")],
            [InlineKeyboardButton(text="🔙 Админ меню", callback_data="admin_menu")]
        ]
    )
    
    await callback.message.edit_text(text, reply_markup=keyboard)
    await callback.answer()


@dp.callback_query(F.data == "admin_pricing")
async def admin_pricing_callback(callback: CallbackQuery):
    if callback.from_user.id != 367692958:
        await callback.answer("❌ У вас нет прав для выполнения этого действия")
        return
    
    try:
        import httpx
        
        # Получаем данные с API
        async with httpx.AsyncClient(timeout=10) as client:
            response = await client.get("http://localhost:8010/api/v1/pricing")
            if response.status_code == 200:
                data = response.json()
                
                text = f"💲 Текущие цены (без наценки)\n\n"
                text += f"💱 Курс доллара: {data['dollar_rate']}₽\n\n"
                
                # VEO 3.1
                if "veo3" in data["prices"]:
                    text += f"🎬 VEO 3.1:\n"
                    for variant, prices in data["prices"]["veo3"].items():
                        text += f"• {variant.title()}: {prices['rub_base']}₽ (${prices['usd']})\n"
                    text += "\n"
                
                # SORA 2
                if "sora2" in data["prices"]:
                    text += f"🎬 SORA 2:\n"
                    for variant, prices in data["prices"]["sora2"].items():
                        text += f"• {variant.title()}: {prices['rub_base']}₽ (${prices['usd']})\n"
                    text += "\n"
                
                # SORA 2 Pro
                if "sora2_pro" in data["prices"]:
                    text += f"⭐ SORA 2 Pro:\n"
                    for variant, prices in data["prices"]["sora2_pro"].items():
                        text += f"• {variant.replace('_', ' ').title()}: {prices['rub_base']}₽ (${prices['usd']})\n"
                    text += "\n"
                
                # Другие модели
                if "other" in data["prices"]:
                    other = data["prices"]["other"]
                    if "suno" in other:
                        text += f"🎵 Suno: {other['suno']['all_models']['rub_final']}₽\n"
                    if "nano_banana" in other:
                        text += f"🖼️ Nano Banana: {other['nano_banana']['all_modes']['rub_final']}₽\n"
                    if "grok_imagine" in other:
                        text += f"🎬 Grok Imagine (6s): {other['grok_imagine']['image_to_video_6s']['rub_final']}₽\n"
                
            else:
                text = "❌ Ошибка получения цен с API"
            
    except Exception as e:
        text = f"❌ Ошибка: {str(e)}"
    
    keyboard = InlineKeyboardMarkup(
        inline_keyboard=[
            [InlineKeyboardButton(text="🔄 Обновить", callback_data="admin_pricing")],
            [InlineKeyboardButton(text="🔙 Админ меню", callback_data="admin_menu")]
        ]
    )
    
    await callback.message.edit_text(text, reply_markup=keyboard)
    await callback.answer()


@dp.callback_query(F.data == "admin_menu")
async def admin_menu_callback(callback: CallbackQuery):
    if callback.from_user.id != 367692958:
        await callback.answer("❌ У вас нет прав для выполнения этого действия")
        return
    
    text = "🔧 Админское меню\n\nВыберите раздел для просмотра статистики:"
    
    await callback.message.edit_text(text, reply_markup=admin_menu_keyboard())
    await callback.answer()


# === УПРАВЛЕНИЕ ПАПКАМИ С КАНАЛАМИ ===
@dp.callback_query(F.data == "admin_folders")
async def admin_folders_callback(callback: CallbackQuery):
    if callback.from_user.id != 367692958:
        await callback.answer("❌ У вас нет прав для выполнения этого действия")
        return
    
    folders = get_all_folders(include_inactive=True)
    
    if not folders:
        text = "📁 <b>Папки с каналами</b>\n\nПапок пока нет."
        keyboard = InlineKeyboardMarkup(
            inline_keyboard=[
                [InlineKeyboardButton(text="➕ Создать папку", callback_data="admin_folder_create")],
                [InlineKeyboardButton(text="🔙 Админ меню", callback_data="admin_menu")]
            ]
        )
    else:
        lines = ["📁 <b>Папки с каналами</b>\n"]
        keyboard_rows = []
        
        for folder in folders:
            status = "✅" if folder['is_active'] else "❌"
            channels = get_folder_channels(folder['id'])
            channels_count = len(channels)
            lines.append(
                f"{status} <b>{folder['name']}</b>\n"
                f"   💰 Цена: {folder['price']:.2f}₽\n"
                f"   📊 Каналов: {channels_count}\n"
            )
            keyboard_rows.append([
                InlineKeyboardButton(
                    text=f"📂 {folder['name']} ({folder['price']:.2f}₽)",
                    callback_data=f"admin_folder_view_{folder['id']}"
                )
            ])
        
        keyboard_rows.append([InlineKeyboardButton(text="➕ Создать папку", callback_data="admin_folder_create")])
        keyboard_rows.append([InlineKeyboardButton(text="🔙 Админ меню", callback_data="admin_menu")])
        
        text = "\n".join(lines)
        keyboard = InlineKeyboardMarkup(inline_keyboard=keyboard_rows)
    
    await callback.message.edit_text(text, reply_markup=keyboard, parse_mode="HTML")
    await callback.answer()


@dp.callback_query(F.data == "admin_folder_create")
async def admin_folder_create_callback(callback: CallbackQuery, state: FSMContext):
    if callback.from_user.id != 367692958:
        await callback.answer("❌ У вас нет прав для выполнения этого действия")
        return
    
    await callback.message.answer(
        "📝 Введите название папки:",
        reply_markup=cancel_keyboard()
    )
    await state.set_state(AdminFolderStates.input_folder_name)
    await callback.answer()


@dp.message(AdminFolderStates.input_folder_name)
async def admin_folder_name_handler(message: Message, state: FSMContext):
    if not check_admin(message.from_user.id, message.from_user.username or ""):
        return
    
    await state.update_data(folder_name=message.text)
    await message.answer(
        "📝 Введите описание папки (или отправьте '-' для пропуска):",
        reply_markup=cancel_keyboard()
    )
    await state.set_state(AdminFolderStates.input_folder_description)


@dp.message(AdminFolderStates.input_folder_description)
async def admin_folder_description_handler(message: Message, state: FSMContext):
    if not check_admin(message.from_user.id, message.from_user.username or ""):
        return
    
    description = None if message.text == "-" else message.text
    await state.update_data(folder_description=description)
    await message.answer(
        "💰 Введите стоимость подключения к папке (в рублях):",
        reply_markup=cancel_keyboard()
    )
    await state.set_state(AdminFolderStates.input_folder_price)


@dp.message(AdminFolderStates.input_folder_price)
async def admin_folder_price_handler(message: Message, state: FSMContext):
    if not check_admin(message.from_user.id, message.from_user.username or ""):
        return
    
    try:
        price = float(message.text.replace(",", "."))
        if price < 0:
            raise ValueError
        
        data = await state.get_data()
        folder_id = create_folder(
            name=data['folder_name'],
            description=data.get('folder_description'),
            price=price
        )
        
        if folder_id:
            await message.answer(
                f"✅ Папка создана!\n\n"
                f"📂 Название: {data['folder_name']}\n"
                f"💰 Цена: {price:.2f}₽\n\n"
                f"Теперь добавьте каналы в эту папку.",
                reply_markup=InlineKeyboardMarkup(
                    inline_keyboard=[
                        [InlineKeyboardButton(text="➕ Добавить канал", callback_data=f"admin_folder_add_channel_{folder_id}")],
                        [InlineKeyboardButton(text="📁 Все папки", callback_data="admin_folders")]
                    ]
                )
            )
        else:
            await message.answer("❌ Ошибка при создании папки")
    except ValueError:
        await message.answer("❌ Неверный формат цены. Введите число (например: 100 или 99.99)")
        return
    
    await state.clear()


@dp.callback_query(F.data.startswith("admin_folder_view_"))
async def admin_folder_view_callback(callback: CallbackQuery):
    if callback.from_user.id != 367692958:
        await callback.answer("❌ У вас нет прав для выполнения этого действия")
        return
    
    folder_id = int(callback.data.split("_")[-1])
    folder = get_folder_by_id(folder_id)
    
    if not folder:
        await callback.answer("❌ Папка не найдена")
        return
    
    channels = get_folder_channels(folder_id)
    
    lines = [
        f"📂 <b>{folder['name']}</b>\n",
        f"💰 Цена: {folder['price']:.2f}₽\n",
        f"📊 Каналов: {len(channels)}\n"
    ]
    
    if folder['description']:
        lines.append(f"📝 Описание: {folder['description']}\n")
    
    if channels:
        lines.append("\n<b>Каналы в папке:</b>")
        for idx, channel in enumerate(channels, 1):
            link = channel['channel_link'] or f"https://t.me/{channel['channel_username']}"
            lines.append(f"{idx}. {channel['channel_title'] or channel['channel_username']}")
            lines.append(f"   🔗 {link}")
    
    keyboard_rows = [
        [InlineKeyboardButton(text="➕ Добавить канал", callback_data=f"admin_folder_add_channel_{folder_id}")],
        [InlineKeyboardButton(text="✏️ Изменить название", callback_data=f"admin_folder_edit_name_{folder_id}")],
        [InlineKeyboardButton(text="✏️ Изменить описание", callback_data=f"admin_folder_edit_description_{folder_id}")],
        [InlineKeyboardButton(text="✏️ Изменить цену", callback_data=f"admin_folder_edit_price_{folder_id}")],
    ]
    
    if channels:
        for channel in channels:
            keyboard_rows.append([
                InlineKeyboardButton(
                    text=f"🗑 {channel['channel_title'] or channel['channel_username']}",
                    callback_data=f"admin_folder_delete_channel_{channel['id']}"
                )
            ])
    
    if folder['is_active']:
        keyboard_rows.append([InlineKeyboardButton(text="❌ Деактивировать", callback_data=f"admin_folder_deactivate_{folder_id}")])
    else:
        keyboard_rows.append([InlineKeyboardButton(text="✅ Активировать", callback_data=f"admin_folder_activate_{folder_id}")])
    
    keyboard_rows.append([InlineKeyboardButton(text="🗑 Удалить папку", callback_data=f"admin_folder_delete_{folder_id}")])
    keyboard_rows.append([InlineKeyboardButton(text="🔙 Все папки", callback_data="admin_folders")])
    
    await callback.message.edit_text("\n".join(lines), reply_markup=InlineKeyboardMarkup(inline_keyboard=keyboard_rows), parse_mode="HTML")
    await callback.answer()


@dp.callback_query(F.data.startswith("admin_folder_add_channel_"))
async def admin_folder_add_channel_callback(callback: CallbackQuery, state: FSMContext):
    if callback.from_user.id != 367692958:
        await callback.answer("❌ У вас нет прав для выполнения этого действия")
        return
    
    folder_id = int(callback.data.split("_")[-1])
    await state.update_data(folder_id=folder_id)
    
    await callback.message.answer(
        "📝 Введите username канала (например: @channel_name или channel_name):",
        reply_markup=cancel_keyboard()
    )
    await state.set_state(AdminFolderStates.input_channel_username)
    await callback.answer()


@dp.message(AdminFolderStates.input_channel_username)
async def admin_channel_username_handler(message: Message, state: FSMContext):
    if not check_admin(message.from_user.id, message.from_user.username or ""):
        return
    
    username = message.text.lstrip("@")
    await state.update_data(channel_username=username)
    
    await message.answer(
        "🔗 Введите ссылку на канал (или отправьте '-' для пропуска):",
        reply_markup=cancel_keyboard()
    )
    await state.set_state(AdminFolderStates.input_channel_link)


@dp.message(AdminFolderStates.input_channel_link)
async def admin_channel_link_handler(message: Message, state: FSMContext):
    if not check_admin(message.from_user.id, message.from_user.username or ""):
        return
    
    link = None if message.text == "-" else message.text
    await state.update_data(channel_link=link)
    
    await message.answer(
        "📝 Введите название канала (или отправьте '-' для пропуска):",
        reply_markup=cancel_keyboard()
    )
    await state.set_state(AdminFolderStates.input_channel_title)


@dp.message(AdminFolderStates.input_channel_title)
async def admin_channel_title_handler(message: Message, state: FSMContext):
    if not check_admin(message.from_user.id, message.from_user.username or ""):
        return
    
    title = None if message.text == "-" else message.text
    data = await state.get_data()
    
    if add_channel_to_folder(
        folder_id=data['folder_id'],
        channel_username=data['channel_username'],
        channel_link=data.get('channel_link'),
        channel_title=title
    ):
        await message.answer("✅ Канал добавлен в папку!")
        # Обновляем просмотр папки
        folder_id = data['folder_id']
        folder = get_folder_by_id(folder_id)
        if folder:
            channels = get_folder_channels(folder_id)
            lines = [
                f"📂 <b>{folder['name']}</b>\n",
                f"💰 Цена: {folder['price']:.2f}₽\n",
                f"📊 Каналов: {len(channels)}\n"
            ]
            if folder['description']:
                lines.append(f"📝 Описание: {folder['description']}\n")
            if channels:
                lines.append("\n<b>Каналы в папке:</b>")
                for idx, channel in enumerate(channels, 1):
                    link = channel['channel_link'] or f"https://t.me/{channel['channel_username']}"
                    lines.append(f"{idx}. {channel['channel_title'] or channel['channel_username']}")
                    lines.append(f"   🔗 {link}")
            
            keyboard_rows = [
                [InlineKeyboardButton(text="➕ Добавить канал", callback_data=f"admin_folder_add_channel_{folder_id}")],
                [InlineKeyboardButton(text="🔙 Все папки", callback_data="admin_folders")]
            ]
            await message.answer("\n".join(lines), reply_markup=InlineKeyboardMarkup(inline_keyboard=keyboard_rows), parse_mode="HTML")
    else:
        await message.answer("❌ Ошибка при добавлении канала")
    
    await state.clear()


@dp.callback_query(F.data.startswith("admin_folder_delete_channel_"))
async def admin_folder_delete_channel_callback(callback: CallbackQuery):
    if callback.from_user.id != 367692958:
        await callback.answer("❌ У вас нет прав для выполнения этого действия")
        return
    
    channel_id = int(callback.data.split("_")[-1])
    # Получаем folder_id из канала
    from database import get_connection
    conn = get_connection()
    cursor = conn.cursor()
    cursor.execute("SELECT folder_id FROM folder_channels WHERE id = %s", (channel_id,))
    result = cursor.fetchone()
    conn.close()
    
    if result and remove_channel_from_folder(channel_id):
        await callback.answer("✅ Канал удален")
        # Обновляем просмотр папки
        folder_id = result['folder_id']
        folder = get_folder_by_id(folder_id)
        if folder:
            channels = get_folder_channels(folder_id)
            lines = [
                f"📂 <b>{folder['name']}</b>\n",
                f"💰 Цена: {folder['price']:.2f}₽\n",
                f"📊 Каналов: {len(channels)}\n"
            ]
            if folder['description']:
                lines.append(f"📝 Описание: {folder['description']}\n")
            if channels:
                lines.append("\n<b>Каналы в папке:</b>")
                for idx, channel in enumerate(channels, 1):
                    link = channel['channel_link'] or f"https://t.me/{channel['channel_username']}"
                    lines.append(f"{idx}. {channel['channel_title'] or channel['channel_username']}")
                    lines.append(f"   🔗 {link}")
            
            keyboard_rows = [
                [InlineKeyboardButton(text="➕ Добавить канал", callback_data=f"admin_folder_add_channel_{folder_id}")],
                [InlineKeyboardButton(text="🔙 Все папки", callback_data="admin_folders")]
            ]
            await callback.message.edit_text("\n".join(lines), reply_markup=InlineKeyboardMarkup(inline_keyboard=keyboard_rows), parse_mode="HTML")
    else:
        await callback.answer("❌ Ошибка при удалении канала")


@dp.callback_query(F.data.startswith("admin_folder_edit_price_"))
async def admin_folder_edit_price_callback(callback: CallbackQuery, state: FSMContext):
    if callback.from_user.id != 367692958:
        await callback.answer("❌ У вас нет прав для выполнения этого действия")
        return
    
    folder_id = int(callback.data.split("_")[-1])
    await state.update_data(folder_id=folder_id)
    
    await callback.message.answer(
        "💰 Введите новую цену (в рублях):",
        reply_markup=cancel_keyboard()
    )
    await state.set_state(AdminFolderStates.edit_folder_price)
    await callback.answer()


@dp.message(AdminFolderStates.edit_folder_price)
async def admin_folder_edit_price_handler(message: Message, state: FSMContext):
    if not check_admin(message.from_user.id, message.from_user.username or ""):
        return
    
    try:
        price = float(message.text.replace(",", "."))
        if price < 0:
            raise ValueError
        
        data = await state.get_data()
        folder_id = data['folder_id']
        
        if update_folder(folder_id, price=price):
            await message.answer("✅ Цена обновлена!")
            # Возвращаемся к просмотру папки
            folder = get_folder_by_id(folder_id)
            if folder:
                channels = get_folder_channels(folder_id)
                lines = [
                    f"📂 <b>{folder['name']}</b>\n",
                    f"💰 Цена: {folder['price']:.2f}₽\n",
                    f"📊 Каналов: {len(channels)}\n"
                ]
                if folder['description']:
                    lines.append(f"📝 Описание: {folder['description']}\n")
                if channels:
                    lines.append("\n<b>Каналы в папке:</b>")
                    for idx, channel in enumerate(channels, 1):
                        link = channel['channel_link'] or f"https://t.me/{channel['channel_username']}"
                        lines.append(f"{idx}. {channel['channel_title'] or channel['channel_username']}")
                        lines.append(f"   🔗 {link}")
                
                keyboard_rows = [
                    [InlineKeyboardButton(text="➕ Добавить канал", callback_data=f"admin_folder_add_channel_{folder_id}")],
                    [InlineKeyboardButton(text="✏️ Изменить название", callback_data=f"admin_folder_edit_name_{folder_id}")],
                    [InlineKeyboardButton(text="✏️ Изменить описание", callback_data=f"admin_folder_edit_description_{folder_id}")],
                    [InlineKeyboardButton(text="✏️ Изменить цену", callback_data=f"admin_folder_edit_price_{folder_id}")],
                ]
                
                if channels:
                    for channel in channels:
                        keyboard_rows.append([
                            InlineKeyboardButton(
                                text=f"🗑 {channel['channel_title'] or channel['channel_username']}",
                                callback_data=f"admin_folder_delete_channel_{channel['id']}"
                            )
                        ])
                
                if folder['is_active']:
                    keyboard_rows.append([InlineKeyboardButton(text="❌ Деактивировать", callback_data=f"admin_folder_deactivate_{folder_id}")])
                else:
                    keyboard_rows.append([InlineKeyboardButton(text="✅ Активировать", callback_data=f"admin_folder_activate_{folder_id}")])
                
                keyboard_rows.append([InlineKeyboardButton(text="🗑 Удалить папку", callback_data=f"admin_folder_delete_{folder_id}")])
                keyboard_rows.append([InlineKeyboardButton(text="🔙 Все папки", callback_data="admin_folders")])
                
                await message.answer("\n".join(lines), reply_markup=InlineKeyboardMarkup(inline_keyboard=keyboard_rows), parse_mode="HTML")
        else:
            await message.answer("❌ Ошибка при обновлении цены")
    except ValueError:
        await message.answer("❌ Неверный формат цены. Введите число (например: 100 или 99.99)")
        return
    
    await state.clear()


@dp.callback_query(F.data.startswith("admin_folder_edit_name_"))
async def admin_folder_edit_name_callback(callback: CallbackQuery, state: FSMContext):
    if callback.from_user.id != 367692958:
        await callback.answer("❌ У вас нет прав для выполнения этого действия")
        return
    
    folder_id = int(callback.data.split("_")[-1])
    await state.update_data(folder_id=folder_id)
    
    await callback.message.answer(
        "📝 Введите новое название папки:",
        reply_markup=cancel_keyboard()
    )
    await state.set_state(AdminFolderStates.edit_folder_name)
    await callback.answer()


@dp.message(AdminFolderStates.edit_folder_name)
async def admin_folder_edit_name_handler(message: Message, state: FSMContext):
    if not check_admin(message.from_user.id, message.from_user.username or ""):
        return
    
    data = await state.get_data()
    folder_id = data['folder_id']
    
    if update_folder(folder_id, name=message.text):
        await message.answer("✅ Название обновлено!")
        await admin_folder_view_callback_helper(message, folder_id)
    else:
        await message.answer("❌ Ошибка при обновлении названия")
    
    await state.clear()


@dp.callback_query(F.data.startswith("admin_folder_edit_description_"))
async def admin_folder_edit_description_callback(callback: CallbackQuery, state: FSMContext):
    if callback.from_user.id != 367692958:
        await callback.answer("❌ У вас нет прав для выполнения этого действия")
        return
    
    folder_id = int(callback.data.split("_")[-1])
    await state.update_data(folder_id=folder_id)
    
    await callback.message.answer(
        "📝 Введите новое описание папки (или отправьте '-' для удаления):",
        reply_markup=cancel_keyboard()
    )
    await state.set_state(AdminFolderStates.edit_folder_description)
    await callback.answer()


@dp.message(AdminFolderStates.edit_folder_description)
async def admin_folder_edit_description_handler(message: Message, state: FSMContext):
    if not check_admin(message.from_user.id, message.from_user.username or ""):
        return
    
    description = None if message.text == "-" else message.text
    data = await state.get_data()
    folder_id = data['folder_id']
    
    if update_folder(folder_id, description=description):
        await message.answer("✅ Описание обновлено!")
        await admin_folder_view_callback_helper(message, folder_id)
    else:
        await message.answer("❌ Ошибка при обновлении описания")
    
    await state.clear()


async def admin_folder_view_callback_helper(message_or_callback, folder_id: int):
    """Вспомогательная функция для отображения папки"""
    folder = get_folder_by_id(folder_id)
    if not folder:
        return
    
    channels = get_folder_channels(folder_id)
    lines = [
        f"📂 <b>{folder['name']}</b>\n",
        f"💰 Цена: {folder['price']:.2f}₽\n",
        f"📊 Каналов: {len(channels)}\n"
    ]
    
    if folder['description']:
        lines.append(f"📝 Описание: {folder['description']}\n")
    
    if channels:
        lines.append("\n<b>Каналы в папке:</b>")
        for idx, channel in enumerate(channels, 1):
            link = channel['channel_link'] or f"https://t.me/{channel['channel_username']}"
            lines.append(f"{idx}. {channel['channel_title'] or channel['channel_username']}")
            lines.append(f"   🔗 {link}")
    
    keyboard_rows = [
        [InlineKeyboardButton(text="➕ Добавить канал", callback_data=f"admin_folder_add_channel_{folder_id}")],
        [InlineKeyboardButton(text="✏️ Изменить название", callback_data=f"admin_folder_edit_name_{folder_id}")],
        [InlineKeyboardButton(text="✏️ Изменить описание", callback_data=f"admin_folder_edit_description_{folder_id}")],
        [InlineKeyboardButton(text="✏️ Изменить цену", callback_data=f"admin_folder_edit_price_{folder_id}")],
    ]
    
    if channels:
        for channel in channels:
            keyboard_rows.append([
                InlineKeyboardButton(
                    text=f"🗑 {channel['channel_title'] or channel['channel_username']}",
                    callback_data=f"admin_folder_delete_channel_{channel['id']}"
                )
            ])
    
    if folder['is_active']:
        keyboard_rows.append([InlineKeyboardButton(text="❌ Деактивировать", callback_data=f"admin_folder_deactivate_{folder_id}")])
    else:
        keyboard_rows.append([InlineKeyboardButton(text="✅ Активировать", callback_data=f"admin_folder_activate_{folder_id}")])
    
    keyboard_rows.append([InlineKeyboardButton(text="🗑 Удалить папку", callback_data=f"admin_folder_delete_{folder_id}")])
    keyboard_rows.append([InlineKeyboardButton(text="🔙 Все папки", callback_data="admin_folders")])
    
    text = "\n".join(lines)
    keyboard = InlineKeyboardMarkup(inline_keyboard=keyboard_rows)
    
    if isinstance(message_or_callback, Message):
        await message_or_callback.answer(text, reply_markup=keyboard, parse_mode="HTML")
    else:
        await message_or_callback.message.edit_text(text, reply_markup=keyboard, parse_mode="HTML")


@dp.callback_query(F.data.startswith("admin_folder_delete_"))
async def admin_folder_delete_callback(callback: CallbackQuery):
    if callback.from_user.id != 367692958:
        await callback.answer("❌ У вас нет прав для выполнения этого действия")
        return
    
    folder_id = int(callback.data.split("_")[-1])
    
    if delete_folder(folder_id):
        await callback.answer("✅ Папка удалена")
        await admin_folders_callback(callback)
    else:
        await callback.answer("❌ Ошибка при удалении папки")


@dp.callback_query(F.data.startswith("admin_folder_activate_"))
async def admin_folder_activate_callback(callback: CallbackQuery):
    if callback.from_user.id != 367692958:
        await callback.answer("❌ У вас нет прав для выполнения этого действия")
        return
    
    folder_id = int(callback.data.split("_")[-1])
    
    if update_folder(folder_id, is_active=True):
        await callback.answer("✅ Папка активирована")
        folder_id = int(callback.data.split("_")[-1])
        await admin_folder_view_callback(callback)
    else:
        await callback.answer("❌ Ошибка при активации папки")


@dp.callback_query(F.data.startswith("admin_folder_deactivate_"))
async def admin_folder_deactivate_callback(callback: CallbackQuery):
    if callback.from_user.id != 367692958:
        await callback.answer("❌ У вас нет прав для выполнения этого действия")
        return
    
    folder_id = int(callback.data.split("_")[-1])
    
    if update_folder(folder_id, is_active=False):
        await callback.answer("✅ Папка деактивирована")
        folder_id = int(callback.data.split("_")[-1])
        await admin_folder_view_callback(callback)
    else:
        await callback.answer("❌ Ошибка при деактивации папки")


# === РАССЫЛКА СООБЩЕНИЙ ===
@dp.callback_query(F.data == "admin_broadcast")
async def admin_broadcast_callback(callback: CallbackQuery, state: FSMContext):
    if callback.from_user.id != 367692958:
        await callback.answer("❌ У вас нет прав для выполнения этого действия")
        return
    
    type_buttons = [
        {"text": "📢 Всем пользователям", "callback_data": "broadcast_all"},
        {"text": "👤 Конкретным пользователям", "callback_data": "broadcast_specific"},
    ]
    keyboard = InlineKeyboardMarkup(
        inline_keyboard=[[InlineKeyboardButton(**btn)] for btn in type_buttons]
    )
    
    await callback.message.edit_text(
        "📢 Рассылка сообщений\n\nВыберите тип рассылки:",
        reply_markup=keyboard
    )
    await state.set_state(BroadcastStates.choose_type)
    await callback.answer()


@dp.callback_query(F.data == "broadcast_all")
async def broadcast_all_callback(callback: CallbackQuery, state: FSMContext):
    if callback.from_user.id != 367692958:
        await callback.answer("❌ У вас нет прав")
        return
    
    await callback.answer()
    await state.update_data(broadcast_type="all")
    await state.set_state(BroadcastStates.input_message)
    
    await callback.message.edit_text(
        "📢 Напишите сообщение для отправки всем пользователям:\n\n"
        "💡 Вы можете отправить текст или фото с подписью",
        reply_markup=cancel_keyboard()
    )


@dp.callback_query(F.data == "broadcast_specific")
async def broadcast_specific_callback(callback: CallbackQuery, state: FSMContext):
    if callback.from_user.id != 367692958:
        await callback.answer("❌ У вас нет прав")
        return
    
    await callback.answer()
    await state.update_data(broadcast_type="specific")
    await state.set_state(BroadcastStates.input_chat_ids)
    
    await callback.message.edit_text(
        "👤 Введите chat_id пользователей через запятую\n\nПример: 123456789, 987654321",
        reply_markup=cancel_keyboard()
    )


@dp.message(BroadcastStates.input_chat_ids)
async def input_chat_ids_handler(message: Message, state: FSMContext):
    # Проверка прав админа
    if not check_admin(message.from_user.id, message.from_user.username or ""):
        await message.answer("❌ У вас нет прав для выполнения этого действия")
        await state.clear()
        return
    
    logging.info(f"[BROADCAST] Получен chat_id: {message.text}")
    
    chat_ids_str = message.text.strip()
    
    try:
        chat_ids = [int(chat_id.strip()) for chat_id in chat_ids_str.split(",")]
        await state.update_data(chat_ids=chat_ids)
        await state.set_state(BroadcastStates.input_message)
        
        await message.answer(
            f"✅ Получено {len(chat_ids)} chat_id\n\n"
            f"Напишите сообщение для отправки:\n\n"
            f"💡 Вы можете отправить текст или фото с подписью",
            reply_markup=cancel_keyboard()
        )
    except ValueError:
        await message.answer(
            "❌ Ошибка: некорректный формат chat_id\n\nВведите chat_id через запятую (только числа)",
            reply_markup=cancel_keyboard()
        )


@dp.message(BroadcastStates.input_message)
async def input_broadcast_message_handler(message: Message, state: FSMContext):
    # Проверка прав админа
    if not check_admin(message.from_user.id, message.from_user.username or ""):
        await message.answer("❌ У вас нет прав для выполнения этого действия")
        await state.clear()
        return
    
    user_data = await state.get_data()
    broadcast_type = user_data.get("broadcast_type")
    
    # Получаем текст и фото
    text_message = message.text or message.caption
    photo_file_id = None
    
    if message.photo:
        # Берем фото самого большого размера (последний элемент в списке)
        photo_file_id = message.photo[-1].file_id
    elif message.document and message.document.mime_type and message.document.mime_type.startswith("image/"):
        photo_file_id = message.document.file_id
    
    # Проверяем, что есть хотя бы фото или текст
    if not photo_file_id and not text_message:
        await message.answer(
            "❌ Ошибка: отправьте текст или фото (с подписью или без)",
            reply_markup=cancel_keyboard()
        )
        return
    
    logging.info(f"[BROADCAST] Запущена рассылка типа {broadcast_type}, фото: {photo_file_id is not None}, текст: {bool(text_message)}")
    
    await message.answer("⏳ Отправка сообщений...")
    
    try:
        if broadcast_type == "all":
            from database import get_all_users
            users = get_all_users()
            
            success_count = 0
            fail_count = 0
            
            for user in users:
                try:
                    if photo_file_id:
                        # Отправляем фото (с подписью, если есть текст)
                        await bot.send_photo(
                            chat_id=user['telegram_id'],
                            photo=photo_file_id,
                            caption=text_message if text_message else None
                        )
                    else:
                        # Отправляем только текст
                        await bot.send_message(chat_id=user['telegram_id'], text=text_message)
                    success_count += 1
                    if user.get("is_blocked"):
                        set_user_blocked(user['telegram_id'], False)
                except TelegramForbiddenError as e:
                    logging.warning(f"Пользователь {user['telegram_id']} заблокировал бота: {e}")
                    set_user_blocked(user['telegram_id'], True)
                    fail_count += 1
                except Exception as e:
                    logging.error(f"Ошибка отправки пользователю {user['telegram_id']}: {e}")
                    fail_count += 1
                await asyncio.sleep(2)
            
            await message.answer(
                f"✅ Рассылка завершена!\n\n"
                f"📊 Статистика:\n"
                f"▫️ Отправлено: {success_count}\n"
                f"▫️ Ошибок: {fail_count}\n"
                f"▫️ Всего: {len(users)}"
            )
        else:
            chat_ids = user_data.get("chat_ids", [])
            
            success_count = 0
            fail_count = 0
            
            for chat_id in chat_ids:
                try:
                    if photo_file_id:
                        # Отправляем фото (с подписью, если есть текст)
                        await bot.send_photo(
                            chat_id=chat_id,
                            photo=photo_file_id,
                            caption=text_message if text_message else None
                        )
                    else:
                        # Отправляем только текст
                        await bot.send_message(chat_id=chat_id, text=text_message)
                    success_count += 1
                    set_user_blocked(chat_id, False)
                except TelegramForbiddenError as e:
                    logging.warning(f"Пользователь {chat_id} заблокировал бота: {e}")
                    set_user_blocked(chat_id, True)
                    fail_count += 1
                except Exception as e:
                    logging.error(f"Ошибка отправки пользователю {chat_id}: {e}")
                    fail_count += 1
                await asyncio.sleep(2)
            
            await message.answer(
                f"✅ Рассылка завершена!\n\n"
                f"📊 Статистика:\n"
                f"▫️ Отправлено: {success_count}\n"
                f"▫️ Ошибок: {fail_count}\n"
                f"▫️ Всего: {len(chat_ids)}"
            )
    
    except Exception as e:
        logging.error(f"Ошибка при рассылке: {e}")
        await message.answer(f"❌ Ошибка при рассылке: {e}")
    
    await state.clear()


@dp.callback_query(F.data == "menu_main")
async def menu_main_callback(callback: CallbackQuery):
    user_tag = get_user_tag(callback.from_user.id)
    
    # Если это админ, добавляем админскую кнопку
    if callback.from_user.id == 367692958:
        text = "🤖 Главное меню\n\nВыберите действие:"
        admin_keyboard = InlineKeyboardMarkup(
            inline_keyboard=[
                [InlineKeyboardButton(text="👤 Профиль", callback_data="menu_profile")],
                [InlineKeyboardButton(text="🚀 Начать генерацию", callback_data="menu_generate")],
                [InlineKeyboardButton(text="💰 Пополнить баланс", callback_data="menu_topup")],
                [InlineKeyboardButton(text="💼 Партнёрская программа", callback_data="menu_partner")],
                [InlineKeyboardButton(text="ℹ️ Тарифы / справка", callback_data="menu_help")],
                [InlineKeyboardButton(text="🔧 Админ меню", callback_data="admin_menu")],
            ]
        )
        await callback.message.edit_text(text, reply_markup=admin_keyboard)
    else:
        if user_tag == "tts":
            text = (
                "🤖 Главное меню\n\n"
                "Перед началом генерации прослушайте примеры озвучки для выбора нейро голоса:\n"
                "<a href=\"https://genius-bot.ru/nejroset-golos-primery-golosov-dlya-ozvuchki/\">Прослушать голоса</a>\n\n"
                "Выберите действие:"
            )
        else:
            text = "🤖 Главное меню\n\nВыберите действие:"
        await callback.message.edit_text(text, reply_markup=main_menu_keyboard(user_tag=user_tag), parse_mode="HTML" if user_tag == "tts" else None)
    
    await callback.answer()


# === УВЕДОМЛЕНИЯ АДМИНУ ===

async def send_admin_payment_notification(user_id: int, username: str, first_name: str, amount: float, tokens: int):
    """Отправка уведомления админу о новом платеже"""
    admin_id = 367692958
    
    text = (
        f"💰 Новый платеж!\n\n"
        f"👤 Пользователь: {first_name} (@{username})\n"
        f"🆔 ID: {user_id}\n"
        f"💵 Сумма: {amount}₽\n"
        f"🎫 Токены: {tokens}\n"
        f"⏰ Время: {datetime.now().strftime('%d.%m.%Y %H:%M')}"
    )
    
    try:
        await bot.send_message(admin_id, text)
    except Exception as e:
        print(f"Ошибка отправки уведомления админу: {e}")
    
# --- Main ---
async def main():
    create_tables()
    create_partner_tables()  # Создаем таблицы для партнерской программы
    
    dp.message.register(start_handler, Command("start"))
    dp.message.register(handle_successful_payment, F.successful_payment)  # Обработчик успешных платежей
    
    # РАССЫЛКА - регистрируем ПЕРВЫМИ чтобы не перехватывалось другими обработчиками
    dp.callback_query.register(admin_broadcast_callback, F.data == "admin_broadcast")
    dp.callback_query.register(broadcast_all_callback, F.data == "broadcast_all")
    dp.callback_query.register(broadcast_specific_callback, F.data == "broadcast_specific")
    dp.message.register(input_chat_ids_handler, BroadcastStates.input_chat_ids)
    dp.message.register(input_broadcast_message_handler, BroadcastStates.input_message)

    # Topup & Balance
    dp.callback_query.register(balance_callback, F.data=="balance")
    dp.callback_query.register(topup_callback, F.data.startswith("topup_"))

    # Generation
    dp.callback_query.register(start_generation, F.data=="generate")
    dp.callback_query.register(select_model, F.data.startswith("model_"))
    dp.callback_query.register(select_veo_variant, F.data.startswith("veo_variant_"))
    dp.callback_query.register(select_veo_task, F.data.startswith("veo_task_"))
    dp.callback_query.register(set_veo_aspect_ratio, F.data.startswith("veo_aspect_"))
    dp.callback_query.register(set_veo_style, F.data.startswith("veo_style_"))
    dp.callback_query.register(set_veo_motion, F.data.startswith("veo_motion_"))

    dp.message.register(get_final_prompt, GenerationStates.GET_FINAL_PROMPT)
    dp.message.register(get_veo_image, GenerationStates.GET_VEO_IMAGE)
    
    # SORA 2 handlers
    dp.callback_query.register(choose_sora2_pro_model_callback, Sora2States.choose_pro_model)
    dp.callback_query.register(choose_sora2_aspect_ratio_callback, Sora2States.choose_aspect_ratio)
    dp.callback_query.register(choose_sora2_duration_callback, Sora2States.choose_duration)
    dp.callback_query.register(choose_sora2_quality_callback, Sora2States.choose_quality)
    dp.message.register(get_sora2_image, Sora2States.get_image)
    dp.message.register(input_sora2_prompt_handler, Sora2States.input_prompt)
    
    # Seedance handlers
    dp.callback_query.register(seedance_choose_settings_callback, SeedanceStates.choose_settings)
    dp.message.register(seedance_get_image, SeedanceStates.get_image)
    dp.message.register(seedance_input_prompt, SeedanceStates.input_prompt)
    
    # Seedream Edit handlers
    dp.message.register(seedream_edit_get_image, SeedreamEditStates.get_image)
    dp.callback_query.register(seedream_edit_choose_aspect_ratio, SeedreamEditStates.choose_aspect_ratio)
    dp.callback_query.register(seedream_edit_choose_quality, SeedreamEditStates.choose_quality)
    dp.message.register(seedream_edit_input_prompt, SeedreamEditStates.input_prompt)
    
    # Runway handlers
    dp.callback_query.register(runway_choose_mode_callback, RunwayStates.choose_mode)
    dp.callback_query.register(runway_choose_aspect_ratio_callback, RunwayStates.choose_aspect_ratio)
    dp.callback_query.register(runway_choose_duration_callback, RunwayStates.choose_duration)
    dp.callback_query.register(runway_choose_quality_callback, RunwayStates.choose_quality)
    dp.message.register(runway_get_image, RunwayStates.get_image)
    dp.message.register(runway_input_prompt, RunwayStates.input_prompt)
    
    # Runway Extend handlers
    dp.message.register(runway_extend_input_task_id, RunwayExtendStates.input_task_id)
    dp.callback_query.register(runway_extend_choose_quality_callback, RunwayExtendStates.choose_quality)
    dp.message.register(runway_extend_input_prompt, RunwayExtendStates.input_prompt)
    
    # Aleph handlers
    dp.message.register(aleph_get_video, AlephStates.get_video)
    dp.message.register(aleph_input_prompt, AlephStates.input_prompt)
    
    # Content type handlers - регистрируем ПОСЛЕ универсального content_tts_handler
    # Универсальный обработчик content_tts уже зарегистрирован выше в TTS handlers
    dp.callback_query.register(choose_content_type_callback, VideoGenStates.choose_content_type)
    dp.callback_query.register(choose_ai_callback, VideoGenStates.choose_ai)
    # choose_suno_model_callback уже зарегистрирован через декоратор
    dp.callback_query.register(choose_suno_mode_callback, SunoStates.choose_mode)
    dp.callback_query.register(choose_suno_instrumental_callback, SunoStates.choose_instrumental)
    dp.message.register(input_suno_style_handler, SunoStates.input_style)
    dp.message.register(input_suno_title_handler, SunoStates.input_title)
    dp.message.register(input_suno_prompt_handler, SunoStates.input_prompt)
    
    # Nano Banana handlers
    dp.message.register(get_nano_image, NanoBananaStates.get_image)
    dp.message.register(input_nano_prompt_handler, NanoBananaStates.input_prompt)
    dp.message.register(get_pro_images_handler, NanoBananaStates.get_pro_images)
    
    # TTS handlers
    # Регистрируем универсальный обработчик content_tts ПЕРВЫМ, без фильтра состояния
    # Это важно, чтобы он срабатывал из главного меню
    dp.callback_query.register(content_tts_handler, F.data == "content_tts")
    dp.callback_query.register(tts_show_voices_callback, TTSStates.choose_voice_info, F.data == "tts_show_voices")
    dp.callback_query.register(choose_tts_voice_callback, TTSStates.choose_voice, F.data.startswith("tts_voice_"))
    dp.message.register(input_tts_text_handler, TTSStates.input_text)
    
    # Partner program handlers
    dp.callback_query.register(partner_withdrawal_callback, F.data == "partner_withdrawal")
    dp.callback_query.register(partner_history_callback, F.data == "partner_history")
    dp.callback_query.register(partner_help_callback, F.data == "partner_help")
    dp.message.register(partner_withdrawal_amount_handler, PartnerStates.withdrawal_amount)
    dp.message.register(partner_withdrawal_payment_handler, PartnerStates.withdrawal_payment)
    
    # Admin handlers
    dp.callback_query.register(admin_approve_withdrawal, F.data.startswith("admin_approve_"))
    dp.callback_query.register(admin_reject_withdrawal, F.data.startswith("admin_reject_"))
    dp.callback_query.register(admin_users_stats_callback, F.data == "admin_users_stats")
    dp.callback_query.register(admin_generations_stats_callback, F.data == "admin_generations_stats")
    dp.callback_query.register(admin_payments_stats_callback, F.data == "admin_payments_stats")
    dp.callback_query.register(admin_pricing_callback, F.data == "admin_pricing")
    dp.callback_query.register(admin_menu_callback, F.data == "admin_menu")
    
    # Admin folder handlers
    dp.callback_query.register(admin_folders_callback, F.data == "admin_folders")
    dp.callback_query.register(admin_folder_create_callback, F.data == "admin_folder_create")
    dp.callback_query.register(admin_folder_view_callback, F.data.startswith("admin_folder_view_"))
    dp.callback_query.register(admin_folder_add_channel_callback, F.data.startswith("admin_folder_add_channel_"))
    dp.callback_query.register(admin_folder_delete_channel_callback, F.data.startswith("admin_folder_delete_channel_"))
    dp.callback_query.register(admin_folder_edit_price_callback, F.data.startswith("admin_folder_edit_price_"))
    dp.callback_query.register(admin_folder_edit_name_callback, F.data.startswith("admin_folder_edit_name_"))
    dp.callback_query.register(admin_folder_edit_description_callback, F.data.startswith("admin_folder_edit_description_"))
    dp.callback_query.register(admin_folder_delete_callback, F.data.startswith("admin_folder_delete_"))
    dp.callback_query.register(admin_folder_activate_callback, F.data.startswith("admin_folder_activate_"))
    dp.callback_query.register(admin_folder_deactivate_callback, F.data.startswith("admin_folder_deactivate_"))
    dp.message.register(admin_folder_name_handler, AdminFolderStates.input_folder_name)
    dp.message.register(admin_folder_description_handler, AdminFolderStates.input_folder_description)
    dp.message.register(admin_folder_price_handler, AdminFolderStates.input_folder_price)
    dp.message.register(admin_folder_edit_price_handler, AdminFolderStates.edit_folder_price)
    dp.message.register(admin_folder_edit_name_handler, AdminFolderStates.edit_folder_name)
    dp.message.register(admin_folder_edit_description_handler, AdminFolderStates.edit_folder_description)
    dp.message.register(admin_channel_username_handler, AdminFolderStates.input_channel_username)
    dp.message.register(admin_channel_link_handler, AdminFolderStates.input_channel_link)
    dp.message.register(admin_channel_title_handler, AdminFolderStates.input_channel_title)
    
    # User folder handlers
    dp.callback_query.register(user_folders_callback, F.data == "user_folders")
    dp.callback_query.register(user_folder_view_callback, F.data.startswith("user_folder_view_"))
    dp.callback_query.register(user_folder_subscribe_callback, F.data.startswith("user_folder_subscribe_"))
    dp.callback_query.register(user_folder_channels_callback, F.data.startswith("user_folder_channels_"))
    dp.callback_query.register(cancel_callback, F.data == "cancel")
    dp.callback_query.register(menu_main_callback, F.data == "menu_main")

    import asyncio
    from kie_api import start_task_monitor
    
    # Автопостинг: канал-источник -> KIE -> целевой канал
    setup_autopost(dp, bot)
    setup_autopost_routes(app, bot)
    setup_autopost_test(dp, bot)
    setup_autopost_fix(dp, bot)
    setup_admin_links(dp, bot)

    # Каждая задача изолирована: если упадёт фоновая, бот продолжит отвечать,
    # а ошибка попадёт в лог. Раньше падение любой из них останавливало всё.
    async def guarded(coro, name: str):
        try:
            await coro
        except asyncio.CancelledError:
            raise
        except Exception as e:
            logging.error("Задача %s остановлена с ошибкой: %s", name, e, exc_info=True)

    await asyncio.gather(
        guarded(start_bot(), "бот"),
        guarded(start_api(), "API"),
        guarded(start_task_monitor(), "мониторинг задач"),
        guarded(autopost_worker(bot), "автопостинг"),
        guarded(telethon_worker(), "чтение каналов"),
        guarded(news_worker(bot), "новостной канал"),
    )

    
# --- Добавить обработчик заглушку для speech_to_text_start ---
@dp.callback_query(F.data == "speech_to_text_start")
async def speech_to_text_start_callback(callback: CallbackQuery, state: FSMContext):
    await callback.message.answer(
        "Пожалуйста, отправьте public-ссылку на аудиофайл или загрузите файл для транскрибации (до 200 МБ). Форматы: MPEG, WAV, AAC, MP4, OGG и др."
    )
    await state.set_state(SpeechToTextStates.SEND_AUDIO)
    await callback.answer()

# --- (обработчик файла/ссылки) ---
@dp.message(SpeechToTextStates.SEND_AUDIO)
async def handle_speech_to_text_audio(message: Message, state: FSMContext):
    import logging
    from database import log_user_request
    import httpx
    user_id = message.from_user.id
    username = message.from_user.username or "user"
    first_name = message.from_user.first_name or "-"
    logging.info(f"[S2T] handler START: user_id={user_id}, username={username}, message_type={message.content_type}")
    audio_url = None
    duration_sec = 60
    external_host = "https://file.io"
    CALLBACK_URL = f"{CALLBACK_BASE_URL}/speech-to-text-callback" # обязательно заменить на свой!
    try:
        if message.audio or (message.document and (message.document.mime_type or '').startswith('audio')):
            file_id = message.audio.file_id if message.audio else message.document.file_id
            logging.info(f"[S2T] Получен file_id: {file_id}")
            await message.answer("📥 Файл принят, начинается скачивание из Telegram...")
            try:
                file_info = await bot.get_file(file_id)
                logging.info(f"[S2T] file_info: {file_info}")
            except Exception as e:
                logging.error(f"[S2T] Ошибка получения file_info: {e}")
                await message.answer(f"❌ Ошибка получения file_info: {e}")
                return
            tg_file_url = f"https://api.telegram.org/file/bot{TELEGRAM_BOT_TOKEN}/{file_info.file_path}"
            logging.info(f"[S2T] Получен tg_file_url: {tg_file_url}")
            await message.answer(f"🔗 Telegram file URL получен. Скачиваем...")
            async with httpx.AsyncClient() as client:
                try:
                    resp_get = await client.get(tg_file_url)
                    logging.info(f"[S2T] resp_get.status_code: {resp_get.status_code}")
                    if resp_get.status_code != 200:
                        logging.error(f"[S2T] Не удалось скачать файл из Telegram (код {resp_get.status_code})")
                        await message.answer(f"❌ Не удалось скачать файл из Telegram (код {resp_get.status_code}).")
                        return
                    file_content = resp_get.content
                    logging.info(f"[S2T] Файл скачан (длина {len(file_content)} байт)")
                except Exception as e:
                    logging.error(f"[S2T] Ошибка скачивания файла: {e}")
                    await message.answer(f"❌ Ошибка скачивания файла: {e}")
                    return
                files = {'file': (file_info.file_path.split('/')[-1], file_content)}
                await message.answer(f"☁ Загружаем файл на file.io...")
                try:
                    resp = await client.post(external_host, files=files)
                    logging.info(f"[S2T] upload file.io status: {resp.status_code}")
                except Exception as e:
                    logging.error(f"[S2T] Ошибка при подключении к file.io: {e}")
                    await message.answer(f"❌ Ошибка при подключении к file.io: {e}")
                    return
                try:
                    json_resp = resp.json()
                    logging.info(f"[S2T] upload file.io ответ: {json_resp}")
                except Exception as e:
                    logging.error(f"[S2T] Ошибка парсинга ответа file.io: {e}, raw={resp.text}")
                    await message.answer(f"❌ Ошибка парсинга ответа file.io: {e}\nRAW: {resp.text}")
                    return
                if resp.status_code == 200 and json_resp.get('success') and json_resp.get('link'):
                    audio_url = json_resp['link']
                    logging.info(f"[S2T] Файл выложен! audio_url: {audio_url}")
                    await message.answer(f"✅ Файл успешно выложен! Ссылка: {audio_url}\n\nОтправляем в ElevenLabs на распознавание...")
                else:
                    logging.error(f"[S2T] Не удалось загрузить файл на file.io. Код: {resp.status_code}, ответ: {json_resp}")
                    await message.answer(f"❌ Не удалось загрузить файл на file.io. Код: {resp.status_code}, ответ: {json_resp}")
                    return
        elif message.text and message.text.startswith("http"):
            audio_url = message.text.strip()
            logging.info(f"[S2T] Получена внешняя ссылка: {audio_url}")
            await message.answer(f"🟢 Получена ссылка на аудиофайл: {audio_url}. Отправляем в ElevenLabs...")
        else:
            logging.error(f"[S2T] Неподдерживаемый тип входа: {message}")
            await message.answer(
                "❌ Принимаются только аудиофайлы или публичные ссылки.\n"
                "Пожалуйста, отправьте ссылку на аудиофайл (mp3, wav, ogg) или загрузите файл до 200 МБ.\n"
                "\nПример: https://example.com/yourfile.mp3 или загрузите mp3/wav/ogg прямо сюда."
            )
            return
        # Проверка
        if not audio_url:
            logging.error(f"[S2T] Не удалось получить ссылку после обработки файла!")
            await message.answer("❌ Ссылка на аудиофайл не получена — задача не отправлена в сервис.")
            return
        cost = get_speech_to_text_price(duration_sec)
        balance = get_balance(user_id)
        if balance < cost:
            await message.answer(
                f"💸 Недостаточно средств для распознавания речи.\n"
                f"Ваш баланс: {balance:.2f}₽, требуется: {cost:.2f}₽",
                reply_markup=menu_button()
            )
            await state.clear()
            return
        try:
            logging.info(f"[S2T] Отправляем запрос на create_speech_to_text_task...")
            api_response = await create_speech_to_text_task(
                audio_url=audio_url,
                language_code="ru",
                tag_audio_events=True,
                diarize=True,
                callback_url=f"{CALLBACK_BASE_URL}/speech-to-text-callback",
                model="elevenlabs/speech-to-text",
            )
            logging.info(f"[S2T] Ответ API: {api_response}")
        except Exception as e:
            logging.error(f"[S2T] Ошибка при обращении к ElevenLabs API: {e}")
            await message.answer(f"❌ Ошибка при обращении к ElevenLabs API: {e}")
            return
        # Debug инфо о raw ответе
        await message.answer(f"🧾 Ответ ElevenLabs API:\n<pre>{api_response}</pre>", parse_mode="HTML")
        data = api_response.get('data', {})
        task_id = data.get('taskId') or data.get('task_id')
        log_user_request(
            user_telegram_id=user_id,
            user_username=username,
            user_first_name=first_name,
            request_type="speech_to_text",
            model_name="elevenlabs/speech-to-text",
            prompt=audio_url,
            image_urls=None,
            task_id=task_id,
            cost=cost,
            api_response=str(api_response),
        )
        if task_id:
            logging.info(f"[S2T] Задача создана! task_id={task_id}")
            if not deduct_balance(user_id, cost):
                logging.error(f"[S2T] Не удалось списать {cost}₽ у пользователя {user_id} после успешного создания задачи")
                await message.answer(
                    "⚠️ Ошибка при списании средств. Пожалуйста, свяжитесь с поддержкой.",
                    reply_markup=menu_button()
                )
                await state.clear()
                return
            await message.answer(
                f"✅ Задача 'Голос в текст' создана! Task ID: <code>{task_id}</code>\nОжидайте результат в чате после завершения распознавания."
            )
            await state.clear()
        else:
            logging.error(f"[S2T] Не удалось получить идентификатор задачи (task_id): {api_response}")
            await message.answer("❌ Не удалось получить идентификатор задачи (task_id). Проверьте ссылку и попробуйте снова либо обратитесь в поддержку.")
    except Exception as e:
        logging.error(f"[S2T] Критическая ошибка: {e}", exc_info=True)
        await message.answer(f"❌ Критическая ошибка в обработке аудио: {e}")
    # В случае любой ошибки state не очищаем — пользователь может попробовать снова


if __name__ == "__main__":
    import asyncio

    asyncio.run(main())
