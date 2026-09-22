#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Генерация фоновой и контентной графики лендингов через KIE (nano-banana-2).

Палитра и настроение подобраны под страницы микросервисов genius-bot.ru:
тёмный сине-стальной фон #0a0f1a, индиго #818cf8, циан #22d3ee.
Текст в изображениях не генерируем — модели плохо рисуют кириллицу.

Запуск:  KIE_API_KEY=... python3 tools/gen_images.py [слаг ...]
"""
from __future__ import annotations

import json
import os
import sys
import time
import urllib.request
from concurrent.futures import ThreadPoolExecutor
from pathlib import Path

KEY = os.environ.get("KIE_API_KEY", "").strip()
BASE = "https://api.kie.ai"
MODEL = os.environ.get("KIE_IMAGE_MODEL", "nano-banana-2")
OUT = Path(__file__).resolve().parent.parent / "content" / "images"

PALETTE = (
    "deep slate-navy background #0a0f1a, indigo #818cf8 and cyan #22d3ee light accents, "
    "soft volumetric glow, subtle film grain, high detail, premium editorial tech illustration, "
    "no text, no letters, no typography, no watermark, no logos, no human faces in focus"
)

SHOTS: dict[str, dict] = {
    "home-hero": {
        "ratio": "16:9",
        "prompt": "A dark control-room wall of floating translucent glass panels arranged in a grid, "
                  "each panel glowing from within with a different soft colour, thin light threads "
                  "connecting them into one network, deep perspective, cinematic wide shot. " + PALETTE,
    },
    "uchitel-hero": {
        "ratio": "16:9",
        "prompt": "An empty modern classroom at dusk seen from the back, a glowing translucent "
                  "holographic lesson plan unfolding above the teacher's desk like layered glass "
                  "sheets, warm indigo light from the windows, calm and orderly. " + PALETTE,
    },
    "uchitel-card": {
        "ratio": "4:3",
        "prompt": "Close-up of a stack of translucent glowing document sheets floating above a dark "
                  "wooden desk, each sheet a different layer of a lesson plan, soft depth of field, "
                  "a pen and a closed notebook beside them. " + PALETTE,
    },
    "ucheba-hero": {
        "ratio": "16:9",
        "prompt": "A dark desk at night lit by a phone screen, glowing geometric shapes and equations "
                  "rising out of the screen as luminous wireframe objects, a notebook and pencil in "
                  "shadow, cosy late-evening study mood. " + PALETTE,
    },
    "ucheba-card": {
        "ratio": "4:3",
        "prompt": "A luminous step-by-step path made of floating glass platforms ascending from a "
                  "tangled knot of light to a single clear glowing sphere, metaphor for a problem "
                  "solved step by step. " + PALETTE,
    },
    "yurist-hero": {
        "ratio": "16:9",
        "prompt": "A long dark table with a contract rendered as a translucent glowing sheet, several "
                  "clauses highlighted in warm amber light while the rest stays cool blue, a subtle "
                  "scale-of-justice silhouette dissolving into light in the background. " + PALETTE,
    },
    "yurist-card": {
        "ratio": "4:3",
        "prompt": "Macro shot of a translucent document page where three paragraphs glow amber and are "
                  "lifted slightly above the page surface, thin connecting lines to small marker dots, "
                  "dark background, precise and clinical. " + PALETTE,
    },
    "biznes-hero": {
        "ratio": "16:9",
        "prompt": "A dark office at night, a glowing chat interface floating in mid-air as stacked "
                  "translucent message bubbles, a city skyline blurred through the window behind, "
                  "one bubble brighter than the rest as it hands off to a person silhouette. " + PALETTE,
    },
    "biznes-card": {
        "ratio": "4:3",
        "prompt": "Two glowing streams of translucent message bubbles converging into a single funnel "
                  "of light that ends in a bright solid node, metaphor for incoming requests becoming "
                  "one qualified lead, dark background. " + PALETTE,
    },
}


def api(method: str, path: str, body=None, params=None, timeout=120):
    url = BASE + path
    if params:
        url += "?" + "&".join(f"{k}={v}" for k, v in params.items())
    data = json.dumps(body).encode() if body is not None else None
    req = urllib.request.Request(url, data=data, method=method, headers={
        "Authorization": f"Bearer {KEY}", "Content-Type": "application/json"})
    with urllib.request.urlopen(req, timeout=timeout) as resp:
        return json.loads(resp.read().decode())


def urls_from(node, acc: list[str]):
    if isinstance(node, str):
        if node.startswith("http") and any(
                node.lower().split("?")[0].endswith(e) for e in (".png", ".jpg", ".jpeg", ".webp")):
            acc.append(node)
    elif isinstance(node, dict):
        for v in node.values():
            urls_from(v, acc)
    elif isinstance(node, list):
        for v in node:
            urls_from(v, acc)
    return acc


def one(name: str, shot: dict) -> str:
    dest = OUT / f"{name}.png"
    if dest.exists() and dest.stat().st_size > 10000:
        return f"{name}: уже есть, пропускаю"
    created = api("POST", "/api/v1/jobs/createTask", {
        "model": MODEL,
        "input": {"prompt": shot["prompt"], "aspect_ratio": shot["ratio"],
                  "resolution": "2K", "output_format": "png"},
    })
    if created.get("code") != 200:
        return f"{name}: createTask -> {created.get('code')} {created.get('msg')}"
    task = created["data"]["taskId"]
    deadline = time.time() + 900
    while time.time() < deadline:
        time.sleep(6)
        info = api("GET", "/api/v1/jobs/recordInfo", params={"taskId": task}, timeout=60).get("data") or {}
        state = str(info.get("state") or info.get("status") or "").lower()
        if state in ("success", "succeeded", "completed"):
            found = urls_from(info, [])
            if not found:
                return f"{name}: готово, но ссылки нет"
            OUT.mkdir(parents=True, exist_ok=True)
            with urllib.request.urlopen(found[0], timeout=180) as r, open(dest, "wb") as f:
                f.write(r.read())
            return f"{name}: сохранено {dest.stat().st_size // 1024} КБ"
        if state in ("fail", "failed", "error"):
            return f"{name}: ошибка — {str(info.get('failMsg') or info)[:160]}"
    return f"{name}: не дождался"


def main() -> None:
    if not KEY:
        sys.exit("Нужен KIE_API_KEY")
    wanted = sys.argv[1:] or list(SHOTS)
    jobs = {k: v for k, v in SHOTS.items() if k in wanted}
    OUT.mkdir(parents=True, exist_ok=True)
    print(f"Генерирую {len(jobs)} изображений моделью {MODEL}", flush=True)
    with ThreadPoolExecutor(max_workers=4) as pool:
        for line in pool.map(lambda kv: one(*kv), jobs.items()):
            print(" ", line, flush=True)


if __name__ == "__main__":
    main()
