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
         *
         * Режим ssh_direct: Vast подменяет точку входа образа своей, и
         * собственный запуск образа не выполняется вовсе. Поэтому ComfyUI
         * запускает наш скрипт запуска — иначе порт проброшен, а слушать
         * его некому.
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
     * Задумано так: пауза снимает плату за видеокарту, диск с весами
     * остаётся, следующее занятие начинается сразу. На практике это
     * зависит от хозяина машины: у части предложений остановленная
     * машина просто удаляется вместе с диском — проверено на своей
     * шкуре. Поэтому кнопка спрашивает подтверждение, а после паузы
     * список машин стоит перечитать.
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
            : 'Команда на паузу принята. Проверьте список: не каждый хозяин умеет держать машину '
              . 'остановленной, и у таких Vast её удаляет вместе с диском.');
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
mkdir -p /workspace
exec >>/workspace/gc-onstart.log 2>&1
echo "=== запуск $(date -u)"
# Отвязываем службы от скрипта запуска: Vast завершает его процесс, и
# вместе с ним может уйти вся группа — тогда ComfyUI умрёт сразу после
# старта, а порт останется проброшенным и молчащим.
SS=""
command -v setsid >/dev/null 2>&1 && SS="setsid"
# Окно внутрь машины без ssh: отдаём /workspace по порту 8189. Иначе
# причину «ComfyUI молчит» узнать нечем — команды на работающей машине
# Vast через API не разрешает.
$SS nohup python3 -m http.server 8189 --directory /workspace >/dev/null 2>&1 &
# Родные службы образа: в режиме ssh Vast подменяет точку входа, и
# supervisord образа не стартует. Запускаем его сами — он поднимет
# ComfyUI так, как задумано в образе, со своим окружением.
if [ -f /etc/supervisor/supervisord.conf ]; then
  $SS nohup supervisord -c /etc/supervisor/supervisord.conf >/dev/null 2>&1 &
  sleep 30
fi
CF=""
for p in /workspace/ComfyUI /opt/ComfyUI /opt/workspace-internal/ComfyUI /root/ComfyUI /ComfyUI; do
  [ -f "$p/main.py" ] && CF="$p" && break
done
[ -z "$CF" ] && CF=$(dirname "$(find / -maxdepth 7 -name main.py -path '*ComfyUI*' 2>/dev/null | head -1)")
echo "ComfyUI: $CF"
if ! curl -s -m 5 http://127.0.0.1:8188/system_stats >/dev/null 2>&1; then
  # Python берём тот, в котором есть torch: в образах он обычно в своём
  # окружении, а системный python про него не знает.
  PY=""
  for c in /venv/main/bin/python /opt/venv/bin/python "$CF/venv/bin/python" "$(command -v python3)" "$(command -v python)"; do
    [ -x "$c" ] && "$c" -c "import torch" >/dev/null 2>&1 && PY="$c" && break
  done
  echo "python: $PY"
  if [ -n "$PY" ] && [ -n "$CF" ]; then
    cd "$CF"
    $SS nohup "$PY" main.py --listen 0.0.0.0 --port 8188 --disable-auto-launch >>/workspace/comfy.log 2>&1 &
  fi
fi
mkdir -p "$CF/models/checkpoints" "$CF/models/text_encoders"
HF=https://huggingface.co
# Фотореалистичная сборка SDXL: у базовой модели руки и лица в полный
# рост выходят кашей, у этой — заметно чище. Её и ставим основной.
wget -nc -q -P "$CF/models/checkpoints" \
  $HF/SG161222/RealVisXL_V5.0/resolve/main/RealVisXL_V5.0_fp16.safetensors
wget -nc -q -P "$CF/models/checkpoints" \
  $HF/stabilityai/stable-diffusion-xl-base-1.0/resolve/main/sd_xl_base_1.0.safetensors
wget -nc -q -P "$CF/models/checkpoints" \
  $HF/Lightricks/LTX-Video/resolve/main/ltx-video-2b-v0.9.5.safetensors
wget -nc -q -P "$CF/models/text_encoders" \
  $HF/comfyanonymous/flux_text_encoders/resolve/main/t5xxl_fp16.safetensors
echo "веса на месте $(date -u)"
SH;
    }

    /**
     * Приводим ответ к виду, с которым удобно работать.
     *
     * Главное здесь — внешний адрес ComfyUI. Vast прокидывает порты
     * наружу на случайные номера, и соответствие лежит в ports.
     */
    /**
     * Что отвечает на каждом порту машины.
     *
     * Когда ComfyUI не отзывается, вслепую не угадать: у части образов
     * он сидит за своим порталом на другом порту. Опрашиваем только
     * адреса этой же арендованной машины — чужие сюда не подставить.
     */
    public static function probe($instance_id) {
        $list = self::instances();
        if (!$list['ok']) {
            return array('ok' => false, 'rows' => array(), 'message' => $list['message']);
        }
        $row = null;
        foreach ($list['items'] as $item) {
            if ((int) $item['id'] === (int) $instance_id) {
                $row = $item;
                break;
            }
        }
        if (!$row || $row['host'] === '' || $row['ports'] === '') {
            return array('ok' => false, 'rows' => array(), 'message' => 'у машины ещё нет портов');
        }
        $rows = array();
        foreach (explode(',', $row['ports']) as $pair) {
            if (!preg_match('~(\d+)/tcp\s*→\s*(\d+)~u', trim($pair), $m)) {
                continue;
            }
            $inner = (int) $m[1];
            $outer = (int) $m[2];
            if ($inner === 22) {
                continue;
            }
            $url = 'http://' . $row['host'] . ':' . $outer . '/';
            $res = wp_remote_get($url, array('timeout' => 8, 'redirection' => 0));
            if (is_wp_error($res)) {
                $rows[] = array('port' => $inner, 'outer' => $outer, 'code' => 0,
                                'note' => $res->get_error_message());
                continue;
            }
            $body = (string) wp_remote_retrieve_body($res);
            $title = '';
            if (preg_match('~<title[^>]*>(.*?)</title>~is', $body, $t)) {
                $title = trim($t[1]);
            }
            $rows[] = array(
                'port'  => $inner,
                'outer' => $outer,
                'code'  => (int) wp_remote_retrieve_response_code($res),
                'note'  => $title !== '' ? $title : mb_substr(trim(wp_strip_all_tags($body)), 0, 120),
            );
        }
        return array('ok' => true, 'rows' => $rows, 'message' => '');
    }

    /**
     * Журнал с машины.
     *
     * Скрипт запуска поднимает на порту 8189 раздачу /workspace, и это
     * единственное окно внутрь: ssh из сайта нет, а команды на
     * работающей машине Vast через API не даёт. Имена файлов — только
     * свои, чужого отсюда не запросить.
     */
    public static function machine_log($instance_id, $file = 'gc-onstart.log') {
        $allowed = array('gc-onstart.log', 'comfy.log');
        if (!in_array($file, $allowed, true)) {
            return array('ok' => false, 'text' => '', 'message' => 'такой журнал не предусмотрен');
        }
        $list = self::instances();
        if (!$list['ok']) {
            return array('ok' => false, 'text' => '', 'message' => $list['message']);
        }
        foreach ($list['items'] as $item) {
            if ((int) $item['id'] !== (int) $instance_id) {
                continue;
            }
            if (!preg_match('~(^|,)\s*' . self::STT_PORT . '/tcp\s*→\s*(\d+)~u', $item['ports'], $m)) {
                return array('ok' => false, 'text' => '', 'message' => 'порт журнала не проброшен');
            }
            $url = 'http://' . $item['host'] . ':' . (int) $m[2] . '/' . $file;
            $res = wp_remote_get($url, array('timeout' => 15));
            if (is_wp_error($res)) {
                return array('ok' => false, 'text' => '', 'message' => $res->get_error_message());
            }
            $code = (int) wp_remote_retrieve_response_code($res);
            if ($code !== 200) {
                return array('ok' => false, 'text' => '', 'message' => 'журнала ещё нет (ответ ' . $code . ')');
            }
            return array('ok' => true, 'text' => (string) wp_remote_retrieve_body($res), 'message' => '');
        }
        return array('ok' => false, 'text' => '', 'message' => 'машина не найдена');
    }

    private static function ports_line($ports) {
        $out = array();
        foreach ((array) $ports as $inner => $rows) {
            if (!is_array($rows) || empty($rows[0]['HostPort'])) {
                continue;
            }
            $out[] = $inner . ' → ' . (int) $rows[0]['HostPort'];
        }
        return implode(', ', $out);
    }

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
            // Все проброшенные порты строкой: когда ComfyUI не отвечает,
            // первым делом нужно знать, выдан ли вообще порт 8188 и что
            // ещё торчит наружу.
            'ports'  => self::ports_line($ports),
        );
    }
}
