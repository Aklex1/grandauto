#!/usr/bin/env bash
# Публикует панель по HTTPS на порту 443.
#
# Зачем: во многих корпоративных сетях исходящий порт 80 закрыт, и панель просто
# не открывается (curl показывает "Timed out"). Порт 443 разрешён почти везде.
# Заодно перестаёт ходить открытым текстом пароль администратора.
#
# Приложение продолжает слушать порт 80, nginx занимает только 443 и проксирует
# запросы внутрь — службу contentfactory трогать не требуется.
#
# Использование:
#   bash deploy/enable_https.sh                 # самоподписанный сертификат
#   bash deploy/enable_https.sh techscore.ru    # сертификат Let's Encrypt
set -euo pipefail

DOMAIN="${1:-}"
APP_PORT="${APP_PORT:-80}"
DATA_DIR="${CF_DATA_DIR:-/var/lib/contentfactory}"
ACME_DIR="$DATA_DIR/acme"           # его отдаёт приложение по /.well-known/acme-challenge
ACME_ROOT="$DATA_DIR/acmeroot"      # каталог, который certbot считает корнем сайта
CERT_DIR=/etc/ssl/contentfactory

if [ "$(id -u)" -ne 0 ]; then
  echo "Запускать от root" >&2
  exit 1
fi

echo "==> Ставлю nginx"
export DEBIAN_FRONTEND=noninteractive
apt-get update -qq
apt-get install -y -qq nginx >/dev/null

CRT="" ; KEY=""

if [ -n "$DOMAIN" ]; then
  echo "==> Домен $DOMAIN: беру сертификат Let's Encrypt"
  apt-get install -y -qq certbot >/dev/null

  # Проверка владения доменом идёт по порту 80, а там стоит приложение, не nginx.
  # Поэтому режим webroot: certbot кладёт файл в каталог, который приложение
  # отдаёт по /.well-known/acme-challenge. Режим --nginx здесь давал 404.
  mkdir -p "$ACME_DIR" "$ACME_ROOT/.well-known"
  ln -sfn "$ACME_DIR" "$ACME_ROOT/.well-known/acme-challenge"

  echo "    проверяю, что путь проверки доступен снаружи"
  probe="cf-probe-$$"
  echo ok > "$ACME_DIR/$probe"
  code=$(curl -s -o /dev/null -w '%{http_code}' --max-time 20 \
         "http://$DOMAIN/.well-known/acme-challenge/$probe" || echo 000)
  rm -f "$ACME_DIR/$probe"
  if [ "$code" != "200" ]; then
    echo "    путь проверки отвечает $code вместо 200." >&2
    echo "    Обычно это значит, что служба contentfactory не обновлена —" >&2
    echo "    сначала выполните: bash $(dirname "$0")/update.sh" >&2
    echo "    Продолжаю с самоподписанным сертификатом." >&2
    DOMAIN=""
  fi
fi

if [ -n "$DOMAIN" ]; then
  if certbot certonly --webroot -w "$ACME_ROOT" -d "$DOMAIN" \
        --non-interactive --agree-tos --register-unsafely-without-email \
        --deploy-hook "systemctl reload nginx"; then
    CRT="/etc/letsencrypt/live/$DOMAIN/fullchain.pem"
    KEY="/etc/letsencrypt/live/$DOMAIN/privkey.pem"
    SERVER_NAME="$DOMAIN"
  else
    echo "Certbot не смог выпустить сертификат — беру самоподписанный" >&2
    DOMAIN=""
  fi
fi

if [ -z "$CRT" ]; then
  echo "==> Делаю самоподписанный сертификат на 10 лет"
  mkdir -p "$CERT_DIR"
  if [ ! -f "$CERT_DIR/panel.crt" ]; then
    openssl req -x509 -nodes -newkey rsa:2048 -days 3650 \
      -keyout "$CERT_DIR/panel.key" -out "$CERT_DIR/panel.crt" \
      -subj "/CN=contentfactory" >/dev/null 2>&1
    chmod 600 "$CERT_DIR/panel.key"
  fi
  CRT="$CERT_DIR/panel.crt"
  KEY="$CERT_DIR/panel.key"
  SERVER_NAME="_"
fi

echo "==> Пишу конфигурацию nginx"
cat > /etc/nginx/sites-available/contentfactory <<NGINX
server {
    listen 443 ssl;
    listen [::]:443 ssl;
    server_name ${SERVER_NAME};

    ssl_certificate     ${CRT};
    ssl_certificate_key ${KEY};
    ssl_protocols       TLSv1.2 TLSv1.3;

    # Футажи заливают файлами в сотни мегабайт — лимит снят.
    client_max_body_size 0;

    # Сборка ролика долгая: короткий таймаут рвал бы страницу очереди.
    proxy_read_timeout    600s;
    proxy_send_timeout    600s;
    proxy_connect_timeout 60s;

    location / {
        proxy_pass http://127.0.0.1:${APP_PORT};
        proxy_http_version 1.1;
        proxy_set_header Host              \$host;
        proxy_set_header X-Real-IP         \$remote_addr;
        proxy_set_header X-Forwarded-For   \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;
        # Перемотка видео в браузере идёт запросами по диапазонам —
        # буферизация ответа ломала бы её.
        proxy_buffering off;
    }
}
NGINX

ln -sf /etc/nginx/sites-available/contentfactory /etc/nginx/sites-enabled/contentfactory
rm -f /etc/nginx/sites-enabled/default

echo "==> Проверяю конфигурацию"
nginx -t

echo "==> Перезапускаю nginx"
systemctl enable nginx >/dev/null 2>&1 || true
systemctl restart nginx

if command -v ufw >/dev/null 2>&1 && ufw status 2>/dev/null | grep -q "Status: active"; then
  ufw allow 443/tcp >/dev/null 2>&1 || true
fi

echo
echo "Готово. Панель доступна по адресу:"
if [ -n "$DOMAIN" ]; then
  echo "  https://${DOMAIN}/"
  echo "  Сертификат настоящий, предупреждений не будет."
  echo "  Обновляется автоматически, nginx перезагружается сам."
else
  IP=$(hostname -I 2>/dev/null | awk '{print $1}')
  echo "  https://${IP:-<адрес сервера>}/"
  echo
  echo "Сертификат самоподписанный — при первом заходе браузер покажет"
  echo "предупреждение. Нажмите «Дополнительно» и «Перейти на сайт»."
fi
