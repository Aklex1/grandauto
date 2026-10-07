<?php
/**
 * Данные каталога звуков + фронтенд-рендер (индекс категорий и страница категории).
 * Перехватывает шорткод [kie_tts_sounds_catalog] у базового плагина kie-tts-wp.
 */

if (!defined('ABSPATH')) {
    exit;
}

class GS_Catalog {

    const SHORTCODE = 'kie_tts_sounds_catalog';
    const CATS_PER_PAGE = 48;
    const SOUNDS_PER_PAGE = 24;

    /** @var array|null Кэш каталога в пределах запроса. */
    private static $cache = null;

    /**
     * Забираем шорткод каталога себе.
     */
    public static function takeover_shortcode() {
        if (shortcode_exists(self::SHORTCODE)) {
            remove_shortcode(self::SHORTCODE);
        }
        add_shortcode(self::SHORTCODE, array(__CLASS__, 'render'));
        add_shortcode('genius_sounds_catalog', array(__CLASS__, 'render'));
    }

    /* ---------------------------------------------------------------------
     * Данные
     *
     * Хранилище разбито на файлы: лёгкий index.json со списком категорий и
     * отдельный cats/<slug>.json на каждую. Единый catalog.json вырос до
     * нескольких мегабайт, и его приходилось декодировать целиком и на каждой
     * странице каталога, и на каждое обновление категории при импорте.
     * ------------------------------------------------------------------ */

    /** @var array|null Список категорий (slug, title, desc, count). */
    private static $index = null;

    /** @var array Кэш полных записей категорий в пределах запроса. */
    private static $cats = array();

    private static function index_path() {
        return GS_Storage::base_dir() . '/index.json';
    }

    private static function cats_dir() {
        return GS_Storage::base_dir() . '/cats';
    }

    private static function cat_path($slug) {
        return self::cats_dir() . '/' . $slug . '.json';
    }

    /**
     * Первичное заполнение: берём SEO-тексты из каталога базового плагина,
     * но выбрасываем его список звуков — он был битым (одни и те же 25 несуществующих файлов
     * во всех 945 категориях).
     */
    public static function ensure_seeded() {
        GS_Storage::ensure_dirs();
        if (!is_dir(self::cats_dir())) {
            wp_mkdir_p(self::cats_dir());
        }
        if (file_exists(self::index_path())) {
            return;
        }

        // Переезд с единого catalog.json на файлы по категориям.
        $legacy_store = GS_Storage::catalog_path();
        if (file_exists($legacy_store)) {
            $decoded = json_decode((string) file_get_contents($legacy_store), true);
            if (is_array($decoded) && !empty($decoded['categories']) && is_array($decoded['categories'])) {
                self::write_all($decoded['categories']);
                @rename($legacy_store, GS_Storage::base_dir() . '/catalog-legacy.json');
                return;
            }
        }

        $categories = array();
        $seed_path = self::legacy_catalog_path();
        if ($seed_path && file_exists($seed_path)) {
            $decoded = json_decode((string) file_get_contents($seed_path), true);
            if (is_array($decoded) && !empty($decoded['categories']) && is_array($decoded['categories'])) {
                foreach ($decoded['categories'] as $cat) {
                    if (!is_array($cat) || empty($cat['slug'])) {
                        continue;
                    }
                    $slug = GS_Storage::sanitize_slug($cat['slug']);
                    if ($slug === '' || isset($categories[$slug])) {
                        continue;
                    }
                    $categories[$slug] = array(
                        'slug'          => $slug,
                        'title'         => isset($cat['title']) ? (string) $cat['title'] : $slug,
                        'headline'      => isset($cat['headline']) ? (string) $cat['headline'] : '',
                        'description'   => isset($cat['description']) ? (string) $cat['description'] : '',
                        'description_2' => isset($cat['description_2']) ? (string) $cat['description_2'] : '',
                        'sounds'        => array(),
                        'imported_at'   => '',
                    );
                }
            }
        }

        self::write_all(array_values($categories));
    }

    /**
     * Записывает набор категорий по файлам и собирает индекс.
     */
    private static function write_all($categories) {
        if (!is_dir(self::cats_dir())) {
            wp_mkdir_p(self::cats_dir());
        }
        $index = array();
        foreach ($categories as $cat) {
            if (!is_array($cat) || empty($cat['slug'])) {
                continue;
            }
            $slug = GS_Storage::sanitize_slug($cat['slug']);
            if ($slug === '') {
                continue;
            }
            $cat['slug'] = $slug;
            self::write_category($cat);
            $index[] = self::index_entry($cat);
        }
        return self::save_index($index);
    }

    private static function write_category($category) {
        return GS_Storage::atomic_put(
            self::cat_path($category['slug']),
            wp_json_encode($category, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }

    /**
     * Строка индекса: всё, что нужно списку категорий и поиску, без списка звуков.
     */
    private static function index_entry($category) {
        $desc = (string) ($category['description'] ?? '');
        if (mb_strlen($desc) > 160) {
            $desc = mb_substr($desc, 0, 160);
        }
        $title = (string) ($category['title'] ?? $category['slug']);
        // Раздел считаем один раз при записи, а не на каждый показ каталога:
        // правил несколько сотен, а подборок почти тысяча.
        $section = isset($category['section']) && $category['section'] !== ''
            ? (string) $category['section']
            : GS_Sections::guess((string) $category['slug'], $title);
        return array(
            'slug'    => (string) $category['slug'],
            'title'   => $title,
            'desc'    => $desc,
            'count'   => self::count_sounds($category),
            'section' => $section,
        );
    }

    /**
     * @return array<int,array{slug:string,title:string,desc:string,count:int}>
     */
    public static function load_index($fresh = false) {
        if (!$fresh && self::$index !== null) {
            return self::$index;
        }
        $items = array();
        $path = self::index_path();
        if (file_exists($path)) {
            $decoded = json_decode((string) file_get_contents($path), true);
            if (is_array($decoded)) {
                $items = $decoded;
            }
        }
        self::$index = $items;
        return $items;
    }

    private static function save_index($items) {
        self::$index = $items;
        return GS_Storage::atomic_put(
            self::index_path(),
            wp_json_encode($items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }

    /**
     * Путь к catalog.json базового плагина (источник SEO-текстов).
     */
    private static function legacy_catalog_path() {
        if (defined('KIE_TTS_PLUGIN_DIR')) {
            return KIE_TTS_PLUGIN_DIR . 'assets/sounds/catalog.json';
        }
        return WP_PLUGIN_DIR . '/kie-tts-wp/assets/sounds/catalog.json';
    }

    /**
     * @return array|null
     */
    public static function get_category($slug) {
        $slug = GS_Storage::sanitize_slug($slug);
        if ($slug === '') {
            return null;
        }
        if (array_key_exists($slug, self::$cats)) {
            return self::$cats[$slug];
        }

        $category = null;
        $path = self::cat_path($slug);
        if (file_exists($path)) {
            $decoded = json_decode((string) file_get_contents($path), true);
            if (is_array($decoded) && !empty($decoded['slug'])) {
                $category = $decoded;
            }
        }
        self::$cats[$slug] = $category;
        return $category;
    }

    /**
     * Обновляет одну категорию (создаёт, если её не было) и её строку в индексе.
     */
    public static function update_category($slug, array $patch) {
        $slug = GS_Storage::sanitize_slug($slug);
        if ($slug === '') {
            return false;
        }

        $existing = self::get_category($slug);
        if (!is_array($existing)) {
            $existing = array(
                'slug'          => $slug,
                'title'         => $slug,
                'headline'      => '',
                'description'   => '',
                'description_2' => '',
                'sounds'        => array(),
                'imported_at'   => '',
            );
        }

        $category = array_merge($existing, $patch);
        $category['slug'] = $slug;
        self::$cats[$slug] = $category;

        if (!self::write_category($category)) {
            return false;
        }

        // Указатель читаем заново, а не из памяти запроса: рядом может
        // идти импорт, который только что дописал в него свои строки.
        // Именно так и потерялись 27 подборок: импорт добавил их в файл,
        // а следующая правка описания записала поверх свою старую копию.
        $lock = self::lock_index();
        $index = self::load_index(true);
        $entry = self::index_entry($category);
        $found = false;
        foreach ($index as $i => $row) {
            if (isset($row['slug']) && $row['slug'] === $slug) {
                $index[$i] = $entry;
                $found = true;
                break;
            }
        }
        if (!$found) {
            $index[] = $entry;
        }
        $saved = self::save_index($index);
        self::unlock_index($lock);
        return $saved;
    }

    /**
     * Замок на время «прочитать указатель — изменить — записать».
     *
     * Сам по себе atomic_put не спасает: он атомарен для записи, но не для
     * пары чтение-запись, а параллельно идут импорт и правка текстов.
     *
     * @return resource|null
     */
    private static function lock_index() {
        $path = self::index_path() . '.lock';
        $handle = @fopen($path, 'c');
        if (!$handle) {
            return null;
        }
        // Ждём недолго: лучше редкая потеря строки, чем зависший запрос.
        @flock($handle, LOCK_EX);
        return $handle;
    }

    private static function unlock_index($handle) {
        if (is_resource($handle)) {
            @flock($handle, LOCK_UN);
            @fclose($handle);
        }
    }

    /**
     * Раздел подборки: сохранённый, иначе вычисленный по названию.
     */
    public static function section_of($category) {
        if (is_array($category) && !empty($category['section'])) {
            return (string) $category['section'];
        }
        $slug  = is_array($category) ? (string) ($category['slug'] ?? '') : (string) $category;
        $title = is_array($category) ? (string) ($category['title'] ?? '') : '';
        return GS_Sections::guess($slug, $title);
    }

    /**
     * Пересобрать индекс: разделы и заголовки.
     *
     * Читаем только индекс, не файлы подборок: их почти тысяча, в каждой до
     * сотни звуков, а памяти у WordPress на этом хостинге 40 МБ. Раздел
     * вычисляется по слагу и названию — они в индексе есть.
     */
    public static function rebuild_index() {
        $lock = self::lock_index();
        $index = self::load_index(true);
        $out = array();
        foreach ($index as $row) {
            if (!is_array($row) || empty($row['slug'])) {
                continue;
            }
            $row['section'] = GS_Sections::guess((string) $row['slug'], (string) ($row['title'] ?? ''));
            $out[] = $row;
        }
        self::save_index($out);
        self::unlock_index($lock);
        return count($out);
    }

    /**
     * Пересобрать указатель по самим файлам подборок.
     *
     * Указатель — производная от файлов, и когда он расходится с ними
     * (так и вышло: параллельные записи затёрли часть строк), правда лежит
     * в файлах. Идём пачками: подборок больше тысячи, а памяти у WordPress
     * на этом хостинге 40 МБ, и держать их все разом нельзя.
     *
     * @return array{done:int,total:int,next:int}
     */
    public static function rebuild_from_files($offset = 0, $limit = 100) {
        $dir = self::cats_dir();
        $names = is_dir($dir) ? scandir($dir) : array();
        $files = array();
        foreach ((array) $names as $name) {
            if (substr($name, -5) === '.json') {
                $files[] = $name;
            }
        }
        sort($files);
        $total = count($files);
        $slice = array_slice($files, max(0, (int) $offset), max(1, (int) $limit));

        $lock = self::lock_index();
        $index = self::load_index(true);
        $by_slug = array();
        foreach ($index as $i => $row) {
            if (!empty($row['slug'])) {
                $by_slug[(string) $row['slug']] = $i;
            }
        }

        $done = 0;
        foreach ($slice as $name) {
            $raw = @file_get_contents($dir . '/' . $name);
            if ($raw === false) {
                continue;
            }
            $category = json_decode($raw, true);
            unset($raw);
            if (!is_array($category) || empty($category['slug'])) {
                continue;
            }
            $entry = self::index_entry($category);
            unset($category);
            $slug = $entry['slug'];
            if (isset($by_slug[$slug])) {
                $index[$by_slug[$slug]] = $entry;
            } else {
                $index[] = $entry;
                $by_slug[$slug] = count($index) - 1;
            }
            $done++;
        }

        self::save_index($index);
        self::unlock_index($lock);

        return array('done' => $done, 'total' => $total, 'next' => (int) $offset + count($slice));
    }

    public static function count_sounds($category) {
        return (isset($category['sounds']) && is_array($category['sounds'])) ? count($category['sounds']) : 0;
    }

    /**
     * Сводка по каталогу для админки и хедера страницы.
     */
    public static function stats() {
        $filled = 0;
        $sounds = 0;
        $index = self::load_index();
        foreach ($index as $row) {
            $n = (int) ($row['count'] ?? 0);
            if ($n > 0) {
                $filled++;
            }
            $sounds += $n;
        }
        return array(
            'categories' => count($index),
            'filled'     => $filled,
            'sounds'     => $sounds,
        );
    }

    /* ---------------------------------------------------------------------
     * URL и определение страницы
     * ------------------------------------------------------------------ */

    public static function base_url() {
        $pid = (int) get_option('kie_tts_sounds_page_id');
        if ($pid > 0) {
            $url = get_permalink($pid);
            if ($url) {
                return trailingslashit($url);
            }
        }
        return trailingslashit(home_url('/sounds-catalog'));
    }

    public static function category_url($slug) {
        $slug = GS_Storage::sanitize_slug($slug);
        $base = self::base_url();
        if ($slug === '') {
            return $base;
        }
        if ((string) get_option('permalink_structure') !== '') {
            return trailingslashit($base . rawurlencode($slug));
        }
        return add_query_arg('sound_cat', $slug, $base);
    }

    /**
     * Адрес страницы списка.
     *
     * Вторая и следующие страницы жили на ?gs_page=2. Поиск считает такой
     * адрес отдельной страницей — в индексе Яндекса лежат и ?gs_page=2, и
     * ?gs_page=20, и ?gs_page=4: мусор, который делит вес с настоящими
     * страницами и путает отчёты. Адрес вида /page/2/ поиск понимает как
     * продолжение списка, а не как новую сущность.
     */
    public static function page_url($slug = '', $page = 1) {
        $page = max(1, (int) $page);
        $base = $slug === '' ? self::base_url() : self::category_url($slug);
        if ($page === 1) {
            return $base;
        }
        if ((string) get_option('permalink_structure') === '') {
            return add_query_arg('gs_page', $page, $base);
        }
        return trailingslashit($base . 'page/' . $page);
    }

    /**
     * Запрошенная страница списка: сначала из адреса, потом из старого
     * параметра — ссылки на него ещё живут в чужих закладках и в индексе.
     */
    public static function requested_page() {
        $page = (int) get_query_var('gs_page');
        if ($page < 1 && isset($_GET['gs_page'])) {
            $page = (int) $_GET['gs_page'];
        }
        return max(1, $page);
    }

    public static function file_url($relative) {
        return GS_Storage::files_url() . '/' . ltrim((string) $relative, '/');
    }

    /**
     * Правила для /page/N/ у каталога и у категории.
     *
     * Базовый плагин завёл только правило вида /sounds-catalog/<категория>/,
     * и трогать его нельзя — правки в чужом плагине теряются при обновлении.
     * Поэтому свои правила добавляем сверху: они разбирают хвост /page/N/ и
     * передают остальное так же, как раньше делал запрос со знаком вопроса.
     */
    public static function add_rewrite_rules() {
        $base = trim((string) wp_parse_url(self::base_url(), PHP_URL_PATH), '/');
        if ($base === '') {
            return;
        }
        $pid = (int) get_option('kie_tts_sounds_page_id');
        if ($pid <= 0) {
            return;
        }
        $quoted = preg_quote($base, '~');
        add_rewrite_rule(
            '^' . $quoted . '/page/([0-9]{1,6})/?$',
            'index.php?page_id=' . $pid . '&gs_page=$matches[1]',
            'top'
        );
        add_rewrite_rule(
            '^' . $quoted . '/([^/]+)/page/([0-9]{1,6})/?$',
            'index.php?page_id=' . $pid . '&kie_sound_category=$matches[1]&gs_page=$matches[2]',
            'top'
        );
    }

    public static function add_query_vars($vars) {
        $vars[] = 'gs_page';
        return $vars;
    }

    /**
     * Старый адрес со знаком вопроса уводим на новый.
     *
     * Без этого в индексе останутся оба, и поиск сам решит, какой из них
     * дубль. Решать это должны мы: постоянный редирект склеивает их и
     * передаёт новому адресу всё, что успел набрать старый.
     */
    public static function redirect_legacy_page() {
        if (is_admin() || !isset($_GET['gs_page']) || !self::is_catalog_request()) {
            return;
        }
        if ((string) get_option('permalink_structure') === '') {
            return;
        }
        $page = max(1, (int) $_GET['gs_page']);
        $target = self::page_url(self::requested_slug(), $page);
        $query = isset($_GET['gs_q']) ? sanitize_text_field(wp_unslash((string) $_GET['gs_q'])) : '';
        if ($query !== '') {
            // Поиск по каталогу — страница служебная, её адрес не трогаем.
            return;
        }
        wp_safe_redirect($target, 301);
        exit;
    }

    /**
     * Текущий запрошенный слаг категории (ЧПУ базового плагина или ?sound_cat=).
     */
    public static function requested_slug() {
        $slug = sanitize_title((string) get_query_var('kie_sound_category'));
        if ($slug === '' && isset($_GET['sound_cat'])) {
            $slug = sanitize_title(wp_unslash((string) $_GET['sound_cat']));
        }
        return GS_Storage::sanitize_slug($slug);
    }

    public static function is_catalog_request() {
        $pid = (int) get_option('kie_tts_sounds_page_id');
        if ($pid > 0 && is_page($pid)) {
            return true;
        }
        global $post;
        if ($post instanceof WP_Post && has_shortcode((string) $post->post_content, self::SHORTCODE)) {
            return true;
        }
        return false;
    }

    /* ---------------------------------------------------------------------
     * Рендер
     * ------------------------------------------------------------------ */

    public static function render($atts = array()) {
        $atts = shortcode_atts(array('category' => ''), (array) $atts, self::SHORTCODE);

        $slug = self::requested_slug();
        if ($slug === '' && $atts['category'] !== '') {
            $slug = GS_Storage::sanitize_slug($atts['category']);
        }

        if ($slug !== '') {
            $category = self::get_category($slug);
            if ($category) {
                return self::render_category($category);
            }
            // Подборки нет — возможно, это раздел. Порядок именно такой:
            // подборок почти тысяча, и ни одна не должна пропасть из-за
            // того, что кто-то назвал раздел тем же словом.
            $section = GS_Sections::get($slug);
            if ($section) {
                return self::render_section($section);
            }
            return self::render_not_found();
        }

        return self::render_index();
    }

    private static function render_not_found() {
        ob_start();
        ?>
        <div class="gs-wrap">
            <div class="gs-empty gs-empty--page">
                <div class="gs-empty__icon" aria-hidden="true">🔍</div>
                <h1 class="gs-empty__title">Категория не найдена</h1>
                <p class="gs-empty__text">Такой подборки нет или она была переименована.</p>
                <a class="gs-btn gs-btn--primary" href="<?php echo esc_url(self::base_url()); ?>">Все категории звуков</a>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Главная каталога: поиск, популярные подборки, сетка категорий с пагинацией.
     */
    private static function render_index() {
        $cats  = self::load_index();
        $stats = self::stats();

        $query = isset($_GET['gs_q']) ? sanitize_text_field(wp_unslash((string) $_GET['gs_q'])) : '';
        $page  = self::requested_page();

        // Сначала заполненные категории, внутри — по числу звуков.
        $list = array();
        foreach ($cats as $cat) {
            if (!is_array($cat) || empty($cat['slug'])) {
                continue;
            }
            $n = (int) ($cat['count'] ?? 0);
            if ($query !== '') {
                $haystack = mb_strtolower(($cat['title'] ?? '') . ' ' . ($cat['desc'] ?? '') . ' ' . $cat['slug']);
                if (mb_strpos($haystack, mb_strtolower($query)) === false) {
                    continue;
                }
            }
            $cat['_count'] = $n;
            $list[] = $cat;
        }

        usort($list, function ($a, $b) {
            if ($a['_count'] === $b['_count']) {
                return strcmp((string) $a['title'], (string) $b['title']);
            }
            return $b['_count'] <=> $a['_count'];
        });

        $total = count($list);
        $pages = max(1, (int) ceil($total / self::CATS_PER_PAGE));
        $page  = min($page, $pages);
        $slice = array_slice($list, ($page - 1) * self::CATS_PER_PAGE, self::CATS_PER_PAGE);

        ob_start();
        ?>
        <div class="gs-wrap gs-catalog">
            <section class="gs-hero">
                <span class="gs-hero__badge">Библиотека звуков Genius-bot</span>
                <h1 class="gs-hero__title">Каталог звуков и звуковых эффектов</h1>
                <p class="gs-hero__lead">Готовые звуки для монтажа, игр, роликов и стримов — слушайте прямо в браузере и скачивайте в MP3. А если нужного звука нет — сгенерируйте его нейросетью за пару секунд.</p>

                <div class="gs-stats" role="list">
                    <div class="gs-stat" role="listitem">
                        <span class="gs-stat__value"><?php echo esc_html(number_format_i18n($stats['sounds'])); ?></span>
                        <span class="gs-stat__label">звуков в библиотеке</span>
                    </div>
                    <div class="gs-stat" role="listitem">
                        <span class="gs-stat__value"><?php echo esc_html(number_format_i18n($stats['categories'])); ?></span>
                        <span class="gs-stat__label">категорий</span>
                    </div>
                    <div class="gs-stat" role="listitem">
                        <span class="gs-stat__value">MP3</span>
                        <span class="gs-stat__label">бесплатное скачивание</span>
                    </div>
                </div>

                <form class="gs-search" method="get" action="<?php echo esc_url(self::base_url()); ?>" role="search">
                    <label class="gs-search__label" for="gs-search-input">Поиск по каталогу звуков</label>
                    <div class="gs-search__row">
                        <input id="gs-search-input" class="gs-search__input" type="search" name="gs_q"
                               value="<?php echo esc_attr($query); ?>"
                               placeholder="Например: выстрел, дождь, шаги, уведомление" autocomplete="off">
                        <button class="gs-btn gs-btn--primary gs-search__submit" type="submit">Найти</button>
                    </div>
                </form>
            </section>

            <?php echo GS_Intent::render_switch($query, 'search'); // phpcs:ignore WordPress.Security.EscapeOutput ?>

            <?php echo GS_Intent::render_router('catalog'); // phpcs:ignore WordPress.Security.EscapeOutput ?>

            <?php echo self::render_cta('', 'index'); // phpcs:ignore WordPress.Security.EscapeOutput ?>

            <?php echo self::render_services(); // phpcs:ignore WordPress.Security.EscapeOutput ?>

            <?php if ($query === '' && $page === 1) {
                echo self::render_sections_nav(); // phpcs:ignore WordPress.Security.EscapeOutput
            } ?>

            <?php if ($query !== ''): ?>
                <div class="gs-section-head">
                    <h2 class="gs-section-title">Результаты: «<?php echo esc_html($query); ?>»</h2>
                    <span class="gs-section-meta"><?php echo esc_html(self::plural_categories($total)); ?></span>
                    <a class="gs-section-reset" href="<?php echo esc_url(self::base_url()); ?>">Сбросить</a>
                </div>
            <?php else: ?>
                <div class="gs-section-head">
                    <h2 class="gs-section-title">Все категории</h2>
                    <span class="gs-section-meta"><?php echo esc_html(self::plural_categories($total)); ?></span>
                </div>
            <?php endif; ?>

            <?php if (empty($slice)): ?>
                <div class="gs-empty">
                    <div class="gs-empty__icon" aria-hidden="true">🤷</div>
                    <p class="gs-empty__text">По запросу ничего не нашлось. Попробуйте другое слово — или создайте нужный звук сами.</p>
                    <div class="gs-empty__actions">
                        <a class="gs-btn gs-btn--primary" href="<?php echo esc_url(GS_Pages::get_studio_url($query)); ?>">Сгенерировать звук</a>
                        <a class="gs-btn gs-btn--ghost" href="<?php echo esc_url(GS_Pages::get_tts_url()); ?>">Озвучить текст голосом</a>
                    </div>
                </div>
            <?php else: ?>
                <ul class="gs-cat-grid">
                    <?php foreach ($slice as $cat): ?>
                        <li class="gs-cat-card<?php echo $cat['_count'] > 0 ? '' : ' is-empty'; ?>">
                            <a class="gs-cat-card__link" href="<?php echo esc_url(self::category_url($cat['slug'])); ?>">
                                <span class="gs-cat-card__title"><?php echo esc_html(self::short_title($cat['title'])); ?></span>
                                <span class="gs-cat-card__meta">
                                    <?php if ($cat['_count'] > 0): ?>
                                        <span class="gs-chip gs-chip--ok"><?php echo esc_html(self::plural_sounds($cat['_count'])); ?></span>
                                    <?php else: ?>
                                        <span class="gs-chip">скоро</span>
                                    <?php endif; ?>
                                </span>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>

                <?php echo self::render_pagination($page, $pages, array('gs_q' => $query)); // phpcs:ignore WordPress.Security.EscapeOutput ?>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Страница раздела: подборки одной темы.
     *
     * Промежуточный уровень нужен не посетителю (он и так пришёл из поиска
     * на нужную подборку), а обходу: с каталога уходит дюжина ссылок вместо
     * 945, и робот доходит до дальних подборок за дни, а не за месяцы.
     */
    private static function render_section($section) {
        $cats = GS_Sections::categories($section['slug']);
        $page  = self::requested_page();
        $total = count($cats);
        $pages = max(1, (int) ceil($total / self::CATS_PER_PAGE));
        $page  = min($page, $pages);
        $slice = array_slice($cats, ($page - 1) * self::CATS_PER_PAGE, self::CATS_PER_PAGE);
        $sounds = 0;
        foreach ($cats as $row) {
            $sounds += (int) ($row['count'] ?? 0);
        }

        ob_start();
        ?>
        <div class="gs-wrap gs-catalog gs-section-page">
            <nav class="gs-breadcrumbs" aria-label="Хлебные крошки">
                <a href="<?php echo esc_url(home_url('/')); ?>">Главная</a>
                <span aria-hidden="true">/</span>
                <a href="<?php echo esc_url(self::base_url()); ?>">Каталог звуков</a>
                <span aria-hidden="true">/</span>
                <span class="gs-breadcrumbs__current"><?php echo esc_html($section['menu']); ?></span>
            </nav>

            <section class="gs-hero">
                <h1 class="gs-hero__title"><?php echo esc_html($section['title']); ?></h1>
                <p class="gs-hero__lead"><?php echo esc_html($section['lead']); ?></p>
                <div class="gs-hero__meta">
                    <span class="gs-chip gs-chip--ok"><?php echo esc_html(self::plural_sounds($sounds)); ?></span>
                    <span class="gs-chip"><?php echo esc_html(self::plural_categories($total)); ?></span>
                    <span class="gs-chip">MP3</span>
                </div>
            </section>

            <?php if (!empty($slice)): ?>
                <ul class="gs-cat-grid">
                    <?php foreach ($slice as $cat): ?>
                        <li class="gs-cat-card<?php echo (int) ($cat['count'] ?? 0) > 0 ? '' : ' is-empty'; ?>">
                            <a class="gs-cat-card__link" href="<?php echo esc_url(self::category_url($cat['slug'])); ?>">
                                <span class="gs-cat-card__title"><?php echo esc_html(self::short_title($cat['title'])); ?></span>
                                <span class="gs-cat-card__meta">
                                    <?php if ((int) ($cat['count'] ?? 0) > 0): ?>
                                        <span class="gs-chip gs-chip--ok"><?php echo esc_html(self::plural_sounds((int) $cat['count'])); ?></span>
                                    <?php else: ?>
                                        <span class="gs-chip">скоро</span>
                                    <?php endif; ?>
                                </span>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <?php echo self::render_pagination($page, $pages, array()); // phpcs:ignore WordPress.Security.EscapeOutput ?>
            <?php endif; ?>

            <?php echo self::render_cta($section['menu'], 'section'); // phpcs:ignore WordPress.Security.EscapeOutput ?>

            <?php echo self::render_services(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
            <?php echo self::render_sections_nav($section['slug']); // phpcs:ignore WordPress.Security.EscapeOutput ?>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Список разделов. Показываем и на каталоге, и внизу раздела: это
     * единственная перелинковка между темами, которая есть у каталога.
     */
    public static function render_sections_nav($current = '') {
        $sections = GS_Sections::overview();
        if (count($sections) < 2) {
            return '';
        }
        ob_start();
        ?>
        <section class="gs-sections">
            <div class="gs-section-head">
                <h2 class="gs-section-title">Разделы каталога</h2>
            </div>
            <ul class="gs-sections__grid">
                <?php foreach ($sections as $item): ?>
                    <li class="gs-sections__item<?php echo $item['slug'] === $current ? ' is-current' : ''; ?>">
                        <a class="gs-sections__link" href="<?php echo esc_url(GS_Sections::url($item['slug'])); ?>">
                            <span class="gs-sections__name"><?php echo esc_html($item['menu']); ?></span>
                            <span class="gs-sections__meta"><?php echo esc_html(self::plural_sounds((int) $item['sounds'])); ?></span>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>
        <?php
        return ob_get_clean();
    }

    /**
     * Страница одной категории: описание, сетка звуков с плеером, CTA, соседние подборки.
     */
    private static function render_category($category) {
        $slug   = $category['slug'];
        $title  = (string) ($category['title'] ?? $slug);
        $sounds = (isset($category['sounds']) && is_array($category['sounds'])) ? $category['sounds'] : array();

        $page  = self::requested_page();
        $total = count($sounds);
        $pages = max(1, (int) ceil($total / self::SOUNDS_PER_PAGE));
        $page  = min($page, $pages);
        $slice = array_slice($sounds, ($page - 1) * self::SOUNDS_PER_PAGE, self::SOUNDS_PER_PAGE);

        ob_start();
        ?>
        <div class="gs-wrap gs-category">
            <?php $section = GS_Sections::get(self::section_of($category)); ?>
            <nav class="gs-breadcrumbs" aria-label="Хлебные крошки">
                <a href="<?php echo esc_url(home_url('/')); ?>">Главная</a>
                <span aria-hidden="true">/</span>
                <a href="<?php echo esc_url(self::base_url()); ?>">Каталог звуков</a>
                <?php if ($section): ?>
                    <span aria-hidden="true">/</span>
                    <a href="<?php echo esc_url(GS_Sections::url($section['slug'])); ?>"><?php echo esc_html($section['menu']); ?></a>
                <?php endif; ?>
                <span aria-hidden="true">/</span>
                <span class="gs-breadcrumbs__current"><?php echo esc_html(self::short_title($title)); ?></span>
            </nav>

            <section class="gs-hero gs-hero--category">
                <h1 class="gs-hero__title"><?php echo esc_html($title); ?></h1>
                <?php if (!empty($category['description'])): ?>
                    <p class="gs-hero__lead"><?php echo esc_html(self::sync_description_count($category['description'], $total)); ?></p>
                <?php endif; ?>
                <div class="gs-hero__meta">
                    <span class="gs-chip gs-chip--ok"><?php echo esc_html(self::plural_sounds($total)); ?></span>
                    <span class="gs-chip">MP3</span>
                    <span class="gs-chip">бесплатно</span>
                    <a class="gs-chip gs-chip--link" href="<?php echo esc_url(GS_Pages::get_studio_url(self::short_title($title))); ?>">Сгенерировать свой звук</a>
                    <a class="gs-chip gs-chip--voice" href="<?php echo esc_url(GS_Pages::get_tts_url()); ?>">Нужен голос — в озвучку</a>
                </div>
            </section>

            <?php if ($total > 0): ?>
                <div class="gs-sounds" data-gs-player>
                    <?php foreach ($slice as $index => $sound): ?>
                        <?php
                        $s_title = (string) ($sound['title'] ?? 'Звук');
                        $s_file  = (string) ($sound['file'] ?? '');
                        if ($s_file === '') {
                            continue;
                        }
                        $s_url  = self::file_url($s_file);
                        $s_dur  = GS_Storage::format_duration($sound['duration'] ?? 0);
                        $s_size = GS_Storage::format_size($sound['size'] ?? 0);
                        ?>
                        <article class="gs-sound" data-gs-sound data-src="<?php echo esc_url($s_url); ?>" data-title="<?php echo esc_attr($s_title); ?>">
                            <button class="gs-sound__play" type="button" data-gs-play aria-label="Прослушать: <?php echo esc_attr($s_title); ?>">
                                <span class="gs-sound__icon gs-sound__icon--play" aria-hidden="true"></span>
                                <span class="gs-sound__icon gs-sound__icon--pause" aria-hidden="true"></span>
                            </button>

                            <div class="gs-sound__body">
                                <h3 class="gs-sound__title"><?php echo esc_html($s_title); ?></h3>
                                <div class="gs-sound__meta">
                                    <?php if ($s_dur !== ''): ?><span data-gs-time><?php echo esc_html($s_dur); ?></span><?php endif; ?>
                                    <?php if ($s_size !== ''): ?><span><?php echo esc_html($s_size); ?></span><?php endif; ?>
                                    <span class="gs-sound__format">MP3</span>
                                </div>
                                <div class="gs-sound__progress" data-gs-progress>
                                    <div class="gs-sound__bar" data-gs-bar></div>
                                </div>
                            </div>

                            <a class="gs-sound__download" href="<?php echo esc_url($s_url); ?>" download
                               aria-label="Скачать: <?php echo esc_attr($s_title); ?>">
                                <span aria-hidden="true">↓</span><span class="gs-sound__download-text">Скачать</span>
                            </a>
                        </article>
                    <?php endforeach; ?>
                </div>

                <?php echo self::render_pagination($page, $pages, array()); // phpcs:ignore WordPress.Security.EscapeOutput ?>
            <?php else: ?>
                <div class="gs-empty">
                    <div class="gs-empty__icon" aria-hidden="true">🎧</div>
                    <h2 class="gs-empty__title">Звуки этой подборки скоро появятся</h2>
                    <p class="gs-empty__text">Подборка ещё наполняется. Не ждите — соберите нужный звук нейросетью прямо сейчас.</p>
                    <div class="gs-empty__actions">
                        <a class="gs-btn gs-btn--primary" href="<?php echo esc_url(GS_Pages::get_studio_url(self::short_title($title))); ?>">Сгенерировать звук</a>
                        <a class="gs-btn gs-btn--ghost" href="<?php echo esc_url(GS_Pages::get_tts_url()); ?>">Озвучить текст голосом</a>
                    </div>
                </div>
            <?php endif; ?>

            <?php echo self::render_cta(self::short_title($title), 'category'); // phpcs:ignore WordPress.Security.EscapeOutput ?>

            <?php if (!empty($category['description_2'])): ?>
                <section class="gs-seo">
                    <?php if (!empty($category['headline'])): ?>
                        <h2 class="gs-seo__title"><?php echo esc_html(self::headline_to_title($category['headline'])); ?></h2>
                    <?php endif; ?>
                    <p><?php echo esc_html($category['description_2']); ?></p>
                </section>
            <?php endif; ?>

            <?php echo self::render_related($slug); // phpcs:ignore WordPress.Security.EscapeOutput ?>

            <?php echo self::render_services(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Перелинковка с каталога на остальные разделы сайта.
     *
     * Каталог держит 83% показов в поиске — это единственная часть сайта, у
     * которой уже есть вес, чтобы его передавать. Остальные разделы
     * опубликованы недавно и в выдаче почти не видны.
     *
     * Порядок не случайный: сначала то, что продолжает тему страницы
     * (голос, музыка, звук из видео), потом остальное. Документы и
     * презентации к звуку медведя отношения не имеют, поэтому стоят
     * отдельной строкой и подписаны как другое направление — иначе это
     * выглядит как ошибка вёрстки, а читается как спам.
     */
    private static function render_services() {
        $lab = class_exists('GS_Lab') ? GS_Lab::available_services() : array();
        $url = function ($id) use ($lab) {
            return isset($lab[$id]) ? GS_Lab::get_url($id) : '';
        };

        $audio = array(
            array(GS_Pages::get_tts_url(), 'Озвучить текст', 'Голосом нейросети, 40+ голосов'),
            array($url('music'), 'Создать музыку', 'Трек по описанию, с вокалом или без'),
            array($url('ytaudio'), 'Звук из видео', 'Дорожка из ролика по ссылке'),
            array($url('vocal'), 'Убрать вокал', 'Минус и голос отдельными файлами'),
            array($url('stt'), 'Расшифровка записи', 'Текст с отметками времени'),
            array($url('denoise'), 'Убрать шум', 'Чистая запись из шумной'),
        );
        $other = array(
            array(class_exists('GS_Legal') ? GS_Legal::get_url('claim') : '', 'Претензия и жалоба'),
            array(class_exists('GS_Legal') ? GS_Legal::get_url('family') : '', 'Развод и алименты'),
            array(class_exists('GS_Legal') ? GS_Legal::get_url('teacher') : '', 'Документы учителю'),
            array($url('avatar'), 'Говорящий аватар'),
            array(class_exists('GS_Slides_Page') ? GS_Slides_Page::get_url() : '', 'Презентации'),
            array(class_exists('GS_Api_Page') ? GS_Api_Page::get_url() : '', 'API для разработчиков'),
        );

        $audio = array_values(array_filter($audio, function ($row) { return $row[0] !== ''; }));
        $other = array_values(array_filter($other, function ($row) { return $row[0] !== ''; }));
        if (!$audio && !$other) {
            return '';
        }

        ob_start();
        ?>
        <section class="gs-services">
            <h2 class="gs-section-title">Сделать свой звук и не только</h2>
            <?php if ($audio): ?>
                <ul class="gs-services__grid">
                    <?php foreach ($audio as $row): ?>
                        <li>
                            <a class="gs-services__card" href="<?php echo esc_url($row[0]); ?>">
                                <span class="gs-services__name"><?php echo esc_html($row[1]); ?></span>
                                <span class="gs-services__lead"><?php echo esc_html($row[2]); ?></span>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <?php if ($other): ?>
                <p class="gs-services__more">
                    <span>Другие сервисы:</span>
                    <?php foreach ($other as $i => $row): ?>
                        <a href="<?php echo esc_url($row[0]); ?>"><?php echo esc_html($row[1]); ?></a><?php
                        echo $i < count($other) - 1 ? '<span aria-hidden="true">·</span>' : ''; ?>
                    <?php endforeach; ?>
                </p>
            <?php endif; ?>
        </section>
        <?php
        return ob_get_clean();
    }

    /**
     * Призыв сделать звук самому — ведёт в студию генерации внутри сайта.
     *
     * Рядом со звуком стоит вторая дорожка — озвучка текста. Раньше её тут
     * не было, и часть людей пыталась получить голос в генераторе звуков:
     * писали реплику в поле «Опишите звук», платили и получали шум.
     */
    public static function render_cta($topic = '', $context = 'index') {
        $studio = GS_Pages::get_studio_url($topic);
        $tts    = GS_Pages::get_tts_url();
        $placeholder = $topic !== ''
            ? sprintf('Например: %s, кинематографично, 3 секунды', mb_strtolower($topic))
            : 'Например: раскат грома вдалеке, глубокий бас, 4 секунды';

        ob_start();
        ?>
        <section class="gs-cta gs-cta--split">
            <div class="gs-cta__lane gs-cta__lane--sfx">
                <div class="gs-cta__text">
                    <span class="gs-cta__badge">Звук и эффекты</span>
                    <h2 class="gs-cta__title">
                        <?php if ($context === 'category' && $topic !== ''): ?>
                            Не нашли нужный звук в подборке «<?php echo esc_html($topic); ?>»?
                        <?php else: ?>
                            Нужного звука нет в каталоге?
                        <?php endif; ?>
                    </h2>
                    <p class="gs-cta__lead">Опишите звук словами — и получите готовый уникальный эффект за несколько секунд. Без авторских прав, сразу в MP3.</p>
                </div>

                <form class="gs-cta__form" method="get" action="<?php echo esc_url(GS_Pages::get_studio_url()); ?>">
                    <label class="gs-cta__label" for="gs-cta-prompt-<?php echo esc_attr($context); ?>">Опишите звук</label>
                    <div class="gs-cta__row">
                        <input id="gs-cta-prompt-<?php echo esc_attr($context); ?>" class="gs-cta__input" type="text" name="prompt"
                               placeholder="<?php echo esc_attr($placeholder); ?>" autocomplete="off">
                        <button class="gs-btn gs-btn--primary gs-cta__submit" type="submit">Создать звук</button>
                    </div>
                    <p class="gs-cta__hint">Генерация идёт в <a href="<?php echo esc_url($studio); ?>">студии звуков</a> прямо на сайте — результат сразу можно скачать.</p>
                </form>
            </div>

            <div class="gs-cta__lane gs-cta__lane--tts">
                <div class="gs-cta__text">
                    <span class="gs-cta__badge gs-cta__badge--voice">Голос и речь</span>
                    <h2 class="gs-cta__title">Нужно, чтобы текст произнесли вслух?</h2>
                    <p class="gs-cta__lead">Это другой сервис. Генератор звуков слова не выговаривает — реплику озвучивает <strong>озвучка текста</strong>: 60+ живых голосов, русский и ещё 30 языков, скорость и интонация.</p>
                </div>
                <ul class="gs-cta__list">
                    <li>Реплики для роликов и рилсов</li>
                    <li>Закадровый голос и аудиокниги</li>
                    <li>Автоответчик, боты, объявления</li>
                </ul>
                <a class="gs-btn gs-btn--ghost gs-cta__submit" href="<?php echo esc_url($tts); ?>">Открыть озвучку текста</a>
            </div>
        </section>
        <?php
        return ob_get_clean();
    }

    /**
     * Похожие подборки.
     *
     * Раньше блок собирался через shuffle(): на каждый запрос страница отдавала
     * другой набор ссылок. Для поисковика это нестабильный граф — вес по такому
     * не передаётся. Теперь подбор детерминированный: сначала категории с общими
     * словами в заголовке, затем соседи по каталогу.
     *
     * @return array<int,array> строки индекса
     */
    public static function related_categories($current_slug, $limit = 10) {
        $index = self::load_index();
        $current = null;
        $pos = -1;
        foreach ($index as $i => $row) {
            if (!empty($row['slug']) && $row['slug'] === $current_slug) {
                $current = $row;
                $pos = $i;
                break;
            }
        }
        if ($current === null) {
            return array();
        }

        $own = self::title_tokens((string) $current['title']);
        $scored = array();
        foreach ($index as $row) {
            if (empty($row['slug']) || $row['slug'] === $current_slug) {
                continue;
            }
            if ((int) ($row['count'] ?? 0) <= 0) {
                continue;
            }
            $shared = array_intersect($own, self::title_tokens((string) $row['title']));
            if (empty($shared)) {
                continue;
            }
            $scored[] = array(
                'row'   => $row,
                'score' => count($shared),
            );
        }

        usort($scored, function ($a, $b) {
            if ($a['score'] !== $b['score']) {
                return $b['score'] <=> $a['score'];
            }
            $ca = (int) ($a['row']['count'] ?? 0);
            $cb = (int) ($b['row']['count'] ?? 0);
            if ($ca !== $cb) {
                return $cb <=> $ca;
            }
            return strcmp((string) $a['row']['slug'], (string) $b['row']['slug']);
        });

        $result = array();
        $seen = array($current_slug => true);
        foreach ($scored as $item) {
            if (count($result) >= $limit) {
                break;
            }
            $slug = (string) $item['row']['slug'];
            if (isset($seen[$slug])) {
                continue;
            }
            $seen[$slug] = true;
            $result[] = $item['row'];
        }

        // Добираем соседями по каталогу, чтобы блок не пустовал у редких тем.
        for ($step = 1; count($result) < $limit && $step < count($index); $step++) {
            foreach (array($pos - $step, $pos + $step) as $i) {
                if ($i < 0 || $i >= count($index) || count($result) >= $limit) {
                    continue;
                }
                $row = $index[$i];
                $slug = isset($row['slug']) ? (string) $row['slug'] : '';
                if ($slug === '' || isset($seen[$slug]) || (int) ($row['count'] ?? 0) <= 0) {
                    continue;
                }
                $seen[$slug] = true;
                $result[] = $row;
            }
        }

        return $result;
    }

    /**
     * Значимые слова заголовка для поиска похожих подборок.
     */
    private static function title_tokens($title) {
        $title = mb_strtolower(self::short_title($title));
        $parts = preg_split('~[^\p{L}\p{N}]+~u', $title, -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($parts)) {
            return array();
        }
        $stop = array('звук', 'звуки', 'звука', 'звуков', 'скачать', 'бесплатно', 'для', 'при',
                      'онлайн', 'подборка', 'эффект', 'эффекты', 'сборник', 'без');
        $tokens = array();
        foreach ($parts as $word) {
            if (mb_strlen($word) < 4 || in_array($word, $stop, true)) {
                continue;
            }
            // Грубая нормализация окончаний: «солдат/солдаты/солдатов» → общий корень.
            $tokens[] = mb_substr($word, 0, 6);
        }
        return array_values(array_unique($tokens));
    }

    /**
     * Предыдущая и следующая категории каталога — стабильная цепочка,
     * по которой обход доходит до глубоких страниц.
     *
     * @return array{prev:?array,next:?array}
     */
    private static function adjacent_categories($current_slug) {
        $index = self::load_index();
        $pos = -1;
        foreach ($index as $i => $row) {
            if (!empty($row['slug']) && $row['slug'] === $current_slug) {
                $pos = $i;
                break;
            }
        }
        if ($pos < 0) {
            return array('prev' => null, 'next' => null);
        }
        return array(
            'prev' => $pos > 0 ? $index[$pos - 1] : null,
            'next' => isset($index[$pos + 1]) ? $index[$pos + 1] : null,
        );
    }

    private static function render_related($current_slug) {
        $related = self::related_categories($current_slug, 10);
        $adjacent = self::adjacent_categories($current_slug);

        if (empty($related) && empty($adjacent['prev']) && empty($adjacent['next'])) {
            return '';
        }

        ob_start();
        ?>
        <section class="gs-related">
            <h2 class="gs-section-title">Похожие подборки звуков</h2>
            <?php if (!empty($related)): ?>
                <ul class="gs-related__list">
                    <?php foreach ($related as $cat): ?>
                        <li>
                            <a class="gs-related__link" href="<?php echo esc_url(self::category_url($cat['slug'])); ?>">
                                <?php echo esc_html(self::short_title($cat['title'])); ?>
                                <span class="gs-chip gs-chip--ok"><?php echo (int) ($cat['count'] ?? 0); ?></span>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <?php if (!empty($adjacent['prev']) || !empty($adjacent['next'])): ?>
                <nav class="gs-adjacent" aria-label="Соседние подборки">
                    <?php if (!empty($adjacent['prev'])): ?>
                        <a class="gs-adjacent__link" href="<?php echo esc_url(self::category_url($adjacent['prev']['slug'])); ?>">
                            <span class="gs-adjacent__dir">← Предыдущая подборка</span>
                            <span class="gs-adjacent__title"><?php echo esc_html(self::short_title($adjacent['prev']['title'])); ?></span>
                        </a>
                    <?php endif; ?>
                    <?php if (!empty($adjacent['next'])): ?>
                        <a class="gs-adjacent__link gs-adjacent__link--next" href="<?php echo esc_url(self::category_url($adjacent['next']['slug'])); ?>">
                            <span class="gs-adjacent__dir">Следующая подборка →</span>
                            <span class="gs-adjacent__title"><?php echo esc_html(self::short_title($adjacent['next']['title'])); ?></span>
                        </a>
                    <?php endif; ?>
                </nav>
            <?php endif; ?>

            <a class="gs-btn gs-btn--ghost" href="<?php echo esc_url(self::base_url()); ?>">Все категории звуков</a>
        </section>
        <?php
        return ob_get_clean();
    }

    private static function render_pagination($page, $pages, $extra_args = array()) {
        if ($pages <= 1) {
            return '';
        }
        $slug = self::requested_slug();
        $link = function ($p) use ($slug, $extra_args) {
            $args = array_filter($extra_args, function ($v) {
                return $v !== '' && $v !== null;
            });
            // Поиск по каталогу остаётся на параметрах: это не страница
            // списка, а выдача по запросу, и в индексе ей делать нечего.
            if (!empty($args)) {
                $args['gs_page'] = $p > 1 ? $p : null;
                return add_query_arg(array_filter($args, function ($v) {
                    return $v !== null;
                }), self::base_url());
            }
            return self::page_url($slug, $p);
        };

        $window = array();
        for ($p = max(1, $page - 2); $p <= min($pages, $page + 2); $p++) {
            $window[] = $p;
        }

        ob_start();
        ?>
        <nav class="gs-pager" aria-label="Постраничная навигация">
            <?php if ($page > 1): ?>
                <a class="gs-pager__btn" href="<?php echo esc_url($link($page - 1)); ?>" rel="prev">← Назад</a>
            <?php endif; ?>

            <?php if (!in_array(1, $window, true)): ?>
                <a class="gs-pager__num" href="<?php echo esc_url($link(1)); ?>">1</a>
                <span class="gs-pager__gap">…</span>
            <?php endif; ?>

            <?php foreach ($window as $p): ?>
                <?php if ($p === $page): ?>
                    <span class="gs-pager__num is-current" aria-current="page"><?php echo (int) $p; ?></span>
                <?php else: ?>
                    <a class="gs-pager__num" href="<?php echo esc_url($link($p)); ?>"><?php echo (int) $p; ?></a>
                <?php endif; ?>
            <?php endforeach; ?>

            <?php if (!in_array($pages, $window, true)): ?>
                <span class="gs-pager__gap">…</span>
                <a class="gs-pager__num" href="<?php echo esc_url($link($pages)); ?>"><?php echo (int) $pages; ?></a>
            <?php endif; ?>

            <?php if ($page < $pages): ?>
                <a class="gs-pager__btn" href="<?php echo esc_url($link($page + 1)); ?>" rel="next">Вперёд →</a>
            <?php endif; ?>
        </nav>
        <?php
        return ob_get_clean();
    }

    private static function current_page_url() {
        $slug = self::requested_slug();
        return $slug !== '' ? self::category_url($slug) : self::base_url();
    }

    /* ---------------------------------------------------------------------
     * Мелкие помощники текста
     * ------------------------------------------------------------------ */

    /**
     * SEO-заголовки в каталоге длинные («Крики солдат — «вперёд», «ура» и звуки армии»).
     * Для карточек и крошек берём часть до тире.
     */
    /**
     * Описания категорий начинаются с числа записей у источника («31 звуков солдат: …»),
     * а показываем мы свою подборку. Подставляем реальное число и заодно
     * чиним согласование, которое в исходных текстах сломано.
     */
    public static function sync_description_count($text, $actual) {
        $text = trim((string) $text);
        $actual = (int) $actual;
        if ($text === '' || $actual <= 0) {
            return $text;
        }
        // Число в описании пишется при генерации и устаревает: в каталоге
        // «звук печатной машинки» заголовок обещал 15 файлов, а описание —
        // 42 записи, потому что старый шаблон искал число только в самом
        // начале строки и только рядом со словом «звук». Ищем первую пару
        // «число + звуки/записи» где угодно в первом предложении.
        $fixed = preg_replace_callback(
            '~(\d+)\s+(звук\w*|запис\w*)~u',
            function () use ($actual) {
                return self::plural_sounds($actual);
            },
            $text,
            1,
            $hits
        );
        return $hits > 0 ? (string) $fixed : $text;
    }

    public static function short_title($title) {
        $title = trim((string) $title);
        $parts = preg_split('~\s+[—–-]\s+~u', $title, 2);
        $short = is_array($parts) && !empty($parts[0]) ? trim($parts[0]) : $title;
        if (mb_strlen($short) < 3) {
            $short = $title;
        }
        return $short;
    }

    private static function headline_to_title($headline) {
        $headline = trim((string) $headline);
        $sentence = preg_split('~(?<=[.!?])\s~u', $headline, 2);
        $first = is_array($sentence) && !empty($sentence[0]) ? trim($sentence[0]) : $headline;
        return mb_strlen($first) > 120 ? mb_substr($first, 0, 117) . '…' : $first;
    }

    public static function plural_sounds($n) {
        return $n . ' ' . self::plural($n, 'звук', 'звука', 'звуков');
    }

    private static function plural_categories($n) {
        return $n . ' ' . self::plural($n, 'категория', 'категории', 'категорий');
    }

    /** То же для текста описания: «в 42 подборках». */
    public static function plural_categories_text($n) {
        return $n . ' ' . self::plural($n, 'подборке', 'подборках', 'подборках');
    }

    private static function plural($n, $one, $few, $many) {
        $n = abs((int) $n) % 100;
        $n1 = $n % 10;
        if ($n > 10 && $n < 20) {
            return $many;
        }
        if ($n1 > 1 && $n1 < 5) {
            return $few;
        }
        if ($n1 === 1) {
            return $one;
        }
        return $many;
    }
}
