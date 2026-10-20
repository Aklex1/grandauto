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

    public static function submit_work($request) {
        $p = (array) $request->get_json_params();
        $module = isset($p['module']) ? (int) $p['module'] : -1;
        if ($module < 0 || $module >= GC_Program::count()) {
            return new WP_Error('gc_module', 'Модуль не найден', array('status' => 400));
        }
        $body = (string) ($p['body'] ?? '');
        $links = (string) ($p['links'] ?? '');
        if (trim($body) === '' && trim($links) === '') {
            return new WP_Error('gc_empty', 'Пришлите ссылку на работу или опишите, что сделали', array('status' => 400));
        }
        $id = GC_Works::submit(get_current_user_id(), $module, $body, $links);
        GC_Works::notify_admin($id);
        return rest_ensure_response(array('ok' => true, 'id' => $id, 'message' => 'Работа ушла на проверку. Разбор придёт в кабинет.'));
    }

    public static function mark_done($request) {
        $p = (array) $request->get_json_params();
        $module = isset($p['module']) ? (int) $p['module'] : -1;
        $done = !empty($p['done']);
        if ($module < 0 || $module >= GC_Program::count()) {
            return new WP_Error('gc_module', 'Модуль не найден', array('status' => 400));
        }
        $list = GC_Students::mark_done(get_current_user_id(), $module, $done);
        return rest_ensure_response(array('ok' => true, 'done' => $list));
    }

    public static function generate($request) {
        $p = (array) $request->get_json_params();
        $size = (string) ($p['size'] ?? '1024x1024');
        $parts = array_map('intval', explode('x', $size));
        $params = array(
            'width'  => isset($parts[0]) && $parts[0] > 0 ? min(1536, $parts[0]) : 1024,
            'height' => isset($parts[1]) && $parts[1] > 0 ? min(1536, $parts[1]) : 1024,
        );
        $out = GC_Gpu::submit(get_current_user_id(), (string) ($p['prompt'] ?? ''), $params);
        if (empty($out['ok'])) {
            return new WP_Error('gc_gen', $out['message'], array('status' => 400));
        }
        return rest_ensure_response($out);
    }

    public static function jobs() {
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
