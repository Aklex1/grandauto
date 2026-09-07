"""
Middleware для сбора метрик Prometheus
"""
from aiogram import BaseMiddleware
from aiogram.types import TelegramObject, Message, CallbackQuery
from typing import Callable, Dict, Any, Awaitable
import logging
import time

logger = logging.getLogger(__name__)

# Импортируем метрики (с проверкой доступности)
try:
    from monitoring.metrics import (
        bot_commands_total,
        bot_messages_total,
        bot_callbacks_total,
        bot_response_latency
    )
    METRICS_AVAILABLE = True
except ImportError:
    METRICS_AVAILABLE = False
    bot_response_latency = None
    logger.warning("⚠️ Prometheus metrics недоступны")


class MetricsMiddleware(BaseMiddleware):
    """Middleware для сбора метрик бота"""
    
    async def __call__(
        self,
        handler: Callable[[TelegramObject, Dict[str, Any]], Awaitable[Any]],
        event: TelegramObject,
        data: Dict[str, Any]
    ) -> Any:
        if not METRICS_AVAILABLE:
            return await handler(event, data)
        
        start_time = time.time()
        request_type = 'unknown'
        
        try:
            # Обработка команд
            if isinstance(event, Message) and event.text and event.text.startswith('/'):
                command = event.text.split()[0] if event.text else 'unknown'
                request_type = 'command'
                if bot_commands_total:
                    bot_commands_total.labels(command=command).inc()
            
            # Обработка обычных сообщений
            elif isinstance(event, Message):
                message_type = event.content_type or 'text'
                request_type = 'message'
                if bot_messages_total:
                    bot_messages_total.labels(type=message_type).inc()
            
            # Обработка callback queries
            elif isinstance(event, CallbackQuery):
                callback_data = event.data or 'unknown'
                request_type = 'callback'
                if bot_callbacks_total:
                    bot_callbacks_total.labels(callback_data=callback_data).inc()
            
            # Выполняем обработчик
            result = await handler(event, data)
            
            # Измеряем время ответа
            if bot_response_latency:
                duration = time.time() - start_time
                bot_response_latency.labels(type=request_type).observe(duration)
            
            return result
        
        except Exception as e:
            # Измеряем время даже при ошибке
            if bot_response_latency:
                duration = time.time() - start_time
                bot_response_latency.labels(type=request_type).observe(duration)
            
            logger.error(f"Ошибка сбора метрик: {e}")
            raise

