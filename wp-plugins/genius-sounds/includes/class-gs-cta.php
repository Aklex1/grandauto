<?php
/**
 * Переход в микросервис прямо из статьи.
 *
 * Ссылка в тексте работает плохо: тема подчёркивает все ссылки одинаково,
 * и предложение зайти в сервис теряется среди перелинковки. Баннер обучения
 * в начале статьи показал, что кнопку видят и по ней идут, — здесь тот же
 * блок, но ведёт он в нужный сервис.
 *
 * Оформление берём у баннера обучения намеренно: человек уже знает, что
 * этот прямоугольник с кнопкой — переход к делу, а не часть текста.
 * Заводить второй внешний вид ради того же смысла незачем.
 *
 * Применение в статье:
 *   [genius_service id="ytaudio"]
 *   [genius_service id="stt" title="Своя строка" btn="Свой текст кнопки"]
 */

if (!defined('ABSPATH')) {
    exit;
}

class GS_CTA {

    const SHORTCODE = 'genius_service';

    public static function boot() {
        add_shortcode(self::SHORTCODE, array(__CLASS__, 'render'));
    }

    public static function render($atts = array()) {
        $atts = shortcode_atts(array(
            'id'    => '',
            'title' => '',
            'lead'  => '',
            'btn'   => '',
        ), is_array($atts) ? $atts : array(), self::SHORTCODE);

        if (!class_exists('GS_Lab')) {
            return '';
        }
        $service = GS_Lab::get_service((string) $atts['id']);
        if (!$service || !GS_Lab::is_available($service['id'])) {
            // Выключенный сервис не рекламируем: человек перейдёт в пустоту.
            return '';
        }

        $url = GS_Lab::get_url($service['id']);
        if ($url === '') {
            return '';
        }

        $title = $atts['title'] !== '' ? $atts['title'] : (string) $service['menu'];
        $lead  = $atts['lead']  !== '' ? $atts['lead']  : (string) $service['lead'];
        $btn   = $atts['btn']   !== '' ? $atts['btn']   : 'Открыть сервис';

        // Бесплатность — главный довод перейти, и говорить о ней надо на
        // кнопке, а не мелким текстом под ней.
        $free = GS_Lab::price($service['id'], 0) <= 0;
        if ($atts['lead'] === '' && $free) {
            $lead = rtrim($lead, ' .') . '. Бесплатно и без регистрации.';
        }

        ob_start();
        ?>
        <aside class="gs-cbanner gs-cbanner--service">
            <span class="gs-cbanner__spark" aria-hidden="true"></span>
            <div class="gs-cbanner__text">
                <p class="gs-cbanner__title"><?php echo esc_html($title); ?></p>
                <p class="gs-cbanner__lead"><?php echo esc_html($lead); ?></p>
            </div>
            <a class="gs-cbanner__btn" href="<?php echo esc_url($url); ?>"><?php echo esc_html($btn); ?></a>
        </aside>
        <?php
        return ob_get_clean();
    }
}
