<?php
/**
 * Страница партнёрской программы.
 *
 * Делиться ссылкой человек будет только если ему есть что показать: своя
 * ссылка на виду, кнопки в соцсети рядом, и честно написано, сколько и с
 * чего капает. Поэтому страница — не описание условий, а рабочее место
 * партнёра: ссылка, кнопки, счётчики.
 */

if (!defined('ABSPATH')) {
    exit;
}

class GS_Partner_Page {

    const OPT_PAGE = 'gs_partner_page';
    const SLUG     = 'partnerskaya-programma';

    public static function register_shortcodes() {
        add_shortcode('genius_partner', array(__CLASS__, 'render'));
    }

    public static function ensure_page() {
        $page_id = (int) get_option(self::OPT_PAGE);
        if ($page_id > 0 && get_post($page_id)) {
            return $page_id;
        }
        $existing = get_page_by_path(self::SLUG);
        if ($existing) {
            $page_id = (int) $existing->ID;
            wp_update_post(array(
                'ID'           => $page_id,
                'post_title'   => 'Партнёрская программа',
                'post_content' => '[genius_partner]',
                'post_status'  => 'publish',
            ));
        } else {
            $page_id = (int) wp_insert_post(array(
                'post_title'   => 'Партнёрская программа',
                'post_content' => '[genius_partner]',
                'post_status'  => 'publish',
                'post_type'    => 'page',
                'post_name'    => self::SLUG,
            ));
        }
        if ($page_id > 0) {
            update_option(self::OPT_PAGE, $page_id);
        }
        return $page_id;
    }

    public static function get_url() {
        $pid = (int) get_option(self::OPT_PAGE);
        return $pid > 0 ? (string) get_permalink($pid) : home_url('/' . self::SLUG . '/');
    }

    public static function is_page() {
        $pid = (int) get_option(self::OPT_PAGE);
        if ($pid > 0 && is_page($pid)) {
            return true;
        }
        global $post;
        return ($post instanceof WP_Post) && has_shortcode((string) $post->post_content, 'genius_partner');
    }

    /* ------------------------------------------------------------------ */

    public static function render($atts = array()) {
        $logged = is_user_logged_in();
        $data = $logged && class_exists('GS_Referral')
            ? GS_Referral::summary(get_current_user_id())
            : array('ready' => class_exists('GS_Referral') && GS_Referral::ready(),
                    'rate' => class_exists('GS_Referral') ? round(GS_Referral::rate() * 100) : 15,
                    'link' => '', 'code' => '', 'invited' => 0, 'earned' => 0.0, 'rows' => array());
        $rate = (int) ($data['rate'] ?: 15);
        $link = (string) $data['link'];

        ob_start();
        ?>
        <div class="gs-wrap gs-partner">
            <header class="gs-hero">
                <span class="gs-hero__badge">Партнёрская программа</span>
                <h1 class="gs-hero__title">Приводите людей — получайте <?php echo (int) $rate; ?>% с их трат</h1>
                <p class="gs-hero__lead">
                    Делитесь своей ссылкой где угодно: в видео на YouTube, в постах, в чатах, в описании
                    канала. Кто перешёл по ней и завёл аккаунт — закрепляется за вами навсегда.
                    Дальше с каждой его оплаченной работы <?php echo (int) $rate; ?>% приходит вам на баланс.
                </p>
            </header>

            <?php if (!$logged): ?>
                <section class="gs-panel gs-partner__guest">
                    <p>Ссылка появится сразу после входа — она привязана к аккаунту.</p>
                    <a class="gs-btn gs-btn--primary gs-btn--lg" data-gs-auth
                       href="<?php echo esc_url(class_exists('GS_Auth') ? GS_Auth::login_url() : wp_login_url()); ?>">Войти</a>
                </section>
            <?php elseif (!$data['ready'] || $link === ''): ?>
                <section class="gs-panel gs-partner__guest">
                    <p>Программа сейчас настраивается — загляните чуть позже.</p>
                </section>
            <?php else: ?>
                <section class="gs-panel gs-partner__link">
                    <label class="gs-label" for="gs-partner-link">Ваша ссылка</label>
                    <div class="gs-partner__row">
                        <input type="text" id="gs-partner-link" class="gs-input" readonly
                               value="<?php echo esc_attr($link); ?>">
                        <button type="button" class="gs-btn gs-btn--primary" id="gs-partner-copy">Скопировать</button>
                    </div>
                    <p class="gs-hint" id="gs-partner-note">Код приглашения: <?php echo esc_html($data['code']); ?></p>

                    <?php if (!empty($data['targets']) && count($data['targets']) > 1): ?>
                        <div class="gs-partner__targets">
                            <span class="gs-partner__share-label">Вести сразу на сервис:</span>
                            <?php foreach ($data['targets'] as $label => $url): ?>
                                <button type="button" class="gs-chip gs-partner__target"
                                        data-gs-target="<?php echo esc_attr($url); ?>">
                                    <?php echo esc_html($label); ?>
                                </button>
                            <?php endforeach; ?>
                        </div>
                        <p class="gs-hint">
                            Метка приглашения ловится на любой странице сайта, так что ссылку можно
                            дать на ту, про которую вы рассказываете. Считается она одинаково.
                        </p>
                    <?php endif; ?>

                    <div class="gs-partner__share">
                        <span class="gs-partner__share-label">Поделиться:</span>
                        <?php
                        $text = rawurlencode('Нейросети для звука, видео и картинок — пробный баланс при первом ключе');
                        $url  = rawurlencode($link);
                        $targets = array(
                            'Telegram' => 'https://t.me/share/url?url=' . $url . '&text=' . $text,
                            'ВКонтакте' => 'https://vk.com/share.php?url=' . $url . '&title=' . $text,
                            'WhatsApp' => 'https://api.whatsapp.com/send?text=' . $text . '%20' . $url,
                            'Одноклассники' => 'https://connect.ok.ru/offer?url=' . $url . '&title=' . $text,
                            'X' => 'https://twitter.com/intent/tweet?url=' . $url . '&text=' . $text,
                        );
                        foreach ($targets as $name => $href): ?>
                            <a class="gs-btn gs-btn--ghost gs-partner__share-btn"
                               href="<?php echo esc_url($href); ?>" target="_blank" rel="noopener nofollow">
                                <?php echo esc_html($name); ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </section>

                <section class="gs-partner__stats">
                    <div class="gs-partner__card">
                        <span class="gs-partner__num"><?php echo (int) $data['invited']; ?></span>
                        <span class="gs-partner__cap">приглашено</span>
                    </div>
                    <div class="gs-partner__card">
                        <span class="gs-partner__num"><?php echo esc_html(number_format_i18n((float) $data['earned'], 2)); ?> ₽</span>
                        <span class="gs-partner__cap">начислено всего</span>
                    </div>
                    <div class="gs-partner__card">
                        <span class="gs-partner__num"><?php echo (int) $rate; ?>%</span>
                        <span class="gs-partner__cap">с каждой их работы</span>
                    </div>
                </section>

                <?php if (!empty($data['rows'])): ?>
                    <section class="gs-partner__log">
                        <h2 class="gs-section-title">Последние начисления</h2>
                        <table class="gs-table">
                            <thead>
                                <tr><th>Когда</th><th>Кто</th><th>Потратил</th><th>Ваша доля</th></tr>
                            </thead>
                            <tbody>
                            <?php foreach ($data['rows'] as $row): ?>
                                <tr>
                                    <td><?php echo esc_html(mysql2date('d.m.Y', (string) ($row['created_at'] ?? ''))); ?></td>
                                    <td><?php echo esc_html((string) ($row['user_label'] ?? '')); ?></td>
                                    <td><?php echo esc_html(number_format_i18n((float) ($row['spent_amount'] ?? 0), 2)); ?> ₽</td>
                                    <td><?php echo esc_html(number_format_i18n((float) ($row['commission_amount'] ?? 0), 2)); ?> ₽</td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </section>
                <?php endif; ?>
            <?php endif; ?>

            <section class="gs-faq">
                <h2 class="gs-section-title">Как это работает</h2>
                <?php
                $faq = array(
                    array('За что именно платим?',
                          'За работу приглашённого с сервисами: озвучка, видео, музыка, картинки, дубляж — всё, что списывается с его баланса. ' . $rate . '% от потраченного приходит вам на баланс сразу.'),
                    array('А если у него операция не вышла?',
                          'Тогда деньги возвращаются ему, а доля с этой суммы не начисляется: считаем по чистым тратам, а не по количеству нажатий.'),
                    array('Сколько действует закрепление?',
                          'Бессрочно. Человек закрепляется за вами в момент, когда заводит аккаунт по вашей ссылке, и остаётся вашим дальше.'),
                    array('Что делать с начисленным?',
                          'Тратить на сервисы как обычный баланс — это те же рубли, что и пополнение.'),
                    array('Где брать людей?',
                          'Там, где вы и так есть: ролики на YouTube и в Shorts, посты и сторис, тематические чаты, описание канала, подпись в рассылке. Лучше всего заходит показ работы: сделали ролик с дубляжом — покажите до и после, а ссылку дайте рядом.'),
                );
                foreach ($faq as $pair): ?>
                    <details class="gs-faq__item">
                        <summary class="gs-faq__q"><?php echo esc_html($pair[0]); ?></summary>
                        <p class="gs-faq__a"><?php echo esc_html($pair[1]); ?></p>
                    </details>
                <?php endforeach; ?>
            </section>

            <?php echo GS_Support::render('Партнёрская программа'); // phpcs:ignore WordPress.Security.EscapeOutput ?>
        </div>
        <?php
        return ob_get_clean();
    }
}
