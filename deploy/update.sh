#!/usr/bin/env bash
# Обновление кода контент-завода до последней версии ветки.
set -euo pipefail
APP_DIR="${APP_DIR:-/opt/contentfactory}"
# Ветку берём ту, на которой стоит рабочая копия, иначе main.
BRANCH="${REPO_BRANCH:-$(git -C "$APP_DIR" rev-parse --abbrev-ref HEAD 2>/dev/null)}"
if [ -z "$BRANCH" ] || [ "$BRANCH" = "HEAD" ]; then
  BRANCH="main"
fi
git -C "$APP_DIR" fetch origin "$BRANCH"
git -C "$APP_DIR" reset --hard "origin/$BRANCH"
"$APP_DIR/venv/bin/pip" install --quiet -r "$APP_DIR/requirements.txt"
systemctl restart contentfactory
sleep 4
systemctl --no-pager --lines=10 status contentfactory
