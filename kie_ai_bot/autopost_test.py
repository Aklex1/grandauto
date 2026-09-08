"""
Пробный прогон автопостинга по команде администратора.

Команда в боте:

    /autopost_test          — по одному посту с каждого канала-источника
    /autopost_test 2        — по два поста с каждого канала

Берёт свежие посты, генерирует и публикует их сразу, минуя суточный лимит,
и присылает администратору отчёт по каждому каналу. Нужен для проверки
настройки: видны ли каналы, находятся ли промпты в комментариях, доходит ли
результат до целевого канала.
"""

import asyncio
import logging

from aiogram import Bot, Dispatcher
from aiogram.filters import Command
from aiogram.types import Message

import autopost
import telethon_source
from config import is_admin

logger = logging.getLogger("autopost.test")

# Сколько ждать готовности картинки от Kie AI, секунд
RESULT_WAIT = 300
POLL_STEP = 5


async def _wait_result(row_id: int) -> tuple:
    """Ждёт, пока запись перейдёт в published или error."""
    from contextlib import closing

    for _ in range(RESULT_WAIT // POLL_STEP):
        await asyncio.sleep(POLL_STEP)
        with closing(autopost._connect()) as conn:
            row = conn.execute(
                "SELECT status, error, result_url FROM autopost_posts WHERE id = ?", (row_id,)
            ).fetchone()
        if not row:
            return "error", "запись пропала из очереди"
        if row["status"] == "published":
            return "published", row["result_url"]
        if row["status"] == "error":
            return "error", row["error"]
    return "error", f"результат не пришёл за {RESULT_WAIT} секунд"


async def run_test(bot: Bot, per_channel: int = 1) -> list:
    """Прогоняет по per_channel постов с каждого канала. Возвращает отчёт."""
    report = []
    client = await telethon_source.make_client()
    if not client:
        return [{"channel": None, "status": "error",
                 "detail": "Telethon не настроен: нет сессии или ключей"}]

    autopost.init_db()
    try:
        for source_chat_id in autopost.SOURCE_CHAT_IDS:
            entry = {"channel": source_chat_id}
            try:
                posts = await telethon_source.find_posts(client, source_chat_id, needed=per_channel)
            except Exception as e:
                entry.update(status="error", detail=f"канал недоступен: {e}")
                report.append(entry)
                continue

            if not posts:
                entry.update(status="skipped", detail="нет постов с промптом в комментариях")
                report.append(entry)
                continue

            for post in posts:
                row_id = autopost.add_post(**post)
                if not row_id:
                    existing = autopost.get_post(post["source_chat_id"], post["source_msg_id"])
                    row_id = existing["id"] if existing else None
                if not row_id:
                    report.append({**entry, "status": "skipped", "detail": "пост уже обработан"})
                    continue

                from contextlib import closing
                with closing(autopost._connect()) as conn:
                    row = conn.execute(
                        "SELECT * FROM autopost_posts WHERE id = ?", (row_id,)
                    ).fetchone()

                if not await autopost.submit_to_kie(row):
                    with closing(autopost._connect()) as conn:
                        err = conn.execute(
                            "SELECT error FROM autopost_posts WHERE id = ?", (row_id,)
                        ).fetchone()
                    report.append({**entry, "status": "error",
                                   "detail": (err["error"] if err else "не удалось отправить в Kie")})
                    continue

                status, detail = await _wait_result(row_id)
                report.append({**entry, "status": status, "detail": detail,
                               "post": post["source_msg_id"],
                               "prompt_len": len(post["prompt"])})
    finally:
        await client.disconnect()

    return report


def _format_report(report: list) -> str:
    icons = {"published": "✅", "skipped": "⏭", "error": "❌"}
    lines = ["<b>Пробный прогон автопостинга</b>", ""]
    for item in report:
        icon = icons.get(item.get("status"), "•")
        channel = item.get("channel") or "—"
        line = f"{icon} <code>{channel}</code>"
        if item.get("post"):
            line += f" пост {item['post']}"
        if item.get("prompt_len"):
            line += f", промпт {item['prompt_len']} симв."
        lines.append(line)
        detail = str(item.get("detail") or "")
        if item.get("status") != "published" and detail:
            lines.append(f"   {detail[:200]}")
    published = sum(1 for i in report if i.get("status") == "published")
    lines += ["", f"Опубликовано: {published} из {len(report)}"]
    return "\n".join(lines)


def setup_autopost_test(dp: Dispatcher, bot: Bot) -> None:
    """Регистрирует админскую команду /autopost_test."""
    if not autopost.ENABLED:
        return

    @dp.message(Command("autopost_test"))
    async def autopost_test_handler(message: Message):
        user = message.from_user
        if not user or not is_admin(user.id, user.username):
            return

        parts = (message.text or "").split()
        try:
            per_channel = max(1, min(3, int(parts[1]))) if len(parts) > 1 else 1
        except ValueError:
            per_channel = 1

        await message.answer(
            f"Запускаю пробный прогон: {len(autopost.SOURCE_CHAT_IDS)} каналов, "
            f"по {per_channel} посту с каждого.\nЭто займёт несколько минут."
        )
        try:
            report = await run_test(bot, per_channel)
        except Exception as e:
            logger.error("[autopost-test] прогон не удался: %s", e, exc_info=True)
            await message.answer(f"❌ Прогон не удался: {e}")
            return

        await message.answer(_format_report(report), parse_mode="HTML")

    logger.info("[autopost] команда /autopost_test доступна администраторам")
