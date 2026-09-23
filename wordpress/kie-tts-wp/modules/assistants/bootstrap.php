<?php
/**
 * Модуль «ИИ-ассистенты» плагина kie-tts-wp.
 *
 * Отдельного плагина нет намеренно: авторизация (почта, VK, Telegram) и баланс пользователя
 * должны быть теми же, что в микросервисах. Поэтому модуль живёт внутри kie-tts-wp
 * и пользуется его учётными записями и его балансом.
 *
 * Подключается загрузчиком wp-content/mu-plugins/genius-assistants-loader.php —
 * чужой kie-tts-wp.php при этом не правится, и обновление плагина ничего не сломает.
 * Если загрузчика нет, сработает и одна строка в конце kie-tts-wp.php:
 *
 *     require_once __DIR__ . '/modules/assistants/bootstrap.php';
 */

if (!defined('ABSPATH')) {
    exit;
}

define('GA_VERSION', '1.6.0');
define('GA_DIR', __DIR__);
define('GA_URL', rtrim(plugins_url('', __FILE__), '/'));
define('GA_REST_NS', 'assistants/v1');

require_once GA_DIR . '/class-ga-presets.php';
require_once GA_DIR . '/class-ga-store.php';
require_once GA_DIR . '/class-ga-billing.php';
require_once GA_DIR . '/class-ga-kb.php';
require_once GA_DIR . '/class-ga-kie.php';
require_once GA_DIR . '/class-ga-chat.php';
require_once GA_DIR . '/class-ga-telegram.php';
require_once GA_DIR . '/class-ga-rest.php';
require_once GA_DIR . '/class-ga-shortcodes.php';
require_once GA_DIR . '/class-ga-admin.php';

// Таблицы создаём и обновляем на лету: модуль подключают к уже активному плагину,
// хук активации к этому моменту давно отработал.
if (did_action('plugins_loaded')) {
    // Загрузчик подключил модуль уже внутри plugins_loaded — хук бы не сработал.
    add_action('init', ['GA_Store', 'maybe_install'], 1);
} else {
    add_action('plugins_loaded', ['GA_Store', 'maybe_install'], 20);
}
add_action('rest_api_init', ['GA_Rest', 'register_routes']);
add_action('init', ['GA_Shortcodes', 'init']);

if (is_admin()) {
    add_action('admin_menu', ['GA_Admin', 'menu']);
    add_action('admin_init', ['GA_Admin', 'handle_post']);
    add_action('admin_enqueue_scripts', ['GA_Admin', 'assets']);
}
