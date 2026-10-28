<?php
/**
 * Проверки расстановки рекламы в списке звуков.
 *
 * Места блоков — не украшение: по статистике РСЯ лента, стоявшая под
 * списком, собирала 10–16 % видимых показов против 36–60 % у блока
 * внутри списка. Поэтому важно, чтобы блоки вставали именно туда, куда
 * задумано, не дублировались (РСЯ отдаёт во второй контейнер с тем же
 * номером пустоту) и не пропадали в коротких разделах.
 */

define('ABSPATH', 1);

$GLOBALS['opts'] = array(
    'gs_ads_fullscreen_touch' => 'R-A-111111-2',
    'gs_ads_floor_touch'      => 'R-A-111111-3',
    'gs_ads_feed'             => 'R-A-111111-4',
    'gs_ads_infeed'           => 'R-A-111111-5',
    'gs_ads_infeed2'          => 'R-A-111111-6',
    'gs_ads_on'               => 1,
);

function get_option($k, $d = null) { return $GLOBALS['opts'][$k] ?? $d; }
function update_option($k, $v, $a = true) { $GLOBALS['opts'][$k] = $v; return true; }
function wp_is_mobile() { return true; }
function is_admin() { return false; }
function is_feed() { return false; }
function is_user_logged_in() { return false; }
function current_user_can($cap) { return false; }
function esc_attr($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
function esc_js($s) { return $s; }
function wp_json_encode($v) { return json_encode($v, JSON_UNESCAPED_UNICODE); }
function add_action($hook, $cb, $p = 10, $n = 1) {}
function sanitize_text_field($s) { return $s; }

/** Каталог: страницы, где реклама разрешена. */
class GS_Catalog {
    public static function is_catalog_request() { return true; }
}

require __DIR__ . '/includes/class-gs-ads.php';

$fail = 0;
function check($what, $got, $want) {
    global $fail;
    $ok = $got === $want;
    if (!$ok) { $fail++; }
    printf("  %s %-52s %s\n", $ok ? 'ок  ' : 'ПЛОХО', $what,
        $ok ? '' : ('получили ' . var_export($got, true) . ', ждали ' . var_export($want, true)));
}
function has($html, $needle) { return strpos($html, $needle) !== false; }

/** Один проход по списку из $n карточек: что и после какой карточки встало. */
function walk($n) {
    $places = array();
    for ($i = 0; $i < $n; $i++) {
        $html = GS_Ads::after_card($i);
        if ($html !== '' && preg_match('~(R-A-[0-9-]+)~', $html, $m)) {
            $places[$m[1]] = $i + 1;
        }
    }
    return $places;
}

echo "=== длинный раздел ===\n";
$long = walk(60);
check('блок в списке — после 3-й карточки',   $long['R-A-111111-5'] ?? 0, GS_Ads::INFEED_AFTER);
check('лента — после 8-й карточки',           $long['R-A-111111-4'] ?? 0, GS_Ads::FEED_AFTER);
check('второй блок — после 13-й карточки',    $long['R-A-111111-6'] ?? 0, GS_Ads::INFEED2_AFTER);
check('больше блоков в списке не появилось',  count($long), 3);
check('лента под списком не повторяется',     GS_Ads::slot('feed'), '');

echo "\n=== обычный раздел (14 звуков) ===\n";
$ads = new ReflectionProperty('GS_Ads', 'done');
$ads->setAccessible(true);
$ads->setValue(null, array());
$mid = walk(14);
check('все три блока помещаются',             count($mid), 3);
check('последний блок не под последней карточкой', ($mid['R-A-111111-6'] ?? 0) < 14, true);

echo "\n=== короткий раздел (5 звуков) ===\n";
$ads->setValue(null, array());
$short = walk(5);
check('блок в списке есть',                   $short['R-A-111111-5'] ?? 0, GS_Ads::INFEED_AFTER);
check('лента в список не влезла',             isset($short['R-A-111111-4']), false);
check('но встаёт под списком',                has(GS_Ads::slot('feed'), 'R-A-111111-4'), true);

echo "\n=== как рисуем ===\n";
$ads->setValue(null, array());
$banner = GS_Ads::after_card(GS_Ads::INFEED_AFTER - 1);
$feed   = GS_Ads::after_card(GS_Ads::FEED_AFTER - 1);
check('баннер — обычный render',              has($banner, 'AdvManager.render('), true);
check('лента — renderFeed',                   has($feed, 'AdvManager.renderFeed('), true);
check('баннер согласия блок не прячет',       has($banner, 'data-cookietrix-skip'), true);
check('до первого места реклама не лезет',    GS_Ads::after_card(0), '');

echo "\n=== загрузчик ждёт показа страницы ===\n";
$ads->setValue(null, array());
ob_start();
GS_Ads::render();
$loader = ob_get_clean();
check('предзагрузку ждём по prerenderingchange', has($loader, 'prerenderingchange'), true);
check('фоновую вкладку — по visibilitychange',   has($loader, 'visibilitychange'), true);
check('до показа сеть не зовём',                 has($loader, 'function start()'), true);
check('согласие проверяется в момент показа',    has($loader, 'function allowed()'), true);
check('загрузчик собирается в браузере',         has($loader, "createElement('script')"), true);

echo "\n=== опечатка в номере ===\n";
$ads->setValue(null, array());
$GLOBALS['opts']['gs_ads_infeed2'] = 'R-A-111111';
check('кривой номер не выводится вовсе',      GS_Ads::after_card(GS_Ads::INFEED2_AFTER - 1), '');

echo $fail ? "\nпровалов: $fail\n" : "\nвсе проверки прошли\n";
exit($fail ? 1 : 0);
