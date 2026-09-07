#!/usr/bin/env bash
# Установка контент-завода на чистую Ubuntu 24.04 LTS.
# Запускать под root:  bash deploy/install.sh
set -euo pipefail

APP_DIR="${APP_DIR:-/opt/contentfactory}"
DATA_DIR="${DATA_DIR:-/var/lib/contentfactory}"
ENV_FILE="/etc/contentfactory.env"
SERVICE="contentfactory"
PORT="${CF_PORT:-80}"
REPO_URL="${REPO_URL:-https://github.com/aklex1/grandauto.git}"
REPO_BRANCH="${REPO_BRANCH:-claude/content-factory-server-0qz0yw}"

log() { printf '\n\033[1;36m==> %s\033[0m\n' "$*"; }

if [[ $EUID -ne 0 ]]; then echo "Запустите под root"; exit 1; fi

log "Устанавливаю системные пакеты"
export DEBIAN_FRONTEND=noninteractive
apt-get update -qq
apt-get install -y --no-install-recommends \
  python3 python3-venv python3-dev python3-pip \
  ffmpeg git curl ca-certificates fonts-dejavu-core \
  build-essential pkg-config ufw openssl

log "Готовлю каталоги"
mkdir -p "$APP_DIR" "$DATA_DIR"/{media,tmp,logs,models}

if [[ -d "$APP_DIR/.git" ]]; then
  log "Обновляю код из репозитория"
  git -C "$APP_DIR" fetch origin "$REPO_BRANCH"
  git -C "$APP_DIR" checkout "$REPO_BRANCH"
  git -C "$APP_DIR" reset --hard "origin/$REPO_BRANCH"
elif [[ -f "$(dirname "$0")/../requirements.txt" ]] \
     && [[ "$(cd "$(dirname "$0")/.." && pwd)" != "$APP_DIR" ]]; then
  log "Копирую код из текущей папки"
  cp -r "$(dirname "$0")/.."/. "$APP_DIR"/
else
  log "Клонирую репозиторий"
  git clone --branch "$REPO_BRANCH" "$REPO_URL" "$APP_DIR"
fi

log "Создаю виртуальное окружение"
python3 -m venv "$APP_DIR/venv"
"$APP_DIR/venv/bin/pip" install --quiet --upgrade pip wheel
"$APP_DIR/venv/bin/pip" install --quiet -r "$APP_DIR/requirements.txt"

if [[ ! -f "$ENV_FILE" ]]; then
  log "Создаю $ENV_FILE"
  SECRET="$(openssl rand -hex 32)"
  ADMIN_PASS="${CF_ADMIN_PASSWORD:-$(openssl rand -base64 12 | tr -d '/+=' | head -c 14)}"
  cat > "$ENV_FILE" <<ENV
CF_SECRET_KEY=$SECRET
CF_ADMIN_USER=${CF_ADMIN_USER:-admin}
CF_ADMIN_PASSWORD=$ADMIN_PASS
KIE_API_KEY=${KIE_API_KEY:-}
CF_DATA_DIR=$DATA_DIR
CF_HOST=0.0.0.0
CF_PORT=$PORT
CF_WORKERS=2
CF_WHISPER_MODEL=small
CF_WHISPER_DEVICE=cpu
CF_WHISPER_COMPUTE=int8
CF_WHISPER_ENABLED=1
ENV
  chmod 600 "$ENV_FILE"
  echo "СГЕНЕРИРОВАН ПАРОЛЬ АДМИНИСТРАТОРА: $ADMIN_PASS"
else
  log "$ENV_FILE уже существует — оставляю как есть"
fi

log "Прогреваю модель Whisper (может занять пару минут)"
set -a; . "$ENV_FILE"; set +a
"$APP_DIR/venv/bin/python" - <<'PY' || echo "Whisper скачаем при первом ролике"
import os
from faster_whisper import WhisperModel
WhisperModel(os.environ.get("CF_WHISPER_MODEL", "small"),
             device=os.environ.get("CF_WHISPER_DEVICE", "cpu"),
             compute_type=os.environ.get("CF_WHISPER_COMPUTE", "int8"),
             download_root=os.path.join(os.environ.get("CF_DATA_DIR", "/var/lib/contentfactory"), "models"))
print("Whisper готов")
PY

log "Ставлю systemd-сервис"
cat > "/etc/systemd/system/${SERVICE}.service" <<UNIT
[Unit]
Description=Content Factory (KIE) — веб-панель и конвейер производства роликов
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
User=root
WorkingDirectory=$APP_DIR
EnvironmentFile=$ENV_FILE
ExecStart=$APP_DIR/venv/bin/python -m uvicorn app.main:app --host \${CF_HOST} --port \${CF_PORT} --timeout-keep-alive 75
Restart=always
RestartSec=5
KillSignal=SIGINT
TimeoutStopSec=30
LimitNOFILE=65535
StandardOutput=append:$DATA_DIR/logs/app.log
StandardError=append:$DATA_DIR/logs/app.log

[Install]
WantedBy=multi-user.target
UNIT

log "Настраиваю ротацию логов"
cat > /etc/logrotate.d/contentfactory <<ROT
$DATA_DIR/logs/*.log {
    daily
    rotate 14
    compress
    missingok
    notifempty
    copytruncate
}
ROT

log "Открываю порты в ufw (если включён)"
ufw allow "$PORT"/tcp >/dev/null 2>&1 || true
ufw allow 22/tcp >/dev/null 2>&1 || true

log "Запускаю сервис"
systemctl daemon-reload
systemctl enable "$SERVICE" >/dev/null
systemctl restart "$SERVICE"
sleep 6
systemctl --no-pager --lines=15 status "$SERVICE" || true

IP="$(curl -s --max-time 5 ifconfig.me || hostname -I | awk '{print $1}')"
log "Готово"
echo "Панель:  http://$IP:$PORT"
echo "Логин:   $(grep CF_ADMIN_USER "$ENV_FILE" | cut -d= -f2)"
echo "Пароль:  $(grep CF_ADMIN_PASSWORD "$ENV_FILE" | cut -d= -f2)"
echo "Данные:  $DATA_DIR"
echo "Логи:    journalctl -u $SERVICE -f   или   tail -f $DATA_DIR/logs/app.log"
