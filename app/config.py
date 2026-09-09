"""Конфигурация контент-завода. Значения берутся из окружения (/etc/contentfactory.env)."""
import os
import secrets
from pathlib import Path


def _bool(v: str, default: bool = False) -> bool:
    if v is None or v == "":
        return default
    return v.strip().lower() in ("1", "true", "yes", "on")


DATA_DIR = Path(os.environ.get("CF_DATA_DIR", "/var/lib/contentfactory"))
MEDIA_DIR = DATA_DIR / "media"
TMP_DIR = DATA_DIR / "tmp"
LOG_DIR = DATA_DIR / "logs"
# Каталог для файлов проверки владения доменом (ACME). Приложение отдаёт его
# по /.well-known/acme-challenge — на порту 80 сидит оно, а не веб-сервер,
# и без этого certbot получал 404 и не мог выпустить сертификат.
ACME_DIR = DATA_DIR / "acme"

DB_URL = os.environ.get("CF_DB_URL") or f"sqlite:///{DATA_DIR / 'contentfactory.db'}"

SECRET_KEY = os.environ.get("CF_SECRET_KEY") or secrets.token_urlsafe(32)
ADMIN_USER = os.environ.get("CF_ADMIN_USER", "admin")
ADMIN_PASSWORD = os.environ.get("CF_ADMIN_PASSWORD", "")

KIE_API_KEY = os.environ.get("KIE_API_KEY", "")
KIE_BASE = os.environ.get("KIE_BASE", "https://api.kie.ai")

HOST = os.environ.get("CF_HOST", "0.0.0.0")
PORT = int(os.environ.get("CF_PORT", "80"))
PUBLIC_URL = os.environ.get("CF_PUBLIC_URL", "").rstrip("/")

WORKERS = int(os.environ.get("CF_WORKERS", "2"))

WHISPER_MODEL = os.environ.get("CF_WHISPER_MODEL", "small")
WHISPER_DEVICE = os.environ.get("CF_WHISPER_DEVICE", "cpu")
WHISPER_COMPUTE = os.environ.get("CF_WHISPER_COMPUTE", "int8")
WHISPER_ENABLED = _bool(os.environ.get("CF_WHISPER_ENABLED"), True)

FFMPEG = os.environ.get("CF_FFMPEG", "ffmpeg")
FFPROBE = os.environ.get("CF_FFPROBE", "ffprobe")

SESSION_COOKIE = "cf_session"
SESSION_MAX_AGE = 60 * 60 * 24 * 14

for _d in (DATA_DIR, MEDIA_DIR, TMP_DIR, LOG_DIR, ACME_DIR):
    _d.mkdir(parents=True, exist_ok=True)
