"""Картинка для обложки: отдельная генерация под свой промпт.

Почему не кадр из ролика. Кадр — это заставка, поверх которой уже лежат шапка,
знак канала, плашка «сейчас играет» и полоса хода. На обложке всё это
накладывается на её собственный заголовок, и выходит каша. Плюс ночной пейзаж
в ленте не цепляет: рядом десяток таких же тёмных картинок.

Что вместо. Отдельный кадр по своему промпту: один герой крупно — девушка в
наушниках у окна с дождём, у камина, в машине ночью, — тёплый свет против
холодной ночи, и пустая сторона под заголовок. Это то, на чём держатся обложки
в жанре: один смысловой центр, крупный короткий текст, высокий контраст.

Буквы генератору не доверяем: он пишет их криво и с ошибками. Текст кладём
сами поверх, поэтому в промпте стоит прямой запрет на любые надписи.
"""
from __future__ import annotations

import logging
from dataclasses import dataclass
from pathlib import Path

from . import kie, storage
from .kie import KieClient, KieError

log = logging.getLogger("cf.covers")


@dataclass(frozen=True)
class Scene:
    """Один сюжет обложки."""

    key: str
    subject: str
    place: str
    light: str


# Сюжеты из тех, что держат жанр: человек со спины или в три четверти, крупно,
# в уютном месте ночью. Лица в упор нет намеренно — так обложка остаётся про
# настроение, а не про конкретного человека, и не стареет от выпуска к выпуску.
SCENES = (
    Scene("drive_sea",
          "a young woman driving a convertible, one hand on the wheel, hair flying "
          "in the wind, smiling, seen from the passenger side",
          "a coastal road above a turquoise sea, cliffs and palms rushing past",
          "bright midday sun, sparkling water, warm skin tones"),
    Scene("beach_headphones",
          "a young woman in big headphones on the beach, eyes closed, face turned "
          "to the sun",
          "a wide sunny beach, turquoise waves breaking behind her",
          "strong sunlight, golden sand, deep blue sky"),
    Scene("balcony_sea",
          "a young woman in headphones leaning on a balcony railing with a coffee, "
          "seen in three-quarter view",
          "a white balcony over the sea, light curtains moving in the breeze",
          "soft morning sun, bright whites against deep blue water"),
    Scene("van_sunset",
          "a young woman in headphones sitting in the open door of a camper van, "
          "feet hanging out, surfboard beside her",
          "a sandy parking spot right by the ocean, waves behind",
          "golden hour sun low over the water, warm orange rim light"),
    Scene("pier_walk",
          "a young woman in headphones walking along a wooden pier, dress moving, "
          "seen from behind over her shoulder",
          "a long pier over clear water, sailing boats in the distance",
          "late afternoon sun, long shadows, bright turquoise sea"),
    Scene("sailboat",
          "a young woman in headphones at the bow of a sailing boat, arms on the "
          "rail, wind in her hair",
          "open sea under a huge blue sky, spray in the air",
          "hard midday sun, white sails, sparkling water"),
    Scene("rooftop_day",
          "a young woman in headphones on a rooftop terrace, dancing lightly, "
          "plants and a city skyline around",
          "a sunny rooftop above a seaside city",
          "warm daylight, green plants, bright blue sky"),
    Scene("night_drive_city",
          "a young woman in headphones driving at night, city lights reflected on "
          "the windscreen, seen in profile",
          "an empty highway into a glowing city",
          "warm street lamps and cool blue dusk, light trails"),
)

# Шортс живёт по другим законам. Его листают большим пальцем, решение занимает
# доли секунды, и спокойная сцена там просто пролистывается. Поэтому здесь
# движение: бег, прыжок, брызги, танец.
SHORT_SCENES = (
    Scene("run_surf",
          "a young woman in headphones running through shallow surf, water "
          "splashing around her legs, caught mid-stride",
          "a sunny beach, turquoise waves rolling in behind her",
          "hard sunlight, frozen water droplets, vivid blue and gold"),
    Scene("jump_sea",
          "a young woman jumping off a wooden jetty into the sea, arms wide, "
          "caught in mid-air",
          "a clear turquoise bay under a blue sky",
          "bright midday sun, spray and ripples below her"),
    Scene("car_window",
          "a young woman in the passenger seat with her arm out of the window "
          "riding the air, laughing, hair flying",
          "a coastal road, sea and palms blurring past",
          "golden sunlight, strong motion blur outside the window"),
    Scene("beach_dance",
          "two friends in headphones dancing on the sand, hands up, caught "
          "mid-movement",
          "a beach party at golden hour, string lights and sea behind",
          "warm low sun, long shadows, vivid colour"),
    Scene("surf",
          "a surfer girl turning on a wave, spray flying off the board",
          "a clean blue-green wave under a bright sky",
          "hard sunlight through the wave, white spray frozen in the air"),
    Scene("skate_promenade",
          "a young woman in headphones skating along a seaside promenade, leaning "
          "into the turn",
          "palms, white railings and the sea beside her",
          "bright afternoon sun, strong motion blur on the background"),
    Scene("convertible_friends",
          "two girls standing up in an open convertible, arms in the air, hair "
          "flying",
          "a coastal highway above the sea",
          "bright sun, deep blue water, saturated colour"),
    Scene("sprinkler",
          "a young woman in headphones spinning under falling water, droplets "
          "flying off her hair",
          "a sunny terrace by the sea",
          "backlit sun through the spray, rainbow highlights"),
)

# Как это снято. Живая сцена с настоящими людьми, а не ночная картина: такую
# обложку в ленте и замечают. Буквы генератору не доверяем — запрет прямой.
LOOK = ("cinematic photograph, real people, natural skin tones, shallow depth of "
        "field, 50mm lens, rich saturated colors, high contrast, sharp focus, "
        "no text, no letters, no words, no numbers, no watermark, no logo, "
        "no signature, no user interface")
# Человек в кадре обязателен и обязательно крупно. Без этого требования
# генератор охотно отдаёт красивый пустой пейзаж: берег, море, никого — а
# именно человек в кадре и делает обложку живой.
PEOPLE = ("the person is the main subject and must be clearly visible in frame, "
          "medium shot from the waist up, face visible, filling a large part of "
          "the frame, never an empty landscape, not a distant tiny figure")
# Пустая сторона — не украшение: заголовок на обложке крупный, и без неё он
# ляжет человеку на лицо.
# «Левая половина проще и темнее» генератор понял буквально и выдал диптих:
# отдельный тёмный кадр слева, основной справа. Поэтому теперь сначала прямо
# сказано, что кадр один, а место под текст описано как открытый фон, а не как
# половина картинки.
ONE_SHOT = ("one single continuous photograph, no split screen, no diptych, no "
            "collage, no panels, no borders, no frame inside the image")
FRAME_H = (f"16:9 horizontal composition, {ONE_SHOT}, the person on the right side "
           "of the frame, the left side filled with open background — sky, sea or "
           "road — without important detail, leaving room for large text")
FRAME_V = (f"9:16 vertical composition, {ONE_SHOT}, the person in the upper part of "
           "the frame, the lower part filled with open background — sand, water or "
           "road — without important detail, leaving room for large text")
# Шортс — это движение. Говорим про него прямо, иначе генератор рисует позу.
MOTION = ("energetic action shot, caught mid-movement, dynamic diagonal "
          "composition, motion blur on the background, vivid saturated colours, "
          "bright and lively, joyful")


def pick(seed: int, *, vertical: bool = False) -> Scene:
    """Сюжет по номеру. Соседние обложки не повторяются."""
    rows = SHORT_SCENES if vertical else SCENES
    return rows[seed % len(rows)]


def style_hint_of(label: str, use: str) -> str:
    """Короткая подсказка о жанре для картинки.

    Музыкальный промпт сюда класть нельзя: «halftime drums around 140 bpm» для
    генератора картинок шум, и он начинает рисовать барабаны. Нужны два слова
    про настроение, не про аранжировку.
    """
    parts = [part.strip() for part in (label.split("(")[0], use) if part.strip()]
    return ", ".join(parts)[:120]


def build_prompt(style_hint: str, seed: int, *, vertical: bool = False) -> str:
    """Промпт обложки: сюжет, свет, правила кадра и запрет на надписи.

    style_hint — пара слов о жанре, чтобы палитра обложки и музыки не спорили.
    Вертикальная обложка идёт по своему набору сюжетов: у шортса другая задача.
    """
    scene = pick(seed, vertical=vertical)
    frame = FRAME_V if vertical else FRAME_H
    hint = f" The mood matches {style_hint}." if style_hint else ""
    head = ("Vertical thumbnail for a short music video."
            if vertical else "YouTube thumbnail photo for a long music mix.")
    look = f"{MOTION}, {LOOK}" if vertical else LOOK
    return (f"{head} {scene.subject}, {scene.place}. "
            f"Lighting: {scene.light}.{hint} {PEOPLE}. {frame}. {look}.")


def make(client: KieClient, dst: Path, *, model: str, style_hint: str = "",
         seed: int = 0, vertical: bool = False) -> tuple[Path, float, str]:
    """Сгенерировать картинку обложки. Возвращает файл, цену и промпт.

    Это единственное место обложки, которое стоит денег, поэтому вызывается оно
    только по просьбе: галочкой при создании или кнопкой в библиотеке.
    """
    prompt = build_prompt(style_hint, seed, vertical=vertical)
    shot = client.run_task(model, kie.image_input_payload(
        model, prompt=prompt, aspect_ratio="9:16" if vertical else "16:9",
        resolution="2K", output_format="png"), timeout=900, poll=5)
    urls = [url for url in kie.extract_urls(shot) if url]
    if not urls:
        raise KieError(f"{model}: в ответе нет ссылки на картинку обложки")
    credits = float(shot.get("_credits") or 0)
    storage.download(urls[0], dst)
    if not dst.exists() or dst.stat().st_size < 1024:
        raise KieError(f"{model}: картинка обложки не скачалась")
    log.info("Картинка обложки готова: сюжет %s, %.1f кредитов",
             pick(seed, vertical=vertical).key, credits)
    return dst, credits, prompt
