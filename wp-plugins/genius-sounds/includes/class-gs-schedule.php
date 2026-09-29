<?php
/**
 * Очередь публикаций: своя скорость у каждого раздела.
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
    const META_LANE  = '_gs_queue_lane';
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

    /**
     * Потоки очереди.
     *
     * Каждый поток идёт своим темпом и своими часами, а статья помечается
     * меткой потока при заливке. Общей скоростью это не описать: одна
     * цифра на всё либо выплёвывает за неделю весь блог, либо растягивает
     * двести промтов на месяцы.
     */
    public static function lanes() {
        return array(
            ''        => 'Блог: лонгриды',
            'promt'   => 'Раздел промтов',
            'api'     => 'Статьи про API',
            'service' => 'Статьи о микросервисах',
            'podarok' => 'Песня в подарок',
            'pretenzia' => 'Претензии',
            'prikaz'    => 'Судебные приказы',
            'semya'     => 'Развод и алименты',
            'ucitel'    => 'Документы учителя',
        );
    }

    /**
     * Темп по умолчанию: две статьи в день из каждого потока, все дни.
     *
     * Часы у потоков разные, и это не косметика. Планировщик просыпается
     * раз в час и за один проход выпускает всё, что накопилось: поставь
     * всем потокам один час — и десять статей выйдут одной минутой, что
     * видно и в фиде, и в Вебмастере.
     */
    public static function lane_defaults() {
        return array(
            ''      => array('enabled' => 1, 'per_day' => 2, 'hours' => array(9, 18),
                             'days' => array(1, 2, 3, 4, 5, 6, 7)),
            'promt' => array('enabled' => 1, 'per_day' => 2, 'hours' => array(11, 20),
                             'days' => array(1, 2, 3, 4, 5, 6, 7)),
            'api'   => array('enabled' => 1, 'per_day' => 2, 'hours' => array(12, 19),
                             'days' => array(1, 2, 3, 4, 5, 6, 7)),
            'service' => array('enabled' => 1, 'per_day' => 2, 'hours' => array(13, 16),
                               'days' => array(1, 2, 3, 4, 5, 6, 7)),
            // Подарочный кластер сезонный: к ноябрю и декабрю он должен уже
            // стоять в поиске, поэтому выходит наравне с остальными, а не по
            // статье в неделю.
            'podarok' => array('enabled' => 1, 'per_day' => 2, 'hours' => array(10, 15),
                               'days' => array(1, 2, 3, 4, 5, 6, 7)),
            // Юридические кластеры: по две статьи в день у каждого, часы свои.
            'pretenzia' => array('enabled' => 1, 'per_day' => 2, 'hours' => array(8, 17),
                                 'days' => array(1, 2, 3, 4, 5, 6, 7)),
            'prikaz'    => array('enabled' => 1, 'per_day' => 2, 'hours' => array(14, 21),
                                 'days' => array(1, 2, 3, 4, 5, 6, 7)),
            'semya'     => array('enabled' => 1, 'per_day' => 2, 'hours' => array(7, 19),
                                 'days' => array(1, 2, 3, 4, 5, 6, 7)),
            'ucitel'    => array('enabled' => 1, 'per_day' => 2, 'hours' => array(6, 15),
                                 'days' => array(1, 2, 3, 4, 5, 6, 7)),
        );
    }

    /** Настройки основного потока — для обратной совместимости вызовов. */
    public static function settings($lane = '') {
        $saved = get_option(self::OPT, array());
        $saved = is_array($saved) ? $saved : array();

        // Старый формат: одни настройки на всё. Считаем их настройками блога.
        $legacy = array();
        if (isset($saved['per_day']) || isset($saved['hours'])) {
            $legacy = array(
                'enabled' => isset($saved['enabled']) ? (int) $saved['enabled'] : 1,
                'per_day' => (int) ($saved['per_day'] ?? 2),
                'hours'   => (array) ($saved['hours'] ?? array(10, 18)),
            );
        }

        $defaults = self::lane_defaults();
        $lane = array_key_exists($lane, $defaults) ? $lane : '';
        $base = $defaults[$lane];
        if ($lane === '' && $legacy) {
            $base = wp_parse_args($legacy, $base);
        }
        $own = isset($saved['lanes'][$lane]) && is_array($saved['lanes'][$lane]) ? $saved['lanes'][$lane] : array();
        return wp_parse_args($own, $base);
    }

    public static function save_settings($values, $lane = '') {
        $now = self::settings($lane);
        $now['enabled'] = !empty($values['enabled']) ? 1 : 0;
        $now['per_day'] = max(1, min(12, (int) ($values['per_day'] ?? $now['per_day'])));

        $hours = array();
        foreach ((array) ($values['hours'] ?? array()) as $hour) {
            $hour = (int) $hour;
            if ($hour >= 0 && $hour <= 23) {
                $hours[] = $hour;
            }
        }
        sort($hours);
        if ($hours) {
            $now['hours'] = array_values(array_unique($hours));
        }

        $days = array();
        foreach ((array) ($values['days'] ?? array()) as $day) {
            $day = (int) $day;
            if ($day >= 1 && $day <= 7) {
                $days[] = $day;
            }
        }
        sort($days);
        // Пустой выбор означал бы «не выходить никогда»: это делается
        // галочкой «публиковать по расписанию», а не пустым списком дней.
        $now['days'] = $days ? array_values(array_unique($days)) : array(1, 2, 3, 4, 5, 6, 7);

        $saved = get_option(self::OPT, array());
        $saved = is_array($saved) ? $saved : array();
        $saved['lanes'] = isset($saved['lanes']) && is_array($saved['lanes']) ? $saved['lanes'] : array();
        $saved['lanes'][$lane] = $now;
        update_option(self::OPT, $saved, false);
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
        $fields = array(
            self::META_ORDER => 'integer',
            self::META_KEY   => 'string',
            self::META_COMP  => 'integer',
            self::META_LANE  => 'string',
        );
        foreach ($fields as $key => $type) {
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
    /**
     * Условие принадлежности потоку.
     *
     * У статей блога метки потока нет вовсе — они залиты до того, как
     * потоки появились. Поэтому основной поток — это «метки нет или она
     * пустая», а не «метка равна пустой строке».
     */
    private static function lane_query($lane) {
        if ((string) $lane === '') {
            return array(
                'relation' => 'OR',
                array('key' => self::META_LANE, 'compare' => 'NOT EXISTS'),
                array('key' => self::META_LANE, 'value' => '', 'compare' => '='),
            );
        }
        return array(array('key' => self::META_LANE, 'value' => (string) $lane, 'compare' => '='));
    }

    public static function queue($limit = 500, $lane = '') {
        return get_posts(array(
            'post_type'        => 'post',
            'post_status'      => 'draft',
            'numberposts'      => (int) $limit,
            'meta_key'         => self::META_ORDER,
            'orderby'          => 'meta_value_num',
            'order'            => 'ASC',
            'meta_query'       => self::lane_query($lane),
            'suppress_filters' => true,
        ));
    }

    public static function queue_size($lane = '') {
        $posts = get_posts(array(
            'post_type'        => 'post',
            'post_status'      => 'draft',
            'numberposts'      => -1,
            'fields'           => 'ids',
            'meta_key'         => self::META_ORDER,
            'meta_query'       => self::lane_query($lane),
            'suppress_filters' => true,
        ));
        return count($posts);
    }

    /** Сколько статей потока уже вышло сегодня. */
    public static function published_today($lane = '') {
        $today = get_posts(array(
            'post_type'        => 'post',
            'post_status'      => 'publish',
            'numberposts'      => -1,
            'fields'           => 'ids',
            'date_query'       => array(array('after' => 'today midnight', 'inclusive' => true)),
            'meta_key'         => self::META_ORDER,
            'meta_query'       => self::lane_query($lane),
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
        foreach (array_keys(self::lanes()) as $lane) {
            $set = self::settings($lane);
            if (empty($set['enabled'])) {
                continue;
            }

            // День недели у каждого потока свой: целыми статьями в день
            // ровный темп «десять-двадцать страниц в неделю» не выставить.
            $days = (array) ($set['days'] ?? array(1, 2, 3, 4, 5, 6, 7));
            if ($days && !in_array((int) current_time('N'), array_map('intval', $days), true)) {
                continue;
            }

            $hour = (int) current_time('G');
            $due = 0;
            foreach ((array) $set['hours'] as $slot) {
                if ($hour >= (int) $slot) {
                    $due++;
                }
            }
            $due = min($due, (int) $set['per_day']);
            $left = $due - self::published_today($lane);
            if ($left <= 0) {
                continue;
            }

            self::publish_next($left, $lane);
        }
    }

    /**
     * Публикует ближайшие статьи из очереди.
     *
     * @return int Сколько вышло.
     */
    public static function publish_next($count = 1, $lane = '') {
        $posts = self::queue((int) $count, $lane);
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
    public static function planned_date($index, $lane = '') {
        $set = self::settings($lane);
        $per = max(1, (int) $set['per_day']);
        $hours = (array) $set['hours'];

        $done_today = self::published_today($lane);
        $left_today = max(0, $per - $done_today);

        if ($index < $left_today) {
            $slot = $hours[min(count($hours) - 1, $done_today + $index)] ?? 12;
            return date_i18n('d.m, H:i', strtotime(current_time('Y-m-d') . ' ' . (int) $slot . ':00'));
        }

        $index -= $left_today;
        $days = array_map('intval', (array) ($set['days'] ?? array(1, 2, 3, 4, 5, 6, 7)));
        $slot = $hours[$index % $per] ?? 12;

        // Считаем по календарю, а не делением: поток может выходить через
        // день, и «плюс N дней» тогда показывает дату, которой не будет.
        $need = (int) floor($index / $per) + 1;
        $ahead = 0;
        for ($step = 1; $step <= 400 && $need > 0; $step++) {
            $stamp = strtotime(current_time('Y-m-d') . ' +' . $step . ' day');
            if (!$days || in_array((int) date_i18n('N', $stamp), $days, true)) {
                $need--;
                $ahead = $step;
            }
        }
        $stamp = strtotime(current_time('Y-m-d') . ' +' . max(1, $ahead) . ' day ' . (int) $slot . ':00');
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
    public static function enqueue($post_id, $order, $key = '', $comp = 0, $lane = '') {
        update_post_meta($post_id, self::META_ORDER, (int) $order);
        if ($key !== '') {
            update_post_meta($post_id, self::META_KEY, sanitize_text_field($key));
        }
        update_post_meta($post_id, self::META_COMP, (int) $comp);
        if ($lane !== '') {
            update_post_meta($post_id, self::META_LANE, sanitize_key($lane));
        }
    }

    public static function meta($post_id) {
        return array(
            'order' => (int) get_post_meta($post_id, self::META_ORDER, true),
            'key'   => (string) get_post_meta($post_id, self::META_KEY, true),
            'comp'  => (int) get_post_meta($post_id, self::META_COMP, true),
            'lane'  => (string) get_post_meta($post_id, self::META_LANE, true),
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

        $lane = sanitize_key((string) ($_POST['lane'] ?? ''));
        if (!array_key_exists($lane, self::lanes())) {
            $lane = '';
        }

        $hours = array();
        foreach (explode(',', (string) ($_POST['hours'] ?? '')) as $piece) {
            $piece = trim($piece);
            if ($piece !== '') {
                $hours[] = (int) $piece;
            }
        }
        self::save_settings(array(
            'enabled' => !empty($_POST['enabled']),
            'per_day' => $_POST['per_day'] ?? null,
            'hours'   => $hours,
            'days'    => (array) ($_POST['days'] ?? array()),
        ), $lane);
        wp_safe_redirect(admin_url('admin.php?page=genius-sounds') . '#gs-schedule');
        exit;
    }

    public static function handle_publish_now() {
        if (!current_user_can('manage_options')) {
            wp_die('Недостаточно прав');
        }
        check_admin_referer('gs_schedule_now');
        $lane = sanitize_key((string) ($_POST['lane'] ?? ''));
        if (!array_key_exists($lane, self::lanes())) {
            $lane = '';
        }
        $done = self::publish_next(1, $lane);
        set_transient('gs_schedule_notice', $done, 60);
        wp_safe_redirect(admin_url('admin.php?page=genius-sounds') . '#gs-schedule');
        exit;
    }

    public static function render_panel() {
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

        <?php foreach (self::lanes() as $lane => $label): ?>
            <?php echo self::render_lane($lane, $label); ?>
        <?php endforeach; ?>
        <?php
        return ob_get_clean();
    }

    private static function render_lane($lane, $label) {
        $set = self::settings($lane);
        $queue = self::queue(30, $lane);
        $total = self::queue_size($lane);

        ob_start();
        ?>
        <h3 style="margin-top:22px"><?php echo esc_html($label); ?></h3>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-bottom:14px">
            <?php wp_nonce_field('gs_schedule_save'); ?>
            <input type="hidden" name="action" value="gs_schedule_save">
            <input type="hidden" name="lane" value="<?php echo esc_attr($lane); ?>">
            <label style="margin-right:16px">
                <input type="checkbox" name="enabled" value="1" <?php checked(!empty($set['enabled'])); ?>>
                публиковать по расписанию
            </label>
            <label style="margin-right:16px">
                статей в день
                <input type="number" name="per_day" min="1" max="12" value="<?php echo (int) $set['per_day']; ?>" class="small-text">
            </label>
            <label style="margin-right:16px">
                часы выхода
                <input type="text" name="hours" value="<?php echo esc_attr(implode(', ', (array) $set['hours'])); ?>" class="small-text">
            </label>
            <span style="margin-right:16px">
                дни:
                <?php
                $chosen = array_map('intval', (array) ($set['days'] ?? array(1, 2, 3, 4, 5, 6, 7)));
                $names = array(1 => 'пн', 2 => 'вт', 3 => 'ср', 4 => 'чт', 5 => 'пт', 6 => 'сб', 7 => 'вс');
                foreach ($names as $num => $name): ?>
                    <label style="margin-right:6px">
                        <input type="checkbox" name="days[]" value="<?php echo (int) $num; ?>"
                            <?php checked(in_array($num, $chosen, true)); ?>><?php echo esc_html($name); ?>
                    </label>
                <?php endforeach; ?>
            </span>
            <?php submit_button('Сохранить', 'secondary', 'submit', false); ?>
        </form>

        <p>
            В очереди: <strong><?php echo (int) $total; ?></strong>,
            сегодня вышло: <?php echo (int) self::published_today($lane); ?> из <?php echo (int) $set['per_day']; ?>.
            <?php if ($total > 0): ?>
                Очередь закончится примерно <?php echo esc_html(self::planned_date($total - 1, $lane)); ?>.
            <?php endif; ?>
        </p>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-bottom:12px">
            <?php wp_nonce_field('gs_schedule_now'); ?>
            <input type="hidden" name="action" value="gs_schedule_now">
            <input type="hidden" name="lane" value="<?php echo esc_attr($lane); ?>">
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
                            <td><?php echo esc_html(self::planned_date($i, $lane)); ?></td>
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
