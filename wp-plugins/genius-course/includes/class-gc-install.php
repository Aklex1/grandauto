<?php
/**
 * Таблицы и страница кабинета.
 *
 * Работы учеников и задания на генерацию держим в своих таблицах, а не в
 * записях: у них свой жизненный цикл, их не надо показывать в поиске и
 * не надо отдавать в ленту, а объём со временем вырастет до десятков
 * тысяч строк на человека.
 */

if (!defined('ABSPATH')) {
    exit;
}

class GC_Install {

    const OPT_VERSION = 'gc_db_version';
    /** Счётчик страниц кабинетов: отдельный, потому что создаются они позже. */
    const OPT_PAGES   = 'gc_pages_version';
    const DB_VERSION  = 5;

    public static function activate() {
        self::tables();
        GC_Students::add_role();
        // Включение идёт из админки, запрос уже прошёл init — страницы
        // создавать можно.
        self::pages();
        update_option(self::OPT_VERSION, self::DB_VERSION, false);
    }

    /**
     * Обновление без переустановки: таблицы и роль догоняют версию кода.
     *
     * Страницы здесь не создаём. Этот метод зовут на plugins_loaded, а
     * wp_insert_post в этот момент валит сайт целиком: константу
     * WP_POST_REVISIONS WordPress определяет уже после загрузки плагинов,
     * и обращение к ней выбрасывает фатальную ошибку на каждом запросе —
     * включая главную. Проверено ценой падения сайта 23.10.2026.
     */
    public static function maybe_upgrade() {
        if ((int) get_option(self::OPT_VERSION) === self::DB_VERSION) {
            return;
        }
        self::tables();
        GC_Students::add_role();
        update_option(self::OPT_VERSION, self::DB_VERSION, false);
    }

    /**
     * Страницы кабинетов. Вызывается на init, не раньше.
     *
     * Свой счётчик вместо общего: таблицы и роль обновляются раньше, и
     * если бы признак был один, страницы больше никогда не создались бы.
     */
    public static function maybe_pages() {
        if ((int) get_option(self::OPT_PAGES) === self::DB_VERSION) {
            return;
        }
        self::pages();
    }

    private static function pages() {
        GC_Cabinet::ensure_pages();
        update_option(self::OPT_PAGES, self::DB_VERSION, false);
    }

    public static function works_table() {
        global $wpdb;
        return $wpdb->prefix . 'gc_works';
    }

    public static function jobs_table() {
        global $wpdb;
        return $wpdb->prefix . 'gc_jobs';
    }

    private static function tables() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $collate = $wpdb->get_charset_collate();

        $works = self::works_table();
        dbDelta("CREATE TABLE {$works} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            module TINYINT UNSIGNED NOT NULL,
            body LONGTEXT NULL,
            links TEXT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'sent',
            feedback LONGTEXT NULL,
            auto_feedback LONGTEXT NULL,
            shared TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            reviewed_at DATETIME NULL,
            PRIMARY KEY (id),
            KEY user_module (user_id, module),
            KEY status (status)
        ) {$collate};");

        $jobs = self::jobs_table();
        dbDelta("CREATE TABLE {$jobs} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            kind VARCHAR(16) NOT NULL DEFAULT 'image',
            prompt TEXT NOT NULL,
            params TEXT NULL,
            status VARCHAR(16) NOT NULL DEFAULT 'queued',
            remote_id VARCHAR(64) NULL,
            result_url TEXT NULL,
            error TEXT NULL,
            shared TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            finished_at DATETIME NULL,
            PRIMARY KEY (id),
            KEY user_created (user_id, created_at),
            KEY status (status)
        ) {$collate};");
    }
}
