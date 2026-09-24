<?php
/**
 * Музыкальные операции сверх генерации трека.
 *
 * В API была одна музыкальная кнопка — «сделать трек». У поставщика их
 * тринадцать: продлить, перепеть, добавить вокал к минусовке или минусовку
 * к вокалу, выгрузить в WAV, собрать видео, вытащить слова с тайм-кодами,
 * переписать описание стиля. Всё это уже оплачено тем же ключом и тем же
 * балансом — просто не было доступно снаружи.
 *
 * Формы запросов выяснены опытом, а не по документации: поставщик называет
 * недостающее поле по одному за раз, и документация с этим расходится.
 * Поэтому здесь записано то, что он принял на самом деле.
 *
 * Две операции отвечают сразу, без очереди: тайм-коды и описание стиля.
 * Чтобы наружу они выглядели как все остальные задачи, ответ кладётся во
 * временное хранилище под своим номером, и опрос состояния читает его
 * оттуда.
 */

if (!defined('ABSPATH')) {
    exit;
}

class GS_Suno {

    const API = 'https://api.kie.ai/api/v1';

    /**
     * Реестр операций.
     *
     * price — наша цена за запуск. Себестоимость у поставщика: 12 кредитов
     * (5 ₽) у всего, что делает новый трек, 2 кредита у видео, 0,5 у
     * тайм-кодов, 0,4 у WAV и у описания стиля.
     */
    public static function operations() {
        return array(
            'music-extend' => array(
                'title'  => 'Продлить трек',
                'about'  => 'Дописывает продолжение к готовому треку с того места, где он кончается.',
                'input'  => array('task_id' => 'required', 'variant' => 'optional'),
                'result' => 'audio',
                'price'  => 59,
                'path'   => '/generate/extend',
                'info'   => '/generate/record-info',
                'needs'  => 'track',
            ),
            'music-remake' => array(
                'title'  => 'Перепеть свою запись',
                'about'  => 'Берёт вашу запись и пересобирает её в другом звучании: та же мелодия, другая музыка.',
                'input'  => array('audio_url' => 'required', 'style' => 'optional',
                                  'title' => 'optional', 'instrumental' => 'optional'),
                'result' => 'audio',
                'price'  => 59,
                'path'   => '/generate/upload-cover',
                'info'   => '/generate/record-info',
                'needs'  => 'upload',
            ),
            'music-continue' => array(
                'title'  => 'Продлить свою запись',
                'about'  => 'Дописывает продолжение к загруженному файлу с указанной секунды.',
                'input'  => array('audio_url' => 'required', 'from' => 'optional',
                                  'instrumental' => 'optional'),
                'result' => 'audio',
                'price'  => 59,
                'path'   => '/generate/upload-extend',
                'info'   => '/generate/record-info',
                'needs'  => 'upload',
            ),
            'music-instrumental' => array(
                'title'  => 'Добавить аккомпанемент',
                'about'  => 'Подкладывает музыку под запись голоса: был вокал без музыки — станет песня.',
                'input'  => array('audio_url' => 'required', 'style' => 'optional',
                                  'title' => 'optional', 'avoid' => 'optional'),
                'result' => 'audio',
                'price'  => 59,
                'path'   => '/generate/add-instrumental',
                'info'   => '/generate/record-info',
                'needs'  => 'upload',
            ),
            'music-vocals' => array(
                'title'  => 'Добавить вокал',
                'about'  => 'Допевает голос поверх инструментала: была минусовка — станет песня.',
                'input'  => array('audio_url' => 'required', 'style' => 'optional',
                                  'title' => 'optional', 'avoid' => 'optional'),
                'result' => 'audio',
                'price'  => 59,
                'path'   => '/generate/add-vocals',
                'info'   => '/generate/record-info',
                'needs'  => 'upload',
            ),
            'music-wav' => array(
                'title'  => 'Трек в WAV',
                'about'  => 'Выгружает готовый трек без сжатия — для монтажа и мастеринга.',
                'input'  => array('task_id' => 'required', 'variant' => 'optional'),
                'result' => 'audio',
                'price'  => 9,
                'path'   => '/wav/generate',
                'info'   => '/wav/record-info',
                'needs'  => 'track',
            ),
            'music-video' => array(
                'title'  => 'Видео к треку',
                'about'  => 'Собирает ролик с обложкой и бегущей звуковой волной — для соцсетей.',
                'input'  => array('task_id' => 'required', 'variant' => 'optional'),
                'result' => 'video',
                'price'  => 19,
                'path'   => '/mp4/generate',
                'info'   => '/mp4/record-info',
                'needs'  => 'track',
            ),
            'music-timestamps' => array(
                'title'  => 'Слова с тайм-кодами',
                'about'  => 'Отдаёт текст песни с отметками времени по словам — для субтитров и караоке.',
                'input'  => array('task_id' => 'required', 'variant' => 'optional'),
                'result' => 'text',
                'price'  => 9,
                'path'   => '/generate/get-timestamped-lyrics',
                'info'   => '',
                'needs'  => 'track',
                'sync'   => true,
            ),
            'music-style' => array(
                'title'  => 'Усилить описание стиля',
                'about'  => 'Переписывает короткое описание в подробное: жанр, темп, инструменты, обработка.',
                'input'  => array('prompt' => 'required'),
                'result' => 'text',
                'price'  => 5,
                'path'   => '/style/generate',
                'info'   => '',
                'needs'  => 'text',
                'sync'   => true,
            ),
        );
    }

    public static function get($id) {
        $all = self::operations();
        return isset($all[$id]) ? $all[$id] : null;
    }

    /* ---------------------------------------------------------------------
     * Постановка задачи
     * ------------------------------------------------------------------ */

    public static function start($id, $input, $callback = '') {
        $op = self::get($id);
        if (!$op) {
            return array('ok' => false, 'task_id' => '', 'message' => 'Неизвестная операция');
        }

        $payload = self::payload($id, $op, $input, $callback);
        if (isset($payload['error'])) {
            return array('ok' => false, 'task_id' => '', 'message' => (string) $payload['error']);
        }

        $res = self::post($op['path'], $payload);
        if (empty($res['ok'])) {
            return array('ok' => false, 'task_id' => '', 'message' => (string) $res['message']);
        }

        $data = isset($res['body']['data']) ? $res['body']['data'] : array();

        // Две операции отвечают сразу, без очереди. Результат отдаём наверх
        // вместе с номером задачи, чтобы он лёг в саму запись о задаче.
        // Раньше он жил во временном хранилище: не забрал за двое суток —
        // деньги списаны, ответ пропал, а задача навсегда осталась «в
        // работе». Запись о задаче живёт столько, сколько нужно.
        if (!empty($op['sync'])) {
            $ready = self::sync_result($id, $data);
            return array(
                'ok'      => true,
                'task_id' => 'gs-sync-' . wp_generate_password(24, false, false),
                'status'  => 'completed',
                'files'   => $ready['files'],
                'text'    => $ready['text'],
                'message' => '',
            );
        }

        $task = is_array($data) ? (string) ($data['taskId'] ?? '') : '';
        if ($task === '') {
            return array('ok' => false, 'task_id' => '', 'message' => 'Поставщик не вернул номер задачи');
        }
        return array('ok' => true, 'task_id' => $task, 'message' => '');
    }

    /**
     * Тело запроса под конкретную операцию.
     *
     * Названия полей у поставщика не приведены к одному виду: где-то
     * uploadUrl, где-то upload_url, стиль зовётся то style, то tags. Ровно
     * так он и принимает — приводить к красоте нечего.
     */
    private static function payload($id, $op, $input, $callback) {
        $callback = (string) $callback;
        $style = trim((string) ($input['style'] ?? ''));
        $title = trim((string) ($input['title'] ?? ''));
        $avoid = trim((string) ($input['avoid'] ?? ''));
        $audio = trim((string) ($input['audio_url'] ?? ''));
        $instrumental = !empty($input['instrumental']);

        if ($op['needs'] === 'track') {
            $source = trim((string) ($input['task_id'] ?? ''));
            if ($source === '') {
                return array('error' => 'Нужен task_id готового трека');
            }
            $variant = max(1, (int) ($input['variant'] ?? 1));
            $audio_id = self::audio_id($source, $variant);
            if ($audio_id === '') {
                return array('error' => 'Не нашёл трек по этому task_id — возможно, он уже не хранится');
            }
            $payload = array('taskId' => $source, 'audioId' => $audio_id);
            if ($id === 'music-extend') {
                // Без этого флага поставщик ждёт полный набор параметров
                // генерации, а нам нужно продолжение ровно того же трека.
                $payload['defaultParamFlag'] = false;
                $payload['model'] = 'V5';
            }
            if ($callback !== '' && empty($op['sync'])) {
                $payload['callBackUrl'] = $callback;
            }
            return $payload;
        }

        if ($op['needs'] === 'text') {
            $prompt = trim((string) ($input['prompt'] ?? ''));
            if ($prompt === '') {
                return array('error' => 'Нужно описание стиля в prompt');
            }
            return array('content' => mb_substr($prompt, 0, 1000));
        }

        if ($audio === '') {
            return array('error' => 'Нужна ссылка на файл в audio_url');
        }

        $payload = array('callBackUrl' => $callback, 'model' => 'V5');

        if ($id === 'music-remake') {
            $payload['uploadUrl'] = $audio;
            $payload['instrumental'] = $instrumental;
            if ($style !== '') { $payload['style'] = mb_substr($style, 0, 200); }
            if ($title !== '') { $payload['title'] = mb_substr($title, 0, 80); }
            return $payload;
        }
        if ($id === 'music-continue') {
            $payload['uploadUrl'] = $audio;
            $payload['instrumental'] = $instrumental;
            // Секунда, с которой продолжаем. Ноль поставщик принимает как
            // «с начала», отрицательного значения не бывает.
            $payload['continueAt'] = max(0, (int) ($input['from'] ?? 0));
            return $payload;
        }
        if ($id === 'music-instrumental') {
            $payload['uploadUrl'] = $audio;
            $payload['tags'] = mb_substr($style !== '' ? $style : 'acoustic', 0, 200);
            // Пустым это поле поставщик не принимает — отвечает отказом, хотя
            // «чего избегать» человек указывать не обязан. Подставляем то, от
            // чего хуже не станет ни одной записи.
            $payload['negativeTags'] = mb_substr($avoid !== '' ? $avoid : 'noise, distortion', 0, 200);
            $payload['title'] = mb_substr($title !== '' ? $title : 'Аккомпанемент', 0, 80);
            return $payload;
        }
        if ($id === 'music-vocals') {
            $payload['upload_url'] = $audio;
            $payload['style'] = mb_substr($style !== '' ? $style : 'pop', 0, 200);
            $payload['negativeTags'] = mb_substr($avoid !== '' ? $avoid : 'noise, distortion', 0, 200);
            $payload['title'] = mb_substr($title !== '' ? $title : 'Вокал', 0, 80);
            return $payload;
        }

        return array('error' => 'Неизвестная операция');
    }

    /**
     * Какой из двух треков задачи берём.
     *
     * За одну генерацию приходит два варианта, и человек выбирает номером,
     * а не длинным идентификатором поставщика.
     */
    private static function audio_id($task_id, $variant) {
        $res = self::get_json('/generate/record-info', array('taskId' => $task_id));
        if (empty($res['ok'])) {
            return '';
        }
        $items = $res['body']['data']['response']['sunoData'] ?? array();
        if (!is_array($items) || !$items) {
            return '';
        }
        $index = min(max(1, (int) $variant), count($items)) - 1;
        return (string) ($items[$index]['id'] ?? '');
    }

    /* ---------------------------------------------------------------------
     * Состояние
     * ------------------------------------------------------------------ */

    public static function state($id, $task_id) {
        $out = array('ok' => false, 'status' => 'pending', 'files' => array(), 'text' => '', 'message' => '');

        $op = self::get($id);
        if (!$op) {
            $out['message'] = 'Неизвестная операция';
            return $out;
        }
        if ($op['info'] === '') {
            // Синхронная операция: её ответ записан в саму задачу при
            // постановке, и опрашивать тут нечего. Сюда попадаем только если
            // запись о задаче потерялась — это отказ, а не ожидание.
            $out['ok'] = true;
            $out['status'] = 'failed';
            $out['message'] = 'Ответ этой задачи не сохранился';
            return $out;
        }

        $res = self::get_json($op['info'], array('taskId' => $task_id));
        if (empty($res['ok'])) {
            $out['message'] = (string) $res['message'];
            return $out;
        }
        $data = $res['body']['data'] ?? array();
        if (!is_array($data)) {
            return $out;
        }
        $out['ok'] = true;

        $flag = (string) ($data['successFlag'] ?? $data['status'] ?? '');
        if ($flag !== '' && (strpos($flag, 'FAILED') !== false || strpos($flag, 'ERROR') !== false)) {
            $out['status'] = 'failed';
            $out['message'] = (string) ($data['errorMsg'] ?? 'Поставщик не справился с задачей');
            return $out;
        }

        $files = self::files($id, $data);
        if ($files) {
            $out['status'] = 'completed';
            $out['files'] = $files;
        }
        return $out;
    }

    /** Ссылки на результат: у каждой операции они лежат по-своему. */
    private static function files($id, $data) {
        $resp = isset($data['response']) && is_array($data['response']) ? $data['response'] : array();
        $files = array();

        if ($id === 'music-wav') {
            $url = (string) ($resp['audio_wav_url'] ?? $resp['audioWavUrl'] ?? $data['audio_wav_url'] ?? '');
            if ($url !== '') {
                $files[] = array('label' => 'Трек в WAV', 'url' => $url, 'kind' => 'audio');
            }
            return $files;
        }
        if ($id === 'music-video') {
            $url = (string) ($resp['video_url'] ?? $resp['videoUrl'] ?? $data['video_url'] ?? '');
            if ($url !== '') {
                $files[] = array('label' => 'Ролик с треком', 'url' => $url, 'kind' => 'video');
            }
            return $files;
        }

        // Всё остальное — новый трек: приходит два варианта, отдаём оба.
        $items = $resp['sunoData'] ?? array();
        if (!is_array($items)) {
            return $files;
        }
        foreach ($items as $i => $item) {
            $url = (string) ($item['audio_url'] ?? $item['source_audio_url'] ?? '');
            if ($url === '') {
                continue;
            }
            $files[] = array(
                'label' => 'Вариант ' . ($i + 1) . (!empty($item['title']) ? ' — ' . $item['title'] : ''),
                'url'   => $url,
                'kind'  => 'audio',
            );
        }
        return $files;
    }

    /** Ответ синхронной операции в том виде, в каком его ждёт опрос состояния. */
    private static function sync_result($id, $data) {
        if ($id === 'music-style') {
            return array('text' => (string) ($data['result'] ?? ''), 'files' => array());
        }
        if ($id === 'music-timestamps') {
            $words = $data['alignedWords'] ?? array();
            if (!is_array($words) || !$words) {
                return array('text' => 'В этом треке нет слов: тайм-коды строятся только по вокалу.',
                             'files' => array());
            }
            $lines = array();
            foreach ($words as $word) {
                $start = (float) ($word['startS'] ?? $word['start_s'] ?? 0);
                $lines[] = sprintf('%02d:%05.2f  %s', (int) ($start / 60), fmod($start, 60),
                                   (string) ($word['word'] ?? ''));
            }
            return array('text' => implode("\n", $lines), 'files' => array());
        }
        return array('text' => '', 'files' => array());
    }

    /* ---------------------------------------------------------------------
     * Связь с поставщиком
     * ------------------------------------------------------------------ */

    private static function key() {
        return trim((string) get_option('kie_tts_api_key', ''));
    }

    private static function post($path, $payload) {
        $key = self::key();
        if ($key === '') {
            return array('ok' => false, 'body' => array(), 'message' => 'Не настроен доступ к поставщику');
        }
        $response = wp_remote_post(self::API . $path, array(
            'timeout' => 120,
            'headers' => array('Authorization' => 'Bearer ' . $key, 'Content-Type' => 'application/json'),
            'body'    => wp_json_encode($payload),
        ));
        return self::read($response);
    }

    private static function get_json($path, $args) {
        $key = self::key();
        if ($key === '') {
            return array('ok' => false, 'body' => array(), 'message' => 'Не настроен доступ к поставщику');
        }
        $response = wp_remote_get(add_query_arg($args, self::API . $path), array(
            'timeout' => 60,
            'headers' => array('Authorization' => 'Bearer ' . $key),
        ));
        return self::read($response);
    }

    private static function read($response) {
        if (is_wp_error($response)) {
            return array('ok' => false, 'body' => array(), 'message' => $response->get_error_message());
        }
        $body = json_decode((string) wp_remote_retrieve_body($response), true);
        if (!is_array($body)) {
            return array('ok' => false, 'body' => array(), 'message' => 'Поставщик ответил неразборчиво');
        }
        $code = (int) ($body['code'] ?? 0);
        if ($code !== 200) {
            return array('ok' => false, 'body' => $body, 'message' => (string) ($body['msg'] ?? 'Отказ поставщика'));
        }
        return array('ok' => true, 'body' => $body, 'message' => '');
    }
}
