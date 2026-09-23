<?php
/**
 * Обвязка вокруг озвучки: кейсы, интеграции, цены.
 *
 * Озвучка — единственный сервис, за который платят каждый день, и при этом
 * три страницы вокруг неё («Кейсы», «Интеграции», «Цены») были заглушками
 * на десяток строк: заголовок, три подзаголовка и ни одной цифры. Человек,
 * который ищет «сколько стоит озвучить курс», уходил с них ни с чем.
 *
 * Сами страницы рисует плагин озвучки — его мы не трогаем. Поэтому блок
 * дописывается снизу фильтром: заглушка остаётся на месте, а под ней
 * появляется то, ради чего страницу открыли — расчёты, шаги и ссылки.
 *
 * Цены здесь продублированы из плагина озвучки: отдельной настройки у него
 * нет, число зашито в коде. Поменяется там — надо поменять и тут, иначе
 * страница цен начнёт врать. Это единственное место в нашем плагине, где
 * стоимость озвучки записана руками.
 */

if (!defined('ABSPATH')) {
    exit;
}

class GS_Voice_Hub {

    /** Столько стоит каждая тысяча знаков. */
    const PER_1000 = 18;

    /** Меньше этой суммы запуск не стоит, каким бы коротким ни был текст. */
    const MIN_CHARGE = 18;

    /** Надбавка за диалоговый режим, в процентах. */
    const DIALOGUE_EXTRA = 30;

    /** Сколько знаков текста уходит на минуту речи в среднем темпе. */
    const CHARS_PER_MINUTE = 1000;

    /** Адрес кабинета озвучки: там и происходит работа. */
    const STUDIO = '/tts-dashboard/';

    /** Какой блок дописывать к какой странице. */
    private static function pages() {
        return array(
            'tts-cases'        => 'cases',
            'tts-integrations' => 'integrations',
            'tts-pricing'      => 'pricing',
        );
    }

    public static function init() {
        add_filter('the_content', array(__CLASS__, 'append'), 20);
    }

    /** Какая из трёх страниц открыта сейчас, если открыта. */
    public static function current() {
        if (!is_page()) {
            return '';
        }
        $post = get_post();
        if (!$post) {
            return '';
        }
        $pages = self::pages();
        return isset($pages[$post->post_name]) ? $pages[$post->post_name] : '';
    }

    public static function is_page() {
        return self::current() !== '';
    }

    /**
     * Дописываем блок под содержимое страницы.
     *
     * Только в основном запросе и только один раз: тема зовёт the_content
     * и для выдержек в списках, а блок с таблицами там не нужен.
     */
    public static function append($html) {
        if (!in_the_loop() || !is_main_query()) {
            return $html;
        }
        $which = self::current();
        if ($which === '') {
            return $html;
        }
        switch ($which) {
            case 'cases':
                return $html . self::cases();
            case 'integrations':
                return $html . self::integrations();
            case 'pricing':
                return $html . self::pricing();
        }
        return $html;
    }

    /* ---------------------------------------------------------------------
     * Счёт
     * ------------------------------------------------------------------ */

    /**
     * Во сколько обойдётся текст такой длины.
     *
     * Считаем пропорционально и не додумываем округление: как именно плагин
     * озвучки обходится с неполной тысячей знаков, из его настроек не видно,
     * а придуманное правило на странице цен — это обещание, за которое потом
     * отвечать деньгами. Поэтому в примерах берём круглые объёмы.
     */
    public static function price($chars) {
        $chars = max(0, (int) $chars);
        $sum = $chars / 1000 * self::PER_1000;
        return max(self::MIN_CHARGE, (int) round($sum));
    }

    /** «1 440 ₽» — с пробелом в разрядах, как принято. */
    private static function rub($sum) {
        return number_format_i18n((int) $sum, 0) . ' ₽';
    }

    /** Ссылка на статью кластера, если она уже вышла. */
    private static function article($slug) {
        $post = get_page_by_path($slug, OBJECT, 'post');
        if (!$post || $post->post_status !== 'publish') {
            return '';
        }
        return (string) get_permalink($post);
    }

    private static function studio() {
        return home_url(self::STUDIO);
    }

    /* ---------------------------------------------------------------------
     * Кейсы
     * ------------------------------------------------------------------ */

    /** Задачи, под которые люди приходят, с расчётом на живых объёмах. */
    private static function scenarios() {
        return array(
            array(
                'title' => 'Закадровый голос для ролика',
                'text'  => 'Видео снято, картинка смонтирована, нужен голос поверх. '
                    . 'Текст пишется под готовый монтаж, файл сводится в редакторе.',
                'volume' => 'ролик на 3 минуты — около 3000 знаков',
                'chars' => 3000,
                'slug'  => 'zakadrovyy-golos-dlya-video',
            ),
            array(
                'title' => 'Короткие вертикальные ролики',
                'text'  => 'Shorts и рилс: реплика на полминуты, серия выпусков одним голосом. '
                    . 'Короткий текст упирается в минимум списания.',
                'volume' => 'ролик на 30 секунд — около 500 знаков',
                'chars' => 500,
                'slug'  => 'ozvuchka-video-dlya-shorts-i-rils',
            ),
            array(
                'title' => 'Карточки товаров на маркетплейсе',
                'text'  => 'Один шаблон реплики, подстановка характеристик, единый голос '
                    . 'на весь каталог. Считается по знакам, а не по числу карточек.',
                'volume' => '100 карточек по 400 знаков — 40 000 знаков',
                'chars' => 40000,
                'slug'  => 'ozvuchka-kartochek-tovarov-marketpleys',
            ),
            array(
                'title' => 'Рекламный ролик',
                'text'  => 'Жёсткий хронометраж и обязательные оговорки. Переделать текст '
                    . 'и переозвучить дешевле, чем пересобирать запись со студией.',
                'volume' => 'ролик на 30 секунд — около 500 знаков',
                'chars' => 500,
                'slug'  => 'ozvuchka-reklamnogo-rolika',
            ),
            array(
                'title' => 'Онлайн-курс и уроки',
                'text'  => 'Десять уроков, которые должны звучать одинаково и переписываются '
                    . 'каждые полгода. Правится один урок, а не весь модуль.',
                'volume' => '10 уроков по 8 минут — около 80 000 знаков',
                'chars' => 80000,
                'slug'  => 'ozvuchka-onlayn-kursa-i-urokov',
            ),
            array(
                'title' => 'Аудиокнига и аудиоверсия статьи',
                'text'  => 'Длинный текст разбивается на части, озвучивается кусками и '
                    . 'собирается обратно. Соседние куски подсказывают интонацию на стыке.',
                'volume' => 'книга на 300 000 знаков',
                'chars' => 300000,
                'slug'  => 'ozvuchit-knigu-neyrosetyu-audiokniga',
            ),
            array(
                'title' => 'Автоответчик и голосовое меню',
                'text'  => 'Приветствие, пункты меню, ожидание, нерабочее время. Поменялся '
                    . 'режим работы — правится одна фраза за минуту.',
                'volume' => '8 коротких реплик — около 900 знаков',
                'chars' => 900,
                'slug'  => 'ozvuchka-avtootvetchika-i-golosovogo-menyu',
            ),
            array(
                'title' => 'Замена студийной записи',
                'text'  => 'Там, где раньше звали диктора: правка одной фразы не означает '
                    . 'новую смену в студии и новый счёт.',
                'volume' => 'сценарий на 10 минут — около 10 000 знаков',
                'chars' => 10000,
                'slug'  => 'skolko-stoit-ozvuchka-diktor-ili-neyroset',
            ),
        );
    }

    private static function cases() {
        $rows = self::scenarios();
        ob_start();
        ?>
        <section class="gs-api gs-voicehub">
            <div class="gs-api__section">
                <h2>Восемь задач, под которые берут озвучку</h2>
                <p class="gs-api__text">
                    Цена считается по знакам текста: <?php echo esc_html(self::rub(self::PER_1000)); ?>
                    за каждую тысячу, минимум <?php echo esc_html(self::rub(self::MIN_CHARGE)); ?> за запуск.
                    Поэтому расчёт ниже идёт не от «сколько минут», а от того, сколько знаков
                    уйдёт на эти минуты: в среднем темпе минута речи — это около
                    <?php echo esc_html(number_format_i18n(self::CHARS_PER_MINUTE, 0)); ?> знаков.
                </p>

                <div class="gs-cross__grid">
                    <?php foreach ($rows as $row): $link = self::article($row['slug']); ?>
                        <article class="gs-cross__card">
                            <h3 class="gs-cross__title"><?php echo esc_html($row['title']); ?></h3>
                            <p class="gs-cross__text"><?php echo esc_html($row['text']); ?></p>
                            <p class="gs-api__muted">
                                <?php echo esc_html($row['volume']); ?> —
                                <strong><?php echo esc_html(self::rub(self::price($row['chars']))); ?></strong>
                                <?php if ($row['chars'] < 1000): ?>
                                    (по минимуму списания)
                                <?php endif; ?>
                            </p>
                            <?php if ($link !== ''): ?>
                                <p><a href="<?php echo esc_url($link); ?>">Как это делают — разбор по шагам</a></p>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                </div>

                <p class="gs-api__text">
                    Диалоговый режим, когда реплики читаются разными голосами, стоит на
                    <?php echo (int) self::DIALOGUE_EXTRA; ?>% дороже базового расчёта.
                    Бесплатные голоса — мужской, женский и бесплатная библиотека — списывают 0 ₽;
                    это отдельные голоса и другое качество, а не платный голос даром.
                </p>
                <p>
                    <a class="gs-btn gs-btn--primary" href="<?php echo esc_url(self::studio()); ?>">Открыть кабинет озвучки</a>
                </p>
            </div>
        </section>
        <?php
        return ob_get_clean();
    }

    /* ---------------------------------------------------------------------
     * Интеграции
     * ------------------------------------------------------------------ */

    private static function integrations() {
        $api = class_exists('GS_Api_Page') ? GS_Api_Page::get_url() : home_url('/api/');
        $base = untrailingslashit(rest_url(GS_Api::NS));
        ob_start();
        ?>
        <section class="gs-api gs-voicehub">
            <div class="gs-api__section">
                <h2>Как это подключается на самом деле</h2>
                <p class="gs-api__text">
                    Интеграция с любым сценарием — это три запроса: поставить задачу,
                    дождаться готовности, забрать файл. Названия узлов в n8n и модулей
                    в Make разные, суть одна.
                </p>

                <h3>Три шага, одинаковые везде</h3>
                <ol class="gs-api__list">
                    <li>
                        <strong>Запуск.</strong> POST <code><?php echo esc_html($base); ?>/generate</code>
                        с заголовком <code>Authorization: Bearer &lt;ключ&gt;</code> и телом
                        <code>{"service":"tts","text":"…","voice":"…"}</code>. В ответ приходит номер задачи.
                    </li>
                    <li>
                        <strong>Ожидание.</strong> GET <code><?php echo esc_html($base); ?>/tasks/{id}</code>
                        раз в несколько секунд, пока состояние не станет <code>completed</code>.
                        Либо, чтобы не опрашивать вовсе, при запуске передать
                        <code>callback_url</code> — на него придёт POST, когда файл будет готов.
                    </li>
                    <li>
                        <strong>Забор файла.</strong> В готовой задаче лежит ссылка на аудио —
                        её отдают дальше: в бот, в облако, в монтажную папку.
                    </li>
                </ol>

                <h3>n8n</h3>
                <p class="gs-api__text">
                    Узел <em>HTTP Request</em> с методом POST на <code>/generate</code>, дальше
                    <em>Wait</em> на 10–15 секунд и второй <em>HTTP Request</em> на <code>/tasks/{id}</code>
                    внутри узла <em>If</em>: пока состояние <code>pending</code> — возвращаемся к ожиданию.
                    Ключ храните в <em>Credentials</em>, а не в теле узла: иначе он уедет вместе
                    с экспортом сценария.
                </p>

                <h3>Make</h3>
                <p class="gs-api__text">
                    Цепочка из четырёх модулей: <em>HTTP → Make a request</em> (запуск),
                    <em>Sleep</em>, <em>HTTP → Make a request</em> (состояние) и
                    <em>HTTP → Get a file</em> (скачивание). Ветку повтора удобнее собрать
                    через <em>Repeater</em> с выходом по состоянию <code>completed</code>.
                </p>

                <h3>Telegram-бот</h3>
                <p class="gs-api__text">
                    Бот принимает текст сообщением, ставит задачу и отвечает голосовым, когда
                    файл готов. Формат <code>opus_48000_64</code> подходит для голосовых лучше
                    прочих: он и есть тот кодек, в котором Telegram хранит голосовые сообщения.
                    Ответ придёт не мгновенно — предупредите об этом одним сообщением, иначе
                    человек отправит текст второй раз и заплатит дважды.
                </p>

                <h3>Что стоит учесть до запуска</h3>
                <ul class="gs-api__list">
                    <li>Частота: не больше <?php echo (int) GS_Api::RATE_PER_MINUTE; ?> запросов в минуту на ключ.</li>
                    <li>Оплата за запуск, без абонплаты: считайте бюджет по знакам текста, который пойдёт через сценарий.</li>
                    <li>Ключ показывается один раз при выпуске — сохраните его сразу.</li>
                    <li>Сервис отдаёт аудиофайл: сведение с видео, субтитры и тайм-коды остаются за вами.</li>
                </ul>

                <p>
                    <a class="gs-btn gs-btn--primary" href="<?php echo esc_url($api); ?>">Документация и ключ</a>
                    <a class="gs-btn gs-btn--ghost" href="<?php echo esc_url(self::studio()); ?>">Попробовать руками</a>
                </p>
            </div>
        </section>
        <?php
        return ob_get_clean();
    }

    /* ---------------------------------------------------------------------
     * Цены
     * ------------------------------------------------------------------ */

    /** Типовые объёмы: по ним человек узнаёт свою задачу быстрее, чем по формуле. */
    private static function volumes() {
        return array(
            array('Пост или новость', '2 000 знаков', 2000, 'около 2 минут'),
            array('Сценарий ролика', '3 000 знаков', 3000, 'около 3 минут'),
            array('Статья в блог', '10 000 знаков', 10000, 'около 10 минут'),
            array('Урок курса', '8 000 знаков', 8000, 'около 8 минут'),
            array('Каталог из 100 карточек', '40 000 знаков', 40000, 'около 40 минут'),
            array('Курс из 10 уроков', '80 000 знаков', 80000, 'около 80 минут'),
            array('Небольшая книга', '300 000 знаков', 300000, 'около 5 часов'),
        );
    }

    private static function pricing() {
        ob_start();
        ?>
        <section class="gs-api gs-voicehub">
            <div class="gs-api__section">
                <h2>Сколько это в деньгах на живых объёмах</h2>
                <p class="gs-api__text">
                    Формула короткая: <?php echo esc_html(self::rub(self::PER_1000)); ?> за каждую
                    тысячу знаков, минимум <?php echo esc_html(self::rub(self::MIN_CHARGE)); ?> за запуск.
                    Чтобы не переводить это в уме каждый раз — таблица типовых объёмов.
                    Длительность в последнем столбце ориентировочная: она зависит от темпа
                    голоса и от того, сколько в тексте пауз.
                </p>

                <div class="gs-api__tablewrap">
                    <table class="gs-api__table">
                        <thead>
                            <tr>
                                <th>Что озвучиваем</th>
                                <th>Объём текста</th>
                                <th>Цена</th>
                                <th>Диалоговый режим</th>
                                <th>Примерно звучания</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach (self::volumes() as $row): ?>
                                <?php $sum = self::price($row[2]); ?>
                                <tr>
                                    <td><?php echo esc_html($row[0]); ?></td>
                                    <td><?php echo esc_html($row[1]); ?></td>
                                    <td><?php echo esc_html(self::rub($sum)); ?></td>
                                    <td><?php echo esc_html(self::rub(round($sum * (100 + self::DIALOGUE_EXTRA) / 100))); ?></td>
                                    <td><?php echo esc_html($row[3]); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <h3>Что входит в эту цену</h3>
                <ul class="gs-api__list">
                    <li>Сама генерация и готовый файл в выбранном формате — MP3, WAV/PCM или Opus.</li>
                    <li>Хранение готовых генераций в кабинете: файл можно скачать и позже.</li>
                    <li>Любой из платных голосов и любой из поддерживаемых языков — доплаты за язык нет.</li>
                </ul>

                <h3>Что в неё не входит</h3>
                <ul class="gs-api__list">
                    <li>Перевод текста: озвучивается ровно то, что вы дали, выбор языка влияет на произношение.</li>
                    <li>Сведение с видео, субтитры и тайм-коды — это делается в вашем редакторе.</li>
                    <li>Голос, похожий на конкретного человека: текст читают готовые голоса из библиотеки.</li>
                </ul>

                <h3>Когда платить не нужно</h3>
                <p class="gs-api__text">
                    Бесплатные голоса — мужской, женский и бесплатная библиотека — списывают
                    0 ₽. Это разумный способ проверить сам текст: как машина прочитает числа,
                    имена и сокращения, слышно и на бесплатном голосе. Платный голос стоит
                    включать, когда текст уже выверен.
                </p>

                <p>
                    <a class="gs-btn gs-btn--primary" href="<?php echo esc_url(self::studio()); ?>">Посчитать на своём тексте</a>
                </p>
            </div>
        </section>
        <?php
        return ob_get_clean();
    }
}
