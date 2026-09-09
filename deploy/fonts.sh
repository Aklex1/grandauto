#!/usr/bin/env bash
# Скачивает шрифты для титров в DATA_DIR/fonts.
#
# Берём из репозитория Google Fonts: открытые лицензии (OFL/Apache), кириллица
# у всех проверена. Системных DejaVu и Liberation хватает для читаемости, но не
# для оформления — формат «бюст» держится на строгом засечном шрифте.
set -euo pipefail

DATA_DIR="${CF_DATA_DIR:-/var/lib/contentfactory}"
DEST="$DATA_DIR/fonts"
BASE="https://raw.githubusercontent.com/google/fonts/main"

mkdir -p "$DEST"

# имя файла -> путь в репозитории (скобки в именах переменных шрифтов кодируются)
download() {
  local name="$1" path="$2"
  if [ -s "$DEST/$name" ]; then
    echo "  $name — уже есть"
    return 0
  fi
  if curl -fsSL --max-time 120 -o "$DEST/$name.part" "$BASE/$path"; then
    mv "$DEST/$name.part" "$DEST/$name"
    echo "  $name — $(stat -c%s "$DEST/$name") байт"
  else
    rm -f "$DEST/$name.part"
    echo "  $name — НЕ СКАЧАЛСЯ" >&2
  fi
}

echo "==> Качаю шрифты в $DEST"
download PlayfairDisplay.ttf "ofl/playfairdisplay/PlayfairDisplay%5Bwght%5D.ttf"
download PTSerif.ttf         "ofl/ptserif/PT_Serif-Web-Bold.ttf"
download Montserrat.ttf      "ofl/montserrat/Montserrat%5Bwght%5D.ttf"
download Oswald.ttf          "ofl/oswald/Oswald%5Bwght%5D.ttf"
download Rubik.ttf           "ofl/rubik/Rubik%5Bwght%5D.ttf"
download Unbounded.ttf       "ofl/unbounded/Unbounded%5Bwght%5D.ttf"

# образцы перерисуются заново под новый набор
rm -rf "$DATA_DIR/font-previews"

echo
echo "Готово. Шрифтов на диске: $(ls -1 "$DEST"/*.ttf 2>/dev/null | wc -l)"
echo "Выбор шрифта появится в опциях пересборки сцены."
