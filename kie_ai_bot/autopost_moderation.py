"""
Модерация автопостинга и ручная публикация.

Модерация: сгенерированный пост не уходит в канал сразу, а сначала
показывается утверждающим пользователям (AUTOPOST_MODERATOR_IDS) с кнопками
«Опубликовать» и «Отклонить». В канал попадает только подтверждённый пост, в
том же оформлении, что и раньше.

Ручная публикация: если модератор стартует бота, ему сразу доступно меню
«Опубликовать пост». Бот спрашивает сначала фото, затем промпт, и публикует
их в канал. Промпт оборачивается в кнопку «Повторить это фото», как у
обычных автопостов.
"""

import logging
import os
from collections import defaultdict
from html import escape
from typing import List

from aiogram import Bot, Dispatcher, F
from aiogram.fsm.context import FSMContext
from aiogram.fsm.state import State, StatesGroup
from aiogram.types import (CallbackQuery, InlineKeyboardButton,
                           InlineKeyboardMarkup, Message, URLInputFile)

import autopost

logger = logging.getLogger("autopost.moderation")


def _parse_ids(raw: str) -> List[int]:
    ids = []
    for chunk in (raw or "").replace(",", " ").split():
        try:
            ids.append(int(chunk))
        except ValueError:
            continue
    return ids


MODERATION_ENABLED = os.getenv("AUTOPOST_MODERATION", "0").strip().lower() in (
    "1", "true", "yes", "on")
MODERATOR_IDS = _parse_ids(os.getenv("AUTOPOST_MODERATOR_IDS", "7442497275,367692958"))

# Сообщения, отправленные модераторам по каждому посту: чтобы убрать кнопки
# после решения. При перезапуске бота теряются — кнопки всё равно работают,
# решение перечитывается из базы
_moderation_msgs = defaultdict(list)


class ManualPublishStates(StatesGroup):
    photo = State()
    prompt = State()


def enabled() -> bool:
    """Модерация включена и есть кому подтверждать."""
    return MODERATION_ENABLED and bool(MODERATOR_IDS)


def is_moderator(user_id: int) -> bool:
    return user_id in MODERATOR_IDS


# --- Модерация автопостов ---------------------------------------------------

def _moderation_keyboard(row_id: int) -> InlineKeyboardMarkup:
    return InlineKeyboardMarkup(inline_keyboard=[[
        InlineKeyboardButton(text="✅ Опубликовать", callback_data=f"apmod_ok:{row_id}"),
        InlineKeyboardButton(text="🗑 Отклонить", callback_data=f"apmod_no:{row_id}"),
    ]])


def _preview_text(row) -> str:
    prompt = (row["prompt"] or "").strip()
    shown = prompt if len(prompt) <= 900 else prompt[:897] + "..."
    parts = ["🔎 <b>Пост на модерацию</b>"]
    if shown:
        parts.append(f"<blockquote>{escape(shown)}</blockquote>")
    else:
        parts.append("<i>без промпта</i>")
    return "\n\n".join(parts)


async def send_for_moderation(bot: Bot, row, result_url: str) -> None:
    """Показывает готовый пост утверждающим пользователям."""
    row_id = row["id"]
    autopost._update(row_id, status="moderation", result_url=result_url, error=None)

    # перечитываем — вдруг промпт/подпись обновились
    row = autopost.get_by_id(row_id) or row
    text = _preview_text(row)
    keyboard = _moderation_keyboard(row_id)
    _moderation_msgs.pop(row_id, None)

    delivered = 0
    for user_id in MODERATOR_IDS:
        try:
            msg = await bot.send_photo(
                chat_id=user_id, photo=URLInputFile(result_url),
                caption=text[:1024], parse_mode="HTML", reply_markup=keyboard,
            )
            _moderation_msgs[row_id].append((user_id, msg.message_id))
            delivered += 1
        except Exception as e:
            logger.warning("[модерация] фото %s не ушло пользователю %s (%s), шлём ссылкой",
                           row_id, user_id, e)
            try:
                msg = await bot.send_message(
                    chat_id=user_id,
                    text=f"{text}\n\n{escape(result_url)}",
                    parse_mode="HTML", reply_markup=keyboard,
                )
                _moderation_msgs[row_id].append((user_id, msg.message_id))
                delivered += 1
            except Exception as e2:
                logger.error("[модерация] пользователю %s не доставить пост %s: %s",
                             user_id, row_id, e2)

    if delivered:
        logger.info("[модерация] пост %s отправлен на подтверждение (%s получателям)",
                    row_id, delivered)
    else:
        logger.error("[модерация] пост %s: ни один модератор не получил — публикуем сразу",
                     row_id)
        await autopost.publish(bot, row, result_url)


async def _clear_buttons(bot: Bot, row_id: int, verdict: str) -> None:
    """Убирает кнопки у всех сообщений модерации и помечает решение."""
    for chat_id, message_id in _moderation_msgs.pop(row_id, []):
        try:
            await bot.edit_message_reply_markup(chat_id=chat_id, message_id=message_id,
                                                reply_markup=None)
            await bot.edit_message_caption(
                chat_id=chat_id, message_id=message_id,
                caption=verdict, parse_mode="HTML")
        except Exception:
            try:
                await bot.edit_message_text(chat_id=chat_id, message_id=message_id,
                                            text=verdict, parse_mode="HTML")
            except Exception:
                pass


def setup(dp: Dispatcher, bot: Bot) -> None:
    """Регистрирует обработчики модерации и ручной публикации."""

    @dp.callback_query(F.data.startswith("apmod_ok:"))
    async def approve(callback: CallbackQuery):
        if not is_moderator(callback.from_user.id):
            return await callback.answer("Только для модераторов", show_alert=True)

        row_id = int(callback.data.split(":", 1)[1])
        row = autopost.get_by_id(row_id)
        if not row:
            return await callback.answer("Запись не найдена", show_alert=True)
        if row["status"] == "published":
            await callback.answer("Уже опубликовано")
            return await _clear_buttons(bot, row_id, "✅ Уже опубликовано")

        await callback.answer("Публикую…")
        who = callback.from_user.username or callback.from_user.id
        await autopost.publish(bot, row, row["result_url"])
        await _clear_buttons(bot, row_id, f"✅ Опубликовано (@{escape(str(who))})")
        logger.info("[модерация] пост %s подтверждён пользователем %s", row_id, who)

    @dp.callback_query(F.data.startswith("apmod_no:"))
    async def reject(callback: CallbackQuery):
        if not is_moderator(callback.from_user.id):
            return await callback.answer("Только для модераторов", show_alert=True)

        row_id = int(callback.data.split(":", 1)[1])
        row = autopost.get_by_id(row_id)
        if not row:
            return await callback.answer("Запись не найдена", show_alert=True)
        if row["status"] == "published":
            await callback.answer("Пост уже опубликован — отклонить нельзя", show_alert=True)
            return await _clear_buttons(bot, row_id, "✅ Опубликовано")

        autopost._update(row_id, status="rejected", error="отклонён модератором")
        await callback.answer("Отклонено")
        who = callback.from_user.username or callback.from_user.id
        await _clear_buttons(bot, row_id, f"🗑 Отклонено (@{escape(str(who))})")
        logger.info("[модерация] пост %s отклонён пользователем %s", row_id, who)

    # --- Ручная публикация ---

    @dp.callback_query(F.data == "apmod_new")
    async def manual_start(callback: CallbackQuery, state: FSMContext):
        if not is_moderator(callback.from_user.id):
            return await callback.answer("Только для модераторов", show_alert=True)
        await state.set_state(ManualPublishStates.photo)
        await callback.message.answer(
            "📤 <b>Публикация поста</b>\n\n"
            "Шаг 1 из 2. Пришлите фотографию для поста.",
            parse_mode="HTML",
            reply_markup=InlineKeyboardMarkup(inline_keyboard=[[
                InlineKeyboardButton(text="❌ Отмена", callback_data="apmod_cancel")]]),
        )
        await callback.answer()

    @dp.callback_query(F.data == "apmod_cancel")
    async def manual_cancel(callback: CallbackQuery, state: FSMContext):
        await state.clear()
        await callback.message.answer("Публикация отменена.")
        await callback.answer()

    @dp.message(ManualPublishStates.photo, F.photo)
    async def manual_photo(message: Message, state: FSMContext):
        if not is_moderator(message.from_user.id):
            return await state.clear()
        file_id = message.photo[-1].file_id
        await state.update_data(photo_file_id=file_id)
        await state.set_state(ManualPublishStates.prompt)
        await message.answer(
            "Шаг 2 из 2. Пришлите промпт — он попадёт в кнопку «Повторить это фото» "
            "и в комментарий под постом.",
            reply_markup=InlineKeyboardMarkup(inline_keyboard=[[
                InlineKeyboardButton(text="❌ Отмена", callback_data="apmod_cancel")]]),
        )

    @dp.message(ManualPublishStates.photo)
    async def manual_photo_wrong(message: Message):
        await message.answer("Нужна именно фотография. Пришлите фото или нажмите «Отмена».")

    @dp.message(ManualPublishStates.prompt, F.text)
    async def manual_prompt(message: Message, state: FSMContext):
        if not is_moderator(message.from_user.id):
            return await state.clear()
        prompt = (message.text or "").strip()
        data = await state.get_data()
        await state.clear()

        file_id = data.get("photo_file_id")
        if not file_id:
            return await message.answer("Фото потерялось. Начните заново: /start")

        await message.answer("Публикую в канал…")
        ok = await autopost.publish_manual(bot, prompt, file_id)
        if ok:
            await message.answer("✅ Опубликовано в канал.", reply_markup=_menu())
        else:
            await message.answer("❌ Не удалось опубликовать. Проверьте настройки канала.")

    @dp.message(ManualPublishStates.prompt)
    async def manual_prompt_wrong(message: Message):
        await message.answer("Пришлите промпт текстом или нажмите «Отмена».")

    logger.info("[модерация] обработчики зарегистрированы; модераторов: %s, модерация: %s",
                len(MODERATOR_IDS), "вкл" if enabled() else "выкл")


def _menu() -> InlineKeyboardMarkup:
    return InlineKeyboardMarkup(inline_keyboard=[[
        InlineKeyboardButton(text="📤 Опубликовать пост", callback_data="apmod_new")]])


async def send_publish_menu(message: Message) -> None:
    """Меню «Опубликовать пост» — показывается модератору при старте бота."""
    await message.answer(
        "📤 <b>Публикация в канал</b>\n\n"
        "Нажмите кнопку, чтобы опубликовать пост: сначала фото, затем промпт.",
        parse_mode="HTML", reply_markup=_menu(),
    )
