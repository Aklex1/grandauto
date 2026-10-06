/**
 * Наставник по индивидуальному проекту: шаги, ввод, оплата.
 *
 * Проект держится на токене в localStorage: школьник возвращается к работе
 * через неделю с того же устройства и видит её на месте. Регистрации на
 * первом шаге нет намеренно — она стоит дороже, чем даёт.
 *
 * Ко всем запросам прикладываем ключ запроса: без него маршрут считает
 * вошедшего гостем.
 *
 * Тариф оплачивается прямо — ссылкой на ЮMoney с посчитанной суммой, без
 * баланса сайта и без входа. За школьный проект платят один раз, обычно с
 * родительской карты и часто с другого устройства: просить сначала завести
 * аккаунт, потом пополнить счёт, потом списать с него — три шага там, где
 * достаточно одного. Вход остаётся, но только ради того, чтобы работа
 * нашлась с любого устройства.
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

    function dmy(stamp) {
        var d = new Date(Number(stamp) * 1000);
        function two(n) { return (n < 10 ? '0' : '') + n; }
        return two(d.getDate()) + '.' + two(d.getMonth() + 1) + '.' + d.getFullYear();
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
        appBox.innerHTML = keepNote() + render();
        bind();
        bindShare();
        syncPicks();
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
        var left = state['запусков_лимит'] - state['запусков_использовано'];
        var done = (state['шаги'] || []).some(function (s) { return s['готов']; });
        var html = '<div class="gs-proekt__bar">'
            + '<strong>Тариф: ' + esc(state['тариф_название']) + '</strong>'
            + '<span>осталось запусков: ' + left + '</span>'
            // Срок доступа виден сразу: иначе о нём узнают в день, когда
            // шаги перестали открываться.
            + (state['доступ_до'] ? '<span>доступ до ' + dmy(state['доступ_до']) + '</span>' : '')
            // Личная ссылка: проект живёт по токену, и без неё работа
            // теряется вместе с историей браузера.
            + '<button type="button" class="gs-proekt__share" data-gs-proekt-share>Ссылка на проект</button>'
            // Готовые шаги одним файлом: в школу сдают документ, а не экран.
            + (done ? '<a class="gs-proekt__doc" href="' + API + 'doc?token='
                + encodeURIComponent(state['токен']) + '">Скачать в Word</a>' : '')
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
                + '<ul>' + (t['шаги'] || []).map(function (step) {
                    return '<li>' + esc(step) + '</li>';
                }).join('') + '</ul>'
                + '<p class="gs-proekt__note">Каждый шаг можно переделать ' + t['переделок']
                + ' раза, доступ до ' + dmy(t['доступ_до']) + '</p>'
                + '<button type="button" class="gs-btn gs-btn--primary" data-pay="' + esc(id)
                + '" data-name="' + esc(t['название']) + '" data-price="' + t['доплата'] + '">'
                + 'Оплатить ' + t['доплата'] + ' ₽</button>'
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
        var label = btn ? (btn.getAttribute('data-name') || '') : '';
        ensureProject().then(function (ready) {
            if (!ready) { return; }
            if (btn) { btn.disabled = true; }
            post('pay', { token: state['токен'], tariff: tariff })
                .then(function (res) {
                    if (res && res.ok && res.link) {
                        // Платим прямо за тариф: сумма в ссылке уже
                        // посчитана, возврат — на эту же страницу с проектом.
                        remember(state['токен']);
                        location.href = res.link;
                        return;
                    }
                    if (res && res['состояние']) { show(res['состояние']); }
                    alert((res && res.message) || 'Не удалось открыть оплату. Повторите.');
                })
                .catch(function () { alert('Не удалось открыть оплату. Повторите.'); })
                .then(function () { if (btn) { btn.disabled = false; } });
        });
    }

    /**
     * Вернулись с оплаты: уведомление от ЮMoney идёт отдельным запросом и
     * доходит за секунды, но не мгновенно. Поэтому не показываем человеку
     * закрытые шаги, а несколько раз спрашиваем состояние.
     */
    function waitForPayment() {
        var tries = 0;
        var note = document.createElement('p');
        note.className = 'gs-proekt__note';
        note.textContent = 'Проверяем оплату…';
        appBox.parentNode.insertBefore(note, appBox);
        (function again() {
            tries++;
            fetch(API + 'state?token=' + encodeURIComponent(stored()),
                  { credentials: 'same-origin', headers: headers() })
                .then(function (r) { return r.ok ? r.json() : null; })
                .then(function (res) {
                    if (res && res['шаги'] && res['тариф'] !== 'free') {
                        note.textContent = 'Оплата прошла: тариф «' + res['тариф_название']
                            + '» открыт. Сохраните ссылку на проект — она ниже.';
                        show(res);
                        return;
                    }
                    if (tries >= 15) {
                        note.textContent = 'Оплата пока не подтвердилась. Это занимает до минуты — '
                            + 'обновите страницу чуть позже, деньги не потеряются.';
                        return;
                    }
                    setTimeout(again, 4000);
                })
                .catch(function () { if (tries < 15) { setTimeout(again, 4000); } });
        })();
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

    /** Адрес, по которому проект открывается с любого устройства. */
    function projectUrl() {
        return location.origin + location.pathname + '?token='
            + encodeURIComponent(state['токен']);
    }

    function bindShare() {
        appBox.querySelectorAll('[data-gs-proekt-share]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var url = projectUrl();
                var done = function () {
                    btn.textContent = 'Ссылка скопирована';
                    setTimeout(function () { btn.textContent = 'Ссылка на проект'; }, 2500);
                };
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(url).then(done, function () { prompt('Ссылка на проект:', url); });
                } else {
                    prompt('Ссылка на проект:', url);
                }
            });
        });
    }

    /**
     * Напоминание сохранить доступ.
     *
     * Показываем тому, кто оплатил и не вошёл: проект держится на токене в
     * этом браузере, и другого способа найти работу у него нет.
     */
    function keepNote() {
        if (!state || state['вошёл'] || state['тариф'] === 'free') { return ''; }
        return '<div class="gs-proekt__keep">'
            + '<strong>Сохраните доступ к оплаченному проекту.</strong> '
            + 'Он открывается по личной ссылке: <code>' + esc(projectUrl()) + '</code> — '
            + 'скопируйте её или <a href="' + (cfg.loginUrl || '/tts-login/') + '" data-gs-auth>войдите</a>, '
            + 'и проект закрепится за аккаунтом: тогда он найдётся с любого устройства.'
            + '</div>';
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

    // Вернулись к работе — показываем её сразу. У вошедшего спрашиваем и
    // без токена: с нового устройства в браузере его нет, а проект есть.
    var token = stored();
    if (token || logged()) {
        fetch(API + 'state' + (token ? '?token=' + encodeURIComponent(token) : ''),
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
        if (new URLSearchParams(location.search).get('paid') && stored()) {
            waitForPayment();
        }
        var pick = unstash();
        if (!pick || !logged()) { return; }
        var btn = root.querySelector('.gs-proekt__buy[data-gs-proekt-pick="' + pick + '"]');
        if (!btn || btn.disabled) { return; }
        btn.closest('.gs-proekt__card').classList.add('is-pick');
        btn.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
})();
