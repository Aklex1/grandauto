<?php
/**
 * Карточка пользователя Телеграма в админке.
 *
 * Вопрос «а что с этим человеком» возникает каждый раз, когда кто-то
 * пишет «деньги списались, а видео нет» или «пополнил, баланс не вырос».
 * До сих пор ответ собирался руками: баланс — в базе бота, платежи —
 * там же, но в другой таблице, генерации — в третьей, а генерации с
 * сайта — вообще в базе сайта. Здесь всё четыре источника сведены на
 * одну страницу: вводишь chat id из Телеграма — видишь профиль, баланс,
 * пополнения и историю генераций.
 *
 * Только чтение: ничего не правит и не списывает. Для правки баланса
 * рядом есть своя форма с журналом и обязательной причиной.
 */

if (!defined('ABSPATH')) {
    exit;
}

class GS_User_Card {

    /** Сколько строк показываем: больше на экран всё равно не читается. */
    const LIMIT_PAY = 20;
    const LIMIT_GEN = 30;

    /** Соединение с базой бота — оно же источник баланса и платежей. */
    protected static function conn() {
        return class_exists('KIE_TTS_DB') ? KIE_TTS_DB::get_bot_connection() : null;
    }

    /** Все строки запроса разом: выборки здесь маленькие. */
    protected static function rows($conn, $sql) {
        $res = $conn->query($sql);
        if (!$res) {
            return array();
        }
        $out = array();
        while ($row = $res->fetch_assoc()) {
            $out[] = $row;
        }
        $res->free();
        return $out;
    }

    /**
     * Профиль человека по chat id или логину.
     *
     * Логин ищем и с собакой, и без: в переписке его присылают как
     * придётся, а искать по памяти цифры chat id — отдельное мучение.
     */
    protected static function find($conn, $raw) {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        if (ctype_digit($raw)) {
            $rows = self::rows($conn, 'SELECT * FROM users WHERE telegram_id = ' . (int) $raw . ' LIMIT 1');
            if ($rows) {
                return $rows[0];
            }
            // Чат-ид не нашёлся — вдруг это внутренний номер строки.
            $rows = self::rows($conn, 'SELECT * FROM users WHERE id = ' . (int) $raw . ' LIMIT 1');
            return $rows ? $rows[0] : null;
        }
        $login = $conn->real_escape_string(ltrim($raw, '@'));
        $rows = self::rows($conn, "SELECT * FROM users WHERE username = '" . $login . "' LIMIT 1");
        if ($rows) {
            return $rows[0];
        }
        $rows = self::rows($conn, "SELECT * FROM users WHERE username LIKE '%" . $login . "%' ORDER BY id DESC LIMIT 1");
        return $rows ? $rows[0] : null;
    }

    /** Пользователь сайта, привязанный к этому Телеграму. */
    protected static function site_user($telegram_id) {
        $found = get_users(array(
            'meta_key'   => 'telegram_id', // phpcs:ignore WordPress.DB.SlowDBQuery
            'meta_value' => (string) $telegram_id, // phpcs:ignore WordPress.DB.SlowDBQuery
            'number'     => 1,
            'fields'     => array('ID', 'user_login', 'user_email'),
        ));
        return $found ? $found[0] : null;
    }

    /** Генерации, сделанные на сайте под этой учётной записью. */
    protected static function site_jobs($user_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'kie_tts_generations';
        $rows = $wpdb->get_results($wpdb->prepare( // phpcs:ignore WordPress.DB
            "SELECT task_id, voice, status, cost, created_at
               FROM {$table}
              WHERE user_id = %d
           ORDER BY id DESC
              LIMIT %d",
            (int) $user_id,
            self::LIMIT_GEN
        ), ARRAY_A);
        return is_array($rows) ? $rows : array();
    }

    /** Денежная строка: суммы у нас всегда в рублях и всегда с копейками. */
    protected static function money($value) {
        return number_format_i18n((float) $value, 2) . ' ₽';
    }

    /** Цветная пометка состояния — глазами видно быстрее, чем читается. */
    protected static function mark($status) {
        $status = (string) $status;
        $color = $status === 'completed' ? '#0a7d26' : ($status === 'failed' ? '#b32d2e' : '#8a6d00');
        return '<span style="color:' . esc_attr($color) . '">' . esc_html($status) . '</span>';
    }

    public static function render() {
        $raw = isset($_GET['tg']) ? sanitize_text_field(wp_unslash($_GET['tg'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification

        ob_start();
        ?>
        <h3 id="gs-tguser">Карточка пользователя Телеграма</h3>
        <p class="description" style="max-width:900px">
            Chat id из Телеграма (или логин, с собакой или без) — и на одной странице
            профиль, текущий баланс, пополнения и история генераций: и те, что сделаны
            в боте, и те, что на сайте. Страница ничего не меняет, только показывает.
        </p>

        <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>"
              style="margin:0 0 14px;display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">
            <input type="hidden" name="page" value="genius-sounds">
            <label>Chat id или логин<br>
                <input type="text" name="tg" value="<?php echo esc_attr($raw); ?>"
                       placeholder="1227942719 или @julymurr" style="width:260px">
            </label>
            <?php submit_button('Показать', 'secondary', 'submit', false); ?>
        </form>
        <?php

        if ($raw === '') {
            return ob_get_clean();
        }

        $conn = self::conn();
        if (!$conn) {
            ?>
            <p class="notice notice-error inline" style="padding:10px;max-width:900px">
                Соединения с базой бота нет — ни баланса, ни платежей отсюда не видно.
                Доступ настраивается выше, в разделе «Доступ к базе бота».
            </p>
            <?php
            return ob_get_clean();
        }

        $person = self::find($conn, $raw);
        if (!$person) {
            $conn->close();
            ?>
            <p class="notice notice-warning inline" style="padding:10px;max-width:900px">
                По запросу «<?php echo esc_html($raw); ?>» в базе бота никого нет.
                Если человек писал боту, но его нет здесь — он не доходил до регистрации:
                запись заводится при первом «/start».
            </p>
            <?php
            return ob_get_clean();
        }

        $tg = (int) $person['telegram_id'];
        $pays = self::rows($conn, 'SELECT * FROM payments WHERE telegram_id = ' . $tg
            . ' ORDER BY id DESC LIMIT ' . (int) self::LIMIT_PAY);
        $pay_total = self::rows($conn, 'SELECT COUNT(*) AS n, SUM(amount) AS summa, SUM(tokens) AS nachisleno'
            . ' FROM payments WHERE telegram_id = ' . $tg . " AND status = 'completed'");
        $pay_total = $pay_total ? $pay_total[0] : array('n' => 0, 'summa' => 0, 'nachisleno' => 0);

        $jobs = self::rows($conn, 'SELECT created_at, request_type, model_name, cost, status, task_id, error_message'
            . ' FROM user_requests_log WHERE user_telegram_id = ' . $tg
            . ' ORDER BY id DESC LIMIT ' . (int) self::LIMIT_GEN);
        $job_total = self::rows($conn, "SELECT COUNT(*) AS n,"
            . " SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS ok,"
            . " SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS bad,"
            . " SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS hangs,"
            . " SUM(CASE WHEN status = 'completed' THEN cost ELSE 0 END) AS spisano,"
            . " SUM(CASE WHEN status <> 'completed' THEN cost ELSE 0 END) AS nedodelano,"
            . ' SUM(cost) AS vsego'
            . ' FROM user_requests_log WHERE user_telegram_id = ' . $tg);
        $job_total = $job_total ? $job_total[0]
            : array('n' => 0, 'ok' => 0, 'bad' => 0, 'hangs' => 0, 'spisano' => 0, 'nedodelano' => 0, 'vsego' => 0);

        /*
         * Платные папки каналов — вторая статья расходов.
         *
         * Она списывает с того же баланса, но в журнал генераций не
         * попадает, и без неё баланс «не сходился» на ровном месте.
         */
        $folders = self::rows($conn, 'SELECT s.subscription_date, s.payment_amount, f.name'
            . ' FROM user_folder_subscriptions s LEFT JOIN folders f ON f.id = s.folder_id'
            . ' WHERE s.user_id = ' . $tg . ' ORDER BY s.id DESC LIMIT ' . (int) self::LIMIT_PAY);
        $folders_sum = 0.0;
        foreach ($folders as $f) {
            $folders_sum += (float) ($f['payment_amount'] ?? 0);
        }

        $conn->close();

        $site = self::site_user($tg);
        $site_jobs = $site ? self::site_jobs($site->ID) : array();
        ?>

        <div class="gs-admin-cards" style="margin:0 0 14px">
            <div class="gs-admin-card">
                <span class="gs-admin-card__value"><?php echo esc_html(self::money($person['balance'] ?? 0)); ?></span>
                <span class="gs-admin-card__label">баланс сейчас</span>
            </div>
            <div class="gs-admin-card">
                <span class="gs-admin-card__value"><?php echo esc_html(self::money($pay_total['summa'] ?? 0)); ?></span>
                <span class="gs-admin-card__label">пополнено всего, <?php echo (int) ($pay_total['n'] ?? 0); ?> платежей</span>
            </div>
            <div class="gs-admin-card">
                <span class="gs-admin-card__value"><?php echo esc_html(self::money($job_total['spisano'] ?? 0)); ?></span>
                <span class="gs-admin-card__label">списано за генерации</span>
            </div>
            <div class="gs-admin-card">
                <span class="gs-admin-card__value"><?php echo (int) ($job_total['ok'] ?? 0); ?> / <?php echo (int) ($job_total['n'] ?? 0); ?></span>
                <span class="gs-admin-card__label">удачных генераций</span>
            </div>
        </div>

        <?php
        /*
         * Сходится ли баланс.
         *
         * Честное правило — платим только за удачное: у видео деньги
         * снимаются после успеха, и незавершённый заказ ничего не стоит.
         * Но часть услуг (кадры, расшифровка, папки) списывает вперёд, и
         * зависший заказ там оставляет деньги снятыми. Поэтому считаем
         * обе величины: сколько должно остаться по-честному и сколько
         * осталось на деле — разница и есть то, что человеку не вернули
         * (или, наоборот, начислили руками).
         */
        $balance = (float) ($person['balance'] ?? 0);
        $fair = (float) ($pay_total['nachisleno'] ?? 0)
            - (float) ($job_total['spisano'] ?? 0) - $folders_sum;
        $delta = $balance - $fair;
        ?>
        <p class="description" style="max-width:900px;margin:0 0 14px">
            Прошло через журнал <strong><?php echo esc_html(self::money($job_total['vsego'] ?? 0)); ?></strong>:
            за удачные <?php echo esc_html(self::money($job_total['spisano'] ?? 0)); ?>,
            за незавершённые и отказы <?php echo esc_html(self::money($job_total['nedodelano'] ?? 0)); ?>
            (<?php echo (int) ($job_total['hangs'] ?? 0); ?> не завершено,
            <?php echo (int) ($job_total['bad'] ?? 0); ?> отказов)<?php
            if ($folders_sum > 0): ?>, плюс папок на
            <?php echo esc_html(self::money($folders_sum)); ?><?php endif; ?>.
            Если платить только за удачное, остаться должно
            <strong><?php echo esc_html(self::money($fair)); ?></strong><?php
            if (abs($delta) >= 0.01): ?>, на деле
            <strong><?php echo esc_html(self::money($balance)); ?></strong> —
            <?php echo $delta < 0 ? 'не хватает ' : 'лишних '; ?>
            <?php echo esc_html(self::money(abs($delta))); ?>: столько осталось снятым за
            незавершённые заказы или добавлено вручную<?php else: ?> — так и есть<?php endif; ?>.
        </p>

        <table class="widefat striped" style="max-width:1000px;margin-bottom:18px">
            <tbody>
                <tr><th style="width:200px">Chat id</th>
                    <td><code><?php echo esc_html((string) $tg); ?></code></td></tr>
                <tr><th>Логин</th>
                    <td><?php echo $person['username']
                        ? '<a href="https://t.me/' . esc_attr($person['username']) . '" target="_blank" rel="noopener">@'
                            . esc_html($person['username']) . '</a>'
                        : '<em>не задан</em>'; // phpcs:ignore WordPress.Security.EscapeOutput ?></td></tr>
                <tr><th>Зарегистрирован</th>
                    <td><?php echo esc_html((string) ($person['registration_date'] ?? '')); ?></td></tr>
                <?php if (!empty($person['tag'])): ?>
                    <tr><th>Метка кампании</th><td><?php echo esc_html((string) $person['tag']); ?></td></tr>
                <?php endif; ?>
                <?php if (!empty($person['is_blocked'])): ?>
                    <tr><th>Блокировка</th><td style="color:#b32d2e">заблокирован</td></tr>
                <?php endif; ?>
                <?php if (!empty($person['vk_user_id'])): ?>
                    <tr><th>Профиль в приложении</th><td><?php echo esc_html((string) $person['vk_user_id']); ?></td></tr>
                <?php endif; ?>
                <tr><th>Учётная запись на сайте</th>
                    <td>
                        <?php if ($site): ?>
                            <a href="<?php echo esc_url(admin_url('user-edit.php?user_id=' . (int) $site->ID)); ?>">
                                <?php echo esc_html($site->user_login); ?></a>
                            <?php echo $site->user_email ? ' · ' . esc_html($site->user_email) : ''; ?>
                        <?php else: ?>
                            <em>не привязана — человек пользуется только ботом</em>
                        <?php endif; ?>
                    </td></tr>
            </tbody>
        </table>

        <h4>Пополнения</h4>
        <?php if (!$pays): ?>
            <p class="description">Платежей нет вовсе — баланс, если он есть, начислен вручную или подарком.</p>
        <?php else: ?>
            <table class="widefat striped" style="max-width:1000px;margin-bottom:18px">
                <thead><tr><th>Когда</th><th>Оплачено</th><th>Начислено</th><th>Статус</th><th>Метка</th><th>Кампания</th></tr></thead>
                <tbody>
                    <?php foreach ($pays as $p): ?>
                        <tr>
                            <td><?php echo esc_html((string) ($p['created_at'] ?? '')); ?></td>
                            <td><?php echo esc_html(self::money($p['amount'] ?? 0)); ?></td>
                            <td><?php echo esc_html(self::money($p['tokens'] ?? 0)); ?></td>
                            <td><?php echo self::mark($p['status'] ?? ''); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
                            <td><code style="font-size:11px"><?php echo esc_html((string) ($p['label'] ?? '')); ?></code></td>
                            <td><?php echo esc_html((string) ($p['campaign_tag'] ?? '')); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <p class="description" style="max-width:900px">
                Строка со статусом <code>pending</code> — это выставленный счёт, по которому
                деньги не дошли: ЮMoney не прислала уведомление или человек передумал.
                Зачисляется только <code>completed</code>.
            </p>
        <?php endif; ?>

        <?php if ($folders): ?>
            <h4>Покупки папок</h4>
            <table class="widefat striped" style="max-width:800px;margin-bottom:18px">
                <thead><tr><th>Когда</th><th>Папка</th><th>Списано</th></tr></thead>
                <tbody>
                    <?php foreach ($folders as $f): ?>
                        <tr>
                            <td><?php echo esc_html((string) ($f['subscription_date'] ?? '')); ?></td>
                            <td><?php echo esc_html((string) ($f['name'] ?? '—')); ?></td>
                            <td><?php echo esc_html(self::money($f['payment_amount'] ?? 0)); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <h4>Генерации в боте</h4>
        <?php if (!$jobs): ?>
            <p class="description">Ни одной генерации через бота.</p>
        <?php else: ?>
            <table class="widefat striped" style="max-width:1100px;margin-bottom:18px">
                <thead><tr><th>Когда</th><th>Что</th><th>Списано</th><th>Статус</th><th>Задача</th><th>Отказ</th></tr></thead>
                <tbody>
                    <?php foreach ($jobs as $j): ?>
                        <tr>
                            <td><?php echo esc_html((string) ($j['created_at'] ?? '')); ?></td>
                            <td><?php echo esc_html(trim((string) ($j['request_type'] ?? '') . ' / ' . (string) ($j['model_name'] ?? ''), ' /')); ?></td>
                            <td><?php echo esc_html(self::money($j['cost'] ?? 0)); ?></td>
                            <td><?php echo self::mark($j['status'] ?? ''); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
                            <td><code style="font-size:11px"><?php echo esc_html((string) ($j['task_id'] ?? '')); ?></code></td>
                            <td style="max-width:320px"><?php echo esc_html((string) ($j['error_message'] ?? '')); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <?php if ($site): ?>
            <h4>Генерации на сайте</h4>
            <?php if (!$site_jobs): ?>
                <p class="description">На сайте этот человек ничего не генерировал.</p>
            <?php else: ?>
                <table class="widefat striped" style="max-width:1000px;margin-bottom:18px">
                    <thead><tr><th>Когда</th><th>Что</th><th>Списано</th><th>Статус</th><th>Задача</th></tr></thead>
                    <tbody>
                        <?php foreach ($site_jobs as $j): ?>
                            <tr>
                                <td><?php echo esc_html((string) ($j['created_at'] ?? '')); ?></td>
                                <td><?php echo esc_html((string) ($j['voice'] ?? '')); ?></td>
                                <td><?php echo esc_html(self::money($j['cost'] ?? 0)); ?></td>
                                <td><?php echo self::mark($j['status'] ?? ''); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
                                <td><code style="font-size:11px"><?php echo esc_html((string) ($j['task_id'] ?? '')); ?></code></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        <?php endif; ?>
        <?php
        return ob_get_clean();
    }
}
