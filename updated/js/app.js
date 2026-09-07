(function () {
  'use strict';

  var yearEl = document.getElementById('year');
  if (yearEl) yearEl.textContent = new Date().getFullYear();

  function maskPhone(input) {
    if (!input || input.dataset.masked) return;
    input.dataset.masked = '1';
    input.addEventListener('input', function (e) {
      var val = e.target.value.replace(/\D/g, '');
      if (val.startsWith('8')) val = '7' + val.slice(1);
      if (val.length && !val.startsWith('7')) val = '7' + val;
      var out = '';
      if (val.length) out = '+7';
      if (val.length > 1) out += ' (' + val.slice(1, 4);
      if (val.length >= 4) out += ') ' + val.slice(4, 7);
      if (val.length >= 7) out += '-' + val.slice(7, 9);
      if (val.length >= 9) out += '-' + val.slice(9, 11);
      e.target.value = out;
    });
  }
  window.maskPhoneInput = maskPhone;

  ['phone', 'callbackPhone', 'bookPhone', 'callbackModalPhone', 'carBookPhone', 'buyoutPhone'].forEach(function (id) {
    maskPhone(document.getElementById(id));
  });

  /* Mobile menu */
  var burger = document.getElementById('burger');
  var nav = document.getElementById('nav');
  if (burger && nav) {
    burger.addEventListener('click', function () {
      var open = nav.classList.toggle('open');
      burger.setAttribute('aria-expanded', open);
    });
    nav.querySelectorAll('.nav__link').forEach(function (link) {
      link.addEventListener('click', function () {
        nav.classList.remove('open');
        burger.setAttribute('aria-expanded', 'false');
      });
    });
  }

  function setDefaultDates(fromId, toId) {
    var from = document.getElementById(fromId);
    var to = document.getElementById(toId);
    if (!from || !to) return;
    var today = new Date();
    var tomorrow = new Date(today);
    tomorrow.setDate(tomorrow.getDate() + 1);
    var fmt = function (d) { return d.toISOString().split('T')[0]; };
    from.min = fmt(today);
    from.value = fmt(today);
    to.min = fmt(tomorrow);
    to.value = fmt(tomorrow);
    from.addEventListener('change', function () {
      var next = new Date(from.value);
      next.setDate(next.getDate() + 1);
      to.min = fmt(next);
      if (new Date(to.value) <= new Date(from.value)) to.value = fmt(next);
    });
  }
  setDefaultDates('dateFrom', 'dateTo');

  /* Catalog filters */
  var grid = document.getElementById('carsGrid');
  var priceFilter = document.getElementById('priceFilter');
  var priceOutput = document.getElementById('priceOutput');
  var transFilter = document.getElementById('transFilter');
  var sortSelect = document.getElementById('sortCars');
  var resetBtn = document.getElementById('resetFilter');
  var carCount = document.getElementById('carCount');

  if (grid && priceFilter) {
    var cards = Array.from(grid.querySelectorAll('.car-card'));

    function fmt(n) {
      return n.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
    }

    function apply() {
      var maxP = parseInt(priceFilter.value, 10);
      var trans = transFilter ? transFilter.value : '';
      var visible = 0;

      cards.forEach(function (card) {
        var price = parseInt(card.dataset.price, 10);
        var cardTrans = card.dataset.trans || '';
        var show = price <= maxP && (!trans || cardTrans.indexOf(trans) !== -1);
        card.style.display = show ? '' : 'none';
        if (show) visible++;
      });

      if (priceOutput) priceOutput.textContent = 'до ' + fmt(maxP) + ' ₽';
      if (carCount) carCount.textContent = visible;

      if (sortSelect) {
        var sorted = cards.filter(function (c) { return c.style.display !== 'none'; });
        sorted.sort(function (a, b) {
          var pa = parseInt(a.dataset.price, 10);
          var pb = parseInt(b.dataset.price, 10);
          var oa = parseInt(a.dataset.order, 10);
          var ob = parseInt(b.dataset.order, 10);
          var na = a.querySelector('h3').textContent;
          var nb = b.querySelector('h3').textContent;
          if (sortSelect.value === 'catalog') return oa - ob;
          if (sortSelect.value === 'price-desc') return pb - pa;
          if (sortSelect.value === 'name') return na.localeCompare(nb, 'ru');
          return pa - pb;
        });
        sorted.forEach(function (c) { grid.appendChild(c); });
      }
    }

    priceFilter.addEventListener('input', apply);
    if (transFilter) transFilter.addEventListener('change', apply);
    if (sortSelect) sortSelect.addEventListener('change', apply);
    if (resetBtn) {
      resetBtn.addEventListener('click', function () {
        priceFilter.value = priceFilter.max;
        if (transFilter) transFilter.value = '';
        if (sortSelect) sortSelect.value = 'catalog';
        apply();
      });
    }
    apply();

    /* --- Живые цены каталога из админки WordPress ------------------------
     * Обновляет цену «от N ₽/сут» на карточках из ACF (базовый сезон,
     * тариф 16-30 суток) через REST-эндпоинт темы, затем пересортировывает.
     * ------------------------------------------------------------------ */
    if (window.fetch) {
      window.fetch('/wp-json/grandauto/v1/prices', { credentials: 'omit' })
        .then(function (r) { return r.ok ? r.json() : null; })
        .then(function (data) {
          if (!data || !data.cars) return;
          var bySlug = {};
          data.cars.forEach(function (c) { bySlug[c.slug] = c; });

          var maxPrice = 0;
          cards.forEach(function (card) {
            var link = card.querySelector('a.car-card__link') || card.querySelector('a');
            if (!link) return;
            var m = (link.getAttribute('href') || '').match(/car\/([^\/.]+)\.html/);
            if (!m) return;
            var c = bySlug[m[1]];
            if (!c) return;

            // Цена «от N ₽/сут» = базовый сезон, 16-30 суток
            if (c.base && c.base['16_30'] != null) {
              var p = Math.round(c.base['16_30']);
              card.dataset.price = String(p);
              var strong = card.querySelector('.car-card__price strong');
              if (strong) strong.textContent = fmt(p);
              if (p > maxPrice) maxPrice = p;
            }

            // Остальные данные карточки из админки
            var info = c.info;
            if (!info) return;
            var title = info.title || '';
            var year = info.year != null ? String(info.year) : '';

            // Заголовок + год
            var h3 = card.querySelector('h3');
            if (h3 && title) {
              h3.textContent = title + ' ';
              if (year) {
                var ys = document.createElement('span');
                ys.className = 'car-card__year';
                ys.textContent = year;
                h3.appendChild(ys);
              }
            }

            // Строка характеристик: «год · коробка · привод»
            var specs = card.querySelector('.car-card__specs');
            if (specs) {
              var parts = [year, info.transmission, info.wd].filter(function (x) { return x; });
              specs.textContent = parts.join(' · ');
            }

            // Фильтр по коробке передач
            if (info.transmission) card.dataset.trans = info.transmission;

            // Alt изображения
            var img = card.querySelector('img');
            if (img && title) img.setAttribute('alt', title);

            // Бейдж КАСКО
            var badge = card.querySelector('.car-card__badge.badge--casco');
            if (info.casco) {
              if (!badge) {
                var imgBox = card.querySelector('.car-card__img');
                if (imgBox) {
                  badge = document.createElement('span');
                  badge.className = 'car-card__badge badge--casco';
                  badge.textContent = 'КАСКО';
                  imgBox.insertBefore(badge, imgBox.firstChild);
                }
              } else {
                badge.style.display = '';
              }
            } else if (badge) {
              badge.style.display = 'none';
            }
          });

          if (maxPrice && parseInt(priceFilter.max, 10) < maxPrice) {
            var atMax = parseInt(priceFilter.value, 10) >= parseInt(priceFilter.max, 10);
            priceFilter.max = String(maxPrice);
            if (atMax) priceFilter.value = String(maxPrice);
          }
          apply();
        })
        .catch(function () {});
    }
  }

  /* Gallery (catalog pages without car-nav) */
  var galleryMain = document.getElementById('galleryMain');
  var galleryThumbs = document.getElementById('galleryThumbs');
  if (galleryMain && galleryThumbs && !document.querySelector('.dyncontent')) {
    var slides = galleryMain.querySelectorAll('.gallery__slide');
    galleryThumbs.querySelectorAll('.gallery__thumb').forEach(function (thumb) {
      thumb.addEventListener('click', function () {
        var idx = parseInt(thumb.dataset.index, 10);
        slides.forEach(function (s, i) { s.classList.toggle('is-active', i === idx); });
        galleryThumbs.querySelectorAll('.gallery__thumb').forEach(function (t, i) {
          t.classList.toggle('is-active', i === idx);
        });
      });
    });
  }
})();
