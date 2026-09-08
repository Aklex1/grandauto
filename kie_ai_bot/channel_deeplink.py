"""
Переход из канала в бота с уже подставленным промптом.

Под каждым опубликованным постом стоит кнопка «Повторить это фото» со ссылкой
вида https://t.me/<бот>?start=p_<id>. По ней бот открывается, достаёт промпт
этого поста из базы автопостинга и сразу просит прислать своё фото —
переписывать промпт руками не нужно.

Дальше работает штатный конвейер nano-banana-edit: фото пользователя плюс
готовый промпт, списание с баланса как при обычной генерации.
"""

import logging
import os
import re
from contextlib import closing
from typing import Optional

from aiogram import Bot
from aiogram.types import InlineKeyboardButton, InlineKeyboardMarkup

import autopost

logger = logging.getLogger("autopost.deeplink")

DEEPLINK_PREFIX = "p_"
BUTTON_TEXT = "🪄 Повторить это фото"

_bot_username: Optional[str] = None


def _username_from_settings() -> Optional[str]:
    """Имя бота из настроек: явное AUTOPOST_BOT_USERNAME или ссылка AUTOPOST_BOT_URL."""
    explicit = os.getenv("AUTOPOST_BOT_USERNAME", "").strip().lstrip("@")
    if explicit:
        return explicit
    m = re.search(r"t\.me/([A-Za-z0-9_]+)", autopost.BOT_URL or "")
    return m.group(1) if m else None


async def get_bot_username(bot: Bot) -> Optional[str]:
    """Имя бота для ссылки. Берётся из настроек, иначе спрашивается у Telegram
    и запоминается — чтобы пост не остался без кнопки из-за сетевого сбоя."""
    global _bot_username
    if _bot_username:
        return _bot_username

    _bot_username = _username_from_settings()
    if _bot_username:
        return _bot_username

    try:
        me = await autopost.with_retries(bot.get_me, what="запрос имени бота")
        _bot_username = me.username
    except Exception as e:
        logger.error("[deeplink] не удалось узнать имя бота: %s", e)
    return _bot_username


async def build_keyboard(bot: Bot, row_id: int) -> Optional[InlineKeyboardMarkup]:
    """Кнопка под постом: открывает бота с этим промптом."""
    username = await get_bot_username(bot)
    if not username:
        return None
    url = f"https://t.me/{username}?start={DEEPLINK_PREFIX}{row_id}"
    return InlineKeyboardMarkup(
        inline_keyboard=[[InlineKeyboardButton(text=BUTTON_TEXT, url=url)]]
    )


def get_prompt(row_id: int) -> Optional[str]:
    """Промпт опубликованного поста по id записи."""
    try:
        with closing(autopost._connect()) as conn:
            row = conn.execute(
                "SELECT prompt FROM autopost_posts WHERE id = ?", (row_id,)
            ).fetchone()
        return (row["prompt"] or "").strip() if row else None
    except Exception as e:
        logger.error("[deeplink] не удалось прочитать промпт %s: %s", row_id, e)
        return None


def parse_payload(param: str) -> Optional[int]:
    """id промпта из параметра /start, если это наша ссылка."""
    if not param or not param.startswith(DEEPLINK_PREFIX):
        return None
    try:
        return int(param[len(DEEPLINK_PREFIX):])
    except ValueError:
        return None


async def start_with_prompt(message, state, param: str, nano_states) -> bool:
    """Обрабатывает переход по кнопке из канала.

    Возвращает True, если ссылка наша и флоу запущен — тогда обычное
    приветствие показывать не нужно."""
    row_id = parse_payload(param)
    if row_id is None:
        return False

    prompt = get_prompt(row_id)
    if not prompt:
        logger.warning("[deeplink] промпт %s не найден", row_id)
        return False

    # Те же поля, что заполняет обычный флоу nano-banana в режиме «edit»
    await state.set_data({
        "nano_mode": "edit",
        "nano_output_format": "png",
        "output_format": "png",
        "image_size": "auto",
        "preset_prompt": prompt,
    })
    await state.set_state(nano_states.get_image)

    shown = prompt if len(prompt) <= 3000 else prompt[:2997] + "..."
    await message.answer(
        "Промпт из канала уже подставлен — переписывать ничего не нужно.\n\n"
        f"<blockquote><code>{_escape(shown)}</code></blockquote>\n"
        "📸 Пришлите своё фото, и я сделаю такой же кадр с вашей внешностью.",
        parse_mode="HTML",
    )
    logger.info("[deeplink] пользователь %s пришёл за промптом %s",
                message.from_user.id if message.from_user else "?", row_id)
    return True


def _escape(text: str) -> str:
    import html

    return html.escape(text)
