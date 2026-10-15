<?php
/**
 * Один заказ — одно списание.
 *
 * Кнопку на странице мы блокируем, но это защищает только от самого
 * невинного случая. Остаются две вкладки, повторная отправка формы,
 * «обновить» на странице подтверждения, нажатие по вернувшейся из кэша
 * кнопке и прямые запросы к маршруту. Любой из них раньше заводил вторую
 * задачу у поставщика и списывал деньги второй раз: проверка баланса,
 * постановка задачи и списание шли друг за другом без всякой защёлки, и
 * два запроса спокойно проходили её вдвоём.
 *
 * Защёлка здесь двойная, потому что случаи разные:
 *
 * 1. Два запроса идут одновременно. Их разводит блокировка самой базы
 *    (GET_LOCK): она достаётся ровно одному, второй получает отказ сразу.
 *    Снимается блокировка концом запроса, поэтому зависнуть не может.
 *
 * 2. Второй запрос приходит, когда первый уже закончил. Блокировка к
 *    этому времени снята, поэтому помним отпечаток удавшегося заказа
 *    недолгое время и отказываем в точно таком же.
 *
 * Отпечаток считается по содержимому заказа, а не по сервису: два разных
 * кадра подряд — обычное дело, и мешать этому нельзя. Повтор слово в
 * слово через несколько секунд — почти наверняка вторая кнопка.
 */

if (!defined('ABSPATH')) {
    exit;
}

class GS_Once {

    /** Сколько помним удавшийся заказ. */
    const TTL = 40;

    /** Блокировки, взятые в этом запросе: снимаем их в конце. */
    private static $held = array();

    private static function fingerprint($user_id, $tag, $payload) {
        // Имя блокировки общее на весь сервер баз данных, а на нём живут
        // и чужие сайты — подмешиваем имя базы, чтобы не столкнуться.
        global $wpdb;
        return substr(md5($wpdb->dbname . '|' . (int) $user_id . '|' . (string) $tag . '|'
            . wp_json_encode($payload)), 0, 32);
    }

    /**
     * Занять заказ.
     *
     * @return bool true — можно работать, false — это повтор
     */
    public static function claim($user_id, $tag, $payload, $ttl = self::TTL) {
        global $wpdb;
        $key = self::fingerprint($user_id, $tag, $payload);

        // Нулевое ожидание: нам нужен ответ «занято», а не очередь. Если
        // база блокировок не поддерживает, ответ будет пустым — тогда
        // полагаемся на отпечаток и не мешаем человеку работать.
        $got = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', 'gs_once_' . $key));
        if ($got !== null && (int) $got !== 1) {
            return false;
        }
        if ((int) $got === 1) {
            self::$held[$key] = true;
        }

        if (get_transient('gs_once_' . $key)) {
            self::release($key);
            return false;
        }
        return true;
    }

    /**
     * Заказ удался — помним его отпечаток.
     *
     * Зовётся только после списания: неудачную попытку запоминать нельзя,
     * иначе человек, поправивший данные, получил бы отказ на ровном месте.
     */
    public static function done($user_id, $tag, $payload, $ttl = self::TTL) {
        $key = self::fingerprint($user_id, $tag, $payload);
        set_transient('gs_once_' . $key, time(), max(5, (int) $ttl));
    }

    /** Отпустить блокировку раньше конца запроса. */
    private static function release($key) {
        global $wpdb;
        if (empty(self::$held[$key])) {
            return;
        }
        unset(self::$held[$key]);
        $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', 'gs_once_' . $key));
    }

    /**
     * Озвучка в базовом плагине.
     *
     * Его код мы не трогаем, но до его обработчика можно встать фильтром:
     * маршрут у озвучки такой же беззащитный, как были наши, — две вкладки
     * заводят две задачи и списывают дважды. Отказ возвращаем здесь же,
     * и до чужого кода запрос не доходит вовсе.
     */
    public static function boot() {
        add_filter('rest_request_before_callbacks', array(__CLASS__, 'guard_tts'), 10, 3);
        add_filter('rest_request_before_callbacks', array(__CLASS__, 'guard_assistants'), 10, 3);
    }

    /**
     * ИИ-помощники — тоже отдельный плагин.
     *
     * Ответ там синхронный, но платный: повторная отправка одного и того
     * же вопроса списывает второй раз и возвращает тот же ответ. Отпечаток
     * берём по тексту обращения и помощнику.
     */
    public static function guard_assistants($response, $handler, $request) {
        if (is_wp_error($response) || !($request instanceof WP_REST_Request)) {
            return $response;
        }
        if (strpos((string) $request->get_route(), '/assistants/v1/message') === false
            || $request->get_method() !== 'POST') {
            return $response;
        }
        $user_id = get_current_user_id();
        if ($user_id <= 0) {
            return $response;
        }
        $params = $request->get_json_params();
        if (!is_array($params)) {
            $params = $request->get_params();
        }
        $text = '';
        foreach (array('message', 'text', 'question', 'prompt') as $field) {
            if (!empty($params[$field])) {
                $text = (string) $params[$field];
                break;
            }
        }
        if (trim($text) === '') {
            return $response;
        }
        $once = array($text, (string) ($params['assistant'] ?? $params['slug'] ?? ''));
        if (!self::claim($user_id, 'assistant', $once)) {
            return self::refusal();
        }
        self::done($user_id, 'assistant', $once);
        return $response;
    }

    public static function guard_tts($response, $handler, $request) {
        if (is_wp_error($response) || !($request instanceof WP_REST_Request)) {
            return $response;
        }
        if (strpos((string) $request->get_route(), '/tts/v1/generate') === false) {
            return $response;
        }
        if ($request->get_method() !== 'POST') {
            return $response;
        }
        $user_id = get_current_user_id();
        if ($user_id <= 0) {
            return $response;
        }
        $params = $request->get_json_params();
        if (!is_array($params)) {
            $params = $request->get_params();
        }
        $once = array(
            (string) ($params['text'] ?? ''),
            (string) ($params['voice'] ?? ''),
            (string) ($params['model'] ?? ''),
        );
        if (trim($once[0]) === '') {
            return $response;
        }
        if (!self::claim($user_id, 'tts', $once)) {
            return self::refusal();
        }
        // Задача пошла в работу — помним отпечаток сразу: ответ чужого
        // маршрута мы не разбираем, а повтор нужно отсечь в любом случае.
        self::done($user_id, 'tts', $once);
        return $response;
    }

    /** Понятный отказ на повтор. */
    public static function refusal() {
        return new WP_Error(
            'gs_duplicate',
            'Этот заказ уже отправлен. Подождите немного — результат появится в истории. '
                . 'Деньги за повтор не списаны.',
            array('status' => 429)
        );
    }
}
