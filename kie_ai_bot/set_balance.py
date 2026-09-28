#!/usr/bin/env python3
"""
Скрипт для установки баланса пользователя
Использование: python set_balance.py <email> <amount>
Пример: python set_balance.py akklex9@gmail.com 400
"""

import sys
from database import get_user_by_email, get_connection

if __name__ == "__main__":
    if len(sys.argv) < 3:
        print("Использование: python set_balance.py <email> <amount>")
        print("Пример: python set_balance.py akklex9@gmail.com 400")
        sys.exit(1)
    
    email = sys.argv[1]
    try:
        amount = float(sys.argv[2])
    except ValueError:
        print(f"Ошибка: '{sys.argv[2]}' не является числом")
        sys.exit(1)
    
    print(f"Установка баланса для пользователя: {email}")
    print(f"Сумма: {amount} токенов")
    print("=" * 50)
    
    # Получаем пользователя
    user = get_user_by_email(email)
    if not user:
        print(f"❌ Пользователь с email {email} не найден в базе данных")
        sys.exit(1)
    
    user_id = user.get('id')
    current_balance = user.get('balance')
    
    print(f"✅ Пользователь найден:")
    print(f"   ID: {user_id}")
    print(f"   Email: {email}")
    print(f"   Текущий баланс: {current_balance}")
    print(f"   Новый баланс: {amount}")
    
    # Подтверждение
    response = input("\nПродолжить? (yes/no): ")
    if response.lower() not in ['yes', 'y', 'да', 'д']:
        print("Отменено.")
        sys.exit(0)
    
    # Устанавливаем баланс
    conn = get_connection()
    try:
        cursor = conn.cursor()
        cursor.execute(
            "UPDATE users SET balance = %s WHERE id = %s",
            (amount, user_id)
        )
        conn.commit()
        print(f"\n✅ Баланс успешно установлен!")
        print(f"   Пользователь ID: {user_id}")
        print(f"   Email: {email}")
        print(f"   Баланс: {amount} токенов")
    except Exception as e:
        conn.rollback()
        print(f"\n❌ Ошибка при обновлении баланса: {e}")
        sys.exit(1)
    finally:
        conn.close()
