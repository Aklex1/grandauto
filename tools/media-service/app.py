"""
Служба бесплатной озвучки и извлечения звука из роликов.

Плагин сайта ходит сюда за двумя вещами: за бесплатными голосами и за
звуковой дорожкой из видео. Старая служба перестала отвечать, поэтому
здесь она собрана заново — с тем же набором запросов и тем же форматом
ответов, чтобы на стороне сайта ничего не менять.

Запросы:
    GET  /health          — проверка живости
    GET  /voices          — список бесплатных голосов
    POST /tts             — озвучить текст
    POST /youtube-audio   — достать дорожку из ролика
    GET  /files/<имя>     — готовые файлы

Ключ доступа передаётся заголовком X-API-Key, если он задан в настройках.
"""

import json
import os
import re
import shutil
import subprocess
import sys
import time
import uuid
from pathlib import Path
from typing import Optional

from fastapi import FastAPI, Header, HTTPException, Request
from fastapi.responses import JSONResponse
from fastapi.staticfiles import StaticFiles
from pydantic import BaseModel

API_KEY = os.environ.get("MEDIA_API_KEY", "").strip()
PUBLIC_BASE = os.environ.get("PUBLIC_BASE", "").rstrip("/")
FILES_DIR = Path(os.environ.get("FILES_DIR", "files")).resolve()
KEEP_HOURS = int(os.environ.get("KEEP_HOURS", "24"))
MAX_MINUTES = int(os.environ.get("MAX_MINUTES", "90"))

# Приём платежей: уведомления ЮMoney приходят сюда, на сервер.
YOOMONEY_SECRET = os.environ.get("YOOMONEY_SECRET", "").strip()
SITE_WEBHOOK = os.environ.get("SITE_WEBHOOK", "https://genius-bot.ru/wp-json/genius/v1/yoomoney").strip()
BOT_WEBHOOK = os.environ.get("BOT_WEBHOOK", "http://127.0.0.1:8000/yoomoney-webhook").strip()

# Метки платежей сайта. Их выдают плагины: кабинет озвучки и «Нейросети».
# Всё, что сюда не подходит, считается платежом бота.
SITE_PREFIXES = tuple(
    p.strip() for p in os.environ.get(
        "SITE_LABEL_PREFIXES", "topup_wp_,topup_telegram_,topup_,kie-neurohub|"
    ).split(",") if p.strip()
)
BOT_PREFIXES = tuple(
    p.strip() for p in os.environ.get("BOT_LABEL_PREFIXES", "").split(",") if p.strip()
)
PAY_LOG = Path(os.environ.get("PAY_LOG", FILES_DIR.parent / "payments.log"))

FILES_DIR.mkdir(parents=True, exist_ok=True)

app = FastAPI(title="Genius media service", version="1.0")
app.mount("/files", StaticFiles(directory=str(FILES_DIR)), name="files")

# Голоса по умолчанию, когда плагин присылает просто «male» или «female».
DEFAULT_VOICES = {
    "male": "ru-RU-DmitryNeural",
    "female": "ru-RU-SvetlanaNeural",
}
# Старые идентификаторы RHVoice остались в сохранённых настройках
# пользователей — переводим их на близкие голоса, чтобы ничего не падало.
RHVOICE_FALLBACK = {
    "aleksandr": "ru-RU-DmitryNeural",
    "anna": "ru-RU-SvetlanaNeural",
    "elena": "ru-RU-SvetlanaNeural",
    "irina": "ru-RU-SvetlanaNeural",
}

FORMATS = {
    "mp3": ("mp3", ["-codec:a", "libmp3lame", "-b:a", "128k"]),
    "opus": ("opus", ["-codec:a", "libopus", "-b:a", "64k"]),
    "wav": ("wav", ["-codec:a", "pcm_s16le", "-ar", "44100"]),
}


def check_key(provided: Optional[str]) -> None:
    if API_KEY and (provided or "").strip() != API_KEY:
        raise HTTPException(status_code=401, detail="Неверный ключ доступа")


def public_url(name: str) -> str:
    if PUBLIC_BASE:
        return f"{PUBLIC_BASE}/files/{name}"
    return f"/files/{name}"


def cleanup() -> None:
    """Старые файлы копятся быстро — подчищаем их при каждом обращении."""
    deadline = time.time() - KEEP_HOURS * 3600
    for item in FILES_DIR.glob("*"):
        try:
            if item.is_file() and item.stat().st_mtime < deadline:
                item.unlink()
        except OSError:
            pass


def resolve_voice(raw: str) -> str:
    value = (raw or "").strip()
    if not value:
        return DEFAULT_VOICES["male"]
    low = value.lower()
    if low in DEFAULT_VOICES:
        return DEFAULT_VOICES[low]
    if low.startswith("edge:"):
        return value.split(":", 1)[1]
    if low.startswith("rhvoice:"):
        name = low.split(":", 1)[1]
        return RHVOICE_FALLBACK.get(name, DEFAULT_VOICES["male"])
    return value


def convert(source: Path, fmt: str) -> Path:
    ext, args = FORMATS.get(fmt, FORMATS["mp3"])
    target = source.with_suffix("." + ext)
    if source == target:
        return source
    result = subprocess.run(
        ["ffmpeg", "-y", "-i", str(source), *args, str(target)],
        capture_output=True,
    )
    if result.returncode != 0 or not target.exists():
        raise HTTPException(status_code=500, detail="Не удалось перекодировать файл")
    source.unlink(missing_ok=True)
    return target


class TtsRequest(BaseModel):
    text: str
    voice: str = "male"
    output_format: str = "mp3"
    style: Optional[str] = None


class YoutubeRequest(BaseModel):
    url: str
    format: str = "mp3"




def yoomoney_signature_ok(form: dict) -> bool:
    """Подпись ЮMoney. Без секрета проверять нечем — тогда принимаем как есть."""
    if not YOOMONEY_SECRET:
        return True
    import hashlib

    parts = "&".join([
        form.get("notification_type", ""),
        form.get("operation_id", ""),
        form.get("amount", ""),
        form.get("currency", ""),
        form.get("datetime", ""),
        form.get("sender", ""),
        form.get("codepro", ""),
        YOOMONEY_SECRET,
        form.get("label", ""),
    ])
    mine = hashlib.sha1(parts.encode("utf-8")).hexdigest()
    return mine == (form.get("sha1_hash") or "").lower()


def whose_payment(label: str) -> str:
    """Чей это платёж — сайта или бота. Решаем по метке, которую выдал
    тот, кто заводил платёж: у сайта она своя, у бота своя."""
    if BOT_PREFIXES and label.startswith(BOT_PREFIXES):
        return "бот"
    if label.startswith(SITE_PREFIXES):
        return "сайт"
    return "бот"


def write_pay_log(entry: dict) -> None:
    try:
        PAY_LOG.parent.mkdir(parents=True, exist_ok=True)
        with open(PAY_LOG, "a", encoding="utf-8") as f:
            f.write(json.dumps(entry, ensure_ascii=False) + "\n")
    except OSError:
        pass


def deliver(url: str, form: dict, timeout: int = 25) -> dict:
    """Передаём уведомление дальше ровно в том виде, в каком получили."""
    import urllib.error
    import urllib.parse
    import urllib.request

    data = urllib.parse.urlencode(form).encode("utf-8")
    request = urllib.request.Request(
        url, data=data,
        headers={"Content-Type": "application/x-www-form-urlencoded"},
    )
    try:
        with urllib.request.urlopen(request, timeout=timeout) as response:
            body = response.read(600).decode("utf-8", "replace")
            return {"ok": 200 <= response.status < 300, "status": response.status, "body": body}
    except urllib.error.HTTPError as error:
        return {"ok": False, "status": error.code, "body": error.read(300).decode("utf-8", "replace")}
    except Exception as error:
        return {"ok": False, "status": 0, "body": str(error)}

def worth_other_client(stderr):
    """
    Отказ, который лечится сменой клиента.

    Площадка то требует подтвердить, что вы не робот, то присылает
    просроченный ответ плеера. И то и другое проходит, если yt-dlp
    представится другим клиентом.
    """
    low = (stderr or "").lower()
    return any(mark in low for mark in (
        "sign in to confirm", "confirm you", "not a bot", "bot",
        "cookies", "account", "consent",
        "page needs to be reloaded", "unable to extract", "player response",
        "failed to extract", "nsig", "precondition check",
    ))


def blocked_as_bot(stderr):
    """Из отказов выше — те, где действительно нужны файлы входа."""
    low = (stderr or "").lower()
    return any(mark in low for mark in ("sign in to confirm", "not a bot", "cookies", "consent"))


def ytdlp_command():
    """
    Как запускать yt-dlp.

    Он ставится в окружение службы, а не в общий PATH, поэтому службе,
    запущенной из systemd, простого имени мало: ищем рядом с интерпретатором,
    потом в PATH, и в последнюю очередь зовём модулем.
    """
    local = Path(sys.executable).with_name("yt-dlp")
    if local.exists():
        return [str(local)]
    found = shutil.which("yt-dlp")
    if found:
        return [found]
    return [sys.executable, "-m", "yt_dlp"]


def ytdlp_ready():
    try:
        probe = subprocess.run(ytdlp_command() + ["--version"], capture_output=True, timeout=20)
        return probe.returncode == 0
    except Exception:
        return False


@app.get("/health")
def health():
    return {
        "status": "ok",
        "ffmpeg": shutil.which("ffmpeg") is not None,
        "ytdlp": ytdlp_ready(),
        "files": len(list(FILES_DIR.glob("*"))),
    }


@app.get("/voices")
async def voices(x_api_key: Optional[str] = Header(default=None)):
    check_key(x_api_key)
    import edge_tts

    try:
        catalog = await edge_tts.list_voices()
    except Exception as error:  # сеть до поставщика голосов
        raise HTTPException(status_code=502, detail=f"Не получен список голосов: {error}")

    out = []
    for item in catalog:
        short = item.get("ShortName", "")
        if not short:
            continue
        out.append({
            "id": "edge:" + short,
            "name": item.get("FriendlyName", short),
            "gender": (item.get("Gender", "") or "").lower(),
            "locale": item.get("Locale", ""),
        })
    out.sort(key=lambda v: (not v["locale"].startswith("ru"), v["locale"], v["name"]))
    return {"voices": out}


@app.post("/tts")
async def tts(payload: TtsRequest, x_api_key: Optional[str] = Header(default=None)):
    check_key(x_api_key)
    cleanup()

    text = (payload.text or "").strip()
    if not text:
        raise HTTPException(status_code=400, detail="Пустой текст")
    if len(text) > 20000:
        raise HTTPException(status_code=400, detail="Текст длиннее двадцати тысяч знаков")

    import edge_tts

    voice = resolve_voice(payload.voice)
    name = f"tts-{uuid.uuid4().hex[:16]}.mp3"
    target = FILES_DIR / name

    try:
        communicate = edge_tts.Communicate(text, voice)
        await communicate.save(str(target))
    except Exception as error:
        # Недописанный файл оставлять нельзя — он потом отдастся как готовый.
        target.unlink(missing_ok=True)
        raise HTTPException(status_code=502, detail=f"Синтез не удался: {error}")

    if not target.exists() or target.stat().st_size < 512:
        target.unlink(missing_ok=True)
        raise HTTPException(status_code=502, detail="Пустой ответ синтеза")

    fmt = (payload.output_format or "mp3").lower()
    if fmt != "mp3":
        target = convert(target, fmt)

    return {
        "audio_url": public_url(target.name),
        "voice": voice,
        "format": target.suffix.lstrip("."),
        "size": target.stat().st_size,
    }


@app.post("/youtube-audio")
def youtube_audio(payload: YoutubeRequest, x_api_key: Optional[str] = Header(default=None)):
    check_key(x_api_key)
    cleanup()

    url = (payload.url or "").strip()
    if not re.match(r"^https?://", url):
        raise HTTPException(status_code=400, detail="Нужна ссылка на ролик")

    fmt = (payload.format or "mp3").lower()
    ext, _ = FORMATS.get(fmt, FORMATS["mp3"])
    stem = f"yt-{uuid.uuid4().hex[:16]}"
    template = str(FILES_DIR / (stem + ".%(ext)s"))

    base = ytdlp_command() + [
        "-x", "--audio-format", ext,
        "--audio-quality", "0",
        "--no-playlist",
        "--match-filter", f"duration < {MAX_MINUTES * 60}",
        "--retries", "3",
        "-o", template,
    ]
    cookies = os.environ.get("YTDLP_COOKIES", "")
    if cookies and Path(cookies).exists():
        # Ролики с ограничением по возрасту и регионам требуют входа.
        base += ["--cookies", cookies]

    # YouTube отвечает серверам «подтвердите, что вы не робот»: с адреса
    # дата-центра обычный клиент он не пускает. Обходится сменой клиента,
    # которым представляется yt-dlp, — пробуем по очереди, пока не выйдет.
    attempts = [[]]
    if not cookies:
        attempts += [
            ["--extractor-args", "youtube:player_client=android"],
            ["--extractor-args", "youtube:player_client=ios"],
            ["--extractor-args", "youtube:player_client=tv_embedded"],
            ["--extractor-args", "youtube:player_client=web_safari"],
        ]

    result = None
    produced = []
    for extra in attempts:
        result = subprocess.run(base + extra + [url], capture_output=True, text=True, timeout=600)
        produced = sorted(FILES_DIR.glob(stem + ".*"))
        if result.returncode == 0 and produced:
            break
        if not worth_other_client(result.stderr or ""):
            break

    if result is None or result.returncode != 0 or not produced:
        message = (result.stderr or "").strip().splitlines() if result else []
        detail = message[-1] if message else "Не удалось получить дорожку"
        if blocked_as_bot(result.stderr if result else ""):
            detail = ("YouTube не отдаёт ролик по запросу с сервера и просит подтвердить, "
                      "что вы не робот. Помогают файлы входа: положите cookies.txt на сервер "
                      "и укажите путь в YTDLP_COOKIES.")
        raise HTTPException(status_code=502, detail=detail[:300])

    target = produced[0]
    title = ""
    duration = 0
    info = subprocess.run(
        ytdlp_command() + ["--no-playlist", "--print", "%(title)s|%(duration)s", "--skip-download", url],
        capture_output=True, text=True, timeout=120,
    )
    if info.returncode != 0 and worth_other_client(info.stderr or ""):
        info = subprocess.run(
            ytdlp_command() + ["--extractor-args", "youtube:player_client=android",
                               "--no-playlist", "--print", "%(title)s|%(duration)s", "--skip-download", url],
            capture_output=True, text=True, timeout=120,
        )
    if info.returncode == 0 and "|" in info.stdout:
        raw_title, _, raw_duration = info.stdout.strip().partition("|")
        title = raw_title.strip()
        duration = int(float(raw_duration)) if raw_duration.strip().replace(".", "").isdigit() else 0

    return {
        "audio_url": public_url(target.name),
        "title": title,
        "duration": duration,
        "format": target.suffix.lstrip("."),
        "size": target.stat().st_size,
    }


@app.post("/yoomoney-webhook")
async def yoomoney_webhook(request: Request):
    """Единая точка приёма платежей на сервере.

    Платёж за услуги сайта уходит на сайт: там по метке находят
    пользователя и пополняют ему баланс. Всё остальное достаётся боту —
    его логику не трогаем.
    """
    form = {k: str(v) for k, v in (await request.form()).items()}
    label = form.get("label", "")
    amount = form.get("withdraw_amount") or form.get("amount") or "0"
    entry = {"at": time.strftime("%Y-%m-%d %H:%M:%S"), "label": label, "amount": amount}

    if not yoomoney_signature_ok(form):
        entry.update(route="отклонено", detail="подпись не сошлась")
        write_pay_log(entry)
        return JSONResponse(status_code=403, content={"detail": "bad signature"})

    if not label:
        # ЮMoney так проверяет адрес при сохранении настроек.
        entry.update(route="пропущено", detail="уведомление без метки")
        write_pay_log(entry)
        return {"status": "ok"}

    owner = whose_payment(label)
    entry["owner"] = owner

    if owner == "сайт":
        # Пользователя ищет сам сайт: по метке он знает, кому пополнять,
        # независимо от того, вошёл человек по почте, через ВК или из бота.
        result = deliver(SITE_WEBHOOK, form)
        credited = False
        try:
            credited = bool(json.loads(result["body"]).get("credited"))
        except (ValueError, AttributeError):
            credited = result["ok"]

        if credited:
            entry.update(route="сайт", detail="баланс пополнен")
        else:
            entry.update(route="сайт", detail=f"не зачислено: {result['body'][:140]}")
            # Метка сайта, а платежа нет — это уже не вопрос маршрута,
            # поэтому боту такое не передаём, а оставляем в журнале.
    elif BOT_WEBHOOK:
        result = deliver(BOT_WEBHOOK, form)
        entry.update(route="бот", detail=f"{result['status']} {result['body'][:120]}")
    else:
        entry.update(route="никуда", detail="платёж бота, но адрес бота не задан")

    write_pay_log(entry)
    # ЮMoney повторяет уведомление, если ответ не 200: подтверждаем приём,
    # а разбор неудач остаётся в журнале.
    return {"status": "ok", "route": entry["route"]}


@app.get("/payments/log")
def payments_log(limit: int = 50, x_api_key: Optional[str] = Header(default=None)):
    check_key(x_api_key)
    if not PAY_LOG.exists():
        return {"entries": []}
    lines = PAY_LOG.read_text(encoding="utf-8", errors="replace").splitlines()[-max(1, min(limit, 500)):]
    entries = []
    for line in lines:
        try:
            entries.append(json.loads(line))
        except ValueError:
            continue
    return {"entries": list(reversed(entries))}


@app.exception_handler(HTTPException)
def http_error(request, exc: HTTPException):
    # Плагин читает поле detail — держим единый формат ошибки.
    return JSONResponse(status_code=exc.status_code, content={"detail": exc.detail})
