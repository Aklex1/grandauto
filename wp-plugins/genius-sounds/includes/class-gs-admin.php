<?php
/**
 * Админка: импорт каталога звуков и настройки студии.
 */

if (!defined('ABSPATH')) {
    exit;
}

class GS_Admin {

    const MENU_SLUG = 'genius-sounds';

    public static function boot() {
        add_action('admin_menu', array(__CLASS__, 'add_menu'));
        add_action('admin_init', array(__CLASS__, 'register_settings'));
    }

    public static function add_menu() {
        add_menu_page(
            'Genius Sounds',
            'Звуки',
            'manage_options',
            self::MENU_SLUG,
            array(__CLASS__, 'render_page'),
            'dashicons-format-audio',
            58
        );
    }

    public static function register_settings() {
        register_setting('gs_settings_group', GS_SFX::OPT_COST, array(
            'type'              => 'number',
            'sanitize_callback' => function ($value) {
                $value = (float) $value;
                return $value > 0 ? $value : 15;
            },
            'default'           => 15,
        ));
    }

    public static function render_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        GS_Storage::ensure_dirs();
        GS_Catalog::ensure_seeded();

        $stats = GS_Catalog::stats();
        $state = GS_Importer::get_state();
        $disk  = GS_Storage::disk_usage();
        $queue = (array) get_option(GS_Importer::OPT_QUEUE, array());
        ?>
        <div class="wrap">
            <h1>Genius Sounds</h1>

            <div class="gs-admin-cards">
                <div class="gs-admin-card">
                    <span class="gs-admin-card__value"><?php echo esc_html(number_format_i18n($stats['sounds'])); ?></span>
                    <span class="gs-admin-card__label">звуков загружено</span>
                </div>
                <div class="gs-admin-card">
                    <span class="gs-admin-card__value"><?php echo esc_html(number_format_i18n($stats['filled'])); ?> / <?php echo esc_html(number_format_i18n($stats['categories'])); ?></span>
                    <span class="gs-admin-card__label">категорий заполнено</span>
                </div>
                <div class="gs-admin-card">
                    <span class="gs-admin-card__value"><?php echo esc_html(GS_Storage::format_size($disk['bytes'])); ?></span>
                    <span class="gs-admin-card__label">занято на диске</span>
                </div>
                <div class="gs-admin-card">
                    <span class="gs-admin-card__value"><?php echo esc_html(number_format_i18n(count($queue))); ?></span>
                    <span class="gs-admin-card__label">в очереди импорта</span>
                </div>
            </div>

            <h2>Импорт звуков</h2>
            <p>Импорт идёт на стороне сервера пачками: очередь категорий обрабатывается по крону,
               прогресс можно двигать вручную кнопкой «Обработать пачку».</p>

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="gs-import-slugs">Категории</label></th>
                    <td>
                        <textarea id="gs-import-slugs" rows="3" class="large-text code"
                                  placeholder="zvuki-soldat orujie vzryivyi — пусто = взять незаполненные по порядку"></textarea>
                        <p class="description">Слаги через пробел, запятую или с новой строки.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="gs-import-count">Сколько категорий взять</label></th>
                    <td><input id="gs-import-count" type="number" value="25" min="1" max="945" class="small-text">
                        <span class="description">используется, если список категорий пуст</span></td>
                </tr>
                <tr>
                    <th scope="row"><label for="gs-import-limit">Звуков на категорию</label></th>
                    <td><input id="gs-import-limit" type="number" value="20" min="1" max="100" class="small-text"></td>
                </tr>
                <tr>
                    <th scope="row">Перезаписывать</th>
                    <td><label><input id="gs-import-force" type="checkbox"> обновлять уже заполненные категории</label></td>
                </tr>
            </table>

            <p>
                <button class="button button-primary" id="gs-import-start">Запустить импорт</button>
                <button class="button" id="gs-import-tick">Обработать пачку</button>
                <button class="button" id="gs-import-stop">Остановить</button>
                <span id="gs-import-spinner" class="spinner" style="float:none"></span>
            </p>

            <div id="gs-import-status" class="notice notice-info inline">
                <p>
                    <strong>Статус:</strong>
                    <span id="gs-import-state"><?php echo esc_html($state['running'] ? 'идёт импорт' : 'ожидание'); ?></span> ·
                    обработано <span id="gs-import-done"><?php echo (int) $state['done']; ?></span> из
                    <span id="gs-import-total"><?php echo (int) $state['total']; ?></span> ·
                    добавлено звуков: <span id="gs-import-imported"><?php echo (int) $state['imported']; ?></span>
                </p>
                <p><em id="gs-import-message"><?php echo esc_html($state['last_message']); ?></em></p>
            </div>

            <hr>

            <h2>Студия генерации</h2>
            <form method="post" action="options.php">
                <?php settings_fields('gs_settings_group'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="gs-cost">Цена одной генерации, ₽</label></th>
                        <td>
                            <input id="gs-cost" name="<?php echo esc_attr(GS_SFX::OPT_COST); ?>" type="number" step="0.5" min="0"
                                   value="<?php echo esc_attr(GS_SFX::get_cost()); ?>" class="small-text">
                            <p class="description">Списывается с общего баланса пользователя (того же, что и озвучка).</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">API-ключ KIE</th>
                        <td>
                            <p class="description">
                                <?php echo GS_SFX::get_api_key() !== '' ? 'Ключ задан в настройках плагина TTS.' : 'Ключ не задан — задайте его в «Настройки → TTS».'; ?>
                            </p>
                        </td>
                    </tr>
                </table>
                <?php submit_button('Сохранить'); ?>
            </form>

            <p>
                Страницы:
                <a href="<?php echo esc_url(GS_Pages::get_studio_url()); ?>" target="_blank" rel="noopener">студия звуков</a> ·
                <a href="<?php echo esc_url(GS_Pages::get_showcase_url()); ?>" target="_blank" rel="noopener">примеры ИИ-звуков</a> ·
                <a href="<?php echo esc_url(GS_Catalog::base_url()); ?>" target="_blank" rel="noopener">каталог</a>
            </p>
        </div>

        <style>
            .gs-admin-cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px; margin: 20px 0; }
            .gs-admin-card { background: #fff; border: 1px solid #dcdcde; border-radius: 8px; padding: 16px; }
            .gs-admin-card__value { display: block; font-size: 24px; font-weight: 600; line-height: 1.2; }
            .gs-admin-card__label { color: #646970; font-size: 13px; }
        </style>

        <script>
        (function () {
            var restUrl = <?php echo wp_json_encode(esc_url_raw(rest_url(GS_Rest::NS . '/'))); ?>;
            var nonce = <?php echo wp_json_encode(wp_create_nonce('wp_rest')); ?>;
            var spinner = document.getElementById('gs-import-spinner');
            var autoTick = false;

            function busy(on) { spinner.classList.toggle('is-active', !!on); }

            function call(path, body) {
                return fetch(restUrl + path, {
                    method: body ? 'POST' : 'GET',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
                    body: body ? JSON.stringify(body) : undefined
                }).then(function (r) { return r.json(); });
            }

            function paint(data) {
                if (!data || !data.state) { return; }
                var s = data.state;
                document.getElementById('gs-import-state').textContent = s.running ? 'идёт импорт' : 'ожидание';
                document.getElementById('gs-import-done').textContent = s.done;
                document.getElementById('gs-import-total').textContent = s.total;
                document.getElementById('gs-import-imported').textContent = s.imported;
                document.getElementById('gs-import-message').textContent = s.last_message || '';
                return s;
            }

            function tick() {
                busy(true);
                return call('import/tick', {}).then(function (data) {
                    busy(false);
                    var s = paint(data);
                    if (autoTick && s && s.running) { setTimeout(tick, 1000); }
                    else { autoTick = false; }
                }).catch(function () { busy(false); autoTick = false; });
            }

            document.getElementById('gs-import-start').addEventListener('click', function (e) {
                e.preventDefault();
                busy(true);
                call('import/start', {
                    slugs: document.getElementById('gs-import-slugs').value,
                    count: parseInt(document.getElementById('gs-import-count').value, 10) || 25,
                    limit: parseInt(document.getElementById('gs-import-limit').value, 10) || 20,
                    force: document.getElementById('gs-import-force').checked
                }).then(function (data) {
                    busy(false);
                    paint(data);
                    autoTick = true;
                    tick();
                }).catch(function () { busy(false); });
            });

            document.getElementById('gs-import-tick').addEventListener('click', function (e) {
                e.preventDefault();
                autoTick = false;
                tick();
            });

            document.getElementById('gs-import-stop').addEventListener('click', function (e) {
                e.preventDefault();
                autoTick = false;
                busy(true);
                call('import/stop', {}).then(function (data) { busy(false); paint(data); });
            });
        })();
        </script>
        <?php
    }
}
