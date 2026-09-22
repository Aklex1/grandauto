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
            'nonce' => wp_create_nonce('wp_rest'),
            'loggedIn' => is_user_logged_in(),
            'loginUrl' => home_url('/tts-login/'),
        ]);
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

        ob_start(); ?>
        <div class="ga-chat<?php echo $atts['compact'] === '1' ? ' ga-chat--compact' : ''; ?>"
             data-slug="<?php echo esc_attr($assistant['slug']); ?>"
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

          <form class="ga-chat__form">
            <textarea class="ga-chat__input" rows="2"
                      maxlength="<?php echo (int) $assistant['max_input_chars']; ?>"
                      placeholder="Опишите задачу…"></textarea>
            <button type="submit" class="ga-chat__send">Спросить</button>
          </form>

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
        </div>
        <?php
        return (string) ob_get_clean();
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
