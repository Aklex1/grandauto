/**
 * Юридический блок: разбор, оплата, готовый документ, счётчик срока.
 *
 * Порядок на странице такой же, как в подарочных песнях, и по той же
 * причине: человек из рекламы не станет платить за то, чего не видел.
 * Сначала бесплатный разбор его ситуации, потом оплата через ЮMoney, потом
 * документ. Номер заказа держим в браузере — с оплаты человек возвращается
 * уже другой страницей, и заказ иначе теряется.
 *
 * Счётчик срока — главное на странице про приказ: человек видит не «десять
 * дней по закону», а сколько осталось именно у него.
 */
(function () {
    'use strict';

    var root = document.querySelector('[data-gs-legal]');
    if (!root) { return; }

    var cfg = window.GS_LEGAL || {};
    var api = cfg.restUrl || '/wp-json/genius-sounds/v1/';
    var STORE = 'gs_legal_order';

    /* ------------------------------------------------------------------ */
    /* Счётчик срока                                                       */
    /* ------------------------------------------------------------------ */

    var got = document.getElementById('gs-legal-got');
    var left = document.getElementById('gs-legal-left');

    function plural(n, forms) {
        var mod100 = n % 100, mod10 = n % 10;
        if (mod100 > 4 && mod100 < 21) { return forms[2]; }
        if (mod10 === 1) { return forms[0]; }
        if (mod10 > 1 && mod10 < 5) { return forms[1]; }
        return forms[2];
    }

    function countDeadline() {
        if (!got || !left) { return; }
        if (!got.value) { left.textContent = ''; left.className = 'gs-legal-deadline__out'; return; }

        var start = new Date(got.value + 'T00:00:00');
        if (isNaN(start.getTime())) { return; }
        var today = new Date();
        today.setHours(0, 0, 0, 0);

        // Десять календарных дней со дня получения: день получения не
        // считается, отсчёт идёт от следующего.
        var deadline = new Date(start.getTime());
        deadline.setDate(deadline.getDate() + 10);
        var days = Math.round((deadline - today) / 86400000);

        if (days > 1) {
            left.textContent = 'Осталось ' + days + ' ' + plural(days, ['день', 'дня', 'дней']) +
                ' — до ' + deadline.toLocaleDateString('ru-RU') + '. Успеваем.';
            left.className = 'gs-legal-deadline__out is-ok';
        } else if (days >= 0) {
            left.textContent = days === 0
                ? 'Сегодня последний день. Подавайте возражение сегодня.'
                : 'Остался один день — до ' + deadline.toLocaleDateString('ru-RU') + '.';
            left.className = 'gs-legal-deadline__out is-warn';
        } else {
            left.textContent = 'Срок прошёл ' + Math.abs(days) + ' ' +
                plural(Math.abs(days), ['день', 'дня', 'дней']) + ' назад. ' +
                'Добавим заявление о восстановлении срока — оно входит в цену.';
            left.className = 'gs-legal-deadline__out is-warn';
        }
    }

    if (got) {
        got.addEventListener('change', countDeadline);
        got.addEventListener('input', countDeadline);
    }

    /* ------------------------------------------------------------------ */
    /* Общее                                                               */
    /* ------------------------------------------------------------------ */

    var send = document.getElementById('gs-legal-send');
    var status = document.getElementById('gs-legal-status');
    var status2 = document.getElementById('gs-legal-status2');
    var reviewBox = document.getElementById('gs-legal-review');
    var reviewText = document.getElementById('gs-legal-review-text');
    var payBox = document.getElementById('gs-legal-pay');
    var doneBox = document.getElementById('gs-legal-done');
    var partsBox = document.getElementById('gs-legal-parts');
    var order = '';
    var timer = null;

    if (!send || !status) { return; }

    function goal(name) {
        try {
            if (typeof window.ym === 'function' && cfg.metrika) {
                window.ym(cfg.metrika, 'reachGoal', name);
            }
        } catch (e) {}
    }

    function say(node, text, kind) {
        if (!node) { return; }
        node.textContent = text || '';
        node.className = 'gs-legal-note' + (kind ? ' is-' + kind : '');
    }

    function value(id) {
        var el = document.getElementById(id);
        return el ? String(el.value || '').trim() : '';
    }

    function plan() {
        var el = document.querySelector('input[name="gs-legal-plan"]:checked');
        return el ? parseInt(el.value, 10) : 490;
    }

    function remember(id) {
        order = id;
        try { localStorage.setItem(STORE, id); } catch (e) {}
    }

    function recall() {
        var fromUrl = new URLSearchParams(location.search).get('order');
        if (fromUrl) { return fromUrl; }
        try { return localStorage.getItem(STORE) || ''; } catch (e) { return ''; }
    }

    function post(path, body) {
        return fetch(api + path, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body)
        }).then(function (r) { return r.json().catch(function () { return {}; }); });
    }

    /* ------------------------------------------------------------------ */
    /* Шаг 1: бесплатный разбор                                            */
    /* ------------------------------------------------------------------ */

    var started = false;
    var story = document.getElementById('gs-legal-story');
    if (story) {
        story.addEventListener('input', function () {
            if (!started) { started = true; goal('legal_form_start'); }
        });
    }

    send.addEventListener('click', function () {
        var text = value('gs-legal-story');
        if (text.length < 30) {
            say(status, 'Опишите ситуацию подробнее — по двум словам документ не собрать.', 'err');
            if (story) { story.focus(); }
            return;
        }

        send.disabled = true;
        say(status, 'Разбираем ситуацию, это займёт полминуты…');

        post('legal/start', {
            page: value('gs-legal-page'),
            story: text,
            contact: value('gs-legal-contact'),
            plan: plan()
        }).then(function (data) {
            send.disabled = false;
            if (data && data.ok) {
                say(status, '');
                remember(data.order);
                goal('legal_review');
                showReview(data.review);
            } else {
                say(status, (data && data.message) ? data.message : 'Не получилось разобрать ситуацию.', 'err');
            }
        }).catch(function () {
            send.disabled = false;
            say(status, 'Сеть не отвечает. Попробуйте ещё раз.', 'err');
        });
    });

    function showReview(html) {
        if (!reviewBox || !reviewText) { return; }
        reviewText.innerHTML = html || '';
        reviewBox.hidden = false;
        buildPay();
        reviewBox.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    /* ------------------------------------------------------------------ */
    /* Шаг 2: оплата                                                       */
    /* ------------------------------------------------------------------ */

    function buildPay() {
        if (!payBox) { return; }
        payBox.innerHTML = '';
        [
            { sum: 490, note: 'один документ' },
            { sum: 990, note: 'комплект из трёх документов' }
        ].forEach(function (item) {
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'gs-legal-btn' + (item.sum === 990 ? '' : ' gs-legal-btn--ghost');
            btn.textContent = 'Оплатить ' + item.sum + ' ₽ — ' + item.note;
            btn.addEventListener('click', function () { pay(item.sum, btn); });
            payBox.appendChild(btn);
        });
    }

    function pay(sum, btn) {
        if (!order) { return; }
        btn.disabled = true;
        say(status2, 'Открываем оплату…');
        post('legal/pay', { order: order, plan: sum }).then(function (data) {
            btn.disabled = false;
            if (data && data.ok && data.link) {
                goal('legal_pay');
                say(status2, 'Оплата откроется в новой вкладке. Как только платёж пройдёт, ' +
                    'документ начнёт собираться — вернитесь на эту страницу, она покажет результат.');
                window.open(data.link, '_blank', 'noopener');
                watch();
            } else {
                say(status2, (data && data.message) ? data.message : 'Не получилось создать ссылку на оплату.', 'err');
            }
        }).catch(function () {
            btn.disabled = false;
            say(status2, 'Сеть не отвечает. Попробуйте ещё раз.', 'err');
        });
    }

    /* ------------------------------------------------------------------ */
    /* Шаг 3: документ                                                     */
    /* ------------------------------------------------------------------ */

    function watch() {
        if (timer) { return; }
        timer = setInterval(check, 8000);
        check();
    }

    function stop() {
        if (timer) { clearInterval(timer); timer = null; }
    }

    function check() {
        if (!order) { return; }
        fetch(api + 'legal/state?order=' + encodeURIComponent(order))
            .then(function (r) { return r.json().catch(function () { return {}; }); })
            .then(function (data) {
                if (!data || !data.status) { return; }
                if (data.status === 'done' && data.parts && data.parts.length) {
                    stop();
                    goal('legal_done');
                    showParts(data.parts);
                } else if (data.status === 'writing') {
                    say(status2, 'Оплата прошла, документ собирается. Обычно это минута-две.');
                } else if (data.status === 'paid') {
                    say(status2, data.message
                        ? 'Оплата прошла. Собираем документ, предыдущая попытка не удалась: ' + data.message
                        : 'Оплата прошла, начинаем собирать документ…');
                }
            }).catch(function () {});
    }

    function showParts(parts) {
        if (!doneBox || !partsBox) { return; }
        say(status2, '');
        partsBox.innerHTML = '';
        parts.forEach(function (part) {
            var wrap = document.createElement('div');
            wrap.className = 'gs-legal-part';

            var head = document.createElement('div');
            head.className = 'gs-legal-part__head';
            var title = document.createElement('b');
            title.textContent = part.title;
            var link = document.createElement('a');
            link.className = 'gs-legal-btn gs-legal-btn--ghost gs-legal-btn--small';
            link.href = part.doc;
            link.textContent = 'Скачать в Word';
            head.appendChild(title);
            head.appendChild(link);

            var body = document.createElement('div');
            body.className = 'gs-legal-part__body';
            body.innerHTML = part.html;

            wrap.appendChild(head);
            wrap.appendChild(body);
            partsBox.appendChild(wrap);
        });
        doneBox.hidden = false;
        doneBox.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    // Человек вернулся с оплаты или открыл страницу заново.
    var known = recall();
    if (known) {
        order = known;
        fetch(api + 'legal/state?order=' + encodeURIComponent(order))
            .then(function (r) { return r.json().catch(function () { return {}; }); })
            .then(function (data) {
                if (!data || !data.status || data.status === 'none') { return; }
                if (data.review) { showReview(data.review); }
                if (data.status === 'done' && data.parts && data.parts.length) {
                    showParts(data.parts);
                } else if (data.status === 'paid' || data.status === 'writing') {
                    watch();
                }
            }).catch(function () {});
    }
})();
