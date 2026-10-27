"""Проверка правил антиспама без aiogram: подставляем заглушки."""
import pathlib, re, sys, types

# Заглушки вместо aiogram: нужны только имена, правила их не трогают.
aiogram = types.ModuleType("aiogram")
class _F:
    def __getattr__(self, name): return self
    def in_(self, *a, **k): return self
aiogram.Bot = type("Bot", (), {})
aiogram.Dispatcher = type("Dispatcher", (), {})
aiogram.F = _F()
types_mod = types.ModuleType("aiogram.types")
class Message: pass
types_mod.Message = Message
sys.modules["aiogram"] = aiogram
sys.modules["aiogram.types"] = types_mod

sys.path.insert(0, str(pathlib.Path(__file__).resolve().parent))
import group_antispam as A

class Msg:
    """Сообщение группы: только то, на что смотрят правила."""
    def __init__(self, text="", caption=None, forward=False, entities=None, auto=False):
        self.text = text
        self.caption = caption
        self.entities = entities
        self.caption_entities = None
        self.is_automatic_forward = auto
        if forward:
            self.forward_origin = object()

    def __getattr__(self, name):   # всё, чего не задали, считаем пустым
        return None

fail = 0
def check(name, got, want):
    global fail
    ok = got == want
    if not ok: fail += 1
    print(("  ок   " if ok else "  ПЛОХО ") + name + ("" if ok else " — получили %r, ждали %r" % (got, want)))

print("=== ссылки ===")
check("голый домен", A.message_has_link(Msg("заходите на example.ru за курсом")), True)
check("t.me", A.message_has_link(Msg("вот канал t.me/somechannel")), True)
check("упоминание", A.message_has_link(Msg("пиши @somebody_here")), True)
check("https", A.message_has_link(Msg("https://vk.com/wall1")), True)
check("обычный текст", A.message_has_link(Msg("как работает нейросеть?")), False)
check("число с точкой", A.message_has_link(Msg("версия 3.5 лучше")), False)

print("=== пересылка ===")
check("переслали", A.message_is_forward(Msg("реклама", forward=True)), True)
check("пост из канала группы", A.message_is_forward(Msg("новость", forward=True, auto=True)), False)
check("своё сообщение", A.message_is_forward(Msg("привет")), False)

print("=== слова ===")
check("заработок", A.message_has_banned_words(Msg("лёгкий заработок без вложений")), True)
check("вакансия", A.message_has_banned_words(Msg("вакансия: оператор чата")), True)
check("впн", A.message_has_banned_words(Msg("продам VPN дёшево")), True)
check("набор людей", A.message_has_banned_words(Msg("ищу людей в команду")), True)
check("обычное «работает»", A.message_has_banned_words(Msg("не работает кнопка генерации")), False)

print("=== итог причины ===")
check("пересылка важнее ссылки", A.spam_reason(Msg("t.me/ads", forward=True)), "пересылка")
check("чистое сообщение", A.spam_reason(Msg("подскажите, какая модель лучше?")), None)

print("=== предупреждений больше нет ===")
src = (pathlib.Path(__file__).resolve().parent / "group_antispam.py").read_text()
check("send_message в модуле не вызывается", "send_message" in src, False)
check("правки сообщений под присмотром", "dp.edited_message.register" in src, True)

print("\nвсе проверки прошли" if not fail else "\nпровалов: %d" % fail)
sys.exit(1 if fail else 0)
