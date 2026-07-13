<?php
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/providers/Provider.php';
require_once '../includes/Notifier.php';

auth_check();
$page_title = 'Create Service';

$clients = db()->query('SELECT id, first_name, last_name, email, phone FROM clients ORDER BY first_name, last_name')->fetchAll();
try {
    $plans = db()->query('SELECT id, name, category, billing_cycle, price, panel_package FROM services WHERE is_active = 1 ORDER BY category, price')->fetchAll();
} catch (\Throwable $e) {
    // panel_package column not migrated yet (visit Plans & Packages once, or import schema_v4.sql)
    $plans = db()->query('SELECT id, name, category, billing_cycle, price, NULL AS panel_package FROM services WHERE is_active = 1 ORDER BY category, price')->fetchAll();
}

// Active + configured hosting panels available for provisioning
$panels = [];
foreach (ProviderRegistry::byCategory('panel') as $key => $def) {
    if (Provider::isActive($key) && Provider::isConfigured($key)) {
        $panels[$key] = $def['name'];
    }
}

$errors = [];
$pre_client = (int)($_GET['client_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $client_id   = (int)($_POST['client_id'] ?? 0);
    $service_id  = (int)($_POST['service_id'] ?? 0) ?: null;
    $label       = trim($_POST['label'] ?? '');
    $domain      = trim($_POST['domain'] ?? '');
    $category    = $_POST['category'] ?? 'hosting';
    $cycle       = $_POST['billing_cycle'] ?? 'monthly';
    $amount      = (float)($_POST['amount'] ?? 0);
    $next_due    = $_POST['next_due_date'] ?: null;
    $provider    = $_POST['provider_key'] ?? '';
    $package     = trim($_POST['package'] ?? '');
    $username    = trim($_POST['username'] ?? '');
    $password    = $_POST['password'] ?? '';
    $c_email     = trim($_POST['contact_email'] ?? '');
    $provision   = !empty($_POST['provision_now']);

    if (!$client_id) $errors[] = 'Please choose a client.';
    if (!$label)     $errors[] = 'A service label is required.';
    if ($provision && $provider) {
        if (!$username) $errors[] = 'A username is required to provision an account.';
        if (!$password) $errors[] = 'A password is required to provision an account.';
        if (!$domain)   $errors[] = 'A domain is required to provision an account.';
    }

    if (!$errors) {
        $is_panel      = $provider && isset($panels[$provider]);
        $prov_category = $is_panel ? 'panel' : 'none';
        $server_host   = $is_panel ? (Provider::config($provider)['host'] ?? null) : null;
        $status        = 'pending';

        // Insert the service record first
        db()->prepare(
            'INSERT INTO client_services
             (client_id, service_id, label, domain, category, provider_category, provider_key,
              username, server_host, package, billing_cycle, amount, status, start_date, next_due_date)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,CURDATE(),?)'
        )->execute([
            $client_id, $service_id, $label, $domain ?: null, $category, $prov_category,
            $is_panel ? $provider : null, $username ?: null, $server_host, $package ?: null,
            $cycle, $amount, $status, $next_due,
        ]);
        $svc_id = (int) db()->lastInsertId();

        // Optionally provision immediately through the panel adapter
        if ($provision && $is_panel) {
            db()->prepare('UPDATE client_services SET status = "provisioning" WHERE id = ?')->execute([$svc_id]);
            try {
                $result = Provider::panel($provider)->createAccount([
                    'username' => $username,
                    'domain'   => $domain,
                    'password' => $password,
                    'package'  => $package ?: 'default',
                    'email'    => $c_email,
                ]);
                $ok = !empty($result['success']);
                db()->prepare('UPDATE client_services SET status = ?, remote_id = ?, meta = ? WHERE id = ?')
                    ->execute([$ok ? 'active' : 'failed', $result['username'] ?? $username,
                               json_encode(['provision' => $result]), $svc_id]);
                db()->prepare('INSERT INTO service_actions (service_id, admin_id, action, status, message) VALUES (?,?,?,?,?)')
                    ->execute([$svc_id, current_admin()['id'], 'provision', $ok ? 'success' : 'failed', $result['message'] ?? '']);
                flash_set($ok ? 'success' : 'error',
                    $ok ? 'Service created and provisioned on ' . $panels[$provider] . '.'
                        : 'Service created, but provisioning failed: ' . ($result['message'] ?? 'unknown error'));
                if ($ok) {
                    $cstmt = db()->prepare('SELECT first_name, last_name, email FROM clients WHERE id = ?');
                    $cstmt->execute([$client_id]);
                    if ($crow = $cstmt->fetch()) {
                        Notifier::send('service_ready', $client_id, [
                            'client_name'   => trim($crow['first_name'] . ' ' . $crow['last_name']),
                            'service_label' => $label,
                            'account_rows'  => Notifier::serviceAccountRows($domain, $username, $password, (string) $server_host, $package ?: 'default'),
                            'email'         => $crow['email'],
                            'link'          => portal_base_url() . '/services.php',
                        ]);
                    }
                }
            } catch (\Throwable $e) {
                db()->prepare('UPDATE client_services SET status = "failed" WHERE id = ?')->execute([$svc_id]);
                db()->prepare('INSERT INTO service_actions (service_id, admin_id, action, status, message) VALUES (?,?,?,?,?)')
                    ->execute([$svc_id, current_admin()['id'], 'provision', 'failed', $e->getMessage()]);
                flash_set('error', 'Service created, but provisioning errored: ' . $e->getMessage());
            }
        } else {
            flash_set('success', 'Service created.');
        }

        log_activity('service_create', 'service', $svc_id, $label);
        header('Location: ' . APP_URL . '/services/view.php?id=' . $svc_id);
        exit;
    }
}

require_once '../includes/header.php';
?>

<div class="content-header">
  <div>
    <h1 class="content-title">Create Service</h1>
    <p class="page-subtitle">Set up a client service and optionally provision it live on a connected hosting panel.</p>
  </div>
  <a href="<?php echo APP_URL; ?>/services/" class="btn btn-ghost"><i class="fas fa-arrow-left"></i> Back</a>
</div>

<?php if ($errors): ?>
  <div class="alert alert-danger"><i class="fas fa-triangle-exclamation"></i> <?php echo h(implode(' ', $errors)); ?></div>
<?php endif; ?>

<form method="POST" class="form-wrap" style="max-width:840px">
  <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>" />

  <!-- Step 1 · Client & plan -->
  <div class="steps">
    <div class="step done"><div class="step-num">1</div><div class="step-label">Client &amp; plan</div></div>
    <div class="step-line"></div>
    <div class="step"><div class="step-num">2</div><div class="step-label">Details</div></div>
    <div class="step-line"></div>
    <div class="step"><div class="step-num">3</div><div class="step-label">Provisioning</div></div>
  </div>

  <div class="form-grid-2">
    <div class="form-group">
      <label class="form-label">Client <span class="req">*</span></label>
      <select name="client_id" class="form-select" required>
        <option value="">Select a client…</option>
        <?php foreach ($clients as $c): ?>
          <option value="<?php echo $c['id']; ?>"
                  data-email="<?php echo h($c['email']); ?>"
                  <?php echo $pre_client === (int)$c['id'] ? 'selected' : ''; ?>>
            <?php echo h($c['first_name'] . ' ' . $c['last_name'] . ' — ' . $c['email']); ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group">
      <label class="form-label">Plan (from catalogue)</label>
      <select name="service_id" id="planSelect" class="form-select">
        <option value="">Custom / none</option>
        <?php foreach ($plans as $p): ?>
          <option value="<?php echo $p['id']; ?>"
                  data-name="<?php echo h($p['name']); ?>"
                  data-category="<?php echo h($p['category']); ?>"
                  data-cycle="<?php echo h($p['billing_cycle']); ?>"
                  data-price="<?php echo h($p['price']); ?>"
                  data-package="<?php echo h($p['panel_package'] ?? ''); ?>">
            <?php echo h($p['name'] . ' — ' . format_money((float)$p['price'])); ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
  </div>

  <p class="form-section-title" style="margin-top:24px">Service details</p>
  <div class="form-grid-2">
    <div class="form-group">
      <label class="form-label">Label <span class="req">*</span></label>
      <input type="text" name="label" id="labelField" class="form-control" placeholder="e.g. Business Hosting — acme.co.ke" required />
    </div>
    <div class="form-group">
      <label class="form-label">Primary domain</label>
      <input type="text" name="domain" id="domainField" class="form-control" placeholder="acme.co.ke" />
    </div>
    <div class="form-group">
      <label class="form-label">Category</label>
      <select name="category" id="categoryField" class="form-select">
        <?php foreach (['hosting'=>'Hosting','vps'=>'VPS','reseller'=>'Reseller','domain'=>'Domain','ssl'=>'SSL','email'=>'Email','other'=>'Other'] as $v=>$l): ?>
          <option value="<?php echo $v; ?>"><?php echo $l; ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group">
      <label class="form-label">Billing cycle</label>
      <select name="billing_cycle" id="cycleField" class="form-select">
        <option value="monthly">Monthly</option>
        <option value="annual">Annual</option>
        <option value="one_time">One-time</option>
      </select>
    </div>
    <div class="form-group">
      <label class="form-label">Amount (<?php echo defined('CURRENCY') ? CURRENCY : 'USD'; ?>)</label>
      <input type="number" step="0.01" name="amount" id="amountField" class="form-control" placeholder="0.00" />
    </div>
    <div class="form-group">
      <label class="form-label">Next due date</label>
      <input type="date" name="next_due_date" class="form-control" />
    </div>
  </div>

  <p class="form-section-title" style="margin-top:24px">Provisioning</p>
  <?php if (!$panels): ?>
    <div class="alert alert-info" style="margin-bottom:16px">
      <i class="fas fa-circle-info"></i>
      No hosting panel is active yet. Enable one in
      <a href="<?php echo APP_URL; ?>/integrations/" style="font-weight:600">Providers</a> to provision accounts automatically. You can still create the service manually.
    </div>
  <?php endif; ?>

  <div class="form-grid-2">
    <div class="form-group">
      <label class="form-label">Hosting panel</label>
      <select name="provider_key" id="providerField" class="form-select">
        <option value="">Manual (no provisioning)</option>
        <?php foreach ($panels as $key => $name): ?>
          <option value="<?php echo h($key); ?>"><?php echo h($name); ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group">
      <label class="form-label">Package / plan name</label>
      <input type="text" name="package" id="packageField" class="form-control" placeholder="default" />
      <small class="form-hint">Auto-filled when the chosen plan is linked to a WHM package (see Plans &amp; Packages).</small>
    </div>
    <div class="form-group">
      <label class="form-label">Username</label>
      <input type="text" name="username" id="usernameField" class="form-control mono" placeholder="acmeco12" />
    </div>
    <div class="form-group">
      <label class="form-label">Password</label>
      <div class="input-affix">
        <input type="text" name="password" id="passwordField" class="form-control mono" placeholder="Strong password" style="padding-right:96px" />
        <button type="button" class="affix-btn" id="genPass">Generate</button>
      </div>
    </div>
    <div class="form-group" style="grid-column:1/-1">
      <label class="form-label">Contact email (for the hosting account)</label>
      <input type="email" name="contact_email" id="contactEmail" class="form-control" placeholder="owner@acme.co.ke" />
    </div>
    <div class="form-group" style="grid-column:1/-1">
      <label class="switch">
        <input type="checkbox" name="provision_now" id="provisionNow" <?php echo $panels ? '' : 'disabled'; ?> />
        <span class="track"></span>
        <span>Provision the account now via the selected panel</span>
      </label>
      <small class="form-hint">If off, the service is saved as <strong>Pending</strong> and you can provision it later from the service page.</small>
    </div>
  </div>

  <div class="form-actions">
    <button type="submit" class="btn btn-primary"><i class="fas fa-circle-plus"></i> Create Service</button>
    <a href="<?php echo APP_URL; ?>/services/" class="btn btn-ghost">Cancel</a>
  </div>
</form>

<script>
(function () {
  var plan = document.getElementById('planSelect');
  function fill(id, v) { var el = document.getElementById(id); if (el && v != null) el.value = v; }
  plan && plan.addEventListener('change', function () {
    var o = plan.options[plan.selectedIndex];
    if (!o.value) return;
    fill('labelField', o.dataset.name);
    fill('amountField', o.dataset.price);
    fill('cycleField', o.dataset.cycle);
    if (o.dataset.package) fill('packageField', o.dataset.package);
    var cat = o.dataset.category, map = { shared:'hosting', wordpress:'hosting', cloud:'hosting', dedicated:'hosting', vps:'vps', reseller:'reseller', ssl:'ssl', email:'email', domain:'domain' };
    fill('categoryField', map[cat] || 'hosting');
  });

  // Suggest a cPanel-style username from the domain
  var domain = document.getElementById('domainField');
  domain && domain.addEventListener('blur', function () {
    var u = document.getElementById('usernameField');
    if (u && !u.value && domain.value) {
      var base = domain.value.split('.')[0].replace(/[^a-z0-9]/gi, '').toLowerCase().slice(0, 8);
      u.value = base + Math.floor(10 + Math.random() * 89);
    }
  });

  // Prefill contact email from the chosen client
  var clientSel = document.querySelector('select[name="client_id"]');
  clientSel && clientSel.addEventListener('change', function () {
    var o = clientSel.options[clientSel.selectedIndex];
    var ce = document.getElementById('contactEmail');
    if (ce && !ce.value && o.dataset.email) ce.value = o.dataset.email;
  });

  // Password generator
  document.getElementById('genPass')?.addEventListener('click', function () {
    var chars = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789!@#$%';
    var p = ''; for (var i = 0; i < 16; i++) p += chars[Math.floor(Math.random() * chars.length)];
    document.getElementById('passwordField').value = p;
  });
})();
</script>

<?php require_once '../includes/footer.php'; ?>
