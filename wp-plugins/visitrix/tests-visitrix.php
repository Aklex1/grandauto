<?php
/**
 * Проверки Visitrix без WordPress: подставляем функции-заглушки и гоняем
 * чистую логику — разбор источников, разбор браузера, проверку настроек,
 * проверку дат и чистку адреса страницы.
 *
 * Запуск: php wp-plugins/visitrix/tests-visitrix.php
 *
 * Почему так, а не через живой сайт: статистика на сайте настоящая, и
 * тестовые заходы в неё писать нельзя — они искажают отчёты.
 *
 * @package Visitrix
 */

define( 'ABSPATH', 1 );

function apply_filters( $hook, $value ) { return $value; }
function home_url( $path = '' ) { return 'https://genius-bot.ru' . $path; }
function site_url( $path = '' ) { return 'https://genius-bot.ru' . $path; }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function sanitize_key( $s ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $s ) ); }
function absint( $v ) { return abs( (int) $v ); }
function esc_url_raw( $u ) { return (string) $u; }
function wp_parse_str( $string, &$array ) { parse_str( (string) $string, $array ); }
function wp_parse_args( $args, $defaults = array() ) { return array_merge( $defaults, (array) $args ); }
function get_option( $key, $default = false ) { return $GLOBALS['vx_options'][ $key ] ?? $default; }
function update_option( $key, $value, $autoload = true ) { $GLOBALS['vx_options'][ $key ] = $value; return true; }

class VX_Roles {
	public function get_names() {
		return array(
			'administrator' => 'Администратор',
			'editor'        => 'Редактор',
			'author'        => 'Автор',
			'subscriber'    => 'Подписчик',
			'gc_student'    => 'Ученик курса',
		);
	}
}
function wp_roles() { return new VX_Roles(); }

// Запрос REST нужен трекеру только ради заголовков — хватает этой заглушки.
class WP_REST_Request {
	private $headers;
	public function __construct( $headers = array() ) { $this->headers = $headers; }
	public function get_header( $name ) { return $this->headers[ $name ] ?? ''; }
	public function get_body() { return ''; }
	public function get_param( $name ) { return null; }
}

$GLOBALS['vx_options'] = array();

require __DIR__ . '/includes/class-visitrix-settings.php';
require __DIR__ . '/includes/class-visitrix-ua.php';
require __DIR__ . '/includes/class-visitrix-sources.php';

// Трекер тянет за собой таблицы и запросы, а нам нужен только разбор
// адреса: берём класс без тех частей, которые ходят в базу.
$tracker = file_get_contents( __DIR__ . '/includes/class-visitrix-tracker.php' );
$tracker = preg_replace( '~^<\?php~', '', $tracker, 1 );
$tracker = str_replace( "defined( 'ABSPATH' ) || exit;", '', $tracker );
eval( $tracker ); // phpcs:ignore Squiz.PHP.Eval.Discouraged

$fail = 0;
$total = 0;
function check( $what, $got, $want ) {
	global $fail, $total;
	$total++;
	$ok = $got === $want;
	if ( ! $ok ) {
		$fail++;
	}
	printf(
		"  %s %-54s %s\n",
		$ok ? 'ок  ' : 'ПЛОХО',
		$what,
		$ok ? '' : ( 'получили ' . var_export( $got, true ) . ', ждали ' . var_export( $want, true ) )
	);
}

function source( $referrer, $params = array() ) {
	$r = Visitrix_Sources::classify( $referrer, $params );
	return $r['type'] . ':' . $r['name'];
}

function path_of( $url ) {
	$m = new ReflectionMethod( 'Visitrix_Tracker', 'clean_path' );
	$m->setAccessible( true );
	return $m->invoke( null, $url );
}

echo "=== источники: откуда пришёл посетитель ===\n";
check( 'без реферера — прямой заход',        source( '' ), 'direct:' );
check( 'свой же сайт — внутренний переход',  source( 'https://genius-bot.ru/blog/' ), 'internal:genius-bot.ru' );
check( 'www свой же — тоже внутренний',      source( 'https://www.genius-bot.ru/x' ), 'internal:genius-bot.ru' );
check( 'Google — поиск',                     source( 'https://www.google.com/search?q=1' ), 'search:Google' );
check( 'Яндекс — поиск',                     source( 'https://yandex.ru/search/?text=1' ), 'search:Yandex' );
check( 'ya.ru — тоже Яндекс',                source( 'https://ya.ru/' ), 'search:Yandex' );
check( 'поиск Mail.ru',                      source( 'https://go.mail.ru/search?q=1' ), 'search:Mail.ru' );
check( 'ChatGPT — ответ нейросети',          source( 'https://chatgpt.com/c/123' ), 'ai:ChatGPT' );
check( 'Perplexity — нейросеть',             source( 'https://www.perplexity.ai/search' ), 'ai:Perplexity' );
check( 'ВКонтакте — соцсеть',                source( 'https://vk.com/wall-1_2' ), 'social:VK' );
check( 'сокращённая ссылка t.co',            source( 'https://t.co/abc' ), 'social:X (Twitter)' );
check( 'Дзен — соцсеть',                     source( 'https://dzen.ru/a/xxx' ), 'social:Dzen' );
check( 'Telegram — мессенджер',              source( 'https://t.me/neuro_creator_pro' ), 'messenger:Telegram' );
check( 'незнакомый сайт — переход по ссылке', source( 'https://example.org/page' ), 'referral:example.org' );

echo "\n=== реклама ===\n";
check( 'метка yclid — Яндекс Директ',        source( '', array( 'yclid' => '42' ) ), 'ad:Yandex Direct' );
check( 'метка gclid — Google Ads',           source( '', array( 'gclid' => '42' ) ), 'ad:Google Ads' );
check( 'utm_medium=cpc — реклама',           source( '', array( 'utm_medium' => 'cpc', 'utm_source' => 'vk' ) ), 'ad:vk' );
check( 'utm_medium=email — не реклама',      source( '', array( 'utm_medium' => 'email', 'utm_source' => 'rассылка' ) ), 'referral:rассылка' );

echo "\n=== без реферера, но с меткой ===\n";
check( 'utm_source=telegram',                source( '', array( 'utm_source' => 'telegram' ) ), 'messenger:Telegram' );
check( 'utm_source=tg — сокращение',         source( '', array( 'utm_source' => 'tg' ) ), 'messenger:Telegram' );
check( 'utm_source=vk',                      source( '', array( 'utm_source' => 'vk' ) ), 'social:VK' );
check( 'utm_source=ya — Яндекс',             source( '', array( 'utm_source' => 'ya' ) ), 'search:Yandex' );
check( 'utm_source=рассылка — переход',      source( '', array( 'utm_source' => 'рассылка' ) ), 'referral:рассылка' );

echo "\n=== боты ===\n";
check( 'пустой агент — бот',                 Visitrix_UA::is_bot( '' ), true );
check( 'Googlebot',                          Visitrix_UA::is_bot( 'Mozilla/5.0 (compatible; Googlebot/2.1)' ), true );
check( 'YandexBot',                          Visitrix_UA::is_bot( 'Mozilla/5.0 (compatible; YandexBot/3.0)' ), true );
check( 'curl',                               Visitrix_UA::is_bot( 'curl/8.5.0' ), true );
check( 'python-requests',                    Visitrix_UA::is_bot( 'python-requests/2.31' ), true );
check( 'headless Chrome',                    Visitrix_UA::is_bot( 'Mozilla/5.0 HeadlessChrome/120' ), true );
check( 'живой Chrome — не бот',              Visitrix_UA::is_bot( 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0 Safari/537.36' ), false );

echo "\n=== устройство, браузер, система ===\n";
$iphone = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1';
$ipad   = 'Mozilla/5.0 (iPad; CPU OS 17_0 like Mac OS X) AppleWebKit/605.1 Version/17.0 Mobile/15E148 Safari/604.1';
$droid  = 'Mozilla/5.0 (Linux; Android 14; SM-S911B) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0 Mobile Safari/537.36';
$tab    = 'Mozilla/5.0 (Linux; Android 13; SM-X200) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0 Safari/537.36';
$yab    = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 YaBrowser/24.4.0 Safari/537.36';
$edge   = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0 Safari/537.36 Edg/131.0';
$mac    = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1 (KHTML, like Gecko) Version/17.0 Safari/605.1';

check( 'iPhone — смартфон',                  Visitrix_UA::parse( $iphone )['device'], 'mobile' );
check( 'iPhone — iOS',                       Visitrix_UA::parse( $iphone )['os'], 'iOS' );
check( 'iPhone — Safari',                    Visitrix_UA::parse( $iphone )['browser'], 'Safari' );
check( 'iPad — планшет',                     Visitrix_UA::parse( $ipad )['device'], 'tablet' );
check( 'Android с Mobile — смартфон',        Visitrix_UA::parse( $droid )['device'], 'mobile' );
check( 'Android без Mobile — планшет',       Visitrix_UA::parse( $tab )['device'], 'tablet' );
check( 'Яндекс.Браузер не путаем с Chrome',  Visitrix_UA::parse( $yab )['browser'], 'Yandex Browser' );
check( 'Edge не путаем с Chrome',            Visitrix_UA::parse( $edge )['browser'], 'Edge' );
check( 'Mac без касаний — компьютер',        Visitrix_UA::parse( $mac, 0 )['device'], 'desktop' );
check( 'Mac с касаниями — iPad',             Visitrix_UA::parse( $mac, 5 )['device'], 'tablet' );
check( 'Mac с касаниями — iOS',              Visitrix_UA::parse( $mac, 5 )['os'], 'iOS' );
check( 'Mac без касаний — macOS',            Visitrix_UA::parse( $mac, 0 )['os'], 'macOS' );

echo "\n=== настройки ===\n";
$clean = Visitrix_Settings::sanitize( array( 'retention_days' => 5 ) );
check( 'срок хранения не ниже 30 дней',      $clean['retention_days'], 30 );
$clean = Visitrix_Settings::sanitize( array( 'retention_days' => 99999 ) );
check( 'срок хранения не выше 3650 дней',    $clean['retention_days'], 3650 );
$clean = Visitrix_Settings::sanitize( array( 'exclude_roles' => array( 'administrator', 'выдуманная' ) ) );
check( 'выдуманная роль отбрасывается',      $clean['exclude_roles'], array( 'administrator' ) );
$clean = Visitrix_Settings::sanitize( array( 'enabled' => 'да', 'respect_dnt' => '0' ) );
check( 'включено приводится к единице',      $clean['enabled'], 1 );
check( 'строка «0» — это выключено',         $clean['respect_dnt'], 0 );
check( 'мусор на входе не роняет',           Visitrix_Settings::sanitize( 'мусор' )['retention_days'], 365 );

echo "\n=== даты отчёта ===\n";
require __DIR__ . '/includes/class-visitrix-rest.php';
check( 'обычная дата',                       Visitrix_Rest::valid_date( '2026-10-21' ), true );
check( '31 февраля не бывает',               Visitrix_Rest::valid_date( '2026-02-31' ), false );
check( 'без ведущих нулей — нет',            Visitrix_Rest::valid_date( '2026-1-1' ), false );
check( 'не строка — нет',                    Visitrix_Rest::valid_date( array( '2026-10-21' ) ), false );
check( 'подставленный sql — нет',            Visitrix_Rest::valid_date( "2026-10-21' OR 1=1" ), false );

echo "\n=== адрес страницы ===\n";
check( 'полный адрес — только путь',         path_of( 'https://genius-bot.ru/blog/post/?x=1' ), '/blog/post/' );
check( 'пустой адрес — корень',              path_of( '' ), '/' );
check( 'не строка — корень',                 path_of( null ), '/' );
check( 'без ведущей косой — добавим',        path_of( 'blog' ), '/blog' );
check( 'кириллица остаётся, но в %-записи',  path_of( 'https://genius-bot.ru/нейросети/' ), '/%D0%BD%D0%B5%D0%B9%D1%80%D0%BE%D1%81%D0%B5%D1%82%D0%B8/' );
check( 'уже закодованный путь не трогаем',   path_of( '/%D0%BD%D0%B5%D0%B9/' ), '/%D0%BD%D0%B5%D0%B9/' );
// parse_url сам меняет управляющий символ на подчёркивание — это PHP, не мы;
// наша задача здесь только убрать пробел.
check( 'пробелы и управляющие символы прочь', path_of( "/blog/\x01 post" ), '/blog/_post' );
check( 'длинный путь обрезается до 255',     strlen( path_of( '/' . str_repeat( 'a', 400 ) ) ), 255 );

echo "\n=== просьба не следить (Do Not Track) ===\n";
check( 'заголовок DNT: 1 — не следим',       Visitrix_Tracker::opted_out( new WP_REST_Request( array( 'dnt' => '1' ) ) ), true );
check( 'Sec-GPC: 1 — тоже не следим',        Visitrix_Tracker::opted_out( new WP_REST_Request( array( 'sec_gpc' => '1' ) ) ), true );
check( 'DNT: 0 — следим как обычно',         Visitrix_Tracker::opted_out( new WP_REST_Request( array( 'dnt' => '0' ) ) ), false );
check( 'без заголовков — следим',            Visitrix_Tracker::opted_out( new WP_REST_Request() ), false );

echo "\n";
printf( "Итого: проверок %d, неудачных %d\n", $total, $fail );
exit( $fail > 0 ? 1 : 0 );
