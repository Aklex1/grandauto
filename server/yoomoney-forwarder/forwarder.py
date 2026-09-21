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
import os
import sys
import threading
import time
import urllib.error
import urllib.parse
import urllib.request
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

LOG = logging.getLogger("yoomoney-forwarder")

# Больше уведомление никто не пришлёт: ЮMoney повторяет попытки, но
# рассчитывать на это нельзя. Поэтому отвечаем сразу, а рассылаем в
# сторонке — медленный получатель не должен ронять быстрого.
TIMEOUT = 20

# Получатель бывает занят перезапуском ровно в ту секунду, когда пришли
# деньги. Пробуем ещё несколько раз, разнося попытки во времени.
RETRIES = (0, 5, 30, 120)

# Куда складывать то, что не приняли. Раз ЮMoney мы уже ответили «принято»,
# повторить она не может — значит, тело обязаны сохранить мы. Иначе платёж
# восстанавливать не из чего: ровно так и потерялись 250 ₽ 21 сентября.
SPOOL = os.environ.get("STATE_DIRECTORY", "").split(":")[0] or "/var/lib/yoomoney-forwarder"


def spool_path(url):
    safe = "".join(c if c.isalnum() else "-" for c in url)[-60:]
    return os.path.join(SPOOL, "failed", "%d-%s.body" % (time.time() * 1000, safe))


def keep(url, body, why):
    """Сохранить непринятое уведомление, чтобы его можно было переотправить."""
    try:
        path = spool_path(url)
        os.makedirs(os.path.dirname(path), exist_ok=True)
        with open(path, "wb") as f:
            f.write(body)
        with open(path + ".meta", "w", encoding="utf-8") as f:
            f.write("%s\n%s\n" % (url, why))
        LOG.error("НЕ ПРИНЯТО %s (%s). Тело сохранено: %s", url, why, path)
    except Exception as e:
        LOG.error("НЕ ПРИНЯТО %s (%s), и сохранить не удалось: %s", url, why, e)


def post(url, body, headers):
    request = urllib.request.Request(url, data=body, method="POST")
    for name, value in headers.items():
        request.add_header(name, value)
    with urllib.request.urlopen(request, timeout=TIMEOUT) as response:
        return response.status, response.read()[:200]


def deliver(url, body, headers):
    why = "причина неизвестна"
    for attempt, pause in enumerate(RETRIES, start=1):
        if pause:
            time.sleep(pause)
        try:
            status, answer = post(url, body, headers)
            LOG.info("доставлено %s: код %s%s", url, status,
                     "" if attempt == 1 else " (с %d-й попытки)" % attempt)
            return
        except urllib.error.HTTPError as e:
            why = "код %s %s" % (e.code, e.read()[:200])
            # Отказ по сути запроса повторять бессмысленно: подпись или
            # адрес не станут другими от ожидания.
            if e.code in (400, 401, 403, 404, 405, 409, 422):
                break
        except Exception as e:  # сеть, таймаут, имя не разрешилось
            why = str(e)
        LOG.warning("не принято %s: %s (попытка %d)", url, why, attempt)
    keep(url, body, why)


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
        # Заодно показываем, сколько уведомлений не приняли получатели, —
        # иначе об этом узнаёшь, только когда придёт жаловаться человек.
        failed = 0
        try:
            failed = len([n for n in os.listdir(os.path.join(SPOOL, "failed"))
                          if n.endswith(".body")])
        except OSError:
            pass
        self.send_response(200)
        self.send_header("Content-Type", "text/plain; charset=utf-8")
        self.end_headers()
        self.wfile.write(("yoomoney-forwarder\nне принято: %d\n" % failed).encode())

    def log_message(self, fmt, *args):
        LOG.debug(fmt, *args)


def replay():
    """Переотправить всё, что не приняли. Принятое удаляем, остальное ждёт."""
    folder = os.path.join(SPOOL, "failed")
    names = sorted(n for n in os.listdir(folder)) if os.path.isdir(folder) else []
    bodies = [n for n in names if n.endswith(".body")]
    if not bodies:
        LOG.info("переотправлять нечего")
        return
    for name in bodies:
        path = os.path.join(folder, name)
        url = ""
        try:
            with open(path + ".meta", encoding="utf-8") as f:
                url = f.readline().strip()
        except OSError:
            LOG.warning("%s: нет записи об адресе, пропускаю", name)
            continue
        with open(path, "rb") as f:
            body = f.read()
        try:
            status, answer = post(url, body, {
                "Content-Type": "application/x-www-form-urlencoded",
                "User-Agent": "yoomoney-forwarder",
            })
            LOG.info("переотправлено %s -> %s: код %s %s", name, url, status, answer)
            os.remove(path)
            os.remove(path + ".meta")
        except Exception as e:
            LOG.warning("снова не принято %s -> %s: %s", name, url, e)


def main():
    parser = argparse.ArgumentParser(description="Раздатчик уведомлений ЮMoney")
    parser.add_argument("--port", type=int, default=8090)
    parser.add_argument("--host", default="0.0.0.0")
    parser.add_argument("--target", action="append",
                        help="адрес получателя, можно указать несколько раз")
    parser.add_argument("--replay", action="store_true",
                        help="переотправить непринятые уведомления и выйти")
    args = parser.parse_args()

    logging.basicConfig(
        level=logging.INFO,
        format="%(asctime)s %(levelname)s %(message)s",
        stream=sys.stdout,
    )

    if args.replay:
        replay()
        return

    if not args.target:
        parser.error("нужен хотя бы один --target")

    Handler.targets = args.target
    LOG.info("слушаю %s:%d, получателей %d", args.host, args.port, len(args.target))
    for url in args.target:
        LOG.info("  → %s", url)

    try:
        server = ThreadingHTTPServer((args.host, args.port), Handler)
    except OSError as e:
        # Занятый порт — самая частая осечка при установке: на сервере уже
        # живут другие службы. Говорим об этом словами, а не трассировкой.
        LOG.error("не удалось занять %s:%d — %s", args.host, args.port, e)
        LOG.error("посмотрите, кто там сидит: ss -tlnp | grep :%d", args.port)
        sys.exit(1)
    server.serve_forever()


if __name__ == "__main__":
    main()
