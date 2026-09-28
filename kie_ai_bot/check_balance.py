#!/usr/bin/env python3
"""
Скрипт для проверки баланса пользователя в базе данных
Использование: python check_balance.py <user_id>
"""

import sys
from database import get_user_by_id, get_balance_by_user_id

if __name__ == "__main__":
    if len(sys.argv) < 2:
        print("Использование: python check_balance.py <user_id>")
        sys.exit(1)
    
    try:
        user_id = int(sys.argv[1])
    except ValueError:
        print(f"Ошибка: '{sys.argv[1]}' не является числом")
        sys.exit(1)
    
    print(f"Проверка баланса для user_id={user_id}")
    print("=" * 50)
    
    # Получаем пользователя
    user = get_user_by_id(user_id)
    if not user:
        print(f"❌ Пользователь с ID {user_id} не найден в базе данных")
        sys.exit(1)
    
    print(f"✅ Пользователь найден:")
    print(f"   ID: {user.get('id')}")
    print(f"   Email: {user.get('email')}")
    print(f"   VK User ID: {user.get('vk_user_id')}")
    print(f"   Device ID: {user.get('device_id')}")
    print(f"   Telegram ID: {user.get('telegram_id')}")
    print(f"   Username: {user.get('username')}")
    
    # Получаем баланс
    balance_raw = user.get('balance')
    print(f"\nБаланс из БД:")
    print(f"   Значение: {balance_raw}")
    print(f"   Тип: {type(balance_raw)}")
    
    balance_float = get_balance_by_user_id(user_id)
    print(f"   Преобразованное значение: {balance_float}")
    print(f"   Тип преобразованного: {type(balance_float)}")
    
    if balance_float == 0.0:
        print(f"\n⚠️  ВНИМАНИЕ: Баланс равен 0!")
        print(f"   Если вы пополняли баланс, проверьте:")
        print(f"   1. Что платеж был успешно обработан webhook")
        print(f"   2. Что в таблице payments есть запись с user_id={user_id}")
        print(f"   3. Что статус платежа = 'completed'")
    else:
        print(f"\n✅ Баланс: {balance_float} токенов ({balance_float} рублей)")
