<?php
/**
 * Страница сервиса презентаций и посадочные под брендовые запросы.
 *
 * Помимо собственной страницы сервиса здесь живут страницы вида
 * «аналог такого-то сервиса». Они честно названы аналогами: человек,
 * искавший чужой продукт, должен сразу понимать, куда попал, — иначе это
 * не посадочная, а подмена. Чужие названия используются только для
 * сравнения, что закон прямо разрешает.
 */

if (!defined('ABSPATH')) {
    exit;
}

class GS_Slides_Page {

    const SLUG        = 'sozdat-prezentaciyu';
    const OPT_PAGE    = 'gs_slides_page';
    const OPT_COST    = 'gs_slides_cost';
    const OPT_PIC     = 'gs_slides_pic_cost';
    const OPT_PAGES   = 'gs_slides_alt_pages';
    const DEFAULT_COST = 49;
    const DEFAULT_PIC  = 10;

    const SEO_TITLE = 'Нейросеть для генерации презентаций — Genius Slides';
    const SEO_DESC  = 'Нейросеть для генерации презентаций: опишите тему — сервис соберёт структуру слайдов, нарисует фоны и отдаст готовый файл PPTX. Презентацию можно доработать в PowerPoint или Google Slides.';

    public static function cost() {
        $cost = get_option(self::OPT_COST, null);
        return $cost === null ? self::DEFAULT_COST : max(0, (float) $cost);
    }

    /** Доплата за одну иллюстрацию рядом с текстом слайда. */
    public static function pic_cost() {
        $cost = get_option(self::OPT_PIC, null);
        return $cost === null ? self::DEFAULT_PIC : max(0, (float) $cost);
    }

    public static function pic_hint() {
        $cost = self::pic_cost();
        return $cost <= 0 ? 'бесплатно' : '+' . number_format_i18n($cost, 0) . ' ₽';
    }

    /** Подпись цены: у сервиса свой тариф, не привязанный к GS_Lab. */
    public static function price_hint() {
        $cost = self::cost();
        return $cost <= 0 ? 'бесплатно' : number_format_i18n($cost, 0) . ' ₽';
    }

    public static function boot() {
        add_filter('body_class', array(__CLASS__, 'body_class'));
        add_filter('document_title_parts', array(__CLASS__, 'title_parts'));
        add_action('wp_head', array(__CLASS__, 'head'), 1);
    }

    public static function register_shortcodes() {
        add_shortcode('genius_slides', array(__CLASS__, 'render'));
        add_shortcode('genius_slides_alt', array(__CLASS__, 'render_alt'));
    }

    /* ---------------------------------------------------------------------
     * Посадочные-аналоги
     * ------------------------------------------------------------------ */

    /**
     * Чужое название в заголовке — только в форме сравнения.
     *
     * Страница обязана честно отвечать на вопрос «куда я попал»: человек
     * искал конкретный продукт, и выдавать ему свой сервис за найденный
     * нельзя. Зато сказать «вот чем это заменить» — можно и полезно.
     */
    public static function alternatives() {
        return array(
            'gamma' => array(
                'id'        => 'gamma',
                'slug'      => 'analog-gamma-app',
                'brand'     => 'Gamma App',
                'h1'        => 'Аналог Gamma App на русском: Genius Slides',
                'seo_title' => 'Аналог Gamma App на русском — генерация презентаций Genius Slides',
                'seo_desc'  => 'Ищете Gamma App или его аналог на русском? Genius Slides собирает презентацию по теме: структура слайдов, фоны и готовый файл PPTX. Оплата российскими картами, интерфейс на русском.',
                'lead'      => 'Gamma App — зарубежный сервис генерации презентаций. Если вы искали именно его, ссылка на официальный сайт есть в поиске; здесь — российский аналог, который работает на русском и принимает оплату российскими картами.',
                'diff'      => array(
                    array('Русский язык', 'Структура слайдов и текст собираются сразу по-русски, а не переводом.'),
                    array('Оплата картой РФ', 'Без зарубежных карт и посредников.'),
                    array('Готовый PPTX', 'Файл открывается в PowerPoint, Google Slides и бесплатных редакторах — и правится дальше руками.'),
                    array('Оплата за запуск', 'Без подписки: платите за конкретную презентацию.'),
                ),
            ),
            'wepik' => array(
                'id'        => 'wepik',
                'slug'      => 'analog-wepik-prezentacii',
                'brand'     => 'Wepik',
                'h1'        => 'Аналог Wepik для презентаций: Genius Slides',
                'seo_title' => 'Аналог Wepik для презентаций — нейросеть Genius Slides',
                'seo_desc'  => 'Нейросети для презентаций вместо Wepik: Genius Slides собирает структуру слайдов, рисует фоны и отдаёт готовый PPTX. На русском, с оплатой российскими картами.',
                'lead'      => 'Wepik — редактор с шаблонами и генерацией от зарубежного разработчика. Если нужен именно он, найти его нетрудно; здесь — аналог, заточенный под другое: не подобрать шаблон, а собрать презентацию по теме с нуля.',
                'diff'      => array(
                    array('Не шаблон, а структура', 'Сервис сам раскладывает тему на слайды, а не просит заполнить готовый макет.'),
                    array('Русский язык', 'Текст пишется по-русски сразу.'),
                    array('Оплата картой РФ', 'Без зарубежных платёжных систем.'),
                    array('Файл, а не редактор', 'На выходе PPTX — дорабатывайте в привычной программе.'),
                ),
            ),
            'kimi' => array(
                'id'        => 'kimi',
                'slug'      => 'analog-kimi-slides',
                'brand'     => 'Kimi Slides',
                'h1'        => 'Аналог Kimi Slides на русском: Genius Slides',
                'seo_title' => 'Аналог Kimi Slides на русском — генерация презентаций нейросетью',
                'seo_desc'  => 'Ищете Kimi Slides или аналог? Genius Slides собирает презентацию по описанию темы: слайды, фоны и готовый файл PPTX. Русский интерфейс и оплата российскими картами.',
                'lead'      => 'Kimi Slides — функция презентаций у зарубежной модели. Если вам нужна именно она, она доступна на сайте разработчика; здесь — аналог на русском, с оплатой российскими картами и выгрузкой в PPTX.',
                'diff'      => array(
                    array('Без зарубежной регистрации', 'Не нужен иностранный номер и зарубежная карта.'),
                    array('Русский язык', 'И в интерфейсе, и в содержании слайдов.'),
                    array('Готовый PPTX', 'Файл сразу открывается в PowerPoint и Google Slides.'),
                    array('Понятная цена', 'Стоимость запуска указана на странице, подписки нет.'),
                ),
            ),
            'sokratik' => array(
                'id'        => 'sokratik',
                'slug'      => 'neiroset-dlya-prezentacii-analog',
                'brand'     => 'сервисов вроде Сократика',
                'h1'        => 'Нейросеть для презентации: аналог знакомых сервисов',
                'seo_title' => 'Нейросеть для презентации — сделать презентацию по теме за минуты',
                'seo_desc'  => 'Нейросеть для презентации: опишите тему — сервис соберёт слайды, нарисует фоны и отдаст готовый PPTX. Русский язык, оплата за запуск, без подписки.',
                'lead'      => 'Сервисов, собирающих презентацию по теме, стало много, и выбирают их обычно по трём вещам: язык, способ оплаты и формат выгрузки. Genius Slides работает на русском, принимает российские карты и отдаёт редактируемый PPTX.',
                'diff'      => array(
                    array('Русский язык', 'Слайды пишутся по-русски, а не переводятся.'),
                    array('Оплата картой РФ', 'Без зарубежных платёжных систем и подписок.'),
                    array('Редактируемый файл', 'PPTX, а не картинки и не закрытый онлайн-редактор.'),
                    array('Оплата за запуск', 'Платите за презентацию, а не за месяц доступа.'),
                ),
            ),
        );
    }

    public static function alt($id) {
        $all = self::alternatives();
        return $all[$id] ?? null;
    }

    /* ---------------------------------------------------------------------
     * Страницы
     * ------------------------------------------------------------------ */

    public static function ensure_pages() {
        self::ensure_one(self::OPT_PAGE, self::SLUG, self::SEO_TITLE, '[genius_slides]');

        $map = (array) get_option(self::OPT_PAGES, array());
        foreach (self::alternatives() as $id => $alt) {
            $page_id = (int) ($map[$id] ?? 0);
            if ($page_id > 0 && get_post($page_id)) {
                continue;
            }
            $map[$id] = self::ensure_one('', $alt['slug'], $alt['seo_title'], '[genius_slides_alt id="' . $id . '"]');
        }
        update_option(self::OPT_PAGES, $map, false);
    }

    private static function ensure_one($option, $slug, $title, $content) {
        $page_id = $option !== '' ? (int) get_option($option) : 0;
        if ($page_id > 0 && get_post($page_id)) {
            return $page_id;
        }
        $existing = get_page_by_path($slug);
        if ($existing) {
            $page_id = (int) $existing->ID;
            wp_update_post(array('ID' => $page_id, 'post_content' => $content, 'post_status' => 'publish'));
        } else {
            $page_id = (int) wp_insert_post(array(
                'post_title'   => $title,
                'post_content' => $content,
                'post_status'  => 'publish',
                'post_type'    => 'page',
                'post_name'    => $slug,
            ));
        }
        if ($option !== '' && $page_id > 0) {
            update_option($option, $page_id, false);
        }
        return $page_id;
    }

    public static function get_url() {
        $page_id = (int) get_option(self::OPT_PAGE);
        $url = $page_id > 0 ? get_permalink($page_id) : '';
        return $url ? $url : home_url('/' . self::SLUG . '/');
    }

    public static function alt_url($id) {
        $alt = self::alt($id);
        if (!$alt) {
            return '';
        }
        $map = (array) get_option(self::OPT_PAGES, array());
        $page_id = (int) ($map[$id] ?? 0);
        $url = $page_id > 0 ? get_permalink($page_id) : '';
        return $url ? $url : home_url('/' . $alt['slug'] . '/');
    }

    public static function is_page() {
        $page_id = (int) get_option(self::OPT_PAGE);
        return !is_admin() && $page_id > 0 && is_page($page_id);
    }

    public static function current_alt() {
        if (is_admin()) {
            return null;
        }
        $map = (array) get_option(self::OPT_PAGES, array());
        foreach ($map as $id => $page_id) {
            if ((int) $page_id > 0 && is_page((int) $page_id)) {
                return self::alt($id);
            }
        }
        return null;
    }

    public static function is_any() {
        return self::is_page() || self::current_alt() !== null;
    }

    public static function body_class($classes) {
        if (self::is_any()) {
            $classes[] = 'gs-studio-page';
            $classes[] = 'gs-chrome';
        }
        return $classes;
    }

    public static function title_parts($parts) {
        if (self::is_page()) {
            return array('title' => self::SEO_TITLE);
        }
        $alt = self::current_alt();
        if ($alt) {
            return array('title' => $alt['seo_title']);
        }
        return $parts;
    }

    public static function head() {
        $alt = self::current_alt();
        if (!self::is_page() && !$alt) {
            return;
        }
        $desc = $alt ? $alt['seo_desc'] : self::SEO_DESC;
        $title = $alt ? $alt['seo_title'] : self::SEO_TITLE;
        $url = $alt ? self::alt_url($alt['id']) : self::get_url();

        echo '<meta name="description" content="' . esc_attr($desc) . '">' . "\n";
        echo '<link rel="canonical" href="' . esc_url($url) . '">' . "\n";
        echo '<meta property="og:type" content="website">' . "\n";
        echo '<meta property="og:title" content="' . esc_attr($title) . '">' . "\n";
        echo '<meta property="og:description" content="' . esc_attr($desc) . '">' . "\n";
        echo '<meta property="og:url" content="' . esc_url($url) . '">' . "\n";
    }

    /* ---------------------------------------------------------------------
     * Разметка
     * ------------------------------------------------------------------ */

    public static function render($atts = array()) {
        $cost = self::cost();
        $logged = is_user_logged_in();

        ob_start();
        ?>
        <div class="gs-wrap gs-studio gs-slides">
            <nav class="gs-breadcrumbs" aria-label="Хлебные крошки">
                <a href="<?php echo esc_url(home_url('/')); ?>">Главная</a>
                <span aria-hidden="true">/</span>
                <span class="gs-breadcrumbs__current">Презентации</span>
            </nav>

            <header class="gs-hero">
                <span class="gs-hero__badge">Genius Slides</span>
                <h1 class="gs-hero__title">Нейросеть для генерации презентаций</h1>
                <p class="gs-hero__lead">
                    Опишите тему — сервис разложит её на слайды, напишет тезисы, нарисует фоны
                    и отдаст готовый файл PPTX. Дальше правьте в PowerPoint, Google Slides или
                    любом бесплатном редакторе: это обычная презентация, а не картинки.
                </p>
                <p class="gs-hero__price">
                    <?php echo esc_html(self::price_hint()); ?> за презентацию
                    <span class="gs-hero__price-add"><?php echo esc_html(self::pic_hint()); ?> за картинку на слайде</span>
                </p>
            </header>

            <?php if (!$logged): ?>
                <div class="gs-panel gs-slides__guest">
                    <p>Чтобы собрать презентацию, войдите — файл сохранится в вашей истории.</p>
                    <a class="gs-btn gs-btn--primary gs-btn--lg" data-gs-auth
                       href="<?php echo esc_url(GS_Auth::login_url()); ?>">Войти</a>
                </div>
            <?php else: ?>
                <div class="gs-slides__bar">
                    <span class="gs-slides__balance">
                        На балансе <strong id="gs-slides-balance"><?php echo esc_html(number_format_i18n(GS_SFX::get_balance(get_current_user_id()), 2)); ?></strong> ₽
                    </span>
                    <button type="button" class="gs-btn gs-btn--ghost" data-gs-topup>Пополнить</button>
                </div>

                <form class="gs-panel gs-form gs-slides__form" id="gs-slides-form" novalidate>
                    <div class="gs-field">
                        <label class="gs-label" for="gs-slides-topic">Тема презентации <span class="gs-req">*</span></label>
                        <textarea id="gs-slides-topic" class="gs-textarea" rows="3" maxlength="600"
                                  placeholder="Например: внедрение нейросетей в отдел маркетинга небольшой компании — что это даёт, сколько стоит, с чего начать"></textarea>
                        <p class="gs-hint">Чем конкретнее тема, тем меньше общих слов на слайдах.</p>
                    </div>

                    <div class="gs-field gs-slides__upload">
                        <label class="gs-label" for="gs-slides-file">Или свой текст файлом</label>
                        <input id="gs-slides-file" class="gs-slides__file" type="file"
                               accept="<?php echo esc_attr(GS_Doctext::accept()); ?>">
                        <label class="gs-slides__filebtn" for="gs-slides-file">Выбрать файл</label>
                        <span class="gs-slides__filename" id="gs-slides-filename">Файл не выбран</span>
                        <button type="button" class="gs-slides__fileclear" id="gs-slides-fileclear" hidden>убрать</button>
                        <p class="gs-hint">
                            TXT, DOCX, MD или RTF до 5 МБ. Презентация будет собрана по вашему тексту — без
                            додумывания фактов, которых в нём нет. Тему тогда можно не заполнять или
                            написать в неё уточнение.
                        </p>
                    </div>

                    <div class="gs-slides__row">
                        <div class="gs-field">
                            <label class="gs-label" for="gs-slides-count">Слайдов</label>
                            <input id="gs-slides-count" class="gs-input" type="number"
                                   min="<?php echo (int) GS_Slides::MIN_SLIDES; ?>"
                                   max="<?php echo (int) GS_Slides::MAX_SLIDES; ?>" value="8">
                        </div>
                        <div class="gs-field">
                            <label class="gs-label" for="gs-slides-style">Оформление</label>
                            <select id="gs-slides-style" class="gs-input gs-select">
                                <?php foreach (GS_Slides::styles() as $id => $style): ?>
                                    <option value="<?php echo esc_attr($id); ?>"><?php echo esc_html($style['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="gs-field">
                        <label class="gs-label" for="gs-slides-audience">Кому показываете (необязательно)</label>
                        <input id="gs-slides-audience" class="gs-input" type="text" maxlength="120"
                               placeholder="Например: руководителю, который про нейросети слышал, но не пробовал">
                    </div>

                    <div class="gs-form__foot">
                        <button class="gs-btn gs-btn--primary gs-btn--lg" type="submit" id="gs-slides-go">
                            Собрать структуру
                        </button>
                        <span class="gs-form__cost">бесплатно — платите только за готовый файл</span>
                    </div>
                    <p class="gs-form__note" id="gs-slides-note" role="status" aria-live="polite"></p>
                </form>

                <section class="gs-slides__result" id="gs-slides-result" hidden></section>
                <section class="gs-slides__history" id="gs-slides-history" hidden></section>
            <?php endif; ?>

            <section class="gs-course__block">
                <h2 class="gs-section-title">Как это работает</h2>
                <div class="gs-course__steps">
                    <article class="gs-course__step">
                        <span class="gs-course__num">1</span>
                        <h3>Вы описываете тему</h3>
                        <p>Одним абзацем: о чём презентация, кому её показывать и сколько нужно слайдов.</p>
                    </article>
                    <article class="gs-course__step">
                        <span class="gs-course__num">2</span>
                        <h3>Сервис собирает структуру</h3>
                        <p>Раскладывает тему на слайды и пишет по 2–4 тезиса на каждый — без воды и вводных слов.</p>
                    </article>
                    <article class="gs-course__step">
                        <span class="gs-course__num">3</span>
                        <h3>Рисует фоны</h3>
                        <p>Под каждый слайд — свой абстрактный фон в выбранном оформлении, все в одном стиле.</p>
                    </article>
                    <article class="gs-course__step">
                        <span class="gs-course__num">4</span>
                        <h3>Отдаёт PPTX</h3>
                        <p>Готовый файл: открывается в PowerPoint, Google Slides и бесплатных редакторах, правится как обычная презентация.</p>
                    </article>
                </div>
            </section>

            <section class="gs-course__block">
                <h2 class="gs-section-title">Что стоит знать заранее</h2>
                <div class="gs-prose">
                    <ul>
                        <li><strong>Цифры проверяйте.</strong> Модель не знает ваших данных: конкретные суммы, доли и даты подставляйте сами.</li>
                        <li><strong>Это черновик, а не финал.</strong> Хорошая презентация всегда дорабатывается — ради этого мы и отдаём редактируемый файл.</li>
                        <li><strong>Графики стройте отдельно.</strong> Диаграммы из ваших чисел делаются в редакторе: нейросеть нарисует похожее на график, но с выдуманными данными.</li>
                        <li><strong>Фоны абстрактные.</strong> Текст, схемы и логотипы на них не генерируются — их добавляют поверх.</li>
                    </ul>
                </div>
            </section>

            <?php echo self::links_block(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
        </div>
        <?php
        return ob_get_clean();
    }

    /** Посадочная-аналог: то же самое плюс честное объяснение отличий. */
    public static function render_alt($atts = array()) {
        $atts = shortcode_atts(array('id' => ''), $atts);
        $alt = self::alt((string) $atts['id']);
        if (!$alt) {
            return '';
        }
        $cost = self::cost();

        ob_start();
        ?>
        <div class="gs-wrap gs-studio gs-slides">
            <nav class="gs-breadcrumbs" aria-label="Хлебные крошки">
                <a href="<?php echo esc_url(home_url('/')); ?>">Главная</a>
                <span aria-hidden="true">/</span>
                <span class="gs-breadcrumbs__current">Презентации</span>
            </nav>

            <header class="gs-hero">
                <span class="gs-hero__badge">Genius Slides</span>
                <h1 class="gs-hero__title"><?php echo esc_html($alt['h1']); ?></h1>
                <p class="gs-hero__lead"><?php echo esc_html($alt['lead']); ?></p>
                <p class="gs-hero__price"><?php echo esc_html(self::price_hint()); ?> за презентацию</p>
            </header>

            <section class="gs-course__block">
                <h2 class="gs-section-title">Чем отличается Genius Slides</h2>
                <div class="gs-course__grid">
                    <?php foreach ($alt['diff'] as $pair): ?>
                        <article class="gs-course__card">
                            <h3><?php echo esc_html($pair[0]); ?></h3>
                            <p><?php echo esc_html($pair[1]); ?></p>
                        </article>
                    <?php endforeach; ?>
                </div>
            </section>

            <section class="gs-slides__cta">
                <h2 class="gs-section-title">Попробовать</h2>
                <p>Опишите тему — сервис соберёт слайды и отдаст готовый файл PPTX.</p>
                <a class="gs-btn gs-btn--primary gs-btn--lg" href="<?php echo esc_url(self::get_url()); ?>">
                    Открыть генератор презентаций
                </a>
            </section>

            <?php echo self::links_block(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
        </div>
        <?php
        return ob_get_clean();
    }

    /** Перелинковка: соседние посадочные и сервисы, нужные к презентации. */
    private static function links_block() {
        $links = array();
        foreach (self::alternatives() as $id => $alt) {
            if (self::current_alt() && self::current_alt()['id'] === $id) {
                continue;
            }
            $links[] = array(self::alt_url($id), $alt['h1']);
        }
        $links[] = array(GS_Lab::get_url('avatar'), 'Говорящий аватар — видео-вступление к презентации');
        $links[] = array(home_url('/tts-dashboard/'), 'Озвучка текста — аудиоверсия доклада');

        ob_start();
        ?>
        <section class="gs-course__block">
            <h2 class="gs-section-title">Ещё по теме</h2>
            <div class="gs-prose">
                <ul>
                    <?php foreach ($links as $link): ?>
                        <?php if ($link[0]): ?>
                            <li><a href="<?php echo esc_url($link[0]); ?>"><?php echo esc_html($link[1]); ?></a></li>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </ul>
            </div>
        </section>
        <?php
        return ob_get_clean();
    }
}
