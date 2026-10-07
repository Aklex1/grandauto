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
        return self::compose($service);
    }

    /**
     * Разметка страницы сервиса.
     *
     * Посадочные под коммерческие запросы показывают тот же инструмент, но со
     * своим заголовком, текстом и вопросами: тогда человек с поиска попадает
     * на страницу, отвечающую ровно его запросу, а не на общую витрину.
     *
     * @param array $service Описание сервиса из реестра.
     * @param array $extra   crumb, intro_html, body_html, cross_title.
     */
    public static function compose($service, $extra = array()) {
        $extra = array_merge(array(
            'crumb'       => '',
            'intro_html'  => '',
            'body_html'   => '',
            'cross_title' => 'Другие инструменты со звуком',
            'back_url'    => '',
            'landing_id'  => '',
        ), (array) $extra);

        $logged  = is_user_logged_in();
        // Бесплатные операции на своём сервере открыты и гостю: человек с
        // поиска должен получить результат, а не форму входа.
        $guest_ok = !$logged && GS_Lab::allows_guests($service['id']);
        $cost    = GS_Lab::get_cost($service['id']);
        $balance = $logged ? GS_SFX::get_balance(get_current_user_id()) : 0.0;
        $back     = $extra['back_url'] !== '' ? $extra['back_url'] : GS_Lab::get_url($service['id']);
        $login    = GS_Pages::get_login_url($back);

        ob_start();
        ?>
        <div class="gs-wrap gs-studio gs-lab" data-service="<?php echo esc_attr($service['id']); ?>">
            <nav class="gs-breadcrumbs" aria-label="Хлебные крошки">
                <a href="<?php echo esc_url(home_url('/')); ?>">Главная</a>
                <span aria-hidden="true">/</span>
                <span class="gs-breadcrumbs__current"><?php echo esc_html($extra['crumb'] !== '' ? $extra['crumb'] : $service['menu']); ?></span>
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

                <?php
                // Служба извлечения звука живёт на отдельной машине, и когда
                // она не на связи, ссылки не работают вовсе. Молчать об этом
                // нельзя: человек вставляет ссылку, получает отказ, пробует
                // ещё раз — и так по кругу. Загрузка файла при этом работает.
                $yta_down = in_array($service['id'], array('ytaudio', 'stt'), true)
                    && GS_Lab::ytaudio_down();
                ?>
                <?php if ($yta_down): ?>
                    <p class="gs-hero__note">
                        Ссылки на ролики сейчас не обрабатываются: служба извлечения звука
                        не на связи. Это наша поломка, и мы уже о ней знаем. Файл с устройства
                        загрузить можно — этот путь работает.
                    </p>
                <?php endif; ?>
            </section>

            <?php if ($extra['intro_html'] !== ''): ?>
                <section class="gs-lab-intro"><?php echo $extra['intro_html']; // phpcs:ignore WordPress.Security.EscapeOutput ?></section>
            <?php endif; ?>

            <?php if (!empty($service['examples_first'])): ?>
                <?php echo self::render_examples($service); // phpcs:ignore WordPress.Security.EscapeOutput ?>
            <?php endif; ?>

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
                    <a class="gs-anchor" id="gs-lab-start" aria-hidden="true"></a>
                    <?php foreach ($service['inputs'] as $input): ?>
                        <div class="gs-field">
                            <?php $optional = in_array($input, (array) (isset($service['input_optional']) ? $service['input_optional'] : array()), true); ?>
                            <?php
                            // Подпись и пояснение — по виду файла. Раньше здесь
                            // стояло «фотография или аудиофайл»: третьего вида не
                            // было, и видео представилось бы аудиофайлом.
                            $labels = array('image' => 'Фотография', 'audio' => 'Аудиофайл', 'video' => 'Видеофайл');
                            // Сервису может понадобиться несколько снимков — по человеку:
                            // тогда «Фотография» четыре раза подряд ничего не объясняет.
                            $own = isset($service['input_labels'][$input]) ? $service['input_labels'][$input] : '';
                            $label = $own !== '' ? $own : (isset($labels[$input]) ? $labels[$input] : 'Файл');
                            ?>
                            <label class="gs-label" for="gs-lab-<?php echo esc_attr($input); ?>">
                                <?php echo esc_html($label); ?><?php echo $optional ? ' (необязательно)' : ''; ?>
                            </label>
                            <input id="gs-lab-<?php echo esc_attr($input); ?>" class="gs-input gs-file" type="file"
                                   data-kind="<?php echo esc_attr($input); ?>"
                                   accept="<?php echo esc_attr($service['accept'][$input]); ?>">
                            <span class="gs-hint" data-file-hint="<?php echo esc_attr($input); ?>">
                                <?php
                                if (strpos($input, 'image') === 0) {
                                    echo 'JPEG или PNG, до 10 МБ. Лицо анфас, крупно.';
                                } elseif ($input === 'video') {
                                    $limit = GS_Lab::max_seconds($service['id']);
                                    echo 'MP4, WebM, MOV или M4V. До '
                                        . (int) round(GS_Lab::MAX_VIDEO_BYTES / 1048576) . ' МБ';
                                    if ($limit > 0) {
                                        echo $limit < 120
                                            ? ' и ' . (int) $limit . ' секунд'
                                            : ' и ' . (int) round($limit / 60) . ' минут';
                                    }
                                    echo '.';
                                } else {
                                    $limit = GS_Lab::max_seconds($service['id']);
                                    $formats = $service['id'] === 'stt'
                                        ? 'MP3, WAV, M4A, OGG, а также MP4 и WebM'
                                        : 'MP3, WAV, M4A или OGG';
                                    echo $formats . '. До ' . ($service['id'] === 'vocal' ? '20' : '10') . ' МБ';
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
                            <label class="gs-label" for="gs-lab-prompt">
                                <?php echo esc_html(!empty($service['prompt_label']) ? $service['prompt_label'] : 'Описание сцены'); ?>
                            </label>
                            <textarea id="gs-lab-prompt" class="gs-textarea" rows="<?php echo empty($service['inputs']) ? 3 : 2; ?>" maxlength="500"
                                      placeholder="<?php echo esc_attr(!empty($service['prompt_place']) ? $service['prompt_place'] : 'Например: спокойно рассказывает, смотрит в камеру'); ?>"></textarea>
                            <span class="gs-hint"><?php echo esc_html($service['prompt_hint']); ?></span>
                        </div>
                    <?php endif; ?>

                    <?php foreach ((array) (isset($service['fields']) ? $service['fields'] : array()) as $name => $field): ?>
                        <?php $fid = 'gs-lab-f-' . sanitize_key($name); ?>
                        <div class="gs-field">
                            <?php if ($field['type'] === 'checkbox'): ?>
                                <label class="gs-check" for="<?php echo esc_attr($fid); ?>">
                                    <input type="checkbox" id="<?php echo esc_attr($fid); ?>"
                                           data-gs-field="<?php echo esc_attr($name); ?>"
                                           <?php checked(!empty($field['default'])); ?>>
                                    <span><?php echo esc_html($field['label']); ?></span>
                                </label>
                            <?php elseif ($field['type'] === 'select'): ?>
                                <label class="gs-label" for="<?php echo esc_attr($fid); ?>"><?php echo esc_html($field['label']); ?></label>
                                <select id="<?php echo esc_attr($fid); ?>" class="gs-input"
                                        data-gs-field="<?php echo esc_attr($name); ?>">
                                    <?php foreach ((array) $field['options'] as $value => $caption): ?>
                                        <option value="<?php echo esc_attr($value); ?>"
                                            <?php selected((string) $value, (string) (isset($field['default']) ? $field['default'] : '')); ?>>
                                            <?php echo esc_html($caption); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            <?php elseif ($field['type'] === 'textarea'): ?>
                                <label class="gs-label" for="<?php echo esc_attr($fid); ?>"><?php echo esc_html($field['label']); ?></label>
                                <textarea id="<?php echo esc_attr($fid); ?>" class="gs-textarea"
                                          data-gs-field="<?php echo esc_attr($name); ?>"
                                          rows="<?php echo (int) (isset($field['rows']) ? $field['rows'] : 4); ?>"
                                          maxlength="<?php echo (int) (isset($field['max']) ? $field['max'] : 2000); ?>"
                                          placeholder="<?php echo esc_attr(isset($field['place']) ? $field['place'] : ''); ?>"></textarea>
                            <?php else: ?>
                                <label class="gs-label" for="<?php echo esc_attr($fid); ?>"><?php echo esc_html($field['label']); ?></label>
                                <input type="text" id="<?php echo esc_attr($fid); ?>" class="gs-input"
                                       data-gs-field="<?php echo esc_attr($name); ?>"
                                       maxlength="<?php echo (int) (isset($field['max']) ? $field['max'] : 200); ?>"
                                       placeholder="<?php echo esc_attr(isset($field['place']) ? $field['place'] : ''); ?>">
                            <?php endif; ?>
                            <?php if (!empty($field['hint'])): ?>
                                <span class="gs-hint"><?php echo esc_html($field['hint']); ?></span>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>

                    <?php if ($service['id'] === 'ytaudio'): ?>
                        <?php echo self::render_local_extract(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                    <?php endif; ?>

                    <div class="gs-form__foot">
                        <div class="gs-balance">
                            <?php if ($logged): ?>
                                <span class="gs-balance__label">Баланс</span>
                                <span class="gs-balance__value" id="gs-lab-balance"><?php echo esc_html(number_format_i18n($balance, 2)); ?> ₽</span>
                                <a class="gs-balance__topup" data-gs-topup href="<?php echo esc_url(GS_Payments::topup_url($service['id'])); ?>">Пополнить</a>
                            <?php elseif ($guest_ok): ?>
                                <span class="gs-balance__label gs-balance__label--free">Бесплатно, без регистрации</span>
                            <?php else: ?>
                                <span class="gs-balance__label">Нужен вход</span>
                            <?php endif; ?>
                        </div>

                        <?php if ($logged || $guest_ok): ?>
                            <button class="gs-btn gs-btn--primary gs-btn--lg" type="submit" id="gs-lab-submit">
                                <?php echo esc_html(self::submit_label($service)); ?>
                            </button>
                        <?php else: ?>
                            <a class="gs-btn gs-btn--primary gs-btn--lg" data-gs-auth
                               href="<?php echo esc_url($login); ?>">Войти и продолжить</a>
                        <?php endif; ?>
                    </div>

                    <p class="gs-form__note" id="gs-lab-note" role="status" aria-live="polite"></p>
                </form>

                <aside class="gs-panel gs-result" id="gs-lab-result">
                    <div class="gs-result__empty" id="gs-lab-empty">
                        <div class="gs-result__icon" aria-hidden="true"><?php
                        $icons = array('avatar' => '🎬', 'music' => '🎵', 'stt' => '📝', 'ytaudio' => '🎬');
                        echo isset($icons[$service['id']]) ? $icons[$service['id']] : '🎚️';
                    ?></div>
                        <h2 class="gs-result__title">Здесь появится результат</h2>
                        <p class="gs-result__text"><?php
                        if ($service['id'] === 'ytaudio') {
                            echo 'Вставьте ссылку слева и заберите дорожку.';
                        } elseif ($service['id'] === 'stt') {
                            echo 'Загрузите запись или вставьте ссылку слева.';
                        } elseif (empty($service['inputs'])) {
                            echo 'Опишите задачу слева и запустите генерацию.';
                        } else {
                            echo 'Загрузите файл слева и запустите обработку.';
                        }
                    ?></p>
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

            <?php if (GS_Lab::keeps_history($service['id'])): ?>
                <section class="gs-lab-history" id="gs-lab-history" hidden>
                    <h2 class="gs-section-title">Ваши кадры</h2>
                    <p class="gs-lab-history__lead">Последние результаты сохраняются здесь — можно вернуться и скачать позже.</p>
                    <div class="gs-lab-history__grid" id="gs-lab-history-grid"></div>
                </section>
            <?php endif; ?>

            <?php if (empty($service['examples_first'])): ?>
                <?php echo self::render_examples($service); // phpcs:ignore WordPress.Security.EscapeOutput ?>
            <?php endif; ?>

            <?php if ($extra['body_html'] !== ''): ?>
                <section class="gs-lab-body"><?php echo $extra['body_html']; // phpcs:ignore WordPress.Security.EscapeOutput ?></section>
            <?php endif; ?>

            <section class="gs-tips">
                <h2 class="gs-section-title">Как это работает</h2>
                <?php
                // Иконки идут по смыслу шага, а не случайным набором:
                // сначала загрузка, потом настройка, работа и результат.
                $step_icons = array('send', 'flow', 'ai', 'check', 'rocket');
                ?>
                <ol class="gs-steps">
                    <?php foreach ($service['steps'] as $i => $step): ?>
                        <li class="gs-step">
                            <?php if (class_exists('GS_Brand')): ?>
                                <?php echo GS_Brand::icon_tag(
                                    $step_icons[$i % count($step_icons)], 'soft', 'gb-icon gs-step__icon'
                                ); ?>
                            <?php endif; ?>
                            <span class="gs-step__num"><?php echo (int) ($i + 1); ?></span>
                            <span class="gs-step__text"><?php echo esc_html($step); ?></span>
                        </li>
                    <?php endforeach; ?>
                </ol>
            </section>

            <?php echo GS_Keywords::render(GS_Keywords::group_for_lab($service['id'])); // phpcs:ignore WordPress.Security.EscapeOutput ?>

            <?php echo self::render_cross_links($service['id'], $extra['cross_title'], $extra['landing_id']); // phpcs:ignore WordPress.Security.EscapeOutput ?>

            <section class="gs-faq">
                <h2 class="gs-section-title">Частые вопросы</h2>
                <?php foreach ($service['faq'] as $pair): ?>
                    <details class="gs-faq__item">
                        <summary class="gs-faq__q"><?php echo esc_html($pair[0]); ?></summary>
                        <p class="gs-faq__a"><?php echo esc_html($pair[1]); ?></p>
                    </details>
                <?php endforeach; ?>
            </section>

            <?php echo GS_Support::render($service['nav']); // phpcs:ignore WordPress.Security.EscapeOutput ?>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Галерея примеров.
     *
     * Сервису, который делает картинку, примеры нужны раньше текста: по
     * описанию человек не понимает, насколько похоже выйдет лицо, и уходит
     * со страницы, не загрузив снимок. Поэтому блок стоит сразу после
     * инструмента, а не в конце.
     */
    /**
     * Галерея примеров — подборками, а не кадр за кадром.
     *
     * Сервис продаёт набор: одна загрузка, несколько локаций. Если
     * показывать по одной картинке, человек так и не поймёт, что получит
     * пять разных кадров, а не пять попыток одного.
     */
    /**
     * Каталог готовых подборок — первой секцией.
     *
     * Человек не хочет описывать съёмку словами: он хочет увидеть готовую
     * подборку и сказать «вот такую же, только с нами». Поэтому примеры
     * стоят до инструмента, а кнопка под каждой подборкой прокручивает к
     * форме и подставляет её — заполнять выпадающие списки руками не надо.
     */
    private static function render_examples($service) {
        $items = isset($service['examples']) && is_array($service['examples']) ? $service['examples'] : array();
        if (empty($items)) {
            return '';
        }
        ob_start();
        ?>
        <section class="gs-lab-examples" id="gs-lab-sets">
            <h2 class="gs-section-title">Готовые подборки</h2>
            <p class="gs-lab-examples__lead">Выберите подборку и нажмите «Повторить» — ниже останется загрузить свои фото. Снимки в примерах мы сгенерировали сами: показывать чужие лица без спроса нельзя, настоящих людей здесь нет.</p>
            <?php foreach ($items as $item): ?>
                <figure class="gs-set">
                    <figcaption class="gs-set__cap">
                        <div class="gs-set__head">
                            <strong><?php echo esc_html($item['title']); ?></strong>
                            <?php if (!empty($item['set'])): ?>
                                <button class="gs-btn gs-btn--primary gs-set__go" type="button"
                                        data-gs-repeat="<?php echo esc_attr($item['set']); ?>"
                                        data-gs-count="<?php echo esc_attr((int) (isset($item['count']) ? $item['count'] : count((array) $item['shots']))); ?>">
                                    Повторить с моими фото
                                </button>
                            <?php endif; ?>
                        </div>
                        <?php if (!empty($item['note'])): ?>
                            <span><?php echo esc_html($item['note']); ?></span>
                        <?php endif; ?>
                    </figcaption>
                    <div class="gs-set__strip">
                        <?php foreach ((array) $item['shots'] as $shot): ?>
                            <div class="gs-set__shot">
                                <img src="<?php echo esc_url($shot[1]); ?>" alt="<?php echo esc_attr($item['title'] . ' — ' . $shot[0]); ?>" loading="lazy" width="560" height="747">
                                <span class="gs-set__tag gs-set__tag--after"><?php echo esc_html($shot[0]); ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </figure>
            <?php endforeach; ?>
        </section>
        <?php
        return ob_get_clean();
    }

    private static function submit_label($service) {
        switch ($service['id']) {
            case 'photo':
                return 'Снять кадр';
            case 'avatar':
                return 'Сделать видео';
            case 'vocal':
                return 'Разделить дорожки';
            case 'denoise':
                return 'Очистить запись';
            case 'music':
                return 'Создать музыку';
            case 'stt':
                return 'Расшифровать';
            case 'ytaudio':
                return 'Достать дорожку';
        }
        return 'Запустить';
    }

    /**
     * Блок ссылок на остальные микросервисы с призывом.
     * Он же раздаёт вес между посадочными: каждая ссылается на все соседние.
     */
    /**
     * Файл с компьютера — разбирается прямо в браузере.
     *
     * Дорожку из чужого ролика можно снять только на сервере, а свой файл
     * незачем гонять по сети: браузер разберёт его сам, быстрее и не отдавая
     * запись никому. Поэтому у сервиса два входа, и второй работает даже
     * тогда, когда служба извлечения по ссылке недоступна.
     */
    private static function render_local_extract() {
        ob_start();
        ?>
        <div class="gs-ytl" id="gs-ytl" data-lame="<?php echo esc_url(GS_PLUGIN_URL . 'assets/js/vendor/lame.min.js'); ?>">
            <div class="gs-ytl__or"><span>или</span></div>

            <label class="gs-ytl__drop" for="gs-ytl-file">
                <span class="gs-ytl__icon" aria-hidden="true">🎞️</span>
                <span class="gs-ytl__title">Файл с компьютера</span>
                <span class="gs-ytl__lead">Перетащите видео сюда или нажмите, чтобы выбрать</span>
                <span class="gs-ytl__formats">MP4, MOV, WebM, M4V, а также готовые аудиофайлы</span>
                <input id="gs-ytl-file" class="gs-ytl__input" type="file"
                       accept="video/*,audio/*,.mp4,.mov,.m4v,.webm,.mkv,.m4a,.mp3,.wav,.ogg">
            </label>

            <p class="gs-ytl__privacy">
                Файл обрабатывается прямо в браузере и никуда не загружается — ни на наш сервер, ни куда-либо ещё.
                Поэтому дорожка готова за секунды и ничего не стоит. Предел здесь не наш, а браузера:
                запись целиком держится в памяти, так что до 25 минут — надёжно, дальше стоит резать на части.
            </p>

            <p class="gs-ytl__note" id="gs-ytl-note" role="status" aria-live="polite"></p>
            <div class="gs-ytl__out" id="gs-ytl-out" hidden></div>
        </div>
        <?php
        return ob_get_clean();
    }

    public static function render_cross_links($current_id = '', $title = 'Другие инструменты со звуком', $skip_landing = '') {
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
        // Числа берём из самого каталога. Вписанные руками «12 000 звуков в
        // 945 категориях» устарели через месяц после того, как их вписали,
        // и на живой странице это читается как небрежность.
        $stats = GS_Catalog::stats();
        $cards[] = array(
            'url'   => GS_Catalog::base_url(),
            'title' => 'Каталог звуков',
            'short' => 'Каталог',
            'text'  => sprintf(
                '%s готовых звуков в %s — слушайте онлайн и скачивайте бесплатно в MP3.',
                number_format_i18n((int) $stats['sounds']),
                GS_Catalog::plural_categories_text((int) $stats['filled'])
            ),
            'cta'   => 'Открыть каталог',
        );

        // Фоновая музыка — самый крупный раздел каталога и отдельный спрос:
        // её ищут не как «звук», а как «музыку для видео». В шапке сайта до
        // неё сейчас не добраться, поэтому ссылка нужна здесь.
        foreach (GS_Sections::overview() as $section) {
            if ($section['slug'] !== 'fonovaya-muzyka') {
                continue;
            }
            $cards[] = array(
                'url'   => GS_Sections::url($section['slug']),
                'title' => $section['title'],
                'short' => 'Фоновая музыка',
                'text'  => sprintf(
                    'Инструментальные треки без слов для видео, роликов и подкастов: %s в %s.',
                    GS_Catalog::plural_sounds((int) $section['sounds']),
                    GS_Catalog::plural_categories_text((int) $section['cats'])
                ),
                'cta'   => 'Слушать музыку',
            );
            break;
        }
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

            <?php
            // Посадочные под частые формулировки задач. Без ссылок отсюда они
            // остаются без внутреннего веса, и поисковик их почти не обходит.
            $tasks = array();
            foreach (GS_Landing::all() as $landing) {
                if ($landing['id'] === $skip_landing || !GS_Lab::is_available($landing['service'])) {
                    continue;
                }
                $tasks[] = $landing;
            }
            ?>
            <?php if ($tasks): ?>
                <p class="gs-cross__tasks">
                    <span class="gs-cross__tasks-label">Частые задачи:</span>
                    <?php foreach ($tasks as $i => $landing): ?><?php echo $i ? ', ' : ''; ?><a href="<?php echo esc_url(GS_Landing::get_url($landing['id'])); ?>"><?php echo esc_html(mb_strtolower($landing['menu'])); ?></a><?php endforeach; ?>.
                </p>
            <?php endif; ?>
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
