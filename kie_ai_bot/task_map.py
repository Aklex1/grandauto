# task_map.py
# taskId -> (chat_id, cost)
# Использует Redis для хранения (с fallback на память если Redis недоступен)
import json
from typing import Optional, Tuple

# Fallback на память если Redis недоступен
_task_chat_map_fallback = {}

# TTL для задач - 24 часа
TASK_TTL = 86400


def _get_redis_client():
    """Получение Redis клиента"""
    try:
        import redis
        from config import REDIS_HOST, REDIS_PORT, REDIS_DB, REDIS_PASSWORD
        return redis.Redis(
            host=REDIS_HOST,
            port=REDIS_PORT,
            db=REDIS_DB,
            password=REDIS_PASSWORD if REDIS_PASSWORD else None,
            decode_responses=True
        )
    except (ImportError, Exception):
        return None


def save_task_chat(task_id: str, chat_id: int, cost: float = 0.0):
    """Сохранение связи task_id -> (chat_id, cost)"""
    redis_client = _get_redis_client()
    if redis_client:
        try:
            data = {
                'chat_id': chat_id,
                'cost': cost
            }
            redis_client.setex(
                f"task:{task_id}",
                TASK_TTL,
                json.dumps(data)
            )
            return
        except Exception:
            # Fallback на память если Redis недоступен
            pass
    
    # Fallback на память
    _task_chat_map_fallback[task_id] = (chat_id, cost)


def get_chat_by_task(task_id: str) -> Optional[int]:
    """Получение chat_id по task_id"""
    redis_client = _get_redis_client()
    if redis_client:
        try:
            data = redis_client.get(f"task:{task_id}")
            if data:
                parsed = json.loads(data)
                return parsed.get('chat_id')
        except Exception:
            pass
    
    # Fallback на память
    result = _task_chat_map_fallback.get(task_id)
    return result[0] if result else None


def get_task_cost(task_id: str) -> float:
    """Получение стоимости задачи по task_id"""
    redis_client = _get_redis_client()
    if redis_client:
        try:
            data = redis_client.get(f"task:{task_id}")
            if data:
                parsed = json.loads(data)
                return float(parsed.get('cost', 0.0))
        except Exception:
            pass
    
    # Fallback на память
    result = _task_chat_map_fallback.get(task_id)
    return result[1] if result else 0.0


def get_task_info(task_id: str) -> Optional[Tuple[int, float]]:
    """Получение полной информации о задаче"""
    redis_client = _get_redis_client()
    if redis_client:
        try:
            data = redis_client.get(f"task:{task_id}")
            if data:
                parsed = json.loads(data)
                return (parsed.get('chat_id'), float(parsed.get('cost', 0.0)))
        except Exception:
            pass
    
    # Fallback на память
    return _task_chat_map_fallback.get(task_id)


def delete_task(task_id: str):
    """Удаление задачи"""
    redis_client = _get_redis_client()
    if redis_client:
        try:
            redis_client.delete(f"task:{task_id}")
            return
        except Exception:
            pass
    
    # Fallback на память
    _task_chat_map_fallback.pop(task_id, None)
