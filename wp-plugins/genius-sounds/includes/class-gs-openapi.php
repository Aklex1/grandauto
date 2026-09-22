<?php
/**
 * Описание API в машинном виде: OpenAPI и коллекция Postman.
 *
 * Документация, написанная руками, расходится с кодом на второй неделе:
 * поменяли цену — в описании осталась старая, добавили операцию — о ней
 * никто не узнал. Поэтому спецификация собирается из того же реестра
 * операций, по которому API и работает, и всегда показывает то, что есть
 * на самом деле.
 *
 * Отдаём два формата. OpenAPI разработчик скармливает генератору клиента
 * или своей среде; коллекция Postman нужна тем, кто сначала щёлкает
 * запросы руками и только потом пишет код. Второй путь встречается чаще,
 * чем принято думать.
 *
 * Оба адреса открыты без ключа: спецификация — это не данные, а описание
 * входа. Прятать её значит заставлять человека регистрироваться, чтобы
 * узнать, подходит ли ему сервис.
 */

if (!defined('ABSPATH')) {
    exit;
}

class GS_Openapi {

    const VERSION = '1.0.0';

    public static function boot() {
        add_action('rest_api_init', array(__CLASS__, 'register_routes'));
    }

    public static function register_routes() {
        register_rest_route(GS_Api::NS, '/openapi.json', array(
            'methods'             => 'GET',
            'callback'            => array(__CLASS__, 'handle_openapi'),
            'permission_callback' => '__return_true',
        ));
        register_rest_route(GS_Api::NS, '/postman.json', array(
            'methods'             => 'GET',
            'callback'            => array(__CLASS__, 'handle_postman'),
            'permission_callback' => '__return_true',
        ));
    }

    public static function handle_openapi($request) {
        return rest_ensure_response(self::spec());
    }

    public static function handle_postman($request) {
        return rest_ensure_response(self::postman());
    }

    private static function base() {
        return untrailingslashit(rest_url(GS_Api::NS));
    }

    /**
     * Операции из реестра — в виде, удобном для описания.
     *
     * @return array<int,array>
     */
    private static function operations() {
        $out = array();
        foreach (GS_Api::available_services() as $id => $service) {
            $out[] = array(
                'id'     => $id,
                'title'  => (string) $service['title'],
                'about'  => (string) $service['about'],
                'input'  => (array) $service['input'],
                'result' => (string) $service['result'],
                'price'  => GS_Api::price($id),
                'hint'   => GS_Api::price_hint($id),
            );
        }
        return $out;
    }

    /**
     * Пример тела запроса под конкретную операцию.
     *
     * Пример важнее схемы: по нему запрос собирают за минуту, а по схеме
     * с oneOf на тринадцать вариантов — за полчаса.
     */
    private static function example($op) {
        $body = array('service' => $op['id']);
        foreach ($op['input'] as $field => $rule) {
            if ($rule !== 'required') {
                continue;
            }
            switch ($field) {
                case 'image_url':
                    $body[$field] = 'https://example.com/photo.jpg';
                    break;
                case 'audio_url':
                    $body[$field] = 'https://example.com/record.mp3';
                    break;
                case 'video_url':
                    $body[$field] = 'https://example.com/clip.mp4';
                    break;
                case 'text':
                    $body[$field] = 'Текст, который нужно озвучить';
                    break;
                case 'prompt':
                    $body[$field] = 'Опишите, что нужно получить';
                    break;
                case 'seconds':
                    $body[$field] = 5;
                    break;
                default:
                    $body[$field] = 'значение';
            }
        }
        return $body;
    }

    /**
     * Человеческое описание входа операции: поле, обязательность, смысл.
     */
    private static function input_text($op) {
        $parts = array();
        foreach ($op['input'] as $field => $rule) {
            $parts[] = sprintf('`%s` — %s', $field, $rule === 'required' ? 'обязательно' : 'необязательно');
        }
        return $parts ? implode(', ', $parts) : 'без параметров';
    }

    public static function spec() {
        $ops = self::operations();

        $rows = array();
        $examples = array();
        foreach ($ops as $op) {
            $rows[] = sprintf('| `%s` | %s | %s | %s | %s ₽ |',
                $op['id'], $op['title'], self::input_text($op), $op['result'],
                rtrim(rtrim(number_format($op['price'], 2, ',', ' '), '0'), ','));
            $examples[$op['id']] = array(
                'summary' => $op['title'],
                'value'   => self::example($op),
            );
        }

        $description = "HTTP API нейросетей: изображения, видео, звук и речь.\n\n"
            . "Как это работает: вы отправляете `POST /generate` с названием операции и её входными "
            . "данными, в ответ приходит `task_id`. Тяжёлые модели не отвечают в том же соединении — "
            . "состояние задачи спрашивайте у `GET /tasks/{task_id}` или укажите `callback_url`, "
            . "и сервис постучится к вам сам, когда результат будет готов.\n\n"
            . "Оплата за запуск, без абонплаты. При выпуске первого ключа на баланс начисляется "
            . GS_Api_Keys::trial_amount() . " ₽ на пробу. Ограничение частоты — "
            . GS_Api::RATE_PER_MINUTE . " запросов в минуту на ключ.\n\n"
            . "Для чата есть отдельная пара маршрутов в формате OpenAI: в клиентской библиотеке "
            . "достаточно поменять `base_url` и ключ.\n\n"
            . "### Операции\n\n"
            . "| Операция | Что делает | Вход | Результат | Цена |\n"
            . "|---|---|---|---|---|\n"
            . implode("\n", $rows);

        $service_enum = array_map(function ($op) {
            return $op['id'];
        }, $ops);

        return array(
            'openapi' => '3.1.0',
            'info' => array(
                'title'       => 'Genius-bot API',
                'version'     => self::VERSION,
                'description' => $description,
                'contact'     => array('url' => GS_Api_Page::get_url()),
            ),
            'servers' => array(
                array('url' => self::base(), 'description' => 'Основной сервер'),
            ),
            'security' => array(array('bearerAuth' => array())),
            'tags' => array(
                array('name' => 'Операции', 'description' => 'Запуск задач и получение результата'),
                array('name' => 'Счёт', 'description' => 'Баланс и список операций'),
                array('name' => 'Чат', 'description' => 'Маршруты в формате OpenAI'),
            ),
            'paths' => array(
                '/services' => array(
                    'get' => array(
                        'tags' => array('Счёт'),
                        'summary' => 'Список операций и цен',
                        'description' => 'Открыт без ключа: по нему видно, что умеет сервис и сколько это стоит.',
                        'security' => array(),
                        'responses' => array(
                            '200' => array(
                                'description' => 'Список операций',
                                'content' => array('application/json' => array(
                                    'schema' => array('$ref' => '#/components/schemas/Services'),
                                )),
                            ),
                        ),
                    ),
                ),
                '/balance' => array(
                    'get' => array(
                        'tags' => array('Счёт'),
                        'summary' => 'Остаток на балансе',
                        'responses' => array(
                            '200' => array(
                                'description' => 'Баланс в рублях',
                                'content' => array('application/json' => array(
                                    'schema' => array('$ref' => '#/components/schemas/Balance'),
                                )),
                            ),
                            '401' => array('$ref' => '#/components/responses/Unauthorized'),
                        ),
                    ),
                ),
                '/uploads' => array(
                    'post' => array(
                        'tags' => array('Операции'),
                        'summary' => 'Загрузить файл и получить ссылку',
                        'description' => 'Нужен, когда файла нет в открытом доступе: операции принимают ссылки, '
                            . 'а не содержимое. В ответ приходит адрес, который можно передать в `image_url` '
                            . 'или `audio_url`.',
                        'requestBody' => array(
                            'required' => true,
                            'content' => array('multipart/form-data' => array(
                                'schema' => array(
                                    'type' => 'object',
                                    'required' => array('file'),
                                    'properties' => array(
                                        'file' => array('type' => 'string', 'format' => 'binary'),
                                        'kind' => array(
                                            'type' => 'string',
                                            'enum' => array('image', 'audio'),
                                            'description' => 'Если не указать, определим по типу файла.',
                                        ),
                                    ),
                                ),
                            )),
                        ),
                        'responses' => array(
                            '200' => array(
                                'description' => 'Ссылка на загруженный файл',
                                'content' => array('application/json' => array(
                                    'schema' => array('$ref' => '#/components/schemas/Upload'),
                                )),
                            ),
                            '400' => array('$ref' => '#/components/responses/BadRequest'),
                            '401' => array('$ref' => '#/components/responses/Unauthorized'),
                        ),
                    ),
                ),
                '/generate' => array(
                    'post' => array(
                        'tags' => array('Операции'),
                        'summary' => 'Запустить операцию',
                        'description' => 'Возвращает `task_id`. Состояние смотрите в `GET /tasks/{task_id}` '
                            . 'или передайте `callback_url` — на него придёт POST с тем же телом, что отдаёт '
                            . 'маршрут задачи.',
                        'requestBody' => array(
                            'required' => true,
                            'content' => array('application/json' => array(
                                'schema' => array('$ref' => '#/components/schemas/GenerateRequest'),
                                'examples' => $examples,
                            )),
                        ),
                        'responses' => array(
                            '200' => array(
                                'description' => 'Задача принята',
                                'content' => array('application/json' => array(
                                    'schema' => array('$ref' => '#/components/schemas/Accepted'),
                                )),
                            ),
                            '400' => array('$ref' => '#/components/responses/BadRequest'),
                            '401' => array('$ref' => '#/components/responses/Unauthorized'),
                            '402' => array('$ref' => '#/components/responses/NoFunds'),
                            '429' => array('$ref' => '#/components/responses/TooMany'),
                            '502' => array('$ref' => '#/components/responses/Upstream'),
                        ),
                    ),
                ),
                '/tasks/{task_id}' => array(
                    'get' => array(
                        'tags' => array('Операции'),
                        'summary' => 'Состояние задачи и результат',
                        'parameters' => array(array(
                            'name' => 'task_id', 'in' => 'path', 'required' => true,
                            'schema' => array('type' => 'string'),
                        )),
                        'responses' => array(
                            '200' => array(
                                'description' => 'Состояние задачи',
                                'content' => array('application/json' => array(
                                    'schema' => array('$ref' => '#/components/schemas/Task'),
                                )),
                            ),
                            '401' => array('$ref' => '#/components/responses/Unauthorized'),
                            '403' => array('description' => 'Задача принадлежит другому ключу'),
                            '404' => array('description' => 'Задача не найдена'),
                        ),
                    ),
                ),
                '/chat/completions' => array(
                    'post' => array(
                        'tags' => array('Чат'),
                        'summary' => 'Чат в формате OpenAI',
                        'description' => 'Тело и ответ повторяют формат OpenAI. Тариф: ' . GS_OpenAI::price_hint()
                            . '. Потоковая отдача пока не поддерживается: `stream` должен быть `false`.',
                        'requestBody' => array(
                            'required' => true,
                            'content' => array('application/json' => array(
                                'schema' => array('$ref' => '#/components/schemas/ChatRequest'),
                                'example' => array(
                                    'model' => GS_OpenAI::MODEL,
                                    'messages' => array(
                                        array('role' => 'user', 'content' => 'Привет! Что ты умеешь?'),
                                    ),
                                ),
                            )),
                        ),
                        'responses' => array(
                            '200' => array(
                                'description' => 'Ответ модели',
                                'content' => array('application/json' => array(
                                    'schema' => array('$ref' => '#/components/schemas/ChatResponse'),
                                )),
                            ),
                            '400' => array('$ref' => '#/components/responses/BadRequest'),
                            '401' => array('$ref' => '#/components/responses/Unauthorized'),
                            '402' => array('$ref' => '#/components/responses/NoFunds'),
                        ),
                    ),
                ),
                '/models' => array(
                    'get' => array(
                        'tags' => array('Чат'),
                        'summary' => 'Список моделей чата',
                        'responses' => array(
                            '200' => array('description' => 'Список в формате OpenAI'),
                            '401' => array('$ref' => '#/components/responses/Unauthorized'),
                        ),
                    ),
                ),
            ),
            'components' => array(
                'securitySchemes' => array(
                    'bearerAuth' => array(
                        'type' => 'http',
                        'scheme' => 'bearer',
                        'description' => 'Ключ выдаётся в разделе ' . GS_Api_Page::get_url()
                            . ' и показывается один раз.',
                    ),
                ),
                'responses' => array(
                    'BadRequest'   => array('description' => 'Неверные параметры запроса',
                                            'content' => array('application/json' => array(
                                                'schema' => array('$ref' => '#/components/schemas/Error')))),
                    'Unauthorized' => array('description' => 'Ключ не передан, не найден или отозван',
                                            'content' => array('application/json' => array(
                                                'schema' => array('$ref' => '#/components/schemas/Error')))),
                    'NoFunds'      => array('description' => 'Недостаточно средств на балансе',
                                            'content' => array('application/json' => array(
                                                'schema' => array('$ref' => '#/components/schemas/Error')))),
                    'TooMany'      => array('description' => 'Слишком много запросов: предел '
                                                . GS_Api::RATE_PER_MINUTE . ' в минуту'),
                    'Upstream'     => array('description' => 'Модель не приняла задачу; средства не списаны'),
                ),
                'schemas' => array(
                    'Services' => array(
                        'type' => 'object',
                        'properties' => array('services' => array(
                            'type' => 'array',
                            'items' => array('$ref' => '#/components/schemas/Service'),
                        )),
                    ),
                    'Service' => array(
                        'type' => 'object',
                        'properties' => array(
                            'service'    => array('type' => 'string', 'enum' => $service_enum),
                            'title'      => array('type' => 'string'),
                            'about'      => array('type' => 'string'),
                            'input'      => array('type' => 'object', 'additionalProperties' => array('type' => 'string')),
                            'result'     => array('type' => 'string', 'enum' => array('image', 'video', 'audio', 'text')),
                            'price'      => array('type' => 'number'),
                            'price_hint' => array('type' => 'string'),
                        ),
                    ),
                    'Balance' => array(
                        'type' => 'object',
                        'properties' => array(
                            'balance'  => array('type' => 'number', 'examples' => array(124.5)),
                            'currency' => array('type' => 'string', 'examples' => array('RUB')),
                        ),
                    ),
                    'Upload' => array(
                        'type' => 'object',
                        'properties' => array(
                            'url'      => array('type' => 'string', 'format' => 'uri'),
                            'kind'     => array('type' => 'string', 'enum' => array('image', 'audio')),
                            'duration' => array('type' => 'integer', 'description' => 'Секунды, для звука'),
                        ),
                    ),
                    'GenerateRequest' => array(
                        'type' => 'object',
                        'required' => array('service'),
                        'properties' => array(
                            'service'      => array('type' => 'string', 'enum' => $service_enum),
                            'prompt'       => array('type' => 'string', 'description' => 'Описание задачи — для операций, которые работают по тексту'),
                            'text'         => array('type' => 'string', 'description' => 'Текст для озвучки'),
                            'image_url'    => array('type' => 'string', 'format' => 'uri'),
                            'audio_url'    => array('type' => 'string', 'format' => 'uri'),
                            'video_url'    => array('type' => 'string', 'format' => 'uri'),
                            'seconds'      => array('type' => 'integer', 'description' => 'Длительность, где она применима'),
                            'callback_url' => array('type' => 'string', 'format' => 'uri',
                                                    'description' => 'Куда постучаться, когда задача готова'),
                        ),
                        'additionalProperties' => true,
                    ),
                    'Accepted' => array(
                        'type' => 'object',
                        'properties' => array(
                            'task_id' => array('type' => 'string'),
                            'service' => array('type' => 'string'),
                            'status'  => array('type' => 'string', 'examples' => array('processing')),
                            'cost'    => array('type' => 'number'),
                            'balance' => array('type' => 'number'),
                        ),
                    ),
                    'Task' => array(
                        'type' => 'object',
                        'properties' => array(
                            'task_id' => array('type' => 'string'),
                            'service' => array('type' => 'string'),
                            'status'  => array('type' => 'string', 'enum' => array('processing', 'done', 'failed')),
                            'files'   => array('type' => 'array', 'items' => array('type' => 'string', 'format' => 'uri')),
                            'text'    => array('type' => 'string', 'description' => 'Для операций, отдающих текст'),
                            'message' => array('type' => 'string'),
                            'cost'    => array('type' => 'number'),
                        ),
                    ),
                    'ChatRequest' => array(
                        'type' => 'object',
                        'required' => array('messages'),
                        'properties' => array(
                            'model'    => array('type' => 'string', 'examples' => array(GS_OpenAI::MODEL)),
                            'messages' => array(
                                'type' => 'array',
                                'items' => array(
                                    'type' => 'object',
                                    'properties' => array(
                                        'role'    => array('type' => 'string', 'enum' => array('system', 'user', 'assistant')),
                                        'content' => array('type' => 'string'),
                                    ),
                                ),
                            ),
                            'temperature' => array('type' => 'number'),
                            'max_tokens'  => array('type' => 'integer'),
                            'stream'      => array('type' => 'boolean', 'examples' => array(false)),
                        ),
                    ),
                    'ChatResponse' => array(
                        'type' => 'object',
                        'properties' => array(
                            'id'      => array('type' => 'string'),
                            'object'  => array('type' => 'string', 'examples' => array('chat.completion')),
                            'model'   => array('type' => 'string'),
                            'choices' => array('type' => 'array', 'items' => array('type' => 'object')),
                            'usage'   => array('type' => 'object'),
                            'genius'  => array(
                                'type' => 'object',
                                'description' => 'Своё поверх чужого формата: сколько списали и каким маршрутом считали',
                            ),
                        ),
                    ),
                    'Error' => array(
                        'type' => 'object',
                        'properties' => array(
                            'code'    => array('type' => 'string'),
                            'message' => array('type' => 'string'),
                            'data'    => array('type' => 'object'),
                        ),
                    ),
                ),
            ),
        );
    }

    /**
     * Коллекция Postman.
     *
     * Собираем своими руками, а не конвертером из OpenAPI: конвертер
     * раскладывает тринадцать операций в один запрос с oneOf, и человеку
     * приходится собирать тело самому. Нам же нужно, чтобы он нажал
     * «Send» и увидел ответ — по одному готовому запросу на операцию.
     */
    public static function postman() {
        $base = self::base();
        $items = array();

        $items[] = self::pm_item('Список операций и цен', 'GET', '/services', null, false);
        $items[] = self::pm_item('Баланс', 'GET', '/balance');

        $ops = array();
        foreach (self::operations() as $op) {
            $ops[] = self::pm_item(
                $op['title'] . ' (' . $op['id'] . ')',
                'POST',
                '/generate',
                self::example($op),
                true,
                $op['about'] . ' Цена: ' . $op['hint'] . '. Вход: ' . self::input_text($op) . '.'
            );
        }
        $items[] = array('name' => 'Операции', 'item' => $ops);

        $items[] = self::pm_item('Состояние задачи', 'GET', '/tasks/{{task_id}}');
        $items[] = self::pm_item('Чат в формате OpenAI', 'POST', '/chat/completions', array(
            'model' => GS_OpenAI::MODEL,
            'messages' => array(array('role' => 'user', 'content' => 'Привет! Что ты умеешь?')),
        ));

        return array(
            'info' => array(
                'name' => 'Genius-bot API',
                'description' => 'Нейросети для фото, видео и звука. Подставьте свой ключ в переменную '
                    . 'коллекции api_key — он выдаётся в разделе ' . GS_Api_Page::get_url() . '.',
                '_postman_id' => md5($base . self::VERSION),
                'schema' => 'https://schema.getpostman.com/json/collection/v2.1.0/collection.json',
            ),
            'variable' => array(
                array('key' => 'base_url', 'value' => $base),
                array('key' => 'api_key', 'value' => '', 'type' => 'string'),
                array('key' => 'task_id', 'value' => ''),
            ),
            'auth' => array(
                'type' => 'bearer',
                'bearer' => array(array('key' => 'token', 'value' => '{{api_key}}', 'type' => 'string')),
            ),
            'item' => $items,
        );
    }

    private static function pm_item($name, $method, $path, $body = null, $auth = true, $note = '') {
        $item = array(
            'name' => $name,
            'request' => array(
                'method' => $method,
                'header' => array(),
                'url' => array(
                    'raw' => '{{base_url}}' . $path,
                    'host' => array('{{base_url}}'),
                    'path' => array_values(array_filter(explode('/', ltrim($path, '/')))),
                ),
            ),
        );
        if ($note !== '') {
            $item['request']['description'] = $note;
        }
        if (!$auth) {
            $item['request']['auth'] = array('type' => 'noauth');
        }
        if ($body !== null) {
            $item['request']['header'][] = array('key' => 'Content-Type', 'value' => 'application/json');
            $item['request']['body'] = array(
                'mode' => 'raw',
                'raw' => wp_json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
                'options' => array('raw' => array('language' => 'json')),
            );
        }
        return $item;
    }
}
