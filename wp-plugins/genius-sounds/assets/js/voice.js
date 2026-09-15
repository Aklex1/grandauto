/**
 * Genius Sounds — песня своим голосом.
 *
 * Мастер из трёх шагов: образец голоса, чтение проверочной фразы, песня.
 * Запись идёт прямо в браузере через MediaRecorder; если микрофона нет
 * или доступ к нему запрещён, остаётся загрузка файла — поэтому оба
 * способа равноправны, а не «основной и запасной».
 */
(function () {
    'use strict';

    var cfg = window.GS_VOICE || {};
    var root = document.querySelector('.gs-voice');
    if (!root || !cfg.restUrl) {
        return;
    }

    var els = {
        note:     document.getElementById('gs-voice-note'),
        phrase:   document.getElementById('gs-voice-phrase'),
        start:    document.getElementById('gs-voice-start'),
        verifyGo: document.getElementById('gs-voice-verify-go'),
        sing:     document.getElementById('gs-voice-sing'),
        pick:     document.getElementById('gs-voice-pick'),
        empty:    document.getElementById('gs-voice-empty'),
        loading:  document.getElementById('gs-voice-loading'),
        ready:    document.getElementById('gs-voice-ready'),
        stage:    document.getElementById('gs-voice-stage'),
        progress: document.getElementById('gs-voice-progress'),
        files:    document.getElementById('gs-voice-files'),
        again:    document.getElementById('gs-voice-again'),
        balance:  document.getElementById('gs-voice-balance'),
        balance2: document.getElementById('gs-voice-balance-2')
    };

    var state = { sample: '', verify: '', task: '', song: '' };
    var timer = null;

    /* ------------------------------------------------------------------ сеть */

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
        }).then(function (r) {
            return r.json().then(function (data) {
                return { ok: r.ok, status: r.status, data: data };
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

    function stage(text, percent) {
        els.stage.textContent = text;
        els.progress.style.width = Math.max(4, Math.min(100, percent)) + '%';
    }

    function money(value) {
        return Number(value).toFixed(2).replace('.', ',') + ' ₽';
    }

    function setBalance(value) {
        if (value === undefined || value === null) {
            return;
        }
        if (els.balance) { els.balance.textContent = money(value); }
        if (els.balance2) { els.balance2.textContent = money(value); }
    }

    function stopTimer() {
        if (timer) { clearInterval(timer); timer = null; }
    }

    function step(n) {
        Array.prototype.forEach.call(root.querySelectorAll('.gs-voice__step'), function (block) {
            block.hidden = Number(block.getAttribute('data-step')) !== n;
        });
        Array.prototype.forEach.call(root.querySelectorAll('.gs-wizard__step'), function (chip) {
            var own = Number(chip.getAttribute('data-step'));
            chip.classList.toggle('is-active', own === n);
            chip.classList.toggle('is-done', own < n);
        });
    }

    /* --------------------------------------------------------------- загрузка */

    function upload(blob, name) {
        var fd = new FormData();
        fd.append('file', blob, name);
        fd.append('kind', 'audio');
        fd.append('service', 'voicesong');
        return api('lab/upload', { method: 'POST', formData: fd }).then(function (res) {
            if (!res.ok || !res.data || !res.data.url) {
                throw new Error((res.data && res.data.message) || 'Не удалось загрузить запись');
            }
            return res.data.url;
        });
    }

    /* ------------------------------------------------------------- в WAV */

    /**
     * Браузер пишет в webm/opus, а поставщику нужен обычный звуковой файл.
     * Перекодируем на месте в моно WAV 16 кГц: так запись принимает любой
     * сервис, и не приходится держать перекодировщик на сервере.
     */
    function toWav(blob) {
        var Ctx = window.AudioContext || window.webkitAudioContext;
        if (!Ctx) {
            return Promise.resolve(blob);
        }
        return blob.arrayBuffer().then(function (buffer) {
            var ctx = new Ctx();
            return ctx.decodeAudioData(buffer).then(function (audio) {
                ctx.close();
                return encodeWav(downmix(audio), 16000, audio.sampleRate);
            });
        }).catch(function () {
            return blob; // не вышло — отдадим как есть, сервер разберётся
        });
    }

    function downmix(audio) {
        var channels = audio.numberOfChannels;
        var length = audio.length;
        var out = new Float32Array(length);
        for (var c = 0; c < channels; c++) {
            var data = audio.getChannelData(c);
            for (var i = 0; i < length; i++) {
                out[i] += data[i] / channels;
            }
        }
        return out;
    }

    function encodeWav(samples, targetRate, sourceRate) {
        var ratio = sourceRate / targetRate;
        var length = Math.floor(samples.length / ratio);
        var buffer = new ArrayBuffer(44 + length * 2);
        var view = new DataView(buffer);

        function str(offset, text) {
            for (var i = 0; i < text.length; i++) {
                view.setUint8(offset + i, text.charCodeAt(i));
            }
        }

        str(0, 'RIFF');
        view.setUint32(4, 36 + length * 2, true);
        str(8, 'WAVEfmt ');
        view.setUint32(16, 16, true);
        view.setUint16(20, 1, true);
        view.setUint16(22, 1, true);
        view.setUint32(24, targetRate, true);
        view.setUint32(28, targetRate * 2, true);
        view.setUint16(32, 2, true);
        view.setUint16(34, 16, true);
        str(36, 'data');
        view.setUint32(40, length * 2, true);

        for (var i = 0; i < length; i++) {
            var value = samples[Math.floor(i * ratio)] || 0;
            value = Math.max(-1, Math.min(1, value));
            view.setInt16(44 + i * 2, value < 0 ? value * 0x8000 : value * 0x7fff, true);
        }
        return new Blob([view], { type: 'audio/wav' });
    }

    /* ----------------------------------------------------------------- запись */

    function wireRecorder(box, onReady) {
        var button = box.querySelector('[data-action="rec"]');
        var time = box.querySelector('[data-role="time"]');
        var play = box.querySelector('[data-role="play"]');
        var recorder = null;
        var chunks = [];
        var ticking = null;
        var started = 0;

        if (!navigator.mediaDevices || !window.MediaRecorder) {
            button.disabled = true;
            button.textContent = 'Запись недоступна — загрузите файл';
            return;
        }

        function tick() {
            var sec = Math.floor((Date.now() - started) / 1000);
            time.textContent = Math.floor(sec / 60) + ':' + ('0' + (sec % 60)).slice(-2);
            // Дальше тридцати секунд записи нет смысла: поставщик разбирает начало.
            if (sec >= 30) { stop(); }
        }

        function stop() {
            if (recorder && recorder.state === 'recording') {
                recorder.stop();
            }
        }

        button.addEventListener('click', function () {
            if (recorder && recorder.state === 'recording') {
                stop();
                return;
            }
            navigator.mediaDevices.getUserMedia({ audio: true }).then(function (stream) {
                chunks = [];
                recorder = new MediaRecorder(stream);
                recorder.ondataavailable = function (e) { if (e.data.size) { chunks.push(e.data); } };
                recorder.onstop = function () {
                    clearInterval(ticking);
                    stream.getTracks().forEach(function (t) { t.stop(); });
                    button.textContent = 'Записать заново';
                    button.classList.remove('is-rec');
                    var blob = new Blob(chunks, { type: recorder.mimeType || 'audio/webm' });
                    play.src = URL.createObjectURL(blob);
                    play.hidden = false;
                    note('Обрабатываем запись…');
                    toWav(blob).then(function (wav) {
                        return upload(wav, 'zapis.wav');
                    }).then(function (url) {
                        note('');
                        onReady(url);
                    }).catch(function (e) {
                        note(e.message, 'error');
                    });
                };
                recorder.start();
                started = Date.now();
                ticking = setInterval(tick, 500);
                button.textContent = 'Остановить';
                button.classList.add('is-rec');
                note('Идёт запись — говорите обычным голосом.');
            }).catch(function () {
                note('Микрофон недоступен. Разрешите доступ в браузере или загрузите готовый файл.', 'error');
            });
        });
    }

    function wireFile(input, onReady) {
        if (!input) { return; }
        input.addEventListener('change', function () {
            if (!input.files || !input.files.length) { return; }
            note('Загружаем файл…');
            upload(input.files[0], input.files[0].name).then(function (url) {
                note('');
                onReady(url);
            }).catch(function (e) {
                note(e.message, 'error');
            });
        });
    }

    var sampleBox = root.querySelector('[data-rec="sample"]');
    var verifyBox = root.querySelector('[data-rec="verify"]');
    if (sampleBox) {
        wireRecorder(sampleBox, function (url) {
            state.sample = url;
            els.start.disabled = false;
        });
    }
    if (verifyBox) {
        wireRecorder(verifyBox, function (url) {
            state.verify = url;
            els.verifyGo.disabled = false;
        });
    }
    wireFile(document.getElementById('gs-voice-sample'), function (url) {
        state.sample = url;
        els.start.disabled = false;
    });
    wireFile(document.getElementById('gs-voice-verify'), function (url) {
        state.verify = url;
        els.verifyGo.disabled = false;
    });

    /* ------------------------------------------------------- шаг 1: фраза */

    els.start.addEventListener('click', function () {
        if (!state.sample) {
            note('Сначала запишите или загрузите свой голос', 'error');
            return;
        }
        els.start.disabled = true;
        show('loading');
        stage('Разбираем голос…', 15);
        note('');

        api('voice/phrase', { method: 'POST', body: { audio_url: state.sample } }).then(function (res) {
            if (!res.ok) {
                els.start.disabled = false;
                show('empty');
                note((res.data && res.data.message) || 'Не удалось начать', 'error');
                return;
            }
            state.task = res.data.task_id;
            setBalance(res.data.balance);
            step(2);
            pollPhrase();
        });
    });

    function pollPhrase() {
        stopTimer();
        var tries = 0;
        timer = setInterval(function () {
            tries++;
            stage('Готовим проверочную фразу…', Math.min(70, 15 + tries * 6));
            api('voice/phrase/' + state.task).then(function (res) {
                var d = res.data || {};
                if (d.status === 'ready') {
                    stopTimer();
                    show('empty');
                    els.phrase.textContent = d.phrase;
                    var button = verifyBox && verifyBox.querySelector('[data-action="rec"]');
                    if (button && window.MediaRecorder) { button.disabled = false; }
                    note('Прочитайте фразу вслух и запишите — это подтвердит, что голос ваш.');
                } else if (d.status === 'failed') {
                    stopTimer();
                    show('empty');
                    step(1);
                    els.start.disabled = false;
                    note(d.message || 'Не получилось разобрать голос', 'error');
                }
            });
        }, 6000);
    }

    /* ------------------------------------------------------- шаг 2: голос */

    els.verifyGo.addEventListener('click', function () {
        if (!state.verify) {
            note('Запишите проверочную фразу', 'error');
            return;
        }
        els.verifyGo.disabled = true;
        show('loading');
        stage('Проверяем запись…', 30);

        var name = (document.getElementById('gs-voice-name') || {}).value || '';
        api('voice/verify', { method: 'POST', body: { task_id: state.task, audio_url: state.verify, name: name } })
            .then(function (res) {
                if (!res.ok) {
                    els.verifyGo.disabled = false;
                    show('empty');
                    note((res.data && res.data.message) || 'Запись не принята', 'error');
                    return;
                }
                pollVoice();
            });
    });

    function pollVoice() {
        stopTimer();
        var tries = 0;
        timer = setInterval(function () {
            tries++;
            stage('Создаём голос…', Math.min(90, 30 + tries * 5));
            api('voice/verify/' + state.task).then(function (res) {
                var d = res.data || {};
                if (d.status === 'completed') {
                    stopTimer();
                    show('empty');
                    fillVoices(d.voices, d.voice_id);
                    if (steps) { steps.hidden = true; }
                    if (ready) { ready.hidden = false; }
                    step(3);
                    note('Голос готов. Больше его создавать не нужно — дальше только текст и стиль.', 'ok');
                } else if (d.status === 'failed') {
                    stopTimer();
                    show('empty');
                    els.verifyGo.disabled = false;
                    note(d.message || 'Голос не создался — попробуйте записать фразу заново', 'error');
                }
            });
        }, 6000);
    }

    function fillVoices(voices, selected) {
        if (!els.pick || !voices || !voices.length) { return; }
        els.pick.innerHTML = '';
        voices.forEach(function (voice) {
            var option = document.createElement('option');
            option.value = voice.id;
            option.textContent = voice.name;
            if (voice.id === selected) { option.selected = true; }
            els.pick.appendChild(option);
        });
    }

    /* ------------------------------------------------------- шаг 3: песня */

    els.sing.addEventListener('click', function () {
        var voice = els.pick ? els.pick.value : '';
        if (!voice) {
            note('Сначала создайте свой голос', 'error');
            return;
        }
        var lyrics = (document.getElementById('gs-voice-lyrics') || {}).value || '';
        var about  = (document.getElementById('gs-voice-about') || {}).value || '';
        if (!lyrics.trim() && !about.trim()) {
            note('Напишите текст песни или опишите, о чём она', 'error');
            return;
        }

        els.sing.disabled = true;
        show('loading');
        stage('Пишем песню…', 10);
        note('');

        api('voice/song', { method: 'POST', body: {
            voice_id: voice,
            lyrics: lyrics,
            about: about,
            prompt: about,
            style: (document.getElementById('gs-voice-style') || {}).value || '',
            title: (document.getElementById('gs-voice-title') || {}).value || ''
        } }).then(function (res) {
            if (!res.ok) {
                els.sing.disabled = false;
                show('empty');
                note((res.data && res.data.message) || 'Не удалось запустить генерацию', 'error');
                return;
            }
            state.song = res.data.task_id;
            setBalance(res.data.balance);
            pollSong();
        });
    });

    /* ------------------------------------------------------- архив песен */

    var archive = document.getElementById('gs-voice-archive');
    var archiveList = document.getElementById('gs-voice-archive-list');

    function renderArchive(songs) {
        if (!archiveList || !songs) { return; }
        archiveList.innerHTML = '';
        songs.forEach(function (song) {
            var item = document.createElement('article');
            item.className = 'gs-archive__item';
            item.setAttribute('data-song', song.id);

            var head = document.createElement('div');
            head.className = 'gs-archive__head';
            var title = document.createElement('span');
            title.className = 'gs-archive__title';
            title.textContent = song.title;
            head.appendChild(title);
            if (song.created) {
                var date = document.createElement('span');
                date.className = 'gs-archive__date';
                date.textContent = new Date(song.created * 1000).toLocaleDateString('ru-RU');
                head.appendChild(date);
            }
            item.appendChild(head);

            var audio = document.createElement('audio');
            audio.controls = true;
            audio.preload = 'none';
            audio.src = song.url;
            audio.className = 'gs-archive__audio';
            item.appendChild(audio);

            var actions = document.createElement('div');
            actions.className = 'gs-archive__actions';

            var link = document.createElement('a');
            link.className = 'gs-btn gs-btn--ghost';
            link.href = song.url;
            link.setAttribute('download', '');
            link.textContent = 'Скачать MP3';
            actions.appendChild(link);

            if (song.published) {
                var done = document.createElement('span');
                done.className = 'gs-archive__done';
                done.textContent = 'В галерее';
                actions.appendChild(done);
            } else {
                var publish = document.createElement('button');
                publish.type = 'button';
                publish.className = 'gs-btn gs-btn--ghost';
                publish.setAttribute('data-publish', song.id);
                publish.textContent = 'Опубликовать в галерее';
                actions.appendChild(publish);
            }

            item.appendChild(actions);
            archiveList.appendChild(item);
        });
        if (archive) { archive.hidden = songs.length === 0; }
    }

    // Публикация: имя автора спрашиваем прямо здесь, чтобы не гонять
    // человека в настройки профиля ради одной подписи.
    if (archiveList) {
        archiveList.addEventListener('click', function (e) {
            var button = e.target.closest('[data-publish]');
            if (!button) { return; }
            var author = window.prompt('Как подписать песню в галерее?', cfg.displayName || '');
            if (author === null) { return; }
            button.disabled = true;
            button.textContent = 'Публикуем…';
            api('voice/publish', { method: 'POST', body: {
                song_id: button.getAttribute('data-publish'), author: author
            } }).then(function (res) {
                if (!res.ok) {
                    button.disabled = false;
                    button.textContent = 'Опубликовать в галерее';
                    note((res.data && res.data.message) || 'Не удалось опубликовать', 'error');
                    return;
                }
                renderArchive(res.data.songs);
                note('Песня в галерее.', 'ok');
            });
        });
    }

    function pollSong() {
        stopTimer();
        var tries = 0;
        timer = setInterval(function () {
            tries++;
            stage('Голос поёт — обычно это занимает пару минут…', Math.min(95, 10 + tries * 3));
            api('voice/song/' + state.song).then(function (res) {
                var d = res.data || {};
                if (d.status === 'completed') {
                    stopTimer();
                    renderFiles(d.files);
                    renderArchive(d.archive);
                    setBalance(d.balance);
                    els.sing.disabled = false;
                    show('ready');
                    note('Песня сохранена в ваш архив ниже — её можно скачать и опубликовать в галерее.', 'ok');
                } else if (d.status === 'failed') {
                    stopTimer();
                    els.sing.disabled = false;
                    show('empty');
                    setBalance(d.balance);
                    note(d.message || 'Не получилось — деньги вернулись на баланс', 'error');
                }
            });
        }, 7000);
    }

    function renderFiles(files) {
        els.files.innerHTML = '';
        (files || []).forEach(function (file) {
            var box = document.createElement('div');
            box.className = 'gs-result__file';

            var title = document.createElement('p');
            title.className = 'gs-result__label';
            title.textContent = file.label;
            box.appendChild(title);

            var audio = document.createElement('audio');
            audio.controls = true;
            audio.src = file.url;
            audio.className = 'gs-result__audio';
            box.appendChild(audio);

            var link = document.createElement('a');
            link.className = 'gs-btn gs-btn--ghost gs-result__download';
            link.href = file.url;
            link.target = '_blank';
            link.rel = 'noopener';
            link.textContent = 'Скачать MP3';
            box.appendChild(link);

            els.files.appendChild(box);
        });
    }

    els.again.addEventListener('click', function () {
        show('empty');
        note('');
    });

    /* ------------------------------------------- голос уже есть */

    // С готовым голосом лестница из шагов не нужна: человек пришёл за песней,
    // а не за повторной регистрацией голоса.
    var steps = document.getElementById('gs-voice-steps');
    var more = document.getElementById('gs-voice-more');
    var back = document.getElementById('gs-voice-back');
    var ready = document.querySelector('.gs-voice__ready');

    function songMode() {
        if (steps) { steps.hidden = true; }
        if (ready) { ready.hidden = false; }
        step(3);
        note('');
    }

    function voiceMode() {
        if (steps) { steps.hidden = false; }
        if (ready) { ready.hidden = true; }
        step(1);
        note('');
    }

    if (more) { more.addEventListener('click', voiceMode); }
    if (back) { back.addEventListener('click', songMode); }

    if (cfg.hasVoice) {
        songMode();
    }
})();
