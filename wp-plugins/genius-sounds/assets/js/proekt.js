/**
 * Наставник по индивидуальному проекту: шаги, ввод, оплата.
 *
 * Проект держится на токене в localStorage: школьник возвращается к работе
 * через неделю с того же устройства и видит её на месте. Регистрации нет
 * намеренно — на этом шаге она стоит дороже, чем даёт.
 */
(function () {
    'use strict';

    var root = document.querySelector('[data-gs-proekt]');
    if (!root) {
        return;
    }

    var API = '/wp-json/genius-sounds/v1/proekt/';
    var KEY = 'gs_proekt_token';
    var startBox = root.querySelector('[data-gs-proekt-start]');
    var appBox = root.querySelector('[data-gs-proekt-app]');
    var state = null;

    function remember(token) {
        try { localStorage.setItem(KEY, token); } catch (e) { /* приватный режим */ }
    }
    function stored() {
        var fromUrl = new URLSearchParams(location.search).get('token');
        if (fromUrl) { return fromUrl; }
        try { return localStorage.getItem(KEY) || ''; } catch (e) { return ''; }
    }

    function post(path, body) {
        return fetch(API + path, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body)
        }).then(function (r) { return r.json(); });
    }

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
        });
    }

    function show(next) {
        state = next;
        if (!state || !state['шаги']) { return; }
        remember(state['токен']);
        startBox.hidden = true;
        appBox.hidden = false;
        appBox.innerHTML = render();
        bind();
    }

    function render() {
        var left = state['запросов_лимит'] - state['запросов_использовано'];
        var html = '<div class="gs-proekt__bar">'
            + '<strong>Тариф: ' + esc(state['тариф_название']) + '</strong>'
            + '<span>осталось запросов: ' + left + ' из ' + state['запросов_лимит'] + '</span>'
            + '</div><div class="gs-proekt__steps-live">';

        state['шаги'].forEach(function (s) {
            var cls = 'gs-proekt__step' + (s['закрыт'] ? ' is-locked' : '') + (s['готов'] ? ' is-done' : '');
            html += '<details class="' + cls + '" data-stage="' + esc(s.id) + '">'
                + '<summary>' + esc(s['title'])
                + (s['готов'] ? ' <span class="gs-proekt__tick">готово</span>' : '')
                + (s['закрыт'] ? ' <span class="gs-proekt__lock">в тарифе выше</span>' : '')
                + '</summary><div class="gs-proekt__body">';
            if (s['подсказка']) {
                html += '<p class="gs-proekt__note">' + esc(s['подсказка']) + '</p>';
            }
            if (s['текст']) {
                html += '<div class="gs-proekt__answer">' + esc(s['текст']).replace(/\n/g, '<br>') + '</div>';
            }
            if (!s['закрыт']) {
                html += '<textarea class="gs-proekt__input" rows="4" placeholder="Что добавить от себя"></textarea>'
                    + '<button type="button" class="gs-btn gs-btn--primary" data-run="' + esc(s.id) + '">'
                    + (s['готов'] ? 'Сделать заново' : 'Запустить шаг') + '</button>';
            } else {
                html += '<button type="button" class="gs-btn gs-btn--ghost" data-pay="'
                    + esc(s['минимальный_тариф']) + '">Открыть этот шаг</button>';
            }
            html += '</div></details>';
        });

        html += '</div><div class="gs-proekt__pay"><h3>Открыть больше шагов</h3><div class="gs-proekt__cards">';
        Object.keys(state['тарифы']).forEach(function (id) {
            var t = state['тарифы'][id];
            if (t['доплата'] <= 0) { return; }
            html += '<div class="gs-proekt__card gs-proekt__card--tariff">'
                + '<h3>' + esc(t['название']) + '</h3>'
                + '<p class="gs-proekt__price">' + t['доплата'] + ' ₽</p>'
                + '<p class="gs-proekt__note">' + t['запросов'] + ' запросов, доступ ' + t['дней'] + ' дней</p>'
                + '<button type="button" class="gs-btn gs-btn--primary" data-pay="' + esc(id) + '">Оплатить</button>'
                + '</div>';
        });
        html += '</div></div>';
        return html;
    }

    function bind() {
        appBox.querySelectorAll('[data-run]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var box = btn.closest('.gs-proekt__body');
                var input = box.querySelector('.gs-proekt__input');
                btn.disabled = true;
                btn.textContent = 'Наставник думает…';
                post('run', { token: state['токен'], stage: btn.getAttribute('data-run'),
                              input: input ? input.value : '' })
                    .then(function (res) {
                        if (res && res['состояние']) { show(res['состояние']); }
                        if (res && !res.ok && res.message) { alert(res.message); }
                    })
                    .catch(function () { alert('Не получилось связаться с наставником. Повторите.'); })
                    .then(function () { btn.disabled = false; });
            });
        });

        appBox.querySelectorAll('[data-pay]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                post('pay', { token: state['токен'], tariff: btn.getAttribute('data-pay') })
                    .then(function (res) {
                        if (res && res['ссылка']) { location.href = res['ссылка']; }
                        else { alert('Не удалось открыть оплату. Напишите нам.'); }
                    });
            });
        });
    }

    root.querySelector('[data-gs-proekt-go]').addEventListener('click', function (btn) {
        var body = {};
        root.querySelectorAll('[data-gs-proekt-field]').forEach(function (el) {
            body[el.getAttribute('data-gs-proekt-field')] = el.value;
        });
        var button = root.querySelector('[data-gs-proekt-go]');
        button.disabled = true;
        button.textContent = 'Подбираю темы…';
        post('start', body)
            .then(function (res) {
                show(res);
                // Сразу запускаем первый шаг: человек нажал «подобрать темы»,
                // и ждать от него второго нажатия незачем.
                var first = appBox.querySelector('[data-run="tema"]');
                if (first) { first.closest('details').open = true; first.click(); }
            })
            .catch(function () { alert('Не получилось начать. Повторите.'); })
            .then(function () {
                button.disabled = false;
                button.textContent = 'Получить 5 тем бесплатно';
            });
    });

    // Вернулись к работе — показываем её сразу.
    var token = stored();
    if (token) {
        fetch(API + 'state?token=' + encodeURIComponent(token))
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (res) { if (res && res['шаги']) { show(res); } })
            .catch(function () { /* проекта нет — останется лендинг */ });
    }
})();
