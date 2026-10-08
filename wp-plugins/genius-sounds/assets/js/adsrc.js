/**
 * Метка перехода: кто привёл человека на сайт.
 *
 * Пишется в браузере, а не на сервере, по двум причинам. Во-первых,
 * хостинг вырезает utm-метки из запроса ещё до PHP — в адресной строке
 * они есть, а до кода не доезжают. Во-вторых, страницы отдаются из кэша,
 * и на таком заходе PHP не запускается вовсе.
 *
 * Правило одно: ничего не ломать. Любая неожиданность — просто выходим.
 */
(function () {
    'use strict';

    var NAME = 'gs_ad';
    var TTL = 90 * 24 * 60 * 60;

    function get(name) {
        var all = ('; ' + document.cookie).split('; ' + name + '=');
        return all.length === 2 ? decodeURIComponent(all.pop().split(';').shift()) : '';
    }

    function cut(v) {
        v = String(v || '').replace(/\s+/g, ' ').trim();
        return v.length > 120 ? v.slice(0, 120) : v;
    }

    try {
        var q = new URLSearchParams(window.location.search);
        var src = cut(q.get('utm_source'));
        var med = cut(q.get('utm_medium'));
        var yclid = q.get('yclid') || q.get('ymclid');
        var kw = cut(q.get('kw') || q.get('mkw') || q.get('utm_term'));

        // Яндекс помечает свой клик yclid даже там, где меток нет.
        if (!src && yclid) {
            src = 'yandex';
            med = med || 'cpc';
        }
        if (!src && !kw) {
            return;
        }

        /* Рекламный переход метку перебивает: за последний клик мы и
           заплатили. Всё остальное пишем только на чистое место, чтобы
           не затереть рекламу переходом из закладки. */
        var paid = med === 'cpc' || !!yclid;
        if (!paid && get(NAME)) {
            return;
        }

        var val = [
            's=' + encodeURIComponent(src),
            'm=' + encodeURIComponent(med),
            'c=' + encodeURIComponent(cut(q.get('utm_campaign'))),
            'g=' + encodeURIComponent(cut(q.get('utm_content') || q.get('gbid'))),
            'k=' + encodeURIComponent(kw),
            't=' + new Date().toISOString().slice(0, 10)
        ].join('&');

        document.cookie = NAME + '=' + encodeURIComponent(val)
            + '; max-age=' + TTL + '; path=/; samesite=lax'
            + (window.location.protocol === 'https:' ? '; secure' : '');
    } catch (e) {
        /* старый браузер или заблокированные куки — не наша забота */
    }
}());
