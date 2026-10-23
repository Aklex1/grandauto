<?php
/**
 * Лендинг «Обучение заработку на нейросетях».
 *
 * Страница, на которую ведут статьи блога. Задача простая: объяснить, чему
 * именно учат, показать инструменты, которые уже работают на этом же сайте,
 * и собрать заявку. Заявка уходит в Telegram — см. GS_Leads.
 *
 * Обещаний конкретных сумм здесь намеренно нет: они не проверяемы и вредят
 * доверию сильнее, чем помогают конверсии.
 */

if (!defined('ABSPATH')) {
    exit;
}

class GS_Course {

    const OPT_PAGE  = 'gs_course_page';
    const OPT_IMG   = 'gs_course_images';
    /** Снимки к кейсам: по нескольку на кейс, отдельно от картинок блоков. */
    const OPT_CASES = 'gs_course_case_shots';
    const SLUG      = 'obuchenie-zarabotku-na-neirosetyah';

    // Ориентир по рынку: практические курсы с разбором заданий у крупных школ
    // идут от 25 000 ₽, короткие самостоятельные — 3 000–15 000 ₽. Базовый тариф
    // держим чуть ниже нижней границы «с обратной связью».
    const PRICE_BASE  = 22900;
    const PRICE_PLUS  = 31900;

    const SEO_TITLE = 'Обучение заработку на нейросетях — практический курс';
    const SEO_DESC  = 'Обучение заработку на нейросетях: озвучка, видео, музыка и тексты на заказ. Разбираем инструменты, первые заказы и цены на работу. Практика на реальных сервисах, без обещаний лёгких денег.';

    public static function boot() {
        add_filter('body_class', array(__CLASS__, 'body_class'));
        add_filter('document_title_parts', array(__CLASS__, 'title_parts'));
        add_action('wp_head', array(__CLASS__, 'head'), 1);
        add_filter('the_content', array(__CLASS__, 'prepend_banner'), 9);
    }

    /** Заголовок страницы целиком наш: тема иначе приклеит к нему имя сайта. */
    public static function title_parts($parts) {
        if (!self::is_page()) {
            return $parts;
        }
        return array('title' => self::SEO_TITLE);
    }

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

        // Вопросы и ответы размечаем: они попадают в выдачу отдельным блоком.
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
    }

    public static function register_shortcodes() {
        add_shortcode('genius_course', array(__CLASS__, 'render'));
    }

    /* ---------------------------------------------------------------------
     * Страница
     * ------------------------------------------------------------------ */

    /**
     * Страница курса.
     *
     * Цитату заполняем не для вида: содержимое страницы — один шорткод, и
     * сторонний SEO-блок темы собирал из него описание для выдачи —
     * «[genius_course]». Цитату он берёт раньше содержимого.
     */
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
                'post_content' => '[genius_course]',
                'post_excerpt' => self::SEO_DESC,
                'post_status'  => 'publish',
            ));
        } else {
            $page_id = (int) wp_insert_post(array(
                'post_title'   => self::SEO_TITLE,
                'post_content' => '[genius_course]',
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

    /** Снимки кейса по его ключу. */
    public static function case_shots($key) {
        $all = get_option(self::OPT_CASES, array());
        if (!is_array($all) || empty($all[$key]) || !is_array($all[$key])) {
            return array();
        }
        return array_values(array_filter(array_map('esc_url_raw', $all[$key])));
    }

    /** Сохранить снимки кейса. Пустой список — убрать карусель. */
    public static function set_case_shots($key, $urls) {
        $all = get_option(self::OPT_CASES, array());
        $all = is_array($all) ? $all : array();
        $clean = array_values(array_filter(array_map('esc_url_raw', (array) $urls)));
        if ($clean) {
            $all[(string) $key] = $clean;
        } else {
            unset($all[(string) $key]);
        }
        update_option(self::OPT_CASES, $all, false);
        return $all;
    }

    /**
     * Кейсы учеников.
     *
     * Обещаниям на посадочной не верят, и правильно делают. Верят
     * разобранному заказу: что сделал человек, сколько это заняло,
     * сколько стоило ему и сколько он получил. Поэтому в каждом кейсе
     * стоит не «доход до 100 000», а чек, время, расходы и остаток —
     * числа, которые можно проверить своим калькулятором.
     *
     * Картинки подставляются из настроек по ключу: кейс без картинки
     * показывается текстом, а не битой рамкой.
     */
    public static function cases() {
        return array(
            array(
                'key'    => 'case-interior',
                'who'    => 'Юлия',
                'title'  => 'Дизайн-проект офиса в нейросети',
                'lead'   => 'Заказчик хотел увидеть офис после ремонта до того, как начнёт его делать. '
                          . 'Юлия собрала альбом на 23 листа A3 по фотографиям и планировке — '
                          . 'без выездов и замеров.',
                'did'    => array(
                    'обмерный план и расстановка мебели: два зала, общий тамбур и санузел',
                    'план потолочного света с координатами в миллиметрах',
                    'ведомость отделки и палитра: зелёный, светлое дерево, белый камень',
                    'одиннадцать видов интерьера, включая вечерний свет',
                ),
                'facts'  => array(
                    array('Чек', '18 000 ₽'),
                    array('Время', 'два вечера, около 10 часов'),
                    array('Расходы на генерации', 'примерно 200 ₽'),
                    array('Осталось на руки', '≈ 17 800 ₽, это 1 780 ₽ в час'),
                ),
                'note'   => 'Дизайн-бюро за полный проект с чертежами и авторским надзором берёт в разы больше — '
                          . 'но и делает другое. Здесь продаётся понятная вещь: увидеть результат до ремонта.',
                'link'   => '',
                'linktext' => '',
            ),
            array(
                'key'    => 'case-channel',
                'who'    => 'Ученица курса',
                'title'  => 'Канал с промтами и свой бот',
                'lead'   => 'Канал с готовыми описаниями для фото: под каждым постом кнопка «Повторить это фото», '
                          . 'которая ведёт в бота. Подписчики генерируют — автор зарабатывает на их генерациях.',
                'did'    => array(
                    'выбрана узкая ниша: портреты и фотосессии, а не «нейросети вообще»',
                    'посты выходят регулярно, по одному-два в день',
                    '848 подписчиков без рекламного бюджета, на одних репостах и подборках',
                    'каждый пост работает дважды: как контент и как кнопка в бота',
                ),
                'facts'  => array(
                    array('Доход', '35 000 – 45 000 ₽ в месяц'),
                    array('Время', '20–30 минут в день'),
                    array('Старт', 'двадцать постов, написанных заранее'),
                    array('Что приносит деньги', 'генерации подписчиков в боте'),
                ),
                'note'   => 'Первые два месяца канал рос медленно — это нормальный срок, а не провал. '
                          . 'Деньги пошли, когда постов накопилось столько, что канал стало за что листать.',
                'link'   => 'https://t.me/promtnanobanana7',
                'linktext' => 'Посмотреть канал',
            ),
        );
    }

    /**
     * Расчёты по соседним направлениям.
     *
     * Это не кейсы: за ними нет конкретного человека, и выдавать их за
     * чужой опыт нельзя — на посадочной, где продают обучение
     * заработку, выдуманная история ученика стоит дороже любой
     * конверсии. Поэтому они стоят отдельным блоком и названы тем, чем
     * являются: арифметикой по рыночным ценам, которую читатель
     * проверяет своим калькулятором.
     */
    public static function models() {
        return array(
            array(
                'title' => 'Карточки для маркетплейса на абонементе',
                'lead'  => 'Два постоянных продавца вместо охоты за разовыми заказами. Самый предсказуемый '
                         . 'способ выйти на стабильные деньги в этом ремесле.',
                'rows'  => array(
                    array('Объём', '2 продавца × 8 товаров в месяц'),
                    array('Чек', '1 800 ₽ за комплект из пяти кадров'),
                    array('Выручка', '28 800 ₽ в месяц'),
                    array('Расходы на генерации', '≈ 250 ₽ (около 120 кадров с отбраковкой)'),
                    array('Время', '14–18 часов в месяц'),
                    array('Остаётся', '≈ 28 500 ₽, это 1 700 ₽ в час'),
                ),
                'note'  => 'Главная работа тут не в кадрах, а в том, чтобы найти двух продавцов и не сорвать '
                         . 'им сроки. Третий появляется сам — по рекомендации первых двух.',
            ),
            array(
                'title' => 'Озвучка и субтитры для онлайн-школы',
                'lead'  => 'У школ ролики выходят потоком, и каждый нужно озвучить, нарезать и снабдить '
                         . 'субтитрами. Работа однообразная, зато её всегда много.',
                'rows'  => array(
                    array('Объём', '25 роликов по 2–3 минуты в месяц'),
                    array('Чек', '700 ₽ за ролик с субтитрами'),
                    array('Выручка', '17 500 ₽ в месяц'),
                    array('Расходы', 'около 400 ₽ на озвучку и обработку'),
                    array('Время', '10–12 часов в месяц'),
                    array('Остаётся', '≈ 17 100 ₽ при двух вечерах в неделю'),
                ),
                'note'  => 'Это цифры одного клиента. Их обычно двое-трое, и тогда направление выходит '
                         . 'на 35–50 тысяч в месяц без роста затрат времени вдвое: дорожки делаются пакетом.',
            ),
        );
    }

    /**
     * Пакет генераций тарифа.
     *
     * Числа живут в плагине курса — там же, где они проверяются при
     * генерации. Если плагин выключен, посадочная всё равно должна
     * открываться, поэтому рядом лежат те же значения по умолчанию.
     *
     * @return array{images:int,videos:int,days:int}
     */
    public static function pack($plan) {
        if (class_exists('GC_Pack')) {
            $pack = GC_Pack::plan_pack($plan);
            if ($pack) {
                return $pack;
            }
        }
        $fallback = array(
            'base' => array('images' => 200, 'videos' => 20, 'days' => 60),
            'plus' => array('images' => 400, 'videos' => 40, 'days' => 120),
        );
        return isset($fallback[$plan]) ? $fallback[$plan] : $fallback['base'];
    }

    /** Что можно сделать пакетом — тариф в работах, а не в штуках. */
    public static function pack_equivalents($plan) {
        if (class_exists('GC_Pack')) {
            $rows = GC_Pack::equivalents($plan);
            if ($rows) {
                return $rows;
            }
        }
        $pack = self::pack($plan);
        return array(
            array((string) (int) floor($pack['images'] / 5), 'комплектов карточек', 'по пять кадров на товар'),
            array((string) $pack['videos'], 'рекламных роликов', 'по пять секунд со звуком, а в быстрой модели — по восемь'),
            array((string) (int) floor($pack['images'] / 3), 'обложек и баннеров', 'по три варианта на выбор заказчику'),
        );
    }

    /** Блок пакета в карточке тарифа. */
    private static function pack_block($plan) {
        $pack = self::pack($plan);
        ob_start();
        ?>
        <div class="gs-course__pack">
            <p class="gs-course__pack-head">Пакет генераций на <?php echo (int) $pack['days']; ?> дней</p>
            <p class="gs-course__pack-nums">
                <b><?php echo (int) $pack['images']; ?></b> кадров <i>+</i>
                <b><?php echo (int) $pack['videos']; ?></b> роликов
            </p>
            <p class="gs-course__pack-note">Ролик — 5 секунд со звуком в обычной модели или 8 секунд в быстрой;
                ступень «максимум» считается за два. Считаются готовые работы: неудачные попытки пакет не
                тратят, а закончится пакет — докупается прямо в кабинете.</p>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Адрес кабинета курса.
     *
     * Кабинет держит отдельный плагин: его может не быть включённым, и
     * тогда лендинг всё равно должен открываться — отдаём известный
     * адрес страницы.
     */
    public static function cabinet_url() {
        if (class_exists('GC_Cabinet')) {
            return (string) GC_Cabinet::url('money');
        }
        return home_url('/kabinet-zarabotok/');
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
            // По этому классу страница отодвигает кнопку темы «наверх»:
            // внизу у нас своя кнопка записи во всю ширину.
            $classes[] = 'gs-course-page';
        }
        return $classes;
    }

    /* ---------------------------------------------------------------------
     * Баннер в блоге
     * ------------------------------------------------------------------ */

    /**
     * Мини-баннер в начале каждой статьи блога.
     *
     * Ставим именно в начало: до конца лонгрида дочитывают не все, а блок
     * ссылок на инструменты в подвале статьи решает другую задачу.
     */
    public static function prepend_banner($content) {
        if (is_admin() || !is_singular('post') || !in_the_loop() || !is_main_query()) {
            return $content;
        }
        // Статьям про иск, приказ и характеристику на ученика заработок на
        // нейросетях не предлагаем: человек пришёл не за этим.
        if (class_exists('GS_Legal') && GS_Legal::is_doc_post()) {
            return $content;
        }
        // У кластера о профессии AI-креатора свой лендинг и свой баннер:
        // два баннера подряд в начале статьи — это уже не предложение, а
        // перекрытый первый экран.
        if (class_exists('GS_Kreator') && GS_Kreator::is_cluster_post()) {
            return $content;
        }
        return self::banner() . $content;
    }

    public static function banner() {
        $url = self::get_url();

        ob_start();
        ?>
        <aside class="gs-cbanner">
            <span class="gs-cbanner__spark" aria-hidden="true"></span>
            <div class="gs-cbanner__text">
                <p class="gs-cbanner__title">Те же нейросети умеют приносить деньги</p>
                <p class="gs-cbanner__lead">Озвучка, фотосессии, ролики и музыка на заказ — разбираем,
                что покупают, сколько это стоит и где брать первых клиентов.</p>
            </div>
            <a class="gs-cbanner__btn" href="<?php echo esc_url($url); ?>">Обучение заработку на нейросетях</a>
        </aside>
        <?php
        return ob_get_clean();
    }

    /** Картинки грузятся в медиатеку один раз, адреса лежат в настройке. */
    public static function image($slug) {
        $images = get_option(self::OPT_IMG, array());
        return is_array($images) && !empty($images[$slug]) ? (string) $images[$slug] : '';
    }

    public static function set_images($map) {
        update_option(self::OPT_IMG, array_map('esc_url_raw', (array) $map), false);
    }

    /* ---------------------------------------------------------------------
     * Разметка
     * ------------------------------------------------------------------ */

    public static function render($atts = array()) {
        $hero   = self::image('hero');
        $tools  = self::image('tools');
        $path   = self::image('path');
        $result = self::image('result');
        $photo  = self::image('photo');

        ob_start();
        ?>
        <div class="gs-wrap gs-studio gs-course">
            <nav class="gs-breadcrumbs" aria-label="Хлебные крошки">
                <a href="<?php echo esc_url(home_url('/')); ?>">Главная</a>
                <span aria-hidden="true">/</span>
                <span class="gs-breadcrumbs__current">Обучение</span>
            </nav>

            <section class="gs-course__hero"<?php echo $hero ? ' style="background-image:linear-gradient(90deg, rgba(8,12,20,.94) 0%, rgba(8,12,20,.78) 55%, rgba(8,12,20,.5) 100%), url(' . esc_url($hero) . ')"' : ''; ?>>
                <div class="gs-course__hero-text">
                    <span class="gs-hero__badge">Практический курс</span>
                    <h1 class="gs-course__title">Обучение заработку на нейросетях</h1>
                    <p class="gs-course__lead">
                        Учимся делать то, за что платят: фотосессии и портреты без съёмки,
                        озвучку роликов, видео из фотографий, музыку под заказ, расшифровку
                        записей и тексты. Не теория про будущее ИИ, а инструменты, готовые
                        работы и понятные цены на них.
                    </p>
                    <div class="gs-course__cta">
                        <a class="gs-btn gs-btn--primary gs-btn--lg" href="#zayavka">Оставить заявку</a>
                        <a class="gs-btn gs-btn--ghost gs-btn--lg" href="#programma">Смотреть программу</a>
                    </div>
                    <p class="gs-course__honest">
                        Без обещаний «миллион за месяц»: сколько получится — зависит от того,
                        сколько работ вы сделаете и кому предложите.
                    </p>
                </div>
            </section>

            <section class="gs-course__block">
                <h2 class="gs-section-title">Кому это подходит</h2>
                <div class="gs-course__grid">
                    <article class="gs-course__card">
                        <h3>Фрилансерам</h3>
                        <p>У вас уже есть заказчики на тексты, монтаж или дизайн. Нейросети добавляют
                        к этому услуги, которых раньше вы не брали: озвучку, музыку, оживление фото.</p>
                    </article>
                    <article class="gs-course__card">
                        <h3>Тем, кто начинает с нуля</h3>
                        <p>Не нужно быть монтажёром или музыкантом. Нужно уметь аккуратно выполнить
                        задачу и договориться о цене — этому и учим.</p>
                    </article>
                    <article class="gs-course__card">
                        <h3>Владельцам малого бизнеса</h3>
                        <p>Ролики, озвучка и картинки для своего дела — вместо подрядчиков.
                        Экономия начинается с первой же задачи.</p>
                    </article>
                    <article class="gs-course__card">
                        <h3>Блогерам и SMM</h3>
                        <p>Контента нужно много и регулярно. Нейросети закрывают рутину:
                        джинглы, субтитры, фоновая музыка, обложки.</p>
                    </article>
                </div>
            </section>

            <section class="gs-course__block">
                <h2 class="gs-section-title">Инструменты, на которых работаем</h2>
                <?php if ($tools): ?>
                    <img class="gs-course__img" src="<?php echo esc_url($tools); ?>" alt="Инструменты: озвучка, видео, музыка, изображения" loading="lazy" decoding="async">
                <?php endif; ?>
                <div class="gs-prose">
                    <p>Учимся не на скриншотах, а на живых сервисах — тех же, что работают на этом сайте.
                    Вы сразу видите, сколько стоит запуск, сколько занимает время и какого качества результат.</p>
                    <ul>
                        <li><a href="/tts-dashboard/">Озвучка текста</a> — закадровый голос для роликов и аудиоверсии статей.</li>
                        <li><a href="/pesnya-svoim-golosom/">Песня своим голосом</a> и <a href="/sozdat-muzyku/">генерация музыки</a> — поздравления, джинглы, фоновые треки.</li>
                        <li><strong>Генерация фотосессий и фото</strong> — портреты, предметная съёмка и карточки товара без студии, модели и фотографа.</li>
                        <li><a href="/ozhivit-foto/">Оживление фото</a> и <a href="/govoryashchiy-avatar/">говорящий аватар</a> — видео из одной фотографии.</li>
                        <li><a href="/rasshifrovka-audio/">Расшифровка записей</a> — интервью, созвоны, субтитры.</li>
                        <li><a href="/ubrat-vokal/">Разделение дорожек</a> и <a href="/ubrat-shum/">очистка звука</a> — работа с чужими записями.</li>
                        <li><a href="/api/">API</a> — когда заказов становится много и их пора ставить на поток.</li>
                    </ul>
                </div>
            </section>

            <section class="gs-course__block">
                <h2 class="gs-section-title">Фотосессии и фото без съёмки</h2>
                <?php if ($photo): ?>
                    <img class="gs-course__img" src="<?php echo esc_url($photo); ?>" alt="Серия сгенерированных портретов" loading="lazy" decoding="async">
                <?php endif; ?>
                <div class="gs-prose">
                    <p>Отдельное направление, с которого многие и начинают зарабатывать: заказчику
                    нужны снимки, а студия, модель и фотограф — нет. Нейросеть делает серию кадров
                    в одном стиле за вечер.</p>
                    <ul>
                        <li><strong>Портреты для соцсетей и резюме</strong> — один образ в нескольких ракурсах и вариантах света.</li>
                        <li><strong>Предметная съёмка</strong> — товар на аккуратном фоне, в интерьере, в руках.</li>
                        <li><strong>Карточки для маркетплейсов</strong> — самый ходовой заказ: снимков нужно много и регулярно.</li>
                        <li><strong>Обложки и фоны</strong> — для статей, каналов, презентаций и рекламы.</li>
                        <li><strong>Аватары и образы</strong> — для брендов, каналов и виртуальных ведущих.</li>
                    </ul>
                    <p>На занятиях разбираем не только как получить красивый кадр, но и что с ним
                    можно делать по закону: чужие лица, чужие логотипы и чужие товарные знаки —
                    это граница, за которую заходить не нужно.</p>
                </div>
            </section>

            <section class="gs-course__block" id="programma">
                <h2 class="gs-section-title">Программа</h2>
                <div class="gs-course__steps">
                    <article class="gs-course__step">
                        <span class="gs-course__num">1</span>
                        <h3>Что покупают и за сколько</h3>
                        <p>Разбираем реальные задачи: озвучка ролика, ролик из фотографий, песня
                        в подарок, субтитры к видео. Смотрим, сколько за это берут на биржах
                        и как считать свою цену, чтобы не работать в минус.</p>
                    </article>
                    <article class="gs-course__step">
                        <span class="gs-course__num">2</span>
                        <h3>Инструменты и качество</h3>
                        <p>Как получить результат, который не стыдно отдать заказчику: что писать
                        в описании, почему шумная запись портит всё, как исправлять неудачные
                        попытки вместо того, чтобы платить за них снова.</p>
                    </article>
                    <article class="gs-course__step">
                        <span class="gs-course__num">3</span>
                        <h3>Первые заказы</h3>
                        <p>Где искать заказчиков, что показать вместо портфолио на старте,
                        как отвечать на «а можно дешевле» и как не попасть на бесплатную
                        работу «на пробу».</p>
                    </article>
                    <article class="gs-course__step">
                        <span class="gs-course__num">4</span>
                        <h3>Поток и деньги</h3>
                        <p>Как считать себестоимость запуска, во сколько обходится час работы,
                        когда пора автоматизировать через API. Права на результат: что можно
                        публиковать, а что нет.</p>
                    </article>
                </div>
            </section>

            <section class="gs-course__block">
                <h2 class="gs-section-title">Как проходит обучение</h2>
                <?php if ($path): ?>
                    <img class="gs-course__img" src="<?php echo esc_url($path); ?>" alt="Три шага обучения" loading="lazy" decoding="async">
                <?php endif; ?>
                <div class="gs-prose">
                    <p>Занятия построены вокруг работ, а не лекций. На каждом шаге вы делаете
                    задание и получаете разбор: что отдать заказчику можно, а что переделать.</p>
                    <p>К концу обучения у вас есть несколько готовых работ — их и показывают
                    первым клиентам вместо портфолио, которого на старте нет.</p>
                </div>
            </section>

            <section class="gs-course__block">
                <h2 class="gs-section-title">Что будет на выходе</h2>
                <?php if ($result): ?>
                    <img class="gs-course__img" src="<?php echo esc_url($result); ?>" alt="Рабочее место после обучения" loading="lazy" decoding="async">
                <?php endif; ?>
                <div class="gs-prose">
                    <ul>
                        <li>Несколько готовых работ в разных форматах: звук, видео, текст.</li>
                        <li>Понимание себестоимости: сколько стоит запуск и сколько просить за работу.</li>
                        <li>Рабочие связки под частые заказы — от заявки до сдачи.</li>
                        <li>Доступ к сервисам, на которых вы учились: они остаются вашим рабочим инструментом.</li>
                        <li><strong>На тарифе «С запуском» — настроенная рекламная кампания в Яндекс
                        Директе</strong> на вашу страницу или бота, чтобы первые заказы пришли сразу после
                        обучения, а не «когда-нибудь потом». Рекламный кабинет оформляется на вас, бюджет
                        вы пополняете сами — деньги идут напрямую Яндексу.</li>
                    </ul>
                </div>
            </section>

            <section class="gs-course__block" id="kejsy">
                <h2 class="gs-section-title">Что получилось у учеников</h2>
                <p class="gs-course__market">
                    Два разобранных примера. Не «доход до 100 000» и не скриншот выписки, а то, что можно
                    посчитать самому: что человек сделал, сколько это заняло, во сколько обошлось и сколько
                    осталось на руках.
                </p>
                <div class="gs-course__cases">
                    <?php foreach (self::cases() as $case) :
                        $shot = self::image($case['key']); ?>
                        <?php $shots = self::case_shots($case['key']);
                        if (!$shots && $shot) { $shots = array($shot); } ?>
                        <article class="gs-course__case">
                            <?php if ($shots) : ?>
                                <?php /* Карусель: снимки листаются вбок, клик открывает
                                        увеличение поверх страницы, а не в новой вкладке —
                                        уводить человека с посадочной незачем. */ ?>
                                <div class="gs-case-shots" role="group" aria-label="Работы из кейса">
                                    <?php foreach ($shots as $i => $url) : ?>
                                        <button type="button" class="gs-case-shot" data-full="<?php echo esc_url($url); ?>"
                                                aria-label="Открыть изображение <?php echo (int) ($i + 1); ?>">
                                            <img src="<?php echo esc_url($url); ?>" alt="" loading="lazy" decoding="async">
                                        </button>
                                    <?php endforeach; ?>
                                </div>
                                <?php if (count($shots) > 1) : ?>
                                    <p class="gs-case-shots__hint">Листайте вбок · нажмите, чтобы увеличить</p>
                                <?php endif; ?>
                            <?php endif; ?>
                            <p class="gs-course__case-who"><?php echo esc_html($case['who']); ?></p>
                            <h3 class="gs-course__case-title"><?php echo esc_html($case['title']); ?></h3>
                            <p class="gs-course__case-lead"><?php echo esc_html($case['lead']); ?></p>
                            <ul class="gs-course__case-did">
                                <?php foreach ((array) $case['did'] as $line) : ?>
                                    <li><?php echo esc_html($line); ?></li>
                                <?php endforeach; ?>
                            </ul>
                            <dl class="gs-course__case-facts">
                                <?php foreach ((array) $case['facts'] as $fact) : ?>
                                    <div>
                                        <dt><?php echo esc_html($fact[0]); ?></dt>
                                        <dd><?php echo esc_html($fact[1]); ?></dd>
                                    </div>
                                <?php endforeach; ?>
                            </dl>
                            <p class="gs-course__case-note"><?php echo esc_html($case['note']); ?></p>
                            <?php if (!empty($case['link'])) : ?>
                                <a class="gs-btn gs-btn--ghost" href="<?php echo esc_url($case['link']); ?>"
                                   target="_blank" rel="noopener"><?php echo esc_html($case['linktext']); ?></a>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                </div>

                <div class="gs-course__models">
                    <h3 class="gs-course__models-title">Как считаются деньги в соседних направлениях</h3>
                    <p class="gs-course__models-note">
                        Ниже не кейсы: за ними нет конкретного человека. Это расчёт по рыночным ценам —
                        чтобы было видно, из чего складывается доход, и можно было пересчитать под себя.
                    </p>
                    <div class="gs-course__modelgrid">
                        <?php foreach (self::models() as $model) : ?>
                            <article class="gs-course__model">
                                <p class="gs-course__model-tag">Расчёт</p>
                                <h4><?php echo esc_html($model['title']); ?></h4>
                                <p class="gs-course__model-lead"><?php echo esc_html($model['lead']); ?></p>
                                <dl>
                                    <?php foreach ((array) $model['rows'] as $row) : ?>
                                        <div>
                                            <dt><?php echo esc_html($row[0]); ?></dt>
                                            <dd><?php echo esc_html($row[1]); ?></dd>
                                        </div>
                                    <?php endforeach; ?>
                                </dl>
                                <p class="gs-course__model-note"><?php echo esc_html($model['note']); ?></p>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </div>
            </section>

            <section class="gs-course__block" id="tarify">
                <h2 class="gs-section-title">Тарифы</h2>
                <p class="gs-course__market">
                    Для сравнения: практические курсы по нейросетям с разбором заданий у крупных
                    школ стоят от 25 000 до 60 000 ₽, программы уровня «профессия» — от 40 000 ₽.
                    Записи без обратной связи — 3 000–15 000 ₽, но там вас никто не проверяет.
                </p>
                <div class="gs-course__plans">
                    <article class="gs-course__plan">
                        <h3 class="gs-course__plan-name">Базовый</h3>
                        <p class="gs-course__price"><?php echo esc_html(number_format_i18n(self::PRICE_BASE)); ?> <span>₽</span></p>
                        <p class="gs-course__plan-note">Всё обучение целиком и разбор ваших работ.</p>
                        <ul class="gs-course__plan-list">
                            <li>Вся программа: фото, звук, видео, музыка, тексты</li>
                            <li>Задания с разбором — что отдавать заказчику, а что переделать</li>
                            <li>Готовые работы в портфолио к концу обучения</li>
                            <li>Расчёт себестоимости и цены для заказчика</li>
                            <li>Доступ к сервисам, на которых учились</li>
                        </ul>
                        <?php echo self::pack_block('base'); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                        <a class="gs-btn gs-btn--ghost gs-btn--lg" href="#zayavka">Выбрать базовый</a>
                    </article>

                    <article class="gs-course__plan gs-course__plan--best">
                        <span class="gs-course__plan-flag">С запуском</span>
                        <h3 class="gs-course__plan-name">С рекламной кампанией</h3>
                        <p class="gs-course__price"><?php echo esc_html(number_format_i18n(self::PRICE_PLUS)); ?> <span>₽</span></p>
                        <p class="gs-course__plan-note">Всё из базового плюс первые заказы. Рекламный бюджет
                            Директа пополняете сами.</p>
                        <ul class="gs-course__plan-list">
                            <li>Всё, что входит в базовый тариф</li>
                            <li><strong>Настроенная рекламная кампания в Яндекс Директе</strong> — на вашу
                                страницу или бота</li>
                            <li><strong>Ключевые фразы, объявления и регионы</strong> — под ваши услуги и цены</li>
                            <li><strong>Счётчик Метрики с целями</strong>: видно, по каким запросам приходят заявки</li>
                            <li>Тексты и креативы для объявлений — из того, что вы сделали на обучении</li>
                            <li>Разбор первых результатов: что откручивать дальше, а что выключить</li>
                        </ul>
                        <?php echo self::pack_block('plus'); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                        <a class="gs-btn gs-btn--primary gs-btn--lg" href="#zayavka">Выбрать с запуском</a>
                    </article>
                </div>
                <p class="gs-course__market">
                    Рекламный бюджет площадок оплачивается отдельно и напрямую — мы настраиваем
                    кампанию, а сколько на неё тратить, решаете вы. Начать можно с минимальной суммы.
                </p>
                <div class="gs-course__packinfo">
                    <h3>Что успеете сделать пакетом тарифа «С рекламной кампанией»</h3>
                    <div class="gs-course__packgrid">
                        <?php foreach (self::pack_equivalents('plus') as $row) : ?>
                            <article>
                                <p class="gs-course__packnum"><?php echo esc_html($row[0]); ?></p>
                                <p class="gs-course__packwhat"><?php echo esc_html($row[1]); ?></p>
                                <p class="gs-course__packhow"><?php echo esc_html($row[2]); ?></p>
                            </article>
                        <?php endforeach; ?>
                    </div>
                    <p class="gs-course__market">
                        Генерации идут на наших серверах — своя видеокарта не нужна, и платить за неё
                        отдельно тоже. Кадр считается около тридцати секунд, ролик — минуту.
                        Пакет кончится раньше срока — продлим отдельно, это недорого.
                    </p>
                </div>

                <p class="gs-course__market">
                    Оплата проходит в личном кабинете, картой через ЮMoney. После заявки мы открываем
                    доступ и первый модуль — его можно пройти целиком и посмотреть, как устроено
                    обучение, до оплаты. Остальные модули, сдача работ и мастерская генераций
                    открываются сразу после платежа.
                </p>
            </section>

            <section class="gs-course__form-wrap" id="zayavka">
                <div class="gs-course__form-text">
                    <h2 class="gs-section-title">Записаться на обучение</h2>
                    <p>Оставьте контакт — расскажем про ближайший поток, формат и стоимость,
                    ответим на вопросы. Без спама и звонков в неурочное время.</p>
                    <p>Уже учитесь? <a href="<?php echo esc_url(self::cabinet_url()); ?>">Вход в личный кабинет</a>.</p>
                </div>
                <form class="gs-panel gs-form gs-course__form" id="gs-course-form" novalidate>
                    <div class="gs-field">
                        <label class="gs-label" for="gs-course-name">Как к вам обращаться</label>
                        <input id="gs-course-name" class="gs-input" type="text" maxlength="80" placeholder="Имя">
                    </div>
                    <div class="gs-field">
                        <label class="gs-label" for="gs-course-contact">Telegram, телефон или почта <span class="gs-req">*</span></label>
                        <input id="gs-course-contact" class="gs-input" type="text" maxlength="120" placeholder="@nickname, +7… или mail@example.com" required>
                    </div>
                    <div class="gs-field">
                        <label class="gs-label" for="gs-course-plan">Тариф</label>
                        <select id="gs-course-plan" class="gs-input gs-select">
                            <option value="Базовый">Базовый — <?php echo esc_html(number_format_i18n(self::PRICE_BASE)); ?> ₽</option>
                            <option value="С рекламной кампанией">С рекламной кампанией — <?php echo esc_html(number_format_i18n(self::PRICE_PLUS)); ?> ₽</option>
                            <option value="Пока не выбрал">Пока не выбрал — нужен совет</option>
                        </select>
                    </div>
                    <div class="gs-field">
                        <label class="gs-label" for="gs-course-comment">Что интересует (необязательно)</label>
                        <textarea id="gs-course-comment" class="gs-textarea" rows="3" maxlength="600" placeholder="Например: хочу делать ролики для маркетплейсов"></textarea>
                    </div>
                    <div class="gs-form__foot">
                        <button class="gs-btn gs-btn--primary gs-btn--lg" type="submit" id="gs-course-send">Отправить заявку</button>
                    </div>
                    <p class="gs-form__note" id="gs-course-note" role="status" aria-live="polite"></p>
                    <p class="gs-course__privacy">
                        Отправляя заявку, вы соглашаетесь на обработку контактных данных для ответа на обращение.
                    </p>
                </form>
            </section>

            <section class="gs-faq">
                <h2 class="gs-section-title">Частые вопросы</h2>
                <?php foreach (self::faq() as $pair): ?>
                    <details class="gs-faq__item">
                        <summary class="gs-faq__q"><?php echo esc_html($pair[0]); ?></summary>
                        <p class="gs-faq__a"><?php echo esc_html($pair[1]); ?></p>
                    </details>
                <?php endforeach; ?>
            </section>

            <?php
            /*
             * Кнопка записи, которая едет вместе со страницей.
             *
             * Лендинг длинный: программа, кейсы, тарифы, вопросы. Человек
             * решается в разных местах, а форма одна и живёт внизу —
             * возвращаться к ней через полстраницы прокрутки никто не
             * станет. Поэтому кнопка висит всегда и прячется сама, когда
             * форма уже на экране: перекрывать то, что человек пришёл
             * заполнять, — худшее, что она может сделать.
             */
            ?>
            <a class="gs-course__float" id="gs-course-float" href="#zayavka">
                <span>Записаться на обучение</span>
                <i aria-hidden="true">→</i>
            </a>
        </div>
        <?php
        return ob_get_clean();
    }

    public static function faq() {
        return array(
            array('Нужен ли опыт в монтаже или программировании?',
                  'Нет. Все инструменты работают в браузере: загрузили файл или написали описание — получили результат. Программировать понадобится только тем, кто дойдёт до автоматизации через API, и это отдельная необязательная часть.'),
            array('Сколько стоит обучение?',
                  'Базовый тариф — 22 900 ₽, тариф с рекламной кампанией — 31 900 ₽. Для сравнения: практические курсы по нейросетям с разбором заданий у крупных школ идут от 25 000 ₽, а программы уровня «профессия» — от 40 000 ₽. Записи без обратной связи стоят дешевле, но там никто не смотрит ваши работы.'),
            array('Что именно входит в рекламную кампанию?',
                  'Мы настраиваем кампанию в Яндекс Директе на вашу страницу или бота: собираем ключевые фразы и регионы, пишем объявления, берём креативы из работ, которые вы сделали на обучении, ставим счётчик Метрики с целями и разбираем первые результаты. Рекламный кабинет оформляется на вас, и бюджет вы пополняете сами — деньги идут напрямую Яндексу, начать можно с минимальной суммы.'),
            array('Можно ли доплатить за рекламу позже?',
                  'Да. Если начали с базового тарифа и решили запускаться — доплачиваете разницу, и кампанию настраиваем после обучения, когда у вас уже есть готовые работы для объявлений.'),
            array('Сколько на этом реально зарабатывают?',
                  'Честный ответ: по-разному, и зависит это не от нейросети, а от того, сколько работ вы сделаете и кому их предложите. Мы показываем цены на типовые задачи и себестоимость запуска — дальше считайте сами.'),
            array('Нужен ли мощный компьютер?',
                  'Нет. Вся обработка идёт на сервере, вам нужен только браузер. Многое делается с телефона.'),
            array('Сколько стоят сами запуски?',
                  'Цена каждой операции указана на её странице: от бесплатного извлечения звука до генерации видео. На обучении вы сразу считаете себестоимость работы, а не узнаёте её потом.'),
            array('Кому принадлежат результаты?',
                  'Вам. Созданные озвучки, треки и видео можно использовать в коммерческих проектах. Ограничения касаются только чужих материалов — чужого текста песни или голоса другого человека.'),
            array('Что если не получится?',
                  'На разборах видно, где именно ломается результат: чаще всего дело в исходнике, а не в модели. Это и есть основная часть обучения — научиться отличать плохой исходник от плохой настройки.'),
        );
    }
}
