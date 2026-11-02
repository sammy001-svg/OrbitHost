<?php
/**
 * Orbit Cloud — admin sign-in, step 2: the code sent by email + SMS.
 * See includes/LoginOtp.php.
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/LoginOtp.php';

auth_start();

if (!empty($_SESSION['admin_id'])) {
    header('Location: ' . APP_URL . '/dashboard.php');
    exit;
}
$pending = (int) ($_SESSION['admin_otp_pending_id'] ?? 0);
if (!$pending) {
    header('Location: ' . APP_URL . '/login.php');
    exit;
}

$error  = '';
$notice = (string) ($_SESSION['admin_otp_notice'] ?? '');
unset($_SESSION['admin_otp_notice']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    if (($_POST['action'] ?? '') === 'resend') {
        $r = auth_resend_otp();
        $r['ok'] ? $notice = $r['message'] : $error = $r['message'];
    } else {
        $code = trim($_POST['code'] ?? '');
        if ($code === '') {
            $error = 'Enter the 6-digit code we sent you.';
        } else {
            $r = auth_verify_otp($code);
            if (!empty($r['ok'])) {
                header('Location: ' . APP_URL . '/dashboard.php');
                exit;
            }
            $error = $r['message'] ?? 'Incorrect code.';
        }
    }
}

$sent_to = LoginOtp::lastChannels('admin', $pending);
// Seconds until "Resend" unlocks, from the newest code's age.
$s = db()->prepare("SELECT GREATEST(0, ? - TIMESTAMPDIFF(SECOND, created_at, NOW())) FROM login_otps WHERE audience = 'admin' AND user_id = ? ORDER BY id DESC LIMIT 1");
$s->execute([LoginOtp::RESEND_AFTER, $pending]);
$wait = (int) $s->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en"<?php echo ($_COOKIE['orbit_admin_theme'] ?? '') === 'light' ? ' data-theme="light"' : ''; ?>>
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Verify sign-in — <?php echo APP_NAME; ?></title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" crossorigin="anonymous" />
  <link rel="stylesheet" href="<?php echo APP_URL; ?>/css/admin.css" />
  <style>
    body { background: var(--navy); align-items: center; justify-content: center; display: flex; min-height: 100vh; padding: 20px; }
    .login-page { width: 100%; max-width: 420px; }
    .login-brand { text-align: center; margin-bottom: 26px; }
    .login-orb { width: 58px; height: 58px; background: var(--green); border-radius: 16px; display: flex; align-items: center; justify-content: center; font-size: 24px; color: #fff; margin: 0 auto 14px; }
    .login-brand h1 { color: #fff; font-size: 22px; font-weight: 700; margin-bottom: 4px; }
    .login-brand p  { color: rgba(255,255,255,.78); font-size: 13px; }
    .login-card { background: var(--surface); color: var(--text); border: 1px solid var(--border); border-radius: 16px; padding: 32px 30px; box-shadow: 0 24px 64px rgba(0,0,0,.35); }
    .login-card h2 { font-size: 18px; font-weight: 700; margin-bottom: 4px; }
    .login-sub { font-size: 13px; color: var(--text-muted); margin-bottom: 18px; line-height: 1.55; }
    .login-sub strong { color: var(--text); }
    .msg { padding: 11px 14px; border-radius: 7px; font-size: 13px; margin-bottom: 16px; border-left: 3px solid; }
    .msg.err { background: var(--danger-bg); color: var(--danger-fg); border-color: var(--danger); }
    .msg.ok  { background: var(--success-bg, rgba(46,167,90,.12)); color: var(--success-fg, var(--green)); border-color: var(--green); }
    .code-input { text-align: center; font-size: 28px; letter-spacing: 10px; font-family: ui-monospace, Menlo, monospace; padding: 12px; }
    .btn-login { width: 100%; justify-content: center; padding: 11px; font-size: 14px; font-weight: 600; margin-top: 6px; }
    .otp-foot { display: flex; justify-content: space-between; align-items: center; margin-top: 16px; font-size: 12.5px; color: var(--text-muted); gap: 10px; flex-wrap: wrap; }
    .linkish { background: none; border: 0; padding: 0; color: var(--green); font-weight: 600; cursor: pointer; font-size: 12.5px; }
    .linkish:disabled { color: var(--text-muted); cursor: default; font-weight: 400; }
    .back-link { display: block; text-align: center; margin-top: 16px; font-size: 12.5px; color: rgba(255,255,255,.78); text-decoration: none; }
  </style>
</head>
<body>
<div class="login-page">
  <div class="login-brand">
    <div class="login-orb"><i class="fas fa-key"></i></div>
    <h1>Orbit Cloud</h1>
    <p>Admin sign-in verification</p>
  </div>
  <div class="login-card">
    <h2>Check your email and phone</h2>
    <p class="login-sub">
      <?php if ($sent_to): ?>
        We sent a 6-digit code to <strong><?php echo implode('</strong> and <strong>', array_map('htmlspecialchars', $sent_to)); ?></strong>.
      <?php else: ?>
        We couldn't deliver a code yet — use <em>Resend code</em> below.
      <?php endif; ?>
      It expires in <?php echo LoginOtp::TTL_MINUTES; ?> minutes.
    </p>

    <?php if ($error): ?><div class="msg err" role="alert"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?></div><?php endif; ?>
    <?php if ($notice && !$error): ?><div class="msg <?php echo $sent_to ? 'ok' : 'err'; ?>" role="status"><i class="fas <?php echo $sent_to ? 'fa-paper-plane' : 'fa-exclamation-circle'; ?>"></i> <?php echo htmlspecialchars($notice); ?></div><?php endif; ?>

    <form method="POST" id="otpForm">
      <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>" />
      <div class="form-group">
        <label class="form-label" for="code">Sign-in code</label>
        <input type="text" id="code" name="code" class="form-control code-input" placeholder="••••••" inputmode="numeric" pattern="[0-9 ]*"
               autocomplete="one-time-code" maxlength="7" required autofocus />
      </div>
      <button type="submit" class="btn btn-primary btn-login"><i class="fas fa-check"></i> Verify &amp; sign in</button>
    </form>

    <div class="otp-foot">
      <span>Didn't get it? Check spam, or</span>
      <form method="POST" style="margin:0">
        <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>" />
        <input type="hidden" name="action" value="resend" />
        <button type="submit" class="linkish" id="resendBtn" data-wait="<?php echo $wait; ?>"><?php echo $wait > 0 ? 'Resend in ' . $wait . 's' : 'Resend code'; ?></button>
      </form>
    </div>
  </div>
  <a href="<?php echo APP_URL; ?>/login.php?restart=1" class="back-link">← Use a different account</a>
</div>
<script>
(function () {
  var code = document.getElementById('code'), form = document.getElementById('otpForm');
  // Digits only; submit automatically once all six are in (paste or type).
  code.addEventListener('input', function () {
    code.value = code.value.replace(/\D/g, '').slice(0, 6);
    if (code.value.length === 6 && !form.dataset.sent) { form.dataset.sent = '1'; form.submit(); }
  });
  var btn = document.getElementById('resendBtn'), wait = parseInt(btn.dataset.wait, 10) || 0;
  if (wait > 0) {
    btn.disabled = true;
    var t = setInterval(function () {
      wait--;
      if (wait <= 0) { clearInterval(t); btn.disabled = false; btn.textContent = 'Resend code'; }
      else btn.textContent = 'Resend in ' + wait + 's';
    }, 1000);
  }
})();
</script>
</body>
</html>
