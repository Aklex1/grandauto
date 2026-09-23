/**
 * Форма связи с поддержкой на страницах сервисов.
 *
 * Форма спрятана до нажатия: на странице сервиса главное — сам сервис, а
 * два поля ввода посреди неё отвлекают того, у кого всё получилось.
 */
(function () {
    'use strict';

    var cfg = window.GS_HELP || {};
    var form = document.getElementById('gs-help-form');
    if (!form) { return; }

    var kindNote = document.getElementById('gs-help-kind');
    var contact = document.getElementById('gs-help-contact');
    var text = document.getElementById('gs-help-text');
    var button = document.getElementById('gs-help-send');
    var note = document.getElementById('gs-help-note');
    var kind = 'support';

    var TITLES = {
        support: 'Поддержка: опишите, что пошло не так — ответим в Telegram.',
        question: 'Вопрос: спрашивайте что угодно про сервис, цену или формат файла.'
    };

    function say(message, state) {
        if (!note) { return; }
        note.textContent = message;
        note.className = 'gs-form__note' + (state ? ' is-' + state : '');
    }

    Array.prototype.forEach.call(document.querySelectorAll('[data-gs-help]'), function (btn) {
        btn.addEventListener('click', function () {
            kind = btn.getAttribute('data-gs-help') === 'question' ? 'question' : 'support';
            if (kindNote) { kindNote.textContent = TITLES[kind]; }
            form.hidden = false;
            say('');
            if (contact) { contact.focus(); }
        });
    });

    form.addEventListener('submit', function (event) {
        event.preventDefault();

        var who = (contact && contact.value ? contact.value : '').trim();
        var what = (text && text.value ? text.value : '').trim();

        if (who === '') {
            say('Оставьте ник в Telegram, почту или телефон — иначе мы не сможем ответить', 'error');
            if (contact) { contact.focus(); }
            return;
        }
        if (what === '') {
            say('Напишите, в чём дело, — по пустому сообщению мы не поймём, чем помочь', 'error');
            if (text) { text.focus(); }
            return;
        }

        button.disabled = true;
        say('Отправляем…');

        var where = form.getAttribute('data-context') || 'сервис';
        var label = (kind === 'question' ? 'Вопрос' : 'Поддержка') + ' — ' + where;

        fetch((cfg.restUrl || '/wp-json/genius-sounds/v1/') + 'lead', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce || '' },
            body: JSON.stringify({ name: '', contact: who, comment: what, source: label })
        }).then(function (response) {
            return response.json().then(function (data) {
                return { ok: response.ok, data: data };
            });
        }).then(function (result) {
            if (!result.ok) {
                say((result.data && result.data.message) || 'Не получилось отправить — напишите нам в Telegram', 'error');
                button.disabled = false;
                return;
            }
            form.hidden = true;
            say('');
            var done = document.createElement('p');
            done.className = 'gs-help__done';
            done.textContent = 'Сообщение отправлено. Ответим в Telegram или на указанный контакт.';
            form.parentNode.appendChild(done);
        }).catch(function () {
            say('Связь оборвалась. Попробуйте ещё раз или напишите в Telegram: ' + (cfg.bot || ''), 'error');
            button.disabled = false;
        });
    });
})();
