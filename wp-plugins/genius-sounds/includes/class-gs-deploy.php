<?php
/**
 * Обновление плагина без щели, в которой сайт лежит.
 *
 * Штатная установка из архива сначала удаляет папку плагина целиком и
 * только потом распаковывает новую. Между этими шагами проходит
 * несколько секунд, и запрос, попавший в них, падал насмерть: главный
 * файл уже есть, а includes/ ещё нет. Это и есть те письма «WordPress
 * нашёл ошибку с одним из плагинов», а в худшем случае WordPress
 * выключает плагин совсем. Проверено замером: при обычной выкладке один
 * запрос из сорока пяти отвечал пятисотой ошибкой.
 *
 * WordPress разрешает поменять решение о сносе папки — у него есть
 * фильтр над настройками установки. Снимаем удаление и запрет на
 * существующую папку: файлы лягут поверх прежних, по одному. В каждый
 * миг на диске целый плагин — где-то старый файл, где-то новый, но ни
 * одного отсутствующего.
 *
 * Снимаем только для своего архива: чужие обновления должны идти как
 * заведено. Узнаём его по содержимому — внутри лежит наш главный файл.
 *
 * Цена решения: файл, удалённый в новой версии, останется на сервере.
 * Для нас это безопасно — плагин подключает свои части поимённо, и
 * лишний файл просто лежит мёртвым грузом. Но при переименовании или
 * удалении класса остатки надо подчищать руками.
 */

if (!defined('ABSPATH')) {
    exit;
}

class GS_Deploy {

    /** Главный файл плагина внутри архива — по нему узнаём свой пакет. */
    const MARK = 'genius-sounds/genius-sounds.php';

    public static function boot() {
        add_filter('upgrader_package_options', array(__CLASS__, 'keep_folder'));
    }

    public static function keep_folder($options) {
        if (!is_array($options) || empty($options['clear_destination'])) {
            return $options;
        }
        $package = isset($options['package']) ? (string) $options['package'] : '';
        if ($package === '' || !self::ours($package)) {
            return $options;
        }
        $options['clear_destination'] = false;
        // Без этого WordPress откажется ставить в существующую папку.
        $options['abort_if_destination_exists'] = false;
        return $options;
    }

    /** Наш ли это архив. */
    private static function ours($package) {
        if (!class_exists('ZipArchive') || !is_readable($package)) {
            // Разобрать архив нечем — пусть идёт обычным путём: лучше
            // короткая щель, чем сорванное обновление.
            return false;
        }
        $zip = new ZipArchive();
        if ($zip->open($package) !== true) {
            return false;
        }
        $ours = $zip->locateName(self::MARK) !== false;
        $zip->close();
        return $ours;
    }
}
