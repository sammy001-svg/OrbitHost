<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';

auth_start();

if (!empty($_SESSION['admin_id'])) {
    header('Location: ' . APP_URL . '/dashboard.php');
    exit;
}

$error   = '';
$timeout = isset($_GET['timeout']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['email']    ?? '');
    $password =      $_POST['password'] ?? '';

    if (!$email || !$password) {
        $error = 'Please enter your email and password.';
    } elseif (auth_login($email, $password)) {
        header('Location: ' . APP_URL . '/dashboard.php');
        exit;
    } else {
        $error = 'Invalid credentials. Please check your email and password.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Sign In — <?php echo APP_NAME; ?></title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" crossorigin="anonymous" />
  <link rel="stylesheet" href="<?php echo APP_URL; ?>/css/admin.css" />
  <style>
    body { background: var(--navy); align-items: center; justify-content: center; display: flex; min-height: 100vh; }

    .login-page { width: 100%; max-width: 420px; padding: 20px; }

    .login-brand { text-align: center; margin-bottom: 28px; }
    .login-orb {
      width: 58px; height: 58px;
      background: var(--green);
      border-radius: 16px;
      display: flex; align-items: center; justify-content: center;
      font-size: 24px; font-weight: 800; color: #fff;
      margin: 0 auto 14px;
    }
    .login-brand h1 { color: #fff; font-size: 22px; font-weight: 700; margin-bottom: 4px; }
    .login-brand p  { color: rgba(255,255,255,.45); font-size: 13px; }

    .login-card {
      background: #fff;
      border-radius: 16px;
      padding: 34px 32px;
      box-shadow: 0 24px 64px rgba(0,0,0,.35);
    }

    .login-card h2 { font-size: 18px; font-weight: 700; margin-bottom: 3px; }
    .login-sub     { font-size: 13px; color: var(--text-muted); margin-bottom: 22px; }

    .login-error   { background: var(--danger-bg); color: #991b1b; padding: 11px 14px; border-radius: 7px; font-size: 13px; margin-bottom: 16px; border-left: 3px solid var(--danger); }
    .login-timeout { background: var(--warning-bg); color: #92400e; padding: 11px 14px; border-radius: 7px; font-size: 13px; margin-bottom: 16px; border-left: 3px solid var(--warning); }

    .login-card .form-label { font-size: 13px; }
    .login-card .form-control { font-size: 14px; padding: 10px 12px; }
    .login-card .form-group { margin-bottom: 16px; }

    .btn-login { width: 100%; justify-content: center; padding: 11px; font-size: 14px; font-weight: 600; margin-top: 6px; }

    .back-link { display: block; text-align: center; margin-top: 16px; font-size: 12.5px; color: rgba(255,255,255,.4); text-decoration: none; }
    .back-link:hover { color: rgba(255,255,255,.8); }
  </style>
</head>
<body>
<div class="login-page">
  <div class="login-brand">
    <div class="login-orb">O</div>
    <h1>Orbit Cloud</h1>
    <p>Admin Control Panel</p>
  </div>

  <div class="login-card">
    <h2>Welcome back</h2>
    <p class="login-sub">Sign in to manage your hosting business</p>

    <?php if ($timeout): ?>
      <div class="login-timeout"><i class="fas fa-clock"></i> Your session expired. Please sign in again.</div>
    <?php endif; ?>

    <?php if ($error): ?>
      <div class="login-error"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <form method="POST" action="" autocomplete="on">
      <div class="form-group">
        <label class="form-label" for="email">Email Address</label>
        <input type="email" id="email" name="email" class="form-control"
               value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>"
               placeholder="admin@orbitcloud.com"
               required autofocus autocomplete="email" />
      </div>
      <div class="form-group">
        <label class="form-label" for="password">Password</label>
        <input type="password" id="password" name="password" class="form-control"
               placeholder="••••••••"
               required autocomplete="current-password" />
      </div>
      <button type="submit" class="btn btn-primary btn-login">
        <i class="fas fa-sign-in-alt"></i> Sign In
      </button>
    </form>
  </div>

  <a href="../index.html" class="back-link">← Back to Orbit Cloud website</a>
</div>
</body>
</html>
