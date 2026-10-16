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

    const OFERTA_SLUG = 'oferta';
    const OPT_OFERTA  = 'gs_oferta_page';

    /** Исходный файл оферты — оставляем ссылкой: он подписан и выложен давно. */
    const OFERTA_FILE = 'https://genius-bot.ru/wp-content/uploads/2024/05/oferta_241901583600.docx';

    public static function boot() {
        add_shortcode('genius_requisites', array(__CLASS__, 'render'));
        add_filter('body_class', array(__CLASS__, 'body_class'));
        add_action('wp_enqueue_scripts', array(__CLASS__, 'assets'), 20);
        add_action('wp_footer', array(__CLASS__, 'render_footer_legal'), 30);
    }

    /**
     * Строка с документами в подвале.
     *
     * Страницы оферты и реквизитов были сиротами: с главной на них не
     * ссылалась ни одна ссылка, а в подвале стояли те же документы файлами
     * .docx из загрузок 2024 года. Поиск такую страницу находит, но веса
     * ей не даёт, а покупатель файл не открывает.
     *
     * Поэтому ссылки печатаем сами и на каждой странице — в том числе в
     * каталоге, где блока перелинковки нет.
     */
    public static function render_footer_legal() {
        if (is_admin() || is_feed()) {
            return;
        }
        $docs = self::documents();
        // Файл оферты из строки убираем: рядом есть та же оферта страницей.
        unset($docs['Оферта файлом (.docx)']);
        $links = array('Реквизиты' => self::get_url()) + $docs;
        $links = array_filter($links, function ($url) {
            return is_string($url) && $url !== '';
        });
        if (count($links) < 2) {
            return;
        }
        ?>
        <nav class="gs-legal" aria-label="Документы и реквизиты">
            <p class="gs-legal__in">
                <?php $i = 0; foreach ($links as $title => $url): $i++; ?>
                    <?php if ($i > 1): ?> · <?php endif; ?>
                    <a href="<?php echo esc_url($url); ?>"><?php echo esc_html($title); ?></a>
                <?php endforeach; ?>
            </p>
        </nav>
        <style>
            .gs-legal{background:#070b14;color:#64748b;padding:14px 16px;font-size:13px;line-height:1.6}
            .gs-legal__in{max-width:1200px;margin:0 auto;text-align:center}
            .gs-legal a{color:#94a3b8;text-decoration:none}
            .gs-legal a:hover{color:#22d3ee;text-decoration:underline}
        </style>
        <?php
    }

    /** Наши документные страницы: оферта и реквизиты. */
    public static function is_doc_page() {
        if (!is_page()) {
            return false;
        }
        $id = (int) get_queried_object_id();
        if ($id <= 0) {
            return false;
        }
        return $id === (int) get_option(self::OPT_OFERTA) || $id === (int) get_option(self::OPT_PAGE);
    }

    public static function body_class($classes) {
        if (self::is_doc_page()) {
            $classes[] = 'gs-doc-page';
        }
        return $classes;
    }

    public static function assets() {
        if (self::is_doc_page()) {
            wp_enqueue_style('genius-sounds-doc', GS_PLUGIN_URL . 'assets/css/doc.css', array(), GS_VERSION);
        }
    }

    public static function get_url() {
        $id = (int) get_option(self::OPT_PAGE);
        if ($id > 0 && get_post($id)) {
            return get_permalink($id);
        }
        return home_url('/' . self::SLUG . '/');
    }

    public static function oferta_url() {
        $id = (int) get_option(self::OPT_OFERTA);
        if ($id > 0 && get_post($id)) {
            return get_permalink($id);
        }
        $page = get_page_by_path(self::OFERTA_SLUG);
        return $page ? get_permalink($page) : '';
    }

    /**
     * Оферта страницей, а не файлом.
     *
     * Создаём один раз: дальше текст правят в редакторе WordPress, и
     * перезаписывать его отсюда нельзя — иначе правка исчезнет при
     * следующем обновлении плагина.
     */
    public static function ensure_oferta_page() {
        $id = (int) get_option(self::OPT_OFERTA);
        if ($id > 0 && get_post($id)) {
            self::fix_oferta_heading($id);
            return $id;
        }
        $existing = get_page_by_path(self::OFERTA_SLUG);
        if ($existing) {
            $id = (int) $existing->ID;
        } else {
            $html = include GS_PLUGIN_DIR . 'includes/oferta-content.php';
            if (!is_string($html) || $html === '') {
                return 0;
            }
            $id = (int) wp_insert_post(array(
                'post_title'   => 'Публичная оферта',
                'post_content' => $html,
                'post_status'  => 'publish',
                'post_type'    => 'page',
                'post_name'    => self::OFERTA_SLUG,
            ));
        }
        if ($id > 0) {
            update_option(self::OPT_OFERTA, $id, false);
            self::fix_oferta_heading($id);
        }
        return $id;
    }

    /**
     * Разовая правка: страница создавалась без заголовка первого уровня —
     * тема его для этого шаблона не печатает, и страница оставалась без
     * единственного тега, который объясняет поиску, о чём она.
     *
     * Трогаем только нетронутый текст: если страницу уже правили руками,
     * ничего не делаем.
     */
    private static function fix_oferta_heading($id) {
        $post = get_post($id);
        if (!$post instanceof WP_Post) {
            return;
        }
        $content = (string) $post->post_content;
        if (stripos($content, '<h1') !== false || strpos($content, 'Общие положения') === false) {
            return;
        }
        $lead = '<h1>Публичная оферта</h1>' . "\n"
              . '<p>Договор об оказании услуг. Тот же текст можно скачать файлом: '
              . '<a href="' . esc_url(self::OFERTA_FILE) . '">оферта в .docx</a>.</p>' . "\n";
        wp_update_post(array('ID' => $id, 'post_content' => $lead . $content));
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
        if (!empty($d['phone'])) {
            $rows['Телефон'] = '<a href="tel:' . esc_attr(preg_replace('~[^0-9+]~', '', (string) $d['phone'])) . '">'
                . esc_html((string) $d['phone']) . '</a>';
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
        $out = array();
        $page = self::oferta_url();
        if ($page !== '') {
            $out['Публичная оферта'] = $page;
        }
        $out['Оферта файлом (.docx)'] = (string) apply_filters('gs_oferta_file', self::OFERTA_FILE);

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
