<?php
/**
 * SEO каталога звуков.
 *
 * Все 945 категорий живут на одной WP-странице, поэтому и тема, и базовый плагин
 * выдавали им одинаковые теги: общий <title>, общее описание, canonical на
 * /sounds-catalog/ и разметку Article/FAQPage от страницы озвучки. Для поисковика
 * это означало, что каждая категория — дубль индекса каталога.
 *
 * Заголовок правим фильтром, остальное — переписыванием буфера wp_head:
 * теги печатают сразу несколько источников (тема us-core и kie-tts-wp),
 * у которых нет общих фильтров.
 */

if (!defined('ABSPATH')) {
    exit;
}

class GS_Seo {

    /** @var array|null Контекст текущего запроса каталога. */
    private static $ctx = null;
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

    /* ---------------------------------------------------------------------
     * Контекст
     * ------------------------------------------------------------------ */

    /**
     * @return array|null {type, category, page, pages, query}
     */
    private static function context() {
        if (self::$resolved) {
            return self::$ctx;
        }
        self::$resolved = true;
        self::$ctx = null;

        $lab = GS_Lab::current_service();
        if ($lab) {
            self::$ctx = array(
                'type'     => 'lab',
                'service'  => $lab,
                'category' => null,
                'page'     => 1,
                'pages'    => 1,
                'query'    => '',
            );
            return self::$ctx;
        }

        if (is_admin() || !GS_Catalog::is_catalog_request()) {
            return null;
        }

        $page  = isset($_GET['gs_page']) ? max(1, (int) $_GET['gs_page']) : 1;
        $query = isset($_GET['gs_q']) ? sanitize_text_field(wp_unslash((string) $_GET['gs_q'])) : '';
        $slug  = GS_Catalog::requested_slug();

        if ($slug !== '') {
            $category = GS_Catalog::get_category($slug);
            if (!$category) {
                return null;
            }
            $pages = max(1, (int) ceil(GS_Catalog::count_sounds($category) / GS_Catalog::SOUNDS_PER_PAGE));
            self::$ctx = array(
                'type'     => 'category',
                'category' => $category,
                'page'     => min($page, $pages),
                'pages'    => $pages,
                'query'    => '',
            );
            return self::$ctx;
        }

        $pages = max(1, (int) ceil(count(GS_Catalog::load_index()) / GS_Catalog::CATS_PER_PAGE));
        self::$ctx = array(
            'type'     => 'index',
            'category' => null,
            'page'     => min($page, $pages),
            'pages'    => $pages,
            'query'    => $query,
        );
        return self::$ctx;
    }

    /* ---------------------------------------------------------------------
     * Заголовок
     * ------------------------------------------------------------------ */

    public static function filter_title_parts($parts) {
        $ctx = self::context();
        if (!$ctx) {
            return $parts;
        }
        $parts['title'] = self::build_title($ctx);
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

    private static function build_title($ctx) {
        if ($ctx['type'] === 'lab') {
            return (string) $ctx['service']['seo_title'];
        }
        if ($ctx['type'] === 'index') {
            $title = 'Каталог звуков — скачать бесплатно в MP3';
            if ($ctx['query'] !== '') {
                $title = 'Поиск: ' . $ctx['query'] . ' — каталог звуков';
            } elseif ($ctx['page'] > 1) {
                $title .= ', страница ' . $ctx['page'];
            }
            return $title;
        }

        $category = $ctx['category'];
        $title = trim((string) ($category['title'] ?? ''));
        if ($title === '') {
            $title = GS_Catalog::short_title((string) $category['slug']);
        }
        if (mb_strlen($title) > 60) {
            $title = mb_substr($title, 0, 59) . '…';
        } else {
            // Дописываем «продающий» хвост только если он целиком помещается.
            $count = GS_Catalog::count_sounds($category);
            if ($count > 0) {
                $suffix = ' — скачать бесплатно, ' . $count . ' MP3';
                if (mb_strlen($title . $suffix) <= 60) {
                    $title .= $suffix;
                }
            }
        }
        if ($ctx['page'] > 1) {
            $title .= ' — страница ' . $ctx['page'];
        }
        return $title;
    }

    /* ---------------------------------------------------------------------
     * Описание и адреса
     * ------------------------------------------------------------------ */

    private static function build_description($ctx) {
        if ($ctx['type'] === 'lab') {
            return (string) $ctx['service']['seo_desc'];
        }
        if ($ctx['type'] === 'index') {
            $stats = GS_Catalog::stats();
            return sprintf(
                'Бесплатный каталог звуков и звуковых эффектов: %s звуков в %s категориях. Слушайте онлайн и скачивайте в MP3 для монтажа, игр и роликов. Нужного звука нет — создайте его нейросетью.',
                number_format_i18n($stats['sounds']),
                number_format_i18n($stats['categories'])
            );
        }

        $category = $ctx['category'];
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
        $text = GS_Catalog::sync_description_count($text, GS_Catalog::count_sounds($category));
        $text = preg_replace('~\s+~u', ' ', $text);
        return mb_strlen($text) > 300 ? mb_substr($text, 0, 297) . '…' : $text;
    }

    /**
     * Адрес страницы с учётом пагинации — canonical должен быть свой у каждой
     * страницы списка, иначе категории со второй страницы и дальше объявляются
     * дублями первой и выпадают из индекса вместе со своими ссылками.
     */
    private static function page_url($ctx, $page = null) {
        if ($ctx['type'] === 'lab') {
            return GS_Lab::get_url($ctx['service']['id']);
        }
        $page = $page === null ? (int) $ctx['page'] : (int) $page;
        $base = $ctx['type'] === 'category'
            ? GS_Catalog::category_url($ctx['category']['slug'])
            : GS_Catalog::base_url();
        return $page > 1 ? add_query_arg('gs_page', $page, $base) : $base;
    }

    /* ---------------------------------------------------------------------
     * Переписывание head
     * ------------------------------------------------------------------ */

    public static function start_buffer() {
        if (self::context()) {
            ob_start();
        }
    }

    public static function flush_buffer() {
        $ctx = self::context();
        if (!$ctx) {
            return;
        }
        $html = ob_get_clean();
        if ($html === false) {
            return;
        }

        $url  = self::page_url($ctx);
        $desc = self::build_description($ctx);

        // Тема дописывает к заголовку полное имя сайта уже после наших фильтров.
        $html = self::replace_tag(
            $html,
            '~<title>.*?</title>~is',
            '<title>' . esc_html(self::build_title($ctx) . ' — ' . self::brand_name(get_bloginfo('name'))) . '</title>'
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

        $html = self::strip_foreign_schema($html);

        echo $html; // phpcs:ignore WordPress.Security.EscapeOutput
        echo self::extra_tags($ctx); // phpcs:ignore WordPress.Security.EscapeOutput
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

    /**
     * Базовый плагин печатает на страницах каталога разметку страницы озвучки:
     * Article с описанием «[kie_tts_sounds_catalog]», FAQPage с вопросами про
     * форматы и API, которых на странице нет, и две крошки, обе ведущие в индекс
     * каталога. FAQ-разметка без соответствующего контента — прямое нарушение
     * требований поисковиков, поэтому чужие блоки убираем и печатаем свои.
     */
    private static function strip_foreign_schema($html) {
        return (string) preg_replace_callback(
            '~<script[^>]*type=["\']application/ld\+json["\'][^>]*>(.*?)</script>~is',
            function ($m) {
                $data = json_decode(trim($m[1]), true);
                if (!is_array($data)) {
                    return $m[0];
                }
                $type = isset($data['@type']) ? $data['@type'] : '';
                $drop = array('Article', 'NewsArticle', 'BlogPosting', 'FAQPage', 'BreadcrumbList');
                return in_array($type, $drop, true) ? '' : $m[0];
            },
            $html
        );
    }

    /**
     * Свои теги: robots, ссылки пагинации и корректная микроразметка.
     */
    private static function extra_tags($ctx) {
        $out = "\n";

        // Пока сервис не подключён, странице нечего делать в индексе:
        // пользователь придёт из поиска и упрётся в заглушку.
        if ($ctx['type'] === 'lab' && !GS_Lab::is_available($ctx['service']['id'])) {
            return $out . '<meta name="robots" content="noindex, follow">' . "\n";
        }

        // Страницы поиска — служебные, в индексе им делать нечего,
        // но ссылки с них пусть передают вес.
        if ($ctx['type'] === 'index' && $ctx['query'] !== '') {
            $out .= '<meta name="robots" content="noindex, follow">' . "\n";
            return $out;
        }

        if ($ctx['page'] > 1) {
            $out .= '<link rel="prev" href="' . esc_url(self::page_url($ctx, $ctx['page'] - 1)) . '">' . "\n";
        }
        if ($ctx['page'] < $ctx['pages']) {
            $out .= '<link rel="next" href="' . esc_url(self::page_url($ctx, $ctx['page'] + 1)) . '">' . "\n";
        }

        if ($ctx['type'] === 'lab') {
            foreach (self::schema_lab($ctx) as $schema) {
                $out .= '<script type="application/ld+json">'
                    . wp_json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                    . '</script>' . "\n";
            }
            return $out;
        }

        $schema = $ctx['type'] === 'category' ? self::schema_category($ctx) : self::schema_index($ctx);
        $out .= '<script type="application/ld+json">'
            . wp_json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            . '</script>' . "\n";

        return $out;
    }

    /* ---------------------------------------------------------------------
     * Микроразметка
     * ------------------------------------------------------------------ */

    private static function breadcrumbs($items) {
        $list = array();
        $position = 1;
        foreach ($items as $name => $url) {
            $list[] = array(
                '@type'    => 'ListItem',
                'position' => $position++,
                'name'     => $name,
                'item'     => $url,
            );
        }
        return array('@type' => 'BreadcrumbList', 'itemListElement' => $list);
    }

    /**
     * Посадочная микросервиса: сам инструмент, крошки и вопросы.
     * FAQPage печатаем только потому, что те же вопросы видимы на странице —
     * разметка без соответствующего контента нарушает требования поисковиков.
     */
    private static function schema_lab($ctx) {
        $service = $ctx['service'];
        $url = GS_Lab::get_url($service['id']);

        $app = array(
            '@context'        => 'https://schema.org',
            '@type'           => 'WebApplication',
            'name'            => $service['h1'],
            'description'     => $service['seo_desc'],
            'url'             => $url,
            'applicationCategory' => 'MultimediaApplication',
            'operatingSystem' => 'Any',
            'inLanguage'      => 'ru-RU',
            'offers'          => array(
                '@type'         => 'Offer',
                'price'         => (string) GS_Lab::get_cost($service['id']),
                'priceCurrency' => 'RUB',
            ),
            'publisher'       => array(
                '@type' => 'Organization',
                'name'  => self::brand_name(get_bloginfo('name')),
                'url'   => home_url('/'),
            ),
            'breadcrumb'      => self::breadcrumbs(array(
                'Главная'          => home_url('/'),
                $service['menu']   => $url,
            )),
        );

        $questions = array();
        foreach ($service['faq'] as $pair) {
            $questions[] = array(
                '@type'          => 'Question',
                'name'           => $pair[0],
                'acceptedAnswer' => array('@type' => 'Answer', 'text' => $pair[1]),
            );
        }
        $faq = array(
            '@context'   => 'https://schema.org',
            '@type'      => 'FAQPage',
            'mainEntity' => $questions,
        );

        return array($app, $faq);
    }

    private static function schema_index($ctx) {
        $stats = GS_Catalog::stats();
        $page  = $ctx['page'];
        $slice = array_slice(GS_Catalog::load_index(), ($page - 1) * GS_Catalog::CATS_PER_PAGE, GS_Catalog::CATS_PER_PAGE);

        $items = array();
        $position = 1;
        foreach ($slice as $row) {
            if (empty($row['slug'])) {
                continue;
            }
            $items[] = array(
                '@type'    => 'ListItem',
                'position' => $position++,
                'name'     => GS_Catalog::short_title((string) $row['title']),
                'url'      => GS_Catalog::category_url((string) $row['slug']),
            );
        }

        return array(
            '@context'    => 'https://schema.org',
            '@type'       => 'CollectionPage',
            'name'        => 'Каталог звуков',
            'description' => self::build_description($ctx),
            'url'         => self::page_url($ctx),
            'inLanguage'  => 'ru-RU',
            'isPartOf'    => array(
                '@type' => 'WebSite',
                'name'  => self::brand_name(get_bloginfo('name')),
                'url'   => home_url('/'),
            ),
            'breadcrumb'  => self::breadcrumbs(array(
                'Главная'        => home_url('/'),
                'Каталог звуков' => GS_Catalog::base_url(),
            )),
            'mainEntity'  => array(
                '@type'            => 'ItemList',
                'numberOfItems'    => (int) $stats['categories'],
                'itemListElement'  => $items,
            ),
        );
    }

    private static function schema_category($ctx) {
        $category = $ctx['category'];
        $slug     = (string) $category['slug'];
        $title    = GS_Catalog::short_title((string) $category['title']);
        $sounds   = (isset($category['sounds']) && is_array($category['sounds'])) ? $category['sounds'] : array();
        $slice    = array_slice($sounds, ($ctx['page'] - 1) * GS_Catalog::SOUNDS_PER_PAGE, GS_Catalog::SOUNDS_PER_PAGE);

        $items = array();
        $position = 1 + ($ctx['page'] - 1) * GS_Catalog::SOUNDS_PER_PAGE;
        foreach ($slice as $sound) {
            if (empty($sound['file'])) {
                continue;
            }
            $audio = array(
                '@type'          => 'AudioObject',
                'name'           => (string) ($sound['title'] ?? 'Звук'),
                'contentUrl'     => GS_Catalog::file_url((string) $sound['file']),
                'encodingFormat' => 'audio/mpeg',
            );
            $duration = (int) ($sound['duration'] ?? 0);
            if ($duration > 0) {
                $audio['duration'] = 'PT' . $duration . 'S';
            }
            $items[] = array(
                '@type'    => 'ListItem',
                'position' => $position++,
                'item'     => $audio,
            );
        }

        return array(
            '@context'    => 'https://schema.org',
            '@type'       => 'CollectionPage',
            'name'        => (string) $category['title'],
            'description' => self::build_description($ctx),
            'url'         => self::page_url($ctx),
            'inLanguage'  => 'ru-RU',
            'isPartOf'    => array(
                '@type' => 'WebSite',
                'name'  => self::brand_name(get_bloginfo('name')),
                'url'   => home_url('/'),
            ),
            'breadcrumb'  => self::breadcrumbs(array(
                'Главная'        => home_url('/'),
                'Каталог звуков' => GS_Catalog::base_url(),
                $title           => GS_Catalog::category_url($slug),
            )),
            'mainEntity'  => array(
                '@type'           => 'ItemList',
                'numberOfItems'   => count($sounds),
                'itemListElement' => $items,
            ),
        );
    }
}
