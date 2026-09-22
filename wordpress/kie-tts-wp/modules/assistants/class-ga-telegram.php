<?php
/**
 * Телеграм-боты ассистентов. Работаем на вебхуках: отдельный демон не нужен,
 * хостинг сайта и так принимает запросы.
 */

if (!defined('ABSPATH')) {
    exit;
}

class GA_Telegram
{
    public static function api(string $token, string $method, array $params = [])
    {
        $response = wp_remote_post("https://api.telegram.org/bot$token/$method", [
            'timeout' => 30,
            'body' => $params,
        ]);
        if (is_wp_error($response)) {
            return $response;
        }
        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($body) || empty($body['ok'])) {
            return new WP_Error('ga_tg', (string) ($body['description'] ?? 'Telegram не ответил'));
        }
        return $body['result'];
    }

    public static function webhook_url(array $token_row): string
    {
        return rest_url(GA_REST_NS . '/telegram/' . (int) $token_row['id']);
    }

    /**
     * Проверяет токен, запоминает имя бота, ставит вебхук и меню команд.
     * Секрет уходит в заголовок X-Telegram-Bot-Api-Secret-Token — чужой POST не пройдёт.
     */
    public static function connect(array $token_row): array
    {
        $token = (string) $token_row['token'];
        $me = self::api($token, 'getMe');
        if (is_wp_error($me)) {
            GA_Store::update_token((int) $token_row['id'], [
                'status' => 'error',
                'last_error' => $me->get_error_message(),
                'checked_at' => current_time('mysql'),
            ]);
            return ['ok' => false, 'error' => $me->get_error_message()];
        }

        $hook = self::api($token, 'setWebhook', [
            'url' => self::webhook_url($token_row),
            'secret_token' => (string) $token_row['secret'],
            'allowed_updates' => wp_json_encode(['message', 'callback_query']),
            'drop_pending_updates' => 'true',
        ]);
        if (is_wp_error($hook)) {
            GA_Store::update_token((int) $token_row['id'], [
                'status' => 'error',
                'last_error' => $hook->get_error_message(),
                'checked_at' => current_time('mysql'),
            ]);
            return ['ok' => false, 'error' => $hook->get_error_message()];
        }

        $assistant = GA_Store::assistant((int) $token_row['assistant_id']);
        self::api($token, 'setMyCommands', ['commands' => wp_json_encode([
            ['command' => 'start', 'description' => 'Начать заново'],
            ['command' => 'menu', 'description' => 'Сценарии'],
            ['command' => 'reset', 'description' => 'Забыть диалог'],
            ['command' => 'balance', 'description' => 'Баланс и лимиты'],
        ], JSON_UNESCAPED_UNICODE)]);
        if ($assistant) {
            self::api($token, 'setMyDescription', [
                'description' => mb_substr((string) $assistant['tagline'], 0, 512),
            ]);
        }

        GA_Store::update_token((int) $token_row['id'], [
            'bot_username' => (string) ($me['username'] ?? ''),
            'bot_title' => (string) ($me['first_name'] ?? ''),
            'status' => 'connected',
            'last_error' => '',
            'checked_at' => current_time('mysql'),
        ]);
        return ['ok' => true, 'username' => (string) ($me['username'] ?? '')];
    }

    public static function disconnect(array $token_row): void
    {
        self::api((string) $token_row['token'], 'deleteWebhook', ['drop_pending_updates' => 'true']);
        GA_Store::update_token((int) $token_row['id'], [
            'status' => 'stopped', 'checked_at' => current_time('mysql'),
        ]);
    }

    // ------------------------------------------------------------------ приём обновлений

    public static function handle_update(array $token_row, array $update): void
    {
        $assistant = GA_Store::assistant((int) $token_row['assistant_id']);
        if (!$assistant || !$assistant['is_active'] || !$assistant['tg_enabled']) {
            return;
        }
        $token = (string) $token_row['token'];

        if (isset($update['callback_query'])) {
            $cq = $update['callback_query'];
            self::api($token, 'answerCallbackQuery', ['callback_query_id' => $cq['id']]);
            $chat_id = (int) ($cq['message']['chat']['id'] ?? 0);
            $payload = (string) ($cq['data'] ?? '');
            if (str_starts_with($payload, 'act:')) {
                $index = (int) substr($payload, 4);
                $actions = GA_Store::actions($assistant);
                if (isset($actions[$index])) {
                    self::send($token, $chat_id, "Шаблон — дополните и отправьте:\n\n"
                        . $actions[$index]['prompt']);
                }
            }
            return;
        }

        $message = $update['message'] ?? null;
        if (!$message) {
            return;
        }
        $chat_id = (int) ($message['chat']['id'] ?? 0);
        $tg_user = (int) ($message['from']['id'] ?? 0);
        $text = trim((string) ($message['text'] ?? ''));
        if (!$chat_id) {
            return;
        }
        if ($text === '' && isset($message['photo'])) {
            self::send($token, $chat_id,
                'Пока я читаю только текст. Перепишите условие текстом — разберу.');
            return;
        }

        $user_id = GA_Billing::user_by_telegram($tg_user);

        if (str_starts_with($text, '/start')) {
            $source = trim(substr($text, 6));
            GA_Store::thread((int) $assistant['id'], 'tg', (string) $chat_id, [
                'user_id' => $user_id, 'tg_user_id' => $tg_user, 'source' => $source,
            ]);
            self::send($token, $chat_id, (string) $assistant['welcome'],
                self::keyboard($assistant));
            return;
        }
        if ($text === '/menu') {
            self::send($token, $chat_id, 'Выберите сценарий:', self::keyboard($assistant));
            return;
        }
        if ($text === '/reset') {
            global $wpdb;
            $thread = GA_Store::thread((int) $assistant['id'], 'tg', (string) $chat_id);
            $wpdb->delete(GA_Store::t('messages'), ['thread_id' => (int) $thread['id']]);
            self::send($token, $chat_id, 'Диалог забыт. Начнём заново.');
            return;
        }
        if ($text === '/balance') {
            self::send($token, $chat_id, self::balance_text($assistant, $user_id));
            return;
        }
        if ($text === '') {
            return;
        }

        self::api($token, 'sendChatAction', ['chat_id' => $chat_id, 'action' => 'typing']);
        $result = GA_Chat::ask($assistant, 'tg', (string) $chat_id, $text, $user_id, [
            'tg_user_id' => $tg_user,
        ]);
        if (!$result['ok']) {
            $hint = '';
            if (in_array($result['code'] ?? '', ['auth_required', 'no_funds'], true)) {
                $hint = "\n\n" . home_url('/tts-login/');
            }
            self::send($token, $chat_id, $result['error'] . $hint);
            return;
        }
        self::send($token, $chat_id, $result['reply']);
    }

    private static function balance_text(array $assistant, int $user_id): string
    {
        if (!$user_id) {
            return sprintf(
                "Аккаунт не привязан. Гостю доступно %d сообщений в сутки.\n\n"
                . "Войдите на сайте — баланс общий со всеми сервисами Genius: %s",
                GA_Billing::guest_free_limit(), home_url('/tts-login/'));
        }
        return sprintf(
            "Баланс: %s ₽ — он общий с озвучкой, презентациями и остальными сервисами.\n"
            . "Бесплатно в сутки: %d сообщений, дальше %s ₽ за сообщение.",
            number_format_i18n(GA_Billing::balance($user_id), 2),
            (int) $assistant['free_daily_limit'],
            number_format_i18n(GA_Billing::price_per_message(), 0));
    }

    private static function keyboard(array $assistant): ?array
    {
        $actions = GA_Store::actions($assistant);
        if (!$actions) {
            return null;
        }
        $rows = [];
        foreach (array_values($actions) as $i => $action) {
            $rows[] = [['text' => $action['label'], 'callback_data' => 'act:' . $i]];
        }
        return ['inline_keyboard' => $rows];
    }

    /** Телеграм рвёт сообщения длиннее 4096 символов — режем по абзацам. */
    public static function send(string $token, int $chat_id, string $text, ?array $markup = null): void
    {
        foreach (self::split($text) as $i => $chunk) {
            $params = ['chat_id' => $chat_id, 'text' => $chunk, 'disable_web_page_preview' => 'true'];
            if ($markup && $i === 0) {
                $params['reply_markup'] = wp_json_encode($markup, JSON_UNESCAPED_UNICODE);
            }
            self::api($token, 'sendMessage', $params);
        }
    }

    private static function split(string $text, int $limit = 3900): array
    {
        if (mb_strlen($text) <= $limit) {
            return [$text];
        }
        $chunks = [];
        $current = '';
        foreach (preg_split('/(\n\n)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE) as $piece) {
            if (mb_strlen($current . $piece) > $limit && $current !== '') {
                $chunks[] = rtrim($current);
                $current = '';
            }
            while (mb_strlen($piece) > $limit) {
                $chunks[] = mb_substr($piece, 0, $limit);
                $piece = mb_substr($piece, $limit);
            }
            $current .= $piece;
        }
        if (trim($current) !== '') {
            $chunks[] = rtrim($current);
        }
        return $chunks;
    }
}
