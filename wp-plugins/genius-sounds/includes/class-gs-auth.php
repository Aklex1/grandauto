<?php
/**
 * Вход прямо на странице сервиса.
 *
 * Раньше «Войти и продолжить» уводило на отдельную страницу входа, а
 * человек к этому моменту уже заполнил форму: выбрал файл, написал
 * описание, настроил параметры. После входа он возвращался на чистую
 * страницу и всё это терял — а часто и не возвращался вовсе.
 *
 * Поэтому вход по почте происходит здесь же, окном, и страница просто
 * перезагружается на том же месте. Вход через Телеграм и ВК устроен
 * иначе: он требует ухода на сторону этих сервисов и обратно, поэтому
 * там переход остаётся, но с возвратом на ту же страницу.
 *
 * Маршруты — платёжного плагина, его же, что и у штатной страницы входа.
 * Своего кода авторизации мы не заводим: пароль, письма и сессии
 * остаются там, где были.
 */

if (!defined('ABSPATH')) {
    exit;
}

class GS_Auth {

    public static function boot() {
        add_action('wp_footer', array(__CLASS__, 'print_modal'));
    }

    /** Нужно ли окно входа на этой странице. */
    public static function needed() {
        if (is_user_logged_in() || is_admin()) {
            return false;
        }
        if (class_exists('GS_Lab') && GS_Lab::current_service()) {
            return true;
        }
        if (class_exists('GS_Landing') && GS_Landing::current()) {
            return true;
        }
        if (class_exists('GS_Slides_Page') && GS_Slides_Page::is_page()) {
            return true;
        }
        if (class_exists('GS_Pages') && GS_Pages::is_studio_request()) {
            return true;
        }
        return false;
    }

    /** Адрес страницы входа — для способов, которым нужен переход. */
    public static function login_url() {
        $page = (int) get_option('kie_tts_auth_page_id');
        $url = $page > 0 ? get_permalink($page) : home_url('/tts-login/');
        return add_query_arg('gs_return', rawurlencode(self::current_url()), $url);
    }

    private static function current_url() {
        $host = isset($_SERVER['HTTP_HOST']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_HOST'])) : '';
        $path = isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])) : '/';
        if ($host === '') {
            return home_url('/');
        }
        return (is_ssl() ? 'https://' : 'http://') . $host . $path;
    }

    public static function print_modal() {
        if (!self::needed()) {
            return;
        }
        $bot = (string) get_option('kie_tts_telegram_bot_username', 'Neuro_HubAI_bot');
        $login = self::login_url();
        ?>
        <div class="gs-auth" id="gs-auth" hidden>
            <div class="gs-auth__veil" data-gs-auth-close></div>
            <div class="gs-auth__box" role="dialog" aria-modal="true" aria-labelledby="gs-auth-title">
                <button type="button" class="gs-auth__x" data-gs-auth-close aria-label="Закрыть">&times;</button>

                <h2 class="gs-auth__title" id="gs-auth-title">Вход</h2>
                <p class="gs-auth__lead">Войдите, чтобы продолжить. Форма на странице сохранится — после входа вы останетесь здесь же.</p>

                <div id="gs-auth-step1">
                    <label class="gs-auth__label" for="gs-auth-email">Почта</label>
                    <input class="gs-auth__input" type="email" id="gs-auth-email"
                           placeholder="you@example.com" autocomplete="email" inputmode="email">
                    <button type="button" class="gs-auth__go" id="gs-auth-send">Получить код</button>
                </div>

                <div id="gs-auth-step2" hidden>
                    <label class="gs-auth__label" for="gs-auth-code">Код из письма</label>
                    <input class="gs-auth__input gs-auth__code" type="text" id="gs-auth-code"
                           placeholder="000000" inputmode="numeric" maxlength="6" autocomplete="one-time-code">
                    <button type="button" class="gs-auth__go" id="gs-auth-verify">Войти</button>
                    <button type="button" class="gs-auth__back" id="gs-auth-back">Другая почта</button>
                </div>

                <p class="gs-auth__note" id="gs-auth-note" role="status" aria-live="polite"></p>

                <div class="gs-auth__or"><span>или</span></div>
                <div class="gs-auth__social">
                    <a class="is-tg" href="https://t.me/<?php echo esc_attr(ltrim($bot, '@')); ?>?start=auth" target="_blank" rel="noopener">
                        Войти через Телеграм
                    </a>
                    <a class="is-vk" href="<?php echo esc_url(add_query_arg('vk_auth', '1', $login)); ?>">
                        Войти через ВКонтакте
                    </a>
                </div>
            </div>
        </div>
        <?php
    }
}
