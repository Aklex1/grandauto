<?php
/**
 * Заявки с лендинга — сразу в Telegram.
 *
 * Форма на сайте без уведомления бесполезна: письмо теряется в спаме, а
 * заявку надо видеть в минуту, когда человек её оставил. Поэтому заявка
 * уходит сообщением в бот, а копия остаётся в журнале на случай, если
 * Telegram окажется недоступен.
 */

if (!defined('ABSPATH')) {
    exit;
}

class GS_Leads {

    const OPT_TOKEN = 'gs_tg_token';
    const OPT_CHAT  = 'gs_tg_chat';
    const OPT_LOG   = 'gs_leads_log';
    const LOG_LIMIT = 200;

    /** Не больше пяти заявок в час с одного адреса. */
    const LIMIT  = 5;
    const WINDOW = 3600;

    public static function boot() {
        add_action('admin_post_gs_leads_test', array(__CLASS__, 'handle_test'));
    }

    public static function handle_test() {
        if (!current_user_can('manage_options')) {
            wp_die('Недостаточно прав');
        }
        check_admin_referer('gs_leads_test');
        set_transient('gs_leads_notice', self::test(), 60);
        wp_safe_redirect(admin_url('admin.php?page=genius-sounds') . '#gs-leads');
        exit;
    }

    public static function token() {
        return trim((string) get_option(self::OPT_TOKEN, ''));
    }

    public static function chat() {
        return trim((string) get_option(self::OPT_CHAT, ''));
    }

    public static function ready() {
        return self::token() !== '' && self::chat() !== '';
    }

    /* ---------------------------------------------------------------------
     * Приём заявки
     * ------------------------------------------------------------------ */

    /**
     * @return array{ok:bool,message:string}
     */
    public static function accept($fields) {
        $name    = trim(sanitize_text_field((string) ($fields['name'] ?? '')));
        $contact = trim(sanitize_text_field((string) ($fields['contact'] ?? '')));
        $comment = trim(sanitize_textarea_field((string) ($fields['comment'] ?? '')));
        $source  = trim(sanitize_text_field((string) ($fields['source'] ?? 'лендинг')));

        if ($contact === '') {
            return array('ok' => false, 'message' => 'Оставьте телефон, почту или ник в Telegram');
        }
        if (mb_strlen($contact) > 120 || mb_strlen($name) > 80) {
            return array('ok' => false, 'message' => 'Слишком длинное значение');
        }

        $gate = self::gate();
        if (!$gate['ok']) {
            return $gate;
        }

        $entry = array(
            'at'      => current_time('mysql'),
            'name'    => $name,
            'contact' => $contact,
            'comment' => mb_substr($comment, 0, 600),
            'source'  => $source,
            'sent'    => false,
        );

        $sent = self::notify($entry);
        $entry['sent'] = !empty($sent['ok']);
        $entry['error'] = $entry['sent'] ? '' : mb_substr((string) $sent['message'], 0, 160);
        self::remember($entry);
        self::count();

        // Даже если Telegram не ответил, для человека заявка принята:
        // она лежит в журнале, и мы её не потеряем.
        return array('ok' => true, 'message' => 'Заявка принята — свяжемся с вами в ближайшее время');
    }

    private static function key() {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '0';
        return 'gs_lead_' . md5($ip);
    }

    private static function gate() {
        $used = (int) get_transient(self::key());
        if ($used >= self::LIMIT) {
            return array('ok' => false, 'message' => 'Слишком много заявок подряд. Напишите нам в Telegram напрямую.');
        }
        return array('ok' => true, 'message' => '');
    }

    private static function count() {
        $key = self::key();
        $used = (int) get_transient($key);
        set_transient($key, $used + 1, self::WINDOW);
    }

    private static function remember($entry) {
        $log = (array) get_option(self::OPT_LOG, array());
        $log[] = $entry;
        update_option(self::OPT_LOG, array_slice($log, -self::LOG_LIMIT), false);
    }

    public static function log_rows() {
        return array_reverse((array) get_option(self::OPT_LOG, array()));
    }

    /* ---------------------------------------------------------------------
     * Telegram
     * ------------------------------------------------------------------ */

    public static function notify($entry) {
        if (!self::ready()) {
            return array('ok' => false, 'message' => 'Бот не настроен');
        }

        $lines = array('📩 Заявка с сайта');
        if ($entry['name'] !== '') {
            $lines[] = 'Имя: ' . $entry['name'];
        }
        $lines[] = 'Контакт: ' . $entry['contact'];
        if ($entry['comment'] !== '') {
            $lines[] = 'Комментарий: ' . $entry['comment'];
        }
        $lines[] = 'Откуда: ' . $entry['source'];
        $lines[] = 'Когда: ' . $entry['at'];

        $response = wp_remote_post(
            'https://api.telegram.org/bot' . self::token() . '/sendMessage',
            array(
                'timeout' => 20,
                'body'    => array(
                    'chat_id'                  => self::chat(),
                    'text'                     => implode("\n", $lines),
                    'disable_web_page_preview' => 'true',
                ),
            )
        );

        if (is_wp_error($response)) {
            return array('ok' => false, 'message' => $response->get_error_message());
        }
        $body = json_decode((string) wp_remote_retrieve_body($response), true);
        if (!is_array($body) || empty($body['ok'])) {
            return array(
                'ok'      => false,
                'message' => is_array($body) ? (string) ($body['description'] ?? 'Telegram отклонил сообщение') : 'Некорректный ответ Telegram',
            );
        }
        return array('ok' => true, 'message' => '');
    }

    /** Проверка настройки: отправляем себе пробное сообщение. */
    public static function test() {
        return self::notify(array(
            'name'    => 'Проверка связи',
            'contact' => 'это тестовое сообщение из админки',
            'comment' => '',
            'source'  => 'настройки',
            'at'      => current_time('mysql'),
        ));
    }
}
