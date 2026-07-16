<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once dirname(__DIR__) . '/admin/includes/functions.php';
require_once dirname(__DIR__) . '/admin/includes/Notifier.php';

portal_start();
if (!empty($_SESSION['client_id'])) { header('Location: ' . PORTAL_URL . '/dashboard.php'); exit; }

// "Get Started" on a plan (or a domain search) passes context here so
// signup shows what the visitor picked. Carried through the form on POST.
$selected_plan   = trim($_GET['plan']   ?? $_POST['plan']   ?? '');
$selected_domain = trim($_GET['domain'] ?? $_POST['domain'] ?? '');
$plan_labels = [
    'shared-starter'       => 'Shared Starter Hosting',
    'shared-business'      => 'Shared Business Hosting',
    'shared-pro'           => 'Shared Pro Hosting',
    'vps-starter'          => 'VPS Starter',
    'vps-business'         => 'VPS Business',
    'vps-pro'              => 'VPS Pro',
    'dedicated-essential'  => 'Dedicated Essential',
    'dedicated-business'   => 'Dedicated Business',
    'dedicated-enterprise' => 'Dedicated Enterprise',
    'cloud-starter'        => 'Cloud Starter',
    'cloud-business'       => 'Cloud Business',
    'cloud-enterprise'     => 'Cloud Enterprise',
    'wp-starter'           => 'WordPress Starter',
    'wp-business'          => 'WordPress Business',
    'wp-pro'               => 'WordPress Pro',
    'reseller-starter'     => 'Reseller Starter',
    'reseller-business'    => 'Reseller Business',
    'reseller-pro'         => 'Reseller Pro',
    'ssl-ov'               => 'OV SSL Certificate',
    'ssl-ev'               => 'EV SSL Certificate',
    'email-orbitmail'      => 'OrbitMail Email Hosting',
    'email-m365'           => 'Microsoft 365 Email',
    'email-gworkspace'     => 'Google Workspace Email',
];
$plan_label = $plan_labels[$selected_plan]
    ?? ($selected_plan !== '' ? ucwords(str_replace('-', ' ', $selected_plan)) : '');

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
    $errors = array_merge($errors, password_policy_errors($pass, [$data['email'], $data['first_name'], $data['last_name']]));
    if ($pass !== $pass2)    $errors[] = 'Passwords do not match.';

    if (!$errors) {
        $dup = db()->prepare('SELECT id FROM clients WHERE email = ?');
        $dup->execute([$data['email']]);
        if ($dup->fetch()) {
            $errors[] = 'An account with this email already exists. Try logging in instead.';
        } else {
            ensure_client_verify_columns();
            $hash  = password_hash($pass, PASSWORD_BCRYPT);
            $token = bin2hex(random_bytes(32));
            db()->prepare('INSERT INTO clients (first_name,last_name,email,phone,company,country,status,portal_password,email_verified,verify_token,verify_expires) VALUES (?,?,?,?,?,?,"active",?,0,?,DATE_ADD(NOW(), INTERVAL 24 HOUR))')
                ->execute([$data['first_name'],$data['last_name'],$data['email'],$data['phone'],$data['company'],$data['country'],$hash,$token]);
            $cid = db()->lastInsertId();
            // Auto-login
            session_regenerate_id(true);
            $_SESSION['client_id']    = $cid;
            $_SESSION['client_name']  = $data['first_name'] . ' ' . $data['last_name'];
            $_SESSION['client_email'] = $data['email'];
            $_SESSION['last_active']  = time();

            Notifier::send('account_welcome', (int) $cid, [
                'client_name' => $data['first_name'],
                'email'       => $data['email'],
                'link'        => PORTAL_URL . '/dashboard.php',
            ]);
            Notifier::send('email_verification', (int) $cid, [
                'client_name' => $data['first_name'],
                'email'       => $data['email'],
                'verify_link' => PORTAL_URL . '/verify-email.php?token=' . $token,
                'link'        => PORTAL_URL . '/dashboard.php',
            ]);

            $to = $_SESSION['post_login_redirect'] ?? 'dashboard.php';
            unset($_SESSION['post_login_redirect']);
            if (!in_array($to, ['checkout.php', 'cart.php', 'dashboard.php', 'domains.php'], true)) $to = 'dashboard.php';
            header('Location: ' . PORTAL_URL . '/' . $to);
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
  <title>Create Account — Orbit Cloud</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" crossorigin="anonymous" />
  <link rel="stylesheet" href="<?php echo PORTAL_URL; ?>/css/portal.css" />
  <style>
    html, body { height: 100%; }
    body { margin: 0; display: flex; min-height: 100vh; background: #fff; }

    .auth-split { display: flex; width: 100%; min-height: 100vh; }

    /* ── Visual / carousel panel ── */
    .auth-visual { position: relative; flex: 1 1 48%; overflow: hidden; background: var(--navy); }
    .auth-slide {
      position: absolute; inset: 0; opacity: 0;
      background-size: cover; background-position: center;
      transition: opacity 1.4s ease;
    }
    .auth-slide.active { opacity: 1; }
    .auth-slide::after {
      content: ''; position: absolute; inset: 0; z-index: 1;
      background: linear-gradient(180deg, rgba(11,31,58,.20) 0%, rgba(11,31,58,.45) 55%, rgba(11,31,58,.94) 100%);
    }
    .auth-slide-copy { position: absolute; left: 0; right: 0; bottom: 0; z-index: 2; padding: 48px 56px 78px; color: #fff; }
    .auth-slide-copy h2 { font-size: 27px; font-weight: 800; line-height: 1.28; margin-bottom: 10px; }
    .auth-slide-copy p  { font-size: 14.5px; color: rgba(255,255,255,.8); max-width: 420px; line-height: 1.6; }
    .auth-dots { position: absolute; left: 56px; bottom: 40px; z-index: 3; display: flex; gap: 8px; }
    .auth-dot { width: 22px; height: 4px; border-radius: 2px; border: none; background: rgba(255,255,255,.35); cursor: pointer; padding: 0; transition: background .2s; }
    .auth-dot.active { background: var(--green); }

    /* ── Form panel ── */
    .auth-form-side { flex: 1 1 52%; display: flex; align-items: center; justify-content: center; padding: 32px 16px; overflow-y: auto; }
    .auth-wrap  { width: 100%; max-width: 480px; }
    .auth-brand { text-align: center; margin-bottom: 24px; }
    .auth-orb   { width: 50px; height: 50px; background: var(--green); border-radius: 14px; display: flex; align-items: center; justify-content: center; font-size: 22px; font-weight: 800; color: #fff; margin: 0 auto 12px; }
    .auth-brand h1 { color: var(--navy); font-size: 20px; font-weight: 700; }
    .auth-brand p  { color: var(--text-muted); font-size: 13px; }
    .auth-card  { padding: 0; }
    .auth-card h2 { font-size: 17px; font-weight: 700; margin-bottom: 20px; }
    .auth-error { background: #fee2e2; color: #991b1b; padding: 11px 14px; border-radius: 7px; font-size: 13px; margin-bottom: 16px; }
    .btn-register { width: 100%; justify-content: center; padding: 11px; font-size: 14px; font-weight: 600; }
    .login-link { text-align: center; margin-top: 16px; padding-top: 16px; border-top: 1px solid var(--border); font-size: 13px; }
    .login-link a { color: var(--green); font-weight: 600; }
    .back-link  { display: block; text-align: center; margin-top: 14px; font-size: 12.5px; color: var(--text-muted); }

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
        <p>Client Portal — Create Account</p>
      </div>
      <div class="auth-card">
        <h2>Create Your Account</h2>
        <?php if ($errors): ?>
          <div class="auth-error"><?php echo htmlspecialchars(implode(' ', $errors)); ?></div>
        <?php endif; ?>

        <?php if ($plan_label): ?>
          <div style="background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46;padding:11px 14px;border-radius:8px;font-size:13px;margin-bottom:16px;line-height:1.5">
            <i class="fas fa-circle-check"></i>
            You're getting started with <strong><?php echo htmlspecialchars($plan_label); ?></strong>. Create your account and our team will set it up.
          </div>
        <?php endif; ?>
        <?php if ($selected_domain): ?>
          <div style="background:#eff6ff;border:1px solid #bfdbfe;color:#1e40af;padding:11px 14px;border-radius:8px;font-size:13px;margin-bottom:16px;line-height:1.5">
            <i class="fas fa-globe"></i>
            Domain of interest: <strong><?php echo htmlspecialchars($selected_domain); ?></strong>
          </div>
        <?php endif; ?>

        <form method="POST">
          <?php if ($selected_plan): ?><input type="hidden" name="plan" value="<?php echo htmlspecialchars($selected_plan); ?>" /><?php endif; ?>
          <?php if ($selected_domain): ?><input type="hidden" name="domain" value="<?php echo htmlspecialchars($selected_domain); ?>" /><?php endif; ?>
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
              <input type="password" id="new_password" name="password" class="form-control" placeholder="Min 10 characters" required />
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
<script src="<?php echo PORTAL_URL; ?>/js/portal.js"></script>
</body>
</html>
