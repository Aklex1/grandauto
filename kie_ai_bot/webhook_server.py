import asyncio
import threading
import os
import logging
import time
from flask import Flask, request
import pymysql.cursors
from config import TELEGRAM_BOT_TOKEN
from aiogram import Bot
from aiogram.client.bot import DefaultBotProperties

# Настройка логирования
logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(name)s - %(levelname)s - %(message)s'
)
logger = logging.getLogger(__name__)

app = Flask(__name__)

# Отключаем debug в продакшене
if os.getenv("ENVIRONMENT") == "production":
    app.config['DEBUG'] = False
    app.config['TESTING'] = False
else:
    app.config['DEBUG'] = True

# Prometheus Metrics для webhook сервера
try:
    from prometheus_client import Counter, Histogram, start_http_server
    from prometheus_client import generate_latest, CONTENT_TYPE_LATEST
    from flask import Response
    
    # Метрики
    webhook_requests_total = Counter(
        'webhook_requests_total',
        'Total webhook requests',
        ['status']
    )
    
    webhook_processing_duration = Histogram(
        'webhook_processing_duration_seconds',
        'Webhook processing duration',
        buckets=[0.1, 0.5, 1.0, 2.0, 5.0]
    )
    
    # Endpoint для метрик в Flask приложении (основной порт webhook сервера)
    @app.route('/metrics')
    def metrics():
        return Response(generate_latest(), mimetype=CONTENT_TYPE_LATEST)
    
    # Запуск отдельного сервера метрик в отдельном потоке.
    # Порт 8002 занят webhook-сервером приложения (webhook_server_app.py),
    # поэтому по умолчанию метрики поднимаются на 8003. WEBHOOK_METRICS_PORT=0 — отключить.
    METRICS_PORT = int(os.getenv("WEBHOOK_METRICS_PORT", "8003"))

    def start_metrics_server():
        try:
            start_http_server(METRICS_PORT)
        except Exception as e:
            logger.error(f"Ошибка metrics server на {METRICS_PORT}: {e}")
    
    try:
        if METRICS_PORT:
            metrics_thread = threading.Thread(target=start_metrics_server, daemon=True)
            metrics_thread.start()
            # Даем время на запуск
            time.sleep(0.5)
            logger.info(f"✅ Prometheus metrics server для webhook запущен на порту {METRICS_PORT}")
        logger.info("💡 Метрики также доступны через http://localhost:8000/metrics")
    except Exception as e:
        logger.warning(f"⚠️ Не удалось запустить metrics server на 8002: {e}")
        logger.info("💡 Метрики доступны через /metrics endpoint основного приложения")
    
except ImportError:
    logger.warning("⚠️ prometheus_client не установлен, метрики недоступны")
    webhook_requests_total = None
    webhook_processing_duration = None

# Цены пакетов (сумма в рублях -> количество токенов)
TOKEN_PACKAGES_PRICES = {
    10: 10,
    500: 500,
    1000: 1000,
    2000: 2000,
    5000: 5000,
}

# Инициализация бота
bot = Bot(token=TELEGRAM_BOT_TOKEN, default=DefaultBotProperties(parse_mode="HTML"))

# --- Функции работы с БД ---
def update_balance(telegram_id: int, tokens: int):
    """Пополняет баланс пользователя. Должно делать commit внутри."""
    from database import get_connection_context
    with get_connection_context() as conn:
        with conn.cursor() as cursor:
            cursor.execute(
                "UPDATE users SET balance = balance + %s WHERE telegram_id=%s",
                (tokens, telegram_id)
            )
        conn.commit()

# --- Отправка сообщений в Telegram ---
async def send_telegram_message(chat_id: int, text: str):
    try:
        await bot.send_message(chat_id=chat_id, text=text)
    except Exception as e:
        print(f"Ошибка при отправке сообщения Telegram: {e}")

def send_telegram_in_thread(chat_id: int, text: str):
    """Запуск асинхронной отправки сообщения в отдельном потоке"""
    threading.Thread(target=lambda: asyncio.run(send_telegram_message(chat_id, text))).start()

# --- Вебхук для YooMoney ---
@app.route('/yoomoney-webhook', methods=['POST'])
def yoomoney_webhook():
    logger.info("=== Новый входящий webhook ===")
    
    # Метрики
    start_time = None
    if webhook_processing_duration:
        start_time = time.time()
    
    data = request.form
    label = data.get('label')

    if not label:
        logger.warning("Webhook пропущен: нет label")
        if webhook_requests_total:
            webhook_requests_total.labels(status='invalid').inc()
        return "Invalid data", 400

    from database import get_connection_context
    try:
        with get_connection_context() as conn:
            with conn.cursor() as cursor:
                cursor.execute("SELECT telegram_id, status, tokens FROM payments WHERE label=%s", (label,))
                payment = cursor.fetchone()

                if not payment:
                    logger.warning(f"Платеж с label {label} не найден.")
                    return "OK", 200

                if payment['status'] == 'completed':
                    logger.info(f"Платеж {label} уже обработан.")
                    return "OK", 200

                telegram_id = payment['telegram_id']
                tokens_to_add = payment['tokens']

                update_balance(telegram_id, tokens_to_add)

                cursor.execute("UPDATE payments SET status='completed' WHERE label=%s", (label,))
                conn.commit()

                logger.info(f"Баланс пользователя {telegram_id} пополнен на {tokens_to_add} токенов.")

                send_telegram_in_thread(
                    chat_id=telegram_id,
                    text=f"✅ Ваш баланс был пополнен на {tokens_to_add} токенов."
                )

                logger.info(f"Отправка уведомления пользователю {telegram_id} инициирована.")
                
                # Метрики успешной обработки
                if webhook_requests_total:
                    webhook_requests_total.labels(status='success').inc()
                if webhook_processing_duration and start_time:
                    webhook_processing_duration.observe(time.time() - start_time)
                    
    except Exception as e:
        logger.error(f"Ошибка обработки webhook: {e}", exc_info=True)
        if webhook_requests_total:
            webhook_requests_total.labels(status='error').inc()
        return "Internal Server Error", 500

    return "OK", 200


if __name__ == '__main__':
    # В продакшене использовать gunicorn: gunicorn -w 4 -b 0.0.0.0:8000 webhook_server:app
    port = int(os.getenv("WEBHOOK_PORT", "8000"))
    debug = os.getenv("ENVIRONMENT") != "production"
    app.run(port=port, debug=debug, host='0.0.0.0')
