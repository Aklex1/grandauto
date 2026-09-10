<?php
/**
 * Страницы плагина: студия генерации звуков (вторая версия tts-dashboard под SFX)
 * и витрина примеров сгенерированных звуков.
 */

if (!defined('ABSPATH')) {
    exit;
}

class GS_Pages {

    const OPT_STUDIO_PAGE   = 'gs_studio_page_id';
    const OPT_SHOWCASE_PAGE = 'gs_showcase_page_id';

    const STUDIO_SLUG   = 'sound-generator';
    const SHOWCASE_SLUG = 'ai-zvuki';

    public static function boot() {
        add_filter('body_class', array(__CLASS__, 'body_class'));
    }

    public static function register_shortcodes() {
        add_shortcode('genius_sfx_studio', array(__CLASS__, 'render_studio'));
        add_shortcode('genius_sfx_showcase', array(__CLASS__, 'render_showcase'));
    }

    public static function ensure_pages() {
        self::ensure_page(self::OPT_STUDIO_PAGE, 'Генератор звуков и спецэффектов', '[genius_sfx_studio]', self::STUDIO_SLUG);
        self::ensure_page(self::OPT_SHOWCASE_PAGE, 'Звуки, созданные нейросетью', '[genius_sfx_showcase]', self::SHOWCASE_SLUG);
    }

    private static function ensure_page($option_key, $title, $shortcode, $slug) {
        $page_id = (int) get_option($option_key);
        if ($page_id > 0 && get_post($page_id)) {
            return $page_id;
        }

        $existing = get_page_by_path($slug);
        if ($existing) {
            $page_id = (int) $existing->ID;
            wp_update_post(array(
                'ID'           => $page_id,
                'post_title'   => $title,
                'post_content' => $shortcode,
                'post_status'  => 'publish',
            ));
        } else {
            $page_id = (int) wp_insert_post(array(
                'post_title'   => $title,
                'post_content' => $shortcode,
                'post_status'  => 'publish',
                'post_type'    => 'page',
                'post_name'    => $slug,
            ));
        }

        if ($page_id > 0) {
            update_option($option_key, $page_id);
        }
        return $page_id;
    }

    /* ---------------------------------------------------------------------
     * URL
     * ------------------------------------------------------------------ */

    public static function get_studio_url($prompt = '') {
        $pid = (int) get_option(self::OPT_STUDIO_PAGE);
        $url = $pid > 0 ? get_permalink($pid) : '';
        if (!$url) {
            $url = home_url('/' . self::STUDIO_SLUG . '/');
        }
        $prompt = trim((string) $prompt);
        if ($prompt !== '') {
            $url = add_query_arg('prompt', rawurlencode($prompt), $url);
        }
        return $url;
    }

    public static function get_showcase_url() {
        $pid = (int) get_option(self::OPT_SHOWCASE_PAGE);
        $url = $pid > 0 ? get_permalink($pid) : '';
        return $url ? $url : home_url('/' . self::SHOWCASE_SLUG . '/');
    }

    public static function get_login_url() {
        $pid = (int) get_option('kie_tts_auth_page_id');
        $url = $pid > 0 ? get_permalink($pid) : '';
        return $url ? $url : wp_login_url(self::get_studio_url());
    }

    public static function get_dashboard_url() {
        $pid = (int) get_option('kie_tts_dashboard_page_id');
        $url = $pid > 0 ? get_permalink($pid) : '';
        return $url ? $url : home_url('/tts-dashboard/');
    }

    public static function is_studio_request() {
        $pid = (int) get_option(self::OPT_STUDIO_PAGE);
        if ($pid > 0 && is_page($pid)) {
            return true;
        }
        global $post;
        return ($post instanceof WP_Post) && has_shortcode((string) $post->post_content, 'genius_sfx_studio');
    }

    public static function is_showcase_request() {
        $pid = (int) get_option(self::OPT_SHOWCASE_PAGE);
        if ($pid > 0 && is_page($pid)) {
            return true;
        }
        global $post;
        return ($post instanceof WP_Post) && has_shortcode((string) $post->post_content, 'genius_sfx_showcase');
    }

    public static function body_class($classes) {
        if (self::is_studio_request()) {
            $classes[] = 'gs-studio-page';
        }
        if (GS_Catalog::is_catalog_request()) {
            $classes[] = 'gs-catalog-page';
        }
        return $classes;
    }

    /* ---------------------------------------------------------------------
     * Студия
     * ------------------------------------------------------------------ */

    public static function render_studio($atts = array()) {
        $prefill = isset($_GET['prompt']) ? sanitize_text_field(wp_unslash((string) $_GET['prompt'])) : '';
        $logged  = is_user_logged_in();
        $cost    = GS_SFX::get_cost();
        $balance = $logged ? GS_SFX::get_balance(get_current_user_id()) : 0.0;

        ob_start();
        ?>
        <div class="gs-wrap gs-studio">
            <section class="gs-hero gs-hero--studio">
                <span class="gs-hero__badge">Suno V5 · KIE</span>
                <h1 class="gs-hero__title">Генератор звуков и спецэффектов</h1>
                <p class="gs-hero__lead">Опишите нужный звук словами — нейросеть соберёт готовый эффект в MP3. Взрывы, шаги, интерфейсные сигналы, атмосфера и бесшовные лупы. Без авторских прав.</p>
                <div class="gs-hero__meta">
                    <span class="gs-chip gs-chip--ok"><?php echo esc_html(number_format_i18n($cost, 0)); ?> ₽ за звук</span>
                    <span class="gs-chip">MP3</span>
                    <span class="gs-chip">коммерческое использование</span>
                    <a class="gs-chip gs-chip--link" href="<?php echo esc_url(GS_Catalog::base_url()); ?>">Каталог готовых звуков</a>
                </div>
            </section>

            <div class="gs-studio__layout">
                <form class="gs-panel gs-form" id="gs-sfx-form" novalidate>
                    <div class="gs-field">
                        <label class="gs-label" for="gs-prompt">Опишите звук</label>
                        <textarea id="gs-prompt" class="gs-textarea" name="prompt" rows="3" maxlength="400"
                                  placeholder="Например: тяжёлая железная дверь закрывается с гулким эхом"><?php echo esc_textarea($prefill); ?></textarea>
                        <div class="gs-field__foot">
                            <span class="gs-hint">Чем конкретнее описание — тем точнее результат.</span>
                            <span class="gs-counter"><span id="gs-prompt-count">0</span>/400</span>
                        </div>
                    </div>

                    <div class="gs-field">
                        <span class="gs-label">Быстрый старт</span>
                        <div class="gs-presets" id="gs-presets">
                            <?php foreach (GS_SFX::get_presets() as $preset): ?>
                                <button type="button" class="gs-preset"
                                        data-prompt="<?php echo esc_attr($preset['prompt']); ?>"
                                        data-mode="<?php echo esc_attr($preset['mode']); ?>"><?php echo esc_html($preset['label']); ?></button>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="gs-field">
                        <span class="gs-label">Тип звука</span>
                        <div class="gs-modes" id="gs-modes">
                            <?php $first = true; foreach (GS_SFX::get_modes() as $mode_key => $mode_label): ?>
                                <label class="gs-mode<?php echo $first ? ' is-active' : ''; ?>">
                                    <input type="radio" name="mode" value="<?php echo esc_attr($mode_key); ?>" <?php checked($first); ?>>
                                    <span><?php echo esc_html($mode_label); ?></span>
                                </label>
                            <?php $first = false; endforeach; ?>
                        </div>
                    </div>

                    <div class="gs-grid-2">
                        <div class="gs-field">
                            <label class="gs-label" for="gs-model">Модель</label>
                            <select id="gs-model" class="gs-select" name="model">
                                <?php foreach (GS_SFX::get_models() as $value => $model): ?>
                                    <option value="<?php echo esc_attr($value); ?>" data-hint="<?php echo esc_attr($model['hint']); ?>"><?php echo esc_html($model['label']); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <span class="gs-hint" id="gs-model-hint"></span>
                        </div>

                        <div class="gs-field">
                            <label class="gs-label" for="gs-seconds">Длительность: <output id="gs-seconds-out">4</output> с</label>
                            <input id="gs-seconds" class="gs-range" type="range" name="seconds" min="1" max="30" step="1" value="4">
                            <span class="gs-hint">Ориентир для модели, а не жёсткая длина.</span>
                        </div>
                    </div>

                    <details class="gs-advanced">
                        <summary>Дополнительно: темп, тональность, зацикливание</summary>
                        <div class="gs-grid-2">
                            <div class="gs-field">
                                <label class="gs-label" for="gs-tempo">Темп, BPM</label>
                                <input id="gs-tempo" class="gs-input" type="number" name="tempo" min="1" max="300" placeholder="не задан">
                            </div>
                            <div class="gs-field">
                                <label class="gs-label" for="gs-key">Тональность</label>
                                <select id="gs-key" class="gs-select" name="key">
                                    <?php foreach (GS_SFX::get_keys() as $key): ?>
                                        <option value="<?php echo esc_attr($key); ?>"><?php echo $key === '' ? 'Любая' : esc_html($key); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <label class="gs-checkbox">
                            <input type="checkbox" id="gs-loop" name="loop">
                            <span>Сделать бесшовный луп</span>
                        </label>
                    </details>

                    <div class="gs-form__foot">
                        <div class="gs-balance">
                            <?php if ($logged): ?>
                                <span class="gs-balance__label">Баланс</span>
                                <span class="gs-balance__value" id="gs-balance"><?php echo esc_html(number_format_i18n($balance, 2)); ?> ₽</span>
                                <a class="gs-balance__topup" href="<?php echo esc_url(self::get_dashboard_url()); ?>">Пополнить</a>
                            <?php else: ?>
                                <span class="gs-balance__label">Нужен вход</span>
                            <?php endif; ?>
                        </div>

                        <?php if ($logged): ?>
                            <button class="gs-btn gs-btn--primary gs-btn--lg" type="submit" id="gs-submit">
                                Создать звук за <?php echo esc_html(number_format_i18n($cost, 0)); ?> ₽
                            </button>
                        <?php else: ?>
                            <a class="gs-btn gs-btn--primary gs-btn--lg" href="<?php echo esc_url(self::get_login_url()); ?>">Войти и создать звук</a>
                        <?php endif; ?>
                    </div>

                    <p class="gs-form__note" id="gs-form-note" role="status" aria-live="polite"></p>
                </form>

                <aside class="gs-panel gs-result" id="gs-result">
                    <div class="gs-result__empty" id="gs-result-empty">
                        <div class="gs-result__icon" aria-hidden="true">🎛️</div>
                        <h2 class="gs-result__title">Здесь появится ваш звук</h2>
                        <p class="gs-result__text">Опишите эффект слева и нажмите «Создать звук». Генерация занимает от 20 до 60 секунд.</p>
                    </div>

                    <div class="gs-result__loading" id="gs-result-loading" hidden>
                        <div class="gs-spinner" aria-hidden="true"></div>
                        <p class="gs-result__text" id="gs-result-stage">Отправляем задачу в Suno…</p>
                        <div class="gs-progress"><div class="gs-progress__bar" id="gs-progress-bar"></div></div>
                    </div>

                    <div class="gs-result__ready" id="gs-result-ready" hidden>
                        <h2 class="gs-result__title" id="gs-result-title">Звук готов</h2>
                        <p class="gs-result__prompt" id="gs-result-prompt"></p>
                        <audio id="gs-result-audio" class="gs-audio" controls preload="auto"></audio>
                        <div class="gs-result__actions">
                            <a class="gs-btn gs-btn--primary" id="gs-result-download" href="#" download>Скачать MP3</a>
                            <button class="gs-btn gs-btn--ghost" type="button" id="gs-result-again">Сгенерировать ещё</button>
                        </div>
                    </div>

                    <div class="gs-history" id="gs-history" hidden>
                        <h3 class="gs-history__title">Ваши последние звуки</h3>
                        <ul class="gs-history__list" id="gs-history-list"></ul>
                    </div>
                </aside>
            </div>

            <section class="gs-tips">
                <h2 class="gs-section-title">Как описывать звук, чтобы получилось с первого раза</h2>
                <div class="gs-tips__grid">
                    <article class="gs-tip">
                        <h3>Называйте источник</h3>
                        <p>«Металлическая дверь», «гравий», «стеклянный стакан» — модель опирается на материал и предмет.</p>
                    </article>
                    <article class="gs-tip">
                        <h3>Добавьте характер</h3>
                        <p>«Резкий», «глухой», «с эхом», «глубокий бас» — так эффект получает нужный тембр и атаку.</p>
                    </article>
                    <article class="gs-tip">
                        <h3>Задайте сцену</h3>
                        <p>«В пустом ангаре», «на улице под дождём» — пространство сильно меняет звучание.</p>
                    </article>
                    <article class="gs-tip">
                        <h3>Не просите музыку</h3>
                        <p>Для чистых эффектов выбирайте тип «Отдельный эффект» — мы сами добавим «no music, no voices».</p>
                    </article>
                </div>
            </section>
        </div>
        <?php
        return ob_get_clean();
    }

    /* ---------------------------------------------------------------------
     * Витрина примеров
     * ------------------------------------------------------------------ */

    public static function render_showcase($atts = array()) {
        $items = GS_SFX::get_showcase();

        ob_start();
        ?>
        <div class="gs-wrap gs-catalog">
            <section class="gs-hero">
                <span class="gs-hero__badge">Suno V5 · KIE</span>
                <h1 class="gs-hero__title">Звуки, созданные нейросетью</h1>
                <p class="gs-hero__lead">Примеры звуков и спецэффектов, сгенерированных в студии Genius-bot по текстовому описанию. Слушайте, скачивайте и создавайте свои.</p>
                <div class="gs-hero__meta">
                    <a class="gs-btn gs-btn--primary" href="<?php echo esc_url(self::get_studio_url()); ?>">Создать свой звук</a>
                    <a class="gs-btn gs-btn--ghost" href="<?php echo esc_url(GS_Catalog::base_url()); ?>">Каталог звуков</a>
                </div>
            </section>

            <?php if (empty($items)): ?>
                <div class="gs-empty">
                    <div class="gs-empty__icon" aria-hidden="true">🎧</div>
                    <p class="gs-empty__text">Примеров пока нет. Сгенерируйте первый звук в студии.</p>
                    <a class="gs-btn gs-btn--primary" href="<?php echo esc_url(self::get_studio_url()); ?>">Открыть студию</a>
                </div>
            <?php else: ?>
                <div class="gs-sounds" data-gs-player>
                    <?php foreach ($items as $item): ?>
                        <?php
                        $title = (string) ($item['title'] ?? 'Сгенерированный звук');
                        $url   = (string) ($item['url'] ?? '');
                        if ($url === '') {
                            continue;
                        }
                        ?>
                        <article class="gs-sound" data-gs-sound data-src="<?php echo esc_url($url); ?>" data-title="<?php echo esc_attr($title); ?>">
                            <button class="gs-sound__play" type="button" data-gs-play aria-label="Прослушать: <?php echo esc_attr($title); ?>">
                                <span class="gs-sound__icon gs-sound__icon--play" aria-hidden="true"></span>
                                <span class="gs-sound__icon gs-sound__icon--pause" aria-hidden="true"></span>
                            </button>
                            <div class="gs-sound__body">
                                <h3 class="gs-sound__title"><?php echo esc_html($title); ?></h3>
                                <div class="gs-sound__meta">
                                    <?php if (!empty($item['prompt'])): ?>
                                        <span class="gs-sound__prompt">«<?php echo esc_html($item['prompt']); ?>»</span>
                                    <?php endif; ?>
                                    <span class="gs-sound__format"><?php echo esc_html((string) ($item['model'] ?? 'V5')); ?></span>
                                </div>
                                <div class="gs-sound__progress" data-gs-progress>
                                    <div class="gs-sound__bar" data-gs-bar></div>
                                </div>
                            </div>
                            <a class="gs-sound__download" href="<?php echo esc_url($url); ?>" download aria-label="Скачать: <?php echo esc_attr($title); ?>">
                                <span aria-hidden="true">↓</span><span class="gs-sound__download-text">Скачать</span>
                            </a>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php echo GS_Catalog::render_cta('', 'index'); // phpcs:ignore WordPress.Security.EscapeOutput ?>
        </div>
        <?php
        return ob_get_clean();
    }
}
