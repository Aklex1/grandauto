/**
 * Форма заявки на лендинге обучения.
 *
 * Отправляем через REST и никуда не уводим человека со страницы: заявка —
 * единственное действие на лендинге, и перезагрузка здесь только мешает.
 */
(function () {
    var cfg = window.GS_COURSE || {};
    var form = document.getElementById('gs-course-form');
    if (!form || !cfg.restUrl) { return; }

    var contact = document.getElementById('gs-course-contact');
    var name = document.getElementById('gs-course-name');
    var comment = document.getElementById('gs-course-comment');
    var plan = document.getElementById('gs-course-plan');
    var button = document.getElementById('gs-course-send');
    var note = document.getElementById('gs-course-note');

    function say(text, kind) {
        if (!note) { return; }
        note.textContent = text;
        note.className = 'gs-form__note' + (kind ? ' is-' + kind : '');
    }

    form.addEventListener('submit', function (event) {
        event.preventDefault();

        var value = (contact && contact.value ? contact.value : '').trim();
        if (value === '') {
            say('Оставьте телефон, почту или ник в Telegram — иначе мы не сможем ответить', 'error');
            if (contact) { contact.focus(); }
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
                source: cfg.source || 'обучение'
            })
        }).then(function (response) {
            return response.json().then(function (data) {
                return { ok: response.ok, data: data };
            });
        }).then(function (result) {
            if (!result.ok) {
                var message = result.data && result.data.message ? result.data.message : 'Не получилось отправить заявку';
                say(message, 'error');
                button.disabled = false;
                return;
            }
            form.classList.add('gs-course__form--sent');
            say(result.data.message || 'Заявка принята — свяжемся с вами в ближайшее время', 'ok');
        }).catch(function () {
            say('Связь оборвалась. Попробуйте ещё раз или напишите нам в Telegram.', 'error');
            button.disabled = false;
        });
    });
})();
