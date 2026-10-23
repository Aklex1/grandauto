<?php
/**
 * Мастерская на моделях через наш шлюз.
 *
 * У курса «Заработок на нейросетях» другая задача, чем у AI-креатора:
 * человек учится продавать работу, а не настраивать ComfyUI. Ему нужно,
 * чтобы кадр и ролик считались всегда, без арендованной видеокарты,
 * которую включают руками и платят за часы.
 *
 * Поэтому здесь второй двигатель мастерской: задания уходят в тот же
 * шлюз моделей, которым работает весь сайт (GS_Provider). Очередь,
 * таблица заданий, лимит в сутки и медиатека — общие с GPU-мастерской:
 * ученику всё равно, где считалось, а нам дешевле одна очередь.
 *
 * Ключ доступа к шлюзу живёт на стороне сайта и в браузер не уходит —
 * ученик обращается только к маршрутам кабинета.
 */

if (!defined('ABSPATH')) {
    exit;
}

class GC_Kie {

    /** Метка в параметрах задания: по ней видно, чем его считать. */
    const ENGINE = 'kie';

    /** Сколько заданий в сутки на ученика: каждое стоит денег. */
    const DAILY_DEFAULT = 20;

    public static function available() {
        return class_exists('GS_Provider') && trim((string) get_option('kie_tts_api_key', '')) !== '';
    }

    /**
     * Готов ли шлюз считать.
     *
     * Маршруты помечаются «не отвечает» на несколько минут, когда
     * поставщик отказал. Если по нужной возможности не осталось ни
     * одного живого — честнее сказать ученику, что сейчас не считается,
     * чем поставить задание, которое сразу упадёт.
     *
     * @return array{ok:bool,message:string}
     */
    public static function health() {
        if (!class_exists('GS_Provider')) {
            return array('ok' => false, 'message' => 'Плагин сервисов выключен');
        }
        if (!self::available()) {
            return array('ok' => false, 'message' => 'Доступ к моделям не настроен');
        }
        $alive = array();
        foreach (GS_Provider::status() as $row) {
            $cap = (string) $row['capability'];
            if (!isset($alive[$cap])) {
                $alive[$cap] = 0;
            }
            $alive[$cap] += empty($row['down']) ? 1 : 0;
        }
        if (isset($alive['image']) && $alive['image'] === 0) {
            return array('ok' => false, 'message' => 'Модели кадров сейчас не отвечают');
        }
        return array('ok' => true, 'message' => 'на связи');
    }

    /** Ролики здесь есть всегда: отдельной настройки им не нужно. */
    public static function video_allowed() {
        return true;
    }

    public static function daily() {
        $s = GC_Gpu::settings();
        return max(1, (int) ($s['daily_kie'] ?? self::DAILY_DEFAULT));
    }

    /**
     * Какую возможность шлюза просить.
     *
     * Референс меняет модель, а не параметр: кадр по описанию и правка
     * присланной картинки — это разные модели, как и ролик по описанию
     * против оживления фотографии.
     */
    public static function capability($kind, $with_reference) {
        if ((string) $kind === 'video') {
            return $with_reference ? 'photo_video' : 'video';
        }
        return $with_reference ? 'image_edit' : 'image';
    }

    /**
     * Соотношение сторон из размера кадра.
     *
     * Модели шлюза принимают не пиксели, а пропорцию: выбираем ближайшую
     * из тех, которые они понимают, иначе 1216×832 превратится в отказ.
     */
    public static function ratio($params) {
        $w = (int) ($params['width'] ?? 0);
        $h = (int) ($params['height'] ?? 0);
        if ($w <= 0 || $h <= 0) {
            return '1:1';
        }
        $known = array('1:1' => 1.0, '4:3' => 4 / 3, '3:4' => 3 / 4, '16:9' => 16 / 9, '9:16' => 9 / 16);
        $want = $w / $h;
        $best = '1:1';
        $diff = PHP_FLOAT_MAX;
        foreach ($known as $name => $value) {
            $d = abs($value - $want);
            if ($d < $diff) {
                $diff = $d;
                $best = $name;
            }
        }
        return $best;
    }

    /**
     * Отправить задание в шлюз.
     *
     * @return array{ok:bool,job:int,status:string,message:string}
     */
    public static function send($job_id) {
        $job = GC_Gpu::job($job_id);
        if (!$job) {
            return array('ok' => false, 'job' => 0, 'status' => '', 'message' => 'Задание не найдено');
        }
        if (!self::available()) {
            return array('ok' => true, 'job' => (int) $job_id, 'status' => 'queued',
                         'message' => 'Модели сейчас недоступны. Задание осталось в очереди.');
        }

        $params = json_decode((string) $job['params'], true);
        $params = is_array($params) ? $params : array();
        $reference = (int) ($params['reference'] ?? 0);
        $ref_url = $reference > 0 ? (string) wp_get_attachment_url($reference) : '';
        $capability = self::capability($job['kind'], $ref_url !== '');

        $input = array(
            'prompt' => (string) $job['prompt'],
            'ratio'  => self::ratio($params),
        );
        if ($ref_url !== '') {
            // Названия полей у моделей разные, адаптер в шлюзе выберет
            // своё: наше дело — дать ссылку оба раза.
            $input['image_url'] = $ref_url;
            $input['image_urls'] = array($ref_url);
        }
        if ((string) $job['kind'] === 'video') {
            $input['duration'] = '5';
            $input['resolution'] = '720p';
        }

        $res = GS_Provider::job($capability, $input, array('timeout' => 30));
        if (empty($res['ok'])) {
            GC_Gpu::fail($job_id, (string) $res['message']);
            return array('ok' => false, 'job' => (int) $job_id, 'status' => 'failed',
                         'message' => (string) $res['message']);
        }
        GC_Gpu::mark_running($job_id, (string) $res['task']);

        return array('ok' => true, 'job' => (int) $job_id, 'status' => 'running',
                     'message' => (string) $job['kind'] === 'video'
                        ? 'Ролик считается: обычно это минута-две.'
                        : 'Кадр считается: обычно это полминуты.');
    }

    /** Спросить шлюз о состоянии задания. */
    public static function poll($job) {
        if (!is_array($job) || (string) $job['remote_id'] === '' || !self::available()) {
            return;
        }
        $state = GS_Provider::job_state((string) $job['remote_id']);
        if (empty($state['ok'])) {
            return;
        }
        $what = strtolower((string) $state['state']);
        if (in_array($what, array('success', 'succeeded', 'completed', 'done', 'finished'), true)) {
            $urls = (array) $state['urls'];
            if ($urls) {
                GC_Gpu::finish((int) $job['id'], (string) $urls[0]);
            } else {
                GC_Gpu::fail((int) $job['id'], 'модель не вернула файл');
            }
            return;
        }
        if (in_array($what, array('fail', 'failed', 'error', 'canceled', 'cancelled'), true)) {
            $why = (string) $state['message'];
            GC_Gpu::fail((int) $job['id'], $why !== '' ? $why : 'модель вернула отказ');
        }
    }
}
