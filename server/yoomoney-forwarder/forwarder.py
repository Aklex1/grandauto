#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Раздатчик уведомлений ЮMoney.

У кошелька один адрес для уведомлений, а получателей два: служба бота и
сайт. Кто бы ни стоял первым, платежи второго теряются — так и вышло:
адрес указывал на бота, и пополнения с сайта не доходили никуда, потому
что бот отвечает «OK» на любую метку, включая чужую.

Эта служба принимает уведомление и отдаёт его обоим, слово в слово.
Подпись при этом остаётся верной: тело запроса не меняется.

Своей логики здесь нет намеренно — ни разбора меток, ни начислений.
Раздатчик, который умеет думать, однажды начнёт думать неправильно, и
разбираться придётся в трёх местах вместо двух.

Запуск:
    python3 forwarder.py --port 8080 \
        --target http://127.0.0.1:8000/yoomoney-webhook \
        --target https://genius-bot.ru/wp-json/genius/v1/yoomoney
"""

import argparse
import logging
import sys
import threading
import urllib.error
import urllib.parse
import urllib.request
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

LOG = logging.getLogger("yoomoney-forwarder")

# Больше уведомление никто не пришлёт: ЮMoney повторяет попытки, но
# рассчитывать на это нельзя. Поэтому отвечаем сразу, а рассылаем в
# сторонке — медленный получатель не должен ронять быстрого.
TIMEOUT = 20


def deliver(url, body, headers):
    request = urllib.request.Request(url, data=body, method="POST")
    for name, value in headers.items():
        request.add_header(name, value)
    try:
        with urllib.request.urlopen(request, timeout=TIMEOUT) as response:
            LOG.info("доставлено %s: код %s", url, response.status)
    except urllib.error.HTTPError as e:
        LOG.warning("отказ %s: код %s %s", url, e.code, e.read()[:200])
    except Exception as e:  # сеть, таймаут, имя не разрешилось
        LOG.warning("не доставлено %s: %s", url, e)


class Handler(BaseHTTPRequestHandler):
    server_version = "yoomoney-forwarder/1.0"
    targets = []

    def do_POST(self):
        length = int(self.headers.get("Content-Length") or 0)
        body = self.rfile.read(length) if length else b""

        # Тип содержимого передаём как есть: ЮMoney шлёт форму, и
        # получатели разбирают её именно так.
        headers = {
            "Content-Type": self.headers.get("Content-Type", "application/x-www-form-urlencoded"),
            "User-Agent": "yoomoney-forwarder",
        }

        label = ""
        for pair in body.decode("utf-8", "replace").split("&"):
            if pair.startswith("label="):
                label = urllib.parse.unquote_plus(pair[6:])
                break
        LOG.info("уведомление: метка %s, байт %d", label or "—", len(body))

        for url in self.targets:
            threading.Thread(target=deliver, args=(url, body, headers), daemon=True).start()

        self.send_response(200)
        self.send_header("Content-Type", "text/plain; charset=utf-8")
        self.end_headers()
        self.wfile.write(b"OK")

    def do_GET(self):
        # Проверка живости: ЮMoney при сохранении адреса дёргает его.
        self.send_response(200)
        self.send_header("Content-Type", "text/plain; charset=utf-8")
        self.end_headers()
        self.wfile.write(b"yoomoney-forwarder\n")

    def log_message(self, fmt, *args):
        LOG.debug(fmt, *args)


def main():
    parser = argparse.ArgumentParser(description="Раздатчик уведомлений ЮMoney")
    parser.add_argument("--port", type=int, default=8080)
    parser.add_argument("--host", default="0.0.0.0")
    parser.add_argument("--target", action="append", required=True,
                        help="адрес получателя, можно указать несколько раз")
    args = parser.parse_args()

    logging.basicConfig(
        level=logging.INFO,
        format="%(asctime)s %(levelname)s %(message)s",
        stream=sys.stdout,
    )

    Handler.targets = args.target
    LOG.info("слушаю %s:%d, получателей %d", args.host, args.port, len(args.target))
    for url in args.target:
        LOG.info("  → %s", url)

    ThreadingHTTPServer((args.host, args.port), Handler).serve_forever()


if __name__ == "__main__":
    main()
