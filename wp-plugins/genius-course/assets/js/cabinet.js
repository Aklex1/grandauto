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

    /* Мини-проверка модуля: ответы сверяет сервер */
    var quiz = document.getElementById('gc-quiz');
    if (quiz) {
        var quizBtn = document.getElementById('gc-quiz-send');
        quizBtn.addEventListener('click', function () {
            var blocks = quiz.querySelectorAll('.gc-quiz__q');
            var answers = [];
            var missed = false;
            Array.prototype.forEach.call(blocks, function (block, i) {
                var picked = block.querySelector('input:checked');
                answers[i] = picked ? parseInt(picked.value, 10) : -1;
                if (!picked) { missed = true; }
            });
            if (missed) {
                say(quiz, 'Ответьте на все три вопроса', 'bad');
                return;
            }
            quizBtn.disabled = true;
            say(quiz, 'Проверяем…');
            send('quiz', { module: parseInt(quiz.getAttribute('data-module'), 10), answers: answers })
                .then(function (res) {
                    quizBtn.disabled = false;
                    if (!res.ok) {
                        say(quiz, (res.data && res.data.message) || 'Не получилось проверить', 'bad');
                        return;
                    }
                    (res.data.rows || []).forEach(function (row) {
                        var block = quiz.querySelector('.gc-quiz__q[data-n="' + row.n + '"]');
                        if (!block) { return; }
                        block.classList.remove('is-right', 'is-wrong');
                        block.classList.add(row.ok ? 'is-right' : 'is-wrong');
                        var opts = block.querySelectorAll('.gc-quiz__opt');
                        Array.prototype.forEach.call(opts, function (opt, i) {
                            opt.classList.toggle('is-answer', i === row.right);
                        });
                        var why = block.querySelector('.gc-quiz__why');
                        if (why) {
                            why.textContent = row.why || '';
                            why.hidden = !row.why;
                        }
                    });
                    var score = res.data.score, total = res.data.total;
                    say(quiz, score + ' из ' + total + (score === total ? ' — всё верно' : '. Ответы разобраны ниже'),
                        score === total ? 'good' : '');
                })
                .catch(function () {
                    quizBtn.disabled = false;
                    say(quiz, 'Связь оборвалась. Попробуйте ещё раз.', 'bad');
                });
        });
    }

    /* Показать свою работу в галерее */
    document.addEventListener('click', function (e) {
        var btn = e.target.closest ? e.target.closest('.gc-share') : null;
        if (!btn) { return; }
        var on = btn.getAttribute('data-on') !== '1';
        btn.disabled = true;
        send('share', { kind: 'job', id: parseInt(btn.getAttribute('data-job'), 10), on: on })
            .then(function (res) {
                btn.disabled = false;
                if (!res.ok) { return; }
                btn.setAttribute('data-on', on ? '1' : '0');
                btn.classList.toggle('is-on', on);
                btn.textContent = on ? 'В галерее' : 'В галерею';
            })
            .catch(function () { btn.disabled = false; });
    });

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
                links: work.elements.links.value,
                shared: work.elements.shared ? work.elements.shared.checked : false
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
                // Выбор ступени нужен только ролику: у кадра одна модель.
                var tiers = document.getElementById('gc-tier');
                if (tiers) { tiers.hidden = !isVideo; }
            });
        });

        /* Подсветка выбранной ступени ролика */
        Array.prototype.forEach.call(gen.querySelectorAll('.gc-tier__item input'), function (input) {
            input.addEventListener('change', function () {
                Array.prototype.forEach.call(gen.querySelectorAll('.gc-tier__item'), function (l) {
                    l.classList.toggle('is-on', l.contains(input) && input.checked);
                });
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
                tier: gen.elements.tier ? gen.elements.tier.value : '',
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

    /* Написать куратору или в поддержку */
    var ask = document.getElementById('gc-ask');
    if (ask) {
        var panel = document.getElementById('gc-ask-panel');
        var askForm = document.getElementById('gc-ask-form');
        var askText = document.getElementById('gc-ask-text');
        var askKind = document.getElementById('gc-ask-kind');
        var askTitle = document.getElementById('gc-ask-title');
        var askHint = document.getElementById('gc-ask-hint');
        var words = {
            curator: {
                title: 'Написать куратору',
                hint: 'Разберём задание, подскажем промпт, посмотрим вашу работу. Ответ придёт на почту или в Telegram.',
                hold: 'Например: в модуле 3 не получается удержать лицо на кадре — что поправить в промпте?'
            },
            support: {
                title: 'Написать в поддержку',
                hint: 'Не открывается модуль, пропали генерации, не считается баланс, вопрос по оплате — это сюда.',
                hold: 'Например: оплатил пакет, а кадры по-прежнему не запускаются.'
            }
        };

        var openAsk = function (kind) {
            var w = words[kind] || words.curator;
            askKind.value = kind;
            askTitle.textContent = w.title;
            askHint.textContent = w.hint;
            askText.setAttribute('placeholder', w.hold);
            ask.classList.add('is-open');
            panel.hidden = false;
            Array.prototype.forEach.call(ask.querySelectorAll('.gc-ask__btn'), function (b) {
                b.classList.toggle('is-on', b.getAttribute('data-kind') === kind);
            });
            askText.focus();
        };
        var closeAsk = function () {
            ask.classList.remove('is-open');
            panel.hidden = true;
            Array.prototype.forEach.call(ask.querySelectorAll('.gc-ask__btn'), function (b) {
                b.classList.remove('is-on');
            });
        };

        Array.prototype.forEach.call(ask.querySelectorAll('.gc-ask__btn'), function (btn) {
            btn.addEventListener('click', function () {
                var kind = btn.getAttribute('data-kind');
                // Повторное нажатие по той же кнопке закрывает панель:
                // иначе её нечем убрать, кроме крестика.
                if (!panel.hidden && askKind.value === kind) {
                    closeAsk();
                    return;
                }
                openAsk(kind);
            });
        });
        // Плашка чата стоит снизу и на мобильном занимает две строки:
        // поднимаем кнопки на её настоящую высоту, а не на угаданную.
        var chat = document.querySelector('.gc-chat');
        if (chat) {
            var lift = function () {
                ask.style.setProperty('--gc-ask-lift', Math.round(chat.getBoundingClientRect().height + 10) + 'px');
            };
            lift();
            window.addEventListener('resize', lift);
        }

        document.getElementById('gc-ask-close').addEventListener('click', closeAsk);
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && !panel.hidden) { closeAsk(); }
        });

        askForm.addEventListener('submit', function (e) {
            e.preventDefault();
            var btn = askForm.querySelector('button[type="submit"]');
            if (askText.value.trim().length < 5) {
                say(askForm, 'Напишите вопрос хотя бы одной фразой', 'bad');
                askText.focus();
                return;
            }
            btn.disabled = true;
            say(askForm, 'Отправляем…');
            send('ask', {
                kind: askKind.value,
                text: askText.value,
                telegram: document.getElementById('gc-ask-tg').value
            })
                .then(function (res) {
                    btn.disabled = false;
                    if (!res.ok) {
                        say(askForm, (res.data && res.data.message) || 'Не получилось отправить', 'bad');
                        return;
                    }
                    askText.value = '';
                    say(askForm, res.data.message || 'Вопрос ушёл', 'good');
                    // Панель закрываем сама: человек вернулся к уроку, а
                    // подтверждение он уже прочитал.
                    window.setTimeout(closeAsk, 4000);
                })
                .catch(function () {
                    btn.disabled = false;
                    say(askForm, 'Связь оборвалась, попробуйте ещё раз', 'bad');
                });
        });
    }
})();
