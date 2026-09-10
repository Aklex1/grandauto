<?php
/**
 * Plugin Name: Genius Sounds — каталог звуков и генератор SFX
 * Plugin URI: https://genius-bot.ru/sounds-catalog/
 * Description: Современный адаптивный каталог звуков (подменяет вывод [kie_tts_sounds_catalog]), серверный импортёр звуков и студия генерации звуков и спецэффектов на Suno через KIE.
 * Version: 1.6.2
 * Author: Genius-bot
 * Text Domain: genius-sounds
 */

if (!defined('ABSPATH')) {
    exit;
}

define('GS_VERSION', '1.6.2');
define('GS_PLUGIN_FILE', __FILE__);
define('GS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('GS_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once GS_PLUGIN_DIR . 'includes/class-gs-storage.php';
require_once GS_PLUGIN_DIR . 'includes/class-gs-catalog.php';
require_once GS_PLUGIN_DIR . 'includes/class-gs-importer.php';
require_once GS_PLUGIN_DIR . 'includes/class-gs-sfx.php';
require_once GS_PLUGIN_DIR . 'includes/class-gs-pages.php';
require_once GS_PLUGIN_DIR . 'includes/class-gs-seo.php';
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
    }

    public function activate() {
        GS_Storage::ensure_dirs();
        GS_Catalog::ensure_seeded();
        GS_Pages::ensure_pages();
        flush_rewrite_rules();
    }

    public function deactivate() {
        GS_Importer::clear_schedule();
        flush_rewrite_rules();
    }

    public function init() {
        GS_Catalog::takeover_shortcode();
        GS_Pages::register_shortcodes();

        // Разовая инициализация после обновления версии плагина.
        if (get_option('gs_bootstrap_version') !== GS_VERSION) {
            GS_Storage::ensure_dirs();
            GS_Catalog::ensure_seeded();
            GS_Pages::ensure_pages();
            update_option('gs_bootstrap_version', GS_VERSION);
            add_action('shutdown', 'flush_rewrite_rules');
        }
    }

    /**
     * Ассеты грузим только на своих страницах, чтобы не утяжелять остальной сайт.
     */
    public function enqueue_front_assets() {
        $ours = GS_Catalog::is_catalog_request() || GS_Pages::is_showcase_request() || GS_Pages::is_studio_request();
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
