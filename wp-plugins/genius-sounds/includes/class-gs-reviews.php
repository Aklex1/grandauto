<?php
/**
 * Отзывы о сервисах — настоящие.
 *
 * По коммерческим запросам поиск сравнивает не только страницы, но и
 * признаки живого дела: цены, условия, контакты и отзывы. Отзывов на
 * сайте нет ни одного, и это одна из самых дешёвых по труду потерь.
 *
 * Здесь — хранение и показ. Содержимое вносит владелец сайта через
 * админку: придуманные отзывы и разметка к ним — обман и покупателя, и
 * поисковика, поэтому ни одной строки «для примера» тут нет и быть не
 * должно. Пока отзывов нет, блок не выводится, а разметка не печатается.
 */

if (!defined('ABSPATH')) {
    exit;
}

class GS_Reviews {

    const OPT = 'gs_reviews';

    public static function boot() {
        add_action('admin_post_gs_review_add', array(__CLASS__, 'handle_add'));
        add_action('admin_post_gs_review_del', array(__CLASS__, 'handle_del'));
    }

    /** @return array<int,array> */
    public static function all() {
        $rows = get_option(self::OPT);
        return is_array($rows) ? array_values($rows) : array();
    }

    /**
     * Отзывы к сервису: сначала написанные про него, потом общие о сайте.
     * Общий отзыв к странице сервиса тоже относится — он про ту же работу.
     */
    public static function for_service($service_id) {
        $own = array();
        $common = array();
        foreach (self::all() as $row) {
            if ((string) ($row['service'] ?? '') === (string) $service_id) {
                $own[] = $row;
            } elseif ((string) ($row['service'] ?? '') === '') {
                $common[] = $row;
            }
        }
        return array_merge($own, $common);
    }

    /** Средняя оценка и число отзывов. */
    public static function aggregate($rows) {
        $rows = array_values(array_filter((array) $rows, function ($row) {
            return (float) ($row['rating'] ?? 0) > 0;
        }));
        if (!$rows) {
            return null;
        }
        $sum = 0.0;
        foreach ($rows as $row) {
            $sum += (float) $row['rating'];
        }
        return array('value' => round($sum / count($rows), 1), 'count' => count($rows));
    }

    public static function add($row) {
        $rows = self::all();
        $rows[] = array(
            'id'      => 'r' . wp_generate_password(8, false, false),
            'at'      => current_time('mysql'),
            'service' => sanitize_key((string) ($row['service'] ?? '')),
            'name'    => sanitize_text_field((string) ($row['name'] ?? '')),
            'rating'  => max(1, min(5, (int) ($row['rating'] ?? 5))),
            'text'    => sanitize_textarea_field((string) ($row['text'] ?? '')),
            'source'  => esc_url_raw((string) ($row['source'] ?? '')),
            'date'    => sanitize_text_field((string) ($row['date'] ?? current_time('Y-m-d'))),
        );
        update_option(self::OPT, $rows, false);
    }

    public static function remove($id) {
        $id = (string) $id;
        $rows = array_values(array_filter(self::all(), function ($row) use ($id) {
            return (string) ($row['id'] ?? '') !== $id;
        }));
        update_option(self::OPT, $rows, false);
    }

    public static function handle_add() {
        if (!current_user_can('manage_options')) {
            wp_die('Недостаточно прав');
        }
        check_admin_referer('gs_review_add');
        $text = isset($_POST['text']) ? (string) wp_unslash($_POST['text']) : '';
        $name = isset($_POST['name']) ? (string) wp_unslash($_POST['name']) : '';
        if (trim($text) !== '' && trim($name) !== '') {
            self::add(array(
                'service' => isset($_POST['service']) ? (string) wp_unslash($_POST['service']) : '',
                'name'    => $name,
                'rating'  => isset($_POST['rating']) ? (int) $_POST['rating'] : 5,
                'text'    => $text,
                'source'  => isset($_POST['source']) ? (string) wp_unslash($_POST['source']) : '',
                'date'    => isset($_POST['date']) ? (string) wp_unslash($_POST['date']) : '',
            ));
        }
        wp_safe_redirect(admin_url('admin.php?page=genius-sounds') . '#gs-reviews');
        exit;
    }

    public static function handle_del() {
        if (!current_user_can('manage_options')) {
            wp_die('Недостаточно прав');
        }
        check_admin_referer('gs_review_del');
        self::remove(isset($_POST['id']) ? (string) wp_unslash($_POST['id']) : '');
        wp_safe_redirect(admin_url('admin.php?page=genius-sounds') . '#gs-reviews');
        exit;
    }

    /** Блок на странице сервиса. Пусто — значит ничего не выводим. */
    public static function render($service_id) {
        $rows = self::for_service($service_id);
        if (!$rows) {
            return '';
        }
        $agg = self::aggregate($rows);

        ob_start();
        ?>
        <section class="gs-reviews" id="gs-reviews">
            <h2 class="gs-section-title">Отзывы</h2>
            <?php if ($agg): ?>
                <p class="gs-reviews__agg">
                    <span class="gs-reviews__stars" aria-hidden="true"><?php
                        echo esc_html(str_repeat('★', (int) round($agg['value'])) . str_repeat('☆', 5 - (int) round($agg['value'])));
                    ?></span>
                    <?php echo esc_html(number_format_i18n($agg['value'], 1)); ?> из 5,
                    <?php echo esc_html(self::plural_reviews((int) $agg['count'])); ?>
                </p>
            <?php endif; ?>
            <div class="gs-reviews__list">
                <?php foreach (array_slice($rows, 0, 8) as $row): ?>
                    <article class="gs-reviews__item">
                        <header class="gs-reviews__head">
                            <span class="gs-reviews__name"><?php echo esc_html((string) $row['name']); ?></span>
                            <?php if (!empty($row['rating'])): ?>
                                <span class="gs-reviews__rate" aria-label="Оценка <?php echo (int) $row['rating']; ?> из 5">
                                    <?php echo esc_html(str_repeat('★', (int) $row['rating'])); ?>
                                </span>
                            <?php endif; ?>
                            <?php if (!empty($row['date'])): ?>
                                <time class="gs-reviews__date" datetime="<?php echo esc_attr((string) $row['date']); ?>"><?php
                                    echo esc_html(mysql2date('j F Y', (string) $row['date']));
                                ?></time>
                            <?php endif; ?>
                        </header>
                        <p class="gs-reviews__text"><?php echo esc_html((string) $row['text']); ?></p>
                        <?php if (!empty($row['source'])): ?>
                            <a class="gs-reviews__src" href="<?php echo esc_url((string) $row['source']); ?>" rel="nofollow noopener" target="_blank">Источник</a>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
        <?php
        return (string) ob_get_clean();
    }

    private static function plural_reviews($n) {
        $forms = array('отзыв', 'отзыва', 'отзывов');
        $mod10 = $n % 10;
        $mod100 = $n % 100;
        if ($mod10 === 1 && $mod100 !== 11) {
            $form = $forms[0];
        } elseif ($mod10 >= 2 && $mod10 <= 4 && ($mod100 < 10 || $mod100 >= 20)) {
            $form = $forms[1];
        } else {
            $form = $forms[2];
        }
        return $n . ' ' . $form;
    }

    /**
     * Разметка отзывов для страницы сервиса.
     *
     * Печатается только из сохранённых отзывов и только когда они видны на
     * самой странице: оценка в выдаче без отзывов на странице — нарушение
     * требований поисковиков, а не хитрость.
     *
     * @return array{aggregateRating?:array,review?:array}
     */
    public static function schema($service_id) {
        $rows = self::for_service($service_id);
        if (!$rows) {
            return array();
        }
        $out = array();
        $agg = self::aggregate($rows);
        if ($agg) {
            $out['aggregateRating'] = array(
                '@type'       => 'AggregateRating',
                'ratingValue' => (string) $agg['value'],
                'reviewCount' => (int) $agg['count'],
                'bestRating'  => '5',
                'worstRating' => '1',
            );
        }
        $items = array();
        foreach (array_slice($rows, 0, 8) as $row) {
            $item = array(
                '@type'         => 'Review',
                'author'        => array('@type' => 'Person', 'name' => (string) $row['name']),
                'reviewBody'    => (string) $row['text'],
            );
            if (!empty($row['date'])) {
                $item['datePublished'] = (string) $row['date'];
            }
            if (!empty($row['rating'])) {
                $item['reviewRating'] = array(
                    '@type'       => 'Rating',
                    'ratingValue' => (string) (int) $row['rating'],
                    'bestRating'  => '5',
                    'worstRating' => '1',
                );
            }
            $items[] = $item;
        }
        if ($items) {
            $out['review'] = $items;
        }
        return $out;
    }
}
