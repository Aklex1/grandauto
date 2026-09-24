<?php
/**
 * Карта сайта для каталога звуков.
 *
 * Категории — виртуальные страницы одного WP-поста, поэтому ни `wp-sitemap.xml`,
 * ни карта базового плагина о них не знают: в индекс попадала только сама
 * страница каталога. Отдаём свою карту и прописываем её в robots.txt.
 *
 *   /sounds-sitemap.xml      — индекс карт
 *   /sounds-sitemap-N.xml    — пачка ссылок (по CHUNK штук)
 */

if (!defined('ABSPATH')) {
    exit;
}

class GS_Sitemap {

    const CHUNK = 500;

    public static function boot() {
        add_action('init', array(__CLASS__, 'add_rewrite_rules'), 5);
        add_filter('query_vars', array(__CLASS__, 'add_query_vars'));
        add_action('template_redirect', array(__CLASS__, 'maybe_render'), 0);
        add_filter('robots_txt', array(__CLASS__, 'filter_robots_txt'), 20, 2);
    }

    public static function add_rewrite_rules() {
        add_rewrite_rule('^sounds-sitemap\.xml$', 'index.php?gs_sitemap=index', 'top');
        add_rewrite_rule('^sounds-sitemap-([0-9]+)\.xml$', 'index.php?gs_sitemap=chunk&gs_sitemap_page=$matches[1]', 'top');
    }

    public static function add_query_vars($vars) {
        $vars[] = 'gs_sitemap';
        $vars[] = 'gs_sitemap_page';
        return $vars;
    }

    public static function index_url() {
        return home_url('/sounds-sitemap.xml');
    }

    public static function chunk_url($n) {
        return home_url('/sounds-sitemap-' . (int) $n . '.xml');
    }

    /**
     * Строки Sitemap в robots.txt.
     *
     * Карты объявляют несколько плагинов сразу, и в итоге в robots.txt
     * оказываются дубль одной и той же карты и адрес, отвечающий редиректом.
     * Робот такие строки терпит, но каждая — лишний повод не начать обход,
     * поэтому список нормализуем: убираем повторы и заменяем адреса,
     * ведущие на редирект, конечными.
     */
    public static function filter_robots_txt($output, $public) {
        if (!$public) {
            return $output;
        }

        $lines = preg_split('/\R/', $output);
        $kept = array();
        $maps = array();

        foreach ($lines as $line) {
            if (!preg_match('/^\s*Sitemap:\s*(\S+)\s*$/i', $line, $m)) {
                $kept[] = $line;
                continue;
            }
            $maps[] = self::tidy_map_url($m[1]);
        }

        $maps[] = self::index_url();

        // Пустые строки на хвосте убираем, иначе после склейки их станет больше.
        while ($kept && trim(end($kept)) === '') {
            array_pop($kept);
        }

        foreach (array_unique($maps) as $map) {
            $kept[] = 'Sitemap: ' . $map;
        }

        return implode("\n", $kept) . "\n";
    }

    /**
     * Адрес карты без заведомого редиректа.
     *
     * Сайт работает со слешем на конце, и `/tts-sitemap.xml` отвечает 301 на
     * `/tts-sitemap.xml/`. Ставим сразу конечный адрес.
     */
    private static function tidy_map_url($url) {
        $url = trim($url);
        if ($url === '' || strpos($url, home_url('/')) !== 0) {
            return $url;
        }
        // Одна и та же карта объявлена и как `/sitemap.xml`, и как
        // `/?sitemap=xml`. Оставляем путь: именно он зарегистрирован
        // в Вебмастере, и по нему же приходит робот.
        $query = (string) wp_parse_url($url, PHP_URL_QUERY);
        if ($query !== '') {
            parse_str($query, $args);
            if (($args['sitemap'] ?? '') === 'xml' && count($args) === 1) {
                return home_url('/sitemap.xml');
            }
            return $url;
        }

        $path = (string) wp_parse_url($url, PHP_URL_PATH);
        if ($path === '' || substr($path, -1) === '/') {
            return $url;
        }
        // Наши собственные карты отдаются без слеша — их не трогаем.
        if (preg_match('~/(sounds-sitemap(-\d+)?|wp-sitemap[^/]*|sitemap)\.xml$~', $path)) {
            return $url;
        }
        return $url . '/';
    }

    /* ---------------------------------------------------------------------
     * Ссылки
     * ------------------------------------------------------------------ */

    /**
     * Все URL каталога: сама страница каталога, категории, студия и витрина.
     *
     * @return array<int,array{loc:string,lastmod:string,priority:string,changefreq:string}>
     */
    private static function collect_urls() {
        $urls = array();

        $urls[] = array(
            'loc'        => GS_Catalog::base_url(),
            'lastmod'    => self::file_date(GS_Storage::base_dir() . '/index.json'),
            'priority'   => '0.9',
            'changefreq' => 'daily',
        );

        // Разделы идут сразу за каталогом и с высоким приоритетом: через них
        // робот доходит до подборок, а не перебирает тысячу ссылок с одной
        // страницы, обходя по полсотни адресов в сутки.
        foreach (GS_Sections::overview() as $section) {
            $urls[] = array(
                'loc'        => GS_Sections::url($section['slug']),
                'lastmod'    => self::file_date(GS_Storage::base_dir() . '/index.json'),
                'priority'   => '0.9',
                'changefreq' => 'weekly',
            );
        }

        foreach (GS_Catalog::load_index() as $row) {
            if (empty($row['slug'])) {
                continue;
            }
            $slug = (string) $row['slug'];
            $urls[] = array(
                'loc'        => GS_Catalog::category_url($slug),
                'lastmod'    => self::file_date(GS_Storage::base_dir() . '/cats/' . $slug . '.json'),
                'priority'   => ((int) ($row['count'] ?? 0) > 0) ? '0.8' : '0.4',
                'changefreq' => 'weekly',
            );
        }

        $extra = array(GS_Pages::get_studio_url(), GS_Pages::get_showcase_url(), GS_Api_Page::get_url(), GS_Course::get_url());
        // Примерка дисков не стоит в меню, и обойти её поисковику можно
        // только по ссылке из подвала и отсюда.
        if (class_exists('GS_Wheel')) {
            $extra[] = GS_Wheel::url();
        }
        foreach (GS_Lab::available_services() as $service) {
            $extra[] = GS_Lab::get_url($service['id']);
        }
        foreach (GS_Landing::all() as $landing) {
            if (GS_Lab::is_available($landing['service'])) {
                $extra[] = GS_Landing::get_url($landing['id']);
            }
        }
        foreach ($extra as $url) {
            if ($url) {
                $urls[] = array(
                    'loc'        => $url,
                    'lastmod'    => gmdate('Y-m-d'),
                    'priority'   => '0.9',
                    'changefreq' => 'weekly',
                );
            }
        }

        return $urls;
    }

    private static function file_date($path) {
        $ts = @filemtime($path);
        return gmdate('Y-m-d', $ts ? $ts : time());
    }

    public static function chunk_count() {
        $total = count(GS_Catalog::load_index()) + count(GS_Sections::overview()) + 4
            + count(GS_Lab::available_services()) + count(GS_Landing::all());
        return max(1, (int) ceil($total / self::CHUNK));
    }

    /* ---------------------------------------------------------------------
     * Вывод
     * ------------------------------------------------------------------ */

    public static function maybe_render() {
        $what = get_query_var('gs_sitemap');
        if ($what === '' || $what === null) {
            return;
        }

        if ($what === 'index') {
            self::render_index();
        }

        $page = max(1, (int) get_query_var('gs_sitemap_page'));
        if ($page > self::chunk_count()) {
            status_header(404);
            nocache_headers();
            return;
        }
        self::render_chunk($page);
    }

    private static function send_headers() {
        status_header(200);
        header('Content-Type: application/xml; charset=UTF-8');
        header('X-Robots-Tag: noindex, follow', true);
    }

    private static function render_index() {
        self::send_headers();
        $lastmod = self::file_date(GS_Storage::base_dir() . '/index.json');

        echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        echo '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        for ($i = 1; $i <= self::chunk_count(); $i++) {
            echo "  <sitemap>\n";
            echo '    <loc>' . esc_url(self::chunk_url($i)) . "</loc>\n";
            echo '    <lastmod>' . esc_html($lastmod) . "</lastmod>\n";
            echo "  </sitemap>\n";
        }
        echo '</sitemapindex>';
        exit;
    }

    private static function render_chunk($page) {
        $urls = array_slice(self::collect_urls(), ($page - 1) * self::CHUNK, self::CHUNK);
        self::send_headers();

        echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach ($urls as $url) {
            echo "  <url>\n";
            echo '    <loc>' . esc_url($url['loc']) . "</loc>\n";
            echo '    <lastmod>' . esc_html($url['lastmod']) . "</lastmod>\n";
            echo '    <changefreq>' . esc_html($url['changefreq']) . "</changefreq>\n";
            echo '    <priority>' . esc_html($url['priority']) . "</priority>\n";
            echo "  </url>\n";
        }
        echo '</urlset>';
        exit;
    }
}
