/**
 * Когда показать оффер «сделайте свой звук».
 *
 * Правило одно: сразу после того, как человек послушал звук. До этого он
 * ещё не получил того, за чем пришёл, и карточка поперёк страницы только
 * гонит его обратно в выдачу.
 *
 * Поверх играющего звука не показываем никогда: он нажал «играть», значит
 * сейчас слушает.
 */
(function () {
    'use strict';

    /*
     * Ждём разметку.
     *
     * Скрипт стоит в подвале и исполняется раньше, чем подвал допечатает
     * саму карточку: без этой проверки getElementById возвращал null,
     * обработчики не навешивались, и оффер молча не показывался.
     */
    function ready(fn) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', fn);
        } else {
            fn();
        }
    }

    ready(function () {
        var box = document.getElementById('gs-offer');
        if (!box || typeof gsOffer === 'undefined') {
            return;
        }

        var KEY = 'gs_offer_hold';
        var shown = false;
        var playing = false;
        var timer = null;

        /*
         * Значения из wp_localize_script приходят строками, а строка «0»
         * в JS правдива: без явного сравнения выключенный предпросмотр
         * показывал карточку всем и сразу.
         */
        var preview = String(gsOffer.preview) === '1';

        /** Закрывал ли он карточку недавно. Память может быть недоступна. */
        function held() {
            try {
                var till = parseInt(window.localStorage.getItem(KEY), 10);
                return till > Date.now();
            } catch (e) {
                return false;
            }
        }

        function hold() {
            try {
                var days = parseInt(gsOffer.hold, 10) || 7;
                window.localStorage.setItem(KEY, String(Date.now() + days * 86400000));
            } catch (e) {
                // Приватное окно или запрет на хранение: тогда карточка
                // покажется ещё раз в следующий визит. Терпимо.
            }
        }

        function goal(name) {
            try {
                if (gsOffer.counter && name && typeof window.ym === 'function') {
                    window.ym(gsOffer.counter, 'reachGoal', name);
                }
            } catch (e) {
            }
        }

        function show() {
            if (shown || playing || held()) {
                return;
            }
            shown = true;
            clearTimeout(timer);
            box.hidden = false;
            var go = box.querySelector('[data-gs-offer-go]');
            if (go) {
                go.focus();
            }
            goal(gsOffer.goalShow);
        }

        function close() {
            box.hidden = true;
            if (!preview) {
                hold();
            }
        }

        function bind() {
            box.addEventListener('click', function (event) {
                if (event.target.closest('[data-gs-offer-close]')) {
                    close();
                    return;
                }
                if (event.target.closest('[data-gs-offer-go]')) {
                    goal(gsOffer.goalClick);
                    hold();
                }
            });

            document.addEventListener('keydown', function (event) {
                if (event.key === 'Escape' && !box.hidden) {
                    close();
                }
            });
        }

        /*
         * Предпросмотр по ссылке ?gs_offer=1.
         *
         * Показываем сразу, цель не зовём и в память не пишем: иначе наши
         * же проверки попадут в отчёт, а проверяющему оффер замолчит на
         * неделю.
         */
        if (preview) {
            box.hidden = false;
            bind();
            return;
        }

        if (held()) {
            return;
        }

        // Звук доиграл — человек получил своё, можно предлагать.
        document.addEventListener('gs:sound-end', function () {
            playing = false;
            clearTimeout(timer);
            timer = setTimeout(show, parseInt(gsOffer.delay, 10) || 1200);
        });

        document.addEventListener('gs:sound-play', function () {
            playing = true;
            clearTimeout(timer);
        });

        /*
         * Так и не включил ни одного звука.
         *
         * Тогда ждём по времени, но только если он листает страницу: без
         * прокрутки это чаще всего открытая и забытая вкладка.
         */
        var scrolled = false;
        window.addEventListener('scroll', function () {
            scrolled = true;
        }, { passive: true, once: true });

        /*
         * На списке разделов плееров нет вовсе, и ждать там полную минуту
         * бессмысленно: человеку нечего проигрывать, ждём меньше.
         */
        var bare = !document.querySelector('[data-gs-play]');
        var wait = bare
            ? (parseInt(gsOffer.bare, 10) || 20000)
            : (parseInt(gsOffer.idle, 10) || 45000);

        timer = setTimeout(function () {
            if (scrolled) {
                show();
            }
        }, wait);

        bind();
    });
})();
