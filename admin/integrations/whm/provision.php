<?php
require_once '../../includes/config.php';
require_once '../../includes/db.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
require_once '../../includes/WHMClient.php';

auth_check();
$page_title = 'Provision cPanel Account';

// Load WHM config
$whm_cfg = db()->query("SELECT settings FROM integration_settings WHERE provider='whm'")->fetchColumn();
$whm_cfg = $whm_cfg ? json_decode($whm_cfg, true) : [];

if (empty($whm_cfg['host']) || empty($whm_cfg['token'])) {
    flash_set('error', 'WHM is not configured.');
    header('Location: ' . APP_URL . '/integrations/index.php#prov-whm');
    exit;
}

$whm      = new WHMClient($whm_cfg['host'], $whm_cfg['user'] ?? 'root', $whm_cfg['token'], (bool)($whm_cfg['ssl_verify'] ?? false));
$packages = [];
try { $packages = $whm->listPackages(); } catch (\Throwable $e) {}

// Load clients and orders without a WHM account
$pending_orders = db()->query(
    "SELECT o.id AS order_id, o.domain_name, o.id, c.first_name, c.last_name, c.email, c.id AS client_id,
            s.name AS service_name
     FROM orders o
     JOIN clients c ON c.id = o.client_id
     JOIN services s ON s.id = o.service_id
     LEFT JOIN whm_accounts wa ON wa.order_id = o.id
     WHERE o.status IN ('active','pending') AND wa.id IS NULL AND o.domain_name IS NOT NULL AND o.domain_name != ''
     ORDER BY o.created_at DESC
     LIMIT 100"
)->fetchAll();

// Pre-select order from query string
$preselect_order = (int)($_GET['order_id'] ?? 0);

$errors  = [];
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $order_id = (int)($_POST['order_id'] ?? 0);
    $domain   = trim($_POST['domain'] ?? '');
    $package  = trim($_POST['package'] ?? '');
    $email    = trim($_POST['email']   ?? '');
    $username = trim($_POST['username'] ?? WHMClient::buildUsername($domain));
    $password = trim($_POST['password'] ?? WHMClient::generatePassword());

    if (!$domain)   $errors[] = 'Domain is required.';
    if (!$email)    $errors[] = 'Email is required.';
    if (!$package)  $errors[] = 'Package is required.';

    if (!$errors) {
        try {
            $result = $whm->createAccount($username, $domain, $password, $package, $email, $email);

            if ((int)($result['metadata']['result'] ?? 0) === 1) {
                db()->prepare('INSERT INTO whm_accounts (order_id, cpanel_user, domain) VALUES (?,?,?)')
                    ->execute([$order_id ?: null, $username, $domain]);

                if ($order_id) {
                    db()->prepare('UPDATE orders SET status="active" WHERE id=?')->execute([$order_id]);
                }

                log_activity('whm_provision', "Provisioned cPanel account: {$username} / {$domain}");
                $success = [
                    'username' => $username,
                    'password' => $password,
                    'domain'   => $domain,
                ];
            } else {
                $errors[] = 'WHM error: ' . htmlspecialchars($result['metadata']['reason'] ?? ($result['result'][0]['statusmsg'] ?? 'Unknown error'));
            }
        } catch (\Throwable $e) {
            $errors[] = 'Connection error: ' . $e->getMessage();
        }
    }
}

require_once '../../includes/header.php';
?>

<div class="content-header">
  <h1 class="content-title">Provision cPanel Account</h1>
  <a href="<?php echo APP_URL; ?>/integrations/whm/" class="btn btn-ghost"><i class="fas fa-arrow-left"></i> Back</a>
</div>

<?php if ($success): ?>
  <div class="card" style="max-width:560px;border:2px solid var(--success)">
    <div class="card-body" style="text-align:center;padding:32px">
      <i class="fas fa-circle-check" style="font-size:48px;color:var(--success);margin-bottom:14px"></i>
      <h2 style="color:var(--success);margin-bottom:4px">Account Provisioned!</h2>
      <p style="color:var(--text-muted);margin-bottom:20px">Send these credentials to the client securely.</p>
      <div style="background:#f8fafc;border:1px solid var(--border);border-radius:8px;padding:20px;text-align:left;font-size:14px">
        <table style="width:100%">
          <tr><td style="color:var(--text-muted);padding:5px 10px;width:120px">Domain</td><td style="font-weight:700;padding:5px 10px"><?php echo htmlspecialchars($success['domain']); ?></td></tr>
          <tr><td style="color:var(--text-muted);padding:5px 10px">cPanel User</td><td style="font-weight:700;padding:5px 10px;font-family:monospace"><?php echo htmlspecialchars($success['username']); ?></td></tr>
          <tr><td style="color:var(--text-muted);padding:5px 10px">Password</td><td style="font-weight:700;padding:5px 10px;font-family:monospace"><?php echo htmlspecialchars($success['password']); ?></td></tr>
          <tr><td style="color:var(--text-muted);padding:5px 10px">cPanel URL</td><td style="padding:5px 10px"><a href="https://<?php echo htmlspecialchars($success['domain']); ?>:2083" target="_blank">https://<?php echo htmlspecialchars($success['domain']); ?>:2083</a></td></tr>
        </table>
      </div>
      <div style="margin-top:20px;display:flex;gap:10px;justify-content:center">
        <a href="provision.php" class="btn btn-primary"><i class="fas fa-plus"></i> Provision Another</a>
        <a href="<?php echo APP_URL; ?>/integrations/whm/" class="btn btn-ghost">View All Accounts</a>
      </div>
    </div>
  </div>

<?php else: ?>

  <?php if ($errors): ?>
    <div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> <?php echo implode('<br>', $errors); ?></div>
  <?php endif; ?>

  <div style="display:grid;grid-template-columns:1fr 340px;gap:20px;align-items:start;max-width:900px">
    <div class="card">
      <div class="card-header"><span class="card-title">Account Details</span></div>
      <div class="card-body">
        <form method="POST" id="provisionForm">
          <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>" />

          <?php if ($pending_orders): ?>
          <div class="form-group">
            <label class="form-label">Link to Pending Order <span style="color:var(--text-muted);font-weight:400">(optional)</span></label>
            <select name="order_id" id="orderSelect" class="form-control">
              <option value="">— Manual entry —</option>
              <?php foreach ($pending_orders as $ord): ?>
                <option value="<?php echo $ord['order_id']; ?>"
                        data-domain="<?php echo htmlspecialchars($ord['domain_name']); ?>"
                        data-email="<?php echo htmlspecialchars($ord['email']); ?>"
                  <?php echo $preselect_order === $ord['order_id'] ? 'selected' : ''; ?>>
                  #<?php echo $ord['order_id']; ?> — <?php echo htmlspecialchars($ord['first_name'] . ' ' . $ord['last_name']); ?> — <?php echo htmlspecialchars($ord['domain_name']); ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <?php else: ?>
            <input type="hidden" name="order_id" value="" />
          <?php endif; ?>

          <div class="form-group">
            <label class="form-label">Domain Name <span class="req">*</span></label>
            <input type="text" name="domain" id="domainInput" class="form-control" placeholder="example.com"
                   value="<?php echo htmlspecialchars($_POST['domain'] ?? ''); ?>" required />
          </div>

          <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px">
            <div class="form-group">
              <label class="form-label">cPanel Username <span class="req">*</span></label>
              <input type="text" name="username" id="usernameInput" class="form-control"
                     placeholder="auto-generated" value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>" />
              <small class="form-hint">Max 16 chars, alphanumeric</small>
            </div>
            <div class="form-group">
              <label class="form-label">Password <span class="req">*</span></label>
              <input type="text" name="password" class="form-control"
                     value="<?php echo htmlspecialchars($_POST['password'] ?? WHMClient::generatePassword()); ?>" required />
            </div>
          </div>

          <div class="form-group">
            <label class="form-label">Email Address <span class="req">*</span></label>
            <input type="email" name="email" id="emailInput" class="form-control"
                   placeholder="client@example.com"
                   value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>" required />
          </div>

          <div class="form-group">
            <label class="form-label">Hosting Package <span class="req">*</span></label>
            <select name="package" class="form-control" required>
              <option value="">— Select Package —</option>
              <?php foreach ($packages as $pkg): ?>
                <option value="<?php echo htmlspecialchars($pkg['name']); ?>"
                  <?php echo ($_POST['package'] ?? '') === $pkg['name'] ? 'selected' : ''; ?>>
                  <?php echo htmlspecialchars($pkg['name']); ?>
                </option>
              <?php endforeach; ?>
              <?php if (!$packages): ?>
                <option value="default">default</option>
              <?php endif; ?>
            </select>
          </div>

          <button type="submit" class="btn btn-primary" onclick="return confirm('Create this cPanel account on the live server?')">
            <i class="fas fa-rocket"></i> Provision Account
          </button>
        </form>
      </div>
    </div>

    <div class="card">
      <div class="card-header"><span class="card-title">Pending Orders</span></div>
      <div style="max-height:400px;overflow-y:auto">
        <?php if ($pending_orders): foreach ($pending_orders as $ord): ?>
          <div style="padding:10px 14px;border-bottom:1px solid var(--border);font-size:13px">
            <div style="font-weight:600"><?php echo htmlspecialchars($ord['first_name'] . ' ' . $ord['last_name']); ?></div>
            <div style="color:var(--text-muted);font-size:12px"><?php echo htmlspecialchars($ord['domain_name']); ?></div>
            <div style="font-size:11px;color:var(--text-muted)"><?php echo htmlspecialchars($ord['service_name']); ?></div>
            <a href="?order_id=<?php echo $ord['order_id']; ?>" class="btn btn-ghost btn-sm" style="margin-top:4px;font-size:11px">Pre-fill</a>
          </div>
        <?php endforeach; else: ?>
          <div style="padding:24px;text-align:center;color:var(--text-muted);font-size:13px">No pending orders without hosting accounts.</div>
        <?php endif; ?>
      </div>
    </div>

  </div>

<?php endif; ?>

<script>
// Auto-fill form when an order is selected
var orderSelect = document.getElementById('orderSelect');
if (orderSelect) {
    orderSelect.addEventListener('change', function () {
        var opt = this.options[this.selectedIndex];
        var domain = opt.dataset.domain || '';
        var email  = opt.dataset.email  || '';
        document.getElementById('domainInput').value  = domain;
        document.getElementById('emailInput').value   = email;
        if (domain) {
            // Auto-build username: strip non-alnum, max 8 chars + 2 random digits
            var u = domain.replace(/\.[^.]+$/, '').replace(/[^a-z0-9]/g, '').substring(0, 8);
            document.getElementById('usernameInput').value = u || '';
        }
    });
    // Trigger if pre-selected
    if (orderSelect.value) orderSelect.dispatchEvent(new Event('change'));
}
</script>

<?php require_once '../../includes/footer.php'; ?>
