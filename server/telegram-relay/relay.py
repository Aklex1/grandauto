#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Пересыльщик сообщений в Telegram.

Сайт живёт на хостинге, с которого api.telegram.org отвечает с
перебоями: часть попыток обрывается за несколько миллисекунд, часть
проходит. В неудачное окно заявки копятся в журнале админки, но до
телеграма не доходят — о них узнают, только если зайти и посмотреть.

Эта служба стоит на сервере, где телеграм доступен, принимает от сайта
текст сообщения и отдаёт его боту. Своей логики, кроме проверки прав и
частоты, здесь нет намеренно.

Что важно для безопасности:

  * токен бота лежит здесь, на сервере, и наружу не выходит — сайт его
    не знает и не передаёт;
  * принимаются только запросы с общим ключом в заголовке, иначе любой
    желающий слал бы сообщения в ваш телеграм;
  * получатель берётся из настроек службы; чужой chat_id не примет,
    даже если его пришлют;
  * частота ограничена, чтобы сорвавшийся скрипт на сайте не завалил
    переписку и не уткнулся в ограничения телеграма.

Запуск:
    RELAY_TOKEN=... TELEGRAM_BOT_TOKEN=... TELEGRAM_CHAT_ID=... \
        python3 relay.py --port 8020
"""

import argparse
import json
import logging
import os
import sys
import threading
import time
import urllib.error
import urllib.parse
import urllib.request
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

LOG = logging.getLogger("telegram-relay")

API = "https://api.telegram.org"
TIMEOUT = 20

# Сколько сообщений принимаем в минуту. Телеграм и сам ограничивает
# частоту, но лучше упереться в свой предел и ответить понятной ошибкой,
# чем получить блокировку бота.
RATE_LIMIT = 30
RATE_WINDOW = 60

# Длиннее телеграм всё равно не примет.
MAX_TEXT = 3900


class Limiter:
    """Счётчик обращений за последнюю минуту."""

    def __init__(self, limit, window):
        self.limit = limit
        self.window = window
        self.hits = []
        self.lock = threading.Lock()

    def allow(self):
        now = time.time()
        with self.lock:
            self.hits = [t for t in self.hits if now - t < self.window]
            if len(self.hits) >= self.limit:
                return False
            self.hits.append(now)
            return True


def send_to_telegram(bot_token, chat_id, text):
    """Отдаём сообщение боту. Возвращаем (успех, пояснение)."""
    url = "%s/bot%s/sendMessage" % (API, bot_token)
    data = urllib.parse.urlencode({
        "chat_id": chat_id,
        "text": text,
        "disable_web_page_preview": "true",
    }).encode("utf-8")
    req = urllib.request.Request(url, data=data, method="POST")
    try:
        with urllib.request.urlopen(req, timeout=TIMEOUT) as resp:
            body = json.loads(resp.read().decode("utf-8", "replace"))
    except urllib.error.HTTPError as err:
        detail = err.read().decode("utf-8", "replace")[:300]
        return False, "телеграм ответил %s: %s" % (err.code, detail)
    except Exception as err:  # сеть, разбор ответа
        return False, "не вышло отправить: %s" % err
    if not body.get("ok"):
        return False, str(body.get("description") or "телеграм отклонил сообщение")
    return True, ""


class Handler(BaseHTTPRequestHandler):
    server_version = "TelegramRelay/1.0"

    def log_message(self, fmt, *args):  # пишем своим журналом, а не в stderr
        LOG.info("%s %s", self.address_string(), fmt % args)

    def _reply(self, code, payload):
        raw = json.dumps(payload, ensure_ascii=False).encode("utf-8")
        self.send_response(code)
        self.send_header("Content-Type", "application/json; charset=utf-8")
        self.send_header("Content-Length", str(len(raw)))
        self.end_headers()
        self.wfile.write(raw)

    def do_GET(self):
        # Проверка живости: без ключа и без всякой тайны.
        if self.path.rstrip("/") in ("", "/health"):
            self._reply(200, {"ok": True, "service": "telegram-relay"})
            return
        self._reply(404, {"ok": False, "error": "нет такого адреса"})

    def do_POST(self):
        if self.path.rstrip("/") != "/send":
            self._reply(404, {"ok": False, "error": "нет такого адреса"})
            return

        token = self.headers.get("X-Relay-Token", "")
        if not self.server.relay_token or token != self.server.relay_token:
            LOG.warning("отказ: неверный ключ от %s", self.address_string())
            self._reply(403, {"ok": False, "error": "неверный ключ"})
            return

        if not self.server.limiter.allow():
            self._reply(429, {"ok": False, "error": "слишком часто"})
            return

        try:
            length = int(self.headers.get("Content-Length", "0"))
        except ValueError:
            length = 0
        if length <= 0 or length > 64 * 1024:
            self._reply(400, {"ok": False, "error": "пустое или слишком большое тело"})
            return

        try:
            payload = json.loads(self.rfile.read(length).decode("utf-8", "replace"))
        except Exception:
            self._reply(400, {"ok": False, "error": "тело не разобралось как JSON"})
            return

        text = str(payload.get("text", "")).strip()
        if not text:
            self._reply(400, {"ok": False, "error": "нечего отправлять"})
            return
        if len(text) > MAX_TEXT:
            text = text[:MAX_TEXT] + "…"

        # Получателя выбираем сами: прислать чужой chat_id нельзя.
        chat = str(payload.get("chat_id", "")).strip()
        if chat and chat not in self.server.allowed_chats:
            LOG.warning("отказ: чужой получатель %s", chat)
            self._reply(403, {"ok": False, "error": "этот получатель не разрешён"})
            return
        chat = chat or self.server.default_chat

        ok, why = send_to_telegram(self.server.bot_token, chat, text)
        if ok:
            LOG.info("отправлено в %s, знаков %d", chat, len(text))
            self._reply(200, {"ok": True})
        else:
            LOG.error("не отправлено: %s", why)
            self._reply(502, {"ok": False, "error": why})


def main():
    parser = argparse.ArgumentParser(description="Пересыльщик сообщений в Telegram")
    parser.add_argument("--port", type=int, default=8020)
    parser.add_argument("--host", default="0.0.0.0")
    args = parser.parse_args()

    logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s")

    bot_token = os.environ.get("TELEGRAM_BOT_TOKEN", "").strip()
    relay_token = os.environ.get("RELAY_TOKEN", "").strip()
    default_chat = os.environ.get("TELEGRAM_CHAT_ID", "").strip()
    allowed = os.environ.get("TELEGRAM_ALLOWED_CHATS", default_chat)
    allowed_chats = set(x.strip() for x in allowed.split(",") if x.strip())

    missing = [name for name, value in (
        ("TELEGRAM_BOT_TOKEN", bot_token),
        ("RELAY_TOKEN", relay_token),
        ("TELEGRAM_CHAT_ID", default_chat),
    ) if not value]
    if missing:
        LOG.error("не заданы переменные: %s", ", ".join(missing))
        return 2
    if len(relay_token) < 24:
        LOG.error("RELAY_TOKEN короче 24 знаков — такой ключ подбирается")
        return 2

    server = ThreadingHTTPServer((args.host, args.port), Handler)
    server.bot_token = bot_token
    server.relay_token = relay_token
    server.default_chat = default_chat
    server.allowed_chats = allowed_chats
    server.limiter = Limiter(RATE_LIMIT, RATE_WINDOW)

    LOG.info("слушаем %s:%d, получателей разрешено %d", args.host, args.port, len(allowed_chats))
    try:
        server.serve_forever()
    except KeyboardInterrupt:
        LOG.info("остановка")
    finally:
        server.server_close()
    return 0


if __name__ == "__main__":
    sys.exit(main())
