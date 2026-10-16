<?php
/**
 * Страница с реквизитами.
 *
 * По коммерческим запросам поиск и покупатель смотрят на одно и то же:
 * понятно ли, кто оказывает услугу и как с ним связаться. Часть этого уже
 * есть в подвале — имя и ИНН, — но отдельной страницы, на которую можно
 * сослаться из документов и карточки организации, не было.
 *
 * Здесь публикуется только то, что известно достоверно. Пустых строк
 * «ОГРНИП: —» тут нет: недостающее лучше добавить, чем показать пробел.
 */

if (!defined('ABSPATH')) {
    exit;
}

class GS_Requisites {

    const SLUG = 'rekvizity';
    const OPT_PAGE = 'gs_requisites_page';

    public static function boot() {
        add_shortcode('genius_requisites', array(__CLASS__, 'render'));
    }

    public static function get_url() {
        $id = (int) get_option(self::OPT_PAGE);
        if ($id > 0 && get_post($id)) {
            return get_permalink($id);
        }
        return home_url('/' . self::SLUG . '/');
    }

    public static function ensure_page() {
        $id = (int) get_option(self::OPT_PAGE);
        if ($id > 0 && get_post($id)) {
            return $id;
        }
        $existing = get_page_by_path(self::SLUG);
        if ($existing) {
            $id = (int) $existing->ID;
        } else {
            $id = (int) wp_insert_post(array(
                'post_title'   => 'Реквизиты',
                'post_content' => '[genius_requisites]',
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

    /** Те же данные, что в карточке организации: один источник на сайт. */
    private static function details() {
        return class_exists('GS_Seo') ? GS_Seo::org_details() : array();
    }

    public static function render($atts = array()) {
        $d = self::details();
        $rows = array();
        if (!empty($d['legalName'])) {
            $rows['Исполнитель'] = esc_html((string) $d['legalName']);
        }
        if (!empty($d['taxID'])) {
            $rows['ИНН'] = esc_html((string) $d['taxID']);
        }
        if (!empty($d['email'])) {
            $rows['Почта'] = '<a href="mailto:' . esc_attr((string) $d['email']) . '">' . esc_html((string) $d['email']) . '</a>';
        }
        if (!empty($d['telegram'])) {
            $rows['Телеграм'] = '<a href="' . esc_url((string) $d['telegram']) . '" rel="noopener">' . esc_html((string) $d['telegram']) . '</a>';
        }
        $rows['Сайт'] = '<a href="' . esc_url(home_url('/')) . '">' . esc_html((string) wp_parse_url(home_url('/'), PHP_URL_HOST)) . '</a>';

        $docs = self::documents();

        ob_start();
        ?>
        <div class="gs-wrap gs-studio gs-req">
            <nav class="gs-breadcrumbs" aria-label="Хлебные крошки">
                <a href="<?php echo esc_url(home_url('/')); ?>">Главная</a>
                <span aria-hidden="true">/</span>
                <span class="gs-breadcrumbs__current">Реквизиты</span>
            </nav>

            <section class="gs-hero gs-hero--studio">
                <h1 class="gs-hero__title">Реквизиты</h1>
                <p class="gs-hero__lead">Кто оказывает услуги на сайте, как связаться и по каким документам идёт работа.</p>
            </section>

            <section class="gs-req__block">
                <h2 class="gs-section-title">Исполнитель</h2>
                <dl class="gs-req__list">
                    <?php foreach ($rows as $label => $value): ?>
                        <div class="gs-req__row">
                            <dt><?php echo esc_html($label); ?></dt>
                            <dd><?php echo $value; // phpcs:ignore WordPress.Security.EscapeOutput ?></dd>
                        </div>
                    <?php endforeach; ?>
                </dl>
            </section>

            <?php if ($docs): ?>
                <section class="gs-req__block">
                    <h2 class="gs-section-title">Документы</h2>
                    <ul class="gs-req__docs">
                        <?php foreach ($docs as $title => $url): ?>
                            <li><a href="<?php echo esc_url($url); ?>"><?php echo esc_html($title); ?></a></li>
                        <?php endforeach; ?>
                    </ul>
                </section>
            <?php endif; ?>

            <section class="gs-req__block">
                <h2 class="gs-section-title">Оплата и возврат</h2>
                <p>Услуги оплачиваются с баланса на сайте: баланс пополняется банковской картой,
                   деньги списываются за каждую выполненную операцию по цене, указанной на странице сервиса.</p>
                <p>Если операция списала деньги и не дала результата, средства возвращаются на баланс
                   автоматически — отдельно просить о возврате не нужно.</p>
            </section>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    /** Документы сайта — берём те страницы, что действительно есть. */
    private static function documents() {
        // Оферта на сайте выложена файлом, а не страницей — ссылаемся на
        // тот же файл, что стоит в подвале.
        $out = array();
        $oferta = (string) apply_filters('gs_oferta_url',
            'https://genius-bot.ru/wp-content/uploads/2024/05/oferta_241901583600.docx');
        if ($oferta !== '') {
            $out['Публичная оферта'] = $oferta;
        }

        $wanted = array(
            'Политика обработки персональных данных' => array('politika-konfidencialnosti', 'privacy-policy', 'personal-data'),
            'Порядок оказания услуг'               => array('poryadok-okazaniya-uslug'),
        );
        foreach ($wanted as $title => $slugs) {
            foreach ($slugs as $slug) {
                $page = get_page_by_path($slug);
                if ($page) {
                    $out[$title] = get_permalink($page);
                    break;
                }
            }
        }
        return $out;
    }
}
