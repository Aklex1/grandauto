/**
 * Юридический блок: заявка и счётчик срока по судебному приказу.
 *
 * Заявка уходит тем же маршрутом, что и остальные заявки сайта. Документ
 * готовится после оплаты, поэтому форма ничего не обещает сразу: честное
 * «ответим и пришлём» лучше, чем полоска загрузки, за которой ничего нет.
 *
 * Счётчик срока — главное на странице про приказ: человек видит не «десять
 * дней по закону», а сколько осталось именно у него.
 */
(function () {
    'use strict';

    var root = document.querySelector('[data-gs-legal]');
    if (!root) { return; }

    var cfg = window.GS_LEGAL || {};

    /* ------------------------------------------------------------------ */
    /* Счётчик срока                                                       */
    /* ------------------------------------------------------------------ */

    var got = document.getElementById('gs-legal-got');
    var left = document.getElementById('gs-legal-left');

    function countDeadline() {
        if (!got || !left) { return; }
        var value = got.value;
        if (!value) { left.textContent = ''; left.className = 'gs-legal-deadline__out'; return; }

        var start = new Date(value + 'T00:00:00');
        if (isNaN(start.getTime())) { return; }
        var today = new Date();
        today.setHours(0, 0, 0, 0);

        // Десять календарных дней со дня получения: сам день получения не
        // считается, поэтому отсчёт идёт от следующего.
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

    function plural(n, forms) {
        var mod100 = n % 100, mod10 = n % 10;
        if (mod100 > 4 && mod100 < 21) { return forms[2]; }
        if (mod10 === 1) { return forms[0]; }
        if (mod10 > 1 && mod10 < 5) { return forms[1]; }
        return forms[2];
    }

    if (got) {
        got.addEventListener('change', countDeadline);
        got.addEventListener('input', countDeadline);
    }

    /* ------------------------------------------------------------------ */
    /* Заявка                                                              */
    /* ------------------------------------------------------------------ */

    var send = document.getElementById('gs-legal-send');
    var status = document.getElementById('gs-legal-status');
    if (!send || !status) { return; }

    function goal(name) {
        try {
            if (typeof window.ym === 'function' && cfg.metrika) {
                window.ym(cfg.metrika, 'reachGoal', name);
            }
        } catch (e) {}
    }

    function say(text, kind) {
        status.textContent = text;
        status.className = 'gs-legal-note' + (kind ? ' is-' + kind : '');
    }

    function value(id) {
        var el = document.getElementById(id);
        return el ? String(el.value || '').trim() : '';
    }

    var started = false;
    var story = document.getElementById('gs-legal-story');
    if (story) {
        story.addEventListener('input', function () {
            if (!started) { started = true; goal('legal_form_start'); }
        });
    }

    send.addEventListener('click', function () {
        var text = value('gs-legal-story');
        var contact = value('gs-legal-contact');
        var plan = document.querySelector('input[name="gs-legal-plan"]:checked');

        if (text.length < 30) {
            say('Опишите ситуацию чуть подробнее — по двум словам документ не собрать.', 'err');
            if (story) { story.focus(); }
            return;
        }
        if (contact === '') {
            say('Оставьте почту или ник в Telegram — иначе документ некуда прислать.', 'err');
            return;
        }

        send.disabled = true;
        say('Отправляем…');

        var payload = {
            name: '',
            contact: contact,
            comment: '[' + value('gs-legal-page') + ' · ' + (plan ? plan.value : '') + ' ₽] ' + text,
            source: 'Юрдокументы: ' + value('gs-legal-page')
        };

        fetch((cfg.restUrl || '/wp-json/genius-sounds/v1/') + 'lead', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        }).then(function (r) {
            return r.json().catch(function () { return {}; });
        }).then(function (data) {
            send.disabled = false;
            if (data && data.ok) {
                goal('legal_form_submit');
                say('Заявка принята. Напишем в ближайшее время и пришлём счёт на выбранный тариф.', 'ok');
                if (story) { story.value = ''; }
            } else {
                say((data && data.message) ? data.message : 'Не получилось отправить. Попробуйте ещё раз.', 'err');
            }
        }).catch(function () {
            send.disabled = false;
            say('Сеть не отвечает. Попробуйте ещё раз или напишите нам в Telegram.', 'err');
        });
    });
})();
