<?php
/**
 * Оплата, сроки и возврат — одним блоком на страницах сервисов.
 *
 * Яндекс сравнивает продавцов по набору признаков: видна ли цена, как
 * платить, что будет, если услуга не оказана, есть ли документы и живые
 * контакты. Цена и документы на страницах были, а вот про возврат не
 * говорилось нигде, хотя правило есть и работает: при неудачной генерации
 * списанное возвращается на баланс автоматически (см. GS_Lab_Jobs).
 *
 * Поэтому пишем прямо то, что и так происходит. Выдумывать гарантии,
 * которых нет, нельзя: обещание «вернём деньги по заявлению» без
 * механизма — обман и покупателя, и поиска.
 *
 * Здесь же исправлена давняя неточность: в строке внизу страниц сервисов
 * стояло «услуги оказывает ИП». В самой оферте исполнитель — самозанятый
 * на НПД, и «ИП» там было выдумкой.
 */

if (!defined('ABSPATH')) {
    exit;
}

class GS_Terms {

    public static function boot() {
        add_action('wp_enqueue_scripts', array(__CLASS__, 'assets'), 25);
    }

    public static function assets() {
        // Стили лежат рядом со стилями сервисов: блок показывается там же.
        if (wp_style_is('genius-sounds-lab', 'enqueued') || wp_style_is('genius-sounds-catalog', 'enqueued')) {
            wp_enqueue_style('genius-sounds-terms', GS_PLUGIN_URL . 'assets/css/terms.css', array(), GS_VERSION);
        }
    }

    /**
     * @param string $kind 'lab' — сервис с оплатой за запуск,
     *                     'free' — бесплатный инструмент.
     */
    public static function render($kind = 'lab') {
        $docs = array();
        if (class_exists('GS_Requisites')) {
            $oferta  = GS_Requisites::oferta_url();
            $privacy = GS_Requisites::privacy_url();
            if ($oferta !== '') {
                $docs['оферта'] = $oferta;
            }
            if ($privacy !== '') {
                $docs['политика обработки данных'] = $privacy;
            }
            $order = get_page_by_path('poryadok-okazaniya-uslug');
            if ($order && $order->post_status === 'publish') {
                $docs['порядок оказания услуг'] = get_permalink($order);
            }
            $docs['реквизиты'] = GS_Requisites::get_url();
        }

        ob_start();
        ?>
        <section class="gs-terms" aria-label="Оплата и возврат">
            <h2 class="gs-section-title">Оплата и возврат</h2>
            <ul class="gs-terms__list">
                <?php if ($kind === 'free'): ?>
                    <li><b>Бесплатно.</b> Инструмент работает без оплаты и без подписки: ни карту, ни баланс привязывать не нужно.</li>
                <?php else: ?>
                    <li><b>Платите за запуск, не за месяц.</b> Абонентской платы нет: деньги списываются с баланса за каждую генерацию, неизрасходованное остаётся на счёте.</li>
                    <li><b>Не получилось — деньги возвращаются.</b> Если генерация не удалась или не дошла до результата, списанное возвращается на баланс автоматически, писать заявление не нужно.</li>
                <?php endif; ?>
                <?php if ($kind === 'free'): ?>
                    <li><b>Результат сразу у вас.</b> Готовый файл скачивается прямо со страницы, ничего ждать и никуда писать не нужно.</li>
                <?php else: ?>
                    <li><b>Результат остаётся у вас.</b> Готовый файл можно скачать, он хранится в истории вашего кабинета.</li>
                <?php endif; ?>
                <?php if ($docs): ?>
                    <?php
                    $links = array();
                    foreach ($docs as $name => $url) {
                        $links[] = '<a href="' . esc_url($url) . '">' . esc_html($name) . '</a>';
                    }
                    ?>
                    <li><b>Документы.</b> <?php echo implode(', ', $links); // phpcs:ignore WordPress.Security.EscapeOutput ?>.</li>
                <?php endif; ?>
            </ul>
        </section>
        <?php
        return (string) ob_get_clean();
    }
}
