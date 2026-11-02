// Keeps the dashboard (camera + AI model) alive while you open Violations / Admin pages.
// Those pages open inside an iframe, so the model is loaded only once per login.
(function () {
  const live = document.getElementById('view-live');
  const frame = document.getElementById('viewFrame');
  if (!live || !frame) return;                       // not the dashboard page

  const urls = { violations: 'violations.php', admin: 'admin_logs.php' };
  const navItems = Array.from(document.querySelectorAll('.nav-item[data-view]'));
  const content = document.querySelector('.content');
  let current = 'live';

  function setHeading(title, sub) {
    document.querySelectorAll('.topbar-title h1, .page-head h1').forEach((e) => { e.textContent = title; });
    document.querySelectorAll('.topbar-title p, .page-head p').forEach((e) => { e.textContent = sub || ''; });
    document.title = title + ' — HelmetWatch';
  }

  function syncFrameTheme() {
    try {
      const d = frame.contentDocument && frame.contentDocument.documentElement;
      if (!d) return;
      d.setAttribute('data-theme', document.documentElement.getAttribute('data-theme'));
      d.setAttribute('data-font', document.documentElement.getAttribute('data-font'));
    } catch (e) { /* ignore */ }
  }
  window.syncFrameTheme = syncFrameTheme;
  frame.addEventListener('load', syncFrameTheme);

  // Refresh the counters / recent captures when you come back to the dashboard
  async function refreshLive() {
    try {
      const r = await fetch('app.php', { credentials: 'same-origin', cache: 'no-store' });
      const doc = new DOMParser().parseFromString(await r.text(), 'text/html');
      ['statusStrip', 'recentBlock'].forEach((id) => {
        const fresh = doc.getElementById(id), mine = document.getElementById(id);
        if (fresh && mine) mine.innerHTML = fresh.innerHTML;
      });
    } catch (e) { /* ignore */ }
  }

  // replace() instead of src=... so opening a page in the frame does not add extra Back-button steps
  function loadFrame(url) {
    try { frame.contentWindow.location.replace(url); } catch (e) { frame.src = url; }
  }

  function show(view, push) {
    if (view !== 'live' && !urls[view]) view = 'live';
    const item = navItems.find((n) => n.dataset.view === view);
    if (view !== 'live' && !item) view = 'live';      // e.g. a non-admin opening #admin
    const target = navItems.find((n) => n.dataset.view === view);
    const wasLive = current === 'live';

    navItems.forEach((n) => n.classList.toggle('active', n === target));
    if (target) setHeading(target.dataset.title, target.dataset.sub);

    if (view === 'live') {
      frame.hidden = true;
      loadFrame('about:blank');
      live.classList.remove('view-away');
      content.classList.remove('frame-mode');
      document.body.classList.remove('frame-mode');
      if (!wasLive) refreshLive();
    } else {
      // The dashboard is only moved out of sight, so the camera and the model keep running.
      live.classList.add('view-away');
      content.classList.add('frame-mode');
      document.body.classList.add('frame-mode');
      frame.hidden = false;
      loadFrame(urls[view] + '?embed=1');
    }
    current = view;
    if (push && location.hash !== '#' + view) history.pushState(null, '', '#' + view);
    window.scrollTo(0, 0);
  }

  // Menu links and any link to those pages (e.g. "Recent violations")
  document.addEventListener('click', (e) => {
    const a = e.target.closest('a[href]');
    if (!a || e.ctrlKey || e.metaKey || e.shiftKey || a.target === '_blank') return;
    const file = (a.getAttribute('href') || '').split(/[?#]/)[0];
    const map = { 'app.php': 'live', 'violations.php': 'violations', 'admin_logs.php': 'admin' };
    if (!(file in map)) return;
    e.preventDefault();
    show(map[file], true);
  });
  window.addEventListener('popstate', () => show((location.hash || '#live').slice(1), false));

  show((location.hash || '#live').slice(1), false);
})();
