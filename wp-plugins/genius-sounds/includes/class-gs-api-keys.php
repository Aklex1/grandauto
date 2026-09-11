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

        return array('ok' => true, 'key' => $key, 'record' => $record, 'message' => '');
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
