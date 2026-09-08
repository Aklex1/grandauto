import pymysql
from datetime import datetime, date
from typing import Any, Dict, Optional, Tuple, List
from contextlib import contextmanager
from config import (
    DB_HOST, DB_NAME, DB_USER, DB_PASSWORD, DB_PORT,
    DB_POOL_MIN_SIZE, DB_POOL_MAX_SIZE
)

# Глобальный пул соединений
_connection_pool = None


def init_connection_pool():
    """
    Инициализация пула соединений
    
    Примечание: pymysql не имеет встроенного ConnectionPool.
    Для полноценного connection pooling рекомендуется использовать:
    - SQLAlchemy с pool
    - pymysql-pooling библиотеку
    - Или создать свой простой pool на основе queue
    
    Сейчас используется обычное соединение с правильным закрытием через context manager.
    """
    global _connection_pool
    # Пока используем None - соединения создаются по требованию
    # В будущем можно добавить реальный connection pool
    _connection_pool = None
    return _connection_pool


def get_connection():
    """Получение соединения с БД (с поддержкой connection pooling)"""
    pool = init_connection_pool()
    if pool:
        return pool.get_connection()
    else:
        # Fallback на обычное соединение если pooling не доступен
        return pymysql.connect(
            host=DB_HOST,
            user=DB_USER,
            password=DB_PASSWORD,
            database=DB_NAME,
            port=DB_PORT,
            charset="utf8mb4",
            cursorclass=pymysql.cursors.DictCursor
        )


@contextmanager
def get_connection_context():
    """Контекстный менеджер для работы с соединением (автоматически закрывает)"""
    pool = init_connection_pool()
    conn = None
    try:
        if pool:
            conn = pool.get_connection()
            yield conn
        else:
            conn = pymysql.connect(
                host=DB_HOST,
                user=DB_USER,
                password=DB_PASSWORD,
                database=DB_NAME,
                port=DB_PORT,
                charset="utf8mb4",
                cursorclass=pymysql.cursors.DictCursor
            )
            yield conn
    finally:
        if conn:
            if pool:
                pool.release_connection(conn)
            else:
                conn.close()


def create_tables():
    """Создание таблицы пользователей"""
    conn = get_connection()
    cursor = conn.cursor()
    cursor.execute("""
        CREATE TABLE IF NOT EXISTS users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            telegram_id BIGINT UNIQUE,
            username VARCHAR(255),
            balance DECIMAL(10,2) DEFAULT 0,
            registration_date DATETIME,
            tag VARCHAR(50) DEFAULT NULL,
            is_blocked BOOLEAN DEFAULT FALSE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    """)
    # Добавляем поле tag если его нет (для существующих таблиц)
    try:
        cursor.execute("ALTER TABLE users ADD COLUMN tag VARCHAR(50) DEFAULT NULL")
    except Exception:
        pass  # Поле уже существует
    try:
        cursor.execute("ALTER TABLE users ADD COLUMN is_blocked BOOLEAN DEFAULT FALSE")
    except Exception:
        pass  # Поле уже существует
    try:
        cursor.execute("ALTER TABLE users ADD COLUMN vk_user_id VARCHAR(64) DEFAULT NULL UNIQUE")
    except Exception:
        pass  # Поле уже существует
    try:
        cursor.execute("ALTER TABLE users ADD COLUMN app_display_name VARCHAR(255) DEFAULT NULL")
    except Exception:
        pass  # Поле уже существует
    try:
        cursor.execute("ALTER TABLE users ADD COLUMN referral_code VARCHAR(24) DEFAULT NULL")
    except Exception:
        pass
    try:
        cursor.execute("ALTER TABLE users ADD COLUMN referred_by_vk_id VARCHAR(64) DEFAULT NULL")
    except Exception:
        pass
    try:
        cursor.execute("ALTER TABLE users ADD COLUMN device_id VARCHAR(64) DEFAULT NULL UNIQUE")
    except Exception:
        pass
    try:
        cursor.execute("ALTER TABLE users ADD COLUMN email VARCHAR(255) DEFAULT NULL UNIQUE")
    except Exception:
        pass
    try:
        cursor.execute("ALTER TABLE users ADD COLUMN password_hash VARCHAR(255) DEFAULT NULL")
    except Exception:
        pass

    # Партнёрская программа для приложения (по VK ID)
    cursor.execute("""
        CREATE TABLE IF NOT EXISTS app_partner_program (
            id INT AUTO_INCREMENT PRIMARY KEY,
            partner_vk_id VARCHAR(64) NOT NULL,
            referral_vk_id VARCHAR(64) NOT NULL,
            total_purchases DECIMAL(10,2) DEFAULT 0.00,
            partner_commission DECIMAL(10,2) DEFAULT 0.00,
            commission_rate DECIMAL(5,2) DEFAULT 10.00,
            first_payment_received BOOLEAN DEFAULT FALSE,
            status ENUM('active', 'inactive') DEFAULT 'active',
            referral_date DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_partner_referral (partner_vk_id, referral_vk_id),
            INDEX idx_partner_vk (partner_vk_id),
            INDEX idx_referral_vk (referral_vk_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    """)

    # Задачи генерации от приложения (для callback и статуса)
    cursor.execute("""
        CREATE TABLE IF NOT EXISTS app_generation_tasks (
            id INT AUTO_INCREMENT PRIMARY KEY,
            task_id VARCHAR(128) NOT NULL UNIQUE,
            vk_user_id VARCHAR(64) NULL,
            user_id INT NULL,
            device_id VARCHAR(64) NULL,
            task_type ENUM('image', 'video') NOT NULL,
            template_id VARCHAR(64) NULL,
            status ENUM('pending', 'processing', 'completed', 'failed') DEFAULT 'pending',
            result_url TEXT NULL,
            cost DECIMAL(10,2) DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            completed_at DATETIME NULL,
            error_message TEXT NULL,
            INDEX idx_vk_user (vk_user_id),
            INDEX idx_user_id (user_id),
            INDEX idx_device_id (device_id),
            INDEX idx_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    """)
    
    # Добавляем поля user_id и device_id если их нет
    try:
        cursor.execute("ALTER TABLE app_generation_tasks ADD COLUMN user_id INT NULL")
    except Exception:
        pass
    try:
        cursor.execute("ALTER TABLE app_generation_tasks ADD COLUMN device_id VARCHAR(64) NULL")
    except Exception:
        pass
    try:
        cursor.execute("ALTER TABLE app_generation_tasks MODIFY COLUMN vk_user_id VARCHAR(64) NULL")
    except Exception:
        pass
    try:
        cursor.execute("ALTER TABLE app_generation_tasks ADD INDEX idx_user_id (user_id)")
    except Exception:
        pass
    try:
        cursor.execute("ALTER TABLE app_generation_tasks ADD INDEX idx_device_id (device_id)")
    except Exception:
        pass

    cursor.execute("""
        CREATE TABLE IF NOT EXISTS campaign_tags (
            id INT AUTO_INCREMENT PRIMARY KEY,
            tag VARCHAR(50) UNIQUE NOT NULL,
            description VARCHAR(255) DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    """)

    cursor.execute("""
        CREATE TABLE IF NOT EXISTS tag_events (
            id INT AUTO_INCREMENT PRIMARY KEY,
            tag_id INT NOT NULL,
            telegram_id BIGINT NOT NULL,
            event_type ENUM('start', 'payment') DEFAULT 'start',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_tag_events_campaign_tag FOREIGN KEY (tag_id) REFERENCES campaign_tags(id) ON DELETE CASCADE,
            INDEX idx_tag_events_tag_created (tag_id, created_at),
            INDEX idx_tag_events_tag_user (tag_id, telegram_id),
            INDEX idx_tag_events_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    """)

    cursor.execute("""
        CREATE TABLE IF NOT EXISTS tts_free_usage (
            id INT AUTO_INCREMENT PRIMARY KEY,
            telegram_id BIGINT NOT NULL,
            usage_date DATE NOT NULL,
            used_chars INT NOT NULL DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_tts_usage_user_date (telegram_id, usage_date),
            INDEX idx_tts_free_usage_date (usage_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    """)

    try:
        cursor.execute("ALTER TABLE payments ADD COLUMN campaign_tag VARCHAR(50) DEFAULT NULL")
    except Exception:
        pass
    try:
        cursor.execute("ALTER TABLE payments ADD COLUMN vk_user_id VARCHAR(64) DEFAULT NULL")
    except Exception:
        pass
    try:
        cursor.execute("ALTER TABLE payments ADD COLUMN device_id VARCHAR(64) DEFAULT NULL")
    except Exception:
        pass
    try:
        cursor.execute("ALTER TABLE payments ADD COLUMN user_id INT DEFAULT NULL")
    except Exception:
        pass

    default_tags = [
        ("tts", "Рекламная кампания TTS")
    ]
    for tag_value, description in default_tags:
        cursor.execute("""
            INSERT INTO campaign_tags (tag, description)
            VALUES (%s, %s)
            ON DUPLICATE KEY UPDATE description = COALESCE(description, VALUES(description))
        """, (tag_value, description))

    # Таблицы для папок с каналами
    cursor.execute("""
        CREATE TABLE IF NOT EXISTS folders (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            description TEXT,
            price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            is_active BOOLEAN DEFAULT TRUE,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_folders_active (is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    """)
    
    cursor.execute("""
        CREATE TABLE IF NOT EXISTS folder_channels (
            id INT AUTO_INCREMENT PRIMARY KEY,
            folder_id INT NOT NULL,
            channel_username VARCHAR(255) NOT NULL,
            channel_link VARCHAR(500),
            channel_title VARCHAR(255),
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_folder_channels_folder FOREIGN KEY (folder_id) REFERENCES folders(id) ON DELETE CASCADE,
            INDEX idx_folder_channels_folder (folder_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    """)
    
    cursor.execute("""
        CREATE TABLE IF NOT EXISTS user_folder_subscriptions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT NOT NULL,
            folder_id INT NOT NULL,
            subscription_date DATETIME DEFAULT CURRENT_TIMESTAMP,
            payment_amount DECIMAL(10,2) NOT NULL,
            UNIQUE KEY uniq_user_folder (user_id, folder_id),
            INDEX idx_user_folder_user (user_id),
            INDEX idx_user_folder_folder (folder_id),
            CONSTRAINT fk_user_folder_subscriptions_folder FOREIGN KEY (folder_id) REFERENCES folders(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    """)

    # Таблица для токенов восстановления пароля
    cursor.execute("""
        CREATE TABLE IF NOT EXISTS password_reset_tokens (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            token VARCHAR(64) NOT NULL UNIQUE,
            expires_at DATETIME NOT NULL,
            used BOOLEAN DEFAULT FALSE,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_token (token),
            INDEX idx_user_id (user_id),
            INDEX idx_expires_at (expires_at),
            CONSTRAINT fk_password_reset_tokens_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    """)

    conn.commit()
    conn.close()


def add_user(telegram_id: int, username: str, tag: str = None):
    """Регистрация нового пользователя"""
    conn = get_connection()
    cursor = conn.cursor()
    cursor.execute("SELECT id FROM users WHERE telegram_id = %s", (telegram_id,))
    if cursor.fetchone() is None:
        cursor.execute(
            "INSERT INTO users (telegram_id, username, balance, registration_date, tag, is_blocked) VALUES (%s, %s, %s, %s, %s, %s)",
            (telegram_id, username, 0, datetime.now(), tag, False)
        )
    else:
        # Обновляем tag если пользователь существует
        if tag:
            cursor.execute("UPDATE users SET tag = %s WHERE telegram_id = %s", (tag, telegram_id))
    conn.commit()
    conn.close()


def create_campaign_tag(tag: str, description: str = None) -> Tuple[bool, Optional[str]]:
    """Создание нового тега кампании"""
    tag = (tag or "").strip().lower()
    if not tag:
        return False, "empty"

    conn = get_connection()
    cursor = conn.cursor()
    try:
        cursor.execute(
            "INSERT INTO campaign_tags (tag, description) VALUES (%s, %s)",
            (tag, description)
        )
        conn.commit()
        return True, None
    except pymysql.err.IntegrityError:
        conn.rollback()
        return False, "duplicate"
    except Exception as exc:
        conn.rollback()
        return False, str(exc)
    finally:
        conn.close()


def delete_campaign_tag(tag_id: int) -> bool:
    """Удаление тега кампании по ID"""
    conn = get_connection()
    cursor = conn.cursor()
    try:
        cursor.execute("DELETE FROM campaign_tags WHERE id = %s", (tag_id,))
        conn.commit()
        return cursor.rowcount > 0
    except Exception:
        conn.rollback()
        return False
    finally:
        conn.close()


def get_campaign_tag_by_value(tag: str) -> Optional[Dict[str, Any]]:
    """Получение информации о теге кампании по значению"""
    tag = (tag or "").strip().lower()
    if not tag:
        return None

    conn = get_connection()
    cursor = conn.cursor()
    cursor.execute("SELECT id, tag, description, created_at FROM campaign_tags WHERE tag = %s", (tag,))
    result = cursor.fetchone()
    conn.close()
    return result


def log_tag_event(tag_id: int, telegram_id: int) -> bool:
    """Логирование перехода по тегу (1 раз в день для пользователя)"""
    if not tag_id or not telegram_id:
        return False

    conn = get_connection()
    cursor = conn.cursor()
    try:
        # Проверяем наличие записи за сегодня
        cursor.execute("""
            SELECT id, created_at FROM tag_events 
            WHERE tag_id = %s AND telegram_id = %s AND DATE(created_at) = CURDATE()
            LIMIT 1
        """, (tag_id, telegram_id))
        existing_event = cursor.fetchone()
        
        if existing_event:
            # Если запись существует, проверяем дату регистрации пользователя
            cursor.execute("""
                SELECT registration_date FROM users WHERE telegram_id = %s
            """, (telegram_id,))
            user_record = cursor.fetchone()
            
            if user_record and user_record.get("registration_date"):
                registration_date = user_record["registration_date"]
                event_date = existing_event.get("created_at")
                
                # Если регистрация была после события, значит пользователь был пересоздан
                # Удаляем старую запись и создадим новую
                try:
                    if isinstance(registration_date, datetime) and isinstance(event_date, datetime):
                        if registration_date > event_date:
                            # Пользователь был пересоздан после создания события - удаляем старое событие
                            cursor.execute("DELETE FROM tag_events WHERE id = %s", (existing_event["id"],))
                        else:
                            # Пользователь существует и запись актуальна - не создаем дубликат
                            return False
                    else:
                        # Если типы не совпадают, считаем что запись актуальна
                        return False
                except (TypeError, AttributeError) as e:
                    # Если сравнение не удалось, считаем что запись актуальна
                    print(f"Error comparing dates in log_tag_event: {e}")
                    return False
            else:
                # Пользователя нет в users - удаляем старую запись (он был удален)
                cursor.execute("DELETE FROM tag_events WHERE id = %s", (existing_event["id"],))

        # Создаем новую запись о переходе
        cursor.execute("""
            INSERT INTO tag_events (tag_id, telegram_id, event_type)
            VALUES (%s, %s, 'start')
        """, (tag_id, telegram_id))
        conn.commit()
        return True
    except Exception as e:
        conn.rollback()
        import traceback
        print(f"Error in log_tag_event: {e}")
        print(traceback.format_exc())
        return False
    finally:
        conn.close()


def get_campaign_tag_overview() -> Dict[str, Any]:
    """Получение статистики по тегам кампаний"""
    conn = get_connection()
    cursor = conn.cursor()

    cursor.execute("""
        SELECT id, tag, description, created_at
        FROM campaign_tags
        ORDER BY created_at DESC
    """)
    tags = cursor.fetchall()

    cursor.execute("""
        SELECT 
            tag_id,
            COUNT(*) AS total_transitions,
            SUM(CASE WHEN DATE(created_at) = CURDATE() THEN 1 ELSE 0 END) AS today_transitions,
            SUM(CASE WHEN YEAR(created_at) = YEAR(CURDATE()) AND MONTH(created_at) = MONTH(CURDATE()) THEN 1 ELSE 0 END) AS month_transitions
        FROM tag_events
        GROUP BY tag_id
    """)
    transitions = {row["tag_id"]: row for row in cursor.fetchall()}

    cursor.execute("""
        SELECT 
            tag,
            COUNT(*) AS users_count
        FROM users
        WHERE tag IS NOT NULL AND tag <> ''
        GROUP BY tag
    """)
    users_counts = {row["tag"]: row["users_count"] for row in cursor.fetchall()}

    cursor.execute("""
        SELECT 
            campaign_tag AS tag,
            SUM(CASE WHEN DATE(created_at) = CURDATE() THEN amount ELSE 0 END) AS today_amount,
            SUM(CASE WHEN DATE(created_at) = CURDATE() THEN 1 ELSE 0 END) AS today_count,
            SUM(CASE WHEN YEAR(created_at) = YEAR(CURDATE()) AND MONTH(created_at) = MONTH(CURDATE()) THEN amount ELSE 0 END) AS month_amount,
            SUM(CASE WHEN YEAR(created_at) = YEAR(CURDATE()) AND MONTH(created_at) = MONTH(CURDATE()) THEN 1 ELSE 0 END) AS month_count
        FROM payments
        WHERE status = 'completed'
        GROUP BY campaign_tag
    """)
    payments = {}
    for row in cursor.fetchall():
        tag_value = row["tag"]
        payments[tag_value] = {
            "today_amount": float(row["today_amount"] or 0),
            "today_count": int(row["today_count"] or 0),
            "month_amount": float(row["month_amount"] or 0),
            "month_count": int(row["month_count"] or 0),
        }

    conn.close()

    overview = []
    for tag in tags:
        tag_id = tag["id"]
        tag_value = tag["tag"]
        tag_payments = payments.get(tag_value, {})
        tag_transitions = transitions.get(tag_id, {})

        overview.append({
            "id": tag_id,
            "tag": tag_value,
            "description": tag["description"],
            "created_at": tag["created_at"],
            "total_transitions": int(tag_transitions.get("total_transitions") or 0),
            "today_transitions": int(tag_transitions.get("today_transitions") or 0),
            "month_transitions": int(tag_transitions.get("month_transitions") or 0),
            "today_amount": float(tag_payments.get("today_amount") or 0),
            "today_count": int(tag_payments.get("today_count") or 0),
            "month_amount": float(tag_payments.get("month_amount") or 0),
            "month_count": int(tag_payments.get("month_count") or 0),
            "users_count": int(users_counts.get(tag_value) or 0),
        })

    no_tag_payments = payments.get(None) or payments.get("")
    no_tag_stats = None
    if no_tag_payments:
        no_tag_stats = {
            "today_amount": float(no_tag_payments.get("today_amount") or 0),
            "today_count": int(no_tag_payments.get("today_count") or 0),
            "month_amount": float(no_tag_payments.get("month_amount") or 0),
            "month_count": int(no_tag_payments.get("month_count") or 0),
        }

    return {"tags": overview, "no_tag": no_tag_stats}

def get_user_tag(telegram_id: int) -> str:
    """Получение tag пользователя"""
    conn = get_connection()
    cursor = conn.cursor()
    cursor.execute("SELECT tag FROM users WHERE telegram_id = %s", (telegram_id,))
    result = cursor.fetchone()
    conn.close()
    return result["tag"] if result and result.get("tag") else None

def set_user_tag(telegram_id: int, tag: str):
    """Установка tag пользователя"""
    conn = get_connection()
    cursor = conn.cursor()
    cursor.execute("UPDATE users SET tag = %s WHERE telegram_id = %s", (tag, telegram_id))
    conn.commit()
    conn.close()


def get_user_by_id(user_id: int) -> Optional[Dict[str, Any]]:
    """Получение пользователя по id."""
    import logging
    logger = logging.getLogger(__name__)
    
    if not user_id:
        return None
    conn = get_connection()
    cursor = conn.cursor()
    cursor.execute(
        "SELECT id, telegram_id, username, balance, vk_user_id, device_id, email, app_display_name, registration_date, password_hash FROM users WHERE id = %s",
        (int(user_id),)
    )
    result = cursor.fetchone()
    conn.close()
    
    if result:
        logger.info(f"[get_user_by_id] Найден пользователь: id={result.get('id')}, email={result.get('email')}, balance={result.get('balance')}, balance_type={type(result.get('balance'))}")
    else:
        logger.warning(f"[get_user_by_id] Пользователь не найден: user_id={user_id}")
    
    return result


def get_user_by_email(email: str) -> Optional[Dict[str, Any]]:
    """Получение пользователя по email."""
    if not email or not str(email).strip():
        return None
    conn = get_connection()
    cursor = conn.cursor()
    cursor.execute(
        "SELECT id, telegram_id, username, balance, vk_user_id, device_id, email, app_display_name, registration_date, password_hash FROM users WHERE email = %s",
        (str(email).strip().lower(),)
    )
    result = cursor.fetchone()
    conn.close()
    return result


def create_user_email(email: str, password_hash: str) -> Dict[str, Any]:
    """Создать пользователя по email и хешу пароля. Возвращает user dict."""
    email = str(email).strip().lower()
    conn = get_connection()
    cursor = conn.cursor()
    cursor.execute(
        "INSERT INTO users (email, password_hash, app_display_name, balance, registration_date, tag) VALUES (%s, %s, %s, 0, %s, 'app')",
        (email, password_hash, email.split("@")[0] or "Пользователь", datetime.now())
    )
    conn.commit()
    uid = cursor.lastrowid
    conn.close()
    return {
        "id": uid,
        "telegram_id": None,
        "username": None,
        "balance": 0,
        "vk_user_id": None,
        "device_id": None,
        "email": email,
        "app_display_name": email.split("@")[0] or "Пользователь",
        "registration_date": None,
        "password_hash": password_hash,
    }


def get_user_by_vk_id(vk_user_id: str) -> Optional[Dict[str, Any]]:
    """Получение пользователя по VK ID (для приложения RuStore)."""
    if not vk_user_id:
        return None
    conn = get_connection()
    cursor = conn.cursor()
    cursor.execute(
        "SELECT id, telegram_id, username, balance, vk_user_id, device_id, email, app_display_name, registration_date, password_hash FROM users WHERE vk_user_id = %s",
        (str(vk_user_id),)
    )
    result = cursor.fetchone()
    conn.close()
    return result


def get_or_create_app_user(vk_user_id: str, display_name: str = None) -> Tuple[Dict[str, Any], bool]:
    """
    Получить или создать пользователя по VK ID. Возвращает (user_dict, created).
    """
    user = get_user_by_vk_id(vk_user_id)
    if user:
        if display_name and display_name != (user.get("app_display_name") or ""):
            conn = get_connection()
            cursor = conn.cursor()
            cursor.execute("UPDATE users SET app_display_name = %s WHERE vk_user_id = %s", (display_name, str(vk_user_id)))
            conn.commit()
            conn.close()
            user["app_display_name"] = display_name
        return user, False
    conn = get_connection()
    cursor = conn.cursor()
    cursor.execute(
        "INSERT INTO users (vk_user_id, app_display_name, balance, registration_date, tag) VALUES (%s, %s, 0, %s, 'app')",
        (str(vk_user_id), display_name or "", datetime.now())
    )
    conn.commit()
    uid = cursor.lastrowid
    conn.close()
    return {
        "id": uid,
        "telegram_id": None,
        "username": None,
        "balance": 0,
        "vk_user_id": str(vk_user_id),
        "app_display_name": display_name or "",
        "registration_date": None,
    }, True


def get_user_by_device_id(device_id: str) -> Optional[Dict[str, Any]]:
    """Получение пользователя по device_id (гостевой вход)."""
    if not device_id:
        return None
    conn = get_connection()
    cursor = conn.cursor()
    cursor.execute(
        "SELECT id, telegram_id, username, balance, vk_user_id, device_id, app_display_name, registration_date FROM users WHERE device_id = %s",
        (str(device_id),)
    )
    result = cursor.fetchone()
    conn.close()
    return result


def get_or_create_guest_user(device_id: str) -> Tuple[Dict[str, Any], bool]:
    """Получить или создать гостевого пользователя по device_id. Возвращает (user_dict, created)."""
    user = get_user_by_device_id(device_id)
    if user:
        return user, False
    conn = get_connection()
    cursor = conn.cursor()
    cursor.execute(
        "INSERT INTO users (device_id, app_display_name, balance, registration_date, tag) VALUES (%s, %s, 0, %s, 'app')",
        (str(device_id), "Гость", datetime.now())
    )
    conn.commit()
    uid = cursor.lastrowid
    conn.close()
    return {
        "id": uid,
        "telegram_id": None,
        "username": None,
        "balance": 0,
        "vk_user_id": None,
        "device_id": str(device_id),
        "app_display_name": "Гость",
        "registration_date": None,
    }, True


def get_user_by_app_id(app_id: str) -> Optional[Dict[str, Any]]:
    """Получение пользователя по идентификатору приложения: vk_user_id, device_id или user_id."""
    if not app_id:
        return None
    # Сначала проверяем числовой ID (для JWT авторизации)
    if app_id.isdigit():
        u = get_user_by_id(int(app_id))
        if u:
            return u
    # Затем проверяем VK ID
    u = get_user_by_vk_id(app_id)
    if u:
        return u
    # И наконец device_id
    return get_user_by_device_id(app_id)


def get_balance_by_vk_id(vk_user_id: str) -> float:
    """Получение баланса по VK ID."""
    from decimal import Decimal
    user = get_user_by_vk_id(vk_user_id)
    if not user or user.get("balance") is None:
        return 0.0
    balance = user["balance"]
    # Обрабатываем Decimal из MySQL
    if isinstance(balance, Decimal):
        return float(balance)
    elif isinstance(balance, (int, float)):
        return float(balance)
    else:
        return float(str(balance))


def update_balance_by_vk_id(vk_user_id: str, tokens: float):
    """Пополнение баланса по VK ID."""
    conn = get_connection()
    cursor = conn.cursor()
    cursor.execute("UPDATE users SET balance = balance + %s WHERE vk_user_id = %s", (tokens, str(vk_user_id)))
    conn.commit()
    conn.close()


def deduct_balance_by_vk_id(vk_user_id: str, tokens: float) -> bool:
    """Списание с баланса по VK ID. Возвращает True при успехе."""
    try:
        conn = get_connection()
        cursor = conn.cursor()
        cursor.execute("SELECT balance FROM users WHERE vk_user_id = %s", (str(vk_user_id),))
        result = cursor.fetchone()
        if result and result["balance"] is not None and float(result["balance"]) >= tokens:
            cursor.execute("UPDATE users SET balance = balance - %s WHERE vk_user_id = %s", (tokens, str(vk_user_id)))
            conn.commit()
            conn.close()
            return True
        conn.close()
        return False
    except Exception:
        try:
            conn.close()
        except Exception:
            pass
        return False


def get_balance_by_device_id(device_id: str) -> float:
    """Получение баланса по device_id (гость)."""
    from decimal import Decimal
    user = get_user_by_device_id(device_id)
    if not user or user.get("balance") is None:
        return 0.0
    balance = user["balance"]
    # Обрабатываем Decimal из MySQL
    if isinstance(balance, Decimal):
        return float(balance)
    elif isinstance(balance, (int, float)):
        return float(balance)
    else:
        return float(str(balance))


def update_balance_by_device_id(device_id: str, tokens: float):
    """Пополнение баланса по device_id (гость)."""
    conn = get_connection()
    cursor = conn.cursor()
    cursor.execute("UPDATE users SET balance = balance + %s WHERE device_id = %s", (tokens, str(device_id)))
    conn.commit()
    conn.close()


def deduct_balance_by_device_id(device_id: str, tokens: float) -> bool:
    """Списание с баланса по device_id. Возвращает True при успехе."""
    import logging
    logger = logging.getLogger(__name__)
    
    try:
        conn = get_connection()
        cursor = conn.cursor()
        cursor.execute("SELECT balance FROM users WHERE device_id = %s", (str(device_id),))
        result = cursor.fetchone()
        
        if not result:
            logger.warning(f"[deduct_balance_by_device_id] Пользователь не найден: device_id={device_id}")
            conn.close()
            return False
        
        balance = result.get("balance")
        if balance is None:
            logger.warning(f"[deduct_balance_by_device_id] Баланс None для device_id={device_id}")
            conn.close()
            return False
        
        balance_float = float(balance)
        logger.info(f"[deduct_balance_by_device_id] Текущий баланс: {balance_float}, требуется: {tokens}")
        
        if balance_float >= tokens:
            cursor.execute("UPDATE users SET balance = balance - %s WHERE device_id = %s", (tokens, str(device_id)))
            conn.commit()
            
            # Проверяем, что баланс действительно обновился
            cursor.execute("SELECT balance FROM users WHERE device_id = %s", (str(device_id),))
            updated_result = cursor.fetchone()
            updated_balance = float(updated_result.get("balance")) if updated_result else None
            logger.info(f"[deduct_balance_by_device_id] Баланс после списания: {updated_balance}")
            
            conn.close()
            return True
        else:
            logger.warning(f"[deduct_balance_by_device_id] Недостаточно средств: balance={balance_float}, tokens={tokens}")
            conn.close()
            return False
    except Exception as e:
        logger.error(f"[deduct_balance_by_device_id] Ошибка при списании баланса: {e}", exc_info=True)
        try:
            conn.close()
        except Exception:
            pass
        return False


def get_balance_by_user_id(user_id: int) -> float:
    """Баланс по id пользователя (для входа по email)."""
    import logging
    from decimal import Decimal
    logger = logging.getLogger(__name__)
    
    logger.info(f"[get_balance_by_user_id] Запрос баланса для user_id={user_id}")
    u = get_user_by_id(user_id)
    
    if not u:
        logger.warning(f"[get_balance_by_user_id] Пользователь не найден: user_id={user_id}")
        return 0.0
    
    balance_raw = u.get("balance")
    logger.info(f"[get_balance_by_user_id] Баланс из БД: balance_raw={balance_raw}, type={type(balance_raw)}, user_id={user_id}")
    
    if balance_raw is None:
        logger.warning(f"[get_balance_by_user_id] Баланс None для user_id={user_id}")
        return 0.0
    
    try:
        # Обрабатываем Decimal из MySQL
        if isinstance(balance_raw, Decimal):
            balance_float = float(balance_raw)
        elif isinstance(balance_raw, (int, float)):
            balance_float = float(balance_raw)
        else:
            balance_float = float(str(balance_raw))
        
        logger.info(f"[get_balance_by_user_id] Баланс преобразован: {balance_float} для user_id={user_id}")
        return balance_float
    except (ValueError, TypeError) as e:
        logger.error(f"[get_balance_by_user_id] Ошибка преобразования баланса: {e}, balance_raw={balance_raw}, type={type(balance_raw)}")
        return 0.0


def update_balance_by_user_id(user_id: int, tokens: float):
    """Пополнение баланса по id пользователя."""
    conn = get_connection()
    cursor = conn.cursor()
    cursor.execute("UPDATE users SET balance = balance + %s WHERE id = %s", (tokens, int(user_id)))
    conn.commit()
    conn.close()


def deduct_balance_by_user_id(user_id: int, tokens: float) -> bool:
    """Списание с баланса по id пользователя."""
    import logging
    logger = logging.getLogger(__name__)
    
    try:
        conn = get_connection()
        cursor = conn.cursor()
        cursor.execute("SELECT balance FROM users WHERE id = %s", (int(user_id),))
        result = cursor.fetchone()
        
        if not result:
            logger.warning(f"[deduct_balance_by_user_id] Пользователь не найден: user_id={user_id}")
            conn.close()
            return False
        
        balance = result.get("balance")
        if balance is None:
            logger.warning(f"[deduct_balance_by_user_id] Баланс None для user_id={user_id}")
            conn.close()
            return False
        
        balance_float = float(balance)
        logger.info(f"[deduct_balance_by_user_id] Текущий баланс: {balance_float}, требуется: {tokens}")
        
        if balance_float >= tokens:
            cursor.execute("UPDATE users SET balance = balance - %s WHERE id = %s", (tokens, int(user_id)))
            conn.commit()
            
            # Проверяем, что баланс действительно обновился
            cursor.execute("SELECT balance FROM users WHERE id = %s", (int(user_id),))
            updated_result = cursor.fetchone()
            updated_balance = float(updated_result.get("balance")) if updated_result else None
            logger.info(f"[deduct_balance_by_user_id] Баланс после списания: {updated_balance}")
            
            conn.close()
            return True
        else:
            logger.warning(f"[deduct_balance_by_user_id] Недостаточно средств: balance={balance_float}, tokens={tokens}")
            conn.close()
            return False
    except Exception as e:
        logger.error(f"[deduct_balance_by_user_id] Ошибка при списании баланса: {e}", exc_info=True)
        try:
            conn.close()
        except Exception:
            pass
        return False


def get_balance_by_app_id(app_id: str) -> float:
    """Баланс по идентификатору приложения (vk_user_id, device_id или user_id)."""
    import logging
    from decimal import Decimal
    logger = logging.getLogger(__name__)
    
    logger.info(f"[get_balance_by_app_id] Запрос баланса для app_id={app_id}, type={type(app_id)}")
    
    # Если app_id числовой (JWT авторизация), сначала пробуем напрямую по user_id
    if app_id.isdigit():
        user_id = int(app_id)
        logger.info(f"[get_balance_by_app_id] app_id числовой, пробую get_balance_by_user_id({user_id})")
        balance = get_balance_by_user_id(user_id)
        logger.info(f"[get_balance_by_app_id] Баланс через get_balance_by_user_id: {balance}")
        if balance > 0:
            return balance
    
    # Пробуем через get_user_by_app_id (для VK ID и device_id)
    u = get_user_by_app_id(app_id)
    if u:
        balance = u.get("balance")
        if balance is not None:
            # Обрабатываем Decimal из MySQL
            try:
                if isinstance(balance, Decimal):
                    balance_float = float(balance)
                elif isinstance(balance, (int, float)):
                    balance_float = float(balance)
                else:
                    balance_float = float(str(balance))
                logger.info(f"[get_balance_by_app_id] Баланс найден через get_user_by_app_id: app_id={app_id}, balance={balance_float}")
                return balance_float
            except (ValueError, TypeError) as e:
                logger.error(f"[get_balance_by_app_id] Ошибка преобразования баланса: {e}, balance={balance}, type={type(balance)}")
        else:
            logger.warning(f"[get_balance_by_app_id] Баланс None для пользователя: app_id={app_id}, user={u}")
    else:
        logger.warning(f"[get_balance_by_app_id] Пользователь не найден: app_id={app_id}")
    
    # Если ничего не помогло, возвращаем 0
    logger.warning(f"[get_balance_by_app_id] Возвращаю 0.0 для app_id={app_id}")
    return 0.0


def deduct_balance_by_app_id(app_id: str, tokens: float) -> bool:
    """Списание по идентификатору приложения (vk, гость или user_id). Использует ту же логику, что и get_balance_by_app_id."""
    import logging
    logger = logging.getLogger(__name__)
    
    logger.info(f"[deduct_balance_by_app_id] Списание баланса: app_id={app_id}, tokens={tokens}, type={type(app_id)}")
    
    # Если app_id числовой (JWT авторизация), сначала пробуем напрямую по user_id
    if app_id.isdigit():
        user_id = int(app_id)
        logger.info(f"[deduct_balance_by_app_id] app_id числовой, пробую deduct_balance_by_user_id({user_id})")
        result = deduct_balance_by_user_id(user_id, tokens)
        logger.info(f"[deduct_balance_by_app_id] Результат deduct_balance_by_user_id: {result}")
        if result:
            return True
    
    # Используем get_user_by_app_id для определения типа пользователя (как в get_balance_by_app_id)
    u = get_user_by_app_id(app_id)
    if u:
        user_id = u.get("id")
        vk_user_id = u.get("vk_user_id")
        device_id = u.get("device_id")
        
        logger.info(f"[deduct_balance_by_app_id] Найден пользователь: user_id={user_id}, vk_user_id={vk_user_id}, device_id={device_id}")
        
        # Пробуем списать по user_id (если есть)
        if user_id:
            result = deduct_balance_by_user_id(int(user_id), tokens)
            logger.info(f"[deduct_balance_by_app_id] Результат deduct_balance_by_user_id (через get_user_by_app_id): {result}")
            if result:
                return True
        
        # Пробуем списать по vk_user_id (если есть)
        if vk_user_id:
            result = deduct_balance_by_vk_id(str(vk_user_id), tokens)
            logger.info(f"[deduct_balance_by_app_id] Результат deduct_balance_by_vk_id: {result}")
            if result:
                return True
        
        # Пробуем списать по device_id (если есть)
        if device_id:
            result = deduct_balance_by_device_id(str(device_id), tokens)
            logger.info(f"[deduct_balance_by_app_id] Результат deduct_balance_by_device_id: {result}")
            if result:
                return True
    
    logger.error(f"[deduct_balance_by_app_id] Не удалось списать баланс ни одним способом для app_id={app_id}")
    return False


def ensure_referral_code(vk_user_id: str) -> str:
    """Генерирует и сохраняет referral_code для пользователя, если ещё нет. Возвращает код."""
    import secrets
    conn = get_connection()
    cursor = conn.cursor()
    cursor.execute("SELECT referral_code FROM users WHERE vk_user_id = %s", (str(vk_user_id),))
    row = cursor.fetchone()
    if row and row.get("referral_code"):
        conn.close()
        return row["referral_code"]
    code = secrets.token_urlsafe(8)[:12]
    cursor.execute("UPDATE users SET referral_code = %s WHERE vk_user_id = %s", (code, str(vk_user_id)))
    conn.commit()
    conn.close()
    return code


def ensure_referral_code_by_device_id(device_id: str) -> str:
    """Генерирует и сохраняет referral_code для гостевого пользователя. Возвращает код."""
    import secrets
    conn = get_connection()
    cursor = conn.cursor()
    cursor.execute("SELECT referral_code FROM users WHERE device_id = %s", (str(device_id),))
    row = cursor.fetchone()
    if row and row.get("referral_code"):
        conn.close()
        return row["referral_code"]
    code = secrets.token_urlsafe(8)[:12]
    cursor.execute("UPDATE users SET referral_code = %s WHERE device_id = %s", (code, str(device_id)))
    conn.commit()
    conn.close()
    return code


def ensure_referral_code_by_user_id(user_id: int) -> str:
    """Генерирует и сохраняет referral_code по id пользователя. Возвращает код."""
    import secrets
    conn = get_connection()
    cursor = conn.cursor()
    cursor.execute("SELECT referral_code FROM users WHERE id = %s", (int(user_id),))
    row = cursor.fetchone()
    if row and row.get("referral_code"):
        conn.close()
        return row["referral_code"]
    code = secrets.token_urlsafe(8)[:12]
    cursor.execute("UPDATE users SET referral_code = %s WHERE id = %s", (code, int(user_id)))
    conn.commit()
    conn.close()
    return code


def ensure_referral_code_app(app_id: str) -> str:
    """Реферальный код для пользователя приложения (VK или гость)."""
    if get_user_by_vk_id(app_id):
        return ensure_referral_code(app_id)
    return ensure_referral_code_by_device_id(app_id)


def add_app_referral(partner_vk_id: str, referral_vk_id: str) -> bool:
    """Привязать реферала к партнёру (по VK ID)."""
    if partner_vk_id == referral_vk_id:
        return False
    conn = get_connection()
    cursor = conn.cursor()
    try:
        cursor.execute("""
            INSERT INTO app_partner_program (partner_vk_id, referral_vk_id)
            VALUES (%s, %s)
        """, (str(partner_vk_id), str(referral_vk_id)))
        conn.commit()
        return True
    except pymysql.err.IntegrityError:
        conn.rollback()
        return False
    finally:
        conn.close()


def get_partner_stats_by_vk(partner_vk_id: str) -> Dict[str, Any]:
    """Статистика партнёра по VK ID."""
    conn = get_connection()
    cursor = conn.cursor()
    cursor.execute(
        "SELECT COUNT(*) AS total_referrals FROM app_partner_program WHERE partner_vk_id = %s",
        (str(partner_vk_id),)
    )
    total_referrals = cursor.fetchone()["total_referrals"]
    cursor.execute(
        "SELECT COALESCE(SUM(total_purchases), 0) AS s FROM app_partner_program WHERE partner_vk_id = %s",
        (str(partner_vk_id),)
    )
    total_purchases = float(cursor.fetchone()["s"] or 0)
    cursor.execute(
        "SELECT COALESCE(SUM(partner_commission), 0) AS s FROM app_partner_program WHERE partner_vk_id = %s",
        (str(partner_vk_id),)
    )
    total_commission = float(cursor.fetchone()["s"] or 0)
    conn.close()
    return {
        "total_referrals": total_referrals,
        "total_purchases": total_purchases,
        "total_commission": total_commission,
        "available_for_withdrawal": total_commission,
    }


def update_partner_commission_by_vk(referral_vk_id: str, purchase_amount: float) -> bool:
    """Начислить комиссию партнёру при оплате реферала (по VK ID)."""
    conn = get_connection()
    cursor = conn.cursor()
    cursor.execute("""
        SELECT partner_vk_id, commission_rate FROM app_partner_program
        WHERE referral_vk_id = %s AND status = 'active'
    """, (str(referral_vk_id),))
    row = cursor.fetchone()
    if not row:
        conn.close()
        return False
    partner_vk_id = row["partner_vk_id"]
    rate = float(row["commission_rate"] or 10)
    commission = purchase_amount * (rate / 100)
    cursor.execute("""
        UPDATE app_partner_program
        SET total_purchases = total_purchases + %s, partner_commission = partner_commission + %s,
            first_payment_received = TRUE
        WHERE partner_vk_id = %s AND referral_vk_id = %s
    """, (purchase_amount, commission, partner_vk_id, str(referral_vk_id)))
    cursor.execute("UPDATE users SET balance = balance + %s WHERE vk_user_id = %s", (commission, partner_vk_id))
    conn.commit()
    conn.close()
    return True


def save_app_task(task_id: str, app_id: str, task_type: str, template_id: str = None, cost: float = 0, 
                  user_id: int = None, device_id: str = None, vk_user_id: str = None) -> bool:
    """Сохранить задачу генерации от приложения. Поддерживает vk_user_id, user_id и device_id."""
    import logging
    logger = logging.getLogger(__name__)
    
    conn = get_connection()
    cursor = conn.cursor()
    try:
        # Определяем тип пользователя по app_id
        if not vk_user_id and not user_id and not device_id:
            # Если не переданы явно, пытаемся определить по app_id
            if app_id.isdigit():
                user_id = int(app_id)
            else:
                # Проверяем, это VK ID или device_id
                user = get_user_by_vk_id(app_id)
                if user:
                    vk_user_id = app_id
                else:
                    device_id = app_id
        
        cursor.execute("""
            INSERT INTO app_generation_tasks (task_id, vk_user_id, user_id, device_id, task_type, template_id, cost, status)
            VALUES (%s, %s, %s, %s, %s, %s, %s, 'pending')
        """, (task_id, vk_user_id, user_id, device_id, task_type, template_id or "", cost))
        conn.commit()
        logger.info(f"[save_app_task] Задача сохранена: task_id={task_id}, vk_user_id={vk_user_id}, user_id={user_id}, device_id={device_id}")
        return True
    except Exception as e:
        conn.rollback()
        logger.error(f"[save_app_task] Ошибка сохранения задачи: {e}")
        return False
    finally:
        conn.close()


def update_app_task_result(task_id: str, status: str, result_url: str = None, error_message: str = None) -> bool:
    """Обновить результат задачи (completed/failed)."""
    import logging
    logger = logging.getLogger(__name__)
    
    conn = get_connection()
    cursor = conn.cursor()
    try:
        # Убеждаемся, что result_url - строка или None
        if result_url is not None:
            result_url = str(result_url).strip()
            if result_url == "" or result_url.lower() == "none":
                result_url = None
        
        logger.info(f"[update_app_task_result] Обновление задачи: task_id={task_id}, status={status}, result_url={result_url}, error_message={error_message}")
        logger.info(f"[update_app_task_result] Тип result_url: {type(result_url)}, значение: {repr(result_url)}")
        
        cursor.execute("""
            UPDATE app_generation_tasks SET status = %s, result_url = %s, error_message = %s, completed_at = NOW()
            WHERE task_id = %s
        """, (status, result_url, error_message, task_id))
        conn.commit()
        updated_rows = cursor.rowcount
        logger.info(f"[update_app_task_result] Обновлено строк: {updated_rows}")
        
        # Проверяем, что данные действительно обновились
        if updated_rows > 0:
            cursor.execute("SELECT status, result_url, error_message, completed_at FROM app_generation_tasks WHERE task_id = %s", (task_id,))
            check_row = cursor.fetchone()
            logger.info(f"[update_app_task_result] Проверка после обновления: status={check_row.get('status') if check_row else None}, result_url={repr(check_row.get('result_url')) if check_row else None}, error_message={check_row.get('error_message') if check_row else None}")
            if check_row:
                logger.info(f"[update_app_task_result] result_url из БД: {repr(check_row.get('result_url'))}, тип: {type(check_row.get('result_url'))}")
                # Дополнительная проверка: убеждаемся, что result_url действительно записался
                if status == "completed" and check_row.get('result_url') is None:
                    logger.error(f"[update_app_task_result] ОШИБКА: Статус completed, но result_url = None в БД! task_id={task_id}")
                elif status == "completed" and check_row.get('result_url'):
                    logger.info(f"[update_app_task_result] УСПЕХ: Статус completed, result_url записан: {check_row.get('result_url')}")
        else:
            logger.warning(f"[update_app_task_result] Задача не обновлена! task_id={task_id}, updated_rows={updated_rows}")
            # Проверяем, существует ли задача
            cursor.execute("SELECT task_id FROM app_generation_tasks WHERE task_id = %s", (task_id,))
            exists = cursor.fetchone()
            if not exists:
                logger.error(f"[update_app_task_result] Задача не существует в БД! task_id={task_id}")
        
        return updated_rows > 0
    except Exception as e:
        logger.error(f"[update_app_task_result] Ошибка обновления задачи: {e}", exc_info=True)
        conn.rollback()
        return False
    finally:
        conn.close()


def get_app_task(task_id: str) -> Optional[Dict[str, Any]]:
    """Получить задачу по task_id. Поддерживает поиск по разным форматам task_id."""
    import logging
    logger = logging.getLogger(__name__)
    
    if not task_id:
        logger.warning(f"[get_app_task] Пустой task_id")
        return None
    
    # Нормализуем task_id (убираем пробелы, приводим к строке)
    task_id = str(task_id).strip()
    
    conn = get_connection()
    cursor = conn.cursor()
    
    # Пробуем точное совпадение
    cursor.execute("SELECT * FROM app_generation_tasks WHERE task_id = %s", (task_id,))
    row = cursor.fetchone()
    
    # Если не найдено, пробуем поиск без учета регистра (для совместимости)
    if not row:
        cursor.execute("SELECT * FROM app_generation_tasks WHERE LOWER(task_id) = LOWER(%s)", (task_id,))
        row = cursor.fetchone()
        if row:
            logger.info(f"[get_app_task] Задача найдена без учета регистра: task_id={task_id}")
    
    if row:
        logger.info(f"[get_app_task] Найдена задача: task_id={task_id}, status={row.get('status')}, result_url={row.get('result_url')}")
    else:
        logger.warning(f"[get_app_task] Задача не найдена: task_id={task_id}")
        # Логируем все существующие task_id для отладки (первые 10)
        cursor.execute("SELECT task_id FROM app_generation_tasks ORDER BY created_at DESC LIMIT 10")
        sample_tasks = cursor.fetchall()
        logger.warning(f"[get_app_task] Примеры существующих task_id: {[t.get('task_id') for t in sample_tasks]}")
    
    conn.close()
    return row


def get_user_generation_history(user_id: int = None, vk_user_id: str = None, device_id: str = None, limit: int = 100) -> List[Dict[str, Any]]:
    """Получить историю генераций пользователя. Поддерживает user_id, vk_user_id и device_id."""
    import logging
    logger = logging.getLogger(__name__)
    
    conn = get_connection()
    cursor = conn.cursor()
    
    try:
        # Формируем WHERE условие в зависимости от переданных параметров
        conditions = []
        params = []
        
        if user_id is not None:
            conditions.append("user_id = %s")
            params.append(int(user_id))
        if vk_user_id:
            conditions.append("vk_user_id = %s")
            params.append(str(vk_user_id))
        if device_id:
            conditions.append("device_id = %s")
            params.append(str(device_id))
        
        if not conditions:
            logger.warning("[get_user_generation_history] Не указаны параметры для поиска")
            conn.close()
            return []
        
        where_clause = " OR ".join(conditions)
        
        # Получаем только завершенные задачи с результатом
        query = f"""
            SELECT 
                task_id,
                task_type,
                template_id,
                status,
                result_url,
                cost,
                created_at,
                completed_at,
                error_message
            FROM app_generation_tasks
            WHERE ({where_clause}) 
            AND status = 'completed' 
            AND result_url IS NOT NULL 
            AND result_url != ''
            ORDER BY created_at DESC
            LIMIT %s
        """
        params.append(limit)
        
        cursor.execute(query, params)
        rows = cursor.fetchall()
        
        logger.info(f"[get_user_generation_history] Найдено задач: {len(rows)} для user_id={user_id}, vk_user_id={vk_user_id}, device_id={device_id}")
        
        return rows if rows else []
    except Exception as e:
        logger.error(f"[get_user_generation_history] Ошибка получения истории: {e}", exc_info=True)
        return []
    finally:
        conn.close()


def get_balance(telegram_id: int) -> float:
    """Получение баланса"""
    import logging
    # Убеждаемся, что telegram_id является int
    telegram_id = int(telegram_id)
    
    conn = get_connection()
    cursor = conn.cursor()
    cursor.execute("SELECT balance FROM users WHERE telegram_id = %s", (telegram_id,))
    result = cursor.fetchone()
    conn.close()
    
    if result:
        balance = result.get("balance")
        if balance is None:
            logging.warning(f"[get_balance] Баланс None для telegram_id={telegram_id}")
            return 0.0
        balance_float = float(balance)
        logging.debug(f"[get_balance] telegram_id={telegram_id}, balance={balance_float}")
        return balance_float
    else:
        logging.warning(f"[get_balance] Пользователь не найден в БД: telegram_id={telegram_id}")
        return 0.0


def has_completed_payments(telegram_id: int) -> bool:
    """Проверяет, были ли у пользователя успешные платежи"""
    conn = get_connection()
    cursor = conn.cursor()
    cursor.execute(
        "SELECT 1 FROM payments WHERE telegram_id = %s AND status = 'completed' LIMIT 1",
        (telegram_id,)
    )
    result = cursor.fetchone()
    conn.close()
    return result is not None


def get_tts_free_usage(telegram_id: int, usage_date: Optional[date] = None) -> int:
    """Возвращает количество использованных бесплатных символов TTS за день"""
    usage_date = usage_date or datetime.now().date()
    conn = get_connection()
    cursor = conn.cursor()
    cursor.execute(
        "SELECT used_chars FROM tts_free_usage WHERE telegram_id = %s AND usage_date = %s",
        (telegram_id, usage_date)
    )
    result = cursor.fetchone()
    conn.close()
    return int(result["used_chars"]) if result and result.get("used_chars") else 0


def increment_tts_free_usage(telegram_id: int, chars: int, usage_date: Optional[date] = None):
    """Увеличивает счетчик бесплатных символов TTS за день"""
    if chars <= 0:
        return

    usage_date = usage_date or datetime.now().date()
    conn = get_connection()
    cursor = conn.cursor()
    try:
        cursor.execute("""
            INSERT INTO tts_free_usage (telegram_id, usage_date, used_chars)
            VALUES (%s, %s, %s)
            ON DUPLICATE KEY UPDATE used_chars = used_chars + VALUES(used_chars)
        """, (telegram_id, usage_date, chars))
        conn.commit()
    except Exception:
        conn.rollback()
        raise
    finally:
        conn.close()


def get_all_users(include_blocked: bool = False) -> List[Dict[str, Any]]:
    """Возвращает список пользователей (по умолчанию без заблокировавших бота)"""
    conn = get_connection()
    cursor = conn.cursor()
    query = "SELECT telegram_id, username, balance, tag, is_blocked FROM users"
    if not include_blocked:
        query += " WHERE is_blocked = FALSE"
    cursor.execute(query)
    users = cursor.fetchall()
    conn.close()
    return users


def set_user_blocked(telegram_id: int, blocked: bool = True):
    """Обновляет флаг блокировки пользователя"""
    conn = get_connection()
    cursor = conn.cursor()
    cursor.execute(
        "UPDATE users SET is_blocked = %s WHERE telegram_id = %s",
        (blocked, telegram_id)
    )
    conn.commit()
    conn.close()


def update_balance(telegram_id: int, tokens: float):
    """Пополнение баланса токенами"""
    conn = get_connection()
    cursor = conn.cursor()
    cursor.execute("UPDATE users SET balance = balance + %s WHERE telegram_id = %s", (tokens, telegram_id))
    conn.commit()
    conn.close()


def refund_balance(telegram_id: int, tokens: float):
    """Возврат средств на баланс (то же что и update_balance, но с другим названием для ясности)"""
    conn = get_connection()
    cursor = conn.cursor()
    cursor.execute("UPDATE users SET balance = balance + %s WHERE telegram_id = %s", (tokens, telegram_id))
    conn.commit()
    conn.close()


def deduct_balance(telegram_id: int, tokens: float) -> bool:
    """Списание токенов с баланса. Возвращает True, если успешно"""
    try:
        conn = get_connection()
        cursor = conn.cursor()
        cursor.execute("SELECT balance FROM users WHERE telegram_id = %s", (telegram_id,))
        result = cursor.fetchone()

        if result and result["balance"] >= tokens:
            cursor.execute("UPDATE users SET balance = balance - %s WHERE telegram_id = %s", (tokens, telegram_id))
            
            # НЕ начисляем комиссию партнеру здесь - это должно происходить отдельно
            # update_partner_commission(telegram_id, tokens)
            
            conn.commit()
            conn.close()
            return True

        conn.close()
        return False
    except Exception as e:
        print(f"Ошибка при списании баланса: {e}")
        try:
            conn.close()
        except:
            pass
        return False


# === ПАРТНЕРСКАЯ ПРОГРАММА ===

def create_partner_tables():
    """Создание таблиц для партнерской программы"""
    try:
        from partner_tiers import create_partner_tier_table

        create_partner_tier_table()
    except Exception as e:
        print(f"⚠️ [ПАРТНЕР] Таблица тарифов не создана: {e}")

    conn = get_connection()
    cursor = conn.cursor()
    
    # Таблица партнерской программы
    cursor.execute("""
        CREATE TABLE IF NOT EXISTS partner_program (
            id INT AUTO_INCREMENT PRIMARY KEY,
            partner_telegram_id BIGINT NOT NULL,
            partner_username VARCHAR(255) NOT NULL,
            referral_telegram_id BIGINT NOT NULL,
            referral_username VARCHAR(255),
            referral_date DATETIME DEFAULT CURRENT_TIMESTAMP,
            total_purchases DECIMAL(10,2) DEFAULT 0.00,
            partner_commission DECIMAL(10,2) DEFAULT 0.00,
            commission_rate DECIMAL(5,2) DEFAULT 10.00,
            first_payment_received BOOLEAN DEFAULT FALSE,
            status ENUM('active', 'inactive') DEFAULT 'active',
            INDEX idx_partner_id (partner_telegram_id),
            INDEX idx_referral_id (referral_telegram_id),
            INDEX idx_partner_referral (partner_telegram_id, referral_telegram_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    """)
    
    # Таблица логов пользовательских запросов
    cursor.execute("""
        CREATE TABLE IF NOT EXISTS user_requests_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_telegram_id BIGINT NOT NULL,
            user_username VARCHAR(255),
            user_first_name VARCHAR(255),
            request_type ENUM('veo3', 'sora2', 'suno', 'nano_banana', 'tts', 'speech_to_text') NOT NULL,
            model_name VARCHAR(255),
            prompt TEXT,
            image_urls TEXT,
            task_id VARCHAR(255),
            cost DECIMAL(10,2),
            status ENUM('pending', 'completed', 'failed') DEFAULT 'pending',
            api_response TEXT,
            error_message TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            completed_at DATETIME NULL,
            INDEX idx_user (user_telegram_id),
            INDEX idx_task_id (task_id),
            INDEX idx_created_at (created_at),
            INDEX idx_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    """)
    
    # Таблица запросов на вывод
    cursor.execute("""
        CREATE TABLE IF NOT EXISTS withdrawal_requests (
            id INT AUTO_INCREMENT PRIMARY KEY,
            partner_telegram_id BIGINT NOT NULL,
            amount DECIMAL(10,2) NOT NULL,
            payment_method VARCHAR(255) NOT NULL,
            payment_details TEXT NOT NULL,
            status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
            request_date DATETIME DEFAULT CURRENT_TIMESTAMP,
            processed_date DATETIME NULL,
            admin_notes TEXT,
            INDEX idx_partner (partner_telegram_id),
            INDEX idx_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    """)
    
    # Таблица партнерских выплат
    cursor.execute("""
        CREATE TABLE IF NOT EXISTS partner_payments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            partner_telegram_id BIGINT NOT NULL,
            amount DECIMAL(10,2) NOT NULL,
            payment_type ENUM('commission', 'withdrawal') NOT NULL,
            description TEXT,
            transaction_date DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_partner (partner_telegram_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    """)
    
    # Добавляем поле first_payment_received если его нет
    try:
        cursor.execute("""
            ALTER TABLE partner_program 
            ADD COLUMN first_payment_received BOOLEAN DEFAULT FALSE
        """)
    except Exception as e:
        # Поле уже существует, игнорируем ошибку
        if "Duplicate column name" not in str(e):
            raise e
    
    # Обновляем существующие записи с commission_rate = 30.00
    # Для vlad_myrsin устанавливаем 15%, для остальных 10%
    cursor.execute("""
        UPDATE partner_program 
        SET commission_rate = CASE 
            WHEN partner_username = 'vlad_myrsin' THEN 15.00
            ELSE 10.00
        END
        WHERE commission_rate = 30.00
    """)
    
    conn.commit()
    conn.close()


def add_referral(partner_telegram_id: int, partner_username: str, referral_telegram_id: int, referral_username: str = None):
    """Добавление реферала к партнеру"""
    print(f"🔗 [РЕФЕРАЛ] Добавление реферала: партнер {partner_telegram_id} (@{partner_username}) -> реферал {referral_telegram_id} (@{referral_username})")
    
    conn = get_connection()
    cursor = conn.cursor()
    
    # Проверяем, не является ли реферал уже партнером
    cursor.execute("SELECT id FROM partner_program WHERE partner_telegram_id = %s AND referral_telegram_id = %s", 
                   (partner_telegram_id, referral_telegram_id))
    if cursor.fetchone():
        print(f"⚠️ [РЕФЕРАЛ] Реферал {referral_telegram_id} уже существует у партнера {partner_telegram_id}")
        conn.close()
        return False
    
    # Ставка зависит от тарифа партнёра: у вебмастеров она выше.
    # В записи храним ставку для повторных покупок; с первого депозита
    # процент считается отдельно в update_partner_commission.
    try:
        from partner_tiers import get_tier

        commission_rate = get_tier(partner_telegram_id)["revshare_rate"]
    except Exception as e:
        print(f"⚠️ [РЕФЕРАЛ] Тариф партнёра не прочитан ({e}), берём стандартный")
        commission_rate = 10.00
    
    cursor.execute("""
        INSERT INTO partner_program (partner_telegram_id, partner_username, referral_telegram_id, referral_username, commission_rate)
        VALUES (%s, %s, %s, %s, %s)
    """, (partner_telegram_id, partner_username, referral_telegram_id, referral_username, commission_rate))
    
    print(f"✅ [РЕФЕРАЛ] Реферал успешно добавлен: партнер {partner_telegram_id} (@{partner_username}), комиссия {commission_rate}%")
    
    conn.commit()
    conn.close()
    return True


def get_partner_stats(partner_telegram_id: int) -> dict:
    """Получение статистики партнера"""
    conn = get_connection()
    cursor = conn.cursor()
    
    # Количество рефералов
    cursor.execute("SELECT COUNT(*) as total_referrals FROM partner_program WHERE partner_telegram_id = %s", (partner_telegram_id,))
    total_referrals = cursor.fetchone()["total_referrals"]
    
    # Количество получивших тестовый ключ (пока 0, можно расширить логику)
    test_keys = 0
    
    # Общая сумма покупок рефералов
    cursor.execute("SELECT SUM(total_purchases) as total_purchases FROM partner_program WHERE partner_telegram_id = %s", (partner_telegram_id,))
    result = cursor.fetchone()
    total_purchases = float(result["total_purchases"]) if result["total_purchases"] else 0.0
    
    # Общая комиссия партнера
    cursor.execute("SELECT SUM(partner_commission) as total_commission FROM partner_program WHERE partner_telegram_id = %s", (partner_telegram_id,))
    result = cursor.fetchone()
    total_commission = float(result["total_commission"]) if result["total_commission"] else 0.0
    
    # Выведенные средства
    cursor.execute("""
        SELECT SUM(amount) as total_withdrawn 
        FROM withdrawal_requests 
        WHERE partner_telegram_id = %s AND status = 'approved'
    """, (partner_telegram_id,))
    result = cursor.fetchone()
    total_withdrawn = float(result["total_withdrawn"]) if result["total_withdrawn"] else 0.0
    
    # Доступно к выводу
    available_for_withdrawal = total_commission - total_withdrawn
    
    conn.close()
    
    return {
        "total_referrals": total_referrals,
        "test_keys": test_keys,
        "total_purchases": total_purchases,
        "total_commission": total_commission,
        "total_withdrawn": total_withdrawn,
        "available_for_withdrawal": available_for_withdrawal
    }


def update_partner_commission(partner_telegram_id: int, purchase_amount: float):
    """Обновление комиссии партнера при покупке реферала"""
    try:
        print(f"🤝 [ПАРТНЕР] Начинаем обработку комиссии для пользователя {partner_telegram_id}, сумма покупки: {purchase_amount}₽")
        
        conn = get_connection()
        cursor = conn.cursor()
        
        # Находим партнера для данного пользователя
        print(f"🔍 [ПАРТНЕР] Ищем партнера для referral_telegram_id = {partner_telegram_id}")
        cursor.execute("""
            SELECT partner_telegram_id, commission_rate, first_payment_received, partner_username, referral_username
            FROM partner_program 
            WHERE referral_telegram_id = %s AND status = 'active'
        """, (partner_telegram_id,))
        
        partner_info = cursor.fetchone()
        
        # Дополнительная отладка - показываем все записи для этого пользователя
        cursor.execute("""
            SELECT partner_telegram_id, partner_username, referral_telegram_id, referral_username, status, first_payment_received
            FROM partner_program 
            WHERE referral_telegram_id = %s
        """, (partner_telegram_id,))
        all_records = cursor.fetchall()
        print(f"🔍 [ПАРТНЕР] Все записи для пользователя {partner_telegram_id}: {all_records}")
        print(f"🔍 [ПАРТНЕР] Найденный партнер: {partner_info}")
        if not partner_info:
            print(f"❌ [ПАРТНЕР] Партнер не найден для пользователя {partner_telegram_id}")
            conn.close()
            return False
        
        partner_id = partner_info["partner_telegram_id"]
        partner_username = partner_info["partner_username"]
        referral_username = partner_info["referral_username"]
        first_payment_received = partner_info["first_payment_received"]
        
        print(f"✅ [ПАРТНЕР] Найден партнер: ID={partner_id}, username={partner_username}, first_payment={first_payment_received}")
        
        # Ставка зависит от тарифа партнёра и от того, первая ли это покупка
        commission_rate = None
        try:
            from partner_tiers import commission_rate as tier_rate, get_tier

            tier = get_tier(partner_id)
            if tier["tier"] != "standard":
                commission_rate = tier_rate(tier, not first_payment_received)
                print(f"⭐ [ПАРТНЕР] Тариф {tier['tier']}: "
                      f"{'первый депозит' if not first_payment_received else 'повторная покупка'}, "
                      f"комиссия {commission_rate}%")
        except Exception as e:
            print(f"⚠️ [ПАРТНЕР] Тариф не прочитан ({e}), берём ставку из записи")

        if commission_rate is None:
            if partner_username == 'vlad_myrsin' and not first_payment_received:
                commission_rate = 15.00
                print(f"⭐ [ПАРТНЕР] vlad_myrsin - первый платеж, повышенная комиссия 15%")
            else:
                commission_rate = partner_info["commission_rate"]
                print(f"📊 [ПАРТНЕР] Стандартная комиссия: {commission_rate}%")
            
        commission_amount = purchase_amount * (commission_rate / 100)
        print(f"💰 [ПАРТНЕР] Расчет: {purchase_amount}₽ × {commission_rate}% = {commission_amount}₽")
        
        # Обновляем данные партнера
        cursor.execute("""
            UPDATE partner_program 
            SET total_purchases = total_purchases + %s,
                partner_commission = partner_commission + %s,
                first_payment_received = TRUE
            WHERE partner_telegram_id = %s AND referral_telegram_id = %s
        """, (purchase_amount, commission_amount, partner_id, partner_telegram_id))
        
        print(f"📈 [ПАРТНЕР] Обновлена статистика партнера {partner_id}")
        
        # Начисляем комиссию на баланс партнера
        cursor.execute("UPDATE users SET balance = balance + %s WHERE telegram_id = %s", (commission_amount, partner_id))
        
        print(f"💳 [ПАРТНЕР] Начислено {commission_amount}₽ на баланс партнера {partner_id}")
        
        # Записываем транзакцию
        payment_type = "first_payment" if partner_username == 'vlad_myrsin' and not first_payment_received else "commission"
        description = f"Комиссия {commission_rate}% с покупки реферала на сумму {purchase_amount}₽"
        if partner_username == 'vlad_myrsin' and not first_payment_received:
            description += " (первый платеж - повышенная комиссия)"
            
        cursor.execute("""
            INSERT INTO partner_payments (partner_telegram_id, amount, payment_type, description)
            VALUES (%s, %s, %s, %s)
        """, (partner_id, commission_amount, payment_type, description))
        
        print(f"📝 [ПАРТНЕР] Записана транзакция: {payment_type}, {commission_amount}₽, {description}")
        
        conn.commit()
        conn.close()
        
        print(f"✅ [ПАРТНЕР] Комиссия успешно начислена партнеру {partner_id} ({partner_username}): {commission_amount}₽")
        
        # Отправляем уведомление партнеру
        try:
            import asyncio
            from kie_api import send_telegram_message
            
            notification_text = (
                f"💰 <b>Начислена партнерская комиссия!</b>\n\n"
                f"👤 Реферал: @{referral_username or 'пользователь'}\n"
                f"💵 Сумма покупки: {purchase_amount}₽\n"
                f"📊 Комиссия: {commission_rate}%\n"
                f"💰 Начислено: {commission_amount}₽\n"
                f"📅 Тип: {payment_type}\n\n"
                f"💳 Ваш баланс пополнен на {commission_amount}₽"
            )
            
            # Запускаем отправку уведомления асинхронно
            asyncio.create_task(send_telegram_message(
                chat_id=partner_id,
                text=notification_text
            ))
            print(f"📱 [ПАРТНЕР] Отправлено уведомление партнеру {partner_id}")
            
        except Exception as e:
            print(f"⚠️ [ПАРТНЕР] Ошибка отправки уведомления партнеру: {e}")
        
        return True
        
    except Exception as e:
        print(f"❌ [ПАРТНЕР] Ошибка обновления комиссии партнера: {e}")
        try:
            conn.close()
        except:
            pass
        return False


def create_withdrawal_request(partner_telegram_id: int, amount: float, payment_method: str, payment_details: str) -> bool:
    """Создание запроса на вывод средств"""
    print(f"💸 [ВЫВОД] Создание запроса на вывод: партнер {partner_telegram_id}, сумма {amount}₽, метод {payment_method}")
    
    conn = get_connection()
    cursor = conn.cursor()
    
    # Проверяем доступную сумму
    stats = get_partner_stats(partner_telegram_id)
    if stats["available_for_withdrawal"] < amount:
        print(f"❌ [ВЫВОД] Недостаточно средств для вывода: доступно {stats['available_for_withdrawal']}₽, запрошено {amount}₽")
        conn.close()
        return False
    
    cursor.execute("""
        INSERT INTO withdrawal_requests (partner_telegram_id, amount, payment_method, payment_details)
        VALUES (%s, %s, %s, %s)
    """, (partner_telegram_id, amount, payment_method, payment_details))
    
    print(f"✅ [ВЫВОД] Запрос на вывод создан: партнер {partner_telegram_id}, сумма {amount}₽")
    
    conn.commit()
    conn.close()
    return True


def get_withdrawal_requests(status: str = None) -> list:
    """Получение запросов на вывод (для админа)"""
    conn = get_connection()
    cursor = conn.cursor()
    
    if status:
        cursor.execute("""
            SELECT wr.*, u.username, u.telegram_id 
            FROM withdrawal_requests wr
            JOIN users u ON wr.partner_telegram_id = u.telegram_id
            WHERE wr.status = %s
            ORDER BY wr.request_date DESC
        """, (status,))
    else:
        cursor.execute("""
            SELECT wr.*, u.username, u.telegram_id 
            FROM withdrawal_requests wr
            JOIN users u ON wr.partner_telegram_id = u.telegram_id
            ORDER BY wr.request_date DESC
        """)
    
    results = cursor.fetchall()
    conn.close()
    return results


def update_withdrawal_status(partner_telegram_id: int, amount: float, status: str, admin_notes: str = None) -> bool:
    """Обновление статуса запроса на вывод"""
    print(f"🔄 [ВЫВОД] Обновление статуса вывода: партнер {partner_telegram_id}, сумма {amount}₽, статус {status}")
    
    conn = get_connection()
    cursor = conn.cursor()
    
    try:
        # Обновляем статус запроса
        cursor.execute("""
            UPDATE withdrawal_requests 
            SET status = %s, processed_date = NOW(), admin_notes = %s
            WHERE partner_telegram_id = %s AND amount = %s AND status = 'pending'
        """, (status, admin_notes, partner_telegram_id, amount))
        
        if cursor.rowcount == 0:
            print(f"❌ [ВЫВОД] Запрос на вывод не найден или уже обработан")
            conn.close()
            return False
        
        # Если статус "approved", списываем средства с баланса партнера
        if status == 'approved':
            print(f"✅ [ВЫВОД] Одобрение вывода средств для партнера {partner_telegram_id}")
            
            # Проверяем, что у партнера достаточно средств
            cursor.execute("SELECT balance FROM users WHERE telegram_id = %s", (partner_telegram_id,))
            result = cursor.fetchone()
            
            if result and result["balance"] >= amount:
                # Списываем средства
                cursor.execute("UPDATE users SET balance = balance - %s WHERE telegram_id = %s", (amount, partner_telegram_id))
                print(f"💳 [ВЫВОД] Списано {amount}₽ с баланса партнера {partner_telegram_id}")
                
                # Записываем транзакцию вывода
                cursor.execute("""
                    INSERT INTO partner_payments (partner_telegram_id, amount, payment_type, description)
                    VALUES (%s, %s, 'withdrawal', %s)
                """, (partner_telegram_id, amount, f"Вывод средств: {amount}₽"))
                print(f"📝 [ВЫВОД] Записана транзакция вывода: {amount}₽")
            else:
                print(f"❌ [ВЫВОД] Недостаточно средств на балансе партнера {partner_telegram_id}")
                conn.rollback()
                conn.close()
                return False
        elif status == 'rejected':
            print(f"❌ [ВЫВОД] Отклонение вывода средств для партнера {partner_telegram_id}")
        
        conn.commit()
        conn.close()
        print(f"✅ [ВЫВОД] Статус вывода успешно обновлен: {status}")
        return True
        
    except Exception as e:
        conn.rollback()
        conn.close()
        print(f"❌ [ВЫВОД] Ошибка обновления статуса вывода: {e}")
        return False


def get_partner_payment_history(partner_telegram_id: int, limit: int = 20) -> list:
    """Получение истории выплат партнера"""
    conn = get_connection()
    cursor = conn.cursor()
    
    try:
        # Получаем историю комиссий и выводов
        cursor.execute("""
            SELECT 
                amount,
                payment_type,
                description,
                transaction_date,
                'payment' as source
            FROM partner_payments 
            WHERE partner_telegram_id = %s
            ORDER BY transaction_date DESC
            LIMIT %s
        """, (partner_telegram_id, limit))
        
        payments = cursor.fetchall()
        
        # Получаем историю запросов на вывод
        cursor.execute("""
            SELECT 
                amount,
                'withdrawal_request' as payment_type,
                CONCAT('Запрос на вывод: ', payment_method) as description,
                request_date as transaction_date,
                status,
                'withdrawal' as source
            FROM withdrawal_requests 
            WHERE partner_telegram_id = %s
            ORDER BY request_date DESC
            LIMIT %s
        """, (partner_telegram_id, limit))
        
        withdrawals = cursor.fetchall()
        
        # Объединяем и сортируем по дате
        all_transactions = list(payments) + list(withdrawals)
        all_transactions.sort(key=lambda x: x['transaction_date'], reverse=True)
        
        conn.close()
        return all_transactions[:limit]
        
    except Exception as e:
        print(f"Ошибка получения истории выплат: {e}")
        conn.close()
        return []


# === ЛОГИРОВАНИЕ ЗАПРОСОВ ===

def log_user_request(user_telegram_id: int, user_username: str, user_first_name: str, 
                    request_type: str, model_name: str, prompt: str, image_urls: str = None, 
                    task_id: str = None, cost: float = 0.0, api_response: str = None) -> int:
    """Логирование запроса пользователя"""
    conn = get_connection()
    cursor = conn.cursor()
    
    try:
        cursor.execute("""
            INSERT INTO user_requests_log 
            (user_telegram_id, user_username, user_first_name, request_type, model_name, 
             prompt, image_urls, task_id, cost, api_response)
            VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
        """, (user_telegram_id, user_username, user_first_name, request_type, model_name, 
              prompt, image_urls, task_id, cost, api_response))
        
        log_id = cursor.lastrowid
        conn.commit()
        conn.close()
        return log_id
        
    except Exception as e:
        print(f"Ошибка логирования запроса: {e}")
        conn.close()
        return 0


def update_request_status(task_id: str, status: str, error_message: str = None):
    """Обновление статуса запроса по task_id"""
    conn = get_connection()
    cursor = conn.cursor()
    
    try:
        completed_at = "NOW()" if status in ['completed', 'failed'] else "NULL"
        cursor.execute(f"""
            UPDATE user_requests_log 
            SET status = %s, error_message = %s, completed_at = {completed_at}
            WHERE task_id = %s
        """, (status, error_message, task_id))
        
        conn.commit()
        conn.close()
        return True
        
    except Exception as e:
        print(f"Ошибка обновления статуса запроса: {e}")
        conn.close()
        return False


def get_user_requests_log(user_telegram_id: int = None, limit: int = 50) -> list:
    """Получение логов запросов (для админа или конкретного пользователя)"""
    conn = get_connection()
    cursor = conn.cursor()
    
    try:
        if user_telegram_id:
            cursor.execute("""
                SELECT * FROM user_requests_log 
                WHERE user_telegram_id = %s 
                ORDER BY created_at DESC 
                LIMIT %s
            """, (user_telegram_id, limit))
        else:
            cursor.execute("""
                SELECT * FROM user_requests_log 
                ORDER BY created_at DESC 
                LIMIT %s
            """, (limit,))
        
        results = cursor.fetchall()
        conn.close()
        return results
        
    except Exception as e:
        print(f"Ошибка получения логов запросов: {e}")
        conn.close()
        return []


# === АДМИНСКАЯ СТАТИСТИКА ===

def get_users_stats(period: str) -> dict:
    """Получение статистики пользователей за период"""
    conn = get_connection()
    cursor = conn.cursor()
    
    try:
        if period == "day":
            date_filter = "DATE(created_at) = CURDATE()"
        elif period == "week":
            date_filter = "created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)"
        elif period == "month":
            date_filter = "created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)"
        else:
            date_filter = "1=1"
        
        # Общее количество пользователей
        cursor.execute(f"SELECT COUNT(*) as total FROM users WHERE {date_filter}")
        total_users = cursor.fetchone()["total"]
        
        # Новые пользователи
        cursor.execute(f"SELECT COUNT(*) as new FROM users WHERE {date_filter}")
        new_users = cursor.fetchone()["new"]
        
        # Активные пользователи (с генерациями)
        cursor.execute(f"""
            SELECT COUNT(DISTINCT user_telegram_id) as active 
            FROM user_requests_log 
            WHERE {date_filter.replace('created_at', 'created_at')}
        """)
        active_users = cursor.fetchone()["active"]
        
        conn.close()
        return {
            "total_users": total_users,
            "new_users": new_users,
            "active_users": active_users
        }
    except Exception as e:
        print(f"Ошибка получения статистики пользователей: {e}")
        conn.close()
        return {"total_users": 0, "new_users": 0, "active_users": 0}


def get_generations_stats(period: str) -> dict:
    """Получение статистики генераций за период"""
    conn = get_connection()
    cursor = conn.cursor()
    
    try:
        if period == "day":
            date_filter = "DATE(created_at) = CURDATE()"
        elif period == "week":
            date_filter = "created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)"
        elif period == "month":
            date_filter = "created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)"
        else:
            date_filter = "1=1"
        
        # Статистика по типам генераций
        cursor.execute(f"""
            SELECT 
                request_type,
                COUNT(*) as count,
                SUM(cost) as total_cost
            FROM user_requests_log 
            WHERE {date_filter}
            GROUP BY request_type
            ORDER BY count DESC
        """)
        
        generations = cursor.fetchall()
        
        # Общая статистика
        cursor.execute(f"""
            SELECT 
                COUNT(*) as total_generations,
                SUM(cost) as total_revenue
            FROM user_requests_log 
            WHERE {date_filter}
        """)
        
        total_stats = cursor.fetchone()
        
        conn.close()
        return {
            "generations_by_type": generations,
            "total_generations": total_stats["total_generations"] or 0,
            "total_revenue": total_stats["total_revenue"] or 0
        }
    except Exception as e:
        print(f"Ошибка получения статистики генераций: {e}")
        conn.close()
        return {"generations_by_type": [], "total_generations": 0, "total_revenue": 0}


def get_payments_stats(period: str) -> dict:
    """Получение статистики платежей за период"""
    conn = get_connection()
    cursor = conn.cursor()
    
    try:
        if period == "day":
            date_filter = "DATE(created_at) = CURDATE()"
        elif period == "week":
            date_filter = "created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)"
        elif period == "month":
            date_filter = "created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)"
        else:
            date_filter = "1=1"
        
        # Статистика платежей
        cursor.execute(f"""
            SELECT 
                COUNT(*) as total_payments,
                SUM(amount) as total_amount,
                SUM(tokens) as total_tokens
            FROM payments 
            WHERE status = 'completed' AND {date_filter}
        """)
        
        stats = cursor.fetchone()
        
        # Последние платежи
        cursor.execute(f"""
            SELECT p.*, u.username, u.first_name
            FROM payments p
            LEFT JOIN users u ON p.telegram_id = u.telegram_id
            WHERE p.status = 'completed' AND {date_filter}
            ORDER BY p.created_at DESC
            LIMIT 10
        """)
        
        recent_payments = cursor.fetchall()
        
        conn.close()
        return {
            "total_payments": stats["total_payments"] or 0,
            "total_amount": stats["total_amount"] or 0,
            "total_tokens": stats["total_tokens"] or 0,
            "recent_payments": recent_payments
        }
    except Exception as e:
        print(f"Ошибка получения статистики платежей: {e}")
        conn.close()
        return {"total_payments": 0, "total_amount": 0, "total_tokens": 0, "recent_payments": []}


# === ПАПКИ С КАНАЛАМИ ===

def create_folder(name: str, description: str = None, price: float = 0.0) -> int:
    """Создание новой папки с каналами"""
    conn = get_connection()
    cursor = conn.cursor()
    try:
        cursor.execute("""
            INSERT INTO folders (name, description, price)
            VALUES (%s, %s, %s)
        """, (name, description, price))
        folder_id = cursor.lastrowid
        conn.commit()
        conn.close()
        return folder_id
    except Exception as e:
        conn.rollback()
        conn.close()
        print(f"Ошибка создания папки: {e}")
        return 0


def update_folder(folder_id: int, name: str = None, description: str = None, price: float = None, is_active: bool = None) -> bool:
    """Обновление папки"""
    conn = get_connection()
    cursor = conn.cursor()
    try:
        updates = []
        params = []
        
        if name is not None:
            updates.append("name = %s")
            params.append(name)
        if description is not None:
            updates.append("description = %s")
            params.append(description)
        if price is not None:
            updates.append("price = %s")
            params.append(price)
        if is_active is not None:
            updates.append("is_active = %s")
            params.append(is_active)
        
        if not updates:
            conn.close()
            return False
        
        params.append(folder_id)
        query = f"UPDATE folders SET {', '.join(updates)} WHERE id = %s"
        cursor.execute(query, params)
        conn.commit()
        conn.close()
        return cursor.rowcount > 0
    except Exception as e:
        conn.rollback()
        conn.close()
        print(f"Ошибка обновления папки: {e}")
        return False


def delete_folder(folder_id: int) -> bool:
    """Удаление папки (каскадно удалит каналы и подписки)"""
    conn = get_connection()
    cursor = conn.cursor()
    try:
        cursor.execute("DELETE FROM folders WHERE id = %s", (folder_id,))
        conn.commit()
        conn.close()
        return cursor.rowcount > 0
    except Exception as e:
        conn.rollback()
        conn.close()
        print(f"Ошибка удаления папки: {e}")
        return False


def get_all_folders(include_inactive: bool = False) -> list:
    """Получение списка всех папок"""
    conn = get_connection()
    cursor = conn.cursor()
    try:
        if include_inactive:
            cursor.execute("SELECT * FROM folders ORDER BY created_at DESC")
        else:
            cursor.execute("SELECT * FROM folders WHERE is_active = TRUE ORDER BY created_at DESC")
        folders = cursor.fetchall()
        conn.close()
        return folders
    except Exception as e:
        print(f"Ошибка получения папок: {e}")
        conn.close()
        return []


def get_folder_by_id(folder_id: int) -> dict:
    """Получение папки по ID"""
    conn = get_connection()
    cursor = conn.cursor()
    try:
        cursor.execute("SELECT * FROM folders WHERE id = %s", (folder_id,))
        folder = cursor.fetchone()
        conn.close()
        return folder
    except Exception as e:
        print(f"Ошибка получения папки: {e}")
        conn.close()
        return None


def add_channel_to_folder(folder_id: int, channel_username: str, channel_link: str = None, channel_title: str = None) -> bool:
    """Добавление канала в папку"""
    conn = get_connection()
    cursor = conn.cursor()
    try:
        cursor.execute("""
            INSERT INTO folder_channels (folder_id, channel_username, channel_link, channel_title)
            VALUES (%s, %s, %s, %s)
        """, (folder_id, channel_username, channel_link, channel_title))
        conn.commit()
        conn.close()
        return True
    except Exception as e:
        conn.rollback()
        conn.close()
        print(f"Ошибка добавления канала в папку: {e}")
        return False


def remove_channel_from_folder(channel_id: int) -> bool:
    """Удаление канала из папки"""
    conn = get_connection()
    cursor = conn.cursor()
    try:
        cursor.execute("DELETE FROM folder_channels WHERE id = %s", (channel_id,))
        conn.commit()
        conn.close()
        return cursor.rowcount > 0
    except Exception as e:
        conn.rollback()
        conn.close()
        print(f"Ошибка удаления канала из папки: {e}")
        return False


def get_folder_channels(folder_id: int) -> list:
    """Получение всех каналов в папке"""
    conn = get_connection()
    cursor = conn.cursor()
    try:
        cursor.execute("SELECT * FROM folder_channels WHERE folder_id = %s ORDER BY created_at", (folder_id,))
        channels = cursor.fetchall()
        conn.close()
        return channels
    except Exception as e:
        print(f"Ошибка получения каналов папки: {e}")
        conn.close()
        return []


def subscribe_user_to_folder(user_id: int, folder_id: int, payment_amount: float) -> bool:
    """Подписка пользователя на папку"""
    conn = get_connection()
    cursor = conn.cursor()
    try:
        cursor.execute("""
            INSERT INTO user_folder_subscriptions (user_id, folder_id, payment_amount)
            VALUES (%s, %s, %s)
            ON DUPLICATE KEY UPDATE payment_amount = VALUES(payment_amount)
        """, (user_id, folder_id, payment_amount))
        conn.commit()
        conn.close()
        return True
    except Exception as e:
        conn.rollback()
        conn.close()
        print(f"Ошибка подписки пользователя на папку: {e}")
        return False


def is_user_subscribed_to_folder(user_id: int, folder_id: int) -> bool:
    """Проверка подписки пользователя на папку"""
    conn = get_connection()
    cursor = conn.cursor()
    try:
        cursor.execute("""
            SELECT 1 FROM user_folder_subscriptions 
            WHERE user_id = %s AND folder_id = %s
            LIMIT 1
        """, (user_id, folder_id))
        result = cursor.fetchone()
        conn.close()
        return result is not None
    except Exception as e:
        print(f"Ошибка проверки подписки: {e}")
        conn.close()
        return False


def get_user_folder_subscriptions(user_id: int) -> list:
    """Получение всех подписок пользователя на папки"""
    conn = get_connection()
    cursor = conn.cursor()
    try:
        cursor.execute("""
            SELECT f.*, ufs.subscription_date, ufs.payment_amount
            FROM user_folder_subscriptions ufs
            JOIN folders f ON ufs.folder_id = f.id
            WHERE ufs.user_id = %s
            ORDER BY ufs.subscription_date DESC
        """, (user_id,))
        subscriptions = cursor.fetchall()
        conn.close()
        return subscriptions
    except Exception as e:
        print(f"Ошибка получения подписок пользователя: {e}")
        conn.close()
        return []


# Функции для работы с токенами восстановления пароля
def create_password_reset_token(user_id: int, token: str, expires_in_hours: int = 1) -> bool:
    """Создание токена для восстановления пароля"""
    import logging
    from datetime import datetime, timedelta
    logger = logging.getLogger(__name__)
    
    conn = get_connection()
    cursor = conn.cursor()
    try:
        expires_at = datetime.now() + timedelta(hours=expires_in_hours)
        cursor.execute("""
            INSERT INTO password_reset_tokens (user_id, token, expires_at)
            VALUES (%s, %s, %s)
        """, (user_id, token, expires_at))
        conn.commit()
        conn.close()
        logger.info(f"Создан токен восстановления пароля для user_id={user_id}")
        return True
    except Exception as e:
        conn.rollback()
        conn.close()
        logger.error(f"Ошибка создания токена восстановления пароля: {e}", exc_info=True)
        return False


def get_password_reset_token(token: str) -> Optional[Dict[str, Any]]:
    """Получение токена восстановления пароля"""
    import logging
    from datetime import datetime
    logger = logging.getLogger(__name__)
    
    conn = get_connection()
    cursor = conn.cursor()
    try:
        cursor.execute("""
            SELECT id, user_id, token, expires_at, used, created_at
            FROM password_reset_tokens
            WHERE token = %s
        """, (token,))
        result = cursor.fetchone()
        conn.close()
        
        if result:
            # Проверяем, не истек ли токен
            expires_at = result.get("expires_at")
            if isinstance(expires_at, str):
                from datetime import datetime
                expires_at = datetime.fromisoformat(expires_at.replace('Z', '+00:00'))
            
            if datetime.now() > expires_at:
                logger.warning(f"Токен восстановления пароля истек: token={token}")
                return None
            
            if result.get("used"):
                logger.warning(f"Токен восстановления пароля уже использован: token={token}")
                return None
            
            return result
        return None
    except Exception as e:
        logger.error(f"Ошибка получения токена восстановления пароля: {e}", exc_info=True)
        conn.close()
        return None


def mark_password_reset_token_as_used(token: str) -> bool:
    """Отметить токен как использованный"""
    import logging
    logger = logging.getLogger(__name__)
    
    conn = get_connection()
    cursor = conn.cursor()
    try:
        cursor.execute("""
            UPDATE password_reset_tokens
            SET used = TRUE
            WHERE token = %s
        """, (token,))
        conn.commit()
        conn.close()
        logger.info(f"Токен восстановления пароля отмечен как использованный: token={token}")
        return cursor.rowcount > 0
    except Exception as e:
        conn.rollback()
        conn.close()
        logger.error(f"Ошибка отметки токена как использованного: {e}", exc_info=True)
        return False


def update_user_password(user_id: int, new_password_hash: str) -> bool:
    """Обновление пароля пользователя"""
    import logging
    logger = logging.getLogger(__name__)
    
    conn = get_connection()
    cursor = conn.cursor()
    try:
        cursor.execute("""
            UPDATE users
            SET password_hash = %s
            WHERE id = %s
        """, (new_password_hash, user_id))
        conn.commit()
        conn.close()
        logger.info(f"Пароль обновлен для user_id={user_id}")
        return cursor.rowcount > 0
    except Exception as e:
        conn.rollback()
        conn.close()
        logger.error(f"Ошибка обновления пароля: {e}", exc_info=True)
        return False