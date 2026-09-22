<?php
/**
 * Переход в раздел API из статей «как это применить».
 *
 * Такие статьи читают люди с задачей: собрать бота, поставить озвучку в
 * ролики, сделать картинки для магазина. Дочитав, человек уходит делать
 * это руками — хотя ровно те же операции у нас есть по HTTP-запросу, на
 * том же балансе и без своей инфраструктуры. Между «прочитал» и «знает,
 * что это можно вызвать из кода» не хватало одной ссылки.
 *
 * Блок ставится сам, а не вписывается в каждую статью руками: статей
 * такого рода уже за сотню, и ручная вставка означала бы, что половина
 * останется без ссылки, а новые не получат её вовсе.
 *
 * Куда именно он попадает:
 *   — записи из рубрик «Инструкции» и «Заработок на нейросетях»;
 *   — записи, заголовок которых начинается с «Как» — это и есть
 *     «как это применить» по форме;
 *   — и только если в тексте ещё нет ссылки на раздел API: в статьях
 *     самого кластера про API она уже стоит по делу, второй раз незачем.
 *
 * Вставить вручную в конкретную статью: [genius_api]
 */

if (!defined('ABSPATH')) {
    exit;
}

class GS_ApiCta {

    const SHORTCODE = 'genius_api';

    /** Рубрики, где статья почти наверняка про «как сделать». */
    const CATS = array('instrukczii', 'zarabotok-na-neirosetyah');

    public static function boot() {
        add_shortcode(self::SHORTCODE, array(__CLASS__, 'render'));
        // Приоритет ниже перелинковки: блок должен оказаться под текстом,
        // а не между абзацем и блоком «читайте также».
        add_filter('the_content', array(__CLASS__, 'maybe_append'), 20);
    }

    /**
     * Дописать блок к статье, если она из тех, ради которых он задуман.
     */
    public static function maybe_append($content) {
        if (!is_singular('post') || !in_the_loop() || !is_main_query()) {
            return $content;
        }
        if (!self::suits(get_post(), $content)) {
            return $content;
        }
        return $content . self::render();
    }

    /**
     * @param WP_Post|null $post
     * @param string       $content
     */
    private static function suits($post, $content) {
        if (!$post instanceof WP_Post) {
            return false;
        }
        // Ссылка на API уже есть — значит, статья про API и так ведёт куда надо.
        if (strpos($content, self::url()) !== false) {
            return false;
        }
        if (has_category(self::CATS, $post)) {
            return true;
        }
        $title = trim((string) $post->post_title);
        return (bool) preg_match('~^как\b~ui', $title);
    }

    private static function url() {
        return class_exists('GS_Api_Page') ? GS_Api_Page::get_url() : home_url('/api/');
    }

    public static function render() {
        $url = self::url();
        if ($url === '') {
            return '';
        }

        $trial = class_exists('GS_Api_Keys') ? (float) GS_Api_Keys::trial_amount() : 0.0;
        $lead = 'Всё, что описано выше, вызывается и из вашего кода: картинки, звук, '
            . 'озвучка, видео и расшифровка — одним HTTP-запросом, на том же балансе.';
        if ($trial > 0) {
            // Пробные деньги — главный довод попробовать сегодня, и сказать
            // о них надо цифрой, а не словом «бесплатно».
            $lead .= ' На старте на счёт кладётся ' . number_format_i18n($trial) . ' ₽ — хватит проверить без оплаты.';
        }

        ob_start();
        ?>
        <aside class="gs-cbanner gs-cbanner--service">
            <span class="gs-cbanner__spark" aria-hidden="true"></span>
            <div class="gs-cbanner__text">
                <p class="gs-cbanner__title">Готовое решение для вашего старта и заработка на ИИ</p>
                <p class="gs-cbanner__lead"><?php echo esc_html($lead); ?></p>
            </div>
            <a class="gs-cbanner__btn" href="<?php echo esc_url($url); ?>">Смотреть API</a>
        </aside>
        <?php
        return ob_get_clean();
    }
}
