<?php
/**
 * Orbit Cloud — order placed.
 *
 * The order and its invoice exist and the invoice is on its way by email.
 * From here the client pays now (the invoice page handles every gateway,
 * M-Pesa STK, account credit and offline references) or later from their
 * client area — which, if they just signed up, is theirs from this moment.
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

$items = db()->prepare('SELECT * FROM invoice_items WHERE invoice_id = ? ORDER BY id');
$items->execute([$id]);
$items = $items->fetchAll();

$me = db()->prepare('SELECT first_name, email FROM clients WHERE id = ?');
$me->execute([$cid]);
$me = $me->fetch() ?: [];

$currency    = $inv['currency'] ?: Currency::current();
$paid        = $inv['status'] === 'paid';
$new_account = !empty($_SESSION['checkout_new_account']);
unset($_SESSION['checkout_new_account']); // shown once

checkout_head('Order received', 0);
?>
<style>
  .done-hero { text-align: center; padding: 34px 26px 10px; }
  .done-hero .ok { width: 72px; height: 72px; border-radius: 50%; background: var(--green-light); color: var(--green); display: flex; align-items: center; justify-content: center; font-size: 34px; margin: 0 auto 14px; }
  .done-line { display: flex; justify-content: space-between; gap: 12px; padding: 9px 0; border-bottom: 1px dashed var(--border); font-size: 13px; }
  .done-line:last-child { border-bottom: 0; }
  .done-steps { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; margin-top: 4px; }
  .done-steps div { border: 1px solid var(--border); border-radius: var(--radius); padding: 12px; font-size: 12.5px; color: var(--text-2); }
  .done-steps i { color: var(--green); display: block; font-size: 16px; margin-bottom: 6px; }
  @media (max-width: 600px) { .done-steps { grid-template-columns: 1fr; } }
</style>

<div style="max-width:660px;margin:0 auto">
  <div class="co-panel">
    <div class="done-hero">
      <div class="ok"><i class="fas fa-check"></i></div>
      <h1 style="font-size:21px;color:var(--text);margin:0 0 6px">Thank you<?php echo !empty($me['first_name']) ? ', ' . h($me['first_name']) : ''; ?> — your order is in</h1>
      <p style="font-size:13.5px;color:var(--text-muted);margin:0">
        Invoice <strong><?php echo h($inv['invoice_number']); ?></strong> has been emailed to <?php echo h($me['email'] ?? 'you'); ?>.
        <?php if (!$paid): ?>Everything is set up automatically as soon as it's paid.<?php endif; ?>
      </p>
    </div>

    <div class="co-panel-body">
      <?php if ($new_account): ?>
        <div class="p-alert p-alert-success" style="text-align:left;margin-bottom:16px">
          <i class="fas fa-user-check"></i>
          <span><strong>Your client account is ready.</strong> You're signed in now — next time, sign in with
          <strong><?php echo h($me['email'] ?? ''); ?></strong> and the password you just chose. We've sent a link to confirm your email address.</span>
        </div>
      <?php endif; ?>

      <div style="border:1px solid var(--border);border-radius:var(--radius);padding:6px 16px;margin-bottom:16px">
        <?php foreach ($items as $it): ?>
          <div class="done-line">
            <span style="color:var(--text-2)"><?php echo h($it['description']); ?></span>
            <span style="font-weight:700;white-space:nowrap;color:<?php echo (float) $it['total'] < 0 ? 'var(--green)' : 'var(--text)'; ?>">
              <?php echo (float) $it['total'] == 0 ? 'Free' : ((float) $it['total'] < 0 ? '−' : '') . h(checkout_money(abs((float) $it['total']), $currency)); ?></span>
          </div>
        <?php endforeach; ?>
        <?php if ((float) $inv['tax_amount'] > 0): ?>
          <div class="done-line"><span>VAT (<?php echo rtrim(rtrim(number_format((float) $inv['tax_rate'], 2, '.', ''), '0'), '.'); ?>%)</span><span style="font-weight:700"><?php echo h(checkout_money((float) $inv['tax_amount'], $currency)); ?></span></div>
        <?php endif; ?>
        <div class="co-sum-total"><span>Total</span><span><?php echo h(checkout_money((float) $inv['total'], $currency)); ?></span></div>
      </div>

      <?php if ($paid): ?>
        <div class="p-alert p-alert-success" style="text-align:left"><i class="fas fa-circle-check"></i> This invoice is paid — we're setting everything up now.</div>
      <?php else: ?>
        <a href="<?php echo PORTAL_URL; ?>/invoices/view.php?id=<?php echo (int) $inv['id']; ?>#pay" class="btn btn-primary" style="width:100%;justify-content:center;padding:13px;font-size:15px;font-weight:700">
          <i class="fas fa-lock"></i> Pay <?php echo h(checkout_money((float) $inv['total'], $currency)); ?> now
        </a>
        <p style="text-align:center;font-size:12px;color:var(--text-muted);margin:8px 0 0">M-Pesa, card or bank transfer · or pay later from Invoices in your client area</p>
      <?php endif; ?>

      <div class="done-steps" style="margin-top:20px">
        <div><i class="fas fa-credit-card"></i> <strong>1. Pay</strong><br>Your invoice is ready to pay securely.</div>
        <div><i class="fas fa-gears"></i> <strong>2. We set up</strong><br><?php echo $inv['order_id'] ? 'Hosting is provisioned and domains registered' : 'Your domains are registered'; ?> automatically.</div>
        <div><i class="fas fa-envelope-open-text"></i> <strong>3. You're live</strong><br>We email your details the moment it's ready.</div>
      </div>

      <div style="display:flex;gap:10px;justify-content:center;flex-wrap:wrap;margin-top:20px">
        <a href="<?php echo PORTAL_URL; ?>/dashboard.php" class="btn btn-ghost" style="border:1px solid var(--border)"><i class="fas fa-gauge"></i> Go to my client area</a>
        <a href="<?php echo PORTAL_URL; ?>/<?php echo $inv['order_id'] ? 'services.php' : 'domains.php'; ?>" class="btn btn-ghost" style="border:1px solid var(--border)">
          <i class="fas <?php echo $inv['order_id'] ? 'fa-box' : 'fa-globe'; ?>"></i> <?php echo $inv['order_id'] ? 'My services' : 'My domains'; ?></a>
      </div>
    </div>
  </div>
</div>

<?php checkout_foot(); ?>
