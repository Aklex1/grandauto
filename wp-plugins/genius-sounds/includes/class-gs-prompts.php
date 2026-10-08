<?php
/**
 * Каталог промтов для фото.
 *
 * Под рекламу в Директе нужна была посадочная: с поиска часть людей не
 * может открыть Телеграм, и трафик по объявлениям на канал просто терялся.
 * Поэтому промты живут и на сайте — с поиском, рубрикатором и страницей
 * на каждый промт, а в Телеграм уводит отдельная кнопка, для тех кому так
 * удобнее.
 *
 * Хранится всё так же, как каталог звуков: индекс одним JSON в хранилище
 * плагина, картинки рядом файлами. Своей таблицы не заводим — записей
 * тысячи, а не миллионы, и этот приём на сайте уже отработан.
 */

if (!defined('ABSPATH')) {
    exit;
}

class GS_Prompts {

    /** Адрес посадочной, совмещённой с каталогом. */
    const SLUG = 'katalog-promtov';

    /** Канал, из которого берём примеры. */
    const TG_CHANNEL = 'promtnanobanana7';

    const OPT_PAGE    = 'gs_prompts_page';
    const OPT_QUERIES = 'gs_prompts_queries';

    /** Сколько карточек на страницу каталога. */
    const PER_PAGE = 24;

    /** Сколько последних запросов держим в журнале поиска. */
    const QUERY_LOG = 400;

    private static $index = null;

    /* ---------------------------------------------------------------------
     * Рубрикатор
     *
     * Собран по ключам рекламной кампании: каждая рубрика — это группа
     * запросов, под которую человек приходит с поиска. Слова в `match`
     * используются и для раскладки промтов по рубрикам, и для поиска.
     * ------------------------------------------------------------------ */

    public static function rubrics() {
        return array(
            'zhenskie' => array(
                'Женские', 'Портреты и фотосессии для девушек и женщин',
                array('женск', 'девушк', 'женщин', 'girl', 'woman', 'female'),
            ),
            'muzhskie' => array(
                'Мужские', 'Кадры для мужчин: портрет, деловой, брутальный',
                array('мужск', 'мужчин', 'парн', 'man', 'male'),
            ),
            'parnye' => array(
                'Парные', 'Съёмка вдвоём: влюблённые, пары, годовщина',
                array('пар', 'парочк', 'влюблен', 'влюблён', 'couple', 'вдвоём'),
            ),
            'semejnye' => array(
                'Семейные', 'Вся семья в кадре, в том числе с детьми',
                array('семь', 'семейн', 'family'),
            ),
            'detskie' => array(
                'Детские', 'Дети и малыши: мягкий свет, живые эмоции',
                array('детск', 'дети', 'ребен', 'ребён', 'child', 'kid', 'baby'),
            ),
            'portret' => array(
                'Портрет и аватар', 'Крупный план, аватарки для соцсетей и мессенджеров',
                array('портрет', 'авы', 'аватар', 'portrait', 'avatar', 'лицо'),
            ),
            'novogodnie' => array(
                'Новогодние и зимние', 'Ёлка, гирлянды, снег, открытки к празднику',
                array('новогодн', 'новый год', 'зимн', 'зима', 'снег', 'ёлк', 'елк', 'christmas', 'winter'),
            ),
            'svadebnye' => array(
                'Свадебные', 'Свадьба, помолвка, выездная регистрация',
                array('свадеб', 'свадьб', 'wedding', 'невест', 'жених'),
            ),
            'studiya' => array(
                'Студийные', 'Студия со светом: октобокс, софтбокс, фон',
                array('студи', 'studio', 'софтбокс', 'октобокс'),
            ),
            'delovye' => array(
                'Деловые', 'Для резюме, сайта компании и деловых сетей',
                array('делов', 'бизнес', 'business', 'резюме', 'офис'),
            ),
            'cherno-beloe' => array(
                'Чёрно-белые', 'Монохром, плёночное зерно, графика света',
                array('черно', 'чёрно', 'монохром', 'black and white', 'b&w'),
            ),
            'avto' => array(
                'С автомобилем', 'Съёмка с машиной: город, трасса, гараж',
                array('машин', 'авто', 'car', 'автомобил'),
            ),
            'trendy' => array(
                'Тренды', 'То, что сейчас разлетается: барби, корона, лицо в воде',
                array('тренд', 'барби', 'barbie', 'корон', 'лицо в воде', 'популярн', 'крут', 'классн'),
            ),
            'retush' => array(
                'Ретушь и восстановление', 'Обработка готового снимка и оживление старых фото',
                array('ретуш', 'обработк', 'восстановл', 'retouch', 'restore'),
            ),
            'prazdniki' => array(
                'Праздники и события', 'День рождения, годовщина, выпускной',
                array('день рожден', 'праздник', 'годовщин', 'выпускн', 'birthday'),
            ),
        );
    }

    public static function rubric_title($key) {
        $r = self::rubrics();
        return isset($r[$key]) ? $r[$key][0] : '';
    }

    public static function rubric_lead($key) {
        $r = self::rubrics();
        return isset($r[$key]) ? $r[$key][1] : '';
    }

    /**
     * Рубрика по тексту.
     *
     * Промт может подойти сразу нескольким рубрикам — возвращаем все, по
     * которым нашлось совпадение, и первую считаем основной.
     */
    public static function detect_rubrics($text) {
        $hay = ' ' . mb_strtolower((string) $text, 'UTF-8') . ' ';
        $out = array();
        foreach (self::rubrics() as $key => $r) {
            foreach ($r[2] as $word) {
                if (mb_strpos($hay, $word, 0, 'UTF-8') !== false) {
                    $out[] = $key;
                    break;
                }
            }
        }
        return $out;
    }

    /* ---------------------------------------------------------------------
     * Хранилище
     * ------------------------------------------------------------------ */

    public static function dir() {
        return GS_Storage::base_dir() . '/prompts';
    }

    public static function url() {
        return GS_Storage::base_url() . '/prompts';
    }

    public static function img_dir() {
        return self::dir() . '/img';
    }

    public static function img_url() {
        return self::url() . '/img';
    }

    private static function index_path() {
        return self::dir() . '/index.json';
    }

    public static function ensure_dirs() {
        foreach (array(self::dir(), self::img_dir()) as $d) {
            if (!is_dir($d)) {
                wp_mkdir_p($d);
            }
        }
    }

    public static function load($fresh = false) {
        if (self::$index !== null && !$fresh) {
            return self::$index;
        }
        $path = self::index_path();
        $data = array();
        if (file_exists($path)) {
            $raw = file_get_contents($path);
            $data = json_decode((string) $raw, true);
            if (!is_array($data)) {
                $data = array();
            }
        }
        self::$index = $data;
        return $data;
    }

    public static function save($items) {
        self::ensure_dirs();
        $ok = GS_Storage::atomic_put(
            self::index_path(),
            wp_json_encode(array_values($items), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
        if ($ok) {
            self::$index = array_values($items);
        }
        return $ok;
    }

    public static function count() {
        return count(self::load());
    }

    public static function get($slug) {
        foreach (self::load() as $item) {
            if ((string) ($item['slug'] ?? '') === (string) $slug) {
                return $item;
            }
        }
        return null;
    }

    /** Сколько промтов в каждой рубрике — для счётчиков в рубрикаторе. */
    public static function rubric_counts() {
        $out = array();
        foreach (self::rubrics() as $key => $r) {
            $out[$key] = 0;
        }
        foreach (self::load() as $item) {
            foreach ((array) ($item['rubrics'] ?? array()) as $key) {
                if (isset($out[$key])) {
                    $out[$key]++;
                }
            }
        }
        return $out;
    }

    /* ---------------------------------------------------------------------
     * Поиск
     * ------------------------------------------------------------------ */

    /**
     * Отбор по строке поиска и рубрике.
     *
     * Ищем по заголовку, самому промту и меткам. Слова проверяем по
     * отдельности и требуем все: так «новогодний парный» находит кадр,
     * у которого эти слова в разных местах описания.
     */
    public static function search($query = '', $rubric = '') {
        $items = self::load();
        $query = trim((string) $query);
        $rubric = (string) $rubric;

        if ($rubric !== '') {
            $items = array_values(array_filter($items, function ($i) use ($rubric) {
                return in_array($rubric, (array) ($i['rubrics'] ?? array()), true);
            }));
        }
        if ($query === '') {
            // Без поиска и без рубрики витрина должна выглядеть живой, а не
            // «первая тысяча по порядку загрузки». Перемешиваем — но не
            // случайно на каждый заход: иначе одна и та же карточка лезла бы
            // на вторую страницу, а кэш страницы терял бы смысл. Порядок
            // одинаков в пределах суток и меняется назавтра.
            if ($rubric === '') {
                $items = self::shuffle_daily($items);
            }
            return $items;
        }

        $words = preg_split('~[\s,]+~u', mb_strtolower($query, 'UTF-8'), -1, PREG_SPLIT_NO_EMPTY);
        $words = array_slice((array) $words, 0, 8);
        $hits = array();
        foreach ($items as $item) {
            $hay = mb_strtolower(
                (string) ($item['title'] ?? '') . ' '
                . (string) ($item['prompt'] ?? '') . ' '
                . implode(' ', (array) ($item['tags'] ?? array())),
                'UTF-8'
            );
            $score = 0;
            $all = true;
            foreach ($words as $w) {
                if (mb_strpos($hay, $w, 0, 'UTF-8') === false) {
                    $all = false;
                    break;
                }
                // Совпадение в заголовке весит больше, чем в теле промта.
                $score += mb_strpos(mb_strtolower((string) ($item['title'] ?? ''), 'UTF-8'),
                    $w, 0, 'UTF-8') !== false ? 3 : 1;
            }
            if ($all) {
                $hits[] = array($score, $item);
            }
        }
        usort($hits, function ($a, $b) {
            return $b[0] <=> $a[0];
        });
        return array_map(function ($h) { return $h[1]; }, $hits);
    }

    /** Устойчивое в пределах суток перемешивание. */
    private static function shuffle_daily($items) {
        $seed = (int) current_time('Ymd');
        $keyed = array();
        foreach ($items as $i => $it) {
            // Простая и быстрая смесь номера с днём: порядок выглядит
            // случайным, но повторяем его при каждом запросе.
            $keyed[] = array(crc32($seed . '|' . (string) ($it['slug'] ?? $i)), $it);
        }
        usort($keyed, function ($a, $b) {
            return $a[0] <=> $b[0];
        });
        return array_map(function ($k) { return $k[1]; }, $keyed);
    }

    /* ---------------------------------------------------------------------
     * Журнал запросов
     *
     * Нужен не ради статистики как таковой: по нему видно, чего в каталоге
     * не хватает. Если неделю подряд ищут «промт для фото с собакой», а
     * выдача пустая — это прямое указание, что дописать.
     * ------------------------------------------------------------------ */

    public static function log_query($query, $found) {
        $query = trim(preg_replace('~\s+~u', ' ', (string) $query));
        if ($query === '' || mb_strlen($query, 'UTF-8') > 120) {
            return;
        }
        $key = mb_strtolower($query, 'UTF-8');

        $log = get_option(self::OPT_QUERIES, array());
        if (!is_array($log)) {
            $log = array();
        }
        if (empty($log['totals']) || !is_array($log['totals'])) {
            $log['totals'] = array();
        }
        if (empty($log['recent']) || !is_array($log['recent'])) {
            $log['recent'] = array();
        }

        if (empty($log['totals'][$key])) {
            $log['totals'][$key] = array('n' => 0, 'empty' => 0, 'last' => '');
        }
        $log['totals'][$key]['n']++;
        if ((int) $found === 0) {
            $log['totals'][$key]['empty']++;
        }
        $log['totals'][$key]['last'] = current_time('mysql');

        array_unshift($log['recent'], array(
            'q'     => $query,
            'found' => (int) $found,
            'at'    => current_time('mysql'),
        ));
        $log['recent'] = array_slice($log['recent'], 0, self::QUERY_LOG);

        // Словарь запросов тоже не даём расти бесконечно: держим самые
        // частые, остальное отсекаем.
        if (count($log['totals']) > 2000) {
            uasort($log['totals'], function ($a, $b) {
                return (int) $b['n'] <=> (int) $a['n'];
            });
            $log['totals'] = array_slice($log['totals'], 0, 1500, true);
        }

        update_option(self::OPT_QUERIES, $log, false);
    }

    public static function queries() {
        $log = get_option(self::OPT_QUERIES, array());
        return is_array($log) ? $log : array();
    }

    public static function reset_queries() {
        delete_option(self::OPT_QUERIES);
    }
}
