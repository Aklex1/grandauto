"""Нарисовать заставку канала из командной строки.

    python3 deploy/make_backdrop.py --out assets/lumen-drift/loop_06.mp4 --preset snowfall

Рисовалка живёт в app/backdrops.py — ею же пользуется кнопка «Сгенерировать»
в панели, чтобы командная строка и панель не разъезжались.
"""
import argparse
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))

from app.backdrops import PRESETS, render


def main(argv: list) -> int:
    parser = argparse.ArgumentParser(description="Зацикленная заставка канала")
    parser.add_argument("--out", required=True)
    parser.add_argument("--preset", default="aurora", choices=sorted(PRESETS))
    parser.add_argument("--seconds", type=float, default=30.0)
    parser.add_argument("--seed", type=int, default=7)
    args = parser.parse_args(argv)
    out = Path(args.out)
    out.parent.mkdir(parents=True, exist_ok=True)
    render(out, args.preset, args.seconds, args.seed)
    print(f"готово: {out} ({out.stat().st_size // 1024} КБ)")
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
