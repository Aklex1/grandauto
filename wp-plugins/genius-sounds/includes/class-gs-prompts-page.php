<?php
/**
 * Посадочная каталога промтов и страницы отдельных промтов.
 *
 * Страница одна и работает на два сценария. Человек приходит по рекламе,
 * которая раньше вела прямо в Телеграм, — и в первом экране выбирает:
 * смотреть промты здесь же или уйти в канал. Кнопка «смотреть здесь»
 * никуда не уводит, а прокручивает к каталогу: в этом и смысл затеи —
 * не терять тех, у кого Телеграм не открывается.
 *
 * Адреса виртуальные, но ведут на одну настоящую страницу с шорткодом —
 * так же, как каталог звуков. Печатать документ самим нельзя: пропадут
 * шапка и подвал темы.
 *
 *   /katalog-promtov/            — посадочная с каталогом
 *   /katalog-promtov/page/2/     — следующие страницы каталога
 *   /katalog-promtov/<промт>/    — страница одного промта
 */

if (!defined('ABSPATH')) {
    exit;
}

class GS_Prompts_Page {

    const SHORTCODE = 'genius_prompts';

    public static function boot() {
        add_action('init', array(__CLASS__, 'add_rewrite_rules'), 5);
        add_filter('query_vars', array(__CLASS__, 'query_vars'));
        add_filter('body_class', array(__CLASS__, 'body_class'));
        add_filter('document_title_parts', array(__CLASS__, 'title_parts'), PHP_INT_MAX);
        add_action('wp_head', array(__CLASS__, 'head'), 2);
        add_action('template_redirect', array(__CLASS__, 'no_cache_for_ads'), 1);
        // Своя липкая плашка: штатная работает только на статьях.
        add_action('wp_footer', array(__CLASS__, 'sticky'), 5);
    }

    public static function register_shortcodes() {
        add_shortcode(self::SHORTCODE, array(__CLASS__, 'render'));
    }

    /* ---------------------------------------------------------------------
     * Страница и адреса
     * ------------------------------------------------------------------ */

    /**
     * Страница с подбором под фразу — персональная, из общего кэша её
     * отдавать нельзя: все увидели бы подбор первого зашедшего.
     */
    public static function no_cache_for_ads() {
        if (self::is_page() && self::ad_phrase() !== '' && class_exists('GS_Cache')) {
            GS_Cache::no_cache();
        }
    }

    public static function page_id() {
        return (int) get_option(GS_Prompts::OPT_PAGE);
    }

    public static function ensure_page() {
        $id = self::page_id();
        if ($id > 0 && get_post($id)) {
            return;
        }
        $existing = get_page_by_path(GS_Prompts::SLUG);
        $args = array(
            'post_title'   => 'Готовые промты для фото: каталог с примерами',
            'post_content' => '[' . self::SHORTCODE . ']',
            'post_status'  => 'publish',
        );
        if ($existing) {
            $id = (int) $existing->ID;
            wp_update_post(array_merge(array('ID' => $id), $args));
        } else {
            $id = (int) wp_insert_post(array_merge($args, array(
                'post_type' => 'page',
                'post_name' => GS_Prompts::SLUG,
            )));
        }
        if ($id > 0) {
            update_option(GS_Prompts::OPT_PAGE, $id);
        }
    }

    public static function add_rewrite_rules() {
        $pid = self::page_id();
        if ($pid <= 0) {
            return;
        }
        $s = preg_quote(GS_Prompts::SLUG, '~');
        add_rewrite_rule('^' . $s . '/page/([0-9]{1,5})/?$',
            'index.php?page_id=' . $pid . '&gs_prompt_page=$matches[1]', 'top');
        // Рубрика — отдельный адрес, а не параметр: на такую страницу
        // ведёт объявление и её же индексирует поиск.
        add_rewrite_rule('^' . $s . '/rubrika/([^/]+)/page/([0-9]{1,5})/?$',
            'index.php?page_id=' . $pid . '&gs_prompt_rubric=$matches[1]&gs_prompt_page=$matches[2]', 'top');
        add_rewrite_rule('^' . $s . '/rubrika/([^/]+)/?$',
            'index.php?page_id=' . $pid . '&gs_prompt_rubric=$matches[1]', 'top');
        add_rewrite_rule('^' . $s . '/([^/]+)/?$',
            'index.php?page_id=' . $pid . '&gs_prompt_slug=$matches[1]', 'top');
    }

    public static function query_vars($vars) {
        $vars[] = 'gs_prompt_slug';
        $vars[] = 'gs_prompt_page';
        $vars[] = 'gs_prompt_rubric';
        return $vars;
    }

    public static function url($slug = '') {
        $base = home_url('/' . GS_Prompts::SLUG . '/');
        return $slug === '' ? $base : $base . rawurlencode($slug) . '/';
    }

    public static function page_url($n) {
        $n = max(1, (int) $n);
        return $n === 1 ? self::url() : home_url('/' . GS_Prompts::SLUG . '/page/' . $n . '/');
    }

    /** Адрес рубрики. */
    public static function rubric_url($key, $n = 1) {
        $key = sanitize_key((string) $key);
        if ($key === '') {
            return self::page_url($n);
        }
        $base = home_url('/' . GS_Prompts::SLUG . '/rubrika/' . $key . '/');
        return (int) $n > 1 ? $base . 'page/' . (int) $n . '/' : $base;
    }

    /**
     * Фраза, с которой человек пришёл.
     *
     * В объявлении Директа в адрес подставляется макрос {keyword} — текст
     * ключевой фразы, по которой объявление сработало. Из обычного поиска
     * фразу не узнать: и Яндекс, и Google давно её в переходе не передают.
     * Поэтому единственный честный источник — метка в рекламной ссылке.
     */
    public static function ad_phrase() {
        // utm_term сюда не доходит: хостинг вырезает utm-метки из запроса
        // ещё до PHP (в браузере они остаются, и Метрика их видит, а мы —
        // нет). Поэтому в рекламной ссылке фразу дублируем своим именем.
        foreach (array('kw', 'term', 'keyword', 'utm_term') as $key) {
            if (empty($_GET[$key])) {
                continue;
            }
            $raw = sanitize_text_field(wp_unslash((string) $_GET[$key]));
            $raw = trim(preg_replace('~\s+~u', ' ', $raw));
            // Незаполненный макрос приезжает как есть — это не фраза.
            if ($raw === '' || strpos($raw, '{') !== false || mb_strlen($raw, 'UTF-8') > 120) {
                continue;
            }
            return $raw;
        }
        return '';
    }

    /** Какая рубрика открыта: адресом или старым параметром ?r=. */
    public static function current_rubric() {
        $key = sanitize_key((string) get_query_var('gs_prompt_rubric'));
        if ($key === '' && isset($_GET['r'])) {
            $key = sanitize_key(wp_unslash((string) $_GET['r']));
        }
        return isset(GS_Prompts::rubrics()[$key]) ? $key : '';
    }

    /** Открыта ли наша страница (посадочная или промт). */
    public static function is_page() {
        $pid = self::page_id();
        return $pid > 0 && is_page($pid);
    }

    /** Открыт ли отдельный промт — и какой. */
    public static function current_item() {
        if (!self::is_page()) {
            return null;
        }
        $slug = (string) get_query_var('gs_prompt_slug');
        if ($slug === '') {
            return null;
        }
        return GS_Prompts::get($slug);
    }

    public static function body_class($classes) {
        if (self::is_page()) {
            $classes[] = 'gs-chrome';
            $classes[] = 'gs-prompts-page';
        }
        return $classes;
    }

    /* ---------------------------------------------------------------------
     * Голова
     * ------------------------------------------------------------------ */

    public static function title_parts($parts) {
        if (!self::is_page()) {
            return $parts;
        }
        $item = self::current_item();
        if ($item) {
            $parts['title'] = (string) $item['title'] . ' — промт для фото';
        } else {
            $rubric = self::current_rubric();
            if ($rubric !== '') {
                $parts['title'] = GS_Prompts::rubric_title($rubric) . ' промты для фото — готовые примеры с кадрами';
            } else {
                $parts['title'] = 'Готовые промты для фото: ' . GS_Prompts::count() . ' примеров с кадрами';
            }
        }
        return $parts;
    }

    public static function head() {
        if (!self::is_page()) {
            return;
        }
        $item = self::current_item();
        if ($item) {
            $desc = 'Готовый промт: ' . mb_substr((string) $item['prompt'], 0, 150, 'UTF-8');
            $canonical = self::url((string) $item['slug']);
        } else {
            $rubric = self::current_rubric();
            $page = (int) get_query_var('gs_prompt_page');
            $page = $page > 1 ? $page : 1;
            if ($rubric !== '') {
                $desc = GS_Prompts::rubric_lead($rubric) . '. Готовые промты с примерами кадров: '
                    . 'смотрите снимок, копируйте текст или нажмите «Повторить фото».';
                $canonical = self::rubric_url($rubric, $page);
            } else {
                $n = GS_Prompts::count();
                $desc = 'Каталог готовых промтов для фото: ' . $n . ' примеров с кадрами. '
                    . 'Поиск по словам, рубрики, кнопка «Повторить фото» — результат сразу на сайте.';
                $canonical = self::page_url($page);
            }
        }
        echo "\n" . '<meta name="description" content="' . esc_attr($desc) . '">' . "\n";
        echo '<link rel="canonical" href="' . esc_url($canonical) . '">' . "\n";
    }

    /* ---------------------------------------------------------------------
     * Липкая плашка
     *
     * В каталоге зовём в генератор фото: человек пришёл за промтом, а
     * промт без генерации — просто текст.
     * ------------------------------------------------------------------ */

    public static function sticky() {
        if (!self::is_page() || !class_exists('GS_Sticky')) {
            return;
        }
        $item = self::current_item();
        $url = class_exists('GS_Neurohub') ? GS_Neurohub::url() : home_url('/neurohub/');
        if ($item) {
            $url = add_query_arg('p', rawurlencode((string) $item['prompt']), $url);
            $text = 'Этот промт уже вписан — загрузите фото и получите свой кадр.';
            $cta = 'Повторить фото';
        } else {
            $text = 'Выберите промт и сделайте такой же кадр со своим лицом.';
            $cta = 'Открыть генератор';
        }
        GS_Sticky::render_card(array(
            'url'   => $url,
            'title' => 'Сделать фото по промту',
            'text'  => $text,
            'cta'   => $cta,
        ));
    }

    /* ---------------------------------------------------------------------
     * Вывод
     * ------------------------------------------------------------------ */

    public static function render($atts = array()) {
        $item = self::current_item();
        ob_start();
        if ($item) {
            self::render_one($item);
        } else {
            self::render_landing();
        }
        return (string) ob_get_clean();
    }

    /** Ссылка в генератор с уже вписанным промтом. */
    public static function make_url($prompt) {
        $url = class_exists('GS_Neurohub') ? GS_Neurohub::url() : home_url('/neurohub/');
        return add_query_arg('p', rawurlencode((string) $prompt), $url);
    }

    private static function img($item) {
        $file = (string) ($item['image'] ?? '');
        if ($file === '') {
            return '';
        }
        return GS_Prompts::img_url() . '/' . ltrim($file, '/');
    }

    /* ----------------------------------------------------------- Посадочная */

    private static function render_landing() {
        $q      = isset($_GET['q']) ? sanitize_text_field(wp_unslash((string) $_GET['q'])) : '';
        $rubric = self::current_rubric();
        $page   = max(1, (int) get_query_var('gs_prompt_page'));

        $found = GS_Prompts::search($q, $rubric);
        if ($q !== '') {
            GS_Prompts::log_query($q, count($found));
        }

        $total = count($found);
        $pages = max(1, (int) ceil($total / GS_Prompts::PER_PAGE));
        $page  = min($page, $pages);
        $slice = array_slice($found, ($page - 1) * GS_Prompts::PER_PAGE, GS_Prompts::PER_PAGE);

        $all = GS_Prompts::count();
        $counts = GS_Prompts::rubric_counts();
        $tg = 'https://t.me/' . GS_Prompts::TG_CHANNEL;
        ?>
        <div class="gs-pr">

            <section class="gs-pr__hero">
                <?php self::hero_wall(); ?>
                <div class="gs-pr__wrap gs-pr__hero-in">
                    <p class="gs-pr__badge">Промты для фото · <?php echo (int) $all; ?> готовых примеров</p>
                    <h1 class="gs-pr__h1">Готовые промты для фото — с кадром, который они дают</h1>
                    <p class="gs-pr__lead">
                        Каждый промт показан вместе со снимком: видно, что получится,
                        ещё до генерации. Скопируйте текст себе или нажмите
                        «Повторить фото» — и сделайте такой же кадр со своим лицом.
                    </p>

                    <div class="gs-pr__choice">
                        <a class="gs-pr__pick gs-pr__pick--main"
                           href="#<?php echo self::ad_phrase() !== '' && !$q ? 'gs-pr-picked' : 'gs-pr-catalog'; ?>">
                            <span class="gs-pr__pick-k">Смотреть на сайте</span>
                            <span class="gs-pr__pick-t">
                                Весь каталог ниже: поиск по словам и рубрики.
                                Ничего не нужно устанавливать и никуда переходить.
                            </span>
                            <span class="gs-pr__pick-go">Открыть каталог ↓</span>
                        </a>
                        <a class="gs-pr__pick" href="<?php echo esc_url($tg); ?>"
                           target="_blank" rel="noopener">
                            <span class="gs-pr__pick-k">Открыть в Telegram</span>
                            <span class="gs-pr__pick-t">
                                Тот же поток промтов в канале: новые связки каждый день,
                                сразу в ленте.
                            </span>
                            <span class="gs-pr__pick-go">Перейти в канал →</span>
                        </a>
                    </div>

                    <ul class="gs-pr__facts">
                        <li>Каждый промт — с готовым снимком</li>
                        <li>Поиск и <?php echo (int) count(GS_Prompts::rubrics()); ?> рубрик</li>
                        <li>Кнопка «Повторить фото» на каждой странице</li>
                    </ul>
                </div>
            </section>

            <?php self::picked($q, $rubric); ?>

            <section class="gs-pr__sec" id="gs-pr-catalog">
                <div class="gs-pr__wrap">
                    <h2 class="gs-pr__h2">
                        <?php echo $rubric !== ''
                            ? esc_html(GS_Prompts::rubric_title($rubric) . ' промты')
                            : 'Каталог промтов'; ?>
                    </h2>

                    <form class="gs-pr__search" method="get"
                          action="<?php echo esc_url($rubric !== '' ? self::rubric_url($rubric) : self::url()); ?>">
                        <span class="gs-pr__search-ic" aria-hidden="true">
                            <svg viewBox="0 0 24 24" width="20" height="20" fill="none"
                                 stroke="currentColor" stroke-width="2" stroke-linecap="round">
                                <circle cx="11" cy="11" r="7"></circle><path d="M20 20l-3.5-3.5"></path>
                            </svg>
                        </span>
                        <input type="search" name="q" value="<?php echo esc_attr($q); ?>"
                               placeholder="Что нужно снять: новогодний парный, деловой портрет, с машиной…"
                               aria-label="Поиск промтов">
                        <button type="submit">Найти</button>
                    </form>

                    <?php self::rubricator($rubric, $q, $counts, $all); ?>

                    <?php if ($rubric !== ''): ?>
                        <p class="gs-pr__sub"><?php echo esc_html(GS_Prompts::rubric_lead($rubric)); ?></p>
                    <?php endif; ?>

                    <?php if (!$slice): ?>
                        <div class="gs-pr__empty">
                            <p><strong>По этому запросу пока ничего нет.</strong></p>
                            <p>Запрос мы записали — такие пробелы и показывают, что добавить
                                в каталог в первую очередь. Попробуйте слово попроще
                                («парный», «студия», «новогодний») или
                                <a href="<?php echo esc_url(self::make_url($q !== '' ? $q : 'фотореалистичный портрет')); ?>">сделайте
                                кадр по своему описанию</a>.</p>
                        </div>
                    <?php else: ?>
                        <p class="gs-pr__count">
                            <?php if ($q !== '' || $rubric !== ''): ?>
                                Найдено: <strong><?php echo (int) $total; ?></strong>
                            <?php else: ?>
                                Всего промтов: <strong><?php echo (int) $total; ?></strong>
                            <?php endif; ?>
                        </p>
                        <div class="gs-pr__mosaic">
                            <?php foreach ($slice as $n => $it): ?>
                                <?php self::card($it, self::tile_size($n)); ?>
                            <?php endforeach; ?>
                        </div>
                        <?php self::pagination($page, $pages, $q, $rubric); ?>
                    <?php endif; ?>
                </div>
            </section>
        </div>
        <?php
    }

    /**
     * Подбор под фразу из объявления.
     *
     * Человек пришёл по рекламе с конкретным запросом — «детские
     * новогодние», «с машиной», «для мужчин». Показывать ему сразу общую
     * витрину значит заставить искать заново то, за что мы уже заплатили
     * клик. Поэтому подходящие кадры идут первыми, а весь каталог —
     * следом: сузить до одной рубрики и оставить человека с пустой
     * страницей было бы хуже.
     */
    private static function picked($q, $rubric) {
        if ($q !== '') {
            return;
        }
        $phrase = self::ad_phrase();
        if ($phrase === '') {
            return;
        }
        // На странице рубрики подбираем внутри неё: объявление привело
        // человека в раздел, уводить его из раздела незачем.
        $items = GS_Prompts::match_phrase($phrase, 8, $rubric);
        // Фразу записываем в тот же журнал, что и поиск по сайту, — так
        // видно и то, за какие запросы мы платим, и то, по каким из них
        // показать нечего.
        GS_Prompts::log_query($phrase, count($items), 'ad');
        if (!$items) {
            return;
        }
        $near = GS_Prompts::detect_rubrics(implode(' ', GS_Prompts::phrase_words($phrase)));
        ?>
        <section class="gs-pr__sec gs-pr__sec--picked" id="gs-pr-picked">
            <div class="gs-pr__wrap">
                <p class="gs-pr__badge gs-pr__badge--soft">Подобрали по вашему запросу</p>
                <h2 class="gs-pr__h2">«<?php echo esc_html($phrase); ?>»</h2>
                <p class="gs-pr__sub">
                    Вот что подходит ближе всего. Нажмите «Повторить фото» — промт
                    уже вписан, останется загрузить своё фото.
                </p>
                <div class="gs-pr__mosaic">
                    <?php foreach ($items as $n => $it): ?>
                        <?php self::card($it, self::tile_size($n)); ?>
                    <?php endforeach; ?>
                </div>
                <p class="gs-pr__picked-more">
                    <?php if ($near && $rubric === ''): ?>
                        <a class="gs-pr__chip is-on" href="<?php echo esc_url(self::rubric_url($near[0])); ?>">
                            Вся рубрика «<?php echo esc_html(GS_Prompts::rubric_title($near[0])); ?>»
                        </a>
                    <?php endif; ?>
                    <a class="gs-pr__chip" href="#gs-pr-catalog">Весь каталог ниже ↓</a>
                </p>
            </div>
        </section>
        <?php
    }

    /**
     * Рубрикатор под поисковой строкой.
     *
     * Показываем все рубрики, даже пустые: это ещё и карта раздела для
     * человека с рекламы — он должен сразу видеть, что тут есть, а пустая
     * рубрика честно открывается и предлагает сделать кадр по описанию.
     */
    private static function rubricator($rubric, $q, $counts, $all) {
        $q_arg = $q !== '' ? array('q' => $q) : array();
        ?>
        <nav class="gs-pr__rubrics" aria-label="Рубрики промтов">
            <a class="gs-pr__chip<?php echo $rubric === '' ? ' is-on' : ''; ?>"
               href="<?php echo esc_url($q_arg ? add_query_arg($q_arg, self::url()) : self::url()); ?>">
                Все<?php if ($all > 0): ?> <span><?php echo (int) $all; ?></span><?php endif; ?>
            </a>
            <?php foreach (GS_Prompts::rubrics() as $key => $r): ?>
                <?php $n = (int) ($counts[$key] ?? 0); ?>
                <a class="gs-pr__chip<?php echo $rubric === $key ? ' is-on' : ''; ?><?php echo $n === 0 ? ' is-thin' : ''; ?>"
                   href="<?php echo esc_url($q_arg
                        ? add_query_arg($q_arg, self::rubric_url($key))
                        : self::rubric_url($key)); ?>"
                   title="<?php echo esc_attr($r[1]); ?>">
                    <?php echo esc_html($r[0]); ?><?php if ($n > 0): ?> <span><?php echo $n; ?></span><?php endif; ?>
                </a>
            <?php endforeach; ?>
        </nav>
        <?php
    }

    /**
     * Размер плитки в мозаике.
     *
     * Ровная сетка из одинаковых прямоугольников выглядит как таблица, а
     * здесь главное — сами кадры. Поэтому ширина у плиток двух видов
     * (четверть и половина ряда), а высота четырёх — рисунок повторяется
     * каждые десять карточек и всегда складывается в целые ряды, так что
     * дырок в кладке не остаётся.
     */
    private static function tile_size($n) {
        $plan = array('is-xl', 'is-s', 'is-m', 'is-s', 'is-t',
                      'is-w', 'is-s', 'is-t', 'is-s', 'is-m');
        return $plan[$n % count($plan)];
    }

    /**
     * Стена кадров за первым экраном.
     *
     * Лучшая реклама каталога промтов — сами кадры, поэтому фон собран из
     * настоящих карточек, а не из абстрактной картинки. Колонки едут в
     * разные стороны и притушены плотной заливкой, чтобы заголовок читался.
     *
     * Если каталог ещё пуст, стена просто не печатается — остаются
     * цветные разводы, и первый экран не ломается.
     */
    private static function hero_wall() {
        $items = GS_Prompts::load();
        $shots = array();
        foreach ($items as $it) {
            $u = self::img($it);
            if ($u !== '') {
                $shots[] = $u;
            }
        }
        if (count($shots) < 8) {
            return;
        }
        // Берём вперемешку, но одинаково при каждой загрузке: случайный
        // порядок ломал бы кэш страницы.
        $pick = array();
        $step = max(1, (int) floor(count($shots) / 24));
        for ($i = 0; $i < count($shots) && count($pick) < 24; $i += $step) {
            $pick[] = $shots[$i];
        }
        $cols = array(array(), array(), array(), array());
        foreach ($pick as $i => $u) {
            $cols[$i % 4][] = $u;
        }
        ?>
        <div class="gs-pr__wall" aria-hidden="true">
            <?php foreach ($cols as $n => $col): ?>
                <?php if (!$col) { continue; } ?>
                <div class="gs-pr__wall-col gs-pr__wall-col--<?php echo (int) $n; ?>">
                    <?php /* дважды — чтобы лента ехала без шва */ ?>
                    <?php foreach (array_merge($col, $col) as $u): ?>
                        <img src="<?php echo esc_url($u); ?>" alt="" loading="lazy" decoding="async">
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        </div>
        <div class="gs-pr__wall-veil" aria-hidden="true"></div>
        <?php
    }

    /**
     * Карточка промта.
     *
     * Кадр занимает плитку целиком, название лежит поверх него на
     * затемнении — так витрина читается как лента снимков, а не как
     * таблица с подписями. Кнопка «Повторить фото» выезжает при наведении
     * и остаётся на виду там, где наведения нет (телефон).
     */
    private static function card($item, $size = '') {
        $url = self::url((string) $item['slug']);
        $img = self::img($item);
        $cls = 'gs-pr__card' . ($size !== '' ? ' ' . $size : '');
        ?>
        <article class="<?php echo esc_attr($cls); ?>">
            <a class="gs-pr__card-link" href="<?php echo esc_url($url); ?>">
                <span class="gs-pr__card-ph">
                    <?php if ($img !== ''): ?>
                        <img src="<?php echo esc_url($img); ?>"
                             alt="<?php echo esc_attr((string) $item['title']); ?>"
                             loading="lazy" decoding="async">
                    <?php endif; ?>
                </span>
                <span class="gs-pr__card-veil" aria-hidden="true"></span>
                <span class="gs-pr__card-txt">
                    <span class="gs-pr__card-t"><?php echo esc_html((string) $item['title']); ?></span>
                    <span class="gs-pr__card-p"><?php
                        echo esc_html(mb_substr((string) $item['prompt'], 0, 120, 'UTF-8')); ?>…</span>
                </span>
            </a>
            <a class="gs-pr__card-go" href="<?php echo esc_url(self::make_url($item['prompt'])); ?>">Повторить фото</a>
            <?php if (current_user_can('manage_options')): ?>
                <button type="button" class="gs-pr__card-del" data-gs-del="<?php echo esc_attr((string) $item['slug']); ?>"
                        title="Убрать карточку из каталога">Удалить</button>
            <?php endif; ?>
        </article>
        <?php
    }

    private static function pagination($page, $pages, $q, $rubric) {
        if ($pages < 2) {
            return;
        }
        $args = $q !== '' ? array('q' => $q) : array();
        $link = function ($n) use ($args, $rubric) {
            $u = $rubric !== '' ? self::rubric_url($rubric, $n) : self::page_url($n);
            return $args ? add_query_arg($args, $u) : $u;
        };
        ?>
        <nav class="gs-pr__pager" aria-label="Страницы каталога">
            <?php if ($page > 1): ?>
                <a href="<?php echo esc_url($link($page - 1)); ?>">← Назад</a>
            <?php endif; ?>
            <span>Страница <?php echo (int) $page; ?> из <?php echo (int) $pages; ?></span>
            <?php if ($page < $pages): ?>
                <a href="<?php echo esc_url($link($page + 1)); ?>">Вперёд →</a>
            <?php endif; ?>
        </nav>
        <?php
    }

    /* ------------------------------------------------------- Страница промта */

    private static function render_one($item) {
        $img = self::img($item);
        $make = self::make_url($item['prompt']);
        $rubrics = (array) ($item['rubrics'] ?? array());
        $related = array();
        if ($rubrics) {
            foreach (GS_Prompts::search('', $rubrics[0]) as $r) {
                if ((string) $r['slug'] !== (string) $item['slug']) {
                    $related[] = $r;
                }
                if (count($related) >= 6) {
                    break;
                }
            }
        }
        ?>
        <div class="gs-pr gs-pr--one">
            <div class="gs-pr__wrap">

                <p class="gs-pr__crumbs">
                    <a href="<?php echo esc_url(self::url()); ?>">Каталог промтов</a>
                    <?php if ($rubrics): ?>
                        · <a href="<?php echo esc_url(self::rubric_url($rubrics[0])); ?>">
                            <?php echo esc_html(GS_Prompts::rubric_title($rubrics[0])); ?></a>
                    <?php endif; ?>
                </p>

                <h1 class="gs-pr__h1 gs-pr__h1--one"><?php echo esc_html((string) $item['title']); ?></h1>

                <div class="gs-pr__one-top">
                    <?php if ($img !== ''): ?>
                        <div class="gs-pr__shot">
                            <img src="<?php echo esc_url($img); ?>"
                                 alt="<?php echo esc_attr((string) $item['title']); ?>"
                                 decoding="async">
                        </div>
                    <?php endif; ?>
                    <div class="gs-pr__one-side">
                        <p class="gs-pr__lead">
                            Так выглядит результат. Загрузите своё фото — и получите
                            такой же кадр с собой.
                        </p>
                        <a class="gs-pr__btn gs-pr__btn--big" href="<?php echo esc_url($make); ?>">Повторить фото</a>
                        <?php if ($rubrics): ?>
                            <p class="gs-pr__tags">
                                <?php foreach ($rubrics as $rk): ?>
                                    <a href="<?php echo esc_url(self::rubric_url($rk)); ?>">
                                        <?php echo esc_html(GS_Prompts::rubric_title($rk)); ?></a>
                                <?php endforeach; ?>
                            </p>
                        <?php endif; ?>
                    </div>
                </div>

                <h2 class="gs-pr__h2">Промт целиком</h2>
                <div class="gs-pr__prompt">
                    <pre data-gs-prompt><?php echo esc_html((string) $item['prompt']); ?></pre>
                    <div class="gs-pr__prompt-act">
                        <button type="button" class="gs-pr__copy" data-gs-copy>Скопировать</button>
                        <a class="gs-pr__btn" href="<?php echo esc_url($make); ?>">Повторить фото</a>
                    </div>
                </div>

                <h2 class="gs-pr__h2">Как получить такой кадр</h2>
                <ol class="gs-pr__steps">
                    <li><b>Нажмите «Повторить фото»</b> — промт уже подставлен, вписывать ничего не нужно.</li>
                    <li><b>Загрузите своё фото.</b> Лучше всего подходит снимок, где лицо видно целиком и при ровном свете.</li>
                    <li><b>Заберите результат.</b> Кадр остаётся в истории, его можно скачать.</li>
                </ol>
                <p class="gs-pr__cta-row">
                    <a class="gs-pr__btn gs-pr__btn--big" href="<?php echo esc_url($make); ?>">Повторить фото</a>
                </p>

                <?php if ($related): ?>
                    <h2 class="gs-pr__h2">Похожие промты</h2>
                    <div class="gs-pr__mosaic gs-pr__mosaic--near">
                        <?php foreach ($related as $n => $r): ?>
                            <?php self::card($r, self::tile_size($n + 1)); ?>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <p class="gs-pr__cta-row">
                    <a class="gs-pr__btn gs-pr__btn--big" href="<?php echo esc_url($make); ?>">Повторить фото</a>
                    <a class="gs-pr__btn gs-pr__btn--ghost" href="<?php echo esc_url(self::url()); ?>">Весь каталог</a>
                </p>
            </div>
        </div>
        <?php
    }

}
