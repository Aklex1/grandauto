<?php
/**
 * Цены автомобилей проката (rental_car):
 *   - регистрация ACF-полей низкого сезона;
 *   - колонки «Базовый сезон» и «Низкий сезон» в списке автомобилей.
 *
 * Базовые цены (price_st_*) живут в группе полей «Арендный автомобиль»,
 * она заведена через админку ACF. Здесь регистрируются только цены
 * низкого сезона (price_low_*), чтобы обе таблицы цен редактировались
 * на одной странице автомобиля, одна под другой.
 *
 * Массовая правка цен — wp-content/themes/grandauto/update_prices_script.php
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Периоды аренды: суффикс поля => подпись.
 */
function ga_price_tiers() {
    return array(
        '1_3'   => '1-3 суток',
        '4_8'   => '4-8 суток',
        '9_15'  => '9-15 суток',
        '16_30' => '16-30 суток',
        '30'    => '30+ суток',
    );
}

/* -------------------------------------------------------------------------
 *  ACF: цены низкого сезона
 * ---------------------------------------------------------------------- */

add_action( 'acf/init', 'add_rental_car_price_fields' );
function add_rental_car_price_fields() {

    if ( ! function_exists( 'acf_add_local_field_group' ) ) {
        return;
    }

    $fields = array();
    foreach ( ga_price_tiers() as $tier => $label ) {
        $fields[] = array(
            'key'          => 'field_price_low_' . $tier,
            'label'        => 'Цена ' . $label . ' (низкий сезон)',
            'name'         => 'price_low_' . $tier,
            'type'         => 'number',
            'instructions' => 'Цена за сутки при аренде на ' . $label . ' в низкий сезон. Пусто — цена не задана.',
            'required'     => 0,
            'wrapper'      => array( 'width' => '20', 'class' => '', 'id' => '' ),
            'default_value' => '',
            'placeholder'  => '',
            'prepend'      => '',
            'append'       => 'руб.',
            'min'          => 0,
            'max'          => '',
            'step'         => '',
        );
    }

    acf_add_local_field_group( array(
        'key'        => 'group_rental_car_prices_low_season',
        'title'      => 'Цены низкого сезона',
        'fields'     => $fields,
        'location'   => array(
            array(
                array(
                    'param'    => 'post_type',
                    'operator' => '==',
                    'value'    => 'rental_car',
                ),
            ),
        ),
        'menu_order'            => 1, // сразу под группой «Арендный автомобиль»
        'position'              => 'normal',
        'style'                 => 'default',
        'label_placement'       => 'top',
        'instruction_placement' => 'label',
        'hide_on_screen'        => '',
        'active'                => true,
        'description'           => 'Цены для низкого сезона. Заполняются по тем же периодам, что и базовые цены выше.',
    ) );
}

/* -------------------------------------------------------------------------
 *  Список автомобилей: колонки с ценами
 * ---------------------------------------------------------------------- */

add_filter( 'manage_rental_car_posts_columns', 'ga_rental_car_price_columns' );
function ga_rental_car_price_columns( $columns ) {
    $new = array();
    foreach ( $columns as $key => $label ) {
        $new[ $key ] = $label;
        if ( 'title' === $key ) {
            $new['ga_price_base'] = 'Базовый сезон, ₽/сут';
            $new['ga_price_low']  = 'Низкий сезон, ₽/сут';
        }
    }
    if ( ! isset( $new['ga_price_base'] ) ) {
        $new['ga_price_base'] = 'Базовый сезон, ₽/сут';
        $new['ga_price_low']  = 'Низкий сезон, ₽/сут';
    }
    return $new;
}

add_action( 'manage_rental_car_posts_custom_column', 'ga_rental_car_price_column_content', 10, 2 );
function ga_rental_car_price_column_content( $column, $post_id ) {

    if ( 'ga_price_base' !== $column && 'ga_price_low' !== $column ) {
        return;
    }

    $prefix = ( 'ga_price_base' === $column ) ? 'price_st_' : 'price_low_';
    $out    = array();

    foreach ( ga_price_tiers() as $tier => $label ) {
        $value = trim( (string) get_post_meta( $post_id, $prefix . $tier, true ) );
        $class = 'ga-price';

        if ( '' === $value || 0 >= (float) $value ) {
            $out[] = '<span class="ga-price ga-price--empty" title="' . esc_attr( $label ) . '">—</span>';
            continue;
        }

        if ( 'ga_price_low' === $column ) {
            $base = (float) get_post_meta( $post_id, 'price_st_' . $tier, true );
            if ( $base > 0 && (float) $value >= $base ) {
                $class .= ' ga-price--bad';
                $label .= ': не ниже базовой цены';
            } elseif ( $base > 0 && (float) $value < $base * 0.4 ) {
                $class .= ' ga-price--bad';
                $label .= ': меньше 40% от базовой цены';
            }
        }

        $out[] = '<span class="' . esc_attr( $class ) . '" title="' . esc_attr( $label ) . '">'
            . esc_html( number_format( (float) $value, 0, ',', ' ' ) ) . '</span>';
    }

    echo implode( ' ', $out ); // phpcs:ignore WordPress.Security.EscapeOutput
}

add_action( 'admin_head', 'ga_rental_car_admin_styles' );
function ga_rental_car_admin_styles() {

    $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
    if ( ! $screen || 'rental_car' !== $screen->post_type ) {
        return;
    }
    ?>
    <style>
        .column-ga_price_base, .column-ga_price_low { width: 200px; }
        .ga-price { display: inline-block; min-width: 46px; padding: 1px 4px; text-align: right;
            font-variant-numeric: tabular-nums; border-radius: 3px; background: #f0f0f1; }
        .ga-price--empty { color: #a7aaad; background: transparent; }
        .ga-price--bad { background: #fcebea; color: #b31806; font-weight: 600; }
    </style>
    <?php
}

add_action( 'admin_notices', 'ga_rental_car_bulk_editor_hint' );
function ga_rental_car_bulk_editor_hint() {

    $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
    if ( ! $screen || 'edit' !== $screen->base || 'rental_car' !== $screen->post_type ) {
        return;
    }
    if ( ! current_user_can( 'edit_posts' ) ) {
        return;
    }

    $url = esc_url( get_template_directory_uri() . '/update_prices_script.php' );
    echo '<div class="notice notice-info"><p>Цены редактируются на странице каждого автомобиля — блоки «Арендный автомобиль» (базовый сезон) и «Цены низкого сезона». '
        . 'Для массовой правки: <a href="' . $url . '">таблица цен всех автомобилей</a>.</p></div>';
}

/* -------------------------------------------------------------------------
 *  Публичный REST API цен для статической витрины /updated/
 *
 *  Адрес: https://grandauto19.ru/wp-json/grandauto/v1/prices
 *  По каждому опубликованному авто отдаёт цены обоих сезонов из ACF:
 *    base — базовый сезон (price_st_*)
 *    low  — низкий сезон  (price_low_*, если пусто → базовая − 15%)
 *  плюс проценты скидок карт лояльности (silver/gold) из настроек темы.
 *  Витрина применяет скидки поверх цены выбранного сезона.
 *  Массовое заполнение цен — update_prices_script.php.
 * ---------------------------------------------------------------------- */

add_action( 'rest_api_init', 'ga_register_prices_route' );
function ga_register_prices_route() {
    register_rest_route( 'grandauto/v1', '/prices', array(
        'methods'             => 'GET',
        'permission_callback' => '__return_true',
        'callback'            => 'ga_rental_prices_payload',
    ) );
}

function ga_rental_prices_payload() {

    $out = array(
        'updated' => gmdate( 'c' ),
        'silver'  => (float) get_option( 'discount_silver', 0 ),
        'gold'    => (float) get_option( 'discount_gold', 0 ),
        'cars'    => array(),
    );

    $posts = get_posts( array(
        'post_type'   => 'rental_car',
        'post_status' => 'publish',
        'numberposts' => -1,
        'orderby'     => 'menu_order title',
        'order'       => 'ASC',
    ) );

    foreach ( $posts as $p ) {
        $base = array();
        $low  = array();

        foreach ( ga_price_tiers() as $tier => $label ) {
            if ( '30' === $tier ) {
                continue; // витрина показывает 4 периода: 1-3, 4-8, 9-15, 16-30
            }
            $b = (float) get_post_meta( $p->ID, 'price_st_' . $tier, true );
            $l = trim( (string) get_post_meta( $p->ID, 'price_low_' . $tier, true ) );

            $base[ $tier ] = ( $b > 0 ) ? $b : null;

            if ( '' === $l || (float) $l <= 0 ) {
                $low[ $tier ] = ( $b > 0 ) ? (float) floor( $b * 0.85 ) : null;
            } else {
                $low[ $tier ] = (float) $l;
            }
        }

        $out['cars'][] = array(
            'id'   => $p->ID,
            'slug' => $p->post_name,
            'base' => $base,
            'low'  => $low,
        );
    }

    return rest_ensure_response( $out );
}
