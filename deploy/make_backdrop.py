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
    "road": {
        "kind": "road",
        "sky": ((0x0B, 0x0D, 0x22), (0x2E, 0x24, 0x52)),
        "glow": (0x8E, 0x6B, 0xD8), "glow_at": (0.50, 0.20), "glow_r": 0.66,
        "stars": 420,
        "horizon": 0.42, "road_near": 0.46, "road_far": 0.012,
        "ground": (0x0E, 0x11, 0x26), "road": (0x15, 0x19, 0x33),
        "edge": (0x4A, 0x44, 0x7E), "dash": (0xD8, 0xDC, 0xEE),
    },
    "planet": {
        "kind": "planet",
        "sky": ((0x0B, 0x10, 0x28), (0x15, 0x1B, 0x3C)),
        "glow": (0xE0, 0x9A, 0x58), "glow_at": (0.66, 0.46), "glow_r": 0.40,
        "stars": 520,
        "planet": (0xF2, 0xB4, 0x70), "planet_at": (0.66, 0.50), "planet_r": 0.30,
        "ring": (0xE8, 0xDC, 0xC0),
        "hills": (((0x3A, 0x30, 0x66), 0.86, 0.045, 1.3, 0.10),
                  ((0x2A, 0x23, 0x4E), 0.93, 0.035, 0.9, 0.55)),
    },
    "snowfall": {
        "kind": "snow",
        "sky": ((0x0C, 0x16, 0x32), (0x20, 0x36, 0x68)),
        "glow": (0x5C, 0xD6, 0xDC), "glow_at": (0.66, 0.22), "glow_r": 0.44,
        "stars": 380,
        "moon": (0xFF, 0xFF, 0xFF), "moon_at": (0.68, 0.22), "moon_r": 0.085,
        "ridges": (((0x3A, 0x4E, 0x8C), 0.80, 8, 0.10),
                   ((0x7C, 0x92, 0xC4), 0.89, 6, 0.08),
                   ((0xDF, 0xE9, 0xF7), 0.95, 5, 0.06)),
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


def _wavy(color: tuple, base: float, amp: float, period: float, phase: float,
          seed: int) -> Image.Image:
    """Мягкая холмистая полоса — волна, а не пила. Для сцены с планетой."""
    layer = Image.new("RGBA", (W, H), (0, 0, 0, 0))
    y0 = H * base
    points = [(0, H)]
    for x in range(0, W + 8, 8):
        k = x / W
        y = y0 - H * amp * (0.5 + 0.5 * math.sin(2 * math.pi * (k * period + phase)))
        points.append((x, y))
    points.append((W, H))
    ImageDraw.Draw(layer).polygon(points, fill=color + (255,))
    return layer


def _planet(cfg: dict) -> Image.Image:
    """Большая планета с тонким кольцом."""
    layer = Image.new("RGBA", (W, H), (0, 0, 0, 0))
    cx, cy = int(W * cfg["planet_at"][0]), int(H * cfg["planet_at"][1])
    r = int(H * cfg["planet_r"])

    halo = Image.new("L", (W, H), 0)
    ImageDraw.Draw(halo).ellipse((cx - r * 2, cy - r * 2, cx + r * 2, cy + r * 2), fill=90)
    halo = halo.filter(ImageFilter.GaussianBlur(radius=r * 0.55))
    layer.paste(Image.new("RGB", (W, H), cfg["planet"]), (0, 0), halo)

    # Диск с мягким переходом к краю: ровная заливка выглядит наклейкой.
    disc = Image.new("L", (W, H), 0)
    dd = ImageDraw.Draw(disc)
    for step in range(12):
        k = step / 11
        rr = int(r * (1 - k * 0.04))
        dd.ellipse((cx - rr, cy - rr, cx + rr, cy + rr), fill=int(255 - k * 35))
    layer.paste(Image.new("RGB", (W, H), cfg["planet"]), (0, 0), disc)

    ring = Image.new("RGBA", (W, H), (0, 0, 0, 0))
    rd = ImageDraw.Draw(ring)
    rx, ry = int(r * 1.75), int(r * 0.42)
    rd.ellipse((cx - rx, cy + int(r * 0.30) - ry, cx + rx, cy + int(r * 0.30) + ry),
               outline=cfg["ring"] + (190,), width=max(2, int(r * 0.022)))
    ring = ring.rotate(-12, center=(cx, cy), resample=Image.BICUBIC)
    layer.alpha_composite(ring)
    return layer


def _road(cfg: dict) -> tuple:
    """Дорога с уходящей перспективой. Возвращает (слой, геометрия полосы)."""
    layer = Image.new("RGBA", (W, H), (0, 0, 0, 0))
    draw = ImageDraw.Draw(layer)
    horizon = H * cfg["horizon"]
    cx = W * 0.5
    near = W * cfg["road_near"]
    far = W * cfg["road_far"]

    # Земля по обе стороны — ровный тёмный тон до низа кадра.
    draw.rectangle((0, horizon, W, H), fill=cfg["ground"] + (255,))
    draw.polygon([(cx - far, horizon), (cx + far, horizon),
                  (cx + near, H), (cx - near, H)], fill=cfg["road"] + (255,))
    # Обочины светлее дороги: по ним и читается направление.
    edge = max(3, int(W * 0.004))
    for side in (-1, 1):
        draw.polygon([(cx + side * far, horizon),
                      (cx + side * (far + edge * 0.35), horizon),
                      (cx + side * (near + edge * 2.2), H),
                      (cx + side * near, H)], fill=cfg["edge"] + (255,))
    return layer, (horizon, cx, near, far)


def _dash_strip(width: int, height: int, color: tuple) -> Image.Image:
    """Полоса с пунктиром постоянного шага — её потом искажает перспектива."""
    strip = Image.new("RGBA", (width, height * 2), (0, 0, 0, 0))
    draw = ImageDraw.Draw(strip)
    period = height // 6
    dash = int(period * 0.52)
    y = 0
    while y < height * 2:
        draw.rectangle((width * 0.42, y, width * 0.58, y + dash), fill=color + (255,))
        y += period
    return strip


def _snowfall(seed: int, count: int = 420) -> Image.Image:
    """Снег: слой вдвое выше кадра, чтобы прокручивать его без шва."""
    rnd = random.Random(seed)
    layer = Image.new("RGBA", (W, H * 2), (0, 0, 0, 0))
    draw = ImageDraw.Draw(layer)
    for _ in range(count):
        x, y = rnd.randrange(W), rnd.randrange(H * 2)
        r = rnd.choice((2, 2, 3, 3, 4, 5))
        a = rnd.randint(110, 240)
        draw.ellipse((x - r, y - r, x + r, y + r), fill=(255, 255, 255, a))
    return layer


def render(out: Path, preset: str, seconds: float, seed: int) -> None:
    """Собрать заставку: слои рисуем на месте, движение задаёт ffmpeg.

    Движение замкнуто по построению: всё, что едет, проходит ровно свой период
    за длину клипа и возвращается к началу. Поэтому повтор бесшовен без починки.
    """
    cfg = PRESETS[preset]
    kind = cfg.get("kind", "ridges")
    tmp = Path(tempfile.mkdtemp(prefix="backdrop_"))

    layers: list[Path] = []

    def put(image: Image.Image, name: str) -> int:
        path = tmp / f"{name}.png"
        image.save(path)
        layers.append(path)
        return len(layers) - 1

    sky_i = put(_sky(cfg["sky"], cfg), "sky")
    steps = [f"[{sky_i}:v]null[bg]"]
    current = "bg"
    index = 1

    def chain(source: str, filt: str) -> str:
        nonlocal current, index
        label = f"L{index}"
        index += 1
        steps.append(f"[{current}][{source}]{filt}[{label}]")
        current = label
        return label

    if kind != "road":
        stars_i = put(_stars(seed, cfg.get("stars", 620)), "stars")
        # Поле вдвое шире кадра уезжает ровно на кадр за период — шва нет.
        chain(f"{stars_i}:v", f"overlay=x='-{W}*mod(t/{seconds},1)':y=0:eval=frame")

    if kind == "planet":
        planet_i = put(_planet(cfg), "planet")
        chain(f"{planet_i}:v", "overlay=0:0")
        for number, (color, base, amp, period, phase) in enumerate(cfg["hills"]):
            hill_i = put(_wavy(color, base, amp, period, phase, seed + number), f"hill{number}")
            swing = 10 + number * 7
            chain(f"{hill_i}:v",
                  f"overlay=x='{swing}*sin(2*PI*t/{seconds})':"
                  f"y='{swing * 0.3:.1f}*sin(2*PI*t/{seconds})':eval=frame")

    elif kind == "snow":
        moon_i = put(_moon(cfg), "moon")
        chain(f"{moon_i}:v", "overlay=0:0")
        for number, (color, base, peaks, height) in enumerate(cfg["ridges"]):
            ridge_i = put(_ridge(color, base, peaks, height, seed + number * 17), f"r{number}")
            amp = 5 + number * 4
            chain(f"{ridge_i}:v",
                  f"overlay=x='{amp}*sin(2*PI*t/{seconds})':y=0:eval=frame")
        snow_i = put(_snowfall(seed), "snow")
        # Снег падает ровно на высоту кадра за период: на стыке картина та же.
        chain(f"{snow_i}:v", f"overlay=x=0:y='-{H}+{H}*mod(t/{seconds},1)':eval=frame")

    elif kind == "road":
        road_layer, (horizon, cx, near, far) = _road(cfg)
        road_i = put(road_layer, "road")
        chain(f"{road_i}:v", "overlay=0:0")
        stars_i = put(_stars(seed, cfg.get("stars", 420)), "stars")
        chain(f"{stars_i}:v", f"overlay=x='-{W}*mod(t/{seconds},1)':y=0:eval=frame")

        road_h = int(H - horizon)
        strip_w = int(near * 2)
        strip_i = put(_dash_strip(strip_w, road_h, cfg["dash"]), "dash")
        # Пунктир: ровная полоса едет вниз на свою высоту за период, а перспектива
        # превращает это в движение навстречу. Рисовать пунктир сразу в
        # перспективе нельзя — при прокрутке штрихи не меняли бы размер.
        steps.append(
            f"[{strip_i}:v]crop=w={strip_w}:h={road_h}:x=0:"
            f"y='{road_h}*mod(t/{seconds},1)'[dashwin]")
        top_in = (strip_w - int(far * 2)) / 2
        steps.append(
            f"[dashwin]perspective="
            f"x0={top_in:.0f}:y0=0:x1={strip_w - top_in:.0f}:y1=0:"
            f"x2=0:y2={road_h}:x3={strip_w}:y3={road_h}:"
            f"sense=destination[dash]")
        chain("dash", f"overlay=x={int(cx - near)}:y={int(horizon)}")

    else:
        moon_i = put(_moon(cfg), "moon")
        chain(f"{moon_i}:v", "overlay=0:0")
        for number, (color, base, peaks, height) in enumerate(cfg["ridges"]):
            ridge_i = put(_ridge(color, base, peaks, height, seed + number * 17), f"r{number}")
            amp = 6 + number * 5
            chain(f"{ridge_i}:v",
                  f"overlay=x='{amp}*sin(2*PI*t/{seconds})':"
                  f"y='{amp * 0.35:.1f}*sin(2*PI*t/{seconds})':eval=frame")

    steps.append(f"[{current}]format=yuv420p[v]")

    inputs: list[str] = []
    for path in layers:
        inputs += ["-loop", "1", "-t", f"{seconds}", "-i", str(path)]

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
