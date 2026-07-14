<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';

auth_start();

if (!empty($_SESSION['admin_id'])) {
    header('Location: ' . APP_URL . '/dashboard.php');
    exit;
}
if (empty($_SESSION['admin_2fa_pending_id'])) {
    header('Location: ' . APP_URL . '/login.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $code = trim($_POST['code'] ?? '');
    if (!$code) {
        $error = 'Enter the 6-digit code from your authenticator app.';
    } else {
        $r = auth_verify_2fa($code);
        if (!empty($r['ok'])) {
            header('Location: ' . APP_URL . '/dashboard.php');
            exit;
        }
        $error = $r['message'] ?? 'Incorrect code.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Verify — <?php echo APP_NAME; ?></title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" crossorigin="anonymous" />
  <link rel="stylesheet" href="<?php echo APP_URL; ?>/css/admin.css" />
  <style>
    body { background: var(--navy); align-items: center; justify-content: center; display: flex; min-height: 100vh; }
    .login-page { width: 100%; max-width: 420px; padding: 20px; }
    .login-brand { text-align: center; margin-bottom: 28px; }
    .login-orb { width: 58px; height: 58px; background: var(--green); border-radius: 16px; display: flex; align-items: center; justify-content: center; font-size: 24px; color: #fff; margin: 0 auto 14px; }
    .login-brand h1 { color: #fff; font-size: 22px; font-weight: 700; margin-bottom: 4px; }
    .login-brand p  { color: rgba(255,255,255,.45); font-size: 13px; }
    .login-card { background: #fff; border-radius: 16px; padding: 34px 32px; box-shadow: 0 24px 64px rgba(0,0,0,.35); }
    .login-card h2 { font-size: 18px; font-weight: 700; margin-bottom: 3px; }
    .login-sub { font-size: 13px; color: var(--text-muted); margin-bottom: 22px; }
    .login-error { background: var(--danger-bg); color: #991b1b; padding: 11px 14px; border-radius: 7px; font-size: 13px; margin-bottom: 16px; border-left: 3px solid var(--danger); }
    .code-input { text-align: center; font-size: 26px; letter-spacing: 8px; font-family: ui-monospace, Menlo, monospace; }
    .btn-login { width: 100%; justify-content: center; padding: 11px; font-size: 14px; font-weight: 600; margin-top: 6px; }
    .back-link { display: block; text-align: center; margin-top: 16px; font-size: 12.5px; color: rgba(255,255,255,.4); text-decoration: none; }
  </style>
</head>
<body>
<div class="login-page">
  <div class="login-brand">
    <div class="login-orb"><i class="fas fa-shield-halved"></i></div>
    <h1>Orbit Cloud</h1>
    <p>Two-Factor Verification</p>
  </div>
  <div class="login-card">
    <h2>Enter your code</h2>
    <p class="login-sub">Open your authenticator app and enter the current 6-digit code, or use a backup code.</p>

    <?php if ($error): ?><div class="login-error"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?></div><?php endif; ?>

    <form method="POST">
      <div class="form-group">
        <input type="text" name="code" class="form-control code-input" placeholder="000000" inputmode="numeric" autocomplete="one-time-code" maxlength="10" required autofocus />
      </div>
      <button type="submit" class="btn btn-primary btn-login"><i class="fas fa-check"></i> Verify</button>
    </form>
  </div>
  <a href="<?php echo APP_URL; ?>/login.php" class="back-link">← Back to sign in</a>
</div>
</body>
</html>
