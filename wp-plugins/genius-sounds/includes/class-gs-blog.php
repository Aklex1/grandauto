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
        add_filter('the_content', array(__CLASS__, 'promt_links_catalog'), 21);
        // Самым последним: вставки дописывает чужой плагин, и приоритет у
        // него больше нашего — на тридцатке их в тексте ещё нет.
        add_filter('the_content', array(__CLASS__, 'drop_inline_related'), PHP_INT_MAX);
        add_action('wp_head', array(__CLASS__, 'print_faq_schema'), 20);
    }

    /**
     * В блоке «Где брать ещё промты» первым идёт наш каталог.
     *
     * Блок записан в тексте шестидесяти пяти статей и ведёт в три места:
     * канал в Telegram, бот и канал в MAX. Все три — наружу и с nofollow.
     * Собственного каталога на 1156 кадров в этом списке не было: статья
     * про промты отправляла читателя куда угодно, только не на страницу,
     * которую мы же и продвигаем.
     *
     * Правим на выводе, а не в тексте статей: так же само встанет в
     * статьях, которые появятся позже.
     */
    public static function promt_links_catalog($html) {
        if (is_admin() || !is_singular() || !in_the_loop() || !is_main_query()) {
            return $html;
        }
        if (strpos($html, 'gs-promt__links') === false || !class_exists('GS_Prompts_Page')) {
            return $html;
        }
        $url = GS_Prompts_Page::url();
        if ($url === '' || strpos($html, (string) $url) !== false) {
            return $html;
        }
        $count = class_exists('GS_Prompts') ? (int) GS_Prompts::count() : 0;
        $line = '<p><strong>Каталог промтов на сайте</strong>'
              . ($count > 0 ? ' — ' . $count . ' готовых кадров с примерами' : '')
              . ': <a href="' . esc_url($url) . '">открыть каталог</a>';

        // Рубрику подбираем по названию статьи теми же словами, по которым
        // раскладываются сами промты: статья про фото девушки ведёт в
        // «Женские», про машину — в «Авто». Так ссылка попадает ближе к
        // тому, зачем человек читал.
        $rubrics = class_exists('GS_Prompts') ? GS_Prompts::detect_rubrics((string) get_the_title()) : array();
        if ($rubrics) {
            $key = (string) $rubrics[0];
            $line .= '. Ближе к теме статьи — рубрика <a href="' . esc_url(GS_Prompts_Page::rubric_url($key)) . '">«'
                   . esc_html(GS_Prompts::rubric_title($key)) . '»</a>';
        }
        $line .= '</p>';

        return (string) preg_replace(
            '~(<div class="gs-promt__links"[^>]*>)~i',
            '$1' . "\n    " . $line,
            $html,
            1
        );
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
        $post = get_queried_object();
        return $content . self::tools_block($post instanceof WP_Post ? $post : null);
    }

    /**
     * Насколько инструмент близок статье.
     *
     * Раньше под каждой статьёй лежала одна и та же лента из тринадцати
     * карточек в одном и том же порядке. Для читателя это стена одинаковых
     * плашек, для поиска — ссылка без темы: про расшифровку записи человек
     * читает статью про расшифровку, а первой карточкой ему показывают
     * генератор звуков.
     *
     * Считаем просто: сколько значимых слов из названия инструмента
     * встретилось в заголовке, адресе и тексте статьи. Заголовок весит
     * больше текста — он и есть тема.
     */
    private static function tool_score($tool, $haystack) {
        $words = self::tool_words($tool);
        $score = 0;
        $strong = false;
        foreach ($words as $word) {
            $stem = mb_substr($word, 0, max(4, mb_strlen($word) - 2));
            if ($stem === '' || mb_strlen($stem) < 4) {
                continue;
            }
            if (mb_strpos($haystack['title'], $stem) !== false) {
                $score += 5;
                $strong = true;
            }
            if (mb_strpos($haystack['slug'], $stem) !== false) {
                $score += 3;
                $strong = true;
            }
            if (mb_strpos($haystack['text'], $stem) !== false) {
                $score += 1;
            }
        }
        return array('score' => $score, 'strong' => $strong);
    }

    /**
     * Слова, по которым узнаём тему инструмента.
     *
     * Одного названия мало и оно же подводит: в «убрать вокал» слово
     * «убрать» встречается в любой статье про обработку, и статья про
     * промты для фото получала ссылку на снятие вокала. Поэтому к названию
     * добавлены слова, которыми тему называют в текстах, а общие глаголы
     * из названий выброшены.
     */
    private static function tool_words($tool) {
        $title = function_exists('mb_strtolower') ? mb_strtolower((string) $tool['title']) : strtolower((string) $tool['title']);
        $skip = array('убрать', 'создать', 'сделать', 'улучшить', 'оживить', 'для', 'своим');
        $words = array();
        foreach ((array) preg_split('~[^а-яёa-z0-9]+~u', $title, -1, PREG_SPLIT_NO_EMPTY) as $word) {
            if (mb_strlen($word) >= 4 && !in_array($word, $skip, true)) {
                $words[] = $word;
            }
        }

        $extra = array(
            'вокал'       => array('минусовка', 'караоке', 'минус'),
            'шум'         => array('шумоподавление', 'очистка', 'помехи'),
            'расшифровка' => array('транскрибация', 'субтитры', 'стенограмма'),
            'аватар'      => array('липсинк', 'говорящий'),
            'музык'       => array('трек', 'мелодия', 'бит'),
            'песн'        => array('вокал', 'куплет', 'припев'),
            'фотосесс'    => array('портрет', 'снимок', 'фотография', 'фото'),
            'обложк'      => array('ковер', 'артворк'),
            'дубляж'      => array('озвучка', 'перевод'),
            'презентац'   => array('слайды', 'питч'),
            'качеств'     => array('апскейл', 'разрешение'),
            // «Эффект» сюда не годится: статья «как убрать эффект нейросети»
            // — про фотографии, а не про звук.
            'звук'        => array('спецэффект'),
        );
        foreach ($extra as $needle => $more) {
            if (mb_strpos($title, $needle) !== false) {
                $words = array_merge($words, $more);
            }
        }
        return array_values(array_unique($words));
    }

    /** Текст статьи в удобном для сравнения виде. */
    private static function haystack($post) {
        $lower = function_exists('mb_strtolower') ? 'mb_strtolower' : 'strtolower';
        if (!$post instanceof WP_Post) {
            return array('title' => '', 'slug' => '', 'text' => '');
        }
        $text = wp_strip_all_tags((string) $post->post_content);
        return array(
            'title' => call_user_func($lower, (string) $post->post_title),
            'slug'  => call_user_func($lower, (string) $post->post_name),
            'text'  => call_user_func($lower, mb_substr($text, 0, 4000)),
        );
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
    public static function tools_block($post = null) {
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

        // Ближайшие теме статьи — вперёд; остальные остаются в ленте
        // в прежнем порядке.
        $top = array();
        if ($post instanceof WP_Post) {
            $hay = self::haystack($post);
            $scored = array();
            foreach ($tools as $i => $tool) {
                $scored[$i] = self::tool_score($tool, $hay);
            }
            uasort($scored, function ($a, $b) {
                return (int) $b['score'] <=> (int) $a['score'];
            });
            $order = array();
            foreach ($scored as $i => $hit) {
                $order[] = $tools[$i];
                // В строку «по теме статьи» попадают только те, чью тему
                // видно в заголовке или адресе: совпадения по тексту
                // набирают любые статьи про обработку чего угодно.
                if (!empty($hit['strong']) && $hit['score'] >= 5 && count($top) < 3) {
                    $top[] = $tools[$i];
                }
            }
            $tools = $order;
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
            <?php if ($top): ?>
                <p class="gs-post-tools__lead">По теме статьи:<?php foreach ($top as $i => $tool): ?><?php echo $i ? ',' : ''; ?> <a href="<?php echo esc_url($tool['url']); ?>"><?php echo esc_html($tool['title']); ?></a><?php endforeach; ?>.</p>
            <?php endif; ?>
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
