<?php
/**
 * Оформление статей блога фирменным набором.
 *
 * Статей больше трёхсот, и переписывать каждую руками — значит потом
 * обновлять их все при каждой правке оформления. Поэтому украшаем на
 * выводе: в базе остаётся чистый текст, а иконки, подпись и врезка
 * появляются при отрисовке. Снимается это одной строкой и не ломает
 * содержимое.
 *
 * Что добавляется:
 *   — подпись автора со временем чтения и персонажем в начале;
 *   — иконка к каждому заголовку раздела, подобранная по смыслу;
 *   — иконки в коротких списках: они читаются как чек-лист;
 *   — врезка с призывом в конце, ведущая туда же, куда липкая панель.
 *
 * Иконки подбираются по словам заголовка. Это грубее ручной разметки, но
 * честнее случайного набора: если слово не узналось, ставится нейтральный
 * документ, а не что попало.
 */

if (!defined('ABSPATH')) {
    exit;
}

class GS_Article_Style {

    /** Слово в заголовке => иконка набора. Порядок важен: ищем по очереди. */
    const ICONS = array(
        'цен'          => 'card',
        'стоим'        => 'card',
        'тариф'        => 'card',
        'оплат'        => 'card',
        'деньг'        => 'card',
        'заработ'      => 'growth',
        'доход'        => 'growth',
        'рост'         => 'growth',
        'срок'         => 'clock',
        'когда'        => 'clock',
        'время'        => 'clock',
        'быстр'        => 'speed',
        'ошибк'        => 'shield',
        'риск'         => 'shield',
        'защит'        => 'shield',
        'безопас'      => 'lock',
        'доступ'       => 'lock',
        'шаг'          => 'flow',
        'инструкц'     => 'flow',
        'как '         => 'flow',
        'настро'       => 'flow',
        'чек-лист'     => 'check',
        'итог'         => 'check',
        'что нужно'    => 'check',
        'что понадоб'  => 'check',
        'вопрос'       => 'chat',
        'ответ'        => 'chat',
        'поддержк'     => 'support',
        'код'          => 'code',
        'api'          => 'code',
        'запрос'       => 'code',
        'бот'          => 'bot',
        'телеграм'     => 'send',
        'канал'        => 'send',
        'нейросет'     => 'ai',
        'модел'        => 'ai',
        'клиент'       => 'users',
        'заказ'        => 'users',
        'сервер'       => 'db',
        'база'         => 'db',
        'запуск'       => 'rocket',
        'старт'        => 'rocket',
        'цел'          => 'target',
        'иде'          => 'idea',
        'пример'       => 'doc',
        'документ'     => 'doc',
        'суд'          => 'doc',
        'заявлен'      => 'doc',
        'урок'         => 'doc',
        'план'         => 'calendar',
        'расписан'     => 'calendar',
        'уведомл'      => 'bell',
        'интеграц'     => 'plug',
        'подключ'      => 'plug',
    );

    public static function boot() {
        add_filter('the_content', array(__CLASS__, 'decorate'), 20);
    }

    public static function decorate($html) {
        if (!class_exists('GS_Brand') || !is_singular('post')
            || !in_the_loop() || !is_main_query()) {
            return $html;
        }
        $html = (string) $html;
        if (trim($html) === '') {
            return $html;
        }

        return self::byline($html) . self::heading_icons(self::list_icons($html))
            . self::cta() . self::related();
    }

    /* ---------------------------------------------------------------------
     * Части
     * ------------------------------------------------------------------ */

    /** Подпись со временем чтения: 180 слов в минуту — привычный ориентир. */
    private static function byline($html) {
        $words = str_word_count(wp_strip_all_tags($html), 0, 'абвгдеёжзийклмнопрстуфхцчшщъыьэюяАБВГДЕЁЖЗИЙКЛМНОПРСТУФХЦЧШЩЪЫЬЭЮЯ');
        $minutes = max(1, (int) round($words / 180));
        $avatar = GS_Brand::mascot_tag('avatar', 'gs-pixel gs-byline__avatar', 40);
        if ($avatar === '') {
            return '';
        }
        return '<p class="gs-byline">' . $avatar
            . '<span>Genius-bot · ' . $minutes . ' ' . self::minutes_word($minutes)
            . ' чтения</span></p>';
    }

    private static function minutes_word($n) {
        $n = abs((int) $n) % 100;
        $tail = $n % 10;
        if ($n > 10 && $n < 20) {
            return 'минут';
        }
        if ($tail === 1) {
            return 'минута';
        }
        if ($tail >= 2 && $tail <= 4) {
            return 'минуты';
        }
        return 'минут';
    }

    /** Иконка к заголовку раздела. */
    private static function heading_icons($html) {
        return preg_replace_callback('~<h2([^>]*)>(.*?)</h2>~us', function ($m) {
            if (strpos($m[1], 'gb-h-icon') !== false) {
                return $m[0];
            }
            $icon = self::icon_for(wp_strip_all_tags($m[2]));
            $attrs = self::add_class($m[1], 'gb-h-icon');
            return '<h2' . $attrs . '>' . GS_Brand::icon_tag($icon) . $m[2] . '</h2>';
        }, $html);
    }

    /**
     * Иконки в списках.
     *
     * Только там, где список читается как перечень, а не как абзацы с
     * дефисами: длинные пункты с иконкой превращаются в кашу.
     */
    private static function list_icons($html) {
        return preg_replace_callback('~<ul([^>]*)>(.*?)</ul>~us', function ($m) {
            if (strpos($m[1], 'gb-list') !== false) {
                return $m[0];
            }
            preg_match_all('~<li([^>]*)>(.*?)</li>~us', $m[2], $items, PREG_SET_ORDER);
            if (count($items) < 2 || count($items) > 8) {
                return $m[0];
            }
            foreach ($items as $item) {
                if (mb_strlen(wp_strip_all_tags($item[2])) > 110) {
                    return $m[0];
                }
            }
            // Иконка у списка одна на все пункты. Раньше её подбирали
            // каждому пункту отдельно, и в перечне однородных строк
            // получался ряд из робота, галочки и документа — пестрит и
            // ничего не значит. Берём ту, что подошла первому пункту, у
            // которого нашлось слово-примета.
            $icon = 'check';
            foreach ($items as $item) {
                $found = self::icon_for(wp_strip_all_tags($item[2]), '');
                if ($found !== '') {
                    $icon = $found;
                    break;
                }
            }
            $inner = '';
            foreach ($items as $item) {
                $inner .= '<li' . $item[1] . '>'
                    . GS_Brand::icon_tag($icon, 'soft', 'gb-icon--sm')
                    . '<span>' . $item[2] . '</span></li>';
            }
            return '<ul' . self::add_class($m[1], 'gb-list') . '>' . $inner . '</ul>';
        }, $html);
    }

    /** Врезка с призывом: ведёт туда же, куда липкая панель этой статьи. */
    private static function cta() {
        if (!class_exists('GS_Sticky')) {
            return '';
        }
        $target = GS_Sticky::target();
        if (!is_array($target) || empty($target['url'])) {
            return '';
        }
        // В документных кластерах персонажа не показываем: рядом с иском и
        // характеристикой на ученика он выглядит неуместно. В медийных
        // сервисах — озвучка, музыка, видео — он остаётся.
        $doc = class_exists('GS_Legal') && GS_Legal::is_doc_post();
        $pixel = $doc ? '' : GS_Brand::mascot_tag('ukazyvaet', 'gs-pixel', 64);
        return '<div class="gb-callout gb-callout--cta gs-article-cta">' . $pixel
            . '<div class="gs-article-cta__text">'
            . '<span class="gb-callout__title">' . esc_html($target['title']) . '</span>'
            . esc_html($target['text']) . '</div>'
            . '<a class="gb-btn" href="' . esc_url($target['url']) . '">'
            . esc_html($target['cta'] ?? 'Открыть') . '</a></div>';
    }

    /**
     * «Читайте дальше»: три статьи того же кластера.
     *
     * Стоит на месте кнопок «поделиться», которые тема рисует после статьи.
     * Кнопки не работали: десять сетей подряд в вертикальной колонке никто
     * не нажимает, а место они занимали экраном. Ссылки на соседние статьи
     * в том же разборе и человеку полезнее, и кластеру.
     */
    private static function related() {
        $post = get_queried_object();
        if (!($post instanceof WP_Post)) {
            return '';
        }

        $args = array(
            'post_type'           => 'post',
            'post_status'         => 'publish',
            'posts_per_page'      => 3,
            'post__not_in'        => array($post->ID),
            'ignore_sticky_posts' => true,
            'no_found_rows'       => true,
            'orderby'             => 'date',
            'order'               => 'DESC',
        );

        // Сначала ищем соседей по кластеру: у статей потока публикаций общая
        // тема, и переход внутри неё осмысленнее, чем в случайную рубрику.
        $lane = (string) get_post_meta($post->ID, '_gs_queue_lane', true);
        $found = array();
        if ($lane !== '') {
            $found = get_posts($args + array('meta_key' => '_gs_queue_lane', 'meta_value' => $lane));
        }
        if (count($found) < 3) {
            $cats = wp_get_post_categories($post->ID);
            if ($cats) {
                $more = get_posts($args + array('category__in' => $cats,
                                                'posts_per_page' => 3 - count($found)));
                $seen = wp_list_pluck($found, 'ID');
                foreach ($more as $item) {
                    if (!in_array($item->ID, $seen, true)) {
                        $found[] = $item;
                    }
                }
            }
        }
        if (!$found) {
            return '';
        }

        $out = '<section class="gs-related"><h2 class="gb-h-icon">'
            . GS_Brand::icon_tag('doc') . 'Читайте дальше</h2><ul class="gs-related__list">';
        foreach (array_slice($found, 0, 3) as $item) {
            $out .= '<li><a href="' . esc_url(get_permalink($item)) . '">'
                . GS_Brand::icon_tag(self::icon_for($item->post_title), 'soft', 'gb-icon--sm')
                . '<span>' . esc_html(get_the_title($item)) . '</span></a></li>';
        }
        return $out . '</ul></section>';
    }

    /* ---------------------------------------------------------------------
     * Мелочи
     * ------------------------------------------------------------------ */

    private static function icon_for($text, $default = 'doc') {
        $text = mb_strtolower(trim((string) $text));
        foreach (self::ICONS as $word => $icon) {
            if (mb_strpos($text, $word) !== false) {
                return $icon;
            }
        }
        return $default;
    }

    /** Добавляем класс, не потеряв тот, что уже стоял в разметке. */
    private static function add_class($attrs, $class) {
        if (preg_match('~class="([^"]*)"~', $attrs)) {
            return preg_replace('~class="([^"]*)"~', 'class="$1 ' . $class . '"', $attrs);
        }
        return $attrs . ' class="' . $class . '"';
    }
}
