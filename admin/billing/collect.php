<?php
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/providers/Provider.php';
require_once '../includes/Notifier.php';

auth_check();
$page_title = 'Collect Payment';

$invoice_id = (int)($_GET['invoice_id'] ?? $_POST['invoice_id'] ?? 0);
$stmt = db()->prepare('SELECT i.*, c.first_name, c.last_name, c.email, c.phone FROM invoices i LEFT JOIN clients c ON c.id = i.client_id WHERE i.id = ?');
$stmt->execute([$invoice_id]);
$inv = $stmt->fetch();

if (!$inv) {
    flash_set('error', 'Invoice not found.');
    header('Location: ' . APP_URL . '/billing/');
    exit;
}

// Active gateways
$gateways = [];
foreach (ProviderRegistry::byCategory('payment') as $key => $def) {
    if (Provider::isActive($key) && Provider::isConfigured($key)) $gateways[$key] = $def;
}

$result = null;
$currency = defined('CURRENCY') ? CURRENCY : 'USD';

function notify_invoice_paid_admin(array $inv, int $invoice_id, string $gateway): void
{
    if (!$inv['client_id'] || !$inv['email']) return;
    Notifier::send('invoice_paid', (int) $inv['client_id'], [
        'client_name'    => trim(($inv['first_name'] ?? '') . ' ' . ($inv['last_name'] ?? '')),
        'invoice_number' => $inv['invoice_number'],
        'amount'         => format_money((float) $inv['total']),
        'gateway'        => ucfirst($gateway),
        'email'          => $inv['email'],
        'link'           => portal_base_url() . '/invoices/view.php?id=' . $invoice_id,
    ]);
}

// ── Return from a gateway checkout: verify and confirm ──
if (isset($_GET['paid']) && $inv['status'] !== 'paid') {
    $p = db()->prepare('SELECT * FROM payments WHERE invoice_id = ? AND status = "pending" ORDER BY id DESC LIMIT 1');
    $p->execute([$invoice_id]);
    $pending = $p->fetch();
    if ($pending) {
        try {
            $v = Provider::payment($pending['gateway'])->verify($pending['gateway_ref']);
            if (!empty($v['success'])) {
                db()->prepare("UPDATE payments SET status = 'completed' WHERE id = ?")->execute([$pending['id']]);
                db()->prepare("UPDATE invoices SET status = 'paid', paid_date = CURDATE(), payment_method = ? WHERE id = ?")
                    ->execute([$pending['gateway'], $invoice_id]);
                notify_invoice_paid_admin($inv, $invoice_id, $pending['gateway']);
                flash_set('success', 'Payment confirmed — invoice marked as paid.');
            } elseif (($v['status'] ?? '') === 'failed') {
                db()->prepare("UPDATE payments SET status = 'failed' WHERE id = ?")->execute([$pending['id']]);
                $reason = $v['message'] ?? 'Payment failed.';
                flash_set('error', 'Payment failed: ' . $reason);
                if ($inv['client_id'] && $inv['email']) {
                    Notifier::send('payment_failed', (int) $inv['client_id'], [
                        'client_name' => trim(($inv['first_name'] ?? '') . ' ' . ($inv['last_name'] ?? '')),
                        'amount' => $currency . ' ' . number_format((float) $pending['amount'], 2),
                        'gateway' => ucfirst($pending['gateway']), 'reason' => $reason,
                        'email' => $inv['email'], 'link' => portal_base_url() . '/invoices/view.php?id=' . $invoice_id,
                    ]);
                }
            } else {
                flash_set('error', $v['message'] ?? ($v['status'] ?? 'Payment not confirmed yet — try again shortly.'));
            }
        } catch (\Throwable $e) {
            flash_set('error', $e->getMessage());
        }
    }
    header('Location: ' . APP_URL . '/billing/collect.php?invoice_id=' . $invoice_id);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'manual') {
        // If the client already started an offline payment (bank transfer,
        // manual M-Pesa, cheque) there's a pending row for this invoice —
        // confirming receipt completes THAT attempt instead of logging a
        // duplicate payment.
        $pending = db()->prepare("SELECT id FROM payments WHERE invoice_id = ? AND status = 'pending' AND gateway IN ('bank_transfer','mpesa_manual','cheque') ORDER BY id DESC LIMIT 1");
        $pending->execute([$invoice_id]);
        $pending_id = (int) $pending->fetchColumn();
        if ($pending_id) {
            db()->prepare("UPDATE payments SET status = 'completed' WHERE id = ?")->execute([$pending_id]);
        } else {
            db()->prepare('INSERT INTO payments (invoice_id, client_id, gateway, gateway_ref, amount, currency, status) VALUES (?,?,?,?,?,?,?)')
                ->execute([$invoice_id, $inv['client_id'], 'manual', 'MANUAL-' . strtoupper(bin2hex(random_bytes(3))), $inv['total'], $currency, 'completed']);
        }
        db()->prepare("UPDATE invoices SET status='paid', paid_date=CURDATE(), payment_method=? WHERE id=?")
            ->execute([trim($_POST['method'] ?? 'Manual'), $invoice_id]);
        notify_invoice_paid_admin($inv, $invoice_id, trim($_POST['method'] ?? 'Manual'));
        log_activity('payment_manual', 'invoice', $invoice_id, $pending_id ? 'Offline payment confirmed received' : 'Manual payment recorded');
        flash_set('success', $pending_id ? 'Offline payment confirmed — invoice marked as paid.' : 'Payment recorded and invoice marked as paid.');
        header('Location: ' . APP_URL . '/billing/');
        exit;
    }

    if ($action === 'collect') {
        $gw = $_POST['gateway'] ?? '';
        if (!isset($gateways[$gw])) {
            flash_set('error', 'Choose an active gateway.');
        } else {
            $base  = rtrim(APP_URL, '/');
            $urls  = [
                'return'   => $base . '/billing/collect.php?invoice_id=' . $invoice_id . '&paid=1',
                'cancel'   => $base . '/billing/collect.php?invoice_id=' . $invoice_id,
                'callback' => $base . '/billing/',
            ];
            try {
                $result = Provider::payment($gw)->createCheckout(
                    (float)$inv['total'], $currency, $inv['invoice_number'],
                    ['name' => trim(($inv['first_name'] ?? '') . ' ' . ($inv['last_name'] ?? '')), 'email' => $inv['email'] ?? '', 'phone' => $inv['phone'] ?? ''],
                    $urls
                );
                // Record the attempt
                db()->prepare('INSERT INTO payments (invoice_id, client_id, gateway, gateway_ref, amount, currency, status, raw) VALUES (?,?,?,?,?,?,?,?)')
                    ->execute([$invoice_id, $inv['client_id'], $gw, $result['ref'] ?? null, $inv['total'], $currency,
                               !empty($result['success']) ? 'pending' : 'failed', json_encode($result)]);
                if (empty($result['success'])) {
                    flash_set('error', 'Gateway error: ' . ($result['message'] ?? 'unknown'));
                }
            } catch (\Throwable $e) {
                $result = ['success' => false, 'message' => $e->getMessage()];
            }
        }
    }
}

require_once '../includes/header.php';
?>

<div class="breadcrumb"><a href="<?php echo APP_URL; ?>/billing/">Payments</a> <span class="breadcrumb-sep">/</span> Collect</div>

<div class="content-header">
  <h1 class="content-title">Collect Payment</h1>
  <a href="<?php echo APP_URL; ?>/billing/" class="btn btn-ghost"><i class="fas fa-arrow-left"></i> Back</a>
</div>

<div style="display:grid;grid-template-columns:1fr 340px;gap:18px;align-items:start;max-width:900px">
  <div class="card">
    <div class="card-header"><span class="card-title"><i class="fas fa-hand-holding-dollar"></i> Choose a gateway</span></div>
    <div class="card-body">
      <?php if ($result && !empty($result['success'])): ?>
        <?php if (($result['mode'] ?? '') === 'redirect'): ?>
          <div class="alert alert-success"><i class="fas fa-check-circle"></i> Checkout created. Share this secure link with the client to pay:</div>
          <div class="input-affix" style="margin-bottom:12px">
            <input type="text" class="form-control mono" id="payLink" value="<?php echo h($result['redirect_url']); ?>" readonly style="padding-right:70px">
            <button type="button" class="affix-btn" onclick="navigator.clipboard.writeText(document.getElementById('payLink').value);this.textContent='Copied'">Copy</button>
          </div>
          <a href="<?php echo h($result['redirect_url']); ?>" target="_blank" rel="noopener" class="btn btn-primary"><i class="fas fa-arrow-up-right-from-square"></i> Open checkout</a>
        <?php elseif (($result['mode'] ?? '') === 'push'): ?>
          <div class="alert alert-success"><i class="fas fa-mobile-screen"></i> <?php echo h($result['message'] ?? 'Payment request sent.'); ?></div>
          <p class="text-muted" style="font-size:13px">Reference: <span class="code-chip"><?php echo h($result['ref'] ?? ''); ?></span></p>
        <?php elseif (($result['mode'] ?? '') === 'instructions'): ?>
          <div class="alert alert-info" style="white-space:pre-line"><i class="fas fa-file-invoice-dollar"></i> <?php echo h($result['message'] ?? ''); ?></div>
          <p class="text-muted" style="font-size:13px">Share these instructions with the client. Once the money arrives, use <strong>Mark as paid</strong> below to confirm receipt.</p>
        <?php endif; ?>
      <?php elseif ($result && empty($result['success'])): ?>
        <div class="alert alert-danger"><i class="fas fa-triangle-exclamation"></i> <?php echo h($result['message'] ?? 'Failed to create checkout.'); ?></div>
      <?php endif; ?>

      <?php if (!$gateways): ?>
        <div class="alert alert-info" style="margin:0"><i class="fas fa-circle-info"></i> No active payment gateways. Enable one in <a href="<?php echo APP_URL; ?>/integrations/" style="font-weight:600">Providers</a>.</div>
      <?php else: ?>
        <form method="POST">
          <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
          <input type="hidden" name="invoice_id" value="<?php echo $invoice_id; ?>">
          <input type="hidden" name="action" value="collect">
          <div class="provider-grid" style="grid-template-columns:1fr 1fr;margin-bottom:18px">
            <?php foreach ($gateways as $key => $def): ?>
              <label class="provider-card" style="cursor:pointer;padding:14px">
                <div class="flex-gap">
                  <input type="radio" name="gateway" value="<?php echo h($key); ?>" required>
                  <div class="provider-logo" style="width:34px;height:34px;font-size:15px;background:<?php echo h($def['color']); ?>"><i class="fas <?php echo h($def['icon']); ?>"></i></div>
                  <div class="provider-name" style="font-size:13.5px"><?php echo h($def['name']); ?></div>
                </div>
              </label>
            <?php endforeach; ?>
          </div>
          <button type="submit" class="btn btn-primary"><i class="fas fa-bolt"></i> Create checkout</button>
        </form>

        <hr style="border:none;border-top:1px solid var(--border);margin:22px 0">
        <p class="form-section-title">Or record a manual payment</p>
        <form method="POST" class="flex-gap" style="gap:10px">
          <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
          <input type="hidden" name="invoice_id" value="<?php echo $invoice_id; ?>">
          <input type="hidden" name="action" value="manual">
          <select name="method" class="form-select" style="width:auto">
            <?php foreach (get_payment_methods() as $m): ?><option><?php echo h($m); ?></option><?php endforeach; ?>
          </select>
          <button type="submit" class="btn btn-ghost" data-confirm="Mark this invoice as paid?"><i class="fas fa-check"></i> Mark as paid</button>
        </form>
      <?php endif; ?>
    </div>
  </div>

  <div class="card">
    <div class="card-header"><span class="card-title">Invoice</span></div>
    <div class="card-body">
      <div class="data-list">
        <div class="row"><span class="k">Number</span><span class="v mono"><?php echo h($inv['invoice_number']); ?></span></div>
        <div class="row"><span class="k">Client</span><span class="v"><?php echo h(trim(($inv['first_name'] ?? '') . ' ' . ($inv['last_name'] ?? ''))) ?: '—'; ?></span></div>
        <div class="row"><span class="k">Status</span><span class="v"><?php echo badge($inv['status']); ?></span></div>
        <div class="row"><span class="k">Due</span><span class="v"><?php echo format_date($inv['due_date']); ?></span></div>
        <div class="row"><span class="k">Total</span><span class="v" style="font-size:16px"><?php echo format_money((float)$inv['total']); ?></span></div>
      </div>
    </div>
  </div>
</div>

<?php require_once '../includes/footer.php'; ?>
