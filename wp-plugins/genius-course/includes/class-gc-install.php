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
    const DB_VERSION  = 3;

    public static function activate() {
        self::tables();
        GC_Students::add_role();
        GC_Cabinet::ensure_page();
        update_option(self::OPT_VERSION, self::DB_VERSION, false);
    }

    /** Обновление без переустановки: таблицы и роль догоняют версию кода. */
    public static function maybe_upgrade() {
        if ((int) get_option(self::OPT_VERSION) === self::DB_VERSION) {
            return;
        }
        self::tables();
        GC_Students::add_role();
        GC_Cabinet::ensure_page();
        update_option(self::OPT_VERSION, self::DB_VERSION, false);
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
            created_at DATETIME NOT NULL,
            finished_at DATETIME NULL,
            PRIMARY KEY (id),
            KEY user_created (user_id, created_at),
            KEY status (status)
        ) {$collate};");
    }
}
