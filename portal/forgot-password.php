<?php
require_once '../admin/includes/config.php';
require_once '../admin/includes/db.php';

session_name('orbit_portal');
session_start();

if (isset($_SESSION['portal_client_id'])) {
    header('Location: ' . (defined('PORTAL_URL') ? PORTAL_URL : '/portal') . '/dashboard.php');
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

$sent   = false;
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim(strtolower($_POST['email'] ?? ''));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    } else {
        $client = db()->prepare('SELECT id, first_name FROM clients WHERE email=? AND portal_password IS NOT NULL');
        $client->execute([$email]);
        $client = $client->fetch();

        if ($client) {
            $token   = bin2hex(random_bytes(32));
            $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));
            db()->prepare('UPDATE clients SET reset_token=?, reset_expires=? WHERE id=?')
                ->execute([$token, $expires, $client['id']]);

            $reset_link = $PORTAL_URL . '/reset-password.php?token=' . $token;

            // Send email via PHP mail() — production should use SMTP
            $subject = 'Password Reset — Orbit Cloud';
            $body    = "Hi {$client['first_name']},\n\nYou requested a password reset for your Orbit Cloud portal account.\n\nClick the link below to reset your password (valid for 1 hour):\n{$reset_link}\n\nIf you did not request this, please ignore this email.\n\n— The Orbit Cloud Team";
            $headers = "From: noreply@orbitcloud.co.ke\r\nX-Mailer: PHP";
            @mail($email, $subject, $body, $headers);
        }

        // Always show success to prevent email enumeration
        $sent = true;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Forgot Password — Orbit Cloud Portal</title>
  <link rel="stylesheet" href="<?php echo $PORTAL_URL; ?>/css/portal.css" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" crossorigin="anonymous" />
</head>
<body>
<div class="auth-wrap">
  <div class="auth-card">
    <a href="<?php echo $PORTAL_URL; ?>/login.php" class="auth-logo">Orbit<span>Cloud</span></a>

    <?php if ($sent): ?>
      <div class="auth-icon-wrap"><i class="fas fa-envelope-circle-check" style="font-size:42px;color:var(--green)"></i></div>
      <h2 style="text-align:center;margin-bottom:6px">Check your inbox</h2>
      <p style="text-align:center;color:var(--text-muted);font-size:14px;margin-bottom:24px">
        If an account exists for that email address we've sent a reset link. It expires in 1 hour.
      </p>
      <a href="<?php echo $PORTAL_URL; ?>/login.php" class="btn btn-primary" style="width:100%;justify-content:center">Back to Sign In</a>
    <?php else: ?>
      <h2 style="text-align:center;margin-bottom:6px">Reset Password</h2>
      <p style="text-align:center;color:var(--text-muted);font-size:14px;margin-bottom:24px">Enter your account email and we'll send you a reset link.</p>

      <?php if ($errors): ?>
        <div class="p-alert p-alert-error" style="margin-bottom:14px"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($errors[0]); ?></div>
      <?php endif; ?>

      <form method="POST">
        <div class="form-group">
          <label class="form-label">Email Address</label>
          <input type="email" name="email" class="form-control" placeholder="you@example.com" required autofocus />
        </div>
        <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center">
          <i class="fas fa-paper-plane"></i> Send Reset Link
        </button>
      </form>

      <div style="text-align:center;margin-top:20px;font-size:13px">
        <a href="<?php echo $PORTAL_URL; ?>/login.php" style="color:var(--text-muted)"><i class="fas fa-arrow-left" style="font-size:11px"></i> Back to Sign In</a>
      </div>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
