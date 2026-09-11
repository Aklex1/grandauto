<?php
/**
 * Запасная озвучка на Gemini TTS.
 *
 * Основной поставщик голоса (ElevenLabs) регулярно отвечает ошибкой, и генерация
 * у пользователя просто падает. Здесь мы перехватываем оба вида отказа —
 * и отказ при постановке задачи, и провал уже поставленной — и повторяем
 * запрос на google/gemini-3-1-flash-tts.
 *
 * Базовый плагин kie-tts-wp не меняется: подключаемся фильтром
 * rest_request_after_callbacks к его же маршрутам. Списание остаётся на стороне
 * базового плагина — под новую задачу заводится обычная строка в истории
 * генераций, и его колбэк списывает деньги ровно один раз.
 */

if (!defined('ABSPATH')) {
    exit;
}

class GS_Tts_Fallback {

    const OPT_ENABLED = 'gs_tts_fallback';
    const API_JOBS    = 'https://api.kie.ai/api/v1/jobs/createTask';
    const MODEL       = 'google/gemini-3-1-flash-tts';

    /** Префикс опции с соответствием «упавшая задача → задача-дублёр». */
    const MAP_PREFIX  = 'gs_tts_fb_';

    public static function boot() {
        add_filter('rest_request_after_callbacks', array(__CLASS__, 'intercept'), 20, 3);
    }

    public static function enabled() {
        return (string) get_option(self::OPT_ENABLED, '1') === '1';
    }

    /* ---------------------------------------------------------------------
     * Перехват маршрутов базового плагина
     * ------------------------------------------------------------------ */

    public static function intercept($response, $handler, $request) {
        if (!self::enabled() || !($request instanceof WP_REST_Request)) {
            return $response;
        }
        $route = (string) $request->get_route();

        if (strpos($route, '/tts/v1/generate') !== false) {
            return self::after_generate($response, $request);
        }
        if (strpos($route, '/tts/v1/status/') !== false) {
            return self::after_status($response, $request);
        }
        return $response;
    }

    /**
     * Задача даже не поставилась — пробуем сразу запасным голосом.
     */
    private static function after_generate($response, $request) {
        if (!is_wp_error($response)) {
            return $response;
        }
        $data = $response->get_error_data();
        $status = is_array($data) && isset($data['status']) ? (int) $data['status'] : 0;
        // Нехватка баланса, пустой текст и прочая валидация — не наш случай.
        if ($status !== 0 && $status < 500) {
            return $response;
        }

        $params = $request->get_json_params();
        if (!is_array($params)) {
            return $response;
        }
        $model = isset($params['model']) ? (string) $params['model'] : '';
        if ($model !== '' && strpos($model, 'elevenlabs/') !== 0) {
            return $response;
        }
        $text = isset($params['text']) ? (string) $params['text'] : '';
        if (trim($text) === '') {
            return $response;
        }

        $user_id = get_current_user_id();
        if ($user_id <= 0) {
            return $response;
        }

        $cost = class_exists('KIE_TTS_API') ? (float) KIE_TTS_API::calculate_cost($text) : 0.0;
        $voice = isset($params['voice']) ? (string) $params['voice'] : '';

        $created = self::create_task($text, $voice);
        if (empty($created['ok'])) {
            return $response;
        }

        self::register_generation($created['task_id'], $user_id, $text, $cost);
        self::log('запасной голос сразу, задача ' . $created['task_id']);

        return new WP_REST_Response(array(
            'success'  => true,
            'task_id'  => $created['task_id'],
            'cost'     => $cost,
            'balance'  => self::balance($user_id),
            'fallback' => true,
        ), 200);
    }

    /**
     * Задача поставилась, но провалилась. Базовый статус читает только строку
     * в истории, поэтому здесь же запускаем дублёра и дальше отдаём его данные.
     */
    private static function after_status($response, $request) {
        if (is_wp_error($response) || !($response instanceof WP_REST_Response)) {
            return $response;
        }
        $body = $response->get_data();
        if (!is_array($body) || empty($body['task_id'])) {
            return $response;
        }
        $task_id = (string) $body['task_id'];
        $status  = isset($body['status']) ? (string) $body['status'] : '';

        // Уже есть дублёр — отдаём его состояние под прежним идентификатором.
        $mapped = get_option(self::MAP_PREFIX . $task_id, '');
        if ($mapped !== '') {
            return self::mirror_mapped($response, $body, (string) $mapped);
        }

        if ($status !== 'failed') {
            return $response;
        }

        $generation = class_exists('KIE_TTS_DB') ? KIE_TTS_DB::get_generation_by_task_id($task_id) : null;
        if (!is_array($generation)) {
            return $response;
        }
        // Дублировать запасную задачу запасной же не надо.
        if (strpos((string) $generation['voice'], 'gemini') !== false) {
            return $response;
        }

        $text = (string) $generation['text'];
        if (trim($text) === '') {
            return $response;
        }

        $created = self::create_task($text, (string) $generation['voice']);
        if (empty($created['ok'])) {
            return $response;
        }

        $cost = (float) $generation['cost'];
        self::register_generation($created['task_id'], (int) $generation['user_id'], $text, $cost);

        // Со старой задачи снимаем стоимость, чтобы колбэк не списал дважды.
        self::zero_cost($task_id);
        update_option(self::MAP_PREFIX . $task_id, $created['task_id'], false);
        self::log('переозвучка после отказа: ' . $task_id . ' → ' . $created['task_id']);

        $body['status'] = 'pending';
        $body['fallback'] = true;
        $response->set_data($body);
        return $response;
    }

    /**
     * Отдаём состояние задачи-дублёра под идентификатором исходной,
     * чтобы фронтенд продолжал опрашивать тот же адрес.
     */
    private static function mirror_mapped($response, $body, $mapped_task_id) {
        $row = class_exists('KIE_TTS_DB') ? KIE_TTS_DB::get_generation_by_task_id($mapped_task_id) : null;
        if (!is_array($row)) {
            return $response;
        }
        $body['status']       = (string) $row['status'];
        $body['audio_url']    = (string) $row['audio_url'];
        $body['completed_at'] = (string) $row['completed_at'];
        $body['fallback']     = true;
        $response->set_data($body);
        return $response;
    }

    /* ---------------------------------------------------------------------
     * Вызов запасной модели
     * ------------------------------------------------------------------ */

    /**
     * Голоса Gemini отличаются от ElevenLabs, поэтому подбираем по полу:
     * лучше близкий тембр, чем отказ генерации.
     */
    public static function map_voice($voice) {
        $female = array('Kore', 'Aoede', 'Leda', 'Autonoe', 'Despina');
        $male   = array('Charon', 'Puck', 'Fenrir', 'Orus', 'Iapetus');

        $catalog = array();
        if (class_exists('KIE_TTS_API') && method_exists('KIE_TTS_API', 'get_elevenlabs_voices_catalog')) {
            $catalog = (array) KIE_TTS_API::get_elevenlabs_voices_catalog();
        }
        $gender = '';
        foreach ($catalog as $item) {
            if (!is_array($item)) {
                continue;
            }
            if ((string) ($item['id'] ?? '') === (string) $voice) {
                $gender = mb_strtolower((string) ($item['category'] ?? $item['gender'] ?? ''));
                break;
            }
        }

        $pool = (strpos($gender, 'male') !== false && strpos($gender, 'female') === false) ? $male : $female;
        $index = abs(crc32((string) $voice)) % count($pool);
        return $pool[$index];
    }

    /**
     * @return array{ok:bool,task_id:string,message:string}
     */
    public static function create_task($text, $voice = '') {
        $key = trim((string) get_option('kie_tts_api_key', ''));
        if ($key === '') {
            return array('ok' => false, 'task_id' => '', 'message' => 'Не настроен доступ к сервису');
        }

        $text = (string) $text;
        if (mb_strlen($text) > 10000) {
            $text = mb_substr($text, 0, 10000);
        }

        $callback = get_option('kie_tts_callback_url', rest_url('tts/v1/callback'));

        $payload = array(
            'model'       => self::MODEL,
            'callBackUrl' => $callback,
            'input'       => array(
                'speakers' => array(
                    array(
                        'speaker_id' => 'Speaker 1',
                        'voice_name' => self::map_voice($voice),
                        'accent'     => 'Neutral',
                    ),
                ),
                'dialogue_turns' => array(
                    array('speaker_id' => 'Speaker 1', 'text' => $text),
                ),
            ),
        );

        $response = wp_remote_post(self::API_JOBS, array(
            'timeout' => 45,
            'headers' => array(
                'Authorization' => 'Bearer ' . $key,
                'Content-Type'  => 'application/json',
            ),
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
                'message' => is_array($body) ? (string) ($body['msg'] ?? 'Отказ сервиса') : 'Некорректный ответ',
            );
        }
        return array('ok' => true, 'task_id' => (string) $body['data']['taskId'], 'message' => '');
    }

    /* ---------------------------------------------------------------------
     * Вспомогательное
     * ------------------------------------------------------------------ */

    private static function register_generation($task_id, $user_id, $text, $cost) {
        if (!class_exists('KIE_TTS_DB')) {
            return;
        }
        $is_telegram = class_exists('KIE_TTS_Auth') && KIE_TTS_Auth::is_telegram_user($user_id);
        KIE_TTS_DB::save_generation($user_id, $task_id, $text, 'gemini-tts', $cost, $is_telegram);
    }

    private static function zero_cost($task_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'kie_tts_generations';
        $wpdb->update($table, array('cost' => 0), array('task_id' => $task_id));
    }

    private static function balance($user_id) {
        if (!class_exists('KIE_TTS_DB')) {
            return 0.0;
        }
        $is_telegram = class_exists('KIE_TTS_Auth') && KIE_TTS_Auth::is_telegram_user($user_id);
        if ($is_telegram) {
            return (float) KIE_TTS_DB::get_user_balance(KIE_TTS_Auth::get_telegram_id($user_id), true);
        }
        return (float) KIE_TTS_DB::get_user_balance($user_id, false);
    }

    private static function log($message) {
        $log = get_option('gs_tts_fallback_log', array());
        if (!is_array($log)) {
            $log = array();
        }
        array_unshift($log, array('at' => current_time('mysql'), 'message' => (string) $message));
        update_option('gs_tts_fallback_log', array_slice($log, 0, 30), false);
    }

    public static function get_log() {
        $log = get_option('gs_tts_fallback_log', array());
        return is_array($log) ? $log : array();
    }
}
