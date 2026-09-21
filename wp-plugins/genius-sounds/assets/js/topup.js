/**
 * Пополнение баланса прямо на странице сервиса.
 *
 * Раньше «Пополнить» уводило в кабинет озвучки: человек бросал заполненную
 * форму и часто не возвращался. Теперь окно открывается на месте, а ссылку
 * на оплату выдаёт тот же маршрут, которым пользуется кабинет.
 */
(function () {
    'use strict';

    var cfg = window.GS_TOPUP || {};
    var box = document.getElementById('gs-topup');
    if (!box || !cfg.restUrl) {
        return;
    }

    var note = document.getElementById('gs-topup-note');
    var go = document.getElementById('gs-topup-go');
    var lastFocus = null;
    var watch = null;
    var startBalance = null;

    function say(text, kind) {
        note.textContent = text || '';
        note.className = 'gs-topup__note' + (kind ? ' is-' + kind : '');
    }

    function open() {
        lastFocus = document.activeElement;
        box.hidden = false;
        document.body.classList.add('gs-topup-open');
        var first = box.querySelector('[data-gs-topup-sum]');
        if (first) { first.focus(); }
    }

    function close() {
        box.hidden = true;
        document.body.classList.remove('gs-topup-open');
        say('');
        go.hidden = true;
        stopWatch();
        if (lastFocus) { lastFocus.focus(); }
    }

    /* ------------------------------------------------------------------ */

    function balance() {
        return fetch(cfg.restUrl + 'balance', {
            credentials: 'same-origin',
            headers: { 'X-WP-Nonce': cfg.nonce }
        }).then(function (r) {
            return r.ok ? r.json() : null;
        }).then(function (d) {
            return d && typeof d.balance !== 'undefined' ? Number(d.balance) : null;
        }).catch(function () {
            return null;
        });
    }

    /** Показываем новое значение там, где оно нарисовано на странице. */
    function paint(value) {
        var shown = value.toLocaleString('ru-RU', {
            minimumFractionDigits: 2, maximumFractionDigits: 2
        }) + ' ₽';
        ['gs-lab-balance', 'gs-sfx-balance', 'gs-slides-balance'].forEach(function (id) {
            var el = document.getElementById(id);
            if (el) { el.textContent = shown; }
        });
    }

    function stopWatch() {
        if (watch) {
            clearInterval(watch);
            watch = null;
        }
    }

    /**
     * Оплата уходит в чужую вкладку, и вернуться на страницу человек может
     * когда угодно. Поэтому после перехода к оплате мы просто ждём, пока
     * баланс не вырастет: уведомление от банка приходит на сервер само,
     * а страница о нём узнаёт отсюда.
     */
    function startWatch() {
        stopWatch();
        var left = 120;                        // десять минут по пять секунд
        watch = setInterval(function () {
            if (--left <= 0) {
                stopWatch();
                say('Баланс пока не обновился. Если деньги списаны, напишите нам — '
                    + 'пополним вручную.', 'error');
                return;
            }
            balance().then(function (now) {
                if (now === null || startBalance === null || now <= startBalance) {
                    return;
                }
                stopWatch();
                paint(now);
                say('Баланс пополнен: ' + now.toFixed(2) + ' ₽', 'ok');
            });
        }, 5000);
    }

    function request(amount) {
        amount = Math.round(Number(amount) || 0);
        if (amount < 50) {
            say('Минимальная сумма — 50 ₽', 'error');
            return;
        }
        say('Готовим ссылку на оплату…');
        go.hidden = true;

        fetch(cfg.restUrl + 'topup', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'X-WP-Nonce': cfg.nonce, 'Content-Type': 'application/json' },
            body: JSON.stringify({ amount: amount })
        }).then(function (r) {
            return r.json().then(function (data) {
                return { ok: r.ok, data: data };
            });
        }).then(function (res) {
            if (!res.ok || !res.data || !res.data.payment_link) {
                say((res.data && res.data.message) || 'Не удалось создать ссылку на оплату', 'error');
                return;
            }
            go.href = res.data.payment_link;
            go.textContent = 'Перейти к оплате ' + amount + ' ₽';
            go.hidden = false;
            go.setAttribute('data-label', res.data.label || '');
            say('Ссылка готова. Оплата откроется в новой вкладке.', 'ok');
        }).catch(function () {
            say('Сеть не отвечает — попробуйте ещё раз', 'error');
        });
    }

    // Кнопки «Пополнить» размечены атрибутом, поэтому новые страницы
    // подхватываются сами, без правки этого файла.
    document.addEventListener('click', function (e) {
        var opener = e.target.closest('[data-gs-topup]');
        if (opener) {
            e.preventDefault();
            open();
            return;
        }
        if (e.target.closest('[data-gs-topup-close]')) {
            e.preventDefault();
            close();
            return;
        }
        var sum = e.target.closest('[data-gs-topup-sum]');
        if (sum) {
            request(sum.getAttribute('data-gs-topup-sum'));
            return;
        }
        if (e.target.closest('#gs-topup-go')) {
            // Запоминаем баланс до оплаты: только по росту видно, что
            // деньги дошли — суммы бывают одинаковые, а платежей несколько.
            balance().then(function (now) {
                startBalance = now === null ? 0 : now;
                var label = document.getElementById('gs-topup-go').getAttribute('data-label');
                say('Ждём подтверждения оплаты…' + (label ? ' Номер платежа: ' + label : ''));
                startWatch();
            });
        }
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && !box.hidden) {
            close();
        }
    });

})();
