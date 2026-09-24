<?php
/**
 * Резерв настроек сайта и восстановление из него.
 *
 * Однажды сохранение настроек одной группы обнулило чужие: options.php
 * пишет всю группу разом и затирает каждую настройку, поля которой в
 * запросе не было. В тот раз слетел ключ доступа к поставщику, и платные
 * сервисы встали. Восстанавливать пришлось руками и по памяти.
 *
 * Поэтому здесь: снимок берётся сам — перед каждым сохранением настроек в
 * админке и раз в сутки, — и лежит десять последних. Восстановление
 * возвращает значения поимённо и ничего не удаляет: настройка, которой в
 * снимке нет, остаётся как есть.
 *
 * Снимки хранятся отдельными записями без автозагрузки: иначе они висели
 * бы в памяти на каждом запросе к сайту.
 */

if (!defined('ABSPATH')) {
    exit;
}

class GS_Backup {

    /** Список снимков: номер => когда и почему сделан. */
    const OPT_INDEX = 'gs_options_backup_index';

    /** Приставка имени записи со снимком. */
    const PREFIX = 'gs_options_backup_';

    /** Сколько снимков держим. Больше не нужно, меньше — не спасёт. */
    const KEEP = 10;

    /** Чаще одного раза в пять минут снимок не делаем. */
    const MIN_GAP = 300;

    /** Настройка длиннее этого в снимок не идёт: это уже не настройка. */
    const MAX_VALUE = 262144;

    const CRON = 'gs_backup_daily';

    /** Какие настройки бережём. */
    private static function prefixes() {
        return array('kie_tts_', 'gs_', 'genius_', 'kie_neurohub_');
    }

    public static function boot() {
        // Самый опасный момент — сохранение настроек в админке. Снимок
        // берём до него: admin_init отрабатывает раньше, чем options.php
        // успевает записать группу.
        add_action('admin_init', array(__CLASS__, 'before_settings_save'), 1);
        add_action('admin_post_gs_backup_now', array(__CLASS__, 'handle_now'));
        add_action('admin_post_gs_backup_restore', array(__CLASS__, 'handle_restore'));
        add_action(self::CRON, array(__CLASS__, 'daily'));
        if (!wp_next_scheduled(self::CRON)) {
            wp_schedule_event(time() + 600, 'daily', self::CRON);
        }
    }

    public static function before_settings_save() {
        if (empty($_POST['option_page']) || !current_user_can('manage_options')) {
            return;
        }
        $group = sanitize_text_field(wp_unslash((string) $_POST['option_page']));
        self::snapshot('перед сохранением группы «' . $group . '»');
    }

    public static function daily() {
        self::snapshot('по расписанию, раз в сутки');
    }

    /* ---------------------------------------------------------------------
     * Снимок
     * ------------------------------------------------------------------ */

    /**
     * Сделать снимок настроек.
     *
     * @param string $reason зачем — попадёт в список снимков.
     * @param bool   $force  снять, даже если прошлый сделан только что.
     * @return int номер снимка или 0, если снимок не понадобился.
     */
    public static function snapshot($reason = '', $force = false) {
        $index = self::index();
        $last = $index ? reset($index) : null;
        if (!$force && $last && (time() - (int) $last['time']) < self::MIN_GAP) {
            // Сохранений подряд бывает несколько; десять почти одинаковых
            // снимков вытеснят те, ради которых всё и затевалось.
            return 0;
        }

        $values = self::collect();
        if (!$values) {
            return 0;
        }

        $id = time();
        while (isset($index[$id])) {
            $id++;
        }
        update_option(self::PREFIX . $id, $values, false);

        $index = array($id => array(
            'time'   => $id,
            'reason' => (string) $reason,
            'count'  => count($values),
            'by'     => is_user_logged_in() ? wp_get_current_user()->user_login : 'система',
        )) + $index;

        // Лишние снимки убираем вместе с их записями, иначе база пухнет.
        foreach (array_slice($index, self::KEEP, null, true) as $old_id => $row) {
            delete_option(self::PREFIX . $old_id);
            unset($index[$old_id]);
        }
        update_option(self::OPT_INDEX, $index, false);
        return $id;
    }

    /**
     * Текущие значения настроек из базы.
     *
     * Читаем таблицу напрямую: перебрать сотни имён по одному значит
     * столько же запросов, а снимок берётся на каждом сохранении.
     */
    private static function collect() {
        global $wpdb;

        $where = array();
        foreach (self::prefixes() as $prefix) {
            $where[] = $wpdb->prepare('option_name LIKE %s', $wpdb->esc_like($prefix) . '%');
        }
        $rows = $wpdb->get_results(
            "SELECT option_name, option_value FROM {$wpdb->options}
             WHERE (" . implode(' OR ', $where) . ")
               AND option_name NOT LIKE '\\_transient\\_%'
               AND option_name NOT LIKE '\\_site\\_transient\\_%'
               AND option_name NOT LIKE '" . $wpdb->esc_like(self::PREFIX) . "%'
               AND option_name <> '" . self::OPT_INDEX . "'",
            ARRAY_A
        );
        if (!is_array($rows)) {
            return array();
        }

        $out = array();
        foreach ($rows as $row) {
            $value = (string) $row['option_value'];
            // Каталоги, журналы и прочие большие куски — не настройки, и
            // держать десять их копий незачем.
            if (strlen($value) > self::MAX_VALUE) {
                continue;
            }
            $out[(string) $row['option_name']] = $value;
        }
        return $out;
    }

    /* ---------------------------------------------------------------------
     * Список и восстановление
     * ------------------------------------------------------------------ */

    /** @return array номер => сведения о снимке, новые сверху */
    public static function index() {
        $index = get_option(self::OPT_INDEX, array());
        if (!is_array($index)) {
            return array();
        }
        krsort($index);
        return $index;
    }

    public static function get($id) {
        $values = get_option(self::PREFIX . (int) $id, null);
        return is_array($values) ? $values : array();
    }

    /**
     * Вернуть значения из снимка.
     *
     * Ничего не удаляем: настройка, которой в снимке нет, остаётся как
     * есть. Восстановление должно чинить, а не откатывать сайт назад.
     *
     * @param bool $only_empty только те, что сейчас пустые — обычный случай
     *                         после обнуления группы.
     * @return array{restored:int,skipped:int}
     */
    public static function restore($id, $only_empty = true) {
        $values = self::get($id);
        $restored = 0;
        $skipped = 0;
        foreach ($values as $name => $value) {
            $current = get_option($name, null);
            if ($current !== null && (string) maybe_serialize($current) === (string) $value) {
                $skipped++;
                continue;
            }
            if ($only_empty && $current !== null && $current !== '' && $current !== false) {
                $skipped++;
                continue;
            }
            update_option($name, maybe_unserialize($value));
            $restored++;
        }
        return array('restored' => $restored, 'skipped' => $skipped);
    }

    /* ---------------------------------------------------------------------
     * Админка
     * ------------------------------------------------------------------ */

    public static function handle_now() {
        if (!current_user_can('manage_options')) {
            wp_die('Недостаточно прав');
        }
        check_admin_referer('gs_backup_now');
        $id = self::snapshot('вручную из админки');
        set_transient('gs_backup_notice', $id
            ? 'Снимок сделан'
            : 'Снимок не нужен: прошлый сделан только что', 60);
        wp_safe_redirect(admin_url('admin.php?page=genius-sounds') . '#gs-backup');
        exit;
    }

    public static function handle_restore() {
        if (!current_user_can('manage_options')) {
            wp_die('Недостаточно прав');
        }
        check_admin_referer('gs_backup_restore');
        $id = (int) ($_POST['snapshot'] ?? 0);
        $mode = (string) ($_POST['mode'] ?? 'empty');
        if ($id <= 0 || !self::get($id)) {
            set_transient('gs_backup_notice', 'Такого снимка нет', 60);
        } else {
            // Перед восстановлением — снимок текущего состояния: если
            // восстановили не тот, откатиться будет куда.
            // Этот снимок берём всегда: восстановить не тот проще
            // простого, и откатиться должно быть куда.
            self::snapshot('перед восстановлением снимка от '
                . wp_date('d.m.Y H:i', $id), true);
            $res = self::restore($id, $mode !== 'all');
            set_transient('gs_backup_notice', sprintf(
                'Восстановлено настроек: %d, оставлено как есть: %d',
                $res['restored'], $res['skipped']), 60);
        }
        wp_safe_redirect(admin_url('admin.php?page=genius-sounds') . '#gs-backup');
        exit;
    }
}
