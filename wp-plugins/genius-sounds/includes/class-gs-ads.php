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

    /** Блоки внутри списка звуков и рекламная лента между ними. */
    const OPT_INFEED  = 'gs_ads_infeed';
    const OPT_INFEED2 = 'gs_ads_infeed2';
    const OPT_FEED    = 'gs_ads_feed';

    /**
     * После скольких карточек встают блоки внутри списка.
     *
     * Места выбраны по статистике РСЯ, а не на глаз: блок в списке
     * видели 36–60 % тех, кому его подобрали, а ленту под списком —
     * 10–16 %, потому что до конца страницы доскроливает меньшинство.
     * Поэтому все три блока теперь внутри списка: чем выше блок, тем
     * больше видимых показов, а платят именно за них.
     *
     * Первый блок всё равно не на первом экране: человек пришёл
     * слушать, и реклама в самом верху только прогоняет его обратно
     * в поиск.
     *
     * Числа посчитаны под обычный раздел каталога: в нём 14–26
     * звуков, и места выбраны так, чтобы в самом маленьком разделе
     * последний блок не оказался под последней карточкой — иначе он
     * повторит судьбу ленты.
     */
    const INFEED_AFTER  = 3;
    const FEED_AFTER    = 8;
    const INFEED2_AFTER = 13;

    /** Что уже нарисовано в этом запросе: один номер — один показ. */
    protected static $done = array();

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
            'infeed2'    => self::OPT_INFEED2,
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
     * Второй раз за страницу один и тот же номер не рисуем: РСЯ
     * считает это ошибкой размещения и отдаёт во второй контейнер
     * пустоту. Поэтому лента, не попавшая в короткий список, ставится
     * под ним, а в длинном — внутри списка и под ним уже не дублируется.
     *
     * @param string $slot infeed | infeed2 | feed
     */
    public static function slot($slot) {
        if (!self::allowed()) {
            return '';
        }
        $block = self::block($slot);
        if ($block === '' || isset(self::$done[$block])) {
            return '';
        }
        self::$done[$block] = true;
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
     * Блок, который встаёт после этой карточки списка.
     *
     * Список звуков рисуется одним циклом, и каждому месту отвечает
     * свой номер карточки — так в шаблоне остаётся одна строка, а
     * расстановка целиком живёт здесь, рядом со статистикой, по
     * которой её выбирали.
     *
     * @param int $index номер карточки с нуля
     */
    public static function after_card($index) {
        $places = array(
            self::INFEED_AFTER  - 1 => 'infeed',
            self::FEED_AFTER    - 1 => 'feed',
            self::INFEED2_AFTER - 1 => 'infeed2',
        );
        $index = (int) $index;
        return isset($places[$index]) ? self::slot($places[$index]) : '';
    }

    /**
     * Где показываем.
     *
     * Каталог, разделы каталога и витрина сгенерированных звуков — это
     * и есть страницы скачивания. Вошедшему не показываем вовсе: это
     * ученики и администратор, их клики рекламной сети только вредят.
     */
    /**
     * Можно ли показывать здесь и этому человеку.
     *
     * Вошедшим реклама не показывается: клики владельца и учеников
     * рекламная сеть считает недействительными и штрафует за них
     * площадку. Но посмотреть на размещение владельцу нужно — для
     * этого есть явный запрос «?ads=1»: показ по нему тоже засчитается,
     * поэтому смотреть можно, нажимать нельзя.
     */
    public static function allowed() {
        if (is_admin() || is_feed() || !self::enabled()) {
            return false;
        }
        if (is_user_logged_in() && !self::preview()) {
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
    /** Просмотр размещения владельцем: ?ads=1 на любой странице каталога. */
    public static function preview() {
        return isset($_GET['ads']) && (string) $_GET['ads'] === '1' && current_user_can('manage_options');
    }

    public static function render() {
        if (!self::allowed()) {
            return;
        }
        $full  = self::block('fullscreen');
        $floor = self::block('floor');
        $any = $full !== '' || $floor !== '' || self::block('infeed') !== ''
            || self::block('infeed2') !== '' || self::block('feed') !== '';
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
    function allowed() {
        var m = document.cookie.match(/(?:^|;\s*)cookietrix=([^;]*)/);
        if (!m) {
            return true;
        }
        try {
            var choice = JSON.parse(decodeURIComponent(m[1]));
            if (choice && choice.c && Number(choice.c.marketing) === 0) {
                return false;
            }
        } catch (e) {}
        return true;
    }

    function start() {
        if (!allowed()) {
            return;
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
    }

    /*
     * Ждём, пока страницу действительно увидят.
     *
     * Браузер заранее подгружает верхние результаты поиска: страница
     * скачана, скрипты отработали, человек её не видел и может не
     * открыть вовсе. Если звать рекламу сразу, сеть получает запрос
     * без показа — у нас так набиралось три четверти запросов, а
     * платят только за видимые показы. Поэтому загрузчик создаётся
     * при переходе страницы в видимое состояние: из предзагрузки —
     * по prerenderingchange, из фоновой вкладки — по visibilitychange.
     */
    if (document.prerendering) {
        document.addEventListener('prerenderingchange', start, { once: true });
    } else if (document.visibilityState === 'hidden') {
        document.addEventListener('visibilitychange', function seen() {
            if (document.visibilityState === 'visible') {
                document.removeEventListener('visibilitychange', seen);
                start();
            }
        });
    } else {
        start();
    }
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
            'infeed2'       => self::OPT_INFEED2,
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
