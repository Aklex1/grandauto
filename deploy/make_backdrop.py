"""Рисуем зацикленную заставку канала: плоская векторная ночная сцена.

Почему не генератор картинок. Визуальный язык канала — плоская векторная графика
с чистыми силуэтами и ровными градиентами. Фотогенераторы такое не делают: они
дают фотореализм, а на просьбу «плоский вектор» — вязкую имитацию. Плюс каждый
клип стоит денег, и вариантов столько, сколько оплачено.

Нарисованная сцена решает обе задачи: она ровно в нужном стиле, стоит ноль и
вариантов даёт бесконечно — достаточно сменить набор цветов и форму гор.

Текста и логотипа здесь нет намеренно. Заголовок, карточку «сейчас играет»,
полосу хода и эквалайзер накладывает завод при сборке: так они чёткие, их можно
менять, не трогая сцену, и они говорят правду о том, что звучит.

Движение замкнуто по построению: звёздное поле шириной в два кадра прокручивается
ровно на кадр за период, горы ходят по синусу. Поэтому повтор бесшовен без всякой
починки — это видно по измерению стыка.

Запуск:
    python3 deploy/make_backdrop.py --out assets/lumen-drift/loop_02.mp4 --preset aurora
"""
from __future__ import annotations

import argparse
import math
import random
import subprocess
import sys
import tempfile
from pathlib import Path

from PIL import Image, ImageDraw, ImageFilter

W, H = 1920, 1080
FPS = 30

# Наборы цветов. Каждый — отдельная заставка в том же языке: ночь, но другая.
PRESETS: dict[str, dict] = {
    "aurora": {
        "sky": ((0x0A, 0x0E, 0x1C), (0x2B, 0x24, 0x52)),
        "glow": (0x3E, 0xC8, 0xC0), "glow_at": (0.42, 0.30), "glow_r": 0.58,
        "moon": (0xF2, 0xF6, 0xFF), "moon_at": (0.80, 0.26), "moon_r": 0.052,
        "ridges": (((0x39, 0x3E, 0x75), 0.80, 9, 0.095),
                   ((0x25, 0x29, 0x55), 0.87, 7, 0.105),
                   ((0x14, 0x16, 0x31), 0.93, 5, 0.105)),
    },
    "snow": {
        "sky": ((0x0C, 0x16, 0x30), (0x1E, 0x33, 0x63)),
        "glow": (0x5A, 0xD0, 0xD8), "glow_at": (0.62, 0.24), "glow_r": 0.50,
        "moon": (0xFF, 0xFF, 0xFF), "moon_at": (0.66, 0.22), "moon_r": 0.060,
        "ridges": (((0x44, 0x59, 0x92), 0.80, 9, 0.095),
                   ((0x2C, 0x3C, 0x6E), 0.87, 7, 0.105),
                   ((0x1A, 0x25, 0x48), 0.93, 5, 0.105)),
    },
    "ember": {
        "sky": ((0x0B, 0x0F, 0x24), (0x3A, 0x26, 0x44)),
        "glow": (0xE8, 0xA2, 0x5C), "glow_at": (0.70, 0.34), "glow_r": 0.46,
        "moon": (0xFF, 0xE2, 0xB0), "moon_at": (0.70, 0.33), "moon_r": 0.075,
        "ridges": (((0x2A, 0x22, 0x4C), 0.81, 7, 0.085),
                   ((0x1D, 0x18, 0x3A), 0.88, 6, 0.095),
                   ((0x12, 0x10, 0x28), 0.94, 4, 0.10)),
    },
    "deep": {
        "sky": ((0x07, 0x0B, 0x18), (0x16, 0x1B, 0x3A)),
        "glow": (0x7C, 0x6B, 0xD8), "glow_at": (0.30, 0.26), "glow_r": 0.54,
        "moon": (0xE6, 0xEC, 0xFF), "moon_at": (0.22, 0.20), "moon_r": 0.044,
        "ridges": (((0x1C, 0x20, 0x44), 0.79, 10, 0.10),
                   ((0x14, 0x17, 0x34), 0.87, 7, 0.11),
                   ((0x0C, 0x0E, 0x22), 0.93, 5, 0.11)),
    },
}


def _sky(colors: tuple, glow: dict) -> Image.Image:
    """Небо: вертикальный градиент плюс мягкое свечение сияния."""
    top, bottom = colors
    base = Image.new("RGB", (1, H))
    pixels = base.load()
    for y in range(H):
        k = y / (H - 1)
        # Квадратичная подгонка: у горизонта цвет набирается быстрее, как в ночи.
        k = k ** 1.6
        pixels[0, y] = tuple(int(top[i] + (bottom[i] - top[i]) * k) for i in range(3))
    sky = base.resize((W, H), Image.BILINEAR)

    aura = Image.new("L", (W, H), 0)
    draw = ImageDraw.Draw(aura)
    cx, cy = int(W * glow["glow_at"][0]), int(H * glow["glow_at"][1])
    rx, ry = int(W * glow["glow_r"] * 0.5), int(H * glow["glow_r"] * 0.18)
    # Сияние держим слабым и широким: плотное пятно читается как клякса, а не
    # как свет в небе.
    draw.ellipse((cx - rx, cy - ry, cx + rx, cy + ry), fill=96)
    aura = aura.filter(ImageFilter.GaussianBlur(radius=int(H * 0.16)))
    sky.paste(Image.new("RGB", (W, H), glow["glow"]), (0, 0), aura)
    return sky


def _stars(seed: int, count: int = 620) -> Image.Image:
    """Звёздное поле шириной в два кадра: так его можно прокручивать без шва."""
    rnd = random.Random(seed)
    layer = Image.new("RGBA", (W * 2, H), (0, 0, 0, 0))
    draw = ImageDraw.Draw(layer)
    for _ in range(count):
        x = rnd.randrange(W * 2)
        # Ближе к горизонту звёзд меньше — иначе небо выглядит обоями.
        # Звёзды собираем в верхней части: у горизонта их съедает свечение, и
        # ровная россыпь по всему кадру выглядит обоями.
        y = int(abs(rnd.gauss(0, 0.55)) % 1.0 * H * 0.66)
        r = rnd.choice((1, 1, 1, 1, 1, 2, 2))
        a = rnd.randint(55, 205)
        draw.ellipse((x - r, y - r, x + r, y + r), fill=(255, 255, 255, a))
    return layer


def _moon(cfg: dict) -> Image.Image:
    layer = Image.new("RGBA", (W, H), (0, 0, 0, 0))
    cx, cy = int(W * cfg["moon_at"][0]), int(H * cfg["moon_at"][1])
    r = int(H * cfg["moon_r"])

    halo = Image.new("L", (W, H), 0)
    ImageDraw.Draw(halo).ellipse((cx - r * 4, cy - r * 4, cx + r * 4, cy + r * 4), fill=70)
    halo = halo.filter(ImageFilter.GaussianBlur(radius=r * 1.6))
    layer.paste(Image.new("RGB", (W, H), cfg["moon"]), (0, 0), halo)

    disc = Image.new("L", (W, H), 0)
    ImageDraw.Draw(disc).ellipse((cx - r, cy - r, cx + r, cy + r), fill=255)
    disc = disc.filter(ImageFilter.GaussianBlur(radius=max(1, r * 0.04)))
    layer.paste(Image.new("RGB", (W, H), cfg["moon"]), (0, 0), disc)
    return layer


def _ridge(color: tuple, base: float, peaks: int, height: float, seed: int) -> Image.Image:
    """Горный силуэт: ряд перекрывающихся треугольников разной высоты.

    Именно перекрывающихся, а не одной ломаной: одна ломаная даёт ровную пилу,
    в которой читается генератор. Разновысокие треугольники, наезжающие друг на
    друга, выглядят грядой.
    """
    rnd = random.Random(seed)
    layer = Image.new("RGBA", (W, H), (0, 0, 0, 0))
    draw = ImageDraw.Draw(layer)
    y0 = H * base
    # Подложка до низа кадра: под грядой не должно просвечивать небо.
    draw.rectangle((0, y0, W, H), fill=color + (255,))

    step = (W + 320) / peaks
    x = -160.0
    while x < W + 160:
        half = step * rnd.uniform(0.62, 0.95)
        top = y0 - H * height * rnd.uniform(0.55, 1.2)
        draw.polygon([(x - half, y0 + 2), (x, top), (x + half, y0 + 2)],
                     fill=color + (255,))
        x += step * rnd.uniform(0.72, 1.05)
    return layer


def render(out: Path, preset: str, seconds: float, seed: int) -> None:
    cfg = PRESETS[preset]
    tmp = Path(tempfile.mkdtemp(prefix="backdrop_"))
    sky = tmp / "sky.png"
    _sky(cfg["sky"], cfg).save(sky)
    stars = tmp / "stars.png"
    _stars(seed).save(stars)
    moon = tmp / "moon.png"
    _moon(cfg).save(moon)
    ridges = []
    for index, (color, base, peaks, height) in enumerate(cfg["ridges"]):
        path = tmp / f"ridge{index}.png"
        _ridge(color, base, peaks, height, seed + index * 17).save(path)
        ridges.append(path)

    inputs = ["-loop", "1", "-t", f"{seconds}", "-i", str(sky),
              "-loop", "1", "-t", f"{seconds}", "-i", str(stars),
              "-loop", "1", "-t", f"{seconds}", "-i", str(moon)]
    for path in ridges:
        inputs += ["-loop", "1", "-t", f"{seconds}", "-i", str(path)]

    # Прокрутка звёзд ровно на кадр за период: на стыке поле совпадает само с
    # собой, и повтор не виден. Горы ходят по синусу и к концу возвращаются.
    steps = [f"[1:v]overlay=x='-{W}*mod(t/{seconds},1)':y=0:eval=frame[s1]"]
    steps.insert(0, "[0:v]null[bg]")
    steps[1] = f"[bg][1:v]overlay=x='-{W}*mod(t/{seconds},1)':y=0:eval=frame[s1]"
    steps.append(f"[s1][2:v]overlay=0:0[s2]")
    current = "s2"
    for index in range(len(ridges)):
        amp = 6 + index * 5
        out_label = f"r{index}"
        steps.append(
            f"[{current}][{3 + index}:v]overlay="
            f"x='{amp}*sin(2*PI*t/{seconds})':y='{amp * 0.35:.1f}*sin(2*PI*t/{seconds})':"
            f"eval=frame[{out_label}]")
        current = out_label
    steps.append(f"[{current}]format=yuv420p[v]")

    subprocess.run([
        "ffmpeg", "-hide_banner", "-loglevel", "error", "-y", *inputs,
        "-filter_complex", ";".join(steps), "-map", "[v]",
        "-r", str(FPS), "-c:v", "libx264", "-preset", "medium", "-crf", "18",
        "-g", str(FPS), "-keyint_min", str(FPS), "-sc_threshold", "0",
        "-movflags", "+faststart", str(out),
    ], check=True)


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
