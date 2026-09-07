#!/usr/bin/env bash
# Обновление кода контент-завода до последней версии ветки.
set -euo pipefail
APP_DIR="${APP_DIR:-/opt/contentfactory}"
BRANCH="${REPO_BRANCH:-claude/content-factory-server-0qz0yw}"
git -C "$APP_DIR" fetch origin "$BRANCH"
git -C "$APP_DIR" reset --hard "origin/$BRANCH"
"$APP_DIR/venv/bin/pip" install --quiet -r "$APP_DIR/requirements.txt"
systemctl restart contentfactory
sleep 4
systemctl --no-pager --lines=10 status contentfactory
