# Оплата ЮMoney: почему баланс не пополняется

Оплата внутри бота проходит (деньги уходят), но баланс не растёт. Код
зачисления исправен: обработчик находит платёж по `label`, добавляет
токены и ставит статус `completed`, повторные уведомления игнорирует.
Значит, до обработчика **не доходит HTTP-уведомление ЮMoney**.

## Как проверить, доходит ли колбэк

```bash
journalctl -u kie-webhook -n 200 --no-pager | grep -i "webhook\|Payload\|label"
```

* Есть строки `=== Новый входящий webhook ===` и `Payload ЮMoney: {...}` —
  уведомления доходят, смотрите дальше причину (label не найден, подпись).
* Пусто — уведомления **не приходят**. Причина в настройке ЮMoney или в
  недоступности эндпойнта.

Проверить, что сервис жив и слушает:

```bash
systemctl status kie-webhook --no-pager
curl -s http://127.0.0.1:8000/health        # должно вернуть "yoomoney webhook alive"
```

Если не слушает — поднять и снять лимит перезапусков (systemd сдаётся
после пяти падений за десять секунд и молча перестаёт пробовать):

```bash
systemctl reset-failed kie-webhook
systemctl enable --now kie-webhook
```

Чтобы это не повторялось, в юнитах стоит `StartLimitIntervalSec=0`, а
`kie-healthcheck.timer` раз в две минуты проверяет порт и поднимает
упавшее. Подробнее — в `deploy/README.md`, раздел «Чтобы поднималось само».

## Очередь на стороне сайта

Уведомления в формате бота приходят на сайт и пересылаются сюда, на
порт 8000. Раньше, если переслать не удавалось, уведомление терялось:
в журнале оставалась строка `cURL error 7: Failed to connect ... port
8000` — и всё. Теперь такое уведомление встаёт в очередь и пересылается
повторно (10 минут, полчаса, час, 3 часа, 6, 12, дальше раз в сутки,
две недели). То есть после подъёма приёмника накопленные пополнения
доедут сами.

Очередь видна в админке сайта: **Genius Sounds → Платежи**, блок
«Не доставлено боту». Платежи, пришедшие до появления очереди, в ней не
лежат — их добирают руками через «Правку баланса вручную» на той же
странице.

## Что настроить в ЮMoney

1. Личный кабинет ЮMoney → **Настройки** → **Уведомления о переводах**
   (https://yoomoney.ru/transfer/myservices/http-notification).
2. Включить HTTP-уведомления и указать **публичный адрес** обработчика.
3. Скопировать **секретное слово** и вписать в `.env`:
   ```
   YOOMONEY_NOTIFICATION_SECRET=<секрет из кабинета>
   ```
   Тогда бот проверяет подпись `sha1_hash`. Пусто — подпись не проверяется.

## Публичный адрес обработчика

Обработчик слушает `0.0.0.0:8000` (`/yoomoney-webhook`). ЮMoney должен
достучаться до него из интернета. Порт 8000 напрямую наружу закрыт, поэтому
надёжнее отдать колбэк через домен по HTTPS, проксируя на localhost:8000.

Пример для nginx на `genius-bot.ru`:

```nginx
location = /yoomoney-webhook {
    proxy_pass http://127.0.0.1:8000/yoomoney-webhook;
    proxy_set_header Host $host;
    proxy_set_header X-Real-IP $remote_addr;
}
```

После этого в ЮMoney указать адрес:
`https://genius-bot.ru/yoomoney-webhook`

Проверить снаружи:

```bash
curl -s https://genius-bot.ru/yoomoney-webhook   # "yoomoney webhook alive"
```

Либо, если проксировать через домен нельзя, открыть порт 8000 наружу
(`ufw allow 8000/tcp`) и указать в ЮMoney `http://<IP-сервера>:8000/yoomoney-webhook`.

## Дозачислить пропущенные платежи

Пока колбэк не работал, платежи остались в статусе `pending`. После починки
их можно найти и зачислить вручную:

```bash
cd /opt/kie_ai_bot && set -a; . ./.env; set +a
venv/bin/python - <<'PY'
from database import get_connection_context
with get_connection_context() as conn:
    with conn.cursor() as cur:
        cur.execute("SELECT label, telegram_id, amount, tokens, created_at "
                    "FROM payments WHERE status='pending' ORDER BY created_at DESC LIMIT 50")
        for r in cur.fetchall():
            print(r)
PY
```

Сверьте эти платежи с реальными поступлениями в кабинете ЮMoney и зачислите
подтверждённые (по одному, аккуратно):

```bash
venv/bin/python - <<'PY'
LABEL = "topup_XXX_YYY"   # подставить label подтверждённого платежа
from database import get_connection_context
with get_connection_context() as conn:
    with conn.cursor() as cur:
        cur.execute("SELECT telegram_id, tokens, status FROM payments WHERE label=%s", (LABEL,))
        p = cur.fetchone()
        assert p and p["status"] != "completed", p
        cur.execute("UPDATE users SET balance = balance + %s WHERE telegram_id=%s",
                    (p["tokens"], p["telegram_id"]))
        cur.execute("UPDATE payments SET status='completed' WHERE label=%s", (LABEL,))
    conn.commit()
print("зачислено")
PY
```
