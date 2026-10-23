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
            // Веса для роликов живут отдельным файлом: кадры считает
            // SDXL, видео — LTX-Video, и один и тот же checkpoint тут
            // не годится.
            'model_video' => 'ltx-video-2b-v0.9.5.safetensors',
            // LTX-Video читает описание не своим клипом, а текстовым
            // кодировщиком t5: в ComfyUI он отдельным файлом.
            'clip_video'  => 't5xxl_fp16.safetensors',
            'video'    => 0,
            'wf_image' => '',
            'wf_video' => '',
            'daily'    => self::DAILY_DEFAULT,
            // Лимит для мастерской на шлюзе моделей: там каждое задание
            // стоит денег у поставщика, а не часов аренды.
            'daily_kie' => GC_Kie::DAILY_DEFAULT,
        ));
    }

    public static function save_settings($values) {
        $now = self::settings();
        $now['enabled']  = !empty($values['enabled']) ? 1 : 0;
        $now['endpoint'] = esc_url_raw(trim((string) ($values['endpoint'] ?? '')));
        $now['token']    = sanitize_text_field((string) ($values['token'] ?? ''));
        $now['driver']   = in_array(($values['driver'] ?? ''), array('comfy', 'worker'), true) ? $values['driver'] : 'comfy';
        $now['model']    = sanitize_text_field((string) ($values['model'] ?? 'sd_xl_base_1.0.safetensors'));
        $now['model_video'] = sanitize_text_field((string) ($values['model_video'] ?? 'ltx-video-2b-v0.9.5.safetensors'));
        $now['clip_video']  = sanitize_text_field((string) ($values['clip_video'] ?? 't5xxl_fp16.safetensors'));
        $now['video']    = !empty($values['video']) ? 1 : 0;
        // Заготовки запросов к ComfyUI храним как есть: это json из
        // самого ComfyUI, и чистить его фильтрами значит сломать.
        foreach (array('wf_image', 'wf_video') as $field) {
            if (isset($values[$field])) {
                $now[$field] = trim((string) wp_unslash($values[$field]));
            }
        }
        $now['daily']    = max(1, (int) ($values['daily'] ?? self::DAILY_DEFAULT));
        $now['daily_kie'] = max(1, (int) ($values['daily_kie'] ?? GC_Kie::DAILY_DEFAULT));
        if (empty($now['secret'])) {
            // Ключ подписи обратного вызова заводим один раз и не показываем
            // в настройках целиком: его знает только машина.
            $now['secret'] = wp_generate_password(40, false);
        }
        update_option(self::OPT, $now, false);
        return $now;
    }

    /**
     * Поколение кэша.
     *
     * Ответы машины держим недолго, но иногда нужно спросить заново
     * прямо сейчас — после скачивания весов, например. Вместо того
     * чтобы искать и удалять каждый ключ, меняем номер поколения: все
     * старые ключи разом перестают совпадать.
     */
    public static function epoch() {
        return (int) get_option('gc_cache_epoch', 0);
    }

    public static function fresh() {
        update_option('gc_cache_epoch', self::epoch() + 1, false);
    }

    /**
     * Что лежит на машине.
     *
     * ComfyUI сам перечисляет доступные файлы в описании узлов: список
     * моделей — это варианты поля ckpt_name. Так имя из настроек можно
     * сверить с тем, что реально скачалось, не заходя на машину.
     */
    public static function models($s = null) {
        $s = is_array($s) ? $s : self::settings();
        $out = array('ok' => false, 'message' => '', 'ckpt' => array(), 'clip' => array());
        if ($s['endpoint'] === '' || $s['driver'] !== 'comfy') {
            $out['message'] = 'машина не подключена';
            return $out;
        }
        $key = 'gc_gpu_models_' . self::epoch();
        $cached = get_transient($key);
        if (is_array($cached)) {
            return $cached;
        }
        $pairs = array(
            'CheckpointLoaderSimple' => array('ckpt', 'ckpt_name'),
            'CLIPLoader'             => array('clip', 'clip_name'),
        );
        foreach ($pairs as $class => $pair) {
            list($slot, $field) = $pair;
            $res = wp_remote_get(trailingslashit($s['endpoint']) . 'object_info/' . $class, array('timeout' => 12));
            if (is_wp_error($res)) {
                $out['message'] = $res->get_error_message();
                return $out;
            }
            $body = json_decode((string) wp_remote_retrieve_body($res), true);
            $list = isset($body[$class]['input']['required'][$field][0])
                ? $body[$class]['input']['required'][$field][0] : array();
            $out[$slot] = is_array($list) ? array_values(array_filter($list, 'is_string')) : array();
            $out['ok'] = true;
        }
        set_transient($key, $out, MINUTE_IN_SECONDS);
        return $out;
    }

    /**
     * Чем считать задания.
     *
     * Курс AI-креатора учится на арендованной машине: там своя сборка
     * ComfyUI, и смысл обучения в том числе в ней. Курсу заработка
     * машина не нужна — его мастерская работает через шлюз моделей и
     * поэтому доступна всегда.
     *
     * @return string 'kie' или 'gpu'
     */
    public static function engine($user_id = 0, $course = '') {
        if ($course === '') {
            $course = $user_id > 0 ? GC_Students::course($user_id) : GC_Courses::current();
        }
        return GC_Courses::field($course, 'studio', 'gpu') === 'kie' ? 'kie' : 'gpu';
    }

    /** Двигатель уже поставленного задания. */
    public static function engine_of($job) {
        $params = is_array($job) ? json_decode((string) $job['params'], true) : null;
        $engine = is_array($params) ? (string) ($params['engine'] ?? '') : '';
        return $engine === GC_Kie::ENGINE ? 'kie' : 'gpu';
    }

    /** Состояние мастерской для ученика: машина или шлюз. */
    public static function state_for($user_id, $course = '') {
        return self::engine($user_id, $course) === 'kie' ? GC_Kie::health() : self::health();
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
        $cached = get_transient('gc_gpu_health_' . self::epoch());
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
        set_transient('gc_gpu_health_' . self::epoch(), $out, MINUTE_IN_SECONDS);
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
    public static function submit($user_id, $prompt, $params = array(), $kind = 'image', $engine = '') {
        $prompt = trim(wp_strip_all_tags((string) $prompt));
        if (mb_strlen($prompt) < 3) {
            return array('ok' => false, 'job' => 0, 'status' => '', 'message' => 'Опишите кадр словами — хотя бы одной фразой.');
        }
        if (!GC_Students::gpu_active($user_id) && !user_can($user_id, 'manage_options')) {
            return array('ok' => false, 'job' => 0, 'status' => '', 'message' => 'Месяц безлимитных генераций закончился. Напишите нам, если нужно продлить.');
        }
        $s = self::settings();
        // Двигатель обычно берётся по курсу ученика. Явно его задают
        // только примеры к модулям: их делает администратор, у которого
        // своего курса нет, а считать их должен шлюз.
        $engine = $engine === 'kie' ? 'kie' : self::engine($user_id);
        $limit = $engine === 'kie' ? GC_Kie::daily() : (int) $s['daily'];
        if (self::used_today($user_id) >= $limit) {
            return array('ok' => false, 'job' => 0, 'status' => '', 'message' => 'На сегодня достаточно: больше ' . $limit . ' заданий в сутки не ставим.');
        }

        global $wpdb;
        $table = GC_Install::jobs_table();
        $kind = $kind === 'video' ? 'video' : 'image';

        if ($engine === 'kie') {
            $params['engine'] = GC_Kie::ENGINE;
            $wpdb->insert($table, array(
                'user_id'    => (int) $user_id,
                'kind'       => $kind,
                'prompt'     => $prompt,
                'params'     => wp_json_encode($params),
                'status'     => 'queued',
                'created_at' => current_time('mysql'),
            ), array('%d', '%s', '%s', '%s', '%s', '%s'));
            return GC_Kie::send((int) $wpdb->insert_id);
        }

        if ($kind === 'video' && !self::video_allowed($s)) {
            return array('ok' => false, 'job' => 0, 'status' => '',
                         'message' => 'Видео пока не настроено: администратор не включил ролики.');
        }
        // Пока задание не ушло, спрашиваем машину, есть ли на ней узлы
        // из заготовки. Иначе ComfyUI молча вернёт ошибку, а ученик
        // увидит «не получилось» без причины.
        if (!empty($s['enabled']) && $s['driver'] === 'comfy' && $s['endpoint'] !== '') {
            // Веса качаются уже после того, как машина начала отвечать:
            // пока файл не дописан, ComfyUI падает с невнятной ошибкой
            // про загрузку модели. Проверяем заранее.
            $have = self::models($s);
            if ($have['ok']) {
                $need = $kind === 'video'
                    ? array((string) $s['model_video'], (string) $s['clip_video'])
                    : array((string) $s['model']);
                $list = array_merge($have['ckpt'], $have['clip']);
                foreach ($need as $file) {
                    if ($file !== '' && !in_array($file, $list, true)) {
                        return array('ok' => false, 'job' => 0, 'status' => '',
                                     'message' => 'Модель ' . $file . ' ещё не докачалась на машину. '
                                                . 'Обычно это пара минут — попробуйте ещё раз.');
                    }
                }
            }
            $miss = self::missing_nodes($kind, !empty($params['reference']), $s);
            if ($miss) {
                return array('ok' => false, 'job' => 0, 'status' => '',
                             'message' => 'На машине нет нужных узлов ComfyUI: ' . implode(', ', $miss)
                                        . '. Нужен образ с ними — смотрите worker/CLOUD.md.');
            }
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
        if (self::engine_of($job) === 'kie') {
            return GC_Kie::send($job_id);
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
        global $wpdb;
        $table = GC_Install::jobs_table();
        // Машина может быть выключена, а задания шлюза всё равно надо
        // двигать: у них своя очередь и своя оплата. Поэтому смотрим на
        // готовность машины по каждому заданию, а не на входе.
        $ready = self::ready();

        $queued = (array) $wpdb->get_results(
            "SELECT id, params FROM {$table} WHERE status = 'queued' ORDER BY id ASC LIMIT 5", ARRAY_A);
        foreach ($queued as $row) {
            if (self::engine_of($row) === 'kie' || $ready) {
                self::send((int) $row['id']);
            }
        }

        // Заодно догоняем быстрые разборы: расписание WordPress
        // срабатывает на чужих запросах и иногда опаздывает.
        if (class_exists('GC_Review')) {
            GC_Review::catch_up();
        }

        $running = (array) $wpdb->get_results(
            "SELECT id, remote_id, created_at, params FROM {$table} WHERE status = 'running' ORDER BY id ASC LIMIT 20", ARRAY_A);
        foreach ($running as $row) {
            $kie = self::engine_of($row) === 'kie';
            if (strtotime($row['created_at']) < time() - 30 * MINUTE_IN_SECONDS) {
                self::fail((int) $row['id'], $kie
                    ? 'модель не вернула результат за полчаса'
                    : 'машина не вернула результат за полчаса');
                continue;
            }
            if ($kie || $ready) {
                self::poll((int) $row['id']);
            }
        }
    }

    /** Опросить машину по заданиям одного ученика: до трёх свежих. */
    public static function refresh_user($user_id) {
        global $wpdb;
        $table = GC_Install::jobs_table();
        $ready = self::ready();
        $rows = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT id, params FROM {$table} WHERE user_id = %d AND status = 'running' ORDER BY id DESC LIMIT 3",
            (int) $user_id
        ), ARRAY_A);
        foreach ($rows as $row) {
            if (self::engine_of($row) === 'kie' || $ready) {
                self::poll((int) $row['id']);
            }
        }
    }

    public static function poll($job_id) {
        $job = self::job($job_id);
        if (!$job || $job['remote_id'] === '') {
            return;
        }
        if (self::engine_of($job) === 'kie') {
            GC_Kie::poll($job);
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

        // Расширение берём из имени файла на машине: ComfyUI отдаёт и
        // кадры, и ролики по одному адресу, и webm, сохранённый как png,
        // браузер отказывается играть.
        $known = array(
            'png'  => 'image/png',  'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
            'webp' => 'image/webp', 'gif' => 'image/gif',
            'webm' => 'video/webm', 'mp4' => 'video/mp4',  'mov'  => 'video/quicktime',
        );
        $query = array();
        $qs = (string) parse_url($image, PHP_URL_QUERY);
        if ($qs !== '') {
            parse_str($qs, $query);
        }
        $remote = !empty($query['filename'])
            ? (string) $query['filename']
            : basename((string) parse_url($image, PHP_URL_PATH));
        $ext = strtolower((string) pathinfo($remote, PATHINFO_EXTENSION));
        $mime = isset($known[$ext]) ? $known[$ext] : 'image/png';
        if (!isset($known[$ext])) {
            $ext = 'png';
        } elseif ($ext === 'jpeg') {
            $ext = 'jpg';
        }

        $name = 'gc-' . (int) $job_id . '-' . wp_generate_password(6, false) . '.' . $ext;
        $upload = wp_upload_bits($name, null, $bytes);
        if (!empty($upload['error'])) {
            self::fail($job_id, (string) $upload['error']);
            return;
        }
        $attachment = wp_insert_attachment(array(
            'post_mime_type' => $mime,
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

        // Задание могло быть не ученическим, а примером к модулю: тогда
        // готовый файл сразу встаёт на своё место в программе.
        $params = json_decode((string) $job['params'], true);
        if (is_array($params) && !empty($params['example']) && class_exists('GC_Examples')) {
            GC_Examples::attach($params['example'], (string) $upload['url'],
                is_wp_error($attachment) ? 0 : (int) $attachment);
        }
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
        $ref = trim((string) ($params['remote_file'] ?? '')) !== '';
        $raw = $kind === 'video' ? (string) $s['wf_video'] : (string) $s['wf_image'];
        if (trim($raw) === '') {
            $raw = self::default_workflow($kind, $ref);
        }
        if ($kind === 'video') {
            // LTX-Video считает только по сетке: сторона кратна 32,
            // длина — 8n+1. Числа из кабинета подгоняем сами, иначе
            // машина отвечает ошибкой про размерности.
            $params['width']  = max(256, ((int) ($params['width'] ?? 768) >> 5) << 5);
            $params['height'] = max(256, ((int) ($params['height'] ?? 512) >> 5) << 5);
            $frames = max(9, min(257, (int) ($params['frames'] ?? 49)));
            $params['frames'] = ((int) floor(($frames - 1) / 8)) * 8 + 1;
            $params['fps']    = max(8, min(30, (int) ($params['fps'] ?? 24)));
        }
        $quoted = function ($value) {
            // Снимаем ровно по одной кавычке с краёв. trim() тут не
            // годится: у описания, которое само кончается кавычкой,
            // он съедает и экранирующий слеш — и весь запрос ломается.
            $json = wp_json_encode((string) $value);
            return is_string($json) && strlen($json) >= 2 ? substr($json, 1, -1) : '';
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
            '%VMODEL%'    => $quoted(isset($s['model_video']) ? $s['model_video'] : $s['model']),
            '%VCLIP%'     => $quoted(isset($s['clip_video']) ? $s['clip_video'] : 't5xxl_fp16.safetensors'),
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
            // Размер второго прохода: полтора раза по стороне, кратно
            // восьми. На нём и чинятся мелкие лица и руки.
            '%WIDTH2%'    => ((int) round((int) ($params['width'] ?? 1024) * 1.5 / 8)) * 8,
            '%HEIGHT2%'   => ((int) round((int) ($params['height'] ?? 1024) * 1.5 / 8)) * 8,
        );
        $json = strtr($raw, $map);
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : null;
    }

    /** Доступны ли ролики ученику. */
    public static function video_allowed($s = null) {
        $s = is_array($s) ? $s : self::settings();
        return trim((string) $s['wf_video']) !== '' || !empty($s['video']);
    }

    /** Какая заготовка идёт в дело, когда своей в настройках нет. */
    public static function default_workflow($kind, $with_reference = false) {
        if ($kind === 'video') {
            return $with_reference ? self::default_video_ref_workflow() : self::default_video_workflow();
        }
        return $with_reference ? self::default_image_ref_workflow() : self::default_image_workflow();
    }

    /**
     * Текст в кадр, два прохода.
     *
     * Один проход на полный рост даёт мелкое лицо и кашу вместо
     * пальцев: модель рисует фигуру целиком в те же пиксели. Второй
     * проход увеличивает картинку в полтора раза и дорисовывает её с
     * малым denoise — композиция остаётся, детали появляются. Это
     * стоит секунд двадцать и снимает большую часть артефактов.
     */
    private static function default_image_workflow() {
        return '{
  "4": {"class_type": "CheckpointLoaderSimple", "inputs": {"ckpt_name": "%MODEL%"}},
  "5": {"class_type": "EmptyLatentImage", "inputs": {"width": %WIDTH%, "height": %HEIGHT%, "batch_size": 1}},
  "6": {"class_type": "CLIPTextEncode", "inputs": {"text": "%PROMPT%", "clip": ["4", 1]}},
  "7": {"class_type": "CLIPTextEncode", "inputs": {"text": "%NEGATIVE%", "clip": ["4", 1]}},
  "3": {"class_type": "KSampler", "inputs": {"seed": %SEED%, "steps": %STEPS%, "cfg": %CFG%,
        "sampler_name": "dpmpp_2m_sde", "scheduler": "karras", "denoise": 1,
        "model": ["4", 0], "positive": ["6", 0], "negative": ["7", 0], "latent_image": ["5", 0]}},
  "11": {"class_type": "LatentUpscale", "inputs": {"samples": ["3", 0], "upscale_method": "nearest-exact",
         "width": %WIDTH2%, "height": %HEIGHT2%, "crop": "disabled"}},
  "12": {"class_type": "KSampler", "inputs": {"seed": %SEED%, "steps": 14, "cfg": %CFG%,
         "sampler_name": "dpmpp_2m_sde", "scheduler": "karras", "denoise": 0.45,
         "model": ["4", 0], "positive": ["6", 0], "negative": ["7", 0], "latent_image": ["11", 0]}},
  "8": {"class_type": "VAEDecode", "inputs": {"samples": ["12", 0], "vae": ["4", 2]}},
  "9": {"class_type": "SaveImage", "inputs": {"filename_prefix": "gc", "images": ["8", 0]}}
}';
    }

    /**
     * Кадр по референсу.
     *
     * Референс сначала растягиваем до выбранного размера, иначе кадр
     * выйдет в размер исходника, а не тот, что человек выбрал. Сила
     * влияния — это denoise: 0.3 оставит почти исходник, 0.9 — только
     * общую композицию.
     */
    private static function default_image_ref_workflow() {
        return '{
  "4": {"class_type": "CheckpointLoaderSimple", "inputs": {"ckpt_name": "%MODEL%"}},
  "10": {"class_type": "LoadImage", "inputs": {"image": "%REFERENCE%"}},
  "12": {"class_type": "ImageScale", "inputs": {"image": ["10", 0], "upscale_method": "lanczos",
         "width": %WIDTH%, "height": %HEIGHT%, "crop": "center"}},
  "11": {"class_type": "VAEEncode", "inputs": {"pixels": ["12", 0], "vae": ["4", 2]}},
  "6": {"class_type": "CLIPTextEncode", "inputs": {"text": "%PROMPT%", "clip": ["4", 1]}},
  "7": {"class_type": "CLIPTextEncode", "inputs": {"text": "%NEGATIVE%", "clip": ["4", 1]}},
  "3": {"class_type": "KSampler", "inputs": {"seed": %SEED%, "steps": %STEPS%, "cfg": %CFG%,
        "sampler_name": "dpmpp_2m", "scheduler": "karras", "denoise": %STRENGTH%,
        "model": ["4", 0], "positive": ["6", 0], "negative": ["7", 0], "latent_image": ["11", 0]}},
  "8": {"class_type": "VAEDecode", "inputs": {"samples": ["3", 0], "vae": ["4", 2]}},
  "9": {"class_type": "SaveImage", "inputs": {"filename_prefix": "gc", "images": ["8", 0]}}
}';
    }

    /**
     * Ролик по описанию.
     *
     * Узлы LTX-Video лежат в самом ComfyUI, отдельных расширений не
     * нужно: хватает файла весов в models/checkpoints. Сохраняем в
     * webm — его играет браузер, и в кабинете он идёт в тег video.
     */
    private static function default_video_workflow() {
        return '{
  "1": {"class_type": "CheckpointLoaderSimple", "inputs": {"ckpt_name": "%VMODEL%"}},
  "10": {"class_type": "CLIPLoader", "inputs": {"clip_name": "%VCLIP%", "type": "ltxv"}},
  "2": {"class_type": "CLIPTextEncode", "inputs": {"text": "%PROMPT%", "clip": ["10", 0]}},
  "3": {"class_type": "CLIPTextEncode", "inputs": {"text": "%NEGATIVE%", "clip": ["10", 0]}},
  "4": {"class_type": "EmptyLTXVLatentVideo", "inputs": {"width": %WIDTH%, "height": %HEIGHT%,
        "length": %FRAMES%, "batch_size": 1}},
  "5": {"class_type": "LTXVConditioning", "inputs": {"positive": ["2", 0], "negative": ["3", 0],
        "frame_rate": %FPS%}},
  "6": {"class_type": "KSampler", "inputs": {"seed": %SEED%, "steps": %STEPS%, "cfg": %CFG%,
        "sampler_name": "euler", "scheduler": "normal", "denoise": 1,
        "model": ["1", 0], "positive": ["5", 0], "negative": ["5", 1], "latent_image": ["4", 0]}},
  "7": {"class_type": "VAEDecode", "inputs": {"samples": ["6", 0], "vae": ["1", 2]}},
  "8": {"class_type": "SaveWEBM", "inputs": {"images": ["7", 0], "filename_prefix": "gc",
        "codec": "vp9", "fps": %FPS%, "crf": 32}}
}';
    }

    /** Ролик из фотографии: первый кадр — референс. */
    private static function default_video_ref_workflow() {
        return '{
  "1": {"class_type": "CheckpointLoaderSimple", "inputs": {"ckpt_name": "%VMODEL%"}},
  "10": {"class_type": "CLIPLoader", "inputs": {"clip_name": "%VCLIP%", "type": "ltxv"}},
  "2": {"class_type": "CLIPTextEncode", "inputs": {"text": "%PROMPT%", "clip": ["10", 0]}},
  "3": {"class_type": "CLIPTextEncode", "inputs": {"text": "%NEGATIVE%", "clip": ["10", 0]}},
  "9": {"class_type": "LoadImage", "inputs": {"image": "%REFERENCE%"}},
  "4": {"class_type": "LTXVImgToVideo", "inputs": {"positive": ["2", 0], "negative": ["3", 0],
        "vae": ["1", 2], "image": ["9", 0], "width": %WIDTH%, "height": %HEIGHT%,
        "length": %FRAMES%, "batch_size": 1}},
  "5": {"class_type": "LTXVConditioning", "inputs": {"positive": ["4", 0], "negative": ["4", 1],
        "frame_rate": %FPS%}},
  "6": {"class_type": "KSampler", "inputs": {"seed": %SEED%, "steps": %STEPS%, "cfg": %CFG%,
        "sampler_name": "euler", "scheduler": "normal", "denoise": 1,
        "model": ["1", 0], "positive": ["5", 0], "negative": ["5", 1], "latent_image": ["4", 2]}},
  "7": {"class_type": "VAEDecode", "inputs": {"samples": ["6", 0], "vae": ["1", 2]}},
  "8": {"class_type": "SaveWEBM", "inputs": {"images": ["7", 0], "filename_prefix": "gc",
        "codec": "vp9", "fps": %FPS%, "crf": 32}}
}';
    }

    /**
     * Какие узлы из заготовки машина не знает.
     *
     * Спрашиваем поштучно: /object_info целиком — это несколько
     * мегабайт json, а нам нужно только «есть или нет». Ответ держим
     * десять минут: образ в середине аренды не меняется.
     */
    public static function missing_nodes($kind, $with_reference, $s = null) {
        $s = is_array($s) ? $s : self::settings();
        if ($s['endpoint'] === '' || $s['driver'] !== 'comfy') {
            return array();
        }
        $graph = self::workflow($kind, 'проверка', array(
            'remote_file' => $with_reference ? 'ref.png' : '',
        ), $s);
        if (!is_array($graph)) {
            return array();
        }
        $classes = array();
        foreach ($graph as $node) {
            if (!empty($node['class_type'])) {
                $classes[(string) $node['class_type']] = true;
            }
        }
        $missing = array();
        foreach (array_keys($classes) as $class) {
            $key = 'gc_node_' . md5($s['endpoint'] . '|' . $class . '|' . self::epoch());
            $known = get_transient($key);
            if ($known === 'yes') {
                continue;
            }
            if ($known === 'no') {
                $missing[] = $class;
                continue;
            }
            $res = wp_remote_get(trailingslashit($s['endpoint']) . 'object_info/' . rawurlencode($class), array(
                'timeout' => 8,
            ));
            if (is_wp_error($res)) {
                // Машина недоступна — это другая беда, и ругаться на
                // узлы тут нечестно.
                return array();
            }
            $body = json_decode((string) wp_remote_retrieve_body($res), true);
            $ok = (int) wp_remote_retrieve_response_code($res) === 200
                  && is_array($body) && isset($body[$class]);
            set_transient($key, $ok ? 'yes' : 'no', 10 * MINUTE_IN_SECONDS);
            if (!$ok) {
                $missing[] = $class;
            }
        }
        return $missing;
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
            // В messages первым идёт служебное execution_start, и если
            // писать их подряд, в карточку попадает заголовок, а не
            // причина. Достаём именно execution_error.
            $why = '';
            foreach ((array) ($item['status']['messages'] ?? array()) as $msg) {
                if (!is_array($msg) || ($msg[0] ?? '') !== 'execution_error') {
                    continue;
                }
                $data = (array) ($msg[1] ?? array());
                $why = trim((string) ($data['node_type'] ?? '') . ': '
                          . (string) ($data['exception_type'] ?? '') . ' '
                          . (string) ($data['exception_message'] ?? ''));
                break;
            }
            if ($why === '') {
                $why = (string) wp_json_encode($item['status']['messages'] ?? 'ошибка на машине');
            }
            self::fail((int) $job['id'], mb_substr($why, 0, 300));
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
    /** Во сколько обошлось задание у поставщика. */
    public static function set_credits($job_id, $credits) {
        self::update($job_id, array('credits' => (float) $credits));
    }

    /**
     * Сколько потратили за период: всего и по видам работ.
     *
     * @return array{jobs:int,credits:float,by_kind:array}
     */
    public static function spending($days = 30) {
        global $wpdb;
        $table = self::jobs_table_name();
        $since = gmdate('Y-m-d H:i:s', time() - max(1, (int) $days) * DAY_IN_SECONDS);
        $rows = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT kind, COUNT(*) AS n, SUM(credits) AS c FROM {$table}
             WHERE status = 'done' AND created_at > %s GROUP BY kind", $since
        ), ARRAY_A);
        $out = array('jobs' => 0, 'credits' => 0.0, 'by_kind' => array());
        foreach ($rows as $row) {
            $out['jobs'] += (int) $row['n'];
            $out['credits'] += (float) $row['c'];
            $out['by_kind'][(string) $row['kind']] = array(
                'jobs'    => (int) $row['n'],
                'credits' => (float) $row['c'],
            );
        }
        return $out;
    }

    private static function jobs_table_name() {
        return GC_Install::jobs_table();
    }

    /** Показывать ли кадр другим ученикам. */
    public static function set_shared($job_id, $shared) {
        self::update($job_id, array('shared' => $shared ? 1 : 0));
    }

    /**
     * Кадры и ролики, которые ученики сами открыли для галереи.
     *
     * Берём по одной работе от человека: иначе один увлёкшийся займёт
     * всю страницу, а галерея нужна, чтобы видеть разных.
     */
    public static function shared($limit = 24) {
        global $wpdb;
        $table = GC_Install::jobs_table();
        return (array) $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE shared = 1 AND status = 'done' AND result_url <> ''
             GROUP BY user_id ORDER BY finished_at DESC LIMIT %d", (int) $limit
        ), ARRAY_A);
    }

    /** Задание ушло считаться: номер у поставщика и состояние «идёт». */
    public static function mark_running($job_id, $remote_id) {
        self::update($job_id, array(
            'status'    => 'running',
            'remote_id' => mb_substr((string) $remote_id, 0, 64),
        ));
    }

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
