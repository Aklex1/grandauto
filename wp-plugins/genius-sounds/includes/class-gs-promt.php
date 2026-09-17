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
    const MAX_TRIES   = 3;

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
            } elseif ((int) get_post_meta($post->ID, self::META_TRIES, true) >= self::MAX_TRIES) {
                $stuck++;
            } else {
                $waiting++;
            }
        }

        return array(
            'waiting' => $waiting,
            'done'    => $done,
            'stuck'   => $stuck,
            'next'    => wp_next_scheduled(self::HOOK),
        );
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
        foreach (array(self::META_PROMPT => 'string', self::META_SHOTS => 'integer') as $key => $type) {
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

        update_post_meta($post->ID, self::META_TRIES,
            (int) get_post_meta($post->ID, self::META_TRIES, true) + 1);

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
            $why = isset($res['error']) ? (string) $res['error'] : 'причина неизвестна';
            error_log('genius-sounds: пример к статье ' . $post->ID . ' не запущен: ' . $why);
            update_post_meta($post->ID, self::META_ERROR, $why);
            return false;
        }
        delete_post_meta($post->ID, self::META_ERROR);
        update_post_meta($post->ID, self::META_TASKS, $tasks);
        return true;
    }

    /**
     * Статьи, вышедшие без примера, — добираем по нескольку за прогон.
     *
     * Ограничение в три штуки намеренное: поставщик берёт задачи не
     * бесплатно, и разом запускать полсотни генераций из-за одного
     * сбоя не нужно.
     */
    private static function start_missing($limit = 3) {
        $posts = get_posts(array(
            'post_type'        => 'post',
            'post_status'      => 'publish',
            'numberposts'      => (int) $limit * 4,
            'suppress_filters' => true,
            'meta_query'       => array(
                array('key' => self::META_PROMPT, 'compare' => 'EXISTS'),
                array('key' => self::META_TASKS, 'compare' => 'NOT EXISTS'),
            ),
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
            if ((int) get_post_meta($post->ID, self::META_TRIES, true) >= self::MAX_TRIES) {
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
        self::start_missing();

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
            // не должна держать статью без картинки навсегда.
            $stuck = (time() - get_post_time('U', true, $post)) > HOUR_IN_SECONDS;
            if ($waiting && !$stuck) {
                continue;
            }

            if ($urls) {
                self::attach($post, $urls);
            }
            delete_post_meta($post->ID, self::META_TASKS);
            update_post_meta($post->ID, self::META_DONE, 1);
        }
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
