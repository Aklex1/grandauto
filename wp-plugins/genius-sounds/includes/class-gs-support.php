<?php
/**
 * Связь с поддержкой прямо со страницы сервиса.
 *
 * У человека, у которого не вышло, есть ровно два пути: уйти или спросить.
 * Второй путь должен быть на той же странице, где случилась неудача, —
 * искать контакты в подвале никто не станет.
 *
 * Заявка уходит тем же путём, что и заявки с лендинга: сообщением в бот
 * владельцу, с копией в журнале на случай, если Telegram промолчит.
 * Отдельной формы и отдельного маршрута заводить не пришлось.
 */

if (!defined('ABSPATH')) {
    exit;
}

class GS_Support {

    /** Прямая ссылка на бота — для тех, кому форма не нужна. */
    const BOT = 'https://t.me/Genius_Boot_bot';

    private static $printed = false;

    /**
     * Блок с двумя кнопками и формой.
     *
     * @param string $context откуда пришли — попадёт в сообщение владельцу.
     * @param string $title   заголовок блока.
     */
    public static function render($context = '', $title = 'Не получилось или остался вопрос?') {
        $context = trim((string) $context);
        ob_start();
        ?>
        <section class="gs-help" id="gs-help">
            <div class="gs-help__head">
                <h2 class="gs-section-title"><?php echo esc_html($title); ?></h2>
                <p class="gs-help__lead">
                    Напишите — разберёмся. Если операция списала деньги и не дала результат,
                    средства возвращаются на баланс автоматически, но о самой поломке мы
                    узнаём только от вас.
                </p>
            </div>

            <div class="gs-help__actions">
                <button type="button" class="gs-btn gs-btn--primary" data-gs-help="support">
                    Написать в поддержку
                </button>
                <button type="button" class="gs-btn gs-btn--ghost" data-gs-help="question">
                    Задать вопрос
                </button>
                <a class="gs-help__direct" href="<?php echo esc_url(self::BOT); ?>" target="_blank" rel="noopener">
                    или сразу в Telegram
                </a>
            </div>

            <form class="gs-help__form" id="gs-help-form" hidden
                  data-context="<?php echo esc_attr($context); ?>">
                <p class="gs-help__kind" id="gs-help-kind"></p>
                <label class="gs-label" for="gs-help-contact">Как с вами связаться</label>
                <input type="text" id="gs-help-contact" class="gs-input" maxlength="120"
                       placeholder="Ник в Telegram, почта или телефон" autocomplete="off">
                <label class="gs-label" for="gs-help-text">Что случилось</label>
                <textarea id="gs-help-text" class="gs-textarea" rows="4" maxlength="600"
                          placeholder="Опишите своими словами: что делали, что ожидали, что получилось"></textarea>
                <div class="gs-help__submit">
                    <button type="submit" class="gs-btn gs-btn--primary" id="gs-help-send">Отправить</button>
                    <span class="gs-form__note" id="gs-help-note"></span>
                </div>
            </form>
        </section>
        <?php
        return ob_get_clean();
    }

    /**
     * Настройки для скрипта. Печатаем один раз на страницу: блок может
     * стоять и в кабинете, и в подвале одной и той же страницы.
     */
    public static function config_script() {
        if (self::$printed) {
            return '';
        }
        self::$printed = true;
        $cfg = array(
            'restUrl' => esc_url_raw(rest_url(GS_Rest::NS . '/')),
            'nonce'   => wp_create_nonce('wp_rest'),
            'bot'     => self::BOT,
        );
        return '<script>window.GS_HELP = ' . wp_json_encode($cfg) . ';</script>';
    }
}
