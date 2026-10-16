<?php
/**
 * SEO каталога звуков.
 *
 * Все 945 категорий живут на одной WP-странице, поэтому и тема, и базовый плагин
 * выдавали им одинаковые теги: общий <title>, общее описание, canonical на
 * /sounds-catalog/ и разметку Article/FAQPage от страницы озвучки. Для поисковика
 * это означало, что каждая категория — дубль индекса каталога.
 *
 * Заголовок правим фильтром, остальное — переписыванием буфера wp_head:
 * теги печатают сразу несколько источников (тема us-core и kie-tts-wp),
 * у которых нет общих фильтров.
 */

if (!defined('ABSPATH')) {
    exit;
}

class GS_Seo {

    /** @var array|null Контекст текущего запроса каталога. */
    private static $ctx = null;
    private static $resolved = false;

    public static function boot() {
        add_filter('document_title_parts', array(__CLASS__, 'filter_title_parts'), PHP_INT_MAX);
        // Часть страниц заголовок получает от стороннего оптимизатора,
        // который обходит разбор по частям. Здесь подрезаем хвост у любого
        // готового заголовка — «Genius-bot — Разработка чат ботов в телеграм
        // и ВК для бизнеса» съедает сорок знаков выдачи на пустом месте.
        add_filter('pre_get_document_title', array(__CLASS__, 'trim_site_tail'), 9999);
        add_filter('the_content', array(__CLASS__, 'promote_front_heading'), 9);
        add_action('wp_head', array(__CLASS__, 'start_buffer'), 0);
        add_action('wp_head', array(__CLASS__, 'flush_buffer'), PHP_INT_MAX);
        add_action('template_redirect', array(__CLASS__, 'maybe_send_404'));
    }

    /**
     * Несуществующий слаг категории отдавал 200 с текстом «не найдено» —
     * для поисковика это мягкий 404 и мусорная страница в индексе.
     */
    public static function maybe_send_404() {
        if (is_admin() || !GS_Catalog::is_catalog_request()) {
            return;
        }
        $slug = GS_Catalog::requested_slug();
        if ($slug === '' || GS_Catalog::get_category($slug) || GS_Sections::get($slug)) {
            return;
        }
        status_header(404);
        nocache_headers();
    }

    /* ---------------------------------------------------------------------
     * Контекст
     * ------------------------------------------------------------------ */

    /**
     * @return array|null {type, category, page, pages, query}
     */
    private static function context() {
        if (self::$resolved) {
            return self::$ctx;
        }
        self::$resolved = true;
        self::$ctx = null;

        if (is_front_page()) {
            self::$ctx = array(
                'type'     => 'home',
                'service'  => null,
                'category' => null,
                'page'     => 1,
                'pages'    => 1,
                'query'    => '',
            );
            return self::$ctx;
        }

        // Каталог промтов — полторы тысячи страниц, и до сих пор ими
        // распоряжался чужой плагин: в описании у всех стояло «[genius_prompts]»
        // (текст шорткода), а к заголовку приклеивался хвост про разработку
        // чат-ботов. Свои заголовок и описание у этих страниц есть давно —
        // не хватало только того, кто уберёт чужие теги.
        if (class_exists('GS_Prompts_Page') && GS_Prompts_Page::is_page()) {
            self::$ctx = array(
                'type'     => 'prompt',
                'service'  => null,
                'url'      => GS_Prompts_Page::seo_canonical(),
                'category' => null,
                // У отдельного промта страниц списка нет: иначе в голову
                // попадает rel=next на самого себя.
                'page'     => GS_Prompts_Page::current_item() ? 1 : GS_Prompts_Page::current_page(),
                'pages'    => GS_Prompts_Page::current_item() ? 1 : GS_Prompts_Page::page_count(),
                'query'    => GS_Prompts_Page::current_item() ? '' : GS_Prompts_Page::search_query(),
            );
            return self::$ctx;
        }

        // Блог: описание ему собирал конструктор страницы, и в выдаче
        // стоял текст шорткода — «[vc_row css="%7B%22default%22…». Своего
        // описания у главной страницы блога не было вовсе.
        $blog = get_page_by_path('blog');
        if ($blog instanceof WP_Post && is_page($blog->ID)) {
            self::$ctx = array(
                'type'     => 'blog',
                'service'  => null,
                'url'      => get_permalink($blog),
                'category' => null,
                'page'     => 1,
                'pages'    => 1,
                'query'    => '',
            );
            return self::$ctx;
        }

        // Оферта и реквизиты — обычные страницы, и заголовок окна им
        // достаётся от темы вместе с длинным хвостом про разработку ботов.
        $doc = self::doc_page();
        if ($doc) {
            self::$ctx = array(
                'type'     => 'docpage',
                'service'  => null,
                'doc'      => $doc,
                'url'      => $doc['url'],
                'category' => null,
                'page'     => 1,
                'pages'    => 1,
                'query'    => '',
            );
            return self::$ctx;
        }

        if (class_exists('GS_Neurohub') && GS_Neurohub::is_page()) {
            self::$ctx = array(
                'type'     => 'neurohub',
                'service'  => null,
                'url'      => GS_Neurohub::url(),
                'category' => null,
                'page'     => 1,
                'pages'    => 1,
                'query'    => '',
            );
            return self::$ctx;
        }

        if (GS_Api_Page::is_page()) {
            self::$ctx = array(
                'type'     => 'api',
                'service'  => null,
                'category' => null,
                'page'     => 1,
                'pages'    => 1,
                'query'    => '',
            );
            return self::$ctx;
        }

        $lab = GS_Lab::current_service();
        if ($lab) {
            self::$ctx = array(
                'type'     => 'lab',
                'service'  => $lab,
                'url'      => GS_Lab::get_url($lab['id']),
                'category' => null,
                'page'     => 1,
                'pages'    => 1,
                'query'    => '',
            );
            return self::$ctx;
        }

        // Посадочная под коммерческий запрос: инструмент общий с сервисом,
        // но заголовки, описание и canonical — собственные, иначе поисковик
        // объявит её дублем страницы сервиса.
        $landing = GS_Landing::current();
        if ($landing) {
            $service = GS_Landing::as_service($landing);
            if ($service) {
                self::$ctx = array(
                    'type'     => 'lab',
                    'service'  => $service,
                    'url'      => GS_Landing::get_url($landing['id']),
                    'category' => null,
                    'page'     => 1,
                    'pages'    => 1,
                    'query'    => '',
                );
                return self::$ctx;
            }
        }

        if (is_admin() || !GS_Catalog::is_catalog_request()) {
            return null;
        }

        $page  = GS_Catalog::requested_page();
        $query = isset($_GET['gs_q']) ? sanitize_text_field(wp_unslash((string) $_GET['gs_q'])) : '';
        $slug  = GS_Catalog::requested_slug();

        if ($slug !== '') {
            $category = GS_Catalog::get_category($slug);
            if (!$category) {
                $section = GS_Sections::get($slug);
                if (!$section) {
                    return null;
                }
                $cats = GS_Sections::categories($slug);
                $pages = max(1, (int) ceil(count($cats) / GS_Catalog::CATS_PER_PAGE));
                self::$ctx = array(
                    'type'     => 'section',
                    'section'  => $section,
                    'category' => null,
                    'page'     => min($page, $pages),
                    'pages'    => $pages,
                    'query'    => '',
                    'sounds'   => array_sum(array_map(function ($row) {
                        return (int) ($row['count'] ?? 0);
                    }, $cats)),
                    'cats'     => count($cats),
                );
                return self::$ctx;
            }
            $pages = max(1, (int) ceil(GS_Catalog::count_sounds($category) / GS_Catalog::SOUNDS_PER_PAGE));
            self::$ctx = array(
                'type'     => 'category',
                'category' => $category,
                'page'     => min($page, $pages),
                'pages'    => $pages,
                'query'    => '',
            );
            return self::$ctx;
        }

        $pages = max(1, (int) ceil(count(GS_Catalog::load_index()) / GS_Catalog::CATS_PER_PAGE));
        self::$ctx = array(
            'type'     => 'index',
            'category' => null,
            'page'     => min($page, $pages),
            'pages'    => $pages,
            'query'    => $query,
        );
        return self::$ctx;
    }

    /* ---------------------------------------------------------------------
     * Заголовок
     * ------------------------------------------------------------------ */

    /**
     * Возвращаем заголовку полную длину.
     *
     * Сторонний оптимизатор обрезает <title> на 48 знаках и дописывает
     * многоточие: у записей блога из заголовка вылетала вторая половина —
     * та, где стоят уточняющие слова запроса. Мы идём последними в цепочке
     * фильтров, поэтому просто подставляем настоящий заголовок обратно.
     *
     * Трогаем только тот случай, когда показанный заголовок — действительно
     * обрезок настоящего: чужие осмысленные правки остаются как есть.
     */
    /**
     * Наши документные страницы: что на них за документ и как его назвать
     * в выдаче.
     */
    /**
     * Страницы озвучки: заголовок и описание у всех восьми были одни.
     *
     * Их рисует соседний плагин, и описание он ставит одно на весь свой
     * раздел — «AI озвучка текста: голоса, диалоги, API интеграции…».
     * В выдаче это восемь страниц с одинаковым описанием, включая цены и
     * документацию API. Текст ниже собран по тому, что на самих страницах
     * написано: цифры тарифов — оттуда же.
     */
    const SERVICE_PAGES = array(
        '/tts-pricing/' => array(
            'title' => 'Цены на озвучку текста — 18 ₽ за 1000 символов',
            'desc'  => 'Сколько стоит озвучка текста: 18 ₽ за каждые 1000 символов, минимум 18 ₽, '
                     . 'диалоговая модель — плюс 30%. Бесплатные голоса — 0 ₽. Форматы MP3, Opus и WAV.',
        ),
        '/tts-cases/' => array(
            'title' => 'Кейсы озвучки: маркетплейсы, курсы, подкасты',
            'desc'  => 'Где применяют синтез речи: карточки товаров на маркетплейсах одним голосом, '
                     . 'уроки онлайн-курсов с разными персонажами, аудиоверсии статей и вставки в подкасты.',
        ),
        '/tts-integrations/' => array(
            'title' => 'Интеграции озвучки: n8n, Make и Telegram-боты',
            'desc'  => 'Подключение API озвучки к автоматизациям: HTTP-запрос в n8n, сценарий в Make '
                     . 'и голосовые ответы в Telegram-боте. Запуск генерации, опрос статуса, скачивание файла.',
        ),
        '/api-docs/' => array(
            'title' => 'API озвучки: ключ, эндпоинты и примеры запросов',
            'desc'  => 'Документация API озвучки: авторизация по ключу, список эндпоинтов, примеры JSON '
                     . 'и cURL, пошаговый сценарий интеграции для внешних сервисов и ботов.',
        ),
        '/profit/' => array(
            'title' => 'Франшиза AI-озвучки под ключ за 7–14 дней',
            'desc'  => 'Готовый сервис озвучки и расшифровки под вашим брендом: сайт, API, приём платежей, '
                     . 'Telegram-бот и автоворонки. Запуск за 7–14 дней, white-label на вашем домене.',
        ),
        '/solutions/' => array(
            'title' => 'Профессия разработчика чат-ботов — что это за работа',
            'desc'  => 'Чем занимается разработчик чат-ботов, какие задачи закрывает бот в бизнесе '
                     . 'и почему спрос на эту работу растёт. Разбор профессии от тех, кто делает ботов.',
        ),
        '/contact/' => array(
            'title' => 'Контакты: связь, почта и реквизиты',
            'desc'  => 'Как с нами связаться: Telegram, электронная почта и реквизиты исполнителя — '
                     . 'имя и ИНН. Вопросы по услугам и предложения о сотрудничестве.',
        ),
        '/tts-login/' => array(
            'title' => 'Вход в кабинет озвучки текста',
            'desc'  => 'Вход в кабинет озвучки: больше двадцати голосов, настройка скорости, стиля и '
                     . 'эмоций, история генераций и выгрузка в MP3, Opus или WAV.',
        ),
        '/tts-register/' => array(
            'title' => 'Регистрация в сервисе озвучки текста',
            'desc'  => 'Регистрация в сервисе озвучки: доступ к голосам, настройкам стиля и скорости, '
                     . 'истории генераций и ключу API. Нужны только почта и пароль.',
        ),
    );

    private static function service_page() {
        if (is_admin()) {
            return null;
        }
        $path = (string) wp_parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
        $path = '/' . trim($path, '/') . '/';
        if (!isset(self::SERVICE_PAGES[$path])) {
            return null;
        }
        $row = self::SERVICE_PAGES[$path];
        return array(
            'url'   => home_url($path),
            'title' => (string) $row['title'],
            'desc'  => (string) $row['desc'],
        );
    }

    private static function doc_page() {
        $service = self::service_page();
        if ($service) {
            return $service;
        }
        if (!class_exists('GS_Requisites') || !is_page()) {
            return null;
        }
        $id = (int) get_queried_object_id();
        if ($id <= 0) {
            return null;
        }
        $oferta = (int) get_option(GS_Requisites::OPT_OFERTA);
        if ($oferta > 0 && $id === $oferta) {
            return array(
                'url'   => get_permalink($id),
                'title' => 'Публичная оферта — договор об оказании услуг',
                'desc'  => 'Публичная оферта Genius-bot: предмет договора, права и обязанности сторон, '
                         . 'цена и порядок расчётов, возврат средств и реквизиты исполнителя.',
            );
        }
        if (class_exists('GS_Consult') && GS_Consult::is_page()) {
            return array(
                'url'   => GS_Consult::get_url(),
                'title' => 'Заказать консультацию по чат-боту для Telegram и ВК',
                'desc'  => 'Разберём задачу бесплатно: что должен делать бот, из каких частей его собрать, '
                         . 'сколько это займёт и во что обойдётся. Ответ в тот же день — опишите задачу в форме.',
            );
        }
        $privacy = (int) get_option(GS_Requisites::OPT_PRIVACY);
        if ($privacy > 0 && $id === $privacy) {
            return array(
                'url'   => get_permalink($id),
                'title' => 'Политика обработки персональных данных',
                'desc'  => 'Какие данные собирает genius-bot.ru, зачем, на каком основании и '
                         . 'как их удалить: оператор, перечень данных, цели обработки и связь с оператором.',
            );
        }
        $req = (int) get_option(GS_Requisites::OPT_PAGE);
        if ($req > 0 && $id === $req) {
            return array(
                'url'   => get_permalink($id),
                'title' => 'Реквизиты и документы',
                'desc'  => 'Кто оказывает услуги на genius-bot.ru: исполнитель, ИНН, связь, '
                         . 'публичная оферта, политика обработки данных и правила возврата.',
            );
        }
        return null;
    }

    private static function restore_full_title($parts) {
        if (is_admin() || !is_singular() || empty($parts['title'])) {
            return $parts;
        }
        $post = get_queried_object();
        if (!($post instanceof WP_Post)) {
            return $parts;
        }
        $full = trim(wp_strip_all_tags(get_the_title($post)));
        if ($full === '') {
            return $parts;
        }
        // У записи блога заголовок самодостаточен: имя сайта — целое
        // предложение про чат-ботов, и в выдаче оно съедает место, а то и
        // вытесняет вторую половину запроса. Сторонний оптимизатор убирал
        // его только у длинных заголовков, поэтому вид страниц блога
        // зависел от длины названия. Убираем хвост у всех записей.
        if ($post->post_type === 'post') {
            unset($parts['tagline'], $parts['site']);
            $parts['title'] = $full;
            return $parts;
        }
        $shown = trim((string) $parts['title']);
        if ($shown === $full) {
            return $parts;
        }
        // Отрезаем хвостовое многоточие и сверяем начало строк.
        $stem = rtrim($shown, ". \xe2\x80\xa6");
        if ($stem !== '' && mb_strpos($full, $stem) === 0) {
            $parts['title'] = $full;
        }
        return $parts;
    }

    /**
     * На главной не было H1 вовсе: заголовок первого экрана свёрстан как H2.
     * Повышаем именно его — это и есть главный заголовок страницы, и трогать
     * ради этого содержимое в конструкторе не нужно.
     */
    public static function promote_front_heading($content) {
        if (is_admin() || !is_front_page() || !in_the_loop() || !is_main_query()) {
            return $content;
        }
        if (stripos($content, '<h1') !== false) {
            return $content;
        }
        $done = false;
        return (string) preg_replace_callback(
            '~<h2([^>]*)>(.*?)</h2>~is',
            function ($m) use (&$done) {
                if ($done) {
                    return $m[0];
                }
                $done = true;
                return '<h1' . $m[1] . '>' . $m[2] . '</h1>';
            },
            $content,
            1
        );
    }

    public static function filter_title_parts($parts) {
        $ctx = self::context();
        if (!$ctx) {
            $parts = self::restore_full_title($parts);
            // Имя сайта в заголовке — целое предложение про разработку
            // чат-ботов. На страницах, которыми мы не распоряжаемся, оно
            // приклеивалось целиком и съедало до сорока знаков выдачи:
            // «Кейсы озвучки — Genius-bot — Разработка чат ботов в телеграм
            // и ВК для бизнеса». Оставляем только бренд.
            if (!empty($parts['site'])) {
                $parts['site'] = self::brand_name((string) $parts['site']);
            }
            return $parts;
        }
        $parts['title'] = self::build_title($ctx);
        unset($parts['tagline']);

        // Имя сайта здесь — целое предложение («Genius-bot - Разработка чат ботов…»),
        // из-за него заголовок категории обрезается в выдаче. Оставляем только бренд.
        if (!empty($parts['site'])) {
            $parts['site'] = self::brand_name((string) $parts['site']);
        }
        return $parts;
    }

    public static function trim_site_tail($title) {
        if (!is_string($title) || $title === '') {
            return $title;
        }
        $site = trim((string) get_bloginfo('name'));
        $brand = self::brand_name($site);
        if ($site !== '' && $brand !== '' && $brand !== $site && mb_strpos($title, $site) !== false) {
            $title = str_replace($site, $brand, $title);
        }
        return $title;
    }

    private static function brand_name($site) {
        $parts = preg_split('~\s+[-—–|]\s+~u', trim($site), 2);
        $brand = (is_array($parts) && !empty($parts[0])) ? trim($parts[0]) : trim($site);
        return $brand !== '' ? $brand : $site;
    }

    /**
     * Уложить заголовок в длину, не ломая фразу.
     *
     * Раньше лишнее просто отрезалось по символу и приклеивалось
     * многоточие — в выдаче получалось «готовые сэмплы для Soundpad и
     * дискор… — Genius-bot». Обрывок слова выглядит как сломанная
     * страница, и по таким заголовкам не кликают: на шестой позиции CTR
     * падал до полупроцента при пяти сотнях показов.
     *
     * Режем по границе слова и без многоточия: укороченная фраза читается
     * как законченная. Если в заголовке есть тире, предпочитаем оборвать
     * по нему — головная часть обычно и есть сам запрос.
     */
    private static function fit_title($title, $limit = 60) {
        $title = trim(preg_replace('~\s+~u', ' ', (string) $title));
        if (mb_strlen($title) <= $limit) {
            return $title;
        }

        // Хвост после тире — пояснение; без него заголовок остаётся целым.
        // Но только если головная часть сама по себе содержательна: у
        // «Смешные мемные звуки — готовые сэмплы…» голова занимает треть
        // отведённой длины, и отрезать по тире значит выбросить половину
        // полезных слов там, где они помещались.
        $head = preg_split('~\s+[—–-]\s+~u', $title, 2);
        if (is_array($head) && mb_strlen($head[0]) <= $limit && mb_strlen($head[0]) >= $limit * 0.6) {
            return trim($head[0]);
        }

        $cut = mb_substr($title, 0, $limit);
        $space = mb_strrpos($cut, ' ');
        if ($space !== false && $space >= 20) {
            $cut = mb_substr($cut, 0, $space);
        }
        // Предлог или союз на конце выглядит обрывом не меньше многоточия.
        $cut = preg_replace('~\s+(и|или|для|с|со|на|в|во|по|из|от|до|за|под|при|про|к|о|об)$~ui', '', $cut);
        return trim($cut, " \t\n\r—–-,:;");
    }

    private static function build_title($ctx) {
        if ($ctx['type'] === 'home') {
            // Заголовок обязан отвечать содержимому: первый экран и почти вся
            // страница — про разработку ботов, сервисы идут ниже.
            return 'Разработка чат-ботов для Telegram и ВК под ключ';
        }
        if ($ctx['type'] === 'prompt') {
            return GS_Prompts_Page::seo_title();
        }
        if ($ctx['type'] === 'docpage') {
            return (string) $ctx['doc']['title'];
        }
        if ($ctx['type'] === 'blog') {
            return 'Блог про нейросети: инструкции, разборы и промты';
        }
        if ($ctx['type'] === 'neurohub') {
            return GS_Neurohub::seo_title();
        }
        if ($ctx['type'] === 'api') {
            return 'API для разработчиков — нейросети для фото, видео и звука';
        }
        if ($ctx['type'] === 'lab') {
            return (string) $ctx['service']['seo_title'];
        }
        if ($ctx['type'] === 'section') {
            $title = self::fit_title((string) $ctx['section']['title']);
            if ($ctx['page'] > 1) {
                $title .= ' — страница ' . $ctx['page'];
            }
            return $title;
        }
        if ($ctx['type'] === 'index') {
            $title = 'Каталог звуков — скачать бесплатно в MP3';
            if ($ctx['query'] !== '') {
                $title = 'Поиск: ' . $ctx['query'] . ' — каталог звуков';
            } elseif ($ctx['page'] > 1) {
                $title .= ', страница ' . $ctx['page'];
            }
            return $title;
        }

        $category = $ctx['category'];
        $title = trim((string) ($category['title'] ?? ''));
        if ($title === '') {
            $title = GS_Catalog::short_title((string) $category['slug']);
        }
        $count = GS_Catalog::count_sounds($category);
        $suffix = $count > 0 ? ' — скачать бесплатно, ' . $count . ' MP3' : '';

        // «Скачать бесплатно» важнее описательного хвоста.
        //
        // В Вебмастере видно, чем это оборачивалось: «звук печатной машинки»
        // — 162 показа и ноль кликов, «звук бритвы» — 147 и ноль, «звук
        // медведя» — 584 показа и три клика, все на шестой-девятой позиции.
        // В заголовке у них стояло «стук клавиш и каретка», а человек на
        // этом запросе ищет файл, который можно скачать. Поэтому если
        // полный заголовок с хвостом не помещается в шестьдесят знаков,
        // сначала пробуем голову до тире плюс хвост, и только потом —
        // обрезку по словам без хвоста.
        if (mb_strlen($title . $suffix) <= 60) {
            $title .= $suffix;
        } else {
            $head = preg_split('~\s+[—–-]\s+~u', $title, 2);
            $head = (is_array($head) && isset($head[0])) ? trim($head[0]) : '';
            if ($suffix !== '' && $head !== '' && mb_strlen($head) >= 9
                && mb_strlen($head . $suffix) <= 60) {
                $title = $head . $suffix;
            } else {
                $title = self::fit_title($title);
            }
        }
        if ($ctx['page'] > 1) {
            $title .= ' — страница ' . $ctx['page'];
        }
        return $title;
    }

    /* ---------------------------------------------------------------------
     * Описание и адреса
     * ------------------------------------------------------------------ */

    private static function build_description($ctx) {
        if ($ctx['type'] === 'home') {
            return 'Разработка чат-ботов для Telegram и ВКонтакте под задачи бизнеса: продажи, поддержка, интеграция с CRM. А ещё нейросети для звука и видео: озвучка текста, генерация музыки, расшифровка записей.';
        }
        if ($ctx['type'] === 'prompt') {
            return GS_Prompts_Page::seo_desc();
        }
        if ($ctx['type'] === 'docpage') {
            return (string) $ctx['doc']['desc'];
        }
        if ($ctx['type'] === 'blog') {
            return 'Разборы нейросетей и пошаговые инструкции: как собрать телеграм-бота, '
                 . 'оживить фото, озвучить текст, составить промт для фотосессии. '
                 . 'Примеры и цифры из наших же сервисов.';
        }
        if ($ctx['type'] === 'neurohub') {
            return GS_Neurohub::seo_desc();
        }
        if ($ctx['type'] === 'api') {
            return 'HTTP API нейросетей: оживление фото, говорящий аватар, редактирование картинок, генерация звуков и озвучка текста. REST и JSON, ключ доступа, вебхук о готовности, оплата за запуск без абонплаты.';
        }
        if ($ctx['type'] === 'lab') {
            return (string) $ctx['service']['seo_desc'];
        }
        if ($ctx['type'] === 'section') {
            return self::with_page_note(sprintf(
                '%s %s в %s. Слушайте онлайн и скачивайте бесплатно в MP3 — для монтажа, роликов, игр и стримов.',
                (string) $ctx['section']['lead'],
                GS_Catalog::plural_sounds((int) $ctx['sounds']),
                GS_Catalog::plural_categories_text((int) $ctx['cats'])
            ), $ctx);
        }
        if ($ctx['type'] === 'index') {
            $stats = GS_Catalog::stats();
            return self::with_page_note(sprintf(
                'Бесплатный каталог звуков и звуковых эффектов: %s звуков в %s категориях. Слушайте онлайн и скачивайте в MP3 для монтажа, игр и роликов. Нужного звука нет — создайте его нейросетью.',
                number_format_i18n($stats['sounds']),
                number_format_i18n($stats['categories'])
            ), $ctx);
        }

        $category = $ctx['category'];
        $text = trim((string) ($category['description'] ?? ''));
        if ($text === '') {
            $text = trim((string) ($category['headline'] ?? ''));
        }
        if ($text === '') {
            $count = GS_Catalog::count_sounds($category);
            $text = GS_Catalog::short_title((string) $category['title']) . ': '
                . ($count > 0 ? GS_Catalog::plural_sounds($count) . ' — ' : '')
                . 'слушайте онлайн и скачивайте бесплатно в MP3.';
        }
        $text = GS_Catalog::sync_description_count($text, GS_Catalog::count_sounds($category));
        $text = preg_replace('~\s+~u', ' ', $text);
        $text = mb_strlen($text) > 300 ? mb_substr($text, 0, 297) . '…' : $text;
        return self::with_page_note($text, $ctx);
    }

    /**
     * Приписать к описанию номер страницы списка.
     *
     * Заголовок у второй страницы подборки отличался, а описание — нет:
     * в Вебмастере это группа дублей, и из неё в индексе остаётся одна
     * страница вместе со всеми ссылками, которые только на ней и стоят.
     */
    private static function with_page_note($text, $ctx) {
        $page  = (int) $ctx['page'];
        $pages = (int) $ctx['pages'];
        if ($page < 2 || $pages < 2) {
            return $text;
        }
        return rtrim($text) . ' Страница ' . $page . ' из ' . $pages . '.';
    }

    /**
     * Адрес страницы с учётом пагинации — canonical должен быть свой у каждой
     * страницы списка, иначе категории со второй страницы и дальше объявляются
     * дублями первой и выпадают из индекса вместе со своими ссылками.
     */
    private static function page_url($ctx, $page = null) {
        if ($ctx['type'] === 'home') {
            return home_url('/');
        }
        if ($ctx['type'] === 'prompt') {
            // Соседние страницы списка — только для самого списка:
            // у отдельного промта номера страницы нет, у него свой адрес.
            if ($page === null || GS_Prompts_Page::current_item()) {
                return (string) $ctx['url'];
            }
            $rubric = GS_Prompts_Page::current_rubric();
            return $rubric !== ''
                ? GS_Prompts_Page::rubric_url($rubric, (int) $page)
                : GS_Prompts_Page::page_url((int) $page);
        }
        if ($ctx['type'] === 'docpage' || $ctx['type'] === 'blog') {
            return (string) $ctx['url'];
        }
        if ($ctx['type'] === 'neurohub') {
            return !empty($ctx['url']) ? $ctx['url'] : GS_Neurohub::url();
        }
        if ($ctx['type'] === 'api') {
            return GS_Api_Page::get_url();
        }
        if ($ctx['type'] === 'lab') {
            return !empty($ctx['url']) ? $ctx['url'] : GS_Lab::get_url($ctx['service']['id']);
        }
        $page = $page === null ? (int) $ctx['page'] : (int) $page;
        if ($ctx['type'] === 'category') {
            return GS_Catalog::page_url((string) $ctx['category']['slug'], $page);
        }
        if ($ctx['type'] === 'section') {
            return GS_Catalog::page_url((string) $ctx['section']['slug'], $page);
        }
        return GS_Catalog::page_url('', $page);
    }

    /* ---------------------------------------------------------------------
     * Переписывание head
     * ------------------------------------------------------------------ */

    /**
     * Буфер головы нужен и там, где своего контекста у нас нет: теги для
     * соцсетей печатают несколько источников на любой странице сайта, и
     * прибрать их можно только здесь.
     */
    public static function start_buffer() {
        if (is_admin() || is_feed()) {
            return;
        }
        ob_start();
    }

    public static function flush_buffer() {
        $ctx = self::context();
        $html = ob_get_clean();
        if ($html === false) {
            return;
        }
        if (!$ctx) {
            echo self::tidy_social($html); // phpcs:ignore WordPress.Security.EscapeOutput
            return;
        }

        $url  = self::page_url($ctx);
        $desc = self::build_description($ctx);

        // Тема дописывает к заголовку полное имя сайта уже после наших фильтров,
        // поэтому собираем его здесь целиком. На главной бренд идёт первым:
        // по названию сайта ищут именно его.
        $title = self::build_title($ctx);
        if ($ctx['type'] !== 'home') {
            $title .= ' — ' . self::brand_name(get_bloginfo('name'));
        } else {
            $title = self::brand_name(get_bloginfo('name')) . ' — ' . $title;
        }
        $html = self::replace_tag($html, '~<title>.*?</title>~is', '<title>' . esc_html($title) . '</title>');
        $html = self::replace_tag(
            $html,
            '~<link[^>]+rel=["\']canonical["\'][^>]*>~i',
            '<link rel="canonical" href="' . esc_url($url) . '">'
        );
        $html = self::replace_tag(
            $html,
            '~<meta[^>]+name=["\']description["\'][^>]*>~i',
            '<meta name="description" content="' . esc_attr($desc) . '">'
        );
        $html = self::rebuild_social($html, $ctx, $title, $desc, $url);

        // Заменять нечего, если тега нет вовсе: на странице нейрохаба
        // canonical не печатал никто, и страница оставалась без него.
        //
        // Только там: на других страницах свой canonical печатает соседний
        // плагин, и часть из них делает это раньше, чем начинается наш
        // буфер — дописав ещё один, мы получали два тега вместо одного.
        if ($ctx['type'] === 'neurohub' && !preg_match('~rel=["\']canonical["\']~i', $html)) {
            $html .= '<link rel="canonical" href="' . esc_url($url) . '">' . "\n";
        }

        if ($ctx['type'] !== 'home') {
            $html = self::strip_foreign_schema($html);
        }

        echo $html; // phpcs:ignore WordPress.Security.EscapeOutput
        echo self::extra_tags($ctx); // phpcs:ignore WordPress.Security.EscapeOutput
    }

    /**
     * Описание и canonical — по одному на страницу.
     *
     * На странице песни описание печаталось трижды, у входа в озвучку —
     * дважды с разным текстом и два canonical подряд. А у страниц, которые
     * собраны конструктором, в описании стоял его же код: на /blog/ — «[vc_row
     * css="%7B%22default%22…», у презентаций — «[genius_slides]». Такое
     * описание в выдаче хуже отсутствующего: поиск сам соберёт сниппет из
     * текста, если описания нет.
     */
    private static function dedupe_head($html) {
        $seen = 0;
        $html = (string) preg_replace_callback(
            '~[ \t]*<meta[^>]+name=["\']description["\'][^>]*>\n?~i',
            function ($m) use (&$seen) {
                $seen++;
                if ($seen > 1) {
                    return '';
                }
                return preg_match('~content=["\']([^"\']*)~i', $m[0], $c) && self::junk_text($c[1])
                    ? ''
                    : $m[0];
            },
            $html
        );
        $seen = 0;
        return (string) preg_replace_callback(
            '~[ \t]*<link[^>]+rel=["\']canonical["\'][^>]*>\n?~i',
            function ($m) use (&$seen) {
                $seen++;
                return $seen > 1 ? '' : $m[0];
            },
            $html
        );
    }

    /** Похоже ли это на код конструктора или шорткод, а не на текст. */
    private static function junk_text($text) {
        $text = trim((string) $text);
        if ($text === '') {
            return false;
        }
        return strpos($text, '[') === 0
            || strpos($text, 'vc_row') !== false
            || strpos($text, '%7B%22') !== false
            || strpos($text, '[/') !== false;
    }

    /**
     * Страница не наша, но теги для соцсетей всё равно убираем в порядок.
     *
     * Заголовок и описание берём из самой головы — их печатает тема или
     * соседний плагин, и спорить с ними незачем. Наша работа здесь —
     * оставить один набор вместо нескольких и добавить картинку, чтобы
     * ссылка в мессенджере приходила с превью.
     */
    private static function tidy_social($html) {
        $html = self::dedupe_head($html);
        if (!preg_match('~(?:og|twitter):~i', $html)) {
            return $html;
        }
        $title = '';
        if (preg_match('~<title[^>]*>(.*?)</title>~is', $html, $m)) {
            $title = trim(html_entity_decode(wp_strip_all_tags($m[1]), ENT_QUOTES, 'UTF-8'));
        }
        if ($title === '') {
            $title = wp_get_document_title();
        }
        $desc = '';
        if (preg_match('~<meta[^>]+name=["\']description["\'][^>]+content=["\']([^"\']*)~i', $html, $m)) {
            $desc = trim(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'));
        }
        $url = '';
        if (preg_match('~<link[^>]+rel=["\']canonical["\'][^>]+href=["\']([^"\']*)~i', $html, $m)) {
            $url = $m[1];
        }
        if ($url === '') {
            $url = home_url(add_query_arg(array()));
        }
        if (self::junk_text($desc)) {
            $desc = '';
        }
        return self::rebuild_social($html, array('type' => 'other'), $title, $desc, $url);
    }

    /**
     * Теги для соцсетей: один набор вместо трёх.
     *
     * og:title печатался трижды, og:type — дважды и с разными значениями
     * («article» и «website»), og:locale и og:site_name по три раза, а в
     * twitter:description у страниц каталога стоял текст чужого шорткода
     * «[kie_tts_sounds_catalog]». Картинки og:image у страниц сервисов не
     * было вовсе: ссылка в мессенджере приходила без превью.
     *
     * Поэтому все чужие теги og: и twitter: из головы убираем и печатаем
     * свой набор — с тем же заголовком и описанием, что и в выдаче.
     */
    private static function rebuild_social($html, $ctx, $title, $desc, $url) {
        $html = (string) preg_replace(
            '~[ \t]*<meta[^>]+(?:property|name)=["\'](?:og|twitter):[^"\']*["\'][^>]*>\s*~i',
            '',
            $html
        );

        $image = self::social_image($ctx);
        $tags = array(
            '<meta property="og:type" content="website">',
            '<meta property="og:site_name" content="' . esc_attr(self::brand_name(get_bloginfo('name'))) . '">',
            '<meta property="og:locale" content="ru_RU">',
            '<meta property="og:title" content="' . esc_attr($title) . '">',
            '<meta property="og:url" content="' . esc_url($url) . '">',
            '<meta name="twitter:card" content="summary_large_image">',
            '<meta name="twitter:title" content="' . esc_attr($title) . '">',
        );
        if ($desc !== '') {
            $tags[] = '<meta property="og:description" content="' . esc_attr($desc) . '">';
            $tags[] = '<meta name="twitter:description" content="' . esc_attr($desc) . '">';
        }
        if (!empty($image['url'])) {
            $tags[] = '<meta property="og:image" content="' . esc_url($image['url']) . '">';
            if (!empty($image['w'])) {
                $tags[] = '<meta property="og:image:width" content="' . (int) $image['w'] . '">';
                $tags[] = '<meta property="og:image:height" content="' . (int) $image['h'] . '">';
            }
            $tags[] = '<meta name="twitter:image" content="' . esc_url($image['url']) . '">';
        }
        return $html . implode("\n", $tags) . "\n";
    }

    /**
     * Картинка для превью ссылки.
     *
     * У отдельного промта это сам кадр — им и делятся. Остальным отдаём
     * общую картинку сайта 1200×630: она лежит в плагине и до сих пор не
     * использовалась.
     */
    private static function social_image($ctx) {
        if ($ctx['type'] === 'prompt' && class_exists('GS_Prompts_Page')) {
            $item = GS_Prompts_Page::current_item();
            if ($item && !empty($item['image'])) {
                return array('url' => GS_Prompts::img_url() . '/' . ltrim((string) $item['image'], '/'), 'w' => 0, 'h' => 0);
            }
        }
        return array('url' => GS_PLUGIN_URL . 'assets/brand/public/og-image.png', 'w' => 1200, 'h' => 630);
    }

    /**
     * Первое вхождение заменяем, остальные выкидываем: и тема, и базовый плагин
     * печатают свои теги, дубли в head поисковику только мешают.
     */
    private static function replace_tag($html, $pattern, $replacement) {
        $count = 0;
        $html = preg_replace_callback($pattern, function () use (&$count, $replacement) {
            $count++;
            return $count === 1 ? $replacement : '';
        }, $html);
        return (string) $html;
    }

    /**
     * Базовый плагин печатает на страницах каталога разметку страницы озвучки:
     * Article с описанием «[kie_tts_sounds_catalog]», FAQPage с вопросами про
     * форматы и API, которых на странице нет, и две крошки, обе ведущие в индекс
     * каталога. FAQ-разметка без соответствующего контента — прямое нарушение
     * требований поисковиков, поэтому чужие блоки убираем и печатаем свои.
     */
    private static function strip_foreign_schema($html) {
        return (string) preg_replace_callback(
            '~<script[^>]*type=["\']application/ld\+json["\'][^>]*>(.*?)</script>~is',
            function ($m) {
                // Свою разметку помечаем data-gs и не трогаем: иначе вместе
                // с чужим Article улетали наши вопросы и ответы.
                if (strpos($m[0], 'data-gs=') !== false) {
                    return $m[0];
                }
                $data = json_decode(trim($m[1]), true);
                if (!is_array($data)) {
                    return $m[0];
                }
                $type = isset($data['@type']) ? $data['@type'] : '';
                $drop = array('Article', 'NewsArticle', 'BlogPosting', 'FAQPage', 'BreadcrumbList');
                return in_array($type, $drop, true) ? '' : $m[0];
            },
            $html
        );
    }

    /**
     * Свои теги: robots, ссылки пагинации и корректная микроразметка.
     */
    private static function extra_tags($ctx) {
        $out = "\n";

        // Пока сервис не подключён, странице нечего делать в индексе:
        // пользователь придёт из поиска и упрётся в заглушку.
        if ($ctx['type'] === 'lab' && !GS_Lab::is_available($ctx['service']['id'])) {
            return $out . '<meta name="robots" content="noindex, follow">' . "\n";
        }

        // Страницы поиска — служебные, в индексе им делать нечего, но
        // ссылки с них пусть передают вес. Сам тег robots ставит
        // GS_Noindex через wp_robots — одним тегом, а не вторым подряд.
        // Здесь обрываем остальное: соседние страницы и микроразметка
        // служебной выдаче не нужны.
        if (($ctx['type'] === 'index' || $ctx['type'] === 'prompt') && $ctx['query'] !== '') {
            return $out;
        }

        if ($ctx['page'] > 1) {
            $out .= '<link rel="prev" href="' . esc_url(self::page_url($ctx, $ctx['page'] - 1)) . '">' . "\n";
        }
        if ($ctx['page'] < $ctx['pages']) {
            $out .= '<link rel="next" href="' . esc_url(self::page_url($ctx, $ctx['page'] + 1)) . '">' . "\n";
        }

        if ($ctx['type'] === 'prompt') {
            $nodes = array(
                array(
                    '@context'    => 'https://schema.org',
                    '@type'       => 'WebPage',
                    'name'        => self::build_title($ctx),
                    'description' => self::build_description($ctx),
                    'url'         => (string) $ctx['url'],
                    'inLanguage'  => 'ru-RU',
                    'isPartOf'    => array('@id' => home_url('/#site')),
                    'publisher'   => array('@id' => home_url('/#org')),
                ),
                self::org_node(),
            );
            foreach ($nodes as $node) {
                $out .= '<script type="application/ld+json">'
                    . wp_json_encode($node, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                    . '</script>' . "\n";
            }
            return $out;
        }

        if ($ctx['type'] === 'docpage') {
            $nodes = array(
                array(
                    '@context'    => 'https://schema.org',
                    '@type'       => 'WebPage',
                    'name'        => (string) $ctx['doc']['title'],
                    'description' => (string) $ctx['doc']['desc'],
                    'url'         => (string) $ctx['url'],
                    'inLanguage'  => 'ru-RU',
                    'publisher'   => array('@id' => home_url('/#org')),
                    'breadcrumb'  => self::breadcrumbs(array(
                        'Главная' => home_url('/'),
                        (string) $ctx['doc']['title'] => (string) $ctx['url'],
                    )),
                ),
                self::org_node(),
            );
            foreach ($nodes as $node) {
                $out .= '<script type="application/ld+json">'
                    . wp_json_encode($node, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                    . '</script>' . "\n";
            }
            return $out;
        }

        if ($ctx['type'] === 'neurohub') {
            // Вопросы печатаем здесь, а не в самом GS_Neurohub: его разметка
            // попадала под общую чистку чужого FAQ и пропадала со страницы.
            $questions = array();
            foreach (GS_Neurohub::faq() as $pair) {
                $questions[] = array(
                    '@type'          => 'Question',
                    'name'           => $pair[0],
                    'acceptedAnswer' => array('@type' => 'Answer', 'text' => $pair[1]),
                );
            }
            $nodes = array(self::org_node());
            if ($questions) {
                $nodes[] = array(
                    '@context'   => 'https://schema.org',
                    '@type'      => 'FAQPage',
                    'mainEntity' => $questions,
                );
            }
            foreach ($nodes as $node) {
                $out .= '<script type="application/ld+json">'
                    . wp_json_encode($node, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                    . '</script>' . "\n";
            }
            return $out;
        }

        if ($ctx['type'] === 'api') {
            foreach (self::schema_api() as $schema) {
                $out .= '<script type="application/ld+json">'
                    . wp_json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                    . '</script>' . "\n";
            }
            return $out;
        }

        if ($ctx['type'] === 'lab') {
            foreach (self::schema_lab($ctx) as $schema) {
                $out .= '<script type="application/ld+json">'
                    . wp_json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                    . '</script>' . "\n";
            }
            return $out;
        }

        if ($ctx['type'] === 'home') {
            foreach (self::schema_home($ctx) as $schema) {
                $out .= '<script type="application/ld+json">'
                    . wp_json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                    . '</script>' . "\n";
            }
            return $out;
        }

        if ($ctx['type'] === 'section') {
            $schema = self::schema_section($ctx);
        } elseif ($ctx['type'] === 'category') {
            $schema = self::schema_category($ctx);
        } else {
            $schema = self::schema_index($ctx);
        }
        $out .= '<script type="application/ld+json">'
            . wp_json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            . '</script>' . "\n";

        return $out;
    }

    /* ---------------------------------------------------------------------
     * Микроразметка
     * ------------------------------------------------------------------ */

    /**
     * Кто оказывает услуги.
     *
     * По коммерческим запросам поиск сравнивает не только страницы, но и
     * того, кто за ними стоит: есть ли имя, реквизиты, способы связи.
     * Узел печатается на всех страницах, которыми распоряжаемся мы, и
     * остальные ссылаются на него по @id — одно описание на весь сайт.
     *
     * Данные берём те же, что опубликованы в подвале: расхождение между
     * разметкой и видимой страницей хуже, чем отсутствие разметки.
     */
    public static function org_details() {
        return (array) apply_filters('gs_org_details', array(
            'name'      => self::brand_name(get_bloginfo('name')),
            // В самой оферте сказано: исполнитель — самозанятый на НПД.
            // «ИП» здесь было бы выдумкой.
            'legalName' => 'Миллер Александр Александрович',
            'taxID'     => '241901583600',
            'email'     => 'akklex9@gmail.com',
            'phone'     => '+7 902 467-77-24',
            'telegram'  => 'https://t.me/Genius_Boot_bot',
        ));
    }

    public static function org_node() {
        $details = self::org_details();

        $node = array(
            '@context'   => 'https://schema.org',
            '@type'      => 'Organization',
            '@id'        => home_url('/#org'),
            'name'       => (string) $details['name'],
            'url'        => home_url('/'),
            'inLanguage' => 'ru-RU',
            'areaServed' => array('@type' => 'Country', 'name' => 'Россия'),
        );
        if (!empty($details['legalName'])) {
            $node['legalName'] = (string) $details['legalName'];
        }
        if (!empty($details['taxID'])) {
            $node['taxID'] = (string) $details['taxID'];
        }
        if (!empty($details['email'])) {
            $node['email'] = (string) $details['email'];
            $node['contactPoint'] = array(
                '@type'             => 'ContactPoint',
                'contactType'       => 'customer support',
                'email'             => (string) $details['email'],
                'availableLanguage' => 'Russian',
            );
        }
        if (!empty($details['phone'])) {
            $node['telephone'] = (string) $details['phone'];
            if (isset($node['contactPoint'])) {
                $node['contactPoint']['telephone'] = (string) $details['phone'];
            }
        }
        if (!empty($details['telegram'])) {
            $node['sameAs'] = array((string) $details['telegram']);
        }
        $logo = self::logo_url();
        if ($logo !== '') {
            $node['logo'] = array('@type' => 'ImageObject', 'url' => $logo);
        }
        return $node;
    }

    /** Картинка для разметки — берём ту же, что стоит в шапке сайта. */
    private static function logo_url() {
        $id = (int) get_theme_mod('custom_logo');
        if ($id > 0) {
            $src = wp_get_attachment_image_src($id, 'full');
            if (is_array($src) && !empty($src[0])) {
                return (string) $src[0];
            }
        }
        $icon = get_site_icon_url(512);
        return is_string($icon) ? $icon : '';
    }

    /**
     * Главная. Прежде здесь печаталась разметка каталога звуков — с его
     * названием, описанием и списком подборок, — хотя главная не каталог,
     * а вход во все сервисы сразу. Теперь это сайт, его владелец и список
     * самих сервисов.
     */
    private static function schema_home($ctx) {
        $site = array(
            '@context'   => 'https://schema.org',
            '@type'      => 'WebSite',
            '@id'        => home_url('/#site'),
            'name'       => self::brand_name(get_bloginfo('name')),
            'url'        => home_url('/'),
            'inLanguage' => 'ru-RU',
            'description' => self::build_description($ctx),
            'publisher'  => array('@id' => home_url('/#org')),
        );

        $items = array();
        $position = 1;
        foreach (GS_Lab::services() as $service) {
            if (empty($service['available']) || empty($service['id'])) {
                continue;
            }
            $items[] = array(
                '@type'    => 'ListItem',
                'position' => $position++,
                'name'     => (string) $service['menu'],
                'url'      => GS_Lab::get_url($service['id']),
            );
        }

        $out = array($site, self::org_node());
        if ($items) {
            $out[] = array(
                '@context'        => 'https://schema.org',
                '@type'           => 'ItemList',
                'name'            => 'Сервисы Genius',
                'numberOfItems'   => count($items),
                'itemListElement' => $items,
            );
        }
        return $out;
    }

    private static function breadcrumbs($items) {
        $list = array();
        $position = 1;
        foreach ($items as $name => $url) {
            $list[] = array(
                '@type'    => 'ListItem',
                'position' => $position++,
                'name'     => $name,
                'item'     => $url,
            );
        }
        return array('@type' => 'BreadcrumbList', 'itemListElement' => $list);
    }

    /**
     * Посадочная микросервиса: сам инструмент, крошки и вопросы.
     * FAQPage печатаем только потому, что те же вопросы видимы на странице —
     * разметка без соответствующего контента нарушает требования поисковиков.
     */
    private static function schema_lab($ctx) {
        $service = $ctx['service'];
        $url = !empty($ctx['url']) ? $ctx['url'] : GS_Lab::get_url($service['id']);

        $app = array(
            '@context'        => 'https://schema.org',
            '@type'           => 'WebApplication',
            'name'            => $service['h1'],
            'description'     => $service['seo_desc'],
            'url'             => $url,
            'applicationCategory' => 'MultimediaApplication',
            'operatingSystem' => 'Any',
            'inLanguage'      => 'ru-RU',
            'offers'          => array(
                '@type'         => 'Offer',
                'price'         => (string) GS_Lab::get_cost($service['id']),
                'priceCurrency' => 'RUB',
                'url'           => $url,
                'availability'  => 'https://schema.org/InStock',
                'seller'        => array('@id' => home_url('/#org')),
            ),
            'publisher'       => array('@id' => home_url('/#org')),
            'breadcrumb'      => self::breadcrumbs(array(
                'Главная'          => home_url('/'),
                $service['menu']   => $url,
            )),
        );

        // Оценка и отзывы — только если они есть на самой странице.
        if (class_exists('GS_Reviews')) {
            foreach (GS_Reviews::schema($service['id']) as $key => $value) {
                $app[$key] = $value;
            }
        }

        $questions = array();
        foreach ($service['faq'] as $pair) {
            $questions[] = array(
                '@type'          => 'Question',
                'name'           => $pair[0],
                'acceptedAnswer' => array('@type' => 'Answer', 'text' => $pair[1]),
            );
        }
        $faq = array(
            '@context'   => 'https://schema.org',
            '@type'      => 'FAQPage',
            'mainEntity' => $questions,
        );

        return array($app, $faq, self::org_node());
    }

    /**
     * Страница API: сам интерфейс как программный продукт плюс те же вопросы,
     * что видны на странице.
     */
    private static function schema_api() {
        $url = GS_Api_Page::get_url();

        $api = array(
            '@context'            => 'https://schema.org',
            '@type'               => 'WebAPI',
            'name'                => 'API Genius-bot',
            'description'         => 'HTTP API нейросетей: оживление фото, говорящий аватар, редактирование изображений, генерация звуков и озвучка текста.',
            'url'                 => $url,
            'documentation'       => $url,
            'inLanguage'          => 'ru-RU',
            'provider'            => array(
                '@type' => 'Organization',
                'name'  => self::brand_name(get_bloginfo('name')),
                'url'   => home_url('/'),
            ),
            'breadcrumb'          => self::breadcrumbs(array(
                'Главная'                => home_url('/'),
                'API для разработчиков'  => $url,
            )),
        );

        $questions = array();
        foreach (GS_Api_Page::faq() as $pair) {
            $questions[] = array(
                '@type'          => 'Question',
                'name'           => $pair[0],
                'acceptedAnswer' => array('@type' => 'Answer', 'text' => $pair[1]),
            );
        }
        $faq = array(
            '@context'   => 'https://schema.org',
            '@type'      => 'FAQPage',
            'mainEntity' => $questions,
        );

        return array($api, $faq);
    }

    private static function schema_index($ctx) {
        $stats = GS_Catalog::stats();
        $page  = $ctx['page'];
        $slice = array_slice(GS_Catalog::load_index(), ($page - 1) * GS_Catalog::CATS_PER_PAGE, GS_Catalog::CATS_PER_PAGE);

        $items = array();
        $position = 1;
        foreach ($slice as $row) {
            if (empty($row['slug'])) {
                continue;
            }
            $items[] = array(
                '@type'    => 'ListItem',
                'position' => $position++,
                'name'     => GS_Catalog::short_title((string) $row['title']),
                'url'      => GS_Catalog::category_url((string) $row['slug']),
            );
        }

        return array(
            '@context'    => 'https://schema.org',
            '@type'       => 'CollectionPage',
            'name'        => 'Каталог звуков',
            'description' => self::build_description($ctx),
            'url'         => self::page_url($ctx),
            'inLanguage'  => 'ru-RU',
            'isPartOf'    => array(
                '@type' => 'WebSite',
                'name'  => self::brand_name(get_bloginfo('name')),
                'url'   => home_url('/'),
            ),
            'breadcrumb'  => self::breadcrumbs(array(
                'Главная'        => home_url('/'),
                'Каталог звуков' => GS_Catalog::base_url(),
            )),
            'mainEntity'  => array(
                '@type'            => 'ItemList',
                'numberOfItems'    => (int) $stats['categories'],
                'itemListElement'  => $items,
            ),
        );
    }

    /**
     * Крошки подборки: главная → каталог → раздел → подборка.
     *
     * Раздел в цепочке — не украшение: по ней поиск понимает, что подборка
     * не висит сама по себе, а принадлежит теме, и переносит на неё часть
     * доверия к разделу.
     */
    private static function category_crumbs($category, $title, $slug) {
        $crumbs = array(
            'Главная'        => home_url('/'),
            'Каталог звуков' => GS_Catalog::base_url(),
        );
        $section = GS_Sections::get(GS_Catalog::section_of($category));
        if ($section) {
            $crumbs[$section['menu']] = GS_Sections::url($section['slug']);
        }
        $crumbs[$title] = GS_Catalog::category_url($slug);
        return $crumbs;
    }

    private static function schema_section($ctx) {
        $section = $ctx['section'];
        $items = array();
        $position = 1 + ($ctx['page'] - 1) * GS_Catalog::CATS_PER_PAGE;
        $slice = array_slice(
            GS_Sections::categories($section['slug']),
            ($ctx['page'] - 1) * GS_Catalog::CATS_PER_PAGE,
            GS_Catalog::CATS_PER_PAGE
        );
        foreach ($slice as $row) {
            $items[] = array(
                '@type'    => 'ListItem',
                'position' => $position++,
                'name'     => GS_Catalog::short_title((string) ($row['title'] ?? $row['slug'])),
                'url'      => GS_Catalog::category_url((string) $row['slug']),
            );
        }

        return array(
            '@context'    => 'https://schema.org',
            '@type'       => 'CollectionPage',
            'name'        => (string) $section['title'],
            'description' => self::build_description($ctx),
            'url'         => self::page_url($ctx),
            'inLanguage'  => 'ru-RU',
            'isPartOf'    => array(
                '@type' => 'WebSite',
                'name'  => self::brand_name(get_bloginfo('name')),
                'url'   => home_url('/'),
            ),
            'breadcrumb'  => self::breadcrumbs(array(
                'Главная'         => home_url('/'),
                'Каталог звуков'  => GS_Catalog::base_url(),
                $section['menu']  => GS_Sections::url($section['slug']),
            )),
            'mainEntity'  => array(
                '@type'           => 'ItemList',
                'numberOfItems'   => count($items),
                'itemListElement' => $items,
            ),
        );
    }

    private static function schema_category($ctx) {
        $category = $ctx['category'];
        $slug     = (string) $category['slug'];
        $title    = GS_Catalog::short_title((string) $category['title']);
        $sounds   = (isset($category['sounds']) && is_array($category['sounds'])) ? $category['sounds'] : array();
        // В разметку берём только начало страницы: шестьдесят AudioObject
        // весят больше, чем весь полезный текст подборки.
        $slice    = array_slice($sounds, ($ctx['page'] - 1) * GS_Catalog::SOUNDS_PER_PAGE, 30);

        $items = array();
        $position = 1 + ($ctx['page'] - 1) * GS_Catalog::SOUNDS_PER_PAGE;
        foreach ($slice as $sound) {
            if (empty($sound['file'])) {
                continue;
            }
            $audio = array(
                '@type'          => 'AudioObject',
                'name'           => (string) ($sound['title'] ?? 'Звук'),
                'contentUrl'     => GS_Catalog::file_url((string) $sound['file']),
                'encodingFormat' => 'audio/mpeg',
            );
            $duration = (int) ($sound['duration'] ?? 0);
            if ($duration > 0) {
                $audio['duration'] = 'PT' . $duration . 'S';
            }
            $items[] = array(
                '@type'    => 'ListItem',
                'position' => $position++,
                'item'     => $audio,
            );
        }

        return array(
            '@context'    => 'https://schema.org',
            '@type'       => 'CollectionPage',
            'name'        => (string) $category['title'],
            'description' => self::build_description($ctx),
            'url'         => self::page_url($ctx),
            'inLanguage'  => 'ru-RU',
            'isPartOf'    => array(
                '@type' => 'WebSite',
                'name'  => self::brand_name(get_bloginfo('name')),
                'url'   => home_url('/'),
            ),
            'breadcrumb'  => self::breadcrumbs(self::category_crumbs($category, $title, $slug)),
            'mainEntity'  => array(
                '@type'           => 'ItemList',
                'numberOfItems'   => count($sounds),
                'itemListElement' => $items,
            ),
        );
    }
}
