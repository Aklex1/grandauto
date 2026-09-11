<?php
/**
 * Пометка платежей по сервисам.
 *
 * Баланс у пользователя один на все инструменты, и это правильно: пополнил
 * раз — тратишь везде. Но тогда по платежам не видно, какой сервис привёл
 * деньги. Поэтому в назначение платежа добавляется метка сервиса, с которого
 * человек пришёл пополняться: в выписке ЮMoney и в вебхуке она видна, а
 * идентификатор платежа остаётся нетронутым — сверка на стороне сайта
 * работает как работала.
 */

if (!defined('ABSPATH')) {
    exit;
}

class GS_Payments {

    const QUERY_ARG = 'gs_src';
    const COOKIE    = 'gs_pay_src';
    const TTL       = 3600;

    public static function boot() {
        add_action('init', array(__CLASS__, 'remember_source'), 5);
        add_filter('rest_request_after_callbacks', array(__CLASS__, 'mark_payment'), 20, 3);
    }

    /** Человеческие названия сервисов для назначения платежа. */
    public static function sources() {
        return array(
            'sfx'      => 'генератор звуков',
            'avatar'   => 'говорящий аватар',
            'vocal'    => 'минусовка',
            'denoise'  => 'очистка записи',
            'catalog'  => 'каталог звуков',
            'api'      => 'API для разработчиков',
            'neurohub' => 'нейросети для фото',
            'tts'      => 'озвучка текста',
        );
    }

    /**
     * Откуда пришёл пользователь — запоминаем на час.
     */
    public static function remember_source() {
        if (is_admin() || empty($_GET[self::QUERY_ARG])) {
            return;
        }
        $source = sanitize_key(wp_unslash((string) $_GET[self::QUERY_ARG]));
        if (!array_key_exists($source, self::sources())) {
            return;
        }
        if (!headers_sent()) {
            setcookie(self::COOKIE, $source, time() + self::TTL, COOKIEPATH ?: '/', COOKIE_DOMAIN, is_ssl(), true);
        }
        $_COOKIE[self::COOKIE] = $source;
    }

    public static function current_source() {
        $source = isset($_COOKIE[self::COOKIE]) ? sanitize_key(wp_unslash((string) $_COOKIE[self::COOKIE])) : '';
        return array_key_exists($source, self::sources()) ? $source : '';
    }

    /** Ссылка на пополнение с пометкой сервиса. */
    public static function topup_url($source) {
        $url = GS_Pages::get_dashboard_url();
        return array_key_exists($source, self::sources())
            ? add_query_arg(self::QUERY_ARG, $source, $url)
            : $url;
    }

    /* ---------------------------------------------------------------------
     * Правка назначения платежа
     * ------------------------------------------------------------------ */

    public static function mark_payment($response, $handler, $request) {
        if (!($request instanceof WP_REST_Request)) {
            return $response;
        }
        if (strpos((string) $request->get_route(), '/tts/v1/') === false
            || strpos((string) $request->get_route(), 'topup') === false) {
            return $response;
        }
        if (!($response instanceof WP_REST_Response)) {
            return $response;
        }
        $body = $response->get_data();
        if (!is_array($body) || empty($body['payment_link'])) {
            return $response;
        }

        $source = self::current_source();
        if ($source === '') {
            $source = 'tts';
        }
        $names = self::sources();
        $target = 'Genius-bot: пополнение баланса — ' . $names[$source] . ' [src:' . $source . ']';

        // Меняем только назначение платежа: идентификатор, по которому
        // сайт сверяет оплату, трогать нельзя.
        $body['payment_link'] = add_query_arg('targets', rawurlencode($target), remove_query_arg('targets', (string) $body['payment_link']));
        $body['source'] = $source;
        $response->set_data($body);
        return $response;
    }
}
