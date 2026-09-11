<?php
/**
 * Генерация звуков и спецэффектов через KIE (Suno «Generate Sounds»).
 *
 * Эндпоинты KIE:
 *   POST https://api.kie.ai/api/v1/generate/sounds     — создать задачу
 *   GET  https://api.kie.ai/api/v1/generate/record-info?taskId=... — статус и ссылки
 */

if (!defined('ABSPATH')) {
    exit;
}

class GS_SFX {

    const API_CREATE = 'https://api.kie.ai/api/v1/generate/sounds';
    const API_STATUS = 'https://api.kie.ai/api/v1/generate/record-info';

    const OPT_COST     = 'gs_sfx_cost';
    const OPT_SHOWCASE = 'gs_sfx_showcase';
    const OPT_CB_TOKEN = 'gs_sfx_callback_token';

    const MODE_SFX     = 'sfx';
    const MODE_AMBIENT = 'ambient';
    const MODE_LOOP    = 'loop';

    public static function boot() {
        // Точка расширения: пока вся работа идёт через REST-слой.
    }

    /* ---------------------------------------------------------------------
     * Настройки
     * ------------------------------------------------------------------ */

    public static function get_api_key() {
        $key = trim((string) get_option('kie_tts_api_key', ''));
        return $key;
    }

    /**
     * Цена одной генерации в рублях (списывается с того же баланса, что и озвучка).
     */
    /**
     * Секрет для колбэка. Сам колбэк открыт наружу (иначе провайдер не достучится),
     * поэтому единственная защита — токен в адресе, который знаем только мы и он.
     */
    public static function callback_token() {
        $token = (string) get_option(self::OPT_CB_TOKEN, '');
        if ($token === '') {
            $token = wp_generate_password(32, false, false);
            update_option(self::OPT_CB_TOKEN, $token, false);
        }
        return $token;
    }

    public static function get_cost() {
        $cost = (float) get_option(self::OPT_COST, 9);
        return $cost > 0 ? round($cost, 2) : 9.00;
    }

    public static function get_models() {
        return array(
            'V5'   => array(
                'label' => 'Стандартная — звуки и эффекты',
                'hint'  => 'Лучший выбор для отдельных SFX: удары, выстрелы, интерфейсные звуки.',
            ),
            'V5_5' => array(
                'label' => 'Расширенная — атмосфера и музыка',
                'hint'  => 'Длиннее и музыкальнее: эмбиенс, фоновые подложки, лупы.',
            ),
        );
    }

    public static function get_keys() {
        return array('', 'C', 'C#', 'D', 'D#', 'E', 'F', 'F#', 'G', 'G#', 'A', 'A#', 'B',
                     'Cm', 'C#m', 'Dm', 'D#m', 'Em', 'Fm', 'F#m', 'Gm', 'G#m', 'Am', 'A#m', 'Bm');
    }

    /**
     * Готовые сценарии для быстрого старта.
     */
    public static function get_presets() {
        return array(
            array('label' => 'Взрыв',        'prompt' => 'кинематографичный взрыв, глубокий бас, разлетающиеся обломки', 'mode' => self::MODE_SFX),
            array('label' => 'Выстрел',      'prompt' => 'одиночный выстрел из винтовки, резкая атака, эхо вдалеке',    'mode' => self::MODE_SFX),
            array('label' => 'Шаги',         'prompt' => 'шаги по гравию, средний темп, ботинки',                        'mode' => self::MODE_SFX),
            array('label' => 'Уведомление',  'prompt' => 'короткий приятный звук уведомления, стеклянный тон',           'mode' => self::MODE_SFX),
            array('label' => 'Клик UI',      'prompt' => 'чистый клик интерфейса, очень короткий, цифровой',             'mode' => self::MODE_SFX),
            array('label' => 'Дождь',        'prompt' => 'ровный дождь по крыше, без грома, спокойный фон',              'mode' => self::MODE_AMBIENT),
            array('label' => 'Гром',         'prompt' => 'раскат грома вдалеке, низкий гул, нарастание',                 'mode' => self::MODE_SFX),
            array('label' => 'Лес',          'prompt' => 'утренний лес, птицы, лёгкий ветер в листве',                   'mode' => self::MODE_AMBIENT),
            array('label' => 'Город',        'prompt' => 'шум города, поток машин, отдалённые голоса',                   'mode' => self::MODE_AMBIENT),
            array('label' => 'Магия',        'prompt' => 'магическое заклинание, искрящийся переливчатый всполох',       'mode' => self::MODE_SFX),
            array('label' => 'Меч',          'prompt' => 'удар меча о металл, звонкий лязг, короткое эхо',               'mode' => self::MODE_SFX),
            array('label' => 'Двигатель',    'prompt' => 'спортивный автомобиль, разгон, рёв двигателя',                 'mode' => self::MODE_SFX),
            array('label' => 'Космос',       'prompt' => 'гул космического корабля, низкая вибрация, бесконечный фон',   'mode' => self::MODE_LOOP),
            array('label' => 'Хоррор',       'prompt' => 'тревожный скрежет, нарастающее напряжение, хоррор',            'mode' => self::MODE_AMBIENT),
        );
    }

    public static function get_modes() {
        return array(
            self::MODE_SFX     => 'Отдельный эффект (SFX)',
            self::MODE_AMBIENT => 'Атмосфера / фон',
            self::MODE_LOOP    => 'Бесшовный луп',
        );
    }

    /* ---------------------------------------------------------------------
     * Промпт
     * ------------------------------------------------------------------ */

    /**
     * Suno «слышит» английский лучше, но описание пользователя мы не переводим —
     * добавляем технические маркеры, которые задают характер и убирают музыку/голос.
     *
     * @param string $raw      описание от пользователя
     * @param string $mode     sfx|ambient|loop
     * @param int    $seconds  желаемая длительность
     */
    public static function build_prompt($raw, $mode = self::MODE_SFX, $seconds = 4) {
        $raw = trim(preg_replace('~\s+~u', ' ', (string) $raw));
        if ($raw === '') {
            return '';
        }

        $seconds = max(1, min(60, (int) $seconds));
        $suffix = array();

        switch ($mode) {
            case self::MODE_AMBIENT:
                $suffix[] = 'ambient sound design';
                $suffix[] = 'realistic field recording';
                $suffix[] = 'steady background texture';
                $suffix[] = 'no music, no voices, no melody';
                break;

            case self::MODE_LOOP:
                $suffix[] = 'seamless loopable ambience';
                $suffix[] = 'constant level, no fade in, no fade out';
                $suffix[] = 'no music, no voices, no melody';
                break;

            case self::MODE_SFX:
            default:
                $suffix[] = 'realistic cinematic sound effect';
                $suffix[] = 'sharp transient, clean tail';
                $suffix[] = 'no music, no voices, no melody';
                break;
        }

        $suffix[] = $seconds . ' seconds';

        return $raw . ', ' . implode(', ', $suffix);
    }

    /* ---------------------------------------------------------------------
     * KIE API
     * ------------------------------------------------------------------ */

    /**
     * @return array{ok:bool,task_id:string,message:string,raw:array}
     */
    public static function create_task($prompt, $params = array()) {
        $key = self::get_api_key();
        if ($key === '') {
            return array('ok' => false, 'task_id' => '', 'message' => 'Генерация временно недоступна: не настроен доступ к сервису', 'raw' => array());
        }

        $prompt = (string) $prompt;
        if (mb_strlen($prompt) > 500) {
            $prompt = mb_substr($prompt, 0, 500);
        }

        $model = isset($params['model']) ? (string) $params['model'] : 'V5';
        if (!array_key_exists($model, self::get_models())) {
            $model = 'V5';
        }

        $payload = array(
            'prompt'     => $prompt,
            'model'      => $model,
            'soundLoop'  => !empty($params['loop']),
            'grabLyrics' => false,
        );

        if (!empty($params['tempo'])) {
            $tempo = (int) $params['tempo'];
            if ($tempo >= 1 && $tempo <= 300) {
                $payload['soundTempo'] = $tempo;
            }
        }
        if (!empty($params['key']) && in_array((string) $params['key'], self::get_keys(), true)) {
            $payload['soundKey'] = (string) $params['key'];
        }
        if (!empty($params['callback_url'])) {
            $payload['callBackUrl'] = esc_url_raw((string) $params['callback_url']);
        }

        $response = wp_remote_post(self::API_CREATE, array(
            'timeout' => 45,
            'headers' => array(
                'Authorization' => 'Bearer ' . $key,
                'Content-Type'  => 'application/json',
            ),
            'body'    => wp_json_encode($payload),
        ));

        if (is_wp_error($response)) {
            return array('ok' => false, 'task_id' => '', 'message' => $response->get_error_message(), 'raw' => array());
        }

        $body = json_decode((string) wp_remote_retrieve_body($response), true);
        if (!is_array($body)) {
            return array('ok' => false, 'task_id' => '', 'message' => 'Некорректный ответ сервиса генерации', 'raw' => array());
        }

        $code = isset($body['code']) ? (int) $body['code'] : 0;
        if ($code !== 200 || empty($body['data']['taskId'])) {
            $msg = isset($body['msg']) ? (string) $body['msg'] : 'Сервис генерации вернул ошибку';
            return array('ok' => false, 'task_id' => '', 'message' => $msg . ' (code ' . $code . ')', 'raw' => $body);
        }

        return array('ok' => true, 'task_id' => (string) $body['data']['taskId'], 'message' => '', 'raw' => $body);
    }

    /**
     * Статус задачи.
     *
     * @return array{ok:bool,status:string,audio_url:string,title:string,duration:float,message:string}
     */
    public static function fetch_task($task_id) {
        $key = self::get_api_key();
        $out = array('ok' => false, 'status' => '', 'audio_url' => '', 'title' => '', 'duration' => 0.0, 'message' => '');

        if ($key === '') {
            $out['message'] = 'Генерация временно недоступна: не настроен доступ к сервису';
            return $out;
        }

        $response = wp_remote_get(add_query_arg('taskId', rawurlencode((string) $task_id), self::API_STATUS), array(
            'timeout' => 45,
            'headers' => array('Authorization' => 'Bearer ' . $key),
        ));

        if (is_wp_error($response)) {
            $out['message'] = $response->get_error_message();
            return $out;
        }

        $body = json_decode((string) wp_remote_retrieve_body($response), true);
        if (!is_array($body) || !isset($body['data'])) {
            $out['message'] = 'Некорректный ответ сервиса генерации';
            return $out;
        }

        $data = $body['data'];
        $out['ok'] = true;
        $out['status'] = isset($data['status']) ? (string) $data['status'] : '';

        if (!empty($data['errorMessage'])) {
            $out['message'] = (string) $data['errorMessage'];
        }

        $items = array();
        if (!empty($data['response']['sunoData']) && is_array($data['response']['sunoData'])) {
            $items = $data['response']['sunoData'];
        }
        if (!empty($items[0]) && is_array($items[0])) {
            $first = $items[0];
            foreach (array('audio_url', 'source_audio_url', 'stream_audio_url') as $field) {
                if (!empty($first[$field])) {
                    $out['audio_url'] = (string) $first[$field];
                    break;
                }
            }
            $out['title'] = isset($first['title']) ? (string) $first['title'] : '';
            $out['duration'] = isset($first['duration']) ? (float) $first['duration'] : 0.0;
        }

        return $out;
    }

    public static function is_failed_status($status) {
        return in_array((string) $status, array(
            'CREATE_TASK_FAILED',
            'GENERATE_AUDIO_FAILED',
            'CALLBACK_EXCEPTION',
            'SENSITIVE_WORD_ERROR',
        ), true);
    }

    /* ---------------------------------------------------------------------
     * Сохранение результата
     * ------------------------------------------------------------------ */

    /**
     * Копируем готовый звук к себе: ссылки KIE живут ограниченное время.
     *
     * @return string публичный URL локальной копии либо '' при неудаче
     */
    public static function store_result($task_id, $audio_url) {
        $task_id = preg_replace('~[^a-zA-Z0-9_-]~', '', (string) $task_id);
        if ($task_id === '' || $audio_url === '') {
            return '';
        }

        GS_Storage::ensure_dirs();
        $filename = $task_id . '.mp3';
        $target   = GS_Storage::generated_dir() . '/' . $filename;

        if (file_exists($target) && filesize($target) > 1024) {
            return GS_Storage::generated_url() . '/' . $filename;
        }

        $response = wp_remote_get($audio_url, array('timeout' => 120));
        if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) !== 200) {
            return '';
        }
        $body = wp_remote_retrieve_body($response);
        if (strlen((string) $body) < 1024) {
            return '';
        }
        if (!GS_Storage::atomic_put($target, $body)) {
            return '';
        }
        return GS_Storage::generated_url() . '/' . $filename;
    }

    /* ---------------------------------------------------------------------
     * Витрина примеров
     * ------------------------------------------------------------------ */

    public static function get_showcase() {
        $items = get_option(self::OPT_SHOWCASE, array());
        return is_array($items) ? $items : array();
    }

    /**
     * Добавляет звук в публичную витрину примеров (страница /ai-zvuki/).
     */
    public static function add_to_showcase(array $item) {
        $items = self::get_showcase();
        $item = wp_parse_args($item, array(
            'title'      => 'Сгенерированный звук',
            'prompt'     => '',
            'url'        => '',
            'model'      => 'V5',
            'mode'       => self::MODE_SFX,
            'duration'   => 0,
            'created_at' => current_time('mysql'),
        ));
        if ($item['url'] === '') {
            return false;
        }
        foreach ($items as $existing) {
            if (isset($existing['url']) && $existing['url'] === $item['url']) {
                return true;
            }
        }
        array_unshift($items, $item);
        $items = array_slice($items, 0, 60);
        return update_option(self::OPT_SHOWCASE, $items, false);
    }

    /* ---------------------------------------------------------------------
     * Баланс (общий с озвучкой)
     * ------------------------------------------------------------------ */

    public static function balance_available() {
        return class_exists('KIE_TTS_DB');
    }

    public static function get_balance($user_id) {
        if (!self::balance_available()) {
            return 0.0;
        }
        $is_telegram = class_exists('KIE_TTS_Auth') && KIE_TTS_Auth::is_telegram_user($user_id);
        if ($is_telegram) {
            $telegram_id = KIE_TTS_Auth::get_telegram_id($user_id);
            return (float) KIE_TTS_DB::get_user_balance($telegram_id, true);
        }
        return (float) KIE_TTS_DB::get_user_balance($user_id, false);
    }

    public static function charge($user_id, $amount) {
        if (!self::balance_available()) {
            return false;
        }
        $is_telegram = class_exists('KIE_TTS_Auth') && KIE_TTS_Auth::is_telegram_user($user_id);
        if ($is_telegram) {
            $telegram_id = KIE_TTS_Auth::get_telegram_id($user_id);
            return (bool) KIE_TTS_DB::deduct_balance($telegram_id, $amount, true);
        }
        return (bool) KIE_TTS_DB::deduct_balance($user_id, $amount, false);
    }

    public static function refund($user_id, $amount) {
        if (!self::balance_available()) {
            return false;
        }
        $is_telegram = class_exists('KIE_TTS_Auth') && KIE_TTS_Auth::is_telegram_user($user_id);
        if ($is_telegram) {
            $telegram_id = KIE_TTS_Auth::get_telegram_id($user_id);
            return (bool) KIE_TTS_DB::update_user_balance($telegram_id, $amount, true);
        }
        return (bool) KIE_TTS_DB::update_user_balance($user_id, $amount, false);
    }
}
