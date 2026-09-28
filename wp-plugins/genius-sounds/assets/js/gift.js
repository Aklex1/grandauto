/**
 * Анкета «песня в подарок»: текст сразу, песня после оплаты.
 *
 * Три шага на одной странице, без перезагрузок и без регистрации:
 * анкета — текст — оплата — готовая песня. Номер заказа держим в браузере,
 * чтобы человек мог закрыть вкладку и вернуться: оплата идёт через ЮMoney,
 * а оттуда он возвращается уже другой страницей.
 */
(function () {
    'use strict';

    var cfg = window.GS_GIFT || {};
    var send = document.getElementById('gs-gift-send');
    var status = document.getElementById('gs-gift-status');
    if (!send || !status || !cfg.restUrl) {
        return;
    }

    var STORE = 'gs_gift_order';
    var result = document.getElementById('gs-gift-result');
    var lyricsBox = document.getElementById('gs-gift-lyrics');
    var payBox = document.getElementById('gs-gift-pay');
    var status2 = document.getElementById('gs-gift-status2');
    var doneBox = document.getElementById('gs-gift-done');
    var tracks = document.getElementById('gs-gift-tracks');
    var order = '';
    var timer = null;

    function val(id) {
        var el = document.getElementById(id);
        if (!el) { return ''; }
        return el.type === 'checkbox' ? (el.checked ? '1' : '') : String(el.value || '').trim();
    }

    function say(node, text, kind) {
        if (!node) { return; }
        node.textContent = text || '';
        node.className = 'gs-gift-status' + (kind ? ' is-' + kind : '');
    }

    function remember(id) {
        order = id;
        try { localStorage.setItem(STORE, id); } catch (e) {}
    }

    function recall() {
        var fromUrl = new URLSearchParams(location.search).get('order');
        if (fromUrl) { return fromUrl; }
        try { return localStorage.getItem(STORE) || ''; } catch (e) { return ''; }
    }

    function post(path, body) {
        return fetch(cfg.restUrl + path, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body)
        }).then(function (r) { return r.json().catch(function () { return {}; }); });
    }

    /* ------------------------------------------------------------------ */
    /* Шаг 1: текст                                                        */
    /* ------------------------------------------------------------------ */

    send.addEventListener('click', function () {
        var name = val('gs-gift-name');
        var story = val('gs-gift-story');
        if (name === '' && story === '') {
            say(status, 'Напишите хотя бы имя и пару деталей про человека — иначе песня выйдет про кого угодно.', 'err');
            return;
        }

        send.disabled = true;
        say(status, 'Собираем текст, это займёт полминуты…');

        post('gift/start', {
            whom: val('gs-gift-whom'),
            name: name,
            occasion: val('gs-gift-occasion'),
            style: val('gs-gift-style'),
            story: story,
            pack: val('gs-gift-pack'),
            contact: val('gs-gift-contact'),
            page: cfg.page || ''
        }).then(function (data) {
            send.disabled = false;
            if (data && data.ok) {
                say(status, '');
                remember(data.order);
                showLyrics(data.lyrics);
            } else {
                say(status, (data && data.message) ? data.message : 'Не получилось собрать текст. Попробуйте ещё раз.', 'err');
            }
        }).catch(function () {
            send.disabled = false;
            say(status, 'Сеть не отвечает. Попробуйте ещё раз.', 'err');
        });
    });

    function showLyrics(text) {
        if (!result || !lyricsBox) { return; }
        lyricsBox.textContent = text || '';
        result.hidden = false;
        buildPay();
        result.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    /* ------------------------------------------------------------------ */
    /* Шаг 2: оплата                                                       */
    /* ------------------------------------------------------------------ */

    function buildPay() {
        if (!payBox || !cfg.packs) { return; }
        payBox.innerHTML = '';
        cfg.packs.forEach(function (pack) {
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'gs-gift-btn gs-gift-btn--small';
            btn.textContent = pack.title + ' — ' + pack.price + ' ₽';
            btn.addEventListener('click', function () { pay(pack.key, btn); });
            payBox.appendChild(btn);
        });
    }

    function pay(key, btn) {
        if (!order) { return; }
        btn.disabled = true;
        say(status2, 'Открываем оплату…');
        post('gift/pay', { order: order, pack: key }).then(function (data) {
            btn.disabled = false;
            if (data && data.ok && data.link) {
                say(status2, 'Откройте оплату в новой вкладке. Как только платёж пройдёт, песня начнёт записываться — '
                    + 'вернитесь на эту страницу, она сама покажет результат.');
                window.open(data.link, '_blank', 'noopener');
                watch();
            } else {
                say(status2, (data && data.message) ? data.message : 'Не получилось создать ссылку на оплату.', 'err');
            }
        }).catch(function () {
            btn.disabled = false;
            say(status2, 'Сеть не отвечает. Попробуйте ещё раз.', 'err');
        });
    }

    /* ------------------------------------------------------------------ */
    /* Шаг 3: ждём песню                                                   */
    /* ------------------------------------------------------------------ */

    function watch() {
        if (timer) { return; }
        timer = setInterval(check, 7000);
        check();
    }

    function check() {
        if (!order) { return; }
        fetch(cfg.restUrl + 'gift/state?order=' + encodeURIComponent(order))
            .then(function (r) { return r.json().catch(function () { return {}; }); })
            .then(function (data) {
                if (!data || !data.status) { return; }
                if (data.status === 'done' && data.files && data.files.length) {
                    stop();
                    showTracks(data.files);
                } else if (data.status === 'failed') {
                    stop();
                    say(status2, 'Запись не получилась. Мы это видим и вернём деньги — или переделаем, '
                        + 'как вам удобнее. Напишите нам, если не свяжемся первыми.', 'err');
                } else if (data.status === 'singing') {
                    say(status2, 'Оплата прошла, песня записывается. Обычно это две-три минуты.');
                } else if (data.status === 'paid') {
                    say(status2, 'Оплата прошла, начинаем запись…');
                }
            }).catch(function () {});
    }

    function stop() {
        if (timer) { clearInterval(timer); timer = null; }
    }

    function showTracks(files) {
        if (!doneBox || !tracks) { return; }
        say(status2, '');
        tracks.innerHTML = '';
        files.forEach(function (url, i) {
            var wrap = document.createElement('div');
            wrap.className = 'gs-gift-track';
            var head = document.createElement('p');
            head.textContent = 'Вариант ' + (i + 1);
            var audio = document.createElement('audio');
            audio.controls = true;
            audio.preload = 'none';
            audio.src = url;
            var link = document.createElement('a');
            link.href = url;
            link.textContent = 'Скачать';
            link.setAttribute('download', '');
            wrap.appendChild(head);
            wrap.appendChild(audio);
            wrap.appendChild(link);
            tracks.appendChild(wrap);
        });
        doneBox.hidden = false;
        doneBox.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    // Человек вернулся с оплаты или просто открыл страницу заново.
    var known = recall();
    if (known) {
        order = known;
        fetch(cfg.restUrl + 'gift/state?order=' + encodeURIComponent(order))
            .then(function (r) { return r.json().catch(function () { return {}; }); })
            .then(function (data) {
                if (!data || !data.status || data.status === 'none') { return; }
                if (data.lyrics) { showLyrics(data.lyrics); }
                if (data.status === 'done' && data.files && data.files.length) {
                    showTracks(data.files);
                } else if (data.status === 'paid' || data.status === 'singing') {
                    watch();
                }
            }).catch(function () {});
    }
})();
