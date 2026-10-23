<?php
/**
 * Пакет генераций в тарифе.
 *
 * Генерации стоят денег у поставщика, и «безлимит» в курсе за 22 900 ₽
 * — это приглашение одному человеку со скриптом выбрать бюджет всей
 * группы. Поэтому в тариф входит понятный пакет: столько-то кадров и
 * столько-то роликов на срок обучения.
 *
 * Считаем не по памяти, а по заданиям: сколько ученик уже посчитал на
 * шлюзе с начала пакета. Отдельно кадры, отдельно ролики — ролик
 * обходится вчетверо дороже кадра, и смешивать их в одно число значит
 * однажды обнаружить, что пакет вышел за вечер.
 *
 * Размеры пакета — настройка: цены у поставщика меняются без
 * предупреждения, и подгонять их выкаткой плагина неудобно.
 */

if (!defined('ABSPATH')) {
    exit;
}

class GC_Pack {

    const OPT = 'gc_packs';

    /** Пакеты по тарифам. Ключи совпадают с тарифами курса. */
    public static function defaults() {
        return array(
            'base' => array('images' => 600,  'videos' => 60,  'days' => 60),
            'plus' => array('images' => 1500, 'videos' => 150, 'days' => 120),
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
     * Пакет ученика: что входит, сколько потрачено, сколько осталось.
     *
     * Пакет есть только там, где генерации идут через шлюз и оплачены.
     * На курсе с арендованной машиной счёт другой — там платят за часы,
     * а не за штуки.
     *
     * @return array|null null — пакета нет (курс без шлюза или без оплаты)
     */
    public static function for_user($user_id, $course = '') {
        $user_id = (int) $user_id;
        $course = $course !== '' ? $course : GC_Students::course($user_id);
        if (GC_Gpu::engine($user_id, $course) !== 'kie') {
            return null;
        }
        $plan = GC_Students::paid_plan($user_id);
        $pack = self::plan_pack($plan);
        if (!$pack) {
            return null;
        }

        $since = self::started($user_id);
        $used = self::used($user_id, $since);
        $until = $pack['days'] > 0 ? strtotime($since) + $pack['days'] * DAY_IN_SECONDS : 0;

        return array(
            'plan'   => $plan,
            'title'  => (string) (GC_Courses::plan($course, $plan)['title'] ?? $plan),
            'images' => (int) $pack['images'],
            'videos' => (int) $pack['videos'],
            'days'   => (int) $pack['days'],
            'used'   => $used,
            'left'   => array(
                'image' => max(0, (int) $pack['images'] - (int) $used['image']),
                'video' => max(0, (int) $pack['videos'] - (int) $used['video']),
            ),
            'since'  => $since,
            'until'  => $until,
            'over'   => $until > 0 && $until < time(),
        );
    }

    /** С какого момента считаем пакет: с оплаты, иначе с выдачи доступа. */
    public static function started($user_id) {
        $paid = GC_Students::paid_at($user_id);
        if ($paid !== '') {
            return $paid;
        }
        $start = (string) get_user_meta((int) $user_id, GC_Students::META_STARTED, true);
        return $start !== '' ? $start : current_time('mysql');
    }

    /** @return array{image:int,video:int} */
    public static function used($user_id, $since = '') {
        global $wpdb;
        $table = GC_Install::jobs_table();
        $since = $since !== '' ? $since : self::started($user_id);
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

    /**
     * Можно ли ставить ещё одно задание.
     *
     * @return array{ok:bool,message:string}
     */
    public static function allows($user_id, $kind, $course = '') {
        $pack = self::for_user($user_id, $course);
        if (!$pack) {
            return array('ok' => true, 'message' => '');
        }
        if (user_can((int) $user_id, 'manage_options')) {
            return array('ok' => true, 'message' => '');
        }
        if (!empty($pack['over'])) {
            return array('ok' => false, 'message' => sprintf(
                'Срок пакета генераций закончился %s. Напишите нам — продлим.',
                date_i18n('j F', (int) $pack['until'])
            ));
        }
        $kind = $kind === 'video' ? 'video' : 'image';
        if ($pack['left'][$kind] <= 0) {
            return array('ok' => false, 'message' => $kind === 'video'
                ? sprintf('Ролики в пакете закончились: %d из %d. Напишите нам, если нужно больше.',
                    (int) $pack['used']['video'], (int) $pack['videos'])
                : sprintf('Кадры в пакете закончились: %d из %d. Напишите нам, если нужно больше.',
                    (int) $pack['used']['image'], (int) $pack['images']));
        }
        return array('ok' => true, 'message' => '');
    }

    /** Сколько работ можно сделать пакетом — для посадочной и кабинета. */
    public static function equivalents($plan = 'base') {
        $pack = self::plan_pack($plan);
        if (!$pack) {
            return array();
        }
        $sets = (int) floor($pack['images'] / 5);
        return array(
            array((string) $sets, 'комплектов карточек', 'по пять кадров на товар'),
            array((string) $pack['videos'], 'рекламных роликов', 'вертикальных, со звуком и субтитрами'),
            array((string) (int) floor($pack['images'] / 3), 'обложек и баннеров', 'по три варианта на выбор заказчику'),
        );
    }
}
