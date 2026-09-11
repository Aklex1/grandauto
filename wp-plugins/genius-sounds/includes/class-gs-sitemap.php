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

    public static function filter_robots_txt($output, $public) {
        if (!$public) {
            return $output;
        }
        if (strpos($output, 'sounds-sitemap.xml') !== false) {
            return $output;
        }
        return $output . "\nSitemap: " . self::index_url() . "\n";
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

        $extra = array(GS_Pages::get_studio_url(), GS_Pages::get_showcase_url());
        foreach (GS_Lab::available_services() as $service) {
            $extra[] = GS_Lab::get_url($service['id']);
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
        $total = count(GS_Catalog::load_index()) + 3 + count(GS_Lab::available_services());
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
