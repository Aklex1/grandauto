<?php
/**
 * Вес страницы: убираем то, что возят с каждой страницей зря.
 *
 * Измерение показало картину, которую по ощущениям не увидеть: страница
 * статьи весит 151 КБ, из них 77 КБ — стили прямо в разметке, а видимого
 * текста 19 КБ. То есть на каждый килобайт текста приходится четыре
 * килобайта стилей, и всё это едет заново на каждой странице, потому что
 * встроенные стили браузер не кэширует.
 *
 * Что здесь делается:
 *
 *   1. Пользовательский CSS из Настройщика (17 КБ) переносится в файл.
 *      Содержимое не меняется и остаётся в Настройщике — просто выводится
 *      ссылкой, а не текстом. Файл пересобирается при каждом сохранении.
 *
 *   2. Стили блочного редактора (9 КБ) снимаются: сайт собран темой и
 *      конструктором, блоков на нём нет.
 *
 *   3. Формы Contact Form 7 (три файла) грузятся только там, где форма
 *      действительно стоит. На статье их не было никогда.
 *
 *   4. Эмодзи-скрипт и его стили — то, чего не просили.
 *
 * Всё выключаемо: если что-то в оформлении поедет, это чинится снятием
 * галки, а не откатом плагина.
 */

if (!defined('ABSPATH')) {
    exit;
}

class GS_Weight {

    const OPT        = 'gs_weight';
    const FILE_NAME  = 'custom-css.css';
    const VER_OPTION = 'gs_weight_css_ver';

    public static function boot() {
        add_action('init', array(__CLASS__, 'maybe_build_css'));
        add_action('wp_enqueue_scripts', array(__CLASS__, 'swap_custom_css'), 5);
        add_action('wp_enqueue_scripts', array(__CLASS__, 'drop_block_styles'), 100);
        add_action('wp_enqueue_scripts', array(__CLASS__, 'drop_form_assets'), 100);
        add_action('init', array(__CLASS__, 'drop_emoji'));
        // Стили темы печатаются прямо в разметке. Правим готовую страницу
        // целиком — через фильтр кэша, которому она достаётся собранной.
        // Свой буфер на wp_head здесь не годится: его закрывает чужой код,
        // и подмена молча не происходит.
        add_filter('wpsupercache_buffer', array(__CLASS__, 'lighten_html'));
        // Кэш покрывает не все страницы: кабинет, раздел API и сервисы из
        // него исключены намеренно — там личные данные. А стили темы у них
        // те же самые сорок три килобайта, поэтому правим и живую выдачу.
        add_action('template_redirect', array(__CLASS__, 'page_buffer'), 0);
        // Настройщик сохранил новый CSS — пересобираем файл, иначе правка
        // не доедет до сайта и человек решит, что Настройщик сломался.
        add_action('customize_save_after', array(__CLASS__, 'build_css'));
        add_action('save_post_custom_css', array(__CLASS__, 'build_css'));
    }

    /**
     * @return array{custom_css:int,block_styles:int,form_assets:int,emoji:int}
     */
    public static function settings() {
        $saved = get_option(self::OPT, array());
        return wp_parse_args(is_array($saved) ? $saved : array(), array(
            'custom_css'   => 1,
            'block_styles' => 1,
            'form_assets'  => 1,
            'emoji'        => 1,
            'theme_css'    => 1,
        ));
    }

    public static function on($key) {
        $set = self::settings();
        return !empty($set[$key]);
    }

    /* ---------------------------------------------------------------------
     * Пользовательский CSS — в файл
     * ------------------------------------------------------------------ */

    private static function css_dir() {
        return GS_Storage::base_dir() . '/css';
    }

    private static function css_path() {
        return self::css_dir() . '/' . self::FILE_NAME;
    }

    private static function css_url() {
        return GS_Storage::base_url() . '/css/' . self::FILE_NAME;
    }

    public static function custom_css_text() {
        $css = wp_get_custom_css();
        return is_string($css) ? trim($css) : '';
    }

    /**
     * Собрать файл, если его нет или содержимое разошлось.
     */
    public static function maybe_build_css() {
        if (!self::on('custom_css')) {
            return;
        }
        $css = self::custom_css_text();
        if ($css === '') {
            return;
        }
        $hash = md5($css);
        if (get_option(self::VER_OPTION) === $hash && file_exists(self::css_path())) {
            return;
        }
        self::build_css();
    }

    public static function build_css() {
        $css = self::custom_css_text();
        if ($css === '') {
            return false;
        }
        if (!is_dir(self::css_dir())) {
            wp_mkdir_p(self::css_dir());
        }
        $header = "/* Собрано из Настройщика. Править там же: файл перезаписывается. */\n";
        $ok = GS_Storage::atomic_put(self::css_path(), $header . $css . "\n");
        if ($ok) {
            update_option(self::VER_OPTION, md5($css), false);
        }
        return $ok;
    }

    /**
     * Вместо 17 КБ текста в разметке — ссылка на файл.
     *
     * WordPress печатает пользовательский CSS обработчиком на wp_head.
     * Снимаем именно его, а не сам CSS: содержимое остаётся в Настройщике,
     * и человек продолжает править его привычным местом.
     */
    public static function swap_custom_css() {
        if (is_admin() || !self::on('custom_css')) {
            return;
        }
        $css = self::custom_css_text();
        if ($css === '' || !file_exists(self::css_path())) {
            return;
        }
        remove_action('wp_head', 'wp_custom_css_cb', 101);
        wp_enqueue_style('gs-custom-css', self::css_url(), array(), substr(md5($css), 0, 8));
    }

    /* ---------------------------------------------------------------------
     * Лишние стили и скрипты
     * ------------------------------------------------------------------ */

    /**
     * Стили блочного редактора.
     *
     * Их печатает ядро на случай, если страница собрана блоками. Здесь
     * страницы собраны темой и конструктором, блоков нет — девять
     * килобайт переменных и правил уезжают в пустоту.
     */
    public static function drop_block_styles() {
        if (!self::on('block_styles')) {
            return;
        }
        // Снятия из очереди мало: ядро печатает эти стили своим
        // обработчиком, а не через очередь, — снимаем и его.
        remove_action('wp_enqueue_scripts', 'wp_enqueue_global_styles');
        remove_action('wp_footer', 'wp_enqueue_global_styles', 1);
        remove_action('wp_enqueue_scripts', 'wp_common_block_scripts_and_styles');
        wp_dequeue_style('global-styles');
        wp_dequeue_style('classic-theme-styles');
        wp_dequeue_style('wp-block-library');
        wp_dequeue_style('wp-block-library-theme');
    }

    /**
     * Форма обратной связи стоит на одной странице, а её файлы ехали на всех.
     */
    public static function drop_form_assets() {
        if (!self::on('form_assets') || self::has_form()) {
            return;
        }
        foreach (array('contact-form-7', 'contact-form-7-rtl') as $handle) {
            wp_dequeue_style($handle);
            wp_deregister_style($handle);
        }
        foreach (array('contact-form-7', 'swv') as $handle) {
            wp_dequeue_script($handle);
            wp_deregister_script($handle);
        }
        // Счётчик Яндекса возит отдельный файл ради событий этой формы.
        wp_dequeue_script('wp-yandex-metrika-contact-form-seven');
    }

    /**
     * Есть ли на странице форма.
     *
     * Смотрим и шорткод, и блок конструктора: форму вставляют обоими
     * способами, и промах здесь — это молча сломанная форма.
     */
    private static function has_form() {
        if (is_admin()) {
            return true;
        }
        $post = get_post();
        if (!$post instanceof WP_Post) {
            return false;
        }
        $content = (string) $post->post_content;
        if (has_shortcode($content, 'contact-form-7') || has_shortcode($content, 'contact-form')) {
            return true;
        }
        return strpos($content, 'us_cform') !== false || strpos($content, 'wpcf7') !== false;
    }

    /* ---------------------------------------------------------------------
     * Стили темы — в файлы
     * ------------------------------------------------------------------ */

    /** Какие блоки выносим: только те, что одинаковы на всех страницах. */
    private static function movable() {
        return array('us-theme-options-css', 'us-current-header-css');
    }

    /**
     * Подменяем большие блоки стилей ссылками на файлы.
     *
     * Имя файла — от содержимого: поменяли настройки темы, и файл станет
     * другим сам, без кнопки «очистить». Оставляем на месте всё мелкое и
     * всё, что зависит от конкретной страницы: там файл только добавит
     * лишний запрос.
     */
    /**
     * Буфер на всю страницу для тех запросов, которые не попадут в кэш.
     *
     * Обработчик отдаём самому PHP: он вызовет его при сбросе буфера в
     * конце запроса, и не придётся угадывать, на каком хуке страница уже
     * собрана, а на каком ещё нет.
     */
    public static function page_buffer() {
        if (!self::on('theme_css') || is_admin() || is_feed() || is_robots()) {
            return;
        }
        if ((defined('REST_REQUEST') && REST_REQUEST) || (defined('DOING_AJAX') && DOING_AJAX)) {
            return;
        }
        if (!empty($_POST) || (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] !== 'GET')) {
            return;
        }
        ob_start(array(__CLASS__, 'lighten_html'));
    }

    public static function lighten_html($html) {
        if (!self::on('theme_css') || !is_string($html) || $html === '') {
            return $html;
        }

        foreach (self::movable() as $id) {
            $html = preg_replace_callback(
                '~<style[^>]*\bid=[\'"]' . preg_quote($id, '~') . '[\'"][^>]*>(.*?)</style>~is',
                function ($m) use ($id) {
                    $css = trim($m[1]);
                    // Мелочь оставляем на месте: отдельный запрос дороже.
                    if (mb_strlen($css) < 4096) {
                        return $m[0];
                    }
                    $url = self::cache_css($id, $css);
                    if ($url === '') {
                        return $m[0];
                    }
                    return '<link rel="stylesheet" id="' . esc_attr($id) . '" href="' . esc_url($url) . '" media="all">';
                },
                $html,
                1
            );
        }

        return $html;
    }

    /**
     * @return string адрес файла или пустая строка, если записать не вышло
     */
    private static function cache_css($id, $css) {
        $name = sanitize_key($id) . '-' . substr(md5($css), 0, 10) . '.css';
        $path = self::css_dir() . '/' . $name;
        if (!file_exists($path)) {
            if (!is_dir(self::css_dir())) {
                wp_mkdir_p(self::css_dir());
            }
            if (!GS_Storage::atomic_put($path, $css)) {
                return '';
            }
            self::sweep($id);
        }
        return GS_Storage::base_url() . '/css/' . $name;
    }

    /**
     * Старые копии того же блока удаляем: иначе в папке за год скопится
     * сотня файлов от каждой правки настроек.
     */
    private static function sweep($id) {
        $keep = sanitize_key($id);
        foreach ((array) glob(self::css_dir() . '/' . $keep . '-*.css') as $file) {
            if (filemtime($file) < time() - DAY_IN_SECONDS) {
                @unlink($file);
            }
        }
    }

    /**
     * Эмодзи: скрипт на 15 КБ и стиль ради подмены символов, которые
     * современные системы рисуют сами.
     */
    public static function drop_emoji() {
        if (!self::on('emoji')) {
            return;
        }
        remove_action('wp_head', 'print_emoji_detection_script', 7);
        remove_action('wp_print_styles', 'print_emoji_styles');
        remove_action('admin_print_scripts', 'print_emoji_detection_script');
        remove_action('admin_print_styles', 'print_emoji_styles');
        remove_filter('the_content_feed', 'wp_staticize_emoji');
        remove_filter('comment_text_rss', 'wp_staticize_emoji');
        remove_filter('wp_mail', 'wp_staticize_emoji_for_email');
        add_filter('tiny_mce_plugins', function ($plugins) {
            return is_array($plugins) ? array_diff($plugins, array('wpemoji')) : $plugins;
        });
    }
}
