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
    /** Какой курс человек учит: у кабинетов разные программы и правила. */
    const META_COURSE  = 'gc_course';
    /** Оплаченный тариф. Пусто — доступ только к бесплатным модулям. */
    const META_PAID    = 'gc_paid';
    const META_PAID_AT = 'gc_paid_at';
    /** Метка платежа ЮMoney, по которой оплата нашлась. */
    const META_PAID_LABEL = 'gc_paid_label';

    /* Посещения кабинета: по ним видно, взялся человек за учёбу или нет. */
    const META_SEEN    = 'gc_seen_at';
    const META_FIRST   = 'gc_first_seen';
    const META_VISITS  = 'gc_visits';
    const META_DAYS    = 'gc_days';
    const META_DAY     = 'gc_last_day';
    const META_LASTMOD = 'gc_last_module';

    /** Новым визитом считаем заход после получасового перерыва. */
    const VISIT_GAP = 1800;

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
            wp_safe_redirect(GC_Cabinet::url(self::course(get_current_user_id())));
            exit;
        }
    }

    public static function redirect_after_login($redirect, $requested, $user) {
        if ($user instanceof WP_User && user_can($user, self::CAP) && !user_can($user, 'edit_posts')) {
            return GC_Cabinet::url(self::course($user->ID));
        }
        return $redirect;
    }

    /* ---------------------------------------------------------------------
     * Курс и оплата
     * ------------------------------------------------------------------ */

    public static function course($user_id) {
        return GC_Courses::of_user($user_id);
    }

    public static function set_course($user_id, $course) {
        $course = GC_Courses::exists($course) ? (string) $course : GC_Courses::DEFAULT_KEY;
        update_user_meta((int) $user_id, self::META_COURSE, $course);
        return $course;
    }

    /** Оплаченный тариф ученика. Пусто — не оплачено. */
    public static function paid_plan($user_id) {
        return (string) get_user_meta((int) $user_id, self::META_PAID, true);
    }

    /**
     * Отметить оплату.
     *
     * Повторное уведомление по той же метке ничего не меняет: ЮMoney
     * присылает уведомление повторно, пока не получит ответ, и открывать
     * доступ дважды нечем — а вот перезаписать дату оплаты и потерять
     * настоящую легко.
     */
    public static function set_paid($user_id, $plan, $label = '') {
        $user_id = (int) $user_id;
        $plan = sanitize_key($plan);
        $already = self::paid_plan($user_id);
        update_user_meta($user_id, self::META_PAID, $plan);
        if ($label !== '') {
            update_user_meta($user_id, self::META_PAID_LABEL, sanitize_text_field($label));
        }
        if ($already === '') {
            update_user_meta($user_id, self::META_PAID_AT, current_time('mysql'));
        }
        return $already === '';
    }

    public static function clear_paid($user_id) {
        delete_user_meta((int) $user_id, self::META_PAID);
        delete_user_meta((int) $user_id, self::META_PAID_AT);
        delete_user_meta((int) $user_id, self::META_PAID_LABEL);
    }

    public static function paid_at($user_id) {
        return (string) get_user_meta((int) $user_id, self::META_PAID_AT, true);
    }

    /**
     * Выдать доступ.
     *
     * Пароль показываем один раз и нигде не храним в открытом виде —
     * дальше человек меняет его сам или восстанавливает почтой.
     *
     * @return array{ok:bool,user_id:int,login:string,password:string,message:string}
     */
    public static function create($email, $name = '', $plan = 'базовый', $note = '', $gpu_days = 30, $course = '') {
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

        update_user_meta($user_id, self::META_COURSE, GC_Courses::exists($course) ? (string) $course : GC_Courses::DEFAULT_KEY);
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
        // Администратору срок генераций не выставляют: он заходит в
        // кабинет, чтобы проверить, как всё выглядит у ученика, и
        // упираться в «месяц закончился» ему незачем. Машина и так
        // пускает его в обход срока — здесь выравниваем вид со смыслом.
        if (user_can((int) $user_id, 'manage_options')) {
            return true;
        }
        $until = self::gpu_until($user_id);
        return $until !== '' && strtotime($until) > time();
    }

    /* ---------------------------------------------------------------------
     * Посещения
     * ------------------------------------------------------------------ */

    /**
     * Отметить заход в кабинет.
     *
     * Своей таблицы не заводим: нужны не клики, а ответ на один вопрос —
     * человек учится или пропал. Для этого хватает четырёх чисел в
     * метках пользователя: когда был последний раз, сколько заходов,
     * сколько разных дней и какой модуль открывал последним.
     *
     * Заходы считаем сессиями, а не страницами: иначе за один вечер
     * набегает тридцать «посещений», и число перестаёт что-либо
     * значить. Администратора не считаем вовсе — он ходит смотреть, как
     * всё выглядит у ученика, и портит статистику.
     */
    public static function touch($user_id, $module = -1) {
        $user_id = (int) $user_id;
        if ($user_id <= 0 || user_can($user_id, 'manage_options')) {
            return;
        }
        $now = time();
        $last = (int) get_user_meta($user_id, self::META_SEEN, true);

        if ($last === 0) {
            update_user_meta($user_id, self::META_FIRST, current_time('mysql'));
        }
        if ($last === 0 || $now - $last > self::VISIT_GAP) {
            update_user_meta($user_id, self::META_VISITS, (int) get_user_meta($user_id, self::META_VISITS, true) + 1);
        }
        // wp_date, а не date_i18n: вторая ждёт время, к которому часовой
        // пояс сайта уже прибавлен, и считала бы дни по UTC.
        $today = function_exists('wp_date') ? wp_date('Y-m-d', $now) : date_i18n('Y-m-d', $now);
        if ((string) get_user_meta($user_id, self::META_DAY, true) !== $today) {
            update_user_meta($user_id, self::META_DAY, $today);
            update_user_meta($user_id, self::META_DAYS, (int) get_user_meta($user_id, self::META_DAYS, true) + 1);
        }
        update_user_meta($user_id, self::META_SEEN, $now);
        if ((int) $module >= 0) {
            update_user_meta($user_id, self::META_LASTMOD, (int) $module);
        }
    }

    /**
     * Сводка посещений для админки.
     *
     * @return array{seen:int,first:string,visits:int,days:int,module:int}
     */
    public static function visits($user_id) {
        $user_id = (int) $user_id;
        $module = get_user_meta($user_id, self::META_LASTMOD, true);
        return array(
            'seen'   => (int) get_user_meta($user_id, self::META_SEEN, true),
            'first'  => (string) get_user_meta($user_id, self::META_FIRST, true),
            'visits' => (int) get_user_meta($user_id, self::META_VISITS, true),
            'days'   => (int) get_user_meta($user_id, self::META_DAYS, true),
            'module' => $module === '' ? -1 : (int) $module,
        );
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

    /**
     * Ученики курса.
     *
     * Пустой курс — все. У учеников, заведённых до появления второго
     * курса, метки нет вовсе: для курса по умолчанию их приходится
     * добирать условием «метки нет», иначе первый набор пропадёт из
     * списка вместе с их работами.
     *
     * @return WP_User[]
     */
    public static function all($course = '') {
        $args = array('role' => self::ROLE, 'orderby' => 'registered', 'order' => 'DESC', 'number' => 500);
        if ($course !== '' && GC_Courses::exists($course)) {
            if ($course === GC_Courses::DEFAULT_KEY) {
                $args['meta_query'] = array(
                    'relation' => 'OR',
                    array('key' => self::META_COURSE, 'value' => $course),
                    array('key' => self::META_COURSE, 'compare' => 'NOT EXISTS'),
                    array('key' => self::META_COURSE, 'value' => '', 'compare' => '='),
                );
            } else {
                $args['meta_query'] = array(
                    array('key' => self::META_COURSE, 'value' => $course),
                );
            }
        }
        return get_users($args);
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
