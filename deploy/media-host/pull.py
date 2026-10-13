#!/usr/bin/env python3
"""
Перенос файлов каталога звуков с сайта на этот сервер.

Качаем по списку из каталога, а не обходом папок: обход по FTP с шареда
медленный и неполный, а список — то, на что сайт реально ссылается.
Файл, который уже лежит и совпал по размеру, пропускаем, поэтому
прогон можно прерывать и запускать заново.

    python3 pull.py                 # всё
    python3 pull.py --dry           # только посчитать
    python3 pull.py --workers 8
"""
import argparse, json, os, sys, time, urllib.request, urllib.error
from concurrent.futures import ThreadPoolExecutor

SITE = os.environ.get("SITE", "https://genius-bot.ru")
BASE = SITE + "/wp-content/uploads/genius-sounds"
ROOT = os.environ.get("ROOT", "/srv/media")
UA = {"User-Agent": "genius-media-sync/1.0"}


def fetch(url, timeout=180):
    for attempt in range(4):
        try:
            with urllib.request.urlopen(urllib.request.Request(url, headers=UA), timeout=timeout) as r:
                return r.read()
        except Exception as e:
            if attempt == 3:
                raise
            time.sleep(2 ** attempt)


def plan():
    """Список файлов: путь внутри files/ и ожидаемый размер."""
    cats = json.loads(fetch(BASE + "/index.json"))
    want = {}
    for cat in cats:
        slug = cat.get("slug")
        if not slug:
            continue
        try:
            data = json.loads(fetch("%s/cats/%s.json" % (BASE, slug)))
        except Exception as e:
            print("раздел %s не прочитался: %s" % (slug, e), file=sys.stderr)
            continue
        items = data if isinstance(data, list) else (data.get("items") or data.get("sounds") or [])
        for it in items:
            rel = (it or {}).get("file")
            if rel:
                want[rel] = int((it or {}).get("size") or 0)
    return want


def grab(rel, size):
    dst = os.path.join(ROOT, "files", rel)
    if os.path.exists(dst) and (size == 0 or os.path.getsize(dst) == size):
        return 0
    os.makedirs(os.path.dirname(dst), exist_ok=True)
    data = fetch("%s/files/%s" % (BASE, rel))
    tmp = dst + ".part"
    with open(tmp, "wb") as f:
        f.write(data)
    os.replace(tmp, dst)
    return len(data)


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--dry", action="store_true")
    ap.add_argument("--workers", type=int, default=6)
    a = ap.parse_args()

    want = plan()
    total = sum(want.values())
    print("в каталоге файлов: %d, по размеру %.1f ГБ" % (len(want), total / 1073741824))
    if a.dry:
        return

    done = {"n": 0, "bytes": 0, "err": 0}
    t0 = time.time()

    def one(item):
        rel, size = item
        try:
            got = grab(rel, size)
        except Exception as e:
            done["err"] += 1
            print("СБОЙ %s: %s" % (rel, str(e)[:90]), file=sys.stderr)
            return
        done["n"] += 1
        done["bytes"] += got
        if done["n"] % 200 == 0:
            el = time.time() - t0
            print("%d из %d, скачано %.1f ГБ, %.0f c" % (done["n"], len(want), done["bytes"] / 1073741824, el), flush=True)

    with ThreadPoolExecutor(max_workers=a.workers) as pool:
        list(pool.map(one, want.items()))

    print("готово: файлов %d, скачано %.1f ГБ, сбоев %d, за %.0f c"
          % (done["n"], done["bytes"] / 1073741824, done["err"], time.time() - t0))


if __name__ == "__main__":
    main()
