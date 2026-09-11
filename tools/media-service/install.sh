#!/usr/bin/env bash
# Установка службы на чистый Ubuntu/Debian без Docker.
# Рядом со скриптом должны лежать app.py и requirements.txt.
set -euo pipefail

DIR=${DIR:-/opt/genius-media}
PORT=${PORT:-8099}
KEY=${MEDIA_API_KEY:-}
BASE=${PUBLIC_BASE:-}

# Подсказку из инструкции легко скопировать вместе с текстом — тогда ключом
# становится слово «придумайте-ключ». Выдаём настоящий и печатаем его в конце.
if [ -z "$KEY" ] || [ "$KEY" = "придумайте-ключ" ]; then
    KEY=$(head -c 24 /dev/urandom | od -An -tx1 | tr -d ' \n')
    echo "== ключ доступа не задан, выдан новый"
fi

if [ -z "$BASE" ]; then
    IP=$(hostname -I | awk '{print $1}')
    BASE="http://${IP}:${PORT}"
fi
# Схема обязательна: по этому адресу сайт скачивает готовые файлы.
case "$BASE" in
    http://*|https://*) ;;
    *) BASE="http://${BASE}" ;;
esac

SELF_IP=$(hostname -I | awk '{print $1}')
case "$BASE" in
    *"$SELF_IP"*) ;;
    *) echo "!! PUBLIC_BASE указывает не на этот сервер ($SELF_IP). Файлы будут отдаваться по адресу $BASE — проверьте, что он ведёт сюда." ;;
esac

echo "== ставлю зависимости системы"
apt-get update -qq
apt-get install -y -qq python3-venv python3-pip ffmpeg curl >/dev/null

echo "== раскладываю файлы в $DIR"
mkdir -p "$DIR/files"
cp app.py requirements.txt "$DIR/"

echo "== собираю окружение"
python3 -m venv "$DIR/venv"
"$DIR/venv/bin/pip" install -q --upgrade pip
"$DIR/venv/bin/pip" install -q -r "$DIR/requirements.txt"

echo "== описываю службу"
cat > /etc/systemd/system/media-service.service <<UNIT
[Unit]
Description=Genius media service (бесплатная озвучка и звук из роликов)
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
WorkingDirectory=$DIR
Environment="MEDIA_API_KEY=$KEY"
Environment="PUBLIC_BASE=$BASE"
Environment="FILES_DIR=$DIR/files"
Environment="KEEP_HOURS=24"
Environment="MAX_MINUTES=90"
Environment="YOOMONEY_SECRET=${YOOMONEY_SECRET:-}"
Environment="SITE_WEBHOOK=${SITE_WEBHOOK:-https://genius-bot.ru/wp-json/genius/v1/yoomoney}"
Environment="BOT_WEBHOOK=${BOT_WEBHOOK:-http://127.0.0.1:8000/yoomoney-webhook}"
Environment="PAY_LOG=$DIR/payments.log"
ExecStart=$DIR/venv/bin/uvicorn app:app --host 0.0.0.0 --port $PORT --workers 2
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
UNIT

systemctl daemon-reload
systemctl enable --now media-service
sleep 3

# Порт нестандартный, и на свежем сервере он чаще всего закрыт межсетевым
# экраном — сайт тогда получает таймаут вместо ответа. Открываем тот,
# что найден; облачную группу безопасности всё равно придётся настроить
# в панели провайдера.
if command -v ufw >/dev/null 2>&1 && ufw status 2>/dev/null | grep -qi '^Status: active'; then
    echo "== открываю порт $PORT в ufw"
    ufw allow "${PORT}/tcp" >/dev/null 2>&1 || true
fi
if command -v firewall-cmd >/dev/null 2>&1 && firewall-cmd --state >/dev/null 2>&1; then
    echo "== открываю порт $PORT в firewalld"
    firewall-cmd --permanent --add-port="${PORT}/tcp" >/dev/null 2>&1 || true
    firewall-cmd --reload >/dev/null 2>&1 || true
fi

echo "== проверка"
curl -fsS "http://127.0.0.1:${PORT}/health" && echo
echo
echo "Служба слушает порт ${PORT}, файлы отдаются по адресу ${BASE}/files/"
echo "Адрес для уведомлений ЮMoney: ${BASE}/yoomoney-webhook"
echo "В настройках сайта укажите:"
echo "  Free TTS Endpoint URL: ${BASE}/tts"
echo "  Free TTS API Key:      ${KEY:-<пусто>}"
