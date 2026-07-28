<?php
/**
 * Orbit Cloud — hosting checkout, step 1: the domain.
 *
 * Entered from a package CTA on the website (?plan=shared-business) or
 * from portal/order.php. The visitor picks how they'll supply a domain —
 * register a new one, transfer one in, or point one they already own —
 * and ticks any add-on services the admin has published.
 */
require_once __DIR__ . '/_layout.php';
require_once dirname(__DIR__, 2) . '/admin/includes/ServiceAddon.php';

portal_start();
Currency::ensureSchema();
$currency = Currency::current();

// ── Entry: a plan on the query string starts (or restarts) an order ──
if (isset($_GET['plan'])) {
    $plan = OrderCart::findPlan((string) $_GET['plan']);
    if (!$plan) {
        header('Location: ' . PORTAL_URL . '/order.php');
        exit;
    }
    if ((int) OrderCart::get('plan_id') !== (int) $plan['id']) {
        OrderCart::clear();
        OrderCart::set('plan_id', (int) $plan['id']);
        // Anything the admin marked "ticked by default" starts selected;
        // the client can untick every one of them below.
        $pre = array_map(fn($a) => (int) $a['id'],
            array_filter(ServiceAddon::forCategory($plan['category']), fn($a) => !empty($a['is_preselected'])));
        OrderCart::set('addons', $pre);
    }
    header('Location: ' . checkout_url('index.php'));
    exit;
}

$plan = OrderCart::plan();
if (!$plan) {
    header('Location: ' . PORTAL_URL . '/order.php');
    exit;
}

$addons = ServiceAddon::forCategory($plan['category']);
$error  = '';

// Domains we can actually sell, for the register/transfer validation below.
$tlds = [];
try {
    foreach (db()->query('SELECT tld FROM domain_tlds WHERE is_active = 1 ORDER BY sort_order, tld')->fetchAll() as $r) {
        $tlds[] = $r['tld'];
    }
} catch (\Throwable $e) { /* TLD pricing not set up — handled in the UI */ }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    portal_csrf_verify();

    $mode   = in_array($_POST['domain_mode'] ?? '', ['register', 'transfer', 'existing'], true) ? $_POST['domain_mode'] : '';
    $name   = strtolower(trim($_POST['domain_' . $mode] ?? ''));
    $name   = preg_replace('/^https?:\/\//', '', $name);
    $name   = preg_replace('/[^a-z0-9.-]/', '', rtrim($name, '/'));
    $years  = max(1, min(10, (int) ($_POST['domain_years'] ?? 1)));

    OrderCart::setAddons((array) ($_POST['addons'] ?? []), $plan['category']);

    if ($mode === '') {
        $error = 'Choose how you\'d like to set up your domain.';
    } elseif ($name === '' || !preg_match('/^[a-z0-9][a-z0-9-]*\.[a-z0-9.-]{2,}$/', $name)) {
        $error = 'Enter a full domain name, e.g. yourbusiness.co.ke';
    } elseif (in_array($mode, ['register', 'transfer'], true) && $tlds && !in_array(substr($name, strpos($name, '.') + 1), $tlds, true)) {
        $error = 'We don\'t sell that domain extension online yet — choose "I already own this domain", or contact us and we\'ll help.';
    } else {
        OrderCart::set('domain_mode', $mode);
        OrderCart::set('domain_name', $name);
        OrderCart::set('domain_years', $mode === 'register' ? $years : 1);
        if ($mode === 'existing') OrderCart::set('id_protection', false);
        header('Location: ' . checkout_url('configure.php'));
        exit;
    }
}

$sel_mode  = OrderCart::get('domain_mode', '');
$sel_name  = (string) OrderCart::get('domain_name', '');
$sel_years = OrderCart::domainYears();
$chosen    = OrderCart::addonIds();
$sum       = OrderCart::summary($currency);

checkout_head('Choose your domain', 1);
?>

<form method="POST" id="step1">
<input type="hidden" name="csrf_token" value="<?php echo portal_csrf_token(); ?>" />

<div class="co-grid">
  <div>
    <?php if ($error): ?>
      <div class="p-alert p-alert-error" style="margin-bottom:16px"><i class="fas fa-circle-exclamation"></i> <?php echo h($error); ?></div>
    <?php endif; ?>

    <div class="co-panel">
      <div class="co-panel-head">
        <h2>Set up your domain</h2>
        <p>You're ordering <strong><?php echo h($plan['name']); ?></strong> — <?php echo h(ucfirst($plan['category'])); ?> hosting.</p>
      </div>
      <div class="co-panel-body">

        <label class="co-opt <?php echo $sel_mode === 'register' ? 'sel' : ''; ?>" data-mode="register">
          <input type="radio" name="domain_mode" value="register" <?php echo $sel_mode === 'register' ? 'checked' : ''; ?> />
          <span style="flex:1">
            <span class="co-opt-t">Register a new domain</span>
            <span class="co-opt-d">Pick a fresh name — we'll register it for you and point it at your hosting.</span>
            <span class="mode-fields" data-for="register" style="display:<?php echo $sel_mode === 'register' ? 'flex' : 'none'; ?>;gap:8px;margin-top:11px;flex-wrap:wrap">
              <input type="text" name="domain_register" class="p-input" placeholder="yourbusiness.co.ke" style="flex:1;min-width:190px"
                     value="<?php echo $sel_mode === 'register' ? h($sel_name) : ''; ?>" />
              <select name="domain_years" class="p-input" style="width:120px">
                <?php for ($y = 1; $y <= 5; $y++): ?>
                  <option value="<?php echo $y; ?>" <?php echo $sel_years === $y ? 'selected' : ''; ?>><?php echo $y; ?> year<?php echo $y > 1 ? 's' : ''; ?></option>
                <?php endfor; ?>
              </select>
            </span>
          </span>
        </label>

        <label class="co-opt <?php echo $sel_mode === 'transfer' ? 'sel' : ''; ?>" data-mode="transfer">
          <input type="radio" name="domain_mode" value="transfer" <?php echo $sel_mode === 'transfer' ? 'checked' : ''; ?> />
          <span style="flex:1">
            <span class="co-opt-t">Transfer a domain to us</span>
            <span class="co-opt-d">Move a domain you registered elsewhere. Transfers add a year to your registration.</span>
            <span class="mode-fields" data-for="transfer" style="display:<?php echo $sel_mode === 'transfer' ? 'block' : 'none'; ?>;margin-top:11px">
              <input type="text" name="domain_transfer" class="p-input" placeholder="yourbusiness.co.ke" style="width:100%"
                     value="<?php echo $sel_mode === 'transfer' ? h($sel_name) : ''; ?>" />
              <small style="display:block;margin-top:6px;font-size:11.5px;color:var(--text-muted)">You'll need the EPP/auth code from your current registrar — we'll ask for it after checkout.</small>
            </span>
          </span>
        </label>

        <label class="co-opt <?php echo $sel_mode === 'existing' ? 'sel' : ''; ?>" data-mode="existing">
          <input type="radio" name="domain_mode" value="existing" <?php echo $sel_mode === 'existing' ? 'checked' : ''; ?> />
          <span style="flex:1">
            <span class="co-opt-t">I already own this domain</span>
            <span class="co-opt-d">Keep it where it is and just point its nameservers at us. Nothing extra to pay.</span>
            <span class="mode-fields" data-for="existing" style="display:<?php echo $sel_mode === 'existing' ? 'block' : 'none'; ?>;margin-top:11px">
              <input type="text" name="domain_existing" class="p-input" placeholder="yourbusiness.co.ke" style="width:100%"
                     value="<?php echo $sel_mode === 'existing' ? h($sel_name) : ''; ?>" />
            </span>
          </span>
        </label>
      </div>
    </div>

    <?php if ($addons): ?>
    <div class="co-panel">
      <div class="co-panel-head">
        <h2>Recommended add-ons</h2>
        <p>Optional extras for your <?php echo h($plan['name']); ?> package. You can add these later too.</p>
      </div>
      <div class="co-panel-body">
        <?php foreach ($addons as $a):
          $on    = in_array((int) $a['id'], $chosen, true);
          $price = ServiceAddon::price($a, $currency);
        ?>
          <label class="co-opt <?php echo $on ? 'sel' : ''; ?>" data-addon>
            <input type="checkbox" name="addons[]" value="<?php echo (int) $a['id']; ?>" <?php echo $on ? 'checked' : ''; ?> />
            <span style="flex:1">
              <span class="co-opt-t"><?php echo h($a['name']); ?></span>
              <?php if ($a['description']): ?><span class="co-opt-d"><?php echo h($a['description']); ?></span><?php endif; ?>
            </span>
            <span style="font-weight:800;color:var(--navy);white-space:nowrap;font-size:13.5px">
              <?php echo $price > 0 ? h(checkout_money($price, $currency)) . '<span style="font-weight:600;color:var(--text-muted);font-size:11.5px">' . h(ServiceAddon::cycleSuffix($a['billing_cycle'])) . '</span>' : 'Free'; ?>
            </span>
          </label>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <div class="co-actions">
      <a href="<?php echo SiteSettings::siteRoot(); ?>/index.html" class="co-back"><i class="fas fa-arrow-left"></i> Back to the website</a>
    </div>
  </div>

  <?php checkout_summary($sum, $currency, 'step1', 'Continue'); ?>
</div>
</form>

<script>
// Reveal the input belonging to the selected option, and keep the summary's
// ticked state visually in sync. Prices themselves are recalculated
// server-side on submit — nothing here is trusted for money.
(function () {
  var form = document.getElementById('step1');

  function syncModes() {
    form.querySelectorAll('.co-opt[data-mode]').forEach(function (opt) {
      var on = opt.querySelector('input[type=radio]').checked;
      opt.classList.toggle('sel', on);
      var f = opt.querySelector('.mode-fields');
      if (f) f.style.display = on ? (f.dataset.for === 'register' ? 'flex' : 'block') : 'none';
      if (on) { var i = f && f.querySelector('input[type=text]'); if (i) i.focus({ preventScroll: true }); }
    });
  }

  form.addEventListener('change', function (e) {
    if (e.target.name === 'domain_mode') syncModes();
    var box = e.target.closest ? e.target.closest('.co-opt[data-addon]') : null;
    if (box) box.classList.toggle('sel', e.target.checked);
  });

  // Clicking anywhere in an option row selects it, but let the text inputs
  // and the year dropdown behave normally.
  form.querySelectorAll('.co-opt').forEach(function (opt) {
    opt.addEventListener('click', function (e) {
      if (e.target.matches('input[type=text], select, option')) e.stopPropagation();
    });
  });
})();
</script>

<?php checkout_foot(); ?>
