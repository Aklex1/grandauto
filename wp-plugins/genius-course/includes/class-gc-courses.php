<?php
/**
 * Курсы кабинета.
 *
 * Кабинет перестал быть одним: кроме обучения AI-креатора появился
 * «Заработок на нейросетях», и у него другая программа, другая
 * страница и платный доступ. Чтобы не плодить второй плагин с теми же
 * таблицами, ролями и мастерской, курс стал полем: страница, программа,
 * тарифы и правило доступа описаны здесь, а остальной код спрашивает.
 *
 * Правило доступа одно и то же в кабинете, в маршрутах и в админке —
 * поэтому оно живёт в одном месте. Разъехавшиеся проверки «открыт ли
 * модуль» — это способ однажды отдать платную программу бесплатно.
 */

if (!defined('ABSPATH')) {
    exit;
}

class GC_Courses {

    /** Курс учеников, заведённых до появления второго курса. */
    const DEFAULT_KEY = 'kreator';

    public static function all() {
        return array(
            'kreator' => array(
                'key'     => 'kreator',
                'title'   => 'Обучение AI-креатора',
                'short'   => 'AI-креатор',
                'slug'    => 'kabinet-kursa',
                'option'  => 'gc_cabinet_page',
                'page'    => 'Кабинет обучения',
                'program' => array('GC_Program', 'kreator'),
                // Мастерская курса считает на арендованной машине: своя
                // сборка ComfyUI — часть ремесла, которому здесь учат.
                'studio'  => 'gpu',
                // Чат курса: вопросы и разбор чужих работ. Пусто — плашки нет.
                'chat'    => '',
                // Доступ выдаётся после оплаты вне сайта: кабинет не
                // продаёт, а только учит.
                'paywall' => false,
                'free'    => 0,
                'landing' => '',
                'plans'   => array(),
            ),
            'money' => array(
                'key'     => 'money',
                'title'   => 'Заработок на нейросетях',
                'short'   => 'Заработок',
                'slug'    => 'kabinet-zarabotok',
                'option'  => 'gc_cabinet_money_page',
                'page'    => 'Кабинет «Заработок на нейросетях»',
                'program' => array('GC_Program_Money', 'modules'),
                // Мастерская работает через шлюз моделей: ученику важно
                // сдать заказ, а не поднять видеокарту, и генерации
                // должны считаться всегда.
                'studio'  => 'kie',
                'chat'    => 'https://t.me/c/4407841852/3',
                // Вкладка с направлениями запуска: у курса про заработок
                // она есть, у курса про ремесло — нет.
                'launch'  => true,
                // Первый модуль открыт сразу, остальное — после оплаты
                // внутри кабинета.
                'paywall' => true,
                'free'    => 1,
                'landing' => 'obuchenie-zarabotku-na-neirosetyah',
                'plans'   => array(
                    'base' => array(
                        'title' => 'Базовый',
                        'price' => 22900,
                        'note'  => 'Всё обучение целиком и разбор ваших работ.',
                        'list'  => array(
                            'Вся программа: фото, звук, видео, музыка, тексты',
                            'Задания с разбором — что отдавать заказчику, а что переделать',
                            'Готовые работы в портфолио к концу обучения',
                            'Расчёт себестоимости и цены для заказчика',
                            'Доступ к сервисам, на которых учились',
                        ),
                    ),
                    'plus' => array(
                        'title' => 'С рекламной кампанией',
                        'flag'  => 'С запуском',
                        'price' => 31900,
                        'note'  => 'Всё из базового плюс первые подписчики и заказы.',
                        'list'  => array(
                            'Всё, что входит в базовый тариф',
                            'Настроенная рекламная кампания на ваше сообщество ВКонтакте',
                            'Кампания на ваш Telegram-канал — с готовыми объявлениями',
                            'Кампания на ваш сайт или страницу услуг',
                            'Тексты и креативы для объявлений — из того, что вы сделали на обучении',
                            'Разбор первых результатов: что откручивать дальше, а что выключить',
                        ),
                    ),
                ),
            ),
        );
    }

    public static function keys() {
        return array_keys(self::all());
    }

    public static function exists($course) {
        return array_key_exists((string) $course, self::all());
    }

    /** Описание курса. Пустой ключ — курс текущего человека. */
    public static function get($course = '') {
        $all = self::all();
        $course = (string) $course;
        if ($course === '' || !isset($all[$course])) {
            $course = self::current();
        }
        return isset($all[$course]) ? $all[$course] : $all[self::DEFAULT_KEY];
    }

    public static function field($course, $name, $default = '') {
        $def = self::get($course);
        return array_key_exists($name, $def) ? $def[$name] : $default;
    }

    public static function title($course) {
        return (string) self::field($course, 'title', '');
    }

    public static function plans($course) {
        return (array) self::field($course, 'plans', array());
    }

    public static function plan($course, $plan) {
        $plans = self::plans($course);
        return isset($plans[(string) $plan]) ? $plans[(string) $plan] : null;
    }

    /**
     * Чей это курс «сейчас».
     *
     * На странице кабинета — курс самой страницы: администратор заходит
     * смотреть любой из них, и его собственная учётка курса не имеет.
     * Вне страницы (маршруты кабинета) — курс вошедшего ученика.
     */
    public static function current() {
        if (!is_admin() && class_exists('GC_Cabinet')) {
            $page = GC_Cabinet::course_of_page();
            if ($page !== '') {
                return $page;
            }
        }
        $user_id = get_current_user_id();
        return $user_id > 0 ? self::of_user($user_id) : self::DEFAULT_KEY;
    }

    public static function of_user($user_id) {
        $course = (string) get_user_meta((int) $user_id, GC_Students::META_COURSE, true);
        return self::exists($course) ? $course : self::DEFAULT_KEY;
    }

    /* ---------------------------------------------------------------------
     * Доступ
     * ------------------------------------------------------------------ */

    public static function paywall($course) {
        return (bool) self::field($course, 'paywall', false);
    }

    /**
     * Оплачен ли курс.
     *
     * Администратор считается оплатившим: он ходит в кабинет смотреть,
     * как всё выглядит у ученика, и платить себе самому незачем.
     */
    public static function is_paid($user_id, $course = '') {
        $user_id = (int) $user_id;
        $course = $course !== '' ? $course : self::of_user($user_id);
        if (!self::paywall($course)) {
            return true;
        }
        if (user_can($user_id, 'manage_options')) {
            return true;
        }
        return (string) get_user_meta($user_id, GC_Students::META_PAID, true) !== '';
    }

    /** Сколько модулей открыто до оплаты. */
    public static function free_modules($course) {
        return max(0, (int) self::field($course, 'free', 0));
    }

    public static function module_open($user_id, $index, $course = '') {
        $course = $course !== '' ? $course : self::of_user($user_id);
        if (self::is_paid($user_id, $course)) {
            return true;
        }
        return (int) $index < self::free_modules($course);
    }

    /**
     * Открыты ли мастерская и сдача работ.
     *
     * До оплаты человек читает первый модуль — и всё. Генерации идут на
     * арендованной видеокарте, а работы на проверку разбирает человек:
     * и то, и другое стоит денег, и бесплатно отдавать их нельзя.
     */
    public static function tools_open($user_id, $course = '') {
        return self::is_paid($user_id, $course);
    }

    /**
     * Ссылка на чат курса.
     *
     * Адрес держим настройкой, а не в коде: тему в чате переносят,
     * группу пересоздают, и каждый раз выкатывать плагин ради одной
     * ссылки — лишняя работа и лишний риск.
     */
    public static function chat($course) {
        $saved = get_option('gc_chats', array());
        $course = $course !== '' ? $course : self::current();
        if (is_array($saved) && !empty($saved[$course])) {
            return (string) $saved[$course];
        }
        return (string) self::field($course, 'chat', '');
    }

    public static function landing_url($course) {
        $slug = (string) self::field($course, 'landing', '');
        return $slug !== '' ? home_url('/' . $slug . '/') : '';
    }
}
