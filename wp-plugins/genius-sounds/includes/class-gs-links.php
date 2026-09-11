<?php
/**
 * Сквозные ссылки на каталог и студию.
 *
 * Каталог звуков был полностью изолирован: ни главная, ни меню, ни одна страница
 * сайта на него не ссылались. 945 страниц не получали никакого внутреннего веса,
 * а поисковик добирался до них только по прямым переходам. Добавляем пункты в
 * навигацию и блок ссылок в подвал — так вес с сильных страниц идёт в каталог,
 * а из каталога уже расходится по категориям (пагинация плюс блок похожих).
 */

if (!defined('ABSPATH')) {
    exit;
}

class GS_Links {

    const OPT_ENABLED = 'gs_sitewide_links';
    const OPT_FOOTER  = 'gs_footer_block';

    /** Страница «Нейросети» — отдельный плагин, но для пользователя это такой же инструмент. */
    const NEUROHUB_SLUG = 'neurohub';

    public static function boot() {
        // Универсальный путь для тем, которые рендерят меню через wp_nav_menu().
        add_filter('wp_nav_menu_items', array(__CLASS__, 'add_menu_items'), 20, 2);
        add_action('wp_footer', array(__CLASS__, 'render_footer_block'), 20);
        add_filter('the_content', array(__CLASS__, 'append_neurohub_links'), 20);
        // Страница «Нейросети» собрана своим плагином и the_content может не
        // дойти до вывода — подстраховываемся хуком подвала.
        add_action('wp_footer', array(__CLASS__, 'render_neurohub_links'), 5);
    }

    public static function neurohub_url() {
        $page = get_page_by_path(self::NEUROHUB_SLUG);
        return $page ? get_permalink($page) : home_url('/' . self::NEUROHUB_SLUG . '/');
    }

    /**
     * Плагин kie-neurohub отдаёт страницу не постом, а своим шаблоном —
     * WordPress считает её блогом. Поэтому определяем по адресу.
     */
    public static function is_neurohub() {
        if (is_admin()) {
            return false;
        }
        $uri = isset($_SERVER['REQUEST_URI']) ? (string) wp_unslash($_SERVER['REQUEST_URI']) : '';
        $path = trim((string) wp_parse_url($uri, PHP_URL_PATH), '/');
        return $path === self::NEUROHUB_SLUG || strpos($path, self::NEUROHUB_SLUG . '/') === 0;
    }

    /**
     * На «Нейросетях» не было ни одной ссылки на остальные инструменты —
     * дописываем тот же блок, что стоит на посадочных микросервисов.
     */
    /** @var bool Блок уже выведен в этом запросе. */
    private static $neurohub_done = false;

    public static function render_neurohub_links() {
        if (is_admin() || !self::is_neurohub() || self::$neurohub_done) {
            return;
        }
        self::$neurohub_done = true;
        // Сначала описание и вопросы — странице нужен текст, потом уже ссылки.
        echo GS_Neurohub::render_footer_content() // phpcs:ignore WordPress.Security.EscapeOutput
            . '<div class="gs-wrap gs-studio gs-neurohub-links">'
            . GS_Lab_Page::render_cross_links('', 'Другие инструменты Genius-bot')
            . '</div>';
    }

    public static function append_neurohub_links($content) {
        if (is_admin() || !self::is_neurohub() || !is_main_query()) {
            return $content;
        }
        if (self::$neurohub_done) {
            return $content;
        }
        self::$neurohub_done = true;
        return $content
            . '<div class="gs-wrap gs-studio gs-neurohub-links">'
            . GS_Lab_Page::render_cross_links('', 'Другие инструменты Genius-bot')
            . '</div>';
    }

    /**
     * Impreza рисует шапку своим обходчиком и фильтр wp_nav_menu_items не вызывает,
     * поэтому пункты добавляем в само меню сайта. Разово: удалённые вручную
     * пункты обратно не возвращаются.
     *
     * @return array{added:int,menu:string,skipped:array}
     */
    public static function install_menu_items($menu_id = 0, $reset = false) {
        $result = array('added' => 0, 'menu' => '', 'skipped' => array());

        $menu_id = (int) $menu_id;
        if ($menu_id <= 0) {
            $locations = get_nav_menu_locations();
            foreach ((array) $locations as $loc_menu_id) {
                if ((int) $loc_menu_id > 0) {
                    $menu_id = (int) $loc_menu_id;
                    break;
                }
            }
        }
        // Impreza не регистрирует меню через locations темы — берём самое
        // наполненное меню сайта, это и есть основная навигация.
        if ($menu_id <= 0) {
            $best = 0;
            $best_count = -1;
            foreach ((array) wp_get_nav_menus() as $nav_menu) {
                $count = (int) $nav_menu->count;
                if ($count > $best_count) {
                    $best_count = $count;
                    $best = (int) $nav_menu->term_id;
                }
            }
            $menu_id = $best;
        }
        if ($menu_id <= 0) {
            return $result;
        }

        $menu = wp_get_nav_menu_object($menu_id);
        if (!$menu) {
            return $result;
        }
        $result['menu'] = $menu->name;

        $existing = wp_get_nav_menu_items($menu_id);

        // Пересборка: сносим всё, что добавляли мы, и строим заново.
        // После нескольких проходов структура успевает разъехаться.
        if ($reset) {
            $ours = array(untrailingslashit(self::menu_tree()['parent']['url']) => true);
            foreach (self::nav_links() as $link) {
                $ours[untrailingslashit($link['url'])] = true;
            }
            foreach (GS_Lab::services() as $lab_id => $lab) {
                $ours[untrailingslashit(GS_Lab::get_url($lab_id))] = true;
            }
            $result['removed'] = 0;
            foreach ((array) $existing as $item) {
                $url = untrailingslashit((string) $item->url);
                $title = trim((string) $item->title);
                if (isset($ours[$url]) || $title === self::menu_tree()['parent']['title']) {
                    wp_delete_post((int) $item->ID, true);
                    $result['removed']++;
                }
            }
            $existing = wp_get_nav_menu_items($menu_id);
        }

        $urls = array();
        foreach ((array) $existing as $item) {
            $urls[untrailingslashit((string) $item->url)] = true;
        }

        // Сервис могли выключить после установки пункта — убираем такие из меню,
        // иначе в шапке висит ссылка на заглушку.
        $wanted = array();
        foreach (self::nav_links() as $link) {
            $wanted[untrailingslashit($link['url'])] = true;
        }
        $result['removed'] = 0;
        foreach (GS_Lab::services() as $lab_id => $lab) {
            if (GS_Lab::is_available($lab_id)) {
                continue;
            }
            $stale = untrailingslashit(GS_Lab::get_url($lab_id));
            foreach ((array) $existing as $item) {
                if (untrailingslashit((string) $item->url) === $stale && !isset($wanted[$stale])) {
                    wp_delete_post((int) $item->ID, true);
                    $result['removed']++;
                }
            }
        }

        $tree = self::menu_tree();

        // Родительский пункт — один на всё меню, ищем его среди существующих.
        $parent_id = 0;
        foreach ((array) $existing as $item) {
            if (trim((string) $item->title) === $tree['parent']['title']) {
                $parent_id = (int) $item->ID;
                break;
            }
        }
        if ($parent_id === 0) {
            $parent_id = wp_update_nav_menu_item($menu_id, 0, array(
                'menu-item-title'  => $tree['parent']['title'],
                'menu-item-url'    => $tree['parent']['url'],
                'menu-item-status' => 'publish',
                'menu-item-type'   => 'custom',
            ));
            if (is_wp_error($parent_id)) {
                return $result;
            }
            $result['added']++;
        }

        foreach ($tree['children'] as $link) {
            if ($link['url'] === '') {
                continue;
            }
            $key = untrailingslashit($link['url']);
            if (isset($urls[$key])) {
                // Уже есть — на всякий случай подчиняем родителю.
                foreach ((array) $existing as $item) {
                    if (untrailingslashit((string) $item->url) === $key && (int) $item->menu_item_parent !== (int) $parent_id) {
                        wp_update_nav_menu_item($menu_id, (int) $item->ID, array(
                            'menu-item-title'     => $link['title'],
                            'menu-item-url'       => $link['url'],
                            'menu-item-status'    => 'publish',
                            'menu-item-type'      => 'custom',
                            'menu-item-parent-id' => $parent_id,
                        ));
                    }
                }
                $result['skipped'][] = $link['title'];
                continue;
            }
            $added = wp_update_nav_menu_item($menu_id, 0, array(
                'menu-item-title'     => $link['title'],
                'menu-item-url'       => $link['url'],
                'menu-item-status'    => 'publish',
                'menu-item-type'      => 'custom',
                'menu-item-parent-id' => $parent_id,
            ));
            if (!is_wp_error($added) && $added) {
                $result['added']++;
            }
        }

        return $result;
    }

    public static function menu_enabled() {
        return (string) get_option(self::OPT_ENABLED, '1') === '1';
    }

    public static function footer_enabled() {
        return (string) get_option(self::OPT_FOOTER, '1') === '1';
    }

    /**
     * Ссылки, которые должны быть в навигации.
     */
    /**
     * Плоский список ссылок (для фильтра меню и проверок).
     */
    private static function nav_links() {
        $links = array(
            array('url' => GS_Catalog::base_url(),     'title' => 'Каталог звуков'),
            array('url' => GS_Pages::get_studio_url(), 'title' => 'Генератор звуков'),
        );
        foreach (GS_Lab::available_services() as $service) {
            $links[] = array('url' => GS_Lab::get_url($service['id']), 'title' => $service['nav']);
        }
        $links[] = array('url' => self::neurohub_url(), 'title' => 'Нейросети');
        $links[] = array('url' => GS_Api_Page::get_url(), 'title' => 'API для разработчиков');
        return $links;
    }

    /**
     * Пункты для меню сайта: родитель «Звуки и видео» и вложенные инструменты.
     * Плоским списком семь пунктов не помещаются в шапку и обрезаются.
     */
    private static function menu_tree() {
        return array(
            'parent'   => array('url' => GS_Catalog::base_url(), 'title' => 'Звуки и видео'),
            'children' => self::nav_links(),
        );
    }

    /* ---------------------------------------------------------------------
     * Меню
     * ------------------------------------------------------------------ */

    public static function add_menu_items($items, $args) {
        if (is_admin() || !self::menu_enabled()) {
            return $items;
        }
        // Только закреплённые за темой меню (шапка и подвал), не случайные вызовы.
        if (empty($args->theme_location)) {
            return $items;
        }

        foreach (self::nav_links() as $link) {
            if ($link['url'] === '' || strpos($items, esc_url($link['url'])) !== false) {
                continue;
            }
            $items .= sprintf(
                '<li class="menu-item menu-item-type-post_type menu-item-object-page w-nav-item level_1 gs-menu-item">'
                . '<a class="w-nav-anchor level_1" href="%s"><span class="w-nav-title">%s</span><span class="w-nav-arrow"></span></a>'
                . '</li>',
                esc_url($link['url']),
                esc_html($link['title'])
            );
        }

        return $items;
    }

    /* ---------------------------------------------------------------------
     * Блок в подвале
     * ------------------------------------------------------------------ */

    /**
     * Самые наполненные категории — им достаётся вес со всех страниц сайта.
     *
     * @return array<int,array>
     */
    private static function top_categories($limit = 12) {
        // Порядок индекса повторяет структуру источника: сверху крупные разделы
        // (музыка, авто, предметы, животные…). Именно им и стоит отдавать вес
        // со всех страниц — дальше он расходится по их подборкам.
        $picked = array();
        foreach (GS_Catalog::load_index() as $row) {
            if (count($picked) >= $limit) {
                break;
            }
            if (empty($row['slug']) || (int) ($row['count'] ?? 0) <= 0) {
                continue;
            }
            $picked[] = $row;
        }
        return $picked;
    }

    public static function render_footer_block() {
        if (is_admin() || !self::footer_enabled()) {
            return;
        }
        // На страницах каталога блок не нужен: там уже есть свои перелинковки.
        if (GS_Catalog::is_catalog_request()) {
            return;
        }

        $cats = self::top_categories(12);
        if (empty($cats)) {
            return;
        }
        ?>
        <section class="gs-footer-links" aria-label="Библиотека звуков">
            <div class="gs-footer-links__h">
                <h2 class="gs-footer-links__title">Библиотека звуков</h2>
                <p class="gs-footer-links__lead">
                    <a href="<?php echo esc_url(GS_Catalog::base_url()); ?>">Каталог звуков</a> ·
                    <a href="<?php echo esc_url(GS_Pages::get_studio_url()); ?>">Генератор звуков и спецэффектов</a> ·
                    <?php foreach (GS_Lab::available_services() as $svc): ?>
                        <a href="<?php echo esc_url(GS_Lab::get_url($svc['id'])); ?>"><?php echo esc_html($svc['menu']); ?></a> ·
                    <?php endforeach; ?>
                    <a href="<?php echo esc_url(GS_Pages::get_showcase_url()); ?>">Звуки, созданные нейросетью</a>
                </p>
                <ul class="gs-footer-links__list">
                    <?php foreach ($cats as $cat): ?>
                        <li>
                            <a href="<?php echo esc_url(GS_Catalog::category_url($cat['slug'])); ?>">
                                <?php echo esc_html(GS_Catalog::short_title((string) $cat['title'])); ?>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </section>
        <style>
            .gs-footer-links{background:#0a0f1a;color:#94a3b8;padding:28px 16px;font-size:14px;line-height:1.6}
            .gs-footer-links__h{max-width:1200px;margin:0 auto}
            .gs-footer-links__title{margin:0 0 8px;color:#f1f5f9;font-size:17px;font-weight:700;text-align:left}
            .gs-footer-links__lead{margin:0 0 12px}
            .gs-footer-links a{color:#818cf8;text-decoration:none}
            .gs-footer-links a:hover{color:#22d3ee}
            .gs-footer-links__list{display:flex;flex-wrap:wrap;gap:8px 16px;margin:0;padding:0;list-style:none}
        </style>
        <?php
    }
}
