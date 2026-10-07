"""Теги и хештеги для музыкального ролика.

Честно о том, чего теги не делают. В топ они не выводят: выдачу решают
заголовок, обложка, кликабельность и удержание — теги на ранжирование влияют
слабо. Их настоящая польза в другом: они ловят поиск по опечаткам и по
формулировкам, которых нет в заголовке («music to study to», «1 hour»,
«no copyright»), и помогают площадке отнести ролик к нужной нише. Поэтому набор
строится не из модных слов, а из того, как такие миксы ищут.

Ограничения площадки: все теги вместе — не длиннее 500 знаков, один тег до 100.
Первые три хештега в описании показываются над заголовком, поэтому они должны
быть короткими и по делу.
"""
from __future__ import annotations

import re

TAGS_LIMIT = 500
TAG_MAX = 100
HASHTAGS_SHOWN = 3

# Как такие миксы ищут независимо от жанра: по занятию и по длине.
USE_TERMS = (
    "study music", "work music", "focus music", "sleep music",
    "relaxing music", "background music", "concentration music",
    "music to study to", "music for work", "chill mix",
)

LENGTH_TERMS = {
    25: ("30 minute mix",),
    30: ("30 minute mix", "30 minutes"),
    35: ("30 minute mix",),
    45: ("45 minute mix",),
    60: ("1 hour mix", "1 hour music", "one hour"),
    90: ("1 hour mix", "long mix"),
}

# Что приписывают к сгенерированным релизам. Называем вещи своими именами:
# скрывать происхождение музыки незачем, а ищут её и так.
HONEST = ("ai music", "ai generated music", "royalty free music",
          "no copyright music")


def _clean(value: str) -> str:
    value = re.sub(r"[#\s]+", " ", str(value or "")).strip().lower()
    return value[:TAG_MAX]


def build(*, style_tags: tuple | list = (), series: str = "", genre: str = "",
          minutes: int = 0, use: str = "", extra: tuple | list = ()) -> list[str]:
    """Набор тегов: жанр, серия, занятие, длительность. Без повторов и в лимите."""
    rows: list[str] = []

    def push(value: str) -> None:
        tag = _clean(value)
        if tag and tag not in rows:
            rows.append(tag)

    def push_pair(value: str) -> None:
        """Фраза и её слитный близнец: «chillstep mix» плюс «chillstepmix».

        Слитная форма — это то, во что превращается хештег, и ровно её ищут,
        когда переходят по хештегу из описания. Фразу с пробелом тоже
        оставляем: по ней работает обычный поиск, а слитная там совпадает хуже.
        """
        push(value)
        glued = re.sub(r"[^a-z0-9]+", "", _clean(value))
        if glued and glued != _clean(value):
            push(glued)

    # Слитные близнецы — только у жанровых и у фирменных тегов. Делать их всем
    # подряд значит забить лимит в 500 знаков повторами вместо новых слов.
    for tag in style_tags:
        push_pair(tag)
    if genre:
        push(genre)
    if series:
        push_pair(series)
        # «Aurora Drive mix» ищут чаще, чем просто «Aurora Drive».
        push_pair(f"{series} mix")
    for term in LENGTH_TERMS.get(int(minutes or 0), ()):
        push(term)
    if use:
        # Из «study and focus» выходит «study music» и «focus music».
        for word in re.split(r"[,&]| and ", use):
            word = word.strip()
            if word:
                push(f"{word} music")
    for term in USE_TERMS:
        push(term)
    for term in HONEST:
        push(term)
    for term in extra:
        push(term)

    # Лимит площадки считается по всей строке, а не по числу тегов.
    out: list[str] = []
    total = 0
    for tag in rows:
        cost = len(tag) + (1 if out else 0)
        if total + cost > TAGS_LIMIT:
            break
        out.append(tag)
        total += cost
    return out


def hashtags(tags: list[str], limit: int = HASHTAGS_SHOWN) -> list[str]:
    """Хештеги для описания: #chillstep #chillstepmix #melodicdubstep.

    Пробелов внутри хештега быть не может — площадка обрывает его на первом же
    пробеле, и «#chillstep mix» превратился бы в «#chillstep» и слово «mix»
    рядом. Поэтому многословные теги склеиваем.

    Почему их мало. Если хештегов в заголовке, описании и поле тегов вместе
    больше пятнадцати, YouTube перестаёт учитывать их все до единого. Над
    заголовком показываются первые три — ими и ограничиваемся.
    """
    out: list[str] = []
    for tag in tags:
        word = re.sub(r"[^a-z0-9]+", "", tag)
        if 3 <= len(word) <= 18 and word not in out:
            out.append(word)
        if len(out) >= limit:
            break
    return out
