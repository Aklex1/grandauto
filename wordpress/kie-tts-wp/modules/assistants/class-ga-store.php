<?php
/**
 * Хранилище ассистентов, токенов ботов и диалогов.
 * Собственные таблицы заводим только под то, чего нет в kie-tts-wp:
 * сами ассистенты, токены телеграм-ботов и переписка. Пользователи и баланс — чужие.
 */

if (!defined('ABSPATH')) {
    exit;
}

class GA_Store
{
    public const SCHEMA_VERSION = 4;
    public const OPT_SCHEMA = 'ga_schema_version';

    public static function t(string $name): string
    {
        global $wpdb;
        return $wpdb->prefix . 'ga_' . $name;
    }

    /** Создаёт таблицы и засевает пресеты при первом подключении модуля. */
    public static function maybe_install(): void
    {
        if ((int) get_option(self::OPT_SCHEMA) === self::SCHEMA_VERSION) {
            return;
        }
        self::install();
        update_option(self::OPT_SCHEMA, self::SCHEMA_VERSION, false);
    }

    public static function install(): void
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();

        dbDelta("CREATE TABLE " . self::t('assistants') . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            slug VARCHAR(80) NOT NULL,
            name VARCHAR(160) NOT NULL,
            tagline VARCHAR(300) NOT NULL DEFAULT '',
            emoji VARCHAR(16) NOT NULL DEFAULT '',
            accent VARCHAR(16) NOT NULL DEFAULT '#6366f1',
            category VARCHAR(80) NOT NULL DEFAULT '',
            wave TINYINT NOT NULL DEFAULT 1,
            position INT NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            chat_model VARCHAR(120) NOT NULL DEFAULT 'gemini-3-8-flash-openai',
            temperature FLOAT NOT NULL DEFAULT 0.4,
            system_prompt LONGTEXT NULL,
            knowledge LONGTEXT NULL,
            welcome TEXT NULL,
            disclaimer TEXT NULL,
            quick_actions LONGTEXT NULL,
            history_depth INT NOT NULL DEFAULT 12,
            max_input_chars INT NOT NULL DEFAULT 6000,
            tg_enabled TINYINT(1) NOT NULL DEFAULT 1,
            web_enabled TINYINT(1) NOT NULL DEFAULT 1,
            free_daily_limit INT NOT NULL DEFAULT 5,
            price_month INT NOT NULL DEFAULT 0,
            landing_url VARCHAR(300) NOT NULL DEFAULT '',
            seo_title VARCHAR(300) NOT NULL DEFAULT '',
            seo_description VARCHAR(600) NOT NULL DEFAULT '',
            keywords TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY slug (slug),
            KEY active_pos (is_active, position)
        ) $charset;");

        dbDelta("CREATE TABLE " . self::t('bot_tokens') . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            assistant_id BIGINT UNSIGNED NOT NULL,
            label VARCHAR(160) NOT NULL DEFAULT '',
            token VARCHAR(200) NOT NULL,
            bot_username VARCHAR(120) NOT NULL DEFAULT '',
            bot_title VARCHAR(200) NOT NULL DEFAULT '',
            secret VARCHAR(64) NOT NULL DEFAULT '',
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            status VARCHAR(24) NOT NULL DEFAULT 'idle',
            last_error TEXT NULL,
            checked_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY token (token),
            KEY assistant (assistant_id)
        ) $charset;");

        dbDelta("CREATE TABLE " . self::t('threads') . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            assistant_id BIGINT UNSIGNED NOT NULL,
            channel VARCHAR(10) NOT NULL DEFAULT 'web',
            external_id VARCHAR(120) NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            ip_hash VARCHAR(64) NOT NULL DEFAULT '',
            tg_user_id BIGINT NOT NULL DEFAULT 0,
            source VARCHAR(160) NOT NULL DEFAULT '',
            messages_today INT NOT NULL DEFAULT 0,
            counter_date DATE NULL,
            total_messages INT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY thread (assistant_id, channel, external_id),
            KEY owner (user_id),
            KEY ip_day (ip_hash),
            KEY recent (last_at)
        ) $charset;");

        dbDelta("CREATE TABLE " . self::t('messages') . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            thread_id BIGINT UNSIGNED NOT NULL,
            role VARCHAR(12) NOT NULL DEFAULT 'user',
            content LONGTEXT NULL,
            cost DECIMAL(10,2) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY thread (thread_id, id)
        ) $charset;");

        self::seed();
    }

    /** Заводская поставка ассистентов. Существующие записи не трогаем — там могут быть правки. */
    public static function seed(): void
    {
        global $wpdb;
        foreach (GA_Presets::all() as $preset) {
            $exists = $wpdb->get_var($wpdb->prepare(
                'SELECT id FROM ' . self::t('assistants') . ' WHERE slug = %s', $preset['slug']));
            if ($exists) {
                continue;
            }
            $wpdb->insert(self::t('assistants'), [
                'slug' => $preset['slug'],
                'name' => $preset['name'],
                'tagline' => $preset['tagline'],
                'emoji' => $preset['emoji'],
                'accent' => $preset['accent'],
                'category' => $preset['category'],
                'wave' => $preset['wave'],
                'position' => $preset['position'],
                'is_active' => 1,
                'system_prompt' => $preset['system_prompt'] . "\n\n" . GA_Presets::FORMAT_RULES,
                'welcome' => $preset['welcome'],
                'disclaimer' => $preset['disclaimer'],
                'quick_actions' => wp_json_encode($preset['quick_actions'], JSON_UNESCAPED_UNICODE),
                'free_daily_limit' => $preset['free_daily_limit'],
                'price_month' => $preset['price_month'],
                'landing_url' => $preset['landing_url'],
                'seo_title' => $preset['seo_title'],
                'seo_description' => $preset['seo_description'],
                'keywords' => $preset['keywords'],
            ]);
        }
    }

    // ------------------------------------------------------------------ ассистенты

    public static function assistants(bool $only_active = false): array
    {
        global $wpdb;
        $where = $only_active ? 'WHERE is_active = 1' : '';
        return $wpdb->get_results(
            'SELECT * FROM ' . self::t('assistants') . " $where ORDER BY position ASC, id ASC",
            ARRAY_A) ?: [];
    }

    public static function assistant(int $id): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . self::t('assistants') . ' WHERE id = %d', $id), ARRAY_A);
        return $row ?: null;
    }

    public static function assistant_by_slug(string $slug): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . self::t('assistants') . ' WHERE slug = %s', $slug), ARRAY_A);
        return $row ?: null;
    }

    public static function update_assistant(int $id, array $fields): void
    {
        global $wpdb;
        if ($fields) {
            $wpdb->update(self::t('assistants'), $fields, ['id' => $id]);
        }
    }

    public static function actions(array $assistant): array
    {
        $raw = json_decode((string) ($assistant['quick_actions'] ?? ''), true);
        return is_array($raw) ? $raw : [];
    }

    // ------------------------------------------------------------------ токены ботов

    public static function tokens(?int $assistant_id = null): array
    {
        global $wpdb;
        if ($assistant_id) {
            return $wpdb->get_results($wpdb->prepare(
                'SELECT * FROM ' . self::t('bot_tokens') . ' WHERE assistant_id = %d ORDER BY id',
                $assistant_id), ARRAY_A) ?: [];
        }
        return $wpdb->get_results('SELECT * FROM ' . self::t('bot_tokens') . ' ORDER BY assistant_id, id',
            ARRAY_A) ?: [];
    }

    public static function token(int $id): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . self::t('bot_tokens') . ' WHERE id = %d', $id), ARRAY_A);
        return $row ?: null;
    }

    public static function token_by_value(string $token): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . self::t('bot_tokens') . ' WHERE token = %s', $token), ARRAY_A);
        return $row ?: null;
    }

    public static function add_token(int $assistant_id, string $token, string $label = ''): int
    {
        global $wpdb;
        $wpdb->insert(self::t('bot_tokens'), [
            'assistant_id' => $assistant_id,
            'token' => $token,
            'label' => $label,
            'secret' => wp_generate_password(32, false, false),
            'is_active' => 1,
            'status' => 'idle',
        ]);
        return (int) $wpdb->insert_id;
    }

    public static function update_token(int $id, array $fields): void
    {
        global $wpdb;
        if ($fields) {
            $wpdb->update(self::t('bot_tokens'), $fields, ['id' => $id]);
        }
    }

    public static function delete_token(int $id): void
    {
        global $wpdb;
        $wpdb->delete(self::t('bot_tokens'), ['id' => $id]);
    }

    /** Токен в интерфейсе показываем обрезанным — целиком он не светится нигде. */
    public static function mask(string $token): string
    {
        $parts = explode(':', $token, 2);
        $tail = $parts[1] ?? '';
        if (strlen($tail) < 12) {
            return '…';
        }
        return $parts[0] . ':' . substr($tail, 0, 4) . '…' . substr($tail, -4);
    }

    // ------------------------------------------------------------------ диалоги

    public static function thread(int $assistant_id, string $channel, string $external_id,
                                  array $extra = []): array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . self::t('threads') . ' WHERE assistant_id = %d AND channel = %s AND external_id = %s',
            $assistant_id, $channel, $external_id), ARRAY_A);
        if ($row) {
            // Добэкиваем ip_hash на диалоги, заведённые до появления IP-слоя.
            if (!empty($extra['ip_hash']) && empty($row['ip_hash'])) {
                $wpdb->update(self::t('threads'), ['ip_hash' => $extra['ip_hash']], ['id' => (int) $row['id']]);
                $row['ip_hash'] = $extra['ip_hash'];
            }
            return $row;
        }
        $wpdb->insert(self::t('threads'), array_merge([
            'assistant_id' => $assistant_id,
            'channel' => $channel,
            'external_id' => $external_id,
        ], $extra));
        return $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . self::t('threads') . ' WHERE id = %d', $wpdb->insert_id), ARRAY_A);
    }

    public static function history(int $thread_id, int $limit): array
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT role, content FROM ' . self::t('messages') .
            ' WHERE thread_id = %d ORDER BY id DESC LIMIT %d', $thread_id, $limit), ARRAY_A) ?: [];
        return array_reverse($rows);
    }

    public static function add_message(int $thread_id, string $role, string $content, float $cost = 0): void
    {
        global $wpdb;
        $wpdb->insert(self::t('messages'), [
            'thread_id' => $thread_id, 'role' => $role, 'content' => $content, 'cost' => $cost,
        ]);
    }

    /** Счётчик бесплатных сообщений за сегодня. Возвращает, сколько уже потрачено. */
    public static function bump_daily(array $thread): int
    {
        global $wpdb;
        $today = current_time('Y-m-d');
        $used = ($thread['counter_date'] === $today) ? (int) $thread['messages_today'] : 0;
        $wpdb->update(self::t('threads'), [
            'counter_date' => $today,
            'messages_today' => $used + 1,
            'total_messages' => (int) $thread['total_messages'] + 1,
            'last_at' => current_time('mysql'),
        ], ['id' => (int) $thread['id']]);
        return $used;
    }

    /**
     * Сколько сообщений пользователя отправлено сегодня с этого хеша IP
     * во всех гостевых диалогах. Второй слой лимита поверх куки.
     */
    public static function guest_ip_used_today(string $ip_hash): int
    {
        if ($ip_hash === '') {
            return 0;
        }
        global $wpdb;
        $threads = self::t('threads');
        $messages = self::t('messages');
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $messages m JOIN $threads t ON t.id = m.thread_id
             WHERE t.ip_hash = %s AND t.user_id = 0 AND m.role = 'user' AND DATE(m.created_at) = %s",
            $ip_hash, current_time('Y-m-d')));
    }

    public static function stats(int $assistant_id): array
    {
        global $wpdb;
        $threads = self::t('threads');
        $messages = self::t('messages');
        return [
            'threads' => (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $threads WHERE assistant_id = %d", $assistant_id)),
            'messages' => (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $messages m JOIN $threads t ON t.id = m.thread_id
                 WHERE t.assistant_id = %d AND m.role = 'user'", $assistant_id)),
            'today' => (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $messages m JOIN $threads t ON t.id = m.thread_id
                 WHERE t.assistant_id = %d AND m.role = 'user' AND DATE(m.created_at) = %s",
                $assistant_id, current_time('Y-m-d'))),
        ];
    }
}
