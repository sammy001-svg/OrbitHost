<?php
require_once '../../includes/config.php';
require_once '../../includes/db.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
require_once '../../includes/DomainClient.php';

auth_check();
$page_title = 'Register Domain';

$domain   = trim($_GET['domain'] ?? $_POST['domain'] ?? '');
$provider = trim($_GET['provider'] ?? $_POST['provider'] ?? 'namecheap');
$errors   = [];

// Load clients for the form
$clients = db()->query('SELECT id, first_name, last_name, email FROM clients WHERE status="active" ORDER BY first_name, last_name')->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm'])) {
    csrf_verify();

    $domain    = trim($_POST['domain']    ?? '');
    $provider  = trim($_POST['provider']  ?? '');
    $client_id = (int)($_POST['client_id'] ?? 0);
    $years     = (int)($_POST['years']    ?? 1);

    $contact = [
        'first_name'   => trim($_POST['contact_first_name']   ?? ''),
        'last_name'    => trim($_POST['contact_last_name']    ?? ''),
        'email'        => trim($_POST['contact_email']        ?? ''),
        'phone'        => trim($_POST['contact_phone']        ?? ''),
        'address'      => trim($_POST['contact_address']      ?? ''),
        'city'         => trim($_POST['contact_city']         ?? ''),
        'state'        => trim($_POST['contact_state']        ?? ''),
        'postal_code'  => trim($_POST['contact_postal_code']  ?? ''),
        'country'      => trim($_POST['contact_country']      ?? 'KE'),
    ];

    foreach (['first_name', 'last_name', 'email', 'phone', 'address', 'city', 'country'] as $f) {
        if (empty($contact[$f])) $errors[] = ucfirst(str_replace('_', ' ', $f)) . ' is required.';
    }

    if (!$errors) {
        try {
            $dc     = DomainClient::fromDB($provider);
            $result = $dc->register($domain, $contact, $years);

            if ($result['success'] ?? false) {
                $expiry = date('Y-m-d', strtotime("+{$years} years"));
                db()->prepare(
                    'INSERT INTO domain_registrations (client_id, domain_name, registrar, expiry_date, status, auto_renew)
                     VALUES (?,?,?,?,?,?)'
                )->execute([$client_id ?: null, $domain, $provider, $expiry, 'active', 1]);

                log_activity('domain_register', "Registered {$domain} via {$provider}");
                flash_set('success', "{$domain} registered successfully!");
                header('Location: ' . APP_URL . '/integrations/domains/');
                exit;
            } else {
                $errors[] = 'Registration failed: ' . htmlspecialchars($result['error'] ?? 'Unknown error');
            }
        } catch (\Throwable $e) {
            $errors[] = $e->getMessage();
        }
    }
}

// Pre-fill contact from client if selected
$prefill = [];
if (!empty($_GET['client_id'])) {
    $stmt = db()->prepare('SELECT * FROM clients WHERE id=?');
    $stmt->execute([(int)$_GET['client_id']]);
    if ($cl = $stmt->fetch()) {
        $prefill = [
            'first_name'  => $cl['first_name'] ?? '',
            'last_name'   => $cl['last_name']  ?? '',
            'email'       => $cl['email']       ?? '',
            'phone'       => $cl['phone']       ?? '',
            'address'     => $cl['address']     ?? '',
            'city'        => $cl['city']        ?? '',
            'state'       => $cl['state']       ?? '',
            'postal_code' => $cl['postal_code'] ?? '',
            'country'     => $cl['country']     ?? 'KE',
        ];
    }
}

require_once '../../includes/header.php';

function fv(array $prefill, string $k, string $default = ''): string {
    return htmlspecialchars($_POST['contact_' . $k] ?? $prefill[$k] ?? $default);
}
?>

<div class="content-header">
  <h1 class="content-title">Register Domain</h1>
  <a href="check.php" class="btn btn-ghost"><i class="fas fa-arrow-left"></i> Back</a>
</div>

<div style="max-width:680px">
  <?php if ($errors): ?>
    <div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> <?php echo implode('<br>', array_map('htmlspecialchars', $errors)); ?></div>
  <?php endif; ?>

  <div class="card">
    <div class="card-header">
      <span class="card-title">Register <strong><?php echo htmlspecialchars($domain); ?></strong></span>
      <span class="badge badge-info"><?php echo ucfirst($provider); ?></span>
    </div>
    <div class="card-body">
      <form method="POST">
        <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>" />
        <input type="hidden" name="confirm"    value="1" />
        <input type="hidden" name="domain"     value="<?php echo htmlspecialchars($domain); ?>" />
        <input type="hidden" name="provider"   value="<?php echo htmlspecialchars($provider); ?>" />

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px">
          <div class="form-group">
            <label class="form-label">Link to Client</label>
            <select name="client_id" class="form-control">
              <option value="">— None —</option>
              <?php foreach ($clients as $cl): ?>
                <option value="<?php echo $cl['id']; ?>" <?php echo ($_POST['client_id'] ?? '') == $cl['id'] ? 'selected' : ''; ?>>
                  <?php echo htmlspecialchars($cl['first_name'] . ' ' . $cl['last_name']); ?> (<?php echo htmlspecialchars($cl['email']); ?>)
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Registration Period</label>
            <select name="years" class="form-control">
              <?php for ($y = 1; $y <= 10; $y++): ?>
                <option value="<?php echo $y; ?>" <?php echo ($_POST['years'] ?? 1) == $y ? 'selected' : ''; ?>><?php echo $y; ?> year<?php echo $y > 1 ? 's' : ''; ?></option>
              <?php endfor; ?>
            </select>
          </div>
        </div>

        <h4 style="margin:16px 0 10px;font-size:13px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted)">Registrant Contact</h4>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px">
          <div class="form-group"><label class="form-label">First Name <span class="req">*</span></label><input type="text" name="contact_first_name" class="form-control" value="<?php echo fv($prefill, 'first_name'); ?>" required /></div>
          <div class="form-group"><label class="form-label">Last Name <span class="req">*</span></label><input type="text" name="contact_last_name" class="form-control" value="<?php echo fv($prefill, 'last_name'); ?>" required /></div>
          <div class="form-group"><label class="form-label">Email <span class="req">*</span></label><input type="email" name="contact_email" class="form-control" value="<?php echo fv($prefill, 'email'); ?>" required /></div>
          <div class="form-group"><label class="form-label">Phone <span class="req">*</span></label><input type="text" name="contact_phone" class="form-control" placeholder="+254700000000" value="<?php echo fv($prefill, 'phone'); ?>" required /></div>
          <div class="form-group" style="grid-column:1/-1"><label class="form-label">Address <span class="req">*</span></label><input type="text" name="contact_address" class="form-control" value="<?php echo fv($prefill, 'address'); ?>" required /></div>
          <div class="form-group"><label class="form-label">City <span class="req">*</span></label><input type="text" name="contact_city" class="form-control" value="<?php echo fv($prefill, 'city'); ?>" required /></div>
          <div class="form-group"><label class="form-label">State / County</label><input type="text" name="contact_state" class="form-control" value="<?php echo fv($prefill, 'state'); ?>" /></div>
          <div class="form-group"><label class="form-label">Postal Code</label><input type="text" name="contact_postal_code" class="form-control" value="<?php echo fv($prefill, 'postal_code'); ?>" /></div>
          <div class="form-group">
            <label class="form-label">Country <span class="req">*</span></label>
            <select name="contact_country" class="form-control">
              <?php foreach (get_countries() as $code => $name): ?>
                <option value="<?php echo $code; ?>" <?php echo fv($prefill, 'country', 'KE') === $code ? 'selected' : ''; ?>><?php echo htmlspecialchars($name); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <div class="alert alert-warning" style="margin-top:16px;font-size:13px">
          <i class="fas fa-triangle-exclamation"></i>
          <strong>Live action:</strong> This will register a real domain<?php echo !empty($provider === 'namecheap' && ($_POST['nc_sandbox'] ?? false)) ? ' in sandbox mode' : ''; ?> and may incur charges from the registrar.
        </div>

        <div style="display:flex;gap:10px;margin-top:16px">
          <button type="submit" class="btn btn-primary" onclick="return confirm('Register <?php echo htmlspecialchars(addslashes($domain)); ?>?')">
            <i class="fas fa-globe"></i> Register Domain
          </button>
          <a href="check.php?domain=<?php echo urlencode($domain); ?>&provider=<?php echo urlencode($provider); ?>" class="btn btn-ghost">Cancel</a>
        </div>
      </form>
    </div>
  </div>
</div>

<?php require_once '../../includes/footer.php'; ?>
