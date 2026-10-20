<?php
/**
 * Личный кабинет.
 *
 * Одна страница с шорткодом: список модулей, открытый модуль, сдача
 * работы и мастерская генераций. Своего шаблона темы не трогаем — тема
 * на сайте одна и меняться не собирается, а лишний шаблон пришлось бы
 * чинить при каждом её обновлении.
 */

if (!defined('ABSPATH')) {
    exit;
}

class GC_Cabinet {

    const SLUG     = 'kabinet-kursa';
    const OPT_PAGE = 'gc_cabinet_page';

    public static function boot() {
        add_shortcode('genius_course_cabinet', array(__CLASS__, 'render'));
        add_action('wp_enqueue_scripts', array(__CLASS__, 'assets'));
        add_filter('body_class', array(__CLASS__, 'body_class'));
        // Кабинет в поиске не нужен: он пустой для всех, кроме ученика.
        add_action('wp_head', array(__CLASS__, 'noindex'), 1);
    }

    public static function ensure_page() {
        $page_id = (int) get_option(self::OPT_PAGE);
        if ($page_id > 0 && get_post($page_id)) {
            return;
        }
        $existing = get_page_by_path(self::SLUG);
        if ($existing) {
            $page_id = (int) $existing->ID;
            wp_update_post(array('ID' => $page_id, 'post_content' => '[genius_course_cabinet]', 'post_status' => 'publish'));
        } else {
            $page_id = (int) wp_insert_post(array(
                'post_title'   => 'Кабинет обучения',
                'post_name'    => self::SLUG,
                'post_content' => '[genius_course_cabinet]',
                'post_status'  => 'publish',
                'post_type'    => 'page',
            ));
        }
        if ($page_id > 0) {
            update_option(self::OPT_PAGE, $page_id, false);
        }
    }

    public static function url() {
        $page_id = (int) get_option(self::OPT_PAGE);
        $url = $page_id > 0 ? get_permalink($page_id) : '';
        return $url ? $url : home_url('/' . self::SLUG . '/');
    }

    public static function is_page() {
        $page_id = (int) get_option(self::OPT_PAGE);
        return !is_admin() && $page_id > 0 && is_page($page_id);
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
            'rest'  => esc_url_raw(rest_url('genius-course/v1/')),
            'nonce' => wp_create_nonce('wp_rest'),
        ));
    }

    public static function render() {
        if (!is_user_logged_in()) {
            return self::login_form();
        }
        if (!GC_Students::is_student()) {
            return '<div class="gc"><div class="gc-note">Этот кабинет для учеников курса. Если вы оплатили обучение,'
                 . ' а доступа нет — напишите нам, выдадим.</div></div>';
        }

        $user = wp_get_current_user();
        $modules = GC_Program::modules();
        $done = GC_Students::progress($user->ID);
        /*
         * Имя «m» здесь занято: WordPress разбирает его как архив по
         * дате, и адрес вида ?m=3 отдавал 404 вместо модуля. Своим
         * параметрам нужны имена, которых нет в списке системных.
         */
        $open = isset($_GET['mod']) ? max(0, min(count($modules) - 1, (int) $_GET['mod'])) : -1;
        $tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'program';

        ob_start();
        ?>
        <div class="gc">
            <?php echo self::header($user, $done, $modules); // phpcs:ignore WordPress.Security.EscapeOutput ?>

            <nav class="gc-tabs">
                <a class="<?php echo $tab === 'program' && $open < 0 ? 'is-on' : ''; ?>" href="<?php echo esc_url(self::url()); ?>">Программа</a>
                <a class="<?php echo $tab === 'studio' ? 'is-on' : ''; ?>" href="<?php echo esc_url(add_query_arg('tab', 'studio', self::url())); ?>">Мастерская</a>
                <a class="<?php echo $tab === 'works' ? 'is-on' : ''; ?>" href="<?php echo esc_url(add_query_arg('tab', 'works', self::url())); ?>">Мои работы</a>
            </nav>

            <?php
            if ($tab === 'studio') {
                echo self::studio($user); // phpcs:ignore WordPress.Security.EscapeOutput
            } elseif ($tab === 'works') {
                echo self::works($user); // phpcs:ignore WordPress.Security.EscapeOutput
            } elseif ($open >= 0) {
                echo self::module($user, $open, $modules[$open], $done); // phpcs:ignore WordPress.Security.EscapeOutput
            } else {
                echo self::program($user, $modules, $done); // phpcs:ignore WordPress.Security.EscapeOutput
            }
            ?>
        </div>
        <?php
        return ob_get_clean();
    }

    private static function login_form() {
        ob_start();
        ?>
        <div class="gc gc--narrow">
            <div class="gc-card">
                <h2 class="gc-h2">Вход в кабинет</h2>
                <p class="gc-lead">Логин и пароль мы присылаем после оплаты. Если письмо потерялось — напишите нам,
                    выдадим новый пароль.</p>
                <?php
                wp_login_form(array(
                    'redirect'       => self::url(),
                    'label_username' => 'Логин или почта',
                    'label_password' => 'Пароль',
                    'label_log_in'   => 'Войти',
                    'remember'       => true,
                ));
                ?>
                <p class="gc-fine"><a href="<?php echo esc_url(wp_lostpassword_url(self::url())); ?>">Забыли пароль?</a></p>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    private static function header($user, $done, $modules) {
        $total = count($modules);
        $passed = count(array_intersect($done, range(0, $total - 1)));
        $percent = $total > 0 ? round($passed / $total * 100) : 0;
        $until = GC_Students::gpu_until($user->ID);
        ob_start();
        ?>
        <header class="gc-head">
            <div>
                <p class="gc-hello">Здравствуйте, <?php echo esc_html($user->display_name); ?></p>
                <h1 class="gc-h1">Обучение AI-креатора</h1>
            </div>
            <div class="gc-head-side">
                <div class="gc-progress" role="img" aria-label="Пройдено <?php echo (int) $passed; ?> из <?php echo (int) $total; ?>">
                    <div class="gc-progress__bar"><span style="width: <?php echo (int) $percent; ?>%"></span></div>
                    <p><?php echo (int) $passed; ?> из <?php echo (int) $total; ?> модулей</p>
                </div>
                <?php if ($until !== '') : ?>
                    <p class="gc-gpu <?php echo GC_Students::gpu_active($user->ID) ? 'is-on' : 'is-off'; ?>">
                        <?php if (GC_Students::gpu_active($user->ID)) : ?>
                            Генерации без лимита до <?php echo esc_html(date_i18n('j F', strtotime($until))); ?>
                        <?php else : ?>
                            Месяц генераций закончился
                        <?php endif; ?>
                    </p>
                <?php endif; ?>
                <a class="gc-exit" href="<?php echo esc_url(wp_logout_url(home_url('/'))); ?>">Выйти</a>
            </div>
        </header>
        <?php
        return ob_get_clean();
    }

    private static function program($user, $modules, $done) {
        ob_start();
        ?>
        <ol class="gc-modules">
            <?php foreach ($modules as $i => $m) :
                $work = GC_Works::last_for_module($user->ID, $i);
                $state = in_array($i, $done, true) ? 'done' : ($work ? 'sent' : 'open');
                ?>
                <li class="gc-module is-<?php echo esc_attr($state); ?>">
                    <a href="<?php echo esc_url(add_query_arg('mod', $i, self::url())); ?>">
                        <span class="gc-module__n"><?php echo (int) ($i + 1); ?></span>
                        <span class="gc-module__body">
                            <strong><?php echo esc_html($m['title']); ?></strong>
                            <span><?php echo esc_html($m['lead']); ?></span>
                        </span>
                        <span class="gc-module__state">
                            <?php
                            if ($state === 'done') {
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

    private static function module($user, $index, $m, $done) {
        $work = GC_Works::last_for_module($user->ID, $index);
        ob_start();
        ?>
        <article class="gc-card gc-lesson">
            <p class="gc-back"><a href="<?php echo esc_url(self::url()); ?>">← Все модули</a></p>
            <p class="gc-kicker">Модуль <?php echo (int) ($index + 1); ?> из <?php echo (int) GC_Program::count(); ?></p>
            <h2 class="gc-h2"><?php echo esc_html($m['title']); ?></h2>
            <p class="gc-lead"><?php echo esc_html($m['lead']); ?></p>
            <p class="gc-goal"><strong>Чему научитесь:</strong> <?php echo esc_html($m['goal']); ?></p>

            <?php foreach ($m['lessons'] as $n => $lesson) : ?>
                <section class="gc-step">
                    <h3><span><?php echo (int) ($n + 1); ?></span><?php echo esc_html($lesson[0]); ?></h3>
                    <p><?php echo esc_html($lesson[1]); ?></p>
                </section>
            <?php endforeach; ?>

            <div class="gc-task">
                <h3>Задание</h3>
                <p><?php echo esc_html($m['task']); ?></p>
                <p class="gc-task__deliver"><strong>Что сдать:</strong> <?php echo esc_html($m['deliver']); ?></p>
                <p class="gc-fine"><?php echo esc_html($m['hint']); ?></p>
            </div>

            <?php if ($work) : ?>
                <div class="gc-sent is-<?php echo esc_attr($work['status']); ?>">
                    <p><strong>Работа сдана <?php echo esc_html(date_i18n('j F, H:i', strtotime($work['created_at']))); ?>.</strong>
                        Статус: <?php echo esc_html($work['status'] === 'accepted' ? 'принята' : ($work['status'] === 'rework' ? 'на доработку' : 'ждёт проверки')); ?>.</p>
                    <?php if (!empty($work['feedback'])) : ?>
                        <div class="gc-feedback"><strong>Разбор:</strong> <?php echo wp_kses_post(wpautop($work['feedback'])); ?></div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

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
                <button type="submit" class="gc-btn">Отправить на проверку</button>
                <p class="gc-note-line" role="status"></p>
            </form>

            <form class="gc-done" id="gc-done-form" data-module="<?php echo (int) $index; ?>">
                <label><input type="checkbox" <?php checked(in_array($index, $done, true)); ?>> Отметить модуль пройденным</label>
            </form>

            <nav class="gc-nav">
                <?php if ($index > 0) : ?>
                    <a class="gc-nav__prev" href="<?php echo esc_url(add_query_arg('mod', $index - 1, self::url())); ?>">
                        ← Модуль <?php echo (int) $index; ?></a>
                <?php else : ?><span></span><?php endif; ?>
                <?php if ($index + 1 < GC_Program::count()) : ?>
                    <a class="gc-nav__next" href="<?php echo esc_url(add_query_arg('mod', $index + 1, self::url())); ?>">
                        Модуль <?php echo (int) ($index + 2); ?> →</a>
                <?php else : ?>
                    <a class="gc-nav__next" href="<?php echo esc_url(self::url()); ?>">К списку модулей</a>
                <?php endif; ?>
            </nav>
        </article>
        <?php
        return ob_get_clean();
    }

    private static function studio($user) {
        $jobs = GC_Gpu::for_user($user->ID);
        $health = GC_Gpu::health();
        $active = GC_Students::gpu_active($user->ID);
        $gpu = GC_Gpu::settings();
        $video = GC_Gpu::video_allowed($gpu);
        ob_start();
        ?>
        <section class="gc-card">
            <h2 class="gc-h2">Мастерская</h2>
            <p class="gc-lead">Кадры и ролики считаются на нашей видеокарте: пока идёт месяц без лимита, количество не
                ограничено. Переделывать описание по двадцать раз — это и есть обучение.</p>

            <p class="gc-gpu-state <?php echo $health['ok'] ? 'is-on' : 'is-off'; ?>">
                <?php // Ученику важно одно: считается или нет. Модель видеокарты и
                      // режим памяти — это для админки.
                      echo $health['ok'] ? 'Генерации включены' : 'Машина выключена — задания встанут в очередь'; ?>
            </p>

            <?php if (!$active) : ?>
                <div class="gc-note">Месяц безлимитных генераций закончился. Напишите нам, если нужно продлить.</div>
            <?php else : ?>
                <form class="gc-form gc-studio" id="gc-gen-form">
                    <?php if ($video) : ?>
                        <div class="gc-kind" role="tablist">
                            <label class="is-on"><input type="radio" name="kind" value="image" checked> Кадр</label>
                            <label><input type="radio" name="kind" value="video"> Ролик</label>
                        </div>
                    <?php else : ?>
                        <input type="hidden" name="kind" value="image">
                    <?php endif; ?>

                    <label>
                        <span>Опишите, что нужно</span>
                        <textarea name="prompt" rows="3" placeholder="Что в кадре, как расположено, какой свет, какой фон, в каком стиле снято"></textarea>
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
                                <option value="1024x1024">Квадрат 1024×1024</option>
                                <option value="832x1216">Вертикальный 832×1216</option>
                                <option value="1216x832">Горизонтальный 1216×832</option>
                                <option value="768x1344">Сторис 768×1344</option>
                                <option value="1344x768">Широкий 1344×768</option>
                            </select>
                        </label>
                        <button type="submit" class="gc-btn">Сгенерировать</button>
                    </div>

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
                                <i>Выше — точнее по словам, но скучнее картинка.</i>
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
                                    <i>При 16 кадрах в секунду 49 кадров — это около трёх секунд.</i>
                                </label>
                                <label class="gc-only-video">
                                    <span>Кадров в секунду <b class="gc-val" data-for="fps">16</b></span>
                                    <input type="range" name="fps" min="6" max="30" step="1" value="16">
                                </label>
                            <?php endif; ?>
                        </div>
                    </details>

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
                </li>
            <?php endforeach; ?>
        </ul>
        <?php
        return ob_get_clean();
    }

    private static function works($user) {
        $works = GC_Works::for_user($user->ID);
        ob_start();
        ?>
        <section class="gc-card">
            <h2 class="gc-h2">Мои работы</h2>
            <?php if (!$works) : ?>
                <p class="gc-fine">Пока ничего не сдано. Работы появятся здесь вместе с разбором.</p>
            <?php else : ?>
                <ul class="gc-works">
                    <?php foreach ($works as $w) :
                        $m = GC_Program::module((int) $w['module']);
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
