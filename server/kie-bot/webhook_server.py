import asyncio
import hashlib
import hmac
import os
import re
import threading
import time
import logging
from urllib.parse import quote

from flask import Flask, request
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

# --------------------------------------------------------------------------
# Настройки
# --------------------------------------------------------------------------

# Секрет HTTP-уведомлений из кошелька ЮMoney: Настройки → HTTP-уведомления.
# Берётся из окружения, чтобы не лежать в коде. Пока он не задан, подпись
# проверить нечем — уведомления принимаются как раньше, но в журнале об
# этом говорится прямо, а не молча.
NOTIFICATION_SECRET = os.getenv("YOOMONEY_NOTIFICATION_SECRET", "").strip()

# Сверять ли сумму платежа с ожидаемой. По умолчанию выключено: сначала
# посмотрите в журнале строки «сумма: пришло X, ожидалось Y» и убедитесь,
# что они сходятся, и только потом включайте YOOMONEY_CHECK_AMOUNT=1.
# Иначе недоплаченный платёж закрывается полным пакетом токенов.
CHECK_AMOUNT = os.getenv("YOOMONEY_CHECK_AMOUNT", "0") == "1"

# Слушаем только себя: уведомление приносит раздатчик с этой же машины.
# Обработчик, открытый наружу, пополняет баланс любому, кто подберёт метку.
# Если раздатчика нет и ЮMoney стучится сюда напрямую — WEBHOOK_HOST=0.0.0.0.
WEBHOOK_HOST = os.getenv("WEBHOOK_HOST", "127.0.0.1")
WEBHOOK_PORT = int(os.getenv("WEBHOOK_PORT", "8000"))

# Цены пакетов (сумма в рублях -> количество токенов)
TOKEN_PACKAGES_PRICES = {
    10: 10,
    500: 500,
    1000: 1000,
    2000: 2000,
    5000: 5000,
}

# --------------------------------------------------------------------------
# Метрики
# --------------------------------------------------------------------------

try:
    from prometheus_client import Counter, Histogram, start_http_server
    from prometheus_client import generate_latest, CONTENT_TYPE_LATEST
    from flask import Response

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

    @app.route('/metrics')
    def metrics():
        return Response(generate_latest(), mimetype=CONTENT_TYPE_LATEST)

    # Отдельный сервер метрик. Порт 8002 занят вебхуком приложения,
    # поэтому по умолчанию 8003. WEBHOOK_METRICS_PORT=0 — не поднимать.
    METRICS_PORT = int(os.getenv("WEBHOOK_METRICS_PORT", "8003"))

    def start_metrics_server():
        try:
            start_http_server(METRICS_PORT)
        except Exception as e:
            logger.error(f"Ошибка metrics server на {METRICS_PORT}: {e}")

    if METRICS_PORT:
        metrics_thread = threading.Thread(target=start_metrics_server, daemon=True)
        metrics_thread.start()
        time.sleep(0.5)
        logger.info(f"✅ Prometheus metrics server для webhook запущен на порту {METRICS_PORT}")
    logger.info(f"💡 Метрики также доступны на http://127.0.0.1:{WEBHOOK_PORT}/metrics")

except ImportError:
    logger.warning("⚠️ prometheus_client не установлен, метрики недоступны")
    webhook_requests_total = None
    webhook_processing_duration = None


def count(status: str):
    if webhook_requests_total:
        webhook_requests_total.labels(status=status).inc()


# Инициализация бота
bot = Bot(token=TELEGRAM_BOT_TOKEN, default=DefaultBotProperties(parse_mode="HTML"))


# --------------------------------------------------------------------------
# Подпись уведомления
# --------------------------------------------------------------------------

def signature_ok(params: dict) -> bool:
    """
    Проверка подписи ЮMoney.

    Подписей у них две, и какая придёт — зависит от настроек кошелька:
    новая sign (HMAC-SHA256 по отсортированным полям) и устаревшая
    sha1_hash по фиксированному порядку. Принимаем обе: отказывать
    деньгам из-за формата подписи неправильно.

    Считается так же, как на сайте genius-bot.ru, — чтобы один и тот же
    секрет подходил обоим получателям одного уведомления.
    """
    if not NOTIFICATION_SECRET:
        return True

    sign = str(params.get('sign') or '').strip().lower()
    if sign:
        fields = {k: v for k, v in params.items() if k != 'sign'}
        body = '&'.join(
            f"{key}={quote(str(fields[key]), safe='')}" for key in sorted(fields)
        )
        calc = hmac.new(NOTIFICATION_SECRET.encode(), body.encode(), hashlib.sha256).hexdigest()
        if hmac.compare_digest(calc, sign):
            return True

    provided = str(params.get('sha1_hash') or '').strip().lower()
    if not provided:
        return False

    body = '&'.join(str(params.get(key, '')) for key in (
        'notification_type', 'operation_id', 'amount',
        'currency', 'datetime', 'sender', 'codepro',
    )) + '&' + NOTIFICATION_SECRET + '&' + str(params.get('label', ''))
    return hmac.compare_digest(hashlib.sha1(body.encode()).hexdigest(), provided)


# --------------------------------------------------------------------------
# Telegram
# --------------------------------------------------------------------------

async def send_telegram_message(chat_id: int, text: str):
    try:
        await bot.send_message(chat_id=chat_id, text=text)
    except Exception as e:
        logger.error(f"Ошибка при отправке сообщения Telegram: {e}")


def send_telegram_in_thread(chat_id: int, text: str):
    """Отправка сообщения в отдельном потоке."""
    threading.Thread(
        target=lambda: asyncio.run(send_telegram_message(chat_id, text)),
        daemon=True,
    ).start()


# --------------------------------------------------------------------------
# Диагностика
# --------------------------------------------------------------------------

def recent_labels(cursor, label: str):
    """
    Когда платёж с такой меткой не найден, в журнале должно быть видно, что
    вообще есть у этого человека. Иначе строка «не найден» не отвечает на
    главный вопрос — метку писал не тот экземпляр бота или база другая.
    """
    m = re.match(r'^topup_(\d+)_', label or '')
    if not m:
        return ''
    try:
        cursor.execute(
            "SELECT label, status FROM payments WHERE telegram_id=%s ORDER BY id DESC LIMIT 5",
            (m.group(1),),
        )
        rows = cursor.fetchall() or []
    except Exception as e:
        return f" (не удалось посмотреть соседние платежи: {e})"
    if not rows:
        return f" У пользователя {m.group(1)} в таблице payments нет ни одной записи."
    listed = ', '.join(f"{r['label']}={r['status']}" for r in rows)
    return f" Последние платежи пользователя {m.group(1)}: {listed}."


# --------------------------------------------------------------------------
# Вебхук ЮMoney
# --------------------------------------------------------------------------

@app.route('/yoomoney-webhook', methods=['POST'])
def yoomoney_webhook():
    start_time = time.time()

    # ЮMoney шлёт форму. JSON принимаем на случай ручной проверки.
    params = request.form.to_dict() or (request.get_json(silent=True) or {})
    label = str(params.get('label') or '').strip()
    paid = params.get('withdraw_amount') or params.get('amount') or '0'

    logger.info(f"=== Новый входящий webhook: метка {label or '—'}, сумма {paid} ===")

    if not NOTIFICATION_SECRET:
        logger.warning(
            "⚠️ ПОДПИСЬ НЕ ПРОВЕРЯЕТСЯ: не задан YOOMONEY_NOTIFICATION_SECRET. "
            "Пока так, пополнить баланс может любой, кто подберёт метку."
        )
    elif not signature_ok(params):
        logger.warning(f"Webhook отклонён: подпись не сошлась (метка {label or '—'}).")
        count('bad_signature')
        return "Bad signature", 403

    if not label:
        # Кошелёк присылает пробное уведомление без метки при сохранении адреса.
        logger.warning("Webhook пропущен: нет label")
        count('invalid')
        return "OK", 200

    from database import get_connection_context
    try:
        with get_connection_context() as conn:
            with conn.cursor() as cursor:
                cursor.execute(
                    "SELECT * FROM payments WHERE label=%s",
                    (label,),
                )
                payment = cursor.fetchone()

                if not payment:
                    logger.warning(f"Платеж с label {label} не найден.{recent_labels(cursor, label)}")
                    count('not_found')
                    return "OK", 200

                if payment['status'] == 'completed':
                    logger.info(f"Платеж {label} уже обработан, повторное уведомление пропущено.")
                    count('duplicate')
                    return "OK", 200

                telegram_id = payment['telegram_id']
                tokens_to_add = payment['tokens']

                # Сумма: сверяем, если в записи она есть. Сначала только
                # пишем в журнал — включать отказ через YOOMONEY_CHECK_AMOUNT=1
                # стоит, убедившись, что значения сходятся.
                expected = payment.get('amount')
                if expected is not None:
                    try:
                        enough = float(paid) + 0.01 >= float(expected)
                    except (TypeError, ValueError):
                        enough = True
                    if not enough:
                        logger.warning(
                            f"Платёж {label}: сумма меньше ожидаемой — пришло {paid}, ожидалось {expected}."
                        )
                        if CHECK_AMOUNT:
                            count('underpaid')
                            return "OK", 200

                # Метку закрываем условием, а не проверкой выше: два
                # одновременных уведомления иначе оба пройдут «не completed»
                # и начислят дважды. Здесь второму достанется rowcount 0.
                cursor.execute(
                    "UPDATE payments SET status='completed' WHERE label=%s AND status<>'completed'",
                    (label,),
                )
                if cursor.rowcount != 1:
                    logger.info(f"Платеж {label} закрыт параллельным уведомлением, начисление пропущено.")
                    count('duplicate')
                    return "OK", 200

                # Баланс меняем в той же транзакции, что и статус платежа.
                # Раздельные соединения означали бы, что при сбое второго
                # шага деньги начислены, а платёж не закрыт — и следующее
                # уведомление начислит их ещё раз.
                cursor.execute(
                    "UPDATE users SET balance = balance + %s WHERE telegram_id=%s",
                    (tokens_to_add, telegram_id),
                )
                conn.commit()

                logger.info(
                    f"Баланс пользователя {telegram_id} пополнен на {tokens_to_add} токенов "
                    f"(метка {label}, сумма {paid})."
                )
                count('success')
                if webhook_processing_duration:
                    webhook_processing_duration.observe(time.time() - start_time)

                # Сообщение — после commit: обещать пополнение до того, как
                # оно записано, нельзя.
                send_telegram_in_thread(
                    chat_id=telegram_id,
                    text=f"✅ Ваш баланс был пополнен на {tokens_to_add} токенов."
                )

    except Exception as e:
        # 500 здесь осмысленный: ЮMoney повторит уведомление, и платёж
        # не потеряется из-за разрыва связи с базой.
        logger.error(f"Ошибка обработки webhook: {e}", exc_info=True)
        count('error')
        return "Internal Server Error", 500

    return "OK", 200


@app.route('/health', methods=['GET'])
def health():
    return {
        "service": "kie-webhook",
        "signature_check": bool(NOTIFICATION_SECRET),
        "amount_check": CHECK_AMOUNT,
    }, 200


if __name__ == '__main__':
    # В продакшене: gunicorn -w 4 -b 127.0.0.1:8000 webhook_server:app
    logger.info(
        f"Запуск вебхука на {WEBHOOK_HOST}:{WEBHOOK_PORT}; "
        f"проверка подписи: {'включена' if NOTIFICATION_SECRET else 'ВЫКЛЮЧЕНА'}"
    )
    app.run(port=WEBHOOK_PORT, debug=os.getenv("ENVIRONMENT") != "production", host=WEBHOOK_HOST)
