/**
 * Genius Sounds — страница API: вкладки примеров, копирование и ключи доступа.
 */
(function () {
    'use strict';

    var cfg = window.GS_API || {};
    var root = document.querySelector('.gs-api');
    if (!root) {
        return;
    }

    /* ------------------------------------------------------------ примеры кода */

    Array.prototype.forEach.call(root.querySelectorAll('.gs-api__code-block'), function (block) {
        block.addEventListener('click', function (e) {
            var tab = e.target.closest('.gs-api__tab');
            if (tab) {
                var lang = tab.getAttribute('data-lang');
                Array.prototype.forEach.call(block.querySelectorAll('.gs-api__tab'), function (t) {
                    t.classList.toggle('is-active', t === tab);
                });
                Array.prototype.forEach.call(block.querySelectorAll('pre[data-lang]'), function (pre) {
                    pre.hidden = pre.getAttribute('data-lang') !== lang;
                });
                return;
            }
            if (e.target.closest('.gs-api__copy')) {
                var visible = block.querySelector('pre[data-lang]:not([hidden])');
                if (!visible) {
                    return;
                }
                var button = e.target.closest('.gs-api__copy');
                var done = function () {
                    button.textContent = 'Скопировано';
                    setTimeout(function () { button.textContent = 'Копировать'; }, 1600);
                };
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(visible.textContent).then(done, function () {});
                    return;
                }
                var area = document.createElement('textarea');
                area.value = visible.textContent;
                document.body.appendChild(area);
                area.select();
                try { document.execCommand('copy'); done(); } catch (err) { /* браузер запретил */ }
                document.body.removeChild(area);
            }
        });
    });

    /* ------------------------------------------------------------------ ключи */

    var form = document.getElementById('gs-api-form');
    var list = document.getElementById('gs-api-list');
    var note = document.getElementById('gs-api-note');
    var fresh = document.getElementById('gs-api-fresh');
    var freshValue = document.getElementById('gs-api-freshvalue');

    function say(text, kind) {
        if (!note) {
            return;
        }
        note.textContent = text || '';
        note.className = 'gs-form__note' + (kind ? ' is-' + kind : '');
    }

    function api(method, body) {
        return fetch(cfg.restUrl + 'api-keys', {
            method: method,
            credentials: 'same-origin',
            headers: { 'X-WP-Nonce': cfg.nonce, 'Content-Type': 'application/json' },
            body: body ? JSON.stringify(body) : undefined
        }).then(function (response) {
            return response.json().then(function (data) {
                return { ok: response.ok, data: data };
            });
        });
    }

    function renderKeys(keys) {
        if (!list) {
            return;
        }
        if (!keys || !keys.length) {
            list.innerHTML = '<p class="gs-api__muted">Ключей пока нет.</p>';
            return;
        }
        list.innerHTML = '';
        keys.forEach(function (key) {
            var row = document.createElement('div');
            row.className = 'gs-api__key';

            var head = document.createElement('div');
            var name = document.createElement('strong');
            name.textContent = key.label;
            var prefix = document.createElement('span');
            prefix.className = 'gs-api__muted';
            prefix.textContent = ' ' + key.prefix + '…';
            head.appendChild(name);
            head.appendChild(prefix);

            var meta = document.createElement('div');
            meta.className = 'gs-api__muted';
            meta.textContent = 'вызовов: ' + (key.calls || 0);

            var revoke = document.createElement('button');
            revoke.type = 'button';
            revoke.className = 'gs-btn gs-btn--ghost';
            revoke.textContent = 'Отозвать';
            revoke.setAttribute('data-revoke', key.prefix);

            row.appendChild(head);
            row.appendChild(meta);
            row.appendChild(revoke);
            list.appendChild(row);
        });
    }

    if (form) {
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var label = document.getElementById('gs-api-label');
            say('');
            api('POST', { label: label ? label.value.trim() : '' }).then(function (res) {
                if (!res.ok || !res.data || !res.data.key) {
                    say((res.data && res.data.message) || 'Не удалось выпустить ключ', 'error');
                    return;
                }
                if (fresh && freshValue) {
                    freshValue.textContent = 'Ключ:   ' + res.data.key + '\nСекрет: ' + res.data.secret;
                    fresh.hidden = false;
                }
                if (label) {
                    label.value = '';
                }
                renderKeys(res.data.keys);
                say('Ключ выпущен. Скопируйте его сейчас — второй раз мы его не покажем.', 'ok');
            }).catch(function () {
                say('Сеть недоступна, попробуйте ещё раз', 'error');
            });
        });
    }

    if (list) {
        list.addEventListener('click', function (e) {
            var button = e.target.closest('[data-revoke]');
            if (!button) {
                return;
            }
            if (!window.confirm('Отозвать ключ? Запросы с ним перестанут работать сразу.')) {
                return;
            }
            api('DELETE', { prefix: button.getAttribute('data-revoke') }).then(function (res) {
                if (!res.ok) {
                    say('Не удалось отозвать ключ', 'error');
                    return;
                }
                renderKeys(res.data.keys);
                say('Ключ отозван.', 'ok');
            });
        });
    }
})();
