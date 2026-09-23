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

    /**
     * Сообщение с вложением (документ/фото). Только для платных: нужен вход
     * и баланс не меньше цены файла. Списывается цена файла (разбор через KIE
     * дороже обычного сообщения).
     *
     * @param array $file ['data'=>base64, 'mime'=>string, 'name'=>string]
     */
    public static function ask_file(array $assistant, string $external_id, string $text,
                                    array $file, int $user_id): array
    {
        if (!GA_Billing::can_upload($user_id)) {
            return ['ok' => false, 'code' => 'upload_locked', 'error' => sprintf(
                'Подгрузка документов и фото доступна после авторизации на платном тарифе. '
                . 'Нужен баланс не меньше %s ₽.', number_format_i18n(GA_Billing::price_per_file(), 0))];
        }
        $mime = strtolower((string) ($file['mime'] ?? ''));
        $name = sanitize_file_name((string) ($file['name'] ?? 'file'));
        $raw = base64_decode((string) ($file['data'] ?? ''), true);
        if ($raw === false || $raw === '') {
            return ['ok' => false, 'code' => 'bad_file', 'error' => 'Файл не удалось прочитать.'];
        }
        if (strlen($raw) > 8 * 1024 * 1024) {
            return ['ok' => false, 'code' => 'too_big', 'error' => 'Файл больше 8 МБ. Уменьшите размер.'];
        }

        $thread = GA_Store::thread((int) $assistant['id'], 'web', $external_id, ['user_id' => $user_id]);
        $system = self::build_system($assistant);
        $messages = [['role' => 'system', 'content' => $system]];
        foreach (GA_Store::history((int) $thread['id'], (int) $assistant['history_depth'] ?: 12) as $row) {
            $messages[] = ['role' => $row['role'], 'content' => (string) $row['content']];
        }

        $is_image = str_starts_with($mime, 'image/');
        $user_note = $text !== '' ? $text : ($is_image ? 'Разбери, что на изображении.' : 'Разбери документ.');

        if ($is_image) {
            $data_url = 'data:' . $mime . ';base64,' . base64_encode($raw);
            $messages[] = GA_Kie::image_message($user_note, $data_url);
        } else {
            $extracted = self::extract_text($raw, $mime, $name);
            if ($extracted === null) {
                return ['ok' => false, 'code' => 'unsupported', 'error' =>
                    'Такой формat не поддерживается. Пришлите фото (jpg, png), текст (txt) или Word (docx).'];
            }
            $extracted = mb_substr($extracted, 0, 20000);
            $messages[] = ['role' => 'user',
                'content' => $user_note . "

Содержимое файла «" . $name . "»:
" . $extracted];
        }

        $reply = GA_Kie::chat((string) $assistant['chat_model'], $messages, (float) $assistant['temperature']);
        if (is_wp_error($reply)) {
            return ['ok' => false, 'code' => 'model', 'error' => $reply->get_error_message()];
        }
        $disclaimer = trim((string) $assistant['disclaimer']);
        if ($disclaimer !== '' && mb_stripos($reply, mb_substr($disclaimer, 0, 24)) === false) {
            $reply .= "

— " . $disclaimer;
        }

        $charged = GA_Billing::price_per_file();
        GA_Billing::charge($user_id, $charged, sprintf('Ассистент «%s» — разбор файла', $assistant['name']));
        GA_Store::add_message((int) $thread['id'], 'user', '📎 ' . $name . ($text ? ' — ' . $text : ''));
        GA_Store::add_message((int) $thread['id'], 'assistant', $reply, $charged);
        GA_Store::bump_daily($thread);

        return ['ok' => true, 'reply' => $reply, 'charged' => $charged,
                'balance' => GA_Billing::balance($user_id)];
    }

    /** Текст из простых форматов: txt/csv/md напрямую, docx через ZipArchive. */
    private static function extract_text(string $raw, string $mime, string $name): ?string
    {
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (str_starts_with($mime, 'text/') || in_array($ext, ['txt', 'csv', 'md', 'log'], true)) {
            return wp_check_invalid_utf8($raw, true);
        }
        if ($ext === 'docx' || $mime === 'application/vnd.openxmlformats-officedocument.wordprocessingml.document') {
            $tmp = wp_tempnam('ga-docx');
            file_put_contents($tmp, $raw);
            $text = '';
            if (class_exists('ZipArchive')) {
                $zip = new ZipArchive();
                if ($zip->open($tmp) === true) {
                    $xml = $zip->getFromName('word/document.xml');
                    $zip->close();
                    if ($xml) {
                        $xml = preg_replace('/<\/w:p>/', "\n", $xml);
                        $text = trim(wp_strip_all_tags($xml));
                    }
                }
            }
            @unlink($tmp);
            return $text !== '' ? $text : null;
        }
        return null;
    }

    /** Системный промпт + база знаний + дата (общий для текстового и файлового пути). */
    private static function build_system(array $assistant): string
    {
        $system = (string) $assistant['system_prompt'];
        $knowledge = trim((string) ($assistant['knowledge'] ?? ''));
        if ($knowledge !== '') {
            $system .= "\n\nБАЗА ЗНАНИЙ\n" . $knowledge;
        }
        return $system . "\n\nСегодня " . date_i18n('j F Y') . '.';
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
        $messages = [['role' => 'system', 'content' => self::build_system($assistant)]];
        foreach (GA_Store::history($thread_id, (int) $assistant['history_depth'] ?: 12) as $row) {
            $messages[] = ['role' => $row['role'], 'content' => (string) $row['content']];
        }
        $messages[] = ['role' => 'user', 'content' => $text];
        return $messages;
    }
}
