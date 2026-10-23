<?php
/**
 * Мусор из поискового индекса.
 *
 * Вебмастер показал 23 адреса, которые попали в поиск по недоразумению:
 * архивы автора (они к тому же отвечали 500 и светили логины пользователей),
 * пустая рубрика «Без рубрики», остатки магазина и варианты страниц с
 * параметрами вида `/sound-generator/?prompt=Звуки карт`. Содержания в них
 * нет, но поиск считает их страницами сайта и по ним же судит о качестве.
 *
 * Канонический адрес у таких страниц уже стоял — этого не хватило: Яндекс
 * канониклы учитывает как пожелание. Поэтому закрываем прямо: архив автора
 * уводим редиректом, остальному ставим noindex, а роботу Яндекса отдельно
 * объявляем Clean-param — по нему он склеивает адреса с параметрами сам.
 */

if (!defined('ABSPATH')) {
    exit;
}

class GS_Noindex {

    /**
     * Параметры, которые не меняют содержимое страницы.
     *
     * Это не весь мусор, какой бывает в ссылках, а только тот, что реально
     * встретился в индексе и в наших же кнопках.
     */
    const JUNK_PARAMS = array(
        'prompt', 'add-to-cart', 'gs_page', 'x', 'ref', 'from',
        'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content',
        // Фраза из объявления: та же страница, только подбор сверху.
        'kw', 'mkw', 'term', 'keyword',
        'yclid', 'gclid', 'ymclid', 'etext', 'fbclid',
    );

    public static function boot() {
        add_action('template_redirect', array(__CLASS__, 'retire_author'), 1);
        add_action('template_redirect', array(__CLASS__, 'retire_gone'), 1);
        add_action('template_redirect', array(__CLASS__, 'retire_twins'), 1);
        add_action('template_redirect', array(__CLASS__, 'canon_case'), 1);
        // Через wp_robots, а не своим тегом в wp_head: иначе на странице
        // окажется два <meta name="robots"> подряд, и какой из них считать —
        // решает робот.
        add_filter('wp_robots', array(__CLASS__, 'robots_meta'));
        add_filter('robots_txt', array(__CLASS__, 'robots'), 30, 2);
    }

    /**
     * Архив автора уводим на блог.
     *
     * Своего смысла у него нет: все статьи и так лежат в блоге. Зато в
     * адресе стоял логин пользователя, а сама страница отвечала ошибкой
     * сервера — и в таком виде попала в поиск.
     */
    /**
     * Адреса, которые остались в поиске, а страниц под ними больше нет.
     *
     * Проверка всех 1600 адресов из индекса нашла восемь таких: по ним
     * Яндекс показывает сайт в выдаче, человек приходит и видит «страница
     * не найдена». Это и потерянный переход, и сигнал поисковику, что сайт
     * разваливается.
     *
     * Ведём каждый адрес на ближайшую по смыслу живую страницу. Остатки
     * магазина сюда не включены намеренно: страниц под них нет и не будет,
     * и честный 404 правильнее перевода на главную.
     */
    const GONE = array(
        '/bot-pay/'                 => '/paybot/',
        '/izmenit-foto-neyrosetyu/' => '/neurohub/',
        '/kartinka-neyrosetyu/'     => '/neurohub/',
        '/video-neyrosetyu/'        => '/video-iz-teksta-neiroset/',
        '/web-app/'                 => '/neurohub/',
        // Кнопка «Заказать консультацию» в шапке ведёт на этот адрес —
        // товар магазина, которого больше нет. Раньше мы уводили её на
        // /solutions/, но там лежит статья про профессию разработчика: ни
        // формы, ни цены, ни способа написать. Теперь — на страницу заявки.
        '/product/konsultacziya-po-razrabotke-chat-bota/' => '/zakazat-konsultaciyu/',
    );

    /**
     * Живые страницы-двойники: уводим на ту, где лежит содержимое.
     *
     * Это не 404, поэтому в GONE им не место: страница открывается и
     * отвечает двести, просто она — обрезанная копия другой. Вебмастер
     * показывал обе в одной группе дублей: «Google Veo 3 — гид» лежит и
     * на /google-veo3/ (шесть тысяч знаков), и на /2810-2/ (тысяча), а
     * статья про Stable Diffusion — на двух слагах, причём во втором от
     * текста остался один врезанный блок про звук из видео.
     */
    const TWINS = array(
        '/2810-2/' => '/google-veo3/',
        /*
         * Виджет консультанта от удалённого плагина: страница отвечает
         * «Консультант недоступен» в 190 байтах, минуя заголовок сайта, —
         * поэтому и запрет индексации до неё не доходил. Ссылок на неё
         * нет ни с одной страницы, а запрос у человека живой: уводим туда,
         * где консультацию действительно заказывают.
         */
        '/consultant/' => '/zakazat-konsultaciyu/',
        /*
         * Статья, у которой в адресе стоял эмодзи: перекодированный
         * «🤖» уводил запрос на самого себя, и браузер упирался в
         * «слишком много переадресаций» — страница была недоступна ни
         * человеку, ни роботу. Теперь она живёт по чистому адресу, а
         * старый ведёт на неё.
         */
        '/%f0%9f%a4%96-umnyj-chat-bot-dlya-biznesa-kak-tehnologii-pomogayut-zarabatyvat-bolshe/'
            => '/umnyj-chat-bot-dlya-biznesa/',
        '/stablediffusion-v-rossii-kak-ispolzovat-cherez-vpn-niderlandy/'
            => '/stable-diffusion-v-rossii-kak-ispolzovat-cherez-vpn-niderlandy/',
    );

    /**
     * Черновики и обрывки, которые остались в индексе от прошлых опытов.
     *
     * Содержания в них нет: /test/ отдаёт «Привет из Web App!», /test-2/ и
     * /2738-2/ — одну и ту же заготовку лендинга про VPN, /test-chata/ и
     * /3dai/ показывают текст шорткода от удалённых плагинов, /2150-2/ —
     * форму чата без страницы. Заголовок окна у половины из них — одно
     * слово «Genius-bot» или «test», и в поиске они дубли друг друга.
     *
     * Уводить их некуда, замены им нет — поэтому просто закрываем от
     * индекса, ссылки с них пусть передают вес.
     */
    const JUNK_PATHS = array(
        '/test/',
        '/test-2/',
        '/test-chata/',
        '/test-formy/',
        '/2150-2/',
        '/2738-2/',
        '/3dai/',
        '/roomplan-app/',
        // Перевалочные страницы бота и личные кабинеты: содержания в них
        // нет вовсе — «Ссылка неполная», «Спасибо за оплату!», форма входа.
        // Человек попадает сюда из бота по кнопке, из поиска — никогда.
        '/perehod/',
        '/privyazka-telegram/',
        '/oplata-dostupa/',
        '/moya-baza/',
        '/consultant/',
        '/tts-dashboard/',
    );

    /** Текущий адрес без параметров, со слэшами по краям. */
    private static function path() {
        $path = (string) wp_parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
        return '/' . trim($path, '/') . '/';
    }

    /** Двойника уводим на страницу с содержимым. */
    public static function retire_twins() {
        /*
         * На 404 проверку раньше не делали — и зря: именно туда попадает
         * адрес, которого больше нет, а ради таких переадресация и
         * заведена. Админку по-прежнему не трогаем.
         */
        if (is_admin()) {
            return;
        }
        $path = self::path();
        // Адреса с процентными кодами приходят и строчными, и прописными:
        // сверяем в одном регистре, иначе редирект срабатывает через раз.
        $lower = strtolower($path);
        if (isset(self::TWINS[$path]) || isset(self::TWINS[$lower])) {
            $to = self::TWINS[$path] ?? self::TWINS[$lower];
            wp_safe_redirect(home_url($to), 301);
            exit;
        }
    }

    /**
     * Адрес, отличающийся от настоящего только регистром, уводим на него.
     *
     * /Rasshifrovka-Audio/ открывался как обычная страница и отвечал 200:
     * canonical указывал на строчный вариант, но лишний адрес существовал,
     * и по ссылке с чужого сайта в индекс попадала его копия.
     *
     * Сверяем с постоянной ссылкой самой записи и уводим только тогда,
     * когда различие именно в регистре: у страниц каталога в адресе есть
     * свои хвосты, и подменять их нельзя.
     */
    public static function canon_case() {
        if (is_admin() || !is_singular()) {
            return;
        }
        $id = (int) get_queried_object_id();
        if ($id <= 0) {
            return;
        }
        $want = (string) wp_parse_url((string) get_permalink($id), PHP_URL_PATH);
        $have = (string) wp_parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
        if ($want === '' || $have === '' || $want === $have) {
            return;
        }
        if (strtolower(rawurldecode($have)) !== strtolower(rawurldecode($want))) {
            return;
        }
        $to = home_url($want);
        $query = (string) wp_parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_QUERY);
        wp_safe_redirect($query !== '' ? $to . '?' . $query : $to, 301);
        exit;
    }

    public static function retire_gone() {
        if (is_admin() || !is_404()) {
            return;
        }
        $path = (string) wp_parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
        $path = '/' . trim($path, '/') . '/';
        if (isset(self::GONE[$path])) {
            wp_safe_redirect(home_url(self::GONE[$path]), 301);
            exit;
        }
    }

    public static function retire_author() {
        if (!is_author()) {
            return;
        }
        wp_safe_redirect(self::blog_url(), 301);
        exit;
    }

    public static function robots_meta($robots) {
        if (!self::junk()) {
            return $robots;
        }
        $robots['noindex'] = true;
        $robots['follow'] = true;
        unset($robots['index']);
        return $robots;
    }

    /** Стоит ли закрывать текущий адрес от поиска. */
    private static function junk() {
        if (is_admin() || is_feed() || is_404()) {
            return false;
        }

        // Страница с параметром, который не меняет содержимого: это копия
        // канонического адреса, и в поиске ей делать нечего.
        foreach (self::JUNK_PARAMS as $param) {
            if (isset($_GET[$param])) { // phpcs:ignore WordPress.Security.NonceVerification
                return true;
            }
        }

        // Выдача поиска — служебная страница, а не содержимое сайта.
        // По каталогу звуков запрос приходит параметром gs_q, по каталогу
        // промтов — q. Закрываем здесь, а не своим тегом в head: иначе на
        // странице оказывались два <meta name="robots"> подряд.
        if (!empty($_GET['gs_q'])) { // phpcs:ignore WordPress.Security.NonceVerification
            return true;
        }
        if (!empty($_GET['q']) && class_exists('GS_Prompts_Page') // phpcs:ignore WordPress.Security.NonceVerification
            && GS_Prompts_Page::is_page()) {
            return true;
        }

        // Черновик или обрывок прошлого опыта — по списку адресов.
        if (in_array(self::path(), self::JUNK_PATHS, true)) {
            return true;
        }

        if (is_author()) {
            return true;
        }

        // Рубрика по умолчанию — та самая «Без рубрики»: в неё падает всё,
        // у чего рубрику не проставили, и списком она ничего не добавляет.
        if (is_category()) {
            $term = get_queried_object();
            $default = (int) get_option('default_category');
            if ($term instanceof WP_Term && ($term->term_id === $default || (int) $term->count === 0)) {
                return true;
            }
        }

        // Пустой архив — страница без единой записи.
        if ((is_archive() || is_search()) && !have_posts()) {
            return true;
        }

        return false;
    }

    /**
     * Указания роботам в robots.txt.
     *
     * Clean-param понимает только Яндекс, и это как раз его случай: адреса
     * с параметрами он склеивает с каноническим сам, не дожидаясь обхода
     * каждого варианта.
     */
    public static function robots($output, $public) {
        if (!$public) {
            return $output;
        }

        $lines = preg_split('/\R/', rtrim($output));
        $maps = array();
        $kept = array();
        foreach ($lines as $line) {
            if (preg_match('/^\s*Sitemap:/i', $line)) {
                $maps[] = $line;
            } else {
                $kept[] = $line;
            }
        }

        $own = array(
            'Disallow: /author/',
            'Disallow: /*?add-to-cart=',
            'Clean-param: ' . implode('&', self::JUNK_PARAMS),
        );
        foreach ($own as $rule) {
            if (!in_array($rule, $kept, true)) {
                $kept[] = $rule;
            }
        }

        while ($kept && trim(end($kept)) === '') {
            array_pop($kept);
        }

        return implode("\n", array_merge($kept, array(''), $maps)) . "\n";
    }

    private static function blog_url() {
        $page = get_page_by_path('blog');
        if ($page instanceof WP_Post) {
            return get_permalink($page);
        }
        return home_url('/');
    }
}
