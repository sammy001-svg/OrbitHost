<?php
/**
 * Orbit Cloud — allocate a received payment to an invoice.
 *
 * Money can reach us without ever touching a checkout: a client pays the
 * M-Pesa till straight from their phone, or sends a transfer we only learn
 * about from a webhook. Automation::recordUnsolicitedPayment() banks those
 * as completed-but-unallocated. This is where a human says which invoice
 * the money was actually for.
 *
 * Allocation is deliberately manual. Matching on amount alone mis-applies
 * money the moment two clients owe the same figure, and a wrongly settled
 * invoice provisions a service nobody paid for.
 */
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/Automation.php';
require_once '../includes/Notifier.php';
require_once '../includes/Currency.php';

auth_check();
require_role('admin', APP_URL . '/billing/index.php');
$page_title = 'Allocate Payment';
Currency::ensureSchema();

$pid = (int) ($_GET['id'] ?? $_POST['payment_id'] ?? 0);

$stmt = db()->prepare("SELECT p.*, c.first_name, c.last_name, c.email
                       FROM payments p LEFT JOIN clients c ON c.id = p.client_id
                       WHERE p.id = ? AND p.invoice_id IS NULL AND p.status = 'completed'");
$stmt->execute([$pid]);
$pay = $stmt->fetch();

if (!$pay) {
    flash_set('error', 'That payment is not available for allocation — it may already be applied.');
    header('Location: ' . APP_URL . '/billing/index.php');
    exit;
}

$currency = $pay['currency'] ?: 'USD';

// ── Assign the payment to a client, when the phone match found nobody ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'assign_client') {
    csrf_verify();
    $cid = (int) ($_POST['client_id'] ?? 0);
    if ($cid) {
        db()->prepare('UPDATE payments SET client_id = ? WHERE id = ?')->execute([$cid, $pid]);
        log_activity('payment_assign', 'payment', $pid, 'Assigned to client #' . $cid);
        flash_set('success', 'Payment assigned. Now choose the invoice it settles.');
    }
    header('Location: ' . APP_URL . '/billing/allocate.php?id=' . $pid);
    exit;
}

// ── Apply the payment to an invoice ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'apply') {
    csrf_verify();
    $inv_id = (int) ($_POST['invoice_id'] ?? 0);

    $iv = db()->prepare('SELECT * FROM invoices WHERE id = ?');
    $iv->execute([$inv_id]);
    $inv = $iv->fetch();

    if (!$inv) {
        flash_set('error', 'Invoice not found.');
    } elseif (strtoupper($inv['currency'] ?: 'USD') !== strtoupper($currency)) {
        // Applying KES to a USD invoice needs an exchange rate this system
        // deliberately does not keep. Refuse rather than guess at one.
        flash_set('error', 'Currency mismatch: this payment is in ' . h($currency)
            . ' but that invoice is in ' . h($inv['currency'] ?: 'USD') . '.');
    } elseif (in_array($inv['status'], ['paid', 'cancelled'], true)) {
        flash_set('error', 'That invoice is already ' . h($inv['status']) . '.');
    } else {
        $paid = (float) $pay['amount'];
        $due  = (float) $inv['total'];
        // Whatever other completed payments have already settled here.
        $st = db()->prepare("SELECT COALESCE(SUM(amount),0) FROM payments WHERE invoice_id = ? AND status = 'completed'");
        $st->execute([$inv_id]);
        $already     = (float) $st->fetchColumn();
        $outstanding = round($due - $already, 2);

        db()->prepare('UPDATE payments SET invoice_id = ? WHERE id = ?')->execute([$inv_id, $pid]);

        if ($paid + 0.01 >= $outstanding) {
            db()->prepare("UPDATE invoices SET status = 'paid', paid_date = CURDATE(), payment_method = ? WHERE id = ?")
                ->execute([$pay['gateway'], $inv_id]);

            $over = round($paid - $outstanding, 2);
            if ($over > 0.01 && $pay['client_id']) {
                // Overpayment becomes account credit rather than vanishing.
                // The credits table is created lazily by clients/view.php, so
                // it may genuinely not exist yet on a fresh install — make sure
                // it does before banking money into it.
                try {
                    db()->exec("CREATE TABLE IF NOT EXISTS client_credits (
                        id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                        client_id  INT UNSIGNED NOT NULL,
                        amount     DECIMAL(10,2) NOT NULL,
                        reason     VARCHAR(255) NOT NULL,
                        invoice_id INT UNSIGNED DEFAULT NULL,
                        created_by INT UNSIGNED DEFAULT NULL,
                        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        INDEX idx_cc_client (client_id),
                        FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
                        FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE SET NULL
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
                } catch (\Throwable $e) { /* no CREATE privilege — insert below will surface it */ }
                try {
                    db()->prepare('INSERT INTO client_credits (client_id, amount, reason, invoice_id, created_by) VALUES (?,?,?,?,?)')
                        ->execute([(int) $pay['client_id'], $over,
                                   'Overpayment on ' . $inv['invoice_number'] . ' (ref ' . $pay['gateway_ref'] . ')',
                                   $inv_id, current_admin()['id']]);
                } catch (\Throwable $e) { /* credits not migrated — invoice is still settled */ }
            }

            // Same call every other payment path uses, so provisioning and
            // renewal advancement behave identically however money arrives.
            try { Automation::invoicePaid($inv_id); } catch (\Throwable $e) {}
            try { Notifier::sendInvoiceEmail($inv_id, 'invoice_paid', ['gateway' => ucfirst((string) $pay['gateway'])]); } catch (\Throwable $e) {}

            log_activity('payment_allocate', 'payment', $pid,
                sprintf('Applied %s %s to %s%s.', $currency, number_format($paid, 2), $inv['invoice_number'],
                        $over > 0.01 ? ' (+' . number_format($over, 2) . ' credit)' : ''));
            flash_set('success', $inv['invoice_number'] . ' settled with this payment'
                . ($over > 0.01 ? ' — ' . format_money($over, $currency) . ' added as account credit.' : '.'));
        } else {
            // Part payment: linked and visible, but the invoice stays open
            // and nothing is provisioned off a partial settlement.
            $left = round($outstanding - $paid, 2);
            log_activity('payment_allocate_partial', 'payment', $pid,
                sprintf('Part-paid %s: %s %s of %s outstanding.', $inv['invoice_number'],
                        $currency, number_format($paid, 2), number_format($outstanding, 2)));
            flash_set('info', 'Part payment recorded against ' . $inv['invoice_number'] . ' — '
                . format_money($left, $currency) . ' still outstanding, so the invoice stays open.');
        }
        header('Location: ' . APP_URL . '/billing/index.php');
        exit;
    }
    header('Location: ' . APP_URL . '/billing/allocate.php?id=' . $pid);
    exit;
}

// Candidates: this client's open invoices, or every open invoice in the
// same currency while we still do not know who paid.
if ($pay['client_id']) {
    // Same currency only: applying KES to a USD invoice is refused below,
    // so offering it here would just be a button that always errors.
    $q = db()->prepare("SELECT i.*, CONCAT(c.first_name,' ',c.last_name) client_name
                        FROM invoices i JOIN clients c ON c.id = i.client_id
                        WHERE i.client_id = ? AND i.status IN ('sent','overdue','draft')
                          AND COALESCE(i.currency,'USD') = ?
                        ORDER BY i.due_date ASC");
    $q->execute([(int) $pay['client_id'], $currency]);
} else {
    $q = db()->prepare("SELECT i.*, CONCAT(c.first_name,' ',c.last_name) client_name
                        FROM invoices i JOIN clients c ON c.id = i.client_id
                        WHERE i.status IN ('sent','overdue') AND COALESCE(i.currency,'USD') = ?
                        ORDER BY i.due_date ASC LIMIT 100");
    $q->execute([$currency]);
}
$candidates = $q->fetchAll();

$clients = db()->query('SELECT id, first_name, last_name, email FROM clients ORDER BY first_name, last_name')->fetchAll();
$raw     = json_decode((string) $pay['raw'], true) ?: [];
$payer   = $raw['webhook']['data']['attributes']['event']['resource'] ?? [];

$settled_for = function (int $invoice_id): float {
    $s = db()->prepare("SELECT COALESCE(SUM(amount),0) FROM payments WHERE invoice_id = ? AND status='completed'");
    $s->execute([$invoice_id]);
    return (float) $s->fetchColumn();
};

require_once '../includes/header.php';
?>

<div class="page-header">
  <div>
    <div class="breadcrumb"><a href="<?php echo APP_URL; ?>/billing/">Payments</a><span class="breadcrumb-sep">›</span> Allocate</div>
    <h1>Allocate payment #<?php echo (int) $pay['id']; ?></h1>
  </div>
  <a href="<?php echo APP_URL; ?>/billing/" class="btn btn-ghost"><i class="fas fa-arrow-left"></i> Back</a>
</div>

<div class="grid-2" style="align-items:start">
  <div class="card">
    <div class="card-header"><div class="card-title"><i class="fas fa-hand-holding-dollar"></i> The payment</div></div>
    <div class="card-body">
      <div style="font-size:30px;font-weight:800;letter-spacing:-1px;color:var(--text)">
        <?php echo h($currency); ?> <?php echo number_format((float) $pay['amount'], 2); ?>
      </div>
      <ul class="profile-meta" style="margin-top:14px">
        <li><span class="key">Gateway</span><span class="val"><?php echo h(ucfirst(str_replace('_', ' ', (string) $pay['gateway']))); ?></span></li>
        <li><span class="key">Reference</span><span class="val mono"><?php echo h((string) $pay['gateway_ref']); ?></span></li>
        <li><span class="key">Received</span><span class="val"><?php echo format_datetime($pay['created_at']); ?></span></li>
        <?php if (!empty($payer['sender_phone_number'])): ?>
          <li><span class="key">Paid from</span><span class="val"><?php echo h((string) $payer['sender_phone_number']); ?></span></li>
        <?php endif; ?>
        <li><span class="key">Client</span><span class="val">
          <?php echo $pay['client_id'] ? h(trim($pay['first_name'] . ' ' . $pay['last_name'])) : '<span class="text-muted">Not matched</span>'; ?>
        </span></li>
      </ul>

      <?php if (!$pay['client_id']): ?>
        <div class="alert alert-warning" style="margin-top:14px"><i class="fas fa-triangle-exclamation"></i>
          We could not match this payer to a client. Pick the right account first.
        </div>
        <form method="POST" style="display:flex;gap:8px;margin-top:10px">
          <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>" />
          <input type="hidden" name="action" value="assign_client" />
          <input type="hidden" name="payment_id" value="<?php echo (int) $pay['id']; ?>" />
          <select name="client_id" class="form-select" required style="flex:1">
            <option value="">— Select client —</option>
            <?php foreach ($clients as $c): ?>
              <option value="<?php echo (int) $c['id']; ?>"><?php echo h($c['first_name'] . ' ' . $c['last_name'] . ' <' . $c['email'] . '>'); ?></option>
            <?php endforeach; ?>
          </select>
          <button class="btn btn-primary" style="white-space:nowrap">Assign</button>
        </form>
      <?php endif; ?>
    </div>
  </div>

  <div class="table-wrap">
    <div class="table-toolbar">
      <span class="card-title">Apply to an invoice</span>
      <span class="table-count"><?php echo count($candidates); ?> open</span>
    </div>
    <div class="table-scroll">
    <table>
      <thead><tr><th>Invoice</th><th>Client</th><th>Due</th><th>Amount</th><th></th></tr></thead>
      <tbody>
        <?php if (!$candidates): ?>
          <tr><td colspan="5"><div class="empty-state"><i class="fas fa-file-invoice"></i>
            <p>No open invoices in <?php echo h($currency); ?><?php echo $pay['client_id'] ? ' for this client' : ''; ?>.</p></div></td></tr>
        <?php else: foreach ($candidates as $inv):
          $left = round((float) $inv['total'] - $settled_for((int) $inv['id']), 2);
          $full = (float) $pay['amount'] + 0.01 >= $left;
        ?>
          <tr>
            <td>
              <div class="td-name"><?php echo h($inv['invoice_number']); ?></div>
              <div class="td-sub"><?php echo badge($inv['status']); ?></div>
            </td>
            <td><?php echo h($inv['client_name']); ?></td>
            <td><?php echo format_date($inv['due_date']); ?></td>
            <td>
              <div class="fw-600"><?php echo h($inv['currency'] ?: 'USD'); ?> <?php echo number_format((float) $inv['total'], 2); ?></div>
              <?php if ($left < (float) $inv['total']): ?><div class="td-sub"><?php echo number_format($left, 2); ?> outstanding</div><?php endif; ?>
            </td>
            <td style="text-align:right">
              <form method="POST" style="margin:0">
                <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>" />
                <input type="hidden" name="action" value="apply" />
                <input type="hidden" name="payment_id" value="<?php echo (int) $pay['id']; ?>" />
                <input type="hidden" name="invoice_id" value="<?php echo (int) $inv['id']; ?>" />
                <button class="btn btn-primary btn-xs" data-confirm="Apply <?php echo h($currency . ' ' . number_format((float) $pay['amount'], 2)); ?> to <?php echo h($inv['invoice_number']); ?>? <?php echo $full ? 'This settles the invoice and provisions any service on it.' : 'This is a PART payment — the invoice stays open.'; ?>">
                  <i class="fas fa-check"></i> Apply
                </button>
              </form>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
    </div>
  </div>
</div>

<?php require_once '../includes/footer.php'; ?>
