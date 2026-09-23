<?php
/**
 * Движок диалога: собирает контекст, проверяет лимиты и баланс, зовёт модель.
 * Одинаково обслуживает веб-виджет и телеграм-бота — канал отличается только идентификатором.
 */

if (!defined('ABSPATH')) {
    exit;
}

class GA_Chat
{
    /**
     * @return array{ok:bool,reply?:string,error?:string,code?:string,charged?:float,left?:int}
     */
    public static function ask(array $assistant, string $channel, string $external_id,
                               string $text, int $user_id = 0, array $extra = []): array
    {
        $text = trim(wp_check_invalid_utf8($text, true));
        if ($text === '') {
            return ['ok' => false, 'code' => 'empty', 'error' => 'Пустое сообщение.'];
        }
        $limit = (int) $assistant['max_input_chars'] ?: 6000;
        if (mb_strlen($text) > $limit) {
            return ['ok' => false, 'code' => 'too_long', 'error' => sprintf(
                'Слишком длинный текст: %d символов при лимите %d. Разбейте на части.',
                mb_strlen($text), $limit)];
        }

        $thread = GA_Store::thread((int) $assistant['id'], $channel, $external_id, array_merge([
            'user_id' => $user_id,
        ], $extra));

        $gate = self::gate($assistant, $thread, $user_id);
        if (!$gate['ok']) {
            return $gate;
        }

        $messages = self::build_context($assistant, (int) $thread['id'], $text);
        $reply = GA_Kie::chat((string) $assistant['chat_model'], $messages,
            (float) $assistant['temperature']);

        if (is_wp_error($reply)) {
            return ['ok' => false, 'code' => 'model', 'error' => $reply->get_error_message()];
        }

        $disclaimer = trim((string) $assistant['disclaimer']);
        if ($disclaimer !== '' && mb_stripos($reply, mb_substr($disclaimer, 0, 24)) === false) {
            $reply .= "\n\n— " . $disclaimer;
        }

        // Платим только за состоявшийся ответ: если модель упала, деньги остаются у человека.
        $charged = 0.0;
        if (!empty($gate['charge'])) {
            $charged = GA_Billing::price_per_message();
            GA_Billing::charge($user_id, $charged, sprintf('Ассистент «%s»', $assistant['name']));
        }

        GA_Store::add_message((int) $thread['id'], 'user', $text);
        GA_Store::add_message((int) $thread['id'], 'assistant', $reply, $charged);
        $used = GA_Store::bump_daily($thread);

        return [
            'ok' => true,
            'reply' => $reply,
            'charged' => $charged,
            'left' => max(0, self::free_limit($assistant, $user_id) - $used - 1),
        ];
    }

    /**
     * Хеш клиентского IP (сырой IP не сохраняем). За реальным IP смотрим
     * заголовки прокси/CDN, затем REMOTE_ADDR. Соль — из ключей WordPress.
     */
    public static function client_ip_hash(): string
    {
        $ip = '';
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $k) {
            if (!empty($_SERVER[$k])) {
                $ip = trim(explode(',', (string) $_SERVER[$k])[0]);
                break;
            }
        }
        if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP)) {
            return '';
        }
        return substr(hash_hmac('sha256', $ip, wp_salt('ga-ip')), 0, 64);
    }

    /** Сколько бесплатных сообщений в сутки положено этому собеседнику. */
    private static function free_limit(array $assistant, int $user_id): int
    {
        return $user_id
            ? (int) $assistant['free_daily_limit']
            : GA_Billing::guest_free_limit();
    }

    /**
     * Проверка права на ответ: сначала бесплатная норма, потом общий баланс.
     * Возвращает ok + признак того, нужно ли списывать деньги.
     */
    private static function gate(array $assistant, array $thread, int $user_id): array
    {
        $today = current_time('Y-m-d');
        $used = ($thread['counter_date'] === $today) ? (int) $thread['messages_today'] : 0;
        $free = self::free_limit($assistant, $user_id);

        // Гостя ограничиваем не только по куке, но и по IP: очистка cookie
        // или инкогнито не обнуляют лимит, если IP тот же. За лимит берём
        // больший из двух счётчиков.
        if (!$user_id) {
            $ip_used = GA_Store::guest_ip_used_today((string) ($thread['ip_hash'] ?? ''));
            $used = max($used, $ip_used);
        }

        if ($used < $free) {
            return ['ok' => true, 'charge' => false];
        }
        if (!$user_id) {
            return [
                'ok' => false,
                'code' => 'auth_required',
                'error' => sprintf(
                    'Бесплатные %d сообщения на сегодня закончились. Войдите — на балансе аккаунта '
                    . 'работают все сервисы Genius, и ассистенты, и озвучка.', $free),
            ];
        }
        $price = GA_Billing::price_per_message();
        if (GA_Billing::balance($user_id) < $price) {
            return [
                'ok' => false,
                'code' => 'no_funds',
                'error' => sprintf(
                    'Бесплатные сообщения на сегодня закончились, а на балансе меньше %s ₽. '
                    . 'Пополните счёт — он общий со всеми сервисами.',
                    number_format_i18n($price, 0)),
            ];
        }
        return ['ok' => true, 'charge' => true];
    }

    /** Системный промпт + база знаний + хвост истории + новая реплика. */
    private static function build_context(array $assistant, int $thread_id, string $text): array
    {
        $system = (string) $assistant['system_prompt'];
        $knowledge = trim((string) ($assistant['knowledge'] ?? ''));
        if ($knowledge !== '') {
            $system .= "\n\nБАЗА ЗНАНИЙ\n" . $knowledge;
        }
        $system .= "\n\nСегодня " . date_i18n('j F Y') . '.';

        $messages = [['role' => 'system', 'content' => $system]];
        foreach (GA_Store::history($thread_id, (int) $assistant['history_depth'] ?: 12) as $row) {
            $messages[] = ['role' => $row['role'], 'content' => (string) $row['content']];
        }
        $messages[] = ['role' => 'user', 'content' => $text];
        return $messages;
    }
}
