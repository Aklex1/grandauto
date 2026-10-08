<?php
/**
 * Единая точка приёма платежей ЮMoney.
 *
 * Кошелёк один на все сервисы, а уведомление ЮMoney шлёт на один адрес.
 * Поэтому сайт принимает его сам и раскладывает по назначению:
 *
 *   kie-neurohub|…   → баланс раздела «Нейросети»
 *   topup_wp_…       → баланс кабинета озвучки (он же общий для микросервисов)
 *   topup_telegram_… → он же, для пользователей из бота
 *   остальное        → пересылается дальше, на прежний адрес бота
 *
 * Метки своих платежей узнаются по полному началу, а не по слову topup_:
 * у бота свой формат, и однажды он уже попал под это правило — платежи
 * уходили в кабинет озвучки, где такой метки нет, и терялись.
 *
 * Попутно ведётся журнал: по метке в назначении платежа видно, с какого
 * сервиса пришли деньги, даже если баланс у пользователя один.
 */

if (!defined('ABSPATH')) {
    exit;
}

class GS_Yoomoney {

    const OPT_SECRET  = 'gs_yoomoney_secret';
    const OPT_FORWARD = 'gs_yoomoney_forward';
    const OPT_LOG     = 'gs_yoomoney_log';
    /** Пока уведомление разбирается внутри сайта, повторно проверять подпись незачем. */
    private static $forwarding = false;
    const OPT_STATS   = 'gs_yoomoney_stats';
    const LOG_LIMIT   = 200;
    /** У скольких свежих записей журнала держим сам пакет для повтора. */
    const KEEP_PARAMS = 30;

    /**
     * Уведомления, которые не удалось передать боту.
     *
     * Платежи бота заводятся в его базе, и зачисляет их он сам: сайт только
     * передаёт ему уведомление. Если бот в этот момент недоступен, деньги
     * уже лежат в кошельке, а баланс не растёт — и уведомление пропадало
     * насовсем, потому что в журнал попадала только строчка об ошибке.
     * Теперь такие уведомления ждут здесь и уходят, как только бот ответит.
     */
    const OPT_OUTBOX  = 'gs_yoomoney_outbox';
    const HOOK_RETRY  = 'gs_yoomoney_retry';
    /** Две недели попыток: дольше ждать нечего, нужно разбираться руками. */
    const OUTBOX_TTL  = 1209600;

    public static function boot() {
        add_action('rest_api_init', array(__CLASS__, 'register_routes'));
        add_action('admin_post_gs_yoomoney_reset', array(__CLASS__, 'handle_reset'));
        add_action('admin_post_gs_yoomoney_retry', array(__CLASS__, 'handle_retry'));
        add_action('admin_post_gs_yoomoney_replay', array(__CLASS__, 'handle_replay'));
        add_filter('cron_schedules', array(__CLASS__, 'add_schedule'), 5);
        add_action('init', array(__CLASS__, 'ensure_cron'));
        add_action(self::HOOK_RETRY, array(__CLASS__, 'drain'));
    }

    public static function add_schedule($schedules) {
        if (!isset($schedules['gs_five_minutes'])) {
            $schedules['gs_five_minutes'] = array('interval' => 300, 'display' => 'Каждые 5 минут');
        }
        return $schedules;
    }

    public static function ensure_cron() {
        if (!wp_next_scheduled(self::HOOK_RETRY)) {
            wp_schedule_event(time() + 120, 'gs_five_minutes', self::HOOK_RETRY);
        }
    }

    /** Повторить пересылку сейчас — когда бот подняли и ждать незачем. */
    public static function handle_retry() {
        if (!current_user_can('manage_options')) {
            wp_die('Недостаточно прав');
        }
        check_admin_referer('gs_yoomoney_retry');
        self::drain(true);
        wp_safe_redirect(admin_url('admin.php?page=genius-sounds') . '#gs-payments');
        exit;
    }

    /**
     * Переслать боту сохранённое уведомление ещё раз.
     *
     * Повтор безопасен: приёмник бота сверяет подпись и пропускает платёж,
     * который уже закрыт, — в журнале это видно как «Payment not found or
     * already processed». То есть дважды одни и те же деньги не зачислятся.
     */
    public static function handle_replay() {
        if (!current_user_can('manage_options')) {
            wp_die('Недостаточно прав');
        }
        check_admin_referer('gs_yoomoney_replay');

        $label = sanitize_text_field(wp_unslash((string) ($_POST['label'] ?? '')));
        $log = get_option(self::OPT_LOG, array());
        $log = is_array($log) ? $log : array();

        $found = null;
        foreach ($log as $row) {
            if ((string) ($row['label'] ?? '') === $label && !empty($row['params'])) {
                $found = $row;
                break;
            }
        }

        if (!$found) {
            set_transient('gs_payment_notice', 'Пакет уведомления не сохранён — повторить нечего', 60);
        } else {
            $sent = self::forward_external((array) $found['params']);
            self::remember($label, (float) $found['amount'],
                !empty($sent['ok']) ? 'переслано' : 'не наш платёж',
                'повтор из журнала: ' . $sent['message'],
                (array) $found['params'], 'bot');
            set_transient('gs_payment_notice',
                (!empty($sent['ok']) ? 'ok: передано боту — ' : 'не передалось — ') . $sent['message'], 60);
        }

        wp_safe_redirect(admin_url('admin.php?page=genius-sounds') . '#gs-payments');
        exit;
    }

    public static function outbox() {
        $rows = get_option(self::OPT_OUTBOX, array());
        return is_array($rows) ? $rows : array();
    }

    /** Отложить уведомление до лучших времён. */
    private static function queue($params, $label, $amount) {
        $rows = self::outbox();
        foreach ($rows as $row) {
            if ((string) ($row['label'] ?? '') === (string) $label) {
                return;  // это же уведомление уже ждёт
            }
        }
        $rows[] = array(
            'label'  => (string) $label,
            'amount' => (float) $amount,
            'params' => (array) $params,
            'tries'  => 1,
            'first'  => time(),
            'next'   => time() + 600,
        );
        update_option(self::OPT_OUTBOX, array_slice($rows, -100), false);
    }

    private static function wait_for($tries) {
        $steps = array(600, 1800, 3600, 10800, 21600, 43200);
        $i = max(0, (int) $tries - 1);
        return isset($steps[$i]) ? $steps[$i] : 86400;
    }

    /**
     * Отдать боту всё, что накопилось.
     *
     * @param bool $now Не смотреть на срок следующей попытки.
     */
    public static function drain($now = false) {
        $rows = self::outbox();
        if (!$rows) {
            return;
        }
        $keep = array();
        foreach ($rows as $row) {
            $tries = (int) ($row['tries'] ?? 1);
            if (!$now && (int) ($row['next'] ?? 0) > time()) {
                $keep[] = $row;
                continue;
            }
            if (time() - (int) ($row['first'] ?? time()) > self::OUTBOX_TTL) {
                self::remember((string) $row['label'], (float) $row['amount'], 'не наш платёж',
                    'две недели не удавалось передать боту — зачислите вручную',
                    (array) $row['params'], 'bot');
                continue;
            }

            $sent = self::forward_external((array) $row['params']);
            if (!empty($sent['ok'])) {
                self::remember((string) $row['label'], (float) $row['amount'], 'переслано',
                    'с повтора, попытка ' . $tries . ': ' . $sent['message'],
                    (array) $row['params'], 'bot');
                continue;
            }
            $row['tries'] = $tries + 1;
            $row['next']  = time() + self::wait_for($row['tries']);
            $row['error'] = (string) $sent['message'];
            $keep[] = $row;
        }
        update_option(self::OPT_OUTBOX, array_values($keep), false);
    }

    /** Сброс журнала и подсчётов — например, после проверочных уведомлений. */
    public static function handle_reset() {
        if (!current_user_can('manage_options')) {
            wp_die('Недостаточно прав');
        }
        check_admin_referer('gs_yoomoney_reset');
        delete_option(self::OPT_LOG);
        delete_option(self::OPT_STATS);
        wp_safe_redirect(admin_url('admin.php?page=genius-sounds') . '#gs-payments');
        exit;
    }

    /** Идёт ли сейчас внутренняя пересылка уведомления. */
    public static function is_forwarding() {
        return self::$forwarding;
    }

    public static function endpoint_url() {
        return rest_url('genius/v1/yoomoney');
    }

    public static function register_routes() {
        register_rest_route('genius/v1', '/yoomoney', array(
            'methods'             => 'POST',
            'callback'            => array(__CLASS__, 'handle'),
            'permission_callback' => '__return_true',
        ));
    }

    /* ---------------------------------------------------------------------
     * Приём уведомления
     * ------------------------------------------------------------------ */

    public static function handle($request) {
        $params = $request->get_params();
        if (!is_array($params)) {
            $params = array();
        }
        $label  = isset($params['label']) ? (string) $params['label'] : '';
        $amount = isset($params['withdraw_amount']) ? (float) $params['withdraw_amount'] : (float) ($params['amount'] ?? 0);

        if (!self::signature_ok($params)) {
            self::remember($label, $amount, 'отклонено', 'подпись не сошлась', $params);
            return new WP_REST_Response(array('status' => 'error', 'credited' => false, 'reason' => 'bad signature'), 403);
        }

        // Тестовое уведомление из личного кабинета ЮMoney приходит без метки.
        if ($label === '') {
            self::remember('', $amount, 'пропущено', 'уведомление без метки', $params);
            return self::reply(false, 'none', 'уведомление без метки');
        }

        // Юридический документ: метка заказа, зачисления на баланс нет —
        // по уведомлению собирается сам документ.
        if (class_exists('GS_Legal_Doc') && strpos($label, GS_Legal_Doc::LABEL_PREFIX) === 0) {
            $result = GS_Legal_Doc::paid($label);
            self::remember($label, $amount, !empty($result['ok']) ? 'зачислено' : 'ошибка',
                (string) ($result['message'] ?? ''), $params, 'legal');
            return self::reply(!empty($result['ok']), 'legal', (string) ($result['message'] ?? ''));
        }

        // Индивидуальный проект: метка заказа, баланс не трогаем — оплата
        // разовая, за тариф, и открывает шаги в конкретном проекте.
        if (class_exists('GS_Proekt') && strpos($label, GS_Proekt::LABEL_PREFIX) === 0) {
            $result = GS_Proekt::paid($label);
            self::remember($label, $amount, !empty($result['ok']) ? 'зачислено' : 'ошибка',
                (string) ($result['message'] ?? ''), $params, 'proekt');
            return self::reply(!empty($result['ok']), 'proekt', (string) ($result['message'] ?? ''));
        }

        // Песня в подарок: метка своя, зачисления на баланс нет — деньги
        // сразу за конкретный заказ, и по уведомлению запускается запись.
        if (class_exists('GS_Gift') && strpos($label, GS_Gift::LABEL_PREFIX) === 0) {
            $result = GS_Gift::paid($label);
            self::remember($label, $amount, !empty($result['ok']) ? 'зачислено' : 'ошибка',
                (string) ($result['message'] ?? ''), $params, 'gift');
            return self::reply(!empty($result['ok']), 'gift', (string) ($result['message'] ?? ''));
        }

        if (strpos($label, 'kie-neurohub|') === 0) {
            $result = self::forward_internal('/neurohub/v1/yoomoney-callback', $params);
            self::remember($label, $amount, $result['ok'] ? 'зачислено' : 'ошибка', $result['message'], $params, 'neurohub');
            return self::reply($result['ok'], 'neurohub', $result['message']);
        }

        if (strpos($label, 'topup_wp_') === 0 || strpos($label, 'topup_telegram_') === 0) {
            // Повторное уведомление по уже закрытому платежу баланс не
            // меняет — и это правильно. Сверять в таком случае нечего.
            $repeat = self::already_completed($label);
            $before = $repeat ? null : self::balance_by_label($label);
            $result = self::forward_internal('/tts/v1/yoomoney-webhook', $params);
            $result = self::confirm_credit($label, $before, $result);
            if (!$result['ok'] && stripos($result['message'], 'not found') !== false) {
                // Метка похожа на нашу, а платежа с ней нет. Чем терять
                // деньги, отдаём уведомление дальше — вдруг это бот.
                $sent = self::forward_external($params);
                if (empty($sent['ok'])) {
                    self::queue($params, $label, $amount);
                }
                self::remember($label, $amount, $sent['ok'] ? 'переслано' : 'ошибка',
                    $result['message'] . '; ' . $sent['message']
                    . (empty($sent['ok']) ? ' — отложено до ответа бота' : ''), $params, 'bot');
                return self::reply(false, 'tts', $result['message']);
            }
            // Пополнение баланса: если окно пополнения проставило [src:],
            // источником будет сервис, с которого человек пришёл платить,
            // иначе — общий кабинет озвучки.
            $src = self::source_from($params, $label);
            self::remember($label, $amount, $result['ok'] ? 'зачислено' : 'ошибка', $result['message'],
                $params, $src !== '' ? $src : 'tts');
            return self::reply($result['ok'], 'tts', $result['message']);
        }

        // Метка не наша: платёж заводил не сайт — скорее всего бот со своим
        // форматом метки. Пересылаем, если задан адрес, и в любом случае
        // честно говорим, что на сайте зачисления не было.
        $result = self::forward_external($params);
        if (empty($result['ok'])) {
            // Деньги уже в кошельке, а передать их боту не вышло. Выбросить
            // уведомление — значит потерять платёж: баланс человеку никто
            // не начислит, и узнаем мы об этом только из его жалобы.
            self::queue($params, $label, $amount);
        }
        self::remember($label, $amount, $result['ok'] ? 'переслано' : 'не наш платёж',
            $result['message'] . (empty($result['ok']) ? ' — отложено до ответа бота' : ''),
            $params, 'bot');
        return self::reply(false, 'unknown', $result['message']);
    }

    /**
     * Ответ в машиночитаемом виде: по нему приёмник на сервере понимает,
     * нужно ли отдавать платёж дальше. Код всегда 200 — иначе ЮMoney
     * будет повторять уведомление, хотя разбирать его уже некому.
     */
    private static function reply($credited, $route, $message) {
        return new WP_REST_Response(array(
            'status'   => 'ok',
            'credited' => (bool) $credited,
            'route'    => (string) $route,
            'message'  => (string) $message,
        ), 200);
    }

    /**
     * Подпись ЮMoney. Пока секрет не задан, проверять нечем — тогда
     * уведомления принимаются как раньше, но это видно в журнале.
     *
     * Подписей у них две: новая sign (HMAC-SHA256 по отсортированным
     * полям) и устаревшая sha1_hash по фиксированному порядку. Какая
     * придёт — зависит от настроек кошелька, поэтому принимаем обе:
     * отказывать деньгам из-за формата подписи неправильно.
     */
    public static function signature_ok($params) {
        $secret = trim((string) get_option(self::OPT_SECRET, ''));
        if ($secret === '') {
            return true;
        }

        if (!empty($params['sign'])) {
            $fields = $params;
            unset($fields['sign']);
            ksort($fields);
            $parts = array();
            foreach ($fields as $key => $value) {
                $parts[] = $key . '=' . rawurlencode((string) $value);
            }
            $calc = hash_hmac('sha256', implode('&', $parts), $secret);
            if (hash_equals($calc, strtolower((string) $params['sign']))) {
                return true;
            }
        }

        $provided = isset($params['sha1_hash']) ? strtolower((string) $params['sha1_hash']) : '';
        if ($provided === '') {
            return false;
        }
        $parts = array(
            (string) ($params['notification_type'] ?? ''),
            (string) ($params['operation_id'] ?? ''),
            (string) ($params['amount'] ?? ''),
            (string) ($params['currency'] ?? ''),
            (string) ($params['datetime'] ?? ''),
            (string) ($params['sender'] ?? ''),
            (string) ($params['codepro'] ?? ''),
            $secret,
            (string) ($params['label'] ?? ''),
        );
        return hash_equals(sha1(implode('&', $parts)), $provided);
    }

    /** Платёж уже закрыт — значит, уведомление повторное. */
    private static function already_completed($label) {
        if (!class_exists('KIE_TTS_Payment')) {
            return false;
        }
        $payment = KIE_TTS_Payment::get_payment_by_label($label);
        return is_array($payment) && (string) ($payment['status'] ?? '') === 'completed';
    }

    /**
     * Баланс плательщика по метке платежа.
     *
     * Нужен, чтобы проверить зачисление делом, а не на слово. У пользователей
     * из бота баланс лежит в его базе, у остальных — в таблице сайта; какой
     * случай, записано в самом платеже.
     *
     * @return float|null null — если посмотреть не получилось.
     */
    private static function balance_by_label($label) {
        if (!class_exists('KIE_TTS_Payment') || !class_exists('KIE_TTS_DB')) {
            return null;
        }
        $payment = KIE_TTS_Payment::get_payment_by_label($label);
        if (!is_array($payment) || empty($payment['user_id'])) {
            return null;
        }
        // Смотрим не на отметку в платеже, а туда, где деньги лежат сейчас.
        // Старые платежи помечены «из бота», но балансы переведены на сайт,
        // и по отметке мы читали бы пустую чужую базу — а значит считали бы
        // зачисление неудачным и возвращали в незакрытые уже закрытое.
        $who = (int) $payment['user_id'];
        $is_telegram = class_exists('KIE_TTS_Auth') && KIE_TTS_Auth::is_telegram_user($who);
        if ($is_telegram) {
            $who = (int) KIE_TTS_Auth::get_telegram_id($who);
            if (!$who) {
                return null;
            }
        }
        return (float) KIE_TTS_DB::get_user_balance($who, $is_telegram);
    }

    /**
     * Действительно ли баланс вырос.
     *
     * Платёжный маршрут отвечает «обработано» и тогда, когда запись в базу
     * не удалась: возвращаемое значение он не проверяет. Для пользователей
     * из бота баланс лежит в чужой базе, до которой сайт может и не
     * достучаться, — и тогда деньги списаны, платёж помечен закрытым, а
     * баланс прежний, и в журнале об этом ни слова. Поэтому сверяем сами.
     */
    private static function confirm_credit($label, $before, $result) {
        if (!$result['ok'] || $before === null) {
            return $result;
        }
        $after = self::balance_by_label($label);
        if ($after === null || $after > $before + 0.001) {
            return $result;
        }
        // Возвращаем платёж в незакрытые. Пока он помечен закрытым, повторное
        // уведомление от ЮMoney ничего не исправит, кнопка ручного зачисления
        // считает работу сделанной, и деньги остаются только в кошельке.
        // В таком виде платёж виден в админке и зачисляется одним нажатием,
        // как только доступ к базе с балансами починят.
        self::reopen($label);
        return array(
            'ok' => false,
            'message' => sprintf(
                'платёж закрыт, но баланс не изменился (%s ₽ до и после) — '
                . 'вернули в незакрытые, зачислите вручную',
                number_format($before, 2, ',', ' ')
            ),
        );
    }

    /** Снять с платежа отметку «закрыт»: зачисления по нему не было. */
    private static function reopen($label) {
        global $wpdb;
        $table = $wpdb->prefix . 'kie_tts_payments';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
            return;
        }
        $wpdb->update(
            $table,
            array('status' => 'pending', 'completed_at' => null),
            array('label' => (string) $label)
        );
    }

    /** Передаём уведомление нужному обработчику внутри сайта. */
    private static function forward_internal($route, $params) {
        $request = new WP_REST_Request('POST', $route);
        foreach ($params as $key => $value) {
            $request->set_param($key, $value);
        }
        self::$forwarding = true;
        $response = rest_do_request($request);
        self::$forwarding = false;
        $status = $response instanceof WP_REST_Response ? $response->get_status() : 0;
        $data = $response instanceof WP_REST_Response ? $response->get_data() : null;

        // Кабинет озвучки отвечает кодом 200 и на неудачу, поэтому смотрим
        // не только код, но и тело ответа — иначе в статистику попадут
        // платежи, которые на самом деле не зачислены.
        $failed = false;
        $message = 'обработано';
        if (is_array($data)) {
            if ((string) ($data['status'] ?? '') === 'error' || (isset($data['success']) && !$data['success'])) {
                $failed = true;
                $message = (string) ($data['message'] ?? 'обработчик отклонил платёж');
            }
        }
        if ($status >= 300 || $failed) {
            if (!$failed) {
                $message = 'код ' . $status;
                if (is_array($data) && !empty($data['message'])) {
                    $message .= ': ' . (string) $data['message'];
                }
            }
            return array('ok' => false, 'message' => $message);
        }
        return array('ok' => true, 'message' => $message);
    }

    /**
     * Жив ли приёмник бота.
     *
     * Адрес берём из настроек сайта, снаружи его не подставить. Нужно это
     * затем, что единственным признаком «бот не принимает» была строка
     * cURL-ошибки в журнале, а она появляется только когда кто-то уже
     * заплатил. Проверять хочется до того.
     */
    public static function probe_forward() {
        $url = trim((string) get_option(self::OPT_FORWARD, ''));
        if ($url === '') {
            return array('ok' => false, 'адрес' => '', 'ответ' => 'адрес пересылки не задан');
        }

        // Бьём в /health рядом с ручкой уведомлений: она отвечает на GET и
        // ничего не меняет, в отличие от самой /yoomoney-webhook.
        $health = preg_replace('~/[^/]*$~', '/health', $url);
        $started = microtime(true);
        $response = wp_remote_get($health, array('timeout' => 15));
        $ms = (int) round((microtime(true) - $started) * 1000);

        if (is_wp_error($response)) {
            return array(
                'ok'     => false,
                'адрес'  => $health,
                'ответ'  => $response->get_error_message(),
                'мс'     => $ms,
            );
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        $body = trim(wp_strip_all_tags((string) wp_remote_retrieve_body($response)));
        return array(
            'ok'     => $code > 0 && $code < 400,
            'адрес'  => $health,
            'код'    => $code,
            'ответ'  => mb_substr($body, 0, 160),
            'мс'     => $ms,
        );
    }

    /**
     * Соседние порты того же сервера.
     *
     * Нужно, чтобы отличить «сервис лёг» от «до сервера вообще нет пути».
     * Если соседние порты отвечают, а наш нет — поднимать приёмник. Если
     * молчат все — дело в сервере или в сети, и приёмник тут ни при чём.
     * Хост берём из того же адреса пересылки, порты — список наших служб.
     */
    public static function probe_neighbours() {
        $url = trim((string) get_option(self::OPT_FORWARD, ''));
        $host = $url === '' ? '' : (string) wp_parse_url($url, PHP_URL_HOST);
        if ($host === '') {
            return array();
        }

        $ports = array(
            8000 => 'приёмник пополнений бота',
            8002 => 'приёмник пополнений приложения',
            8003 => 'метрики приёмника пополнений',
            8111 => 'адрес, указанный в кошельке ЮMoney',
            8010 => 'бот и колбэки',
            8011 => 'API приложения',
        );
        $out = array();
        foreach ($ports as $port => $what) {
            $started = microtime(true);
            $response = wp_remote_get('http://' . $host . ':' . $port . '/', array('timeout' => 8));
            $ms = (int) round((microtime(true) - $started) * 1000);
            if (is_wp_error($response)) {
                $out[$port] = array('что' => $what, 'ответ' => $response->get_error_message(), 'мс' => $ms);
                continue;
            }
            // Любой HTTP-код — уже ответ: порт слушается, служба жива.
            $out[$port] = array(
                'что'  => $what,
                'код'  => (int) wp_remote_retrieve_response_code($response),
                'мс'   => $ms,
            );
        }
        return $out;
    }

    /** Платежи бота идут дальше по прежнему адресу — его логику не трогаем. */
    private static function forward_external($params) {
        $url = trim((string) get_option(self::OPT_FORWARD, ''));
        if ($url === '') {
            return array('ok' => false, 'message' => 'адрес пересылки не задан');
        }
        $response = wp_remote_post($url, array(
            'timeout' => 20,
            'headers' => array('Content-Type' => 'application/x-www-form-urlencoded'),
            'body'    => $params,
        ));
        if (is_wp_error($response)) {
            return array('ok' => false, 'message' => $response->get_error_message());
        }
        // Приёмник бота отвечает «OK» и на мусор, поэтому по одному коду
        // не понять, зачислил он или выбросил. Записываем ответ целиком —
        // иначе разбираться приходится вслепую, как в прошлый раз.
        $code = (int) wp_remote_retrieve_response_code($response);
        $body = trim(wp_strip_all_tags((string) wp_remote_retrieve_body($response)));
        $message = 'код ' . $code;
        if ($body !== '') {
            $message .= ': ' . mb_substr($body, 0, 120);
        }
        return array('ok' => $code < 300, 'message' => $message);
    }

    /* ---------------------------------------------------------------------
     * Журнал и статистика по сервисам
     * ------------------------------------------------------------------ */

    /** Из назначения платежа достаём метку вида [src:vocal]. */
    public static function source_from($params, $label = '') {
        $target = (string) ($params['targets'] ?? '');
        if (preg_match('~\[src:([a-z0-9_-]{2,20})\]~i', $target, $m)) {
            return strtolower($m[1]);
        }

        // Метку [src:] ставит только окно пополнения баланса. У остальных
        // сервисов ссылку на оплату собирает GS_Pay, и опознать платёж можно
        // по номеру заказа: он начинается с имени сервиса. Без этого всё,
        // кроме пополнений, валилось в «неизвестно».
        $label = (string) ($label !== '' ? $label : ($params['label'] ?? ''));
        $by_prefix = array(
            'proekt_' => 'proekt',
            'legal_'  => 'legal',
            'gift_'   => 'gift',
            'wheel_'  => 'wheel',
            'photo_'  => 'photo',
            'course_' => 'course',
            'slides_' => 'slides',
        );
        foreach ($by_prefix as $prefix => $source) {
            if (stripos($label, $prefix) === 0) {
                return $source;
            }
        }

        // Платежи из бота приходят со своим номером заказа — его формат
        // знает платёжный модуль, он же помнит, с какой страницы пришёл
        // человек, если тот платил на сайте.
        if (class_exists('GS_Payments')) {
            $known = GS_Payments::payment_source_of($label);
            if ($known !== '') {
                return $known;
            }
        }
        return '';
    }

    private static function remember($label, $amount, $status, $message, $params, $source = '') {
        if ($source === '') {
            $source = self::source_from($params, $label);
        }
        if ($source === '') {
            // Ключом, а не словом: по слову строку не открыть — в адресе
            // кириллица не переживает очистку параметра.
            $source = 'unknown';
        }

        $log = get_option(self::OPT_LOG, array());
        if (!is_array($log)) {
            $log = array();
        }
        $row = array(
            'at'      => current_time('mysql'),
            'label'   => (string) $label,
            'amount'  => (float) $amount,
            'status'  => (string) $status,
            'message' => (string) $message,
            'source'  => (string) $source,
        );

        // У неудачных уведомлений храним сам пакет. Подпись ЮMoney считается
        // по полям уведомления, поэтому сохранённый пакет можно переслать
        // позже — он так и останется подписанным. Без этого единственным
        // следом платежа оставалась строка ошибки, и дозачислять приходилось
        // руками, на доверии к скриншоту из кошелька.
        if (!in_array($status, array('зачислено', 'переслано'), true) && is_array($params)) {
            $row['params'] = $params;
        }
        array_unshift($log, $row);

        // Пакеты занимают место, поэтому держим их только у свежих записей:
        // платёж, не дошедший месяц назад, пересылать уже некуда.
        $log = array_slice($log, 0, self::LOG_LIMIT);
        foreach ($log as $i => $kept) {
            if ($i >= self::KEEP_PARAMS && isset($kept['params'])) {
                unset($log[$i]['params']);
            }
        }
        update_option(self::OPT_LOG, $log, false);

        if (in_array($status, array('зачислено', 'переслано'), true) && $amount > 0) {
            $stats = get_option(self::OPT_STATS, array());
            if (!is_array($stats)) {
                $stats = array();
            }
            if (empty($stats[$source])) {
                $stats[$source] = array('count' => 0, 'sum' => 0.0);
            }
            $stats[$source]['count']++;
            $stats[$source]['sum'] = round((float) $stats[$source]['sum'] + $amount, 2);
            update_option(self::OPT_STATS, $stats, false);

            // Доход в Метрику — только отложить. Уведомление ЮMoney должно
            // закрыться быстро и не зависеть от того, ответила ли Метрика:
            // при таймауте кошелёк повторит уведомление, а платёж уже
            // зачислен.
            if ($status === 'зачислено' && class_exists('GS_Metrika')) {
                GS_Metrika::queue_payment((string) $label, (float) $amount);
            }
        }
    }

    public static function get_log() {
        $log = get_option(self::OPT_LOG, array());
        return is_array($log) ? $log : array();
    }

    /**
     * Платежи одного сервиса.
     *
     * Сводка отвечает «сколько», но не отвечает «кто и когда»: чтобы
     * разобрать спорный платёж, приходилось лезть в кошелёк. Здесь тот же
     * журнал, отфильтрованный по сервису и дополненный тем, что о платеже
     * знает сайт — кто платил и был ли до этого бесплатный пробник.
     */
    public static function log_for($source) {
        $source = (string) $source;
        $rows = array();
        foreach (self::get_log() as $row) {
            if ((string) ($row['source'] ?? '') !== $source) {
                continue;
            }
            $label = (string) ($row['label'] ?? '');
            $who = 0;
            $trial = '';
            if (class_exists('GS_Payments') && $label !== '') {
                $who = (int) GS_Payments::payment_user_of($label);
                $trial = (string) GS_Payments::payment_trial_of($label);
            }
            $row['user_id'] = $who;
            $row['user'] = $who > 0 ? self::user_title($who) : '';
            $row['trial'] = $trial;
            $rows[] = $row;
        }
        return $rows;
    }

    /** Кто платил: показываем логин и почту, а не голый номер. */
    private static function user_title($user_id) {
        $user = get_userdata((int) $user_id);
        if (!$user) {
            return '#' . (int) $user_id;
        }
        $mail = (string) $user->user_email;
        return $user->user_login . ($mail !== '' ? ' · ' . $mail : '');
    }

    /** Сколько денег принёс сервис за всё время — по журналу, а не по счётчику. */
    public static function totals_for($source) {
        $sum = 0.0;
        $ok = 0;
        foreach (self::log_for($source) as $row) {
            if (in_array((string) $row['status'], array('зачислено', 'переслано'), true)) {
                $sum += (float) $row['amount'];
                $ok++;
            }
        }
        return array('count' => $ok, 'sum' => round($sum, 2));
    }

    public static function get_stats() {
        $stats = get_option(self::OPT_STATS, array());
        if (!is_array($stats)) {
            return array();
        }
        uasort($stats, function ($a, $b) {
            return (float) $b['sum'] <=> (float) $a['sum'];
        });
        return $stats;
    }
}
