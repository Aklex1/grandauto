<?php
/**
 * Лендинг «Обучение AI-креатора».
 *
 * Отличие от лендинга заработка на нейросетях — не в оформлении, а в
 * источнике программы. Она собрана не из того, что мы умеем показать, а из
 * того, что требуют в вакансиях: 219 объявлений по запросу «AI креатор» на
 * hh.ru разобраны по частоте требований, и каждый модуль закрывает
 * конкретное из них. Поэтому на странице есть блок с цифрами рынка — он
 * же главный аргумент, и он проверяем.
 *
 * Обещаний дохода здесь нет намеренно: вилки взяты из объявлений и названы
 * вилками рынка, а не тем, что заработает выпускник. Непроверяемое обещание
 * бьёт по доверию сильнее, чем помогает конверсии.
 *
 * Заявка уходит общим маршрутом /v1/lead — см. GS_Leads.
 */

if (!defined('ABSPATH')) {
    exit;
}

class GS_Kreator {

    const OPT_PAGE = 'gs_kreator_page';
    const OPT_IMG  = 'gs_kreator_images';
    const SLUG     = 'obuchenie-ai-kreatora';

    /** Метка потока публикаций у статей кластера: по ней ведём их сюда. */
    const LANE = 'kreator';

    /**
     * Цена.
     *
     * Ориентир по рынку: программы «профессия AI-креатор» у крупных школ
     * идут от 36 000 ₽ за курс целиком. Базовый тариф держим ниже этой
     * границы, тариф с наставником — в ней же, но с разбором каждой работы,
     * чего в записанных курсах нет.
     */
    const PRICE_BASE = 24900;
    const PRICE_PRO  = 36900;

    /** Срок обучения. Называется в трёх местах, включая разметку для поиска. */
    const WEEKS = 6;

    /** Телеграм-канал проекта: разборы вакансий, промты и заказы. */
    const TG_URL  = 'https://t.me/neuro_creator_pro';
    const TG_NAME = 'AI-креатор | Заработок на нейросетях';

    /* Цифры рынка. Собраны 20.10.2026 по выдаче hh.ru по запросу
     * «AI креатор»: 219 вакансий всего, разобрано 100 карточек и 14 полных
     * описаний. Дату держим рядом с цифрами — через полгода они другие. */
    const HH_TOTAL   = 219;
    const HH_CARDS   = 100;
    const HH_PAY     = 60;
    const HH_MED_LOW = 70000;
    const HH_MED_TOP = 100000;
    const HH_MAX     = 350000;
    const HH_REMOTE  = 66;
    const HH_NOEXP   = 17;
    const HH_DATE    = '20 октября 2026';

    const SEO_TITLE = 'Обучение AI-креатора: программа по требованиям вакансий';
    const SEO_DESC  = 'Обучение на AI-креатора: вертикальные ролики, генерация кадров, монтаж, звук и контент для продаж. Программа собрана по 219 вакансиям с hh.ru — каждый модуль закрывает требование работодателей. Практика на рабочих сервисах, разбор работ, портфолио из шести работ.';

    public static function boot() {
        add_filter('body_class', array(__CLASS__, 'body_class'));
        add_filter('document_title_parts', array(__CLASS__, 'title_parts'));
        add_action('wp_head', array(__CLASS__, 'head'), 1);
        // На девятом приоритете: тема и прочие фильтры содержимого работают
        // позже, и баннер должен попасть в самое начало статьи.
        add_filter('the_content', array(__CLASS__, 'prepend_banner'), 9);
    }

    public static function register_shortcodes() {
        add_shortcode('genius_kreator', array(__CLASS__, 'render'));
    }

    /* ---------------------------------------------------------------------
     * Страница
     * ------------------------------------------------------------------ */

    public static function ensure_page() {
        $page_id = (int) get_option(self::OPT_PAGE);
        if ($page_id > 0 && get_post($page_id)) {
            $post = get_post($page_id);
            if ($post && trim((string) $post->post_excerpt) === '') {
                wp_update_post(array('ID' => $page_id, 'post_excerpt' => self::SEO_DESC));
            }
            return;
        }
        $existing = get_page_by_path(self::SLUG);
        if ($existing) {
            $page_id = (int) $existing->ID;
            wp_update_post(array(
                'ID'           => $page_id,
                'post_title'   => self::SEO_TITLE,
                'post_content' => '[genius_kreator]',
                'post_excerpt' => self::SEO_DESC,
                'post_status'  => 'publish',
            ));
        } else {
            $page_id = (int) wp_insert_post(array(
                'post_title'   => self::SEO_TITLE,
                'post_content' => '[genius_kreator]',
                'post_excerpt' => self::SEO_DESC,
                'post_status'  => 'publish',
                'post_type'    => 'page',
                'post_name'    => self::SLUG,
            ));
        }
        if ($page_id > 0) {
            update_option(self::OPT_PAGE, $page_id, false);
        }
    }

    public static function get_url() {
        $page_id = (int) get_option(self::OPT_PAGE);
        $url = $page_id > 0 ? get_permalink($page_id) : '';
        return $url ? $url : home_url('/' . self::SLUG . '/');
    }

    public static function is_page() {
        $page_id = (int) get_option(self::OPT_PAGE);
        return !is_admin() && $page_id > 0 && is_page($page_id);
    }

    /**
     * Классы страницы.
     *
     * gs-kr-page красит полотно светлым: это единственная светлая страница
     * плагина, и фон ей нужно перекрашивать на уровне body — тёмный цвет
     * задан там же. Шапку и подвал оставляем тёмными: светлая вставка
     * между ними читается как отдельный разворот, а перекрашивать всю
     * обвязку ради одной страницы значило бы трогать весь сайт.
     */
    public static function body_class($classes) {
        if (self::is_page()) {
            $classes[] = 'gs-studio-page';
            $classes[] = 'gs-chrome';
            $classes[] = 'gs-kr-page';
        }
        return $classes;
    }

    public static function title_parts($parts) {
        if (!self::is_page()) {
            return $parts;
        }
        return array('title' => self::SEO_TITLE);
    }

    /* ---------------------------------------------------------------------
     * Картинки
     * ------------------------------------------------------------------ */

    /**
     * Картинки лендинга: вложения медиатеки, загруженные при сборке страницы.
     *
     * Держим именно идентификаторы, а не адреса: адрес меняется при переносе
     * сайта и при смене года в пути загрузок, а вложение остаётся тем же.
     * Настройкой можно подменить любой кадр, не трогая код.
     */
    const IMAGES = array(
        'hero'    => 7287,
        'video'   => 7241,
        'fashion' => 7242,
        'ugc'     => 7243,
        'cards'   => 7244,
        'mentor'  => 7245,
    );

    public static function image($slug) {
        $images = get_option(self::OPT_IMG, array());
        if (is_array($images) && !empty($images[$slug])) {
            return (string) $images[$slug];
        }
        if (empty(self::IMAGES[$slug])) {
            return '';
        }
        $url = wp_get_attachment_image_url((int) self::IMAGES[$slug], 'full');
        return $url ? (string) $url : '';
    }

    public static function set_images($map) {
        update_option(self::OPT_IMG, array_map('esc_url_raw', (array) $map), false);
    }

    /* ---------------------------------------------------------------------
     * Связь со статьями кластера
     * ------------------------------------------------------------------ */

    /** Статья из кластера о профессии: по метке потока публикаций. */
    public static function is_cluster_post($post = null) {
        $post = $post ? $post : get_post();
        if (!($post instanceof WP_Post) || $post->post_type !== 'post') {
            return false;
        }
        return (string) get_post_meta($post->ID, '_gs_queue_lane', true) === self::LANE;
    }

    /**
     * Мини-баннер на первом экране статьи кластера.
     *
     * Именно в начало: до конца лонгрида на три тысячи слов дочитывают не
     * все, а человек, который читает «сколько зарабатывает AI-креатор»,
     * решает про обучение в первые же минуты.
     */
    public static function prepend_banner($content) {
        if (is_admin() || !is_singular('post') || !in_the_loop() || !is_main_query()) {
            return $content;
        }
        if (!self::is_cluster_post()) {
            return $content;
        }
        return self::banner() . $content;
    }

    public static function banner() {
        $url = self::get_url();
        ob_start();
        ?>
        <aside class="gs-krbanner">
            <svg class="gs-krbanner__art" viewBox="0 0 96 96" aria-hidden="true" focusable="false">
                <defs>
                    <linearGradient id="gs-krb-g" x1="0" y1="0" x2="1" y2="1">
                        <stop offset="0" stop-color="#8b5cf6"/>
                        <stop offset="1" stop-color="#22d3ee"/>
                    </linearGradient>
                </defs>
                <rect x="10" y="22" width="52" height="38" rx="7" fill="none" stroke="url(#gs-krb-g)" stroke-width="3"/>
                <path d="M34 33l14 8-14 8z" fill="url(#gs-krb-g)"/>
                <rect x="68" y="34" width="18" height="30" rx="4" fill="none" stroke="url(#gs-krb-g)" stroke-width="3"/>
                <path d="M16 70h40M16 78h24" stroke="url(#gs-krb-g)" stroke-width="3" stroke-linecap="round"/>
            </svg>
            <div class="gs-krbanner__text">
                <p class="gs-krbanner__title">Обучение AI-креатора: программа по требованиям вакансий</p>
                <?php /* Текст держим в одной строке: баннер печатается до wpautop,
                         и перенос строки в исходнике он превращает в <br>. */ ?>
                <p class="gs-krbanner__lead">Разобрали <?php echo (int) self::HH_TOTAL; ?> вакансий на hh.ru и собрали программу под то, что спрашивают на деле: вертикальные ролики, генерация кадров, монтаж, звук, контент для продаж.</p>
            </div>
            <a class="gs-krbanner__btn" href="<?php echo esc_url($url); ?>">Смотреть программу</a>
        </aside>
        <?php
        return ob_get_clean();
    }

    /* ---------------------------------------------------------------------
     * Иконки и графика
     * ------------------------------------------------------------------ */

    /**
     * Набор иконок.
     *
     * Рисуем контуром в одной сетке 24×24 и одной толщиной линии: иконки
     * из разных наборов рядом заметны сразу, а своя сетка держит их в одном
     * весе. Цвет берётся у текста, поэтому в тёмной и светлой теме иконка
     * ведёт себя как буква.
     */
    private static function icons() {
        return array(
            'film'     => '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M8 4v16M16 4v16M3 12h18"/>',
            'play'     => '<rect x="3" y="5" width="13" height="14" rx="2"/><path d="m16 12 5-3v6z"/>',
            'person'   => '<circle cx="12" cy="8" r="3.2"/><path d="M5.5 20a6.5 6.5 0 0 1 13 0"/>',
            'doc'      => '<path d="M6 3h8l4 4v14H6z"/><path d="M14 3v4h4M9 12h6M9 16h6"/>',
            'star'     => '<path d="m12 3.5 2.6 5.4 5.9.8-4.3 4.1 1 5.9-5.2-2.9-5.2 2.9 1-5.9L3.5 9.7l5.9-.8z"/>',
            'trend'    => '<path d="M3 17l5.5-5.5 3.5 3.5L21 6"/><path d="M15 6h6v6"/>',
            'image'    => '<rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="8.5" cy="10" r="1.6"/><path d="m4 17 5-4.5 4.5 4 3-2.5L20 17"/>',
            'sparkle'  => '<path d="M12 3.5 13.8 9l5.5 1.8-5.5 1.8L12 18l-1.8-5.4L4.7 10.8 10.2 9z"/><path d="M18.5 16.5 19.2 18l1.5.7-1.5.7-.7 1.5-.7-1.5-1.5-.7 1.5-.7z"/>',
            'wave'     => '<path d="M4 12h2M8 8v8M12 5v14M16 9v6M20 12h-2"/>',
            'cart'     => '<path d="M3 5h2.2l2.3 10.2h9.6L19 8H6"/><circle cx="9.5" cy="19" r="1.4"/><circle cx="16.5" cy="19" r="1.4"/>',
            'bulb'     => '<path d="M9.5 17.5h5M10.5 20.5h3"/><path d="M12 3.5a5.5 5.5 0 0 1 3.3 9.9c-.6.5-.9 1.1-.9 1.8H9.6c0-.7-.3-1.3-.9-1.8A5.5 5.5 0 0 1 12 3.5z"/>',
            'flow'     => '<rect x="3" y="4" width="6" height="5" rx="1.5"/><rect x="15" y="4" width="6" height="5" rx="1.5"/><rect x="9" y="15" width="6" height="5" rx="1.5"/><path d="M6 9v3h12V9M12 12v3"/>',
            'check'    => '<rect x="5" y="4" width="14" height="17" rx="2"/><path d="M9 3h6v3H9zM9 12l2 2 4-4"/>',
            'wallet'   => '<rect x="3" y="6" width="18" height="13" rx="2.5"/><path d="M3 10h18M16.5 14.5h1.5"/>',
            'search'   => '<circle cx="11" cy="11" r="6"/><path d="m16 16 4.5 4.5"/>',
            'layers'   => '<path d="m12 3.5 8.5 4.3-8.5 4.3-8.5-4.3z"/><path d="m4 12.5 8 4 8-4M4 16.5l8 4 8-4"/>',
            'camera'   => '<path d="M4 8h3.5l1.5-2h6l1.5 2H20a1 1 0 0 1 1 1v9a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V9a1 1 0 0 1 1-1z"/><circle cx="12" cy="13.5" r="3.4"/>',
            'grid'     => '<rect x="3.5" y="3.5" width="7" height="7" rx="1.5"/><rect x="13.5" y="3.5" width="7" height="7" rx="1.5"/><rect x="3.5" y="13.5" width="7" height="7" rx="1.5"/><rect x="13.5" y="13.5" width="7" height="7" rx="1.5"/>',
            'phone'    => '<rect x="7" y="2.5" width="10" height="19" rx="2.5"/><path d="M10.5 5.5h3"/>',
            'carousel' => '<rect x="7.5" y="5" width="9" height="14" rx="2"/><path d="M4 8v8M20 8v8"/>',
            'clock'    => '<circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/>',
            'money'    => '<circle cx="12" cy="12" r="8.5"/><path d="M12 7v10M9.5 9.5h4a1.8 1.8 0 0 1 0 3.6h-4"/>',
            'remote'   => '<path d="M12 3.5a8.5 8.5 0 1 0 0 17 8.5 8.5 0 0 0 0-17z"/><path d="M3.5 12h17M12 3.5c2.2 2.4 3.3 5.3 3.3 8.5S14.2 18.1 12 20.5c-2.2-2.4-3.3-5.3-3.3-8.5S9.8 5.9 12 3.5z"/>',
            'tg'       => '<path d="M21 4.5 18.2 19c-.2 1-.8 1.2-1.6.8l-4.5-3.3-2.2 2.1c-.2.2-.5.4-.9.4l.3-4.6 8.4-7.6c.4-.3-.1-.5-.6-.2L6.7 11.1 2.3 9.7c-1-.3-1-1 .2-1.4L19.7 3c.8-.3 1.5.2 1.3 1.5z"/>',
        );
    }

    /** Иконка в разметке. Неизвестное имя молча ничего не рисует. */
    public static function icon($name, $class = 'gs-kr__ico') {
        $icons = self::icons();
        if (empty($icons[$name])) {
            return '';
        }
        return '<svg class="' . esc_attr($class) . '" viewBox="0 0 24 24" fill="none" stroke="currentColor"'
             . ' stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'
             . $icons[$name] . '</svg>';
    }

    /**
     * Шкала зарплат.
     *
     * Вилки из объявлений словами не читаются: «от 20 000 до 350 000» в
     * тексте выглядит одинаково с любой другой парой чисел. На шкале сразу
     * видно, что середина рынка — узкая полоса слева, а верхние цифры из
     * заголовков вакансий это хвост.
     */
    public static function salary_chart() {
        $min = 20000;
        $max = self::HH_MAX;
        $pos = function ($value) use ($min, $max) {
            return round((($value - $min) / ($max - $min)) * 100, 2);
        };
        $a = $pos(self::HH_MED_LOW);
        $b = $pos(self::HH_MED_TOP);
        ob_start();
        ?>
        <figure class="gs-kr__chart gs-kr__chart--pay">
            <figcaption class="gs-kr__chart-cap">Вилки в объявлениях, ₽ в месяц</figcaption>
            <div class="gs-kr__pay">
                <svg class="gs-kr__pay-svg" viewBox="0 0 100 18" preserveAspectRatio="none" aria-hidden="true" focusable="false">
                    <rect x="0" y="6" width="100" height="6" rx="3" fill="var(--kr-line)"/>
                    <rect x="<?php echo esc_attr($a); ?>" y="4" width="<?php echo esc_attr($b - $a); ?>" height="10" rx="5" fill="url(#gs-kr-pay-g)"/>
                    <defs>
                        <linearGradient id="gs-kr-pay-g" x1="0" y1="0" x2="1" y2="0">
                            <stop offset="0" stop-color="#5b4ae0"/>
                            <stop offset="1" stop-color="#0ea5b7"/>
                        </linearGradient>
                    </defs>
                </svg>
                <span class="gs-kr__pay-pin" style="left: <?php echo esc_attr($a); ?>%"><b><?php echo esc_html(number_format_i18n(self::HH_MED_LOW)); ?></b><i>медиана «от»</i></span>
                <span class="gs-kr__pay-pin gs-kr__pay-pin--up" style="left: <?php echo esc_attr($b); ?>%"><b><?php echo esc_html(number_format_i18n(self::HH_MED_TOP)); ?></b><i>медиана «до»</i></span>
            </div>
            <div class="gs-kr__pay-axis">
                <span><?php echo esc_html(number_format_i18n($min)); ?> ₽<br><i>минимум в выборке</i></span>
                <span><?php echo esc_html(number_format_i18n($max)); ?> ₽<br><i>максимум в выборке</i></span>
            </div>
        </figure>
        <?php
        return ob_get_clean();
    }

    /**
     * Кольцо опыта: сколько вакансий какой опыт просят.
     *
     * Круг рисуем одной окружностью с разрывами штриха — так сегменты
     * считаются арифметикой процентов, без тригонометрии и без путаницы
     * со знаками в дугах.
     */
    public static function experience_chart() {
        $rows = array(
            array(17, 'Опыт не требуется', '#0f9d58'),
            array(59, 'От года до трёх',   '#5b4ae0'),
            array(23, 'От трёх до шести',  '#0ea5b7'),
            array(1,  'Больше шести лет',  '#94a3b8'),
        );
        $total = 0;
        foreach ($rows as $row) {
            $total += $row[0];
        }
        $circumference = 2 * M_PI * 42;
        $offset = 0;
        ob_start();
        ?>
        <figure class="gs-kr__chart gs-kr__chart--exp">
            <figcaption class="gs-kr__chart-cap">Какой опыт просят в вакансиях</figcaption>
            <div class="gs-kr__exp">
                <svg class="gs-kr__exp-svg" viewBox="0 0 100 100" aria-hidden="true" focusable="false">
                    <?php foreach ($rows as $row) :
                        $len = $circumference * ($row[0] / $total);
                        ?>
                        <circle cx="50" cy="50" r="42" fill="none" stroke="<?php echo esc_attr($row[2]); ?>"
                                stroke-width="14" stroke-linecap="butt"
                                stroke-dasharray="<?php echo esc_attr(round($len, 2) . ' ' . round($circumference - $len, 2)); ?>"
                                stroke-dashoffset="<?php echo esc_attr(round(-$offset, 2)); ?>"
                                transform="rotate(-90 50 50)"/>
                        <?php $offset += $len; ?>
                    <?php endforeach; ?>
                </svg>
                <ul class="gs-kr__exp-legend">
                    <?php foreach ($rows as $row) : ?>
                        <li><span style="background: <?php echo esc_attr($row[2]); ?>"></span>
                            <b><?php echo (int) $row[0]; ?></b> <?php echo esc_html($row[1]); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </figure>
        <?php
        return ob_get_clean();
    }

    /* ---------------------------------------------------------------------
     * Содержание
     * ------------------------------------------------------------------ */

    /**
     * Требования из вакансий по частоте упоминания.
     *
     * Это основа программы и одновременно доказательство для читателя:
     * цифры он может проверить сам за десять минут.
     */
    public static function demands() {
        return array(
            array(20, 'Монтаж', 'Premiere, DaVinci, After Effects, CapCut — сборка, ритм, цветокоррекция', 6, 'film'),
            array(15, 'Вертикальные ролики', 'Reels, Shorts, VK Клипы — от идеи до готового файла', 1, 'play'),
            array(15, 'UGC и работа в кадре', 'Живая подача, съёмка на телефон, личный стиль', 7, 'person'),
            array(14, 'Сценарии', 'Текст под хронометраж, первые секунды, структура', 2, 'doc'),
            array(13, 'Портфолио', '«Смотрим работы, а не резюме» — дословно из объявлений', 8, 'star'),
            array(8,  'Тренды и референсы', 'Поиск идей у конкурентов и адаптация под бренд', 2, 'trend'),
            array(7,  'Генерация кадров', 'Midjourney, Stable Diffusion, ComfyUI, правка готового кадра', 3, 'image'),
            array(7,  'Оживление и AI-видео', 'Kling, Runway, Seedance, Higgsfield', 4, 'sparkle'),
            array(5,  'Звук и озвучка', 'Дорожка, музыка, эффекты, субтитры', 5, 'wave'),
            array(3,  'Контент для маркетплейсов', 'Карточки и карусели для Wildberries и Ozon', 7, 'cart'),
        );
    }

    /** Программа: восемь модулей, каждый против требования из вакансий. */
    public static function program() {
        return array(
            array(
                'Вертикальный ролик под ключ',
                'Собираем первый ролик целиком в первую же неделю: идея, сценарий, кадры, звук, монтаж, выгрузка под три площадки. Дальше всё обучение — углубление каждого шага.',
                'Ролик 15 секунд со звуком и субтитрами',
                'В объявлениях это главный ежедневный результат: «1 Reels в день», «вести ролик под ключ в одиночку».',
                'play',
            ),
            array(
                'Идея, референс, сценарий',
                'Где брать идеи, когда своих нет: разбор чужих удачных роликов, адаптация тренда под бренд, сценарий под хронометраж, три варианта первых секунд.',
                'Банк из 20 разобранных референсов и 5 сценариев',
                'Требование «искать идеи у конкурентов и адаптировать их под нас» встречается в каждой второй вакансии.',
                'bulb',
            ),
            array(
                'Кадр: генерация и фотореализм',
                'Постановка задачи модели по пяти слоям, правка готового кадра, серия в одном стиле. Отдельно — суперреализм: кожа с порами, фактура ткани, корректная анатомия и физика складок.',
                'Серия из 10 кадров в одном стиле плюс один каталожный кадр',
                'Дословно из вакансии fashion-направления: «натуральная кожа, реалистичная ткань, корректная физика, никаких деревянных моделей».',
                'image',
            ),
            array(
                'Оживление кадра и AI-видео',
                'Движение из статичного кадра, сцены с одним персонажем, склейка отрезков в сюжет. Что модели ещё не умеют и как это обходить монтажом.',
                'Мини-сериал: три связанных ролика с одним героем',
                'Именно эти инструменты перечислены в объявлениях по именам: Kling, Runway, Seedance, Higgsfield.',
                'sparkle',
            ),
            array(
                'Звук: озвучка, музыка, эффекты',
                'Начитка сценария, подбор голоса, фоновая подложка, акценты, субтитры с дорожки. Почему ролик без звука не досматривают и как попасть в хронометраж с первого раза.',
                'Три ролика с разным звуковым решением',
                'Требование «работать со звуком и озвучкой» идёт в одном ряду с монтажом.',
                'wave',
            ),
            array(
                'Монтаж и ритм',
                'Сборка в Premiere или DaVinci, базовая анимация в After Effects, цветокоррекция, динамика и чувство ритма, субтитры, нарезка под площадки. Разбор, почему ролик выглядит дёшево.',
                'Один материал, собранный в трёх разных ритмах',
                'Самое частое требование во всей выборке — 20 упоминаний. Без монтажа генерация не продаётся.',
                'film',
            ),
            array(
                'Контент, который продаёт',
                'Карточки товара и карусели для Wildberries и Ozon, UGC-ролики с собой в кадре, интеграция продукта в развлекательный сюжет. Разбор требований площадок.',
                'Серия 20 карточек, карусель и UGC-ролик',
                'UGC и контент для карточек — 18 упоминаний вместе; «работа в кадре» вынесена в отдельный навык.',
                'cart',
            ),
            array(
                'Поток, автоматизация, сдача',
                'Пайплайн на сотни кадров через API, ТЗ подрядчику и монтажёру, смета и себестоимость работы, число правок в договоре, самозанятость и права на результат.',
                'Посчитанная смета и рабочий пайплайн на 100 кадров',
                'Из объявлений: «построить пайплайны, где ИИ работает за вас», «передавать монтажёру понятное ТЗ», «сдавать в срок и вносить правки».',
                'flow',
            ),
        );
    }

    /** Что остаётся на руках. Портфолио собрано под то, что просят показать. */
    public static function portfolio() {
        return array(
            array('Рекламный ролик 15 секунд', 'Полный цикл под бренд: сценарий, кадры, звук, монтаж', 'film'),
            array('AI-мини-сериал из трёх роликов', 'Один персонаж, сквозной сюжет, узнаваемый стиль', 'layers'),
            array('Каталожный кадр', 'Товар или одежда на модели — уровень коммерческой съёмки', 'camera'),
            array('Серия из 20 карточек', 'Один свет, один фон, требования маркетплейса', 'grid'),
            array('UGC-ролик с собой в кадре', 'Живая подача, съёмка на телефон, монтаж под Shorts', 'phone'),
            array('Карусель под оффер', 'Текст и визуал, которые работают вместе', 'carousel'),
        );
    }


    public static function faq() {
        return array(
            array('Откуда взялась программа?',
                  'Из выдачи hh.ru по запросу «AI креатор» на ' . self::HH_DATE . ': ' . self::HH_TOTAL . ' вакансий, разобрано ' . self::HH_CARDS . ' карточек и 14 полных описаний. Требования посчитаны по частоте упоминания, и каждый модуль закрывает конкретное из них. Цифры можно проверить самостоятельно за десять минут — поиск открытый.'),
            array('Нужен ли опыт в монтаже?',
                  'Нет, монтаж разбираем с нуля — это шестой модуль и самое частое требование в вакансиях. Если опыт есть, этот модуль пройдёте быстрее и потратите время на генерацию и звук.'),
            array('Сколько зарабатывают AI-креаторы?',
                  'По вилкам из объявлений на ' . self::HH_DATE . ': медиана нижней границы — ' . number_format_i18n(self::HH_MED_LOW) . ' ₽, медиана верхней — ' . number_format_i18n(self::HH_MED_TOP) . ' ₽, максимум в выборке — ' . number_format_i18n(self::HH_MAX) . ' ₽. Это вилки рынка, а не обещание дохода: сколько получится у вас, зависит от портфолио и от того, скольким заказчикам вы его покажете.'),
            array('Берут ли без опыта?',
                  'В ' . self::HH_NOEXP . ' из ' . self::HH_CARDS . ' разобранных карточек опыт не требуется, в 59 просят от года. При этом почти везде написано «смотрим работы, а не резюме» — поэтому выпускная часть обучения это шесть работ в портфолио, а не сертификат.'),
            array('Сколько стоит обучение?',
                  'Базовый тариф — ' . number_format_i18n(self::PRICE_BASE) . ' ₽, тариф с наставником — ' . number_format_i18n(self::PRICE_PRO) . ' ₽. Программы «профессия AI-креатор» у крупных школ стоят от 36 000 ₽ за курс целиком при сроке от трёх до шести месяцев.'),
            array('Сколько длится и в каком режиме?',
                  'Восемь модулей, рассчитанных на два часа в день: это шесть недель. Материалы в записи, задания сдаются текстом и файлами, разборы письменные — поэтому разница во времени с Москвой значения не имеет.'),
            array('Правда ли, что после обучения дают заказы?',
                  'Да, и вот как это устроено. К нам приходят задачи, которые мы не берём сами: карточки для магазинов, короткие ролики, озвучка, оформление каналов. Их мы передаём выпускникам — на расширенном тарифе в первую очередь. Плюс помогаем с поиском удалённой работы: где искать вакансии, по каким названиям, какие работы показывать под конкретное объявление и что писать в отклике. Чего мы не делаем — не обещаем трудоустройство: честной гарантии здесь не даёт никто, а заказ всё равно нужно выполнить так, чтобы заказчик вернулся.'),
            array('Что входит в тариф с наставником?',
                  'Разбор каждой сданной работы, а не только шести выпускных, сборка портфолио под конкретную нишу и помощь с первыми откликами: что писать в сопроводительном и какие работы показывать под какую вакансию.'),
            array('Нужен ли мощный компьютер?',
                  'Для генерации нет: она считается на стороне сервисов, вам нужен браузер. Для монтажа хватает любого ноутбука последних лет и бесплатного редактора — именно на таком и ведём занятия.'),
            array('Сколько придётся потратить на сами запуски?',
                  'В течение первого месяца — нисколько: пакет генераций фото и видео без ограничения по количеству входит в оба тарифа, и на время обучения счётчик с них снят. Дальше работают обычные цены операций: картинка — 9 ₽, правка кадра — 35 ₽, озвучка — 18 ₽ за тысячу знаков, звук — 9 ₽, расшифровка — 3 ₽ за минуту, черновая начитка бесплатными голосами — 0 ₽. Считать себестоимость работы вы научитесь в восьмом модуле — именно по этим ценам.'),
            array('Кому принадлежат работы?',
                  'Вам. Всё, что сделано на обучении, можно показывать в портфолио и использовать в коммерческих проектах. Ограничения касаются чужого: узнаваемых лиц, логотипов и персонажей с правообладателем — это разбираем отдельно.'),
            array('Что если не получится?',
                  'На разборах видно, где именно ломается результат, и почти всегда это не модель: либо задача поставлена неточно, либо исходник плохой, либо ритм монтажа не тот. Научиться отличать одно от другого — и есть основная часть обучения.'),
        );
    }

    /* ---------------------------------------------------------------------
     * Разметка страницы
     * ------------------------------------------------------------------ */

    public static function head() {
        if (!self::is_page()) {
            return;
        }
        $url = self::get_url();
        $image = self::image('hero');

        echo '<meta name="description" content="' . esc_attr(self::SEO_DESC) . '">' . "\n";
        echo '<link rel="canonical" href="' . esc_url($url) . '">' . "\n";
        echo '<meta property="og:type" content="website">' . "\n";
        echo '<meta property="og:title" content="' . esc_attr(self::SEO_TITLE) . '">' . "\n";
        echo '<meta property="og:description" content="' . esc_attr(self::SEO_DESC) . '">' . "\n";
        echo '<meta property="og:url" content="' . esc_url($url) . '">' . "\n";
        if ($image !== '') {
            echo '<meta property="og:image" content="' . esc_url($image) . '">' . "\n";
            echo '<meta name="twitter:card" content="summary_large_image">' . "\n";
        }

        $faq = array();
        foreach (self::faq() as $pair) {
            $faq[] = array(
                '@type'          => 'Question',
                'name'           => $pair[0],
                'acceptedAnswer' => array('@type' => 'Answer', 'text' => $pair[1]),
            );
        }
        echo '<script type="application/ld+json">' . wp_json_encode(array(
            '@context'   => 'https://schema.org',
            '@type'      => 'FAQPage',
            'mainEntity' => $faq,
        ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '</script>' . "\n";

        // Разметка курса: цену и длительность поиск показывает отдельным
        // блоком, а без offers Course считается неполным.
        echo '<script type="application/ld+json">' . wp_json_encode(array(
            '@context'    => 'https://schema.org',
            '@type'       => 'Course',
            'name'        => 'Обучение AI-креатора',
            'description' => self::SEO_DESC,
            'url'         => $url,
            'inLanguage'  => 'ru',
            'provider'    => array('@type' => 'Organization', 'name' => get_bloginfo('name'), 'url' => home_url('/')),
            'teaches'     => wp_list_pluck(self::program(), 0),
            'hasCourseInstance' => array(array(
                '@type'              => 'CourseInstance',
                'courseMode'         => 'online',
                'courseWorkload'     => 'P' . self::WEEKS . 'W',
                'courseSchedule'     => array(
                    '@type'      => 'Schedule',
                    'repeatFrequency' => 'P1D',
                    'duration'   => 'PT2H',
                ),
            )),
            'offers' => array(
                array('@type' => 'Offer', 'name' => 'Базовый', 'price' => self::PRICE_BASE,
                      'priceCurrency' => 'RUB', 'availability' => 'https://schema.org/InStock', 'url' => $url),
                array('@type' => 'Offer', 'name' => 'С наставником', 'price' => self::PRICE_PRO,
                      'priceCurrency' => 'RUB', 'availability' => 'https://schema.org/InStock', 'url' => $url),
            ),
        ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '</script>' . "\n";
    }

    /** Фоновая графика секции: рисуем SVG, а не грузим картинку. */
    private static function waves() {
        return '<svg class="gs-kr__waves" viewBox="0 0 1200 120" preserveAspectRatio="none" aria-hidden="true" focusable="false">'
             . '<path d="M0 60C180 10 320 110 520 70 720 30 900 100 1200 40V120H0z" fill="currentColor" opacity=".55"/>'
             . '<path d="M0 86C220 46 380 116 600 90 820 64 980 110 1200 76V120H0z" fill="currentColor" opacity=".3"/>'
             . '</svg>';
    }

    public static function render($atts = array()) {
        $url   = self::get_url();
        $hero  = self::image('hero');
        $pay   = number_format_i18n(self::HH_MED_LOW) . '–' . number_format_i18n(self::HH_MED_TOP);

        ob_start();
        ?>
        <div class="gs-wrap gs-studio gs-kr">

            <?php
            /*
             * Летающий слой.
             *
             * Страница шире содержимого, и по бокам оставалось пустое
             * поле. Фигуры лежат в этих полях, не наезжая на текст:
             * слой закреплён в окне, фигуры медленно дрейфуют и
             * поворачиваются, у каждой свой темп. Ниже 1200 точек слой
             * выключается — там полей нет, и фигуры попали бы под текст.
             */
            ?>
            <div class="gs-kr__deco" aria-hidden="true">
                <svg class="gs-kr__fig gs-kr__fig--1" viewBox="0 0 100 100"><circle cx="50" cy="50" r="42" fill="none" stroke="currentColor" stroke-width="5"/></svg>
                <svg class="gs-kr__fig gs-kr__fig--2" viewBox="0 0 100 100"><circle cx="50" cy="50" r="40" fill="none" stroke="currentColor" stroke-width="5" stroke-dasharray="3 13" stroke-linecap="round"/></svg>
                <svg class="gs-kr__fig gs-kr__fig--3" viewBox="0 0 100 100"><rect x="14" y="14" width="72" height="72" rx="18" fill="none" stroke="currentColor" stroke-width="5"/></svg>
                <svg class="gs-kr__fig gs-kr__fig--4" viewBox="0 0 100 100"><path d="M50 12v76M12 50h76" stroke="currentColor" stroke-width="7" stroke-linecap="round"/></svg>
                <svg class="gs-kr__fig gs-kr__fig--5" viewBox="0 0 100 100"><path d="M50 14 88 82H12z" fill="none" stroke="currentColor" stroke-width="5" stroke-linejoin="round"/></svg>
                <svg class="gs-kr__fig gs-kr__fig--6" viewBox="0 0 100 100"><path d="M50 8 61 39l31 11-31 11-11 31-11-31L8 50l31-11z" fill="currentColor"/></svg>
                <svg class="gs-kr__fig gs-kr__fig--7" viewBox="0 0 140 40"><path d="M4 26c14-22 26 10 40-8s26 22 40 4 26-10 52-14" fill="none" stroke="currentColor" stroke-width="5" stroke-linecap="round"/></svg>
                <svg class="gs-kr__fig gs-kr__fig--8" viewBox="0 0 100 100"><g fill="currentColor"><circle cx="18" cy="18" r="7"/><circle cx="50" cy="18" r="7"/><circle cx="82" cy="18" r="7"/><circle cx="18" cy="50" r="7"/><circle cx="50" cy="50" r="7"/><circle cx="82" cy="50" r="7"/><circle cx="18" cy="82" r="7"/><circle cx="50" cy="82" r="7"/><circle cx="82" cy="82" r="7"/></g></svg>
                <svg class="gs-kr__fig gs-kr__fig--9" viewBox="0 0 100 100"><circle cx="50" cy="50" r="30" fill="none" stroke="currentColor" stroke-width="6"/></svg>
                <svg class="gs-kr__fig gs-kr__fig--10" viewBox="0 0 100 100"><path d="M50 10c22 0 40 18 40 40s-18 40-40 40S10 72 10 50 28 10 50 10z" fill="none" stroke="currentColor" stroke-width="5" stroke-dasharray="26 18"/></svg>
                <svg class="gs-kr__fig gs-kr__fig--11" viewBox="0 0 100 100"><path d="M20 80 50 20l30 60z" fill="currentColor" opacity=".55"/></svg>
                <svg class="gs-kr__fig gs-kr__fig--12" viewBox="0 0 100 100"><rect x="20" y="20" width="60" height="60" rx="14" fill="currentColor" opacity=".5"/></svg>
            </div>

            <?php
            /*
             * Своя шапка вместо сайтовой.
             *
             * На посадочной меню — это список способов уйти, не дочитав.
             * Оставляем единственную ссылку: логотип на главную, чтобы
             * человек понимал, чей это сайт, и мог проверить.
             */
            ?>
            <header class="gs-kr__bar">
                <a class="gs-kr__logo" href="<?php echo esc_url(home_url('/')); ?>" aria-label="<?php echo esc_attr(get_bloginfo('name')); ?>">
                    <img src="<?php echo esc_url(class_exists('GS_Brand') ? GS_Brand::logo('light') : ''); ?>"
                         alt="<?php echo esc_attr(get_bloginfo('name')); ?>" width="176" height="48" decoding="async" />
                </a>
                <a class="gs-kr__bar-cta" href="#gs-kr-form">Записаться</a>
            </header>

            <section class="gs-kr__hero">
                <svg class="gs-kr__glow" viewBox="0 0 600 600" aria-hidden="true" focusable="false">
                    <defs>
                        <radialGradient id="gs-kr-glow-a" cx="50%" cy="50%" r="50%">
                            <stop offset="0" stop-color="#8b5cf6" stop-opacity=".55"/>
                            <stop offset="1" stop-color="#8b5cf6" stop-opacity="0"/>
                        </radialGradient>
                        <radialGradient id="gs-kr-glow-b" cx="50%" cy="50%" r="50%">
                            <stop offset="0" stop-color="#22d3ee" stop-opacity=".4"/>
                            <stop offset="1" stop-color="#22d3ee" stop-opacity="0"/>
                        </radialGradient>
                    </defs>
                    <circle cx="190" cy="200" r="190" fill="url(#gs-kr-glow-a)"/>
                    <circle cx="430" cy="380" r="170" fill="url(#gs-kr-glow-b)"/>
                </svg>

                <div class="gs-kr__hero-text">
                    <span class="gs-kr__badge">Программа собрана по <?php echo (int) self::HH_TOTAL; ?> вакансиям</span>
                    <h1 class="gs-kr__title">Обучение AI-креатора: учим тому, что спрашивают в вакансиях</h1>
                    <p class="gs-kr__lead">Мы разобрали выдачу hh.ru по запросу «AI креатор» и посчитали требования по
                        частоте. Самое частое — не красивые картинки, а монтаж, вертикальные ролики и UGC. Программа
                        построена ровно в этом порядке. <strong>А после обучения мы передаём заказы и помогаем найти
                        удалённую работу: разбираем портфолио под вакансию и отклики.</strong></p>
                    <div class="gs-kr__cta">
                        <a class="gs-kr__btn" href="#gs-kr-form">Записаться на обучение</a>
                        <a class="gs-kr__btn gs-kr__btn--ghost" href="#gs-kr-program">Смотреть программу</a>
                    </div>
                    <?php
                    /*
                     * Подписи держим в две-три коротких строки: в узкой
                     * колонке первого экрана длинная подпись переносится
                     * посреди словосочетания, и ряд перестаёт читаться
                     * как ряд.
                     */
                    $facts = array(
                        array('money',   'от ' . number_format_i18n(self::PRICE_BASE) . ' ₽', 'два тарифа, без рассрочки'),
                        array('clock',   self::WEEKS . ' недель',      'по два часа в день'),
                        array('check',   '6 работ',                    'в портфолио на выходе'),
                        array('cart',    'Заказы после',               'передаём свои задачи'),
                        array('remote',  'Удалённо',                   '66 вакансий из 100'),
                        array('sparkle', 'Месяц без лимита',           'генерации фото и видео'),
                    );
                    ?>
                    <ul class="gs-kr__facts">
                        <?php foreach ($facts as $fact) : ?>
                            <li>
                                <span class="gs-kr__fact-ico"><?php echo self::icon($fact[0]); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
                                <b><?php echo esc_html($fact[1]); ?></b>
                                <span class="gs-kr__fact-note"><?php echo esc_html($fact[2]); ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <p class="gs-kr__fine">Материалы в записи, разборы письменные — часовой пояс не мешает.
                        Обязательных эфиров нет.</p>
                </div>

                <?php if ($hero !== '') : ?>
                    <div class="gs-kr__hero-art">
                        <img src="<?php echo esc_url($hero); ?>" alt="Рабочий стол AI-креатора: ноутбук с кадрами, планшет и телефон с вертикальным видео"
                             width="1280" height="720" loading="eager" decoding="async" />
                    </div>
                <?php endif; ?>
            </section>

            <section class="gs-kr__stats" aria-label="Цифры рынка">
                <div class="gs-kr__stat">
                    <span class="gs-kr__stat-num"><?php echo (int) self::HH_TOTAL; ?></span>
                    <span class="gs-kr__stat-cap">вакансий «AI креатор» на hh.ru</span>
                </div>
                <div class="gs-kr__stat">
                    <span class="gs-kr__stat-num"><?php echo esc_html($pay); ?> ₽</span>
                    <span class="gs-kr__stat-cap">медианная вилка в объявлениях</span>
                </div>
                <div class="gs-kr__stat">
                    <span class="gs-kr__stat-num"><?php echo (int) self::HH_REMOTE; ?> из <?php echo (int) self::HH_CARDS; ?></span>
                    <span class="gs-kr__stat-cap">допускают удалённую работу</span>
                </div>
                <div class="gs-kr__stat">
                    <span class="gs-kr__stat-num"><?php echo (int) self::HH_NOEXP; ?> из <?php echo (int) self::HH_CARDS; ?></span>
                    <span class="gs-kr__stat-cap">берут без опыта</span>
                </div>
                <div class="gs-kr__charts">
                    <?php echo self::salary_chart(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                    <?php echo self::experience_chart(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                </div>
                <p class="gs-kr__source">Выборка: <?php echo (int) self::HH_CARDS; ?> карточек и 14 полных описаний,
                    <?php echo esc_html(self::HH_DATE); ?>. Зарплата указана в <?php echo (int) self::HH_PAY; ?> из
                    <?php echo (int) self::HH_CARDS; ?> карточек, максимум в выборке — <?php echo esc_html(number_format_i18n(self::HH_MAX)); ?> ₽.
                    Это вилки рынка, а не обещание дохода.</p>
            </section>

            <section class="gs-kr__demands">
                <h2 class="gs-kr__h2">Что требуют работодатели — по частоте</h2>
                <p class="gs-kr__sub">Слева — сколько раз требование встретилось в выборке. Справа — модуль программы,
                    который его закрывает.</p>
                <svg width="0" height="0" aria-hidden="true" focusable="false" style="position:absolute">
                    <defs>
                        <linearGradient id="gs-kr-bar-g" x1="0" y1="0" x2="1" y2="0">
                            <stop offset="0" stop-color="#5b4ae0"/>
                            <stop offset="1" stop-color="#0ea5b7"/>
                        </linearGradient>
                    </defs>
                </svg>
                <ul class="gs-kr__dlist">
                    <?php $top = max(array_map(function ($row) { return (int) $row[0]; }, self::demands())); ?>
                    <?php foreach (self::demands() as $d) : ?>
                        <li class="gs-kr__d">
                            <span class="gs-kr__d-ico"><?php echo self::icon($d[4]); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
                            <span class="gs-kr__d-body">
                                <strong class="gs-kr__d-name"><?php echo esc_html($d[1]); ?></strong>
                                <span class="gs-kr__d-note"><?php echo esc_html($d[2]); ?></span>
                                <?php /* Полоса частоты: доля от самого частого требования. */ ?>
                                <svg class="gs-kr__d-bar" viewBox="0 0 100 4" preserveAspectRatio="none" aria-hidden="true" focusable="false">
                                    <rect x="0" y="0" width="100" height="4" rx="2" fill="var(--kr-line)"/>
                                    <rect x="0" y="0" width="<?php echo esc_attr(round($d[0] / $top * 100, 1)); ?>" height="4" rx="2" fill="url(#gs-kr-bar-g)"/>
                                </svg>
                            </span>
                            <span class="gs-kr__d-num"><?php echo (int) $d[0]; ?><i>упом.</i></span>
                            <a class="gs-kr__d-mod" href="#gs-kr-m<?php echo (int) $d[3]; ?>">модуль <?php echo (int) $d[3]; ?></a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </section>

            <section class="gs-kr__program" id="gs-kr-program">
                <h2 class="gs-kr__h2">Программа: восемь модулей</h2>
                <p class="gs-kr__sub">У каждого модуля есть сдаваемая работа. К концу обучения их шесть в портфолио —
                    именно то, что просят показать вместо резюме.</p>
                <ol class="gs-kr__mods">
                    <?php $n = 0; foreach (self::program() as $m) : $n++; ?>
                        <li class="gs-kr__mod" id="gs-kr-m<?php echo (int) $n; ?>">
                            <span class="gs-kr__mod-n">
                                <?php echo self::icon($m[4], 'gs-kr__mod-ico'); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                                <i><?php echo (int) $n; ?></i>
                            </span>
                            <div class="gs-kr__mod-body">
                                <h3 class="gs-kr__mod-title"><?php echo esc_html($m[0]); ?></h3>
                                <p class="gs-kr__mod-text"><?php echo esc_html($m[1]); ?></p>
                                <p class="gs-kr__mod-out"><span>Сдаёте:</span> <?php echo esc_html($m[2]); ?></p>
                                <p class="gs-kr__mod-why"><?php echo esc_html($m[3]); ?></p>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ol>
            </section>

            <section class="gs-kr__gift">
                <div class="gs-kr__gift-text">
                    <span class="gs-kr__gift-tag">Входит в оба тарифа</span>
                    <h2 class="gs-kr__h2">Месяц генераций без лимита — в подарок</h2>
                    <p class="gs-kr__sub">Вся практика идёт на наших сервисах, и на месяц мы снимаем с них счётчик:
                        генерируйте фото и видео сколько нужно, без оплаты за запуск и без ограничения по количеству.
                        Переделывать кадр двадцать раз, пока он не сойдётся, — это и есть обучение.</p>
                    <ul class="gs-kr__gift-list">
                        <li><?php echo self::icon('image', 'gs-kr__ico'); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                            <b>Фото без лимита</b><span>генерация и правка кадров</span></li>
                        <li><?php echo self::icon('play', 'gs-kr__ico'); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                            <b>Видео без лимита</b><span>оживление кадров и сцены</span></li>
                        <li><?php echo self::icon('clock', 'gs-kr__ico'); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                            <b>Целый месяц</b><span>со дня старта обучения</span></li>
                    </ul>
                </div>
                <div class="gs-kr__gift-act">
                    <a class="gs-kr__btn" href="#gs-kr-form">Записаться с подарком</a>
                    <a class="gs-kr__btn gs-kr__btn--ghost" href="<?php echo esc_url(self::TG_URL); ?>" target="_blank" rel="noopener">Сначала посмотреть канал</a>
                </div>
            </section>

            <section class="gs-kr__folio">
                <?php echo self::waves(); ?>
                <h2 class="gs-kr__h2">Что остаётся на руках</h2>
                <p class="gs-kr__sub">Шесть работ, собранных под требование «портфолио с роликами», и посчитанная смета,
                    по которой видно себестоимость каждой работы.</p>
                <ul class="gs-kr__cards">
                    <?php foreach (self::portfolio() as $i => $p) : ?>
                        <li class="gs-kr__card">
                            <span class="gs-kr__card-n"><?php echo self::icon($p[2]); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
                            <strong class="gs-kr__card-title"><?php echo esc_html($p[0]); ?></strong>
                            <span class="gs-kr__card-text"><?php echo esc_html($p[1]); ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <?php
                /* Галерея работ: показываем те же четыре формата, которые
                   перечислены в вакансиях, по одному кадру на формат. */
                $shots = array(
                    array('fashion', 'Каталожный кадр: кожа, ткань, свет', 'Коммерческий кадр одежды на модели'),
                    array('video',   'Монтаж и звук: дорожки и цветокоррекция', 'Таймлайн монтажа со звуковой дорожкой'),
                    array('ugc',     'UGC: короткое видео на телефон', 'Съёмка короткого видео на телефон с кольцевой лампой'),
                    array('cards',   'Серия карточек товара в одном свете', 'Ноутбук с сеткой карточек товара'),
                );
                $shots = array_values(array_filter($shots, function ($shot) {
                    return self::image($shot[0]) !== '';
                }));
                if ($shots) :
                    ?>
                    <ul class="gs-kr__gallery">
                        <?php foreach ($shots as $shot) : ?>
                            <li class="gs-kr__gitem">
                                <img src="<?php echo esc_url(self::image($shot[0])); ?>" alt="<?php echo esc_attr($shot[2]); ?>"
                                     width="1280" height="720" loading="lazy" decoding="async" />
                                <span><?php echo esc_html($shot[1]); ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </section>

            <section class="gs-kr__after">
                <h2 class="gs-kr__h2">Что происходит после обучения</h2>
                <p class="gs-kr__sub">Портфолио без первого заказа остаётся папкой с картинками. Поэтому обучение не
                    заканчивается последним модулем: дальше мы помогаем превратить работы в деньги.</p>
                <div class="gs-kr__after-grid">
                    <div class="gs-kr__after-item">
                        <span class="gs-kr__after-n">1</span>
                        <?php echo self::icon('cart', 'gs-kr__after-ico'); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                        <h3>Передаём заказы</h3>
                        <p>Через нас проходят задачи, которые мы не берём сами: карточки для магазинов, ролики,
                            озвучка, оформление каналов. Выпускникам они уходят в первую очередь — с нашей стороны
                            задача уже обсуждена с заказчиком, вам остаётся работа.</p>
                    </div>
                    <div class="gs-kr__after-item">
                        <span class="gs-kr__after-n">2</span>
                        <?php echo self::icon('remote', 'gs-kr__after-ico'); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                        <h3>Ищем удалённую работу вместе</h3>
                        <p>Удалённая работа в этой профессии — норма, а не льгота: в <?php echo (int) self::HH_REMOTE; ?>
                            вакансиях из <?php echo (int) self::HH_CARDS; ?> прямо написано «можно удалённо». Мы
                            показываем, где эти вакансии искать и по каким пяти названиям, потому что единого
                            названия у профессии до сих пор нет.</p>
                    </div>
                    <div class="gs-kr__after-item">
                        <span class="gs-kr__after-n">3</span>
                        <?php echo self::icon('star', 'gs-kr__after-ico'); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                        <h3>Собираем портфолио под вакансию</h3>
                        <p>Под каждую вакансию нужен свой набор работ и своё сопроводительное. Разбираем, какие из
                            шести работ показывать этому работодателю, что написать в отклике и какие цифры по
                            результатам назвать — их просят почти везде.</p>
                    </div>
                </div>
                <p class="gs-kr__fine">Честно: гарантии трудоустройства мы не даём — её не даёт никто, кто говорит
                    правду. Заказы и помощь с откликами — даём, и это записано в тарифах ниже.</p>
            </section>

            <section class="gs-kr__tg">
                <svg class="gs-kr__tg-ico" viewBox="0 0 48 48" aria-hidden="true" focusable="false">
                    <circle cx="24" cy="24" r="24" fill="#fff" fill-opacity=".16"/>
                    <path d="M34.6 14.5 30.9 33c-.3 1.3-1 1.6-2.1.99l-5.8-4.3-2.8 2.7c-.3.3-.6.6-1.2.6l.4-5.9 10.8-9.7c.5-.4-.1-.7-.8-.3l-13.3 8.4-5.7-1.8c-1.2-.4-1.3-1.2.3-1.8l22.3-8.6c1-.4 1.9.2 1.6 1.7z" fill="#fff"/>
                </svg>
                <div class="gs-kr__tg-text">
                    <p class="gs-kr__tg-title">Разборы вакансий и заказы — в телеграм-канале</p>
                    <p class="gs-kr__tg-lead">«<?php echo esc_html(self::TG_NAME); ?>»: живые цифры по заказам, промты, кейсы и вакансии, которые ищут исполнителя. Подписка ни к чему не обязывает — и по ней видно, что в профессии происходит прямо сейчас.</p>
                </div>
                <a class="gs-kr__tg-btn" href="<?php echo esc_url(self::TG_URL); ?>" target="_blank" rel="noopener">Открыть канал</a>
            </section>

            <section class="gs-kr__how">
                <h2 class="gs-kr__h2">Как устроено обучение</h2>
                <div class="gs-kr__how-grid">
                    <div class="gs-kr__how-item">
                        <?php echo self::icon('play', 'gs-kr__how-ico'); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                        <h3>Материалы в записи</h3>
                        <p>Смотрите когда удобно. Прямых эфиров нет намеренно: половина заказчиков и учеников живёт не
                            в московском времени, и обязательный вебинар в 19:00 попадает кому-то на ночь.</p>
                    </div>
                    <div class="gs-kr__how-item">
                        <?php echo self::icon('check', 'gs-kr__how-ico'); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                        <h3>Задания после каждого модуля</h3>
                        <p>Не тесты, а работа: ролик, серия кадров, смета. Сдаётся файлами и ссылкой, проверяется
                            письменно — с указанием, что именно переделать и почему.</p>
                    </div>
                    <div class="gs-kr__how-item">
                        <?php echo self::icon('wallet', 'gs-kr__how-ico'); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                        <h3>Практика на рабочих сервисах</h3>
                        <p>Не на демо-доступах: генерация, озвучка, звук и расшифровка идут на тех же сервисах, на
                            которых работают за деньги, с оплатой за запуск и видимой себестоимостью.</p>
                    </div>
                    <div class="gs-kr__how-item">
                        <?php echo self::icon('search', 'gs-kr__how-ico'); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                        <h3>Разбор ошибок, а не похвала</h3>
                        <p>Главный навык — отличать плохую постановку задачи от плохого исходника и от неверного ритма
                            монтажа. Этому учат только на разборах конкретных работ.</p>
                    </div>
                </div>
                <?php
                $mentor = self::image('mentor');
                if ($mentor !== '') :
                    ?>
                    <figure class="gs-kr__shot">
                        <img src="<?php echo esc_url($mentor); ?>" alt="Разбор работ: наставник и ученик у экрана"
                             width="1280" height="720" loading="lazy" decoding="async" />
                        <figcaption>Разбор сданной работы: что переделать и почему.</figcaption>
                    </figure>
                <?php endif; ?>
            </section>

            <section class="gs-kr__trust">
                <h2 class="gs-kr__h2">Что можно проверить до оплаты</h2>
                <p class="gs-kr__sub">Отзывов выпускников мы не показываем: курс молодой, а рисовать их — последнее
                    дело. Вместо отзывов — то, что проверяется за десять минут.</p>
                <ul class="gs-kr__trust-grid">
                    <li>
                        <?php echo self::icon('search', 'gs-kr__trust-ico'); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                        <b>Цифры рынка</b>
                        <span>Откройте hh.ru, введите «AI креатор» — и сверьте с числами наверху страницы.</span>
                    </li>
                    <li>
                        <?php echo self::icon('doc', 'gs-kr__trust-ico'); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                        <b>Как мы пишем и считаем</b>
                        <span>Три разбора профессии опубликованы открыто и бесплатно — ссылки внизу страницы.</span>
                    </li>
                    <li>
                        <?php echo self::icon('wallet', 'gs-kr__trust-ico'); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                        <b>Сервисы, на которых практика</b>
                        <span>Генерация, озвучка и расшифровка работают на этом же сайте — попробуйте до оплаты, а на обучении месяц пользуетесь без лимита.</span>
                    </li>
                    <li>
                        <?php echo self::icon('check', 'gs-kr__trust-ico'); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                        <b>Документы и реквизиты</b>
                        <span>Исполнитель, ИНН и публичная оферта — внизу страницы, до всякой оплаты.</span>
                    </li>
                </ul>
            </section>

            <section class="gs-kr__doubts">
                <h2 class="gs-kr__h2">Три возражения, которые мы слышим чаще всего</h2>
                <p class="gs-kr__sub">Отвечаем на них здесь, а не в переписке после оплаты.</p>
                <div class="gs-kr__doubt-grid">
                    <div class="gs-kr__doubt">
                        <?php echo self::icon('person', 'gs-kr__doubt-ico'); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                        <h3>«У меня нет опыта и профильного образования»</h3>
                        <p>В <?php echo (int) self::HH_NOEXP; ?> вакансиях из <?php echo (int) self::HH_CARDS; ?> опыт
                            не требуется вовсе, а в остальных смотрят портфолио: «смотрим работы, а не резюме» —
                            дословная цитата из объявления. Первый модуль начинается с нуля, монтаж разбираем
                            с первого нажатия.</p>
                    </div>
                    <div class="gs-kr__doubt">
                        <?php echo self::icon('clock', 'gs-kr__doubt-ico'); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                        <h3>«У меня нет времени, я работаю»</h3>
                        <p>Программа рассчитана на два часа в день — это вечер после работы. Эфиров нет, дедлайнов
                            по группе нет: сдаёте работы своим темпом, разбор приходит текстом. При сорока минутах
                            в день срок растягивается, но результат тот же.</p>
                    </div>
                    <div class="gs-kr__doubt">
                        <?php echo self::icon('bulb', 'gs-kr__doubt-ico'); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                        <h3>«Нейросети есть в открытом доступе, зачем платить»</h3>
                        <p>Незачем, если вы готовы собирать порядок сами — честный план на 90 дней мы выложили
                            открыто, ссылка внизу страницы. Платят здесь не за доступ к моделям, а за
                            последовательность и за разбор ваших работ живым человеком.</p>
                    </div>
                </div>
            </section>

            <section class="gs-kr__price">
                <h2 class="gs-kr__h2">Тарифы</h2>
                <div class="gs-kr__plans">
                    <div class="gs-kr__plan">
                        <h3 class="gs-kr__plan-name">Базовый</h3>
                        <p class="gs-kr__plan-sum"><?php echo esc_html(number_format_i18n(self::PRICE_BASE)); ?> ₽</p>
                        <ul class="gs-kr__plan-list">
                            <li>Восемь модулей и все материалы</li>
                            <li>Задания после каждого модуля</li>
                            <li>Проверка шести выпускных работ</li>
                            <li>Чат с ответами на вопросы</li>
                            <li>Шаблоны ТЗ, сметы и договора</li>
                            <li>Месяц генераций фото и видео без лимита</li>
                            <li>Доступ к заказам, которые проходят через нас</li>
                            <li>Где искать удалённые вакансии и по каким названиям</li>
                        </ul>
                        <a class="gs-kr__btn gs-kr__btn--ghost" href="#gs-kr-form">Выбрать базовый</a>
                    </div>
                    <div class="gs-kr__plan gs-kr__plan--pro">
                        <span class="gs-kr__plan-tag">с наставником</span>
                        <h3 class="gs-kr__plan-name">Расширенный</h3>
                        <p class="gs-kr__plan-sum"><?php echo esc_html(number_format_i18n(self::PRICE_PRO)); ?> ₽</p>
                        <ul class="gs-kr__plan-list">
                            <li>Всё из базового тарифа</li>
                            <li>Разбор каждой сданной работы, а не только выпускных</li>
                            <li>Сборка портфолио под выбранную нишу</li>
                            <li>Месяц генераций фото и видео без лимита</li>
                            <li>Заказы в первую очередь — до общего доступа</li>
                            <li>Подбор удалённых вакансий под вашу нишу</li>
                            <li>Разбор откликов и сопроводительных писем</li>
                            <li>Разбор вашей сметы и ставки</li>
                        </ul>
                        <a class="gs-kr__btn" href="#gs-kr-form">Выбрать расширенный</a>
                    </div>
                </div>
                <p class="gs-kr__fine">Для сравнения: программы «профессия AI-креатор» у крупных школ идут от 36 000 ₽
                    за курс целиком при сроке от трёх до шести месяцев.</p>
            </section>

            <section class="gs-kr__form-wrap" id="gs-kr-form">
                <?php echo self::waves(); ?>
                <div class="gs-kr__form-inner">
                    <div>
                    <h2 class="gs-kr__h2">Записаться на обучение</h2>
                    <p class="gs-kr__sub">Оставьте контакт — расскажем, с чего начинается первый модуль, и ответим на
                        вопросы по программе. Это не автоматическая рассылка: отвечает человек.</p>
                    <form class="gs-form" id="gs-kreator-form" novalidate>
                        <label class="gs-form__row">
                            <span class="gs-form__label">Как к вам обращаться</span>
                            <input class="gs-form__input" id="gs-kreator-name" type="text" name="name" autocomplete="name" placeholder="Имя" />
                        </label>
                        <label class="gs-form__row">
                            <span class="gs-form__label">Телефон, почта или ник в Telegram <b>*</b></span>
                            <input class="gs-form__input" id="gs-kreator-contact" type="text" name="contact" autocomplete="tel" placeholder="+7 900 000-00-00 или @nick" required />
                        </label>
                        <label class="gs-form__row">
                            <span class="gs-form__label">Тариф</span>
                            <select class="gs-form__input" id="gs-kreator-plan" name="plan">
                                <option value="базовый">Базовый — <?php echo esc_html(number_format_i18n(self::PRICE_BASE)); ?> ₽</option>
                                <option value="расширенный">Расширенный, с наставником — <?php echo esc_html(number_format_i18n(self::PRICE_PRO)); ?> ₽</option>
                                <option value="не выбрал">Пока не выбрал, нужна консультация</option>
                            </select>
                        </label>
                        <label class="gs-form__row">
                            <span class="gs-form__label">Что хотите делать после обучения</span>
                            <textarea class="gs-form__input gs-form__area" id="gs-kreator-comment" name="comment" rows="3" placeholder="Например: ролики для своего магазина на Wildberries"></textarea>
                        </label>
                        <button class="gs-kr__btn gs-kr__btn--wide" id="gs-kreator-send" type="submit">Отправить заявку</button>
                        <p class="gs-form__note" id="gs-kreator-note" role="status"></p>
                        <p class="gs-kr__or">или</p>
                        <a class="gs-kr__btn gs-kr__btn--ghost gs-kr__btn--wide" href="<?php echo esc_url(self::TG_URL); ?>" target="_blank" rel="noopener">
                            <?php echo self::icon('tg', 'gs-kr__ico'); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                            Написать в Telegram
                        </a>
                        <p class="gs-kr__fine">Отправляя заявку, вы соглашаетесь с
                            <a href="https://genius-bot.ru/politika-konfidencialnosti/">политикой конфиденциальности</a>.</p>
                    </form>
                    </div>

                    <aside class="gs-kr__form-side">
                        <h3>Что будет дальше</h3>
                        <ol>
                            <li><strong>Ответим в течение дня.</strong> В Telegram, по почте или звонком — как удобнее.</li>
                            <li><strong>Спросим про задачу.</strong> Магазин, канал, агентство или работа по найму — от
                                этого зависит, с какой ниши начинать и какие работы собирать в портфолио.</li>
                            <li><strong>Пришлём первый модуль.</strong> Начать можно в тот же день: первый ролик
                                собирается в первую неделю.</li>
                            <li><strong>Оплата после разговора.</strong> Никаких предоплат до того, как вы поймёте, что
                                программа вам подходит.</li>
                        </ol>
                    </aside>
                </div>
            </section>

            <section class="gs-kr__faq">
                <h2 class="gs-kr__h2">Частые вопросы</h2>
                <?php foreach (self::faq() as $i => $pair) : ?>
                    <details class="gs-kr__q"<?php echo $i === 0 ? ' open' : ''; ?>>
                        <summary><?php echo esc_html($pair[0]); ?></summary>
                        <p><?php echo wp_kses_post($pair[1]); ?></p>
                    </details>
                <?php endforeach; ?>
            </section>

            <section class="gs-kr__reads">
                <h2 class="gs-kr__h2">Разобраться в профессии до покупки</h2>
                <p class="gs-kr__sub">Эти материалы отвечают на вопрос «а моё ли это» лучше любого лендинга.</p>
                <ul class="gs-kr__rlist">
                    <li><a href="https://genius-bot.ru/ai-kreator-chem-zanimaetsya/">Чем занимается AI-креатор и сколько зарабатывает</a>
                        — задачи по дням, двенадцать навыков, вилки по уровням, расчёт расходов.</li>
                    <li><a href="https://genius-bot.ru/kak-stat-ai-kreatorom/">Как стать AI-креатором с нуля за 90 дней</a>
                        — план по неделям, если хотите попробовать самостоятельно.</li>
                    <li><a href="https://genius-bot.ru/kursy-ai-kreatora/">Курсы AI-креатора: разбор программ и цен</a>
                        — честное сравнение, включая случаи, когда обучение вам не нужно.</li>
                    <li><a href="<?php echo esc_url(self::TG_URL); ?>" target="_blank" rel="noopener">Телеграм-канал «<?php echo esc_html(self::TG_NAME); ?>»</a>
                        — разборы вакансий, промты и заказы по мере появления.</li>
                </ul>
            </section>

            <?php
            /*
             * Свой подвал: только то, без чего страница с формой не имеет
             * права существовать — кто оказывает услугу и по каким
             * правилам. Остальное в сайтовом подвале и на этой странице
             * лишнее.
             */
            $org = class_exists('GS_Seo') ? GS_Seo::org_details() : array();
            $legal = array();
            if (class_exists('GS_Requisites')) {
                $legal['Реквизиты'] = GS_Requisites::get_url();
                $legal['Публичная оферта'] = GS_Requisites::oferta_url();
                $legal['Политика обработки персональных данных'] = GS_Requisites::privacy_url();
            }
            $legal['Порядок оказания услуг'] = home_url('/poryadok-okazaniya-uslug/');
            $legal = array_filter($legal);
            ?>
            <footer class="gs-kr__foot">
                <p class="gs-kr__foot-org">
                    <?php if (!empty($org['legalName'])) : ?>
                        <?php echo esc_html($org['legalName']); ?><?php endif; ?>
                    <?php if (!empty($org['taxID'])) : ?>
                        · ИНН <?php echo esc_html($org['taxID']); ?><?php endif; ?>
                    <?php if (!empty($org['email'])) : ?>
                        · <a href="mailto:<?php echo esc_attr($org['email']); ?>"><?php echo esc_html($org['email']); ?></a><?php endif; ?>
                </p>
                <p class="gs-kr__foot-legal">
                    <?php $i = 0; foreach ($legal as $title => $url) : $i++; ?>
                        <?php echo $i > 1 ? ' · ' : ''; ?><a href="<?php echo esc_url($url); ?>"><?php echo esc_html($title); ?></a>
                    <?php endforeach; ?>
                </p>
            </footer>

            <div class="gs-kr__sticky" aria-hidden="false">
                <span class="gs-kr__sticky-price">от <?php echo esc_html(number_format_i18n(self::PRICE_BASE)); ?> ₽<i><?php echo (int) self::WEEKS; ?> недель, 8 модулей</i></span>
                <a class="gs-kr__btn gs-kr__sticky-btn" href="#gs-kr-form">Записаться</a>
            </div>

        </div>
        <?php
        return ob_get_clean();
    }
}
