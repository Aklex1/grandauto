#!/usr/bin/env bash
# Запуск/перезапуск всех сервисов бота.
set -euo pipefail
APP_DIR="/opt/kie_ai_bot"
SRC_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
[[ $EUID -eq 0 ]] || { echo "Запускайте от root"; exit 1; }

if grep -q 'ЗАПОЛНИТЬ' "${APP_DIR}/.env"; then
    echo "[x] В ${APP_DIR}/.env остались незаполненные значения 'ЗАПОЛНИТЬ'."
    exit 1
fi

cd "${APP_DIR}"
echo "==> Проверка доступа к MySQL"
"${APP_DIR}/venv/bin/python" -c "import database; database.get_connection().close(); print('MySQL: OK')"

echo "==> Перезапуск сервисов"
systemctl enable kie-bot.service kie-webhook.service kie-app-webhook.service app-api.service >/dev/null
systemctl restart kie-bot.service kie-webhook.service kie-app-webhook.service app-api.service
sleep 3
bash "${SRC_DIR}/deploy/status.sh"
