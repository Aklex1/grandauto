/**
 * Лента инструментов в конце статьи.
 *
 * Прокрутка своя, браузерная: стрелки только подталкивают её на видимую
 * ширину, а полоска внизу показывает, где мы в ленте. Пока скрипт не
 * отработал, блок помечен как «не готов» и кнопок не видно — без них
 * лента всё равно листается пальцем и колесом.
 */
(function () {
    'use strict';

    function setup(box) {
        var view = box.querySelector('[data-gs-track]');
        var prev = box.querySelector('[data-gs-prev]');
        var next = box.querySelector('[data-gs-next]');
        var bar = box.querySelector('[data-gs-bar]');
        if (!view || !prev || !next) {
            return;
        }

        function hidden() {
            return view.scrollWidth - view.clientWidth;
        }

        function refresh() {
            var max = hidden();
            // Всё поместилось — листать нечего.
            box.classList.toggle('is-ready', max > 4);
            prev.disabled = view.scrollLeft <= 2;
            next.disabled = view.scrollLeft >= max - 2;
            if (bar) {
                var seen = view.clientWidth / (view.scrollWidth || 1);
                var done = max > 0 ? view.scrollLeft / max : 0;
                bar.style.width = Math.max(12, Math.min(100, seen * 100)) + '%';
                bar.style.transform = 'translateX(' + (done * (100 / Math.max(seen, 0.01) - 100)) + '%)';
            }
        }

        function step(dir) {
            // Шаг — видимая ширина без одной карточки: так последняя
            // карточка предыдущего экрана остаётся зацепкой для глаза.
            var card = view.querySelector('.gs-post-tools__card');
            var by = Math.max(160, view.clientWidth - (card ? card.offsetWidth * 0.5 : 60));
            view.scrollBy({ left: dir * by, behavior: 'smooth' });
        }

        prev.addEventListener('click', function () { step(-1); });
        next.addEventListener('click', function () { step(1); });
        view.addEventListener('scroll', function () {
            window.requestAnimationFrame(refresh);
        }, { passive: true });
        window.addEventListener('resize', refresh);

        // Картинки иконок меняют высоту карточек уже после разбора разметки.
        if (window.ResizeObserver) {
            new ResizeObserver(refresh).observe(view);
        }
        refresh();
    }

    function boot() {
        var boxes = document.querySelectorAll('[data-gs-carousel]');
        for (var i = 0; i < boxes.length; i++) {
            setup(boxes[i]);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
