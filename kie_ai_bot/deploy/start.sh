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
SERVICES=(kie-bot kie-webhook kie-app-webhook app-api)

# reset-failed обязателен. Юнит, упёршийся в лимит перезапусков, на
# restart отвечает отказом «start request repeated too quickly», и из-за
# set -e скрипт обрывался на этой строке, не дойдя до остальных сервисов
# и до вывода состояния. Именно так приёмник пополнений и не поднялся.
systemctl reset-failed "${SERVICES[@]}" >/dev/null 2>&1 || true
systemctl enable "${SERVICES[@]}" >/dev/null 2>&1 || true

# Каждый сервис поднимаем отдельно: упавший не должен мешать остальным.
for s in "${SERVICES[@]}"; do
    if systemctl restart "${s}.service" 2>/dev/null; then
        echo "    ${s}: запущен"
    else
        echo "    ${s}: НЕ ЗАПУСТИЛСЯ — journalctl -u ${s} -n 40 --no-pager"
    fi
done

systemctl enable --now kie-healthcheck.timer >/dev/null 2>&1 || true
sleep 3
bash "${SRC_DIR}/deploy/status.sh"
