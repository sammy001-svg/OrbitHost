<?php
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/providers/Provider.php';

auth_check();
$page_title = 'Payments';

// KPIs
$collected = (float) db()->query("SELECT COALESCE(SUM(amount),0) FROM payments WHERE status='completed' AND MONTH(created_at)=MONTH(CURDATE()) AND YEAR(created_at)=YEAR(CURDATE())")->fetchColumn();
$pending   = (float) db()->query("SELECT COALESCE(SUM(amount),0) FROM payments WHERE status='pending'")->fetchColumn();
$unpaid    = (float) db()->query("SELECT COALESCE(SUM(total),0) FROM invoices WHERE status IN ('sent','overdue')")->fetchColumn();

// Active gateways
$gateways = [];
foreach (ProviderRegistry::byCategory('payment') as $key => $def) {
    if (Provider::isActive($key) && Provider::isConfigured($key)) $gateways[$key] = $def;
}

// Recent payments
$payments = db()->query(
    "SELECT p.*, c.first_name, c.last_name, i.invoice_number
     FROM payments p
     LEFT JOIN clients c ON c.id = p.client_id
     LEFT JOIN invoices i ON i.id = p.invoice_id
     ORDER BY p.created_at DESC LIMIT 50"
)->fetchAll();

// Unpaid invoices ready to collect
$open_invoices = db()->query(
    "SELECT i.*, c.first_name, c.last_name FROM invoices i
     LEFT JOIN clients c ON c.id = i.client_id
     WHERE i.status IN ('sent','overdue','draft') ORDER BY i.due_date ASC LIMIT 20"
)->fetchAll();

function pay_badge(string $s): string
{
    $m = ['completed'=>'badge-success','pending'=>'badge-warning','failed'=>'badge-danger','refunded'=>'badge-secondary'];
    return '<span class="badge ' . ($m[$s] ?? 'badge-secondary') . '">' . ucfirst($s) . '</span>';
}

require_once '../includes/header.php';
?>

<div class="content-header">
  <div>
    <h1 class="content-title">Payments</h1>
    <p class="page-subtitle">Collect invoice payments through your connected gateways and track every transaction.</p>
  </div>
  <a href="<?php echo APP_URL; ?>/integrations/#prov-stripe" class="btn btn-ghost"><i class="fas fa-plug"></i> Gateways</a>
</div>

<div class="stat-grid">
  <div class="stat-card">
    <div class="stat-icon green"><i class="fas fa-sack-dollar"></i></div>
    <div><div class="stat-label">Collected (month)</div><div class="stat-value" style="font-size:22px"><?php echo format_money($collected); ?></div></div>
  </div>
  <div class="stat-card">
    <div class="stat-icon orange"><i class="fas fa-hourglass-half"></i></div>
    <div><div class="stat-label">Pending</div><div class="stat-value" style="font-size:22px"><?php echo format_money($pending); ?></div></div>
  </div>
  <div class="stat-card">
    <div class="stat-icon red"><i class="fas fa-file-invoice-dollar"></i></div>
    <div><div class="stat-label">Unpaid invoices</div><div class="stat-value" style="font-size:22px"><?php echo format_money($unpaid); ?></div></div>
  </div>
  <div class="stat-card">
    <div class="stat-icon navy"><i class="fas fa-credit-card"></i></div>
    <div><div class="stat-label">Active gateways</div><div class="stat-value"><?php echo count($gateways); ?></div></div>
  </div>
</div>

<?php if (!$gateways): ?>
  <div class="alert alert-info"><i class="fas fa-circle-info"></i> No payment method is active yet. Enable Stripe, PayPal, M-Pesa (Kopo Kopo STK), Flutterwave — or an offline method like Bank Transfer, Manual M-Pesa or Cheque — in <a href="<?php echo APP_URL; ?>/integrations/" style="font-weight:600">Providers</a>.</div>
<?php endif; ?>

<div style="display:grid;grid-template-columns:1fr 360px;gap:18px;align-items:start">
  <!-- Payments -->
  <div class="table-wrap">
    <div class="table-toolbar"><span class="card-title">Recent payments</span><span class="table-count"><?php echo count($payments); ?> shown</span></div>
    <div class="table-scroll">
    <table>
      <thead><tr><th>Reference</th><th>Client</th><th>Gateway</th><th>Amount</th><th>Status</th><th>Date</th></tr></thead>
      <tbody>
        <?php if (!$payments): ?>
          <tr><td colspan="6"><div class="empty-state"><i class="fas fa-receipt"></i><p>No payments recorded yet.</p></div></td></tr>
        <?php else: foreach ($payments as $p): ?>
          <tr>
            <td>
              <div class="td-name mono" style="font-size:12px"><?php echo h($p['gateway_ref'] ?: '—'); ?></div>
              <?php if ($p['invoice_number']): ?><div class="td-sub"><?php echo h($p['invoice_number']); ?></div><?php endif; ?>
            </td>
            <td><?php echo $p['first_name'] ? h($p['first_name'] . ' ' . $p['last_name']) : '<span class="text-muted">—</span>'; ?></td>
            <td><span class="code-chip"><?php echo h($p['gateway']); ?></span></td>
            <td class="fw-600"><?php echo h($p['currency']); ?> <?php echo number_format((float)$p['amount'], 2); ?></td>
            <td><?php echo pay_badge($p['status']); ?></td>
            <td style="font-size:12px;color:var(--text-muted)"><?php echo time_ago($p['created_at']); ?></td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
    </div>
  </div>

  <!-- Collect -->
  <div class="card">
    <div class="card-header"><span class="card-title"><i class="fas fa-hand-holding-dollar"></i> Collect payment</span></div>
    <div class="card-flush">
      <?php if (!$open_invoices): ?>
        <div class="empty-state" style="padding:32px"><i class="fas fa-check-circle"></i><p>No open invoices.</p></div>
      <?php else: ?>
        <div style="padding:6px 0">
        <?php foreach ($open_invoices as $inv): ?>
          <a href="<?php echo APP_URL; ?>/billing/collect.php?invoice_id=<?php echo $inv['id']; ?>" class="data-list" style="text-decoration:none;display:block;padding:0 18px">
            <div class="row">
              <div>
                <div class="fw-600" style="font-size:13px;color:var(--navy)"><?php echo h($inv['invoice_number']); ?></div>
                <div class="td-sub"><?php echo $inv['first_name'] ? h($inv['first_name'] . ' ' . $inv['last_name']) : 'Client'; ?> · <?php echo badge($inv['status']); ?></div>
              </div>
              <div style="text-align:right">
                <div class="fw-700"><?php echo format_money((float)$inv['total']); ?></div>
                <div class="td-sub"><i class="fas fa-arrow-right"></i> Collect</div>
              </div>
            </div>
          </a>
        <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php require_once '../includes/footer.php'; ?>
