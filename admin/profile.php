<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

auth_check();
ensure_admin_2fa_columns();
$page_title = 'My Account';
$admin_id = current_admin()['id'];

$stmt = db()->prepare('SELECT * FROM admin_users WHERE id = ?');
$stmt->execute([$admin_id]);
$me = $stmt->fetch();

$errors = []; $new_backup_codes = null; $new_secret_setup = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'change_password') {
        $cur = $_POST['current_password'] ?? '';
        $new = $_POST['new_password'] ?? '';
        $new2 = $_POST['new_password2'] ?? '';
        if (!password_verify($cur, $me['password'])) {
            $errors[] = 'Current password is incorrect.';
        } elseif ($new !== $new2) {
            $errors[] = 'New passwords do not match.';
        } else {
            $errors = password_policy_errors($new, [$me['email'], $me['name']]);
            if (!$errors) {
                db()->prepare('UPDATE admin_users SET password = ? WHERE id = ?')
                    ->execute([password_hash($new, PASSWORD_BCRYPT), $admin_id]);
                log_activity('password_change', 'admin_user', $admin_id, 'Changed own password');
                flash_set('success', 'Password updated.');
                header('Location: ' . APP_URL . '/profile.php');
                exit;
            }
        }

    } elseif ($action === '2fa_start_setup') {
        // Generate a pending secret, held in session only until confirmed —
        // nothing is written to admin_users (and 2FA isn't required) until
        // the admin proves they can actually generate a matching code.
        $_SESSION['2fa_setup_secret'] = TOTP::generateSecret();
        header('Location: ' . APP_URL . '/profile.php#twofactor');
        exit;

    } elseif ($action === '2fa_confirm_setup') {
        $secret = $_SESSION['2fa_setup_secret'] ?? '';
        $code   = trim($_POST['code'] ?? '');
        if (!$secret) {
            $errors[] = 'Setup session expired — click "Set up 2FA" again.';
        } elseif (!TOTP::verify($secret, $code)) {
            $errors[] = 'That code didn\'t match — check your authenticator app and try again.';
        } else {
            $codes = TOTP::generateBackupCodes();
            $hashed = array_map(fn($c) => password_hash($c, PASSWORD_BCRYPT), $codes);
            db()->prepare('UPDATE admin_users SET totp_secret = ?, totp_enabled = 1, totp_backup_codes = ? WHERE id = ?')
                ->execute([$secret, json_encode($hashed), $admin_id]);
            unset($_SESSION['2fa_setup_secret']);
            log_activity('2fa_enabled', 'admin_user', $admin_id, '');
            $new_backup_codes = $codes; // shown once, right now
            $me['totp_enabled'] = 1;
            flash_set('success', 'Two-factor authentication is now enabled. Save your backup codes below — they won\'t be shown again.');
        }

    } elseif ($action === '2fa_disable') {
        if (!password_verify($_POST['confirm_password'] ?? '', $me['password'])) {
            $errors[] = 'Incorrect password — 2FA was not disabled.';
        } else {
            db()->prepare('UPDATE admin_users SET totp_secret = NULL, totp_enabled = 0, totp_backup_codes = NULL WHERE id = ?')
                ->execute([$admin_id]);
            log_activity('2fa_disabled', 'admin_user', $admin_id, '');
            flash_set('success', 'Two-factor authentication disabled.');
            header('Location: ' . APP_URL . '/profile.php');
            exit;
        }
    }
}

if (!empty($_SESSION['2fa_setup_secret']) && !$me['totp_enabled']) {
    $new_secret_setup = $_SESSION['2fa_setup_secret'];
}

require_once __DIR__ . '/includes/header.php';
?>

<div class="content-header">
  <div>
    <h1 class="content-title">My Account</h1>
    <p class="page-subtitle">Manage your own sign-in credentials.</p>
  </div>
</div>

<?php if ($errors): ?>
  <div class="alert alert-danger"><i class="fas fa-triangle-exclamation"></i> <?php echo h(implode(' ', $errors)); ?></div>
<?php endif; ?>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:18px;align-items:start;max-width:900px">

  <div class="card">
    <div class="card-header"><span class="card-title"><i class="fas fa-key"></i> Change Password</span></div>
    <div class="card-body">
      <form method="POST">
        <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>" />
        <input type="hidden" name="action" value="change_password" />
        <div class="form-group">
          <label class="form-label">Current Password</label>
          <input type="password" name="current_password" class="form-control" required autocomplete="current-password" />
        </div>
        <div class="form-group">
          <label class="form-label">New Password</label>
          <input type="password" name="new_password" class="form-control" required autocomplete="new-password" />
          <small class="form-hint">At least 10 characters, with 3 of: lowercase, uppercase, numbers, symbols.</small>
        </div>
        <div class="form-group">
          <label class="form-label">Confirm New Password</label>
          <input type="password" name="new_password2" class="form-control" required autocomplete="new-password" />
        </div>
        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Update Password</button>
      </form>
    </div>
  </div>

  <div class="card" id="twofactor">
    <div class="card-header"><span class="card-title"><i class="fas fa-shield-halved"></i> Two-Factor Authentication</span></div>
    <div class="card-body">
      <?php if ($new_backup_codes): ?>
        <div class="alert alert-warning" style="margin-bottom:16px"><i class="fas fa-triangle-exclamation"></i> Save these backup codes now — each works once if you lose access to your authenticator app. They will not be shown again.</div>
        <div class="code-chip" style="display:block;padding:14px;font-size:14px;line-height:2;text-align:center">
          <?php echo implode('&nbsp;&nbsp;&nbsp;', array_map('h', $new_backup_codes)); ?>
        </div>

      <?php elseif (!empty($me['totp_enabled'])): ?>
        <p style="font-size:13.5px;color:var(--text-muted);margin-bottom:16px"><i class="fas fa-circle-check" style="color:var(--success)"></i> Two-factor authentication is <strong>enabled</strong> on your account.</p>
        <form method="POST">
          <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>" />
          <input type="hidden" name="action" value="2fa_disable" />
          <div class="form-group">
            <label class="form-label">Confirm your password to disable</label>
            <input type="password" name="confirm_password" class="form-control" required />
          </div>
          <button type="submit" class="btn btn-danger" data-confirm="Disable two-factor authentication on your account?"><i class="fas fa-lock-open"></i> Disable 2FA</button>
        </form>

      <?php elseif ($new_secret_setup): ?>
        <p style="font-size:13.5px;color:var(--text-muted);margin-bottom:14px">Scan this into your authenticator app (Google Authenticator, Authy, 1Password, …) using "enter a setup key manually" — no camera needed:</p>
        <div class="code-chip" style="display:block;padding:12px;font-size:15px;letter-spacing:2px;text-align:center;margin-bottom:14px"><?php echo h(chunk_split($new_secret_setup, 4, ' ')); ?></div>
        <p style="font-size:12px;color:var(--text-muted);margin-bottom:14px">Account name: <code><?php echo h($me['email']); ?></code> · Issuer: <code>Orbit Cloud</code></p>
        <form method="POST">
          <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>" />
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
          <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>" />
          <input type="hidden" name="action" value="2fa_start_setup" />
          <button type="submit" class="btn btn-primary"><i class="fas fa-shield-halved"></i> Set Up 2FA</button>
        </form>
      <?php endif; ?>
    </div>
  </div>

</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
