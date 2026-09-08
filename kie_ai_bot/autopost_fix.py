"""
Починка уже опубликованных постов: промпт в комментарий, чистая подпись.

Команда в боте (для администратора):

    /autopost_fix

Нужна для постов, которые вышли до появления надёжной отправки комментариев:
у одних промпт остался обрезанным в подписи, у других его нет вовсе, а в базе
не записан id поста в канале — поэтому фоновый воркер их не видит.

Что делает:
1. Берёт записи со статусом published, у которых промпт не отправлен
   комментарием, и id поста в канале не записан.
2. Сопоставляет их с реальными постами канала по времени публикации (Telethon).
3. Проставляет найденный id, чистит подпись до шапки, если в неё был вписан
   промпт, и отправляет промпт комментарием.
"""

import logging
from contextlib import closing
from datetime import datetime, timezone

from aiogram import Bot, Dispatcher
from aiogram.filters import Command
from aiogram.types import Message

import autopost
import telethon_source
from config import is_admin

logger = logging.getLogger("autopost.fix")

# Насколько время публикации записи может расходиться со временем поста, секунд
TIME_TOLERANCE = 180
# Сколько последних постов канала просматривать
CHANNEL_LOOKBACK = 100


def _rows_to_fix() -> list:
    with closing(autopost._connect()) as conn:
        return conn.execute(
            "SELECT * FROM autopost_posts WHERE status = 'published' "
            "AND prompt_posted = 0 AND prompt IS NOT NULL AND published_at IS NOT NULL "
            "ORDER BY published_at"
        ).fetchall()


async def _channel_posts(client) -> list:
    """Последние посты целевого канала: (id, время публикации)."""
    posts = []
    async for message in client.iter_messages(autopost.TARGET_CHAT_ID, limit=CHANNEL_LOOKBACK):
        if message.date:
            posts.append((message.id, message.date.astimezone(timezone.utc), message))
    return posts


def _match(published_at: str, posts: list):
    """Пост канала, ближайший по времени к моменту публикации записи."""
    try:
        moment = datetime.fromisoformat(published_at)
    except ValueError:
        return None
    if moment.tzinfo is None:
        moment = moment.replace(tzinfo=timezone.utc)

    best, best_delta = None, None
    for msg_id, date, message in posts:
        delta = abs((date - moment).total_seconds())
        if best_delta is None or delta < best_delta:
            best, best_delta = (msg_id, message), delta
    if best and best_delta is not None and best_delta <= TIME_TOLERANCE:
        return best
    return None


async def _clean_caption(bot: Bot, row, message) -> bool:
    """Возвращает подпись к чистой шапке, если в неё был вписан промпт."""
    header = autopost.build_caption(row["source_caption"])
    current = (getattr(message, "message", "") or "").strip()
    # В шапке пять строк; если текста заметно больше — в подписи сидит промпт
    if len(current) <= len(header.replace("<a href=\"", "").replace("</a>", "")) + 40:
        return False
    try:
        await autopost.with_retries(
            lambda: bot.edit_message_caption(
                chat_id=autopost.TARGET_CHAT_ID,
                message_id=row["channel_msg_id"],
                caption=header,
                parse_mode="HTML",
            ),
            what="очистка подписи",
        )
        return True
    except Exception as e:
        logger.warning("[autopost-fix] подпись поста %s не очищена: %s", row["channel_msg_id"], e)
        return False


async def run_fix(bot: Bot) -> list:
    client = await telethon_source.get_client()
    if not client:
        return [{"status": "error", "detail": "Telethon не настроен"}]

    rows = _rows_to_fix()
    if not rows:
        return []

    posts = await _channel_posts(client)
    report = []

    for row in rows:
        entry = {"id": row["id"]}

        channel_msg_id = row["channel_msg_id"]
        message = None
        if not channel_msg_id:
            found = _match(row["published_at"], posts)
            if not found:
                report.append({**entry, "status": "error",
                               "detail": "не нашёл пост канала по времени публикации"})
                continue
            channel_msg_id, message = found
            autopost._update(row["id"], channel_msg_id=channel_msg_id)
            row = dict(row)
            row["channel_msg_id"] = channel_msg_id

        entry["post"] = channel_msg_id

        if message is not None:
            cleaned = await _clean_caption(bot, row, message)
            entry["caption_cleaned"] = cleaned

        posted = await autopost._comment_with_prompt(bot, channel_msg_id, row["prompt"])
        autopost._update(row["id"], prompt_posted=1 if posted else 0)
        entry["status"] = "published" if posted else "error"
        if not posted:
            entry["detail"] = "комментарий не отправился"
        report.append(entry)

    return report


def setup_autopost_fix(dp: Dispatcher, bot: Bot) -> None:
    """Регистрирует админскую команду /autopost_fix."""
    if not autopost.ENABLED:
        return

    @dp.message(Command("autopost_fix"))
    async def autopost_fix_handler(message: Message):
        user = message.from_user
        if not user or not is_admin(user.id, user.username):
            return

        await message.answer("Ищу посты без промпта в комментариях...")
        try:
            report = await run_fix(bot)
        except Exception as e:
            logger.error("[autopost-fix] не удалось: %s", e, exc_info=True)
            await message.answer(f"❌ Не удалось: {e}")
            return

        if not report:
            await message.answer("Все опубликованные посты уже с промптом в комментариях.")
            return

        lines = ["<b>Починка постов</b>", ""]
        for item in report:
            icon = "✅" if item.get("status") == "published" else "❌"
            line = f"{icon} пост {item.get('post', '—')}"
            if item.get("caption_cleaned"):
                line += " (подпись очищена)"
            lines.append(line)
            if item.get("detail"):
                lines.append(f"   {item['detail']}")
        fixed = sum(1 for i in report if i.get("status") == "published")
        lines += ["", f"Исправлено: {fixed} из {len(report)}"]
        await message.answer("\n".join(lines), parse_mode="HTML")

    logger.info("[autopost] команда /autopost_fix доступна администраторам")
