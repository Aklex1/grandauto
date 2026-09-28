#!/usr/bin/env bash
# Состояние сервисов, портов и внешних зависимостей.
APP_DIR="/opt/kie_ai_bot"
SERVICES=(kie-bot kie-webhook kie-app-webhook app-api)

echo "==================== СЕРВИСЫ ===================="
for s in "${SERVICES[@]}"; do
    state=$(systemctl is-active "${s}.service" 2>/dev/null || true)
    if [[ "$state" == "active" ]]; then
        printf '  \033[1;32m●\033[0m %-16s %s\n' "$s" "$state"
    else
        printf '  \033[1;31m●\033[0m %-16s %s\n' "$s" "${state:-not-installed}"
    fi
done

echo
echo "==================== ПОРТЫ ======================"
for p in 8000 8002 8010 8011; do
    if ss -ltn 2>/dev/null | grep -q ":${p} "; then
        printf '  \033[1;32mOK\033[0m   :%s слушается\n' "$p"
    else
        printf '  \033[1;31mNO\033[0m   :%s не слушается\n' "$p"
    fi
done

echo
echo "==================== ПРОВЕРКИ ==================="
if redis-cli ping >/dev/null 2>&1; then echo "  OK   Redis отвечает"; else echo "  NO   Redis не отвечает"; fi

if [[ -x "${APP_DIR}/venv/bin/python" ]]; then
    (cd "${APP_DIR}" && "${APP_DIR}/venv/bin/python" - <<'PY'
try:
    import database
    database.get_connection().close()
    print("  OK   MySQL доступен")
except Exception as e:
    print(f"  NO   MySQL недоступен: {e}")

try:
    import urllib.request, json, config
    with urllib.request.urlopen(
        f"https://api.telegram.org/bot{config.TELEGRAM_BOT_TOKEN}/getMe", timeout=15
    ) as r:
        d = json.load(r)
    print(f"  OK   Telegram API: @{d['result']['username']}")
except Exception as e:
    print(f"  NO   Telegram API: {e}")

try:
    import config
    # Без членства в группе обсуждений промпты в комментарии не уйдут
    try:
        import json, urllib.request, autopost, config as _c
        chat = autopost.DISCUSSION_CHAT_ID
        if chat:
            url = (f"https://api.telegram.org/bot{_c.TELEGRAM_BOT_TOKEN}/getChatMember"
                   f"?chat_id={chat}&user_id={_c.TELEGRAM_BOT_TOKEN.split(':')[0]}")
            with urllib.request.urlopen(url, timeout=15) as r:
                status = json.load(r)["result"]["status"]
            if status in ("left", "kicked"):
                print(f"  NO   Бот НЕ в группе обсуждений {chat} — промпты в комментарии не уйдут")
            else:
                print(f"  OK   Бот в группе обсуждений ({status})")
        else:
            print("  ..   AUTOPOST_DISCUSSION_CHAT_ID не задан")
    except Exception as e:
        print(f"  ..   Группа обсуждений: проверить не вышло ({e})")

    print(f"  ..   CALLBACK_BASE_URL = {config.CALLBACK_BASE_URL}")
    print(f"  ..   APP_API_BASE_URL  = {config.APP_API_BASE_URL}")
except Exception as e:
    print(f"  NO   config: {e}")
PY
    )
fi

IP=$(curl -s --max-time 10 https://api.ipify.org 2>/dev/null || echo "?")
echo "  ..   Внешний IP сервера: ${IP}"

echo
echo "Логи:  journalctl -u kie-bot -f   |   journalctl -u kie-webhook -f"
