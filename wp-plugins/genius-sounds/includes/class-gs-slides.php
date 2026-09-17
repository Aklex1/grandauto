<?php
/**
 * Genius Slides — генерация презентаций.
 *
 * Конвейер из трёх шагов: языковая модель раскладывает тему на слайды,
 * модель изображений рисует фон под каждый, GS_Pptx собирает всё в .pptx.
 *
 * Структуру просим сразу в JSON и с готовым описанием картинки для каждого
 * слайда: иначе приходится вторым проходом придумывать, что нарисовать, а
 * это лишний вызов и лишний повод разъехаться по стилю.
 *
 * Картинки — самая долгая часть (минуты на весь набор), поэтому запускаем
 * их пачкой и опрашиваем, как остальные наши сервисы, а не держим
 * соединение открытым.
 */

if (!defined('ABSPATH')) {
    exit;
}

class GS_Slides {

    const CHAT     = 'https://api.kie.ai/gemini-2.5-flash/v1/chat/completions';
    const MODEL    = 'gemini-2.5-flash';
    const API_JOBS = 'https://api.kie.ai/api/v1/jobs/createTask';
    const API_INFO = 'https://api.kie.ai/api/v1/jobs/recordInfo';
    const IMG_MODEL = 'google/nano-banana';

    const META_DECKS = 'gs_slides_decks';
    const DECKS_KEEP = 30;
    const DIR        = 'slides';

    /** Сколько слайдов разрешаем заказать. */
    const MIN_SLIDES = 4;
    const MAX_SLIDES = 14;

    /** Через сколько секунд бросаем зависшую задачу и возвращаем деньги. */
    const TIMEOUT = 900;

    /** Оформление: у каждого свой «характер» фона. */
    public static function styles() {
        return array(
            'business' => array(
                'name'  => 'Деловой',
                'hint'  => 'Строгие фоны, приглушённые тона — для отчётов и коммерческих предложений.',
                'image' => 'dark navy background, restrained corporate abstraction, subtle geometric shapes, '
                         . 'soft indigo and steel blue gradients, professional, calm',
            ),
            'tech' => array(
                'name'  => 'Технологичный',
                'hint'  => 'Неон и сетки — для продуктов, ИТ и стартапов.',
                'image' => 'dark background, neon indigo and cyan accents, glowing grid and light particles, '
                         . 'futuristic tech aesthetic, volumetric light',
            ),
            'edu' => array(
                'name'  => 'Учебный',
                'hint'  => 'Спокойные иллюстративные фоны — для лекций и защит.',
                'image' => 'dark muted background, soft illustrative shapes, gentle teal and violet gradients, '
                         . 'calm educational mood, plenty of empty space',
            ),
            'bright' => array(
                'name'  => 'Яркий',
                'hint'  => 'Насыщенные градиенты — для питчей и выступлений.',
                'image' => 'deep gradient background, saturated violet magenta and cyan, bold abstract flowing shapes, '
                         . 'energetic, high contrast',
            ),
        );
    }

    public static function style($id) {
        $all = self::styles();
        return $all[$id] ?? $all['business'];
    }

    /* ---------------------------------------------------------------------
     * Шаг 1. Структура
     * ------------------------------------------------------------------ */

    /**
     * Просит модель разложить тему на слайды.
     *
     * @return array{ok:bool,message:string,deck:array}
     */
    public static function outline($topic, $count, $audience = '', $tone = '', $source = '') {
        $count = max(self::MIN_SLIDES, min(self::MAX_SLIDES, (int) $count));
        $source = trim((string) $source);
        $from_text = $source !== '';

        $rules = array(
            'Ты — редактор презентаций. Твоя задача — разложить материал на слайды.',
            'Отвечай строго одним JSON-объектом, без markdown, без пояснений, без ```.',
            'Формат: {"title":"заголовок презентации","subtitle":"подзаголовок в 3-6 слов",',
            '"slides":[{"title":"заголовок слайда","bullets":["пункт","пункт"],"image":"english description of an abstract background"}]}',
            'Слайдов ровно ' . $count . ', считая титульный: первый элемент slides — это содержательный слайд,',
            'титул собирается из title и subtitle отдельно.',
            'На слайде 2-4 пункта, каждый — законченная мысль до 90 символов, без вводных слов.',
            'Пиши по-русски, конкретно, без канцелярита и без обещаний результата.',
            'Поле image — короткое описание АБСТРАКТНОГО фона на английском, без текста, людей и логотипов.',
        );

        if ($from_text) {
            // Когда материал принесли, выдумывать нечего: всё, чего нет
            // в тексте, окажется в презентации враньём от имени автора.
            $rules[] = 'Материал даёт пользователь. Опирайся ТОЛЬКО на него: не добавляй фактов, цифр,';
            $rules[] = 'названий и выводов, которых в тексте нет. Если материала не хватает на заданное';
            $rules[] = 'число слайдов, делай меньше слайдов, но не придумывай содержание.';
            $rules[] = 'Сохраняй порядок и логику исходника, формулировки сокращай до тезисов.';
        } else {
            $rules[] = 'Не выдумывай цифры, названия компаний и даты: если факта нет, пиши по существу без него.';
        }

        $system = implode(' ', $rules);

        if ($from_text) {
            $user = "Сделай презентацию по этому материалу.";
            if (trim($topic) !== '') {
                $user .= ' Уточнение от автора: ' . $topic . '.';
            }
            if (trim($audience) !== '') {
                $user .= ' Аудитория: ' . $audience . '.';
            }
            if (trim($tone) !== '') {
                $user .= ' Тон: ' . $tone . '.';
            }
            $user .= "\n\nМАТЕРИАЛ:\n" . $source;
        } else {
            $user = 'Тема презентации: ' . $topic . '.';
            if (trim($audience) !== '') {
                $user .= ' Аудитория: ' . $audience . '.';
            }
            if (trim($tone) !== '') {
                $user .= ' Тон: ' . $tone . '.';
            }
        }

        $res = self::chat($system, $user);
        if (!$res['ok']) {
            return array('ok' => false, 'message' => $res['message'], 'deck' => array());
        }

        $deck = self::parse_deck($res['content']);
        if (!$deck) {
            return array('ok' => false, 'message' => 'Не удалось разобрать ответ модели — попробуйте ещё раз', 'deck' => array());
        }
        return array('ok' => true, 'message' => '', 'deck' => $deck);
    }

    /** Ответ модели — JSON, но иногда завёрнутый в ```; достаём объект. */
    private static function parse_deck($content) {
        $content = trim((string) $content);
        $content = preg_replace('/^```(?:json)?|```$/mu', '', $content);
        $start = strpos($content, '{');
        $end = strrpos($content, '}');
        if ($start === false || $end === false || $end <= $start) {
            return array();
        }
        $data = json_decode(substr($content, $start, $end - $start + 1), true);
        if (!is_array($data) || empty($data['slides']) || !is_array($data['slides'])) {
            return array();
        }

        $slides = array();
        foreach ($data['slides'] as $row) {
            $title = trim(sanitize_text_field((string) ($row['title'] ?? '')));
            if ($title === '') {
                continue;
            }
            $bullets = array();
            foreach ((array) ($row['bullets'] ?? array()) as $bullet) {
                $bullet = trim(sanitize_text_field((string) $bullet));
                if ($bullet !== '') {
                    $bullets[] = mb_substr($bullet, 0, 160);
                }
            }
            $slides[] = array(
                'title'   => mb_substr($title, 0, 120),
                'bullets' => array_slice($bullets, 0, 5),
                'image'   => trim(sanitize_text_field((string) ($row['image'] ?? ''))),
            );
        }
        if (!$slides) {
            return array();
        }

        return array(
            'title'    => mb_substr(trim(sanitize_text_field((string) ($data['title'] ?? 'Презентация'))), 0, 120),
            'subtitle' => mb_substr(trim(sanitize_text_field((string) ($data['subtitle'] ?? ''))), 0, 120),
            'slides'   => $slides,
        );
    }

    /* ---------------------------------------------------------------------
     * Шаг 2. Фоны
     * ------------------------------------------------------------------ */

    /**
     * Ставит в очередь по картинке на слайд плюс одну на титул.
     *
     * @return array<int,string> Позиция слайда => идентификатор задачи
     */
    public static function start_images($deck, $style_id, $illustrations = array()) {
        $style = self::style($style_id);
        $tasks = array();

        // Фон рисуем на титул и на каждый слайд. Ключи строковые: рядом с
        // фоном на том же слайде может стоять иллюстрация, и различать их
        // по одному числовому индексу уже не выйдет.
        $tasks += self::queue_image('bg:-1', $style, (string) $deck['title'], false);
        foreach ($deck['slides'] as $i => $slide) {
            $hint = $slide['image'] !== '' ? $slide['image'] : (string) $slide['title'];
            $tasks += self::queue_image('bg:' . $i, $style, $hint, false);
            if (in_array($i, (array) $illustrations, true)) {
                $subject = self::illustration_hint($slide);
                $tasks += self::queue_image('il:' . $i, $style, $subject, true);
            }
        }
        return $tasks;
    }

    /**
     * Что рисовать рядом с текстом.
     *
     * Фону достаточно настроения, а иллюстрация должна быть про содержание
     * слайда — иначе она просто вторая абстракция и ничего не добавляет.
     */
    private static function illustration_hint($slide) {
        $parts = array($slide['title']);
        foreach ((array) $slide['bullets'] as $bullet) {
            $parts[] = $bullet;
        }
        return mb_substr(implode('. ', $parts), 0, 300);
    }

    private static function queue_image($key, $style, $hint, $is_illustration) {
        if ($is_illustration) {
            $prompt = $style['image'] . ', a single clear symbolic object illustrating this idea: ' . $hint
                . ', centred composition, vertical 3:4 frame, generous empty space around the object, '
                . 'no text, no letters, no logos, no faces';
            $size = '3:4';
        } else {
            $prompt = $style['image'] . ', ' . $hint
                . ', wide 16:9 presentation background, no text, no letters, no logos, no people, '
                . 'composition leaves the left and centre area calm for text';
            $size = '16:9';
        }

        $res = self::post(self::API_JOBS, array(
            'model' => self::IMG_MODEL,
            'input' => array(
                'prompt'        => $prompt,
                'output_format' => 'png',
                'image_size'    => $size,
            ),
        ));
        $task = (string) ($res['body']['data']['taskId'] ?? '');
        return $task !== '' ? array($key => $task) : array();
    }

    /**
     * Забирает готовые картинки.
     *
     * @return array{done:bool,images:array<int,string>}
     */
    public static function collect_images($tasks) {
        $images = array();
        $done = true;
        foreach ($tasks as $key => $task) {
            $res = self::get(self::API_INFO, array('taskId' => $task));
            $state = (string) ($res['body']['data']['state'] ?? '');
            if ($state === 'success') {
                $urls = self::result_urls($res['body']['data']);
                if ($urls) {
                    $images[$key] = $urls[0];
                }
                continue;
            }
            if ($state === 'fail') {
                // Фон не обязателен: слайд соберётся и без него.
                continue;
            }
            $done = false;
        }
        return array('done' => $done, 'images' => $images);
    }

    private static function result_urls($data) {
        $raw = $data['resultJson'] ?? ($data['result'] ?? '');
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }
        if (!is_array($raw)) {
            return array();
        }
        $urls = $raw['resultUrls'] ?? ($raw['result_urls'] ?? array());
        return array_values(array_filter((array) $urls, 'is_string'));
    }

    /* ---------------------------------------------------------------------
     * Шаг 3. Сборка файла
     * ------------------------------------------------------------------ */

    /**
     * Скачивает фоны и собирает .pptx.
     *
     * @return array{ok:bool,message:string,url:string,file:string}
     */
    public static function build($deck, $images, $user_id) {
        $dir = GS_Storage::base_dir() . '/' . self::DIR;
        if (!wp_mkdir_p($dir)) {
            return array('ok' => false, 'message' => 'Нет доступа к хранилищу', 'url' => '', 'file' => '');
        }

        $stamp = time() . '-' . wp_generate_password(6, false, false);
        $temp = array();

        $fetch = function ($url) use ($dir, $stamp, &$temp) {
            if (!is_string($url) || $url === '') {
                return '';
            }
            $body = wp_remote_retrieve_body(wp_remote_get($url, array('timeout' => 60)));
            if ($body === '') {
                return '';
            }
            // Фон кладём как JPEG: тип объявлен в пакете один, и лишние
            // форматы там только ломают совместимость.
            $path = $dir . '/tmp-' . $stamp . '-' . count($temp) . '.jpg';
            if (!self::save_jpeg($body, $path)) {
                return '';
            }
            $temp[] = $path;
            return $path;
        };

        $slides = array();
        $slides[] = array(
            'title'   => $deck['title'],
            'bullets' => $deck['subtitle'] !== '' ? array($deck['subtitle']) : array(),
            'image'   => $fetch($images['bg:-1'] ?? ''),
        );
        foreach ($deck['slides'] as $i => $slide) {
            $slides[] = array(
                'title'        => $slide['title'],
                'bullets'      => $slide['bullets'],
                'image'        => $fetch($images['bg:' . $i] ?? ''),
                'illustration' => $fetch($images['il:' . $i] ?? ''),
            );
        }

        $name = self::file_name($deck['title'], $stamp);
        $path = $dir . '/' . $name;
        $res = GS_Pptx::build($slides, $path, array(
            'title'  => $deck['title'],
            'author' => 'Genius-bot',
        ));

        foreach ($temp as $file) {
            @unlink($file);
        }
        if (!$res['ok']) {
            return array('ok' => false, 'message' => $res['message'], 'url' => '', 'file' => '');
        }

        return array(
            'ok'      => true,
            'message' => '',
            'url'     => GS_Storage::base_url() . '/' . self::DIR . '/' . $name,
            'file'    => $path,
        );
    }

    /** Картинка приходит png — переводим в jpeg, чтобы тип в пакете был один. */
    private static function save_jpeg($body, $path) {
        if (!function_exists('imagecreatefromstring')) {
            return false;
        }
        $image = @imagecreatefromstring($body);
        if (!$image) {
            return false;
        }
        $ok = imagejpeg($image, $path, 86);
        imagedestroy($image);
        return (bool) $ok;
    }

    private static function file_name($title, $stamp) {
        $slug = sanitize_title($title);
        if ($slug === '') {
            $slug = 'prezentaciya';
        }
        return mb_substr($slug, 0, 60) . '-' . $stamp . '.pptx';
    }

    /* ---------------------------------------------------------------------
     * История пользователя
     * ------------------------------------------------------------------ */

    public static function remember($user_id, $entry) {
        $rows = (array) get_user_meta($user_id, self::META_DECKS, true);
        $rows[] = $entry;
        update_user_meta($user_id, self::META_DECKS, array_slice($rows, -self::DECKS_KEEP));
    }

    public static function own_decks($user_id) {
        $rows = (array) get_user_meta($user_id, self::META_DECKS, true);
        return array_reverse(array_values(array_filter($rows, 'is_array')));
    }

    /* ---------------------------------------------------------------------
     * Вызовы поставщика
     * ------------------------------------------------------------------ */

    private static function key() {
        return trim((string) get_option('kie_tts_api_key', ''));
    }

    private static function chat($system, $user) {
        $key = self::key();
        if ($key === '') {
            return array('ok' => false, 'message' => 'Сервис не настроен', 'content' => '');
        }
        $payload = array(
            'model'    => self::MODEL,
            'stream'   => false,
            'messages' => array(
                array('role' => 'system', 'content' => $system),
                array('role' => 'user', 'content' => $user),
            ),
        );

        // Причину отказа нужно донести до вызывающего: сообщение
        // «поставщик не ответил» одинаково для сети, лимита и неверного
        // ключа, и по нему невозможно понять, что чинить.
        $last = 'поставщик не ответил';
        foreach (array(0, 5, 15) as $pause) {
            if ($pause > 0) {
                sleep($pause);
            }
            $response = wp_remote_post(self::CHAT, array(
                'timeout' => 180,
                'headers' => array('Authorization' => 'Bearer ' . $key, 'Content-Type' => 'application/json'),
                'body'    => wp_json_encode($payload),
            ));
            if (is_wp_error($response)) {
                $last = $response->get_error_message();
                continue;
            }

            $code = (int) wp_remote_retrieve_response_code($response);
            $raw = (string) wp_remote_retrieve_body($response);
            $body = json_decode($raw, true);
            if (!is_array($body)) {
                $last = 'неразборчивый ответ (HTTP ' . $code . ')';
                continue;
            }

            $inner = isset($body['code']) ? (int) $body['code'] : 200;
            if ($inner !== 200 || $code >= 400) {
                $last = (string) ($body['msg'] ?? ($body['error']['message'] ?? ('HTTP ' . $code)));
                continue;
            }

            $content = (string) ($body['choices'][0]['message']['content'] ?? '');
            if (trim($content) === '') {
                $last = 'пустой ответ модели';
                continue;
            }
            return array('ok' => true, 'message' => '', 'content' => $content);
        }
        return array('ok' => false, 'message' => self::human_error($last), 'content' => '');
    }

    /**
     * Ответ поставщика — человеку.
     *
     * Он пишет на английском и о своей инфраструктуре: «Network error» здесь
     * означает, что у него не отвечает модель, а не что у человека плохой
     * интернет. Если не перевести, посетитель будет чинить свой вайфай.
     */
    private static function human_error($raw) {
        $low = mb_strtolower((string) $raw);
        if (strpos($low, 'network error') !== false || strpos($low, 'internal') !== false
            || strpos($low, 'try again') !== false || strpos($low, 'timeout') !== false
            || strpos($low, 'maintain') !== false || strpos($low, 'unavailable') !== false) {
            return 'Сервис генерации сейчас недоступен на стороне поставщика. Деньги не списаны — попробуйте через несколько минут.';
        }
        if (strpos($low, 'unauthorized') !== false || strpos($low, 'api key') !== false) {
            return 'Генерация временно недоступна: не настроен доступ к сервису.';
        }
        if (strpos($low, 'rate limit') !== false || strpos($low, 'too many') !== false) {
            return 'Слишком много запросов подряд. Подождите минуту и попробуйте снова.';
        }
        if (strpos($low, 'пустой ответ') !== false) {
            return 'Модель вернула пустой ответ. Уточните тему и попробуйте ещё раз.';
        }
        return 'Не удалось собрать структуру: ' . $raw;
    }

    private static function post($url, $payload) {
        $key = self::key();
        if ($key === '') {
            return array('ok' => false, 'message' => 'Сервис не настроен', 'body' => array());
        }
        $response = wp_remote_post($url, array(
            'timeout' => 60,
            'headers' => array('Authorization' => 'Bearer ' . $key, 'Content-Type' => 'application/json'),
            'body'    => wp_json_encode($payload),
        ));
        return self::unpack($response);
    }

    private static function get($url, $args) {
        $key = self::key();
        if ($key === '') {
            return array('ok' => false, 'message' => 'Сервис не настроен', 'body' => array());
        }
        $response = wp_remote_get(add_query_arg($args, $url), array(
            'timeout' => 45,
            'headers' => array('Authorization' => 'Bearer ' . $key),
        ));
        return self::unpack($response);
    }

    private static function unpack($response) {
        if (is_wp_error($response)) {
            return array('ok' => false, 'message' => $response->get_error_message(), 'body' => array());
        }
        $body = json_decode((string) wp_remote_retrieve_body($response), true);
        if (!is_array($body)) {
            return array('ok' => false, 'message' => 'Неразборчивый ответ поставщика', 'body' => array());
        }
        $code = (int) ($body['code'] ?? wp_remote_retrieve_response_code($response));
        if ($code !== 200) {
            return array('ok' => false, 'message' => (string) ($body['msg'] ?? 'Отказ поставщика'), 'body' => $body);
        }
        return array('ok' => true, 'message' => '', 'body' => $body);
    }
}
