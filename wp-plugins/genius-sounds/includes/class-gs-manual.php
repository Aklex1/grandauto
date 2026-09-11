<?php
/**
 * Временный режим: заказ обрабатывается вручную через веб-интерфейс студии.
 *
 * Пока у разделения дорожек и шумоподавления нет рабочего API, пользователь
 * всё равно может оформить заказ: файл и оплата принимаются сразу, заказ
 * попадает в очередь, а готовый результат прикладывается через админку или
 * через API очереди — и дальше приходит пользователю в историю и на почту.
 *
 * Снаружи это тот же сервис: страница, статус, возврат денег при отказе.
 */

if (!defined('ABSPATH')) {
    exit;
}

class GS_Manual {

    const PREFIX     = 'gs_manual_';
    const OPT_INDEX  = 'gs_manual_index';
    const MAX_ORDERS = 500;

    /** Сколько ждём выполнения, прежде чем считать заказ просроченным. */
    const DEADLINE = 43200; // 12 часов

    public static function boot() {
        add_action('admin_post_gs_manual_result', array(__CLASS__, 'handle_admin_result'));
        add_action('admin_post_gs_manual_fail', array(__CLASS__, 'handle_admin_fail'));
    }

    public static function enabled($service_id) {
        return (string) get_option('gs_lab_manual_' . $service_id, '0') === '1';
    }

    public static function is_own_task($task_id) {
        return strpos((string) $task_id, 'mn-') === 0;
    }

    /* ---------------------------------------------------------------------
     * Заказы
     * ------------------------------------------------------------------ */

    public static function index() {
        $index = get_option(self::OPT_INDEX, array());
        return is_array($index) ? $index : array();
    }

    public static function get($task_id) {
        $order = get_option(self::PREFIX . $task_id, array());
        return is_array($order) && !empty($order['service']) ? $order : null;
    }

    private static function save($task_id, $order) {
        update_option(self::PREFIX . $task_id, $order, false);
    }

    /**
     * @return array{ok:bool,task_id:string,message:string}
     */
    public static function create_order($service_id, $params) {
        $service = GS_Lab::get_service($service_id);
        if (!$service) {
            return array('ok' => false, 'task_id' => '', 'message' => 'Неизвестный сервис');
        }
        $audio = isset($params['audio_url']) ? (string) $params['audio_url'] : '';
        if ($audio === '') {
            return array('ok' => false, 'task_id' => '', 'message' => 'Нет файла для обработки');
        }

        $task_id = 'mn-' . bin2hex(random_bytes(8));
        $order = array(
            'task_id'   => $task_id,
            'service'   => $service_id,
            'user_id'   => isset($params['user_id']) ? (int) $params['user_id'] : get_current_user_id(),
            'cost'      => isset($params['cost']) ? (float) $params['cost'] : GS_Lab::get_cost($service_id),
            'audio_url' => $audio,
            'seconds'   => isset($params['seconds']) ? (int) $params['seconds'] : 0,
            'status'    => 'queued',
            'files'     => array(),
            'message'   => '',
            'created'   => time(),
            'done_at'   => 0,
        );
        self::save($task_id, $order);

        $index = self::index();
        array_unshift($index, $task_id);
        update_option(self::OPT_INDEX, array_slice($index, 0, self::MAX_ORDERS), false);

        self::notify_owner($order);

        return array('ok' => true, 'task_id' => $task_id, 'message' => '');
    }

    /**
     * Состояние заказа в том же виде, что и у обычных сервисов.
     *
     * @return array{ok:bool,status:string,files:array,message:string}
     */
    public static function state($task_id) {
        $order = self::get($task_id);
        if (!$order) {
            return array('ok' => false, 'status' => 'pending', 'files' => array(), 'message' => '');
        }
        // Заказ просрочен — не держим человека в неведении и возвращаем деньги.
        if ($order['status'] === 'queued' && (int) $order['created'] < time() - self::DEADLINE) {
            return array(
                'ok'      => true,
                'status'  => 'failed',
                'files'   => array(),
                'message' => 'Не успели обработать запись, деньги возвращены на баланс',
            );
        }
        return array(
            'ok'      => true,
            'status'  => $order['status'] === 'queued' ? 'pending' : (string) $order['status'],
            'files'   => is_array($order['files']) ? $order['files'] : array(),
            'message' => (string) $order['message'],
        );
    }

    /**
     * Прикладываем готовые файлы: пути уже лежат в нашей папке результатов.
     *
     * @param array $files [['label'=>…, 'url'=>…, 'kind'=>'audio'], …]
     */
    public static function complete($task_id, $files) {
        $order = self::get($task_id);
        if (!$order) {
            return false;
        }
        $clean = array();
        foreach ((array) $files as $file) {
            if (empty($file['url'])) {
                continue;
            }
            $clean[] = array(
                'label' => isset($file['label']) ? (string) $file['label'] : 'Результат',
                'url'   => esc_url_raw((string) $file['url']),
                'kind'  => 'audio',
            );
        }
        if (empty($clean)) {
            return false;
        }
        $order['files']   = $clean;
        $order['status']  = 'completed';
        $order['done_at'] = time();
        self::save($task_id, $order);

        if (class_exists('KIE_TTS_DB')) {
            KIE_TTS_DB::update_generation_status($task_id, 'completed', $clean[0]['url']);
        }
        self::notify_user($order);
        return true;
    }

    public static function fail($task_id, $reason = '') {
        $order = self::get($task_id);
        if (!$order || $order['status'] !== 'queued') {
            return false;
        }
        $order['status']  = 'failed';
        $order['message'] = $reason !== '' ? (string) $reason : 'Не удалось обработать запись, деньги возвращены на баланс';
        $order['done_at'] = time();
        self::save($task_id, $order);

        GS_SFX::refund((int) $order['user_id'], (float) $order['cost']);
        if (class_exists('KIE_TTS_DB')) {
            KIE_TTS_DB::update_generation_status($task_id, 'failed');
        }
        return true;
    }

    /** Открытые заказы — для админки и для очереди обработчика. */
    public static function open_orders($limit = 50) {
        $out = array();
        foreach (self::index() as $task_id) {
            $order = self::get($task_id);
            if (!$order || $order['status'] !== 'queued') {
                continue;
            }
            $out[] = $order;
            if (count($out) >= $limit) {
                break;
            }
        }
        return $out;
    }

    public static function recent($limit = 20) {
        $out = array();
        foreach (self::index() as $task_id) {
            $order = self::get($task_id);
            if (!$order) {
                continue;
            }
            $out[] = $order;
            if (count($out) >= $limit) {
                break;
            }
        }
        return $out;
    }

    /* ---------------------------------------------------------------------
     * Письма
     * ------------------------------------------------------------------ */

    private static function notify_owner($order) {
        $service = GS_Lab::get_service($order['service']);
        $to = get_option('admin_email');
        if (!$to) {
            return;
        }
        $subject = 'Новый заказ: ' . ($service ? $service['menu'] : $order['service']);
        $body = "Поступил заказ на обработку.\n\n"
            . 'Услуга: ' . ($service ? $service['menu'] : $order['service']) . "\n"
            . 'Файл: ' . $order['audio_url'] . "\n"
            . 'Длительность: ' . GS_Storage::format_duration((int) $order['seconds']) . "\n"
            . 'Оплачено: ' . number_format_i18n((float) $order['cost'], 2) . " ₽\n\n"
            . 'Обработать и приложить результат: ' . admin_url('admin.php?page=genius-sounds#gs-manual') . "\n";
        wp_mail($to, $subject, $body);
    }

    private static function notify_user($order) {
        $user = get_userdata((int) $order['user_id']);
        if (!$user || empty($user->user_email)) {
            return;
        }
        $service = GS_Lab::get_service($order['service']);
        $lines = array('Ваш файл готов.', '');
        foreach ($order['files'] as $file) {
            $lines[] = $file['label'] . ': ' . $file['url'];
        }
        $lines[] = '';
        $lines[] = 'Файлы также лежат в истории на сайте: ' . GS_Pages::get_dashboard_url();
        wp_mail(
            $user->user_email,
            ($service ? $service['menu'] : 'Обработка звука') . ' — готово',
            implode("\n", $lines)
        );
    }

    /* ---------------------------------------------------------------------
     * Приём файла результата
     * ------------------------------------------------------------------ */

    /**
     * Кладёт присланный файл в папку результатов и возвращает адрес.
     *
     * @return array{ok:bool,url:string,message:string}
     */
    public static function store_result_file($file, $task_id, $suffix) {
        if (!is_array($file) || empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            return array('ok' => false, 'url' => '', 'message' => 'Файл не получен');
        }
        if ((int) $file['size'] > GS_Lab::MAX_AUDIO_BYTES_VOCAL) {
            return array('ok' => false, 'url' => '', 'message' => 'Файл больше 20 МБ');
        }
        $check = wp_check_filetype_and_ext($file['tmp_name'], (string) $file['name']);
        $ext = $check['ext'] ? $check['ext'] : strtolower((string) pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, array('mp3', 'wav', 'ogg', 'm4a'), true)) {
            return array('ok' => false, 'url' => '', 'message' => 'Нужен звуковой файл: MP3, WAV, OGG или M4A');
        }

        GS_Storage::ensure_dirs();
        $name = GS_Storage::sanitize_filename($task_id . '-' . $suffix . '.' . $ext);
        $target = GS_Storage::generated_dir() . '/' . $name;
        if (!@move_uploaded_file($file['tmp_name'], $target)) {
            return array('ok' => false, 'url' => '', 'message' => 'Не удалось сохранить файл');
        }
        @chmod($target, 0644);

        return array('ok' => true, 'url' => GS_Storage::generated_url() . '/' . $name, 'message' => '');
    }

    /* ---------------------------------------------------------------------
     * Действия из админки
     * ------------------------------------------------------------------ */

    public static function handle_admin_result() {
        if (!current_user_can('manage_options')) {
            wp_die('Недостаточно прав');
        }
        check_admin_referer('gs_manual');

        $task_id = isset($_POST['task_id']) ? sanitize_text_field(wp_unslash((string) $_POST['task_id'])) : '';
        $order = self::get($task_id);
        if (!$order) {
            self::back('Заказ не найден');
        }

        $files = array();
        $errors = array();
        foreach (self::labels((string) $order['service']) as $slot => $label) {
            if (empty($_FILES[$slot]['name'])) {
                continue;
            }
            $stored = self::store_result_file($_FILES[$slot], $task_id, $slot);
            if (empty($stored['ok'])) {
                $errors[] = $label . ': ' . $stored['message'];
                continue;
            }
            $files[] = array('label' => $label, 'url' => $stored['url'], 'kind' => 'audio');
        }

        if (empty($files)) {
            self::back($errors ? implode('; ', $errors) : 'Не приложен ни один файл');
        }
        self::complete($task_id, $files);
        self::back('Результат отправлен пользователю');
    }

    public static function handle_admin_fail() {
        if (!current_user_can('manage_options')) {
            wp_die('Недостаточно прав');
        }
        check_admin_referer('gs_manual');

        $task_id = isset($_POST['task_id']) ? sanitize_text_field(wp_unslash((string) $_POST['task_id'])) : '';
        $reason = isset($_POST['reason']) ? sanitize_text_field(wp_unslash((string) $_POST['reason'])) : '';
        self::fail($task_id, $reason);
        self::back('Заказ закрыт, деньги возвращены');
    }

    private static function back($message) {
        wp_safe_redirect(add_query_arg(
            'gs_manual_notice',
            rawurlencode($message),
            admin_url('admin.php?page=genius-sounds')
        ) . '#gs-manual');
        exit;
    }

    /** Подписи дорожек по услуге. */
    public static function labels($service_id) {
        if ($service_id === 'vocal') {
            return array('minus' => 'Минусовка (инструментал)', 'vocal' => 'Вокал без музыки');
        }
        return array('clean' => 'Очищенная запись');
    }
}
