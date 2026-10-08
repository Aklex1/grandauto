<?php
/**
 * Посадочная вокруг примерки дисков.
 *
 * Инструмент на /showwheel/ работает и берёт деньги, а страницы вокруг него
 * не было вовсе: в выдаче она показывалась с общим описанием сайта про
 * чат-ботов, без канонического адреса и без единой строки текста, который
 * поисковику есть за что зацепить. То есть платящий сервис был невидим.
 *
 * Сам инструмент рисует чужой плагин — его не трогаем. Заголовок, описание
 * и канонический адрес подменяем в готовой разметке головы, а текст
 * дописываем в подвал страницы: там он оказывается ровно под инструментом,
 * перед общими ссылками сайта.
 *
 * В меню страницу не выносим — так решено: она набирает вес ссылкой из
 * подвала, которая стоит на каждой странице сайта, и картой сайта.
 */

if (!defined('ABSPATH')) {
    exit;
}

class GS_Wheel {

    /** Адрес инструмента. Задан чужим плагином, у нас только ссылка на него. */
    const SLUG = 'showwheel';

    /**
     * Своя посадочная.
     *
     * Инструмент открывается сразу формой загрузки: человек с поиска видит
     * два поля и кнопку и не понимает, что получит и за сколько. Поэтому
     * продающая страница живёт отдельно — с примерами «до и после», ценами
     * и перечнем того, что можно задать, — а кнопка уводит в инструмент.
     */
    const LANDING_SLUG = 'primerka-diskov';
    const OPT_PAGE     = 'gs_wheel_landing_page';

    /** Цены — те же, что показывает сам инструмент. */
    const PRICE      = 65;
    const PRICE_PAINT = 25;
    const PRICE_SIZE  = 35;
    const PRICE_PLATE = 40;

    private static $buffering = false;

    public static function boot() {
        // Буферизуем страницу целиком, а не только голову: заголовок и
        // канонический адрес печатает сам плагин примерки, мимо wp_head, —
        // подмена внутри wp_head до них просто не дотягивалась.
        add_action('template_redirect', array(__CLASS__, 'page_start'), 0);
        add_action('shutdown', array(__CLASS__, 'page_flush'), 0);
        // Раньше остальных блоков подвала: текст посадочной должен идти
        // сразу за инструментом, а не после ссылок на каталог звуков.
        add_action('wp_footer', array(__CLASS__, 'render'), 1);
        add_filter('document_title_parts', array(__CLASS__, 'title_parts'), PHP_INT_MAX);
        add_shortcode('genius_wheel_landing', array(__CLASS__, 'render_landing'));
        // Голову посадочной перехватываем отдельным буфером: страница
        // обычная, всю её перебирать незачем.
        add_action('wp_head', array(__CLASS__, 'head_start'), 0);
        add_action('wp_head', array(__CLASS__, 'head_flush'), PHP_INT_MAX);
    }

    public static function landing_url() {
        $page_id = (int) get_option(self::OPT_PAGE);
        $link = $page_id > 0 ? get_permalink($page_id) : '';
        return $link ? $link : home_url('/' . self::LANDING_SLUG . '/');
    }

    public static function ensure_page() {
        $page_id = (int) get_option(self::OPT_PAGE);
        if ($page_id > 0 && get_post($page_id)) {
            return;
        }
        $existing = get_page_by_path(self::LANDING_SLUG);
        $args = array(
            'post_title'   => self::landing_title(),
            'post_content' => '[genius_wheel_landing]',
            'post_excerpt' => self::landing_description(),
            'post_status'  => 'publish',
        );
        if ($existing) {
            $page_id = (int) $existing->ID;
            // По этому адресу могла уже лежать чужая страница. Её текст
            // прячем в мету, а не стираем: если окажется, что там было
            // что-то нужное, вернуть можно без резервной копии базы.
            $was = (string) $existing->post_content;
            if (strpos($was, '[genius_wheel_landing]') === false) {
                if ($was !== '' && get_post_meta($page_id, '_gs_wheel_prev_content', true) === '') {
                    update_post_meta($page_id, '_gs_wheel_prev_content', $was);
                }
                wp_update_post(array_merge(array('ID' => $page_id), $args));
            }
        } else {
            $page_id = (int) wp_insert_post(array_merge($args, array(
                'post_type' => 'page',
                'post_name' => self::LANDING_SLUG,
            )));
        }
        if ($page_id > 0) {
            update_option(self::OPT_PAGE, $page_id);
        }
    }

    /**
     * Примеры «до и после».
     *
     * Снимки сделаны этим же инструментом и лежат в ассетах плагина: чужие
     * машины без спроса показывать нельзя, поэтому и кузова сгенерированы.
     */
    public static function examples() {
        $base = GS_PLUGIN_URL . 'assets/brand/wheel/';
        return array(
            array('Седан', 'Многоспицевые 18 дюймов', $base . 'sedan-before.jpg', $base . 'sedan-after.jpg'),
            array('Кроссовер', 'Чёрные матовые 20 дюймов', $base . 'suv-before.jpg', $base . 'suv-after.jpg'),
            array('Хэтчбек', 'Двухцветные 17 дюймов', $base . 'hatch-before.jpg', $base . 'hatch-after.jpg'),
            array('Купе', 'Полированные литые 19 дюймов', $base . 'coupe-before.jpg', $base . 'coupe-after.jpg'),
        );
    }

    public static function url() {
        return home_url('/' . self::SLUG . '/');
    }

    /** Открыт ли сейчас инструмент примерки. */
    public static function is_page() {
        if (is_admin()) {
            return false;
        }
        $uri = isset($_SERVER['REQUEST_URI']) ? (string) wp_unslash($_SERVER['REQUEST_URI']) : '';
        $path = trim((string) wp_parse_url($uri, PHP_URL_PATH), '/');
        if ($path === '') {
            return false;
        }
        $parts = explode('/', $path);
        return strtolower($parts[0]) === self::SLUG;
    }

    /* ---------------------------------------------------------------------
     * Голова страницы
     * ------------------------------------------------------------------ */

    public static function title() {
        return 'Примерка дисков онлайн: как диски будут смотреться на вашей машине';
    }

    public static function description() {
        return 'Примерка дисков онлайн по фото: загрузите снимок своего автомобиля и '
            . 'понравившийся диск — нейросеть покажет, как он будет смотреться. '
            . 'Первая примерка бесплатно, дальше ' . self::PRICE . ' ₽ за примерку.';
    }

    public static function title_parts($parts) {
        if (self::is_landing()) {
            $parts['title'] = self::landing_title();
            return $parts;
        }
        if (!self::is_page()) {
            return $parts;
        }
        $parts['title'] = self::title();
        return $parts;
    }

    public static function page_start() {
        if (!self::is_page()) {
            return;
        }
        self::$buffering = true;
        ob_start();
    }

    /**
     * Чужие теги в голове переписываем на свои.
     *
     * Описание страницы бралось общее по сайту — «разработка чат-бота под
     * ключ». Для страницы, на которую приходят по запросу «примерить диски
     * на авто», это худшее из возможных описаний в выдаче.
     */
    public static function page_flush() {
        if (!self::$buffering) {
            return;
        }
        self::$buffering = false;
        $html = ob_get_clean();
        if ($html === false || $html === '') {
            return;
        }

        $url = self::url();
        $desc = self::description();
        $title = self::title() . ' — ' . get_bloginfo('name');

        $html = self::replace($html, '~<title>.*?</title>~is',
            '<title>' . esc_html($title) . '</title>');
        $html = self::replace($html, '~<meta[^>]+name=["\']description["\'][^>]*>~i',
            '<meta name="description" content="' . esc_attr($desc) . '">');
        $html = self::replace($html, '~<meta[^>]+property=["\']og:description["\'][^>]*>~i',
            '<meta property="og:description" content="' . esc_attr($desc) . '">');
        $html = self::replace($html, '~<meta[^>]+property=["\']og:title["\'][^>]*>~i',
            '<meta property="og:title" content="' . esc_attr(self::title()) . '">');
        $html = self::replace($html, '~<meta[^>]+property=["\']og:url["\'][^>]*>~i',
            '<meta property="og:url" content="' . esc_url($url) . '">');
        $html = self::replace($html, '~<link[^>]+rel=["\']canonical["\'][^>]*>~i',
            '<link rel="canonical" href="' . esc_url($url) . '">');

        // Разметку дописываем перед закрытием головы, а не в конец страницы:
        // иначе она окажется вне <head> и часть разборщиков её не увидит.
        $head = self::schema();
        if (self::is_embedded()) {
            // В рамке разметка услуги не нужна и вредна: тот же объект уже
            // описан посадочной, а поисковику этот адрес мы не отдаём.
            $head = self::embed_styles()
                . '<meta name="robots" content="noindex,nofollow">' . "\n";
        }
        $html = preg_replace('~</head>~i', $head . '</head>', $html, 1);

        echo $html; // phpcs:ignore WordPress.Security.EscapeOutput
    }

    /** Первое вхождение заменяем, остальные убираем: дубли в голове вредны. */
    private static function replace($html, $pattern, $replacement) {
        $count = 0;
        $html = preg_replace_callback($pattern, function () use (&$count, $replacement) {
            $count++;
            return $count === 1 ? $replacement : '';
        }, $html);
        // Тега могло не быть вовсе — тогда добавляем свой. Канонического
        // адреса на этой странице как раз не было ни одного.
        if ($count === 0) {
            $html = preg_replace('~</head>~i', $replacement . "\n</head>", $html, 1);
        }
        return $html;
    }

    /**
     * Разметка страницы инструмента: услуга с ценой.
     *
     * Вопросы отсюда убраны намеренно — FAQPage отдаёт посадочная, и две
     * страницы с одинаковым списком вопросов конкурировали бы между собой.
     */
    private static function schema() {
        $data = array(
            '@context' => 'https://schema.org',
            '@graph'   => array(
                array(
                    '@type'       => 'Service',
                    'name'        => 'Примерка дисков онлайн',
                    'description' => self::description(),
                    'url'         => self::url(),
                    'areaServed'  => 'RU',
                    'offers'      => array(
                        '@type'         => 'Offer',
                        'price'         => (string) self::PRICE,
                        'priceCurrency' => 'RUB',
                    ),
                ),
            ),
        );
        return "\n" . '<script type="application/ld+json">'
            . wp_json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            . '</script>' . "\n";
    }

    /* ---------------------------------------------------------------------
     * Текст посадочной
     * ------------------------------------------------------------------ */

    private static function faq() {
        return array(
            array('Какие фотографии нужны?',
                  'Две: автомобиль и диск. Машину лучше снять сбоку или в три четверти, '
                  . 'целиком и при дневном свете — тогда видно колёсные арки и посадку. '
                  . 'Диск подойдёт любой: фотография с сайта магазина, из объявления или '
                  . 'снятая в шинном центре.'),
            array('Это точная примерка или картинка?',
                  'Это визуализация: нейросеть показывает, как диск смотрится на вашей '
                  . 'машине. Она не проверяет совместимость по разболтовке, вылету и '
                  . 'диаметру ступицы — эти параметры сверяйте по каталогу производителя '
                  . 'или у продавца. Диаметр можно задать отдельной опцией, чтобы колесо '
                  . 'в кадре не оказалось больше или меньше нужного.'),
            array('Сколько стоит?',
                  'Первая примерка бесплатно. Дальше ' . self::PRICE . ' ₽ за примерку. '
                  . 'Дополнительные опции считаются сверху: перекрасить диски — '
                  . self::PRICE_PAINT . ' ₽, задать диаметр — ' . self::PRICE_SIZE . ' ₽, '
                  . 'оставить государственный номер в кадре — ' . self::PRICE_PLATE . ' ₽.'),
            array('Зачем платить за то, чтобы не скрывать номер?',
                  'По умолчанию номер на снимке закрывается — так безопаснее выкладывать '
                  . 'результат в объявление или в соцсети. Если снимок нужен для себя и '
                  . 'номер мешать не будет, эту защиту можно отключить.'),
            array('Можно ли подобрать цвет дисков?',
                  'Да, включите «перекрасить диски» и укажите цвет. Это удобно, когда '
                  . 'модель нравится, а в наличии другой цвет — или наоборот, когда диск '
                  . 'собираются красить и хочется заранее посмотреть.'),
            array('Где потом искать результат?',
                  'Все готовые примерки лежат в разделе «История» на этой же странице, '
                  . 'пока вы не вышли из аккаунта. Понравившийся результат можно добавить '
                  . 'в общую галерею.'),
        );
    }

    public static function render() {
        if (!self::is_page() || self::is_embedded()) {
            return;
        }
        ?>
        <section class="gs-wheel">
            <div class="gs-wheel__wrap">
                <h2>Зачем примерять диски по фотографии</h2>
                <p>
                    Диски выбирают глазами, а покупают вслепую. На витрине магазина и на
                    карточке в объявлении колесо выглядит само по себе — а как оно сядет
                    именно на вашу машину, с её цветом кузова, посадкой и арками, видно
                    только после установки. Отсюда и главная беда: комплект куплен,
                    установлен, а «не то».
                </p>
                <p>
                    Примерка по фотографии решает ровно эту задачу и ничего больше:
                    вы загружаете снимок своего автомобиля и снимок понравившегося диска
                    и смотрите на результат до покупки. Первая примерка бесплатна —
                    чтобы понять, стоит ли оно того, платить не нужно.
                </p>

                <h2>Как получить понятный результат</h2>
                <ul>
                    <li><strong>Машину — целиком и сбоку.</strong> Снимок в три четверти
                        или строго сбоку показывает и арку, и профиль колеса. Кадр «в упор»
                        на одно колесо годится хуже: не с чем сравнивать размер.</li>
                    <li><strong>Днём, без резких теней.</strong> В сумерках и под фонарями
                        цвет кузова и диска врут оба, и результат получается о чужой машине.</li>
                    <li><strong>Диск — фронтально.</strong> Подойдёт фотография из каталога
                        магазина или из объявления: чем меньше диск в кадре повёрнут, тем
                        точнее нейросеть повторит его рисунок.</li>
                    <li><strong>Диаметр — отдельной опцией.</strong> Если важно увидеть
                        именно 18 дюймов, а не «примерно такие», задайте его: без этого
                        колесо в кадре может выйти крупнее или мельче задуманного.</li>
                </ul>

                <h2>Сколько это стоит</h2>
                <div class="gs-wheel__tablewrap">
                    <table class="gs-wheel__table">
                        <thead><tr><th>Что</th><th>Цена</th><th>Когда нужно</th></tr></thead>
                        <tbody>
                            <tr>
                                <td>Первая примерка</td>
                                <td>бесплатно</td>
                                <td>Попробовать без регистрации кошелька</td>
                            </tr>
                            <tr>
                                <td>Примерка</td>
                                <td><?php echo (int) self::PRICE; ?> ₽</td>
                                <td>Каждый следующий запуск</td>
                            </tr>
                            <tr>
                                <td>Перекрасить диски</td>
                                <td>+<?php echo (int) self::PRICE_PAINT; ?> ₽</td>
                                <td>Модель нравится, нужен другой цвет</td>
                            </tr>
                            <tr>
                                <td>Задать диаметр</td>
                                <td>+<?php echo (int) self::PRICE_SIZE; ?> ₽</td>
                                <td>Сравнить 17, 18 и 19 дюймов между собой</td>
                            </tr>
                            <tr>
                                <td>Оставить номер в кадре</td>
                                <td>+<?php echo (int) self::PRICE_PLATE; ?> ₽</td>
                                <td>Снимок для себя, а не для публикации</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <h2>Кому это пригодится</h2>
                <div class="gs-wheel__grid">
                    <article class="gs-wheel__card">
                        <h3>Владельцу машины</h3>
                        <p>Выбрать комплект на сезон и не гадать, не будет ли он выглядеть
                            слишком крупным или слишком скромным.</p>
                    </article>
                    <article class="gs-wheel__card">
                        <h3>Магазину дисков</h3>
                        <p>Ответить покупателю картинкой вместо слов «вам подойдёт»:
                            сомнение снимается за минуту, а не за возврат.</p>
                    </article>
                    <article class="gs-wheel__card">
                        <h3>Шинному центру</h3>
                        <p>Показать клиенту два-три варианта прямо за стойкой и продать
                            тот, который он увидел на своей машине.</p>
                    </article>
                    <article class="gs-wheel__card">
                        <h3>Продавцу автомобиля</h3>
                        <p>Посмотреть, насколько другой комплект меняет вид машины в
                            объявлении — и стоит ли он возни перед продажей.</p>
                    </article>
                </div>

                <h2>Чего примерка не делает</h2>
                <p>
                    Это визуализация, а не подбор по каталогу. Нейросеть не проверяет
                    разболтовку, вылет и диаметр ступицы и не отвечает на вопрос, встанет
                    ли диск физически. Совместимость сверяйте по данным производителя
                    автомобиля или у продавца дисков — а примерка отвечает на другой
                    вопрос: как это будет выглядеть.
                </p>

                <p class="gs-wheel__more">
                    Примеры «до и после», полный список настроек и ответы на частые
                    вопросы — на странице
                    <a href="<?php echo esc_url(self::landing_url()); ?>">«Примерка дисков онлайн»</a>.
                </p>
            </div>
        </section>
        <?php
    }

    /* ---------------------------------------------------------------------
     * Посадочная
     * ------------------------------------------------------------------ */

    /** Открыта ли своя посадочная. */
    public static function is_landing() {
        $page_id = (int) get_option(self::OPT_PAGE);
        if ($page_id > 0 && is_page($page_id)) {
            return true;
        }
        return is_page(self::LANDING_SLUG);
    }

    /**
     * Что можно задать перед примеркой.
     *
     * Инструмент показывает эти переключатели только после загрузки обеих
     * фотографий — то есть человек узнаёт о возможностях и доплатах уже
     * внутри. На посадочной выкладываем их сразу: это и есть ответ на
     * вопрос «а что я вообще получу за свои деньги».
     */
    private static function options() {
        return array(
            array(
                'Свой диск по фотографии',
                'В цене',
                'Снимок диска из каталога магазина, из объявления или сделанный '
                . 'в шинном центре. Нейросеть повторит рисунок спиц на вашей машине.',
                true,
            ),
            array(
                'Цвет дисков',
                '+' . self::PRICE_PAINT . ' ₽',
                'Модель нравится, а цвет нужен другой: чёрный матовый, графит, '
                . 'бронза, полированный металл. Удобно и перед покраской своих.',
                false,
            ),
            array(
                'Диаметр колеса',
                '+' . self::PRICE_SIZE . ' ₽',
                'От 15 до 22 дюймов. Без этого колесо в кадре выходит «примерно '
                . 'такое»; с диаметром можно честно сравнить 17, 18 и 19 между собой.',
                false,
            ),
            array(
                'Номер в кадре',
                '+' . self::PRICE_PLATE . ' ₽',
                'По умолчанию государственный номер закрывается — так снимок не '
                . 'страшно выложить в объявление. Если результат нужен для себя, '
                . 'защиту можно снять.',
                false,
            ),
        );
    }

    /**
     * Посадочная целиком.
     *
     * Инструмент встречает человека двумя полями загрузки и кнопкой: что он
     * получит, за сколько и что вообще можно настроить — становится понятно
     * только после оплаты. Поэтому страница устроена наоборот: сначала
     * примеры «до и после», сразу цена и список настроек, и только потом
     * кнопка в инструмент.
     */
    public static function render_landing() {
        $tool = self::url();
        ob_start();
        ?>
        <div class="gs-wl">

            <section class="gs-wl__hero">
                <div class="gs-wl__wrap gs-wl__hero-in">
                    <p class="gs-wl__badge">Примерка по фотографии · результат за минуту</p>
                    <h1 class="gs-wl__h1">Посмотрите, как диски сядут на вашу машину — до покупки</h1>
                    <p class="gs-wl__lead">
                        Загрузите снимок своего автомобиля и фотографию понравившегося
                        диска. Нейросеть поставит этот диск на вашу машину — с её цветом
                        кузова, посадкой и арками. Без замеров, выезда и установки.
                    </p>
                    <div class="gs-wl__pills">
                        <span class="gs-wl__pill gs-wl__pill--free">Первая примерка бесплатно</span>
                        <span class="gs-wl__pill">дальше <b><?php echo (int) self::PRICE; ?> ₽</b> за примерку</span>
                        <span class="gs-wl__pill">цвет, диаметр и номер — опциями</span>
                    </div>
                    <p class="gs-wl__cta-row">
                        <a class="gs-wl__btn" href="#gs-wl-tool">Перейти к примерке</a>
                        <a class="gs-wl__btn gs-wl__btn--ghost" href="#gs-wl-examples">Сначала посмотреть примеры</a>
                    </p>
                    <ul class="gs-wl__facts">
                        <li>Нужны две фотографии — машина и диск</li>
                        <li>Готово примерно за минуту</li>
                        <li>Все примерки остаются в истории</li>
                    </ul>
                </div>
            </section>

            <section class="gs-wl__sec" id="gs-wl-examples">
                <div class="gs-wl__wrap">
                    <h2 class="gs-wl__h2">До и после: четыре примерки</h2>
                    <p class="gs-wl__sub">
                        Потяните ползунок — слева исходная фотография, справа тот же
                        кадр после примерки. Кузов, фон, свет и ракурс не меняются:
                        меняются только колёса.
                    </p>
                    <div class="gs-wl__cases">
                        <?php foreach (self::examples() as $i => $ex): ?>
                            <?php
                            list($body, $wheels, $before, $after) = $ex;
                            $uid = 'gs-wl-cmp-' . (int) $i;
                            ?>
                            <figure class="gs-wl__case">
                                <div class="gs-wl__cmp" data-gs-compare>
                                    <img class="gs-wl__img" src="<?php echo esc_url($after); ?>"
                                         alt="<?php echo esc_attr($body . ': ' . $wheels . ' после примерки'); ?>"
                                         loading="lazy" decoding="async" width="1100" height="629">
                                    <div class="gs-wl__cmp-before" data-gs-compare-before>
                                        <img class="gs-wl__img" src="<?php echo esc_url($before); ?>"
                                             alt="<?php echo esc_attr($body . ': штатные колёса до примерки'); ?>"
                                             loading="lazy" decoding="async" width="1100" height="629">
                                    </div>
                                    <span class="gs-wl__tag gs-wl__tag--l">До</span>
                                    <span class="gs-wl__tag gs-wl__tag--r">После</span>
                                    <span class="gs-wl__handle" data-gs-compare-handle aria-hidden="true"></span>
                                    <label class="gs-wl__sr" for="<?php echo esc_attr($uid); ?>">
                                        Сравнение «до и после»: <?php echo esc_html($body); ?>
                                    </label>
                                    <input class="gs-wl__range" id="<?php echo esc_attr($uid); ?>"
                                           type="range" min="0" max="100" value="50" step="1"
                                           data-gs-compare-input>
                                </div>
                                <figcaption class="gs-wl__cap">
                                    <b><?php echo esc_html($body); ?></b>
                                    <span><?php echo esc_html($wheels); ?></span>
                                </figcaption>
                            </figure>
                        <?php endforeach; ?>
                    </div>
                    <p class="gs-wl__note">
                        Автомобили на примерах сгенерированы: чужие машины и номера
                        показывать без спроса нельзя. Сама примерка сделана тем же
                        инструментом, которым воспользуетесь вы.
                    </p>
                    <p class="gs-wl__cta-row">
                        <a class="gs-wl__btn" href="#gs-wl-tool">Примерить на свою машину</a>
                    </p>
                </div>
            </section>

            <section class="gs-wl__sec gs-wl__sec--alt">
                <div class="gs-wl__wrap">
                    <h2 class="gs-wl__h2">Что можно задать</h2>
                    <p class="gs-wl__sub">
                        Доплаты считаются только за то, что включили. Ничего не трогали —
                        платите <?php echo (int) self::PRICE; ?> ₽ за примерку.
                    </p>
                    <div class="gs-wl__opts">
                        <?php foreach (self::options() as $opt): ?>
                            <article class="gs-wl__opt<?php echo $opt[3] ? ' gs-wl__opt--base' : ''; ?>">
                                <p class="gs-wl__opt-top">
                                    <span class="gs-wl__opt-name"><?php echo esc_html($opt[0]); ?></span>
                                    <span class="gs-wl__opt-price"><?php echo esc_html($opt[1]); ?></span>
                                </p>
                                <p class="gs-wl__opt-text"><?php echo esc_html($opt[2]); ?></p>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </div>
            </section>

            <section class="gs-wl__sec">
                <div class="gs-wl__wrap">
                    <h2 class="gs-wl__h2">Как это работает</h2>
                    <ol class="gs-wl__steps">
                        <li>
                            <b>Фотография машины</b>
                            Сбоку или в три четверти, целиком и при дневном свете —
                            тогда видно арки и посадку колеса.
                        </li>
                        <li>
                            <b>Фотография диска</b>
                            Подойдёт снимок из каталога магазина, из объявления или
                            сделанный на месте. Чем ровнее диск в кадре, тем точнее
                            повторится рисунок.
                        </li>
                        <li>
                            <b>Настройки, если нужны</b>
                            Цвет, диаметр, номер в кадре. Цена пересчитывается сразу,
                            до запуска.
                        </li>
                        <li>
                            <b>Готовый кадр</b>
                            Примерно минута — и ваша машина на новых колёсах.
                            Результат остаётся в истории, его можно скачать.
                        </li>
                    </ol>
                </div>
            </section>

            <section class="gs-wl__sec gs-wl__tool-sec" id="gs-wl-tool">
                <div class="gs-wl__wrap">
                    <h2 class="gs-wl__h2">Примерьте прямо здесь</h2>
                    <p class="gs-wl__sub">
                        Это тот же инструмент, что и на отдельной странице: тот же
                        баланс, те же настройки, та же история примерок. Уходить
                        со страницы не нужно.
                    </p>
                    <div class="gs-wl__tool" data-gs-tool data-gs-tool-state="loading">
                        <iframe class="gs-wl__frame"
                                data-gs-tool-frame
                                src="<?php echo esc_url(add_query_arg(self::EMBED_FLAG, 1, $tool)); ?>"
                                title="Примерка дисков по фотографии"
                                scrolling="no"></iframe>
                        <p class="gs-wl__tool-note">
                            Инструмент не открылся прямо здесь —
                            <a href="<?php echo esc_url($tool); ?>">откройте его отдельной страницей</a>.
                        </p>
                    </div>
                </div>
            </section>

            <section class="gs-wl__sec gs-wl__sec--alt">
                <div class="gs-wl__wrap">
                    <h2 class="gs-wl__h2">Сколько стоит</h2>
                    <div class="gs-wl__tablewrap">
                        <table class="gs-wl__table">
                            <thead>
                                <tr><th>Что</th><th>Цена</th><th>Когда нужно</th></tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td>Первая примерка</td>
                                    <td class="gs-wl__free">бесплатно</td>
                                    <td>Попробовать, не пополняя баланс</td>
                                </tr>
                                <tr>
                                    <td>Примерка</td>
                                    <td><?php echo (int) self::PRICE; ?> ₽</td>
                                    <td>Каждый следующий запуск</td>
                                </tr>
                                <tr>
                                    <td>Перекрасить диски</td>
                                    <td>+<?php echo (int) self::PRICE_PAINT; ?> ₽</td>
                                    <td>Модель нравится, нужен другой цвет</td>
                                </tr>
                                <tr>
                                    <td>Задать диаметр</td>
                                    <td>+<?php echo (int) self::PRICE_SIZE; ?> ₽</td>
                                    <td>Сравнить 17, 18 и 19 дюймов между собой</td>
                                </tr>
                                <tr>
                                    <td>Оставить номер в кадре</td>
                                    <td>+<?php echo (int) self::PRICE_PLATE; ?> ₽</td>
                                    <td>Снимок для себя, а не для публикации</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <p class="gs-wl__note">
                        Баланс общий со всеми сервисами сайта: пополнили один раз —
                        тратите на примерку, озвучку или генерацию.
                    </p>
                </div>
            </section>

            <section class="gs-wl__sec">
                <div class="gs-wl__wrap">
                    <h2 class="gs-wl__h2">Кому это пригодится</h2>
                    <div class="gs-wl__grid">
                        <article class="gs-wl__card">
                            <h3>Владельцу машины</h3>
                            <p>Выбрать комплект на сезон и не гадать, не окажется ли он
                                слишком крупным или слишком скромным.</p>
                        </article>
                        <article class="gs-wl__card">
                            <h3>Магазину дисков</h3>
                            <p>Ответить покупателю картинкой вместо слов «вам подойдёт»:
                                сомнение снимается за минуту, а не за возврат.</p>
                        </article>
                        <article class="gs-wl__card">
                            <h3>Шинному центру</h3>
                            <p>Показать клиенту два-три варианта прямо за стойкой и
                                продать тот, который он увидел на своей машине.</p>
                        </article>
                        <article class="gs-wl__card">
                            <h3>Продавцу автомобиля</h3>
                            <p>Посмотреть, насколько другой комплект меняет вид машины
                                в объявлении — и стоит ли он возни перед продажей.</p>
                        </article>
                    </div>
                </div>
            </section>

            <section class="gs-wl__sec gs-wl__sec--alt">
                <div class="gs-wl__wrap">
                    <h2 class="gs-wl__h2">Чего примерка не делает</h2>
                    <p class="gs-wl__plain">
                        Это визуализация, а не подбор по каталогу. Нейросеть не проверяет
                        разболтовку, вылет и диаметр ступицы и не отвечает на вопрос,
                        встанет ли диск физически. Совместимость сверяйте по данным
                        производителя автомобиля или у продавца дисков. Примерка отвечает
                        на другой вопрос — как это будет выглядеть.
                    </p>
                </div>
            </section>

            <section class="gs-wl__sec">
                <div class="gs-wl__wrap">
                    <h2 class="gs-wl__h2">Частые вопросы</h2>
                    <div class="gs-wl__faq">
                        <?php foreach (self::faq() as $item): ?>
                            <details class="gs-wl__q">
                                <summary><?php echo esc_html($item[0]); ?></summary>
                                <p><?php echo esc_html($item[1]); ?></p>
                            </details>
                        <?php endforeach; ?>
                    </div>
                </div>
            </section>

            <section class="gs-wl__final">
                <div class="gs-wl__wrap">
                    <h2 class="gs-wl__h2">Примерьте диски на свою машину</h2>
                    <p class="gs-wl__sub">
                        Первая примерка бесплатна — посмотрите на результат, прежде чем
                        платить за комплект, который нельзя вернуть.
                    </p>
                    <p class="gs-wl__cta-row">
                        <a class="gs-wl__btn gs-wl__btn--big" href="#gs-wl-tool">Перейти к примерке</a>
                    </p>
                </div>
            </section>

        </div>
        <?php
        return (string) ob_get_clean();
    }



    /* ---------------------------------------------------------------------
     * Инструмент внутри посадочной
     * ------------------------------------------------------------------ */

    /** Метка, по которой инструмент понимает, что открыт в рамке. */
    const EMBED_FLAG = 'gswl';

    /**
     * Открыт ли инструмент внутри посадочной.
     *
     * Вставлять инструмент копией разметки нельзя: его рисует отдельный
     * плагин, который подключает свои стили, сценарии и сессию по своему
     * адресу, — в чужой странице от него осталась бы мёртвая форма. Поэтому
     * на посадочной стоит рамка с тем же адресом: оплата, личный кабинет и
     * история работают ровно те же, и в чужом плагине ничего не правится.
     *
     * В рамке страница должна быть голой — без шапки, подвала и наших
     * собственных блоков вокруг.
     */
    public static function is_embedded() {
        return self::is_page()
            && isset($_GET[self::EMBED_FLAG])
            && $_GET[self::EMBED_FLAG] !== '';
    }

    /**
     * Стили голого режима.
     *
     * Прячем всё, что рисуется вокруг инструмента: шапку и подвал темы,
     * наш текст под инструментом, липкую панель, уведомление о cookie и
     * блок ссылок на каталог. Внутри рамки это дубли того, что уже есть на
     * посадочной, да ещё и со своей прокруткой.
     */
    private static function embed_styles() {
        return "\n" . '<style id="gs-wheel-embed">'
            . 'html,body{background:#0b111d!important;margin:0!important;padding:0!important}'
            . '#page-header,header.l-header,.l-header,.l-subheader,.l-titlebar,'
            . '#page-footer,footer#page-footer,.l-footer,'
            . '.gs-wheel,.gs-footer-links,.gs-sticky,#gs-sticky,#cookie-notice'
            . '{display:none!important}'
            . '.l-main,.l-canvas,.l-section,.l-section__content{padding-top:0!important;'
            . 'padding-bottom:0!important;margin-top:0!important;margin-bottom:0!important}'
            . '</style>' . "\n";
    }

    /* ---------------------------------------------------------------------
     * Голова посадочной
     * ------------------------------------------------------------------ */

    private static $head_buffering = false;

    public static function landing_title() {
        return 'Примерка дисков онлайн по фото — как диски сядут на вашу машину';
    }

    public static function landing_description() {
        return 'Примерьте диски на свою машину по фотографии: загрузите снимок '
            . 'автомобиля и понравившегося диска и посмотрите результат до покупки. '
            . 'Цвет, диаметр и номер в кадре — отдельными опциями. Первая примерка '
            . 'бесплатно, дальше ' . self::PRICE . ' ₽.';
    }

    /**
     * Чужие теги в голове посадочной переписываем на свои.
     *
     * Страница обычная, поэтому описание ей ставит тема — то же самое общее
     * описание сайта, из-за которого инструмент и был невидим в выдаче.
     * Правим тем же способом, что и на странице инструмента, только буфером
     * одной головы: всю страницу здесь перехватывать незачем.
     */
    public static function head_start() {
        if (!self::is_landing()) {
            return;
        }
        self::$head_buffering = true;
        ob_start();
    }

    public static function head_flush() {
        if (!self::$head_buffering) {
            return;
        }
        self::$head_buffering = false;
        $html = ob_get_clean();
        if ($html === false || $html === '') {
            return;
        }

        $url = self::landing_url();
        $desc = self::landing_description();

        $html = self::replace_head($html, '~<meta[^>]+name=["\']description["\'][^>]*>~i',
            '<meta name="description" content="' . esc_attr($desc) . '">');
        $html = self::replace_head($html, '~<meta[^>]+property=["\']og:description["\'][^>]*>~i',
            '<meta property="og:description" content="' . esc_attr($desc) . '">');
        $html = self::replace_head($html, '~<meta[^>]+property=["\']og:title["\'][^>]*>~i',
            '<meta property="og:title" content="' . esc_attr(self::landing_title()) . '">');
        $html = self::replace_head($html, '~<link[^>]+rel=["\']canonical["\'][^>]*>~i',
            '<link rel="canonical" href="' . esc_url($url) . '">');

        $html .= self::landing_schema();

        echo $html; // phpcs:ignore WordPress.Security.EscapeOutput
    }

    /**
     * То же, что replace(), но для буфера wp_head.
     *
     * Внутри wp_head закрывающего тега головы ещё нет, поэтому недостающий
     * тег дописываем в конец буфера — он всё равно окажется в <head>.
     */
    private static function replace_head($html, $pattern, $replacement) {
        $count = 0;
        $html = preg_replace_callback($pattern, function () use (&$count, $replacement) {
            $count++;
            return $count === 1 ? $replacement : '';
        }, $html);
        if ($count === 0) {
            $html .= "\n" . $replacement . "\n";
        }
        return $html;
    }

    /**
     * Разметка посадочной.
     *
     * Отдельно от разметки инструмента: цена и вопросы те же, но адрес и
     * картинки свои, иначе две страницы описывают один и тот же объект по
     * одному адресу.
     */
    private static function landing_schema() {
        $faq = array();
        foreach (self::faq() as $item) {
            $faq[] = array(
                '@type' => 'Question',
                'name'  => $item[0],
                'acceptedAnswer' => array('@type' => 'Answer', 'text' => $item[1]),
            );
        }
        $images = array();
        foreach (self::examples() as $ex) {
            $images[] = $ex[3];
        }
        $url = self::landing_url();
        $data = array(
            '@context' => 'https://schema.org',
            '@graph'   => array(
                array(
                    '@type'       => 'Service',
                    'name'        => 'Примерка дисков онлайн по фото',
                    'description' => self::landing_description(),
                    'url'         => $url,
                    'image'       => $images,
                    'areaServed'  => 'RU',
                    'provider'    => array(
                        '@type' => 'Organization',
                        'name'  => get_bloginfo('name'),
                        'url'   => home_url('/'),
                    ),
                    'offers' => array(
                        '@type'         => 'Offer',
                        'price'         => (string) self::PRICE,
                        'priceCurrency' => 'RUB',
                        'url'           => self::url(),
                        'availability'  => 'https://schema.org/InStock',
                    ),
                ),
                array('@type' => 'FAQPage', 'mainEntity' => $faq),
                array(
                    '@type' => 'BreadcrumbList',
                    'itemListElement' => array(
                        array('@type' => 'ListItem', 'position' => 1,
                              'name' => 'Главная', 'item' => home_url('/')),
                        array('@type' => 'ListItem', 'position' => 2,
                              'name' => 'Примерка дисков', 'item' => $url),
                    ),
                ),
            ),
        );
        return "\n" . '<script type="application/ld+json">'
            . wp_json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            . '</script>' . "\n";
    }

}
