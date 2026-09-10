<?php
/**
 * Файловое хранилище каталога: пути, URL, безопасная запись.
 * Всё лежит в uploads, чтобы переустановка плагина не сносила библиотеку звуков.
 */

if (!defined('ABSPATH')) {
    exit;
}

class GS_Storage {

    const BASE_DIR_NAME = 'genius-sounds';

    /**
     * Абсолютный путь к корню хранилища.
     */
    public static function base_dir() {
        $uploads = wp_get_upload_dir();
        return trailingslashit($uploads['basedir']) . self::BASE_DIR_NAME;
    }

    /**
     * Публичный URL корня хранилища.
     */
    public static function base_url() {
        $uploads = wp_get_upload_dir();
        return trailingslashit($uploads['baseurl']) . self::BASE_DIR_NAME;
    }

    public static function files_dir() {
        return self::base_dir() . '/files';
    }

    public static function files_url() {
        return self::base_url() . '/files';
    }

    public static function generated_dir() {
        return self::base_dir() . '/generated';
    }

    public static function generated_url() {
        return self::base_url() . '/generated';
    }

    public static function catalog_path() {
        return self::base_dir() . '/catalog.json';
    }

    public static function ensure_dirs() {
        foreach (array(self::base_dir(), self::files_dir(), self::generated_dir()) as $dir) {
            if (!is_dir($dir)) {
                wp_mkdir_p($dir);
            }
        }
    }

    /**
     * Атомарная запись файла: сначала во временный, потом rename.
     * Иначе параллельный веб-запрос может прочитать половину JSON.
     */
    public static function atomic_put($path, $contents) {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
        }
        $tmp = $path . '.tmp-' . wp_generate_password(8, false, false);
        if (file_put_contents($tmp, $contents) === false) {
            return false;
        }
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            return false;
        }
        return true;
    }

    /**
     * Слаг категории → безопасное имя папки.
     */
    public static function sanitize_slug($slug) {
        $slug = strtolower(trim((string) $slug));
        $slug = preg_replace('~[^a-z0-9_-]~', '', $slug);
        return (string) $slug;
    }

    /**
     * Имя файла из URL источника: только латиница, цифры, дефис.
     */
    public static function sanitize_filename($name) {
        $name = (string) $name;
        $ext = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($ext, array('mp3', 'wav', 'ogg', 'm4a', 'opus'), true)) {
            $ext = 'mp3';
        }
        $base = (string) pathinfo($name, PATHINFO_FILENAME);
        $base = strtolower($base);
        $base = preg_replace('~[^a-z0-9]+~', '-', $base);
        $base = trim((string) $base, '-');
        if ($base === '') {
            $base = 'sound-' . substr(md5($name), 0, 8);
        }
        if (strlen($base) > 80) {
            $base = substr($base, 0, 80);
            $base = rtrim($base, '-');
        }
        return $base . '.' . $ext;
    }

    /**
     * Человекочитаемый размер.
     */
    public static function format_size($bytes) {
        $bytes = (int) $bytes;
        if ($bytes <= 0) {
            return '';
        }
        if ($bytes < 1024 * 1024) {
            return round($bytes / 1024) . ' КБ';
        }
        return round($bytes / (1024 * 1024), 1) . ' МБ';
    }

    /**
     * Секунды → м:сс.
     */
    public static function format_duration($seconds) {
        $seconds = (int) round((float) $seconds);
        if ($seconds <= 0) {
            return '';
        }
        $m = (int) floor($seconds / 60);
        $s = $seconds % 60;
        return $m . ':' . str_pad((string) $s, 2, '0', STR_PAD_LEFT);
    }

    /**
     * Занятое хранилищем место (для админки).
     */
    public static function disk_usage() {
        $total = 0;
        $count = 0;
        $dir = self::files_dir();
        if (!is_dir($dir)) {
            return array('bytes' => 0, 'files' => 0);
        }
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file->isFile()) {
                $total += $file->getSize();
                $count++;
            }
        }
        return array('bytes' => $total, 'files' => $count);
    }
}
