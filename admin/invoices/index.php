<?php
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

auth_check();
$page_title = 'Invoices';
$db = db();

$search = trim($_GET['q']      ?? '');
$status = trim($_GET['status'] ?? '');
$page   = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * PER_PAGE;

$where  = ['1=1'];
$params = [];

if ($search) {
    $where[]  = '(i.invoice_number LIKE ? OR c.first_name LIKE ? OR c.last_name LIKE ? OR c.email LIKE ?)';
    $like     = "%$search%";
    array_push($params, $like, $like, $like, $like);
}
if ($status) { $where[] = 'i.status=?'; $params[] = $status; }

$wql   = implode(' AND ', $where);
$cnt   = $db->prepare("SELECT COUNT(*) FROM invoices i JOIN clients c ON c.id=i.client_id WHERE $wql");
$cnt->execute($params);
$total = (int) $cnt->fetchColumn();

$stmt = $db->prepare("
    SELECT i.*, CONCAT(c.first_name,' ',c.last_name) AS client_name, c.email AS client_email
    FROM invoices i
    JOIN clients c ON c.id = i.client_id
    WHERE $wql
    ORDER BY i.created_at DESC
    LIMIT ? OFFSET ?
");
$stmt->execute(array_merge($params, [PER_PAGE, $offset]));
$invoices = $stmt->fetchAll();

// Revenue summary
$summary = $db->query("SELECT status, COALESCE(SUM(total),0) AS total FROM invoices GROUP BY status")->fetchAll();
$summary_map = array_column($summary, 'total', 'status');

require_once '../includes/header.php';
?>

<div class="page-header">
  <div>
    <div class="breadcrumb"><a href="<?php echo APP_URL; ?>/dashboard.php">Dashboard</a><span class="breadcrumb-sep">›</span> Invoices</div>
    <h1>Invoices <span style="font-size:15px;font-weight:400;color:var(--text-muted)">(<?php echo number_format($total); ?>)</span></h1>
  </div>
  <a href="<?php echo APP_URL; ?>/invoices/add.php" class="btn btn-primary">
    <i class="fas fa-plus"></i> New Invoice
  </a>
</div>

<!-- Summary cards -->
<div class="stat-grid" style="margin-bottom:20px">
  <?php
  $cards = [
    ['Paid',    'paid',    'green',  'fa-check-circle'],
    ['Pending', 'sent',    'navy',   'fa-paper-plane'],
    ['Overdue', 'overdue', 'red',    'fa-exclamation-circle'],
    ['Draft',   'draft',   'orange', 'fa-file-alt'],
  ];
  foreach ($cards as [$label, $key, $color, $icon]):
  ?>
  <div class="stat-card" style="cursor:pointer" onclick="location='<?php echo APP_URL; ?>/invoices/?status=<?php echo $key; ?>'">
    <div class="stat-icon <?php echo $color; ?>"><i class="fas <?php echo $icon; ?>"></i></div>
    <div>
      <div class="stat-label"><?php echo $label; ?></div>
      <div class="stat-value"><?php echo format_money($summary_map[$key] ?? 0); ?></div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<div class="table-wrap">
  <div class="table-toolbar">
    <form method="GET" class="filter-form">
      <input type="text" name="q" class="search-input"
             placeholder="Search invoice #, client…"
             value="<?php echo h($search); ?>" />
      <select name="status" class="form-select" style="width:130px">
        <option value="">All Status</option>
        <?php foreach (['draft','sent','paid','overdue','cancelled'] as $s): ?>
          <option value="<?php echo $s; ?>" <?php echo $status===$s?'selected':''; ?>><?php echo ucfirst($s); ?></option>
        <?php endforeach; ?>
      </select>
      <button type="submit" class="btn btn-ghost btn-sm">Filter</button>
      <?php if ($search||$status): ?><a href="<?php echo APP_URL; ?>/invoices/" class="btn btn-ghost btn-sm">Clear</a><?php endif; ?>
    </form>
    <span class="table-count">Showing <?php echo count($invoices); ?> of <?php echo number_format($total); ?></span>
  </div>

  <table>
    <thead>
      <tr>
        <th>Invoice #</th>
        <th>Client</th>
        <th>Subtotal</th>
        <th>Tax</th>
        <th>Total</th>
        <th>Status</th>
        <th>Due Date</th>
        <th>Paid Date</th>
        <th>Actions</th>
      </tr>
    </thead>
    <tbody>
    <?php if ($invoices): foreach ($invoices as $inv): ?>
      <tr>
        <td>
          <a href="<?php echo APP_URL; ?>/invoices/view.php?id=<?php echo $inv['id']; ?>"
             style="font-weight:700;color:var(--navy);text-decoration:none">
            <?php echo h($inv['invoice_number']); ?>
          </a>
        </td>
        <td>
          <a href="<?php echo APP_URL; ?>/clients/view.php?id=<?php echo $inv['client_id']; ?>" style="color:var(--text);text-decoration:none">
            <div class="td-name"><?php echo h($inv['client_name']); ?></div>
            <div class="td-sub"><?php echo h($inv['client_email']); ?></div>
          </a>
        </td>
        <td><?php echo format_money($inv['subtotal']); ?></td>
        <td><?php echo $inv['tax_rate'] > 0 ? format_money($inv['tax_amount']) : '—'; ?></td>
        <td><strong><?php echo format_money($inv['total']); ?></strong></td>
        <td><?php echo badge($inv['status']); ?></td>
        <td><?php echo format_date($inv['due_date']); ?></td>
        <td><?php echo format_date($inv['paid_date']); ?></td>
        <td class="actions">
          <a href="<?php echo APP_URL; ?>/invoices/view.php?id=<?php echo $inv['id']; ?>"   class="action-link view">View</a>
          <a href="<?php echo APP_URL; ?>/invoices/view.php?id=<?php echo $inv['id']; ?>&print=1" class="action-link" target="_blank">Print</a>
          <form method="POST" action="<?php echo APP_URL; ?>/invoices/delete.php" style="display:inline">
            <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>" />
            <input type="hidden" name="id"         value="<?php echo $inv['id']; ?>" />
            <button type="submit" class="action-link danger"
                    data-confirm="Delete invoice <?php echo h($inv['invoice_number']); ?>?">Delete</button>
          </form>
        </td>
      </tr>
    <?php endforeach; else: ?>
      <tr><td colspan="9"><div class="empty-state"><i class="fas fa-file-invoice"></i><p>No invoices found.</p></div></td></tr>
    <?php endif; ?>
    </tbody>
  </table>

  <?php echo paginate($total, $page, PER_PAGE, APP_URL . '/invoices/?q=' . urlencode($search) . '&status=' . urlencode($status)); ?>
</div>

<?php require_once '../includes/footer.php'; ?>
