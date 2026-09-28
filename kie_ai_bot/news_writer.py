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
