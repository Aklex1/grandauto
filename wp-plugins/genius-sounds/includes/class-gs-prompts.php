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
    private static $lock = null;

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
                array('парн', 'пара ', ' пары', ' паре', ' парой', 'парочк',
                      'влюблен', 'влюблён', 'вдвоём', 'вдвоем', 'couple'),
            ),
            'semejnye' => array(
                'Семейные', 'Вся семья в кадре, в том числе с детьми',
                array('семей', 'семья', 'семьи', 'семьё', 'семье', 'family'),
            ),
            'detskie' => array(
                'Детские', 'Дети и малыши: мягкий свет, живые эмоции',
                array('детск', 'дети', 'ребен', 'ребён', 'child', 'kid', 'baby'),
            ),
            'portret' => array(
                'Портрет и аватар', 'Крупный план, аватарки для соцсетей и мессенджеров',
                array('портрет', 'аватар', 'крупный план', 'portrait', 'avatar'),
            ),
            'novogodnie' => array(
                'Новогодние и зимние', 'Ёлка, гирлянды, снег, открытки к празднику',
                array('новогодн', 'новый год', 'рождествен', 'зимн', 'зима ', 'снег',
                      'ёлк', 'елка', 'елки', 'ёлочн', 'гирлянд', 'christmas', 'winter'),
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
                array('чёрно-бел', 'черно-бел', 'чёрно бел', 'черно бел', 'монохром',
                      'чб-', 'black and white', 'b&w'),
            ),
            'avto' => array(
                'С автомобилем', 'Съёмка с машиной: город, трасса, гараж',
                array('машин', 'автомобил', 'за рулём', 'за рулем', 'салон авто',
                      'внедорожник', 'кабриолет', 'мотоцикл'),
            ),
            'trendy' => array(
                'Тренды', 'То, что сейчас разлетается: барби, корона, лицо в воде',
                array('тренд', 'барби', 'barbie', 'корон', 'лицо в воде', 'популярн', 'крут', 'классн'),
            ),
            'retush' => array(
                'Ретушь и восстановление', 'Обработка готового снимка и оживление старых фото',
                array('ретуш', 'обработк', 'восстановл', 'восстановленн', 'реставрац',
                      'цветокоррекц', 'retouch', 'restore'),
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

    /**
     * Адрес карточки по названию.
     *
     * Названия у нас русские, а латинский адрес читается и в выдаче, и в
     * рекламной ссылке, поэтому переводим буквы вручную: штатная
     * sanitize_title оставила бы кириллицу в процентах.
     */
    public static function slugify($title) {
        $map = array(
            'а'=>'a','б'=>'b','в'=>'v','г'=>'g','д'=>'d','е'=>'e','ё'=>'e','ж'=>'zh',
            'з'=>'z','и'=>'i','й'=>'y','к'=>'k','л'=>'l','м'=>'m','н'=>'n','о'=>'o',
            'п'=>'p','р'=>'r','с'=>'s','т'=>'t','у'=>'u','ф'=>'f','х'=>'h','ц'=>'c',
            'ч'=>'ch','ш'=>'sh','щ'=>'sch','ъ'=>'','ы'=>'y','ь'=>'','э'=>'e',
            'ю'=>'yu','я'=>'ya',
        );
        $t = mb_strtolower((string) $title, 'UTF-8');
        $t = strtr($t, $map);
        $t = preg_replace('~[^a-z0-9]+~u', '-', $t);
        $t = trim((string) $t, '-');
        return mb_substr((string) $t, 0, 70, 'UTF-8');
    }

    /* ---------------------------------------------------------------------
     * Подбор под фразу, с которой пришёл человек
     *
     * В объявлении Директа стоит макрос {keyword}: по клику он
     * подставляет в адрес ту ключевую фразу, по которой объявление
     * сработало. Фраза приходит длинной и рекламной — «готовые промты для
     * ии фотосессии детские новогодние», — поэтому обычный поиск по ней
     * ничего не найдёт: он требует все слова разом. Здесь мягче: служебные
     * слова выбрасываем, по остатку считаем вес и показываем лучшее сверху.
     * ------------------------------------------------------------------ */

    /** Слова, которые есть в каждом втором рекламном запросе и ничего не значат. */
    private static function noise() {
        return array(
            'промт', 'промты', 'промта', 'промтов', 'промпт', 'промпты', 'промпта', 'промптов',
            'для', 'или', 'как', 'что', 'это', 'все', 'мой', 'моя', 'свои', 'своих', 'своё', 'свое',
            'ии', 'нейросеть', 'нейросети', 'нейросетью', 'нейросетей', 'нейро', 'нейросетке',
            'нейрофотосессия', 'нейрофотосессии', 'нейрофото',
            'nano', 'banana', 'babana', 'pro', 'gemini', 'chatgpt', 'gpt', 'midjourney', 'ai',
            'готовые', 'готовый', 'готовая', 'готовых', 'готовое', 'лучшие', 'лучший', 'лучшая',
            'бесплатно', 'бесплатные', 'бесплатный', 'скачать', 'пример', 'примеры', 'примера',
            'список', 'подборка', 'подборки', 'сделать', 'создать', 'создания', 'генерации',
            'генератор', 'сгенерировать', 'русском', 'русские', 'языке', 'где', 'брать',
            'фото', 'фотка', 'фотки', 'фоток', 'фотографии', 'изображений', 'изображения',
            'картинок', 'картинки', 'картинка', 'снимок', 'кадр', 'телеграм', 'тг', 'бот',
            'онлайн', 'сайт', 'сайте', 'новые', 'крутые', 'классные', 'красивые', 'популярные',
        );
    }

    /** Значащие слова фразы. */
    public static function phrase_words($phrase) {
        $phrase = mb_strtolower(trim((string) $phrase), 'UTF-8');
        $parts = preg_split('~[^\p{L}\p{N}]+~u', $phrase, -1, PREG_SPLIT_NO_EMPTY);
        $noise = array_flip(self::noise());
        $out = array();
        foreach ((array) $parts as $w) {
            if (mb_strlen($w, 'UTF-8') < 3 || isset($noise[$w])) {
                continue;
            }
            $out[] = $w;
            if (count($out) >= 8) {
                break;
            }
        }
        return array_values(array_unique($out));
    }

    /**
     * Карточки под фразу: сначала самые подходящие.
     *
     * Окончания в русском мешают сравнивать слова целиком («детские» и
     * «детский»), поэтому сверяем по усечённой основе. Совпадение в
     * названии весит больше, чем в теле промта, а попадание в рубрику —
     * больше всего: именно рубрика и есть смысл запроса.
     */
    public static function match_phrase($phrase, $limit = 8, $rubric = '') {
        $words = self::phrase_words($phrase);
        if (!$words) {
            return array();
        }
        $want = self::detect_rubrics(implode(' ', $words));
        $pool = self::load();
        $rubric = (string) $rubric;
        if ($rubric !== '') {
            $pool = array_values(array_filter($pool, function ($i) use ($rubric) {
                return in_array($rubric, (array) ($i['rubrics'] ?? array()), true);
            }));
        }
        $hits = array();
        foreach ($pool as $item) {
            $title = mb_strtolower((string) ($item['title'] ?? ''), 'UTF-8');
            $body = $title . ' ' . mb_strtolower(
                (string) ($item['prompt'] ?? '') . ' ' . implode(' ', (array) ($item['tags'] ?? array())),
                'UTF-8'
            );
            $score = 0;
            foreach ($words as $w) {
                $len = mb_strlen($w, 'UTF-8');
                $stem = $len > 5 ? mb_substr($w, 0, $len - 2, 'UTF-8') : $w;
                if (mb_strpos($title, $stem, 0, 'UTF-8') !== false) {
                    $score += 3;
                } elseif (mb_strpos($body, $stem, 0, 'UTF-8') !== false) {
                    $score += 1;
                }
            }
            $mine = (array) ($item['rubrics'] ?? array());
            foreach ($want as $r) {
                if (in_array($r, $mine, true)) {
                    $score += 4;
                }
            }
            if ($score > 0) {
                $hits[] = array($score, $item);
            }
        }
        if (!$hits) {
            return array();
        }
        usort($hits, function ($a, $b) {
            return $b[0] <=> $a[0];
        });
        $hits = array_slice($hits, 0, max(1, (int) $limit));
        return array_map(function ($h) { return $h[1]; }, $hits);
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

    /**
     * Замок на индекс.
     *
     * Наполнение идёт в несколько потоков, а индекс — один файл: без
     * замка два запроса прочитают одно и то же, и карточка, добавленная
     * первым, пропадёт вместе с его записью. Берём замок на время
     * «прочитать — изменить — записать».
     */
    public static function lock() {
        self::ensure_dirs();
        $fh = @fopen(self::dir() . '/.lock', 'c');
        if (!$fh) {
            return false;
        }
        if (!flock($fh, LOCK_EX)) {
            fclose($fh);
            return false;
        }
        self::$lock = $fh;
        return true;
    }

    public static function unlock() {
        if (self::$lock) {
            flock(self::$lock, LOCK_UN);
            fclose(self::$lock);
            self::$lock = null;
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
            // Без поиска витрина должна выглядеть живой, а не
            // «первая тысяча по порядку загрузки». Перемешиваем — но не
            // случайно на каждый заход: иначе одна и та же карточка лезла бы
            // на вторую страницу, а кэш страницы терял бы смысл. Порядок
            // одинаков в пределах суток и меняется назавтра.
            $items = self::shuffle_daily($items);
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

    public static function log_query($query, $found, $source = 'site') {
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
            $log['totals'][$key] = array('n' => 0, 'empty' => 0, 'last' => '', 'ad' => 0);
        }
        $log['totals'][$key]['n']++;
        if ($source === 'ad') {
            // Фразы из объявлений считаем отдельно: по ним видно, за что
            // мы платим и что при этом нечем показать.
            $log['totals'][$key]['ad'] = (int) ($log['totals'][$key]['ad'] ?? 0) + 1;
        }
        if ((int) $found === 0) {
            $log['totals'][$key]['empty']++;
        }
        $log['totals'][$key]['last'] = current_time('mysql');

        array_unshift($log['recent'], array(
            'q'     => $query,
            'found' => (int) $found,
            'at'    => current_time('mysql'),
            'src'   => $source,
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
