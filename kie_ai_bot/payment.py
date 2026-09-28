# utils/payments.py
import urllib.parse
import time
from datetime import datetime
import pymysql
from config import YOOMONEY_RECEIVER, YOOMONEY_RECEIVER_APP, YOOMONEY_SUCCESS_URL, DB_HOST, DB_NAME, DB_USER, DB_PASSWORD, DB_PORT

TOKEN_PACKAGES_PRICES = {
    200: 200,
    250: 250,
    300: 300,
    500: 500,
    1000: 1000,
    2000: 2000,
    5000: 5000,
}

# Пакеты для приложения RuStore (те же суммы -> токены)
APP_TOKEN_PACKAGES = TOKEN_PACKAGES_PRICES


def create_payment_record(chat_id: int, label: str, amount: float):
    """Создаёт запись в таблице payments с токенами, датой и статусом pending."""
    tokens_to_add = TOKEN_PACKAGES_PRICES.get(round(amount))
    if not tokens_to_add:
        raise ValueError(f"Не найден пакет токенов для суммы {amount}")

    # Получаем текущий тег пользователя (если есть), чтобы зафиксировать источник платежа
    try:
        from database import get_user_tag  # Локальный импорт, чтобы избежать циклических зависимостей
        user_tag = get_user_tag(chat_id)
    except Exception:
        user_tag = None

    conn = pymysql.connect(
        host=DB_HOST,
        user=DB_USER,
        password=DB_PASSWORD,
        database=DB_NAME,
        port=DB_PORT,
        charset='utf8mb4',
        cursorclass=pymysql.cursors.DictCursor
    )
    try:
        with conn.cursor() as cursor:
            cursor.execute("""
                INSERT INTO payments (telegram_id, label, amount, tokens, status, created_at, campaign_tag)
                VALUES (%s, %s, %s, %s, %s, %s, %s)
            """, (chat_id, label, amount, tokens_to_add, 'pending', datetime.utcnow(), user_tag))
        conn.commit()
    finally:
        conn.close()


def generate_yoomoney_link(chat_id: int, amount: float) -> tuple[str, str]:
    """Генерирует ссылку для пополнения баланса через YooMoney и создаёт запись в БД."""
    amount_str = f"{amount:.2f}"
    timestamp = int(time.time())
    label = f"topup_{chat_id}_{timestamp}"

    create_payment_record(chat_id, label, amount)

    link = (
        f"https://yoomoney.ru/quickpay/confirm.xml?"
        f"receiver={YOOMONEY_RECEIVER}"
        f"&quickpay-form=shop"
        f"&targets=BotBalance"
        f"&paymentType=AC"
        f"&sum={amount_str}"
        f"&label={label}"
        f"&successURL={urllib.parse.quote(YOOMONEY_SUCCESS_URL)}"
    )
    return link, label


def create_app_payment_record(vk_user_id: str = None, device_id: str = None, user_id: int = None, label: str = None, amount: float = None):
    """Создаёт запись о пополнении (vk_user_id, device_id или user_id — один из них)."""
    if not label or amount is None:
        raise ValueError("Нужны label и amount")
    tokens = APP_TOKEN_PACKAGES.get(round(amount))
    if not tokens:
        raise ValueError(f"Не найден пакет для суммы {amount}. Доступные: {list(APP_TOKEN_PACKAGES.keys())}")
    conn = pymysql.connect(
        host=DB_HOST, user=DB_USER, password=DB_PASSWORD, database=DB_NAME, port=DB_PORT,
        charset='utf8mb4', cursorclass=pymysql.cursors.DictCursor
    )
    try:
        with conn.cursor() as cursor:
            # Определяем, какие поля использовать в зависимости от типа пользователя
            if user_id:
                cursor.execute("""
                    INSERT INTO payments (user_id, label, amount, tokens, status, created_at, campaign_tag)
                    VALUES (%s, %s, %s, %s, 'pending', %s, 'app')
                """, (int(user_id), label, amount, tokens, datetime.utcnow()))
            elif vk_user_id:
                cursor.execute("""
                    INSERT INTO payments (vk_user_id, label, amount, tokens, status, created_at, campaign_tag)
                    VALUES (%s, %s, %s, %s, 'pending', %s, 'app')
                """, (str(vk_user_id), label, amount, tokens, datetime.utcnow()))
            elif device_id:
                cursor.execute("""
                    INSERT INTO payments (device_id, label, amount, tokens, status, created_at, campaign_tag)
                    VALUES (%s, %s, %s, %s, 'pending', %s, 'app')
                """, (str(device_id), label, amount, tokens, datetime.utcnow()))
            else:
                raise ValueError("Нужен один из параметров: vk_user_id, device_id или user_id")
        conn.commit()
    finally:
        conn.close()


def generate_yoomoney_link_for_app(vk_user_id: str = None, device_id: str = None, user_id: int = None, amount: float = None) -> tuple:
    """Генерирует ссылку на пополнение. Передайте ровно один из: vk_user_id, device_id, user_id."""
    set_count = sum(1 for x in [vk_user_id, device_id, user_id] if x is not None)
    if set_count != 1:
        raise ValueError("Укажите ровно один из параметров: vk_user_id, device_id или user_id")
    user_key = str(vk_user_id or device_id or user_id)
    amount_str = f"{amount:.2f}"
    timestamp = int(time.time())
    label = f"app_{user_key}_{timestamp}"
    create_app_payment_record(vk_user_id=vk_user_id, device_id=device_id, user_id=user_id, label=label, amount=float(amount))
    # Используем отдельный кошелек для приложения
    link = (
        f"https://yoomoney.ru/quickpay/confirm.xml?"
        f"receiver={YOOMONEY_RECEIVER_APP}"
        f"&quickpay-form=shop"
        f"&targets=Пополнение баланса"
        f"&paymentType=AC"
        f"&sum={amount_str}"
        f"&label={label}"
        f"&successURL={urllib.parse.quote(YOOMONEY_SUCCESS_URL)}"
    )
    return link, label
