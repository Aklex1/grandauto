<?php
/**
 * Примеры к модулям.
 *
 * Курс без куратора держится на показе: словами «кадр выглядит дёшево»
 * ничего не объяснишь, нужен сам кадр — и рядом второй, сделанный
 * иначе. Поэтому к каждому модулю идут примеры, собранные теми же
 * моделями, которыми ученик работает в мастерской: что он видит в
 * уроке, то и повторит руками.
 *
 * Что именно показывать, описано в программе (поле examples у модуля):
 * вид, заголовок, пояснение и запрос к модели. Сами файлы делаются
 * один раз из админки и ложатся в медиатеку — ученику они приходят
 * готовыми, без единого обращения к моделям и без расхода на каждый
 * просмотр.
 *
 * Кадры и ролики считаются не сразу: задание уходит в общую очередь и
 * возвращается через полминуты-минуту. Тексты приходят сразу — за ними
 * чат-модель, а не очередь.
 */

if (!defined('ABSPATH')) {
    exit;
}

class GC_Examples {

    const OPT = 'gc_examples';

    /* ---------------------------------------------------------------------
     * Что должно быть
     * ------------------------------------------------------------------ */

    /** @return array<int, array> описания примеров модуля из программы */
    public static function specs($course, $module) {
        $m = GC_Program::module($module, $course);
        if (!$m || empty($m['examples'])) {
            return array();
        }
        $out = array();
        foreach ((array) $m['examples'] as $spec) {
            if (!empty($spec['key'])) {
                $out[(string) $spec['key']] = $spec;
            }
        }
        return $out;
    }

    public static function spec($course, $module, $key) {
        $specs = self::specs($course, $module);
        return isset($specs[(string) $key]) ? $specs[(string) $key] : null;
    }

    /* ---------------------------------------------------------------------
     * Что уже сделано
     * ------------------------------------------------------------------ */

    public static function saved() {
        $all = get_option(self::OPT, array());
        return is_array($all) ? $all : array();
    }

    /** Готовые примеры модуля, в порядке программы. */
    public static function get($course, $module) {
        $all = self::saved();
        $have = isset($all[$course][(int) $module]) ? (array) $all[$course][(int) $module] : array();
        $out = array();
        foreach (self::specs($course, $module) as $key => $spec) {
            if (empty($have[$key]) || empty($have[$key]['ready'])) {
                continue;
            }
            $out[] = array_merge($spec, (array) $have[$key]);
        }
        return $out;
    }

    /** Состояние одного примера: нет, считается, готов. */
    public static function state($course, $module, $key) {
        $all = self::saved();
        $row = isset($all[$course][(int) $module][(string) $key])
            ? (array) $all[$course][(int) $module][(string) $key] : array();
        if (!empty($row['ready'])) {
            return 'готов';
        }
        if (!empty($row['job'])) {
            $job = GC_Gpu::job((int) $row['job']);
            if ($job && $job['status'] === 'failed') {
                return 'не вышло: ' . mb_substr((string) $job['error'], 0, 60);
            }
            /*
             * Задание посчиталось, а пример остался «считается»: файл
             * есть, а до записи дело не дошло — перезапуск по дороге,
             * обрыв запроса, чей угодно сбой. Ставим его на место прямо
             * здесь, а не считаем заново: вторая генерация стоит денег
             * и даёт другой кадр.
             */
            if ($job && $job['status'] === 'done' && !empty($job['result_url'])) {
                self::attach(array('course' => $course, 'module' => (int) $module, 'key' => (string) $key),
                    (string) $job['result_url']);
                return 'готов';
            }
            return 'считается';
        }
        return 'нет';
    }

    public static function row($course, $module, $key) {
        $all = self::saved();
        return isset($all[$course][(int) $module][(string) $key])
            ? (array) $all[$course][(int) $module][(string) $key] : array();
    }

    private static function put($course, $module, $key, $row) {
        $all = self::saved();
        if (!isset($all[$course]) || !is_array($all[$course])) {
            $all[$course] = array();
        }
        if (!isset($all[$course][(int) $module]) || !is_array($all[$course][(int) $module])) {
            $all[$course][(int) $module] = array();
        }
        $all[$course][(int) $module][(string) $key] = $row;
        update_option(self::OPT, $all, false);
    }

    public static function forget($course, $module, $key) {
        $all = self::saved();
        unset($all[$course][(int) $module][(string) $key]);
        update_option(self::OPT, $all, false);
    }

    /* ---------------------------------------------------------------------
     * Изготовление
     * ------------------------------------------------------------------ */

    /**
     * Сделать пример.
     *
     * @return array{ok:bool,state:string,message:string}
     */
    public static function make($course, $module, $key) {
        $spec = self::spec($course, $module, $key);
        if (!$spec) {
            return array('ok' => false, 'state' => '', 'message' => 'Такого примера нет в программе');
        }
        $kind = (string) ($spec['kind'] ?? 'image');

        if ($kind === 'text') {
            return self::make_text($course, $module, $key, $spec);
        }
        return self::make_media($course, $module, $key, $spec);
    }

    /**
     * Текстовый пример.
     *
     * Чат отвечает сразу, и ждать его в очереди незачем. Просим не
     * «написать пример», а показать работу целиком: бриф, результат и
     * разбор — иначе получается красивый текст, по которому ничему не
     * научишься.
     */
    private static function make_text($course, $module, $key, $spec) {
        if (!class_exists('GS_Provider')) {
            return array('ok' => false, 'state' => '', 'message' => 'Плагин сервисов выключен');
        }
        $system = 'Ты практик, который зарабатывает на нейросетях и показывает ученику свою работу. '
                . 'Пиши по-русски, без воды, без восклицаний и без обращений к читателю. '
                . 'Markdown: подзаголовки через ###, списки через дефис. Не упоминай, какой моделью сделан текст.';
        $res = GS_Provider::chat($system, (string) $spec['prompt'], array('timeout' => 120));
        if (empty($res['ok']) || trim((string) $res['content']) === '') {
            return array('ok' => false, 'state' => '', 'message' => (string) ($res['message'] ?: 'модель не ответила'));
        }
        self::put($course, $module, $key, array(
            'kind'    => 'text',
            'ready'   => 1,
            'text'    => wp_kses_post((string) $res['content']),
            'made_at' => current_time('mysql'),
        ));
        return array('ok' => true, 'state' => 'готов', 'message' => 'Текст готов');
    }

    /**
     * Кадр или ролик.
     *
     * Ставим обычное задание в ту же очередь, что и у учеников: когда
     * оно закончится, GC_Gpu::finish() позовёт attach() и файл встанет
     * на место. Так примеры считаются тем же кодом, который мы и так
     * чиним, а не второй его копией.
     */
    private static function make_media($course, $module, $key, $spec) {
        $kind = (string) ($spec['kind'] ?? 'image') === 'video' ? 'video' : 'image';
        $size = (string) ($spec['size'] ?? '1024x1024');
        $parts = array_map('intval', explode('x', $size));
        $params = array(
            'width'   => isset($parts[0]) && $parts[0] > 0 ? $parts[0] : 1024,
            'height'  => isset($parts[1]) && $parts[1] > 0 ? $parts[1] : 1024,
            'example' => array('course' => (string) $course, 'module' => (int) $module, 'key' => (string) $key),
        );
        // Ролик из готового кадра: оживляем пример соседнего модуля, а не
        // случайную картинку — ученик видит ту же сцену в движении.
        if (!empty($spec['from'])) {
            $from = self::row($course, (int) ($spec['from'][0] ?? 0), (string) ($spec['from'][1] ?? ''));
            if (!empty($from['attachment'])) {
                $params['reference'] = (int) $from['attachment'];
            }
        }

        $out = GC_Gpu::submit(get_current_user_id(), (string) $spec['prompt'], $params, $kind, 'kie');
        if (empty($out['ok'])) {
            return array('ok' => false, 'state' => '', 'message' => (string) $out['message']);
        }
        self::put($course, $module, $key, array(
            'kind'    => $kind,
            'ready'   => 0,
            'job'     => (int) $out['job'],
            'made_at' => current_time('mysql'),
        ));
        return array('ok' => true, 'state' => 'считается', 'message' => (string) $out['message']);
    }

    /**
     * Задание закончилось: ставим файл на место примера.
     *
     * Зовётся из GC_Gpu::finish(), когда в параметрах задания лежит
     * метка примера.
     */
    public static function attach($mark, $url, $attachment_id = 0) {
        if (!is_array($mark) || empty($mark['key'])) {
            return;
        }
        $course = (string) ($mark['course'] ?? '');
        $module = (int) ($mark['module'] ?? 0);
        $key = (string) $mark['key'];
        $row = self::row($course, $module, $key);
        $row['ready'] = 1;
        $row['url'] = (string) $url;
        $row['attachment'] = (int) $attachment_id;
        $row['made_at'] = current_time('mysql');
        self::put($course, $module, $key, $row);
    }

    /** Сколько примеров готово и сколько всего — для админки. */
    public static function counts($course) {
        $total = 0;
        $ready = 0;
        for ($i = 0; $i < GC_Program::count($course); $i++) {
            foreach (self::specs($course, $i) as $key => $spec) {
                $total++;
                $ready += self::state($course, $i, $key) === 'готов' ? 1 : 0;
            }
        }
        return array('ready' => $ready, 'total' => $total);
    }
}
