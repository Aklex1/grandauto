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

/**
 * Появление блоков при прокрутке.
 *
 * Класс kr-anim ставит скрипт, и только после этого стили прячут блоки:
 * при отключённом скрипте страница должна оставаться видимой целиком.
 * Наблюдатель срабатывает один раз на блок — повторные проявления при
 * прокрутке вверх-вниз выглядят как брак, а не как оформление.
 */
(function () {
    'use strict';

    var root = document.querySelector('.gs-kr');
    if (!root || !('IntersectionObserver' in window)) {
        return;
    }
    if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
        return;
    }

    var marks = [];
    root.querySelectorAll('section').forEach(function (section) {
        var kids = section.children.length > 1
            ? Array.prototype.slice.call(section.children)
            : [section];
        kids.forEach(function (el, i) {
            el.setAttribute('data-kr-in', '');
            el.style.setProperty('--kr-delay', Math.min(i, 6) * 0.06 + 's');
            marks.push(el);
        });
    });
    root.querySelectorAll('.gs-kr__d').forEach(function (el, i) {
        el.setAttribute('data-kr-in', '');
        el.style.setProperty('--kr-delay', Math.min(i, 9) * 0.05 + 's');
        marks.push(el);
    });

    root.classList.add('kr-anim');

    var seen = new IntersectionObserver(function (entries) {
        entries.forEach(function (entry) {
            if (!entry.isIntersecting) {
                return;
            }
            entry.target.classList.add('is-in');
            seen.unobserve(entry.target);
        });
    }, { rootMargin: '0px 0px -8% 0px', threshold: 0.06 });

    marks.forEach(function (el) { seen.observe(el); });

    // Первый экран показываем сразу: ждать прокрутки ему незачем.
    var hero = root.querySelector('.gs-kr__hero');
    if (hero) {
        Array.prototype.slice.call(hero.children).forEach(function (el) { el.classList.add('is-in'); });
        hero.classList.add('is-in');
    }
})();
