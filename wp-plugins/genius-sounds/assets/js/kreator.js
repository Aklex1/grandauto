/**
 * Заявка с лендинга обучения AI-креатора.
 *
 * Отправляем тем же маршрутом /v1/lead, что и остальные формы сайта, и
 * никуда не уводим человека со страницы: заявка здесь единственное
 * действие, и перезагрузка только мешает.
 *
 * Цель в Метрике зовём сами: автоцель «отправка формы» ловит обычную
 * отправку с перезагрузкой, а наша уходит запросом — в отчёте стояли бы
 * нули при живых заявках.
 */
(function () {
    'use strict';

    var cfg = window.GS_KREATOR || {};
    var form = document.getElementById('gs-kreator-form');
    if (!form || !cfg.restUrl) {
        return;
    }

    var contact = document.getElementById('gs-kreator-contact');
    var name    = document.getElementById('gs-kreator-name');
    var comment = document.getElementById('gs-kreator-comment');
    var plan    = document.getElementById('gs-kreator-plan');
    var button  = document.getElementById('gs-kreator-send');
    var note    = document.getElementById('gs-kreator-note');

    function say(text, kind) {
        if (!note) {
            return;
        }
        note.textContent = text;
        note.className = 'gs-form__note' + (kind ? ' is-' + kind : '');
    }

    function goal() {
        try {
            if (cfg.counter && typeof window.ym === 'function') {
                window.ym(cfg.counter, 'reachGoal', cfg.goal || 'lead_form');
            }
        } catch (e) {
        }
    }

    form.addEventListener('submit', function (event) {
        event.preventDefault();

        var value = (contact && contact.value ? contact.value : '').trim();
        if (value === '') {
            say('Оставьте телефон, почту или ник в Telegram — иначе мы не сможем ответить', 'error');
            if (contact) {
                contact.focus();
            }
            return;
        }

        button.disabled = true;
        say('Отправляем…');

        fetch(cfg.restUrl + 'lead', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce || '' },
            body: JSON.stringify({
                name: name ? name.value : '',
                contact: value,
                comment: (plan && plan.value ? 'Тариф: ' + plan.value + '. ' : '') + (comment ? comment.value : ''),
                source: cfg.source || 'обучение AI-креатора'
            })
        }).then(function (response) {
            return response.json().then(function (data) {
                return { ok: response.ok, data: data };
            });
        }).then(function (result) {
            if (!result.ok) {
                say((result.data && result.data.message) || 'Не получилось отправить заявку', 'error');
                button.disabled = false;
                return;
            }
            form.reset();
            say(result.data.message || 'Заявка принята — свяжемся с вами в ближайшее время', 'ok');
            goal();
        }).catch(function () {
            say('Связь оборвалась. Попробуйте ещё раз или напишите нам в Telegram.', 'error');
            button.disabled = false;
        });
    });
})();
