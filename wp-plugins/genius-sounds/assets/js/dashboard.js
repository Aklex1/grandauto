/**
 * Genius Sounds — кабинет озвучки: чистим то, что переехало.
 *
 * Расшифровка и звук из ролика стали отдельными сервисами со своими
 * страницами, а описание API и выпуск ключей — общим разделом для всех
 * инструментов сразу. Три одноимённые вкладки из кабинета убираем и на
 * их место ставим ссылки.
 *
 * Разметку рабочего плагина не трогаем: всё делается здесь, поверх.
 */
(function () {
    'use strict';

    var cfg = window.GS_DASHBOARD || {};

    // Переехавшие разделы: вкладку прячем, вместо неё — ссылка на сервис.
    var MOVED = [
        { panel: 'transcribe', url: cfg.sttUrl, label: '📝 Расшифровка записи' },
        { panel: 'youtube-audio', url: cfg.ytUrl, label: '🎬 Звук из видео' },
        { panel: 'api', url: cfg.apiUrl, label: '🔌 API' }
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

    /* ------------------------------------------------------------------ текст из студии звуков
     *
     * В генератор звуков приходят с репликой — «голос девушки говорит "…"».
     * Там теперь стоит переход сюда, и текст он приносит в адресе. Подставляем
     * его в поле озвучки: иначе человек набирает всё заново и уходит.
     *
     * Разметку рабочего плагина не трогаем — работаем с готовым полем.
     */

    function carriedText() {
        var m = /[?&]gs_text=([^&#]*)/.exec(window.location.search);
        if (!m) {
            return '';
        }
        try {
            return decodeURIComponent(m[1].replace(/\+/g, ' ')).trim();
        } catch (e) {
            return '';
        }
    }

    // Поле озвучки готово уже на DOMContentLoaded, а выпадающий список
    // режимов плагин дорисовывает позже. Поэтому две задачи — подставить
    // текст и переключить режим — считаются выполненными по отдельности:
    // одна попытка на обе означала бы, что режим не переключится никогда.
    var filled = false;

    function field() {
        return document.getElementById('tts-text')
            || document.querySelector('[data-panel="generate"] textarea[name="text"]')
            || document.querySelector('textarea[name="text"]');
    }

    /**
     * Кабинет по умолчанию открыт в режиме «Диалог»: там вместо общего поля
     * стоят реплики по ролям, а наше поле спрятано. Человек с одной фразой
     * из студии звуков попал бы в пустой экран.
     *
     * Смотрим на значение режима, а не на видимость поля: на DOMContentLoaded
     * поле ещё на виду, плагин прячет его позже — по видимости мы принимали
     * решение раньше, чем было что решать. Переключаем не больше двух раз,
     * чтобы не спорить с человеком, если он сам выбрал диалог.
     */
    var modeSwitches = 0;

    function ensurePlainMode() {
        if (modeSwitches >= 2) {
            return;
        }
        var model = document.getElementById('tts-model');
        if (!model || model.value.indexOf('dialogue') === -1) {
            return;
        }
        var plain = null;
        Array.prototype.forEach.call(model.options, function (opt) {
            if (!plain && opt.value.indexOf('text-to-speech') !== -1) {
                plain = opt.value;
            }
        });
        if (!plain) {
            return;
        }
        model.value = plain;
        model.dispatchEvent(new Event('change', { bubbles: true }));
        modeSwitches++;
    }

    function prefillText() {
        var text = carriedText();
        if (!text) {
            filled = true;
            modeSwitches = 2;
            return;
        }

        ensurePlainMode();

        if (filled) {
            return;
        }
        var box = field();
        if (!box) {
            return; // панель рисуется скриптом плагина — повторим позже
        }
        filled = true;

        // Своё человек уже написал — не перетираем.
        if (!box.value.trim()) {
            box.value = text;
            box.dispatchEvent(new Event('input', { bubbles: true }));
            box.dispatchEvent(new Event('change', { bubbles: true }));
        }

        // Открываем нужную вкладку: по ссылке из студии человек ждёт озвучку.
        var tab = document.querySelector('.kie-tts-menu-item[data-panel="generate"]');
        if (tab && !tab.classList.contains('active')) {
            tab.click();
        }

        if (!document.getElementById('gs-carried-note')) {
            var note = document.createElement('p');
            note.id = 'gs-carried-note';
            note.className = 'gs-carried-note';
            note.innerHTML = '🗣️ Текст перенесён из генератора звуков. '
                + '<span>Здесь его произнесёт живой голос — выберите голос и язык ниже.</span>';
            (box.parentNode || box).insertBefore(note, box);
        }

        try {
            box.focus({ preventScroll: true });
        } catch (e) {
            box.focus();
        }
        var anchor = document.getElementById('gs-carried-note') || box;
        anchor.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }

    function apply() {
        moveOut();
        prefillText();
        // Панель API убрана целиком; правки внутри неё нужны только на тот
        // случай, если разметка плагина изменится и панель останется на месте.
        var panel = document.querySelector('[data-panel="api"]');
        if (!cfg.apiUrl || !panel || panel.hidden) {
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
    setTimeout(apply, 2600);
    setTimeout(apply, 5000);
})();
