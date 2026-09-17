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

    public static function boot() {
        add_action('init', array(__CLASS__, 'remember_source'), 5);
        add_action('template_redirect', array(__CLASS__, 'remember_page_source'), 1);
        add_action('wp_footer', array(__CLASS__, 'print_modal'));
        add_filter('rest_request_after_callbacks', array(__CLASS__, 'mark_payment'), 20, 3);
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
                    <?php foreach (array(200, 300, 500, 1000) as $sum): ?>
                        <button type="button" class="gs-topup__sum" data-gs-topup-sum="<?php echo (int) $sum; ?>">
                            <?php echo esc_html(number_format_i18n($sum)); ?> ₽
                        </button>
                    <?php endforeach; ?>
                </div>

                <div class="gs-topup__own">
                    <label class="gs-label" for="gs-topup-own">Своя сумма</label>
                    <div class="gs-topup__row">
                        <input id="gs-topup-own" class="gs-input" type="number" min="50" max="100000" step="50" placeholder="от 50 ₽">
                        <button type="button" class="gs-btn gs-btn--ghost" data-gs-topup-own>Получить ссылку</button>
                    </div>
                </div>

                <p class="gs-topup__note" id="gs-topup-note" role="status" aria-live="polite"></p>
                <a class="gs-btn gs-btn--primary gs-btn--lg gs-topup__go" id="gs-topup-go" href="#" target="_blank" rel="noopener" hidden>Перейти к оплате</a>
                <p class="gs-topup__hint">После оплаты баланс обновится сам — страницу закрывать не нужно.</p>
            </div>
        </div>
        <?php
    }

    /** Человеческие названия сервисов для назначения платежа. */
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
