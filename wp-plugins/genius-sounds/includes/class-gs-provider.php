<?php
/**
 * Адаптер поставщиков: единая точка, через которую сервисы просят работу.
 *
 * Микросервис больше не знает ни адреса, ни названия модели — он говорит,
 * что ему нужно («картинка», «текст», «липсинк»), и отдаёт понятные ему
 * данные. Куда это отправить, решает адаптер.
 *
 * Зачем: у агрегатора регулярно ложится не весь сервис, а отдельная модель.
 * Пока маршрут был прибит к коду сервиса, такой сбой означал «сервис не
 * работает», хотя рядом стоят три живые модели, умеющие то же самое.
 *
 * Упавший маршрут запоминается: следующий запрос его пропускает сразу, а не
 * ждёт три таймаута заново. Через DOWN_TTL маршрут снова допускается к
 * работе — сам себя он починенным объявить не может.
 */

if (!defined('ABSPATH')) {
    exit;
}

class GS_Provider {

    const API_JOBS = 'https://api.kie.ai/api/v1/jobs/createTask';
    const API_INFO = 'https://api.kie.ai/api/v1/jobs/recordInfo';
    const CHAT_URL = 'https://api.kie.ai/%s/v1/chat/completions';

    const OPT_HEALTH = 'gs_provider_health';
    const OPT_LOG    = 'gs_provider_log';
    const LOG_KEEP   = 60;

    /** Сколько держим маршрут исключённым после отказа поставщика. */
    const DOWN_TTL = 300;

    /* ---------------------------------------------------------------------
     * Таблица маршрутов
     * ------------------------------------------------------------------ */

    /**
     * Что умеем и чем это закрывается — по порядку предпочтения.
     *
     * shape превращает наши обычные поля в то, что ждёт конкретная модель:
     * у одной размер кадра называется image_size, у другой aspect_ratio, и
     * прятать это различие — ровно работа адаптера.
     */
    public static function routes() {
        $routes = array(
            'chat' => array(
                array('id' => 'chat:gemini-2.5-flash', 'kind' => 'chat', 'model' => 'gemini-2.5-flash'),
                array('id' => 'chat:gpt-5-2',          'kind' => 'chat', 'model' => 'gpt-5-2'),
            ),

            'image' => array(
                array(
                    'id' => 'image:nano-banana', 'kind' => 'job', 'model' => 'google/nano-banana',
                    'shape' => function ($in) {
                        return array(
                            'prompt'        => (string) $in['prompt'],
                            'output_format' => 'png',
                            'image_size'    => (string) ($in['ratio'] ?? '16:9'),
                        );
                    },
                ),
                array(
                    'id' => 'image:z-image', 'kind' => 'job', 'model' => 'z-image',
                    'shape' => function ($in) {
                        return array(
                            'prompt'       => (string) $in['prompt'],
                            'image_size'   => (string) ($in['ratio'] ?? '16:9'),
                            'aspect_ratio' => (string) ($in['ratio'] ?? '16:9'),
                        );
                    },
                ),
                array(
                    'id' => 'image:imagen4-fast', 'kind' => 'job', 'model' => 'google/imagen4-fast',
                    'shape' => function ($in) {
                        return array(
                            'prompt'       => (string) $in['prompt'],
                            'aspect_ratio' => (string) ($in['ratio'] ?? '16:9'),
                        );
                    },
                ),
                array(
                    'id' => 'image:seedream-v4', 'kind' => 'job', 'model' => 'bytedance/seedream-v4-text-to-image',
                    'shape' => function ($in) {
                        // У этой модели размер — из своего списка названий.
                        $map = array('16:9' => 'landscape_16_9', '9:16' => 'portrait_16_9',
                                     '3:4' => 'portrait_4_3', '4:3' => 'landscape_4_3', '1:1' => 'square_hd');
                        $ratio = (string) ($in['ratio'] ?? '16:9');
                        return array(
                            'prompt'     => (string) $in['prompt'],
                            'image_size' => $map[$ratio] ?? 'landscape_16_9',
                        );
                    },
                ),
            ),

            // Ниже — сервисы, у которых запасной модели пока нет. Они здесь
            // не ради отказоустойчивости, а чтобы маршрут был описан в одном
            // месте: добавить запасной — одна строка, а не правка сервиса.
            'avatar' => array(
                array(
                    'id' => 'avatar:kling', 'kind' => 'job', 'model' => 'kling/ai-avatar-pro',
                    'shape' => function ($in) {
                        return array(
                            'image_url' => (string) $in['image_url'],
                            'audio_url' => (string) $in['audio_url'],
                            'prompt'    => (string) ($in['prompt'] ?? ''),
                        );
                    },
                ),
            ),

            'denoise' => array(
                array(
                    'id' => 'denoise:elevenlabs', 'kind' => 'job', 'model' => 'elevenlabs/audio-isolation',
                    'shape' => function ($in) {
                        return array('audio_url' => (string) $in['audio_url']);
                    },
                ),
            ),
        );

        /**
         * Позволяем дополнять таблицу, не трогая этот файл.
         */
        return apply_filters('gs_provider_routes', $routes);
    }

    public static function capabilities() {
        return array_keys(self::routes());
    }

    /* ---------------------------------------------------------------------
     * Здоровье маршрутов
     * ------------------------------------------------------------------ */

    private static function health() {
        $rows = get_option(self::OPT_HEALTH, array());
        return is_array($rows) ? $rows : array();
    }

    public static function is_down($route_id) {
        $rows = self::health();
        $until = (int) ($rows[$route_id]['until'] ?? 0);
        return $until > time();
    }

    private static function mark_down($route_id, $why) {
        $rows = self::health();
        $rows[$route_id] = array(
            'until' => time() + self::DOWN_TTL,
            'why'   => mb_substr((string) $why, 0, 160),
            'at'    => time(),
        );
        update_option(self::OPT_HEALTH, $rows, false);
        self::remember($route_id, false, $why);
    }

    private static function mark_up($route_id) {
        $rows = self::health();
        if (isset($rows[$route_id])) {
            unset($rows[$route_id]);
            update_option(self::OPT_HEALTH, $rows, false);
        }
    }

    /** Короткая история переключений — чтобы было видно, что и когда падало. */
    private static function remember($route_id, $ok, $note) {
        $log = (array) get_option(self::OPT_LOG, array());
        $log[] = array(
            'at'    => current_time('mysql'),
            'route' => $route_id,
            'ok'    => (bool) $ok,
            'note'  => mb_substr((string) $note, 0, 160),
        );
        update_option(self::OPT_LOG, array_slice($log, -self::LOG_KEEP), false);
    }

    public static function log_rows() {
        return array_reverse((array) get_option(self::OPT_LOG, array()));
    }

    /** Состояние для админки. */
    public static function status() {
        $out = array();
        foreach (self::routes() as $capability => $routes) {
            foreach ($routes as $i => $route) {
                $rows = self::health();
                $down = self::is_down($route['id']);
                $out[] = array(
                    'capability' => $capability,
                    'id'         => $route['id'],
                    'model'      => $route['model'],
                    'primary'    => $i === 0,
                    'down'       => $down,
                    'why'        => $down ? (string) ($rows[$route['id']]['why'] ?? '') : '',
                    'until'      => $down ? (int) $rows[$route['id']]['until'] : 0,
                );
            }
        }
        return $out;
    }

    /* ---------------------------------------------------------------------
     * Вызовы
     * ------------------------------------------------------------------ */

    /**
     * Текст. Возвращает ответ первой ответившей модели.
     *
     * @return array{ok:bool,content:string,route:string,message:string}
     */
    public static function chat($system, $user, $opts = array()) {
        $last = 'ни один маршрут не ответил';
        foreach (self::pick('chat') as $route) {
            $payload = array(
                'model'    => $route['model'],
                'stream'   => false,
                'messages' => array(
                    array('role' => 'system', 'content' => (string) $system),
                    array('role' => 'user',   'content' => (string) $user),
                ),
            );
            $res = self::send(sprintf(self::CHAT_URL, $route['model']), $payload, (int) ($opts['timeout'] ?? 180));

            if ($res['ok']) {
                $content = (string) ($res['body']['choices'][0]['message']['content'] ?? '');
                if (trim($content) !== '') {
                    self::mark_up($route['id']);
                    return array('ok' => true, 'content' => $content, 'route' => $route['id'], 'message' => '');
                }
                $res['message'] = 'пустой ответ модели';
            }

            $last = $res['message'];
            if (!self::worth_switching($res['message'])) {
                // Отказ по сути запроса: у соседней модели будет то же самое.
                break;
            }
            self::mark_down($route['id'], $res['message']);
        }
        return array('ok' => false, 'content' => '', 'route' => '', 'message' => self::human($last));
    }

    /**
     * Задача в очередь (картинки, видео, звук).
     *
     * @return array{ok:bool,task:string,route:string,message:string}
     */
    public static function job($capability, $input, $opts = array()) {
        $last = 'ни один маршрут не ответил';
        foreach (self::pick($capability) as $route) {
            $shape = $route['shape'] ?? null;
            $payload = array(
                'model' => $route['model'],
                'input' => is_callable($shape) ? $shape($input) : $input,
            );
            if (!empty($opts['callback'])) {
                $payload['callBackUrl'] = (string) $opts['callback'];
            }

            $res = self::send(self::API_JOBS, $payload, (int) ($opts['timeout'] ?? 60));
            $task = $res['ok'] ? (string) ($res['body']['data']['taskId'] ?? '') : '';
            if ($task !== '') {
                self::mark_up($route['id']);
                return array('ok' => true, 'task' => $task, 'route' => $route['id'], 'message' => '');
            }

            $last = $res['message'] !== '' ? $res['message'] : 'поставщик не вернул задачу';
            if (!self::worth_switching($last)) {
                break;
            }
            self::mark_down($route['id'], $last);
        }
        return array('ok' => false, 'task' => '', 'route' => '', 'message' => self::human($last));
    }

    /**
     * Состояние задачи. Маршрут для опроса не важен — очередь общая,
     * но принимаем его, чтобы вызывающий не хранил лишнего знания.
     *
     * @return array{ok:bool,state:string,urls:array,message:string}
     */
    public static function job_state($task, $route_id = '') {
        $res = self::send(add_query_arg(array('taskId' => (string) $task), self::API_INFO), null, 45);
        if (!$res['ok']) {
            return array('ok' => false, 'state' => '', 'urls' => array(), 'message' => self::human($res['message']));
        }
        $data = (array) ($res['body']['data'] ?? array());
        return array(
            'ok'      => true,
            'state'   => (string) ($data['state'] ?? ''),
            'urls'    => self::result_urls($data),
            'message' => (string) ($data['failMsg'] ?? ''),
        );
    }

    public static function result_urls($data) {
        $raw = $data['resultJson'] ?? ($data['result'] ?? '');
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }
        if (!is_array($raw)) {
            return array();
        }
        $urls = $raw['resultUrls'] ?? ($raw['result_urls'] ?? ($raw['urls'] ?? array()));
        return array_values(array_filter((array) $urls, 'is_string'));
    }

    /* ---------------------------------------------------------------------
     * Внутреннее
     * ------------------------------------------------------------------ */

    /** Живые маршруты возможности; если полегли все — пробуем всё равно. */
    private static function pick($capability) {
        $all = self::routes()[$capability] ?? array();
        $live = array_values(array_filter($all, function ($route) {
            return !self::is_down($route['id']);
        }));
        return $live ? $live : $all;
    }

    private static function key() {
        return trim((string) get_option('kie_tts_api_key', ''));
    }

    private static function send($url, $payload, $timeout) {
        $key = self::key();
        if ($key === '') {
            return array('ok' => false, 'message' => 'не настроен доступ к сервису', 'body' => array());
        }
        $args = array(
            'timeout' => $timeout,
            'headers' => array('Authorization' => 'Bearer ' . $key, 'Content-Type' => 'application/json'),
        );

        if ($payload === null) {
            $response = wp_remote_get($url, $args);
        } else {
            $args['body'] = wp_json_encode($payload);
            $response = wp_remote_post($url, $args);
        }

        if (is_wp_error($response)) {
            return array('ok' => false, 'message' => $response->get_error_message(), 'body' => array());
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        $body = json_decode((string) wp_remote_retrieve_body($response), true);
        if (!is_array($body)) {
            return array('ok' => false, 'message' => 'неразборчивый ответ (HTTP ' . $code . ')', 'body' => array());
        }
        // Агрегатор отдаёт свой код внутри тела и при HTTP 200.
        $inner = isset($body['code']) ? (int) $body['code'] : 200;
        if ($inner !== 200 || $code >= 400) {
            $message = (string) ($body['msg'] ?? ($body['error']['message'] ?? ('HTTP ' . $code)));
            return array('ok' => false, 'message' => $message, 'body' => $body);
        }
        return array('ok' => true, 'message' => '', 'body' => $body);
    }

    /**
     * Стоит ли пробовать соседний маршрут.
     *
     * Переключаемся только на бедах поставщика. Если он отказал по сути
     * запроса — «файл слишком большой», «недопустимое значение», — соседняя
     * модель ответит так же, а мы потратим время и деньги на повтор.
     */
    private static function worth_switching($message) {
        $low = mb_strtolower((string) $message);
        foreach (array('network error', 'internal', 'try again', 'timeout', 'timed out',
                       'maintain', 'unavailable', 'overload', 'not supported', 'no user can use',
                       'gateway', 'busy', 'capacity', 'rate limit', 'too many',
                       'неразборчивый', 'не настроен') as $needle) {
            if (strpos($low, $needle) !== false) {
                return true;
            }
        }
        return false;
    }

    /** Ответ поставщика — человеку. */
    public static function human($raw) {
        $low = mb_strtolower((string) $raw);
        if (strpos($low, 'не настроен') !== false || strpos($low, 'unauthorized') !== false
            || strpos($low, 'api key') !== false) {
            return 'Генерация временно недоступна: не настроен доступ к сервису.';
        }
        if (strpos($low, 'rate limit') !== false || strpos($low, 'too many') !== false) {
            return 'Слишком много запросов подряд. Подождите минуту и попробуйте снова.';
        }
        if (strpos($low, 'insufficient') !== false || strpos($low, 'credit') !== false) {
            return 'На стороне поставщика закончились средства. Мы уже знаем об этом.';
        }
        if (self::worth_switching($low)) {
            return 'Сервис генерации сейчас недоступен на стороне поставщика. Деньги не списаны — попробуйте через несколько минут.';
        }
        return 'Не удалось выполнить запрос: ' . $raw;
    }
}
