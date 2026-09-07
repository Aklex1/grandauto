"""
Rate Limiting Middleware для aiogram
Ограничивает количество запросов от пользователя
"""
from aiogram import BaseMiddleware
from aiogram.types import TelegramObject, Message, CallbackQuery
from typing import Callable, Dict, Any, Awaitable
import logging

logger = logging.getLogger(__name__)


class RateLimitMiddleware(BaseMiddleware):
    """
    Middleware для ограничения количества запросов от пользователя
    """
    
    def __init__(self, max_requests: int = 20, time_window: int = 60):
        """
        Args:
            max_requests: Максимальное количество запросов
            time_window: Временное окно в секундах
        """
        self.max_requests = max_requests
        self.time_window = time_window
        self._redis_client = None
        self._init_redis()
    
    def _init_redis(self):
        """Инициализация Redis клиента"""
        try:
            import redis
            from config import REDIS_HOST, REDIS_PORT, REDIS_DB, REDIS_PASSWORD
            self._redis_client = redis.Redis(
                host=REDIS_HOST,
                port=REDIS_PORT,
                db=REDIS_DB,
                password=REDIS_PASSWORD if REDIS_PASSWORD else None,
                decode_responses=True
            )
            # Проверяем подключение
            self._redis_client.ping()
            logger.info("✅ Rate limiting использует Redis")
        except (ImportError, Exception) as e:
            logger.warning(f"⚠️ Redis недоступен для rate limiting, используется in-memory: {e}")
            self._redis_client = None
            self._memory_cache = {}
    
    def _check_rate_limit(self, user_id: int) -> bool:
        """Проверка rate limit. Возвращает True если лимит не превышен"""
        key = f"rate_limit:{user_id}"
        
        if self._redis_client:
            try:
                current = self._redis_client.get(key)
                if current and int(current) >= self.max_requests:
                    return False
                
                pipe = self._redis_client.pipeline()
                pipe.incr(key)
                pipe.expire(key, self.time_window)
                pipe.execute()
                return True
            except Exception as e:
                logger.error(f"Ошибка Redis rate limiting: {e}")
                return True  # Разрешаем запрос при ошибке Redis
        
        # Fallback на память
        import time
        current_time = time.time()
        if user_id in self._memory_cache:
            count, window_start = self._memory_cache[user_id]
            if current_time - window_start < self.time_window:
                if count >= self.max_requests:
                    return False
                self._memory_cache[user_id] = (count + 1, window_start)
            else:
                self._memory_cache[user_id] = (1, current_time)
        else:
            self._memory_cache[user_id] = (1, current_time)
        
        return True
    
    async def __call__(
        self,
        handler: Callable[[TelegramObject, Dict[str, Any]], Awaitable[Any]],
        event: TelegramObject,
        data: Dict[str, Any]
    ) -> Any:
        # Получаем user_id из события
        user_id = None
        if isinstance(event, (Message, CallbackQuery)):
            if hasattr(event, 'from_user') and event.from_user:
                user_id = event.from_user.id
        
        if not user_id:
            return await handler(event, data)
        
        # Проверяем rate limit
        if not self._check_rate_limit(user_id):
            # Превышен лимит
            if isinstance(event, Message):
                await event.answer(
                    f"⚠️ Слишком много запросов. Подождите {self.time_window} секунд."
                )
            elif isinstance(event, CallbackQuery):
                await event.answer(
                    f"⚠️ Слишком много запросов. Подождите {self.time_window} секунд.",
                    show_alert=True
                )
            return
        
        # Продолжаем обработку
        return await handler(event, data)

