/**
 * Кабинет: сдача работ, отметка модуля и мастерская.
 *
 * Опрос генераций включается только когда что-то считается, и сам
 * выключается, когда очередь пуста: держать таймер на пустой странице —
 * это греть телефон впустую.
 */
(function () {
    'use strict';

    var cfg = window.GC || {};
    if (!cfg.rest) {
        return;
    }

    function send(path, body) {
        // Курс страницы прикладываем ко всем запросам: у ученика он и так
        // один, а администратор смотрит оба кабинета — иначе маршрут
        // проверял бы номер модуля по чужой программе.
        if (body && cfg.course && !body.course) {
            body.course = cfg.course;
        }
        return fetch(cfg.rest + path, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce || '' },
            body: JSON.stringify(body)
        }).then(function (r) {
            return r.json().then(function (data) { return { ok: r.ok, data: data }; });
        });
    }

    function say(form, text, kind) {
        var note = form.querySelector('.gc-note-line');
        if (!note) { return; }
        note.textContent = text;
        note.className = 'gc-note-line' + (kind ? ' is-' + kind : '');
    }

    /* Сдача работы */
    var work = document.getElementById('gc-work-form');
    if (work) {
        work.addEventListener('submit', function (e) {
            e.preventDefault();
            var btn = work.querySelector('button');
            btn.disabled = true;
            say(work, 'Отправляем…');
            send('work', {
                module: parseInt(work.getAttribute('data-module'), 10),
                body: work.elements.body.value,
                links: work.elements.links.value
            }).then(function (res) {
                if (!res.ok) {
                    say(work, (res.data && res.data.message) || 'Не получилось отправить', 'bad');
                    btn.disabled = false;
                    return;
                }
                say(work, res.data.message || 'Работа ушла на проверку', 'good');
                work.reset();
                setTimeout(function () { window.location.reload(); }, 1200);
            }).catch(function () {
                say(work, 'Связь оборвалась. Попробуйте ещё раз.', 'bad');
                btn.disabled = false;
            });
        });
    }

    /* Отметка «пройден» */
    var done = document.getElementById('gc-done-form');
    if (done) {
        var box = done.querySelector('input[type="checkbox"]');
        box.addEventListener('change', function () {
            send('done', { module: parseInt(done.getAttribute('data-module'), 10), done: box.checked })
                .catch(function () { box.checked = !box.checked; });
        });
    }

    /* Мастерская */
    var gen = document.getElementById('gc-gen-form');
    var jobs = document.getElementById('gc-jobs');
    var timer = null;

    function refresh() {
        fetch(cfg.rest + 'jobs', { credentials: 'same-origin', headers: { 'X-WP-Nonce': cfg.nonce || '' } })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data && data.html && jobs) {
                    jobs.innerHTML = data.html;
                }
                if (!data || !data.busy) {
                    clearInterval(timer);
                    timer = null;
                }
            })
            .catch(function () {});
    }

    function watch() {
        if (timer) { return; }
        timer = setInterval(refresh, 5000);
    }

    /* Переключатель «кадр / ролик»: прячем настройки, которых нет у кадра */
    function setField(name, value) {
        if (!gen || !gen.elements[name]) {
            return;
        }
        gen.elements[name].value = value;
        gen.elements[name].dispatchEvent(new Event('input', { bubbles: true }));
    }

    if (gen) {
        var kinds = gen.querySelectorAll('.gc-kind input');
        Array.prototype.forEach.call(kinds, function (input) {
            input.addEventListener('change', function () {
                Array.prototype.forEach.call(gen.querySelectorAll('.gc-kind label'), function (l) {
                    l.classList.toggle('is-on', l.contains(input) && input.checked);
                });
                var isVideo = gen.elements.kind.value === 'video';
                gen.classList.toggle('is-video', isVideo);
                /* Ролику нужна другая строгость: на 6.5 LTX идёт пятнами,
                   рабочий диапазон — около тройки. Подставляем сами,
                   иначе первый ролик у каждого выходит браком. */
                setField('cfg', isVideo ? 3 : 6.5);
                if (isVideo) {
                    setField('fps', 24);
                }
            });
        });

        /* Числа рядом с ползунками: без них значение приходится угадывать */
        Array.prototype.forEach.call(gen.querySelectorAll('input[type="range"]'), function (range) {
            var out = gen.querySelector('.gc-val[data-for="' + range.name + '"]')
                || (range.name === 'strength' ? document.getElementById('gc-ref-val') : null);
            if (!out) { return; }
            var show = function () { out.textContent = range.value; };
            range.addEventListener('input', show);
            show();
        });

        /* Референс */
        var pick = document.getElementById('gc-ref-pick');
        var file = document.getElementById('gc-ref-file');
        var drop = document.getElementById('gc-ref-drop');
        var prev = document.getElementById('gc-ref-preview');
        var img = document.getElementById('gc-ref-img');
        var nameOut = document.getElementById('gc-ref-name');
        var idField = document.getElementById('gc-ref-id');
        var clear = document.getElementById('gc-ref-clear');

        function upload(f) {
            if (!f) { return; }
            var data = new FormData();
            data.append('file', f);
            if (cfg.course) { data.append('course', cfg.course); }
            say(gen, 'Загружаем референс…');
            fetch(cfg.rest + 'reference', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'X-WP-Nonce': cfg.nonce || '' },
                body: data
            }).then(function (r) {
                return r.json().then(function (d) { return { ok: r.ok, d: d }; });
            }).then(function (res) {
                if (!res.ok) {
                    say(gen, (res.d && res.d.message) || 'Не получилось загрузить', 'bad');
                    return;
                }
                idField.value = res.d.id;
                img.src = res.d.url;
                nameOut.textContent = res.d.name;
                prev.hidden = false;
                if (drop) { drop.hidden = true; }
                say(gen, 'Референс загружен', 'good');
            }).catch(function () { say(gen, 'Связь оборвалась', 'bad'); });
        }

        if (pick && file) {
            pick.addEventListener('click', function () { file.click(); });
            file.addEventListener('change', function () { upload(file.files[0]); });
        }
        if (drop) {
            ['dragenter', 'dragover'].forEach(function (ev) {
                drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.add('is-over'); });
            });
            ['dragleave', 'drop'].forEach(function (ev) {
                drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.remove('is-over'); });
            });
            drop.addEventListener('drop', function (e) {
                if (e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0]) {
                    upload(e.dataTransfer.files[0]);
                }
            });
        }
        if (clear) {
            clear.addEventListener('click', function () {
                idField.value = '';
                prev.hidden = true;
                if (drop) { drop.hidden = false; }
                if (file) { file.value = ''; }
            });
        }

        gen.addEventListener('submit', function (e) {
            e.preventDefault();
            var btn = gen.querySelector('button[type="submit"]');
            btn.disabled = true;
            say(gen, 'Отправляем на машину…');
            var num = function (name, fallback) {
                return gen.elements[name] ? gen.elements[name].value : fallback;
            };
            send('gen', {
                prompt: gen.elements.prompt.value,
                size: gen.elements.size.value,
                kind: gen.elements.kind ? gen.elements.kind.value : 'image',
                negative: num('negative', ''),
                steps: num('steps', 28),
                cfg: num('cfg', 6.5),
                seed: num('seed', 0),
                frames: num('frames', 49),
                fps: num('fps', 16),
                strength: num('strength', 0.75),
                reference: idField ? idField.value : ''
            })
                .then(function (res) {
                    btn.disabled = false;
                    if (!res.ok) {
                        say(gen, (res.data && res.data.message) || 'Не получилось', 'bad');
                        return;
                    }
                    say(gen, res.data.message || 'Считается', 'good');
                    refresh();
                    watch();
                })
                .catch(function () {
                    btn.disabled = false;
                    say(gen, 'Связь оборвалась', 'bad');
                });
        });
    }

    if (jobs && jobs.querySelector('.is-running, .is-queued')) {
        watch();
    }
})();
