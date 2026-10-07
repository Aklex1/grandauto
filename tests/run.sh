#!/bin/bash
# Прогон тестов завода. Каждый тест получает свой каталог данных, поэтому они не
# мешают друг другу и ничего не делают с настоящей базой.
#
#   bash tests/run.sh                  # все
#   bash tests/run.sh test_youtube     # один или несколько
#
# Тесты держат подставные серверы вместо KIE, Suno и Google: сеть наружу им не
# нужна, денег они не тратят. Нужны ffmpeg и ffprobe.
set -u
cd "$(dirname "$0")/.."
ROOT=$(pwd)
WORK=${CF_TEST_WORK:-$ROOT/.testrun}
mkdir -p "$WORK"

if [ $# -gt 0 ]; then
  LIST=""
  for name in "$@"; do LIST="$LIST tests/${name%.py}.py"; done
else
  LIST=$(ls tests/test_*.py)
fi

pass=0; fail=0; failed=""
for path in $LIST; do
  name=$(basename "$path" .py)
  rm -rf "$WORK/$name"; mkdir -p "$WORK/$name"
  if CF_DATA_DIR="$WORK/$name/data" OUT="$WORK/$name/out" \
     CF_ADMIN_PASSWORD=test1234 CF_WHISPER_ENABLED=0 PYTHONPATH="$ROOT" \
     timeout 1200 python3 "$path" > "$WORK/$name.log" 2>&1; then
    if grep -q "ВСЁ ПРОШЛО" "$WORK/$name.log"; then
      echo "OK   $name"; pass=$((pass+1))
    else
      echo "??   $name — нет итоговой строки, смотрите $WORK/$name.log"
      fail=$((fail+1)); failed="$failed $name"
    fi
  else
    echo "FAIL $name — $WORK/$name.log"
    fail=$((fail+1)); failed="$failed $name"
  fi
done
echo "--- прошло $pass, упало $fail:$failed"
[ "$fail" -eq 0 ]
