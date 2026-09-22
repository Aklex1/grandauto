# WordPress-часть genius-bot.ru

| Папка | Что это |
|---|---|
| `kie-tts-wp/modules/assistants/` | модуль ИИ-ассистентов внутри существующего плагина `kie-tts-wp` — телеграм-боты, веб-чат, админка токенов, галерея |
| `mu-plugins/genius-assistants-loader.php` | подключает модуль, не трогая `kie-tts-wp.php` |
| `mu-plugins/000-rest-basic-auth-fix.php` | без него пароли приложений WordPress не работают на nginx + php-fpm |

## Установка

Всё сводится к копированию файлов — править чужой код не нужно нигде.

1. `mu-plugins/*.php` → `wp-content/mu-plugins/`
2. `kie-tts-wp/modules/assistants/` → `wp-content/plugins/kie-tts-wp/modules/`

Активировать нечего: mu-плагины подключаются сами, таблицы создаются при первом
заходе в админку, четыре ассистента первой волны появляются сразу.

Готовый архив под распаковку в корень сайта собирается командой `make deploy-zip`
(см. `tools/`), он же приложен к задаче.

Подробности по модулю — в `kie-tts-wp/modules/assistants/README.md`.
