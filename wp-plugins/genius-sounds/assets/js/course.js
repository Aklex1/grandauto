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

/* ---------------------------------------------------------------------
 * Увеличение снимков в кейсах
 *
 * Клик по работе из кейса открывает её поверх страницы, а не в новой
 * вкладке: уводить человека с посадочной в момент, когда он смотрит
 * доказательства, — худшее, что можно сделать. Закрывается щелчком,
 * крестиком и клавишей Esc.
 * ------------------------------------------------------------------ */
(function () {
    'use strict';

    var box = null;

    function close() {
        if (!box) { return; }
        box.remove();
        box = null;
        document.removeEventListener('keydown', onKey);
        document.body.style.overflow = '';
    }

    function onKey(e) {
        if (e.key === 'Escape') { close(); }
    }

    function open(src) {
        close();
        box = document.createElement('div');
        box.className = 'gs-lightbox';
        box.innerHTML = '<button type="button" class="gs-lightbox__close" aria-label="Закрыть">×</button>'
            + '<img src="' + src.replace(/"/g, '&quot;') + '" alt="">';
        box.addEventListener('click', function (e) {
            // Щелчок по самой картинке не закрывает: по ней ещё водят мышью.
            if (e.target === box || e.target.classList.contains('gs-lightbox__close')) { close(); }
        });
        document.body.appendChild(box);
        document.body.style.overflow = 'hidden';
        document.addEventListener('keydown', onKey);
    }

    document.addEventListener('click', function (e) {
        var btn = e.target.closest ? e.target.closest('.gs-case-shot') : null;
        if (!btn) { return; }
        e.preventDefault();
        var full = btn.getAttribute('data-full');
        if (full) { open(full); }
    });
})();

/**
 * Кнопка записи, едущая со страницей.
 *
 * Прячется, когда форма заявки сама на экране, и поднимается над
 * плашкой о печеньках: на телефоне обе стоят внизу, и без подъёма
 * кнопка закрывала бы согласие, которое человек ещё не нажал.
 */
(function () {
    'use strict';

    var cta = document.getElementById('gs-course-float');
    if (!cta) { return; }

    var form = document.getElementById('zayavka');
    if (form && 'IntersectionObserver' in window) {
        new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                cta.classList.toggle('is-off', entry.isIntersecting);
            });
        }, { rootMargin: '-10% 0px -10% 0px' }).observe(form);
    }

    var lift = function () {
        // Плашка стоит fixed, и offsetParent у неё пустой: смотрим,
        // сколько места она занимает снизу, а не этот признак.
        var bar = document.querySelector('.cookietrix-banner');
        var take = 0;
        if (bar && getComputedStyle(bar).display !== 'none') {
            var rect = bar.getBoundingClientRect();
            if (rect.height > 0) {
                take = Math.max(0, Math.round(window.innerHeight - rect.top));
            }
        }
        cta.style.setProperty('--gs-float-lift', take + 'px');
    };
    lift();
    window.addEventListener('resize', lift);
    // Плашку закрывают в любой момент — тогда кнопка опускается обратно.
    if ('MutationObserver' in window) {
        new MutationObserver(lift).observe(document.body, { childList: true });
    }

    // Плавная прокрутка к форме и подсветка первого поля: иначе человек
    // доезжает до неё и не понимает, что от него хотят.
    cta.addEventListener('click', function (e) {
        if (!form) { return; }
        e.preventDefault();
        form.scrollIntoView({ behavior: 'smooth', block: 'start' });
        window.setTimeout(function () {
            var field = document.getElementById('gs-course-contact');
            if (field) { field.focus({ preventScroll: true }); }
        }, 600);
    });
})();
