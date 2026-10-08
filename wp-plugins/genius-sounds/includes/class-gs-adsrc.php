<?php
/**
 * Откуда человек пришёл: метка перехода на платеже.
 *
 * Сайт и так помнит, из какого сервиса человек пошёл платить, — но не
 * помнит, что привело его на сайт. Пока рекламы не было, разницы не
 * было: платёж из нейрохаба он и есть платёж из нейрохаба. С запуском
 * Директа вопрос «сколько принесла кампания» без этой метки не
 * отвечается вовсе: в отчёте все платежи выглядят одинаково.
 *
 * Метку ставим один раз при переходе и носим с человеком, потому что
 * платят редко в первый визит. Кука живёт три месяца — за это время
 * возвращаются почти все, кто вообще вернётся.
 *
 * Пишет куку не PHP, а скрипт в браузере, и на то есть причина: хостинг
 * вырезает utm-метки из запроса ещё до PHP, так что сервер их просто не
 * видит. В адресной строке они остаются, и браузеру доступны. Заодно это
 * работает и на страницах из кэша, где PHP вообще не запускается.
 */

if (!defined('ABSPATH')) {
    exit;
}

class GS_Adsrc {

    const COOKIE = 'gs_ad';
    const META   = 'gs_ad_first';
    const TTL    = 7776000; // 90 дней

    public static function boot() {
        // Вошедшему метку переносим на учётную запись: кука живёт в одном
        // браузере, а платит человек иногда с другого устройства.
        add_action('wp_login', array(__CLASS__, 'on_login'), 10, 2);
        add_action('user_register', array(__CLASS__, 'on_register'));
    }

    /* ---------------------------------------------------------------------
     * Чтение
     * ------------------------------------------------------------------ */

    /**
     * Метка текущего посетителя.
     *
     * @return array{src:string,medium:string,campaign:string,group:string,keyword:string,at:string}
     */
    public static function current() {
        $raw = isset($_COOKIE[self::COOKIE]) ? (string) wp_unslash($_COOKIE[self::COOKIE]) : '';
        return self::parse($raw);
    }

    public static function parse($raw) {
        $out = array('src' => '', 'medium' => '', 'campaign' => '',
                     'group' => '', 'keyword' => '', 'at' => '');
        $raw = trim((string) $raw);
        if ($raw === '') {
            return $out;
        }
        $pairs = array();
        parse_str($raw, $pairs);
        if (!is_array($pairs)) {
            return $out;
        }
        $map = array('s' => 'src', 'm' => 'medium', 'c' => 'campaign',
                     'g' => 'group', 'k' => 'keyword', 't' => 'at');
        foreach ($map as $short => $long) {
            if (!empty($pairs[$short])) {
                $out[$long] = mb_substr(sanitize_text_field((string) $pairs[$short]), 0, 120, 'UTF-8');
            }
        }
        return $out;
    }

    /** Есть ли вообще метка. */
    public static function has($mark) {
        return is_array($mark) && ($mark['src'] !== '' || $mark['keyword'] !== '');
    }

    /**
     * Человеку понятной строкой.
     *
     * Номер группы сам по себе ничего не говорит, поэтому рядом с ним
     * печатаем фразу: по ней в отчёте видно, за какой запрос заплачено.
     */
    public static function label($mark = null) {
        $m = is_array($mark) ? $mark : self::current();
        if (!self::has($m)) {
            return '';
        }
        $parts = array();
        $src = $m['src'] !== '' ? $m['src'] : 'переход';
        if ($src === 'yandex' && $m['medium'] === 'cpc') {
            $parts[] = 'Директ';
        } else {
            $parts[] = $src . ($m['medium'] !== '' ? ' / ' . $m['medium'] : '');
        }
        if ($m['campaign'] !== '') {
            $parts[] = $m['campaign'];
        }
        if ($m['group'] !== '') {
            $parts[] = 'группа ' . $m['group'];
        }
        if ($m['keyword'] !== '') {
            $parts[] = '«' . $m['keyword'] . '»';
        }
        return implode(' · ', $parts);
    }

    /* ---------------------------------------------------------------------
     * Перенос на учётную запись
     * ------------------------------------------------------------------ */

    public static function on_login($login, $user) {
        if ($user instanceof WP_User) {
            self::remember_for($user->ID);
        }
    }

    public static function on_register($user_id) {
        self::remember_for((int) $user_id);
    }

    /** Первую метку не затираем: она и есть ответ на вопрос «кто привёл». */
    public static function remember_for($user_id) {
        $user_id = (int) $user_id;
        if ($user_id <= 0) {
            return;
        }
        $mark = self::current();
        if (!self::has($mark)) {
            return;
        }
        if (get_user_meta($user_id, self::META, true)) {
            return;
        }
        update_user_meta($user_id, self::META, $mark);
    }

    public static function of_user($user_id) {
        $mark = get_user_meta((int) $user_id, self::META, true);
        return is_array($mark) ? $mark : array('src' => '', 'medium' => '', 'campaign' => '',
                                               'group' => '', 'keyword' => '', 'at' => '');
    }

    /**
     * Метка для платежа: сначала браузер, потом учётная запись.
     *
     * Куку человек мог потерять — почистил браузер, вернулся с другого
     * устройства. Тогда берём ту, что осталась на учётной записи с
     * первого прихода.
     */
    public static function for_payment($user_id = 0) {
        $mark = self::current();
        if (self::has($mark)) {
            return $mark;
        }
        $user_id = (int) $user_id ?: get_current_user_id();
        return $user_id > 0 ? self::of_user($user_id) : $mark;
    }
}
