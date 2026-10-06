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

    public static function render() {
        ob_start();
        ?>
        <div class="gs-proekt" data-gs-proekt>

            <section class="gs-proekt__hero">
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
                    <span>Разовая оплата, без подписки</span>
                    <span>Выгрузка в Word</span>
                </p>
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

            <section class="gs-proekt__app" data-gs-proekt-app hidden></section>

            <section class="gs-proekt__steps">
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
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>
        </div>
        <?php
        return ob_get_clean();
    }
}
