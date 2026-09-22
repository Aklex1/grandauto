<?php
/**
 * Plugin Name: Genius Assistants Loader
 * Description: Подключает модуль ИИ-ассистентов из kie-tts-wp, не трогая сам плагин. Благодаря этому обновление kie-tts-wp не стирает интеграцию.
 * Version: 1.0.0
 *
 * Куда положить: wp-content/mu-plugins/genius-assistants-loader.php
 * Активировать не нужно — mu-плагины подключаются сами.
 */

if (!defined('ABSPATH')) {
    exit;
}

add_action('plugins_loaded', static function (): void {
    $module = WP_PLUGIN_DIR . '/kie-tts-wp/modules/assistants/bootstrap.php';
    if (!is_readable($module)) {
        return;
    }
    // Модуль живёт внутри kie-tts-wp и опирается на его учётные записи и баланс.
    // Если плагин выключен, молча ничего не делаем.
    if (!function_exists('is_plugin_active')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }
    if (!is_plugin_active('kie-tts-wp/kie-tts-wp.php')) {
        return;
    }
    require_once $module;
}, 5);
