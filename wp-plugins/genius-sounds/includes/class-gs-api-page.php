<?php
/**
 * Страница документации API: описание запросов, примеры кода и выдача ключей.
 *
 * Страница живёт по адресу /api/ и намеренно сделана одностраничной:
 * разработчику удобнее держать всё перед глазами, чем ходить по разделам.
 */

if (!defined('ABSPATH')) {
    exit;
}

class GS_Api_Page {

    const OPT_PAGE = 'gs_api_page';
    const SLUG     = 'api';

    public static function register_shortcodes() {
        add_shortcode('genius_api', array(__CLASS__, 'render'));
    }

    public static function ensure_page() {
        $page_id = (int) get_option(self::OPT_PAGE);
        if ($page_id > 0 && get_post($page_id)) {
            return $page_id;
        }
        $existing = get_page_by_path(self::SLUG);
        if ($existing) {
            $page_id = (int) $existing->ID;
            wp_update_post(array(
                'ID'           => $page_id,
                'post_title'   => 'API для разработчиков',
                'post_content' => '[genius_api]',
                'post_status'  => 'publish',
            ));
        } else {
            $page_id = (int) wp_insert_post(array(
                'post_title'   => 'API для разработчиков',
                'post_content' => '[genius_api]',
                'post_status'  => 'publish',
                'post_type'    => 'page',
                'post_name'    => self::SLUG,
            ));
        }
        if ($page_id > 0) {
            update_option(self::OPT_PAGE, $page_id);
        }
        return $page_id;
    }

    public static function get_url() {
        $pid = (int) get_option(self::OPT_PAGE);
        $url = $pid > 0 ? get_permalink($pid) : '';
        return $url ? $url : home_url('/' . self::SLUG . '/');
    }

    public static function is_page() {
        $pid = (int) get_option(self::OPT_PAGE);
        return $pid > 0 && is_page($pid);
    }

    /* ---------------------------------------------------------------------
     * Разметка
     * ------------------------------------------------------------------ */

    public static function render() {
        // Страница-документация большая, и ронять из-за неё весь сайт нельзя:
        // при любой ошибке отдаём короткую заглушку вместо белого экрана.
        try {
            return self::render_page();
        } catch (Throwable $e) {
            error_log('genius-sounds: страница API не отрисована — ' . $e->getMessage());
            return '<p>Документация временно недоступна. Напишите нам — пришлём описание API письмом.</p>';
        }
    }

    private static function render_page() {
        $logged = is_user_logged_in();
        $base   = rest_url(GS_Api::NS);
        $keys   = $logged ? GS_Api_Keys::for_user(get_current_user_id()) : array();

        ob_start();
        ?>
        <div class="gs-wrap gs-studio gs-api">
            <nav class="gs-breadcrumbs" aria-label="Хлебные крошки">
                <a href="<?php echo esc_url(home_url('/')); ?>">Главная</a>
                <span aria-hidden="true">/</span>
                <span class="gs-breadcrumbs__current">API для разработчиков</span>
            </nav>

            <section class="gs-hero gs-hero--studio">
                <span class="gs-hero__badge">HTTP API</span>
                <h1 class="gs-hero__title">API для разработчиков: нейросети в вашем сервисе</h1>
                <p class="gs-hero__lead">
                    Оживление фото, говорящий аватар, редактирование картинок, звуки и озвучка —
                    те же инструменты, что и на сайте, только вызываются из вашего кода.
                    Один ключ, один формат запроса, оплата с общего баланса аккаунта.
                </p>
                <div class="gs-hero__meta">
                    <span class="gs-chip gs-chip--ok">без абонплаты — платите за запуск</span>
                    <span class="gs-chip">REST + JSON</span>
                    <span class="gs-chip">вебхук о готовности</span>
                </div>
            </section>

            <?php echo self::render_keys($logged, $keys); // phpcs:ignore WordPress.Security.EscapeOutput ?>

            <section class="gs-api__section" id="start">
                <h2 class="gs-section-title">Быстрый старт</h2>
                <p class="gs-api__text">
                    Все запросы идут на <code><?php echo esc_html($base); ?></code>, ключ передаётся
                    заголовком <code>Authorization: Bearer &lt;ключ&gt;</code>. Ответы — JSON в UTF-8.
                    Схема одна для всех операций: поставили задачу, получили <code>task_id</code>,
                    забрали результат по нему.
                </p>
                <?php echo self::render_code('Запустить оживление фото', self::sample_quickstart($base)); ?>
                <p class="gs-api__text">
                    В ответе — <code>task_id</code>, списанная сумма и остаток баланса.
                    Статус запрашивайте раз в 5–10 секунд: картинка обычно готова за 10–40 секунд,
                    видео и аватар — за 1–5 минут.
                </p>
                <?php echo self::render_code('Ответ', self::sample_response()); ?>
            </section>

            <section class="gs-api__section" id="services">
                <h2 class="gs-section-title">Что можно вызвать</h2>
                <div class="gs-api__tablewrap">
                    <table class="gs-api__table">
                        <thead>
                            <tr><th>service</th><th>Что делает</th><th>Обязательные поля</th><th>Цена</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach (GS_Api::available_services() as $id => $service): ?>
                                <tr>
                                    <td><code><?php echo esc_html($id); ?></code></td>
                                    <td>
                                        <strong><?php echo esc_html($service['title']); ?></strong>
                                        <span class="gs-api__muted"><?php echo esc_html($service['about']); ?></span>
                                    </td>
                                    <td>
                                        <?php
                                        $required = array();
                                        foreach ($service['input'] as $field => $rule) {
                                            if ($rule === 'required') {
                                                $required[] = '<code>' . esc_html($field) . '</code>';
                                            }
                                        }
                                        echo $required ? implode(', ', $required) : '—'; // phpcs:ignore WordPress.Security.EscapeOutput
                                        ?>
                                    </td>
                                    <td><?php echo esc_html(GS_Api::price_hint($id)); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <p class="gs-api__text">
                    Необязательные поля: <code>prompt</code> уточняет результат,
                    <code>callback_url</code> включает вебхук, <code>voice</code> выбирает голос озвучки,
                    <code>mode</code> и <code>seconds</code> управляют генерацией звука
                    (<code>sfx</code>, <code>ambient</code>, <code>loop</code>).
                </p>
            </section>

            <section class="gs-api__section" id="files">
                <h2 class="gs-section-title">Свои файлы</h2>
                <p class="gs-api__text">
                    Ссылки в <code>image_url</code> и <code>audio_url</code> должны открываться из интернета.
                    Если файла в открытом доступе нет, загрузите его к нам — ссылка вернётся в ответе.
                    Фото до 10 МБ, аудио до 20 МБ.
                </p>
                <?php echo self::render_code('Загрузка файла', self::sample_upload($base)); ?>
            </section>

            <section class="gs-api__section" id="webhook">
                <h2 class="gs-section-title">Вебхук вместо опроса</h2>
                <p class="gs-api__text">
                    Передайте <code>callback_url</code> — и мы сами постучимся POST-запросом,
                    когда задача будет готова или сорвётся. В заголовке <code>X-Genius-Signature</code>
                    придёт HMAC-SHA256 от тела запроса на секрете ключа: так вы убедитесь, что запрос наш.
                    Секрет показывается один раз вместе с ключом.
                </p>
                <?php echo self::render_code('Проверка подписи', self::sample_signature()); ?>
            </section>

            <section class="gs-api__section" id="errors">
                <h2 class="gs-section-title">Ошибки и лимиты</h2>
                <div class="gs-api__tablewrap">
                    <table class="gs-api__table">
                        <thead><tr><th>Код</th><th>Когда</th><th>Что делать</th></tr></thead>
                        <tbody>
                            <tr><td><code>401</code></td><td>Ключ не передан или отозван</td><td>Проверьте заголовок Authorization</td></tr>
                            <tr><td><code>402</code></td><td>Не хватает денег на балансе</td><td>Пополните баланс в личном кабинете</td></tr>
                            <tr><td><code>400</code></td><td>Нет обязательного поля или файл слишком длинный</td><td>Смотрите <code>message</code> в ответе</td></tr>
                            <tr><td><code>429</code></td><td>Больше 60 запросов в минуту на ключ</td><td>Опрашивайте статус реже или включите вебхук</td></tr>
                            <tr><td><code>502</code></td><td>Модель не приняла задачу</td><td>Повторите запрос; деньги не списываются</td></tr>
                            <tr><td><code>503</code></td><td>Операция временно отключена</td><td>Проверяйте <code>/services</code></td></tr>
                        </tbody>
                    </table>
                </div>
                <p class="gs-api__text">
                    Если задача сорвалась уже после запуска, статус придёт как <code>failed</code>,
                    а списанные деньги вернутся на баланс автоматически. Результаты хранятся у нас
                    и доступны по прямой ссылке — скачайте их к себе, если нужны надолго.
                </p>
            </section>

            <?php echo GS_Lab_Page::render_cross_links('', 'Те же инструменты с человеческим интерфейсом'); // phpcs:ignore WordPress.Security.EscapeOutput ?>

            <section class="gs-faq">
                <h2 class="gs-section-title">Частые вопросы</h2>
                <?php foreach (self::faq() as $pair): ?>
                    <details class="gs-faq__item">
                        <summary class="gs-faq__q"><?php echo esc_html($pair[0]); ?></summary>
                        <p class="gs-faq__a"><?php echo esc_html($pair[1]); ?></p>
                    </details>
                <?php endforeach; ?>
            </section>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Панель ключей: выдача, список и отзыв.
     */
    private static function render_keys($logged, $keys) {
        ob_start();
        ?>
        <section class="gs-panel gs-api__keys" id="keys">
            <h2 class="gs-section-title">Ключ доступа</h2>
            <?php if (!$logged): ?>
                <p class="gs-api__text">
                    Ключ выдаётся в аккаунте: он привязан к балансу, с которого списывается оплата.
                </p>
                <a class="gs-btn gs-btn--primary" href="<?php echo esc_url(GS_Pages::get_login_url(self::get_url())); ?>">Войти и получить ключ</a>
            <?php else: ?>
                <p class="gs-api__text">
                    Ключ показывается один раз — сохраните его сразу. Потерянный ключ не восстанавливается,
                    его нужно отозвать и выпустить новый.
                </p>
                <form class="gs-api__keyform" id="gs-api-form">
                    <input class="gs-input" id="gs-api-label" type="text" maxlength="60" placeholder="Название: например, «Бот в Telegram»">
                    <button class="gs-btn gs-btn--primary" type="submit" id="gs-api-create">Выпустить ключ</button>
                </form>
                <p class="gs-form__note" id="gs-api-note" role="status" aria-live="polite"></p>

                <div class="gs-api__fresh" id="gs-api-fresh" hidden>
                    <p class="gs-api__freshlabel">Новый ключ и секрет для подписи вебхуков:</p>
                    <pre class="gs-api__code" id="gs-api-freshvalue"></pre>
                </div>

                <div class="gs-api__list" id="gs-api-list">
                    <?php echo self::render_key_rows($keys); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                </div>
            <?php endif; ?>
        </section>
        <?php
        return ob_get_clean();
    }

    public static function render_key_rows($keys) {
        if (empty($keys)) {
            return '<p class="gs-api__muted">Ключей пока нет.</p>';
        }
        ob_start();
        foreach ($keys as $key) {
            ?>
            <div class="gs-api__key">
                <div>
                    <strong><?php echo esc_html($key['label']); ?></strong>
                    <span class="gs-api__muted"><?php echo esc_html($key['prefix']); ?>…</span>
                </div>
                <div class="gs-api__muted">
                    вызовов: <?php echo (int) $key['calls']; ?>
                    <?php if ($key['last_used'] !== ''): ?>
                        · последний <?php echo esc_html(mysql2date('d.m.Y H:i', $key['last_used'])); ?>
                    <?php endif; ?>
                </div>
                <button class="gs-btn gs-btn--ghost" type="button" data-revoke="<?php echo esc_attr($key['prefix']); ?>">Отозвать</button>
            </div>
            <?php
        }
        return ob_get_clean();
    }

    /**
     * Блок кода с вкладками по языкам.
     */
    private static function render_code($title, $samples) {
        $uid = 'gs-code-' . wp_generate_password(6, false, false);
        $names = array(
            'curl'   => 'curl',
            'python' => 'Python',
            'js'     => 'JavaScript',
            'php'    => 'PHP',
            'json'   => 'JSON',
        );
        ob_start();
        ?>
        <div class="gs-api__code-block" id="<?php echo esc_attr($uid); ?>">
            <div class="gs-api__code-head">
                <span class="gs-api__code-title"><?php echo esc_html($title); ?></span>
                <div class="gs-api__tabs">
                    <?php $first = true; foreach ($samples as $lang => $code): ?>
                        <button type="button" class="gs-api__tab<?php echo $first ? ' is-active' : ''; ?>"
                                data-lang="<?php echo esc_attr($lang); ?>">
                            <?php echo esc_html($names[$lang] ?? $lang); ?>
                        </button>
                    <?php $first = false; endforeach; ?>
                </div>
                <button type="button" class="gs-api__copy">Копировать</button>
            </div>
            <?php $first = true; foreach ($samples as $lang => $code): ?>
                <pre class="gs-api__code" data-lang="<?php echo esc_attr($lang); ?>"<?php echo $first ? '' : ' hidden'; ?>><?php echo esc_html($code); ?></pre>
            <?php $first = false; endforeach; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    /* ---------------------------------------------------------------------
     * Примеры кода
     *
     * Все примеры — в nowdoc: внутри есть и ${…}, и $переменные чужих языков,
     * которые PHP иначе попытается подставить.
     * ------------------------------------------------------------------ */

    private static function sample_quickstart($base) {
        $curl = <<<'CODE'
curl -X POST {{BASE}}/generate \
  -H 'Authorization: Bearer ВАШ_КЛЮЧ' \
  -H 'Content-Type: application/json' \
  -d '{
    "service": "photo-video",
    "image_url": "https://example.com/photo.jpg",
    "prompt": "лёгкая улыбка, поворот головы"
  }'
CODE;

        $python = <<<'CODE'
import time, requests

API = '{{BASE}}'
HEAD = {'Authorization': 'Bearer ВАШ_КЛЮЧ'}

task = requests.post(f'{API}/generate', headers=HEAD, json={
    'service': 'photo-video',
    'image_url': 'https://example.com/photo.jpg',
    'prompt': 'лёгкая улыбка, поворот головы',
}).json()

while True:
    state = requests.get(f"{API}/tasks/{task['task_id']}", headers=HEAD).json()
    if state['status'] != 'pending':
        break
    time.sleep(6)

print(state['files'][0]['url'])
CODE;

        $js = <<<'CODE'
const API = '{{BASE}}';
const head = { Authorization: 'Bearer ВАШ_КЛЮЧ', 'Content-Type': 'application/json' };

const task = await fetch(`${API}/generate`, {
  method: 'POST',
  headers: head,
  body: JSON.stringify({
    service: 'photo-video',
    image_url: 'https://example.com/photo.jpg',
    prompt: 'лёгкая улыбка, поворот головы',
  }),
}).then((r) => r.json());

const state = await fetch(`${API}/tasks/${task.task_id}`, { headers: head })
  .then((r) => r.json());
CODE;

        $php = <<<'CODE'
$api  = '{{BASE}}';
$head = ['Authorization: Bearer ВАШ_КЛЮЧ', 'Content-Type: application/json'];

$ch = curl_init($api . '/generate');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => $head,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POSTFIELDS => json_encode([
        'service' => 'photo-video',
        'image_url' => 'https://example.com/photo.jpg',
        'prompt' => 'лёгкая улыбка, поворот головы',
    ], JSON_UNESCAPED_UNICODE),
]);
$task = json_decode(curl_exec($ch), true);
CODE;

        return self::with_base(array('curl' => $curl, 'python' => $python, 'js' => $js, 'php' => $php), $base);
    }

    private static function sample_response() {
        $json = <<<'CODE'
// ответ на POST /generate
{
  "task_id": "a1b2c3d4",
  "service": "photo-video",
  "status": "pending",
  "cost": 25,
  "balance": 475
}

// GET /tasks/a1b2c3d4 после готовности
{
  "task_id": "a1b2c3d4",
  "status": "completed",
  "files": [
    { "label": "Результат", "kind": "video", "url": "https://…/result.mp4" }
  ]
}
CODE;
        return array('json' => $json);
    }

    private static function sample_upload($base) {
        $curl = <<<'CODE'
curl -X POST {{BASE}}/uploads \
  -H 'Authorization: Bearer ВАШ_КЛЮЧ' \
  -F 'file=@photo.jpg' \
  -F 'kind=image'
CODE;

        $python = <<<'CODE'
up = requests.post(f'{API}/uploads', headers=HEAD,
                   files={'file': open('photo.jpg', 'rb')},
                   data={'kind': 'image'}).json()

print(up['url'])   # эту ссылку и передавайте в image_url
CODE;
        return self::with_base(array('curl' => $curl, 'python' => $python), $base);
    }

    private static function sample_signature() {
        $python = <<<'CODE'
import hmac, hashlib

def valid(body: bytes, signature: str, secret: str) -> bool:
    mine = hmac.new(secret.encode(), body, hashlib.sha256).hexdigest()
    return hmac.compare_digest(mine, signature)
CODE;

        $php = <<<'CODE'
$body = file_get_contents('php://input');
$mine = hash_hmac('sha256', $body, $secret);
$ok   = hash_equals($mine, $_SERVER['HTTP_X_GENIUS_SIGNATURE'] ?? '');

if ($ok) {
    $event = json_decode($body, true);
    // $event['status'] === 'completed' → забираем $event['files']
}
CODE;
        return array('python' => $python, 'php' => $php);
    }

    private static function with_base($samples, $base) {
        foreach ($samples as $lang => $code) {
            $samples[$lang] = str_replace('{{BASE}}', $base, $code);
        }
        return $samples;
    }

    public static function faq() {
        return array(
            array('Сколько стоит доступ к API?',
                  'Абонентской платы нет: вы платите только за выполненные задачи по тем же ценам, что и на сайте. Деньги списываются с баланса аккаунта, пополнить его можно в личном кабинете.'),
            array('Что будет, если генерация сорвётся?',
                  'Статус задачи станет failed, а списанная сумма вернётся на баланс автоматически. Если модель не приняла задачу, деньги вообще не списываются — ответ придёт с кодом 502.'),
            array('Как долго хранятся результаты?',
                  'Файлы лежат у нас и доступны по прямой ссылке. Мы рекомендуем сразу перекладывать их в своё хранилище: так вы не зависите от чужих сроков хранения.'),
            array('Можно ли использовать API в коммерческом продукте?',
                  'Да. Права на созданные файлы остаются у вас, ограничений на коммерческое применение нет. Единственное требование — не выдавать сам API за собственный и не перепродавать доступ к нему как к своему сервису.'),
            array('Есть ли тестовый режим?',
                  'Отдельной песочницы нет, но самые дешёвые операции — генерация звука и картинки — стоят единицы рублей, этого достаточно, чтобы проверить интеграцию целиком.'),
            array('Сколько запросов в минуту выдержит ключ?',
                  'До шестидесяти. Если нужно больше, включите вебхук: тогда статус не придётся опрашивать вовсе, и лимит перестанет мешать.'),
        );
    }
}
