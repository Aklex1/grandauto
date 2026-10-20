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

    if (gen) {
        gen.addEventListener('submit', function (e) {
            e.preventDefault();
            var btn = gen.querySelector('button');
            btn.disabled = true;
            say(gen, 'Отправляем на машину…');
            send('gen', { prompt: gen.elements.prompt.value, size: gen.elements.size.value })
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
