"""
Пробный прогон автопостинга по команде администратора.

Команда в боте:

    /autopost_test          — по одному посту с каждого канала-источника
    /autopost_test 2        — по два поста с каждого канала

Берёт свежие посты, генерирует и публикует их сразу, минуя суточный лимит
и равномерное распределение, и присылает администратору отчёт по каждому
каналу. Нужен для проверки настройки: видны ли каналы, находятся ли промпты
в комментариях, доходит ли результат до целевого канала.

Результат прогон забирает сам, опросом Kie AI, не дожидаясь фонового воркера
и callback-а — так тест работает даже при закрытом снаружи порте 8010.
"""

import asyncio
import logging
from contextlib import closing

import httpx
from aiogram import Bot, Dispatcher
from aiogram.filters import Command
from aiogram.types import Message

import autopost
import telethon_source
from config import KIE_API_KEY, is_admin

logger = logging.getLogger("autopost.test")

# Сколько ждать готовности картинки от Kie AI, секунд
RESULT_WAIT = 420
POLL_STEP = 5


def _row(row_id: int):
    with closing(autopost._connect()) as conn:
        return conn.execute("SELECT * FROM autopost_posts WHERE id = ?", (row_id,)).fetchone()


async def _await_kie_result(task_id: str) -> tuple:
    """Опрашивает Kie AI до готовности. Возвращает (url, None) или (None, ошибка)."""
    headers = {"Authorization": f"Bearer {KIE_API_KEY}"}
    async with httpx.AsyncClient(timeout=60) as client:
        for _ in range(RESULT_WAIT // POLL_STEP):
            await asyncio.sleep(POLL_STEP)
            try:
                resp = await client.get(
                    autopost.KIE_RECORD_URL, params={"taskId": task_id}, headers=headers
                )
                task_data = (resp.json() or {}).get("data") or {}
            except Exception as e:
                logger.warning("[autopost-test] опрос %s не удался: %s", task_id, e)
                continue

            state = (task_data.get("state") or task_data.get("status") or "").lower()
            if state in ("success", "succeeded", "completed"):
                url = autopost._extract_result_url(task_data)
                return (url, None) if url else (None, "Kie вернул успех без ссылки")
            if state in ("fail", "failed", "error"):
                return None, (task_data.get("failMsg") or task_data.get("msg") or "генерация не удалась")
    return None, f"результат не пришёл за {RESULT_WAIT} секунд"


async def _process_one(bot: Bot, post: dict) -> dict:
    """Ставит пост в очередь, генерирует и публикует. Возвращает результат."""
    entry = {"channel": post["source_chat_id"], "post": post["source_msg_id"],
             "prompt_len": len(post["prompt"])}

    row_id = autopost.add_post(**post)
    if not row_id:
        existing = autopost.get_post(post["source_chat_id"], post["source_msg_id"])
        if existing and existing["status"] == "published":
            return {**entry, "status": "skipped", "detail": "пост уже публиковался"}
        row_id = existing["id"] if existing else None
    if not row_id:
        return {**entry, "status": "error", "detail": "не удалось поставить в очередь"}

    # Запись могла уже уйти в работу к фоновому воркеру — тогда не трогаем её,
    # иначе тот же пост опубликуется дважды
    if not autopost.claim_for_generation(row_id):
        current = _row(row_id)
        state = current["status"] if current else "?"
        return {**entry, "status": "skipped",
                "detail": f"пост уже в работе (статус {state})"}

    row = _row(row_id)
    if not await autopost.submit_to_kie(row):
        fresh = _row(row_id)
        return {**entry, "status": "error",
                "detail": (fresh["error"] if fresh else "не удалось отправить в Kie")}

    row = _row(row_id)
    result_url, error = await _await_kie_result(row["task_id"])
    if error:
        autopost._update(row_id, status="error", error=error)
        return {**entry, "status": "error", "detail": error}

    await autopost.publish(bot, row, result_url)

    fresh = _row(row_id)
    if fresh and fresh["status"] == "published":
        return {**entry, "status": "published", "detail": result_url}
    return {**entry, "status": "error",
            "detail": (fresh["error"] if fresh else "публикация не удалась")}


async def run_test(bot: Bot, per_channel: int = 1, progress=None) -> list:
    """Прогоняет по per_channel постов с каждого канала. Возвращает отчёт."""
    # Общий клиент на весь процесс: второй доступ к файлу сессии даёт
    # «database is locked», а отключать его нельзя — им пользуется воркер.
    client = await telethon_source.get_client()
    if not client:
        return [{"channel": None, "status": "error",
                 "detail": "Telethon не настроен: нет файла сессии или ключей"}]

    autopost.init_db()
    report = []
    for source_chat_id in autopost.SOURCE_CHAT_IDS:
        logger.info("[autopost-test] канал %s", source_chat_id)
        try:
            posts = await telethon_source.find_posts(
                client, source_chat_id, needed=per_channel
            )
        except Exception as e:
            report.append({"channel": source_chat_id, "status": "error",
                           "detail": f"канал недоступен: {e}"})
            continue

        if not posts:
            report.append({"channel": source_chat_id, "status": "skipped",
                           "detail": "нет постов с промптом в комментариях"})
            continue

        for post in posts:
            try:
                entry = await _process_one(bot, post)
            except Exception as e:
                logger.error("[autopost-test] пост %s: %s", post["source_msg_id"], e, exc_info=True)
                entry = {"channel": source_chat_id, "post": post["source_msg_id"],
                         "status": "error", "detail": str(e)}
            report.append(entry)
            if progress:
                await progress(entry)

    return report


async def _say(message: Message, text: str) -> None:
    """Сообщение админу: сетевой сбой не должен ронять весь прогон."""
    try:
        await autopost.with_retries(
            lambda: message.answer(text, parse_mode="HTML"), what="ответ администратору"
        )
    except Exception as e:
        logger.warning("[autopost-test] не удалось ответить администратору: %s", e)


def _format_report(report: list) -> str:
    icons = {"published": "✅", "skipped": "⏭", "error": "❌"}
    lines = ["<b>Пробный прогон автопостинга</b>", ""]
    for item in report:
        icon = icons.get(item.get("status"), "•")
        line = f"{icon} <code>{item.get('channel') or '—'}</code>"
        if item.get("post"):
            line += f" пост {item['post']}"
        if item.get("prompt_len"):
            line += f", промпт {item['prompt_len']} симв."
        lines.append(line)
        if item.get("status") != "published" and item.get("detail"):
            lines.append(f"   {str(item['detail'])[:200]}")
    published = sum(1 for i in report if i.get("status") == "published")
    lines += ["", f"Опубликовано: {published} из {len(report)}"]
    return "\n".join(lines)


_running = False


def setup_autopost_test(dp: Dispatcher, bot: Bot) -> None:
    """Регистрирует админскую команду /autopost_test."""
    if not autopost.ENABLED:
        return

    @dp.message(Command("autopost_test"))
    async def autopost_test_handler(message: Message):
        user = message.from_user
        logger.info(
            "[autopost-test] команда от %s (%s)",
            user.id if user else "?", user.username if user else "?",
        )
        if not user or not is_admin(user.id, user.username):
            logger.warning("[autopost-test] отказано: не администратор")
            return

        global _running
        if _running:
            await _say(message, "Прогон уже идёт — дождитесь отчёта.")
            return

        parts = (message.text or "").split()
        try:
            per_channel = max(1, min(3, int(parts[1]))) if len(parts) > 1 else 1
        except ValueError:
            per_channel = 1

        _running = True
        await _say(
            message,
            f"Запускаю пробный прогон: каналов {len(autopost.SOURCE_CHAT_IDS)}, "
            f"по {per_channel} посту с каждого.\n"
            "Буду присылать результат по мере готовности.",
        )

        async def progress(entry: dict):
            icon = {"published": "✅", "skipped": "⏭", "error": "❌"}.get(entry.get("status"), "•")
            text = f"{icon} канал <code>{entry.get('channel')}</code>"
            if entry.get("post"):
                text += f", пост {entry['post']}"
            if entry.get("status") != "published" and entry.get("detail"):
                text += f"\n{str(entry['detail'])[:200]}"
            await _say(message, text)

        try:
            report = await run_test(bot, per_channel, progress=progress)
        except Exception as e:
            logger.error("[autopost-test] прогон не удался: %s", e, exc_info=True)
            await _say(message, f"❌ Прогон не удался: {e}")
            return
        finally:
            _running = False

        await _say(message, _format_report(report))

    logger.info("[autopost] команда /autopost_test доступна администраторам")
