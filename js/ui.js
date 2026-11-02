// Shared UI: user dropdown + Settings (theme) popup
(function () {
  const $ = (id) => document.getElementById(id);
  const userBtn = $('userBtn'), menu = $('userDropdown');

  function setMenu(open) {
    if (!menu) return;
    menu.classList.toggle('show', open);
    if (userBtn) userBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
  }
  if (userBtn) userBtn.addEventListener('click', (e) => { e.stopPropagation(); setMenu(!menu.classList.contains('show')); });
  document.addEventListener('click', (e) => { if (!e.target.closest('.user-menu-wrap')) setMenu(false); });

  // ---- Settings popup ----
  const modal = $('settingsModal');
  if (!modal) return;
  const msg = $('themeMsg');
  const csrf = (document.querySelector('meta[name="csrf"]') || {}).content || '';
  const open = () => { setMenu(false); modal.classList.add('show'); modal.setAttribute('aria-hidden', 'false'); };
  const close = () => { modal.classList.remove('show'); modal.setAttribute('aria-hidden', 'true'); };
  $('openSettings').addEventListener('click', open);
  $('closeSettings').addEventListener('click', close);
  $('doneSettings').addEventListener('click', close);
  modal.addEventListener('mousedown', (e) => { if (e.target === modal) close(); });
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape') close(); });

  async function save(params) {
    msg.textContent = 'Saving…';
    try {
      const body = new URLSearchParams({ ...params, csrf });
      const r = await fetch('set_theme.php', { method: 'POST', body, credentials: 'same-origin' });
      const j = await r.json();
      msg.textContent = j.ok ? 'Saved ✓' : 'Could not save.';
    } catch (e) {
      msg.textContent = 'Could not save.';
    }
  }
  function mark(selector, active) {
    document.querySelectorAll(selector).forEach((b) => {
      const on = b === active;
      b.classList.toggle('selected', on);
      b.setAttribute('aria-checked', on ? 'true' : 'false');
    });
  }

  document.querySelectorAll('[data-theme-choice]').forEach((btn) => {
    btn.addEventListener('click', () => {
      const theme = btn.dataset.themeChoice;
      document.documentElement.setAttribute('data-theme', theme);   // instant preview
      if (window.syncFrameTheme) window.syncFrameTheme();
      mark('[data-theme-choice]', btn);
      save({ theme });
    });
  });
  document.querySelectorAll('[data-font-choice]').forEach((btn) => {
    btn.addEventListener('click', () => {
      const font = btn.dataset.fontChoice;
      document.documentElement.setAttribute('data-font', font);
      if (window.syncFrameTheme) window.syncFrameTheme();
      mark('[data-font-choice]', btn);
      save({ font });
    });
  });
})();
