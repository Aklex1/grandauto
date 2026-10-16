<?php
/**
 * Что нельзя отдавать из кэша.
 *
 * Кэш страниц на сайте включён, и это правильно: сборка страницы занимала
 * около полутора секунд, готовый файл отдаётся за треть. Но у нас есть
 * страницы, где на экране личное: баланс, ключи API, одноразовый код
 * входа, результат чужой генерации. Если такая страница попадёт в кэш,
 * следующий гость увидит чужое — и узнаем мы об этом от него.
 *
 * В самом плагине кэша список таких адресов уже прописан руками. Этого
 * мало: каждый новый микросервис — это новая личная страница, и однажды её
 * в тот список не добавят. Поэтому плагин говорит о себе сам — константой
 * DONOTCACHEPAGE, которую понимают все кэширующие плагины. Список адресов
 * при этом остаётся: он работает раньше, до загрузки WordPress, и экономит
 * ещё и эту работу. Здесь — страховка, а не замена.
 *
 * Важно: константа запрещает записать страницу в кэш, а не отдать уже
 * записанную. Поэтому она объявляется до вывода — на template_redirect, —
 * и файл не появляется вовсе.
 */

if (!defined('ABSPATH')) {
    exit;
}

class GS_Cache {

    public static function boot() {
        add_action('template_redirect', array(__CLASS__, 'maybe_no_cache'), 0);
        // Ответы REST персональны всегда: там и баланс, и задачи, и ключи.
        add_action('rest_api_init', array(__CLASS__, 'no_cache'), 0);
    }

    /**
     * Первый отрезок адреса. Сравниваем именно его, а не «есть ли в адресе
     * такая подстрока»: статья /api-nejroseti-besplatno/ не должна
     * попадать под правило для раздела /api/.
     */
    private static function path_head() {
        $uri = isset($_SERVER['REQUEST_URI']) ? (string) wp_unslash($_SERVER['REQUEST_URI']) : '';
        $path = trim((string) wp_parse_url($uri, PHP_URL_PATH), '/');
        if ($path === '') {
            return '';
        }
        $parts = explode('/', $path);
        return strtolower($parts[0]);
    }

    /**
     * Адреса личных страниц. Собираем из тех же реестров, по которым
     * страницы создаются, — тогда новый сервис попадает сюда сам.
     *
     * @return string[]
     */
    public static function paths() {
        return apply_filters('gs_no_cache_paths',
            array_values(array_unique(array_merge(self::private_paths(), self::service_paths()))));
    }

    /**
     * Кабинеты и вход: здесь личное видно и без разбора, кто смотрит, —
     * ключи доступа, код входа, своя история. Эти адреса в кэш не попадают
     * никогда.
     */
    public static function private_paths() {
        $paths = array('tts-login', 'tts-dashboard');

        if (class_exists('GS_Api_Page')) {
            $paths[] = GS_Api_Page::SLUG;
        }
        /**
         * Чужие плагины со своими кабинетами: их страницы тоже личные, а
         * их адреса нам взять негде.
         */
        $paths = array_merge($paths, array('pdf-online', 'showwheel'));

        return array_values(array_unique(array_filter(array_map('strtolower', $paths))));
    }

    /**
     * Страницы сервисов. Вошедшему здесь показывают баланс, и такую сборку
     * в общий кэш отдавать нельзя. Гостю же показывают «Нужен вход» и ни
     * одной личной цифры — ему готовая страница и нужна больше всех:
     * с поиска приходят именно гостем, и секунда сборки идёт в оценку
     * сайта. Поэтому запрет здесь — только для вошедшего.
     *
     * @return string[]
     */
    public static function service_paths() {
        $paths = array();
        if (class_exists('GS_Lab')) {
            foreach (GS_Lab::services() as $service) {
                if (!empty($service['slug'])) {
                    $paths[] = strtolower((string) $service['slug']);
                }
            }
        }
        // Презентации устроены так же: гостю показывают цену и форму со
        // словами «войдите», баланс и история появляются только у вошедшего.
        if (class_exists('GS_Slides_Page')) {
            $paths[] = strtolower((string) GS_Slides_Page::SLUG);
        }
        // Генератор звуков и нейрохаб раньше лежали среди личных страниц и
        // не кэшировались никому — отсюда 1,5–2,4 секунды на сборку у
        // гостя, то есть у того, кто пришёл из поиска.
        //
        // Проверено на живой странице: гостю обе отдают один и тот же
        // HTML — два запроса подряд различаются только числом счётчика
        // Метрики. Баланс в нейрохабе стоит в разметке нулём и скрыт
        // стилем, настоящее значение подставляет скрипт из своего API;
        // остаток бесплатных попыток генератора тоже приходит скриптом.
        // Личного в ответе сервера нет.
        //
        // Вошедшему страницы по-прежнему собираются заново, а гостя с
        // начатой пробой наш кэш пропускает мимо себя по его же cookie.
        $paths[] = 'neurohub';
        if (class_exists('GS_Pages')) {
            $paths[] = strtolower((string) GS_Pages::STUDIO_SLUG);
        }
        return array_values(array_unique(array_filter($paths)));
    }

    public static function maybe_no_cache() {
        if (is_admin()) {
            return;
        }
        $head = self::path_head();
        if ($head !== '' && in_array($head, self::private_paths(), true)) {
            self::no_cache();
            return;
        }
        $service = $head !== '' && in_array($head, self::service_paths(), true);
        if (!$service && class_exists('GS_Lab') && GS_Lab::current_service()) {
            // Страницу сервиса могли собрать шорткодом на другом адресе.
            $service = true;
        }
        if ($service && is_user_logged_in()) {
            self::no_cache();
        }
    }

    public static function no_cache() {
        if (!defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', true);
        }
    }
}
