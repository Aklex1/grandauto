"""Вернуть озвучку из уже собранных роликов в кэш — чтобы пересборка была бесплатной.

Зачем. Серии, собранные до появления кэша озвучки, свой голос не сохранили:
рабочая папка стирается после сборки. Пересобрать их (например, чтобы обложка
перестала обрезаться) значило бы озвучить всё заново и заплатить второй раз.
Но голос никуда не делся — он в звуковой дорожке готового ролика. Скрипт
вытаскивает её и кладёт туда, где сборка ищет готовую озвучку.

Важное ограничение. Если у канала была включена фоновая музыка, в дорожке лежит
голос ВМЕСТЕ с ней, и при пересборке музыка наложится второй раз. Поэтому такие
каналы пропускаются: ключ --force снимает запрет, но звук будет грязный.

Запуск на сервере:
    venv/bin/python deploy/recover_voices.py            # показать, что будет
    venv/bin/python deploy/recover_voices.py --apply    # сделать
"""
import argparse
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))

from sqlalchemy import select

from app import archives, config, storage
from app.db import session_scope
from app.models import ArchiveItem, Channel


def main(argv: list) -> int:
    parser = argparse.ArgumentParser(description="Озвучка из готовых роликов в кэш")
    parser.add_argument("--apply", action="store_true",
                        help="сделать, а не только показать")
    parser.add_argument("--force", action="store_true",
                        help="брать дорожку даже при включённой фоновой музыке "
                             "(в озвучку попадёт и она)")
    parser.add_argument("--channel", type=int, default=0,
                        help="только этот канал")
    args = parser.parse_args(argv)

    saved = skipped = missing = 0
    with session_scope() as session:
        query = select(ArchiveItem).where(ArchiveItem.status == "done")
        if args.channel:
            query = query.where(ArchiveItem.channel_id == args.channel)
        rows = session.execute(query.order_by(ArchiveItem.channel_id,
                                              ArchiveItem.idx)).scalars().all()
        print(f"готовых серий: {len(rows)}")

        for item in rows:
            channel = session.get(Channel, item.channel_id)
            if channel is None:
                continue
            if channel.background_music and not args.force:
                skipped += 1
                continue
            video = storage.abspath(item.video_path) if item.video_path else None
            if video is None or not video.exists():
                missing += 1
                continue

            dest = archives.voice_cache_path(channel, item)
            if dest.exists() and storage.media_duration(dest) > 0.5:
                continue
            print(f"  {channel.slug} · серия {item.folder}: {video.name} → {dest.name}")
            if not args.apply:
                saved += 1
                continue
            try:
                storage.run_ff([
                    config.FFMPEG, "-hide_banner", "-loglevel", "error", "-y",
                    "-i", str(video), "-vn", "-c:a", "aac", "-b:a", "160k",
                    "-ar", "48000", "-ac", "2", str(dest)], timeout=600)
            except Exception as exc:  # noqa: BLE001 — одна серия не должна ронять проход
                print(f"    не вышло: {exc}")
                dest.unlink(missing_ok=True)
                continue
            if storage.media_duration(dest) <= 0.5:
                dest.unlink(missing_ok=True)
                print("    пусто, пропускаю")
                continue
            saved += 1

    print(f"\n{'сохранено' if args.apply else 'будет сохранено'}: {saved}")
    if skipped:
        print(f"пропущено из-за фоновой музыки: {skipped} "
              f"(их дорожка — голос вместе с музыкой; --force снимает запрет)")
    if missing:
        print(f"без файла ролика: {missing}")
    if not args.apply and saved:
        print("это был показ. Повторите с --apply, чтобы сделать")
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
