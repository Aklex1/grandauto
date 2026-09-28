"""
Черновик поста из новости: отбор пользы и текст по шаблону канала.

Раньше новость уезжала в канал пересказом ленты: «Источник: Хабр», три
абзаца чужого текста и никакого мнения. Такое читают по одному разу.
Здесь новость сначала проходит отбор — полезна ли она обычному человеку
(учителю, студенту, автору, продавцу), а не разработчику, — и только потом
переписывается по шаблону:

    🔥 хук одной строкой
    что это — одна-две строки простыми словами
    почему важно вам — одна строка
    👉 как попробовать — шаг и шаг
    💬 моё мнение — одна честная фраза
    вопрос читателю
    #рубрика

Модель зовём через тот же KIE, которым бот уже генерирует картинки, —
отдельного ключа не нужно. Если модель не ответила или отбраковала
новость, вызывающий код решает сам: взять следующую новость или собрать
пост по-старому. Молча publish'ить английский текст или пустой ответ
нельзя, поэтому все отказы возвращаются явно.
"""

import json
import logging
import os
import re
from dataclasses import dataclass
from typing import List, Optional

import httpx

from config import KIE_API_KEY

logger = logging.getLogger("news.writer")

CHAT_URL = "https://api.kie.ai/{model}/v1/chat/completions"

# Порядок как у сайта: сначала дешёвая быстрая модель, потом запасная.
MODELS = [m.strip() for m in
          os.getenv("NEWS_WRITER_MODELS", "gemini-2.5-flash,gpt-5-2").split(",")
          if m.strip()]
TIMEOUT = int(os.getenv("NEWS_WRITER_TIMEOUT", "90") or 90)
ENABLED = os.getenv("NEWS_WRITER_ENABLED", "0").strip().lower() in ("1", "true", "yes", "on")

# Пост должен помещаться под медиа одним сообщением.
LIMIT = int(os.getenv("NEWS_WRITER_LIMIT", "900") or 900)

SYSTEM = (
    "Ты редактор телеграм-канала «Нейросети» — про нейросети для жизни и работы, "
    "без хайпа. Читатели: учителя, студенты, авторы контента, мелкие "
    "предприниматели, продавцы на маркетплейсах. Они не программисты.\n\n"
    "Твоя работа в два шага.\n"
    "1. Решить, полезна ли новость такому читателю. Полезно: инструмент, который "
    "можно открыть и применить; подешевело или стало бесплатным; работает из "
    "России; сценарий, экономящий время; понятная опасность (мошенники, утечки). "
    "Не полезно: раунды инвестиций, кадровые перестановки, бенчмарки и научные "
    "статьи без применения, корпоративные анонсы без доступа, железо для "
    "дата-центров, чужие мнения о будущем ИИ.\n"
    "2. Если полезна — написать пост по шаблону, по-русски, живым языком, "
    "без канцелярита и без слова «революция». Мнение автора обязательно и "
    "должно быть конкретным: что понравилось или что не так.\n\n"
    "Запрещено: ссылки и адреса сайтов, названия и упоминания сервисов-конкурентов "
    "в роли рекламы, выдуманные цифры и цены, обещания заработка, "
    "слова «уникальный», «прорыв», «мастхэв».\n\n"
    "Ответ — только JSON, без пояснений и без markdown-обёртки:\n"
    '{"useful": true|false, "reason": "почему взял или отбраковал, одна фраза", '
    '"hook": "заголовок-хук до 80 знаков", "what": "что это, 1-2 строки", '
    '"why_you": "почему важно читателю, одна строка", '
    '"how": "как попробовать: шаг → шаг, до 160 знаков", '
    '"opinion": "мнение автора, одна фраза", '
    '"question": "вопрос читателю, одна фраза", '
    '"tag": "один хэштег из списка: #проверил_сам #разбор #новости #осторожно"}'
)


@dataclass
class Draft:
    useful: bool
    reason: str = ""
    text: str = ""          # готовый пост в HTML для телеграма
    model: str = ""


# Рубрики канала: что за пост, сколько свежего материала нужно и чем
# отличается задание модели. Ключ — то же слово, по которому рубрика
# опознаётся в контент-плане (см. WEEKDAY_RUBRICS в news_autopost).
RUBRIC_SPECS = {
    "главное за неделю": {
        "shape": "digest",
        "items": 5,
        "tag": "#новости",
        "task": (
            "Собери «Главное за неделю за 2 минуты»: пять пунктов, каждый — одна "
            "строка о том, что случилось, и сразу чем это полезно читателю. "
            "Никакой воды и технических деталей. Если материала меньше пяти, "
            "сделай столько пунктов, сколько есть."
        ),
    },
    "промпт дня": {
        "shape": "post",
        "items": 0,
        "tag": "#промпт_дня",
        "task": (
            "Напиши рубрику «Промпт дня». В поле what дай сам промпт целиком, в "
            "кавычках, готовый к копированию: одна задача, которую человек решает "
            "в работе или учёбе (письмо, конспект, описание товара, план урока). "
            "В how — что подставить своё и куда вставить промпт. Промпт должен "
            "работать в любом чате с нейросетью."
        ),
    },
    "проверил сам": {
        "shape": "post",
        "items": 1,
        "tag": "#проверил_сам",
        "task": (
            "Напиши рубрику «Проверил сам» по свежему материалу: что за "
            "инструмент, что он умеет на практике, сколько стоит и работает ли "
            "из России. В opinion дай честную оценку с оговоркой — что вышло "
            "хуже ожиданий. Не выдумывай цифры, которых нет в материале."
        ),
    },
    "батл": {
        "shape": "post",
        "items": 1,
        "tag": "#батл",
        "task": (
            "Напиши рубрику «Батл»: одна бытовая или рабочая задача и два "
            "способа её решить нейросетями (например, текстовая модель против "
            "генератора картинок). В what — задача и оба подхода, в opinion — "
            "кто победил и почему. В question — вопрос-опрос читателям, кто "
            "лучше по их опыту. Итог не выдавай за измерение: это наблюдение."
        ),
    },
    "кейс": {
        "shape": "post",
        "items": 0,
        "tag": "#кейс",
        "task": (
            "Напиши рубрику «Кейс»: как человек обычной профессии (учитель, "
            "юрист, продавец на маркетплейсе, репетитор, автор) сократил рутину "
            "нейросетями. Пиши как разбор приёма, а не как историю успеха: "
            "в what — что именно делалось по шагам, в why_you — где тут "
            "экономия времени. Никаких сумм заработка и вымышленных отзывов."
        ),
    },
    "нейрофейл": {
        "shape": "post",
        "items": 0,
        "tag": "#нейрофейл",
        "task": (
            "Напиши рубрику «Нейрофейл»: типичная осечка нейросетей, над которой "
            "можно посмеяться, и что с ней делать. Тон лёгкий, без злорадства. "
            "Только то, что действительно бывает: выдуманные факты и ссылки, "
            "лишние пальцы, зеркальный текст, путаница в датах. Конкретных "
            "цифр и названий брендов не выдумывай."
        ),
    },
    "челлендж": {
        "shape": "post",
        "items": 0,
        "tag": "#челлендж",
        "task": (
            "Напиши рубрику «Челлендж недели»: короткое задание, которое читатель "
            "выполнит за 15 минут и покажет результат. В how — шаги, в question — "
            "приглашение прислать работу в комментарии. Обещаний призов не давай."
        ),
    },
}


def _clean(value: object, limit: int = 300) -> str:
    """Строка от модели: без html, без ссылок, в одну строку."""
    text = str(value or "").strip()
    text = re.sub(r"<[^>]+>", "", text)
    text = re.sub(r"https?://\S+|(?<![\w@.])(?:[a-z0-9-]+\.)+(?:ru|com|org|net|io|ai|me|dev)(?:/\S*)?",
                  "", text, flags=re.I)
    # После вырезанного адреса остаётся висячий предлог: «Открывается на и
    # работает». Убираем его вместе с адресом.
    text = re.sub(r"\s+(?:на|в|во|по|с|со|из|от|у|для|при|через)\s+(?=и\s|\s|[.,;:!?)]|$)",
                  " ", text, flags=re.I)
    text = re.sub(r"\s+", " ", text).strip(" -—•")
    return text[:limit].strip()


def _escape(text: str) -> str:
    return (text.replace("&", "&amp;").replace("<", "&lt;").replace(">", "&gt;"))


def _assemble(data: dict) -> str:
    """Пост из частей. Порядок строк — тот же, что в шаблоне канала."""
    hook = _clean(data.get("hook"), 90)
    what = _clean(data.get("what"), 260)
    why_you = _clean(data.get("why_you"), 180)
    how = _clean(data.get("how"), 200)
    opinion = _clean(data.get("opinion"), 200)
    question = _clean(data.get("question"), 140)
    tag = _clean(data.get("tag"), 40)
    if tag and not tag.startswith("#"):
        tag = "#" + tag.lstrip("#").split()[0]

    if not hook or not what:
        return ""

    lines = [f"🔥 <b>{_escape(hook)}</b>", ""]
    lines.append(_escape(what))
    if why_you:
        lines.append(f"<b>Почему это важно вам:</b> {_escape(why_you)}")
    if how:
        lines.append(f"👉 {_escape(how)}")
    if opinion:
        lines.append(f"💬 {_escape(opinion)}")
    if question:
        lines.append("")
        lines.append(_escape(question))
    if tag:
        lines.append(_escape(tag))

    text = "\n".join(lines).strip()
    # Лимит подписи под медиа — режем по строкам, чтобы не оборвать фразу.
    while len(text) > LIMIT and len(lines) > 3:
        # Первыми уходят необязательные строки с конца, кроме хэштега.
        drop = -2 if lines[-1].startswith("#") else -1
        lines.pop(drop)
        text = "\n".join(lines).strip()
    return text


def _parse(content: str) -> Optional[dict]:
    """JSON из ответа модели. Модели любят обернуть его в ```json."""
    raw = (content or "").strip()
    raw = re.sub(r"^```(?:json)?|```$", "", raw, flags=re.I | re.M).strip()
    start, end = raw.find("{"), raw.rfind("}")
    if start < 0 or end <= start:
        return None
    try:
        data = json.loads(raw[start:end + 1])
        return data if isinstance(data, dict) else None
    except Exception:
        return None


async def _ask(model: str, user: str) -> Optional[str]:
    payload = {
        "model": model,
        "stream": False,
        "temperature": 0.7,
        "messages": [
            {"role": "system", "content": SYSTEM},
            {"role": "user", "content": user},
        ],
    }
    headers = {"Authorization": f"Bearer {KIE_API_KEY}", "Content-Type": "application/json"}
    url = CHAT_URL.format(model=model)
    async with httpx.AsyncClient(timeout=TIMEOUT) as client:
        response = await client.post(url, headers=headers, json=payload)
        if response.status_code != 200:
            logger.warning("[писатель] %s ответил %s: %s", model, response.status_code,
                           response.text[:200])
            return None
        body = response.json()
    try:
        return body["choices"][0]["message"]["content"]
    except Exception:
        logger.warning("[писатель] %s: неожиданный ответ %s", model, str(body)[:200])
        return None


def _assemble_digest(data: dict) -> str:
    """Дайджест «главное за неделю»: пять строк вместо связного текста."""
    hook = _clean(data.get("hook"), 90)
    bullets = data.get("bullets") or data.get("items") or []
    if isinstance(bullets, str):
        bullets = [bullets]
    lines_out = []
    for raw in list(bullets)[:5]:
        if isinstance(raw, dict):
            piece = " — ".join(x for x in (_clean(raw.get("what"), 120),
                                           _clean(raw.get("why"), 120)) if x)
        else:
            piece = _clean(raw, 200)
        if piece:
            lines_out.append(piece)
    if not hook or not lines_out:
        return ""

    opinion = _clean(data.get("opinion"), 200)
    question = _clean(data.get("question"), 140)
    tag = _clean(data.get("tag"), 40) or "#новости"
    if not tag.startswith("#"):
        tag = "#" + tag.lstrip("#").split()[0]

    lines = [f"🗞 <b>{_escape(hook)}</b>", ""]
    for index, piece in enumerate(lines_out, 1):
        lines.append(f"{index}. {_escape(piece)}")
    if opinion:
        lines.append("")
        lines.append(f"💬 {_escape(opinion)}")
    if question:
        lines.append(_escape(question))
    lines.append(_escape(tag))

    text = "\n".join(lines).strip()
    while len(text) > LIMIT and len(lines) > 4:
        # Режем с конца пункты, а не хвост: обрубленный пункт выглядит сбоем
        drop = -2 if lines[-1].startswith("#") else -1
        lines.pop(drop)
        text = "\n".join(lines).strip()
    return text


async def write_rubric(rubric: str, items: Optional[List[dict]] = None) -> Draft:
    """
    Пост рубрики, когда в контент-плане на этот день ничего не осталось.

    rubric — ключ из RUBRIC_SPECS («промпт дня», «батл», «кейс»…).
    items — свежий материал из лент: [{"title":…, "summary":…, "source":…}].
    Рубрикам вроде «промпт дня» материал не нужен вовсе, а «главному за
    неделю» нужно до пяти новостей.
    """
    spec = RUBRIC_SPECS.get(rubric)
    if not spec:
        return Draft(useful=False, reason=f"неизвестная рубрика: {rubric}")
    if not KIE_API_KEY:
        return Draft(useful=False, reason="нет ключа поставщика")

    need = int(spec.get("items", 0))
    items = list(items or [])
    if need and not items:
        return Draft(useful=False, reason="для этой рубрики нужен свежий материал")

    user = f"Рубрика: {rubric}.\n{spec['task']}\n"
    if items:
        user += "\nСвежий материал (ссылки в пост не ставить):\n"
        for one in items[:max(need, 1)]:
            title = _clean(one.get("title"), 200)
            summary = _clean(one.get("summary"), 600)
            source = _clean(one.get("source"), 60)
            user += f"- {title}"
            if summary:
                user += f". {summary}"
            if source:
                user += f" (источник: {source})"
            user += "\n"
        user += ("\nЕсли материал на английском — переведи смысл, пост только "
                 "по-русски.\n")

    if spec["shape"] == "digest":
        user += ('\nВерни JSON: {"useful": true, "reason": "", "hook": "заголовок до 80 знаков", '
                 '"bullets": ["что случилось — чем полезно вам", "…"], '
                 '"opinion": "одна фраза от автора", "question": "вопрос читателям", '
                 f'"tag": "{spec["tag"]}"}}')
    else:
        user += (f'\nВерни JSON по инструкции из системного сообщения, поле tag = "{spec["tag"]}". '
                 'useful всегда true: рубрику ведём по расписанию, а не по новизне.')

    last = "модель не ответила"
    for model in MODELS:
        try:
            content = await _ask(model, user)
        except Exception as e:
            last = f"{model}: {e}"
            logger.warning("[писатель] %s не ответил: %s", model, e)
            continue
        data = _parse(content or "")
        if data is None:
            last = f"{model}: ответ не разобран"
            continue
        text = (_assemble_digest(data) if spec["shape"] == "digest" else _assemble(data))
        if not text:
            last = f"{model}: пустые поля в ответе"
            continue
        return Draft(useful=True, reason=_clean(data.get("reason"), 200), text=text, model=model)

    return Draft(useful=False, reason=last)


async def write(title: str, summary: str, source: str = "") -> Draft:
    """
    Черновик по новости. useful=False — новость не берём вовсе.

    Пустой text при useful=True означает, что модель ответила невнятно:
    вызывающий код в этом случае собирает пост по-старому, а не публикует
    пустоту.
    """
    if not KIE_API_KEY:
        return Draft(useful=False, reason="нет ключа поставщика")

    user = "Новость.\n"
    if source:
        user += f"Источник (в пост не ставить): {source}\n"
    user += f"Заголовок: {_clean(title, 300)}\n"
    if summary:
        user += f"Текст: {_clean(summary, 1800)}\n"
    user += ("\nЕсли новость на английском — переведи смысл, пост только по-русски. "
             "Верни JSON по инструкции.")

    last = "модель не ответила"
    for model in MODELS:
        try:
            content = await _ask(model, user)
        except Exception as e:
            last = f"{model}: {e}"
            logger.warning("[писатель] %s не ответил: %s", model, e)
            continue
        data = _parse(content or "")
        if data is None:
            last = f"{model}: ответ не разобран"
            continue
        if not data.get("useful"):
            reason = _clean(data.get("reason"), 200) or "не полезно читателю"
            logger.info("[писатель] новость отбракована (%s): %s", model, reason)
            return Draft(useful=False, reason=reason, model=model)
        text = _assemble(data)
        if not text:
            last = f"{model}: пустые поля в ответе"
            continue
        return Draft(useful=True, reason=_clean(data.get("reason"), 200), text=text, model=model)

    return Draft(useful=True, reason=last)   # полезность неизвестна, текста нет


if __name__ == "__main__":
    # Проверка на сервере, где есть ключ поставщика:
    #   venv/bin/python news_writer.py "Заголовок новости" "Текст новости"
    import asyncio
    import sys

    async def _demo() -> None:
        title = sys.argv[1] if len(sys.argv) > 1 else (
            "Google открыл бесплатный доступ к генератору изображений в России")
        summary = sys.argv[2] if len(sys.argv) > 2 else ""
        draft = await write(title, summary, source="проверка")
        print("модель:", draft.model or "—")
        print("полезно:", draft.useful, "|", draft.reason)
        print("-" * 60)
        print(draft.text or "(текста нет)")
        print("-" * 60)
        print("знаков:", len(draft.text))

    asyncio.run(_demo())
