<?php
/**
 * Шорткоды: галерея ассистентов для главной и чат-виджет для лендинга.
 *
 *   [genius_assistants_gallery]                — витрина всех активных ассистентов
 *   [genius_assistant slug="uchitel"]          — рабочий чат на странице ассистента
 *   [genius_assistant slug="uchitel" compact="1"]
 */

if (!defined('ABSPATH')) {
    exit;
}

class GA_Shortcodes
{
    public static function init(): void
    {
        add_shortcode('genius_assistants_gallery', [self::class, 'gallery']);
        add_shortcode('genius_assistant', [self::class, 'widget']);
        add_shortcode('genius_knowledge_base', [self::class, 'knowledge_base']);
        add_shortcode('genius_bind_telegram', [self::class, 'bind_telegram']);
        add_shortcode('genius_open', [self::class, 'open_page']);
    }

    /** Только свой путь на этом же сайте — без внешних редиректов. */
    public static function safe_path(string $raw): string
    {
        $raw = trim(wp_unslash($raw));
        if ($raw === '') {
            return '';
        }
        $p = wp_parse_url($raw);
        if (!empty($p['host']) || !empty($p['scheme'])) {
            return '';
        }
        $path = '/' . ltrim((string) ($p['path'] ?? ''), '/');
        $query = isset($p['query']) ? '?' . $p['query'] : '';
        $frag = isset($p['fragment']) ? '#' . $p['fragment'] : '';
        return $path . $query . $frag;
    }

    /**
     * Возврат на страницу, с которой пользователь пошёл авторизовываться.
     * Кнопка «Войти» кладёт исходный URL в куку ga_login_return; после входа
     * (даже если чужой вход перебросил на дашборд) возвращаем человека туда.
     */
    public static function maybe_login_return(): void
    {
        if (is_admin() || !is_user_logged_in() || empty($_COOKIE['ga_login_return'])) {
            return;
        }
        $return = self::safe_path((string) wp_unslash($_COOKIE['ga_login_return']));
        setcookie('ga_login_return', '', [
            'expires' => time() - 3600, 'path' => '/', 'samesite' => 'Lax', 'secure' => is_ssl(),
        ]);
        unset($_COOKIE['ga_login_return']);
        if ($return === '') {
            return;
        }
        $current = '/' . ltrim((string) wp_parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH), '/');
        $target = '/' . ltrim((string) wp_parse_url($return, PHP_URL_PATH), '/');
        if ($current === $target) {
            return; // уже на нужной странице (вход перезагрузил её же)
        }
        wp_safe_redirect(home_url($return));
        exit;
    }

    /**
     * «Переходник»: бот отправляет сюда с ?to=/сервис. Вошедшего сразу форвардим
     * на сервис, гостю даём войти — после входа модалка перезагружает эту же
     * страницу, и форвард срабатывает.
     */
    public static function maybe_open(): void
    {
        if (!is_page('perehod')) {
            return;
        }
        $to = self::safe_path((string) ($_GET['to'] ?? ''));
        if ($to !== '' && is_user_logged_in()) {
            wp_safe_redirect(home_url($to));
            exit;
        }
    }

    private static function kb_assets(): void
    {
        wp_enqueue_style('ga-assistants', GA_URL . '/assets/css/assistants.css', [], GA_VERSION);
        wp_enqueue_script('kie-tts-auth-modal');
        wp_enqueue_script('ga-kb', GA_URL . '/assets/js/kb.js', [], GA_VERSION, true);
        wp_localize_script('ga-kb', 'gaKB', [
            'rest' => esc_url_raw(rest_url(GA_REST_NS . '/')),
            'nonce' => wp_create_nonce('wp_rest'),
        ]);
    }

    private static function assets(): void
    {
        wp_enqueue_style('ga-assistants', GA_URL . '/assets/css/assistants.css', [], GA_VERSION);
        // Модалка входа — та же, что у микросервисов. Если kie-tts-wp её уже подключил,
        // WordPress не подключит второй раз.
        wp_enqueue_script('kie-tts-auth-modal');
        wp_enqueue_script('ga-chat', GA_URL . '/assets/js/chat.js', [], GA_VERSION, true);
        wp_localize_script('ga-chat', 'gaChat', [
            'rest' => esc_url_raw(rest_url(GA_REST_NS . '/')),
            // Пополнение общее с микросервисами: тот же REST-маршрут kie-tts-wp,
            // тот же nonce (действие wp_rest единое для всех маршрутов WP REST).
            'ttsRest' => esc_url_raw(rest_url('tts/v1/')),
            'nonce' => wp_create_nonce('wp_rest'),
            'loggedIn' => is_user_logged_in(),
            'loginUrl' => home_url('/tts-login/'),
            'topupAmounts' => self::topup_amounts(),
            'currency' => '₽',
        ]);
    }

    /** Суммы пополнения — те же, что в модалке микросервисов. */
    private static function topup_amounts(): array
    {
        $raw = get_option('ga_topup_amounts', '200,300,400,500');
        $out = [];
        foreach (explode(',', (string) $raw) as $part) {
            $v = (int) trim($part);
            if ($v > 0) {
                $out[] = $v;
            }
        }
        return $out ?: [200, 300, 400, 500];
    }

    /** Баланс в рублях без лишних нулей: 48, 48.5, 120. */
    private static function money(float $value): string
    {
        $rounded = round($value, 2);
        if (abs($rounded - round($rounded)) < 0.005) {
            return number_format_i18n((int) round($rounded), 0);
        }
        return rtrim(rtrim(number_format_i18n($rounded, 2), '0'), '.,');
    }

    public static function gallery($atts = []): string
    {
        $atts = shortcode_atts(['wave' => '', 'title' => 'ИИ-ассистенты Genius'], $atts);
        self::assets();
        $items = GA_Store::assistants(true);
        if ($atts['wave'] !== '') {
            $items = array_filter($items, fn($a) => (int) $a['wave'] === (int) $atts['wave']);
        }
        if (!$items) {
            return '';
        }

        ob_start(); ?>
        <section class="ga-gallery">
          <div class="ga-gallery__grid">
            <?php foreach ($items as $a):
                $url = $a['landing_url'] ?: '#'; ?>
              <a class="ga-card" href="<?php echo esc_url($url); ?>"
                 style="--ga-accent: <?php echo esc_attr($a['accent']); ?>">
                <span class="ga-card__icon" aria-hidden="true"><?php echo esc_html($a['emoji']); ?></span>
                <span class="ga-card__cat"><?php echo esc_html($a['category']); ?></span>
                <h3 class="ga-card__name"><?php echo esc_html($a['name']); ?></h3>
                <p class="ga-card__tag"><?php echo esc_html($a['tagline']); ?></p>
                <span class="ga-card__foot">
                  <span class="ga-card__free">
                    <?php echo (int) $a['free_daily_limit']; ?> сообщений в день бесплатно
                  </span>
                  <span class="ga-card__go">Открыть →</span>
                </span>
              </a>
            <?php endforeach; ?>
          </div>
        </section>
        <?php
        return (string) ob_get_clean();
    }

    public static function widget($atts = []): string
    {
        $atts = shortcode_atts(['slug' => '', 'compact' => '0'], $atts);
        $assistant = GA_Store::assistant_by_slug(sanitize_key($atts['slug']));
        if (!$assistant || !$assistant['is_active'] || !$assistant['web_enabled']) {
            return '';
        }
        self::assets();

        $actions = GA_Store::actions($assistant);
        $bot = self::bot_username((int) $assistant['id']);
        $user_id = get_current_user_id();
        $can_upload = GA_Billing::can_upload($user_id);
        $price_file = GA_Billing::price_per_file();
        $balance = $user_id ? GA_Billing::balance($user_id) : 0.0;
        $topup_amounts = self::topup_amounts();

        ob_start(); ?>
        <div class="ga-chat<?php echo $atts['compact'] === '1' ? ' ga-chat--compact' : ''; ?>"
             data-slug="<?php echo esc_attr($assistant['slug']); ?>"
             data-can-upload="<?php echo $can_upload ? '1' : '0'; ?>"
             data-price-file="<?php echo esc_attr($price_file); ?>"
             style="--ga-accent: <?php echo esc_attr($assistant['accent']); ?>">
          <header class="ga-chat__head">
            <span class="ga-chat__icon" aria-hidden="true"><?php echo esc_html($assistant['emoji']); ?></span>
            <span class="ga-chat__title">
              <strong><?php echo esc_html($assistant['name']); ?></strong>
              <small><?php echo esc_html($assistant['tagline']); ?></small>
            </span>
            <?php if ($bot): ?>
              <a class="ga-chat__tg" href="https://t.me/<?php echo esc_attr($bot); ?>?start=web"
                 target="_blank" rel="noopener">Открыть в Telegram</a>
            <?php endif; ?>
          </header>

          <?php if ($user_id): ?>
            <div class="ga-account">
              <div class="ga-account__top">
                <div class="ga-balance">
                  <span class="ga-balance__label">Ваш баланс</span>
                  <strong class="ga-balance__value" data-ga-balance><?php
                    echo esc_html(self::money($balance)); ?>&nbsp;₽</strong>
                </div>
                <div class="ga-support">
                  <button type="button" class="ga-support__main" data-ga-support>🛟 Техническая поддержка</button>
                  <button type="button" class="ga-support__ask" data-ga-support>Задать вопрос</button>
                </div>
              </div>
              <div class="ga-topup">
                <span class="ga-topup__label">Пополнить баланс через ЮMoney</span>
                <div class="ga-topup__amounts">
                  <?php foreach ($topup_amounts as $amt): ?>
                    <button type="button" class="ga-topup__amount" data-amount="<?php echo (int) $amt; ?>">
                      <?php echo (int) $amt; ?>&nbsp;₽
                    </button>
                  <?php endforeach; ?>
                </div>
                <a class="ga-topup__link" hidden target="_blank" rel="noopener"></a>
                <p class="ga-topup__msg" hidden role="status"></p>
              </div>
            </div>
          <?php endif; ?>

          <div class="ga-chat__log" role="log" aria-live="polite">
            <div class="ga-msg ga-msg--bot"><?php
              echo nl2br(esc_html($assistant['welcome'])); ?></div>
          </div>

          <?php if ($actions): ?>
            <div class="ga-chat__actions">
              <?php foreach ($actions as $action): ?>
                <button type="button" class="ga-chip"
                        data-prompt="<?php echo esc_attr($action['prompt']); ?>">
                  <?php echo esc_html($action['label']); ?>
                </button>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>

          <div class="ga-chat__filechip" hidden></div>
          <form class="ga-chat__form">
            <label class="ga-chat__attach<?php echo $can_upload ? '' : ' is-locked'; ?>"
                   title="Прикрепить документ или фото">
              <input type="file" class="ga-chat__file"
                     accept="image/jpeg,image/png,image/webp,.txt,.csv,.md,.docx" hidden>
              <span aria-hidden="true">📎</span>
            </label>
            <textarea class="ga-chat__input" rows="2"
                      maxlength="<?php echo (int) $assistant['max_input_chars']; ?>"
                      placeholder="Опишите задачу…"></textarea>
            <button type="submit" class="ga-chat__send">Спросить</button>
          </form>
          <p class="ga-chat__uploadnote" hidden>
            Подгрузка документов и фото доступна после авторизации на платном тарифе
            (<?php echo esc_html(number_format_i18n($price_file, 0)); ?> ₽ за разбор).
          </p>

          <p class="ga-chat__note">
            <?php if ($user_id): ?>
              Бесплатно <?php echo (int) $assistant['free_daily_limit']; ?> сообщений в сутки,
              дальше <?php echo esc_html(number_format_i18n(GA_Billing::price_per_message(), 0)); ?> ₽
              за ответ с общего баланса.
            <?php else: ?>
              Без входа доступно <?php echo GA_Billing::guest_free_limit(); ?> сообщений.
              <button type="button" class="ga-link kie-auth-open-trigger">Войти</button>
              — почтой, через VK или Telegram. Баланс общий со всеми сервисами Genius.
            <?php endif; ?>
          </p>

          <?php if ($assistant['disclaimer']): ?>
            <p class="ga-chat__disclaimer"><?php echo esc_html($assistant['disclaimer']); ?></p>
          <?php endif; ?>

          <?php if ($user_id): ?>
            <div class="ga-supmodal" hidden>
              <div class="ga-supmodal__bg" data-ga-support-close></div>
              <div class="ga-supmodal__box" role="dialog" aria-modal="true"
                   aria-label="Техническая поддержка">
                <button type="button" class="ga-supmodal__x" data-ga-support-close
                        aria-label="Закрыть">✕</button>
                <h3 class="ga-supmodal__title">Техническая поддержка</h3>
                <p class="ga-supmodal__lead">Опишите вопрос — ответим в Telegram или на указанный контакт.</p>
                <form class="ga-supform">
                  <textarea class="ga-supform__msg" rows="4" maxlength="2000" required
                            placeholder="Что случилось? Чем помочь?"></textarea>
                  <input type="text" class="ga-supform__contact" maxlength="120"
                         placeholder="Как ответить: @telegram, e-mail или телефон">
                  <button type="submit" class="ga-supform__send">Отправить</button>
                  <p class="ga-supform__status" hidden role="status"></p>
                </form>
              </div>
            </div>
          <?php endif; ?>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * Голая страница веб-виджета тенанта для встраивания через iframe на чужой сайт.
     * Без обвязки темы: только чат, свой на genius-bot.ru origin (запросы same-origin).
     */
    public static function maybe_consultant(): void
    {
        if (!is_page('consultant')) {
            return;
        }
        $key = isset($_GET['t']) ? preg_replace('/[^a-f0-9]/', '', (string) $_GET['t']) : '';
        $t = $key ? GA_Tenant::by_public_key($key) : null;
        nocache_headers();
        header('Content-Type: text/html; charset=utf-8');
        $css = esc_url(GA_URL . '/assets/css/assistants.css?v=' . GA_VERSION);
        if (!$t || !$t['is_active']) {
            echo '<!doctype html><meta charset="utf-8"><body style="margin:0;background:#0b1220;'
                . 'color:#e2e8f0;font-family:system-ui,sans-serif;padding:20px">Консультант недоступен.</body>';
            exit;
        }
        $accent = $t['accent'] ?: '#22d3ee';
        $name = esc_html($t['name'] ?: 'Консультант');
        $welcome = nl2br(esc_html(GA_Tenant::welcome_text($t)));
        $rest = esc_url(rest_url(GA_REST_NS . '/tenant/chat'));
        ?><!doctype html><html lang="ru"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?php echo $name; ?></title>
<link rel="stylesheet" href="<?php echo $css; ?>">
<style>html,body{margin:0;background:transparent}.ga-chat{margin:0;max-width:none;height:100vh;box-sizing:border-box}
.ga-chat__log{flex:1;max-height:none}</style>
</head><body>
<div class="ga-chat ga-tenant" style="--ga-accent:<?php echo esc_attr($accent); ?>">
  <header class="ga-chat__head"><span class="ga-chat__title"><strong><?php echo $name; ?></strong></span></header>
  <div class="ga-chat__log" role="log" aria-live="polite"><div class="ga-msg ga-msg--bot"><?php echo $welcome; ?></div></div>
  <form class="ga-chat__form"><textarea class="ga-chat__input" rows="2" maxlength="6000" placeholder="Ваш вопрос…"></textarea><button type="submit" class="ga-chat__send">Отправить</button></form>
</div>
<script>
(function(){
  var key=<?php echo wp_json_encode($t['public_key']); ?>, rest=<?php echo wp_json_encode($rest); ?>;
  var log=document.querySelector('.ga-chat__log'), form=document.querySelector('.ga-chat__form'),
      input=document.querySelector('.ga-chat__input'), send=document.querySelector('.ga-chat__send'), busy=false;
  function push(role,text){var n=document.createElement('div');n.className='ga-msg ga-msg--'+role;
    String(text).split('\n').forEach(function(l,i){if(i)n.appendChild(document.createElement('br'));n.appendChild(document.createTextNode(l));});
    log.appendChild(n);log.scrollTop=log.scrollHeight;return n;}
  form.addEventListener('submit',function(e){e.preventDefault();var t=input.value.trim();if(!t||busy)return;
    push('user',t);input.value='';busy=true;send.disabled=true;var p=push('bot','…');p.classList.add('is-typing');
    fetch(rest,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({key:key,text:t})})
      .then(function(r){return r.json();}).then(function(d){p.remove();
        if(d&&d.ok){push('bot',d.reply);}else{var n=push('bot',(d&&d.error)||'Не получилось ответить. Попробуйте ещё раз.');n.classList.add('ga-msg--notice');}})
      .catch(function(){p.remove();var n=push('bot','Сеть не отвечает. Повторите.');n.classList.add('ga-msg--notice');})
      .finally(function(){busy=false;send.disabled=false;});});
  input.addEventListener('keydown',function(e){if((e.metaKey||e.ctrlKey)&&e.key==='Enter')form.requestSubmit();});
})();
</script>
</body></html><?php
        exit;
    }

    public static function open_page($atts = []): string
    {
        self::kb_assets();
        $to = self::safe_path((string) ($_GET['to'] ?? ''));
        ob_start();
        echo '<div class="ga-kb ga-bind">';
        echo '<h3 class="ga-kb__title">Переход в сервис</h3>';
        if ($to === '') {
            echo '<p class="ga-kb__lead">Ссылка неполная. Вернитесь в бота и нажмите кнопку ещё раз.</p>';
        } elseif (is_user_logged_in()) {
            // Сюда попадаем, только если серверный редирект не сработал — форвардим сами.
            echo '<p class="ga-kb__lead">Открываем сервис…</p>'
                . '<p><a class="ga-support__main" href="' . esc_url(home_url($to)) . '">Открыть сервис</a></p>'
                . '<script>location.replace(' . wp_json_encode(home_url($to)) . ');</script>';
        } else {
            echo '<p class="ga-kb__lead">Войдите, чтобы продолжить — после входа сразу откроется нужный сервис.</p>'
                . '<p><button type="button" class="ga-support__main kie-auth-open-trigger">Войти</button> '
                . '<a class="ga-support__ask" href="' . esc_url(home_url('/tts-login/')) . '">Страница входа</a></p>';
        }
        echo '</div>';
        return '<div class="ga-kbwrap">' . (string) ob_get_clean() . '</div>';
    }

    public static function bind_telegram($atts = []): string
    {
        self::kb_assets();
        $tg = isset($_GET['tg']) ? (int) $_GET['tg'] : 0;
        $exp = isset($_GET['exp']) ? (int) $_GET['exp'] : 0;
        $sig = isset($_GET['sig']) ? preg_replace('/[^a-f0-9]/', '', (string) $_GET['sig']) : '';
        $to = self::safe_path((string) ($_GET['to'] ?? ''));
        $uid = get_current_user_id();

        ob_start();
        echo '<div class="ga-kb ga-bind">';
        echo '<h3 class="ga-kb__title">Привязка Telegram</h3>';
        if (!$tg || !$exp || !$sig) {
            echo '<p class="ga-kb__lead">Ссылка неполная. Вернитесь в бота и нажмите «Привязать аккаунт».</p>';
        } elseif (!$uid) {
            echo '<p class="ga-kb__lead">Войдите на сайте — почтой, через VK или Telegram, — и аккаунт '
                . 'привяжется. Баланс станет общим со всеми сервисами Genius.</p>'
                . '<p><button type="button" class="ga-support__main kie-auth-open-trigger">Войти</button> '
                . '<a class="ga-support__ask" href="' . esc_url(home_url('/tts-login/')) . '">Страница входа</a></p>'
                . '<p class="ga-kb__lead" style="margin-top:12px">После входа снова нажмите «Привязать аккаунт» '
                . 'в боте.</p>';
        } else {
            $res = GA_Billing::bind_apply($uid, $tg, $exp, $sig);
            if (!empty($res['ok'])) {
                echo '<p class="ga-kb__lead">✅ Аккаунт Telegram привязан. Баланс теперь общий, доступно пополнение.</p>';
                if ($to !== '') {
                    echo '<p><a class="ga-support__main" href="' . esc_url(home_url($to)) . '">Открыть сервис</a></p>'
                        . '<script>setTimeout(function(){location.replace('
                        . wp_json_encode(home_url($to)) . ');},1500);</script>';
                } else {
                    echo '<p class="ga-kb__lead">Вернитесь в бота и наберите <b>/balance</b>.</p>';
                }
            } elseif (($res['code'] ?? '') === 'taken') {
                echo '<p class="ga-kb__lead">Этот Telegram уже привязан к другому аккаунту. '
                    . 'Войдите под ним или напишите в поддержку.</p>';
            } elseif (($res['code'] ?? '') === 'expired') {
                echo '<p class="ga-kb__lead">Ссылка устарела. Вернитесь в бота и нажмите «Привязать аккаунт» ещё раз.</p>';
            } else {
                echo '<p class="ga-kb__lead">Ссылка недействительна. Повторите привязку из бота.</p>';
            }
        }
        echo '</div>';
        return '<div class="ga-kbwrap">' . (string) ob_get_clean() . '</div>';
    }

    public static function knowledge_base($atts = []): string
    {
        self::kb_assets();
        $uid = get_current_user_id();
        ob_start();
        if (!$uid): ?>
          <div class="ga-kb ga-kb--guest">
            <h3 class="ga-kb__title">Личный кабинет</h3>
            <p class="ga-kb__lead">Войдите, чтобы вести свою базу знаний и обучить консультанта
               отвечать по вашим услугам и ценам.
               <button type="button" class="ga-link kie-auth-open-trigger">Войти</button></p>
          </div>
        <?php else:
          $can = GA_Billing::can_manage_kb($uid);
          $kb = GA_KB::get($uid);
          $balance = GA_Billing::balance($uid); ?>
          <div class="ga-kb" data-can="<?php echo $can ? '1' : '0'; ?>">
            <div class="ga-kb__head">
              <h3 class="ga-kb__title">Моя база знаний</h3>
              <span class="ga-kb__bal">Баланс: <?php echo esc_html(self::money($balance)); ?>&nbsp;₽</span>
            </div>

            <?php if (!$can): ?>
              <div class="ga-kb__lock">
                <p>Загрузка базы знаний доступна после пополнения баланса. Пополните счёт —
                   и обучите консультанта отвечать по вашим услугам, ценам, срокам и условиям.</p>
                <a class="ga-kb__topup" href="<?php echo esc_url(home_url('/ai-pomoshnik/dlya-biznesa/')); ?>">
                  Пополнить баланс</a>
              </div>
            <?php else: ?>
              <p class="ga-kb__lead">Заполните базу — консультант будет отвечать строго по ней:
                 услуги, цены, сроки, условия, частые вопросы. Вставьте текст или загрузите документ.</p>
              <form class="ga-kbform">
                <input type="text" class="ga-kbform__company" maxlength="120"
                       placeholder="Название компании (необязательно)"
                       value="<?php echo esc_attr($kb['company']); ?>">
                <textarea class="ga-kbform__content" rows="12"
                          placeholder="Например:&#10;Услуги и цены: …&#10;Сроки: …&#10;Условия и гарантии: …&#10;Частые вопросы: …"><?php
                  echo esc_textarea($kb['content']); ?></textarea>
                <div class="ga-kbform__row">
                  <label class="ga-kbform__file" title="Загрузить txt или docx — текст добавится в базу">
                    <input type="file" accept=".txt,.csv,.md,.docx" hidden>
                    <span aria-hidden="true">📎</span> Загрузить документ
                  </label>
                  <span class="ga-kbform__count"></span>
                  <button type="submit" class="ga-kbform__save">Сохранить базу</button>
                </div>
                <p class="ga-kbform__status" hidden role="status"></p>
              </form>
            <?php endif; ?>

            <?php if ($can):
              $t = GA_Tenant::ensure($uid); ?>
              <div class="ga-box" data-public-key="<?php echo esc_attr($t['public_key']); ?>">
                <h4 class="ga-box__title">🚀 Ваш бот-консультант (коробка)</h4>
                <p class="ga-box__lead">Свой Telegram-бот и виджет на сайте под вашим брендом.
                   Отвечает вашим клиентам по вашей базе, списывается с вашего баланса.</p>

                <div class="ga-boxform">
                  <input type="text" class="ga-box__name" maxlength="160"
                         placeholder="Название компании / консультанта"
                         value="<?php echo esc_attr($t['name']); ?>">
                  <textarea class="ga-box__welcome" rows="2" maxlength="1000"
                            placeholder="Приветствие бота (что клиент видит на /start)"><?php
                    echo esc_textarea($t['welcome']); ?></textarea>
                  <textarea class="ga-box__persona" rows="3" maxlength="4000"
                            placeholder="Стиль и правила: тон общения, что можно и чего нельзя обещать"><?php
                    echo esc_textarea($t['persona']); ?></textarea>
                  <div class="ga-boxform__row">
                    <label class="ga-box__inline">Цвет
                      <input type="color" class="ga-box__accent" value="<?php echo esc_attr($t['accent']); ?>"></label>
                    <label class="ga-box__inline" title="Сколько сообщений клиенту не списывать с вашего баланса. 0 — каждое сообщение с вашего баланса.">Бесплатно клиенту/сутки
                      <input type="number" class="ga-box__free" min="0" style="width:80px"
                             value="<?php echo (int) $t['free_daily']; ?>"></label>
                    <button type="button" class="ga-box__save">Сохранить брендинг</button>
                  </div>
                  <p class="ga-box__status" hidden role="status"></p>
                </div>

                <div class="ga-box__bot">
                  <div class="ga-box__state"><?php
                    if ($t['status'] === 'connected' && $t['bot_username']) {
                        echo 'Бот подключён: <a href="https://t.me/' . esc_attr($t['bot_username'])
                            . '" target="_blank" rel="noopener">@' . esc_html($t['bot_username']) . '</a>';
                    } elseif ($t['status'] === 'error') {
                        echo 'Ошибка подключения: ' . esc_html($t['last_error']);
                    } else {
                        echo 'Бот не подключён.';
                    } ?></div>
                  <input type="text" class="ga-box__token" autocomplete="off" spellcheck="false"
                         placeholder="Токен бота от @BotFather (123456789:AA…)">
                  <div class="ga-boxform__row">
                    <button type="button" class="ga-box__connect">Подключить бота</button>
                    <button type="button" class="ga-box__disconnect"<?php
                      echo $t['bot_token'] === '' ? ' hidden' : ''; ?>>Отключить</button>
                  </div>
                  <p class="ga-box__botstatus" hidden role="status"></p>
                  <p class="ga-box__hint">Создайте бота у
                     <a href="https://t.me/BotFather" target="_blank" rel="noopener">@BotFather</a>,
                     скопируйте токен и вставьте сюда — вебхук настроится сам.</p>
                </div>

                <div class="ga-box__embed"<?php echo $t['bot_token'] !== '' ? '' : ' hidden'; ?>>
                  <p class="ga-box__lead">Виджет на ваш сайт — вставьте код в HTML страницы:</p>
                  <textarea class="ga-box__embedcode" readonly rows="2"><?php
                    echo esc_textarea('<iframe src="' . home_url('/consultant/?t=' . $t['public_key'])
                      . '" style="width:100%;max-width:440px;height:640px;border:0;border-radius:16px" '
                      . 'title="Консультант"></iframe>'); ?></textarea>
                  <a class="ga-box__widget" href="<?php echo esc_url(home_url('/consultant/?t=' . $t['public_key'])); ?>"
                     target="_blank" rel="noopener">Открыть виджет ↗</a>
                </div>
              </div>
            <?php else: ?>
              <div class="ga-kb__cta">
                <div class="ga-kb__ctaText">
                  <strong>🚀 Своя коробка: бот + база + брендинг</strong>
                  <span>Пополните баланс — и подключите свой Telegram-бот и виджет под вашим брендом,
                     отвечающий вашим клиентам по вашей базе.</span>
                </div>
              </div>
            <?php endif; ?>
          </div>
        <?php endif;
        return '<div class="ga-kbwrap">' . (string) ob_get_clean() . '</div>';
    }

    private static function bot_username(int $assistant_id): string
    {
        foreach (GA_Store::tokens($assistant_id) as $token) {
            if ($token['is_active'] && $token['bot_username']) {
                return (string) $token['bot_username'];
            }
        }
        return '';
    }
}
