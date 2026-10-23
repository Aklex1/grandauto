<?php
/**
 * Проверки правил доступа и оплаты курсов.
 *
 * Запуск: php wp-plugins/genius-course/tests-course.php
 *
 * Без WordPress: нужные его функции подменяются здесь же. Проверяем то,
 * что дороже всего сломать, — кому какой модуль открыт и что делает
 * уведомление об оплате. Разметку и запросы к базе не трогаем.
 */

define('ABSPATH', 1);
define('DAY_IN_SECONDS', 86400);

$GLOBALS['meta'] = array();
$GLOBALS['users'] = array();
$GLOBALS['caps'] = array();
$GLOBALS['mail'] = array();

function get_user_meta($id, $key, $single = false) {
    return $GLOBALS['meta'][(int) $id][$key] ?? '';
}
function update_user_meta($id, $key, $value) {
    $GLOBALS['meta'][(int) $id][$key] = $value;
    return true;
}
function delete_user_meta($id, $key) {
    unset($GLOBALS['meta'][(int) $id][$key]);
    return true;
}
function user_can($id, $cap) {
    return !empty($GLOBALS['caps'][(int) $id][$cap]);
}
function get_user_by($field, $value) {
    return $GLOBALS['users'][(int) $value] ?? false;
}
function get_current_user_id() { return 0; }
function is_admin() { return true; }
function current_time($type = 'mysql') { return date('Y-m-d H:i:s'); }
function sanitize_key($k) { return preg_replace('~[^a-z0-9_\-]~', '', strtolower((string) $k)); }
function sanitize_text_field($t) { return trim(strip_tags((string) $t)); }
function home_url($path = '/') { return 'https://example.test' . $path; }
function admin_url($path = '') { return 'https://example.test/wp-admin/' . $path; }
function add_query_arg($key, $value, $url) { return $url . (strpos($url, '?') === false ? '?' : '&') . $key . '=' . $value; }
function wp_mail($to, $subject, $body) { $GLOBALS['mail'][] = array($to, $subject, $body); return true; }
function get_option($k, $d = null) { return $d; }
function number_format_i18n($n) { return number_format((float) $n, 0, ',', ' '); }

/** Кабинет и построитель ссылок — чужие классы, здесь нужны только адреса. */
class GC_Cabinet {
    public static function url($course = '') { return 'https://example.test/kab-' . $course . '/'; }
    public static function course_of_page() { return ''; }
}
class GS_Pay {
    public static $last = array();
    public static function link($label, $amount, $target, $success = '') {
        self::$last = compact('label', 'amount', 'target', 'success');
        return 'https://yoomoney.ru/quickpay/confirm.xml?label=' . rawurlencode($label);
    }
}

$base = __DIR__ . '/includes/';
require $base . 'class-gc-courses.php';
require $base . 'class-gc-program.php';
require $base . 'class-gc-program-money.php';
require $base . 'class-gc-students.php';
require $base . 'class-gc-pay.php';

$fail = 0;
function check($what, $got, $want) {
    global $fail;
    $ok = $got === $want;
    if (!$ok) { $fail++; }
    printf("  %s %-56s %s\n", $ok ? 'ок  ' : 'ПЛОХО', $what,
        $ok ? '' : ('получили ' . var_export($got, true) . ', ждали ' . var_export($want, true)));
}

/* Ученики: 10 — курс заработка, 11 — AI-креатор, 12 — админ, 13 — без метки. */
foreach (array(10, 11, 12, 13) as $id) {
    $u = new stdClass();
    $u->ID = $id;
    $u->user_email = 'u' . $id . '@example.test';
    $u->display_name = 'Ученик ' . $id;
    $GLOBALS['users'][$id] = $u;
}
GC_Students::set_course(10, 'money');
GC_Students::set_course(11, 'kreator');
$GLOBALS['caps'][12]['manage_options'] = true;
GC_Students::set_course(12, 'money');

echo "=== курсы ===\n";
check('курсов два',                                 count(GC_Courses::keys()), 2);
check('курс по умолчанию — AI-креатор',             GC_Courses::DEFAULT_KEY, 'kreator');
check('у заработка есть платный барьер',            GC_Courses::paywall('money'), true);
check('у AI-креатора барьера нет',                  GC_Courses::paywall('kreator'), false);
check('бесплатный модуль один',                     GC_Courses::free_modules('money'), 1);
check('курс ученика читается из метки',             GC_Students::course(10), 'money');
check('ученик без метки попадает в курс по умолчанию', GC_Students::course(13), 'kreator');
check('неизвестный курс не проходит',               GC_Courses::exists('podcast'), false);
check('у заработка два тарифа',                     count(GC_Courses::plans('money')), 2);
check('базовый стоит 22 900',                       (int) GC_Courses::plan('money', 'base')['price'], 22900);
check('с кампанией — 31 900',                       (int) GC_Courses::plan('money', 'plus')['price'], 31900);

echo "\n=== программы ===\n";
$money = GC_Program::modules('money');
$kreator = GC_Program::modules('kreator');
check('в заработке восемь модулей',                 count($money), 8);
check('в AI-креаторе восемь модулей',               count($kreator), 8);
check('программы разные',                           $money[0]['title'] === $kreator[0]['title'], false);
check('count знает курс',                           GC_Program::count('money'), 8);
check('module отдаёт модуль курса',                 GC_Program::module(0, 'money')['title'], $money[0]['title']);
check('за границей программы ничего нет',           GC_Program::module(8, 'money'), null);
check('первый модуль — про рынок',                  $money[0]['title'], 'Что покупают и за сколько');
check('последний — про заказы',                      $money[7]['title'], 'Первые заказы и поток');
$bad = array();
foreach ($money as $i => $m) {
    foreach (array('title', 'lead', 'goal', 'lessons', 'task', 'deliver', 'hint') as $key) {
        if (empty($m[$key])) { $bad[] = $i . ':' . $key; }
    }
    if (count($m['lessons']) !== 3) { $bad[] = $i . ':уроков ' . count($m['lessons']); }
    foreach ($m['lessons'] as $n => $lesson) {
        if (count($lesson) !== 2 || $lesson[0] === '' || $lesson[1] === '') { $bad[] = $i . ':урок' . $n; }
    }
}
check('все поля модулей заполнены',                 $bad, array());

echo "\n=== доступ до оплаты ===\n";
check('первый модуль открыт сразу',                 GC_Courses::module_open(10, 0, 'money'), true);
check('второй закрыт',                              GC_Courses::module_open(10, 1, 'money'), false);
check('последний закрыт',                           GC_Courses::module_open(10, 7, 'money'), false);
check('мастерская закрыта',                         GC_Courses::tools_open(10, 'money'), false);
check('курс не отмечен оплаченным',                 GC_Courses::is_paid(10, 'money'), false);
check('на курсе без барьера открыто всё',           GC_Courses::module_open(11, 7, 'kreator'), true);
check('и мастерская тоже',                          GC_Courses::tools_open(11, 'kreator'), true);
check('администратору открыто всё',                 GC_Courses::module_open(12, 7, 'money'), true);
check('курс берётся из метки, если не передан',     GC_Courses::module_open(10, 1), false);

echo "\n=== метка платежа ===\n";
$label = GC_Pay::label(10, 'money', 'base');
check('метка собрана',                              $label, 'gckurs_10_money_base');
check('ссылка содержит метку',                      strpos(GC_Pay::link(10, 'money', 'base'), rawurlencode($label)) !== false, true);
check('сумма в ссылке — цена тарифа',               (float) GS_Pay::$last['amount'], 22900.0);
check('назначение человеческое',                    GS_Pay::$last['target'], 'Обучение «Заработок на нейросетях», тариф «Базовый»');
check('возврат ведёт в кабинет курса',              GS_Pay::$last['success'], 'https://example.test/kab-money/?paid=1');
check('несуществующий тариф ссылки не даёт',        GC_Pay::link(10, 'money', 'gold'), '');

echo "\n=== уведомление об оплате ===\n";
check('чужая метка отбита',                         GC_Pay::paid('topup_wp_777')['ok'], false);
check('метка без тарифа отбита',                    GC_Pay::paid('gckurs_10')['ok'], false);
check('неизвестный ученик отбит',                   GC_Pay::paid('gckurs_999_money_base')['ok'], false);
check('неизвестный тариф отбит',                    GC_Pay::paid('gckurs_10_money_gold')['ok'], false);
check('модули до оплаты закрыты',                   GC_Courses::module_open(10, 3, 'money'), false);
$paid = GC_Pay::paid($label);
check('оплата принята',                             $paid['ok'], true);
check('тариф записан',                              GC_Students::paid_plan(10), 'base');
check('все модули открылись',                       GC_Courses::module_open(10, 7, 'money'), true);
check('мастерская открылась',                       GC_Courses::tools_open(10, 'money'), true);
check('дата оплаты записана',                       GC_Students::paid_at(10) !== '', true);
check('письмо о продаже ушло',                      count($GLOBALS['mail']), 1);
$GLOBALS['meta'][10][GC_Students::META_PAID_AT] = '2020-01-01 00:00:00';
$again = GC_Pay::paid($label);
check('повтор уведомления принят',                  $again['ok'], true);
check('и назван повтором',                          $again['message'], 'доступ уже открыт — повторное уведомление');
check('дату оплаты повтор не переписал',            GC_Students::paid_at(10), '2020-01-01 00:00:00');
check('второго письма не было',                     count($GLOBALS['mail']), 1);

echo "\n=== оплата поверх другого курса ===\n";
GC_Students::set_course(11, 'kreator');
GC_Students::clear_paid(11);
$cross = GC_Pay::paid(GC_Pay::label(11, 'money', 'plus'));
check('платёж принят',                              $cross['ok'], true);
check('курс выправлен по метке',                    GC_Students::course(11), 'money');
check('открыт оплаченный тариф',                    GC_Students::paid_plan(11), 'plus');

echo "\n=== снятие оплаты ===\n";
GC_Students::clear_paid(10);
check('тариф снят',                                 GC_Students::paid_plan(10), '');
check('дата оплаты снята',                          GC_Students::paid_at(10), '');
check('остался только первый модуль',               GC_Courses::module_open(10, 1, 'money'), false);
check('первый всё ещё открыт',                      GC_Courses::module_open(10, 0, 'money'), true);

printf("\n%s\n", $fail === 0 ? 'Все проверки прошли.' : ('Провалено проверок: ' . $fail));
exit($fail === 0 ? 0 : 1);
