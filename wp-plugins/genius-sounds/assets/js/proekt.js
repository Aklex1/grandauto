/**
 * Наставник по индивидуальному проекту: шаги, ввод, оплата.
 *
 * Проект держится на токене в localStorage: школьник возвращается к работе
 * через неделю с того же устройства и видит её на месте. Регистрации на
 * первом шаге нет намеренно — она стоит дороже, чем даёт.
 *
 * Ко всем запросам прикладываем ключ запроса. Без него маршрут считает
 * вошедшего гостем: баланс не показывался, а оплата отвечала «войдите»
 * тому, кто уже вошёл, и уводила его в кабинет озвучки.
 *
 * Вход и пополнение — окнами на этой же странице, как в остальных
 * сервисах. Уход на отдельную страницу терял и форму, и человека.
 */
(function () {
    'use strict';

    var root = document.querySelector('[data-gs-proekt]');
    if (!root) {
        return;
    }

    var cfg = window.GS_PROEKT || {};
    var API = cfg.restUrl || '/wp-json/genius-sounds/v1/proekt/';
    var KEY = 'gs_proekt_token';
    var PICK = 'gs_proekt_pick';
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

    /** Выбранный до входа тариф: после возврата человек не ищет кнопку заново. */
    function stash(tariff) {
        try { localStorage.setItem(PICK, tariff || ''); } catch (e) {}
    }
    function unstash() {
        var v = '';
        try { v = localStorage.getItem(PICK) || ''; localStorage.removeItem(PICK); } catch (e) {}
        return v;
    }

    function headers() {
        return { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce || '' };
    }

    function post(path, body) {
        return fetch(API + path, {
            method: 'POST',
            credentials: 'same-origin',
            headers: headers(),
            body: JSON.stringify(body)
        }).then(function (r) { return r.json(); });
    }

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
        });
    }

    function logged() {
        return state ? !!state['вошёл'] : root.getAttribute('data-gs-logged') === '1';
    }

    /** Окно входа на месте; если его на странице нет — штатная страница с возвратом. */
    function openLogin() {
        if (document.getElementById('gs-auth')) {
            var ghost = document.getElementById('gs-proekt-auth');
            if (!ghost) {
                ghost = document.createElement('button');
                ghost.type = 'button';
                ghost.id = 'gs-proekt-auth';
                ghost.setAttribute('data-gs-auth', '');
                ghost.style.display = 'none';
                document.body.appendChild(ghost);
            }
            ghost.click();
            return;
        }
        location.href = cfg.loginUrl || '/tts-login/';
    }

    /** Окно пополнения — то же, что во всех сервисах. */
    function openTopup(message) {
        var opener = root.querySelector('[data-gs-topup]');
        if (message) { alert(message); }
        if (opener && document.getElementById('gs-topup')) {
            opener.click();
            return;
        }
        if (opener) { location.href = opener.getAttribute('href'); }
    }

    function profile() {
        var body = {};
        root.querySelectorAll('[data-gs-proekt-field]').forEach(function (el) {
            body[el.getAttribute('data-gs-proekt-field')] = el.value;
        });
        return body;
    }

    function show(next) {
        state = next;
        if (!state || !state['шаги']) { return; }
        remember(state['токен']);
        startBox.hidden = true;
        appBox.hidden = false;
        appBox.innerHTML = render();
        bind();
        syncBalance();
        syncPicks();
    }

    function syncBalance() {
        var box = root.querySelector('[data-gs-proekt-balance]');
        if (box && state && state['баланс'] !== null && state['баланс'] !== undefined) {
            box.textContent = Number(state['баланс']).toFixed(2) + ' ₽';
        }
    }

    /**
     * Цены на тарифах внизу страницы — после покупки это уже доплата
     * разницы, а не полная цена. Открытый тариф кнопкой не торгуем.
     */
    function syncPicks() {
        if (!state || !state['тарифы']) { return; }
        root.querySelectorAll('.gs-proekt__buy[data-gs-proekt-pick]').forEach(function (btn) {
            var id = btn.getAttribute('data-gs-proekt-pick');
            if (id === 'free') {
                btn.disabled = true;
                btn.textContent = 'Открыт';
                return;
            }
            var t = state['тарифы'][id];
            if (!t || t['доплата'] <= 0) {
                btn.disabled = true;
                btn.textContent = id === state['тариф'] ? 'Ваш тариф' : 'Открыт';
                return;
            }
            btn.disabled = false;
            btn.setAttribute('data-price', t['доплата']);
            btn.textContent = 'Оплатить ' + t['доплата'] + ' ₽';
        });
    }

    function render() {
        var left = state['запросов_лимит'] - state['запросов_использовано'];
        var html = '<div class="gs-proekt__bar">'
            + '<strong>Тариф: ' + esc(state['тариф_название']) + '</strong>'
            + '<span>осталось запросов: ' + left + ' из ' + state['запросов_лимит'] + '</span>'
            + (state['баланс'] === null ? ''
                : '<span>баланс: ' + Number(state['баланс']).toFixed(2) + ' ₽</span>')
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
                + '<button type="button" class="gs-btn gs-btn--primary" data-pay="' + esc(id)
                + '" data-name="' + esc(t['название']) + '" data-price="' + t['доплата'] + '">'
                + (state['вошёл'] ? 'Открыть за ' + t['доплата'] + ' ₽' : 'Войти и открыть') + '</button>'
                + '</div>';
        });
        html += '</div></div>';
        return html;
    }

    /** Проект нужен и для оплаты: тариф открывается именно проекту. */
    function ensureProject() {
        if (state && state['токен']) {
            return Promise.resolve(true);
        }
        return post('start', profile()).then(function (res) {
            if (res && res['шаги']) { show(res); return true; }
            alert('Не получилось начать проект. Повторите.');
            return false;
        }).catch(function () {
            alert('Не получилось начать проект. Повторите.');
            return false;
        });
    }

    function payTariff(tariff, btn, andRunFirst) {
        if (!logged()) {
            stash(tariff);
            openLogin();
            return;
        }
        var label = btn ? (btn.getAttribute('data-name') || '') : '';
        ensureProject().then(function (ready) {
            if (!ready) { return; }
            var t = (state['тарифы'] || {})[tariff] || {};
            var sum = Number(t['доплата'] || (btn && btn.getAttribute('data-price')) || 0);
            if (sum > 0 && !confirm('Списать ' + sum + ' ₽ с баланса сайта и открыть тариф «'
                    + (t['название'] || label || tariff) + '»?')) {
                return;
            }
            if (btn) { btn.disabled = true; }
            post('pay', { token: state['токен'], tariff: tariff })
                .then(function (res) {
                    if (res && res.ok) {
                        if (res['состояние']) { show(res['состояние']); }
                        if (andRunFirst) { runFirstStep(); }
                        return;
                    }
                    if (res && res.need_login) {
                        stash(tariff);
                        openLogin();
                        return;
                    }
                    // Денег не хватило — открываем ту же форму пополнения,
                    // что и в остальных сервисах, а не уводим со страницы.
                    if (res && res.need_topup) {
                        openTopup(res.message);
                        return;
                    }
                    alert((res && res.message) || 'Не удалось открыть тариф.');
                })
                .catch(function () { alert('Не удалось открыть тариф. Повторите.'); })
                .then(function () { if (btn) { btn.disabled = false; } });
        });
    }

    /** Первый шаг запускаем сами: человек уже нажал кнопку, второй раз незачем. */
    function runFirstStep() {
        var first = appBox.querySelector('[data-run="tema"]');
        if (!first) { return; }
        var done = (state['шаги'] || []).some(function (s) { return s['готов']; });
        first.closest('details').open = true;
        appBox.scrollIntoView({ behavior: 'smooth', block: 'start' });
        if (!done) { first.click(); }
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
                payTariff(btn.getAttribute('data-pay'), btn, false);
            });
        });
    }

    // Кнопки на тарифах внизу страницы: работают и до того, как проект начат.
    root.querySelectorAll('[data-gs-proekt-pick]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var pick = btn.getAttribute('data-gs-proekt-pick');
            if (!pick || pick === 'free') {
                if (state) { runFirstStep(); return; }
                startBox.scrollIntoView({ behavior: 'smooth', block: 'center' });
                var field = startBox.querySelector('[data-gs-proekt-field="предметы"]');
                if (field) { field.focus(); }
                return;
            }
            payTariff(pick, btn, true);
        });
    });

    root.querySelector('[data-gs-proekt-go]').addEventListener('click', function () {
        var button = root.querySelector('[data-gs-proekt-go]');
        button.disabled = true;
        button.textContent = 'Подбираю темы…';
        post('start', profile())
            .then(function (res) {
                show(res);
                runFirstStep();
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
        fetch(API + 'state?token=' + encodeURIComponent(token),
              { credentials: 'same-origin', headers: headers() })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (res) { if (res && res['шаги']) { show(res); } })
            .catch(function () { /* проекта нет — останется лендинг */ })
            .then(afterLogin);
    } else {
        afterLogin();
    }

    /** Человек уходил входить ради тарифа — доводим его до той же кнопки. */
    function afterLogin() {
        var pick = unstash();
        if (!pick || !logged()) { return; }
        var btn = root.querySelector('.gs-proekt__buy[data-gs-proekt-pick="' + pick + '"]');
        if (!btn || btn.disabled) { return; }
        btn.closest('.gs-proekt__card').classList.add('is-pick');
        btn.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
})();
