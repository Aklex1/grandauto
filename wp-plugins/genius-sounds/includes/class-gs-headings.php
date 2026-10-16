<?php
/**
 * Заголовок первого уровня там, где его потеряли.
 *
 * Обход всех страниц сайта нашёл тридцать пять адресов, где H1 либо нет
 * вовсе, либо их несколько. Половина — старые статьи, собранные
 * конструктором темы с выключенным блоком заголовка: на странице нет даже
 * видимого названия, человек попадает из поиска сразу на подзаголовок
 * «Что такое Gemini и почему он недоступен в России?». Другая половина —
 * страницы, где в редакторе каждый раздел отметили первым уровнем: у
 * промтов в стиле GTA таких заголовков шесть.
 *
 * И то и другое правим на выводе, не трогая сами записи: список адресов
 * короткий и проверяемый, а правка в редакторе конструктора рискует
 * развалить вёрстку блоков.
 */

if (!defined('ABSPATH')) {
    exit;
}

class GS_Headings {

    /**
     * Адреса, где заголовка первого уровня нет и названия не видно.
     *
     * Проверено по живым страницам: текста названия в теле нет ни в каком
     * теге, поэтому H1 с названием записи ничего не дублирует.
     */
    const ADD_H1 = array(
        '/solutions/',
        '/poryadok-okazaniya-uslug/',
        '/nastrojka-vless/',
        '/gemini-v-rossii-podrobnyj-manual-po-podklyucheniyu-i-rabote-s-servisom/',
        '/hosting-dlya-telegram-bota-kak-vybrat-i-na-chto-obratit-vnimanie/',
        '/kak-ispolzovat-chatgpt-v-rossii-zapusk-cherez-vpn-s-lokacziej-niderlandy/',
        '/kak-zapustit-claude-v-rossii-dostup-cherez-vpn-niderlandy/',
    );

    /**
     * Адреса, где название печатает тема, а в тексте H1 стоят лишние.
     *
     * Проверено по живым страницам: первым на странице идёт заголовок с
     * названием записи, и он не из текста — значит все H1 внутри текста
     * вторые по счёту, и каждый из них лишний.
     */
    const DEMOTE_ALL = array(
        '/promt-dlya-foto-v-stile-gta-kak-sozdat-effektnye-izobrazheniya/',
        '/kak-sdelat-stikery-v-nejroseti-podrobnoe-rukovodstvo-prompty/',
        '/shatgpt-prezentacziya/',
    );

    /**
     * Адреса, где название тема не печатает: первый H1 в тексте и есть
     * заголовок страницы, лишние — только те, что идут за ним.
     */
    const DEMOTE_REST = array(
        '/paybot/',
    );

    public static function boot() {
        // Поздним приоритетом: к этому времени шорткоды и конструктор уже
        // отработали, и мы видим тот же HTML, что увидит посетитель.
        add_filter('the_content', array(__CLASS__, 'fix'), 99);
    }

    public static function fix($html) {
        if (is_admin() || !is_singular() || !in_the_loop() || !is_main_query()) {
            return $html;
        }
        $path = self::path();

        if (in_array($path, self::DEMOTE_ALL, true)) {
            return self::demote($html, false);
        }
        if (in_array($path, self::DEMOTE_REST, true)) {
            return self::demote($html, true);
        }
        if (in_array($path, self::ADD_H1, true) && !preg_match('~<h1[\s>]~i', $html)) {
            $title = trim(wp_strip_all_tags((string) get_the_title()));
            if ($title === '') {
                return $html;
            }
            return '<h1 class="gs-h1">' . esc_html($title) . '</h1>' . $html;
        }
        return $html;
    }

    /**
     * Опустить лишние заголовки первого уровня на второй.
     *
     * $keep_first — оставить ли самый первый: это зависит от того, печатает
     * ли тема название страницы отдельно. Если печатает, в тексте первым
     * уровнем не должно быть ничего.
     */
    private static function demote($html, $keep_first) {
        $seen = 0;
        return (string) preg_replace_callback(
            '~<h1\b([^>]*)>(.*?)</h1>~is',
            function ($m) use (&$seen, $keep_first) {
                $seen++;
                if ($keep_first && $seen === 1) {
                    return $m[0];
                }
                return '<h2' . $m[1] . '>' . $m[2] . '</h2>';
            },
            $html
        );
    }

    private static function path() {
        $path = (string) wp_parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
        return '/' . trim($path, '/') . '/';
    }
}
