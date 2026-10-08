<?php
/**
 * Полоса «выбрать другой кадр» — мостик в каталог промтов.
 *
 * Там, где человек выбирает кадр из нашей небольшой подборки, у него
 * ровно два исхода: нашёл или ушёл с сайта. Каталог промтов закрывает
 * второй случай — в нём больше тысячи кадров, и к каждому есть текст,
 * который его повторяет. Полоса держится на экране, чтобы этот выход
 * был виден, а не лежал где-то внизу страницы.
 *
 * Два места и два размера: у ИИ-фотосессии карточка на десятую часть
 * крупнее обычной, в нейрохабе — на пятнадцатую. На широком экране обе
 * сразу развёрнуты, на телефоне обе — строка внизу.
 */

if (!defined('ABSPATH')) {
    exit;
}

class GS_Promts_Band {

    /** Сколько карточек должно быть в каталоге, чтобы звать туда. */
    const MIN_ITEMS = 20;

    public static function boot() {
        // В подвале, а не внутри разметки страницы: в контейнере со своим
        // контекстом наложения прилипание не работает.
        add_action('wp_footer', array(__CLASS__, 'maybe_render'), 6);
    }

    /**
     * Где показываем и в каком виде.
     *
     * @return array{wide:bool,watch:string}|null
     */
    public static function place() {
        if (class_exists('GS_Neurohub') && GS_Neurohub::is_page()) {
            // Поле ввода чужого приложения — по нему понимаем, что человек
            // уже у инструмента и мешать ему не надо.
            return array('wide' => true, 'big' => true, 'watch' => '#knPrompt');
        }
        if (class_exists('GS_Lab')) {
            $service = GS_Lab::current_service();
            if (is_array($service) && (string) ($service['id'] ?? '') === 'photo') {
                return array('wide' => true, 'big' => false, 'watch' => '#gs-lab-form');
            }
        }
        return null;
    }

    public static function needed() {
        return self::place() !== null
            && class_exists('GS_Prompts') && class_exists('GS_Prompts_Page')
            && GS_Prompts::count() >= self::MIN_ITEMS;
    }

    public static function maybe_render() {
        if (!self::needed()) {
            return;
        }
        echo self::render(self::place());
    }

    /** Склонение слова «кадр» при числе. */
    private static function frames($n) {
        $n = (int) $n;
        $ten = $n % 100;
        if ($ten >= 11 && $ten <= 14) {
            return 'кадров';
        }
        $one = $n % 10;
        if ($one === 1) {
            return 'кадр';
        }
        if ($one >= 2 && $one <= 4) {
            return 'кадра';
        }
        return 'кадров';
    }

    private static function shots($limit) {
        $out = array();
        foreach (GS_Prompts::search('', '') as $item) {
            $file = (string) ($item['image'] ?? '');
            if ($file === '') {
                continue;
            }
            $out[] = GS_Prompts::shot_url($file);
            if (count($out) >= $limit) {
                break;
            }
        }
        return $out;
    }

    public static function render($opts) {
        $wide  = !empty($opts['wide']);
        $big   = !empty($opts['big']);
        $watch = (string) ($opts['watch'] ?? '');
        $total = GS_Prompts::count();
        $shots = self::shots(4);
        $cls = 'gs-promts-band'
            . ($wide ? ' gs-promts-band--wide' : '')
            . ($big ? ' gs-promts-band--xl' : '');
        ob_start();
        ?>
        <aside class="<?php echo esc_attr($cls); ?>" data-gs-band
               data-gs-band-watch="<?php echo esc_attr($watch); ?>" hidden>
            <button type="button" class="gs-promts-band__close" data-gs-band-close
                    aria-label="Скрыть подсказку">×</button>
            <?php if ($shots): ?>
                <div class="gs-promts-band__shots" aria-hidden="true">
                    <?php foreach ($shots as $url): ?>
                        <img src="<?php echo esc_url($url); ?>" alt=""
                             loading="lazy" decoding="async" width="120" height="160">
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <span class="gs-promts-band__tab" aria-hidden="true">Ещё <?php echo (int) $total; ?>
                <?php echo esc_html(self::frames($total)); ?></span>
            <div class="gs-promts-band__body">
                <div class="gs-promts-band__text">
                    <strong><?php echo $big
                        ? 'Нужен готовый кадр, а не чистый лист?'
                        : 'Не нашли подходящую подборку?'; ?></strong>
                    <span>В каталоге промтов — <?php echo (int) $total; ?> готовых
                        <?php echo esc_html(self::frames($total)); ?> с текстами к каждому:
                        поиск по словам и 15 рубрик.</span>
                </div>
                <a class="gs-btn gs-btn--primary gs-promts-band__go"
                   href="<?php echo esc_url(GS_Prompts_Page::url()); ?>"><span
                   class="gs-promts-band__cta-long"><?php echo $big
                        ? 'Открыть каталог промтов' : 'Выбрать другой кадр'; ?></span><span
                   class="gs-promts-band__cta-short">Каталог</span></a>
            </div>
        </aside>
        <?php
        return ob_get_clean();
    }
}
