/**
 * Genius Sounds — каталог промтов.
 *
 * Две мелочи: ширина окна для полос во всю страницу и копирование промта.
 */
(function () {
    'use strict';

    /* Секции выходят из контейнера темы отрицательными полями. Считать от
       100vw нельзя: в vw входит полоса прокрутки, и страница едет вбок. */
    function syncView() {
        var w = document.documentElement.clientWidth;
        if (w > 0) {
            document.documentElement.style.setProperty('--pr-view', w + 'px');
        }
    }
    syncView();
    window.addEventListener('resize', syncView);
    window.addEventListener('orientationchange', syncView);

    var btn = document.querySelector('[data-gs-copy]');
    var box = document.querySelector('[data-gs-prompt]');
    if (!btn || !box) {
        return;
    }

    btn.addEventListener('click', function () {
        var text = box.textContent || '';

        function done() {
            var was = btn.textContent;
            btn.textContent = 'Скопировано';
            btn.classList.add('is-done');
            setTimeout(function () {
                btn.textContent = was;
                btn.classList.remove('is-done');
            }, 1800);
        }

        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(done, fallback);
        } else {
            fallback();
        }

        // Без защищённого соединения и в старых браузерах clipboard нет —
        // выделяем текст во временном поле и копируем по старинке.
        function fallback() {
            var ta = document.createElement('textarea');
            ta.value = text;
            ta.setAttribute('readonly', '');
            ta.style.position = 'fixed';
            ta.style.left = '-9999px';
            document.body.appendChild(ta);
            ta.select();
            try {
                document.execCommand('copy');
                done();
            } catch (e) {
                /* ничего не поделать — текст и так виден на странице */
            }
            document.body.removeChild(ta);
        }
    });
}());
