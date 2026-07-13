<?php
/**
 * OrbitHost — Domain search inside the client portal.
 * Uses the same live availability API as the public site; results add
 * straight to the domain cart so checkout never leaves the portal.
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once dirname(__DIR__) . '/admin/includes/functions.php';

portal_check();
$page_title = 'Find a Domain';
$cart_count = count($_SESSION['cart_domains'] ?? []);

require_once __DIR__ . '/includes/header.php';
?>

<div class="page-hero">
  <div class="container">
    <div>
      <h1>Find Your Perfect Domain</h1>
      <p>Search, register and manage — all from your portal</p>
    </div>
    <a href="<?php echo PORTAL_URL; ?>/cart.php" class="btn btn-white">
      <i class="fas fa-cart-shopping"></i> Cart<?php echo $cart_count ? ' (' . $cart_count . ')' : ''; ?>
    </a>
  </div>
</div>

<div class="page-body">
<div class="container" style="max-width:860px">

  <div class="p-card" style="padding:26px">
    <form id="dsForm" style="display:flex;gap:10px" onsubmit="return false">
      <input type="text" id="dsQuery" class="form-control" placeholder="Type a domain name, e.g. mybusiness or mybusiness.co.ke"
             style="flex:1;padding:13px 16px;border:2px solid var(--border);border-radius:10px;font-size:15px" autofocus />
      <button type="submit" id="dsBtn" class="btn btn-primary" style="padding:13px 22px;font-weight:700"><i class="fas fa-magnifying-glass"></i> Search</button>
    </form>
    <div id="dsStatus" style="margin-top:14px;font-size:13px;color:var(--text-muted);display:none"></div>
    <div id="dsResults" style="margin-top:6px"></div>
  </div>

  <p style="text-align:center;font-size:12.5px;color:var(--text-muted);margin-top:16px">
    Already own a domain elsewhere? <a href="<?php echo PORTAL_URL; ?>/domain-transfer.php" style="color:var(--green);font-weight:600">Transfer it to OrbitHost</a>
  </p>
</div>
</div>

<script>
(function () {
  var API  = <?php echo json_encode(preg_replace('#/portal/?$#', '', PORTAL_URL) . '/api/domain-check.php'); ?>;
  var CART = <?php echo json_encode(PORTAL_URL . '/cart.php'); ?>;
  var form = document.getElementById('dsForm'), q = document.getElementById('dsQuery'),
      btn  = document.getElementById('dsBtn'), status = document.getElementById('dsStatus'),
      out  = document.getElementById('dsResults');

  function esc(s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; }

  form.addEventListener('submit', function () {
    var term = q.value.trim();
    if (term.length < 2) return;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Searching…';
    status.style.display = 'block';
    status.textContent = 'Checking availability…';
    out.innerHTML = '';

    fetch(API + '?q=' + encodeURIComponent(term))
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d.ok) { status.textContent = d.error || 'Search failed — try again.'; return; }
        status.textContent = d.live ? 'Live availability from the registrar:' : 'Prices shown — availability will be confirmed at checkout:';
        out.innerHTML = d.results.map(function (r) {
          var state, action;
          if (r.available === true) {
            state  = '<span style="color:var(--green);font-weight:700;font-size:12.5px"><i class="fas fa-circle-check"></i> Available</span>';
            action = '<a href="' + CART + '?add=' + encodeURIComponent(r.domain) + '" class="btn btn-primary btn-sm" style="white-space:nowrap"><i class="fas fa-cart-plus"></i> Add to Cart</a>';
          } else if (r.available === false) {
            state  = '<span style="color:var(--danger);font-weight:600;font-size:12.5px"><i class="fas fa-circle-xmark"></i> Taken</span>';
            action = '';
          } else {
            state  = '<span style="color:var(--text-muted);font-size:12.5px"><i class="fas fa-circle-question"></i> Check at order</span>';
            action = '<a href="' + CART + '?add=' + encodeURIComponent(r.domain) + '" class="btn btn-ghost btn-sm" style="border:1px solid var(--border);white-space:nowrap">Add anyway</a>';
          }
          return '<div style="display:flex;align-items:center;gap:14px;padding:13px 4px;border-bottom:1px solid var(--border)">' +
            '<span style="flex:1;font-family:ui-monospace,Menlo,monospace;font-weight:700;color:var(--navy);word-break:break-all">' + esc(r.domain) + '</span>' +
            state +
            '<span style="font-weight:700;white-space:nowrap">' + esc(r.currency) + ' ' + Number(r.price).toFixed(2) + '<span style="font-size:11px;color:var(--text-muted);font-weight:500">/yr</span></span>' +
            action + '</div>';
        }).join('');
      })
      .catch(function () { status.textContent = 'Connection problem — please try again.'; })
      .finally(function () { btn.disabled = false; btn.innerHTML = '<i class="fas fa-magnifying-glass"></i> Search'; });
  });
})();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
