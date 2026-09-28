#!/usr/bin/env python3
"""
Скрипт для тестирования отправки email
Использование: python test_email.py <email>
Пример: python test_email.py test@example.com
"""

import sys
import os

# Добавляем текущую директорию в путь для импорта
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

from email_service import send_welcome_email, send_password_reset_email
import logging

# Настройка логирования
logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(name)s - %(levelname)s - %(message)s'
)

if __name__ == "__main__":
    if len(sys.argv) < 2:
        print("Использование: python test_email.py <email> [test_type]")
        print("  test_type: welcome (по умолчанию) или reset")
        print("Примеры:")
        print("  python test_email.py test@example.com")
        print("  python test_email.py test@example.com welcome")
        print("  python test_email.py test@example.com reset")
        sys.exit(1)
    
    email = sys.argv[1].strip().lower()
    test_type = sys.argv[2].lower() if len(sys.argv) > 2 else "welcome"
    
    if "@" not in email:
        print(f"❌ Некорректный email: {email}")
        sys.exit(1)
    
    print("=" * 60)
    print("Тестирование отправки email")
    print("=" * 60)
    print(f"Email получателя: {email}")
    print(f"Тип теста: {test_type}")
    print("=" * 60)
    
    try:
        if test_type == "welcome":
            print("\n📧 Отправка приветственного письма...")
            success = send_welcome_email(email, "Тестовый пользователь")
            if success:
                print("✅ Приветственное письмо успешно отправлено!")
                print(f"   Проверьте почту: {email}")
            else:
                print("❌ Ошибка отправки приветственного письма")
                print("   Проверьте логи выше для деталей")
                sys.exit(1)
        
        elif test_type == "reset":
            print("\n📧 Отправка письма для восстановления пароля...")
            test_token = "test-reset-token-12345"
            success = send_password_reset_email(email, test_token)
            if success:
                print("✅ Письмо для восстановления пароля успешно отправлено!")
                print(f"   Проверьте почту: {email}")
                print(f"   Тестовый токен: {test_token}")
            else:
                print("❌ Ошибка отправки письма для восстановления пароля")
                print("   Проверьте логи выше для деталей")
                sys.exit(1)
        
        else:
            print(f"❌ Неизвестный тип теста: {test_type}")
            print("   Используйте: welcome или reset")
            sys.exit(1)
        
        print("\n" + "=" * 60)
        print("✅ Тест завершен успешно!")
        print("=" * 60)
        
    except Exception as e:
        print(f"\n❌ Критическая ошибка: {e}")
        import traceback
        traceback.print_exc()
        sys.exit(1)
