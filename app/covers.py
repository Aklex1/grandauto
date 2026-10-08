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
import random
from dataclasses import dataclass
from pathlib import Path
from typing import Mapping

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
    # --- море и берег ------------------------------------------------------------
    Scene("beach_headphones",
          "a young woman in big headphones standing at the water's edge, eyes "
          "closed, face turned to the sun",
          "a wide sunny beach, turquoise waves breaking behind her",
          "strong sunlight, golden sand, deep blue sky"),
    Scene("pier_walk",
          "a young woman in headphones walking along a wooden pier, dress moving, "
          "seen from behind over her shoulder",
          "a long pier over clear water, sailing boats in the distance",
          "late afternoon sun, long shadows, bright turquoise sea"),
    Scene("sailboat",
          "a young woman in headphones at the bow of a sailing boat, hands on the "
          "rail, wind in her hair",
          "open sea under a huge blue sky, spray in the air",
          "hard midday sun, white sails, sparkling water"),
    Scene("van_sunset",
          "a young woman in headphones sitting in the open door of a camper van, "
          "feet hanging out, surfboard beside her",
          "a sandy parking spot right by the ocean, waves behind",
          "golden hour sun low over the water, warm orange rim light"),
    Scene("balcony_sea",
          "a young woman in headphones leaning on a balcony railing with a coffee, "
          "seen in three-quarter view",
          "a white balcony over the sea, light curtains moving in the breeze",
          "soft morning sun, bright whites against deep blue water"),
    Scene("surf_sit",
          "a young woman sitting astride a surfboard in calm water, hands trailing "
          "in the sea, looking at the horizon",
          "flat glassy ocean beyond the break, shoreline far behind",
          "early sun just over the water, soft gold on the swell"),
    Scene("rock_pool",
          "a young woman in headphones sitting on warm rocks with her feet in a "
          "tide pool",
          "a rocky shore with clear pools, waves breaking further out",
          "high sun, wet rock highlights, vivid green water"),
    Scene("lighthouse",
          "a young woman in a light jacket walking a coastal path, hair blown "
          "sideways, headphones on",
          "a white lighthouse on the cliff ahead, sea far below",
          "bright windy day, fast clouds, hard shadows"),
    Scene("kayak_dawn",
          "a young woman paddling a kayak, mid-stroke, looking ahead",
          "a still bay at dawn, mist on the water, hills behind",
          "pink and gold dawn light, mirror-smooth water"),
    Scene("ferry_deck",
          "a young woman in headphones at the rail of a ferry deck, jacket open, "
          "smiling into the wind",
          "open sea behind, the wake stretching away",
          "bright overcast light, cool blues and warm skin"),
    # --- дорога и путешествие ----------------------------------------------------
    Scene("drive_sea",
          "a young woman at the wheel of a convertible, both hands on the steering "
          "wheel in front of her, body and face turned forward along the road, "
          "hair blowing back, smiling, photographed from the passenger seat beside "
          "her",
          "a coastal road above a turquoise sea, cliffs and palms rushing past",
          "bright midday sun, sparkling water, warm skin tones"),
    Scene("night_drive_city",
          "a young woman in headphones driving at night, hands on the steering "
          "wheel in front of her, looking ahead at the road, seen in profile from "
          "the passenger seat",
          "an empty highway into a glowing city",
          "warm street lamps and cool blue dusk, light trails"),
    Scene("roadside_map",
          "two friends leaning on the bonnet of a parked car with a paper map "
          "between them, laughing",
          "a desert road pull-off, mountains on the horizon",
          "late afternoon sun, long shadows, warm dust"),
    Scene("motorbike_coast",
          "a young woman beside a parked motorbike, helmet under her arm, looking "
          "out over the sea",
          "a coastal viewpoint, road curving away below",
          "golden hour, warm chrome highlights"),
    Scene("train_window_day",
          "a young woman in headphones resting her chin on her hand by a train "
          "window, seen in profile",
          "fields and distant hills sliding past the glass",
          "bright daylight, soft reflections on the window"),
    Scene("bus_night",
          "a young woman in headphones alone on a night bus, head against the "
          "window, eyes half closed",
          "an empty lit carriage, city lights blurring outside",
          "warm interior light against cold blue night"),
    Scene("fuel_stop_dusk",
          "a young woman in headphones sitting on the bonnet of her car with a "
          "paper cup, legs crossed",
          "a small petrol station at dusk, open road behind",
          "neon sign glow against a deep orange sky"),
    Scene("desert_convertible",
          "a young woman standing beside an open convertible, arms stretched up, "
          "scarf in the wind",
          "a straight desert road, red rock buttes far off",
          "hard low sun, long shadow across the asphalt"),
    Scene("camper_morning",
          "a young woman in a blanket with a mug in the doorway of a camper van",
          "a mountain pull-off at sunrise, pines and mist below",
          "cold blue morning with a warm wedge of sunrise"),
    Scene("bridge_sunset",
          "a young woman in headphones leaning on the railing of a footbridge",
          "a river and a city skyline catching the last sun",
          "orange sunset reflected in glass towers"),
    # --- город и крыши -----------------------------------------------------------
    Scene("rooftop_day",
          "a young woman in headphones on a rooftop terrace, moving lightly to the "
          "music, plants around her",
          "a sunny rooftop above a seaside city",
          "warm daylight, green plants, bright blue sky"),
    Scene("rooftop_dusk",
          "two friends sitting on the edge of a rooftop with their backs to the "
          "camera, shoulders touching",
          "a city at dusk, first lights coming on below",
          "violet dusk sky, warm windows below"),
    Scene("cafe_terrace",
          "a young woman in headphones at a small cafe table with a notebook, "
          "pen in hand, half smiling",
          "a sunny terrace with striped awnings, street life behind",
          "dappled sunlight through leaves"),
    Scene("bookshop_window",
          "a young woman in headphones reading in a deep window seat of a bookshop",
          "tall shelves and a rainy street outside the glass",
          "warm lamp light inside, cold grey light outside"),
    Scene("metro_platform",
          "a young woman in headphones waiting alone on a platform, bag over her "
          "shoulder",
          "a tiled metro station at night, a train blurred in the tunnel",
          "cool fluorescent light, warm tunnel glow"),
    Scene("crosswalk_rain",
          "a young woman with an umbrella and headphones standing at a crossing",
          "a wet city street at night, neon signs reflected in the asphalt",
          "pink and cyan neon on wet ground"),
    Scene("record_store",
          "a young woman in headphones flipping through vinyl in a record shop",
          "crates of records, posters on the wall behind",
          "warm tungsten light, saturated sleeve colours"),
    Scene("office_window_night",
          "a young woman in headphones at a desk by a floor-to-ceiling window, "
          "chair turned to the view",
          "a lit city skyline at night beyond the glass",
          "cool monitor light on her face, warm city below"),
    Scene("night_market",
          "a young woman in headphones walking through a night market with a paper "
          "cup, looking around",
          "food stalls, paper lanterns and steam in the air",
          "warm lantern light, rich reds and golds"),
    Scene("skate_park_rest",
          "a young woman sitting on a ramp edge with her board, headphones around "
          "her neck",
          "an empty concrete skate park at golden hour, graffiti walls",
          "low warm sun across the concrete"),
    # --- природа и горы ----------------------------------------------------------
    Scene("forest_hammock",
          "a young woman in headphones lying in a hammock, one leg hanging down",
          "a sunlit pine forest, light through the needles",
          "warm shafts of sunlight through the trees"),
    Scene("lake_dock",
          "a young woman sitting on a wooden dock with her feet over the water, "
          "headphones on",
          "a still mountain lake in the morning, mist lifting",
          "cool morning blues with a warm rim from the rising sun"),
    Scene("mountain_ridge",
          "a hiker in headphones standing on a ridge, pack on her shoulders, "
          "looking out",
          "layered mountain ridges under a huge sky",
          "sunrise light across the ridges, deep blue shadow"),
    Scene("waterfall_rest",
          "a young woman sitting on a boulder beside a waterfall, arms around her "
          "knees",
          "green mossy rocks and falling water behind her",
          "soft diffused light, spray in the air"),
    Scene("field_bike",
          "a young woman beside a bicycle in a wheat field, hand shading her eyes",
          "endless golden field under a wide sky",
          "low evening sun turning the field gold"),
    Scene("dunes_dusk",
          "a young woman walking a dune ridge, footprints behind her",
          "rolling sand dunes at dusk, wind lifting sand",
          "deep orange sky, blue shadow in the valleys"),
    Scene("autumn_bench",
          "a young woman in headphones on a park bench with a coffee, leaves "
          "falling around her",
          "an avenue of red and gold trees",
          "warm afternoon light through autumn leaves"),
    Scene("snow_porch",
          "a young woman wrapped in a blanket on a cabin porch with a mug",
          "a snowy valley, pines heavy with snow",
          "cold blue snow light, warm glow from the doorway"),
    Scene("aurora_watch",
          "a young woman in a parka looking up, breath visible",
          "a frozen lake under green aurora, mountains behind",
          "green and violet aurora reflected on the ice"),
    Scene("canoe_evening",
          "a young woman paddling a canoe slowly, ripples spreading behind",
          "a quiet river between forested banks",
          "warm low sun down the river, long reflections"),
    # --- дом и уют ---------------------------------------------------------------
    Scene("fireplace",
          "a young woman in big headphones curled up in a deep armchair with a "
          "blanket and a mug",
          "a warm room in front of a burning fireplace, bookshelves behind",
          "orange firelight on her face against deep blue shadows"),
    Scene("rain_window_desk",
          "a young woman in headphones at a desk by a rain-streaked window, chin "
          "on her hand",
          "a small room at night, city lights blurred outside",
          "cool blue outside, one warm desk lamp inside"),
    Scene("attic_snow",
          "a young woman in headphones in a knitted sweater hugging her knees",
          "a wooden attic room with a round window, heavy snow outside",
          "warm candlelight inside against a pale blue snowstorm"),
    Scene("kitchen_morning",
          "a young woman in headphones making coffee, steam rising, smiling to "
          "herself",
          "a bright kitchen with plants on the windowsill",
          "clean morning sunlight across the counter"),
    Scene("studio_keys",
          "a young woman in headphones at a synthesiser, hands on the keys, eyes "
          "closed",
          "a small home studio, cables and monitors around",
          "warm key light, coloured LEDs in the background"),
    Scene("vinyl_floor",
          "a young woman lying on a rug beside a record player, records spread "
          "around her",
          "a sunlit living room floor",
          "wide warm daylight across the rug"),
    Scene("greenhouse",
          "a young woman in headphones watering plants in a glasshouse",
          "rows of green plants, glass panes above",
          "bright diffused light through the glass, rich greens"),
    Scene("string_lights",
          "two friends on a balcony with mugs, laughing, string lights overhead",
          "a city evening beyond the railing",
          "warm string lights against a blue evening"),
    Scene("desk_cat",
          "a young woman in headphones at a desk, chin on her hand, a sleeping cat "
          "beside the keyboard",
          "a small studio room at night, plants and a record player on the shelf",
          "warm amber desk lamp, the rest of the room deep blue"),
    Scene("window_reading",
          "a young woman in headphones reading in a wide window seat, knees up",
          "a tall window with a quiet street below",
          "soft daylight from the side, dust in the air"),
)


# Шортс живёт по другим законам. Его листают большим пальцем, решение занимает
# доли секунды, и спокойная сцена там просто пролистывается. Поэтому здесь
# движение: бег, прыжок, брызги, танец.
SHORT_SCENES = (
    # --- пляж и вода -------------------------------------------------------------
    Scene("run_surf",
          "a young woman in headphones running through shallow surf, water "
          "splashing around her legs, caught mid-stride",
          "a sunny beach, turquoise waves rolling in behind her",
          "hard sunlight, frozen water droplets, vivid blue and gold"),
    Scene("beach_walk_headphones",
          "a young woman in big headphones walking along the shoreline, hands in "
          "her pockets, hair in the wind, mid-step",
          "wet sand mirroring the sky, waves sliding up beside her",
          "bright sun, reflections on wet sand, saturated turquoise"),
    Scene("jump_sea",
          "a young woman jumping off a wooden jetty into the sea, arms wide, "
          "caught in mid-air",
          "a clear turquoise bay under a blue sky",
          "bright midday sun, spray and ripples below her"),
    Scene("beach_dance",
          "two friends in headphones dancing on the sand, hands up, caught "
          "mid-movement",
          "a beach party at golden hour, string lights and sea behind",
          "warm low sun, long shadows, vivid colour"),
    Scene("surf",
          "a surfer girl turning on a wave, spray flying off the board",
          "a clean blue-green wave under a bright sky",
          "hard sunlight through the wave, white spray frozen in the air"),
    Scene("volleyball",
          "a young woman leaping for a volleyball, arm back, sand flying from her "
          "feet",
          "a beach court, net and sea behind",
          "high sun, sharp shadows, bright sand"),
    Scene("cartwheel_sand",
          "a young woman mid-cartwheel on wet sand, hair hanging down, laughing",
          "an empty beach at low tide, sea behind",
          "late sun, long shadow across the sand"),
    Scene("splash_friends",
          "two friends throwing water at each other in the shallows, caught "
          "mid-splash",
          "shallow turquoise water, beach behind",
          "hard noon sun, frozen droplets catching light"),
    Scene("paddleboard",
          "a young woman turning hard on a paddleboard, paddle cutting the water",
          "a calm bay with cliffs behind",
          "bright morning light, clear green water"),
    Scene("sunset_sprint",
          "a young woman sprinting along the shoreline towards the camera, spray "
          "at her heels",
          "a beach at sunset, sun low behind her",
          "backlit golden hour, rim light on her outline"),
    # --- дорога и город в движении -----------------------------------------------
    Scene("car_window",
          "a young woman sitting in the passenger seat facing forward, her arm out "
          "of the open window riding the air, laughing, hair flying",
          "a coastal road, sea and palms blurring past",
          "golden sunlight, strong motion blur outside the window"),
    Scene("skate_promenade",
          "a young woman in headphones skating along a seaside promenade, leaning "
          "into the turn",
          "palms, white railings and the sea beside her",
          "bright afternoon sun, strong motion blur on the background"),
    Scene("bike_rain",
          "a cyclist in headphones cutting through a puddle, water spraying up",
          "a neon shopping street at night, signs reflected in the water",
          "pink and green neon, splash frozen mid-air"),
    Scene("scooter_city",
          "a young woman on a scooter leaning into a bend, scarf flying",
          "a sunny city street with palms and parked cars",
          "warm daylight, blurred background, sharp face"),
    Scene("crosswalk_run",
          "a young woman in headphones running across a crossing, bag swinging",
          "a busy city crossing, traffic blurred around her",
          "hard afternoon sun between buildings"),
    Scene("subway_run",
          "a young woman in headphones running along a platform, scarf trailing",
          "a night metro station, a train blurring past",
          "cold fluorescent light against warm train windows"),
    Scene("rollerblades",
          "a young woman on rollerblades carving a turn, arms out for balance",
          "a park path lined with trees, people blurred behind",
          "dappled sunlight through the leaves"),
    Scene("longboard_hill",
          "a young woman crouched low on a longboard going downhill, hair streaming",
          "a steep hill road with the sea at the bottom",
          "bright sun, strong motion blur on the asphalt"),
    Scene("taxi_window_night",
          "a young woman in headphones leaning out of a taxi window, eyes closed, "
          "wind in her hair",
          "a neon city street at night, lights streaking past",
          "magenta and cyan neon, long exposure streaks"),
    Scene("stairs_jump",
          "a young woman jumping down a flight of city steps, both feet off the "
          "ground",
          "wide stone steps with a plaza below",
          "hard midday sun, sharp shadow under her"),
    # --- танец и люди ------------------------------------------------------------
    Scene("crowd_hands",
          "a crowd seen from inside, hands and phone lights raised, one woman in "
          "headphones in focus close to the camera",
          "an open-air night set, stage haze behind",
          "violet stage light, warm phone lights"),
    Scene("rooftop_party",
          "two friends in headphones spinning with their hands in the air",
          "a rooftop above a glowing city, string lights overhead",
          "warm string lights against cold blue dusk"),
    Scene("festival_confetti",
          "a young woman laughing with confetti falling around her, arms up",
          "a festival field at golden hour, flags and crowd behind",
          "backlit sun through the confetti"),
    Scene("street_dance_neon",
          "a dancer mid-spin on a wet street, jacket flying out",
          "a neon alley at night, puddles throwing colour back",
          "hot magenta and cyan neon, wet highlights"),
    Scene("silent_disco",
          "a young woman in glowing headphones dancing with her eyes closed",
          "a dark room full of dancers with coloured headphones",
          "blue and pink glow from the headphones themselves"),
    Scene("concert_jump",
          "a young woman jumping in a crowd, hands up, caught at the top",
          "a packed open-air concert, stage lights behind",
          "white stage beams through haze"),
    Scene("bonfire_dance",
          "friends dancing around a bonfire on the sand, sparks rising",
          "a night beach, sea dark behind",
          "orange firelight on faces against deep blue night"),
    Scene("boat_party",
          "a young woman dancing on a boat deck, hair flying, sea spray behind",
          "a small boat cutting across a bright bay",
          "hard sun, sparkling wake, vivid blue"),
    Scene("studio_mirror",
          "a dancer mid-turn in a studio, reflection caught in the mirror",
          "a bright dance studio with a wall of mirrors",
          "clean daylight through tall windows"),
    Scene("sparklers_night",
          "two friends spinning sparklers, light trails around them",
          "a dark beach at night, sea behind",
          "white sparkler trails against deep blue"),
    # --- спорт и движение --------------------------------------------------------
    Scene("basketball_night",
          "a young woman going up for a shot on an outdoor court, ball leaving her "
          "hand",
          "a floodlit city court at night, fence and towers behind",
          "white floodlights, deep shadow around"),
    Scene("parkour_roof",
          "a young woman mid-leap between two low rooftops, arms wide",
          "a sunlit rooftop level above a city",
          "hard afternoon sun, strong shadow below her"),
    Scene("climbing_wall",
          "a young woman reaching for a hold on a climbing wall, muscles tight",
          "a bright bouldering gym, colourful holds",
          "even gym light, saturated hold colours"),
    Scene("autumn_jog",
          "a young woman in headphones jogging through fallen leaves, leaves "
          "kicked up behind her",
          "a park avenue of red and gold trees",
          "low sun down the avenue, backlit leaves"),
    Scene("boxing_gym",
          "a young woman landing a punch on a heavy bag, sweat and dust in the air",
          "an old boxing gym, ropes and bags around",
          "hard side light through high windows"),
    Scene("yoga_cliff",
          "a young woman rising from a yoga pose on a cliff mat, arms sweeping up",
          "a cliff edge above the sea at sunrise",
          "pink and gold sunrise behind her"),
    Scene("pool_push",
          "a swimmer pushing off the pool wall underwater, bubbles streaming",
          "a clear blue swimming pool, lane ropes above",
          "underwater sunlight in moving patterns"),
    Scene("tennis_serve",
          "a young woman at the top of a tennis serve, racquet back, ball in the air",
          "an outdoor court with palms behind",
          "hard midday sun, sharp shadow on the court"),
    Scene("trampoline",
          "a young woman at the top of a trampoline jump, hair up, laughing",
          "a garden with a hedge and summer sky",
          "bright sun, deep blue sky behind"),
    Scene("summit_arms",
          "a hiker throwing her arms up on a summit, pack on her back",
          "a mountain top above a sea of cloud",
          "sunrise breaking over the cloud layer"),
    # --- погода и время года -----------------------------------------------------
    Scene("rain_umbrella_spin",
          "a young woman spinning with an umbrella in the rain, water flying off "
          "the rim",
          "a wet city square at night, lamps glowing",
          "warm lamp light through the rain"),
    Scene("snow_throw",
          "two friends throwing snow at each other, powder in the air",
          "a snowy park with bare trees",
          "cold blue snow light with a warm low sun"),
    Scene("leaf_kick",
          "a young woman kicking a pile of autumn leaves into the air, laughing",
          "a park path covered in leaves",
          "warm backlight through the flying leaves"),
    Scene("sprinkler",
          "a young woman in headphones spinning under falling water, droplets "
          "flying off her hair",
          "a sunny terrace by the sea",
          "backlit sun through the spray, rainbow highlights"),
    Scene("wind_pier",
          "a young woman on a windy pier, coat and hair blown sideways, holding "
          "the rail",
          "a grey-green sea with white caps",
          "bright overcast light, cold colours with warm skin"),
    Scene("puddle_jump",
          "a young woman in boots jumping into a puddle, splash frozen around her "
          "feet",
          "a rainy street with warm shop windows",
          "warm window light on wet pavement"),
    Scene("sunflower_run",
          "a young woman running through a sunflower field, hands brushing the "
          "flowers",
          "rows of sunflowers taller than her, blue sky above",
          "hard sun, saturated yellow and blue"),
    Scene("desert_jeep",
          "a young woman standing up through the roof of a moving jeep, arms out",
          "a desert track with dust rising behind",
          "low sun, golden dust, long shadows"),
    Scene("blossom_spin",
          "a young woman spinning under blossom, petals falling around her",
          "an avenue of cherry trees in full bloom",
          "soft spring sun, pink petals against blue"),
    Scene("night_run_city",
          "a young woman in headphones running a city bridge at night, breath "
          "visible",
          "a lit bridge with the skyline behind",
          "cold blue night, warm lamp pools along the bridge"),
)


# Как это снято. Живая сцена с настоящими людьми, а не ночная картина: такую
# обложку в ленте и замечают. Буквы генератору не доверяем — запрет прямой.
LOOK = ("cinematic photograph, real people, natural skin tones, correct anatomy, "
        "natural plausible pose, body facing the direction of movement, hands "
        "holding what they are using, shallow depth of field, 50mm lens, rich "
        "saturated colors, high contrast, sharp focus, "
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
FRAME_H = (f"16:9 horizontal composition, {ONE_SHOT}, the person stands in the "
           "right third of the frame, nothing important on the left: the left 45% "
           "of the width is open background — sky, sea, road or field — kept clear "
           "for large text, and the person must not cross into it")
FRAME_V = (f"9:16 vertical composition, {ONE_SHOT}, the person in the upper half "
           "of the frame, nothing important below: the lower 35% of the height is "
           "open background — sand, water or road — kept clear for large text")
# Шортс — это движение. Говорим про него прямо, иначе генератор рисует позу.
MOTION = ("energetic action shot, caught mid-movement, dynamic diagonal "
          "composition, motion blur on the background, vivid saturated colours, "
          "bright and lively, joyful")


def rows_for(vertical: bool) -> tuple[Scene, ...]:
    """Набор сюжетов: у ролика свой, у отрывка свой."""
    return SHORT_SCENES if vertical else SCENES


def pick(seed: int, *, vertical: bool = False) -> Scene:
    """Сюжет по номеру — для показа и для командной строки."""
    rows = rows_for(vertical)
    return rows[seed % len(rows)]


def pick_fresh(used: Mapping[str, int], *, vertical: bool = False) -> Scene:
    """Сюжет, которого ещё не было. Из наименее использованных — случайный.

    Просто случайный выбор повторяется чаще, чем кажется: из пятидесяти сюжетов
    совпадение в первой же десятке почти неизбежно. Поэтому сначала идут те, что
    не использованы ни разу, и только когда круг пройден — те, что реже других.
    """
    rows = rows_for(vertical)
    least = min(used.get(scene.key, 0) for scene in rows)
    pool = [scene for scene in rows if used.get(scene.key, 0) == least]
    return random.choice(pool)


def style_hint_of(label: str, use: str) -> str:
    """Короткая подсказка о жанре для картинки.

    Музыкальный промпт сюда класть нельзя: «halftime drums around 140 bpm» для
    генератора картинок шум, и он начинает рисовать барабаны. Нужны два слова
    про настроение, не про аранжировку.
    """
    parts = [part.strip() for part in (label.split("(")[0], use) if part.strip()]
    return ", ".join(parts)[:120]


def build_prompt(scene: Scene, style_hint: str = "", *, vertical: bool = False) -> str:
    """Промпт обложки: сюжет, свет, правила кадра и запрет на надписи.

    style_hint — пара слов о жанре, чтобы палитра обложки и музыки не спорили.
    """
    frame = FRAME_V if vertical else FRAME_H
    hint = f" The mood matches {style_hint}." if style_hint else ""
    head = ("Vertical thumbnail for a short music video."
            if vertical else "YouTube thumbnail photo for a long music mix.")
    look = f"{MOTION}, {LOOK}" if vertical else LOOK
    return (f"{head} {scene.subject}, {scene.place}. "
            f"Lighting: {scene.light}.{hint} {PEOPLE}. {frame}. {look}.")


def make(client: KieClient, dst: Path, *, model: str, scene: Scene,
         style_hint: str = "", vertical: bool = False) -> tuple[Path, float, str]:
    """Сгенерировать картинку обложки. Возвращает файл, цену и промпт.

    Это единственное место обложки, которое стоит денег, поэтому вызывается оно
    только по просьбе: галочкой при создании или кнопкой в библиотеке.
    """
    prompt = build_prompt(scene, style_hint, vertical=vertical)
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
    log.info("Картинка обложки готова: сюжет %s, %.1f кредитов", scene.key, credits)
    return dst, credits, prompt
