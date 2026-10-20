<?php
/**
 * Управление арендой на Vast.
 *
 * Это API инфраструктуры, а не генерации: им ищут предложения по
 * видеокартам, запускают и останавливают машины и узнают их адрес.
 * Сами кадры считает уже ComfyUI на арендованной машине — см. GC_Gpu.
 *
 * Ключ лежит в настройках сайта и наружу не выходит ни разу: ни в
 * разметку кабинета, ни в скрипты, ни ученикам. В админке он показан
 * огрызком, потому что человеку нужно убедиться, что ключ тот самый, а
 * не прочитать его целиком.
 */

if (!defined('ABSPATH')) {
    exit;
}

class GC_Vast {

    const OPT  = 'gc_vast';
    /*
     * Версию пути указываем в каждом вызове: Vast переводит маршруты на
     * v1 по одному, и список машин уже отвечает «v0 устарел», пока поиск
     * предложений и аренда живут в v0.
     */
    const BASE = 'https://console.vast.ai/api';

    /** Порты служб внутри контейнера. Наружу Vast их пробрасывает сам. */
    const COMFY_PORT = 8188;
    const STT_PORT   = 8189;
    const TTS_PORT   = 8190;

    public static function settings() {
        $saved = get_option(self::OPT, array());
        return wp_parse_args(is_array($saved) ? $saved : array(), array(
            'key'       => '',
            'instance'  => 0,
            'image'     => 'vastai/comfy:cuda-12.1-auto',
            'disk'      => 40,
            'max_price' => 0.5,
            'gpu'       => 'RTX 4090',
        ));
    }

    public static function save($values) {
        $now = self::settings();
        if (isset($values['key']) && trim((string) $values['key']) !== '' && strpos((string) $values['key'], '…') === false) {
            $now['key'] = trim((string) $values['key']);
        }
        foreach (array('image', 'gpu') as $field) {
            if (isset($values[$field])) {
                $now[$field] = sanitize_text_field((string) $values[$field]);
            }
        }
        if (isset($values['disk'])) {
            $now['disk'] = max(10, min(500, (int) $values['disk']));
        }
        if (isset($values['max_price'])) {
            $now['max_price'] = max(0.05, min(10, (float) $values['max_price']));
        }
        if (isset($values['instance'])) {
            $now['instance'] = (int) $values['instance'];
        }
        update_option(self::OPT, $now, false);
        return $now;
    }

    public static function has_key() {
        $s = self::settings();
        return $s['key'] !== '';
    }

    /** Огрызок ключа для показа человеку: видно, что ключ тот, и только. */
    public static function key_hint() {
        $s = self::settings();
        if ($s['key'] === '') {
            return '';
        }
        return substr($s['key'], 0, 4) . '…' . substr($s['key'], -4);
    }

    /**
     * Запрос к API аренды.
     *
     * @return array{ok:bool,code:int,body:mixed,message:string}
     */
    public static function call($method, $path, $body = null, $query = array()) {
        $s = self::settings();
        if ($s['key'] === '') {
            return array('ok' => false, 'code' => 0, 'body' => null, 'message' => 'Ключ Vast не задан');
        }
        $url = self::BASE . '/' . ltrim($path, '/');
        if ($query) {
            $url = add_query_arg($query, $url);
        }
        $args = array(
            'method'  => strtoupper($method),
            'timeout' => 30,
            'headers' => array(
                'Authorization' => 'Bearer ' . $s['key'],
                'Accept'        => 'application/json',
            ),
        );
        if ($body !== null) {
            $args['headers']['Content-Type'] = 'application/json';
            $args['body'] = wp_json_encode($body);
        }
        $res = wp_remote_request($url, $args);
        if (is_wp_error($res)) {
            return array('ok' => false, 'code' => 0, 'body' => null, 'message' => $res->get_error_message());
        }
        $code = (int) wp_remote_retrieve_response_code($res);
        $raw = (string) wp_remote_retrieve_body($res);
        $data = json_decode($raw, true);
        return array(
            'ok'      => $code >= 200 && $code < 300,
            'code'    => $code,
            'body'    => $data === null ? $raw : $data,
            'message' => $code >= 200 && $code < 300 ? '' : ('ответ ' . $code . ': ' . mb_substr($raw, 0, 200)),
        );
    }

    /** Наши машины. */
    public static function instances() {
        $res = self::call('GET', 'v1/instances', null, array('owner' => 'me'));
        if (!$res['ok']) {
            return array('ok' => false, 'items' => array(), 'message' => $res['message']);
        }
        $items = array();
        $list = isset($res['body']['instances']) ? (array) $res['body']['instances'] : (array) $res['body'];
        foreach ($list as $row) {
            if (!is_array($row)) {
                continue;
            }
            $items[] = self::shape($row);
        }
        return array('ok' => true, 'items' => $items, 'message' => '');
    }

    /**
     * Предложения по видеокартам.
     *
     * Запрос у Vast — это json в параметре q. Условия ставим простые:
     * нужная карта, машина свободна, цена не выше потолка, диска
     * хватает под веса моделей.
     */
    public static function offers($gpu = '', $max_price = 0) {
        $s = self::settings();
        $gpu = $gpu !== '' ? $gpu : $s['gpu'];
        $max_price = $max_price > 0 ? $max_price : (float) $s['max_price'];

        $q = array(
            'verified'       => array('eq' => true),
            'rentable'       => array('eq' => true),
            'external'       => array('eq' => false),
            'gpu_name'       => array('eq' => $gpu),
            'dph_total'      => array('lte' => $max_price),
            'disk_space'     => array('gte' => (int) $s['disk']),
            'inet_down'      => array('gte' => 100),
            'order'          => array(array('dph_total', 'asc')),
            'type'           => 'on-demand',
            'limit'          => 12,
        );
        $res = self::call('GET', 'v0/bundles', null, array('q' => wp_json_encode($q)));
        if (!$res['ok']) {
            return array('ok' => false, 'items' => array(), 'message' => $res['message']);
        }
        $list = isset($res['body']['offers']) ? (array) $res['body']['offers'] : array();
        $items = array();
        foreach ($list as $row) {
            $items[] = array(
                'id'    => (int) ($row['id'] ?? 0),
                'gpu'   => (string) ($row['gpu_name'] ?? ''),
                'num'   => (int) ($row['num_gpus'] ?? 1),
                'ram'   => round((float) ($row['gpu_ram'] ?? 0) / 1024, 0),
                'price' => round((float) ($row['dph_total'] ?? 0), 3),
                'disk'  => (int) ($row['disk_space'] ?? 0),
                'down'  => (int) ($row['inet_down'] ?? 0),
                'place' => (string) ($row['geolocation'] ?? ''),
                'rel'   => round((float) ($row['reliability2'] ?? 0) * 100, 1),
            );
        }
        return array('ok' => true, 'items' => $items, 'message' => '');
    }

    /**
     * Арендовать машину.
     *
     * Деньги списываются с общего баланса аккаунта, поэтому вызов
     * доступен только из админки и только по явному нажатию.
     */
    public static function rent($offer_id) {
        $s = self::settings();
        /*
         * Поля — по документации Vast: image, label, disk, runtype, env,
         * onstart. Порты задаются ключами вида «-p 8188:8188» внутри env,
         * а не отдельным полем: это единственный поддерживаемый способ.
         *
         * Три порта: ComfyUI, распознавание речи и озвучка. Две
         * последние службы поднимает скрипт запуска, и если их на образе
         * нет — машина всё равно встанет, просто порты будут молчать.
         */
        $body = array(
            'image'   => $s['image'],
            'label'   => 'genius-course',
            'disk'    => (float) $s['disk'],
            'runtype' => 'ssh_direct',
            'env'     => array(
                '-p ' . self::COMFY_PORT . ':' . self::COMFY_PORT => '1',
                '-p ' . self::STT_PORT . ':' . self::STT_PORT     => '1',
                '-p ' . self::TTS_PORT . ':' . self::TTS_PORT     => '1',
                'OPEN_BUTTON_PORT' => (string) self::COMFY_PORT,
            ),
            // Переменные окружения в ssh-сессии не видны, пока их не
            // выложить в /etc/environment — так написано в документации.
            'onstart' => 'env >> /etc/environment',
        );
        $res = self::call('PUT', 'v0/asks/' . (int) $offer_id . '/', $body);
        if (!$res['ok']) {
            return array('ok' => false, 'id' => 0, 'message' => $res['message']);
        }
        $id = (int) ($res['body']['new_contract'] ?? 0);
        if ($id > 0) {
            self::save(array('instance' => $id));
        }
        return array('ok' => true, 'id' => $id, 'message' => 'Машина заказана, поднимается.');
    }

    public static function stop($instance_id) {
        $res = self::call('DELETE', 'v0/instances/' . (int) $instance_id . '/', array());
        return array('ok' => $res['ok'], 'message' => $res['ok'] ? 'Машина остановлена.' : $res['message']);
    }

    /**
     * Приводим ответ к виду, с которым удобно работать.
     *
     * Главное здесь — внешний адрес ComfyUI. Vast прокидывает порты
     * наружу на случайные номера, и соответствие лежит в ports.
     */
    private static function shape($row) {
        $ports = isset($row['ports']) && is_array($row['ports']) ? $row['ports'] : array();
        $host = (string) ($row['public_ipaddr'] ?? '');
        $public = '';
        $key = self::COMFY_PORT . '/tcp';
        if ($host !== '' && isset($ports[$key][0]['HostPort'])) {
            $public = 'http://' . trim($host) . ':' . (int) $ports[$key][0]['HostPort'];
        }
        return array(
            'id'     => (int) ($row['id'] ?? 0),
            'status' => (string) ($row['actual_status'] ?? ($row['cur_state'] ?? '')),
            'gpu'    => (string) ($row['gpu_name'] ?? ''),
            'num'    => (int) ($row['num_gpus'] ?? 1),
            'price'  => round((float) ($row['dph_total'] ?? 0), 3),
            'image'  => (string) ($row['image_uuid'] ?? ''),
            'host'   => $host,
            'url'    => $public,
            'label'  => (string) ($row['label'] ?? ''),
        );
    }
}
