<?php
/**
 * Блог в стилистике микросервисов: тёмная тема, карточки постов, читаемая статья
 * и блок перелинковки на инструменты в конце каждого материала.
 *
 * Сам шаблон темы не трогаем — перекрашиваем её разметку и дописываем блок
 * ссылок фильтром the_content.
 */

if (!defined('ABSPATH')) {
    exit;
}

class GS_Blog {

    const OPT_ENABLED = 'gs_blog_restyle';

    public static function boot() {
        add_filter('body_class', array(__CLASS__, 'body_class'));
        add_filter('the_content', array(__CLASS__, 'append_tools_block'), 20);
        // Самым последним: вставки дописывает чужой плагин, и приоритет у
        // него больше нашего — на тридцатке их в тексте ещё нет.
        add_filter('the_content', array(__CLASS__, 'drop_inline_related'), PHP_INT_MAX);
        add_action('wp_head', array(__CLASS__, 'print_faq_schema'), 20);
    }

    /**
     * Разметка вопросов и ответов для статей.
     *
     * Больше половины материалов заканчиваются разбором частых вопросов,
     * оформленным как «<strong>Вопрос?</strong> ответ». Поисковику это
     * обычный текст, хотя по такой паре он умеет показывать раскрывающийся
     * ответ прямо в выдаче. Собираем разметку из того, что уже написано:
     * выдумывать вопросы, которых нет на странице, нельзя.
     */
    public static function print_faq_schema() {
        if (is_admin() || !self::enabled() || !self::is_single_post()) {
            return;
        }
        $post = get_queried_object();
        if (!($post instanceof WP_Post)) {
            return;
        }
        $pairs = self::extract_faq($post->post_content);
        if (count($pairs) < 2) {
            return;
        }

        $items = array();
        foreach ($pairs as $pair) {
            $items[] = array(
                '@type' => 'Question',
                'name'  => $pair['q'],
                'acceptedAnswer' => array(
                    '@type' => 'Answer',
                    'text'  => $pair['a'],
                ),
            );
        }
        $data = array(
            '@context'   => 'https://schema.org',
            '@type'      => 'FAQPage',
            'mainEntity' => $items,
        );
        echo "\n<script type=\"application/ld+json\">"
            . wp_json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            . "</script>\n";
    }

    /**
     * Пары «вопрос — ответ» из блока частых вопросов.
     *
     * @return array<int, array{q:string,a:string}>
     */
    private static function extract_faq($content) {
        // В хранимом тексте абзацы часто без тегов: <p> дорисовывается при
        // выводе. Приводим содержимое к тому виду, который видит читатель,
        // иначе вопрос и ответ не находятся рядом.
        $content = wpautop((string) $content);
        // Ищем именно заголовок блока: словосочетание «частые вопросы»
        // встречается и в оглавлении, и в тексте, а нам нужен раздел.
        if (!preg_match('~<h[23][^>]*>[^<]*(?:частые вопросы|вопросы и ответы|faq)[^<]*</h[23]>~iu', $content, $m, PREG_OFFSET_CAPTURE)) {
            return array();
        }
        $tail = substr($content, $m[0][1] + strlen($m[0][0]));

        // Две манеры записи: жирный вопрос в начале абзаца и вопрос
        // отдельным подзаголовком. Материалы писались в разное время.
        $patterns = array(
            '~<p>\s*<strong>(.+?)</strong>\s*(.+?)</p>~is',
            '~<h[34][^>]*>(.+?)</h[34]>\s*<p>(.+?)</p>~is',
        );

        foreach ($patterns as $pattern) {
            $pairs = array();
            if (!preg_match_all($pattern, $tail, $m, PREG_SET_ORDER)) {
                continue;
            }
            foreach ($m as $hit) {
                $q = trim(wp_strip_all_tags($hit[1]));
                $a = trim(wp_strip_all_tags($hit[2]));
                // Строка без вопроса — это уже не блок вопросов, дальше не идём.
                if (mb_substr($q, -1) !== '?' || $a === '') {
                    break;
                }
                $pairs[] = array('q' => $q, 'a' => $a);
            }
            if (count($pairs) >= 2) {
                return $pairs;
            }
        }
        return array();
    }

    public static function enabled() {
        return (string) get_option(self::OPT_ENABLED, '1') === '1';
    }

    /**
     * Список постов: и архивы, и страница-витрина блога на WPBakery.
     */
    public static function is_blog_list() {
        // Страницу «Нейросети» её плагин отдаёт под видом блога —
        // стили ленты постов ей только мешают.
        if (class_exists('GS_Links') && GS_Links::is_neurohub()) {
            return false;
        }
        if (is_home() || is_archive() || is_category() || is_tag()) {
            return true;
        }
        global $post;
        if ($post instanceof WP_Post && $post->post_type === 'page' && $post->post_name === 'blog') {
            return true;
        }
        return false;
    }

    public static function is_single_post() {
        return is_singular('post');
    }

    public static function body_class($classes) {
        if (is_admin() || !self::enabled()) {
            return $classes;
        }
        if (self::is_single_post()) {
            $classes[] = 'gs-post-page';
            $classes[] = 'gs-chrome';
        } elseif (self::is_blog_list()) {
            $classes[] = 'gs-blog-page';
            $classes[] = 'gs-chrome';
        }
        return $classes;
    }

    /**
     * Убирает чужие вставки «Читать …» из статей документных кластеров.
     *
     * Плагин похожих записей ставит три ссылки прямо посреди текста и
     * подбирает их по всему блогу: в статье о разводе так оказались «Промты
     * для Sora 2» и два гида по озвучке. Для статьи про нейросети это
     * уместная перелинковка, для юридической — шум, который сбивает с
     * задачи и уводит с воронки.
     */
    public static function drop_inline_related($content) {
        if (is_admin() || !is_singular('post') || !in_the_loop() || !is_main_query()) {
            return $content;
        }
        if (!class_exists('GS_Legal') || !GS_Legal::is_doc_post()) {
            return $content;
        }
        // Вставка помечена своим комментарием — по нему и находим, не
        // полагаясь на классы: они у плагина случайные на каждую запись.
        $clean = preg_replace(
            '~<div[^>]*>\s*<a\b[^>]*>\s*<!--\s*INLINE RELATED POSTS.*?</a>\s*</div>~isu',
            '', $content
        );
        return $clean === null ? $content : $clean;
    }

    /**
     * В конце статьи — ссылки на инструменты. Это и полезно читателю,
     * и раздаёт вес с растущего блога на посадочные страницы.
     */
    public static function append_tools_block($content) {
        if (is_admin() || !self::enabled() || !self::is_single_post() || !in_the_loop() || !is_main_query()) {
            return $content;
        }
        return $content . self::tools_block();
    }

    /**
     * Иконка под инструмент. Карточки без картинки читались одинаково, и
     * глазу не за что было зацепиться при быстрой прокрутке.
     */
    private static function tool_icon($title) {
        // Порядок важен: «каталог звуков» ловился на слове «звук» и получал
        // ту же иконку, что генератор.
        $map = array(
            'каталог'     => 'db',
            'вокал'       => 'flow',
            'спецэффект'  => 'ai',
            'звук'        => 'ai',
            'аватар'      => 'users',
            'расшифровк'  => 'doc',
            'видео'       => 'speed',
            'музык'       => 'idea',
            'песн'        => 'send',
            'текст'       => 'doc',
            'обложк'      => 'target',
            'голос'       => 'support',
            'дубляж'      => 'chat',
            'презентац'   => 'chart',
            'качеств'     => 'growth',
            'шум'         => 'shield',
        );
        $lower = function_exists('mb_strtolower') ? mb_strtolower($title) : strtolower($title);
        foreach ($map as $needle => $icon) {
            if (mb_strpos($lower, $needle) !== false) {
                return $icon;
            }
        }
        return 'rocket';
    }

    /**
     * Инструменты в конце статьи — лентой с прокруткой.
     *
     * Сеткой из одиннадцати карточек блок занимал целый экран и обрывал
     * чтение: после статьи человек упирался в стену одинаковых плашек.
     * Лента показывает три-четыре карточки, остальные — движением вбок.
     * Прокрутка своя, браузерная: стрелки только подталкивают её, поэтому
     * без скриптов блок остаётся рабочим, просто без кнопок.
     */
    public static function tools_block() {
        $tools = array(
            array(
                'url'   => GS_Pages::get_studio_url(),
                'title' => 'Генератор звуков и спецэффектов',
                'text'  => 'Опишите звук словами — нейросеть соберёт готовый эффект в MP3.',
                'cta'   => 'Создать звук',
            ),
            array(
                'url'   => GS_Catalog::base_url(),
                'title' => 'Каталог звуков',
                'text'  => 'Больше 12 000 готовых звуков в 945 категориях — слушайте и скачивайте бесплатно.',
                'cta'   => 'Открыть каталог',
            ),
        );

        foreach (GS_Lab::available_services() as $service) {
            $tools[] = array(
                'url'   => GS_Lab::get_url($service['id']),
                'title' => $service['menu'],
                'text'  => $service['lead'],
                'cta'   => $service['menu'],
            );
        }

        $brand = class_exists('GS_Brand');

        ob_start();
        ?>
        <aside class="gs-post-tools" data-gs-carousel>
            <div class="gs-post-tools__head">
                <h2 class="gs-post-tools__title">Попробуйте инструменты <span class="gs-nobr">Genius-bot</span></h2>
                <div class="gs-post-tools__nav">
                    <button type="button" class="gs-post-tools__arrow" data-gs-prev
                            aria-label="Предыдущие инструменты">&#8249;</button>
                    <button type="button" class="gs-post-tools__arrow" data-gs-next
                            aria-label="Следующие инструменты">&#8250;</button>
                </div>
            </div>
            <div class="gs-post-tools__viewport" data-gs-track tabindex="0">
                <div class="gs-post-tools__track">
                    <?php foreach ($tools as $tool): ?>
                        <article class="gs-post-tools__card">
                            <?php if ($brand): ?>
                                <span class="gs-post-tools__icon">
                                    <?php echo GS_Brand::icon_tag(self::tool_icon($tool['title']), 'soft', 'gs-post-tools__img'); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                                </span>
                            <?php endif; ?>
                            <h3><a href="<?php echo esc_url($tool['url']); ?>"><?php echo esc_html($tool['title']); ?></a></h3>
                            <p><?php echo esc_html($tool['text']); ?></p>
                            <a class="gs-post-tools__cta" href="<?php echo esc_url($tool['url']); ?>"><?php echo esc_html($tool['cta']); ?></a>
                        </article>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="gs-post-tools__rail"><span data-gs-bar></span></div>
        </aside>
        <?php
        return ob_get_clean();
    }
}
