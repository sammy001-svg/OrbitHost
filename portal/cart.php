<?php
/**
 * Orbit Cloud — Shopping Cart.
 *
 * One cart for everything a visitor is buying: a hosting package (built
 * in portal/order/) plus any domains — registrations or transfers — added
 * from the website's domain search or the box on this page. Works before
 * sign-in; the account is created (or signed into) at checkout.
 *
 *   GET  ?add=domain.tld          add a registration (availability re-checked)
 *   GET  ?add=domain.tld&transfer=1   add a transfer instead
 *   GET  ?remove=domain.tld       remove a domain
 *   GET  ?remove_hosting=1        remove the hosting package
 *   POST action=update|checkout|promo|add|empty
 */
require_once __DIR__ . '/order/_layout.php';

portal_start();
Currency::ensureSchema();
$currency = Currency::current();
$notice = ''; $notice_kind = 'info';
$self = PORTAL_URL . '/cart.php';

/** Add one domain, returning an error message or ''. */
function cart_add_domain(string $raw, bool $transfer, string $epp = ''): string
{
    $d = OrderCart::cleanDomain($raw);
    if ($d === '') return 'Enter a full domain name, like yourbusiness.co.ke.';
    $tld = explode('.', $d, 2)[1];
    if (!OrderCart::tldPricing($tld)) return 'We don\'t sell .' . $tld . ' domains online yet — contact us and we\'ll help.';
    if (!$transfer && OrderCart::domainTaken($d) === true) {
        return $d . ' is already registered. If it\'s yours, add it as a transfer instead.';
    }
    if ($transfer && OrderCart::domainTaken($d) === false) {
        return $d . ' isn\'t registered yet, so there\'s nothing to transfer — register it instead.';
    }
    OrderCart::putDomain($d, ['mode' => $transfer ? 'transfer' : 'register', 'epp' => $epp]);
    return '';
}

// ── GET actions ──
if (isset($_GET['add'])) {
    $err = cart_add_domain((string) $_GET['add'], !empty($_GET['transfer']));
    if ($err === '') { header('Location: ' . $self . '?added=' . urlencode(OrderCart::cleanDomain((string) $_GET['add']))); exit; }
    $notice = $err; $notice_kind = 'error';
}
if (isset($_GET['remove'])) {
    OrderCart::removeDomain(strtolower(trim((string) $_GET['remove'])));
    header('Location: ' . $self); exit;
}
if (isset($_GET['remove_hosting'])) {
    OrderCart::clearHosting();
    header('Location: ' . $self); exit;
}
if (isset($_GET['added'])) {
    $notice = OrderCart::cleanDomain((string) $_GET['added']) . ' was added to your cart.'; $notice_kind = 'success';
}

// ── POST actions ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    portal_csrf_verify();
    $action = (string) ($_POST['action'] ?? 'update');

    // Every submit carries the current per-domain settings.
    foreach (OrderCart::domains() as $d => $it) {
        $k = md5($d);
        $upd = ['id_protection' => !empty($_POST['idp'][$k])];
        if ($it['mode'] === 'register' && isset($_POST['years'][$k])) $upd['years'] = max(1, min(10, (int) $_POST['years'][$k]));
        if ($it['mode'] === 'transfer' && isset($_POST['epp'][$k]))   $upd['epp']   = trim((string) $_POST['epp'][$k]);
        OrderCart::putDomain($d, $upd);
    }

    if ($action === 'empty') {
        OrderCart::clear();
        header('Location: ' . $self); exit;
    }
    if ($action === 'add') {
        $err = cart_add_domain((string) ($_POST['new_domain'] ?? ''), ($_POST['new_mode'] ?? '') === 'transfer', trim((string) ($_POST['new_epp'] ?? '')));
        if ($err === '') { header('Location: ' . $self . '?added=' . urlencode(OrderCart::cleanDomain((string) $_POST['new_domain']))); exit; }
        $notice = $err; $notice_kind = 'error';
    }
    if ($action === 'promo') {
        OrderCart::setPromo((string) ($_POST['promo'] ?? ''));
        header('Location: ' . $self); exit;
    }
    if ($action === 'remove_promo') {
        OrderCart::setPromo('');
        header('Location: ' . $self); exit;
    }
    if ($action === 'checkout') {
        $missing = [];
        foreach (OrderCart::domains() as $d => $it) {
            if ($it['mode'] === 'transfer' && strlen($it['epp']) < 4) $missing[] = $d;
        }
        if ($missing) {
            $notice = 'Enter the EPP/auth code for ' . implode(', ', $missing) . ' — the transfer can\'t start without it. Your current registrar provides it.';
            $notice_kind = 'error';
        } elseif (OrderCart::plan() && !OrderCart::hostingComplete()) {
            header('Location: ' . checkout_url('index.php')); exit;
        } elseif (OrderCart::checkoutReady()) {
            header('Location: ' . checkout_url('review.php')); exit;
        }
    }
    if ($action === 'update') { header('Location: ' . $self); exit; }
}

$plan     = OrderCart::plan();
$domains  = OrderCart::domains();
$sum      = OrderCart::summary($currency);
$billing  = SiteSettings::billing();
$idp_unit = (float) ($billing['id_protection'][$currency] ?? 0);
$site     = SiteSettings::siteRoot();
$empty    = !$plan && !$domains;

// Price per domain line, from the one summary everything is billed from.
$line_for = [];
foreach ($sum['lines'] as $l) {
    if (($l['type'] ?? '') === 'cart_domain') $line_for[$l['domain']] = (float) $l['amount'];
}

// Domain-only carts show "Cart → Checkout"; a hosting order has its own
// four package steps, which the cart sits outside of.
checkout_head('Shopping Cart', ($empty || $plan) ? 0 : 1);
?>
<style>
  .cart-item { display: flex; gap: 14px; align-items: flex-start; padding: 16px 0; border-bottom: 1px solid var(--border); }
  .cart-item:last-child { border-bottom: 0; }
  .cart-ico { width: 40px; height: 40px; border-radius: 10px; background: var(--green-light); color: var(--green); display: flex; align-items: center; justify-content: center; font-size: 17px; flex: 0 0 40px; }
  .cart-name { font-weight: 800; color: var(--text); font-size: 14.5px; word-break: break-all; }
  .cart-name.mono { font-family: ui-monospace, Menlo, monospace; }
  .cart-meta { font-size: 12.5px; color: var(--text-muted); margin-top: 3px; }
  .cart-ctl { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; margin-top: 10px; font-size: 12.5px; }
  .cart-ctl select, .cart-ctl input[type=text] { padding: 7px 9px; border: 1px solid var(--border); border-radius: 8px; font-size: 12.5px; background: var(--surface); color: var(--text); }
  .cart-price { font-weight: 800; color: var(--text); white-space: nowrap; font-size: 14px; text-align: right; }
  .cart-rm { font-size: 12px; color: var(--text-muted); text-decoration: none; display: inline-block; margin-top: 6px; }
  .cart-rm:hover { color: var(--danger); }
  .cart-badge { display: inline-block; font-size: 10.5px; font-weight: 800; letter-spacing: .3px; text-transform: uppercase; padding: 2px 7px; border-radius: 20px; background: var(--surface-2, #f1f5f9); color: var(--text-muted); margin-left: 6px; vertical-align: 2px; }
  .cart-add { display: flex; gap: 8px; flex-wrap: wrap; }
  .cart-add input[type=text] { flex: 1; min-width: 200px; }
  .cart-tabs { display: inline-flex; border: 1px solid var(--border); border-radius: 9px; overflow: hidden; margin-bottom: 12px; }
  .cart-tabs label { padding: 7px 14px; font-size: 12.5px; font-weight: 700; cursor: pointer; color: var(--text-muted); }
  .cart-tabs input { display: none; }
  .cart-tabs label:has(input:checked) { background: var(--green); color: #fff; }
  .ds-row { display: flex; align-items: center; gap: 10px; padding: 9px 0; border-bottom: 1px solid var(--border); flex-wrap: wrap; font-size: 13px; }
  .upsell { display: flex; gap: 14px; align-items: center; padding: 16px; border: 2px dashed var(--green); border-radius: var(--radius); background: var(--green-light); flex-wrap: wrap; }
  @media (max-width: 600px) { .co-bar .co-secure:last-child { display: none; } }
</style>

<?php if ($notice): ?>
  <div class="p-alert p-alert-<?php echo $notice_kind === 'error' ? 'error' : ($notice_kind === 'success' ? 'success' : 'info'); ?>" style="margin-bottom:16px">
    <i class="fas <?php echo $notice_kind === 'success' ? 'fa-circle-check' : 'fa-circle-info'; ?>"></i> <?php echo h($notice); ?>
  </div>
<?php endif; ?>

<?php if ($empty): ?>
  <div class="co-panel" style="max-width:640px;margin:20px auto;text-align:center">
    <div class="co-panel-body" style="padding:44px 26px">
      <i class="fas fa-cart-shopping" style="font-size:44px;color:var(--text-muted);opacity:.35"></i>
      <h1 style="font-size:20px;color:var(--text);margin:16px 0 6px">Your cart is empty</h1>
      <p style="color:var(--text-muted);font-size:13.5px;margin:0 0 22px">Find the perfect domain or pick a hosting plan to get started.</p>
      <form method="POST" class="cart-add" style="max-width:460px;margin:0 auto 16px">
        <input type="hidden" name="csrf_token" value="<?php echo portal_csrf(); ?>" />
        <input type="hidden" name="action" value="add" /><input type="hidden" name="new_mode" value="register" />
        <input type="text" name="new_domain" class="form-control" placeholder="yourbusiness.co.ke" autofocus />
        <button class="btn btn-primary"><i class="fas fa-magnifying-glass"></i> Find domain</button>
      </form>
      <div style="display:flex;gap:10px;justify-content:center;flex-wrap:wrap">
        <a href="<?php echo h($site); ?>/domains.html" class="btn btn-ghost" style="border:1px solid var(--border)"><i class="fas fa-globe"></i> Domains</a>
        <a href="<?php echo h($site); ?>/hosting/shared.html#plans" class="btn btn-ghost" style="border:1px solid var(--border)"><i class="fas fa-server"></i> Hosting plans</a>
      </div>
    </div>
  </div>
<?php else: ?>

<form method="POST" id="cartForm">
<input type="hidden" name="csrf_token" value="<?php echo portal_csrf(); ?>" />
<input type="hidden" name="action" id="cartAction" value="checkout" />

<div class="co-grid">
  <div>
    <div class="co-panel">
      <div class="co-panel-head" style="display:flex;justify-content:space-between;align-items:center;gap:10px">
        <div><h2>Shopping Cart</h2><p><?php echo OrderCart::count(); ?> item<?php echo OrderCart::count() === 1 ? '' : 's'; ?> · prices in <?php echo h($currency); ?></p></div>
        <button type="submit" class="co-back" style="background:none;border:0;cursor:pointer" data-act="empty" data-confirm-msg="Remove everything from your cart?"><i class="fas fa-trash-can"></i> Empty cart</button>
      </div>
      <div class="co-panel-body" style="padding-top:4px;padding-bottom:4px">

        <?php if ($plan):
          $plan_amt = Currency::planAmount($plan, $currency); ?>
          <div class="cart-item">
            <span class="cart-ico"><i class="fas fa-server"></i></span>
            <div style="flex:1;min-width:0">
              <div class="cart-name"><?php echo h($plan['name']); ?> <span class="cart-badge"><?php echo h(ucfirst($plan['category'])); ?> hosting</span></div>
              <div class="cart-meta">
                Billed <?php echo h(str_replace('_', ' ', $plan['billing_cycle'])); ?>
                <?php if (OrderCart::hostingComplete()): ?> · on <strong style="font-family:ui-monospace,Menlo,monospace"><?php echo h((string) OrderCart::get('domain_name')); ?></strong><?php endif; ?>
              </div>
              <?php if (!OrderCart::hostingComplete()): ?>
                <div class="p-alert p-alert-info" style="margin:10px 0 0;padding:8px 12px;font-size:12.5px"><i class="fas fa-circle-info"></i> Choose a domain for this package to continue.</div>
              <?php endif; ?>
              <div class="cart-ctl">
                <a href="<?php echo checkout_url(OrderCart::hostingComplete() ? 'configure.php' : 'index.php'); ?>" class="btn btn-ghost btn-sm" style="border:1px solid var(--border)"><i class="fas fa-sliders"></i> <?php echo OrderCart::hostingComplete() ? 'Edit package' : 'Finish setting up'; ?></a>
              </div>
            </div>
            <div>
              <div class="cart-price"><?php echo h(checkout_money((float) $plan_amt['price'], $currency)); ?></div>
              <a href="?remove_hosting=1" class="cart-rm"><i class="fas fa-xmark"></i> Remove</a>
            </div>
          </div>
        <?php endif; ?>

        <?php foreach ($domains as $d => $it):
          $k = md5($d);
          $priced = OrderCart::tldPricing($it['tld']) !== null; ?>
          <div class="cart-item">
            <span class="cart-ico"><i class="fas <?php echo $it['mode'] === 'transfer' ? 'fa-right-left' : 'fa-globe'; ?>"></i></span>
            <div style="flex:1;min-width:0">
              <div class="cart-name mono"><?php echo h($d); ?>
                <span class="cart-badge"><?php echo $it['mode'] === 'transfer' ? 'Transfer' : 'Registration'; ?></span>
                <?php if ($plan && OrderCart::get('domain_mode') === 'cart' && OrderCart::get('domain_name') === $d): ?><span class="cart-badge" style="background:var(--green-light);color:var(--green)">Hosting domain</span><?php endif; ?>
              </div>
              <?php if (!$priced): ?>
                <div class="cart-meta" style="color:var(--danger)">This extension is no longer sold online — remove it to continue.</div>
              <?php endif; ?>
              <div class="cart-ctl">
                <?php if ($it['mode'] === 'register'): ?>
                  <label>Register for
                    <select name="years[<?php echo $k; ?>]" data-autosave>
                      <?php for ($y = 1; $y <= 10; $y++): ?><option value="<?php echo $y; ?>" <?php echo $it['years'] === $y ? 'selected' : ''; ?>><?php echo $y; ?> year<?php echo $y > 1 ? 's' : ''; ?></option><?php endfor; ?>
                    </select></label>
                <?php else: ?>
                  <input type="text" name="epp[<?php echo $k; ?>]" value="<?php echo h($it['epp']); ?>" placeholder="EPP / auth code" autocomplete="off" style="font-family:ui-monospace,Menlo,monospace;min-width:180px" />
                  <span style="color:var(--text-muted)">Adds 1 year to the registration.</span>
                <?php endif; ?>
                <?php if ($idp_unit > 0): ?>
                  <label style="display:inline-flex;gap:6px;align-items:center;cursor:pointer">
                    <input type="checkbox" name="idp[<?php echo $k; ?>]" value="1" <?php echo $it['id_protection'] ? 'checked' : ''; ?> data-autosave />
                    ID Protection <span style="color:var(--text-muted)">(+<?php echo h(checkout_money($idp_unit, $currency)); ?>/yr)</span>
                  </label>
                <?php endif; ?>
              </div>
            </div>
            <div>
              <div class="cart-price"><?php echo $priced ? h(checkout_money($line_for[$d] ?? 0, $currency)) : '—'; ?></div>
              <a href="?remove=<?php echo urlencode($d); ?>" class="cart-rm"><i class="fas fa-xmark"></i> Remove</a>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

    <?php if (!$plan && $domains): $first = array_key_first($domains); ?>
      <div class="upsell" style="margin-top:16px">
        <i class="fas fa-rocket" style="font-size:24px;color:var(--green)"></i>
        <div style="flex:1;min-width:200px">
          <div style="font-weight:800;color:var(--text)">Put <?php echo h($first); ?> to work</div>
          <div style="font-size:12.5px;color:var(--text-muted)">Add a hosting plan and we'll set up your website and email on it — one checkout, one invoice.</div>
        </div>
        <a href="<?php echo h($site); ?>/hosting/shared.html#plans" class="btn btn-primary btn-sm"><i class="fas fa-plus"></i> Add hosting</a>
      </div>
    <?php endif; ?>

    <div class="co-panel" style="margin-top:16px">
      <div class="co-panel-head"><h2>Add another domain</h2></div>
      <div class="co-panel-body">
        <div class="cart-tabs" role="tablist">
          <label><input type="radio" name="new_mode" value="register" checked /> Register</label>
          <label><input type="radio" name="new_mode" value="transfer" /> Transfer</label>
        </div>
        <div class="cart-add">
          <input type="text" name="new_domain" id="newDomain" class="form-control" placeholder="yourbusiness.co.ke" autocomplete="off" />
          <input type="text" name="new_epp" id="newEpp" class="form-control" placeholder="EPP / auth code" style="display:none;max-width:200px" autocomplete="off" />
          <button type="button" class="btn btn-primary" id="dsBtn"><i class="fas fa-magnifying-glass"></i> Search</button>
        </div>
        <div id="dsOut"></div>
      </div>
    </div>

    <div class="co-actions">
      <a href="<?php echo h($site); ?>/index.html" class="co-back"><i class="fas fa-arrow-left"></i> Continue shopping</a>
    </div>
  </div>

  <div>
    <?php checkout_summary($sum, $currency, 'cartForm', 'Checkout'); ?>
    <div class="co-panel" style="margin-top:16px">
      <div class="co-panel-body">
        <?php if ($sum['promo_code']): ?>
          <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;font-size:13px">
            <span><i class="fas fa-tag" style="color:var(--green)"></i> <strong><?php echo h($sum['promo_code']); ?></strong> applied</span>
            <button type="submit" class="co-back" style="background:none;border:0;cursor:pointer" data-act="remove_promo">Remove</button>
          </div>
        <?php else: ?>
          <label class="form-label" for="promo" style="font-size:12.5px">Promo code</label>
          <div style="display:flex;gap:8px">
            <input type="text" id="promo" name="promo" class="form-control" style="text-transform:uppercase" value="<?php echo h((string) ($_SESSION['cart_promo'] ?? '')); ?>" />
            <button type="submit" class="btn btn-ghost" style="border:1px solid var(--border)" data-act="promo">Apply</button>
          </div>
          <?php if ($sum['promo_error']): ?><div style="font-size:12px;color:var(--danger);margin-top:6px"><?php echo h($sum['promo_error']); ?></div><?php endif; ?>
        <?php endif; ?>
      </div>
    </div>
    <p style="font-size:11.5px;color:var(--text-muted);text-align:center;margin-top:12px"><i class="fas fa-lock"></i> You'll create your client account or sign in at checkout.</p>
  </div>
</div>
</form>

<script>
(function () {
  var f = document.getElementById('cartForm'), act = document.getElementById('cartAction');
  // Buttons that set the form action, so one form drives every change.
  f.querySelectorAll('[data-act]').forEach(function (b) {
    b.addEventListener('click', function (e) {
      if (b.dataset.confirmMsg && !confirm(b.dataset.confirmMsg)) { e.preventDefault(); return; }
      act.value = b.dataset.act;
    });
  });
  document.querySelectorAll('button[form="cartForm"]').forEach(function (b) {
    b.addEventListener('click', function () { act.value = 'checkout'; });
  });
  // Changing a term or ID Protection re-prices immediately.
  f.querySelectorAll('[data-autosave]').forEach(function (el) {
    el.addEventListener('change', function () { act.value = 'update'; f.submit(); });
  });
  // Enter in the promo box applies the promo, not checkout.
  var promo = document.getElementById('promo');
  if (promo) promo.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); act.value = 'promo'; f.submit(); } });

  // ── Add another domain ──
  var input = document.getElementById('newDomain'), epp = document.getElementById('newEpp');
  var btn = document.getElementById('dsBtn'), out = document.getElementById('dsOut');
  var API = <?php echo json_encode($site . '/api/domain-check.php'); ?>;
  function mode() { return f.querySelector('input[name=new_mode]:checked').value; }
  function esc(s) { return String(s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
  function money(v, c) { return (c === 'KES' ? 'KSh ' : '$') + Number(v).toFixed(2); }
  f.querySelectorAll('input[name=new_mode]').forEach(function (r) {
    r.addEventListener('change', function () {
      var t = mode() === 'transfer';
      epp.style.display = t ? '' : 'none';
      btn.innerHTML = t ? '<i class="fas fa-plus"></i> Add transfer' : '<i class="fas fa-magnifying-glass"></i> Search';
      out.innerHTML = '';
    });
  });
  function addNow(domain) { act.value = 'add'; input.value = domain; f.submit(); }
  function search() {
    var q = input.value.trim(); if (q.length < 2) { input.focus(); return; }
    if (mode() === 'transfer') { addNow(q); return; }
    btn.disabled = true; out.innerHTML = '<div class="ds-row" style="color:var(--text-muted)"><i class="fas fa-spinner fa-spin"></i> Checking availability…</div>';
    fetch(API + '?q=' + encodeURIComponent(q)).then(function (r) { return r.json(); }).then(function (d) {
      if (!d.ok) { out.innerHTML = '<div class="ds-row" style="color:var(--text-muted)">' + esc(d.error || 'Search unavailable.') + '</div>'; return; }
      out.innerHTML = d.results.map(function (r) {
        var st = r.available === true ? '<span style="color:var(--green);font-weight:700"><i class="fas fa-circle-check"></i> Available</span>'
               : r.available === false ? '<span style="color:var(--danger);font-weight:700"><i class="fas fa-circle-xmark"></i> Taken</span>'
               : '<span style="color:var(--text-muted)">Unconfirmed</span>';
        var b = r.available === false ? '' : '<button type="button" class="btn btn-primary btn-sm" data-add="' + esc(r.domain) + '">Add to cart</button>';
        return '<div class="ds-row"><span style="flex:1;min-width:150px;font-family:ui-monospace,Menlo,monospace;font-weight:700">' + esc(r.domain) + '</span>' + st
             + '<span style="font-weight:700;min-width:90px;text-align:right">' + money(r.price, r.currency) + '/yr</span>' + b + '</div>';
      }).join('');
    }).catch(function () { out.innerHTML = '<div class="ds-row" style="color:var(--text-muted)">Could not reach the domain checker — try again.</div>'; })
      .then(function () { btn.disabled = false; });
  }
  btn.addEventListener('click', search);
  input.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); search(); } });
  out.addEventListener('click', function (e) { var b = e.target.closest('[data-add]'); if (b) addNow(b.getAttribute('data-add')); });
})();
</script>
<?php endif; ?>

<?php checkout_foot(); ?>
