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

        $result = GA_Chat::ask(
            $assistant, 'web', $external,
            (string) $request->get_param('text'), $user_id,
            ['source' => sanitize_text_field((string) $request->get_param('source'))]
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
}
