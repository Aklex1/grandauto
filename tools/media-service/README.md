# Служба бесплатной озвучки и звука из роликов

Плагин сайта ходит за этими двумя вещами на отдельный сервер. Старая служба
на `212.74.231.21:8099` перестала отвечать — сайт получает таймаут на десятой
секунде, поэтому не работают ни бесплатные голоса, ни «Аудио с YouTube».

Здесь она собрана заново, с теми же запросами и тем же форматом ответов:
на стороне сайта менять ничего не нужно, только адрес в настройках.

## Что внутри

| Запрос | Назначение |
|---|---|
| `GET /health` | проверка живости, видно наличие ffmpeg и yt-dlp |
| `GET /voices` | список бесплатных голосов для кабинета |
| `POST /tts` | озвучить текст: `{text, voice, output_format}` |
| `POST /youtube-audio` | достать дорожку: `{url, format}` |
| `GET /files/…` | готовые файлы |

Озвучка — на голосах Microsoft Edge (бесплатные, без ключа), извлечение —
на `yt-dlp` с перекодированием через ffmpeg. Ключ доступа передаётся
заголовком `X-API-Key`, если он задан.

Старые идентификаторы голосов остаются рабочими: `male`, `female`,
`edge:ru-RU-DmitryNeural`, а `rhvoice:Aleksandr` и подобные переводятся
на близкие голоса — сохранённые настройки пользователей не сломаются.

## Запуск в Docker (рекомендуется)

```bash
git clone <репозиторий> && cd tools/media-service
export MEDIA_API_KEY="придумайте-ключ"
export PUBLIC_BASE="http://IP_СЕРВЕРА:8099"
docker compose up -d --build
curl http://127.0.0.1:8099/health
```

`PUBLIC_BASE` — это адрес, по которому файлы видны снаружи: сайт скачивает
готовый звук именно по нему. Если поставите домен с сертификатом, укажите
`https://…` — так браузер не будет ругаться на смешанное содержимое.

## Запуск без Docker

```bash
sudo apt install -y python3-venv ffmpeg
sudo mkdir -p /opt/genius-media && sudo cp app.py /opt/genius-media/
cd /opt/genius-media
sudo python3 -m venv venv && sudo ./venv/bin/pip install -r requirements.txt
sudo cp media-service.service /etc/systemd/system/
sudo nano /etc/systemd/system/media-service.service   # ключ и PUBLIC_BASE
sudo systemctl enable --now media-service
```

## Настройка на сайте

WordPress → «Настройки → TTS»:

* **Free TTS Endpoint URL** — `http://IP_СЕРВЕРА:8099/tts`
* **Free TTS API Key** — тот же ключ, что в `MEDIA_API_KEY`

Плагин сам отрезает `/tts` в конце, когда обращается к `/voices` и
`/youtube-audio`, поэтому адрес указывается именно так.

## Проверка после установки

```bash
curl -s http://IP:8099/health
curl -s -H "X-API-Key: КЛЮЧ" http://IP:8099/voices | head -c 200
curl -s -X POST http://IP:8099/tts -H "Content-Type: application/json" \
  -H "X-API-Key: КЛЮЧ" -d '{"text":"Проверка","voice":"male"}'
curl -s -X POST http://IP:8099/youtube-audio -H "Content-Type: application/json" \
  -H "X-API-Key: КЛЮЧ" -d '{"url":"https://www.youtube.com/watch?v=...","format":"mp3"}'
```

## Если ролики не скачиваются

YouTube иногда требует подтверждения, что запрос не от робота: чаще всего
это происходит на серверах дата-центров. Лечится файломcookie:

1. в браузере, где выполнен вход, сохраните cookies для youtube.com
   в формате Netscape (расширение «Get cookies.txt»);
2. положите файл на сервер и укажите путь в переменной `YTDLP_COOKIES`;
3. перезапустите службу.

Ещё стоит держать `yt-dlp` свежим — площадка меняет защиту, и старые
версии перестают работать:

```bash
docker compose exec media pip install -U yt-dlp && docker compose restart media
```

## Настройки

| Переменная | Смысл | По умолчанию |
|---|---|---|
| `MEDIA_API_KEY` | ключ доступа; пусто — проверки нет | пусто |
| `PUBLIC_BASE` | адрес файлов снаружи | `http://127.0.0.1:8099` |
| `FILES_DIR` | куда складывать результаты | `files` |
| `KEEP_HOURS` | сколько часов хранить файлы | 24 |
| `MAX_MINUTES` | предел длины ролика | 90 |
| `YTDLP_COOKIES` | путь к cookies для YouTube | пусто |
