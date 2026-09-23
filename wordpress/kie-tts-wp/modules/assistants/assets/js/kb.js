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
