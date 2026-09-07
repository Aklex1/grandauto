#!/usr/bin/env bash
# ============================================================================
# Установка kie_ai_bot на чистый Ubuntu 22.04/24.04
#
#   sudo bash deploy/install.sh
#
# Скрипт идемпотентен: повторный запуск обновляет код и перезапускает сервисы.
# Файл /opt/kie_ai_bot/.env НЕ перезаписывается, если уже существует.
# ============================================================================
set -euo pipefail

APP_DIR="/opt/kie_ai_bot"
SRC_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PY="${APP_DIR}/venv/bin/python"

log()  { printf '\n\033[1;32m==> %s\033[0m\n' "$*"; }
warn() { printf '\033[1;33m[!] %s\033[0m\n' "$*"; }
die()  { printf '\033[1;31m[x] %s\033[0m\n' "$*" >&2; exit 1; }

[[ $EUID -eq 0 ]] || die "Запускайте от root: sudo bash deploy/install.sh"

log "1/8 Системные пакеты"
export DEBIAN_FRONTEND=noninteractive
apt-get update -qq
apt-get install -y -qq python3 python3-venv python3-pip redis-server ufw curl ca-certificates

log "2/8 Redis"
systemctl enable --now redis-server
redis-cli ping >/dev/null 2>&1 && echo "Redis: OK" || warn "Redis не отвечает на PING"

log "3/8 Код в ${APP_DIR}"
mkdir -p "${APP_DIR}"
# Копируем исходники, не трогая .env, venv, загрузки и БД
for f in *.py requirements.txt prometheus.yml; do
    [[ -e "${SRC_DIR}/${f}" ]] && cp -f "${SRC_DIR}"/${f} "${APP_DIR}/" || true
done
cp -rf "${SRC_DIR}/middlewares" "${APP_DIR}/"
cp -rf "${SRC_DIR}/models"      "${APP_DIR}/"
mkdir -p "${APP_DIR}/app_uploads"
mkdir -p "${APP_DIR}/autopost_media"
# Каталог для референсного фото автопостинга
mkdir -p /opt/refer
find "${APP_DIR}" -name '__pycache__' -type d -prune -exec rm -rf {} + 2>/dev/null || true

log "4/8 Виртуальное окружение и зависимости"
[[ -d "${APP_DIR}/venv" ]] || python3 -m venv "${APP_DIR}/venv"
"${APP_DIR}/venv/bin/pip" install --upgrade -q pip wheel
"${APP_DIR}/venv/bin/pip" install -q -r "${APP_DIR}/requirements.txt"
echo "Python: $(${PY} --version)"

log "5/8 Конфигурация (.env)"
if [[ -f "${APP_DIR}/.env" ]]; then
    echo ".env уже существует — оставляем как есть."
else
    cp "${SRC_DIR}/deploy/env.example" "${APP_DIR}/.env"
    chmod 600 "${APP_DIR}/.env"
    warn "Создан ${APP_DIR}/.env из шаблона. ЗАПОЛНИТЕ его перед запуском!"
fi
# JWT_SECRET генерируем один раз, если он ещё плейсхолдер
if grep -q '^JWT_SECRET=СГЕНЕРИРУЕТСЯ_АВТОМАТИЧЕСКИ$' "${APP_DIR}/.env"; then
    SECRET="$(head -c 32 /dev/urandom | base64 | tr -d '=+/' | cut -c1-40)"
    sed -i "s|^JWT_SECRET=.*|JWT_SECRET=${SECRET}|" "${APP_DIR}/.env"
    echo "JWT_SECRET сгенерирован."
fi

log "6/8 systemd-юниты"
install -m 644 "${SRC_DIR}/deploy/systemd/"*.service /etc/systemd/system/
systemctl daemon-reload

log "7/8 Firewall (порты 22, 8000, 8002, 8010, 8011)"
if ufw status | grep -q '^Status: active'; then
    for p in 22 8000 8002 8010 8011; do ufw allow "${p}/tcp" >/dev/null; done
    echo "Правила ufw обновлены."
else
    echo "ufw неактивен — правила не требуются (порты открыты)."
fi

log "8/8 Проверка конфигурации и запуск"
if grep -q 'ЗАПОЛНИТЬ' "${APP_DIR}/.env"; then
    warn "В ${APP_DIR}/.env остались значения 'ЗАПОЛНИТЬ' — сервисы НЕ запускаются."
    warn "Отредактируйте файл и выполните: bash ${SRC_DIR}/deploy/start.sh"
    exit 0
fi

cd "${APP_DIR}"
if ! "${PY}" -c "import config, database; database.get_connection().close(); print('MySQL: OK')"; then
    die "Нет доступа к MySQL. Проверьте DB_* в .env и добавьте IP этого сервера в whitelist в панели хостинга."
fi

systemctl enable --now kie-bot.service kie-webhook.service kie-app-webhook.service app-api.service
sleep 3
bash "${SRC_DIR}/deploy/status.sh" || true

log "Готово."
