<?php
require_once '../admin/includes/config.php';
require_once '../admin/includes/db.php';

session_name('orbit_portal');
session_start();

$PORTAL_URL = (function () {
    $prot = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $root = rtrim(str_replace('\\', '/', dirname(__DIR__)), '/');
    $doc  = rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
    $rel  = str_replace($doc, '', $root);
    return $prot . '://' . $host . $rel . '/portal';
})();

$token  = trim($_GET['token'] ?? $_POST['token'] ?? '');
$errors = [];
$done   = false;
$invite = null;

if ($token) {
    $stmt = db()->prepare(
        'SELECT pi.*, c.first_name, c.last_name, c.email
         FROM portal_invites pi
         JOIN clients c ON c.id = pi.client_id
         WHERE pi.token=? AND pi.expires_at > NOW() AND pi.accepted_at IS NULL'
    );
    $stmt->execute([$token]);
    $invite = $stmt->fetch();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $invite) {
    $pass  = $_POST['password']  ?? '';
    $pass2 = $_POST['password2'] ?? '';
    if (strlen($pass) < 8) {
        $errors[] = 'Password must be at least 8 characters.';
    } elseif ($pass !== $pass2) {
        $errors[] = 'Passwords do not match.';
    } else {
        $hash = password_hash($pass, PASSWORD_BCRYPT);
        db()->prepare('UPDATE clients SET portal_password=?, email_verified=1 WHERE id=?')
            ->execute([$hash, $invite['client_id']]);
        db()->prepare('UPDATE portal_invites SET accepted_at=NOW() WHERE id=?')
            ->execute([$invite['id']]);

        $_SESSION['portal_client_id'] = $invite['client_id'];
        $_SESSION['portal_login']     = time();
        session_regenerate_id(true);

        header('Location: ' . $PORTAL_URL . '/dashboard.php');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Accept Invite — OrbitHost Portal</title>
  <link rel="stylesheet" href="<?php echo $PORTAL_URL; ?>/css/portal.css" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" crossorigin="anonymous" />
</head>
<body>
<div class="auth-wrap">
  <div class="auth-card">
    <div class="auth-logo">Orbit<span>Host</span></div>

    <?php if (!$invite): ?>
      <div style="text-align:center">
        <i class="fas fa-link-slash" style="font-size:42px;color:var(--danger);margin-bottom:14px"></i>
        <h2>Invalid Invite</h2>
        <p style="color:var(--text-muted);font-size:14px;margin-bottom:20px">This invitation link is invalid, has expired, or has already been used.</p>
        <p style="font-size:13px;color:var(--text-muted)">Contact <strong>sammyopiyo001@gmail.com</strong> to request a new invite.</p>
      </div>

    <?php else: ?>
      <div style="text-align:center;margin-bottom:24px">
        <i class="fas fa-user-check" style="font-size:36px;color:var(--green);margin-bottom:12px"></i>
        <h2>Welcome to OrbitHost</h2>
        <p style="color:var(--text-muted);font-size:14px">
          Hi <strong><?php echo htmlspecialchars($invite['first_name']); ?></strong>, set a password for your client portal account.<br />
          <span style="font-size:12px"><?php echo htmlspecialchars($invite['email']); ?></span>
        </p>
      </div>

      <?php if ($errors): ?>
        <div class="p-alert p-alert-error" style="margin-bottom:14px"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($errors[0]); ?></div>
      <?php endif; ?>

      <form method="POST">
        <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>" />
        <div class="form-group">
          <label class="form-label">Choose a Password</label>
          <input type="password" name="password" id="new_password" class="form-control" placeholder="At least 8 characters" required autofocus />
          <div id="strengthBar" style="height:3px;border-radius:2px;margin-top:6px;width:0;transition:.3s"></div>
        </div>
        <div class="form-group">
          <label class="form-label">Confirm Password</label>
          <input type="password" name="password2" class="form-control" placeholder="Repeat password" required />
        </div>
        <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center">
          <i class="fas fa-rocket"></i> Activate My Account
        </button>
      </form>
      <script>
      document.getElementById('new_password').addEventListener('input', function () {
        var v = this.value, s = document.getElementById('strengthBar');
        var score = (v.length >= 8 ? 1 : 0) + (/[A-Z]/.test(v) ? 1 : 0) + (/[0-9]/.test(v) ? 1 : 0) + (/[^A-Za-z0-9]/.test(v) ? 1 : 0);
        s.style.width  = [0, 25, 50, 75, 100][score] + '%';
        s.style.background = ['', '#ef4444', '#f59e0b', '#3b82f6', '#22c55e'][score];
      });
      </script>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
