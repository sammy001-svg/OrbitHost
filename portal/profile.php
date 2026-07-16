<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once dirname(__DIR__) . '/admin/includes/functions.php';
require_once dirname(__DIR__) . '/admin/includes/Notifier.php';

portal_check();
$page_title = 'My Profile';
$cid = current_client()['id'];
ensure_client_notification_prefs();

$client = db()->prepare('SELECT * FROM clients WHERE id=?');
$client->execute([$cid]);
$client = $client->fetch();

$errors = $success_pw = false;

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
