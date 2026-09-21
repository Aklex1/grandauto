<?php
/**
 * Единая точка приёма платежей ЮMoney.
 *
 * Кошелёк один на все сервисы, а уведомление ЮMoney шлёт на один адрес.
 * Поэтому сайт принимает его сам и раскладывает по назначению:
 *
 *   kie-neurohub|…   → баланс раздела «Нейросети»
 *   topup_wp_…       → баланс кабинета озвучки (он же общий для микросервисов)
 *   topup_telegram_… → он же, для пользователей из бота
 *   остальное        → пересылается дальше, на прежний адрес бота
 *
 * Метки своих платежей узнаются по полному началу, а не по слову topup_:
 * у бота свой формат, и однажды он уже попал под это правило — платежи
 * уходили в кабинет озвучки, где такой метки нет, и терялись.
 *
 * Попутно ведётся журнал: по метке в назначении платежа видно, с какого
 * сервиса пришли деньги, даже если баланс у пользователя один.
 */

if (!defined('ABSPATH')) {
    exit;
}

class GS_Yoomoney {

    const OPT_SECRET  = 'gs_yoomoney_secret';
    const OPT_FORWARD = 'gs_yoomoney_forward';
    const OPT_LOG     = 'gs_yoomoney_log';
    /** Пока уведомление разбирается внутри сайта, повторно проверять подпись незачем. */
    private static $forwarding = false;
    const OPT_STATS   = 'gs_yoomoney_stats';
    const LOG_LIMIT   = 200;

    public static function boot() {
        add_action('rest_api_init', array(__CLASS__, 'register_routes'));
        add_action('admin_post_gs_yoomoney_reset', array(__CLASS__, 'handle_reset'));
    }

    /** Сброс журнала и подсчётов — например, после проверочных уведомлений. */
    public static function handle_reset() {
        if (!current_user_can('manage_options')) {
            wp_die('Недостаточно прав');
        }
        check_admin_referer('gs_yoomoney_reset');
        delete_option(self::OPT_LOG);
        delete_option(self::OPT_STATS);
        wp_safe_redirect(admin_url('admin.php?page=genius-sounds') . '#gs-payments');
        exit;
    }

    /** Идёт ли сейчас внутренняя пересылка уведомления. */
    public static function is_forwarding() {
        return self::$forwarding;
    }

    public static function endpoint_url() {
        return rest_url('genius/v1/yoomoney');
    }

    public static function register_routes() {
        register_rest_route('genius/v1', '/yoomoney', array(
            'methods'             => 'POST',
            'callback'            => array(__CLASS__, 'handle'),
            'permission_callback' => '__return_true',
        ));
    }

    /* ---------------------------------------------------------------------
     * Приём уведомления
     * ------------------------------------------------------------------ */

    public static function handle($request) {
        $params = $request->get_params();
        if (!is_array($params)) {
            $params = array();
        }
        $label  = isset($params['label']) ? (string) $params['label'] : '';
        $amount = isset($params['withdraw_amount']) ? (float) $params['withdraw_amount'] : (float) ($params['amount'] ?? 0);

        if (!self::signature_ok($params)) {
            self::remember($label, $amount, 'отклонено', 'подпись не сошлась', $params);
            return new WP_REST_Response(array('status' => 'error', 'credited' => false, 'reason' => 'bad signature'), 403);
        }

        // Тестовое уведомление из личного кабинета ЮMoney приходит без метки.
        if ($label === '') {
            self::remember('', $amount, 'пропущено', 'уведомление без метки', $params);
            return self::reply(false, 'none', 'уведомление без метки');
        }

        if (strpos($label, 'kie-neurohub|') === 0) {
            $result = self::forward_internal('/neurohub/v1/yoomoney-callback', $params);
            self::remember($label, $amount, $result['ok'] ? 'зачислено' : 'ошибка', $result['message'], $params, 'neurohub');
            return self::reply($result['ok'], 'neurohub', $result['message']);
        }

        if (strpos($label, 'topup_wp_') === 0 || strpos($label, 'topup_telegram_') === 0) {
            // Повторное уведомление по уже закрытому платежу баланс не
            // меняет — и это правильно. Сверять в таком случае нечего.
            $repeat = self::already_completed($label);
            $before = $repeat ? null : self::balance_by_label($label);
            $result = self::forward_internal('/tts/v1/yoomoney-webhook', $params);
            $result = self::confirm_credit($label, $before, $result);
            if (!$result['ok'] && stripos($result['message'], 'not found') !== false) {
                // Метка похожа на нашу, а платежа с ней нет. Чем терять
                // деньги, отдаём уведомление дальше — вдруг это бот.
                $sent = self::forward_external($params);
                self::remember($label, $amount, $sent['ok'] ? 'переслано' : 'ошибка',
                    $result['message'] . '; ' . $sent['message'], $params, 'bot');
                return self::reply(false, 'tts', $result['message']);
            }
            self::remember($label, $amount, $result['ok'] ? 'зачислено' : 'ошибка', $result['message'], $params);
            return self::reply($result['ok'], 'tts', $result['message']);
        }

        // Метка не наша: платёж заводил не сайт — скорее всего бот со своим
        // форматом метки. Пересылаем, если задан адрес, и в любом случае
        // честно говорим, что на сайте зачисления не было.
        $result = self::forward_external($params);
        self::remember($label, $amount, $result['ok'] ? 'переслано' : 'не наш платёж', $result['message'], $params, 'bot');
        return self::reply(false, 'unknown', $result['message']);
    }

    /**
     * Ответ в машиночитаемом виде: по нему приёмник на сервере понимает,
     * нужно ли отдавать платёж дальше. Код всегда 200 — иначе ЮMoney
     * будет повторять уведомление, хотя разбирать его уже некому.
     */
    private static function reply($credited, $route, $message) {
        return new WP_REST_Response(array(
            'status'   => 'ok',
            'credited' => (bool) $credited,
            'route'    => (string) $route,
            'message'  => (string) $message,
        ), 200);
    }

    /**
     * Подпись ЮMoney. Пока секрет не задан, проверять нечем — тогда
     * уведомления принимаются как раньше, но это видно в журнале.
     *
     * Подписей у них две: новая sign (HMAC-SHA256 по отсортированным
     * полям) и устаревшая sha1_hash по фиксированному порядку. Какая
     * придёт — зависит от настроек кошелька, поэтому принимаем обе:
     * отказывать деньгам из-за формата подписи неправильно.
     */
    public static function signature_ok($params) {
        $secret = trim((string) get_option(self::OPT_SECRET, ''));
        if ($secret === '') {
            return true;
        }

        if (!empty($params['sign'])) {
            $fields = $params;
            unset($fields['sign']);
            ksort($fields);
            $parts = array();
            foreach ($fields as $key => $value) {
                $parts[] = $key . '=' . rawurlencode((string) $value);
            }
            $calc = hash_hmac('sha256', implode('&', $parts), $secret);
            if (hash_equals($calc, strtolower((string) $params['sign']))) {
                return true;
            }
        }

        $provided = isset($params['sha1_hash']) ? strtolower((string) $params['sha1_hash']) : '';
        if ($provided === '') {
            return false;
        }
        $parts = array(
            (string) ($params['notification_type'] ?? ''),
            (string) ($params['operation_id'] ?? ''),
            (string) ($params['amount'] ?? ''),
            (string) ($params['currency'] ?? ''),
            (string) ($params['datetime'] ?? ''),
            (string) ($params['sender'] ?? ''),
            (string) ($params['codepro'] ?? ''),
            $secret,
            (string) ($params['label'] ?? ''),
        );
        return hash_equals(sha1(implode('&', $parts)), $provided);
    }

    /** Платёж уже закрыт — значит, уведомление повторное. */
    private static function already_completed($label) {
        if (!class_exists('KIE_TTS_Payment')) {
            return false;
        }
        $payment = KIE_TTS_Payment::get_payment_by_label($label);
        return is_array($payment) && (string) ($payment['status'] ?? '') === 'completed';
    }

    /**
     * Баланс плательщика по метке платежа.
     *
     * Нужен, чтобы проверить зачисление делом, а не на слово. У пользователей
     * из бота баланс лежит в его базе, у остальных — в таблице сайта; какой
     * случай, записано в самом платеже.
     *
     * @return float|null null — если посмотреть не получилось.
     */
    private static function balance_by_label($label) {
        if (!class_exists('KIE_TTS_Payment') || !class_exists('KIE_TTS_DB')) {
            return null;
        }
        $payment = KIE_TTS_Payment::get_payment_by_label($label);
        if (!is_array($payment) || empty($payment['user_id'])) {
            return null;
        }
        $is_telegram = !empty($payment['is_telegram']);
        $who = (int) $payment['user_id'];
        if ($is_telegram) {
            $who = class_exists('KIE_TTS_Auth') ? (int) KIE_TTS_Auth::get_telegram_id($who) : 0;
            if (!$who) {
                return null;
            }
        }
        return (float) KIE_TTS_DB::get_user_balance($who, $is_telegram);
    }

    /**
     * Действительно ли баланс вырос.
     *
     * Платёжный маршрут отвечает «обработано» и тогда, когда запись в базу
     * не удалась: возвращаемое значение он не проверяет. Для пользователей
     * из бота баланс лежит в чужой базе, до которой сайт может и не
     * достучаться, — и тогда деньги списаны, платёж помечен закрытым, а
     * баланс прежний, и в журнале об этом ни слова. Поэтому сверяем сами.
     */
    private static function confirm_credit($label, $before, $result) {
        if (!$result['ok'] || $before === null) {
            return $result;
        }
        $after = self::balance_by_label($label);
        if ($after === null || $after > $before + 0.001) {
            return $result;
        }
        return array(
            'ok' => false,
            'message' => sprintf(
                'платёж закрыт, но баланс не изменился (%s ₽ до и после) — зачислите вручную',
                number_format($before, 2, ',', ' ')
            ),
        );
    }

    /** Передаём уведомление нужному обработчику внутри сайта. */
    private static function forward_internal($route, $params) {
        $request = new WP_REST_Request('POST', $route);
        foreach ($params as $key => $value) {
            $request->set_param($key, $value);
        }
        self::$forwarding = true;
        $response = rest_do_request($request);
        self::$forwarding = false;
        $status = $response instanceof WP_REST_Response ? $response->get_status() : 0;
        $data = $response instanceof WP_REST_Response ? $response->get_data() : null;

        // Кабинет озвучки отвечает кодом 200 и на неудачу, поэтому смотрим
        // не только код, но и тело ответа — иначе в статистику попадут
        // платежи, которые на самом деле не зачислены.
        $failed = false;
        $message = 'обработано';
        if (is_array($data)) {
            if ((string) ($data['status'] ?? '') === 'error' || (isset($data['success']) && !$data['success'])) {
                $failed = true;
                $message = (string) ($data['message'] ?? 'обработчик отклонил платёж');
            }
        }
        if ($status >= 300 || $failed) {
            if (!$failed) {
                $message = 'код ' . $status;
                if (is_array($data) && !empty($data['message'])) {
                    $message .= ': ' . (string) $data['message'];
                }
            }
            return array('ok' => false, 'message' => $message);
        }
        return array('ok' => true, 'message' => $message);
    }

    /** Платежи бота идут дальше по прежнему адресу — его логику не трогаем. */
    private static function forward_external($params) {
        $url = trim((string) get_option(self::OPT_FORWARD, ''));
        if ($url === '') {
            return array('ok' => false, 'message' => 'адрес пересылки не задан');
        }
        $response = wp_remote_post($url, array(
            'timeout' => 20,
            'headers' => array('Content-Type' => 'application/x-www-form-urlencoded'),
            'body'    => $params,
        ));
        if (is_wp_error($response)) {
            return array('ok' => false, 'message' => $response->get_error_message());
        }
        return array('ok' => true, 'message' => 'код ' . wp_remote_retrieve_response_code($response));
    }

    /* ---------------------------------------------------------------------
     * Журнал и статистика по сервисам
     * ------------------------------------------------------------------ */

    /** Из назначения платежа достаём метку вида [src:vocal]. */
    public static function source_from($params) {
        $target = (string) ($params['targets'] ?? '');
        if (preg_match('~\[src:([a-z0-9_-]{2,20})\]~i', $target, $m)) {
            return strtolower($m[1]);
        }
        return '';
    }

    private static function remember($label, $amount, $status, $message, $params, $source = '') {
        if ($source === '') {
            $source = self::source_from($params);
        }
        if ($source === '') {
            $source = 'неизвестно';
        }

        $log = get_option(self::OPT_LOG, array());
        if (!is_array($log)) {
            $log = array();
        }
        array_unshift($log, array(
            'at'      => current_time('mysql'),
            'label'   => (string) $label,
            'amount'  => (float) $amount,
            'status'  => (string) $status,
            'message' => (string) $message,
            'source'  => (string) $source,
        ));
        update_option(self::OPT_LOG, array_slice($log, 0, self::LOG_LIMIT), false);

        if (in_array($status, array('зачислено', 'переслано'), true) && $amount > 0) {
            $stats = get_option(self::OPT_STATS, array());
            if (!is_array($stats)) {
                $stats = array();
            }
            if (empty($stats[$source])) {
                $stats[$source] = array('count' => 0, 'sum' => 0.0);
            }
            $stats[$source]['count']++;
            $stats[$source]['sum'] = round((float) $stats[$source]['sum'] + $amount, 2);
            update_option(self::OPT_STATS, $stats, false);
        }
    }

    public static function get_log() {
        $log = get_option(self::OPT_LOG, array());
        return is_array($log) ? $log : array();
    }

    public static function get_stats() {
        $stats = get_option(self::OPT_STATS, array());
        if (!is_array($stats)) {
            return array();
        }
        uasort($stats, function ($a, $b) {
            return (float) $b['sum'] <=> (float) $a['sum'];
        });
        return $stats;
    }
}
