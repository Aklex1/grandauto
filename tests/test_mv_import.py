"""Музыкальный микс из архива с материалами: что взято, что догенерировано."""
import io
import json
import os
import shutil
import sys
import zipfile

sys.path.insert(0, "/home/user/grandauto")
from pathlib import Path

from app import bootstrap; bootstrap.run()
from app import main as app_main, media, music, musicvideo as mv, storage
from app import settings_store as st
from app.db import session_scope
from app.models import Job, MusicVideo, MusicVideoTrack
from fastapi.testclient import TestClient

OUT = Path(os.environ["OUT"]); OUT.mkdir(parents=True, exist_ok=True)


def ff(args, timeout=300):
    storage.run_ff(["ffmpeg", "-hide_banner", "-loglevel", "error", "-y"] + args,
                   timeout=timeout)


# --- собираем архив, похожий на настоящий ------------------------------------------
SRC = OUT / "pack"; SRC.mkdir(exist_ok=True)
(SRC / "tracks").mkdir(exist_ok=True)
(SRC / "art").mkdir(exist_ok=True)
(SRC / "meta").mkdir(exist_ok=True)
(SRC / "stingers").mkdir(exist_ok=True)
(SRC / "__MACOSX").mkdir(exist_ok=True)

# Имена нарочно такие, чтобы проверить человеческий порядок: 10 после 2, а не до.
PIECES = [("01_intro.mp3", 70, 220), ("02_groove.mp3", 80, 300), ("10_outro.mp3", 65, 180)]
for fname, span, freq in PIECES:
    ff(["-f", "lavfi", "-i", f"sine=frequency={freq}:duration={span}",
        "-c:a", "libmp3lame", str(SRC / "tracks" / fname)])
# Отбивка короче минимума — не композиция, браться не должна.
ff(["-f", "lavfi", "-i", "sine=frequency=900:duration=12", "-c:a", "libmp3lame",
    str(SRC / "stingers" / "jingle.mp3")])
# Мусор macOS и файл-призрак.
ff(["-f", "lavfi", "-i", "sine=frequency=400:duration=70", "-c:a", "libmp3lame",
    str(SRC / "__MACOSX" / "junk.mp3")])
shutil.copy(SRC / "tracks" / "01_intro.mp3", SRC / "tracks" / "._01_intro.mp3")

ff(["-f", "lavfi", "-i", "testsrc2=size=1920x1080", "-frames:v", "1",
    str(SRC / "art" / "backdrop.png")])
ff(["-f", "lavfi", "-i", "color=c=0x101418:size=640x360", "-frames:v", "1",
    str(SRC / "art" / "thumb_small.png")])
(SRC / "meta" / "notes.json").write_text(json.dumps({
    "release": {"title": "Soul Notes 007", "catalog": "SN-007"},
    "copy": {"description": "Three slow soul pieces recorded in one evening."},
    "audio": {"genre": "neo soul", "lang": "en"},
}, ensure_ascii=False), encoding="utf-8")
(SRC / "meta" / "readme.md").write_text("Inputs for assembly.", encoding="utf-8")


def pack(root: Path) -> bytes:
    buf = io.BytesIO()
    with zipfile.ZipFile(buf, "w", zipfile.ZIP_DEFLATED) as zf:
        for path in sorted(root.rglob("*")):
            if path.is_file():
                zf.write(path, path.relative_to(root).as_posix())
    return buf.getvalue()


BLOB = pack(SRC)
print(f"архив собран: {len(BLOB) // 1024} КБ")

# --- 1. разбор: что взято, что отброшено -------------------------------------------
with session_scope() as session:
    st.set_value(session, "kie_api_key", "test-key")
    session.commit()
    # Имя без жанра — жанр должен прийти из json («neo soul»).
    first = mv.import_zip(session, BLOB, name="assembly-inputs-v1.zip")
    first_id = first.id

with session_scope() as session:
    row = session.get(MusicVideo, first_id)
    rows = session.execute(
        MusicVideoTrack.__table__.select()
        .where(MusicVideoTrack.video_id == first_id)
        .order_by(MusicVideoTrack.idx)).all()
    assert row.style == "soul", f"жанр не распознан по json: {row.style}"
    assert len(rows) == 3, f"взято композиций {len(rows)}, ждали 3: {[r.title for r in rows]}"
    names = [r.title for r in rows]
    assert names == ["01_intro", "02_groove", "10_outro"], f"порядок сбился: {names}"
    assert all(r.duration_sec > 60 for r in rows)
    assert "jingle" not in " ".join(names), "короткая отбивка попала в микс"
    assert "junk" not in " ".join(names), "мусор macOS попал в микс"
    assert row.title == "Soul Notes 007", f"заголовок из json не взят: {row.title!r}"
    assert "slow soul pieces" in row.description, row.description[:120]
    assert row.backdrop_src.endswith("backdrop.png"), \
        f"в заставку пошла не та картинка: {row.backdrop_src}"
    assert row.source_dir, "папка архива не записана"
    # Материала всего ~3,4 минуты, поэтому берётся привычная для жанра длина.
    assert row.minutes == mv.style_of("soul").minutes, row.minutes
    print(f"1. разбор: {len(rows)} композиции в порядке {names}, "
          f"жанр {row.style}, заголовок «{row.title}»")
    print(f"   заставка из архива: {Path(row.backdrop_src).name}; "
          f"длительность по умолчанию {row.minutes} мин")

# Имя файла тоже должно работать само, без json.
assert mv.guess_style("soul-notes-007-assembly-inputs-v1 (1).zip") == "soul"
assert mv.guess_style("chillstep_pack_02.zip") == "chillstep"
assert mv.guess_style("nothing_here.zip") == ""
print("2. жанр по имени архива: soul-notes-007 → soul, chillstep_pack → chillstep")

# --- 2. сборка: догенерировать недостающее, заставку из архива не заказывать --------
SYN = OUT / "syn"; SYN.mkdir(exist_ok=True)
for index in range(20):
    ff(["-f", "lavfi", "-i", f"sine=frequency={500 + index * 11}:duration=30",
        "-c:a", "aac", str(SYN / f"g{index}.m4a")])

calls = {"suno": 0}


def fake_tracks(client, style, *, model="", timeout=0, poll=0):
    calls["suno"] += 1
    base = (calls["suno"] - 1) * 2
    return [(f"local://{SYN / f'g{base}.m4a'}", f"Generated {base + 1}"),
            (f"local://{SYN / f'g{base + 1}.m4a'}", f"Generated {base + 2}")]


def fake_download(url, dest, **kw):
    dest.parent.mkdir(parents=True, exist_ok=True)
    shutil.copy(url.replace("local://", ""), dest)
    return dest


def forbidden(*a, **kw):
    raise AssertionError("заставка заказана у генератора, хотя она есть в архиве")


music.generate_tracks = fake_tracks
storage.download = fake_download
mv.storage.download = fake_download
real_backdrop = mv.ensure_backdrop
mv.ensure_backdrop = forbidden

with session_scope() as session:
    second = mv.import_zip(session, BLOB, name="soul-notes-007-assembly-inputs-v1.zip",
                           minutes=5)
    mix_id = second.id
    assert second.minutes == 5

mv.build(mix_id)

with session_scope() as session:
    row = session.get(MusicVideo, mix_id)
    rows = session.execute(
        MusicVideoTrack.__table__.select()
        .where(MusicVideoTrack.video_id == mix_id)
        .order_by(MusicVideoTrack.idx)).all()
    assert row.status == "done", f"{row.status}: {row.error}"
    assert len(rows) > 3, "ничего не догенерировалось"
    assert [r.title for r in rows][:3] == ["01_intro", "02_groove", "10_outro"], \
        "материал архива должен идти первым"
    assert calls["suno"] > 0, "Suno не позвали за недостающим"
    assert row.duration_sec >= 300, f"короче заказа: {row.duration_sec:.0f} с"
    assert row.width == 1920 and row.height == 1080
    assert row.credits == 0.0, f"за заставку из архива списано {row.credits}"
    assert Path(row.loop_path).name == "backdrop_from_image.mp4", row.loop_path
    assert storage.abspath(row.video_path).exists()
    print(f"3. сборка: {len(rows)} композиции ({3} из архива, {len(rows) - 3} "
          f"догенерировано за {calls['suno']} заявки), {row.duration_sec / 60:.1f} мин, "
          f"кредитов {row.credits:.0f}")
    print(f"   заставка сделана из картинки архива: {Path(row.loop_path).name}")
    video_file = storage.abspath(row.video_path)
    tracklist = row.tracklist
    assert tracklist.splitlines()[0].startswith("0:00")
    assert "01_intro" in tracklist, tracklist[:100]
    print("   тайм-код начинается с композиции архива: " + tracklist.splitlines()[0])


# Наезд на картинку обязан идти туда-обратно: период 24 с, а не 12.
def gray(path, at):
    raw = Path(str(path) + f".{at}.raw")
    storage.run_ff(["ffmpeg", "-hide_banner", "-loglevel", "error", "-y", "-ss", str(at),
                    "-i", str(path), "-frames:v", "1", "-vf", "scale=32:18,format=gray",
                    "-f", "rawvideo", str(raw)], timeout=120)
    return raw.read_bytes()


def diff(a, b):
    return sum(abs(x - y) for x, y in zip(a, b)) / max(1, len(a))


near, mirrored, straight = gray(video_file, 0.5), gray(video_file, 23.5), gray(video_file, 12.5)
assert diff(near, mirrored) < diff(near, straight), (
    f"наезд не зеркалится: {diff(near, mirrored):.1f} против {diff(near, straight):.1f}")
print(f"4. наезд туда-обратно: кадр на 23,5 с совпал с первым "
      f"({diff(near, mirrored):.1f}), а на 12,5 с — нет ({diff(near, straight):.1f})")

mv.ensure_backdrop = real_backdrop

# --- 3. панель ---------------------------------------------------------------------
client = TestClient(app_main.app)
client.post("/login", data={"username": "admin", "password": "test1234"},
            follow_redirects=False)

# Загрузка архива — своя, третья вкладка.
page = client.get("/music?tab=archive")
assert page.status_code == 200
assert "Собрать из архива" in page.text and 'action="/music/import"' in page.text
assert "определить по архиву" in page.text and "по материалам архива" in page.text
tabs = client.get("/music").text
assert "?tab=archive" in tabs and "Сборка из архива" in tabs, "нет третьей вкладки"
assert 'action="/music/import"' not in tabs, "форма архива осталась на первой вкладке"
print("5. «Сборка из архива» — отдельная третья вкладка, форма только на ней")

with session_scope() as session:
    jobs_before = len(session.execute(Job.__table__.select()).all())
res = client.post("/music/import",
                  files={"file": ("soul-notes-007-assembly-inputs-v1 (1).zip", BLOB,
                                  "application/zip")},
                  data={"minutes": "25", "style": "", "language": "en"},
                  follow_redirects=False)
assert res.status_code == 303, res.status_code
assert "imported=3" in res.headers["location"], res.headers["location"]
with session_scope() as session:
    jobs = session.execute(Job.__table__.select()).all()
    assert len(jobs) == jobs_before + 1 and jobs[-1].kind == "music_video"
    created = session.execute(MusicVideo.__table__.select()).all()[-1]
    assert created.style == "soul" and created.minutes == 25
    assert created.backdrop_src.endswith("backdrop.png")
    web_id = created.id
print(f"6. загрузка через панель: микс #{web_id}, жанр soul, 25 минут, "
      f"3 композиции из архива, задача в очереди")

res = client.post("/music/import", data={"minutes": "25"}, follow_redirects=False)
assert res.status_code == 303 and "error=no-file" in res.headers["location"]
res = client.post("/music/import",
                  files={"file": ("broken.zip", b"not a zip at all", "application/zip")},
                  data={"minutes": "25"}, follow_redirects=False)
assert res.status_code == 303 and "error=bad-zip" in res.headers["location"]
print("7. пустая загрузка и битый zip отвечают понятной ошибкой, а не пятисоткой")

# --- 4. удаление забирает и распакованный архив ------------------------------------
with session_scope() as session:
    row = session.get(MusicVideo, web_id)
    unpacked = storage.abspath(row.source_dir)
    assert unpacked.is_dir()
    mv.drop(session, web_id, with_files=True)
    assert not unpacked.exists(), "распакованный архив остался на диске"
    assert session.get(MusicVideo, web_id) is None
print("8. удаление с файлами забирает и распакованный архив")

print("\nВСЁ ПРОШЛО")
