<?php
/**
 * Яндекс.Метрика: цели и проверка, что считается.
 *
 * Вебмастер и Метрика живут на разных хостах API, поэтому ходить в них
 * одним клиентом не выходит. Токен берём тот же — он выдан на аккаунт, и
 * если у него есть права на Метрику, этого достаточно.
 *
 * Нужно это ради одного вопроса: трафик каталога звуков вырос в пять раз,
 * а платят по-прежнему единицы. Без целей не понять, где теряются люди —
 * на переходе из каталога в студию, на регистрации или на оплате.
 */

if (!defined('ABSPATH')) {
    exit;
}

class GS_Metrika {

    const API = 'https://api-metrika.yandex.net';
    const OPT_COUNTER = 'gs_metrika_counter';
    const OPT_TOKEN   = 'gs_metrika_token';

    public static function boot() {
        add_action('admin_post_gs_metrika_save', array(__CLASS__, 'handle_save'));
    }

    /**
     * Номер счётчика и токен с правами на Метрику.
     *
     * Токен Вебмастера Метрика не принимает — он выдан без metrika:write,
     * и на запрос целей отвечает 403. Поэтому здесь свой: если он задан,
     * берём его, если нет — пробуем вебмастерский, вдруг права совпали.
     */
    public static function handle_save() {
        if (!current_user_can('manage_options')) {
            wp_die('Недостаточно прав');
        }
        check_admin_referer('gs_metrika_save');

        $counter = (int) ($_POST['counter'] ?? 0);
        if ($counter > 0) {
            update_option(self::OPT_COUNTER, $counter, false);
        }
        $token = trim((string) wp_unslash((string) ($_POST['token'] ?? '')));
        if ($token !== '') {
            update_option(self::OPT_TOKEN, $token, false);
        }

        $check = self::call('/management/v1/counters');
        $message = !empty($check['ok'])
            ? 'ok: Метрика отвечает, счётчик ' . self::counter()
            : (string) $check['message'];

        set_transient('gs_payment_notice', $message, 60);
        wp_safe_redirect(admin_url('admin.php?page=genius-sounds') . '#gs-metrika');
        exit;
    }

    public static function counter() {
        return (int) get_option(self::OPT_COUNTER, 0);
    }

    private static function token() {
        $own = trim((string) get_option(self::OPT_TOKEN, ''));
        if ($own !== '') {
            return $own;
        }
        return class_exists('GS_Webmaster') ? GS_Webmaster::token() : '';
    }

    public static function call($path, $method = 'GET', $payload = null) {
        $token = self::token();
        if ($token === '') {
            return array('ok' => false, 'body' => array(), 'message' => 'Токен Яндекса не задан');
        }

        $args = array(
            'timeout' => 45,
            'method'  => strtoupper($method),
            'headers' => array(
                'Authorization' => 'OAuth ' . $token,
                'Content-Type'  => 'application/json',
            ),
        );
        if ($payload !== null) {
            $args['body'] = wp_json_encode($payload);
        }

        $response = wp_remote_request(self::API . $path, $args);
        if (is_wp_error($response)) {
            return array('ok' => false, 'body' => array(), 'message' => $response->get_error_message());
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $body = json_decode((string) wp_remote_retrieve_body($response), true);
        $body = is_array($body) ? $body : array();

        if ($code === 401 || $code === 403) {
            return array('ok' => false, 'body' => $body, 'code' => $code,
                'message' => 'Токен не принят Метрикой: выдан без прав metrika:write');
        }
        if ($code >= 400) {
            $why = (string) ($body['message'] ?? $body['errors'][0]['message'] ?? ('код ' . $code));
            return array('ok' => false, 'body' => $body, 'code' => $code,
                'message' => 'Метрика ответила ошибкой: ' . $why);
        }
        return array('ok' => true, 'body' => $body, 'code' => $code, 'message' => '');
    }
}
