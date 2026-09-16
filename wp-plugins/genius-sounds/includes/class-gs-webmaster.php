<?php
/**
 * Данные Яндекс.Вебмастера внутри админки.
 *
 * Замечания Вебмастера копировать руками неудобно и легко упустить, поэтому
 * сайт забирает их сам. Токен лежит в настройках и наружу не выходит: все
 * запросы уходят с сервера — так же, как ключ генерации.
 *
 * Токен выдаётся на полгода и отзывается в настройках аккаунта Яндекса,
 * права нужны только на Вебмастер: webmaster:hostinfo и webmaster:verify.
 */

if (!defined('ABSPATH')) {
    exit;
}

class GS_Webmaster {

    const API        = 'https://api.webmaster.yandex.net/v4';
    const OPT_TOKEN  = 'gs_webmaster_token';
    const OPT_CACHE  = 'gs_webmaster_cache';
    const CACHE_TTL  = 900;

    public static function token() {
        return trim((string) get_option(self::OPT_TOKEN, ''));
    }

    public static function ready() {
        return self::token() !== '';
    }

    /* ---------------------------------------------------------------------
     * Обмен с API
     * ------------------------------------------------------------------ */

    /**
     * @return array{ok:bool,body:array,message:string}
     */
    private static function call($path, $method = 'GET', $payload = null) {
        $token = self::token();
        if ($token === '') {
            return array('ok' => false, 'body' => array(), 'message' => 'Токен Вебмастера не задан');
        }

        $args = array(
            'timeout' => 45,
            'method'  => $method,
            'headers' => array(
                'Authorization' => 'OAuth ' . $token,
                'Content-Type'  => 'application/json',
            ),
        );
        if ($payload !== null) {
            $args['body'] = wp_json_encode($payload);
        }

        $response = wp_remote_request(self::API . $path, $args);
        if (is_wp_error($response)) {
            return array('ok' => false, 'body' => array(), 'message' => $response->get_error_message());
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $body = json_decode((string) wp_remote_retrieve_body($response), true);
        $body = is_array($body) ? $body : array();

        if ($code === 401 || $code === 403) {
            return array('ok' => false, 'body' => $body, 'message' => 'Токен не принят: истёк или выдан без прав на Вебмастер');
        }
        if ($code >= 400) {
            $why = (string) ($body['error_message'] ?? $body['error_code'] ?? ('код ' . $code));
            return array('ok' => false, 'body' => $body, 'message' => 'Вебмастер ответил ошибкой: ' . $why);
        }
        return array('ok' => true, 'body' => $body, 'message' => '');
    }

    /** Идентификаторы пользователя и сайта меняются редко — держим в кеше. */
    private static function ids() {
        $cache = get_option(self::OPT_CACHE, array());
        if (is_array($cache) && !empty($cache['user']) && !empty($cache['host'])
            && (int) ($cache['at'] ?? 0) > time() - DAY_IN_SECONDS) {
            return array('ok' => true, 'user' => $cache['user'], 'host' => $cache['host'], 'message' => '');
        }

        $me = self::call('/user/');
        if (!$me['ok']) {
            return array('ok' => false, 'user' => '', 'host' => '', 'message' => $me['message']);
        }
        $user = (string) ($me['body']['user_id'] ?? '');
        if ($user === '') {
            return array('ok' => false, 'user' => '', 'host' => '', 'message' => 'Не удалось определить пользователя');
        }

        $hosts = self::call('/user/' . rawurlencode($user) . '/hosts/');
        if (!$hosts['ok']) {
            return array('ok' => false, 'user' => $user, 'host' => '', 'message' => $hosts['message']);
        }

        $ours = wp_parse_url(home_url('/'), PHP_URL_HOST);
        $host_id = '';
        foreach ((array) ($hosts['body']['hosts'] ?? array()) as $host) {
            $url = (string) ($host['unicode_host_url'] ?? $host['ascii_host_url'] ?? '');
            if ($url !== '' && wp_parse_url($url, PHP_URL_HOST) === $ours) {
                $host_id = (string) ($host['host_id'] ?? '');
                break;
            }
        }
        if ($host_id === '') {
            return array(
                'ok'      => false,
                'user'    => $user,
                'host'    => '',
                'message' => 'Сайт не найден в Вебмастере — добавьте его и подтвердите права',
            );
        }

        update_option(self::OPT_CACHE, array('user' => $user, 'host' => $host_id, 'at' => time()), false);
        return array('ok' => true, 'user' => $user, 'host' => $host_id, 'message' => '');
    }

    public static function forget() {
        delete_option(self::OPT_CACHE);
    }

    private static function host_path($tail) {
        $ids = self::ids();
        if (!$ids['ok']) {
            return $ids;
        }
        $ids['path'] = '/user/' . rawurlencode($ids['user']) . '/hosts/' . rawurlencode($ids['host']) . $tail;
        return $ids;
    }

    /* ---------------------------------------------------------------------
     * Данные
     * ------------------------------------------------------------------ */

    /**
     * Замечания Вебмастера, приведённые к человеческому виду.
     *
     * @return array{ok:bool,problems:array,message:string}
     */
    public static function diagnostics() {
        $ids = self::host_path('/diagnostics/');
        if (!$ids['ok']) {
            return array('ok' => false, 'problems' => array(), 'message' => $ids['message']);
        }
        $res = self::call($ids['path']);
        if (!$res['ok']) {
            return array('ok' => false, 'problems' => array(), 'message' => $res['message']);
        }

        $out = array();
        foreach ((array) ($res['body']['problems'] ?? array()) as $problem) {
            $state = (string) ($problem['state'] ?? '');
            if ($state === 'ABSENT') {
                continue; // замечания нет — показывать нечего
            }
            $out[] = array(
                'type'     => (string) ($problem['problem_type'] ?? ''),
                'title'    => self::human_problem((string) ($problem['problem_type'] ?? '')),
                'severity' => self::human_severity((string) ($problem['severity'] ?? '')),
                'state'    => $state,
                'since'    => (string) ($problem['last_state_update'] ?? ''),
            );
        }
        return array('ok' => true, 'problems' => $out, 'message' => '');
    }

    /** Сколько страниц в поиске и в обходе — короткая сводка. */
    public static function indexing() {
        $ids = self::host_path('/summary/');
        if (!$ids['ok']) {
            return array('ok' => false, 'summary' => array(), 'message' => $ids['message']);
        }
        $res = self::call($ids['path']);
        if (!$res['ok']) {
            return array('ok' => false, 'summary' => array(), 'message' => $res['message']);
        }
        return array('ok' => true, 'summary' => $res['body'], 'message' => '');
    }

    /** Внутренние битые ссылки — то, что чинится прямо на сайте. */
    public static function broken_links($limit = 50) {
        $ids = self::host_path('/links/internal/broken/?offset=0&limit=' . (int) $limit);
        if (!$ids['ok']) {
            return array('ok' => false, 'links' => array(), 'message' => $ids['message']);
        }
        $res = self::call($ids['path']);
        if (!$res['ok']) {
            return array('ok' => false, 'links' => array(), 'message' => $res['message']);
        }
        return array('ok' => true, 'links' => (array) ($res['body']['links'] ?? array()), 'message' => '');
    }

    /**
     * Отправка страниц на переобход. Квота у Вебмастера дневная,
     * поэтому отправляем по одной и честно считаем отказы.
     *
     * @param array<int,string> $urls
     */
    public static function recrawl($urls) {
        $ids = self::host_path('/recrawl/queue/');
        if (!$ids['ok']) {
            return array('ok' => false, 'sent' => 0, 'failed' => 0, 'message' => $ids['message']);
        }

        $sent = $failed = 0;
        $last = '';
        foreach ((array) $urls as $url) {
            $url = esc_url_raw((string) $url);
            if ($url === '') {
                continue;
            }
            $res = self::call($ids['path'], 'POST', array('url' => $url));
            if ($res['ok']) {
                $sent++;
            } else {
                $failed++;
                $last = $res['message'];
            }
        }
        return array(
            'ok'      => $sent > 0,
            'sent'    => $sent,
            'failed'  => $failed,
            'message' => $failed > 0 ? $last : '',
        );
    }

    /* ---------------------------------------------------------------------
     * Перевод
     * ------------------------------------------------------------------ */

    public static function human_severity($severity) {
        switch (strtoupper($severity)) {
            case 'FATAL':            return 'критично';
            case 'CRITICAL':         return 'критично';
            case 'POSSIBLE_PROBLEM': return 'возможная проблема';
            case 'RECOMMENDATION':   return 'рекомендация';
        }
        return mb_strtolower($severity);
    }

    /**
     * Коды замечаний Вебмастера по-английски и без пояснений.
     * Переводим известные, остальные показываем как есть — лучше сырой код,
     * чем выдуманная формулировка.
     */
    public static function human_problem($type) {
        $map = array(
            'SITE_ERROR'                 => 'Сайт отвечает ошибкой',
            'DISALLOWED_IN_ROBOTS'       => 'Страницы закрыты в robots.txt',
            'NO_ROBOTS_TXT'              => 'Нет файла robots.txt',
            'NO_SITEMAP'                 => 'Не указана карта сайта',
            'SITEMAP_ERRORS'             => 'Ошибки в карте сайта',
            'DOCS_NOT_INDEXED'           => 'Страницы не попали в индекс',
            'MAIN_PAGE_DOWN'             => 'Главная страница недоступна',
            'SLOW_AVG_RESPONSE_TIME'     => 'Долгий ответ сервера',
            'DUPLICATE_CONTENT'          => 'Дубли страниц',
            'NO_TITLE'                   => 'Нет заголовка title',
            'NO_DESCRIPTION'             => 'Нет описания description',
            'MANY_TITLE_DUPLICATES'      => 'Повторяющиеся заголовки title',
            'MANY_DESCRIPTION_DUPLICATES'=> 'Повторяющиеся описания',
            'ERROR_PAGES'                => 'Страницы с ошибками',
            'SOFT_404'                   => 'Мягкие 404: ошибка отдаётся кодом 200',
            'NO_MOBILE_VERSION'          => 'Нет мобильной версии',
            'NOT_MOBILE_FRIENDLY'        => 'Страницы неудобны на телефоне',
            'SSL_CERTIFICATE_ERROR'      => 'Проблема с сертификатом',
            'THREATS'                    => 'Найдены угрозы безопасности',
            'BAD_HTTP_STATUS'            => 'Неверный код ответа',
            'DNS_ERROR'                  => 'Ошибка DNS',
            'NO_METRIKA_COUNTER'         => 'Не подключена Метрика',
            'BIG_FAVICON_ABSENT'         => 'Нет крупного значка сайта',
        );
        return isset($map[$type]) ? $map[$type] : $type;
    }
}
