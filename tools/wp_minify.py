# -*- coding: utf-8 -*-
"""Минификация HTML под вставку в контент WordPress.

Тема прогоняет контент через wpautop: он реагирует на переводы строк —
двойные превращает в <p>, одиночные в <br>, ломая и <style>, и структуру
карточек. Убираем переносы строк между тегами (внутри <pre>/<script>/<style>
переносы схлопываем тоже, но их содержимое не разбиваем), и wpautop больше
не за что зацепиться.
"""
import re


def minify(html: str) -> str:
    # защищаем <pre> и <script>/<style>: сохраняем как плейсхолдеры
    stash = []

    def keep(m):
        stash.append(m.group(0))
        return f"\x00{len(stash)-1}\x00"

    html = re.sub(r"(?s)<pre\b.*?</pre>", keep, html)
    html = re.sub(r"(?s)<script\b.*?</script>", keep, html)
    html = re.sub(r"(?s)<style\b.*?</style>", keep, html)

    # схлопываем пробелы между тегами и убираем переносы строк
    html = re.sub(r">\s+<", "><", html)
    html = re.sub(r"[\r\n]+", " ", html)
    html = re.sub(r"[ \t]{2,}", " ", html)

    # возвращаем защищённые блоки, у style/script убираем переносы внутри
    def restore(m):
        block = stash[int(m.group(1))]
        if block[:5].lower() in ("<styl", "<scri"):
            # внутри CSS/JS переносы не нужны для wpautop, схлопнем в пробел
            block = re.sub(r"[\r\n]+", " ", block)
            block = re.sub(r"[ \t]{2,}", " ", block)
        return block

    html = re.sub(r"\x00(\d+)\x00", restore, html)
    return html.strip()


if __name__ == "__main__":
    import sys
    print(minify(open(sys.argv[1], encoding="utf-8").read()))
