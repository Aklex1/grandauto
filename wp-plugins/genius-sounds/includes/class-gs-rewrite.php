<?php
/**
 * Свои тексты на страницах подборок.
 *
 * Описания в каталоге пришли из источника, откуда собирались звуки: у 945
 * подборок текст построен по одному шаблону и местами совпадает с чужим
 * дословно. Поиск считает такие страницы малоценными — отсюда и
 * несоответствие в отчётах: 861 показ по «звукам злой бабушки» и 18
 * переходов. Показывают, а не выбирают.
 *
 * Здесь текст пишется заново: по названию подборки, тому, что в ней
 * действительно лежит, и разделу, к которому она относится. Не «улучшение
 * стиля», а другой текст о том же предмете.
 *
 * Пишет его языковая модель через общий адаптер маршрутов — тот же, что у
 * микросервисов. Ответ разбирается как JSON и проверяется по длине: короче
 * нижней границы текст бесполезен, длиннее верхней — не поместится в
 * описание страницы и будет обрезан на полуслове.
 */

if (!defined('ABSPATH')) {
    exit;
}

class GS_Rewrite {

    /** Границы длин: описание идёт и в <meta description>, и на страницу. */
    const DESC_MIN = 140;
    const DESC_MAX = 300;
    const LONG_MIN = 400;
    const LONG_MAX = 1400;

    /** Сколько названий звуков показываем модели — чтобы текст был о деле. */
    const SAMPLE = 12;

    /**
     * Переписать тексты одной подборки.
     *
     * @return array{ok:bool,slug:string,message:string,fields:array}
     */
    public static function category($slug, $opts = array()) {
        $slug = GS_Storage::sanitize_slug((string) $slug);
        $out = array('ok' => false, 'slug' => $slug, 'message' => '', 'fields' => array());
        if ($slug === '') {
            $out['message'] = 'пустой слаг';
            return $out;
        }

        $category = GS_Catalog::get_category($slug);
        if (!$category) {
            $out['message'] = 'подборки нет';
            return $out;
        }

        $res = GS_Provider::chat_messages(self::messages($category, $opts), array(
            'temperature' => 0.85,
            'max_tokens'  => 1200,
            'timeout'     => 180,
        ));
        if (empty($res['ok'])) {
            $out['message'] = (string) $res['message'];
            $out['detail'] = (string) ($res['detail'] ?? '');
            return $out;
        }

        $fields = self::parse((string) $res['content']);
        if (!$fields) {
            $out['message'] = 'ответ не разобрался';
            return $out;
        }

        $problem = self::validate($fields);
        if ($problem !== '') {
            $out['message'] = $problem;
            return $out;
        }

        if (empty($opts['dry'])) {
            GS_Catalog::update_category($slug, $fields);
        }
        $out['ok'] = true;
        $out['fields'] = $fields;
        $out['route'] = (string) ($res['route'] ?? '');
        return $out;
    }

    /**
     * Задание модели.
     *
     * Показываем названия звуков из самой подборки: без них текст
     * получается о категории вообще («здесь собраны звуки природы»), а
     * нужен — об этой.
     */
    private static function messages($category, $opts = array()) {
        $title = (string) ($category['title'] ?? $category['slug']);
        $count = GS_Catalog::count_sounds($category);
        $section = GS_Sections::get(GS_Catalog::section_of($category));
        $names = array();
        foreach ((array) ($category['sounds'] ?? array()) as $sound) {
            $name = trim((string) ($sound['title'] ?? ''));
            if ($name !== '') {
                $names[] = $name;
            }
            if (count($names) >= self::SAMPLE) {
                break;
            }
        }

        $system = 'Ты пишешь тексты для каталога звуков на русском языке. '
            . 'Пишешь просто и по делу, как человек, который сам монтирует видео: '
            . 'без «в современном мире», без «широкий ассортимент», без восклицаний и без воды. '
            . 'Никогда не упоминаешь другие сайты и сервисы. '
            . 'Отвечаешь строго одним объектом JSON без пояснений и без разметки.';

        $user = "Подборка каталога: «{$title}».\n";
        if ($section) {
            $user .= "Раздел: {$section['menu']}.\n";
        }
        $user .= "Звуков в подборке: {$count}.\n";
        if ($names) {
            $user .= "Что внутри: " . implode('; ', $names) . ".\n";
        }
        $hint = trim((string) ($opts['hint'] ?? ''));
        if ($hint !== '') {
            // Формулировки, которыми эту подборку ищут. Нужны не для
            // «плотности ключей», а чтобы текст отвечал на тот же вопрос,
            // с которым человек пришёл.
            $user .= "Так эту тему ищут и описывают: {$hint}\n";
        }

        $want_title = !empty($opts['with_title']);

        $user .= "\nНапиши свой текст об этой подборке. Не пересказывай названия подряд — "
            . "объясни, что это за звуки, как они звучат и где их обычно ставят: монтаж видео, "
            . "игры, стримы, озвучка, подкасты. Упомяни, что файлы в MP3, слушаются онлайн и "
            . "скачиваются бесплатно, — но не отдельным лозунгом, а по ходу дела.\n\n"
            . "Верни JSON с полями:\n"
            . ($want_title
                ? '{"title": "…", "description": "…", "headline": "…", "description_2": "…"}'
                : '{"description": "…", "headline": "…", "description_2": "…"}') . "\n"
            . ($want_title
                ? "title — заголовок страницы, 40–60 знаков, с главной формулировкой запроса; "
                  . "без названия сайта и без слова «бесплатно» в начале.\n"
                : '')
            . "description — 180–280 знаков, первое предложение отвечает на вопрос «что это»; "
            . "не начинай с числа, со слова «Это» и со слова «Подборка» — "
            . "начни с самого звука или с того, ради чего его берут; первые слова у соседних "
            . "подборок должны быть разными, иначе страницы сливаются в одну.\n"
            . "headline — заголовок второго блока, 4–9 слов, без точки в конце.\n"
            . "description_2 — 600–1100 знаков, 2–3 абзаца через \\n\\n: чем эти звуки отличаются "
            . "друг от друга, как выбрать нужный и что с ним делать дальше.";

        return array(
            array('role' => 'system', 'content' => $system),
            array('role' => 'user', 'content' => $user),
        );
    }

    /**
     * Разбор ответа.
     *
     * Модель иногда оборачивает JSON в ```json — это не ошибка модели, а
     * привычка формата, и ломаться из-за неё незачем.
     */
    private static function parse($content) {
        $text = trim($content);
        $text = preg_replace('~^```(?:json)?\s*|\s*```$~u', '', $text);
        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        if ($start === false || $end === false || $end <= $start) {
            return array();
        }
        $data = json_decode(substr($text, $start, $end - $start + 1), true);
        if (!is_array($data)) {
            return array();
        }

        $fields = array();
        foreach (array('title', 'description', 'headline', 'description_2') as $key) {
            if (!isset($data[$key]) || !is_string($data[$key])) {
                continue;
            }
            $value = trim(preg_replace('~[ \t]+~u', ' ', $data[$key]));
            $value = preg_replace('~\n{3,}~u', "\n\n", $value);
            if ($value !== '') {
                $fields[$key] = $value;
            }
        }
        // Заголовок необязателен: у давно живущих подборок он уже свой.
        $required = array('description', 'headline', 'description_2');
        foreach ($required as $key) {
            if (empty($fields[$key])) {
                return array();
            }
        }
        return $fields;
    }

    private static function validate($fields) {
        $desc = mb_strlen($fields['description']);
        if ($desc < self::DESC_MIN || $desc > self::DESC_MAX) {
            return sprintf('описание %d знаков — нужно %d–%d', $desc, self::DESC_MIN, self::DESC_MAX);
        }
        $long = mb_strlen($fields['description_2']);
        if ($long < self::LONG_MIN || $long > self::LONG_MAX) {
            return sprintf('второй блок %d знаков — нужно %d–%d', $long, self::LONG_MIN, self::LONG_MAX);
        }
        if (mb_strlen($fields['headline']) > 120) {
            return 'заголовок второго блока длиннее 120 знаков';
        }
        if (isset($fields['title'])) {
            $len = mb_strlen($fields['title']);
            if ($len < 20 || $len > 70) {
                return sprintf('заголовок страницы %d знаков — нужно 20–70', $len);
            }
        }
        // Шаблонное начало — главная беда таких текстов: тысяча страниц,
        // и каждая открывается одними и теми же двумя словами. Поиск видит
        // это как один текст, размноженный по сайту, а человек — как отписку.
        if (preg_match('~^\s*(это|подборка|набор|коллекция)\b~ui', $fields['description'])) {
            return 'описание начинается с шаблонных слов';
        }
        // Чужие имена в тексте каталога недопустимы ни в каком виде.
        if (preg_match('~zvukogram|звукограм~ui', implode(' ', $fields))) {
            return 'в тексте упомянут источник';
        }
        return '';
    }
}
