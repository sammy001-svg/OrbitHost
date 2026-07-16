<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';

auth_start();

if (!empty($_SESSION['admin_id'])) {
    header('Location: ' . APP_URL . '/dashboard.php');
    exit;
}
if (!empty($_SESSION['admin_2fa_pending_id'])) {
    header('Location: ' . APP_URL . '/verify-2fa.php');
    exit;
}

$error   = '';
$timeout = isset($_GET['timeout']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['email']    ?? '');
    $password =      $_POST['password'] ?? '';

    if (!$email || !$password) {
        $error = 'Please enter your email and password.';
    } else {
        $r = auth_login($email, $password);
        if (!empty($r['needs_2fa'])) {
            header('Location: ' . APP_URL . '/verify-2fa.php');
            exit;
        } elseif (!empty($r['ok'])) {
            header('Location: ' . APP_URL . '/dashboard.php');
            exit;
        } else {
            $error = $r['message'] ?? 'Invalid credentials. Please check your email and password.';
        }
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
    html, body { height: 100%; }
    body { margin: 0; display: flex; min-height: 100vh; background: #fff; }

    .auth-split { display: flex; width: 100%; min-height: 100vh; }

    /* ── Visual / carousel panel ── */
    .auth-visual { position: relative; flex: 1 1 52%; overflow: hidden; background: var(--navy); }
    .auth-slide {
      position: absolute; inset: 0; opacity: 0;
      background-size: cover; background-position: center;
      transition: opacity 1.4s ease;
    }
    .auth-slide.active { opacity: 1; }
    .auth-slide::after {
      content: ''; position: absolute; inset: 0; z-index: 1;
      background: radial-gradient(ellipse at center, rgba(11,31,58,.68) 0%, rgba(11,31,58,.38) 100%);
    }
    .auth-slide-copy { position: absolute; inset: 0; z-index: 2; display: flex; flex-direction: column; align-items: center; justify-content: center; text-align: center; padding: 48px 64px; color: #fff; }
    .auth-slide-copy h2 { font-size: 42px; font-weight: 800; line-height: 1.2; margin-bottom: 18px; text-shadow: 0 2px 24px rgba(0,0,0,.4); }
    .auth-slide-copy p  { font-size: 18px; color: rgba(255,255,255,.9); max-width: 460px; line-height: 1.7; text-shadow: 0 1px 14px rgba(0,0,0,.35); }
    .auth-dots { position: absolute; left: 50%; bottom: 40px; transform: translateX(-50%); z-index: 3; display: flex; gap: 8px; }
    .auth-dot { width: 22px; height: 4px; border-radius: 2px; border: none; background: rgba(255,255,255,.35); cursor: pointer; padding: 0; transition: background .2s; }
    .auth-dot.active { background: var(--green); }

    /* ── Form panel ── */
    .auth-form-side { flex: 1 1 48%; display: flex; align-items: center; justify-content: center; padding: 40px 24px; overflow-y: auto; }
    .login-page { width: 100%; max-width: 400px; padding: 20px; }

    .login-brand { text-align: center; margin-bottom: 28px; }
    .login-orb {
      width: 58px; height: 58px;
      background: var(--green);
      border-radius: 16px;
      display: flex; align-items: center; justify-content: center;
      font-size: 24px; font-weight: 800; color: #fff;
      margin: 0 auto 14px;
    }
    .login-brand h1 { color: var(--navy); font-size: 22px; font-weight: 700; margin-bottom: 4px; }
    .login-brand p  { color: var(--text-muted); font-size: 13px; }

    .login-card { padding: 0; }

    .login-card h2 { font-size: 18px; font-weight: 700; margin-bottom: 3px; }
    .login-sub     { font-size: 13px; color: var(--text-muted); margin-bottom: 22px; }

    .login-error   { background: var(--danger-bg); color: #991b1b; padding: 11px 14px; border-radius: 7px; font-size: 13px; margin-bottom: 16px; border-left: 3px solid var(--danger); }
    .login-timeout { background: var(--warning-bg); color: #92400e; padding: 11px 14px; border-radius: 7px; font-size: 13px; margin-bottom: 16px; border-left: 3px solid var(--warning); }

    .login-card .form-label { font-size: 13px; }
    .login-card .form-control { font-size: 14px; padding: 10px 12px; }
    .login-card .form-group { margin-bottom: 16px; }

    .btn-login { width: 100%; justify-content: center; padding: 11px; font-size: 14px; font-weight: 600; margin-top: 6px; }

    .back-link { display: block; text-align: center; margin-top: 16px; font-size: 12.5px; color: var(--text-muted); text-decoration: none; }
    .back-link:hover { color: var(--navy); }

    @media (max-width: 900px) {
      .auth-visual { display: none; }
      .auth-form-side { flex: 1 1 100%; }
    }
  </style>
</head>
<body>
<div class="auth-split">
  <div class="auth-visual">
    <div class="auth-slide active" style="background-image:url('https://picsum.photos/seed/orbithost-admin-1/1200/1600')">
      <div class="auth-slide-copy">
        <h2>Everything in One Place</h2>
        <p>Clients, billing, tickets, domains, and servers — one console, zero guesswork.</p>
      </div>
    </div>
    <div class="auth-slide" style="background-image:url('https://picsum.photos/seed/orbithost-admin-2/1200/1600')">
      <div class="auth-slide-copy">
        <h2>Automate the Busy Work</h2>
        <p>Usage syncing, renewal reminders, and invoicing run themselves in the background.</p>
      </div>
    </div>
    <div class="auth-slide" style="background-image:url('https://picsum.photos/seed/orbithost-admin-3/1200/1600')">
      <div class="auth-slide-copy">
        <h2>Built-In Guardrails</h2>
        <p>Role-based access, a full audit log, and two-factor auth keep your team accountable.</p>
      </div>
    </div>
    <div class="auth-slide" style="background-image:url('https://picsum.photos/seed/orbithost-admin-4/1200/1600')">
      <div class="auth-slide-copy">
        <h2>Support Clients Faster</h2>
        <p>Every ticket, payment, and service update is a click away — no digging required.</p>
      </div>
    </div>
    <div class="auth-dots">
      <button type="button" class="auth-dot active" data-i="0" aria-label="Slide 1"></button>
      <button type="button" class="auth-dot" data-i="1" aria-label="Slide 2"></button>
      <button type="button" class="auth-dot" data-i="2" aria-label="Slide 3"></button>
      <button type="button" class="auth-dot" data-i="3" aria-label="Slide 4"></button>
    </div>
  </div>

  <div class="auth-form-side">
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
  </div>
</div>
<script>
(function () {
  var slides = document.querySelectorAll('.auth-slide');
  var dots   = document.querySelectorAll('.auth-dot');
  if (!slides.length) return;
  var i = 0, timer;
  function show(n) {
    slides.forEach(function (s, idx) { s.classList.toggle('active', idx === n); });
    dots.forEach(function (d, idx) { d.classList.toggle('active', idx === n); });
    i = n;
  }
  function restart() { clearInterval(timer); timer = setInterval(function () { show((i + 1) % slides.length); }, 6000); }
  dots.forEach(function (d) { d.addEventListener('click', function () { show(parseInt(d.dataset.i, 10)); restart(); }); });
  restart();
})();
</script>
</body>
</html>
