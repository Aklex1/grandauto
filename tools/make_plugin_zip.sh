#!/usr/bin/env bash
# Собирает устанавливаемый через wp-admin плагин genius-assistants.zip
# из модуля wordpress/kie-tts-wp/modules/assistants. Версия берётся из bootstrap.php.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
SRC="$ROOT/wordpress/kie-tts-wp/modules/assistants"
OUT="$ROOT/genius-assistants-plugin.zip"

VER="$(grep -oE "GA_VERSION', '[0-9.]+'" "$SRC/bootstrap.php" | grep -oE '[0-9.]+')"
[ -n "$VER" ] || { echo "не нашёл GA_VERSION в bootstrap.php"; exit 1; }

BUILD="$(mktemp -d)"
DEST="$BUILD/genius-assistants"
mkdir -p "$DEST"
# Копируем модуль как есть (bootstrap использует __DIR__, поэтому пути не зависят от папки).
cp -r "$SRC"/. "$DEST"/
# README модуля в архив не нужен.
rm -f "$DEST/README.md"

cat > "$DEST/genius-assistants.php" <<PHP
<?php
/**
 * Plugin Name: Genius Assistants
 * Description: ИИ-ассистенты Genius: телеграм-боты и веб-версии. Вход и баланс — общие с kie-tts-wp. Баланс и пополнение ЮMoney в виджете, кнопки поддержки. Гостевой лимит по куке+IP. Загрузка документов/фото на платном тарифе. Админка токенов ботов, галерея, чат-виджет.
 * Version: $VER
 * Requires at least: 6.4
 * Requires PHP: 8.0
 * Author: Genius-bot
 */
if (!defined('ABSPATH')) { exit; }
require_once __DIR__ . '/bootstrap.php';
PHP

rm -f "$OUT"
( cd "$BUILD" && zip -qr "$OUT" genius-assistants )
rm -rf "$BUILD"
echo "готово: $OUT (версия $VER)"
