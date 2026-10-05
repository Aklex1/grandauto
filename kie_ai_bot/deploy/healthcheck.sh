#!/usr/bin/env bash
# Сторож сервисов: раз в две минуты проверяет, что каждый порт жив, и
# поднимает упавшее.
#
# Restart=always в юните спасает только от падения процесса. Два случая он
# не ловит, и оба уже случались:
#   1) крэш-петля. systemd по умолчанию сдаётся после пяти перезапусков за
#      десять секунд и оставляет юнит мёртвым навсегда. Так приёмник
#      пополнений простоял с 26 сентября: процесс падал, пока была
#      недоступна база, systemd упёрся в лимит и больше не пробовал.
#   2) процесс жив, но не отвечает — gunicorn с подвисшими воркерами
#      systemd считает работающим.
# Сторож смотрит не на процесс, а на ответ по порту, поэтому видит оба.

set -u
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin

# unit:порт:путь. Пустой путь — проверяем только, что порт слушается
# (у бота и app-api нет health-ручки).
CHECKS=(
    "kie-webhook:8000:/health"
    "kie-app-webhook:8002:/health"
    "kie-bot:8010:"
    "app-api:8011:"
)

say() { logger -t kie-healthcheck -- "$1" 2>/dev/null || true; echo "$1"; }

alive() {
    local port="$1" path="$2"
    if [[ -n "$path" ]]; then
        curl -fsS --max-time 10 "http://127.0.0.1:${port}${path}" >/dev/null 2>&1
    else
        ss -ltn 2>/dev/null | grep -q ":${port} "
    fi
}

for check in "${CHECKS[@]}"; do
    unit="${check%%:*}"
    rest="${check#*:}"
    port="${rest%%:*}"
    path="${rest#*:}"

    # Юнита может не быть вовсе — тогда это не наша забота, а установка.
    if ! systemctl list-unit-files "${unit}.service" >/dev/null 2>&1 \
       || ! systemctl cat "${unit}.service" >/dev/null 2>&1; then
        say "${unit}: юнит не установлен, пропускаю"
        continue
    fi

    if alive "$port" "$path"; then
        continue
    fi

    # Даём второй шанс: перезапуск сервиса рядом или пик нагрузки мог
    # съесть одну проверку, перезапускать из-за этого незачем.
    sleep 5
    if alive "$port" "$path"; then
        continue
    fi

    state="$(systemctl is-active "${unit}.service" 2>/dev/null || true)"
    say "${unit}: порт ${port} не отвечает (состояние ${state:-unknown}) — перезапускаю"

    # reset-failed снимает лимит перезапусков, иначе systemd откажется
    # запускать юнит, упёршийся в start limit.
    systemctl reset-failed "${unit}.service" >/dev/null 2>&1 || true
    systemctl restart "${unit}.service" >/dev/null 2>&1 || true

    sleep 10
    if alive "$port" "$path"; then
        say "${unit}: поднялся"
    else
        say "${unit}: поднять не удалось, смотрите journalctl -u ${unit} -n 50"
    fi
done
