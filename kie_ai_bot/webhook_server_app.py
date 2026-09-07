# Webhook сервер для мобильного приложения (RuStore)
# Обрабатывает платежи от YooMoney для пользователей приложения
# Запуск: python webhook_server_app.py или через systemd

import os
import logging
import time
from flask import Flask, request
from config import DB_HOST, DB_NAME, DB_USER, DB_PASSWORD, DB_PORT

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

# Кошелек для приложения
YOOMONEY_RECEIVER_APP = os.getenv("YOOMONEY_RECEIVER_APP", "4100119283768788")

# Prometheus Metrics (опционально)
try:
    from prometheus_client import Counter, Histogram
    from prometheus_client import generate_latest, CONTENT_TYPE_LATEST
    from flask import Response
    
    webhook_requests_total = Counter(
        'app_webhook_requests_total',
        'Total app webhook requests',
        ['status']
    )
    
    webhook_processing_duration = Histogram(
        'app_webhook_processing_duration_seconds',
        'App webhook processing duration',
        buckets=[0.1, 0.5, 1.0, 2.0, 5.0]
    )
    
    @app.route('/metrics')
    def metrics():
        return Response(generate_latest(), mimetype=CONTENT_TYPE_LATEST)
    
except ImportError:
    logger.warning("⚠️ prometheus_client не установлен, метрики недоступны")
    webhook_requests_total = None
    webhook_processing_duration = None


# --- Функции работы с БД ---
def get_connection():
    """Получение соединения с БД"""
    import pymysql
    return pymysql.connect(
        host=DB_HOST,
        user=DB_USER,
        password=DB_PASSWORD,
        database=DB_NAME,
        port=DB_PORT,
        charset='utf8mb4',
        cursorclass=pymysql.cursors.DictCursor
    )


def update_balance_by_vk_id(vk_user_id: str, tokens: float):
    """Пополнение баланса по VK ID"""
    conn = get_connection()
    try:
        with conn.cursor() as cursor:
            cursor.execute(
                "UPDATE users SET balance = balance + %s WHERE vk_user_id = %s",
                (tokens, str(vk_user_id))
            )
        conn.commit()
    finally:
        conn.close()


def update_balance_by_device_id(device_id: str, tokens: float):
    """Пополнение баланса по device_id (гость)"""
    conn = get_connection()
    try:
        with conn.cursor() as cursor:
            cursor.execute(
                "UPDATE users SET balance = balance + %s WHERE device_id = %s",
                (tokens, str(device_id))
            )
        conn.commit()
    finally:
        conn.close()


def update_balance_by_user_id(user_id: int, tokens: float):
    """Пополнение баланса по user_id (email)"""
    conn = get_connection()
    try:
        with conn.cursor() as cursor:
            cursor.execute(
                "UPDATE users SET balance = balance + %s WHERE id = %s",
                (tokens, int(user_id))
            )
        conn.commit()
    finally:
        conn.close()


def update_partner_commission_by_vk(vk_user_id: str, purchase_amount: float):
    """Начислить комиссию партнёру при оплате реферала (по VK ID)"""
    from database import update_partner_commission_by_vk as update_partner
    try:
        update_partner(vk_user_id, purchase_amount)
    except Exception as e:
        logger.warning(f"Ошибка начисления партнёрской комиссии: {e}")


# --- Вебхук для YooMoney (только для приложения) ---
@app.route('/yoomoney-webhook-app', methods=['POST'])
def yoomoney_webhook_app():
    """Обработка webhook от YooMoney для платежей из мобильного приложения"""
    logger.info("=== Новый входящий webhook для приложения ===")
    
    start_time = time.time() if webhook_processing_duration else None
    
    data = request.form
    label = data.get('label')
    
    if not label:
        logger.warning("Webhook пропущен: нет label")
        if webhook_requests_total:
            webhook_requests_total.labels(status='invalid').inc()
        return "Invalid data", 400
    
    # Проверяем, что label начинается с "app_" (платежи от приложения)
    if not label.startswith('app_'):
        logger.warning(f"Webhook пропущен: label '{label}' не для приложения")
        if webhook_requests_total:
            webhook_requests_total.labels(status='invalid').inc()
        return "OK", 200  # Возвращаем OK, чтобы YooMoney не повторял запрос
    
    try:
        conn = get_connection()
        try:
            with conn.cursor() as cursor:
                # Ищем платеж по label
                cursor.execute("""
                    SELECT telegram_id, vk_user_id, device_id, user_id, status, tokens, amount 
                    FROM payments 
                    WHERE label = %s
                """, (label,))
                payment = cursor.fetchone()
                
                if not payment:
                    logger.warning(f"Платеж с label {label} не найден в БД.")
                    if webhook_requests_total:
                        webhook_requests_total.labels(status='not_found').inc()
                    return "OK", 200
                
                if payment['status'] == 'completed':
                    logger.info(f"Платеж {label} уже обработан ранее.")
                    if webhook_requests_total:
                        webhook_requests_total.labels(status='duplicate').inc()
                    return "OK", 200
                
                # Получаем данные платежа
                vk_user_id = payment.get('vk_user_id')
                device_id = payment.get('device_id')
                user_id = payment.get('user_id')
                tokens_to_add = float(payment.get('tokens') or 0)
                amount_rub = float(payment.get('amount') or 0)
                
                # Начисляем баланс в зависимости от типа пользователя
                if vk_user_id:
                    update_balance_by_vk_id(vk_user_id, tokens_to_add)
                    logger.info(f"✅ Баланс приложения (vk_user_id={vk_user_id}) пополнен на {tokens_to_add} токенов.")
                    # Начисляем партнёрскую комиссию, если есть реферал
                    if amount_rub > 0:
                        update_partner_commission_by_vk(vk_user_id, amount_rub)
                elif device_id:
                    update_balance_by_device_id(device_id, tokens_to_add)
                    logger.info(f"✅ Баланс приложения (гость device_id={device_id}) пополнен на {tokens_to_add} токенов.")
                elif user_id:
                    update_balance_by_user_id(user_id, tokens_to_add)
                    logger.info(f"✅ Баланс приложения (user_id={user_id}) пополнен на {tokens_to_add} токенов.")
                else:
                    logger.warning(f"Платеж {label} не имеет идентификатора пользователя приложения.")
                    if webhook_requests_total:
                        webhook_requests_total.labels(status='no_user').inc()
                    return "OK", 200
                
                # Обновляем статус платежа
                cursor.execute("UPDATE payments SET status='completed' WHERE label=%s", (label,))
                conn.commit()
                
                logger.info(f"✅ Платеж {label} успешно обработан. Начислено {tokens_to_add} токенов.")
                
                # Метрики успешной обработки
                if webhook_requests_total:
                    webhook_requests_total.labels(status='success').inc()
                if webhook_processing_duration and start_time:
                    webhook_processing_duration.observe(time.time() - start_time)
                    
        finally:
            conn.close()
            
    except Exception as e:
        logger.error(f"❌ Ошибка обработки webhook: {e}", exc_info=True)
        if webhook_requests_total:
            webhook_requests_total.labels(status='error').inc()
        return "Internal Server Error", 500
    
    return "OK", 200


@app.route('/health', methods=['GET'])
def health():
    """Health check endpoint"""
    return {"status": "ok", "service": "app-webhook"}, 200


if __name__ == '__main__':
    # Порт для webhook приложения (можно изменить через переменную окружения)
    port = int(os.getenv("APP_WEBHOOK_PORT", "8002"))
    debug = os.getenv("ENVIRONMENT") != "production"
    logger.info(f"🚀 Запуск webhook сервера для приложения на порту {port}")
    logger.info(f"📱 Кошелек: {YOOMONEY_RECEIVER_APP}")
    app.run(port=port, debug=debug, host='0.0.0.0')
