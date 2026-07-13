/* OrbitHost Admin Panel — JavaScript */

document.addEventListener('DOMContentLoaded', function () {

  // ── Sidebar mobile toggle ────────────────────────────────
  var toggle  = document.getElementById('sidebarToggle');
  var sidebar = document.getElementById('sidebar');
  var overlay;

  function openSidebar() {
    sidebar.classList.add('open');
    overlay = document.createElement('div');
    overlay.className = 'sidebar-overlay';
    document.body.appendChild(overlay);
    overlay.addEventListener('click', closeSidebar);
  }

  function closeSidebar() {
    sidebar.classList.remove('open');
    if (overlay && overlay.parentNode) overlay.parentNode.removeChild(overlay);
    overlay = null;
  }

  if (toggle && sidebar) {
    toggle.addEventListener('click', function (e) {
      e.stopPropagation();
      sidebar.classList.contains('open') ? closeSidebar() : openSidebar();
    });
  }

  // ── Auto-dismiss alerts after 5 s ───────────────────────
  document.querySelectorAll('.alert').forEach(function (el) {
    setTimeout(function () {
      el.style.transition = 'opacity .5s';
      el.style.opacity = '0';
      setTimeout(function () { el.style.display = 'none'; }, 500);
    }, 5000);
  });

  // ── Confirm dialogs ──────────────────────────────────────
  document.querySelectorAll('[data-confirm]').forEach(function (el) {
    el.addEventListener('click', function (e) {
      var msg = el.getAttribute('data-confirm') || 'Are you sure? This action cannot be undone.';
      if (!confirm(msg)) e.preventDefault();
    });
  });

  // ── Tab system ───────────────────────────────────────────
  var hash = window.location.hash;
  document.querySelectorAll('.tabs').forEach(function (tabs) {
    tabs.querySelectorAll('.tab-link').forEach(function (link) {
      link.addEventListener('click', function (e) {
        e.preventDefault();
        var target = link.getAttribute('href');
        tabs.querySelectorAll('.tab-link').forEach(function (l) { l.classList.remove('active'); });
        link.classList.add('active');
        var panes = link.closest('.page-content') || document;
        panes.querySelectorAll('.tab-pane').forEach(function (p) { p.classList.remove('active'); });
        var pane = document.querySelector(target);
        if (pane) pane.classList.add('active');
        history.replaceState(null, '', target);
      });
    });

    // Activate from URL hash
    if (hash) {
      var active = tabs.querySelector('.tab-link[href="' + hash + '"]');
      if (active) active.click();
    }
  });

  // ── Invoice line items ───────────────────────────────────
  var addBtn = document.getElementById('addLineItem');
  var itemsBody = document.getElementById('itemsBody');

  if (addBtn && itemsBody) {
    var rowIndex = itemsBody.querySelectorAll('tr').length;

    addBtn.addEventListener('click', function () {
      rowIndex++;
      var tr = document.createElement('tr');
      tr.innerHTML =
        '<td><input type="text" name="items[' + rowIndex + '][description]" class="form-control" placeholder="Description…" required /></td>' +
        '<td style="width:80px"><input type="number" name="items[' + rowIndex + '][quantity]" class="form-control" value="1" min="1" /></td>' +
        '<td style="width:120px"><input type="number" name="items[' + rowIndex + '][unit_price]" class="form-control item-price" step="0.01" placeholder="0.00" /></td>' +
        '<td class="item-total" style="width:100px;text-align:right;font-weight:600">0.00</td>' +
        '<td style="width:40px"><button type="button" class="btn btn-ghost btn-xs remove-row" title="Remove">×</button></td>';
      itemsBody.appendChild(tr);
      bindRow(tr);
      calcTotals();
    });

    itemsBody.querySelectorAll('tr').forEach(bindRow);
  }

  function bindRow(row) {
    var qtyEl   = row.querySelector('input[name*="[quantity]"]');
    var priceEl = row.querySelector('input[name*="[unit_price]"]');
    var remBtn  = row.querySelector('.remove-row');

    function rowTotal() {
      var q = parseFloat(qtyEl ? qtyEl.value : 0) || 0;
      var p = parseFloat(priceEl ? priceEl.value : 0) || 0;
      var t = q * p;
      var cell = row.querySelector('.item-total');
      if (cell) cell.textContent = t.toFixed(2);
      calcTotals();
    }

    if (qtyEl)   qtyEl.addEventListener('input', rowTotal);
    if (priceEl) priceEl.addEventListener('input', rowTotal);
    if (remBtn)  remBtn.addEventListener('click', function () { row.remove(); calcTotals(); });
  }

  function calcTotals() {
    var sub = 0;
    document.querySelectorAll('.item-total').forEach(function (c) {
      sub += parseFloat(c.textContent) || 0;
    });
    var taxRateEl = document.getElementById('taxRate');
    var rate  = taxRateEl ? parseFloat(taxRateEl.value) || 0 : 0;
    var tax   = sub * (rate / 100);
    var total = sub + tax;

    setVal('displaySubtotal', sub.toFixed(2));
    setVal('displayTax',      tax.toFixed(2));
    setVal('displayTotal',    total.toFixed(2));
    setInput('hiddenSubtotal', sub.toFixed(2));
    setInput('hiddenTaxAmount', tax.toFixed(2));
    setInput('hiddenTotal',    total.toFixed(2));
  }

  function setVal(id, val)   { var el = document.getElementById(id); if (el) el.textContent = val; }
  function setInput(id, val) { var el = document.getElementById(id); if (el) el.value = val; }

  var taxRateEl = document.getElementById('taxRate');
  if (taxRateEl) { taxRateEl.addEventListener('input', calcTotals); calcTotals(); }

  // ── Slide-over drawers (event delegation — robust to any markup) ──
  //  Trigger:  [data-drawer-open="drawerId"]
  //  Elements: <div class="drawer" id="drawerId"> + <div id="drawerId-scrim">
  function closeDrawers() {
    document.querySelectorAll('.drawer.open, .drawer-scrim.open')
      .forEach(function (el) { el.classList.remove('open'); });
    document.body.style.overflow = '';
  }

  document.addEventListener('click', function (e) {
    var opener = e.target.closest ? e.target.closest('[data-drawer-open]') : null;
    if (opener) {
      e.preventDefault();
      var id = opener.getAttribute('data-drawer-open');
      var drawer = document.getElementById(id);
      var scrim  = document.getElementById(id + '-scrim');
      if (drawer) drawer.classList.add('open');
      if (scrim)  scrim.classList.add('open');
      document.body.style.overflow = 'hidden';
      return;
    }
    if ((e.target.closest && e.target.closest('[data-drawer-close]')) ||
        (e.target.classList && e.target.classList.contains('drawer-scrim'))) {
      closeDrawers();
    }
  });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeDrawers(); });

  // ── Secret field show/hide (token / API key inputs) ──────
  document.addEventListener('click', function (e) {
    var btn = e.target.closest ? e.target.closest('.affix-btn[data-toggle-secret]') : null;
    if (!btn) return;
    var wrap = btn.closest('.input-affix');
    var input = wrap ? wrap.querySelector('input') : null;
    if (!input) return;
    var show = input.type === 'password';
    input.type = show ? 'text' : 'password';
    btn.textContent = show ? 'Hide' : 'Show';
  });

  // ── "Detect my server IP" (registrar IP-whitelist helper) ──
  document.addEventListener('click', function (e) {
    var btn = e.target.closest ? e.target.closest('.ip-detect-btn') : null;
    if (!btn) return;
    var out = document.getElementById(btn.getAttribute('data-target'));
    if (!out) return;
    var original = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Detecting…';
    fetch('server-ip.php')
      .then(function (r) { return r.json(); })
      .then(function (d) {
        out.style.display = 'inline-flex';
        if (d.ok) {
          out.textContent = d.ip;
          out.title = 'Click to copy';
          out.style.cursor = 'pointer';
          out.onclick = function () { navigator.clipboard.writeText(d.ip); out.textContent = 'Copied!'; setTimeout(function () { out.textContent = d.ip; }, 1200); };
        } else {
          out.textContent = d.error || 'Could not detect IP.';
        }
      })
      .catch(function () { out.style.display = 'inline-flex'; out.textContent = 'Detection failed — try again.'; })
      .finally(function () { btn.disabled = false; btn.innerHTML = original; });
  });

  // ── Notification bell ────────────────────────────────────
  (function () {
    var toggle = document.getElementById('notifToggle');
    var dropdown = document.getElementById('notifDropdown');
    var menu = document.getElementById('notifMenu');
    if (!toggle || !dropdown) return;

    toggle.addEventListener('click', function (e) {
      e.stopPropagation();
      dropdown.classList.toggle('open');
    });
    document.addEventListener('click', function (e) {
      if (menu && !menu.contains(e.target)) dropdown.classList.remove('open');
    });

    function render(data) {
      toggle.querySelector('.dot')?.remove();
      if (data.unread > 0) {
        var dot = document.createElement('span');
        dot.className = 'dot';
        toggle.appendChild(dot);
      }
      var list = dropdown.querySelector('.notif-dd-list');
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

    // Resolve the admin app root from this script's own tag so polling works
    // reliably regardless of how deep the current admin page is nested.
    var adminScript = document.querySelector('script[src*="/js/admin.js"]');
    var appBase = adminScript ? adminScript.src.replace(/\/js\/admin\.js.*$/i, '') : '';
    var pollUrl = appBase + '/notifications/poll.php';
    function refresh() {
      fetch(pollUrl).then(function (r) { return r.json(); }).then(function (d) { if (d.ok) render(d); }).catch(function () {});
    }
    refresh();
    setInterval(refresh, 25000);
  })();

  // ── Quick status filter links ────────────────────────────
  document.querySelectorAll('[data-filter-status]').forEach(function (el) {
    el.addEventListener('click', function (e) {
      e.preventDefault();
      var form = document.querySelector('.filter-form');
      if (form) {
        var sel = form.querySelector('[name="status"]');
        if (sel) sel.value = el.getAttribute('data-filter-status');
        form.closest('form') ? form.closest('form').submit() : form.submit();
      }
    });
  });

});
