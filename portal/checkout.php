<?php
/**
 * OrbitHost — Domain checkout
 * Cart → sign-in gate → invoice → gateway payment → domain registration.
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once dirname(__DIR__) . '/admin/includes/functions.php';
require_once dirname(__DIR__) . '/admin/includes/providers/Provider.php';
require_once dirname(__DIR__) . '/admin/includes/DomainClient.php';
require_once dirname(__DIR__) . '/admin/includes/Notifier.php';

portal_start();

// ── Sign-in gate: remember where to come back to ──
if (empty($_SESSION['client_id'])) {
    $_SESSION['post_login_redirect'] = 'checkout.php';
    header('Location: ' . PORTAL_URL . '/login.php?next=checkout');
    exit;
}

$client_id = (int)$_SESSION['client_id'];
$client = db()->prepare('SELECT * FROM clients WHERE id = ?');
$client->execute([$client_id]);
$client = $client->fetch();

$cart = $_SESSION['cart_domains'] ?? [];

// ── Price the cart ──
$tld_rows = [];
try {
    foreach (db()->query('SELECT tld, currency, register_price FROM domain_tlds WHERE is_active = 1')->fetchAll() as $r) {
        $tld_rows[$r['tld']] = $r;
    }
} catch (\Throwable $e) { /* handled below */ }

$items = []; $total = 0.0; $currency = defined('CURRENCY') ? CURRENCY : 'USD';
foreach ($cart as $domain => $it) {
    $p = $tld_rows[$it['tld']] ?? null;
    if (!$p) continue;
    $line = (float)$p['register_price'] * $it['years'];
    $total += $line;
    $currency = $p['currency'];
    $items[] = ['domain' => $domain, 'years' => $it['years'], 'unit' => (float)$p['register_price'], 'line' => $line];
}

// ── Active gateways ──
$gateways = [];
foreach (ProviderRegistry::byCategory('payment') as $key => $def) {
    if (Provider::isActive($key) && Provider::isConfigured($key)) $gateways[$key] = $def;
}

function iso_country(string $name): string
{
    $map = ['Kenya'=>'KE','Uganda'=>'UG','Tanzania'=>'TZ','Rwanda'=>'RW','Ethiopia'=>'ET','Nigeria'=>'NG','Ghana'=>'GH',
            'South Africa'=>'ZA','Egypt'=>'EG','Morocco'=>'MA','USA'=>'US','United Kingdom'=>'GB','Canada'=>'CA',
            'Australia'=>'AU','Germany'=>'DE','France'=>'FR','India'=>'IN','China'=>'CN','UAE'=>'AE','Saudi Arabia'=>'SA'];
    return $map[$name] ?? 'KE';
}

/** After a confirmed payment: register every cart domain and record it. */
function fulfil_domains(array $items, array $cart, array $client, int $invoice_id): array
{
    $reg_key = Provider::activeFor('registrar');
    $summary = [];
    foreach ($items as $it) {
        $domain = $it['domain'];
        $years  = (int)($cart[$domain]['years'] ?? 1);
        $ok = false; $note = '';
        if ($reg_key) {
            try {
                $r = Provider::registrar($reg_key)->register($domain, [
                    'first_name'   => $client['first_name'],
                    'last_name'    => $client['last_name'],
                    'email'        => $client['email'],
                    'phone'        => $client['phone'] ?: '+254700000000',
                    'company'      => $client['company'] ?? '',
                    'country_code' => iso_country($client['country'] ?? 'Kenya'),
                ], $years);
                $ok   = !empty($r['success']);
                $note = $r['message'] ?? '';
            } catch (\Throwable $e) {
                $note = $e->getMessage();
            }
        } else {
            $note = 'No registrar active — to be registered manually by our team.';
        }

        try {
            db()->prepare('INSERT INTO domain_registrations (client_id, domain_name, registrar, registration_date, expiry_date, status, auto_renew)
                           VALUES (?,?,?,CURDATE(),DATE_ADD(CURDATE(), INTERVAL ? YEAR),?,1)
                           ON DUPLICATE KEY UPDATE status=VALUES(status), expiry_date=VALUES(expiry_date)')
                ->execute([$client['id'], $domain, $reg_key ?: 'manual', $years, $ok ? 'active' : 'pending']);
        } catch (\Throwable $e) {
            // legacy ENUM registrar column — record as manual so the client still sees it
            try {
                db()->prepare('INSERT IGNORE INTO domain_registrations (client_id, domain_name, registrar, registration_date, expiry_date, status, auto_renew)
                               VALUES (?,?,?,CURDATE(),DATE_ADD(CURDATE(), INTERVAL ? YEAR),?,1)')
                    ->execute([$client['id'], $domain, 'manual', $years, $ok ? 'active' : 'pending']);
            } catch (\Throwable $e2) { /* keep going — payment is already recorded */ }
        }
        $summary[] = ['domain' => $domain, 'registered' => $ok, 'note' => $note];
    }
    return $summary;
}

$view = 'form'; $error = ''; $push_msg = ''; $pay_id = 0; $done = [];

// ── Verify a returning / pending payment ──
if (isset($_GET['pay'])) {
    $pay_id = (int)$_GET['pay'];
    $stmt = db()->prepare('SELECT * FROM payments WHERE id = ? AND client_id = ?');
    $stmt->execute([$pay_id, $client_id]);
    $pay = $stmt->fetch();

    if (!$pay) {
        $error = 'Payment record not found.';
    } elseif ($pay['status'] === 'completed') {
        $view = 'success';
        $done = json_decode($pay['raw'] ?? '', true)['fulfilment'] ?? [];
    } else {
        try {
            $v = Provider::payment($pay['gateway'])->verify($pay['gateway_ref']);
            if (!empty($v['success'])) {
                $fulfilment = fulfil_domains($items, $cart, $client, (int)$pay['invoice_id']);
                db()->prepare("UPDATE payments SET status='completed', raw=? WHERE id=?")
                    ->execute([json_encode(['verify' => $v, 'fulfilment' => $fulfilment]), $pay_id]);
                if ($pay['invoice_id']) {
                    db()->prepare("UPDATE invoices SET status='paid', paid_date=CURDATE(), payment_method=? WHERE id=?")
                        ->execute([$pay['gateway'], $pay['invoice_id']]);
                }
                $_SESSION['cart_domains'] = [];
                $view = 'success';
                $done = $fulfilment;

                $registered = array_filter($done, fn($d) => !empty($d['registered']));
                $item_desc  = $registered ? implode(', ', array_column($registered, 'domain')) : 'domain order';
                $inv_no = '';
                if ($pay['invoice_id']) {
                    $invstmt = db()->prepare('SELECT invoice_number FROM invoices WHERE id = ?');
                    $invstmt->execute([$pay['invoice_id']]);
                    $inv_no = $invstmt->fetchColumn() ?: '';
                }

                Notifier::send('invoice_paid', $client_id, [
                    'client_name' => trim($client['first_name'] . ' ' . $client['last_name']),
                    'invoice_number' => $inv_no, 'amount' => $currency . ' ' . number_format($total, 2),
                    'gateway' => ucfirst($pay['gateway']), 'email' => $client['email'],
                    'link' => PORTAL_URL . '/domains.php',
                ]);
                Notifier::send('order_new', $client_id, [
                    'client_name' => trim($client['first_name'] . ' ' . $client['last_name']),
                    'item' => $item_desc, 'amount' => $currency . ' ' . number_format($total, 2),
                    'note' => 'You can manage your domains any time from the client portal.',
                    'email' => $client['email'], 'link' => PORTAL_URL . '/domains.php',
                ]);
                Notifier::sendToAllAdmins('order_new_admin', [
                    'client_name' => trim($client['first_name'] . ' ' . $client['last_name']),
                    'item' => $item_desc, 'amount' => $currency . ' ' . number_format($total, 2),
                    'gateway' => ucfirst($pay['gateway']),
                    'link' => APP_URL . '/integrations/domains/',
                ]);
            } else {
                $view = 'pending';
                $error = $v['message'] ?? ($v['status'] ?? 'Payment not confirmed yet.');
            }
        } catch (\Throwable $e) {
            $view = 'pending';
            $error = $e->getMessage();
        }
    }
}

// ── Start a payment ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'pay') {
    portal_csrf_verify();

    if (!$items) {
        $error = 'Your cart is empty.';
    } else {
        $gw = $_POST['gateway'] ?? '';
        if (!isset($gateways[$gw])) {
            $error = 'Please choose a payment method.';
        } else {
            try {
                // Invoice
                $inv_no = generate_invoice_number();
                db()->prepare("INSERT INTO invoices (invoice_number, client_id, subtotal, tax_rate, tax_amount, total, status, due_date)
                               VALUES (?,?,?,0,0,?, 'sent', CURDATE())")
                    ->execute([$inv_no, $client_id, $total, $total]);
                $invoice_id = (int)db()->lastInsertId();
                $item_stmt = db()->prepare('INSERT INTO invoice_items (invoice_id, description, quantity, unit_price, total) VALUES (?,?,?,?,?)');
                foreach ($items as $it) {
                    $item_stmt->execute([$invoice_id, 'Domain registration: ' . $it['domain'] . ' (' . $it['years'] . ' yr)', $it['years'], $it['unit'], $it['line']]);
                }

                // Payment attempt
                db()->prepare('INSERT INTO payments (invoice_id, client_id, gateway, amount, currency, status) VALUES (?,?,?,?,?,"pending")')
                    ->execute([$invoice_id, $client_id, $gw, $total, $currency]);
                $pay_id = (int)db()->lastInsertId();

                $return = PORTAL_URL . '/checkout.php?pay=' . $pay_id;
                $r = Provider::payment($gw)->createCheckout($total, $currency, $inv_no, [
                    'name'  => $client['first_name'] . ' ' . $client['last_name'],
                    'email' => $client['email'],
                    'phone' => trim($_POST['mpesa_phone'] ?? '') ?: ($client['phone'] ?? ''),
                ], ['return' => $return, 'cancel' => PORTAL_URL . '/checkout.php', 'callback' => $return]);

                if (empty($r['success'])) {
                    $error = $r['message'] ?? 'The payment gateway rejected the request.';
                    db()->prepare("UPDATE payments SET status='failed', raw=? WHERE id=?")->execute([json_encode($r), $pay_id]);
                } else {
                    db()->prepare('UPDATE payments SET gateway_ref=? WHERE id=?')->execute([$r['ref'] ?? '', $pay_id]);
                    if (($r['mode'] ?? '') === 'redirect' && !empty($r['redirect_url'])) {
                        header('Location: ' . $r['redirect_url']);
                        exit;
                    }
                    $view = 'pending';
                    $push_msg = $r['message'] ?? 'Payment request sent — approve it on your phone, then click Verify below.';
                }
            } catch (\Throwable $e) {
                $error = $e->getMessage();
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
  <title>Checkout — OrbitHost</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" crossorigin="anonymous" />
  <link rel="stylesheet" href="<?php echo PORTAL_URL; ?>/css/portal.css" />
  <style>
    body { background: var(--navy); min-height: 100vh; padding: 32px 16px; }
    .co-wrap { max-width: 620px; margin: 0 auto; }
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
    .done-row { display: flex; align-items: center; gap: 10px; padding: 10px 0; border-bottom: 1px solid var(--border); font-size: 13.5px; }
    .mpesa-phone { display: none; margin-bottom: 14px; }
  </style>
</head>
<body>
<div class="co-wrap">
  <div class="co-brand">
    <div class="co-orb">O</div>
    <h1>Secure Checkout</h1>
  </div>

  <div class="co-card">
    <?php if ($error && $view !== 'pending'): ?><div class="co-error"><i class="fas fa-triangle-exclamation"></i> <?php echo htmlspecialchars($error); ?></div><?php endif; ?>

    <?php if ($view === 'success'): ?>
      <div style="text-align:center;margin-bottom:18px">
        <i class="fas fa-circle-check" style="font-size:44px;color:var(--green)"></i>
        <h2 style="font-size:18px;margin-top:12px">Payment received — thank you!</h2>
      </div>
      <?php foreach ($done as $d): ?>
        <div class="done-row">
          <i class="fas <?php echo $d['registered'] ? 'fa-check-circle' : 'fa-clock'; ?>" style="color:<?php echo $d['registered'] ? 'var(--green)' : '#d97706'; ?>"></i>
          <span class="co-domain" style="flex:1"><?php echo htmlspecialchars($d['domain']); ?></span>
          <span style="font-size:12px;color:var(--text-muted)"><?php echo $d['registered'] ? 'Registered' : 'Processing — our team will complete this shortly'; ?></span>
        </div>
      <?php endforeach; ?>
      <a href="<?php echo PORTAL_URL; ?>/domains.php" class="btn btn-primary btn-pay" style="margin-top:20px"><i class="fas fa-globe"></i> View my domains</a>

    <?php elseif ($view === 'pending'): ?>
      <div class="co-info"><i class="fas fa-mobile-screen"></i> <?php echo htmlspecialchars($push_msg ?: 'Waiting for payment confirmation.'); ?></div>
      <?php if ($error): ?><div class="co-error" style="background:#fffbeb;color:#92400e"><i class="fas fa-hourglass-half"></i> <?php echo htmlspecialchars($error); ?></div><?php endif; ?>
      <a href="<?php echo PORTAL_URL; ?>/checkout.php?pay=<?php echo $pay_id; ?>" class="btn btn-primary btn-pay"><i class="fas fa-rotate"></i> I've paid — verify now</a>

    <?php elseif (!$items): ?>
      <div style="text-align:center;padding:24px 0;color:var(--text-muted)">
        <i class="fas fa-cart-shopping" style="font-size:38px;opacity:.25;display:block;margin-bottom:12px"></i>
        <p>Your cart is empty.</p>
        <a href="../domains.html" class="btn btn-primary" style="margin-top:14px;display:inline-flex"><i class="fas fa-magnifying-glass"></i> Search for a domain</a>
      </div>

    <?php elseif (!$gateways): ?>
      <div class="co-info"><i class="fas fa-circle-info"></i> Online payment is being set up. Please contact us to complete your order — your cart is saved.</div>

    <?php else: ?>
      <h2 style="font-size:15px;font-weight:700;margin-bottom:10px">Order summary</h2>
      <?php foreach ($items as $it): ?>
        <div class="co-row">
          <span class="co-domain"><?php echo htmlspecialchars($it['domain']); ?> <span style="color:var(--text-muted);font-weight:400">× <?php echo $it['years']; ?> yr</span></span>
          <span><?php echo htmlspecialchars($currency); ?> <?php echo number_format($it['line'], 2); ?></span>
        </div>
      <?php endforeach; ?>
      <div class="co-total"><span>Total</span><span><?php echo htmlspecialchars($currency); ?> <?php echo number_format($total, 2); ?></span></div>

      <form method="POST" style="margin-top:20px">
        <input type="hidden" name="csrf_token" value="<?php echo portal_csrf(); ?>" />
        <input type="hidden" name="action" value="pay" />
        <h2 style="font-size:15px;font-weight:700;margin-bottom:4px">Pay with</h2>
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
        <button type="submit" class="btn btn-primary btn-pay"><i class="fas fa-lock"></i> Pay <?php echo htmlspecialchars($currency); ?> <?php echo number_format($total, 2); ?></button>
      </form>
      <script>
        document.querySelectorAll('input[name="gateway"]').forEach(function (r) {
          r.addEventListener('change', function () {
            document.getElementById('mpesaPhone').style.display = r.dataset.gw === 'mpesa' ? 'block' : 'none';
          });
        });
      </script>
    <?php endif; ?>
  </div>

  <a href="<?php echo PORTAL_URL; ?>/cart.php" class="back-link">← Back to cart</a>
</div>
</body>
</html>
