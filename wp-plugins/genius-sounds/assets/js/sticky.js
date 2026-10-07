/**
 * Липкая панель со ссылкой на сервис.
 *
 * Показываем не сразу: панель, выехавшая на первом экране, воспринимается
 * как всплывающая реклама, а появившаяся после нескольких абзацев — как
 * подсказка. Закрытие запоминаем на сутки, чтобы вернувшийся читатель не
 * встречал её снова.
 */
(function () {
    'use strict';

    var box = document.getElementById('gs-sticky');
    if (!box) { return; }

    var key = box.getAttribute('data-gs-sticky-key') || 'gs-sticky';
    var after = parseInt(box.getAttribute('data-gs-sticky-after'), 10) || 600;
    var closer = box.querySelector('.gs-sticky__x');

    function hiddenUntil() {
        try {
            var until = parseInt(localStorage.getItem(key) || '0', 10);
            return until > Date.now();
        } catch (e) {
            return false;
        }
    }

    if (hiddenUntil()) { return; }

    // Снизу же стоит плашка согласия на куки от стороннего плагина.
    // Её стили мы правим в cookie.css, но высоту панели CSS не знает —
    // подставляем сами, иначе плашка снова ляжет поверх кнопки.
    function lift(on) {
        var h = on ? box.offsetHeight + 10 : 0;
        document.documentElement.style.setProperty('--gs-cookie-lift', h + 'px');
    }

    function onScroll() {
        var show = window.scrollY > after;
        if (show === !box.hidden) { return; }
        box.hidden = !show;
        // Класс ставим следующим кадром, иначе переход не отрабатывает:
        // элемент только что перестал быть hidden и анимировать нечего.
        if (show) {
            requestAnimationFrame(function () { box.classList.add('is-on'); lift(true); });
        } else {
            box.classList.remove('is-on');
            lift(false);
        }
    }

    window.addEventListener('scroll', onScroll, { passive: true });
    window.addEventListener('resize', function () {
        if (!box.hidden) { lift(true); }
    }, { passive: true });
    onScroll();

    if (closer) {
        closer.addEventListener('click', function () {
            box.classList.remove('is-on');
            box.hidden = true;
            lift(false);
            window.removeEventListener('scroll', onScroll);
            try {
                localStorage.setItem(key, String(Date.now() + 24 * 60 * 60 * 1000));
            } catch (e) {}
        });
    }
})();
