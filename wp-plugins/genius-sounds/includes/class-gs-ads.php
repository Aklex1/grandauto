<?php
/**
 * Реклама РСЯ на страницах каталога звуков.
 *
 * Каталог — это трафик, который ничего не продаёт: человек пришёл
 * скачать звук, скачал и ушёл. Реклама здесь и есть способ окупить
 * хостинг и генерации, поэтому она ставится именно там, где ничего не
 * продаётся, и не ставится там, где человек оформляет заказ или учится:
 * на лендингах услуг, в кабинете и на оплате рекламный блок стоит
 * дороже, чем приносит.
 *
 * Номер блока — настройка, а не константа: блоки в РСЯ пересоздают,
 * и выкатывать ради этого плагин незачем. Пока номер не задан, на
 * страницы не выводится ничего — ни скрипта, ни пустого места.
 */

if (!defined('ABSPATH')) {
    exit;
}

class GS_Ads {

    /** Полноэкранный блок: у РСЯ он свой для телефона и для компьютера. */
    const OPT_TOUCH   = 'gs_ads_fullscreen_touch';
    const OPT_DESKTOP = 'gs_ads_fullscreen_desktop';
    const OPT_ON      = 'gs_ads_on';

    /** Прилипающий баннер внизу экрана — тоже по платформам. */
    const OPT_FLOOR_TOUCH   = 'gs_ads_floor_touch';
    const OPT_FLOOR_DESKTOP = 'gs_ads_floor_desktop';

    /** Блок внутри списка звуков и рекламная лента под ним. */
    const OPT_INFEED = 'gs_ads_infeed';
    const OPT_FEED   = 'gs_ads_feed';

    /** После скольких карточек вставляем блок в списке звуков. */
    const INFEED_AFTER = 6;

    public static function boot() {
        add_action('wp_footer', array(__CLASS__, 'render'), 20);
        add_action('admin_post_gs_ads_save', array(__CLASS__, 'handle_save'));
    }

    public static function handle_save() {
        if (!current_user_can('manage_options')) {
            wp_die('Недостаточно прав');
        }
        check_admin_referer('gs_ads_save');
        $values = array('on' => !empty($_POST['on']));
        foreach (array_keys(self::fields()) as $name) {
            $values[$name] = isset($_POST[$name]) ? sanitize_text_field(wp_unslash($_POST[$name])) : '';
        }
        self::save($values);
        wp_safe_redirect(admin_url('admin.php?page=genius-sounds') . '#gs-ads');
        exit;
    }

    public static function enabled() {
        return (int) get_option(self::OPT_ON, 1) === 1;
    }

    /**
     * Номер блока по его месту.
     *
     * Номер у РСЯ выглядит как R-A-123456-7: всё остальное — чья-то
     * опечатка, и лучше не выводить ничего, чем пустой блок.
     */
    public static function block($slot) {
        $touch = wp_is_mobile();
        $map = array(
            'fullscreen' => $touch ? self::OPT_TOUCH : self::OPT_DESKTOP,
            'floor'      => $touch ? self::OPT_FLOOR_TOUCH : self::OPT_FLOOR_DESKTOP,
            'infeed'     => self::OPT_INFEED,
            'feed'       => self::OPT_FEED,
        );
        if (!isset($map[$slot])) {
            return '';
        }
        $id = trim((string) get_option($map[$slot], ''));
        return preg_match('~^R-A-[0-9]+-[0-9]+$~', $id) ? $id : '';
    }

    /**
     * Место под блок прямо в содержимом страницы.
     *
     * Лента и блок внутри списка рисуются в свой контейнер, а не поверх
     * страницы: Ya.Context нужно сказать, куда именно ставить. Пока
     * номер не задан, не выводим и контейнер — пустая рамка в списке
     * звуков выглядит как поломка вёрстки.
     *
     * @param string $slot infeed | feed
     */
    public static function slot($slot) {
        if (!self::allowed()) {
            return '';
        }
        $block = self::block($slot);
        if ($block === '') {
            return '';
        }
        $id = 'yandex_rtb_' . preg_replace('~[^A-Za-z0-9_-]~', '', $block);
        $method = $slot === 'feed' ? 'renderFeed' : 'render';
        ob_start();
        ?>
        <div class="gs-ad gs-ad--<?php echo esc_attr($slot); ?>" id="<?php echo esc_attr($id); ?>"></div>
        <script data-cookietrix-skip>
        window.yaContextCb = window.yaContextCb || [];
        window.yaContextCb.push(function () {
            Ya.Context.AdvManager.<?php echo esc_js($method); ?>({
                blockId: <?php echo wp_json_encode($block); ?>,
                renderTo: <?php echo wp_json_encode($id); ?>
            });
        });
        </script>
        <?php
        return ob_get_clean();
    }

    /**
     * Где показываем.
     *
     * Каталог, разделы каталога и витрина сгенерированных звуков — это
     * и есть страницы скачивания. Вошедшему не показываем вовсе: это
     * ученики и администратор, их клики рекламной сети только вредят.
     */
    public static function allowed() {
        if (is_admin() || is_feed() || is_user_logged_in() || !self::enabled()) {
            return false;
        }
        if (class_exists('GS_Catalog') && GS_Catalog::is_catalog_request()) {
            return true;
        }
        if (class_exists('GS_Pages') && GS_Pages::is_showcase_request()) {
            return true;
        }
        return false;
    }

    /**
     * Загрузчик сети и блоки поверх страницы.
     *
     * Скрипт РСЯ нужен всем блокам, в том числе тем, что нарисованы
     * внутри страницы, — поэтому печатаем его, если задан хоть один
     * номер, а не только полноэкранный.
     */
    public static function render() {
        if (!self::allowed()) {
            return;
        }
        $full  = self::block('fullscreen');
        $floor = self::block('floor');
        $any = $full !== '' || $floor !== '' || self::block('infeed') !== '' || self::block('feed') !== '';
        if (!$any) {
            return;
        }
        $platform = wp_is_mobile() ? 'touch' : 'desktop';
        /*
         * Загрузчик создаём скриптом, а не тегом в разметке.
         *
         * Баннер согласия подменяет обычные теги на «text/plain» и
         * держит их до нажатия — реклама тогда не показывается почти
         * никому: до кнопки доходит меньшинство. Тег, собранный в
         * браузере, под эту подмену не попадает, и на всякий случай
         * помечен штатной оговоркой самого баннера.
         *
         * Явный отказ при этом уважаем: если человек нажал «только
         * необходимые», рекламы нет. Решение читается в браузере, а не
         * на сервере, иначе страница из кэша раздавала бы всем ответ
         * одного посетителя.
         */
        ?>
<script data-cookietrix-skip>
(function () {
    var m = document.cookie.match(/(?:^|;\s*)cookietrix=([^;]*)/);
    if (m) {
        try {
            var choice = JSON.parse(decodeURIComponent(m[1]));
            if (choice && choice.c && Number(choice.c.marketing) === 0) {
                return;
            }
        } catch (e) {}
    }
    window.yaContextCb = window.yaContextCb || [];
    var loader = document.createElement('script');
    loader.src = 'https://yandex.ru/ads/system/context.js';
    loader.async = true;
    loader.setAttribute('data-cookietrix-skip', '');
    document.head.appendChild(loader);
        <?php if ($full !== '') : ?>
    window.yaContextCb.push(function () {
        Ya.Context.AdvManager.render({
            blockId: <?php echo wp_json_encode($full); ?>,
            type: 'fullscreen',
            platform: <?php echo wp_json_encode($platform); ?>
        });
    });
        <?php endif; ?>
        <?php if ($floor !== '') : ?>
    window.yaContextCb.push(function () {
        Ya.Context.AdvManager.render({
            blockId: <?php echo wp_json_encode($floor); ?>,
            type: 'floorAd',
            platform: <?php echo wp_json_encode($platform); ?>
        });
    });
        <?php endif; ?>
})();
</script>
        <?php
    }

    /** Поля настроек: имя в форме → опция. */
    public static function fields() {
        return array(
            'touch'         => self::OPT_TOUCH,
            'desktop'       => self::OPT_DESKTOP,
            'floor_touch'   => self::OPT_FLOOR_TOUCH,
            'floor_desktop' => self::OPT_FLOOR_DESKTOP,
            'infeed'        => self::OPT_INFEED,
            'feed'          => self::OPT_FEED,
        );
    }

    /** Сохранение настроек из админки. */
    public static function save($values) {
        $out = array();
        foreach (self::fields() as $name => $option) {
            $raw = trim((string) ($values[$name] ?? ''));
            $clean = preg_match('~^R-A-[0-9]+-[0-9]+$~', $raw) ? $raw : '';
            update_option($option, $clean, false);
            $out[$name] = $clean;
        }
        update_option(self::OPT_ON, empty($values['on']) ? 0 : 1, false);
        $out['on'] = self::enabled();
        return $out;
    }
}
