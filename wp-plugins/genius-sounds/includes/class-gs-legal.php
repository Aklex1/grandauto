<?php
/**
 * Юридический блок: две посадочные и страницы под хвосты запросов.
 *
 * Строение простое и намеренно одинаковое для всех страниц: первый экран с
 * формой, «как это работает», веер дочерних страниц, образец документа,
 * тарифы, вопросы. Отличается содержание, а не вёрстка: двадцать три
 * страницы с разной структурой невозможно поддерживать, а поисковику важно
 * другое — что на каждой свой заголовок, своё описание и свои вопросы.
 *
 * Иерархия настоящая: дочерние страницы лежат под посадочной, поэтому адрес
 * читается как путь — /pretenziya/pretenziya-prodavcu/. Это же даёт крошки
 * и разметку BreadcrumbList без отдельной таблицы соответствий.
 *
 * Заявка уходит в Telegram через GS_Leads — тем же путём, что и остальные
 * заявки сайта. Документ пока не генерируется автоматически: обещать в
 * разметке то, чего нет, нельзя, поэтому на страницах написано, что документ
 * присылают после оплаты, а не «скачайте сейчас».
 */

if (!defined('ABSPATH')) {
    exit;
}

class GS_Legal {

    const OPT_PAGES   = 'gs_legal_pages';
    const PRICE_BASE  = 490;
    const PRICE_FULL  = 990;

    /** Потоки очереди публикаций под каждый кластер. */
    const LANE_CLAIM  = 'pretenzia';
    const LANE_ORDER  = 'prikaz';

    private static $tree = null;

    /* ---------------------------------------------------------------------
     * Данные
     * ------------------------------------------------------------------ */

    public static function tree() {
        if (self::$tree === null) {
            self::$tree = (array) require GS_PLUGIN_DIR . 'includes/legal-pages.php';
        }
        return self::$tree;
    }

    public static function page($id) {
        $tree = self::tree();
        return isset($tree[$id]) ? $tree[$id] : null;
    }

    /** Корневые страницы: посадочные. */
    public static function roots() {
        $out = array();
        foreach (self::tree() as $id => $page) {
            if (($page['parent'] ?? '') === '') {
                $out[$id] = $page;
            }
        }
        return $out;
    }

    /** Дочерние страницы посадочной, в порядке дерева. */
    public static function children($parent_id) {
        $out = array();
        foreach (self::tree() as $id => $page) {
            if (($page['parent'] ?? '') === $parent_id) {
                $out[$id] = $page;
            }
        }
        return $out;
    }

    /** К какому кластеру относится страница. */
    public static function root_of($id) {
        $page = self::page($id);
        if (!$page) {
            return '';
        }
        return ($page['parent'] ?? '') === '' ? $id : (string) $page['parent'];
    }

    /** Поток очереди публикаций для кластера. */
    public static function lane_of($id) {
        return self::root_of($id) === 'order' ? self::LANE_ORDER : self::LANE_CLAIM;
    }

    /* ---------------------------------------------------------------------
     * Страницы
     * ------------------------------------------------------------------ */

    public static function ids() {
        $saved = get_option(self::OPT_PAGES, array());
        return is_array($saved) ? $saved : array();
    }

    public static function page_id($id) {
        $ids = self::ids();
        return isset($ids[$id]) ? (int) $ids[$id] : 0;
    }

    public static function get_url($id) {
        $page_id = self::page_id($id);
        $url = $page_id > 0 ? get_permalink($page_id) : '';
        if ($url) {
            return $url;
        }
        $page = self::page($id);
        return $page ? home_url('/' . $page['slug'] . '/') : home_url('/');
    }

    /**
     * Создаём и обновляем страницы.
     *
     * Родителей ставим первым проходом: у дочерней страницы post_parent
     * должен быть известен, иначе адрес соберётся без пути, и потом его
     * менять — значит менять адрес уже проиндексированной страницы.
     *
     * Цитату заполняем не для вида: содержимое страницы — один шорткод, и
     * сторонний SEO-блок темы иначе собирает описание для выдачи из него.
     */
    public static function ensure_pages() {
        $ids = self::ids();
        $order = array_merge(array_keys(self::roots()), array());
        foreach (self::tree() as $id => $page) {
            if (($page['parent'] ?? '') !== '') {
                $order[] = $id;
            }
        }

        foreach ($order as $id) {
            $page = self::page($id);
            if (!$page) {
                continue;
            }
            $parent_id = ($page['parent'] ?? '') !== ''
                ? (int) (isset($ids[$page['parent']]) ? $ids[$page['parent']] : 0)
                : 0;

            $data = array(
                'post_title'   => $page['seo_title'],
                'post_content' => '[genius_legal id="' . $id . '"]',
                'post_excerpt' => $page['seo_desc'],
                'post_status'  => 'publish',
                'post_type'    => 'page',
                'post_parent'  => $parent_id,
            );

            $existing = isset($ids[$id]) ? (int) $ids[$id] : 0;
            if ($existing <= 0) {
                $found = get_page_by_path(
                    $parent_id > 0 ? get_post_field('post_name', $parent_id) . '/' . $page['slug'] : $page['slug']
                );
                if (!$found) {
                    $found = get_page_by_path($page['slug']);
                }
                if ($found instanceof WP_Post) {
                    $existing = (int) $found->ID;
                }
            }

            if ($existing > 0) {
                $data['ID'] = $existing;
                wp_update_post($data);
                $ids[$id] = $existing;
            } else {
                $data['post_name'] = $page['slug'];
                $new = (int) wp_insert_post($data);
                if ($new > 0) {
                    $ids[$id] = $new;
                }
            }
        }
        update_option(self::OPT_PAGES, $ids, false);
        return $ids;
    }

    /* ---------------------------------------------------------------------
     * Вывод и SEO
     * ------------------------------------------------------------------ */

    public static function boot() {
        add_filter('body_class', array(__CLASS__, 'body_class'));
        add_filter('document_title_parts', array(__CLASS__, 'title_parts'));
        add_action('wp_head', array(__CLASS__, 'head'), 1);
    }

    public static function register_shortcodes() {
        add_shortcode('genius_legal', array(__CLASS__, 'shortcode'));
    }

    /** Какая страница блока открыта сейчас. '' — никакая. */
    public static function current() {
        if (is_admin()) {
            return '';
        }
        foreach (self::ids() as $id => $page_id) {
            if ((int) $page_id > 0 && is_page((int) $page_id)) {
                return (string) $id;
            }
        }
        return '';
    }

    public static function body_class($classes) {
        if (self::current() !== '') {
            $classes[] = 'gs-legal-page';
            $classes[] = 'gs-chrome';
        }
        return $classes;
    }

    /** Заголовок целиком наш: тема иначе приклеит к нему имя сайта. */
    public static function title_parts($parts) {
        $id = self::current();
        if ($id === '') {
            return $parts;
        }
        $page = self::page($id);
        return array('title' => $page['seo_title']);
    }

    public static function head() {
        $id = self::current();
        if ($id === '') {
            return;
        }
        $page = self::page($id);
        $url = self::get_url($id);

        echo '<meta name="description" content="' . esc_attr($page['seo_desc']) . '">' . "\n";
        echo '<link rel="canonical" href="' . esc_url($url) . '">' . "\n";
        echo '<meta property="og:type" content="website">' . "\n";
        echo '<meta property="og:title" content="' . esc_attr($page['seo_title']) . '">' . "\n";
        echo '<meta property="og:description" content="' . esc_attr($page['seo_desc']) . '">' . "\n";
        echo '<meta property="og:url" content="' . esc_url($url) . '">' . "\n";

        // Вопросы и ответы размечаем: они попадают в выдачу отдельным блоком.
        $faq = array();
        foreach ((array) ($page['faq'] ?? array()) as $pair) {
            $faq[] = array(
                '@type'          => 'Question',
                'name'           => $pair[0],
                'acceptedAnswer' => array('@type' => 'Answer', 'text' => $pair[1]),
            );
        }
        if ($faq) {
            echo '<script type="application/ld+json">' . wp_json_encode(array(
                '@context'   => 'https://schema.org',
                '@type'      => 'FAQPage',
                'mainEntity' => $faq,
            ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '</script>' . "\n";
        }

        // Крошки: поисковик показывает путь вместо длинного адреса.
        $crumbs = array(array('name' => 'Главная', 'url' => home_url('/')));
        $root = self::root_of($id);
        if ($root !== $id) {
            $crumbs[] = array('name' => self::page($root)['menu'], 'url' => self::get_url($root));
        }
        $crumbs[] = array('name' => $page['menu'], 'url' => $url);

        $items = array();
        foreach ($crumbs as $i => $crumb) {
            $items[] = array(
                '@type'    => 'ListItem',
                'position' => $i + 1,
                'name'     => $crumb['name'],
                'item'     => $crumb['url'],
            );
        }
        echo '<script type="application/ld+json">' . wp_json_encode(array(
            '@context'        => 'https://schema.org',
            '@type'           => 'BreadcrumbList',
            'itemListElement' => $items,
        ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '</script>' . "\n";
    }

    public static function shortcode($atts) {
        $atts = shortcode_atts(array('id' => ''), $atts, 'genius_legal');
        $id = (string) $atts['id'];
        if (!self::page($id)) {
            return '';
        }
        ob_start();
        self::render($id);
        return ob_get_clean();
    }

    /* ---------------------------------------------------------------------
     * Разметка страницы
     * ------------------------------------------------------------------ */

    private static function render($id) {
        $page = self::page($id);
        $root = self::root_of($id);
        $is_root = $root === $id;
        $children = self::children($is_root ? $id : $root);
        $order_cluster = $root === 'order';
        ?>
        <div class="gs-legal" data-gs-legal="<?php echo esc_attr($id); ?>">

            <nav class="gs-legal-crumbs" aria-label="Путь">
                <a href="<?php echo esc_url(home_url('/')); ?>">Главная</a>
                <?php if (!$is_root): ?>
                    <span>→</span>
                    <a href="<?php echo esc_url(self::get_url($root)); ?>"><?php
                        echo esc_html(self::page($root)['menu']); ?></a>
                <?php endif; ?>
                <span>→</span><b><?php echo esc_html($page['menu']); ?></b>
            </nav>

            <section class="gs-legal-hero">
                <div class="gs-legal-hero__text">
                    <h1><?php echo esc_html($page['h1']); ?></h1>
                    <p class="gs-legal-lead"><?php echo esc_html($page['lead']); ?></p>
                    <ul class="gs-legal-bullets">
                        <?php foreach ((array) ($page['bullets'] ?? array()) as $line): ?>
                            <li><?php echo esc_html($line); ?></li>
                        <?php endforeach; ?>
                    </ul>
                    <p class="gs-legal-trust">
                        <span>📄 Word и PDF</span>
                        <span>⏱ 5–10 минут</span>
                        <span>💳 от <?php echo (int) self::PRICE_BASE; ?> ₽</span>
                    </p>
                </div>
                <?php self::render_form($id, $order_cluster); ?>
            </section>

            <?php if ($order_cluster) {
                self::render_deadline();
            } ?>

            <section class="gs-legal-steps">
                <h2>Как это работает</h2>
                <div class="gs-legal-grid3">
                    <div class="gs-legal-card"><b>1. Опишите ситуацию</b>
                        <p>Своими словами: что произошло, когда, какие суммы и чего вы хотите.
                        Юридические термины подбирать не нужно.</p></div>
                    <div class="gs-legal-card"><b>2. Получите документ</b>
                        <p><?php echo $order_cluster
                            ? 'Возражение со ссылками на ГПК РФ, с расчётом срока и, если он пропущен, с заявлением о его восстановлении.'
                            : 'Претензию со ссылками на закон, расчётом неустойки и сроком для ответа.'; ?></p></div>
                    <div class="gs-legal-card"><b>3. Отправьте адресату</b>
                        <p><?php echo $order_cluster
                            ? 'Мировому судье — лично, почтой или через Госуслуги. В комплекте порядок подачи и что сохранить.'
                            : 'Вручите под подпись или отправьте заказным письмом с описью. Инструкция прилагается.'; ?></p></div>
                </div>
            </section>

            <?php if ($children): ?>
                <section class="gs-legal-cases">
                    <h2><?php echo $is_root ? 'Выберите свою ситуацию' : 'Другие ситуации этого раздела'; ?></h2>
                    <div class="gs-legal-grid4">
                        <?php foreach ($children as $child_id => $child): ?>
                            <?php if ($child_id === $id) { continue; } ?>
                            <a class="gs-legal-case" href="<?php echo esc_url(self::get_url($child_id)); ?>">
                                <b><?php echo esc_html($child['menu']); ?></b>
                                <span><?php echo esc_html($child['h1']); ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                    <?php if (!$is_root): ?>
                        <p class="gs-legal-back">
                            <a href="<?php echo esc_url(self::get_url($root)); ?>">←
                                <?php echo esc_html(self::page($root)['h1']); ?></a>
                        </p>
                    <?php endif; ?>
                </section>
            <?php endif; ?>

            <?php self::render_prices($order_cluster); ?>

            <?php if (!empty($page['faq'])): ?>
                <section class="gs-legal-faq">
                    <h2>Частые вопросы</h2>
                    <?php foreach ($page['faq'] as $pair): ?>
                        <details>
                            <summary><?php echo esc_html($pair[0]); ?></summary>
                            <p><?php echo wp_kses_post($pair[1]); ?></p>
                        </details>
                    <?php endforeach; ?>
                </section>
            <?php endif; ?>

            <p class="gs-legal-disclaimer">
                Сервис готовит проект документа по сведениям, которые вы указали, и не оказывает
                услуги адвоката. Перед отправкой проверьте в документе даты, суммы и реквизиты.
                По сложному спору стоит показать документ юристу.
            </p>
        </div>
        <?php
    }

    /** Счётчик срока: он же главный аргумент на странице про приказ. */
    private static function render_deadline() {
        ?>
        <section class="gs-legal-deadline" id="gs-legal-deadline">
            <h2>Сколько у вас осталось времени</h2>
            <p>На возражение даётся десять дней со дня получения копии приказа (ст. 128 ГПК РФ).
            Укажите дату — посчитаем.</p>
            <div class="gs-legal-deadline__row">
                <label for="gs-legal-got">Дата получения приказа
                    <input type="date" id="gs-legal-got"></label>
                <p class="gs-legal-deadline__out" id="gs-legal-left"></p>
            </div>
        </section>
        <?php
    }

    private static function render_prices($order_cluster) {
        ?>
        <section class="gs-legal-prices">
            <h2>Сколько стоит</h2>
            <div class="gs-legal-grid2">
                <div class="gs-legal-card gs-legal-price">
                    <b><?php echo $order_cluster ? 'Возражение' : 'Претензия'; ?></b>
                    <div class="gs-legal-price__sum"><?php echo (int) self::PRICE_BASE; ?> ₽</div>
                    <ul>
                        <?php if ($order_cluster): ?>
                            <li>Возражение на судебный приказ</li>
                            <li>Заявление о восстановлении срока, если он пропущен</li>
                            <li>Порядок подачи: судье, почтой или через Госуслуги</li>
                        <?php else: ?>
                            <li>Претензия под вашу ситуацию</li>
                            <li>Ссылки на статьи закона и расчёт неустойки</li>
                            <li>Инструкция по отправке</li>
                        <?php endif; ?>
                        <li>Одна бесплатная доработка</li>
                    </ul>
                </div>
                <div class="gs-legal-card gs-legal-price gs-legal-price--full">
                    <b>Полный комплект</b>
                    <div class="gs-legal-price__sum"><?php echo (int) self::PRICE_FULL; ?> ₽</div>
                    <ul>
                        <li>Всё из первого тарифа</li>
                        <?php if ($order_cluster): ?>
                            <li>Заявление о повороте исполнения — вернуть списанное</li>
                            <li>Заявление приставам о прекращении производства</li>
                            <li>План действий, если взыскатель подаст иск</li>
                        <?php else: ?>
                            <li>Жалоба в надзорный орган: Роспотребнадзор, ЦБ или ГЖИ</li>
                            <li>Черновик искового заявления с расчётом цены иска</li>
                            <li>План действий на 30 дней</li>
                        <?php endif; ?>
                        <li>Три бесплатные доработки</li>
                    </ul>
                </div>
            </div>
        </section>
        <?php
    }

    private static function render_form($id, $order_cluster) {
        $page = self::page($id);
        $placeholder = $order_cluster
            ? 'Например: 12 сентября получил судебный приказ от мирового судьи участка № 5, взыскатель — МФО, сумма 48 000 ₽ вместе с процентами. Заём брал в 2021 году, платил до 2022-го. Хочу отменить приказ и вернуть списанное с карты.'
            : 'Например: 12 сентября купил стиральную машину за 34 990 ₽. Через две недели перестала сливать воду, сервис отказал в гарантийном ремонте. Хочу вернуть деньги.';
        ?>
        <div class="gs-legal-card gs-legal-form" id="gs-legal-form">
            <b><?php echo $order_cluster ? 'Составить возражение' : 'Составить претензию'; ?></b>
            <label for="gs-legal-story">Что случилось</label>
            <textarea id="gs-legal-story" rows="6"
                      placeholder="<?php echo esc_attr($placeholder); ?>"></textarea>

            <label>Что нужно</label>
            <div class="gs-legal-plans">
                <label class="gs-legal-plan">
                    <input type="radio" name="gs-legal-plan" value="<?php echo (int) self::PRICE_BASE; ?>" checked>
                    <b><?php echo (int) self::PRICE_BASE; ?> ₽</b>
                    <span><?php echo $order_cluster ? 'Возражение и восстановление срока'
                                                    : 'Претензия и инструкция'; ?></span>
                </label>
                <label class="gs-legal-plan">
                    <input type="radio" name="gs-legal-plan" value="<?php echo (int) self::PRICE_FULL; ?>">
                    <b><?php echo (int) self::PRICE_FULL; ?> ₽</b>
                    <span><?php echo $order_cluster ? 'Плюс поворот исполнения и приставы'
                                                    : 'Плюс жалоба и черновик иска'; ?></span>
                </label>
            </div>

            <label for="gs-legal-contact">Куда прислать документ</label>
            <input id="gs-legal-contact" type="text" placeholder="Почта или ник в Telegram">

            <button type="button" class="gs-legal-btn" id="gs-legal-send">Разобрать ситуацию
                бесплатно</button>
            <p class="gs-legal-note" id="gs-legal-status">Сначала бесплатный разбор: кому
                адресовать, что требовать и чего не хватает в описании. Оплата — после него.</p>
            <input type="hidden" id="gs-legal-case" value="<?php echo esc_attr($page['case'] ?? 'other'); ?>">
            <input type="hidden" id="gs-legal-page" value="<?php echo esc_attr($id); ?>">

            <!-- Шаг 2: разбор и оплата -->
            <div class="gs-legal-step" id="gs-legal-review" hidden>
                <h3>Разбор вашей ситуации</h3>
                <div class="gs-legal-review__text" id="gs-legal-review-text"></div>
                <p class="gs-legal-note">Документ соберём по этому разбору. Поля в квадратных
                    скобках — то, чего не было в описании: их нужно будет заполнить перед отправкой.</p>
                <div class="gs-legal-pay" id="gs-legal-pay"></div>
                <p class="gs-legal-note" id="gs-legal-status2"></p>
            </div>

            <!-- Шаг 3: готовые документы -->
            <div class="gs-legal-step" id="gs-legal-done" hidden>
                <h3>Документы готовы</h3>
                <div id="gs-legal-parts"></div>
                <p class="gs-legal-note">Проверьте даты, суммы и реквизиты, заполните поля в
                    скобках. PDF получается печатью из Word или браузера.</p>
            </div>
        </div>
        <?php
    }
}
