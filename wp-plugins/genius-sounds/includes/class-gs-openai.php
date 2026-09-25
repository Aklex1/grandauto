<?php
/**
 * Маршрут в формате OpenAI.
 *
 * Разработчик выбирает шлюз не по возможностям, а по стоимости перехода.
 * Если у нас свой формат запроса, ему надо переписывать код — и он не
 * станет. Если формат тот же, что у OpenAI, переход стоит одну строку:
 *
 *   client = OpenAI(api_key="gb_…", base_url="https://genius-bot.ru/wp-json/genius/v1")
 *
 * Поэтому адреса и тела запросов здесь повторяют чужие, а внутри работает
 * наш адаптер с запасными моделями: клиент об этом не знает и знать не
 * должен.
 *
 * Цену считаем по счётчику токенов от поставщика, а не на глаз: он его
 * отдаёт. Тариф — за миллион токенов, как принято у шлюзов, и такой,
 * чтобы оставаться в прибыли даже когда работает запасной маршрут: у него
 * вывод стоит впятеро дороже основного.
 */

if (!defined('ABSPATH')) {
    exit;
}

class GS_OpenAI {

    /**
     * Тариф: рублей за миллион токенов.
     *
     * Прежние 40 и 400 стояли почти вплотную к себестоимости: миллион
     * токенов запроса обходится нам в 37 ₽, ответа — в 294 ₽. На входе
     * оставалось 8%, на длинном разборе договора — 16%, то есть чат
     * работал за бензин. Втрое выше — запас, который переживёт и скачок
     * курса, и подорожание у поставщика.
     */
    const OPT_IN      = 'gs_api_chat_in';
    const OPT_OUT     = 'gs_api_chat_out';
    const IN_DEFAULT  = 120;
    const OUT_DEFAULT = 1200;

    /**
     * Наше имя модели. Принимаем любое, но в ответе честно говорим, чем
     * считали: клиент мог прислать «gpt-4o», а работал у нас другой.
     */
    const MODEL = 'genius-chat';

    /** Защита от абсурдного запроса: столько знаков переписки принимаем. */
    const MAX_CHARS = 60000;

    /** Меньше этой суммы на балансе не начинаем: цену узнаём только после. */
    const MIN_BALANCE = 1.0;

    public static function boot() {
        add_action('rest_api_init', array(__CLASS__, 'register_routes'));
    }

    public static function register_routes() {
        $auth = array('GS_Api', 'check_key');

        register_rest_route(GS_Api::NS, '/chat/completions', array(
            'methods'             => 'POST',
            'callback'            => array(__CLASS__, 'handle_chat'),
            'permission_callback' => $auth,
        ));
        register_rest_route(GS_Api::NS, '/models', array(
            'methods'             => 'GET',
            'callback'            => array(__CLASS__, 'handle_models'),
            'permission_callback' => $auth,
        ));
    }

    /* ---------------------------------------------------------------------
     * Тариф
     * ------------------------------------------------------------------ */

    public static function rate($which) {
        $option = $which === 'in' ? self::OPT_IN : self::OPT_OUT;
        $default = $which === 'in' ? self::IN_DEFAULT : self::OUT_DEFAULT;
        $value = get_option($option, null);
        if ($value === null || $value === '') {
            return (float) $default;
        }
        return max(0.0, (float) $value);
    }

    public static function price_hint() {
        return sprintf(
            '%s ₽ за млн токенов запроса и %s ₽ за млн токенов ответа',
            number_format_i18n(self::rate('in'), 0),
            number_format_i18n(self::rate('out'), 0)
        );
    }

    private static function price($in_tokens, $out_tokens) {
        $sum = $in_tokens / 1000000 * self::rate('in')
             + $out_tokens / 1000000 * self::rate('out');
        // Округляем вверх до копейки: доли копейки на балансе не живут,
        // а отдавать ответы даром из-за округления вниз незачем.
        return ceil($sum * 100) / 100;
    }

    /**
     * Токены, когда поставщик их не назвал.
     *
     * Русский текст даёт примерно три знака на токен. Считаем именно так,
     * а не «по словам»: занижать оценку — значит работать в убыток.
     */
    private static function guess_tokens($text) {
        return (int) ceil(mb_strlen((string) $text) / 3);
    }

    /* ---------------------------------------------------------------------
     * Маршруты
     * ------------------------------------------------------------------ */

    public static function handle_models($request) {
        return rest_ensure_response(array(
            'object' => 'list',
            'data'   => array(
                array(
                    'id'       => self::MODEL,
                    'object'   => 'model',
                    'created'  => 1756684800,
                    'owned_by' => 'genius-bot',
                ),
            ),
        ));
    }

    public static function handle_chat($request) {
        $params = $request->get_json_params();
        if (!is_array($params)) {
            return self::fail('invalid_request_error', 'Тело запроса должно быть объектом JSON', 400);
        }
        if (!empty($params['stream'])) {
            // Потоковую отдачу не поддерживаем, и врать об этом нельзя:
            // клиент будет ждать событий, которых не будет.
            return self::fail('invalid_request_error',
                'Потоковая отдача пока не поддерживается: передайте stream=false', 400);
        }

        $messages = self::clean_messages($params['messages'] ?? null);
        if ($messages instanceof WP_REST_Response) {
            return $messages;
        }

        $user_id = (int) GS_Api::caller_user_id();
        $balance = class_exists('GS_SFX') ? (float) GS_SFX::get_balance($user_id) : 0.0;
        if ($balance < self::MIN_BALANCE) {
            return self::fail('insufficient_quota',
                sprintf('Недостаточно средств: на балансе %.2f ₽. Пополните баланс на genius-bot.ru', $balance), 402);
        }

        // Потолок ответа: столько токенов вывода покрывает баланс. Без него
        // один запрос может уйти в минус, а списать больше баланса нельзя.
        $out_rate = self::rate('out');
        $affordable = $out_rate > 0 ? (int) floor($balance / $out_rate * 1000000) : 0;
        $max_tokens = isset($params['max_tokens']) ? (int) $params['max_tokens'] : 0;
        if ($affordable > 0 && ($max_tokens <= 0 || $max_tokens > $affordable)) {
            $max_tokens = $affordable;
        }

        $res = GS_Provider::chat_messages($messages, array(
            'temperature' => isset($params['temperature']) ? (float) $params['temperature'] : null,
            'top_p'       => isset($params['top_p']) ? (float) $params['top_p'] : null,
            'max_tokens'  => $max_tokens > 0 ? $max_tokens : null,
        ));

        if (empty($res['ok'])) {
            return self::fail('upstream_error', (string) $res['message'], 502);
        }

        $usage = is_array($res['usage']) ? $res['usage'] : array();
        $in_tokens  = (int) ($usage['prompt_tokens'] ?? 0);
        $out_tokens = (int) ($usage['completion_tokens'] ?? 0);
        if ($in_tokens <= 0) {
            $in_tokens = self::guess_tokens(self::messages_text($messages));
        }
        if ($out_tokens <= 0) {
            $out_tokens = self::guess_tokens($res['content']);
        }

        $cost = self::price($in_tokens, $out_tokens);
        if ($cost > 0 && class_exists('GS_SFX')) {
            // Списываем после ответа: заранее цена неизвестна. Если списать
            // не удалось, ответ всё равно отдаём — он уже стоил нам денег у
            // поставщика, и отнимать его у человека вторично незачем.
            GS_SFX::charge($user_id, min($cost, $balance), 'api');
        }
        if (class_exists('KIE_TTS_DB')) {
            $is_telegram = class_exists('KIE_TTS_Auth') && KIE_TTS_Auth::is_telegram_user($user_id);
            KIE_TTS_DB::save_generation($user_id, 'chat-' . wp_generate_password(12, false, false),
                'API: чат', 'api:chat', $cost, $is_telegram);
        }

        $requested = isset($params['model']) ? sanitize_text_field((string) $params['model']) : '';

        return rest_ensure_response(array(
            'id'      => 'chatcmpl-' . wp_generate_password(24, false, false),
            'object'  => 'chat.completion',
            'created' => time(),
            'model'   => $requested !== '' ? $requested : self::MODEL,
            'choices' => array(array(
                'index'         => 0,
                'message'       => array('role' => 'assistant', 'content' => (string) $res['content']),
                'finish_reason' => 'stop',
            )),
            'usage'   => array(
                'prompt_tokens'     => $in_tokens,
                'completion_tokens' => $out_tokens,
                'total_tokens'      => $in_tokens + $out_tokens,
            ),
            // Своё, поверх чужого формата: сколько списали и каким
            // маршрутом считали. Клиентам OpenAI лишние поля не мешают.
            'genius'  => array(
                'cost'    => $cost,
                'balance' => class_exists('GS_SFX') ? GS_SFX::get_balance($user_id) : 0,
                'route'   => (string) $res['route'],
            ),
        ));
    }

    /* ---------------------------------------------------------------------
     * Внутреннее
     * ------------------------------------------------------------------ */

    private static function clean_messages($raw) {
        if (!is_array($raw) || !$raw) {
            return self::fail('invalid_request_error', 'Нужен непустой массив messages', 400);
        }
        $out = array();
        foreach ($raw as $row) {
            if (!is_array($row)) {
                continue;
            }
            $role = isset($row['role']) ? (string) $row['role'] : 'user';
            if (!in_array($role, array('system', 'user', 'assistant'), true)) {
                $role = 'user';
            }
            // Содержимое бывает массивом частей — берём из них текст.
            $content = $row['content'] ?? '';
            if (is_array($content)) {
                $parts = array();
                foreach ($content as $part) {
                    if (is_array($part) && isset($part['text'])) {
                        $parts[] = (string) $part['text'];
                    } elseif (is_string($part)) {
                        $parts[] = $part;
                    }
                }
                $content = implode("\n", $parts);
            }
            $content = trim((string) $content);
            if ($content === '') {
                continue;
            }
            $out[] = array('role' => $role, 'content' => $content);
        }
        if (!$out) {
            return self::fail('invalid_request_error', 'В messages нет ни одного непустого сообщения', 400);
        }
        if (mb_strlen(self::messages_text($out)) > self::MAX_CHARS) {
            return self::fail('invalid_request_error',
                sprintf('Переписка длиннее %d знаков — сократите её', self::MAX_CHARS), 400);
        }
        return $out;
    }

    private static function messages_text($messages) {
        $text = '';
        foreach ((array) $messages as $row) {
            $text .= (string) ($row['content'] ?? '');
        }
        return $text;
    }

    /**
     * Ошибка в том же виде, что у OpenAI.
     *
     * Клиентские библиотеки ищут error.message на верхнем уровне тела.
     * WP_Error отдал бы свою обёртку {code, message, data}, и разбор у
     * клиента сломался бы — а вся ценность этого маршрута в том, что у
     * него ничего не надо менять. Поэтому отвечаем своим ответом.
     */
    private static function fail($type, $message, $status) {
        return new WP_REST_Response(array(
            'error' => array(
                'message' => (string) $message,
                'type'    => (string) $type,
                'param'   => null,
                'code'    => (string) $type,
            ),
        ), (int) $status);
    }
}
