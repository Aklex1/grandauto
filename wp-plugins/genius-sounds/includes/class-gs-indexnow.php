<?php
/**
 * IndexNow: рассказать поиску о новой странице сразу.
 *
 * Обычный путь — дождаться, пока робот сам зайдёт по карте сайта: по
 * справке Яндекса на это уходит от трёх до семи дней. IndexNow сокращает
 * ожидание: мы сами сообщаем адрес, как только страница изменилась.
 * Гарантии, что страницу возьмут в индекс, это не даёт — только то, что о
 * ней узнают без обхода.
 *
 * Ключ подтверждения лежит файлом в корне сайта: поиск забирает его по
 * адресу и убеждается, что адреса шлёт хозяин сайта, а не посторонний.
 *
 * Адреса копим в очереди и отправляем по расписанию пачкой: на каждую
 * правку стучаться наружу — значит складывать чужую задержку в своё время
 * ответа, а правок при массовом обновлении бывает много.
 */

if (!defined('ABSPATH')) {
    exit;
}

class GS_IndexNow {

    const OPT_KEY   = 'gs_indexnow_key';
    const OPT_QUEUE = 'gs_indexnow_queue';
    const OPT_LOG   = 'gs_indexnow_log';
    const CRON      = 'gs_indexnow_send';
    const ENDPOINT  = 'https://api.indexnow.org/indexnow';

    /** Сколько адресов отдаём за один заход и сколько помним в очереди. */
    const BATCH = 100;
    const QUEUE_MAX = 500;

    public static function boot() {
        add_action('init', array(__CLASS__, 'serve_key'), 1);
        add_action('transition_post_status', array(__CLASS__, 'on_post'), 10, 3);
        add_action(self::CRON, array(__CLASS__, 'flush'));
        add_filter('cron_schedules', array(__CLASS__, 'schedule'));

        if (!wp_next_scheduled(self::CRON)) {
            wp_schedule_event(time() + 300, 'gs_ten_minutes', self::CRON);
        }
    }

    public static function schedule($schedules) {
        if (!isset($schedules['gs_ten_minutes'])) {
            $schedules['gs_ten_minutes'] = array('interval' => 600, 'display' => 'Каждые десять минут');
        }
        return $schedules;
    }

    /** Ключ сайта: один на всё время, создаётся при первом обращении. */
    public static function key() {
        $key = (string) get_option(self::OPT_KEY, '');
        if (strlen($key) < 32) {
            $key = strtolower(wp_generate_password(32, false, false));
            update_option(self::OPT_KEY, $key, false);
        }
        return $key;
    }

    public static function key_url() {
        return home_url('/' . self::key() . '.txt');
    }

    /**
     * Отдаём файл с ключом. Делаем это сами, а не файлом на диске: файл
     * пришлось бы класть руками и помнить о нём при переносе сайта.
     */
    public static function serve_key() {
        $uri = isset($_SERVER['REQUEST_URI']) ? (string) wp_unslash($_SERVER['REQUEST_URI']) : '';
        $path = trim((string) wp_parse_url($uri, PHP_URL_PATH), '/');
        if ($path === '' || substr($path, -4) !== '.txt') {
            return;
        }
        if (strtolower(substr($path, 0, -4)) !== self::key()) {
            return;
        }
        header('Content-Type: text/plain; charset=UTF-8');
        header('Cache-Control: public, max-age=86400');
        echo esc_html(self::key());
        exit;
    }

    /** Публикация или правка записи — адрес в очередь. */
    public static function on_post($new_status, $old_status, $post) {
        if (!$post instanceof WP_Post || $new_status === $old_status && $new_status !== 'publish') {
            return;
        }
        if ($new_status !== 'publish' && $old_status !== 'publish') {
            return;
        }
        $type = get_post_type_object($post->post_type);
        if (!$type || empty($type->public)) {
            return;
        }
        if (wp_is_post_revision($post) || wp_is_post_autosave($post)) {
            return;
        }
        self::add(get_permalink($post));
    }

    /** Поставить адрес в очередь. */
    public static function add($url) {
        $url = esc_url_raw((string) $url);
        if ($url === '' || strpos($url, home_url('/')) !== 0) {
            return;
        }
        $queue = get_option(self::OPT_QUEUE);
        $queue = is_array($queue) ? $queue : array();
        if (in_array($url, $queue, true)) {
            return;
        }
        $queue[] = $url;
        if (count($queue) > self::QUEUE_MAX) {
            $queue = array_slice($queue, -self::QUEUE_MAX);
        }
        update_option(self::OPT_QUEUE, $queue, false);
    }

    /** Отправить накопленное. */
    public static function flush() {
        $queue = get_option(self::OPT_QUEUE);
        $queue = is_array($queue) ? array_values(array_unique($queue)) : array();
        if (!$queue) {
            return array('ok' => true, 'отправлено' => 0, 'сообщение' => 'очередь пуста');
        }

        $batch = array_slice($queue, 0, self::BATCH);
        $rest  = array_slice($queue, count($batch));

        $host = (string) wp_parse_url(home_url('/'), PHP_URL_HOST);
        $body = array(
            'host'        => $host,
            'key'         => self::key(),
            'keyLocation' => self::key_url(),
            'urlList'     => $batch,
        );

        $res = wp_remote_post(self::ENDPOINT, array(
            'timeout' => 20,
            'headers' => array('Content-Type' => 'application/json; charset=utf-8'),
            'body'    => wp_json_encode($body),
        ));

        $code = is_wp_error($res) ? 0 : (int) wp_remote_retrieve_response_code($res);
        $message = is_wp_error($res) ? $res->get_error_message() : (string) wp_remote_retrieve_response_message($res);

        // 200 и 202 — приняли. 429 — слишком часто, адреса оставляем в
        // очереди и пробуем в следующий заход.
        $done = in_array($code, array(200, 202), true);
        update_option(self::OPT_QUEUE, $done ? $rest : $queue, false);

        $log = get_option(self::OPT_LOG);
        $log = is_array($log) ? $log : array();
        array_unshift($log, array(
            'когда'   => current_time('mysql'),
            'адресов' => count($batch),
            'ответ'   => $code,
            'итог'    => $done ? 'принято' : ('не вышло: ' . $message),
        ));
        update_option(self::OPT_LOG, array_slice($log, 0, 20), false);

        return array('ok' => $done, 'отправлено' => $done ? count($batch) : 0,
            'ответ' => $code, 'сообщение' => $message, 'осталось' => count($done ? $rest : $queue));
    }

    /** Что происходило: для проверки из админки. */
    public static function state() {
        $queue = get_option(self::OPT_QUEUE);
        $log = get_option(self::OPT_LOG);
        $next = wp_next_scheduled(self::CRON);
        return array(
            'ключ'       => self::key_url(),
            'в_очереди'  => is_array($queue) ? count($queue) : 0,
            'след_заход' => $next ? get_date_from_gmt(gmdate('Y-m-d H:i:s', $next), 'Y-m-d H:i') : 'не запланирован',
            'история'    => is_array($log) ? array_slice($log, 0, 5) : array(),
        );
    }
}
