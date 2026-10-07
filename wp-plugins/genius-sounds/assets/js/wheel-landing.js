/**
 * Genius Sounds — сравнение «до и после» на посадочной примерки дисков.
 *
 * Разделитель двигает обычный input[type=range], растянутый прозрачным слоем
 * поверх карточки: мышь, палец и стрелки с клавиатуры работают сами, без
 * обработки указателей вручную. Сценарию остаётся переложить значение в
 * переменную --wl-split, которой обрезается верхний кадр.
 *
 * Без сценария картинка всё равно поделена пополам — значение по умолчанию
 * задано в стилях.
 */
(function () {
    'use strict';

    var boxes = document.querySelectorAll('[data-gs-compare]');
    if (!boxes.length) {
        return;
    }

    Array.prototype.forEach.call(boxes, function (box) {
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
}());
