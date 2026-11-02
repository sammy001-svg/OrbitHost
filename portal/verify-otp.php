<?php
/**
 * Orbit Cloud — client sign-in, step 2: the code sent by email + SMS.
 * See admin/includes/LoginOtp.php.
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once dirname(__DIR__) . '/admin/includes/SiteSettings.php';
require_once dirname(__DIR__) . '/admin/includes/LoginOtp.php';

portal_start();

if (!empty($_SESSION['client_id'])) {
    header('Location: ' . PORTAL_URL . '/' . portal_after_auth());
    exit;
}
$pending = (int) ($_SESSION['client_otp_pending_id'] ?? 0);
if (!$pending) {
    header('Location: ' . PORTAL_URL . '/login.php');
    exit;
}

$error  = '';
$notice = (string) ($_SESSION['client_otp_notice'] ?? '');
unset($_SESSION['client_otp_notice']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    portal_csrf_verify();
    if (($_POST['action'] ?? '') === 'resend') {
        $r = portal_resend_otp();
        $r['ok'] ? $notice = $r['message'] : $error = $r['message'];
    } else {
        $code = trim($_POST['code'] ?? '');
        if ($code === '') {
            $error = 'Enter the 6-digit code we sent you.';
        } else {
            $r = portal_verify_otp($code);
            if (!empty($r['ok'])) {
                header('Location: ' . PORTAL_URL . '/' . portal_after_auth());
                exit;
            }
            $error = $r['message'] ?? 'Incorrect code.';
        }
    }
}

$sent_to = LoginOtp::lastChannels('client', $pending);
$s = db()->prepare("SELECT GREATEST(0, ? - TIMESTAMPDIFF(SECOND, created_at, NOW())) FROM login_otps WHERE audience = 'client' AND user_id = ? ORDER BY id DESC LIMIT 1");
$s->execute([LoginOtp::RESEND_AFTER, $pending]);
$wait  = (int) $s->fetchColumn();
$brand = SiteSettings::brandName() ?: 'Orbit Cloud';
?>
<!DOCTYPE html>
<html lang="en"<?php $_t = $_COOKIE['orbit_portal_theme'] ?? ''; echo in_array($_t, ['light', 'dark'], true) ? ' data-theme="' . $_t . '"' : ''; ?>>
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta name="robots" content="noindex" />
  <title>Verify sign-in — <?php echo htmlspecialchars($brand); ?></title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" crossorigin="anonymous" />
  <link rel="stylesheet" href="<?php echo PORTAL_URL; ?>/css/portal.css" />
  <style>
    body { background: var(--navy); min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 28px 16px; color: var(--text); }
    .otp-wrap { width: 100%; max-width: 420px; }
    .otp-brand { text-align: center; margin-bottom: 22px; }
    .otp-orb { width: 56px; height: 56px; background: var(--green); border-radius: 16px; display: flex; align-items: center; justify-content: center; font-size: 22px; color: #fff; margin: 0 auto 12px; }
    .otp-brand h1 { color: #fff; font-size: 20px; font-weight: 700; }
    .otp-brand p { color: rgba(255,255,255,.78); font-size: 13px; margin-top: 3px; }
    .otp-card { background: var(--surface); color: var(--text); border: 1px solid var(--border); border-radius: 16px; padding: 30px 28px; box-shadow: 0 24px 64px rgba(0,0,0,.3); }
    .otp-card h2 { font-size: 18px; font-weight: 700; color: var(--text); }
    .otp-sub { font-size: 13px; color: var(--text-muted); margin: 5px 0 18px; line-height: 1.55; }
    .otp-sub strong { color: var(--text); }
    .code-input { text-align: center; font-size: 28px; letter-spacing: 10px; font-family: ui-monospace, Menlo, monospace; padding: 12px; }
    .otp-foot { display: flex; justify-content: space-between; align-items: center; gap: 10px; flex-wrap: wrap; margin-top: 16px; font-size: 12.5px; color: var(--text-muted); }
    .linkish { background: none; border: 0; padding: 0; color: var(--green); font-weight: 600; cursor: pointer; font-size: 12.5px; font-family: inherit; }
    .linkish:disabled { color: var(--text-muted); cursor: default; font-weight: 400; }
    .otp-back { display: block; text-align: center; margin-top: 16px; font-size: 12.5px; color: rgba(255,255,255,.78); }
  </style>
</head>
<body>
<div class="otp-wrap">
  <div class="otp-brand">
    <div class="otp-orb"><i class="fas fa-key"></i></div>
    <h1><?php echo htmlspecialchars($brand); ?></h1>
    <p>Client Portal — sign-in verification</p>
  </div>
  <div class="otp-card">
    <h2>Check your email and phone</h2>
    <p class="otp-sub">
      <?php if ($sent_to): ?>
        We sent a 6-digit code to <strong><?php echo implode('</strong> and <strong>', array_map('htmlspecialchars', $sent_to)); ?></strong>.
      <?php else: ?>
        We couldn't deliver a code yet — use <em>Resend code</em> below.
      <?php endif; ?>
      It expires in <?php echo LoginOtp::TTL_MINUTES; ?> minutes.
    </p>

    <?php if ($error): ?>
      <div class="p-alert p-alert-error" role="alert"><i class="fas fa-circle-exclamation"></i> <?php echo htmlspecialchars($error); ?></div>
    <?php elseif ($notice): ?>
      <div class="p-alert <?php echo $sent_to ? 'p-alert-success' : 'p-alert-error'; ?>" role="status"><i class="fas <?php echo $sent_to ? 'fa-paper-plane' : 'fa-circle-exclamation'; ?>"></i> <?php echo htmlspecialchars($notice); ?></div>
    <?php endif; ?>

    <form method="POST" id="otpForm">
      <input type="hidden" name="csrf_token" value="<?php echo portal_csrf(); ?>" />
      <div class="form-group">
        <label class="form-label" for="code">Sign-in code</label>
        <input type="text" id="code" name="code" class="form-control code-input" placeholder="••••••" inputmode="numeric" pattern="[0-9 ]*"
               autocomplete="one-time-code" maxlength="7" required autofocus />
      </div>
      <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center;padding:12px;font-weight:700"><i class="fas fa-check"></i> Verify &amp; sign in</button>
    </form>

    <div class="otp-foot">
      <span>Didn't get it? Check spam, or</span>
      <form method="POST" style="margin:0">
        <input type="hidden" name="csrf_token" value="<?php echo portal_csrf(); ?>" />
        <input type="hidden" name="action" value="resend" />
        <button type="submit" class="linkish" id="resendBtn" data-wait="<?php echo $wait; ?>"><?php echo $wait > 0 ? 'Resend in ' . $wait . 's' : 'Resend code'; ?></button>
      </form>
    </div>
  </div>
  <a href="<?php echo PORTAL_URL; ?>/login.php?restart=1" class="otp-back">← Use a different account</a>
</div>
<script>
(function () {
  var code = document.getElementById('code'), form = document.getElementById('otpForm');
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
