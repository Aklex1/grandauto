<?php
/**
 * Пакет генераций: что входит в тариф и что можно докупить.
 *
 * Генерации стоят денег у поставщика, и «безлимит» в курсе за 22 900 ₽
 * — это приглашение одному человеку со скриптом выбрать бюджет всей
 * группы. Поэтому в тариф входит понятный пакет, а когда он кончается,
 * ученик докупает следующий — тем же ЮMoney, что и сам курс.
 *
 * Считаем остатком, а не расходом: у человека на счету столько-то
 * кадров и столько-то роликов, задание списывает единицу, неудачное
 * возвращает. Так остаток не зависит от того, когда он что покупал, и
 * его видно одним числом — «осталось 540 кадров», а не «использовано
 * 60 из 600 за период с такого-то».
 *
 * Кадры и ролики врозь: ролик обходится вчетверо дороже, и в одном
 * числе пакет на два месяца уходил бы за вечер.
 */

if (!defined('ABSPATH')) {
    exit;
}

class GC_Pack {

    const OPT        = 'gc_packs';
    const META       = 'gc_quota';
    const META_UNTIL = 'gc_quota_until';
    const META_LOG   = 'gc_quota_log';

    /* ---------------------------------------------------------------------
     * Что входит в тарифы и что продаётся отдельно
     * ------------------------------------------------------------------ */

    /**
     * Что входит в тариф.
     *
     * Кадры считаются штуками, ролики — единицами: у роликов три
     * ступени, и дорогая стоит у поставщика вчетверо больше дешёвой.
     * Единица — это самый дешёвый ролик; обычный списывает две,
     * «максимум» — четыре.
     *
     * Размеры подобраны под правило «расходы на пакет не больше
     * трети цены тарифа» по худшему случаю — когда всё считается
     * самой дорогой ступенью. Кадр обходится примерно в два рубля,
     * единица ролика — примерно в тридцать один.
     */
    public static function defaults() {
        return array(
            'base' => array('images' => 600,  'videos' => 120, 'days' => 60),
            'plus' => array('images' => 1500, 'videos' => 180, 'days' => 120),
        );
    }

    public static function all() {
        $saved = get_option(self::OPT, array());
        $out = self::defaults();
        if (is_array($saved)) {
            foreach ($out as $plan => $pack) {
                foreach (array('images', 'videos', 'days') as $field) {
                    if (isset($saved[$plan][$field])) {
                        $out[$plan][$field] = max(0, (int) $saved[$plan][$field]);
                    }
                }
            }
        }
        return $out;
    }

    public static function save($values) {
        $packs = self::defaults();
        foreach ($packs as $plan => $pack) {
            foreach (array('images', 'videos', 'days') as $field) {
                if (isset($values[$plan][$field])) {
                    $packs[$plan][$field] = max(0, (int) $values[$plan][$field]);
                }
            }
        }
        update_option(self::OPT, $packs, false);
        return $packs;
    }

    public static function plan_pack($plan) {
        $all = self::all();
        $plan = (string) $plan;
        return isset($all[$plan]) ? $all[$plan] : null;
    }

    /**
     * Пакеты на докупку.
     *
     * Цены держатся примерно втрое выше себестоимости — обычная наценка
     * на перепродажу вычислений. Кадр обходится около двух рублей,
     * единица ролика — около тридцати одного, и ролики здесь считаются
     * теми же единицами, что и в тарифе.
     */
    public static function extras() {
        return array(
            'light' => array(
                'title'  => 'Небольшой',
                'price'  => 490,
                'images' => 40,
                'videos' => 2,
                'days'   => 60,
                'note'   => 'На один заказ или на вечер переделок.',
            ),
            'work' => array(
                'title'  => 'Рабочий',
                'price'  => 990,
                'images' => 80,
                'videos' => 6,
                'days'   => 90,
                'note'   => 'Комплекты карточек с запасом на отбраковку и три обычных ролика.',
            ),
            'pro' => array(
                'title'  => 'Поток',
                'price'  => 1990,
                'images' => 150,
                'videos' => 12,
                'days'   => 120,
                'note'   => 'Когда заказы идут каждую неделю и считать некогда.',
            ),
        );
    }

    /**
     * Чем единица отличается от ролика — одной строкой.
     *
     * Эта подпись идёт рядом с каждым числом: иначе «120 роликов» в
     * тарифе и «60 роликов» в работе ученика расходятся, и человек
     * считает себя обманутым.
     */
    public static function video_legend() {
        return 'Единица — это короткий ролик (8 секунд, Veo 3 Fast). '
             . 'Обычный ролик Seedance 2.0 на 5 секунд стоит две единицы, «максимум» — четыре.';
    }

    public static function extra($key) {
        $all = self::extras();
        return isset($all[(string) $key]) ? $all[(string) $key] : null;
    }

    /* ---------------------------------------------------------------------
     * Остаток на счету ученика
     * ------------------------------------------------------------------ */

    /** @return array{image:int,video:int} */
    public static function balance($user_id) {
        $raw = get_user_meta((int) $user_id, self::META, true);
        if (!is_array($raw)) {
            return array('image' => 0, 'video' => 0);
        }
        return array(
            'image' => max(0, (int) ($raw['image'] ?? 0)),
            'video' => max(0, (int) ($raw['video'] ?? 0)),
        );
    }

    private static function put_balance($user_id, $balance) {
        update_user_meta((int) $user_id, self::META, array(
            'image' => max(0, (int) $balance['image']),
            'video' => max(0, (int) $balance['video']),
        ));
    }

    public static function until($user_id) {
        return (int) get_user_meta((int) $user_id, self::META_UNTIL, true);
    }

    /** Начислить пакет: тарифный при оплате курса или докупленный. */
    public static function grant($user_id, $images, $videos, $days, $label = '') {
        $user_id = (int) $user_id;
        $balance = self::balance($user_id);
        $balance['image'] += max(0, (int) $images);
        $balance['video'] += max(0, (int) $videos);
        self::put_balance($user_id, $balance);

        if ((int) $days > 0) {
            // Срок продлевается от большего: докупка во время курса не
            // должна укорачивать уже выданный срок.
            $until = max(self::until($user_id), time()) + (int) $days * DAY_IN_SECONDS;
            update_user_meta($user_id, self::META_UNTIL, $until);
        }

        $log = get_user_meta($user_id, self::META_LOG, true);
        $log = is_array($log) ? $log : array();
        $log[] = array(
            'at'     => current_time('mysql'),
            'images' => (int) $images,
            'videos' => (int) $videos,
            'days'   => (int) $days,
            'label'  => sanitize_text_field($label),
        );
        update_user_meta($user_id, self::META_LOG, array_slice($log, -20));
        return self::balance($user_id);
    }

    public static function log($user_id) {
        $log = get_user_meta((int) $user_id, self::META_LOG, true);
        return is_array($log) ? array_reverse($log) : array();
    }

    /**
     * Начислить тарифный пакет при оплате курса.
     *
     * Повторное уведомление ЮMoney ничего не удваивает: тарифный пакет
     * начисляется один раз, по метке платежа.
     */
    public static function grant_plan($user_id, $plan, $label = '') {
        $pack = self::plan_pack($plan);
        if (!$pack) {
            return false;
        }
        foreach (self::log($user_id) as $row) {
            if ($label !== '' && (string) $row['label'] === $label) {
                return false;
            }
        }
        self::grant($user_id, $pack['images'], $pack['videos'], $pack['days'], $label !== '' ? $label : 'тариф ' . $plan);
        return true;
    }

    /**
     * Остаток и срок — для кабинета и админки.
     *
     * @return array|null null — пакета у человека нет
     */
    public static function for_user($user_id, $course = '') {
        $user_id = (int) $user_id;
        $course = $course !== '' ? $course : GC_Students::course($user_id);
        if (GC_Gpu::engine($user_id, $course) !== 'kie') {
            return null;
        }
        self::ensure($user_id, $course);

        $balance = self::balance($user_id);
        $until = self::until($user_id);
        $plan = GC_Students::paid_plan($user_id);
        $pack = self::plan_pack($plan);
        if (!$pack && $balance['image'] <= 0 && $balance['video'] <= 0) {
            return null;
        }
        return array(
            'plan'   => $plan,
            'title'  => (string) (GC_Courses::plan($course, $plan)['title'] ?? 'докупленный'),
            'images' => (int) ($pack['images'] ?? 0),
            'videos' => (int) ($pack['videos'] ?? 0),
            'left'   => $balance,
            'until'  => $until,
            'over'   => $until > 0 && $until < time(),
        );
    }

    /**
     * Завести счёт тому, кто оплатил курс до появления пакетов.
     *
     * Начисляем пакет тарифа и вычитаем уже посчитанное: иначе человек,
     * отучившийся месяц, получил бы полный пакет заново.
     */
    public static function ensure($user_id, $course = '') {
        $user_id = (int) $user_id;
        if (get_user_meta($user_id, self::META, true) !== '') {
            return;
        }
        $plan = GC_Students::paid_plan($user_id);
        $pack = self::plan_pack($plan);
        if (!$pack) {
            return;
        }
        $used = self::spent_before($user_id);
        $images = max(0, (int) $pack['images'] - (int) $used['image']);
        $videos = max(0, (int) $pack['videos'] - (int) $used['video']);
        self::put_balance($user_id, array('image' => $images, 'video' => $videos));
        $paid_at = GC_Students::paid_at($user_id);
        $from = $paid_at !== '' ? strtotime($paid_at) : time();
        update_user_meta($user_id, self::META_UNTIL, $from + (int) $pack['days'] * DAY_IN_SECONDS);
    }

    /** Сколько человек посчитал заданий с момента оплаты. */
    public static function spent_before($user_id) {
        global $wpdb;
        $table = GC_Install::jobs_table();
        $paid = GC_Students::paid_at($user_id);
        $since = $paid !== '' ? $paid : (string) get_user_meta((int) $user_id, GC_Students::META_STARTED, true);
        if ($since === '') {
            return array('image' => 0, 'video' => 0);
        }
        $rows = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT kind, COUNT(*) AS n FROM {$table}
             WHERE user_id = %d AND created_at >= %s AND status <> 'failed' GROUP BY kind",
            (int) $user_id, $since
        ), ARRAY_A);
        $out = array('image' => 0, 'video' => 0);
        foreach ($rows as $row) {
            $kind = (string) $row['kind'] === 'video' ? 'video' : 'image';
            $out[$kind] += (int) $row['n'];
        }
        return $out;
    }

    /* ---------------------------------------------------------------------
     * Списание
     * ------------------------------------------------------------------ */

    /**
     * Можно ли ставить задание.
     *
     * @return array{ok:bool,message:string}
     */
    public static function allows($user_id, $kind, $course = '', $cost = 1) {
        $pack = self::for_user($user_id, $course);
        if (!$pack || user_can((int) $user_id, 'manage_options')) {
            return array('ok' => true, 'message' => '');
        }
        if (!empty($pack['over'])) {
            return array('ok' => false, 'message' => sprintf(
                'Срок пакета закончился %s. Докупить новый можно прямо в мастерской.',
                date_i18n('j F', (int) $pack['until'])
            ));
        }
        $kind = $kind === 'video' ? 'video' : 'image';
        $cost = max(1, (int) $cost);
        $left = (int) $pack['left'][$kind];
        if ($left <= 0) {
            return array('ok' => false, 'message' => $kind === 'video'
                ? 'Ролики в пакете закончились. Докупить можно прямо в мастерской.'
                : 'Кадры в пакете закончились. Докупить можно прямо в мастерской.');
        }
        /*
         * Дорогая ступень ролика стоит нескольких единиц, и отказ должен
         * называть вещи своими именами: иначе человек видит «осталось 3»
         * и не понимает, почему не считается.
         */
        if ($left < $cost) {
            return array('ok' => false, 'message' => sprintf(
                'На эту ступень нужно %d единиц пакета, а осталось %d. Возьмите ступень попроще или докупите пакет.',
                $cost, $left
            ));
        }
        return array('ok' => true, 'message' => '');
    }

    /** Списать стоимость задания: оно ушло считаться. */
    public static function spend($user_id, $kind, $cost = 1) {
        if (user_can((int) $user_id, 'manage_options')) {
            return;
        }
        $kind = $kind === 'video' ? 'video' : 'image';
        $balance = self::balance($user_id);
        if ($balance[$kind] <= 0) {
            return;
        }
        $balance[$kind] = max(0, $balance[$kind] - max(1, (int) $cost));
        self::put_balance($user_id, $balance);
    }

    /** Вернуть списанное: задание не вышло не по вине ученика. */
    public static function refund($user_id, $kind, $cost = 1) {
        if (user_can((int) $user_id, 'manage_options')) {
            return;
        }
        $kind = $kind === 'video' ? 'video' : 'image';
        $balance = self::balance($user_id);
        $balance[$kind] += max(1, (int) $cost);
        self::put_balance($user_id, $balance);
    }

    /** Сколько работ можно сделать пакетом — для посадочной и кабинета. */
    public static function equivalents($plan = 'base') {
        $pack = self::plan_pack($plan);
        if (!$pack) {
            return array();
        }
        return array(
            array((string) (int) floor($pack['images'] / 5), 'комплектов карточек', 'по пять кадров на товар'),
            array((string) (int) floor($pack['videos'] / 2), 'рекламных роликов', 'по пять секунд, со звуком — или вдвое больше коротких'),
            array((string) (int) floor($pack['images'] / 3), 'обложек и баннеров', 'по три варианта на выбор заказчику'),
        );
    }
}
