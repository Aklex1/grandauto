/**
 * Подъём плашки о куках над нашими нижними панелями.
 *
 * Плашка согласия от стороннего плагина стоит в нижнем углу, и там же
 * у нас появляются липкие элементы: панель со ссылкой на сервис в
 * статьях, полоса в каталог промтов на страницах сервисов. Плашка
 * ложилась на них и закрывала кнопку — человек видел призыв, а нажать
 * не мог.
 *
 * Высоту панели CSS знать не может, поэтому считаем её здесь и отдаём
 * в переменную, которую cookie.css прибавляет к отступу снизу. Раньше
 * это делал скрипт липкой панели, но он грузится не везде, и на
 * страницах сервисов подъём оставался нулевым.
 *
 * Учитываем только то, что действительно прижато к низу окна: боковой
 * столбик на широком экране плашке не мешает, поднимать её не из-за
 * чего.
 */
(function () {
    'use strict';

    var SEL = '#gs-sticky, .gs-sticky, [data-gs-band]';
    var GAP = 10;
    var last = -1;
    var queued = false;

    function calc() {
        queued = false;
        var lift = 0;
        var nodes = document.querySelectorAll(SEL);
        for (var i = 0; i < nodes.length; i++) {
            var el = nodes[i];
            if (el.hidden) {
                continue;
            }
            var s = window.getComputedStyle(el);
            if (s.display === 'none' || s.visibility === 'hidden' || parseFloat(s.opacity) === 0) {
                continue;
            }
            var r = el.getBoundingClientRect();
            if (r.height < 10 || r.bottom < window.innerHeight - 24) {
                continue;
            }
            lift = Math.max(lift, Math.round(window.innerHeight - r.top) + GAP);
        }
        if (lift !== last) {
            last = lift;
            document.documentElement.style.setProperty('--gs-cookie-lift', lift + 'px');
        }
    }

    function schedule() {
        if (!queued) {
            queued = true;
            window.requestAnimationFrame(calc);
        }
    }

    schedule();
    window.addEventListener('scroll', schedule, { passive: true });
    window.addEventListener('resize', schedule);
    window.addEventListener('load', schedule);
    // Панели появляются с переходом: пока он идёт, высота ещё не та.
    document.addEventListener('transitionend', schedule, true);

    // Закрыть панель можно и не прокручивая страницу — тогда ни одно из
    // событий выше не сработает.
    if ('MutationObserver' in window) {
        var mo = new MutationObserver(schedule);
        mo.observe(document.body, {
            childList: true,
            subtree: true,
            attributes: true,
            attributeFilter: ['class', 'hidden', 'style']
        });
    }
}());
