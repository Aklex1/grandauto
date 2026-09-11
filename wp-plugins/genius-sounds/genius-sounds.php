<?php
/**
 * Plugin Name: Genius Sounds — каталог звуков и генератор SFX
 * Plugin URI: https://genius-bot.ru/sounds-catalog/
 * Description: Современный адаптивный каталог звуков (подменяет вывод [kie_tts_sounds_catalog]), серверный импортёр звуков и студия генерации звуков и спецэффектов на Suno через KIE.
 * Version: 1.15.1
 * Author: Genius-bot
 * Text Domain: genius-sounds
 */

if (!defined('ABSPATH')) {
    exit;
}

define('GS_VERSION', '1.15.1');
define('GS_PLUGIN_FILE', __FILE__);
define('GS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('GS_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once GS_PLUGIN_DIR . 'includes/class-gs-storage.php';
require_once GS_PLUGIN_DIR . 'includes/class-gs-catalog.php';
require_once GS_PLUGIN_DIR . 'includes/class-gs-importer.php';
require_once GS_PLUGIN_DIR . 'includes/class-gs-sfx.php';
require_once GS_PLUGIN_DIR . 'includes/class-gs-pages.php';
require_once GS_PLUGIN_DIR . 'includes/class-gs-seo.php';
require_once GS_PLUGIN_DIR . 'includes/class-gs-sitemap.php';
require_once GS_PLUGIN_DIR . 'includes/class-gs-links.php';
require_once GS_PLUGIN_DIR . 'includes/class-gs-musicai.php';
require_once GS_PLUGIN_DIR . 'includes/class-gs-lab.php';
require_once GS_PLUGIN_DIR . 'includes/class-gs-lab-page.php';
require_once GS_PLUGIN_DIR . 'includes/class-gs-blog.php';
require_once GS_PLUGIN_DIR . 'includes/class-gs-tts-fallback.php';
require_once GS_PLUGIN_DIR . 'includes/class-gs-api-keys.php';
require_once GS_PLUGIN_DIR . 'includes/class-gs-api.php';
require_once GS_PLUGIN_DIR . 'includes/class-gs-api-page.php';
require_once GS_PLUGIN_DIR . 'includes/class-gs-rest.php';
require_once GS_PLUGIN_DIR . 'includes/class-gs-admin.php';

class Genius_Sounds_Plugin {

    /** @var Genius_Sounds_Plugin|null */
    private static $instance = null;

    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        register_activation_hook(GS_PLUGIN_FILE, array($this, 'activate'));
        register_deactivation_hook(GS_PLUGIN_FILE, array($this, 'deactivate'));

        // Позже базового плагина (он вешает шорткоды на init с приоритетом по умолчанию),
        // чтобы успеть перехватить [kie_tts_sounds_catalog].
        add_action('init', array($this, 'init'), 20);
        add_action('wp_enqueue_scripts', array($this, 'enqueue_front_assets'));

        GS_Importer::boot();
        GS_SFX::boot();
        GS_Rest::boot();
        GS_Admin::boot();
        GS_Pages::boot();
        GS_Seo::boot();
        GS_Sitemap::boot();
        GS_Links::boot();
        GS_Lab::boot();
        GS_Blog::boot();
        GS_Tts_Fallback::boot();
        GS_Api::boot();
    }

    public function activate() {
        GS_Storage::ensure_dirs();
        GS_Catalog::ensure_seeded();
        GS_Pages::ensure_pages();
        GS_Lab::ensure_pages();
        GS_Api_Page::ensure_page();
        flush_rewrite_rules();
    }

    public function deactivate() {
        GS_Importer::clear_schedule();
        flush_rewrite_rules();
    }

    public function init() {
        GS_Catalog::takeover_shortcode();
        GS_Pages::register_shortcodes();
        GS_Lab_Page::register_shortcodes();
        GS_Api_Page::register_shortcodes();

        // Разовая инициализация после обновления версии плагина.
        //
        // Отметку о версии ставим ДО работы: если что-то в ней сорвётся,
        // сайт не должен повторять падение на каждом следующем запросе.
        if (get_option('gs_bootstrap_version') !== GS_VERSION) {
            update_option('gs_bootstrap_version', GS_VERSION);
            try {
                GS_Storage::ensure_dirs();
                GS_Catalog::ensure_seeded();
                GS_Pages::ensure_pages();
                GS_Lab::ensure_pages();
                GS_Api_Page::ensure_page();
                add_action('shutdown', 'flush_rewrite_rules');
            } catch (Throwable $e) {
                error_log('genius-sounds: инициализация не удалась — ' . $e->getMessage());
            }
        }
    }

    /**
     * Ассеты грузим только на своих страницах, чтобы не утяжелять остальной сайт.
     */
    public function enqueue_front_assets() {
        $blog = GS_Blog::enabled() && (GS_Blog::is_single_post() || GS_Blog::is_blog_list());
        $neurohub = GS_Links::is_neurohub();
        if ($neurohub) {
            wp_enqueue_style('genius-sounds-catalog', GS_PLUGIN_URL . 'assets/css/catalog.css', array(), GS_VERSION);
            wp_enqueue_style('genius-sounds-studio', GS_PLUGIN_URL . 'assets/css/studio.css', array('genius-sounds-catalog'), GS_VERSION);
        }
        $api = GS_Api_Page::is_page();
        $ours = GS_Catalog::is_catalog_request() || GS_Pages::is_showcase_request()
            || GS_Pages::is_studio_request() || GS_Lab::current_service() || $blog || $api;
        if ($ours) {
            // Перекрашиваем шапку и подвал темы под тёмные страницы плагина.
            wp_enqueue_style('genius-sounds-chrome', GS_PLUGIN_URL . 'assets/css/chrome.css', array(), GS_VERSION);
        }

        if (GS_Catalog::is_catalog_request() || GS_Pages::is_showcase_request()) {
            wp_enqueue_style('genius-sounds-catalog', GS_PLUGIN_URL . 'assets/css/catalog.css', array(), GS_VERSION);
            wp_enqueue_script('genius-sounds-catalog', GS_PLUGIN_URL . 'assets/js/catalog.js', array(), GS_VERSION, true);
            wp_localize_script('genius-sounds-catalog', 'GS_CATALOG', array(
                'studioUrl' => GS_Pages::get_studio_url(),
            ));
        }

        if ($blog) {
            wp_enqueue_style('genius-sounds-catalog', GS_PLUGIN_URL . 'assets/css/catalog.css', array(), GS_VERSION);
            wp_enqueue_style('genius-sounds-blog', GS_PLUGIN_URL . 'assets/css/blog.css', array('genius-sounds-catalog'), GS_VERSION);
            wp_enqueue_script('genius-sounds-catalog', GS_PLUGIN_URL . 'assets/js/catalog.js', array(), GS_VERSION, true);
        }

        if ($api) {
            wp_enqueue_style('genius-sounds-catalog', GS_PLUGIN_URL . 'assets/css/catalog.css', array(), GS_VERSION);
            wp_enqueue_style('genius-sounds-studio', GS_PLUGIN_URL . 'assets/css/studio.css', array('genius-sounds-catalog'), GS_VERSION);
            wp_enqueue_style('genius-sounds-api', GS_PLUGIN_URL . 'assets/css/api.css', array('genius-sounds-studio'), GS_VERSION);
            wp_enqueue_script('genius-sounds-catalog', GS_PLUGIN_URL . 'assets/js/catalog.js', array(), GS_VERSION, true);
            wp_enqueue_script('genius-sounds-apidocs', GS_PLUGIN_URL . 'assets/js/apidocs.js', array(), GS_VERSION, true);
            wp_localize_script('genius-sounds-apidocs', 'GS_API', array(
                'restUrl' => esc_url_raw(rest_url(GS_Rest::NS . '/')),
                'nonce'   => wp_create_nonce('wp_rest'),
            ));
        }

        $lab = GS_Lab::current_service();
        if ($lab) {
            wp_enqueue_style('genius-sounds-catalog', GS_PLUGIN_URL . 'assets/css/catalog.css', array(), GS_VERSION);
            wp_enqueue_style('genius-sounds-studio', GS_PLUGIN_URL . 'assets/css/studio.css', array('genius-sounds-catalog'), GS_VERSION);
            wp_enqueue_script('genius-sounds-catalog', GS_PLUGIN_URL . 'assets/js/catalog.js', array(), GS_VERSION, true);
            wp_enqueue_script('genius-sounds-lab', GS_PLUGIN_URL . 'assets/js/lab.js', array(), GS_VERSION, true);
            wp_localize_script('genius-sounds-lab', 'GS_LAB', array(
                'restUrl'     => esc_url_raw(rest_url(GS_Rest::NS . '/')),
                'nonce'       => wp_create_nonce('wp_rest'),
                'loggedIn'    => is_user_logged_in(),
                'loginUrl'    => GS_Pages::get_login_url(GS_Lab::get_url($lab['id'])),
                'service'     => $lab['id'],
                'inputs'      => array_values($lab['inputs']),
                'pollSeconds' => (int) $lab['poll_seconds'],
            ));
        }

        if (GS_Pages::is_studio_request()) {
            // catalog.css несёт базовые токены и общие компоненты (кнопки, чипы, хиро).
            wp_enqueue_style('genius-sounds-catalog', GS_PLUGIN_URL . 'assets/css/catalog.css', array(), GS_VERSION);
            wp_enqueue_style('genius-sounds-studio', GS_PLUGIN_URL . 'assets/css/studio.css', array('genius-sounds-catalog'), GS_VERSION);
            // catalog.js правит отступ под фиксированной шапкой на всех наших страницах.
            wp_enqueue_script('genius-sounds-catalog', GS_PLUGIN_URL . 'assets/js/catalog.js', array(), GS_VERSION, true);
            wp_enqueue_script('genius-sounds-studio', GS_PLUGIN_URL . 'assets/js/studio.js', array(), GS_VERSION, true);
            wp_localize_script('genius-sounds-studio', 'GS_STUDIO', array(
                'restUrl'   => esc_url_raw(rest_url(GS_Rest::NS . '/')),
                'nonce'     => wp_create_nonce('wp_rest'),
                'loggedIn'  => is_user_logged_in(),
                'loginUrl'  => GS_Pages::get_login_url(GS_Pages::current_studio_url()),
                'topupUrl'  => GS_Pages::get_dashboard_url(),
                'cost'      => GS_SFX::get_cost(),
                'presets'   => GS_SFX::get_presets(),
            ));
        }
    }
}

Genius_Sounds_Plugin::get_instance();
