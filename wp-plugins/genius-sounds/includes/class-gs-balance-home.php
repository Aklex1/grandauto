<?php
/**
 * Деньги живут на сайте, а не в базе бота.
 *
 * У людей, вошедших через Телеграм, плагин озвучки держал баланс во внешней
 * базе бота и ходил туда напрямую по mysqli. База перестала пускать сайт
 * («Access denied for user»), и получилось худшее из возможного: баланс у
 * всех показывался нулём, а платёжный маршрут этого не замечал — помечал
 * платёж закрытым и отправлял деньги в недоступную базу. Две оплаты по
 * 200 ₽ так и растворились.
 *
 * Переключатель у этой развилки один — метка пользователя is_telegram_user:
 * по ней и плагин озвучки, и наши сервисы решают, где искать деньги. Снимаем
 * метку — и баланс, приветственный бонус, списания и новые платежи идут в
 * таблицу сайта, ту же, что у всех остальных. Метку telegram_id не трогаем:
 * вход через Телеграм работает по ней и продолжает работать.
 *
 * Метку нужно не только снять, но и не дать вернуться: обработчик входа
 * ставит её заново при каждом заходе. Поэтому фильтром перехватываем саму
 * запись этой меты.
 *
 * Люди из ВК под раздачу не попадали: у них метки нет, и баланс всегда лежал
 * на сайте. Проверяем и их — но менять там нечего.
 */

if (!defined('ABSPATH')) {
    exit;
}

class GS_Balance_Home {

    /** Держим ли балансы на сайте. */
    const OPT_ENABLED = 'gs_balance_home';

    /** Метки платежей, которые мы уже зачислили руками. */
    const OPT_CREDITED = 'gs_balance_home_credited';

    /** Метка-переключатель чужого плагина. */
    const META_BOT = 'is_telegram_user';

    /** Отметка о выданном приветственном бонусе. */
    const META_BONUS = 'kie_tts_welcome_bonus_granted';

    public static function boot() {
        if (!self::enabled()) {
            return;
        }
        add_filter('add_user_metadata', array(__CLASS__, 'block_bot_meta'), 10, 4);
        add_filter('update_user_metadata', array(__CLASS__, 'block_bot_meta'), 10, 4);
    }

    public static function enabled() {
        return (string) get_option(self::OPT_ENABLED, '1') === '1';
    }

    /**
     * Не пускаем метку бота обратно.
     *
     * Возвращённое не-null значение говорит WordPress, что запись уже
     * сделана, и он её не делает. Именно это нам и нужно: обработчик входа
     * ставит метку при каждом заходе, и без заслонки человек после
     * следующего входа снова оказался бы с деньгами в чужой базе.
     */
    public static function block_bot_meta($check, $object_id, $meta_key, $meta_value) {
        if ($meta_key !== self::META_BOT) {
            return $check;
        }
        return true;
    }

    /* ---------------------------------------------------------------------
     * Разовый перевод
     * ------------------------------------------------------------------ */

    /**
     * Перевести всех, кто входил через Телеграм, на баланс сайта.
     *
     * Приветственный бонус у таких людей уходил в недоступную базу — то есть
     * они его не получили, хотя отметка о выдаче стоит. Снимаем отметку:
     * бонус начислится на сайте при следующем входе.
     *
     * @return array отчёт о том, что сделано
     */
    public static function migrate() {
        $report = array(
            'нашли'          => 0,
            'сняли_метку'    => 0,
            'вернули_бонус'  => 0,
            'уже_на_сайте'   => 0,
            'люди'           => array(),
        );

        foreach (self::bot_users() as $user_id) {
            $report['нашли']++;
            $had_meta = (bool) get_user_meta($user_id, self::META_BOT, true);
            if ($had_meta) {
                delete_user_meta($user_id, self::META_BOT);
                $report['сняли_метку']++;
            } else {
                $report['уже_на_сайте']++;
            }

            // Строка баланса завязывается сама при первом чтении.
            $balance = class_exists('KIE_TTS_DB') ? (float) KIE_TTS_DB::get_user_balance($user_id, false) : 0.0;

            if ($had_meta && get_user_meta($user_id, self::META_BONUS, true)) {
                delete_user_meta($user_id, self::META_BONUS);
                $report['вернули_бонус']++;
            }

            $user = get_user_by('id', $user_id);
            $report['люди'][] = array(
                'user_id' => $user_id,
                'логин'   => $user ? $user->user_login : '',
                'баланс'  => $balance,
                'метка'   => $had_meta ? 'снята' : 'и не было',
            );
        }

        return $report;
    }

    /**
     * Кого переводим: у кого есть метка бота или телеграмный логин.
     *
     * Ищем по обоим признакам. Метка могла и потеряться, а деньги при
     * следующем входе всё равно уехали бы в чужую базу — переводим всех,
     * кто когда-либо входил через Телеграм.
     */
    private static function bot_users() {
        global $wpdb;
        $ids = array();

        foreach ((array) get_users(array('meta_key' => self::META_BOT, 'fields' => 'ID')) as $id) {
            $ids[] = (int) $id;
        }
        foreach ((array) $wpdb->get_col(
            "SELECT ID FROM {$wpdb->users} WHERE user_login LIKE 'telegram\\_%'"
        ) as $id) {
            $ids[] = (int) $id;
        }
        foreach ((array) get_users(array('meta_key' => 'telegram_id', 'fields' => 'ID')) as $id) {
            $ids[] = (int) $id;
        }

        $ids = array_values(array_unique(array_filter($ids)));
        sort($ids);
        return $ids;
    }

    /* ---------------------------------------------------------------------
     * Потерянные оплаты
     * ------------------------------------------------------------------ */

    /**
     * Зачислить оплаты, деньги по которым пришли, а баланс не изменился.
     *
     * Берём только закрытые платежи с метками бота: закрытыми их сделало
     * уведомление от ЮMoney, то есть деньги в кошельке действительно есть.
     * Незакрытые не трогаем — среди них брошенные попытки, по которым
     * никто не платил.
     *
     * Каждую метку зачисляем один раз: список зачисленных храним рядом,
     * чтобы повторный запуск не удвоил деньги.
     */
    public static function credit_lost($dry = true) {
        global $wpdb;
        $table = $wpdb->prefix . 'kie_tts_payments';
        $done = get_option(self::OPT_CREDITED, array());
        if (!is_array($done)) {
            $done = array();
        }

        $out = array('зачислили' => 0, 'сумма' => 0.0, 'пропустили' => 0, 'платежи' => array());
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
            return $out;
        }

        $rows = (array) $wpdb->get_results(
            "SELECT label, user_id, amount FROM {$table}
              WHERE status = 'completed' AND label LIKE 'topup\\_telegram\\_%'
           ORDER BY id ASC", ARRAY_A);

        foreach ($rows as $row) {
            $label = (string) $row['label'];
            $user_id = (int) $row['user_id'];
            $amount = round((float) $row['amount'], 2);
            if (in_array($label, $done, true)) {
                $out['пропустили']++;
                continue;
            }
            if ($user_id <= 0 || $amount <= 0 || !get_user_by('id', $user_id)) {
                $out['платежи'][] = array('метка' => $label, 'итог' => 'нет получателя');
                continue;
            }
            if ($dry) {
                $out['платежи'][] = array(
                    'метка'  => $label,
                    'кому'   => $user_id,
                    'сумма'  => $amount,
                    'итог'   => 'зачислим',
                );
                $out['зачислили']++;
                $out['сумма'] += $amount;
                continue;
            }

            $ok = class_exists('GS_SFX') ? GS_SFX::refund($user_id, $amount) : false;
            if ($ok) {
                $done[] = $label;
                $out['зачислили']++;
                $out['сумма'] += $amount;
                self::note($user_id, $amount, 'оплата ' . $label . ' — деньги пришли, баланс не изменился');
            }
            $out['платежи'][] = array(
                'метка' => $label,
                'кому'  => $user_id,
                'сумма' => $amount,
                'итог'  => $ok ? 'зачислено' : 'НЕ ВЫШЛО',
            );
        }

        if (!$dry) {
            update_option(self::OPT_CREDITED, array_values(array_unique($done)), false);
        }
        $out['сумма'] = round($out['сумма'], 2);
        return $out;
    }

    /** Запись в тот же журнал правок баланса, что и у ручных зачислений. */
    private static function note($user_id, $amount, $reason) {
        if (!class_exists('GS_Payments')) {
            return;
        }
        $log = get_option(GS_Payments::OPT_ADJUST_LOG, array());
        if (!is_array($log)) {
            $log = array();
        }
        $user = get_user_by('id', $user_id);
        array_unshift($log, array(
            'time'    => current_time('mysql'),
            'by'      => 'перевод балансов на сайт',
            'user'    => $user ? $user->user_login : (string) $user_id,
            'amount'  => (float) $amount,
            'reason'  => (string) $reason,
            'balance' => class_exists('KIE_TTS_DB') ? (float) KIE_TTS_DB::get_user_balance((int) $user_id) : 0.0,
        ));
        update_option(GS_Payments::OPT_ADJUST_LOG, array_slice($log, 0, GS_Payments::ADJUST_KEEP), false);
    }

    /* ---------------------------------------------------------------------
     * Для админки
     * ------------------------------------------------------------------ */

    /** Сколько людей ещё держат деньги в базе бота. */
    public static function still_in_bot() {
        return count((array) get_users(array('meta_key' => self::META_BOT, 'fields' => 'ID')));
    }
}
