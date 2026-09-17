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

    const DIR  = 'promt';
    const HOOK = 'gs_promt_collect';

    /** Ссылки, которые идут в каждую статью раздела. */
    const TG_CHANNEL = 'https://t.me/promtnanobanana7';
    const TG_BOT     = 'https://t.me/Neuro_HubAI_bot?start=Sv_lana0707';
    const MAX_CHANNEL = 'https://max.ru/join/Ba2dnqkVMbJlRI3BlvIWp95Grn7SzFESG7HxtazTosw';

    public static function boot() {
        add_action('init', array(__CLASS__, 'register_meta'));
        add_action('transition_post_status', array(__CLASS__, 'on_publish'), 10, 3);
        add_action(self::HOOK, array(__CLASS__, 'collect'));
        if (!wp_next_scheduled(self::HOOK)) {
            wp_schedule_event(time() + 120, 'gs_five_minutes', self::HOOK);
        }
        add_filter('cron_schedules', array(__CLASS__, 'add_schedule'));
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
        $prompt = trim((string) get_post_meta($post->ID, self::META_PROMPT, true));
        if ($prompt === '' || get_post_meta($post->ID, self::META_DONE, true)) {
            return;
        }

        $shots = (int) get_post_meta($post->ID, self::META_SHOTS, true);
        $shots = max(1, min(2, $shots ?: 1));

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
            error_log('genius-sounds: пример к статье ' . $post->ID . ' не запущен');
            return;
        }
        update_post_meta($post->ID, self::META_TASKS, $tasks);
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
        $marker = '<!--gs-promt-example-->';
        if (strpos($content, $marker) !== false) {
            $content = str_replace($marker, $figures, $content);
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
