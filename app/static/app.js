// Автообновление статусов сборки и баланса.
(function () {
  const isVideoPage = /^\/videos\/\d+$/.test(location.pathname);

  async function tick() {
    try {
      const res = await fetch('/api/status', { credentials: 'same-origin' });
      if (!res.ok) return;
      const data = await res.json();
      const credits = document.getElementById('credits');
      if (credits && data.credits) credits.textContent = data.credits + ' cr';

      data.videos.forEach(v => {
        const bar = document.querySelector(`[data-progress-for="${v.id}"] > span`);
        if (bar) bar.style.width = v.progress + '%';
        const label = document.querySelector(`[data-stage-for="${v.id}"]`);
        if (label) label.textContent = v.stage || v.status;
      });

      if (isVideoPage) {
        const id = parseInt(location.pathname.split('/').pop(), 10);
        const active = data.videos.find(v => v.id === id);
        if (!active) { location.reload(); return; }
      }
    } catch (e) { /* сеть моргнула — повторим на следующем тике */ }
  }

  const hasActive = document.querySelector('[data-progress-for]');
  if (hasActive || isVideoPage) setInterval(tick, 5000);

  document.querySelectorAll('[data-confirm]').forEach(el => {
    el.addEventListener('submit', ev => {
      if (!confirm(el.getAttribute('data-confirm'))) ev.preventDefault();
    });
  });

  // Бета-вкладка стоков: «отметить все» и счётчик выбранных.
  document.querySelectorAll('[data-toggle-all]').forEach(master => {
    const scope = document.querySelector(master.getAttribute('data-toggle-all'));
    if (!scope) return;
    const boxes = () => scope.querySelectorAll('input[type=checkbox][name=item]');
    const counter = document.querySelector('[data-selected-count]');
    const refresh = () => {
      if (!counter) return;
      const n = scope.querySelectorAll('input[type=checkbox][name=item]:checked').length;
      counter.textContent = n;
    };
    master.addEventListener('change', () => {
      boxes().forEach(b => { b.checked = master.checked; });
      refresh();
    });
    boxes().forEach(b => b.addEventListener('change', refresh));
    refresh();
  });

  // Демо голоса. Плеер берём по имени из data-voice-preview, а если имени нет —
  // общий плеер страницы: так один обработчик обслуживает и настройки канала,
  // и блоки пересборки, где плеер свой у каждой сцены.
  function voicePlayerFor(sel) {
    const name = sel.getAttribute('data-voice-preview');
    return document.getElementById(name || 'voice-preview');
  }

  function playVoiceDemo(sel, autoplay) {
    const player = voicePlayerFor(sel);
    if (!player) return;
    const opt = sel.options[sel.selectedIndex];
    const url = opt ? opt.getAttribute('data-preview') : '';
    const note = document.querySelector(`[data-voice-note="${sel.id}"]`);
    if (!url) {
      player.removeAttribute('src');
      player.load();
      if (note) note.textContent = 'У этого голоса нет демо в каталоге KIE';
      return;
    }
    if (note) note.textContent = '';
    player.src = url;
    if (autoplay) player.play().catch(() => {});
  }

  document.querySelectorAll('[data-voice-preview]').forEach(sel => {
    sel.addEventListener('change', () => playVoiceDemo(sel, true));
    playVoiceDemo(sel, false);   // подставляем демо текущего голоса, не проигрывая
  });

  // Образец начертания: картинка рисуется на сервере тем же ffmpeg, что и титры,
  // поэтому в списке видно ровно то, что окажется в кадре.
  document.querySelectorAll('[data-font-preview]').forEach(sel => {
    const img = document.getElementById(sel.getAttribute('data-font-preview'));
    const show = () => { if (img && sel.value) img.src = `/font-preview/${sel.value}.png`; };
    sel.addEventListener('change', show);
    show();
  });

  // Кнопка «прослушать» — чтобы переслушать голос, не меняя выбор.
  document.querySelectorAll('[data-voice-play]').forEach(btn => {
    btn.addEventListener('click', ev => {
      ev.preventDefault();
      const sel = document.getElementById(btn.getAttribute('data-voice-play'));
      if (sel) playVoiceDemo(sel, true);
    });
  });
})();
