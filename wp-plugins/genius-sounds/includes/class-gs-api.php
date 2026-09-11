<?php
/**
 * Публичное API для разработчиков.
 *
 * Те же инструменты, что и на сайте — оживление фото, редактирование
 * изображений, говорящий аватар, звуки и озвучка, — но вызываются из чужого
 * кода по ключу доступа. Деньги списываются с того же баланса, что и в
 * личном кабинете, поэтому отдельной кассы заводить не нужно.
 *
 * Схема простая и одинаковая для всех операций:
 *   POST /wp-json/genius/v1/generate  → task_id
 *   GET  /wp-json/genius/v1/tasks/ID  → status, files
 * Плюс необязательный вебхук: сервис сам постучится на callback_url,
 * когда задача будет готова.
 */

if (!defined('ABSPATH')) {
    exit;
}

class GS_Api {

    const NS          = 'genius/v1';
    const API_CREATE  = 'https://api.kie.ai/api/v1/jobs/createTask';
    const API_INFO    = 'https://api.kie.ai/api/v1/jobs/recordInfo';
    const TASK_PREFIX = 'gs_api_task_';
    const CRON_HOOK   = 'gs_api_poll';

    /** Ограничение запросов на ключ: защита от случайного цикла в чужом коде. */
    const RATE_PER_MINUTE = 60;

    public static function boot() {
        add_action('rest_api_init', array(__CLASS__, 'register_routes'));
        add_filter('cron_schedules', array(__CLASS__, 'cron_schedule'));
        add_action(self::CRON_HOOK, array(__CLASS__, 'poll_pending'));
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + 60, 'gs_minute', self::CRON_HOOK);
        }
    }

    public static function cron_schedule($schedules) {
        if (!isset($schedules['gs_minute'])) {
            $schedules['gs_minute'] = array('interval' => 60, 'display' => 'Каждую минуту (Genius API)');
        }
        return $schedules;
    }

    /* ---------------------------------------------------------------------
     * Реестр операций
     * ------------------------------------------------------------------ */

    /**
     * engine — кто выполняет:
     *   jobs — прямой вызов моделей, lab/sfx/tts — наши же микросервисы.
     */
    public static function services() {
        return array(
            'photo-video' => array(
                'id'     => 'photo-video',
                'engine' => 'jobs',
                'title'  => 'Оживить фото',
                'about'  => 'Из фотографии получается короткое видео: движение головы, мимика, лёгкая камера.',
                'model'  => 'bytedance/v1-pro-fast-image-to-video',
                'input'  => array('image_url' => 'required', 'prompt' => 'optional'),
                'result' => 'video',
                'price'  => 25,
                'build'  => array(__CLASS__, 'build_photo_video'),
            ),
            'image-edit' => array(
                'id'     => 'image-edit',
                'engine' => 'jobs',
                'title'  => 'Изменить фото по описанию',
                'about'  => 'Замена фона и одежды, удаление объектов, реставрация — словами, без редактора.',
                'model'  => 'google/nano-banana-edit',
                'input'  => array('image_url' => 'required', 'prompt' => 'required'),
                'result' => 'image',
                'price'  => 35,
                'build'  => array(__CLASS__, 'build_image_edit'),
            ),
            'image' => array(
                'id'     => 'image',
                'engine' => 'jobs',
                'title'  => 'Картинка по описанию',
                'about'  => 'Изображение из текста — для карточек товара, обложек и иллюстраций.',
                'model'  => 'google/nano-banana',
                'input'  => array('prompt' => 'required'),
                'result' => 'image',
                'price'  => 9,
                'build'  => array(__CLASS__, 'build_image'),
            ),
            'upscale' => array(
                'id'     => 'upscale',
                'engine' => 'jobs',
                'title'  => 'Увеличить качество фото',
                'about'  => 'Апскейл вдвое с восстановлением деталей: для старых снимков и мелких картинок.',
                'model'  => 'topaz/image-upscale',
                'input'  => array('image_url' => 'required'),
                'result' => 'image',
                'price'  => 50,
                'build'  => array(__CLASS__, 'build_upscale'),
            ),
            'avatar' => array(
                'id'     => 'avatar',
                'engine' => 'lab',
                'lab_id' => 'avatar',
                'title'  => 'Говорящий аватар',
                'about'  => 'Фотография плюс запись голоса — видео, где человек со снимка говорит.',
                'input'  => array('image_url' => 'required', 'audio_url' => 'required', 'prompt' => 'optional'),
                'result' => 'video',
            ),
            'vocal' => array(
                'id'     => 'vocal',
                'engine' => 'lab',
                'lab_id' => 'vocal',
                'title'  => 'Убрать вокал',
                'about'  => 'Две дорожки из песни: минусовка и отдельно голос.',
                'input'  => array('audio_url' => 'required'),
                'result' => 'audio',
            ),
            'denoise' => array(
                'id'     => 'denoise',
                'engine' => 'lab',
                'lab_id' => 'denoise',
                'title'  => 'Убрать шум',
                'about'  => 'Чистый голос без фонового гула, эха и шума улицы.',
                'input'  => array('audio_url' => 'required'),
                'result' => 'audio',
            ),
            'sfx' => array(
                'id'     => 'sfx',
                'engine' => 'sfx',
                'title'  => 'Звук по описанию',
                'about'  => 'Звуковой эффект или фон из текстового описания, MP3.',
                'input'  => array('prompt' => 'required', 'mode' => 'optional', 'seconds' => 'optional'),
                'result' => 'audio',
            ),
            'tts' => array(
                'id'     => 'tts',
                'engine' => 'tts',
                'title'  => 'Озвучка текста',
                'about'  => 'Речь из текста живым голосом, больше тридцати языков.',
                'input'  => array('text' => 'required', 'voice' => 'optional'),
                'result' => 'audio',
            ),
        );
    }

    public static function get_service($id) {
        $services = self::services();
        return isset($services[$id]) ? $services[$id] : null;
    }

    /** Операции, которые прямо сейчас можно вызвать. */
    public static function available_services() {
        $out = array();
        foreach (self::services() as $id => $service) {
            if ($service['engine'] === 'lab' && !GS_Lab::is_available($service['lab_id'])) {
                continue;
            }
            $out[$id] = $service;
        }
        return $out;
    }

    public static function price($id, $params = array()) {
        $service = self::get_service($id);
        if (!$service) {
            return 0.0;
        }
        if ($service['engine'] === 'lab') {
            $seconds = isset($params['seconds']) ? (float) $params['seconds'] : 0.0;
            return GS_Lab::price($service['lab_id'], $seconds);
        }
        if ($service['engine'] === 'sfx') {
            return GS_SFX::get_cost();
        }
        if ($service['engine'] === 'tts') {
            $text = isset($params['text']) ? (string) $params['text'] : '';
            return class_exists('KIE_TTS_API') ? (float) KIE_TTS_API::calculate_cost($text) : 0.0;
        }
        $price = (float) get_option('gs_api_price_' . $id, $service['price']);
        return $price > 0 ? round($price, 2) : (float) $service['price'];
    }

    /** Понятная подпись цены для документации. */
    public static function price_hint($id) {
        $service = self::get_service($id);
        if (!$service) {
            return '';
        }
        if ($service['engine'] === 'lab') {
            return GS_Lab::price_hint($service['lab_id']);
        }
        if ($service['engine'] === 'tts') {
            return '12 ₽ за 1000 знаков';
        }
        return number_format_i18n(self::price($id), 0) . ' ₽ за запуск';
    }

    /* ---------------------------------------------------------------------
     * Маршруты
     * ------------------------------------------------------------------ */

    public static function register_routes() {
        $auth = array(__CLASS__, 'check_key');

        register_rest_route(self::NS, '/services', array(
            'methods'             => 'GET',
            'callback'            => array(__CLASS__, 'handle_services'),
            'permission_callback' => '__return_true',
        ));
        register_rest_route(self::NS, '/balance', array(
            'methods'             => 'GET',
            'callback'            => array(__CLASS__, 'handle_balance'),
            'permission_callback' => $auth,
        ));
        register_rest_route(self::NS, '/uploads', array(
            'methods'             => 'POST',
            'callback'            => array(__CLASS__, 'handle_upload'),
            'permission_callback' => $auth,
        ));
        register_rest_route(self::NS, '/generate', array(
            'methods'             => 'POST',
            'callback'            => array(__CLASS__, 'handle_generate'),
            'permission_callback' => $auth,
        ));
        register_rest_route(self::NS, '/tasks/(?P<task_id>[a-zA-Z0-9_-]+)', array(
            'methods'             => 'GET',
            'callback'            => array(__CLASS__, 'handle_task'),
            'permission_callback' => $auth,
        ));
    }

    /** Текущий владелец ключа в рамках запроса. */
    private static $caller = array('user_id' => 0, 'secret' => '', 'prefix' => '');

    public static function check_key($request) {
        $key = GS_Api_Keys::key_from_request($request);
        if ($key === '') {
            return new WP_Error('gs_api_no_key', 'Нужен ключ доступа: заголовок Authorization: Bearer <ключ>', array('status' => 401));
        }
        $resolved = GS_Api_Keys::resolve($key);
        if ((int) $resolved['user_id'] <= 0) {
            return new WP_Error('gs_api_bad_key', 'Ключ доступа не найден или отозван', array('status' => 401));
        }
        if (!self::rate_ok($resolved['prefix'])) {
            return new WP_Error('gs_api_rate', 'Слишком много запросов: не больше ' . self::RATE_PER_MINUTE . ' в минуту', array('status' => 429));
        }
        self::$caller = $resolved;
        return true;
    }

    private static function rate_ok($prefix) {
        $slot = 'gs_api_rate_' . md5($prefix . '|' . gmdate('YmdHi'));
        $count = (int) get_transient($slot);
        if ($count >= self::RATE_PER_MINUTE) {
            return false;
        }
        set_transient($slot, $count + 1, 120);
        return true;
    }

    /* ---------------------------------------------------------------------
     * Обработчики
     * ------------------------------------------------------------------ */

    public static function handle_services($request) {
        $out = array();
        foreach (self::available_services() as $id => $service) {
            $out[] = array(
                'service'     => $id,
                'title'       => $service['title'],
                'about'       => $service['about'],
                'input'       => $service['input'],
                'result'      => $service['result'],
                'price'       => self::price($id),
                'price_hint'  => self::price_hint($id),
            );
        }
        return rest_ensure_response(array('services' => $out));
    }

    public static function handle_balance($request) {
        $user_id = (int) self::$caller['user_id'];
        return rest_ensure_response(array(
            'balance'  => GS_SFX::get_balance($user_id),
            'currency' => 'RUB',
        ));
    }

    /**
     * Загрузка файла: чужие серверы не всегда отдают прямые ссылки,
     * поэтому даём собственное хранилище.
     */
    public static function handle_upload($request) {
        $files = $request->get_file_params();
        $file = isset($files['file']) ? $files['file'] : null;
        if (!$file) {
            return new WP_Error('gs_api_no_file', 'Файл не приложен: поле file', array('status' => 400));
        }
        $kind = sanitize_key((string) $request->get_param('kind'));
        if (!in_array($kind, array('image', 'audio'), true)) {
            $type = (string) ($file['type'] ?? '');
            $kind = strpos($type, 'image') === 0 ? 'image' : 'audio';
        }
        $max = $kind === 'image' ? GS_Lab::MAX_IMAGE_BYTES : GS_Lab::MAX_AUDIO_BYTES_VOCAL;

        $stored = GS_Lab::store_upload($file, $kind, $max);
        if (empty($stored['ok'])) {
            return new WP_Error('gs_api_upload', $stored['message'], array('status' => 400));
        }
        $seconds = $kind === 'audio' ? GS_Lab::media_duration(GS_Lab::local_path($stored['url'])) : 0.0;

        return rest_ensure_response(array(
            'url'      => $stored['url'],
            'kind'     => $kind,
            'duration' => round($seconds),
        ));
    }

    public static function handle_generate($request) {
        $user_id = (int) self::$caller['user_id'];
        $params = $request->get_json_params();
        if (!is_array($params)) {
            $params = $request->get_params();
        }

        $service_id = sanitize_key((string) ($params['service'] ?? ''));
        $service = self::get_service($service_id);
        if (!$service) {
            return new WP_Error('gs_api_bad_service', 'Неизвестная операция. Список: GET /wp-json/' . self::NS . '/services', array('status' => 400));
        }
        if ($service['engine'] === 'lab' && !GS_Lab::is_available($service['lab_id'])) {
            return new WP_Error('gs_api_unavailable', 'Операция временно недоступна', array('status' => 503));
        }

        $input = self::read_input($service, $params);
        if (is_wp_error($input)) {
            return $input;
        }

        $callback = isset($params['callback_url']) ? esc_url_raw((string) $params['callback_url']) : '';
        if ($callback !== '' && !preg_match('~^https?://~i', $callback)) {
            return new WP_Error('gs_api_bad_callback', 'callback_url должен начинаться с http:// или https://', array('status' => 400));
        }

        $cost = self::price($service_id, $input);
        $balance = GS_SFX::get_balance($user_id);
        if ($balance < $cost) {
            return new WP_Error(
                'gs_api_balance',
                sprintf('Недостаточно средств: на балансе %.2f ₽, нужно %.2f ₽', $balance, $cost),
                array('status' => 402, 'balance' => $balance, 'cost' => $cost)
            );
        }

        $created = self::dispatch($service, $input);
        if (empty($created['ok'])) {
            return new WP_Error('gs_api_provider', $created['message'] ?: 'Сервис не принял задачу', array('status' => 502));
        }
        $task_id = (string) $created['task_id'];

        // Озвучку списывает колбэк базового плагина — по строке истории,
        // которую мы заводим ниже. Дважды за одно и то же не берём.
        if ($service['engine'] !== 'tts' && !GS_SFX::charge($user_id, $cost)) {
            return new WP_Error('gs_api_charge', 'Не удалось списать средства с баланса', array('status' => 500));
        }
        if (class_exists('KIE_TTS_DB')) {
            $is_telegram = class_exists('KIE_TTS_Auth') && KIE_TTS_Auth::is_telegram_user($user_id);
            KIE_TTS_DB::save_generation($user_id, $task_id, 'API: ' . $service['title'], 'api:' . $service_id, $cost, $is_telegram);
        }

        update_option(self::TASK_PREFIX . $task_id, array(
            'user_id'   => $user_id,
            'service'   => $service_id,
            'engine'    => $service['engine'],
            'cost'      => $cost,
            'callback'  => $callback,
            'secret'    => (string) self::$caller['secret'],
            'status'    => 'pending',
            'files'     => array(),
            'created'   => time(),
            'notified'  => 0,
        ), false);

        return rest_ensure_response(array(
            'task_id' => $task_id,
            'service' => $service_id,
            'status'  => 'pending',
            'cost'    => $cost,
            'balance' => GS_SFX::get_balance($user_id),
        ));
    }

    public static function handle_task($request) {
        $task_id = (string) $request['task_id'];
        $task = get_option(self::TASK_PREFIX . $task_id, array());
        if (!is_array($task) || empty($task['service'])) {
            return new WP_Error('gs_api_no_task', 'Задача не найдена', array('status' => 404));
        }
        if ((int) $task['user_id'] !== (int) self::$caller['user_id']) {
            return new WP_Error('gs_api_forbidden', 'Задача принадлежит другому ключу', array('status' => 403));
        }

        $state = self::refresh($task_id, $task);
        return rest_ensure_response(array(
            'task_id' => $task_id,
            'service' => $task['service'],
            'status'  => $state['status'],
            'files'   => $state['files'],
            'message' => $state['message'],
            'cost'    => (float) $task['cost'],
        ));
    }

    /* ---------------------------------------------------------------------
     * Разбор входных данных
     * ------------------------------------------------------------------ */

    private static function read_input($service, $params) {
        $input = array();
        foreach ($service['input'] as $field => $rule) {
            $value = isset($params[$field]) ? $params[$field] : '';

            if ($field === 'image_url' || $field === 'audio_url') {
                $url = esc_url_raw((string) $value);
                if ($url === '') {
                    if ($rule === 'required') {
                        return new WP_Error('gs_api_input', 'Не указан ' . $field, array('status' => 400));
                    }
                    continue;
                }
                if (!preg_match('~^https?://~i', $url)) {
                    return new WP_Error('gs_api_input', $field . ' должен быть ссылкой http/https', array('status' => 400));
                }
                $input[$field] = $url;
                continue;
            }

            if ($field === 'text' || $field === 'prompt') {
                $text = sanitize_textarea_field((string) $value);
                if (trim($text) === '') {
                    if ($rule === 'required') {
                        return new WP_Error('gs_api_input', 'Не указан ' . $field, array('status' => 400));
                    }
                    continue;
                }
                $input[$field] = $text;
                continue;
            }

            if ($value !== '') {
                $input[$field] = sanitize_text_field((string) $value);
            }
        }

        // Цена аватара, минусовки и очистки зависит от длины записи —
        // измеряем её до списания, даже если файл лежит на чужом сервере.
        if (isset($input['audio_url']) && $service['engine'] === 'lab') {
            $input['seconds'] = self::remote_duration($input['audio_url']);
            $limit = GS_Lab::max_seconds($service['lab_id']);
            if ($limit > 0 && $input['seconds'] > $limit + 1) {
                return new WP_Error(
                    'gs_api_too_long',
                    sprintf('Запись длиннее %d сек', (int) $limit),
                    array('status' => 400)
                );
            }
        }
        return $input;
    }

    /**
     * Длительность файла: свои загрузки читаем с диска, чужие — скачиваем
     * во временный файл, иначе нечем считать цену.
     */
    private static function remote_duration($url) {
        $local = GS_Lab::local_path($url);
        if ($local !== '') {
            return GS_Lab::media_duration($local);
        }
        $response = wp_remote_get($url, array('timeout' => 60, 'limit_response_size' => 26214400));
        if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) !== 200) {
            return 0.0;
        }
        $body = wp_remote_retrieve_body($response);
        if (strlen((string) $body) < 1024) {
            return 0.0;
        }
        $tmp = wp_tempnam('gs-api-audio');
        if (!$tmp) {
            return 0.0;
        }
        file_put_contents($tmp, $body);
        $seconds = GS_Lab::media_duration($tmp);
        @unlink($tmp);
        return $seconds;
    }

    /* ---------------------------------------------------------------------
     * Постановка задачи
     * ------------------------------------------------------------------ */

    private static function dispatch($service, $input) {
        if ($service['engine'] === 'lab') {
            return GS_Lab::create_task($service['lab_id'], $input);
        }
        if ($service['engine'] === 'sfx') {
            return self::dispatch_sfx($input);
        }
        if ($service['engine'] === 'tts') {
            return self::dispatch_tts($input);
        }
        $payload = call_user_func($service['build'], $service, $input);
        return self::jobs_create($payload);
    }

    public static function build_photo_video($service, $input) {
        return array(
            'model' => $service['model'],
            'input' => array(
                'prompt'     => isset($input['prompt']) && $input['prompt'] !== '' ? $input['prompt'] : 'оживить фотографию, естественное движение',
                'image_url'  => $input['image_url'],
                'resolution' => '720p',
                'duration'   => '5',
            ),
        );
    }

    public static function build_image_edit($service, $input) {
        return array(
            'model' => $service['model'],
            'input' => array(
                'prompt'        => $input['prompt'],
                'image_urls'    => array($input['image_url']),
                'output_format' => 'png',
                'image_size'    => 'auto',
            ),
        );
    }

    public static function build_image($service, $input) {
        return array(
            'model' => $service['model'],
            'input' => array(
                'prompt'        => $input['prompt'],
                'output_format' => 'png',
                'image_size'    => 'auto',
            ),
        );
    }

    public static function build_upscale($service, $input) {
        return array(
            'model' => $service['model'],
            'input' => array(
                'image_url'      => $input['image_url'],
                'upscale_factor' => '2',
            ),
        );
    }

    private static function dispatch_sfx($input) {
        $mode = isset($input['mode']) ? (string) $input['mode'] : GS_SFX::MODE_SFX;
        if (!array_key_exists($mode, GS_SFX::get_modes())) {
            $mode = GS_SFX::MODE_SFX;
        }
        $seconds = isset($input['seconds']) ? (int) $input['seconds'] : 4;
        $seconds = max(1, min(60, $seconds));

        return GS_SFX::create_task(GS_SFX::build_prompt((string) $input['prompt'], $mode, $seconds), array(
            'model'        => 'V5',
            'loop'         => $mode === GS_SFX::MODE_LOOP,
            'callback_url' => add_query_arg('token', GS_SFX::callback_token(), rest_url(GS_Rest::NS . '/sfx/callback')),
        ));
    }

    private static function dispatch_tts($input) {
        $text = (string) $input['text'];
        $voice = isset($input['voice']) ? (string) $input['voice'] : '';
        $callback = get_option('kie_tts_callback_url', rest_url('tts/v1/callback'));

        if (class_exists('KIE_TTS_API')) {
            $created = KIE_TTS_API::create_tts_task($text, $voice !== '' ? $voice : null, array(), $callback);
            if (is_array($created) && (int) ($created['code'] ?? 0) === 200 && !empty($created['data']['taskId'])) {
                return array('ok' => true, 'task_id' => (string) $created['data']['taskId'], 'message' => '');
            }
        }
        // Основной голос недоступен — отдаём запасным, лишь бы клиент получил звук.
        return GS_Tts_Fallback::create_task($text, $voice);
    }

    private static function jobs_create($payload) {
        $key = trim((string) get_option('kie_tts_api_key', ''));
        if ($key === '') {
            return array('ok' => false, 'task_id' => '', 'message' => 'Сервис генерации не настроен');
        }
        $response = wp_remote_post(self::API_CREATE, array(
            'timeout' => 45,
            'headers' => array('Authorization' => 'Bearer ' . $key, 'Content-Type' => 'application/json'),
            'body'    => wp_json_encode($payload),
        ));
        if (is_wp_error($response)) {
            return array('ok' => false, 'task_id' => '', 'message' => $response->get_error_message());
        }
        $body = json_decode((string) wp_remote_retrieve_body($response), true);
        if (!is_array($body) || (int) ($body['code'] ?? 0) !== 200 || empty($body['data']['taskId'])) {
            return array(
                'ok'      => false,
                'task_id' => '',
                'message' => is_array($body) ? (string) ($body['msg'] ?? 'Сервис вернул ошибку') : 'Некорректный ответ сервиса',
            );
        }
        return array('ok' => true, 'task_id' => (string) $body['data']['taskId'], 'message' => '');
    }

    /* ---------------------------------------------------------------------
     * Состояние задачи
     * ------------------------------------------------------------------ */

    /**
     * @return array{status:string,files:array,message:string}
     */
    public static function refresh($task_id, $task) {
        if ((string) $task['status'] === 'completed' || (string) $task['status'] === 'failed') {
            return array(
                'status'  => (string) $task['status'],
                'files'   => is_array($task['files']) ? $task['files'] : array(),
                'message' => isset($task['message']) ? (string) $task['message'] : '',
            );
        }

        $engine = (string) $task['engine'];
        if ($engine === 'lab') {
            $service = self::get_service($task['service']);
            $state = GS_Lab::fetch_task($service['lab_id'], $task_id);
        } elseif ($engine === 'sfx') {
            $state = self::state_sfx($task_id);
        } elseif ($engine === 'tts') {
            $state = self::state_from_generations($task_id);
        } else {
            $state = self::jobs_state($task_id);
        }

        if (empty($state['ok'])) {
            return array('status' => 'pending', 'files' => array(), 'message' => '');
        }

        if ($state['status'] === 'failed') {
            if ($engine !== 'tts') {
                GS_SFX::refund((int) $task['user_id'], (float) $task['cost']);
            }
            if (class_exists('KIE_TTS_DB')) {
                KIE_TTS_DB::update_generation_status($task_id, 'failed');
            }
            $task['status'] = 'failed';
            $task['message'] = $state['message'] ?: 'Обработка не удалась, средства возвращены';
            update_option(self::TASK_PREFIX . $task_id, $task, false);
            self::notify($task_id, $task);
            return array('status' => 'failed', 'files' => array(), 'message' => (string) $task['message']);
        }

        if ($state['status'] !== 'completed') {
            return array('status' => 'pending', 'files' => array(), 'message' => '');
        }

        $files = array();
        foreach ($state['files'] as $file) {
            $local = GS_Lab::store_result($task_id, $file['url'], $file['kind']);
            $files[] = array(
                'label' => $file['label'],
                'kind'  => $file['kind'],
                'url'   => $local !== '' ? $local : $file['url'],
            );
        }
        if (class_exists('KIE_TTS_DB') && !empty($files)) {
            KIE_TTS_DB::update_generation_status($task_id, 'completed', $files[0]['url']);
        }
        $task['status'] = 'completed';
        $task['files'] = $files;
        update_option(self::TASK_PREFIX . $task_id, $task, false);
        self::notify($task_id, $task);

        return array('status' => 'completed', 'files' => $files, 'message' => '');
    }

    /** Задачи моделей: единый разбор ответа jobs/recordInfo. */
    private static function jobs_state($task_id) {
        $out = array('ok' => false, 'status' => 'pending', 'files' => array(), 'message' => '');
        $key = trim((string) get_option('kie_tts_api_key', ''));
        if ($key === '') {
            return $out;
        }
        $response = wp_remote_get(add_query_arg('taskId', $task_id, self::API_INFO), array(
            'timeout' => 45,
            'headers' => array('Authorization' => 'Bearer ' . $key),
        ));
        if (is_wp_error($response)) {
            return $out;
        }
        $body = json_decode((string) wp_remote_retrieve_body($response), true);
        if (!is_array($body) || !isset($body['data']) || !is_array($body['data'])) {
            return $out;
        }
        $out['ok'] = true;
        $data = $body['data'];
        $state = (string) ($data['state'] ?? '');

        if ($state === 'fail') {
            $out['status'] = 'failed';
            $out['message'] = (string) ($data['failMsg'] ?? 'Генерация не удалась');
            return $out;
        }
        if ($state !== 'success') {
            return $out;
        }

        $result = isset($data['resultJson']) ? $data['resultJson'] : array();
        if (is_string($result)) {
            $decoded = json_decode($result, true);
            $result = is_array($decoded) ? $decoded : array();
        }
        $urls = array();
        if (!empty($result['resultUrls'])) {
            $urls = is_array($result['resultUrls']) ? $result['resultUrls'] : array($result['resultUrls']);
        }
        foreach ($urls as $url) {
            $ext = strtolower((string) pathinfo(wp_parse_url((string) $url, PHP_URL_PATH), PATHINFO_EXTENSION));
            $kind = in_array($ext, array('mp4', 'webm', 'mov'), true) ? 'video'
                : (in_array($ext, array('mp3', 'wav', 'ogg', 'm4a'), true) ? 'audio' : 'image');
            $out['files'][] = array('label' => 'Результат', 'url' => (string) $url, 'kind' => $kind);
        }
        if (!empty($out['files'])) {
            $out['status'] = 'completed';
        }
        return $out;
    }

    /** Звук: состояние читаем у генератора звуков. */
    private static function state_sfx($task_id) {
        $out = array('ok' => false, 'status' => 'pending', 'files' => array(), 'message' => '');
        $res = GS_SFX::fetch_task($task_id);
        if (empty($res['ok'])) {
            return $out;
        }
        $out['ok'] = true;
        if (GS_SFX::is_failed_status((string) $res['status'])) {
            $out['status'] = 'failed';
            $out['message'] = $res['message'] ?: 'Не удалось сгенерировать звук';
            return $out;
        }
        if ((string) $res['audio_url'] !== '') {
            $out['status'] = 'completed';
            $out['files'][] = array('label' => 'Звук', 'url' => (string) $res['audio_url'], 'kind' => 'audio');
        }
        return $out;
    }

    /** Озвучка живёт в общей истории генераций. */
    private static function state_from_generations($task_id) {
        $out = array('ok' => false, 'status' => 'pending', 'files' => array(), 'message' => '');
        if (!class_exists('KIE_TTS_DB')) {
            return $out;
        }
        $row = KIE_TTS_DB::get_generation_by_task_id($task_id);
        if (!is_array($row)) {
            return $out;
        }
        $out['ok'] = true;
        $status = (string) $row['status'];
        if ($status === 'failed') {
            $out['status'] = 'failed';
            $out['message'] = 'Генерация не удалась';
            return $out;
        }
        if ($status === 'completed' && !empty($row['audio_url'])) {
            $out['status'] = 'completed';
            $out['files'][] = array('label' => 'Аудио', 'url' => (string) $row['audio_url'], 'kind' => 'audio');
        }
        return $out;
    }

    /* ---------------------------------------------------------------------
     * Вебхуки и фоновый опрос
     * ------------------------------------------------------------------ */

    /**
     * Сообщаем чужому сервису, что задача готова. Подпись позволяет
     * получателю убедиться, что запрос действительно от нас.
     */
    private static function notify($task_id, &$task) {
        if (empty($task['callback']) || !empty($task['notified'])) {
            return;
        }
        $body = wp_json_encode(array(
            'task_id' => $task_id,
            'service' => $task['service'],
            'status'  => $task['status'],
            'files'   => is_array($task['files']) ? $task['files'] : array(),
            'message' => isset($task['message']) ? $task['message'] : '',
        ));
        wp_remote_post((string) $task['callback'], array(
            'timeout'  => 20,
            'blocking' => false,
            'headers'  => array(
                'Content-Type'       => 'application/json',
                'X-Genius-Event'     => 'task.' . $task['status'],
                'X-Genius-Signature' => hash_hmac('sha256', (string) $body, (string) $task['secret']),
            ),
            'body'     => $body,
        ));
        $task['notified'] = 1;
        update_option(self::TASK_PREFIX . $task_id, $task, false);
    }

    /**
     * Раз в минуту дотягиваем задачи с вебхуком: клиент не обязан опрашивать
     * статус сам, если попросил уведомление.
     */
    public static function poll_pending() {
        global $wpdb;
        $rows = $wpdb->get_col($wpdb->prepare(
            "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s LIMIT 200",
            $wpdb->esc_like(self::TASK_PREFIX) . '%'
        ));
        $checked = 0;
        foreach ((array) $rows as $name) {
            $task_id = substr($name, strlen(self::TASK_PREFIX));
            $task = get_option($name, array());
            if (!is_array($task) || empty($task['service'])) {
                continue;
            }
            // Готовое и давно отданное убираем, чтобы таблица настроек не пухла.
            if (in_array((string) $task['status'], array('completed', 'failed'), true)) {
                if ((int) $task['created'] < time() - DAY_IN_SECONDS * 7) {
                    delete_option($name);
                }
                continue;
            }
            if ((int) $task['created'] < time() - HOUR_IN_SECONDS * 6) {
                delete_option($name);
                continue;
            }
            if (empty($task['callback']) || $checked >= 15) {
                continue;
            }
            self::refresh($task_id, $task);
            $checked++;
        }
    }
}
