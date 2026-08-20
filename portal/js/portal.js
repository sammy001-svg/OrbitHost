/* Orbit Cloud Client Portal — JS */
document.addEventListener('DOMContentLoaded', function () {

  // Mobile nav toggle
  var toggle = document.getElementById('mobileToggle');
  var nav    = document.getElementById('portalNav');
  if (toggle && nav) {
    toggle.addEventListener('click', function () { nav.classList.toggle('open'); });
  }

  // Client dropdown
  var trigger  = document.getElementById('clientTrigger');
  var dropdown = document.getElementById('clientDropdown');
  if (trigger && dropdown) {
    trigger.addEventListener('click', function (e) {
      e.stopPropagation();
      dropdown.classList.toggle('open');
    });
    document.addEventListener('click', function () { dropdown.classList.remove('open'); });
  }

  // Notification bell
  (function () {
    var notifToggle = document.getElementById('notifToggle');
    var notifDropdown = document.getElementById('notifDropdown');
    var notifMenu = document.getElementById('notifMenu');
    if (!notifToggle || !notifDropdown) return;

    notifToggle.addEventListener('click', function (e) {
      e.stopPropagation();
      notifDropdown.classList.toggle('open');
    });
    document.addEventListener('click', function (e) {
      if (notifMenu && !notifMenu.contains(e.target)) notifDropdown.classList.remove('open');
    });

    var script = document.querySelector('script[src*="/js/portal.js"]');
    var base = script ? script.src.replace(/\/js\/portal\.js.*$/i, '') : '';
    var pollUrl = base + '/notifications-poll.php';

    function render(data) {
      var existingDot = notifToggle.querySelector('.dot');
      if (existingDot) existingDot.remove();
      if (data.unread > 0) {
        var dot = document.createElement('span');
        dot.className = 'dot';
        notifToggle.appendChild(dot);
      }
      var list = notifDropdown.querySelector('.notif-dd-list');
      if (!list) return;
      if (!data.items.length) {
        list.innerHTML = '<div class="notif-empty"><i class="fas fa-bell-slash"></i><p>No notifications yet.</p></div>';
        return;
      }
      list.innerHTML = data.items.map(function (n) {
        return '<a href="' + n.link + '" class="notif-item' + (n.is_read ? '' : ' unread') + '">' +
          '<span class="notif-item-title">' + n.title + '</span>' +
          '<span class="notif-item-msg">' + n.message + '</span>' +
          '<span class="notif-item-time">' + n.time + '</span></a>';
      }).join('');
    }

    function refresh() {
      fetch(pollUrl).then(function (r) { return r.json(); }).then(function (d) { if (d.ok) render(d); }).catch(function () {});
    }
    refresh();
    setInterval(refresh, 25000);
  })();

  // Auto-dismiss alerts
  document.querySelectorAll('.p-alert').forEach(function (el) {
    setTimeout(function () {
      el.style.transition = 'opacity .5s';
      el.style.opacity = '0';
      setTimeout(function () { el.style.display = 'none'; }, 500);
    }, 5000);
  });

  // Confirm dialogs
  document.querySelectorAll('[data-confirm]').forEach(function (el) {
    el.addEventListener('click', function (e) {
      if (!confirm(el.getAttribute('data-confirm') || 'Are you sure?')) e.preventDefault();
    });
  });

  // Password strength indicator
  var pwField = document.getElementById('new_password');
  var pwBar   = document.getElementById('strengthBar');
  if (pwField && pwBar) {
    pwField.addEventListener('input', function () {
      var v = pwField.value;
      var strength = 0;
      if (v.length >= 8) strength++;
      if (/[A-Z]/.test(v)) strength++;
      if (/[0-9]/.test(v)) strength++;
      if (/[^A-Za-z0-9]/.test(v)) strength++;
      var colors = ['#dc2626','#d97706','#d97706','#1A8A45','#1A8A45'];
      var widths = ['25%','40%','60%','80%','100%'];
      pwBar.style.width = widths[strength] || '0%';
      pwBar.style.background = colors[strength] || '#e2e8f0';
    });
  }

});

/* ── Colour theme ────────────────────────────────────────────
   Three states, cycled by the header button: follow the system
   (no cookie), force light, force dark. The cookie is what
   includes/header.php reads to stamp data-theme server-side, so
   every later page already paints in the chosen theme. */
(function () {
  var btn = document.getElementById('portalThemeToggle');
  if (!btn) return;

  var root = document.documentElement;

  function systemIsDark() {
    return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
  }

  function label(mode) {
    return mode === 'light' ? 'Light theme — click for dark'
         : mode === 'dark'  ? 'Dark theme — click to follow your system'
         : 'Following your system theme — click for light';
  }

  function icon(mode) {
    return mode === 'light' ? 'fa-sun' : mode === 'dark' ? 'fa-moon' : 'fa-circle-half-stroke';
  }

  function paint(mode) {
    var i = btn.querySelector('i');
    if (i) i.className = 'fas ' + icon(mode);
    btn.title = label(mode);
  }

  var current = root.getAttribute('data-theme') || '';
  paint(current);

  btn.addEventListener('click', function () {
    // system → light → dark → system
    var next = current === '' ? 'light' : current === 'light' ? 'dark' : '';
    current = next;

    if (next) root.setAttribute('data-theme', next);
    else root.removeAttribute('data-theme');

    document.cookie = 'orbit_portal_theme=' + next + ';path=/;max-age=' + (next ? 31536000 : 0) + ';samesite=lax';
    paint(next);

    var meta = document.querySelector('meta[name="theme-color"]');
    if (meta) {
      var dark = next === 'dark' || (next === '' && systemIsDark());
      meta.setAttribute('content', dark ? '#070d17' : '#0B1E3D');
    }
  });
})();
