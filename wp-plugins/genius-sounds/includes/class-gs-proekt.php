<?php
/**
 * Наставник по индивидуальному проекту: шаги, тарифы, доступ, вызов модели.
 *
 * Чем отличается от конкурентов и почему сделано именно так: на рынке
 * продают «сгенерируй работу целиком» по подписке. Здесь наставник ведёт
 * ученика по одиннадцати шагам и намеренно не пишет за него — оставляет
 * места «[вставь свои данные]» там, где нужны настоящие цифры. Это честно
 * перед школой и, по разбору рынка в исходном проекте, как раз свободная
 * ниша.
 *
 * Оплата разовая, по тарифам, а не с общего баланса: родитель платит один
 * раз за проект. Деньги идут через ЮMoney тем же путём, что и юрдокументы
 * — меткой заказа, без подписок и второй платёжной системы.
 */

if (!defined('ABSPATH')) {
    exit;
}

class GS_Proekt {

    const OPT_PREFIX  = 'gs_proekt_';
    /** Список проектов человека: без него работа живёт только в одном браузере. */
    const META_LIST = 'gs_proekt_tokens';
    const LABEL_PREFIX = 'proekt_';
    const COOKIE = 'gs_proekt';

    /** До скольки знаков принимаем ввод ученика: дальше это уже не ввод, а работа целиком. */
    const MAX_INPUT = 15000;

    /** Сколько знаков прошлых шагов отдаём модели (примерно четыре знака на токен). */
    const CONTEXT_BUDGET = 60000;

    /** Сколько живёт проект без оплаты. */
    const FREE_TTL = 604800;   // 7 дней

    /**
     * Сколько бесплатных прогонов отдаём с одного адреса в сутки.
     *
     * Бесплатный шаг работает без регистрации — значит ничто не мешает
     * открывать новый проект после каждых трёх запросов и расходовать
     * чужие деньги у поставщика бесконечно.
     *
     * Считаем по вошедшему, а у гостя — по адресу. Порог высокий нарочно:
     * школа и мобильный оператор прячут за одним адресом целый класс, и
     * экономные пять запусков отрезали бы живых людей вместе с перебором.
     */
    const FREE_PER_DAY = 20;
    const FREE_KEY = 'gs_proekt_free_';

    /**
     * Шаги: идентификатор, название, минимальный тариф.
     * Порядок важен — на нём держится сборка контекста.
     */
    /**
     * Уборка брошенных проектов.
     *
     * Каждый проект — строка в настройках сайта. Бесплатный шаг открыт без
     * регистрации, значит строк будет много, и почти все — одноразовые.
     * Раз в сутки сносим то, за что не платили и к чему месяц не
     * возвращались; оплаченные не трогаем вообще.
     */
    public static function boot() {
        add_action('gs_proekt_cleanup', array(__CLASS__, 'cleanup'));
        if (!wp_next_scheduled('gs_proekt_cleanup')) {
            wp_schedule_event(time() + 3600, 'daily', 'gs_proekt_cleanup');
        }
    }

    public static function cleanup() {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT option_name, option_value FROM {$wpdb->options}
             WHERE option_name LIKE %s AND option_name NOT LIKE %s LIMIT 500",
            $wpdb->esc_like(self::OPT_PREFIX) . '%',
            '%' . $wpdb->esc_like('transient') . '%'
        ));
        $edge = time() - 30 * DAY_IN_SECONDS;
        $gone = 0;
        foreach ((array) $rows as $row) {
            $data = maybe_unserialize($row->option_value);
            if (!is_array($data) || !isset($data['created'])) {
                continue;
            }
            if (!empty($data['paid_at'])) {
                continue;
            }
            $last = (int) $data['created'];
            foreach ((array) ($data['steps'] ?? array()) as $step) {
                $last = max($last, (int) ($step['at'] ?? 0));
            }
            if ($last < $edge) {
                delete_option($row->option_name);
                $gone++;
            }
        }
        return $gone;
    }

    public static function stages() {
        return array(
            array('tema',          '1. Тема',                    'free'),
            array('pasport',       '2. Паспорт проекта',         'project'),
            array('plan',          '3. План и график',           'project'),
            array('istochniki',    '4. Источники',               'project'),
            array('teoriya',       '5. Теоретическая глава',     'project'),
            array('praktika',      '6. Практическая часть',      'project'),
            array('vvedenie_zakl', '7. Введение и заключение',   'project'),
            array('oformlenie',    '8. Оформление и проверка',   'project'),
            array('prezentacia',   '9. Презентация',             'defense'),
            array('rech',          '10. Защитная речь',          'defense'),
            array('voprosy',       '11. Вопросы комиссии',       'defense'),
            array('proverka',      'Проверка моего текста',      'project'),
        );
    }

    /**
     * Тариф: название и цена.
     *
     * Ступени две, а не три. «Старт» без глав продавался плохо по самой
     * своей сути: кому нужен текст работы — на нём не остановится, кому
     * хватит паспорта и плана — обойдётся бесплатным шагом. Осталось
     * понятное деление: сделать проект или сделать проект и защиту.
     */
    public static function tariffs() {
        return array(
            'free'    => array('Бесплатно',        0),
            'project' => array('Проект',           690),
            'defense' => array('Проект + защита',  1290),
        );
    }

    public static function tariff_order() {
        return array('free', 'project', 'defense');
    }

    /** Сколько раз можно переделать каждый шаг. */
    const TRIES_PER_STEP = 3;

    /**
     * Лимит запусков тарифа.
     *
     * Считаем от числа открытых шагов, а не задаём числом: «15 запросов»
     * школьнику ничего не говорит — он не знает, много это или мало, и на
     * всякий случай не покупает. «Каждый шаг можно переделать трижды»
     * понятно сразу, а в основе — та же защита от перебора.
     */
    public static function limit_for($tariff) {
        $open = 0;
        foreach (self::stages() as $s) {
            if (self::allows($tariff, $s[0])) {
                $open++;
            }
        }
        return max(3, $open * self::TRIES_PER_STEP);
    }

    /**
     * До какого числа открыт доступ.
     *
     * Срок в днях спорит с учебным годом: темы утверждают осенью,
     * защищаются весной, и купленный в октябре «месяц доступа» кончается
     * задолго до защиты. Поэтому оплаченный тариф живёт до конца сезона —
     * до 31 мая, а после 31 мая покупка открывает уже следующий учебный год.
     */
    public static function season_end($from = null) {
        $from = $from ? (int) $from : time();
        $year = (int) wp_date('Y', $from);
        $end = strtotime(sprintf('%d-05-31 23:59:59', $year));
        if ($from > $end) {
            $end = strtotime(sprintf('%d-05-31 23:59:59', $year + 1));
        }
        return (int) $end;
    }

    public static function stage_titles() {
        $out = array();
        foreach (self::stages() as $s) {
            $out[$s[0]] = $s[1];
        }
        return $out;
    }

    public static function stage_min_tariff() {
        $out = array();
        foreach (self::stages() as $s) {
            $out[$s[0]] = $s[2];
        }
        return $out;
    }

    public static function allows($tariff, $stage) {
        $order = self::tariff_order();
        $mins = self::stage_min_tariff();
        if (!isset($mins[$stage])) {
            return false;
        }
        return array_search($tariff, $order, true) >= array_search($mins[$stage], $order, true);
    }

    /** Переход на старший тариф — доплата разницы, а не полная цена заново. */
    public static function upgrade_price($current, $target) {
        $t = self::tariffs();
        if (!isset($t[$current], $t[$target])) {
            return 0;
        }
        return max(0, (int) $t[$target][1] - (int) $t[$current][1]);
    }

    /* ---------------------------------------------------------------------
     * Проекты
     * ------------------------------------------------------------------ */

    private static function free_key() {
        $user = get_current_user_id();
        if ($user > 0) {
            return self::FREE_KEY . 'u' . $user;
        }
        $ip = isset($_SERVER['REMOTE_ADDR'])
            ? (string) sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '0';
        return self::FREE_KEY . md5($ip);
    }

    public static function free_left() {
        return max(0, self::FREE_PER_DAY - (int) get_transient(self::free_key()));
    }

    private static function note_free() {
        $key = self::free_key();
        set_transient($key, (int) get_transient($key) + 1, DAY_IN_SECONDS);
    }

    public static function token_clean($token) {
        return preg_replace('~[^A-Za-z0-9]~', '', (string) $token);
    }

    public static function project($token) {
        $token = self::token_clean($token);
        if ($token === '') {
            return null;
        }
        $row = get_option(self::OPT_PREFIX . $token, null);
        if (!is_array($row)) {
            return null;
        }
        // Ступень «Старт» убрана из сетки, но проекты с ней уже куплены.
        // Переводим их на «Проект» при чтении: человек заплатил за шаги,
        // которые теперь входят в него, и терять доступ он не должен.
        $tariffs = self::tariffs();
        if (!isset($tariffs[(string) ($row['tariff'] ?? '')])) {
            $row['tariff'] = ((string) ($row['tariff'] ?? '')) === 'start' ? 'project' : 'free';
        }
        return $row;
    }

    private static function save($token, $row) {
        update_option(self::OPT_PREFIX . self::token_clean($token), $row, false);
    }

    /**
     * Привязать проект к человеку.
     *
     * Токен лежит в браузере, и этого достаточно, пока школьник работает с
     * одного устройства. Но оплативший с телефона открывает ноутбук и видит
     * пустую страницу — поэтому у вошедшего держим ещё и список его
     * проектов.
     */
    private static function remember_for_user($user, $token) {
        $user = (int) $user;
        if ($user <= 0) {
            return;
        }
        $list = get_user_meta($user, self::META_LIST, true);
        if (!is_array($list)) {
            $list = array();
        }
        array_unshift($list, self::token_clean($token));
        update_user_meta($user, self::META_LIST, array_slice(array_values(array_unique($list)), 0, 20));
    }

    /** Последний проект человека: к нему и возвращаем на новом устройстве. */
    public static function latest_for_user($user) {
        $user = (int) $user;
        if ($user <= 0) {
            return '';
        }
        $list = get_user_meta($user, self::META_LIST, true);
        if (!is_array($list)) {
            return '';
        }
        $best = '';
        $best_rank = array(-1, 0);
        foreach ($list as $token) {
            $row = self::project($token);
            if (!$row) {
                continue;
            }
            $at = max((int) ($row['created'] ?? 0), (int) ($row['paid_at'] ?? 0));
            $steps = 0;
            foreach ((array) ($row['steps'] ?? array()) as $step) {
                $at = max($at, (int) ($step['at'] ?? 0));
                if (!empty($step['output'])) {
                    $steps++;
                }
            }
            // Оплаченный проект важнее свежего пустого: человек вернулся
            // за тем, за что заплатил, а не за вчерашней пробой.
            $paid = !empty($row['paid_at']) && (int) $row['paid_until'] > time();
            $rank = array($paid ? 2 : ($steps > 0 ? 1 : 0), $at);
            if ($rank > $best_rank) {
                $best_rank = $rank;
                $best = (string) $row['token'];
            }
        }
        return $best;
    }

    public static function create($profile) {
        $token = wp_generate_password(24, false, false);
        self::remember_for_user(get_current_user_id(), $token);
        self::save($token, array(
            'token'    => $token,
            'profile'  => (array) $profile,
            'tariff'   => 'free',
            'paid_until' => time() + self::FREE_TTL,   // пробный доступ, не сезон
            'used'     => 0,
            'steps'    => array(),
            'created'  => time(),
            'user'     => get_current_user_id(),
        ));
        return $token;
    }

    /* ---------------------------------------------------------------------
     * Шаг
     * ------------------------------------------------------------------ */

    /**
     * Собрать сообщение модели: профиль, прошлые шаги, инструкция, ввод.
     *
     * Прошлые шаги режем с конца, но паспорт проекта оставляем всегда: на
     * нём держится вся работа — тема, цель, задачи. Если его выбросить,
     * следующие шаги начнут противоречить предыдущим.
     */
    public static function build_message($stage, $row, $input) {
        $prompts = gs_proekt_stage_prompts();
        $titles = self::stage_titles();

        $lines = array();
        foreach ((array) $row['profile'] as $key => $value) {
            $value = trim((string) $value);
            if ($value !== '') {
                $lines[] = '- ' . $key . ': ' . $value;
            }
        }
        if (!$lines) {
            $lines[] = '- (не заполнен)';
        }
        $lines[] = '- сегодня: ' . date_i18n('d.m.Y');
        $parts = array("<профиль_ученика>\n" . implode("\n", $lines) . "\n</профиль_ученика>");

        $steps = isset($row['steps']) && is_array($row['steps']) ? $row['steps'] : array();
        $ordered = array();
        foreach (self::stages() as $s) {
            $id = $s[0];
            if ($id !== $stage && $id !== 'proverka' && !empty($steps[$id]['output'])) {
                $ordered[] = $id;
            }
        }

        $budget = self::CONTEXT_BUDGET;
        $kept = array();
        foreach (array_reverse($ordered) as $id) {
            $text = (string) $steps[$id]['output'];
            if ($id !== 'pasport' && mb_strlen($text) > $budget) {
                continue;
            }
            $kept[] = array($id, $text);
            $budget -= mb_strlen($text);
        }
        foreach (array_reverse($kept) as $pair) {
            $parts[] = '<результат_шага name="' . $titles[$pair[0]] . '">' . "\n"
                . $pair[1] . "\n" . '</результат_шага>';
        }

        $parts[] = "<инструкция_шага>\n" . $prompts[$stage] . "\n</инструкция_шага>";
        $input = trim((string) $input);
        $parts[] = "<ввод_ученика>\n" . ($input !== '' ? $input : '(пусто)') . "\n</ввод_ученика>";
        return implode("\n\n", $parts);
    }

    /**
     * Прогнать шаг.
     *
     * Возвращает массив с ok/текстом или с причиной отказа и нужным тарифом —
     * чтобы интерфейс мог сразу предложить доплату, а не просто сказать
     * «нельзя».
     */
    public static function run($token, $stage, $input) {
        $prompts = gs_proekt_stage_prompts();
        if (!isset($prompts[$stage])) {
            return array('ok' => false, 'message' => 'Неизвестный шаг');
        }
        $row = self::project($token);
        if (!$row) {
            return array('ok' => false, 'message' => 'Проект не найден — начните заново');
        }

        $tariffs = self::tariffs();
        $tariff = (string) $row['tariff'];
        $titles = self::stage_titles();
        $mins = self::stage_min_tariff();

        if (!empty($row['paid_until']) && (int) $row['paid_until'] < time()) {
            return array('ok' => false, 'message' => 'Срок доступа закончился. Откройте тариф заново.',
                'need' => $tariff === 'free' ? 'project' : $tariff);
        }
        if (!self::allows($tariff, $stage)) {
            $need = $mins[$stage];
            return array('ok' => false, 'need' => $need,
                'message' => 'Шаг «' . $titles[$stage] . '» доступен в тарифе «' . $tariffs[$need][0] . '».');
        }
        // Бесплатный тариф — за счёт сайта, поэтому считаем ещё и по адресу:
        // иначе новый проект каждые три запроса обходит лимит целиком.
        if ($tariff === 'free' && self::free_left() <= 0) {
            return array('ok' => false, 'need' => 'project',
                'message' => 'Бесплатных запусков на сегодня больше нет. '
                    . 'Завтра снова будут, а тариф открывает все шаги сразу.');
        }
        $limit = self::limit_for($tariff);
        if ((int) $row['used'] >= $limit) {
            $order = self::tariff_order();
            $i = (int) array_search($tariff, $order, true);
            $next = $order[min($i + 1, count($order) - 1)];
            return array('ok' => false, 'need' => $next === $tariff ? null : $next,
                'message' => 'Переделки закончились: каждый шаг можно запускать до '
                    . self::TRIES_PER_STEP . ' раз. В старшем тарифе их больше.');
        }
        if (mb_strlen((string) $input) > self::MAX_INPUT) {
            return array('ok' => false,
                'message' => 'Слишком длинный ввод: до ' . self::MAX_INPUT . ' знаков.');
        }

        $answer = GS_Provider::chat_messages(array(
            array('role' => 'system', 'content' => gs_proekt_system()),
            array('role' => 'user',   'content' => self::build_message($stage, $row, $input)),
        ), array('max_tokens' => 8000));

        if (empty($answer['ok'])) {
            return array('ok' => false, 'message' => 'Наставник сейчас не отвечает. Попробуйте ещё раз через минуту.');
        }

        // Поставщик отдаёт ответ в ключе content.
        $text = trim((string) ($answer['content'] ?? ''));
        if ($text === '') {
            return array('ok' => false, 'message' => 'Наставник вернул пустой ответ. Попробуйте ещё раз.');
        }
        if ($tariff === 'free') {
            self::note_free();
        }
        $row['steps'][$stage] = array(
            'input'  => (string) $input,
            'output' => $text,
            'at'     => time(),
        );
        $row['used'] = (int) $row['used'] + 1;
        self::save($token, $row);

        return array('ok' => true, 'text' => $text, 'html' => class_exists('GS_Md') ? GS_Md::to_html($text) : '',
            'used' => (int) $row['used'], 'limit' => $limit);
    }

    /* ---------------------------------------------------------------------
     * Оплата
     * ------------------------------------------------------------------ */

    public static function label($token, $tariff) {
        return self::LABEL_PREFIX . self::token_clean($token) . '_' . preg_replace('~[^a-z]~', '', $tariff);
    }

    /**
     * Ссылка на оплату тарифа.
     *
     * Платим не с баланса сайта, а прямо за тариф: родитель приходит один
     * раз, и просить его сначала положить деньги на счёт, а потом списать
     * их — лишний шаг, на котором теряется половина. Тем более что кнопки
     * пополнения кратны пятистам: за «Проект» пришлось бы платить дважды.
     *
     * Ссылку собирает общий помощник — назначение платежа человек видит у
     * ЮMoney, и оно должно называть тариф, а не «пополнение баланса».
     * Метка несёт проект и тариф: по ней уведомление открывает шаги.
     *
     * Вход не требуется: школьник может работать по токену, а платить за
     * него будут с родительской карты, часто с другого устройства.
     */
    public static function pay_link($token, $tariff) {
        $row = self::project($token);
        $tariffs = self::tariffs();
        if (!$row || !isset($tariffs[$tariff]) || $tariff === 'free') {
            return array('ok' => false, 'message' => 'Проект или тариф не найден');
        }
        if (!class_exists('GS_Pay')) {
            return array('ok' => false, 'message' => 'Оплата временно недоступна');
        }

        // Срок вышел — это не переход на старший тариф, а покупка заново:
        // берём полную цену. Иначе доплата считалась бы нулевой и продлить
        // было бы нечем.
        $expired = !empty($row['paid_until']) && (int) $row['paid_until'] < time();
        $price = $expired
            ? (int) $tariffs[$tariff][1]
            : self::upgrade_price((string) $row['tariff'], $tariff);
        if ($price <= 0) {
            return array('ok' => false, 'message' => 'Этот тариф уже открыт');
        }

        $back = add_query_arg(
            array('token' => self::token_clean($token), 'paid' => 1),
            class_exists('GS_Proekt_Page') ? GS_Proekt_Page::url() : home_url('/proekt/')
        );
        $link = GS_Pay::link(
            self::label($token, $tariff),
            (float) $price,
            'Наставник по индивидуальному проекту: тариф «' . $tariffs[$tariff][0] . '»',
            $back
        );
        if ($link === '') {
            return array('ok' => false, 'message' => 'Оплата временно недоступна');
        }

        // Кто платит — тому и привязываем проект: вошедший найдёт работу с
        // любого устройства, гость останется на токене.
        self::remember_for_user(get_current_user_id(), $token);

        return array('ok' => true, 'link' => $link, 'price' => $price,
            'tariff' => $tariff, 'название' => $tariffs[$tariff][0]);
    }

    /* ---------------------------------------------------------------------
     * Выгрузка в Word
     * ------------------------------------------------------------------ */

    /**
     * Готовые шаги одним файлом.
     *
     * Отдаём .doc в виде HTML: Word и Google Документы открывают такой файл
     * и дают править, а собирать настоящий DOCX ради одного файла — значит
     * тащить библиотеку. Оформление сразу в типовых требованиях: Times New
     * Roman 14, полуторный интервал, выравнивание по ширине — чтобы ученик
     * не переделывал вручную то, что можно задать один раз.
     */
    public static function serve_doc($token) {
        $row = self::project($token);
        if (!$row) {
            status_header(404);
            exit('Проект не найден');
        }
        $titles = self::stage_titles();
        $body = '';
        foreach (self::stages() as $s) {
            $id = $s[0];
            $text = (string) ($row['steps'][$id]['output'] ?? '');
            if ($text === '') {
                continue;
            }
            $html = class_exists('GS_Md') ? GS_Md::to_html($text) : wpautop(esc_html($text));
            $body .= '<h2>' . esc_html($titles[$id]) . '</h2>' . $html
                . '<p style="page-break-after:always"></p>';
        }
        if ($body === '') {
            status_header(404);
            exit('В проекте пока нет готовых шагов');
        }

        $tema = trim((string) ($row['profile']['тема'] ?? ''));
        $name = 'individualnyy-proekt';

        nocache_headers();
        header('Content-Type: application/msword; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $name . '.doc"');
        echo "<html xmlns:o='urn:schemas-microsoft-com:office:office' "
            . "xmlns:w='urn:schemas-microsoft-com:office:word' xmlns='http://www.w3.org/TR/REC-html40'>";
        echo '<head><meta charset="utf-8"><title>Индивидуальный проект</title>';
        echo '<style>body{font-family:"Times New Roman",serif;font-size:14pt;line-height:1.5}'
            . 'h1,h2{font-size:14pt;font-weight:bold}p{margin:0 0 10pt;text-align:justify;text-indent:1.25cm}'
            . 'li{margin:0 0 6pt}table{border-collapse:collapse}td,th{border:1px solid #000;padding:4pt}'
            . '</style></head><body>';
        echo '<h1 style="text-align:center">Индивидуальный проект</h1>';
        if ($tema !== '') {
            echo '<p style="text-align:center;text-indent:0">' . esc_html($tema) . '</p>';
        }
        echo '<p style="text-indent:0;font-size:11pt">Черновик собран наставником genius-bot.ru. '
            . 'Перед сдачей подставьте свои данные там, где стоит пометка «вставь свои данные», '
            . 'и оформите титульный лист по образцу школы.</p>';
        echo '<p style="page-break-after:always"></p>';
        echo $body;
        echo '</body></html>';
        exit;
    }

    /** Уведомление ЮMoney: открываем тариф и продлеваем срок. */
    public static function paid($label) {
        $rest = substr((string) $label, strlen(self::LABEL_PREFIX));
        $at = strrpos($rest, '_');
        if ($at === false) {
            return array('ok' => false, 'message' => 'метка без тарифа');
        }
        $token = substr($rest, 0, $at);
        $tariff = substr($rest, $at + 1);

        $row = self::project($token);
        $tariffs = self::tariffs();
        if (!$row || !isset($tariffs[$tariff])) {
            return array('ok' => false, 'message' => 'проект или тариф не найден');
        }
        $order = self::tariff_order();
        $expired = !empty($row['paid_until']) && (int) $row['paid_until'] < time();
        // Повторное уведомление о том же тарифе ничего не меняет. Но если
        // срок вышел, та же ступень означает покупку заново — её открываем.
        if (!$expired
            && array_search($tariff, $order, true) <= array_search((string) $row['tariff'], $order, true)) {
            return array('ok' => true, 'message' => 'тариф уже открыт — повторное уведомление');
        }

        $row['tariff'] = $tariff;
        $row['paid_until'] = self::season_end();
        $row['paid_at'] = time();
        if ($expired) {
            $row['used'] = 0;
        }
        self::save($token, $row);
        return array('ok' => true, 'message' => 'открыт тариф «' . $tariffs[$tariff][0]
            . '» до ' . wp_date('d.m.Y', $row['paid_until']));
    }
}
