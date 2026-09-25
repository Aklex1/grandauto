<?php
/**
 * REST-слой: студия генерации звуков + управление импортом каталога.
 */

if (!defined('ABSPATH')) {
    exit;
}

class GS_Rest {

    const NS = 'genius-sounds/v1';

    public static function boot() {
        add_action('rest_api_init', array(__CLASS__, 'register_routes'));
    }

    public static function register_routes() {
        register_rest_route(self::NS, '/sfx/generate', array(
            'methods'             => 'POST',
            'callback'            => array(__CLASS__, 'handle_generate'),
            'permission_callback' => array(__CLASS__, 'perm_logged_in'),
        ));

        register_rest_route(self::NS, '/sfx/status/(?P<task_id>[a-zA-Z0-9_-]+)', array(
            'methods'             => 'GET',
            'callback'            => array(__CLASS__, 'handle_status'),
            'permission_callback' => array(__CLASS__, 'perm_logged_in'),
        ));

        register_rest_route(self::NS, '/sfx/history', array(
            'methods'             => 'GET',
            'callback'            => array(__CLASS__, 'handle_history'),
            'permission_callback' => array(__CLASS__, 'perm_logged_in'),
        ));

        register_rest_route(self::NS, '/sfx/balance', array(
            'methods'             => 'GET',
            'callback'            => array(__CLASS__, 'handle_balance'),
            'permission_callback' => array(__CLASS__, 'perm_logged_in'),
        ));

        register_rest_route(self::NS, '/sfx/callback', array(
            'methods'             => 'POST',
            'callback'            => array(__CLASS__, 'handle_callback'),
            'permission_callback' => '__return_true',
        ));

        // --- ключи публичного API: выдача и отзыв из личного кабинета ---

        register_rest_route(self::NS, '/api-keys', array(
            array(
                'methods'             => 'GET',
                'callback'            => array(__CLASS__, 'handle_api_keys_list'),
                'permission_callback' => array(__CLASS__, 'perm_logged_in'),
            ),
            array(
                'methods'             => 'POST',
                'callback'            => array(__CLASS__, 'handle_api_key_issue'),
                'permission_callback' => array(__CLASS__, 'perm_logged_in'),
            ),
            array(
                'methods'             => 'DELETE',
                'callback'            => array(__CLASS__, 'handle_api_key_revoke'),
                'permission_callback' => array(__CLASS__, 'perm_logged_in'),
            ),
        ));

        // --- админские маршруты ---

        register_rest_route(self::NS, '/import/start', array(
            'methods'             => 'POST',
            'callback'            => array(__CLASS__, 'handle_import_start'),
            'permission_callback' => array(__CLASS__, 'perm_admin'),
        ));

        register_rest_route(self::NS, '/import/tick', array(
            'methods'             => 'POST',
            'callback'            => array(__CLASS__, 'handle_import_tick'),
            'permission_callback' => array(__CLASS__, 'perm_admin'),
        ));

        register_rest_route(self::NS, '/import/stop', array(
            'methods'             => 'POST',
            'callback'            => array(__CLASS__, 'handle_import_stop'),
            'permission_callback' => array(__CLASS__, 'perm_admin'),
        ));

        register_rest_route(self::NS, '/import/status', array(
            'methods'             => 'GET',
            'callback'            => array(__CLASS__, 'handle_import_status'),
            'permission_callback' => array(__CLASS__, 'perm_admin'),
        ));

        register_rest_route(self::NS, '/lab/upload', array(
            'methods'             => 'POST',
            'callback'            => array(__CLASS__, 'handle_lab_upload'),
            'permission_callback' => array(__CLASS__, 'perm_logged_in'),
        ));

        // Бесплатные операции работают и без входа — решает обработчик,
        // потому что право зависит от сервиса, а не от маршрута.
        register_rest_route(self::NS, '/lab/generate', array(
            'methods'             => 'POST',
            'callback'            => array(__CLASS__, 'handle_lab_generate'),
            'permission_callback' => '__return_true',
        ));

        register_rest_route(self::NS, '/lab/status/(?P<service>[a-z]+)/(?P<task_id>[a-zA-Z0-9_-]+)', array(
            'methods'             => 'GET',
            'callback'            => array(__CLASS__, 'handle_lab_status'),
            'permission_callback' => '__return_true',
        ));

        // Песня своим голосом: голос создаётся в три приёма и живёт в кабинете.
        register_rest_route(self::NS, '/voice/phrase', array(
            'methods'             => 'POST',
            'callback'            => array(__CLASS__, 'handle_voice_phrase'),
            'permission_callback' => array(__CLASS__, 'perm_logged_in'),
        ));
        register_rest_route(self::NS, '/voice/phrase/(?P<task_id>[a-zA-Z0-9_-]+)', array(
            'methods'             => 'GET',
            'callback'            => array(__CLASS__, 'handle_voice_phrase_state'),
            'permission_callback' => array(__CLASS__, 'perm_logged_in'),
        ));
        register_rest_route(self::NS, '/voice/verify', array(
            'methods'             => 'POST',
            'callback'            => array(__CLASS__, 'handle_voice_verify'),
            'permission_callback' => array(__CLASS__, 'perm_logged_in'),
        ));
        register_rest_route(self::NS, '/voice/verify/(?P<task_id>[a-zA-Z0-9_-]+)', array(
            'methods'             => 'GET',
            'callback'            => array(__CLASS__, 'handle_voice_verify_state'),
            'permission_callback' => array(__CLASS__, 'perm_logged_in'),
        ));
        register_rest_route(self::NS, '/voice/list', array(
            'methods'             => 'GET',
            'callback'            => array(__CLASS__, 'handle_voice_list'),
            'permission_callback' => array(__CLASS__, 'perm_logged_in'),
        ));
        // Наполнение галереи руками: положить показательную песню или убрать
        // чужую. Только для администратора.
        register_rest_route(self::NS, '/songs/seed', array(
            'methods'             => 'POST',
            'callback'            => array(__CLASS__, 'handle_songs_seed'),
            'permission_callback' => array(__CLASS__, 'perm_admin'),
        ));
        register_rest_route(self::NS, '/songs/remove', array(
            'methods'             => 'POST',
            'callback'            => array(__CLASS__, 'handle_songs_remove'),
            'permission_callback' => array(__CLASS__, 'perm_admin'),
        ));

        // Каталог: чтение указателя и правка текстов подборок.
        // Только администратору: это содержимое сайта, а не публичные данные.
        register_rest_route(self::NS, '/catalog/index', array(
            'methods'             => 'GET',
            'callback'            => array(__CLASS__, 'handle_catalog_index'),
            'permission_callback' => array(__CLASS__, 'perm_admin'),
        ));
        register_rest_route(self::NS, '/catalog/update', array(
            'methods'             => 'POST',
            'callback'            => array(__CLASS__, 'handle_catalog_update'),
            'permission_callback' => array(__CLASS__, 'perm_admin'),
        ));
        // Черновик статьи по заданию: справка о сервисе собирается на
        // сервере, задание приходит снаружи.
        register_rest_route(self::NS, '/content/longread', array(
            'methods'             => 'POST',
            'callback'            => array(__CLASS__, 'handle_longread'),
            'permission_callback' => array(__CLASS__, 'perm_admin'),
        ));
        register_rest_route(self::NS, '/catalog/rewrite', array(
            'methods'             => 'POST',
            'callback'            => array(__CLASS__, 'handle_catalog_rewrite'),
            'permission_callback' => array(__CLASS__, 'perm_admin'),
        ));
        register_rest_route(self::NS, '/catalog/sections', array(
            'methods'             => 'POST',
            'callback'            => array(__CLASS__, 'handle_catalog_sections'),
            'permission_callback' => array(__CLASS__, 'perm_admin'),
        ));

        // Разбор ответов Вебмастера: маршруты у него разные, а токен должен
        // остаться на сервере. Только для администратора.
        register_rest_route(self::NS, '/webmaster/probe', array(
            'methods'             => 'POST',
            'callback'            => array(__CLASS__, 'handle_webmaster_probe'),
            'permission_callback' => array(__CLASS__, 'perm_admin'),
        ));

        // Презентации разбиты на два шага: сначала бесплатная структура,
        // потом платная отрисовка. Иначе нельзя дать выбрать иллюстрации
        // по слайдам — их не из чего выбирать, пока слайдов нет.
        register_rest_route(self::NS, '/slides/outline', array(
            'methods'             => 'POST',
            'callback'            => array(__CLASS__, 'handle_slides_outline'),
            'permission_callback' => array(__CLASS__, 'perm_logged_in'),
        ));
        register_rest_route(self::NS, '/slides/upload', array(
            'methods'             => 'POST',
            'callback'            => array(__CLASS__, 'handle_slides_upload'),
            'permission_callback' => array(__CLASS__, 'perm_logged_in'),
        ));
        register_rest_route(self::NS, '/slides/render', array(
            'methods'             => 'POST',
            'callback'            => array(__CLASS__, 'handle_slides_render'),
            'permission_callback' => array(__CLASS__, 'perm_logged_in'),
        ));
        register_rest_route(self::NS, '/slides/history', array(
            'methods'             => 'GET',
            'callback'            => array(__CLASS__, 'handle_slides_history'),
            'permission_callback' => array(__CLASS__, 'perm_logged_in'),
        ));
        register_rest_route(self::NS, '/slides/(?P<task_id>[a-zA-Z0-9_-]+)', array(
            'methods'             => 'GET',
            'callback'            => array(__CLASS__, 'handle_slides_status'),
            'permission_callback' => array(__CLASS__, 'perm_logged_in'),
        ));

        // Заявка с лендинга обучения. Открыта для гостей — это её смысл;
        // от перебора защищает счётчик по адресу внутри GS_Leads.
        register_rest_route(self::NS, '/lead', array(
            'methods'             => 'POST',
            'callback'            => array(__CLASS__, 'handle_lead'),
            'permission_callback' => '__return_true',
        ));

        register_rest_route(self::NS, '/voice/archive', array(
            'methods'             => 'GET',
            'callback'            => array(__CLASS__, 'handle_voice_archive'),
            'permission_callback' => array(__CLASS__, 'perm_logged_in'),
        ));
        register_rest_route(self::NS, '/voice/publish', array(
            'methods'             => 'POST',
            'callback'            => array(__CLASS__, 'handle_voice_publish'),
            'permission_callback' => array(__CLASS__, 'perm_logged_in'),
        ));

        // Ручной возврат на баланс: сбои случаются, и оператор должен уметь
        // вернуть деньги, не залезая в базу. Каждый возврат попадает в журнал.
        register_rest_route(self::NS, '/voice/refund', array(
            'methods'             => 'POST',
            'callback'            => array(__CLASS__, 'handle_voice_refund'),
            'permission_callback' => array(__CLASS__, 'perm_admin'),
        ));

        register_rest_route(self::NS, '/voice/song', array(
            'methods'             => 'POST',
            'callback'            => array(__CLASS__, 'handle_voice_song'),
            'permission_callback' => array(__CLASS__, 'perm_logged_in'),
        ));
        register_rest_route(self::NS, '/voice/song/(?P<task_id>[a-zA-Z0-9_-]+)', array(
            'methods'             => 'GET',
            'callback'            => array(__CLASS__, 'handle_voice_song_state'),
            'permission_callback' => array(__CLASS__, 'perm_logged_in'),
        ));

        register_rest_route(self::NS, '/lab/callback', array(
            'methods'             => 'POST',
            'callback'            => array(__CLASS__, 'handle_lab_callback'),
            'permission_callback' => '__return_true',
        ));

        register_rest_route(self::NS, '/tts-fallback/test', array(
            'methods'             => 'POST',
            'callback'            => array(__CLASS__, 'handle_tts_fallback_test'),
            'permission_callback' => array(__CLASS__, 'perm_admin'),
        ));

        // Пробник моделей поставщика: узнать идентификатор и формат входа,
        // не заводя ради этого отдельный сервис (только админ).
        register_rest_route(self::NS, '/musicai/probe', array(
            'methods'             => 'POST',
            'callback'            => array(__CLASS__, 'handle_musicai_probe'),
            'permission_callback' => array(__CLASS__, 'perm_admin'),
        ));

        register_rest_route(self::NS, '/jobs/probe', array(
            'methods'             => 'POST',
            'callback'            => array(__CLASS__, 'handle_jobs_probe'),
            'permission_callback' => array(__CLASS__, 'perm_admin'),
        ));

        // Служба извлечения звука отказывает по-разному, а пользователю
        // видно одно и то же «не удалось получить дорожку». Здесь её ответ
        // виден целиком — без этого чинить нечего (только админ).
        register_rest_route(self::NS, '/ytaudio/probe', array(
            'methods'             => 'POST',
            'callback'            => array(__CLASS__, 'handle_ytaudio_probe'),
            'permission_callback' => array(__CLASS__, 'perm_admin'),
        ));

        // На сайте живут два телеграм-бота: один водит в кабинет озвучки,
        // другой — в нейрохаб, PDF и примерку дисков. Настройки у них
        // разные, и по одному полю с токеном не понять, какой это бот.
        // Здесь спрашиваем у самого Telegram — наружу отдаём только имя.
        register_rest_route(self::NS, '/telegram/whoami', array(
            'methods'             => 'GET',
            'callback'            => array(__CLASS__, 'handle_telegram_whoami'),
            'permission_callback' => array(__CLASS__, 'perm_admin'),
        ));

        // Временная проверка форматов запроса к поставщику (только админ).
        register_rest_route(self::NS, '/lab/provider-test', array(
            'methods'             => 'POST',
            'callback'            => array(__CLASS__, 'handle_provider_test'),
            'permission_callback' => array(__CLASS__, 'perm_admin'),
        ));

        register_rest_route(self::NS, '/lab/credits', array(
            'methods'             => 'GET',
            'callback'            => array(__CLASS__, 'handle_lab_credits'),
            'permission_callback' => array(__CLASS__, 'perm_admin'),
        ));

        register_rest_route(self::NS, '/links/install-menu', array(
            'methods'             => 'POST',
            'callback'            => array(__CLASS__, 'handle_install_menu'),
            'permission_callback' => array(__CLASS__, 'perm_admin'),
        ));

        // Кто вмешивается в заголовок страницы: разовая диагностика.
        register_rest_route(self::NS, '/diag/title', array(
            'methods'             => 'GET',
            'callback'            => array(__CLASS__, 'handle_diag_title'),
            'permission_callback' => array(__CLASS__, 'perm_admin'),
        ));

        // Кто и с каким балансом запускал генерации: вопрос операционный,
        // а в админке такой сводки нет. Только чтение и только админу.
        register_rest_route(self::NS, '/diag/balances', array(
            'methods'             => 'GET',
            'callback'            => array(__CLASS__, 'handle_diag_balances'),
            'permission_callback' => array(__CLASS__, 'perm_admin'),
        ));

        // Почему очередь отправки в индекс не двигается.
        register_rest_route(self::NS, '/diag/cron', array(
            'methods'             => 'GET',
            'callback'            => array(__CLASS__, 'handle_diag_cron'),
            'permission_callback' => array(__CLASS__, 'perm_admin'),
        ));

        register_rest_route(self::NS, '/showcase', array(
            'methods'             => 'POST',
            'callback'            => array(__CLASS__, 'handle_showcase_add'),
            'permission_callback' => array(__CLASS__, 'perm_admin'),
        ));
    }

    /**
     * Последние генерации и баланс их авторов.
     *
     * Отвечает на простой вопрос: не уходит ли работа людям, у которых на
     * счету пусто. Ничего не меняет, наружу не отдаёт ни почты, ни токенов —
     * только логин, суммы и состояние задач.
     */
    public static function handle_diag_balances($request) {
        global $wpdb;
        $limit = max(1, min(200, (int) $request->get_param('limit') ?: 50));
        $table = $wpdb->prefix . 'kie_tts_generations';

        $cols = $wpdb->get_col("SHOW COLUMNS FROM {$table}");
        if (!is_array($cols) || !$cols) {
            return new WP_Error('gs_no_table', 'Журнала генераций нет', array('status' => 500));
        }

        $rows = $wpdb->get_results(
            "SELECT * FROM {$table} ORDER BY id DESC LIMIT " . (int) $limit,
            ARRAY_A
        );
        if (!is_array($rows)) {
            $rows = array();
        }

        // Баланс спрашиваем по одному разу на человека, а не на строку.
        $balances = array();
        $out = array();
        foreach ($rows as $row) {
            $uid = (int) ($row['user_id'] ?? 0);
            if (!array_key_exists($uid, $balances)) {
                $user = $uid > 0 ? get_user_by('id', $uid) : null;
                $balances[$uid] = array(
                    'login'   => $user ? $user->user_login : ($uid > 0 ? 'нет такого пользователя' : 'гость'),
                    'balance' => ($uid > 0 && class_exists('GS_SFX')) ? GS_SFX::get_balance($uid) : 0.0,
                );
            }
            $out[] = array(
                'когда'    => (string) ($row['created_at'] ?? ''),
                'кто'      => $balances[$uid]['login'],
                'user_id'  => $uid,
                'что'      => (string) ($row['voice'] ?? ''),
                'название' => mb_substr((string) ($row['text'] ?? ''), 0, 40),
                'списано'  => isset($row['cost']) ? (float) $row['cost'] : null,
                'статус'   => (string) ($row['status'] ?? ''),
                'баланс'   => $balances[$uid]['balance'],
            );
        }

        // Ключи API по владельцам: живой ли это был человек или подарок
        // забрали, не приступая к работе.
        $keys = array();
        if ($request->get_param('keys') && class_exists('GS_Api_Keys')) {
            $by_user = array();
            foreach (GS_Api_Keys::index() as $record) {
                $uid = (int) ($record['user_id'] ?? 0);
                if (!isset($by_user[$uid])) {
                    $by_user[$uid] = array('ключей' => 0, 'вызовов' => 0, 'последний' => '', 'выдан' => '');
                }
                $by_user[$uid]['ключей']++;
                $by_user[$uid]['вызовов'] += (int) ($record['calls'] ?? 0);
                $used = (string) ($record['last_used'] ?? '');
                if ($used > $by_user[$uid]['последний']) {
                    $by_user[$uid]['последний'] = $used;
                }
                $made = (string) ($record['created'] ?? '');
                if ($by_user[$uid]['выдан'] === '' || $made < $by_user[$uid]['выдан']) {
                    $by_user[$uid]['выдан'] = $made;
                }
            }
            foreach ($by_user as $uid => $row) {
                $user = $uid > 0 ? get_user_by('id', $uid) : null;
                $row['user_id'] = $uid;
                $row['логин'] = $user ? $user->user_login : 'УДАЛЁН';
                $row['подарок'] = $uid > 0 ? (string) get_user_meta($uid, 'gs_api_trial_given', true) : '';
                $row['баланс'] = ($uid > 0 && class_exists('GS_SFX')) ? GS_SFX::get_balance($uid) : 0.0;
                $keys[] = $row;
            }
        }

        // Сверка: одинаковый баланс у разных людей выглядит подозрительно,
        // поэтому рядом показываем, что лежит в самой таблице балансов.
        $raw = array();
        if ($request->get_param('raw')) {
            $like = $wpdb->esc_like($wpdb->prefix . 'kie_tts') . '%';
            $tables = $wpdb->get_col($wpdb->prepare('SHOW TABLES LIKE %s', $like));
            $raw['таблицы'] = is_array($tables) ? $tables : array();
            foreach ((array) $raw['таблицы'] as $t) {
                $tcols = $wpdb->get_col("SHOW COLUMNS FROM {$t}");
                if (!is_array($tcols) || !in_array('balance', $tcols, true)) {
                    continue;
                }
                $raw['таблица_балансов'] = $t;
                $raw['колонки'] = $tcols;
                $raw['строки'] = $wpdb->get_results("SELECT * FROM {$t} ORDER BY id DESC LIMIT 40", ARRAY_A);
                break;
            }
        }

        return rest_ensure_response(array(
            'колонки'   => $cols,
            'всего'     => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}"),
            'показано'  => count($out),
            'записи'    => $out,
            'ключи'     => $keys,
            'сверка'    => $raw,
        ));
    }

    /** Состояние планировщика и очереди IndexNow. */
    public static function handle_diag_cron($request) {
        $events = array();
        foreach ((array) _get_cron_array() as $time => $hooks) {
            foreach ((array) $hooks as $hook => $items) {
                if (strpos($hook, 'gs_') !== 0) {
                    continue;
                }
                $events[] = array('hook' => $hook, 'at' => gmdate('Y-m-d H:i:s', $time), 'in' => $time - time());
            }
        }
        $out = array(
            'disabled' => defined('DISABLE_WP_CRON') && DISABLE_WP_CRON,
            'alt'      => defined('ALTERNATE_WP_CRON') && ALTERNATE_WP_CRON,
            'lock'     => get_transient('doing_cron'),
            'queue'    => GS_Index::queue_size(),
            'events'   => $events,
        );
        if ($request->get_param('prices')) {
            $out['prices'] = array();
            foreach (GS_Lab::services() as $id => $service) {
                $out['prices'][$id] = array(
                    'min'    => get_option('gs_lab_min_' . $id, 'нет'),
                    'cost'   => get_option($service['cost_option'], 'нет'),
                    'rate'   => get_option('gs_lab_rate_' . $id, 'нет'),
                    'effect' => GS_Lab::get_cost($id),
                    'guest'  => GS_Lab::allows_guests($id),
                );
            }
        }
        if ($request->get_param('drain')) {
            GS_Index::drain();
            $out['after'] = GS_Index::queue_size();
        }
        return rest_ensure_response($out);
    }

    /** Список обработчиков, которые правят заголовок страницы. */
    public static function handle_diag_title($request) {
        global $wp_filter;
        $out = array();
        foreach (array('pre_get_document_title', 'document_title_parts', 'document_title_separator', 'wp_title') as $hook) {
            $out[$hook] = array();
            if (empty($wp_filter[$hook])) {
                continue;
            }
            foreach ($wp_filter[$hook]->callbacks as $priority => $callbacks) {
                foreach ($callbacks as $cb) {
                    $fn = $cb['function'];
                    if (is_array($fn)) {
                        $name = (is_object($fn[0]) ? get_class($fn[0]) : (string) $fn[0]) . '::' . $fn[1];
                    } elseif ($fn instanceof Closure) {
                        $name = 'Closure';
                    } else {
                        $name = (string) $fn;
                    }
                    $file = '';
                    try {
                        $ref = is_array($fn)
                            ? new ReflectionMethod($fn[0], $fn[1])
                            : new ReflectionFunction($fn);
                        $file = str_replace(ABSPATH, '', (string) $ref->getFileName()) . ':' . $ref->getStartLine();
                    } catch (Throwable $e) {
                        $file = '—';
                    }
                    $out[$hook][] = array('priority' => $priority, 'callback' => $name, 'where' => $file);
                }
            }
        }
        return rest_ensure_response($out);
    }

    /**
     * Сколько бесплатных запусков отдаём одному гостю.
     *
     * Операция ничего не стоит по деньгам, но занимает наш сервер, поэтому
     * держим разумный предел: человеку на пробу хватает, выкачать чужой
     * канал пачкой — уже нет.
     */
    const GUEST_LIMIT  = 5;
    const GUEST_WINDOW = 3600;

    private static function guest_key($service_id) {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
        return 'gs_guest_' . $service_id . '_' . md5($ip . '|' . wp_salt());
    }

    private static function guest_gate($service_id) {
        if ((int) get_transient(self::guest_key($service_id)) < self::GUEST_LIMIT) {
            return true;
        }
        return new WP_Error(
            'gs_guest_limit',
            'Бесплатные запуски на ближайший час исчерпаны. Войдите в аккаунт — там ограничения нет.',
            array('status' => 429)
        );
    }

    /**
     * Счёт ведём по удачным запускам: сорвалась задача не по вине
     * человека — глупо тратить на это его попытку.
     */
    private static function guest_count($service_id) {
        $key = self::guest_key($service_id);
        set_transient($key, (int) get_transient($key) + 1, self::GUEST_WINDOW);
    }

    public static function perm_logged_in() {
        return is_user_logged_in();
    }

    public static function perm_admin() {
        return current_user_can('manage_options');
    }

    /* ---------------------------------------------------------------------
     * Студия
     * ------------------------------------------------------------------ */

    public static function handle_generate($request) {
        $user_id = get_current_user_id();
        $params  = $request->get_json_params();
        if (!is_array($params)) {
            $params = $request->get_params();
        }

        $raw_prompt = isset($params['prompt']) ? sanitize_textarea_field((string) $params['prompt']) : '';
        if (trim($raw_prompt) === '') {
            return new WP_Error('gs_missing_prompt', 'Опишите звук, который нужно создать', array('status' => 400));
        }
        if (mb_strlen($raw_prompt) > 400) {
            return new WP_Error('gs_prompt_too_long', 'Описание слишком длинное — максимум 400 символов', array('status' => 400));
        }

        $mode = isset($params['mode']) ? sanitize_key((string) $params['mode']) : GS_SFX::MODE_SFX;
        if (!array_key_exists($mode, GS_SFX::get_modes())) {
            $mode = GS_SFX::MODE_SFX;
        }

        $model = isset($params['model']) ? sanitize_text_field((string) $params['model']) : 'V5';
        if (!array_key_exists($model, GS_SFX::get_models())) {
            $model = 'V5';
        }

        $seconds = isset($params['seconds']) ? (int) $params['seconds'] : 4;
        $seconds = max(1, min(60, $seconds));

        $loop  = !empty($params['loop']) || $mode === GS_SFX::MODE_LOOP;
        $tempo = isset($params['tempo']) ? (int) $params['tempo'] : 0;
        $key   = isset($params['key']) ? sanitize_text_field((string) $params['key']) : '';

        $cost    = GS_SFX::get_cost();
        $balance = GS_SFX::get_balance($user_id);

        if ($balance < $cost) {
            return new WP_Error(
                'gs_insufficient_balance',
                sprintf('Недостаточно средств. Баланс: %.2f ₽, нужно: %.2f ₽', $balance, $cost),
                array('status' => 402, 'balance' => $balance, 'cost' => $cost)
            );
        }

        $prompt = GS_SFX::build_prompt($raw_prompt, $mode, $seconds);

        $created = GS_SFX::create_task($prompt, array(
            'model'        => $model,
            'loop'         => $loop,
            'tempo'        => $tempo,
            'key'          => $key,
            'callback_url' => add_query_arg('token', GS_SFX::callback_token(), rest_url(self::NS . '/sfx/callback')),
        ));

        if (empty($created['ok'])) {
            return new WP_Error('gs_kie_error', $created['message'] ?: 'Сервис генерации не принял задачу', array('status' => 502));
        }

        $task_id = $created['task_id'];

        // Бесплатный сервис ничего не списывает: нулевое списание база
        // считает неудачей, и пользователь получил бы ложный отказ.
        if ($cost > 0 && !GS_SFX::charge($user_id, $cost)) {
            return new WP_Error('gs_charge_failed', 'Не удалось списать средства с баланса', array('status' => 500));
        }

        if (class_exists('KIE_TTS_DB')) {
            $is_telegram = class_exists('KIE_TTS_Auth') && KIE_TTS_Auth::is_telegram_user($user_id);
            KIE_TTS_DB::save_generation($user_id, $task_id, $raw_prompt, 'sfx:' . $model, $cost, $is_telegram);
        }

        update_option('gs_sfx_task_' . $task_id, array(
            'user_id' => $user_id,
            'prompt'  => $raw_prompt,
            'full'    => $prompt,
            'mode'    => $mode,
            'model'   => $model,
            'cost'    => $cost,
        ), false);

        return rest_ensure_response(array(
            'success'   => true,
            'task_id'   => $task_id,
            'prompt'    => $raw_prompt,
            'full_prompt' => $prompt,
            'cost'      => $cost,
            'balance'   => GS_SFX::get_balance($user_id),
        ));
    }

    public static function handle_status($request) {
        $task_id = (string) $request['task_id'];
        $user_id = get_current_user_id();

        $meta = get_option('gs_sfx_task_' . $task_id, array());
        $stored = self::get_stored_generation($task_id);

        // Служебная запись удаляется по завершении задачи, поэтому владельца
        // проверяем и по ней, и по строке в истории генераций.
        $owner = 0;
        if (is_array($meta) && !empty($meta['user_id'])) {
            $owner = (int) $meta['user_id'];
        } elseif (is_array($stored) && isset($stored['user_id'])) {
            $owner = (int) $stored['user_id'];
        }
        if ($owner > 0 && $owner !== (int) $user_id && !current_user_can('manage_options')) {
            return new WP_Error('gs_forbidden', 'Задача принадлежит другому пользователю', array('status' => 403));
        }

        if ($stored && !empty($stored['audio_url']) && $stored['status'] === 'completed') {
            return rest_ensure_response(array(
                'success'   => true,
                'status'    => 'completed',
                'audio_url' => $stored['audio_url'],
                'prompt'    => is_array($meta) ? ($meta['prompt'] ?? '') : '',
                'balance'   => GS_SFX::get_balance($user_id),
            ));
        }

        $task = GS_SFX::fetch_task($task_id);
        if (empty($task['ok'])) {
            return rest_ensure_response(array('success' => true, 'status' => 'pending', 'message' => $task['message']));
        }

        if (GS_SFX::is_failed_status($task['status'])) {
            self::finalize_failure($task_id, $meta);
            return rest_ensure_response(array(
                'success' => false,
                'status'  => 'failed',
                'message' => $task['message'] ?: 'Генерация не удалась, средства возвращены на баланс',
                'balance' => GS_SFX::get_balance($user_id),
            ));
        }

        if ($task['audio_url'] !== '') {
            $local = GS_SFX::store_result($task_id, $task['audio_url']);
            $url = $local !== '' ? $local : $task['audio_url'];

            if (class_exists('KIE_TTS_DB')) {
                KIE_TTS_DB::update_generation_status($task_id, 'completed', $url);
            }
            // Служебная запись о задаче больше не нужна — иначе wp_options
            // растёт по строке на каждую генерацию.
            delete_option('gs_sfx_task_' . $task_id);

            return rest_ensure_response(array(
                'success'   => true,
                'status'    => 'completed',
                'audio_url' => $url,
                'title'     => $task['title'],
                'duration'  => $task['duration'],
                'prompt'    => is_array($meta) ? ($meta['prompt'] ?? '') : '',
                'balance'   => GS_SFX::get_balance($user_id),
            ));
        }

        return rest_ensure_response(array(
            'success' => true,
            'status'  => 'pending',
            'stage'   => $task['status'],
        ));
    }

    public static function handle_callback($request) {
        $token = (string) $request->get_param('token');
        if (!hash_equals(GS_SFX::callback_token(), $token)) {
            return new WP_Error('gs_bad_token', 'Неверный токен колбэка', array('status' => 403));
        }

        $params = $request->get_json_params();
        if (!is_array($params)) {
            return rest_ensure_response(array('success' => true));
        }

        $data = isset($params['data']) && is_array($params['data']) ? $params['data'] : $params;
        $task_id = '';
        foreach (array('task_id', 'taskId') as $field) {
            if (!empty($data[$field])) {
                $task_id = (string) $data[$field];
                break;
            }
        }
        if ($task_id === '') {
            return rest_ensure_response(array('success' => true));
        }

        $audio_url = '';
        $items = array();
        foreach (array('data', 'sunoData') as $field) {
            if (!empty($data[$field]) && is_array($data[$field])) {
                $items = $data[$field];
                break;
            }
        }
        if (!empty($items[0]) && is_array($items[0])) {
            foreach (array('audio_url', 'source_audio_url', 'stream_audio_url') as $field) {
                if (!empty($items[0][$field])) {
                    $audio_url = (string) $items[0][$field];
                    break;
                }
            }
        }

        if ($audio_url === '') {
            return rest_ensure_response(array('success' => true));
        }

        $local = GS_SFX::store_result($task_id, $audio_url);
        if (class_exists('KIE_TTS_DB')) {
            KIE_TTS_DB::update_generation_status($task_id, 'completed', $local !== '' ? $local : $audio_url);
        }

        return rest_ensure_response(array('success' => true));
    }

    public static function handle_history($request) {
        global $wpdb;
        $user_id = get_current_user_id();
        $table = $wpdb->prefix . 'kie_tts_generations';

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT task_id, text, voice, status, audio_url, created_at
             FROM {$table}
             WHERE user_id = %d AND voice LIKE %s
             ORDER BY id DESC
             LIMIT 30",
            $user_id,
            'sfx:%'
        ), ARRAY_A);

        if (!is_array($rows)) {
            $rows = array();
        }

        $items = array();
        foreach ($rows as $row) {
            $items[] = array(
                'task_id'    => (string) $row['task_id'],
                'prompt'     => (string) $row['text'],
                'model'      => str_replace('sfx:', '', (string) $row['voice']),
                'status'     => (string) $row['status'],
                'audio_url'  => (string) $row['audio_url'],
                'created_at' => (string) $row['created_at'],
            );
        }

        return rest_ensure_response(array('success' => true, 'items' => $items));
    }

    public static function handle_balance() {
        return rest_ensure_response(array(
            'success' => true,
            'balance' => GS_SFX::get_balance(get_current_user_id()),
            'cost'    => GS_SFX::get_cost(),
        ));
    }

    private static function get_stored_generation($task_id) {
        if (!class_exists('KIE_TTS_DB')) {
            return null;
        }
        $row = KIE_TTS_DB::get_generation_by_task_id($task_id);
        return is_array($row) ? $row : null;
    }

    /**
     * Провал генерации: помечаем и возвращаем деньги (один раз).
     */
    private static function finalize_failure($task_id, $meta) {
        $stored = self::get_stored_generation($task_id);
        if ($stored && (string) $stored['status'] === 'failed') {
            return;
        }
        if (class_exists('KIE_TTS_DB')) {
            KIE_TTS_DB::update_generation_status($task_id, 'failed');
        }
        if (is_array($meta) && !empty($meta['user_id']) && !empty($meta['cost'])) {
            GS_SFX::refund_charge((int) $meta['user_id'], (float) $meta['cost']);
        }
        delete_option('gs_sfx_task_' . $task_id);
    }

    /* ---------------------------------------------------------------------
     * Импорт
     * ------------------------------------------------------------------ */

    public static function handle_import_start($request) {
        $params = $request->get_json_params();
        if (!is_array($params)) {
            $params = $request->get_params();
        }

        $limit = isset($params['limit']) ? (int) $params['limit'] : 20;
        $force = !empty($params['force']);

        $slugs = array();
        if (!empty($params['slugs'])) {
            if (is_array($params['slugs'])) {
                $slugs = $params['slugs'];
            } else {
                $slugs = preg_split('~[\s,]+~', (string) $params['slugs'], -1, PREG_SPLIT_NO_EMPTY);
            }
        } else {
            $count = isset($params['count']) ? (int) $params['count'] : 50;
            $slugs = GS_Importer::pending_slugs($count);
        }

        $queued = GS_Importer::start($slugs, $limit, $force);

        return rest_ensure_response(array(
            'success' => true,
            'queued'  => $queued,
            'state'   => GS_Importer::get_state(),
        ));
    }

    public static function handle_import_tick() {
        $result = GS_Importer::run_tick();
        return rest_ensure_response(array(
            'success' => true,
            'result'  => $result,
            'state'   => GS_Importer::get_state(),
            'stats'   => GS_Catalog::stats(),
        ));
    }

    public static function handle_import_stop() {
        GS_Importer::stop();
        return rest_ensure_response(array('success' => true, 'state' => GS_Importer::get_state()));
    }

    public static function handle_import_status() {
        return rest_ensure_response(array(
            'success'   => true,
            'state'     => GS_Importer::get_state(),
            'stats'     => GS_Catalog::stats(),
            'queue_len' => count((array) get_option(GS_Importer::OPT_QUEUE, array())),
            'disk'      => GS_Storage::disk_usage(),
            'disk_free' => GS_Storage::disk_free(),
        ));
    }

    /* ---------------------------------------------------------------------
     * Микросервисы
     * ------------------------------------------------------------------ */

    /* ---------------------------------------------------------------------
     * Ключи публичного API
     * ------------------------------------------------------------------ */

    public static function handle_api_keys_list($request) {
        return rest_ensure_response(array(
            'success' => true,
            'keys'    => GS_Api_Keys::for_user(get_current_user_id()),
        ));
    }

    public static function handle_api_key_issue($request) {
        $params = $request->get_json_params();
        $label = is_array($params) && isset($params['label']) ? (string) $params['label'] : '';

        $issued = GS_Api_Keys::issue(get_current_user_id(), $label);
        if (empty($issued['ok'])) {
            return new WP_Error('gs_key_failed', $issued['message'], array('status' => 400));
        }
        // Открытое значение ключа и секрет отдаём ровно один раз.
        return rest_ensure_response(array(
            'success' => true,
            'key'     => $issued['key'],
            'secret'  => $issued['record']['secret'],
            // Сколько подарили на пробу: интерфейсу надо об этом сказать,
            // иначе человек не поймёт, откуда взялся баланс.
            'trial'   => (float) ($issued['trial'] ?? 0),
            'balance' => class_exists('GS_SFX') ? GS_SFX::get_balance(get_current_user_id()) : 0,
            'keys'    => GS_Api_Keys::for_user(get_current_user_id()),
        ));
    }

    public static function handle_api_key_revoke($request) {
        $params = $request->get_json_params();
        $prefix = is_array($params) && isset($params['prefix']) ? sanitize_text_field((string) $params['prefix']) : '';
        if ($prefix === '') {
            return new WP_Error('gs_key_missing', 'Не указан ключ', array('status' => 400));
        }
        GS_Api_Keys::revoke(get_current_user_id(), $prefix);
        return rest_ensure_response(array(
            'success' => true,
            'keys'    => GS_Api_Keys::for_user(get_current_user_id()),
        ));
    }

    public static function handle_lab_upload($request) {
        $kind = sanitize_key((string) $request->get_param('kind'));
        if (!in_array($kind, array('image', 'audio', 'video'), true)) {
            return new WP_Error('gs_bad_kind', 'Неизвестный тип файла', array('status' => 400));
        }
        $service_id = sanitize_key((string) $request->get_param('service'));

        $files = $request->get_file_params();
        $file = isset($files['file']) ? $files['file'] : null;
        if (!$file) {
            return new WP_Error('gs_no_file', 'Файл не приложен', array('status' => 400));
        }

        $max = GS_Lab::MAX_IMAGE_BYTES;
        if ($kind === 'audio') {
            $max = $service_id === 'vocal' ? GS_Lab::MAX_AUDIO_BYTES_VOCAL : GS_Lab::MAX_AUDIO_BYTES;
        } elseif ($kind === 'video') {
            $max = GS_Lab::MAX_VIDEO_BYTES;
        }

        $stored = GS_Lab::store_upload($file, $kind, $max);
        if (empty($stored['ok'])) {
            return new WP_Error('gs_upload_failed', $stored['message'], array('status' => 400));
        }

        // Цена зависит от длины записи, поэтому считаем её сразу после загрузки
        // и показываем пользователю до запуска обработки.
        $seconds = 0.0;
        $price = GS_Lab::get_cost($service_id);
        // Видео считается по тем же правилам, что и запись: цена зависит от
        // длительности, а длинный файл разоряет человека на одном нажатии.
        if ($kind === 'audio' || $kind === 'video') {
            $seconds = GS_Lab::media_duration(GS_Lab::local_path($stored['url']));
            $limit = GS_Lab::max_seconds($service_id);
            if ($limit > 0 && $seconds > $limit + 1) {
                $path = GS_Lab::local_path($stored['url']);
                if ($path !== '') {
                    @unlink($path);
                }
                return new WP_Error(
                    'gs_too_long',
                    $limit < 120
                        ? sprintf('Ролик длиннее %d секунд — вырежьте фрагмент покороче', $limit)
                        : sprintf('Запись длиннее %d мин — загрузите файл покороче', (int) ceil($limit / 60)),
                    array('status' => 400)
                );
            }
            $price = GS_Lab::price($service_id, $seconds);
        }

        return rest_ensure_response(array(
            'success'  => true,
            'url'      => $stored['url'],
            'duration' => round($seconds),
            'price'    => $price,
        ));
    }

    public static function handle_lab_generate($request) {
        $user_id = get_current_user_id();
        $params  = $request->get_json_params();
        if (!is_array($params)) {
            $params = $request->get_params();
        }

        $service_id = sanitize_key((string) ($params['service'] ?? ''));
        $service = GS_Lab::get_service($service_id);
        if (!$service) {
            return new WP_Error('gs_bad_service', 'Неизвестный сервис', array('status' => 400));
        }

        if ($user_id <= 0) {
            if (!GS_Lab::allows_guests($service_id)) {
                return new WP_Error('gs_login_required', 'Войдите, чтобы запустить обработку', array('status' => 401));
            }
            $gate = self::guest_gate($service_id);
            if (is_wp_error($gate)) {
                return $gate;
            }
        }

        $payload = array();
        $optional = (array) (isset($service['input_optional']) ? $service['input_optional'] : array());
        foreach ($service['inputs'] as $input) {
            $field = $input . '_url';
            $url = isset($params[$field]) ? esc_url_raw((string) $params[$field]) : '';
            if ($url === '' && in_array($input, $optional, true)) {
                // Файл необязателен: сервис примет вместо него ссылку.
                $payload[$field] = '';
                continue;
            }
            // Обычным пользователям — только свои загрузки; администратору разрешаем
            // внешний адрес, чтобы можно было проверить сервис на эталонном файле.
            $own_upload = $url !== '' && strpos($url, GS_Lab::uploads_url()) === 0;
            if (!$own_upload && !(current_user_can('manage_options') && $url !== '')) {
                return new WP_Error('gs_missing_file', 'Сначала загрузите файл', array('status' => 400));
            }
            $payload[$field] = $url;
        }
        if (!empty($service['prompt'])) {
            $payload['prompt'] = sanitize_textarea_field((string) ($params['prompt'] ?? ''));
        }

        // Дополнительные поля берём только объявленные сервисом — присланному
        // сверх того не верим.
        if (!empty($service['fields']) && is_array($service['fields'])) {
            $sent = isset($params['fields']) && is_array($params['fields']) ? $params['fields'] : array();
            $clean = array();
            foreach ($service['fields'] as $name => $field) {
                $type = isset($field['type']) ? (string) $field['type'] : 'text';
                if ($type === 'checkbox') {
                    $clean[$name] = array_key_exists($name, $sent)
                        ? (bool) filter_var($sent[$name], FILTER_VALIDATE_BOOLEAN)
                        : !empty($field['default']);
                    continue;
                }
                $value = isset($sent[$name]) ? (string) $sent[$name] : '';
                $value = $type === 'textarea' ? sanitize_textarea_field($value) : sanitize_text_field($value);
                if ($type === 'select' && !empty($field['options']) && !array_key_exists($value, (array) $field['options'])) {
                    $value = (string) (isset($field['default']) ? $field['default'] : '');
                }
                $max = isset($field['max']) ? (int) $field['max'] : 200;
                $clean[$name] = $max > 0 ? mb_substr($value, 0, $max) : $value;
            }
            $payload['fields'] = $clean;
        }

        // Источник может быть ссылкой: приводим его к звуку и узнаём
        // длительность до оплаты — иначе двухчасовая запись по ссылке
        // считалась бы по минимальной цене.
        $prepared = GS_Lab::prepare($service_id, $payload);
        if (empty($prepared['ok'])) {
            return new WP_Error('gs_bad_source', $prepared['message'] ?: 'Не удалось разобрать источник', array('status' => 400));
        }
        $payload = $prepared['payload'];

        // Считаем длительность сами: присланной цене не верим.
        $seconds = (float) $prepared['seconds'];
        if ($seconds <= 0 && !empty($payload['audio_url'])) {
            $seconds = GS_Lab::media_duration(GS_Lab::local_path($payload['audio_url']));
        }
        if (!empty($payload['audio_url'])) {
            $limit = GS_Lab::max_seconds($service_id);
            if ($limit > 0 && $seconds > $limit + 1) {
                return new WP_Error(
                    'gs_too_long',
                    sprintf('Запись длиннее %d мин — разрежьте её на части', (int) ceil($limit / 60)),
                    array('status' => 400)
                );
            }
        }

        $cost = GS_Lab::price($service_id, $seconds);
        $balance = GS_SFX::get_balance($user_id);
        if ($balance < $cost) {
            return new WP_Error(
                'gs_insufficient_balance',
                sprintf('Недостаточно средств. Баланс: %.2f ₽, нужно: %.2f ₽', $balance, $cost),
                array('status' => 402, 'balance' => $balance, 'cost' => $cost)
            );
        }

        // Ручной очереди нужно знать, чей это заказ и на какую сумму.
        $payload['user_id'] = $user_id;
        $payload['cost']    = $cost;
        $payload['seconds'] = (int) round($seconds);

        $created = GS_Lab::create_task($service_id, $payload);
        if (empty($created['ok'])) {
            return new WP_Error('gs_service_error', $created['message'] ?: 'Сервис не принял задачу', array('status' => 502));
        }
        $task_id = $created['task_id'];

        // Бесплатная операция ничего не списывает: нулевое списание база
        // считает неудачей, и пользователь получил бы ложный отказ.
        if ($cost > 0 && !GS_SFX::charge($user_id, $cost)) {
            return new WP_Error('gs_charge_failed', 'Не удалось списать средства с баланса', array('status' => 500));
        }

        if ($user_id <= 0) {
            self::guest_count($service_id);
        }

        // Гостю историю писать некуда — у него нет аккаунта.
        if ($user_id > 0 && class_exists('KIE_TTS_DB')) {
            $is_telegram = class_exists('KIE_TTS_Auth') && KIE_TTS_Auth::is_telegram_user($user_id);
            KIE_TTS_DB::save_generation($user_id, $task_id, $service['menu'], 'lab:' . $service_id, $cost, $is_telegram);
        }
        update_option('gs_lab_task_' . $task_id, array(
            'user_id' => $user_id,
            'service' => $service_id,
            'cost'    => $cost,
        ), false);

        return rest_ensure_response(array(
            'success' => true,
            'task_id' => $task_id,
            'cost'    => $cost,
            'balance' => GS_SFX::get_balance($user_id),
        ));
    }

    public static function handle_lab_status($request) {
        $service_id = sanitize_key((string) $request['service']);
        $task_id    = (string) $request['task_id'];
        $user_id    = get_current_user_id();

        if (!GS_Lab::get_service($service_id)) {
            return new WP_Error('gs_bad_service', 'Неизвестный сервис', array('status' => 400));
        }

        $meta = get_option('gs_lab_task_' . $task_id, array());
        $stored = self::get_stored_generation($task_id);
        $owner = 0;
        if (is_array($meta) && !empty($meta['user_id'])) {
            $owner = (int) $meta['user_id'];
        } elseif (is_array($stored) && isset($stored['user_id'])) {
            $owner = (int) $stored['user_id'];
        }
        if ($owner > 0 && $owner !== (int) $user_id && !current_user_can('manage_options')) {
            return new WP_Error('gs_forbidden', 'Задача принадлежит другому пользователю', array('status' => 403));
        }

        $task = GS_Lab::fetch_task($service_id, $task_id);
        if (empty($task['ok'])) {
            return rest_ensure_response(array('success' => true, 'status' => 'pending', 'message' => $task['message']));
        }

        if ($task['status'] === 'failed') {
            if (class_exists('KIE_TTS_DB')) {
                KIE_TTS_DB::update_generation_status($task_id, 'failed');
            }
            if (is_array($meta) && !empty($meta['user_id']) && !empty($meta['cost'])) {
                GS_SFX::refund_charge((int) $meta['user_id'], (float) $meta['cost']);
            }
            delete_option('gs_lab_task_' . $task_id);
            return rest_ensure_response(array(
                'success' => false,
                'status'  => 'failed',
                'message' => $task['message'] ?: 'Обработка не удалась, средства возвращены',
                'balance' => GS_SFX::get_balance($user_id),
            ));
        }

        if ($task['status'] !== 'completed') {
            return rest_ensure_response(array('success' => true, 'status' => 'pending'));
        }

        $files = array();
        foreach ($task['files'] as $file) {
            // Текстовые выгрузки уже лежат у нас — перекладывать их незачем.
            $local = $file['kind'] === 'file' ? '' : GS_Lab::store_result($task_id, $file['url'], $file['kind']);
            $files[] = array(
                'label' => $file['label'],
                'kind'  => $file['kind'],
                'url'   => $local !== '' ? $local : $file['url'],
            );
        }
        if (class_exists('KIE_TTS_DB') && !empty($files)) {
            KIE_TTS_DB::update_generation_status($task_id, 'completed', $files[0]['url']);
        }
        delete_option('gs_lab_task_' . $task_id);

        return rest_ensure_response(array(
            'success' => true,
            'status'  => 'completed',
            'files'   => $files,
            'text'    => isset($task['text']) ? (string) $task['text'] : '',
            'balance' => GS_SFX::get_balance($user_id),
        ));
    }

    /* ---------------------------------------------------------------------
     * Песня своим голосом
     *
     * Деньги берём в двух местах: за создание голоса и за каждую песню.
     * Списываем в момент постановки задачи, а при отказе поставщика
     * возвращаем на баланс — человек не должен платить за неудачу.
     * ------------------------------------------------------------------ */

    /** Ссылка на запись: принимаем только свои загрузки. */
    private static function own_upload($url) {
        $url = esc_url_raw((string) $url);
        if ($url === '' || strpos($url, GS_Lab::uploads_url()) !== 0) {
            return '';
        }
        return $url;
    }

    public static function handle_voice_phrase($request) {
        $user_id = get_current_user_id();
        $params  = $request->get_json_params();
        if (!is_array($params)) {
            $params = $request->get_params();
        }

        $url = self::own_upload($params['audio_url'] ?? '');
        if ($url === '') {
            return new WP_Error('gs_no_audio', 'Сначала запишите или загрузите свой голос', array('status' => 400));
        }

        $cost = GS_Voice::voice_cost();
        if ($cost > 0 && !GS_SFX::charge($user_id, $cost)) {
            return new WP_Error('gs_charge_failed', 'На балансе не хватает средств', array('status' => 402));
        }

        try {
            $seconds = GS_Lab::media_duration(GS_Lab::local_path($url));
            $res = GS_Voice::start_phrase($url, $seconds);
        } catch (Throwable $e) {
            if ($cost > 0) {
                GS_SFX::refund_charge($user_id, $cost);
            }
            error_log('genius-sounds: создание голоса — ' . $e->getMessage());
            return new WP_Error('gs_voice_failed', 'Не получилось начать создание голоса. Деньги вернулись на баланс.', array('status' => 500));
        }
        if (empty($res['ok'])) {
            if ($cost > 0) {
                GS_SFX::refund_charge($user_id, $cost);
            }
            return new WP_Error('gs_voice_failed', $res['message'] !== '' ? $res['message'] : 'Не удалось начать создание голоса', array('status' => 502));
        }

        // Плату помним при задаче: если голос не создастся, вернём её.
        update_option('gs_voice_task_' . $res['task_id'], array(
            'user_id' => $user_id,
            'cost'    => $cost,
            'at'      => time(),
        ), false);

        return rest_ensure_response(array(
            'task_id' => $res['task_id'],
            'status'  => 'pending',
            'cost'    => $cost,
            'balance' => GS_SFX::get_balance($user_id),
        ));
    }

    public static function handle_voice_phrase_state($request) {
        $task_id = sanitize_text_field((string) $request['task_id']);
        $state = GS_Voice::phrase_state($task_id);
        if ($state['status'] === 'failed') {
            self::refund_voice_task($task_id);
        }
        $meta = get_option('gs_voice_task_' . $task_id);
        if ($state['status'] === 'pending' && is_array($meta)
            && time() - (int) ($meta['at'] ?? 0) > self::VOICE_TIMEOUT) {
            self::refund_voice_task($task_id);
            return rest_ensure_response(array(
                'status'  => 'failed',
                'phrase'  => '',
                'message' => 'Сервис не ответил за отведённое время. Деньги вернулись на баланс — попробуйте ещё раз.',
            ));
        }
        return rest_ensure_response(array(
            'status'  => $state['status'],
            'phrase'  => $state['phrase'],
            'message' => $state['message'],
        ));
    }

    public static function handle_voice_verify($request) {
        $user_id = get_current_user_id();
        $params  = $request->get_json_params();
        if (!is_array($params)) {
            $params = $request->get_params();
        }

        $task_id = sanitize_text_field((string) ($params['task_id'] ?? ''));
        $url     = self::own_upload($params['audio_url'] ?? '');
        $name    = sanitize_text_field((string) ($params['name'] ?? ''));

        if ($task_id === '' || $url === '') {
            return new WP_Error('gs_no_audio', 'Запишите проверочную фразу и попробуйте снова', array('status' => 400));
        }
        $meta = get_option('gs_voice_task_' . $task_id);
        if (!is_array($meta) || (int) ($meta['user_id'] ?? 0) !== $user_id) {
            return new WP_Error('gs_foreign_task', 'Задача не найдена', array('status' => 404));
        }

        $res = GS_Voice::submit_verify($task_id, $url, $name);
        if (empty($res['ok'])) {
            return new WP_Error('gs_voice_failed', $res['message'] !== '' ? $res['message'] : 'Запись не принята', array('status' => 502));
        }

        $meta['name'] = $name;
        update_option('gs_voice_task_' . $task_id, $meta, false);

        return rest_ensure_response(array('status' => 'pending'));
    }

    /** Сколько ждём голос, прежде чем считать задачу пропавшей. */
    const VOICE_TIMEOUT = 900;

    public static function handle_voice_verify_state($request) {
        $user_id = get_current_user_id();
        $task_id = sanitize_text_field((string) $request['task_id']);
        $meta = get_option('gs_voice_task_' . $task_id);
        if (!is_array($meta) || (int) ($meta['user_id'] ?? 0) !== $user_id) {
            return new WP_Error('gs_foreign_task', 'Задача не найдена', array('status' => 404));
        }

        $state = GS_Voice::voice_state($task_id);
        $out = array('status' => $state['status'], 'message' => $state['message'], 'voices' => array());

        // Поставщик умеет молча не браться за запись: задача остаётся
        // в ожидании навсегда. Без срока деньги зависали бы вместе с ней.
        if ($state['status'] === 'pending'
            && time() - (int) ($meta['at'] ?? 0) > self::VOICE_TIMEOUT) {
            self::refund_voice_task($task_id);
            return rest_ensure_response(array(
                'status'  => 'failed',
                'message' => 'Сервис не ответил за отведённое время. Деньги вернулись на баланс — попробуйте записать голос ещё раз.',
                'voices'  => array(),
            ));
        }

        if ($state['status'] === 'completed') {
            $voices = GS_Voice::remember_voice($user_id, $state['voice_id'], (string) ($meta['name'] ?? ''));
            delete_option('gs_voice_task_' . $task_id);
            $out['voice_id'] = $state['voice_id'];
            $out['voices']   = $voices;
        } elseif ($state['status'] === 'failed') {
            self::refund_voice_task($task_id);
        }
        return rest_ensure_response($out);
    }

    /** Возврат платы за несостоявшийся голос — ровно один раз. */
    private static function refund_voice_task($task_id) {
        $meta = get_option('gs_voice_task_' . $task_id);
        if (!is_array($meta)) {
            return;
        }
        if ((float) ($meta['cost'] ?? 0) > 0) {
            GS_SFX::refund_charge((int) $meta['user_id'], (float) $meta['cost']);
        }
        delete_option('gs_voice_task_' . $task_id);
    }

    public static function handle_voice_list($request) {
        $user_id = get_current_user_id();
        return rest_ensure_response(array(
            'voices'     => GS_Voice::user_voices($user_id),
            'balance'    => GS_SFX::get_balance($user_id),
            'voice_cost' => GS_Voice::voice_cost(),
            'song_cost'  => GS_Voice::song_cost(),
        ));
    }

    const OPT_REFUND_LOG = 'gs_refund_log';

    public static function handle_voice_refund($request) {
        $params = $request->get_json_params();
        if (!is_array($params)) {
            $params = $request->get_params();
        }
        $user_id = (int) ($params['user_id'] ?? 0);
        $amount  = round((float) ($params['amount'] ?? 0), 2);
        $reason  = sanitize_text_field((string) ($params['reason'] ?? ''));

        if ($user_id <= 0 || !get_userdata($user_id)) {
            return new WP_Error('gs_bad_user', 'Пользователь не найден', array('status' => 400));
        }
        if ($amount <= 0 || $amount > 100000) {
            return new WP_Error('gs_bad_amount', 'Некорректная сумма', array('status' => 400));
        }

        $before = GS_SFX::get_balance($user_id);
        if (!GS_SFX::refund($user_id, $amount)) {
            return new WP_Error('gs_refund_failed', 'Не удалось вернуть на баланс', array('status' => 500));
        }
        $after = GS_SFX::get_balance($user_id);

        $log = (array) get_option(self::OPT_REFUND_LOG, array());
        $log[] = array(
            'time'   => current_time('mysql'),
            'by'     => get_current_user_id(),
            'user'   => $user_id,
            'amount' => $amount,
            'before' => $before,
            'after'  => $after,
            'reason' => mb_substr($reason, 0, 200),
        );
        update_option(self::OPT_REFUND_LOG, array_slice($log, -100), false);

        return rest_ensure_response(array(
            'success' => true,
            'before'  => $before,
            'after'   => $after,
        ));
    }

    public static function handle_voice_song($request) {
        $user_id = get_current_user_id();
        $params  = $request->get_json_params();
        if (!is_array($params)) {
            $params = $request->get_params();
        }

        $voice_id = sanitize_text_field((string) ($params['voice_id'] ?? ''));
        if ($voice_id === '' || !GS_Voice::owns_voice($user_id, $voice_id)) {
            return new WP_Error('gs_no_voice', 'Сначала создайте свой голос', array('status' => 400));
        }

        $fields = array(
            'lyrics' => sanitize_textarea_field((string) ($params['lyrics'] ?? '')),
            'style'  => sanitize_text_field((string) ($params['style'] ?? '')),
            'title'  => sanitize_text_field((string) ($params['title'] ?? '')),
            'prompt' => sanitize_textarea_field((string) ($params['prompt'] ?? '')),
        );

        $cost = GS_Voice::song_cost();
        if ($cost > 0 && !GS_SFX::charge($user_id, $cost)) {
            return new WP_Error('gs_charge_failed', 'На балансе не хватает средств', array('status' => 402));
        }

        // Плата снята до запуска, поэтому любая неожиданность здесь — включая
        // ошибку в самом коде — обязана вернуть деньги, а не оставить человека
        // с белым экраном и списанным балансом.
        try {
            $res = GS_Voice::create_song($voice_id, $fields);
        } catch (Throwable $e) {
            if ($cost > 0) {
                GS_SFX::refund_charge($user_id, $cost);
            }
            error_log('genius-sounds: песня своим голосом — ' . $e->getMessage());
            return new WP_Error('gs_song_failed', 'Не получилось запустить генерацию. Деньги вернулись на баланс.', array('status' => 500));
        }
        if (empty($res['ok'])) {
            if ($cost > 0) {
                GS_SFX::refund_charge($user_id, $cost);
            }
            return new WP_Error('gs_song_failed', $res['message'] !== '' ? $res['message'] : 'Не удалось запустить генерацию', array('status' => 502));
        }

        update_option('gs_voice_song_' . $res['task_id'], array(
            'user_id' => $user_id,
            'cost'    => $cost,
            'at'      => time(),
            'title'   => $fields['title'] !== '' ? $fields['title'] : GS_Lab::music_title($fields['prompt'] !== '' ? $fields['prompt'] : $fields['lyrics']),
            'style'   => $fields['style'],
        ), false);

        return rest_ensure_response(array(
            'task_id' => $res['task_id'],
            'status'  => 'pending',
            'cost'    => $cost,
            'balance' => GS_SFX::get_balance($user_id),
        ));
    }

    public static function handle_voice_song_state($request) {
        $user_id = get_current_user_id();
        $task_id = sanitize_text_field((string) $request['task_id']);
        $meta = get_option('gs_voice_song_' . $task_id);
        if (!is_array($meta) || (int) ($meta['user_id'] ?? 0) !== $user_id) {
            return new WP_Error('gs_foreign_task', 'Задача не найдена', array('status' => 404));
        }

        $state = GS_Voice::song_state($task_id);
        $archive = array();
        if ($state['status'] === 'completed') {
            // Ссылки поставщика живут недолго, поэтому кладём копии в архив
            // сразу — иначе скачать песню завтра уже не выйдет.
            $archive = GS_Voice::remember_songs(
                $user_id,
                $state['files'],
                (string) ($meta['title'] ?? ''),
                (string) ($meta['style'] ?? '')
            );
            delete_option('gs_voice_song_' . $task_id);
        } elseif ($state['status'] === 'failed') {
            if ((float) ($meta['cost'] ?? 0) > 0) {
                GS_SFX::refund_charge($user_id, (float) $meta['cost']);
            }
            delete_option('gs_voice_song_' . $task_id);
        }

        return rest_ensure_response(array(
            'status'  => $state['status'],
            'files'   => $state['files'],
            'message' => $state['message'],
            'balance' => GS_SFX::get_balance($user_id),
            'archive' => $archive,
        ));
    }

    public static function handle_songs_seed($request) {
        $params = $request->get_json_params();
        if (!is_array($params)) {
            $params = $request->get_params();
        }
        $res = GS_Songs::publish(
            get_current_user_id(),
            (string) ($params['url'] ?? ''),
            (string) ($params['title'] ?? ''),
            (string) ($params['author'] ?? ''),
            (string) ($params['style'] ?? '')
        );
        if (empty($res['ok'])) {
            return new WP_Error('gs_seed_failed', $res['message'], array('status' => 400));
        }
        return rest_ensure_response(array(
            'success' => true,
            'message' => $res['message'],
            'total'   => GS_Songs::count(),
            'gallery' => GS_Songs::get_url(),
        ));
    }

    public static function handle_songs_remove($request) {
        $params = $request->get_json_params();
        if (!is_array($params)) {
            $params = $request->get_params();
        }
        $removed = GS_Songs::remove((string) ($params['id'] ?? ''));
        return rest_ensure_response(array('success' => $removed, 'total' => GS_Songs::count()));
    }

    /**
     * Указатель каталога: слаг, название, описание из индекса, число звуков
     * и раздел. Список звуков не отдаём — он тяжёлый и здесь не нужен.
     */
    public static function handle_catalog_index($request) {
        $rows = array();
        foreach (GS_Catalog::load_index() as $row) {
            if (!is_array($row) || empty($row['slug'])) {
                continue;
            }
            $rows[] = array(
                'slug'    => (string) $row['slug'],
                'title'   => (string) ($row['title'] ?? ''),
                'desc'    => (string) ($row['desc'] ?? ''),
                'count'   => (int) ($row['count'] ?? 0),
                'section' => (string) ($row['section'] ?? GS_Sections::guess((string) $row['slug'], (string) ($row['title'] ?? ''))),
            );
        }
        return rest_ensure_response(array('success' => true, 'total' => count($rows), 'items' => $rows));
    }

    /**
     * Правка текстов подборки. Принимаем только поля текста и раздела:
     * список звуков живёт своей жизнью и правится импортом.
     */
    public static function handle_catalog_update($request) {
        $params = $request->get_json_params();
        if (!is_array($params)) {
            $params = $request->get_params();
        }
        $items = isset($params['items']) && is_array($params['items'])
            ? $params['items']
            : array($params);

        $done = 0;
        $missing = array();
        foreach ($items as $item) {
            if (!is_array($item) || empty($item['slug'])) {
                continue;
            }
            $slug = GS_Storage::sanitize_slug((string) $item['slug']);
            if ($slug === '' || !GS_Catalog::get_category($slug)) {
                $missing[] = (string) $item['slug'];
                continue;
            }
            $patch = array();
            foreach (array('title', 'headline', 'description', 'description_2', 'section') as $field) {
                if (isset($item[$field]) && is_string($item[$field])) {
                    $patch[$field] = trim($item[$field]);
                }
            }
            if (!$patch) {
                continue;
            }
            if (GS_Catalog::update_category($slug, $patch)) {
                $done++;
            }
        }
        return rest_ensure_response(array(
            'success' => true,
            'updated' => $done,
            'missing' => $missing,
        ));
    }

    public static function handle_longread($request) {
        $params = $request->get_json_params();
        if (!is_array($params)) {
            $params = $request->get_params();
        }
        if (!empty($params['facts_only'])) {
            return rest_ensure_response(array('success' => true, 'facts' => GS_Longread::facts()));
        }
        return rest_ensure_response(GS_Longread::write(is_array($params['brief'] ?? null) ? $params['brief'] : $params));
    }

    /**
     * Переписать тексты подборок.
     *
     * Работаем небольшими пачками: у хостинга свой предел на время
     * запроса, а каждый текст — обращение к модели на несколько секунд.
     */
    public static function handle_catalog_rewrite($request) {
        $params = $request->get_json_params();
        if (!is_array($params)) {
            $params = $request->get_params();
        }
        $dry = !empty($params['dry']);

        // Принимаем и простой список слагов, и список с подсказками:
        // подсказка — формулировки, которыми тему ищут, и без них текст
        // получается верным, но не о том, что спрашивают.
        $items = array();
        if (isset($params['items']) && is_array($params['items'])) {
            foreach ($params['items'] as $item) {
                if (is_array($item) && !empty($item['slug'])) {
                    $items[] = array(
                        'slug'       => (string) $item['slug'],
                        'hint'       => isset($item['hint']) ? (string) $item['hint'] : '',
                        'with_title' => !empty($item['with_title']),
                    );
                }
            }
        }
        if (isset($params['slugs']) && is_array($params['slugs'])) {
            foreach ($params['slugs'] as $slug) {
                $items[] = array('slug' => (string) $slug, 'hint' => '', 'with_title' => false);
            }
        }
        $items = array_slice($items, 0, 5);

        $results = array();
        foreach ($items as $item) {
            $results[] = GS_Rewrite::category($item['slug'], array(
                'dry'        => $dry,
                'hint'       => $item['hint'],
                'with_title' => $item['with_title'],
            ));
        }
        return rest_ensure_response(array(
            'success' => true,
            'done'    => count(array_filter($results, function ($r) { return !empty($r['ok']); })),
            'results' => $results,
        ));
    }

    /**
     * Разделы: привязка подборок и пересборка указателя.
     */
    public static function handle_catalog_sections($request) {
        $params = $request->get_json_params();
        if (!is_array($params)) {
            $params = $request->get_params();
        }

        $assigned = 0;
        if (!empty($params['assign']) && is_array($params['assign'])) {
            foreach ($params['assign'] as $section => $slugs) {
                if (is_array($slugs)) {
                    $assigned += GS_Sections::assign($slugs, (string) $section);
                }
            }
        }
        $rebuilt = !empty($params['rebuild']) ? GS_Catalog::rebuild_index() : 0;

        // Полная пересборка по файлам подборок — когда указатель разошёлся
        // с ними. Идёт пачками: ответ говорит, с какого места продолжать.
        $from_files = null;
        if (!empty($params['from_files'])) {
            $from_files = GS_Catalog::rebuild_from_files(
                (int) ($params['offset'] ?? 0),
                (int) ($params['limit'] ?? 100)
            );
        }

        return rest_ensure_response(array(
            'success'  => true,
            'assigned' => $assigned,
            'rebuilt'  => $rebuilt,
            'files'    => $from_files,
            'sections' => GS_Sections::overview(),
        ));
    }

    public static function handle_webmaster_probe($request) {
        $params = $request->get_json_params();
        if (!is_array($params)) {
            $params = $request->get_params();
        }
        return rest_ensure_response(GS_Webmaster::probe(
            (string) ($params['path'] ?? '/user/'),
            (string) ($params['method'] ?? 'GET'),
            isset($params['payload']) ? $params['payload'] : null
        ));
    }

    /* ---------------------------------------------------------------------
     * Презентации
     * ------------------------------------------------------------------ */

    /**
     * Шаг 1: структура. Бесплатно — один вызов языковой модели стоит копейки,
     * а брать деньги за то, что человек ещё не видел, нечестно. От перебора
     * защищает счётчик: он же не даёт случайно сжечь лимит поставщика.
     */
    public static function handle_slides_outline(WP_REST_Request $request) {
        $user_id = get_current_user_id();
        $params = $request->get_json_params();
        if (!is_array($params)) {
            $params = $request->get_params();
        }

        $topic = trim(sanitize_textarea_field((string) ($params['topic'] ?? '')));
        $source_key = sanitize_text_field((string) ($params['source_id'] ?? ''));
        $source = '';
        if ($source_key !== '') {
            $stored = get_transient('gs_slides_src_' . $source_key);
            if (!is_array($stored) || (int) ($stored['user_id'] ?? 0) !== $user_id) {
                return new WP_Error('gs_slides_source', 'Загруженный файл не найден — загрузите его заново', array('status' => 400));
            }
            $source = (string) $stored['text'];
        }

        if ($source === '' && mb_strlen($topic) < 5) {
            return new WP_Error('gs_slides_topic', 'Опишите тему или загрузите файл с текстом', array('status' => 400));
        }

        $gate = 'gs_slides_rate_' . $user_id;
        if ((int) get_transient($gate) >= 20) {
            return new WP_Error('gs_slides_rate', 'Слишком много запросов подряд. Подождите немного.', array('status' => 429));
        }
        set_transient($gate, (int) get_transient($gate) + 1, HOUR_IN_SECONDS);

        try {
            $outline = GS_Slides::outline(
                $topic,
                (int) ($params['count'] ?? 8),
                trim(sanitize_text_field((string) ($params['audience'] ?? ''))),
                trim(sanitize_text_field((string) ($params['tone'] ?? ''))),
                $source
            );
        } catch (Throwable $e) {
            error_log('genius-sounds: структура презентации — ' . $e->getMessage());
            return new WP_Error('gs_slides_failed', 'Не получилось собрать структуру. Попробуйте ещё раз.', array('status' => 500));
        }
        if (empty($outline['ok'])) {
            return new WP_Error('gs_slides_failed', $outline['message'], array('status' => 502));
        }

        $draft_id = wp_generate_password(20, false, false);
        set_transient('gs_slides_draft_' . $draft_id, array(
            'user_id' => $user_id,
            'deck'    => $outline['deck'],
        ), 2 * HOUR_IN_SECONDS);

        return rest_ensure_response(array(
            'draft_id' => $draft_id,
            'outline'  => $outline['deck'],
            'base'     => GS_Slides_Page::cost(),
            'pic'      => GS_Slides_Page::pic_cost(),
            'balance'  => GS_SFX::get_balance($user_id),
        ));
    }

    /** Приём файла с текстом. Сам файл не храним — только извлечённый текст. */
    public static function handle_slides_upload(WP_REST_Request $request) {
        $files = $request->get_file_params();
        $file = $files['file'] ?? null;
        if (!is_array($file) || empty($file['tmp_name'])) {
            return new WP_Error('gs_slides_nofile', 'Файл не пришёл', array('status' => 400));
        }
        if (!empty($file['error'])) {
            return new WP_Error('gs_slides_upload', 'Файл не загрузился — попробуйте ещё раз', array('status' => 400));
        }

        $res = GS_Doctext::extract((string) $file['tmp_name'], (string) $file['name']);
        if (empty($res['ok'])) {
            return new WP_Error('gs_slides_parse', $res['message'], array('status' => 400));
        }

        $source_id = wp_generate_password(20, false, false);
        set_transient('gs_slides_src_' . $source_id, array(
            'user_id' => get_current_user_id(),
            'text'    => $res['text'],
        ), 2 * HOUR_IN_SECONDS);

        return rest_ensure_response(array(
            'source_id' => $source_id,
            'name'      => sanitize_file_name((string) $file['name']),
            'chars'     => mb_strlen($res['text']),
        ));
    }

    /** Шаг 2: отрисовка. Здесь и снимаются деньги — за фоны и иллюстрации. */
    public static function handle_slides_render(WP_REST_Request $request) {
        $user_id = get_current_user_id();
        $params = $request->get_json_params();
        if (!is_array($params)) {
            $params = $request->get_params();
        }

        $draft_id = sanitize_text_field((string) ($params['draft_id'] ?? ''));
        $draft = get_transient('gs_slides_draft_' . $draft_id);
        if (!is_array($draft) || (int) ($draft['user_id'] ?? 0) !== $user_id) {
            return new WP_Error('gs_slides_draft', 'Структура устарела — соберите её заново', array('status' => 400));
        }

        $deck = $draft['deck'];
        $style = sanitize_key((string) ($params['style'] ?? 'business'));

        // Номера слайдов приходят от браузера — берём только существующие.
        $illustrations = array();
        foreach ((array) ($params['illustrations'] ?? array()) as $index) {
            $index = (int) $index;
            if (isset($deck['slides'][$index]) && !in_array($index, $illustrations, true)) {
                $illustrations[] = $index;
            }
        }

        // Свои картинки: принимаем только адреса из нашей же папки загрузок.
        // Чужой адрес означал бы, что сервер пойдёт качать произвольный URL
        // по просьбе пользователя, — этого не нужно ни нам, ни ему.
        $own = array();
        foreach ((array) ($params['own'] ?? array()) as $index => $url) {
            $index = (int) $index;
            $url = esc_url_raw(trim((string) $url));
            if (!isset($deck['slides'][$index]) || $url === '') {
                continue;
            }
            if (GS_Lab::local_path($url) === '') {
                return new WP_Error('gs_slides_own', 'Картинку нужно загрузить через форму', array('status' => 400));
            }
            $own[$index] = $url;
        }

        // За свою картинку денег не берём: рисовать нечего.
        $illustrations = array_values(array_diff($illustrations, array_keys($own)));

        $cost = GS_Slides_Page::cost() + count($illustrations) * GS_Slides_Page::pic_cost();
        if ($cost > 0 && !GS_SFX::charge($user_id, $cost)) {
            return new WP_Error('gs_charge_failed', 'На балансе не хватает средств', array('status' => 402));
        }

        // Деньги сняты до запуска, поэтому любая неожиданность ниже —
        // включая ошибку в самом коде — обязана вернуть их на баланс.
        try {
            $tasks = GS_Slides::start_images($deck, $style, $illustrations);
            if (!$tasks) {
                throw new RuntimeException('поставщик не принял ни одной картинки');
            }
        } catch (Throwable $e) {
            if ($cost > 0) {
                GS_SFX::refund_charge($user_id, $cost);
            }
            error_log('genius-sounds: презентация — ' . $e->getMessage());
            return new WP_Error(
                'gs_slides_failed',
                'Не получилось запустить отрисовку: ' . $e->getMessage() . '. Деньги вернулись на баланс.',
                array('status' => 502)
            );
        }

        $task_id = 'slides' . wp_generate_password(20, false, false);
        update_option('gs_slides_' . $task_id, array(
            'user_id' => $user_id,
            'cost'    => $cost,
            'at'      => time(),
            'deck'    => $deck,
            'style'   => $style,
            'tasks'   => $tasks,
            'own'     => $own,
        ), false);
        delete_transient('gs_slides_draft_' . $draft_id);

        return rest_ensure_response(array(
            'task_id' => $task_id,
            'status'  => 'pending',
            'cost'    => $cost,
            'pics'    => count($illustrations),
            'own'     => count($own),
            'balance' => GS_SFX::get_balance($user_id),
        ));
    }

    public static function handle_slides_status(WP_REST_Request $request) {
        $task_id = sanitize_text_field((string) $request['task_id']);
        $key = 'gs_slides_' . $task_id;
        $meta = get_option($key, array());
        if (!is_array($meta) || empty($meta['user_id'])) {
            return new WP_Error('gs_slides_unknown', 'Задача не найдена', array('status' => 404));
        }
        if ((int) $meta['user_id'] !== get_current_user_id()) {
            return new WP_Error('gs_slides_foreign', 'Это чужая задача', array('status' => 403));
        }
        if (!empty($meta['url'])) {
            return rest_ensure_response(array('status' => 'completed', 'url' => $meta['url'], 'title' => $meta['deck']['title']));
        }

        $got = GS_Slides::collect_images((array) $meta['tasks']);

        // Фоны иногда зависают у поставщика. Ждать бесконечно нельзя:
        // по истечении срока собираем что есть, а деньги возвращаем.
        $overdue = (time() - (int) $meta['at']) > GS_Slides::TIMEOUT;
        if (!$got['done'] && !$overdue) {
            return rest_ensure_response(array(
                'status' => 'pending',
                'ready'  => count($got['images']),
                'total'  => count((array) $meta['tasks']),
            ));
        }

        // Свои картинки не проходили через поставщика — подставляем их
        // на те же места, куда встала бы сгенерированная иллюстрация.
        $images = $got['images'];
        foreach ((array) ($meta['own'] ?? array()) as $index => $url) {
            $images['il:' . (int) $index] = (string) $url;
        }

        $built = GS_Slides::build($meta['deck'], $images, (int) $meta['user_id']);
        if (empty($built['ok'])) {
            if (!empty($meta['cost'])) {
                GS_SFX::refund_charge((int) $meta['user_id'], (float) $meta['cost']);
            }
            delete_option($key);
            return new WP_Error('gs_slides_build', $built['message'] . ' Деньги вернулись на баланс.', array('status' => 500));
        }

        if ($overdue && !$got['done'] && !empty($meta['cost'])) {
            // Часть фонов не дождались — честнее вернуть деньги, файл отдать.
            GS_SFX::refund_charge((int) $meta['user_id'], (float) $meta['cost']);
        }

        $meta['url'] = $built['url'];
        update_option($key, $meta, false);
        GS_Slides::remember((int) $meta['user_id'], array(
            'at'     => current_time('mysql'),
            'title'  => $meta['deck']['title'],
            'slides' => count($meta['deck']['slides']) + 1,
            'url'    => $built['url'],
        ));

        return rest_ensure_response(array(
            'status'  => 'completed',
            'url'     => $built['url'],
            'title'   => $meta['deck']['title'],
            'balance' => GS_SFX::get_balance((int) $meta['user_id']),
        ));
    }

    public static function handle_slides_history() {
        return rest_ensure_response(array('items' => GS_Slides::own_decks(get_current_user_id())));
    }

    public static function handle_course_images(WP_REST_Request $request) {
        $images = (array) $request->get_param('images');
        $clean = array();
        foreach (array('hero', 'tools', 'path', 'result', 'photo') as $slug) {
            if (!empty($images[$slug])) {
                $clean[$slug] = (string) $images[$slug];
            }
        }
        GS_Course::set_images($clean);
        GS_Course::ensure_page();
        return rest_ensure_response(array(
            'ok'     => true,
            'images' => get_option(GS_Course::OPT_IMG, array()),
            'url'    => GS_Course::get_url(),
        ));
    }

    public static function handle_lead(WP_REST_Request $request) {
        $res = GS_Leads::accept(array(
            'name'    => (string) $request->get_param('name'),
            'contact' => (string) $request->get_param('contact'),
            'comment' => (string) $request->get_param('comment'),
            'source'  => (string) $request->get_param('source'),
        ));
        if (empty($res['ok'])) {
            return new WP_Error('gs_lead_rejected', $res['message'], array('status' => 400));
        }
        return rest_ensure_response(array('ok' => true, 'message' => $res['message']));
    }

    public static function handle_voice_archive($request) {
        return rest_ensure_response(array(
            'songs'   => GS_Voice::own_songs(get_current_user_id()),
            'gallery' => GS_Songs::get_url(),
        ));
    }

    public static function handle_voice_publish($request) {
        $user_id = get_current_user_id();
        $params  = $request->get_json_params();
        if (!is_array($params)) {
            $params = $request->get_params();
        }

        $song_id = sanitize_text_field((string) ($params['song_id'] ?? ''));
        $song = GS_Voice::find_song($user_id, $song_id);
        if (!$song) {
            return new WP_Error('gs_no_song', 'Песня не найдена в вашем архиве', array('status' => 404));
        }

        $user = get_userdata($user_id);
        $author = trim(sanitize_text_field((string) ($params['author'] ?? '')));
        if ($author === '') {
            $author = $user ? $user->display_name : 'Аноним';
        }

        $res = GS_Songs::publish($user_id, $song['url'], $song['title'], $author, $song['style']);
        if (empty($res['ok'])) {
            return new WP_Error('gs_publish_failed', $res['message'], array('status' => 500));
        }
        GS_Voice::mark_published($user_id, $song_id);

        return rest_ensure_response(array(
            'success' => true,
            'message' => $res['message'],
            'gallery' => GS_Songs::get_url(),
            'songs'   => GS_Voice::own_songs($user_id),
        ));
    }

    public static function handle_lab_callback($request) {
        $token = (string) $request->get_param('token');
        if (!hash_equals(GS_SFX::callback_token(), $token)) {
            return new WP_Error('gs_bad_token', 'Неверный токен колбэка', array('status' => 403));
        }
        // Результат забирает опрос статуса: колбэк нужен агрегатору как подтверждение.
        return rest_ensure_response(array('success' => true));
    }

    /**
     * Проверка запасной озвучки без ожидания отказа основной модели.
     */
    public static function handle_tts_fallback_test($request) {
        $params = $request->get_json_params();
        $text = is_array($params) && !empty($params['text'])
            ? sanitize_textarea_field((string) $params['text'])
            : 'Проверка запасной озвучки на Gemini.';

        $created = GS_Tts_Fallback::create_task($text, '');
        if (empty($created['ok'])) {
            return new WP_Error('gs_fallback_failed', $created['message'], array('status' => 502));
        }

        $user_id = get_current_user_id();
        $cost = class_exists('KIE_TTS_API') ? (float) KIE_TTS_API::calculate_cost($text) : 0.0;
        if (class_exists('KIE_TTS_DB')) {
            $is_telegram = class_exists('KIE_TTS_Auth') && KIE_TTS_Auth::is_telegram_user($user_id);
            KIE_TTS_DB::save_generation($user_id, $created['task_id'], $text, 'gemini-tts', $cost, $is_telegram);
        }

        return rest_ensure_response(array(
            'success' => true,
            'task_id' => $created['task_id'],
            'cost'    => $cost,
        ));
    }

    public static function handle_musicai_probe($request) {
        $params = $request->get_json_params();
        $path   = is_array($params) && !empty($params['path']) ? (string) $params['path'] : '/';
        $method = is_array($params) && !empty($params['method']) ? (string) $params['method'] : 'GET';
        $body   = is_array($params) && isset($params['payload']) && is_array($params['payload'])
            ? $params['payload'] : null;
        return rest_ensure_response(GS_MusicAI::probe($path, $method, $body));
    }

    public static function handle_telegram_whoami($request) {
        $sources = array(
            'озвучка и микросервисы' => array('kie_tts_telegram_bot_token', 'kie_tts_telegram_bot_username'),
            'нейрохаб, PDF, колесо'  => array('kie_neurohub_telegram_bot_token', 'kie_neurohub_telegram_bot_username'),
        );
        $out = array();
        foreach ($sources as $label => $pair) {
            list($token_option, $name_option) = $pair;
            $token = trim((string) get_option($token_option, ''));
            $row = array(
                'настроено имя' => (string) get_option($name_option, ''),
                'токен'         => $token === '' ? 'пусто' : 'задан',
                'бот по токену' => '',
                'ошибка'        => '',
            );
            if ($token !== '') {
                $response = wp_remote_get('https://api.telegram.org/bot' . rawurlencode($token) . '/getMe',
                    array('timeout' => 20));
                if (is_wp_error($response)) {
                    $row['ошибка'] = $response->get_error_message();
                } else {
                    $body = json_decode((string) wp_remote_retrieve_body($response), true);
                    if (is_array($body) && !empty($body['ok'])) {
                        $row['бот по токену'] = (string) ($body['result']['username'] ?? '');
                    } else {
                        $row['ошибка'] = is_array($body)
                            ? (string) ($body['description'] ?? 'Telegram отклонил токен')
                            : 'некорректный ответ Telegram';
                    }
                }
            }
            $out[$label] = $row;
        }
        return rest_ensure_response($out);
    }

    public static function handle_ytaudio_probe($request) {
        $params = $request->get_json_params();
        $url = is_array($params) && !empty($params['url']) ? esc_url_raw((string) $params['url']) : '';
        $format = is_array($params) && !empty($params['format']) ? (string) $params['format'] : 'mp3';
        if ($url === '') {
            return new WP_Error('gs_no_url', 'Нужна ссылка', array('status' => 400));
        }
        if (!class_exists('KIE_TTS_API')) {
            return new WP_Error('gs_no_service', 'Служба извлечения недоступна', array('status' => 503));
        }
        return rest_ensure_response(array(
            'raw' => KIE_TTS_API::create_youtube_audio_task($url, $format),
        ));
    }

    public static function handle_jobs_probe($request) {
        $params = $request->get_json_params();
        $model = is_array($params) && !empty($params['model']) ? (string) $params['model'] : '';
        $input = is_array($params) && !empty($params['input']) && is_array($params['input']) ? $params['input'] : array();
        $info  = is_array($params) && !empty($params['task']) ? (string) $params['task'] : '';
        $url   = is_array($params) && !empty($params['url']) ? esc_url_raw((string) $params['url']) : '';
        if ($url !== '' && !empty($params['duration'])) {
            // Диагностика расчёта цены: сколько секунд мы видим по ссылке.
            return rest_ensure_response(array(
                'url'     => $url,
                'seconds' => GS_Lab::remote_duration($url),
            ));
        }
        if ($url !== '') {
            $payload = is_array($params) && !empty($params['payload']) ? (array) $params['payload'] : array();
            $method  = is_array($params) && !empty($params['method']) ? (string) $params['method'] : 'POST';
            return rest_ensure_response(GS_Api::probe_raw($url, $payload, $method));
        }
        if ($info !== '') {
            return rest_ensure_response(GS_Api::probe_info($info));
        }
        if ($model === '') {
            return new WP_Error('gs_no_model', 'Нужен model', array('status' => 400));
        }
        return rest_ensure_response(GS_Api::probe_create($model, $input));
    }

    public static function handle_provider_test($request) {
        $params = $request->get_json_params();
        $url = isset($params['url']) ? esc_url_raw((string) $params['url']) : '';
        $variant = isset($params['variant']) ? (int) $params['variant'] : 1;
        if ($url === '') {
            return new WP_Error('gs_no_url', 'Нужен адрес файла', array('status' => 400));
        }
        return rest_ensure_response(GS_Lab::vocal_probe($url, $variant));
    }

    public static function handle_lab_credits($request) {
        $task = (string) $request->get_param('task');
        if ($task !== '') {
            return rest_ensure_response(GS_Lab::task_credits($task));
        }
        return rest_ensure_response(GS_Lab::provider_credits());
    }

    public static function handle_install_menu($request) {
        $params = $request->get_json_params();
        $menu_id = (is_array($params) && !empty($params['menu_id'])) ? (int) $params['menu_id'] : 0;
        $reset   = is_array($params) && !empty($params['reset']);
        return rest_ensure_response(array(
            'success' => true,
            'result'  => GS_Links::install_menu_items($menu_id, $reset),
        ));
    }

    public static function handle_showcase_add($request) {
        $params = $request->get_json_params();
        if (!is_array($params)) {
            $params = $request->get_params();
        }

        $url = isset($params['url']) ? esc_url_raw((string) $params['url']) : '';
        if ($url === '') {
            return new WP_Error('gs_missing_url', 'Нужна ссылка на аудио', array('status' => 400));
        }

        GS_SFX::add_to_showcase(array(
            'title'    => isset($params['title']) ? sanitize_text_field((string) $params['title']) : 'Сгенерированный звук',
            'prompt'   => isset($params['prompt']) ? sanitize_textarea_field((string) $params['prompt']) : '',
            'url'      => $url,
            'model'    => isset($params['model']) ? sanitize_text_field((string) $params['model']) : 'V5',
            'mode'     => isset($params['mode']) ? sanitize_key((string) $params['mode']) : GS_SFX::MODE_SFX,
            'duration' => isset($params['duration']) ? (float) $params['duration'] : 0,
        ));

        return rest_ensure_response(array(
            'success' => true,
            'page'    => GS_Pages::get_showcase_url(),
            'items'   => count(GS_SFX::get_showcase()),
        ));
    }
}
