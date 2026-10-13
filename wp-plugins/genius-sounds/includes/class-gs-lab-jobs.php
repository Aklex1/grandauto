<?php
/**
 * Заказанная генерация доходит до человека.
 *
 * Раньше результат забирала страница: браузер опрашивал статус и только
 * в этот момент файл перекладывался к нам, записывался в журнал и
 * показывался. Стоило закрыть вкладку — и забирать становилось некому.
 * Задача у поставщика доигрывала, деньги были списаны, а человек не
 * получал ничего: ни файла, ни истории, ни отказа. Так и вышло с
 * говорящим аватаром — ролик лежал готовым, пока его не спросили руками.
 *
 * Теперь заказ живёт в журнале с момента нажатия кнопки. Страница лишь
 * показывает то, что в журнале уже есть, а доводит заказ до конца
 * расписание: раз в пять минут оно само забирает всё, что доиграло.
 * Закрытая вкладка больше ничего не решает.
 *
 * Готовое помечается непрочитанным, пока человек его не увидел, —
 * отсюда и уведомление, и отметка о прочтении.
 */

if (!defined('ABSPATH')) {
    exit;
}

class GS_Lab_Jobs {

    const CRON = 'gs_lab_jobs';

    /** Сколько заказов разбираем за один заход расписания. */
    const SWEEP = 25;

    /** Сколько последних заказов храним в истории сервиса. */
    const KEEP = 24;

    /**
     * Сколько ещё ждём задачу, по которой нет ответа.
     *
     * Через сутки поставщик свою задачу уже не помнит, и спрашивать
     * нечего: запись закрываем отказом и возвращаем деньги.
     */
    const GIVE_UP_HOURS = 24;

    public static function boot() {
        add_filter('cron_schedules', array(__CLASS__, 'schedule'));
        add_action(self::CRON, array(__CLASS__, 'sweep'));
        if (!wp_next_scheduled(self::CRON)) {
            wp_schedule_event(time() + 120, 'gs_five_minutes', self::CRON);
        }
    }

    public static function schedule($list) {
        if (empty($list['gs_five_minutes'])) {
            $list['gs_five_minutes'] = array('interval' => 300, 'display' => 'Каждые пять минут');
        }
        return $list;
    }

    /* ---------------------------------------------------------------------
     * Хранилище истории
     *
     * Держим по сервису в метаданных пользователя: строк немного, они
     * нужны только своему хозяину, и отдельная таблица тут не окупается.
     * ------------------------------------------------------------------ */

    private static function key($service_id) {
        return 'gs_lab_history_' . sanitize_key($service_id);
    }

    /**
     * Строка истории в нынешнем виде.
     *
     * До журнала строка хранила один файл и подпись. Старые записи
     * трогать незачем — приводим их к нынешнему виду при чтении.
     */
    private static function normalize($row) {
        if (!is_array($row)) {
            return null;
        }
        if (isset($row['files']) && is_array($row['files'])) {
            $row['files'] = array_values(array_filter($row['files'], function ($f) {
                return is_array($f) && !empty($f['url']);
            }));
            $row['task']    = (string) ($row['task'] ?? '');
            $row['status']  = (string) ($row['status'] ?? 'ready');
            $row['note']    = (string) ($row['note'] ?? '');
            $row['at']      = (string) ($row['at'] ?? '');
            $row['seen']    = (string) ($row['seen'] ?? '');
            $row['text']    = (string) ($row['text'] ?? '');
            $row['message'] = (string) ($row['message'] ?? '');
            return $row;
        }
        $url = (string) ($row['url'] ?? '');
        if ($url === '') {
            return null;
        }
        return array(
            'task'    => '',
            'status'  => 'ready',
            'note'    => (string) ($row['note'] ?? ''),
            'at'      => (string) ($row['at'] ?? ''),
            // Старое человек уже видел: показывать по нему уведомление
            // спустя месяцы — пугать на ровном месте.
            'seen'    => (string) ($row['at'] ?? '-'),
            'text'    => '',
            'message' => '',
            'files'   => array(array(
                'url'   => $url,
                'kind'  => (string) ($row['kind'] ?? 'image'),
                'label' => '',
            )),
        );
    }

    public static function history($user_id, $service_id) {
        $user_id = (int) $user_id;
        if ($user_id <= 0 || !GS_Lab::get_service($service_id)) {
            return array();
        }
        self::backfill($user_id);
        $rows = get_user_meta($user_id, self::key($service_id), true);
        if (!is_array($rows)) {
            return array();
        }
        $out = array();
        foreach ($rows as $row) {
            $row = self::normalize($row);
            if ($row) {
                $out[] = $row;
            }
        }
        return $out;
    }

    /**
     * Прошлые заказы — в историю.
     *
     * Журнал завели сегодня, а люди заказывали и раньше: у них история
     * была бы пустой, хотя заплачено. Записи о каждом заказе лежат в
     * общей таблице генераций — переносим их оттуда, по разу на человека.
     *
     * Свежее готовое помечаем непрочитанным: ровно оно и есть то, что до
     * человека не дошло. Давнее помечаем прочитанным — всплывающая
     * плашка о позапрошлой неделе только путает.
     */
    private static function backfill($user_id) {
        static $done = array();
        $user_id = (int) $user_id;
        if (isset($done[$user_id])) {
            return;
        }
        $done[$user_id] = true;
        if (get_user_meta($user_id, 'gs_lab_backfill', true)) {
            return;
        }
        // Метку ставим до переноса: если он оборвётся, второй заход не
        // задвоит строки, а недостающее допишет обычная работа журнала.
        update_user_meta($user_id, 'gs_lab_backfill', current_time('mysql'));
        if (!class_exists('KIE_TTS_DB')) {
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'kie_tts_generations';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT task_id, voice, status, audio_url, created_at
               FROM {$table}
              WHERE user_id = %d AND voice LIKE %s
           ORDER BY id DESC
              LIMIT 200",
            $user_id,
            'lab:%'
        ), ARRAY_A);
        if (!is_array($rows) || !$rows) {
            return;
        }

        $fresh_after = time() - 3 * DAY_IN_SECONDS;
        $by_service = array();
        foreach ($rows as $row) {
            $service_id = substr((string) $row['voice'], 4);
            $service = GS_Lab::get_service($service_id);
            if (!$service) {
                continue;
            }
            $status = (string) $row['status'];
            $url = (string) $row['audio_url'];
            if ($status === 'completed' && $url === '') {
                // Готово, но файла не осталось — показывать нечего.
                continue;
            }
            $at = (string) $row['created_at'];
            $ready = $status === 'completed';
            $by_service[$service_id][] = array(
                'task'    => (string) $row['task_id'],
                'status'  => $ready ? 'ready' : ($status === 'failed' ? 'failed' : 'pending'),
                'note'    => '',
                'at'      => $at,
                // Незакрытый заказ не видел никто: он ещё ничем не кончился.
                // Давнее готовое помечаем виденным — всплывать плашкой о
                // позапрошлой неделе ни к чему.
                'seen'    => ($status === 'failed' || ($ready && strtotime($at) <= $fresh_after)) ? $at : '',
                'text'    => '',
                'message' => $status === 'failed' ? 'Средства возвращены на баланс' : '',
                'files'   => $url !== '' ? array(array(
                    'url'   => $url,
                    'kind'  => (string) $service['result_kind'],
                    'label' => '',
                )) : array(),
            );
        }

        foreach ($by_service as $service_id => $old) {
            $have = get_user_meta($user_id, self::key($service_id), true);
            $have = is_array($have) ? $have : array();
            $seen_tasks = array();
            foreach ($have as $row) {
                if (!empty($row['task'])) {
                    $seen_tasks[(string) $row['task']] = true;
                }
            }
            foreach ($old as $row) {
                if (!isset($seen_tasks[$row['task']])) {
                    $have[] = $row;
                }
            }
            usort($have, function ($a, $b) {
                return strcmp((string) ($b['at'] ?? ''), (string) ($a['at'] ?? ''));
            });
            update_user_meta($user_id, self::key($service_id), array_slice($have, 0, self::KEEP));
        }
    }

    private static function save($user_id, $service_id, array $rows) {
        update_user_meta((int) $user_id, self::key($service_id), array_slice(array_values($rows), 0, self::KEEP));
    }

    /**
     * Заказ принят — строка появляется сразу.
     *
     * Именно поэтому заказ не теряется: он виден в истории ещё до того,
     * как поставщик что-то ответил, и человеку не нужно помнить, что он
     * запускал полчаса назад.
     */
    public static function remember_order($user_id, $service_id, $task_id, $note = '', $cost = 0) {
        $user_id = (int) $user_id;
        $task_id = (string) $task_id;
        if ($user_id <= 0 || $task_id === '' || !GS_Lab::get_service($service_id)) {
            return;
        }
        $rows = self::history($user_id, $service_id);
        foreach ($rows as $row) {
            if ($row['task'] === $task_id) {
                return;
            }
        }
        array_unshift($rows, array(
            'task'    => $task_id,
            'status'  => 'pending',
            'note'    => mb_substr((string) $note, 0, 120),
            'at'      => current_time('mysql'),
            'seen'    => '',
            'text'    => '',
            'message' => '',
            'cost'    => (float) $cost,
            'files'   => array(),
        ));
        self::save($user_id, $service_id, $rows);
    }

    /** Заказ закрыт: готов или не вышел. */
    public static function finish_order($user_id, $service_id, $task_id, $status, array $files, $text = '', $message = '', $note = '') {
        $user_id = (int) $user_id;
        $task_id = (string) $task_id;
        if ($user_id <= 0 || !GS_Lab::get_service($service_id)) {
            return;
        }
        $clean = array();
        foreach ($files as $file) {
            $url = (string) ($file['url'] ?? '');
            if ($url === '') {
                continue;
            }
            $clean[] = array(
                'url'   => $url,
                'kind'  => (string) ($file['kind'] ?? 'file'),
                'label' => (string) ($file['label'] ?? ''),
            );
        }
        if ($status === 'ready' && !$clean && trim((string) $text) === '') {
            // Готово без содержимого — показывать нечего, и за готовое
            // такую строку выдавать нельзя.
            $status = 'failed';
            $message = $message !== '' ? $message : 'Результат не пришёл';
        }

        $rows = self::history($user_id, $service_id);
        $found = false;
        foreach ($rows as $i => $row) {
            if ($row['task'] !== $task_id || $task_id === '') {
                continue;
            }
            $found = true;
            if ($row['status'] !== 'pending') {
                // Уже закрыта: страница и расписание нередко приходят к
                // готовому почти разом. Второй раз не трогаем, иначе
                // сбросится отметка о прочтении.
                return;
            }
            $rows[$i]['status']  = $status;
            $rows[$i]['files']   = $clean;
            $rows[$i]['text']    = (string) $text;
            $rows[$i]['message'] = (string) $message;
            $rows[$i]['done']    = current_time('mysql');
            // Результат появился только что — значит, его ещё никто не
            // видел, какой бы отметка ни была у строки «в работе».
            $rows[$i]['seen']    = '';
            break;
        }
        if (!$found) {
            // Строки заказа нет — значит, он заведён до журнала. Подпись
            // берём из служебной записи, иначе её взять уже негде.
            array_unshift($rows, array(
                'task'    => $task_id,
                'status'  => $status,
                'note'    => mb_substr((string) $note, 0, 120),
                'at'      => current_time('mysql'),
                'done'    => current_time('mysql'),
                'seen'    => '',
                'text'    => (string) $text,
                'message' => (string) $message,
                'files'   => $clean,
            ));
        }
        self::save($user_id, $service_id, $rows);
    }

    /* ---------------------------------------------------------------------
     * Доводка заказа
     * ------------------------------------------------------------------ */

    /** Чей это заказ. */
    public static function owner($task_id) {
        $meta = get_option('gs_lab_task_' . $task_id, array());
        if (is_array($meta) && !empty($meta['user_id'])) {
            return (int) $meta['user_id'];
        }
        if (class_exists('KIE_TTS_DB')) {
            $row = KIE_TTS_DB::get_generation_by_task_id($task_id);
            if (is_array($row) && isset($row['user_id'])) {
                return (int) $row['user_id'];
            }
        }
        return 0;
    }

    /**
     * Спросить поставщика и закрыть заказ, если он доиграл.
     *
     * Один и тот же код зовут страница и расписание: деньги, журнал и
     * история должны сходиться независимо от того, кто пришёл первым.
     *
     * @return array{ok:bool,status:string,files:array,text:string,message:string}
     */
    public static function settle($service_id, $task_id) {
        $out = array('ok' => true, 'status' => 'pending', 'files' => array(), 'text' => '', 'message' => '');
        if (!GS_Lab::get_service($service_id)) {
            return array('ok' => false, 'status' => 'pending', 'files' => array(),
                         'text' => '', 'message' => 'Неизвестный сервис');
        }

        $meta  = get_option('gs_lab_task_' . $task_id, array());
        $owner = self::owner($task_id);
        $task  = GS_Lab::fetch_task($service_id, $task_id);

        if (empty($task['ok'])) {
            // Поставщик не ответил — это ещё не отказ. Но если заказ висит
            // сутки, ждать больше нечего: закрываем и возвращаем деньги.
            if (self::too_old($meta, $task_id)) {
                return self::fail($service_id, $task_id, $owner, $meta,
                    'Обработка не состоялась, средства возвращены');
            }
            $out['message'] = (string) ($task['message'] ?? '');
            return $out;
        }

        if ($task['status'] === 'failed') {
            return self::fail($service_id, $task_id, $owner, $meta,
                (string) ($task['message'] ?: 'Обработка не удалась, средства возвращены'));
        }

        if ($task['status'] !== 'completed') {
            if (self::too_old($meta, $task_id)) {
                return self::fail($service_id, $task_id, $owner, $meta,
                    'Обработка не состоялась, средства возвращены');
            }
            return $out;
        }

        $files = array();
        foreach ((array) $task['files'] as $file) {
            // Текстовые выгрузки уже лежат у нас — перекладывать их незачем.
            $local = ($file['kind'] ?? '') === 'file' ? '' : GS_Lab::store_result($task_id, $file['url'], $file['kind']);
            $files[] = array(
                'label' => (string) ($file['label'] ?? ''),
                'kind'  => (string) ($file['kind'] ?? 'file'),
                'url'   => $local !== '' ? $local : (string) $file['url'],
            );
        }
        // Закрываем запись в общем журнале всегда, а не только когда есть
        // файл: у текста песни и расшифровки файла нет вовсе, и такие
        // заказы оставались бы «в работе» навечно — расписание опрашивало
        // бы по ним поставщика до скончания века.
        if (class_exists('KIE_TTS_DB')) {
            if (!empty($files)) {
                KIE_TTS_DB::update_generation_status($task_id, 'completed', $files[0]['url']);
            } else {
                KIE_TTS_DB::update_generation_status($task_id, 'completed');
            }
        }

        // Служебную запись убираем до возврата денег и по её удалению же
        // понимаем, что к заказу пришли первыми: страница опрашивает
        // статус каждые шесть секунд, расписание ходит своим чередом, и
        // без этого они могли бы вернуть одну сумму дважды.
        $claimed = self::claim($task_id);

        // Подборка могла выйти не целиком: за каждый несостоявшийся кадр
        // возвращаем его долю, иначе человек платит за то, чего не получил.
        $missed = (int) ($task['failed_count'] ?? 0);
        if ($claimed && $missed > 0 && is_array($meta) && !empty($meta['user_id']) && !empty($meta['cost'])) {
            $per = (float) $meta['cost'] / max(1, $missed + count($files));
            GS_SFX::refund_charge((int) $meta['user_id'], round($per * $missed, 2));
        }

        $text = isset($task['text']) ? (string) $task['text'] : '';
        self::finish_order($owner, $service_id, $task_id, 'ready', $files, $text, '',
            is_array($meta) ? (string) ($meta['note'] ?? '') : '');

        $out['status'] = 'completed';
        $out['files']  = $files;
        $out['text']   = $text;
        return $out;
    }

    /** Закрыть заказ отказом и вернуть деньги. */
    private static function fail($service_id, $task_id, $owner, $meta, $message) {
        if (class_exists('KIE_TTS_DB')) {
            KIE_TTS_DB::update_generation_status($task_id, 'failed');
        }
        // Возврат привязан к служебной записи: вернёт деньги тот, кому
        // удалось её удалить. Остальные — страница, следующий заход
        // расписания — уже ничего не вернут.
        if (self::claim($task_id) && is_array($meta) && !empty($meta['user_id']) && !empty($meta['cost'])) {
            GS_SFX::refund_charge((int) $meta['user_id'], (float) $meta['cost']);
        }
        self::finish_order($owner, $service_id, $task_id, 'failed', array(), '', $message);
        return array('ok' => true, 'status' => 'failed', 'files' => array(),
                     'text' => '', 'message' => $message);
    }

    /**
     * Забрать заказ себе.
     *
     * Удаление служебной записи удаётся ровно одному из тех, кто пришёл
     * к заказу: база считает удалённые строки, и второму достанется ноль.
     * На этом и держится правило «деньги возвращает первый».
     */
    private static function claim($task_id) {
        return (bool) delete_option('gs_lab_task_' . $task_id);
    }

    /** Заказ висит дольше, чем поставщик вообще помнит свои задачи. */
    private static function too_old($meta, $task_id) {
        $started = 0;
        if (is_array($meta) && !empty($meta['at'])) {
            $started = (int) $meta['at'];
        }
        if ($started <= 0 && class_exists('KIE_TTS_DB')) {
            $row = KIE_TTS_DB::get_generation_by_task_id($task_id);
            if (is_array($row) && !empty($row['created_at'])) {
                $started = (int) strtotime((string) $row['created_at']) - (int) (get_option('gmt_offset') * HOUR_IN_SECONDS);
            }
        }
        if ($started <= 0) {
            return false;
        }
        return (time() - $started) > self::GIVE_UP_HOURS * HOUR_IN_SECONDS;
    }

    /* ---------------------------------------------------------------------
     * Расписание
     * ------------------------------------------------------------------ */

    /** Заказы, по которым ещё нет ответа. */
    public static function pending_jobs($limit = 0) {
        global $wpdb;
        if (!class_exists('KIE_TTS_DB')) {
            return array();
        }
        $limit = $limit > 0 ? (int) $limit : self::SWEEP;
        $table = $wpdb->prefix . 'kie_tts_generations';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT task_id, user_id, voice, created_at
               FROM {$table}
              WHERE voice LIKE %s
                AND status NOT IN ('completed', 'failed')
                AND created_at > DATE_SUB(NOW(), INTERVAL %d HOUR)
           ORDER BY id DESC
              LIMIT %d",
            'lab:%',
            self::GIVE_UP_HOURS + 12,
            $limit
        ), ARRAY_A);
        return is_array($rows) ? $rows : array();
    }

    public static function sweep() {
        $done = 0;
        $failed = 0;
        $waiting = 0;
        foreach (self::pending_jobs() as $row) {
            $service_id = substr((string) $row['voice'], 4);
            if (!GS_Lab::get_service($service_id)) {
                continue;
            }
            $res = self::settle($service_id, (string) $row['task_id']);
            if ($res['status'] === 'completed') {
                $done++;
            } elseif ($res['status'] === 'failed') {
                $failed++;
            } else {
                $waiting++;
            }
        }
        return array('забрано' => $done, 'отказов' => $failed, 'ещё_в_работе' => $waiting);
    }

    /* ---------------------------------------------------------------------
     * Уведомление и отметка о прочтении
     * ------------------------------------------------------------------ */

    /**
     * Что человек ещё не видел.
     *
     * Показываем и готовое, и отказ: про возврат денег человеку знать
     * не менее важно, чем про готовый файл.
     */
    public static function news($user_id) {
        $user_id = (int) $user_id;
        $out = array();
        if ($user_id <= 0) {
            return $out;
        }
        foreach (GS_Lab::services() as $id => $service) {
            foreach (self::history($user_id, $id) as $row) {
                if ($row['status'] === 'pending' || $row['seen'] !== '') {
                    continue;
                }
                $out[] = array(
                    'task'    => $row['task'],
                    'service' => $id,
                    'name'    => (string) $service['menu'],
                    'url'     => GS_Lab::get_url($id),
                    'status'  => $row['status'],
                    'note'    => $row['note'],
                    'at'      => $row['at'],
                    'message' => $row['message'],
                    'kind'    => $row['files'] ? (string) $row['files'][0]['kind'] : 'text',
                    'file'    => $row['files'] ? (string) $row['files'][0]['url'] : '',
                );
            }
        }
        usort($out, function ($a, $b) {
            return strcmp((string) $b['at'], (string) $a['at']);
        });
        return $out;
    }

    /**
     * Сколько непрочитанного — не поднимая подробностей.
     *
     * Считаем в разметке страницы, чтобы не ходить лишний раз на сервер:
     * у большинства людей непрочитанного нет, и запрос был бы впустую.
     * Все метки пользователя читаются одним запросом к базе и остаются в
     * памяти, так что обход сервисов ничего не стоит.
     */
    public static function unseen_count($user_id) {
        $user_id = (int) $user_id;
        if ($user_id <= 0) {
            return 0;
        }
        $n = 0;
        foreach (GS_Lab::services() as $id => $service) {
            foreach (self::history($user_id, $id) as $row) {
                if ($row['status'] !== 'pending' && $row['seen'] === '') {
                    $n++;
                }
            }
        }
        return $n;
    }

    /**
     * Снять отметку о прочтении.
     *
     * Нужно для разбора обращений: человек пишет, что ничего не получил,
     * — снимаем отметку, и готовое снова попадётся ему на глаза плашкой,
     * а не ссылкой в переписке.
     */
    public static function renotify($user_id, $service_id, $task_id) {
        $user_id = (int) $user_id;
        if ($user_id <= 0 || !GS_Lab::get_service($service_id)) {
            return 0;
        }
        $rows = self::history($user_id, $service_id);
        $hit = 0;
        foreach ($rows as $i => $row) {
            if ($row['status'] === 'pending') {
                continue;
            }
            if ($task_id !== '' && $row['task'] !== $task_id) {
                continue;
            }
            $rows[$i]['seen'] = '';
            $hit++;
        }
        if ($hit) {
            self::save($user_id, $service_id, $rows);
        }
        return $hit;
    }

    /**
     * Человек увидел результат — записываем когда.
     *
     * Отметка нужна не только чтобы погасить значок: по ней видно, дошёл
     * ли до человека тот результат, за который он заплатил.
     */
    public static function mark_seen($user_id, $service_id, array $tasks) {
        $user_id = (int) $user_id;
        if ($user_id <= 0 || !GS_Lab::get_service($service_id)) {
            return 0;
        }
        $rows = self::history($user_id, $service_id);
        $now = current_time('mysql');
        $hit = 0;
        foreach ($rows as $i => $row) {
            if ($row['seen'] !== '' || $row['status'] === 'pending') {
                continue;
            }
            if ($tasks && !in_array($row['task'], $tasks, true)) {
                continue;
            }
            $rows[$i]['seen'] = $now;
            $hit++;
        }
        if ($hit) {
            self::save($user_id, $service_id, $rows);
        }
        return $hit;
    }
}
