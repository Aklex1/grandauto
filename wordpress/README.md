# WordPress-часть genius-bot.ru

| Папка | Что это |
|---|---|
| `kie-tts-wp/modules/assistants/` | модуль ИИ-ассистентов внутри существующего плагина `kie-tts-wp` — телеграм-боты, веб-чат, админка токенов, галерея |
| `mu-plugins/000-rest-basic-auth-fix.php` | однократный фикс: без него пароли приложений WordPress не работают на nginx + php-fpm |

## Порядок установки

1. `mu-plugins/000-rest-basic-auth-fix.php` → `wp-content/mu-plugins/`
   После этого REST API начнёт принимать пароль приложения, и страницы можно будет
   публиковать автоматически, а не руками.
2. `kie-tts-wp/modules/assistants/` → `wp-content/plugins/kie-tts-wp/modules/`
3. В конец `kie-tts-wp.php` добавить:
   ```php
   require_once __DIR__ . '/modules/assistants/bootstrap.php';
   ```

Подробности по модулю — в `kie-tts-wp/modules/assistants/README.md`.
