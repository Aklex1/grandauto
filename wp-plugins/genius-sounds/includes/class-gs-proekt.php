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
    const LABEL_PREFIX = 'proekt_';
    const COOKIE = 'gs_proekt';

    /** До скольки знаков принимаем ввод ученика: дальше это уже не ввод, а работа целиком. */
    const MAX_INPUT = 15000;

    /** Сколько знаков прошлых шагов отдаём модели (примерно четыре знака на токен). */
    const CONTEXT_BUDGET = 60000;

    /** Сколько живёт проект без оплаты. */
    const FREE_TTL = 604800;   // 7 дней

    /**
     * Шаги: идентификатор, название, минимальный тариф.
     * Порядок важен — на нём держится сборка контекста.
     */
    public static function stages() {
        return array(
            array('tema',          '1. Тема',                    'free'),
            array('pasport',       '2. Паспорт проекта',         'start'),
            array('plan',          '3. План и график',           'start'),
            array('istochniki',    '4. Источники',               'start'),
            array('teoriya',       '5. Теоретическая глава',     'project'),
            array('praktika',      '6. Практическая часть',      'project'),
            array('vvedenie_zakl', '7. Введение и заключение',   'project'),
            array('oformlenie',    '8. Оформление и проверка',   'project'),
            array('prezentacia',   '9. Презентация',             'defense'),
            array('rech',          '10. Защитная речь',          'defense'),
            array('voprosy',       '11. Вопросы комиссии',       'defense'),
            array('proverka',      'Проверка моего текста',      'start'),
        );
    }

    /** Тариф: название, цена, лимит запросов, доступ в днях. */
    public static function tariffs() {
        return array(
            'free'    => array('Бесплатно',        0,    3,   7),
            'start'   => array('Старт',            490,  15,  30),
            'project' => array('Проект',           990,  50,  60),
            'defense' => array('Проект + защита',  1490, 100, 90),
        );
    }

    public static function tariff_order() {
        return array('free', 'start', 'project', 'defense');
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

    public static function token_clean($token) {
        return preg_replace('~[^A-Za-z0-9]~', '', (string) $token);
    }

    public static function project($token) {
        $token = self::token_clean($token);
        if ($token === '') {
            return null;
        }
        $row = get_option(self::OPT_PREFIX . $token, null);
        return is_array($row) ? $row : null;
    }

    private static function save($token, $row) {
        update_option(self::OPT_PREFIX . self::token_clean($token), $row, false);
    }

    public static function create($profile) {
        $token = wp_generate_password(24, false, false);
        self::save($token, array(
            'token'    => $token,
            'profile'  => (array) $profile,
            'tariff'   => 'free',
            'paid_until' => time() + self::FREE_TTL,
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
            return array('ok' => false, 'message' => 'Срок доступа закончился. Продлите тариф.',
                'need' => $tariff === 'free' ? 'start' : $tariff);
        }
        if (!self::allows($tariff, $stage)) {
            $need = $mins[$stage];
            return array('ok' => false, 'need' => $need,
                'message' => 'Шаг «' . $titles[$stage] . '» доступен в тарифе «' . $tariffs[$need][0] . '».');
        }
        $limit = (int) $tariffs[$tariff][2];
        if ((int) $row['used'] >= $limit) {
            $order = self::tariff_order();
            $i = (int) array_search($tariff, $order, true);
            $next = $order[min($i + 1, count($order) - 1)];
            return array('ok' => false, 'need' => $next === $tariff ? null : $next,
                'message' => 'Лимит запросов тарифа исчерпан (' . $limit . ').');
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
     * Платит обычно родитель, а не ученик, поэтому назначение платежа
     * должно быть понятно человеку, который открыл кошелёк и видит только
     * строку списания: называем тариф и номер проекта.
     */
    public static function pay_link($token, $tariff, $return_url) {
        $row = self::project($token);
        $tariffs = self::tariffs();
        if (!$row || !isset($tariffs[$tariff]) || !class_exists('GS_Pay')) {
            return '';
        }
        $price = self::upgrade_price((string) $row['tariff'], $tariff);
        if ($price <= 0) {
            return '';
        }
        $target = 'Наставник по индивидуальному проекту, тариф «' . $tariffs[$tariff][0]
            . '» (проект ' . self::token_clean($token) . ')';
        return GS_Pay::link(self::label($token, $tariff), (float) $price, $target, $return_url);
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
        if (array_search($tariff, $order, true) <= array_search((string) $row['tariff'], $order, true)) {
            return array('ok' => true, 'message' => 'тариф уже открыт — повторное уведомление');
        }

        $row['tariff'] = $tariff;
        $row['paid_until'] = time() + ((int) $tariffs[$tariff][3] * 86400);
        $row['paid_at'] = time();
        self::save($token, $row);
        return array('ok' => true, 'message' => 'открыт тариф «' . $tariffs[$tariff][0] . '»');
    }
}
