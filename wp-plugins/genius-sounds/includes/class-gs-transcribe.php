<?php
/**
 * Расшифровка записей в кабинете озвучки.
 *
 * Прежний поставщик расшифровки у агрегатора не работает, поэтому
 * задачу выполняем сами через Gemini (см. GS_Gemini) и подменяем два
 * маршрута рабочего плагина: запуск и проверку состояния. Снаружи всё
 * выглядит по-старому — тот же номер задачи, тот же ответ, — так что
 * ни кабинет, ни внешний API переделывать не нужно.
 *
 * Gemini отвечает сразу и долго, а кабинет ждёт мгновенного ответа с
 * номером задачи, поэтому очередь держим у себя: запрос возвращает
 * номер, работа идёт в фоне, состояние читается при опросе.
 *
 * Заодно оставляем прежние подпорки для старого пути: свободный ввод
 * языка приводим к понятному коду, а сорвавшуюся задачу один раз молча
 * повторяем.
 */

if (!defined('ABSPATH')) {
    exit;
}

class GS_Transcribe {

    const OPT_ENABLED = 'gs_stt_guard';
    /** Чем расшифровываем: gemini (своя очередь) или provider (старый путь). */
    const OPT_ENGINE  = 'gs_stt_engine';
    /** Исходник задачи: нужен, чтобы её можно было повторить. */
    const SRC_PREFIX  = 'gs_stt_src_';
    /** Соответствие «сорвавшаяся задача → повтор». */
    const MAP_PREFIX  = 'gs_stt_retry_';

    /** Наши задачи: хранилище и узнаваемый номер. */
    const TASK_PREFIX = 'gs_stt_task_';
    const ID_PREFIX   = 'gst-';
    const TASK_TTL    = 259200; // трое суток

    /** Сколько ждём фоновый заход, прежде чем посчитать задачу самим. */
    const SPAWN_GRACE = 8;
    /** Дольше этого работа считается сорвавшейся. */
    const RUN_LIMIT   = 900;

    public static function boot() {
        add_filter('rest_pre_dispatch', array(__CLASS__, 'normalize_request'), 10, 3);
        add_filter('rest_request_after_callbacks', array(__CLASS__, 'after_callbacks'), 20, 3);
        add_action('rest_api_init', array(__CLASS__, 'register_routes'));
        add_action('gs_stt_run', array(__CLASS__, 'run'));
    }

    /** Расшифровываем сами, пока не сказано иное. */
    public static function engine() {
        return (string) get_option(self::OPT_ENGINE, 'gemini') === 'provider' ? 'provider' : 'gemini';
    }

    public static function register_routes() {
        register_rest_route('genius-sounds/v1', '/stt/run', array(
            'methods'             => 'POST',
            'callback'            => array(__CLASS__, 'handle_run'),
            'permission_callback' => '__return_true',
        ));
    }

    public static function enabled() {
        return (string) get_option(self::OPT_ENABLED, '1') === '1';
    }

    /* ---------------------------------------------------------------------
     * Код языка
     * ------------------------------------------------------------------ */

    /**
     * Приводим распространённые написания к коду, который принимает поставщик.
     */
    public static function normalize_language($raw) {
        $value = trim(mb_strtolower((string) $raw));
        if ($value === '') {
            return '';
        }
        $map = array(
            'ru' => array('ru', 'rus', 'ru-ru', 'ru_ru', 'russian', 'русский', 'рус', 'ru-RU'),
            'en' => array('en', 'eng', 'en-us', 'en_us', 'en-gb', 'english', 'английский', 'англ'),
            'uk' => array('uk', 'ukr', 'uk-ua', 'ukrainian', 'украинский', 'укр'),
            'de' => array('de', 'deu', 'ger', 'de-de', 'german', 'немецкий'),
            'fr' => array('fr', 'fra', 'fre', 'fr-fr', 'french', 'французский'),
            'es' => array('es', 'spa', 'es-es', 'spanish', 'испанский'),
            'it' => array('it', 'ita', 'it-it', 'italian', 'итальянский'),
            'pl' => array('pl', 'pol', 'polish', 'польский'),
            'tr' => array('tr', 'tur', 'turkish', 'турецкий'),
            'kk' => array('kk', 'kaz', 'kazakh', 'казахский'),
            'zh' => array('zh', 'chi', 'zho', 'zh-cn', 'chinese', 'китайский'),
            'ar' => array('ar', 'ara', 'arabic', 'арабский'),
        );
        foreach ($map as $code => $variants) {
            if (in_array($value, $variants, true)) {
                return $code;
            }
        }
        // Что-то вроде «ru-RU» или «en_GB» — берём первую часть.
        if (preg_match('~^([a-z]{2})[-_]~', $value, $m)) {
            return $m[1];
        }
        return $value;
    }

    /**
     * Перехватываем маршруты расшифровки рабочего плагина.
     *
     * Возврат значения из этого фильтра отменяет обычную обработку —
     * этим и пользуемся, чтобы ответить своей задачей.
     */
    public static function normalize_request($result, $server, $request) {
        if (!self::enabled() || !($request instanceof WP_REST_Request)) {
            return $result;
        }
        $route = (string) $request->get_route();
        if (!preg_match('~^/tts/v1/(api/)?transcribe(?:-status/([^/]+))?/?$~', $route, $m)) {
            return $result;
        }
        $is_api  = !empty($m[1]);
        $task_id = isset($m[2]) ? rawurldecode($m[2]) : '';

        // Состояние своей задачи отдаём сами, чужие не трогаем.
        if ($task_id !== '') {
            if (strpos($task_id, self::ID_PREFIX) !== 0) {
                return $result;
            }
            $auth = self::authorize($request, $is_api);
            if (is_wp_error($auth)) {
                return $auth;
            }
            return self::status_response($task_id);
        }

        if ($request->get_method() !== 'POST') {
            return $result;
        }

        $params = $request->get_json_params();
        if (!is_array($params)) {
            $params = array();
        }
        if (isset($params['language_code'])) {
            $clean = self::normalize_language($params['language_code']);
            if ($clean !== (string) $params['language_code']) {
                $params['language_code'] = $clean;
                $request->set_body(wp_json_encode($params));
                $request->set_param('language_code', $clean);
            }
        }

        if (self::engine() === 'gemini') {
            $auth = self::authorize($request, $is_api);
            if (is_wp_error($auth)) {
                return $auth;
            }
            return self::start($params, $is_api ? (int) $request->get_param('_api_user_id') : get_current_user_id());
        }

        // Старый путь: запоминаем исходные данные — по ним можно повторить задачу.
        self::$pending = array(
            'audio_url'   => isset($params['audio_url']) ? (string) $params['audio_url'] : '',
            'youtube_url' => isset($params['youtube_url']) ? (string) $params['youtube_url'] : '',
            'params'      => array(
                'language_code'    => isset($params['language_code']) ? (string) $params['language_code'] : '',
                'tag_audio_events' => !empty($params['tag_audio_events']),
                'diarize'          => !empty($params['diarize']),
            ),
        );
        return $result;
    }

    /**
     * Доступ ровно тот же, что у рабочего плагина: в кабинете — вход,
     * во внешнем API — ключ.
     */
    private static function authorize($request, $is_api) {
        if (!$is_api) {
            if (!is_user_logged_in()) {
                return new WP_Error('rest_forbidden', 'Требуется вход', array('status' => 401));
            }
            return true;
        }

        $key = (string) $request->get_header('X-API-Key');
        if ($key === '') {
            $auth = (string) $request->get_header('Authorization');
            if ($auth !== '' && stripos($auth, 'Bearer ') === 0) {
                $key = trim(substr($auth, 7));
            }
        }
        if ($key === '') {
            $key = (string) $request->get_param('api_key');
        }
        $key = trim($key);
        if ($key === '') {
            return new WP_Error('missing_api_key', 'API key отсутствует', array('status' => 401));
        }
        if (!class_exists('KIE_TTS_DB')) {
            return new WP_Error('rest_forbidden', 'Проверка ключа недоступна', array('status' => 503));
        }
        $user_id = KIE_TTS_DB::validate_api_key($key, array(
            'endpoint'   => (string) $request->get_route(),
            'method'     => (string) $request->get_method(),
            'ip'         => isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field((string) $_SERVER['REMOTE_ADDR']) : '',
            'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field((string) $_SERVER['HTTP_USER_AGENT']) : '',
        ));
        if (!$user_id) {
            return new WP_Error('invalid_api_key', 'Некорректный API key', array('status' => 401));
        }
        $request->set_param('_api_user_id', (int) $user_id);
        return true;
    }

    /** @var array|null Данные запроса, который сейчас обрабатывается. */
    private static $pending = null;

    /* ---------------------------------------------------------------------
     * Своя очередь расшифровки
     * ------------------------------------------------------------------ */

    /** Ставим задачу и сразу отдаём её номер — как делал прежний поставщик. */
    private static function start($params, $user_id) {
        $created = self::create($params, $user_id);
        if (is_wp_error($created)) {
            return $created;
        }
        return new WP_REST_Response(array(
            'success'   => true,
            'task_id'   => $created['id'],
            'record_id' => '',
            'audio_url' => $created['audio_url'],
        ), 200);
    }

    /**
     * Ставит расшифровку в очередь. Отсюда её заводят и кабинет озвучки,
     * и отдельный микросервис — очередь у них общая.
     *
     * @return array{id:string,audio_url:string}|WP_Error
     */
    public static function create($params, $user_id) {
        $audio_url   = isset($params['audio_url']) ? esc_url_raw((string) $params['audio_url']) : '';
        $youtube_url = isset($params['youtube_url']) ? esc_url_raw((string) $params['youtube_url']) : '';

        if ($audio_url === '' && $youtube_url === '') {
            return new WP_Error('missing_source', 'Укажите ссылку на запись или загрузите файл', array('status' => 400));
        }
        if ($audio_url === '' && $youtube_url !== '') {
            $extracted = self::audio_from_youtube($youtube_url);
            if (is_wp_error($extracted)) {
                return $extracted;
            }
            $audio_url = $extracted;
        }

        $id = self::ID_PREFIX . wp_generate_password(20, false, false);
        $task = array(
            'id'        => $id,
            'user_id'   => (int) $user_id,
            'audio_url' => $audio_url,
            'params'    => array(
                'language_code'    => isset($params['language_code']) ? sanitize_text_field((string) $params['language_code']) : '',
                'tag_audio_events' => !empty($params['tag_audio_events']),
                'diarize'          => !empty($params['diarize']),
            ),
            'status'    => 'pending',
            'text'      => '',
            'segments'  => array(),
            'language'  => '',
            'error'     => '',
            'credits'   => 0.0,
            'created'   => time(),
            'started'   => 0,
            'finished'  => 0,
        );
        self::save($task);
        self::spawn($id);

        return array('id' => $id, 'audio_url' => $audio_url);
    }

    /**
     * Состояние задачи для микросервиса: текст, фразы и готовые файлы.
     *
     * @return array{ok:bool,status:string,files:array,text:string,message:string}
     */
    public static function state($id) {
        $out = array('ok' => true, 'status' => 'pending', 'files' => array(), 'text' => '', 'message' => '');
        $task = self::load($id);
        if (!$task) {
            $out['status'] = 'failed';
            $out['message'] = 'Задача не найдена или устарела';
            return $out;
        }

        // Фоновый заход мог не состояться — тогда считаем прямо здесь.
        if ($task['status'] === 'pending' && (int) $task['started'] === 0
            && (time() - (int) $task['created']) >= self::SPAWN_GRACE) {
            self::run($id);
            $task = self::load($id);
            if (!$task) {
                $out['status'] = 'failed';
                $out['message'] = 'Задача потерялась';
                return $out;
            }
        }

        if ($task['status'] === 'failed') {
            $out['status'] = 'failed';
            $out['message'] = (string) $task['error'];
            return $out;
        }
        if ($task['status'] !== 'completed') {
            return $out;
        }

        $out['status'] = 'completed';
        $out['text']   = (string) $task['text'];
        $out['files']  = self::result_files($task);
        return $out;
    }

    /**
     * Раскладываем расшифровку по файлам: текст, субтитры и таблицу фраз.
     * Их удобнее скачать, чем выделять мышью на странице.
     */
    private static function result_files($task) {
        if (!class_exists('GS_Storage')) {
            return array();
        }
        GS_Storage::ensure_dirs();
        $dir = GS_Storage::generated_dir();
        $url = GS_Storage::generated_url();
        $stem = 'stt-' . preg_replace('~[^a-zA-Z0-9_-]~', '', (string) $task['id']);

        $segments = is_array($task['segments']) ? $task['segments'] : array();
        $parts = array(
            'txt' => array('Текст расшифровки', self::as_text($task)),
            'srt' => array('Субтитры SRT', self::as_srt($segments)),
            'vtt' => array('Субтитры VTT', self::as_vtt($segments)),
        );

        $files = array();
        foreach ($parts as $ext => $part) {
            list($label, $body) = $part;
            if (trim((string) $body) === '') {
                continue;
            }
            $name = $stem . '.' . $ext;
            if (!file_exists($dir . '/' . $name)) {
                // Текстовый файл отдаётся без указания кодировки: без метки
                // браузер и «Блокнот» читают кириллицу как набор символов.
                $prefix = $ext === 'txt' ? "\xEF\xBB\xBF" : '';
                file_put_contents($dir . '/' . $name, $prefix . $body);
            }
            $files[] = array('label' => $label, 'url' => $url . '/' . $name, 'kind' => 'file');
        }
        return $files;
    }

    /** Текст с отметками времени и говорящими — если они есть. */
    private static function as_text($task) {
        $segments = is_array($task['segments']) ? $task['segments'] : array();
        if (!$segments) {
            return (string) $task['text'];
        }
        $lines = array();
        foreach ($segments as $seg) {
            $stamp = '[' . self::clock((float) $seg['start']) . ' — ' . self::clock((float) $seg['end']) . ']';
            $who = trim((string) $seg['speaker']);
            $lines[] = $stamp . ($who !== '' ? ' ' . $who . ':' : '') . ' ' . $seg['text'];
        }
        return implode("\n", $lines) . "\n\n---\n\n" . (string) $task['text'] . "\n";
    }

    private static function as_srt($segments) {
        if (!$segments) {
            return '';
        }
        $out = array();
        foreach ($segments as $i => $seg) {
            $out[] = ($i + 1);
            $out[] = self::stamp((float) $seg['start'], ',') . ' --> ' . self::stamp((float) $seg['end'], ',');
            $out[] = (string) $seg['text'];
            $out[] = '';
        }
        return implode("\n", $out);
    }

    private static function as_vtt($segments) {
        if (!$segments) {
            return '';
        }
        $out = array('WEBVTT', '');
        foreach ($segments as $seg) {
            $out[] = self::stamp((float) $seg['start'], '.') . ' --> ' . self::stamp((float) $seg['end'], '.');
            $out[] = (string) $seg['text'];
            $out[] = '';
        }
        return implode("\n", $out);
    }

    private static function stamp($seconds, $sep) {
        $seconds = max(0.0, (float) $seconds);
        $h = (int) floor($seconds / 3600);
        $m = (int) floor(fmod($seconds, 3600) / 60);
        $s = (int) floor(fmod($seconds, 60));
        $ms = (int) round(fmod($seconds, 1) * 1000);
        return sprintf('%02d:%02d:%02d%s%03d', $h, $m, $s, $sep, $ms);
    }

    private static function clock($seconds) {
        $seconds = max(0.0, (float) $seconds);
        return sprintf('%02d:%02d', (int) floor($seconds / 60), (int) floor(fmod($seconds, 60)));
    }

    /** Звук с YouTube достаёт отдельная служба рабочего плагина. */
    private static function audio_from_youtube($youtube_url) {
        if (!class_exists('KIE_TTS_API')) {
            return new WP_Error('youtube_audio_error', 'Извлечение звука с YouTube сейчас недоступно', array('status' => 503));
        }
        $data = KIE_TTS_API::create_youtube_audio_task($youtube_url, 'mp3');
        if (is_array($data) && !empty($data['audio_url'])) {
            return esc_url_raw((string) $data['audio_url']);
        }
        $message = is_array($data) && !empty($data['message'])
            ? (string) $data['message']
            : 'Не удалось получить звук по ссылке YouTube';
        return new WP_Error('youtube_audio_error', $message, array('status' => 503));
    }

    /** Ответ о состоянии в том же виде, что отдавал рабочий плагин. */
    private static function status_response($id) {
        $task = self::load($id);
        if (!$task) {
            return new WP_Error('transcribe_status_error', 'Задача не найдена или устарела', array('status' => 404));
        }

        // Фоновый заход мог не состояться — тогда считаем прямо здесь.
        if ($task['status'] === 'pending') {
            $waiting = time() - (int) $task['created'];
            $running = (int) $task['started'] > 0 ? time() - (int) $task['started'] : 0;
            if ((int) $task['started'] === 0 && $waiting >= self::SPAWN_GRACE) {
                self::run($id);
                $task = self::load($id);
            } elseif ((int) $task['started'] > 0 && $running > self::RUN_LIMIT) {
                $task['status']   = 'failed';
                $task['error']    = 'Расшифровка не уложилась во время. Попробуйте ещё раз.';
                $task['finished'] = time();
                self::save($task);
            }
        }
        if (!$task) {
            return new WP_Error('transcribe_status_error', 'Задача не найдена', array('status' => 404));
        }

        $state = 'waiting';
        if ($task['status'] === 'completed') {
            $state = 'success';
        } elseif ($task['status'] === 'failed') {
            $state = 'fail';
        }

        return new WP_REST_Response(array(
            'success'       => true,
            'task_id'       => $task['id'],
            'status'        => $task['status'],
            'state'         => $state,
            'text'          => (string) $task['text'],
            'segments'      => is_array($task['segments']) ? $task['segments'] : array(),
            'result'        => array(
                'text'     => (string) $task['text'],
                'segments' => is_array($task['segments']) ? $task['segments'] : array(),
                'language' => (string) $task['language'],
            ),
            'error_message' => (string) $task['error'],
        ), 200);
    }

    /** Фоновый заход: свой же маршрут, вызов без ожидания ответа. */
    private static function spawn($id) {
        $task = self::load($id);
        if (!$task) {
            return;
        }
        $secret = wp_hash($id . '|gs-stt');
        wp_remote_post(rest_url('genius-sounds/v1/stt/run'), array(
            'timeout'   => 0.01,
            'blocking'  => false,
            'sslverify' => false,
            'body'      => array('id' => $id, 'key' => $secret),
        ));
    }

    public static function handle_run($request) {
        $id  = sanitize_text_field((string) $request->get_param('id'));
        $key = (string) $request->get_param('key');
        if ($id === '' || !hash_equals(wp_hash($id . '|gs-stt'), $key)) {
            return new WP_Error('rest_forbidden', 'Нет доступа', array('status' => 403));
        }
        self::run($id);
        return rest_ensure_response(array('ok' => true));
    }

    /** Собственно работа: спрашиваем Gemini и сохраняем ответ. */
    public static function run($id) {
        $task = self::load($id);
        if (!$task || $task['status'] !== 'pending') {
            return;
        }
        // Кто-то уже считает эту задачу.
        if ((int) $task['started'] > 0 && (time() - (int) $task['started']) < self::RUN_LIMIT) {
            return;
        }
        $task['started'] = time();
        self::save($task);

        if (function_exists('set_time_limit')) {
            @set_time_limit(self::RUN_LIMIT);
        }

        $result = GS_Gemini::transcribe($task['audio_url'], $task['params']);

        $task = self::load($id);
        if (!$task) {
            return;
        }
        if (!empty($result['ok'])) {
            $task['status']   = 'completed';
            $task['text']     = (string) $result['text'];
            $task['segments'] = is_array($result['segments']) ? $result['segments'] : array();
            $task['language'] = (string) $result['language'];
            $task['credits']  = (float) $result['credits'];
        } else {
            $task['status'] = 'failed';
            $task['error']  = (string) $result['error'];
            self::log('расшифровка не удалась (' . $id . '): ' . $result['error']);
        }
        $task['finished'] = time();
        self::save($task);
    }

    private static function save($task) {
        set_transient(self::TASK_PREFIX . $task['id'], $task, self::TASK_TTL);
    }

    private static function load($id) {
        $task = get_transient(self::TASK_PREFIX . $id);
        return is_array($task) && !empty($task['id']) ? $task : null;
    }

    /* ---------------------------------------------------------------------
     * Повтор при отказе поставщика
     * ------------------------------------------------------------------ */

    public static function after_callbacks($response, $handler, $request) {
        if (!self::enabled() || !($request instanceof WP_REST_Request)) {
            return $response;
        }
        $route = (string) $request->get_route();
        if (strpos($route, '/tts/v1/') === false || strpos($route, 'transcribe') === false) {
            return $response;
        }

        if (strpos($route, 'transcribe-status') !== false) {
            return self::after_status($response, $request);
        }
        return self::after_create($response);
    }

    /** Сохраняем исходник задачи, чтобы её можно было повторить. */
    private static function after_create($response) {
        if (!($response instanceof WP_REST_Response) || self::$pending === null) {
            return $response;
        }
        $body = $response->get_data();
        if (!is_array($body) || empty($body['task_id'])) {
            return $response;
        }
        $source = self::$pending;
        $source['audio_url'] = !empty($body['audio_url']) ? (string) $body['audio_url'] : $source['audio_url'];
        update_option(self::SRC_PREFIX . $body['task_id'], $source, false);
        self::$pending = null;
        return $response;
    }

    /**
     * Поставщик иногда отваливается по таймауту. Один раз повторяем
     * задачу сами и дальше отдаём состояние повтора под прежним номером.
     */
    private static function after_status($response, $request) {
        if (!($response instanceof WP_REST_Response)) {
            return $response;
        }
        $body = $response->get_data();
        if (!is_array($body)) {
            return $response;
        }
        $task_id = (string) $request->get_param('task_id');
        if ($task_id === '') {
            return $response;
        }

        $mapped = (string) get_option(self::MAP_PREFIX . $task_id, '');
        if ($mapped !== '') {
            return self::mirror($response, $mapped);
        }

        if ((string) ($body['status'] ?? '') !== 'failed') {
            return $response;
        }
        if (!self::is_provider_timeout((string) ($body['error_message'] ?? ''))) {
            return $response;
        }

        $source = get_option(self::SRC_PREFIX . $task_id, array());
        if (!is_array($source) || empty($source['audio_url']) || !class_exists('KIE_TTS_API')) {
            return $response;
        }

        $created = KIE_TTS_API::create_speech_to_text_task(
            (string) $source['audio_url'],
            is_array($source['params']) ? $source['params'] : array(),
            null
        );
        $new_id = is_array($created) && !empty($created['data']['taskId']) ? (string) $created['data']['taskId'] : '';
        if ($new_id === '') {
            return $response;
        }

        update_option(self::MAP_PREFIX . $task_id, $new_id, false);
        update_option(self::SRC_PREFIX . $new_id, $source, false);
        self::log('повтор расшифровки после отказа поставщика: ' . $task_id . ' → ' . $new_id);

        $body['status'] = 'pending';
        $body['error_message'] = '';
        $body['retried'] = true;
        $response->set_data($body);
        return $response;
    }

    private static function is_provider_timeout($message) {
        $message = mb_strtolower($message);
        foreach (array('timed out', 'timeout', 'no results were returned', 'try again') as $needle) {
            if (strpos($message, $needle) !== false) {
                return true;
            }
        }
        return false;
    }

    /** Отдаём состояние повтора под номером исходной задачи. */
    private static function mirror($response, $new_id) {
        $request = new WP_REST_Request('GET', '/tts/v1/transcribe-status/' . $new_id);
        $request->set_param('task_id', $new_id);
        $fresh = rest_do_request($request);
        if ($fresh instanceof WP_REST_Response && is_array($fresh->get_data())) {
            $data = $fresh->get_data();
            $data['retried'] = true;
            $response->set_data($data);
        }
        return $response;
    }

    private static function log($message) {
        $log = get_option('gs_stt_log', array());
        if (!is_array($log)) {
            $log = array();
        }
        array_unshift($log, array('at' => current_time('mysql'), 'message' => (string) $message));
        update_option('gs_stt_log', array_slice($log, 0, 30), false);
    }

    public static function get_log() {
        $log = get_option('gs_stt_log', array());
        return is_array($log) ? $log : array();
    }
}
