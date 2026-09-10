/**
 * Genius Sounds — плеер каталога.
 * Один общий Audio на страницу: 25+ отдельных <audio> тормозят мобильные браузеры.
 */
(function () {
    'use strict';

    var audio = null;
    var currentCard = null;

    function getAudio() {
        if (!audio) {
            audio = new Audio();
            audio.preload = 'none';

            audio.addEventListener('timeupdate', function () {
                if (!currentCard || !audio.duration) {
                    return;
                }
                var bar = currentCard.querySelector('[data-gs-bar]');
                if (bar) {
                    bar.style.width = ((audio.currentTime / audio.duration) * 100) + '%';
                }
                var time = currentCard.querySelector('[data-gs-time]');
                if (time) {
                    time.textContent = formatTime(audio.currentTime) + ' / ' + formatTime(audio.duration);
                }
            });

            audio.addEventListener('ended', function () {
                stop();
            });

            audio.addEventListener('error', function () {
                if (currentCard) {
                    var title = currentCard.querySelector('.gs-sound__title');
                    if (title) {
                        title.setAttribute('title', 'Не удалось загрузить файл');
                    }
                }
                stop();
            });
        }
        return audio;
    }

    function formatTime(seconds) {
        if (!isFinite(seconds)) {
            return '0:00';
        }
        var m = Math.floor(seconds / 60);
        var s = Math.floor(seconds % 60);
        return m + ':' + (s < 10 ? '0' : '') + s;
    }

    function stop() {
        var a = getAudio();
        a.pause();
        if (currentCard) {
            currentCard.classList.remove('is-playing');
            var bar = currentCard.querySelector('[data-gs-bar]');
            if (bar) {
                bar.style.width = '0%';
            }
        }
        currentCard = null;
    }

    function play(card) {
        var src = card.getAttribute('data-src');
        if (!src) {
            return;
        }
        var a = getAudio();

        if (currentCard === card) {
            if (a.paused) {
                a.play();
                card.classList.add('is-playing');
            } else {
                a.pause();
                card.classList.remove('is-playing');
            }
            return;
        }

        stop();
        currentCard = card;
        a.src = src;
        card.classList.add('is-playing');
        var promise = a.play();
        if (promise && promise.catch) {
            promise.catch(function () {
                card.classList.remove('is-playing');
            });
        }
    }

    function seek(card, event) {
        if (currentCard !== card) {
            return;
        }
        var a = getAudio();
        if (!a.duration) {
            return;
        }
        var track = event.currentTarget;
        var rect = track.getBoundingClientRect();
        var ratio = (event.clientX - rect.left) / rect.width;
        a.currentTime = Math.max(0, Math.min(1, ratio)) * a.duration;
    }

    /**
     * Тёмная тема базового плагина обнуляет отступ под фиксированной шапкой,
     * потому что саму шапку прячет. Мы шапку показываем — значит, отступ надо
     * вернуть, причём по реальной высоте: она разная на десктопе и телефоне.
     */
    function fixHeaderOffset() {
        if (!document.body.classList.contains('gs-chrome')) {
            return;
        }
        var header = document.querySelector('.l-header');
        if (!header) {
            return;
        }
        var target = document.querySelector('.l-main > .l-section:first-of-type > .l-section-h')
            || document.getElementById('page-content')
            || document.querySelector('.l-main');
        if (!target) {
            return;
        }
        if (getComputedStyle(header).position !== 'fixed') {
            target.style.paddingTop = '';
            return;
        }
        var height = header.getBoundingClientRect().height;
        target.style.paddingTop = height > 0 ? Math.ceil(height) + 'px' : '';
    }

    function init() {
        fixHeaderOffset();
        window.addEventListener('resize', fixHeaderOffset);
        window.addEventListener('load', fixHeaderOffset);

        var cards = document.querySelectorAll('[data-gs-sound]');
        Array.prototype.forEach.call(cards, function (card) {
            var button = card.querySelector('[data-gs-play]');
            if (button) {
                button.addEventListener('click', function (e) {
                    e.preventDefault();
                    play(card);
                });
            }
            var progress = card.querySelector('[data-gs-progress]');
            if (progress) {
                progress.addEventListener('click', function (e) {
                    seek(card, e);
                });
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
