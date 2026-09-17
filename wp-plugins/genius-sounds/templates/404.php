<?php
/**
 * Шаблон 404.
 *
 * Тема отдаёт на 404 только заголовок с одной строкой текста, и вставить
 * блоки в неё некуда. Поэтому берём страницу целиком: шапка и подвал
 * остаются темины, между ними — наш блок.
 */

if (!defined('ABSPATH')) {
    exit;
}

get_header();
?>
<main id="page-content" class="l-main">
    <section class="l-section">
        <div class="l-section-h i-cf">
            <?php echo GS_404::render(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
        </div>
    </section>
</main>
<?php
get_footer();
