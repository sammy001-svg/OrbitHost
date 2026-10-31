<?php
/**
 * Orbit Cloud — review a short payment.
 *
 * Automation::settlePayment() banks a payment that arrived for less than
 * it should have, but leaves the invoice open and fulfils nothing. This is
 * where a human decides what happens next — see
 * Automation::resolveShortPayment() for what each choice does.
 */
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/Automation.php';
require_once '../includes/Notifier.php';

auth_check();
require_role('admin', APP_URL . '/billing/index.php');
$page_title = 'Short Payment';
Automation::ensureSchema();

$pid = (int) ($_GET['id'] ?? $_POST['payment_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $r = Automation::resolveShortPayment($pid, (string) ($_POST['action'] ?? ''), (int) current_admin()['id'], (string) ($_POST['note'] ?? ''));
    flash_set($r['ok'] ? 'success' : 'error', $r['message']);
    // Chasing keeps it in the queue; anything else is done with.
    $still_open = db()->prepare('SELECT review FROM payments WHERE id = ?');
    $still_open->execute([$pid]);
    $dest = (!$r['ok'] || $still_open->fetchColumn() === Automation::REVIEW_CHASING)
        ? '/billing/short.php?id=' . $pid : '/billing/index.php';
    header('Location: ' . APP_URL . $dest);
    exit;
}

$stmt = db()->prepare("SELECT p.*, c.first_name, c.last_name, c.email,
                              i.invoice_number, i.total inv_total, i.status inv_status, i.due_date
                       FROM payments p
                       LEFT JOIN clients c  ON c.id = p.client_id
                       LEFT JOIN invoices i ON i.id = p.invoice_id
                       WHERE p.id = ? AND p.review IS NOT NULL");
$stmt->execute([$pid]);
$pay = $stmt->fetch();
if (!$pay) {
    flash_set('error', 'That payment has nothing to review.');
    header('Location: ' . APP_URL . '/billing/index.php');
    exit;
}

$raw      = json_decode((string) $pay['raw'], true) ?: [];
$short    = $raw['short'] ?? [];
$currency = $pay['currency'] ?: 'USD';
$expected = (float) ($short['expected'] ?? 0);
$open     = in_array($pay['review'], [Automation::REVIEW_SHORT, Automation::REVIEW_CHASING], true);
$left     = $pay['invoice_id'] ? Automation::invoiceOutstanding((int) $pay['invoice_id']) : 0.0;
$money    = fn(float $v) => h($currency) . ' ' . number_format($v, 2);

$credit = 0.0;
if ($pay['client_id']) {
    try {
        $q = db()->prepare('SELECT COALESCE(SUM(amount),0) FROM client_credits WHERE client_id = ?');
        $q->execute([(int) $pay['client_id']]);
        $credit = (float) $q->fetchColumn();
    } catch (\Throwable $e) { /* credits table not created yet */ }
}

// Everything else paid against this invoice, so the admin sees the whole picture.
$others = [];
if ($pay['invoice_id']) {
    $q = db()->prepare('SELECT * FROM payments WHERE invoice_id = ? AND id <> ? ORDER BY created_at');
    $q->execute([(int) $pay['invoice_id'], $pid]);
    $others = $q->fetchAll();
}

$what_for = [
    'domain_checkout' => 'Domain registration',
    'order_service'   => 'New service order',
    'renew'           => 'Domain renewal',
    'transfer'        => 'Domain transfer',
    'invoice_payment' => 'Invoice payment',
][$raw['context']['action'] ?? ''] ?? 'Invoice payment';

$resolution_labels = [
    'write_off'    => 'Shortfall written off',
    'apply_credit' => 'Covered from account credit',
    'refunded'     => 'Refunded',
    'balance_paid' => 'Balance paid by the client',
];

require_once '../includes/header.php';
?>

<div class="page-header">
  <div>
    <div class="breadcrumb"><a href="<?php echo APP_URL; ?>/billing/">Payments</a><span class="breadcrumb-sep">›</span> Short payment</div>
    <h1>Short payment #<?php echo (int) $pay['id']; ?></h1>
  </div>
  <a href="<?php echo APP_URL; ?>/billing/" class="btn btn-ghost"><i class="fas fa-arrow-left"></i> Back</a>
</div>

<div class="grid-2" style="align-items:start">
  <div class="card">
    <div class="card-header"><div class="card-title"><i class="fas fa-scale-unbalanced"></i> What came in</div></div>
    <div class="card-body">
      <div style="display:flex;gap:28px;flex-wrap:wrap">
        <div>
          <div class="stat-label">Received</div>
          <div style="font-size:26px;font-weight:800;letter-spacing:-1px;color:var(--text)"><?php echo $money((float) $pay['amount']); ?></div>
        </div>
        <div>
          <div class="stat-label">Expected</div>
          <div style="font-size:26px;font-weight:800;letter-spacing:-1px;color:var(--text-muted)"><?php echo $money($expected); ?></div>
        </div>
        <?php if ($pay['invoice_id']): ?>
        <div>
          <div class="stat-label">Still owed</div>
          <div style="font-size:26px;font-weight:800;letter-spacing:-1px;color:<?php echo $left > 0.01 ? 'var(--danger)' : 'var(--success)'; ?>"><?php echo $money($left); ?></div>
        </div>
        <?php endif; ?>
      </div>

      <ul class="profile-meta" style="margin-top:16px">
        <li><span class="key">For</span><span class="val"><?php echo h($what_for); ?></span></li>
        <li><span class="key">Invoice</span><span class="val">
          <?php if ($pay['invoice_id']): ?>
            <a href="<?php echo APP_URL; ?>/invoices/view.php?id=<?php echo (int) $pay['invoice_id']; ?>"><?php echo h($pay['invoice_number']); ?></a>
            <?php echo badge($pay['inv_status']); ?>
          <?php else: ?><span class="text-muted">None</span><?php endif; ?>
        </span></li>
        <li><span class="key">Client</span><span class="val">
          <?php if ($pay['client_id']): ?>
            <a href="<?php echo APP_URL; ?>/clients/view.php?id=<?php echo (int) $pay['client_id']; ?>"><?php echo h(trim($pay['first_name'] . ' ' . $pay['last_name'])); ?></a>
          <?php else: ?><span class="text-muted">Unknown</span><?php endif; ?>
        </span></li>
        <li><span class="key">Gateway</span><span class="val"><?php echo h(ucfirst(str_replace('_', ' ', (string) $pay['gateway']))); ?></span></li>
        <li><span class="key">Reference</span><span class="val mono"><?php echo h((string) $pay['gateway_ref']); ?></span></li>
        <li><span class="key">Flagged</span><span class="val"><?php echo !empty($short['flagged_at']) ? format_datetime(date('Y-m-d H:i:s', strtotime($short['flagged_at']))) : format_datetime($pay['created_at']); ?></span></li>
        <?php if ($pay['client_id']): ?>
          <li><span class="key">Account credit</span><span class="val"><?php echo $money($credit); ?></span></li>
        <?php endif; ?>
      </ul>

      <?php foreach ($short['chased'] ?? [] as $c): ?>
        <div class="alert alert-info" style="margin-top:12px"><i class="fas fa-envelope"></i>
          Balance of <?php echo $money((float) ($c['amount'] ?? 0)); ?> requested from the client <?php echo time_ago(date('Y-m-d H:i:s', strtotime($c['at']))); ?><?php echo !empty($c['note']) ? ' — “' . h($c['note']) . '”' : ''; ?>.
        </div>
      <?php endforeach; ?>

      <?php if (!$open && !empty($short['resolution'])): $res = $short['resolution']; ?>
        <div class="alert alert-success" style="margin-top:12px"><i class="fas fa-circle-check"></i>
          <?php echo h($resolution_labels[$res['action']] ?? ucfirst((string) $res['action'])); ?>
          <?php echo isset($res['amount']) ? ' (' . $money((float) $res['amount']) . ')' : ''; ?>
          <?php echo !empty($res['at']) ? ' — ' . time_ago(date('Y-m-d H:i:s', strtotime($res['at']))) : ''; ?><?php echo !empty($res['note']) ? ': ' . h($res['note']) : '.'; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($open): ?>
  <div class="card">
    <div class="card-header"><div class="card-title"><i class="fas fa-gavel"></i> Decide</div></div>
    <div class="card-body">
      <?php if (!$pay['invoice_id']): ?>
        <p class="text-muted">This payment is not linked to an invoice, so only a refund can be recorded here.</p>
      <?php elseif (in_array($pay['inv_status'], ['paid', 'cancelled'], true)): ?>
        <div class="alert alert-warning"><i class="fas fa-triangle-exclamation"></i>
          <?php echo h($pay['invoice_number']); ?> is already <?php echo h($pay['inv_status']); ?>. Only a refund can still be recorded.</div>
      <?php endif; ?>

      <?php $can_settle = $pay['invoice_id'] && !in_array($pay['inv_status'], ['paid', 'cancelled'], true); ?>
      <form method="POST">
        <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>" />
        <input type="hidden" name="payment_id" value="<?php echo (int) $pay['id']; ?>" />
        <div class="form-group">
          <label class="form-label" for="note">Note <span class="text-muted">(required for a refund: the refund reference)</span></label>
          <textarea id="note" name="note" class="form-control" rows="2" maxlength="500" placeholder="e.g. M-Pesa rounding, agreed discount, refund ref RF12345"></textarea>
        </div>

        <div style="display:flex;flex-direction:column;gap:10px">
          <?php if ($can_settle): ?>
            <button name="action" value="write_off" class="btn btn-primary" style="justify-content:flex-start"
                    data-confirm="Write off <?php echo h($currency . ' ' . number_format($left, 2)); ?> and mark <?php echo h($pay['invoice_number']); ?> paid? This fulfils the order now.">
              <i class="fas fa-check"></i> Accept — write off <?php echo $money($left); ?> and fulfil
            </button>

            <?php if ($pay['client_id']): ?>
              <button name="action" value="apply_credit" class="btn btn-ghost" style="justify-content:flex-start"
                      <?php echo $credit + 0.01 < $left ? 'disabled title="Only ' . h($currency . ' ' . number_format($credit, 2)) . ' credit available"' : ''; ?>
                      data-confirm="Take <?php echo h($currency . ' ' . number_format($left, 2)); ?> from the client's account credit and settle <?php echo h($pay['invoice_number']); ?>?">
                <i class="fas fa-wallet"></i> Cover <?php echo $money($left); ?> from account credit
              </button>

              <button name="action" value="chase" class="btn btn-ghost" style="justify-content:flex-start"
                      data-confirm="Email the client asking for the <?php echo h($currency . ' ' . number_format($left, 2)); ?> balance?">
                <i class="fas fa-envelope"></i> <?php echo $pay['review'] === Automation::REVIEW_CHASING ? 'Send another balance request' : 'Ask the client for the balance'; ?>
              </button>
            <?php endif; ?>
          <?php endif; ?>

          <button name="action" value="refunded" class="btn btn-ghost" style="justify-content:flex-start;color:var(--danger)"
                  data-confirm="Record this payment as refunded? Only do this once the money has actually been sent back — the invoice will be owed in full again.">
            <i class="fas fa-rotate-left"></i> I've refunded it — record the refund
          </button>
        </div>
      </form>
      <p class="text-muted" style="font-size:12px;margin-top:12px">
        A refund is recorded only — send the money back through the gateway or bank first.
        When a requested balance arrives the invoice settles and fulfils on its own.
      </p>
    </div>
  </div>
  <?php endif; ?>
</div>

<?php if ($others): ?>
<div class="table-wrap" style="margin-top:18px">
  <div class="table-toolbar"><span class="card-title">Other payments on <?php echo h($pay['invoice_number']); ?></span></div>
  <div class="table-scroll">
  <table>
    <thead><tr><th>Reference</th><th>Gateway</th><th>Amount</th><th>Status</th><th>Date</th></tr></thead>
    <tbody>
      <?php foreach ($others as $o): ?>
        <tr>
          <td class="mono" style="font-size:12px"><?php echo h($o['gateway_ref'] ?: '—'); ?></td>
          <td><span class="code-chip"><?php echo h($o['gateway']); ?></span></td>
          <td class="fw-600"><?php echo h($o['currency']); ?> <?php echo number_format((float) $o['amount'], 2); ?></td>
          <td><?php echo badge($o['status']); ?></td>
          <td style="font-size:12px;color:var(--text-muted)"><?php echo time_ago($o['created_at']); ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
<?php endif; ?>

<?php require_once '../includes/footer.php'; ?>
