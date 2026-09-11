"""Вставляет пересылку платежей сайта в Flask-обработчик KIE-бота.

Сделано по образцу patch_kie_webhook.py из AI-коуча: тот же маршрут, тот же
приём — блок в начале функции, ранний возврат, логика бота не трогается.

Что делает:
  1. находит маршрут @app.route('/yoomoney-webhook', ...) и его функцию;
  2. добавляет импорт из site_relay_snippet после последнего импорта файла;
  3. вставляет пять строк в начало тела функции;
  4. сохраняет резервную копию <файл>.bak и печатает разницу.

Повторный запуск ничего не меняет. Блок пересылки коучу, если он уже стоит,
остаётся на месте: метки у проектов разные, и каждый блок отвечает за свои.

Запуск (сначала посмотреть, потом применить):
    python3 patch_kie_webhook_site.py /opt/src/kie_ai_bot/<файл>.py --dry-run
    python3 patch_kie_webhook_site.py /opt/src/kie_ai_bot/<файл>.py

Откат:
    mv /opt/src/kie_ai_bot/<файл>.py.bak /opt/src/kie_ai_bot/<файл>.py
"""
from __future__ import annotations

import argparse
import difflib
import re
import shutil
import sys
from pathlib import Path

IMPORT_LINE = "from site_relay_snippet import is_site_payment, relay_to_site_sync"
MARKER = "is_site_payment"

ROUTE_RE = re.compile(r"^\s*@app\.route\(\s*['\"](?P<path>[^'\"]*yoomoney[^'\"]*)['\"]")
DEF_RE = re.compile(r"^(?P<indent>\s*)def\s+(?P<name>\w+)\s*\(")


def build_block(indent: str) -> list[str]:
    return [
        f"{indent}# --- пересылка платежей сайта genius-bot.ru (см. site_relay_snippet.py) ---\n",
        f"{indent}_raw_body_site = request.get_data()\n",
        f"{indent}if is_site_payment(request.form.get('label')):\n",
        f"{indent}    relay_to_site_sync(_raw_body_site, request.content_type)\n",
        f"{indent}    return 'ok', 200\n",
        f"{indent}# --- конец пересылки ---\n",
        "\n",
    ]


def insert_import(lines: list[str]) -> list[str]:
    if any(IMPORT_LINE in line for line in lines):
        return lines
    last_import = -1
    for index, line in enumerate(lines[:120]):
        if re.match(r"^(import |from )\S", line):
            last_import = index
    position = last_import + 1 if last_import >= 0 else 0
    return lines[:position] + [IMPORT_LINE + "\n"] + lines[position:]


def find_handler(lines: list[str], route: str) -> tuple[int, str, str]:
    """Возвращает (номер строки первой строки тела, отступ тела, имя функции)."""
    for index, line in enumerate(lines):
        match = ROUTE_RE.match(line)
        if not match or match.group("path") != route:
            continue
        for offset in range(index + 1, min(index + 12, len(lines))):
            def_match = DEF_RE.match(lines[offset])
            if not def_match:
                continue
            head = offset
            while head < len(lines) and not lines[head].rstrip().endswith(":"):
                head += 1
            body = head + 1
            stripped = lines[body].strip() if body < len(lines) else ""
            if stripped.startswith(('"""', "'''")):
                quote = stripped[:3]
                if not (stripped.endswith(quote) and len(stripped) > 3):
                    body += 1
                    while body < len(lines) and quote not in lines[body]:
                        body += 1
                body += 1
            indent = re.match(r"\s*", lines[body]).group(0) if body < len(lines) else "    "
            return body, indent or "    ", def_match.group("name")
    raise SystemExit(f"Маршрут {route} с функцией на Flask в файле не найден")


def main() -> None:
    parser = argparse.ArgumentParser(description="Вставить пересылку платежей сайта")
    parser.add_argument("target", type=Path, help="файл Flask-приложения")
    parser.add_argument("--route", default="/yoomoney-webhook", help="путь маршрута")
    parser.add_argument("--dry-run", action="store_true", help="только показать разницу")
    args = parser.parse_args()

    if not args.target.is_file():
        raise SystemExit(f"Нет файла: {args.target}")

    original = args.target.read_text(encoding="utf-8").splitlines(keepends=True)
    if any(MARKER in line for line in original):
        print("Пересылка сайта уже вставлена — файл не меняю.")
        return

    body_index, indent, name = find_handler(original, args.route)
    patched = original[:body_index] + build_block(indent) + original[body_index:]
    patched = insert_import(patched)

    diff = difflib.unified_diff(original, patched, fromfile=str(args.target),
                                tofile=str(args.target) + " (после правки)", n=2)
    sys.stdout.writelines(diff)

    if args.dry_run:
        print(f"\n--dry-run: файл не изменён. Функция обработчика: {name}()")
        return

    shutil.copy2(args.target, args.target.with_suffix(args.target.suffix + ".bak"))
    args.target.write_text("".join(patched), encoding="utf-8")
    print(f"\nГотово. Резервная копия: {args.target}.bak")
    print(f"Проверьте синтаксис:  python3 -m py_compile {args.target}")
    print("Затем перезапустите KIE-бота обычной командой.")


if __name__ == "__main__":
    main()
