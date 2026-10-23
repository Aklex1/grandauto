<?php
/**
 * Мини-проверка в конце модуля.
 *
 * Три вопроса с объяснением ответа — не экзамен, а способ закрепить
 * прочитанное и увидеть движение. Результат хранится в метке
 * пользователя: своей таблицы ради трёх чисел на модуль заводить
 * незачем, а в админке он стоит рядом с посещениями и показывает,
 * читают модули или пролистывают.
 *
 * Правильные ответы живут в программе и в браузер не уезжают: проверка
 * идёт на сервере, иначе ответ виден в коде страницы.
 */

if (!defined('ABSPATH')) {
    exit;
}

class GC_Quiz {

    const META = 'gc_quiz';

    /** Вопросы модуля без правильных ответов — то, что можно отдать в браузер. */
    public static function questions($course, $module) {
        $m = GC_Program::module($module, $course);
        if (!$m || empty($m['quiz'])) {
            return array();
        }
        $out = array();
        foreach ((array) $m['quiz'] as $i => $row) {
            $out[] = array(
                'n'       => $i,
                'text'    => (string) $row[0],
                'options' => array_values((array) $row[1]),
            );
        }
        return $out;
    }

    public static function count($course, $module) {
        return count(self::questions($course, $module));
    }

    /**
     * Проверить ответы.
     *
     * @param array $answers номера выбранных вариантов по порядку вопросов
     * @return array{score:int,total:int,rows:array}
     */
    public static function check($course, $module, $answers) {
        $m = GC_Program::module($module, $course);
        $quiz = ($m && !empty($m['quiz'])) ? (array) $m['quiz'] : array();
        $rows = array();
        $score = 0;
        foreach ($quiz as $i => $row) {
            $right = (int) $row[2];
            $given = isset($answers[$i]) ? (int) $answers[$i] : -1;
            $ok = $given === $right;
            $score += $ok ? 1 : 0;
            $rows[] = array(
                'n'     => $i,
                'ok'    => $ok,
                'right' => $right,
                'why'   => isset($row[3]) ? (string) $row[3] : '',
            );
        }
        return array('score' => $score, 'total' => count($quiz), 'rows' => $rows);
    }

    /** Сохранить результат. Лучший результат не затираем худшим. */
    public static function save($user_id, $course, $module, $score, $total) {
        $all = get_user_meta((int) $user_id, self::META, true);
        $all = is_array($all) ? $all : array();
        $was = isset($all[$course][(int) $module]['score']) ? (int) $all[$course][(int) $module]['score'] : -1;
        if ((int) $score < $was) {
            return $all;
        }
        $all[$course][(int) $module] = array(
            'score' => (int) $score,
            'total' => (int) $total,
            'at'    => current_time('mysql'),
        );
        update_user_meta((int) $user_id, self::META, $all);
        return $all;
    }

    public static function result($user_id, $course, $module) {
        $all = get_user_meta((int) $user_id, self::META, true);
        return is_array($all) && isset($all[$course][(int) $module])
            ? (array) $all[$course][(int) $module] : array();
    }

    /**
     * Сводка по курсу для админки.
     *
     * @return array{done:int,modules:int,score:int,total:int}
     */
    public static function summary($user_id, $course) {
        $all = get_user_meta((int) $user_id, self::META, true);
        $rows = is_array($all) && isset($all[$course]) ? (array) $all[$course] : array();
        $score = 0;
        $total = 0;
        foreach ($rows as $row) {
            $score += (int) ($row['score'] ?? 0);
            $total += (int) ($row['total'] ?? 0);
        }
        return array(
            'done'    => count($rows),
            'modules' => (int) GC_Program::count($course),
            'score'   => $score,
            'total'   => $total,
        );
    }
}
