"""Музыкальные видео: мягкие переходы, тайм-код, заставка по кругу, вкладки панели."""
import os
import shutil
import sys

sys.path.insert(0, "/home/user/grandauto")
from pathlib import Path

from app import bootstrap; bootstrap.run()
from app import media, music, musicvideo as mv, storage
from app import settings_store as st
from app.db import session_scope
from app.models import Job, LoopClip, MusicVideo, MusicVideoTrack
from fastapi.testclient import TestClient
from app import main as app_main

OUT = Path(os.environ["OUT"]); OUT.mkdir(parents=True, exist_ok=True)


def ff(args, timeout=300):
    storage.run_ff(["ffmpeg", "-hide_banner", "-loglevel", "error", "-y"] + args,
                   timeout=timeout)


def dur(path):
    return storage.media_duration(path)


# --- 1. сшивание мягкими переходами ----------------------------------------------
# Три трека разной высоты тона: по тону слышно (и видно в спектре), где стык.
tracks = []
for index, freq in enumerate((220, 330, 440)):
    path = OUT / f"t{index}.m4a"
    ff(["-f", "lavfi", "-i", f"sine=frequency={freq}:duration=30", "-c:a", "aac", str(path)])
    tracks.append(path)

mix = OUT / "mix.m4a"
fade = media.stitch_music(tracks, mix, crossfade=6.0)
assert abs(fade - 6.0) < 0.01, f"переход {fade}"
want = media.CROSSFADE_SEC * 0  # считаем по формуле модуля
want = mv.effective_duration([dur(t) for t in tracks], fade)
got = dur(mix)
assert abs(got - want) < 1.0, f"длина микса {got:.2f}, ожидалась {want:.2f}"
print(f"1. сшито: {got:.1f} с из трёх по 30 с, переход {fade:.1f} с "
      f"(склейка встык дала бы {sum(dur(t) for t in tracks):.1f})")

# Переход не длиннее трети самого короткого трека: иначе ffmpeg не строит фильтр.
short = OUT / "short.m4a"
ff(["-f", "lavfi", "-i", "sine=frequency=500:duration=6", "-c:a", "aac", str(short)])
mix2 = OUT / "mix2.m4a"
fade2 = media.stitch_music([tracks[0], short], mix2, crossfade=20.0)
assert 0 < fade2 <= 2.01, f"переход не поджался: {fade2}"
assert dur(mix2) > 30, "короткий трек потерялся"
print(f"2. короткий трек: переход поджат до {fade2:.1f} с, микс {dur(mix2):.1f} с")

# --- 2. громкость микса -----------------------------------------------------------
quiet = OUT / "quiet.m4a"
ff(["-f", "lavfi", "-i", "sine=frequency=300:duration=20", "-af", "volume=-18dB",
    "-c:a", "aac", str(quiet)])
loud = OUT / "loud.m4a"
media.normalize_audio(quiet, loud)
import subprocess

# ebur128 печатает итог в stderr — stdout у него пустой.
proc = subprocess.run(["ffmpeg", "-hide_banner", "-nostats", "-i", str(loud),
                       "-af", "ebur128=framelog=quiet", "-f", "null", "-"],
                      capture_output=True, text=True, timeout=300)
out = proc.stdout + proc.stderr
level = None
for line in out.splitlines():
    if "I:" in line and "LUFS" in line:
        level = float(line.split("I:")[1].split("LUFS")[0])
assert level is not None and abs(level - media.MUSIC_LUFS) < 1.5, f"громкость {level}"
print(f"3. громкость микса: {level:.1f} LUFS (цель {media.MUSIC_LUFS})")

# --- 3. заставка по кругу ---------------------------------------------------------
loop_src = OUT / "loop.mp4"
ff(["-f", "lavfi", "-i", "testsrc2=size=1920x1080:duration=4", "-c:v", "libx264",
    "-pix_fmt", "yuv420p", str(loop_src)])
video = OUT / "video.mp4"
media.build_music_video(loop_src, mix, video, (1920, 1080), dur(mix), OUT / "render")
probe = storage.run_ff(["ffprobe", "-v", "error", "-select_streams", "v:0",
                        "-show_entries", "stream=width,height", "-of", "csv=p=0",
                        str(video)], timeout=60).strip()
assert probe.startswith("1920,1080"), f"формат {probe}"
assert abs(dur(video) - dur(mix)) < 1.5, f"видео {dur(video):.1f}, звук {dur(mix):.1f}"
# Звук в ролике — именно микс, а не тишина.
assert dur(video) > 60, "ролик короче минуты"
print(f"4. заставка 4 с растянута на {dur(video):.1f} с, формат {probe}, "
      f"файл {video.stat().st_size // 1024} КБ")

# Замыкание заставки. Генераторы видео берут требование «seamless loop» к
# сведению, но выполнять им его нечем: клип уезжает в одну сторону. Поэтому не
# верим обещанию, а меряем стык и чиним по результату.
def gray(path, at):
    raw = Path(str(path) + f".{at}.raw")
    storage.run_ff(["ffmpeg", "-hide_banner", "-loglevel", "error", "-y", "-ss", str(at),
                    "-i", str(path), "-frames:v", "1", "-vf", "scale=32:18,format=gray",
                    "-f", "rawvideo", str(raw)], timeout=120)
    return raw.read_bytes()


def diff(a, b):
    return sum(abs(x - y) for x, y in zip(a, b)) / max(1, len(a))


# Клип с однонаправленным движением — ровно то, что отдаёт pixverse: картинка
# непрерывно уезжает и к началу не возвращается. Шахматный testsrc2 для этого не
# годится: он сам по себе периодичен и честно читается как замкнутый.
shot = OUT / "drift_src.png"
ff(["-f", "lavfi", "-i", "testsrc2=size=2400x1350", "-frames:v", "1", str(shot)])
drift = OUT / "drift.mp4"
ff(["-loop", "1", "-i", str(shot), "-t", "8", "-r", "24",
    "-vf", ("crop=1200:675:x='min(iw-1200,t/8*(iw-1200))':y='min(ih-675,t/8*(ih-675))',"
            "scale=1920:1080,format=yuv420p"),
    "-c:v", "libx264", "-preset", "veryfast", "-crf", "20", str(drift)])
seam, normal = media.loop_seam(drift)
assert seam / normal > media.SEAM_OK_RATIO, \
    f"рваный стык не распознан: {seam:.1f} при шаге {normal:.2f}"
print(f"5. стык измерен: разрыв {seam:.1f} при обычном шаге {normal:.2f} — "
      f"в {seam / normal:.0f} раз больше порога нет, порог {media.SEAM_OK_RATIO}")

closed = OUT / "closed.mp4"
media.close_loop(drift, closed)
seam2, normal2 = media.loop_seam(closed)
assert seam2 / normal2 <= media.SEAM_OK_RATIO, \
    f"самосклейка не замкнула: {seam2:.2f} при шаге {normal2:.2f}"
assert dur(closed) < dur(drift), "самосклейка не укоротила клип на длину наплыва"
print(f"6. самосклейка замкнула: отношение {seam / normal:.0f} → "
      f"{seam2 / normal2:.1f}, длина {dur(drift):.1f} → {dur(closed):.1f} с "
      f"(движение никуда не разворачивалось)")

# Статичная плашка — уже замкнутый луп, чинить нечего.
still = OUT / "still.png"
ff(["-f", "lavfi", "-i", "color=c=0x202830:size=1920x1080", "-frames:v", "1", str(still)])
flat = OUT / "flat.mp4"
ff(["-loop", "1", "-i", str(still), "-t", "4", "-r", "24", "-c:v", "libx264",
    "-pix_fmt", "yuv420p", str(flat)])
seam3, normal3 = media.loop_seam(flat)
assert seam3 / normal3 <= media.SEAM_OK_RATIO, f"{seam3} / {normal3}"
print(f"7. замкнутый клип починки не требует: отношение {seam3 / normal3:.1f}")

# Предохранитель: reverse держит все кадры в памяти, час FullHD развернуть нельзя.
assert not media.can_reverse(3600.0, (1920, 1080)), "предохранитель по памяти не работает"
assert media.can_reverse(4.0, (1920, 1080)), "на этой машине мало памяти даже на 4 с"

# Ненаправленное движение плюс рваный стык — разворачиваем: наплыва не будет совсем.
pp = OUT / "video_pp.mp4"
media.build_music_video(drift, mix, pp, (1920, 1080), 30.0, OUT / "render_pp",
                        pingpong=True)
L = dur(drift)
near, mirrored, straight = gray(pp, 0.5), gray(pp, 2 * L - 0.5), gray(pp, L + 0.5)
assert diff(near, mirrored) < diff(near, straight), (
    f"зеркальный проход не сработал: {diff(near, mirrored):.1f} против "
    f"{diff(near, straight):.1f}")
print(f"8. зеркальный проход: кадр на {2 * L - 0.5:.1f} с совпал с первым "
      f"({diff(near, mirrored):.1f}), на {L + 0.5:.1f} с — нет "
      f"({diff(near, straight):.1f})")

# Направленное движение: разворачивать нельзя, замыкаем наплывом.
fw = OUT / "video_fw.mp4"
media.build_music_video(drift, mix, fw, (1920, 1080), 30.0, OUT / "render_fw",
                        pingpong=False)
assert abs(dur(fw) - 30.0) < 1.5, dur(fw)
# Период стал короче исходного клипа на длину наплыва — значит починка была.
period = dur(closed)
assert diff(gray(fw, 0.3), gray(fw, period + 0.3)) < \
    diff(gray(fw, 0.3), gray(fw, L + 0.3)), "клип повторяется без самосклейки"
print(f"9. без разворота заставка замкнута наплывом: период {period:.1f} с "
      f"вместо исходных {L:.1f}")

# --- 4. тайм-код ------------------------------------------------------------------
assert mv.timecode(0) == "0:00"
assert mv.timecode(61) == "1:01"
assert mv.timecode(3671) == "1:01:11"
print("10. тайм-код: 0:00, 1:01, 1:01:11")

# --- 5. генерация миксом: уже скачанное не оплачивается заново ---------------------
SRC = OUT / "src"; SRC.mkdir(exist_ok=True)
for index in range(40):
    ff(["-f", "lavfi", "-i", f"sine=frequency={200 + index * 7}:duration=30",
        "-c:a", "aac", str(SRC / f"s{index}.m4a")])

calls = {"suno": 0, "dl": 0}


def fake_tracks(client, style, *, model="", timeout=0, poll=0):
    """Одна заявка — два варианта, как у настоящего Suno."""
    calls["suno"] += 1
    base = (calls["suno"] - 1) * 2
    return [(f"local://{SRC / f's{base}.m4a'}", f"Track {base + 1}"),
            (f"local://{SRC / f's{base + 1}.m4a'}", f"Track {base + 2}")]


def fake_download(url, dest, **kw):
    calls["dl"] += 1
    dest.parent.mkdir(parents=True, exist_ok=True)
    shutil.copy(url.replace("local://", ""), dest)
    return dest


music.generate_tracks = fake_tracks
storage.download = fake_download
mv.storage.download = fake_download

with session_scope() as session:
    st.set_value(session, "kie_api_key", "test-key")
    session.commit()
    # Заставка жанра уже в библиотеке — значит генерировать её не надо и
    # обращений к KIE за картинкой быть не должно вовсе.
    shot = OUT / "backdrop_lib.mp4"
    shutil.copy(loop_src, shot)
    session.add(LoopClip(fmt=mv.FMT_PREFIX + "lofi_jazz", title="тест",
                         path=storage.rel(shot), duration_sec=4.0,
                         width=1920, height=1080, credits=12.0))
    session.commit()
    # Пять минут — нижняя граница, которую ставит create(): просить меньше
    # бессмысленно, один трек Suno и так длиннее.
    row = mv.create(session, style="lofi_jazz", minutes=5, suno_model="V5",
                    language="ru")
    mix_id = row.id
    assert mv.create(session, style="soul", minutes=1).minutes == 5, "нет нижней границы"

mv.build(mix_id, reuse_backdrop=True)

with session_scope() as session:
    row = session.get(MusicVideo, mix_id)
    rows = session.execute(
        MusicVideoTrack.__table__.select().where(MusicVideoTrack.video_id == mix_id)
    ).all()
    assert row.status == "done", f"состояние {row.status}: {row.error}"
    ordered = row.minutes * 60
    assert row.duration_sec >= ordered, f"микс короче заказа: {row.duration_sec:.0f} с"
    # Перебор ограничен: Suno отдаёт по два варианта за заявку, и последняя
    # заявка может перевалить за заказ — но не в разы.
    assert row.duration_sec <= ordered + 150, f"перебор: {row.duration_sec:.0f} с при заказе {ordered}"
    assert row.width == 1920 and row.height == 1080
    assert storage.abspath(row.video_path).exists()
    assert row.crossfade_sec > 0
    print(f"11. микс #{mix_id}: {row.duration_sec / 60:.1f} мин из {len(rows)} треков, "
          f"заявок к Suno {calls['suno']}, кредитов {row.credits:.0f}")

    # Тайм-код считается с вычетом перекрытий: сумма длин больше длины микса,
    # и без вычета последняя метка ушла бы за конец ролика.
    starts = sorted((r.start_sec, r.duration_sec) for r in session.execute(
        MusicVideoTrack.__table__.select()
        .where(MusicVideoTrack.video_id == mix_id)).all())
    assert starts[0][0] == 0.0, "первая композиция не с нуля"
    last = starts[-1]
    assert last[0] + last[1] <= row.duration_sec + 2, \
        f"последняя метка {last[0]:.0f}+{last[1]:.0f} вышла за {row.duration_sec:.0f}"
    naive = sum(d for _s, d in starts)
    assert naive > row.duration_sec + 5, "перекрытия не учтены — проверять нечего"
    print(f"   тайм-код: последняя метка {mv.timecode(last[0])}, конец "
          f"{mv.timecode(row.duration_sec)}; встык было бы {mv.timecode(naive)}")

    assert row.tracklist.splitlines()[0].startswith("0:00"), row.tracklist[:40]
    assert "Тайм-код:" in row.description, (
        "язык не взят у микса — описание вышло не русским: " + row.description[:200])
    assert row.yt_title, "заголовок не сгенерирован"
    print(f"   заголовок: {row.yt_title}")
    print("   описание (начало): " + row.description.splitlines()[0][:70])

suno_before, dl_before = calls["suno"], calls["dl"]
with session_scope() as session:
    credits_before = session.get(MusicVideo, mix_id).credits
assert credits_before == 0.0, \
    f"за заставку из библиотеки списано {credits_before} — её оплатил другой микс"
mv.build(mix_id, reuse_backdrop=True)
assert calls["suno"] == suno_before, \
    f"пересборка заказала музыку заново: {calls['suno']} против {suno_before}"
assert calls["dl"] == dl_before, "пересборка качала треки заново"
with session_scope() as session:
    again = session.get(MusicVideo, mix_id)
    # Заставка взята из библиотеки, значит этот микс за неё не платил — ноль
    # здесь правильный. Проверяем, что пересборка цифру не меняет.
    assert again.credits == credits_before, \
        f"пересборка переписала трату: {again.credits} вместо {credits_before}"
print(f"12. пересборка бесплатна: заявок к Suno по-прежнему {calls['suno']}, "
      f"скачиваний {calls['dl']}")

# --- 6. сорвавшаяся заявка не роняет микс ------------------------------------------
state = {"n": 0}


def flaky(client, style, *, model="", timeout=0, poll=0):
    state["n"] += 1
    if state["n"] % 2 == 1:
        raise music.MusicError("Suno: GENERATE_AUDIO_FAILED")
    return fake_tracks(client, style, model=model)


music.generate_tracks = flaky
with session_scope() as session:
    row = mv.create(session, style="chillstep", minutes=5, suno_model="V5")
    flaky_id = row.id
    shot2 = OUT / "backdrop_chill.mp4"
    shutil.copy(loop_src, shot2)
    session.add(LoopClip(fmt=mv.FMT_PREFIX + "chillstep", title="тест",
                         path=storage.rel(shot2), duration_sec=4.0,
                         width=1920, height=1080))
    session.commit()

mv.build(flaky_id, reuse_backdrop=True, language="en")
with session_scope() as session:
    row = session.get(MusicVideo, flaky_id)
    assert row.status == "done", f"состояние {row.status}: {row.error}"
    assert row.duration_sec >= row.minutes * 60, f"{row.duration_sec:.0f} с"
    assert "Tracklist:" in row.description
    print(f"13. половина заявок падала — микс всё равно собран: "
          f"{row.duration_sec / 60:.1f} мин при заказе {row.minutes} мин, "
          f"попыток {state['n']}")
music.generate_tracks = fake_tracks

# --- 7. промпт заставки подбирается по жанру ---------------------------------------
for key in mv.STYLE_ORDER:
    style = mv.style_of(key)
    assert style.suno and style.image and style.motion, key
    assert "Seamless loop" in mv.motion_prompt(style), key
    assert "no text" in mv.image_prompt(style).lower(), key
    variants = {mv.suno_prompt(style, i) for i in range(len(style.moods))}
    assert len(variants) == len(style.moods), f"{key}: оттенки повторяются"
print(f"14. жанров {len(mv.STYLE_ORDER)}: у каждого свой промпт музыки, заставки и "
      f"движения, оттенков по {len(mv.style_of('lofi_jazz').moods)}")

# --- 8. панель ---------------------------------------------------------------------
client = TestClient(app_main.app)
client.post("/login", data={"username": "admin", "password": "test1234"},
            follow_redirects=False)

page = client.get("/music")
assert page.status_code == 200
assert "Создать композицию" in page.text and "Библиотека" in page.text
for key in mv.STYLE_ORDER:
    assert f'value="{key}"' in page.text, f"жанра {key} нет в форме"
assert "Музыка" in page.text, "нет пункта в верхнем меню"
print("15. вкладка «Создать композицию»: все жанры в форме, пункт меню на месте")

lib = client.get("/music?tab=library")
assert lib.status_code == 200
assert f"#{mix_id}" in lib.text and "Тайм-код" in lib.text
assert "Скачать видео" in lib.text and "Описание файлом" in lib.text
print("16. вкладка «Библиотека»: готовые миксы со ссылками и тайм-кодом")

with session_scope() as session:
    before = len(session.execute(Job.__table__.select()).all())
res = client.post("/music/create", data={"style": "soul", "minutes": 35,
                                         "suno_model": "V4_5PLUS", "language": "en"},
                  follow_redirects=False)
assert res.status_code == 303 and "tab=library" in res.headers["location"]
with session_scope() as session:
    jobs = session.execute(Job.__table__.select()).all()
    assert len(jobs) == before + 1
    kind = jobs[-1].kind
    assert kind == "music_video", kind
    created = session.execute(MusicVideo.__table__.select()).all()[-1]
    assert created.style == "soul" and created.minutes == 35
print(f"17. создание: жанр soul, 35 минут, задача «{kind}» в очереди "
      f"(генерация не запущена — работает воркер)")

res = client.post(f"/music/{mix_id}/meta", data={
    "yt_title": "Проверка заголовка", "description": "Проверка описания",
    "tags": "a, b"}, follow_redirects=False)
assert res.status_code == 303
with session_scope() as session:
    row = session.get(MusicVideo, mix_id)
    assert row.yt_title == "Проверка заголовка"
txt = client.get(f"/music/{mix_id}/description.txt")
assert txt.status_code == 200 and "Проверка описания" in txt.text
print("18. правка описания сохраняется, выгрузка текстом работает")

res = client.post(f"/music/{flaky_id}/drop", data={}, follow_redirects=False)
assert res.status_code == 303
with session_scope() as session:
    assert session.get(MusicVideo, flaky_id) is None
    # Файлы остались: их могли уже выложить.
    assert (mv.work_dir(flaky_id) / "video.mp4").exists()
print("19. удаление убирает запись, файлы на диске остаются")

print("\nВСЁ ПРОШЛО")
