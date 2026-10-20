<?php
/**
 * Админка курса.
 *
 * Отдельный пункт меню верхнего уровня, а не вкладка внутри плагина
 * звуков: курсом занимается другой человек в другие дни, и искать его
 * среди настроек каталога — лишняя работа каждый раз.
 */

if (!defined('ABSPATH')) {
    exit;
}

class GC_Admin {

    const SLUG = 'genius-course';

    public static function boot() {
        add_action('admin_menu', array(__CLASS__, 'menu'));
        add_action('admin_post_gc_create', array(__CLASS__, 'handle_create'));
        add_action('admin_post_gc_reset', array(__CLASS__, 'handle_reset'));
        add_action('admin_post_gc_gpu_days', array(__CLASS__, 'handle_gpu_days'));
        add_action('admin_post_gc_review', array(__CLASS__, 'handle_review'));
        add_action('admin_post_gc_settings', array(__CLASS__, 'handle_settings'));
    }

    public static function menu() {
        $waiting = GC_Works::waiting();
        $label = 'Курс';
        if ($waiting > 0) {
            $label .= ' <span class="update-plugins count-' . (int) $waiting . '"><span class="plugin-count">'
                    . (int) $waiting . '</span></span>';
        }
        add_menu_page('Курс', $label, 'manage_options', self::SLUG, array(__CLASS__, 'render'), 'dashicons-welcome-learn-more', 31);
    }

    private static function tab() {
        $tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'students';
        return in_array($tab, array('students', 'works', 'jobs', 'settings'), true) ? $tab : 'students';
    }

    private static function url($tab = '', $args = array()) {
        $args = array_merge(array('page' => self::SLUG), $tab !== '' ? array('tab' => $tab) : array(), $args);
        return add_query_arg($args, admin_url('admin.php'));
    }

    public static function render() {
        if (!current_user_can('manage_options')) {
            return;
        }
        $tab = self::tab();
        ?>
        <div class="wrap">
            <h1>Курс «Обучение AI-креатора»</h1>
            <h2 class="nav-tab-wrapper">
                <a class="nav-tab <?php echo $tab === 'students' ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url(self::url('students')); ?>">Ученики</a>
                <a class="nav-tab <?php echo $tab === 'works' ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url(self::url('works')); ?>">Работы<?php
                    $waiting = GC_Works::waiting();
                    echo $waiting > 0 ? ' (' . (int) $waiting . ')' : ''; ?></a>
                <a class="nav-tab <?php echo $tab === 'jobs' ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url(self::url('jobs')); ?>">Генерации</a>
                <a class="nav-tab <?php echo $tab === 'settings' ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url(self::url('settings')); ?>">Настройки</a>
            </h2>
            <?php
            self::notice();
            if ($tab === 'works') {
                self::works();
            } elseif ($tab === 'jobs') {
                self::jobs();
            } elseif ($tab === 'settings') {
                self::settings();
            } else {
                self::students();
            }
            ?>
        </div>
        <?php
    }

    /**
     * Сообщение после действия.
     *
     * Пароль показываем ровно один раз и прямо здесь: в базе он лежит
     * только свёрткой, и второй возможности его увидеть не будет.
     */
    private static function notice() {
        if (!empty($_GET['gc_login'])) {
            $login = sanitize_text_field(wp_unslash($_GET['gc_login']));
            $pass = isset($_GET['gc_pass']) ? sanitize_text_field(wp_unslash($_GET['gc_pass'])) : '';
            ?>
            <div class="notice notice-success">
                <p><strong>Доступ готов. Скопируйте и отправьте ученику — второй раз пароль не покажется.</strong></p>
                <p><code>Вход: <?php echo esc_html(GC_Cabinet::url()); ?><br>
                    Логин: <?php echo esc_html($login); ?><br>
                    Пароль: <?php echo esc_html($pass); ?></code></p>
            </div>
            <?php
        }
        if (!empty($_GET['gc_msg'])) {
            echo '<div class="notice notice-info"><p>' . esc_html(sanitize_text_field(wp_unslash($_GET['gc_msg']))) . '</p></div>';
        }
        if (!empty($_GET['gc_err'])) {
            echo '<div class="notice notice-error"><p>' . esc_html(sanitize_text_field(wp_unslash($_GET['gc_err']))) . '</p></div>';
        }
    }

    private static function students() {
        $students = GC_Students::all();
        ?>
        <h3>Выдать доступ</h3>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="gc-admin-form">
            <?php wp_nonce_field('gc_create'); ?>
            <input type="hidden" name="action" value="gc_create">
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="gc-email">Почта ученика</label></th>
                    <td><input id="gc-email" name="email" type="email" class="regular-text" required>
                        <p class="description">На неё уходит восстановление пароля — почта должна быть настоящей.</p></td>
                </tr>
                <tr>
                    <th scope="row"><label for="gc-name">Имя</label></th>
                    <td><input id="gc-name" name="name" type="text" class="regular-text"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="gc-plan">Тариф</label></th>
                    <td><select id="gc-plan" name="plan">
                        <option value="базовый">Базовый</option>
                        <option value="расширенный">Расширенный</option>
                    </select></td>
                </tr>
                <tr>
                    <th scope="row"><label for="gc-days">Генерации без лимита</label></th>
                    <td><input id="gc-days" name="gpu_days" type="number" min="0" max="365" value="30" class="small-text"> дней</td>
                </tr>
                <tr>
                    <th scope="row"><label for="gc-note">Заметка</label></th>
                    <td><textarea id="gc-note" name="note" rows="2" class="large-text" placeholder="Откуда пришёл, что оплатил, о чём договорились"></textarea></td>
                </tr>
            </table>
            <p><button class="button button-primary">Создать доступ</button></p>
        </form>

        <h3>Ученики (<?php echo count($students); ?>)</h3>
        <table class="widefat striped">
            <thead><tr><th>Ученик</th><th>Логин</th><th>Тариф</th><th>Прогресс</th><th>Генерации до</th><th>Действия</th></tr></thead>
            <tbody>
            <?php if (!$students) : ?>
                <tr><td colspan="6">Пока никого. Выдайте первый доступ формой выше.</td></tr>
            <?php endif; ?>
            <?php foreach ($students as $u) :
                $done = count(GC_Students::progress($u->ID));
                $until = GC_Students::gpu_until($u->ID);
                ?>
                <tr>
                    <td><strong><?php echo esc_html($u->display_name); ?></strong><br><span class="description"><?php echo esc_html($u->user_email); ?></span>
                        <?php $note = get_user_meta($u->ID, GC_Students::META_NOTE, true); ?>
                        <?php if ($note) : ?><br><span class="description"><?php echo esc_html($note); ?></span><?php endif; ?>
                    </td>
                    <td><code><?php echo esc_html($u->user_login); ?></code></td>
                    <td><?php echo esc_html((string) get_user_meta($u->ID, GC_Students::META_PLAN, true)); ?></td>
                    <td><?php echo (int) $done; ?> из <?php echo (int) GC_Program::count(); ?></td>
                    <td><?php echo $until ? esc_html(date_i18n('j M Y', strtotime($until))) : '—'; ?></td>
                    <td>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline">
                            <?php wp_nonce_field('gc_reset'); ?>
                            <input type="hidden" name="action" value="gc_reset">
                            <input type="hidden" name="user" value="<?php echo (int) $u->ID; ?>">
                            <button class="button button-small">Новый пароль</button>
                        </form>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline">
                            <?php wp_nonce_field('gc_gpu_days'); ?>
                            <input type="hidden" name="action" value="gc_gpu_days">
                            <input type="hidden" name="user" value="<?php echo (int) $u->ID; ?>">
                            <input type="number" name="days" value="30" min="0" max="365" class="small-text">
                            <button class="button button-small">Продлить</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    private static function works() {
        $queue = GC_Works::queue();
        ?>
        <h3>Очередь проверки</h3>
        <p class="description">Сверху то, что ждёт дольше всех. Разбор ученик видит в кабинете сразу после сохранения.</p>
        <?php if (!$queue) : ?>
            <p>Работ пока нет.</p>
            <?php return; ?>
        <?php endif; ?>
        <?php foreach ($queue as $w) :
            $u = get_user_by('id', (int) $w['user_id']);
            $m = GC_Program::module((int) $w['module']);
            ?>
            <div class="card" style="max-width:900px;margin-bottom:16px">
                <h4 style="margin-top:0">
                    <?php echo esc_html($u ? $u->display_name : ('id ' . (int) $w['user_id'])); ?>
                    — модуль <?php echo (int) ($w['module'] + 1); ?><?php echo $m ? ': ' . esc_html($m['title']) : ''; ?>
                    <span class="description"><?php echo esc_html(date_i18n('j F, H:i', strtotime($w['created_at']))); ?></span>
                </h4>
                <?php if (!empty($w['links'])) : ?>
                    <p><strong>Ссылки:</strong><br><?php echo nl2br(esc_html($w['links'])); ?></p>
                <?php endif; ?>
                <?php if (!empty($w['body'])) : ?>
                    <div><?php echo wp_kses_post(wpautop($w['body'])); ?></div>
                <?php endif; ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('gc_review'); ?>
                    <input type="hidden" name="action" value="gc_review">
                    <input type="hidden" name="id" value="<?php echo (int) $w['id']; ?>">
                    <p><textarea name="feedback" rows="4" class="large-text" placeholder="Что переделать и почему"><?php echo esc_textarea((string) $w['feedback']); ?></textarea></p>
                    <p>
                        <select name="status">
                            <option value="sent" <?php selected($w['status'], 'sent'); ?>>Ждёт проверки</option>
                            <option value="accepted" <?php selected($w['status'], 'accepted'); ?>>Принять</option>
                            <option value="rework" <?php selected($w['status'], 'rework'); ?>>На доработку</option>
                        </select>
                        <button class="button button-primary">Сохранить разбор</button>
                    </p>
                </form>
            </div>
        <?php endforeach; ?>
        <?php
    }

    private static function jobs() {
        $jobs = GC_Gpu::recent();
        $health = GC_Gpu::health();
        ?>
        <h3>Генерации</h3>
        <p>Машина: <strong><?php echo $health['ok'] ? 'на связи' : 'недоступна'; ?></strong>
            <span class="description"><?php echo esc_html($health['message']); ?></span></p>
        <table class="widefat striped">
            <thead><tr><th>№</th><th>Ученик</th><th>Описание</th><th>Состояние</th><th>Результат</th><th>Когда</th></tr></thead>
            <tbody>
            <?php if (!$jobs) : ?><tr><td colspan="6">Пока пусто.</td></tr><?php endif; ?>
            <?php foreach ($jobs as $j) :
                $u = get_user_by('id', (int) $j['user_id']);
                ?>
                <tr>
                    <td><?php echo (int) $j['id']; ?></td>
                    <td><?php echo esc_html($u ? $u->display_name : ('id ' . (int) $j['user_id'])); ?></td>
                    <td><?php echo esc_html(mb_substr((string) $j['prompt'], 0, 90)); ?></td>
                    <td><?php echo esc_html($j['status']); ?><?php if (!empty($j['error'])) : ?><br><span class="description"><?php echo esc_html(mb_substr((string) $j['error'], 0, 90)); ?></span><?php endif; ?></td>
                    <td><?php if (!empty($j['result_url'])) : ?><a href="<?php echo esc_url($j['result_url']); ?>" target="_blank">кадр</a><?php else : ?>—<?php endif; ?></td>
                    <td><?php echo esc_html(date_i18n('j M, H:i', strtotime($j['created_at']))); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    private static function settings() {
        $s = GC_Gpu::settings();
        $health = GC_Gpu::health();
        ?>
        <h3>Машина с видеокартой</h3>
        <p class="description">Арендуется на vast.ai, сервис из папки <code>worker</code> плагина. Подробности —
            в <code>worker/README.md</code>: там и команда запуска, и что открывать в портах.</p>
        <p>Состояние: <strong><?php echo $health['ok'] ? 'на связи' : 'недоступна'; ?></strong>
            <span class="description"><?php echo esc_html($health['message']); ?></span></p>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('gc_settings'); ?>
            <input type="hidden" name="action" value="gc_settings">
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">Генерации включены</th>
                    <td><label><input type="checkbox" name="enabled" value="1" <?php checked(!empty($s['enabled'])); ?>>
                        принимать задания</label>
                        <p class="description">Выключено — задания копятся в очереди и уйдут, когда машину включат.</p></td>
                </tr>
                <tr>
                    <th scope="row"><label for="gc-endpoint">Адрес машины</label></th>
                    <td><input id="gc-endpoint" name="endpoint" type="url" class="regular-text code" value="<?php echo esc_attr($s['endpoint']); ?>" placeholder="https://123.45.67.89:18080">
                        <p class="description">Адрес и порт, которые vast.ai показывает для вашего инстанса.</p></td>
                </tr>
                <tr>
                    <th scope="row"><label for="gc-token">Токен</label></th>
                    <td><input id="gc-token" name="token" type="text" class="regular-text code" value="<?php echo esc_attr($s['token']); ?>">
                        <p class="description">Тот же, что в переменной <code>GC_TOKEN</code> на машине.</p></td>
                </tr>
                <tr>
                    <th scope="row">Ключ обратного вызова</th>
                    <td><code><?php echo esc_html($s['secret']); ?></code>
                        <p class="description">Положите его на машину в <code>GC_SECRET</code>. Им подписывается результат.</p></td>
                </tr>
                <tr>
                    <th scope="row"><label for="gc-model">Модель</label></th>
                    <td><input id="gc-model" name="model" type="text" class="regular-text code" value="<?php echo esc_attr($s['model']); ?>"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="gc-daily">Заданий в сутки на ученика</label></th>
                    <td><input id="gc-daily" name="daily" type="number" min="1" max="5000" value="<?php echo (int) $s['daily']; ?>" class="small-text">
                        <p class="description">Не ограничение для человека, а предохранитель от зацикленного скрипта.</p></td>
                </tr>
            </table>
            <p><button class="button button-primary">Сохранить</button></p>
        </form>
        <h3>Адрес кабинета</h3>
        <p><a href="<?php echo esc_url(GC_Cabinet::url()); ?>" target="_blank"><?php echo esc_html(GC_Cabinet::url()); ?></a></p>
        <?php
    }

    /* ---------------------------------------------------------------------
     * Обработчики
     * ------------------------------------------------------------------ */

    public static function handle_create() {
        check_admin_referer('gc_create');
        if (!current_user_can('manage_options')) {
            wp_die('Недостаточно прав');
        }
        $out = GC_Students::create(
            (string) ($_POST['email'] ?? ''),
            (string) ($_POST['name'] ?? ''),
            (string) ($_POST['plan'] ?? 'базовый'),
            (string) ($_POST['note'] ?? ''),
            (int) ($_POST['gpu_days'] ?? 30)
        );
        if (empty($out['ok'])) {
            wp_safe_redirect(self::url('students', array('gc_err' => $out['message'])));
            exit;
        }
        wp_safe_redirect(self::url('students', array('gc_login' => $out['login'], 'gc_pass' => $out['password'])));
        exit;
    }

    public static function handle_reset() {
        check_admin_referer('gc_reset');
        if (!current_user_can('manage_options')) {
            wp_die('Недостаточно прав');
        }
        $user_id = (int) ($_POST['user'] ?? 0);
        $pass = GC_Students::reset_password($user_id);
        $user = get_user_by('id', $user_id);
        if ($pass === '' || !$user) {
            wp_safe_redirect(self::url('students', array('gc_err' => 'Ученик не найден')));
            exit;
        }
        wp_safe_redirect(self::url('students', array('gc_login' => $user->user_login, 'gc_pass' => $pass)));
        exit;
    }

    public static function handle_gpu_days() {
        check_admin_referer('gc_gpu_days');
        if (!current_user_can('manage_options')) {
            wp_die('Недостаточно прав');
        }
        $user_id = (int) ($_POST['user'] ?? 0);
        $days = (int) ($_POST['days'] ?? 30);
        GC_Students::set_gpu_days($user_id, $days);
        wp_safe_redirect(self::url('students', array('gc_msg' => 'Срок генераций обновлён: ' . $days . ' дней с сегодня')));
        exit;
    }

    public static function handle_review() {
        check_admin_referer('gc_review');
        if (!current_user_can('manage_options')) {
            wp_die('Недостаточно прав');
        }
        GC_Works::review((int) ($_POST['id'] ?? 0), (string) ($_POST['status'] ?? 'sent'), (string) ($_POST['feedback'] ?? ''));
        wp_safe_redirect(self::url('works', array('gc_msg' => 'Разбор сохранён')));
        exit;
    }

    public static function handle_settings() {
        check_admin_referer('gc_settings');
        if (!current_user_can('manage_options')) {
            wp_die('Недостаточно прав');
        }
        GC_Gpu::save_settings((array) $_POST);
        delete_transient('gc_gpu_health');
        wp_safe_redirect(self::url('settings', array('gc_msg' => 'Настройки сохранены')));
        exit;
    }
}
