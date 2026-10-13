/**
 * Genius Sounds — «ваш заказ готов».
 *
 * Готовое забирает расписание, но человек об этом не знает: он мог уйти
 * со страницы сервиса на главную или вовсе закрыть сайт и вернуться
 * назавтра. Поэтому про готовое говорим на любой странице, а не только
 * там, где заказывали.
 *
 * Показываем один раз: как только человек увидел плашку, отметка о
 * прочтении уходит на сервер. По ней потом видно, дошло ли до человека
 * то, за что он заплатил.
 */
(function () {
    'use strict';

    var cfg = window.GS_NEWS || {};
    if (!cfg.restUrl || !cfg.nonce) {
        return;
    }
    // На сайте скрипты собираются в общий пакет, и наш попадает в страницу
    // дважды. Спрашивать сервер два раза за одну загрузку незачем.
    if (window.__gsNewsAsked) {
        return;
    }
    window.__gsNewsAsked = true;

    function api(path, body) {
        return fetch(cfg.restUrl + path, {
            method: body ? 'POST' : 'GET',
            credentials: 'same-origin',
            headers: { 'X-WP-Nonce': cfg.nonce, 'Content-Type': 'application/json' },
            body: body ? JSON.stringify(body) : undefined
        }).then(function (r) { return r.json(); });
    }

    function el(tag, cls, text) {
        var node = document.createElement(tag);
        if (cls) { node.className = cls; }
        if (text) { node.textContent = text; }
        return node;
    }

    function wordForm(n, one, few, many) {
        var a = n % 100;
        var b = n % 10;
        if (a > 10 && a < 20) { return many; }
        if (b === 1) { return one; }
        if (b >= 2 && b <= 4) { return few; }
        return many;
    }

    function show(items) {
        var ready = items.filter(function (i) { return i.status === 'ready'; });
        var failed = items.filter(function (i) { return i.status === 'failed'; });
        if (!ready.length && !failed.length) {
            return;
        }

        var box = el('div', 'gs-news');
        box.setAttribute('role', 'status');

        var head = el('div', 'gs-news__head');
        head.appendChild(el('strong', '', ready.length
            ? (ready.length + ' ' + wordForm(ready.length, 'работа готова', 'работы готовы', 'работ готово'))
            : 'Обработка не удалась'));
        var close = el('button', 'gs-news__close', '×');
        close.type = 'button';
        close.setAttribute('aria-label', 'Закрыть');
        head.appendChild(close);
        box.appendChild(head);

        // Показываем по одной строке на сервис: «Говорящий аватар — 2».
        var byService = {};
        items.forEach(function (item) {
            var key = item.service;
            if (!byService[key]) {
                byService[key] = { name: item.name, url: item.url, ready: 0, failed: 0 };
            }
            byService[key][item.status === 'ready' ? 'ready' : 'failed']++;
        });
        Object.keys(byService).forEach(function (key) {
            var row = byService[key];
            var link = el('a', 'gs-news__row');
            link.href = row.url;
            link.appendChild(el('span', 'gs-news__name', row.name));
            var tail = row.ready
                ? 'готово: ' + row.ready
                : 'не вышло: ' + row.failed + ', деньги вернули';
            link.appendChild(el('span', 'gs-news__count', tail));
            box.appendChild(link);
        });

        document.body.appendChild(box);
        requestAnimationFrame(function () { box.classList.add('is-on'); });

        function dismiss() {
            box.classList.remove('is-on');
            setTimeout(function () {
                if (box.parentNode) { box.parentNode.removeChild(box); }
            }, 260);
        }
        close.addEventListener('click', dismiss);

        // Отметку ставим сразу: плашка на экране — значит, увидел. Если
        // ждать нажатия, человек уйдёт по ссылке, и отметки не будет.
        var byTask = {};
        items.forEach(function (item) {
            if (!byTask[item.service]) { byTask[item.service] = []; }
            byTask[item.service].push(item.task);
        });
        Object.keys(byTask).forEach(function (service) {
            api('lab/seen', { service: service, tasks: byTask[service] }).catch(function () {});
        });
    }

    function check() {
        api('lab/news').then(function (res) {
            if (res && res.success && res.items && res.items.length) {
                show(res.items);
            }
        }).catch(function () {});
    }

    // На странице сервиса скрипт и не подключается: там заказ на виду в
    // истории, и отметка о прочтении от плашки погасила бы в ней пометку
    // «новое». Проверку по разметке держим на случай, если страница
    // сервиса появится без ведома сервера.
    function start() {
        if (!document.getElementById('gs-lab-form')) {
            check();
        }
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
}());
