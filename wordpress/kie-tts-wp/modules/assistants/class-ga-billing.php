<?php
/**
 * Единый баланс: ассистенты списывают с того же счёта, что и микросервисы.
 *
 * kie-tts-wp хранит баланс в двух местах — у пользователей, пришедших из телеграм-бота,
 * он лежит в БД бота, у остальных в таблице wp_kie_tts_balance. Дублировать эту логику
 * нельзя: разойдутся. Поэтому порядок такой:
 *   1) отдаём вопрос самому kie-tts-wp — через фильтры и его же публичные функции;
 *   2) если он их не предоставил, читаем и пишем в его таблицу напрямую,
 *      определяя имена колонок на лету, чтобы не гадать по памяти.
 *
 * Чтобы прибить интеграцию намертво, достаточно в kie-tts-wp повесить два фильтра:
 *   add_filter('ga_balance_get',    fn($_, $uid) => kie_tts_get_balance($uid), 10, 2);
 *   add_filter('ga_balance_charge', fn($_, $uid, $sum, $note) => kie_tts_charge($uid, $sum, $note), 10, 4);
 */

if (!defined('ABSPATH')) {
    exit;
}

class GA_Billing
{
    public const OPT_PRICE = 'ga_price_per_message';   // рублей за сообщение
    public const OPT_FREE_GUEST = 'ga_guest_free';     // бесплатных сообщений гостю
    public const OPT_PRICE_FILE = 'ga_price_per_file'; // рублей за сообщение с файлом (дороже: разбор через KIE)

    public static function price_per_message(): float
    {
        return (float) get_option(self::OPT_PRICE, 2);
    }

    public static function guest_free_limit(): int
    {
        // По умолчанию берём тот же лимит, что показывают микросервисы гостю.
        return (int) get_option(self::OPT_FREE_GUEST, get_option('kie_tts_guest_free_limit', 5));
    }

    /** Цена сообщения с вложением (документ/фото). Разбор через KIE дороже обычного. */
    public static function price_per_file(): float
    {
        return (float) get_option(self::OPT_PRICE_FILE, 8);
    }

    /**
     * Может ли пользователь прикладывать файлы. Функция платная: нужен вход
     * и положительный баланс. Гостю и на нуле — закрыто.
     */
    public static function can_upload(int $user_id): bool
    {
        return $user_id > 0 && self::balance($user_id) >= self::price_per_file();
    }

    /**
     * Может ли пользователь вести свою базу знаний. Функция для оплативших:
     * нужен вход и положительный баланс (проще говоря — уже платил). Админ — всегда.
     */
    public static function can_manage_kb(int $user_id): bool
    {
        if (!$user_id) {
            return false;
        }
        if (user_can($user_id, 'manage_options')) {
            return true;
        }
        return self::balance($user_id) > 0;
    }

    /** Баланс пользователя в рублях. */
    public static function balance(int $user_id): float
    {
        if (!$user_id) {
            return 0.0;
        }
        $external = apply_filters('ga_balance_get', null, $user_id);
        if ($external !== null) {
            return (float) $external;
        }
        foreach (['kie_tts_get_balance', 'kie_tts_user_balance'] as $fn) {
            if (function_exists($fn)) {
                return (float) call_user_func($fn, $user_id);
            }
        }
        return self::table_balance($user_id);
    }

    /**
     * Списание. Возвращает true, если деньги сняты.
     * Отрицательный баланс не допускаем — проверяем перед списанием.
     */
    public static function charge(int $user_id, float $amount, string $note = ''): bool
    {
        if ($amount <= 0) {
            return true;
        }
        if (!$user_id) {
            return false;
        }
        $external = apply_filters('ga_balance_charge', null, $user_id, $amount, $note);
        if ($external !== null) {
            return (bool) $external;
        }
        foreach (['kie_tts_charge', 'kie_tts_deduct_balance'] as $fn) {
            if (function_exists($fn)) {
                return (bool) call_user_func($fn, $user_id, $amount, $note);
            }
        }
        return self::table_charge($user_id, $amount);
    }

    // ------------------------------------------------------------------ прямая работа с таблицей

    /** Имя таблицы балансов kie-tts-wp. */
    private static function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'kie_tts_balance';
    }

    /**
     * Колонка с суммой определяется на лету: в разных версиях плагина она называется
     * по-разному, а гадать вслепую — верный способ молча разойтись с микросервисами.
     */
    private static function amount_column(): ?string
    {
        static $cached = false;
        static $column = null;
        if ($cached) {
            return $column;
        }
        $cached = true;
        global $wpdb;
        $table = self::table();
        $found = $wpdb->get_col("SHOW COLUMNS FROM `$table`");
        if (!$found) {
            return $column = null;
        }
        foreach (['balance', 'amount', 'value', 'sum', 'credits'] as $candidate) {
            if (in_array($candidate, $found, true)) {
                return $column = $candidate;
            }
        }
        return $column = null;
    }

    private static function table_balance(int $user_id): float
    {
        $column = self::amount_column();
        if (!$column) {
            return 0.0;
        }
        global $wpdb;
        $table = self::table();
        $value = $wpdb->get_var($wpdb->prepare(
            "SELECT `$column` FROM `$table` WHERE user_id = %d", $user_id));
        return (float) $value;
    }

    private static function table_charge(int $user_id, float $amount): bool
    {
        $column = self::amount_column();
        if (!$column) {
            return false;
        }
        global $wpdb;
        $table = self::table();
        // Условие в UPDATE защищает от гонки: два одновременных запроса не уведут баланс в минус.
        $done = $wpdb->query($wpdb->prepare(
            "UPDATE `$table` SET `$column` = `$column` - %f WHERE user_id = %d AND `$column` >= %f",
            $amount, $user_id, $amount));
        return (bool) $done;
    }

    /**
     * Телеграм-пользователь, пришедший в бота ассистента, может быть ещё не связан
     * с аккаунтом на сайте. Ищем связь по мете, которую заводит kie-tts-wp.
     */
    public static function user_by_telegram(int $tg_id): int
    {
        if (!$tg_id) {
            return 0;
        }
        foreach (['telegram_id', 'kie_tts_telegram_id', 'tg_id'] as $meta_key) {
            $users = get_users([
                'meta_key' => $meta_key,
                'meta_value' => (string) $tg_id,
                'number' => 1,
                'fields' => 'ID',
            ]);
            if ($users) {
                return (int) $users[0];
            }
        }
        return 0;
    }
}
