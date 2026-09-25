<?php
/**
 * Какие голоса выбирают на самом деле — и как их послушать.
 *
 * В API голос задаётся идентификатором вида «EkK5I93UQWFDigLMpZcX», а взять
 * его было неоткуда: список голосов жил только внутри кабинета, в разметке
 * страницы. Разработчик, подключающий озвучку, либо оставлял голос по
 * умолчанию, либо угадывал.
 *
 * Здесь список выложен наружу и отсортирован не по алфавиту, а по тому, как
 * часто голос выбирают: это и есть честный рейтинг. Рядом — образец, чтобы
 * выбирать ухом, а не по названию вроде «Husky, Engaging and Bold».
 *
 * Сам каталог лежит файлом рядом: он приходит из плагина озвучки, и держать
 * его копию в коде — меньшее зло, чем читать чужую разметку на каждый показ.
 * Обновляется кнопкой в админке, когда в кабинете появятся новые голоса.
 */

if (!defined('ABSPATH')) {
    exit;
}

class GS_Voice_Rank {

    /** Где лежат образцы и сколько их имеет смысл делать. */
    const OPT_SAMPLES = 'gs_voice_samples';
    const OPT_STATS   = 'gs_voice_stats';
    const STATS_TTL   = 21600;
    const TOP         = 12;

    /** Фраза для образца: короткая, с числом и с шипящими — слышно дикцию. */
    const SAMPLE_TEXT = 'Здравствуйте! Заказ номер сорок семь уже собран и ждёт вас на пункте выдачи.';

    public static function boot() {
        add_action('admin_post_gs_voice_samples', array(__CLASS__, 'handle_samples'));
        add_action('admin_post_gs_voice_forget', array(__CLASS__, 'handle_forget'));
    }

    /** Забыть неудачи: поставщик мог починиться, и стоит попробовать снова. */
    public static function handle_forget() {
        if (!current_user_can('manage_options')) {
            wp_die('Недостаточно прав');
        }
        check_admin_referer('gs_voice_forget');
        delete_option(self::OPT_FAILED);
        delete_option(self::OPT_PENDING);
        set_transient('gs_voice_samples_notice', 'Список неудач очищен', 60);
        wp_safe_redirect(admin_url('admin.php?page=genius-sounds') . '#gs-voices');
        exit;
    }

    /* ---------------------------------------------------------------------
     * Каталог
     * ------------------------------------------------------------------ */

    /** @return array<int,array{id:string,name:string,category:string}> */
    public static function catalogue() {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $file = GS_PLUGIN_DIR . 'assets/data/voices.json';
        $cache = array();
        if (is_readable($file)) {
            $rows = json_decode((string) file_get_contents($file), true);
            if (is_array($rows)) {
                foreach ($rows as $row) {
                    if (!empty($row['id']) && !empty($row['name'])) {
                        $cache[] = array(
                            'id'       => (string) $row['id'],
                            'name'     => (string) $row['name'],
                            'category' => (string) ($row['category'] ?? ''),
                        );
                    }
                }
            }
        }
        return $cache;
    }

    /* ---------------------------------------------------------------------
     * Рейтинг
     * ------------------------------------------------------------------ */

    /**
     * Сколько раз какой голос заказывали.
     *
     * Считаем по журналу генераций — это единственный источник, где видно
     * настоящий выбор людей, а не наши догадки о том, что красивее звучит.
     * Результат кладём в кэш: запрос идёт по таблице на сотни тысяч строк,
     * а рейтинг за шесть часов не меняется.
     *
     * @return array<string,int> идентификатор голоса => число заказов
     */
    public static function usage() {
        $cached = get_transient(self::OPT_STATS);
        if (is_array($cached)) {
            return $cached;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'kie_tts_generations';
        $rows = $wpdb->get_results(
            "SELECT voice, COUNT(*) AS n
             FROM {$table}
             WHERE voice IS NOT NULL AND voice <> ''
             GROUP BY voice
             ORDER BY n DESC
             LIMIT 200",
            ARRAY_A
        );

        $out = array();
        if (is_array($rows)) {
            foreach ($rows as $row) {
                $voice = (string) $row['voice'];
                // В журнале одним полем лежат и голоса, и служебные метки
                // вроде «sfx:…» или «lab:…» — они к рейтингу отношения не имеют.
                if ($voice === '' || strpos($voice, ':') !== false) {
                    continue;
                }
                $out[$voice] = (int) $row['n'];
            }
        }
        set_transient(self::OPT_STATS, $out, self::STATS_TTL);
        return $out;
    }

    /**
     * Голоса по популярности, с образцами.
     *
     * Голоса, которые ещё никто не заказывал, из рейтинга не выбрасываем:
     * иначе новый голос никогда не получит первого слушателя.
     */
    public static function ranked($limit = 0) {
        $usage = self::usage();
        $samples = get_option(self::OPT_SAMPLES, array());
        if (!is_array($samples)) {
            $samples = array();
        }
        $total = array_sum($usage);

        $rows = array();
        foreach (self::catalogue() as $voice) {
            $count = isset($usage[$voice['id']]) ? (int) $usage[$voice['id']] : 0;
            $rows[] = array(
                'id'       => $voice['id'],
                'name'     => $voice['name'],
                'category' => $voice['category'],
                'count'    => $count,
                'share'    => $total > 0 ? round($count / $total * 100, 1) : 0.0,
                'sample'   => isset($samples[$voice['id']]) ? (string) $samples[$voice['id']] : '',
            );
        }
        usort($rows, function ($a, $b) {
            if ($a['count'] === $b['count']) {
                // При равном счёте вперёд идут те, кого можно послушать.
                $sa = $a['sample'] !== '' ? 1 : 0;
                $sb = $b['sample'] !== '' ? 1 : 0;
                return $sb - $sa;
            }
            return $b['count'] - $a['count'];
        });

        $limit = (int) $limit;
        return $limit > 0 ? array_slice($rows, 0, $limit) : $rows;
    }

    /* ---------------------------------------------------------------------
     * Образцы
     * ------------------------------------------------------------------ */

    /** Поставленные, но ещё не забранные образцы: голос => номер задачи. */
    const OPT_PENDING = 'gs_voice_samples_pending';

    /** Голоса, на которых образец не вышел: второй раз деньги не тратим. */
    const OPT_FAILED = 'gs_voice_samples_failed';

    /**
     * Сделать образцы для самых ходовых голосов.
     *
     * В два приёма: сначала ставим задачи, потом забираем готовые. В один
     * приём нельзя — дюжина голосов это несколько минут ожидания, а админка
     * оборвётся по таймауту на середине, и половина образцов потеряется
     * вместе с потраченными на них деньгами.
     *
     * Делаем не все шестьдесят семь: образец стоит денег, а слушают обычно
     * первую дюжину. Уже готовые не переделываем.
     */
    public static function handle_samples() {
        if (!current_user_can('manage_options')) {
            wp_die('Недостаточно прав');
        }
        check_admin_referer('gs_voice_samples');

        $samples = self::stored(self::OPT_SAMPLES);
        $pending = self::stored(self::OPT_PENDING);
        $failed  = self::stored(self::OPT_FAILED);

        // Шаг первый: забираем то, что уже готово с прошлого раза.
        $taken = 0;
        foreach ($pending as $voice_id => $task) {
            $state = GS_Api::state_from_generations((string) $task);
            if (!empty($state['files'][0]['url'])) {
                $samples[$voice_id] = (string) $state['files'][0]['url'];
                unset($pending[$voice_id]);
                $taken++;
            } elseif (($state['status'] ?? '') === 'failed') {
                // Запоминаем неудачу поимённо. Без этого каждое нажатие
                // ставило те же двенадцать задач заново — и каждый раз за
                // деньги, а образцов всё равно не появлялось.
                $failed[$voice_id] = current_time('mysql');
                unset($pending[$voice_id]);
            }
        }

        // Шаг второй: ставим задачи на тех, у кого образца ещё нет.
        //
        // Если движок лежит, ставим ровно одну задачу — пробную. Двенадцать
        // запросов в упавший сервис не приблизят результат, а список неудач
        // раздуют так, что потом непонятно, что пробовать заново.
        $limit = self::engine_down() ? 1 : self::TOP;
        $queued = 0;
        foreach (array_slice(self::ranked(self::TOP), 0, $limit) as $voice) {
            $id = $voice['id'];
            if (!empty($samples[$id]) || !empty($pending[$id]) || !empty($failed[$id])) {
                continue;
            }
            $task = self::queue_sample($id);
            if ($task !== '') {
                $pending[$id] = $task;
                $queued++;
            }
        }

        update_option(self::OPT_SAMPLES, $samples, false);
        update_option(self::OPT_PENDING, $pending, false);
        update_option(self::OPT_FAILED, $failed, false);

        $notice = sprintf(
            'Забрано готовых: %d, поставлено новых: %d, ждут очереди: %d, '
            . 'не вышло совсем: %d. Нажмите ещё раз через минуту, чтобы забрать '
            . 'поставленные. Неудачные повторно не ставятся — чтобы очистить '
            . 'список неудач, нажмите «Забыть неудачи».',
            $taken, $queued, count($pending), count($failed));
        if (self::engine_down()) {
            $notice .= ' Похоже, движок озвучки у поставщика не отвечает: '
                . 'все задачи возвращаются с отказом. Образцы появятся сами, '
                . 'когда он оживёт — ставим по одной пробной задаче за нажатие, '
                . 'чтобы не копить пустые неудачи.';
        }
        set_transient('gs_voice_samples_notice', $notice, 60);
        wp_safe_redirect(admin_url('admin.php?page=genius-sounds') . '#gs-voices');
        exit;
    }

    /**
     * Похоже ли, что движок озвучки лёг.
     *
     * Отличаем «образцы ещё не делали» от «образцы не выходят». Признак
     * простой: задачи ставились, все до одной провалились, и ни одного
     * готового образца нет. Так было 24 сентября, когда двенадцать голосов
     * подряд вернули у поставщика «Internal Error» — и молчащая страница
     * выглядела как наша недоделка, хотя дело было не в нас.
     */
    public static function engine_down() {
        $failed = count(self::stored(self::OPT_FAILED));
        $ready  = count(self::stored(self::OPT_SAMPLES));
        return $ready === 0 && $failed >= 3;
    }

    private static function stored($option) {
        $value = get_option($option, array());
        return is_array($value) ? $value : array();
    }

    /**
     * Поставить задачу на образец.
     *
     * Цену ставим нулевую: это наш служебный файл, а не заказ человека, и
     * списывать за него с чьего-то баланса не за что. Строка в журнале
     * генераций всё равно нужна — по ней плагин озвучки кладёт готовый файл.
     */
    private static function queue_sample($voice_id) {
        if (!class_exists('KIE_TTS_API')) {
            return '';
        }
        // Без адреса обратного вызова готовый файл некому забрать: плагин
        // озвучки дописывает строку истории именно из него. Пустая строка
        // оставляла задачи висеть, а потом помечала их неудачными.
        $callback = get_option('kie_tts_callback_url', rest_url('tts/v1/callback'));
        $created = KIE_TTS_API::create_tts_task(self::SAMPLE_TEXT, $voice_id, array(), $callback);
        if (!is_array($created) || (int) ($created['code'] ?? 0) !== 200) {
            return '';
        }
        $task = (string) ($created['data']['taskId'] ?? '');
        if ($task !== '' && class_exists('GS_Tts_Fallback')) {
            GS_Tts_Fallback::register_generation($task, 0, self::SAMPLE_TEXT, 0.0, $voice_id);
        }
        return $task;
    }

    /* ---------------------------------------------------------------------
     * Блок для страницы API
     * ------------------------------------------------------------------ */

    public static function render() {
        $rows = self::ranked();
        if (!$rows) {
            return '';
        }
        $heard = array_values(array_filter($rows, function ($row) {
            return $row['sample'] !== '';
        }));

        ob_start();
        ?>
        <div class="gs-api__section" id="voices">
            <h2 class="gs-section-title">Голоса: что выбирают и как это звучит</h2>
            <p class="gs-api__text">
                В запросе голос задаётся идентификатором в поле <code>voice</code>.
                Порядок в списке — по числу заказов: сверху то, что люди выбирают
                чаще всего. Голос можно не указывать вовсе — тогда возьмётся тот,
                что стоит по умолчанию.
            </p>

            <?php if (!$heard && self::engine_down()): ?>
                <p class="gs-api__text">
                    Образцы звучания временно недоступны: движок озвучки у поставщика
                    не принимает задачи. Список голосов и их идентификаторы работают
                    как обычно — образцы вернутся, когда поставщик починит сервис.
                </p>
            <?php endif; ?>

            <?php if ($heard): ?>
                <p class="gs-api__text">Послушать:</p>
                <div class="gs-voices">
                    <?php foreach (array_slice($heard, 0, self::TOP) as $row): ?>
                        <article class="gs-voices__item">
                            <h3 class="gs-voices__name"><?php echo esc_html($row['name']); ?></h3>
                            <p class="gs-voices__meta">
                                <?php echo esc_html($row['category']); ?>
                                <?php if ($row['count'] > 0): ?>
                                    · <?php echo esc_html(number_format_i18n($row['count'])); ?> заказов
                                <?php endif; ?>
                            </p>
                            <audio class="gs-voices__audio" controls preload="none"
                                   src="<?php echo esc_url($row['sample']); ?>"></audio>
                            <code class="gs-voices__id"><?php echo esc_html($row['id']); ?></code>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div class="gs-api__tablewrap">
                <table class="gs-api__table">
                    <thead>
                        <tr><th>Голос</th><th>Группа</th><th>Заказов</th><th>Идентификатор</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $row): ?>
                            <tr>
                                <td><?php echo esc_html($row['name']); ?></td>
                                <td><?php echo esc_html($row['category']); ?></td>
                                <td><?php echo $row['count'] > 0
                                    ? esc_html(number_format_i18n($row['count'])) : '—'; ?></td>
                                <td><code><?php echo esc_html($row['id']); ?></code></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
}
