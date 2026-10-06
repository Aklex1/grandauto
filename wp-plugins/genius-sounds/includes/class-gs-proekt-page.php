<?php
/**
 * Страница наставника по индивидуальному проекту: /proekt/
 *
 * Одна страница, два состояния. Пока проекта нет — лендинг с бесплатным
 * подбором темы: школьник приходит из поиска с паникой «задали проект, не
 * знаю о чём», и просить у него регистрацию на этом шаге — значит потерять
 * его. Как только тема подобрана, та же страница превращается в рабочий
 * кабинет со списком шагов.
 *
 * Тексты лендинга авторские, из исходного проекта.
 */

if (!defined('ABSPATH')) {
    exit;
}

class GS_Proekt_Page {

    const SLUG = 'proekt';

    public static function boot() {
        add_shortcode('gs_proekt', array(__CLASS__, 'render'));
        add_action('wp_footer', array(__CLASS__, 'print_schema'), 20);
    }

    public static function url() {
        return home_url('/' . self::SLUG . '/');
    }

    public static function is_page() {
        $post = get_post();
        return $post && has_shortcode((string) $post->post_content, 'gs_proekt');
    }

    /** Примеры тем — показывают уровень наставника до всякой оплаты. */
    private static function examples() {
        return array(
            'Биология и здоровье' => array(
                'Питьевой режим школьников-спортсменов: опрос и рекомендации',
                'Как меняется пульс после разных видов разминки',
                'Сравнение содержания витамина C в соках из магазина и свежих фруктах',
            ),
            'Химия' => array(
                'Определение кислотности почвы на пришкольном участке',
                'Сравнение моющих средств: что эффективнее против жира',
                'Жёсткость водопроводной воды в моём районе',
            ),
            'Физика и инженерия' => array(
                'Модель солнечного зарядного устройства для телефона',
                'Шумовое загрязнение в школе: замеры и карта',
                'Как форма бумажного самолёта влияет на дальность полёта',
            ),
            'Информатика' => array(
                'Телеграм-бот с расписанием и заменами для класса',
                'Сайт-путеводитель по музеям моего города',
                'Как школьники проверяют фейки: опрос и памятка',
            ),
            'История и обществознание' => array(
                'История моей улицы в фотографиях и воспоминаниях жителей',
                'Финансовая грамотность старшеклассников: опрос и игра',
                'Семейный архив: мой прадед в годы войны',
            ),
            'Литература и языки' => array(
                'Молодёжный сленг в чатах класса: словарь',
                'Англицизмы в рекламе моего города',
                'Какие книги читают девятиклассники: опрос и рейтинг',
            ),
        );
    }

    /**
     * Картинка оформления. Имена постоянные — после перезаливки адрес не
     * меняется, и вёрстку править не нужно.
     */
    private static function img($key) {
        $map = array(
            'hero'    => 'proekt-hero.jpg',
            'steps'   => 'proekt-shagi.png',
            'defense' => 'proekt-zashchita.jpg',
        );
        if (!isset($map[$key])) {
            return '';
        }
        $up = wp_get_upload_dir();
        return trailingslashit($up['baseurl']) . '2026/10/' . $map[$key];
    }

    public static function render() {
        $uid = get_current_user_id();
        ob_start();
        ?>
        <div class="gs-proekt" data-gs-proekt data-gs-logged="<?php echo $uid > 0 ? 1 : 0; ?>">

            <section class="gs-proekt__hero">
                <div class="gs-proekt__hero-text">
                <h1 class="gs-proekt__title">Индивидуальный проект без паники: от темы до защиты</h1>
                <p class="gs-proekt__lead">
                    Наставник проведёт по шагам: подберёт тему, составит паспорт проекта и план,
                    подскажет, где искать источники, поможет с главами, презентацией и речью.
                    Проект остаётся твоим — поэтому его не стыдно защищать.
                </p>
                <ul class="gs-proekt__bullets">
                    <li>5 тем под твои интересы — бесплатно, за минуту</li>
                    <li>Паспорт: актуальность, цель, задачи, гипотеза — по ФГОС</li>
                    <li>Опрос или эксперимент и обработка твоих результатов</li>
                    <li>Презентация, защитная речь и 15 вопросов комиссии с ответами</li>
                </ul>
                <p class="gs-proekt__badges">
                    <span>9, 10 и 11 класс</span>
                    <span>Оплата с баланса сайта</span>
                    <span>Проект остаётся твоим</span>
                </p>
                </div>
                <img class="gs-proekt__hero-img" loading="lazy" width="960" height="540"
                     src="<?php echo esc_url(self::img('hero')); ?>"
                     alt="Школьник работает над индивидуальным проектом">
            </section>

            <section class="gs-proekt__start" data-gs-proekt-start>
                <h2>Начнём с темы — это бесплатно</h2>
                <div class="gs-proekt__form">
                    <label>Класс
                        <select data-gs-proekt-field="класс">
                            <option value="9">9</option>
                            <option value="10" selected>10</option>
                            <option value="11">11</option>
                        </select>
                    </label>
                    <label>Тип проекта
                        <select data-gs-proekt-field="тип">
                            <option value="">Не задан</option>
                            <option>Исследовательский</option>
                            <option>Прикладной (продукт)</option>
                            <option>Творческий</option>
                            <option>Социальный</option>
                            <option>Информационный</option>
                            <option>Инженерный</option>
                        </select>
                    </label>
                    <label class="gs-proekt__wide">Любимые предметы и увлечения
                        <input type="text" data-gs-proekt-field="предметы"
                               placeholder="биология, спорт, фотография">
                    </label>
                    <label class="gs-proekt__wide">Своя тема, если есть
                        <input type="text" data-gs-proekt-field="тема"
                               placeholder="можно оставить пустым">
                    </label>
                    <label class="gs-proekt__wide">Требования школы, если знаешь
                        <input type="text" data-gs-proekt-field="требования"
                               placeholder="объём, срок защиты, что требует руководитель">
                    </label>
                </div>
                <button type="button" class="gs-btn gs-btn--primary gs-btn--lg" data-gs-proekt-go>
                    Получить 5 тем бесплатно
                </button>
                <p class="gs-proekt__note">Без регистрации и оплаты. Дальше — по желанию.</p>
            </section>

            <section class="gs-proekt__account">
                <?php if ($uid > 0): ?>
                    <div class="gs-balance">
                        <span class="gs-balance__label">Баланс</span>
                        <span class="gs-balance__value" data-gs-proekt-balance><?php
                            echo esc_html(number_format_i18n(class_exists('GS_SFX')
                                ? (float) GS_SFX::get_balance($uid) : 0, 2)); ?> ₽</span>
                        <a class="gs-balance__topup" data-gs-topup
                           href="<?php echo esc_url(GS_Payments::topup_url('proekt')); ?>">Пополнить</a>
                    </div>
                    <p class="gs-proekt__note">
                        Баланс общий для всех инструментов сайта. Тариф списывается с него один раз.
                    </p>
                <?php else: ?>
                    <p class="gs-proekt__note">
                        Первый шаг — без входа. Чтобы работа сохранилась и можно было открыть
                        остальные шаги, <a href="<?php echo esc_url(home_url('/tts-login/?redirect='
                            . rawurlencode('/' . self::SLUG . '/'))); ?>">войдите</a>
                        или <a href="<?php echo esc_url(home_url('/tts-register/?redirect='
                            . rawurlencode('/' . self::SLUG . '/'))); ?>">создайте аккаунт</a> —
                        это минута.
                    </p>
                <?php endif; ?>
            </section>

            <section class="gs-proekt__app" data-gs-proekt-app hidden></section>

            <section class="gs-proekt__steps">
                <img class="gs-proekt__wide-img" loading="lazy" width="960" height="540"
                     src="<?php echo esc_url(self::img('steps')); ?>"
                     alt="Одиннадцать шагов индивидуального проекта">
                <h2>11 шагов — как в требованиях школы</h2>
                <ol class="gs-proekt__list">
                    <?php foreach (GS_Proekt::stages() as $s): ?>
                        <?php if ($s[0] === 'proverka') { continue; } ?>
                        <li><?php echo esc_html(preg_replace('~^\d+\.\s*~u', '', $s[1])); ?></li>
                    <?php endforeach; ?>
                </ol>
                <p class="gs-proekt__note">Отдельно — проверка твоего текста: что исправить до сдачи.</p>
            </section>

            <section class="gs-proekt__examples">
                <h2>Примеры тем</h2>
                <p class="gs-proekt__note">Наставник подберёт тему под твои интересы — вот как это выглядит.</p>
                <div class="gs-proekt__cards">
                    <?php foreach (self::examples() as $group => $items): ?>
                        <div class="gs-proekt__card">
                            <h3><?php echo esc_html($group); ?></h3>
                            <ul>
                                <?php foreach ($items as $item): ?>
                                    <li><?php echo esc_html($item); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>

            <section class="gs-proekt__defense">
                <img class="gs-proekt__wide-img" loading="lazy" width="960" height="540"
                     src="<?php echo esc_url(self::img('defense')); ?>"
                     alt="Защита индивидуального проекта перед комиссией">
                <h2>Защита — там, где обычно сыплются</h2>
                <p class="gs-proekt__lead">
                    Генераторы выдают текст и на этом заканчиваются. А спрашивают на защите:
                    зачем эта тема, откуда цифры, что сделано своими руками. Наставник готовит
                    презентацию, речь по минутам и пятнадцать вопросов комиссии с ответами —
                    чтобы на защите не было неожиданностей.
                </p>
            </section>

            <section class="gs-proekt__tariffs" id="tarify">
                <h2>Тарифы</h2>
                <p class="gs-proekt__note">
                    Оплата разовая, за проект, без подписки. Переход на старший тариф —
                    доплата разницы, а не полная цена заново.
                </p>
                <div class="gs-proekt__cards">
                    <?php $tariffs = GS_Proekt::tariffs(); ?>
                    <?php foreach (GS_Proekt::tariff_order() as $id): ?>
                        <?php list($name, $price, $limit, $days) = $tariffs[$id]; ?>
                        <div class="gs-proekt__card gs-proekt__card--tariff">
                            <h3><?php echo esc_html($name); ?></h3>
                            <p class="gs-proekt__price">
                                <?php echo $price > 0 ? esc_html(number_format_i18n($price)) . ' ₽' : 'бесплатно'; ?>
                            </p>
                            <ul>
                                <?php foreach (GS_Proekt::stages() as $s): ?>
                                    <?php if ($s[2] === $id): ?>
                                        <li><?php echo esc_html(preg_replace('~^\d+\.\s*~u', '', $s[1])); ?></li>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </ul>
                            <p class="gs-proekt__note">
                                <?php echo (int) $limit; ?> запросов, доступ <?php echo (int) $days; ?> дней
                            </p>
                            <?php if ($price > 0): ?>
                                <button type="button" class="gs-btn gs-btn--primary gs-proekt__buy"
                                        data-gs-proekt-pick="<?php echo esc_attr($id); ?>"
                                        data-price="<?php echo (int) $price; ?>"
                                        data-name="<?php echo esc_attr($name); ?>"
                                        <?php echo $uid > 0 ? '' : 'data-gs-auth'; ?>>
                                    Оплатить <?php echo esc_html(number_format_i18n($price)); ?> ₽
                                </button>
                            <?php else: ?>
                                <button type="button" class="gs-btn gs-btn--ghost gs-proekt__buy"
                                        data-gs-proekt-pick="free">Начать бесплатно</button>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>

            <section class="gs-proekt__faq">
                <h2>Частые вопросы</h2>
                <?php foreach (self::faq() as $pair): ?>
                    <p><strong><?php echo esc_html($pair[0]); ?></strong> <?php echo wp_kses_post($pair[1]); ?></p>
                <?php endforeach; ?>
            </section>

            <section class="gs-proekt__links">
                <h2>Разобраться самому</h2>
                <p class="gs-proekt__note">
                    Если хочется сначала понять, как устроена работа целиком:
                </p>
                <ul>
                    <li><a href="<?php echo esc_url(home_url('/individualnyy-proekt/')); ?>">Индивидуальный проект: как сделать и защитить</a>
                        — структура, объёмы разделов, календарь до защиты и вопросы комиссии.</li>
                    <li><a href="<?php echo esc_url(home_url('/temy-individualnogo-proekta-10-klass/')); ?>">120 тем для 10 класса</a>
                        — с подсказкой, что делать и где брать данные по каждой.</li>
                </ul>
            </section>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Вопросы на странице и они же в разметке.
     *
     * Держим одним списком: выдумывать для поисковика вопросы, которых нет
     * на странице, нельзя, а дублировать их руками — значит однажды развести.
     */
    private static function faq() {
        return array(
            array('Это сделает проект за меня?',
                  'Нет, и намеренно. Наставник ведёт по шагам и пишет черновики разделов, но там, где нужны '
                  . 'ваши цифры, оставляет пометку «вставь свои данные» и объясняет, как их получить. '
                  . 'На защите спрашивают именно про них.'),
            array('Нужно ли регистрироваться, чтобы попробовать?',
                  'Нет. Первый шаг — пять тем под ваши интересы — работает без входа. Аккаунт нужен дальше: '
                  . 'чтобы работа сохранилась и к ней можно было вернуться с другого устройства.'),
            array('Сколько стоит и есть ли подписка?',
                  'Подписки нет. Тариф оплачивается один раз за проект и списывается с общего баланса сайта: '
                  . '«Старт» — 490 ₽, «Проект» — 990 ₽, «Проект + защита» — 1490 ₽. Переход на старший тариф — '
                  . 'доплата разницы, а не полная цена заново.'),
            array('Что входит в тариф «Проект + защита»?',
                  'Все одиннадцать шагов: тема, паспорт по ФГОС, план, источники, теоретическая и практическая '
                  . 'главы, введение и заключение, оформление, презентация, защитная речь по минутам и '
                  . 'пятнадцать вопросов комиссии с ответами.'),
            array('Можно ли скачать результат в Word?',
                  'Да. Готовые шаги выгружаются одним файлом .doc с типовым оформлением — Times New Roman 14, '
                  . 'полуторный интервал, выравнивание по ширине. Файл открывается в Word и Google Документах '
                  . 'и правится дальше.'),
            array('Подойдёт ли девятому классу?',
                  'Да. Шаги те же, отличается объём: в 9 классе итоговый проект обычно короче, и наставник '
                  . 'подстраивается под указанные вами требования школы.'),
            array('Что будет, если тема уже занята одноклассником?',
                  'Наставник предложит другой угол на том же материале: другой объект наблюдения, другую '
                  . 'группу или период. Две работы на одном материале с разными данными — это две разные работы.'),
        );
    }

    /**
     * Разметка страницы: вопросы и тарифы.
     *
     * Тарифы отдаём как предложения услуги — по ним поисковик показывает цену
     * прямо в выдаче, а цена здесь и есть главный вопрос родителя.
     */
    public static function print_schema() {
        if (is_admin() || !self::is_page()) {
            return;
        }
        $items = array();
        foreach (self::faq() as $pair) {
            $items[] = array(
                '@type' => 'Question',
                'name'  => $pair[0],
                'acceptedAnswer' => array('@type' => 'Answer', 'text' => wp_strip_all_tags($pair[1])),
            );
        }
        $offers = array();
        $tariffs = GS_Proekt::tariffs();
        foreach (GS_Proekt::tariff_order() as $id) {
            if ((int) $tariffs[$id][1] <= 0) {
                continue;
            }
            $offers[] = array(
                '@type'         => 'Offer',
                'name'          => $tariffs[$id][0],
                'price'         => (string) (int) $tariffs[$id][1],
                'priceCurrency' => 'RUB',
                'url'           => self::url(),
                'availability'  => 'https://schema.org/InStock',
            );
        }
        $data = array(
            array(
                '@context'   => 'https://schema.org',
                '@type'      => 'FAQPage',
                'mainEntity' => $items,
            ),
            array(
                '@context'    => 'https://schema.org',
                '@type'       => 'Service',
                'name'        => 'Наставник по индивидуальному проекту',
                'serviceType' => 'Помощь с индивидуальным проектом для 9–11 класса',
                'url'         => self::url(),
                'areaServed'  => 'RU',
                'provider'    => array('@type' => 'Organization', 'name' => 'Genius-bot',
                                       'url' => home_url('/')),
                'offers'      => $offers,
            ),
        );
        foreach ($data as $one) {
            echo "\n<script type=\"application/ld+json\">"
                . wp_json_encode($one, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                . "</script>\n";
        }
    }
}
