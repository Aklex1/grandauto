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
        foreach (array(GS_Links::OPT_ENABLED, GS_Links::OPT_FOOTER, GS_Tts_Fallback::OPT_ENABLED, GS_Blog::OPT_ENABLED) as $flag) {
            register_setting('gs_settings_group', $flag, array(
                'type'              => 'string',
                'sanitize_callback' => function ($value) {
                    return $value ? '1' : '0';
                },
                'default'           => '1',
            ));
        }

        foreach (GS_Lab::services() as $lab_id => $lab) {
            register_setting('gs_settings_group', 'gs_lab_enabled_' . $lab_id, array(
                'type'              => 'string',
                'sanitize_callback' => function ($value) {
                    return $value ? '1' : '0';
                },
            ));
            register_setting('gs_settings_group', $lab['cost_option'], array(
                'type'              => 'number',
                'sanitize_callback' => function ($value) {
                    $value = (float) $value;
                    return $value > 0 ? $value : 1;
                },
            ));
            // Цена считается от длительности: минимум, ставка и предел длины.
            foreach (array('gs_lab_min_' . $lab_id, 'gs_lab_rate_' . $lab_id, 'gs_lab_max_seconds_' . $lab_id) as $number) {
                register_setting('gs_settings_group', $number, array(
                    'type'              => 'number',
                    'sanitize_callback' => function ($value) {
                        $value = (float) $value;
                        return $value > 0 ? $value : 0;
                    },
                ));
            }
        }

        // Второй поставщик обработки звука: ключ и названия рабочих процессов.
        register_setting('gs_settings_group', GS_MusicAI::OPT_KEY, array(
            'type'              => 'string',
            'sanitize_callback' => function ($value) {
                return trim(sanitize_text_field((string) $value));
            },
        ));
        foreach (array(GS_MusicAI::OPT_WF_VOCAL, GS_MusicAI::OPT_WF_DENOISE) as $workflow) {
            register_setting('gs_settings_group', $workflow, array(
                'type'              => 'string',
                'sanitize_callback' => function ($value) {
                    return trim(sanitize_text_field((string) $value));
                },
            ));
        }

        register_setting('gs_settings_group', GS_Importer::OPT_MAX_FILE_MB, array(
            'type'              => 'number',
            'sanitize_callback' => function ($value) {
                $value = (float) $value;
                return $value > 0 ? $value : GS_Importer::DEFAULT_MAX_FILE_MB;
            },
            'default'           => GS_Importer::DEFAULT_MAX_FILE_MB,
        ));

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
                    <span class="gs-admin-card__value"><?php echo esc_html(GS_Storage::format_size(GS_Storage::disk_free())); ?></span>
                    <span class="gs-admin-card__label">свободно на диске</span>
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
                    <th scope="row"><label for="gs-import-maxmb">Максимальный размер файла, МБ</label></th>
                    <td>
                        <input id="gs-import-maxmb" name="<?php echo esc_attr(GS_Importer::OPT_MAX_FILE_MB); ?>" type="number" step="1" min="1"
                               value="<?php echo esc_attr(get_option(GS_Importer::OPT_MAX_FILE_MB, GS_Importer::DEFAULT_MAX_FILE_MB)); ?>" class="small-text" form="gs-settings-form">
                        <p class="description">Сохраняется вместе с настройками студии ниже. Длинные музыкальные треки — основной источник роста диска.</p>
                    </td>
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
            <form method="post" action="options.php" id="gs-settings-form">
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
                        <th scope="row">Запасная озвучка</th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr(GS_Tts_Fallback::OPT_ENABLED); ?>" value="1"
                                    <?php checked(GS_Tts_Fallback::enabled()); ?>>
                                при отказе ElevenLabs повторять генерацию на Gemini TTS
                            </label>
                            <?php $fb_log = GS_Tts_Fallback::get_log(); ?>
                            <?php if (!empty($fb_log)): ?>
                                <p class="description">Последние срабатывания:</p>
                                <ul style="margin:4px 0 0 16px;list-style:disc">
                                    <?php foreach (array_slice($fb_log, 0, 5) as $row): ?>
                                        <li><code><?php echo esc_html($row['at']); ?></code> — <?php echo esc_html($row['message']); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Блог</th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr(GS_Blog::OPT_ENABLED); ?>" value="1"
                                    <?php checked(GS_Blog::enabled()); ?>>
                                оформлять блог и статьи в стилистике микросервисов
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Микросервисы</th>
                        <td>
                            <?php foreach (GS_Lab::services() as $lab_id => $lab): ?>
                                <p>
                                    <label>
                                        <input type="checkbox" name="gs_lab_enabled_<?php echo esc_attr($lab_id); ?>" value="1"
                                            <?php checked(GS_Lab::is_available($lab_id)); ?>>
                                        <strong><?php echo esc_html($lab['menu']); ?></strong>
                                    </label>
                                    — минимум
                                    <input name="gs_lab_min_<?php echo esc_attr($lab_id); ?>" type="number" step="1" min="1"
                                           value="<?php echo esc_attr(GS_Lab::get_cost($lab_id)); ?>" class="small-text"> ₽,
                                    ставка
                                    <input name="gs_lab_rate_<?php echo esc_attr($lab_id); ?>" type="number" step="1" min="1"
                                           value="<?php echo esc_attr(GS_Lab::rate($lab_id)); ?>" class="small-text"> ₽
                                    за <?php echo GS_Lab::pricing($lab_id, 'unit') === 'second' ? 'секунду' : 'минуту'; ?>,
                                    предел
                                    <input name="gs_lab_max_seconds_<?php echo esc_attr($lab_id); ?>" type="number" step="10" min="0"
                                           value="<?php echo esc_attr(GS_Lab::max_seconds($lab_id)); ?>" class="small-text"> сек
                                    <br><span class="description"><?php echo esc_html(GS_Lab::price_hint($lab_id)); ?></span>
                                    <?php if (!empty($lab['blocked_note']) && !GS_Lab::is_available($lab_id)): ?>
                                        <br><span class="description"><?php echo esc_html($lab['blocked_note']); ?></span>
                                    <?php endif; ?>
                                </p>
                            <?php endforeach; ?>
                            <p class="description">Выключенный сервис не показывается в меню, карте сайта и закрыт от индексации.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Студия обработки звука</th>
                        <td>
                            <p>
                                <label>Ключ доступа<br>
                                    <input name="<?php echo esc_attr(GS_MusicAI::OPT_KEY); ?>" type="password" autocomplete="off"
                                           value="<?php echo esc_attr(GS_MusicAI::api_key()); ?>" class="regular-text">
                                </label>
                            </p>
                            <p>
                                <label>Рабочий процесс «Убрать вокал»<br>
                                    <input name="<?php echo esc_attr(GS_MusicAI::OPT_WF_VOCAL); ?>" type="text"
                                           value="<?php echo esc_attr(GS_MusicAI::workflow('vocal')); ?>" class="regular-text"
                                           placeholder="например stems-vocals-accompaniment">
                                </label>
                            </p>
                            <p>
                                <label>Рабочий процесс «Убрать шум»<br>
                                    <input name="<?php echo esc_attr(GS_MusicAI::OPT_WF_DENOISE); ?>" type="text"
                                           value="<?php echo esc_attr(GS_MusicAI::workflow('denoise')); ?>" class="regular-text"
                                           placeholder="например speech-noise-suppression">
                                </label>
                            </p>
                            <p class="description">
                                Пока ключ или название процесса пустые, оба сервиса закрыты и показывают, что инструмент подключается.
                                Оплата у поставщика поминутная, поэтому цена для пользователя тоже считается от длительности файла.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Сквозные ссылки</th>
                        <td>
                            <label><input type="checkbox" name="<?php echo esc_attr(GS_Links::OPT_ENABLED); ?>" value="1" <?php checked(GS_Links::menu_enabled()); ?>>
                                пункты «Каталог звуков» и «Генератор звуков» в меню</label><br>
                            <label><input type="checkbox" name="<?php echo esc_attr(GS_Links::OPT_FOOTER); ?>" value="1" <?php checked(GS_Links::footer_enabled()); ?>>
                                блок ссылок на каталог в подвале сайта</label>
                            <p class="description">Без них каталог не получает внутреннего веса: на него не ссылается ни одна страница сайта.</p>
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
