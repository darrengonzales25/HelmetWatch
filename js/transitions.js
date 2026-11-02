(function () {
  var MIN_SHOW = 700;
  var LOGOUT_DELAY = 1000; // signing-out screen: 1s
  var loader = null;
  var shownAt = 0;
  var hideTimer = null;

  var logo = '<svg class="hw-loader-logo" viewBox="0 0 40 46" aria-hidden="true">'
           + '<path d="M20 2 4 8v14c0 11 7 19 16 22 9-3 16-11 16-22V8L20 2z" fill="currentColor"/>'
           + '<path d="M12 26a8 8 0 0 1 16 0z" style="fill:var(--bg)"/></svg>';

  function build() {
    var el = document.createElement('div');
    el.id = 'hw-loader';
    el.className = 'hw-loader';
    el.setAttribute('role', 'status');
    el.setAttribute('aria-live', 'polite');
    el.innerHTML = '<div class="hw-loader-box"><div class="hw-loader-scan">' + logo + '<span class="hw-scanline"></span></div>'
      + '<div class="hw-loader-name">HelmetWatch</div>'
      + '<div class="hw-loader-text"></div>'
      + '<div class="hw-loader-bar"><span></span></div></div>';
    document.body.appendChild(el);
    return el;
  }

  function show(text) {
    loader = document.getElementById('hw-loader') || build();
    loader.querySelector('.hw-loader-text').textContent = text || 'Loading…';
    shownAt = Date.now();
    clearTimeout(hideTimer);
    loader.classList.add('show');
  }

  function hide() {
    loader = loader || document.getElementById('hw-loader');
    if (!loader) return;
    var wait = Math.max(0, MIN_SHOW - (Date.now() - shownAt));
    clearTimeout(hideTimer);
    hideTimer = setTimeout(function () { loader.classList.remove('show'); }, wait);
  }

  window.hwLoader = { show: show, hide: hide };

  var pre = document.getElementById('hw-loader');
  if (pre && pre.classList.contains('show')) {
    loader = pre;
    shownAt = Date.now();
    // Keep the loading screen up until the page AND the model are ready
    var pageReady = new Promise(function (res) {
      if (document.readyState === 'complete') res();
      else window.addEventListener('load', res);
    });
    var modelReady = window.hwModelReady || Promise.resolve();
    Promise.all([pageReady, modelReady]).then(hide, hide);
    setTimeout(hide, 30000); // safety net so it never gets stuck
  }

  window.addEventListener('pageshow', function (e) {
    if (e.persisted) {
      var el = document.getElementById('hw-loader');
      if (el) el.classList.remove('show');
    }
  });

  document.addEventListener('click', function (e) {
    if (e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey) return;
    var a = e.target.closest('a[href]');
    if (!a || a.target === '_blank') return;
    var href = a.getAttribute('href');
    if (!href || href.charAt(0) === '#' || /^javascript:/i.test(href)) return;

    if (/logout/i.test(href)) {
      e.preventDefault();
      show('Signing out…');
      setTimeout(function () { window.location.href = a.href; }, LOGOUT_DELAY);
      return;
    }

    if (a.closest('.dropdown-menu')) {
      show('Loading…');
      var frame = document.getElementById('viewFrame');
      if (frame) frame.addEventListener('load', hide, { once: true });
      setTimeout(hide, 3000);
    }
  });

  var frame = document.getElementById('viewFrame');
  var live = document.getElementById('view-live');
  if (frame && live) {
    new MutationObserver(function () {
      if (frame.hidden) {
        live.classList.remove('hw-fade');
        void live.offsetWidth;
        live.classList.add('hw-fade');
      }
    }).observe(frame, { attributes: true, attributeFilter: ['hidden'] });
    live.addEventListener('animationend', function (e) {
      if (e.target === live) live.classList.remove('hw-fade');
    });
  }
})();
