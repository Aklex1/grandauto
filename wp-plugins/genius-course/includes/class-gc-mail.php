<?php
/**
 * Письмо с доступом в кабинет.
 *
 * Пароль показывается один раз — в админке после создания учётки.
 * Переписывать его руками в мессенджер значит однажды отправить не
 * тому человеку или перепутать букву, поэтому письмо уходит само, на
 * ту почту, с которой учётку завели.
 *
 * Вёрстка лежит отдельным файлом в templates/: почтовую верстку правят
 * чаще, чем код, и держать её строками в PHP неудобно. Метки в двойных
 * фигурных скобках — тот же файл можно открыть в браузере, вписать
 * данные руками и отправить из своей почты.
 */

if (!defined('ABSPATH')) {
    exit;
}

class GC_Mail {

    const TEMPLATE = 'templates/welcome-email.html';

    public static function template() {
        $path = GC_PATH . self::TEMPLATE;
        return is_readable($path) ? (string) file_get_contents($path) : '';
    }

    /**
     * Готовое письмо для ученика.
     *
     * @param WP_User $user
     * @param string  $password пароль, который видно один раз
     * @param string  $course   ключ курса; пусто — курс ученика
     */
    public static function render($user, $password, $course = '') {
        $html = self::template();
        if ($html === '') {
            return '';
        }
        $course = $course !== '' ? $course : GC_Students::course($user->ID);
        $name = trim((string) ($user->first_name ?: $user->display_name));
        // Логин вместо имени в приветствии выглядит как письмо робота.
        if ($name === '' || $name === $user->user_login) {
            $name = '';
        }

        if ($name === '') {
            $html = str_replace('{{name}}, здравствуйте!', 'Здравствуйте!', $html);
        }

        $note = self::note($user, $course);
        if ($note === '') {
            // Пустой блок про оплату убираем целиком, вместе с подложкой.
            $html = preg_replace('~<!-- \{\{note\}\}.*?-->\s*<tr>.*?</tr>~s', '', $html, 1);
        }

        $map = array(
            '{{name}}'        => esc_html($name),
            '{{course}}'      => esc_html(GC_Courses::title($course)),
            '{{cabinet_url}}' => esc_url(GC_Cabinet::url($course)),
            '{{login}}'       => esc_html($user->user_login),
            '{{password}}'    => esc_html($password),
            '{{lost_url}}'    => esc_url(wp_lostpassword_url(GC_Cabinet::url($course))),
            '{{note}}'        => $note,
            '{{steps}}'       => self::steps($course),
            '{{support}}'     => self::support(),
            '{{site}}'        => esc_html(get_bloginfo('name')),
            '{{year}}'        => esc_html(date_i18n('Y')),
        );
        return strtr($html, $map);
    }

    /**
     * Отправить письмо.
     *
     * @param string $copy_to необязательная копия себе — проверить, как дошло
     * @return array{ok:bool,message:string}
     */
    public static function send($user, $password, $course = '', $copy_to = '') {
        if (!($user instanceof WP_User) || !is_email($user->user_email)) {
            return array('ok' => false, 'message' => 'У ученика нет почтового адреса');
        }
        $html = self::render($user, $password, $course);
        if ($html === '') {
            return array('ok' => false, 'message' => 'Шаблон письма не найден');
        }
        $course = $course !== '' ? $course : GC_Students::course($user->ID);
        $subject = sprintf('Доступ в кабинет курса «%s»', GC_Courses::title($course));
        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
            'Reply-To: ' . get_option('admin_email'),
        );

        $ok = wp_mail($user->user_email, $subject, $html, $headers);
        $copy = '';
        if (is_email($copy_to)) {
            // Копия отдельным письмом, а не в копию получателя: ученику
            // незачем видеть наши служебные адреса.
            $sent = wp_mail(sanitize_email($copy_to), $subject, $html, $headers);
            $copy = $sent ? '; копия ушла на ' . sanitize_email($copy_to)
                          : '; копию на ' . sanitize_email($copy_to) . ' отправить не вышло';
        }

        return array(
            'ok'      => (bool) $ok,
            'message' => ($ok ? 'Письмо с доступом отправлено на ' . $user->user_email
                              : 'Почта сайта не приняла письмо для ' . $user->user_email) . $copy,
        );
    }

    /**
     * Абзац про доступ.
     *
     * У платного курса он объясняет, что открыто сейчас и что откроется
     * после оплаты. У бесплатного — срок генераций, если он задан.
     */
    private static function note($user, $course) {
        if (GC_Courses::paywall($course)) {
            if (GC_Courses::is_paid($user->ID, $course)) {
                return 'Доступ открыт полностью: все модули, сдача работ на проверку и мастерская генераций.';
            }
            $free = GC_Courses::free_modules($course);
            return sprintf(
                'Сейчас открыт %s модуль. Остальные, сдача работ на проверку и мастерская генераций '
                . 'открываются сразу после оплаты — оплатить можно прямо в кабинете, картой любого банка.',
                $free === 1 ? 'первый' : 'вводный'
            );
        }
        $until = GC_Students::gpu_until($user->ID);
        if ($until !== '' && GC_Students::gpu_active($user->ID)) {
            return sprintf('Все модули открыты. Генерации в мастерской без лимита до %s.',
                date_i18n('j F', strtotime($until)));
        }
        return 'Все модули открыты сразу — учиться можно в своём темпе.';
    }

    /** Три шага, с которых начинают. Готовыми <li>: шаблон их только вставляет. */
    private static function steps($course) {
        if (GC_Courses::paywall($course)) {
            $steps = array(
                'Войдите в кабинет и откройте первый модуль — он открыт сразу.',
                'Посмотрите примеры прямо в уроке: кадры, ролик и тексты с разбором сделаны теми же моделями, с которыми вы будете работать.',
                'Решите, продолжать ли: оплата внутри кабинета, доступ ко всему курсу открывается сразу после платежа.',
            );
        } else {
            $steps = array(
                'Пройдите первый модуль целиком: он задаёт порядок всей дальнейшей работы.',
                'Сдайте первую работу на проверку — разбор придёт в кабинет.',
                'Загляните в мастерскую: кадры и ролики считаются на нашей стороне, количество не ограничено.',
            );
        }
        $out = '';
        foreach ($steps as $step) {
            $out .= '<li style="margin:0 0 6px 0;">' . esc_html($step) . '</li>';
        }
        return $out;
    }

    private static function support() {
        $mail = sanitize_email((string) get_option('admin_email'));
        $text = 'Не получается войти — просто ответьте на это письмо';
        return $mail !== ''
            ? $text . ' или напишите на ' . esc_html($mail) . '.'
            : $text . '.';
    }
}
