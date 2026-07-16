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
    .auth-wrap { width: 100%; max-width: 420px; padding: 20px; }
    .auth-brand { text-align: center; margin-bottom: 28px; }
    .auth-orb {
      width: 60px; height: 60px; background: var(--green); border-radius: 16px;
      display: flex; align-items: center; justify-content: center; font-size: 26px; font-weight: 800; color: #fff;
      margin: 0 auto 14px;
    }
    .auth-brand h1 { color: var(--navy); font-size: 22px; font-weight: 700; margin-bottom: 4px; }
    .auth-brand p  { color: var(--text-muted); font-size: 13px; }
    .auth-card { padding: 0; }
    .auth-card h2  { font-size: 18px; font-weight: 700; margin-bottom: 3px; }
    .auth-card .sub { font-size: 13px; color: var(--text-muted); margin-bottom: 22px; }
    .auth-error   { background: #fee2e2; color: #991b1b; padding: 11px 14px; border-radius: 7px; font-size: 13px; margin-bottom: 16px; }
    .auth-timeout { background: #fffbeb; color: #92400e; padding: 11px 14px; border-radius: 7px; font-size: 13px; margin-bottom: 16px; }
    .auth-card .form-group { margin-bottom: 16px; }
    .btn-login { width: 100%; justify-content: center; padding: 11px; font-size: 14px; font-weight: 600; margin-top: 6px; }
    .auth-footer { display: flex; justify-content: space-between; margin-top: 16px; font-size: 12.5px; }
    .auth-footer a { color: var(--text-muted); text-decoration: none; }
    .auth-footer a:hover { color: var(--navy); }
    .register-link { text-align: center; margin-top: 18px; padding-top: 18px; border-top: 1px solid var(--border); font-size: 13px; }
    .register-link a { color: var(--green); font-weight: 600; }

    @media (max-width: 900px) {
      .auth-visual { display: none; }
      .auth-form-side { flex: 1 1 100%; }
    }
  </style>
</head>
<body>
<div class="auth-split">
  <div class="auth-visual">
    <div class="auth-slide active" style="background-image:url('https://picsum.photos/seed/orbithost-portal-1/1200/1600')">
      <div class="auth-slide-copy">
        <h2>99.9% Uptime Guaranteed</h2>
        <p>Enterprise-grade infrastructure, monitored around the clock, so your site never sleeps.</p>
      </div>
    </div>
    <div class="auth-slide" style="background-image:url('https://picsum.photos/seed/orbithost-portal-2/1200/1600')">
      <div class="auth-slide-copy">
        <h2>Bank-Grade Security</h2>
        <p>Free SSL, daily backups, and proactive protection included on every plan.</p>
      </div>
    </div>
    <div class="auth-slide" style="background-image:url('https://picsum.photos/seed/orbithost-portal-3/1200/1600')">
      <div class="auth-slide-copy">
        <h2>Live in Minutes</h2>
        <p>Order hosting or a domain and go live fast — priced in KSh or USD, whichever you prefer.</p>
      </div>
    </div>
    <div class="auth-slide" style="background-image:url('https://picsum.photos/seed/orbithost-portal-4/1200/1600')">
      <div class="auth-slide-copy">
        <h2>Real Humans, 24/7</h2>
        <p>Tickets, live chat, and a full knowledge base — help is always one click away.</p>
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
