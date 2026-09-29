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
                        <li><strong>На тарифе «С запуском» — настроенная рекламная кампания</strong> на ваши
                        сообщества во ВКонтакте и Telegram и на сайт, чтобы первые подписчики
                        и заказы пришли сразу после обучения, а не «когда-нибудь потом».</li>
                    </ul>
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
                        <a class="gs-btn gs-btn--ghost gs-btn--lg" href="#zayavka">Выбрать базовый</a>
                    </article>

                    <article class="gs-course__plan gs-course__plan--best">
                        <span class="gs-course__plan-flag">С запуском</span>
                        <h3 class="gs-course__plan-name">С рекламной кампанией</h3>
                        <p class="gs-course__price"><?php echo esc_html(number_format_i18n(self::PRICE_PLUS)); ?> <span>₽</span></p>
                        <p class="gs-course__plan-note">Всё из базового плюс первые подписчики и заказы.</p>
                        <ul class="gs-course__plan-list">
                            <li>Всё, что входит в базовый тариф</li>
                            <li><strong>Настроенная рекламная кампания</strong> на ваше сообщество ВКонтакте</li>
                            <li><strong>Кампания на ваш Telegram-канал</strong> — с готовыми объявлениями</li>
                            <li><strong>Кампания на ваш сайт или страницу услуг</strong></li>
                            <li>Тексты и креативы для объявлений — из того, что вы сделали на обучении</li>
                            <li>Разбор первых результатов: что откручивать дальше, а что выключить</li>
                        </ul>
                        <a class="gs-btn gs-btn--primary gs-btn--lg" href="#zayavka">Выбрать с запуском</a>
                    </article>
                </div>
                <p class="gs-course__market">
                    Рекламный бюджет площадок оплачивается отдельно и напрямую — мы настраиваем
                    кампанию, а сколько на неё тратить, решаете вы. Начать можно с минимальной суммы.
                </p>
            </section>

            <section class="gs-course__form-wrap" id="zayavka">
                <div class="gs-course__form-text">
                    <h2 class="gs-section-title">Записаться на обучение</h2>
                    <p>Оставьте контакт — расскажем про ближайший поток, формат и стоимость,
                    ответим на вопросы. Без спама и звонков в неурочное время.</p>
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
                  'Мы настраиваем кампании на ваше сообщество ВКонтакте, ваш Telegram-канал и ваш сайт или страницу услуг: собираем аудитории, пишем объявления, берём креативы из работ, которые вы сделали на обучении, и разбираем первые результаты. Рекламный бюджет площадок вы оплачиваете отдельно и напрямую — начать можно с минимальной суммы.'),
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
