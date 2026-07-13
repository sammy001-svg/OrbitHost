<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once dirname(__DIR__, 2) . '/admin/includes/functions.php';

portal_check();
$cid = current_client()['id'];

$id   = (int)($_GET['id'] ?? 0);
$stmt = db()->prepare('SELECT i.*, c.first_name, c.last_name, c.email AS client_email, c.phone AS client_phone, c.company, c.country FROM invoices i JOIN clients c ON c.id=i.client_id WHERE i.id=? AND i.client_id=?');
$stmt->execute([$id, $cid]);
$inv = $stmt->fetch();

if (!$inv) {
    portal_flash_set('error', 'Invoice not found.');
    header('Location: ' . PORTAL_URL . '/invoices/');
    exit;
}

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

  <div class="p-card">
    <div class="p-card-body" style="padding:40px">

      <!-- Invoice header -->
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:24px;margin-bottom:32px">
        <div>
          <div class="inv-logo">Orbit<span>Host</span></div>
          <div style="margin-top:10px;font-size:13px;color:var(--text-muted);line-height:1.8">
            OrbitHost Limited<br />Nairobi, Kenya<br />sammyopiyo001@gmail.com
          </div>
        </div>
        <div style="text-align:right">
          <div style="font-size:30px;font-weight:800;color:var(--navy);margin-bottom:8px">INVOICE</div>
          <?php echo badge($inv['status']); ?>
          <table style="margin-top:14px;margin-left:auto">
            <tr><td style="font-size:12px;color:var(--text-muted);padding:3px 8px;text-align:right">Invoice #</td><td style="font-weight:700;padding:3px 8px"><?php echo htmlspecialchars($inv['invoice_number']); ?></td></tr>
            <tr><td style="font-size:12px;color:var(--text-muted);padding:3px 8px;text-align:right">Date</td>    <td style="padding:3px 8px"><?php echo format_date($inv['created_at']); ?></td></tr>
            <tr><td style="font-size:12px;color:var(--text-muted);padding:3px 8px;text-align:right">Due</td>     <td style="padding:3px 8px;font-weight:600;color:<?php echo $inv['status']==='overdue'?'var(--danger)':'inherit'; ?>"><?php echo format_date($inv['due_date']); ?></td></tr>
          </table>
        </div>
      </div>

      <!-- Bill to -->
      <div style="margin-bottom:28px">
        <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.7px;color:var(--text-muted);margin-bottom:8px">Bill To</div>
        <div style="font-size:15px;font-weight:700;color:var(--navy)"><?php echo htmlspecialchars($inv['first_name'] . ' ' . $inv['last_name']); ?></div>
        <?php if ($inv['company']): ?><div style="font-size:13px"><?php echo htmlspecialchars($inv['company']); ?></div><?php endif; ?>
        <div style="font-size:13px;color:var(--text-muted)"><?php echo htmlspecialchars($inv['client_email']); ?></div>
        <div style="font-size:13px;color:var(--text-muted)"><?php echo htmlspecialchars($inv['country']); ?></div>
      </div>

      <!-- Line items -->
      <table style="width:100%;border-collapse:collapse;margin-bottom:8px">
        <thead>
          <tr style="background:#f8fafc;border-bottom:2px solid var(--border)">
            <th style="padding:10px 14px;text-align:left;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted)">Description</th>
            <th style="padding:10px 14px;text-align:center;font-size:11px;font-weight:700;text-transform:uppercase;color:var(--text-muted);width:80px">Qty</th>
            <th style="padding:10px 14px;text-align:right;font-size:11px;font-weight:700;text-transform:uppercase;color:var(--text-muted);width:120px">Unit Price</th>
            <th style="padding:10px 14px;text-align:right;font-size:11px;font-weight:700;text-transform:uppercase;color:var(--text-muted);width:120px">Total</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($items as $it): ?>
          <tr style="border-bottom:1px solid #f1f5f9">
            <td style="padding:12px 14px;font-size:13.5px"><?php echo htmlspecialchars($it['description']); ?></td>
            <td style="padding:12px 14px;text-align:center;font-size:13.5px"><?php echo $it['quantity']; ?></td>
            <td style="padding:12px 14px;text-align:right;font-size:13.5px"><?php echo format_money($it['unit_price']); ?></td>
            <td style="padding:12px 14px;text-align:right;font-weight:600;font-size:13.5px"><?php echo format_money($it['total']); ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>

      <!-- Totals -->
      <div style="display:flex;justify-content:flex-end;margin-bottom:28px">
        <table style="width:260px">
          <tr>
            <td style="padding:5px 10px;color:var(--text-muted);font-size:13px">Subtotal</td>
            <td style="padding:5px 10px;text-align:right;font-size:13px"><?php echo format_money($inv['subtotal']); ?></td>
          </tr>
          <?php if ($inv['tax_rate'] > 0): ?>
          <tr>
            <td style="padding:5px 10px;color:var(--text-muted);font-size:13px">VAT (<?php echo $inv['tax_rate']; ?>%)</td>
            <td style="padding:5px 10px;text-align:right;font-size:13px"><?php echo format_money($inv['tax_amount']); ?></td>
          </tr>
          <?php endif; ?>
          <tr style="border-top:2px solid var(--border)">
            <td style="padding:12px 10px;font-weight:800;font-size:16px;color:var(--navy)">Total</td>
            <td style="padding:12px 10px;text-align:right;font-weight:800;font-size:16px;color:var(--navy)"><?php echo format_money($inv['total']); ?></td>
          </tr>
        </table>
      </div>

      <?php if ($inv['notes']): ?>
      <div style="border-top:1px solid var(--border);padding-top:16px;font-size:13px;color:var(--text-muted)">
        <?php echo nl2br(htmlspecialchars($inv['notes'])); ?>
      </div>
      <?php endif; ?>

      <div style="text-align:center;margin-top:28px;font-size:12px;color:var(--text-muted);border-top:1px solid var(--border);padding-top:14px">
        Thank you for choosing OrbitHost! For queries: sammyopiyo001@gmail.com
      </div>
    </div>
  </div>

  <?php if (in_array($inv['status'], ['sent','overdue'])): ?>
  <!-- Payment section -->
  <div class="p-card no-print" id="pay" style="margin-top:20px">
    <div class="p-card-header">
      <div class="p-card-title"><i class="fas fa-credit-card" style="color:var(--green);margin-right:8px"></i>Pay This Invoice</div>
      <div><?php echo badge($inv['status']); ?></div>
    </div>
    <div class="p-card-body">
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px;margin-bottom:20px">

        <div style="border:2px solid var(--border);border-radius:10px;padding:18px;text-align:center;cursor:pointer;transition:.14s" onmouseover="this.style.borderColor='var(--green)'" onmouseout="this.style.borderColor='var(--border)'">
          <div style="font-size:28px;margin-bottom:8px">📱</div>
          <div style="font-weight:700;font-size:14px">M-Pesa</div>
          <div style="font-size:12px;color:var(--text-muted);margin-top:4px">Paybill: <strong>522533</strong><br />Account: INV-<?php echo htmlspecialchars($inv['invoice_number']); ?></div>
        </div>

        <div style="border:2px solid var(--border);border-radius:10px;padding:18px;text-align:center;cursor:pointer;transition:.14s" onmouseover="this.style.borderColor='var(--green)'" onmouseout="this.style.borderColor='var(--border)'">
          <div style="font-size:28px;margin-bottom:8px">💳</div>
          <div style="font-weight:700;font-size:14px">Bank Transfer</div>
          <div style="font-size:12px;color:var(--text-muted);margin-top:4px">Equity Bank<br />Acc: 0123456789</div>
        </div>

        <div style="border:2px solid var(--border);border-radius:10px;padding:18px;text-align:center;cursor:pointer;transition:.14s" onmouseover="this.style.borderColor='var(--green)'" onmouseout="this.style.borderColor='var(--border)'">
          <div style="font-size:28px;margin-bottom:8px">🌐</div>
          <div style="font-weight:700;font-size:14px">PayPal</div>
          <div style="font-size:12px;color:var(--text-muted);margin-top:4px">sammyopiyo001<br />@gmail.com</div>
        </div>

      </div>
      <div class="p-alert p-alert-info">
        <i class="fas fa-info-circle"></i>
        After payment, please <a href="<?php echo PORTAL_URL; ?>/tickets/add.php?subject=Payment+confirmation+<?php echo urlencode($inv['invoice_number']); ?>" style="color:var(--navy);font-weight:600">open a support ticket</a> with your payment confirmation. We'll update your invoice within 1 business hour.
      </div>
    </div>
  </div>
  <?php endif; ?>

</div>
</div>

<?php require_once '../includes/footer.php'; ?>
