<?php
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

auth_check();
$page_title = 'Orders';
$db = db();

$search = trim($_GET['q']      ?? '');
$status = trim($_GET['status'] ?? '');
$page   = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * PER_PAGE;

$where  = ['1=1'];
$params = [];

if ($search) {
    $where[]  = '(c.first_name LIKE ? OR c.last_name LIKE ? OR c.email LIKE ? OR o.domain LIKE ? OR o.service_name LIKE ?)';
    $like     = "%$search%";
    array_push($params, $like, $like, $like, $like, $like);
}
if ($status) {
    $where[]  = 'o.status = ?';
    $params[] = $status;
}

$wql   = implode(' AND ', $where);
$cnt   = $db->prepare("SELECT COUNT(*) FROM orders o JOIN clients c ON c.id=o.client_id WHERE $wql");
$cnt->execute($params);
$total = (int) $cnt->fetchColumn();

$stmt = $db->prepare("
    SELECT o.*,
           CONCAT(c.first_name,' ',c.last_name) AS client_name,
           c.email AS client_email
    FROM orders o
    JOIN clients c ON c.id = o.client_id
    WHERE $wql
    ORDER BY o.created_at DESC
    LIMIT ? OFFSET ?
");
$stmt->execute(array_merge($params, [PER_PAGE, $offset]));
$orders = $stmt->fetchAll();

require_once '../includes/header.php';
?>

<div class="page-header">
  <div>
    <div class="breadcrumb"><a href="<?php echo APP_URL; ?>/dashboard.php">Dashboard</a><span class="breadcrumb-sep">›</span> Orders</div>
    <h1>Orders <span style="font-size:15px;font-weight:400;color:var(--text-muted)">(<?php echo number_format($total); ?>)</span></h1>
  </div>
  <a href="<?php echo APP_URL; ?>/orders/add.php" class="btn btn-primary">
    <i class="fas fa-plus"></i> New Order
  </a>
</div>

<div class="table-wrap">
  <div class="table-toolbar">
    <form method="GET" class="filter-form">
      <input type="text" name="q" class="search-input"
             placeholder="Search client, domain, service…"
             value="<?php echo h($search); ?>" />
      <select name="status" class="form-select" style="width:140px">
        <option value="">All Status</option>
        <?php foreach (['active','pending','suspended','cancelled','expired'] as $s): ?>
          <option value="<?php echo $s; ?>" <?php echo $status===$s?'selected':''; ?>><?php echo ucfirst($s); ?></option>
        <?php endforeach; ?>
      </select>
      <button type="submit" class="btn btn-ghost btn-sm">Filter</button>
      <?php if ($search||$status): ?><a href="<?php echo APP_URL; ?>/orders/" class="btn btn-ghost btn-sm">Clear</a><?php endif; ?>
    </form>
    <span class="table-count">Showing <?php echo count($orders); ?> of <?php echo number_format($total); ?></span>
  </div>

  <table>
    <thead>
      <tr>
        <th>#</th>
        <th>Client</th>
        <th>Service</th>
        <th>Domain</th>
        <th>Amount</th>
        <th>Billing</th>
        <th>Status</th>
        <th>Next Due</th>
        <th>Actions</th>
      </tr>
    </thead>
    <tbody>
    <?php if ($orders): foreach ($orders as $o): ?>
      <tr>
        <td style="color:var(--text-muted);font-size:12px">#<?php echo $o['id']; ?></td>
        <td>
          <a href="<?php echo APP_URL; ?>/clients/view.php?id=<?php echo $o['client_id']; ?>" style="text-decoration:none">
            <div class="td-name"><?php echo h($o['client_name']); ?></div>
            <div class="td-sub"><?php echo h($o['client_email']); ?></div>
          </a>
        </td>
        <td><?php echo h($o['service_name'] ?? '—'); ?></td>
        <td><?php echo h($o['domain'] ?: '—'); ?></td>
        <td><strong><?php echo format_money($o['amount']); ?></strong></td>
        <td><?php echo badge($o['billing_cycle']); ?></td>
        <td><?php echo badge($o['status']); ?></td>
        <td><?php echo format_date($o['next_due']); ?></td>
        <td class="actions">
          <a href="<?php echo APP_URL; ?>/orders/edit.php?id=<?php echo $o['id']; ?>" class="action-link edit">Edit</a>
          <form method="POST" action="<?php echo APP_URL; ?>/orders/delete.php" style="display:inline">
            <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>" />
            <input type="hidden" name="id"         value="<?php echo $o['id']; ?>" />
            <button type="submit" class="action-link danger"
                    data-confirm="Delete this order? This cannot be undone.">Delete</button>
          </form>
        </td>
      </tr>
    <?php endforeach; else: ?>
      <tr><td colspan="9"><div class="empty-state"><i class="fas fa-box"></i><p>No orders found.</p></div></td></tr>
    <?php endif; ?>
    </tbody>
  </table>

  <?php echo paginate($total, $page, PER_PAGE, APP_URL . '/orders/?q=' . urlencode($search) . '&status=' . urlencode($status)); ?>
</div>

<?php require_once '../includes/footer.php'; ?>
