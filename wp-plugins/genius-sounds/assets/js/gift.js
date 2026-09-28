/**
 * Анкета «песня в подарок».
 *
 * Отправка без перезагрузки: человек, заполнивший шесть полей, не должен
 * увидеть чистую страницу и гадать, ушла заявка или нет. Регистрация не
 * нужна — маршрут открытый, от перебора его защищает счётчик на сервере.
 */
(function () {
    'use strict';

    var cfg = window.GS_GIFT || {};
    var send = document.getElementById('gs-gift-send');
    var status = document.getElementById('gs-gift-status');
    if (!send || !status || !cfg.restUrl) {
        return;
    }

    function val(id) {
        var el = document.getElementById(id);
        if (!el) { return ''; }
        return el.type === 'checkbox' ? (el.checked ? '1' : '') : String(el.value || '').trim();
    }

    function say(text, kind) {
        status.textContent = text || '';
        status.className = 'gs-gift-status' + (kind ? ' is-' + kind : '');
    }

    // Кнопка «Выбрать» в карточке пакета не только ведёт к анкете, но и
    // выставляет этот пакет: иначе человек выбирает дважды и один раз зря.
    document.addEventListener('click', function (e) {
        var pick = e.target.closest ? e.target.closest('[data-gs-gift-pack]') : null;
        if (!pick) { return; }
        var want = pick.getAttribute('data-gs-gift-pack') || '';
        var select = document.getElementById('gs-gift-pack');
        if (!select || !want) { return; }
        Array.prototype.forEach.call(select.options, function (opt) {
            if (opt.value.indexOf(want) === 0) { select.value = opt.value; }
        });
    });

    send.addEventListener('click', function () {
        var contact = val('gs-gift-contact');
        if (contact === '') {
            say('Оставьте контакт — телефон, почту или ник в Telegram. Иначе нам некуда ответить.', 'err');
            var field = document.getElementById('gs-gift-contact');
            if (field) { field.focus(); }
            return;
        }

        var payload = {
            whom: val('gs-gift-whom'),
            name: val('gs-gift-name'),
            occasion: val('gs-gift-occasion'),
            style: val('gs-gift-style'),
            story: val('gs-gift-story'),
            pack: val('gs-gift-pack'),
            contact: contact,
            urgent: val('gs-gift-urgent') ? 1 : 0,
            page: cfg.page || ''
        };

        send.disabled = true;
        say('Отправляем…');

        fetch(cfg.restUrl + 'gift/lead', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        }).then(function (r) {
            return r.json().catch(function () { return {}; });
        }).then(function (data) {
            send.disabled = false;
            if (data && data.ok) {
                say('Заявка ушла. Ответим в течение часа — напишем на указанный контакт.', 'ok');
                var story = document.getElementById('gs-gift-story');
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
