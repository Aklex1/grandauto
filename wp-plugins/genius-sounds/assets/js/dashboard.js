/**
 * Genius Sounds — кабинет озвучки: ссылка на общую документацию API.
 *
 * Описание всех инструментов теперь живёт в одном разделе, поэтому
 * ссылку из кабинета уводим туда, а дублирующую справку внутри панели
 * сворачиваем — ключи и их выпуск остаются на месте.
 */
(function () {
    'use strict';

    var cfg = window.GS_DASHBOARD || {};
    if (!cfg.apiUrl) {
        return;
    }

    function apply() {
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
