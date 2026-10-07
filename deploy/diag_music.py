"""Почему музыкальный микс собрался не так: треки, заставка, тайм-код.

Запускать на сервере: venv/bin/python deploy/diag_music.py [id_микса]

Показывает, сколько музыки реально лежит на диске, откуда взялась заставка и
сходится ли тайм-код с длиной ролика. Ничего не меняет и не запускает.
"""
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))

from sqlalchemy import select

from app import musicvideo as mv
from app import storage
from app.db import session_scope
from app.models import LoopClip, MusicVideo, MusicVideoTrack


def show(session, video: MusicVideo, deep: bool) -> None:
    style = mv.style_of(video.style)
    print(f"\n=== микс #{video.id} «{video.title or video.yt_title or '—'}»")
    print(f"  жанр: {style.label} ({video.style}), заказано {video.minutes} мин, "
          f"модель {video.suno_model}")
    print(f"  состояние: {video.status} / {mv.STAGES.get(video.stage, video.stage)}")
    if video.error:
        print(f"  ошибка: {video.error[:300]}")

    rows = session.execute(
        select(MusicVideoTrack).where(MusicVideoTrack.video_id == video.id)
        .order_by(MusicVideoTrack.idx)).scalars().all()
    alive = [r for r in rows if r.path and storage.abspath(r.path).exists()]
    total = sum(r.duration_sec for r in alive)
    print(f"  треков: записей {len(rows)}, файлов на диске {len(alive)}, "
          f"сумма {total / 60:.1f} мин")
    if len(rows) != len(alive):
        print("    ВНИМАНИЕ: часть треков потеряна с диска — пересборка их "
              "перекачает (и это будет платно)")
    if deep:
        for row in rows:
            ok = row.path and storage.abspath(row.path).exists()
            print(f"    {row.idx:>3}  {mv.timecode(row.start_sec):>8}  "
                  f"{row.duration_sec:>6.0f} с  {'есть' if ok else 'НЕТ ФАЙЛА'}  "
                  f"{row.title[:40]}")

    if video.crossfade_sec:
        want = mv.effective_duration([r.duration_sec for r in alive], video.crossfade_sec)
        print(f"  переход {video.crossfade_sec:.1f} с → ожидаемая длина "
              f"{want / 60:.1f} мин")

    video_file = storage.abspath(video.video_path) if video.video_path else None
    if video_file is not None and video_file.exists():
        real = storage.media_duration(video_file)
        print(f"  ролик: {video.video_path}")
        print(f"    {real / 60:.1f} мин, {video.width}×{video.height}, "
              f"{video_file.stat().st_size / 1048576:.0f} МБ")
        if alive:
            last = alive[-1]
            end = last.start_sec + last.duration_sec
            if end > real + 3:
                print(f"    ВНИМАНИЕ: последняя метка тайм-кода {mv.timecode(end)} "
                      f"выходит за конец ролика {mv.timecode(real)}")
    else:
        print(f"  ролик: {video.video_path or 'не собран'}"
              f"{'  ← файла нет' if video.video_path else ''}")

    shot = mv.backdrop_for(session, video.style)
    if shot is not None:
        print(f"  заставка жанра в библиотеке: #{shot.id}, "
              f"{shot.duration_sec:.0f} с, {shot.width}×{shot.height}, "
              f"{shot.credits:.0f} кредитов")
        if video.loop_id and video.loop_id != shot.id:
            print(f"    этот микс собран на другой заставке #{video.loop_id} — "
                  f"пересборка возьмёт новую")
    else:
        print("  заставка жанра: в библиотеке нет — следующая сборка её закажет (платно)")
    print(f"  зеркальное повторение заставки: "
          f"{'да' if style.pingpong else 'нет (движение направленное)'}")


def main(argv: list) -> int:
    want = int(argv[0]) if argv and argv[0].isdigit() else 0
    with session_scope() as session:
        query = select(MusicVideo).order_by(MusicVideo.id)
        rows = session.execute(
            query.where(MusicVideo.id == want) if want else query).scalars().all()
        if not rows:
            print("миксов нет" + (f" с номером {want}" if want else ""))
        for row in rows:
            show(session, row, deep=bool(want))

        library = session.execute(
            select(LoopClip).where(LoopClip.fmt.like(mv.FMT_PREFIX + "%"),
                                   LoopClip.is_active.is_(True))
            .order_by(LoopClip.fmt)).scalars().all()
        print(f"\n=== заставки в библиотеке: {len(library)}")
        for shot in library:
            key = shot.fmt[len(mv.FMT_PREFIX):]
            on_disk = shot.path and storage.abspath(shot.path).exists()
            print(f"  {key:16} {shot.duration_sec:>5.0f} с  {shot.width}×{shot.height}  "
                  f"{'есть' if on_disk else 'НЕТ ФАЙЛА'}  {shot.credits:.0f} кредитов")
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
