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
        again:    document.getElementById('gs-lab-again'),
        history:  document.getElementById('gs-lab-history'),
        historyGrid: document.getElementById('gs-lab-history-grid')
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

        // Бесплатные операции гостю доступны; за остальным — на вход.
        if (!cfg.loggedIn && !cfg.guestOk) {
            window.location.href = cfg.loginUrl;
            return;
        }

        var payload = { service: cfg.service };
        var optional = cfg.inputsOptional || [];
        var missing = false;
        (cfg.inputs || []).forEach(function (kind) {
            if (!uploaded[kind]) {
                if (optional.indexOf(kind) === -1) {
                    missing = true;
                }
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

        // Дополнительные поля сервиса объявлены в разметке — собираем их как есть.
        var extra = form.querySelectorAll('[data-gs-field]');
        if (extra.length) {
            payload.fields = {};
            Array.prototype.forEach.call(extra, function (el) {
                var name = el.getAttribute('data-gs-field');
                payload.fields[name] = el.type === 'checkbox' ? el.checked : el.value.trim();
            });
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
            els.stage.textContent = cfg.manual
                ? 'Заказ принят. Обычно готово за 10–15 минут — файл придёт в историю и на почту, страницу можно закрыть.'
                : 'Нейросеть обрабатывает файл…';
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
                note(cfg.manual
                    ? 'Заказ в работе. Готовый файл появится в истории и придёт на почту.'
                    : 'Обработка занимает слишком долго. Загляните в историю через пару минут.', cfg.manual ? 'ok' : 'error');
                return;
            }
            els.progress.style.width = Math.min(92, 30 + (elapsed / timeout) * 120) + '%';

            api('lab/status/' + encodeURIComponent(cfg.service) + '/' + encodeURIComponent(taskId)).then(function (res) {
                var data = res.data || {};
                if (data.status === 'completed') {
                    stopPolling();
                    setBusy(false);
                    renderFiles(data.files || [], data.text || '');
                    // Свежий кадр уже лежит в истории — перечитываем её,
                    // чтобы он появился в галерее без перезагрузки страницы.
                    loadHistory();

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

    /**
     * История работ.
     *
     * Строка появляется в момент заказа, а не по готовности: человек
     * закрывает вкладку, и без этого заказ пропадал бы из виду вместе с
     * ней. Отсюда три состояния — в работе, готово, не вышло, — и
     * показывать их надо по-разному.
     */
    var HISTORY_RELOAD = 30000;
    var historyTimer = null;

    function el(tag, cls, text) {
        var node = document.createElement(tag);
        if (cls) { node.className = cls; }
        if (text) { node.textContent = text; }
        return node;
    }

    function when(stamp) {
        if (!stamp) { return ''; }
        var m = String(stamp).match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/);
        if (!m) { return String(stamp); }
        return m[3] + '.' + m[2] + '.' + m[1] + ', ' + m[4] + ':' + m[5];
    }

    /** Проигрыватель или картинка — по тому, что лежит в файле. */
    function mediaNode(file) {
        var kind = file.kind || 'file';
        if (kind === 'image') {
            var img = el('img');
            img.src = file.url;
            img.loading = 'lazy';
            img.alt = file.label || 'Результат';
            return img;
        }
        if (kind === 'video') {
            var video = el('video');
            video.src = file.url;
            video.controls = true;
            video.preload = 'metadata';
            video.playsInline = true;
            return video;
        }
        if (kind === 'audio' || kind === 'stems') {
            var audio = el('audio');
            audio.src = file.url;
            audio.controls = true;
            audio.preload = 'none';
            return audio;
        }
        return null;
    }

    function downloadLink(file, index) {
        var a = el('a', 'gs-lab-shot__get', file.label || (index > 0 ? 'Скачать ещё' : 'Скачать'));
        a.href = file.url;
        a.setAttribute('download', '');
        return a;
    }

    /** Карточка-плитка: нужна там, где на выходе кадр. */
    function shotCard(item, file) {
        var card = el('figure', 'gs-lab-shot');
        var link = el('a');
        link.href = file.url;
        link.target = '_blank';
        link.rel = 'noopener';
        link.appendChild(mediaNode(file) || el('span', '', 'Файл'));
        card.appendChild(link);

        var cap = el('figcaption', 'gs-lab-shot__cap');
        cap.appendChild(el('span', '', item.note || 'Кадр'));
        cap.appendChild(downloadLink(file, 0));
        card.appendChild(cap);
        return card;
    }

    /** Строка: для ролика, дорожки и текста — там плитка нечитаема. */
    function rowCard(item) {
        var isNew = item.status !== 'pending' && !item.seen;
        var row = el('div', 'gs-lab-row' + (isNew ? ' gs-lab-row--new' : ''));

        var main = el('div', 'gs-lab-row__main');
        var title = el('div', 'gs-lab-row__title');
        if (item.status === 'pending') {
            title.appendChild(el('span', 'gs-lab-tag gs-lab-tag--work', 'В работе'));
            title.appendChild(el('span', '', 'Заберём сами — страницу можно закрыть'));
        } else if (item.status === 'failed') {
            title.appendChild(el('span', 'gs-lab-tag gs-lab-tag--fail', 'Не вышло'));
            title.appendChild(el('span', '', item.message || 'Средства возвращены на баланс'));
        } else {
            title.appendChild(el('span', 'gs-lab-tag gs-lab-tag--ready', 'Готово'));
            if (isNew) {
                title.appendChild(el('span', 'gs-lab-tag gs-lab-tag--new', 'новое'));
            }
        }
        main.appendChild(title);
        if (item.note) {
            main.appendChild(el('div', 'gs-lab-row__note', item.note));
        }
        main.appendChild(el('div', 'gs-lab-row__when', when(item.at)));
        row.appendChild(main);

        var files = item.files || [];
        if (files.length) {
            var media = el('div', 'gs-lab-row__media');
            var node = mediaNode(files[0]);
            if (node) { media.appendChild(node); }
            row.appendChild(media);

            var links = el('div', 'gs-lab-row__links');
            files.forEach(function (file, i) { links.appendChild(downloadLink(file, i)); });
            row.appendChild(links);
        } else if (item.text) {
            var box = el('div', 'gs-lab-row__media');
            box.appendChild(el('p', 'gs-lab-row__text', item.text));
            row.appendChild(box);
        }
        return row;
    }

    function loadHistory() {
        if (!els.history || !els.historyGrid || !cfg.loggedIn) { return; }
        api('lab/history/' + encodeURIComponent(cfg.service)).then(function (res) {
            var items = (res.data && res.data.items) || [];
            if (!items.length) {
                els.history.hidden = true;
                return;
            }
            var shots = cfg.resultKind === 'image';
            var fresh = [];
            els.historyGrid.innerHTML = '';
            items.forEach(function (item) {
                var files = item.files || [];
                if (item.status !== 'pending' && !item.seen && item.task) {
                    fresh.push(item.task);
                }
                // Готовые кадры показываем плиткой, каждый своей карточкой:
                // человек выбирает из них один.
                if (shots && item.status === 'ready' && files.length) {
                    files.forEach(function (file) { els.historyGrid.appendChild(shotCard(item, file)); });
                    return;
                }
                els.historyGrid.appendChild(rowCard(item));
            });
            els.history.hidden = false;

            // Человек результат увидел — записываем это. Отметка гасит
            // уведомление и показывает, дошло ли оплаченное до адресата.
            if (fresh.length) {
                api('lab/seen', { method: 'POST', body: { service: cfg.service, tasks: fresh } }).catch(function () {});
            }

            // Пока есть незакрытые заказы, список перечитываем сам: их
            // закрывает расписание, и страница должна это заметить.
            if (historyTimer) { clearTimeout(historyTimer); historyTimer = null; }
            var working = items.some(function (item) { return item.status === 'pending'; });
            if (working) {
                historyTimer = setTimeout(loadHistory, HISTORY_RELOAD);
            }
        }).catch(function () {});
    }
    loadHistory();

    // «Повторить с моими фото»: подставляем подборку и уводим к форме.
    // Человек пришёл за конкретной съёмкой, а не за выпадающими списками —
    // заполнять их руками он не должен.
    Array.prototype.forEach.call(document.querySelectorAll('[data-gs-repeat]'), function (btn) {
        btn.addEventListener('click', function () {
            var setField = form.querySelector('[data-gs-field="set"]');
            var cntField = form.querySelector('[data-gs-field="count"]');
            if (setField) {
                setField.value = btn.getAttribute('data-gs-repeat');
                setField.dispatchEvent(new Event('change', { bubbles: true }));
            }
            var want = parseInt(btn.getAttribute('data-gs-count'), 10);
            if (cntField && want > 0) {
                var allowed = [3, 5, 7].filter(function (n) { return n <= want; });
                cntField.value = String(allowed.length ? allowed[allowed.length - 1] : 3);
                cntField.dispatchEvent(new Event('change', { bubbles: true }));
            }
            var anchor = document.getElementById('gs-lab-start') || form;
            anchor.scrollIntoView({ behavior: 'smooth', block: 'start' });
            var first = form.querySelector('.gs-file');
            if (first) { setTimeout(function () { first.focus({ preventScroll: true }); }, 400); }
        });
    });

    // Подборка: цена зависит от числа кадров, и человек должен видеть сумму
    // до запуска, а не узнавать её из списания.
    (function () {
        var count = form.querySelector('[data-gs-field="count"]');
        if (!count || !els.price || !cfg.perFrame) { return; }
        function show() {
            var n = parseInt(count.value, 10) || 0;
            if (n > 0) {
                els.price.textContent = money(cfg.perFrame * n) + ' за подборку из ' + n;
            }
        }
        count.addEventListener('change', show);
        show();
    })();

    function renderFiles(files, text) {
        els.progress.style.width = '100%';
        els.files.innerHTML = '';

        // Расшифровка — это прежде всего текст: показываем его сразу,
        // а файлы идут ниже как способ забрать результат с собой.
        if (text) {
            var box = document.createElement('div');
            box.className = 'gs-lab-file gs-lab-text';

            var caption = document.createElement('h3');
            caption.className = 'gs-lab-file__title';
            caption.textContent = cfg.textLabel || 'Расшифровка';
            box.appendChild(caption);

            var body = document.createElement('div');
            body.className = 'gs-lab-text__body';
            body.textContent = text;
            box.appendChild(body);

            var copy = document.createElement('button');
            copy.type = 'button';
            copy.className = 'gs-btn gs-btn--primary';
            copy.textContent = 'Скопировать текст';
            copy.addEventListener('click', function () {
                var done = function () {
                    copy.textContent = 'Скопировано';
                    setTimeout(function () { copy.textContent = 'Скопировать текст'; }, 1800);
                };
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(text).then(done, function () {});
                    return;
                }
                var area = document.createElement('textarea');
                area.value = text;
                document.body.appendChild(area);
                area.select();
                try { document.execCommand('copy'); done(); } catch (e) {}
                document.body.removeChild(area);
            });
            box.appendChild(copy);
            els.files.appendChild(box);
        }

        files.forEach(function (file) {
            var wrap = document.createElement('div');
            wrap.className = 'gs-lab-file';

            var title = document.createElement('h3');
            title.className = 'gs-lab-file__title';
            title.textContent = file.label;
            wrap.appendChild(title);

            if (file.kind === 'image') {
                var picture = document.createElement('img');
                picture.src = file.url;
                picture.alt = file.label;
                picture.loading = 'lazy';
                picture.className = 'gs-lab-image';
                wrap.appendChild(picture);
            } else if (file.kind !== 'file') {
                var media = document.createElement(file.kind === 'video' ? 'video' : 'audio');
                media.controls = true;
                media.src = file.url;
                media.className = file.kind === 'video' ? 'gs-lab-video' : 'gs-audio';
                if (file.kind === 'video') {
                    media.setAttribute('playsinline', '');
                }
                wrap.appendChild(media);
            }

            var link = document.createElement('a');
            link.className = 'gs-btn gs-btn--primary';
            link.href = file.url;
            link.setAttribute('download', '');
            link.textContent = 'Скачать';
            wrap.appendChild(link);

            els.files.appendChild(wrap);
        });

        // Гость получил результат — самое время рассказать, что рядом.
        if (!cfg.loggedIn && cfg.guestOk && cfg.registerUrl) {
            var invite = document.createElement('div');
            invite.className = 'gs-lab-file gs-lab-invite';

            var head = document.createElement('h3');
            head.className = 'gs-lab-file__title';
            head.textContent = 'Что с этой дорожкой можно сделать дальше';
            invite.appendChild(head);

            var text = document.createElement('p');
            text.className = 'gs-lab-invite__text';
            text.textContent = 'Расшифровать в текст с таймкодами, очистить от шума, '
                + 'отделить голос от музыки. Эти инструменты работают в аккаунте.';
            invite.appendChild(text);

            var go = document.createElement('a');
            go.className = 'gs-btn gs-btn--primary';
            go.href = cfg.registerUrl;
            go.textContent = 'Создать аккаунт';
            invite.appendChild(go);

            els.files.appendChild(invite);
        }

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
