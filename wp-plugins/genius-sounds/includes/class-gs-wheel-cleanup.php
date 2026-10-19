<?php
/**
 * Недельная уборка рендеров подбора дисков.
 *
 * Каждый расчёт сохраняет картинку в медиатеку, а WordPress добавляет к
 * ней ещё шесть-семь производных размеров. За восемь месяцев так набежало
 * 927 вложений на 8 ГБ — почти четверть всего места на хостинге, при том
 * что человек забирает свой рендер в тот же день. Квота кончилась раньше,
 * чем кто-то это заметил.
 *
 * Поэтому раз в неделю, в ночь с воскресенья, уносим рендеры прошедшей
 * недели вместе со всеми их размерами.
 */

if (!defined('ABSPATH')) {
    exit;
}

class GS_Wheel_Cleanup {

    const CRON    = 'gs_wheel_cleanup';
    const OPT_LOG = 'gs_wheel_cleanup_log';

    /** Имя файла рендера всегда начинается так. */
    const PREFIX = 'wheel-fitment-';

    /** Сколько дней рендер ещё нужен человеку. */
    const KEEP_DAYS = 7;

    /** Сколько вложений уносим за один заход. */
    const BATCH = 200;

    public static function boot() {
        add_filter('cron_schedules', array(__CLASS__, 'schedule'));
        add_action('init', array(__CLASS__, 'ensure_cron'));
        add_action(self::CRON, array(__CLASS__, 'run'));
    }

    /** Недельное расписание есть с WordPress 5.4, но полагаться на это не стоит. */
    public static function schedule($list) {
        $list = (array) $list;
        if (empty($list['weekly'])) {
            $list['weekly'] = array('interval' => WEEK_IN_SECONDS, 'display' => 'Раз в неделю');
        }
        return $list;
    }

    public static function ensure_cron() {
        if (!wp_next_scheduled(self::CRON)) {
            wp_schedule_event(self::first_run(), 'weekly', self::CRON);
        }
    }

    /**
     * Ближайшая ночь с воскресенья на понедельник по времени сайта.
     *
     * Не в полночь: в полночь на хостинге стартует всё остальное, и
     * уборка на 900 файлов встанет в очередь за чужими задачами.
     */
    public static function first_run() {
        $tz = wp_timezone();
        $now  = new DateTime('now', $tz);
        $next = new DateTime('sunday 23:40', $tz);
        if ($next <= $now) {
            $next->modify('+1 week');
        }
        return $next->getTimestamp();
    }

    /**
     * Вложения-рендеры старше KEEP_DAYS дней.
     *
     * Отбор по имени файла, а не по заголовку: заголовок человек может
     * переименовать, а имя файла задаёт сам плагин подбора.
     */
    public static function stale($limit) {
        global $wpdb;
        $edge = gmdate('Y-m-d H:i:s', time() - self::KEEP_DAYS * DAY_IN_SECONDS);
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT p.ID, m.meta_value AS file
               FROM {$wpdb->posts} p
               INNER JOIN {$wpdb->postmeta} m
                       ON m.post_id = p.ID AND m.meta_key = '_wp_attached_file'
              WHERE p.post_type = 'attachment'
                AND p.post_date_gmt < %s
                AND m.meta_value LIKE %s
              ORDER BY p.post_date_gmt ASC
              LIMIT %d",
            $edge,
            '%' . $wpdb->esc_like(self::PREFIX) . '%',
            max(1, (int) $limit)
        ));

        $out = array();
        foreach ((array) $rows as $row) {
            // LIKE ловит и совпадение посреди пути; имя файла проверяем сами.
            if (strpos(basename((string) $row->file), self::PREFIX) === 0) {
                $out[] = (int) $row->ID;
            }
        }
        return $out;
    }

    /** Сколько места занимает вложение вместе со всеми размерами. */
    public static function weight($id) {
        $path = get_attached_file($id);
        if (!$path) {
            return 0;
        }
        $bytes = file_exists($path) ? (int) filesize($path) : 0;
        $meta = wp_get_attachment_metadata($id);
        if (!is_array($meta) || empty($meta['sizes'])) {
            return $bytes;
        }
        $dir = dirname($path);
        foreach ((array) $meta['sizes'] as $size) {
            if (empty($size['file'])) {
                continue;
            }
            $sub = $dir . '/' . $size['file'];
            if (file_exists($sub)) {
                $bytes += (int) filesize($sub);
            }
        }
        return $bytes;
    }

    /**
     * @param bool $dry Только посчитать, ничего не удаляя.
     */
    public static function run($dry = false) {
        $ids = self::stale(self::BATCH);
        $gone = 0;
        $bytes = 0;
        foreach ($ids as $id) {
            $weight = self::weight($id);
            if ($dry) {
                $gone++;
                $bytes += $weight;
                continue;
            }
            if (wp_delete_attachment($id, true)) {
                $gone++;
                $bytes += $weight;
            }
        }

        // Набрали полный заход — значит осталось ещё. Доберём через десять
        // минут, чтобы не держать один запрос на тысяче файлов.
        $more = count($ids) >= self::BATCH;
        if (!$dry && $more) {
            // Повторный вызов с тем же часом WordPress отбрасывает сам,
            // так что сторожить отдельным флагом нечего.
            wp_schedule_single_event(time() + 600, self::CRON);
        }

        $report = array(
            'когда'       => gmdate('Y-m-d H:i', time()),
            'удалено'     => $gone,
            'освобождено' => $bytes,
            'есть_ещё'    => $more,
            'только_счёт' => (bool) $dry,
        );
        if (!$dry) {
            update_option(self::OPT_LOG, $report, false);
        }
        return $report;
    }
}
