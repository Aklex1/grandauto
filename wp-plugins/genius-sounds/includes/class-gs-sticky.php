<?php
/**
 * Липкая панель со ссылкой на сервис — в статьях блога.
 *
 * Врезка в начале статьи уходит за верхний край экрана через два абзаца, а
 * блок в конце видят только дочитавшие. Панель решает обе беды: она
 * появляется, когда человек начал читать всерьёз, и дальше едет вместе с
 * ним.
 *
 * Куда вести, решает сама статья. Материалы подарочного кластера ведут на
 * посадочную «Песня в подарок», остальные — на тот микросервис, ссылка на
 * который в тексте уже стоит. Второе важнее, чем кажется: тридцать статей
 * про транскрибацию не должны звать в подарки, а статья про удаление
 * вокала — вести именно туда, где вокал и удаляют.
 *
 * Панель закрывается крестиком и после этого не показывается сутки: реклама,
 * которую нельзя убрать, раздражает сильнее, чем помогает.
 */

if (!defined('ABSPATH')) {
    exit;
}

class GS_Sticky {

    /** После скольких пикселей прокрутки показывать. */
    const AFTER_PX = 600;

    public static function boot() {
        // Раньше скриптов подвала: они печатаются на двадцатом приоритете, и
        // панель, выведенная после них, скрипту уже не видна — он ищет её в
        // момент разбора страницы и молча выходит.
        add_action('wp_footer', array(__CLASS__, 'render'), 5);
    }

    /** Нужна ли панель на этой странице. */
    public static function needed() {
        return !is_admin() && is_singular('post') && self::target() !== null;
    }

    /**
     * Куда ведём с этой статьи.
     *
     * @return array{url:string,title:string,text:string,cta:string}|null
     */
    public static function target() {
        $post = get_queried_object();
        if (!($post instanceof WP_Post) || $post->post_type !== 'post') {
            return null;
        }

        // Подарочный кластер: у его статей своя метка потока публикаций.
        if (class_exists('GS_Gift')
            && (string) get_post_meta($post->ID, '_gs_queue_lane', true) === GS_Gift::LANE) {
            return array(
                'url'   => GS_Gift::get_url(GS_Gift::root()),
                'title' => 'Песня в подарок',
                'text'  => 'Анкета про человека — текст сразу и бесплатно, песня через десять минут. От '
                           . (int) GS_Gift::PRICE_SONG . ' ₽.',
                'cta'   => 'Заполнить анкету',
            );
        }

        return self::service_from_content((string) $post->post_content);
    }

    /**
     * Сервис, ссылка на который стоит в тексте статьи.
     *
     * Берём первый по порядку появления, а не первый по списку сервисов:
     * автор статьи ставит главную ссылку раньше сопутствующих, и это лучшая
     * подсказка о том, ради чего статья написана.
     */
    private static function service_from_content($content) {
        if (!class_exists('GS_Lab') || $content === '') {
            return null;
        }
        $best = null;
        $at = PHP_INT_MAX;

        foreach (GS_Lab::available_services() as $service) {
            $slug = isset($service['slug']) ? (string) $service['slug'] : '';
            if ($slug === '') {
                continue;
            }
            $pos = strpos($content, '/' . $slug . '/');
            if ($pos !== false && $pos < $at) {
                $at = $pos;
                $best = $service;
            }
        }
        if (!$best) {
            return null;
        }

        $lead = (string) ($best['lead'] ?? '');
        if (mb_strlen($lead) > 120) {
            $lead = rtrim(mb_substr($lead, 0, 117), " ,.;:—-") . '…';
        }
        return array(
            'url'   => GS_Lab::get_url($best['id']),
            'title' => (string) ($best['menu'] ?? 'Инструмент'),
            'text'  => $lead,
            'cta'   => 'Открыть',
        );
    }

    public static function render() {
        if (!self::needed()) {
            return;
        }
        $target = self::target();
        $key = 'gs-sticky-' . sanitize_title($target['title']);
        ?>
        <aside class="gs-sticky" id="gs-sticky" data-gs-sticky-key="<?php echo esc_attr($key); ?>"
               data-gs-sticky-after="<?php echo (int) self::AFTER_PX; ?>" hidden>
            <div class="gs-sticky__in">
                <div class="gs-sticky__text">
                    <strong><?php echo esc_html($target['title']); ?></strong>
                    <span><?php echo esc_html($target['text']); ?></span>
                </div>
                <a class="gs-sticky__go" href="<?php echo esc_url($target['url']); ?>">
                    <?php echo esc_html($target['cta']); ?>
                </a>
                <button type="button" class="gs-sticky__x" aria-label="Скрыть">&times;</button>
            </div>
        </aside>
        <?php
    }
}
