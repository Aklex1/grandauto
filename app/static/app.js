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

  document.querySelectorAll('[data-voice-preview]').forEach(sel => {
    sel.addEventListener('change', () => {
      const opt = sel.options[sel.selectedIndex];
      const url = opt.getAttribute('data-preview');
      const player = document.getElementById('voice-preview');
      if (url && player) { player.src = url; player.play().catch(() => {}); }
    });
  });
})();
