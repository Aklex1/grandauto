/**
 * Звук из своего файла — прямо в браузере.
 *
 * Дорожка по ссылке снимается на сервере, и это единственный способ добраться
 * до чужого ролика. Но когда файл уже лежит на компьютере, гонять его через
 * сеть незачем: браузер сам умеет разобрать видео и отдать звук. Так человек
 * получает результат за секунды вместо минут, не упирается в размер загрузки
 * и не отправляет свою запись никуда — она не покидает его машину.
 *
 * Отсюда и устройство: декодируем файл через Web Audio, потом собираем WAV
 * своими руками или зовём кодировщик MP3. Кодировщик подгружается только в
 * тот момент, когда он понадобился.
 */
(function () {
    'use strict';

    var root = document.getElementById('gs-ytl');
    if (!root) {
        return;
    }

    var input  = document.getElementById('gs-ytl-file');
    var note   = document.getElementById('gs-ytl-note');
    var out    = document.getElementById('gs-ytl-out');
    var drop   = root.querySelector('.gs-ytl__drop');
    var fmtSel = document.getElementById('gs-lab-f-format');

    // Декодированная дорожка живёт в памяти целиком: час записи — это больше
    // гигабайта, и вкладка такого не переживёт. Предупреждаем заранее.
    var SOFT_MINUTES = 25;
    var MAX_BYTES = 700 * 1024 * 1024;

    var busy = false;

    function say(text, kind) {
        note.textContent = text || '';
        note.className = 'gs-ytl__note' + (kind ? ' is-' + kind : '');
    }

    function format() {
        var value = fmtSel ? String(fmtSel.value || 'mp3').toLowerCase() : 'mp3';
        return value === 'wav' ? 'wav' : 'mp3';
    }

    function baseName(name) {
        return String(name || 'dorozhka').replace(/\.[^.]+$/, '').slice(0, 60) || 'dorozhka';
    }

    function minutes(seconds) {
        var m = Math.floor(seconds / 60);
        var s = Math.round(seconds % 60);
        return m + ' мин ' + (s < 10 ? '0' : '') + s + ' с';
    }

    /* ------------------------------------------------------------------ */

    /** Загружаем кодировщик MP3 один раз и только когда он нужен. */
    var lamePromise = null;
    function lame() {
        if (lamePromise) {
            return lamePromise;
        }
        lamePromise = new Promise(function (resolve, reject) {
            if (window.lamejs) {
                resolve(window.lamejs);
                return;
            }
            var s = document.createElement('script');
            s.src = root.getAttribute('data-lame');
            s.onload = function () {
                window.lamejs ? resolve(window.lamejs) : reject(new Error('lame'));
            };
            s.onerror = function () { reject(new Error('lame')); };
            document.head.appendChild(s);
        });
        return lamePromise;
    }

    /** Float32 −1..1 в 16-битные отсчёты, как их ждут и WAV, и MP3. */
    function toInt16(channel, from, to) {
        var out = new Int16Array(to - from);
        for (var i = from; i < to; i++) {
            var v = channel[i];
            v = v > 1 ? 1 : (v < -1 ? -1 : v);
            out[i - from] = v < 0 ? v * 0x8000 : v * 0x7fff;
        }
        return out;
    }

    function wav(buffer) {
        var channels = Math.min(2, buffer.numberOfChannels);
        var left = buffer.getChannelData(0);
        var right = channels > 1 ? buffer.getChannelData(1) : null;
        var frames = buffer.length;
        var bytes = frames * channels * 2;

        var head = new DataView(new ArrayBuffer(44));
        var ascii = function (off, text) {
            for (var i = 0; i < text.length; i++) { head.setUint8(off + i, text.charCodeAt(i)); }
        };
        ascii(0, 'RIFF');
        head.setUint32(4, 36 + bytes, true);
        ascii(8, 'WAVEfmt ');
        head.setUint32(16, 16, true);
        head.setUint16(20, 1, true);                       // PCM
        head.setUint16(22, channels, true);
        head.setUint32(24, buffer.sampleRate, true);
        head.setUint32(28, buffer.sampleRate * channels * 2, true);
        head.setUint16(32, channels * 2, true);
        head.setUint16(34, 16, true);
        ascii(36, 'data');
        head.setUint32(40, bytes, true);

        var body = new Int16Array(frames * channels);
        for (var i = 0, j = 0; i < frames; i++) {
            var l = left[i];
            body[j++] = l < 0 ? Math.max(-1, l) * 0x8000 : Math.min(1, l) * 0x7fff;
            if (right) {
                var r = right[i];
                body[j++] = r < 0 ? Math.max(-1, r) * 0x8000 : Math.min(1, r) * 0x7fff;
            }
        }
        return new Blob([head.buffer, body.buffer], { type: 'audio/wav' });
    }

    /**
     * MP3 кодируется кусками с паузами между ними: без пауз вкладка замирает
     * на всё время кодирования и выглядит зависшей.
     */
    function mp3(buffer, onProgress) {
        return lame().then(function (lib) {
            return new Promise(function (resolve, reject) {
                var channels = Math.min(2, buffer.numberOfChannels);
                var encoder;
                try {
                    encoder = new lib.Mp3Encoder(channels, buffer.sampleRate, 192);
                } catch (e) {
                    reject(e);
                    return;
                }

                var left = buffer.getChannelData(0);
                var right = channels > 1 ? buffer.getChannelData(1) : null;
                var frames = buffer.length;
                var step = 1152 * 64;
                var parts = [];
                var at = 0;

                function chunk() {
                    var stop = Math.min(at + step, frames);
                    try {
                        var l = toInt16(left, at, stop);
                        var block = right
                            ? encoder.encodeBuffer(l, toInt16(right, at, stop))
                            : encoder.encodeBuffer(l);
                        if (block.length) {
                            parts.push(new Int8Array(block));
                        }
                    } catch (e) {
                        reject(e);
                        return;
                    }
                    at = stop;
                    onProgress(at / frames);

                    if (at < frames) {
                        setTimeout(chunk, 0);
                        return;
                    }
                    var tail = encoder.flush();
                    if (tail.length) {
                        parts.push(new Int8Array(tail));
                    }
                    resolve(new Blob(parts, { type: 'audio/mpeg' }));
                }
                setTimeout(chunk, 0);
            });
        });
    }

    /* ------------------------------------------------------------------ */

    function show(blob, name, seconds) {
        var url = URL.createObjectURL(blob);
        out.innerHTML = '';

        var player = document.createElement('audio');
        player.controls = true;
        player.src = url;
        player.className = 'gs-ytl__player';

        var link = document.createElement('a');
        link.className = 'gs-btn gs-btn--primary gs-ytl__save';
        link.href = url;
        link.download = name;
        link.textContent = 'Скачать ' + name;

        var meta = document.createElement('p');
        meta.className = 'gs-ytl__meta';
        meta.textContent = minutes(seconds) + ' · ' + (blob.size / 1048576).toFixed(1) + ' МБ';

        out.appendChild(player);
        out.appendChild(link);
        out.appendChild(meta);
        out.hidden = false;
    }

    function handle(file) {
        if (busy || !file) {
            return;
        }
        if (file.size > MAX_BYTES) {
            say('Файл больше 700 МБ — браузер такой не потянет. Возьмите фрагмент покороче.', 'bad');
            return;
        }

        busy = true;
        out.hidden = true;
        root.classList.add('is-busy');
        say('Читаем файл…');

        var Ctx = window.AudioContext || window.webkitAudioContext;
        if (!Ctx) {
            say('Браузер не умеет разбирать звук. Попробуйте Chrome или Safari посвежее.', 'bad');
            busy = false;
            root.classList.remove('is-busy');
            return;
        }

        file.arrayBuffer().then(function (raw) {
            say('Разбираем дорожку…');
            var ctx = new Ctx();
            return new Promise(function (resolve, reject) {
                // Старые браузеры знают только версию с обратными вызовами.
                var maybe = ctx.decodeAudioData(raw, resolve, reject);
                if (maybe && typeof maybe.then === 'function') {
                    maybe.then(resolve, reject);
                }
            }).then(function (buffer) {
                try { ctx.close(); } catch (e) { /* уже закрыт */ }
                return buffer;
            });
        }).then(function (buffer) {
            var seconds = buffer.duration;
            if (seconds / 60 > SOFT_MINUTES) {
                say('Запись длиннее ' + SOFT_MINUTES + ' минут — браузеру не хватит памяти. '
                    + 'Разрежьте файл или возьмите фрагмент.', 'bad');
                throw new Error('long');
            }

            var kind = format();
            var name = baseName(file.name) + '.' + kind;

            if (kind === 'wav') {
                say('Собираем WAV…');
                var blob = wav(buffer);
                show(blob, name, seconds);
                say('Готово. Файл собран у вас в браузере и никуда не отправлялся.', 'ok');
                return;
            }

            say('Кодируем MP3… 0%');
            return mp3(buffer, function (part) {
                say('Кодируем MP3… ' + Math.round(part * 100) + '%');
            }).then(function (blob) {
                show(blob, name, seconds);
                say('Готово. Файл собран у вас в браузере и никуда не отправлялся.', 'ok');
            });
        }).catch(function (e) {
            if (e && e.message === 'long') {
                return;
            }
            if (e && e.message === 'lame') {
                say('Не удалось загрузить кодировщик MP3. Выберите формат WAV или обновите страницу.', 'bad');
                return;
            }
            // decodeAudioData отказывается молча: почти всегда это кодек,
            // которого у браузера нет — MKV, AVI, WMV.
            say('Браузер не смог разобрать этот файл. Такое бывает с MKV, AVI и WMV — '
                + 'пересохраните в MP4 или воспользуйтесь ссылкой на ролик.', 'bad');
        }).then(function () {
            busy = false;
            root.classList.remove('is-busy');
        });
    }

    input.addEventListener('change', function () {
        handle(input.files && input.files[0]);
    });

    if (drop) {
        ['dragenter', 'dragover'].forEach(function (name) {
            drop.addEventListener(name, function (e) {
                e.preventDefault();
                drop.classList.add('is-over');
            });
        });
        ['dragleave', 'drop'].forEach(function (name) {
            drop.addEventListener(name, function (e) {
                e.preventDefault();
                drop.classList.remove('is-over');
            });
        });
        drop.addEventListener('drop', function (e) {
            var files = e.dataTransfer && e.dataTransfer.files;
            handle(files && files[0]);
        });
    }

    // Формат меняют уже после того, как файл выбран: пересобираем по той же
    // записи, а не заставляем выбирать файл заново.
    if (fmtSel) {
        fmtSel.addEventListener('change', function () {
            if (!out.hidden && input.files && input.files[0]) {
                handle(input.files[0]);
            }
        });
    }
})();
