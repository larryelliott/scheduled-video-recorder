// Small enhancements only: live status, countdowns, preview editing, confirm prompts, copy/share.
(function () {
  var base = (document.querySelector('meta[name=base]') || {}).content || '';

  // Notices ("Saved.") fade away by themselves; tapping one dismisses it.
  function dismiss(el) {
    el.classList.add('hide');
    setTimeout(function () { if (el.parentNode) { el.parentNode.removeChild(el); } }, 450);
  }
  document.querySelectorAll('[data-autoclose]').forEach(function (el) {
    setTimeout(function () { dismiss(el); }, parseInt(el.getAttribute('data-autoclose'), 10) || 8000);
    el.addEventListener('click', function () { dismiss(el); });
  });

  // Service page: refresh the status, video, times and recording sections every 10 s (the edit forms are left alone).
  var live = document.querySelector('[data-live-url]');
  if (live) {
    var last = {};
    setInterval(function () {
      if (document.hidden) { return; }
      fetch(live.getAttribute('data-live-url'), { credentials: 'same-origin', cache: 'no-store' })
        .then(function (r) { return r.ok ? r.json() : null; })
        .then(function (d) {
          if (!d) { return; }
          ['head', 'video', 'cut', 'rec'].forEach(function (k) {
            var el = document.getElementById('svc-' + k);
            if (el && typeof d[k] === 'string' && last[k] !== d[k]) { last[k] = d[k]; el.innerHTML = d[k]; }
          });
        })
        .catch(function () {});
    }, 10000);
  }

  // Ask before risky actions.
  document.addEventListener('submit', function (e) {
    var msg = e.target.getAttribute && e.target.getAttribute('data-confirm');
    if (msg && !window.confirm(msg)) { e.preventDefault(); }
  });

  // Refresh the status card every 10 s while the page is visible.
  var card = document.getElementById('status-wrap');
  if (card) {
    setInterval(function () {
      if (document.hidden) { return; }
      fetch(base + '/api/status-card', { credentials: 'same-origin', cache: 'no-store' })
        .then(function (r) { return r.ok ? r.text() : null; })
        .then(function (html) { if (html) { card.innerHTML = html; } })
        .catch(function () {});
    }, 10000);
  }

  // "in 2 h 14 min" countdowns.
  function tick() {
    document.querySelectorAll('[data-countdown]').forEach(function (el) {
      var s = parseInt(el.getAttribute('data-countdown'), 10) - Math.floor(Date.now() / 1000);
      if (s <= 0) { el.textContent = 'Recording window is open.'; return; }
      var d = Math.floor(s / 86400), h = Math.floor((s % 86400) / 3600), m = Math.floor((s % 3600) / 60);
      el.textContent = 'Starts in ' + (d ? d + ' d ' : '') + (h ? h + ' h ' : '') + m + ' min.';
    });
  }
  tick(); setInterval(tick, 30000);

  // Live intro preview while editing.
  document.querySelectorAll('form[data-live]').forEach(function (form) {
    form.addEventListener('input', function (e) {
      var f = e.target.getAttribute('data-f');
      if (!f) { return; }
      var t = document.querySelector('.intro [data-f="' + f + '"]');
      if (t) { t.textContent = e.target.value || e.target.placeholder || ''; }
    });
  });

  // Copy / share a one-time sign-in link.
  document.querySelectorAll('[data-copy]').forEach(function (b) {
    b.addEventListener('click', function () {
      var text = document.querySelector(b.getAttribute('data-copy')).textContent;
      (navigator.clipboard ? navigator.clipboard.writeText(text) : Promise.reject()).then(function () { b.textContent = 'Copied'; }, function () { window.prompt('Copy this link', text); });
    });
  });
  document.querySelectorAll('[data-share]').forEach(function (b) {
    if (!navigator.share) { b.hidden = true; return; }
    b.addEventListener('click', function () {
      navigator.share({ title: 'Sermon video sign-in link', url: document.querySelector(b.getAttribute('data-share')).textContent });
    });
  });
})();
