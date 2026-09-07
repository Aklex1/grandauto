#!/usr/bin/env python3
"""
Разовая авторизация Telethon для чтения чужого канала-источника.

Запускать на сервере ОДИН раз, интерактивно:

    cd /opt/kie_ai_bot
    venv/bin/python deploy/telethon_login.py

Скрипт спросит номер телефона, код из Telegram и, если включена, облачный пароль.
После успешного входа рядом появится файл сессии (TELETHON_SESSION),
и сервису kie-bot больше не потребуется ввод.
"""

import os
import sys
from pathlib import Path

# Код и конфиг живут в рабочем каталоге (/opt/kie_ai_bot), а сам скрипт —
# в каталоге репозитория, поэтому ищем .env в обоих местах.
APP_DIR = Path(os.getenv("KIE_APP_DIR", "/opt/kie_ai_bot"))
REPO_DIR = Path(__file__).resolve().parent.parent

for candidate in (APP_DIR, REPO_DIR, Path.cwd()):
    if (candidate / "config.py").exists():
        sys.path.insert(0, str(candidate))
        break
else:
    sys.path.insert(0, str(REPO_DIR))

from dotenv import load_dotenv

for candidate in (APP_DIR / ".env", REPO_DIR / ".env", Path.cwd() / ".env"):
    if candidate.exists():
        load_dotenv(candidate)
        print(f"Конфигурация: {candidate}")
        break
else:
    print("⚠️  Файл .env не найден — читаю только переменные окружения")

try:
    from telethon import TelegramClient
except ImportError:
    sys.exit("Не установлен telethon. Выполните: venv/bin/pip install telethon")

API_ID = os.getenv("TELETHON_API_ID", "").strip()
API_HASH = os.getenv("TELETHON_API_HASH", "").strip()
SESSION = os.getenv("TELETHON_SESSION", "/opt/kie_ai_bot/telethon.session")

if not API_ID or not API_HASH:
    sys.exit(
        f"В {APP_DIR}/.env не заданы TELETHON_API_ID и TELETHON_API_HASH.\n"
        "Получите их на https://my.telegram.org -> API development tools\n"
        "(App api_id -> TELETHON_API_ID, App api_hash -> TELETHON_API_HASH)."
    )

print(f"Файл сессии: {SESSION}")
with TelegramClient(SESSION, int(API_ID), API_HASH) as client:
    me = client.get_me()
    print(f"✅ Вход выполнен: {getattr(me, 'username', None) or me.id} ({me.first_name})")

    source = os.getenv("AUTOPOST_SOURCE_CHAT_ID", "").strip()
    if source:
        try:
            entity = client.get_entity(int(source))
            print(f"✅ Канал-источник доступен: {getattr(entity, 'title', entity)}")
        except Exception as e:
            print(f"⚠️  Канал-источник {source} недоступен: {e}")
            print("   Убедитесь, что этот аккаунт подписан на канал.")
