/**
 * Цели Метрики: где каталог превращается в деньги.
 *
 * Трафик каталога звуков вырос в пять раз, а платят единицы. Без событий
 * непонятно, где обрывается путь: человек не видит перехода в студию,
 * видит но не жмёт, или жмёт и уходит на регистрации. Цели по адресам
 * (регистрация, кабинет, оплата) Метрика считает сама, а вот нажатия
 * считать некому — этим занимается этот файл.
 *
 * Правило одно: ничего не ломать. Если счётчик не загрузился или его
 * вырезал блокировщик, обработчики молча ничего не делают, а переход по
 * ссылке происходит как обычно.
 */
(function () {
    'use strict';

    var cfg = window.gsGoals || {};
    var counter = parseInt(cfg.counter, 10) || 0;
    if (!counter) {
        return;
    }

    function hit(name, params, done) {
        try {
            if (typeof window.ym === 'function') {
                window.ym(counter, 'reachGoal', name, params || {}, done || undefined);
                return true;
            }
        } catch (e) {
            /* счётчик недоступен — это не повод ломать переход */
        }
        if (done) {
            done();
        }
        return false;
    }

    /**
     * Цель и переход по ссылке.
     *
     * Простой reachGoal перед обычным кликом теряется: браузер уходит на
     * другую страницу раньше, чем запрос успевает выйти. Проверка в
     * браузере это и показала — нажатие на карточку инструмента не
     * доезжало до Метрики вовсе.
     *
     * Поэтому придерживаем переход и отпускаем его по ответу счётчика, но
     * не дольше 400 мс: цель важна, а ждать человек не должен. Клики со
     * средней кнопкой, с Ctrl и по ссылкам в новую вкладку не трогаем —
     * там переход и так не мешает запросу.
     */
    function hitAndFollow(name, params, e, link) {
        var href = link && link.getAttribute('href');
        var newTab = !href || link.target === '_blank' || e.defaultPrevented
            || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey
            || href.charAt(0) === '#' || href.indexOf('javascript:') === 0;
        if (newTab) {
            hit(name, params);
            return;
        }

        e.preventDefault();
        var went = false;
        var go = function () {
            if (!went) {
                went = true;
                window.location.href = link.href;
            }
        };
        setTimeout(go, 400);
        hit(name, params, go);
    }

    function closest(el, selector) {
        while (el && el.nodeType === 1) {
            if (el.matches && el.matches(selector)) {
                return el;
            }
            el = el.parentElement;
        }
        return null;
    }

    document.addEventListener('click', function (e) {
        var el = e.target;
        if (!el || el.nodeType !== 1) {
            return;
        }

        // Липкая панель — главный мостик из статьи в нужный сервис.
        var sticky = closest(el, '.gs-sticky__go');
        if (sticky) {
            hitAndFollow('sticky_go', { url: location.pathname }, e, sticky);
            return;
        }

        // Карусель инструментов под статьёй.
        var tool = closest(el, '[data-gs-carousel] a');
        if (tool) {
            hitAndFollow('tool_open', { tool: tool.getAttribute('href') || '' }, e, tool);
            return;
        }

        // Призыв «сделать своё» на страницах каталога.
        var cta = closest(el, '.gs-cta a, .gs-cta__submit');
        if (cta) {
            hitAndFollow('cta_studio', { url: location.pathname }, e, cta);
            return;
        }

        // Пополнение баланса — последний шаг перед деньгами.
        if (closest(el, '[data-gs-topup]')) {
            hit('topup_open');
            return;
        }

        // Уход в бота: для каталога это тоже конверсия, человек остаётся наш.
        var tg = closest(el, 'a[href*="t.me/"]');
        if (tg) {
            hitAndFollow('to_bot', { from: location.pathname }, e, tg);
        }
    }, true);
})();
