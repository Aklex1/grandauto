<?php
/**
 * Фирменный стиль: логотип, иконки, персонаж Пиксель.
 *
 * Раньше оформление собиралось на каждой странице заново: где-то эмодзи,
 * где-то юникодные значки, где-то ничего. Здесь один набор на весь сайт —
 * микросервисы, посадочные и статьи берут иконки и персонажа отсюда, а не
 * заводят свои.
 *
 * Файлы лежат в плагине (assets/brand), поэтому переживают смену темы и
 * обновляются вместе с плагином.
 */

if (!defined('ABSPATH')) {
    exit;
}

class GS_Brand {

    /** Эмоции персонажа: подпись => файл. Список закрытый, чтобы в разметку
     *  не попал несуществующий файл и пустая картинка. */
    const POSES = array(
        'privet', 'stoit', 'dumaet', 'ideya', 'uspeh', 'oshibka',
        'ukazyvaet', 'rabotaet', 'zhdet', 'avatar', 'avatar-happy',
    );

    public static function boot() {
        add_action('wp_enqueue_scripts', array(__CLASS__, 'enqueue'), 5);
        add_action('wp_head', array(__CLASS__, 'head_icons'), 99);
    }

    public static function enqueue() {
        wp_enqueue_style('gs-brand', GS_PLUGIN_URL . 'assets/brand/css/brand.css', array(), GS_VERSION);
        // Набор рассчитан на светлые страницы, а сайт тёмный: переопределяем
        // поверхности, не трогая сам набор — его проще обновлять целиком.
        wp_enqueue_style('gs-brand-site', GS_PLUGIN_URL . 'assets/css/brand-site.css',
                         array('gs-brand'), GS_VERSION);
    }

    /**
     * Значки вкладки и превью ссылки.
     *
     * Ставим поздно и не трогаем то, что вывела тема: у браузера побеждает
     * последнее объявление, у соцсетей — первое совпадение og:image, которое
     * страница задаёт сама, если ей есть что показать.
     */
    public static function head_icons() {
        $base = GS_PLUGIN_URL . 'assets/brand/public/';
        echo "\n<!-- Genus Bot -->\n";
        echo '<link rel="icon" type="image/svg+xml" href="' . esc_url($base . 'favicon.svg') . '">' . "\n";
        echo '<link rel="icon" type="image/png" sizes="32x32" href="' . esc_url($base . 'favicon-32x32.png') . '">' . "\n";
        echo '<link rel="icon" type="image/png" sizes="16x16" href="' . esc_url($base . 'favicon-16x16.png') . '">' . "\n";
        echo '<link rel="apple-touch-icon" sizes="180x180" href="' . esc_url($base . 'apple-touch-icon.png') . '">' . "\n";
        echo '<link rel="mask-icon" href="' . esc_url($base . 'safari-pinned-tab.svg') . '" color="#22D3EE">' . "\n";
        echo '<meta name="theme-color" content="#0E0C1D">' . "\n";
    }

    /* ---------------------------------------------------------------------
     * Помощники разметки
     * ------------------------------------------------------------------ */

    public static function url($path) {
        return GS_PLUGIN_URL . 'assets/brand/' . ltrim((string) $path, '/');
    }

    /** Иконка: soft — с плашкой, для карточек; outline — в строку текста. */
    public static function icon($name, $style = 'soft') {
        $name = preg_replace('~[^a-z0-9-]~', '', strtolower((string) $name));
        $style = $style === 'outline' ? 'outline' : 'soft';
        return self::url('icons/' . $style . '/' . $name . '.svg');
    }

    public static function icon_tag($name, $style = 'soft', $class = 'gb-icon') {
        if ($name === '') {
            return '';
        }
        return '<img class="' . esc_attr($class) . '" src="' . esc_url(self::icon($name, $style))
            . '" alt="" width="48" height="48" loading="lazy" decoding="async">';
    }

    /** Персонаж. Неизвестная эмоция — молча ничего, а не битая картинка. */
    public static function mascot($pose = 'stoit') {
        $pose = (string) $pose;
        if (!in_array($pose, self::POSES, true)) {
            return '';
        }
        return self::url('mascot/pixel-' . $pose . '.svg');
    }

    public static function mascot_tag($pose = 'stoit', $class = 'gs-pixel', $width = 120) {
        $src = self::mascot($pose);
        if ($src === '') {
            return '';
        }
        return '<img class="' . esc_attr($class) . '" src="' . esc_url($src)
            . '" alt="" width="' . (int) $width . '" loading="lazy" decoding="async">';
    }

    public static function logo($variant = 'dark') {
        $file = $variant === 'light' ? 'genus-bot-logo.svg' : 'genus-bot-logo-dark.svg';
        return self::url('logo/' . $file);
    }
}
