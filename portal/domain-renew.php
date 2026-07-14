<?php
/**
 * Orbit Cloud — self-service domain renewal.
 * Client picks years, pays through any active gateway, and on confirmed
 * payment the registrar's renew() is called and the domain's expiry is
 * extended. If payment succeeds but the registrar call fails, the
 * payment is never lost — the domain is flagged for manual follow-up.
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/domain_payment.php';
require_once dirname(__DIR__) . '/admin/includes/DomainClient.php';
require_once dirname(__DIR__) . '/admin/includes/Notifier.php';
require_once dirname(__DIR__) . '/admin/includes/Automation.php';
require_once dirname(__DIR__) . '/admin/includes/Currency.php';

portal_check();
Currency::ensureSchema();
$client_id = (int) current_client()['id'];

$client = db()->prepare('SELECT * FROM clients WHERE id = ?');
$client->execute([$client_id]);
$client = $client->fetch();

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$stmt = db()->prepare('SELECT * FROM domain_registrations WHERE id = ? AND client_id = ?');
$stmt->execute([$id, $client_id]);
$dom = $stmt->fetch();

if (!$dom) {
    portal_flash_set('error', 'Domain not found.');
    header('Location: ' . PORTAL_URL . '/domains.php');
    exit;
}

// ── Can this domain actually be auto-renewed? ──
$reg_key = $dom['registrar'];
$reg_def = ProviderRegistry::get($reg_key);
$registrar_ok = $reg_def && $reg_def['category'] === 'registrar' && Provider::isActive($reg_key) && Provider::isConfigured($reg_key);

[$sld, $tld] = array_pad(explode('.', $dom['domain_name'], 2), 2, '');
$tld_row = null;
try {
    $t = db()->prepare('SELECT * FROM domain_tlds WHERE tld = ? AND is_active = 1');
    $t->execute([$tld]);
    $tld_row = $t->fetch();
} catch (\Throwable $e) { /* table missing */ }

$currency    = Currency::current();
$renew_price = $tld_row ? (float) ($currency === 'KES' ? ($tld_row['renew_price_kes'] ?? 0) : ($tld_row['renew_price_usd'] ?? 0)) : 0.0;
$renewable   = $registrar_ok && $tld_row && $renew_price > 0;
$gateways    = dp_active_gateways($currency);

$view = 'form'; $error = ''; $push_msg = ''; $pay_id = 0; $renew_ok = null; $renew_note = '';

// ── Verify / complete a pending payment ──
// settlePayment() verifies with the gateway and, on success, calls the
// registrar's renew() from context stored at payment-creation time (not
// a page-local variable) — so the reconciliation cron/webhook can finish
// this identically if the client never comes back to this tab.
if (isset($_GET['pay'])) {
    $pay_id = (int) $_GET['pay'];
    $stmt = db()->prepare('SELECT * FROM payments WHERE id = ? AND client_id = ?');
    $stmt->execute([$pay_id, $client_id]);
    $pay_row = $stmt->fetch();

    if (!$pay_row) {
        $view = 'form';
        $error = 'Payment record not found.';
    } elseif ($pay_row['status'] === 'completed') {
        $view = 'success';
        $raw = json_decode($pay_row['raw'] ?? '', true) ?: [];
        $renew_ok   = $raw['renewal']['success'] ?? null;
        $renew_note = $raw['renewal']['message'] ?? '';
    } elseif ($pay_row['status'] === 'failed') {
        $view = 'failed';
        $error = 'This payment attempt failed.';
    } else {
        $r = Automation::settlePayment($pay_id);
        if ($r['status'] === 'completed') {
            $view = 'success';
            $renew_ok   = $r['renewal']['success'] ?? null;
            $renew_note = $r['renewal']['message'] ?? '';
        } elseif ($r['status'] === 'failed') {
            $view = 'failed';
            $error = $r['message'];
        } else {
            $view = 'pending';
            $error = $r['message'];
        }
    }
}

// ── Start a payment ──
if ($renewable && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'pay') {
    portal_csrf_verify();
    $years = max(1, min(5, (int) ($_POST['years'] ?? 1)));
    $gw    = $_POST['gateway'] ?? '';
    $total = round($renew_price * $years, 2);

    if (!isset($gateways[$gw])) {
        $error = 'Please choose a payment method.';
    } else {
        $invoice_id = dp_create_invoice($client_id, 'Domain renewal: ' . $dom['domain_name'] . ' (' . $years . ' yr)', $total, $currency);
        $return = PORTAL_URL . '/domain-renew.php?id=' . $id;
        $start  = dp_start_payment(
            $client_id, $invoice_id, $total, $currency, $gw,
            ['name' => trim($client['first_name'] . ' ' . $client['last_name']), 'email' => $client['email'], 'phone' => trim($_POST['mpesa_phone'] ?? '') ?: $client['phone']],
            $return, PORTAL_URL . '/domain-renew.php?id=' . $id,
            ['action' => 'renew', 'domain_id' => $id, 'years' => $years]
        );
        $pay_id = $start['pay_id'];
        $r = $start['result'];

        if (empty($r['success'])) {
            $error = $r['message'] ?? 'The payment gateway rejected the request.';
        } elseif (($r['mode'] ?? '') === 'redirect' && !empty($r['redirect_url'])) {
            header('Location: ' . $r['redirect_url']);
            exit;
        } else {
            $view = 'pending';
            $push_msg = $r['message'] ?? 'Payment request sent — approve it, then click Verify below.';
        }
    }
}

$days_left = $dom['expiry_date'] ? (int) ceil((strtotime($dom['expiry_date']) - time()) / 86400) : null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Renew Domain — Orbit Cloud</title>
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
    .years-select { width: 100%; padding: 9px 12px; border: 1px solid var(--border); border-radius: 8px; font-size: 13.5px; }
    .mpesa-phone { display: none; margin-bottom: 14px; }
  </style>
</head>
<body>
<div class="co-wrap">
  <div class="co-brand">
    <div class="co-orb">O</div>
    <h1>Renew Domain</h1>
  </div>

  <div class="co-card">
    <?php if ($error && $view !== 'pending'): ?><div class="co-error"><i class="fas fa-triangle-exclamation"></i> <?php echo htmlspecialchars($error); ?></div><?php endif; ?>

    <?php if ($view === 'success'): ?>
      <div style="text-align:center;margin-bottom:18px">
        <i class="fas fa-circle-check" style="font-size:44px;color:var(--green)"></i>
        <h2 style="font-size:18px;margin-top:12px">Payment received — thank you!</h2>
      </div>
      <div class="co-row"><span class="co-domain"><?php echo htmlspecialchars($dom['domain_name']); ?></span>
        <span><?php echo $renew_ok === false ? 'Processing' : 'Renewed'; ?></span></div>
      <?php if ($renew_note): ?><p style="font-size:13px;color:var(--text-muted);margin-top:10px"><?php echo htmlspecialchars($renew_note); ?></p><?php endif; ?>
      <a href="<?php echo PORTAL_URL; ?>/domains.php" class="btn btn-primary btn-pay" style="margin-top:20px"><i class="fas fa-globe"></i> Back to My Domains</a>

    <?php elseif ($view === 'pending'): ?>
      <div class="co-info" style="white-space:pre-line;text-align:left"><i class="fas fa-mobile-screen"></i> <?php echo htmlspecialchars($push_msg ?: 'Waiting for payment confirmation.'); ?></div>
      <?php if ($error): ?><div class="co-error" style="background:#fffbeb;color:#92400e"><i class="fas fa-hourglass-half"></i> <?php echo htmlspecialchars($error); ?></div><?php endif; ?>
      <a href="<?php echo PORTAL_URL; ?>/domain-renew.php?id=<?php echo $id; ?>&pay=<?php echo $pay_id; ?>" class="btn btn-primary btn-pay"><i class="fas fa-rotate"></i> I've paid — verify now</a>

    <?php elseif ($view === 'failed'): ?>
      <div style="text-align:center;margin-bottom:14px">
        <i class="fas fa-circle-xmark" style="font-size:40px;color:var(--danger)"></i>
        <h2 style="font-size:17px;margin-top:10px">Payment failed</h2>
      </div>
      <p style="font-size:13.5px;color:var(--text-muted);margin-bottom:16px"><?php echo htmlspecialchars($error); ?></p>
      <a href="<?php echo PORTAL_URL; ?>/domain-renew.php?id=<?php echo $id; ?>" class="btn btn-primary btn-pay"><i class="fas fa-rotate"></i> Try Again</a>

    <?php elseif (!$registrar_ok): ?>
      <div class="co-info"><i class="fas fa-circle-info"></i> This domain isn't linked to an active automated registrar, so it can't be renewed online yet.</div>
      <a href="<?php echo PORTAL_URL; ?>/tickets/add.php?subject=<?php echo urlencode('Renew ' . $dom['domain_name']); ?>" class="btn btn-primary btn-pay"><i class="fas fa-life-ring"></i> Contact Support to Renew</a>

    <?php elseif (!$tld_row || $renew_price <= 0): ?>
      <div class="co-info"><i class="fas fa-circle-info"></i> Online renewal pricing isn't set up for this domain's extension yet.</div>
      <a href="<?php echo PORTAL_URL; ?>/tickets/add.php?subject=<?php echo urlencode('Renew ' . $dom['domain_name']); ?>" class="btn btn-primary btn-pay"><i class="fas fa-life-ring"></i> Contact Support to Renew</a>

    <?php elseif (!$gateways): ?>
      <div class="co-info"><i class="fas fa-circle-info"></i> Online payment is being set up. Please contact us to renew this domain.</div>

    <?php else: ?>
      <div class="co-row"><span class="co-domain"><?php echo htmlspecialchars($dom['domain_name']); ?></span>
        <span><?php echo $days_left !== null ? 'Expires in ' . max(0, $days_left) . ' days' : ''; ?></span></div>

      <form method="POST" id="renewForm" style="margin-top:16px">
        <input type="hidden" name="csrf_token" value="<?php echo portal_csrf(); ?>" />
        <input type="hidden" name="action" value="pay" />
        <input type="hidden" name="id" value="<?php echo $id; ?>" />

        <label style="font-size:13px;font-weight:600;display:block;margin-bottom:6px">Renew for</label>
        <select name="years" id="yearsSelect" class="years-select">
          <?php for ($y = 1; $y <= 5; $y++): ?>
            <option value="<?php echo $y; ?>"><?php echo $y; ?> year<?php echo $y > 1 ? 's' : ''; ?> — <?php echo htmlspecialchars($currency); ?> <?php echo number_format($renew_price * $y, 2); ?></option>
          <?php endfor; ?>
        </select>

        <h2 style="font-size:15px;font-weight:700;margin:18px 0 4px">Pay with</h2>
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
          <label style="font-size:13px;font-weight:600;display:block;margin-bottom:6px">M-Pesa phone number</label>
          <input type="tel" name="mpesa_phone" class="form-control" placeholder="07XX XXX XXX" value="<?php echo htmlspecialchars($client['phone'] ?? ''); ?>" style="width:100%;padding:10px;border:1px solid var(--border);border-radius:8px" />
        </div>
        <button type="submit" class="btn btn-primary btn-pay"><i class="fas fa-lock"></i> Pay &amp; Renew</button>
      </form>
      <script>
        document.querySelectorAll('input[name="gateway"]').forEach(function (r) {
          r.addEventListener('change', function () { document.getElementById('mpesaPhone').style.display = r.dataset.gw === 'kopokopo' ? 'block' : 'none'; });
        });
      </script>
    <?php endif; ?>
  </div>

  <a href="<?php echo PORTAL_URL; ?>/domains.php" class="back-link">← Back to My Domains</a>
</div>
</body>
</html>
