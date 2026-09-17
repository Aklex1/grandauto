<?php
/**
 * Страница 404 с подсказками, куда идти дальше.
 *
 * Штатная страница темы говорит «не найдено» и оставляет человека в тупике:
 * единственное действие — кнопка «назад» в браузере. Поэтому показываем
 * два блока: свежие статьи из разных рубрик и карточки микросервисов.
 *
 * Статьи берём по одной из рубрики, а не «последние N»: последние почти
 * всегда оказываются из одной серии публикаций, и плитка выходит
 * однообразной именно тогда, когда человеку нужен выбор.
 */

if (!defined('ABSPATH')) {
    exit;
}

class GS_404 {

    /** Сколько статей показываем в плитке. */
    const POSTS = 6;

    public static function boot() {
        add_filter('404_template', array(__CLASS__, 'template'));
        add_filter('body_class', array(__CLASS__, 'body_class'));
    }

    public static function is_page() {
        return !is_admin() && is_404();
    }

    public static function body_class($classes) {
        if (self::is_page()) {
            $classes[] = 'gs-chrome';
            $classes[] = 'gs-404-page';
        }
        return $classes;
    }

    /** Свой шаблон вместо темы: в теме на 404 нет места под блоки. */
    public static function template($template) {
        $own = GS_PLUGIN_DIR . 'templates/404.php';
        return file_exists($own) ? $own : $template;
    }

    /* ---------------------------------------------------------------------
     * Подбор статей
     * ------------------------------------------------------------------ */

    /**
     * По одной свежей статье из каждой рубрики, добор — просто свежими.
     *
     * @return array<int,WP_Post>
     */
    public static function posts() {
        $picked = array();
        $seen = array();

        foreach (self::categories() as $cat) {
            $found = get_posts(array(
                'post_type'        => 'post',
                'post_status'      => 'publish',
                'numberposts'      => 1,
                'cat'              => (int) $cat->term_id,
                'exclude'          => $seen,
                'orderby'          => 'date',
                'order'            => 'DESC',
                'suppress_filters' => true,
            ));
            if ($found) {
                $picked[] = $found[0];
                $seen[] = (int) $found[0]->ID;
            }
            if (count($picked) >= self::POSTS) {
                return $picked;
            }
        }

        // Рубрик оказалось меньше, чем плиток, — добираем свежими.
        $rest = get_posts(array(
            'post_type'        => 'post',
            'post_status'      => 'publish',
            'numberposts'      => self::POSTS - count($picked),
            'exclude'          => $seen,
            'orderby'          => 'date',
            'order'            => 'DESC',
            'suppress_filters' => true,
        ));

        return array_merge($picked, $rest);
    }

    /** Непустые рубрики, самые наполненные — первыми. */
    private static function categories() {
        $cats = get_categories(array(
            'hide_empty' => true,
            'orderby'    => 'count',
            'order'      => 'DESC',
            'number'     => 20,
        ));
        if (!is_array($cats)) {
            return array();
        }
        // «Без рубрики» — не рубрика, а её отсутствие: в подборку не берём.
        return array_values(array_filter($cats, function ($cat) {
            return !in_array($cat->slug, array('uncategorized', 'bez-rubriki'), true)
                && strpos($cat->slug, '%d0%b1%d0%b5%d0%b7') !== 0;
        }));
    }

    /* ---------------------------------------------------------------------
     * Разметка
     * ------------------------------------------------------------------ */

    public static function render() {
        $posts = self::posts();
        $search = get_search_query();

        ob_start();
        ?>
        <div class="gs-wrap gs-studio gs-404">
            <section class="gs-404__head">
                <span class="gs-404__code" aria-hidden="true">404</span>
                <h1 class="gs-404__title">Такой страницы нет</h1>
                <p class="gs-404__lead">
                    Ссылка могла устареть или в адресе опечатка. Ничего страшного —
                    ниже то, ради чего чаще всего приходят на сайт.
                </p>
                <form class="gs-404__search" role="search" method="get" action="<?php echo esc_url(home_url('/')); ?>">
                    <label class="screen-reader-text" for="gs-404-s">Поиск по сайту</label>
                    <input id="gs-404-s" class="gs-input" type="search" name="s"
                           value="<?php echo esc_attr($search); ?>" placeholder="Поиск по сайту">
                    <button class="gs-btn gs-btn--primary" type="submit">Найти</button>
                </form>
            </section>

            <?php if ($posts): ?>
                <section class="gs-404__block">
                    <h2 class="gs-section-title">Почитать в блоге</h2>
                    <div class="gs-404__grid">
                        <?php foreach ($posts as $post): ?>
                            <?php
                            $link = get_permalink($post->ID);
                            $thumb = get_the_post_thumbnail_url($post->ID, 'medium_large');
                            $cats = get_the_category($post->ID);
                            $cat = $cats ? $cats[0] : null;
                            ?>
                            <article class="gs-404__card">
                                <a class="gs-404__cover<?php echo $thumb ? '' : ' is-empty'; ?>" href="<?php echo esc_url($link); ?>" tabindex="-1" aria-hidden="true">
                                    <?php if ($thumb): ?>
                                        <img src="<?php echo esc_url($thumb); ?>" alt="" loading="lazy" decoding="async">
                                    <?php endif; ?>
                                </a>
                                <div class="gs-404__card-body">
                                    <?php if ($cat): ?>
                                        <a class="gs-404__cat" href="<?php echo esc_url(get_category_link($cat->term_id)); ?>"><?php echo esc_html($cat->name); ?></a>
                                    <?php endif; ?>
                                    <h3 class="gs-404__card-title">
                                        <a href="<?php echo esc_url($link); ?>"><?php echo esc_html(get_the_title($post->ID)); ?></a>
                                    </h3>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                    <p class="gs-404__more">
                        <a class="gs-btn gs-btn--ghost" href="<?php echo esc_url(get_permalink(get_option('page_for_posts')) ?: home_url('/blog/')); ?>">Все статьи блога</a>
                    </p>
                </section>
            <?php endif; ?>

            <section class="gs-404__block">
                <h2 class="gs-section-title">Инструменты Genius-bot</h2>
                <div class="gs-404__tools">
                    <?php foreach (self::tools() as $tool): ?>
                        <a class="gs-404__tool" href="<?php echo esc_url($tool['url']); ?>">
                            <span class="gs-404__tool-name"><?php echo esc_html($tool['title']); ?></span>
                            <span class="gs-404__tool-text"><?php echo esc_html($tool['text']); ?></span>
                            <span class="gs-404__tool-go">Открыть</span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </section>
        </div>
        <?php
        return ob_get_clean();
    }

    /** Карточки микросервисов: сначала бесплатное и самое ходовое. */
    private static function tools() {
        $tools = array();

        foreach (GS_Lab::available_services() as $service) {
            $tools[] = array(
                'url'   => GS_Lab::get_url($service['id']),
                'title' => $service['menu'],
                'text'  => $service['badge'] !== '' ? $service['badge'] : $service['menu'],
            );
        }

        $tools[] = array(
            'url'   => GS_Pages::get_studio_url(),
            'title' => 'Генератор звуков',
            'text'  => 'Звук и спецэффект по описанию',
        );
        $tools[] = array(
            'url'   => GS_Catalog::base_url(),
            'title' => 'Каталог звуков',
            'text'  => 'Больше 12 000 готовых звуков',
        );
        $tools[] = array(
            'url'   => GS_Course::get_url(),
            'title' => 'Обучение заработку',
            'text'  => 'Практический курс по нейросетям',
        );

        return array_values(array_filter($tools, function ($tool) {
            return !empty($tool['url']);
        }));
    }
}
