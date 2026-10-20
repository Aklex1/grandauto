<?php
/**
 * Генерации на арендованном GPU.
 *
 * Как это устроено целиком.
 *
 * 1. На vast.ai арендуется машина с видеокартой и поднимается наш
 *    маленький HTTP-сервис (папка worker). Он умеет три вещи: сказать,
 *    что жив, принять задание и отдать результат.
 * 2. Сайт отправляет задание и сразу получает его номер. Генерация идёт
 *    десятки секунд, и держать всё это время PHP-запрос нельзя: упрётся
 *    в таймаут веб-сервера.
 * 3. Готовый кадр машина присылает обратно на наш маршрут обратного
 *    вызова, подписанный общим ключом. Если обратный вызов не дошёл —
 *    а он не дойдёт, если у машины нет белого адреса, — задания
 *    добираются опросом по расписанию. Поэтому путей два, и оба нужны.
 * 4. Картинка сохраняется в медиатеку сайта: машину выключат, её адрес
 *    умрёт вместе с арендой, а работа ученика должна остаться.
 *
 * Почему не считаем на своём хостинге: там нет видеокарты, а на
 * процессоре один кадр считается минутами. Почему не через чужой API:
 * на обучении люди переделывают кадр по двадцать раз, и поштучная
 * оплата съела бы обещанный «месяц без лимита».
 */

if (!defined('ABSPATH')) {
    exit;
}

class GC_Gpu {

    const OPT  = 'gc_gpu';
    const HOOK = 'gc_gpu_tick';

    /** Сколько заданий в сутки на ученика. Защита от зацикленного скрипта. */
    const DAILY_DEFAULT = 300;

    public static function boot() {
        add_action(self::HOOK, array(__CLASS__, 'tick'));
        add_action('init', array(__CLASS__, 'ensure_cron'));
    }

    public static function ensure_cron() {
        if (!wp_next_scheduled(self::HOOK)) {
            wp_schedule_event(time() + 60, 'gc_minute', self::HOOK);
        }
    }

    public static function settings() {
        $saved = get_option(self::OPT, array());
        return wp_parse_args(is_array($saved) ? $saved : array(), array(
            'enabled'  => 0,
            'endpoint' => '',
            'token'    => '',
            'secret'   => '',
            'model'    => 'sdxl',
            'daily'    => self::DAILY_DEFAULT,
        ));
    }

    public static function save_settings($values) {
        $now = self::settings();
        $now['enabled']  = !empty($values['enabled']) ? 1 : 0;
        $now['endpoint'] = esc_url_raw(trim((string) ($values['endpoint'] ?? '')));
        $now['token']    = sanitize_text_field((string) ($values['token'] ?? ''));
        $now['model']    = sanitize_text_field((string) ($values['model'] ?? 'sdxl'));
        $now['daily']    = max(1, (int) ($values['daily'] ?? self::DAILY_DEFAULT));
        if (empty($now['secret'])) {
            // Ключ подписи обратного вызова заводим один раз и не показываем
            // в настройках целиком: его знает только машина.
            $now['secret'] = wp_generate_password(40, false);
        }
        update_option(self::OPT, $now, false);
        return $now;
    }

    public static function ready() {
        $s = self::settings();
        return !empty($s['enabled']) && $s['endpoint'] !== '';
    }

    /** Жива ли машина. Ответ кэшируем на минуту: опрашивать чаще незачем. */
    public static function health() {
        $s = self::settings();
        if ($s['endpoint'] === '') {
            return array('ok' => false, 'message' => 'Адрес машины не указан');
        }
        $cached = get_transient('gc_gpu_health');
        if (is_array($cached)) {
            return $cached;
        }
        $res = wp_remote_get(trailingslashit($s['endpoint']) . 'health', array(
            'timeout' => 8,
            'headers' => array('Authorization' => 'Bearer ' . $s['token']),
        ));
        if (is_wp_error($res)) {
            $out = array('ok' => false, 'message' => $res->get_error_message());
        } else {
            $code = (int) wp_remote_retrieve_response_code($res);
            $body = json_decode((string) wp_remote_retrieve_body($res), true);
            $out = $code === 200
                ? array('ok' => true, 'message' => isset($body['gpu']) ? (string) $body['gpu'] : 'на связи')
                : array('ok' => false, 'message' => 'машина ответила ' . $code);
        }
        set_transient('gc_gpu_health', $out, MINUTE_IN_SECONDS);
        return $out;
    }

    public static function used_today($user_id) {
        global $wpdb;
        $table = GC_Install::jobs_table();
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE user_id = %d AND created_at > %s",
            (int) $user_id, gmdate('Y-m-d H:i:s', time() - DAY_IN_SECONDS)
        ));
    }

    /**
     * Поставить задание.
     *
     * @return array{ok:bool,job:int,status:string,message:string}
     */
    public static function submit($user_id, $prompt, $params = array()) {
        $prompt = trim(wp_strip_all_tags((string) $prompt));
        if (mb_strlen($prompt) < 3) {
            return array('ok' => false, 'job' => 0, 'status' => '', 'message' => 'Опишите кадр словами — хотя бы одной фразой.');
        }
        if (!GC_Students::gpu_active($user_id) && !user_can($user_id, 'manage_options')) {
            return array('ok' => false, 'job' => 0, 'status' => '', 'message' => 'Месяц безлимитных генераций закончился. Напишите нам, если нужно продлить.');
        }
        $s = self::settings();
        if (self::used_today($user_id) >= (int) $s['daily']) {
            return array('ok' => false, 'job' => 0, 'status' => '', 'message' => 'На сегодня достаточно: больше ' . (int) $s['daily'] . ' заданий в сутки не ставим.');
        }

        global $wpdb;
        $table = GC_Install::jobs_table();
        $wpdb->insert($table, array(
            'user_id'    => (int) $user_id,
            'kind'       => 'image',
            'prompt'     => $prompt,
            'params'     => wp_json_encode($params),
            'status'     => 'queued',
            'created_at' => current_time('mysql'),
        ), array('%d', '%s', '%s', '%s', '%s', '%s'));
        $job_id = (int) $wpdb->insert_id;

        if (!self::ready()) {
            // Машину включают руками: задание ждёт в очереди, и это
            // честнее, чем молча потерять его.
            return array('ok' => true, 'job' => $job_id, 'status' => 'queued',
                         'message' => 'Машина сейчас выключена. Задание встало в очередь и пойдёт в работу, как только её включат.');
        }
        return self::send($job_id);
    }

    /** Отправить конкретное задание на машину. */
    public static function send($job_id) {
        $job = self::job($job_id);
        if (!$job) {
            return array('ok' => false, 'job' => 0, 'status' => '', 'message' => 'Задание не найдено');
        }
        $s = self::settings();
        $params = json_decode((string) $job['params'], true);
        $params = is_array($params) ? $params : array();

        $res = wp_remote_post(trailingslashit($s['endpoint']) . 'jobs', array(
            'timeout' => 20,
            'headers' => array(
                'Authorization' => 'Bearer ' . $s['token'],
                'Content-Type'  => 'application/json',
            ),
            'body' => wp_json_encode(array(
                'prompt'   => $job['prompt'],
                'model'    => $s['model'],
                'width'    => isset($params['width']) ? (int) $params['width'] : 1024,
                'height'   => isset($params['height']) ? (int) $params['height'] : 1024,
                'steps'    => isset($params['steps']) ? (int) $params['steps'] : 28,
                'job'      => (int) $job['id'],
                'callback' => rest_url('genius-course/v1/gpu-callback'),
                'sign'     => self::sign((int) $job['id']),
            )),
        ));

        if (is_wp_error($res)) {
            self::fail($job_id, $res->get_error_message());
            return array('ok' => false, 'job' => $job_id, 'status' => 'failed', 'message' => 'Машина не ответила: ' . $res->get_error_message());
        }
        $code = (int) wp_remote_retrieve_response_code($res);
        $body = json_decode((string) wp_remote_retrieve_body($res), true);
        if ($code !== 200 || empty($body['id'])) {
            $message = isset($body['error']) ? (string) $body['error'] : ('ответ ' . $code);
            self::fail($job_id, $message);
            return array('ok' => false, 'job' => $job_id, 'status' => 'failed', 'message' => 'Машина отказалась: ' . $message);
        }

        self::update($job_id, array('status' => 'running', 'remote_id' => (string) $body['id']));
        return array('ok' => true, 'job' => $job_id, 'status' => 'running', 'message' => 'Считается.');
    }

    /**
     * Проход по расписанию.
     *
     * Делает две вещи: дотягивает очередь, если машину включили, и
     * опрашивает те задания, по которым обратный вызов не пришёл.
     */
    public static function tick() {
        if (!self::ready()) {
            return;
        }
        global $wpdb;
        $table = GC_Install::jobs_table();

        $queued = (array) $wpdb->get_col("SELECT id FROM {$table} WHERE status = 'queued' ORDER BY id ASC LIMIT 5");
        foreach ($queued as $id) {
            self::send((int) $id);
        }

        $running = (array) $wpdb->get_results(
            "SELECT id, remote_id, created_at FROM {$table} WHERE status = 'running' ORDER BY id ASC LIMIT 20", ARRAY_A);
        foreach ($running as $row) {
            if (strtotime($row['created_at']) < time() - 30 * MINUTE_IN_SECONDS) {
                self::fail((int) $row['id'], 'машина не вернула результат за полчаса');
                continue;
            }
            self::poll((int) $row['id']);
        }
    }

    public static function poll($job_id) {
        $job = self::job($job_id);
        if (!$job || $job['remote_id'] === '') {
            return;
        }
        $s = self::settings();
        $res = wp_remote_get(trailingslashit($s['endpoint']) . 'jobs/' . rawurlencode($job['remote_id']), array(
            'timeout' => 15,
            'headers' => array('Authorization' => 'Bearer ' . $s['token']),
        ));
        if (is_wp_error($res)) {
            return;
        }
        $body = json_decode((string) wp_remote_retrieve_body($res), true);
        if (!is_array($body) || empty($body['status'])) {
            return;
        }
        if ($body['status'] === 'done' && !empty($body['image'])) {
            self::finish($job_id, (string) $body['image']);
        } elseif ($body['status'] === 'failed') {
            self::fail($job_id, isset($body['error']) ? (string) $body['error'] : 'машина вернула отказ');
        }
    }

    /**
     * Сохранить результат.
     *
     * Картинка приходит строкой base64 или ссылкой. В обоих случаях
     * кладём её в медиатеку: арендованная машина живёт часы, медиатека —
     * столько же, сколько сайт.
     */
    public static function finish($job_id, $image) {
        $job = self::job($job_id);
        if (!$job || $job['status'] === 'done') {
            return;
        }
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $bytes = '';
        if (strpos($image, 'http') === 0) {
            $res = wp_remote_get($image, array('timeout' => 60));
            if (!is_wp_error($res)) {
                $bytes = (string) wp_remote_retrieve_body($res);
            }
        } else {
            $clean = preg_replace('~^data:image/[a-z]+;base64,~i', '', $image);
            $bytes = (string) base64_decode($clean, true);
        }
        if ($bytes === '') {
            self::fail($job_id, 'пустая картинка');
            return;
        }

        $name = 'gc-' . (int) $job_id . '-' . wp_generate_password(6, false) . '.png';
        $upload = wp_upload_bits($name, null, $bytes);
        if (!empty($upload['error'])) {
            self::fail($job_id, (string) $upload['error']);
            return;
        }
        $attachment = wp_insert_attachment(array(
            'post_mime_type' => 'image/png',
            'post_title'     => mb_substr($job['prompt'], 0, 80),
            'post_status'    => 'inherit',
            'post_author'    => (int) $job['user_id'],
        ), $upload['file']);
        if (!is_wp_error($attachment)) {
            wp_update_attachment_metadata($attachment, wp_generate_attachment_metadata($attachment, $upload['file']));
        }

        self::update($job_id, array(
            'status'      => 'done',
            'result_url'  => $upload['url'],
            'finished_at' => current_time('mysql'),
        ));
    }

    public static function fail($job_id, $message) {
        self::update($job_id, array(
            'status'      => 'failed',
            'error'       => mb_substr((string) $message, 0, 500),
            'finished_at' => current_time('mysql'),
        ));
    }

    public static function job($job_id) {
        global $wpdb;
        $table = GC_Install::jobs_table();
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", (int) $job_id), ARRAY_A);
    }

    public static function for_user($user_id, $limit = 40) {
        global $wpdb;
        $table = GC_Install::jobs_table();
        return (array) $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE user_id = %d ORDER BY id DESC LIMIT %d", (int) $user_id, (int) $limit
        ), ARRAY_A);
    }

    public static function recent($limit = 50) {
        global $wpdb;
        $table = GC_Install::jobs_table();
        return (array) $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", (int) $limit
        ), ARRAY_A);
    }

    /** Подпись обратного вызова: иначе результат сможет прислать кто угодно. */
    public static function sign($job_id) {
        $s = self::settings();
        return hash_hmac('sha256', 'job:' . (int) $job_id, (string) $s['secret']);
    }

    private static function update($job_id, $fields) {
        global $wpdb;
        $wpdb->update(GC_Install::jobs_table(), $fields, array('id' => (int) $job_id));
    }
}

// Своё расписание: раз в минуту. Штатного такого в WordPress нет.
add_filter('cron_schedules', function ($schedules) {
    if (!isset($schedules['gc_minute'])) {
        $schedules['gc_minute'] = array('interval' => 60, 'display' => 'Раз в минуту (курс)');
    }
    return $schedules;
});
