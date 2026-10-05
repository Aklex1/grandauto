#!/usr/bin/env python3
"""
Разбор пополнения, которое не дошло до баланса.

Нужен для случая, когда деньги в кошельке есть, а баланса нет: приёмник
уведомлений лежал, уведомление ЮMoney не дошло, платёж остался pending.
Повторить само уведомление в таком случае уже нечем — подписанный пакет
не сохранился, — поэтому платёж закрывается здесь, ровно так же, как это
сделал бы приёмник: токены из самого платежа, статус completed, человеку
сообщение в Телеграм.

Без флагов ничего не меняет — только показывает, что есть:

    venv/bin/python pay_fix.py 244019461

Закрыть висящие платежи и начислить их токены:

    venv/bin/python pay_fix.py 244019461 --close-pending --yes

Только два последних из висящих (остальные не трогать):

    venv/bin/python pay_fix.py 244019461 --close-pending --last 2 --yes

Или поимённо, по метке из таблицы выше:

    venv/bin/python pay_fix.py 244019461 --label topup_244019461_1791084834 --yes

Начислить сумму руками (когда платежа в базе нет вовсе):

    venv/bin/python pay_fix.py 244019461 --add 500 --reason "два платежа 04.10 по 250" --yes
"""

import argparse
import sys
from datetime import datetime

from database import get_connection

LOG_FILE = "/opt/kie_ai_bot/manual_topup.log"


def write_log(line):
    try:
        with open(LOG_FILE, "a", encoding="utf-8") as fh:
            fh.write("%s  %s\n" % (datetime.now().strftime("%Y-%m-%d %H:%M:%S"), line))
    except Exception as e:
        print("не смог записать в журнал %s: %s" % (LOG_FILE, e))


def balance_of(cursor, telegram_id):
    cursor.execute("SELECT balance FROM users WHERE telegram_id = %s", (telegram_id,))
    row = cursor.fetchone()
    return None if not row else float(row["balance"])


def notify(telegram_id, text):
    """Сообщить человеку — тем же текстом, что присылает приёмник."""
    try:
        import requests
        from config import TELEGRAM_BOT_TOKEN
        r = requests.post(
            "https://api.telegram.org/bot%s/sendMessage" % TELEGRAM_BOT_TOKEN,
            data={"chat_id": telegram_id, "text": text},
            timeout=20,
        )
        print("   сообщение в Телеграм: %s" % ("отправлено" if r.ok else r.text[:200]))
    except Exception as e:
        print("   сообщение в Телеграм не ушло: %s" % e)


def main():
    p = argparse.ArgumentParser(description="Разбор не дошедшего пополнения")
    p.add_argument("telegram_id", type=int)
    p.add_argument("--close-pending", action="store_true",
                   help="закрыть висящие платежи и начислить их токены")
    p.add_argument("--last", type=int, default=0, metavar="N",
                   help="из висящих взять только N последних по времени")
    p.add_argument("--label", action="append", default=[], metavar="МЕТКА",
                   help="закрыть именно этот платёж; можно указать несколько раз")
    p.add_argument("--add", type=float, default=0.0,
                   help="начислить столько токенов, не привязываясь к платежу")
    p.add_argument("--reason", default="", help="зачем начислено — пишется в журнал")
    p.add_argument("--yes", action="store_true", help="подтвердить запись")
    args = p.parse_args()

    if args.add and (args.close_pending or args.label):
        print("Выберите одно: либо закрытие платежей, либо --add")
        sys.exit(1)
    if args.last and not args.close_pending:
        print("--last работает вместе с --close-pending")
        sys.exit(1)
    if args.last < 0:
        print("--last не может быть отрицательным")
        sys.exit(1)
    if args.add and not args.reason:
        print("К --add нужна --reason: через месяц по одной сумме уже не вспомнить, за что она")
        sys.exit(1)

    conn = get_connection()
    cursor = conn.cursor()

    before = balance_of(cursor, args.telegram_id)
    if before is None:
        print("Человека с telegram_id=%s нет в базе бота." % args.telegram_id)
        print("Пусть зайдёт в бота — аккаунт создастся, тогда и начислим.")
        conn.close()
        sys.exit(1)

    print("telegram_id %s, баланс сейчас: %.2f" % (args.telegram_id, before))

    cursor.execute(
        """SELECT label, amount, tokens, status, created_at
             FROM payments WHERE telegram_id = %s
         ORDER BY created_at DESC LIMIT 20""", (args.telegram_id,))
    rows = cursor.fetchall()

    print("\nплатежи (последние %d):" % len(rows))
    for r in rows:
        print("  %s  %-34s %8.2f ₽  %6.2f токенов  %s"
              % (r["created_at"], r["label"], float(r["amount"]),
                 float(r["tokens"]), r["status"]))

    pending = [r for r in rows if r["status"] == "pending"]

    if not args.close_pending and not args.add and not args.label:
        print("\nВисит незакрытых: %d на %.2f токенов." %
              (len(pending), sum(float(r["tokens"]) for r in pending)))
        print("Ничего не менял. Как закрыть:")
        print("  все висящие:        --close-pending --yes")
        print("  два последних:      --close-pending --last 2 --yes")
        print("  конкретный платёж:  --label <метка> --yes")
        conn.close()
        return

    if args.label:
        # Поимённо: берём только названные метки, и только те, что висят.
        by_label = {r["label"]: r for r in rows}
        chosen = []
        for label in args.label:
            row = by_label.get(label)
            if not row:
                print("\nПлатежа %s у этого человека нет — проверьте метку." % label)
                conn.close()
                sys.exit(1)
            if row["status"] != "pending":
                print("\nПлатёж %s уже закрыт (%s) — пропускаю." % (label, row["status"]))
                continue
            chosen.append(row)
        if not chosen:
            print("\nЗакрывать нечего.")
            conn.close()
            return
    elif args.close_pending:
        if not pending:
            print("\nНезакрытых платежей нет — закрывать нечего.")
            conn.close()
            return
        # Платежи уже отсортированы по времени, свежие сверху.
        chosen = pending[:args.last] if args.last else pending
        if args.last and len(pending) > args.last:
            print("\nВисит %d, беру %d последних — остальные не трогаю."
                  % (len(pending), len(chosen)))
    else:
        chosen = []

    plan = ([("платёж %s" % r["label"], float(r["tokens"]), r["label"]) for r in chosen]
            if chosen else [(args.reason, args.add, None)])
    total = sum(x[1] for x in plan)

    print("\nНачислю %.2f токенов, баланс станет %.2f:" % (total, before + total))
    for what, tokens, _ in plan:
        print("  +%.2f — %s" % (tokens, what))

    if not args.yes:
        print("\nЭто была примерка. Повторите с --yes, чтобы записать.")
        conn.close()
        return

    try:
        added = 0.0
        for what, tokens, label in plan:
            if label:
                # Сначала закрываем платёж, и только если это удалось —
                # добавляем деньги. Платёж, который кто-то закрыл секунду
                # назад, уже не pending, строк не затронется, и второго
                # начисления не будет. Так повторный запуск безопасен.
                cursor.execute(
                    "UPDATE payments SET status='completed' WHERE label=%s AND status='pending'",
                    (label,))
                if cursor.rowcount != 1:
                    print("   %s уже закрыт — пропускаю" % label)
                    continue
            cursor.execute("UPDATE users SET balance = balance + %s WHERE telegram_id = %s",
                           (tokens, args.telegram_id))
            added += tokens
            write_log("telegram_id=%s +%.2f токенов — %s" % (args.telegram_id, tokens, what))
        conn.commit()

        if added == 0:
            print("\nНачислять оказалось нечего — баланс не менялся.")
            conn.close()
            return
    except Exception as e:
        conn.rollback()
        print("\nНе записалось, ничего не изменено: %s" % e)
        conn.close()
        sys.exit(1)

    after = balance_of(cursor, args.telegram_id)
    print("\nГотово: %.2f → %.2f токенов." % (before, after))
    conn.close()

    notify(args.telegram_id, "✅ Ваш баланс был пополнен на %g токенов." % added)
    print("Журнал начислений: %s" % LOG_FILE)


if __name__ == "__main__":
    main()
