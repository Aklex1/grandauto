<?php
/**
 * Микросервисы обработки аудио и видео поверх того же агрегатора и баланса,
 * что и студия звуков: оживление фото (липсинк), удаление вокала и очистка записи.
 *
 * Каждый сервис — отдельная посадочная страница под собранную семантику,
 * с перелинковкой на соседние сервисы.
 */

if (!defined('ABSPATH')) {
    exit;
}

class GS_Lab {

    const API_JOBS        = 'https://api.kie.ai/api/v1/jobs/createTask';
    const API_JOBS_INFO   = 'https://api.kie.ai/api/v1/jobs/recordInfo';
    const API_VOCAL       = 'https://api.kie.ai/api/v1/vocal-removal/generate';
    const API_VOCAL_INFO  = 'https://api.kie.ai/api/v1/vocal-removal/record-info';

    const UPLOAD_DIR = 'uploads';

    /** Максимальные размеры загрузки: ограничения самого агрегатора. */
    const MAX_IMAGE_BYTES = 10485760;  // 10 МБ
    const MAX_AUDIO_BYTES = 10485760;  // 10 МБ (audio-isolation), для вокала — 20 МБ
    const MAX_AUDIO_BYTES_VOCAL = 20971520;

    public static function boot() {
        add_filter('body_class', array(__CLASS__, 'body_class'));
    }

    /* ---------------------------------------------------------------------
     * Реестр сервисов
     * ------------------------------------------------------------------ */

    /**
     * Ключевые слова в текстах взяты из подбора по базе Mutagen:
     * в скобках — конкуренция / частота.
     */
    public static function services() {
        return array(
            'avatar' => array(
                'id'          => 'avatar',
                'slug'        => 'govoryashchiy-avatar',
                'page_option' => 'gs_lab_page_avatar',
                'menu'        => 'Говорящий аватар',
                'nav'         => 'Говорящий аватар',
                'h1'          => 'Говорящий аватар из фото: видео, где человек со снимка говорит',
                'seo_title'   => 'Говорящий аватар из фото — сделать видео из фото с озвучкой',
                'seo_desc'    => 'Говорящий аватар из фото за пару минут: загрузите фотографию и запись голоса — нейросеть синхронизирует губы с речью и отдаст видео в MP4. Без установки программ и монтажа.',
                'lead'        => 'Загрузите фотографию и аудио с речью — нейросеть синхронизирует губы с голосом. На выходе видео, где человек со снимка говорит вашим текстом: для аватара канала, приветствия на сайте или поздравления.',
                'badge'       => 'Липсинк по фото и голосу',
                'cost_option' => 'gs_lab_cost_avatar',
                'cost'        => 90,
                'available'   => true,
                'inputs'      => array('image', 'audio'),
                'accept'      => array(
                    'image' => 'image/jpeg,image/png',
                    'audio' => 'audio/mpeg,audio/wav,audio/x-wav,audio/aac,audio/mp4,audio/ogg',
                ),
                'prompt'      => true,
                'prompt_hint' => 'Необязательно: опишите манеру речи или план кадра — например «спокойно рассказывает, крупный план».',
                'result_kind' => 'video',
                'poll_seconds'=> 600,
                'steps'       => array(
                    'Загрузите портрет: лицо крупно, анфас, без очков и головных уборов.',
                    'Добавьте аудио с речью — своё или сгенерированное в озвучке.',
                    'Нажмите «Сделать видео» и подождите: ролик собирается несколько минут.',
                ),
                'faq'         => array(
                    array('Какое фото подойдёт, чтобы оживить его нейросетью?',
                          'Лучше всего работает портрет анфас, где лицо занимает заметную часть кадра и хорошо освещено. Форматы JPEG и PNG, до 10 МБ. Снимки в профиль, в тёмных очках или с перекрытым ртом дают заметно хуже результат.'),
                    array('Можно ли сделать говорящее видео из старого фото?',
                          'Да, старые и отсканированные снимки работают, если лицо различимо. Архивную фотографию лучше сначала восстановить и повысить резкость в разделе «Нейросети», а уже потом загружать сюда для озвучки.'),
                    array('Где взять голос для видео?',
                          'Подойдёт любая запись речи до 5 минут: диктофон, кружок из мессенджера или файл из нашей озвучки текста. Если запись шумная, прогоните её через очистку звука — липсинк будет точнее.'),
                    array('Сколько длится генерация?',
                          'Обычно от одной до нескольких минут, в зависимости от длины аудио. Страницу можно не держать открытой: готовый ролик остаётся в истории.'),
                ),
            ),

            'vocal' => array(
                'id'          => 'vocal',
                'slug'        => 'ubrat-vokal',
                'page_option' => 'gs_lab_page_vocal',
                'menu'        => 'Убрать вокал',
                'nav'         => 'Убрать вокал',
                'h1'          => 'Убрать вокал из песни онлайн: минусовка за пару минут',
                'seo_title'   => 'Убрать вокал из песни онлайн — сделать минусовку бесплатно',
                'seo_desc'    => 'Уберите вокал из песни онлайн и получите минусовку: нейросеть отделит голос от музыки и отдаст две дорожки — инструментал и вокал. Загрузите трек и скачайте результат в MP3.',
                'lead'        => 'Загрузите трек — нейросеть отделит вокал от музыки и вернёт две дорожки: чистый инструментал для караоке и отдельно голос. Ничего устанавливать не нужно.',
                'badge'       => 'Разделение дорожек',
                'cost_option' => 'gs_lab_cost_vocal',
                'cost'        => 25,
                'available'   => false,
                'blocked_note'=> 'Разделение дорожек подключается: у текущего поставщика моделей эта операция работает только с треками, сгенерированными им самим, и не принимает загруженные файлы. Ищем провайдера, который умеет разделять любые записи.',
                'inputs'      => array('audio'),
                'accept'      => array(
                    'audio' => 'audio/mpeg,audio/wav,audio/x-wav,audio/aac,audio/mp4,audio/ogg',
                ),
                'prompt'      => false,
                'result_kind' => 'stems',
                'poll_seconds'=> 600,
                'steps'       => array(
                    'Загрузите песню в MP3 или WAV — до 20 МБ.',
                    'Нажмите «Разделить дорожки» и подождите пару минут.',
                    'Скачайте минусовку и отдельно вокал.',
                ),
                'faq'         => array(
                    array('Как убрать вокал из песни без потери качества?',
                          'Нейросеть не вырезает частоты, как старые «инверсия фазы» и эквалайзеры, а заново собирает дорожки. Поэтому инструментал остаётся полным, а не глухим. Качество исходника всё же важно: из 128 kbps результат будет хуже, чем из 320 kbps или WAV.'),
                    array('Чем это отличается от минусовки, скачанной в интернете?',
                          'Готовые минусовки есть только у популярных песен и часто в плохом качестве. Здесь минусовка делается из вашего файла — из любой песни, включая редкие и авторские.'),
                    array('Можно ли наоборот оставить только голос?',
                          'Да, сервис отдаёт обе дорожки сразу: и инструментал, и вокал. Отдельный файл с голосом удобен для ремиксов, разборов и караоке-бэков.'),
                    array('Подойдёт ли результат для караоке?',
                          'Да, инструментальная дорожка — это и есть готовый минус для караоке. Если в песне плотный бэк-вокал, его частично может унести вместе с основным голосом.'),
                ),
            ),

            'denoise' => array(
                'id'          => 'denoise',
                'slug'        => 'ubrat-shum',
                'page_option' => 'gs_lab_page_denoise',
                'menu'        => 'Убрать шум',
                'nav'         => 'Убрать шум',
                'h1'          => 'Убрать шум из аудио онлайн: очистка записи голоса',
                'seo_title'   => 'Убрать шум из аудио онлайн — очистить запись голоса от шума',
                'seo_desc'    => 'Уберите шум из аудио онлайн: нейросеть отделит голос от фонового гула, эха и уличного шума. Загрузите запись или дорожку из видео и скачайте чистый голос в MP3.',
                'lead'        => 'Загрузите запись — нейросеть уберёт фоновый гул, шум улицы, шипение микрофона и оставит чистый голос. Подходит для интервью, созвонов, голосовых и звука из видео.',
                'badge'       => 'Шумоподавление',
                'cost_option' => 'gs_lab_cost_denoise',
                'cost'        => 20,
                'available'   => false,
                'blocked_note'=> 'Очистка звука временно недоступна: модель шумоподавления у поставщика отвечает ошибкой даже на его собственных примерах. Включим сразу, как только он починит.',
                'inputs'      => array('audio'),
                'accept'      => array(
                    'audio' => 'audio/mpeg,audio/wav,audio/x-wav,audio/aac,audio/mp4,audio/ogg',
                ),
                'prompt'      => false,
                'result_kind' => 'audio',
                'poll_seconds'=> 420,
                'steps'       => array(
                    'Загрузите запись в MP3 или WAV — до 10 МБ.',
                    'Нажмите «Очистить запись».',
                    'Послушайте результат и скачайте чистый голос.',
                ),
                'faq'         => array(
                    array('Какой шум убирает нейросеть?',
                          'Ровный фон — гул техники, кондиционер, шум улицы и трафика, шипение дешёвого микрофона, ветер. Отделяет речь от фона целиком, а не режет частоты, поэтому голос не становится «подводным».'),
                    array('Можно ли убрать эхо из записи?',
                          'Сильную реверберацию комнаты нейросеть заметно уменьшает, но полностью «сухим» голос из гулкого помещения не сделает. Чем ближе микрофон был ко рту, тем лучше результат.'),
                    array('Как почистить звук в видео?',
                          'Извлеките аудиодорожку любым конвертером, очистите её здесь и подставьте обратно в монтаже. Форматы MP4 и OGG тоже принимаются напрямую.'),
                    array('Останется ли качество записи?',
                          'На выходе MP3 с исходной длительностью. Речь становится разборчивее, но нейросеть не добавляет того, чего в записи не было: полностью заглушенные шумом слова не восстановятся.'),
                ),
            ),
        );
    }

    public static function get_service($id) {
        $services = self::services();
        return isset($services[$id]) ? $services[$id] : null;
    }

    /**
     * Доступность сервиса. Дефолт берётся из реестра, но переключается в админке:
     * часть моделей у поставщика то появляется, то отваливается.
     */
    public static function is_available($id) {
        $service = self::get_service($id);
        if (!$service) {
            return false;
        }
        $option = get_option('gs_lab_enabled_' . $id, null);
        if ($option === null || $option === '') {
            return !empty($service['available']);
        }
        return (string) $option === '1';
    }

    /**
     * Только рабочие сервисы — для меню, подвала и карты сайта.
     */
    public static function available_services() {
        $out = array();
        foreach (self::services() as $id => $service) {
            if (self::is_available($id)) {
                $out[$id] = $service;
            }
        }
        return $out;
    }

    public static function get_cost($id) {
        $service = self::get_service($id);
        if (!$service) {
            return 0.0;
        }
        $cost = (float) get_option($service['cost_option'], $service['cost']);
        return $cost > 0 ? round($cost, 2) : (float) $service['cost'];
    }

    /* ---------------------------------------------------------------------
     * Страницы
     * ------------------------------------------------------------------ */

    public static function ensure_pages() {
        foreach (self::services() as $service) {
            $page_id = (int) get_option($service['page_option']);
            if ($page_id > 0 && get_post($page_id)) {
                continue;
            }
            $shortcode = '[genius_lab id="' . $service['id'] . '"]';
            $existing = get_page_by_path($service['slug']);
            if ($existing) {
                $page_id = (int) $existing->ID;
                wp_update_post(array(
                    'ID'           => $page_id,
                    'post_title'   => $service['seo_title'],
                    'post_content' => $shortcode,
                    'post_status'  => 'publish',
                ));
            } else {
                $page_id = (int) wp_insert_post(array(
                    'post_title'   => $service['seo_title'],
                    'post_content' => $shortcode,
                    'post_status'  => 'publish',
                    'post_type'    => 'page',
                    'post_name'    => $service['slug'],
                ));
            }
            if ($page_id > 0) {
                update_option($service['page_option'], $page_id);
            }
        }
    }

    public static function get_url($id) {
        $service = self::get_service($id);
        if (!$service) {
            return '';
        }
        $page_id = (int) get_option($service['page_option']);
        $url = $page_id > 0 ? get_permalink($page_id) : '';
        return $url ? $url : home_url('/' . $service['slug'] . '/');
    }

    /**
     * Сервис текущего запроса, если открыта его посадочная страница.
     *
     * @return array|null
     */
    public static function current_service() {
        if (is_admin()) {
            return null;
        }
        foreach (self::services() as $service) {
            $page_id = (int) get_option($service['page_option']);
            if ($page_id > 0 && is_page($page_id)) {
                return $service;
            }
        }
        global $post;
        if ($post instanceof WP_Post && has_shortcode((string) $post->post_content, 'genius_lab')) {
            foreach (self::services() as $service) {
                if (strpos((string) $post->post_content, 'id="' . $service['id'] . '"') !== false) {
                    return $service;
                }
            }
        }
        return null;
    }

    public static function body_class($classes) {
        if (self::current_service()) {
            $classes[] = 'gs-lab-page';
            $classes[] = 'gs-studio-page';
            $classes[] = 'gs-chrome';
        }
        return $classes;
    }

    /* ---------------------------------------------------------------------
     * Загрузка файлов
     * ------------------------------------------------------------------ */

    public static function uploads_dir() {
        return GS_Storage::base_dir() . '/' . self::UPLOAD_DIR;
    }

    public static function uploads_url() {
        return GS_Storage::base_url() . '/' . self::UPLOAD_DIR;
    }

    /**
     * Сохраняет присланный файл и возвращает публичный URL:
     * агрегатору нужен именно адрес, а не содержимое.
     *
     * @return array{ok:bool,url:string,message:string}
     */
    public static function store_upload($file, $kind, $max_bytes) {
        $fail = function ($message) {
            return array('ok' => false, 'url' => '', 'message' => $message);
        };

        if (!is_array($file) || empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            return $fail('Файл не получен');
        }
        if ((int) $file['size'] > $max_bytes) {
            return $fail('Файл больше ' . round($max_bytes / 1048576) . ' МБ');
        }

        $check = wp_check_filetype_and_ext($file['tmp_name'], (string) $file['name']);
        $ext = $check['ext'] ? $check['ext'] : strtolower((string) pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowed = $kind === 'image'
            ? array('jpg', 'jpeg', 'png')
            : array('mp3', 'wav', 'aac', 'm4a', 'mp4', 'ogg', 'oga');
        if (!in_array($ext, $allowed, true)) {
            return $fail('Неподдерживаемый формат: ' . $ext);
        }

        $dir = self::uploads_dir() . '/' . gmdate('Y-m');
        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
        }
        $name = $kind . '-' . wp_generate_password(18, false, false) . '.' . $ext;
        $target = $dir . '/' . $name;

        if (!@move_uploaded_file($file['tmp_name'], $target)) {
            return $fail('Не удалось сохранить файл');
        }
        @chmod($target, 0644);

        return array(
            'ok'      => true,
            'url'     => self::uploads_url() . '/' . gmdate('Y-m') . '/' . $name,
            'message' => '',
        );
    }

    /* ---------------------------------------------------------------------
     * Вызовы агрегатора
     * ------------------------------------------------------------------ */

    private static function api_key() {
        return trim((string) get_option('kie_tts_api_key', ''));
    }

    private static function post_json($url, $payload) {
        $key = self::api_key();
        if ($key === '') {
            return array('ok' => false, 'message' => 'Генерация временно недоступна: не настроен доступ к сервису', 'body' => array());
        }
        $response = wp_remote_post($url, array(
            'timeout' => 45,
            'headers' => array(
                'Authorization' => 'Bearer ' . $key,
                'Content-Type'  => 'application/json',
            ),
            'body'    => wp_json_encode($payload),
        ));
        if (is_wp_error($response)) {
            return array('ok' => false, 'message' => $response->get_error_message(), 'body' => array());
        }
        $body = json_decode((string) wp_remote_retrieve_body($response), true);
        if (!is_array($body)) {
            return array('ok' => false, 'message' => 'Некорректный ответ сервиса генерации', 'body' => array());
        }
        if ((int) ($body['code'] ?? 0) !== 200) {
            return array('ok' => false, 'message' => (string) ($body['msg'] ?? 'Сервис генерации вернул ошибку'), 'body' => $body);
        }
        return array('ok' => true, 'message' => '', 'body' => $body);
    }

    private static function get_json($url, $args) {
        $key = self::api_key();
        if ($key === '') {
            return array('ok' => false, 'message' => 'Генерация временно недоступна', 'body' => array());
        }
        $response = wp_remote_get(add_query_arg($args, $url), array(
            'timeout' => 45,
            'headers' => array('Authorization' => 'Bearer ' . $key),
        ));
        if (is_wp_error($response)) {
            return array('ok' => false, 'message' => $response->get_error_message(), 'body' => array());
        }
        $body = json_decode((string) wp_remote_retrieve_body($response), true);
        if (!is_array($body) || !isset($body['data'])) {
            return array('ok' => false, 'message' => 'Некорректный ответ сервиса генерации', 'body' => array());
        }
        return array('ok' => true, 'message' => '', 'body' => $body);
    }

    /**
     * Ставит задачу сервиса.
     *
     * @return array{ok:bool,task_id:string,message:string}
     */
    public static function create_task($id, $params) {
        $callback = add_query_arg('token', GS_SFX::callback_token(), rest_url(GS_Rest::NS . '/lab/callback'));

        if ($id === 'avatar') {
            $res = self::post_json(self::API_JOBS, array(
                'model'       => 'kling/ai-avatar-pro',
                'callBackUrl' => $callback,
                'input'       => array(
                    'image_url' => (string) $params['image_url'],
                    'audio_url' => (string) $params['audio_url'],
                    'prompt'    => (string) ($params['prompt'] ?? ''),
                ),
            ));
            $task = $res['ok'] ? (string) ($res['body']['data']['taskId'] ?? '') : '';
            return array('ok' => $res['ok'] && $task !== '', 'task_id' => $task, 'message' => $res['message']);
        }

        if ($id === 'denoise') {
            $res = self::post_json(self::API_JOBS, array(
                'model'       => 'elevenlabs/audio-isolation',
                'callBackUrl' => $callback,
                'input'       => array('audio_url' => (string) $params['audio_url']),
            ));
            $task = $res['ok'] ? (string) ($res['body']['data']['taskId'] ?? '') : '';
            return array('ok' => $res['ok'] && $task !== '', 'task_id' => $task, 'message' => $res['message']);
        }

        if ($id === 'vocal') {
            $res = self::post_json(self::API_VOCAL, array(
                'audioUrl'    => (string) $params['audio_url'],
                'audioId'     => 'gs-' . wp_generate_password(16, false, false),
                'type'        => 'separate_vocal',
                'stemName'    => 'Vocals',
                'callBackUrl' => $callback,
            ));
            $task = $res['ok'] ? (string) ($res['body']['data']['taskId'] ?? '') : '';
            return array('ok' => $res['ok'] && $task !== '', 'task_id' => $task, 'message' => $res['message']);
        }

        return array('ok' => false, 'task_id' => '', 'message' => 'Неизвестный сервис');
    }

    /**
     * Статус задачи.
     *
     * @return array{ok:bool,status:string,files:array,message:string}
     */
    public static function fetch_task($id, $task_id) {
        $out = array('ok' => false, 'status' => 'pending', 'files' => array(), 'message' => '');

        if ($id === 'vocal') {
            $res = self::get_json(self::API_VOCAL_INFO, array('taskId' => $task_id));
            if (!$res['ok']) {
                $out['message'] = $res['message'];
                return $out;
            }
            $data = $res['body']['data'];
            $flag = (string) ($data['successFlag'] ?? '');
            $out['ok'] = true;

            if (in_array($flag, array('CREATE_TASK_FAILED', 'GENERATE_AUDIO_FAILED', 'CALLBACK_EXCEPTION'), true)) {
                $out['status'] = 'failed';
                $out['message'] = 'Не удалось разделить дорожки';
                return $out;
            }
            $resp = isset($data['response']) && is_array($data['response']) ? $data['response'] : array();
            if (!empty($resp['instrumentalUrl']) || !empty($resp['vocalUrl'])) {
                $out['status'] = 'completed';
                if (!empty($resp['instrumentalUrl'])) {
                    $out['files'][] = array('label' => 'Минусовка (инструментал)', 'url' => (string) $resp['instrumentalUrl'], 'kind' => 'audio');
                }
                if (!empty($resp['vocalUrl'])) {
                    $out['files'][] = array('label' => 'Вокал без музыки', 'url' => (string) $resp['vocalUrl'], 'kind' => 'audio');
                }
            }
            return $out;
        }

        $res = self::get_json(self::API_JOBS_INFO, array('taskId' => $task_id));
        if (!$res['ok']) {
            $out['message'] = $res['message'];
            return $out;
        }
        $data = $res['body']['data'];
        $out['ok'] = true;
        $state = (string) ($data['state'] ?? 'waiting');

        if ($state === 'fail') {
            $out['status'] = 'failed';
            $out['message'] = (string) ($data['failMsg'] ?? 'Генерация не удалась');
            return $out;
        }
        if ($state !== 'success') {
            return $out;
        }

        $result = isset($data['resultJson']) ? $data['resultJson'] : array();
        if (is_string($result)) {
            $decoded = json_decode($result, true);
            $result = is_array($decoded) ? $decoded : array();
        }
        $urls = array();
        if (!empty($result['resultUrls'])) {
            $urls = is_array($result['resultUrls']) ? $result['resultUrls'] : array($result['resultUrls']);
        }
        if (empty($urls)) {
            return $out;
        }

        $service = self::get_service($id);
        $kind = ($service && $service['result_kind'] === 'video') ? 'video' : 'audio';
        $label = $kind === 'video' ? 'Готовое видео' : 'Очищенная запись';
        foreach ($urls as $url) {
            $out['files'][] = array('label' => $label, 'url' => (string) $url, 'kind' => $kind);
        }
        $out['status'] = 'completed';
        return $out;
    }

    /**
     * Копируем результат к себе: ссылки агрегатора живут ограниченное время.
     */
    public static function store_result($task_id, $url, $kind) {
        $task_id = preg_replace('~[^a-zA-Z0-9_-]~', '', (string) $task_id);
        if ($task_id === '' || $url === '') {
            return '';
        }
        GS_Storage::ensure_dirs();

        $ext = strtolower((string) pathinfo(wp_parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));
        if (!in_array($ext, array('mp3', 'wav', 'mp4', 'ogg', 'm4a'), true)) {
            $ext = $kind === 'video' ? 'mp4' : 'mp3';
        }
        $name = $task_id . '-' . substr(md5($url), 0, 8) . '.' . $ext;
        $target = GS_Storage::generated_dir() . '/' . $name;

        if (file_exists($target) && filesize($target) > 1024) {
            return GS_Storage::generated_url() . '/' . $name;
        }
        $response = wp_remote_get($url, array('timeout' => 180));
        if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) !== 200) {
            return '';
        }
        $body = wp_remote_retrieve_body($response);
        if (strlen((string) $body) < 1024) {
            return '';
        }
        if (!GS_Storage::atomic_put($target, $body)) {
            return '';
        }
        return GS_Storage::generated_url() . '/' . $name;
    }
}
