#!/usr/bin/env bash
# Собирает архив для распаковки в корень сайта: /genius-bot.ru/public_html/
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
BUILD="$(mktemp -d)"
BASE="https://genius-bot.ru/wp-content/uploads/genius-assistants"

mkdir -p "$BUILD"/wp-content/{mu-plugins,plugins/kie-tts-wp/modules,uploads/genius-assistants} "$BUILD"/stranitsy
cp -r "$ROOT/wordpress/kie-tts-wp/modules/assistants" "$BUILD/wp-content/plugins/kie-tts-wp/modules/"
cp "$ROOT"/wordpress/mu-plugins/*.php "$BUILD/wp-content/mu-plugins/"
cp "$ROOT"/content/images/*.webp "$BUILD/wp-content/uploads/genius-assistants/"

i=1
for page in home uchitel ucheba yurist biznes header; do
  sed "s#{IMG}#$BASE#g" "$ROOT/content/landings/$page.html" \
    > "$(printf '%s/stranitsy/%02d-%s.html' "$BUILD" "$i" "$page")"
  i=$((i + 1))
done

cp "$ROOT/docs/УСТАНОВКА.txt" "$BUILD/" 2>/dev/null || true
( cd "$BUILD" && zip -qr "$ROOT/genius-assistants-deploy.zip" . )
rm -rf "$BUILD"
echo "готово: $ROOT/genius-assistants-deploy.zip"
