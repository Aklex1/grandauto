<?php
/**
 * Разметка markdown в HTML.
 *
 * Модели просили отвечать готовым HTML, и это работало, пока у поставщика
 * был живой маршрут, отдающий ответ как есть. На оставшемся маршруте ответ
 * проходит через разбор, который выедает из текста угловые скобки: вместо
 * «<h2>Польза</h2><p>Текст</p>» до нас доходит «Польза</h2>Текст». Снаружи
 * это выглядит как модель, разучившаяся делать заголовки, — на самом деле
 * разметку теряют по дороге, и никакими указаниями в задании это не лечится.
 *
 * Поэтому модель просят о markdown: угловых скобок в нём нет, и он доходит
 * целым. HTML собираем сами — здесь, в одном месте для всех, кто получает
 * от модели текст: и для статей блога, и для документов.
 *
 * Строки, которые уже начинаются с тега, пропускаем как есть: ответ бывает
 * смешанным, и ломать готовую разметку ради единообразия незачем.
 */

if (!defined('ABSPATH')) {
    exit;
}

class GS_Md {

    /**
     * Текст с разметкой markdown → HTML.
     */
    public static function to_html($text) {
        $text = str_replace(array("\r\n", "\r"), "\n", (string) $text);

        // Иногда модель заворачивает весь ответ в одну ограду кода. Внутри
        // обычный текст, и разбирать его как код нельзя.
        if (preg_match('~^```[a-z]*[ \t]*\n(.*)\n```\s*$~us', trim($text), $m)
            && strpos($m[1], '```') === false) {
            $text = $m[1];
        }

        $out   = array();
        $para  = array();
        $rows  = array();
        $list  = '';
        $code  = null;

        foreach (explode("\n", $text) as $raw) {
            $line = trim($raw);

            // Внутри ограды кода разметки нет — собираем строки как есть.
            if ($code !== null) {
                if (strpos($line, '```') === 0) {
                    $out[] = '<pre><code>' . esc_html(rtrim(implode("\n", $code))) . '</code></pre>';
                    $code = null;
                } else {
                    $code[] = $raw;
                }
                continue;
            }
            if (strpos($line, '```') === 0) {
                self::flush($para, $rows, $list, $out);
                $code = array();
                continue;
            }

            if ($line === '') {
                self::flush($para, $rows, $list, $out);
                continue;
            }

            // Разделительная черта смысла не несёт.
            if (preg_match('~^([-*_])\1{2,}$~u', $line)) {
                self::flush($para, $rows, $list, $out);
                continue;
            }

            // Заголовки. Глубже третьего уровня в статье незачем.
            if (preg_match('~^(#{1,6})\s+(.+?)\s*#*$~u', $line, $m)) {
                self::flush($para, $rows, $list, $out);
                $tag = strlen($m[1]) <= 2 ? 'h2' : 'h3';
                $head = trim(self::inline($m[2]));
                // «## **Заголовок**» — выделение внутри заголовка лишнее.
                $head = preg_replace('~^<strong>(.*)</strong>$~us', '$1', $head);
                $out[] = '<' . $tag . '>' . $head . '</' . $tag . '>';
                continue;
            }

            // Строка таблицы.
            if (strpos($line, '|') === 0 && substr($line, -1) === '|') {
                self::flush_para($para, $out);
                self::flush_list($list, $out);
                $rows[] = $line;
                continue;
            }

            // Пункт списка: дефис, звёздочка или номер.
            if (preg_match('~^(?:([-*•])|(\d{1,3})[.)])\s+(.+)$~u', $line, $m)) {
                self::flush_para($para, $out);
                self::flush_table($rows, $out);
                $want = $m[1] !== '' ? 'ul' : 'ol';
                if ($list !== $want) {
                    self::flush_list($list, $out);
                    $out[] = '<' . $want . '>';
                    $list = $want;
                }
                $out[] = '<li>' . self::inline($m[3]) . '</li>';
                continue;
            }

            // Готовый тег — отдаём как есть.
            if (preg_match('~^</?[a-z][a-z0-9]*[\s/>]~i', $line)) {
                self::flush($para, $rows, $list, $out);
                $out[] = $raw;
                continue;
            }

            // Цитата смысла разметки здесь не несёт: это обычный абзац.
            $line = preg_replace('~^(?:&gt;|>)\s*~u', '', $line);
            if ($line === '') {
                continue;
            }
            self::flush_table($rows, $out);
            self::flush_list($list, $out);
            $para[] = $line;
        }

        if ($code !== null && $code) {
            $out[] = '<pre><code>' . esc_html(rtrim(implode("\n", $code))) . '</code></pre>';
        }
        self::flush($para, $rows, $list, $out);

        return trim(implode("\n", $out));
    }

    /**
     * Строчная разметка: код, ссылки, выделения.
     */
    public static function inline($text) {
        $text = (string) $text;

        // Код — первым: внутри него остальная разметка не действует.
        $text = preg_replace_callback('~`([^`\n]+)`~u', function ($m) {
            return '<code>' . esc_html($m[1]) . '</code>';
        }, $text);

        $text = preg_replace('~\[([^\]\n]+)\]\(\s*((?:https?://|/|mailto:)[^\s)]+)\s*\)~u',
            '<a href="$2">$1</a>', $text);
        $text = preg_replace('~\*\*([^*\n]+)\*\*~u', '<strong>$1</strong>', $text);
        $text = preg_replace('~(?<![\w_])__([^_\n]+)__(?![\w_])~u', '<strong>$1</strong>', $text);
        $text = preg_replace('~(?<![\w*])\*([^*\n]+)\*(?![\w*])~u', '<em>$1</em>', $text);

        return $text;
    }

    /* ------------------------------------------------------------------ */

    private static function flush(&$para, &$rows, &$list, &$out) {
        self::flush_para($para, $out);
        self::flush_table($rows, $out);
        self::flush_list($list, $out);
    }

    private static function flush_para(&$para, &$out) {
        if (!$para) {
            return;
        }
        $out[] = '<p>' . self::inline(implode(' ', $para)) . '</p>';
        $para = array();
    }

    private static function flush_list(&$list, &$out) {
        if ($list === '') {
            return;
        }
        $out[] = '</' . $list . '>';
        $list = '';
    }

    /**
     * Таблица markdown. Вторая строка из дефисов — разделитель шапки; без
     * неё таблицу собираем целиком в тело.
     */
    private static function flush_table(&$rows, &$out) {
        if (!$rows) {
            return;
        }
        $cells = array();
        foreach ($rows as $row) {
            $row = trim($row, "| \t");
            $cells[] = array_map('trim', preg_split('~\s*\|\s*~u', $row));
        }
        $rows = array();

        $head = array();
        if (count($cells) > 1 && preg_match('~^[\s:|-]+$~u', implode('|', $cells[1]))) {
            $head = array_shift($cells);
            array_shift($cells);
        }

        $html = '<table>';
        if ($head) {
            $html .= '<thead><tr>';
            foreach ($head as $cell) {
                $html .= '<th>' . self::inline($cell) . '</th>';
            }
            $html .= '</tr></thead>';
        }
        if ($cells) {
            $html .= '<tbody>';
            foreach ($cells as $row) {
                $html .= '<tr>';
                foreach ($row as $cell) {
                    $html .= '<td>' . self::inline($cell) . '</td>';
                }
                $html .= '</tr>';
            }
            $html .= '</tbody>';
        }
        $out[] = $html . '</table>';
    }
}
