"""
Сервис для отправки email писем
Поддерживает SMTP через Gmail, SendGrid и другие сервисы
"""
import smtplib
import logging
from email.mime.text import MIMEText
from email.mime.multipart import MIMEMultipart
from typing import Optional
import os
from config import (
    SMTP_HOST, SMTP_PORT, SMTP_USER, SMTP_PASSWORD, 
    SMTP_FROM_EMAIL, SMTP_FROM_NAME, APP_BASE_URL
)

logger = logging.getLogger(__name__)


def send_email(
    to_email: str,
    subject: str,
    html_body: str,
    text_body: Optional[str] = None
) -> bool:
    """
    Отправка email письма
    
    Args:
        to_email: Email получателя
        subject: Тема письма
        html_body: HTML содержимое письма
        text_body: Текстовое содержимое (опционально)
    
    Returns:
        True если письмо отправлено успешно, False в противном случае
    """
    try:
        # Создаем сообщение
        msg = MIMEMultipart('alternative')
        msg['Subject'] = subject
        msg['From'] = f"{SMTP_FROM_NAME} <{SMTP_FROM_EMAIL}>"
        msg['To'] = to_email
        
        # Добавляем текстовую версию (если есть)
        if text_body:
            part1 = MIMEText(text_body, 'plain', 'utf-8')
            msg.attach(part1)
        
        # Добавляем HTML версию
        part2 = MIMEText(html_body, 'html', 'utf-8')
        msg.attach(part2)
        
        # Отправляем через SMTP
        # Убираем пробелы из пароля (Gmail выдает пароли приложений с пробелами)
        password_clean = SMTP_PASSWORD.replace(" ", "")
        
        with smtplib.SMTP(SMTP_HOST, SMTP_PORT) as server:
            if SMTP_PORT == 587:
                server.starttls()
            server.login(SMTP_USER, password_clean)
            server.send_message(msg)
        
        logger.info(f"Email успешно отправлен на {to_email}")
        return True
        
    except Exception as e:
        logger.error(f"Ошибка отправки email на {to_email}: {e}", exc_info=True)
        return False


def send_welcome_email(email: str, display_name: str) -> bool:
    """
    Отправка приветственного письма при регистрации
    
    Args:
        email: Email пользователя
        display_name: Имя пользователя
    
    Returns:
        True если письмо отправлено успешно
    """
    subject = "Добро пожаловать в Neuro Hub!"
    
    html_body = f"""
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <style>
            body {{
                font-family: Arial, sans-serif;
                line-height: 1.6;
                color: #333;
                max-width: 600px;
                margin: 0 auto;
                padding: 20px;
            }}
            .header {{
                background-color: #4CAF50;
                color: white;
                padding: 20px;
                text-align: center;
                border-radius: 5px 5px 0 0;
            }}
            .content {{
                background-color: #f9f9f9;
                padding: 20px;
                border-radius: 0 0 5px 5px;
            }}
            .button {{
                display: inline-block;
                padding: 12px 24px;
                background-color: #4CAF50;
                color: white;
                text-decoration: none;
                border-radius: 5px;
                margin-top: 20px;
            }}
        </style>
    </head>
    <body>
        <div class="header">
            <h1>Добро пожаловать в Neuro Hub!</h1>
        </div>
        <div class="content">
            <p>Здравствуйте, {display_name}!</p>
            <p>Спасибо за регистрацию в Neuro Hub - платформе для генерации и редактирования изображений и видео с помощью нейросетей.</p>
            <p>Теперь вы можете:</p>
            <ul>
                <li>Генерировать изображения по текстовому описанию</li>
                <li>Редактировать фотографии с помощью AI</li>
                <li>Создавать видео из изображений</li>
                <li>Использовать готовые шаблоны для быстрого результата</li>
            </ul>
            <p>💡 <strong>Полезный ресурс:</strong> Подписывайтесь на наш <a href="https://t.me/promtnanobanana7" style="color: #4CAF50; text-decoration: none;">Telegram-канал с промптами</a>, чтобы получать идеи для создания потрясающих изображений и видео!</p>
            <p>Начните работу прямо сейчас!</p>
            <p>Если у вас возникнут вопросы, мы всегда готовы помочь.</p>
            <p>С уважением,<br>Команда Neuro Hub</p>
        </div>
    </body>
    </html>
    """
    
    text_body = f"""
    Добро пожаловать в Neuro Hub!
    
    Здравствуйте, {display_name}!
    
    Спасибо за регистрацию в Neuro Hub - платформе для генерации и редактирования изображений и видео с помощью нейросетей.
    
    Теперь вы можете:
    - Генерировать изображения по текстовому описанию
    - Редактировать фотографии с помощью AI
    - Создавать видео из изображений
    - Использовать готовые шаблоны для быстрого результата
    
    💡 Полезный ресурс: Подписывайтесь на наш Telegram-канал с промптами, чтобы получать идеи для создания потрясающих изображений и видео!
    https://t.me/promtnanobanana7
    
    Начните работу прямо сейчас!
    
    Если у вас возникнут вопросы, мы всегда готовы помочь.
    
    С уважением,
    Команда Neuro Hub
    """
    
    return send_email(email, subject, html_body, text_body)


def send_password_reset_email(email: str, reset_token: str) -> bool:
    """
    Отправка письма со ссылкой для восстановления пароля
    
    Args:
        email: Email пользователя
        reset_token: Токен для восстановления пароля
    
    Returns:
        True если письмо отправлено успешно
    """
    reset_url = f"{APP_BASE_URL}/reset-password?token={reset_token}"
    
    subject = "Восстановление пароля Neuro Hub"
    
    html_body = f"""
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <style>
            body {{
                font-family: Arial, sans-serif;
                line-height: 1.6;
                color: #333;
                max-width: 600px;
                margin: 0 auto;
                padding: 20px;
            }}
            .header {{
                background-color: #2196F3;
                color: white;
                padding: 20px;
                text-align: center;
                border-radius: 5px 5px 0 0;
            }}
            .content {{
                background-color: #f9f9f9;
                padding: 20px;
                border-radius: 0 0 5px 5px;
            }}
            .button {{
                display: inline-block;
                padding: 12px 24px;
                background-color: #2196F3;
                color: white;
                text-decoration: none;
                border-radius: 5px;
                margin-top: 20px;
            }}
            .warning {{
                color: #d32f2f;
                font-weight: bold;
                margin-top: 20px;
            }}
        </style>
    </head>
    <body>
        <div class="header">
            <h1>Восстановление пароля</h1>
        </div>
        <div class="content">
            <p>Здравствуйте!</p>
            <p>Вы запросили восстановление пароля для вашего аккаунта в Neuro Hub.</p>
            <p>Для установки нового пароля нажмите на кнопку ниже:</p>
            <p style="text-align: center;">
                <a href="{reset_url}" class="button">Восстановить пароль</a>
            </p>
            <p>Или скопируйте эту ссылку в браузер:</p>
            <p style="word-break: break-all; color: #666;">{reset_url}</p>
            <p class="warning">⚠️ Ссылка действительна в течение 1 часа.</p>
            <p class="warning">⚠️ Если вы не запрашивали восстановление пароля, просто проигнорируйте это письмо.</p>
            <p>С уважением,<br>Команда Neuro Hub</p>
        </div>
    </body>
    </html>
    """
    
    text_body = f"""
    Восстановление пароля
    
    Здравствуйте!
    
    Вы запросили восстановление пароля для вашего аккаунта в Neuro Hub.
    
    Для установки нового пароля перейдите по ссылке:
    {reset_url}
    
    ⚠️ Ссылка действительна в течение 1 часа.
    ⚠️ Если вы не запрашивали восстановление пароля, просто проигнорируйте это письмо.
    
    С уважением,
    Команда Neuro Hub
    """
    
    return send_email(email, subject, html_body, text_body)
