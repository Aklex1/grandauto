"""Пересылка уведомлений ЮMoney на сайт genius-bot.ru из обработчика KIE-бота.

Схема та же, что уже работает для AI-коуча: в настройках кошелька остаётся
один адрес — http://89.169.38.152:8000/yoomoney-webhook, а обработчик бота
отдаёт чужие платежи по метке. Своя логика бота не меняется и не ломается.

Метки сайта:
    topup_wp_…        — пополнение из кабинета, пользователь сайта
    topup_telegram_…  — то же для пользователя, пришедшего из Telegram
    kie-neurohub|…    — пополнение в разделе «Нейросети»

Важно: тело запроса пересылается БАЙТ В БАЙТ. Подпись ЮMoney считается по
всем параметрам, поэтому неизменённое тело остаётся проверяемым — сайт
проверит его сам, если в админке задан секрет.

Куда вставить
-------------
В обработчик /yoomoney-webhook, следом за блоком пересылки коучу: если метка
сайта — переслать и сразу вернуть 200, дальше не идти. Автоматическая вставка:
    python3 patch_kie_webhook_site.py /opt/src/kie_ai_bot/<файл>.py --dry-run
"""
from __future__ import annotations

import logging
import time
import urllib.error
import urllib.request

log = logging.getLogger("yoomoney-relay-site")

# Приёмник на сайте: он находит пользователя по метке и пополняет ему баланс.
SITE_NOTIFY_URL = "https://genius-bot.ru/wp-json/genius/v1/yoomoney"

# Метки, которые выдаёт сайт при создании платежа.
SITE_LABEL_PREFIXES = ("topup_wp_", "topup_telegram_", "topup_", "kie-neurohub|")

ATTEMPTS = 3
TIMEOUT = 15

# Свой opener без прокси: в окружении systemd может лежать http_proxy,
# и urllib увёл бы запрос не туда.
_opener = urllib.request.build_opener(urllib.request.ProxyHandler({}))


def is_site_payment(label) -> bool:
    """True, если платёж заводил сайт, а не бот.

    Проверка точная: у бота метки вида «kie-123», у раздела «Нейросети» —
    «kie-neurohub|…», и путать их нельзя.
    """
    return bool(label) and str(label).startswith(SITE_LABEL_PREFIXES)


def relay_to_site_sync(raw_body: bytes, content_type: str | None = None) -> bool:
    """Пересылает уведомление сайту как есть.

    True — сайт ответил (зачислил он платёж или нет, видно в его журнале,
    админка «Звуки» → «Платежи по сервисам»),
    False — доставить не удалось: платёж останется неподтверждённым, и его
    можно будет провести вручную.
    """
    headers = {"Content-Type": content_type or "application/x-www-form-urlencoded"}
    for attempt in range(ATTEMPTS):
        request = urllib.request.Request(SITE_NOTIFY_URL, data=raw_body,
                                         headers=headers, method="POST")
        try:
            with _opener.open(request, timeout=TIMEOUT) as response:
                body = response.read(300).decode("utf-8", "replace")
                log.info("Переслано сайту: HTTP %s %s", response.status, body)
                return True
        except urllib.error.HTTPError as exc:
            body = exc.read(300).decode("utf-8", "replace") if exc.fp else ""
            log.warning("Сайт ответил HTTP %s: %s", exc.code, body)
            if exc.code < 500:
                return True  # ответил осмысленно — дальше это его дело
        except (urllib.error.URLError, OSError) as exc:
            log.warning("Пересылка сайту не удалась (попытка %s): %s", attempt + 1, exc)
        time.sleep(1.5 * (attempt + 1))

    log.error("Не удалось переслать уведомление сайту — платёж не зачислен")
    return False


# ---------------------------------------------------------------------------
# Как это выглядит в обработчике (Flask, как у KIE-бота)
# ---------------------------------------------------------------------------
#
# from site_relay_snippet import is_site_payment, relay_to_site_sync
#
# @app.route('/yoomoney-webhook', methods=['POST'])
# def yoomoney_webhook():
#     _raw_body = request.get_data()
#     if is_ai_coach_payment(request.form.get('label')):      # уже стоит
#         relay_to_ai_coach_sync(_raw_body, request.content_type)
#         return 'ok', 200
#     if is_site_payment(request.form.get('label')):          # добавляем
#         relay_to_site_sync(_raw_body, request.content_type)
#         return 'ok', 200
#     ...                                                     # логика бота как была


if __name__ == "__main__":
    # Самопроверка: python3 site_relay_snippet.py
    # Реальные платежи не затрагивает — метка заведомо несуществующая.
    logging.basicConfig(level=logging.INFO, format="%(message)s")
    for label in ("topup_wp_1_0000000000", "kie-neurohub|999|test|10.00", "kie-123", "ac.1.pro_month"):
        print(f"  {label:<32} → платёж сайта: {is_site_payment(label)}")
    body = b"notification_type=p2p-incoming&label=topup_wp_1_0000000000&amount=1.00&withdraw_amount=1.00"
    print("доставлено сайту:", relay_to_site_sync(body))
