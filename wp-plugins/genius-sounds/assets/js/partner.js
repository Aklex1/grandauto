/**
 * Копирование партнёрской ссылки.
 *
 * Выделить длинную ссылку пальцем на телефоне — отдельное мучение, и на
 * этом шаге люди отваливаются чаще всего.
 */
(function () {
    'use strict';

    var input = document.getElementById('gs-partner-link');
    var button = document.getElementById('gs-partner-copy');
    var note = document.getElementById('gs-partner-note');
    if (!input || !button) { return; }

    var original = note ? note.textContent : '';

    // Партнёр рассказывает про конкретный сервис — пусть и ссылку даёт на
    // него. Метка приглашения ловится на любой странице, поэтому разницы в
    // начислении нет, а первый экран у человека совпадает с обещанием.
    Array.prototype.forEach.call(document.querySelectorAll('[data-gs-target]'), function (chip) {
        chip.addEventListener('click', function () {
            input.value = chip.getAttribute('data-gs-target') || input.value;
            Array.prototype.forEach.call(document.querySelectorAll('[data-gs-target]'), function (other) {
                other.classList.toggle('is-active', other === chip);
            });
            if (note) {
                note.textContent = 'Ссылка ведёт на: ' + chip.textContent.trim();
            }
        });
    });

    button.addEventListener('click', function () {
        input.focus();
        input.select();
        input.setSelectionRange(0, 99999);

        var done = function () {
            if (!note) { return; }
            note.textContent = 'Ссылка скопирована — вставляйте куда угодно';
            setTimeout(function () { note.textContent = original; }, 2500);
        };

        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(input.value).then(done, function () {
                try { document.execCommand('copy'); done(); } catch (e) { /* покажем как есть */ }
            });
            return;
        }
        try { document.execCommand('copy'); done(); } catch (e) { /* покажем как есть */ }
    });
})();
