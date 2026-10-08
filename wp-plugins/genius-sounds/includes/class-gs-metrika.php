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

    /** Очередь конверсий и отметка, что цель заведена. */
    const OPT_QUEUE = 'gs_metrika_queue';
    const OPT_GOAL  = 'gs_metrika_goal_ready';

    /** Имя цели: одно и для события в браузере, и для загрузки с сервера. */
    const GOAL = 'payment';

    const CRON = 'gs_metrika_flush';

    public static function boot() {
        add_action('admin_post_gs_metrika_save', array(__CLASS__, 'handle_save'));
        add_action(self::CRON, array(__CLASS__, 'flush'));
        if (!wp_next_scheduled(self::CRON)) {
            wp_schedule_event(time() + 600, 'hourly', self::CRON);
        }
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

    /* ---------------------------------------------------------------------
     * Доход по оплатам
     *
     * Клики Метрика считает сама, а денег не видит: оплату подтверждает
     * ЮMoney уведомлением на сервер, когда человека на сайте уже нет.
     * Поэтому оплату отдаём отдельно, офлайн-конверсией, и привязываем к
     * визиту по номеру — ClientID Метрики или yclid Директа.
     *
     * Ради этого всё и затевалось: с доходом в Метрике Директ видит
     * выручку по каждой группе и фразе и умеет оптимизировать показы на
     * деньги, а не на клики.
     *
     * Отправляем не сразу, а очередью: уведомление об оплате должно
     * закрыться быстро и не зависеть от того, ответила ли Метрика.
     * ------------------------------------------------------------------ */

    /** Отложить оплату до ближайшей отправки. */
    public static function queue_payment($label, $amount) {
        $amount = round((float) $amount, 2);
        if ($amount <= 0 || self::counter() <= 0) {
            return;
        }
        $ids = class_exists('GS_Payments')
            ? GS_Payments::payment_ids_of((string) $label)
            : array('ymid' => '', 'yclid' => '');
        if ($ids['ymid'] === '' && $ids['yclid'] === '') {
            // Привязать не к чему: без номера визита Метрика такую строку
            // всё равно отбросит, а очередь копить незачем.
            return;
        }
        $queue = get_option(self::OPT_QUEUE, array());
        if (!is_array($queue)) {
            $queue = array();
        }
        $queue[(string) $label] = array(
            'price' => $amount,
            'at'    => time(),
            'ymid'  => (string) $ids['ymid'],
            'yclid' => (string) $ids['yclid'],
        );
        // Очередь не должна расти без предела, если Метрика недоступна
        // неделями: держим последние двести оплат.
        if (count($queue) > 200) {
            $queue = array_slice($queue, -200, null, true);
        }
        update_option(self::OPT_QUEUE, $queue, false);
    }

    /**
     * Отправить накопленное.
     *
     * Метрика принимает конверсии не старше трёх недель, поэтому
     * просроченные просто выбрасываем: висеть в очереди вечно им незачем.
     */
    public static function flush() {
        $queue = get_option(self::OPT_QUEUE, array());
        if (!is_array($queue) || !$queue || self::counter() <= 0) {
            return array('ok' => true, 'sent' => 0, 'message' => 'нечего отправлять');
        }
        if (!self::ensure_goal()) {
            return array('ok' => false, 'sent' => 0,
                'message' => 'цель «' . self::GOAL . '» не заведена в Метрике');
        }

        $edge = time() - 20 * DAY_IN_SECONDS;
        $by_yclid = array();
        $by_client = array();
        $stale = array();
        foreach ($queue as $label => $row) {
            if ((int) ($row['at'] ?? 0) < $edge) {
                $stale[] = $label;
                continue;
            }
            $line = array(self::GOAL, (int) $row['at'], number_format((float) $row['price'], 2, '.', ''), 'RUB');
            if (!empty($row['yclid'])) {
                $by_yclid[$label] = array_merge(array((string) $row['yclid']), $line);
            } elseif (!empty($row['ymid'])) {
                $by_client[$label] = array_merge(array((string) $row['ymid']), $line);
            }
        }

        $sent = 0;
        $msg = array();
        foreach (array(
            array('YCLID', 'Yclid', $by_yclid),
            array('CLIENT_ID', 'ClientId', $by_client),
        ) as $batch) {
            list($type, $head, $rows) = $batch;
            if (!$rows) {
                continue;
            }
            $csv = $head . ",Target,DateTime,Price,Currency\n";
            foreach ($rows as $row) {
                $csv .= implode(',', $row) . "\n";
            }
            $res = self::upload_csv($csv, $type);
            if (!empty($res['ok'])) {
                $sent += count($rows);
                foreach (array_keys($rows) as $label) {
                    unset($queue[$label]);
                }
            } else {
                $msg[] = (string) $res['message'];
            }
        }
        foreach ($stale as $label) {
            unset($queue[$label]);
        }
        update_option(self::OPT_QUEUE, $queue, false);

        return array('ok' => !$msg, 'sent' => $sent,
            'message' => $msg ? implode('; ', $msg) : ('отправлено оплат: ' . $sent));
    }

    /** Сколько оплат ждёт отправки. */
    public static function queue_size() {
        $queue = get_option(self::OPT_QUEUE, array());
        return is_array($queue) ? count($queue) : 0;
    }

    /**
     * Цель в счётчике.
     *
     * Загрузка привязывается к цели по имени, и если её нет, Метрика
     * молча отбрасывает строки. Заводим один раз сами — то же имя потом
     * годится и для события из браузера.
     */
    public static function ensure_goal() {
        if (get_option(self::OPT_GOAL) === self::GOAL) {
            return true;
        }
        $counter = self::counter();
        if ($counter <= 0) {
            return false;
        }
        $list = self::call('/management/v1/counter/' . $counter . '/goals');
        if (!empty($list['ok'])) {
            foreach ((array) ($list['body']['goals'] ?? array()) as $goal) {
                foreach ((array) ($goal['conditions'] ?? array()) as $cond) {
                    if ((string) ($cond['url'] ?? '') === self::GOAL) {
                        update_option(self::OPT_GOAL, self::GOAL, false);
                        return true;
                    }
                }
            }
        }
        $made = self::call('/management/v1/counter/' . $counter . '/goals', 'POST', array(
            'goal' => array(
                'name'       => 'Оплата',
                'type'       => 'action',
                'is_retargeting' => 0,
                'conditions' => array(array('type' => 'exact', 'url' => self::GOAL)),
            ),
        ));
        if (empty($made['ok'])) {
            return false;
        }
        update_option(self::OPT_GOAL, self::GOAL, false);
        return true;
    }

    /**
     * Загрузка конверсий файлом.
     *
     * Метрика ждёт multipart с полем file, а не JSON, поэтому общий
     * call() здесь не годится — тело собираем руками.
     */
    private static function upload_csv($csv, $client_id_type) {
        $token = self::token();
        if ($token === '') {
            return array('ok' => false, 'message' => 'Токен Яндекса не задан');
        }
        $boundary = 'gs' . md5((string) microtime(true));
        $body  = '--' . $boundary . "\r\n";
        $body .= 'Content-Disposition: form-data; name="file"; filename="conversions.csv"' . "\r\n";
        $body .= 'Content-Type: text/csv' . "\r\n\r\n";
        $body .= $csv . "\r\n";
        $body .= '--' . $boundary . "--\r\n";

        $url = self::API . '/management/v1/counter/' . self::counter()
            . '/offline_conversions/upload?client_id_type=' . rawurlencode($client_id_type);

        $response = wp_remote_post($url, array(
            'timeout' => 60,
            'headers' => array(
                'Authorization' => 'OAuth ' . $token,
                'Content-Type'  => 'multipart/form-data; boundary=' . $boundary,
            ),
            'body' => $body,
        ));
        if (is_wp_error($response)) {
            return array('ok' => false, 'message' => $response->get_error_message());
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        $data = json_decode((string) wp_remote_retrieve_body($response), true);
        $data = is_array($data) ? $data : array();
        if ($code >= 400) {
            $why = (string) ($data['message'] ?? $data['errors'][0]['message'] ?? ('код ' . $code));
            return array('ok' => false, 'message' => 'Метрика отказала (' . $client_id_type . '): ' . $why);
        }
        return array('ok' => true, 'message' => '', 'body' => $data);
    }
}
