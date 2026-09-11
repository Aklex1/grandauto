<?php
/**
 * Подстраховка расшифровки записей.
 *
 * Две беды у живого сервиса. Первая: поле языка — свободный ввод, и
 * «rus» вместо «ru» поставщик отклоняет целиком. Вторая: поставщик
 * временами отваливается по таймауту, и пользователь видит отказ на
 * ровном месте.
 *
 * Базовый плагин не трогаем: приводим код языка к понятному виду до
 * вызова и один раз молча повторяем задачу, если она сорвалась не по
 * вине пользователя.
 */

if (!defined('ABSPATH')) {
    exit;
}

class GS_Transcribe {

    const OPT_ENABLED = 'gs_stt_guard';
    /** Исходник задачи: нужен, чтобы её можно было повторить. */
    const SRC_PREFIX  = 'gs_stt_src_';
    /** Соответствие «сорвавшаяся задача → повтор». */
    const MAP_PREFIX  = 'gs_stt_retry_';

    public static function boot() {
        add_filter('rest_pre_dispatch', array(__CLASS__, 'normalize_request'), 10, 3);
        add_filter('rest_request_after_callbacks', array(__CLASS__, 'after_callbacks'), 20, 3);
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

    public static function normalize_request($result, $server, $request) {
        if (!self::enabled() || !($request instanceof WP_REST_Request)) {
            return $result;
        }
        $route = (string) $request->get_route();
        if (strpos($route, '/tts/v1/') === false || strpos($route, 'transcribe') === false) {
            return $result;
        }
        if (strpos($route, 'transcribe-status') !== false) {
            return $result;
        }

        $params = $request->get_json_params();
        if (!is_array($params)) {
            return $result;
        }
        if (isset($params['language_code'])) {
            $clean = self::normalize_language($params['language_code']);
            if ($clean !== (string) $params['language_code']) {
                $params['language_code'] = $clean;
                $request->set_body(wp_json_encode($params));
                $request->set_param('language_code', $clean);
            }
        }
        // Запоминаем исходные данные — по ним можно будет повторить задачу.
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

    /** @var array|null Данные запроса, который сейчас обрабатывается. */
    private static $pending = null;

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
