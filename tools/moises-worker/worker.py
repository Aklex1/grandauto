#!/usr/bin/env python3
"""
Обработчик очереди genius-bot.ru через веб-интерфейс студии Moises.

Пока у разделения дорожек нет рабочего API, заказы с сайта выполняются
через обычный браузер. Скрипт берёт эту рутину на себя: забирает очередь,
скачивает исходник, открывает студию и отправляет готовые дорожки обратно —
пользователю они приходят в историю и на почту.

Два режима:
  assist (по умолчанию) — браузер открывается, дорожки вы делаете руками,
          скрипт сам подхватывает сохранённые файлы и закрывает заказ;
  auto   — скрипт пробует прокликать студию сам по селекторам из selectors.json.
          Разметку студии меняют часто, поэтому режим рассчитан на подстройку.

Запуск:
  pip install playwright requests && playwright install chromium
  GB_KEY=gb_... python worker.py            # assist
  GB_KEY=gb_... python worker.py --auto     # автоматический

Ключ — из личного кабинета на сайте (страница «API для разработчиков»),
выпущенный под администратором: очередь доступна только владельцу.
"""

import argparse
import json
import os
import shutil
import sys
import time
from pathlib import Path

import requests

SITE = os.environ.get("GB_SITE", "https://genius-bot.ru").rstrip("/")
KEY = os.environ.get("GB_KEY", "")
API = SITE + "/wp-json/genius/v1"
STUDIO = os.environ.get("MOISES_URL", "https://studio.moises.ai/home")

WORK = Path(os.environ.get("GB_WORK", "work")).resolve()
PROFILE = Path(os.environ.get("MOISES_PROFILE", WORK / "chrome-profile")).resolve()
POLL_SECONDS = int(os.environ.get("GB_POLL", "60"))
# Обычно браузер нужен видимый — в него вы входите руками.
# GB_HEADLESS=1 пригодится для проверок и работы без экрана.
HEADLESS = os.environ.get("GB_HEADLESS", "") == "1"
# На некоторых машинах браузер стоит отдельно от playwright.
CHROME_PATH = os.environ.get("GB_CHROME", "")
# Обычный Chrome вместо встроенного Chromium: GB_CHANNEL=chrome.
CHANNEL = os.environ.get("GB_CHANNEL", "")
# Подключение к уже запущенному браузеру, где вы вошли обычным способом.
CDP = os.environ.get("GB_CDP", "")

# Сайт отдаёт файлы через защиту хостинга: без этой куки вместо звука
# приезжает страница проверки.
COOKIES = {"beget": "begetok"}
HEAD = {"Authorization": "Bearer " + KEY}


def log(message):
    print(time.strftime("[%H:%M:%S] ") + message, flush=True)


def queue():
    r = requests.get(API + "/queue", headers=HEAD, cookies=COOKIES, timeout=60)
    if r.status_code == 401:
        sys.exit("Ключ не принят. Проверьте GB_KEY.")
    if r.status_code == 403:
        sys.exit("Ключ выпущен не под администратором — очередь ему недоступна.")
    r.raise_for_status()
    return r.json().get("orders", [])


def fetch_source(order):
    folder = WORK / order["task_id"]
    folder.mkdir(parents=True, exist_ok=True)
    source = folder / ("source" + Path(order["audio_url"]).suffix)
    if not source.exists():
        with requests.get(order["audio_url"], cookies=COOKIES, timeout=300, stream=True) as r:
            r.raise_for_status()
            with open(source, "wb") as f:
                shutil.copyfileobj(r.raw, f)
    return folder, source


def send_result(task_id, slots, folder):
    """slots: {'minus': 'Минусовка', 'vocal': 'Вокал'} → ищем файлы с такими именами."""
    files = {}
    handles = []
    for slot in slots:
        for ext in ("mp3", "wav", "m4a", "ogg"):
            candidate = folder / "out" / f"{slot}.{ext}"
            if candidate.exists():
                handle = open(candidate, "rb")
                handles.append(handle)
                files[slot] = (candidate.name, handle, "audio/mpeg")
                break
    if not files:
        return False, "в папке out нет ни одного файла"
    try:
        r = requests.post(API + "/queue/" + task_id, headers=HEAD, cookies=COOKIES, files=files, timeout=600)
    finally:
        for handle in handles:
            handle.close()
    if r.status_code != 200:
        return False, f"{r.status_code}: {r.text[:200]}"
    return True, ", ".join(files)


def mark_failed(task_id, reason):
    requests.post(API + "/queue/" + task_id + "/fail", headers=HEAD, cookies=COOKIES,
                  json={"reason": reason}, timeout=60)


def open_studio(page_url=STUDIO):
    """Постоянный профиль: в студию достаточно войти один раз, вручную.

    Вход через Google в браузере, запущенном автоматикой, часто не открывается:
    Google видит служебные флаги и молча блокирует окно. Поэтому флаги снимаем,
    а при GB_CDP вообще не запускаем свой браузер — подключаемся к вашему,
    где вы уже вошли обычным способом."""
    from playwright.sync_api import sync_playwright

    playwright = sync_playwright().start()

    if CDP:
        browser = playwright.chromium.connect_over_cdp(CDP)
        context = browser.contexts[0] if browser.contexts else browser.new_context(accept_downloads=True)
        page = context.pages[0] if context.pages else context.new_page()
        page.goto(page_url, wait_until="domcontentloaded")
        return playwright, context, page

    options = {
        "user_data_dir": str(PROFILE),
        "headless": HEADLESS,
        "accept_downloads": True,
        "viewport": {"width": 1440, "height": 900},
        # Без этих двух строк Google считает окно автоматизированным
        # и не показывает форму входа.
        "args": ["--disable-blink-features=AutomationControlled"],
        "ignore_default_args": ["--enable-automation"],
    }
    if CHANNEL:
        options["channel"] = CHANNEL
    if CHROME_PATH:
        options["executable_path"] = CHROME_PATH

    context = playwright.chromium.launch_persistent_context(**options)
    page = context.pages[0] if context.pages else context.new_page()
    page.goto(page_url, wait_until="domcontentloaded")
    return playwright, context, page


def close_studio(playwright, context):
    """Чужой браузер не закрываем — мы к нему только подключились."""
    if context and not CDP:
        context.close()
    if playwright:
        playwright.stop()


def selectors():
    path = Path(__file__).with_name("selectors.json")
    if path.exists():
        return json.loads(path.read_text(encoding="utf-8"))
    return {}


def run_auto(page, source, folder, slots):
    """Прокликивание студии по селекторам. Разметку меняют — правьте selectors.json."""
    sel = selectors()
    if not sel:
        return False, "нет selectors.json — автоматический режим не настроен"

    page.set_input_files(sel["upload_input"], str(source))
    page.wait_for_selector(sel["ready_marker"], timeout=int(sel.get("timeout_ms", 600000)))

    out = folder / "out"
    out.mkdir(exist_ok=True)
    for slot, download_selector in sel.get("downloads", {}).items():
        if slot not in slots:
            continue
        with page.expect_download(timeout=120000) as info:
            page.click(download_selector)
        info.value.save_as(str(out / f"{slot}.mp3"))
    return True, "дорожки скачаны"


def run_assist(order, folder, source, page):
    out = folder / "out"
    out.mkdir(exist_ok=True)
    names = ", ".join(f"{slot}.mp3" for slot in order["slots"])
    log(f"Заказ {order['task_id']} ({order['service']}, {order['seconds']} сек)")
    log(f"  исходник: {source}")
    log(f"  загрузите его в открытой студии, а готовые дорожки сохраните как: {names}")
    log(f"  в папку: {out}")
    log("  как только файлы появятся — отправлю их пользователю сам")

    waited = 0
    while waited < 3600:
        if any((out / f"{slot}.{ext}").exists()
               for slot in order["slots"] for ext in ("mp3", "wav", "m4a", "ogg")):
            time.sleep(3)  # даём файлу дописаться
            return True
        time.sleep(5)
        waited += 5
    return False


def resolve_file(raw):
    """Путь может быть без расширения или с приблизительным именем —
    ищем подходящий файл рядом, чтобы не спотыкаться на мелочи."""
    path = Path(raw).expanduser()
    if path.exists():
        return path
    folder = path.parent if str(path.parent) not in ("", ".") else Path.cwd()
    stem = path.name.lower()
    if folder.is_dir():
        for candidate in sorted(folder.iterdir()):
            name = candidate.name.lower()
            if candidate.is_file() and (name.startswith(stem) or stem in name):
                if candidate.suffix.lower() in (".mp3", ".wav", ".flac", ".m4a", ".ogg"):
                    return candidate
    sys.exit(f"Не нашёл файл: {raw}")


def inspect_studio(url):
    """Один раз проходим по странице студии и записываем, за что можно зацепиться.

    Разметку студии я подобрать не могу — доступа к аккаунту нет. Этот режим
    собирает кандидатов сам: поля загрузки, кнопки и ссылки с их текстом."""
    playwright, context, page = open_studio(url)
    log("Открыл студию. Войдите в аккаунт и дойдите до экрана загрузки трека.")
    if not HEADLESS:
        input("Когда нужный экран открыт — нажмите Enter здесь... ")

    dump = page.evaluate("""() => {
        const describe = (el) => {
            const attr = (n) => el.getAttribute(n);
            const id = attr('id');
            const testid = attr('data-testid') || attr('data-test-id') || attr('data-cy');
            const cls = (el.className && typeof el.className === 'string')
                ? '.' + el.className.trim().split(/\s+/).slice(0, 3).join('.') : '';
            return {
                tag: el.tagName.toLowerCase(),
                text: (el.innerText || el.value || '').trim().slice(0, 60),
                id: id || '',
                testid: testid || '',
                selector: testid ? `[data-testid="${testid}"]` : (id ? `#${id}` : el.tagName.toLowerCase() + cls),
                visible: !!(el.offsetWidth || el.offsetHeight),
            };
        };
        const pick = (sel) => Array.from(document.querySelectorAll(sel)).map(describe);
        return {
            url: location.href,
            inputs: pick('input[type=file]'),
            buttons: pick('button, [role=button], a').filter((b) => b.text).slice(0, 120),
        };
    }""")

    out = Path(__file__).with_name("studio-dump.json")
    out.write_text(json.dumps(dump, ensure_ascii=False, indent=2), encoding="utf-8")
    log(f"Записал {out}")
    log("Пришлите этот файл — по нему соберу selectors.json для автоматического режима.")
    close_studio(playwright, context)


def run_single_file(raw_path, url, auto):
    """Разовая проверка без очереди: обработать один файл с диска."""
    source = resolve_file(raw_path)
    folder = WORK / "single"
    (folder / "out").mkdir(parents=True, exist_ok=True)
    log(f"Файл: {source} ({source.stat().st_size // 1024} КБ)")

    playwright, context, page = open_studio(url)
    slots = {"minus": "Минусовка", "vocal": "Вокал"}
    try:
        if auto:
            ok, message = run_auto(page, source, folder, slots)
            log(f"автоматический режим: {message}")
            if not ok:
                auto = False
        if not auto:
            log(f"Загрузите файл в студии и сохраните минус как {folder / 'out' / 'minus.mp3'}")
            input("Когда файл сохранён — нажмите Enter... ")
        saved = sorted((folder / "out").glob("*"))
        log("Получено: " + (", ".join(f.name for f in saved) if saved else "ничего"))
    finally:
        close_studio(playwright, context)


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--auto", action="store_true", help="прокликивать студию по selectors.json")
    parser.add_argument("--once", action="store_true", help="обработать одну партию и выйти")
    parser.add_argument("--file", help="разовая проверка: обработать файл с диска, без очереди сайта")
    parser.add_argument("--inspect", action="store_true",
                        help="собрать со страницы студии кандидатов в селекторы и сохранить в studio-dump.json")
    parser.add_argument("--url", default=STUDIO, help="адрес страницы студии")
    args = parser.parse_args()

    # Проверочные режимы к сайту не обращаются — ключ им не нужен.
    if args.inspect:
        return inspect_studio(args.url)
    if args.file:
        return run_single_file(args.file, args.url, args.auto)

    if not KEY:
        sys.exit("Не задан GB_KEY — ключ доступа с правами владельца сайта.")
    WORK.mkdir(parents=True, exist_ok=True)

    playwright = context = page = None
    try:
        while True:
            orders = queue()
            if not orders:
                log("очередь пуста")
            for order in orders:
                folder, source = fetch_source(order)
                if page is None:
                    playwright, context, page = open_studio()

                if args.auto:
                    ok, message = run_auto(page, source, folder, order["slots"])
                    if not ok:
                        log(f"  автоматический режим не справился: {message}")
                        ok = run_assist(order, folder, source, page)
                else:
                    ok = run_assist(order, folder, source, page)

                if not ok:
                    log("  файлы не дождались — заказ оставлен в очереди")
                    continue

                sent, message = send_result(order["task_id"], order["slots"], folder)
                log(f"  отправлено: {message}" if sent else f"  не отправлено: {message}")

            if args.once:
                break
            time.sleep(POLL_SECONDS)
    finally:
        close_studio(playwright, context)


if __name__ == "__main__":
    main()
