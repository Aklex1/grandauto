<?php
/**
 * Маршруты кабинета.
 *
 * Всё, что делает ученик, идёт сюда: сдача работы, отметка модуля,
 * генерация и опрос её состояния. Плюс обратный вызов от арендованной
 * машины — единственный маршрут без входа, и поэтому единственный с
 * подписью.
 */

if (!defined('ABSPATH')) {
    exit;
}

class GC_Rest {

    const NS = 'genius-course/v1';

    public static function boot() {
        add_action('rest_api_init', array(__CLASS__, 'routes'));
    }

    public static function routes() {
        $student = array(__CLASS__, 'perm_student');

        register_rest_route(self::NS, '/work', array(
            'methods'             => 'POST',
            'callback'            => array(__CLASS__, 'submit_work'),
            'permission_callback' => $student,
        ));
        register_rest_route(self::NS, '/done', array(
            'methods'             => 'POST',
            'callback'            => array(__CLASS__, 'mark_done'),
            'permission_callback' => $student,
        ));
        register_rest_route(self::NS, '/gen', array(
            'methods'             => 'POST',
            'callback'            => array(__CLASS__, 'generate'),
            'permission_callback' => $student,
        ));
        register_rest_route(self::NS, '/reference', array(
            'methods'             => 'POST',
            'callback'            => array(__CLASS__, 'upload_reference'),
            'permission_callback' => $student,
        ));
        register_rest_route(self::NS, '/quiz', array(
            'methods'             => 'POST',
            'callback'            => array(__CLASS__, 'quiz'),
            'permission_callback' => $student,
        ));
        register_rest_route(self::NS, '/share', array(
            'methods'             => 'POST',
            'callback'            => array(__CLASS__, 'share'),
            'permission_callback' => $student,
        ));
        register_rest_route(self::NS, '/jobs', array(
            'methods'             => 'GET',
            'callback'            => array(__CLASS__, 'jobs'),
            'permission_callback' => $student,
        ));
        register_rest_route(self::NS, '/gpu-callback', array(
            'methods'             => 'POST',
            'callback'            => array(__CLASS__, 'gpu_callback'),
            'permission_callback' => '__return_true',
        ));
    }

    public static function perm_student() {
        return is_user_logged_in() && GC_Students::is_student();
    }

    /**
     * Курс, к которому относится запрос.
     *
     * У ученика он один — свой, и подменить его запросом нельзя: иначе
     * чужая программа решала бы, сколько у него модулей. Администратор
     * ходит по кабинетам обоих курсов, поэтому ему курс сообщает сама
     * страница.
     */
    private static function course($request) {
        $user_id = get_current_user_id();
        if (current_user_can('manage_options')) {
            $p = (array) $request->get_json_params();
            $asked = isset($p['course']) ? sanitize_key((string) $p['course']) : '';
            if ($asked === '') {
                $asked = sanitize_key((string) $request->get_param('course'));
            }
            if (GC_Courses::exists($asked)) {
                return $asked;
            }
        }
        return GC_Students::course($user_id);
    }

    /**
     * Оплачен ли доступ к работе и мастерской.
     *
     * Проверка стоит здесь, а не только в разметке: кнопку можно не
     * показывать, а запрос всё равно отправить руками.
     */
    private static function paid_guard($course) {
        if (GC_Courses::tools_open(get_current_user_id(), $course)) {
            return null;
        }
        return new WP_Error('gc_unpaid', 'Эта часть кабинета открывается после оплаты обучения.', array('status' => 402));
    }

    public static function submit_work($request) {
        $p = (array) $request->get_json_params();
        $course = self::course($request);
        $guard = self::paid_guard($course);
        if ($guard) {
            return $guard;
        }
        $module = isset($p['module']) ? (int) $p['module'] : -1;
        if ($module < 0 || $module >= GC_Program::count($course)) {
            return new WP_Error('gc_module', 'Модуль не найден', array('status' => 400));
        }
        $body = (string) ($p['body'] ?? '');
        $links = (string) ($p['links'] ?? '');
        if (trim($body) === '' && trim($links) === '') {
            return new WP_Error('gc_empty', 'Пришлите ссылку на работу или опишите, что сделали', array('status' => 400));
        }
        $id = GC_Works::submit(get_current_user_id(), $module, $body, $links, !empty($p['shared']));
        GC_Works::notify_admin($id);
        // Быстрый разбор считается фоном: ученику не надо ждать минуту
        // в форме, а первый ответ он увидит, обновив страницу.
        GC_Review::queue($id);
        return rest_ensure_response(array(
            'ok'      => true,
            'id'      => $id,
            'message' => GC_Review::available()
                ? 'Работа ушла на проверку. Через минуту здесь появится быстрый разбор, следом — разбор от проверяющего.'
                : 'Работа ушла на проверку. Разбор придёт в кабинет.',
        ));
    }

    public static function mark_done($request) {
        $p = (array) $request->get_json_params();
        $course = self::course($request);
        $module = isset($p['module']) ? (int) $p['module'] : -1;
        $done = !empty($p['done']);
        if ($module < 0 || $module >= GC_Program::count($course)) {
            return new WP_Error('gc_module', 'Модуль не найден', array('status' => 400));
        }
        if (!GC_Courses::module_open(get_current_user_id(), $module, $course)) {
            return new WP_Error('gc_unpaid', 'Модуль откроется после оплаты обучения.', array('status' => 402));
        }
        $list = GC_Students::mark_done(get_current_user_id(), $module, $done);
        return rest_ensure_response(array('ok' => true, 'done' => $list));
    }

    public static function generate($request) {
        $p = (array) $request->get_json_params();
        $guard = self::paid_guard(self::course($request));
        if ($guard) {
            return $guard;
        }
        $size = (string) ($p['size'] ?? '1024x1024');
        $parts = array_map('intval', explode('x', $size));
        $kind = ($p['kind'] ?? 'image') === 'video' ? 'video' : 'image';

        /*
         * Границы ставим здесь, а не в заготовке запроса: ученик меняет
         * настройки руками, и кадр 4096×4096 на 150 шагов займёт
         * видеокарту на полчаса, а очередь общая.
         */
        $params = array(
            'width'    => isset($parts[0]) && $parts[0] > 0 ? max(256, min(1536, $parts[0])) : 1024,
            'height'   => isset($parts[1]) && $parts[1] > 0 ? max(256, min(1536, $parts[1])) : 1024,
            'steps'    => max(6, min(60, (int) ($p['steps'] ?? 28))),
            'cfg'      => max(1, min(14, (float) ($p['cfg'] ?? 6.5))),
            'seed'     => max(0, min(2147483646, (int) ($p['seed'] ?? 0))),
            'negative' => sanitize_textarea_field((string) ($p['negative'] ?? '')),
            'frames'   => max(8, min(($kind === 'video' ? 129 : 16), (int) ($p['frames'] ?? 49))),
            'fps'      => max(6, min(30, (int) ($p['fps'] ?? 16))),
            'strength' => max(0.05, min(1, (float) ($p['strength'] ?? 0.75))),
        );

        $reference = (int) ($p['reference'] ?? 0);
        if ($reference > 0) {
            // Чужую картинку по номеру не берём: только то, что этот же
            // человек загрузил сам.
            $author = (int) get_post_field('post_author', $reference);
            if ($author === get_current_user_id() || current_user_can('manage_options')) {
                $params['reference'] = $reference;
            }
        }

        $out = GC_Gpu::submit(get_current_user_id(), (string) ($p['prompt'] ?? ''), $params, $kind);
        if (empty($out['ok'])) {
            return new WP_Error('gc_gen', $out['message'], array('status' => 400));
        }
        return rest_ensure_response($out);
    }

    /**
     * Загрузка референса.
     *
     * Файл кладём в медиатеку сайта за учеником: он остаётся у человека
     * в кабинете и переживает перезапуск машины, а на саму машину
     * уезжает уже перед заданием.
     */
    public static function upload_reference($request) {
        $guard = self::paid_guard(self::course($request));
        if ($guard) {
            return $guard;
        }
        $files = $request->get_file_params();
        if (empty($files['file']['tmp_name'])) {
            return new WP_Error('gc_file', 'Файл не пришёл', array('status' => 400));
        }
        $file = $files['file'];
        $type = wp_check_filetype((string) $file['name']);
        if (!in_array((string) $type['type'], array('image/jpeg', 'image/png', 'image/webp'), true)) {
            return new WP_Error('gc_type', 'Референс принимаем картинкой: jpg, png или webp', array('status' => 400));
        }
        if ((int) $file['size'] > 12 * MB_IN_BYTES) {
            return new WP_Error('gc_size', 'Слишком большой файл: до 12 МБ', array('status' => 400));
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $id = media_handle_sideload(array(
            'name'     => sanitize_file_name((string) $file['name']),
            'tmp_name' => (string) $file['tmp_name'],
        ), 0, 'Референс ученика');
        if (is_wp_error($id)) {
            return new WP_Error('gc_save', $id->get_error_message(), array('status' => 500));
        }
        wp_update_post(array('ID' => (int) $id, 'post_author' => get_current_user_id()));

        return rest_ensure_response(array(
            'ok'   => true,
            'id'   => (int) $id,
            'url'  => wp_get_attachment_image_url((int) $id, 'medium'),
            'name' => sanitize_file_name((string) $file['name']),
        ));
    }

    /**
     * Мини-проверка модуля.
     *
     * Ответы сверяет сервер: правильные варианты в браузер не уезжают,
     * иначе их видно в коде страницы.
     */
    public static function quiz($request) {
        $p = (array) $request->get_json_params();
        $course = self::course($request);
        $module = isset($p['module']) ? (int) $p['module'] : -1;
        if ($module < 0 || $module >= GC_Program::count($course)) {
            return new WP_Error('gc_module', 'Модуль не найден', array('status' => 400));
        }
        if (!GC_Courses::module_open(get_current_user_id(), $module, $course)) {
            return new WP_Error('gc_unpaid', 'Модуль откроется после оплаты обучения.', array('status' => 402));
        }
        $answers = isset($p['answers']) && is_array($p['answers']) ? array_map('intval', $p['answers']) : array();
        $out = GC_Quiz::check($course, $module, $answers);
        if ($out['total'] === 0) {
            return new WP_Error('gc_quiz', 'У этого модуля нет проверки', array('status' => 400));
        }
        GC_Quiz::save(get_current_user_id(), $course, $module, $out['score'], $out['total']);
        return rest_ensure_response(array('ok' => true) + $out);
    }

    /**
     * Показать работу другим ученикам или убрать из галереи.
     *
     * Чужое не трогаем: и кадр, и работа проверяются на владельца.
     */
    public static function share($request) {
        $p = (array) $request->get_json_params();
        $kind = ($p['kind'] ?? 'job') === 'work' ? 'work' : 'job';
        $id = (int) ($p['id'] ?? 0);
        $on = !empty($p['on']);
        $user_id = get_current_user_id();

        if ($kind === 'job') {
            $job = GC_Gpu::job($id);
            if (!$job || (int) $job['user_id'] !== $user_id) {
                return new WP_Error('gc_own', 'Это не ваша работа', array('status' => 403));
            }
            if ($on && (string) $job['status'] !== 'done') {
                return new WP_Error('gc_not_done', 'Показывать нечего: задание ещё считается', array('status' => 400));
            }
            GC_Gpu::set_shared($id, $on);
        } else {
            $work = GC_Works::get($id);
            if (!$work || (int) $work['user_id'] !== $user_id) {
                return new WP_Error('gc_own', 'Это не ваша работа', array('status' => 403));
            }
            GC_Works::set_shared($id, $on);
        }
        return rest_ensure_response(array(
            'ok'      => true,
            'on'      => $on,
            'message' => $on
                ? 'Работа появится в галерее курса'
                : 'Убрали из галереи',
        ));
    }

    public static function jobs() {
        // Спрашиваем машину прямо здесь: иначе готовый кадр появится
        // только после очередного прохода расписания, и ученик минуту
        // смотрит на «считается», хотя всё давно посчитано.
        GC_Gpu::refresh_user(get_current_user_id());
        $jobs = GC_Gpu::for_user(get_current_user_id());
        return rest_ensure_response(array(
            'ok'     => true,
            'html'   => GC_Cabinet::jobs_markup($jobs),
            'busy'   => (bool) array_filter($jobs, function ($j) {
                return in_array($j['status'], array('queued', 'running'), true);
            }),
        ));
    }

    /**
     * Результат от машины.
     *
     * Без входа, поэтому проверяем подпись: ключ знает только машина, и
     * подписан им номер конкретного задания. Без этого прислать «готовый
     * кадр» мог бы кто угодно, кто угадает номер.
     */
    public static function gpu_callback($request) {
        $p = (array) $request->get_json_params();
        $job_id = isset($p['job']) ? (int) $p['job'] : 0;
        $sign = (string) ($p['sign'] ?? '');
        if ($job_id <= 0 || !hash_equals(GC_Gpu::sign($job_id), $sign)) {
            return new WP_Error('gc_sign', 'Подпись не сошлась', array('status' => 403));
        }
        if (!empty($p['error'])) {
            GC_Gpu::fail($job_id, (string) $p['error']);
            return rest_ensure_response(array('ok' => true));
        }
        if (empty($p['image'])) {
            return new WP_Error('gc_empty', 'Нет картинки', array('status' => 400));
        }
        GC_Gpu::finish($job_id, (string) $p['image']);
        return rest_ensure_response(array('ok' => true));
    }
}
