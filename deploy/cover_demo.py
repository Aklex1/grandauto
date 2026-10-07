"""Примеры обложек: одна горизонтальная для ролика, одна вертикальная для шортса.

Запускать на сервере:

    cd /opt/contentfactory
    venv/bin/python deploy/cover_demo.py            # покажет промпты, ничего не потратит
    venv/bin/python deploy/cover_demo.py --go       # сгенерирует обе картинки (платно)

Ключ KIE берётся из настроек панели — в командной строке его передавать не надо
и никуда вставлять тоже. Готовые файлы кладутся в media и показываются ссылками,
по которым их видно прямо в панели.

Зачем отдельная команда. Кнопка в библиотеке делает обложку уже существующему
ролику, а посмотреть, как это вообще выглядит, хочется до того, как что-то
собрано. Эта команда рисует пример на любой подложке и говорит, во сколько он
обошёлся.
"""
import argparse
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))

from app import chrome, config, covers, musicvideo as mv, storage
from app import settings_store as st
from app.db import session_scope
from app.kie import KieClient


def plate(dst: Path, vertical: bool) -> Path:
    """Запасная подложка, если генерация выключена: показать компоновку текста."""
    from app import backdrops

    clip = dst.with_suffix(".mp4")
    backdrops.render(clip, "aurora", 2, 5)
    mv.media.frame_grab(clip, dst, at=0.5)
    clip.unlink(missing_ok=True)
    return dst


def main() -> int:
    ap = argparse.ArgumentParser(description="Примеры обложек ролика и шортса")
    ap.add_argument("--go", action="store_true",
                    help="генерировать картинки (платно); без него только промпты")
    ap.add_argument("--style", default="chillstep", help="жанр, по умолчанию chillstep")
    ap.add_argument("--seed", type=int, default=-1,
                    help="номер сюжета; по умолчанию берётся тот, которого ещё не было")
    ap.add_argument("--list", action="store_true",
                    help="показать весь контент-план сюжетов и выйти")
    ap.add_argument("--title", default="Aurora Drive", help="заголовок на обложке")
    ap.add_argument("--short-title", default="Drift Beyond", help="заголовок шортса")
    args = ap.parse_args()

    style = mv.style_of(args.style)
    hint = covers.style_hint_of(style.label, style.use)

    if args.list:
        for vertical, title in ((False, "РОЛИКИ"), (True, "ШОРТСЫ")):
            rows = covers.rows_for(vertical)
            print(f"\n=== {title}: {len(rows)} сюжетов")
            for number, scene in enumerate(rows, 1):
                print(f"{number:3}. {scene.key:22} {scene.subject.split(',')[0]} — "
                      f"{scene.place.split(',')[0]}")
        print(f"\nвсего сюжетов: "
              f"{len(covers.rows_for(False)) + len(covers.rows_for(True))}")
        return 0

    print(f"жанр: {style.label}\n")
    # Сюжет берём тот, которого ещё не было, — так же, как это делает завод.
    scenes = {}
    with session_scope() as session:
        for vertical in (False, True):
            scenes[vertical] = (covers.pick(args.seed, vertical=vertical)
                                if args.seed >= 0
                                else mv.fresh_scene(session, vertical=vertical))
    for vertical in (False, True):
        kind = "шортс 9:16" if vertical else "ролик 16:9"
        print(f"--- {kind}: сюжет «{scenes[vertical].key}»")
        print(covers.build_prompt(scenes[vertical], hint, vertical=vertical))
        print()

    if not args.go:
        print("Это был показ без трат. Чтобы сгенерировать обе картинки, "
              "повторите с --go.")
        return 0

    with session_scope() as session:
        key = st.get(session, "kie_api_key", "") or config.KIE_API_KEY
        model = st.get(session, "default_image_model", "nano-banana-2")
        channel_logo = None
        from app.models import MusicChannel
        from sqlalchemy import select

        channel = session.execute(select(MusicChannel)).scalars().first()
        if channel is not None:
            channel_logo = mv._logo_of(session, channel.id)
    if not key:
        print("Ключ KIE не задан в панели (Настройки → ключи). Без него генерации нет.")
        return 2

    out = config.MEDIA_DIR / "_musicvideo" / "_covers"
    out.mkdir(parents=True, exist_ok=True)
    client = KieClient(api_key=key)
    spent = 0.0

    for vertical, title, badge, note in (
            (False, args.title, "35 min", style.use),
            (True, args.short_title, "48 sec",
             channel.name if channel is not None else style.label)):
        kind = "short" if vertical else "mix"
        art = out / f"demo_{kind}_art.png"
        try:
            _path, credits, _prompt = covers.make(
                client, art, model=model, scene=scenes[vertical], style_hint=hint,
                vertical=vertical)
            spent += credits
            print(f"{kind}: картинка готова, {credits:.1f} кредитов")
        except Exception as exc:  # noqa: BLE001 — покажем компоновку и без неё
            print(f"{kind}: генерация не удалась ({exc}); рисую на запасной подложке")
            plate(art, vertical)

        cover = out / f"demo_{kind}_cover.jpg"
        chrome.cover(cover, art, title=title, note=note, badge=badge,
                     logo=channel_logo, accent=abs(args.seed) + 3,
                     size=chrome.COVER_VERTICAL if vertical else chrome.COVER_SIZE)
        print(f"{kind}: обложка {cover}")
        print(f"      в панели: /media/{storage.rel(cover)}")

    print(f"\nвсего потрачено: {spent:.1f} кредитов")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
