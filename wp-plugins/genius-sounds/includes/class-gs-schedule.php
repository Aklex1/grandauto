<?php
/**
 * Очередь публикаций: по два лонгрида в день.
 *
 * Готовые статьи лежат черновиками, а сайт сам выпускает их по расписанию.
 * Собственная очередь вместо штатной отложенной публикации выбрана потому,
 * что порядок нужно менять: сначала выходят статьи под низкоконкурентные
 * запросы, конкурентные ждут. Ещё очередь можно поставить на паузу и
 * догнать пропущенное, если сайт сутки никто не открывал.
 */

if (!defined('ABSPATH')) {
    exit;
}

class GS_Schedule {

    const OPT        = 'gs_schedule';
    const META_ORDER = '_gs_queue_order';
    const META_KEY   = '_gs_queue_key';
    const META_COMP  = '_gs_queue_comp';
    const HOOK       = 'gs_schedule_tick';

    public static function boot() {
        add_action('init', array(__CLASS__, 'register_meta'));
        add_action('init', array(__CLASS__, 'ensure_cron'));
        add_action(self::HOOK, array(__CLASS__, 'tick'));
        add_action('admin_post_gs_schedule_save', array(__CLASS__, 'handle_save'));
        add_action('admin_post_gs_schedule_now', array(__CLASS__, 'handle_publish_now'));
    }

    /* ---------------------------------------------------------------------
     * Настройки
     * ------------------------------------------------------------------ */

    public static function settings() {
        $saved = get_option(self::OPT, array());
        return wp_parse_args(is_array($saved) ? $saved : array(), array(
            'enabled' => 1,
            'per_day' => 2,
            'hours'   => array(10, 18),
        ));
    }

    public static function save_settings($values) {
        $now = self::settings();
        $now['enabled'] = !empty($values['enabled']) ? 1 : 0;
        $now['per_day'] = max(1, min(10, (int) ($values['per_day'] ?? 2)));

        $hours = array();
        foreach ((array) ($values['hours'] ?? array()) as $hour) {
            $hour = (int) $hour;
            if ($hour >= 0 && $hour <= 23) {
                $hours[] = $hour;
            }
        }
        sort($hours);
        $now['hours'] = $hours ? array_values(array_unique($hours)) : array(10, 18);
        update_option(self::OPT, $now, false);
        return $now;
    }

    /**
     * Метки очереди видны в REST: черновики заливаются пачкой, и проставлять
     * порядок отдельным запросом к каждой записи — лишний круг.
     */
    public static function register_meta() {
        $auth = function () {
            return current_user_can('edit_posts');
        };
        foreach (array(self::META_ORDER => 'integer', self::META_KEY => 'string', self::META_COMP => 'integer') as $key => $type) {
            register_post_meta('post', $key, array(
                'type'          => $type,
                'single'        => true,
                'show_in_rest'  => true,
                'auth_callback' => $auth,
            ));
        }
    }

    public static function ensure_cron() {
        if (!wp_next_scheduled(self::HOOK)) {
            wp_schedule_event(time() + 300, 'hourly', self::HOOK);
        }
    }

    /* ---------------------------------------------------------------------
     * Очередь
     * ------------------------------------------------------------------ */

    /**
     * Черновики в порядке очереди.
     *
     * @return array<int,WP_Post>
     */
    public static function queue($limit = 500) {
        return get_posts(array(
            'post_type'        => 'post',
            'post_status'      => 'draft',
            'numberposts'      => (int) $limit,
            'meta_key'         => self::META_ORDER,
            'orderby'          => 'meta_value_num',
            'order'            => 'ASC',
            'suppress_filters' => true,
        ));
    }

    public static function queue_size() {
        $posts = get_posts(array(
            'post_type'        => 'post',
            'post_status'      => 'draft',
            'numberposts'      => -1,
            'fields'           => 'ids',
            'meta_key'         => self::META_ORDER,
            'suppress_filters' => true,
        ));
        return count($posts);
    }

    /** Сколько статей из очереди уже вышло сегодня. */
    public static function published_today() {
        $today = get_posts(array(
            'post_type'        => 'post',
            'post_status'      => 'publish',
            'numberposts'      => -1,
            'fields'           => 'ids',
            'date_query'       => array(array('after' => 'today midnight', 'inclusive' => true)),
            'meta_key'         => self::META_ORDER,
            'suppress_filters' => true,
        ));
        return count($today);
    }

    /**
     * Очередной запуск. Проверяем не «наступил ли ровно этот час», а
     * «сколько слотов уже прошло сегодня»: тогда пропущенный из-за тишины
     * на сайте час догоняется следующим же обращением.
     */
    public static function tick() {
        $set = self::settings();
        if (empty($set['enabled'])) {
            return;
        }

        $hour = (int) current_time('G');
        $due = 0;
        foreach ((array) $set['hours'] as $slot) {
            if ($hour >= (int) $slot) {
                $due++;
            }
        }
        $due = min($due, (int) $set['per_day']);
        $left = $due - self::published_today();
        if ($left <= 0) {
            return;
        }

        self::publish_next($left);
    }

    /**
     * Публикует ближайшие статьи из очереди.
     *
     * @return int Сколько вышло.
     */
    public static function publish_next($count = 1) {
        $posts = self::queue((int) $count);
        $done = 0;
        foreach ($posts as $post) {
            $ok = wp_update_post(array(
                'ID'          => $post->ID,
                'post_status' => 'publish',
                'post_date'   => current_time('mysql'),
                'post_date_gmt' => get_gmt_from_date(current_time('mysql')),
            ), true);
            if (!is_wp_error($ok)) {
                $done++;
            }
        }
        return $done;
    }

    /** Когда, по расчёту, выйдет статья на позиции $index (с нуля). */
    public static function planned_date($index) {
        $set = self::settings();
        $per = max(1, (int) $set['per_day']);
        $hours = (array) $set['hours'];

        $done_today = self::published_today();
        $left_today = max(0, $per - $done_today);

        if ($index < $left_today) {
            $slot = $hours[min(count($hours) - 1, $done_today + $index)] ?? 12;
            return date_i18n('d.m, H:i', strtotime(current_time('Y-m-d') . ' ' . (int) $slot . ':00'));
        }

        $index -= $left_today;
        $day = (int) floor($index / $per) + 1;
        $slot = $hours[$index % $per] ?? 12;
        $stamp = strtotime(current_time('Y-m-d') . ' +' . $day . ' day ' . (int) $slot . ':00');
        return date_i18n('d.m, H:i', $stamp);
    }

    /* ---------------------------------------------------------------------
     * Наполнение очереди
     * ------------------------------------------------------------------ */

    /**
     * Ставит черновик в очередь.
     *
     * @param int    $post_id
     * @param int    $order Позиция: чем меньше, тем раньше выйдет.
     * @param string $key   Запрос, под который написана статья.
     * @param int    $comp  Конкуренция запроса — для наглядности в панели.
     */
    public static function enqueue($post_id, $order, $key = '', $comp = 0) {
        update_post_meta($post_id, self::META_ORDER, (int) $order);
        if ($key !== '') {
            update_post_meta($post_id, self::META_KEY, sanitize_text_field($key));
        }
        update_post_meta($post_id, self::META_COMP, (int) $comp);
    }

    public static function meta($post_id) {
        return array(
            'order' => (int) get_post_meta($post_id, self::META_ORDER, true),
            'key'   => (string) get_post_meta($post_id, self::META_KEY, true),
            'comp'  => (int) get_post_meta($post_id, self::META_COMP, true),
        );
    }

    /* ---------------------------------------------------------------------
     * Админка
     * ------------------------------------------------------------------ */

    public static function handle_save() {
        if (!current_user_can('manage_options')) {
            wp_die('Недостаточно прав');
        }
        check_admin_referer('gs_schedule_save');

        $hours = array();
        foreach (explode(',', (string) ($_POST['hours'] ?? '')) as $piece) {
            $piece = trim($piece);
            if ($piece !== '') {
                $hours[] = (int) $piece;
            }
        }
        self::save_settings(array(
            'enabled' => !empty($_POST['enabled']),
            'per_day' => $_POST['per_day'] ?? 2,
            'hours'   => $hours,
        ));
        wp_safe_redirect(admin_url('admin.php?page=genius-sounds') . '#gs-schedule');
        exit;
    }

    public static function handle_publish_now() {
        if (!current_user_can('manage_options')) {
            wp_die('Недостаточно прав');
        }
        check_admin_referer('gs_schedule_now');
        $done = self::publish_next(1);
        set_transient('gs_schedule_notice', $done, 60);
        wp_safe_redirect(admin_url('admin.php?page=genius-sounds') . '#gs-schedule');
        exit;
    }

    public static function render_panel() {
        $set = self::settings();
        $queue = self::queue(40);
        $total = self::queue_size();
        $notice = get_transient('gs_schedule_notice');

        ob_start();
        ?>
        <h2 id="gs-schedule">Очередь публикаций</h2>

        <?php if ($notice !== false): ?>
            <?php delete_transient('gs_schedule_notice'); ?>
            <p class="notice notice-<?php echo $notice ? 'success' : 'warning'; ?>" style="padding:10px;max-width:900px">
                <?php echo $notice ? 'Опубликовано статей: ' . (int) $notice : 'Очередь пуста — публиковать нечего'; ?>
            </p>
        <?php endif; ?>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-bottom:14px">
            <?php wp_nonce_field('gs_schedule_save'); ?>
            <input type="hidden" name="action" value="gs_schedule_save">
            <label style="margin-right:16px">
                <input type="checkbox" name="enabled" value="1" <?php checked(!empty($set['enabled'])); ?>>
                публиковать по расписанию
            </label>
            <label style="margin-right:16px">
                статей в день
                <input type="number" name="per_day" min="1" max="10" value="<?php echo (int) $set['per_day']; ?>" class="small-text">
            </label>
            <label style="margin-right:16px">
                часы выхода
                <input type="text" name="hours" value="<?php echo esc_attr(implode(', ', (array) $set['hours'])); ?>" class="small-text">
            </label>
            <?php submit_button('Сохранить расписание', 'secondary', 'submit', false); ?>
        </form>

        <p>
            В очереди: <strong><?php echo (int) $total; ?></strong>,
            сегодня вышло: <?php echo (int) self::published_today(); ?> из <?php echo (int) $set['per_day']; ?>.
            <?php if ($total > 0): ?>
                Очередь закончится примерно <?php echo esc_html(self::planned_date($total - 1)); ?>.
            <?php endif; ?>
        </p>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-bottom:12px">
            <?php wp_nonce_field('gs_schedule_now'); ?>
            <input type="hidden" name="action" value="gs_schedule_now">
            <?php submit_button('Опубликовать следующую сейчас', 'secondary', 'submit', false); ?>
        </form>

        <?php if ($queue): ?>
            <table class="widefat striped" style="max-width:1000px">
                <thead><tr><th>№</th><th>Статья</th><th>Запрос</th><th>Конк.</th><th>Выйдет</th></tr></thead>
                <tbody>
                    <?php foreach ($queue as $i => $post): ?>
                        <?php $meta = self::meta($post->ID); ?>
                        <tr>
                            <td><?php echo (int) $meta['order']; ?></td>
                            <td><a href="<?php echo esc_url(get_edit_post_link($post->ID)); ?>"><?php echo esc_html($post->post_title); ?></a></td>
                            <td><?php echo esc_html($meta['key']); ?></td>
                            <td><?php echo $meta['comp'] ? (int) $meta['comp'] : '—'; ?></td>
                            <td><?php echo esc_html(self::planned_date($i)); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php if ($total > count($queue)): ?>
                <p class="description">Показаны первые <?php echo count($queue); ?> из <?php echo (int) $total; ?>.</p>
            <?php endif; ?>
        <?php else: ?>
            <p class="description">Очередь пуста: черновиков с меткой очереди нет.</p>
        <?php endif; ?>
        <?php
        return ob_get_clean();
    }
}
