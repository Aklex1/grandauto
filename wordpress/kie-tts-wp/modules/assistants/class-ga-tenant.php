<?php
/**
 * Коробка: у оплатившего клиента (тенанта) — свой телеграм-бот, своя база знаний
 * и брендинг. Консультant отвечает ЕГО клиентам по ЕГО базе, а списывается всё
 * с баланса самого клиента (владельца коробки).
 *
 * Бот тенанта работает на вебхуке (ставится при подключении токена), поэтому
 * отдельный демон не нужен — их может быть сколько угодно. Диалоги изолированы
 * по тенанту. Модель, температура и правила формата берутся у базового ассистента
 * (по умолчанию «бизнес»), поверх — брендинг и база тенанта.
 */

if (!defined('ABSPATH')) {
    exit;
}

class GA_Tenant
{
    private static function table(): string
    {
        return GA_Store::t('tenants');
    }

    // ------------------------------------------------------------------ выборки

    public static function get(int $id): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id), ARRAY_A);
        return $row ?: null;
    }

    public static function by_owner(int $user_id): ?array
    {
        global $wpdb;
        if (!$user_id) {
            return null;
        }
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . self::table() . ' WHERE owner_user_id = %d', $user_id), ARRAY_A);
        return $row ?: null;
    }

    public static function by_public_key(string $key): ?array
    {
        global $wpdb;
        if (!preg_match('/^[a-f0-9]{32}$/', $key)) {
            return null;
        }
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . self::table() . ' WHERE public_key = %s', $key), ARRAY_A);
        return $row ?: null;
    }

    public static function by_token(string $token): ?array
    {
        global $wpdb;
        if ($token === '') {
            return null;
        }
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . self::table() . ' WHERE bot_token = %s', $token), ARRAY_A);
        return $row ?: null;
    }

    /** Возвращает коробку владельца, создавая пустую при первом обращении. */
    public static function ensure(int $user_id): array
    {
        $row = self::by_owner($user_id);
        if ($row) {
            return $row;
        }
        global $wpdb;
        $wpdb->insert(self::table(), [
            'owner_user_id' => $user_id,
            'public_key' => md5(wp_generate_password(32, false, false) . $user_id . microtime(true)),
            'secret' => wp_generate_password(32, false, false),
            'base_slug' => 'biznes',
            'accent' => '#22d3ee',
            'free_daily' => 0,
            'is_active' => 1,
            'status' => 'idle',
        ]);
        return self::by_owner($user_id);
    }

    public static function update(int $id, array $fields): void
    {
        global $wpdb;
        if ($fields) {
            $fields['updated_at'] = current_time('mysql');
            $wpdb->update(self::table(), $fields, ['id' => $id]);
        }
    }

    // ------------------------------------------------------------------ бот тенанта (вебхук)

    public static function webhook_url(array $tenant): string
    {
        return rest_url(GA_REST_NS . '/tenant/webhook/' . (int) $tenant['id']);
    }

    /** Проверяет токен, ставит вебхук и команды. */
    public static function connect(array $tenant): array
    {
        $token = (string) $tenant['bot_token'];
        if ($token === '') {
            return ['ok' => false, 'error' => 'Нет токена бота.'];
        }
        $me = GA_Telegram::api($token, 'getMe');
        if (is_wp_error($me)) {
            self::update((int) $tenant['id'], ['status' => 'error',
                'last_error' => $me->get_error_message(), 'checked_at' => current_time('mysql')]);
            return ['ok' => false, 'error' => $me->get_error_message()];
        }
        $hook = GA_Telegram::api($token, 'setWebhook', [
            'url' => self::webhook_url($tenant),
            'secret_token' => (string) $tenant['secret'],
            'allowed_updates' => wp_json_encode(['message']),
            'drop_pending_updates' => 'true',
        ]);
        if (is_wp_error($hook)) {
            self::update((int) $tenant['id'], ['status' => 'error',
                'last_error' => $hook->get_error_message(), 'checked_at' => current_time('mysql')]);
            return ['ok' => false, 'error' => $hook->get_error_message()];
        }
        GA_Telegram::api($token, 'setMyCommands', ['commands' => wp_json_encode([
            ['command' => 'start', 'description' => 'Начать'],
            ['command' => 'reset', 'description' => 'Забыть диалог'],
        ], JSON_UNESCAPED_UNICODE)]);
        self::update((int) $tenant['id'], [
            'bot_username' => (string) ($me['username'] ?? ''),
            'status' => 'connected', 'last_error' => '', 'checked_at' => current_time('mysql'),
        ]);
        return ['ok' => true, 'username' => (string) ($me['username'] ?? '')];
    }

    public static function disconnect(array $tenant): void
    {
        if (!empty($tenant['bot_token'])) {
            GA_Telegram::api((string) $tenant['bot_token'], 'deleteWebhook', ['drop_pending_updates' => 'true']);
        }
        self::update((int) $tenant['id'], [
            'bot_token' => '', 'bot_username' => '', 'status' => 'idle', 'last_error' => '',
            'checked_at' => current_time('mysql'),
        ]);
    }

    public static function handle_update(array $tenant, array $update): void
    {
        if (empty($tenant['is_active']) || empty($tenant['bot_token'])) {
            return;
        }
        $token = (string) $tenant['bot_token'];
        $message = $update['message'] ?? null;
        if (!$message) {
            return;
        }
        $chat_id = (int) ($message['chat']['id'] ?? 0);
        $text = trim((string) ($message['text'] ?? ''));
        if (!$chat_id) {
            return;
        }
        if ($text === '' && (isset($message['photo']) || isset($message['document']))) {
            GA_Telegram::send($token, $chat_id, 'Пока я отвечаю только на текст. Опишите вопрос словами.');
            return;
        }
        if (str_starts_with($text, '/start')) {
            GA_Telegram::send($token, $chat_id, self::welcome_text($tenant));
            return;
        }
        if ($text === '/reset') {
            global $wpdb;
            $base = GA_Store::assistant_by_slug($tenant['base_slug'] ?: 'biznes');
            if ($base) {
                $thread = GA_Store::thread((int) $base['id'], 'ten',
                    self::ext($tenant, 'tg', (string) $chat_id));
                $wpdb->delete(GA_Store::t('messages'), ['thread_id' => (int) $thread['id']]);
            }
            GA_Telegram::send($token, $chat_id, 'Диалог забыт. Начнём заново.');
            return;
        }
        if ($text === '') {
            return;
        }
        GA_Telegram::api($token, 'sendChatAction', ['chat_id' => $chat_id, 'action' => 'typing']);
        $res = self::reply($tenant, 'tg', (string) $chat_id, $text);
        GA_Telegram::send($token, $chat_id, $res['ok'] ? $res['reply'] : $res['error']);
    }

    // ------------------------------------------------------------------ ответ (общий для тг и веба)

    private static function ext(array $tenant, string $channel, string $customer): string
    {
        return (int) $tenant['id'] . ':' . $channel . ':' . $customer;
    }

    public static function welcome_text(array $tenant): string
    {
        $w = trim((string) $tenant['welcome']);
        if ($w !== '') {
            return $w;
        }
        $name = trim((string) $tenant['name']);
        return 'Здравствуйте! Я консультант ' . ($name !== '' ? '«' . $name . '»' : 'компании')
            . '. Задайте вопрос — помогу.';
    }

    /**
     * @return array{ok:bool,reply?:string,error?:string,code?:string}
     */
    public static function reply(array $tenant, string $channel, string $customer, string $text): array
    {
        $base = GA_Store::assistant_by_slug($tenant['base_slug'] ?: 'biznes');
        if (!$base) {
            return ['ok' => false, 'code' => 'cfg', 'error' => 'Консультант ещё не настроен.'];
        }
        $owner = (int) $tenant['owner_user_id'];
        // Платформа не должна работать в минус: коробка активна, пока у владельца
        // есть баланс. Это же прикрывает бесплатный безлимит веб-виджета, если
        // браузер режет third-party куку посетителя.
        if (GA_Billing::balance($owner) <= 0) {
            return ['ok' => false, 'code' => 'no_funds',
                'error' => 'Извините, консультант временно недоступен. Загляните чуть позже.'];
        }
        $text = trim(wp_check_invalid_utf8($text, true));
        if ($text === '') {
            return ['ok' => false, 'code' => 'empty', 'error' => 'Пустое сообщение.'];
        }
        $max = (int) $base['max_input_chars'] ?: 6000;
        if (mb_strlen($text) > $max) {
            return ['ok' => false, 'code' => 'too_long',
                'error' => 'Сообщение слишком длинное, сократите.'];
        }

        $thread = GA_Store::thread((int) $base['id'], 'ten',
            self::ext($tenant, $channel, $customer), ['user_id' => $owner]);

        $today = current_time('Y-m-d');
        $used = ($thread['counter_date'] === $today) ? (int) $thread['messages_today'] : 0;
        $free = max(0, (int) $tenant['free_daily']);
        $charge = 0.0;
        if ($used >= $free) {
            $price = GA_Billing::price_per_message();
            if (GA_Billing::balance($owner) < $price) {
                return ['ok' => false, 'code' => 'no_funds',
                    'error' => 'Извините, консультант временно недоступен. Загляните чуть позже.'];
            }
            $charge = $price;
        }

        $messages = [['role' => 'system', 'content' => self::build_system($tenant, $base)]];
        foreach (GA_Store::history((int) $thread['id'], (int) $base['history_depth'] ?: 12) as $row) {
            $messages[] = ['role' => $row['role'], 'content' => (string) $row['content']];
        }
        $messages[] = ['role' => 'user', 'content' => $text];

        $reply = GA_Kie::chat((string) $base['chat_model'], $messages, (float) $base['temperature']);
        if (is_wp_error($reply)) {
            return ['ok' => false, 'code' => 'model',
                'error' => 'Не получилось ответить. Попробуйте ещё раз.'];
        }
        if ($charge > 0) {
            GA_Billing::charge($owner, $charge,
                sprintf('Коробка «%s»', $tenant['name'] ?: ('#' . $tenant['id'])));
        }
        GA_Store::add_message((int) $thread['id'], 'user', $text);
        GA_Store::add_message((int) $thread['id'], 'assistant', $reply, $charge);
        GA_Store::bump_daily($thread);
        return ['ok' => true, 'reply' => $reply];
    }

    /** Системный промпт коробки: правила базового ассистента + брендинг + база владельца. */
    private static function build_system(array $tenant, array $base): string
    {
        $name = trim((string) $tenant['name']);
        $sys = 'Ты — ИИ-консультант компании ' . ($name !== '' ? '«' . $name . '»' : '')
            . ". Отвечай её клиентам вежливо, коротко и по делу.\n\n"
            . (string) $base['system_prompt'];
        $persona = trim((string) $tenant['persona']);
        if ($persona !== '') {
            $sys .= "\n\nСТИЛЬ И ПРАВИЛА КОМПАНИИ\n" . $persona;
        }
        $kb = GA_KB::get($tenant['owner_user_id']);
        $content = trim((string) $kb['content']);
        if ($content !== '') {
            $sys .= "\n\nБАЗА ЗНАНИЙ КОМПАНИИ\nОтвечай строго по ней; чего здесь нет — не выдумывай, "
                . "предложи оставить контакт.\n" . $content;
        }
        return $sys . "\n\nСегодня " . date_i18n('j F Y') . '.';
    }
}
