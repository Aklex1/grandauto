<?php
/**
 * Быстрый разбор сданной работы.
 *
 * Между «отправил работу» и «пришёл разбор» раньше была тишина — и
 * это главное место, где люди бросают курс. Теперь сразу после сдачи
 * приходит первый разбор по чек-листу модуля: он не заменяет человека,
 * а снимает очевидное — не влез в хронометраж, нет подписей к работам,
 * цены выдуманы. Человеческий разбор приходит следом и остаётся
 * главным.
 *
 * Разбор пишет та же модель, что отвечает на сайте. Считается он
 * полминуты, поэтому не в запросе ученика, а фоном: задание ставится в
 * расписание и подбирается в ближайшую минуту.
 */

if (!defined('ABSPATH')) {
    exit;
}

class GC_Review {

    const HOOK = 'gc_auto_review';

    public static function boot() {
        add_action(self::HOOK, array(__CLASS__, 'run'));
    }

    public static function available() {
        return class_exists('GS_Provider') && trim((string) get_option('kie_tts_api_key', '')) !== '';
    }

    /** Поставить разбор в очередь сразу после сдачи. */
    public static function queue($work_id) {
        if (!self::available()) {
            return;
        }
        wp_schedule_single_event(time() + 5, self::HOOK, array((int) $work_id));
    }

    /**
     * Догнать то, что не ушло по расписанию.
     *
     * Расписание WordPress срабатывает на чужих запросах: если ученик
     * сдал работу и закрыл вкладку, задание может пролежать. Проход
     * раз в минуту из GC_Gpu::tick() это подбирает.
     */
    public static function catch_up() {
        if (!self::available()) {
            return;
        }
        foreach (GC_Works::awaiting_auto(2) as $work) {
            self::run((int) $work['id']);
        }
    }

    public static function run($work_id) {
        $work = GC_Works::get($work_id);
        if (!$work || trim((string) $work['auto_feedback']) !== '') {
            return;
        }
        if (!self::available()) {
            return;
        }
        $course = GC_Students::course((int) $work['user_id']);
        $module = GC_Program::module((int) $work['module'], $course);
        if (!$module) {
            return;
        }

        $res = GS_Provider::chat(self::system(), self::prompt($work, $module), array('timeout' => 120));
        if (empty($res['ok']) || trim((string) $res['content']) === '') {
            return;
        }
        GC_Works::set_auto($work_id, (string) $res['content']);
    }

    private static function system() {
        return 'Ты проверяешь работы на практическом курсе по нейросетям. Пишешь по-русски, коротко и по делу, '
             . 'без похвалы ради похвалы и без воды. Ты видишь только текст ученика и ссылки — файлы не '
             . 'открываются, поэтому не делай вид, что смотрел их. Разбираешь по пунктам чек-листа: что видно '
             . 'из описания, что не сходится, о чём спросить. Если по тексту судить нельзя, так и пиши. '
             . 'Формат: ### Что видно, ### Что поправить (список), ### Вопрос к вам (одна строка). '
             . 'Не больше 250 слов. Без обращений «дорогой друг» и без восклицательных знаков.';
    }

    private static function prompt($work, $module) {
        $checks = !empty($module['check']) ? (array) $module['check'] : array();
        $lines = '';
        foreach ($checks as $i => $check) {
            $lines .= ($i + 1) . '. ' . $check . "\n";
        }
        if ($lines === '') {
            $lines = '1. работа отвечает заданию модуля' . "\n";
        }

        return sprintf(
            "Модуль: %s\nЗадание: %s\nЧто нужно сдать: %s\n\nЧек-лист проверки:\n%s\nЧто прислал ученик:\n%s\n\nСсылки:\n%s",
            (string) $module['title'],
            (string) $module['task'],
            (string) $module['deliver'],
            $lines,
            trim(wp_strip_all_tags((string) $work['body'])) !== ''
                ? wp_strip_all_tags((string) $work['body'])
                : '(описания нет)',
            trim((string) $work['links']) !== '' ? (string) $work['links'] : '(ссылок нет)'
        );
    }
}
