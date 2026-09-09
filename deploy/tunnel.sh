#!/usr/bin/env bash
# Туннель Cloudflare: делает панель доступной, когда прямое соединение с сервером
# не проходит (блокировка портов у провайдера, корпоративный фильтр, антивирус).
#
# Как это работает: сервер САМ устанавливает исходящее соединение с Cloudflare,
# входящие порты не нужны вовсе. Браузер обращается к адресам Cloudflare, а не к
# серверу — то есть маршрут, на котором всё ломалось, из схемы уходит.
#
# Использование:
#   bash deploy/tunnel.sh              # быстрый туннель, без регистрации
#   bash deploy/tunnel.sh --url        # показать текущий адрес туннеля
#   bash deploy/tunnel.sh --token XXX  # постоянный туннель по токену Cloudflare
#   bash deploy/tunnel.sh --stop       # остановить
set -euo pipefail

APP_PORT="${APP_PORT:-80}"
LOG=/var/log/cloudflared.log
UNIT=/etc/systemd/system/cf-tunnel.service

if [ "$(id -u)" -ne 0 ]; then
  echo "Запускать от root" >&2
  exit 1
fi

show_url() {
  # Быстрый туннель печатает выданный адрес в журнал при запуске.
  local url
  url=$(grep -ho 'https://[a-z0-9-]*\.trycloudflare\.com' "$LOG" 2>/dev/null | tail -1 || true)
  if [ -n "$url" ]; then
    echo "$url"
    return 0
  fi
  return 1
}

case "${1:-}" in
  --url)
    if url=$(show_url); then
      echo "Панель доступна по адресу:"
      echo "  $url"
    else
      echo "Адрес пока не выдан. Посмотрите журнал: tail -30 $LOG" >&2
      exit 1
    fi
    exit 0
    ;;
  --stop)
    systemctl stop cf-tunnel 2>/dev/null || true
    systemctl disable cf-tunnel 2>/dev/null || true
    echo "Туннель остановлен."
    exit 0
    ;;
esac

# --------------------------------------------------------------- установка
if ! command -v cloudflared >/dev/null 2>&1; then
  echo "==> Ставлю cloudflared"
  case "$(uname -m)" in
    x86_64)  ARCH=amd64 ;;
    aarch64) ARCH=arm64 ;;
    *) echo "Неизвестная архитектура $(uname -m)" >&2; exit 1 ;;
  esac
  TMP=$(mktemp -d)
  curl -fsSL -o "$TMP/cloudflared.deb" \
    "https://github.com/cloudflare/cloudflared/releases/latest/download/cloudflared-linux-${ARCH}.deb"
  dpkg -i "$TMP/cloudflared.deb" >/dev/null
  rm -rf "$TMP"
fi
echo "==> cloudflared $(cloudflared --version 2>/dev/null | head -1)"

# ------------------------------------------------- постоянный туннель по токену
if [ "${1:-}" = "--token" ]; then
  TOKEN="${2:-}"
  if [ -z "$TOKEN" ]; then
    echo "Укажите токен: bash deploy/tunnel.sh --token XXXXX" >&2
    exit 1
  fi
  systemctl stop cf-tunnel 2>/dev/null || true
  rm -f "$UNIT"
  systemctl daemon-reload
  cloudflared service install "$TOKEN"
  systemctl restart cloudflared
  echo
  echo "Постоянный туннель запущен."
  echo "Адрес задаётся в панели Cloudflare (Zero Trust → Networks → Tunnels),"
  echo "там же в маршруте укажите http://127.0.0.1:${APP_PORT}"
  exit 0
fi

# ------------------------------------------------------------ быстрый туннель
echo "==> Запускаю быстрый туннель на порт ${APP_PORT}"
: > "$LOG"
cat > "$UNIT" <<UNITEOF
[Unit]
Description=Cloudflare tunnel for Content Factory panel
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
ExecStart=/usr/bin/cloudflared tunnel --no-autoupdate --logfile ${LOG} \\
          --url http://127.0.0.1:${APP_PORT}
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
UNITEOF

systemctl daemon-reload
systemctl enable cf-tunnel >/dev/null 2>&1 || true
systemctl restart cf-tunnel

echo "==> Жду адрес от Cloudflare"
for _ in $(seq 1 30); do
  if url=$(show_url); then
    echo
    echo "Готово. Панель доступна по адресу:"
    echo "  $url"
    echo
    echo "Адрес временный: он меняется при каждом перезапуске туннеля."
    echo "Посмотреть текущий:  bash $(dirname "$0")/tunnel.sh --url"
    echo "Остановить туннель:  bash $(dirname "$0")/tunnel.sh --stop"
    exit 0
  fi
  sleep 2
done

echo "Адрес за минуту не появился. Журнал:" >&2
tail -20 "$LOG" >&2
exit 1
