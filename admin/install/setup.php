<?php
/**
 * Orbit Cloud Admin — First-run password setup.
 * Access this file once at: http://your-site/admin/install/setup.php
 * DELETE this file after use.
 */
define('SETUP_MODE', true);
require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/db.php';

$msg = '';
$ok  = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $pass  = $_POST['password'] ?? '';
    $pass2 = $_POST['password2'] ?? '';
    $name  = trim($_POST['name'] ?? 'Super Admin');

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $msg = 'Please enter a valid email address.';
    } elseif (strlen($pass) < 8) {
        $msg = 'Password must be at least 8 characters.';
    } elseif ($pass !== $pass2) {
        $msg = 'Passwords do not match.';
    } else {
        $hash = password_hash($pass, PASSWORD_BCRYPT);
        $stmt = db()->prepare("SELECT COUNT(*) FROM admin_users WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->fetchColumn() > 0) {
            db()->prepare("UPDATE admin_users SET name=?, password=?, role='super_admin' WHERE email=?")
                ->execute([$name, $hash, $email]);
        } else {
            db()->prepare("INSERT INTO admin_users (name, email, password, role) VALUES (?,?,?,'super_admin')")
                ->execute([$name, $email, $hash]);
        }
        $ok  = true;
        $msg = 'Admin account created. <strong>Delete this file (setup.php) now!</strong>';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Orbit Cloud Admin Setup</title>
<style>
  body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;background:#0B1E3D;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0}
  .box{background:#fff;border-radius:12px;padding:36px;width:100%;max-width:420px;box-shadow:0 20px 60px rgba(0,0,0,.4)}
  h1{margin:0 0 6px;font-size:20px}p.sub{color:#64748b;font-size:13px;margin:0 0 24px}
  label{display:block;font-size:13px;font-weight:500;margin-bottom:5px}
  input{width:100%;box-sizing:border-box;padding:9px 12px;border:1px solid #e2e8f0;border-radius:6px;font-size:14px;margin-bottom:14px;outline:none}
  input:focus{border-color:#1A8A45;box-shadow:0 0 0 3px rgba(26,138,69,.1)}
  button{width:100%;padding:10px;background:#1A8A45;color:#fff;border:none;border-radius:6px;font-size:14px;font-weight:600;cursor:pointer}
  button:hover{background:#146b35}
  .alert{padding:12px 14px;border-radius:6px;margin-bottom:16px;font-size:13px}
  .alert-ok  {background:#dcfce7;color:#166534}
  .alert-err {background:#fee2e2;color:#b91c1c}
  .warning{background:#fef9c3;border-radius:6px;padding:10px 14px;font-size:12px;color:#92400e;margin-bottom:20px}
</style>
</head>
<body>
<div class="box">
  <h1>Orbit Cloud Admin Setup</h1>
  <p class="sub">Create your administrator account to get started.</p>
  <div class="warning">⚠ Delete this file immediately after setup — it is a security risk if left accessible.</div>

  <?php if ($msg): ?>
    <div class="alert <?php echo $ok ? 'alert-ok' : 'alert-err'; ?>"><?php echo $msg; ?></div>
  <?php endif; ?>

  <?php if (!$ok): ?>
  <form method="POST">
    <label>Your Name</label>
    <input type="text" name="name" value="<?php echo htmlspecialchars($_POST['name'] ?? ''); ?>" placeholder="Super Admin" />
    <label>Email Address</label>
    <input type="email" name="email" value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>" placeholder="admin@orbitcloud.com" required />
    <label>Password (min 8 chars)</label>
    <input type="password" name="password" placeholder="••••••••" required />
    <label>Confirm Password</label>
    <input type="password" name="password2" placeholder="••••••••" required />
    <button type="submit">Create Admin Account</button>
  </form>
  <?php else: ?>
    <a href="<?php echo APP_URL; ?>/login.php" style="display:block;text-align:center;margin-top:12px;color:#1A8A45;font-weight:600">Go to Login →</a>
  <?php endif; ?>
</div>
</body>
</html>
