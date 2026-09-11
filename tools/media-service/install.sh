#!/usr/bin/env bash
# Установка службы на чистый Ubuntu/Debian без Docker.
# Рядом со скриптом должны лежать app.py и requirements.txt.
set -euo pipefail

DIR=${DIR:-/opt/genius-media}
PORT=${PORT:-8099}
KEY=${MEDIA_API_KEY:-}
BASE=${PUBLIC_BASE:-}

if [ -z "$BASE" ]; then
    IP=$(hostname -I | awk '{print $1}')
    BASE="http://${IP}:${PORT}"
fi
# Схема обязательна: по этому адресу сайт скачивает готовые файлы.
case "$BASE" in
    http://*|https://*) ;;
    *) BASE="http://${BASE}" ;;
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
ExecStart=$DIR/venv/bin/uvicorn app:app --host 0.0.0.0 --port $PORT --workers 2
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
UNIT

systemctl daemon-reload
systemctl enable --now media-service
sleep 3

echo "== проверка"
curl -fsS "http://127.0.0.1:${PORT}/health" && echo
echo
echo "Служба слушает порт ${PORT}, файлы отдаются по адресу ${BASE}/files/"
echo "В настройках сайта укажите:"
echo "  Free TTS Endpoint URL: ${BASE}/tts"
echo "  Free TTS API Key:      ${KEY:-<пусто>}"
