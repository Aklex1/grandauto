<?php
/**
 * Сданные работы.
 *
 * Работа — это текст плюс ссылки на файлы. Файлы к себе не принимаем
 * намеренно: ролик на сто мегабайт в медиатеке сайта не нужен никому, а
 * у ученика он и так лежит в облаке, которым он пользуется каждый день.
 */

if (!defined('ABSPATH')) {
    exit;
}

class GC_Works {

    public static function submit($user_id, $module, $body, $links) {
        global $wpdb;
        $table = GC_Install::works_table();
        $wpdb->insert($table, array(
            'user_id'    => (int) $user_id,
            'module'     => (int) $module,
            'body'       => wp_kses_post($body),
            'links'      => sanitize_textarea_field($links),
            'status'     => 'sent',
            'created_at' => current_time('mysql'),
        ), array('%d', '%d', '%s', '%s', '%s', '%s'));
        return (int) $wpdb->insert_id;
    }

    public static function for_user($user_id) {
        global $wpdb;
        $table = GC_Install::works_table();
        return (array) $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE user_id = %d ORDER BY created_at DESC", (int) $user_id
        ), ARRAY_A);
    }

    public static function last_for_module($user_id, $module) {
        global $wpdb;
        $table = GC_Install::works_table();
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE user_id = %d AND module = %d ORDER BY created_at DESC LIMIT 1",
            (int) $user_id, (int) $module
        ), ARRAY_A);
    }

    /** Очередь проверки: сначала то, что ждёт дольше всех. */
    public static function queue($limit = 100) {
        global $wpdb;
        $table = GC_Install::works_table();
        return (array) $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} ORDER BY (status = 'sent') DESC, created_at ASC LIMIT %d", (int) $limit
        ), ARRAY_A);
    }

    public static function review($id, $status, $feedback) {
        global $wpdb;
        $table = GC_Install::works_table();
        $status = in_array($status, array('sent', 'accepted', 'rework'), true) ? $status : 'sent';
        $wpdb->update($table, array(
            'status'      => $status,
            'feedback'    => wp_kses_post($feedback),
            'reviewed_at' => current_time('mysql'),
        ), array('id' => (int) $id), array('%s', '%s', '%s'), array('%d'));
    }

    /** Сколько работ ждёт проверки — для счётчика в меню админки. */
    public static function waiting() {
        global $wpdb;
        $table = GC_Install::works_table();
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status = 'sent'");
    }

    /**
     * Письмо проверяющему.
     *
     * Счётчик в меню видно только тому, кто зашёл в консоль, а работа
     * может прийти ночью. Письмо шлём тихо: не ушло — не беда, работа
     * всё равно лежит в очереди и никуда не денется.
     */
    public static function notify_admin($work_id) {
        $work = self::get($work_id);
        if (!$work) {
            return;
        }
        $user = get_user_by('id', (int) $work['user_id']);
        $course = GC_Students::course((int) $work['user_id']);
        $module = GC_Program::module((int) $work['module'], $course);
        $subject = sprintf('Работа на проверку: %s, %s, модуль %d',
            $user ? $user->display_name : 'ученик',
            GC_Courses::field($course, 'short', ''),
            (int) $work['module'] + 1);
        $body = sprintf(
            "Ученик: %s (%s)
Модуль %d: %s

Ссылки:
%s

Что пишет:
%s

Проверить: %s",
            $user ? $user->display_name : '—',
            $user ? $user->user_email : '—',
            (int) $work['module'] + 1,
            $module ? $module['title'] : '',
            (string) $work['links'],
            wp_strip_all_tags((string) $work['body']),
            admin_url('admin.php?page=genius-course&tab=works')
        );
        wp_mail(get_option('admin_email'), $subject, $body);
    }

    public static function get($id) {
        global $wpdb;
        $table = GC_Install::works_table();
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", (int) $id), ARRAY_A);
    }
}
