/**
 * Промт из ссылки — сразу в поле нейрохаба.
 *
 * Статьи раздела промтов ведут сюда кнопкой «Создать такое же фото» и
 * передают текст промта в адресе. Без этого человек приходит на пустую
 * форму и должен вернуться за промтом обратно в статью.
 *
 * Сам нейрохаб мы не трогаем: подставляем значение в его поле и
 * переключаем вкладку его же кнопкой, как это сделал бы человек.
 */
(function () {
    var params = new URLSearchParams(window.location.search);
    var prompt = params.get('p');
    if (!prompt) { return; }

    var tries = 0;
    var tabTries = 0;

    function openTab() {
        var tab = document.querySelector('.kn-tab[data-tab="gen"]');
        if (!tab || tab.classList.contains('active')) {
            return;
        }
        tab.click();
        if (++tabTries < 12) {
            setTimeout(openTab, 400);
        }
    }

    function fill() {
        var field = document.getElementById('knPrompt');

        // Форма рисуется скриптом нейрохаба, поэтому ждём её появления.
        if (!field) {
            if (++tries < 40) { setTimeout(fill, 250); }
            return;
        }

        // Нейрохаб помечает выбранную вкладку классом active. Его скрипт
        // навешивает обработчики не сразу, поэтому жмём, пока не сработает.
        openTab();

        field.value = prompt;
        field.dispatchEvent(new Event('input', { bubbles: true }));
        field.dispatchEvent(new Event('change', { bubbles: true }));

        if (document.querySelector('.gs-nh-hint')) {
            return;
        }

        var hint = document.createElement('p');
        hint.className = 'gs-nh-hint';
        hint.textContent = 'Промт из статьи подставлен — выберите модель выше и нажмите «Сгенерировать».';
        field.parentNode.insertBefore(hint, field.nextSibling);

        field.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', fill);
    } else {
        fill();
    }
})();
