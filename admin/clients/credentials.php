<?php
/**
 * Orbit Cloud — issue a client's portal login (username and/or password)
 * and email it to them. See includes/ClientCredentials.php.
 */
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/ClientCredentials.php';

auth_check();

$id = (int) ($_GET['id'] ?? 0);
require_role('admin', APP_URL . '/clients/view.php?id=' . $id);
ClientCredentials::ensureSchema();

$stmt = db()->prepare('SELECT * FROM clients WHERE id = ?');
$stmt->execute([$id]);
$client = $stmt->fetch();
if (!$client) {
    flash_set('error', 'Client not found.');
    header('Location: ' . APP_URL . '/clients/index.php');
    exit;
}

$error = '';
$form  = [
    'mode'         => $client['portal_username'] ? 'username' : 'email',
    'username'     => $client['portal_username'] ?: ClientCredentials::suggestUsername($client),
    'pw_mode'      => 'generate',
    'force_change' => true,
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $form = [
        'mode'         => ($_POST['mode'] ?? '') === 'username' ? 'username' : 'email',
        'username'     => trim((string) ($_POST['username'] ?? '')),
        'pw_mode'      => ($_POST['pw_mode'] ?? '') === 'manual' ? 'manual' : 'generate',
        'force_change' => !empty($_POST['force_change']),
    ];
    $password = $form['pw_mode'] === 'manual' ? (string) ($_POST['password'] ?? '') : null;

    if ($form['pw_mode'] === 'manual' && $password === '') {
        $error = 'Enter a password, or choose to generate one.';
    } else {
        $r = ClientCredentials::issue($id, $form['mode'] === 'username' ? $form['username'] : null, $password,
                                      $form['force_change'], (int) current_admin()['id']);
        if ($r['ok']) {
            // Shown once on the next page load so the admin can pass it on
            // by phone if the email doesn't arrive — then it's gone.
            $_SESSION['issued_credentials'][$id] = ['username' => $r['username'], 'password' => $r['password'],
                                                    'email_sent' => $r['email_sent'], 'at' => time()];
            flash_set($r['email_sent'] ? 'success' : 'error', $r['message']);
            header('Location: ' . APP_URL . '/clients/credentials.php?id=' . $id);
            exit;
        }
        $error = $r['message'];
    }
}

// One-time reveal (expires after 10 minutes even if never viewed).
$issued = $_SESSION['issued_credentials'][$id] ?? null;
unset($_SESSION['issued_credentials'][$id]);
if ($issued && time() - $issued['at'] > 600) $issued = null;

$page_title = 'Portal Login';
require_once '../includes/header.php';
?>

<div class="content-header">
  <div>
    <div class="breadcrumb"><a href="<?php echo APP_URL; ?>/clients/">Clients</a><span class="breadcrumb-sep">›</span>
      <a href="<?php echo APP_URL; ?>/clients/view.php?id=<?php echo $id; ?>"><?php echo h($client['first_name'] . ' ' . $client['last_name']); ?></a><span class="breadcrumb-sep">›</span> Portal login</div>
    <h1 class="content-title">Portal Login</h1>
  </div>
  <a href="<?php echo APP_URL; ?>/clients/view.php?id=<?php echo $id; ?>" class="btn btn-ghost"><i class="fas fa-arrow-left"></i> Back</a>
</div>

<div style="max-width:560px">

  <?php if ($issued): ?>
    <div class="card" style="margin-bottom:18px;border-color:var(--success)">
      <div class="card-header"><div class="card-title"><i class="fas fa-key"></i> Login issued</div></div>
      <div class="card-body">
        <ul class="profile-meta">
          <li><span class="key">Email</span><span class="val"><?php echo h($client['email']); ?></span></li>
          <?php if ($issued['username']): ?>
            <li><span class="key">Username</span><span class="val mono"><?php echo h($issued['username']); ?></span></li>
          <?php endif; ?>
          <li><span class="key">Password</span><span class="val mono" style="user-select:all"><?php echo h($issued['password']); ?></span></li>
        </ul>
        <p class="text-muted" style="font-size:12px;margin-top:12px">
          <?php echo $issued['email_sent'] ? 'These details were emailed to the client.' : 'The email did NOT go out — pass these on securely, or fix mail settings and issue again.'; ?>
          This password is shown only once and is not stored readable anywhere.
        </p>
      </div>
    </div>
  <?php endif; ?>

  <?php if ($error): ?>
    <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?php echo h($error); ?></div>
  <?php endif; ?>

  <?php if ($client['status'] !== 'active'): ?>
    <div class="alert alert-warning"><i class="fas fa-triangle-exclamation"></i>
      This client is <?php echo h($client['status']); ?> — they won't be able to sign in until the account is active.</div>
  <?php endif; ?>

  <div class="card">
    <div class="card-body">
      <div style="display:flex;align-items:center;gap:16px;margin-bottom:20px">
        <div class="client-avatar" style="width:52px;height:52px;font-size:20px">
          <?php echo h(strtoupper(substr($client['first_name'], 0, 1) . substr($client['last_name'], 0, 1))); ?>
        </div>
        <div>
          <div style="font-weight:700;font-size:16px"><?php echo h($client['first_name'] . ' ' . $client['last_name']); ?></div>
          <div style="font-size:13px;color:var(--text-muted)"><?php echo h($client['email']); ?>
            <?php if ($client['portal_username']): ?> · username <span class="mono"><?php echo h($client['portal_username']); ?></span><?php endif; ?></div>
        </div>
      </div>

      <?php if ($client['portal_password']): ?>
        <div class="alert alert-info"><i class="fas fa-info-circle"></i>
          This client already has a portal password. Issuing a new login replaces it — their old password stops working.</div>
      <?php endif; ?>

      <form method="POST" id="credForm">
        <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>" />

        <div class="form-group">
          <label class="form-label">Client signs in with</label>
          <label style="display:flex;gap:8px;align-items:center;margin-bottom:6px;font-weight:400">
            <input type="radio" name="mode" value="email" <?php echo $form['mode'] === 'email' ? 'checked' : ''; ?> />
            Their email address + a password
          </label>
          <label style="display:flex;gap:8px;align-items:center;font-weight:400">
            <input type="radio" name="mode" value="username" <?php echo $form['mode'] === 'username' ? 'checked' : ''; ?> />
            A username + a password <span class="text-muted">(email still works too)</span>
          </label>
        </div>

        <div class="form-group" id="usernameRow">
          <label class="form-label" for="username">Username</label>
          <input type="text" id="username" name="username" class="form-control mono" maxlength="30"
                 value="<?php echo h($form['username']); ?>" pattern="[a-z0-9][a-z0-9._\-]{2,29}" autocomplete="off" />
          <div class="text-muted" style="font-size:12px;margin-top:4px">3–30 characters: lowercase letters, numbers, dot, dash or underscore.</div>
        </div>

        <div class="form-group">
          <label class="form-label">Password</label>
          <label style="display:flex;gap:8px;align-items:center;margin-bottom:6px;font-weight:400">
            <input type="radio" name="pw_mode" value="generate" <?php echo $form['pw_mode'] === 'generate' ? 'checked' : ''; ?> />
            Generate a strong password
          </label>
          <label style="display:flex;gap:8px;align-items:center;font-weight:400">
            <input type="radio" name="pw_mode" value="manual" <?php echo $form['pw_mode'] === 'manual' ? 'checked' : ''; ?> />
            Set one myself
          </label>
          <input type="text" id="password" name="password" class="form-control mono" style="margin-top:8px"
                 placeholder="At least 10 characters, 3 of: lower, upper, number, symbol" autocomplete="new-password" />
        </div>

        <div class="form-group">
          <label style="display:flex;gap:8px;align-items:center;font-weight:400">
            <input type="checkbox" name="force_change" value="1" <?php echo $form['force_change'] ? 'checked' : ''; ?> />
            Require the client to change this password when they first sign in
          </label>
        </div>

        <button type="submit" class="btn btn-primary"
                data-confirm="Set this login and email it to <?php echo h($client['email']); ?>?<?php echo $client['portal_password'] ? ' Their current password will stop working.' : ''; ?>">
          <i class="fas fa-paper-plane"></i> Save &amp; email login to client
        </button>
      </form>
    </div>
  </div>
</div>

<script>
(function () {
  var f = document.getElementById('credForm');
  function sync() {
    var userMode = f.querySelector('input[name=mode]:checked').value === 'username';
    document.getElementById('usernameRow').style.display = userMode ? '' : 'none';
    document.getElementById('username').required = userMode;
    var manual = f.querySelector('input[name=pw_mode]:checked').value === 'manual';
    var pw = document.getElementById('password');
    pw.style.display = manual ? '' : 'none';
    pw.required = manual;
  }
  f.addEventListener('change', sync);
  sync();
})();
</script>

<?php require_once '../includes/footer.php'; ?>
