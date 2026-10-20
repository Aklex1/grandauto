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
            // Чем считаем: comfy — ComfyUI на арендованной машине (так
            // работает связка с Vast), worker — наша служба из папки
            // worker. Второй путь оставлен для случая, когда ComfyUI на
            // образе нет.
            'driver'   => 'comfy',
            'endpoint' => '',
            'token'    => '',
            'secret'   => '',
            'model'    => 'sd_xl_base_1.0.safetensors',
            'wf_image' => '',
            'wf_video' => '',
            'daily'    => self::DAILY_DEFAULT,
        ));
    }

    public static function save_settings($values) {
        $now = self::settings();
        $now['enabled']  = !empty($values['enabled']) ? 1 : 0;
        $now['endpoint'] = esc_url_raw(trim((string) ($values['endpoint'] ?? '')));
        $now['token']    = sanitize_text_field((string) ($values['token'] ?? ''));
        $now['driver']   = in_array(($values['driver'] ?? ''), array('comfy', 'worker'), true) ? $values['driver'] : 'comfy';
        $now['model']    = sanitize_text_field((string) ($values['model'] ?? 'sd_xl_base_1.0.safetensors'));
        // Заготовки запросов к ComfyUI храним как есть: это json из
        // самого ComfyUI, и чистить его фильтрами значит сломать.
        foreach (array('wf_image', 'wf_video') as $field) {
            if (isset($values[$field])) {
                $now[$field] = trim((string) wp_unslash($values[$field]));
            }
        }
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
        // У ComfyUI нет своего /health: спрашиваем системную сводку,
        // она же показывает видеокарту и свободную память.
        $path = $s['driver'] === 'comfy' ? 'system_stats' : 'health';
        $res = wp_remote_get(trailingslashit($s['endpoint']) . $path, array(
            'timeout' => 8,
            'headers' => array('Authorization' => 'Bearer ' . $s['token']),
        ));
        if (is_wp_error($res)) {
            $out = array('ok' => false, 'message' => $res->get_error_message());
        } else {
            $code = (int) wp_remote_retrieve_response_code($res);
            $body = json_decode((string) wp_remote_retrieve_body($res), true);
            if ($code !== 200) {
                $out = array('ok' => false, 'message' => 'машина ответила ' . $code);
            } elseif ($s['driver'] === 'comfy') {
                $name = isset($body['devices'][0]['name']) ? (string) $body['devices'][0]['name'] : 'ComfyUI на связи';
                $out = array('ok' => true, 'message' => $name);
            } else {
                $out = array('ok' => true, 'message' => isset($body['gpu']) ? (string) $body['gpu'] : 'на связи');
            }
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
    public static function submit($user_id, $prompt, $params = array(), $kind = 'image') {
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
        $kind = $kind === 'video' ? 'video' : 'image';
        if ($kind === 'video' && trim((string) $s['wf_video']) === '') {
            return array('ok' => false, 'job' => 0, 'status' => '',
                         'message' => 'Видео пока не настроено: на машине нет заготовки запроса для роликов.');
        }
        $wpdb->insert($table, array(
            'user_id'    => (int) $user_id,
            'kind'       => $kind,
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

        if ($s['driver'] === 'comfy') {
            return self::send_comfy($job, $params, $s);
        }

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
        if ($s['driver'] === 'comfy') {
            self::poll_comfy($job, $s);
            return;
        }
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

    /* ---------------------------------------------------------------------
     * ComfyUI на арендованной машине
     * ------------------------------------------------------------------ */

    /**
     * Заготовка запроса для ComfyUI.
     *
     * ComfyUI принимает не «текст и размер», а граф узлов — тот самый,
     * что человек собирает мышью в интерфейсе, выгруженный в формате
     * для API. Граф зависит от того, какие модели стоят на машине,
     * поэтому держим его настройкой, а не в коде: сменился образ —
     * поменялась и заготовка, без правки плагина.
     *
     * Подстановки делаем по готовой json-строке, а значения прогоняем
     * через json-кодирование: описание кадра с кавычкой иначе ломает
     * весь запрос.
     */
    public static function workflow($kind, $prompt, $params, $s) {
        $raw = $kind === 'video' ? (string) $s['wf_video'] : (string) $s['wf_image'];
        if (trim($raw) === '') {
            $raw = self::default_image_workflow();
        }
        $quoted = function ($value) {
            return trim(wp_json_encode((string) $value), '"');
        };
        $negative = trim((string) ($params['negative'] ?? ''));
        if ($negative === '') {
            $negative = 'текст, надписи, водяной знак, искажённые руки, пластиковая кожа';
        }
        $seed = (int) ($params['seed'] ?? 0);
        $map = array(
            '%PROMPT%'    => $quoted($prompt),
            '%NEGATIVE%'  => $quoted($negative),
            '%MODEL%'     => $quoted($s['model']),
            '%WIDTH%'     => (int) ($params['width'] ?? 1024),
            '%HEIGHT%'    => (int) ($params['height'] ?? 1024),
            '%FRAMES%'    => (int) ($params['frames'] ?? 49),
            '%FPS%'       => (int) ($params['fps'] ?? 16),
            '%STEPS%'     => (int) ($params['steps'] ?? 28),
            '%CFG%'       => (float) ($params['cfg'] ?? 6.5),
            '%STRENGTH%'  => (float) ($params['strength'] ?? 0.75),
            // Имя файла-референса на самой машине: его туда кладёт
            // upload_reference() перед отправкой задания.
            '%REFERENCE%' => $quoted((string) ($params['remote_file'] ?? '')),
            '%SEED%'      => $seed > 0 ? $seed : wp_rand(1, 2147483646),
        );
        $json = strtr($raw, $map);
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : null;
    }

    /** Простой текст-в-кадр на SDXL: то, что есть на любом образе с ComfyUI. */
    private static function default_image_workflow() {
        return '{
  "4": {"class_type": "CheckpointLoaderSimple", "inputs": {"ckpt_name": "%MODEL%"}},
  "5": {"class_type": "EmptyLatentImage", "inputs": {"width": %WIDTH%, "height": %HEIGHT%, "batch_size": 1}},
  "6": {"class_type": "CLIPTextEncode", "inputs": {"text": "%PROMPT%", "clip": ["4", 1]}},
  "7": {"class_type": "CLIPTextEncode", "inputs": {"text": "%NEGATIVE%", "clip": ["4", 1]}},
  "3": {"class_type": "KSampler", "inputs": {"seed": %SEED%, "steps": 28, "cfg": 6.5,
        "sampler_name": "dpmpp_2m", "scheduler": "karras", "denoise": 1,
        "model": ["4", 0], "positive": ["6", 0], "negative": ["7", 0], "latent_image": ["5", 0]}},
  "8": {"class_type": "VAEDecode", "inputs": {"samples": ["3", 0], "vae": ["4", 2]}},
  "9": {"class_type": "SaveImage", "inputs": {"filename_prefix": "gc", "images": ["8", 0]}}
}';
    }

    /**
     * Положить референс на машину.
     *
     * ComfyUI берёт картинки не по ссылке, а из своей папки input:
     * поэтому файл из медиатеки сайта сначала уезжает туда, и только
     * потом его имя подставляется в граф. Имя возвращаем вместе с
     * подпапкой — ComfyUI различает их.
     */
    public static function upload_reference($attachment_id, $s) {
        $path = get_attached_file((int) $attachment_id);
        if (!$path || !file_exists($path)) {
            return array('ok' => false, 'name' => '', 'message' => 'файл референса не найден');
        }
        $body = file_get_contents($path);
        if ($body === false) {
            return array('ok' => false, 'name' => '', 'message' => 'файл референса не читается');
        }
        $name = basename($path);
        $boundary = 'gc' . wp_generate_password(16, false);
        $type = (string) get_post_mime_type((int) $attachment_id);
        $parts = '';
        foreach (array('type' => 'input', 'overwrite' => 'true', 'subfolder' => '') as $field => $value) {
            $parts .= "--{$boundary}\r\n"
                    . "Content-Disposition: form-data; name=\"{$field}\"\r\n\r\n{$value}\r\n";
        }
        $parts .= "--{$boundary}\r\n"
                . "Content-Disposition: form-data; name=\"image\"; filename=\"{$name}\"\r\n"
                . "Content-Type: " . ($type !== '' ? $type : 'application/octet-stream') . "\r\n\r\n"
                . $body . "\r\n--{$boundary}--\r\n";

        $res = wp_remote_post(trailingslashit($s['endpoint']) . 'upload/image', array(
            'timeout' => 60,
            'headers' => array('Content-Type' => 'multipart/form-data; boundary=' . $boundary),
            'body'    => $parts,
        ));
        if (is_wp_error($res)) {
            return array('ok' => false, 'name' => '', 'message' => $res->get_error_message());
        }
        $code = (int) wp_remote_retrieve_response_code($res);
        $data = json_decode((string) wp_remote_retrieve_body($res), true);
        if ($code !== 200 || empty($data['name'])) {
            return array('ok' => false, 'name' => '', 'message' => 'машина не приняла файл, ответ ' . $code);
        }
        $full = (string) $data['name'];
        if (!empty($data['subfolder'])) {
            $full = trim((string) $data['subfolder'], '/') . '/' . $full;
        }
        return array('ok' => true, 'name' => $full, 'message' => '');
    }

    private static function send_comfy($job, $params, $s) {
        // Референс уезжает на машину прямо перед заданием: между
        // постановкой в очередь и отправкой машину могли перезапустить,
        // а её папка input живёт только вместе с ней.
        if (!empty($params['reference'])) {
            $up = self::upload_reference((int) $params['reference'], $s);
            if (!$up['ok']) {
                self::fail((int) $job['id'], $up['message']);
                return array('ok' => false, 'job' => (int) $job['id'], 'status' => 'failed',
                             'message' => 'Референс не доехал до машины: ' . $up['message']);
            }
            $params['remote_file'] = $up['name'];
        }

        $graph = self::workflow((string) $job['kind'], (string) $job['prompt'], $params, $s);
        if (!$graph) {
            self::fail((int) $job['id'], 'заготовка запроса не разобралась как json');
            return array('ok' => false, 'job' => (int) $job['id'], 'status' => 'failed',
                         'message' => 'Заготовка запроса к ComfyUI не разобралась. Проверьте её в настройках.');
        }
        $res = wp_remote_post(trailingslashit($s['endpoint']) . 'prompt', array(
            'timeout' => 20,
            'headers' => array('Content-Type' => 'application/json'),
            'body'    => wp_json_encode(array(
                'prompt'    => $graph,
                'client_id' => 'genius-course-' . (int) $job['id'],
            )),
        ));
        if (is_wp_error($res)) {
            self::fail((int) $job['id'], $res->get_error_message());
            return array('ok' => false, 'job' => (int) $job['id'], 'status' => 'failed',
                         'message' => 'Машина не ответила: ' . $res->get_error_message());
        }
        $code = (int) wp_remote_retrieve_response_code($res);
        $body = json_decode((string) wp_remote_retrieve_body($res), true);
        if ($code !== 200 || empty($body['prompt_id'])) {
            $message = isset($body['error']['message']) ? (string) $body['error']['message'] : ('ответ ' . $code);
            self::fail((int) $job['id'], $message);
            return array('ok' => false, 'job' => (int) $job['id'], 'status' => 'failed',
                         'message' => 'ComfyUI отказался: ' . $message);
        }
        self::update((int) $job['id'], array('status' => 'running', 'remote_id' => (string) $body['prompt_id']));
        return array('ok' => true, 'job' => (int) $job['id'], 'status' => 'running', 'message' => 'Считается.');
    }

    /**
     * Опрос ComfyUI.
     *
     * Обратного вызова у него нет, поэтому только опрос: раз в минуту
     * по расписанию и чаще — из кабинета, пока человек смотрит на
     * страницу. Готовый файл лежит в outputs под разными ключами в
     * зависимости от узла, которым сохраняли: кадры в images, ролики в
     * gifs или videos.
     */
    private static function poll_comfy($job, $s) {
        $res = wp_remote_get(trailingslashit($s['endpoint']) . 'history/' . rawurlencode((string) $job['remote_id']), array(
            'timeout' => 15,
        ));
        if (is_wp_error($res)) {
            return;
        }
        $body = json_decode((string) wp_remote_retrieve_body($res), true);
        if (!is_array($body) || empty($body[$job['remote_id']])) {
            return;
        }
        $item = $body[$job['remote_id']];
        $status = isset($item['status']['status_str']) ? (string) $item['status']['status_str'] : '';
        if ($status === 'error') {
            $why = isset($item['status']['messages']) ? wp_json_encode($item['status']['messages']) : 'ошибка на машине';
            self::fail((int) $job['id'], mb_substr((string) $why, 0, 300));
            return;
        }
        $outputs = isset($item['outputs']) && is_array($item['outputs']) ? $item['outputs'] : array();
        foreach ($outputs as $node) {
            foreach (array('images', 'gifs', 'videos') as $key) {
                if (empty($node[$key][0]['filename'])) {
                    continue;
                }
                $file = $node[$key][0];
                $url = add_query_arg(array(
                    'filename'  => $file['filename'],
                    'subfolder' => isset($file['subfolder']) ? $file['subfolder'] : '',
                    'type'      => isset($file['type']) ? $file['type'] : 'output',
                ), trailingslashit($s['endpoint']) . 'view');
                self::finish((int) $job['id'], $url);
                return;
            }
        }
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
