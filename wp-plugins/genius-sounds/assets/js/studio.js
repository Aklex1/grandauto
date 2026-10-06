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
        historyList: document.getElementById('gs-history-list'),

        switchBox:  document.getElementById('gs-speech-switch'),
        switchLink: document.querySelector('[data-gs-switch-link]')
    };

    var polling = null;
    var pollStarted = 0;

    /* ------------------------------------------------------------------ звук или голос
     *
     * Часть людей приходит сюда за озвучкой: пишут реплику в кавычках и
     * ждут, что её произнесут. Suno соберёт из этого шум, деньги спишутся.
     * Правила те же, что в GS_Intent на сервере: держим их рядом.
     */

    var SPEECH_STRONG = [
        'закадровый голос', 'закадровым голосом', 'голос за кадром',
        'озвучить текст', 'озвучка текста', 'текст для озвучки',
        'озвучь текст', 'voiceover', 'voice over', 'войсовер'
    ];
    var SPEECH_VERBS = [
        'скажи', 'сказал', 'сказать', 'говорит', 'говорить', 'говорят',
        'произнес', 'произнёс', 'произнос', 'проговор', 'озвуч', 'наговор',
        'прочита', 'прочти', 'зачита', 'читает', 'читай', 'читать',
        'приветствует', 'представляется',
        ' say ', ' says ', 'speak', 'narrat'
    ];
    var SPEECH_NOUNS = [
        'голос', 'диктор', 'озвучк', 'речь', 'реплик', 'монолог', 'диалог',
        'интонац', 'тембр', 'фраз', 'текст', 'закадров',
        'voice', 'tts'
    ];
    var QUOTED = /[«"“„']\s*([^«»"“”„']{6,})\s*[»"”“']/;

    function quotedPart(text) {
        var m = QUOTED.exec(text || '');
        return m ? m[1].trim() : '';
    }

    function speechScore(text) {
        text = (text || '').trim();
        if (!text) {
            return 0;
        }
        var low = text.toLowerCase();
        var score = 0;
        var i;

        for (i = 0; i < SPEECH_STRONG.length; i++) {
            if (low.indexOf(SPEECH_STRONG[i]) !== -1) {
                return 3;
            }
        }

        var quote = quotedPart(text);
        if (quote) {
            score += quote.length >= 25 ? 3 : 2;
        }

        for (i = 0; i < SPEECH_VERBS.length; i++) {
            if (low.indexOf(SPEECH_VERBS[i]) !== -1) {
                score += 2;
                break;
            }
        }

        // Слова про голос считаем не больше двух: без глагола речи
        // «гул голосов в кафе» должен остаться звуком.
        var nouns = 0;
        for (i = 0; i < SPEECH_NOUNS.length; i++) {
            if (low.indexOf(SPEECH_NOUNS[i]) !== -1) {
                nouns++;
                if (nouns >= 2) {
                    break;
                }
            }
        }
        score += nouns;

        return score;
    }

    function looksLikeSpeech(text) {
        return speechScore(text) >= 3;
    }

    /** Подсказку показываем и обновляем ссылку: текст уходит в озвучку как есть. */
    function updateSwitch() {
        if (!els.switchBox) {
            return;
        }
        var text = els.prompt.value.trim();
        var show = looksLikeSpeech(text);
        els.switchBox.hidden = !show;
        if (show && els.switchLink && cfg.ttsUrl) {
            var carry = quotedPart(text) || text;
            var sep = cfg.ttsUrl.indexOf('?') === -1 ? '?' : '&';
            els.switchLink.href = cfg.ttsUrl + sep + (cfg.ttsArg || 'gs_text')
                + '=' + encodeURIComponent(carry.slice(0, 900));
        }
        if (!show) {
            speechConfirmed = false;
        }
    }

    // Первое нажатие на «Создать звук» с текстом-репликой не тратит деньги:
    // сначала предупреждение, и только повторное нажатие запускает генерацию.
    var speechConfirmed = false;
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

    els.prompt.addEventListener('input', function () {
        updateCounter();
        updateSwitch();
    });
    updateCounter();
    updateSwitch();

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
        updateSwitch();
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

        if (!cfg.loggedIn && !cfg.trialLeft) {
            window.location.href = cfg.loginUrl;
            return;
        }

        var prompt = els.prompt.value.trim();
        if (!prompt) {
            note('Опишите звук, который нужно создать', 'error');
            els.prompt.focus();
            return;
        }

        if (looksLikeSpeech(prompt) && !speechConfirmed) {
            speechConfirmed = true;
            updateSwitch();
            if (els.switchBox) {
                els.switchBox.hidden = false;
                els.switchBox.classList.add('is-alarm');
                els.switchBox.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
            note('Похоже, вы хотите, чтобы текст произнесли голосом — это озвучка, а не генератор звуков. '
                + 'Перейдите по кнопке выше или нажмите «Создать звук» ещё раз, если вам действительно нужен шум.', 'error');
            return;
        }

        if (!cfg.loggedIn) {
            runTrial(prompt);
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

    /**
     * Пробный звук гостю.
     *
     * Отдельный маршрут и отдельный опрос состояния: обычные требуют
     * аккаунта и списывают деньги, а здесь ни того, ни другого. Длина и
     * режим фиксированы — это демонстрация, а не полноценная генерация.
     */
    function runTrial(prompt) {
        setBusy(true);
        note('');
        show('loading');
        els.stage.textContent = 'Отправляем задачу в Suno…';
        els.progress.style.width = '8%';

        api('sfx/trial', { prompt: prompt }).then(function (res) {
            if (!res.ok || !res.data || res.data.success !== true) {
                var message = (res.data && (res.data.message || res.data.code))
                    || 'Не удалось запустить генерацию';
                setBusy(false);
                show('empty');
                note(message, 'error');
                if (res.status === 429) { offerAccount(true); }
                return;
            }
            cfg.trialLeft = res.data['осталось'] || 0;
            els.stage.textContent = 'Suno собирает звук…';
            els.progress.style.width = '28%';
            pollTrial(res.data.task_id, prompt);
        }).catch(function () {
            setBusy(false);
            show('empty');
            note('Сеть недоступна, попробуйте ещё раз', 'error');
        });
    }

    function pollTrial(taskId, prompt) {
        pollStarted = Date.now();
        stopPolling();
        polling = setInterval(function () {
            var elapsed = Date.now() - pollStarted;
            if (elapsed > POLL_TIMEOUT) {
                stopPolling();
                setBusy(false);
                show('empty');
                note('Генерация занимает слишком долго. Попробуйте ещё раз.', 'error');
                return;
            }
            els.progress.style.width = Math.min(92, 28 + (elapsed / POLL_TIMEOUT) * 140) + '%';

            api('sfx/trial/' + encodeURIComponent(taskId)).then(function (res) {
                var data = res.data || {};
                if (data.status === 'completed' && data.audio_url) {
                    stopPolling();
                    setBusy(false);
                    renderResult(data, prompt);
                    note('Готово. Это пробный звук на пять секунд.', 'ok');
                    offerAccount(false);
                    if (window.ym && window.gsGoals && window.gsGoals.counter) {
                        window.ym(window.gsGoals.counter, 'reachGoal', 'trial_sound');
                    }
                    return;
                }
                if (data.status === 'failed') {
                    stopPolling();
                    setBusy(false);
                    show('empty');
                    note(data.message || 'Генерация не удалась, попробуйте другое описание', 'error');
                    return;
                }
                if (data.stage === 'TEXT_SUCCESS' || data.stage === 'FIRST_SUCCESS') {
                    els.stage.textContent = 'Почти готово, сводим звук…';
                }
            }).catch(function () { /* разрыв сети — ждём следующего тика */ });
        }, POLL_INTERVAL);
    }

    /**
     * Предложение аккаунта — после результата, а не до него.
     *
     * Человек уже услышал, что получается; теперь понятно, за что
     * предлагают регистрацию.
     */
    function offerAccount(spent) {
        if (document.getElementById('gs-trial-offer')) { return; }
        var box = document.createElement('div');
        box.id = 'gs-trial-offer';
        box.className = 'gs-trial-offer';
        box.innerHTML = (spent
                ? '<strong>Бесплатный звук на сегодня уже создан.</strong> '
                : '<strong>Понравилось?</strong> ')
            + 'С аккаунтом открываются длина до 60 секунд, бесшовные лупы, история звуков и '
            + 'скачивание в один клик. Звук стоит ' + formatMoney(cfg.cost) + ', оплата с баланса.'
            + '<div class="gs-trial-offer__row">'
            + '<a class="gs-btn gs-btn--primary" data-gs-auth href="' + (cfg.loginUrl || '/tts-login/') + '">Создать аккаунт</a>'
            + '<a class="gs-btn gs-btn--ghost" href="' + (cfg.catalogUrl || '/sounds-catalog/') + '">Готовые звуки в каталоге</a>'
            + '</div>';
        var holder = document.getElementById('gs-result') || form;
        holder.appendChild(box);
    }

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
