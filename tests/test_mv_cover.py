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
    st.set_value(session, "kie_api_key", "test-key")
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

print("ВСЁ ПРОШЛО")
