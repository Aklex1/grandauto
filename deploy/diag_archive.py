"""Почему архивная серия собралась не так: формат, кадры, титры.

Запускать на сервере: venv/bin/python deploy/diag_archive.py [id_архива]

Показывает, каким форматом принят архив, нашлись ли сюжетные кадры и раскладка
титров, и какие файлы реально лежат на диске. Ничего не меняет и не запускает.
"""
import json
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))

from sqlalchemy import func, select

from app import archives, storage, tts
from app.db import session_scope
from app.models import ArchiveBatch, ArchiveItem


def show_item(item: ArchiveItem, deep: bool) -> None:
    print(f"  серия {item.folder}: {item.status}, «{item.title[:50]}»")
    print(f"    речь: {len(item.narration.split())} слов, начало: "
          f"{item.narration[:60]!r}")
    src = storage.abspath(item.source_dir) if item.source_dir else None
    print(f"    папка серии: {item.source_dir or 'НЕ СОХРАНЕНА'}"
          f"{'' if src and src.exists() else '  ← на диске её нет'}")

    scenes, plan = archives._scene_plan(item)
    if not scenes:
        print("    сцены: НЕТ — кадры показать неоткуда, "
              "ролик соберётся на одной обложке")
    else:
        alive = 0
        for scene in scenes:
            path = (src / str(scene.get("asset"))) if src else None
            ok = bool(path and path.exists())
            alive += 1 if ok else 0
            if deep:
                print(f"      сцена {scene.get('id')}: {scene.get('asset')} "
                      f"{'есть' if ok else 'НЕТ ФАЙЛА'}, "
                      f"токенов {scene.get('tokens') or scene.get('words')}")
        print(f"    сцены: {len(scenes)}, кадров на диске {alive}")
        if plan:
            print(f"    тайминг пакета: обложка до {plan.get('cover_gone_ms')} мс, "
                  f"переход {plan.get('scene_fade_ms')} мс")

    # Сохранённая озвучка: по ней слышно, шумит ли сам голос или шум добавила
    # сборка. Файл можно скопировать и послушать отдельно.
    from app.models import Channel as _Channel
    with session_scope() as inner:
        channel = inner.get(_Channel, item.channel_id)
        voice = archives.voice_cache_path(channel, item) if channel else None
    if voice is not None and voice.exists():
        print(f"    озвучка в кэше: {voice}")
        print(f"      длина {storage.media_duration(voice):.1f} с, "
              f"ожидается по тексту {tts.expected_seconds(item.narration):.0f} с")
    else:
        print("    озвучка в кэше: нет")

    try:
        layout = json.loads(item.captions_json or "{}")
    except ValueError:
        layout = {}
    if layout.get("pages"):
        print(f"    титры: {len(layout['pages'])} страниц, токенов "
              f"{layout.get('total')}, зона {layout.get('zone')}")
    else:
        print("    титры: своей раскладки нет — режем текст сами")


def main(argv: list) -> int:
    want = int(argv[0]) if argv and argv[0].isdigit() else 0
    with session_scope() as session:
        query = select(ArchiveBatch).order_by(ArchiveBatch.id)
        if want:
            query = query.where(ArchiveBatch.id == want)
        batches = session.execute(query).scalars().all()
        if not batches:
            print("архивов нет")
            return 0

        for batch in batches:
            counts = dict(session.execute(
                select(ArchiveItem.status, func.count(ArchiveItem.id))
                .where(ArchiveItem.batch_id == batch.id)
                .group_by(ArchiveItem.status)).all())
            print(f"\n=== архив #{batch.id} «{batch.name}» (канал {batch.channel_id})")
            print(f"  формат: {archives.PRESETS.get(batch.preset, batch.preset)}"
                  f"{'  ' + batch.package_format if batch.package_format else ''}")
            if batch.preset != archives.PRESET_STORY:
                print("  ВНИМАНИЕ: архив принят прежним форматом. Если это пакет "
                      "production.v2, значит он загружался на старой версии завода "
                      "— его надо загрузить заново, сюжетных кадров у этих серий нет")
            print(f"  серий: {batch.total}, по состояниям: {counts or 'пусто'}")
            print(f"  на паузе: {'да' if batch.paused else 'нет'}, "
                  f"в день: {batch.per_day or 'все разом'}")
            root = storage.abspath(batch.path) if batch.path else None
            print(f"  распаковано в: {batch.path}"
                  f"{'' if root and root.exists() else '  ← папки нет'}")

            items = session.execute(
                select(ArchiveItem).where(ArchiveItem.batch_id == batch.id)
                .order_by(ArchiveItem.idx).limit(3 if not want else 5)).scalars().all()
            for item in items:
                show_item(item, deep=bool(want))
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
