<?php
/**
 * Orbit Cloud — Checkout.
 *
 * The one checkout for everything in the cart: a hosting package (after
 * its own steps), domains on their own, or both. A visitor creates their
 * client area account here — or signs in to an existing one — and placing
 * the order writes a single invoice (see _place.php), then hands off to
 * complete.php to pay.
 */
require_once __DIR__ . '/_layout.php';
require_once __DIR__ . '/_place.php';
require_once dirname(__DIR__, 2) . '/admin/includes/ServiceAddon.php';
require_once dirname(__DIR__, 2) . '/admin/includes/LoginGuard.php';

portal_start();
Currency::ensureSchema();
$currency = Currency::current();

// A second submit of a form that already placed its order (double click,
// back-and-resubmit): the cart is empty now, so send them to that order
// rather than to an empty cart. Checked before checkout_require() for
// exactly that reason.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_SESSION['checkout_last_invoice'])
    && !hash_equals((string) ($_SESSION['checkout_nonce'] ?? ''), (string) ($_POST['checkout_nonce'] ?? ''))) {
    header('Location: ' . checkout_url('complete.php') . '?invoice=' . (int) $_SESSION['checkout_last_invoice']);
    exit;
}
// A package finished in the steps joins the rest of the cart.
OrderCart::commitHosting();
checkout_require('review');

$plan      = OrderCart::plan();        // only ever an unfinished one here
$packages  = OrderCart::allHosting();
$logged_in = !empty($_SESSION['client_id']);
$errors    = [];
$mode      = $logged_in ? 'account' : (($_POST['auth_mode'] ?? ($_GET['auth'] ?? 'new')) === 'existing' ? 'existing' : 'new');
$data      = ['first_name' => '', 'last_name' => '', 'email' => '', 'phone' => '', 'company' => '', 'country' => 'Kenya'];
$site      = SiteSettings::siteRoot();

if ($logged_in) {
    $stmt = db()->prepare('SELECT * FROM clients WHERE id = ?');
    $stmt->execute([(int) $_SESSION['client_id']]);
    $me = $stmt->fetch() ?: [];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    portal_csrf_verify();

    // One order per checkout page: a double click, a refresh-resubmit or a
    // second tab posting the same form can't place the order twice.
    $nonce = (string) ($_POST['checkout_nonce'] ?? '');
    if ($nonce === '' || !hash_equals((string) ($_SESSION['checkout_nonce'] ?? ''), $nonce)) {
        $errors[] = 'This checkout page had expired — please review your order and try again.';
    }

    $client = null;
    if (!$errors && $logged_in) {
        $client = $me;

    } elseif (!$errors && $mode === 'existing') {
        // Same brute-force lockout and 2FA challenge as the sign-in page.
        $r = portal_login(trim($_POST['login_email'] ?? ''), (string) ($_POST['login_password'] ?? ''));
        if (empty($r['ok'])) {
            $errors[] = $r['message'] ?? 'Those details don\'t match an account.';
        } elseif (!empty($r['needs_2fa'])) {
            // The cart survives in the session — we come straight back here.
            $_SESSION['post_login_redirect'] = 'order/review.php';
            header('Location: ' . PORTAL_URL . '/verify-2fa.php');
            exit;
        } else {
            $stmt = db()->prepare('SELECT * FROM clients WHERE id = ?');
            $stmt->execute([(int) $_SESSION['client_id']]);
            $client = $stmt->fetch() ?: null;
        }

    } elseif (!$errors) {
        // ── Create the client area account ──
        $data = [
            'first_name' => trim($_POST['first_name'] ?? ''),
            'last_name'  => trim($_POST['last_name']  ?? ''),
            'email'      => strtolower(trim($_POST['email'] ?? '')),
            'phone'      => trim($_POST['phone']      ?? ''),
            'company'    => trim($_POST['company']    ?? ''),
            'country'    => trim($_POST['country']    ?? 'Kenya'),
        ];
        $pass  = (string) ($_POST['password']  ?? '');
        $pass2 = (string) ($_POST['password2'] ?? '');

        if (!$data['first_name']) $errors[] = 'First name is required.';
        if (!$data['last_name'])  $errors[] = 'Last name is required.';
        if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'A valid email address is required.';
        // Needed for M-Pesa, SMS alerts, and as the domain registrant's phone.
        if (strlen(preg_replace('/\D/', '', $data['phone'])) < 9) $errors[] = 'A mobile number is required (e.g. 0712 345 678).';
        if (!in_array($data['country'], get_countries(), true)) $data['country'] = 'Kenya';
        $errors = array_merge($errors, password_policy_errors($pass, [$data['email'], $data['first_name'], $data['last_name']]));
        if ($pass !== $pass2) $errors[] = 'Passwords do not match.';

        if (!$errors) {
            $dup = db()->prepare('SELECT id FROM clients WHERE email = ?');
            $dup->execute([$data['email']]);
            if ($dup->fetch()) {
                $errors[] = 'An account with ' . $data['email'] . ' already exists — choose "I already have an account" to sign in and finish your order.';
                $mode = 'existing';
                $_POST['login_email'] = $data['email'];
            }
        }
    }

    if (!$errors && empty($_POST['accept_tos'])) {
        $errors[] = 'Please read and accept the Terms of Service to place your order.';
    }

    // Create the account only once everything else has passed, so a
    // missing tick-box doesn't leave a half-registered visitor behind.
    if (!$errors && !$client && $mode === 'new') {
        ensure_client_verify_columns();
        $token = bin2hex(random_bytes(32));
        db()->prepare('INSERT INTO clients (first_name,last_name,email,phone,company,country,status,portal_password,email_verified,verify_token,verify_expires)
                       VALUES (?,?,?,?,?,?,"active",?,0,?,DATE_ADD(NOW(), INTERVAL 24 HOUR))')
            ->execute([$data['first_name'], $data['last_name'], $data['email'], $data['phone'],
                       $data['company'], $data['country'], password_hash($pass, PASSWORD_BCRYPT), $token]);
        $cid = (int) db()->lastInsertId();
        log_activity('client_signup_checkout', 'client', $cid, 'Account created at checkout: ' . $data['email']);

        session_regenerate_id(true);
        $_SESSION['client_id']    = $cid;
        $_SESSION['client_name']  = $data['first_name'] . ' ' . $data['last_name'];
        $_SESSION['client_email'] = $data['email'];
        $_SESSION['last_active']  = time();
        $_SESSION['checkout_new_account'] = true;
        $_SESSION['checkout_welcome'] = ['token' => $token, 'name' => $data['first_name'], 'email' => $data['email']];

        $client = array_merge($data, ['id' => $cid]);
    }

    if ($client && !$errors) {
        try {
            $placed = place_hosting_order($client, $currency);
            unset($_SESSION['checkout_nonce']);
            $_SESSION['checkout_last_invoice'] = (int) $placed['invoice_id'];
            // Redirect first, then email — checkout must never wait on SMTP.
            header('Location: ' . checkout_url('complete.php') . '?invoice=' . $placed['invoice_id']);
            notify_hosting_order($placed, $client, $currency);
            if (!empty($_SESSION['checkout_welcome'])) {
                $w = $_SESSION['checkout_welcome'];
                unset($_SESSION['checkout_welcome']);
                try {
                    Notifier::send('account_welcome', (int) $client['id'], ['client_name' => $w['name'], 'email' => $w['email'], 'link' => PORTAL_URL . '/dashboard.php']);
                    Notifier::send('email_verification', (int) $client['id'], ['client_name' => $w['name'], 'email' => $w['email'],
                                                                              'link' => PORTAL_URL . '/verify-email.php?token=' . $w['token']]);
                } catch (\Throwable $e) { /* the account works without them */ }
            }
            exit;
        } catch (\Throwable $e) {
            $errors[] = $e->getMessage();
            // The account (if just created) exists and is signed in — show
            // it that way, so fixing the cart doesn't ask them to sign up again.
            $logged_in = true; $mode = 'account';
            $stmt = db()->prepare('SELECT * FROM clients WHERE id = ?');
            $stmt->execute([(int) $client['id']]);
            $me = $stmt->fetch() ?: $client;
        }
    }
}

// A fresh page is a fresh attempt.
unset($_SESSION['checkout_last_invoice']);
$_SESSION['checkout_nonce'] = bin2hex(random_bytes(16));
$sum = OrderCart::summary($currency);
$mode_label = ['register' => 'New registration', 'transfer' => 'Transfer to us', 'existing' => 'Domain you already own', 'cart' => 'From your cart'];

checkout_head('Checkout', checkout_review_step());
?>
<style>
  .rv-item { display: flex; gap: 12px; align-items: flex-start; padding: 11px 0; border-bottom: 1px solid var(--border); }
  .rv-item:last-child { border-bottom: 0; }
  .rv-ico { width: 34px; height: 34px; border-radius: 9px; background: var(--green-light); color: var(--green); display: flex; align-items: center; justify-content: center; flex: 0 0 34px; }
  .rv-t { font-weight: 700; color: var(--text); font-size: 13.5px; word-break: break-all; }
  .rv-d { font-size: 12.5px; color: var(--text-muted); }
  .pw-meter { height: 5px; border-radius: 3px; background: var(--border); overflow: hidden; margin-top: 6px; }
  .pw-meter span { display: block; height: 100%; width: 0; transition: width .2s, background .2s; }
  .pw-hint { font-size: 11.5px; color: var(--text-muted); margin-top: 4px; }
  .rv-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
  @media (max-width: 600px) { .rv-grid { grid-template-columns: 1fr; } .co-bar .co-secure:last-child { display: none; } }
</style>

<form method="POST" id="step4" novalidate>
<input type="hidden" name="csrf_token" value="<?php echo portal_csrf(); ?>" />
<input type="hidden" name="checkout_nonce" value="<?php echo h($_SESSION['checkout_nonce']); ?>" />
<input type="hidden" name="auth_mode" id="authMode" value="<?php echo h($mode); ?>" />

<div class="co-grid">
  <div>
    <?php if ($errors): ?>
      <div class="p-alert p-alert-error" style="margin-bottom:16px" role="alert">
        <i class="fas fa-circle-exclamation"></i>
        <?php if (count($errors) === 1): ?><?php echo h($errors[0]); ?>
        <?php else: ?><ul style="margin:4px 0 0 18px"><?php foreach ($errors as $e): ?><li><?php echo h($e); ?></li><?php endforeach; ?></ul><?php endif; ?>
      </div>
    <?php endif; ?>

    <div class="co-panel">
      <div class="co-panel-head" style="display:flex;justify-content:space-between;align-items:center;gap:10px">
        <div><h2>Your order</h2></div>
        <a href="<?php echo PORTAL_URL; ?>/cart.php" class="co-back"><i class="fas fa-pen"></i> Edit cart</a>
      </div>
      <div class="co-panel-body" style="padding-top:6px;padding-bottom:6px">
        <?php foreach ($packages as $uid => $pk): $p = $pk['plan']; ?>
          <div class="rv-item">
            <span class="rv-ico"><i class="fas fa-server"></i></span>
            <div style="flex:1">
              <div class="rv-t"><?php echo h($p['name']); ?> — <?php echo h(ucfirst($p['category'])); ?> hosting</div>
              <div class="rv-d">Billed <?php echo h(str_replace('_', ' ', $p['billing_cycle'])); ?> · on
                <strong style="font-family:ui-monospace,Menlo,monospace"><?php echo h((string) $pk['domain_name']); ?></strong>
                (<?php echo h($mode_label[$pk['domain_mode']] ?? ''); ?><?php echo !empty($pk['id_protection']) ? ', ID Protection' : ''; ?>)</div>
            </div>
            <a href="<?php echo PORTAL_URL; ?>/cart.php?edit_pkg=<?php echo urlencode((string) $uid); ?>" class="co-back">Change</a>
          </div>
        <?php endforeach; ?>
        <?php foreach (OrderCart::domains() as $d => $it): ?>
          <div class="rv-item">
            <span class="rv-ico"><i class="fas <?php echo $it['mode'] === 'transfer' ? 'fa-right-left' : 'fa-globe'; ?>"></i></span>
            <div style="flex:1">
              <div class="rv-t" style="font-family:ui-monospace,Menlo,monospace"><?php echo h($d); ?></div>
              <div class="rv-d"><?php echo $it['mode'] === 'transfer' ? 'Transfer to us (adds 1 year)' : 'Registration · ' . $it['years'] . ' year' . ($it['years'] > 1 ? 's' : ''); ?><?php echo $it['id_protection'] ? ' · ID Protection' : ''; ?></div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

    <?php if ($logged_in): ?>
      <div class="co-panel">
        <div class="co-panel-head"><h2>Your account</h2></div>
        <div class="co-panel-body">
          <div class="p-alert p-alert-success" style="margin:0">
            <i class="fas fa-circle-check"></i>
            Signed in as <strong><?php echo h(trim(($me['first_name'] ?? '') . ' ' . ($me['last_name'] ?? ''))); ?></strong> (<?php echo h($me['email'] ?? ''); ?>). This order will be added to your account.
            <a href="<?php echo PORTAL_URL; ?>/logout.php" style="margin-left:6px">Not you?</a>
          </div>
        </div>
      </div>
    <?php else: ?>
      <div class="co-panel">
        <div class="co-panel-head">
          <h2>Your client account</h2>
          <p>Your services, invoices and support live in your client area. Create your account now, or sign in if you already have one.</p>
        </div>
        <div class="co-panel-body">
          <div style="display:flex;gap:8px;margin-bottom:18px;flex-wrap:wrap" role="tablist">
            <button type="button" class="btn <?php echo $mode === 'new' ? 'btn-primary' : 'btn-ghost'; ?>" data-auth="new" style="<?php echo $mode === 'new' ? '' : 'border:1px solid var(--border)'; ?>"><i class="fas fa-user-plus"></i> Create an account</button>
            <button type="button" class="btn <?php echo $mode === 'existing' ? 'btn-primary' : 'btn-ghost'; ?>" data-auth="existing" style="<?php echo $mode === 'existing' ? '' : 'border:1px solid var(--border)'; ?>"><i class="fas fa-right-to-bracket"></i> I already have an account</button>
          </div>

          <div data-pane="new" style="display:<?php echo $mode === 'new' ? 'block' : 'none'; ?>">
            <div class="rv-grid">
              <div class="form-group"><label class="form-label" for="fn">First name <span style="color:var(--danger)">*</span></label>
                <input type="text" id="fn" name="first_name" class="form-control" value="<?php echo h($data['first_name']); ?>" autocomplete="given-name" /></div>
              <div class="form-group"><label class="form-label" for="ln">Last name <span style="color:var(--danger)">*</span></label>
                <input type="text" id="ln" name="last_name" class="form-control" value="<?php echo h($data['last_name']); ?>" autocomplete="family-name" /></div>
              <div class="form-group"><label class="form-label" for="em">Email address <span style="color:var(--danger)">*</span></label>
                <input type="email" id="em" name="email" class="form-control" value="<?php echo h($data['email']); ?>" autocomplete="email" />
                <div class="pw-hint">Your sign-in, invoices and service details go here.</div></div>
              <div class="form-group"><label class="form-label" for="ph">Mobile number <span style="color:var(--danger)">*</span></label>
                <input type="tel" id="ph" name="phone" class="form-control" placeholder="0712 345 678" value="<?php echo h($data['phone']); ?>" autocomplete="tel" />
                <div class="pw-hint">For M-Pesa payments and service alerts.</div></div>
              <div class="form-group"><label class="form-label" for="co">Company <span style="color:var(--text-muted);font-weight:400">(optional)</span></label>
                <input type="text" id="co" name="company" class="form-control" value="<?php echo h($data['company']); ?>" autocomplete="organization" /></div>
              <div class="form-group"><label class="form-label" for="cty">Country</label>
                <select id="cty" name="country" class="form-select" autocomplete="country-name">
                  <?php foreach (get_countries() as $c): ?>
                    <option value="<?php echo h($c); ?>" <?php echo $data['country'] === $c ? 'selected' : ''; ?>><?php echo h($c); ?></option>
                  <?php endforeach; ?>
                </select></div>
              <div class="form-group"><label class="form-label" for="pw">Password <span style="color:var(--danger)">*</span></label>
                <div style="position:relative">
                  <input type="password" id="pw" name="password" class="form-control" autocomplete="new-password" style="padding-right:62px" />
                  <button type="button" data-show="pw" style="position:absolute;right:6px;top:50%;transform:translateY(-50%);border:0;background:none;color:var(--text-muted);font-size:12px;cursor:pointer">Show</button>
                </div>
                <div class="pw-meter"><span id="pwBar"></span></div>
                <div class="pw-hint" id="pwHint">At least 10 characters, with 3 of: lowercase, uppercase, numbers, symbols.</div></div>
              <div class="form-group"><label class="form-label" for="pw2">Confirm password <span style="color:var(--danger)">*</span></label>
                <input type="password" id="pw2" name="password2" class="form-control" autocomplete="new-password" />
                <div class="pw-hint" id="pw2Hint"></div></div>
            </div>
          </div>

          <div data-pane="existing" style="display:<?php echo $mode === 'existing' ? 'block' : 'none'; ?>">
            <div class="form-group"><label class="form-label" for="le">Email or username</label>
              <input type="text" id="le" name="login_email" class="form-control" value="<?php echo h($_POST['login_email'] ?? ''); ?>" autocomplete="username" autocapitalize="none" /></div>
            <div class="form-group"><label class="form-label" for="lp">Password</label>
              <input type="password" id="lp" name="login_password" class="form-control" autocomplete="current-password" /></div>
            <p style="font-size:12.5px;color:var(--text-muted);margin:0">
              <a href="<?php echo PORTAL_URL; ?>/forgot-password.php" target="_blank" rel="noopener">Forgotten your password?</a>
              Your cart is kept while you reset it.
            </p>
          </div>
        </div>
      </div>
    <?php endif; ?>

    <div class="co-panel">
      <div class="co-panel-body">
        <label style="display:flex;gap:10px;align-items:flex-start;font-size:13px;color:var(--text-2);cursor:pointer">
          <input type="checkbox" name="accept_tos" value="1" <?php echo !empty($_POST['accept_tos']) ? 'checked' : ''; ?> style="margin-top:3px" />
          <span>I have read and agree to the <a href="<?php echo h($site); ?>/legal.html#terms" target="_blank" rel="noopener">Terms of Service</a>
            and <a href="<?php echo h($site); ?>/legal.html" target="_blank" rel="noopener">Privacy Policy</a><?php echo OrderCart::domains() || array_filter($packages, fn($pk) => in_array($pk['domain_mode'], ['register', 'transfer'], true)) ? ', and confirm the domain contact details are mine' : ''; ?>.</span>
        </label>
      </div>
    </div>

    <div class="co-panel">
      <div class="co-panel-head"><h2>What happens next</h2></div>
      <div class="co-panel-body" style="font-size:13px;color:var(--text-2);line-height:1.8">
        <div><i class="fas fa-file-invoice" style="color:var(--green);width:18px"></i> We raise one invoice for everything and email it to you.</div>
        <div><i class="fas fa-credit-card" style="color:var(--green);width:18px"></i> Pay straight away by M-Pesa, card or bank — or later from your client area.</div>
        <div><i class="fas fa-rocket" style="color:var(--green);width:18px"></i> Once paid, <?php echo $packages ? 'your hosting is set up and ' : ''; ?>your domains are registered automatically.</div>
        <?php if (!$logged_in): ?><div><i class="fas fa-user-shield" style="color:var(--green);width:18px"></i> You're signed in to your new client area right away.</div><?php endif; ?>
      </div>
    </div>

    <div class="co-actions">
      <a href="<?php echo PORTAL_URL; ?>/cart.php" class="co-back"><i class="fas fa-arrow-left"></i> Back to cart</a>
    </div>
  </div>

  <?php checkout_summary($sum, $currency, 'step4', 'Complete Order'); ?>
</div>
</form>

<script>
(function () {
  var form = document.getElementById('step4');
  document.querySelectorAll('[data-auth]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var want = btn.dataset.auth;
      document.getElementById('authMode').value = want;
      document.querySelectorAll('[data-pane]').forEach(function (p) { p.style.display = p.dataset.pane === want ? 'block' : 'none'; });
      document.querySelectorAll('[data-auth]').forEach(function (b) {
        var on = b === btn;
        b.classList.toggle('btn-primary', on); b.classList.toggle('btn-ghost', !on);
        b.style.border = on ? '' : '1px solid var(--border)';
      });
    });
  });
  document.querySelectorAll('[data-show]').forEach(function (b) {
    b.addEventListener('click', function () {
      var i = document.getElementById(b.dataset.show), show = i.type === 'password';
      i.type = show ? 'text' : 'password'; b.textContent = show ? 'Hide' : 'Show';
    });
  });

  // Strength meter — mirrors password_policy_errors(); the server decides.
  var pw = document.getElementById('pw'), pw2 = document.getElementById('pw2');
  if (pw) {
    var bar = document.getElementById('pwBar'), hint = document.getElementById('pwHint');
    pw.addEventListener('input', function () {
      var v = pw.value, cls = [/[a-z]/, /[A-Z]/, /[0-9]/, /[^A-Za-z0-9]/].filter(function (r) { return r.test(v); }).length;
      var ok = v.length >= 10 && cls >= 3, score = Math.min(4, (v.length >= 10) + (v.length >= 14) + Math.max(0, cls - 1));
      bar.style.width = (v ? Math.max(15, score * 25) : 0) + '%';
      bar.style.background = ok ? (score >= 4 ? 'var(--green)' : '#65a30d') : (v.length >= 6 ? '#d97706' : 'var(--danger)');
      hint.textContent = !v ? 'At least 10 characters, with 3 of: lowercase, uppercase, numbers, symbols.'
        : ok ? (score >= 4 ? 'Strong password.' : 'Good — longer is even better.')
        : (v.length < 10 ? (10 - v.length) + ' more character' + (10 - v.length === 1 ? '' : 's') + ' needed.' : 'Mix in uppercase, numbers or symbols.');
    });
    pw2.addEventListener('input', function () {
      document.getElementById('pw2Hint').textContent = pw2.value && pw2.value !== pw.value ? 'Passwords don\'t match yet.' : '';
    });
  }

  // One submit only.
  form.addEventListener('submit', function (e) {
    if (form.dataset.sent) { e.preventDefault(); return; }
    form.dataset.sent = '1';
    setTimeout(function () {
      document.querySelectorAll('button[form="step4"]').forEach(function (b) { b.disabled = true; b.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Placing your order…'; });
    }, 0);
  });
  window.addEventListener('pageshow', function () { form.dataset.sent = ''; });
})();
</script>

<?php checkout_foot(); ?>
