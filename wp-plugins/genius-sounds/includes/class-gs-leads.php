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

    /** Пересыльщик на сервере, где телеграм доступен. */
    const OPT_RELAY_URL   = 'gs_tg_relay_url';
    const OPT_RELAY_TOKEN = 'gs_tg_relay_token';

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
        return (self::token() !== '' && self::chat() !== '') || self::relay_ready();
    }

    /** Адрес службы-пересыльщика на нашем сервере. */
    public static function relay_url() {
        return trim((string) get_option(self::OPT_RELAY_URL, ''));
    }

    /** Общий ключ, которым пересыльщик отличает нас от посторонних. */
    public static function relay_token() {
        return trim((string) get_option(self::OPT_RELAY_TOKEN, ''));
    }

    public static function relay_ready() {
        return self::relay_url() !== '' && self::relay_token() !== '';
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
        $entry['how']  = (string) ($sent['how'] ?? '');
        $entry['error'] = $entry['sent'] ? '' : mb_substr((string) $sent['message'], 0, 200);
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

    /**
     * Отправка уведомления.
     *
     * С хостинга сайта api.telegram.org недоступен: соединение обрывается
     * за несколько миллисекунд — это закрытый доступ, а не медленная сеть.
     * Поэтому путей два: напрямую и через пересыльщик на сервере, где
     * телеграм отвечает. Сначала пробуем напрямую — если доступ когда-нибудь
     * откроют, лишнего звена в цепочке не останется.
     */
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

        $text = implode("\n", $lines);

        $direct = array('ok' => false, 'message' => 'бот не настроен');
        if (self::token() !== '' && self::chat() !== '') {
            $direct = self::send_direct($text);
            if (!empty($direct['ok'])) {
                return array('ok' => true, 'message' => '', 'how' => 'напрямую');
            }
        }

        if (!self::relay_ready()) {
            return array('ok' => false, 'how' => '', 'message' => (string) $direct['message']);
        }

        $relay = self::send_via_relay($text);
        if (!empty($relay['ok'])) {
            return array('ok' => true, 'message' => '', 'how' => 'через сервер');
        }

        return array(
            'ok'      => false,
            'how'     => '',
            'message' => 'напрямую: ' . $direct['message'] . '; через сервер: ' . $relay['message'],
        );
    }

    /** Напрямую в телеграм — путь, закрытый у нынешнего хостинга. */
    private static function send_direct($text) {
        $response = wp_remote_post(
            'https://api.telegram.org/bot' . self::token() . '/sendMessage',
            array(
                'timeout' => 15,
                'body'    => array(
                    'chat_id'                  => self::chat(),
                    'text'                     => $text,
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
                'message' => is_array($body)
                    ? (string) ($body['description'] ?? 'Telegram отклонил сообщение')
                    : 'Некорректный ответ Telegram',
            );
        }
        return array('ok' => true, 'message' => '');
    }

    /**
     * Через пересыльщик: сайт отдаёт только текст. Токен бота остаётся на
     * сервере, получателя выбирает сама служба — чужой chat_id она не
     * примет, даже если его прислать.
     */
    private static function send_via_relay($text) {
        $response = wp_remote_post(self::relay_url(), array(
            'timeout' => 20,
            'headers' => array(
                'Content-Type'  => 'application/json; charset=utf-8',
                'X-Relay-Token' => self::relay_token(),
            ),
            'body'    => wp_json_encode(array('text' => $text)),
        ));
        if (is_wp_error($response)) {
            return array('ok' => false, 'message' => $response->get_error_message());
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        $body = json_decode((string) wp_remote_retrieve_body($response), true);
        if ($code === 200 && is_array($body) && !empty($body['ok'])) {
            return array('ok' => true, 'message' => '');
        }
        return array(
            'ok'      => false,
            'message' => is_array($body) && !empty($body['error']) ? (string) $body['error'] : 'ответ ' . $code,
        );
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
