<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../../admin/includes/functions.php';

portal_start();
if (!empty($_SESSION['client_id'])) { header('Location: ' . PORTAL_URL . '/dashboard.php'); exit; }

$errors = [];
$data   = ['first_name'=>'','last_name'=>'','email'=>'','phone'=>'','company'=>'','country'=>'Kenya'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = [
        'first_name' => trim($_POST['first_name'] ?? ''),
        'last_name'  => trim($_POST['last_name']  ?? ''),
        'email'      => trim($_POST['email']       ?? ''),
        'phone'      => trim($_POST['phone']       ?? ''),
        'company'    => trim($_POST['company']     ?? ''),
        'country'    => trim($_POST['country']     ?? 'Kenya'),
    ];
    $pass  = $_POST['password']  ?? '';
    $pass2 = $_POST['password2'] ?? '';

    if (!$data['first_name']) $errors[] = 'First name is required.';
    if (!$data['last_name'])  $errors[] = 'Last name is required.';
    if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'A valid email address is required.';
    if (strlen($pass) < 8)   $errors[] = 'Password must be at least 8 characters.';
    if ($pass !== $pass2)    $errors[] = 'Passwords do not match.';

    if (!$errors) {
        $dup = db()->prepare('SELECT id FROM clients WHERE email = ?');
        $dup->execute([$data['email']]);
        if ($dup->fetch()) {
            $errors[] = 'An account with this email already exists. Try logging in instead.';
        } else {
            $hash = password_hash($pass, PASSWORD_BCRYPT);
            db()->prepare('INSERT INTO clients (first_name,last_name,email,phone,company,country,status,portal_password,email_verified) VALUES (?,?,?,?,?,?,"active",?,1)')
                ->execute([$data['first_name'],$data['last_name'],$data['email'],$data['phone'],$data['company'],$data['country'],$hash]);
            $cid = db()->lastInsertId();
            // Auto-login
            session_regenerate_id(true);
            $_SESSION['client_id']    = $cid;
            $_SESSION['client_name']  = $data['first_name'] . ' ' . $data['last_name'];
            $_SESSION['client_email'] = $data['email'];
            $_SESSION['last_active']  = time();
            header('Location: ' . PORTAL_URL . '/dashboard.php');
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Create Account — OrbitHost</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" crossorigin="anonymous" />
  <link rel="stylesheet" href="<?php echo PORTAL_URL; ?>/css/portal.css" />
  <style>
    body { background: var(--navy); min-height: 100vh; padding: 32px 16px; }
    .auth-wrap  { max-width: 500px; margin: 0 auto; }
    .auth-brand { text-align: center; margin-bottom: 24px; }
    .auth-orb   { width: 50px; height: 50px; background: var(--green); border-radius: 14px; display: flex; align-items: center; justify-content: center; font-size: 22px; font-weight: 800; color: #fff; margin: 0 auto 12px; }
    .auth-brand h1 { color: #fff; font-size: 20px; font-weight: 700; }
    .auth-brand p  { color: rgba(255,255,255,.4); font-size: 13px; }
    .auth-card  { background: #fff; border-radius: 16px; padding: 30px 28px; box-shadow: 0 20px 60px rgba(0,0,0,.3); }
    .auth-card h2 { font-size: 17px; font-weight: 700; margin-bottom: 20px; }
    .auth-error { background: #fee2e2; color: #991b1b; padding: 11px 14px; border-radius: 7px; font-size: 13px; margin-bottom: 16px; }
    .btn-register { width: 100%; justify-content: center; padding: 11px; font-size: 14px; font-weight: 600; }
    .login-link { text-align: center; margin-top: 16px; padding-top: 16px; border-top: 1px solid var(--border); font-size: 13px; }
    .login-link a { color: var(--green); font-weight: 600; }
    .back-link  { display: block; text-align: center; margin-top: 14px; font-size: 12.5px; color: rgba(255,255,255,.4); }
  </style>
</head>
<body>
<div class="auth-wrap">
  <div class="auth-brand">
    <div class="auth-orb">O</div>
    <h1>OrbitHost</h1>
    <p>Client Portal — Create Account</p>
  </div>
  <div class="auth-card">
    <h2>Create Your Account</h2>
    <?php if ($errors): ?>
      <div class="auth-error"><?php echo htmlspecialchars(implode(' ', $errors)); ?></div>
    <?php endif; ?>

    <form method="POST">
      <div class="form-grid-2">
        <div class="form-group">
          <label class="form-label">First Name <span class="req">*</span></label>
          <input type="text" name="first_name" class="form-control" value="<?php echo htmlspecialchars($data['first_name']); ?>" required autofocus />
        </div>
        <div class="form-group">
          <label class="form-label">Last Name <span class="req">*</span></label>
          <input type="text" name="last_name" class="form-control" value="<?php echo htmlspecialchars($data['last_name']); ?>" required />
        </div>
      </div>
      <div class="form-group">
        <label class="form-label">Email Address <span class="req">*</span></label>
        <input type="email" name="email" class="form-control" value="<?php echo htmlspecialchars($data['email']); ?>" required />
      </div>
      <div class="form-grid-2">
        <div class="form-group">
          <label class="form-label">Phone</label>
          <input type="tel" name="phone" class="form-control" value="<?php echo htmlspecialchars($data['phone']); ?>" placeholder="+254 7XX XXX XXX" />
        </div>
        <div class="form-group">
          <label class="form-label">Country</label>
          <select name="country" class="form-select">
            <?php foreach (['Kenya','Uganda','Tanzania','Rwanda','Nigeria','Ghana','South Africa','USA','United Kingdom','Other'] as $c): ?>
              <option <?php echo $data['country']===$c?'selected':''; ?>><?php echo $c; ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="form-group">
        <label class="form-label">Company (optional)</label>
        <input type="text" name="company" class="form-control" value="<?php echo htmlspecialchars($data['company']); ?>" />
      </div>
      <div class="form-grid-2">
        <div class="form-group">
          <label class="form-label">Password <span class="req">*</span></label>
          <input type="password" id="new_password" name="password" class="form-control" placeholder="Min 8 characters" required />
          <div style="height:3px;background:#f1f5f9;border-radius:2px;margin-top:6px"><div id="strengthBar" style="height:100%;border-radius:2px;transition:width .2s,background .2s;width:0"></div></div>
        </div>
        <div class="form-group">
          <label class="form-label">Confirm Password <span class="req">*</span></label>
          <input type="password" name="password2" class="form-control" placeholder="Repeat password" required />
        </div>
      </div>
      <button type="submit" class="btn btn-primary btn-register"><i class="fas fa-user-plus"></i> Create Account</button>
    </form>

    <div class="login-link">Already have an account? <a href="<?php echo PORTAL_URL; ?>/login.php">Sign in →</a></div>
  </div>
  <a href="../index.html" class="back-link">← Back to OrbitHost website</a>
</div>
<script src="<?php echo PORTAL_URL; ?>/js/portal.js"></script>
</body>
</html>
