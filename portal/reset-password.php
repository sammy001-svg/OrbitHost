<?php
require_once '../admin/includes/config.php';
require_once '../admin/includes/db.php';

session_name('orbit_portal');
session_start();

if (isset($_SESSION['portal_client_id'])) {
    header('Location: /portal/dashboard.php');
    exit;
}

$PORTAL_URL = (function () {
    $prot = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $root = rtrim(str_replace('\\', '/', dirname(__DIR__)), '/');
    $doc  = rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
    $rel  = str_replace($doc, '', $root);
    return $prot . '://' . $host . $rel . '/portal';
})();

$token  = trim($_GET['token'] ?? $_POST['token'] ?? '');
$errors = [];
$done   = false;
$client = null;

if ($token) {
    $stmt = db()->prepare('SELECT id, first_name FROM clients WHERE reset_token=? AND reset_expires > NOW()');
    $stmt->execute([$token]);
    $client = $stmt->fetch();
}

if (!$client && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $invalid_token = true;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $client) {
    $pass  = $_POST['password']  ?? '';
    $pass2 = $_POST['password2'] ?? '';

    if (strlen($pass) < 8) {
        $errors[] = 'Password must be at least 8 characters.';
    } elseif ($pass !== $pass2) {
        $errors[] = 'Passwords do not match.';
    } else {
        $hash = password_hash($pass, PASSWORD_BCRYPT);
        db()->prepare('UPDATE clients SET portal_password=?, reset_token=NULL, reset_expires=NULL WHERE id=?')
            ->execute([$hash, $client['id']]);
        $done = true;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Reset Password — OrbitHost Portal</title>
  <link rel="stylesheet" href="<?php echo $PORTAL_URL; ?>/css/portal.css" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" crossorigin="anonymous" />
</head>
<body>
<div class="auth-wrap">
  <div class="auth-card">
    <a href="<?php echo $PORTAL_URL; ?>/login.php" class="auth-logo">Orbit<span>Host</span></a>

    <?php if ($done): ?>
      <div style="text-align:center">
        <i class="fas fa-circle-check" style="font-size:46px;color:var(--green);margin-bottom:14px"></i>
        <h2 style="margin-bottom:6px">Password Updated</h2>
        <p style="color:var(--text-muted);font-size:14px;margin-bottom:24px">Your portal password has been reset. You can now sign in with your new password.</p>
        <a href="<?php echo $PORTAL_URL; ?>/login.php" class="btn btn-primary" style="width:100%;justify-content:center">Sign In Now</a>
      </div>

    <?php elseif (!empty($invalid_token) || (!$client && $token)): ?>
      <div style="text-align:center">
        <i class="fas fa-link-slash" style="font-size:42px;color:var(--danger);margin-bottom:14px"></i>
        <h2 style="margin-bottom:6px">Link Expired</h2>
        <p style="color:var(--text-muted);font-size:14px;margin-bottom:24px">This reset link is invalid or has expired. Please request a new one.</p>
        <a href="<?php echo $PORTAL_URL; ?>/forgot-password.php" class="btn btn-primary" style="width:100%;justify-content:center">Request New Link</a>
      </div>

    <?php elseif (!$token): ?>
      <div style="text-align:center">
        <p style="color:var(--text-muted);font-size:14px;margin-bottom:16px">No reset token provided.</p>
        <a href="<?php echo $PORTAL_URL; ?>/forgot-password.php" class="btn btn-primary" style="width:100%;justify-content:center">Forgot Password</a>
      </div>

    <?php else: ?>
      <h2 style="text-align:center;margin-bottom:6px">Set New Password</h2>
      <p style="text-align:center;color:var(--text-muted);font-size:14px;margin-bottom:24px">Hi <?php echo htmlspecialchars($client['first_name']); ?>, choose a strong password.</p>

      <?php if ($errors): ?>
        <div class="p-alert p-alert-error" style="margin-bottom:14px"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($errors[0]); ?></div>
      <?php endif; ?>

      <form method="POST">
        <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>" />
        <div class="form-group">
          <label class="form-label">New Password</label>
          <input type="password" name="password" id="new_password" class="form-control" placeholder="At least 8 characters" required />
          <div id="strengthBar" style="height:3px;border-radius:2px;margin-top:6px;width:0;transition:.3s"></div>
        </div>
        <div class="form-group">
          <label class="form-label">Confirm Password</label>
          <input type="password" name="password2" class="form-control" placeholder="Repeat password" required />
        </div>
        <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center">
          <i class="fas fa-key"></i> Set New Password
        </button>
      </form>
      <script>
      document.getElementById('new_password').addEventListener('input', function () {
        var v = this.value, s = document.getElementById('strengthBar');
        var score = (v.length >= 8 ? 1 : 0) + (/[A-Z]/.test(v) ? 1 : 0) + (/[0-9]/.test(v) ? 1 : 0) + (/[^A-Za-z0-9]/.test(v) ? 1 : 0);
        var w = [0, 25, 50, 75, 100][score];
        var c = ['', '#ef4444', '#f59e0b', '#3b82f6', '#22c55e'][score];
        s.style.width = w + '%';
        s.style.background = c;
      });
      </script>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
