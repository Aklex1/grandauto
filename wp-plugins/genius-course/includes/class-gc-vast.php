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
            // Скрипт запуска: качает веса и ставит недостающее. Пустое
            // поле — значит берём встроенный.
            'onstart'   => '',
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
        if (isset($values['onstart'])) {
            // Это скрипт для машины: фильтры тут всё сломают, поэтому
            // только снимаем слеши, добавленные WordPress.
            $now['onstart'] = trim((string) wp_unslash($values['onstart']));
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
            'onstart' => self::onstart($s),
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
        if (!$res['ok']) {
            // Vast переводит маршруты на v1 по одному: если старый
            // отвечает «устарел», пробуем новый, а не сдаёмся.
            $res = self::call('DELETE', 'v1/instances/' . (int) $instance_id . '/', array());
        }
        return array('ok' => $res['ok'], 'message' => $res['ok'] ? 'Машина остановлена.' : $res['message']);
    }

    /**
     * Пауза и запуск.
     *
     * Пауза снимает плату за видеокарту, но оставляет диск: скачанные
     * веса никуда не деваются, и следующее занятие начинается сразу, а
     * не с получасовой загрузки моделей. Запуск заново выполняет скрипт
     * запуска — значит, недостающие веса дотянутся сами.
     */
    public static function state($instance_id, $state) {
        $state = $state === 'running' ? 'running' : 'stopped';
        $body = array('state' => $state);
        $res = self::call('PUT', 'v0/instances/' . (int) $instance_id . '/', $body);
        if (!$res['ok']) {
            $res = self::call('PUT', 'v1/instances/' . (int) $instance_id . '/', $body);
        }
        if (!$res['ok']) {
            return array('ok' => false, 'message' => $res['message']);
        }
        return array('ok' => true, 'message' => $state === 'running'
            ? 'Машина запускается. Скрипт запуска выполнится заново.'
            : 'Машина на паузе: видеокарта не тарифицируется, диск с весами остаётся.');
    }

    /**
     * Выполнить команду на машине.
     *
     * Vast умеет запускать команду в контейнере без ssh и отдаёт ссылку
     * на вывод. Нужно это в одном случае: скрипт запуска сработал до
     * того, как образ развернул свои папки, и веса не скачались —
     * тогда тот же скрипт запускают ещё раз, уже руками.
     */
    public static function exec($instance_id, $command) {
        $res = self::call('PUT', 'v0/instances/command/' . (int) $instance_id . '/',
                          array('command' => (string) $command));
        if (!$res['ok']) {
            return array('ok' => false, 'url' => '', 'message' => $res['message']);
        }
        $url = (string) ($res['body']['result_url'] ?? '');
        if ($url !== '') {
            update_option('gc_exec_url', $url, false);
        }
        return array('ok' => true, 'url' => $url,
                     'message' => $url !== '' ? 'Команда отправлена.' : 'Команда отправлена, ссылки на вывод нет.');
    }

    /**
     * Та же загрузка весов, но одной строкой.
     *
     * Через API Vast принимает только однострочную команду, поэтому
     * скрипт запуска для него не годится: переводы строк он отвергает.
     */
    public static function weights_command() {
        $hf = 'https://huggingface.co';
        $parts = array(
            'CF=""',
            'for p in /workspace/ComfyUI /opt/ComfyUI /opt/workspace-internal/ComfyUI /root/ComfyUI /ComfyUI; '
                . 'do [ -d "$p" ] && CF="$p" && break; done',
            '[ -z "$CF" ] && CF=$(find / -maxdepth 6 -type d -name ComfyUI 2>/dev/null | head -1)',
            'echo "ComfyUI: $CF"',
            'mkdir -p "$CF/models/checkpoints" "$CF/models/text_encoders"',
            'pip install --no-cache-dir av >/dev/null 2>&1',
            'wget -nc -q -P "$CF/models/checkpoints" '
                . $hf . '/stabilityai/stable-diffusion-xl-base-1.0/resolve/main/sd_xl_base_1.0.safetensors',
            'wget -nc -q -P "$CF/models/checkpoints" '
                . $hf . '/Lightricks/LTX-Video/resolve/main/ltx-video-2b-v0.9.5.safetensors',
            'wget -nc -q -P "$CF/models/text_encoders" '
                . $hf . '/comfyanonymous/flux_text_encoders/resolve/main/t5xxl_fp16.safetensors',
            'ls -l "$CF/models/checkpoints" "$CF/models/text_encoders"',
        );
        return 'bash -lc ' . escapeshellarg(implode('; ', $parts));
    }

    /** Вывод последней команды. Появляется не сразу: Vast кладёт файл по готовности. */
    public static function exec_output($url = '') {
        $url = $url !== '' ? $url : (string) get_option('gc_exec_url', '');
        if ($url === '') {
            return array('ok' => false, 'text' => '', 'message' => 'команд ещё не было');
        }
        $res = wp_remote_get($url, array('timeout' => 20));
        if (is_wp_error($res)) {
            return array('ok' => false, 'text' => '', 'message' => $res->get_error_message());
        }
        $code = (int) wp_remote_retrieve_response_code($res);
        if ($code !== 200) {
            return array('ok' => false, 'text' => '', 'message' => 'вывод ещё не готов (ответ ' . $code . ')');
        }
        return array('ok' => true, 'text' => (string) wp_remote_retrieve_body($res), 'message' => '');
    }

    /**
     * Скрипт запуска машины.
     *
     * Vast выполняет его один раз после старта контейнера. Задача —
     * положить веса туда, где ComfyUI их увидит. Папку ищем, а не
     * пишем жёстко: у разных образов ComfyUI лежит в разных местах, и
     * ошибка в пути означает машину без моделей за деньги.
     */
    public static function onstart($s = null) {
        $s = is_array($s) ? $s : self::settings();
        if (trim((string) $s['onstart']) !== '') {
            return (string) $s['onstart'];
        }
        return self::default_onstart();
    }

    public static function default_onstart() {
        return <<<'SH'
env >> /etc/environment
CF=""
for p in /workspace/ComfyUI /opt/ComfyUI /opt/workspace-internal/ComfyUI /root/ComfyUI /ComfyUI; do
  [ -d "$p" ] && CF="$p" && break
done
[ -z "$CF" ] && CF=$(find / -maxdepth 6 -type d -name ComfyUI 2>/dev/null | head -1)
[ -z "$CF" ] && exit 0
mkdir -p "$CF/models/checkpoints" "$CF/models/text_encoders"
pip install --no-cache-dir av >/dev/null 2>&1
HF=https://huggingface.co
wget -nc -q -P "$CF/models/checkpoints"   $HF/stabilityai/stable-diffusion-xl-base-1.0/resolve/main/sd_xl_base_1.0.safetensors
wget -nc -q -P "$CF/models/checkpoints"   $HF/Lightricks/LTX-Video/resolve/main/ltx-video-2b-v0.9.5.safetensors
wget -nc -q -P "$CF/models/text_encoders"   $HF/comfyanonymous/flux_text_encoders/resolve/main/t5xxl_fp16.safetensors
echo "модели на месте" > /workspace/gc-onstart.done
SH;
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
            // Пояснение Vast к состоянию: пока машина поднимается, здесь
            // видно, качается образ или уже запускается контейнер. Без
            // этого «loading» ничем не отличается от зависшего заказа.
            'msg'    => (string) ($row['status_msg'] ?? ''),
        );
    }
}
