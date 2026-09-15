<?php
/**
 * Песня своим голосом: собственный голос пользователя и пение им.
 *
 * Это единственный сервис с состоянием: голос создаётся один раз и потом
 * живёт в кабинете, а песен им можно спеть сколько угодно. Поставщик ведёт
 * создание голоса в три приёма:
 *
 *   1. /voice/validate      — по образцу записи выдаёт проверочную фразу
 *   2. /voice/validate-info — фраза готова, её показываем человеку
 *   3. /voice/generate      — принимаем запись этой фразы и получаем голос
 *
 * Проверочная фраза — защита от клонирования чужого голоса: спеть её должен
 * тот же человек, чей образец загружен, и прочитать именно её.
 *
 * Пение — обычная генерация музыки, только с указанием персоны:
 * `persona_id` = идентификатор голоса, `persona_model` = voice_persona.
 */

if (!defined('ABSPATH')) {
    exit;
}

class GS_Voice {

    const API_VALIDATE      = 'https://api.kie.ai/api/v1/voice/validate';
    const API_VALIDATE_INFO = 'https://api.kie.ai/api/v1/voice/validate-info';
    const API_GENERATE      = 'https://api.kie.ai/api/v1/voice/generate';
    const API_RECORD_INFO   = 'https://api.kie.ai/api/v1/voice/record-info';
    const API_CHECK         = 'https://api.kie.ai/api/v1/voice/check-voice';
    const API_UPLOAD        = 'https://kieai.redpandaai.co/api/file-url-upload';
    const API_JOBS          = 'https://api.kie.ai/api/v1/jobs/createTask';
    const API_JOBS_INFO     = 'https://api.kie.ai/api/v1/jobs/recordInfo';

    const SONG_MODEL  = 'ai-music-api/generate';
    const META_VOICES = 'gs_voices';
    const META_SONGS  = 'gs_voice_songs';
    const SONGS_KEEP  = 50;

    /** Отрезок образца, который поставщик разбирает как вокал. */
    const SAMPLE_START = 0;
    const SAMPLE_END   = 20;

    /** Цена создания голоса — отдельная от цены песни. */
    const OPT_VOICE_COST = 'gs_voice_cost';
    const VOICE_COST     = 149;

    /* ---------------------------------------------------------------------
     * Цены
     * ------------------------------------------------------------------ */

    public static function voice_cost() {
        $cost = (float) get_option(self::OPT_VOICE_COST, self::VOICE_COST);
        return $cost > 0 ? round($cost, 2) : (float) self::VOICE_COST;
    }

    public static function song_cost() {
        return GS_Lab::get_cost('voicesong');
    }

    /* ---------------------------------------------------------------------
     * Голоса пользователя
     * ------------------------------------------------------------------ */

    /**
     * @return array<int,array{id:string,name:string,created:int}>
     */
    public static function user_voices($user_id) {
        $list = get_user_meta((int) $user_id, self::META_VOICES, true);
        if (!is_array($list)) {
            return array();
        }
        $out = array();
        foreach ($list as $row) {
            if (!empty($row['id'])) {
                $out[] = array(
                    'id'      => (string) $row['id'],
                    'name'    => (string) ($row['name'] ?? 'Мой голос'),
                    'created' => (int) ($row['created'] ?? 0),
                );
            }
        }
        return $out;
    }

    public static function remember_voice($user_id, $voice_id, $name) {
        $list = self::user_voices($user_id);
        foreach ($list as $row) {
            if ($row['id'] === $voice_id) {
                return $list;
            }
        }
        array_unshift($list, array(
            'id'      => (string) $voice_id,
            'name'    => $name !== '' ? $name : 'Мой голос',
            'created' => time(),
        ));
        $list = array_slice($list, 0, 20);
        update_user_meta((int) $user_id, self::META_VOICES, $list);
        return $list;
    }

    public static function owns_voice($user_id, $voice_id) {
        foreach (self::user_voices($user_id) as $row) {
            if ($row['id'] === (string) $voice_id) {
                return true;
            }
        }
        return false;
    }

    /* ---------------------------------------------------------------------
     * Архив песен пользователя
     *
     * Ссылки поставщика живут считаные часы, поэтому готовую песню сразу
     * копируем к себе: человек должен иметь возможность скачать её и через
     * неделю, а не только в минуту генерации.
     * ------------------------------------------------------------------ */

    /**
     * @return array<int,array{id:string,title:string,style:string,url:string,created:int,published:bool}>
     */
    public static function own_songs($user_id) {
        $list = get_user_meta((int) $user_id, self::META_SONGS, true);
        if (!is_array($list)) {
            return array();
        }
        $out = array();
        foreach ($list as $row) {
            if (empty($row['url'])) {
                continue;
            }
            $out[] = array(
                'id'        => (string) ($row['id'] ?? ''),
                'title'     => (string) ($row['title'] ?? 'Песня'),
                'style'     => (string) ($row['style'] ?? ''),
                'url'       => (string) $row['url'],
                'created'   => (int) ($row['created'] ?? 0),
                'published' => !empty($row['published']),
            );
        }
        return $out;
    }

    public static function find_song($user_id, $song_id) {
        foreach (self::own_songs($user_id) as $song) {
            if ($song['id'] === (string) $song_id) {
                return $song;
            }
        }
        return null;
    }

    /**
     * Сохраняет готовые варианты в архив.
     *
     * @param array $files Результат song_state(): label, url, kind.
     * @return array Архив после добавления.
     */
    public static function remember_songs($user_id, $files, $title, $style) {
        $list = get_user_meta((int) $user_id, self::META_SONGS, true);
        $list = is_array($list) ? $list : array();

        $n = 0;
        foreach ((array) $files as $file) {
            $url = (string) ($file['url'] ?? '');
            if ($url === '') {
                continue;
            }
            $local = GS_Songs::store_copy($url);
            if ($local === '') {
                continue; // не скопировалось — в архив не кладём битую ссылку
            }
            $n++;
            $name = trim((string) $title);
            if ($name === '') {
                $name = 'Песня';
            }
            array_unshift($list, array(
                'id'      => 'song-' . wp_generate_password(10, false, false),
                'title'   => mb_substr($name, 0, 80) . ($n > 1 ? ' — вариант ' . $n : ''),
                'style'   => mb_substr((string) $style, 0, 120),
                'url'     => $local,
                'created' => time(),
            ));
        }

        $list = array_slice($list, 0, self::SONGS_KEEP);
        update_user_meta((int) $user_id, self::META_SONGS, $list);
        return self::own_songs($user_id);
    }

    /** Отметка «уже в галерее» — чтобы не предлагать публиковать дважды. */
    public static function mark_published($user_id, $song_id) {
        $list = get_user_meta((int) $user_id, self::META_SONGS, true);
        if (!is_array($list)) {
            return;
        }
        foreach ($list as &$row) {
            if ((string) ($row['id'] ?? '') === (string) $song_id) {
                $row['published'] = true;
            }
        }
        unset($row);
        update_user_meta((int) $user_id, self::META_SONGS, $list);
    }

    /* ---------------------------------------------------------------------
     * Передача записи поставщику
     * ------------------------------------------------------------------ */

    /**
     * Ссылка на запись в хранилище поставщика.
     *
     * Подтверждение голоса делает не сам агрегатор, а музыкальный сервис за
     * ним, и записи с нашего домена он не забирает: задача навсегда зависает
     * в ожидании, без ошибки и без причины. Проверено опытом — та же запись,
     * положенная в хранилище поставщика, принимается за пятнадцать секунд.
     * Поэтому файл сначала перекладываем туда, а наружу отдаём его ссылку.
     */
    private static function hosted($url) {
        $public = GS_Lab::public_url($url);
        if ($public === '') {
            return '';
        }
        $res = self::post(self::API_UPLOAD, array(
            'fileUrl'    => $public,
            'uploadPath' => 'genius/voice',
        ));
        if (!$res['ok']) {
            return $public; // не вышло — пробуем своей ссылкой, хуже не будет
        }
        $hosted = (string) ($res['body']['data']['downloadUrl'] ?? '');
        return $hosted !== '' ? $hosted : $public;
    }

    /* ---------------------------------------------------------------------
     * Шаг 1: проверочная фраза
     * ------------------------------------------------------------------ */

    /**
     * @return array{ok:bool,task_id:string,message:string}
     */
    public static function start_phrase($audio_url, $seconds = 0) {
        $end = (int) self::SAMPLE_END;
        if ($seconds > 0) {
            $end = (int) max(5, min($end, floor($seconds)));
        }
        $res = self::post(self::API_VALIDATE, array(
            'voiceUrl'    => self::hosted($audio_url),
            'vocalStartS' => (int) self::SAMPLE_START,
            'vocalEndS'   => $end,
            'language'    => 'ru',
        ));
        $task = $res['ok'] ? (string) ($res['body']['data']['taskId'] ?? '') : '';
        return array(
            'ok'      => $res['ok'] && $task !== '',
            'task_id' => $task,
            'message' => $res['message'],
        );
    }

    /**
     * @return array{ok:bool,status:string,phrase:string,message:string}
     */
    public static function phrase_state($task_id) {
        $out = array('ok' => false, 'status' => 'pending', 'phrase' => '', 'message' => '');
        $res = self::get(self::API_VALIDATE_INFO, array('taskId' => (string) $task_id));
        if (!$res['ok']) {
            $out['message'] = $res['message'];
            return $out;
        }
        $data = is_array($res['body']['data'] ?? null) ? $res['body']['data'] : array();
        $out['ok'] = true;
        $status = (string) ($data['status'] ?? '');

        if ($status === 'wait_validating' || $status === 'success') {
            $out['status'] = 'ready';
            $out['phrase'] = (string) ($data['validateInfo'] ?? '');
            if ($out['phrase'] === '') {
                $out['status'] = 'pending';
            }
            return $out;
        }
        if ($status === 'fail' || $status === 'processing_validate_fail') {
            $out['status']  = 'failed';
            $out['message'] = self::human_error((string) ($data['errorMessage'] ?? ''));
            return $out;
        }
        return $out;
    }

    /* ---------------------------------------------------------------------
     * Шаг 2: запись фразы и создание голоса
     * ------------------------------------------------------------------ */

    public static function submit_verify($task_id, $verify_url, $name) {
        $res = self::post(self::API_GENERATE, array(
            'taskId'           => (string) $task_id,
            'verifyUrl'        => self::hosted($verify_url),
            'voiceName'        => mb_substr($name !== '' ? $name : 'Мой голос', 0, 60),
            'singerSkillLevel' => 'beginner',
        ));
        return array('ok' => $res['ok'], 'message' => $res['message']);
    }

    /**
     * @return array{ok:bool,status:string,voice_id:string,message:string}
     */
    public static function voice_state($task_id) {
        $out = array('ok' => false, 'status' => 'pending', 'voice_id' => '', 'message' => '');
        $res = self::get(self::API_RECORD_INFO, array('taskId' => (string) $task_id));
        if (!$res['ok']) {
            $out['message'] = $res['message'];
            return $out;
        }
        $data = is_array($res['body']['data'] ?? null) ? $res['body']['data'] : array();
        $out['ok'] = true;
        $status = (string) ($data['status'] ?? '');

        if ($status === 'success' && !empty($data['voiceId'])) {
            $out['status']   = 'completed';
            $out['voice_id'] = (string) $data['voiceId'];
            return $out;
        }
        if ($status === 'fail' || $status === 'processing_validate_fail') {
            $out['status']  = 'failed';
            $out['message'] = self::human_error((string) ($data['errorMessage'] ?? ''));
            return $out;
        }
        return $out;
    }

    /** Голос готов к пению — поставщик доучивает его уже после выдачи. */
    public static function is_available($task_id) {
        $res = self::post(self::API_CHECK, array('taskId' => (string) $task_id));
        if (!$res['ok']) {
            return false;
        }
        return !empty($res['body']['data']['isAvailable']);
    }

    /* ---------------------------------------------------------------------
     * Шаг 3: песня этим голосом
     * ------------------------------------------------------------------ */

    public static function create_song($voice_id, $fields) {
        $lyrics = trim((string) ($fields['lyrics'] ?? ''));
        $style  = trim((string) ($fields['style'] ?? ''));
        $title  = trim((string) ($fields['title'] ?? ''));
        $about  = trim((string) ($fields['prompt'] ?? ''));

        if ($lyrics === '' && $about === '') {
            return array('ok' => false, 'task_id' => '', 'message' => 'Напишите текст песни или опишите, о чём она');
        }

        $callback = add_query_arg('token', GS_SFX::callback_token(), rest_url(GS_Rest::NS . '/lab/callback'));

        $res = self::post(self::API_JOBS, array(
            'model'       => self::SONG_MODEL,
            'callBackUrl' => $callback,
            'input'       => array(
                'prompt'        => mb_substr($lyrics !== '' ? $lyrics : $about, 0, 2500),
                'custom_mode'   => true,
                'instrumental'  => false,
                'model'         => 'V5',
                'style'         => mb_substr($style !== '' ? $style : ($about !== '' ? $about : 'поп, тёплое настроение'), 0, 200),
                'title'         => mb_substr($title !== '' ? $title : GS_Lab::music_title($about !== '' ? $about : $lyrics), 0, 80),
                'persona_id'    => (string) $voice_id,
                'persona_model' => 'voice_persona',
            ),
        ));
        $task = $res['ok'] ? (string) ($res['body']['data']['taskId'] ?? '') : '';
        return array(
            'ok'      => $res['ok'] && $task !== '',
            'task_id' => $task,
            'message' => $res['message'],
        );
    }

    /**
     * @return array{ok:bool,status:string,files:array,message:string}
     */
    public static function song_state($task_id) {
        $out = array('ok' => false, 'status' => 'pending', 'files' => array(), 'message' => '');
        $res = self::get(self::API_JOBS_INFO, array('taskId' => (string) $task_id));
        if (!$res['ok']) {
            $out['message'] = $res['message'];
            return $out;
        }
        $data = is_array($res['body']['data'] ?? null) ? $res['body']['data'] : array();
        $out['ok'] = true;
        $state = (string) ($data['state'] ?? 'waiting');

        if ($state === 'fail') {
            $out['status']  = 'failed';
            $out['message'] = self::human_error((string) ($data['failMsg'] ?? ''));
            return $out;
        }
        if ($state !== 'success') {
            return $out;
        }

        $result = $data['resultJson'] ?? array();
        if (is_string($result)) {
            $decoded = json_decode($result, true);
            $result = is_array($decoded) ? $decoded : array();
        }
        $tracks = isset($result['data']) && is_array($result['data']) ? $result['data'] : array();
        $n = 0;
        foreach ($tracks as $track) {
            $url = (string) ($track['audio_url'] ?? '');
            if ($url === '') {
                continue;
            }
            $n++;
            $out['files'][] = array(
                'label' => 'Вариант ' . $n . (!empty($track['title']) ? ' — ' . $track['title'] : ''),
                'url'   => $url,
                'kind'  => 'audio',
            );
        }
        if (!$out['files']) {
            return $out;
        }
        $out['status'] = 'completed';
        return $out;
    }

    /* ---------------------------------------------------------------------
     * Обмен с поставщиком
     * ------------------------------------------------------------------ */

    private static function key() {
        return trim((string) get_option('kie_tts_api_key', ''));
    }

    /**
     * Поставщик регулярно отвечает «Internal Error, Please try again later»
     * и через несколько секунд принимает тот же запрос. Один отказ — не повод
     * возвращать человеку ошибку, поэтому пробуем трижды с паузой.
     */
    private static function post($url, $payload) {
        $key = self::key();
        if ($key === '') {
            return array('ok' => false, 'message' => 'Сервис не настроен', 'body' => array());
        }
        $args = array(
            'timeout' => 60,
            'headers' => array('Authorization' => 'Bearer ' . $key, 'Content-Type' => 'application/json'),
            'body'    => wp_json_encode($payload),
        );

        $res = array('ok' => false, 'message' => '', 'body' => array());
        foreach (array(0, 4, 12) as $pause) {
            if ($pause > 0) {
                sleep($pause);
            }
            $res = self::unpack(wp_remote_post($url, $args));
            if ($res['ok'] || !self::worth_retry($res)) {
                return $res;
            }
        }
        return $res;
    }

    /** Отказ, который имеет смысл повторить: временный сбой, а не отказ по сути. */
    private static function worth_retry($res) {
        $code = (int) ($res['body']['code'] ?? 0);
        $low  = mb_strtolower((string) $res['message']);
        if ($code >= 500 || $code === 429) {
            return true;
        }
        foreach (array('internal error', 'try again', 'timeout', 'timed out', 'maintain', 'temporar') as $mark) {
            if (strpos($low, $mark) !== false) {
                return true;
            }
        }
        return false;
    }

    private static function get($url, $args) {
        $key = self::key();
        if ($key === '') {
            return array('ok' => false, 'message' => 'Сервис не настроен', 'body' => array());
        }
        $response = wp_remote_get(add_query_arg($args, $url), array(
            'timeout' => 45,
            'headers' => array('Authorization' => 'Bearer ' . $key),
        ));
        return self::unpack($response);
    }

    private static function unpack($response) {
        if (is_wp_error($response)) {
            return array('ok' => false, 'message' => $response->get_error_message(), 'body' => array());
        }
        $body = json_decode((string) wp_remote_retrieve_body($response), true);
        if (!is_array($body)) {
            return array('ok' => false, 'message' => 'Некорректный ответ сервиса', 'body' => array());
        }
        if ((int) ($body['code'] ?? 0) !== 200) {
            return array(
                'ok'      => false,
                'message' => self::human_error((string) ($body['msg'] ?? '')),
                'body'    => $body,
            );
        }
        return array('ok' => true, 'message' => '', 'body' => $body);
    }

    /**
     * Ответы поставщика приходят по-английски и техническим языком.
     * Человеку нужно понимать, что делать дальше.
     */
    public static function human_error($message) {
        $text = trim((string) $message);
        $low  = mb_strtolower($text);

        if ($low === '') {
            return 'Не получилось — попробуйте ещё раз';
        }
        if (strpos($low, 'not match') !== false || strpos($low, 'mismatch') !== false
            || strpos($low, 'verify') !== false || strpos($low, 'validat') !== false) {
            return 'Запись не совпала с проверочной фразой. Прочитайте её целиком, ближе к микрофону и без фоновой музыки.';
        }
        if (strpos($low, 'too short') !== false || strpos($low, 'duration') !== false) {
            return 'Запись слишком короткая. Нужно хотя бы десять секунд чистого голоса.';
        }
        if (strpos($low, 'no vocal') !== false || strpos($low, 'vocal') !== false) {
            return 'В записи не нашёлся голос. Загрузите дорожку, где вы говорите или поёте без музыки.';
        }
        if (strpos($low, 'credit') !== false || strpos($low, 'insufficient') !== false) {
            return 'Сервис временно недоступен — сообщите нам, починим.';
        }
        // Сбой на стороне поставщика: человеку важно, что дело не в его записи
        // и что деньги уже вернулись, а не техническая формулировка.
        if (strpos($low, 'internal error') !== false || strpos($low, 'try again') !== false
            || strpos($low, 'timeout') !== false) {
            return 'Сервис создания голоса сейчас отвечает сбоем — дело не в вашей записи. Деньги вернулись на баланс, попробуйте через несколько минут.';
        }
        if (strpos($low, 'copyright') !== false || strpos($low, 'policy') !== false) {
            return 'Поставщик отклонил запись: похоже на чужой голос из известной записи. Загрузите собственный голос.';
        }
        return 'Не получилось: ' . mb_substr($text, 0, 160);
    }
}
