#!/usr/bin/env python3
"""
Разовая авторизация Telethon для чтения чужого канала-источника.

Запускать на сервере ОДИН раз, интерактивно:

    cd /opt/kie_ai_bot
    venv/bin/python deploy/telethon_login.py

Спросит номер телефона, код из Telegram и, если включена двухфакторная
защита, облачный пароль. После успешного входа рядом появится файл сессии
(TELETHON_SESSION), и сервису kie-bot больше не потребуется ввод.

Облачный пароль можно передать переменной окружения TELETHON_PASSWORD,
если скрытый ввод в вашем терминале работает неудобно.
"""

import asyncio
import os
import sys
from getpass import getpass
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
    from telethon.errors import (
        PasswordHashInvalidError,
        PhoneCodeExpiredError,
        PhoneCodeInvalidError,
        SessionPasswordNeededError,
    )
except ImportError:
    sys.exit("Не установлен telethon. Выполните: venv/bin/pip install telethon")

API_ID = os.getenv("TELETHON_API_ID", "").strip()
API_HASH = os.getenv("TELETHON_API_HASH", "").strip()
SESSION = os.getenv("TELETHON_SESSION", "/opt/kie_ai_bot/telethon.session")
SOURCE = os.getenv("AUTOPOST_SOURCE_CHAT_ID", "").strip()

if not API_ID or not API_HASH:
    sys.exit(
        f"В {APP_DIR}/.env не заданы TELETHON_API_ID и TELETHON_API_HASH.\n"
        "Получите их на https://my.telegram.org -> API development tools\n"
        "(App api_id -> TELETHON_API_ID, App api_hash -> TELETHON_API_HASH)."
    )


def ask_password(attempt: int) -> str:
    """Скрытый ввод с запасным вариантом, если терминал его не поддерживает."""
    env_password = os.getenv("TELETHON_PASSWORD")
    if env_password and attempt == 1:
        print("Использую пароль из переменной TELETHON_PASSWORD")
        return env_password
    print("\nНа аккаунте включена двухфакторная защита (облачный пароль).")
    print("Это НЕ код из Telegram — это пароль, заданный в")
    print("Настройки -> Конфиденциальность -> Облачный пароль.")
    print("Ввод скрыт: символы не отображаются, просто наберите и нажмите Enter.")
    try:
        return getpass("Облачный пароль: ")
    except Exception:
        return input("Облачный пароль (видимый ввод): ")


async def main() -> None:
    print(f"Файл сессии: {SESSION}")
    client = TelegramClient(SESSION, int(API_ID), API_HASH)
    await client.connect()

    try:
        if not await client.is_user_authorized():
            phone = input("Номер телефона в формате +79991234567: ").strip()
            await client.send_code_request(phone)

            try:
                code = input("Код из Telegram (придёт в приложение, не по SMS): ").strip()
                await client.sign_in(phone=phone, code=code)
            except SessionPasswordNeededError:
                for attempt in range(1, 4):
                    password = ask_password(attempt)
                    if not password:
                        print("Пароль пустой — попробуйте ещё раз.")
                        continue
                    try:
                        await client.sign_in(password=password)
                        break
                    except PasswordHashInvalidError:
                        left = 3 - attempt
                        print(f"❌ Неверный облачный пароль. Осталось попыток: {left}")
                else:
                    sys.exit(
                        "Войти не удалось: облачный пароль не подошёл.\n"
                        "Пароль можно сменить в Telegram: Настройки -> Конфиденциальность\n"
                        "-> Облачный пароль. После смены запустите скрипт заново."
                    )
            except PhoneCodeInvalidError:
                sys.exit("Войти не удалось: неверный код. Запустите скрипт заново.")
            except PhoneCodeExpiredError:
                sys.exit("Войти не удалось: код устарел. Запустите скрипт заново.")

        me = await client.get_me()
        print(f"\n✅ Вход выполнен: {getattr(me, 'username', None) or me.id} ({me.first_name})")

        if SOURCE:
            try:
                entity = await client.get_entity(int(SOURCE))
                print(f"✅ Канал-источник доступен: {getattr(entity, 'title', entity)}")
            except Exception as e:
                print(f"⚠️  Канал-источник {SOURCE} недоступен: {e}")
                print("   Убедитесь, что этот аккаунт подписан на канал.")

        print("\nДальше: chmod 600 файла сессии и systemctl restart kie-bot")
    finally:
        await client.disconnect()


asyncio.run(main())
