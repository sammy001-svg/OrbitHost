<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once dirname(__DIR__) . '/admin/includes/functions.php';
require_once dirname(__DIR__) . '/admin/includes/Notifier.php';

portal_check();
$page_title = 'My Profile';
$cid = current_client()['id'];
ensure_client_notification_prefs();
ensure_client_2fa_columns();

$client = db()->prepare('SELECT * FROM clients WHERE id=?');
$client->execute([$cid]);
$client = $client->fetch();

$errors = $success_pw = false;
$new_backup_codes = null; $new_secret_setup = null;

// ── Update profile ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    portal_csrf_verify();
    $fn    = trim($_POST['first_name'] ?? '');
    $ln    = trim($_POST['last_name']  ?? '');
    $phone = trim($_POST['phone']      ?? '');
    $comp  = trim($_POST['company']    ?? '');
    $ctry  = trim($_POST['country']    ?? '');

    if (!$fn || !$ln) { $errors = 'First and last name are required.'; }
    else {
        db()->prepare('UPDATE clients SET first_name=?,last_name=?,phone=?,company=?,country=? WHERE id=?')
            ->execute([$fn,$ln,$phone,$comp,$ctry,$cid]);
        $_SESSION['client_name'] = "$fn $ln";
        portal_flash_set('success', 'Profile updated successfully.');
        header('Location: ' . PORTAL_URL . '/profile.php');
        exit;
    }
}

// ── Notification preferences ───────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_notify_prefs'])) {
    portal_csrf_verify();
    db()->prepare('UPDATE clients SET notify_reminders=?, notify_announcements=? WHERE id=?')
        ->execute([!empty($_POST['notify_reminders']) ? 1 : 0, !empty($_POST['notify_announcements']) ? 1 : 0, $cid]);
    portal_flash_set('success', 'Notification preferences updated.');
    header('Location: ' . PORTAL_URL . '/profile.php');
    exit;
}

// ── Change password ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    portal_csrf_verify();
    $cur  = $_POST['current_password'] ?? '';
    $new  = $_POST['new_password']     ?? '';
    $new2 = $_POST['new_password2']    ?? '';

    $policy_errors = password_policy_errors($new, [$client['email'], $client['first_name'], $client['last_name']]);
    if (!password_verify($cur, $client['portal_password'] ?? '')) {
        $errors = 'Current password is incorrect.';
    } elseif ($policy_errors) {
        $errors = implode(' ', $policy_errors);
    } elseif ($new !== $new2) {
        $errors = 'New passwords do not match.';
    } else {
        $hash = password_hash($new, PASSWORD_BCRYPT);
        db()->prepare('UPDATE clients SET portal_password=? WHERE id=?')->execute([$hash,$cid]);
        Notifier::send('password_changed', (int) $cid, [
            'client_name' => $client['first_name'],
            'email'       => $client['email'],
            'link'        => PORTAL_URL . '/profile.php',
        ]);
        portal_flash_set('success', 'Password changed successfully.');
        header('Location: ' . PORTAL_URL . '/profile.php');
        exit;
    }
}

// ── Two-factor authentication ───────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && str_starts_with($_POST['action'], '2fa_')) {
    portal_csrf_verify();
    $action = $_POST['action'];

    if ($action === '2fa_start_setup') {
        // Generate a pending secret, held in session only until confirmed —
        // nothing is written to the client's row (and 2FA isn't required)
        // until they prove they can actually generate a matching code.
        $_SESSION['portal_2fa_setup_secret'] = TOTP::generateSecret();
        header('Location: ' . PORTAL_URL . '/profile.php#twofactor');
        exit;

    } elseif ($action === '2fa_confirm_setup') {
        $secret = $_SESSION['portal_2fa_setup_secret'] ?? '';
        $code   = trim($_POST['code'] ?? '');
        if (!$secret) {
            $errors = 'Setup session expired — click "Set up 2FA" again.';
        } elseif (!TOTP::verify($secret, $code)) {
            $errors = 'That code didn\'t match — check your authenticator app and try again.';
        } else {
            $codes = TOTP::generateBackupCodes();
            $hashed = array_map(fn($c) => password_hash($c, PASSWORD_BCRYPT), $codes);
            db()->prepare('UPDATE clients SET totp_secret = ?, totp_enabled = 1, totp_backup_codes = ? WHERE id = ?')
                ->execute([$secret, json_encode($hashed), $cid]);
            unset($_SESSION['portal_2fa_setup_secret']);
            Notifier::send('two_factor_enabled', (int) $cid, [
                'client_name' => $client['first_name'],
                'email'       => $client['email'],
                'link'        => PORTAL_URL . '/profile.php',
            ]);
            $new_backup_codes = $codes; // shown once, right now
            $client['totp_enabled'] = 1;
            portal_flash_set('success', 'Two-factor authentication is now enabled. Save your backup codes below — they won\'t be shown again.');
        }

    } elseif ($action === '2fa_disable') {
        if (!password_verify($_POST['confirm_password'] ?? '', $client['portal_password'] ?? '')) {
            $errors = 'Incorrect password — 2FA was not disabled.';
        } else {
            db()->prepare('UPDATE clients SET totp_secret = NULL, totp_enabled = 0, totp_backup_codes = NULL WHERE id = ?')
                ->execute([$cid]);
            Notifier::send('two_factor_disabled', (int) $cid, [
                'client_name' => $client['first_name'],
                'email'       => $client['email'],
                'link'        => PORTAL_URL . '/profile.php',
            ]);
            portal_flash_set('success', 'Two-factor authentication disabled.');
            header('Location: ' . PORTAL_URL . '/profile.php');
            exit;
        }
    }
}

if (!empty($_SESSION['portal_2fa_setup_secret']) && empty($client['totp_enabled'])) {
    $new_secret_setup = $_SESSION['portal_2fa_setup_secret'];
}

require_once __DIR__ . '/includes/header.php';
?>

<div class="page-hero">
  <div class="container"><div><h1>My Profile</h1><p>Manage your account information and password</p></div></div>
</div>

<div class="page-body">
<div class="container" style="max-width:700px">

  <?php if ($errors): ?><div class="p-alert p-alert-error"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($errors); ?></div><?php endif; ?>

  <!-- Profile form -->
  <div class="p-form-card" style="margin-bottom:20px">
    <h2 style="font-size:16px;font-weight:700;margin-bottom:20px;padding-bottom:12px;border-bottom:1px solid var(--border)">
      <i class="fas fa-user" style="color:var(--green);margin-right:8px"></i>Personal Information
    </h2>
    <form method="POST">
      <input type="hidden" name="csrf_token"     value="<?php echo portal_csrf(); ?>" />
      <input type="hidden" name="update_profile" value="1" />
      <div class="form-grid-2">
        <div class="form-group">
          <label class="form-label">First Name <span class="req">*</span></label>
          <input type="text" name="first_name" class="form-control" value="<?php echo htmlspecialchars($client['first_name']); ?>" required />
        </div>
        <div class="form-group">
          <label class="form-label">Last Name <span class="req">*</span></label>
          <input type="text" name="last_name" class="form-control" value="<?php echo htmlspecialchars($client['last_name']); ?>" required />
        </div>
      </div>
      <div class="form-group">
        <label class="form-label">Email Address</label>
        <input type="email" class="form-control" value="<?php echo htmlspecialchars($client['email']); ?>" disabled style="background:#f8fafc;color:var(--text-muted)" />
        <div class="form-hint">Contact support to change your email address.</div>
      </div>
      <div class="form-grid-2">
        <div class="form-group">
          <label class="form-label">Phone</label>
          <input type="tel" name="phone" class="form-control" value="<?php echo htmlspecialchars($client['phone']); ?>" />
        </div>
        <div class="form-group">
          <label class="form-label">Country</label>
          <select name="country" class="form-select">
            <?php foreach (['Kenya','Uganda','Tanzania','Rwanda','Nigeria','Ghana','South Africa','USA','United Kingdom','Other'] as $c): ?>
              <option <?php echo $client['country']===$c?'selected':''; ?>><?php echo $c; ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="form-group">
        <label class="form-label">Company</label>
        <input type="text" name="company" class="form-control" value="<?php echo htmlspecialchars($client['company']); ?>" />
      </div>
      <div class="form-actions">
        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Changes</button>
      </div>
    </form>
  </div>

  <!-- Change password -->
  <div class="p-form-card">
    <h2 style="font-size:16px;font-weight:700;margin-bottom:20px;padding-bottom:12px;border-bottom:1px solid var(--border)">
      <i class="fas fa-lock" style="color:var(--green);margin-right:8px"></i>Change Password
    </h2>
    <form method="POST">
      <input type="hidden" name="csrf_token"      value="<?php echo portal_csrf(); ?>" />
      <input type="hidden" name="change_password" value="1" />
      <div class="form-group">
        <label class="form-label">Current Password</label>
        <input type="password" name="current_password" class="form-control" required autocomplete="current-password" />
      </div>
      <div class="form-grid-2">
        <div class="form-group">
          <label class="form-label">New Password</label>
          <input type="password" id="new_password" name="new_password" class="form-control" placeholder="Min 10 characters" required />
          <div style="height:3px;background:#f1f5f9;border-radius:2px;margin-top:6px"><div id="strengthBar" style="height:100%;border-radius:2px;transition:width .2s,background .2s;width:0"></div></div>
        </div>
        <div class="form-group">
          <label class="form-label">Confirm New Password</label>
          <input type="password" name="new_password2" class="form-control" required />
        </div>
      </div>
      <div class="form-actions">
        <button type="submit" class="btn btn-primary"><i class="fas fa-key"></i> Update Password</button>
      </div>
    </form>
  </div>

  <!-- Two-factor authentication -->
  <div class="p-form-card" style="margin-top:20px" id="twofactor">
    <h2 style="font-size:16px;font-weight:700;margin-bottom:20px;padding-bottom:12px;border-bottom:1px solid var(--border)">
      <i class="fas fa-shield-halved" style="color:var(--green);margin-right:8px"></i>Two-Factor Authentication
    </h2>
    <?php if ($new_backup_codes): ?>
      <div class="p-alert p-alert-warning" style="margin-bottom:16px"><i class="fas fa-triangle-exclamation"></i> Save these backup codes now — each works once if you lose access to your authenticator app. They will not be shown again.</div>
      <div style="background:#f8fafc;border:1px solid var(--border);border-radius:8px;display:block;padding:14px;font-size:14px;line-height:2;text-align:center;font-family:ui-monospace,Menlo,monospace">
        <?php echo implode('&nbsp;&nbsp;&nbsp;', array_map('htmlspecialchars', $new_backup_codes)); ?>
      </div>

    <?php elseif (!empty($client['totp_enabled'])): ?>
      <p style="font-size:13.5px;color:var(--text-muted);margin-bottom:16px"><i class="fas fa-circle-check" style="color:var(--green)"></i> Two-factor authentication is <strong>enabled</strong> on your account.</p>
      <form method="POST">
        <input type="hidden" name="csrf_token" value="<?php echo portal_csrf(); ?>" />
        <input type="hidden" name="action" value="2fa_disable" />
        <div class="form-group">
          <label class="form-label">Confirm your password to disable</label>
          <input type="password" name="confirm_password" class="form-control" required />
        </div>
        <button type="submit" class="btn" style="background:var(--danger);color:#fff" data-confirm="Disable two-factor authentication on your account?"><i class="fas fa-lock-open"></i> Disable 2FA</button>
      </form>

    <?php elseif ($new_secret_setup): ?>
      <p style="font-size:13.5px;color:var(--text-muted);margin-bottom:14px">Scan this into your authenticator app (Google Authenticator, Authy, 1Password, …) using "enter a setup key manually" — no camera needed:</p>
      <div style="background:#f8fafc;border:1px solid var(--border);border-radius:8px;padding:12px;font-size:15px;letter-spacing:2px;text-align:center;margin-bottom:14px;font-family:ui-monospace,Menlo,monospace"><?php echo htmlspecialchars(chunk_split($new_secret_setup, 4, ' ')); ?></div>
      <p style="font-size:12px;color:var(--text-muted);margin-bottom:14px">Account name: <code><?php echo htmlspecialchars($client['email']); ?></code> · Issuer: <code>Orbit Cloud</code></p>
      <form method="POST">
        <input type="hidden" name="csrf_token" value="<?php echo portal_csrf(); ?>" />
        <input type="hidden" name="action" value="2fa_confirm_setup" />
        <div class="form-group">
          <label class="form-label">Enter the code your app shows now</label>
          <input type="text" name="code" class="form-control" inputmode="numeric" maxlength="6" placeholder="000000" required autofocus />
        </div>
        <button type="submit" class="btn btn-primary"><i class="fas fa-check"></i> Confirm &amp; Enable</button>
      </form>

    <?php else: ?>
      <p style="font-size:13.5px;color:var(--text-muted);margin-bottom:16px">Not enabled. Adds a 6-digit code from your phone to sign-in, on top of your password.</p>
      <form method="POST">
        <input type="hidden" name="csrf_token" value="<?php echo portal_csrf(); ?>" />
        <input type="hidden" name="action" value="2fa_start_setup" />
        <button type="submit" class="btn btn-primary"><i class="fas fa-shield-halved"></i> Set Up 2FA</button>
      </form>
    <?php endif; ?>
  </div>

  <!-- Notification preferences -->
  <div class="p-form-card" style="margin-top:20px">
    <h2 style="font-size:16px;font-weight:700;margin-bottom:20px;padding-bottom:12px;border-bottom:1px solid var(--border)">
      <i class="fas fa-bell" style="color:var(--green);margin-right:8px"></i>Notification Preferences
    </h2>
    <p style="font-size:13px;color:var(--text-muted);margin-bottom:16px">Invoices, tickets, and account/security alerts always send — these two are the only ones you can turn off.</p>
    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?php echo portal_csrf(); ?>" />
      <input type="hidden" name="update_notify_prefs" value="1" />
      <div class="form-group" style="display:flex;align-items:center;justify-content:space-between;gap:12px">
        <div>
          <div style="font-weight:600;font-size:13.5px">Renewal reminders</div>
          <div style="font-size:12px;color:var(--text-muted)">Early heads-up (30/14 days out) that a service or domain is due. Urgent last-chance warnings always send regardless.</div>
        </div>
        <label class="switch"><input type="checkbox" name="notify_reminders" <?php echo $client['notify_reminders'] ? 'checked' : ''; ?> /><span class="track"></span></label>
      </div>
      <div class="form-group" style="display:flex;align-items:center;justify-content:space-between;gap:12px">
        <div>
          <div style="font-weight:600;font-size:13.5px">Announcements &amp; promotions</div>
          <div style="font-size:12px;color:var(--text-muted)">Occasional news, maintenance notices, and offers from our team.</div>
        </div>
        <label class="switch"><input type="checkbox" name="notify_announcements" <?php echo $client['notify_announcements'] ? 'checked' : ''; ?> /><span class="track"></span></label>
      </div>
      <div class="form-actions">
        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Preferences</button>
      </div>
    </form>
  </div>

</div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
