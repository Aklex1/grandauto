<?php
/**
 * Кабинет озвучки (/tts-dashboard/) в стилистике остальных сервисов.
 *
 * Базовый плагин не трогаем: добавляем класс на body, свой файл стилей
 * и небольшой скрипт, который убирает переехавшие разделы.
 *
 * Отсюда же вычищаем из верхней панели плагина ссылки на его старую
 * документацию: описание API теперь одно на все инструменты и живёт в
 * общем разделе.
 */

if (!defined('ABSPATH')) {
    exit;
}

class GS_Dashboard {

    const OPT_ENABLED = 'gs_dashboard_restyle';

    public static function boot() {
        add_filter('body_class', array(__CLASS__, 'body_class'));
        add_filter('the_content', array(__CLASS__, 'append_keywords'), 20);
        // Позже вывода шорткодов: панель рисуется именно ими.
        add_filter('the_content', array(__CLASS__, 'strip_old_docs_links'), 30);
        add_filter('the_content', array(__CLASS__, 'append_proekt'), 35);
        add_filter('the_content', array(__CLASS__, 'append_support'), 40);
    }

    /**
     * Проект школьника в кабинете.
     *
     * Работа живёт по токену в браузере, и человек, который оплатил тариф с
     * телефона, на компьютере не знает, куда идти. Кабинет — единственное
     * место, которое он точно найдёт, поэтому показываем ссылку именно
     * тем, у кого проект есть.
     */
    public static function append_proekt($content) {
        if (is_admin() || !is_main_query() || !in_the_loop() || !self::is_page()) {
            return $content;
        }
        if (!class_exists('GS_Proekt') || !class_exists('GS_Proekt_Page')) {
            return $content;
        }
        $token = GS_Proekt::latest_for_user(get_current_user_id());
        if ($token === '') {
            return $content;
        }
        $row = GS_Proekt::project($token);
        $tariffs = GS_Proekt::tariffs();
        $tariff = isset($tariffs[$row['tariff']]) ? $tariffs[$row['tariff']][0] : '';
        $done = 0;
        foreach ((array) ($row['steps'] ?? array()) as $step) {
            if (!empty($step['output'])) {
                $done++;
            }
        }
        $url = add_query_arg('token', $token, GS_Proekt_Page::url());

        ob_start();
        ?>
        <section class="gs-dash-proekt">
            <h3>Индивидуальный проект</h3>
            <p>Тариф «<?php echo esc_html($tariff); ?>», готовых шагов: <?php echo (int) $done; ?>.
                Доступ до <?php echo esc_html(date_i18n('d.m.Y', (int) $row['paid_until'])); ?>.</p>
            <p><a class="gs-dash-proekt__go" href="<?php echo esc_url($url); ?>">Открыть мой проект</a></p>
        </section>
        <style>
            .gs-dash-proekt{max-width:1200px;margin:24px auto;padding:18px 20px;border-radius:14px;
                background:linear-gradient(135deg,rgba(90,92,224,.1),rgba(255,176,92,.1));
                border:1px solid rgba(90,92,224,.18)}
            .gs-dash-proekt h3{margin:0 0 6px;font-size:18px}
            .gs-dash-proekt p{margin:0 0 6px}
            .gs-dash-proekt__go{font-weight:600;color:#5a5ce0}
        </style>
        <?php
        return $content . ob_get_clean();
    }

    /**
     * Убираем из верхней панели рабочего плагина ссылки на его прежнюю
     * документацию — «Документация» и «API» ведут на /api-docs/, которого
     * больше нет в навигации: описание переехало в общий раздел.
     *
     * Разметку плагина не меняем, вырезаем уже готовый вывод.
     */
    public static function strip_old_docs_links($content) {
        if (is_admin() || strpos($content, 'zv-topbar-links') === false) {
            return $content;
        }
        return preg_replace_callback(
            '~<div class="zv-topbar-links">(.*?)</div>~s',
            function ($block) {
                $links = preg_replace('~<a[^>]*href="[^"]*api-docs[^"]*"[^>]*>.*?</a>\s*~s', '', $block[1]);
                return '<div class="zv-topbar-links">' . $links . '</div>';
            },
            $content
        );
    }

    /**
     * Разборы частых задач переехали на страницу расшифровки вместе с
     * самим сервисом. Здесь фильтр оставлен пустым, чтобы блок не появился
     * в кабинете второй раз.
     */
    /**
     * Связь с поддержкой в кабинете озвучки.
     *
     * Кабинет рисует чужой плагин, и трогать его нельзя. Но блок — наш
     * вывод в конце содержимого страницы: базовый плагин об этом не знает
     * и знать не должен. Озвучка — главный платный сервис, и оставлять
     * её единственной без кнопки «что-то не так» было бы странно.
     */
    public static function append_support($content) {
        if (is_admin() || !is_main_query() || !in_the_loop() || !self::is_page()) {
            return $content;
        }
        if (strpos($content, 'gs-help') !== false || !class_exists('GS_Support')) {
            return $content;
        }
        return $content . GS_Support::render('Озвучка текста');
    }

    public static function append_keywords($content) {
        if (is_admin() || !is_main_query() || !in_the_loop()) {
            return $content;
        }
        if (!self::enabled() || !self::is_page() || !class_exists('GS_Keywords')) {
            return $content;
        }
        if (strpos($content, 'gs-keys') !== false) {
            return $content;
        }
        return $content;
    }

    public static function enabled() {
        return (string) get_option(self::OPT_ENABLED, '1') === '1';
    }

    public static function page_id() {
        return (int) get_option('kie_tts_dashboard_page_id');
    }

    public static function is_page() {
        $pid = self::page_id();
        if ($pid > 0 && is_page($pid)) {
            return true;
        }
        // Страницу могли создать вручную — подстраховываемся адресом.
        $uri = isset($_SERVER['REQUEST_URI']) ? (string) wp_unslash($_SERVER['REQUEST_URI']) : '';
        $path = trim((string) wp_parse_url($uri, PHP_URL_PATH), '/');
        return $path === 'tts-dashboard';
    }

    public static function body_class($classes) {
        if (is_admin() || !self::enabled() || !self::is_page()) {
            return $classes;
        }
        $classes[] = 'gs-dashboard';
        $classes[] = 'gs-chrome';
        return $classes;
    }
}
