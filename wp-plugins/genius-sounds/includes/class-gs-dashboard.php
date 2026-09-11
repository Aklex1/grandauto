<?php
/**
 * Кабинет озвучки (/tts-dashboard/) в стилистике остальных сервисов.
 *
 * Базовый плагин не трогаем: добавляем класс на body, свой файл стилей
 * и небольшой скрипт, который уводит ссылку на документацию в общий
 * раздел API — там теперь описаны все инструменты сразу.
 */

if (!defined('ABSPATH')) {
    exit;
}

class GS_Dashboard {

    const OPT_ENABLED = 'gs_dashboard_restyle';

    public static function boot() {
        add_filter('body_class', array(__CLASS__, 'body_class'));
        add_filter('the_content', array(__CLASS__, 'append_keywords'), 20);
    }

    /**
     * Под кабинетом показываем разборы частых задач по расшифровке:
     * читателю — быстрый ответ, страницам блога — вес с сильной страницы.
     */
    public static function append_keywords($content) {
        if (is_admin() || !is_main_query() || !in_the_loop()) {
            return $content;
        }
        if (!self::enabled() || !self::is_page() || !class_exists('GS_Keywords')) {
            return $content;
        }
        if (strpos($content, 'gs-keys') !== false) {
            return $content;
        }
        return $content . GS_Keywords::render('stt');
    }

    public static function enabled() {
        return (string) get_option(self::OPT_ENABLED, '1') === '1';
    }

    public static function page_id() {
        return (int) get_option('kie_tts_dashboard_page_id');
    }

    public static function is_page() {
        $pid = self::page_id();
        if ($pid > 0 && is_page($pid)) {
            return true;
        }
        // Страницу могли создать вручную — подстраховываемся адресом.
        $uri = isset($_SERVER['REQUEST_URI']) ? (string) wp_unslash($_SERVER['REQUEST_URI']) : '';
        $path = trim((string) wp_parse_url($uri, PHP_URL_PATH), '/');
        return $path === 'tts-dashboard';
    }

    public static function body_class($classes) {
        if (is_admin() || !self::enabled() || !self::is_page()) {
            return $classes;
        }
        $classes[] = 'gs-dashboard';
        $classes[] = 'gs-chrome';
        return $classes;
    }
}
