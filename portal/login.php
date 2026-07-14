<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';

portal_start();

// Where to land after auth (e.g. checkout) — whitelist of portal pages only
$next = $_GET['next'] ?? '';
if ($next === 'checkout') { $_SESSION['post_login_redirect'] = 'checkout.php'; }
function portal_after_auth(): string
{
    $to = $_SESSION['post_login_redirect'] ?? 'dashboard.php';
    unset($_SESSION['post_login_redirect']);
    return in_array($to, ['checkout.php', 'cart.php', 'dashboard.php', 'domains.php'], true) ? $to : 'dashboard.php';
}

if (!empty($_SESSION['client_id'])) { header('Location: ' . PORTAL_URL . '/' . portal_after_auth()); exit; }

$error   = '';
$timeout = isset($_GET['timeout']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email']    ?? '');
    $pass  =      $_POST['password'] ?? '';
    if (!$email || !$pass) {
        $error = 'Please enter your email and password.';
    } else {
        $r = portal_login($email, $pass);
        if (!empty($r['ok'])) {
            header('Location: ' . PORTAL_URL . '/' . portal_after_auth());
            exit;
        }
        $error = $r['message'] ?? 'Invalid email or password.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Client Portal — Orbit Cloud</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" crossorigin="anonymous" />
  <link rel="stylesheet" href="<?php echo PORTAL_URL; ?>/css/portal.css" />
  <style>
    body { background: var(--navy); align-items: center; justify-content: center; display: flex; min-height: 100vh; }
    .auth-wrap { width: 100%; max-width: 440px; padding: 20px; }
    .auth-brand { text-align: center; margin-bottom: 28px; }
    .auth-orb {
      width: 60px; height: 60px; background: var(--green); border-radius: 16px;
      display: flex; align-items: center; justify-content: center; font-size: 26px; font-weight: 800; color: #fff;
      margin: 0 auto 14px;
    }
    .auth-brand h1 { color: #fff; font-size: 22px; font-weight: 700; margin-bottom: 4px; }
    .auth-brand p  { color: rgba(255,255,255,.45); font-size: 13px; }
    .auth-card { background: #fff; border-radius: 16px; padding: 34px 32px; box-shadow: 0 24px 64px rgba(0,0,0,.3); }
    .auth-card h2  { font-size: 18px; font-weight: 700; margin-bottom: 3px; }
    .auth-card .sub { font-size: 13px; color: var(--text-muted); margin-bottom: 22px; }
    .auth-error   { background: #fee2e2; color: #991b1b; padding: 11px 14px; border-radius: 7px; font-size: 13px; margin-bottom: 16px; }
    .auth-timeout { background: #fffbeb; color: #92400e; padding: 11px 14px; border-radius: 7px; font-size: 13px; margin-bottom: 16px; }
    .auth-card .form-group { margin-bottom: 16px; }
    .btn-login { width: 100%; justify-content: center; padding: 11px; font-size: 14px; font-weight: 600; margin-top: 6px; }
    .auth-footer { display: flex; justify-content: space-between; margin-top: 16px; font-size: 12.5px; }
    .auth-footer a { color: rgba(255,255,255,.5); text-decoration: none; }
    .auth-footer a:hover { color: rgba(255,255,255,.9); }
    .register-link { text-align: center; margin-top: 18px; padding-top: 18px; border-top: 1px solid var(--border); font-size: 13px; }
    .register-link a { color: var(--green); font-weight: 600; }
  </style>
</head>
<body>
<div class="auth-wrap">
  <div class="auth-brand">
    <div class="auth-orb">O</div>
    <h1>Orbit Cloud</h1>
    <p>Client Portal</p>
  </div>
  <div class="auth-card">
    <h2>Sign in to your account</h2>
    <p class="sub">Manage your services, invoices, and support</p>

    <?php if ($timeout): ?><div class="auth-timeout"><i class="fas fa-clock"></i> Your session expired. Please sign in again.</div><?php endif; ?>
    <?php if ($error):   ?><div class="auth-error"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?></div><?php endif; ?>

    <form method="POST">
      <div class="form-group">
        <label class="form-label">Email Address</label>
        <input type="email" name="email" class="form-control"
               value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>"
               placeholder="you@example.com" required autofocus autocomplete="email" />
      </div>
      <div class="form-group">
        <label class="form-label">Password</label>
        <input type="password" name="password" class="form-control"
               placeholder="••••••••" required autocomplete="current-password" />
      </div>
      <button type="submit" class="btn btn-primary btn-login">
        <i class="fas fa-sign-in-alt"></i> Sign In
      </button>
    </form>

    <div class="register-link">
      Don't have an account? <a href="<?php echo PORTAL_URL; ?>/register.php">Create one →</a>
    </div>
  </div>
  <div class="auth-footer">
    <a href="<?php echo PORTAL_URL; ?>/forgot-password.php">Forgot password?</a>
    <a href="../index.html">← Back to website</a>
  </div>
</div>
</body>
</html>
