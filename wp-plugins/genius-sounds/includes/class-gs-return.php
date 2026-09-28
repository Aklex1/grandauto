<?php
/**
 * Возврат на страницу, с которой человек пошёл входить.
 *
 * Плагин озвучки после любого способа входа ведёт на /tts-dashboard/:
 * в обработчике Телеграма и ВК адрес кабинета зашит прямо перед
 * wp_redirect(), а вход по коду из письма отдаёт тот же адрес в ответе
 * маршрута. Человек, который нажал «Войти» на странице удаления вокала,
 * попадал в кабинет озвучки и оттуда уже не возвращался.
 *
 * Чинится в двух местах, не трогая чужой плагин:
 *
 * 1. Запоминаем адрес в куке в момент нажатия на вход. Именно нажатия, а не
 *    каждого просмотра: иначе кука уводила бы человека с той страницы, куда он
 *    пришёл нарочно. Пишем куку из браузера, а не из PHP, потому что для
 *    гостя страницы отдаются из кеша и PHP на них не выполняется.
 *
 * 2. Подменяем адрес перехода: фильтром wp_redirect — для Телеграма и ВК,
 *    фильтром ответа маршрута — для кода из письма. Подменяем только когда
 *    человек уже вошёл и его ведут именно на страницу входа или в кабинет,
 *    иначе гостя, которого кабинет разворачивает на вход, мы бы зацикливали.
 */

if (!defined('ABSPATH')) {
    exit;
}

class GS_Return {

    /** Сколько живёт память о странице. Дорога через Телеграм небыстрая. */
    const TTL = 1800;

    public static function boot() {
        add_filter('wp_redirect', array(__CLASS__, 'filter_redirect'), 5, 2);
        add_filter('rest_request_after_callbacks', array(__CLASS__, 'filter_rest_login'), 20, 3);
        add_action('template_redirect', array(__CLASS__, 'forget_on_arrival'), 20);
        add_action('wp_footer', array(__CLASS__, 'print_script'), 99);
    }

    /* ---------------------------------------------------------------------
     * Подмена адреса перехода
     * ------------------------------------------------------------------ */

    /**
     * Телеграм и ВК: оба обработчика чужого плагина заканчиваются
     * wp_redirect() на кабинет, и оба к этому моменту уже посадили
     * пользователя в сессию — на это и опираемся.
     */
    public static function filter_redirect($location, $status = 302) {
        if (is_admin() || wp_doing_ajax() || !is_user_logged_in()) {
            return $location;
        }
        $target = self::target();
        if ($target === '' || !self::is_auth_landing($location)) {
            return $location;
        }
        self::forget();
        if (self::path_of($target) === self::path_of($location)) {
            return $location;
        }
        return $target;
    }

    /**
     * Код из письма: маршрут отдаёт адрес перехода в ответе, страница по
     * нему и уходит. Куку гасим заголовком — редиректа здесь нет, и
     * template_redirect на этом запросе уже не сработает.
     */
    public static function filter_rest_login($response, $handler, $request) {
        if (!($request instanceof WP_REST_Request) || is_wp_error($response)) {
            return $response;
        }
        if (strpos((string) $request->get_route(), '/tts/v1/email/verify-code') === false) {
            return $response;
        }
        if (!($response instanceof WP_REST_Response)) {
            return $response;
        }
        $data = $response->get_data();
        if (!is_array($data) || empty($data['redirect'])) {
            return $response;
        }
        $target = self::target();
        if ($target === '' || self::path_of($target) === self::path_of((string) $data['redirect'])) {
            return $response;
        }
        $data['redirect'] = $target;
        $response->set_data($data);
        $response->header('Set-Cookie', GS_Pages::RETURN_COOKIE . '=; Path=/; Max-Age=0');
        return $response;
    }

    /**
     * Человек доехал — память больше не нужна.
     *
     * Без этого кука доживала бы свои полчаса и могла развернуть уже
     * вошедшего пользователя, который сам зашёл в кабинет.
     */
    public static function forget_on_arrival() {
        if (is_admin() || !is_user_logged_in() || empty($_COOKIE[GS_Pages::RETURN_COOKIE])) {
            return;
        }
        $target = self::target();
        if ($target === '' || self::path_of($target) === self::path_of(self::current_url())) {
            self::forget();
        }
    }

    /* ---------------------------------------------------------------------
     * Память о странице
     * ------------------------------------------------------------------ */

    /** Куда возвращать. Пустая строка — некуда. */
    public static function target() {
        if (empty($_COOKIE[GS_Pages::RETURN_COOKIE])) {
            return '';
        }
        $url = esc_url_raw(trim((string) wp_unslash($_COOKIE[GS_Pages::RETURN_COOKIE])));
        if ($url === '') {
            return '';
        }
        $host = wp_parse_url($url, PHP_URL_HOST);
        $home = wp_parse_url(home_url('/'), PHP_URL_HOST);
        if ($host === null || strcasecmp((string) $host, (string) $home) !== 0) {
            return '';
        }
        // Возвращать на сам вход бессмысленно.
        return self::is_auth_landing($url) ? '' : $url;
    }

    private static function forget() {
        if (!headers_sent()) {
            setcookie(GS_Pages::RETURN_COOKIE, '', time() - 3600, '/', '', is_ssl(), false);
        }
        unset($_COOKIE[GS_Pages::RETURN_COOKIE]);
    }

    /* ---------------------------------------------------------------------
     * Что считать страницей входа
     * ------------------------------------------------------------------ */

    /** Страницы, на которые людей приводит чужой плагин после входа. */
    private static function landing_paths() {
        $paths = array();
        $options = array(
            'kie_tts_dashboard_page_id',
            'kie_tts_auth_page_id',
            'kie_tts_login_page_id',
            'kie_tts_register_page_id',
        );
        foreach ($options as $option) {
            $id = (int) get_option($option);
            if ($id <= 0) {
                continue;
            }
            $link = get_permalink($id);
            if ($link) {
                $paths[] = self::path_of($link);
            }
        }
        // Если страницы не заведены опциями — работают адреса по умолчанию.
        $paths[] = '/tts-dashboard';
        $paths[] = '/tts-login';
        $paths[] = '/tts-register';
        return array_values(array_unique(array_filter($paths)));
    }

    private static function is_auth_landing($url) {
        return in_array(self::path_of($url), self::landing_paths(), true);
    }

    private static function path_of($url) {
        $path = (string) wp_parse_url((string) $url, PHP_URL_PATH);
        if ($path === '') {
            $path = '/';
        }
        return untrailingslashit($path);
    }

    private static function current_url() {
        $host = isset($_SERVER['HTTP_HOST']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_HOST'])) : '';
        $path = isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])) : '/';
        if ($host === '') {
            return home_url('/');
        }
        return (is_ssl() ? 'https://' : 'http://') . $host . $path;
    }

    /* ---------------------------------------------------------------------
     * Запоминание в браузере
     * ------------------------------------------------------------------ */

    /**
     * Крохотный скрипт в подвале: запомнить страницу при нажатии на вход.
     *
     * Отдельным файлом делать нечего — это двадцать строк, которые нужны на
     * каждой странице сайта, а лишний запрос стоит дороже самого скрипта.
     * Слушаем на перехвате (true), потому что наше же окно входа отменяет
     * нажатие на ссылках входа и до обычного слушателя дело не доходит.
     */
    public static function print_script() {
        if (is_user_logged_in() || is_admin()) {
            return;
        }
        $cookie = GS_Pages::RETURN_COOKIE;
        ?>
<script id="gs-return">
(function () {
    var SEL = '[data-gs-auth],#karm-open-btn,#karm-open-topbar,#open-auth-required,'
        + '.kie-tts-auth-required,.kie-auth-open-trigger,.telegram-auth-btn';
    var LOGIN = /\/(tts-login|tts-register|tts-dashboard)(\/|$|\?)/;
    if (LOGIN.test(location.pathname)) { return; }
    function remember() {
        try {
            document.cookie = '<?php echo esc_js($cookie); ?>=' + encodeURIComponent(location.href)
                + '; path=/; max-age=<?php echo (int) self::TTL; ?>; samesite=lax'
                + (location.protocol === 'https:' ? '; secure' : '');
        } catch (e) {}
    }
    function starts(el) {
        if (!el || !el.closest) { return false; }
        if (el.closest(SEL)) { return true; }
        var link = el.closest('a[href]');
        if (!link) { return false; }
        var href = link.getAttribute('href') || '';
        if (href.indexOf('t.me/') !== -1) { return href.indexOf('start=auth') !== -1; }
        return LOGIN.test(href);
    }
    document.addEventListener('click', function (e) {
        if (starts(e.target)) { remember(); }
    }, true);
})();
</script>
        <?php
    }
}
