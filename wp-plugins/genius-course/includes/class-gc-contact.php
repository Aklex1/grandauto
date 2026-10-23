<?php
/**
 * Письмо куратору и в поддержку из кабинета.
 *
 * Курс самостоятельный, но вопросы всё равно появляются — и чаще
 * всего в тот момент, когда человек сидит в уроке. Если в этот момент
 * написать некуда, он закрывает вкладку и не возвращается.
 *
 * Сообщение уходит туда же, куда заявки с сайта: в Telegram владельцу.
 * Отдельного канала не заводим — заявки и так приходят одним потоком,
 * и отвечать на них привычным способом проще, чем держать в голове
 * второй.
 *
 * Копию храним у себя: Telegram иногда не отвечает, а вопрос ученика
 * терять нельзя. Журнал виден в админке.
 */

if (!defined('ABSPATH')) {
    exit;
}

class GC_Contact {

    const OPT_LOG   = 'gc_messages';
    const META_TG   = 'gc_telegram';
    const LOG_LIMIT = 200;
    /** Чтобы один человек не завалил Telegram за минуту. */
    const PAUSE = 120;

    public static function kinds() {
        return array(
            'curator' => 'куратору',
            'support' => 'в поддержку',
        );
    }

    public static function kind_title($kind) {
        $all = self::kinds();
        return isset($all[(string) $kind]) ? $all[(string) $kind] : 'куратору';
    }

    /**
     * Принять сообщение ученика.
     *
     * @return array{ok:bool,message:string}
     */
    public static function send($user_id, $kind, $text, $telegram = '') {
        $user = get_user_by('id', (int) $user_id);
        if (!$user) {
            return array('ok' => false, 'message' => 'Не нашли вас в кабинете — перезайдите, пожалуйста.');
        }
        $text = trim(wp_strip_all_tags((string) $text));
        if (mb_strlen($text) < 5) {
            return array('ok' => false, 'message' => 'Напишите вопрос хотя бы одной фразой.');
        }
        if (mb_strlen($text) > 2000) {
            $text = mb_substr($text, 0, 2000);
        }
        $kind = isset(self::kinds()[$kind]) ? $kind : 'curator';

        $last = (int) get_user_meta((int) $user_id, 'gc_message_at', true);
        if ($last > 0 && time() - $last < self::PAUSE && !user_can((int) $user_id, 'manage_options')) {
            return array('ok' => false, 'message' => 'Сообщение уже ушло. Следующее можно отправить через пару минут.');
        }

        // Ник в Telegram запоминаем: в следующий раз он подставится сам.
        $telegram = trim(sanitize_text_field((string) $telegram));
        if ($telegram !== '') {
            $telegram = '@' . ltrim($telegram, '@');
            update_user_meta((int) $user_id, self::META_TG, $telegram);
        } else {
            $telegram = (string) get_user_meta((int) $user_id, self::META_TG, true);
        }

        $course = GC_Students::course($user_id);
        $entry = array(
            'at'       => current_time('mysql'),
            'user'     => (int) $user_id,
            'name'     => $user->display_name,
            'email'    => $user->user_email,
            'login'    => $user->user_login,
            'telegram' => $telegram,
            'course'   => $course,
            'kind'     => $kind,
            'text'     => $text,
            'sent'     => false,
        );

        $out = self::notify($entry);
        $entry['sent'] = !empty($out['ok']);
        $entry['error'] = $entry['sent'] ? '' : mb_substr((string) $out['message'], 0, 200);
        self::remember($entry);
        update_user_meta((int) $user_id, 'gc_message_at', time());

        // Даже если Telegram молчит, для ученика вопрос принят: он лежит
        // в журнале, и мы его не потеряем.
        return array('ok' => true, 'message' => $kind === 'support'
            ? 'Вопрос ушёл в поддержку. Ответим в течение дня — на почту или в Telegram.'
            : 'Вопрос ушёл куратору. Ответ придёт на почту или в Telegram.');
    }

    /**
     * Отправка владельцу.
     *
     * Пользуемся тем же путём, что и заявки с сайта: прямой вызов
     * Telegram, а если хостинг его обрывает — пересыльщик на сервере.
     * Ответ владелец шлёт привычным способом, пересылая сообщение в
     * бота.
     */
    private static function notify($entry) {
        if (!class_exists('GS_Leads')) {
            return array('ok' => false, 'message' => 'Плагин сайта выключен');
        }
        $who = $entry['name'] !== '' && $entry['name'] !== $entry['login']
            ? $entry['name'] . ' (' . $entry['login'] . ')'
            : $entry['login'];
        // Метка ученика: по ней видно, от кого вопрос, даже когда
        // сообщение переслали дальше и подпись потерялась.
        $who .= ' · #u' . (int) $entry['user'];
        $contact = $entry['email'];
        if ($entry['telegram'] !== '') {
            $contact .= ', ' . $entry['telegram'];
        }

        return GS_Leads::notify(array(
            'at'      => $entry['at'],
            'name'    => $who,
            'contact' => $contact,
            'comment' => $entry['text'],
            'source'  => 'кабинет курса «' . GC_Courses::title($entry['course']) . '», '
                       . self::kind_title($entry['kind']),
        ));
    }

    private static function remember($entry) {
        $log = get_option(self::OPT_LOG, array());
        $log = is_array($log) ? $log : array();
        array_unshift($log, $entry);
        update_option(self::OPT_LOG, array_slice($log, 0, self::LOG_LIMIT), false);
    }

    /** @return array<int, array> журнал, свежие сверху */
    public static function log($limit = 50) {
        $log = get_option(self::OPT_LOG, array());
        $log = is_array($log) ? $log : array();
        return array_slice($log, 0, max(1, (int) $limit));
    }

    public static function waiting() {
        $day = time() - DAY_IN_SECONDS;
        $n = 0;
        foreach (self::log(50) as $row) {
            if (strtotime((string) $row['at']) >= $day) {
                $n++;
            }
        }
        return $n;
    }

    public static function telegram_of($user_id) {
        return (string) get_user_meta((int) $user_id, self::META_TG, true);
    }
}
