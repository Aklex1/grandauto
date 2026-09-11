<?php
/**
 * Посадочные страницы микросервисов: общий шаблон, тексты берутся из реестра GS_Lab.
 */

if (!defined('ABSPATH')) {
    exit;
}

class GS_Lab_Page {

    public static function register_shortcodes() {
        add_shortcode('genius_lab', array(__CLASS__, 'render'));
    }

    public static function render($atts = array()) {
        $atts = shortcode_atts(array('id' => ''), (array) $atts, 'genius_lab');
        $service = GS_Lab::get_service(sanitize_key($atts['id']));
        if (!$service) {
            return '';
        }

        $logged  = is_user_logged_in();
        $cost    = GS_Lab::get_cost($service['id']);
        $balance = $logged ? GS_SFX::get_balance(get_current_user_id()) : 0.0;
        $login    = GS_Pages::get_login_url(GS_Lab::get_url($service['id']));

        ob_start();
        ?>
        <div class="gs-wrap gs-studio gs-lab" data-service="<?php echo esc_attr($service['id']); ?>">
            <nav class="gs-breadcrumbs" aria-label="Хлебные крошки">
                <a href="<?php echo esc_url(home_url('/')); ?>">Главная</a>
                <span aria-hidden="true">/</span>
                <span class="gs-breadcrumbs__current"><?php echo esc_html($service['menu']); ?></span>
            </nav>

            <section class="gs-hero gs-hero--studio">
                <span class="gs-hero__badge"><?php echo esc_html($service['badge']); ?></span>
                <h1 class="gs-hero__title"><?php echo esc_html($service['h1']); ?></h1>
                <p class="gs-hero__lead"><?php echo esc_html($service['lead']); ?></p>
                <div class="gs-hero__meta">
                    <span class="gs-chip gs-chip--ok" id="gs-lab-price"><?php echo esc_html(GS_Lab::price_hint($service['id'])); ?></span>
                    <span class="gs-chip">без установки программ</span>
                    <?php if (GS_Lab::is_manual($service['id'])): ?>
                        <span class="gs-chip">готово в течение 15 минут</span>
                    <?php else: ?>
                        <span class="gs-chip">результат сразу скачивается</span>
                    <?php endif; ?>
                </div>

                <?php if (GS_Lab::is_manual($service['id'])): ?>
                    <p class="gs-hero__note">
                        Сейчас записи обрабатываются в ручном режиме, поэтому результат приходит не мгновенно:
                        обычно в течение 15 минут. Готовые дорожки появятся в вашей истории и придут на почту —
                        страницу можно закрыть. Если обработать не получится, деньги вернутся на баланс.
                    </p>
                <?php endif; ?>
            </section>

            <?php if (!GS_Lab::is_available($service['id'])): ?>
                <section class="gs-empty gs-empty--page">
                    <div class="gs-empty__icon" aria-hidden="true">🛠️</div>
                    <h2 class="gs-empty__title">Инструмент подключается</h2>
                    <p class="gs-empty__text"><?php echo esc_html($service['blocked_note']); ?></p>
                    <a class="gs-btn gs-btn--primary" href="<?php echo esc_url(GS_Pages::get_studio_url()); ?>">Перейти к генератору звуков</a>
                </section>
            <?php else: ?>
            <div class="gs-studio__layout">
                <form class="gs-panel gs-form" id="gs-lab-form" novalidate>
                    <?php foreach ($service['inputs'] as $input): ?>
                        <div class="gs-field">
                            <label class="gs-label" for="gs-lab-<?php echo esc_attr($input); ?>">
                                <?php echo $input === 'image' ? 'Фотография' : 'Аудиофайл'; ?>
                            </label>
                            <input id="gs-lab-<?php echo esc_attr($input); ?>" class="gs-input gs-file" type="file"
                                   data-kind="<?php echo esc_attr($input); ?>"
                                   accept="<?php echo esc_attr($service['accept'][$input]); ?>">
                            <span class="gs-hint" data-file-hint="<?php echo esc_attr($input); ?>">
                                <?php
                                if ($input === 'image') {
                                    echo 'JPEG или PNG, до 10 МБ. Лицо анфас, крупно.';
                                } else {
                                    $limit = GS_Lab::max_seconds($service['id']);
                                    echo 'MP3, WAV, M4A или OGG. До ' . ($service['id'] === 'vocal' ? '20' : '10') . ' МБ';
                                    if ($limit > 0) {
                                        echo $limit < 120
                                            ? ' и ' . (int) $limit . ' секунд'
                                            : ' и ' . (int) round($limit / 60) . ' минут';
                                    }
                                    echo '.';
                                }
                                ?>
                            </span>
                        </div>
                    <?php endforeach; ?>

                    <?php if (!empty($service['prompt'])): ?>
                        <div class="gs-field">
                            <label class="gs-label" for="gs-lab-prompt">Описание сцены</label>
                            <textarea id="gs-lab-prompt" class="gs-textarea" rows="2" maxlength="500"
                                      placeholder="Например: спокойно рассказывает, смотрит в камеру"></textarea>
                            <span class="gs-hint"><?php echo esc_html($service['prompt_hint']); ?></span>
                        </div>
                    <?php endif; ?>

                    <div class="gs-form__foot">
                        <div class="gs-balance">
                            <?php if ($logged): ?>
                                <span class="gs-balance__label">Баланс</span>
                                <span class="gs-balance__value" id="gs-lab-balance"><?php echo esc_html(number_format_i18n($balance, 2)); ?> ₽</span>
                                <a class="gs-balance__topup" href="<?php echo esc_url(GS_Pages::get_dashboard_url()); ?>">Пополнить</a>
                            <?php else: ?>
                                <span class="gs-balance__label">Нужен вход</span>
                            <?php endif; ?>
                        </div>

                        <?php if ($logged): ?>
                            <button class="gs-btn gs-btn--primary gs-btn--lg" type="submit" id="gs-lab-submit">
                                <?php echo esc_html(self::submit_label($service)); ?>
                            </button>
                        <?php else: ?>
                            <a class="gs-btn gs-btn--primary gs-btn--lg" href="<?php echo esc_url($login); ?>">Войти и продолжить</a>
                        <?php endif; ?>
                    </div>

                    <p class="gs-form__note" id="gs-lab-note" role="status" aria-live="polite"></p>
                </form>

                <aside class="gs-panel gs-result" id="gs-lab-result">
                    <div class="gs-result__empty" id="gs-lab-empty">
                        <div class="gs-result__icon" aria-hidden="true"><?php echo $service['id'] === 'avatar' ? '🎬' : '🎚️'; ?></div>
                        <h2 class="gs-result__title">Здесь появится результат</h2>
                        <p class="gs-result__text">Загрузите файл слева и запустите обработку.</p>
                    </div>

                    <div class="gs-result__loading" id="gs-lab-loading" hidden>
                        <div class="gs-spinner" aria-hidden="true"></div>
                        <p class="gs-result__text" id="gs-lab-stage">Загружаем файл…</p>
                        <div class="gs-progress"><div class="gs-progress__bar" id="gs-lab-progress"></div></div>
                    </div>

                    <div class="gs-result__ready" id="gs-lab-ready" hidden>
                        <h2 class="gs-result__title">Готово</h2>
                        <div id="gs-lab-files"></div>
                        <div class="gs-result__actions">
                            <button class="gs-btn gs-btn--ghost" type="button" id="gs-lab-again">Обработать ещё файл</button>
                        </div>
                    </div>
                </aside>
            </div>
            <?php endif; ?>

            <section class="gs-tips">
                <h2 class="gs-section-title">Как это работает</h2>
                <ol class="gs-steps">
                    <?php foreach ($service['steps'] as $i => $step): ?>
                        <li class="gs-step">
                            <span class="gs-step__num"><?php echo (int) ($i + 1); ?></span>
                            <span class="gs-step__text"><?php echo esc_html($step); ?></span>
                        </li>
                    <?php endforeach; ?>
                </ol>
            </section>

            <?php echo self::render_cross_links($service['id']); // phpcs:ignore WordPress.Security.EscapeOutput ?>

            <section class="gs-faq">
                <h2 class="gs-section-title">Частые вопросы</h2>
                <?php foreach ($service['faq'] as $pair): ?>
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

    private static function submit_label($service) {
        switch ($service['id']) {
            case 'avatar':
                return 'Сделать видео';
            case 'vocal':
                return 'Разделить дорожки';
            case 'denoise':
                return 'Очистить запись';
        }
        return 'Запустить';
    }

    /**
     * Блок ссылок на остальные микросервисы с призывом.
     * Он же раздаёт вес между посадочными: каждая ссылается на все соседние.
     */
    public static function render_cross_links($current_id = '', $title = 'Другие инструменты со звуком') {
        $cards = array();

        foreach (GS_Lab::services() as $service) {
            if ($service['id'] === $current_id || !GS_Lab::is_available($service['id'])) {
                continue;
            }
            $cards[] = array(
                'url'   => GS_Lab::get_url($service['id']),
                'title' => $service['h1'],
                'short' => $service['menu'],
                'text'  => $service['lead'],
                'cta'   => self::cta_label($service['id']),
            );
        }

        $cards[] = array(
            'url'   => GS_Pages::get_studio_url(),
            'title' => 'Генератор звуков и спецэффектов',
            'short' => 'Создать звук',
            'text'  => 'Опишите звук словами — нейросеть соберёт готовый эффект в MP3: взрывы, шаги, интерфейсные сигналы, атмосфера и бесшовные лупы.',
            'cta'   => 'Создать звук',
        );
        $cards[] = array(
            'url'   => GS_Catalog::base_url(),
            'title' => 'Каталог звуков',
            'short' => 'Каталог',
            'text'  => 'Больше 12 000 готовых звуков в 945 категориях — слушайте онлайн и скачивайте бесплатно в MP3.',
            'cta'   => 'Открыть каталог',
        );
        $cards[] = array(
            'url'   => GS_Api_Page::get_url(),
            'title' => 'API для разработчиков',
            'short' => 'API для разработчиков',
            'text'  => 'Те же инструменты из вашего кода: оживление фото, аватар, картинки, звуки и озвучка по HTTP-запросу. Ключ, JSON, оплата за запуск.',
            'cta'   => 'Смотреть документацию',
        );

        ob_start();
        ?>
        <section class="gs-cross">
            <h2 class="gs-section-title"><?php echo esc_html($title); ?></h2>
            <p class="gs-cross__lead">Все инструменты работают на одном балансе — переключайтесь между ними без отдельной оплаты.</p>
            <div class="gs-cross__grid">
                <?php foreach ($cards as $card): ?>
                    <article class="gs-cross__card">
                        <h3 class="gs-cross__title">
                            <a href="<?php echo esc_url($card['url']); ?>"><?php echo esc_html($card['short']); ?></a>
                        </h3>
                        <p class="gs-cross__text"><?php echo esc_html($card['text']); ?></p>
                        <a class="gs-btn gs-btn--primary gs-cross__cta" href="<?php echo esc_url($card['url']); ?>">
                            <?php echo esc_html($card['cta']); ?>
                        </a>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
        <?php
        return ob_get_clean();
    }

    private static function cta_label($id) {
        switch ($id) {
            case 'avatar':
                return 'Сделать говорящее видео';
            case 'vocal':
                return 'Убрать вокал';
            case 'denoise':
                return 'Очистить запись';
        }
        return 'Открыть';
    }
}
