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
        $account = self::bot_account($assistant, $user_id);

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
    private static function bot_account(array $assistant, int $user_id): array
    {
        $topup = $assistant['landing_url'] ?: home_url('/tts-dashboard/');
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
            $text = sprintf(
                "Аккаунт ещё не привязан. Гостю доступно %d сообщений в сутки.\n"
                . "Войдите на сайте через Telegram — баланс станет общим со всеми сервисами Genius, "
                . "и можно будет пополнять счёт.",
                GA_Billing::guest_free_limit());
        }
        return [
            'logged_in' => (bool) $user_id,
            'balance' => $balance,
            'balance_text' => $text,
            'topup_url' => $topup,
            'login_url' => home_url('/tts-login/'),
        ];
    }
}
