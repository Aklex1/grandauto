<?php
/**
 * Ключи доступа к публичному API.
 *
 * Ключ выдаётся один раз и больше нигде не хранится в открытом виде: в базе
 * лежит только хеш, префикс для опознания и секрет для подписи вебхуков.
 * Один пользователь может держать несколько ключей — например, отдельный
 * для боевого сервиса и отдельный для тестов.
 */

if (!defined('ABSPATH')) {
    exit;
}

class GS_Api_Keys {

    const OPT_INDEX = 'gs_api_keys';
    const PREFIX    = 'gb_';
    const MAX_PER_USER = 5;

    /**
     * Пробный баланс при первом ключе.
     *
     * 50 ₽ — это круг проверок: картинка (9 ₽), звук (9 ₽), расшифровка
     * (10 ₽), озвучка (18 ₽). Видео за 119 ₽ на пробный баланс не купить,
     * и это намеренно: в самом дорогом для нас случае подарок стоит
     * 10-15 ₽ настоящих денег у поставщика.
     *
     * Опасность не в сумме, а в количестве: аккаунты бесплатны, поэтому
     * рядом стоит месячный предел на все подарки вместе — он и держит
     * расход, сколько бы регистраций ни пришло со статей.
     */
    const OPT_TRIAL  = 'gs_api_trial';
    const TRIAL_META = 'gs_api_trial_given';
    const TRIAL_DEFAULT = 50;

    /** Сколько всего отдаём на пробы за месяц; ноль — без предела. */
    const OPT_TRIAL_BUDGET = 'gs_api_trial_budget';
    const TRIAL_BUDGET_DEFAULT = 3000;
    const OPT_TRIAL_SPENT  = 'gs_api_trial_spent';

    /** @return array<string,array> хеш => запись */
    public static function index() {
        $index = get_option(self::OPT_INDEX, array());
        return is_array($index) ? $index : array();
    }

    private static function save_index($index) {
        update_option(self::OPT_INDEX, $index, false);
    }

    private static function hash($key) {
        return hash('sha256', (string) $key);
    }

    /**
     * Выдаёт новый ключ. Открытое значение возвращается ровно один раз.
     *
     * @return array{ok:bool,key:string,record:array,message:string}
     */
    public static function issue($user_id, $label = '') {
        $user_id = (int) $user_id;
        if ($user_id <= 0) {
            return array('ok' => false, 'key' => '', 'record' => array(), 'message' => 'Нужен вход в аккаунт');
        }
        if (count(self::for_user($user_id)) >= self::MAX_PER_USER) {
            return array('ok' => false, 'key' => '', 'record' => array(), 'message' => 'Больше пяти ключей на аккаунт не выдаём — отзовите ненужный');
        }

        $key = self::PREFIX . bin2hex(random_bytes(20));
        $record = array(
            'user_id'   => $user_id,
            'label'     => sanitize_text_field($label) ?: 'Ключ от ' . date_i18n('d.m.Y'),
            'prefix'    => substr($key, 0, 11),
            'secret'    => bin2hex(random_bytes(16)),
            'created'   => current_time('mysql'),
            'last_used' => '',
            'calls'     => 0,
        );

        $index = self::index();
        $index[self::hash($key)] = $record;
        self::save_index($index);

        return array(
            'ok'      => true,
            'key'     => $key,
            'record'  => $record,
            'trial'   => self::grant_trial($user_id),
            'message' => '',
        );
    }

    /** Сколько дарим на пробу — ноль выключает подарок совсем. */
    public static function trial_amount() {
        $value = get_option(self::OPT_TRIAL, null);
        if ($value === null || $value === '') {
            return (float) self::TRIAL_DEFAULT;
        }
        return max(0.0, (float) $value);
    }

    /**
     * Пробный баланс при первом ключе.
     *
     * Разработчик не станет платить, чтобы проверить, работает ли сервис:
     * он возьмёт тот, где можно попробовать даром. Поэтому дарим сразу при
     * выпуске ключа, а не «по запросу в поддержку».
     *
     * Отметка стоит на пользователе, а не на ключе: иначе выпуск второго
     * ключа принёс бы ещё сотню, и так до пяти.
     *
     * @return float сколько начислили (ноль — не начисляли)
     */
    public static function grant_trial($user_id) {
        $amount = self::trial_amount();
        if ($amount <= 0 || get_user_meta($user_id, self::TRIAL_META, true)) {
            return 0.0;
        }
        if (!class_exists('GS_SFX') || !GS_SFX::balance_available()) {
            return 0.0;
        }
        if (!self::trial_budget_left($amount)) {
            // Месячный предел исчерпан. Ключ всё равно выдаём: человек
            // пришёл работать, а не за подарком.
            return 0.0;
        }
        // Отметку ставим до начисления: если начисление не пройдёт, повтор
        // случится по обращению человека, а не сам по себе пять раз.
        update_user_meta($user_id, self::TRIAL_META, current_time('mysql'));
        if (!GS_SFX::refund($user_id, $amount)) {
            return 0.0;
        }
        self::trial_spend($amount);
        return $amount;
    }

    /** Месячный предел на подарки: ноль в настройке — предела нет. */
    public static function trial_budget() {
        $value = get_option(self::OPT_TRIAL_BUDGET, null);
        if ($value === null || $value === '') {
            return (float) self::TRIAL_BUDGET_DEFAULT;
        }
        return max(0.0, (float) $value);
    }

    /** Сколько подарков уже отдано в текущем месяце. */
    public static function trial_spent() {
        $row = get_option(self::OPT_TRIAL_SPENT, array());
        $month = current_time('Y-m');
        if (!is_array($row) || ($row['month'] ?? '') !== $month) {
            return 0.0;
        }
        return (float) ($row['sum'] ?? 0);
    }

    private static function trial_budget_left($amount) {
        $budget = self::trial_budget();
        if ($budget <= 0) {
            return true;
        }
        return (self::trial_spent() + $amount) <= $budget;
    }

    private static function trial_spend($amount) {
        $month = current_time('Y-m');
        $row = get_option(self::OPT_TRIAL_SPENT, array());
        $sum = (is_array($row) && ($row['month'] ?? '') === $month) ? (float) ($row['sum'] ?? 0) : 0.0;
        update_option(self::OPT_TRIAL_SPENT, array('month' => $month, 'sum' => $sum + $amount), false);
    }

    public static function revoke($user_id, $prefix) {
        $user_id = (int) $user_id;
        $index = self::index();
        $changed = false;
        foreach ($index as $hash => $record) {
            if ((int) $record['user_id'] !== $user_id) {
                continue;
            }
            if ((string) $record['prefix'] !== (string) $prefix) {
                continue;
            }
            unset($index[$hash]);
            $changed = true;
        }
        if ($changed) {
            self::save_index($index);
        }
        return $changed;
    }

    /** Ключи пользователя без секретов — для личного кабинета. */
    public static function for_user($user_id) {
        $user_id = (int) $user_id;
        $out = array();
        foreach (self::index() as $record) {
            if ((int) $record['user_id'] !== $user_id) {
                continue;
            }
            $out[] = array(
                'label'     => (string) $record['label'],
                'prefix'    => (string) $record['prefix'],
                'created'   => (string) $record['created'],
                'last_used' => (string) $record['last_used'],
                'calls'     => (int) $record['calls'],
            );
        }
        return $out;
    }

    /**
     * Ключ из заголовка запроса: Authorization: Bearer … или X-Api-Key.
     */
    public static function key_from_request($request) {
        if (!($request instanceof WP_REST_Request)) {
            return '';
        }
        $header = (string) $request->get_header('authorization');
        if ($header !== '' && stripos($header, 'bearer ') === 0) {
            return trim(substr($header, 7));
        }
        $direct = (string) $request->get_header('x-api-key');
        if ($direct !== '') {
            return trim($direct);
        }
        return '';
    }

    /**
     * @return array{user_id:int,secret:string,prefix:string}
     */
    public static function resolve($key) {
        $empty = array('user_id' => 0, 'secret' => '', 'prefix' => '');
        $key = trim((string) $key);
        if ($key === '' || strpos($key, self::PREFIX) !== 0) {
            return $empty;
        }
        $index = self::index();
        $hash = self::hash($key);
        if (!isset($index[$hash])) {
            return $empty;
        }
        $record = $index[$hash];

        // Отметка об использовании нужна владельцу ключа, чтобы понимать,
        // какой из них ещё живой. Пишем не чаще раза в минуту.
        $now = current_time('mysql');
        if (substr((string) $record['last_used'], 0, 16) !== substr($now, 0, 16)) {
            $index[$hash]['last_used'] = $now;
            $index[$hash]['calls'] = (int) $record['calls'] + 1;
            self::save_index($index);
        }

        return array(
            'user_id' => (int) $record['user_id'],
            'secret'  => (string) $record['secret'],
            'prefix'  => (string) $record['prefix'],
        );
    }
}
