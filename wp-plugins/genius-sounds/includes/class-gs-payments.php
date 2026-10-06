<?php
/**
 * Пометка платежей по сервисам.
 *
 * Баланс у пользователя один на все инструменты, и это правильно: пополнил
 * раз — тратишь везде. Но тогда по платежам не видно, какой сервис привёл
 * деньги. Поэтому в назначение платежа добавляется метка сервиса, с которого
 * человек пришёл пополняться: в выписке ЮMoney и в вебхуке она видна, а
 * идентификатор платежа остаётся нетронутым — сверка на стороне сайта
 * работает как работала.
 */

if (!defined('ABSPATH')) {
    exit;
}

class GS_Payments {

    const QUERY_ARG = 'gs_src';
    const COOKIE    = 'gs_pay_src';
    const TTL       = 3600;

    /** Секрет HTTP-уведомлений ЮMoney — пока пуст, подпись не проверяется. */
    const OPT_SECRET = 'gs_yoomoney_secret';
    /** Маршрут, на который ЮMoney шлёт уведомления об оплате. */
    const HOOK_ROUTE = '/tts/v1/yoomoney-webhook';

    public static function boot() {
        add_action('init', array(__CLASS__, 'remember_source'), 5);
        add_action('template_redirect', array(__CLASS__, 'remember_page_source'), 1);
        add_action('wp_footer', array(__CLASS__, 'print_modal'));
        add_filter('rest_request_after_callbacks', array(__CLASS__, 'mark_payment'), 20, 3);
        add_filter('rest_pre_dispatch', array(__CLASS__, 'guard_notification'), 10, 3);
        add_action('admin_post_gs_payment_credit', array(__CLASS__, 'handle_credit'));
        add_action('admin_post_gs_balance_adjust', array(__CLASS__, 'handle_adjust'));
        add_action('admin_post_gs_botdb_save', array(__CLASS__, 'handle_botdb_save'));
        add_action('admin_post_gs_bot_payment_close', array(__CLASS__, 'handle_bot_payment_close'));
        add_action('admin_post_gs_purge_cache', array(__CLASS__, 'handle_purge_cache'));
    }

    /* ---------------------------------------------------------------------
     * Уведомление об оплате
     * ------------------------------------------------------------------ */

    /**
     * Проверка подписи уведомления ЮMoney.
     *
     * Платёжный маршрут зачисляет баланс по одной лишь метке платежа, а
     * метка складывается из номера пользователя и времени — подобрать её
     * может кто угодно. Пока секрет не задан, ломать приём уведомлений
     * нельзя: без него магазин просто не получит денег. Поэтому проверка
     * включается ровно тогда, когда секрет появился в настройках.
     */
    public static function guard_notification($result, $server, $request) {
        if (!($request instanceof WP_REST_Request) || $request->get_route() !== self::HOOK_ROUTE) {
            return $result;
        }
        // Единая точка приёма уже проверила подпись и сейчас разбирает
        // уведомление внутри сайта — второй раз проверять нечего.
        if (class_exists('GS_Yoomoney') && GS_Yoomoney::is_forwarding()) {
            return $result;
        }
        $secret = trim((string) get_option(self::OPT_SECRET, ''));
        if ($secret === '') {
            return $result;
        }

        $post = $request->get_body_params();
        $post = is_array($post) ? $post : array();
        if (!class_exists('GS_Yoomoney') || !GS_Yoomoney::signature_ok($post)) {
            error_log('genius-sounds: уведомление ЮMoney с неверной подписью, метка '
                . (isset($post['label']) ? (string) $post['label'] : '—'));
            return new WP_REST_Response(array('status' => 'error', 'message' => 'bad signature'), 403);
        }
        return $result;
    }

    /* ---------------------------------------------------------------------
     * Разбор застрявших платежей
     * ------------------------------------------------------------------ */

    /**
     * Платежи, за которые деньги могли прийти, а баланс не пополнился.
     *
     * Балансов на сайте два — кабинета озвучки и раздела «Нейросети», и
     * платежи у них в разных таблицах. Разбираться с застрявшим платежом
     * владельцу приходится в обоих случаях, поэтому и список общий.
     */
    public static function pending_payments($limit = 40) {
        global $wpdb;
        $rows = array();

        $table = $wpdb->prefix . 'kie_tts_payments';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table) {
            foreach ((array) $wpdb->get_results($wpdb->prepare(
                "SELECT user_id, label, amount, status, is_telegram, created_at
                   FROM {$table} WHERE status <> 'completed'
               ORDER BY created_at DESC LIMIT %d", (int) $limit), ARRAY_A) as $row) {
                $row['kind'] = 'tts';
                $rows[] = $row;
            }
        }

        $nh = $wpdb->prefix . 'kie_neurohub_payments';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $nh)) === $nh) {
            foreach ((array) $wpdb->get_results($wpdb->prepare(
                "SELECT id, user_key, amount, status, created_at
                   FROM {$nh} WHERE status <> 'completed'
               ORDER BY created_at DESC LIMIT %d", (int) $limit), ARRAY_A) as $row) {
                $rows[] = array(
                    'kind'        => 'neurohub',
                    'user_id'     => 0,
                    'user_key'    => (string) $row['user_key'],
                    'label'       => 'kie-neurohub|' . (int) $row['id'] . '|' . $row['user_key']
                                     . '|' . number_format((float) $row['amount'], 2, '.', ''),
                    'amount'      => $row['amount'],
                    'status'      => $row['status'],
                    'is_telegram' => 0,
                    'created_at'  => $row['created_at'],
                );
            }
        }

        usort($rows, function ($a, $b) {
            return strcmp((string) $b['created_at'], (string) $a['created_at']);
        });
        return array_slice($rows, 0, (int) $limit);
    }

    public static function completed_count() {
        global $wpdb;
        $table = $wpdb->prefix . 'kie_tts_payments';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
            return 0;
        }
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status = 'completed'");
    }

    /** Последнее зачисление — по нему видно, когда уведомления перестали приходить. */
    public static function last_completed() {
        global $wpdb;
        $table = $wpdb->prefix . 'kie_tts_payments';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
            return '';
        }
        return (string) $wpdb->get_var("SELECT completed_at FROM {$table} WHERE status = 'completed' ORDER BY completed_at DESC LIMIT 1");
    }

    /**
     * Ручное зачисление.
     *
     * Нужно, когда деньги на кошелёк пришли, а уведомление — нет: без этой
     * кнопки единственный способ помочь человеку — лезть в базу руками.
     * Зачисление идёт через тот же метод платёжного плагина, что и
     * обычное, поэтому история и статусы остаются согласованными.
     */
    /** Журнал ручных правок баланса. */
    const OPT_ADJUST_LOG = 'gs_balance_adjust_log';
    const ADJUST_KEEP    = 50;

    /**
     * Ручная правка баланса.
     *
     * Это не платёж, и в журнал платежей правка не попадает: денег не
     * приходило. Нужна она для честных случаев — вернуть человеку за сбой,
     * которого не заметил автомат, или положить на проверку нового сервиса.
     * Раньше такого инструмента не было, и единственным способом что-то
     * поправить оставалось провести несуществующий платёж, то есть соврать
     * в учёте.
     *
     * Каждая правка пишется в журнал: кто сделал, кому, сколько и зачем.
     * Инструмент «дать себе денег» без такого следа заводить нельзя.
     */
    public static function handle_adjust() {
        if (!current_user_can('manage_options')) {
            wp_die('Недостаточно прав');
        }
        check_admin_referer('gs_balance_adjust');

        $who = sanitize_text_field(wp_unslash((string) ($_POST['who'] ?? '')));
        $amount = round((float) ($_POST['amount'] ?? 0), 2);
        $reason = sanitize_text_field(wp_unslash((string) ($_POST['reason'] ?? '')));

        $user = is_numeric($who) ? get_user_by('id', (int) $who) : get_user_by('login', $who);
        if (!$user) {
            $user = get_user_by('email', $who);
        }

        $done = false;
        if (!$user) {
            $message = 'Не нашёл такого пользователя';
        } elseif ($reason === '') {
            $message = 'Без причины правку не делаем';
        } elseif ($amount == 0) {
            $message = 'Сумма не может быть нулевой';
        } else {
            // Знак решает, что делаем: плюс кладёт на баланс, минус списывает.
            $done = $amount > 0
                ? GS_SFX::refund((int) $user->ID, $amount)
                : GS_SFX::charge((int) $user->ID, abs($amount), 'admin');
            $message = $done ? 'Баланс изменён' : 'Не удалось изменить баланс';
        }

        if ($done) {
            $log = get_option(self::OPT_ADJUST_LOG, array());
            if (!is_array($log)) {
                $log = array();
            }
            array_unshift($log, array(
                'time'    => current_time('mysql'),
                'by'      => wp_get_current_user()->user_login,
                'user'    => $user->user_login,
                'amount'  => $amount,
                'reason'  => $reason,
                'balance' => class_exists('KIE_TTS_DB')
                    ? (float) KIE_TTS_DB::get_user_balance((int) $user->ID)
                    : 0.0,
            ));
            update_option(self::OPT_ADJUST_LOG, array_slice($log, 0, self::ADJUST_KEEP), false);
        }

        set_transient('gs_adjust_notice', $message, 60);
        wp_safe_redirect(admin_url('admin.php?page=genius-sounds') . '#gs-balance-adjust');
        exit;
    }

    /**
     * Сбросить кэш страниц.
     *
     * Страницы каталога не записи, и обычный сброс кэша записи их не
     * касается: отдаются они из кэша по адресу. Из-за этого правка
     * заголовков и описаний может неделями не доезжать до выдачи — я сам
     * на это попался, проверяя исправленный заголовок и видя старый.
     *
     * Плагин кэша на сайте сторонний и в разных версиях зовётся по-разному,
     * поэтому пробуем все известные имена и говорим, что сработало.
     */
    public static function handle_purge_cache() {
        if (!current_user_can('manage_options')) {
            wp_die('Недостаточно прав');
        }
        check_admin_referer('gs_purge_cache');

        $done = array();
        $failed = array();
        foreach (array('wp_cache_clear_cache', 'wpsc_delete_files', 'rocket_clean_domain',
                       'w3tc_flush_all', 'ce_clear_cache', 'litespeed_purge_all') as $fn) {
            if (!function_exists($fn)) {
                continue;
            }
            // У части этих функций есть обязательные аргументы: вызов без
            // них в PHP 8 роняет страницу целиком. Зовём только те, что
            // работают без параметров.
            try {
                $ref = new ReflectionFunction($fn);
                if ($ref->getNumberOfRequiredParameters() > 0) {
                    $failed[] = $fn . ' (нужны аргументы)';
                    continue;
                }
                $fn();
                $done[] = $fn;
            } catch (Throwable $e) {
                $failed[] = $fn . ' (' . $e->getMessage() . ')';
            }
        }
        try {
            wp_cache_flush();
            $done[] = 'wp_cache_flush';
        } catch (Throwable $e) {
            $failed[] = 'wp_cache_flush';
        }

        $message = $done
            ? 'ok: кэш сброшен (' . implode(', ', $done) . ')'
            : 'Плагин кэша не найден — сбрасывать нечего';
        if ($failed) {
            $message .= '. Пропущено: ' . implode(', ', $failed);
        }
        set_transient('gs_payment_notice', $message, 60);
        wp_safe_redirect(admin_url('admin.php?page=genius-sounds') . '#gs-payments-stuck');
        exit;
    }

    /**
     * Закрыть один платёж в базе бота и начислить его токены.
     *
     * Делает то же, что приёмник пополнений: берёт токены из самой записи
     * платежа, переводит её из pending в completed и прибавляет баланс.
     * Сумма не приходит снаружи — она в платеже, поэтому начислить больше
     * оплаченного нельзя.
     *
     * Порядок важен: сначала перевод статуса, потом деньги. Если строк не
     * затронуто, значит платёж уже закрыт — кем-то другим или прошлым
     * запуском, — и баланс не трогаем. Отсюда безопасность повторов: хоть
     * из админки, хоть из обработчика уведомлений, хоть обоими сразу.
     *
     * @param string     $label     Метка платежа.
     * @param float|null $paid      Сколько пришло по уведомлению; при
     *                              расхождении с платежом начисления не будет.
     * @param int        $expect_tg Чей платёж ожидаем. Ноль — не проверять.
     */
    public static function close_bot_payment($label, $paid = null, $expect_tg = 0) {
        if (!class_exists('KIE_TTS_DB')) {
            return array('ok' => false, 'message' => 'плагин озвучки не загружен');
        }
        $conn = KIE_TTS_DB::get_bot_connection();
        if (!$conn) {
            return array('ok' => false, 'message' => 'нет связи с базой бота');
        }

        $stmt = $conn->prepare('SELECT telegram_id, tokens, amount, status FROM payments WHERE label = ?');
        $stmt->bind_param('s', $label);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) {
            $conn->close();
            return array('ok' => false, 'message' => 'платежа с такой меткой в базе бота нет');
        }

        // Метка могла прийти от другого человека — например, при опечатке
        // в админке. Закрывать её можно, но тогда деньги уйдут владельцу
        // платежа, а в журнале окажется номер, который набрали. Лучше
        // отказать и сказать, в чём дело.
        if ($expect_tg > 0 && (int) $row['telegram_id'] !== $expect_tg) {
            $conn->close();
            return array('ok' => false, 'message' => 'это платёж другого человека (telegram_id '
                . (int) $row['telegram_id'] . ')');
        }

        // Защита от накрутки: уплачено должно сойтись с тем, что ждали.
        // Копейки комиссии допускаем, разницу в сумме — нет.
        $expected = (float) $row['amount'];
        if ($paid !== null && $expected > 0 && abs((float) $paid - $expected) > 0.01) {
            $conn->close();
            return array('ok' => false, 'message' => sprintf(
                'сумма не сошлась: пришло %s, платёж на %s',
                number_format((float) $paid, 2, ',', ' '), number_format($expected, 2, ',', ' ')));
        }

        $stmt = $conn->prepare("UPDATE payments SET status='completed' WHERE label = ? AND status='pending'");
        $stmt->bind_param('s', $label);
        $stmt->execute();
        $changed = $stmt->affected_rows;
        $stmt->close();
        $conn->close();

        if ($changed !== 1) {
            return array('ok' => false, 'already' => true,
                'message' => 'платёж уже закрыт (' . (string) $row['status'] . ')');
        }

        $tg = (int) $row['telegram_id'];
        $tokens = (float) $row['tokens'];
        KIE_TTS_DB::update_user_balance($tg, $tokens, true);

        return array(
            'ok'          => true,
            'telegram_id' => $tg,
            'tokens'      => $tokens,
            'message'     => 'зачислено ' . number_format($tokens, 2, ',', ' ') . ' в базе бота',
        );
    }

    /**
     * Закрыть платёж бота, до которого не дошло уведомление.
     *
     * Делает ровно то же, что сделал бы приёмник пополнений: берёт токены
     * из самой записи платежа, ставит completed и прибавляет баланс в базе
     * бота. Суммы не выдумываются — они из платежа.
     *
     * Закрываем поимённо, по меткам, а не «все незакрытые»: человек часто
     * жмёт «пополнить» несколько раз подряд, и в базе висит четыре записи
     * на две реальные оплаты. Закрыть всё — значит подарить разницу.
     *
     * Баланс прибавляется только если платёж удалось перевести из pending
     * в completed. Строк не затронуто — значит его уже закрыли, и второго
     * начисления не будет: повторный запуск безопасен.
     */
    public static function handle_bot_payment_close() {
        if (!current_user_can('manage_options')) {
            wp_die('Недостаточно прав');
        }
        check_admin_referer('gs_bot_payment_close');

        $tg = (int) ($_POST['telegram_id'] ?? 0);
        $raw = (string) wp_unslash((string) ($_POST['labels'] ?? ''));
        $labels = array_filter(array_map('trim', preg_split('~[\s,]+~', $raw)));

        if ($tg <= 0 || !$labels) {
            set_transient('gs_payment_notice', 'Нужны номер в телеграме и хотя бы одна метка', 60);
            wp_safe_redirect(admin_url('admin.php?page=genius-sounds') . '#gs-botpay');
            exit;
        }

        $probe = class_exists('KIE_TTS_DB') ? KIE_TTS_DB::get_bot_connection() : null;
        if (!$probe) {
            set_transient('gs_payment_notice', 'Нет связи с базой бота — проверьте доступы', 60);
            wp_safe_redirect(admin_url('admin.php?page=genius-sounds') . '#gs-botpay');
            exit;
        }
        $probe->close();

        $before = class_exists('KIE_TTS_DB') ? (float) KIE_TTS_DB::get_user_balance($tg, true) : 0.0;
        $done = array();
        $skipped = array();

        foreach ($labels as $label) {
            $result = self::close_bot_payment($label, null, $tg);
            if (!empty($result['ok'])) {
                $done[] = $label . ' (+' . number_format((float) $result['tokens'], 2, ',', ' ') . ')';
            } else {
                $skipped[] = $label . ' — ' . (string) $result['message'];
            }
        }

        $after = class_exists('KIE_TTS_DB') ? (float) KIE_TTS_DB::get_user_balance($tg, true) : 0.0;

        if ($done) {
            $log = get_option(self::OPT_ADJUST_LOG, array());
            if (!is_array($log)) {
                $log = array();
            }
            array_unshift($log, array(
                'time'    => current_time('mysql'),
                'by'      => wp_get_current_user()->user_login,
                'user'    => 'telegram_' . $tg . ' (баланс бота)',
                'amount'  => $after - $before,
                'reason'  => 'закрыты платежи бота: ' . implode(', ', $done),
                'balance' => $after,
            ));
            update_option(self::OPT_ADJUST_LOG, array_slice($log, 0, self::ADJUST_KEEP), false);
        }

        $message = $done
            ? 'ok: зачислено ' . number_format($after - $before, 2, ',', ' ') . ' — баланс бота '
              . number_format($before, 2, ',', ' ') . ' → ' . number_format($after, 2, ',', ' ')
            : 'Ничего не зачислено';
        if ($skipped) {
            $message .= '. Пропущено: ' . implode('; ', $skipped);
        }

        set_transient('gs_payment_notice', $message, 120);
        wp_safe_redirect(admin_url('admin.php?page=genius-sounds') . '#gs-botpay');
        exit;
    }

    /**
     * Доступ к базе бота.
     *
     * Баланс телеграмных пользователей лежит в базе бота, и сайт умеет в неё
     * ходить — методами плагина озвучки. Но формы для этих доступов нет ни
     * у кого: значения однажды попали в базу и с тех пор неизменяемы, а
     * сейчас пароль не тот — соединение отбивается «Access denied». Из-за
     * этого жалобу «деньги ушли, в боте баланса нет» нельзя закрыть из
     * админки вообще, только командой на сервере бота.
     *
     * Поля чужие, поэтому пишем именно их, по одному, а не отправляем чужую
     * форму целиком: так соседние настройки заведомо не пострадают.
     */
    public static function handle_botdb_save() {
        if (!current_user_can('manage_options')) {
            wp_die('Недостаточно прав');
        }
        check_admin_referer('gs_botdb_save');

        // Снимок до записи: доступы чужие, и если я ошибусь полем, вернуть
        // прежние значения иначе будет нечем — формы-то у них нет.
        if (class_exists('GS_Backup')) {
            GS_Backup::snapshot('перед правкой доступов к базе бота', true);
        }

        $fields = array(
            'kie_tts_db_host' => sanitize_text_field(wp_unslash((string) ($_POST['host'] ?? ''))),
            'kie_tts_db_name' => sanitize_text_field(wp_unslash((string) ($_POST['base'] ?? ''))),
            'kie_tts_db_user' => sanitize_text_field(wp_unslash((string) ($_POST['login'] ?? ''))),
            'kie_tts_db_port' => (int) ($_POST['port'] ?? 3306),
        );
        foreach ($fields as $option => $value) {
            if ($value !== '' && $value !== 0) {
                update_option($option, $value, false);
            }
        }

        // Пароль перезаписываем только если его ввели: иначе пустое поле
        // при правке соседней строки затёрло бы рабочий доступ.
        $secret = (string) ($_POST['secret'] ?? '');
        if (trim($secret) !== '') {
            update_option('kie_tts_db_password', $secret, false);
        }

        $message = 'Доступы сохранены';
        if (class_exists('KIE_TTS_DB')) {
            $conn = KIE_TTS_DB::get_bot_connection();
            $message .= $conn ? '. Соединение с базой бота установлено' : '. Соединение не установилось — проверьте значения';
            if ($conn) {
                $conn->close();
            }
        }

        set_transient('gs_botdb_notice', $message, 60);
        wp_safe_redirect(admin_url('admin.php?page=genius-sounds') . '#gs-botdb');
        exit;
    }

    /** Последние правки — для показа в админке. */
    public static function adjust_log() {
        $log = get_option(self::OPT_ADJUST_LOG, array());
        return is_array($log) ? $log : array();
    }

    public static function handle_credit() {
        if (!current_user_can('manage_options')) {
            wp_die('Недостаточно прав');
        }
        check_admin_referer('gs_payment_credit');

        $label = sanitize_text_field(wp_unslash((string) ($_POST['label'] ?? '')));
        $done = false;

        if (strpos($label, 'kie-neurohub|') === 0) {
            // У раздела «Нейросети» своя точка приёма — зовём её так же,
            // как это сделало бы настоящее уведомление.
            $request = new WP_REST_Request('POST', '/neurohub/v1/yoomoney-callback');
            $request->set_param('label', $label);
            $parts = explode('|', $label);
            $request->set_param('amount', isset($parts[3]) ? $parts[3] : '0');
            $response = rest_do_request($request);
            $done = $response instanceof WP_REST_Response && $response->get_status() < 300;
        } elseif ($label !== '' && class_exists('KIE_TTS_Payment')) {
            $done = (bool) KIE_TTS_Payment::process_payment($label, 0);
        }
        set_transient('gs_payment_notice', $done ? 'ok:' . $label : 'fail:' . $label, 60);
        wp_safe_redirect(admin_url('admin.php?page=genius-sounds') . '#gs-payments');
        exit;
    }

    /**
     * Источник платежа по самой странице.
     *
     * Раньше метка ставилась только при переходе с ?gs_src=…, то есть при уходе
     * в кабинет. Теперь пополнение происходит на месте, и запоминать, откуда
     * человек платит, надо в момент открытия страницы сервиса.
     */
    public static function remember_page_source() {
        if (is_admin() || headers_sent()) {
            return;
        }
        $source = '';
        if (class_exists('GS_Lab')) {
            $service = GS_Lab::current_service();
            if ($service) {
                $source = (string) $service['id'];
            }
        }
        if ($source === '' && class_exists('GS_Landing')) {
            $landing = GS_Landing::current_service();
            if ($landing) {
                $source = (string) $landing['id'];
            }
        }
        if ($source === '' && class_exists('GS_Slides_Page') && GS_Slides_Page::is_page()) {
            $source = 'slides';
        }
        if ($source === '' && class_exists('GS_Pages') && GS_Pages::is_studio_request()) {
            $source = 'sfx';
        }
        if ($source === '' || !array_key_exists($source, self::sources())) {
            return;
        }
        setcookie(self::COOKIE, $source, time() + self::TTL, COOKIEPATH ?: '/', COOKIE_DOMAIN, is_ssl(), true);
        $_COOKIE[self::COOKIE] = $source;
    }

    /**
     * Суммы пополнения.
     *
     * Список обязан совпадать с тем, что принимает платёжный маршрут
     * tts/v1/topup: он проверяет сумму по своему перечню и на всё остальное
     * отвечает отказом. Раньше кнопки и этот перечень разошлись — окно
     * предлагало 1000 ₽, а оплата её не принимала. Поэтому список живёт
     * в одном месте и меняется вместе с платёжным плагином.
     */
    public static function amounts() {
        return apply_filters('gs_topup_amounts', self::allowed());
    }

    /**
     * Суммы, которые принимает платёжный маршрут.
     *
     * Базовый список зашит в платёжном плагине, и точки расширения у него
     * нет. Поэтому добавленные там суммы дублируются здесь настройкой:
     * показывать кнопку, которой платёжный маршрут не знает, нельзя —
     * кнопка, ведущая в отказ, хуже отсутствующей.
     */
    public static function allowed() {
        $base = array(200, 300, 400, 500);
        $extra = (array) get_option(self::OPT_EXTRA, array());
        $all = array_map('intval', array_merge($extra, $base));
        $all = array_values(array_unique(array_filter($all)));
        sort($all);
        return $all;
    }

    /** Нужно ли окно пополнения на этой странице. */
    public static function needs_modal() {
        if (!is_user_logged_in()) {
            return false;
        }
        if (class_exists('GS_Lab') && GS_Lab::current_service()) {
            return true;
        }
        if (class_exists('GS_Landing') && GS_Landing::current()) {
            return true;
        }
        if (class_exists('GS_Slides_Page') && GS_Slides_Page::is_page()) {
            return true;
        }
        if (class_exists('GS_Pages') && GS_Pages::is_studio_request()) {
            return true;
        }
        return false;
    }

    /**
     * Окно пополнения прямо на странице сервиса.
     *
     * Уводить человека в кабинет ради пополнения — значит терять его на
     * полпути: он уходит с формы, которую уже заполнил.
     */
    public static function print_modal() {
        if (!self::needs_modal()) {
            return;
        }
        ?>
        <div class="gs-topup" id="gs-topup" hidden>
            <div class="gs-topup__veil" data-gs-topup-close></div>
            <div class="gs-topup__box" role="dialog" aria-modal="true" aria-labelledby="gs-topup-title">
                <button type="button" class="gs-topup__x" data-gs-topup-close aria-label="Закрыть">&times;</button>
                <h2 class="gs-topup__title" id="gs-topup-title">Пополнить баланс</h2>
                <p class="gs-topup__lead">Баланс общий для всех инструментов. Оплата картой через ЮMoney.</p>

                <div class="gs-topup__amounts">
                    <?php foreach (self::amounts() as $sum): ?>
                        <button type="button" class="gs-topup__sum" data-gs-topup-sum="<?php echo (int) $sum; ?>">
                            <?php echo esc_html(number_format_i18n($sum)); ?> ₽
                        </button>
                    <?php endforeach; ?>
                </div>

                <p class="gs-topup__note" id="gs-topup-note" role="status" aria-live="polite"></p>
                <a class="gs-btn gs-btn--primary gs-btn--lg gs-topup__go" id="gs-topup-go" href="#" target="_blank" rel="noopener" hidden>Перейти к оплате</a>
                <p class="gs-topup__hint">
                    После оплаты вернитесь на эту вкладку: баланс обновится сам, закрывать её не нужно.
                    Если деньги списались, а баланс не изменился — напишите нам номер платежа, пополним вручную.
                </p>
            </div>
        </div>
        <?php
    }

    /** Человеческие названия сервисов для назначения платежа. */
    /** Суммы сверх зашитых в платёжном плагине — если он их принял. */
    const OPT_EXTRA = 'gs_topup_extra_amounts';

    public static function sources() {
        return array(
            'sfx'      => 'генератор звуков',
            'avatar'   => 'говорящий аватар',
            'vocal'    => 'минусовка',
            'denoise'  => 'очистка записи',
            'catalog'  => 'каталог звуков',
            'api'      => 'API для разработчиков',
            'neurohub' => 'нейросети для фото',
            'tts'      => 'озвучка текста',
            'music'    => 'генерация музыки',
            'stt'      => 'расшифровка записи',
            'ytaudio'  => 'звук из видео',
            'voicesong'=> 'песня своим голосом',
            'proekt'   => 'наставник по индивидуальному проекту',
            'slides'   => 'генерация презентаций',
        );
    }

    /**
     * Откуда пришёл пользователь — запоминаем на час.
     */
    public static function remember_source() {
        if (is_admin() || empty($_GET[self::QUERY_ARG])) {
            return;
        }
        $source = sanitize_key(wp_unslash((string) $_GET[self::QUERY_ARG]));
        if (!array_key_exists($source, self::sources())) {
            return;
        }
        if (!headers_sent()) {
            setcookie(self::COOKIE, $source, time() + self::TTL, COOKIEPATH ?: '/', COOKIE_DOMAIN, is_ssl(), true);
        }
        $_COOKIE[self::COOKIE] = $source;
    }

    public static function current_source() {
        $source = isset($_COOKIE[self::COOKIE]) ? sanitize_key(wp_unslash((string) $_COOKIE[self::COOKIE])) : '';
        return array_key_exists($source, self::sources()) ? $source : '';
    }

    /** Ссылка на пополнение с пометкой сервиса. */
    public static function topup_url($source) {
        $url = GS_Pages::get_dashboard_url();
        return array_key_exists($source, self::sources())
            ? add_query_arg(self::QUERY_ARG, $source, $url)
            : $url;
    }

    /* ---------------------------------------------------------------------
     * Правка назначения платежа
     * ------------------------------------------------------------------ */

    /**
     * Журнал «метка платежа → сервис».
     *
     * Назначение платежа человек видит у ЮMoney, но в самой метке сервиса
     * нет, и ответить на вопрос «кто платил из каталога звуков» по данным
     * сайта было нечем. Пишем соответствие при выдаче ссылки: это
     * единственный момент, когда известны и метка, и сервис.
     */
    const OPT_SRC_LOG = 'gs_payment_sources';
    const SRC_LOG_KEEP = 300;

    public static function note_payment_source($label, $source) {
        $label = trim((string) $label);
        if ($label === '') {
            return;
        }
        $log = get_option(self::OPT_SRC_LOG, array());
        if (!is_array($log)) {
            $log = array();
        }
        $log[$label] = array(
            'src'  => (string) $source,
            'user' => get_current_user_id(),
            'at'   => current_time('mysql'),
        );
        if (count($log) > self::SRC_LOG_KEEP) {
            $log = array_slice($log, -self::SRC_LOG_KEEP, null, true);
        }
        update_option(self::OPT_SRC_LOG, $log, false);
    }

    /** Откуда пришёл платёж с этой меткой: ключ сервиса или пустая строка. */
    public static function payment_source_of($label) {
        $log = get_option(self::OPT_SRC_LOG, array());
        return is_array($log) && isset($log[(string) $label]['src'])
            ? (string) $log[(string) $label]['src'] : '';
    }

    public static function mark_payment($response, $handler, $request) {
        if (!($request instanceof WP_REST_Request)) {
            return $response;
        }
        if (strpos((string) $request->get_route(), '/tts/v1/') === false
            || strpos((string) $request->get_route(), 'topup') === false) {
            return $response;
        }
        if (!($response instanceof WP_REST_Response)) {
            return $response;
        }
        $body = $response->get_data();
        if (!is_array($body) || empty($body['payment_link'])) {
            return $response;
        }

        $source = self::current_source();
        if ($source === '') {
            $source = 'tts';
        }
        $names = self::sources();
        $target = 'Genius-bot: пополнение баланса — ' . $names[$source] . ' [src:' . $source . ']';

        // Меняем только назначение платежа: идентификатор, по которому
        // сайт сверяет оплату, трогать нельзя.
        $body['payment_link'] = add_query_arg('targets', rawurlencode($target), remove_query_arg('targets', (string) $body['payment_link']));
        $body['source'] = $source;

        // Метку платёжный плагин уже собрал — запоминаем, из какого сервиса
        // человек пошёл платить. Саму метку не трогаем: по ней сверяется оплата.
        $query = (string) wp_parse_url((string) $body['payment_link'], PHP_URL_QUERY);
        $args = array();
        wp_parse_str($query, $args);
        if (!empty($args['label'])) {
            self::note_payment_source((string) $args['label'], $source);
        }
        $response->set_data($body);
        return $response;
    }
}
