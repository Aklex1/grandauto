# Модуль «ИИ-ассистенты» для kie-tts-wp

Телеграм-боты и веб-версии ИИ-ассистентов внутри существующего плагина `kie-tts-wp`.
Отдельного плагина нет намеренно: вход (почта, VK, Telegram) и баланс пользователя
должны быть теми же, что в микросервисах.

## Установка

1. Скопируйте папку `assistants` в `wp-content/plugins/kie-tts-wp/modules/`.
2. Положите `mu-plugins/genius-assistants-loader.php` в `wp-content/mu-plugins/`.
   Он подключит модуль сам — чужой `kie-tts-wp.php` править не нужно, и обновление
   плагина не сотрёт интеграцию. (Если mu-плагины не используются, можно вместо
   этого дописать в конец `kie-tts-wp.php` строку
   `require_once __DIR__ . '/modules/assistants/bootstrap.php';`.)
3. Откройте любую страницу админки — таблицы создадутся сами, четыре ассистента
   первой волны появятся в разделе **ИИ-ассистенты**.

Ключ KIE отдельно задавать не нужно: берётся `kie_tts_api_key`, тот же, что у озвучки.

## Подключение ботов

**ИИ-ассистенты → Токены ботов**: выбрать ассистента, вставить токен от
[@BotFather](https://t.me/BotFather), нажать «Подключить».

Вебхук, команды (`/start`, `/menu`, `/reset`, `/balance`) и описание бота
настраиваются автоматически. Токен в интерфейсе показывается обрезанным.
Вебхук защищён секретом в заголовке `X-Telegram-Bot-Api-Secret-Token` — чужой POST не пройдёт.

Демона держать не нужно: боты работают на вебхуках, запросы принимает сам сайт.

## Вывод на сайте

| Куда | Шорткод |
|---|---|
| Галерея на главной | `[genius_assistants_gallery]` |
| Чат на лендинге | `[genius_assistant slug="uchitel"]` |
| Компактный чат | `[genius_assistant slug="uchitel" compact="1"]` |

Слаги первой волны: `uchitel`, `ucheba`, `yurist`, `biznes`.

## Баланс

Своего счёта у модуля нет. Баланс спрашивается у `kie-tts-wp` по цепочке:

1. фильтры `ga_balance_get` / `ga_balance_charge`;
2. функции `kie_tts_get_balance()` / `kie_tts_charge()`;
3. таблица `wp_kie_tts_balance` напрямую — имя колонки с суммой определяется
   на лету через `SHOW COLUMNS`, чтобы не разойтись с плагином.

Чтобы закрепить связь жёстко, добавьте в `kie-tts-wp`:

```php
add_filter('ga_balance_get',    fn($_, $uid) => kie_tts_get_balance($uid), 10, 2);
add_filter('ga_balance_charge', fn($_, $uid, $sum, $note) => kie_tts_charge($uid, $sum, $note), 10, 4);
```

Порядок списания: сначала бесплатная суточная норма ассистента, потом общий баланс.
Деньги снимаются только за состоявшийся ответ — если модель не ответила, списания нет.

## Что внутри

| Файл | Отвечает за |
|---|---|
| `bootstrap.php` | подключение модуля, хуки |
| `class-ga-presets.php` | заводские ассистенты: промпты, сценарии, SEO |
| `class-ga-store.php` | таблицы `ga_assistants`, `ga_bot_tokens`, `ga_threads`, `ga_messages` |
| `class-ga-billing.php` | мост к балансу микросервисов |
| `class-ga-kie.php` | вызов чат-моделей KIE |
| `class-ga-chat.php` | контекст диалога, лимиты, списание |
| `class-ga-telegram.php` | Telegram API, вебхуки, обработка команд |
| `class-ga-rest.php` | `assistants/v1/message`, `/state`, `/telegram/{id}` |
| `class-ga-shortcodes.php` | галерея и чат-виджет |
| `class-ga-admin.php` | разделы админки |
