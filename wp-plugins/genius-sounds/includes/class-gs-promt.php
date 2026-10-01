<?php
/**
 * Раздел промтов: примеры к статьям рисуются при публикации.
 *
 * Статья с промтом без примера бесполезна: человек не видит, что он
 * получит. Рисовать двести примеров заранее — значит заплатить за них
 * все сразу и получить картинки, устаревшие к моменту выхода статьи.
 * Поэтому пример рисуется в момент публикации, по промту из самой статьи.
 *
 * Картинку обязательно копируем к себе: ссылки поставщика живут часы, а
 * статья должна работать годами.
 */

if (!defined('ABSPATH')) {
    exit;
}

class GS_Promt {

    /** Промт, по которому рисуется пример. */
    const META_PROMPT = '_gs_promt';
    /** Сколько примеров нужно статье. */
    const META_SHOTS  = '_gs_promt_shots';
    /** Задачи, которые ждут результата. */
    const META_TASKS  = '_gs_promt_tasks';
    /** Готово — чтобы не рисовать второй раз при любой правке. */
    const META_DONE   = '_gs_promt_done';
    /** Почему пример не запустился — чтобы не гадать по логам. */
    const META_ERROR  = '_gs_promt_error';
    /** Сколько раз пробовали: бесконечно повторять нельзя. */
    const META_TRIES  = '_gs_promt_tries';
    /**
     * Когда пробовать снова.
     *
     * Раньше попыток было ровно три, и статья, которой не повезло три раза
     * подряд, оставалась без картинки навсегда. Теперь попытки не кончаются,
     * но между ними растёт пауза — от десяти минут до суток, чтобы
     * неудачная статья не ходила к поставщику каждые пять минут.
     */
    const META_NEXT   = '_gs_promt_next';
    /** Когда запущены текущие задачи — от этого считается терпение. */
    const META_STARTED = '_gs_promt_started';
    /** После стольких неудач статья попадает в админке в «давно ждёт». */
    const LONG_WAIT   = 3;
    /** Пауза после отказа по деньгам: долбиться в пустой счёт незачем. */
    const OPT_PAUSE   = 'gs_promt_paused_until';
    const PAUSE_TTL   = 1800;

    /** Место, куда встаёт пример. */
    const MARKER = '<!--gs-promt-example-->';

    const DIR  = 'promt';
    const HOOK = 'gs_promt_collect';

    /** Ссылки, которые идут в каждую статью раздела. */
    const TG_CHANNEL = 'https://t.me/promtnanobanana7';
    const TG_BOT     = 'https://t.me/Neuro_HubAI_bot?start=Sv_lana0707';
    const MAX_CHANNEL = 'https://max.ru/join/Ba2dnqkVMbJlRI3BlvIWp95Grn7SzFESG7HxtazTosw';

    public static function boot() {
        // Фильтр расписаний — раньше всего остального: wp_schedule_event
        // проверяет интервал по уже зарегистрированному списку и молча
        // отказывается, если фильтр навешен позже. Из-за этого задача
        // сбора не вставала вовсе.
        add_filter('cron_schedules', array(__CLASS__, 'add_schedule'));
        add_action('init', array(__CLASS__, 'register_meta'));
        add_action('init', array(__CLASS__, 'ensure_cron'));
        add_action('transition_post_status', array(__CLASS__, 'on_publish'), 10, 3);
        add_action(self::HOOK, array(__CLASS__, 'collect'));
        add_action('admin_post_gs_promt_collect', array(__CLASS__, 'handle_collect'));
        add_action('admin_post_gs_promt_retry', array(__CLASS__, 'handle_retry'));
    }

    public static function ensure_cron() {
        if (!wp_next_scheduled(self::HOOK)) {
            wp_schedule_event(time() + 60, 'gs_five_minutes', self::HOOK);
        }
    }

    /** Ручной прогон сбора — когда ждать пять минут незачем. */
    public static function handle_collect() {
        if (!current_user_can('manage_options')) {
            wp_die('Недостаточно прав');
        }
        check_admin_referer('gs_promt_collect');
        self::collect();
        wp_safe_redirect(admin_url('admin.php?page=genius-sounds') . '#gs-promt');
        exit;
    }

    /**
     * Попробовать прямо сейчас, не дожидаясь своей очереди.
     *
     * Сами попытки не кончаются, но после нескольких неудач пауза между
     * ними доходит до суток. Кнопка нужна, когда причина уже устранена —
     * счёт пополнили — и ждать сутки незачем.
     */
    public static function handle_retry() {
        if (!current_user_can('manage_options')) {
            wp_die('Недостаточно прав');
        }
        check_admin_referer('gs_promt_retry');

        $posts = get_posts(array(
            'post_type' => 'post', 'post_status' => 'publish', 'numberposts' => -1,
            'meta_key' => self::META_PROMPT, 'suppress_filters' => true,
        ));
        foreach ($posts as $post) {
            if (strpos((string) $post->post_content, self::MARKER) === false) {
                continue;
            }
            delete_post_meta($post->ID, self::META_TRIES);
            delete_post_meta($post->ID, self::META_DONE);
            delete_post_meta($post->ID, self::META_NEXT);
        }
        delete_option(self::OPT_PAUSE);
        self::collect();
        wp_safe_redirect(admin_url('admin.php?page=genius-sounds') . '#gs-promt');
        exit;
    }

    /** Сколько статей ждёт примера и сколько его получило. */
    public static function stats() {
        $posts = get_posts(array(
            'post_type' => 'post', 'post_status' => 'publish', 'numberposts' => -1,
            'meta_key' => self::META_PROMPT, 'suppress_filters' => true,
        ));

        // Считаем по тексту статьи, а не по метке «готово»: метка
        // означает только, что задачу перестали ждать, а вышла картинка
        // или нет — видно по маркеру.
        $done = $waiting = $stuck = 0;
        foreach ($posts as $post) {
            if (strpos((string) $post->post_content, self::MARKER) === false) {
                $done++;
            } elseif (get_post_meta($post->ID, self::META_TASKS, true)) {
                $waiting++;
            } else {
                $waiting++;
                // Сдаваться мы больше не сдаёмся, но показать, что статья
                // ждёт картинку уже третью попытку, стоит: обычно за этим
                // стоит пустой счёт у поставщика, и он решается деньгами.
                if ((int) get_post_meta($post->ID, self::META_TRIES, true) >= self::LONG_WAIT) {
                    $stuck++;
                }
            }
        }

        return array(
            'waiting' => $waiting,
            'done'    => $done,
            'stuck'   => $stuck,
            'error'   => self::last_error(),
            'paused'  => (int) get_option(self::OPT_PAUSE),
            'next'    => wp_next_scheduled(self::HOOK),
        );
    }

    /** Последняя причина отказа — чтобы не гадать, промт виноват или деньги. */
    public static function last_error() {
        $posts = get_posts(array(
            'post_type' => 'post', 'post_status' => 'publish', 'numberposts' => 1,
            'meta_key' => self::META_ERROR, 'orderby' => 'modified', 'order' => 'DESC',
            'fields' => 'ids', 'suppress_filters' => true,
        ));
        return $posts ? (string) get_post_meta($posts[0], self::META_ERROR, true) : '';
    }

    public static function add_schedule($schedules) {
        if (!isset($schedules['gs_five_minutes'])) {
            $schedules['gs_five_minutes'] = array('interval' => 300, 'display' => 'Каждые 5 минут');
        }
        return $schedules;
    }

    public static function register_meta() {
        $auth = function () {
            return current_user_can('edit_posts');
        };
        // Причина отказа и счётчик попыток видны в REST намеренно: без них
        // разбираться, почему статья вышла без примера, приходится по логам
        // сервера, куда доступ есть не всегда.
        $fields = array(
            self::META_PROMPT => 'string',
            self::META_SHOTS  => 'integer',
            self::META_ERROR  => 'string',
            self::META_TRIES  => 'integer',
            self::META_NEXT   => 'integer',
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

    /* ---------------------------------------------------------------------
     * Запуск при публикации
     * ------------------------------------------------------------------ */

    public static function on_publish($new, $old, $post) {
        if ($new !== 'publish' || $old === 'publish' || !($post instanceof WP_Post) || $post->post_type !== 'post') {
            return;
        }
        if (strpos((string) $post->post_content, self::MARKER) === false) {
            return;
        }

        self::start($post);
    }

    /**
     * Запускает генерацию примеров к статье.
     *
     * Отдельным методом, потому что запуск нужен и при публикации, и при
     * доборе: если в момент выхода статьи поставщик молчал, статья так и
     * останется без примера, пока её кто-нибудь не перевыпустит. Добор
     * снимает эту зависимость от удачного стечения обстоятельств.
     */
    public static function start($post) {
        $prompt = trim((string) get_post_meta($post->ID, self::META_PROMPT, true));
        if ($prompt === '') {
            return false;
        }

        $shots = (int) get_post_meta($post->ID, self::META_SHOTS, true);
        $shots = max(1, min(2, $shots ?: 1));

        $res = array();
        $tasks = array();
        for ($i = 0; $i < $shots; $i++) {
            $res = GS_Provider::job('image', array('prompt' => $prompt, 'ratio' => '3:4'));
            if (!empty($res['ok'])) {
                $tasks[] = $res['task'];
            }
        }

        if (!$tasks) {
            // Поставщик не принял — статья выходит без примера, и это
            // лучше, чем задержать публикацию до его выздоровления.
            // Ключ отказа у адаптера — message: по нему видно, лежит ли
            // поставщик или кончились деньги, а это разные решения.
            $why = trim((string) ($res['message'] ?? ''));
            if ($why === '') {
                $why = 'причина неизвестна';
            }
            error_log('genius-sounds: пример к статье ' . $post->ID . ' не запущен: ' . $why);
            update_post_meta($post->ID, self::META_ERROR, $why);

            // Пустой счёт — не вина статьи: попытку не засчитываем, иначе
            // за вечер без денег все статьи исчерпают лимит и останутся
            // без примеров навсегда. Вместо этого встаём на паузу.
            if (self::is_money($why)) {
                update_option(self::OPT_PAUSE, time() + self::PAUSE_TTL, false);
                update_post_meta($post->ID, self::META_NEXT, time() + self::PAUSE_TTL);
                return false;
            }

            $tries = (int) get_post_meta($post->ID, self::META_TRIES, true) + 1;
            update_post_meta($post->ID, self::META_TRIES, $tries);
            update_post_meta($post->ID, self::META_NEXT, time() + self::wait_for($tries));
            return false;
        }
        update_post_meta($post->ID, self::META_TRIES,
            (int) get_post_meta($post->ID, self::META_TRIES, true) + 1);
        delete_post_meta($post->ID, self::META_ERROR);
        delete_post_meta($post->ID, self::META_NEXT);
        delete_option(self::OPT_PAUSE);
        update_post_meta($post->ID, self::META_TASKS, $tasks);
        update_post_meta($post->ID, self::META_STARTED, time());
        return true;
    }

    /**
     * Статьи, вышедшие без примера, — добираем по нескольку за прогон.
     *
     * Ограничение в три штуки намеренное: поставщик берёт задачи не
     * бесплатно, и разом запускать полсотни генераций из-за одного
     * сбоя не нужно.
     */
    /**
     * Сколько ждать до следующей попытки.
     *
     * Первые неудачи чаще случайны — повторяем скоро. Если не выходит и
     * дальше, дело обычно в деньгах у поставщика: ходить к нему каждые
     * пять минут бессмысленно, но и бросать статью нельзя — рано или
     * поздно счёт пополнят, и картинка доедет сама.
     */
    private static function wait_for($tries) {
        $steps = array(
            10 * MINUTE_IN_SECONDS,
            30 * MINUTE_IN_SECONDS,
            HOUR_IN_SECONDS,
            3 * HOUR_IN_SECONDS,
            6 * HOUR_IN_SECONDS,
            12 * HOUR_IN_SECONDS,
        );
        $i = max(0, (int) $tries - 1);
        return isset($steps[$i]) ? $steps[$i] : DAY_IN_SECONDS;
    }

    /** Отказ из-за денег, а не из-за промта или сбоя. */
    private static function is_money($why) {
        $why = mb_strtolower((string) $why);
        foreach (array('средств', 'баланс', 'credit', 'insufficient', 'quota', 'оплат') as $word) {
            if (mb_strpos($why, $word) !== false) {
                return true;
            }
        }
        return false;
    }

    private static function start_missing($limit = 3) {
        if ((int) get_option(self::OPT_PAUSE) > time()) {
            return;
        }

        $posts = get_posts(array(
            'post_type'        => 'post',
            'post_status'      => 'publish',
            // Берём всех кандидатов, а не первые несколько: готова статья
            // или нет, видно только по маркеру в тексте, и при коротком
            // списке все места занимали давно нарисованные статьи, а те
            // две, что ждут картинку, до перебора не доходили.
            'numberposts'      => 200,
            'suppress_filters' => true,
            'meta_query'       => array(
                array('key' => self::META_PROMPT, 'compare' => 'EXISTS'),
                array('key' => self::META_TASKS, 'compare' => 'NOT EXISTS'),
                // Срок следующей попытки: у новой статьи его нет вовсе.
                array(
                    'relation' => 'OR',
                    array('key' => self::META_NEXT, 'compare' => 'NOT EXISTS'),
                    array('key' => self::META_NEXT, 'value' => time(),
                          'compare' => '<=', 'type' => 'NUMERIC'),
                ),
            ),
            // Сортировка по дате, а не по сроку следующей попытки:
            // сортировка по мете требует, чтобы мета существовала, и
            // статья, которую ещё ни разу не пробовали, выпадала из
            // выборки целиком. Свежие идут первыми — им картинка нужнее,
            // а давно отказавшие всё равно отсекаются по сроку.
            'orderby' => 'date',
            'order'   => 'DESC',
        ));

        $started = 0;
        foreach ($posts as $post) {
            if ($started >= $limit) {
                break;
            }
            // Метка «готово» сама по себе ничего не гарантирует: задача
            // могла завершиться отказом, и тогда в тексте так и остался
            // маркер, а картинки нет. Считаем работу сделанной только
            // тогда, когда маркер из текста ушёл.
            if (strpos((string) $post->post_content, self::MARKER) === false) {
                continue;
            }
            if (self::start($post)) {
                $started++;
            }
        }
    }

    /* ---------------------------------------------------------------------
     * Сбор готового
     * ------------------------------------------------------------------ */

    public static function collect() {
        $posts = get_posts(array(
            'post_type'        => 'post',
            'post_status'      => 'publish',
            'numberposts'      => 10,
            'meta_key'         => self::META_TASKS,
            'suppress_filters' => true,
        ));

        foreach ($posts as $post) {
            $tasks = (array) get_post_meta($post->ID, self::META_TASKS, true);
            if (!$tasks) {
                delete_post_meta($post->ID, self::META_TASKS);
                continue;
            }

            $urls = array();
            $waiting = false;
            foreach ($tasks as $task) {
                $res = GS_Provider::job_state($task);
                if (empty($res['ok'])) {
                    $waiting = true;
                    continue;
                }
                if ($res['state'] === 'success' && !empty($res['urls'])) {
                    $urls[] = $res['urls'][0];
                } elseif ($res['state'] !== 'fail') {
                    $waiting = true;
                }
            }

            // Ждём, пока не готовы все, но не дольше часа: подвисшая задача
            // не должна держать статью без картинки навсегда. Час считается
            // от запуска задач, а не от выхода статьи: у повторной попытки
            // статья давно опубликована, и по её дате терпение кончалось бы
            // раньше, чем поставщик успевал ответить.
            $started = (int) get_post_meta($post->ID, self::META_STARTED, true);
            if (!$started) {
                $started = (int) get_post_time('U', true, $post);
            }
            $stuck = (time() - $started) > HOUR_IN_SECONDS;
            if ($waiting && !$stuck) {
                continue;
            }

            delete_post_meta($post->ID, self::META_TASKS);
            delete_post_meta($post->ID, self::META_STARTED);

            if ($urls) {
                self::attach($post, $urls);
                update_post_meta($post->ID, self::META_DONE, 1);
                delete_post_meta($post->ID, self::META_NEXT);
                delete_post_meta($post->ID, self::META_ERROR);
                continue;
            }

            // Задача не дала картинки — отметкой «готово» это закрывать
            // нельзя: тогда статья навсегда остаётся с маркером вместо
            // примера. Ставим её в очередь на следующую попытку.
            $tries = (int) get_post_meta($post->ID, self::META_TRIES, true);
            update_post_meta($post->ID, self::META_NEXT, time() + self::wait_for($tries));
            update_post_meta($post->ID, self::META_ERROR,
                $stuck ? 'поставщик не ответил вовремя' : 'генерация не удалась');
        }

        // Добор идёт последним: запущенная только что задача не должна
        // попасть в разбор этого же прогона — ей нужно время.
        self::start_missing();
    }

    /** Кладём картинки в медиатеку и вставляем в текст статьи. */
    private static function attach($post, $urls) {
        $prompt = (string) get_post_meta($post->ID, self::META_PROMPT, true);
        $figures = '';
        $first_id = 0;

        foreach ($urls as $i => $url) {
            $id = self::sideload($url, $post, $i);
            if (!$id) {
                continue;
            }
            if (!$first_id) {
                $first_id = $id;
            }
            $src = wp_get_attachment_image_url($id, 'large');
            $alt = 'Пример по промту: ' . mb_substr($prompt, 0, 90);
            $figures .= "\n<figure class=\"wp-block-image size-large\">"
                . '<img src="' . esc_url($src) . '" alt="' . esc_attr($alt) . '" class="wp-image-' . (int) $id . '" loading="lazy" decoding="async">'
                . '<figcaption class="wp-element-caption">'
                . esc_html($i === 0 ? 'Что получается по этому промту' : 'Второй вариант по тому же промту')
                . "</figcaption></figure>\n";
        }

        if ($figures === '') {
            return;
        }

        // Два вертикальных кадра подряд — это два экрана прокрутки между
        // промтом и разбором. Рядом они читаются как пара вариантов, ради
        // чего их и рисуют.
        if (count($urls) > 1) {
            $figures = '<div class="gs-promt__shots">' . $figures . '</div>';
        }

        // Примеры ставим сразу после блока с промтом: человек читает промт
        // и тут же видит результат, не пролистывая статью.
        $content = (string) $post->post_content;
        if (strpos($content, self::MARKER) !== false) {
            $content = str_replace(self::MARKER, $figures, $content);
        } else {
            $content .= $figures;
        }

        wp_update_post(array('ID' => $post->ID, 'post_content' => $content));

        if ($first_id && !has_post_thumbnail($post->ID)) {
            set_post_thumbnail($post->ID, $first_id);
        }

        self::refresh($post);
    }

    /**
     * Показать обновлённую статью.
     *
     * Картинка приезжает через часы после публикации, и к этому моменту
     * страница давно лежит в кэше — без сброса читатель видел бы прежний
     * текст без примера. Заодно сообщаем поиску, что страница изменилась.
     */
    private static function refresh($post) {
        clean_post_cache($post->ID);

        // Кэш страниц ведёт сторонний плагин, и зовётся он по-разному в
        // разных версиях. Берём то, что есть.
        foreach (array('wpsc_delete_post_cache', 'wp_cache_post_change',
                       'rocket_clean_post', 'w3tc_flush_post') as $fn) {
            if (function_exists($fn)) {
                $fn($post->ID);
                break;
            }
        }

        $url = get_permalink($post);
        if ($url && class_exists('GS_Index')) {
            GS_Index::enqueue(array($url));
        }
    }

    private static function sideload($url, $post, $index) {
        $response = wp_remote_get($url, array('timeout' => 120));
        if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) !== 200) {
            return 0;
        }
        $body = wp_remote_retrieve_body($response);
        if (strlen($body) < 5000) {
            return 0;
        }

        $name = sanitize_title($post->post_name ?: 'promt') . '-' . ($index + 1) . '.png';
        $upload = wp_upload_bits($name, null, $body);
        if (!empty($upload['error'])) {
            return 0;
        }

        $id = wp_insert_attachment(array(
            'post_mime_type' => 'image/png',
            'post_title'     => $post->post_title,
            'post_status'    => 'inherit',
        ), $upload['file'], $post->ID);
        if (!$id) {
            return 0;
        }

        require_once ABSPATH . 'wp-admin/includes/image.php';
        wp_update_attachment_metadata($id, wp_generate_attachment_metadata($id, $upload['file']));
        update_post_meta($id, '_wp_attachment_image_alt', 'Пример изображения по промту');
        return (int) $id;
    }

    /* ---------------------------------------------------------------------
     * Блок ссылок для статей
     * ------------------------------------------------------------------ */

    /** Ссылка на генерацию с уже подставленным промтом. */
    public static function try_url($prompt) {
        return add_query_arg('p', rawurlencode(mb_substr($prompt, 0, 600)), home_url('/neurohub/'));
    }
}
