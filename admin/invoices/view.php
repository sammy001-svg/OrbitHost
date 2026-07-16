<?php
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/SiteSettings.php';
require_once '../includes/Automation.php';
require_once '../includes/Coupon.php';

auth_check();
Coupon::ensureInvoiceColumns();
$_invoice_logo = SiteSettings::logoOnNavy(60, 240);

$id = (int)($_GET['id'] ?? 0);
$stmt = db()->prepare('
    SELECT i.*, c.first_name, c.last_name, c.email AS client_email, c.phone AS client_phone,
           c.company, c.country,
           CONCAT(c.first_name," ",c.last_name) AS client_name
    FROM invoices i
    JOIN clients c ON c.id = i.client_id
    WHERE i.id = ?
');
$stmt->execute([$id]);
$inv = $stmt->fetch();

if (!$inv) {
    flash_set('error', 'Invoice not found.');
    header('Location: ' . APP_URL . '/invoices/index.php');
    exit;
}

$page_title = $inv['invoice_number'];

$items = db()->prepare('SELECT * FROM invoice_items WHERE invoice_id=? ORDER BY id');
$items->execute([$id]);
$items = $items->fetchAll();

// Handle status update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    csrf_verify();
    $ns = $_POST['new_status'] ?? '';
    if (in_array($ns, ['draft','sent','paid','overdue','cancelled'])) {
        $newly_paid = $ns === 'paid' && $inv['status'] !== 'paid';
        $paid = $ns === 'paid' ? date('Y-m-d') : ($inv['paid_date'] ?: null);
        db()->prepare('UPDATE invoices SET status=?, paid_date=? WHERE id=?')->execute([$ns, $paid, $id]);
        // Marking paid manually (e.g. cash/bank transfer with no gateway
        // payment row) previously never advanced next_due or reactivated a
        // suspended order/service the way the automated payment paths do —
        // run the same hook here so both paths stay in sync.
        if ($newly_paid) {
            try { Automation::invoicePaid($id); } catch (\Throwable $e) {}
        }
        log_activity('update_invoice_status', 'invoice', $id, "Status changed to $ns");
        flash_set('success', 'Invoice status updated to ' . ucfirst($ns) . '.');
        header('Location: ' . APP_URL . '/invoices/view.php?id=' . $id);
        exit;
    }
}

$print = isset($_GET['print']);
if ($print) { ob_start(); }

require_once '../includes/header.php';
?>

<div class="page-header no-print">
  <div>
    <div class="breadcrumb"><a href="<?php echo APP_URL; ?>/invoices/">Invoices</a><span class="breadcrumb-sep">›</span> <?php echo h($inv['invoice_number']); ?></div>
    <h1><?php echo h($inv['invoice_number']); ?></h1>
  </div>
  <div class="page-header-actions">
    <!-- Status update -->
    <form method="POST" style="display:flex;gap:8px;align-items:center">
      <input type="hidden" name="csrf_token"   value="<?php echo csrf_token(); ?>" />
      <input type="hidden" name="update_status" value="1" />
      <select name="new_status" class="form-select" style="width:130px">
        <?php foreach (['draft','sent','paid','overdue','cancelled'] as $s): ?>
          <option value="<?php echo $s; ?>" <?php echo $inv['status']===$s?'selected':''; ?>><?php echo ucfirst($s); ?></option>
        <?php endforeach; ?>
      </select>
      <button type="submit" class="btn btn-ghost btn-sm">Update Status</button>
    </form>
    <button onclick="window.print()" class="btn btn-ghost btn-sm"><i class="fas fa-print"></i> Print</button>
    <a href="<?php echo APP_URL; ?>/invoices/" class="btn btn-ghost btn-sm"><i class="fas fa-arrow-left"></i> Back</a>
  </div>
</div>

<!-- Invoice document -->
<div class="card" id="invoiceDoc">
  <div class="card-body" style="padding:40px">

    <!-- Header -->
    <div class="invoice-header">
      <div>
        <?php if ($_invoice_logo): ?>
          <?php echo $_invoice_logo; ?>
        <?php else: ?>
          <div class="invoice-logo">Orbit<span>Cloud</span></div>
        <?php endif; ?>
        <div style="margin-top:10px;font-size:13px;color:var(--text-muted);line-height:1.8">
          Orbit Cloud Limited<br />
          Nairobi, Kenya<br />
          sammyopiyo001@gmail.com<br />
          orbitcloud.com
        </div>
      </div>
      <div class="invoice-status-block">
        <div style="font-size:32px;font-weight:800;color:var(--navy);margin-bottom:8px">INVOICE</div>
        <?php echo badge($inv['status']); ?>
        <table style="margin-top:14px;width:100%">
          <tr><td style="font-size:12px;color:var(--text-muted);padding:3px 6px">Invoice #</td><td style="font-weight:700;padding:3px 6px"><?php echo h($inv['invoice_number']); ?></td></tr>
          <tr><td style="font-size:12px;color:var(--text-muted);padding:3px 6px">Date</td>    <td style="padding:3px 6px"><?php echo format_date($inv['created_at']); ?></td></tr>
          <tr><td style="font-size:12px;color:var(--text-muted);padding:3px 6px">Due Date</td><td style="padding:3px 6px;font-weight:600;color:<?php echo $inv['status']==='overdue'?'var(--danger)':'inherit'; ?>"><?php echo format_date($inv['due_date']); ?></td></tr>
          <?php if ($inv['paid_date']): ?>
          <tr><td style="font-size:12px;color:var(--text-muted);padding:3px 6px">Paid</td>   <td style="padding:3px 6px;color:var(--green);font-weight:600"><?php echo format_date($inv['paid_date']); ?></td></tr>
          <?php endif; ?>
        </table>
      </div>
    </div>

    <!-- Bill To -->
    <div style="margin-bottom:28px">
      <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:var(--text-muted);margin-bottom:8px">Bill To</div>
      <div style="font-size:15px;font-weight:700;color:var(--navy)"><?php echo h($inv['client_name']); ?></div>
      <?php if ($inv['company']): ?><div style="font-size:13px"><?php echo h($inv['company']); ?></div><?php endif; ?>
      <div style="font-size:13px;color:var(--text-muted)"><?php echo h($inv['client_email']); ?></div>
      <?php if ($inv['client_phone']): ?><div style="font-size:13px;color:var(--text-muted)"><?php echo h($inv['client_phone']); ?></div><?php endif; ?>
      <div style="font-size:13px;color:var(--text-muted)"><?php echo h($inv['country']); ?></div>
    </div>

    <!-- Line items -->
    <table style="width:100%;border-collapse:collapse;margin-bottom:8px">
      <thead>
        <tr style="background:#f8fafc;border-bottom:2px solid var(--border)">
          <th style="padding:10px 14px;text-align:left;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted)">Description</th>
          <th style="padding:10px 14px;text-align:center;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted);width:80px">Qty</th>
          <th style="padding:10px 14px;text-align:right;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted);width:120px">Unit Price</th>
          <th style="padding:10px 14px;text-align:right;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted);width:120px">Total</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($items as $item): ?>
        <tr style="border-bottom:1px solid #f1f5f9">
          <td style="padding:12px 14px;font-size:13.5px"><?php echo h($item['description']); ?></td>
          <td style="padding:12px 14px;text-align:center;font-size:13.5px"><?php echo $item['quantity']; ?></td>
          <td style="padding:12px 14px;text-align:right;font-size:13.5px"><?php echo format_money($item['unit_price']); ?></td>
          <td style="padding:12px 14px;text-align:right;font-size:13.5px;font-weight:600"><?php echo format_money($item['total']); ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>

    <!-- Totals -->
    <div style="display:flex;justify-content:flex-end;margin-bottom:28px">
      <table style="width:280px">
        <tr>
          <td style="padding:6px 10px;color:var(--text-muted);font-size:13px">Subtotal</td>
          <td style="padding:6px 10px;text-align:right;font-size:13px"><?php echo format_money($inv['subtotal']); ?></td>
        </tr>
        <?php if ($inv['tax_rate'] > 0): ?>
        <tr>
          <td style="padding:6px 10px;color:var(--text-muted);font-size:13px">VAT (<?php echo $inv['tax_rate']; ?>%)</td>
          <td style="padding:6px 10px;text-align:right;font-size:13px"><?php echo format_money($inv['tax_amount']); ?></td>
        </tr>
        <?php endif; ?>
        <?php if (!empty($inv['discount_amount']) && $inv['discount_amount'] > 0): ?>
        <tr>
          <td style="padding:6px 10px;color:var(--text-muted);font-size:13px">Discount<?php echo $inv['coupon_code'] ? ' (' . h($inv['coupon_code']) . ')' : ''; ?></td>
          <td style="padding:6px 10px;text-align:right;font-size:13px">-<?php echo format_money($inv['discount_amount']); ?></td>
        </tr>
        <?php endif; ?>
        <tr style="border-top:2px solid var(--border)">
          <td style="padding:12px 10px;font-weight:800;font-size:16px;color:var(--navy)">Total Due</td>
          <td style="padding:12px 10px;text-align:right;font-weight:800;font-size:16px;color:var(--navy)"><?php echo format_money($inv['total']); ?></td>
        </tr>
      </table>
    </div>

    <!-- Payment info -->
    <?php if ($inv['payment_method'] || $inv['notes']): ?>
    <div style="border-top:2px solid var(--border);padding-top:20px">
      <?php if ($inv['payment_method']): ?>
        <div style="margin-bottom:8px"><strong style="font-size:12px;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted)">Payment Method:</strong> <?php echo h($inv['payment_method']); ?></div>
      <?php endif; ?>
      <?php if ($inv['notes']): ?>
        <div style="font-size:13px;color:var(--text-muted)"><?php echo nl2br(h($inv['notes'])); ?></div>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Footer -->
    <div style="margin-top:32px;text-align:center;font-size:12px;color:var(--text-muted);border-top:1px solid var(--border);padding-top:16px">
      Thank you for choosing Orbit Cloud. &nbsp;|&nbsp; For queries: sammyopiyo001@gmail.com
    </div>

  </div>
</div>

<?php require_once '../includes/footer.php';

if ($print) {
    $html = ob_get_clean();
    echo $html;
    echo '<script>window.onload=function(){window.print();};</script>';
}
?>
