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

// Только из консоли: файл попадает на хост вместе с плагином, и по
// адресу в браузере ему отвечать нечего.
if (PHP_SAPI !== 'cli') {
    header('HTTP/1.0 404 Not Found');
    exit;
}

define('ABSPATH', 1);
define('DAY_IN_SECONDS', 86400);
define('GC_PATH', __DIR__ . '/');

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
function wp_strip_all_tags($t) { return trim(strip_tags((string) $t)); }
function sanitize_text_field($t) { return trim(strip_tags((string) $t)); }
function home_url($path = '/') { return 'https://example.test' . $path; }
function admin_url($path = '') { return 'https://example.test/wp-admin/' . $path; }
function add_query_arg($key, $value, $url) { return $url . (strpos($url, '?') === false ? '?' : '&') . $key . '=' . $value; }
function wp_mail($to, $subject, $body) { $GLOBALS['mail'][] = array($to, $subject, $body); return true; }
$GLOBALS['opts'] = array();
function get_option($k, $d = null) { return array_key_exists($k, $GLOBALS['opts']) ? $GLOBALS['opts'][$k] : $d; }
function update_option($k, $v, $a = true) { $GLOBALS['opts'][$k] = $v; return true; }
function get_permalink($id) { return 'https://example.test/?p=' . (int) $id; }
function wp_parse_args($args, $defaults = array()) { return array_merge($defaults, (array) $args); }
function number_format_i18n($n) { return number_format((float) $n, 0, ',', ' '); }
function esc_html($t) { return htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8'); }
function esc_url($t) { return (string) $t; }
function esc_attr($t) { return htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8'); }
function wp_lostpassword_url($to = '') { return 'https://example.test/wp-login.php?action=lostpassword'; }
function get_bloginfo($what = 'name') { return 'Genius-bot'; }
function date_i18n($f, $ts = null) { return date($f, $ts === null ? time() : $ts); }
function is_email($mail) { return (bool) filter_var((string) $mail, FILTER_VALIDATE_EMAIL); }
function sanitize_email($mail) { return (string) $mail; }

/** Мастерская: в проверках пакета нужен только её двигатель. */
class GC_Gpu {
    public static function engine($user_id = 0, $course = '') { return 'kie'; }
}

/** Построитель ссылок на оплату — чужой класс, здесь нужен только адрес. */
class GS_Pay {
    public static $last = array();
    public static function link($label, $amount, $target, $success = '') {
        self::$last = compact('label', 'amount', 'target', 'success');
        return 'https://yoomoney.ru/quickpay/confirm.xml?label=' . rawurlencode($label);
    }
}

$base = __DIR__ . '/includes/';
require $base . 'class-gc-cabinet.php';
require $base . 'class-gc-kie.php';
require $base . 'class-gc-courses.php';
require $base . 'class-gc-program.php';
require $base . 'class-gc-program-money.php';
require $base . 'class-gc-students.php';
require $base . 'class-gc-pay.php';
require $base . 'class-gc-mail.php';
require $base . 'class-gc-pack.php';
require $base . 'class-gc-contact.php';

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
    $u->user_login = 'student' . $id;
    // У ученика, которого завели без имени, WordPress показывает логин:
    // письмо не должно здороваться с ним по логину.
    $u->display_name = $id === 13 ? 'student13' : 'Ученик ' . $id;
    $u->first_name = $id === 13 ? '' : 'Денис';
    $GLOBALS['users'][$id] = $u;
}
update_option('gc_cabinet_page', 7285);
update_option('gc_cabinet_money_page', 7542);
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
    if (count($m['lessons']) !== 5) { $bad[] = $i . ':уроков ' . count($m['lessons']); }
    foreach ($m['lessons'] as $n => $lesson) {
        if (count($lesson) !== 2 || $lesson[0] === '' || $lesson[1] === '') { $bad[] = $i . ':урок' . $n; }
    }
}
check('все поля модулей заполнены',                 $bad, array());

$kinds = array();
$bad = array();
$keys = array();
foreach ($money as $i => $m) {
    if (empty($m['examples'])) { $bad[] = $i . ':нет примеров'; continue; }
    foreach ($m['examples'] as $ex) {
        foreach (array('key', 'kind', 'title', 'note', 'prompt') as $field) {
            if (empty($ex[$field])) { $bad[] = $i . ':' . $field; }
        }
        $kind = (string) ($ex['kind'] ?? '');
        $kinds[$kind] = ($kinds[$kind] ?? 0) + 1;
        if (in_array($kind, array('image', 'video'), true) && empty($ex['size'])) {
            $bad[] = $i . ':' . $ex['key'] . ' без размера';
        }
        $pair = $i . '/' . ($ex['key'] ?? '');
        if (in_array($pair, $keys, true)) { $bad[] = 'повтор ключа ' . $pair; }
        $keys[] = $pair;
    }
}
check('примеры описаны у всех модулей',            $bad, array());
check('примеров всего',                            count($keys), 20);
check('кадров',                                    (int) ($kinds['image'] ?? 0), 7);
check('роликов',                                   (int) ($kinds['video'] ?? 0), 1);
check('текстов',                                   (int) ($kinds['text'] ?? 0), 12);

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
check('возврат ведёт в кабинет курса',              GS_Pay::$last['success'], 'https://example.test/?p=7542&paid=1');
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

echo "\n=== мастерская: чем считать ===\n";
check('у заработка мастерская на шлюзе',            GC_Courses::field('money', 'studio', ''), 'kie');
check('у AI-креатора — на машине',                  GC_Courses::field('kreator', 'studio', ''), 'gpu');
check('кадр без референса',                         GC_Kie::capability('image', false), 'image');
check('кадр по референсу — правка',                 GC_Kie::capability('image', true), 'image_edit');
check('ролик по описанию',                          GC_Kie::capability('video', false), 'video');
check('ролик из фотографии — оживление',            GC_Kie::capability('video', true), 'photo_video');
check('квадрат',                                    GC_Kie::ratio(array('width' => 1024, 'height' => 1024)), '1:1');
check('сторис',                                     GC_Kie::ratio(array('width' => 1080, 'height' => 1920)), '9:16');
check('горизонтальный',                             GC_Kie::ratio(array('width' => 1920, 'height' => 1080)), '16:9');
check('книжный',                                    GC_Kie::ratio(array('width' => 1080, 'height' => 1440)), '3:4');
check('альбомный',                                  GC_Kie::ratio(array('width' => 1440, 'height' => 1080)), '4:3');
check('без размера — квадрат',                      GC_Kie::ratio(array()), '1:1');
check('ролики на шлюзе есть всегда',                GC_Kie::video_allowed(), true);

echo "\n=== кабинеты и карта сайта ===\n";
$pages = array();
foreach (array(7285, 7542, 9001) as $id) {
    $page = new stdClass();
    $page->ID = $id;
    $pages[] = $page;
}
unset($_GET['sitemap'], $_SERVER['REQUEST_URI']);
check('без запроса карты список не трогаем',        count(GC_Cabinet::hide_from_sitemap($pages)), 3);
$_SERVER['REQUEST_URI'] = '/sitemap.xml';
$left = GC_Cabinet::hide_from_sitemap($pages);
check('на карте кабинетов не осталось',             count($left), 1);
check('и это не кабинет',                           (int) $left[0]->ID, 9001);
unset($_SERVER['REQUEST_URI']);
$_GET['sitemap'] = 'xml';
check('адрес вида ?sitemap=xml тоже ловим',         count(GC_Cabinet::hide_from_sitemap($pages)), 1);
unset($_GET['sitemap']);
check('адрес кабинета заработка',                   GC_Cabinet::url('money'), 'https://example.test/?p=7542');
check('адрес кабинета AI-креатора',                 GC_Cabinet::url('kreator'), 'https://example.test/?p=7285');

echo "\n=== письмо с доступом ===\n";
GC_Students::clear_paid(10);
$letter = GC_Mail::render($GLOBALS['users'][10], 'aBcD1234', 'money');
check('шаблон нашёлся',                             $letter !== '', true);
check('ни одной незаполненной метки',               preg_match('~\{\{\w+\}\}~', $letter), 0);
check('логин в письме',                             strpos($letter, 'student10') !== false, true);
check('пароль в письме',                            strpos($letter, 'aBcD1234') !== false, true);
check('адрес кабинета в письме',                    strpos($letter, 'https://example.test/?p=7542') !== false, true);
check('название курса',                             strpos($letter, 'Заработок на нейросетях') !== false, true);
check('обращение по имени',                         strpos($letter, 'Денис, здравствуйте!') !== false, true);
check('про оплату сказано',                         strpos($letter, 'после оплаты') !== false, true);
GC_Students::set_paid(10, 'base');
$paid_letter = GC_Mail::render($GLOBALS['users'][10], 'aBcD1234', 'money');
check('оплатившему про оплату не пишем',            strpos($paid_letter, 'после оплаты'), false);
check('оплатившему — про полный доступ',            strpos($paid_letter, 'Доступ открыт полностью') !== false, true);
GC_Students::clear_paid(10);
$nameless = GC_Mail::render($GLOBALS['users'][13], 'aBcD1234', 'kreator');
check('без имени — просто «Здравствуйте!»',         strpos($nameless, 'Здравствуйте!') !== false, true);
check('и без запятой перед ним',                    strpos($nameless, ', здравствуйте!'), false);
check('у курса без оплаты свои шаги',               strpos($nameless, 'Сдайте первую работу') !== false, true);

echo "\n=== посещения кабинета ===\n";
GC_Students::touch(10, 2);
$been = GC_Students::visits(10);
check('первый заход записан',                       $been['visits'], 1);
check('и день тоже',                                $been['days'], 1);
check('запомнили открытый модуль',                  $been['module'], 2);
check('первый вход проставлен',                     $been['first'] !== '', true);
GC_Students::touch(10, 3);
$been = GC_Students::visits(10);
check('через минуту заход не удваивается',          $been['visits'], 1);
check('но модуль обновился',                        $been['module'], 3);
check('и день не задвоился',                        $been['days'], 1);
// Перерыв больше получаса — это уже следующий заход.
update_user_meta(10, GC_Students::META_SEEN, time() - 2 * GC_Students::VISIT_GAP);
GC_Students::touch(10, 0);
check('после перерыва заход новый',                 GC_Students::visits(10)['visits'], 2);
GC_Students::touch(12, 5);
check('администратора не считаем',                  GC_Students::visits(12)['visits'], 0);
check('у того, кто не заходил, пусто',              GC_Students::visits(11)['seen'], 0);

echo "\n=== пакет генераций ===\n";
GC_Students::set_paid(10, 'base');
check('в базовом тарифе 600 кадров',               GC_Pack::plan_pack('base')['images'], 600);
check('и 120 единиц роликов',                      GC_Pack::plan_pack('base')['videos'], 120);
check('в расширенном 1500 кадров',                 GC_Pack::plan_pack('plus')['images'], 1500);
check('и 180 единиц роликов',                      GC_Pack::plan_pack('plus')['videos'], 180);
check('пакетов на докупку три',                    count(GC_Pack::extras()), 3);

// Правило владельца: пакет не дороже трети тарифа. Кадр — 4 кредита,
// единица ролика — 60; кредит стоит 0,5 ₽, и худший случай считаем по
// самой дорогой ступени (четыре единицы за 250 кредитов).
$cost = function ($plan) {
    $pack = GC_Pack::plan_pack($plan);
    return $pack['images'] * 4 * 0.5 + $pack['videos'] * 62.5 * 0.5;
};
check('базовый пакет укладывается в треть',        $cost('base') <= 22900 * 0.3, true);
check('расширенный тоже',                          $cost('plus') <= 31900 * 0.3, true);
foreach (GC_Pack::extras() as $key => $extra) {
    $spend = $extra['images'] * 2 + $extra['videos'] * 31.25;
    check('докупка «' . $key . '» дороже себестоимости втрое', $spend <= $extra['price'] * 0.4, true);
}

echo "\n=== ступени роликов ===\n";
check('ступеней три',                              count(GC_Kie::tiers()), 3);
check('по умолчанию обычная',                      GC_Kie::TIER_DEFAULT, 'base');
check('короткий ролик — единица',                  GC_Kie::cost('video', 'fast'), 1);
check('обычный — две',                             GC_Kie::cost('video', 'base'), 2);
check('максимум — четыре',                         GC_Kie::cost('video', 'max'), 4);
check('кадр всегда единица',                       GC_Kie::cost('image', 'max'), 1);
check('неизвестная ступень — обычная',             GC_Kie::tier_of(array('tier' => 'gold')), 'base');
check('ступень из параметров читается',            GC_Kie::tier_of(array('tier' => 'max')), 'max');
check('возможность для короткого',                 GC_Kie::capability('video', false, 'fast'), 'video_fast');
check('для максимума',                             GC_Kie::capability('video', true, 'max'), 'video_max');
check('обычный по описанию',                       GC_Kie::capability('video', false, 'base'), 'video');
check('обычный по фотографии',                     GC_Kie::capability('video', true, 'base'), 'photo_video');
check('кадр с референсом — правка',                GC_Kie::capability('image', true, ''), 'image_edit');

echo "\n=== пакет генераций ===\n";

GC_Pack::grant_plan(10, 'base', 'gckurs_10_money_base');
$balance = GC_Pack::balance(10);
check('тарифный пакет начислен',                   $balance['image'], 600);
check('единицы роликов тоже',                      $balance['video'], 120);
check('повтор по той же метке не удваивает',       GC_Pack::grant_plan(10, 'base', 'gckurs_10_money_base'), false);
check('и остаток прежний',                         GC_Pack::balance(10)['image'], 600);

GC_Pack::spend(10, 'image');
GC_Pack::spend(10, 'video', GC_Kie::cost('video', 'base'));
check('кадр списан',                               GC_Pack::balance(10)['image'], 599);
check('обычный ролик списал две единицы',          GC_Pack::balance(10)['video'], 118);
GC_Pack::spend(10, 'video', GC_Kie::cost('video', 'max'));
check('максимум списал четыре',                    GC_Pack::balance(10)['video'], 114);
GC_Pack::refund(10, 'video', GC_Kie::cost('video', 'max'));
check('неудачный ролик вернулся целиком',          GC_Pack::balance(10)['video'], 118);
GC_Pack::refund(10, 'video', GC_Kie::cost('video', 'base'));
check('и обычный тоже',                            GC_Pack::balance(10)['video'], 120);
check('админу ничего не списываем',                (function () { GC_Pack::spend(12, 'image'); return GC_Pack::balance(12)['image']; })(), 0);

check('генерация разрешена',                       GC_Pack::allows(10, 'image')['ok'], true);
$GLOBALS['meta'][10][GC_Pack::META] = array('image' => 0, 'video' => 3);
check('кадры кончились — отказ',                   GC_Pack::allows(10, 'image')['ok'], false);
check('и сказано, где докупить',                   strpos(GC_Pack::allows(10, 'image')['message'], 'мастерской') !== false, true);
check('ролики ещё есть',                           GC_Pack::allows(10, 'video')['ok'], true);
check('на обычный ролик трёх единиц хватает',      GC_Pack::allows(10, 'video', '', 2)['ok'], true);
check('на максимум — уже нет',                     GC_Pack::allows(10, 'video', '', 4)['ok'], false);
check('и сказано, сколько нужно',                  strpos(GC_Pack::allows(10, 'video', '', 4)['message'], '4 единиц') !== false, true);

echo "\n=== докупка генераций ===\n";
$label = GC_Pay::pack_label(10, 'work');
check('метка пакета собрана',                      strpos($label, 'gcpack_10_work_') === 0, true);
$out = GC_Pay::pack_paid($label);
check('пакет зачислен',                            $out['ok'], true);
check('кадры добавились',                          GC_Pack::balance(10)['image'], 80);
check('единицы роликов добавились',                GC_Pack::balance(10)['video'], 9);
$again = GC_Pay::pack_paid($label);
check('повтор уведомления не удваивает',           $again['message'], 'пакет уже начислен — повторное уведомление');
check('остаток тот же',                            GC_Pack::balance(10)['image'], 80);
check('чужая метка отбита',                        GC_Pay::pack_paid('topup_wp_7')['ok'], false);
check('неизвестный пакет отбит',                   GC_Pay::pack_paid('gcpack_10_gold_1')['ok'], false);
check('срок пакета проставлен',                    GC_Pack::until(10) > time(), true);


echo "\n=== вопрос из кабинета ===\n";

/** Заглушка заявок: ловим то, что ушло бы в телеграм. */
class GS_Leads {
    public static $last = null;
    public static $ok = true;
    public static function notify($entry) {
        self::$last = $entry;
        return self::$ok
            ? array('ok' => true, 'message' => '', 'how' => 'напрямую')
            : array('ok' => false, 'message' => 'телеграм молчит');
    }
}

$GLOBALS['opts'][GC_Contact::OPT_LOG] = array();
$GLOBALS['users'][20] = (object) array(
    'ID' => 20, 'display_name' => 'Ерасыл Муканов', 'user_login' => 'erasyl',
    'user_email' => 'erasyl@example.test',
);
$GLOBALS['meta'][20][GC_Students::META_COURSE] = 'money';

check('пустой вопрос не уходит',                   GC_Contact::send(20, 'curator', 'ой')['ok'], false);
check('и в журнал не попал',                       count(GC_Contact::log(10)), 0);

$out = GC_Contact::send(20, 'curator', 'В модуле 3 не держится лицо на кадре, что поправить?', 'erasyl_tg');
check('вопрос принят',                             $out['ok'], true);
check('ученику сказали про ответ',                 strpos($out['message'], 'куратору') !== false, true);
check('ушло в телеграм',                           GS_Leads::$last !== null, true);
check('в сообщении есть метка ученика',            strpos(GS_Leads::$last['name'], '#u20') !== false, true);
check('и имя с логином',                           strpos(GS_Leads::$last['name'], 'erasyl') !== false, true);
check('контакт — почта и ник',                     GS_Leads::$last['contact'], 'erasyl@example.test, @erasyl_tg');
check('ник запомнили',                             GC_Contact::telegram_of(20), '@erasyl_tg');
check('откуда — курс и адресат',                   strpos(GS_Leads::$last['source'], 'куратору') !== false, true);
check('вопрос в журнале',                          count(GC_Contact::log(10)), 1);
check('журнал помнит, что ушло',                   GC_Contact::log(10)[0]['sent'], true);

check('второй вопрос подряд отбит',                GC_Contact::send(20, 'support', 'А ещё не считается баланс')['ok'], false);

// Молчащий телеграм не теряет вопрос: он остаётся в журнале.
$GLOBALS['meta'][20]['gc_message_at'] = time() - 600;
GS_Leads::$ok = false;
$out = GC_Contact::send(20, 'support', 'Оплатил пакет, а кадры не запускаются');
check('при обрыве связи вопрос всё равно принят',  $out['ok'], true);
check('в поддержку — свой ответ',                  strpos($out['message'], 'поддержку') !== false, true);
check('в журнале помечен как не ушедший',          GC_Contact::log(10)[0]['sent'], false);
check('причина записана',                          GC_Contact::log(10)[0]['error'], 'телеграм молчит');
check('за сутки видно оба вопроса',                GC_Contact::waiting(), 2);
check('неизвестный адресат — куратор',              GC_Contact::kind_title('nobody'), 'куратору');

printf("\n%s\n", $fail === 0 ? 'Все проверки прошли.' : ('Провалено проверок: ' . $fail));
exit($fail === 0 ? 0 : 1);
