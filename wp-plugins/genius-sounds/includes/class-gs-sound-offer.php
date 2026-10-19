<?php
/**
 * Оффер «сделайте свой звук» на страницах каталога.
 *
 * В каталог из поиска приходит 5740 визитов в месяц — «звуки из мемов»,
 * «пак голос девушки», «аниме звуки». Ссылки на генератор на страницах
 * есть, но лежат под длинным списком звуков: глубина визита 1,48, и в
 * генератор из поиска уходят два человека в месяц. Человек получает файл
 * и закрывает вкладку, не доскроллив до предложения.
 *
 * Поэтому предложение приходит к нему само — но только после того, как он
 * получил то, за чем пришёл: сразу по окончании проигранного звука. Раньше
 * показывать нельзя, это бьёт по отказам, а отказы и удовлетворённость
 * последним кликом — то, на чём держится ранжирование коммерческих
 * страниц.
 */

if (!defined('ABSPATH')) {
    exit;
}

class GS_Sound_Offer {

    /** Сколько дней не показывать тому, кто закрыл. */
    const HOLD_DAYS = 7;

    /** Запас после окончания звука, чтобы карточка не прыгала в тишину. */
    const DELAY_MS = 1200;

    /** Если звук так и не включили: показ по времени, с условием прокрутки. */
    const IDLE_MS = 45000;

    public static function boot() {
        // Приоритет ниже, чем у печати скриптов подвала (она на 20):
        // иначе разметка появляется после своего же скрипта.
        add_action('wp_footer', array(__CLASS__, 'render'), 5);
    }

    /** Показываем только гостю: вошедший уже пользуется сервисом. */
    public static function wanted() {
        if (is_user_logged_in() || is_admin()) {
            return false;
        }
        return class_exists('GS_Catalog')
            && (GS_Catalog::is_catalog_request()
                || (class_exists('GS_Pages') && GS_Pages::is_showcase_request()));
    }

    public static function enqueue() {
        if (!self::wanted()) {
            return;
        }
        wp_enqueue_style('genius-sounds-offer', GS_PLUGIN_URL . 'assets/css/sound-offer.css', array(), GS_VERSION);
        wp_enqueue_script('genius-sounds-offer', GS_PLUGIN_URL . 'assets/js/sound-offer.js', array(), GS_VERSION, true);
        wp_localize_script('genius-sounds-offer', 'gsOffer', array(
            'delay'   => self::DELAY_MS,
            'idle'    => self::IDLE_MS,
            'hold'    => self::HOLD_DAYS,
            'counter' => class_exists('GS_Metrika') ? (int) GS_Metrika::counter() : 0,
            'goalShow'  => 'offer_show',
            'goalClick' => 'offer_click',
        ));
    }

    /**
     * Разметка лежит в странице сразу, а показ решает скрипт.
     *
     * Иначе её пришлось бы собирать на лету, а страница отдаётся из кэша
     * одна на всех гостей — вставить туда разное всё равно не получится.
     */
    public static function render() {
        if (!self::wanted()) {
            return;
        }
        $url = class_exists('GS_Pages') ? GS_Pages::get_studio_url() : home_url('/sound-generator/');
        ?>
        <div class="gs-offer" id="gs-offer" hidden>
            <div class="gs-offer__veil" data-gs-offer-close></div>
            <div class="gs-offer__card" role="dialog" aria-modal="true" aria-labelledby="gs-offer-title">
                <button class="gs-offer__close" type="button" aria-label="Закрыть" data-gs-offer-close>
                    <svg width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true">
                        <path d="M3.5 3.5l9 9M12.5 3.5l-9 9" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
                    </svg>
                </button>

                <span class="gs-offer__badge">
                    <svg width="13" height="13" viewBox="0 0 16 16" fill="none" aria-hidden="true">
                        <path d="M8 1.5l1.7 4.3 4.6.3-3.5 3 1.1 4.5L8 11.2l-3.9 2.4 1.1-4.5-3.5-3 4.6-.3z" stroke="currentColor" stroke-width="1.3" stroke-linejoin="round"/>
                    </svg>
                    Свой звук за полминуты
                </span>

                <h2 class="gs-offer__title" id="gs-offer-title">Не нашли нужный? Сделайте свой.</h2>

                <p class="gs-offer__lead">Опишите звук словами — нейросеть соберёт его под ваш кадр.
                    <strong>Первый звук на 5 секунд — бесплатно, без регистрации.</strong></p>

                <ul class="gs-offer__list">
                    <li>Длина от 1 до 30 секунд — ровно столько, сколько нужно сцене</li>
                    <li>Готовый MP3 без авторских прав — в ролик, игру или стрим</li>
                    <li>Дальше 9 ₽ за звук, без подписки и абонентской платы</li>
                </ul>

                <a class="gs-offer__go" href="<?php echo esc_url($url); ?>" data-gs-offer-go>
                    Сделать свой звук бесплатно
                    <svg width="17" height="17" viewBox="0 0 16 16" fill="none" aria-hidden="true">
                        <path d="M3 8h9.5M9 4.5L12.5 8 9 11.5" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </a>

                <p class="gs-offer__fine">Без карты и регистрации. Одна бесплатная проба на человека.</p>
            </div>
        </div>
        <?php
    }
}
