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

  // Запомнить страницу, с которой пошли авторизовываться — чтобы вернуться сюда.
  function rememberReturn() {
    try {
      document.cookie = 'ga_login_return=' +
        encodeURIComponent(location.pathname + location.search + location.hash) +
        '; path=/; max-age=900; samesite=Lax';
    } catch (e) {}
  }

  // Рубли без лишних нулей: 48, 48.5, 120.
  function money(v) {
    v = Math.round((Number(v) || 0) * 100) / 100;
    if (Math.abs(v - Math.round(v)) < 0.005) return String(Math.round(v));
    return v.toFixed(2).replace(/0+$/, '').replace(/\.$/, '');
  }

  function setup(root) {
    var slug = root.getAttribute('data-slug');
    var log = root.querySelector('.ga-chat__log');
    var form = root.querySelector('.ga-chat__form');
    var input = root.querySelector('.ga-chat__input');
    var send = root.querySelector('.ga-chat__send');
    var attach = root.querySelector('.ga-chat__attach');
    var fileInput = root.querySelector('.ga-chat__file');
    var fileChip = root.querySelector('.ga-chat__filechip');
    var uploadNote = root.querySelector('.ga-chat__uploadnote');
    var canUpload = root.getAttribute('data-can-upload') === '1';
    var balanceEl = root.querySelector('[data-ga-balance]');
    var topup = root.querySelector('.ga-topup');
    var busy = false;
    var pendingFile = null;

    function setBalance(v) {
      if (balanceEl && v != null && !isNaN(Number(v))) {
        balanceEl.textContent = money(v) + ' ₽';
      }
    }

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
          rememberReturn();
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

    // Кнопка «Войти» в гостевой подсказке: сами открываем модалку входа
    // (или уводим на /tts-login/), не полагаясь на чужой скрипт на странице.
    root.querySelectorAll('.kie-auth-open-trigger').forEach(function (b) {
      b.addEventListener('click', function (e) {
        e.preventDefault();
        rememberReturn();
        if (typeof window.kieTtsOpenAuthModal === 'function') {
          window.kieTtsOpenAuthModal();
        } else {
          window.location.href = (window.gaChat && gaChat.loginUrl) || '/tts-login/';
        }
      });
    });

    root.querySelectorAll('.ga-chip').forEach(function (chip) {
      chip.addEventListener('click', function () {
        input.value = chip.getAttribute('data-prompt');
        input.focus();
        // Курсор в конец — шаблоны заканчиваются многоточием, дописывать нужно туда.
        input.setSelectionRange(input.value.length, input.value.length);
        input.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
      });
    });

    function clearFile() {
      pendingFile = null;
      if (fileInput) fileInput.value = '';
      if (fileChip) { fileChip.hidden = true; fileChip.textContent = ''; }
    }

    if (attach) {
      attach.addEventListener('click', function (e) {
        if (!canUpload) {
          e.preventDefault();
          if (uploadNote) uploadNote.hidden = false;
          attach.classList.add('is-flash');
          setTimeout(function () { attach.classList.remove('is-flash'); }, 1200);
          notice('Подгрузка документов и фото доступна после авторизации на платном тарифе.',
                 true);
        }
      });
    }
    if (fileInput) {
      fileInput.addEventListener('change', function () {
        var f = fileInput.files && fileInput.files[0];
        if (!f) return;
        if (f.size > 8 * 1024 * 1024) {
          notice('Файл больше 8 МБ. Уменьшите размер.', false);
          fileInput.value = ''; return;
        }
        var reader = new FileReader();
        reader.onload = function () {
          var res = String(reader.result || '');
          var b64 = res.indexOf(',') >= 0 ? res.slice(res.indexOf(',') + 1) : res;
          pendingFile = { data: b64, mime: f.type || 'application/octet-stream', name: f.name };
          if (fileChip) {
            fileChip.hidden = false;
            fileChip.textContent = '';
            fileChip.appendChild(document.createTextNode('📎 ' + f.name + '  '));
            var x = el('button', 'ga-chip', '✕');
            x.type = 'button';
            x.addEventListener('click', clearFile);
            fileChip.appendChild(x);
          }
        };
        reader.readAsDataURL(f);
      });
    }

    function ask(text) {
      busy = true;
      send.disabled = true;
      var pending = push('bot', '…');
      pending.classList.add('is-typing');

      fetch(gaChat.rest + 'message', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': gaChat.nonce },
        body: JSON.stringify(pendingFile
          ? { slug: slug, text: text, source: location.pathname, file: pendingFile }
          : { slug: slug, text: text, source: location.pathname })
      })
        .then(function (r) { return r.json().then(function (d) { return { status: r.status, data: d }; }); })
        .then(function (res) {
          pending.remove();
          var d = res.data || {};
          if (d.ok) {
            push('bot', d.reply);
            setBalance(d.balance);
            clearFile();
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
      if ((!text && !pendingFile) || busy) return;
      push('user', (pendingFile ? '📎 ' + pendingFile.name + (text ? ' — ' : '') : '') + text);
      input.value = '';
      ask(text);
    });

    input.addEventListener('keydown', function (e) {
      if ((e.metaKey || e.ctrlKey) && e.key === 'Enter') {
        form.requestSubmit();
      }
    });

    // ---- Пополнение баланса через ЮMoney (тот же маршрут, что у микросервисов) ----
    if (topup && window.gaChat && gaChat.ttsRest) {
      var topupLink = topup.querySelector('.ga-topup__link');
      var topupMsg = topup.querySelector('.ga-topup__msg');
      var topupBtns = topup.querySelectorAll('.ga-topup__amount');

      function topupState(text, kind) {
        if (!topupMsg) return;
        topupMsg.textContent = text || '';
        topupMsg.hidden = !text;
        topupMsg.className = 'ga-topup__msg' + (kind ? ' is-' + kind : '');
      }

      topupBtns.forEach(function (btn) {
        btn.addEventListener('click', function () {
          var amount = parseInt(btn.getAttribute('data-amount'), 10) || 0;
          if (!amount || btn.classList.contains('is-loading')) return;
          topupBtns.forEach(function (b) { b.classList.remove('is-loading'); });
          btn.classList.add('is-loading');
          if (topupLink) topupLink.hidden = true;
          topupState('Готовим ссылку на оплату…', 'load');

          fetch(gaChat.ttsRest + 'topup', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': gaChat.nonce },
            body: JSON.stringify({ amount: amount })
          })
            .then(function (r) { return r.json().then(function (d) { return { status: r.status, data: d }; }); })
            .then(function (res) {
              var d = res.data || {};
              if (d.success && d.payment_link && topupLink) {
                topupLink.href = d.payment_link;
                topupLink.textContent = 'Перейти к оплате ' + amount + ' ₽ →';
                topupLink.hidden = false;
                topupState('Ссылка готова — оплата откроется в новой вкладке.', 'ok');
              } else {
                topupState((d && d.message) || 'Не удалось создать ссылку на оплату. Попробуйте ещё раз.', 'err');
              }
            })
            .catch(function () {
              topupState('Сеть не отвечает. Повторите попытку.', 'err');
            })
            .finally(function () { btn.classList.remove('is-loading'); });
        });
      });
    }

    // ---- Форма техподдержки (модалка) → уведомление владельцу в Telegram ----
    var supModal = root.querySelector('.ga-supmodal');
    if (supModal) {
      var supForm = supModal.querySelector('.ga-supform');
      var supMsg = supModal.querySelector('.ga-supform__msg');
      var supContact = supModal.querySelector('.ga-supform__contact');
      var supSend = supModal.querySelector('.ga-supform__send');
      var supStatus = supModal.querySelector('.ga-supform__status');
      var supBusy = false;

      function supOpen() {
        supModal.hidden = false;
        if (supStatus) { supStatus.hidden = true; supStatus.textContent = ''; }
        setTimeout(function () { if (supMsg) supMsg.focus(); }, 30);
      }
      function supClose() { supModal.hidden = true; }
      function supState(text, kind) {
        if (!supStatus) return;
        supStatus.textContent = text || '';
        supStatus.hidden = !text;
        supStatus.className = 'ga-supform__status' + (kind ? ' is-' + kind : '');
      }

      root.querySelectorAll('[data-ga-support]').forEach(function (b) {
        b.addEventListener('click', supOpen);
      });
      supModal.querySelectorAll('[data-ga-support-close]').forEach(function (b) {
        b.addEventListener('click', supClose);
      });
      document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && !supModal.hidden) supClose();
      });

      if (supForm) {
        supForm.addEventListener('submit', function (e) {
          e.preventDefault();
          if (supBusy) return;
          var message = (supMsg && supMsg.value || '').trim();
          if (message.length < 5) { supState('Опишите вопрос подробнее.', 'err'); return; }
          supBusy = true;
          if (supSend) supSend.disabled = true;
          supState('Отправляем…', 'load');

          fetch(gaChat.rest + 'support', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': gaChat.nonce },
            body: JSON.stringify({
              slug: slug,
              message: message,
              contact: (supContact && supContact.value || '').trim(),
              page: location.href
            })
          })
            .then(function (r) { return r.json().then(function (d) { return { status: r.status, data: d }; }); })
            .then(function (res) {
              var d = res.data || {};
              if (d.ok) {
                supState(d.message || 'Отправлено. Спасибо!', 'ok');
                if (supForm) supForm.reset();
                setTimeout(supClose, 1800);
              } else {
                supState(d.error || 'Не удалось отправить. Попробуйте позже.', 'err');
              }
            })
            .catch(function () { supState('Сеть не отвечает. Повторите попытку.', 'err'); })
            .finally(function () { supBusy = false; if (supSend) supSend.disabled = false; });
        });
      }
    }

    // Синхронизируем баланс и доступ к загрузке на случай устаревшего кэша страницы.
    // Nonce обязателен: без него WP REST не опознаёт куку и вернёт гостя.
    if (window.gaChat && gaChat.loggedIn && gaChat.rest) {
      fetch(gaChat.rest + 'state?slug=' + encodeURIComponent(slug),
            { credentials: 'same-origin', headers: { 'X-WP-Nonce': gaChat.nonce } })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          if (!d) return;
          setBalance(d.balance);
          if (typeof d.can_upload !== 'undefined') {
            canUpload = !!d.can_upload;
            if (attach) attach.classList.toggle('is-locked', !canUpload);
          }
        })
        .catch(function () {});
    }
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
