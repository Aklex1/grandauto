/**
 * Вход окном, без ухода со страницы.
 *
 * Обращаемся к тем же маршрутам, что и штатная страница входа: код на
 * почту и проверка кода. Своей авторизации здесь нет — только другое
 * место для той же формы.
 *
 * После успешного входа страница перезагружается. Это намеренно: сервер
 * должен отдать её уже для вошедшего, с балансом и рабочей формой, а
 * введённые в браузере значения при перезагрузке сохраняет сам браузер.
 */
(function () {
    'use strict';

    var cfg = window.GS_AUTH || {};
    var box = document.getElementById('gs-auth');
    if (!box || !cfg.restUrl) {
        return;
    }

    var step1 = document.getElementById('gs-auth-step1');
    var step2 = document.getElementById('gs-auth-step2');
    var email = document.getElementById('gs-auth-email');
    var code = document.getElementById('gs-auth-code');
    var note = document.getElementById('gs-auth-note');
    var send = document.getElementById('gs-auth-send');
    var verify = document.getElementById('gs-auth-verify');
    var lastFocus = null;
    var sentTo = '';

    function say(text, kind) {
        note.textContent = text || '';
        note.className = 'gs-auth__note' + (kind ? ' is-' + kind : '');
    }

    function open() {
        lastFocus = document.activeElement;
        box.hidden = false;
        document.body.classList.add('gs-auth-open');
        email.focus();
    }

    function close() {
        box.hidden = true;
        document.body.classList.remove('gs-auth-open');
        say('');
        if (lastFocus) { lastFocus.focus(); }
    }

    function reset() {
        step1.hidden = false;
        step2.hidden = true;
        code.value = '';
        sentTo = '';
        say('');
        email.focus();
    }

    function post(path, body) {
        return fetch(cfg.restUrl + path, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body)
        }).then(function (r) {
            return r.json().catch(function () { return {}; }).then(function (data) {
                return { ok: r.ok, data: data };
            });
        });
    }

    function requestCode() {
        var value = String(email.value || '').trim();
        if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value)) {
            say('Введите адрес почты целиком', 'bad');
            email.focus();
            return;
        }
        send.disabled = true;
        say('Отправляем код…');
        post('email/send-code', { email: value }).then(function (res) {
            send.disabled = false;
            if (!res.ok) {
                say((res.data && res.data.message) || 'Не удалось отправить код', 'bad');
                return;
            }
            sentTo = value;
            step1.hidden = true;
            step2.hidden = false;
            say('Код отправлен на ' + value + '. Проверьте почту, письмо приходит за минуту.', 'ok');
            code.focus();
        }).catch(function () {
            send.disabled = false;
            say('Сеть не отвечает — попробуйте ещё раз', 'bad');
        });
    }

    function verifyCode() {
        var digits = String(code.value || '').replace(/\D/g, '');
        if (digits.length !== 6) {
            say('Код состоит из шести цифр', 'bad');
            code.focus();
            return;
        }
        verify.disabled = true;
        say('Проверяем…');
        post('email/verify-code', { email: sentTo, code: digits }).then(function (res) {
            if (!res.ok) {
                verify.disabled = false;
                say((res.data && res.data.message) || 'Неверный или устаревший код', 'bad');
                return;
            }
            say('Готово, обновляем страницу…', 'ok');
            // Перезагружаем текущий адрес, а не идём по redirect из ответа:
            // ответ ведёт в кабинет озвучки, а человек остался здесь.
            window.location.reload();
        }).catch(function () {
            verify.disabled = false;
            say('Сеть не отвечает — попробуйте ещё раз', 'bad');
        });
    }

    /**
     * Кнопку входа рисует не одно место, а каждая посадочная своим кодом.
     * Ставить пометку вручную в каждой — значит однажды забыть: так и
     * вышло со страницей генератора звуков, где вход снова уводил со
     * страницы. Поэтому перехватываем и любую ссылку на страницу входа,
     * кроме тех, что внутри самого окна: вход через ВК уводит на сторону
     * по делу.
     */
    function isLogin(el) {
        if (!el) { return false; }
        if (el.closest('#gs-auth')) { return false; }
        if (el.closest('[data-gs-auth]')) { return true; }
        var link = el.closest('a[href]');
        if (!link) { return false; }
        var href = link.getAttribute('href') || '';
        return href.indexOf('/tts-login') !== -1 && href.indexOf('vk_auth') === -1;
    }

    document.addEventListener('click', function (e) {
        if (isLogin(e.target)) {
            e.preventDefault();
            open();
            return;
        }
        if (e.target.closest('[data-gs-auth-close]')) {
            e.preventDefault();
            close();
            return;
        }
        if (e.target.closest('#gs-auth-send')) { requestCode(); return; }
        if (e.target.closest('#gs-auth-verify')) { verifyCode(); return; }
        if (e.target.closest('#gs-auth-back')) { reset(); }
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && !box.hidden) {
            close();
            return;
        }
        if (e.key !== 'Enter' || box.hidden) {
            return;
        }
        if (e.target === email) { e.preventDefault(); requestCode(); }
        if (e.target === code) { e.preventDefault(); verifyCode(); }
    });

    // Шесть цифр из письма часто вставляют целиком — принимаем сразу.
    code.addEventListener('input', function () {
        var digits = String(code.value || '').replace(/\D/g, '').slice(0, 6);
        if (code.value !== digits) {
            code.value = digits;
        }
        if (digits.length === 6) {
            verifyCode();
        }
    });
})();
