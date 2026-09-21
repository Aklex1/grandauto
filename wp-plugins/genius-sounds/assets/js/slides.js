/**
 * Genius Slides: структура, выбор иллюстраций, сборка файла.
 *
 * Шагов два. Сначала бесплатно собирается структура — её показываем
 * целиком, чтобы человек видел, за что платит. Потом он отмечает слайды,
 * которым нужна картинка рядом с текстом, видит итоговую сумму и только
 * тогда запускает отрисовку.
 */
(function () {
    var cfg = window.GS_SLIDES || {};
    var form = document.getElementById('gs-slides-form');
    if (!form || !cfg.restUrl) { return; }

    var topic = document.getElementById('gs-slides-topic');
    var count = document.getElementById('gs-slides-count');
    var style = document.getElementById('gs-slides-style');
    var audience = document.getElementById('gs-slides-audience');
    var button = document.getElementById('gs-slides-go');
    var note = document.getElementById('gs-slides-note');
    var result = document.getElementById('gs-slides-result');
    var history = document.getElementById('gs-slides-history');
    var balance = document.getElementById('gs-slides-balance');

    var file = document.getElementById('gs-slides-file');
    var fileName = document.getElementById('gs-slides-filename');
    var fileClear = document.getElementById('gs-slides-fileclear');

    var state = { draft: null, source: null, base: 0, pic: 0, picked: {}, own: {} };

    function say(text, kind) {
        note.textContent = text;
        note.className = 'gs-form__note' + (kind ? ' is-' + kind : '');
    }

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
        });
    }

    function money(n) {
        return (Math.round(n * 100) / 100).toLocaleString('ru-RU');
    }

    function setBalance(value) {
        if (balance && typeof value === 'number') {
            balance.textContent = money(value);
        }
    }

    function call(path, body) {
        return fetch(cfg.restUrl + path, {
            method: body ? 'POST' : 'GET',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce || '' },
            body: body ? JSON.stringify(body) : undefined
        }).then(function (r) {
            return r.json().then(function (d) { return { ok: r.ok, data: d }; });
        });
    }

    /* --- загрузка файла ------------------------------------------------ */

    function resetFile() {
        state.source = null;
        if (file) { file.value = ''; }
        fileName.textContent = 'Файл не выбран';
        fileClear.hidden = true;
    }

    if (file) {
        file.addEventListener('change', function () {
            var f = file.files && file.files[0];
            if (!f) { resetFile(); return; }

            fileName.textContent = 'Читаем ' + f.name + '…';
            fileClear.hidden = false;

            var data = new FormData();
            data.append('file', f);
            fetch(cfg.restUrl + 'slides/upload', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'X-WP-Nonce': cfg.nonce || '' },
                body: data
            }).then(function (r) {
                return r.json().then(function (d) { return { ok: r.ok, data: d }; });
            }).then(function (res) {
                if (!res.ok) {
                    say((res.data && res.data.message) || 'Файл не подошёл', 'error');
                    resetFile();
                    return;
                }
                state.source = res.data.source_id;
                fileName.textContent = res.data.name + ' — ' + res.data.chars.toLocaleString('ru-RU') + ' символов';
                say('Текст принят. Презентация будет собрана по нему.', 'ok');
            }).catch(function () {
                say('Не удалось загрузить файл', 'error');
                resetFile();
            });
        });
    }

    if (fileClear) {
        fileClear.addEventListener('click', function () {
            resetFile();
            say('');
        });
    }

    /* --- структура и выбор иллюстраций --------------------------------- */

    function total() {
        // Свою картинку рисовать не нужно, значит и платить за неё не за что.
        var pics = Object.keys(state.picked).filter(function (k) {
            return state.picked[k] && !state.own[k];
        }).length;
        return { pics: pics, sum: state.base + pics * state.pic };
    }

    function refreshPrice() {
        var t = total();
        var own = Object.keys(state.own).length;
        var box = document.getElementById('gs-slides-total');
        if (!box) { return; }
        box.innerHTML = 'К оплате <strong>' + money(t.sum) + ' ₽</strong>' +
            '<span> — ' + money(state.base) + ' ₽ презентация' +
            (t.pics ? ' + ' + t.pics + ' × ' + money(state.pic) + ' ₽ картинки' : '') +
            (own ? ' + ' + own + ' своих бесплатно' : '') + '</span>';
    }

    /** Загружает свою картинку и привязывает её к слайду. */
    function attachOwn(index, input) {
        var chosen = input.files && input.files[0];
        var card = input.closest('.gs-slides__card');
        var label = card.querySelector('[data-own-name]');
        if (!chosen) { return; }

        label.textContent = 'Загружаем…';
        var data = new FormData();
        data.append('file', chosen);
        data.append('kind', 'image');
        data.append('service', 'slides');

        fetch(cfg.restUrl + 'lab/upload', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'X-WP-Nonce': cfg.nonce },
            body: data
        }).then(function (r) {
            return r.json().then(function (d) { return { ok: r.ok, data: d }; });
        }).then(function (res) {
            if (!res.ok || !res.data || !res.data.url) {
                label.textContent = (res.data && res.data.message) || 'Не удалось загрузить';
                input.value = '';
                return;
            }
            state.own[index] = res.data.url;
            label.textContent = chosen.name;
            card.classList.add('is-own');
            // Своя картинка заменяет сгенерированную: отметка больше не нужна.
            var tick = card.querySelector('[data-slide]');
            if (tick) {
                tick.checked = true;
                tick.disabled = true;
                state.picked[index] = true;
            }
            card.classList.add('is-picked');
            refreshPrice();
        }).catch(function () {
            label.textContent = 'Сеть не отвечает';
            input.value = '';
        });
    }

    function dropOwn(index, card) {
        delete state.own[index];
        card.classList.remove('is-own');
        card.querySelector('[data-own-name]').textContent = '';
        card.querySelector('[data-own-file]').value = '';
        var tick = card.querySelector('[data-slide]');
        if (tick) {
            tick.disabled = false;
            tick.checked = false;
            state.picked[index] = false;
        }
        card.classList.remove('is-picked');
        refreshPrice();
    }

    function showOutline(deck) {
        state.picked = {};
        state.own = {};
        var rows = deck.slides.map(function (s, i) {
            var items = (s.bullets || []).map(function (b) { return '<li>' + esc(b) + '</li>'; }).join('');
            return '<article class="gs-slides__card"><span class="gs-slides__num">' + (i + 2) + '</span>' +
                '<h3>' + esc(s.title) + '</h3><ul>' + items + '</ul>' +
                '<label class="gs-slides__pick">' +
                '<input type="checkbox" data-slide="' + i + '">' +
                '<span>Картинка на слайде</span>' +
                '<em>' + (state.pic > 0 ? '+' + money(state.pic) + ' ₽' : 'бесплатно') + '</em>' +
                '</label>' +
                '<div class="gs-slides__own">' +
                '<label class="gs-slides__ownpick">' +
                '<input type="file" accept="image/jpeg,image/png" data-own-file="' + i + '">' +
                '<span>Загрузить свою</span></label>' +
                '<span class="gs-slides__ownname" data-own-name></span>' +
                '<button type="button" class="gs-slides__owndrop" data-own-drop="' + i + '">убрать</button>' +
                '</div></article>';
        }).join('');

        result.hidden = false;
        result.innerHTML =
            '<h2 class="gs-section-title">' + esc(deck.title) + '</h2>' +
            '<p class="gs-slides__sub">' + esc(deck.subtitle || '') + '</p>' +
            '<p class="gs-slides__tip">Отметьте слайды, которым нужна картинка рядом с текстом. ' +
            'Фон рисуется на каждом слайде и входит в базовую цену. ' +
            'Если картинка уже есть — загрузите свою, она бесплатна.</p>' +
            '<div class="gs-slides__deck">' + rows + '</div>' +
            '<div class="gs-slides__pay">' +
            '<p class="gs-slides__total" id="gs-slides-total"></p>' +
            '<button type="button" class="gs-btn gs-btn--primary gs-btn--lg" id="gs-slides-render">Собрать презентацию</button>' +
            '</div>';

        result.querySelectorAll('[data-slide]').forEach(function (box) {
            box.addEventListener('change', function () {
                state.picked[box.getAttribute('data-slide')] = box.checked;
                box.closest('.gs-slides__card').classList.toggle('is-picked', box.checked);
                refreshPrice();
            });
        });
        result.querySelectorAll('[data-own-file]').forEach(function (input) {
            input.addEventListener('change', function () {
                attachOwn(input.getAttribute('data-own-file'), input);
            });
        });
        result.querySelectorAll('[data-own-drop]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                dropOwn(btn.getAttribute('data-own-drop'), btn.closest('.gs-slides__card'));
            });
        });
        document.getElementById('gs-slides-render').addEventListener('click', render);
        refreshPrice();
        result.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    /* --- отрисовка ------------------------------------------------------ */

    function render() {
        var go = document.getElementById('gs-slides-render');
        go.disabled = true;
        say('Запускаем отрисовку…');

        var picked = Object.keys(state.picked)
            .filter(function (k) { return state.picked[k] && !state.own[k]; })
            .map(Number);

        call('slides/render', {
            draft_id: state.draft,
            style: style.value,
            illustrations: picked,
            own: state.own
        })
            .then(function (res) {
                if (!res.ok) {
                    say((res.data && res.data.message) || 'Не удалось запустить отрисовку', 'error');
                    go.disabled = false;
                    return;
                }
                setBalance(res.data.balance);
                say('Списано ' + money(res.data.cost) + ' ₽. Рисуем картинки…');
                poll(res.data.task_id, Date.now());
            }).catch(function () {
                say('Связь оборвалась. Попробуйте ещё раз.', 'error');
                go.disabled = false;
            });
    }

    function poll(taskId, started) {
        call('slides/' + encodeURIComponent(taskId)).then(function (res) {
            if (!res.ok) {
                say((res.data && res.data.message) || 'Не удалось собрать презентацию', 'error');
                return;
            }
            if (res.data.status === 'completed') {
                setBalance(res.data.balance);
                say('Готово', 'ok');
                showFile(res.data.url, res.data.title);
                return;
            }
            var secs = Math.round((Date.now() - started) / 1000);
            say('Рисуем: готово ' + (res.data.ready || 0) + ' из ' + (res.data.total || 0) +
                '. Идёт ' + secs + ' с — страницу можно не закрывать.');
            setTimeout(function () { poll(taskId, started); }, 6000);
        }).catch(function () {
            setTimeout(function () { poll(taskId, started); }, 8000);
        });
    }

    function showFile(url, title) {
        var old = result.querySelector('.gs-slides__done');
        if (old) { old.remove(); }
        result.insertAdjacentHTML('afterbegin',
            '<div class="gs-slides__done"><p>Презентация готова — ' + esc(title) + '</p>' +
            '<a class="gs-btn gs-btn--primary gs-btn--lg" href="' + esc(url) + '" download>Скачать PPTX</a></div>');
        var pay = result.querySelector('.gs-slides__pay');
        if (pay) { pay.remove(); }
        loadHistory();
        result.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    function loadHistory() {
        call('slides/history').then(function (res) {
            var items = (res.data && res.data.items) || [];
            if (!items.length) { return; }
            history.hidden = false;
            history.innerHTML = '<h2 class="gs-section-title">Ваши презентации</h2>' +
                '<ul class="gs-slides__list">' + items.map(function (it) {
                    return '<li><a href="' + esc(it.url) + '" download>' + esc(it.title) + '</a>' +
                        '<span>' + esc(it.slides) + ' слайдов · ' + esc(it.at) + '</span></li>';
                }).join('') + '</ul>';
        }).catch(function () {});
    }

    /* --- шаг 1 ---------------------------------------------------------- */

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        var value = (topic.value || '').trim();
        if (!state.source && value.length < 5) {
            say('Опишите тему или загрузите файл с текстом', 'error');
            topic.focus();
            return;
        }

        button.disabled = true;
        result.hidden = true;
        result.innerHTML = '';
        say(state.source ? 'Читаем ваш текст и раскладываем на слайды…' : 'Раскладываем тему на слайды…');

        call('slides/outline', {
            topic: value,
            source_id: state.source || '',
            count: parseInt(count.value, 10) || 8,
            audience: audience.value
        }).then(function (res) {
            button.disabled = false;
            if (!res.ok) {
                say((res.data && res.data.message) || 'Не удалось собрать структуру', 'error');
                return;
            }
            state.draft = res.data.draft_id;
            state.base = res.data.base;
            state.pic = res.data.pic;
            setBalance(res.data.balance);
            say('Структура готова. Отметьте слайды с картинками и запускайте сборку.', 'ok');
            showOutline(res.data.outline);
        }).catch(function () {
            button.disabled = false;
            say('Связь оборвалась. Попробуйте ещё раз.', 'error');
        });
    });

    loadHistory();
})();
