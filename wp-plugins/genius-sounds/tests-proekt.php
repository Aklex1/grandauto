<?php
define('ABSPATH', 1);
$GLOBALS['opts'] = array();
function get_option($k, $d = null) { return $GLOBALS['opts'][$k] ?? $d; }
function update_option($k, $v, $a = true) { $GLOBALS['opts'][$k] = $v; return true; }
function wp_generate_password($n, $s = true, $x = true) { return substr(str_repeat('a1b2c3d4', 4), 0, $n); }
function get_current_user_id() { return 7; }
function date_i18n($f) { return date($f); }
class GS_Md { public static function to_html($t) { return "<p>" . $t . "</p>"; } }
class GS_Provider {
    public static $reply = array('ok' => true, 'content' => "## Ответ\nтекст наставника");
    public static $last;
    public static function chat_messages($m, $o = array()) { self::$last = $m; return self::$reply; }
}
require '/home/user/grandauto/wp-plugins/genius-sounds/includes/proekt-prompts.php';
require '/home/user/grandauto/wp-plugins/genius-sounds/includes/class-gs-proekt.php';

$fail = 0;
function check($what, $got, $want) {
    global $fail;
    $ok = $got === $want;
    if (!$ok) { $fail++; }
    printf("  %s %-52s %s\n", $ok ? 'ок  ' : 'ПЛОХО', $what,
        $ok ? '' : ('получили ' . var_export($got, true) . ', ждали ' . var_export($want, true)));
}

echo "=== доступ по тарифам ===\n";
check('бесплатный тариф пускает на «Тема»',        GS_Proekt::allows('free', 'tema'), true);
check('бесплатный не пускает на «Паспорт»',        GS_Proekt::allows('free', 'pasport'), false);
check('«Старт» не пускает на теорию',              GS_Proekt::allows('start', 'teoriya'), false);
check('«Проект» пускает на теорию',                GS_Proekt::allows('project', 'teoriya'), true);
check('«Проект» не пускает на защитную речь',      GS_Proekt::allows('project', 'rech'), false);
check('«Проект + защита» пускает на речь',         GS_Proekt::allows('defense', 'rech'), true);

echo "\n=== доплата разницы ===\n";
check('Старт → Проект = 500',                      GS_Proekt::upgrade_price('start', 'project'), 500);
check('Бесплатно → Проект + защита = 1490',        GS_Proekt::upgrade_price('free', 'defense'), 1490);
check('вниз не доплачиваем',                       GS_Proekt::upgrade_price('defense', 'start'), 0);

echo "\n=== шаг ===\n";
$t = GS_Proekt::create(array('класс' => '10', 'предметы' => 'биология'));
$r = GS_Proekt::run($t, 'pasport', '');
check('закрытый шаг отбит',                        $r['ok'], false);
check('и назван нужный тариф',                     $r['need'], 'start');
$r = GS_Proekt::run($t, 'tema', 'люблю биологию');
check('открытый шаг прошёл',                       $r['ok'], true);
check('счётчик запросов вырос',                    $r['used'], 1);
$r = GS_Proekt::run($t, 'tema', str_repeat('я', 15001));
check('слишком длинный ввод отбит',                $r['ok'], false);

echo "\n=== лимит запросов ===\n";
$row = GS_Proekt::project($t); $row['used'] = 3; update_option('gs_proekt_' . $t, $row);
$r = GS_Proekt::run($t, 'tema', 'ещё');
check('лимит бесплатного тарифа сработал',         $r['ok'], false);
check('предложен следующий тариф',                 $r['need'], 'start');

echo "\n=== оплата ===\n";
$label = GS_Proekt::label($t, 'project');
$p = GS_Proekt::paid($label);
check('тариф открылся',                            $p['ok'], true);
check('в проекте стоит «Проект»',                  GS_Proekt::project($t)['tariff'], 'project');
$p2 = GS_Proekt::paid($label);
check('повтор не понижает и не ломает',            GS_Proekt::project($t)['tariff'], 'project');
check('чужая метка отбита',                        GS_Proekt::paid('proekt_нет_project')['ok'], false);

echo "\n=== срок доступа ===\n";
$row = GS_Proekt::project($t); $row['paid_until'] = time() - 10; update_option('gs_proekt_' . $t, $row);
$r = GS_Proekt::run($t, 'tema', 'после срока');
check('после срока доступ закрыт',                 $r['ok'], false);

echo "\n=== контекст для модели ===\n";
$row = GS_Proekt::project($t);
$row['paid_until'] = time() + 1000; $row['used'] = 0; $row['tariff'] = 'defense';
$row['steps'] = array(
  'tema' => array('output' => 'ТЕМА-РЕЗУЛЬТАТ'),
  'pasport' => array('output' => str_repeat('П', 70000)),
  'plan' => array('output' => 'ПЛАН-РЕЗУЛЬТАТ'),
);
update_option('gs_proekt_' . $t, $row);
GS_Proekt::run($t, 'teoriya', 'мои выписки');
$msg = GS_Provider::$last[1]['content'];
check('паспорт в контексте, хоть и огромный',      strpos($msg, 'П') !== false && strpos($msg, '2. Паспорт проекта') !== false, true);
check('ранние шаги вытеснены бюджетом',            strpos($msg, 'ТЕМА-РЕЗУЛЬТАТ') === false, true);
check('свежий шаг остался',                        strpos($msg, 'ПЛАН-РЕЗУЛЬТАТ') !== false, true);
check('инструкция шага на месте',                  strpos($msg, '<инструкция_шага>') !== false, true);
check('ввод ученика на месте',                     strpos($msg, 'мои выписки') !== false, true);
check('текущий шаг не попал в контекст как прошлый', substr_count($msg, '<результат_шага'), 2);

echo "\n=== поставщик молчит ===\n";
GS_Provider::$reply = array('ok' => false);
$r = GS_Proekt::run($t, 'rech', '');
check('отказ поставщика не роняет',                $r['ok'], false);
GS_Provider::$reply = array('ok' => true, 'content' => '   ');
$r = GS_Proekt::run($t, 'rech', '');
check('пустой ответ не сохраняется',               $r['ok'], false);

echo "\n" . ($fail ? "ПРОВАЛОВ: $fail\n" : "все проверки прошли\n");
