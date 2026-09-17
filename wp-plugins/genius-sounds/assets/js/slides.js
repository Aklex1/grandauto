/**
 * Genius Slides: запуск сборки и ожидание результата.
 *
 * Фоны рисуются минутами, поэтому опрашиваем задачу и показываем, сколько
 * картинок уже готово — иначе человеку кажется, что страница зависла.
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

    function say(text, kind) {
        note.textContent = text;
        note.className = 'gs-form__note' + (kind ? ' is-' + kind : '');
    }

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
        });
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

    function showOutline(deck) {
        if (!deck || !deck.slides) { return; }
        var rows = deck.slides.map(function (s, i) {
            var items = (s.bullets || []).map(function (b) { return '<li>' + esc(b) + '</li>'; }).join('');
            return '<article class="gs-slides__card"><span class="gs-slides__num">' + (i + 2) + '</span>' +
                '<h3>' + esc(s.title) + '</h3><ul>' + items + '</ul></article>';
        }).join('');
        result.hidden = false;
        result.innerHTML = '<h2 class="gs-section-title">' + esc(deck.title) + '</h2>' +
            '<p class="gs-slides__sub">' + esc(deck.subtitle || '') + '</p>' +
            '<div class="gs-slides__deck">' + rows + '</div>';
    }

    function showFile(url, title) {
        var link = '<a class="gs-btn gs-btn--primary gs-btn--lg" href="' + esc(url) + '" download>Скачать PPTX</a>';
        var head = result.querySelector('.gs-slides__done');
        if (head) { head.remove(); }
        result.insertAdjacentHTML('afterbegin',
            '<div class="gs-slides__done"><p>Презентация готова — ' + esc(title) + '</p>' + link + '</div>');
        loadHistory();
    }

    function poll(taskId, started) {
        call('slides/' + encodeURIComponent(taskId)).then(function (res) {
            if (!res.ok) {
                say((res.data && res.data.message) || 'Не удалось собрать презентацию', 'error');
                button.disabled = false;
                return;
            }
            if (res.data.status === 'completed') {
                say('Готово', 'ok');
                button.disabled = false;
                showFile(res.data.url, res.data.title);
                return;
            }
            var ready = res.data.ready || 0, total = res.data.total || 0;
            var secs = Math.round((Date.now() - started) / 1000);
            say('Рисуем фоны: готово ' + ready + ' из ' + total + '. Идёт ' + secs + ' с — страницу можно не закрывать.');
            setTimeout(function () { poll(taskId, started); }, 6000);
        }).catch(function () {
            setTimeout(function () { poll(taskId, started); }, 8000);
        });
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

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        var value = (topic.value || '').trim();
        if (value.length < 5) {
            say('Опишите тему — хотя бы несколькими словами', 'error');
            topic.focus();
            return;
        }
        button.disabled = true;
        result.hidden = true;
        result.innerHTML = '';
        say('Раскладываем тему на слайды…');

        call('slides/create', {
            topic: value,
            count: parseInt(count.value, 10) || 8,
            style: style.value,
            audience: audience.value
        }).then(function (res) {
            if (!res.ok) {
                say((res.data && res.data.message) || 'Не удалось запустить сборку', 'error');
                button.disabled = false;
                return;
            }
            showOutline(res.data.outline);
            say('Структура готова. Рисуем фоны…');
            poll(res.data.task_id, Date.now());
        }).catch(function () {
            say('Связь оборвалась. Попробуйте ещё раз.', 'error');
            button.disabled = false;
        });
    });

    loadHistory();
})();
