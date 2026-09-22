/**
 * Чат-виджет ассистента. Без зависимостей — на странице уже хватает своих скриптов.
 * Вход переиспользуем чужой: window.kieTtsOpenAuthModal из kie-tts-wp.
 */
(function () {
  'use strict';

  function el(tag, cls, text) {
    var node = document.createElement(tag);
    if (cls) node.className = cls;
    if (text != null) node.textContent = text;
    return node;
  }

  function setup(root) {
    var slug = root.getAttribute('data-slug');
    var log = root.querySelector('.ga-chat__log');
    var form = root.querySelector('.ga-chat__form');
    var input = root.querySelector('.ga-chat__input');
    var send = root.querySelector('.ga-chat__send');
    var busy = false;

    function push(role, text) {
      var node = el('div', 'ga-msg ga-msg--' + role);
      text.split('\n').forEach(function (line, i) {
        if (i) node.appendChild(document.createElement('br'));
        node.appendChild(document.createTextNode(line));
      });
      log.appendChild(node);
      log.scrollTop = log.scrollHeight;
      return node;
    }

    function notice(text, withLogin) {
      var node = el('div', 'ga-msg ga-msg--notice');
      node.appendChild(document.createTextNode(text + ' '));
      if (withLogin) {
        var btn = el('button', 'ga-link', 'Войти');
        btn.type = 'button';
        btn.addEventListener('click', function () {
          if (typeof window.kieTtsOpenAuthModal === 'function') {
            window.kieTtsOpenAuthModal();
          } else {
            window.location.href = (window.gaChat && gaChat.loginUrl) || '/tts-login/';
          }
        });
        node.appendChild(btn);
      }
      log.appendChild(node);
      log.scrollTop = log.scrollHeight;
    }

    root.querySelectorAll('.ga-chip').forEach(function (chip) {
      chip.addEventListener('click', function () {
        input.value = chip.getAttribute('data-prompt');
        input.focus();
        // Курсор в конец — шаблоны заканчиваются многоточием, дописывать нужно туда.
        input.setSelectionRange(input.value.length, input.value.length);
        input.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
      });
    });

    function ask(text) {
      busy = true;
      send.disabled = true;
      var pending = push('bot', '…');
      pending.classList.add('is-typing');

      fetch(gaChat.rest + 'message', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': gaChat.nonce },
        body: JSON.stringify({ slug: slug, text: text, source: location.pathname })
      })
        .then(function (r) { return r.json().then(function (d) { return { status: r.status, data: d }; }); })
        .then(function (res) {
          pending.remove();
          var d = res.data || {};
          if (d.ok) {
            push('bot', d.reply);
            return;
          }
          notice(d.error || 'Не получилось получить ответ. Попробуйте ещё раз.',
                 d.code === 'auth_required' || d.code === 'no_funds');
        })
        .catch(function () {
          pending.remove();
          notice('Сеть не отвечает. Проверьте соединение и повторите.', false);
        })
        .finally(function () {
          busy = false;
          send.disabled = false;
        });
    }

    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var text = input.value.trim();
      if (!text || busy) return;
      push('user', text);
      input.value = '';
      ask(text);
    });

    input.addEventListener('keydown', function (e) {
      if ((e.metaKey || e.ctrlKey) && e.key === 'Enter') {
        form.requestSubmit();
      }
    });
  }

  function boot() {
    document.querySelectorAll('.ga-chat').forEach(setup);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
