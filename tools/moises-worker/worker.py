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
# К настольному приложению подключаемся как есть: уводить его с текущего
# экрана переходом по адресу нельзя — там нет привычной адресной строки.
KEEP_PAGE = os.environ.get("GB_KEEP_PAGE", "") == "1"

# Сайт отдаёт файлы через защиту хостинга: без этой куки вместо звука
# приезжает страница проверки.
COOKIES = {"beget": "begetok"}
HEAD = {"Authorization": "Bearer " + KEY}


def wait_enter(prompt):
    """Пауза до нажатия Enter. В консоли без ввода просто идём дальше."""
    try:
        input(prompt)
    except EOFError:
        pass


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
        try:
            browser = playwright.chromium.connect_over_cdp(CDP)
            context = browser.contexts[0] if browser.contexts else browser.new_context(accept_downloads=True)
            page = context.pages[0] if context.pages else context.new_page()
            # У настольного приложения адрес вида file:// или app:// —
            # это его собственный экран, переходить никуда не нужно.
            current = (page.url or "")
            if not KEEP_PAGE and current.startswith("http"):
                page.goto(page_url, wait_until="domcontentloaded")
            else:
                log(f"  подключился к готовому окну: {current[:60] or 'без адреса'}")
            page.bring_to_front()
            return playwright, context, page
        except Exception as error:
            # Частый случай: переменная осталась с прошлого запуска,
            # а браузер с отладочным портом уже закрыт.
            log(f"Не подключился к браузеру на {CDP}: {type(error).__name__}")
            log("  либо запустите Chrome с ключом --remote-debugging-port=9222,")
            log("  либо очистите переменную: set GB_CDP=")
            log("  пока открою свой браузер — в нём нужен вход в студию")
            globals()["CDP"] = ""

    options = {
        "user_data_dir": str(PROFILE),
        "headless": HEADLESS,
        "accept_downloads": True,
        "viewport": {"width": 1440, "height": 900},
        # Без этих двух строк Google считает окно автоматизированным
        # и не показывает форму входа.
        "args": [
            "--disable-blink-features=AutomationControlled",
            # В неактивном окне Chrome тормозит таймеры, и студия
            # застревает на «идёт разделение».
            "--disable-background-timer-throttling",
            "--disable-backgrounding-occluded-windows",
            "--disable-renderer-backgrounding",
        ],
        "ignore_default_args": ["--enable-automation"],
    }
    if CHANNEL:
        options["channel"] = CHANNEL
    if CHROME_PATH:
        options["executable_path"] = CHROME_PATH

    context = playwright.chromium.launch_persistent_context(**options)
    page = context.pages[0] if context.pages else context.new_page()
    page.goto(page_url, wait_until="domcontentloaded")
    page.bring_to_front()
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


def upload_track(page, source, sel):
    """Поле загрузки у студии спрятано за кнопкой, поэтому сначала пробуем
    честный диалог выбора файла и только потом — прямую подстановку."""
    button = sel.get("upload_button", "")
    if button:
        try:
            with page.expect_file_chooser(timeout=15000) as chooser:
                page.click(button, timeout=10000)
            chooser.value.set_files(str(source))
            return True, "файл отдан через диалог выбора"
        except Exception as error:
            log(f"  через диалог не вышло ({type(error).__name__}), пробую поле напрямую")

    page.locator(sel.get("upload_input", "input[type=file]")).first.set_input_files(str(source))
    return True, "файл подставлен в поле загрузки"


def wait_ready(page, marker, timeout_ms):
    """Ждём готовности, а на полпути один раз перезагружаем страницу:
    вкладка, открытая до конца обработки, иногда так и висит на прогрессе."""
    half = max(30000, timeout_ms // 2)
    try:
        page.wait_for_selector(marker, timeout=half)
        return True
    except Exception:
        log("  готовность не появилась — перезагружаю страницу")

    page.reload(wait_until="domcontentloaded")
    page.bring_to_front()
    try:
        page.wait_for_selector(marker, timeout=timeout_ms - half)
        return True
    except Exception:
        return False


def run_auto(page, source, folder, slots):
    """Прокликивание студии по селекторам. Разметку меняют — правьте selectors.json."""
    sel = selectors()
    if not sel:
        return False, "нет selectors.json — автоматический режим не настроен"

    ok, message = upload_track(page, source, sel)
    log("  " + message)
    if not ok:
        return False, "не удалось загрузить файл"

    # Пока не известны признак готовности и кнопки скачивания, дальше
    # дорожки сохраняет человек — загрузку мы уже сняли с него.
    marker = sel.get("ready_marker", "")
    downloads = sel.get("downloads", {})
    if not marker or not downloads:
        return False, "загрузка сделана; признак готовности и кнопки скачивания ещё не настроены"

    if not wait_ready(page, marker, int(sel.get("timeout_ms", 900000))):
        return False, "студия так и не показала готовый трек"


    out = folder / "out"
    out.mkdir(exist_ok=True)
    saved = []
    for slot, download_selector in downloads.items():
        if slot not in slots:
            continue
        with page.expect_download(timeout=180000) as info:
            page.click(download_selector)
        download = info.value
        target = out / (slot + Path(download.suggested_filename).suffix)
        download.save_as(str(target))
        saved.append(target.name)
    if not saved:
        return False, "кнопки скачивания не сработали"
    return True, "скачано: " + ", ".join(saved)


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


def resolve_shortcut(path):
    """Windows-ярлык (.lnk) хранит путь к программе внутри себя —
    достаём его через PowerShell, чтобы не искать exe руками."""
    import subprocess
    script = (
        "$s=(New-Object -COM WScript.Shell).CreateShortcut('" + str(path).replace("'", "''") + "');"
        "Write-Output $s.TargetPath"
    )
    try:
        out = subprocess.run(["powershell", "-NoProfile", "-Command", script],
                             capture_output=True, text=True, timeout=30)
        target = (out.stdout or "").strip().splitlines()
        return target[0] if target else ""
    except Exception as error:
        log(f"  не удалось прочитать ярлык: {type(error).__name__}")
        return ""


def find_app(hint=""):
    """Ищем программу: по подсказке, по ярлыку рядом со скриптом,
    затем в обычных местах установки."""
    if hint:
        path = Path(hint)
        if path.suffix.lower() == ".lnk":
            target = resolve_shortcut(path)
            if target:
                return target
        if path.exists():
            return str(path)

    here = Path(__file__).parent
    for link in sorted(here.glob("*.lnk")):
        target = resolve_shortcut(link)
        if target and Path(target).exists():
            log(f"  нашёл по ярлыку {link.name}")
            return target

    local = os.environ.get("LOCALAPPDATA", "")
    guesses = []
    if local:
        for name in ("moises", "Moises", "moises-desktop"):
            guesses.append(Path(local) / "Programs" / name / "Moises.exe")
    for guess in guesses:
        if guess.exists():
            return str(guess)
    return ""


def wait_for_port(url, seconds=40):
    """Приложению нужно время, чтобы поднять отладочный порт."""
    import urllib.request
    deadline = time.time() + seconds
    while time.time() < deadline:
        try:
            with urllib.request.urlopen(url.rstrip("/") + "/json/version", timeout=3) as r:
                if r.status == 200:
                    return True
        except Exception:
            time.sleep(1.5)
    return False


def launch_app(hint=""):
    """Запускаем настольное приложение с отладочным портом и дожидаемся его."""
    import subprocess
    app = find_app(hint)
    if not app:
        log("Не нашёл программу. Укажите путь: --launch-app \"C:\\путь\\Moises.exe\"")
        return ""
    port = os.environ.get("GB_PORT", "9222")
    url = f"http://127.0.0.1:{port}"
    log(f"Запускаю: {app}")
    try:
        subprocess.Popen([app, f"--remote-debugging-port={port}"],
                         stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    except Exception as error:
        log(f"  не запустилось: {error}")
        return ""
    if not wait_for_port(url):
        log("  отладочный порт так и не открылся — возможно, приложение не на Electron")
        return ""
    log(f"  подключаюсь к {url}")
    return url


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
        wait_enter("Когда нужный экран открыт — нажмите Enter здесь... ")

    dump = page.evaluate("""() => {
        const describe = (el) => {
            const attr = (n) => el.getAttribute(n);
            const id = attr('id');
            const testid = attr('data-testid') || attr('data-test-id') || attr('data-cy');
            const label = attr('aria-label') || '';
            const title = attr('title') || '';
            const text = (el.innerText || el.value || '').trim().slice(0, 60);
            // Классы у студии генерируемые, цепляться за них нельзя —
            // поэтому предпочитаем подпись и текст.
            let selector = el.tagName.toLowerCase();
            if (testid) {
                selector = `[data-testid="${testid}"]`;
            } else if (id && !/^radix-/.test(id)) {
                selector = `#${id}`;
            } else if (label) {
                selector = `${el.tagName.toLowerCase()}[aria-label="${label}"]`;
            } else if (text) {
                selector = `${el.tagName.toLowerCase()}:has-text("${text.split(String.fromCharCode(10))[0]}")`;
            }
            return {
                tag: el.tagName.toLowerCase(),
                text: text,
                label: label,
                title: title,
                id: id || '',
                testid: testid || '',
                selector: selector,
                visible: !!(el.offsetWidth || el.offsetHeight),
            };
        };
        const pick = (sel) => Array.from(document.querySelectorAll(sel)).map(describe);
        const interesting = /скач|загруз|download|export|экспорт|минус|instrument|вокал|vocal|stem|дорожк|сохран/i;
        return {
            url: location.href,
            inputs: pick('input[type=file]'),
            buttons: pick('button, [role=button], [role=menuitem], a').filter((b) => b.text).slice(0, 150),
            // Кнопки скачивания часто без текста — только значок с подписью для читалок.
            labelled: pick('[aria-label], [title]').filter((b) => interesting.test(b.label || b.title || b.text)).slice(0, 80),
            dialogs: pick('[role=dialog] button, [role=dialog] [role=menuitem]').filter((b) => b.text).slice(0, 60),
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
            wait_enter("Когда файл сохранён — нажмите Enter... ")
        saved = sorted((folder / "out").glob("*"))
        log("Получено: " + (", ".join(f.name for f in saved) if saved else "ничего"))
    finally:
        close_studio(playwright, context)


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--auto", action="store_true", help="прокликивать студию по selectors.json")
    parser.add_argument("--once", action="store_true", help="обработать одну партию и выйти")
    parser.add_argument("--file", help="разовая проверка: обработать файл с диска, без очереди сайта")
    parser.add_argument("--launch-app", nargs="?", const="", default=None,
                        help="запустить настольное приложение с отладочным портом и подключиться к нему")
    parser.add_argument("--keep-page", action="store_true",
                        help="не переходить по адресу: разбирать окно как есть (для настольного приложения)")
    parser.add_argument("--inspect", action="store_true",
                        help="собрать со страницы студии кандидатов в селекторы и сохранить в studio-dump.json")
    parser.add_argument("--url", default=STUDIO, help="адрес страницы студии")
    args = parser.parse_args()
    if args.keep_page:
        globals()["KEEP_PAGE"] = True

    if args.launch_app is not None:
        url = launch_app(args.launch_app)
        if not url:
            sys.exit("Приложение не поднялось с отладочным портом.")
        globals()["CDP"] = url
        globals()["KEEP_PAGE"] = True

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
