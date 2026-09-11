/**
 * Genius Sounds — микросервисы: загрузка файла, постановка задачи, опрос статуса.
 */
(function () {
    'use strict';

    var cfg = window.GS_LAB || {};
    var form = document.getElementById('gs-lab-form');
    if (!form) {
        return;
    }

    var els = {
        submit:   document.getElementById('gs-lab-submit'),
        note:     document.getElementById('gs-lab-note'),
        balance:  document.getElementById('gs-lab-balance'),
        prompt:   document.getElementById('gs-lab-prompt'),
        empty:    document.getElementById('gs-lab-empty'),
        loading:  document.getElementById('gs-lab-loading'),
        ready:    document.getElementById('gs-lab-ready'),
        stage:    document.getElementById('gs-lab-stage'),
        progress: document.getElementById('gs-lab-progress'),
        files:    document.getElementById('gs-lab-files'),
        price:    document.getElementById('gs-lab-price'),
        again:    document.getElementById('gs-lab-again')
    };

    var polling = null;
    var pollStarted = 0;
    var POLL_INTERVAL = 6000;
    var submitLabel = els.submit ? els.submit.textContent.trim() : 'Запустить';

    function api(path, options) {
        options = options || {};
        var headers = { 'X-WP-Nonce': cfg.nonce };
        if (!options.formData) {
            headers['Content-Type'] = 'application/json';
        }
        return fetch(cfg.restUrl + path, {
            method: options.method || 'GET',
            credentials: 'same-origin',
            headers: headers,
            body: options.formData ? options.formData : (options.body ? JSON.stringify(options.body) : undefined)
        }).then(function (response) {
            return response.json().then(function (data) {
                return { ok: response.ok, status: response.status, data: data };
            });
        });
    }

    function note(text, kind) {
        els.note.textContent = text || '';
        els.note.className = 'gs-form__note' + (kind ? ' is-' + kind : '');
    }

    function show(which) {
        els.empty.hidden = which !== 'empty';
        els.loading.hidden = which !== 'loading';
        els.ready.hidden = which !== 'ready';
    }

    function setBusy(busy) {
        if (!els.submit) {
            return;
        }
        els.submit.disabled = busy;
        els.submit.textContent = busy ? 'Обрабатываем…' : submitLabel;
    }

    function money(value) {
        return Number(value).toFixed(2).replace('.', ',') + ' ₽';
    }

    function duration(seconds) {
        seconds = Math.round(Number(seconds) || 0);
        var m = Math.floor(seconds / 60);
        var s = seconds % 60;
        return m > 0 ? m + ' мин ' + s + ' с' : s + ' с';
    }

    function stopPolling() {
        if (polling) {
            clearInterval(polling);
            polling = null;
        }
    }

    /* ------------------------------------------------------------------ файлы */

    var uploaded = {};

    Array.prototype.forEach.call(form.querySelectorAll('.gs-file'), function (input) {
        input.addEventListener('change', function () {
            var kind = input.getAttribute('data-kind');
            var hint = form.querySelector('[data-file-hint="' + kind + '"]');
            uploaded[kind] = null;
            if (!input.files || !input.files.length) {
                return;
            }
            var file = input.files[0];
            if (hint) {
                hint.textContent = 'Загружаем ' + file.name + '…';
            }

            var fd = new FormData();
            fd.append('file', file);
            fd.append('kind', kind);
            fd.append('service', cfg.service);

            api('lab/upload', { method: 'POST', formData: fd }).then(function (res) {
                if (!res.ok || !res.data || !res.data.url) {
                    var message = (res.data && res.data.message) || 'Не удалось загрузить файл';
                    if (hint) {
                        hint.textContent = message;
                    }
                    note(message, 'error');
                    input.value = '';
                    return;
                }
                uploaded[kind] = res.data.url;
                if (hint) {
                    hint.textContent = 'Готово: ' + file.name + (res.data.duration ? ' — ' + duration(res.data.duration) : '');
                }
                // Цена считается от длительности: показываем её сразу,
                // чтобы списание не стало сюрпризом.
                if (els.price && res.data.price) {
                    els.price.textContent = money(res.data.price) + ' за эту обработку';
                }
                note('');
            }).catch(function () {
                if (hint) {
                    hint.textContent = 'Сеть недоступна, попробуйте ещё раз';
                }
            });
        });
    });

    /* ------------------------------------------------------------------ запуск */

    form.addEventListener('submit', function (e) {
        e.preventDefault();

        if (!cfg.loggedIn) {
            window.location.href = cfg.loginUrl;
            return;
        }

        var payload = { service: cfg.service };
        var missing = false;
        (cfg.inputs || []).forEach(function (kind) {
            if (!uploaded[kind]) {
                missing = true;
                return;
            }
            payload[kind + '_url'] = uploaded[kind];
        });

        if (missing) {
            note('Сначала загрузите файл', 'error');
            return;
        }
        if (els.prompt) {
            payload.prompt = els.prompt.value.trim();
        }

        setBusy(true);
        note('');
        show('loading');
        els.stage.textContent = 'Отправляем задачу…';
        els.progress.style.width = '10%';

        api('lab/generate', { method: 'POST', body: payload }).then(function (res) {
            if (!res.ok || !res.data || res.data.success !== true) {
                var message = (res.data && (res.data.message || res.data.code)) || 'Не удалось запустить обработку';
                if (res.status === 402) {
                    message += '. Пополните баланс, чтобы продолжить.';
                }
                setBusy(false);
                show('empty');
                note(message, 'error');
                return;
            }
            if (els.balance && typeof res.data.balance !== 'undefined') {
                els.balance.textContent = money(res.data.balance);
            }
            els.stage.textContent = 'Нейросеть обрабатывает файл…';
            els.progress.style.width = '30%';
            startPolling(res.data.task_id);
        }).catch(function () {
            setBusy(false);
            show('empty');
            note('Сеть недоступна, попробуйте ещё раз', 'error');
        });
    });

    function startPolling(taskId) {
        pollStarted = Date.now();
        var timeout = (cfg.pollSeconds || 600) * 1000;
        stopPolling();

        polling = setInterval(function () {
            var elapsed = Date.now() - pollStarted;
            if (elapsed > timeout) {
                stopPolling();
                setBusy(false);
                show('empty');
                note('Обработка занимает слишком долго. Загляните в историю через пару минут.', 'error');
                return;
            }
            els.progress.style.width = Math.min(92, 30 + (elapsed / timeout) * 120) + '%';

            api('lab/status/' + encodeURIComponent(cfg.service) + '/' + encodeURIComponent(taskId)).then(function (res) {
                var data = res.data || {};
                if (data.status === 'completed') {
                    stopPolling();
                    setBusy(false);
                    renderFiles(data.files || []);
                    if (els.balance && typeof data.balance !== 'undefined') {
                        els.balance.textContent = money(data.balance);
                    }
                    return;
                }
                if (data.status === 'failed') {
                    stopPolling();
                    setBusy(false);
                    show('empty');
                    note(data.message || 'Обработка не удалась, средства возвращены', 'error');
                    if (els.balance && typeof data.balance !== 'undefined') {
                        els.balance.textContent = money(data.balance);
                    }
                }
            }).catch(function () {
                /* разрыв сети — ждём следующего тика */
            });
        }, POLL_INTERVAL);
    }

    function renderFiles(files) {
        els.progress.style.width = '100%';
        els.files.innerHTML = '';

        files.forEach(function (file) {
            var wrap = document.createElement('div');
            wrap.className = 'gs-lab-file';

            var title = document.createElement('h3');
            title.className = 'gs-lab-file__title';
            title.textContent = file.label;
            wrap.appendChild(title);

            var media = document.createElement(file.kind === 'video' ? 'video' : 'audio');
            media.controls = true;
            media.src = file.url;
            media.className = file.kind === 'video' ? 'gs-lab-video' : 'gs-audio';
            if (file.kind === 'video') {
                media.setAttribute('playsinline', '');
            }
            wrap.appendChild(media);

            var link = document.createElement('a');
            link.className = 'gs-btn gs-btn--primary';
            link.href = file.url;
            link.setAttribute('download', '');
            link.textContent = 'Скачать';
            wrap.appendChild(link);

            els.files.appendChild(wrap);
        });

        show('ready');
        note('Готово! Файл сохранён в вашей истории.', 'ok');
    }

    if (els.again) {
        els.again.addEventListener('click', function () {
            show('empty');
            note('');
        });
    }
})();
