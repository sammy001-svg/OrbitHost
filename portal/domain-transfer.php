<?php
/**
 * OrbitHost — self-service domain transfer-in.
 * Two steps: (1) client enters the domain + auth/EPP code, we look up
 * transfer pricing for its TLD; (2) client pays, and on confirmation we
 * submit the transfer request to the active registrar. Transfers are
 * not instant — success here means "accepted for processing", and the
 * domain is recorded as pending until it completes (5–7 days typical).
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/domain_payment.php';
require_once dirname(__DIR__) . '/admin/includes/DomainClient.php';
require_once dirname(__DIR__) . '/admin/includes/Notifier.php';

portal_check();
$client_id = (int) current_client()['id'];
$client = db()->prepare('SELECT * FROM clients WHERE id = ?');
$client->execute([$client_id]);
$client = $client->fetch();

$reg_key  = Provider::activeFor('registrar');
$gateways = dp_active_gateways();

$view = 'form'; $error = ''; $push_msg = ''; $pay_id = 0; $tf_ok = null; $tf_note = '';
$domain = trim($_POST['domain'] ?? ''); $auth_code = trim($_POST['auth_code'] ?? ''); $years = max(1, min(5, (int) ($_POST['years'] ?? 1)));
$tld_row = null; $currency = defined('CURRENCY') ? CURRENCY : 'USD';

function tf_price_lookup(string $domain): ?array
{
    $domain = strtolower(preg_replace('/[^a-z0-9.-]/i', '', $domain));
    if (!str_contains($domain, '.')) return null;
    [, $tld] = array_pad(explode('.', $domain, 2), 2, '');
    try {
        $t = db()->prepare('SELECT * FROM domain_tlds WHERE tld = ? AND is_active = 1');
        $t->execute([$tld]);
        $row = $t->fetch();
        return $row ?: null;
    } catch (\Throwable $e) {
        return null;
    }
}

// ── Verify / complete a pending payment ──
if (isset($_GET['pay'])) {
    $pay_id = (int) $_GET['pay'];
    $result = dp_verify($pay_id, $client_id);

    if (!$result['ok']) {
        if (!empty($result['failed'])) {
            $view = 'failed';
            $error = $result['message'] ?? 'Payment failed.';
            if (empty($result['already'])) {
                Notifier::send('payment_failed', $client_id, [
                    'client_name' => trim($client['first_name'] . ' ' . $client['last_name']),
                    'amount' => $result['payment']['currency'] . ' ' . number_format($result['payment']['amount'], 2),
                    'gateway' => ucfirst($result['payment']['gateway']), 'reason' => $error,
                    'email' => $client['email'], 'link' => PORTAL_URL . '/domain-transfer.php',
                ]);
            }
        } else {
            $view = !empty($result['pending']) ? 'pending' : 'form';
            $error = $result['message'] ?? 'Payment could not be confirmed.';
        }
    } else {
        $view = 'success';
        if (empty($result['already'])) {
            $ctx    = dp_context($result['payment']);
            $domain = $ctx['domain'] ?? '';
            $code   = $ctx['auth_code'] ?? '';
            $years  = max(1, min(5, (int) ($ctx['years'] ?? 1)));

            $inv_no = '';
            if ($result['payment']['invoice_id']) {
                $invstmt = db()->prepare('SELECT invoice_number FROM invoices WHERE id = ?');
                $invstmt->execute([$result['payment']['invoice_id']]);
                $inv_no = $invstmt->fetchColumn() ?: '';
            }
            $paid_amount = $result['payment']['currency'] . ' ' . number_format($result['payment']['amount'], 2);
            Notifier::send('invoice_paid', $client_id, [
                'client_name' => trim($client['first_name'] . ' ' . $client['last_name']),
                'invoice_number' => $inv_no, 'amount' => $paid_amount,
                'gateway' => ucfirst($result['payment']['gateway']), 'email' => $client['email'],
                'link' => PORTAL_URL . '/domains.php',
            ]);
            Notifier::sendToAllAdmins('order_new_admin', [
                'client_name' => trim($client['first_name'] . ' ' . $client['last_name']),
                'item' => 'Domain transfer: ' . $domain, 'amount' => $paid_amount,
                'gateway' => ucfirst($result['payment']['gateway']),
                'link' => APP_URL . '/integrations/domains/',
            ]);

            try {
                $rr = Provider::registrar($reg_key)->transfer($domain, $code, [
                    'first_name' => $client['first_name'], 'last_name' => $client['last_name'],
                    'email' => $client['email'], 'phone' => $client['phone'] ?: '+254700000000',
                    'company' => $client['company'] ?? '', 'country_code' => dp_iso_country($client['country'] ?? 'Kenya'),
                ], $years);
                $tf_ok = !empty($rr['success']);
                $tf_note = $rr['message'] ?? '';
            } catch (\Throwable $e) {
                $tf_ok = false;
                $tf_note = $e->getMessage();
            }

            try {
                db()->prepare('INSERT INTO domain_registrations (client_id, domain_name, registrar, registration_date, status, auto_renew)
                               VALUES (?,?,?,CURDATE(),?,1)
                               ON DUPLICATE KEY UPDATE status = VALUES(status)')
                    ->execute([$client_id, $domain, $reg_key, $tf_ok ? 'pending' : 'pending']);
            } catch (\Throwable $e) { /* keep going — payment + transfer request already happened */ }
        }
    }
}

// ── Step 1: look up transfer price for the entered domain ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'quote') {
    portal_csrf_verify();
    if (!$domain || !str_contains($domain, '.')) {
        $error = 'Enter a full domain name, e.g. mybusiness.com';
    } elseif (strlen($auth_code) < 4) {
        $error = 'Enter the auth/EPP code from your current registrar.';
    } else {
        $tld_row = tf_price_lookup($domain);
        if (!$reg_key) {
            $error = 'Domain transfers are temporarily unavailable — no registrar is active.';
        } elseif (!$tld_row || (float) $tld_row['transfer_price'] <= 0) {
            $error = "We don't have online transfer pricing set up for that extension yet.";
        } else {
            $currency = $tld_row['currency'];
            $view = 'quote';
        }
    }
}

// ── Step 2: pay ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'pay') {
    portal_csrf_verify();
    $tld_row = tf_price_lookup($domain);
    if (!$reg_key || !$tld_row || (float) $tld_row['transfer_price'] <= 0) {
        $error = 'This domain can no longer be quoted for transfer — please start again.';
    } else {
        $currency = $tld_row['currency'];
        $gw = $_POST['gateway'] ?? '';
        $total = round((float) $tld_row['transfer_price'] * $years, 2);

        if (!isset($gateways[$gw])) {
            $error = 'Please choose a payment method.';
            $view = 'quote';
        } else {
            $invoice_id = dp_create_invoice($client_id, 'Domain transfer: ' . $domain . ' (' . $years . ' yr)', $total);
            $return = PORTAL_URL . '/domain-transfer.php';
            $start  = dp_start_payment(
                $client_id, $invoice_id, $total, $currency, $gw,
                ['name' => trim($client['first_name'] . ' ' . $client['last_name']), 'email' => $client['email'], 'phone' => trim($_POST['mpesa_phone'] ?? '') ?: $client['phone']],
                $return, $return,
                ['action' => 'transfer', 'domain' => $domain, 'auth_code' => $auth_code, 'years' => $years]
            );
            $pay_id = $start['pay_id'];
            $r = $start['result'];

            if (empty($r['success'])) {
                $error = $r['message'] ?? 'The payment gateway rejected the request.';
                $view = 'quote';
            } elseif (($r['mode'] ?? '') === 'redirect' && !empty($r['redirect_url'])) {
                header('Location: ' . $r['redirect_url']);
                exit;
            } else {
                $view = 'pending';
                $push_msg = $r['message'] ?? 'Payment request sent — approve it, then click Verify below.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Transfer Domain — OrbitHost</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" crossorigin="anonymous" />
  <link rel="stylesheet" href="<?php echo PORTAL_URL; ?>/css/portal.css" />
  <style>
    body { background: var(--navy); min-height: 100vh; padding: 32px 16px; }
    .co-wrap { max-width: 560px; margin: 0 auto; }
    .co-brand { text-align: center; margin-bottom: 24px; }
    .co-orb { width: 50px; height: 50px; background: var(--green); border-radius: 14px; display: flex; align-items: center; justify-content: center; font-size: 22px; font-weight: 800; color: #fff; margin: 0 auto 12px; }
    .co-brand h1 { color: #fff; font-size: 20px; font-weight: 700; }
    .co-card { background: #fff; border-radius: 16px; padding: 28px; box-shadow: 0 20px 60px rgba(0,0,0,.3); }
    .co-row { display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid var(--border); font-size: 13.5px; }
    .co-row:last-of-type { border-bottom: none; }
    .co-domain { font-family: ui-monospace, Menlo, monospace; font-weight: 600; color: var(--navy); }
    .co-total { display: flex; justify-content: space-between; padding-top: 14px; margin-top: 6px; border-top: 2px solid var(--border); font-size: 17px; font-weight: 800; color: var(--navy); }
    .gw-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin: 18px 0; }
    .gw-opt { display: flex; align-items: center; gap: 10px; border: 1px solid var(--border); border-radius: 10px; padding: 12px; cursor: pointer; font-weight: 600; font-size: 13.5px; }
    .gw-opt:has(input:checked) { border-color: var(--green); box-shadow: 0 0 0 2px rgba(26,138,69,.15); }
    .gw-icon { width: 34px; height: 34px; border-radius: 9px; display: flex; align-items: center; justify-content: center; color: #fff; font-size: 15px; flex-shrink: 0; }
    .co-error { background: #fee2e2; color: #991b1b; padding: 11px 14px; border-radius: 8px; font-size: 13px; margin-bottom: 16px; }
    .co-info  { background: #eff6ff; color: #1e40af; padding: 11px 14px; border-radius: 8px; font-size: 13px; margin-bottom: 16px; }
    .btn-pay { width: 100%; justify-content: center; padding: 13px; font-size: 15px; font-weight: 700; }
    .back-link { display: block; text-align: center; margin-top: 16px; font-size: 12.5px; color: rgba(255,255,255,.45); }
    .years-select, .tf-input { width: 100%; padding: 9px 12px; border: 1px solid var(--border); border-radius: 8px; font-size: 13.5px; margin-bottom: 14px; font-family: inherit; }
    .mpesa-phone { display: none; margin-bottom: 14px; }
    label.tf-label { font-size: 13px; font-weight: 600; display: block; margin-bottom: 6px; }
  </style>
</head>
<body>
<div class="co-wrap">
  <div class="co-brand">
    <div class="co-orb">O</div>
    <h1>Transfer a Domain to OrbitHost</h1>
  </div>

  <div class="co-card">
    <?php if ($error && !in_array($view, ['pending'], true)): ?><div class="co-error"><i class="fas fa-triangle-exclamation"></i> <?php echo htmlspecialchars($error); ?></div><?php endif; ?>

    <?php if ($view === 'success'): ?>
      <div style="text-align:center;margin-bottom:18px">
        <i class="fas fa-circle-check" style="font-size:44px;color:var(--green)"></i>
        <h2 style="font-size:18px;margin-top:12px">Payment received!</h2>
      </div>
      <p style="font-size:13.5px;color:var(--text-muted)"><?php echo htmlspecialchars($tf_note ?: 'Your transfer request has been submitted.'); ?></p>
      <?php if ($tf_ok === false): ?><p style="font-size:12.5px;color:var(--warning);margin-top:8px"><i class="fas fa-triangle-exclamation"></i> Our team has been notified and will complete this manually.</p><?php endif; ?>
      <a href="<?php echo PORTAL_URL; ?>/domains.php" class="btn btn-primary btn-pay" style="margin-top:20px"><i class="fas fa-globe"></i> Back to My Domains</a>

    <?php elseif ($view === 'pending'): ?>
      <div class="co-info"><i class="fas fa-mobile-screen"></i> <?php echo htmlspecialchars($push_msg ?: 'Waiting for payment confirmation.'); ?></div>
      <?php if ($error): ?><div class="co-error" style="background:#fffbeb;color:#92400e"><i class="fas fa-hourglass-half"></i> <?php echo htmlspecialchars($error); ?></div><?php endif; ?>
      <a href="<?php echo PORTAL_URL; ?>/domain-transfer.php?pay=<?php echo $pay_id; ?>" class="btn btn-primary btn-pay"><i class="fas fa-rotate"></i> I've paid — verify now</a>

    <?php elseif ($view === 'failed'): ?>
      <div style="text-align:center;margin-bottom:14px">
        <i class="fas fa-circle-xmark" style="font-size:40px;color:var(--danger)"></i>
        <h2 style="font-size:17px;margin-top:10px">Payment failed</h2>
      </div>
      <p style="font-size:13.5px;color:var(--text-muted);margin-bottom:16px"><?php echo htmlspecialchars($error); ?></p>
      <a href="<?php echo PORTAL_URL; ?>/domain-transfer.php" class="btn btn-primary btn-pay"><i class="fas fa-rotate"></i> Start Over</a>

    <?php elseif ($view === 'quote'): ?>
      <div class="co-row"><span class="co-domain"><?php echo htmlspecialchars($domain); ?></span><span>Transfer + 1yr renewal</span></div>

      <form method="POST" style="margin-top:16px">
        <input type="hidden" name="csrf_token" value="<?php echo portal_csrf(); ?>" />
        <input type="hidden" name="action" value="pay" />
        <input type="hidden" name="domain" value="<?php echo htmlspecialchars($domain); ?>" />
        <input type="hidden" name="auth_code" value="<?php echo htmlspecialchars($auth_code); ?>" />

        <label class="tf-label">Years</label>
        <select name="years" class="years-select">
          <?php for ($y = 1; $y <= 5; $y++): ?>
            <option value="<?php echo $y; ?>" <?php echo $y === $years ? 'selected' : ''; ?>><?php echo $y; ?> year<?php echo $y > 1 ? 's' : ''; ?> — <?php echo htmlspecialchars($currency); ?> <?php echo number_format($tld_row['transfer_price'] * $y, 2); ?></option>
          <?php endfor; ?>
        </select>

        <h2 style="font-size:15px;font-weight:700;margin:8px 0 4px">Pay with</h2>
        <?php if (!$gateways): ?>
          <div class="co-info"><i class="fas fa-circle-info"></i> Online payment is being set up — please contact support to complete this transfer.</div>
        <?php else: ?>
          <div class="gw-grid">
            <?php foreach ($gateways as $key => $def): ?>
              <label class="gw-opt">
                <input type="radio" name="gateway" value="<?php echo htmlspecialchars($key); ?>" required data-gw="<?php echo htmlspecialchars($key); ?>" />
                <span class="gw-icon" style="background:<?php echo htmlspecialchars($def['color']); ?>"><i class="fas <?php echo htmlspecialchars($def['icon']); ?>"></i></span>
                <?php echo htmlspecialchars($def['name']); ?>
              </label>
            <?php endforeach; ?>
          </div>
          <div class="mpesa-phone" id="mpesaPhone">
            <label class="tf-label">M-Pesa phone number</label>
            <input type="tel" name="mpesa_phone" class="tf-input" placeholder="07XX XXX XXX" value="<?php echo htmlspecialchars($client['phone'] ?? ''); ?>" />
          </div>
          <button type="submit" class="btn btn-primary btn-pay"><i class="fas fa-lock"></i> Pay &amp; Start Transfer</button>
        <?php endif; ?>
      </form>
      <script>
        document.querySelectorAll('input[name="gateway"]').forEach(function (r) {
          r.addEventListener('change', function () { document.getElementById('mpesaPhone').style.display = r.dataset.gw === 'mpesa' ? 'block' : 'none'; });
        });
      </script>

    <?php else: ?>
      <p style="font-size:13.5px;color:var(--text-muted);margin-bottom:18px">Moving an existing domain to OrbitHost usually takes 5–7 days once your current registrar approves it. We'll handle everything after you pay.</p>
      <form method="POST">
        <input type="hidden" name="csrf_token" value="<?php echo portal_csrf(); ?>" />
        <input type="hidden" name="action" value="quote" />

        <label class="tf-label">Domain name</label>
        <input type="text" name="domain" class="tf-input" placeholder="mybusiness.com" value="<?php echo htmlspecialchars($domain); ?>" required />

        <label class="tf-label">Auth / EPP code</label>
        <input type="text" name="auth_code" class="tf-input" placeholder="From your current registrar" value="<?php echo htmlspecialchars($auth_code); ?>" required />
        <p style="font-size:11.5px;color:var(--text-muted);margin:-8px 0 14px">Ask your current registrar for this code, and make sure the domain is unlocked and at least 60 days old.</p>

        <label class="tf-label">Years to add</label>
        <select name="years" class="years-select">
          <?php for ($y = 1; $y <= 5; $y++): ?><option value="<?php echo $y; ?>"><?php echo $y; ?> year<?php echo $y > 1 ? 's' : ''; ?></option><?php endfor; ?>
        </select>

        <button type="submit" class="btn btn-primary btn-pay"><i class="fas fa-arrow-right"></i> Get Price</button>
      </form>
    <?php endif; ?>
  </div>

  <a href="<?php echo PORTAL_URL; ?>/domains.php" class="back-link">← Back to My Domains</a>
</div>
</body>
</html>
