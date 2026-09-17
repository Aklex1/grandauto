/**
 * Подсказка про загрузку своего фото в нейрохабе.
 *
 * В панели кнопка «Выбрать файлы» выглядит как поле для URL, и по ней не
 * видно, что сюда можно положить собственный снимок и получить результат
 * по своему референсу. Дорисовываем стрелку к самой кнопке и текст рядом
 * с ней — так подсказка привязана к месту действия, а не висит абзацем
 * где-то выше, где её не читают.
 *
 * Разметку нейрохаба не меняем: оборачиваем его же кнопку и вставляем
 * свой блок рядом. Сам ввод файла остаётся его, обработчики не трогаем.
 */
(function () {
    'use strict';

    var SEEN = 'gs_nh_upload_seen';

    var ROWS = [
        { row: 'knImagesRow', input: 'knFile',
          text: 'Можно загрузить своё фото — нейросеть возьмёт с него лицо, позу или стиль.' },
        { row: 'knSingleImageRow', input: 'knSingleFile',
          text: 'Загрузите свой кадр — из него и получится ролик.' }
    ];

    var layouts = [];

    function seen() {
        try { return localStorage.getItem(SEEN) === '1'; } catch (e) { return false; }
    }

    function remember() {
        try { localStorage.setItem(SEEN, '1'); } catch (e) { /* приватный режим */ }
    }

    function arrow() {
        var svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
        svg.setAttribute('class', 'gs-nh-arrow');
        svg.setAttribute('viewBox', '0 0 72 36');
        svg.setAttribute('width', '72');
        svg.setAttribute('height', '36');
        svg.setAttribute('aria-hidden', 'true');
        svg.setAttribute('focusable', 'false');
        svg.innerHTML =
            '<path d="M68 29C52 33 32 29 16 13" fill="none" stroke="currentColor"' +
            ' stroke-width="2.4" stroke-linecap="round"/>' +
            '<path d="M14 11l12 2M14 11l1 12" fill="none" stroke="currentColor"' +
            ' stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/>';
        return svg;
    }

    function decorate(conf) {
        var row = document.getElementById(conf.row);
        var input = document.getElementById(conf.input);
        if (!row || !input || row.querySelector('.gs-nh-upload')) {
            return false;
        }

        var button = row.querySelector('label[for="' + conf.input + '"]');
        if (!button) {
            return false;
        }

        // Оборачиваем кнопку, чтобы стрелка встала рядом с ней, а не
        // уехала на свою строку. Связь label с полем через for сохраняется.
        var box = document.createElement('div');
        box.className = 'gs-nh-upload';
        button.parentNode.insertBefore(box, button);
        box.appendChild(button);
        button.classList.add('gs-nh-upload__btn');

        var point = document.createElement('span');
        point.className = 'gs-nh-upload__point';
        point.appendChild(arrow());

        var text = document.createElement('span');
        text.className = 'gs-nh-upload__text';
        text.textContent = conf.text;
        point.appendChild(text);
        box.appendChild(point);

        if (!seen()) {
            box.classList.add('is-new');
        }

        // Кнопка растянута на всю строку — подсказка встаёт под ней, и
        // стрелка должна показывать вверх, а не вбок. В узком блоке
        // кнопка занимает своё место, и подсказка помещается рядом.
        function layout() {
            var wide = button.offsetWidth > box.offsetWidth * 0.6;
            box.classList.toggle('is-stacked', wide);
        }
        layouts.push(layout);
        layout();
        window.addEventListener('resize', layout);

        function used() {
            box.classList.remove('is-new');
            remember();
        }

        button.addEventListener('click', used);
        input.addEventListener('change', used);
        return true;
    }

    function run() {
        ROWS.forEach(decorate);
        // Строка бывает спрятана в момент разметки, и ширины тогда нулевые.
        // Пересчитываем, пока она не покажется.
        layouts.forEach(function (fn) { fn(); });
    }

    // Строки показа зависят от выбранной модели: нейрохаб их прячет и
    // показывает заново, поэтому проверяем разметку ещё некоторое время
    // после загрузки, а не только один раз.
    function watch() {
        run();
        var left = 40;
        var timer = setInterval(function () {
            run();
            if (--left <= 0) { clearInterval(timer); }
        }, 500);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', watch);
    } else {
        watch();
    }
})();
