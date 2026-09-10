<?php
/**
 * SEO страниц каталога.
 *
 * Все 945 категорий живут на одной WP-странице, поэтому и тема, и базовый плагин
 * выдавали им один и тот же <title>, одинаковое описание и — что хуже всего —
 * canonical на /sounds-catalog/. Для поисковика это означало, что каждая
 * категория — дубль индекса каталога.
 *
 * Заголовок правим фильтром, а остальные теги — переписыванием буфера wp_head:
 * их печатают сразу несколько источников (тема us-core и kie-tts-wp),
 * у которых нет общих фильтров.
 */

if (!defined('ABSPATH')) {
    exit;
}

class GS_Seo {

    /** @var array|null Категория текущего запроса. */
    private static $category = null;
    private static $resolved = false;

    public static function boot() {
        add_filter('document_title_parts', array(__CLASS__, 'filter_title_parts'), PHP_INT_MAX);
        add_action('wp_head', array(__CLASS__, 'start_buffer'), 0);
        add_action('wp_head', array(__CLASS__, 'flush_buffer'), PHP_INT_MAX);
        add_action('template_redirect', array(__CLASS__, 'maybe_send_404'));
    }

    /**
     * Несуществующий слаг категории отдавал 200 с текстом «не найдено» —
     * для поисковика это мягкий 404 и мусорная страница в индексе.
     */
    public static function maybe_send_404() {
        if (is_admin() || !GS_Catalog::is_catalog_request()) {
            return;
        }
        $slug = GS_Catalog::requested_slug();
        if ($slug === '' || GS_Catalog::get_category($slug)) {
            return;
        }
        status_header(404);
        nocache_headers();
    }

    /**
     * @return array|null
     */
    private static function current_category() {
        if (self::$resolved) {
            return self::$category;
        }
        self::$resolved = true;
        self::$category = null;

        if (is_admin() || !GS_Catalog::is_catalog_request()) {
            return null;
        }
        $slug = GS_Catalog::requested_slug();
        if ($slug === '') {
            return null;
        }
        self::$category = GS_Catalog::get_category($slug);
        return self::$category;
    }

    /* ---------------------------------------------------------------------
     * Заголовок
     * ------------------------------------------------------------------ */

    public static function filter_title_parts($parts) {
        $category = self::current_category();
        if (!$category) {
            return $parts;
        }
        $parts['title'] = self::build_title($category);
        unset($parts['tagline']);

        // Имя сайта здесь — целое предложение («Genius-bot - Разработка чат ботов…»),
        // из-за него заголовок категории обрезается в выдаче. Оставляем только бренд.
        if (!empty($parts['site'])) {
            $parts['site'] = self::brand_name((string) $parts['site']);
        }

        return $parts;
    }

    private static function brand_name($site) {
        $parts = preg_split('~\s+[-—–|]\s+~u', trim($site), 2);
        $brand = (is_array($parts) && !empty($parts[0])) ? trim($parts[0]) : trim($site);
        return $brand !== '' ? $brand : $site;
    }

    private static function build_title($category) {
        $title = trim((string) ($category['title'] ?? ''));
        if ($title === '') {
            $title = GS_Catalog::short_title((string) $category['slug']);
        }
        if (mb_strlen($title) > 60) {
            return mb_substr($title, 0, 59) . '…';
        }

        // Дописываем «продающий» хвост только если он целиком помещается в заголовок.
        $count = GS_Catalog::count_sounds($category);
        if ($count > 0) {
            $suffix = ' — скачать бесплатно, ' . $count . ' MP3';
            if (mb_strlen($title . $suffix) <= 60) {
                $title .= $suffix;
            }
        }
        return $title;
    }

    /* ---------------------------------------------------------------------
     * Описание, canonical, OG
     * ------------------------------------------------------------------ */

    private static function build_description($category) {
        $text = trim((string) ($category['description'] ?? ''));
        if ($text === '') {
            $text = trim((string) ($category['headline'] ?? ''));
        }
        if ($text === '') {
            $count = GS_Catalog::count_sounds($category);
            $text = GS_Catalog::short_title((string) $category['title']) . ': '
                . ($count > 0 ? GS_Catalog::plural_sounds($count) . ' — ' : '')
                . 'слушайте онлайн и скачивайте бесплатно в MP3.';
        }
        $text = preg_replace('~\s+~u', ' ', $text);
        return mb_strlen($text) > 300 ? mb_substr($text, 0, 297) . '…' : $text;
    }

    public static function start_buffer() {
        if (self::current_category()) {
            ob_start();
        }
    }

    public static function flush_buffer() {
        $category = self::current_category();
        if (!$category) {
            return;
        }
        $html = ob_get_clean();
        if ($html === false) {
            return;
        }

        $url  = GS_Catalog::category_url($category['slug']);
        $desc = self::build_description($category);

        // Тема дописывает к заголовку полное имя сайта уже после наших фильтров,
        // поэтому итоговый <title> собираем здесь.
        $html = self::replace_tag(
            $html,
            '~<title>.*?</title>~is',
            '<title>' . esc_html(self::build_title($category) . ' — ' . self::brand_name(get_bloginfo('name'))) . '</title>'
        );

        $html = self::replace_tag(
            $html,
            '~<link[^>]+rel=["\']canonical["\'][^>]*>~i',
            '<link rel="canonical" href="' . esc_url($url) . '">'
        );
        $html = self::replace_tag(
            $html,
            '~<meta[^>]+name=["\']description["\'][^>]*>~i',
            '<meta name="description" content="' . esc_attr($desc) . '">'
        );
        $html = self::replace_tag(
            $html,
            '~<meta[^>]+property=["\']og:description["\'][^>]*>~i',
            '<meta property="og:description" content="' . esc_attr($desc) . '">'
        );
        $html = self::replace_tag(
            $html,
            '~<meta[^>]+property=["\']og:url["\'][^>]*>~i',
            '<meta property="og:url" content="' . esc_url($url) . '">'
        );

        echo $html; // phpcs:ignore WordPress.Security.EscapeOutput
    }

    /**
     * Первое вхождение заменяем, остальные выкидываем: и тема, и базовый плагин
     * печатают свои теги, дубли в head поисковику только мешают.
     */
    private static function replace_tag($html, $pattern, $replacement) {
        $count = 0;
        $html = preg_replace_callback($pattern, function () use (&$count, $replacement) {
            $count++;
            return $count === 1 ? $replacement : '';
        }, $html);
        return (string) $html;
    }
}
