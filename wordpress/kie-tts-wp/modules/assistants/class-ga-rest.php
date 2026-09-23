<?php
/**
 * REST: сообщение из веб-виджета и приём обновлений телеграма.
 */

if (!defined('ABSPATH')) {
    exit;
}

class GA_Rest
{
    public static function register_routes(): void
    {
        register_rest_route(GA_REST_NS, '/message', [
            'methods' => 'POST',
            'permission_callback' => '__return_true',
            'callback' => [self::class, 'message'],
            'args' => [
                'slug' => ['required' => true, 'type' => 'string'],
                'text' => ['required' => true, 'type' => 'string'],
            ],
        ]);

        register_rest_route(GA_REST_NS, '/state', [
            'methods' => 'GET',
            'permission_callback' => '__return_true',
            'callback' => [self::class, 'state'],
        ]);

        register_rest_route(GA_REST_NS, '/telegram/(?P<id>\d+)', [
            'methods' => 'POST',
            'permission_callback' => '__return_true',
            'callback' => [self::class, 'telegram'],
        ]);

        // Server-to-server для отдельных телеграм-ботов (/opt/aihelpers).
        // Логика ответа и баланс — те же, что у веба; бот только рисует Telegram-UI.
        register_rest_route(GA_REST_NS, '/bot/reply', [
            'methods' => 'POST',
            'permission_callback' => [self::class, 'bot_auth'],
            'callback' => [self::class, 'bot_reply'],
        ]);

        // Форма техподдержки: вопрос из виджета → уведомление владельцу в Telegram.
        register_rest_route(GA_REST_NS, '/support', [
            'methods' => 'POST',
            'permission_callback' => static fn() => is_user_logged_in(),
            'callback' => [self::class, 'support'],
        ]);

        // Личный кабинет: база знаний клиента.
        register_rest_route(GA_REST_NS, '/kb', [
            [
                'methods' => 'GET',
                'permission_callback' => static fn() => is_user_logged_in(),
                'callback' => [self::class, 'kb_get'],
            ],
            [
                'methods' => 'POST',
                'permission_callback' => static fn() => is_user_logged_in(),
                'callback' => [self::class, 'kb_save'],
            ],
        ]);
        register_rest_route(GA_REST_NS, '/kb/extract', [
            'methods' => 'POST',
            'permission_callback' => static fn() => is_user_logged_in(),
            'callback' => [self::class, 'kb_extract'],
        ]);
    }

    // ------------------------------------------------------------------ база знаний

    public static function kb_get(WP_REST_Request $request)
    {
        $uid = get_current_user_id();
        $kb = GA_KB::get($uid);
        return [
            'ok' => true,
            'can_manage' => GA_Billing::can_manage_kb($uid),
            'company' => $kb['company'],
            'content' => $kb['content'],
            'max' => GA_KB::MAX,
            'balance' => GA_Billing::balance($uid),
        ];
    }

    public static function kb_save(WP_REST_Request $request)
    {
        $uid = get_current_user_id();
        if (!GA_Billing::can_manage_kb($uid)) {
            return new WP_REST_Response(['ok' => false,
                'error' => 'База знаний доступна после пополнения баланса.'], 403);
        }
        GA_KB::save($uid, (string) $request->get_param('company'),
            (string) $request->get_param('content'));
        return ['ok' => true, 'message' => 'База знаний сохранена.',
                'balance' => GA_Billing::balance($uid)];
    }

    public static function kb_extract(WP_REST_Request $request)
    {
        $uid = get_current_user_id();
        if (!GA_Billing::can_manage_kb($uid)) {
            return new WP_REST_Response(['ok' => false,
                'error' => 'Доступно после пополнения баланса.'], 403);
        }
        $file = $request->get_param('file');
        if (!is_array($file) || empty($file['data'])) {
            return new WP_REST_Response(['ok' => false, 'error' => 'Файл не получен.'], 400);
        }
        $raw = base64_decode((string) $file['data'], true);
        if ($raw === false || $raw === '') {
            return new WP_REST_Response(['ok' => false, 'error' => 'Файл не удалось прочитать.'], 400);
        }
        if (strlen($raw) > 8 * 1024 * 1024) {
            return new WP_REST_Response(['ok' => false, 'error' => 'Файл больше 8 МБ.'], 400);
        }
        $text = GA_Chat::extract_upload($raw,
            sanitize_text_field((string) ($file['mime'] ?? '')),
            sanitize_file_name((string) ($file['name'] ?? 'file')));
        if ($text === null) {
            return new WP_REST_Response(['ok' => false,
                'error' => 'Формат не поддерживается: пришлите txt или docx.'], 400);
        }
        return ['ok' => true, 'text' => mb_substr($text, 0, GA_KB::MAX)];
    }

    /** Общий секрет ботов. Генерируется один раз, показывается в настройках. */
    public static function bot_secret(): string
    {
        $secret = (string) get_option('ga_bot_secret', '');
        if ($secret === '') {
            $secret = wp_generate_password(48, false, false);
            update_option('ga_bot_secret', $secret, false);
        }
        return $secret;
    }

    public static function bot_auth(WP_REST_Request $request): bool
    {
        $given = (string) $request->get_header('x_ga_bot_secret');
        $secret = self::bot_secret();
        return $secret !== '' && $given !== '' && hash_equals($secret, $given);
    }

    /** Гость опознаётся по подписанной куке — иначе лимит обходится очисткой localStorage. */
    private static function visitor_id(): string
    {
        $cookie = 'ga_visitor';
        if (!empty($_COOKIE[$cookie]) && preg_match('/^[a-f0-9]{32}$/', $_COOKIE[$cookie])) {
            return $_COOKIE[$cookie];
        }
        $id = md5(wp_generate_password(32, false, false) . microtime(true));
        setcookie($cookie, $id, [
            'expires' => time() + YEAR_IN_SECONDS,
            'path' => '/',
            'secure' => is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        $_COOKIE[$cookie] = $id;
        return $id;
    }

    public static function state(WP_REST_Request $request)
    {
        $user_id = get_current_user_id();
        $assistant = GA_Store::assistant_by_slug((string) $request->get_param('slug'));
        return [
            'logged_in' => (bool) $user_id,
            'balance' => $user_id ? GA_Billing::balance($user_id) : null,
            'price' => GA_Billing::price_per_message(),
            'free_daily' => $assistant
                ? ($user_id ? (int) $assistant['free_daily_limit'] : GA_Billing::guest_free_limit())
                : GA_Billing::guest_free_limit(),
            'can_upload' => GA_Billing::can_upload($user_id),
            'price_file' => GA_Billing::price_per_file(),
        ];
    }

    public static function message(WP_REST_Request $request)
    {
        $slug = sanitize_key((string) $request->get_param('slug'));
        $assistant = GA_Store::assistant_by_slug($slug);
        if (!$assistant || !$assistant['is_active'] || !$assistant['web_enabled']) {
            return new WP_Error('ga_not_found', 'Ассистент не найден', ['status' => 404]);
        }

        $user_id = get_current_user_id();
        $external = $user_id ? 'u' . $user_id : 'g' . self::visitor_id();

        $file = $request->get_param('file');
        if (is_array($file) && !empty($file['data'])) {
            $result = GA_Chat::ask_file(
                $assistant, $external, (string) $request->get_param('text'),
                [
                    'data' => (string) $file['data'],
                    'mime' => sanitize_text_field((string) ($file['mime'] ?? '')),
                    'name' => sanitize_file_name((string) ($file['name'] ?? 'file')),
                ],
                $user_id
            );
            if (!$result['ok']) {
                $status = ($result['code'] ?? '') === 'model' ? 502 : 400;
                return new WP_REST_Response([
                    'ok' => false, 'code' => $result['code'] ?? 'error', 'error' => $result['error'],
                    'login_url' => home_url('/tts-login/'),
                ], $status);
            }
            return ['ok' => true, 'reply' => $result['reply'], 'charged' => $result['charged'],
                    'balance' => $result['balance']];
        }

        $result = GA_Chat::ask(
            $assistant, 'web', $external,
            (string) $request->get_param('text'), $user_id,
            [
                'source' => sanitize_text_field((string) $request->get_param('source')),
                'ip_hash' => $user_id ? '' : GA_Chat::client_ip_hash(),
            ]
        );

        if (!$result['ok']) {
            $status = ($result['code'] ?? '') === 'model' ? 502 : 400;
            return new WP_REST_Response([
                'ok' => false,
                'code' => $result['code'] ?? 'error',
                'error' => $result['error'],
                'login_url' => home_url('/tts-login/'),
            ], $status);
        }

        return [
            'ok' => true,
            'reply' => $result['reply'],
            'charged' => $result['charged'],
            'left_today' => $result['left'],
            'balance' => $user_id ? GA_Billing::balance($user_id) : null,
        ];
    }

    public static function telegram(WP_REST_Request $request)
    {
        $row = GA_Store::token((int) $request->get_param('id'));
        if (!$row || !$row['is_active']) {
            return new WP_REST_Response(['ok' => true], 200);
        }
        $secret = $request->get_header('x_telegram_bot_api_secret_token');
        if (!hash_equals((string) $row['secret'], (string) $secret)) {
            return new WP_REST_Response(['ok' => true], 200);
        }
        $update = json_decode($request->get_body(), true);
        if (is_array($update)) {
            // Телеграм ждёт ответ быстро и повторяет апдейт при таймауте;
            // ошибку модели гасим здесь, чтобы не поймать дубль сообщения.
            try {
                GA_Telegram::handle_update($row, $update);
            } catch (Throwable $e) {
                GA_Store::update_token((int) $row['id'], [
                    'last_error' => mb_substr($e->getMessage(), 0, 500),
                    'checked_at' => current_time('mysql'),
                ]);
            }
        }
        return new WP_REST_Response(['ok' => true], 200);
    }

    // ------------------------------------------------------------------ отдельные боты

    /**
     * Единая точка для standalone-ботов. Бот присылает slug ассистента, telegram-id
     * собеседника и намерение (intent); в ответ получает готовый текст и данные для
     * кнопок. Пользователь опознаётся по telegram_id — баланс общий с сайтом.
     */
    public static function bot_reply(WP_REST_Request $request)
    {
        $slug = sanitize_key((string) $request->get_param('slug'));
        $assistant = GA_Store::assistant_by_slug($slug);
        if (!$assistant || !$assistant['is_active'] || !$assistant['tg_enabled']) {
            return new WP_REST_Response(
                ['ok' => false, 'code' => 'not_found', 'error' => 'Ассистент недоступен.'], 404);
        }
        $tg_id = (int) $request->get_param('tg_id');
        if (!$tg_id) {
            return new WP_REST_Response(
                ['ok' => false, 'code' => 'bad_request', 'error' => 'Не указан tg_id.'], 400);
        }

        $intent = sanitize_key((string) ($request->get_param('intent') ?: 'chat'));
        $text = (string) $request->get_param('text');
        $user_id = GA_Billing::user_by_telegram($tg_id);
        $external = (string) $tg_id;
        $account = self::bot_account($assistant, $user_id, $tg_id);

        switch ($intent) {
            case 'start':
                GA_Store::thread((int) $assistant['id'], 'tg', $external, [
                    'user_id' => $user_id,
                    'tg_user_id' => $tg_id,
                    'source' => sanitize_text_field((string) $request->get_param('source')),
                ]);
                return array_merge([
                    'ok' => true,
                    'reply' => (string) $assistant['welcome'],
                    'actions' => self::bot_actions($assistant),
                ], $account);

            case 'menu':
                return array_merge([
                    'ok' => true, 'reply' => 'Выберите сценарий:',
                    'actions' => self::bot_actions($assistant),
                ], $account);

            case 'balance':
                return array_merge(['ok' => true, 'reply' => $account['balance_text']], $account);

            case 'reset':
                global $wpdb;
                $thread = GA_Store::thread((int) $assistant['id'], 'tg', $external);
                $wpdb->delete(GA_Store::t('messages'), ['thread_id' => (int) $thread['id']]);
                return array_merge(['ok' => true, 'reply' => 'Диалог забыт. Начнём заново.'], $account);

            case 'action':
                $index = (int) $request->get_param('action');
                $actions = GA_Store::actions($assistant);
                if (isset($actions[$index])) {
                    return ['ok' => true,
                        'reply' => "Шаблон — дополните и отправьте:\n\n" . $actions[$index]['prompt']];
                }
                return ['ok' => true, 'reply' => 'Такого сценария нет. Наберите /menu.'];

            default: // chat
                if (trim($text) === '') {
                    return array_merge(['ok' => true,
                        'reply' => 'Пришлите текст задачи — разберу. /menu — готовые сценарии.'], $account);
                }
                $result = GA_Chat::ask($assistant, 'tg', $external, $text, $user_id,
                    ['tg_user_id' => $tg_id]);
                if (!$result['ok']) {
                    $need = in_array($result['code'] ?? '', ['auth_required', 'no_funds'], true);
                    return array_merge([
                        'ok' => false, 'code' => $result['code'] ?? 'error',
                        'error' => $result['error'], 'need_topup' => $need,
                    ], $account);
                }
                return array_merge([
                    'ok' => true, 'reply' => $result['reply'], 'charged' => $result['charged'],
                    'balance' => $user_id ? GA_Billing::balance($user_id) : null,
                ], $account);
        }
    }

    // ------------------------------------------------------------------ техподдержка

    /**
     * Вопрос из формы поддержки. Летит владельцу в Telegram через уведомительный бот;
     * если бот не настроен или не ответил — на почту администратора. Простой антиспам:
     * не больше 5 обращений в час на пользователя.
     */
    public static function support(WP_REST_Request $request)
    {
        $user = wp_get_current_user();
        $uid = (int) $user->ID;

        $rl_key = 'ga_support_rl_' . $uid;
        $count = (int) get_transient($rl_key);
        if ($count >= 5) {
            return new WP_REST_Response(['ok' => false,
                'error' => 'Слишком много обращений подряд. Попробуйте через час.'], 429);
        }

        $message = trim((string) $request->get_param('message'));
        if (mb_strlen($message) < 5) {
            return new WP_REST_Response(['ok' => false,
                'error' => 'Опишите вопрос подробнее — хотя бы пару предложений.'], 400);
        }
        $message = mb_substr(sanitize_textarea_field($message), 0, 2000);
        $contact = mb_substr(sanitize_text_field((string) $request->get_param('contact')), 0, 120);
        $slug = sanitize_key((string) $request->get_param('slug'));
        $assistant = GA_Store::assistant_by_slug($slug);
        $page = esc_url_raw((string) $request->get_param('page'));

        $name = $user->display_name ?: $user->user_login;
        $tg_meta = get_user_meta($uid, 'telegram_id', true);

        $text = "🆘 Вопрос в поддержку Genius\n"
            . 'Помощник: ' . ($assistant ? $assistant['name'] : '—') . "\n"
            . 'Пользователь: ' . $name . ' (id ' . $uid . ")\n"
            . 'E-mail: ' . $user->user_email . "\n"
            . ($tg_meta ? 'Telegram id: ' . $tg_meta . "\n" : '')
            . ($contact ? 'Контакт для ответа: ' . $contact . "\n" : '')
            . ($page ? 'Страница: ' . $page . "\n" : '')
            . "\n" . $message;

        $delivered = self::support_notify($text);
        if (!$delivered) {
            $admin = get_option('admin_email');
            $delivered = (bool) wp_mail($admin, 'Вопрос в поддержку Genius', $text);
            if (!$delivered) {
                return new WP_REST_Response(['ok' => false,
                    'error' => 'Не удалось отправить сообщение. Напишите нам на ' . $admin], 502);
            }
        }

        set_transient($rl_key, $count + 1, HOUR_IN_SECONDS);
        return ['ok' => true, 'message' => 'Спасибо! Вопрос отправлен — ответим в ближайшее время.'];
    }

    /** Отправка уведомления владельцу через настроенный бот. false — если не настроен/не дошло. */
    private static function support_notify(string $text): bool
    {
        $token = trim((string) get_option('ga_support_bot_token', ''));
        $chat = trim((string) get_option('ga_support_chat_id', ''));
        if ($token === '' || $chat === '') {
            return false;
        }
        $result = GA_Telegram::api($token, 'sendMessage', [
            'chat_id' => $chat,
            'text' => $text,
            'disable_web_page_preview' => 'true',
        ]);
        return !is_wp_error($result);
    }

    /** Сценарии-кнопки для инлайн-клавиатуры бота (label + индекс для callback_data). */
    private static function bot_actions(array $assistant): array
    {
        $out = [];
        foreach (array_values(GA_Store::actions($assistant)) as $i => $act) {
            $out[] = ['label' => $act['label'], 'index' => $i];
        }
        return $out;
    }

    /** Баланс, текст о балансе и ссылки для кнопок бота. Пополнение — редирект на сайт. */
    private static function bot_account(array $assistant, int $user_id, int $tg_id = 0): array
    {
        // Кнопки Telegram требуют абсолютный URL. Ведём через переходник /perehod/,
        // чтобы после входа человек попадал именно на нужный сервис (не на дашборд).
        $service = $assistant['landing_url'] ?: '/tts-dashboard/';
        $topup = add_query_arg('to', $service, home_url('/perehod/'));
        $bind = null;
        if ($user_id) {
            $balance = GA_Billing::balance($user_id);
            $text = sprintf(
                "💰 Баланс: %s ₽ — общий со всеми сервисами Genius.\n"
                . "Бесплатно в сутки: %d сообщений, дальше %s ₽ за сообщение.",
                number_format_i18n($balance, 2),
                (int) $assistant['free_daily_limit'],
                number_format_i18n(GA_Billing::price_per_message(), 0));
        } else {
            $balance = null;
            $bind = $tg_id ? GA_Billing::bind_url($tg_id, $service) : null;
            $text = sprintf(
                "Аккаунт ещё не привязан — сейчас работает гостевой лимит (%d сообщений в сутки).\n"
                . "Нажмите «Привязать аккаунт», войдите на сайте — и баланс станет общим со всеми "
                . "сервисами Genius, появится пополнение счёта.",
                GA_Billing::guest_free_limit());
        }
        return [
            'logged_in' => (bool) $user_id,
            'balance' => $balance,
            'balance_text' => $text,
            'topup_url' => $topup,
            'login_url' => home_url('/tts-login/'),
            'bind_url' => $bind,
        ];
    }
}
