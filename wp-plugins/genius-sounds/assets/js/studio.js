/**
 * Genius Sounds — студия генерации звуков (Suno через KIE).
 */
(function () {
    'use strict';

    var cfg = window.GS_STUDIO || {};

    var form = document.getElementById('gs-sfx-form');
    if (!form) {
        return;
    }

    var els = {
        prompt:    document.getElementById('gs-prompt'),
        count:     document.getElementById('gs-prompt-count'),
        presets:   document.getElementById('gs-presets'),
        modes:     document.getElementById('gs-modes'),
        model:     document.getElementById('gs-model'),
        modelHint: document.getElementById('gs-model-hint'),
        seconds:   document.getElementById('gs-seconds'),
        secondsOut:document.getElementById('gs-seconds-out'),
        tempo:     document.getElementById('gs-tempo'),
        key:       document.getElementById('gs-key'),
        loop:      document.getElementById('gs-loop'),
        submit:    document.getElementById('gs-submit'),
        note:      document.getElementById('gs-form-note'),
        balance:   document.getElementById('gs-balance'),

        empty:     document.getElementById('gs-result-empty'),
        loading:   document.getElementById('gs-result-loading'),
        ready:     document.getElementById('gs-result-ready'),
        stage:     document.getElementById('gs-result-stage'),
        progress:  document.getElementById('gs-progress-bar'),
        audio:     document.getElementById('gs-result-audio'),
        title:     document.getElementById('gs-result-title'),
        promptOut: document.getElementById('gs-result-prompt'),
        download:  document.getElementById('gs-result-download'),
        again:     document.getElementById('gs-result-again'),

        history:     document.getElementById('gs-history'),
        historyList: document.getElementById('gs-history-list')
    };

    var polling = null;
    var pollStarted = 0;
    var POLL_INTERVAL = 4000;
    var POLL_TIMEOUT = 5 * 60 * 1000;

    /* ------------------------------------------------------------------ утилиты */

    function api(path, body) {
        return fetch(cfg.restUrl + path, {
            method: body ? 'POST' : 'GET',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': cfg.nonce
            },
            body: body ? JSON.stringify(body) : undefined
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
        els.submit.textContent = busy ? 'Генерируем…' : 'Создать звук за ' + Math.round(cfg.cost) + ' ₽';
    }

    function formatMoney(value) {
        return Number(value).toFixed(2).replace('.', ',') + ' ₽';
    }

    /* ------------------------------------------------------------------ форма */

    function updateCounter() {
        els.count.textContent = els.prompt.value.length;
    }

    function updateModelHint() {
        var option = els.model.options[els.model.selectedIndex];
        els.modelHint.textContent = option ? (option.getAttribute('data-hint') || '') : '';
    }

    function selectMode(value) {
        var labels = els.modes.querySelectorAll('.gs-mode');
        Array.prototype.forEach.call(labels, function (label) {
            var input = label.querySelector('input');
            var active = input && input.value === value;
            label.classList.toggle('is-active', !!active);
            if (input) {
                input.checked = !!active;
            }
        });
    }

    function currentMode() {
        var checked = els.modes.querySelector('input:checked');
        return checked ? checked.value : 'sfx';
    }

    els.prompt.addEventListener('input', updateCounter);
    updateCounter();

    els.model.addEventListener('change', updateModelHint);
    updateModelHint();

    els.seconds.addEventListener('input', function () {
        els.secondsOut.textContent = els.seconds.value;
    });

    els.modes.addEventListener('change', function (e) {
        if (e.target && e.target.name === 'mode') {
            selectMode(e.target.value);
        }
    });

    els.presets.addEventListener('click', function (e) {
        var button = e.target.closest('.gs-preset');
        if (!button) {
            return;
        }
        els.prompt.value = button.getAttribute('data-prompt') || '';
        updateCounter();
        var mode = button.getAttribute('data-mode');
        if (mode) {
            selectMode(mode);
        }
        els.prompt.focus();
    });

    if (els.again) {
        els.again.addEventListener('click', function () {
            show('empty');
            note('');
            els.prompt.focus();
        });
    }

    /* ------------------------------------------------------------------ генерация */

    form.addEventListener('submit', function (e) {
        e.preventDefault();

        if (!cfg.loggedIn) {
            window.location.href = cfg.loginUrl;
            return;
        }

        var prompt = els.prompt.value.trim();
        if (!prompt) {
            note('Опишите звук, который нужно создать', 'error');
            els.prompt.focus();
            return;
        }

        var payload = {
            prompt: prompt,
            mode: currentMode(),
            model: els.model.value,
            seconds: parseInt(els.seconds.value, 10) || 4,
            loop: !!els.loop.checked
        };

        var tempo = parseInt(els.tempo.value, 10);
        if (tempo > 0) {
            payload.tempo = tempo;
        }
        if (els.key.value) {
            payload.key = els.key.value;
        }

        setBusy(true);
        note('');
        show('loading');
        els.stage.textContent = 'Отправляем задачу в Suno…';
        els.progress.style.width = '8%';

        api('sfx/generate', payload).then(function (res) {
            if (!res.ok || !res.data || res.data.success !== true) {
                var message = (res.data && (res.data.message || res.data.code)) || 'Не удалось запустить генерацию';
                if (res.status === 402) {
                    message += '. Пополните баланс, чтобы продолжить.';
                }
                setBusy(false);
                show('empty');
                note(message, 'error');
                return;
            }

            if (els.balance && typeof res.data.balance !== 'undefined') {
                els.balance.textContent = formatMoney(res.data.balance);
            }
            els.stage.textContent = 'Suno собирает звук…';
            els.progress.style.width = '28%';
            startPolling(res.data.task_id, prompt);
        }).catch(function () {
            setBusy(false);
            show('empty');
            note('Сеть недоступна, попробуйте ещё раз', 'error');
        });
    });

    function startPolling(taskId, prompt) {
        pollStarted = Date.now();
        stopPolling();
        polling = setInterval(function () {
            var elapsed = Date.now() - pollStarted;

            if (elapsed > POLL_TIMEOUT) {
                stopPolling();
                setBusy(false);
                show('empty');
                note('Генерация занимает слишком долго. Загляните в историю через пару минут.', 'error');
                return;
            }

            // Плавно тянем полосу до 92%, пока ждём.
            var pct = Math.min(92, 28 + (elapsed / POLL_TIMEOUT) * 140);
            els.progress.style.width = pct + '%';

            api('sfx/status/' + encodeURIComponent(taskId)).then(function (res) {
                var data = res.data || {};

                if (data.status === 'completed' && data.audio_url) {
                    stopPolling();
                    setBusy(false);
                    renderResult(data, prompt);
                    if (els.balance && typeof data.balance !== 'undefined') {
                        els.balance.textContent = formatMoney(data.balance);
                    }
                    loadHistory();
                    return;
                }

                if (data.status === 'failed') {
                    stopPolling();
                    setBusy(false);
                    show('empty');
                    note(data.message || 'Генерация не удалась, средства возвращены', 'error');
                    if (els.balance && typeof data.balance !== 'undefined') {
                        els.balance.textContent = formatMoney(data.balance);
                    }
                    return;
                }

                if (data.stage === 'TEXT_SUCCESS' || data.stage === 'FIRST_SUCCESS') {
                    els.stage.textContent = 'Почти готово, сводим звук…';
                }
            }).catch(function () {
                // Разрыв сети — просто ждём следующего тика.
            });
        }, POLL_INTERVAL);
    }

    function stopPolling() {
        if (polling) {
            clearInterval(polling);
            polling = null;
        }
    }

    function renderResult(data, prompt) {
        els.progress.style.width = '100%';
        show('ready');
        els.title.textContent = data.title && data.title !== '' ? data.title : 'Звук готов';
        els.promptOut.textContent = '«' + prompt + '»';
        els.audio.src = data.audio_url;
        els.download.href = data.audio_url;
        els.download.setAttribute('download', 'genius-sound.mp3');
        note('Готово! Звук сохранён в вашей истории.', 'ok');
    }

    /* ------------------------------------------------------------------ история */

    function loadHistory() {
        if (!cfg.loggedIn || !els.historyList) {
            return;
        }
        api('sfx/history').then(function (res) {
            var items = (res.data && res.data.items) || [];
            var done = items.filter(function (item) {
                return item.audio_url && item.status === 'completed';
            });

            if (!done.length) {
                els.history.hidden = true;
                return;
            }

            els.historyList.innerHTML = '';
            done.slice(0, 10).forEach(function (item) {
                var li = document.createElement('li');
                li.className = 'gs-history__item';

                var span = document.createElement('span');
                span.className = 'gs-history__prompt';
                span.textContent = item.prompt || 'Звук';
                span.title = item.prompt || '';

                var button = document.createElement('button');
                button.type = 'button';
                button.className = 'gs-history__play';
                button.textContent = 'Слушать';
                button.addEventListener('click', function () {
                    renderResult({ audio_url: item.audio_url, title: 'Звук из истории' }, item.prompt || '');
                });

                li.appendChild(span);
                li.appendChild(button);
                els.historyList.appendChild(li);
            });

            els.history.hidden = false;
        }).catch(function () {
            /* история не критична */
        });
    }

    loadHistory();
})();
