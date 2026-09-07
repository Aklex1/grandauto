
"""
Конфигурация проекта с поддержкой переменных окружения
Использует .env файл для безопасного хранения секретов
"""
import os
from dotenv import load_dotenv

# Загружаем переменные из .env файла
load_dotenv()

# Telegram Bot
TELEGRAM_BOT_TOKEN = os.getenv("TELEGRAM_BOT_TOKEN", "8274788194:AAGMCv8-A2KxgLCR0NV3ShP2iYsfZQaXoZQ")

# KIE API
KIE_API_KEY = os.getenv("KIE_API_KEY", "611e797a34cfaa7f5ff771d982fbe800")

# YooMoney
YOOMONEY_RECEIVER = os.getenv("YOOMONEY_RECEIVER", "4100119260324712")  # Для Telegram бота
YOOMONEY_RECEIVER_APP = os.getenv("YOOMONEY_RECEIVER_APP", "4100119283768788")  # Для мобильного приложения
YOOMONEY_SUCCESS_URL = os.getenv("YOOMONEY_SUCCESS_URL", "https://genius-bot.ru/success")

# Database
DB_HOST = os.getenv("DB_HOST", "akklexb6.beget.tech")
DB_NAME = os.getenv("DB_NAME", "akklexb6_neuro")
DB_USER = os.getenv("DB_USER", "akklexb6_neuro")
DB_PASSWORD = os.getenv("DB_PASSWORD", "hXm*!e&30bQt")
DB_PORT = int(os.getenv("DB_PORT", "3306"))

# Redis (для кэширования, очередей и FSM storage)
REDIS_HOST = os.getenv("REDIS_HOST", "localhost")
REDIS_PORT = int(os.getenv("REDIS_PORT", "6379"))
REDIS_DB = int(os.getenv("REDIS_DB", "0"))
REDIS_PASSWORD = os.getenv("REDIS_PASSWORD", None)

# Connection Pool настройки
DB_POOL_MIN_SIZE = int(os.getenv("DB_POOL_MIN_SIZE", "5"))
DB_POOL_MAX_SIZE = int(os.getenv("DB_POOL_MAX_SIZE", "20"))

# Наценки
GENERATION_MARKUP = float(os.getenv("GENERATION_MARKUP", "50"))
SPEECH_TO_TEXT_MARKUP = float(os.getenv("SPEECH_TO_TEXT_MARKUP", "0.3"))

# Публичный адрес этого сервера, куда KIE присылает callback-и с результатами.
# Должен указывать на порт 8010 (FastAPI внутри main.py) и быть доступен из интернета.
CALLBACK_BASE_URL = os.getenv("CALLBACK_BASE_URL", "http://techscore.ru:8010").rstrip("/")

# Публичный адрес API мобильного приложения (порт 8011)
APP_API_BASE_URL = os.getenv("APP_API_BASE_URL", "http://techscore.ru:8011").rstrip("/")

# URL сайта для авторизации
WP_SITE_URL = os.getenv("WP_SITE_URL", "https://genius-bot.ru")
WP_AUTH_PAGE = os.getenv("WP_AUTH_PAGE", "/tts-login")

# JWT для приложения (вход по email)
JWT_SECRET = os.getenv("JWT_SECRET", "change-me-in-production")
JWT_ALGORITHM = "HS256"
JWT_EXPIRE_DAYS = 30

# SMTP настройки для отправки email
SMTP_HOST = os.getenv("SMTP_HOST", "smtp.gmail.com")
SMTP_PORT = int(os.getenv("SMTP_PORT", "587"))
SMTP_USER = os.getenv("SMTP_USER", "akklex9@gmail.com")
# Пароль приложения Gmail (можно с пробелами - они будут автоматически удалены в email_service.py)
SMTP_PASSWORD = os.getenv("SMTP_PASSWORD", "hats prqs gpmu ojio")
SMTP_FROM_EMAIL = os.getenv("SMTP_FROM_EMAIL", "akklex9@gmail.com")
SMTP_FROM_NAME = os.getenv("SMTP_FROM_NAME", "Neuro Hub")

# Базовый URL приложения (для ссылок в письмах)
APP_BASE_URL = os.getenv("APP_BASE_URL", "https://your-app-domain.com")

# Админы бота (ID и username)
ADMIN_IDS = [367692958]  # Основной админ по ID
ADMIN_USERNAMES = ["Alex_mlr_dev"]  # Админы по username

def is_admin(user_id: int, username: str = None) -> bool:
    """Проверяет, является ли пользователь админом"""
    if user_id in ADMIN_IDS:
        return True
    if username and username in ADMIN_USERNAMES:
        return True
    return False

# Проверка обязательных переменных (только в продакшене)
if os.getenv("ENVIRONMENT") == "production":
    required_vars = [
        "TELEGRAM_BOT_TOKEN",
        "KIE_API_KEY",
        "YOOMONEY_RECEIVER",
        "DB_HOST",
        "DB_NAME",
        "DB_USER",
        "DB_PASSWORD"
    ]
    missing_vars = [var for var in required_vars if not os.getenv(var)]
    if missing_vars:
        raise ValueError(f"Отсутствуют обязательные переменные окружения: {', '.join(missing_vars)}")