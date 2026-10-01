<?php
/**
 * Мусор из поискового индекса.
 *
 * Вебмастер показал 23 адреса, которые попали в поиск по недоразумению:
 * архивы автора (они к тому же отвечали 500 и светили логины пользователей),
 * пустая рубрика «Без рубрики», остатки магазина и варианты страниц с
 * параметрами вида `/sound-generator/?prompt=Звуки карт`. Содержания в них
 * нет, но поиск считает их страницами сайта и по ним же судит о качестве.
 *
 * Канонический адрес у таких страниц уже стоял — этого не хватило: Яндекс
 * канониклы учитывает как пожелание. Поэтому закрываем прямо: архив автора
 * уводим редиректом, остальному ставим noindex, а роботу Яндекса отдельно
 * объявляем Clean-param — по нему он склеивает адреса с параметрами сам.
 */

if (!defined('ABSPATH')) {
    exit;
}

class GS_Noindex {

    /**
     * Параметры, которые не меняют содержимое страницы.
     *
     * Это не весь мусор, какой бывает в ссылках, а только тот, что реально
     * встретился в индексе и в наших же кнопках.
     */
    const JUNK_PARAMS = array(
        'prompt', 'add-to-cart', 'gs_page', 'x', 'ref', 'from',
        'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content',
        'yclid', 'gclid', 'ymclid', 'etext', 'fbclid',
    );

    public static function boot() {
        add_action('template_redirect', array(__CLASS__, 'retire_author'), 1);
        // Через wp_robots, а не своим тегом в wp_head: иначе на странице
        // окажется два <meta name="robots"> подряд, и какой из них считать —
        // решает робот.
        add_filter('wp_robots', array(__CLASS__, 'robots_meta'));
        add_filter('robots_txt', array(__CLASS__, 'robots'), 30, 2);
    }

    /**
     * Архив автора уводим на блог.
     *
     * Своего смысла у него нет: все статьи и так лежат в блоге. Зато в
     * адресе стоял логин пользователя, а сама страница отвечала ошибкой
     * сервера — и в таком виде попала в поиск.
     */
    public static function retire_author() {
        if (!is_author()) {
            return;
        }
        wp_safe_redirect(self::blog_url(), 301);
        exit;
    }

    public static function robots_meta($robots) {
        if (!self::junk()) {
            return $robots;
        }
        $robots['noindex'] = true;
        $robots['follow'] = true;
        unset($robots['index']);
        return $robots;
    }

    /** Стоит ли закрывать текущий адрес от поиска. */
    private static function junk() {
        if (is_admin() || is_feed() || is_404()) {
            return false;
        }

        // Страница с параметром, который не меняет содержимого: это копия
        // канонического адреса, и в поиске ей делать нечего.
        foreach (self::JUNK_PARAMS as $param) {
            if (isset($_GET[$param])) { // phpcs:ignore WordPress.Security.NonceVerification
                return true;
            }
        }

        if (is_author()) {
            return true;
        }

        // Рубрика по умолчанию — та самая «Без рубрики»: в неё падает всё,
        // у чего рубрику не проставили, и списком она ничего не добавляет.
        if (is_category()) {
            $term = get_queried_object();
            $default = (int) get_option('default_category');
            if ($term instanceof WP_Term && ($term->term_id === $default || (int) $term->count === 0)) {
                return true;
            }
        }

        // Пустой архив — страница без единой записи.
        if ((is_archive() || is_search()) && !have_posts()) {
            return true;
        }

        return false;
    }

    /**
     * Указания роботам в robots.txt.
     *
     * Clean-param понимает только Яндекс, и это как раз его случай: адреса
     * с параметрами он склеивает с каноническим сам, не дожидаясь обхода
     * каждого варианта.
     */
    public static function robots($output, $public) {
        if (!$public) {
            return $output;
        }

        $lines = preg_split('/\R/', rtrim($output));
        $maps = array();
        $kept = array();
        foreach ($lines as $line) {
            if (preg_match('/^\s*Sitemap:/i', $line)) {
                $maps[] = $line;
            } else {
                $kept[] = $line;
            }
        }

        $own = array(
            'Disallow: /author/',
            'Disallow: /*?add-to-cart=',
            'Clean-param: ' . implode('&', self::JUNK_PARAMS),
        );
        foreach ($own as $rule) {
            if (!in_array($rule, $kept, true)) {
                $kept[] = $rule;
            }
        }

        while ($kept && trim(end($kept)) === '') {
            array_pop($kept);
        }

        return implode("\n", array_merge($kept, array(''), $maps)) . "\n";
    }

    private static function blog_url() {
        $page = get_page_by_path('blog');
        if ($page instanceof WP_Post) {
            return get_permalink($page);
        }
        return home_url('/');
    }
}
