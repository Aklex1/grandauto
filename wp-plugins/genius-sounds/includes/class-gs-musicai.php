<?php
/**
 * Второй поставщик обработки звука — студия Moises (Music AI).
 *
 * Основной агрегатор не умеет разделять дорожки в загруженных файлах и отдаёт
 * ошибку на шумоподавлении, поэтому «Убрать вокал» и «Убрать шум» работают
 * через отдельный API. Ключ и названия рабочих процессов задаются в админке:
 * пока ключа нет, оба сервиса честно показывают, что инструмент подключается.
 *
 * Оплата у поставщика — поминутная, поэтому и на нашей стороне цена считается
 * от длительности файла (GS_Lab::price).
 */

if (!defined('ABSPATH')) {
    exit;
}

class GS_MusicAI {

    const OPT_KEY        = 'gs_musicai_key';
    const OPT_WF_VOCAL   = 'gs_musicai_wf_vocal';
    const OPT_WF_DENOISE = 'gs_musicai_wf_denoise';

    /** Основной адрес API и запасной: у поставщика встречаются оба префикса. */
    const API_BASES = array('https://api.music.ai/api', 'https://api.music.ai/v1');

    public static function api_key() {
        return trim((string) get_option(self::OPT_KEY, ''));
    }

    public static function workflow($service_id) {
        $option = $service_id === 'vocal' ? self::OPT_WF_VOCAL : self::OPT_WF_DENOISE;
        return trim((string) get_option($option, ''));
    }

    /** Сервис можно включать, только когда есть и ключ, и рабочий процесс. */
    public static function ready($service_id) {
        return self::api_key() !== '' && self::workflow($service_id) !== '';
    }

    public static function handles($service_id) {
        return in_array($service_id, array('vocal', 'denoise'), true);
    }

    /* ---------------------------------------------------------------------
     * Запросы
     * ------------------------------------------------------------------ */

    private static function request($method, $path, $payload = null) {
        $key = self::api_key();
        if ($key === '') {
            return array('ok' => false, 'message' => 'Не настроен доступ к сервису обработки', 'body' => array());
        }

        $args = array(
            'method'  => $method,
            'timeout' => 45,
            'headers' => array(
                'Authorization' => $key,
                'Content-Type'  => 'application/json',
            ),
        );
        if ($payload !== null) {
            $args['body'] = wp_json_encode($payload);
        }

        $last = array('ok' => false, 'message' => 'Сервис обработки недоступен', 'body' => array());
        foreach (self::API_BASES as $base) {
            $response = wp_remote_request($base . $path, $args);
            if (is_wp_error($response)) {
                $last = array('ok' => false, 'message' => $response->get_error_message(), 'body' => array());
                continue;
            }
            $code = (int) wp_remote_retrieve_response_code($response);
            $body = json_decode((string) wp_remote_retrieve_body($response), true);
            // Неверный префикс адреса — пробуем следующий, всё остальное разбираем.
            if (in_array($code, array(404, 405), true)) {
                $last = array('ok' => false, 'message' => 'Сервис обработки не принял запрос', 'body' => array());
                continue;
            }
            if ($code < 200 || $code >= 300) {
                $message = is_array($body) ? (string) ($body['message'] ?? $body['error'] ?? '') : '';
                if ($code === 401 || $code === 403) {
                    $message = 'Сервис обработки отклонил ключ доступа';
                }
                return array('ok' => false, 'message' => $message ?: 'Сервис обработки вернул ошибку', 'body' => is_array($body) ? $body : array());
            }
            return array('ok' => true, 'message' => '', 'body' => is_array($body) ? $body : array());
        }
        return $last;
    }

    /**
     * @return array{ok:bool,task_id:string,message:string}
     */
    public static function create_job($service_id, $audio_url) {
        $workflow = self::workflow($service_id);
        if ($workflow === '') {
            return array('ok' => false, 'task_id' => '', 'message' => 'Инструмент ещё настраивается');
        }
        $res = self::request('POST', '/job', array(
            'name'     => 'genius-' . $service_id . '-' . gmdate('YmdHis'),
            'workflow' => $workflow,
            'params'   => array('inputUrl' => (string) $audio_url),
        ));
        if (empty($res['ok'])) {
            return array('ok' => false, 'task_id' => '', 'message' => $res['message']);
        }
        $id = (string) ($res['body']['id'] ?? '');
        if ($id === '') {
            return array('ok' => false, 'task_id' => '', 'message' => 'Сервис обработки не вернул задачу');
        }
        // Идентификаторы у поставщика — UUID, а маршрут статуса принимает
        // только буквы, цифры и дефис: помечаем префиксом, чтобы отличать.
        return array('ok' => true, 'task_id' => 'ma-' . $id, 'message' => '');
    }

    public static function is_own_task($task_id) {
        return strpos((string) $task_id, 'ma-') === 0;
    }

    private static function job_id($task_id) {
        return preg_replace('~[^a-zA-Z0-9-]~', '', substr((string) $task_id, 3));
    }

    /**
     * @return array{ok:bool,status:string,files:array,message:string}
     */
    public static function fetch_job($service_id, $task_id) {
        $out = array('ok' => false, 'status' => 'pending', 'files' => array(), 'message' => '');

        $res = self::request('GET', '/job/' . self::job_id($task_id));
        if (empty($res['ok'])) {
            $out['message'] = $res['message'];
            return $out;
        }
        $out['ok'] = true;
        $status = strtoupper((string) ($res['body']['status'] ?? ''));

        if ($status === 'FAILED') {
            $out['status'] = 'failed';
            $out['message'] = $service_id === 'vocal'
                ? 'Не удалось разделить дорожки'
                : 'Не удалось очистить запись';
            return $out;
        }
        if ($status !== 'SUCCEEDED') {
            return $out;
        }

        $result = isset($res['body']['result']) && is_array($res['body']['result']) ? $res['body']['result'] : array();
        foreach ($result as $key => $url) {
            if (!is_string($url) || strpos($url, 'http') !== 0) {
                continue;
            }
            $out['files'][] = array(
                'label' => self::label($service_id, (string) $key),
                'url'   => $url,
                'kind'  => 'audio',
            );
        }
        if (empty($out['files'])) {
            $out['message'] = 'Сервис обработки не вернул файлы';
            $out['status']  = 'failed';
            return $out;
        }
        $out['status'] = 'completed';
        return $out;
    }

    /**
     * Названия дорожек у разных рабочих процессов отличаются —
     * приводим к понятным пользователю подписям.
     */
    private static function label($service_id, $key) {
        $map = array(
            'vocals'        => 'Вокал без музыки',
            'vocal'         => 'Вокал без музыки',
            'lead_vocals'   => 'Основной вокал',
            'backing_vocals'=> 'Бэк-вокал',
            'accompaniment' => 'Минусовка (инструментал)',
            'instrumental'  => 'Минусовка (инструментал)',
            'music'         => 'Минусовка (инструментал)',
            'bass'          => 'Бас',
            'drums'         => 'Ударные',
            'other'         => 'Остальные инструменты',
        );
        $clean = strtolower(str_replace('-', '_', $key));
        if (isset($map[$clean])) {
            return $map[$clean];
        }
        return $service_id === 'vocal' ? 'Дорожка' : 'Очищенная запись';
    }
}
