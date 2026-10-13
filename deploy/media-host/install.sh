#!/usr/bin/env bash
#
# Раздача файлов каталога звуков с этого сервера.
#
# Зачем: на шаред-хостинге сайта кончилась квота, и сервер перестал
# сохранять файлы вообще — вместе с ним встали все микросервисы с
# загрузкой. Семнадцать с половиной гигабайт каталога звуков лежат там
# мёртвым грузом и место не отпустят. Здесь диск на 80 ГБ и он почти
# пустой.
#
# Что делает: ставит nginx, отдаёт /srv/media по https на поддомене и
# получает сертификат. Приложение, которое сейчас занимает 80-й порт,
# НЕ трогает — только предупреждает: Let's Encrypt без 80-го порта
# сертификат не выдаст, и это решение за человеком.
#
#   bash install.sh media.genius-bot.ru
#
set -euo pipefail

HOST="${1:-}"
ROOT="/srv/media"

if [ -z "$HOST" ]; then
    echo "Укажите поддомен: bash install.sh media.genius-bot.ru" >&2
    exit 1
fi

echo "== Проверяю, что поддомен ведёт сюда"
MY_IP="$(curl -s -4 --max-time 15 ifconfig.me || true)"
DNS_IP="$(getent hosts "$HOST" | awk '{print $1}' | head -1 || true)"
echo "   этот сервер: ${MY_IP:-неизвестно}"
echo "   $HOST -> ${DNS_IP:-записи нет}"
if [ -z "$DNS_IP" ]; then
    echo "   Запись A ещё не создана или не разошлась. Сертификат получить не выйдет." >&2
    echo "   Создайте A-запись $HOST -> $MY_IP и запустите снова." >&2
    exit 1
fi

echo "== Кто занимает 80-й порт"
HOLDER="$(ss -lptn 'sport = :80' 2>/dev/null | awk 'NR>1{print $NF}' | head -1 || true)"
echo "   ${HOLDER:-свободен}"
if [ -n "$HOLDER" ] && ! echo "$HOLDER" | grep -q nginx; then
    cat >&2 <<'WARN'

   На 80-м порту сидит не nginx, а другое приложение (панель на uvicorn).
   Let's Encrypt проверяет владение доменом по 80-му порту, поэтому
   дальше нужен выбор, и делать его за вас скрипт не станет:

     1) Перевести панель за nginx: панель слушает 127.0.0.1:8080,
        nginx на 80/443 проксирует её по её домену, а по медиа-домену
        отдаёт файлы. Правильный путь, но правится unit панели.
     2) Остановить панель на минуту, получить сертификат,
        поднять обратно. Продлевать раз в два месяца так же.

   Выберите и скажите — допишу под выбранное.
WARN
    exit 2
fi

echo "== Ставлю nginx и certbot"
export DEBIAN_FRONTEND=noninteractive
apt-get update -qq
apt-get install -y -qq nginx certbot python3-certbot-nginx >/dev/null

mkdir -p "$ROOT"
chown -R www-data:www-data "$ROOT"

echo "== Настраиваю раздачу"
cat > /etc/nginx/sites-available/media.conf <<NGINX
server {
    listen 80;
    server_name $HOST;
    root $ROOT;

    # Отдаём только файлы и только на чтение: это витрина, а не хранилище
    # с записью. Листинг закрыт, чтобы каталог нельзя было выкачать целиком.
    autoindex off;
    location / {
        try_files \$uri =404;
        add_header Access-Control-Allow-Origin "*" always;
        add_header Cache-Control "public, max-age=2592000, immutable" always;
    }
    location = /healthz { return 200 "ok\n"; add_header Content-Type text/plain; }
}
NGINX
ln -sf /etc/nginx/sites-available/media.conf /etc/nginx/sites-enabled/media.conf
nginx -t
systemctl reload nginx

echo "== Получаю сертификат"
certbot --nginx -d "$HOST" --agree-tos --register-unsafely-without-email --non-interactive --redirect

echo
echo "Готово. Проверка: curl -I https://$HOST/healthz"
echo "Файлы класть в $ROOT — дальше запускайте pull.sh"
