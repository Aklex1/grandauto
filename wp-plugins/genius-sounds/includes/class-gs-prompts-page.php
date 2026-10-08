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
        // Своя липкая плашка: штатная работает только на статьях.
        add_action('wp_footer', array(__CLASS__, 'sticky'), 5);
    }

    public static function register_shortcodes() {
        add_shortcode(self::SHORTCODE, array(__CLASS__, 'render'));
    }

    /* ---------------------------------------------------------------------
     * Страница и адреса
     * ------------------------------------------------------------------ */

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
        add_rewrite_rule('^' . $s . '/([^/]+)/?$',
            'index.php?page_id=' . $pid . '&gs_prompt_slug=$matches[1]', 'top');
    }

    public static function query_vars($vars) {
        $vars[] = 'gs_prompt_slug';
        $vars[] = 'gs_prompt_page';
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
            $parts['title'] = 'Готовые промты для фото: ' . GS_Prompts::count() . ' примеров с кадрами';
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
            $n = GS_Prompts::count();
            $desc = 'Каталог готовых промтов для фото: ' . $n . ' примеров с кадрами. '
                . 'Поиск по словам, рубрики, кнопка «Повторить фото» — результат сразу на сайте.';
            $page = (int) get_query_var('gs_prompt_page');
            $canonical = self::page_url($page > 1 ? $page : 1);
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
        $rubric = isset($_GET['r']) ? sanitize_key(wp_unslash((string) $_GET['r'])) : '';
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
                        <a class="gs-pr__pick gs-pr__pick--main" href="#gs-pr-catalog">
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

            <section class="gs-pr__sec" id="gs-pr-catalog">
                <div class="gs-pr__wrap">
                    <h2 class="gs-pr__h2">Каталог промтов</h2>

                    <form class="gs-pr__search" method="get" action="<?php echo esc_url(self::url()); ?>">
                        <input type="search" name="q" value="<?php echo esc_attr($q); ?>"
                               placeholder="Что нужно снять: новогодний парный, деловой портрет, с машиной…"
                               aria-label="Поиск промтов">
                        <?php if ($rubric !== ''): ?>
                            <input type="hidden" name="r" value="<?php echo esc_attr($rubric); ?>">
                        <?php endif; ?>
                        <button type="submit">Найти</button>
                    </form>

                    <nav class="gs-pr__rubrics" aria-label="Рубрики">
                        <a class="gs-pr__chip<?php echo $rubric === '' ? ' is-on' : ''; ?>"
                           href="<?php echo esc_url(add_query_arg(array_filter(array('q' => $q)), self::url())); ?>">
                            Все <span><?php echo (int) $all; ?></span>
                        </a>
                        <?php foreach (GS_Prompts::rubrics() as $key => $r): ?>
                            <?php if (empty($counts[$key])) { continue; } ?>
                            <a class="gs-pr__chip<?php echo $rubric === $key ? ' is-on' : ''; ?>"
                               href="<?php echo esc_url(add_query_arg(array_filter(array('q' => $q, 'r' => $key)), self::url())); ?>"
                               title="<?php echo esc_attr(GS_Prompts::rubric_lead($key)); ?>">
                                <?php echo esc_html($r[0]); ?> <span><?php echo (int) $counts[$key]; ?></span>
                            </a>
                        <?php endforeach; ?>
                    </nav>

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
                        <div class="gs-pr__grid">
                            <?php foreach ($slice as $it): ?>
                                <?php self::card($it); ?>
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

    private static function card($item) {
        $url = self::url((string) $item['slug']);
        $img = self::img($item);
        ?>
        <article class="gs-pr__card">
            <a class="gs-pr__card-img" href="<?php echo esc_url($url); ?>">
                <?php if ($img !== ''): ?>
                    <img src="<?php echo esc_url($img); ?>"
                         alt="<?php echo esc_attr((string) $item['title']); ?>"
                         loading="lazy" decoding="async">
                <?php endif; ?>
            </a>
            <div class="gs-pr__card-b">
                <h3><a href="<?php echo esc_url($url); ?>"><?php echo esc_html((string) $item['title']); ?></a></h3>
                <p><?php echo esc_html(mb_substr((string) $item['prompt'], 0, 110, 'UTF-8')); ?>…</p>
                <a class="gs-pr__card-go" href="<?php echo esc_url(self::make_url($item['prompt'])); ?>">Повторить фото</a>
            </div>
        </article>
        <?php
    }

    private static function pagination($page, $pages, $q, $rubric) {
        if ($pages < 2) {
            return;
        }
        $args = array_filter(array('q' => $q, 'r' => $rubric));
        $link = function ($n) use ($args) {
            $u = self::page_url($n);
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
                        · <a href="<?php echo esc_url(add_query_arg('r', $rubrics[0], self::url())); ?>">
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
                                    <a href="<?php echo esc_url(add_query_arg('r', $rk, self::url())); ?>">
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
                    <div class="gs-pr__grid">
                        <?php foreach ($related as $r): ?>
                            <?php self::card($r); ?>
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
