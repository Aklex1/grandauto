<?php
/**
 * Постоянные переезды внутри блога.
 *
 * Автопубликация однажды выпустила одну и ту же статью дважды: тот же
 * заголовок, тот же текст, разные адреса. Для поиска это две страницы,
 * конкурирующие между собой за один запрос, и обе проигрывают.
 *
 * Лечится не удалением: старый адрес уже в индексе и на него могут вести
 * ссылки. Отдаём с него 301 на статью, которую оставили, — вес переезжает
 * туда же. Заодно убираем переехавшую запись из карты сайта: приглашать
 * робота на страницу, которая только перенаправляет, незачем.
 *
 * Список ведём здесь: следующий дубль — это одна строка, а не ещё один
 * плагин переадресаций.
 */

if (!defined('ABSPATH')) {
    exit;
}

class GS_Redirects {

    /**
     * Что куда: идентификатор старой записи => идентификатор той, что осталась.
     *
     * 4926 и 6188 — «Как автоматизировать рутину с помощью нейросети»,
     * выпущенная дважды с разницей в десять дней. Оставили 6188: она
     * переписана в полноценную инструкцию по нашему API.
     */
    const MOVED = array(
        4926 => 6188,
    );

    public static function boot() {
        add_action('template_redirect', array(__CLASS__, 'maybe_move'), 1);
        add_filter('wp_sitemaps_posts_query_args', array(__CLASS__, 'hide_moved'), 10, 2);
    }

    public static function maybe_move() {
        if (is_admin() || !is_singular()) {
            return;
        }
        $id = (int) get_queried_object_id();
        if (!isset(self::MOVED[$id])) {
            return;
        }
        $target = get_permalink((int) self::MOVED[$id]);
        if (!$target) {
            return;
        }
        wp_safe_redirect($target, 301);
        exit;
    }

    public static function hide_moved($args, $post_type) {
        if ($post_type !== 'post') {
            return $args;
        }
        $exclude = isset($args['post__not_in']) ? (array) $args['post__not_in'] : array();
        $args['post__not_in'] = array_merge($exclude, array_keys(self::MOVED));
        return $args;
    }
}
