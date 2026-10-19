/**
 * Отправка заявки на консультацию.
 *
 * Форма уходит нашим же маршрутом /v1/lead: он пишет заявку в журнал и
 * пробует уведомить в Telegram. Страница при этом не перезагружается —
 * человек видит ответ там же, где нажал кнопку.
 */
(function () {
    var form = document.querySelector('.gs-consult__form');
    if (!form || typeof gsConsult === 'undefined') {
        return;
    }
    var note = form.querySelector('.gs-consult__note');
    var send = form.querySelector('.gs-consult__send');
    var busy = false;

    /*
     * Сказать Метрике, что заявка пришла.
     *
     * Автоцель «отправка формы заявки» ловит обычную отправку с
     * перезагрузкой, а наша уходит запросом — поэтому в отчёте стояли
     * нули при живых заявках в журнале. Зовём цель сами и молча: счётчик
     * мог быть вырезан блокировщиком, и падать из-за этого форме нельзя.
     */
    function goal() {
        try {
            if (gsConsult.counter && typeof window.ym === 'function') {
                window.ym(gsConsult.counter, 'reachGoal', gsConsult.goal || 'lead_form');
            }
        } catch (e) {
        }
    }

    function say(text, kind) {
        if (!note) {
            return;
        }
        note.textContent = text;
        note.className = 'gs-consult__note' + (kind ? ' is-' + kind : '');
    }

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        if (busy) {
            return;
        }
        var data = {
            name: (form.elements.name.value || '').trim(),
            contact: (form.elements.contact.value || '').trim(),
            comment: (form.elements.comment.value || '').trim(),
            source: 'страница консультации'
        };
        if (!data.contact) {
            say('Оставьте телефон, почту или ник в Telegram — иначе мы не сможем ответить.', 'bad');
            form.elements.contact.focus();
            return;
        }
        busy = true;
        send.disabled = true;
        say('Отправляем…');
        fetch(gsConsult.url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(data)
        }).then(function (r) {
            return r.json().then(function (body) { return { ok: r.ok, body: body }; });
        }).then(function (res) {
            if (res.ok) {
                form.reset();
                say(res.body.message || 'Заявка принята — свяжемся с вами в ближайшее время', 'good');
                goal();
                return;
            }
            say(res.body.message || 'Не получилось отправить. Напишите нам в Telegram — так быстрее.', 'bad');
        }).catch(function () {
            say('Связь с сервером прервалась. Напишите нам в Telegram — так быстрее.', 'bad');
        }).then(function () {
            busy = false;
            send.disabled = false;
        });
    });
})();
