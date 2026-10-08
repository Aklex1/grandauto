/**
 * Genius Sounds — имя поставщика не показываем пользователю.
 *
 * Страницы генерации фото и кабинета озвучки рисуют отдельные плагины, и
 * в их надписях о ходе работы всплывает название поставщика: «Задача
 * отправлена в KIE API», «Задача в очереди KIE». Человеку это ничего не
 * объясняет, зато выдаёт, на чьих мощностях всё крутится.
 *
 * Править чужой плагин не стали намеренно: его обновление вернуло бы
 * надписи обратно, и правку пришлось бы помнить. Здесь мы вместо этого
 * подменяем текст уже в разметке — переживает любые их обновления.
 *
 * Трогаем только текстовые узлы: разметку, классы и адреса не задеваем,
 * иначе сломались бы их же селекторы.
 */
(function () {
    'use strict';

    var RULES = [
        [/задача\s+отправлена\s+в\s+KIE\s*API/gi, 'Задача отправлена в обработку'],
        [/задача\s+в\s+очереди\s+KIE/gi, 'Задача в очереди'],
        [/в\s+очереди\s+KIE/gi, 'в очереди'],
        [/\s*в\s+KIE\s*API/gi, ' в обработку'],
        [/плагина\s+KIE\s*TTS/gi, 'плагина озвучки'],
        [/\bKIE\s*API\b/g, 'сервис генерации'],
        [/\bKIE\b/g, 'сервис'],
    ];

    function clean(s) {
        var out = s;
        for (var i = 0; i < RULES.length; i++) {
            out = out.replace(RULES[i][0], RULES[i][1]);
        }
        return out;
    }

    // Внутрь <script>, <style> и полей ввода не лезем: там это не текст
    // для глаз, а код и данные.
    var SKIP = { SCRIPT: 1, STYLE: 1, TEXTAREA: 1, CODE: 1, PRE: 1 };

    function walk(node) {
        if (!node) {
            return;
        }
        if (node.nodeType === 3) {
            var t = node.nodeValue;
            if (t && t.indexOf('KIE') !== -1) {
                var fixed = clean(t);
                if (fixed !== t) {
                    node.nodeValue = fixed;
                }
            }
            return;
        }
        if (node.nodeType !== 1 || SKIP[node.nodeName]) {
            return;
        }
        // Подписи и подсказки тоже видны человеку.
        ['title', 'aria-label', 'placeholder'].forEach(function (a) {
            var v = node.getAttribute && node.getAttribute(a);
            if (v && v.indexOf('KIE') !== -1) {
                node.setAttribute(a, clean(v));
            }
        });
        for (var c = node.firstChild; c; c = c.nextSibling) {
            walk(c);
        }
    }

    function sweep() {
        walk(document.body);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', sweep);
    } else {
        sweep();
    }

    // Надписи о ходе работы появляются уже после загрузки, по мере
    // обращения к поставщику, — поэтому следим за разметкой постоянно.
    if (window.MutationObserver) {
        var queued = false;
        new MutationObserver(function () {
            if (queued) {
                return;
            }
            queued = true;
            window.requestAnimationFrame(function () {
                queued = false;
                sweep();
            });
        }).observe(document.documentElement, {
            childList: true,
            subtree: true,
            characterData: true,
        });
    }
}());
