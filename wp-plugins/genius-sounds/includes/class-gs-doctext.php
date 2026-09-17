<?php
/**
 * Текст из загруженного файла.
 *
 * Поддерживаем то, в чём люди реально приносят материал: обычный txt и
 * docx. Старый бинарный .doc не берём сознательно — разбирать его без
 * внешних библиотек ненадёжно, и лучше честно попросить пересохранить,
 * чем отдать человеку кашу вместо презентации.
 */

if (!defined('ABSPATH')) {
    exit;
}

class GS_Doctext {

    /** Больше этого в презентацию всё равно не поместится. */
    const MAX_CHARS = 60000;
    const MAX_BYTES = 5242880; // 5 МБ

    public static function accept() {
        return '.txt,.docx,.md,.rtf,text/plain,application/vnd.openxmlformats-officedocument.wordprocessingml.document';
    }

    /**
     * @return array{ok:bool,message:string,text:string}
     */
    public static function extract($path, $name) {
        if (!is_readable($path)) {
            return self::fail('Файл не удалось прочитать');
        }
        if (filesize($path) > self::MAX_BYTES) {
            return self::fail('Файл больше 5 МБ — пришлите текст покороче');
        }

        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        switch ($ext) {
            case 'docx':
                $text = self::from_docx($path);
                break;
            case 'doc':
                return self::fail('Формат .doc не поддерживается. Откройте файл в Word и сохраните как .docx или .txt');
            case 'rtf':
                $text = self::from_rtf((string) file_get_contents($path));
                break;
            case 'txt':
            case 'md':
            case '':
                $text = self::from_plain((string) file_get_contents($path));
                break;
            default:
                return self::fail('Поддерживаются файлы .txt, .md, .rtf и .docx');
        }

        $text = self::tidy($text);
        if (mb_strlen($text) < 200) {
            return self::fail('В файле слишком мало текста — нужно хотя бы несколько абзацев');
        }
        return array('ok' => true, 'message' => '', 'text' => mb_substr($text, 0, self::MAX_CHARS));
    }

    private static function fail($message) {
        return array('ok' => false, 'message' => $message, 'text' => '');
    }

    /* ---------------------------------------------------------------------
     * Форматы
     * ------------------------------------------------------------------ */

    /**
     * docx — это zip, текст лежит в word/document.xml.
     *
     * Абзацы (w:p) переводим в переводы строки, а разрывы (w:br) — в пробелы:
     * без этого весь документ слипается в одну строку, и модель теряет
     * структуру исходника вместе с ней.
     */
    private static function from_docx($path) {
        if (!class_exists('ZipArchive')) {
            return '';
        }
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            return '';
        }
        $xml = (string) $zip->getFromName('word/document.xml');
        $zip->close();
        if ($xml === '') {
            return '';
        }

        $xml = preg_replace('~<w:p\b[^>]*/>~u', "\n", $xml);
        $xml = str_replace(array('</w:p>', '<w:br/>', '<w:br />', '<w:tab/>'),
                           array("\n", ' ', ' ', ' '), $xml);
        $text = strip_tags($xml);
        return html_entity_decode($text, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    /** rtf разбираем грубо: снимаем управляющие последовательности. */
    private static function from_rtf($raw) {
        $raw = preg_replace('~\{\\\\\*?[^{}]*\}~u', ' ', $raw);
        $raw = preg_replace('~\\\\par[d]?~u', "\n", $raw);
        $raw = preg_replace("~\\\\'([0-9a-f]{2})~ui", ' ', $raw);
        $raw = preg_replace('~\\\\[a-z]+-?\d* ?~ui', ' ', $raw);
        return str_replace(array('{', '}'), ' ', $raw);
    }

    /**
     * Обычный текст. Кодировку определяем сами: файлы из Windows часто
     * приходят в CP1251, и без перекодировки получится набор вопросов.
     */
    private static function from_plain($raw) {
        $raw = preg_replace('~^\xEF\xBB\xBF~', '', $raw);
        if (!mb_check_encoding($raw, 'UTF-8')) {
            $guess = mb_detect_encoding($raw, array('UTF-8', 'Windows-1251', 'KOI8-R', 'ISO-8859-5'), true);
            $raw = mb_convert_encoding($raw, 'UTF-8', $guess ?: 'Windows-1251');
        }
        return $raw;
    }

    private static function tidy($text) {
        $text = str_replace(array("\r\n", "\r"), "\n", (string) $text);
        $text = preg_replace('~[ \t\x{00A0}]+~u', ' ', $text);
        $text = preg_replace('~\n{3,}~u', "\n\n", $text);
        // Управляющие символы в текст презентации попадать не должны.
        $text = preg_replace('~[\x00-\x08\x0B\x0C\x0E-\x1F]~u', '', $text);
        return trim((string) $text);
    }
}
