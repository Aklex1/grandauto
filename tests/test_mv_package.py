"""Пакет вида soul-notes-007: готовый мастер, дубли сырья, плашки, тайм-код."""
import io
import json
import os
import shutil
import sys
import zipfile

sys.path.insert(0, "/home/user/grandauto")
from pathlib import Path

from app import bootstrap; bootstrap.run()
from app import main as app_main, music, musicvideo as mv, storage
from app import settings_store as st
from app.db import session_scope
from app.models import MusicVideo, MusicVideoTrack
from fastapi.testclient import TestClient

OUT = Path(os.environ["OUT"]); OUT.mkdir(parents=True, exist_ok=True)


def ff(args, timeout=300):
    storage.run_ff(["ffmpeg", "-hide_banner", "-loglevel", "error", "-y"] + args,
                   timeout=timeout)


def mp3(path: Path, span: int, freq: int):
    path.parent.mkdir(parents=True, exist_ok=True)
    ff(["-f", "lavfi", "-i", f"sine=frequency={freq}:duration={span}",
        "-c:a", "libmp3lame", str(path)])


def m4a(path: Path, span: int, freq: int):
    path.parent.mkdir(parents=True, exist_ok=True)
    ff(["-f", "lavfi", "-i", f"sine=frequency={freq}:duration={span}",
        "-c:a", "aac", str(path)])


# --- слепок настоящего пакета -------------------------------------------------------
SRC = OUT / "soul-notes-007"
if SRC.exists():
    shutil.rmtree(SRC)
SRC.mkdir(parents=True)

# Сведённый мастер — в пакете он и есть микс.
m4a(SRC / "music/master/sn007-velvet-afterglow-full-v1.m4a", 200, 240)
# Сырьё Suno: три композиции по два варианта. Второй вариант — тот же материал.
SHAS = ["039cf388", "2e16ed2a", "3f4c83db"]
for index, sha in enumerate(SHAS):
    mp3(SRC / f"music/raw/{sha}/raw/variation-01.mp3", 120, 200 + index * 30)
    mp3(SRC / f"music/raw/{sha}/raw/variation-02.mp3", 130, 205 + index * 30)
    (SRC / f"music/raw/{sha}/ARCHIVE_RECEIPT.json").write_text(
        json.dumps({"task": sha}), encoding="utf-8")
# Производное: нарезка и прослушки. В микс им нельзя, хотя они длиннее минимума.
for number in (1, 2, 3):
    m4a(SRC / f"music/shorts/sn007-short0{number}-60s.m4a", 60, 500 + number)
    m4a(SRC / f"music/tests/{chr(64 + number)}-60s.m4a", 60, 600 + number)
    mp3(SRC / f"music/tests/{chr(64 + number)}-instrumental-v1-20s-80s.mp3", 60, 700 + number)

# Тайм-код пакета. Он сведён не нами, и по длинам сырья его не восстановить.
CHAPTERS = [
    {"index": 1, "title": "A — Instrumental", "start": 0},
    {"index": 2, "title": "B — Wordless Female", "start": 38},
    {"index": 3, "title": "C — Stay Close", "start": 95},
    {"index": 4, "title": "A — Velvet Window", "start": 150},
]
(SRC / "music/timeline.json").parent.mkdir(parents=True, exist_ok=True)
(SRC / "music/timeline.json").write_text(json.dumps(
    {"mix": "sn007", "segments": CHAPTERS}, ensure_ascii=False), encoding="utf-8")

# Плашки: вертикальные для шортсов тяжелее горизонтальных — ловушка по весу.
(SRC / "visual/plates").mkdir(parents=True, exist_ok=True)
(SRC / "visual/plates-v2").mkdir(parents=True, exist_ok=True)
ff(["-f", "lavfi", "-i", "color=c=0x101418:size=1920x1080", "-frames:v", "1",
    str(SRC / "visual/plates/master-landscape.png")])
ff(["-f", "lavfi", "-i", "color=c=0x14181f:size=1920x1080", "-frames:v", "1",
    str(SRC / "visual/plates-v2/master-landscape-v3.png")])
for name in ("portrait-01-v2", "portrait-02-v2", "portrait-03-v2"):
    ff(["-f", "lavfi", "-i", "testsrc2=size=1080x1920", "-frames:v", "1",
        str(SRC / f"visual/plates-v2/{name}.png")])
(SRC / "visual/ACTIVE_PLATES.json").write_text(json.dumps({
    "master": {"landscape": "master-landscape-v3.png"},
    "shorts": ["portrait-01-v2.png", "portrait-02-v2.png"],
}), encoding="utf-8")
# Контрольные картинки — они в qa, их брать нельзя.
(SRC / "visual/animation/qa").mkdir(parents=True, exist_ok=True)
ff(["-f", "lavfi", "-i", "testsrc2=size=1920x1080", "-frames:v", "1",
    str(SRC / "visual/animation/qa/master-raw-0s.png")])

(SRC / "metadata").mkdir(parents=True, exist_ok=True)
(SRC / "metadata/metadata-en-master.json").write_text(json.dumps({
    "youtube": {"title": "Velvet Afterglow — Slow Soul for Late Evenings",
                "description": "Four slow soul pieces for a quiet night."},
    "audio": {"genre": "neo soul"},
}, ensure_ascii=False), encoding="utf-8")
(SRC / "ASSEMBLY_PLAN.json").write_text(json.dumps({"target": "landscape"}),
                                        encoding="utf-8")

# Текстовое исследование: общий замысел плюс задание на каждую композицию.
(SRC / "CLAUDE_BRIEF.md").write_text(
    "# Velvet Afterglow\n\nСлоу-соул для поздних вечеров. Основа — Fender Rhodes "
    "и мягкий аналоговый бас, живые барабаны с глубоким пульсом около 80 bpm. "
    "Развитие от разреженного вступления к плотной середине со струнными. "
    "Бэк-вокал без слов, женский, только как краска.\n",
    encoding="utf-8")
(SRC / "music/DEVELOPMENT_BRIEF.md").write_text(
    "Каждая вещь начинается с одного инструмента и прирастает слоями.\n",
    encoding="utf-8")
SPECS = [
    ("01-a-instrumental", {"letter": "A", "instruments": "rhodes, upright bass, brushes",
                           "development": "sparse intro, strings from the middle",
                           "vocals": "none", "bpm": 78}),
    ("02-b-wordless-female", {"letter": "B", "instruments": "rhodes, clavinet, live drums",
                              "development": "steady groove, horns on the last third",
                              "vocals": "wordless female, texture only", "bpm": 82}),
    ("03-c-stay-close", {"letter": "C", "instruments": "electric guitar, organ, bass",
                         "development": "slow build to a gospel-tinged climax",
                         "vocals": "none", "bpm": 76}),
]
for stem, body in SPECS:
    (SRC / f"music/specs/{stem}.json").parent.mkdir(parents=True, exist_ok=True)
    (SRC / f"music/specs/{stem}.json").write_text(
        json.dumps(body, ensure_ascii=False), encoding="utf-8")
(SRC / "START_HERE.md").write_text("Inputs for assembly of SN-007.", encoding="utf-8")

sizes = {p.relative_to(SRC).as_posix(): p.stat().st_size for p in SRC.rglob("*") if p.is_file()}
assert sizes["visual/plates-v2/portrait-02-v2.png"] > \
    sizes["visual/plates-v2/master-landscape-v3.png"], "ловушка по весу не воспроизвелась"


def pack(root: Path) -> bytes:
    buf = io.BytesIO()
    with zipfile.ZipFile(buf, "w", zipfile.ZIP_DEFLATED) as zf:
        for path in sorted(root.rglob("*")):
            if path.is_file():
                zf.write(path, path.relative_to(root).as_posix())
    return buf.getvalue()


BLOB = pack(SRC)
print(f"слепок пакета: файлов {len(sizes)}, zip {len(BLOB) // 1024} КБ")

# --- 1. разбор -------------------------------------------------------------------
def forbidden_music(*a, **kw):
    raise AssertionError("Suno позвали, хотя в пакете готовый мастер")


def forbidden_backdrop(*a, **kw):
    raise AssertionError("заставка заказана, хотя в пакете есть плашка")


music.generate_tracks = forbidden_music
mv.ensure_backdrop = forbidden_backdrop

with session_scope() as session:
    st.set_value(session, "kie_api_key", "test-key")
    session.commit()
    video = mv.import_zip(session, BLOB, name="soul-notes-007-assembly-inputs-v1 (1).zip")
    mix_id = video.id

with session_scope() as session:
    row = session.get(MusicVideo, mix_id)
    rows = session.execute(
        MusicVideoTrack.__table__.select().where(MusicVideoTrack.video_id == mix_id)
        .order_by(MusicVideoTrack.idx)).all()
    names = [r.title for r in rows]
    assert len(rows) == 1, f"взято {len(rows)} дорожек вместо одного мастера: {names}"
    assert "full" in names[0], names[0]
    assert abs(rows[0].duration_sec - 200) < 2, rows[0].duration_sec
    print(f"1. мастер найден и взят один: {names[0]} ({rows[0].duration_sec:.0f} с)")
    print("   сырьё, шортсы и прослушки не попали — иначе дорожек было бы "
          f"{1 + 6 + 3 + 6}")

    assert row.style == "soul", row.style
    assert row.title.startswith("Velvet Afterglow"), row.title
    assert "slow soul pieces" in row.description, row.description[:120]
    assert row.minutes == 3, f"длительность не по мастеру: {row.minutes}"
    assert row.master_ready, "мастер не отмечен как готовый — завод полезет догенерировать"
    print(f"2. жанр {row.style}, заголовок из metadata, длительность по мастеру "
          f"{row.minutes} мин")

    assert Path(row.backdrop_src).name == "master-landscape-v3.png", row.backdrop_src
    print(f"3. плашка выбрана по ACTIVE_PLATES: {Path(row.backdrop_src).name} "
          f"(вертикальная portrait-02-v2.png тяжелее, но не взята)")

    lines = row.chapters_src.splitlines()
    assert len(lines) == 4, row.chapters_src
    assert lines[0] == "0:00 A — Instrumental", lines[0]
    assert lines[1].startswith("0:38"), lines[1]
    assert lines[3].startswith("2:30"), lines[3]
    print(f"4. тайм-код взят из timeline.json: {len(lines)} глав, "
          f"от «{lines[0]}» до «{lines[3]}»")

# --- 1б. исследование прочитано ------------------------------------------------------
with session_scope() as session:
    row = session.get(MusicVideo, mix_id)
    assert row.brief, "исследование не найдено в архиве"
    assert "Fender Rhodes" in row.brief, row.brief[:200]
    assert "прирастает слоями" in row.brief, "второй файл брифа не подхватился"
    # Служебные файлы в бриф попадать не должны — там нет замысла.
    assert "ARCHIVE_RECEIPT" not in row.brief
    print(f"5. исследование из архива прочитано: {len(row.brief)} знаков из двух файлов")

specs = mv.read_specs([p for p in storage.abspath(row.source_dir).rglob("*")
                       if p.suffix.lower() in mv.TEXT_EXT])
assert len(specs) == 3, [s[:40] for s in specs]
assert specs[0].startswith("01-a-instrumental"), specs[0][:60]
assert "wordless female" in specs[1], specs[1][:120]
assert not any("MANIFEST" in s or "RECEIPT" in s for s in specs)
print(f"6. задания на композиции: {len(specs)} в порядке номеров, "
      f"у второй «{[w for w in specs[1].split('; ') if 'vocals' in w][0]}»")

# Без чат-модели задания собираются обрезкой — но всё равно по исследованию.
with session_scope() as session:
    st.set_value(session, "default_chat_model", "")
    session.commit()
    plan = mv.plan_from_brief(session, mv.style_of("soul"), row.brief, specs, count=2)
assert len(plan) == 3, len(plan)
assert all(len(p) <= mv.PROMPT_LIMIT for p in plan), [len(p) for p in plan]
assert "rhodes" in plan[0].lower() and "Fender Rhodes" in plan[0]
assert "wordless female" in plan[1]
assert plan[0] != plan[1], "задания вышли одинаковыми"
print(f"7. без чат-модели задания всё равно по исследованию: {len(plan)} шт., "
      f"до {max(len(p) for p in plan)} знаков, инструменты и бэк-вокал на месте")

# --- 2. сборка: ни одного обращения к генераторам ------------------------------------
mv.build(mix_id)

with session_scope() as session:
    row = session.get(MusicVideo, mix_id)
    assert row.status == "done", f"{row.status}: {row.error}"
    assert row.width == 1920 and row.height == 1080
    assert abs(row.duration_sec - 200) < 3, row.duration_sec
    assert row.credits == 0.0, row.credits
    assert row.tracklist.splitlines()[0] == "0:00 A — Instrumental", row.tracklist[:80]
    assert "A — Velvet Window" in row.description, row.description[-200:]
    assert storage.abspath(row.video_path).exists()
    print(f"8. собран без единой генерации: {row.duration_sec / 60:.1f} мин, "
          f"1920×1080, {row.file_size // 1048576} МБ, кредитов {row.credits:.0f}")
    print("   тайм-код архива попал и в описание, и в карточку")

# --- 3. тот же пакет без мастера: по одному варианту на композицию -------------------
NOMASTER = OUT / "no-master"
if NOMASTER.exists():
    shutil.rmtree(NOMASTER)
shutil.copytree(SRC, NOMASTER)
shutil.rmtree(NOMASTER / "music/master")
(NOMASTER / "music/timeline.json").unlink()

with session_scope() as session:
    second = mv.import_zip(session, pack(NOMASTER), name="soul-notes-007-raw.zip",
                           minutes=5)
    raw_id = second.id

with session_scope() as session:
    rows = session.execute(
        MusicVideoTrack.__table__.select().where(MusicVideoTrack.video_id == raw_id)
        .order_by(MusicVideoTrack.idx)).all()
    names = [r.title for r in rows]
    assert len(rows) == 3, f"дубли не отсеялись: {names}"
    assert all(n == "variation-01" for n in names), names
    row = session.get(MusicVideo, raw_id)
    assert not row.chapters_src, "тайм-кода нет, а он откуда-то взялся"
    assert not row.master_ready, "без мастера не должно быть отметки о мастере"
    assert row.minutes == 5, f"заказанная длина не учтена: {row.minutes}"
    print(f"9. без мастера: {len(rows)} композиции — по первому варианту из каждой "
          f"папки, второй отброшен как дубль")

# --- 4. панель видит происхождение --------------------------------------------------
client = TestClient(app_main.app)
client.post("/login", data={"username": "admin", "password": "test1234"},
            follow_redirects=False)
lib = client.get("/music?tab=library")
assert lib.status_code == 200
assert "из архива" in lib.text and "заставка своя" in lib.text
assert "тайм-код из архива" in lib.text and "по исследованию" in lib.text
print("10. в библиотеке помечено: из архива, заставка своя, тайм-код из архива, "
      "по исследованию")

print("\nВСЁ ПРОШЛО")
