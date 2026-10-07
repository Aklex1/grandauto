"""Кнопка «Другая обложка»: перерисовка без пересборки ролика.

Проверяем именно через панель, а не вызовом функции: кнопка должна работать от
нажатия до новой картинки на странице, и у ролика, и у вертикального отрывка.
"""
import os
import sys

sys.path.insert(0, "/home/user/grandauto")
from pathlib import Path

from app import bootstrap; bootstrap.run()
from app import backdrops, chrome, musicvideo as mv, storage
from app import settings_store as st
from app.db import session_scope
from app.models import MusicShort, MusicVideo
from fastapi.testclient import TestClient
from app import main as app_main

OUT = Path(os.environ["OUT"]); OUT.mkdir(parents=True, exist_ok=True)

# Готовый «микс»: восемь секунд нарисованной сцены со звуком — всё, что нужно
# обложке. Генерация не задействована, денег это не стоит.
scene = OUT / "scene.mp4"
backdrops.render(scene, "aurora", 8, 3)
clip = OUT / "mix.mp4"
storage.run_ff(["ffmpeg", "-hide_banner", "-loglevel", "error", "-y", "-i", str(scene),
                "-f", "lavfi", "-i", "sine=frequency=220:duration=8",
                "-shortest", "-c:v", "libx264", "-pix_fmt", "yuv420p",
                "-c:a", "aac", str(clip)], timeout=300)
media_clip = storage.abspath("_musicvideo/test/mix.mp4")
media_clip.parent.mkdir(parents=True, exist_ok=True)
media_clip.write_bytes(clip.read_bytes())

with session_scope() as session:
    # Ключа нет — значит первые проверки идут по бесплатному пути из кадра и
    # наружу не ходят. Генерацию включаем ниже, подставным клиентом.
    st.set_value(session, "kie_api_key", "")
    session.commit()
    row = mv.create(session, style="chillstep", minutes=30, suno_model="V5")
    row.video_path = storage.rel(media_clip)
    row.duration_sec = 1800.0
    row.yt_title = "Aurora Drive — melodic night-drive chillstep · 30 min"
    row.status = "done"
    session.commit()
    mix_id = row.id

client = TestClient(app_main.app)
client.post("/login", data={"username": "admin",
                            "password": os.environ["CF_ADMIN_PASSWORD"]},
            follow_redirects=False)

# --- 1. кнопка рисует обложку ------------------------------------------------------
page = client.get("/music?tab=library").text
assert "/cover" in page, "кнопки обложки нет на странице библиотеки"
assert "Сделать обложку" in page, "у микса без обложки кнопка должна предлагать сделать"
print("1. кнопка обложки есть в библиотеке и предлагает сделать первую")

reply = client.post(f"/music/{mix_id}/cover", data={"back": "/music?tab=library"},
                    follow_redirects=False)
assert reply.status_code == 303, reply.status_code
assert "error" not in reply.headers["location"], reply.headers["location"]
with session_scope() as session:
    first = session.get(MusicVideo, mix_id).cover_path
    accent_one = session.get(MusicVideo, mix_id).cover_accent
assert first and storage.abspath(first).exists(), f"файла обложки нет: {first}"
from PIL import Image
assert Image.open(storage.abspath(first)).size == chrome.COVER_SIZE, "не 1280×720"
print(f"2. обложка нарисована: {Path(first).name}, 1280×720, "
      f"{storage.abspath(first).stat().st_size // 1024} КБ")

# --- 2. второе нажатие даёт другую обложку ----------------------------------------
client.post(f"/music/{mix_id}/cover", data={"back": "/music?tab=library"},
            follow_redirects=False)
with session_scope() as session:
    second = session.get(MusicVideo, mix_id).cover_path
    accent_two = session.get(MusicVideo, mix_id).cover_accent
assert second != first, "второе нажатие вернуло ту же обложку"
assert accent_two != accent_one, f"оттенок не сменился: {accent_one} → {accent_two}"
assert storage.abspath(first).exists(), "прежняя обложка удалена — на неё мог быть ссылки"
old_bytes = storage.abspath(first).read_bytes()
new_bytes = storage.abspath(second).read_bytes()
assert old_bytes != new_bytes, "картинка побайтово та же"
print(f"3. второе нажатие: {Path(second).name}, оттенок {accent_one} → {accent_two}, "
      f"картинка другая, прежняя осталась на диске")

page = client.get("/music?tab=library").text
assert second in page, "новая обложка не показана на странице"
assert "Другая обложка" in page, "кнопка не переименовалась"
print("4. новая обложка показана на странице, кнопка стала «Другая обложка»")

# --- 3. несобранный микс: кнопка не падает ----------------------------------------
with session_scope() as session:
    raw = mv.create(session, style="chillstep", minutes=30, suno_model="V5")
    raw_id = raw.id
reply = client.post(f"/music/{raw_id}/cover", data={"back": "/music?tab=library"},
                    follow_redirects=False)
assert reply.status_code == 303 and "error=not-built" in reply.headers["location"], \
    reply.headers["location"]
print("5. у несобранного микса кнопка отвечает понятной ошибкой, а не падением")

# --- 4. обложка отрывка -----------------------------------------------------------
with session_scope() as session:
    short = MusicShort(video_id=mix_id, title="Drift Beyond",
                       path=storage.rel(media_clip), start_sec=120.0,
                       duration_sec=48.0, file_size=media_clip.stat().st_size)
    session.add(short)
    session.commit()
    short_id = short.id

reply = client.post(f"/music/shorts/{short_id}/cover", data={"back": "/music?tab=library"},
                    follow_redirects=False)
assert reply.status_code == 303 and "error" not in reply.headers["location"], \
    reply.headers["location"]
with session_scope() as session:
    sh_first = session.get(MusicShort, short_id).cover_path
assert sh_first and storage.abspath(sh_first).exists(), f"нет файла: {sh_first}"
assert Image.open(storage.abspath(sh_first)).size == chrome.COVER_VERTICAL, "не 1080×1920"
print(f"6. вертикальная обложка отрывка: {Path(sh_first).name}, 1080×1920")

client.post(f"/music/shorts/{short_id}/cover", data={"back": "/music?tab=library"},
            follow_redirects=False)
with session_scope() as session:
    sh_second = session.get(MusicShort, short_id).cover_path
assert sh_second != sh_first, "у отрывка второе нажатие вернуло ту же обложку"
assert storage.abspath(sh_second).read_bytes() != storage.abspath(sh_first).read_bytes()
print("7. второе нажатие у отрывка тоже даёт другую картинку")

# --- 5. на превью YouTube идёт обложка, а не голый кадр ---------------------------
sent = {}


def fake_upload(creds, path, *, title, description, tags, privacy, thumbnail=None):
    sent["thumbnail"] = thumbnail
    return {"id": "vid123", "url": "https://youtu.be/vid123"}


# publish() берёт модуль внутри функции, поэтому подменяем сам модуль, а не ссылку.
from app import youtube as yt_mod

yt_mod.credentials = lambda session: {"refresh_token": "x", "client_id": "y",
                                      "client_secret": "z"}
yt_mod.upload = fake_upload
with session_scope() as session:
    video = session.get(MusicVideo, mix_id)
    video.poster_path = storage.rel(media_clip)  # голый кадр тоже есть
    session.commit()
    cover_now = video.cover_path
mv.publish(mix_id, privacy="private")
assert sent["thumbnail"] is not None, "превью не передано вовсе"
assert sent["thumbnail"] == storage.abspath(cover_now), \
    f"на превью ушёл не тот файл: {sent['thumbnail']}"
print("8. при выгрузке на YouTube превью — нарисованная обложка, а не кадр из ролика")

# --- 6. картинка обложки генерируется по своему промпту ----------------------------
# Кадр из ролика на обложке не годится: на нём уже шапка, знак канала и плашка
# плеера. Проверяем, что берётся отдельная картинка, а не кадр.
art_src = OUT / "art.png"
from PIL import Image as _Image
_Image.new("RGB", (1920, 1080), (120, 40, 90)).save(art_src)

asked = []
hits = {"download": 0}


class FakeKie:
    def __init__(self, *a, **kw):
        pass

    def run_task(self, model, payload, **kw):
        asked.append((model, payload))
        return {"resultUrls": ["http://fake/art.png"], "_credits": 12.0}


def fake_download(url, dest, **kw):
    hits["download"] += 1
    Path(dest).parent.mkdir(parents=True, exist_ok=True)
    Path(dest).write_bytes(art_src.read_bytes())
    return dest


mv.KieClient = FakeKie
import app.storage as storage_mod
storage_mod.download = fake_download

with session_scope() as session:
    st.set_value(session, "default_image_model", "nano-banana-2")
    st.set_value(session, "kie_api_key", "test-key")
    session.commit()

before = len(asked)
client.post(f"/music/{mix_id}/cover", data={"back": "/music?tab=library"},
            follow_redirects=False)
assert len(asked) == before + 1, "картинку обложки не заказывали"
model, payload = asked[-1]
prompt = str(payload)
assert "no text" in prompt and "woman" in prompt, prompt[:300]
assert "photograph" in prompt, "обложка заказана не живой сценой: " + prompt[:300]
assert "clearly visible in frame" in prompt, "человека в кадре не потребовали"
# Без прямого запрета генератор понимает «левая половина проще» как диптих и
# рисует вторую картинку слева — так и вышло на первом же заказе.
assert "no split screen" in prompt and "no diptych" in prompt, "диптих не запрещён"
# «За рулём, вид со стороны пассажира» генератор понял как «развернулась назад»:
# героиня сидела спиной к рулю. Правило на позу должно быть в каждом промпте.
assert "correct anatomy" in prompt and "natural plausible pose" in prompt, prompt[:200]

# Пустого пейзажа быть не должно: человек есть в каждом сюжете, у ролика и у
# отрывка. Без явного требования генератор охотно отдаёт берег без людей.
from app import covers as _cov
for scene in _cov.SCENES + _cov.SHORT_SCENES:
    who = scene.subject.lower()
    assert any(word in who for word in ("woman", "girl", "friends", "surfer",
                                        "driver", "hiker", "dancer", "cyclist",
                                        "swimmer", "skateboarder")), scene.key
assert "16:9" in prompt, "горизонтальная обложка заказана не в 16:9"
with session_scope() as session:
    art_cover = session.get(MusicVideo, mix_id).cover_path
    spent = session.get(MusicVideo, mix_id).credits
assert spent >= 12.0, f"трата не записана: {spent}"
# Обложка собрана поверх сгенерированной картинки, а не поверх кадра ролика:
# у кадра ночная синева, у нашей подмены — пурпур.
img = _Image.open(storage.abspath(art_cover)).convert("RGB")
r, g, b = img.getpixel((img.width - 60, 40))
assert r > b and r > g, f"обложка не из сгенерированной картинки: {(r, g, b)}"
print(f"7. обложка собрана поверх своей картинки, {spent:.0f} кредитов, "
      f"промпт с запретом надписей")

# --- 7. подпись не повторяет пилюлю ------------------------------------------------
assert mv._cover_note("melodic night-drive chillstep · 30 min") == \
    "melodic night-drive chillstep", mv._cover_note("melodic night-drive chillstep · 30 min")
assert mv._cover_note("35 minutes") == "", "длительность всё ещё лезет в подпись"
print("8. подпись под заголовком — смысл, а не вторая длительность")

# --- 8. бесплатный вариант из кадра ------------------------------------------------
before = len(asked)
client.post(f"/music/{mix_id}/cover",
            data={"back": "/music?tab=library", "source": "frame"},
            follow_redirects=False)
assert len(asked) == before, "вариант «из кадра» всё равно пошёл в генерацию"
print("9. кнопка «Из кадра» ничего не заказывает и не тратит")

# --- 9. вертикальная обложка отрывка заказывается в 9:16 ---------------------------
before = len(asked)
client.post(f"/music/shorts/{short_id}/cover", data={"back": "/music?tab=library"},
            follow_redirects=False)
assert len(asked) == before + 1, "картинку обложки отрывка не заказывали"
short_prompt = str(asked[-1][1])
assert "9:16" in short_prompt, "вертикальная обложка заказана не в 9:16"
assert "mid-movement" in short_prompt, "у шортса сцена без движения: " + short_prompt[:300]
from app import covers as covers_mod
assert ({s.key for s in covers_mod.SHORT_SCENES} &
        {s.key for s in covers_mod.SCENES}) == set(), "сюжеты ролика и шортса совпадают"
print("10. обложка отрывка заказана в вертикальном кадре 9:16")

# --- 10. скачивание по кнопке ------------------------------------------------------
reply = client.get(f"/music/{mix_id}/cover.jpg")
assert reply.status_code == 200, reply.status_code
assert reply.headers["content-type"] == "image/jpeg", reply.headers["content-type"]
assert "attachment" in reply.headers.get("content-disposition", ""), \
    reply.headers.get("content-disposition")
assert len(reply.content) > 10000, "скачался пустой файл"
name_mix = reply.headers["content-disposition"]
reply = client.get(f"/music/shorts/{short_id}/cover.jpg")
assert reply.status_code == 200 and reply.headers["content-type"] == "image/jpeg"
assert "attachment" in reply.headers.get("content-disposition", "")
print(f"11. обложка скачивается файлом: {name_mix.split('filename=')[-1].strip()} "
      f"и отдельно вертикальная у отрывка")

# --- 11. хештеги без пробелов ------------------------------------------------------
from app import tags as tags_mod

style_row = mv.style_of("chillstep")
rows = tags_mod.build(style_tags=style_row.tags, series="Aurora Drive",
                      genre=style_row.key, minutes=30, use=style_row.use)
tags_line = tags_mod.hashtags(rows)
assert tags_line[:3] == ["chillstep", "chillstepmix", "melodicdubstep"], tags_line
assert all(" " not in word for word in tags_line), tags_line
assert len(tags_line) <= 3, f"хештегов больше трёх: {tags_line}"
assert "chillstepmix" in rows, "слитного близнеца нет среди ключевых слов"
assert "chillstep mix" in rows, "фразу с пробелом убрали — обычный поиск её ищет"
assert len(", ".join(rows)) <= tags_mod.TAGS_LIMIT, "вышли за 500 знаков"
print("12. хештеги: " + " ".join("#" + w for w in tags_line) +
      " — без пробелов, фразы остались отдельными ключевыми словами")

# --- 12. сто сюжетов и ни одного повтора ------------------------------------------
import app.covers as cov

assert len(cov.SCENES) == 50 and len(cov.SHORT_SCENES) == 50, "план не на сто сюжетов"
keys = [sc.key for sc in cov.SCENES + cov.SHORT_SCENES]
assert len(set(keys)) == 100, "ключи сюжетов повторяются"
for sc in cov.SCENES + cov.SHORT_SCENES:
    assert sc.subject and sc.place and sc.light, sc.key
print(f"13. контент-план: {len(cov.SCENES)} сюжетов для роликов и "
      f"{len(cov.SHORT_SCENES)} для шортсов, все ключи разные")

# Завод выбирает сюжет, которого ещё не было: отмечаем выбранный у ролика и
# просим следующий — так же, как это делает отрисовка обложки.
taken = []
with session_scope() as session:
    for _ in range(14):
        scene = mv.fresh_scene(session, vertical=False)
        taken.append(scene.key)
        row = mv.create(session, style="chillstep", minutes=30, suno_model="V5")
        row.cover_scene = scene.key
        session.commit()
assert len(set(taken)) == 14, f"сюжеты повторились: {taken}"
assert len(set(taken[:3])) == 3
print(f"14. четырнадцать роликов подряд — четырнадцать разных сюжетов, "
      f"первые три: {', '.join(taken[:3])}")

taken_v = []
with session_scope() as session:
    for _ in range(14):
        scene = mv.fresh_scene(session, vertical=True)
        taken_v.append(scene.key)
        row = MusicShort(video_id=mix_id, title="t", path=storage.rel(media_clip),
                         duration_sec=30.0, cover_scene=scene.key)
        session.add(row)
        session.commit()
assert len(set(taken_v)) == 14, f"сюжеты отрывков повторились: {taken_v}"
assert not (set(taken) & set(taken_v)), "ролик и отрывок берут из одного набора"
print(f"15. у отрывков свои четырнадцать разных сюжетов, с роликами не пересекаются")

# Когда круг пройден, выбор идёт по второму разу, а не падает.
with session_scope() as session:
    for sc in cov.SCENES:
        row = mv.create(session, style="chillstep", minutes=30, suno_model="V5")
        row.cover_scene = sc.key
        session.commit()
    again = mv.fresh_scene(session, vertical=False)
assert again.key in {sc.key for sc in cov.SCENES}, again.key
print("16. круг пройден — выбор идёт по второму заходу, а не ломается")

print("ВСЁ ПРОШЛО")
