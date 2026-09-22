<?php
/**
 * Админка модуля: список ассистентов, карточка ассистента, токены телеграм-ботов, настройки.
 */

if (!defined('ABSPATH')) {
    exit;
}

class GA_Admin
{
    private const CAP = 'manage_options';
    private const SLUG = 'genius-assistants';

    public static function menu(): void
    {
        add_menu_page('ИИ-ассистенты', 'ИИ-ассистенты', self::CAP, self::SLUG,
            [self::class, 'page_assistants'], 'dashicons-format-chat', 31);
        add_submenu_page(self::SLUG, 'Ассистенты', 'Ассистенты', self::CAP, self::SLUG,
            [self::class, 'page_assistants']);
        add_submenu_page(self::SLUG, 'Токены ботов', 'Токены ботов', self::CAP,
            self::SLUG . '-tokens', [self::class, 'page_tokens']);
        add_submenu_page(self::SLUG, 'Настройки ассистентов', 'Настройки', self::CAP,
            self::SLUG . '-settings', [self::class, 'page_settings']);
    }

    public static function assets($hook): void
    {
        if (!is_string($hook) || !str_contains($hook, self::SLUG)) {
            return;
        }
        wp_enqueue_style('ga-admin', GA_URL . '/assets/css/admin.css', [], GA_VERSION);
    }

    private static function redirect(string $page, array $args = []): void
    {
        wp_safe_redirect(add_query_arg(array_merge(['page' => $page], $args), admin_url('admin.php')));
        exit;
    }

    // ------------------------------------------------------------------ обработка форм

    public static function handle_post(): void
    {
        if (empty($_POST['ga_action']) || !current_user_can(self::CAP)) {
            return;
        }
        $action = sanitize_key(wp_unslash($_POST['ga_action']));
        check_admin_referer('ga_' . $action);

        switch ($action) {
            case 'save_assistant':
                self::save_assistant();
                break;
            case 'add_token':
                self::add_token();
                break;
            case 'token_op':
                self::token_op();
                break;
            case 'save_settings':
                self::save_settings();
                break;
        }
    }

    private static function save_assistant(): void
    {
        $id = (int) ($_POST['assistant_id'] ?? 0);
        if (!$id || !GA_Store::assistant($id)) {
            self::redirect(self::SLUG);
        }
        $actions = [];
        $labels = (array) ($_POST['action_label'] ?? []);
        $prompts = (array) ($_POST['action_prompt'] ?? []);
        foreach ($labels as $i => $label) {
            $label = sanitize_text_field(wp_unslash($label));
            $prompt = sanitize_textarea_field(wp_unslash($prompts[$i] ?? ''));
            if ($label !== '' && $prompt !== '') {
                $actions[] = ['label' => $label, 'prompt' => $prompt];
            }
        }
        GA_Store::update_assistant($id, [
            'name' => sanitize_text_field(wp_unslash($_POST['name'] ?? '')),
            'tagline' => sanitize_text_field(wp_unslash($_POST['tagline'] ?? '')),
            'emoji' => sanitize_text_field(wp_unslash($_POST['emoji'] ?? '')),
            'accent' => sanitize_hex_color(wp_unslash($_POST['accent'] ?? '')) ?: '#6366f1',
            'category' => sanitize_text_field(wp_unslash($_POST['category'] ?? '')),
            'is_active' => empty($_POST['is_active']) ? 0 : 1,
            'tg_enabled' => empty($_POST['tg_enabled']) ? 0 : 1,
            'web_enabled' => empty($_POST['web_enabled']) ? 0 : 1,
            'position' => (int) ($_POST['position'] ?? 0),
            'chat_model' => sanitize_text_field(wp_unslash($_POST['chat_model'] ?? '')),
            'temperature' => (float) ($_POST['temperature'] ?? 0.4),
            'system_prompt' => sanitize_textarea_field(wp_unslash($_POST['system_prompt'] ?? '')),
            'knowledge' => sanitize_textarea_field(wp_unslash($_POST['knowledge'] ?? '')),
            'welcome' => sanitize_textarea_field(wp_unslash($_POST['welcome'] ?? '')),
            'disclaimer' => sanitize_textarea_field(wp_unslash($_POST['disclaimer'] ?? '')),
            'quick_actions' => wp_json_encode($actions, JSON_UNESCAPED_UNICODE),
            'history_depth' => max(2, (int) ($_POST['history_depth'] ?? 12)),
            'max_input_chars' => max(500, (int) ($_POST['max_input_chars'] ?? 6000)),
            'free_daily_limit' => max(0, (int) ($_POST['free_daily_limit'] ?? 5)),
            'price_month' => max(0, (int) ($_POST['price_month'] ?? 0)),
            'landing_url' => esc_url_raw(wp_unslash($_POST['landing_url'] ?? '')),
            'seo_title' => sanitize_text_field(wp_unslash($_POST['seo_title'] ?? '')),
            'seo_description' => sanitize_text_field(wp_unslash($_POST['seo_description'] ?? '')),
            'keywords' => sanitize_textarea_field(wp_unslash($_POST['keywords'] ?? '')),
        ]);
        self::redirect(self::SLUG, ['assistant' => $id, 'ga_msg' => 'saved']);
    }

    private static function add_token(): void
    {
        $assistant_id = (int) ($_POST['assistant_id'] ?? 0);
        $token = trim(sanitize_text_field(wp_unslash($_POST['token'] ?? '')));
        $label = sanitize_text_field(wp_unslash($_POST['label'] ?? ''));

        if (!$assistant_id || !preg_match('/^\d{6,}:[A-Za-z0-9_-]{30,}$/', $token)) {
            self::redirect(self::SLUG . '-tokens', ['ga_msg' => 'bad_token']);
        }
        if (GA_Store::token_by_value($token)) {
            self::redirect(self::SLUG . '-tokens', ['ga_msg' => 'dup_token']);
        }
        $id = GA_Store::add_token($assistant_id, $token, $label);
        $result = GA_Telegram::connect(GA_Store::token($id));
        self::redirect(self::SLUG . '-tokens',
            ['ga_msg' => $result['ok'] ? 'connected' : 'tg_error']);
    }

    private static function token_op(): void
    {
        $id = (int) ($_POST['token_id'] ?? 0);
        $row = GA_Store::token($id);
        if (!$row) {
            self::redirect(self::SLUG . '-tokens');
        }
        $op = sanitize_key(wp_unslash($_POST['op'] ?? ''));
        if ($op === 'delete') {
            GA_Telegram::disconnect($row);
            GA_Store::delete_token($id);
            self::redirect(self::SLUG . '-tokens', ['ga_msg' => 'deleted']);
        }
        if ($op === 'pause') {
            GA_Telegram::disconnect($row);
            GA_Store::update_token($id, ['is_active' => 0]);
            self::redirect(self::SLUG . '-tokens', ['ga_msg' => 'paused']);
        }
        // reconnect / resume
        GA_Store::update_token($id, ['is_active' => 1]);
        $result = GA_Telegram::connect(GA_Store::token($id));
        self::redirect(self::SLUG . '-tokens',
            ['ga_msg' => $result['ok'] ? 'connected' : 'tg_error']);
    }

    private static function save_settings(): void
    {
        update_option(GA_Billing::OPT_PRICE, max(0, (float) ($_POST['price'] ?? 2)), false);
        update_option(GA_Billing::OPT_FREE_GUEST, max(0, (int) ($_POST['guest_free'] ?? 5)), false);
        update_option(GA_Kie::OPT_MODEL,
            sanitize_text_field(wp_unslash($_POST['model'] ?? '')) ?: 'gemini-3-8-flash-openai', false);
        self::redirect(self::SLUG . '-settings', ['ga_msg' => 'saved']);
    }

    // ------------------------------------------------------------------ вывод

    private static function notice(): void
    {
        $messages = [
            'saved' => ['updated', 'Сохранено.'],
            'connected' => ['updated', 'Бот подключён: вебхук установлен, команды заданы.'],
            'deleted' => ['updated', 'Токен удалён, вебхук снят.'],
            'paused' => ['updated', 'Бот остановлен: вебхук снят, токен сохранён.'],
            'bad_token' => ['error', 'Это не похоже на токен бота. Формат: 123456789:AA…'],
            'dup_token' => ['error', 'Такой токен уже добавлен.'],
            'tg_error' => ['error', 'Telegram не принял токен — подробности в колонке «Состояние».'],
        ];
        $key = sanitize_key(wp_unslash($_GET['ga_msg'] ?? ''));
        if (isset($messages[$key])) {
            printf('<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
                esc_attr($messages[$key][0]), esc_html($messages[$key][1]));
        }
    }

    public static function page_assistants(): void
    {
        $id = (int) ($_GET['assistant'] ?? 0);
        echo '<div class="wrap ga-wrap">';
        self::notice();
        if ($id && ($assistant = GA_Store::assistant($id))) {
            self::form_assistant($assistant);
        } else {
            self::list_assistants();
        }
        echo '</div>';
    }

    private static function list_assistants(): void
    {
        $items = GA_Store::assistants();
        ?>
        <h1>ИИ-ассистенты</h1>
        <p class="description">
          Каждый ассистент — это телеграм-бот и веб-версия на одном аккаунте пользователя.
          Баланс общий со всеми сервисами: сколько денег на счёте в озвучке, столько же и здесь.
        </p>
        <table class="widefat striped ga-table">
          <thead><tr>
            <th>Ассистент</th><th>Каналы</th><th>Боты</th><th>Бесплатно в сутки</th>
            <th>Подписка</th><th>Диалогов</th><th>Сообщений сегодня</th><th>Шорткод</th>
          </tr></thead>
          <tbody>
          <?php foreach ($items as $a):
              $stats = GA_Store::stats((int) $a['id']);
              $bots = count(GA_Store::tokens((int) $a['id'])); ?>
            <tr>
              <td>
                <strong><a href="<?php echo esc_url(add_query_arg(
                    ['page' => self::SLUG, 'assistant' => (int) $a['id']], admin_url('admin.php'))); ?>">
                  <?php echo esc_html($a['emoji'] . ' ' . $a['name']); ?>
                </a></strong>
                <?php if (!$a['is_active']): ?><span class="ga-pill ga-pill--off">выключен</span><?php endif; ?>
                <div class="row-actions"><span><?php echo esc_html($a['tagline']); ?></span></div>
              </td>
              <td>
                <?php echo $a['tg_enabled'] ? 'Telegram' : '—'; ?><br>
                <?php echo $a['web_enabled'] ? 'Веб' : '—'; ?>
              </td>
              <td><?php echo $bots ?: '—'; ?></td>
              <td><?php echo (int) $a['free_daily_limit']; ?></td>
              <td><?php echo $a['price_month'] ? esc_html($a['price_month']) . ' ₽/мес' : '—'; ?></td>
              <td><?php echo (int) $stats['threads']; ?></td>
              <td><?php echo (int) $stats['today']; ?></td>
              <td><code>[genius_assistant slug="<?php echo esc_attr($a['slug']); ?>"]</code></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <h2>Галерея на главной</h2>
        <p>Вставьте на главную страницу шорткод <code>[genius_assistants_gallery]</code> —
           он выведет карточки всех включённых ассистентов со ссылками на их лендинги.</p>
        <?php
    }

    private static function form_assistant(array $a): void
    {
        $actions = GA_Store::actions($a);
        $actions[] = ['label' => '', 'prompt' => '']; // пустая строка для нового сценария
        ?>
        <h1><?php echo esc_html($a['emoji'] . ' ' . $a['name']); ?></h1>
        <p><a href="<?php echo esc_url(admin_url('admin.php?page=' . self::SLUG)); ?>">← Все ассистенты</a></p>
        <form method="post">
          <?php wp_nonce_field('ga_save_assistant'); ?>
          <input type="hidden" name="ga_action" value="save_assistant">
          <input type="hidden" name="assistant_id" value="<?php echo (int) $a['id']; ?>">

          <h2 class="title">Витрина</h2>
          <table class="form-table" role="presentation">
            <tr><th><label for="ga-name">Название</label></th>
              <td><input id="ga-name" name="name" class="regular-text" value="<?php echo esc_attr($a['name']); ?>"></td></tr>
            <tr><th><label for="ga-tagline">Подзаголовок</label></th>
              <td><input id="ga-tagline" name="tagline" class="large-text" value="<?php echo esc_attr($a['tagline']); ?>"></td></tr>
            <tr><th><label for="ga-emoji">Значок и цвет</label></th>
              <td>
                <input id="ga-emoji" name="emoji" size="4" value="<?php echo esc_attr($a['emoji']); ?>">
                <input type="color" name="accent" value="<?php echo esc_attr($a['accent']); ?>">
                <input name="category" value="<?php echo esc_attr($a['category']); ?>" placeholder="Категория">
                <input name="position" type="number" value="<?php echo (int) $a['position']; ?>" size="4"
                       title="Порядок в галерее">
              </td></tr>
            <tr><th>Каналы</th>
              <td>
                <label><input type="checkbox" name="is_active" <?php checked($a['is_active']); ?>> Показывать</label><br>
                <label><input type="checkbox" name="tg_enabled" <?php checked($a['tg_enabled']); ?>> Telegram</label><br>
                <label><input type="checkbox" name="web_enabled" <?php checked($a['web_enabled']); ?>> Веб-версия</label>
              </td></tr>
            <tr><th><label for="ga-landing">Ссылка на лендинг</label></th>
              <td><input id="ga-landing" name="landing_url" class="large-text"
                         value="<?php echo esc_attr($a['landing_url']); ?>"></td></tr>
          </table>

          <h2 class="title">Поведение</h2>
          <table class="form-table" role="presentation">
            <tr><th><label for="ga-model">Модель</label></th>
              <td>
                <input id="ga-model" name="chat_model" class="regular-text"
                       value="<?php echo esc_attr($a['chat_model']); ?>">
                <input name="temperature" type="number" step="0.05" min="0" max="2" size="4"
                       value="<?php echo esc_attr($a['temperature']); ?>" title="Температура">
                <p class="description">Ключ KIE берётся тот же, что у озвучки (<code>kie_tts_api_key</code>).</p>
              </td></tr>
            <tr><th><label for="ga-prompt">Системный промпт</label></th>
              <td><textarea id="ga-prompt" name="system_prompt" rows="18" class="large-text code"
                ><?php echo esc_textarea($a['system_prompt']); ?></textarea></td></tr>
            <tr><th><label for="ga-knowledge">База знаний</label></th>
              <td><textarea id="ga-knowledge" name="knowledge" rows="8" class="large-text"
                ><?php echo esc_textarea($a['knowledge'] ?? ''); ?></textarea>
                <p class="description">Цены, условия, услуги — всё, что ассистент вправе утверждать.
                   Чего здесь нет, того он не придумает.</p></td></tr>
            <tr><th><label for="ga-welcome">Приветствие</label></th>
              <td><textarea id="ga-welcome" name="welcome" rows="4" class="large-text"
                ><?php echo esc_textarea($a['welcome']); ?></textarea></td></tr>
            <tr><th><label for="ga-disclaimer">Приписка к ответам</label></th>
              <td><textarea id="ga-disclaimer" name="disclaimer" rows="2" class="large-text"
                ><?php echo esc_textarea($a['disclaimer']); ?></textarea>
                <p class="description">Добавляется в конец каждого ответа. Для юриста — обязательна.</p></td></tr>
          </table>

          <h2 class="title">Сценарии (кнопки)</h2>
          <table class="widefat striped ga-actions">
            <thead><tr><th style="width:230px">Кнопка</th><th>Шаблон запроса</th></tr></thead>
            <tbody>
            <?php foreach ($actions as $action): ?>
              <tr>
                <td><input name="action_label[]" class="widefat"
                           value="<?php echo esc_attr($action['label']); ?>"></td>
                <td><textarea name="action_prompt[]" rows="2" class="widefat"
                  ><?php echo esc_textarea($action['prompt']); ?></textarea></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
          <p class="description">Пустая строка внизу — для нового сценария. Чтобы удалить, очистите оба поля.</p>

          <h2 class="title">Лимиты и цена</h2>
          <table class="form-table" role="presentation">
            <tr><th><label for="ga-free">Бесплатно в сутки</label></th>
              <td><input id="ga-free" name="free_daily_limit" type="number" min="0"
                         value="<?php echo (int) $a['free_daily_limit']; ?>">
                <p class="description">Дальше сообщения идут с общего баланса по цене из настроек.</p></td></tr>
            <tr><th><label for="ga-price">Подписка, ₽/мес</label></th>
              <td><input id="ga-price" name="price_month" type="number" min="0"
                         value="<?php echo (int) $a['price_month']; ?>">
                <p class="description">Витринная цена для лендинга.</p></td></tr>
            <tr><th>Контекст</th>
              <td>
                <input name="history_depth" type="number" min="2" value="<?php echo (int) $a['history_depth']; ?>"
                       title="Сколько прошлых реплик помнить"> реплик памяти,
                <input name="max_input_chars" type="number" min="500" value="<?php echo (int) $a['max_input_chars']; ?>">
                символов на сообщение
              </td></tr>
          </table>

          <h2 class="title">SEO</h2>
          <table class="form-table" role="presentation">
            <tr><th><label for="ga-seo-title">Title</label></th>
              <td><input id="ga-seo-title" name="seo_title" class="large-text"
                         value="<?php echo esc_attr($a['seo_title']); ?>"></td></tr>
            <tr><th><label for="ga-seo-desc">Description</label></th>
              <td><textarea id="ga-seo-desc" name="seo_description" rows="2" class="large-text"
                ><?php echo esc_textarea($a['seo_description']); ?></textarea></td></tr>
            <tr><th><label for="ga-keys">Ключи кластера</label></th>
              <td><textarea id="ga-keys" name="keywords" rows="3" class="large-text"
                ><?php echo esc_textarea($a['keywords']); ?></textarea></td></tr>
          </table>

          <?php submit_button('Сохранить ассистента'); ?>
        </form>
        <?php
    }

    public static function page_tokens(): void
    {
        $assistants = GA_Store::assistants();
        $tokens = GA_Store::tokens();
        $by_id = [];
        foreach ($assistants as $a) {
            $by_id[(int) $a['id']] = $a;
        }
        echo '<div class="wrap ga-wrap">';
        self::notice();
        ?>
        <h1>Токены телеграм-ботов</h1>
        <p class="description">
          Создайте бота у <a href="https://t.me/BotFather" target="_blank" rel="noopener">@BotFather</a>,
          скопируйте токен и добавьте его сюда. Вебхук, команды и описание бота настроятся сами.
          Токен хранится в базе и в интерфейсе показывается обрезанным.
        </p>

        <h2>Добавить бота</h2>
        <form method="post" class="ga-add-token">
          <?php wp_nonce_field('ga_add_token'); ?>
          <input type="hidden" name="ga_action" value="add_token">
          <select name="assistant_id" required>
            <option value="">— ассистент —</option>
            <?php foreach ($assistants as $a): ?>
              <option value="<?php echo (int) $a['id']; ?>">
                <?php echo esc_html($a['emoji'] . ' ' . $a['name']); ?>
              </option>
            <?php endforeach; ?>
          </select>
          <input name="token" class="regular-text" required
                 placeholder="123456789:AAE…" autocomplete="off" spellcheck="false">
          <input name="label" placeholder="Заметка (необязательно)">
          <?php submit_button('Подключить', 'primary', 'submit', false); ?>
        </form>

        <h2>Подключённые боты</h2>
        <?php if (!$tokens): ?>
          <p>Пока ни одного. Добавьте токен выше — бот заработает сразу после подключения.</p>
        <?php else: ?>
        <table class="widefat striped ga-table">
          <thead><tr>
            <th>Бот</th><th>Ассистент</th><th>Токен</th><th>Состояние</th><th>Проверен</th><th></th>
          </tr></thead>
          <tbody>
          <?php foreach ($tokens as $t):
              $a = $by_id[(int) $t['assistant_id']] ?? null; ?>
            <tr>
              <td>
                <?php if ($t['bot_username']): ?>
                  <strong><a href="https://t.me/<?php echo esc_attr($t['bot_username']); ?>"
                             target="_blank" rel="noopener">@<?php echo esc_html($t['bot_username']); ?></a></strong>
                <?php else: ?>
                  <em>имя ещё не получено</em>
                <?php endif; ?>
                <?php if ($t['label']): ?>
                  <div class="row-actions"><span><?php echo esc_html($t['label']); ?></span></div>
                <?php endif; ?>
              </td>
              <td><?php echo $a ? esc_html($a['emoji'] . ' ' . $a['name']) : '<em>удалён</em>'; ?></td>
              <td><code><?php echo esc_html(GA_Store::mask((string) $t['token'])); ?></code></td>
              <td>
                <?php
                $labels = [
                    'connected' => ['ok', 'работает'],
                    'idle' => ['off', 'не подключён'],
                    'stopped' => ['off', 'остановлен'],
                    'error' => ['err', 'ошибка'],
                ];
                [$cls, $text] = $labels[$t['status']] ?? ['off', $t['status']];
                if (!$t['is_active']) {
                    [$cls, $text] = ['off', 'остановлен'];
                }
                printf('<span class="ga-pill ga-pill--%s">%s</span>', esc_attr($cls), esc_html($text));
                if ($t['last_error']) {
                    echo '<div class="ga-err">' . esc_html($t['last_error']) . '</div>';
                }
                ?>
              </td>
              <td><?php echo $t['checked_at']
                    ? esc_html(mysql2date('j M, H:i', $t['checked_at'])) : '—'; ?></td>
              <td class="ga-ops">
                <form method="post" class="ga-inline">
                  <?php wp_nonce_field('ga_token_op'); ?>
                  <input type="hidden" name="ga_action" value="token_op">
                  <input type="hidden" name="token_id" value="<?php echo (int) $t['id']; ?>">
                  <button class="button" name="op" value="reconnect">Переподключить</button>
                  <?php if ($t['is_active']): ?>
                    <button class="button" name="op" value="pause">Остановить</button>
                  <?php endif; ?>
                  <button class="button button-link-delete" name="op" value="delete"
                          onclick="return confirm('Удалить токен и снять вебхук?')">Удалить</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <?php endif;
        echo '</div>';
    }

    public static function page_settings(): void
    {
        echo '<div class="wrap ga-wrap">';
        self::notice();
        ?>
        <h1>Настройки ассистентов</h1>
        <form method="post">
          <?php wp_nonce_field('ga_save_settings'); ?>
          <input type="hidden" name="ga_action" value="save_settings">
          <table class="form-table" role="presentation">
            <tr><th><label for="ga-set-price">Цена сообщения, ₽</label></th>
              <td><input id="ga-set-price" name="price" type="number" step="0.5" min="0"
                         value="<?php echo esc_attr(GA_Billing::price_per_message()); ?>">
                <p class="description">Списывается с того же баланса, что и озвучка,
                   и только после того, как ответ действительно получен.</p></td></tr>
            <tr><th><label for="ga-set-guest">Гостю бесплатно</label></th>
              <td><input id="ga-set-guest" name="guest_free" type="number" min="0"
                         value="<?php echo (int) GA_Billing::guest_free_limit(); ?>">
                <p class="description">Сколько сообщений можно отправить без входа.</p></td></tr>
            <tr><th><label for="ga-set-model">Модель по умолчанию</label></th>
              <td><input id="ga-set-model" name="model" class="regular-text"
                         value="<?php echo esc_attr(GA_Kie::default_model()); ?>"></td></tr>
          </table>
          <?php submit_button(); ?>
        </form>

        <h2>Как устроен баланс</h2>
        <p>
          Модуль не заводит своего счёта. Он спрашивает баланс у kie-tts-wp: сначала через фильтры
          <code>ga_balance_get</code> и <code>ga_balance_charge</code>, потом через функции
          <code>kie_tts_get_balance()</code> / <code>kie_tts_charge()</code>, и лишь в последнюю
          очередь читает таблицу <code><?php global $wpdb; echo esc_html($wpdb->prefix); ?>kie_tts_balance</code>
          напрямую. Чтобы закрепить связь жёстко, добавьте в kie-tts-wp:
        </p>
        <pre class="ga-code">add_filter('ga_balance_get',    fn($_, $uid) =&gt; kie_tts_get_balance($uid), 10, 2);
add_filter('ga_balance_charge', fn($_, $uid, $sum, $note) =&gt; kie_tts_charge($uid, $sum, $note), 10, 4);</pre>
        <?php
        echo '</div>';
    }
}
