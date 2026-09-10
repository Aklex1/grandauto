<?php
/**
 * Импортёр звуков: парсит категорию источника на стороне сервера,
 * складывает mp3 в uploads и дописывает catalog.json.
 *
 * Работает пачками: очередь слагов в опции + тик по WP-Cron (или вручную из админки),
 * чтобы не упираться в max_execution_time на шаред-хостинге.
 */

if (!defined('ABSPATH')) {
    exit;
}

class GS_Importer {

    const SOURCE_BASE   = 'https://zvukogram.com';
    const OPT_QUEUE     = 'gs_import_queue';
    const OPT_STATE     = 'gs_import_state';
    const CRON_HOOK     = 'gs_import_tick';
    const LOCK_KEY      = 'gs_import_lock';

    const OPT_MAX_FILE_MB = 'gs_import_max_file_mb';
    const DEFAULT_MAX_FILE_MB = 8; // музыкальные категории тянут вверх занимаемое место
    const TICK_BUDGET    = 20;       // секунд на один тик

    public static function boot() {
        add_action(self::CRON_HOOK, array(__CLASS__, 'run_tick'));
    }

    /**
     * Предельный размер одного файла: длинные музыкальные треки занимают
     * в разы больше, чем короткие эффекты, и быстро съедают диск.
     */
    public static function max_file_bytes() {
        $mb = (float) get_option(self::OPT_MAX_FILE_MB, self::DEFAULT_MAX_FILE_MB);
        if ($mb <= 0) {
            $mb = self::DEFAULT_MAX_FILE_MB;
        }
        return (int) round($mb * 1024 * 1024);
    }

    /* ---------------------------------------------------------------------
     * Очередь и расписание
     * ------------------------------------------------------------------ */

    public static function get_state() {
        $state = get_option(self::OPT_STATE, array());
        if (!is_array($state)) {
            $state = array();
        }
        return array_merge(array(
            'running'      => false,
            'limit'        => 20,
            'force'        => false,
            'done'         => 0,
            'total'        => 0,
            'imported'     => 0,
            'failed'       => 0,
            'current'      => '',
            'last_message' => '',
            'started_at'   => '',
            'updated_at'   => '',
        ), $state);
    }

    public static function set_state(array $patch) {
        $state = array_merge(self::get_state(), $patch);
        $state['updated_at'] = current_time('mysql');
        update_option(self::OPT_STATE, $state, false);
        return $state;
    }

    /**
     * @param string[] $slugs
     */
    public static function start(array $slugs, $limit = 20, $force = false) {
        $queue = array();
        foreach ($slugs as $slug) {
            $slug = GS_Storage::sanitize_slug($slug);
            if ($slug !== '' && !in_array($slug, $queue, true)) {
                $queue[] = $slug;
            }
        }
        update_option(self::OPT_QUEUE, $queue, false);
        self::set_state(array(
            'running'      => !empty($queue),
            'limit'        => max(1, min(100, (int) $limit)),
            'force'        => (bool) $force,
            'done'         => 0,
            'total'        => count($queue),
            'imported'     => 0,
            'failed'       => 0,
            'current'      => '',
            'last_message' => empty($queue) ? 'Очередь пуста' : 'Очередь поставлена',
            'started_at'   => current_time('mysql'),
        ));
        self::schedule_next();
        return count($queue);
    }

    public static function stop() {
        update_option(self::OPT_QUEUE, array(), false);
        self::set_state(array('running' => false, 'current' => '', 'last_message' => 'Импорт остановлен'));
        self::clear_schedule();
    }

    public static function schedule_next($delay = 5) {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_single_event(time() + max(1, (int) $delay), self::CRON_HOOK);
        }
    }

    public static function clear_schedule() {
        $ts = wp_next_scheduled(self::CRON_HOOK);
        while ($ts) {
            wp_unschedule_event($ts, self::CRON_HOOK);
            $ts = wp_next_scheduled(self::CRON_HOOK);
        }
    }

    /**
     * Один тик: берём категории из очереди, пока не кончится бюджет времени.
     *
     * @return array сводка тика
     */
    public static function run_tick() {
        if (get_transient(self::LOCK_KEY)) {
            return array('skipped' => true, 'reason' => 'locked');
        }
        set_transient(self::LOCK_KEY, 1, 120);

        $started = microtime(true);
        $processed = array();

        try {
            $state = self::get_state();
            if (empty($state['running'])) {
                return array('skipped' => true, 'reason' => 'not_running');
            }

            while ((microtime(true) - $started) < self::TICK_BUDGET) {
                $queue = get_option(self::OPT_QUEUE, array());
                if (!is_array($queue) || empty($queue)) {
                    self::set_state(array('running' => false, 'current' => '', 'last_message' => 'Импорт завершён'));
                    break;
                }

                $slug = array_shift($queue);
                update_option(self::OPT_QUEUE, $queue, false);
                self::set_state(array('current' => $slug));

                $result = self::import_category($slug, (int) $state['limit'], (bool) $state['force']);
                $processed[] = $result;

                $state = self::get_state();
                self::set_state(array(
                    'done'         => (int) $state['done'] + 1,
                    'imported'     => (int) $state['imported'] + (int) ($result['imported'] ?? 0),
                    'failed'       => (int) $state['failed'] + (empty($result['ok']) ? 1 : 0),
                    'last_message' => (string) ($result['message'] ?? ''),
                ));
            }

            $state = self::get_state();
            if (!empty($state['running'])) {
                self::schedule_next(5);
            }
        } finally {
            delete_transient(self::LOCK_KEY);
        }

        return array('processed' => $processed);
    }

    /* ---------------------------------------------------------------------
     * Парсинг источника
     * ------------------------------------------------------------------ */

    private static function request_args($referer = '') {
        return array(
            'timeout'     => 45,
            'redirection' => 3,
            'headers'     => array(
                'User-Agent'      => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36',
                'Accept-Language' => 'ru-RU,ru;q=0.9',
                'Referer'         => $referer !== '' ? $referer : self::SOURCE_BASE . '/',
            ),
        );
    }

    /**
     * HTML страницы категории источника.
     *
     * @return array{ok:bool,message:string,html:string}
     */
    private static function fetch_category_html($slug) {
        $slug = GS_Storage::sanitize_slug($slug);
        if ($slug === '') {
            return array('ok' => false, 'message' => 'Пустой слаг', 'html' => '');
        }

        $url = self::SOURCE_BASE . '/category/' . rawurlencode($slug) . '/';
        $response = wp_remote_get($url, self::request_args());

        if (is_wp_error($response)) {
            return array('ok' => false, 'message' => 'Ошибка запроса: ' . $response->get_error_message(), 'html' => '');
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            return array('ok' => false, 'message' => 'HTTP ' . $code . ' на ' . $url, 'html' => '');
        }

        return array('ok' => true, 'message' => '', 'html' => (string) wp_remote_retrieve_body($response));
    }

    /**
     * Список треков категории источника.
     *
     * Крупные разделы (muz, avto, predmetyi…) — витрины подкатегорий, треков на них нет.
     * Для таких собираем подборку из дочерних категорий, иначе самые посещаемые
     * страницы каталога остались бы пустыми.
     *
     * @return array{ok:bool,message:string,tracks:array}
     */
    public static function fetch_tracks($slug, $limit = 15) {
        $page = self::fetch_category_html($slug);
        if (empty($page['ok'])) {
            return array('ok' => false, 'message' => $page['message'], 'tracks' => array());
        }

        $tracks = self::parse_tracks($page['html']);
        if (!empty($tracks)) {
            return array('ok' => true, 'message' => '', 'tracks' => $tracks);
        }

        $tracks = self::collect_from_subcategories($slug, $page['html'], $limit);
        return array(
            'ok'      => true,
            'message' => empty($tracks) ? 'нет треков и подкатегорий' : '',
            'tracks'  => $tracks,
        );
    }

    /**
     * Ссылки на дочерние категории со страницы-витрины.
     *
     * @return string[]
     */
    public static function parse_subcategories($html, $self_slug = '') {
        $slugs = array();
        if (!preg_match_all('~href="/category/([a-z0-9-]+)/"~i', (string) $html, $m)) {
            return $slugs;
        }
        foreach ($m[1] as $found) {
            $found = GS_Storage::sanitize_slug($found);
            if ($found === '' || $found === $self_slug || in_array($found, $slugs, true)) {
                continue;
            }
            $slugs[] = $found;
        }
        return $slugs;
    }

    /**
     * Берём понемногу из каждой дочерней категории, пока не наберём лимит.
     *
     * @return array
     */
    private static function collect_from_subcategories($slug, $html, $limit) {
        $subs = self::parse_subcategories($html, $slug);
        if (empty($subs)) {
            return array();
        }

        $limit = max(1, (int) $limit);
        $subs = array_slice($subs, 0, 8);
        $per_sub = max(2, (int) ceil($limit / max(1, min(count($subs), 8))));

        $tracks = array();
        $seen = array();

        foreach ($subs as $sub) {
            if (count($tracks) >= $limit) {
                break;
            }
            $sub_page = self::fetch_category_html($sub);
            if (empty($sub_page['ok'])) {
                continue;
            }
            $taken = 0;
            foreach (self::parse_tracks($sub_page['html']) as $track) {
                if ($taken >= $per_sub || count($tracks) >= $limit) {
                    break;
                }
                $key = $track['source_id'] !== '' ? $track['source_id'] : $track['path'];
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $tracks[] = $track;
                $taken++;
            }
            usleep(150000);
        }

        return $tracks;
    }

    /**
     * Достаём карточки треков: путь к файлу, длительность и название.
     *
     * @return array<int,array{path:string,duration:int,title:string,source_id:string}>
     */
    public static function parse_tracks($html) {
        $tracks = array();
        if (!preg_match_all('~<div class="onetrack[^"]*"([^>]*)>(.*?)(?=<div class="onetrack|\z)~su', $html, $blocks, PREG_SET_ORDER)) {
            return $tracks;
        }

        foreach ($blocks as $block) {
            $attrs = $block[1];
            $body  = $block[2];

            if (!preg_match('~data-track="([^"]+)"~', $attrs, $m_track)) {
                continue;
            }
            $path = html_entity_decode($m_track[1], ENT_QUOTES, 'UTF-8');
            if (stripos($path, '.mp3') === false) {
                continue;
            }

            $duration = 0;
            if (preg_match('~data-duration="(\d+)"~', $attrs, $m_dur)) {
                $duration = (int) $m_dur[1];
            }
            $source_id = '';
            if (preg_match('~data-id="(\d+)"~', $attrs, $m_id)) {
                $source_id = $m_id[1];
            }

            $title = '';
            if (preg_match('~class="waveTitle"[^>]*?data-full="([^"]*)"~s', $body, $m_title)) {
                $title = $m_title[1];
            } elseif (preg_match('~class="waveTitle"[^>]*>([^<]+)<~s', $body, $m_title2)) {
                $title = $m_title2[1];
            }
            $title = self::clean_title($title);
            if ($title === '') {
                $title = self::title_from_path($path);
            }

            $tracks[] = array(
                'path'      => $path,
                'duration'  => $duration,
                'title'     => $title,
                'source_id' => $source_id,
            );
        }

        return $tracks;
    }

    /**
     * В источнике названия приходят с двойным экранированием (&amp;quot;).
     */
    private static function clean_title($title) {
        $title = (string) $title;
        for ($i = 0; $i < 2; $i++) {
            $title = html_entity_decode($title, ENT_QUOTES, 'UTF-8');
        }
        $title = preg_replace('~\s+~u', ' ', $title);
        $title = trim((string) $title);
        if (mb_strlen($title) > 160) {
            $title = mb_substr($title, 0, 157) . '…';
        }
        return $title;
    }

    private static function title_from_path($path) {
        $base = (string) pathinfo($path, PATHINFO_FILENAME);
        $base = str_replace('-', ' ', $base);
        return trim(ucfirst($base));
    }

    /* ---------------------------------------------------------------------
     * Импорт категории
     * ------------------------------------------------------------------ */

    /**
     * @return array{ok:bool,slug:string,imported:int,skipped:int,message:string}
     */
    public static function import_category($slug, $limit = 20, $force = false) {
        $slug = GS_Storage::sanitize_slug($slug);
        $result = array('ok' => false, 'slug' => $slug, 'imported' => 0, 'skipped' => 0, 'message' => '');

        if ($slug === '') {
            $result['message'] = 'Пустой слаг';
            return $result;
        }

        $category = GS_Catalog::get_category($slug);
        $existing = ($category && !empty($category['sounds']) && is_array($category['sounds'])) ? $category['sounds'] : array();

        if (!$force && count($existing) > 0) {
            $result['ok'] = true;
            $result['skipped'] = count($existing);
            $result['message'] = $slug . ': уже заполнена (' . count($existing) . ')';
            return $result;
        }

        $fetched = self::fetch_tracks($slug, $limit);
        if (empty($fetched['ok'])) {
            $result['message'] = $slug . ': ' . $fetched['message'];
            return $result;
        }
        if (empty($fetched['tracks'])) {
            $result['ok'] = true;
            $result['message'] = $slug . ': треков не найдено';
            return $result;
        }

        GS_Storage::ensure_dirs();
        $dir = GS_Storage::files_dir() . '/' . $slug;
        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
        }

        // Уже сохранённые файлы не качаем повторно.
        $by_file = array();
        foreach ($existing as $sound) {
            if (!empty($sound['file'])) {
                $by_file[basename((string) $sound['file'])] = $sound;
            }
        }

        $sounds = array();
        $seen = array();
        $limit = max(1, min(100, (int) $limit));
        $referer = self::SOURCE_BASE . '/category/' . rawurlencode($slug) . '/';

        foreach ($fetched['tracks'] as $track) {
            if (count($sounds) >= $limit) {
                break;
            }

            $filename = self::build_filename($track);

            // Один и тот же трек попадается на странице дважды (блок «популярное» + общий список).
            if (isset($seen[$filename])) {
                continue;
            }
            $seen[$filename] = true;

            $target = $dir . '/' . $filename;

            if (isset($by_file[$filename]) && file_exists($target)) {
                $sounds[] = $by_file[$filename];
                $result['skipped']++;
                continue;
            }

            if (!file_exists($target)) {
                $saved = self::download_file(self::SOURCE_BASE . $track['path'], $target, $referer);
                if (!$saved['ok']) {
                    continue;
                }
                usleep(200000); // не долбим источник
            }

            $size = (int) @filesize($target);
            if ($size <= 0) {
                @unlink($target);
                continue;
            }

            $sounds[] = array(
                'title'    => $track['title'],
                'file'     => $slug . '/' . $filename,
                'duration' => (int) $track['duration'],
                'size'     => $size,
            );
            $result['imported']++;
        }

        if (empty($sounds)) {
            $result['message'] = $slug . ': не удалось скачать ни одного файла';
            return $result;
        }

        GS_Catalog::update_category($slug, array(
            'sounds'      => $sounds,
            'imported_at' => current_time('mysql'),
        ));

        $result['ok'] = true;
        $result['message'] = $slug . ': +' . $result['imported'] . ' (всего ' . count($sounds) . ')';
        return $result;
    }

    /**
     * Имя файла внутри категории.
     *
     * В одной категории встречаются одинаковые basename из разных папок источника
     * (например, два разных «the-soldiers-are-marching.mp3»), поэтому добавляем
     * стабильный префикс из id трека — иначе файлы затирают друг друга.
     */
    private static function build_filename(array $track) {
        $name = GS_Storage::sanitize_filename(basename($track['path']));
        $id = preg_replace('~[^0-9a-z]~', '', strtolower((string) $track['source_id']));
        if ($id === '') {
            $id = substr(md5($track['path']), 0, 6);
        }
        return $id . '-' . $name;
    }

    /**
     * Скачивание одного файла с проверкой типа и размера.
     *
     * @return array{ok:bool,message:string}
     */
    public static function download_file($url, $target, $referer = '') {
        $args = self::request_args($referer);
        $args['timeout'] = 60;

        $response = wp_remote_get($url, $args);
        if (is_wp_error($response)) {
            return array('ok' => false, 'message' => $response->get_error_message());
        }
        if ((int) wp_remote_retrieve_response_code($response) !== 200) {
            return array('ok' => false, 'message' => 'HTTP ' . wp_remote_retrieve_response_code($response));
        }

        $type = strtolower((string) wp_remote_retrieve_header($response, 'content-type'));
        if ($type !== '' && strpos($type, 'audio') === false && strpos($type, 'octet-stream') === false) {
            return array('ok' => false, 'message' => 'Не аудио: ' . $type);
        }

        $body = wp_remote_retrieve_body($response);
        $len = strlen((string) $body);
        if ($len < 1024) {
            return array('ok' => false, 'message' => 'Слишком маленький файл');
        }
        if ($len > self::max_file_bytes()) {
            return array('ok' => false, 'message' => 'Слишком большой файл');
        }

        if (!GS_Storage::atomic_put($target, $body)) {
            return array('ok' => false, 'message' => 'Не удалось записать файл');
        }
        return array('ok' => true, 'message' => '');
    }

    /**
     * Слаги категорий, которые ещё не наполнены.
     *
     * @return string[]
     */
    public static function pending_slugs($limit = 0) {
        $slugs = array();
        foreach (GS_Catalog::load()['categories'] as $cat) {
            if (empty($cat['slug'])) {
                continue;
            }
            if (GS_Catalog::count_sounds($cat) === 0) {
                $slugs[] = $cat['slug'];
            }
        }
        if ($limit > 0) {
            $slugs = array_slice($slugs, 0, (int) $limit);
        }
        return $slugs;
    }
}
