"""
Генератор ссылок в админке: обычные партнёрские и для вебмастеров.

Раздел «🔗 Ссылки» в админ-меню. Админ выбирает тип ссылки, вводит метку
источника — бот отдаёт готовую ссылку и показывает, кто уже по ней пришёл.

Типы:
* обычная партнёрская — ?start=<метка>, стандартные 10% с каждой покупки;
  метка попадает в тег кампании, по ней видно источник трафика;
* для вебмастеров — ?start=wm_<метка>, тариф 50% с первого депозита
  и 20% со всех последующих.
"""

import logging
import re
from contextlib import closing

from aiogram import Bot, Dispatcher, F
from aiogram.filters import Command
from aiogram.fsm.context import FSMContext
from aiogram.fsm.state import State, StatesGroup
from aiogram.types import CallbackQuery, InlineKeyboardButton, InlineKeyboardMarkup, Message

import partner_tiers
from config import is_admin

logger = logging.getLogger("admin.links")

# Метка допускает только то, что Telegram разрешает в параметре start
LABEL_RE = re.compile(r"^[A-Za-z0-9_-]{2,50}$")


class LinkStates(StatesGroup):
    input_label = State()


def _menu() -> InlineKeyboardMarkup:
    return InlineKeyboardMarkup(inline_keyboard=[
        [InlineKeyboardButton(text="🤝 Обычная партнёрская (10%)",
                              callback_data="links_new_standard")],
        [InlineKeyboardButton(text="💼 Для вебмастера (50% / 20%)",
                              callback_data="links_new_webmaster")],
        [InlineKeyboardButton(text="📋 Кто подключился", callback_data="links_partners")],
        [InlineKeyboardButton(text="🔙 Админ-меню", callback_data="admin_menu")],
    ])


def _cancel() -> InlineKeyboardMarkup:
    return InlineKeyboardMarkup(inline_keyboard=[
        [InlineKeyboardButton(text="❌ Отмена", callback_data="links_menu")]
    ])


async def _bot_username(bot: Bot) -> str:
    from channel_deeplink import get_bot_username

    return await get_bot_username(bot) or "Neuro_HubAI_bot"


def _webmaster_partners(limit: int = 20) -> list:
    """Кто уже подключился по ссылкам для вебмастеров."""
    from database import get_connection

    try:
        conn = get_connection()
        try:
            with conn.cursor() as cursor:
                cursor.execute("""
                    SELECT partner_telegram_id, partner_username, tier, source, created_at
                    FROM partner_tiers
                    WHERE tier <> 'standard'
                    ORDER BY created_at DESC
                    LIMIT %s
                """, (limit,))
                return cursor.fetchall() or []
        finally:
            conn.close()
    except Exception as e:
        logger.error("[ссылки] не удалось прочитать партнёров: %s", e)
        return []


def setup_admin_links(dp: Dispatcher, bot: Bot) -> None:
    """Регистрирует раздел «Ссылки» в админке."""

    async def show_menu(target, edit: bool = False):
        text = (
            "🔗 <b>Генератор ссылок</b>\n\n"
            "<b>Обычная партнёрская</b> — 10% с каждой покупки приведённого "
            "пользователя. Метка сохраняется как тег кампании, по ней видно источник.\n\n"
            "<b>Для вебмастера</b> — 50% с первого депозита реферала и 20% со всех "
            "последующих. Тариф закрепляется за тем, кто перешёл по ссылке."
        )
        if edit:
            await target.message.edit_text(text, reply_markup=_menu(), parse_mode="HTML")
        else:
            await target.answer(text, reply_markup=_menu(), parse_mode="HTML")

    @dp.message(Command("links"))
    async def links_command(message: Message):
        user = message.from_user
        if not user or not is_admin(user.id, user.username):
            return
        await show_menu(message)

    @dp.callback_query(F.data == "links_menu")
    async def links_menu(callback: CallbackQuery, state: FSMContext):
        await state.clear()
        await show_menu(callback, edit=True)
        await callback.answer()

    @dp.callback_query(F.data.in_({"links_new_standard", "links_new_webmaster"}))
    async def links_new(callback: CallbackQuery, state: FSMContext):
        kind = "webmaster" if callback.data.endswith("webmaster") else "standard"
        await state.set_state(LinkStates.input_label)
        await state.update_data(link_kind=kind)

        example = "cpaclub" if kind == "webmaster" else "instagram_dec"
        conditions = ("50% с первого депозита и 20% далее" if kind == "webmaster"
                      else "10% с каждой покупки")
        await callback.message.edit_text(
            f"Условия ссылки: <b>{conditions}</b>\n\n"
            "Пришлите метку источника — по ней вы отличите этот трафик от другого.\n"
            f"Латиница, цифры, дефис и подчёркивание. Например: <code>{example}</code>",
            reply_markup=_cancel(), parse_mode="HTML",
        )
        await callback.answer()

    @dp.message(LinkStates.input_label)
    async def links_label(message: Message, state: FSMContext):
        user = message.from_user
        if not user or not is_admin(user.id, user.username):
            return

        label = (message.text or "").strip().lstrip("@")
        if not LABEL_RE.match(label):
            await message.answer(
                "Метка не подошла: нужны латиница, цифры, дефис или подчёркивание, "
                "от 2 до 50 символов. Пришлите другую.",
                reply_markup=_cancel(),
            )
            return

        data = await state.get_data()
        kind = data.get("link_kind", "standard")
        await state.clear()

        username = await _bot_username(bot)
        if kind == "webmaster":
            link = f"https://t.me/{username}?start={partner_tiers.WEBMASTER_PREFIX}{label}"
            text = (
                "💼 <b>Ссылка для вебмастера готова</b>\n\n"
                f"<code>{link}</code>\n\n"
                "Условия для перешедшего: <b>50% с первого депозита</b> приведённого "
                "пользователя и <b>20% со всех последующих</b> покупок.\n"
                f"Источник: <code>{label}</code> — по нему найдёте его в списке партнёров."
            )
        else:
            link = f"https://t.me/{username}?start={label}"
            text = (
                "🤝 <b>Партнёрская ссылка готова</b>\n\n"
                f"<code>{link}</code>\n\n"
                "Условия: <b>10% с каждой покупки</b> приведённого пользователя.\n"
                f"Метка кампании: <code>{label}</code>\n\n"
                "Чтобы метка учитывалась в статистике кампаний, добавьте её "
                "в разделе «Теги кампаний»."
            )

        await message.answer(text, reply_markup=_menu(), parse_mode="HTML")
        logger.info("[ссылки] админ %s создал ссылку %s (%s)", user.id, link, kind)

    @dp.callback_query(F.data == "links_partners")
    async def links_partners(callback: CallbackQuery):
        rows = _webmaster_partners()
        if not rows:
            text = ("📋 По ссылкам для вебмастеров пока никто не подключился.\n\n"
                    "Как только партнёр перейдёт по ссылке, он появится здесь.")
        else:
            lines = ["📋 <b>Партнёры на повышенном тарифе</b>", ""]
            for row in rows:
                name = row.get("partner_username") or row.get("partner_telegram_id")
                created = str(row.get("created_at") or "")[:16]
                lines.append(
                    f"• @{name} — источник <code>{row.get('source') or '—'}</code>, "
                    f"{created}"
                )
            text = "\n".join(lines)

        await callback.message.edit_text(text, reply_markup=_menu(), parse_mode="HTML")
        await callback.answer()

    logger.info("[админка] раздел ссылок доступен: /links")
