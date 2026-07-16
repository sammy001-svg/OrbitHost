<?php
/**
 * Orbit Cloud — client invoice view + payment.
 * Three ways to settle an invoice: account credit, an online gateway
 * (Stripe/PayPal/M-Pesa STK/Flutterwave — whichever are active for this
 * invoice's currency), or an offline method (bank transfer, manual
 * M-Pesa, cheque) where the client submits a reference/cheque number
 * for the team to verify and confirm in Billing.
 */
require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once dirname(__DIR__, 2) . '/admin/includes/functions.php';
require_once dirname(__DIR__, 2) . '/admin/includes/providers/Provider.php';
require_once dirname(__DIR__, 2) . '/admin/includes/SiteSettings.php';
require_once dirname(__DIR__, 2) . '/admin/includes/Notifier.php';
require_once dirname(__DIR__, 2) . '/admin/includes/Automation.php';
require_once dirname(__DIR__, 2) . '/admin/includes/Currency.php';
require_once dirname(__DIR__, 2) . '/admin/includes/Coupon.php';
require_once __DIR__ . '/../includes/domain_payment.php';
$_invoice_logo = SiteSettings::logoOnNavy(60, 240);

portal_check();
Currency::ensureSchema();
Coupon::ensureInvoiceColumns();
$cid = current_client()['id'];

$id   = (int)($_GET['id'] ?? 0);
$stmt = db()->prepare('SELECT i.*, c.first_name, c.last_name, c.email AS client_email, c.phone AS client_phone, c.company, c.country FROM invoices i JOIN clients c ON c.id=i.client_id WHERE i.id=? AND i.client_id=?');
$stmt->execute([$id, $cid]);
$inv = $stmt->fetch();

if (!$inv) {
    portal_flash_set('error', 'Invoice not found.');
    header('Location: ' . PORTAL_URL . '/invoices/index.php');
    exit;
}

$inv_currency = $inv['currency'] ?: 'USD';
$gateways = dp_active_gateways($inv_currency);
$error = ''; $push_msg = '';

// ── Account credit balance ──
$credit_balance = 0.0;
try {
    $stmt2 = db()->prepare('SELECT COALESCE(SUM(amount),0) FROM client_credits WHERE client_id = ?');
    $stmt2->execute([$cid]);
    $credit_balance = (float) $stmt2->fetchColumn();
} catch (\Throwable $e) { /* table not migrated yet */ }

// ── Returning from an online gateway checkout ──
if (isset($_GET['pay']) && $inv['status'] !== 'paid') {
    $pstmt = db()->prepare('SELECT status FROM payments WHERE id = ? AND client_id = ? AND invoice_id = ?');
    $pstmt->execute([(int) $_GET['pay'], $cid, $id]);
    $pay_status = $pstmt->fetchColumn();
    if ($pay_status && !in_array($pay_status, ['completed', 'failed'], true)) {
        $r = Automation::settlePayment((int) $_GET['pay']);
        if ($r['status'] === 'completed') {
            portal_flash_set('success', 'Payment confirmed — invoice paid. Thank you!');
            header('Location: ' . PORTAL_URL . '/invoices/view.php?id=' . $id);
            exit;
        } elseif ($r['status'] === 'failed') {
            $error = 'Payment failed: ' . $r['message'];
        } else {
            $push_msg = $r['message'] ?: 'Waiting for payment confirmation — refresh in a moment.';
        }
    }
}

// ── Pay with account credit ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'pay_credit' && in_array($inv['status'], ['sent', 'overdue'], true)) {
    portal_csrf_verify();
    if ($credit_balance >= (float) $inv['total']) {
        db()->prepare('INSERT INTO client_credits (client_id, amount, reason, invoice_id) VALUES (?,?,?,?)')
            ->execute([$cid, -1 * (float) $inv['total'], 'Applied to invoice ' . $inv['invoice_number'], $id]);
        db()->prepare("INSERT INTO payments (invoice_id, client_id, gateway, gateway_ref, amount, currency, status) VALUES (?,?,?,?,?,?,'completed')")
            ->execute([$id, $cid, 'credit', 'CREDIT-' . strtoupper(bin2hex(random_bytes(4))), $inv['total'], $inv_currency]);
        db()->prepare("UPDATE invoices SET status='paid', paid_date=CURDATE(), payment_method='Account Credit' WHERE id=?")->execute([$id]);
        Automation::invoicePaid($id);
        Notifier::sendInvoiceEmail($id, 'invoice_paid', ['gateway' => 'Account Credit']);
        portal_flash_set('success', 'Paid with account credit — invoice settled.');
    } else {
        portal_flash_set('error', 'Insufficient account credit to cover this invoice.');
    }
    header('Location: ' . PORTAL_URL . '/invoices/view.php?id=' . $id);
    exit;
}

// ── Pay via an online gateway ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'pay_gateway' && in_array($inv['status'], ['sent', 'overdue'], true)) {
    portal_csrf_verify();
    $gw = $_POST['gateway'] ?? '';
    if (!isset($gateways[$gw])) {
        $error = 'Please choose a payment method.';
    } else {
        $return = PORTAL_URL . '/invoices/view.php?id=' . $id;
        $start  = dp_start_payment(
            $cid, $id, (float) $inv['total'], $inv_currency, $gw,
            ['name' => trim($inv['first_name'] . ' ' . $inv['last_name']), 'email' => $inv['client_email'],
             'phone' => trim($_POST['mpesa_phone'] ?? '') ?: ($inv['client_phone'] ?? '')],
            $return, $return,
            ['action' => 'invoice_payment']
        );
        $r = $start['result'];
        if (empty($r['success'])) {
            $error = $r['message'] ?? 'The payment gateway rejected the request.';
        } elseif (($r['mode'] ?? '') === 'redirect' && !empty($r['redirect_url'])) {
            header('Location: ' . $r['redirect_url']);
            exit;
        } else {
            $push_msg = $r['message'] ?? 'Payment started — complete it, then refresh this page.';
        }
    }
}

// ── Submit a manual payment reference (bank transfer / manual M-Pesa / cheque) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit_reference' && in_array($inv['status'], ['sent', 'overdue'], true)) {
    portal_csrf_verify();
    $gw  = $_POST['gateway'] ?? '';
    $ref = trim($_POST['reference'] ?? '');
    $offline_keys = ['mpesa_manual', 'bank_transfer', 'cheque'];
    if (!in_array($gw, $offline_keys, true) || !Provider::isActive($gw) || !Provider::isConfigured($gw)) {
        portal_flash_set('error', 'Please choose a valid payment method.');
    } elseif ($ref === '' || strlen($ref) < 3) {
        portal_flash_set('error', 'Please enter the reference or cheque number from your payment.');
    } else {
        $existing = db()->prepare("SELECT id FROM payments WHERE invoice_id = ? AND gateway = ? AND status = 'pending' ORDER BY id DESC LIMIT 1");
        $existing->execute([$id, $gw]);
        $existing_id = (int) $existing->fetchColumn();
        if ($existing_id) {
            db()->prepare('UPDATE payments SET gateway_ref = ? WHERE id = ?')->execute([$ref, $existing_id]);
        } else {
            db()->prepare("INSERT INTO payments (invoice_id, client_id, gateway, gateway_ref, amount, currency, status) VALUES (?,?,?,?,?,?,'pending')")
                ->execute([$id, $cid, $gw, $ref, $inv['total'], $inv_currency]);
        }
        $gw_def = ProviderRegistry::get($gw);
        Notifier::sendToAllAdmins('payment_reference_submitted', [
            'client_name'    => trim($inv['first_name'] . ' ' . $inv['last_name']),
            'invoice_number' => $inv['invoice_number'],
            'amount'         => format_money((float) $inv['total'], $inv_currency),
            'gateway'        => $gw_def['name'] ?? ucfirst(str_replace('_', ' ', $gw)),
            'reference'      => $ref,
            'link'           => APP_URL . '/billing/collect.php?invoice_id=' . $id,
        ]);
        portal_flash_set('success', "Thanks! We've received your reference and will confirm within 1 business hour.");
    }
    header('Location: ' . PORTAL_URL . '/invoices/view.php?id=' . $id . '#pay');
    exit;
}

// Latest offline reference submitted per method (pending OR rejected) —
// shown back to the client instead of a blank form so they're not left
// wondering whether it went through, and so a rejected one prompts a
// correction with the reason instead of silently vanishing.
$pending_offline = [];
try {
    $rows = db()->prepare("SELECT gateway, gateway_ref, status, raw, created_at FROM payments WHERE invoice_id = ? AND gateway IN ('mpesa_manual','bank_transfer','cheque') AND status IN ('pending','failed') ORDER BY id DESC");
    $rows->execute([$id]);
    foreach ($rows->fetchAll() as $r) {
        if (!isset($pending_offline[$r['gateway']])) $pending_offline[$r['gateway']] = $r;
    }
} catch (\Throwable $e) { /* payments table always exists — defensive only */ }

$page_title = $inv['invoice_number'];
$items = db()->prepare('SELECT * FROM invoice_items WHERE invoice_id=? ORDER BY id');
$items->execute([$id]);
$items = $items->fetchAll();

require_once '../includes/header.php';
?>

<div class="page-hero">
  <div class="container">
    <div>
      <h1><?php echo htmlspecialchars($inv['invoice_number']); ?></h1>
      <p><?php echo badge($inv['status']); ?></p>
    </div>
    <div style="display:flex;gap:8px" class="no-print">
      <button onclick="window.print()" class="btn btn-white"><i class="fas fa-print"></i> Print</button>
      <?php if (in_array($inv['status'], ['sent','overdue'])): ?>
        <a href="#pay" class="btn btn-primary"><i class="fas fa-credit-card"></i> Pay This Invoice</a>
      <?php endif; ?>
    </div>
  </div>
</div>

<div class="page-body">
<div class="container" style="max-width:820px">

  <?php if ($error): ?>
    <div class="p-alert p-alert-error no-print" style="margin-bottom:16px"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?></div>
  <?php endif; ?>
  <?php if ($push_msg): ?>
    <div class="p-alert p-alert-info no-print" style="margin-bottom:16px;white-space:pre-line"><i class="fas fa-hourglass-half"></i> <?php echo htmlspecialchars($push_msg); ?></div>
  <?php endif; ?>

  <div class="p-card">
    <div class="invoice-doc">

      <!-- Invoice header -->
      <div class="invoice-doc-head">
        <div>
          <?php if ($_invoice_logo): ?>
            <?php echo $_invoice_logo; ?>
          <?php else: ?>
            <div class="inv-logo">Orbit<span>Cloud</span></div>
          <?php endif; ?>
          <div class="invoice-brand-addr">Orbit Cloud Limited<br />Nairobi, Kenya<br />sammyopiyo001@gmail.com</div>
        </div>
        <div class="invoice-title-block">
          <div class="invoice-title">INVOICE</div>
          <?php echo badge($inv['status']); ?>
          <table class="invoice-meta-table">
            <tr><td class="k">Invoice #</td><td class="v"><?php echo htmlspecialchars($inv['invoice_number']); ?></td></tr>
            <tr><td class="k">Date</td><td class="v"><?php echo format_date($inv['created_at']); ?></td></tr>
            <tr><td class="k">Due</td><td class="v" style="<?php echo $inv['status']==='overdue' ? 'color:var(--danger)' : ''; ?>"><?php echo format_date($inv['due_date']); ?></td></tr>
          </table>
        </div>
      </div>

      <!-- Bill to -->
      <div class="invoice-bill-to">
        <div class="invoice-section-label">Bill To</div>
        <div class="invoice-client-name"><?php echo htmlspecialchars($inv['first_name'] . ' ' . $inv['last_name']); ?></div>
        <?php if ($inv['company']): ?><div class="invoice-client-line"><?php echo htmlspecialchars($inv['company']); ?></div><?php endif; ?>
        <div class="invoice-client-line"><?php echo htmlspecialchars($inv['client_email']); ?></div>
        <div class="invoice-client-line"><?php echo htmlspecialchars($inv['country']); ?></div>
      </div>

      <!-- Line items -->
      <table class="invoice-items">
        <thead>
          <tr>
            <th>Description</th>
            <th class="qty">Qty</th>
            <th class="num">Unit Price</th>
            <th class="num">Total</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($items as $it): ?>
          <tr>
            <td><?php echo htmlspecialchars($it['description']); ?></td>
            <td class="qty"><?php echo $it['quantity']; ?></td>
            <td class="num"><?php echo format_money($it['unit_price'], $inv_currency); ?></td>
            <td class="num" style="font-weight:600"><?php echo format_money($it['total'], $inv_currency); ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>

      <!-- Totals -->
      <div class="invoice-totals-wrap">
        <table class="invoice-totals">
          <tr><td class="k">Subtotal</td><td class="v"><?php echo format_money($inv['subtotal'], $inv_currency); ?></td></tr>
          <?php if ($inv['tax_rate'] > 0): ?>
          <tr><td class="k">VAT (<?php echo $inv['tax_rate']; ?>%)</td><td class="v"><?php echo format_money($inv['tax_amount'], $inv_currency); ?></td></tr>
          <?php endif; ?>
          <?php if (!empty($inv['discount_amount']) && $inv['discount_amount'] > 0): ?>
          <tr><td class="k">Discount<?php echo $inv['coupon_code'] ? ' (' . htmlspecialchars($inv['coupon_code']) . ')' : ''; ?></td><td class="v">-<?php echo format_money($inv['discount_amount'], $inv_currency); ?></td></tr>
          <?php endif; ?>
          <tr class="total-row"><td>Total</td><td class="v"><?php echo format_money($inv['total'], $inv_currency); ?></td></tr>
        </table>
      </div>

      <?php if ($inv['notes']): ?>
      <div class="invoice-notes"><?php echo nl2br(htmlspecialchars($inv['notes'])); ?></div>
      <?php endif; ?>

      <div class="invoice-foot-note">Thank you for choosing Orbit Cloud! For queries: sammyopiyo001@gmail.com</div>
    </div>
  </div>

  <?php if (in_array($inv['status'], ['sent','overdue'])): ?>
  <!-- Payment section -->
  <div class="p-card no-print" id="pay" style="margin-top:20px">
    <div class="p-card-header">
      <div class="p-card-title"><i class="fas fa-credit-card" style="color:var(--green);margin-right:8px"></i>Pay This Invoice</div>
      <div><?php echo format_money((float) $inv['total'], $inv_currency); ?></div>
    </div>
    <div class="p-card-body">

      <?php if ($credit_balance > 0): ?>
        <div class="p-alert p-alert-info" style="display:flex;align-items:center;justify-content:space-between;gap:14px;flex-wrap:wrap;margin-bottom:18px">
          <span><i class="fas fa-wallet"></i> Account credit available: <strong><?php echo format_money($credit_balance); ?></strong></span>
          <?php if ($credit_balance >= (float) $inv['total']): ?>
            <form method="POST" style="margin:0">
              <input type="hidden" name="csrf_token" value="<?php echo portal_csrf(); ?>" />
              <input type="hidden" name="action" value="pay_credit" />
              <button type="submit" class="btn btn-primary btn-sm" data-confirm="Pay this invoice using your account credit?"><i class="fas fa-check"></i> Pay with Account Credit</button>
            </form>
          <?php else: ?>
            <span class="text-muted" style="font-size:12.5px">Not enough to cover this invoice in full — use another method below.</span>
          <?php endif; ?>
        </div>
      <?php endif; ?>

      <div class="pay-tabs" role="tablist">
        <?php if ($gateways): ?><button type="button" class="pay-tab active" data-pay-tab="online">Pay Online</button><?php endif; ?>
        <button type="button" class="pay-tab<?php echo $gateways ? '' : ' active'; ?>" data-pay-tab="offline">Bank / M-Pesa / Cheque</button>
      </div>

      <?php if ($gateways): ?>
      <div class="pay-pane active" data-pay-pane="online">
        <form method="POST">
          <input type="hidden" name="csrf_token" value="<?php echo portal_csrf(); ?>" />
          <input type="hidden" name="action" value="pay_gateway" />
          <div class="gw-grid">
            <?php foreach ($gateways as $key => $def): ?>
              <label class="gw-opt">
                <input type="radio" name="gateway" value="<?php echo htmlspecialchars($key); ?>" required data-gw="<?php echo htmlspecialchars($key); ?>" />
                <span class="gw-icon" style="background:<?php echo htmlspecialchars($def['color']); ?>"><i class="fas <?php echo htmlspecialchars($def['icon']); ?>"></i></span>
                <?php echo htmlspecialchars($def['name']); ?>
              </label>
            <?php endforeach; ?>
          </div>
          <div id="invMpesaPhone" style="display:none;margin-bottom:14px">
            <label style="font-size:13px;font-weight:600;display:block;margin-bottom:6px">M-Pesa phone number</label>
            <input type="tel" name="mpesa_phone" class="form-control" placeholder="07XX XXX XXX" value="<?php echo htmlspecialchars($inv['client_phone'] ?? ''); ?>" style="width:100%;padding:10px;border:1px solid var(--border);border-radius:8px" />
          </div>
          <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center;padding:12px;font-weight:700"><i class="fas fa-lock"></i> Pay <?php echo format_money((float) $inv['total'], $inv_currency); ?></button>
        </form>
        <script>
          document.querySelectorAll('input[name="gateway"]').forEach(function (r) {
            r.addEventListener('change', function () {
              document.getElementById('invMpesaPhone').style.display = r.dataset.gw === 'kopokopo' ? 'block' : 'none';
            });
          });
        </script>
      </div>
      <?php endif; ?>

      <div class="pay-pane<?php echo $gateways ? '' : ' active'; ?>" data-pay-pane="offline">
        <?php
        // Real payment details come from the configured offline providers —
        // never hardcoded, so what clients see always matches Providers config.
        $offline_cards = [];
        foreach (ProviderRegistry::byCategory('payment') as $pkey => $pdef) {
            if (empty($pdef['offline']) || !Provider::isActive($pkey) || !Provider::isConfigured($pkey)) continue;
            $pcfg  = Provider::config($pkey);
            $lines = match ($pkey) {
                'mpesa_manual'  => array_filter([
                    'Paybill / Till: <strong>' . h($pcfg['paybill']) . '</strong>',
                    $pcfg['account_name'] !== '' ? 'Name: ' . h($pcfg['account_name']) : null,
                    'Account: <strong>' . h($inv['invoice_number']) . '</strong>',
                ]),
                'bank_transfer' => array_filter([
                    h($pcfg['bank_name']),
                    'Acc name: ' . h($pcfg['account_name']),
                    'Acc no: <strong>' . h($pcfg['account_number']) . '</strong>',
                    $pcfg['branch'] !== '' ? 'Branch: ' . h($pcfg['branch']) : null,
                    $pcfg['swift_code'] !== '' ? 'SWIFT: ' . h($pcfg['swift_code']) : null,
                    'Reference: <strong>' . h($inv['invoice_number']) . '</strong>',
                ]),
                'cheque'        => array_filter([
                    'Payable to: <strong>' . h($pcfg['payee_name']) . '</strong>',
                    $pcfg['delivery'] !== '' ? h($pcfg['delivery']) : null,
                    'Reference: <strong>' . h($inv['invoice_number']) . '</strong>',
                ]),
                default => [],
            };
            if ($lines) $offline_cards[] = ['key' => $pkey, 'def' => $pdef, 'lines' => $lines];
        }
        ?>
        <?php if ($offline_cards): ?>
        <div class="offline-grid">
          <?php foreach ($offline_cards as $card):
              $refLabel = match ($card['key']) {
                  'mpesa_manual'  => 'M-Pesa transaction code',
                  'bank_transfer' => 'Bank transfer reference',
                  'cheque'        => 'Cheque number',
                  default         => 'Reference number',
              };
              $submitted = $pending_offline[$card['key']] ?? null;
          ?>
            <div class="offline-card">
              <div class="offline-card-head">
                <span class="offline-icon" style="background:<?php echo h($card['def']['color']); ?>"><i class="fas <?php echo h($card['def']['icon']); ?>"></i></span>
                <span class="offline-name"><?php echo h($card['def']['name']); ?></span>
              </div>
              <div class="offline-instructions"><?php echo implode('<br />', $card['lines']); ?></div>

              <?php if ($submitted && $submitted['status'] === 'failed'):
                  $raw = json_decode($submitted['raw'] ?? '', true) ?: [];
                  $rejectReason = $raw['rejection_reason'] ?? 'The reference could not be verified.';
              ?>
                <div class="offline-submitted" style="background:#fef2f2;border-color:#fecaca;color:#991b1b">
                  <i class="fas fa-triangle-exclamation"></i>
                  <span>Reference <strong><?php echo h($submitted['gateway_ref']); ?></strong> couldn't be verified: <?php echo h($rejectReason); ?> Please correct it and resubmit below.</span>
                </div>
                <form method="POST" class="offline-ref-form" style="margin-top:10px">
                  <input type="hidden" name="csrf_token" value="<?php echo portal_csrf(); ?>" />
                  <input type="hidden" name="action" value="submit_reference" />
                  <input type="hidden" name="gateway" value="<?php echo h($card['key']); ?>" />
                  <input type="text" name="reference" placeholder="<?php echo h($refLabel); ?>" required minlength="3" />
                  <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-paper-plane"></i></button>
                </form>
              <?php elseif ($submitted): ?>
                <div class="offline-submitted">
                  <i class="fas fa-clock"></i>
                  <span>Reference <strong><?php echo h($submitted['gateway_ref']); ?></strong> submitted <?php echo time_ago($submitted['created_at']); ?> — awaiting confirmation.
                    <a href="#" class="offline-edit-ref" data-gw="<?php echo h($card['key']); ?>" data-label="<?php echo h($refLabel); ?>" style="font-weight:600">Correct it?</a>
                  </span>
                </div>
                <form method="POST" class="offline-ref-form" data-gw-form="<?php echo h($card['key']); ?>" style="display:none;margin-top:10px">
                  <input type="hidden" name="csrf_token" value="<?php echo portal_csrf(); ?>" />
                  <input type="hidden" name="action" value="submit_reference" />
                  <input type="hidden" name="gateway" value="<?php echo h($card['key']); ?>" />
                  <input type="text" name="reference" placeholder="<?php echo h($refLabel); ?>" value="<?php echo h($submitted['gateway_ref']); ?>" required minlength="3" />
                  <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-check"></i></button>
                </form>
              <?php else: ?>
                <form method="POST" class="offline-ref-form">
                  <input type="hidden" name="csrf_token" value="<?php echo portal_csrf(); ?>" />
                  <input type="hidden" name="action" value="submit_reference" />
                  <input type="hidden" name="gateway" value="<?php echo h($card['key']); ?>" />
                  <input type="text" name="reference" placeholder="<?php echo h($refLabel); ?>" required minlength="3" />
                  <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-paper-plane"></i></button>
                </form>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
        <div class="p-alert p-alert-info" style="margin-top:14px">
          <i class="fas fa-info-circle"></i>
          Paid already? Enter the reference or cheque number above and submit it — no need to open a support ticket. We verify and confirm within 1 business hour.
        </div>
        <?php else: ?>
        <div class="p-alert p-alert-info"><i class="fas fa-info-circle"></i> Please <a href="<?php echo PORTAL_URL; ?>/tickets/add.php?subject=<?php echo urlencode('Payment details for ' . $inv['invoice_number']); ?>" style="color:var(--navy);font-weight:600">open a support ticket</a> for payment details for this invoice.</div>
        <?php endif; ?>
      </div>

    </div>
  </div>
  <?php endif; ?>

</div>
</div>

<script>
document.querySelectorAll('.pay-tab').forEach(function (tab) {
  tab.addEventListener('click', function () {
    document.querySelectorAll('.pay-tab').forEach(function (t) { t.classList.remove('active'); });
    document.querySelectorAll('.pay-pane').forEach(function (p) { p.classList.remove('active'); });
    tab.classList.add('active');
    var pane = document.querySelector('[data-pay-pane="' + tab.dataset.payTab + '"]');
    if (pane) pane.classList.add('active');
  });
});
document.querySelectorAll('.offline-edit-ref').forEach(function (link) {
  link.addEventListener('click', function (e) {
    e.preventDefault();
    var form = document.querySelector('[data-gw-form="' + link.dataset.gw + '"]');
    if (form) { form.style.display = 'flex'; form.querySelector('input[name="reference"]').focus(); }
  });
});
</script>

<?php require_once '../includes/footer.php'; ?>
