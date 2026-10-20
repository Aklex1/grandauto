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

    public static function body_class($classes) {
        if (self::is_page()) {
            $classes[] = 'gs-studio-page';
            $classes[] = 'gs-chrome';
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
        'hero'    => 7240,
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
            array(20, 'Монтаж', 'Premiere, DaVinci, After Effects, CapCut — сборка, ритм, цветокоррекция', 6),
            array(15, 'Вертикальные ролики', 'Reels, Shorts, VK Клипы — от идеи до готового файла', 1),
            array(15, 'UGC и работа в кадре', 'Живая подача, съёмка на телефон, личный стиль', 7),
            array(14, 'Сценарии', 'Текст под хронометраж, первые секунды, структура', 2),
            array(13, 'Портфолио', '«Смотрим работы, а не резюме» — дословно из объявлений', 8),
            array(8,  'Тренды и референсы', 'Поиск идей у конкурентов и адаптация под бренд', 2),
            array(7,  'Генерация кадров', 'Midjourney, Stable Diffusion, ComfyUI, правка готового кадра', 3),
            array(7,  'Оживление и AI-видео', 'Kling, Runway, Seedance, Higgsfield', 4),
            array(5,  'Звук и озвучка', 'Дорожка, музыка, эффекты, субтитры', 5),
            array(3,  'Контент для маркетплейсов', 'Карточки и карусели для Wildberries и Ozon', 7),
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
            ),
            array(
                'Идея, референс, сценарий',
                'Где брать идеи, когда своих нет: разбор чужих удачных роликов, адаптация тренда под бренд, сценарий под хронометраж, три варианта первых секунд.',
                'Банк из 20 разобранных референсов и 5 сценариев',
                'Требование «искать идеи у конкурентов и адаптировать их под нас» встречается в каждой второй вакансии.',
            ),
            array(
                'Кадр: генерация и фотореализм',
                'Постановка задачи модели по пяти слоям, правка готового кадра, серия в одном стиле. Отдельно — суперреализм: кожа с порами, фактура ткани, корректная анатомия и физика складок.',
                'Серия из 10 кадров в одном стиле плюс один каталожный кадр',
                'Дословно из вакансии fashion-направления: «натуральная кожа, реалистичная ткань, корректная физика, никаких деревянных моделей».',
            ),
            array(
                'Оживление кадра и AI-видео',
                'Движение из статичного кадра, сцены с одним персонажем, склейка отрезков в сюжет. Что модели ещё не умеют и как это обходить монтажом.',
                'Мини-сериал: три связанных ролика с одним героем',
                'Именно эти инструменты перечислены в объявлениях по именам: Kling, Runway, Seedance, Higgsfield.',
            ),
            array(
                'Звук: озвучка, музыка, эффекты',
                'Начитка сценария, подбор голоса, фоновая подложка, акценты, субтитры с дорожки. Почему ролик без звука не досматривают и как попасть в хронометраж с первого раза.',
                'Три ролика с разным звуковым решением',
                'Требование «работать со звуком и озвучкой» идёт в одном ряду с монтажом.',
            ),
            array(
                'Монтаж и ритм',
                'Сборка в Premiere или DaVinci, базовая анимация в After Effects, цветокоррекция, динамика и чувство ритма, субтитры, нарезка под площадки. Разбор, почему ролик выглядит дёшево.',
                'Один материал, собранный в трёх разных ритмах',
                'Самое частое требование во всей выборке — 20 упоминаний. Без монтажа генерация не продаётся.',
            ),
            array(
                'Контент, который продаёт',
                'Карточки товара и карусели для Wildberries и Ozon, UGC-ролики с собой в кадре, интеграция продукта в развлекательный сюжет. Разбор требований площадок.',
                'Серия 20 карточек, карусель и UGC-ролик',
                'UGC и контент для карточек — 18 упоминаний вместе; «работа в кадре» вынесена в отдельный навык.',
            ),
            array(
                'Поток, автоматизация, сдача',
                'Пайплайн на сотни кадров через API, ТЗ подрядчику и монтажёру, смета и себестоимость работы, число правок в договоре, самозанятость и права на результат.',
                'Посчитанная смета и рабочий пайплайн на 100 кадров',
                'Из объявлений: «построить пайплайны, где ИИ работает за вас», «передавать монтажёру понятное ТЗ», «сдавать в срок и вносить правки».',
            ),
        );
    }

    /** Что остаётся на руках. Портфолио собрано под то, что просят показать. */
    public static function portfolio() {
        return array(
            array('Рекламный ролик 15 секунд', 'Полный цикл под бренд: сценарий, кадры, звук, монтаж'),
            array('AI-мини-сериал из трёх роликов', 'Один персонаж, сквозной сюжет, узнаваемый стиль'),
            array('Каталожный кадр', 'Товар или одежда на модели — уровень коммерческой съёмки'),
            array('Серия из 20 карточек', 'Один свет, один фон, требования маркетплейса'),
            array('UGC-ролик с собой в кадре', 'Живая подача, съёмка на телефон, монтаж под Shorts'),
            array('Карусель под оффер', 'Текст и визуал, которые работают вместе'),
        );
    }

    /** Инструменты, на которых идёт практика, с ценой запуска. */
    public static function tools() {
        return array(
            array('Картинка по описанию', '9 ₽ за запуск', 'https://genius-bot.ru/generaciya-foto-neyrosetyu/'),
            array('Правка кадра по описанию', '35 ₽ за запуск', ''),
            array('ИИ-фотосессия', '19 ₽ за кадр', 'https://genius-bot.ru/neyrofotosessiya/'),
            array('Озвучка текста', '18 ₽ за 1000 знаков', 'https://genius-bot.ru/tts-pricing/'),
            array('Бесплатные голоса', '0 ₽', ''),
            array('Звуковой эффект', '9 ₽ за звук', 'https://genius-bot.ru/sound-generator/'),
            array('Расшифровка для субтитров', '3 ₽ за минуту', 'https://genius-bot.ru/rasshifrovka-audio/'),
            array('Единый API для потока', 'оплата за запуск', 'https://genius-bot.ru/api/'),
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
                  'Восемь модулей, рассчитанных на два часа в день: это около десяти недель. Материалы в записи, задания сдаются текстом и файлами, разборы письменные — поэтому разница во времени с Москвой значения не имеет.'),
            array('Что входит в тариф с наставником?',
                  'Разбор каждой сданной работы, а не только шести выпускных, сборка портфолио под конкретную нишу и помощь с первыми откликами: что писать в сопроводительном и какие работы показывать под какую вакансию.'),
            array('Нужен ли мощный компьютер?',
                  'Для генерации нет: она считается на стороне сервисов, вам нужен браузер. Для монтажа хватает любого ноутбука последних лет и бесплатного редактора — именно на таком и ведём занятия.'),
            array('Сколько придётся потратить на сами запуски?',
                  'Около 3 000 ₽ за всё обучение при оплате за запуск, без подписок: картинка — 9 ₽, озвучка — 18 ₽ за тысячу знаков, звук — 9 ₽, расшифровка — 3 ₽ за минуту, черновая начитка бесплатными голосами — 0 ₽. Смету вы считаете сами в восьмом модуле.'),
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
                'courseWorkload'     => 'P10W',
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

            <nav class="gs-breadcrumbs" aria-label="Хлебные крошки">
                <a href="<?php echo esc_url(home_url('/')); ?>">Главная</a>
                <span aria-hidden="true">/</span>
                <span>Обучение AI-креатора</span>
            </nav>

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
                        построена ровно в этом порядке: каждый модуль закрывает конкретное требование работодателей.</p>
                    <div class="gs-kr__cta">
                        <a class="gs-kr__btn" href="#gs-kr-form">Записаться на обучение</a>
                        <a class="gs-kr__btn gs-kr__btn--ghost" href="#gs-kr-program">Смотреть программу</a>
                    </div>
                    <p class="gs-kr__fine">Восемь модулей, около десяти недель по два часа в день. Материалы в записи,
                        разборы письменные — часовой пояс не мешает.</p>
                </div>

                <?php if ($hero !== '') : ?>
                    <div class="gs-kr__hero-art">
                        <img src="<?php echo esc_url($hero); ?>" alt="Креатор монтирует вертикальный ролик за компьютером"
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
                <p class="gs-kr__source">Выборка: <?php echo (int) self::HH_CARDS; ?> карточек и 14 полных описаний,
                    <?php echo esc_html(self::HH_DATE); ?>. Зарплата указана в <?php echo (int) self::HH_PAY; ?> из
                    <?php echo (int) self::HH_CARDS; ?> карточек, максимум в выборке — <?php echo esc_html(number_format_i18n(self::HH_MAX)); ?> ₽.
                    Это вилки рынка, а не обещание дохода.</p>
            </section>

            <section class="gs-kr__demands">
                <h2 class="gs-kr__h2">Что требуют работодатели — по частоте</h2>
                <p class="gs-kr__sub">Слева — сколько раз требование встретилось в выборке. Справа — модуль программы,
                    который его закрывает.</p>
                <ul class="gs-kr__dlist">
                    <?php foreach (self::demands() as $d) : ?>
                        <li class="gs-kr__d">
                            <span class="gs-kr__d-num"><?php echo (int) $d[0]; ?></span>
                            <span class="gs-kr__d-body">
                                <strong class="gs-kr__d-name"><?php echo esc_html($d[1]); ?></strong>
                                <span class="gs-kr__d-note"><?php echo esc_html($d[2]); ?></span>
                            </span>
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
                            <span class="gs-kr__mod-n"><?php echo (int) $n; ?></span>
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

            <section class="gs-kr__folio">
                <?php echo self::waves(); ?>
                <h2 class="gs-kr__h2">Что остаётся на руках</h2>
                <p class="gs-kr__sub">Шесть работ, собранных под требование «портфолио с роликами», и посчитанная смета,
                    по которой видно себестоимость каждой работы.</p>
                <ul class="gs-kr__cards">
                    <?php foreach (self::portfolio() as $i => $p) : ?>
                        <li class="gs-kr__card">
                            <span class="gs-kr__card-n"><?php echo (int) ($i + 1); ?></span>
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

            <section class="gs-kr__how">
                <h2 class="gs-kr__h2">Как устроено обучение</h2>
                <div class="gs-kr__how-grid">
                    <div class="gs-kr__how-item">
                        <h3>Материалы в записи</h3>
                        <p>Смотрите когда удобно. Прямых эфиров нет намеренно: половина заказчиков и учеников живёт не
                            в московском времени, и обязательный вебинар в 19:00 попадает кому-то на ночь.</p>
                    </div>
                    <div class="gs-kr__how-item">
                        <h3>Задания после каждого модуля</h3>
                        <p>Не тесты, а работа: ролик, серия кадров, смета. Сдаётся файлами и ссылкой, проверяется
                            письменно — с указанием, что именно переделать и почему.</p>
                    </div>
                    <div class="gs-kr__how-item">
                        <h3>Практика на рабочих сервисах</h3>
                        <p>Не на демо-доступах: генерация, озвучка, звук и расшифровка идут на тех же сервисах, на
                            которых работают за деньги, с оплатой за запуск и видимой себестоимостью.</p>
                    </div>
                    <div class="gs-kr__how-item">
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

            <section class="gs-kr__tools">
                <h2 class="gs-kr__h2">На чём идёт практика</h2>
                <p class="gs-kr__sub">Все операции — с одного рублёвого баланса и с оплатой за запуск, без подписок.
                    Расход на всё обучение укладывается примерно в 3 000 ₽.</p>
                <ul class="gs-kr__tgrid">
                    <?php foreach (self::tools() as $t) : ?>
                        <li class="gs-kr__tool">
                            <?php if ($t[2] !== '') : ?>
                                <a href="<?php echo esc_url($t[2]); ?>"><?php echo esc_html($t[0]); ?></a>
                            <?php else : ?>
                                <span><?php echo esc_html($t[0]); ?></span>
                            <?php endif; ?>
                            <em><?php echo esc_html($t[1]); ?></em>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <p class="gs-kr__sub">Из рыночных инструментов в программе разбираем те, что названы в самих
                    объявлениях: Midjourney, Stable Diffusion, ComfyUI, Kling, Runway, Seedance, Higgsfield,
                    Premiere Pro, DaVinci Resolve, After Effects, CapCut, Figma.</p>
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
                            <li>Помощь с первыми откликами и сопроводительным</li>
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
                </ul>
            </section>

        </div>
        <?php
        return ob_get_clean();
    }
}
