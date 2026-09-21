<?php
/**
 * Страница «Песня своим голосом»: мастер из трёх шагов.
 *
 * Обычные сервисы — одна форма и одна кнопка. Здесь без шагов не обойтись:
 * человек сначала отдаёт образец голоса, потом читает фразу, которую сервис
 * придумывает уже после разбора образца, и только затем пишет песню.
 * Поэтому у страницы своя разметка, а не общая форма микросервисов.
 */

if (!defined('ABSPATH')) {
    exit;
}

class GS_Voice_Page {

    const SERVICE = 'voicesong';

    public static function register_shortcodes() {
        add_shortcode('genius_voice', array(__CLASS__, 'render'));
    }

    public static function render($atts = array()) {
        $service = GS_Lab::get_service(self::SERVICE);
        if (!$service) {
            return '';
        }

        $logged  = is_user_logged_in();
        $user_id = get_current_user_id();
        $balance = $logged ? GS_SFX::get_balance($user_id) : 0.0;
        $voices  = $logged ? GS_Voice::user_voices($user_id) : array();
        $login   = GS_Pages::get_login_url(GS_Lab::get_url(self::SERVICE));

        ob_start();
        ?>
        <div class="gs-wrap gs-studio gs-lab gs-voice" data-service="voicesong">
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
                    <span class="gs-chip gs-chip--ok">голос — <?php echo esc_html(number_format_i18n(GS_Voice::voice_cost(), 0)); ?> ₽ один раз</span>
                    <span class="gs-chip gs-chip--ok">песня — <?php echo esc_html(number_format_i18n(GS_Voice::song_cost(), 0)); ?> ₽</span>
                    <span class="gs-chip">запись прямо в браузере</span>
                </div>
            </section>

            <?php if (!GS_Lab::is_available(self::SERVICE)): ?>
                <section class="gs-empty gs-empty--page">
                    <div class="gs-empty__icon" aria-hidden="true">🎤</div>
                    <h2 class="gs-empty__title">Инструмент подключается</h2>
                    <p class="gs-empty__text">Скоро здесь можно будет спеть песню собственным голосом.</p>
                    <a class="gs-btn gs-btn--primary" href="<?php echo esc_url(GS_Lab::get_url('music')); ?>">Пока создать музыку нейросетью</a>
                </section>
            <?php elseif (!$logged): ?>
                <section class="gs-empty gs-empty--page">
                    <div class="gs-empty__icon" aria-hidden="true">🎤</div>
                    <h2 class="gs-empty__title">Нужен вход</h2>
                    <p class="gs-empty__text">
                        Голос сохраняется в вашем кабинете: один раз создали — и дальше поёте им любые песни.
                        Поэтому без аккаунта не обойтись.
                    </p>
                    <a class="gs-btn gs-btn--primary gs-btn--lg" data-gs-auth
                       href="<?php echo esc_url($login); ?>">Войти и создать голос</a>
                </section>
            <?php else: ?>

            <?php $has_voice = !empty($voices); ?>

            <?php if ($has_voice): ?>
                <p class="gs-voice__ready">
                    Ваш голос готов — песни делаются в один шаг.
                    <button type="button" class="gs-linkbtn" id="gs-voice-more">Создать ещё один голос</button>
                </p>
            <?php endif; ?>

            <ol class="gs-wizard" id="gs-voice-steps"<?php echo $has_voice ? ' hidden' : ''; ?>>
                <li class="gs-wizard__step is-active" data-step="1"><span>1</span> Образец голоса</li>
                <li class="gs-wizard__step" data-step="2"><span>2</span> Проверочная фраза</li>
                <li class="gs-wizard__step" data-step="3"><span>3</span> Песня</li>
            </ol>

            <div class="gs-studio__layout">
                <div class="gs-panel gs-form gs-voice__panel">

                    <!-- Шаг 1 -->
                    <section class="gs-voice__step" data-step="1">
                        <h2 class="gs-voice__title">Запишите свой голос</h2>
                        <p class="gs-voice__text">
                            Двадцать секунд обычной речи без музыки и посторонних звуков. Говорите ровно,
                            ближе к микрофону — по этой записи нейросеть запомнит ваш тембр.
                        </p>
                        <div class="gs-source">
                            <div class="gs-source__way">
                                <span class="gs-source__title">Записать прямо здесь</span>
                                <div class="gs-rec" data-rec="sample">
                                    <button type="button" class="gs-btn gs-btn--ghost gs-rec__go" data-action="rec">Записать с микрофона</button>
                                    <span class="gs-rec__time" data-role="time">0:00</span>
                                    <audio class="gs-rec__play" data-role="play" controls hidden></audio>
                                </div>
                                <span class="gs-hint">Двадцать секунд обычной речи. Остановить можно в любой момент.</span>
                            </div>
                            <div class="gs-source__way">
                                <span class="gs-source__title">Загрузить готовую запись</span>
                                <input id="gs-voice-sample" class="gs-input gs-file" type="file" accept="audio/*">
                                <span class="gs-hint">MP3, WAV, M4A или OGG, до 10 МБ. Подойдёт запись с диктофона.</span>
                            </div>
                        </div>
                        <div class="gs-form__foot">
                            <div class="gs-balance">
                                <span class="gs-balance__label">Баланс</span>
                                <span class="gs-balance__value" id="gs-voice-balance"><?php echo esc_html(number_format_i18n($balance, 2)); ?> ₽</span>
                                <a class="gs-balance__topup" data-gs-topup href="<?php echo esc_url(GS_Payments::topup_url(self::SERVICE)); ?>">Пополнить</a>
                            </div>
                            <button class="gs-btn gs-btn--primary gs-btn--lg" type="button" id="gs-voice-start" disabled>
                                Создать голос за <?php echo esc_html(number_format_i18n(GS_Voice::voice_cost(), 0)); ?> ₽
                            </button>
                            <?php if ($has_voice): ?>
                                <button type="button" class="gs-linkbtn" id="gs-voice-back">Вернуться к песне</button>
                            <?php endif; ?>
                        </div>
                    </section>

                    <!-- Шаг 2 -->
                    <section class="gs-voice__step" data-step="2" hidden>
                        <h2 class="gs-voice__title">Прочитайте эту фразу вслух</h2>
                        <blockquote class="gs-voice__phrase" id="gs-voice-phrase">Готовим фразу…</blockquote>
                        <p class="gs-voice__text gs-voice__text--small">
                            Займёт десять секунд и только один раз: без этого поставщик голос не создаёт.
                            Так подтверждается, что голос ваш, — иначе любую запись из чужого ролика
                            можно было бы превратить в поющий голос её владельца.
                        </p>
                        <div class="gs-source">
                            <div class="gs-source__way">
                                <span class="gs-source__title">Записать прямо здесь</span>
                                <div class="gs-rec" data-rec="verify">
                                    <button type="button" class="gs-btn gs-btn--ghost gs-rec__go" data-action="rec" disabled>Записать фразу</button>
                                    <span class="gs-rec__time" data-role="time">0:00</span>
                                    <audio class="gs-rec__play" data-role="play" controls hidden></audio>
                                </div>
                                <span class="gs-hint">Тем же голосом и в том же месте, что и образец.</span>
                            </div>
                            <div class="gs-source__way">
                                <span class="gs-source__title">Загрузить запись фразы</span>
                                <input id="gs-voice-verify" class="gs-input gs-file" type="file" accept="audio/*">
                                <span class="gs-hint">Если микрофон в браузере недоступен — запишите на телефон.</span>
                            </div>
                        </div>
                        <div class="gs-field">
                            <label class="gs-label" for="gs-voice-name">Название голоса</label>
                            <input id="gs-voice-name" class="gs-input" type="text" maxlength="60" placeholder="Мой голос">
                        </div>
                        <div class="gs-form__foot">
                            <button class="gs-btn gs-btn--primary gs-btn--lg" type="button" id="gs-voice-verify-go" disabled>
                                Подтвердить голос
                            </button>
                        </div>
                    </section>

                    <!-- Шаг 3 -->
                    <section class="gs-voice__step" data-step="3" hidden>
                        <h2 class="gs-voice__title">Напишите песню</h2>
                        <div class="gs-field">
                            <label class="gs-label" for="gs-voice-pick">Каким голосом петь</label>
                            <select id="gs-voice-pick" class="gs-input">
                                <?php foreach ($voices as $voice): ?>
                                    <option value="<?php echo esc_attr($voice['id']); ?>"><?php echo esc_html($voice['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="gs-field">
                            <label class="gs-label" for="gs-voice-lyrics">Текст песни</label>
                            <textarea id="gs-voice-lyrics" class="gs-textarea" rows="7" maxlength="2500"
                                      placeholder="[Куплет]&#10;…&#10;[Припев]&#10;…"></textarea>
                            <span class="gs-hint">Пометки [Куплет] и [Припев] помогают выстроить структуру. Оставьте пустым — нейросеть напишет текст сама по описанию ниже.</span>
                        </div>
                        <div class="gs-field">
                            <label class="gs-label" for="gs-voice-style">Стиль и настроение</label>
                            <input id="gs-voice-style" class="gs-input" type="text" maxlength="200"
                                   placeholder="поп-баллада, гитара, тёплое настроение">
                        </div>
                        <div class="gs-field">
                            <label class="gs-label" for="gs-voice-title">Название песни (необязательно)</label>
                            <input id="gs-voice-title" class="gs-input" type="text" maxlength="80" placeholder="Мой голос">
                        </div>
                        <div class="gs-field">
                            <label class="gs-label" for="gs-voice-about">О чём песня (если текста нет)</label>
                            <textarea id="gs-voice-about" class="gs-textarea" rows="3" maxlength="500"
                                      placeholder="Поздравление маме с юбилеем, тёплое и с юмором"></textarea>
                        </div>
                        <div class="gs-form__foot">
                            <div class="gs-balance">
                                <span class="gs-balance__label">Баланс</span>
                                <span class="gs-balance__value" id="gs-voice-balance-2"><?php echo esc_html(number_format_i18n($balance, 2)); ?> ₽</span>
                                <a class="gs-balance__topup" data-gs-topup href="<?php echo esc_url(GS_Payments::topup_url(self::SERVICE)); ?>">Пополнить</a>
                            </div>
                            <button class="gs-btn gs-btn--primary gs-btn--lg" type="button" id="gs-voice-sing">
                                Спеть за <?php echo esc_html(number_format_i18n(GS_Voice::song_cost(), 0)); ?> ₽
                            </button>
                        </div>
                    </section>

                    <p class="gs-form__note" id="gs-voice-note" role="status" aria-live="polite"></p>
                </div>

                <aside class="gs-panel gs-result" id="gs-voice-result">
                    <div class="gs-result__empty" id="gs-voice-empty">
                        <div class="gs-result__icon" aria-hidden="true">🎤</div>
                        <h2 class="gs-result__title">Здесь появится песня</h2>
                        <p class="gs-result__text">Сначала создайте голос, потом напишите текст — и нейросеть споёт его вашим голосом.</p>
                    </div>
                    <div class="gs-result__loading" id="gs-voice-loading" hidden>
                        <div class="gs-spinner" aria-hidden="true"></div>
                        <p class="gs-result__text" id="gs-voice-stage">Работаем…</p>
                        <div class="gs-progress"><div class="gs-progress__bar" id="gs-voice-progress"></div></div>
                    </div>
                    <div class="gs-result__ready" id="gs-voice-ready" hidden>
                        <h2 class="gs-result__title">Готово</h2>
                        <div id="gs-voice-files"></div>
                        <div class="gs-result__actions">
                            <button class="gs-btn gs-btn--ghost" type="button" id="gs-voice-again">Спеть ещё песню</button>
                        </div>
                    </div>
                </aside>
            </div>
            <?php endif; ?>

            <?php $songs = $logged ? GS_Voice::own_songs($user_id) : array(); ?>
            <section class="gs-archive" id="gs-voice-archive"<?php echo $songs ? '' : ' hidden'; ?>>
                <h2 class="gs-section-title">Ваши песни</h2>
                <p class="gs-archive__lead">
                    Всё, что вы спели своим голосом. Файлы лежат у нас — скачать можно в любой момент.
                </p>
                <div class="gs-archive__list" id="gs-voice-archive-list">
                    <?php foreach ($songs as $song): ?>
                        <article class="gs-archive__item" data-song="<?php echo esc_attr($song['id']); ?>">
                            <div class="gs-archive__head">
                                <span class="gs-archive__title"><?php echo esc_html($song['title']); ?></span>
                                <?php if ($song['created']): ?>
                                    <span class="gs-archive__date"><?php echo esc_html(date_i18n('d.m.Y', $song['created'])); ?></span>
                                <?php endif; ?>
                            </div>
                            <audio class="gs-archive__audio" controls preload="none" src="<?php echo esc_url($song['url']); ?>"></audio>
                            <div class="gs-archive__actions">
                                <a class="gs-btn gs-btn--ghost" href="<?php echo esc_url($song['url']); ?>" download>Скачать MP3</a>
                                <?php if ($song['published']): ?>
                                    <span class="gs-archive__done">В галерее</span>
                                <?php else: ?>
                                    <button type="button" class="gs-btn gs-btn--ghost" data-publish="<?php echo esc_attr($song['id']); ?>">Опубликовать в галерее</button>
                                <?php endif; ?>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            </section>

            <?php echo GS_Songs::render_teaser(3); // phpcs:ignore WordPress.Security.EscapeOutput ?>

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

            <section class="gs-lab-body">
                <h2 class="gs-section-title">Что получится, а что нет</h2>
                <div class="gs-prose">
                    <p>Нейросеть повторяет тембр — то, по чему голос узнают близкие. Она не копирует манеру пения
                    и не делает из вас профессионального вокалиста: это ваш голос, поющий ровно и в ноты.
                    Поэтому образец не нужно петь, достаточно обычной речи.</p>
                    <p>Лучше всего результат слышен на поздравлениях, гимнах команды и песнях к семейным датам:
                    когда важно, чтобы пел именно этот человек. Для фона под ролик голос не нужен — там быстрее
                    заказать <a href="/muzyka-dlya-video/">музыку для видео</a>.</p>
                </div>

                <h2 class="gs-section-title">Как записать хороший образец</h2>
                <div class="gs-prose">
                    <ul>
                        <li>Тихая комната: без телевизора, улицы и фоновой музыки.</li>
                        <li>Микрофон в двадцати сантиметрах — можно диктофон телефона.</li>
                        <li>Двадцать секунд связной речи, без длинных пауз.</li>
                        <li>Обычный тон: не читайте нарочито «дикторским» голосом.</li>
                    </ul>
                    <p>Если запись получилась шумной, прогоните её через <a href="/ubrat-shum/">очистку от шума</a>
                    и загрузите результат — тембр станет чище, и голос выйдет точнее.</p>
                </div>
            </section>

            <?php echo GS_Lab_Page::render_cross_links(self::SERVICE, 'Другие инструменты со звуком'); // phpcs:ignore WordPress.Security.EscapeOutput ?>

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
}
