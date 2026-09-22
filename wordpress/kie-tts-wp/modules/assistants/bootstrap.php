<?php
/**
 * Модуль «ИИ-ассистенты» плагина kie-tts-wp.
 *
 * Отдельного плагина нет намеренно: авторизация (почта, VK, Telegram) и баланс пользователя
 * должны быть теми же, что в микросервисах. Поэтому модуль живёт внутри kie-tts-wp
 * и пользуется его учётными записями и его балансом.
 *
 * Подключение — одна строка в конце главного файла плагина kie-tts-wp.php:
 *
 *     require_once __DIR__ . '/modules/assistants/bootstrap.php';
 */

if (!defined('ABSPATH')) {
    exit;
}

define('GA_VERSION', '1.0.0');
define('GA_DIR', __DIR__);
define('GA_URL', plugins_url('modules/assistants', dirname(__DIR__) . '/kie-tts-wp.php'));
define('GA_REST_NS', 'assistants/v1');

require_once GA_DIR . '/class-ga-presets.php';
require_once GA_DIR . '/class-ga-store.php';
require_once GA_DIR . '/class-ga-billing.php';
require_once GA_DIR . '/class-ga-kie.php';
require_once GA_DIR . '/class-ga-chat.php';
require_once GA_DIR . '/class-ga-telegram.php';
require_once GA_DIR . '/class-ga-rest.php';
require_once GA_DIR . '/class-ga-shortcodes.php';
require_once GA_DIR . '/class-ga-admin.php';

// Таблицы создаём и обновляем на лету: модуль подключают к уже активному плагину,
// хук активации к этому моменту давно отработал.
add_action('plugins_loaded', ['GA_Store', 'maybe_install'], 20);
add_action('rest_api_init', ['GA_Rest', 'register_routes']);
add_action('init', ['GA_Shortcodes', 'init']);

if (is_admin()) {
    add_action('admin_menu', ['GA_Admin', 'menu']);
    add_action('admin_init', ['GA_Admin', 'handle_post']);
    add_action('admin_enqueue_scripts', ['GA_Admin', 'assets']);
}
