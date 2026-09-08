"""
Тарифы партнёрской программы.

Обычный партнёр получает 10% со всех покупок приведённого пользователя.
Вебмастер, пришедший по особой ссылке, — 50% с первого депозита реферала
и 20% со всех последующих.

Тариф закрепляется за партнёром в момент перехода по ссылке вида
https://t.me/<бот>?start=wm_<никнейм>, где никнейм — метка источника,
по которой видно, откуда пришёл вебмастер.
"""

import logging
from typing import Optional

logger = logging.getLogger("partner_tiers")

# Ставки по тарифам: (процент с первого депозита, процент со следующих)
TIERS = {
    "standard": (10.0, 10.0),
    "webmaster": (50.0, 20.0),
}
DEFAULT_TIER = "standard"

# Префикс ссылки, по которой партнёр получает тариф вебмастера
WEBMASTER_PREFIX = "wm_"


def create_partner_tier_table() -> None:
    """Таблица тарифов. Вызывается при старте вместе с остальными."""
    from database import get_connection

    conn = get_connection()
    try:
        with conn.cursor() as cursor:
            cursor.execute("""
                CREATE TABLE IF NOT EXISTS partner_tiers (
                    partner_telegram_id BIGINT PRIMARY KEY,
                    partner_username VARCHAR(255),
                    tier VARCHAR(32) NOT NULL DEFAULT 'standard',
                    first_deposit_rate DECIMAL(5,2) NOT NULL DEFAULT 10.00,
                    revshare_rate DECIMAL(5,2) NOT NULL DEFAULT 10.00,
                    source VARCHAR(255),
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_tier (tier),
                    INDEX idx_source (source)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            """)
        conn.commit()
    finally:
        conn.close()


def parse_webmaster_payload(param: str) -> Optional[str]:
    """Метка источника из параметра /start, если это ссылка для вебмастеров."""
    if not param or not param.startswith(WEBMASTER_PREFIX):
        return None
    source = param[len(WEBMASTER_PREFIX):].strip()
    return source or None


def set_tier(partner_telegram_id: int, partner_username: str = None,
             tier: str = "webmaster", source: str = None) -> bool:
    """Закрепляет тариф за партнёром. Повторный переход по ссылке тариф
    не понижает: если вебмастер уже зарегистрирован, запись не трогаем."""
    from database import get_connection

    first_rate, revshare_rate = TIERS.get(tier, TIERS[DEFAULT_TIER])
    conn = get_connection()
    try:
        with conn.cursor() as cursor:
            cursor.execute(
                "SELECT tier FROM partner_tiers WHERE partner_telegram_id = %s",
                (partner_telegram_id,),
            )
            existing = cursor.fetchone()
            if existing and existing["tier"] == tier:
                return False

            cursor.execute("""
                INSERT INTO partner_tiers
                    (partner_telegram_id, partner_username, tier,
                     first_deposit_rate, revshare_rate, source)
                VALUES (%s, %s, %s, %s, %s, %s)
                ON DUPLICATE KEY UPDATE
                    partner_username = VALUES(partner_username),
                    tier = VALUES(tier),
                    first_deposit_rate = VALUES(first_deposit_rate),
                    revshare_rate = VALUES(revshare_rate),
                    source = VALUES(source)
            """, (partner_telegram_id, partner_username, tier,
                  first_rate, revshare_rate, source))
        conn.commit()
        logger.info(
            "[партнёрка] %s (%s) получил тариф %s: %s%% с первого депозита, "
            "%s%% далее, источник %s",
            partner_telegram_id, partner_username, tier, first_rate, revshare_rate, source,
        )
        return True
    except Exception as e:
        logger.error("[партнёрка] не удалось закрепить тариф за %s: %s",
                     partner_telegram_id, e)
        return False
    finally:
        conn.close()


def get_tier(partner_telegram_id: int) -> dict:
    """Тариф партнёра. Для незарегистрированных — стандартный."""
    from database import get_connection

    first_rate, revshare_rate = TIERS[DEFAULT_TIER]
    default = {
        "tier": DEFAULT_TIER,
        "first_deposit_rate": first_rate,
        "revshare_rate": revshare_rate,
        "source": None,
    }

    try:
        conn = get_connection()
        try:
            with conn.cursor() as cursor:
                cursor.execute("""
                    SELECT tier, first_deposit_rate, revshare_rate, source
                    FROM partner_tiers WHERE partner_telegram_id = %s
                """, (partner_telegram_id,))
                row = cursor.fetchone()
        finally:
            conn.close()
    except Exception as e:
        logger.error("[партнёрка] не удалось прочитать тариф %s: %s",
                     partner_telegram_id, e)
        return default

    if not row:
        return default
    return {
        "tier": row["tier"],
        "first_deposit_rate": float(row["first_deposit_rate"]),
        "revshare_rate": float(row["revshare_rate"]),
        "source": row["source"],
    }


def commission_rate(tier: dict, is_first_payment: bool) -> float:
    """Ставка для конкретной покупки: с первого депозита одна, дальше другая."""
    return tier["first_deposit_rate"] if is_first_payment else tier["revshare_rate"]


def describe(tier: dict) -> str:
    """Человеческое описание тарифа для сообщений в боте."""
    if tier["first_deposit_rate"] == tier["revshare_rate"]:
        return f"{tier['revshare_rate']:.0f}% с каждой покупки"
    return (f"{tier['first_deposit_rate']:.0f}% с первого депозита "
            f"и {tier['revshare_rate']:.0f}% со всех последующих")
