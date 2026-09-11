/**
 * Genius Sounds — кабинет озвучки: чистим то, что переехало.
 *
 * Расшифровка и звук из ролика стали отдельными сервисами со своими
 * страницами, поэтому одноимённые вкладки из кабинета убираем и на их
 * место ставим ссылки. Описание API тоже живёт теперь в общем разделе:
 * ссылку уводим туда, дублирующую справку сворачиваем — ключи и их
 * выпуск остаются на месте.
 *
 * Разметку рабочего плагина не трогаем: всё делается здесь, поверх.
 */
(function () {
    'use strict';

    var cfg = window.GS_DASHBOARD || {};

    // Переехавшие разделы: вкладку прячем, вместо неё — ссылка на сервис.
    var MOVED = [
        { panel: 'transcribe', url: cfg.sttUrl, label: '📝 Расшифровка записи' },
        { panel: 'youtube-audio', url: cfg.ytUrl, label: '🎬 Звук из видео' }
    ];

    function moveOut() {
        MOVED.forEach(function (item) {
            if (!item.url) {
                return;
            }
            var nav = document.querySelector('.kie-tts-top-nav');
            var buttons = document.querySelectorAll('[data-panel="' + item.panel + '"]');
            Array.prototype.forEach.call(buttons, function (el) {
                // Кнопка меню превращается в ссылку, панель с формой исчезает.
                if (el.classList.contains('kie-tts-menu-item')) {
                    if (el.dataset.gsMoved) {
                        return;
                    }
                    var link = document.createElement('a');
                    link.className = el.className.replace('active', '').trim();
                    link.href = item.url;
                    link.textContent = item.label;
                    link.dataset.gsMoved = '1';
                    el.parentNode.replaceChild(link, el);
                } else {
                    el.hidden = true;
                    el.style.display = 'none';
                }
            });
            // Кнопка для незалогиненных живёт с другим признаком.
            var guarded = document.querySelectorAll('[data-auth-reason="' + item.panel + '"]');
            Array.prototype.forEach.call(guarded, function (el) {
                if (el.dataset.gsMoved) {
                    return;
                }
                var link = document.createElement('a');
                link.className = (el.className || '').replace('kie-tts-auth-required', '').replace('kie-auth-open-trigger', '').trim();
                link.href = item.url;
                link.textContent = item.label;
                link.dataset.gsMoved = '1';
                el.parentNode.replaceChild(link, el);
            });
            if (nav) {
                nav.setAttribute('data-gs-cleaned', '1');
            }
        });
    }

    function apply() {
        moveOut();
        if (!cfg.apiUrl) {
            return;
        }
        var panel = document.querySelector('[data-panel="api"]');
        if (!panel) {
            return;
        }

        // Ссылка «Открыть документацию API» ведёт в общий раздел.
        Array.prototype.forEach.call(panel.querySelectorAll('a, button'), function (el) {
            var text = (el.textContent || '').toLowerCase();
            if (text.indexOf('документац') === -1) {
                return;
            }
            if (el.tagName === 'A') {
                el.setAttribute('href', cfg.apiUrl);
                el.setAttribute('target', '_blank');
                el.setAttribute('rel', 'noopener');
            } else {
                el.addEventListener('click', function (e) {
                    e.preventDefault();
                    window.open(cfg.apiUrl, '_blank', 'noopener');
                });
            }
        });

        // Старая справка внутри панели дублирует общий раздел.
        var docs = panel.querySelectorAll('.api-docs-card');
        if (docs.length) {
            Array.prototype.forEach.call(docs, function (card) {
                card.hidden = true;
            });
            var note = document.createElement('p');
            note.className = 'gs-dash-apinote';
            note.innerHTML = 'Полное описание всех инструментов — в разделе '
                + '<a href="' + cfg.apiUrl + '" target="_blank" rel="noopener">API для разработчиков</a>: '
                + 'озвучка, расшифровка, звук из ролика, оживление фото, говорящий аватар и генерация звуков.';
            (docs[0].parentNode || panel).insertBefore(note, docs[0]);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', apply);
    } else {
        apply();
    }
    // Панели рисуются скриптом плагина — подстраховываемся повтором.
    setTimeout(apply, 1200);
})();
