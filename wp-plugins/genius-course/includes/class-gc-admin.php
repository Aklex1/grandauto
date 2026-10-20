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
        add_action('admin_post_gc_vast', array(__CLASS__, 'handle_vast'));
        add_action('admin_post_gc_rent', array(__CLASS__, 'handle_rent'));
        add_action('admin_post_gc_stop', array(__CLASS__, 'handle_stop'));
        add_action('admin_post_gc_use', array(__CLASS__, 'handle_use'));
        add_action('admin_post_gc_fresh', array(__CLASS__, 'handle_fresh'));
        add_action('admin_post_gc_exec', array(__CLASS__, 'handle_exec'));
        add_action('admin_post_gc_state', array(__CLASS__, 'handle_state'));
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
        return in_array($tab, array('students', 'works', 'jobs', 'machine', 'settings'), true) ? $tab : 'students';
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
                <a class="nav-tab <?php echo $tab === 'machine' ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url(self::url('machine')); ?>">Машина</a>
                <a class="nav-tab <?php echo $tab === 'settings' ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url(self::url('settings')); ?>">Настройки</a>
            </h2>
            <?php
            self::notice();
            if ($tab === 'works') {
                self::works();
            } elseif ($tab === 'jobs') {
                self::jobs();
            } elseif ($tab === 'machine') {
                self::machine();
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

    /**
     * Аренда видеокарты.
     *
     * Здесь три разных действия, и их нарочно не смешали в одну кнопку:
     * найти предложение, арендовать и подключить к генерациям. Аренда
     * тратит деньги с общего баланса, поэтому она всегда отдельное
     * осознанное нажатие.
     */
    private static function machine() {
        $v = GC_Vast::settings();
        $gpu = GC_Gpu::settings();
        ?>
        <h3>Ключ Vast</h3>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('gc_vast'); ?>
            <input type="hidden" name="action" value="gc_vast">
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="gc-vast-key">Ключ API</label></th>
                    <td>
                        <input id="gc-vast-key" name="key" type="password" class="regular-text code" autocomplete="off"
                               placeholder="<?php echo GC_Vast::has_key() ? esc_attr('сохранён: ' . GC_Vast::key_hint()) : 'вставьте ключ'; ?>">
                        <p class="description">Ключ лежит только здесь, на сервере. В кабинет ученика и в код страницы он
                            не попадает: это ключ управления арендой, а не генерации.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="gc-vast-gpu">Какую карту искать</label></th>
                    <td><input id="gc-vast-gpu" name="gpu" type="text" class="regular-text" value="<?php echo esc_attr($v['gpu']); ?>">
                        <p class="description">Название как у Vast: RTX 4090, RTX 3090, L40S.</p></td>
                </tr>
                <tr>
                    <th scope="row"><label for="gc-vast-price">Потолок цены</label></th>
                    <td><input id="gc-vast-price" name="max_price" type="number" step="0.05" min="0.05" max="10" value="<?php echo esc_attr($v['max_price']); ?>" class="small-text"> $ в час</td>
                </tr>
                <tr>
                    <th scope="row"><label for="gc-vast-disk">Диск</label></th>
                    <td><input id="gc-vast-disk" name="disk" type="number" min="10" max="500" value="<?php echo (int) $v['disk']; ?>" class="small-text"> ГБ
                        <p class="description">Веса SDXL и моделей видео занимают десятки гигабайт.</p></td>
                </tr>
                <tr>
                    <th scope="row"><label for="gc-vast-image">Образ</label></th>
                    <td><input id="gc-vast-image" name="image" type="text" class="large-text code" value="<?php echo esc_attr($v['image']); ?>">
                        <p class="description">Образ с уже установленным ComfyUI. Порт <?php echo (int) GC_Vast::COMFY_PORT; ?> прокидывается наружу автоматически.</p></td>
                </tr>
                <tr>
                    <th scope="row"><label for="gc-vast-onstart">Скрипт запуска</label></th>
                    <td><textarea id="gc-vast-onstart" name="onstart" rows="8" class="large-text code"
                                  placeholder="пусто — берём встроенный"><?php echo esc_textarea($v['onstart']); ?></textarea>
                        <p class="description">Выполняется один раз после старта машины. Пустое поле — встроенный
                            скрипт: он находит папку ComfyUI и качает туда SDXL, LTX-Video и текстовый кодировщик.
                            Правится только если нужны другие веса.</p>
                        <details><summary>Показать встроенный</summary>
                            <pre class="code" style="white-space:pre-wrap"><?php echo esc_html(GC_Vast::default_onstart()); ?></pre>
                        </details></td>
                </tr>
            </table>
            <p><button class="button button-primary">Сохранить</button>
               <button class="button" name="check" value="1">Сохранить и проверить ключ</button></p>
        </form>

        <?php if (GC_Vast::has_key()) : ?>
            <h3>Наши машины</h3>
            <?php $list = GC_Vast::instances(); ?>
            <?php if (!$list['ok']) : ?>
                <div class="notice notice-error inline"><p>Vast не ответил: <?php echo esc_html($list['message']); ?></p></div>
            <?php elseif (!$list['items']) : ?>
                <p>Сейчас ни одной машины не арендовано.</p>
            <?php else : ?>
                <table class="widefat striped">
                    <thead><tr><th>№</th><th>Карта</th><th>Состояние</th><th>Цена</th><th>Адрес ComfyUI</th><th>Действия</th></tr></thead>
                    <tbody>
                    <?php foreach ($list['items'] as $i) : ?>
                        <tr>
                            <td><?php echo (int) $i['id']; ?></td>
                            <td><?php echo esc_html($i['num'] . '× ' . $i['gpu']); ?></td>
                            <td><?php echo esc_html($i['status']); ?>
                                <?php if (!empty($i['msg'])) : ?>
                                    <br><span class="description"><?php echo esc_html(mb_substr($i['msg'], 0, 200)); ?></span>
                                <?php endif; ?></td>
                            <td><?php echo esc_html('$' . $i['price'] . '/час'); ?></td>
                            <td><?php if ($i['url'] !== '') : ?>
                                    <a href="<?php echo esc_url($i['url']); ?>" target="_blank"><?php echo esc_html($i['url']); ?></a>
                                <?php else : ?><span class="description">порт ещё не выдан</span><?php endif; ?>
                                <?php if (!empty($i['ports'])) : ?>
                                    <br><span class="description"><?php echo esc_html($i['ports']); ?></span>
                                <?php endif; ?></td>
                            <td>
                                <?php if ($i['url'] !== '') : ?>
                                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline">
                                        <?php wp_nonce_field('gc_use'); ?>
                                        <input type="hidden" name="action" value="gc_use">
                                        <input type="hidden" name="url" value="<?php echo esc_attr($i['url']); ?>">
                                        <button class="button button-small button-primary">Использовать для генераций</button>
                                    </form>
                                <?php endif; ?>
                                <?php $live = strpos($i['status'], 'running') !== false; ?>
                                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline">
                                    <?php wp_nonce_field('gc_state'); ?>
                                    <input type="hidden" name="action" value="gc_state">
                                    <input type="hidden" name="id" value="<?php echo (int) $i['id']; ?>">
                                    <input type="hidden" name="state" value="<?php echo $live ? 'stopped' : 'running'; ?>">
                                    <button class="button button-small"><?php echo $live ? 'Пауза' : 'Запустить'; ?></button>
                                </form>
                                <?php if (!$live) : ?>
                                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline">
                                        <?php wp_nonce_field('gc_exec'); ?>
                                        <input type="hidden" name="action" value="gc_exec">
                                        <input type="hidden" name="id" value="<?php echo (int) $i['id']; ?>">
                                        <button class="button button-small">Докачать модели</button>
                                    </form>
                                <?php endif; ?>
                                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline"
                                      onsubmit="return confirm('Остановить машину? Аренда прекратится.');">
                                    <?php wp_nonce_field('gc_stop'); ?>
                                    <input type="hidden" name="action" value="gc_stop">
                                    <input type="hidden" name="id" value="<?php echo (int) $i['id']; ?>">
                                    <button class="button button-small">Остановить</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <p class="description">Веса качает скрипт запуска. Если на машине их не оказалось — «Пауза», затем
                «Запустить»: скрипт выполнится заново, а диск с уже скачанным останется. Команды на работающей
                машине Vast через API не разрешает, только по ssh.</p>

            <h3>Свободные предложения</h3>
            <p class="description">Дешёвые сверху. Аренда списывает деньги с общего баланса Vast — нажатие сразу
                запускает машину.</p>
            <?php $offers = GC_Vast::offers(); ?>
            <?php if (!$offers['ok']) : ?>
                <div class="notice notice-error inline"><p>Поиск не прошёл: <?php echo esc_html($offers['message']); ?></p></div>
            <?php elseif (!$offers['items']) : ?>
                <p>По заданным условиям ничего нет. Поднимите потолок цены или смените карту.</p>
            <?php else : ?>
                <table class="widefat striped">
                    <thead><tr><th>Карта</th><th>Память</th><th>Цена</th><th>Диск</th><th>Канал</th><th>Где</th><th>Надёжность</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($offers['items'] as $o) : ?>
                        <tr>
                            <td><?php echo esc_html($o['num'] . '× ' . $o['gpu']); ?></td>
                            <td><?php echo (int) $o['ram']; ?> ГБ</td>
                            <td><strong><?php echo esc_html('$' . $o['price']); ?></strong>/час</td>
                            <td><?php echo (int) $o['disk']; ?> ГБ</td>
                            <td><?php echo (int) $o['down']; ?> Мбит</td>
                            <td><?php echo esc_html($o['place']); ?></td>
                            <td><?php echo esc_html($o['rel']); ?>%</td>
                            <td>
                                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                                      onsubmit="return confirm('Арендовать за $<?php echo esc_attr($o['price']); ?> в час? Деньги спишутся с баланса Vast.');">
                                    <?php wp_nonce_field('gc_rent'); ?>
                                    <input type="hidden" name="action" value="gc_rent">
                                    <input type="hidden" name="offer" value="<?php echo (int) $o['id']; ?>">
                                    <button class="button button-small">Арендовать</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <h3>Что сейчас подключено к генерациям</h3>
            <p><?php echo $gpu['endpoint'] !== '' ? esc_html($gpu['endpoint']) : 'адрес не задан'; ?>
                — <?php echo !empty($gpu['enabled']) ? 'генерации включены' : 'генерации выключены'; ?>,
                драйвер <?php echo esc_html($gpu['driver']); ?>.</p>
            <?php if ($gpu['endpoint'] !== '') : ?>
                <?php $models = GC_Gpu::models($gpu); ?>
                <h3>Что лежит на машине</h3>
                <?php if (!$models['ok']) : ?>
                    <p class="description">Машина не отвечает: <?php echo esc_html($models['message']); ?>.
                        Пока качаются веса, это нормально.</p>
                <?php else : ?>
                    <p>Модели: <?php echo $models['ckpt']
                        ? '<code>' . implode('</code>, <code>', array_map('esc_html', $models['ckpt'])) . '</code>'
                        : '<span class="description">ни одной — скрипт запуска ещё качает</span>'; ?></p>
                    <p>Текстовые кодировщики: <?php echo $models['clip']
                        ? '<code>' . implode('</code>, <code>', array_map('esc_html', $models['clip'])) . '</code>'
                        : '<span class="description">нет</span>'; ?></p>
                <?php endif; ?>
                <?php $exec = GC_Vast::exec_output(); ?>
                <?php if ($exec['ok'] || $exec['message'] !== 'команд ещё не было') : ?>
                    <h3>Вывод последней команды</h3>
                    <?php if (!$exec['ok']) : ?>
                        <p class="description"><?php echo esc_html($exec['message']); ?></p>
                    <?php else : ?>
                        <pre class="code" style="white-space:pre-wrap;max-height:260px;overflow:auto"><?php
                            echo esc_html(mb_substr($exec['text'], -4000)); ?></pre>
                    <?php endif; ?>
                <?php endif; ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('gc_fresh'); ?>
                    <input type="hidden" name="action" value="gc_fresh">
                    <button class="button">Спросить машину заново</button>
                </form>
            <?php endif; ?>
        <?php endif; ?>
        <?php
    }

    public static function handle_state() {
        if (!current_user_can('manage_options')) {
            wp_die('Нет доступа');
        }
        check_admin_referer('gc_state');
        $out = GC_Vast::state((int) ($_POST['id'] ?? 0), (string) ($_POST['state'] ?? ''));
        GC_Gpu::fresh();
        wp_safe_redirect(self::url('machine', $out['ok']
            ? array('gc_msg' => $out['message'])
            : array('gc_err' => $out['message'])));
        exit;
    }

    public static function handle_exec() {
        if (!current_user_can('manage_options')) {
            wp_die('Нет доступа');
        }
        check_admin_referer('gc_exec');
        // Своя команда — для разбора неполадок; пустое поле означает
        // обычное «докачать модели».
        $cmd = trim((string) wp_unslash($_POST['cmd'] ?? ''));
        $out = GC_Vast::exec((int) ($_POST['id'] ?? 0), $cmd !== '' ? $cmd : GC_Vast::weights_command());
        GC_Gpu::fresh();
        wp_safe_redirect(self::url('machine', $out['ok']
            ? array('gc_msg' => 'Скрипт отправлен на машину. Вывод появится ниже через минуту-другую.')
            : array('gc_err' => $out['message'])));
        exit;
    }

    public static function handle_fresh() {
        if (!current_user_can('manage_options')) {
            wp_die('Нет доступа');
        }
        check_admin_referer('gc_fresh');
        GC_Gpu::fresh();
        wp_safe_redirect(self::url('machine', array('gc_msg' => 'Ответы машины спрошены заново.')));
        exit;
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
        <?php if ($health['ok'] && $s['driver'] === 'comfy') : ?>
            <?php
            // Сверяем заготовки с машиной: какие узлы она знает. Так
            // видно заранее, что ролики не поедут, а не после жалобы
            // ученика.
            $checks = array('кадры' => GC_Gpu::missing_nodes('image', false, $s));
            if (GC_Gpu::video_allowed($s)) {
                $checks['ролики'] = GC_Gpu::missing_nodes('video', false, $s);
            }
            foreach ($checks as $label => $miss) :
                ?>
                <p><?php echo esc_html(ucfirst($label)); ?>:
                    <?php if ($miss) : ?>
                        <strong style="color:#b32d2e">нет узлов <?php echo esc_html(implode(', ', $miss)); ?></strong>
                        <span class="description">— нужен образ с ними, смотрите <code>worker/CLOUD.md</code>.</span>
                    <?php else : ?>
                        <strong style="color:#1a7f37">узлы на месте</strong>
                    <?php endif; ?>
                </p>
            <?php endforeach; ?>
        <?php endif; ?>
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
                    <th scope="row">Чем считаем</th>
                    <td>
                        <label><input type="radio" name="driver" value="comfy" <?php checked($s['driver'], 'comfy'); ?>>
                            ComfyUI на арендованной машине</label><br>
                        <label><input type="radio" name="driver" value="worker" <?php checked($s['driver'], 'worker'); ?>>
                            своя служба из папки worker</label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="gc-model">Файл модели для кадров</label></th>
                    <td><input id="gc-model" name="model" type="text" class="regular-text code" value="<?php echo esc_attr($s['model']); ?>">
                        <p class="description">Имя как в папке моделей ComfyUI, например <code>sd_xl_base_1.0.safetensors</code>.</p></td>
                </tr>
                <tr>
                    <th scope="row">Ролики</th>
                    <td><label><input type="checkbox" name="video" value="1" <?php checked(!empty($s['video'])); ?>>
                        разрешить видео ученикам</label>
                        <p class="description">Без своей заготовки берём встроенную — LTX-Video, узлы для него есть
                            в самом ComfyUI. Нужен только файл весов на машине.</p></td>
                </tr>
                <tr>
                    <th scope="row"><label for="gc-model-video">Файл модели для роликов</label></th>
                    <td><input id="gc-model-video" name="model_video" type="text" class="regular-text code" value="<?php echo esc_attr($s['model_video']); ?>">
                        <p class="description">Например <code>ltx-video-2b-v0.9.5.safetensors</code>. Подставляется
                            как <code>%VMODEL%</code>.</p></td>
                </tr>
                <tr>
                    <th scope="row"><label for="gc-clip-video">Текстовый кодировщик для роликов</label></th>
                    <td><input id="gc-clip-video" name="clip_video" type="text" class="regular-text code" value="<?php echo esc_attr($s['clip_video']); ?>">
                        <p class="description">Лежит в <code>models/text_encoders</code>, обычно
                            <code>t5xxl_fp16.safetensors</code>. Подставляется как <code>%VCLIP%</code>.</p></td>
                </tr>
                <tr>
                    <th scope="row"><label for="gc-wf-image">Запрос для кадров</label></th>
                    <td><textarea id="gc-wf-image" name="wf_image" rows="6" class="large-text code" placeholder="пусто — берём встроенный SDXL"><?php echo esc_textarea($s['wf_image']); ?></textarea>
                        <p class="description">Граф ComfyUI в формате для API. Подстановки:
                            <code>%PROMPT%</code>, <code>%NEGATIVE%</code>, <code>%MODEL%</code>,
                            <code>%WIDTH%</code>, <code>%HEIGHT%</code>, <code>%STEPS%</code>,
                            <code>%CFG%</code>, <code>%STRENGTH%</code>, <code>%REFERENCE%</code>,
                            <code>%SEED%</code>. Пусто — берём встроенный SDXL, а с референсом его же
                            через <code>VAEEncode</code>.</p></td>
                </tr>
                <tr>
                    <th scope="row"><label for="gc-wf-video">Запрос для роликов</label></th>
                    <td><textarea id="gc-wf-video" name="wf_video" rows="6" class="large-text code" placeholder="пусто — встроенный LTX-Video"><?php echo esc_textarea($s['wf_video']); ?></textarea>
                        <p class="description">Для другой модели — Wan, Hunyuan — вставьте сюда её граф.
                            Дополнительно доступны <code>%VMODEL%</code>, <code>%FRAMES%</code>,
                            <code>%FPS%</code>. Размеры и длину подгоняем под сетку модели сами.</p></td>
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

    public static function handle_vast() {
        check_admin_referer('gc_vast');
        if (!current_user_can('manage_options')) {
            wp_die('Недостаточно прав');
        }
        GC_Vast::save((array) $_POST);
        $msg = 'Настройки аренды сохранены';
        if (!empty($_POST['check'])) {
            $list = GC_Vast::instances();
            $msg = $list['ok']
                ? sprintf('Ключ рабочий. Машин на аккаунте: %d.', count($list['items']))
                : ('Ключ не подошёл: ' . $list['message']);
        }
        wp_safe_redirect(self::url('machine', array('gc_msg' => $msg)));
        exit;
    }

    public static function handle_rent() {
        check_admin_referer('gc_rent');
        if (!current_user_can('manage_options')) {
            wp_die('Недостаточно прав');
        }
        $out = GC_Vast::rent((int) ($_POST['offer'] ?? 0));
        wp_safe_redirect(self::url('machine', $out['ok']
            ? array('gc_msg' => $out['message'] . ' Номер: ' . (int) $out['id'] . '. Адрес появится через минуту-две.')
            : array('gc_err' => $out['message'])));
        exit;
    }

    public static function handle_stop() {
        check_admin_referer('gc_stop');
        if (!current_user_can('manage_options')) {
            wp_die('Недостаточно прав');
        }
        $out = GC_Vast::stop((int) ($_POST['id'] ?? 0));
        wp_safe_redirect(self::url('machine', $out['ok']
            ? array('gc_msg' => $out['message'])
            : array('gc_err' => $out['message'])));
        exit;
    }

    /**
     * Подключить машину к генерациям.
     *
     * Одним нажатием: адрес, драйвер ComfyUI и включение — иначе человек
     * копирует адрес руками и ошибается в порте.
     */
    public static function handle_use() {
        check_admin_referer('gc_use');
        if (!current_user_can('manage_options')) {
            wp_die('Недостаточно прав');
        }
        $url = esc_url_raw((string) ($_POST['url'] ?? ''));
        if ($url === '') {
            wp_safe_redirect(self::url('machine', array('gc_err' => 'Пустой адрес')));
            exit;
        }
        $now = GC_Gpu::settings();
        $now['endpoint'] = $url;
        $now['driver'] = 'comfy';
        $now['enabled'] = 1;
        GC_Gpu::save_settings($now);
        delete_transient('gc_gpu_health');
        $health = GC_Gpu::health();
        wp_safe_redirect(self::url('machine', array(
            'gc_msg' => $health['ok']
                ? ('Подключено: ' . $health['message'])
                : ('Адрес сохранён, но машина пока не отвечает: ' . $health['message']),
        )));
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
