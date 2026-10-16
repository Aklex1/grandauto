<?php
/**
 * Готовая страница сервиса для гостя.
 *
 * Страницы сервисов собираются около секунды, а главная отдаётся за две
 * десятых — её кладёт в кэш плагин кэширования, а сервисные страницы мы
 * сами из кэша исключили: на них бывает личное — баланс, ключи, результат
 * чужой генерации.
 *
 * Но личное там бывает только у вошедшего: гостю страница показывает
 * «Нужен вход» и ни одной цифры баланса. А именно гость — это и человек с
 * поиска, и поисковый робот, которому секунда ожидания идёт в оценку
 * сайта. Поэтому здесь свой маленький кэш: гостю отдаём готовый HTML,
 * вошедшему — всегда свежую сборку.
 *
 * Правила, без которых такой кэш превращается в утечку:
 *   — только GET без параметров и без личных печений;
 *   — только страницы, которые рисуем мы сами;
 *   — перед записью страницу осматриваем: нашли признак личного — не пишем;
 *   — ключ помнит версию плагина и «эпоху», которая сдвигается при любой
 *     правке записей, так что правка видна сразу, а не через четверть часа.
 */

if (!defined('ABSPATH')) {
    exit;
}

class GS_Page_Cache {

    /** Сколько живёт готовая страница. */
    const TTL = 900;

    /** Счётчик правок: сдвинулся — все готовые страницы недействительны. */
    const EPOCH = 'gs_html_epoch';

    const PREFIX = 'gs_html_';

    /** Признаки личного: встретили в готовой странице — не сохраняем. */
    private static $personal = array(
        'gs-balance__value',
        'id="wpadminbar"',
        'gs-api-key',
        'gs-login-code',
    );

    public static function boot() {
        // Раньше, чем что-либо выведено, но позже, чем WordPress понял,
        // какую страницу он показывает.
        add_action('template_redirect', array(__CLASS__, 'start'), 5);

        foreach (array('save_post', 'deleted_post', 'switch_theme', 'customize_save_after') as $hook) {
            add_action($hook, array(__CLASS__, 'bump'));
        }
        add_action('updated_option', array(__CLASS__, 'option_changed'), 10, 1);
    }

    /**
     * Настройку меняют из админки — там же и сдвигаем эпоху, чтобы правка
     * была видна сразу.
     *
     * Только из админки: на самом сайте наши настройки тоже пишутся —
     * очередь целей, здоровье поставщика, служебные отметки задач, — и если
     * считать устаревание по ним, эпоха будет сдвигаться на каждом запросе.
     * Кэш тогда не срабатывает ни разу: страница записывается под одним
     * ключом, а следующий посетитель ищет уже под другим.
     */
    public static function option_changed($option) {
        if (!is_admin()) {
            return;
        }
        if (strpos((string) $option, 'gs_') === 0 && strpos((string) $option, '_transient') === false) {
            self::bump();
        }
    }

    public static function bump() {
        update_option(self::EPOCH, (string) time(), false);
    }

    private static function epoch() {
        $value = get_option(self::EPOCH);
        return is_string($value) && $value !== '' ? $value : '0';
    }

    /**
     * Наша ли это страница сервиса. Кабинеты, вход и чужие плагины сюда
     * не попадают: там личное есть и у гостя.
     */
    private static function ours() {
        if (class_exists('GS_Lab') && GS_Lab::current_service()) {
            return true;
        }
        if (class_exists('GS_Landing') && method_exists('GS_Landing', 'current') && GS_Landing::current()) {
            return true;
        }
        return false;
    }

    /**
     * Можно ли этому посетителю отдать или записать готовую страницу.
     * Возвращает причину отказа — её же отдаём заголовком, иначе
     * «кэш почему-то не работает» приходится выяснять вслепую.
     */
    private static function reason() {
        if (is_admin()) {
            return 'admin';
        }
        if (is_user_logged_in()) {
            return 'logged';
        }
        if (function_exists('wp_doing_ajax') && wp_doing_ajax()) {
            return 'ajax';
        }
        if (defined('DONOTCACHEPAGE') && DONOTCACHEPAGE) {
            return 'nocache';
        }
        $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string) $_SERVER['REQUEST_METHOD']) : 'GET';
        if ($method !== 'GET') {
            return 'method';
        }
        if (!empty($_GET) || !empty($_POST)) { // phpcs:ignore WordPress.Security.NonceVerification
            return 'query';
        }
        if (is_404() || is_search() || is_feed() || is_preview() || is_robots() || post_password_required()) {
            return 'special';
        }
        // Печенья, из-за которых страница у двух гостей выглядит по-разному.
        // Свои служебные (откуда пришёл, куда вернуть после входа, источник
        // рекламы) на вид страницы не влияют, и мешать кэшу им незачем —
        // иначе он не включится ни разу: такое печенье мы сами и ставим.
        foreach (array_keys((array) $_COOKIE) as $name) {
            if (preg_match('~^(wordpress_logged_in|wordpress_sec|wp-postpass|wp-settings|comment_author|woocommerce|wp_woocommerce|tts_|gs_sfx_trial|gs_proekt)~i', (string) $name)) {
                return 'cookie';
            }
        }
        if (!self::ours()) {
            return 'other';
        }
        return '';
    }

    private static function eligible() {
        return self::reason() === '';
    }

    /**
     * Ключ страницы. Кроме адреса в нём версия плагина, эпоха правок и
     * согласие с печеньем: баннер согласия рисуется на сервере, и человеку,
     * который его закрыл, незачем видеть его снова.
     */
    private static function key() {
        $uri  = isset($_SERVER['REQUEST_URI']) ? (string) wp_unslash($_SERVER['REQUEST_URI']) : '/';
        $path = (string) wp_parse_url($uri, PHP_URL_PATH);
        $parts = array(
            $path,
            GS_VERSION,
            self::epoch(),
            isset($_COOKIE['cookie_notice_accepted']) ? 'c1' : 'c0',
            (function_exists('wp_is_mobile') && wp_is_mobile()) ? 'm1' : 'm0',
        );
        return self::PREFIX . md5(implode('|', $parts));
    }

    /**
     * Ранняя отдача: до темы и до остальных плагинов.
     *
     * Сборка страницы занимает три четверти секунды, а отдача из нашего
     * кэша — почти полсекунды. Разница невелика, и причина не в шаблоне:
     * столько же тратится на загрузку самого WordPress с четырьмя десятками
     * плагинов — до того, как дело дойдёт до страницы.
     *
     * Поэтому смотрим в кэш в самом начале: наш плагин подключается раньше
     * темы и раньше большинства соседей, и если готовая страница есть, она
     * уходит человеку сразу, а остальная загрузка не случается вовсе.
     *
     * Здесь нельзя пользоваться ничем из WordPress, кроме работы с
     * настройками: тема, запрос и даже wp_is_mobile() ещё не готовы.
     * Поэтому проверки — только по адресу и печеньям, а ключ собирается
     * из того же, из чего и при записи.
     */
    public static function early() {
        if (is_admin() || (defined('DOING_CRON') && DOING_CRON)) {
            return;
        }
        $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string) $_SERVER['REQUEST_METHOD']) : 'GET';
        if ($method !== 'GET' || !empty($_GET) || !empty($_POST)) { // phpcs:ignore WordPress.Security.NonceVerification
            return;
        }
        foreach (array_keys((array) $_COOKIE) as $name) {
            if (preg_match('~^(wordpress_logged_in|wordpress_sec|wp-postpass|wp-settings|comment_author|woocommerce|wp_woocommerce|tts_|gs_sfx_trial|gs_proekt)~i', (string) $name)) {
                return;
            }
        }
        $key = self::key();
        $html = get_transient($key);
        if (!is_string($html) || $html === '') {
            if (!headers_sent()) {
                header('X-GS-Key: ' . $key . '/' . self::epoch());
            }
            return;
        }
        if (!headers_sent()) {
            header('X-GS-Cache: hit-early');
            header('X-GS-Time: ' . self::spent());
            header('Cache-Control: public, max-age=300, must-revalidate');
            header('Vary: Cookie');
        }
        echo $html; // phpcs:ignore WordPress.Security.EscapeOutput
        exit;
    }

    public static function start() {
        $why = self::reason();
        if ($why !== '') {
            if (!headers_sent() && $why !== 'admin' && $why !== 'ajax') {
                header('X-GS-Cache: off-' . $why);
            }
            return;
        }
        $key = self::key();
        $html = get_transient($key);
        if (is_string($html) && $html !== '') {
            self::headers('hit');
            if (!headers_sent()) {
                // Сколько заняла отдача на сервере. Без этой цифры мерить
                // ускорение приходится через канал до песочницы, а он
                // добавляет до секунды и врёт больше, чем сам сайт.
                header('X-GS-Time: ' . self::spent());
            }
            echo $html; // phpcs:ignore WordPress.Security.EscapeOutput
            exit;
        }
        self::headers('miss');
        ob_start(array(__CLASS__, 'keep'));
    }

    /**
     * Сохраняем собранную страницу, если в ней нет личного и она собралась
     * целиком. Возвращаем буфер как есть: наша задача — запомнить, а не
     * переписать.
     */
    public static function keep($html) {
        if (!is_string($html) || strlen($html) < 2048) {
            return $html;
        }
        if (function_exists('http_response_code') && (int) http_response_code() !== 200) {
            return $html;
        }
        if (is_user_logged_in() || (defined('DONOTCACHEPAGE') && DONOTCACHEPAGE)) {
            return $html;
        }
        if (stripos($html, '</html>') === false) {
            return $html;
        }
        foreach (self::$personal as $mark) {
            if (stripos($html, $mark) !== false) {
                return $html;
            }
        }
        $html .= "\n<!-- gs: страница собрана за " . self::spent() . " с; ключ " . self::key()
               . "; эпоха " . self::epoch() . " -->";
        set_transient(self::key(), $html, self::TTL);
        return $html;
    }

    /** Сколько прошло с начала запроса, в секундах. */
    public static function spent() {
        $start = isset($_SERVER['REQUEST_TIME_FLOAT']) ? (float) $_SERVER['REQUEST_TIME_FLOAT'] : 0.0;
        return $start > 0 ? number_format(microtime(true) - $start, 3, '.', '') : '0';
    }

    /**
     * Заголовки. Гостю разрешаем подержать страницу у себя пять минут, но
     * с оговоркой Vary: Cookie — чтобы вошедшему не досталась гостевая
     * копия ни от браузера, ни от промежуточного кэша.
     */
    private static function headers($state) {
        if (headers_sent()) {
            return;
        }
        header('X-GS-Cache: ' . $state);
        header('Cache-Control: public, max-age=300, must-revalidate');
        header('Vary: Cookie');
    }

    /** Убрать все готовые страницы (при обновлении плагина). */
    public static function flush() {
        global $wpdb;
        $like = $wpdb->esc_like('_transient_' . self::PREFIX) . '%';
        $names = $wpdb->get_col($wpdb->prepare("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $like));
        foreach ((array) $names as $name) {
            delete_transient(str_replace('_transient_', '', (string) $name));
        }
        self::bump();
    }
}
