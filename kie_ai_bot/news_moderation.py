"""
Предпросмотр черновика новости перед публикацией.

Черновик пишет модель, а отвечает за канал человек. Поэтому при включённом
NEWS_WRITER_PREVIEW пост не уходит в группу сразу: он показывается
модераторам с кнопками «Опубликовать» и «Пропустить». Нажали «Опубликовать» —
пост выходит в том же оформлении, что и автоматический. Нажали «Пропустить» —
новость помечается как просмотренная и больше не всплывает.

Если никто не нажал ничего, пост не выходит: тихая публикация неодобренного
текста — худший из вариантов. Просроченные черновики (NEWS_DRAFT_TTL часов)
закрываются сами, чтобы не висеть в списке вечно.

Выключено по умолчанию: пока предпросмотр не включён, автопостинг работает
как раньше.
"""

import json
import logging
import os
from contextlib import closing
from datetime import datetime, timedelta, timezone
from typing import List, Optional

from aiogram import Bot, Dispatcher, F
from aiogram.types import CallbackQuery, InlineKeyboardButton, InlineKeyboardMarkup

import news_autopost

logger = logging.getLogger("news.moderation")


def _parse_ids(raw: str) -> List[int]:
    ids = []
    for chunk in (raw or "").replace(",", " ").split():
        try:
            ids.append(int(chunk))
        except ValueError:
            continue
    return ids


ENABLED = os.getenv("NEWS_WRITER_PREVIEW", "0").strip().lower() in ("1", "true", "yes", "on")
# Кому показывать. По умолчанию — те же люди, что утверждают фото-автопосты
MODERATOR_IDS = _parse_ids(os.getenv("NEWS_MODERATOR_IDS",
                                     os.getenv("AUTOPOST_MODERATOR_IDS", "")))
TTL_HOURS = int(os.getenv("NEWS_DRAFT_TTL", "12") or 12)


def enabled() -> bool:
    return ENABLED and bool(MODERATOR_IDS)


# --- Хранилище черновиков --------------------------------------------------

def init_db() -> None:
    with closing(news_autopost._connect()) as conn:
        conn.execute("""
            CREATE TABLE IF NOT EXISTS news_drafts (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                title      TEXT,
                source     TEXT,
                link       TEXT,
                image      TEXT,
                text       TEXT NOT NULL,
                status     TEXT NOT NULL DEFAULT 'pending',   -- pending | sent | skipped | expired
                created_at TEXT NOT NULL,
                decided_at TEXT
            )
        """)
        conn.commit()


def save_draft(item, text: str) -> int:
    init_db()
    now = datetime.now(timezone.utc).isoformat(timespec="seconds")
    with closing(news_autopost._connect()) as conn:
        cur = conn.execute(
            "INSERT INTO news_drafts (title, source, link, image, text, created_at)"
            " VALUES (?, ?, ?, ?, ?, ?)",
            (item.title, item.source, item.link, item.image or "", text, now),
        )
        conn.commit()
        return int(cur.lastrowid)


def get_draft(draft_id: int) -> Optional[dict]:
    with closing(news_autopost._connect()) as conn:
        row = conn.execute("SELECT * FROM news_drafts WHERE id = ?", (draft_id,)).fetchone()
    return dict(row) if row else None


def set_status(draft_id: int, status: str) -> None:
    now = datetime.now(timezone.utc).isoformat(timespec="seconds")
    with closing(news_autopost._connect()) as conn:
        conn.execute("UPDATE news_drafts SET status = ?, decided_at = ? WHERE id = ?",
                     (status, now, draft_id))
        conn.commit()


def expire_old() -> int:
    """Закрывает черновики, которые никто не посмотрел. Возвращает их число."""
    # Таблицы может ещё не быть: чистка вызывается раньше первой выдачи.
    init_db()
    edge = (datetime.now(timezone.utc) - timedelta(hours=TTL_HOURS)).isoformat(timespec="seconds")
    with closing(news_autopost._connect()) as conn:
        cur = conn.execute(
            "UPDATE news_drafts SET status = 'expired' WHERE status = 'pending' AND created_at < ?",
            (edge,),
        )
        conn.commit()
        return cur.rowcount or 0


# --- Показ модераторам -----------------------------------------------------

def _keyboard(draft_id: int) -> InlineKeyboardMarkup:
    return InlineKeyboardMarkup(inline_keyboard=[[
        InlineKeyboardButton(text="✅ Опубликовать", callback_data=f"ndraft_ok_{draft_id}"),
        InlineKeyboardButton(text="🚫 Пропустить", callback_data=f"ndraft_no_{draft_id}"),
    ]])


async def send_for_review(bot: Bot, item, text: str) -> bool:
    """Показывает черновик модераторам. True — хотя бы один получил."""
    draft_id = save_draft(item, text)
    head = (f"📝 <b>Черновик новости №{draft_id}</b>\n"
            f"<i>Источник: {item.source or '—'}</i>\n\n")
    delivered = 0
    for user_id in MODERATOR_IDS:
        try:
            await bot.send_message(user_id, head + text, parse_mode="HTML",
                                   reply_markup=_keyboard(draft_id),
                                   disable_web_page_preview=True)
            delivered += 1
        except Exception as e:
            logger.warning("[черновики] %s не получил черновик: %s", user_id, e)
    if not delivered:
        set_status(draft_id, "skipped")
        return False
    logger.info("[черновики] №%s ушёл на проверку (%s получателей)", draft_id, delivered)
    return True


# --- Кнопки ----------------------------------------------------------------

class _Item:
    """Минимальный вид материала — publish_item берёт из него только поля."""

    def __init__(self, row: dict):
        self.title = row.get("title") or ""
        self.source = row.get("source") or ""
        self.link = row.get("link") or ""
        self.image = row.get("image") or ""
        self.summary = ""
        self.published = None


def setup(dp: Dispatcher, bot: Bot) -> None:
    @dp.callback_query(F.data.startswith("ndraft_"))
    async def decide(callback: CallbackQuery) -> None:
        data = callback.data or ""
        if callback.from_user.id not in MODERATOR_IDS:
            await callback.answer("Это не ваш черновик", show_alert=True)
            return

        try:
            action, raw_id = data[len("ndraft_"):].split("_", 1)
            draft_id = int(raw_id)
        except ValueError:
            await callback.answer("Не разобрал кнопку")
            return

        row = get_draft(draft_id)
        if not row:
            await callback.answer("Черновик не найден")
            return
        if row["status"] != "pending":
            await callback.answer(f"Уже решено: {row['status']}")
            return

        if action == "no":
            set_status(draft_id, "skipped")
            # Помечаем новость просмотренной, иначе она вернётся в следующий час
            news_autopost.remember("skip", url=row["link"], title=row["title"],
                                   source=row["source"])
            await callback.answer("Пропустили")
            await _strip_buttons(callback, "🚫 Пропущено")
            return

        ok = await news_autopost.publish_news(bot, _Item(row), draft_text=row["text"])
        set_status(draft_id, "sent" if ok else "pending")
        await callback.answer("Опубликовано" if ok else "Не отправилось, попробуйте ещё раз")
        if ok:
            await _strip_buttons(callback, "✅ Опубликовано")

    async def _strip_buttons(callback: CallbackQuery, verdict: str) -> None:
        try:
            await callback.message.edit_reply_markup(reply_markup=None)
            await callback.message.reply(verdict)
        except Exception as e:
            logger.debug("[черновики] кнопки не убрались: %s", e)

    logger.info("[черновики] предпросмотр включён, получателей: %s", len(MODERATOR_IDS))
