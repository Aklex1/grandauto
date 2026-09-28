#!/usr/bin/env bash
# Хвост логов всех сервисов сразу: bash deploy/logs.sh [строк]
N="${1:-100}"
journalctl -n "$N" -f -u kie-bot -u kie-webhook -u kie-app-webhook -u app-api
