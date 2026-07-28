<?php
/**
 * Orbit Cloud — hosting checkout, step 3: domain configuration.
 *
 * Confirms the domain being set up and offers ID Protection (WHOIS
 * privacy), whose price comes from Site Settings → Billing & Tax. A
 * domain the client already owns has no registration record with us, so
 * there's nothing to shield and the option isn't offered.
 */
require_once __DIR__ . '/_layout.php';

portal_start();
Currency::ensureSchema();
$currency = Currency::current();
checkout_require('domain-config');

$plan    = OrderCart::plan();
$mode    = (string) OrderCart::get('domain_mode');
$domain  = (string) OrderCart::get('domain_name');
$years   = OrderCart::domainYears();
$billing = SiteSettings::billing();
$idp_price = (float) ($billing['id_protection'][$currency] ?? 0);
$idp_available = OrderCart::idProtectionAvailable() && $idp_price > 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    portal_csrf_verify();
    OrderCart::set('id_protection', $idp_available && !empty($_POST['id_protection']));
    $next = ($_POST['action'] ?? '') === 'reprice' ? 'domain-config.php' : 'review.php';
    header('Location: ' . checkout_url($next));
    exit;
}

$idp_on = (bool) OrderCart::get('id_protection');
$sum    = OrderCart::summary($currency);

$mode_label = $mode === 'register' ? 'New registration'
            : ($mode === 'transfer' ? 'Transfer to us' : 'Already owned by you');

checkout_head('Configure your domain', 3);
?>

<form method="POST" id="step3">
<input type="hidden" name="csrf_token" value="<?php echo portal_csrf(); ?>" />
<input type="hidden" name="action" id="step3Action" value="continue" />

<div class="co-grid">
  <div>
    <div class="co-panel">
      <div class="co-panel-head">
        <h2>Your domain</h2>
        <p>This is the domain your <?php echo h($plan['name']); ?> package will be set up on.</p>
      </div>
      <div class="co-panel-body">
        <div style="display:flex;align-items:center;gap:14px;padding:16px;border:2px solid var(--green);background:var(--green-light);border-radius:var(--radius);flex-wrap:wrap">
          <i class="fas fa-globe" style="font-size:22px;color:var(--green)"></i>
          <div style="flex:1;min-width:180px">
            <div style="font-family:ui-monospace,Menlo,monospace;font-weight:800;font-size:16px;color:var(--navy);word-break:break-all"><?php echo h($domain); ?></div>
            <div style="font-size:12.5px;color:var(--text-muted);margin-top:2px">
              <?php echo h($mode_label); ?><?php if ($mode === 'register'): ?> · <?php echo $years; ?> year<?php echo $years > 1 ? 's' : ''; ?><?php endif; ?>
            </div>
          </div>
          <a href="<?php echo checkout_url('index.php'); ?>" class="btn btn-ghost" style="border:1px solid var(--border);background:#fff">Change</a>
        </div>

        <?php if ($mode === 'existing'): ?>
          <div class="p-alert p-alert-info" style="margin:16px 0 0">
            <i class="fas fa-circle-info"></i>
            After checkout we'll send you the nameservers to set at your current registrar. Your website goes live once those changes propagate — usually within a few hours.
          </div>
        <?php elseif ($mode === 'transfer'): ?>
          <div class="p-alert p-alert-info" style="margin:16px 0 0">
            <i class="fas fa-circle-info"></i>
            Make sure the domain is unlocked at your current registrar and you have its EPP/auth code ready. We'll request it once your order is confirmed.
          </div>
        <?php endif; ?>
      </div>
    </div>

    <div class="co-panel">
      <div class="co-panel-head">
        <h2>ID Protection</h2>
        <p>Keeps your name, address, email and phone number out of the public WHOIS directory.</p>
      </div>
      <div class="co-panel-body">
        <?php if (!$idp_available): ?>
          <p style="font-size:13px;color:var(--text-muted);margin:0">
            <?php if ($mode === 'existing'): ?>
              ID Protection is managed by your current registrar for a domain you already own, so there's nothing to add here.
            <?php else: ?>
              ID Protection isn't available for online ordering right now. Open a ticket after checkout and our team will set it up for you.
            <?php endif; ?>
          </p>
        <?php else: ?>
          <label class="co-opt <?php echo $idp_on ? 'sel' : ''; ?>" data-idp>
            <input type="checkbox" name="id_protection" value="1" <?php echo $idp_on ? 'checked' : ''; ?> />
            <span style="flex:1">
              <span class="co-opt-t">Add ID Protection</span>
              <span class="co-opt-d">Replaces your personal details in public WHOIS lookups with our privacy service, which cuts down spam and cold calls.</span>
            </span>
            <span style="text-align:right;white-space:nowrap">
              <span style="font-weight:800;color:var(--navy);font-size:14px"><?php echo h(checkout_money($idp_price, $currency)); ?></span>
              <span style="display:block;font-size:11.5px;color:var(--text-muted)">per year</span>
            </span>
          </label>
        <?php endif; ?>
      </div>
    </div>

    <div class="co-actions">
      <a href="<?php echo checkout_url('configure.php'); ?>" class="co-back"><i class="fas fa-arrow-left"></i> Back to package</a>
    </div>
  </div>

  <?php checkout_summary($sum, $currency, 'step3', 'Continue'); ?>
</div>
</form>

<script>
// Ticking ID Protection changes the total, so re-price server-side.
document.querySelectorAll('.co-opt[data-idp] input[type=checkbox]').forEach(function (c) {
  c.addEventListener('change', function () {
    document.getElementById('step3Action').value = 'reprice';
    document.getElementById('step3').submit();
  });
});
</script>

<?php checkout_foot(); ?>
