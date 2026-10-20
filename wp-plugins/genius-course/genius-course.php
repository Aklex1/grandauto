<?php
/**
 * Plugin Name: Genius Course
 * Description: Личный кабинет обучения AI-креатора: доступы, модули, сдача работ и генерации на арендованном GPU.
 * Version:     1.8.3
 * Author:      Genius-bot
 * Text Domain: genius-course
 *
 * Почему отдельный плагин, а не раздел в genius-sounds.
 *
 * У кабинета своя жизнь: доступы выдаются поштучно и стоят денег, работы
 * учеников — персональные данные, а генерации идут на арендованной машине,
 * которую включают и выключают руками. Складывать это в плагин, который
 * обновляется по нескольку раз в день ради вёрстки лендинга, значит
 * однажды уронить обучение правкой отступа.
 */

if (!defined('ABSPATH')) {
    exit;
}

define('GC_VERSION', '1.8.3');
define('GC_FILE', __FILE__);
define('GC_PATH', plugin_dir_path(__FILE__));
define('GC_URL', plugin_dir_url(__FILE__));

foreach (array(
    'includes/class-gc-install.php',
    'includes/class-gc-program.php',
    'includes/class-gc-students.php',
    'includes/class-gc-works.php',
    'includes/class-gc-vast.php',
    'includes/class-gc-gpu.php',
    'includes/class-gc-cabinet.php',
    'includes/class-gc-rest.php',
    'includes/class-gc-admin.php',
) as $file) {
    require_once GC_PATH . $file;
}

register_activation_hook(__FILE__, array('GC_Install', 'activate'));

add_action('plugins_loaded', function () {
    GC_Install::maybe_upgrade();
    GC_Students::boot();
    GC_Cabinet::boot();
    GC_Rest::boot();
    GC_Gpu::boot();
    if (is_admin()) {
        GC_Admin::boot();
    }
});
