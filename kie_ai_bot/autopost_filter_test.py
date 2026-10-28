"""Проверка отсева постов автопостинга: надписи на фото и поиск промпта.

Фильтр надписей однажды уже начал отсекать чистые фотографии: tesseract
на гладком фоне «читал» собственный шум вроде «oa ee | Le } а й РЕ ey SIN»,
это семнадцать букв при пороге в четыре — и пост уходил в пропуск. Проверки
ниже держат оба случая: шум проходит, настоящая надпись отсекается.
"""

import pathlib
import sys
import types

# Заглушки вместо aiogram, httpx и телетона: правила их не трогают.
for name in ("aiogram", "httpx", "fastapi", "fastapi.responses", "telethon"):
    if name not in sys.modules:
        sys.modules[name] = types.ModuleType(name)
sys.modules["aiogram"].Bot = type("Bot", (), {})
sys.modules["aiogram"].Dispatcher = type("Dispatcher", (), {})
sys.modules["aiogram"].F = type("F", (), {})()
sys.modules["aiogram.types"] = types.ModuleType("aiogram.types")
for cls in ("FSInputFile", "Message", "URLInputFile"):
    setattr(sys.modules["aiogram.types"], cls, type(cls, (), {}))
sys.modules["fastapi"].Request = type("Request", (), {})
sys.modules["fastapi.responses"].FileResponse = type("FileResponse", (), {})
sys.modules["fastapi.responses"].JSONResponse = type("JSONResponse", (), {})

sys.path.insert(0, str(pathlib.Path(__file__).resolve().parent))
import telethon_source as T

fail = 0


def check(what, got, want):
    global fail
    ok = got == want
    if not ok:
        fail += 1
    print("  %s %-46s %s" % ("ок  " if ok else "ПЛОХО", what,
                             "" if ok else "получили %r, ждали %r" % (got, want)))


class FakeTess:
    """Притворяется pytesseract: отдаёт заранее заданные слова и уверенность."""

    class Output:
        DICT = "dict"

    def __init__(self, words):
        self.words = words

    def image_to_data(self, image, lang=None, output_type=None):
        return {
            "text": [w for w, _ in self.words],
            "conf": [c for _, c in self.words],
        }

    def image_to_string(self, image, lang=None):
        return " ".join(w for w, _ in self.words)


def letters(words):
    return sum(len(w) for w in words)


print("=== шум распознавания ===")
# Так выглядел настоящий отказ из журнала: обрывки с низкой уверенностью.
noise = FakeTess([("oa", 31), ("ee", 12), ("|", 0), ("Le", 44), ("}", 3),
                  ("а", 29), ("й", 18), ("РЕ", 22), ("ey", 40), ("SIN", 51),
                  ("ИИ", 36)])
check("шум не считается надписью", letters(T._confident_words(noise, None)) >= T.IMAGE_TEXT_MIN_CHARS, False)

mixed = FakeTess([("PЕkлaмa", 88)])   # латиница вперемешку с кириллицей
check("смешанные алфавиты — тоже шум", T._confident_words(mixed, None), [])

print("\n=== настоящая надпись ===")
real = FakeTess([("СКИДКА", 92), ("50%", 88), ("только", 85), ("сегодня", 91)])
check("рекламная надпись ловится", letters(T._confident_words(real, None)) >= T.IMAGE_TEXT_MIN_CHARS, True)

latin = FakeTess([("SALE", 95), ("today", 90)])
check("надпись латиницей ловится", letters(T._confident_words(latin, None)) >= T.IMAGE_TEXT_MIN_CHARS, True)

one_word = FakeTess([("Промпт", 90)])
check("одного уверенного слова довольно", letters(T._confident_words(one_word, None)) >= T.IMAGE_TEXT_MIN_CHARS, True)

print("\n=== запасной путь ===")


class OldTess(FakeTess):
    def image_to_data(self, *a, **k):
        raise AttributeError("image_to_data нет")


check("старый pytesseract — читаем целиком", T._confident_words(OldTess([("СКИДКА", 90)]), None), ["СКИДКА"])

print("\n=== пороги ===")
check("уверенность берём из настроек", T.IMAGE_TEXT_MIN_CONF > 0, True)
check("короткие обрывки отсекаются", T.IMAGE_TEXT_MIN_WORD >= 2, True)

print("\nвсе проверки прошли" if not fail else "\nпровалов: %d" % fail)
sys.exit(1 if fail else 0)
