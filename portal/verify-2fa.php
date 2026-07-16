<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once dirname(__DIR__) . '/admin/includes/SiteSettings.php';

portal_start();

if (!empty($_SESSION['client_id'])) {
    header('Location: ' . PORTAL_URL . '/' . portal_after_auth());
    exit;
}
if (empty($_SESSION['client_2fa_pending_id'])) {
    header('Location: ' . PORTAL_URL . '/login.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $code = trim($_POST['code'] ?? '');
    if (!$code) {
        $error = 'Enter the 6-digit code from your authenticator app.';
    } else {
        $r = portal_verify_2fa($code);
        if (!empty($r['ok'])) {
            header('Location: ' . PORTAL_URL . '/' . portal_after_auth());
            exit;
        }
        $error = $r['message'] ?? 'Incorrect code.';
    }
}

$_brand_logo = SiteSettings::logoImgTag(44, 170);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Verify — Orbit Cloud Client Portal</title>
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
    .auth-brand-card {
      display: inline-flex; align-items: center; gap: 12px;
      background: var(--navy); border-radius: 14px; padding: 16px 26px;
      margin-bottom: 14px;
    }
    .auth-orb {
      width: 44px; height: 44px; background: var(--green); border-radius: 12px;
      display: flex; align-items: center; justify-content: center; font-size: 20px; font-weight: 800; color: #fff;
      flex-shrink: 0;
    }
    .auth-brand-card h1 { color: #fff; font-size: 20px; font-weight: 700; }
    .auth-brand p { color: var(--text-muted); font-size: 13px; }
    .auth-card { padding: 0; }
    .auth-card h2  { font-size: 18px; font-weight: 700; margin-bottom: 3px; }
    .auth-card .sub { font-size: 13px; color: var(--text-muted); margin-bottom: 22px; }
    .auth-error { background: #fee2e2; color: #991b1b; padding: 11px 14px; border-radius: 7px; font-size: 13px; margin-bottom: 16px; }
    .code-input { text-align: center; font-size: 26px; letter-spacing: 8px; font-family: ui-monospace, Menlo, monospace; }
    .auth-card .form-group { margin-bottom: 16px; }
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
        <div class="auth-brand-card">
          <?php if ($_brand_logo): ?>
            <?php echo $_brand_logo; ?>
          <?php else: ?>
            <div class="auth-orb"><i class="fas fa-shield-halved"></i></div>
            <h1>Orbit Cloud</h1>
          <?php endif; ?>
        </div>
        <p>Two-Factor Verification</p>
      </div>
      <div class="auth-card">
        <h2>Enter your code</h2>
        <p class="sub">Open your authenticator app and enter the current 6-digit code, or use a backup code.</p>

        <?php if ($error): ?><div class="auth-error"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?></div><?php endif; ?>

        <form method="POST">
          <div class="form-group">
            <input type="text" name="code" class="form-control code-input" placeholder="000000" inputmode="numeric" autocomplete="one-time-code" maxlength="10" required autofocus />
          </div>
          <button type="submit" class="btn btn-primary btn-login"><i class="fas fa-check"></i> Verify</button>
        </form>
      </div>
      <a href="<?php echo PORTAL_URL; ?>/login.php" class="back-link">← Back to sign in</a>
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
