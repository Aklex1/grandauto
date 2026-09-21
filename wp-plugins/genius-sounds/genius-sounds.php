<?php
/**
 * Plugin Name: Genius Sounds — каталог звуков и генератор SFX
 * Plugin URI: https://genius-bot.ru/sounds-catalog/
 * Description: Современный адаптивный каталог звуков (подменяет вывод [kie_tts_sounds_catalog]), серверный импортёр звуков и студия генерации звуков и спецэффектов на Suno через KIE.
 * Version: 1.63.2
 * Author: Genius-bot
 * Text Domain: genius-sounds
 */

if (!defined('ABSPATH')) {
    exit;
}

define('GS_VERSION', '1.91.0');
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
require_once GS_PLUGIN_DIR . 'includes/class-gs-index.php';
require_once GS_PLUGIN_DIR . 'includes/class-gs-webmaster.php';
require_once GS_PLUGIN_DIR . 'includes/class-gs-landing.php';
require_once GS_PLUGIN_DIR . 'includes/class-gs-voice.php';
require_once GS_PLUGIN_DIR . 'includes/class-gs-voice-page.php';
require_once GS_PLUGIN_DIR . 'includes/class-gs-songs.php';
require_once GS_PLUGIN_DIR . 'includes/class-gs-course.php';
require_once GS_PLUGIN_DIR . 'includes/class-gs-404.php';
require_once GS_PLUGIN_DIR . 'includes/class-gs-provider.php';
require_once GS_PLUGIN_DIR . 'includes/class-gs-doctext.php';
require_once GS_PLUGIN_DIR . 'includes/class-gs-pptx.php';
require_once GS_PLUGIN_DIR . 'includes/class-gs-slides.php';
require_once GS_PLUGIN_DIR . 'includes/class-gs-slides-page.php';
require_once GS_PLUGIN_DIR . 'includes/class-gs-leads.php';
require_once GS_PLUGIN_DIR . 'includes/class-gs-schedule.php';
require_once GS_PLUGIN_DIR . 'includes/class-gs-promt.php';
require_once GS_PLUGIN_DIR . 'includes/class-gs-links.php';
require_once GS_PLUGIN_DIR . 'includes/class-gs-musicai.php';
require_once GS_PLUGIN_DIR . 'includes/class-gs-payments.php';
require_once GS_PLUGIN_DIR . 'includes/class-gs-auth.php';
require_once GS_PLUGIN_DIR . 'includes/class-gs-yoomoney.php';
require_once GS_PLUGIN_DIR . 'includes/class-gs-dashboard.php';
require_once GS_PLUGIN_DIR . 'includes/class-gs-keywords.php';
require_once GS_PLUGIN_DIR . 'includes/class-gs-neurohub.php';
require_once GS_PLUGIN_DIR . 'includes/class-gs-manual.php';
require_once GS_PLUGIN_DIR . 'includes/class-gs-lab.php';
require_once GS_PLUGIN_DIR . 'includes/class-gs-lab-page.php';
require_once GS_PLUGIN_DIR . 'includes/class-gs-blog.php';
require_once GS_PLUGIN_DIR . 'includes/class-gs-tts-fallback.php';
require_once GS_PLUGIN_DIR . 'includes/class-gs-gemini.php';
require_once GS_PLUGIN_DIR . 'includes/class-gs-transcribe.php';
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
        GS_Index::boot();
        GS_Webmaster::boot();
        GS_Landing::boot();
        GS_Songs::boot();
        GS_Course::boot();
        GS_404::boot();
        GS_Slides_Page::boot();
        GS_Leads::boot();
        GS_Schedule::boot();
        GS_Promt::boot();
        GS_Links::boot();
        GS_Lab::boot();
        GS_Manual::boot();
        GS_Neurohub::boot();
        GS_Dashboard::boot();
        GS_Payments::boot();
        GS_Auth::boot();
        GS_Yoomoney::boot();
        GS_Blog::boot();
        GS_Tts_Fallback::boot();
        GS_Transcribe::boot();
        GS_Api::boot();
    }

    public function activate() {
        GS_Storage::ensure_dirs();
        GS_Catalog::ensure_seeded();
        GS_Pages::ensure_pages();
        GS_Lab::ensure_pages();
        GS_Landing::ensure_pages();
        GS_Songs::ensure_page();
        GS_Course::ensure_page();
        GS_Slides_Page::ensure_pages();
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
        GS_Landing::register_shortcodes();
        GS_Voice_Page::register_shortcodes();
        GS_Songs::register_shortcodes();
        GS_Course::register_shortcodes();
        GS_Slides_Page::register_shortcodes();
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
                GS_Landing::ensure_pages();
                GS_Songs::ensure_page();
                GS_Course::ensure_page();
                GS_Slides_Page::ensure_pages();
                GS_Api_Page::ensure_page();
                add_action('shutdown', 'flush_rewrite_rules');
            } catch (Throwable $e) {
                error_log('genius-sounds: инициализация не удалась — ' . $e->getMessage());
            }
        }
    }

    /** Штатная страница входа платёжного плагина. */
    private static function is_login_page() {
        $page = (int) get_option('kie_tts_auth_page_id');
        return ($page > 0 && is_page($page)) || is_page('tts-login');
    }

    /**
     * Ассеты грузим только на своих страницах, чтобы не утяжелять остальной сайт.
     */
    public function enqueue_front_assets() {
        $blog = GS_Blog::enabled() && (GS_Blog::is_single_post() || GS_Blog::is_blog_list());
        $neurohub = GS_Links::is_neurohub();
        if ($neurohub) {
            // Промт из статьи подставляем в поле нейрохаба — см. скрипт.
            wp_enqueue_script('genius-sounds-nhprompt', GS_PLUGIN_URL . 'assets/js/neurohub-prompt.js', array(), GS_VERSION, true);
            // Кнопка загрузки своего снимка ничем не выделена — подсвечиваем её.
            wp_enqueue_script('genius-sounds-nhupload', GS_PLUGIN_URL . 'assets/js/neurohub-upload-hint.js', array(), GS_VERSION, true);
            wp_enqueue_style('genius-sounds-catalog', GS_PLUGIN_URL . 'assets/css/catalog.css', array(), GS_VERSION);
            wp_enqueue_style('genius-sounds-studio', GS_PLUGIN_URL . 'assets/css/studio.css', array('genius-sounds-catalog'), GS_VERSION);
            wp_enqueue_style('genius-sounds-api', GS_PLUGIN_URL . 'assets/css/api.css', array('genius-sounds-studio'), GS_VERSION);
            wp_enqueue_style('genius-sounds-neurohub', GS_PLUGIN_URL . 'assets/css/neurohub.css', array('genius-sounds-studio'), GS_VERSION);
        }
        $api = GS_Api_Page::is_page();
        $dashboard = GS_Dashboard::enabled() && GS_Dashboard::is_page();
        $ours = GS_Catalog::is_catalog_request() || GS_Pages::is_showcase_request()
            || GS_Pages::is_studio_request() || GS_Lab::current_service() || GS_Landing::current()
            || GS_Songs::is_page() || GS_Course::is_page() || GS_404::is_page() || GS_Slides_Page::is_any() || $blog || $api || $dashboard;
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

        if ($dashboard) {
            wp_enqueue_style('genius-sounds-catalog', GS_PLUGIN_URL . 'assets/css/catalog.css', array(), GS_VERSION);
            wp_enqueue_style('genius-sounds-dashboard', GS_PLUGIN_URL . 'assets/css/dashboard.css', array('genius-sounds-catalog'), GS_VERSION);
            wp_enqueue_script('genius-sounds-dashboard', GS_PLUGIN_URL . 'assets/js/dashboard.js', array(), GS_VERSION, true);
            wp_localize_script('genius-sounds-dashboard', 'GS_DASHBOARD', array(
                'apiUrl' => GS_Api_Page::get_url(),
                'sttUrl' => GS_Lab::get_url('stt'),
                'ytUrl'  => GS_Lab::get_url('ytaudio'),
            ));
        }

        // Посадочная под коммерческий запрос показывает тот же инструмент,
        // значит ей нужны те же стили и тот же скрипт.
        // Вход на месте: окно вместо ухода на отдельную страницу.
        if (GS_Auth::needed()) {
            wp_enqueue_style('genius-sounds-auth', GS_PLUGIN_URL . 'assets/css/auth.css', array(), GS_VERSION);
            wp_enqueue_script('genius-sounds-auth', GS_PLUGIN_URL . 'assets/js/auth.js', array(), GS_VERSION, true);
            wp_localize_script('genius-sounds-auth', 'GS_AUTH', array(
                'restUrl' => esc_url_raw(rest_url('tts/v1/')),
            ));
        }

        // Штатная страница входа: тема перекрашивает её поля при фокусе,
        // и текст пропадает — лечится теми же стилями.
        if (self::is_login_page()) {
            wp_enqueue_style('genius-sounds-auth', GS_PLUGIN_URL . 'assets/css/auth.css', array(), GS_VERSION);
        }

        // Пополнение на месте: одно окно на все страницы сервисов.
        if (GS_Payments::needs_modal()) {
            wp_enqueue_script('genius-sounds-topup', GS_PLUGIN_URL . 'assets/js/topup.js', array(), GS_VERSION, true);
            wp_localize_script('genius-sounds-topup', 'GS_TOPUP', array(
                'restUrl' => esc_url_raw(rest_url('tts/v1/')),
                'nonce'   => wp_create_nonce('wp_rest'),
            ));
        }

        if (GS_Slides_Page::is_any()) {
            wp_enqueue_style('genius-sounds-catalog', GS_PLUGIN_URL . 'assets/css/catalog.css', array(), GS_VERSION);
            wp_enqueue_style('genius-sounds-studio', GS_PLUGIN_URL . 'assets/css/studio.css', array('genius-sounds-catalog'), GS_VERSION);
            wp_enqueue_style('genius-sounds-course', GS_PLUGIN_URL . 'assets/css/course.css', array('genius-sounds-studio'), GS_VERSION);
            wp_enqueue_style('genius-sounds-slides', GS_PLUGIN_URL . 'assets/css/slides.css', array('genius-sounds-course'), GS_VERSION);
            if (GS_Slides_Page::is_page()) {
                wp_enqueue_script('genius-sounds-slides', GS_PLUGIN_URL . 'assets/js/slides.js', array(), GS_VERSION, true);
                wp_localize_script('genius-sounds-slides', 'GS_SLIDES', array(
                    'restUrl' => esc_url_raw(rest_url(GS_Rest::NS . '/')),
                    'nonce'   => wp_create_nonce('wp_rest'),
                ));
            }
        }

        if (GS_404::is_page()) {
            wp_enqueue_style('genius-sounds-catalog', GS_PLUGIN_URL . 'assets/css/catalog.css', array(), GS_VERSION);
            wp_enqueue_style('genius-sounds-studio', GS_PLUGIN_URL . 'assets/css/studio.css', array('genius-sounds-catalog'), GS_VERSION);
            wp_enqueue_style('genius-sounds-404', GS_PLUGIN_URL . 'assets/css/e404.css', array('genius-sounds-studio'), GS_VERSION);
        }

        if (GS_Course::is_page()) {
            wp_enqueue_style('genius-sounds-catalog', GS_PLUGIN_URL . 'assets/css/catalog.css', array(), GS_VERSION);
            wp_enqueue_style('genius-sounds-studio', GS_PLUGIN_URL . 'assets/css/studio.css', array('genius-sounds-catalog'), GS_VERSION);
            wp_enqueue_style('genius-sounds-course', GS_PLUGIN_URL . 'assets/css/course.css', array('genius-sounds-studio'), GS_VERSION);
            wp_enqueue_script('genius-sounds-course', GS_PLUGIN_URL . 'assets/js/course.js', array(), GS_VERSION, true);
            wp_localize_script('genius-sounds-course', 'GS_COURSE', array(
                'restUrl' => esc_url_raw(rest_url(GS_Rest::NS . '/')),
                'nonce'   => wp_create_nonce('wp_rest'),
                'source'  => 'обучение заработку на нейросетях',
            ));
        }

        $lab = GS_Lab::current_service();
        $landing = $lab ? null : GS_Landing::current();
        if ($landing) {
            $lab = GS_Landing::current_service();
        }
        if ($lab && $lab['id'] === 'voicesong') {
            // У песни своим голосом свой мастер из трёх шагов: общая форма,
            // рассчитанная на «загрузил — запустил», для него не годится.
            wp_enqueue_style('genius-sounds-catalog', GS_PLUGIN_URL . 'assets/css/catalog.css', array(), GS_VERSION);
            wp_enqueue_style('genius-sounds-studio', GS_PLUGIN_URL . 'assets/css/studio.css', array('genius-sounds-catalog'), GS_VERSION);
            wp_enqueue_script('genius-sounds-catalog', GS_PLUGIN_URL . 'assets/js/catalog.js', array(), GS_VERSION, true);
            wp_enqueue_script('genius-sounds-voice', GS_PLUGIN_URL . 'assets/js/voice.js', array(), GS_VERSION, true);
            wp_localize_script('genius-sounds-voice', 'GS_VOICE', array(
                'restUrl'  => esc_url_raw(rest_url(GS_Rest::NS . '/')),
                'nonce'    => wp_create_nonce('wp_rest'),
                'hasVoice' => is_user_logged_in() && count(GS_Voice::user_voices(get_current_user_id())) > 0,
                'displayName' => is_user_logged_in() ? wp_get_current_user()->display_name : '',
            ));
        } elseif ($lab) {
            // После входа человек должен вернуться на ту страницу, где начал,
            // а не на общую посадочную сервиса.
            $back = $landing ? GS_Landing::get_url($landing['id']) : GS_Lab::get_url($lab['id']);
            wp_enqueue_style('genius-sounds-catalog', GS_PLUGIN_URL . 'assets/css/catalog.css', array(), GS_VERSION);
            wp_enqueue_style('genius-sounds-studio', GS_PLUGIN_URL . 'assets/css/studio.css', array('genius-sounds-catalog'), GS_VERSION);
            wp_enqueue_script('genius-sounds-catalog', GS_PLUGIN_URL . 'assets/js/catalog.js', array(), GS_VERSION, true);
            wp_enqueue_script('genius-sounds-lab', GS_PLUGIN_URL . 'assets/js/lab.js', array(), GS_VERSION, true);
            if ($lab['id'] === 'ytaudio') {
                // Свой файл разбирается в браузере — серверу он не нужен.
                wp_enqueue_script('genius-sounds-ytlocal', GS_PLUGIN_URL . 'assets/js/ytaudio-local.js', array(), GS_VERSION, true);
            }
            wp_localize_script('genius-sounds-lab', 'GS_LAB', array(
                'restUrl'     => esc_url_raw(rest_url(GS_Rest::NS . '/')),
                'nonce'       => wp_create_nonce('wp_rest'),
                'loggedIn'    => is_user_logged_in(),
                'guestOk'     => GS_Lab::allows_guests($lab['id']),
                'loginUrl'    => GS_Pages::get_login_url($back),
                'registerUrl' => GS_Pages::get_login_url(GS_Lab::get_url('stt')),
                'service'     => $lab['id'],
                'inputs'      => array_values($lab['inputs']),
                'inputsOptional' => array_values((array) (isset($lab['input_optional']) ? $lab['input_optional'] : array())),
                'pollSeconds' => (int) $lab['poll_seconds'],
                'manual'      => GS_Lab::is_manual($lab['id']),
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
