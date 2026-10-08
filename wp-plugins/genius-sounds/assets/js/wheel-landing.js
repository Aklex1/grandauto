/**
 * Genius Sounds — посадочная примерки дисков.
 *
 * Две задачи: сравнение «до и после» и встроенный инструмент.
 */
(function () {
    'use strict';

    /* ------------------------------------------------------------------
     * Ширина окна без полосы прокрутки
     *
     * Секции выходят из контейнера темы отрицательными полями. Считать их
     * от 100vw нельзя: в vw входит полоса прокрутки, и страница начинает
     * ездить вбок на ширину полосы. clientWidth её не включает.
     * ---------------------------------------------------------------- */
    function syncView() {
        var w = document.documentElement.clientWidth;
        if (w > 0) {
            document.documentElement.style.setProperty('--wl-view', w + 'px');
        }
    }
    syncView();
    window.addEventListener('resize', syncView);
    window.addEventListener('orientationchange', syncView);

    /* ------------------------------------------------------------------
     * Сравнение «до и после»
     *
     * Разделитель двигает обычный input[type=range], растянутый прозрачным
     * слоем поверх карточки: мышь, палец и стрелки работают сами. Сценарию
     * остаётся переложить значение в переменную --wl-split, которой
     * обрезается верхний кадр. Без сценария кадр поделён пополам — значение
     * по умолчанию задано в стилях.
     * ---------------------------------------------------------------- */
    Array.prototype.forEach.call(document.querySelectorAll('[data-gs-compare]'), function (box) {
        var input = box.querySelector('[data-gs-compare-input]');
        if (!input) {
            return;
        }

        function apply() {
            var value = parseFloat(input.value);
            if (isNaN(value)) {
                value = 50;
            }
            box.style.setProperty('--wl-split', value + '%');
            input.setAttribute('aria-valuetext', 'показано ' + Math.round(value)
                + '% исходного кадра');
        }

        input.addEventListener('input', apply);
        input.addEventListener('change', apply);
        apply();
    });

    /* ------------------------------------------------------------------
     * Встроенный инструмент
     *
     * Инструмент рисует отдельный плагин и умеет работать только по своему
     * адресу: там он открывает сессию, подключает свои стили и сценарии и
     * принимает оплату. Поэтому вставляем его как есть, своей страницей, а
     * не копией разметки — тогда и платежи, и личный кабинет, и история
     * остаются ровно теми же, без единой правки в чужом плагине.
     *
     * Рамка и страница на одном домене, так что высоту берём прямо из
     * вложенного документа и подгоняем — полосы прокрутки внутри не будет.
     * Если дотянуться не вышло, остаётся запасная ссылка под рамкой.
     * ---------------------------------------------------------------- */
    var frame = document.querySelector('[data-gs-tool-frame]');
    if (!frame) {
        return;
    }

    var shell = frame.closest('[data-gs-tool]') || frame.parentNode;
    var timer = null;

    function innerDoc() {
        try {
            return frame.contentDocument || null;
        } catch (e) {
            return null; // другой домен — высоту не узнать
        }
    }

    var MIN_H = 260;

    /**
     * Подогнать высоту рамки под содержимое.
     *
     * Мерить «как есть» нельзя: внутри рамки documentElement растянут на всю
     * её высоту, поэтому scrollHeight никогда не окажется меньше текущей —
     * рамка росла бы и не сжималась обратно. Поэтому на время замера
     * схлопываем её. Сжатие, замер и установка идут одним куском без
     * перерисовки, так что мигания не видно; положение прокрутки
     * возвращаем на случай, если браузер решит его поправить.
     */
    function fit() {
        var doc = innerDoc();
        if (!doc || !doc.body) {
            return false;
        }
        var keep = window.pageYOffset;
        var prev = frame.style.height;

        frame.style.height = '0px';
        var h = Math.max(
            doc.body.scrollHeight,
            doc.documentElement ? doc.documentElement.scrollHeight : 0
        );

        if (!(h > 0)) {
            frame.style.height = prev;
            return false;
        }
        frame.style.height = Math.max(h, MIN_H) + 'px';
        if (window.pageYOffset !== keep) {
            window.scrollTo(0, keep);
        }
        return true;
    }

    /* Разметка инструмента меняется часто — замер не чаще раза в кадр. */
    var queued = false;
    function fitSoon() {
        if (queued) {
            return;
        }
        queued = true;
        window.requestAnimationFrame(function () {
            queued = false;
            fit();
        });
    }

    frame.addEventListener('load', function () {
        if (shell) {
            shell.setAttribute('data-gs-tool-state', fit() ? 'ready' : 'blocked');
        }
        // Внутри инструмента разметка меняется по ходу работы: загрузили
        // фотографию, открылись настройки, пришёл результат. Следим за
        // высотой, пока страница открыта.
        var doc = innerDoc();
        if (doc && window.MutationObserver) {
            new MutationObserver(fitSoon).observe(doc.documentElement, {
                childList: true,
                subtree: true,
                attributes: true,
            });
        }
        if (timer) {
            clearInterval(timer);
        }
        timer = setInterval(fit, 1200);
    });

    frame.addEventListener('error', function () {
        if (shell) {
            shell.setAttribute('data-gs-tool-state', 'blocked');
        }
    });

    window.addEventListener('resize', fitSoon);
}());
