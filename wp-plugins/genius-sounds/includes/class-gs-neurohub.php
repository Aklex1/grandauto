<?php
/**
 * Страница «Нейросети» (/neurohub/) в стилистике остальных микросервисов.
 *
 * Плагин kie-neurohub отдаёт её собственным шаблоном, поэтому разметку
 * не трогаем: добавляем вводный блок перед приложением, текстовую часть
 * с вопросами после него и перекрашиваем всё отдельным файлом стилей.
 *
 * Вводный блок нужен не только для вида: до него на странице не было ни
 * заголовка с запросом, ни текста — поисковику показывать было нечего.
 */

if (!defined('ABSPATH')) {
    exit;
}

class GS_Neurohub {

    public static function boot() {
        add_action('wp_body_open', array(__CLASS__, 'render_hero'), 5);
        add_action('wp_head', array(__CLASS__, 'render_meta'), 3);
    }

    public static function is_page() {
        return class_exists('GS_Links') && GS_Links::is_neurohub();
    }

    public static function url() {
        return GS_Links::neurohub_url();
    }

    public static function h1() {
        return 'Оживить фото нейросетью: анимация снимков и генерация изображений';
    }

    public static function seo_title() {
        return 'Оживить фото нейросетью онлайн — анимация фотографий и видео из снимка';
    }

    public static function seo_desc() {
        return 'Оживить фото нейросетью онлайн: анимация старых и семейных снимков, видео из фотографии, редактирование и генерация изображений по описанию. Без установки программ, результат за пару минут.';
    }

    /* ---------------------------------------------------------------------
     * Шапка страницы
     * ------------------------------------------------------------------ */

    public static function render_hero() {
        if (!self::is_page()) {
            return;
        }
        ?>
        <div class="gs-wrap gs-studio gs-nh-hero">
            <nav class="gs-breadcrumbs" aria-label="Хлебные крошки">
                <a href="<?php echo esc_url(home_url('/')); ?>">Главная</a>
                <span aria-hidden="true">/</span>
                <span class="gs-breadcrumbs__current">Нейросети</span>
            </nav>

            <section class="gs-hero gs-hero--studio">
                <span class="gs-hero__badge">Фото, видео и картинки</span>
                <h1 class="gs-hero__title"><?php echo esc_html(self::h1()); ?></h1>
                <p class="gs-hero__lead">
                    Загрузите снимок — нейросеть оживит его: добавит движение, мимику и поворот головы.
                    Здесь же можно отредактировать фотографию по описанию, заменить фон, увеличить качество
                    старого кадра или собрать картинку с нуля. Всё в браузере, без программ и монтажа.
                </p>
                <div class="gs-hero__meta">
                    <span class="gs-chip gs-chip--ok">оплата за результат</span>
                    <span class="gs-chip">анимация фото и видео</span>
                    <span class="gs-chip">редактирование по описанию</span>
                </div>
            </section>
        </div>
        <?php
    }

    /* ---------------------------------------------------------------------
     * Описание, вопросы и перелинковка под приложением
     * ------------------------------------------------------------------ */

    public static function render_footer_content() {
        ob_start();
        ?>
        <div class="gs-wrap gs-studio gs-nh-text">
            <section class="gs-api__section">
                <h2 class="gs-section-title">Что умеет раздел</h2>
                <div class="gs-nh-grid">
                    <?php foreach (self::features() as $feature): ?>
                        <article class="gs-nh-card">
                            <h3><?php echo esc_html($feature[0]); ?></h3>
                            <p><?php echo esc_html($feature[1]); ?></p>
                        </article>
                    <?php endforeach; ?>
                </div>
            </section>

            <section class="gs-api__section">
                <h2 class="gs-section-title">Как оживить фотографию</h2>
                <ol class="gs-steps">
                    <?php foreach (self::steps() as $step): ?>
                        <li class="gs-steps__item"><?php echo esc_html($step); ?></li>
                    <?php endforeach; ?>
                </ol>
            </section>

            <?php echo GS_Keywords::render('photo'); // phpcs:ignore WordPress.Security.EscapeOutput ?>

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

    public static function features() {
        return array(
            array('Анимация фото', 'Старый или современный снимок оживает: лёгкое движение головы, мимика, моргание. Подходит и для чёрно-белых архивных фотографий.'),
            array('Видео из фотографии', 'Из одного кадра собирается короткий ролик с движением камеры — для сторис, поздравления или заставки.'),
            array('Редактирование по описанию', 'Заменить фон, убрать лишний объект, примерить одежду, перенести стиль — словами, без фоторедактора.'),
            array('Улучшение качества', 'Апскейл вдвое с восстановлением деталей: мелкое или размытое фото становится пригодным для печати.'),
            array('Генерация картинок', 'Изображение по текстовому описанию — для карточек товара, обложек и иллюстраций.'),
            array('История работ', 'Все готовые файлы остаются в разделе «История»: их можно скачать позже или повторить генерацию с другим описанием.'),
        );
    }

    public static function steps() {
        return array(
            'Войдите в аккаунт и пополните баланс — оплата списывается только за выполненную работу.',
            'Выберите режим: анимация фото, редактирование, генерация изображения или видео.',
            'Загрузите снимок и при необходимости опишите результат словами.',
            'Нажмите «Сгенерировать» и подождите: фото оживает за одну–две минуты, видео дольше.',
            'Скачайте готовый файл — он останется в истории и после закрытия страницы.',
        );
    }

    public static function faq() {
        return array(
            array('Какое фото подойдёт, чтобы оживить его нейросетью?',
                  'Лучше всего работает портрет, где лицо хорошо различимо и занимает заметную часть кадра. Подойдут и старые снимки: главное, чтобы черты лица не были размыты до неузнаваемости. Если фотография мелкая или мутная, сначала прогоните её через улучшение качества, а потом уже анимируйте.'),
            array('Можно ли оживить старое или чёрно-белое фото?',
                  'Да, архивные и чёрно-белые снимки обрабатываются так же, как обычные. Порядок для семейного архива обычно такой: сканируем, увеличиваем качество, при желании раскрашиваем и только потом оживляем — так движение получается естественнее.'),
            array('Сколько это стоит?',
                  'Оплата поштучная, с баланса аккаунта: редактирование и генерация изображений — десятки рублей за запуск, видео дороже, точная цена показана рядом с каждым режимом. Абонентской платы нет, неизрасходованные деньги остаются на балансе.'),
            array('Сколько ждать результат?',
                  'Изображение готово за десятки секунд, анимация фото — за одну–две минуты, видео с движением камеры дольше, до нескольких минут. Страницу можно не держать открытой: готовый файл появится в истории.'),
            array('Кому принадлежат готовые файлы?',
                  'Вам. Ограничений на использование, в том числе коммерческое, мы не накладываем. Не загружайте только чужие фотографии без согласия людей на них.'),
            array('Что делать, если результат не понравился?',
                  'Повторите генерацию с другим описанием — в истории есть кнопка повтора, она подставляет прежние параметры. Для анимации помогает выбрать кадр, где лицо крупнее и лучше освещено.'),
        );
    }

    /* ---------------------------------------------------------------------
     * Метаданные
     * ------------------------------------------------------------------ */

    public static function render_meta() {
        if (!self::is_page()) {
            return;
        }
        $url = self::url();
        echo "\n";
        echo '<meta name="description" content="' . esc_attr(self::seo_desc()) . '">' . "\n";
        echo '<link rel="canonical" href="' . esc_url($url) . '">' . "\n";
        echo '<meta property="og:title" content="' . esc_attr(self::seo_title()) . '">' . "\n";
        echo '<meta property="og:description" content="' . esc_attr(self::seo_desc()) . '">' . "\n";
        echo '<meta property="og:url" content="' . esc_url($url) . '">' . "\n";
        echo '<meta property="og:type" content="website">' . "\n";

        $questions = array();
        foreach (self::faq() as $pair) {
            $questions[] = array(
                '@type'          => 'Question',
                'name'           => $pair[0],
                'acceptedAnswer' => array('@type' => 'Answer', 'text' => $pair[1]),
            );
        }
        $schema = array(
            array(
                '@context'            => 'https://schema.org',
                '@type'               => 'WebApplication',
                'name'                => self::h1(),
                'description'         => self::seo_desc(),
                'url'                 => $url,
                'applicationCategory' => 'MultimediaApplication',
                'operatingSystem'     => 'Any',
                'inLanguage'          => 'ru-RU',
            ),
            array(
                '@context'   => 'https://schema.org',
                '@type'      => 'FAQPage',
                'mainEntity' => $questions,
            ),
        );
        foreach ($schema as $item) {
            echo '<script type="application/ld+json">'
                . wp_json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                . '</script>' . "\n";
        }
    }
}
