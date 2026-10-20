# Что поднимать на арендованной машине

Коротко: одна машина, на ней ComfyUI для кадров и роликов плюс две
маленькие службы — распознавание речи и озвучка. Сайт обращается к ним
по трём портам. Ключ Vast при этом остаётся на сайте и нужен только
чтобы машину арендовать и погасить.

| Что | Порт | Чем | Зачем |
|---|---|---|---|
| Кадры и ролики | 8188 | ComfyUI | мастерская в кабинете |
| Распознавание речи | 8189 | faster-whisper + FastAPI | субтитры, расшифровка |
| Озвучка | 8190 | XTTS-v2 или F5-TTS | дорожка под ролик |

## 1. Выбор машины

Что считаем определяет память видеокарты, а не её скорость.

| Задача | Память | Карта | Цена, ориентир |
|---|---|---|---|
| Кадры SDXL / Flux schnell | 24 ГБ | RTX 4090, 3090 | $0,4–0,5 в час |
| Ролики LTX-Video 2B, Wan 2.2 5B | 24 ГБ | RTX 4090 | $0,4–0,5 |
| Ролики Wan 2.2 14B, HunyuanVideo 13B | 48–80 ГБ | A100 80, H100 80 | $1,5–3 |
| Видео + звук одной моделью | 80 ГБ | H100 | $2–3 |

Диск: 60 ГБ под кадры, 150–250 ГБ если тянете веса видео и звука.
Веса скачиваются один раз и живут, пока машина не погашена, поэтому
поднимать её ради пяти кадров невыгодно — выгоднее занятие целиком.

## 2. Образ и запуск

В админке сайта, вкладка **Курс → Машина**, поле «Образ». Рабочие
варианты:

- `vastai/comfy:cuda-12.1-auto` — ComfyUI уже внутри, порт 8188;
- `ghcr.io/ai-dock/comfyui:latest-cuda` — тот же ComfyUI плюс
  менеджер расширений;
- любой `pytorch/pytorch:2.4.0-cuda12.1-cudnn9-runtime`, если ставите
  всё сами скриптом запуска.

Порт 8188 плагин пробрасывает сам: в запросе на аренду уходит
`env: {"-p 8188:8188": "1"}`. Для двух дополнительных служб добавьте в
том же поле `-p 8189:8189` и `-p 8190:8190`.

## 3. Скрипт запуска

Поле `onstart` выполняется после старта контейнера. Этот ставит
недостающее и поднимает обе службы:

```bash
env >> /etc/environment            # иначе переменные не видны в ssh
cd /workspace

# ---- модели для кадров
mkdir -p ComfyUI/models/checkpoints
wget -nc -P ComfyUI/models/checkpoints \
  https://huggingface.co/stabilityai/stable-diffusion-xl-base-1.0/resolve/main/sd_xl_base_1.0.safetensors

# ---- узлы для роликов и звука
cd ComfyUI/custom_nodes
git clone https://github.com/kijai/ComfyUI-WanVideoWrapper      # Wan 2.2
git clone https://github.com/Lightricks/ComfyUI-LTXVideo         # LTX-Video
git clone https://github.com/kosinkadink/ComfyUI-VideoHelperSuite # сборка mp4 со звуком
git clone https://github.com/kijai/ComfyUI-MMAudio               # звук к готовому видео
cd /workspace

# ---- распознавание речи
pip install --no-cache-dir faster-whisper fastapi "uvicorn[standard]" python-multipart
nohup python -m stt_server > stt.log 2>&1 &

# ---- озвучка
pip install --no-cache-dir TTS
nohup python -m tts_server > tts.log 2>&1 &
```

Два последних блока предполагают, что файлы `stt_server.py` и
`tts_server.py` лежат в `/workspace`. Они короткие, примеры ниже.

## 4. Распознавание речи (STT)

`faster-whisper` с моделью `large-v3` занимает около 3 ГБ видеопамяти и
расшифровывает час записи за полторы-две минуты на 4090.

```python
# stt_server.py
import os, tempfile
from fastapi import FastAPI, File, UploadFile, Header, HTTPException
from faster_whisper import WhisperModel
import uvicorn

TOKEN = os.environ.get("GC_TOKEN", "")
model = WhisperModel("large-v3", device="cuda", compute_type="float16")
app = FastAPI()

@app.post("/stt")
async def stt(file: UploadFile = File(...), authorization: str = Header(default="")):
    if TOKEN and authorization != "Bearer " + TOKEN:
        raise HTTPException(401, "нет доступа")
    with tempfile.NamedTemporaryFile(suffix=".bin", delete=False) as tmp:
        tmp.write(await file.read())
        path = tmp.name
    segments, info = model.transcribe(path, language=None, vad_filter=True)
    rows = [{"start": s.start, "end": s.end, "text": s.text.strip()} for s in segments]
    os.unlink(path)
    return {"language": info.language, "segments": rows,
            "text": " ".join(r["text"] for r in rows)}

if __name__ == "__main__":
    uvicorn.run(app, host="0.0.0.0", port=8189)
```

Сегменты приходят с таймкодами — из них же собираются субтитры.

## 5. Озвучка (TTS)

Для русского работают два варианта. XTTS-v2 умеет клонировать голос по
20-секундному образцу, F5-TTS чище звучит на длинных текстах.

```python
# tts_server.py
import io, os
from fastapi import FastAPI, Header, HTTPException
from fastapi.responses import Response
from pydantic import BaseModel
from TTS.api import TTS
import uvicorn

TOKEN = os.environ.get("GC_TOKEN", "")
tts = TTS("tts_models/multilingual/multi-dataset/xtts_v2").to("cuda")
app = FastAPI()

class Say(BaseModel):
    text: str
    language: str = "ru"
    speaker_wav: str = "/workspace/voices/default.wav"

@app.post("/tts")
def say(req: Say, authorization: str = Header(default="")):
    if TOKEN and authorization != "Bearer " + TOKEN:
        raise HTTPException(401, "нет доступа")
    buf = io.BytesIO()
    tts.tts_to_file(text=req.text, language=req.language,
                    speaker_wav=req.speaker_wav, file_path=buf)
    return Response(content=buf.getvalue(), media_type="audio/wav")

if __name__ == "__main__":
    uvicorn.run(app, host="0.0.0.0", port=8190)
```

Образцы голосов кладутся в `/workspace/voices/`. Один раз загруженный
голос живёт, пока жива машина.

## 6. Видео со звуком

Моделей, которые делают картинку и звук одной кнопкой, пока мало, и
все они тяжёлые. На практике работают три схемы.

**Схема А. Звук отдельно, сведение в ComfyUI.** Ролик считает Wan или
LTX, дорожку даёт MMAudio (шумы, атмосфера, удары) или наш TTS
(реплики), а узел `VHS_VideoCombine` из VideoHelperSuite принимает
кадры и аудио и отдаёт готовый mp4. Это самый управляемый путь и он же
самый дешёвый: звук можно переделать, не пересчитывая видео.

**Схема Б. Одна модель.** Wan 2.2 S2V делает видео под готовую
речевую дорожку: сначала озвучка, потом видео под неё. Нужен H100 или
A100 на 80 ГБ.

**Схема В. Озвучка поверх готового.** Самая дешёвая: ролик считается
без звука, дорожка собирается на сайте из нашей же озвучки, сведение
делает `ffmpeg -i video.mp4 -i voice.wav -c:v copy -shortest out.mp4`.
Подходит, когда звук — это закадровый текст, а не эффекты в кадре.

Для схемы А заготовка запроса в настройках сайта должна заканчиваться
узлом `VHS_VideoCombine` с подключённым входом `audio` — тогда плагин
заберёт из ответа готовый mp4, а не последовательность кадров.

## 7. Встроенные заготовки: что скачать на машину

Свой граф нужен не всегда. В плагине лежат четыре заготовки, и они
берутся сами, когда поля в настройках пустые:

| Что нажал ученик | Заготовка | Узлы |
|---|---|---|
| кадр по описанию | SDXL, текст → кадр | `CheckpointLoaderSimple`, `EmptyLatentImage`, `KSampler`, `SaveImage` |
| кадр с референсом | SDXL, кадр → кадр | плюс `LoadImage`, `ImageScale`, `VAEEncode` |
| ролик по описанию | LTX-Video | `CLIPLoader`, `EmptyLTXVLatentVideo`, `LTXVConditioning`, `SaveWEBM` |
| ролик из фотографии | LTX-Video | плюс `LoadImage`, `LTXVImgToVideo` |

Все эти узлы есть в самом ComfyUI — расширений ставить не нужно. Нужны
только файлы весов:

```bash
cd /workspace/ComfyUI/models
# кадры
wget -nc -P checkpoints \
  https://huggingface.co/stabilityai/stable-diffusion-xl-base-1.0/resolve/main/sd_xl_base_1.0.safetensors
# ролики: сама модель и текстовый кодировщик к ней
wget -nc -P checkpoints \
  https://huggingface.co/Lightricks/LTX-Video/resolve/main/ltx-video-2b-v0.9.5.safetensors
wget -nc -P text_encoders \
  https://huggingface.co/comfyanonymous/flux_text_encoders/resolve/main/t5xxl_fp16.safetensors
```

Про `SaveWEBM`: ему нужен пакет `av` (`pip install av`), на большинстве
образов он уже стоит. Если узла нет, замените в заготовке сохранение на
`SaveAnimatedWEBP` — кабинет покажет такой файл картинкой, или на
`VHS_VideoCombine` из VideoHelperSuite, если нужен mp4 со звуком.

Размеры и длину ролика плагин подгоняет сам: сторона кратна 32, число
кадров 8n+1 — иначе LTX отвечает ошибкой про размерности.

Проверить, всё ли на месте, можно не вслепую: на вкладке **Курс →
Настройки**, пока машина на связи, рядом с состоянием выводится строка
«узлы на месте» или список недостающих. Плагин спрашивает ComfyUI по
каждому узлу из заготовки (`/object_info/{узел}`) и запоминает ответ на
десять минут. Тот же список ученик увидит в ответ на попытку
сгенерировать — вместо безликого «не получилось».

## 8. Что вписать в настройках сайта

**Курс → Машина**: ключ Vast, карта, потолок цены, диск, образ. Дальше
кнопка «Арендовать» у подходящего предложения и «Использовать для
генераций» у поднявшейся машины — адрес и порт подставятся сами.

**Курс → Настройки**: заготовки запросов. Для кадров можно оставить
пустым — возьмётся встроенный SDXL. Для роликов вставьте граф из
ComfyUI, сохранённый в формате для API, с подстановками:

| Подстановка | Что подставится |
|---|---|
| `%PROMPT%` | описание от ученика |
| `%NEGATIVE%` | чего не должно быть в кадре |
| `%MODEL%` | файл модели для кадров |
| `%VMODEL%` | файл модели для роликов |
| `%VCLIP%` | текстовый кодировщик для роликов |
| `%WIDTH%`, `%HEIGHT%` | размер |
| `%FRAMES%`, `%FPS%` | длина ролика и частота кадров |
| `%STEPS%`, `%CFG%` | шаги и строгость |
| `%SEED%` | зерно |
| `%REFERENCE%` | имя загруженного референса на машине |
| `%STRENGTH%` | насколько держаться референса |

Граф берётся так: собрали в ComfyUI мышью, проверили, затем
**Workflow → Export (API)**. В выгруженном json заменяете значения на
подстановки.

## 9. Деньги

Аренда списывается посекундно, пока машина включена, и не
останавливается от того, что ею не пользуются. Поэтому:

- включать перед занятием, гасить после — кнопка «Остановить» на
  вкладке «Машина»;
- веса при остановке теряются, если не подключён том: повторная
  загрузка SDXL это минуты, видео — десятки минут;
- для постоянной работы дешевле один раз сделать том (`volume_info`) и
  монтировать его к новым машинам.
