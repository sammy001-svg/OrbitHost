<?php
/**
 * Orbit Cloud — hosting checkout, step 4: Review & Checkout.
 *
 * Final look at the order, then either create a client area account or
 * sign in to an existing one. Placing the order writes the order and its
 * invoice (see _place.php) and hands off to the invoice page to pay.
 */
require_once __DIR__ . '/_layout.php';
require_once __DIR__ . '/_place.php';
require_once dirname(__DIR__, 2) . '/admin/includes/ServiceAddon.php';
require_once dirname(__DIR__, 2) . '/admin/includes/LoginGuard.php';

portal_start();
Currency::ensureSchema();
$currency = Currency::current();
checkout_require('review');

$plan      = OrderCart::plan();
$logged_in = !empty($_SESSION['client_id']);
$errors    = [];
$mode      = $logged_in ? 'account' : (($_POST['auth_mode'] ?? 'new') === 'existing' ? 'existing' : 'new');
$data      = ['first_name'=>'','last_name'=>'','email'=>'','phone'=>'','company'=>'','country'=>'Kenya'];

if ($logged_in) {
    $stmt = db()->prepare('SELECT * FROM clients WHERE id = ?');
    $stmt->execute([(int) $_SESSION['client_id']]);
    $me = $stmt->fetch() ?: [];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    portal_csrf_verify();
    $client = null;

    if ($logged_in) {
        $client = $me;

    } elseif ($mode === 'existing') {
        // ── Sign in to an existing client area account ──
        // Goes through portal_login() rather than checking the hash here,
        // so checkout gets the same brute-force lockout and the same 2FA
        // challenge as the normal sign-in page.
        $r = portal_login(trim($_POST['login_email'] ?? ''), (string) ($_POST['login_password'] ?? ''));

        if (empty($r['ok'])) {
            $errors[] = $r['message'] ?? 'Those details don\'t match an account.';
        } elseif (!empty($r['needs_2fa'])) {
            // The cart survives in the session, so we come straight back
            // here once the second factor checks out.
            $_SESSION['post_login_redirect'] = 'order/review.php';
            header('Location: ' . PORTAL_URL . '/verify-2fa.php');
            exit;
        } else {
            $stmt = db()->prepare('SELECT * FROM clients WHERE id = ?');
            $stmt->execute([(int) $_SESSION['client_id']]);
            $client = $stmt->fetch() ?: null;
        }

    } else {
        // ── Create the client area account ──
        $data = [
            'first_name' => trim($_POST['first_name'] ?? ''),
            'last_name'  => trim($_POST['last_name']  ?? ''),
            'email'      => trim($_POST['email']      ?? ''),
            'phone'      => trim($_POST['phone']      ?? ''),
            'company'    => trim($_POST['company']    ?? ''),
            'country'    => trim($_POST['country']    ?? 'Kenya'),
        ];
        $pass  = (string) ($_POST['password']  ?? '');
        $pass2 = (string) ($_POST['password2'] ?? '');

        if (!$data['first_name']) $errors[] = 'First name is required.';
        if (!$data['last_name'])  $errors[] = 'Last name is required.';
        if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'A valid email address is required.';
        $errors = array_merge($errors, password_policy_errors($pass, [$data['email'], $data['first_name'], $data['last_name']]));
        if ($pass !== $pass2) $errors[] = 'Passwords do not match.';

        if (!$errors) {
            $dup = db()->prepare('SELECT id FROM clients WHERE email = ?');
            $dup->execute([$data['email']]);
            if ($dup->fetch()) {
                $errors[] = 'An account with this email already exists — switch to "I already have an account" and sign in to finish your order.';
            } else {
                ensure_client_verify_columns();
                $hash  = password_hash($pass, PASSWORD_BCRYPT);
                $token = bin2hex(random_bytes(32));
                db()->prepare('INSERT INTO clients (first_name,last_name,email,phone,company,country,status,portal_password,email_verified,verify_token,verify_expires)
                               VALUES (?,?,?,?,?,?,"active",?,0,?,DATE_ADD(NOW(), INTERVAL 24 HOUR))')
                    ->execute([$data['first_name'], $data['last_name'], $data['email'], $data['phone'],
                               $data['company'], $data['country'], $hash, $token]);
                $cid = (int) db()->lastInsertId();

                session_regenerate_id(true);
                $_SESSION['client_id']    = $cid;
                $_SESSION['client_name']  = $data['first_name'] . ' ' . $data['last_name'];
                $_SESSION['client_email'] = $data['email'];
                $_SESSION['last_active']  = time();

                try {
                    Notifier::send('account_welcome', $cid, [
                        'client_name' => $data['first_name'],
                        'email'       => $data['email'],
                        'link'        => PORTAL_URL . '/dashboard.php',
                    ]);
                    Notifier::send('email_verification', $cid, [
                        'client_name' => $data['first_name'],
                        'email'       => $data['email'],
                        'link'        => PORTAL_URL . '/verify-email.php?token=' . $token,
                    ]);
                } catch (\Throwable $e) { /* non-fatal */ }

                $client = array_merge($data, ['id' => $cid]);
            }
        }
    }

    if ($client && !$errors) {
        try {
            $placed = place_hosting_order($client, $currency);
            // Redirect first, then email — see notify_hosting_order() for why
            // checkout must never wait on an SMTP server.
            header('Location: ' . checkout_url('complete.php') . '?invoice=' . $placed['invoice_id']);
            notify_hosting_order($placed, $client, $currency);
            exit;
        } catch (\Throwable $e) {
            $errors[] = $e->getMessage();
        }
    }
}

$sum   = OrderCart::summary($currency);
$mode_label = ['register' => 'New registration', 'transfer' => 'Transfer to us', 'existing' => 'Domain you already own'];

checkout_head('Review & Checkout', 4);
?>

<form method="POST" id="step4">
<input type="hidden" name="csrf_token" value="<?php echo portal_csrf(); ?>" />
<input type="hidden" name="auth_mode" id="authMode" value="<?php echo h($mode); ?>" />

<div class="co-grid">
  <div>
    <?php if ($errors): ?>
      <div class="p-alert p-alert-error" style="margin-bottom:16px">
        <i class="fas fa-circle-exclamation"></i>
        <?php if (count($errors) === 1): ?><?php echo h($errors[0]); ?>
        <?php else: ?><ul style="margin:4px 0 0 18px"><?php foreach ($errors as $e): ?><li><?php echo h($e); ?></li><?php endforeach; ?></ul><?php endif; ?>
      </div>
    <?php endif; ?>

    <div class="co-panel">
      <div class="co-panel-head"><h2>Review your order</h2></div>
      <div class="co-panel-body">
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px">
          <div>
            <div style="font-size:11.5px;text-transform:uppercase;letter-spacing:.4px;color:var(--text-muted);font-weight:700">Package</div>
            <div style="font-weight:700;color:var(--text);margin-top:3px"><?php echo h($plan['name']); ?></div>
            <div style="font-size:12.5px;color:var(--text-muted)"><?php echo h(ucfirst($plan['category'])); ?> hosting · billed <?php echo h(str_replace('_', ' ', $plan['billing_cycle'])); ?></div>
          </div>
          <div>
            <div style="font-size:11.5px;text-transform:uppercase;letter-spacing:.4px;color:var(--text-muted);font-weight:700">Domain</div>
            <div style="font-family:ui-monospace,Menlo,monospace;font-weight:700;color:var(--text);margin-top:3px;word-break:break-all"><?php echo h((string) OrderCart::get('domain_name')); ?></div>
            <div style="font-size:12.5px;color:var(--text-muted)">
              <?php echo h($mode_label[OrderCart::get('domain_mode')] ?? ''); ?>
              <?php if (OrderCart::get('id_protection')): ?> · ID Protection<?php endif; ?>
            </div>
          </div>
        </div>
        <div style="margin-top:14px;display:flex;gap:14px;flex-wrap:wrap">
          <a href="<?php echo checkout_url('configure.php'); ?>" class="co-back">Change package</a>
          <a href="<?php echo checkout_url('domain-config.php'); ?>" class="co-back">Change domain options</a>
        </div>
      </div>
    </div>

    <?php if ($logged_in): ?>
      <div class="co-panel">
        <div class="co-panel-head"><h2>Your account</h2></div>
        <div class="co-panel-body">
          <div class="p-alert p-alert-success" style="margin:0">
            <i class="fas fa-circle-check"></i>
            Signed in as <strong><?php echo h(trim(($me['first_name'] ?? '') . ' ' . ($me['last_name'] ?? ''))); ?></strong> (<?php echo h($me['email'] ?? ''); ?>). This order will be added to your account.
          </div>
        </div>
      </div>
    <?php else: ?>
      <div class="co-panel">
        <div class="co-panel-head">
          <h2>Your details</h2>
          <p>You'll use these to sign in to your client area and manage this service.</p>
        </div>
        <div class="co-panel-body">
          <div style="display:flex;gap:8px;margin-bottom:18px;flex-wrap:wrap">
            <button type="button" class="btn <?php echo $mode === 'new' ? 'btn-primary' : 'btn-ghost'; ?>" data-auth="new" style="<?php echo $mode === 'new' ? '' : 'border:1px solid var(--border)'; ?>">I'm new here</button>
            <button type="button" class="btn <?php echo $mode === 'existing' ? 'btn-primary' : 'btn-ghost'; ?>" data-auth="existing" style="<?php echo $mode === 'existing' ? '' : 'border:1px solid var(--border)'; ?>">I already have an account</button>
          </div>

          <div data-pane="new" style="display:<?php echo $mode === 'new' ? 'block' : 'none'; ?>">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
              <div class="form-group"><label class="form-label">First name <span style="color:var(--danger)">*</span></label>
                <input type="text" name="first_name" class="form-control" value="<?php echo h($data['first_name']); ?>" /></div>
              <div class="form-group"><label class="form-label">Last name <span style="color:var(--danger)">*</span></label>
                <input type="text" name="last_name" class="form-control" value="<?php echo h($data['last_name']); ?>" /></div>
              <div class="form-group"><label class="form-label">Email address <span style="color:var(--danger)">*</span></label>
                <input type="email" name="email" class="form-control" value="<?php echo h($data['email']); ?>" /></div>
              <div class="form-group"><label class="form-label">Phone</label>
                <input type="text" name="phone" class="form-control" placeholder="+254 7XX XXX XXX" value="<?php echo h($data['phone']); ?>" /></div>
              <div class="form-group"><label class="form-label">Company <span style="color:var(--text-muted);font-weight:400">(optional)</span></label>
                <input type="text" name="company" class="form-control" value="<?php echo h($data['company']); ?>" /></div>
              <div class="form-group"><label class="form-label">Country</label>
                <select name="country" class="form-select">
                  <?php foreach (get_countries() as $c): ?>
                    <option value="<?php echo h($c); ?>" <?php echo $data['country'] === $c ? 'selected' : ''; ?>><?php echo h($c); ?></option>
                  <?php endforeach; ?>
                </select></div>
              <div class="form-group"><label class="form-label">Password <span style="color:var(--danger)">*</span></label>
                <input type="password" name="password" class="form-control" autocomplete="new-password" /></div>
              <div class="form-group"><label class="form-label">Confirm password <span style="color:var(--danger)">*</span></label>
                <input type="password" name="password2" class="form-control" autocomplete="new-password" /></div>
            </div>
          </div>

          <div data-pane="existing" style="display:<?php echo $mode === 'existing' ? 'block' : 'none'; ?>">
            <div class="form-group"><label class="form-label">Email address</label>
              <input type="email" name="login_email" class="form-control" value="<?php echo h($_POST['login_email'] ?? ''); ?>" autocomplete="username" /></div>
            <div class="form-group"><label class="form-label">Password</label>
              <input type="password" name="login_password" class="form-control" autocomplete="current-password" /></div>
            <p style="font-size:12.5px;color:var(--text-muted);margin:0">
              <a href="<?php echo PORTAL_URL; ?>/forgot-password.php" target="_blank" rel="noopener">Forgotten your password?</a>
              Your order is kept while you reset it.
            </p>
          </div>
        </div>
      </div>
    <?php endif; ?>

    <div class="co-panel">
      <div class="co-panel-head"><h2>What happens next</h2></div>
      <div class="co-panel-body" style="font-size:13px;color:var(--text-2);line-height:1.75">
        <div><i class="fas fa-file-invoice" style="color:var(--green);width:18px"></i> We generate invoice and email a copy to you.</div>
        <div><i class="fas fa-credit-card" style="color:var(--green);width:18px"></i> You can pay it straight away, or later from your client area.</div>
        <div><i class="fas fa-rocket" style="color:var(--green);width:18px"></i> Your hosting is set up automatically once payment clears.</div>
      </div>
    </div>

    <div class="co-actions">
      <a href="<?php echo checkout_url('domain-config.php'); ?>" class="co-back"><i class="fas fa-arrow-left"></i> Back</a>
    </div>
  </div>

  <?php checkout_summary($sum, $currency, 'step4', 'Complete Order'); ?>
</div>
</form>

<script>
document.querySelectorAll('[data-auth]').forEach(function (btn) {
  btn.addEventListener('click', function () {
    var want = btn.dataset.auth;
    document.getElementById('authMode').value = want;
    document.querySelectorAll('[data-pane]').forEach(function (p) {
      p.style.display = p.dataset.pane === want ? 'block' : 'none';
    });
    document.querySelectorAll('[data-auth]').forEach(function (b) {
      var on = b === btn;
      b.classList.toggle('btn-primary', on);
      b.classList.toggle('btn-ghost', !on);
      b.style.border = on ? '' : '1px solid var(--border)';
    });
  });
});
</script>

<?php checkout_foot(); ?>
