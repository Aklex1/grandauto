<?php
/**
 * Подтверждение прав в вебмастерах и мгновенная отправка страниц в индекс.
 *
 * Обход поисковика приходит сам по себе и с задержкой в недели: свежая статья
 * висит без трафика ровно столько, сколько робот до неё не доходит. IndexNow
 * решает это в одну сторону — сайт сам сообщает Яндексу и Bing, что адрес
 * появился или изменился.
 *
 *   /<ключ>.txt   — файл подтверждения владения, отдаём на лету
 */

if (!defined('ABSPATH')) {
    exit;
}

class GS_Index {

    const OPT_YANDEX  = 'gs_verify_yandex';
    const OPT_GOOGLE  = 'gs_verify_google';
    const OPT_KEY     = 'gs_indexnow_key';
    const OPT_ENABLED = 'gs_indexnow_enabled';
    const OPT_LOG     = 'gs_indexnow_log';
    const OPT_QUEUE   = 'gs_indexnow_queue';

    const HOOK_DRAIN  = 'gs_indexnow_drain';
    const BATCH       = 100;
    const PAUSE       = 300;

    /**
     * По протоколу любой участник делится списком с остальными, поэтому
     * достаточно достучаться до одного. Первым ставим Яндекс: сайт русский,
     * и общий вход api.indexnow.org отвечает этому серверу отказом по частоте.
     */
    const ENDPOINTS = array(
        'https://yandex.com/indexnow',
        'https://www.bing.com/indexnow',
        'https://api.indexnow.org/indexnow',
    );
    const LOG_LIMIT = 20;

    public static function boot() {
        add_action('wp_head', array(__CLASS__, 'print_verification'), 1);
        add_action('init', array(__CLASS__, 'add_rewrite_rules'), 5);
        add_filter('query_vars', array(__CLASS__, 'add_query_vars'));
        add_action('template_redirect', array(__CLASS__, 'maybe_render_key'), 0);
        add_action('transition_post_status', array(__CLASS__, 'on_transition'), 10, 3);
        add_action('admin_post_gs_indexnow_all', array(__CLASS__, 'handle_submit_all'));
        add_action(self::HOOK_DRAIN, array(__CLASS__, 'drain'));
    }

    /** Разовая отправка всего каталога страниц по кнопке в админке. */
    public static function handle_submit_all() {
        if (!current_user_can('manage_options')) {
            wp_die('Недостаточно прав');
        }
        check_admin_referer('gs_indexnow_all');
        self::submit_everything();
        wp_safe_redirect(admin_url('admin.php?page=genius-sounds') . '#gs-index');
        exit;
    }

    /* ---------------------------------------------------------------------
     * Подтверждение прав
     * ------------------------------------------------------------------ */

    /**
     * Вебмастеры просят мета-тег в <head> главной. Владелец вставляет либо
     * голый код, либо целиком тег — принимаем оба вида.
     */
    public static function clean_code($value) {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }
        if (preg_match('~content=["\']([^"\']+)["\']~i', $value, $m)) {
            $value = $m[1];
        }
        return sanitize_text_field($value);
    }

    public static function print_verification() {
        if (!is_front_page()) {
            return;
        }
        $tags = array(
            'yandex-verification'      => self::clean_code(get_option(self::OPT_YANDEX, '')),
            'google-site-verification' => self::clean_code(get_option(self::OPT_GOOGLE, '')),
        );
        foreach ($tags as $name => $code) {
            if ($code === '') {
                continue;
            }
            echo '<meta name="' . esc_attr($name) . '" content="' . esc_attr($code) . '" />' . "\n";
        }
    }

    /* ---------------------------------------------------------------------
     * Ключ IndexNow
     * ------------------------------------------------------------------ */

    /**
     * Ключ заводится один раз и дальше не меняется: он же имя файла,
     * по которому поисковик проверяет, что отправитель владеет сайтом.
     */
    public static function key() {
        $key = trim((string) get_option(self::OPT_KEY, ''));
        if (!preg_match('~^[a-f0-9]{32}$~', $key)) {
            $key = md5(wp_generate_password(32, false) . home_url('/') . microtime(true));
            update_option(self::OPT_KEY, $key, false);
        }
        return $key;
    }

    public static function key_url() {
        return home_url('/' . self::key() . '.txt');
    }

    public static function enabled() {
        return get_option(self::OPT_ENABLED, '1') !== '0';
    }

    public static function add_rewrite_rules() {
        add_rewrite_rule('^([a-f0-9]{32})\.txt$', 'index.php?gs_indexnow_key=$matches[1]', 'top');
    }

    public static function add_query_vars($vars) {
        $vars[] = 'gs_indexnow_key';
        return $vars;
    }

    public static function maybe_render_key() {
        $asked = (string) get_query_var('gs_indexnow_key');
        if ($asked === '') {
            return;
        }
        if (!hash_equals(self::key(), $asked)) {
            // Иначе адрес с чужим ключом ответил бы главной страницей с кодом 200:
            // поисковик получил бы бесконечный набор дублей.
            global $wp_query;
            if ($wp_query instanceof WP_Query) {
                $wp_query->set_404();
            }
            status_header(404);
            nocache_headers();
            return;
        }
        status_header(200);
        header('Content-Type: text/plain; charset=utf-8');
        header('X-Robots-Tag: noindex');
        echo self::key();
        exit;
    }

    /* ---------------------------------------------------------------------
     * Отправка адресов
     * ------------------------------------------------------------------ */

    /**
     * Шлём только то, что действительно стало публичным: черновики,
     * автосохранения и служебные типы записей поисковику не нужны.
     */
    public static function on_transition($new_status, $old_status, $post) {
        if ($new_status !== 'publish' || !($post instanceof WP_Post)) {
            return;
        }
        if (wp_is_post_revision($post) || wp_is_post_autosave($post)) {
            return;
        }
        $type = get_post_type_object($post->post_type);
        if (!$type || empty($type->public)) {
            return;
        }
        $url = get_permalink($post);
        if (!$url) {
            return;
        }
        self::submit(array($url));
    }

    /**
     * @param array<int,string> $urls
     * @return array{sent:int,code:int,error:string}
     */
    public static function submit($urls) {
        $result = array('sent' => 0, 'code' => 0, 'error' => '', 'host' => '');
        if (!self::enabled()) {
            $result['error'] = 'отправка выключена';
            return $result;
        }

        $host  = wp_parse_url(home_url('/'), PHP_URL_HOST);
        $clean = array();
        foreach ((array) $urls as $url) {
            $url = esc_url_raw(trim((string) $url));
            if ($url === '' || wp_parse_url($url, PHP_URL_HOST) !== $host) {
                continue;
            }
            $clean[$url] = true;
        }
        $clean = array_keys($clean);
        if (!$clean) {
            $result['error'] = 'нет адресов этого сайта';
            return $result;
        }
        $clean = array_slice($clean, 0, self::BATCH);

        $body = wp_json_encode(array(
            'host'        => $host,
            'key'         => self::key(),
            'keyLocation' => self::key_url(),
            'urlList'     => $clean,
        ));

        foreach (self::ENDPOINTS as $endpoint) {
            $response = wp_remote_post($endpoint, array(
                'timeout' => 20,
                'headers' => array('Content-Type' => 'application/json; charset=utf-8'),
                'body'    => $body,
            ));

            if (is_wp_error($response)) {
                $result['error'] = $response->get_error_message();
                $result['code']  = 0;
                continue;
            }

            $result['code']  = (int) wp_remote_retrieve_response_code($response);
            $result['error'] = '';
            if ($result['code'] < 400) {
                $result['sent'] = count($clean);
                $result['host'] = $endpoint;
                break;
            }

            $result['sent']  = 0;
            $result['host']  = $endpoint;
            $result['error'] = trim(wp_remote_retrieve_body($response));
            if ($result['code'] !== 429) {
                break; // отказ по сути запроса — другие входы ответят так же
            }
        }

        self::log($clean, $result);
        return $result;
    }

    private static function log($urls, $result) {
        $log   = (array) get_option(self::OPT_LOG, array());
        $log[] = array(
            'time'  => current_time('mysql'),
            'count' => count($urls),
            'first' => (string) reset($urls),
            'code'  => $result['code'],
            'host'  => (string) $result['host'],
            'error' => mb_substr((string) $result['error'], 0, 200),
        );
        if (count($log) > self::LOG_LIMIT) {
            $log = array_slice($log, -self::LOG_LIMIT);
        }
        update_option(self::OPT_LOG, $log, false);
    }

    public static function log_rows() {
        return array_reverse((array) get_option(self::OPT_LOG, array()));
    }

    /**
     * Разовая отправка всего, что уже опубликовано: записи, страницы и
     * посадочные микросервисов. Нужна после подключения ключа.
     *
     * Сразу всё отдавать нельзя: на пачке в 277 адресов сервис отвечает
     * TooManyRequests и не берёт ничего. Поэтому складываем адреса в очередь
     * и вычерпываем по {@see BATCH} штук с паузой.
     */
    public static function submit_everything() {
        $urls = array(home_url('/'));

        $posts = get_posts(array(
            'post_type'        => array('post', 'page'),
            'post_status'      => 'publish',
            'numberposts'      => 2000,
            'fields'           => 'ids',
            'suppress_filters' => true,
        ));
        foreach ($posts as $id) {
            $url = get_permalink($id);
            if ($url) {
                $urls[] = $url;
            }
        }

        // Каталог здесь был пропущен, а это большая часть сайта: тысяча
        // подборок и разделы. В индексе они есть (Вебмастер видит 1175
        // страниц против 359 в карте), но попали туда по ссылкам и с
        // задержкой в месяцы — быстрее сказать о них самим.
        if (class_exists('GS_Catalog')) {
            $urls[] = GS_Catalog::base_url();
            foreach (GS_Catalog::load_index() as $row) {
                if (!empty($row['slug'])) {
                    $urls[] = GS_Catalog::category_url((string) $row['slug']);
                }
            }
        }
        if (class_exists('GS_Sections')) {
            foreach (GS_Sections::overview() as $section) {
                $urls[] = GS_Sections::url($section['slug']);
            }
        }

        return self::enqueue($urls);
    }

    /**
     * @param array<int,string> $urls
     * @return array{queued:int}
     */
    public static function enqueue($urls) {
        $queue = (array) get_option(self::OPT_QUEUE, array());
        foreach ((array) $urls as $url) {
            $url = esc_url_raw(trim((string) $url));
            if ($url !== '') {
                $queue[] = $url;
            }
        }
        $queue = array_values(array_unique($queue));
        update_option(self::OPT_QUEUE, $queue, false);
        self::schedule(1);
        return array('queued' => count($queue));
    }

    public static function queue_size() {
        return count((array) get_option(self::OPT_QUEUE, array()));
    }

    private static function schedule($delay) {
        if (!wp_next_scheduled(self::HOOK_DRAIN)) {
            wp_schedule_single_event(time() + (int) $delay, self::HOOK_DRAIN);
        }
    }

    /**
     * Одна порция из очереди. Отказ по превышению частоты — не потеря:
     * адреса возвращаются на место и уходят следующей попыткой.
     */
    public static function drain() {
        $queue = (array) get_option(self::OPT_QUEUE, array());
        if (!$queue) {
            return;
        }
        $batch = array_slice($queue, 0, self::BATCH);
        $rest  = array_slice($queue, self::BATCH);

        $result = self::submit($batch);
        if ($result['sent'] === 0 && $result['code'] === 429) {
            $rest = array_merge($batch, $rest);
        }

        update_option(self::OPT_QUEUE, array_values($rest), false);
        if ($rest) {
            self::schedule(self::PAUSE);
        }
    }
}
