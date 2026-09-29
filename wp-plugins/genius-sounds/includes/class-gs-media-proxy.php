<?php
/**
 * Отдача файлов службы медиа через сайт.
 *
 * Служба живёт на отдельной машине и отдаёт готовые файлы по простому
 * http с адресом и портом: http://89.169.38.152:8099/files/имя.opus.
 * Кабинет открыт по https, и браузер такой звук на защищённой странице
 * либо молча не проигрывает, либо ругается на небезопасное содержимое —
 * человек видит пустой плеер при полностью исправной генерации.
 *
 * Здесь заводим маршрут-посредник на самом сайте: он забирает файл у
 * службы и отдаёт его тем же https, что и страница. Чужой плагин не
 * трогаем — адреса в его ответах подменяем на выходе, фильтром.
 *
 * Посредник намеренно узкий: ходит только на адрес службы из настроек и
 * только в папку готовых файлов, имя пропускает по белому списку букв,
 * цифр и точки, расширение — из короткого перечня звука и видео. Ни
 * произвольный адрес, ни путь с «..» через него не пройдут.
 */

if (!defined('ABSPATH')) {
    exit;
}

class GS_Media_Proxy {

    const NS = 'genius-sounds/v1';

    /** Что вообще умеет отдавать служба: звук генерации и дорожки роликов. */
    const TYPES = array(
        'mp3'  => 'audio/mpeg',
        'opus' => 'audio/ogg',
        'ogg'  => 'audio/ogg',
        'wav'  => 'audio/wav',
        'm4a'  => 'audio/mp4',
        'flac' => 'audio/flac',
        'webm' => 'audio/webm',
        'mp4'  => 'video/mp4',
    );

    public static function boot() {
        add_action('rest_api_init', array(__CLASS__, 'register'));
        add_filter('rest_post_dispatch', array(__CLASS__, 'rewrite'), 10, 3);
    }

    public static function register() {
        register_rest_route(self::NS, '/media/(?P<name>[A-Za-z0-9][A-Za-z0-9._-]{0,127})', array(
            'methods'             => 'GET',
            'callback'            => array(__CLASS__, 'serve'),
            'permission_callback' => '__return_true',
        ));
    }

    /**
     * Адрес папки готовых файлов службы: «http://хост:порт/files/».
     * Пусто, если служба не настроена или уже отдаёт по https — тогда
     * посредник не нужен и подменять нечего.
     */
    public static function files_base() {
        static $base = null;
        if ($base !== null) {
            return $base;
        }
        $base = '';
        $url = trim((string) get_option('kie_tts_free_tts_url', ''));
        if ($url === '') {
            return $base;
        }
        $parts = wp_parse_url($url);
        if (empty($parts['host']) || empty($parts['scheme']) || $parts['scheme'] !== 'http') {
            return $base;
        }
        $base = 'http://' . $parts['host']
            . (empty($parts['port']) ? '' : ':' . (int) $parts['port'])
            . '/files/';
        return $base;
    }

    public static function proxy_base() {
        return rest_url(self::NS . '/media/');
    }

    /**
     * Подмена адресов в ответах: и своих, и базового плагина.
     *
     * Правим на выходе, а не в чужом коде: плагин озвучки обновляется
     * сам по себе, и правка внутри него не пережила бы обновление.
     */
    public static function rewrite($response, $server, $request) {
        $base = self::files_base();
        if ($base === '' || !($response instanceof WP_REST_Response)) {
            return $response;
        }
        $route = (string) $request->get_route();
        if (strpos($route, '/media/') !== false) {
            return $response;
        }
        if (strpos($route, '/tts/v1/') !== 0 && strpos($route, '/' . self::NS . '/') !== 0) {
            return $response;
        }
        $data = $response->get_data();
        if (!is_array($data) && !is_string($data)) {
            return $response;
        }
        $response->set_data(self::walk($data, $base, self::proxy_base()));
        return $response;
    }

    private static function walk($value, $base, $proxy) {
        if (is_string($value)) {
            return strpos($value, $base) === false ? $value : str_replace($base, $proxy, $value);
        }
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = self::walk($item, $base, $proxy);
            }
        }
        return $value;
    }

    private static function type_of($name) {
        $ext = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));
        return isset(self::TYPES[$ext]) ? self::TYPES[$ext] : '';
    }

    /**
     * Отдать файл. Тело прокачиваем кусками: дорожка часового ролика
     * весит десятки мегабайт, и держать её целиком в памяти незачем.
     */
    public static function serve($request) {
        $name = (string) $request['name'];
        $base = self::files_base();
        if ($base === '') {
            return new WP_Error('gs_media_off', 'Служба файлов не настроена', array('status' => 404));
        }
        if (strpos($name, '..') !== false || self::type_of($name) === '') {
            return new WP_Error('gs_media_bad', 'Такого файла нет', array('status' => 404));
        }
        if (!function_exists('curl_init')) {
            return self::serve_simple($base . $name, $name);
        }

        $sent = false;
        $status = 200;
        // Плеер просит куски файла заголовком Range — прокидываем его как
        // есть, иначе перемотка каждый раз качает запись заново.
        $head = array('Accept-Encoding: identity');
        if (!empty($_SERVER['HTTP_RANGE']) && preg_match('~^bytes=[0-9,\-]+$~', $_SERVER['HTTP_RANGE'])) {
            $head[] = 'Range: ' . $_SERVER['HTTP_RANGE'];
        }

        $ch = curl_init($base . $name);
        curl_setopt_array($ch, array(
            CURLOPT_HTTPHEADER     => $head,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 300,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$status, &$sent, $name) {
                $trim = trim($line);
                if (stripos($trim, 'HTTP/') === 0) {
                    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
                    return strlen($line);
                }
                if ($trim === '') {
                    if ($status >= 200 && $status < 300) {
                        self::open_headers($status, $name);
                        $sent = true;
                    }
                    return strlen($line);
                }
                $pass = array('content-length', 'content-range', 'accept-ranges', 'last-modified', 'etag');
                $pair = explode(':', $trim, 2);
                if (count($pair) === 2 && in_array(strtolower(trim($pair[0])), $pass, true)) {
                    header(trim($pair[0]) . ': ' . trim($pair[1]));
                }
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => function ($ch, $chunk) use (&$sent) {
                if (!$sent) {
                    return strlen($chunk);
                }
                echo $chunk;
                flush();
                return strlen($chunk);
            },
        ));
        curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if (!$sent) {
            if ($error !== '') {
                return new WP_Error('gs_media_down', 'Файл сейчас недоступен', array('status' => 502));
            }
            return new WP_Error('gs_media_gone', 'Такого файла нет', array('status' => 404));
        }
        exit;
    }

    /** Запасной путь, если curl в сборке PHP нет. */
    private static function serve_simple($url, $name) {
        $answer = wp_remote_get($url, array('timeout' => 120));
        if (is_wp_error($answer) || (int) wp_remote_retrieve_response_code($answer) !== 200) {
            return new WP_Error('gs_media_down', 'Файл сейчас недоступен', array('status' => 502));
        }
        $body = wp_remote_retrieve_body($answer);
        self::open_headers(200, $name);
        header('Content-Length: ' . strlen($body));
        echo $body;
        exit;
    }

    /**
     * Заголовки ответа. Перебиваем json-заголовок, который REST-сервер
     * успевает выставить до вызова обработчика.
     */
    private static function open_headers($status, $name) {
        status_header($status);
        header('Content-Type: ' . self::type_of($name));
        header('Accept-Ranges: bytes');
        // Имя случайное и живёт сутки — можно спокойно кэшировать.
        header('Cache-Control: public, max-age=86400');
        header('X-Robots-Tag: noindex');
        header_remove('Expires');
        header_remove('Pragma');
        while (ob_get_level() > 0) {
            ob_end_flush();
        }
    }
}
