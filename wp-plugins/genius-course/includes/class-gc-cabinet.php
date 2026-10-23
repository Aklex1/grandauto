<?php
/**
 * Личный кабинет.
 *
 * Одна страница с шорткодом: список модулей, открытый модуль, сдача
 * работы и мастерская генераций. Своего шаблона темы не трогаем — тема
 * на сайте одна и меняться не собирается, а лишний шаблон пришлось бы
 * чинить при каждом её обновлении.
 *
 * Кабинетов теперь два — по одному на курс, и оба собираются этим же
 * кодом: отличаются страница, программа и правило доступа, а всё
 * остальное у них одинаковое. Какой курс показывать, решает атрибут
 * шорткода на странице, а не учётка посетителя: администратор заходит
 * смотреть любой из кабинетов.
 */

if (!defined('ABSPATH')) {
    exit;
}

class GC_Cabinet {

    /** Страница курса AI-креатора: была первой и адрес менять нельзя. */
    const SLUG     = 'kabinet-kursa';
    const OPT_PAGE = 'gc_cabinet_page';

    public static function boot() {
        add_shortcode('genius_course_cabinet', array(__CLASS__, 'render'));
        add_action('wp_enqueue_scripts', array(__CLASS__, 'assets'));
        add_filter('body_class', array(__CLASS__, 'body_class'));
        // Кабинет в поиске не нужен: он пустой для всех, кроме ученика.
        add_action('wp_head', array(__CLASS__, 'noindex'), 1);
        add_filter('get_pages', array(__CLASS__, 'hide_from_sitemap'), 10, 1);
    }

    /**
     * Кабинеты не попадают в карту сайта.
     *
     * Карту собирает сторонний плагин: он берёт все опубликованные
     * страницы через get_pages() и про noindex не спрашивает. Робот
     * ходит по такому адресу впустую, а в вебмастере копятся страницы
     * «исключено по noindex» — поэтому убираем их из выдачи get_pages,
     * но только на запросе карты: в меню, списках и выпадашках админки
     * страницы кабинетов нужны как обычные.
     */
    public static function hide_from_sitemap($pages) {
        if (!is_array($pages) || !$pages || !self::sitemap_request()) {
            return $pages;
        }
        $ours = array();
        foreach (GC_Courses::all() as $def) {
            $id = (int) get_option((string) $def['option']);
            if ($id > 0) {
                $ours[] = $id;
            }
        }
        if (!$ours) {
            return $pages;
        }
        $kept = array();
        foreach ($pages as $page) {
            if (!is_object($page) || !isset($page->ID) || !in_array((int) $page->ID, $ours, true)) {
                $kept[] = $page;
            }
        }
        return $kept;
    }

    /** Запрос карты сайта: /sitemap.xml или /?sitemap=xml. */
    public static function sitemap_request() {
        if (isset($_GET['sitemap']) && $_GET['sitemap'] === 'xml') {
            return true;
        }
        $uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
        return $uri !== '' && strpos($uri, '/sitemap') !== false;
    }

    /* ---------------------------------------------------------------------
     * Страницы кабинетов
     * ------------------------------------------------------------------ */

    /** Страницы под все курсы: вызывается при включении и обновлении. */
    public static function ensure_pages() {
        foreach (GC_Courses::keys() as $course) {
            self::ensure_page($course);
        }
    }

    public static function ensure_page($course = GC_Courses::DEFAULT_KEY) {
        /*
         * Раньше init страницу не создаём. wp_insert_post до этого
         * момента выбрасывает фатальную ошибку: WordPress определяет
         * WP_POST_REVISIONS и создаёт $wp_rewrite уже после загрузки
         * плагинов. Лучше остаться без страницы, чем без сайта.
         */
        if (!did_action('init')) {
            return;
        }
        $def = GC_Courses::get($course);
        $option = (string) $def['option'];
        $slug = (string) $def['slug'];
        $content = '[genius_course_cabinet course="' . esc_attr($def['key']) . '"]';

        $page_id = (int) get_option($option);
        if ($page_id > 0 && get_post($page_id)) {
            return;
        }
        $existing = get_page_by_path($slug);
        if ($existing) {
            $page_id = (int) $existing->ID;
            wp_update_post(array('ID' => $page_id, 'post_content' => $content, 'post_status' => 'publish'));
        } else {
            $page_id = (int) wp_insert_post(array(
                'post_title'   => (string) $def['page'],
                'post_name'    => $slug,
                'post_content' => $content,
                'post_status'  => 'publish',
                'post_type'    => 'page',
            ));
        }
        if ($page_id > 0) {
            update_option($option, $page_id, false);
        }
    }

    public static function page_id($course = '') {
        $def = GC_Courses::get($course);
        return (int) get_option((string) $def['option']);
    }

    public static function url($course = '') {
        $def = GC_Courses::get($course);
        $page_id = (int) get_option((string) $def['option']);
        $url = $page_id > 0 ? get_permalink($page_id) : '';
        return $url ? $url : home_url('/' . (string) $def['slug'] . '/');
    }

    /**
     * Какой кабинет открыт.
     *
     * Пустая строка — это не страница кабинета вовсе. Нужна именно
     * страница, а не курс посетителя: по ней решают и программу, и
     * правило доступа, и администратор видит то, что видит ученик.
     */
    public static function course_of_page() {
        if (is_admin()) {
            return '';
        }
        foreach (GC_Courses::all() as $key => $def) {
            $page_id = (int) get_option((string) $def['option']);
            if ($page_id > 0 && is_page($page_id)) {
                return (string) $key;
            }
        }
        return '';
    }

    public static function is_page($course = '') {
        if (is_admin()) {
            return false;
        }
        if ($course === '') {
            return self::course_of_page() !== '';
        }
        $page_id = self::page_id($course);
        return $page_id > 0 && is_page($page_id);
    }

    public static function body_class($classes) {
        if (self::is_page()) {
            $classes[] = 'gc-page';
        }
        return $classes;
    }

    public static function noindex() {
        if (self::is_page()) {
            echo '<meta name="robots" content="noindex, nofollow">' . "\n";
        }
    }

    public static function assets() {
        if (!self::is_page()) {
            return;
        }
        wp_enqueue_style('genius-course', GC_URL . 'assets/css/cabinet.css', array(), GC_VERSION);
        wp_enqueue_script('genius-course', GC_URL . 'assets/js/cabinet.js', array(), GC_VERSION, true);
        wp_localize_script('genius-course', 'GC', array(
            'rest'   => esc_url_raw(rest_url('genius-course/v1/')),
            'nonce'  => wp_create_nonce('wp_rest'),
            // Курс страницы: по нему маршруты сверяют номер модуля, когда
            // кабинет открыл администратор, у которого своего курса нет.
            'course' => self::course_of_page(),
        ));
    }

    /* ---------------------------------------------------------------------
     * Разметка
     * ------------------------------------------------------------------ */

    public static function render($atts = array()) {
        $atts = shortcode_atts(array('course' => ''), is_array($atts) ? $atts : array(), 'genius_course_cabinet');
        $course = GC_Courses::exists($atts['course']) ? (string) $atts['course'] : GC_Courses::DEFAULT_KEY;

        if (!is_user_logged_in()) {
            return self::login_form($course);
        }
        if (!GC_Students::is_student()) {
            return '<div class="gc"><div class="gc-note">Этот кабинет для учеников курса. Если вы оплатили обучение,'
                 . ' а доступа нет — напишите нам, выдадим.</div></div>';
        }

        $user = wp_get_current_user();

        /*
         * Ученик другого курса на этой странице ничего не найдёт: у
         * курсов разные программы, и его работы привязаны к номерам
         * модулей своей. Отправляем к своему кабинету, а не показываем
         * чужую программу.
         */
        if (!current_user_can('manage_options') && GC_Students::course($user->ID) !== $course) {
            $own = GC_Students::course($user->ID);
            return '<div class="gc"><div class="gc-note">Вы учитесь на другом курсе: «'
                 . esc_html(GC_Courses::title($own)) . '». <a href="' . esc_url(self::url($own))
                 . '">Открыть свой кабинет</a>.</div></div>';
        }

        $modules = GC_Program::modules($course);
        $done = GC_Students::progress($user->ID);
        $paid = GC_Courses::is_paid($user->ID, $course);
        /*
         * Имя «m» здесь занято: WordPress разбирает его как архив по
         * дате, и адрес вида ?m=3 отдавал 404 вместо модуля. Своим
         * параметрам нужны имена, которых нет в списке системных.
         */
        $open = isset($_GET['mod']) ? max(0, min(count($modules) - 1, (int) $_GET['mod'])) : -1;
        $tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'program';

        // Отмечаем заход: в админке по нему видно, учится человек или
        // пропал после первого дня.
        GC_Students::touch($user->ID, $open);

        ob_start();
        ?>
        <div class="gc">
            <?php
            echo self::header($user, $done, $modules, $course); // phpcs:ignore WordPress.Security.EscapeOutput

            if (!empty($_GET['paid'])) {
                echo '<div class="gc-note gc-note--ok">Платёж принят. Доступ открывается автоматически, обычно за'
                   . ' минуту: если модули ещё закрыты, обновите страницу.</div>';
            }

            // Уведомление об оплате висит на каждой вкладке, пока курс не
            // оплачен: человек должен видеть, что именно закрыто и почему,
            // а не искать это по модулям.
            echo self::paywall($user, $course, $paid); // phpcs:ignore WordPress.Security.EscapeOutput
            ?>

            <nav class="gc-tabs">
                <a class="<?php echo $tab === 'program' && $open < 0 ? 'is-on' : ''; ?>" href="<?php echo esc_url(self::url($course)); ?>">Программа</a>
                <a class="<?php echo $tab === 'studio' ? 'is-on' : ''; ?>" href="<?php echo esc_url(add_query_arg('tab', 'studio', self::url($course))); ?>">Мастерская</a>
                <a class="<?php echo $tab === 'works' ? 'is-on' : ''; ?>" href="<?php echo esc_url(add_query_arg('tab', 'works', self::url($course))); ?>">Мои работы</a>
                <a class="<?php echo $tab === 'gallery' ? 'is-on' : ''; ?>" href="<?php echo esc_url(add_query_arg('tab', 'gallery', self::url($course))); ?>">Галерея</a>
                <?php if (GC_Courses::field($course, 'launch', false)) : ?>
                    <a class="<?php echo $tab === 'launch' ? 'is-on' : ''; ?>" href="<?php echo esc_url(add_query_arg('tab', 'launch', self::url($course))); ?>">Свой запуск</a>
                <?php endif; ?>
            </nav>

            <?php
            if ($tab === 'studio') {
                echo self::studio($user, $course, $paid); // phpcs:ignore WordPress.Security.EscapeOutput
            } elseif ($tab === 'works') {
                echo self::works($user, $course, $paid); // phpcs:ignore WordPress.Security.EscapeOutput
            } elseif ($tab === 'gallery') {
                echo self::gallery($course); // phpcs:ignore WordPress.Security.EscapeOutput
            } elseif ($tab === 'launch' && GC_Courses::field($course, 'launch', false)) {
                echo self::launch($course); // phpcs:ignore WordPress.Security.EscapeOutput
            } elseif ($open >= 0) {
                echo self::module($user, $open, $modules[$open], $done, $course, $paid); // phpcs:ignore WordPress.Security.EscapeOutput
            } else {
                echo self::program($user, $modules, $done, $course, $paid); // phpcs:ignore WordPress.Security.EscapeOutput
            }

            // Чат курса — там, где человек учится: на списке модулей и
            // внутри урока. В мастерской и в своих работах он только
            // закрывал бы кнопки.
            if ($tab === 'program') {
                echo self::chat_bar($course, $open); // phpcs:ignore WordPress.Security.EscapeOutput
            }

            // Кнопки «написать» висят на всех вкладках: вопрос появляется
            // там, где человек застрял, а не там, где мы его ждём.
            echo self::ask_widget($user, $course); // phpcs:ignore WordPress.Security.EscapeOutput
            ?>
        </div>
        <?php
        return ob_get_clean();
    }

    private static function login_form($course = '') {
        ob_start();
        ?>
        <div class="gc gc--narrow">
            <div class="gc-card">
                <h2 class="gc-h2">Вход в кабинет</h2>
                <p class="gc-lead">Логин и пароль мы присылаем после оплаты. Если письмо потерялось — напишите нам,
                    выдадим новый пароль.</p>
                <?php
                wp_login_form(array(
                    'redirect'       => self::url($course),
                    'label_username' => 'Логин или почта',
                    'label_password' => 'Пароль',
                    'label_log_in'   => 'Войти',
                    'remember'       => true,
                ));
                ?>
                <p class="gc-fine"><a href="<?php echo esc_url(wp_lostpassword_url(self::url($course))); ?>">Забыли пароль?</a></p>
                <?php $landing = GC_Courses::landing_url($course); ?>
                <?php if ($landing !== '') : ?>
                    <p class="gc-fine">Ещё не учитесь? <a href="<?php echo esc_url($landing); ?>">Посмотреть программу курса</a>.</p>
                <?php endif; ?>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    private static function header($user, $done, $modules, $course) {
        $unlimited = GC_Gpu::engine($user->ID, $course) !== 'kie';
        $total = count($modules);
        $passed = count(array_intersect($done, range(0, max(0, $total - 1))));
        $percent = $total > 0 ? round($passed / $total * 100) : 0;
        $until = GC_Students::gpu_until($user->ID);
        ob_start();
        ?>
        <header class="gc-head">
            <div>
                <p class="gc-hello">Здравствуйте, <?php echo esc_html($user->display_name); ?></p>
                <h1 class="gc-h1"><?php echo esc_html(GC_Courses::title($course)); ?></h1>
            </div>
            <div class="gc-head-side">
                <div class="gc-progress" role="img" aria-label="Пройдено <?php echo (int) $passed; ?> из <?php echo (int) $total; ?>">
                    <div class="gc-progress__bar"><span style="width: <?php echo (int) $percent; ?>%"></span></div>
                    <p><?php echo (int) $passed; ?> из <?php echo (int) $total; ?> модулей</p>
                </div>
                <?php if ($until !== '' && $unlimited) : ?>
                    <p class="gc-gpu <?php echo GC_Students::gpu_active($user->ID) ? 'is-on' : 'is-off'; ?>">
                        <?php if (GC_Students::gpu_active($user->ID)) : ?>
                            <?php echo $unlimited ? 'Генерации без лимита до ' : 'Генерации доступны до '; ?>
                            <?php echo esc_html(date_i18n('j F', strtotime($until))); ?>
                        <?php else : ?>
                            <?php echo $unlimited ? 'Месяц генераций закончился' : 'Срок генераций закончился'; ?>
                        <?php endif; ?>
                    </p>
                <?php endif; ?>
                <a class="gc-exit" href="<?php echo esc_url(wp_logout_url(home_url('/'))); ?>">Выйти</a>
            </div>
        </header>
        <?php
        return ob_get_clean();
    }

    /**
     * Блок оплаты.
     *
     * Показывается на каждой вкладке, пока курс не оплачен: так человек
     * всегда видит, что открыто сейчас и что откроется после оплаты.
     * Платёж уходит в ЮMoney с меткой этого ученика — доступ открывается
     * сам, без письма в поддержку.
     */
    private static function paywall($user, $course, $paid) {
        if ($paid || !GC_Courses::paywall($course)) {
            return '';
        }
        $plans = GC_Courses::plans($course);
        $free = GC_Courses::free_modules($course);
        ob_start();
        ?>
        <section class="gc-pay" id="oplata">
            <div class="gc-pay__head">
                <h2 class="gc-h2">Открыт <?php echo $free === 1 ? 'первый модуль' : 'вводный блок'; ?>. Остальное — после оплаты</h2>
                <p class="gc-lead">Сейчас доступн<?php echo $free === 1 ? 'а программа первого модуля' : 'ы вводные модули'; ?>:
                    посмотрите, как устроено обучение. После оплаты сразу открываются все модули,
                    сдача работ на проверку и мастерская генераций.</p>
            </div>
            <div class="gc-pay__plans">
                <?php foreach ($plans as $key => $plan) :
                    $link = GC_Pay::link($user->ID, $course, $key);
                    ?>
                    <article class="gc-pay__plan<?php echo !empty($plan['flag']) ? ' is-best' : ''; ?>">
                        <?php if (!empty($plan['flag'])) : ?>
                            <span class="gc-pay__flag"><?php echo esc_html($plan['flag']); ?></span>
                        <?php endif; ?>
                        <h3><?php echo esc_html($plan['title']); ?></h3>
                        <p class="gc-pay__price"><?php echo esc_html(number_format_i18n((float) $plan['price'])); ?> <span>₽</span></p>
                        <p class="gc-pay__note"><?php echo esc_html($plan['note']); ?></p>
                        <ul class="gc-pay__list">
                            <?php foreach ((array) $plan['list'] as $line) : ?>
                                <li><?php echo esc_html($line); ?></li>
                            <?php endforeach; ?>
                        </ul>
                        <?php
                        // Пакет генераций: самая непонятная часть тарифа, если
                        // не сказать её числами и не перевести в работы.
                        $pack = class_exists('GC_Pack') ? GC_Pack::plan_pack($key) : null;
                        if ($pack) : ?>
                            <div class="gc-pay__pack">
                                <p class="gc-pay__pack-head">Пакет генераций на <?php echo (int) $pack['days']; ?> дней</p>
                                <p class="gc-pay__pack-nums">
                                    <b><?php echo (int) $pack['images']; ?></b> кадров
                                    <i>+</i>
                                    <b><?php echo (int) $pack['videos']; ?></b> <?php
                                        echo esc_html(self::rollers((int) $pack['videos'])); ?>
                                </p>
                                <p class="gc-fine"><?php echo esc_html(GC_Pack::video_legend()); ?></p>
                                <ul>
                                    <?php foreach (GC_Pack::equivalents($key) as $row) : ?>
                                        <li><strong><?php echo esc_html($row[0]); ?></strong> <?php echo esc_html($row[1]); ?>
                                            <span><?php echo esc_html($row[2]); ?></span></li>
                                    <?php endforeach; ?>
                                </ul>
                                <p class="gc-fine">Считаются готовые работы: неудачные попытки пакет не тратят.</p>
                            </div>
                        <?php endif; ?>
                        <?php if ($link !== '') : ?>
                            <a class="gc-btn<?php echo !empty($plan['flag']) ? '' : ' gc-btn--ghost'; ?>"
                               href="<?php echo esc_url($link); ?>" rel="nofollow noopener">Оплатить
                                <?php echo esc_html(number_format_i18n((float) $plan['price'])); ?> ₽</a>
                        <?php else : ?>
                            <p class="gc-fine">Оплата картой сейчас недоступна — напишите нам, выставим счёт.</p>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>
            <p class="gc-fine">Оплата проходит через ЮMoney, картой любого банка. Доступ открывается автоматически
                после подтверждения платежа — обычно это минута. Чек приходит на почту от платёжной системы.</p>
        </section>
        <?php
        return ob_get_clean();
    }

    /** Короткое напоминание на вкладках, которые закрыты до оплаты. */
    private static function locked_note($course, $what) {
        return '<div class="gc-note gc-note--lock">' . esc_html($what)
             . ' открывается после оплаты обучения. <a href="#oplata">Посмотреть тарифы</a>.</div>';
    }

    private static function program($user, $modules, $done, $course, $paid) {
        ob_start();
        ?>
        <ol class="gc-modules">
            <?php foreach ($modules as $i => $m) :
                $open = $paid || GC_Courses::module_open($user->ID, $i, $course);
                $work = $open ? GC_Works::last_for_module($user->ID, $i) : null;
                $state = !$open ? 'lock' : (in_array($i, $done, true) ? 'done' : ($work ? 'sent' : 'open'));
                ?>
                <li class="gc-module is-<?php echo esc_attr($state); ?>">
                    <a href="<?php echo esc_url(add_query_arg('mod', $i, self::url($course))); ?>">
                        <span class="gc-module__n"><?php echo (int) ($i + 1); ?></span>
                        <span class="gc-module__body">
                            <strong><?php echo esc_html($m['title']); ?></strong>
                            <span><?php echo esc_html($m['lead']); ?></span>
                        </span>
                        <span class="gc-module__state">
                            <?php
                            if (!$open) {
                                echo 'после оплаты';
                            } elseif ($state === 'done') {
                                echo 'пройден';
                            } elseif ($work) {
                                echo esc_html($work['status'] === 'accepted' ? 'принято' : ($work['status'] === 'rework' ? 'на доработке' : 'на проверке'));
                            } else {
                                echo 'открыть';
                            }
                            ?>
                        </span>
                    </a>
                </li>
            <?php endforeach; ?>
        </ol>
        <?php
        return ob_get_clean();
    }

    private static function module($user, $index, $m, $done, $course, $paid) {
        $open = $paid || GC_Courses::module_open($user->ID, $index, $course);
        $work = GC_Works::last_for_module($user->ID, $index);
        ob_start();
        ?>
        <article class="gc-card gc-lesson<?php echo $open ? '' : ' is-locked'; ?>">
            <p class="gc-back"><a href="<?php echo esc_url(self::url($course)); ?>">← Все модули</a></p>
            <p class="gc-kicker">Модуль <?php echo (int) ($index + 1); ?> из <?php echo (int) GC_Program::count($course); ?></p>
            <h2 class="gc-h2"><?php echo esc_html($m['title']); ?></h2>
            <p class="gc-lead"><?php echo esc_html($m['lead']); ?></p>
            <p class="gc-goal"><strong>Чему научитесь:</strong> <?php echo esc_html($m['goal']); ?></p>

            <?php if (!$open) : ?>
                <?php
                // Закрытый модуль показываем оглавлением: что внутри —
                // видно, сами уроки и задание открываются после оплаты.
                ?>
                <ul class="gc-locked-list">
                    <?php foreach ($m['lessons'] as $lesson) : ?>
                        <li><?php echo esc_html($lesson[0]); ?></li>
                    <?php endforeach; ?>
                    <li>Задание с разбором</li>
                </ul>
                <?php echo self::examples_teaser($course, $index); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                <?php echo self::locked_note($course, 'Этот модуль'); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                <nav class="gc-nav">
                    <a class="gc-nav__prev" href="<?php echo esc_url(self::url($course)); ?>">← Все модули</a>
                    <a class="gc-nav__next" href="<?php echo esc_url(self::url($course)); ?>#oplata">Открыть весь курс →</a>
                </nav>
            </article>
            <?php
            return ob_get_clean();
        endif;
        ?>

            <?php foreach ($m['lessons'] as $n => $lesson) : ?>
                <section class="gc-step">
                    <h3><span><?php echo (int) ($n + 1); ?></span><?php echo esc_html($lesson[0]); ?></h3>
                    <p><?php echo esc_html($lesson[1]); ?></p>
                </section>
            <?php endforeach; ?>

            <?php echo self::examples($course, $index); // phpcs:ignore WordPress.Security.EscapeOutput ?>

            <div class="gc-task">
                <h3>Задание</h3>
                <p><?php echo esc_html($m['task']); ?></p>
                <p class="gc-task__deliver"><strong>Что сдать:</strong> <?php echo esc_html($m['deliver']); ?></p>
                <p class="gc-fine"><?php echo esc_html($m['hint']); ?></p>
            </div>

            <?php echo self::quiz($user, $course, $index); // phpcs:ignore WordPress.Security.EscapeOutput ?>

            <?php if ($work) : ?>
                <div class="gc-sent is-<?php echo esc_attr($work['status']); ?>">
                    <p><strong>Работа сдана <?php echo esc_html(date_i18n('j F, H:i', strtotime($work['created_at']))); ?>.</strong>
                        Статус: <?php echo esc_html($work['status'] === 'accepted' ? 'принята' : ($work['status'] === 'rework' ? 'на доработку' : 'ждёт проверки')); ?>.</p>
                    <?php if (!empty($work['auto_feedback'])) : ?>
                        <div class="gc-feedback gc-feedback--auto">
                            <strong>Быстрый разбор</strong>
                            <span class="gc-fine">Его пишет модель сразу после сдачи — по чек-листу модуля.
                                Разбор проверяющего придёт следом.</span>
                            <?php echo wp_kses_post(class_exists('GS_Md')
                                ? GS_Md::to_html((string) $work['auto_feedback'])
                                : wpautop((string) $work['auto_feedback'])); ?>
                        </div>
                    <?php elseif ($work['status'] === 'sent' && GC_Review::available()) : ?>
                        <p class="gc-fine">Быстрый разбор считается — обновите страницу через минуту.</p>
                    <?php endif; ?>
                    <?php if (!empty($work['feedback'])) : ?>
                        <div class="gc-feedback"><strong>Разбор проверяющего:</strong> <?php echo wp_kses_post(wpautop($work['feedback'])); ?></div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if (GC_Courses::tools_open($user->ID, $course)) : ?>
                <form class="gc-form" id="gc-work-form" data-module="<?php echo (int) $index; ?>">
                    <h3><?php echo $work ? 'Сдать ещё раз' : 'Сдать работу'; ?></h3>
                    <label>
                        <span>Ссылки на файлы</span>
                        <textarea name="links" rows="2" placeholder="Ссылка на облако, по одной в строке"></textarea>
                    </label>
                    <label>
                        <span>Что получилось и где застряли</span>
                        <textarea name="body" rows="4" placeholder="Пара абзацев: что задумали, что вышло не так, что уже пробовали"></textarea>
                    </label>
                    <label class="gc-check">
                        <input type="checkbox" name="shared" value="1">
                        <span>Можно показать мою работу другим ученикам в галерее — после того, как её примут</span>
                    </label>
                    <button type="submit" class="gc-btn">Отправить на проверку</button>
                    <p class="gc-note-line" role="status"></p>
                </form>
            <?php else : ?>
                <?php echo self::locked_note($course, 'Сдача работ на проверку'); // phpcs:ignore WordPress.Security.EscapeOutput ?>
            <?php endif; ?>

            <form class="gc-done" id="gc-done-form" data-module="<?php echo (int) $index; ?>">
                <label><input type="checkbox" <?php checked(in_array($index, $done, true)); ?>> Отметить модуль пройденным</label>
            </form>

            <nav class="gc-nav">
                <?php if ($index > 0) : ?>
                    <a class="gc-nav__prev" href="<?php echo esc_url(add_query_arg('mod', $index - 1, self::url($course))); ?>">
                        ← Модуль <?php echo (int) $index; ?></a>
                <?php else : ?><span></span><?php endif; ?>
                <?php if ($index + 1 < GC_Program::count($course)) : ?>
                    <a class="gc-nav__next" href="<?php echo esc_url(add_query_arg('mod', $index + 1, self::url($course))); ?>">
                        Модуль <?php echo (int) ($index + 2); ?> →</a>
                <?php else : ?>
                    <a class="gc-nav__next" href="<?php echo esc_url(self::url($course)); ?>">К списку модулей</a>
                <?php endif; ?>
            </nav>
        </article>
        <?php
        return ob_get_clean();
    }

    /**
     * Примеры к модулю.
     *
     * Кадры и ролики лежат в медиатеке, тексты — в настройках: ученику
     * они приходят готовыми, без единого обращения к моделям. Пока
     * пример не сделан, блока просто нет — пустых рамок с надписью
     * «скоро» в кабинете быть не должно.
     */
    private static function examples($course, $index) {
        if (!class_exists('GC_Examples')) {
            return '';
        }
        $items = GC_Examples::get($course, $index);
        if (!$items) {
            return '';
        }
        ob_start();
        ?>
        <section class="gc-examples">
            <h3>Примеры по теме</h3>
            <p class="gc-fine">Всё, что ниже, сделано теми же моделями, что стоят у вас в мастерской — повторить
                можно сразу после урока.</p>
            <?php foreach ($items as $item) :
                $kind = (string) ($item['kind'] ?? 'image');
                ?>
                <article class="gc-example is-<?php echo esc_attr($kind); ?>">
                    <h4><?php echo esc_html((string) $item['title']); ?></h4>
                    <?php if ($kind === 'image' && !empty($item['url'])) : ?>
                        <a class="gc-example__shot" href="<?php echo esc_url($item['url']); ?>" target="_blank" rel="noopener">
                            <img src="<?php echo esc_url($item['url']); ?>" alt="<?php echo esc_attr((string) $item['title']); ?>"
                                 loading="lazy" decoding="async">
                        </a>
                    <?php elseif ($kind === 'video' && !empty($item['url'])) : ?>
                        <video class="gc-example__clip" src="<?php echo esc_url($item['url']); ?>"
                               controls loop muted playsinline preload="metadata"></video>
                    <?php elseif ($kind === 'text' && !empty($item['text'])) : ?>
                        <div class="gc-example__text">
                            <?php
                            $text = (string) $item['text'];
                            echo wp_kses_post(class_exists('GS_Md') ? GS_Md::to_html($text) : wpautop($text));
                            ?>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($item['note'])) : ?>
                        <p class="gc-example__note"><?php echo esc_html((string) $item['note']); ?></p>
                    <?php endif; ?>
                    <?php
                    /*
                     * Кнопка повторяет пример в мастерской: описание уже
                     * вписано, человеку остаётся нажать «сгенерировать».
                     * Копировать текст руками никто не станет.
                     */
                    if (in_array($kind, array('image', 'video'), true) && !empty($item['prompt'])
                        && GC_Courses::tools_open(get_current_user_id(), $course)) :
                        $link = add_query_arg(array(
                            'tab'    => 'studio',
                            'kind'   => $kind,
                            'size'   => (string) ($item['size'] ?? '1024x1024'),
                            'prompt' => rawurlencode((string) $item['prompt']),
                        ), self::url($course));
                        ?>
                        <p class="gc-example__do">
                            <a class="gc-btn gc-btn--ghost" href="<?php echo esc_url($link); ?>">Сделать это задание</a>
                            <span class="gc-fine">Откроется мастерская с этим описанием</span>
                        </p>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        </section>
        <?php
        return ob_get_clean();
    }

    /**
     * Мини-проверка модуля.
     *
     * Три вопроса с объяснением ответа. Правильные варианты в разметку
     * не попадают: их знает только сервер, иначе ответ виден в коде
     * страницы и проверка превращается в игру «найди подсказку».
     */
    private static function quiz($user, $course, $index) {
        if (!class_exists('GC_Quiz')) {
            return '';
        }
        $questions = GC_Quiz::questions($course, $index);
        if (!$questions) {
            return '';
        }
        $was = GC_Quiz::result($user->ID, $course, $index);
        ob_start();
        ?>
        <section class="gc-quiz" id="gc-quiz" data-module="<?php echo (int) $index; ?>">
            <h3>Проверьте себя</h3>
            <p class="gc-fine">Три вопроса по модулю. Ответ разбирается сразу — это не экзамен, а способ
                закрепить прочитанное.</p>
            <?php if ($was) : ?>
                <p class="gc-quiz__was">В прошлый раз: <strong><?php echo (int) $was['score']; ?> из
                    <?php echo (int) $was['total']; ?></strong>. Можно пройти ещё раз.</p>
            <?php endif; ?>
            <?php foreach ($questions as $q) : ?>
                <div class="gc-quiz__q" data-n="<?php echo (int) $q['n']; ?>">
                    <p class="gc-quiz__text"><?php echo (int) ($q['n'] + 1); ?>. <?php echo esc_html($q['text']); ?></p>
                    <?php foreach ($q['options'] as $i => $option) : ?>
                        <label class="gc-quiz__opt">
                            <input type="radio" name="q<?php echo (int) $q['n']; ?>" value="<?php echo (int) $i; ?>">
                            <span><?php echo esc_html($option); ?></span>
                        </label>
                    <?php endforeach; ?>
                    <p class="gc-quiz__why" hidden></p>
                </div>
            <?php endforeach; ?>
            <button type="button" class="gc-btn" id="gc-quiz-send">Проверить ответы</button>
            <p class="gc-note-line" role="status"></p>
        </section>
        <?php
        return ob_get_clean();
    }

    /**
     * Пакет генераций: что входит и сколько осталось.
     *
     * Полоса показывает не «сколько потратил», а «сколько осталось»:
     * человеку важно именно это, а не чувство вины за израсходованное.
     */
    private static function pack_card($user, $course) {
        if (!class_exists('GC_Pack')) {
            return '';
        }
        $pack = GC_Pack::for_user($user->ID, $course);
        if (!$pack) {
            return '';
        }
        $rows = array(
            array('кадры',  (int) $pack['left']['image'], (int) $pack['images']),
            array('ролики', (int) $pack['left']['video'], (int) $pack['videos']),
        );
        ob_start();
        ?>
        <section class="gc-pack">
            <div class="gc-pack__head">
                <strong>Пакет генераций: тариф «<?php echo esc_html($pack['title']); ?>»</strong>
                <?php if ((int) $pack['until'] > 0) : ?>
                    <span class="<?php echo !empty($pack['over']) ? 'is-off' : ''; ?>">
                        <?php echo !empty($pack['over'])
                            ? 'срок закончился ' . esc_html(date_i18n('j F', (int) $pack['until']))
                            : 'действует до ' . esc_html(date_i18n('j F', (int) $pack['until'])); ?>
                    </span>
                <?php endif; ?>
            </div>
            <div class="gc-pack__bars">
                <?php foreach ($rows as $row) :
                    list($name, $left, $total) = $row;
                    $percent = $total > 0 ? max(0, min(100, round($left / $total * 100))) : ($left > 0 ? 100 : 0);
                    ?>
                    <div class="gc-pack__bar<?php echo $left <= 0 ? ' is-empty' : ''; ?>">
                        <p><span><?php echo esc_html($name); ?></span>
                            осталось <strong><?php echo (int) $left; ?></strong><?php
                            /*
                             * «Из скольких» показываем, только пока остаток
                             * меньше нынешнего пакета. У тех, кому начислили
                             * по старым размерам, на счету больше — и
                             * «596 из 200» читается как ошибка, хотя
                             * начислено честно.
                             */
                            echo $left <= $total ? ' из ' . (int) $total : ''; ?></p>
                        <div class="gc-pack__line"><span style="width: <?php echo (int) $percent; ?>%"></span></div>
                    </div>
                <?php endforeach; ?>
            </div>
            <p class="gc-fine">Неудачные попытки пакет не тратят: задание, которое не вышло, возвращается на счёт.</p>
            <?php echo self::pack_shop($user, $course, $pack); // phpcs:ignore WordPress.Security.EscapeOutput ?>
        </section>
        <?php
        return ob_get_clean();
    }

    /**
     * Докупка генераций.
     *
     * Витрина спрятана, пока остаток приличный: предлагать докупить
     * тому, у кого пятьсот кадров на счету, — навязывание. Когда
     * остаётся мало или срок вышел, она раскрыта сразу.
     */
    private static function pack_shop($user, $course, $pack) {
        if (!class_exists('GC_Pay') || !class_exists('GC_Pack')) {
            return '';
        }
        $extras = GC_Pack::extras();
        if (!$extras) {
            return '';
        }
        $low = !empty($pack['over'])
            || (int) $pack['left']['image'] <= 30
            || (int) $pack['left']['video'] <= 2;

        ob_start();
        ?>
        <details class="gc-shop"<?php echo $low ? ' open' : ''; ?>>
            <summary><?php echo $low ? 'Пакет заканчивается — докупить генерации' : 'Докупить генерации'; ?></summary>
            <?php if (!empty($_GET['bought'])) : ?>
                <p class="gc-note gc-note--ok">Платёж принят. Пакет начисляется автоматически, обычно за минуту:
                    если остаток ещё прежний, обновите страницу.</p>
            <?php endif; ?>
            <p class="gc-fine">Пакет добавляется к текущему остатку и продлевает срок. Оплата — той же картой,
                через ЮMoney.</p>
            <div class="gc-shop__grid">
                <?php foreach ($extras as $key => $extra) :
                    $link = GC_Pay::pack_link($user->ID, $key);
                    ?>
                    <article class="gc-shop__card">
                        <h4><?php echo esc_html($extra['title']); ?></h4>
                        <p class="gc-shop__price"><?php echo esc_html(number_format_i18n((float) $extra['price'])); ?> ₽</p>
                        <p class="gc-shop__nums"><b><?php echo (int) $extra['images']; ?></b> кадров
                            <i>+</i> <b><?php echo (int) $extra['videos']; ?></b> <?php
                            echo esc_html(self::rollers((int) $extra['videos'])); ?></p>
                        <p class="gc-shop__note"><?php echo esc_html($extra['note']); ?></p>
                        <p class="gc-fine">Срок: <?php echo (int) $extra['days']; ?> дней</p>
                        <?php if ($link !== '') : ?>
                            <a class="gc-btn gc-btn--ghost" href="<?php echo esc_url($link); ?>" rel="nofollow noopener">Купить</a>
                        <?php else : ?>
                            <p class="gc-fine">Оплата картой сейчас недоступна — напишите нам.</p>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>
        </details>
        <?php
        return ob_get_clean();
    }

    /**
     * Схема запуска: откуда трафик и куда он приходит.
     *
     * Рисуем вёрсткой, а не картинкой: подписи остаются читаемыми на
     * телефоне, схема сама переносится в столбец, и её не нужно
     * перегенерировать при каждой правке текста.
     */
    private static function flow($case, $full = false) {
        if (empty($case['flow'])) {
            return '';
        }
        $icons = array(
            // Рупор — реклама, страница — сайт, облачко — заявка,
            // галочка — сама работа, стрелка по кругу — повторные заказы.
            'ads'    => '<path d="M4 9v4h3l5 3V6L7 9H4z"/><path d="M15 8a4 4 0 010 6" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/>',
            'page'   => '<rect x="4" y="3.5" width="13" height="15" rx="2.5" fill="none" stroke="currentColor" stroke-width="1.6"/><path d="M7.5 8h6M7.5 11.5h6M7.5 15h3.5" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/>',
            'chat'   => '<path d="M4 5.5A2.5 2.5 0 016.5 3h9A2.5 2.5 0 0118 5.5v6A2.5 2.5 0 0115.5 14H9l-4 4v-4H6.5A2.5 2.5 0 014 11.5v-6z" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/>',
            'work'   => '<path d="M4 11l4.5 4.5L18 6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>',
            'repeat' => '<path d="M5 9a6 6 0 0110-4m1.5 6a6 6 0 01-10 4" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/><path d="M15 3.5V7h-3.5M6 18v-3.5h3.5" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>',
        );
        ob_start();
        ?>
        <div class="gc-flow<?php echo $full ? ' gc-flow--full' : ''; ?>">
            <?php foreach ((array) $case['flow'] as $i => $step) :
                list($icon, $title, $note) = array_pad((array) $step, 3, '');
                ?>
                <div class="gc-flow__step<?php echo $i === 0 ? ' is-first' : ''; ?>">
                    <span class="gc-flow__icon" aria-hidden="true">
                        <svg viewBox="0 0 22 22" width="22" height="22" fill="currentColor"><?php
                            echo isset($icons[$icon]) ? $icons[$icon] : $icons['work']; // phpcs:ignore WordPress.Security.EscapeOutput
                        ?></svg>
                    </span>
                    <strong><?php echo esc_html($title); ?></strong>
                    <?php if ($full && $note !== '') : ?>
                        <span><?php echo esc_html($note); ?></span>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Направления запуска.
     *
     * Ремесло само по себе денег не приносит: нужно решить, что
     * запускать и кому это продавать. Шесть разобранных направлений с
     * честной вилкой дохода, шагами запуска и местами, где брать людей.
     * Открыто всем, включая не оплативших: это ответ на вопрос «а что
     * я с этого получу», а не сама программа.
     */
    private static function launch($course) {
        if (!class_exists('GC_Launch')) {
            return '';
        }
        $cases = GC_Launch::cases();
        $key = isset($_GET['case']) ? sanitize_key(wp_unslash($_GET['case'])) : '';
        $one = $key !== '' ? GC_Launch::get($key) : null;

        ob_start();
        if (!$one) :
            ?>
            <section class="gc-card">
                <h2 class="gc-h2">С чего начать свой запуск</h2>
                <p class="gc-lead">Шесть направлений, которые получается открыть в одиночку с тем, чему учит курс.
                    Выберите одно и доведите до первых денег — распыляться на три сразу не выходит ни у кого.</p>
                <p class="gc-fine">Суммы — не обещание. Это вилка, в которую попадают те, кто делает работу
                    регулярно; рядом написано, при каком объёме она верна. Первые деньги обычно приходят на
                    третьей-четвёртой неделе.</p>
            </section>
            <div class="gc-launch">
                <?php foreach ($cases as $k => $case) : ?>
                    <article class="gc-launch__card">
                        <h3><?php echo esc_html($case['title']); ?></h3>
                        <p class="gc-launch__income"><?php echo esc_html($case['income']); ?></p>
                        <p class="gc-launch__lead"><?php echo esc_html($case['lead']); ?></p>
                        <p class="gc-launch__basis"><?php echo esc_html($case['basis']); ?></p>
                        <?php echo self::flow($case); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                        <a class="gc-btn gc-btn--ghost" href="<?php echo esc_url(add_query_arg(
                            array('tab' => 'launch', 'case' => $k), self::url($course))); ?>">Как запустить</a>
                    </article>
                <?php endforeach; ?>
            </div>
            <?php
        else :
            $modules = GC_Program::modules($course);
            ?>
            <article class="gc-card gc-lesson">
                <p class="gc-back"><a href="<?php echo esc_url(add_query_arg('tab', 'launch', self::url($course))); ?>">← Все направления</a></p>
                <h2 class="gc-h2"><?php echo esc_html($one['title']); ?></h2>
                <p class="gc-lead"><?php echo esc_html($one['lead']); ?></p>

                <div class="gc-launch__facts">
                    <p><strong>Сколько приносит:</strong> <?php echo esc_html($one['income']); ?><br>
                        <span class="gc-fine"><?php echo esc_html($one['basis']); ?></span></p>
                    <p><strong>Что нужно на старте:</strong> <?php echo esc_html($one['start']); ?></p>
                    <p><strong>Что здесь трудного:</strong> <?php echo esc_html($one['hard']); ?></p>
                </div>

                <section class="gc-step">
                    <h3><span>1</span>Как это работает целиком</h3>
                    <p>Деньги приносит не сама нейросеть, а связка: реклама приводит людей на страницу,
                        страница превращает их в заявки, работа превращает заявки в деньги, а довольный
                        заказчик возвращается.</p>
                    <?php echo self::flow($one, true); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                </section>

                <section class="gc-step">
                    <h3><span>2</span>Что вы делаете</h3>
                    <ul class="gc-launch__list">
                        <?php foreach ((array) $one['what'] as $line) : ?>
                            <li><?php echo esc_html($line); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </section>

                <section class="gc-step">
                    <h3><span>3</span>Как запустить</h3>
                    <ol class="gc-launch__steps">
                        <?php foreach ((array) $one['steps'] as $line) : ?>
                            <li><?php echo esc_html($line); ?></li>
                        <?php endforeach; ?>
                    </ol>
                </section>

                <section class="gc-step">
                    <h3><span>4</span>Где брать людей</h3>
                    <ul class="gc-launch__list">
                        <?php foreach ((array) $one['ads'] as $line) : ?>
                            <li><?php echo esc_html($line); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </section>

                <?php if (!empty($one['modules'])) : ?>
                    <div class="gc-task">
                        <h3>Какие модули нужны именно здесь</h3>
                        <ul class="gc-launch__list">
                            <?php foreach ((array) $one['modules'] as $i) :
                                if (!isset($modules[$i])) { continue; } ?>
                                <li><a href="<?php echo esc_url(add_query_arg('mod', (int) $i, self::url($course))); ?>">
                                    Модуль <?php echo (int) ($i + 1); ?>: <?php echo esc_html($modules[$i]['title']); ?></a></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <div class="gc-launch__plus">
                    <h3>Что берём на себя на тарифе «С рекламной кампанией»</h3>
                    <p><?php echo esc_html(GC_Launch::plus_note()); ?></p>
                    <?php if (!GC_Courses::is_paid(get_current_user_id(), $course)) : ?>
                        <p><a class="gc-btn" href="<?php echo esc_url(self::url($course)); ?>#oplata">Посмотреть тарифы</a></p>
                    <?php endif; ?>
                </div>
            </article>
            <?php
        endif;
        return ob_get_clean();
    }

    /**
     * Галерея работ учеников.
     *
     * Только то, что человек сам открыл: кадры из мастерской по кнопке
     * «показать» и принятые работы, к которым он поставил согласие.
     * Чужая хорошая работа объясняет планку лучше любого текста.
     */
    private static function gallery($course) {
        $jobs = class_exists('GC_Gpu') ? GC_Gpu::shared(24) : array();
        $works = GC_Works::gallery(20);
        ob_start();
        ?>
        <section class="gc-card">
            <h2 class="gc-h2">Галерея курса</h2>
            <p class="gc-lead">Работы других учеников — те, что они сами открыли. Свои кадры можно показать
                кнопкой «В галерею» в мастерской, а сданную работу — галочкой при отправке.</p>

            <?php if (!$jobs && !$works) : ?>
                <p class="gc-fine">Пока пусто. Будьте первым: сделайте кадр в мастерской и нажмите «В галерею».</p>
            <?php endif; ?>

            <?php if ($jobs) : ?>
                <ul class="gc-grid">
                    <?php foreach ($jobs as $job) :
                        $author = get_user_by('id', (int) $job['user_id']);
                        $still = (bool) preg_match('~\.(png|jpe?g|webp)([^a-z]|$)~i', (string) $job['result_url']);
                        ?>
                        <li class="gc-job is-done">
                            <?php if (!$still && preg_match('~\.(mp4|webm|mov)([^a-z]|$)~i', (string) $job['result_url'])) : ?>
                                <video src="<?php echo esc_url($job['result_url']); ?>" controls loop muted playsinline preload="metadata"></video>
                            <?php else : ?>
                                <a href="<?php echo esc_url($job['result_url']); ?>" target="_blank" rel="noopener">
                                    <img src="<?php echo esc_url($job['result_url']); ?>" alt="" loading="lazy" decoding="async"></a>
                            <?php endif; ?>
                            <span class="gc-job__prompt"><?php echo esc_html(mb_substr((string) $job['prompt'], 0, 80)); ?></span>
                            <span class="gc-fine"><?php echo esc_html($author ? $author->display_name : 'ученик'); ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <?php if ($works) : ?>
                <h3>Принятые работы</h3>
                <ul class="gc-works">
                    <?php foreach ($works as $w) :
                        $author = get_user_by('id', (int) $w['user_id']);
                        $m = GC_Program::module((int) $w['module'], GC_Students::course((int) $w['user_id']));
                        ?>
                        <li class="is-accepted">
                            <p class="gc-works__head">
                                <strong>Модуль <?php echo (int) ($w['module'] + 1); ?><?php echo $m ? ': ' . esc_html($m['title']) : ''; ?></strong>
                                <span><?php echo esc_html($author ? $author->display_name : 'ученик'); ?></span>
                            </p>
                            <?php if (!empty($w['links'])) : ?>
                                <p class="gc-fine"><?php echo esc_html($w['links']); ?></p>
                            <?php endif; ?>
                            <?php if (!empty($w['feedback'])) : ?>
                                <div class="gc-feedback"><strong>Разбор:</strong> <?php echo wp_kses_post(wpautop($w['feedback'])); ?></div>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>
        <?php
        return ob_get_clean();
    }

    /**
     * Липкая плашка со ссылкой на чат курса.
     *
     * Курс без куратора, и вопросы всё равно появляются: на списке
     * модулей и в каждом уроке внизу висит ссылка в чат, где спрашивают
     * и показывают свои работы. Адрес — настройка курса: тему переносят,
     * группу пересоздают, и выкатывать ради этого плагин незачем.
     */
    private static function chat_bar($course, $module = -1) {
        $url = GC_Courses::chat($course);
        if ($url === '') {
            return '';
        }
        $where = $module >= 0 ? 'по этому модулю' : 'по курсу';
        ob_start();
        ?>
        <aside class="gc-chat" role="complementary">
            <div class="gc-chat__text">
                <strong>Вопросы <?php echo esc_html($where); ?> — в чате курса</strong>
                <span>Там же выкладывают работы на обсуждение и разбирают чужие: в отдельных темах по
                    кадрам, роликам, звуку и заказам.</span>
            </div>
            <a class="gc-chat__btn" href="<?php echo esc_url($url); ?>" target="_blank" rel="noopener">
                Открыть чат</a>
        </aside>
        <?php
        return ob_get_clean();
    }

    /** Склонение «ролик» к числу: 1 ролик, 2 ролика, 5 роликов. */
    public static function rollers($n) {
        $n = abs((int) $n) % 100;
        $last = $n % 10;
        if ($n > 10 && $n < 20) {
            return 'роликов';
        }
        if ($last === 1) {
            return 'ролик';
        }
        if ($last >= 2 && $last <= 4) {
            return 'ролика';
        }
        return 'роликов';
    }

    /**
     * Плавающие кнопки «написать куратору» и «в поддержку».
     *
     * Курс самостоятельный, но вопросы делятся на два разных: «не
     * понял задание» и «не работает кабинет». Первый разбирает куратор,
     * второй — тот, кто правит сайт. Если кнопка одна, оба вопроса
     * приходят вперемешку и отвечает на них один человек дольше, чем
     * нужно.
     *
     * Сообщение уходит владельцу в Telegram тем же потоком, что заявки
     * с сайта: ответ он пишет привычным способом. Форму держим на
     * странице свёрнутой — раскрывается по кнопке, чтобы не закрывать
     * урок.
     */
    private static function ask_widget($user, $course) {
        if (!class_exists('GC_Contact')) {
            return '';
        }
        $tg = GC_Contact::telegram_of($user->ID);
        ob_start();
        ?>
        <div class="gc-ask" id="gc-ask">
            <div class="gc-ask__panel" id="gc-ask-panel" hidden>
                <form class="gc-ask__form" id="gc-ask-form">
                    <div class="gc-ask__head">
                        <strong id="gc-ask-title">Написать куратору</strong>
                        <button type="button" class="gc-ask__x" id="gc-ask-close" aria-label="Закрыть">×</button>
                    </div>
                    <p class="gc-ask__hint" id="gc-ask-hint">Разберём задание, подскажем промпт, посмотрим вашу
                        работу. Ответ придёт на почту или в Telegram.</p>
                    <input type="hidden" name="kind" id="gc-ask-kind" value="curator">
                    <label class="gc-field">
                        <span>Вопрос</span>
                        <textarea name="text" id="gc-ask-text" rows="4" maxlength="2000"
                                  placeholder="Например: в модуле 3 не получается удержать лицо на кадре — что поправить в промпте?"></textarea>
                    </label>
                    <label class="gc-field">
                        <span>Ник в Telegram — если удобнее ответить там</span>
                        <input type="text" name="telegram" id="gc-ask-tg" maxlength="40"
                               value="<?php echo esc_attr($tg); ?>" placeholder="@nickname">
                    </label>
                    <p class="gc-note-line" aria-live="polite"></p>
                    <button type="submit" class="gc-btn">Отправить</button>
                    <p class="gc-ask__fine">Ответ приходит на <?php echo esc_html($user->user_email); ?> или в
                        Telegram. Обычно в течение дня.</p>
                </form>
            </div>
            <div class="gc-ask__btns">
                <button type="button" class="gc-ask__btn gc-ask__btn--curator" data-kind="curator">
                    <span class="gc-ask__ico" aria-hidden="true">?</span>
                    <span class="gc-ask__long">Написать куратору</span>
                    <span class="gc-ask__short" aria-hidden="true">Куратору</span>
                </button>
                <button type="button" class="gc-ask__btn gc-ask__btn--support" data-kind="support">
                    <span class="gc-ask__ico" aria-hidden="true">!</span>
                    <span class="gc-ask__long">Написать в поддержку</span>
                    <span class="gc-ask__short" aria-hidden="true">Поддержка</span>
                </button>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Витрина примеров в закрытом модуле.
     *
     * Закрытый модуль показывал одно оглавление, и человек не понимал,
     * за что платит. Кадры и ролики показываем целиком — качество
     * должно быть видно до оплаты. Разборы и готовые тексты — только
     * названиями: это и есть работа, за которую берут деньги, а не
     * витрина.
     */
    private static function examples_teaser($course, $index) {
        if (!class_exists('GC_Examples')) {
            return '';
        }
        $items = GC_Examples::get($course, $index);
        if (!$items) {
            return '';
        }
        $media = array();
        $texts = array();
        foreach ($items as $item) {
            $kind = (string) ($item['kind'] ?? 'image');
            if (in_array($kind, array('image', 'video'), true) && !empty($item['url'])) {
                $media[] = $item;
            } else {
                $texts[] = (string) $item['title'];
            }
        }
        ob_start();
        ?>
        <section class="gc-examples gc-examples--teaser">
            <h3>Что внутри модуля</h3>
            <?php if ($media) : ?>
                <p class="gc-fine">Эти работы сделаны в мастерской курса — в самом модуле к каждой идёт разбор:
                    почему так, а не иначе.</p>
                <div class="gc-teaser-grid">
                    <?php foreach ($media as $item) : ?>
                        <figure class="gc-teaser">
                            <?php if ((string) $item['kind'] === 'video') : ?>
                                <video src="<?php echo esc_url($item['url']); ?>" controls loop muted playsinline preload="metadata"></video>
                            <?php else : ?>
                                <img src="<?php echo esc_url($item['url']); ?>" alt="<?php echo esc_attr((string) $item['title']); ?>"
                                     loading="lazy" decoding="async">
                            <?php endif; ?>
                            <figcaption><?php echo esc_html((string) $item['title']); ?></figcaption>
                        </figure>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <?php if ($texts) : ?>
                <p class="gc-teaser-list"><strong>Разборы и готовые образцы в модуле:</strong>
                    <?php echo esc_html(implode(', ', $texts)); ?>.</p>
            <?php endif; ?>
        </section>
        <?php
        return ob_get_clean();
    }

    private static function studio($user, $course, $paid) {
        if (!GC_Courses::tools_open($user->ID, $course)) {
            $where = GC_Gpu::engine($user->ID, $course) === 'kie'
                ? 'Кадры и ролики считаются на наших серверах: своя видеокарта не нужна, описание — единственная настройка.'
                : 'Кадры и ролики считаются на нашей видеокарте: после оплаты обучения количество не ограничено месяц.';
            return '<section class="gc-card"><h2 class="gc-h2">Мастерская</h2>'
                 . '<p class="gc-lead">' . esc_html($where)
                 . ' Переделывать описание по двадцать раз — это и есть обучение.</p>'
                 . self::locked_note($course, 'Мастерская генераций') . '</section>';
        }
        $jobs = GC_Gpu::for_user($user->ID);
        $engine = GC_Gpu::engine($user->ID, $course);
        $kie = $engine === 'kie';
        $health = GC_Gpu::state_for($user->ID, $course);
        $active = GC_Students::gpu_active($user->ID);
        $gpu = GC_Gpu::settings();
        $video = $kie ? GC_Kie::video_allowed() : GC_Gpu::video_allowed($gpu);
        $daily = $kie ? GC_Kie::daily() : (int) $gpu['daily'];
        ob_start();
        ?>
        <section class="gc-card">
            <h2 class="gc-h2">Мастерская</h2>
            <?php if ($kie) : ?>
                <?php /* Про поставщика моделей ученику знать незачем: ему важно,
                          что считается у нас и сколько можно в сутки. */ ?>
                <p class="gc-lead">Кадры и ролики считаются на наших серверах — видеокарту заводить не нужно.
                    До <?php echo (int) GC_Kie::daily('image'); ?> кадров и <?php echo (int) GC_Kie::daily('video'); ?>
                    роликов в сутки: переделывать описание по двадцать раз — это и есть обучение.</p>
            <?php else : ?>
                <p class="gc-lead">Кадры и ролики считаются на нашей видеокарте: пока идёт месяц без лимита, количество не
                    ограничено. Переделывать описание по двадцать раз — это и есть обучение.</p>
            <?php endif; ?>

            <?php echo self::pack_card($user, $course); // phpcs:ignore WordPress.Security.EscapeOutput ?>

            <p class="gc-gpu-state <?php echo $health['ok'] ? 'is-on' : 'is-off'; ?>">
                <?php // Ученику важно одно: считается или нет. Модель видеокарты и
                      // режим памяти — это для админки.
                      if ($health['ok']) {
                          echo 'Генерации включены';
                      } else {
                          echo $kie
                              ? 'Модели сейчас не отвечают — задания встанут в очередь'
                              : 'Машина выключена — задания встанут в очередь';
                      } ?>
            </p>

            <?php if (!$active) : ?>
                <div class="gc-note"><?php echo $kie
                    ? 'Срок доступа к генерациям закончился. Напишите нам, если нужно продлить.'
                    : 'Месяц безлимитных генераций закончился. Напишите нам, если нужно продлить.'; ?></div>
            <?php else : ?>
                <form class="gc-form gc-studio" id="gc-gen-form">
                    <?php if ($video) : ?>
                        <div class="gc-kind" role="tablist">
                            <label class="<?php echo (isset($_GET['kind']) && $_GET['kind'] === 'video') ? '' : 'is-on'; ?>">
                                <input type="radio" name="kind" value="image" <?php checked(!isset($_GET['kind']) || $_GET['kind'] !== 'video'); ?>> Кадр</label>
                            <label class="<?php echo (isset($_GET['kind']) && $_GET['kind'] === 'video') ? 'is-on' : ''; ?>">
                                <input type="radio" name="kind" value="video" <?php checked(isset($_GET['kind']) && $_GET['kind'] === 'video'); ?>> Ролик</label>
                        </div>
                    <?php else : ?>
                        <input type="hidden" name="kind" value="image">
                    <?php endif; ?>

                    <?php
                    /*
                     * Ступень ролика. Модели различаются в цене в
                     * несколько раз, поэтому выбор стоит рядом с кнопкой,
                     * а не в тонких настройках: человек должен видеть, во
                     * что ему обойдётся ролик, до того как нажмёт.
                     */
                    if ($kie && $video) : ?>
                        <div class="gc-tier" id="gc-tier" <?php echo $preset_kind === 'video' ? '' : 'hidden'; ?>>
                            <p class="gc-tier__head">Чем считать ролик</p>
                            <div class="gc-tier__list">
                                <?php foreach (GC_Kie::tiers() as $key => $tier) : ?>
                                    <label class="gc-tier__item <?php echo $key === GC_Kie::TIER_DEFAULT ? 'is-on' : ''; ?>">
                                        <input type="radio" name="tier" value="<?php echo esc_attr($key); ?>"
                                               <?php checked($key, GC_Kie::TIER_DEFAULT); ?>>
                                        <span class="gc-tier__name"><?php echo esc_html($tier['title']); ?>
                                            <i><?php echo esc_html($tier['model']); ?></i></span>
                                        <span class="gc-tier__cost"><?php echo (int) $tier['cost']; ?>
                                            <?php echo esc_html(self::rollers((int) $tier['cost'])); ?>
                                            из пакета</span>
                                        <span class="gc-tier__note"><?php echo esc_html($tier['note']); ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php
                    // Описание из урока: кнопка «Сделать это задание»
                    // приводит сюда с готовым текстом, размером и видом.
                    $preset = isset($_GET['prompt']) ? sanitize_textarea_field(wp_unslash(rawurldecode((string) $_GET['prompt']))) : '';
                    $preset_size = isset($_GET['size']) ? sanitize_text_field(wp_unslash($_GET['size'])) : '';
                    $preset_kind = isset($_GET['kind']) && $_GET['kind'] === 'video' ? 'video' : 'image';
                    ?>
                    <?php if ($preset !== '') : ?>
                        <p class="gc-preset">Описание из урока уже вписано — можно сразу нажать «Сгенерировать»
                            или поменять в нём пару слов и посмотреть, что изменится.</p>
                    <?php endif; ?>
                    <label>
                        <span>Опишите, что нужно</span>
                        <textarea name="prompt" rows="3" placeholder="Что в кадре, как расположено, какой свет, какой фон, в каком стиле снято"><?php
                            echo esc_textarea($preset); ?></textarea>
                    </label>

                    <?php
                    /*
                     * Референс — главная вещь в работе креатора: заказчик
                     * почти всегда приносит «как здесь, только с нашим
                     * товаром». Поэтому загрузка стоит на виду, а не в
                     * тонких настройках.
                     */
                    ?>
                    <div class="gc-ref">
                        <div class="gc-ref__drop" id="gc-ref-drop">
                            <input type="file" id="gc-ref-file" accept="image/jpeg,image/png,image/webp" hidden>
                            <button type="button" class="gc-ref__btn" id="gc-ref-pick">Выбрать референс</button>
                            <span class="gc-ref__hint">или перетащите картинку сюда — jpg, png, webp до 12 МБ</span>
                        </div>
                        <div class="gc-ref__preview" id="gc-ref-preview" hidden>
                            <img alt="" id="gc-ref-img">
                            <div>
                                <p class="gc-ref__name" id="gc-ref-name"></p>
                                <label class="gc-ref__range">
                                    <span>Насколько держаться референса: <b id="gc-ref-val">0.75</b></span>
                                    <input type="range" name="strength" min="0.1" max="1" step="0.05" value="0.75">
                                </label>
                                <button type="button" class="gc-ref__drop-btn" id="gc-ref-clear">Убрать</button>
                            </div>
                        </div>
                        <input type="hidden" name="reference" id="gc-ref-id" value="">
                    </div>

                    <div class="gc-row">
                        <label>
                            <span>Размер</span>
                            <select name="size">
                                <?php if ($kie) :
                                    $sizes = array(
                                        '1024x1024' => 'Квадрат — карточка товара',
                                        '1080x1920' => 'Вертикальный — сторис и рилс',
                                        '1920x1080' => 'Горизонтальный — ютуб и сайт',
                                        '1080x1440' => 'Книжный 3:4 — пост в ленте',
                                        '1440x1080' => 'Альбомный 4:3 — обложка',
                                    );
                                    $chosen = isset($sizes[$preset_size]) ? $preset_size : '1024x1024';
                                    foreach ($sizes as $value => $label) : ?>
                                        <option value="<?php echo esc_attr($value); ?>" <?php selected($value, $chosen); ?>><?php
                                            echo esc_html($label); ?></option>
                                    <?php endforeach; ?>
                                <?php else : ?>
                                    <option value="1024x1024">Квадрат 1024×1024</option>
                                    <option value="832x1216">Вертикальный 832×1216</option>
                                    <option value="1216x832">Горизонтальный 1216×832</option>
                                    <option value="768x1344">Сторис 768×1344</option>
                                    <option value="1344x768">Широкий 1344×768</option>
                                <?php endif; ?>
                            </select>
                        </label>
                        <button type="submit" class="gc-btn">Сгенерировать</button>
                    </div>

                    <?php if (!$kie) : ?>
                    <details class="gc-more">
                        <summary>Тонкие настройки</summary>
                        <div class="gc-more__grid">
                            <label>
                                <span>Чего не должно быть в кадре</span>
                                <textarea name="negative" rows="2" placeholder="текст, надписи, водяной знак, искажённые руки"></textarea>
                            </label>
                            <label>
                                <span>Шагов расчёта <b class="gc-val" data-for="steps">28</b></span>
                                <input type="range" name="steps" min="6" max="60" step="1" value="28">
                                <i>Больше шагов — чище кадр и дольше ожидание.</i>
                            </label>
                            <label>
                                <span>Насколько строго по описанию <b class="gc-val" data-for="cfg">6.5</b></span>
                                <input type="range" name="cfg" min="1" max="14" step="0.5" value="6.5">
                                <i>Выше — точнее по словам, но скучнее картинка. Для роликов ставим около тройки:
                                    на шести LTX идёт пятнами.</i>
                            </label>
                            <label>
                                <span>Зерно</span>
                                <input type="number" name="seed" min="0" max="2147483646" value="0" placeholder="0 — случайное">
                                <i>Одно и то же зерно с тем же описанием даёт тот же кадр.</i>
                            </label>
                            <?php if ($video) : ?>
                                <label class="gc-only-video">
                                    <span>Длина ролика, кадров <b class="gc-val" data-for="frames">49</b></span>
                                    <input type="range" name="frames" min="16" max="129" step="1" value="49">
                                    <i>При 24 кадрах в секунду 49 кадров — это две секунды, 121 кадр — пять.</i>
                                </label>
                                <label class="gc-only-video">
                                    <span>Кадров в секунду <b class="gc-val" data-for="fps">16</b></span>
                                    <input type="range" name="fps" min="6" max="30" step="1" value="24">
                                </label>
                            <?php endif; ?>
                        </div>
                    </details>
                    <?php else : ?>
                        <p class="gc-fine">Описание — единственная настройка, которая здесь нужна: модели на наших
                            серверах принимают его целиком, вместе со светом, фоном и стилем съёмки.</p>
                    <?php endif; ?>

                    <p class="gc-note-line" role="status"></p>
                </form>
            <?php endif; ?>

            <div class="gc-jobs" id="gc-jobs">
                <?php echo self::jobs_markup($jobs); // phpcs:ignore WordPress.Security.EscapeOutput ?>
            </div>
        </section>
        <?php
        return ob_get_clean();
    }

    public static function jobs_markup($jobs) {
        if (!$jobs) {
            return '<p class="gc-fine">Здесь появятся ваши кадры.</p>';
        }
        ob_start();
        ?>
        <ul class="gc-grid">
            <?php foreach ($jobs as $job) : ?>
                <li class="gc-job is-<?php echo esc_attr($job['status']); ?>" data-job="<?php echo (int) $job['id']; ?>">
                    <?php if ($job['status'] === 'done' && !empty($job['result_url'])) : ?>
                        <?php
                        // Решаем по файлу, а не только по виду задания:
                        // узел сохранения на машине может отдать
                        // анимированный webp, и тег video его не играет.
                        $still = (bool) preg_match('~\.(png|jpe?g|webp)([^a-z]|$)~i', (string) $job['result_url']);
                        $is_video = !$still && ((string) $job['kind'] === 'video'
                            || preg_match('~\.(mp4|webm|mov|gif)([^a-z]|$)~i', (string) $job['result_url']));
                        ?>
                        <?php if ($is_video) : ?>
                            <video src="<?php echo esc_url($job['result_url']); ?>" controls loop muted playsinline preload="metadata"></video>
                        <?php else : ?>
                            <a href="<?php echo esc_url($job['result_url']); ?>" target="_blank" rel="noopener">
                                <img src="<?php echo esc_url($job['result_url']); ?>" alt="" loading="lazy" decoding="async" />
                            </a>
                        <?php endif; ?>
                    <?php else : ?>
                        <span class="gc-job__state">
                            <?php
                            if ($job['status'] === 'failed') {
                                echo 'не вышло: ' . esc_html(mb_substr((string) $job['error'], 0, 90));
                            } elseif ($job['status'] === 'running') {
                                echo 'считается…';
                            } else {
                                echo 'в очереди';
                            }
                            ?>
                        </span>
                    <?php endif; ?>
                    <span class="gc-job__prompt"><?php echo esc_html(mb_substr((string) $job['prompt'], 0, 90)); ?></span>
                    <?php if ($job['status'] === 'done' && !empty($job['result_url'])) : ?>
                        <button type="button" class="gc-share<?php echo !empty($job['shared']) ? ' is-on' : ''; ?>"
                                data-job="<?php echo (int) $job['id']; ?>"
                                data-on="<?php echo !empty($job['shared']) ? '1' : '0'; ?>"><?php
                            echo !empty($job['shared']) ? 'В галерее' : 'В галерею'; ?></button>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
        <?php
        return ob_get_clean();
    }

    private static function works($user, $course, $paid) {
        $works = GC_Works::for_user($user->ID);
        ob_start();
        ?>
        <section class="gc-card">
            <h2 class="gc-h2">Мои работы</h2>
            <?php if (!GC_Courses::tools_open($user->ID, $course)) : ?>
                <p class="gc-lead">Каждый модуль заканчивается работой: вы присылаете её сюда, в ответ приходит
                    разбор — что отдавать заказчику, а что переделать.</p>
                <?php echo self::locked_note($course, 'Сдача работ на проверку'); // phpcs:ignore WordPress.Security.EscapeOutput ?>
            <?php elseif (!$works) : ?>
                <p class="gc-fine">Пока ничего не сдано. Работы появятся здесь вместе с разбором.</p>
            <?php else : ?>
                <ul class="gc-works">
                    <?php foreach ($works as $w) :
                        $m = GC_Program::module((int) $w['module'], $course);
                        ?>
                        <li class="is-<?php echo esc_attr($w['status']); ?>">
                            <p class="gc-works__head">
                                <strong>Модуль <?php echo (int) ($w['module'] + 1); ?><?php echo $m ? ': ' . esc_html($m['title']) : ''; ?></strong>
                                <span><?php echo esc_html(date_i18n('j F, H:i', strtotime($w['created_at']))); ?></span>
                            </p>
                            <p class="gc-works__state"><?php echo esc_html($w['status'] === 'accepted' ? 'Принята' : ($w['status'] === 'rework' ? 'На доработку' : 'Ждёт проверки')); ?></p>
                            <?php if (!empty($w['links'])) : ?>
                                <p class="gc-fine"><?php echo esc_html($w['links']); ?></p>
                            <?php endif; ?>
                            <?php if (!empty($w['feedback'])) : ?>
                                <div class="gc-feedback"><strong>Разбор:</strong> <?php echo wp_kses_post(wpautop($w['feedback'])); ?></div>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>
        <?php
        return ob_get_clean();
    }
}
