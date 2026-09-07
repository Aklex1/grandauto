(function (global) {
  'use strict';

  function fmtPrice(n) {
    return n.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
  }

  function getRoot(root) {
    if (root && root.querySelector) return root;
    return document.querySelector('.dyncontent') || document;
  }

  function initTariff(root) {
    root = getRoot(root);
    var tariffButtons = root.querySelectorAll('.tariff-btn');
    var priceLists = root.querySelectorAll('.price-list');
    var tariffNotices = root.querySelectorAll('.tariff-notice');
    var priceDisplays = root.querySelectorAll('.price-display');
    var bookPriceDisplay = root.querySelector('.book-card .price-display');

    function minPriceInPanel(panel, tariff) {
      var list = panel.querySelector('.price-list[data-tariff="' + tariff + '"]');
      if (!list) return null;
      var vals = list.querySelectorAll('.price-list__val');
      var min = Infinity;
      vals.forEach(function (el) {
        var n = parseInt(el.textContent.replace(/\s/g, ''), 10);
        if (!isNaN(n) && n < min) min = n;
      });
      return min === Infinity ? null : min;
    }

    function updateBookCardPrice() {
      if (!bookPriceDisplay) return;
      var tariff = 'low';
      try { tariff = localStorage.getItem('selectedTariff') || 'low'; } catch (e) {}
      var activeTab = root.querySelector('.tab-content.active-tab') ||
        root.querySelector('.tab-content[data-tab="standard"]');
      if (!activeTab) {
        root.querySelectorAll('.tab-content').forEach(function (p) {
          if (p.style.display !== 'none') activeTab = p;
        });
      }
      if (!activeTab) return;
      var min = minPriceInPanel(activeTab, tariff);
      if (min != null) bookPriceDisplay.textContent = fmtPrice(min);
    }

    function setTariff(selected) {
      tariffButtons.forEach(function (btn) {
        var on = btn.getAttribute('data-tariff') === selected;
        btn.classList.toggle('active', on);
        btn.setAttribute('aria-checked', on ? 'true' : 'false');
      });
      tariffNotices.forEach(function (n) {
        n.classList.toggle('active', n.getAttribute('data-tariff') === selected);
      });
      priceLists.forEach(function (list) {
        var show = list.getAttribute('data-tariff') === selected;
        list.style.display = show ? 'grid' : 'none';
      });
      priceDisplays.forEach(function (el) {
        if (el.closest('.book-card')) return;
        var val = selected === 'basic' ? el.getAttribute('data-basic-price') : el.getAttribute('data-low-price');
        if (val) el.textContent = val;
      });
      try { localStorage.setItem('selectedTariff', selected); } catch (e) {}
      updateBookCardPrice();
    }

    if (!tariffButtons.length) return;
    tariffButtons.forEach(function (btn) {
      if (btn.dataset.tariffBound) return;
      btn.dataset.tariffBound = '1';
      btn.addEventListener('click', function () {
        setTariff(btn.getAttribute('data-tariff'));
      });
    });
    var saved = null;
    try { saved = localStorage.getItem('selectedTariff'); } catch (e) {}
    setTariff(saved || 'low');
    global.updateBookCardPrice = updateBookCardPrice;
  }

  function initTabs(root) {
    root = getRoot(root);
    var tabBtns = root.querySelectorAll('.tabs-nav__btn');
    var tabContents = root.querySelectorAll('.tab-content');
    if (!tabBtns.length) return;

    function setTab(tabId) {
      tabBtns.forEach(function (btn) {
        var active = btn.getAttribute('data-tab') === tabId;
        btn.classList.toggle('active', active);
        var item = btn.closest('.tabs-nav__item');
        if (item) item.classList.toggle('active', active);
      });
      tabContents.forEach(function (panel) {
        var show = panel.getAttribute('data-tab') === tabId;
        panel.style.display = show ? 'block' : 'none';
        panel.classList.toggle('active-tab', show);
      });
      if (global.updateBookCardPrice) global.updateBookCardPrice();
    }

    tabBtns.forEach(function (btn) {
      if (btn.dataset.tabBound) return;
      btn.dataset.tabBound = '1';
      btn.addEventListener('click', function () {
        setTab(btn.getAttribute('data-tab'));
      });
    });
    setTab('standard');
  }

  function initDates(root) {
    root = getRoot(root);
    var from = root.querySelector('#bookFrom');
    var to = root.querySelector('#bookTo');
    if (!from || !to || from.dataset.datesBound) return;
    from.dataset.datesBound = '1';
    var today = new Date();
    var tomorrow = new Date(today);
    tomorrow.setDate(tomorrow.getDate() + 1);
    var fmt = function (d) { return d.toISOString().split('T')[0]; };
    from.min = fmt(today);
    if (!from.value) from.value = fmt(today);
    to.min = fmt(tomorrow);
    if (!to.value) to.value = fmt(tomorrow);
    from.addEventListener('change', function () {
      var next = new Date(from.value);
      next.setDate(next.getDate() + 1);
      to.min = fmt(next);
      if (new Date(to.value) <= new Date(from.value)) to.value = fmt(next);
    });
  }

  /* --- Живые цены из админки WordPress -----------------------------------
   * Обе таблицы (базовый / низкий сезон) и все карты лояльности
   * пересчитываются из ACF-полей через REST-эндпоинт темы.
   * Базовый сезон = price_st_*, низкий = price_low_* (пусто → базовая −15%).
   * Silver / Gold — проценты скидок из настроек темы, применяются поверх.
   * -------------------------------------------------------------------- */
  var PRICES_API = '/wp-json/grandauto/v1/prices';
  var TIER_KEYS = ['1_3', '4_8', '9_15', '16_30'];

  function fmtNum(n) {
    return String(n).replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
  }

  function hydratePrices(root) {
    root = getRoot(root);
    var box = root.querySelector('.dyncontent') || document.querySelector('.dyncontent');
    if (!box || !global.fetch) return;
    var id = parseInt(box.getAttribute('data-post-id'), 10);
    var slug = box.getAttribute('data-slug');

    global.fetch(PRICES_API, { credentials: 'omit' })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (data) {
        if (!data || !data.cars) return;
        var car = null;
        for (var i = 0; i < data.cars.length; i++) {
          if ((id && data.cars[i].id === id) || (slug && data.cars[i].slug === slug)) {
            car = data.cars[i];
            break;
          }
        }
        if (!car) return;

        var factors = {
          standard: 1,
          silver: 1 - (parseFloat(data.silver) || 0) / 100,
          gold: 1 - (parseFloat(data.gold) || 0) / 100
        };

        Object.keys(factors).forEach(function (tab) {
          ['basic', 'low'].forEach(function (season) {
            var arr = season === 'basic' ? car.base : car.low;
            if (!arr) return;
            var list = root.querySelector(
              '.tab-content[data-tab="' + tab + '"] .price-list[data-tariff="' + season + '"]'
            );
            if (!list) return;
            var vals = list.querySelectorAll('.price-list__val');
            TIER_KEYS.forEach(function (tk, idx) {
              if (idx >= vals.length || arr[tk] == null) return;
              vals[idx].textContent = fmtNum(Math.floor(arr[tk] * factors[tab]));
            });
          });
        });

        if (global.updateBookCardPrice) global.updateBookCardPrice();
      })
      .catch(function () {});
  }

  function init(root) {
    root = getRoot(root);
    initTariff(root);
    initTabs(root);
    initDates(root);
    hydratePrices(root);
  }

  global.GrandAutoCarPage = { init: init };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () { init(); });
  } else {
    init();
  }
})(window);
