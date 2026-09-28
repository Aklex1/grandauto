#!/usr/bin/env bash
# Состояние очереди автопостинга: что в работе, что мешает, когда следующий пост.
#   bash deploy/queue.sh
APP_DIR="${KIE_APP_DIR:-/opt/kie_ai_bot}"
cd "${APP_DIR}" || exit 1

"${APP_DIR}/venv/bin/python" - <<'PY'
import os
from contextlib import closing
from datetime import datetime, timezone

import autopost
import telethon_source

print("=" * 60)
print("ОЧЕРЕДЬ АВТОПОСТИНГА")
print("=" * 60)

autopost.init_db()

with closing(autopost._connect()) as conn:
    rows = conn.execute(
        "SELECT status, COUNT(*) AS n FROM autopost_posts GROUP BY status"
    ).fetchall()
    total = conn.execute("SELECT COUNT(*) AS n FROM autopost_posts").fetchone()["n"]

if rows:
    print("\nПо статусам:")
    for r in rows:
        print(f"  {r['status']:<12} {r['n']}")
else:
    print("\nОчередь пуста — ни одного поста не взято в работу.")
print(f"  {'всего':<12} {total}")

sent = autopost._sent_today()
print(f"\nСуточный лимит: {autopost.DAILY_LIMIT} постов, уже отправлено сегодня: {sent}")
print(f"Свободных слотов в очереди: {autopost.free_slots()}")

elapsed = autopost._seconds_since_last_send()
interval = autopost.publish_interval()
if elapsed is None:
    print("Публикаций ещё не было — следующая уйдёт при первом же готовом посте")
else:
    left = interval - elapsed
    print(f"С последней отправки: {elapsed / 60:.0f} мин, интервал: {interval / 60:.0f} мин")
    print("Следующая публикация: " + ("готова к отправке" if left <= 0
                                      else f"через {left / 60:.0f} мин"))

print("\nКвоты по каналам (взято сегодня / лимит):")
for chat_id in autopost.SOURCE_CHAT_IDS:
    taken = autopost.taken_today(chat_id)
    print(f"  {chat_id:>16}  {taken} / {autopost.PER_CHANNEL_DAILY}")

with closing(autopost._connect()) as conn:
    recent = conn.execute(
        "SELECT id, source_chat_id, source_msg_id, status, error, created_at "
        "FROM autopost_posts ORDER BY id DESC LIMIT 10"
    ).fetchall()
    errors = conn.execute(
        "SELECT id, error FROM autopost_posts WHERE error IS NOT NULL "
        "ORDER BY id DESC LIMIT 5"
    ).fetchall()

if recent:
    print("\nПоследние записи:")
    for r in recent:
        print(f"  #{r['id']:<4} канал {r['source_chat_id']} пост {r['source_msg_id']} "
              f"— {r['status']}  {str(r['created_at'])[:16]}")

if errors:
    print("\nПоследние ошибки:")
    for r in errors:
        print(f"  #{r['id']}: {str(r['error'])[:120]}")

print("\nФильтры отбора:")
print(f"  стоп-слов рекламы: {len(telethon_source.AD_STOP_WORDS)}")
print(f"  отсев картинок с текстом: {'да' if telethon_source.SKIP_IMAGES_WITH_TEXT else 'нет'}"
      f", порог {telethon_source.IMAGE_TEXT_MIN_CHARS} символов")
print(f"  OCR готов: {'да' if telethon_source._ocr_available() else 'НЕТ'}")
print(f"  глубина обхода канала: {telethon_source.MAX_LOOKBACK} постов")
print(f"  опрос каналов раз в {telethon_source.POLL_INTERVAL // 60} мин")

print("\nПодсказки:")
if not rows:
    print("  • Очередь пуста: посты не проходят отбор. Смотрите в логе строки")
    print("    «промпта в комментариях нет», «рекламный», «на фото надпись»:")
    print("    journalctl -u kie-bot -n 300 --no-pager | grep -i telethon | tail -30")
if telethon_source.IMAGE_TEXT_MIN_CHARS <= 4:
    print("  • Порог текста на картинке очень строгий: водяные знаки и подписи")
    print("    каналов тоже считаются надписью. Если отсеивается слишком много,")
    print("    поднимите AUTOPOST_IMAGE_TEXT_MIN_CHARS до 15-20.")
PY
