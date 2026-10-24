<?php
/**
 * Реклама РСЯ на страницах каталога звуков.
 *
 * Каталог — это трафик, который ничего не продаёт: человек пришёл
 * скачать звук, скачал и ушёл. Реклама здесь и есть способ окупить
 * хостинг и генерации, поэтому она ставится именно там, где ничего не
 * продаётся, и не ставится там, где человек оформляет заказ или учится:
 * на лендингах услуг, в кабинете и на оплате рекламный блок стоит
 * дороже, чем приносит.
 *
 * Номер блока — настройка, а не константа: блоки в РСЯ пересоздают,
 * и выкатывать ради этого плагин незачем. Пока номер не задан, на
 * страницы не выводится ничего — ни скрипта, ни пустого места.
 */

if (!defined('ABSPATH')) {
    exit;
}

class GS_Ads {

    /** Полноэкранный блок: у РСЯ он свой для телефона и для компьютера. */
    const OPT_TOUCH   = 'gs_ads_fullscreen_touch';
    const OPT_DESKTOP = 'gs_ads_fullscreen_desktop';
    const OPT_ON      = 'gs_ads_on';

    public static function boot() {
        add_action('wp_footer', array(__CLASS__, 'render'), 20);
        add_action('admin_post_gs_ads_save', array(__CLASS__, 'handle_save'));
    }

    public static function handle_save() {
        if (!current_user_can('manage_options')) {
            wp_die('Недостаточно прав');
        }
        check_admin_referer('gs_ads_save');
        self::save(array(
            'touch'   => isset($_POST['touch']) ? sanitize_text_field(wp_unslash($_POST['touch'])) : '',
            'desktop' => isset($_POST['desktop']) ? sanitize_text_field(wp_unslash($_POST['desktop'])) : '',
            'on'      => !empty($_POST['on']),
        ));
        wp_safe_redirect(admin_url('admin.php?page=genius-sounds') . '#gs-ads');
        exit;
    }

    public static function enabled() {
        return (int) get_option(self::OPT_ON, 1) === 1;
    }

    public static function block($platform = '') {
        $platform = $platform !== '' ? $platform : (wp_is_mobile() ? 'touch' : 'desktop');
        $option = $platform === 'touch' ? self::OPT_TOUCH : self::OPT_DESKTOP;
        $id = trim((string) get_option($option, ''));
        // Номер блока у РСЯ выглядит как R-A-123456-7: всё остальное —
        // чья-то опечатка, и лучше не выводить ничего, чем пустой блок.
        return preg_match('~^R-A-[0-9]+-[0-9]+$~', $id) ? $id : '';
    }

    /**
     * Где показываем.
     *
     * Каталог, разделы каталога и витрина сгенерированных звуков — это
     * и есть страницы скачивания. Вошедшему не показываем вовсе: это
     * ученики и администратор, их клики рекламной сети только вредят.
     */
    public static function allowed() {
        if (is_admin() || is_feed() || is_user_logged_in() || !self::enabled()) {
            return false;
        }
        if (class_exists('GS_Catalog') && GS_Catalog::is_catalog_request()) {
            return true;
        }
        if (class_exists('GS_Pages') && GS_Pages::is_showcase_request()) {
            return true;
        }
        return false;
    }

    public static function render() {
        if (!self::allowed()) {
            return;
        }
        $block = self::block();
        if ($block === '') {
            return;
        }
        $platform = wp_is_mobile() ? 'touch' : 'desktop';
        ?>
<script>window.yaContextCb = window.yaContextCb || [];</script>
<script src="https://yandex.ru/ads/system/context.js" async></script>
<script>
window.yaContextCb.push(function () {
    Ya.Context.AdvManager.render({
        blockId: <?php echo wp_json_encode($block); ?>,
        type: 'fullscreen',
        platform: <?php echo wp_json_encode($platform); ?>
    });
});
</script>
        <?php
    }

    /** Сохранение настроек из админки. */
    public static function save($values) {
        $clean = function ($raw) {
            $raw = trim((string) $raw);
            return preg_match('~^R-A-[0-9]+-[0-9]+$~', $raw) ? $raw : '';
        };
        update_option(self::OPT_TOUCH, $clean($values['touch'] ?? ''), false);
        update_option(self::OPT_DESKTOP, $clean($values['desktop'] ?? ''), false);
        update_option(self::OPT_ON, empty($values['on']) ? 0 : 1, false);
        return array(
            'touch'   => (string) get_option(self::OPT_TOUCH, ''),
            'desktop' => (string) get_option(self::OPT_DESKTOP, ''),
            'on'      => self::enabled(),
        );
    }
}
