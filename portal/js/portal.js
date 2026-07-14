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
