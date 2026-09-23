/**
 * Личный кабинет: база знаний клиента. Сохранение, загрузка документов
 * (текст извлекается на сервере и добавляется в базу) и заявка на «коробку».
 */
(function () {
  'use strict';

  function boot() {
    var root = document.querySelector('.ga-kb');
    if (!root || !window.gaKB) return;

    var form = root.querySelector('.ga-kbform');
    var cta = root.querySelector('.ga-kb__ctaBtn');
    var ctaStatus = root.querySelector('.ga-kb__ctaStatus');

    function setStatus(node, text, kind) {
      if (!node) return;
      node.textContent = text || '';
      node.hidden = !text;
      node.className = node.className.replace(/\s*is-(ok|err|load)\b/g, '') + (kind ? ' is-' + kind : '');
    }

    if (form) {
      var company = form.querySelector('.ga-kbform__company');
      var content = form.querySelector('.ga-kbform__content');
      var fileInput = form.querySelector('.ga-kbform__file input[type=file]');
      var count = form.querySelector('.ga-kbform__count');
      var save = form.querySelector('.ga-kbform__save');
      var status = form.querySelector('.ga-kbform__status');
      var busy = false;

      function refreshCount() {
        if (count) count.textContent = (content.value.length) + ' символов';
      }
      content.addEventListener('input', refreshCount);
      refreshCount();

      if (fileInput) {
        fileInput.addEventListener('change', function () {
          var f = fileInput.files && fileInput.files[0];
          if (!f) return;
          if (f.size > 8 * 1024 * 1024) { setStatus(status, 'Файл больше 8 МБ.', 'err'); fileInput.value = ''; return; }
          setStatus(status, 'Читаем документ…', 'load');
          var reader = new FileReader();
          reader.onload = function () {
            var res = String(reader.result || '');
            var b64 = res.indexOf(',') >= 0 ? res.slice(res.indexOf(',') + 1) : res;
            fetch(gaKB.rest + 'kb/extract', {
              method: 'POST', credentials: 'same-origin',
              headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': gaKB.nonce },
              body: JSON.stringify({ file: { data: b64, mime: f.type || '', name: f.name } })
            })
              .then(function (r) { return r.json(); })
              .then(function (d) {
                if (d && d.ok && d.text) {
                  var add = (content.value.trim() ? content.value.trim() + '\n\n' : '')
                    + '# ' + f.name + '\n' + d.text;
                  content.value = add.slice(0, 20000);
                  refreshCount();
                  setStatus(status, 'Документ добавлен в базу. Не забудьте сохранить.', 'ok');
                } else {
                  setStatus(status, (d && d.error) || 'Не удалось прочитать документ.', 'err');
                }
              })
              .catch(function () { setStatus(status, 'Сеть не отвечает.', 'err'); })
              .finally(function () { fileInput.value = ''; });
          };
          reader.readAsDataURL(f);
        });
      }

      form.addEventListener('submit', function (e) {
        e.preventDefault();
        if (busy) return;
        busy = true;
        if (save) save.disabled = true;
        setStatus(status, 'Сохраняем…', 'load');
        fetch(gaKB.rest + 'kb', {
          method: 'POST', credentials: 'same-origin',
          headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': gaKB.nonce },
          body: JSON.stringify({ company: company ? company.value : '', content: content.value })
        })
          .then(function (r) { return r.json().then(function (d) { return { status: r.status, data: d }; }); })
          .then(function (res) {
            var d = res.data || {};
            setStatus(status, d.ok ? (d.message || 'Сохранено.') : (d.error || 'Не удалось сохранить.'),
              d.ok ? 'ok' : 'err');
          })
          .catch(function () { setStatus(status, 'Сеть не отвечает.', 'err'); })
          .finally(function () { busy = false; if (save) save.disabled = false; });
      });
    }

    // ---- Коробка: брендинг и подключение своего бота ----
    var box = root.querySelector('.ga-box');
    if (box) {
      var bName = box.querySelector('.ga-box__name');
      var bWelcome = box.querySelector('.ga-box__welcome');
      var bPersona = box.querySelector('.ga-box__persona');
      var bAccent = box.querySelector('.ga-box__accent');
      var bFree = box.querySelector('.ga-box__free');
      var bToken = box.querySelector('.ga-box__token');
      var bSave = box.querySelector('.ga-box__save');
      var bConnect = box.querySelector('.ga-box__connect');
      var bDisconnect = box.querySelector('.ga-box__disconnect');
      var bState = box.querySelector('.ga-box__state');
      var bStatus = box.querySelector('.ga-box__status');
      var bBotStatus = box.querySelector('.ga-box__botstatus');
      var bEmbed = box.querySelector('.ga-box__embed');
      var bBusy = false;

      function boxFields() {
        return {
          name: bName ? bName.value : '',
          welcome: bWelcome ? bWelcome.value : '',
          persona: bPersona ? bPersona.value : '',
          accent: bAccent ? bAccent.value : '#22d3ee',
          free_daily: bFree ? bFree.value : 10
        };
      }

      function applyTenant(d) {
        if (!d) return;
        if (bState) {
          if (d.status === 'connected' && d.bot_username) {
            bState.innerHTML = 'Бот подключён: <a href="https://t.me/' + d.bot_username
              + '" target="_blank" rel="noopener">@' + d.bot_username + '</a>';
          } else if (d.status === 'error') {
            bState.textContent = 'Ошибка подключения: ' + (d.last_error || '');
          } else {
            bState.textContent = 'Бот не подключён.';
          }
        }
        if (bDisconnect) bDisconnect.hidden = !d.has_token;
        if (bEmbed) bEmbed.hidden = !d.has_token;
      }

      function boxPost(action, status, done) {
        var body = boxFields();
        body.action = action;
        if (action === 'connect' && bToken) body.bot_token = bToken.value.trim();
        bBusy = true;
        fetch(gaKB.rest + 'tenant', {
          method: 'POST', credentials: 'same-origin',
          headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': gaKB.nonce },
          body: JSON.stringify(body)
        })
          .then(function (r) { return r.json().then(function (d) { return { s: r.status, d: d }; }); })
          .then(function (res) {
            var d = res.d || {};
            if (d.ok) {
              applyTenant(d);
              setStatus(status, done || 'Готово.', 'ok');
              if (action === 'connect' && bToken) bToken.value = '';
            } else {
              setStatus(status, d.error || 'Не удалось. Попробуйте ещё раз.', 'err');
            }
          })
          .catch(function () { setStatus(status, 'Сеть не отвечает.', 'err'); })
          .finally(function () { bBusy = false; });
      }

      if (bSave) bSave.addEventListener('click', function () {
        if (bBusy) return; setStatus(bStatus, 'Сохраняем…', 'load');
        boxPost('save', bStatus, 'Брендинг сохранён.');
      });
      if (bConnect) bConnect.addEventListener('click', function () {
        if (bBusy) return;
        if (bToken && !bToken.value.trim()) { setStatus(bBotStatus, 'Вставьте токен бота от @BotFather.', 'err'); return; }
        setStatus(bBotStatus, 'Подключаем бота…', 'load');
        boxPost('connect', bBotStatus, 'Бот подключён.');
      });
      if (bDisconnect) bDisconnect.addEventListener('click', function () {
        if (bBusy) return; setStatus(bBotStatus, 'Отключаем…', 'load');
        boxPost('disconnect', bBotStatus, 'Бот отключён.');
      });
    }

    if (cta) {
      cta.addEventListener('click', function () {
        cta.disabled = true;
        setStatus(ctaStatus, 'Отправляем заявку…', 'load');
        var companyEl = root.querySelector('.ga-kbform__company');
        var company = companyEl ? companyEl.value.trim() : '';
        fetch(gaKB.rest + 'support', {
          method: 'POST', credentials: 'same-origin',
          headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': gaKB.nonce },
          body: JSON.stringify({
            slug: 'biznes',
            message: 'Заявка на коробку: свой бот + база знаний + брендинг для сайта и Telegram.'
              + (company ? ' Компания: ' + company + '.' : ''),
            contact: '',
            page: location.href
          })
        })
          .then(function (r) { return r.json(); })
          .then(function (d) {
            setStatus(ctaStatus, d && d.ok ? 'Заявка отправлена — свяжемся с вами.'
              : (d && d.error) || 'Не удалось отправить заявку.', d && d.ok ? 'ok' : 'err');
            if (!(d && d.ok)) cta.disabled = false;
          })
          .catch(function () { setStatus(ctaStatus, 'Сеть не отвечает.', 'err'); cta.disabled = false; });
      });
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
