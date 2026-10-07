"""Промпты отрезков: считаются до заказа, у каждого выпуска свой оттенок.

Жалоба, из которой это выросло: два микса одного жанра выходили похожими.
Причина была в том, что описание для Suno складывалось в момент заказа из жанра
и названия оттенка, а круг оттенков у каждого микса начинался с нуля.
"""
import math
import os
import sys

sys.path.insert(0, "/home/user/grandauto")
from pathlib import Path

from app import bootstrap; bootstrap.run()
from app import musicvideo as mv, storage
from app import settings_store as st
from app.db import session_scope
from app.models import MusicVideo

OUT = Path(os.environ["OUT"]); OUT.mkdir(parents=True, exist_ok=True)

# --- 1. шаг по кругу оттенков обходит круг целиком -------------------------------
# Шаг, не взаимно простой с длиной круга, берёт не все оттенки: при четырёх
# оттенках шаг 2 гоняет два по кольцу. Так и было у выпусков #5 и #7.
for count in range(3, 9):
    for offset in range(count * 4):
        step = mv.mood_step(count, offset)
        assert math.gcd(step, count) == 1, f"шаг {step} при {count} оттенках"
        seen = {(index * step + offset) % count for index in range(count)}
        assert len(seen) == count, f"шаг {step} не обошёл {count} оттенков: {sorted(seen)}"
print("1. шаг по кругу оттенков всегда обходит круг целиком (3–8 оттенков, 4 круга выпусков)")

style = mv.style_of("chillstep")
assert len(style.moods) >= 3, "у жанра слишком мало оттенков для проверки"
for mix in range(1, 13):
    order = [mv.suno_prompt(style, index, offset=mix) for index in range(len(style.moods))]
    moods = [line.split("Variation: ")[1].split(".")[0] for line in order]
    assert len(set(moods)) == len(style.moods), f"выпуск #{mix} ходит по кругу неполно: {moods}"
print(f"2. каждый выпуск проходит все {len(style.moods)} оттенка до повтора (проверено 12 выпусков)")

# --- 2. акцент выпуска ------------------------------------------------------------
accents = [mv.blend_mood(style, mix) for mix in range(1, 17)]
assert len(set(accents)) == 16, f"акценты повторяются: {accents}"
print(f"3. у шестнадцати выпусков шестнадцать разных акцентов, первый — «{accents[0]}»")

# --- 3. план есть и без исследования ----------------------------------------------
with session_scope() as session:
    st.set_value(session, "kie_api_key", "test-key")
    st.set_value(session, "default_chat_model", "")  # модели нет — промпты механические
    session.commit()
    first = mv.create(session, style="chillstep", minutes=30, suno_model="V5")
    second = mv.create(session, style="chillstep", minutes=30, suno_model="V5")
    first_id, second_id = first.id, second.id

plan_a = mv.ensure_plan(first_id, count=4)
plan_b = mv.ensure_plan(second_id, count=4)
assert len(plan_a) == 4 and len(plan_b) == 4, f"план не посчитан: {len(plan_a)}/{len(plan_b)}"
assert plan_a[0] != plan_b[0], "два микса одного жанра просят у Suno одно и то же"
assert len(set(plan_a)) == 4, f"внутри микса промпты повторяются: {plan_a}"
print(f"4. без исследования план всё равно считается: по 4 промпта, "
      f"у разных миксов первые заявки разные")

# Посчитанное запоминается: «дособрать» после сбоя идёт тем же замыслом.
with session_scope() as session:
    stored = session.get(MusicVideo, first_id).plan_json
assert stored and plan_a[0] in stored, "план не сохранился у микса"
assert mv.ensure_plan(first_id, count=4) == plan_a, "план пересчитался заново"
print("5. план запомнен у микса: повторный вызов отдаёт те же промпты")

# Микс оказался длиннее плана — план продолжается, начало не переписывается.
longer = mv.ensure_plan(first_id, count=7)
assert len(longer) == 7, f"план не продолжился: {len(longer)}"
assert longer[:4] == plan_a, "продолжение переписало начало плана"
print(f"6. план продолжается при нехватке: было 4, стало {len(longer)}")

# --- 4. промпт считается моделью до заказа ----------------------------------------
asked: list[str] = []


class FakeChat:
    def __init__(self, *a, **kw):
        pass

    def chat_json(self, model, messages, *, temperature=None, **kw):
        asked.append(messages[0]["content"])
        return {"prompts": [f"lush pads, 92 bpm, D minor, part {n}" for n in range(1, 6)]}, 0.0


mv.KieClient = FakeChat
with session_scope() as session:
    st.set_value(session, "default_chat_model", "gpt-test")
    session.commit()
    third = mv.create(session, style="chillstep", minutes=30, suno_model="V5")
    third_id = third.id

plan_c = mv.ensure_plan(third_id, count=5)
assert len(asked) == 1, f"обращений к модели {len(asked)}"
assert plan_c[0].startswith("lush pads"), f"промпт не от модели: {plan_c[0]}"
assert len(set(plan_c)) == 5, "модель выдала одинаковые промпты, а их взяли как есть"
accent = mv.blend_mood(style, third_id)
assert accent in asked[0], "акцент выпуска не передан модели"
assert style.suno in asked[0], "жанр не передан модели"
print(f"7. промпты отрезков считает модель, акцент выпуска «{accent}» ей передан")

# Модель отдала меньше, чем просили, — нехватку добираем сами, заказ не встаёт.
class Short(FakeChat):
    def chat_json(self, model, messages, *, temperature=None, **kw):
        return {"prompts": ["warm keys, 90 bpm"]}, 0.0


mv.KieClient = Short
with session_scope() as session:
    short_row = mv.create(session, style="chillstep", minutes=30, suno_model="V5")
    short_id = short_row.id
plan_d = mv.ensure_plan(short_id, count=4)
assert len(plan_d) == 4, f"нехватка не добрана: {plan_d}"
assert plan_d[0] == "warm keys, 90 bpm" and "Variation:" in plan_d[1], plan_d
print("8. модель вернула один промпт из четырёх — остальные добраны механически")

# Модель молчит — заказ идёт по механическим промптам, а не падает.
class Broken(FakeChat):
    def chat_json(self, model, messages, *, temperature=None, **kw):
        raise RuntimeError("шлюз молчит")


mv.KieClient = Broken
with session_scope() as session:
    broken_row = mv.create(session, style="chillstep", minutes=30, suno_model="V5")
    broken_id = broken_row.id
plan_e = mv.ensure_plan(broken_id, count=3)
assert len(plan_e) == 3 and all("Variation:" in line for line in plan_e), plan_e
print("9. модель молчит — промпты механические, заказ не срывается")

# --- 5. заказ идёт именно по посчитанным промптам ---------------------------------
sent: list[str] = []


def fake_batch(client, prompt, *, model, dest_dir, index):
    """Вместо Suno: запоминаем, с каким промптом пришли, и отдаём «трек»."""
    sent.append(prompt)
    dest_dir.mkdir(parents=True, exist_ok=True)
    dest = dest_dir / f"fake{index}.m4a"
    dest.write_bytes(b"not really audio")
    return [{"title": f"Part {index + 1}", "prompt": prompt, "model": model,
             "path": storage.rel(dest), "source_url": "", "duration_sec": 200.0}]


mv._fetch_batch = fake_batch
rows = mv.ensure_tracks(third_id, target_sec=600, model="V5", fade=6.0)
assert sent, "заказ не пошёл"
assert len(rows) >= 3, f"треков {len(rows)}"
assert sent[0] == plan_c[0], f"заказали не по плану: {sent[0]!r} вместо {plan_c[0]!r}"
assert all(line in plan_c or "Variation:" in line for line in sent), sent
assert len(set(sent)) == len(sent), f"один и тот же промпт заказан дважды: {sent}"
print(f"10. {len(sent)} заявок к Suno ушли по посчитанным промптам, "
      f"первая — «{sent[0][:48]}…»")

# Длина микса больше плана — промпты на хвост считаются до его заказа.
with session_scope() as session:
    plan_now = session.get(MusicVideo, third_id).plan_json
assert plan_now and len(sent) <= len(mv.ensure_plan(third_id, count=len(sent))), \
    "хвост заказан без промпта"
print("11. хвост длиннее плана заказан по дописанным промптам, а не по пустому месту")

print("ВСЁ ПРОШЛО")
