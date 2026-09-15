<?php
/**
 * Галерея песен, спетых голосами пользователей.
 *
 * Свежий посетитель не верит описанию — он верит услышанному. Поэтому готовые
 * песни собраны на отдельной странице и показываются на самой посадочной:
 * послушал чужое, понял, чего ждать, и пошёл делать своё.
 *
 * Файл поставщика живёт считаные часы, поэтому опубликованная песня
 * копируется к нам: галерея не должна разваливаться на следующий день.
 */

if (!defined('ABSPATH')) {
    exit;
}

class GS_Songs {

    const OPT_ITEMS  = 'gs_songs_gallery';
    const OPT_PAGE   = 'gs_songs_page';
    const SLUG       = 'pesni-polzovateley';
    const LIMIT      = 200;
    const DIR        = 'songs';

    public static function boot() {
        add_filter('body_class', array(__CLASS__, 'body_class'));
    }

    public static function register_shortcodes() {
        add_shortcode('genius_songs', array(__CLASS__, 'render'));
    }

    /* ---------------------------------------------------------------------
     * Хранилище
     * ------------------------------------------------------------------ */

    /**
     * @return array<int,array{id:string,title:string,author:string,style:string,url:string,created:int}>
     */
    public static function all() {
        $items = get_option(self::OPT_ITEMS, array());
        return is_array($items) ? $items : array();
    }

    public static function latest($count) {
        return array_slice(self::all(), 0, max(0, (int) $count));
    }

    public static function count() {
        return count(self::all());
    }

    /**
     * Публикация песни в галерею.
     *
     * @return array{ok:bool,message:string}
     */
    public static function publish($user_id, $url, $title, $author, $style = '') {
        $url = esc_url_raw((string) $url);
        if ($url === '') {
            return array('ok' => false, 'message' => 'Нет ссылки на песню');
        }

        // Песня из архива уже лежит у нас — копировать второй раз незачем.
        $ours = strpos($url, GS_Storage::base_url() . '/' . self::DIR . '/') === 0;
        $local = $ours ? $url : self::store_copy($url);
        if ($local === '') {
            return array('ok' => false, 'message' => 'Не удалось сохранить песню — попробуйте ещё раз');
        }

        $items = self::all();
        foreach ($items as $existing) {
            if ((string) ($existing['url'] ?? '') === $local) {
                return array('ok' => true, 'message' => 'Эта песня уже в галерее');
            }
        }

        $author = trim(sanitize_text_field((string) $author));
        $title  = trim(sanitize_text_field((string) $title));

        array_unshift($items, array(
            'id'      => 'gsong-' . wp_generate_password(10, false, false),
            'title'   => $title !== '' ? mb_substr($title, 0, 80) : 'Песня без названия',
            'author'  => $author !== '' ? mb_substr($author, 0, 40) : 'Аноним',
            'style'   => mb_substr(trim(sanitize_text_field((string) $style)), 0, 120),
            'url'     => $local,
            'source'  => $url,
            'user_id' => (int) $user_id,
            'created' => time(),
        ));
        update_option(self::OPT_ITEMS, array_slice($items, 0, self::LIMIT), false);

        return array('ok' => true, 'message' => 'Песня опубликована в галерее');
    }

    public static function remove($id) {
        $id = (string) $id;
        $items = self::all();
        $kept = array();
        foreach ($items as $item) {
            if ((string) ($item['id'] ?? '') === $id) {
                continue; // файл остаётся: он же лежит в архиве автора
            }
            $kept[] = $item;
        }
        update_option(self::OPT_ITEMS, $kept, false);
        return count($items) !== count($kept);
    }

    /** Копия песни у нас: ссылка поставщика живёт считаные часы. */
    public static function store_copy($url) {
        $dir = GS_Storage::base_dir() . '/' . self::DIR;
        if (!is_dir($dir) && !wp_mkdir_p($dir)) {
            return '';
        }
        $name = 'song-' . wp_generate_password(16, false, false) . '.mp3';
        $path = $dir . '/' . $name;

        $response = wp_remote_get($url, array('timeout' => 120));
        if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) !== 200) {
            return '';
        }
        $body = wp_remote_retrieve_body($response);
        if (strlen($body) < 10000) {
            return '';
        }
        if (file_put_contents($path, $body) === false) {
            return '';
        }
        @chmod($path, 0644);

        return GS_Storage::base_url() . '/' . self::DIR . '/' . $name;
    }


    /* ---------------------------------------------------------------------
     * Страница
     * ------------------------------------------------------------------ */

    public static function ensure_page() {
        $page_id = (int) get_option(self::OPT_PAGE);
        if ($page_id > 0 && get_post($page_id)) {
            return;
        }
        $title = 'Песни пользователей, спетые своим голосом';
        $existing = get_page_by_path(self::SLUG);
        if ($existing) {
            $page_id = (int) $existing->ID;
            wp_update_post(array(
                'ID'           => $page_id,
                'post_title'   => $title,
                'post_content' => '[genius_songs]',
                'post_status'  => 'publish',
            ));
        } else {
            $page_id = (int) wp_insert_post(array(
                'post_title'   => $title,
                'post_content' => '[genius_songs]',
                'post_status'  => 'publish',
                'post_type'    => 'page',
                'post_name'    => self::SLUG,
            ));
        }
        if ($page_id > 0) {
            update_option(self::OPT_PAGE, $page_id, false);
        }
    }

    public static function get_url() {
        $page_id = (int) get_option(self::OPT_PAGE);
        $url = $page_id > 0 ? get_permalink($page_id) : '';
        return $url ? $url : home_url('/' . self::SLUG . '/');
    }

    public static function is_page() {
        $page_id = (int) get_option(self::OPT_PAGE);
        return !is_admin() && $page_id > 0 && is_page($page_id);
    }

    public static function body_class($classes) {
        if (self::is_page()) {
            $classes[] = 'gs-studio-page';
            $classes[] = 'gs-chrome';
        }
        return $classes;
    }

    /* ---------------------------------------------------------------------
     * Разметка
     * ------------------------------------------------------------------ */

    /** Список песен — и на своей странице, и блоком на посадочной. */
    public static function render_list($items, $empty = '') {
        ob_start();
        if (!$items) {
            if ($empty !== '') {
                echo '<p class="gs-songs__empty">' . esc_html($empty) . '</p>';
            }
            return ob_get_clean();
        }
        ?>
        <div class="gs-songs">
            <?php foreach ($items as $item): ?>
                <article class="gs-song">
                    <div class="gs-song__head">
                        <h3 class="gs-song__title"><?php echo esc_html($item['title']); ?></h3>
                        <span class="gs-song__author"><?php echo esc_html($item['author']); ?></span>
                    </div>
                    <?php if (!empty($item['style'])): ?>
                        <p class="gs-song__style"><?php echo esc_html($item['style']); ?></p>
                    <?php endif; ?>
                    <audio class="gs-song__audio" controls preload="none" src="<?php echo esc_url($item['url']); ?>"></audio>
                </article>
            <?php endforeach; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    /** Блок «что получалось у других» для страницы сервиса. */
    public static function render_teaser($count = 3) {
        $items = self::latest($count);
        if (!$items) {
            return '';
        }
        ob_start();
        ?>
        <section class="gs-songs-teaser">
            <h2 class="gs-section-title">Послушайте, что получалось у других</h2>
            <p class="gs-songs-teaser__lead">
                Песни, которые люди сделали здесь своим голосом и разрешили показать.
            </p>
            <?php echo self::render_list($items); // phpcs:ignore WordPress.Security.EscapeOutput ?>
            <?php if (self::count() > count($items)): ?>
                <p class="gs-songs-teaser__more">
                    <a href="<?php echo esc_url(self::get_url()); ?>">Все песни пользователей</a>
                </p>
            <?php endif; ?>
        </section>
        <?php
        return ob_get_clean();
    }

    public static function render($atts = array()) {
        $items = self::all();
        ob_start();
        ?>
        <div class="gs-wrap gs-studio gs-songs-page">
            <nav class="gs-breadcrumbs" aria-label="Хлебные крошки">
                <a href="<?php echo esc_url(home_url('/')); ?>">Главная</a>
                <span aria-hidden="true">/</span>
                <span class="gs-breadcrumbs__current">Песни пользователей</span>
            </nav>

            <section class="gs-hero gs-hero--studio">
                <span class="gs-hero__badge">Голоса наших пользователей</span>
                <h1 class="gs-hero__title">Песни, спетые собственным голосом</h1>
                <p class="gs-hero__lead">
                    Здесь то, что получилось у других: голос записан на обычный микрофон,
                    текст свой, музыку написала нейросеть. Послушайте — и сделайте свою.
                </p>
                <div class="gs-hero__meta">
                    <span class="gs-chip gs-chip--ok"><?php echo esc_html(self::count()); ?> <?php echo esc_html(self::plural(self::count())); ?></span>
                    <a class="gs-btn gs-btn--primary" href="<?php echo esc_url(GS_Lab::get_url('voicesong')); ?>">Создать свою песню</a>
                </div>
            </section>

            <?php echo self::render_list($items, 'Пока здесь пусто — ваша песня может стать первой.'); // phpcs:ignore WordPress.Security.EscapeOutput ?>

            <?php echo GS_Lab_Page::render_cross_links('voicesong', 'Другие инструменты со звуком'); // phpcs:ignore WordPress.Security.EscapeOutput ?>
        </div>
        <?php
        return ob_get_clean();
    }

    private static function plural($n) {
        $n = (int) $n;
        $ten = $n % 100;
        if ($ten >= 11 && $ten <= 14) {
            return 'песен';
        }
        switch ($n % 10) {
            case 1:  return 'песня';
            case 2:
            case 3:
            case 4:  return 'песни';
        }
        return 'песен';
    }
}
