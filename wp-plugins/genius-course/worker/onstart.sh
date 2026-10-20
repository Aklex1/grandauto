#!/usr/bin/env bash
# Запуск службы на инстансе vast.ai.
#
# Кладётся в поле «On-start script» при создании инстанса. Образ берём
# с уже установленным PyTorch — ставить его с нуля дольше, чем считать
# первую сотню кадров.
set -euo pipefail

cd /workspace
mkdir -p gc-worker && cd gc-worker

# Файл службы приезжает вместе с плагином; сюда его кладут один раз
# любым способом: wget с сайта, scp или копипастой через веб-терминал.
if [ ! -f app.py ]; then
  echo "положите app.py в /workspace/gc-worker" >&2
  exit 1
fi

pip install --no-cache-dir \
  "diffusers>=0.31" "transformers>=4.44" accelerate safetensors \
  fastapi "uvicorn[standard]" requests pillow

export GC_TOKEN="${GC_TOKEN:?задайте GC_TOKEN в переменных окружения инстанса}"
export GC_PORT="${GC_PORT:-18080}"

nohup python app.py > worker.log 2>&1 &
echo "служба поднята на порту ${GC_PORT}, журнал в /workspace/gc-worker/worker.log"
