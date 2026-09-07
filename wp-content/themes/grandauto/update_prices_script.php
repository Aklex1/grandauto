<?php
/**
 * GRAND AUTO — массовое управление ценами проката.
 *
 * Открывать по адресу:
 *   https://grandauto19.ru/wp-content/themes/grandauto/update_prices_script.php
 *
 * Доступ: только авторизованный пользователь WordPress с правом редактировать
 * записи (Администратор / Редактор / Автор). Неавторизованных перебрасывает
 * на форму входа, остальных — на страницу с ошибкой 403.
 *
 * Как пользоваться:
 *   1. Правите таблицу $PRICES ниже (Внешний вид → Редактор тем → update_prices_script.php).
 *   2. Открываете скрипт в браузере — он ПОКАЗЫВАЕТ, что изменится, но ничего не пишет.
 *   3. Проверяете таблицу и нажимаете «Применить изменения».
 *
 * Формат строки таблицы:
 *   ID => ['Название для контроля',
 *          [базовый сезон:  1-3, 4-8, 9-15, 16-30, 30+],
 *          [низкий  сезон:  1-3, 4-8, 9-15, 16-30, 30+]],
 *
 *   число  — записать это значение
 *   null   — не трогать поле (оставить как есть в базе)
 *   ''     — очистить поле
 *
 * ID — это номер записи автомобиля. Его видно в адресе страницы редактирования:
 *   /wp-admin/post.php?post=863&action=edit  →  ID = 863
 * Привязка идёт строго по ID; название нужно только для самопроверки — если оно
 * разойдётся с названием записи, скрипт покажет предупреждение.
 *
 * ВАЖНО про низкий сезон: на текущем публичном сайте (/prokat-avtonew/) цены
 * низкого сезона НЕ берутся из этих полей — фронтенд считает их как «базовая
 * минус 15%». Поля price_low_* заполняются здесь на будущее и используются
 * старыми шаблонами темы. Чтобы сайт показывал именно эти цифры, нужна правка
 * фронтенда в каталоге /updated/.
 */

/* ============================================================================
 *  ТАБЛИЦА ЦЕН — редактируйте только её
 * ========================================================================== */

$PRICES = [
      863 => ['TOYOTA LC 200',                 [ 21890,  20790,  19690,  18700,  11111], [ 19900,  18900,  17900,  17000,    111]],  // ВНИМАНИЕ: в базе сейчас 2100/1900/1700/1500 — перезаписано старым скриптом 07.09.2026. Здесь возвращены значения, которые были до этого.
      167 => ['LEXUS LX 570 [ЧЕРНОВИК]',       [ 16200,  15700,  15100,  14400,  13600], [  null,   null,   null,   null,   null]],
      698 => ['LAND CRUISER PRADO [ЧЕРНОВИК]', [ 13900,  13300,  12700,  11900,  10000], [  9700,   9300,   8900,   8300,   null]],
      749 => ['TANK 300',                      [ 13900,  13300,  12700,  11900, 111111], [ 92700,   9300,   8900,   8300,    111]],
     1013 => ['TOYOTA HILUX [ЧЕРНОВИК]',       [ 11900,  11300,  10700,   9900,      0], [  null,   null,   null,   null,   null]],
     1040 => ['Mazda CX-5',                    [  9800,   9400,   8900,   8600,  '000'], [  3900,   3700,   3500,   3200,   null]],
      678 => ['TOYOTA RAV-4',                  [  9800,   9400,   8900,   8600,  11111], [  8200,   7900,   7450,   7150,   null]],
      772 => ['Toyota Camry [ЧЕРНОВИК]',       [  9800,   9400,   8900,   8600, 111111], [  8200,   7900,   7500,   7100,   null]],
     1158 => ['KIA K5',                        [  9800,   9400,   8900,   8600, '0000'], [  3400,   3200,   3000,   2800,  '000']],
      788 => ['Toyota Camry [ЧЕРНОВИК]',       [  9800,   9400,   8900,   8600,    111], [  7800,   7500,   7100,   6800,   1111]],
      177 => ['NISSAN PATROL [ЧЕРНОВИК]',      [  9800,   9400,   9000,   8700,   7900], [  null,   null,   null,   null,   null]],
      731 => ['TOYOTA ALPHARD HYBRID [ЧЕРНОВИК]', [  9400,   9000,   8600,   8300,   7500], [  null,   null,   null,   null,   null]],
      630 => ['TOYOTA RAV-4',                  [  8000,   7800,   7500,   7200,  11111], [  6750,   6500,   6300,   6100,   1111]],
      683 => ['TOYOTA CAMRY [ЧЕРНОВИК]',       [  7000,   6600,   6200,   5800,   5400], [  null,   null,   null,   null,   null]],
      187 => ['TOYOTA RAV-4 [ЧЕРНОВИК]',       [  6800,   6400,   6100,   5600,   1111], [  4800,   4500,   4300,   4000,   null]],
      643 => ['MITSUBSHI PAJERO [ЧЕРНОВИК]',   [  6500,   6200,   5900,   5600,   5300], [  null,   null,   null,   null,   null]],
      932 => ['Мazda СХ-5',                    [  6400,   6000,   5600,   5200,   1111], [  5350,   5050,   4750,   4300,    111]],
      725 => ['HYUNDAI CRETA',                 [  6300,   5900,   5600,   5300,   1111], [  3300,   3100,   2900,   2700,   1111]],
      207 => ['TOYOTA CAMRY',                  [  5900,   5600,   5300,   5000,   1111], [  4950,   4650,   4400,   4200,   1111]],
     1103 => ['Toyota Camry [ЧЕРНОВИК]',       [  5900,   5600,   5300,   5000,   1111], [  4950,   4650,   4400,   4200,  '000']],
      842 => ['Honda Crosstour',               [  5900,   5600,   5300,   4900,   1107], [  4100,   3900,   3700,   3500,   1111]],
      612 => ['BMW 528i [ЧЕРНОВИК]',           [  5800,   5500,   5200,   4900,   4600], [  null,   null,   null,   null,   null]],
      217 => ['HYUNDAI CRETA',                 [  5700,   5400,   5100,   4700,  11111], [  4750,   4500,   4200,   3900,    111]],
     1041 => ['Hyundai Avante',                [  5400,   5100,   4800,   4600,   1111], [  4500,   4200,   4000,   3800,   1111]],
      967 => ['Toyota RAV 4 [ЧЕРНОВИК]',       [  5300,   5000,   4600,   4300,    111], [  3700,   3500,   3200,   3000,   null]],
     1188 => ['Mazda 6',                       [  5300,   5000,   4800,   4600, '0000'], [  4400,   4200,   4000,   3800,   null]],
      202 => ['MAZDA 6 [ЧЕРНОВИК]',            [  5200,   5000,   4800,   4600,   1111], [  4200,   4000,   3800,   3600,    111]],
      976 => ['KIA RIO',                       [  5200,   4900,   4600,   4299,    111], [  3800,   3700,   3600,   3300,   1111]],
      850 => ['geely emgrand',                 [  5100,   4700,   4400,   4100,   1111], [  3600,   3300,   3100,   2900,     11]],
      921 => ['Hyundai Solaris HS',            [  5000,   4700,   4500,   4200,    111], [  4200,   4000,   3800,   3600,    111]],
      197 => ['TOYOTA RAV4 [ЧЕРНОВИК]',        [  4800,   4500,   4200,   3900,   3500], [  null,   null,   null,   null,   null]],
      638 => ['HYUNDAI AVANTE [ЧЕРНОВИК]',     [  4700,   4400,   4100,   3800,   3400], [  null,   null,   null,   null,   null]],
      227 => ['TOYOTA CAMRY',                  [  4600,   4400,   4200,   3900,  11111], [  3800,   3700,   3600,   3300,    111]],
      261 => ['VOLKSWAGEN POLO',               [  4600,   4300,   4100,   3900,    111], [  3800,   3700,   3600,   3300,    111]],
      926 => ['Hyundai Solaris',               [  4500,   4300,   4100,   3900, 111111], [  3200,   3000,   2900,   2700,   null]],
     1002 => ['Hyundai Solaris',               [  4500,      3,   4100,   3900,      0], [  3800,   3700,   3600,   3300,    111]],
      651 => ['hyundai SOLARIS',               [  4500,   4300,   4100,   3900,   1111], [  3500,   3300,   3200,   3000,    111]],
      937 => ['SKODA RAPID',                   [  4500,   4300,   4100,   3900,    111], [  3200,   3000,   2800,   2600,    111]],
      237 => ['SKODA RAPID',                   [  4500,   4300,   4100,   3900,   1111], [  3800,   3700,   3600,   3300,    111]],
      618 => ['Kia Rio',                       [  4500,   4300,   4100,   3900,   1111], [  4400,   4100,   3800,   3600,    111]],
      222 => ['TOYOTA CAMRY [ЧЕРНОВИК]',       [  4300,   4000,   3700,   3400,   3000], [  null,   null,   null,   null,   null]],
     1042 => ['Skoda Octavia',                 [  4300,   4100,   3900,   3700,  '000'], [  3200,   3000,   2800,   2600,   null]],
      987 => ['Toyota Camry [ЧЕРНОВИК]',       [  4300,   4100,   3900,   3700,      0], [  3200,   3000,   2800,   2600,   null]],
     1088 => ['Toyota Prius',                  [  4000,   3700,   3500,   3200,   1111], [  3800,   3700,   3600,   3300, '0000']],
      661 => ['VOLKSWAGEN POLO',               [  4000,   3700,   3500,   3200,    111], [  3350,   3150,   2950,   2750,    111]],
      230 => ['TOYOTA WISH',                   [  4000,   3700,   3500,   3200,   1111], [  2800,   2600,   2400,   2200,   null]],
      997 => ['Toyota Prius [ЧЕРНОВИК]',       [  4000,   3700,   3500,   3200,   1111], [  3200,   3000,   2800,   2600,    111]],
      992 => ['Toyota Prius',                  [  4000,   3700,   3500,   3200,   1111], [  3200,   3000,   2800,   2600,   1111]],
      704 => ['TOYOTA CAMRY [ЧЕРНОВИК]',       [  3900,   3700,   3500,   3300,   2900], [  null,   null,   null,   null,   null]],
     1075 => ['Kia RIO [ЧЕРНОВИК]',            [  3700,   3500,   3300,   3100,  '000'], [  2600,   2400,   2200,   2000,  '000']],
     1061 => ['Прицеп для перевозки',          [  3500,   3500,   3500,   3500,   3500], [  3500,   3500,   3500,   3500,   3500]],
      977 => ['VOLKSWAGEN POLO [ЧЕРНОВИК]',    [  3100,   2900,   2700,   2500,   1111], [  2650,   2400,   2400,   2100,   null]],
     1070 => ['Лада Гранта',                   [  3000,   2700,   2500,   2200,   1111], [  2550,   2300,   2100,   1900,    111]],
      694 => ['VOLKSWAGEN POLO [ЧЕРНОВИК]',    [  2800,   2500,   2300,   2100,   1111], [  2950,   2650,   2400,   2200,    111]],
      276 => ['TOYOTA FIELDER [ЧЕРНОВИК]',     [  2800,   2500,   2300,   2200,   1111], [  2300,   2750,   1900,   1900,   null]],
     1187 => ['Mazda 6 [ЧЕРНОВИК]',            [  null,   null,   null,   null,   null], [  null,   null,   null,   null,   null]],];

/* ============================================================================
 *  Дальше — служебный код. Менять не нужно.
 * ========================================================================== */

$wp_load = dirname( __FILE__ ) . '/../../../wp-load.php';
if ( ! file_exists( $wp_load ) ) {
    exit( 'wp-load.php не найден' );
}
require_once $wp_load;

// --- Доступ -----------------------------------------------------------------
if ( ! is_user_logged_in() ) {
    auth_redirect(); // отправит на /wp-login.php и вернёт обратно после входа
    exit;
}
if ( ! current_user_can( 'edit_posts' ) ) {
    wp_die(
        'У вашей учётной записи нет прав на изменение цен.',
        'Доступ запрещён',
        [ 'response' => 403 ]
    );
}

// --- Поля ACF ---------------------------------------------------------------
$TIERS = [
    '1_3'   => '1-3 суток',
    '4_8'   => '4-8 суток',
    '9_15'  => '9-15 суток',
    '16_30' => '16-30 суток',
    '30'    => '30+ суток',
];

$FIELD_KEYS = [
    'price_st_1_3'    => 'field_628f39cd849e5',
    'price_st_4_8'    => 'field_628f3a73849e6',
    'price_st_9_15'   => 'field_628f3a81849e7',
    'price_st_16_30'  => 'field_628f3a8f849e8',
    'price_st_30'     => 'field_628f3a9b849e9',
    'price_low_1_3'   => 'field_price_low_1_3',
    'price_low_4_8'   => 'field_price_low_4_8',
    'price_low_9_15'  => 'field_price_low_9_15',
    'price_low_16_30' => 'field_price_low_16_30',
    'price_low_30'    => 'field_price_low_30',
];

/** Приводит значение к строке для сравнения. */
function ga_norm( $v ) {
    return trim( (string) $v );
}

/** Убирает служебные пометки вида [ЧЕРНОВИК] и приводит название к сравнимому виду. */
function ga_norm_title( $t ) {
    $t = preg_replace( '/\[[^\]]*\]/u', '', (string) $t );
    $t = mb_strtoupper( $t, 'UTF-8' );
    return preg_replace( '/[^A-ZА-Я0-9]/u', '', $t );
}

/** Записывает поле: через ACF, если он есть, иначе напрямую в мета-поля. */
function ga_write_field( $post_id, $name, $key, $value ) {
    if ( function_exists( 'update_field' ) ) {
        return update_field( $key, $value, $post_id );
    }
    update_post_meta( $post_id, $name, $value );
    update_post_meta( $post_id, '_' . $name, $key );
    return true;
}

// --- Разбор таблицы и сверка с базой ---------------------------------------
$rows        = [];
$total_changes = 0;
$has_errors  = false;

foreach ( $PRICES as $post_id => $spec ) {
    $row = [
        'id'       => (int) $post_id,
        'label'    => isset( $spec[0] ) ? (string) $spec[0] : '',
        'wp_title' => '',
        'errors'   => [],
        'warnings' => [],
        'cells'    => [],
    ];

    $post = get_post( $post_id );
    if ( ! $post ) {
        $row['errors'][] = 'запись с таким ID не найдена';
    } elseif ( 'rental_car' !== $post->post_type ) {
        $row['errors'][] = 'это не автомобиль проката (тип записи: ' . $post->post_type . ')';
    } else {
        $row['wp_title'] = $post->post_title;
        $row['status']   = $post->post_status;
        if ( ga_norm_title( $row['label'] ) !== ga_norm_title( $post->post_title ) ) {
            $row['warnings'][] = 'название в файле не совпадает с названием записи («' . $post->post_title . '»)';
        }
    }

    $base = isset( $spec[1] ) && is_array( $spec[1] ) ? array_values( $spec[1] ) : [];
    $low  = isset( $spec[2] ) && is_array( $spec[2] ) ? array_values( $spec[2] ) : [];

    $i = 0;
    foreach ( $TIERS as $tier => $tier_label ) {
        foreach ( [ 'base' => 'price_st_', 'low' => 'price_low_' ] as $season => $prefix ) {
            $src   = 'base' === $season ? $base : $low;
            $name  = $prefix . $tier;
            $new   = array_key_exists( $i, $src ) ? $src[ $i ] : null;
            $cur   = $row['errors'] ? '' : ga_norm( get_post_meta( $post_id, $name, true ) );

            $cell = [
                'name'    => $name,
                'season'  => $season,
                'tier'    => $tier_label,
                'current' => $cur,
                'new'     => $new,
                'skip'    => ( null === $new ),
                'changed' => false,
            ];
            if ( null !== $new && ga_norm( $new ) !== $cur ) {
                $cell['changed'] = true;
                $total_changes++;
            }
            $row['cells'][ $season ][ $tier ] = $cell;
        }
        $i++;
    }

    // Проверки на здравый смысл.
    if ( ! $row['errors'] ) {
        $eff = [];
        foreach ( [ 'base', 'low' ] as $season ) {
            foreach ( $TIERS as $tier => $tl ) {
                $c            = $row['cells'][ $season ][ $tier ];
                $eff[ $season ][ $tier ] = $c['skip'] ? $c['current'] : ga_norm( $c['new'] );
            }
        }
        foreach ( $TIERS as $tier => $tl ) {
            $b = $eff['base'][ $tier ];
            $l = $eff['low'][ $tier ];
            if ( '' === $b || '' === $l ) {
                continue;
            }
            if ( (float) $l >= (float) $b && (float) $b > 0 ) {
                $row['warnings'][] = $tl . ': низкий сезон (' . $l . ') не ниже базового (' . $b . ')';
            } elseif ( (float) $b > 0 && (float) $l > 0 && (float) $l < (float) $b * 0.4 ) {
                $row['warnings'][] = $tl . ': низкий сезон (' . $l . ') меньше 40% от базового (' . $b . ') — похоже на ошибку';
            }
        }
        $prev = null;
        foreach ( $TIERS as $tier => $tl ) {
            $v = $eff['base'][ $tier ];
            if ( '' === $v || 0 >= (float) $v ) {
                continue;
            }
            if ( null !== $prev && (float) $v > (float) $prev ) {
                $row['warnings'][] = 'базовая цена за ' . $tl . ' (' . $v . ') выше, чем за более короткий срок (' . $prev . ')';
            }
            $prev = $v;
        }
    }

    if ( $row['errors'] ) {
        $has_errors = true;
    }
    $rows[] = $row;
}

// Автомобили, которых нет в таблице.
$missing = [];
foreach ( get_posts( [
    'post_type'   => 'rental_car',
    'post_status' => [ 'publish', 'draft', 'pending', 'private' ],
    'numberposts' => -1,
    'fields'      => 'ids',
] ) as $id ) {
    if ( ! array_key_exists( $id, $PRICES ) ) {
        $missing[ $id ] = get_the_title( $id );
    }
}

// --- Применение -------------------------------------------------------------
$applied = null;
if ( isset( $_POST['ga_apply'] ) ) {
    check_admin_referer( 'ga_apply_prices' );
    $applied = [ 'fields' => 0, 'cars' => 0, 'log' => [] ];

    foreach ( $rows as $ri => $row ) {
        if ( $row['errors'] ) {
            continue;
        }
        $changed_here = 0;
        foreach ( [ 'base', 'low' ] as $season ) {
            foreach ( $TIERS as $tier => $tl ) {
                $cell = $row['cells'][ $season ][ $tier ];
                if ( $cell['skip'] || ! $cell['changed'] ) {
                    continue;
                }
                ga_write_field( $row['id'], $cell['name'], $FIELD_KEYS[ $cell['name'] ], $cell['new'] );
                $applied['log'][] = sprintf(
                    '#%d %s — %s: %s → %s',
                    $row['id'],
                    $row['wp_title'],
                    $cell['name'],
                    '' === $cell['current'] ? '(пусто)' : $cell['current'],
                    ga_norm( $cell['new'] )
                );
                $changed_here++;
                $rows[ $ri ]['cells'][ $season ][ $tier ]['current'] = ga_norm( $cell['new'] );
                $rows[ $ri ]['cells'][ $season ][ $tier ]['changed'] = false;
            }
        }
        if ( $changed_here ) {
            clean_post_cache( $row['id'] );
            $applied['cars']++;
            $applied['fields'] += $changed_here;
        }
    }
}

// --- Вывод ------------------------------------------------------------------
function ga_cell_html( $cell ) {
    if ( $cell['skip'] ) {
        return '<td class="skip">' . esc_html( '' === $cell['current'] ? '—' : $cell['current'] ) . '</td>';
    }
    $new = ga_norm( $cell['new'] );
    if ( ! $cell['changed'] ) {
        return '<td>' . esc_html( '' === $new ? '—' : $new ) . '</td>';
    }
    return '<td class="chg">'
        . '<s>' . esc_html( '' === $cell['current'] ? 'пусто' : $cell['current'] ) . '</s> '
        . '<b>' . esc_html( '' === $new ? 'пусто' : $new ) . '</b></td>';
}

header( 'Content-Type: text/html; charset=utf-8' );
?>
<!doctype html>
<meta charset="utf-8">
<title>Цены проката — Grand Auto</title>
<style>
 body{font:14px/1.5 -apple-system,Segoe UI,Roboto,sans-serif;margin:0;padding:24px;background:#f4f7f9;color:#222}
 h1{font-size:22px;margin:0 0 4px}
 .wrap{max-width:1400px;margin:0 auto}
 .bar{background:#fff;border:1px solid #e0e6ea;border-radius:10px;padding:16px 20px;margin-bottom:18px}
 table{border-collapse:collapse;width:100%;background:#fff;font-size:13px}
 th,td{border:1px solid #e0e6ea;padding:5px 8px;text-align:right;white-space:nowrap}
 th{background:#eef3f6;text-align:center;font-weight:600}
 td.car{text-align:left;white-space:normal;min-width:210px}
 td.skip{color:#9aa5ab}
 td.chg{background:#fff6d8}
 td.chg b{color:#0a7a2f}
 td.chg s{color:#b31806}
 tr.err td{background:#fde8e6}
 .warn{color:#8a5a00;font-size:12px;display:block}
 .err{color:#b31806;font-size:12px;display:block;font-weight:600}
 .btn{display:inline-block;background:#008DC7;color:#fff;border:0;border-radius:8px;padding:11px 22px;font-size:15px;cursor:pointer}
 .btn[disabled]{background:#9aa5ab;cursor:default}
 .ok{background:#e6f6ea;border-color:#b7e0c2}
 .sep{background:#f7fafb}
 code{background:#eef3f6;padding:1px 5px;border-radius:4px}
 details{margin-top:10px}
</style>
<div class="wrap">
<h1>Цены проката — Grand Auto</h1>
<p style="margin:0 0 18px;color:#666">Файл <code>wp-content/themes/grandauto/update_prices_script.php</code> · пользователь: <?php echo esc_html( wp_get_current_user()->display_name ); ?></p>

<?php if ( null !== $applied ) : ?>
    <div class="bar ok">
        <b>Изменения применены.</b> Автомобилей: <?php echo (int) $applied['cars']; ?>, полей: <?php echo (int) $applied['fields']; ?>.
        <?php if ( $applied['log'] ) : ?>
            <details><summary>Показать список</summary>
            <pre style="white-space:pre-wrap"><?php echo esc_html( implode( "\n", $applied['log'] ) ); ?></pre>
            </details>
        <?php endif; ?>
        <p style="margin:10px 0 0"><a href="<?php echo esc_url( remove_query_arg( 'x' ) ); ?>">Обновить страницу и сверить результат</a></p>
    </div>
<?php else : ?>
    <div class="bar">
        <?php if ( $total_changes ) : ?>
            <p style="margin:0 0 12px"><b>Найдено изменений: <?php echo (int) $total_changes; ?></b> (жёлтые ячейки ниже). Пока ничего не записано.</p>
            <form method="post">
                <?php wp_nonce_field( 'ga_apply_prices' ); ?>
                <button class="btn" name="ga_apply" value="1" onclick="return confirm('Записать изменения в базу?')">Применить изменения</button>
            </form>
        <?php else : ?>
            <p style="margin:0"><b>Таблица совпадает с базой — применять нечего.</b> Отредактируйте <code>$PRICES</code> в этом файле и обновите страницу.</p>
        <?php endif; ?>
        <?php if ( $has_errors ) : ?>
            <p style="margin:12px 0 0" class="err">Есть строки с ошибками (красные) — они будут пропущены.</p>
        <?php endif; ?>
    </div>
<?php endif; ?>

<table>
<thead>
    <tr>
        <th rowspan="2">ID</th><th rowspan="2">Автомобиль</th><th rowspan="2">Статус</th>
        <th colspan="5">Базовый сезон, ₽/сут</th>
        <th colspan="5" class="sep">Низкий сезон, ₽/сут</th>
    </tr>
    <tr>
        <?php foreach ( $TIERS as $tl ) : ?><th><?php echo esc_html( $tl ); ?></th><?php endforeach; ?>
        <?php foreach ( $TIERS as $tl ) : ?><th class="sep"><?php echo esc_html( $tl ); ?></th><?php endforeach; ?>
    </tr>
</thead>
<tbody>
<?php foreach ( $rows as $row ) : ?>
    <tr class="<?php echo $row['errors'] ? 'err' : ''; ?>">
        <td><?php echo (int) $row['id']; ?></td>
        <td class="car">
            <?php echo esc_html( $row['wp_title'] ? $row['wp_title'] : $row['label'] ); ?>
            <?php foreach ( $row['errors'] as $e ) : ?><span class="err"><?php echo esc_html( $e ); ?></span><?php endforeach; ?>
            <?php foreach ( $row['warnings'] as $w ) : ?><span class="warn">! <?php echo esc_html( $w ); ?></span><?php endforeach; ?>
        </td>
        <td><?php echo esc_html( isset( $row['status'] ) ? ( 'publish' === $row['status'] ? 'на сайте' : 'черновик' ) : '—' ); ?></td>
        <?php foreach ( [ 'base', 'low' ] as $season ) : ?>
            <?php foreach ( $TIERS as $tier => $tl ) : ?>
                <?php echo ga_cell_html( $row['cells'][ $season ][ $tier ] ); // phpcs:ignore ?>
            <?php endforeach; ?>
        <?php endforeach; ?>
    </tr>
<?php endforeach; ?>
</tbody>
</table>

<?php if ( $missing ) : ?>
    <div class="bar" style="margin-top:18px">
        <b>Нет в таблице <?php echo count( $missing ); ?> автомобил(ей)</b> — их цены скрипт не трогает:
        <ul>
        <?php foreach ( $missing as $id => $t ) : ?>
            <li><code><?php echo (int) $id; ?></code> — <?php echo esc_html( $t ); ?></li>
        <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>
</div>
