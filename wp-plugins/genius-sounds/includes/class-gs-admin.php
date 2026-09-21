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
            // options.php сохраняет ВСЕ настройки группы, в том числе те, для
            // которых в форме нет поля: тогда в обработчик приходит null.
            // Без этой оговорки каждое сохранение настроек затирало цены
            // и делала бесплатную операцию платной.
            $cost_option  = $lab['cost_option'];
            $default_cost = (float) $lab['cost'];
            register_setting('gs_settings_group', $cost_option, array(
                'type'              => 'number',
                'sanitize_callback' => function ($value) use ($cost_option, $default_cost) {
                    if ($value === null || $value === '') {
                        return (float) get_option($cost_option, $default_cost);
                    }
                    $value = (float) $value;
                    // Ноль — законная цена: операции на своём сервере бесплатны.
                    return $value >= 0 ? $value : $default_cost;
                },
            ));
            register_setting('gs_settings_group', 'gs_lab_manual_' . $lab_id, array(
                'type'              => 'string',
                'sanitize_callback' => function ($value) {
                    return $value ? '1' : '0';
                },
            ));
            // Цена считается от длительности: минимум, ставка и предел длины.
            foreach (array('gs_lab_min_' . $lab_id, 'gs_lab_rate_' . $lab_id, 'gs_lab_max_seconds_' . $lab_id) as $number) {
                register_setting('gs_settings_group', $number, array(
                    'type'              => 'number',
                    'sanitize_callback' => function ($value) use ($number) {
                        if ($value === null) {
                            return get_option($number, 0);
                        }
                        $value = (float) $value;
                        return $value > 0 ? $value : 0;
                    },
                ));
            }
        }

        // Пробный баланс при выпуске ключа API: ноль выключает подарок.
        register_setting('gs_settings_group', GS_Api_Keys::OPT_TRIAL, array(
            'type'              => 'number',
            'sanitize_callback' => function ($value) {
                if ($value === null || $value === '') {
                    return (float) get_option(GS_Api_Keys::OPT_TRIAL, GS_Api_Keys::TRIAL_DEFAULT);
                }
                return max(0.0, (float) $value);
            },
        ));

        // Приём платежей: секрет для проверки подписи и адрес пересылки чужих.
        register_setting('gs_settings_group', GS_Yoomoney::OPT_SECRET, array(
            'type'              => 'string',
            'sanitize_callback' => function ($value) {
                return trim(sanitize_text_field((string) $value));
            },
        ));
        register_setting('gs_settings_group', GS_Yoomoney::OPT_FORWARD, array(
            'type'              => 'string',
            'sanitize_callback' => function ($value) {
                $value = trim((string) $value);
                return $value === '' ? '' : esc_url_raw($value);
            },
        ));

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

        // Подтверждение прав в вебмастерах и мгновенная отправка адресов в индекс.
        foreach (array(GS_Index::OPT_YANDEX, GS_Index::OPT_GOOGLE) as $verify) {
            register_setting('gs_settings_group', $verify, array(
                'type'              => 'string',
                'sanitize_callback' => array('GS_Index', 'clean_code'),
                'default'           => '',
            ));
        }
        // Токен Вебмастера: пустое значение при сохранении не должно стирать
        // уже записанный — форма приходит без полей, которых на ней нет.
        register_setting('gs_settings_group', GS_Webmaster::OPT_TOKEN, array(
            'type'              => 'string',
            'sanitize_callback' => function ($value) {
                if ($value === null) {
                    return (string) get_option(GS_Webmaster::OPT_TOKEN, '');
                }
                $value = trim(sanitize_text_field((string) $value));
                if ($value !== '') {
                    GS_Webmaster::forget(); // сменился токен — сбрасываем кеш идентификаторов
                }
                return $value;
            },
            'default'           => '',
        ));

        // Бот для заявок. Токен, как и токен Вебмастера, не должен стираться
        // при сохранении формы, на которой поля нет.
        register_setting('gs_settings_group', GS_Leads::OPT_TOKEN, array(
            'type'              => 'string',
            'sanitize_callback' => function ($value) {
                if ($value === null) {
                    return (string) get_option(GS_Leads::OPT_TOKEN, '');
                }
                return trim(sanitize_text_field((string) $value));
            },
            'default'           => '',
        ));
        register_setting('gs_settings_group', GS_Leads::OPT_CHAT, array(
            'type'              => 'string',
            'sanitize_callback' => function ($value) {
                if ($value === null) {
                    return (string) get_option(GS_Leads::OPT_CHAT, '');
                }
                return trim(sanitize_text_field((string) $value));
            },
            'default'           => '',
        ));

        // Суммы сверх зашитых в платёжном плагине: включать их можно только
        // после того, как они там разрешены, иначе кнопка ведёт в отказ.
        register_setting('gs_settings_group', GS_Payments::OPT_EXTRA, array(
            'type'              => 'array',
            'sanitize_callback' => function ($value) {
                if ($value === null) {
                    return (array) get_option(GS_Payments::OPT_EXTRA, array());
                }
                $out = array();
                foreach (explode(',', (string) $value) as $piece) {
                    $sum = (int) trim($piece);
                    if ($sum > 0) {
                        $out[] = $sum;
                    }
                }
                return array_values(array_unique($out));
            },
            'default'           => array(),
        ));

        register_setting('gs_settings_group', GS_Index::OPT_ENABLED, array(
            'type'              => 'string',
            'sanitize_callback' => function ($value) {
                return $value ? '1' : '0';
            },
            'default'           => '1',
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

    /**
     * Замечания Яндекс.Вебмастера. Копировать их руками из интерфейса долго
     * и легко упустить, поэтому забираем сами и показываем рядом с настройками.
     */
    /**
     * Маршруты поставщиков: что сейчас живо и что адаптер отключил.
     *
     * Когда сервис «не работает», первый вопрос — упал ли поставщик. Здесь
     * это видно сразу, вместе с причиной и временем возврата маршрута.
     */
    public static function render_provider() {
        $rows = GS_Provider::status();
        $log = GS_Provider::log_rows();

        ob_start();
        ?>
        <h2 id="gs-provider">Маршруты поставщиков</h2>
        <p class="description" style="max-width:900px">
            Сервисы не знают, к какой модели идти, — это решает адаптер. Если модель
            отвечает отказом со своей стороны, маршрут временно исключается, а запрос
            уходит на запасной. Через пять минут маршрут снова допускается к работе.
        </p>

        <table class="widefat striped" style="max-width:1000px">
            <thead><tr><th>Возможность</th><th>Маршрут</th><th>Модель</th><th>Роль</th><th>Состояние</th></tr></thead>
            <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td><?php echo esc_html($row['capability']); ?></td>
                        <td><code><?php echo esc_html($row['id']); ?></code></td>
                        <td><?php echo esc_html($row['model']); ?></td>
                        <td><?php echo $row['primary'] ? 'основной' : 'запасной'; ?></td>
                        <td>
                            <?php if ($row['down']): ?>
                                <span style="color:#b32d2e">отключён</span>
                                — <?php echo esc_html($row['why']); ?>
                                <br><span class="description">вернётся <?php echo esc_html(date_i18n('H:i', $row['until'])); ?></span>
                            <?php else: ?>
                                <span style="color:#1a7f37">в работе</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <?php if ($log): ?>
            <h3>Последние переключения</h3>
            <table class="widefat striped" style="max-width:1000px">
                <thead><tr><th>Когда</th><th>Маршрут</th><th>Что случилось</th></tr></thead>
                <tbody>
                    <?php foreach (array_slice($log, 0, 20) as $row): ?>
                        <tr>
                            <td><?php echo esc_html($row['at']); ?></td>
                            <td><code><?php echo esc_html($row['route']); ?></code></td>
                            <td><?php echo esc_html($row['note']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        <?php
        return ob_get_clean();
    }

    /** Журнал заявок: последняя строка показывает, дошло ли сообщение до бота. */
    /**
     * Примеры к статьям раздела промтов.
     *
     * Картинка рисуется в момент публикации, а забирается кроном: между
     * этими событиями статья какое-то время живёт без примера, и панель
     * показывает, сколько таких статей сейчас в ожидании.
     */
    public static function render_promt() {
        if (!class_exists('GS_Promt')) {
            return '';
        }
        $stats = GS_Promt::stats();

        ob_start();
        ?>
        <h2 id="gs-promt">Примеры к статьям с промтами</h2>
        <p>
            Ждут картинку: <strong><?php echo (int) $stats['waiting']; ?></strong>,
            уже с примером: <strong><?php echo (int) $stats['done']; ?></strong>.
            <?php if (!empty($stats['stuck'])): ?>
                <span style="color:#b32d2e">Сдались после трёх попыток: <?php echo (int) $stats['stuck']; ?>.</span>
            <?php endif; ?>
            <?php if (!empty($stats['error'])): ?>
                <br><span class="description">Последний отказ поставщика: <?php echo esc_html($stats['error']); ?></span>
            <?php endif; ?>
            <?php if (!empty($stats['paused']) && $stats['paused'] > time()): ?>
                <br><span class="description">Запуск новых примеров на паузе до
                <?php echo esc_html(date_i18n('H:i', $stats['paused'] + (int) (get_option('gmt_offset') * HOUR_IN_SECONDS))); ?>
                — счёт у поставщика пуст. Кнопка ниже снимает паузу.</span>
            <?php endif; ?>
            <?php if ($stats['next']): ?>
                Следующий сбор: <?php echo esc_html(date_i18n('d.m, H:i', $stats['next'] + (int) (get_option('gmt_offset') * HOUR_IN_SECONDS))); ?>.
            <?php else: ?>
                <span style="color:#b32d2e">Задача сбора не запланирована.</span>
            <?php endif; ?>
        </p>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-bottom:12px">
            <?php wp_nonce_field('gs_promt_collect'); ?>
            <input type="hidden" name="action" value="gs_promt_collect">
            <?php submit_button('Собрать готовые примеры сейчас', 'secondary', 'submit', false); ?>
        </form>
        <?php if (!empty($stats['stuck']) || (!empty($stats['paused']) && $stats['paused'] > time())): ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-bottom:12px">
                <?php wp_nonce_field('gs_promt_retry'); ?>
                <input type="hidden" name="action" value="gs_promt_retry">
                <?php submit_button('Счёт пополнен — дорисовать примеры', 'secondary', 'submit', false); ?>
            </form>
        <?php endif; ?>
        <?php
        return ob_get_clean();
    }

    /**
     * Платежи, за которые деньги пришли, а баланс не пополнился.
     *
     * Такое случается, когда уведомление от ЮMoney потерялось или ушло не
     * туда. Без этой таблицы единственный способ помочь человеку — лезть
     * в базу руками, а деньги у него уже списаны.
     */
    public static function render_stuck_payments() {
        if (!class_exists('GS_Payments')) {
            return '';
        }
        $rows = GS_Payments::pending_payments(40);
        $notice = get_transient('gs_payment_notice');

        ob_start();
        ?>
        <h2 id="gs-payments-stuck">Незакрытые пополнения</h2>

        <?php if ($notice !== false): ?>
            <?php delete_transient('gs_payment_notice'); ?>
            <?php $ok = strpos((string) $notice, 'ok:') === 0; ?>
            <p class="notice notice-<?php echo $ok ? 'success' : 'error'; ?>" style="padding:10px;max-width:900px">
                <?php echo $ok
                    ? 'Баланс пополнен по метке ' . esc_html(substr((string) $notice, 3))
                    : 'Не удалось зачислить по метке ' . esc_html(substr((string) $notice, 5)); ?>
            </p>
        <?php endif; ?>

        <p class="description" style="max-width:900px">
            Ссылка на оплату заводит запись до перехода в ЮMoney, поэтому здесь оседают и
            брошенные попытки. Зачисляйте только те, по которым деньги действительно пришли —
            это видно в кошельке. Всего закрыто платежей:
            <strong><?php echo (int) GS_Payments::completed_count(); ?></strong><?php
            $last = GS_Payments::last_completed();
            echo $last ? ', последнее ' . esc_html(date_i18n('d.m.Y H:i', strtotime($last))) : '';
            ?>.
        </p>

        <?php if (!$rows): ?>
            <p class="description">Незакрытых пополнений нет.</p>
        <?php else: ?>
            <table class="widefat striped" style="max-width:1000px">
                <thead><tr><th>Когда</th><th>Метка</th><th>Кто</th><th>Сумма</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td><?php echo esc_html(date_i18n('d.m.Y H:i', strtotime((string) $row['created_at']))); ?></td>
                        <td><code><?php echo esc_html((string) $row['label']); ?></code></td>
                        <td><?php
                            if (($row['kind'] ?? '') === 'neurohub') {
                                echo esc_html($row['user_key']) . ' · нейросети';
                            } else {
                                $user = get_userdata((int) $row['user_id']);
                                echo esc_html($user ? $user->user_login : ('id ' . (int) $row['user_id']));
                                echo !empty($row['is_telegram']) ? ' · бот' : '';
                            }
                        ?></td>
                        <td><?php echo esc_html(number_format_i18n((float) $row['amount'], 2)); ?> ₽</td>
                        <td>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                <?php wp_nonce_field('gs_payment_credit'); ?>
                                <input type="hidden" name="action" value="gs_payment_credit">
                                <input type="hidden" name="label" value="<?php echo esc_attr((string) $row['label']); ?>">
                                <?php submit_button('Зачислить', 'secondary small', 'submit', false); ?>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        <?php
        return ob_get_clean();
    }

    public static function render_leads() {
        $rows = GS_Leads::log_rows();
        $notice = get_transient('gs_leads_notice');

        ob_start();
        ?>
        <h2 id="gs-leads">Заявки с лендинга</h2>

        <?php if (is_array($notice)): ?>
            <?php delete_transient('gs_leads_notice'); ?>
            <p class="notice notice-<?php echo !empty($notice['ok']) ? 'success' : 'error'; ?>" style="padding:10px;max-width:900px">
                <?php echo !empty($notice['ok']) ? 'Тестовое сообщение ушло в Telegram.' : esc_html('Telegram не принял сообщение: ' . $notice['message']); ?>
            </p>
        <?php endif; ?>

        <?php if (!GS_Leads::ready()): ?>
            <p class="description" style="max-width:900px">Бот не настроен — заявки будут копиться только здесь.</p>
        <?php else: ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-bottom:12px">
                <?php wp_nonce_field('gs_leads_test'); ?>
                <input type="hidden" name="action" value="gs_leads_test">
                <?php submit_button('Отправить тестовое сообщение', 'secondary', 'submit', false); ?>
            </form>
        <?php endif; ?>

        <?php if ($rows): ?>
            <table class="widefat striped" style="max-width:1000px">
                <thead><tr><th>Когда</th><th>Имя</th><th>Контакт</th><th>Комментарий</th><th>Откуда</th><th>В бот</th></tr></thead>
                <tbody>
                    <?php foreach (array_slice($rows, 0, 50) as $row): ?>
                        <tr>
                            <td><?php echo esc_html($row['at'] ?? ''); ?></td>
                            <td><?php echo esc_html($row['name'] ?? ''); ?></td>
                            <td><?php echo esc_html($row['contact'] ?? ''); ?></td>
                            <td><?php echo esc_html($row['comment'] ?? ''); ?></td>
                            <td><?php echo esc_html($row['source'] ?? ''); ?></td>
                            <td><?php echo !empty($row['sent']) ? 'да' : esc_html('нет — ' . ($row['error'] ?? '')); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p class="description">Заявок пока нет.</p>
        <?php endif; ?>
        <?php
        return ob_get_clean();
    }

    public static function render_webmaster() {
        ob_start();
        ?>
        <h2 id="gs-webmaster">Яндекс.Вебмастер</h2>
        <?php $notice = get_transient('gs_webmaster_notice'); ?>
        <?php if (is_array($notice)): ?>
            <?php delete_transient('gs_webmaster_notice'); ?>
            <p class="notice notice-<?php echo !empty($notice['ok']) ? 'success' : 'warning'; ?>" style="padding:10px;max-width:900px">
                Отправлено на переобход: <?php echo (int) $notice['sent']; ?>,
                отказов: <?php echo (int) $notice['failed']; ?>.
                <?php echo esc_html($notice['message']); ?>
            </p>
        <?php endif; ?>
        <?php if (!GS_Webmaster::ready()): ?>
            <p class="description" style="max-width:900px">
                Токен не задан. Без него замечания придётся смотреть в интерфейсе Вебмастера вручную.
            </p>
        <?php else: ?>
            <?php $quota = GS_Webmaster::quota(); ?>
            <?php if ($quota['ok']): ?>
                <p>
                    Переобход: осталось <strong><?php echo (int) $quota['left']; ?></strong>
                    из <?php echo (int) $quota['total']; ?> запросов на сегодня.
                </p>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-bottom:12px">
                    <?php wp_nonce_field('gs_webmaster_recrawl'); ?>
                    <input type="hidden" name="action" value="gs_webmaster_recrawl">
                    <?php submit_button('Отправить свежие страницы на переобход', 'secondary', 'submit', false); ?>
                </form>
            <?php endif; ?>

            <?php $diag = GS_Webmaster::diagnostics(); ?>
            <?php if (!$diag['ok']): ?>
                <p class="notice notice-warning" style="padding:10px;max-width:900px">
                    <?php echo esc_html($diag['message']); ?>
                </p>
            <?php elseif (!$diag['problems']): ?>
                <p class="description">
                    Замечаний нет: пройдено проверок — <?php echo (int) ($diag['checked'] ?? 0); ?>.
                </p>
            <?php else: ?>
                <p class="description">
                    Пройдено проверок: <?php echo (int) ($diag['checked'] ?? 0); ?>,
                    требуют внимания: <?php echo count($diag['problems']); ?>.
                </p>
                <table class="widefat striped" style="max-width:900px">
                    <thead><tr><th>Замечание</th><th>Важность</th><th>Состояние</th><th>Код</th></tr></thead>
                    <tbody>
                        <?php foreach ($diag['problems'] as $problem): ?>
                            <tr>
                                <td><?php echo esc_html($problem['title']); ?></td>
                                <td><?php echo esc_html($problem['severity']); ?></td>
                                <td><?php echo esc_html($problem['state']); ?></td>
                                <td><code><?php echo esc_html($problem['type']); ?></code></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <?php $broken = GS_Webmaster::broken_links(20); ?>
            <?php if (!empty($broken['links'])): ?>
                <h3>Битые внутренние ссылки</h3>
                <table class="widefat striped" style="max-width:900px">
                    <thead><tr><th>Страница</th><th>Куда ведёт</th></tr></thead>
                    <tbody>
                        <?php foreach ($broken['links'] as $link): ?>
                            <tr>
                                <td><code><?php echo esc_html(mb_substr((string) ($link['source_url'] ?? ''), 0, 70)); ?></code></td>
                                <td><code><?php echo esc_html(mb_substr((string) ($link['destination_url'] ?? ''), 0, 70)); ?></code></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        <?php endif; ?>
        <?php
        return ob_get_clean();
    }

    /**
     * Индексирование: подтверждение прав и журнал отправок IndexNow.
     */
    public static function render_index_tools() {
        $rows = GS_Index::log_rows();
        ob_start();
        ?>
        <h2 id="gs-index">Индексирование</h2>
        <p>
            Подтверждение прав:
            Яндекс — <?php echo GS_Index::clean_code(get_option(GS_Index::OPT_YANDEX, '')) !== '' ? '<strong>код задан</strong>' : 'код не задан'; ?>,
            Google — <?php echo GS_Index::clean_code(get_option(GS_Index::OPT_GOOGLE, '')) !== '' ? '<strong>код задан</strong>' : 'код не задан'; ?>.
            Быстрая отправка: <?php echo GS_Index::enabled() ? '<strong>включена</strong>' : 'выключена'; ?>.
        </p>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-bottom:12px">
            <?php wp_nonce_field('gs_indexnow_all'); ?>
            <input type="hidden" name="action" value="gs_indexnow_all">
            <?php submit_button('Отправить все страницы в IndexNow', 'secondary', 'submit', false); ?>
        </form>
        <p class="description" style="max-width:900px">
            Новые записи уходят сами при публикации. Кнопка нужна один раз — чтобы разом сообщить
            обо всём, что было опубликовано до подключения ключа. Адреса уходят порциями по
            <?php echo (int) GS_Index::BATCH; ?> штук с паузой: на большой пачке сервис отвечает отказом и не берёт ничего.
            <?php if (GS_Index::queue_size() > 0): ?>
                <br><strong>В очереди: <?php echo (int) GS_Index::queue_size(); ?> адресов.</strong>
            <?php endif; ?>
        </p>

        <?php if (!empty($rows)): ?>
            <h3>Последние отправки</h3>
            <table class="widefat striped" style="max-width:900px">
                <thead><tr><th>Когда</th><th>Адресов</th><th>Первый адрес</th><th>Итог</th></tr></thead>
                <tbody>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <td><?php echo esc_html(mysql2date('d.m.Y H:i', $row['time'])); ?></td>
                            <td><?php echo (int) $row['count']; ?></td>
                            <td><code><?php echo esc_html(mb_substr((string) $row['first'], 0, 60)); ?></code></td>
                            <td>
                                <?php
                                $where = isset($row['host']) ? (string) wp_parse_url($row['host'], PHP_URL_HOST) : '';
                                echo esc_html(($row['error'] !== '' ? $row['error'] : 'принято, код ' . $row['code'])
                                    . ($where !== '' ? ' — ' . $where : ''));
                                ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        <?php
        return ob_get_clean();
    }

    /**
     * Очередь ручной обработки: пока у сервиса нет рабочего API,
     * заказы выполняются руками, и делать это надо прямо здесь.
     */
    public static function render_manual_queue() {
        $manual = array();
        foreach (GS_Lab::services() as $id => $service) {
            if (GS_Lab::is_manual($id)) {
                $manual[$id] = $service;
            }
        }
        $orders = GS_Manual::open_orders();
        if (empty($manual) && empty($orders)) {
            return '';
        }

        $notice = isset($_GET['gs_manual_notice']) ? sanitize_text_field(wp_unslash((string) $_GET['gs_manual_notice'])) : '';

        ob_start();
        ?>
        <h2 id="gs-manual">Очередь ручной обработки</h2>
        <?php if ($notice !== ''): ?>
            <div class="notice notice-success"><p><?php echo esc_html($notice); ?></p></div>
        <?php endif; ?>

        <p class="description">
            Заказы по услугам <?php echo esc_html(implode(', ', wp_list_pluck($manual, 'menu'))); ?>
            выполняются вручную: скачайте исходник, обработайте его в студии и приложите готовые дорожки.
            Пользователь получит их в истории и на почту. Кнопка «Вернуть деньги» закрывает заказ с возвратом на баланс.
        </p>

        <?php if (empty($orders)): ?>
            <p><em>Открытых заказов нет.</em></p>
        <?php else: ?>
            <?php foreach ($orders as $order): ?>
                <?php $service = GS_Lab::get_service($order['service']); ?>
                <div class="gs-admin-card" style="margin-bottom:12px">
                    <p style="margin-top:0">
                        <strong><?php echo esc_html($service ? $service['menu'] : $order['service']); ?></strong>
                        — <?php echo esc_html(GS_Storage::format_duration((int) $order['seconds'])); ?>,
                        <?php echo esc_html(number_format_i18n((float) $order['cost'], 2)); ?> ₽,
                        <?php echo esc_html(human_time_diff((int) $order['created'])); ?> назад
                        <br>
                        <a href="<?php echo esc_url($order['audio_url']); ?>" target="_blank" rel="noopener">скачать исходник</a>
                    </p>

                    <form method="post" enctype="multipart/form-data" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php wp_nonce_field('gs_manual'); ?>
                        <input type="hidden" name="action" value="gs_manual_result">
                        <input type="hidden" name="task_id" value="<?php echo esc_attr($order['task_id']); ?>">
                        <?php foreach (GS_Manual::labels((string) $order['service']) as $slot => $label): ?>
                            <p>
                                <label><?php echo esc_html($label); ?><br>
                                    <input type="file" name="<?php echo esc_attr($slot); ?>" accept="audio/*">
                                </label>
                            </p>
                        <?php endforeach; ?>
                        <?php submit_button('Отправить результат', 'primary', 'submit', false); ?>
                    </form>

                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:8px">
                        <?php wp_nonce_field('gs_manual'); ?>
                        <input type="hidden" name="action" value="gs_manual_fail">
                        <input type="hidden" name="task_id" value="<?php echo esc_attr($order['task_id']); ?>">
                        <input type="text" name="reason" class="regular-text" placeholder="Причина (необязательно)">
                        <?php submit_button('Вернуть деньги', 'secondary', 'submit', false); ?>
                    </form>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
        <?php
        return ob_get_clean();
    }

    /**
     * Платежи по сервисам: баланс общий, но видно, откуда пришли деньги.
     */
    public static function render_payments() {
        $stats = GS_Yoomoney::get_stats();
        $log = array_slice(GS_Yoomoney::get_log(), 0, 15);
        if (empty($stats) && empty($log)) {
            return '';
        }
        $names = GS_Payments::sources();

        ob_start();
        ?>
        <h2 id="gs-payments">Платежи по сервисам</h2>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-bottom:12px">
            <?php wp_nonce_field('gs_yoomoney_reset'); ?>
            <input type="hidden" name="action" value="gs_yoomoney_reset">
            <?php submit_button('Очистить журнал и счётчики', 'secondary', 'submit', false); ?>
        </form>
        <?php if (!empty($stats)): ?>
            <table class="widefat striped" style="max-width:620px">
                <thead><tr><th>Сервис</th><th>Платежей</th><th>Сумма</th></tr></thead>
                <tbody>
                    <?php foreach ($stats as $source => $row): ?>
                        <tr>
                            <td><?php echo esc_html($names[$source] ?? $source); ?></td>
                            <td><?php echo (int) $row['count']; ?></td>
                            <td><?php echo esc_html(number_format_i18n((float) $row['sum'], 2)); ?> ₽</td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <?php if (!empty($log)): ?>
            <h3>Последние уведомления</h3>
            <table class="widefat striped" style="max-width:900px">
                <thead><tr><th>Когда</th><th>Метка</th><th>Сумма</th><th>Итог</th></tr></thead>
                <tbody>
                    <?php foreach ($log as $row): ?>
                        <tr>
                            <td><?php echo esc_html(mysql2date('d.m.Y H:i', $row['at'])); ?></td>
                            <td><code><?php echo esc_html(mb_substr((string) $row['label'], 0, 46)); ?></code></td>
                            <td><?php echo esc_html(number_format_i18n((float) $row['amount'], 2)); ?> ₽</td>
                            <td><?php echo esc_html($row['status'] . ($row['message'] ? ' — ' . $row['message'] : '')); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        <?php
        return ob_get_clean();
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
                                    <?php if (GS_Lab::pricing($lab_id, 'unit') === 'fixed'): ?>
                                    — цена
                                    <input name="<?php echo esc_attr($lab['cost_option']); ?>" type="number" step="1" min="0"
                                           value="<?php echo esc_attr(GS_Lab::get_cost($lab_id)); ?>" class="small-text"> ₽
                                    <span class="description">(0 — бесплатно и без регистрации)</span>,
                                    <?php else: ?>
                                    — минимум
                                    <input name="gs_lab_min_<?php echo esc_attr($lab_id); ?>" type="number" step="1" min="0"
                                           value="<?php echo esc_attr(GS_Lab::get_cost($lab_id)); ?>" class="small-text"> ₽,
                                    ставка
                                    <input name="gs_lab_rate_<?php echo esc_attr($lab_id); ?>" type="number" step="1" min="0"
                                           value="<?php echo esc_attr(GS_Lab::rate($lab_id)); ?>" class="small-text"> ₽
                                    за <?php echo GS_Lab::pricing($lab_id, 'unit') === 'second' ? 'секунду' : 'минуту'; ?>,
                                    <?php endif; ?>
                                    предел
                                    <input name="gs_lab_max_seconds_<?php echo esc_attr($lab_id); ?>" type="number" step="10" min="0"
                                           value="<?php echo esc_attr(GS_Lab::max_seconds($lab_id)); ?>" class="small-text"> сек
                                    <br><span class="description"><?php echo esc_html(GS_Lab::price_hint($lab_id)); ?></span>
                                    <?php if (GS_MusicAI::handles($lab_id)): ?>
                                        <br><label>
                                            <input type="checkbox" name="gs_lab_manual_<?php echo esc_attr($lab_id); ?>" value="1"
                                                <?php checked(GS_Manual::enabled($lab_id)); ?>>
                                            временно обрабатывать вручную: заказ попадает в очередь ниже, результат прикладываете сами
                                        </label>
                                    <?php endif; ?>
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
                        <th scope="row">Приём платежей</th>
                        <td>
                            <p>
                                Адрес для уведомлений ЮMoney:<br>
                                <code><?php echo esc_html(GS_Yoomoney::endpoint_url()); ?></code>
                            </p>
                            <p>
                                <label>Секрет для проверки подписи<br>
                                    <input name="<?php echo esc_attr(GS_Yoomoney::OPT_SECRET); ?>" type="password" autocomplete="off"
                                           value="<?php echo esc_attr(get_option(GS_Yoomoney::OPT_SECRET, '')); ?>" class="regular-text">
                                </label>
                            </p>
                            <p>
                                <label>Куда пересылать чужие платежи<br>
                                    <input name="<?php echo esc_attr(GS_Yoomoney::OPT_FORWARD); ?>" type="url"
                                           value="<?php echo esc_attr(get_option(GS_Yoomoney::OPT_FORWARD, '')); ?>" class="regular-text"
                                           placeholder="http://89.169.38.152:8000/yoomoney-webhook">
                                </label>
                            </p>
                            <p class="description">
                                Сайт сам раскладывает платежи по балансам: метка <code>kie-neurohub|…</code> идёт в «Нейросети»,
                                <code>topup_wp_…</code> и <code>topup_telegram_…</code> — в общий баланс сервисов,
                                остальные (в том числе метки бота вида <code>topup_&lt;id&gt;_&lt;время&gt;</code>)
                                пересылаются по указанному адресу без изменений.
                            </p>
                            <p class="description">
                                <strong>Пока секрет не задан, подпись не проверяется.</strong> Это значит, что зачислить себе
                                баланс может любой, кто подберёт метку платежа, — а она складывается из номера пользователя
                                и времени. Секрет берётся в ЮMoney: Настройки → HTTP-уведомления, там же проверьте, что
                                адрес уведомлений совпадает с указанным выше. Проверка включится сама, как только поле
                                заполнено.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Поисковые системы</th>
                        <td>
                            <p>
                                <label>Код подтверждения Яндекс.Вебмастера<br>
                                    <input name="<?php echo esc_attr(GS_Index::OPT_YANDEX); ?>" type="text"
                                           value="<?php echo esc_attr(get_option(GS_Index::OPT_YANDEX, '')); ?>" class="regular-text"
                                           placeholder="можно вставить целиком мета-тег">
                                </label>
                            </p>
                            <p>
                                <label>Код подтверждения Google Search Console<br>
                                    <input name="<?php echo esc_attr(GS_Index::OPT_GOOGLE); ?>" type="text"
                                           value="<?php echo esc_attr(get_option(GS_Index::OPT_GOOGLE, '')); ?>" class="regular-text"
                                           placeholder="можно вставить целиком мета-тег">
                                </label>
                            </p>
                            <p>
                                <label><input type="checkbox" name="<?php echo esc_attr(GS_Index::OPT_ENABLED); ?>" value="1" <?php checked(GS_Index::enabled()); ?>>
                                    сообщать Яндексу и Bing о новых страницах сразу (IndexNow)</label>
                            </p>
                            <p>
                                <label>Токен API Яндекс.Вебмастера<br>
                                    <input name="<?php echo esc_attr(GS_Webmaster::OPT_TOKEN); ?>" type="password" autocomplete="off"
                                           value="<?php echo esc_attr(get_option(GS_Webmaster::OPT_TOKEN, '')); ?>" class="regular-text">
                                </label>
                            </p>
                            <p class="description">
                                С токеном сайт сам забирает замечания Вебмастера и умеет отправлять страницы
                                на переобход — список появится ниже. Токен выдаётся на
                                <a href="https://oauth.yandex.ru" target="_blank" rel="noopener">oauth.yandex.ru</a>
                                с правами <code>webmaster:hostinfo</code> и <code>webmaster:verify</code>,
                                живёт полгода и отзывается в настройках аккаунта.
                            </p>
                            <p class="description">
                                Ключ подтверждения лежит по адресу
                                <a href="<?php echo esc_url(GS_Index::key_url()); ?>" target="_blank" rel="noopener"><?php echo esc_html(GS_Index::key_url()); ?></a>
                                — он создаётся сам и менять его не нужно. Мета-теги выводятся только на главной: этого хватает
                                обоим вебмастерам. Без подтверждения прав ни отчёты, ни переобход недоступны.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Пробный баланс для API</th>
                        <td>
                            <p>
                                <input name="<?php echo esc_attr(GS_Api_Keys::OPT_TRIAL); ?>" type="number"
                                       step="10" min="0" class="small-text"
                                       value="<?php echo esc_attr(GS_Api_Keys::trial_amount()); ?>"> ₽
                            </p>
                            <p class="description">
                                Начисляется один раз на аккаунт при выпуске первого ключа API.
                                Разработчик не станет платить, чтобы проверить работоспособность, —
                                он возьмёт сервис, где можно попробовать даром. Ноль выключает подарок.
                                Отметка стоит на аккаунте, поэтому второй ключ денег не приносит.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Дополнительные суммы пополнения</th>
                        <td>
                            <p>
                                <input name="<?php echo esc_attr(GS_Payments::OPT_EXTRA); ?>" type="text"
                                       value="<?php echo esc_attr(implode(', ', (array) get_option(GS_Payments::OPT_EXTRA, array()))); ?>"
                                       class="regular-text" placeholder="например: 100">
                            </p>
                            <p class="description">
                                Через запятую. Платёжный маршрут сейчас принимает
                                <?php echo esc_html(implode(', ', array(200, 300, 400, 500))); ?> ₽ — этот список зашит
                                в плагине озвучки, и точки расширения у него нет. Добавляйте сюда сумму только
                                после того, как она разрешена там: иначе кнопка появится, а оплата вернёт отказ.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Заявки в Telegram</th>
                        <td>
                            <p>
                                <label>Токен бота<br>
                                    <input name="<?php echo esc_attr(GS_Leads::OPT_TOKEN); ?>" type="password" autocomplete="off"
                                           value="<?php echo esc_attr(get_option(GS_Leads::OPT_TOKEN, '')); ?>" class="regular-text">
                                </label>
                            </p>
                            <p>
                                <label>ID чата или канала<br>
                                    <input name="<?php echo esc_attr(GS_Leads::OPT_CHAT); ?>" type="text" autocomplete="off"
                                           value="<?php echo esc_attr(get_option(GS_Leads::OPT_CHAT, '')); ?>" class="regular-text">
                                </label>
                            </p>
                            <p class="description">
                                Заявки с лендинга обучения уходят сообщением в этот чат. Копия каждой заявки
                                остаётся в журнале ниже, поэтому недоступность Telegram заявку не теряет.
                                Чтобы бот смог написать первым, ему нужно один раз отправить <code>/start</code>.
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

            <?php echo self::render_manual_queue(); // phpcs:ignore WordPress.Security.EscapeOutput ?>

            <?php echo self::render_payments(); // phpcs:ignore WordPress.Security.EscapeOutput ?>

            <?php echo self::render_index_tools(); // phpcs:ignore WordPress.Security.EscapeOutput ?>

            <?php echo self::render_provider(); // phpcs:ignore WordPress.Security.EscapeOutput ?>

            <?php echo GS_Schedule::render_panel(); // phpcs:ignore WordPress.Security.EscapeOutput ?>

            <?php echo self::render_promt(); // phpcs:ignore WordPress.Security.EscapeOutput ?>

            <?php echo self::render_stuck_payments(); // phpcs:ignore WordPress.Security.EscapeOutput ?>

            <?php echo self::render_leads(); // phpcs:ignore WordPress.Security.EscapeOutput ?>

            <?php echo self::render_webmaster(); // phpcs:ignore WordPress.Security.EscapeOutput ?>

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
