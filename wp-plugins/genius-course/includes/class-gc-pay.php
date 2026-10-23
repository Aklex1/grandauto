<?php
/**
 * Оплата курса внутри кабинета.
 *
 * Ссылка на оплату собирается тем же построителем, что и остальные
 * платежи сайта (GS_Pay), а уведомление принимает один общий приёмник
 * ЮMoney в genius-sounds: кошелёк один, и адрес уведомления у него
 * тоже один. Приёмник узнаёт наш платёж по началу метки и зовёт
 * GC_Pay::paid() — больше связи между плагинами нет, поэтому курс
 * продолжает работать, даже если приёмник обновляют.
 *
 * В метке лежит номер ученика, курс и тариф: по ней открывается доступ
 * конкретному человеку, и подделать её нельзя — уведомление приходит с
 * подписью кошелька, которую проверяет приёмник.
 */

if (!defined('ABSPATH')) {
    exit;
}

class GC_Pay {

    /**
     * Начало метки.
     *
     * Коротко и латиницей: ЮMoney режет метку по длине, а приёмник
     * сверяет её именно началом, не словом внутри.
     */
    const LABEL_PREFIX = 'gckurs_';

    /** Начало метки докупленного пакета генераций. */
    const PACK_PREFIX = 'gcpack_';

    /** Метка платежа: gckurs_<ученик>_<курс>_<тариф>. */
    public static function label($user_id, $course, $plan) {
        return self::LABEL_PREFIX . (int) $user_id . '_' . sanitize_key($course) . '_' . sanitize_key($plan);
    }

    /**
     * Ссылка на оплату тарифа.
     *
     * @return string пусто, если тариф или кошелёк не настроены
     */
    public static function link($user_id, $course, $plan) {
        $def = GC_Courses::plan($course, $plan);
        if (!$def || !class_exists('GS_Pay')) {
            return '';
        }
        $target = sprintf('Обучение «%s», тариф «%s»', GC_Courses::title($course), (string) $def['title']);
        $success = add_query_arg('paid', '1', GC_Cabinet::url($course));
        return (string) GS_Pay::link(self::label($user_id, $course, $plan), (float) $def['price'], $target, $success);
    }

    /* ---------------------------------------------------------------------
     * Докупка генераций
     * ------------------------------------------------------------------ */

    /** Метка платежа за пакет: gcpack_<ученик>_<пакет>_<время>. */
    public static function pack_label($user_id, $key) {
        // Время в метке делает её разной у повторных покупок: иначе
        // второй пакет выглядел бы повтором первого и не зачислился.
        return self::PACK_PREFIX . (int) $user_id . '_' . sanitize_key($key) . '_' . time();
    }

    public static function pack_link($user_id, $key) {
        $pack = GC_Pack::extra($key);
        if (!$pack || !class_exists('GS_Pay')) {
            return '';
        }
        $course = GC_Students::course($user_id);
        $target = sprintf('Пакет генераций «%s»: %d кадров и %d единиц роликов',
            (string) $pack['title'], (int) $pack['images'], (int) $pack['videos']);
        $success = add_query_arg(array('tab' => 'studio', 'bought' => '1'), GC_Cabinet::url($course));
        return (string) GS_Pay::link(self::pack_label($user_id, $key), (float) $pack['price'], $target, $success);
    }

    /**
     * Уведомление об оплате пакета генераций.
     *
     * @return array{ok:bool,message:string}
     */
    public static function pack_paid($label) {
        $label = (string) $label;
        if (strpos($label, self::PACK_PREFIX) !== 0) {
            return array('ok' => false, 'message' => 'метка не от пакета генераций');
        }
        $parts = explode('_', substr($label, strlen(self::PACK_PREFIX)));
        if (count($parts) < 2) {
            return array('ok' => false, 'message' => 'в метке нет ученика или пакета');
        }
        $user_id = (int) $parts[0];
        $key = sanitize_key($parts[1]);
        $user = get_user_by('id', $user_id);
        $pack = GC_Pack::extra($key);
        if (!$user || !$pack) {
            return array('ok' => false, 'message' => 'ученик или пакет по метке не найден');
        }
        // Повторное уведомление по той же метке ничего не удваивает.
        foreach (GC_Pack::log($user_id) as $row) {
            if ((string) $row['label'] === $label) {
                return array('ok' => true, 'message' => 'пакет уже начислен — повторное уведомление');
            }
        }
        GC_Pack::grant($user_id, $pack['images'], $pack['videos'], $pack['days'], $label);
        $left = GC_Pack::balance($user_id);

        return array('ok' => true, 'message' => sprintf(
            'начислен пакет «%s»: +%d кадров, +%d единиц роликов (стало %d и %d), ученик %s',
            (string) $pack['title'], (int) $pack['images'], (int) $pack['videos'],
            (int) $left['image'], (int) $left['video'], $user->user_email
        ));
    }

    /**
     * Уведомление об оплате.
     *
     * Вызывается приёмником ЮMoney после проверки подписи. Ответ
     * возвращаем в том же виде, что и остальные обработчики платежей:
     * по нему приёмник пишет журнал.
     *
     * @return array{ok:bool,message:string}
     */
    public static function paid($label) {
        $label = (string) $label;
        if (strpos($label, self::LABEL_PREFIX) !== 0) {
            return array('ok' => false, 'message' => 'метка не от курса');
        }
        $parts = explode('_', substr($label, strlen(self::LABEL_PREFIX)));
        if (count($parts) < 3) {
            return array('ok' => false, 'message' => 'в метке нет ученика, курса или тарифа');
        }
        $user_id = (int) $parts[0];
        $course = sanitize_key($parts[1]);
        $plan = sanitize_key($parts[2]);

        $user = get_user_by('id', $user_id);
        if (!$user) {
            return array('ok' => false, 'message' => 'ученик по метке не найден: id ' . $user_id);
        }
        if (!GC_Courses::plan($course, $plan)) {
            return array('ok' => false, 'message' => 'тариф не найден: ' . $course . '/' . $plan);
        }

        /*
         * Курс в метке — главный. Доступ человеку выдали руками в
         * админке, но если курс там выбрали не тот, платёж всё равно
         * должен открыть то, за что заплатили: иначе человек заплатил
         * и остался без доступа, а разбираться будет поддержка.
         */
        if (GC_Students::course($user_id) !== $course) {
            GC_Students::set_course($user_id, $course);
        }

        $first = GC_Students::set_paid($user_id, $plan, $label);
        if (!$first) {
            return array('ok' => true, 'message' => 'доступ уже открыт — повторное уведомление');
        }
        // Вместе с доступом начисляем пакет генераций тарифа.
        GC_Pack::grant_plan($user_id, $plan, $label);
        self::notify_admin($user, $course, $plan);

        return array('ok' => true, 'message' => sprintf(
            'открыт полный доступ: %s, тариф «%s», ученик %s',
            GC_Courses::title($course),
            (string) GC_Courses::plan($course, $plan)['title'],
            $user->user_email
        ));
    }

    /**
     * Письмо о продаже.
     *
     * Деньги приходят ночью и в выходные, а тариф «с рекламной
     * кампанией» означает работу руками: настройку кампаний никто не
     * начнёт, пока не узнает о платеже. Письмо шлём тихо — не ушло, и
     * оплата всё равно уже отмечена в карточке ученика.
     */
    private static function notify_admin($user, $course, $plan) {
        $def = GC_Courses::plan($course, $plan);
        $subject = sprintf('Оплата курса: %s — %s', GC_Courses::title($course), (string) $def['title']);
        $body = sprintf(
            "Ученик: %s (%s)
Курс: %s
Тариф: %s, %s руб.

Доступ к модулям и сдаче работ открыт автоматически.
Карточка ученика: %s",
            $user->display_name,
            $user->user_email,
            GC_Courses::title($course),
            (string) $def['title'],
            number_format((float) $def['price'], 0, '.', ' '),
            admin_url('admin.php?page=genius-course&tab=' . ($course === GC_Courses::DEFAULT_KEY ? 'students' : $course))
        );
        wp_mail(get_option('admin_email'), $subject, $body);
    }
}
