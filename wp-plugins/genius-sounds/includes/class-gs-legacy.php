<?php
/**
 * Остатки удалённого магазина.
 *
 * WooCommerce с сайта убран, а его страницы остались и отдавали 200:
 * /my-account/ печатала голый текст «[woocommerce_my_account]» — шорткод
 * некому было разобрать, — /cart/, /checkout/ и /shop/ стояли пустыми.
 * Все четыре лежали в карте сайта, то есть поисковик их обходил и держал
 * в индексе как тонкие страницы.
 *
 * «Мой аккаунт» уводим в настоящий кабинет: по этому адресу приходят с
 * закладок и из старых писем, и человеку нужен именно личный кабинет, а
 * не сообщение об ошибке. Остальным трём замены нет — отдаём 410: это
 * прямой ответ «страницы больше не будет», и поисковик выкидывает её
 * быстрее, чем по 404.
 *
 * Перехват идёт по адресу, а не по записи, поэтому работает и после того,
 * как сами страницы убраны в корзину.
 */

if (!defined('ABSPATH')) {
    exit;
}

class GS_Legacy {

    /** Куда уводим «Мой аккаунт». */
    const ACCOUNT_TARGET = 'tts-dashboard';

    /** Страницы магазина, которым замены нет. */
    private static $gone = array('cart', 'checkout', 'shop');

    public static function boot() {
        // Раньше всего: до того, как тема начнёт рисовать страницу.
        add_action('template_redirect', array(__CLASS__, 'handle'), 0);
    }

    private static function path() {
        $uri = isset($_SERVER['REQUEST_URI']) ? (string) wp_unslash($_SERVER['REQUEST_URI']) : '';
        return strtolower(trim((string) wp_parse_url($uri, PHP_URL_PATH), '/'));
    }

    public static function handle() {
        if (is_admin()) {
            return;
        }
        $path = self::path();
        if ($path === '') {
            return;
        }

        if ($path === 'my-account') {
            wp_safe_redirect(home_url('/' . self::ACCOUNT_TARGET . '/'), 301);
            exit;
        }

        if (in_array($path, self::$gone, true)) {
            self::gone();
        }
    }

    /**
     * 410 вместо 404.
     *
     * Разница не косметическая: 404 значит «сейчас нет, может появиться»,
     * и адрес ещё долго перепроверяют. 410 значит «удалено навсегда».
     */
    private static function gone() {
        status_header(410);
        nocache_headers();
        header('Content-Type: text/html; charset=utf-8');
        $home = esc_url(home_url('/'));
        echo '<!doctype html><html lang="ru"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<meta name="robots" content="noindex,nofollow">'
            . '<title>Страница удалена</title>'
            . '<style>body{margin:0;min-height:100vh;display:flex;align-items:center;'
            . 'justify-content:center;background:#080c14;color:#94a3b8;'
            . 'font:16px/1.7 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif;'
            . 'text-align:center;padding:24px}'
            . 'h1{margin:0 0 10px;color:#f1f5f9;font-size:26px}'
            . 'a{color:#818cf8}</style></head><body><div>'
            . '<h1>Этой страницы больше нет</h1>'
            . '<p>Магазин на сайте закрыт.<br>'
            . '<a href="' . $home . '">Перейти на главную</a></p>'
            . '</div></body></html>';
        exit;
    }
}
