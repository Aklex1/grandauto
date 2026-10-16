<?php
/**
 * Страница заявки на консультацию.
 *
 * Главная кнопка в шапке — «Заказать консультацию» — вела на адрес
 * удалённого товара магазина: /product/konsultacziya-po-razrabotke-chat-bota/.
 * Оттуда человек через редирект попадал на /solutions/, где лежит статья
 * про профессию разработчика чат-ботов — ни цены, ни формы, ни способа
 * написать. Формы на сайте не было ни одной: единственная стояла на
 * странице про Veo 3.
 *
 * Поэтому здесь — страница, с которой заявку действительно можно
 * отправить. Форма отдаёт данные нашему же маршруту /v1/lead: он пишет
 * заявку в журнал админки и пробует уведомить в Telegram. Даже если
 * Telegram недоступен, заявка не теряется.
 */

if (!defined('ABSPATH')) {
    exit;
}

class GS_Consult {

    const SLUG     = 'zakazat-konsultaciyu';
    const OPT_PAGE = 'gs_consult_page';

    public static function boot() {
        add_shortcode('genius_consult', array(__CLASS__, 'render'));
        add_filter('body_class', array(__CLASS__, 'body_class'));
        add_action('wp_enqueue_scripts', array(__CLASS__, 'assets'), 20);
    }

    public static function page_id() {
        return (int) get_option(self::OPT_PAGE);
    }

    public static function is_page() {
        $id = self::page_id();
        return $id > 0 && is_page($id);
    }

    public static function get_url() {
        $id = self::page_id();
        if ($id > 0 && get_post($id)) {
            return get_permalink($id);
        }
        return home_url('/' . self::SLUG . '/');
    }

    public static function ensure_page() {
        $id = self::page_id();
        if ($id > 0 && get_post($id)) {
            return $id;
        }
        $existing = get_page_by_path(self::SLUG);
        if ($existing) {
            $id = (int) $existing->ID;
        } else {
            $id = (int) wp_insert_post(array(
                'post_title'   => 'Заказать консультацию',
                'post_content' => '[genius_consult]',
                'post_status'  => 'publish',
                'post_type'    => 'page',
                'post_name'    => self::SLUG,
            ));
        }
        if ($id > 0) {
            update_option(self::OPT_PAGE, $id, false);
        }
        return $id;
    }

    public static function body_class($classes) {
        if (self::is_page()) {
            $classes[] = 'gs-chrome';
        }
        return $classes;
    }

    public static function assets() {
        if (!self::is_page()) {
            return;
        }
        wp_enqueue_style('genius-sounds-consult', GS_PLUGIN_URL . 'assets/css/consult.css', array(), GS_VERSION);
        wp_enqueue_script('genius-sounds-consult', GS_PLUGIN_URL . 'assets/js/consult.js', array(), GS_VERSION, true);
        wp_localize_script('genius-sounds-consult', 'gsConsult', array(
            'url' => esc_url_raw(rest_url('genius-sounds/v1/lead')),
        ));
    }

    /** Что умеем — по тем сервисам, которые на сайте действительно есть. */
    private static function topics() {
        return array(
            'Бот в Telegram или ВК: продажи, запись, поддержка, оплата внутри бота.',
            'Приём платежей и подписка на канал: проверка оплаты и доступ без ручной работы.',
            'Связка с таблицами, CRM и почтой: заявка из бота сразу попадает туда, где её ждут.',
            'Нейросети внутри бота: озвучка, расшифровка записей, картинки и видео по описанию.',
        );
    }

    public static function render($atts = array()) {
        $d = class_exists('GS_Seo') ? GS_Seo::org_details() : array();
        $privacy = class_exists('GS_Requisites') ? GS_Requisites::privacy_url() : '';
        $oferta  = class_exists('GS_Requisites') ? GS_Requisites::oferta_url() : '';
        $req     = class_exists('GS_Requisites') ? GS_Requisites::get_url() : '';

        ob_start();
        ?>
        <div class="gs-wrap gs-consult">
            <nav class="gs-breadcrumbs" aria-label="Хлебные крошки">
                <a href="<?php echo esc_url(home_url('/')); ?>">Главная</a>
                <span>Заказать консультацию</span>
            </nav>

            <h1 class="gs-consult__h1">Заказать консультацию по чат-боту</h1>
            <p class="gs-consult__lead">
                Расскажите, что должен делать бот, — разберём задачу и скажем, как её собрать:
                какие части нужны, сколько это займёт и во что обойдётся. Разбор бесплатный,
                ответ приходит в тот же день.
            </p>

            <div class="gs-consult__grid">
                <section class="gs-consult__form-card">
                    <h2>Заявка</h2>
                    <form class="gs-consult__form" novalidate>
                        <label>
                            <span>Как к вам обращаться</span>
                            <input type="text" name="name" maxlength="80" autocomplete="name">
                        </label>
                        <label>
                            <span>Телефон, почта или ник в Telegram <b>*</b></span>
                            <input type="text" name="contact" maxlength="120" required
                                   placeholder="+7 900 000-00-00, mail@example.com или @nickname">
                        </label>
                        <label>
                            <span>Задача</span>
                            <textarea name="comment" rows="5" maxlength="600"
                                      placeholder="Что должен делать бот, где он нужен, к чему его подключить"></textarea>
                        </label>
                        <button type="submit" class="gs-consult__send">Отправить заявку</button>
                        <p class="gs-consult__note" role="status" aria-live="polite"></p>
                        <p class="gs-consult__legal">
                            Нажимая кнопку, вы соглашаетесь с обработкой персональных данных
                            <?php if ($privacy !== ''): ?>
                                — <a href="<?php echo esc_url($privacy); ?>">политика обработки данных</a>
                            <?php endif; ?>.
                            Данные нужны только для ответа на заявку.
                        </p>
                    </form>
                </section>

                <aside class="gs-consult__side">
                    <section>
                        <h2>С чем обычно приходят</h2>
                        <ul class="gs-consult__list">
                            <?php foreach (self::topics() as $row): ?>
                                <li><?php echo esc_html($row); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </section>

                    <section>
                        <h2>Как это будет</h2>
                        <ol class="gs-consult__steps">
                            <li>Вы оставляете заявку — она сразу попадает к нам.</li>
                            <li>Мы отвечаем в тот же день и задаём уточняющие вопросы.</li>
                            <li>Обсуждаем задачу голосом или перепиской — как вам удобнее.</li>
                            <li>Присылаем состав работ, срок и стоимость. Решение за вами.</li>
                        </ol>
                    </section>

                    <section>
                        <h2>Связаться напрямую</h2>
                        <ul class="gs-consult__contacts">
                            <?php if (!empty($d['telegram'])): ?>
                                <li>Телеграм: <a href="<?php echo esc_url((string) $d['telegram']); ?>" rel="noopener"><?php echo esc_html((string) $d['telegram']); ?></a></li>
                            <?php endif; ?>
                            <?php if (!empty($d['email'])): ?>
                                <li>Почта: <a href="mailto:<?php echo esc_attr((string) $d['email']); ?>"><?php echo esc_html((string) $d['email']); ?></a></li>
                            <?php endif; ?>
                            <?php if (!empty($d['phone'])): ?>
                                <li>Телефон: <a href="tel:<?php echo esc_attr(preg_replace('~[^0-9+]~', '', (string) $d['phone'])); ?>"><?php echo esc_html((string) $d['phone']); ?></a></li>
                            <?php endif; ?>
                        </ul>
                        <p class="gs-consult__who">
                            Исполнитель: <?php echo esc_html((string) ($d['legalName'] ?? '')); ?><?php if (!empty($d['taxID'])): ?>, ИНН <?php echo esc_html((string) $d['taxID']); ?><?php endif; ?>.
                            <?php if ($oferta !== ''): ?><a href="<?php echo esc_url($oferta); ?>">Оферта</a><?php endif; ?>
                            <?php if ($req !== ''): ?> · <a href="<?php echo esc_url($req); ?>">реквизиты</a><?php endif; ?>
                        </p>
                    </section>
                </aside>
            </div>
        </div>
        <?php
        return (string) ob_get_clean();
    }
}
