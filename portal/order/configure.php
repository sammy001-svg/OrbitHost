<?php
/**
 * Orbit Cloud — hosting checkout, step 2: configure.
 *
 * Shows what's being bought, lets the client pick a billing cycle, and
 * prices the whole order including VAT.
 *
 * A plan in the catalogue carries exactly one billing cycle, so "Starter
 * monthly" and "Starter annual" are two rows in Plans & Packages. Picking
 * a cycle here therefore switches which of those sibling plans is being
 * ordered — see OrderCart::cycleOptions(). A package published on only
 * one cycle simply shows that cycle with nothing to choose.
 */
require_once __DIR__ . '/_layout.php';

portal_start();
Currency::ensureSchema();
$currency = Currency::current();
checkout_require('configure');

$plan   = OrderCart::plan();
$cycles = OrderCart::cycleOptions($plan);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    portal_csrf_verify();
    $want = (string) ($_POST['billing_cycle'] ?? '');
    if (isset($cycles[$want])) {
        OrderCart::set('plan_id', (int) $cycles[$want]['id']);
    }
    // Changing the cycle re-prices in place; only Continue moves on.
    $next = ($_POST['action'] ?? '') === 'reprice' ? 'configure.php' : 'domain-config.php';
    header('Location: ' . checkout_url($next));
    exit;
}

$cycle_labels = ['monthly' => 'Monthly', 'annual' => 'Annual', 'one_time' => 'One-time'];
$features = array_values(array_filter(array_map('trim',
    preg_split('/\r\n|\r|\n/', (string) ($plan['features'] ?? '')))));
$sum = OrderCart::summary($currency);

checkout_head('Configure your package', 2);
?>

<form method="POST" id="step2">
<input type="hidden" name="csrf_token" value="<?php echo portal_csrf(); ?>" />
<input type="hidden" name="action" id="step2Action" value="continue" />

<div class="co-grid">
  <div>
    <div class="co-panel">
      <div class="co-panel-head">
        <h2><?php echo h($plan['name']); ?> — <?php echo h(ucfirst($plan['category'])); ?> Hosting</h2>
        <?php if (!empty($plan['description'])): ?>
          <p><?php echo h($plan['description']); ?></p>
        <?php endif; ?>
      </div>
      <div class="co-panel-body">
        <div style="display:flex;justify-content:space-between;gap:14px;padding-bottom:14px;border-bottom:1px solid var(--border);flex-wrap:wrap">
          <div>
            <div style="font-size:11.5px;text-transform:uppercase;letter-spacing:.4px;color:var(--text-muted);font-weight:700">Domain</div>
            <div style="font-family:ui-monospace,Menlo,monospace;font-weight:700;color:var(--navy);margin-top:3px"><?php echo h((string) OrderCart::get('domain_name')); ?></div>
            <div style="font-size:12px;color:var(--text-muted);margin-top:2px">
              <?php
                $mode = OrderCart::get('domain_mode');
                echo $mode === 'register' ? 'New registration' : ($mode === 'transfer' ? 'Transfer to us' : 'Domain you already own');
              ?>
            </div>
          </div>
          <a href="<?php echo checkout_url('index.php'); ?>" class="btn btn-ghost" style="border:1px solid var(--border);align-self:flex-start">Change</a>
        </div>

        <?php if ($features): ?>
        <div style="margin-top:16px">
          <div style="font-size:11.5px;text-transform:uppercase;letter-spacing:.4px;color:var(--text-muted);font-weight:700;margin-bottom:9px">What's included</div>
          <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:6px 18px">
            <?php foreach ($features as $f): ?>
              <div style="font-size:13px;color:var(--text-sub,#4b5878);display:flex;gap:8px;align-items:flex-start">
                <i class="fas fa-check" style="color:var(--green);margin-top:3px;font-size:11px"></i><span><?php echo h($f); ?></span>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <div class="co-panel">
      <div class="co-panel-head">
        <h2>Billing cycle</h2>
        <p><?php echo count($cycles) > 1 ? 'Choose how often you\'d like to be billed for this package.' : 'This package is billed on a single cycle.'; ?></p>
      </div>
      <div class="co-panel-body">
        <?php foreach ($cycles as $cycle => $row):
          $amt = Currency::planAmount($row, $currency);
          $on  = (int) $row['id'] === (int) $plan['id'];
          $per = $cycle === 'monthly' ? 'per month' : ($cycle === 'annual' ? 'per year' : 'one-time');
        ?>
          <label class="co-opt <?php echo $on ? 'sel' : ''; ?>" data-cycle>
            <input type="radio" name="billing_cycle" value="<?php echo h($cycle); ?>" <?php echo $on ? 'checked' : ''; ?> />
            <span style="flex:1">
              <span class="co-opt-t"><?php echo h($cycle_labels[$cycle] ?? ucfirst($cycle)); ?></span>
              <?php if ($cycle === 'annual' && isset($cycles['monthly'])):
                $m = Currency::planAmount($cycles['monthly'], $currency)['price'] * 12;
                $a = $amt['price'];
                if ($m > 0 && $a < $m): ?>
                  <span class="co-opt-d" style="color:var(--green);font-weight:600">Save <?php echo h(checkout_money($m - $a, $currency)); ?> a year versus monthly billing</span>
              <?php endif; endif; ?>
            </span>
            <span style="text-align:right;white-space:nowrap">
              <span style="font-weight:800;color:var(--navy);font-size:15px"><?php echo h(checkout_money((float) $amt['price'], $currency)); ?></span>
              <span style="display:block;font-size:11.5px;color:var(--text-muted)"><?php echo $per; ?></span>
            </span>
          </label>
        <?php endforeach; ?>

        <?php if ((float) (Currency::planAmount($plan, $currency)['setup_fee']) > 0): ?>
          <p style="font-size:12.5px;color:var(--text-muted);margin:12px 0 0">
            A one-time setup fee of <strong><?php echo h(checkout_money((float) Currency::planAmount($plan, $currency)['setup_fee'], $currency)); ?></strong> applies to this package and is included in the total.
          </p>
        <?php endif; ?>
      </div>
    </div>

    <div class="co-actions">
      <a href="<?php echo checkout_url('index.php'); ?>" class="co-back"><i class="fas fa-arrow-left"></i> Back to domain</a>
    </div>
  </div>

  <?php checkout_summary($sum, $currency, 'step2', 'Continue'); ?>
</div>
</form>

<script>
// Switching cycle changes the price, so round-trip to the server and let it
// re-price rather than doing money maths in the browser. Continue keeps the
// form's default action and moves to the next step.
document.querySelectorAll('.co-opt[data-cycle] input[type=radio]').forEach(function (r) {
  r.addEventListener('change', function () {
    document.getElementById('step2Action').value = 'reprice';
    document.getElementById('step2').submit();
  });
});
</script>

<?php checkout_foot(); ?>
