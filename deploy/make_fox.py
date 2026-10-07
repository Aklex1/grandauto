"""Маскот канала: белая лиса. Спрайт и короткие клипы с прозрачностью.

Рисуем её кодом по тем же причинам, что и заставки: стиль плоский векторный,
генераторы такое не делают, а нарисованную лису можно крутить как угодно и
бесплатно.

Кадров нужно мало: спрайт маленький, а движение по экрану добавляет ffmpeg при
сборке ролика. Готовятся два действия — бег и «вышла, села, помахала хвостом».
"""
from __future__ import annotations

import argparse
import math
import sys
import tempfile
from pathlib import Path

from PIL import Image, ImageDraw

W, H = 300, 200          # размер спрайта
FUR = (0xF4, 0xF7, 0xFC)
SHADE = (0xD3, 0xDC, 0xEA)
DARK = (0x1B, 0x22, 0x36)
TEAL = (0x7F, 0xE3, 0xD4)


def _leg(draw, x, y, length, angle, width, color):
    rad = math.radians(angle)
    x2, y2 = x + math.sin(rad) * length, y + math.cos(rad) * length
    draw.line((x, y, x2, y2), fill=color + (255,), width=width)
    draw.ellipse((x2 - width * 0.6, y2 - width * 0.6, x2 + width * 0.6, y2 + width * 0.6),
                 fill=color + (255,))


def _tail(draw, bx, by, sweep, color):
    """Хвост дугой: чем больше sweep, тем сильнее занесён вбок."""
    points = []
    for step in range(16):
        k = step / 15
        ang = math.radians(150 + sweep * k - 70 * k)
        rad = 56 * (0.35 + k)
        points.append((bx + math.cos(ang) * rad, by - math.sin(ang) * rad * 0.8))
    width = 30
    for index, (px, py) in enumerate(points):
        r = width * (0.35 + 0.65 * (index / len(points)))
        draw.ellipse((px - r, py - r, px + r, py + r), fill=color + (255,))


def _fox(pose: str, phase: float) -> Image.Image:
    """Один кадр. pose: run | sit. phase от 0 до 1 — фаза движения."""
    img = Image.new("RGBA", (W, H), (0, 0, 0, 0))
    draw = ImageDraw.Draw(img)
    swing = math.sin(phase * 2 * math.pi)

    if pose == "run":
        body_y = 112 - abs(swing) * 5
        _tail(draw, 92, body_y - 8, 40 + swing * 26, SHADE)
        for side, base in ((1, 108), (1, 150), (-1, 118), (-1, 160)):
            _leg(draw, base, body_y + 18, 34, 18 * swing * side, 13, SHADE if side < 0 else FUR)
        draw.ellipse((86, body_y - 26, 190, body_y + 26), fill=FUR + (255,))
        hx, hy = 196, body_y - 28
        draw.polygon([(hx - 10, hy - 6), (hx - 2, hy - 34), (hx + 12, hy - 8)],
                     fill=FUR + (255,))
        draw.polygon([(hx + 10, hy - 8), (hx + 22, hy - 32), (hx + 30, hy - 4)],
                     fill=FUR + (255,))
        draw.ellipse((hx - 14, hy - 12, hx + 34, hy + 30), fill=FUR + (255,))
        draw.polygon([(hx + 26, hy + 2), (hx + 54, hy + 14), (hx + 26, hy + 22)],
                     fill=FUR + (255,))
        draw.ellipse((hx + 48, hy + 11, hx + 56, hy + 19), fill=DARK + (255,))
        draw.ellipse((hx + 16, hy + 6, hx + 24, hy + 14), fill=DARK + (255,))
    else:
        body_y = 118
        _tail(draw, 96, body_y + 4, 24 + swing * 46, SHADE)
        _leg(draw, 150, body_y + 20, 30, 6, 13, FUR)
        _leg(draw, 126, body_y + 24, 26, -4, 13, SHADE)
        draw.ellipse((96, body_y - 34, 176, body_y + 34), fill=FUR + (255,))
        hx, hy = 150, body_y - 54
        draw.polygon([(hx - 12, hy + 16), (hx - 4, hy - 14), (hx + 10, hy + 14)],
                     fill=FUR + (255,))
        draw.polygon([(hx + 12, hy + 14), (hx + 24, hy - 12), (hx + 32, hy + 16)],
                     fill=FUR + (255,))
        draw.ellipse((hx - 14, hy + 4, hx + 34, hy + 48), fill=FUR + (255,))
        draw.polygon([(hx + 26, hy + 20), (hx + 52, hy + 30), (hx + 26, hy + 38)],
                     fill=FUR + (255,))
        draw.ellipse((hx + 46, hy + 26, hx + 54, hy + 34), fill=DARK + (255,))
        draw.ellipse((hx + 14, hy + 22, hx + 22, hy + 30), fill=DARK + (255,))
    return img


def build(folder: Path, pose: str, frames: int) -> int:
    """Кадры спрайта в PNG: альфа доезжает без потерь.

    Через видео с прозрачностью не вышло: VP9 с yuva420p на выходе терял альфу,
    и лиса приезжала с чёрной подложкой. Кадров тут два десятка и каждый по
    несколько килобайт — последовательность картинок и проще, и надёжнее.
    """
    folder.mkdir(parents=True, exist_ok=True)
    for old in folder.glob("*.png"):
        old.unlink()
    for index in range(frames):
        _fox(pose, index / frames).save(folder / f"f{index:03d}.png")
    return frames


def main(argv: list) -> int:
    parser = argparse.ArgumentParser(description="Маскот канала: белая лиса")
    parser.add_argument("--dir", default="assets/mascot")
    args = parser.parse_args(argv)
    folder = Path(args.dir)
    run = build(folder / "run", "run", 8)
    sit = build(folder / "sit", "sit", 16)
    print(f"готово: бег {run} кадров, сидит и машет хвостом {sit} кадров")
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
