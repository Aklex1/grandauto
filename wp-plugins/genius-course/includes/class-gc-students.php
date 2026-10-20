<?php
/**
 * Ученики и доступы.
 *
 * Доступ — это обычный пользователь WordPress с ролью gc_student, а не
 * своя таблица логинов. Так вход, смена пароля, восстановление и защита
 * от перебора достаются готовыми: своя авторизация на сайте с оплатами
 * — это способ однажды раздать чужие работы не тому человеку.
 */

if (!defined('ABSPATH')) {
    exit;
}

class GC_Students {

    const ROLE = 'gc_student';
    const CAP  = 'gc_access_course';

    const META_PLAN    = 'gc_plan';
    const META_STARTED = 'gc_started_at';
    const META_UNTIL   = 'gc_gpu_until';
    const META_NOTE    = 'gc_note';
    const META_PROGRESS = 'gc_progress';

    public static function boot() {
        add_filter('login_redirect', array(__CLASS__, 'redirect_after_login'), 10, 3);
        // Ученику в админке делать нечего, а панель сверху закрывает кабинет.
        add_action('after_setup_theme', array(__CLASS__, 'hide_admin_bar'));
        add_action('admin_init', array(__CLASS__, 'block_admin'));
    }

    public static function add_role() {
        remove_role(self::ROLE);
        add_role(self::ROLE, 'Ученик курса', array(
            'read'    => true,
            self::CAP => true,
        ));
        // Администратору доступ нужен, чтобы видеть кабинет глазами ученика.
        $admin = get_role('administrator');
        if ($admin) {
            $admin->add_cap(self::CAP);
        }
    }

    public static function is_student($user = null) {
        $user = $user ? $user : wp_get_current_user();
        return ($user instanceof WP_User) && $user->exists() && user_can($user, self::CAP);
    }

    public static function hide_admin_bar() {
        if (is_user_logged_in() && !current_user_can('edit_posts')) {
            show_admin_bar(false);
        }
    }

    /** Ученика из админки разворачиваем в кабинет, кроме ajax. */
    public static function block_admin() {
        if (wp_doing_ajax() || current_user_can('edit_posts')) {
            return;
        }
        if (self::is_student()) {
            wp_safe_redirect(GC_Cabinet::url());
            exit;
        }
    }

    public static function redirect_after_login($redirect, $requested, $user) {
        if ($user instanceof WP_User && user_can($user, self::CAP) && !user_can($user, 'edit_posts')) {
            return GC_Cabinet::url();
        }
        return $redirect;
    }

    /**
     * Выдать доступ.
     *
     * Пароль показываем один раз и нигде не храним в открытом виде —
     * дальше человек меняет его сам или восстанавливает почтой.
     *
     * @return array{ok:bool,user_id:int,login:string,password:string,message:string}
     */
    public static function create($email, $name = '', $plan = 'базовый', $note = '', $gpu_days = 30) {
        $email = sanitize_email($email);
        if (!is_email($email)) {
            return array('ok' => false, 'user_id' => 0, 'login' => '', 'password' => '', 'message' => 'Нужна настоящая почта: на неё приходит восстановление пароля.');
        }
        if (email_exists($email)) {
            return array('ok' => false, 'user_id' => 0, 'login' => '', 'password' => '', 'message' => 'Такая почта уже есть. Откройте карточку ученика и выдайте новый пароль.');
        }

        $login = self::make_login($email);
        $password = wp_generate_password(12, false);
        $user_id = wp_insert_user(array(
            'user_login'   => $login,
            'user_email'   => $email,
            'user_pass'    => $password,
            'display_name' => $name !== '' ? sanitize_text_field($name) : $login,
            'first_name'   => sanitize_text_field($name),
            'role'         => self::ROLE,
        ));
        if (is_wp_error($user_id)) {
            return array('ok' => false, 'user_id' => 0, 'login' => '', 'password' => '', 'message' => $user_id->get_error_message());
        }

        update_user_meta($user_id, self::META_PLAN, sanitize_text_field($plan));
        update_user_meta($user_id, self::META_NOTE, sanitize_textarea_field($note));
        update_user_meta($user_id, self::META_STARTED, current_time('mysql'));
        self::set_gpu_days($user_id, (int) $gpu_days);

        return array('ok' => true, 'user_id' => (int) $user_id, 'login' => $login, 'password' => $password, 'message' => 'Доступ создан.');
    }

    /** Новый пароль существующему ученику. */
    public static function reset_password($user_id) {
        $user = get_user_by('id', (int) $user_id);
        if (!$user) {
            return '';
        }
        $password = wp_generate_password(12, false);
        wp_set_password($password, $user->ID);
        return $password;
    }

    /**
     * Срок бесплатных генераций.
     *
     * Месяц без лимита обещан на посадочной, и отсчёт идёт со дня выдачи
     * доступа, а не со дня первой генерации: иначе дата превращается в
     * спор, который нечем подтвердить.
     */
    public static function set_gpu_days($user_id, $days) {
        $days = max(0, (int) $days);
        $until = $days > 0 ? gmdate('Y-m-d H:i:s', time() + $days * DAY_IN_SECONDS) : '';
        update_user_meta((int) $user_id, self::META_UNTIL, $until);
        return $until;
    }

    public static function gpu_until($user_id) {
        return (string) get_user_meta((int) $user_id, self::META_UNTIL, true);
    }

    public static function gpu_active($user_id) {
        $until = self::gpu_until($user_id);
        return $until !== '' && strtotime($until) > time();
    }

    /** Прогресс: какие модули ученик отметил пройденными. */
    public static function progress($user_id) {
        $done = get_user_meta((int) $user_id, self::META_PROGRESS, true);
        return is_array($done) ? array_map('intval', $done) : array();
    }

    public static function mark_done($user_id, $module, $done = true) {
        $list = self::progress($user_id);
        $module = (int) $module;
        if ($done) {
            if (!in_array($module, $list, true)) {
                $list[] = $module;
            }
        } else {
            $list = array_values(array_diff($list, array($module)));
        }
        sort($list);
        update_user_meta((int) $user_id, self::META_PROGRESS, $list);
        return $list;
    }

    /** @return WP_User[] */
    public static function all() {
        return get_users(array('role' => self::ROLE, 'orderby' => 'registered', 'order' => 'DESC', 'number' => 500));
    }

    private static function make_login($email) {
        $base = sanitize_user(current(explode('@', $email)), true);
        $base = $base !== '' ? $base : 'student';
        $login = $base;
        $i = 1;
        while (username_exists($login)) {
            $i++;
            $login = $base . $i;
        }
        return $login;
    }
}
