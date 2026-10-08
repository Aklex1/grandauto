/**
 * Поведение полосы «выбрать другой кадр».
 *
 * Показываем не сразу: человек должен сначала посмотреть готовые
 * подборки, иначе предложение уйти выглядит так, будто мы сами в своих
 * подборках не уверены. Закрыл — не показываем неделю.
 *
 * И главное: пока на экране форма, полосы нет. На телефоне она стоит
 * ровно там же, где кнопка «Снять кадр», и перекрывать главную кнопку
 * страницы ради ссылки в соседний раздел — плохой размен.
 */
(function () {
    'use strict';

    var KEY = 'gs_promts_band_off';
    var WEEK = 7 * 24 * 60 * 60 * 1000;

    var band = document.querySelector('[data-gs-band]');
    if (!band) {
        return;
    }

    try {
        var until = parseInt(window.localStorage.getItem(KEY), 10);
        if (until && until > Date.now()) {
            return;
        }
    } catch (e) {
        /* приватный режим — просто показываем */
    }

    var formOnScreen = false;
    var scrolled = false;

    function sync() {
        var show = scrolled && !formOnScreen;
        if (show === !band.hidden) {
            return;
        }
        if (show) {
            band.hidden = false;
            document.body.classList.add('gs-band-on');
            // Перерисовка между снятием hidden и классом: иначе переход
            // не проигрывается и полоса появляется рывком.
            window.requestAnimationFrame(function () { band.classList.add('is-on'); });
        } else {
            band.classList.remove('is-on');
            document.body.classList.remove('gs-band-on');
            window.setTimeout(function () {
                if (!band.classList.contains('is-on')) { band.hidden = true; }
            }, 300);
        }
    }

    function onScroll() {
        scrolled = window.pageYOffset > 500;
        sync();
    }
    window.addEventListener('scroll', onScroll, { passive: true });
    onScroll();

    var form = document.getElementById('gs-lab-form');
    if (form && 'IntersectionObserver' in window) {
        new IntersectionObserver(function (entries) {
            formOnScreen = entries[0].isIntersecting;
            sync();
        }, { rootMargin: '0px 0px -40% 0px' }).observe(form);
    }

    var close = band.querySelector('[data-gs-band-close]');
    if (close) {
        close.addEventListener('click', function () {
            band.classList.remove('is-on');
            document.body.classList.remove('gs-band-on');
            window.setTimeout(function () { band.hidden = true; }, 300);
            try {
                window.localStorage.setItem(KEY, String(Date.now() + WEEK));
            } catch (e) {
                /* не запомнили — покажем в следующий раз */
            }
        });
    }
}());
