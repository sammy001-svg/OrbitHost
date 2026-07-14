<?php
/**
 * Orbit Cloud — email verification landing page.
 * Reached from the link in the "email_verification" notification email.
 * Deliberately does NOT require an active session — the link may be
 * opened on a different device/browser than the one used to register.
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once dirname(__DIR__) . '/admin/includes/functions.php';

portal_start();
ensure_client_verify_columns();

$token = trim($_GET['token'] ?? '');
$state = 'invalid';

if ($token !== '') {
    $stmt = db()->prepare('SELECT id, first_name, email_verified FROM clients WHERE verify_token = ? AND verify_expires > NOW()');
    $stmt->execute([$token]);
    $client = $stmt->fetch();

    if ($client) {
        if (!$client['email_verified']) {
            db()->prepare('UPDATE clients SET email_verified = 1, verify_token = NULL, verify_expires = NULL WHERE id = ?')
                ->execute([$client['id']]);
        }
        $state = 'verified';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Verify Email — Orbit Cloud</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" crossorigin="anonymous" />
  <link rel="stylesheet" href="<?php echo PORTAL_URL; ?>/css/portal.css" />
  <style>
    body { background: var(--navy); min-height: 100vh; padding: 32px 16px; display: flex; align-items: center; justify-content: center; }
    .vc-wrap { max-width: 440px; width: 100%; }
    .vc-card { background: #fff; border-radius: 16px; padding: 36px 32px; text-align: center; box-shadow: 0 20px 60px rgba(0,0,0,.3); }
    .vc-icon { font-size: 46px; margin-bottom: 14px; }
  </style>
</head>
<body>
<div class="vc-wrap">
  <div class="vc-card">
    <?php if ($state === 'verified'): ?>
      <div class="vc-icon" style="color:var(--green)"><i class="fas fa-circle-check"></i></div>
      <h1 style="font-size:19px;margin-bottom:8px">Email verified!</h1>
      <p style="color:var(--text-muted);font-size:14px;margin-bottom:22px">Thanks<?php echo isset($client['first_name']) ? ', ' . htmlspecialchars($client['first_name']) : ''; ?> — your email address is confirmed.</p>
      <a href="<?php echo PORTAL_URL; ?>/<?php echo !empty($_SESSION['client_id']) ? 'dashboard.php' : 'login.php'; ?>" class="btn btn-primary" style="width:100%;justify-content:center">
        <?php echo !empty($_SESSION['client_id']) ? 'Go to Dashboard' : 'Sign In'; ?>
      </a>
    <?php else: ?>
      <div class="vc-icon" style="color:var(--danger)"><i class="fas fa-circle-xmark"></i></div>
      <h1 style="font-size:19px;margin-bottom:8px">Link expired or invalid</h1>
      <p style="color:var(--text-muted);font-size:14px;margin-bottom:22px">This verification link is no longer valid. Sign in and we'll let you resend it.</p>
      <a href="<?php echo PORTAL_URL; ?>/login.php" class="btn btn-primary" style="width:100%;justify-content:center">Sign In</a>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
