<?php
/**
 * Блог в стилистике микросервисов: тёмная тема, карточки постов, читаемая статья
 * и блок перелинковки на инструменты в конце каждого материала.
 *
 * Сам шаблон темы не трогаем — перекрашиваем её разметку и дописываем блок
 * ссылок фильтром the_content.
 */

if (!defined('ABSPATH')) {
    exit;
}

class GS_Blog {

    const OPT_ENABLED = 'gs_blog_restyle';

    public static function boot() {
        add_filter('body_class', array(__CLASS__, 'body_class'));
        add_filter('the_content', array(__CLASS__, 'append_tools_block'), 20);
    }

    public static function enabled() {
        return (string) get_option(self::OPT_ENABLED, '1') === '1';
    }

    /**
     * Список постов: и архивы, и страница-витрина блога на WPBakery.
     */
    public static function is_blog_list() {
        // Страницу «Нейросети» её плагин отдаёт под видом блога —
        // стили ленты постов ей только мешают.
        if (class_exists('GS_Links') && GS_Links::is_neurohub()) {
            return false;
        }
        if (is_home() || is_archive() || is_category() || is_tag()) {
            return true;
        }
        global $post;
        if ($post instanceof WP_Post && $post->post_type === 'page' && $post->post_name === 'blog') {
            return true;
        }
        return false;
    }

    public static function is_single_post() {
        return is_singular('post');
    }

    public static function body_class($classes) {
        if (is_admin() || !self::enabled()) {
            return $classes;
        }
        if (self::is_single_post()) {
            $classes[] = 'gs-post-page';
            $classes[] = 'gs-chrome';
        } elseif (self::is_blog_list()) {
            $classes[] = 'gs-blog-page';
            $classes[] = 'gs-chrome';
        }
        return $classes;
    }

    /**
     * В конце статьи — ссылки на инструменты. Это и полезно читателю,
     * и раздаёт вес с растущего блога на посадочные страницы.
     */
    public static function append_tools_block($content) {
        if (is_admin() || !self::enabled() || !self::is_single_post() || !in_the_loop() || !is_main_query()) {
            return $content;
        }
        return $content . self::tools_block();
    }

    public static function tools_block() {
        $tools = array(
            array(
                'url'   => GS_Pages::get_studio_url(),
                'title' => 'Генератор звуков и спецэффектов',
                'text'  => 'Опишите звук словами — нейросеть соберёт готовый эффект в MP3.',
                'cta'   => 'Создать звук',
            ),
            array(
                'url'   => GS_Catalog::base_url(),
                'title' => 'Каталог звуков',
                'text'  => 'Больше 12 000 готовых звуков в 945 категориях — слушайте и скачивайте бесплатно.',
                'cta'   => 'Открыть каталог',
            ),
        );

        foreach (GS_Lab::available_services() as $service) {
            $tools[] = array(
                'url'   => GS_Lab::get_url($service['id']),
                'title' => $service['menu'],
                'text'  => $service['lead'],
                'cta'   => $service['menu'],
            );
        }

        ob_start();
        ?>
        <aside class="gs-post-tools">
            <h2 class="gs-post-tools__title">Попробуйте инструменты Genius-bot</h2>
            <div class="gs-post-tools__grid">
                <?php foreach ($tools as $tool): ?>
                    <article class="gs-post-tools__card">
                        <h3><a href="<?php echo esc_url($tool['url']); ?>"><?php echo esc_html($tool['title']); ?></a></h3>
                        <p><?php echo esc_html($tool['text']); ?></p>
                        <a class="gs-post-tools__cta" href="<?php echo esc_url($tool['url']); ?>"><?php echo esc_html($tool['cta']); ?></a>
                    </article>
                <?php endforeach; ?>
            </div>
        </aside>
        <?php
        return ob_get_clean();
    }
}
