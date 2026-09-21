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
        $response->set_data($body);
        return $response;
    }
}
