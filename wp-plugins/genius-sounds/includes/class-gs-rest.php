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

        register_rest_route(self::NS, '/showcase', array(
            'methods'             => 'POST',
            'callback'            => array(__CLASS__, 'handle_showcase_add'),
            'permission_callback' => array(__CLASS__, 'perm_admin'),
        ));
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
            'callback_url' => rest_url(self::NS . '/sfx/callback'),
        ));

        if (empty($created['ok'])) {
            return new WP_Error('gs_kie_error', $created['message'] ?: 'Сервис генерации не принял задачу', array('status' => 502));
        }

        $task_id = $created['task_id'];

        if (!GS_SFX::charge($user_id, $cost)) {
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
        if (is_array($meta) && !empty($meta['user_id'])
            && (int) $meta['user_id'] !== (int) $user_id
            && !current_user_can('manage_options')) {
            return new WP_Error('gs_forbidden', 'Задача принадлежит другому пользователю', array('status' => 403));
        }

        // Готовый результат уже мог быть сохранён колбэком.
        $stored = self::get_stored_generation($task_id);
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
            GS_SFX::refund((int) $meta['user_id'], (float) $meta['cost']);
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
