<?php
/**
 * Orbit Cloud — hosting checkout: order placed.
 *
 * The order exists and its invoice has been emailed. From here the client
 * either pays now (handing off to the portal invoice page, which already
 * handles gateways, account credit and offline references) or leaves it
 * and pays later from their client area.
 */
require_once __DIR__ . '/_layout.php';

portal_start();
Currency::ensureSchema();

if (empty($_SESSION['client_id'])) {
    header('Location: ' . PORTAL_URL . '/login.php');
    exit;
}

$cid = (int) $_SESSION['client_id'];
$id  = (int) ($_GET['invoice'] ?? 0);

$stmt = db()->prepare('SELECT i.*, o.service_name, o.domain_name FROM invoices i
                       LEFT JOIN orders o ON o.id = i.order_id
                       WHERE i.id = ? AND i.client_id = ?');
$stmt->execute([$id, $cid]);
$inv = $stmt->fetch();

if (!$inv) {
    header('Location: ' . PORTAL_URL . '/services.php');
    exit;
}

$currency = $inv['currency'] ?: Currency::current();
$paid     = $inv['status'] === 'paid';

checkout_head('Order received', 5);
?>

<div style="max-width:620px;margin:0 auto">
  <div class="co-panel">
    <div class="co-panel-body" style="text-align:center;padding:34px 26px">
      <i class="fas fa-circle-check" style="font-size:46px;color:var(--green)"></i>
      <h1 style="font-size:20px;color:var(--text);margin:14px 0 6px">Thank you — your order is in</h1>
      <p style="font-size:13.5px;color:var(--text-muted);margin:0">
        We've emailed invoice <strong><?php echo h($inv['invoice_number']); ?></strong> to your inbox.
        <?php if (!$paid): ?>Your hosting is set up automatically as soon as it's paid.<?php endif; ?>
      </p>

      <div style="text-align:left;border:1px solid var(--border);border-radius:var(--radius);padding:16px;margin:22px 0">
        <div class="co-sum-sub"><span>Package</span><span style="font-weight:700;color:var(--text)"><?php echo h((string) $inv['service_name']); ?></span></div>
        <?php if ($inv['domain_name']): ?>
          <div class="co-sum-sub"><span>Domain</span><span style="font-weight:700;color:var(--text);font-family:ui-monospace,Menlo,monospace"><?php echo h($inv['domain_name']); ?></span></div>
        <?php endif; ?>
        <div class="co-sum-sub"><span>Subtotal</span><span><?php echo h(checkout_money((float) $inv['subtotal'], $currency)); ?></span></div>
        <?php if ((float) $inv['tax_amount'] > 0): ?>
          <div class="co-sum-sub"><span>VAT (<?php echo rtrim(rtrim(number_format((float) $inv['tax_rate'], 2, '.', ''), '0'), '.'); ?>%)</span><span><?php echo h(checkout_money((float) $inv['tax_amount'], $currency)); ?></span></div>
        <?php endif; ?>
        <div class="co-sum-total"><span>Total</span><span><?php echo h(checkout_money((float) $inv['total'], $currency)); ?></span></div>
      </div>

      <?php if ($paid): ?>
        <div class="p-alert p-alert-success" style="text-align:left"><i class="fas fa-circle-check"></i> This invoice is paid — we're provisioning your service now.</div>
      <?php endif; ?>

      <div style="display:flex;gap:10px;justify-content:center;flex-wrap:wrap;margin-top:6px">
        <?php if (!$paid): ?>
          <a href="<?php echo PORTAL_URL; ?>/invoices/view.php?id=<?php echo (int) $inv['id']; ?>" class="btn btn-primary">
            <i class="fas fa-credit-card"></i> Pay this invoice
          </a>
        <?php endif; ?>
        <a href="<?php echo PORTAL_URL; ?>/services.php" class="btn btn-ghost" style="border:1px solid var(--border)">
          <i class="fas fa-box"></i> My Services
        </a>
      </div>

      <p style="font-size:12px;color:var(--text-muted);margin:18px 0 0">
        You can pay any time from <a href="<?php echo PORTAL_URL; ?>/invoices/">Invoices</a> in your client area.
      </p>
    </div>
  </div>
</div>

<?php checkout_foot(); ?>
