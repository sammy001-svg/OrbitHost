<?php
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

auth_check();
$page_title = 'Clients';
$db = db();

$search = trim($_GET['q']      ?? '');
$status = trim($_GET['status'] ?? '');
$page   = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * PER_PAGE;

$where  = ['1=1'];
$params = [];

if ($search) {
    $where[]  = '(c.first_name LIKE ? OR c.last_name LIKE ? OR c.email LIKE ? OR c.company LIKE ?)';
    $like     = "%$search%";
    array_push($params, $like, $like, $like, $like);
}
if ($status) {
    $where[]  = 'c.status = ?';
    $params[] = $status;
}

$wql = implode(' AND ', $where);

$cnt = $db->prepare("SELECT COUNT(*) FROM clients c WHERE $wql");
$cnt->execute($params);
$total = (int) $cnt->fetchColumn();

$stmt = $db->prepare("
    SELECT c.*,
           COUNT(DISTINCT o.id) AS order_count,
           COUNT(DISTINCT i.id) AS invoice_count
    FROM clients c
    LEFT JOIN orders o   ON o.client_id = c.id
    LEFT JOIN invoices i ON i.client_id = c.id
    WHERE $wql
    GROUP BY c.id
    ORDER BY c.created_at DESC
    LIMIT ? OFFSET ?
");
$stmt->execute(array_merge($params, [PER_PAGE, $offset]));
$clients = $stmt->fetchAll();

require_once '../includes/header.php';
?>

<div class="page-header">
  <div>
    <div class="breadcrumb"><a href="<?php echo APP_URL; ?>/dashboard.php">Dashboard</a><span class="breadcrumb-sep">›</span> Clients</div>
    <h1>Clients <span style="font-size:15px;font-weight:400;color:var(--text-muted)">(<?php echo number_format($total); ?>)</span></h1>
  </div>
  <a href="<?php echo APP_URL; ?>/clients/add.php" class="btn btn-primary">
    <i class="fas fa-plus"></i> Add Client
  </a>
</div>

<div class="table-wrap">
  <div class="table-toolbar">
    <form method="GET" class="filter-form">
      <input type="text" name="q" class="search-input" placeholder="Search name, email, company…"
             value="<?php echo h($search); ?>" />
      <select name="status" class="form-select" style="width:140px">
        <option value="">All Status</option>
        <option value="active"    <?php echo $status === 'active'    ? 'selected' : ''; ?>>Active</option>
        <option value="suspended" <?php echo $status === 'suspended' ? 'selected' : ''; ?>>Suspended</option>
        <option value="cancelled" <?php echo $status === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
      </select>
      <button type="submit" class="btn btn-ghost btn-sm">Filter</button>
      <?php if ($search || $status): ?>
        <a href="<?php echo APP_URL; ?>/clients/" class="btn btn-ghost btn-sm">Clear</a>
      <?php endif; ?>
    </form>
    <span class="table-count">Showing <?php echo count($clients); ?> of <?php echo number_format($total); ?></span>
  </div>

  <div id="bulkBar" style="display:none;align-items:center;gap:10px;padding:10px 20px;background:var(--green-light);border-bottom:1px solid var(--border);font-size:13px">
    <span id="bulkCount" style="font-weight:600;color:var(--navy)"></span>
    <button type="button" class="btn btn-primary btn-sm" id="bulkAnnounceBtn"><i class="fas fa-bullhorn"></i> Send Announcement</button>
    <button type="button" class="btn btn-ghost btn-sm" id="bulkClearBtn">Clear selection</button>
  </div>

  <table>
    <thead>
      <tr>
        <th style="width:34px"><input type="checkbox" id="selectAll" /></th>
        <th>Client</th>
        <th>Company</th>
        <th>Country</th>
        <th>Orders</th>
        <th>Invoices</th>
        <th>Status</th>
        <th>Joined</th>
        <th>Actions</th>
      </tr>
    </thead>
    <tbody>
    <?php if ($clients): foreach ($clients as $c): ?>
      <tr>
        <td><input type="checkbox" class="row-check" value="<?php echo (int) $c['id']; ?>" /></td>
        <td>
          <a href="<?php echo APP_URL; ?>/clients/view.php?id=<?php echo $c['id']; ?>" style="text-decoration:none">
            <div class="td-name"><?php echo h($c['first_name'] . ' ' . $c['last_name']); ?></div>
            <div class="td-sub"><?php echo h($c['email']); ?></div>
          </a>
        </td>
        <td><?php echo h($c['company'] ?: '—'); ?></td>
        <td><?php echo h($c['country']); ?></td>
        <td><strong><?php echo $c['order_count']; ?></strong></td>
        <td><strong><?php echo $c['invoice_count']; ?></strong></td>
        <td><?php echo badge($c['status']); ?></td>
        <td><?php echo format_date($c['created_at']); ?></td>
        <td class="actions">
          <a href="<?php echo APP_URL; ?>/clients/view.php?id=<?php echo $c['id']; ?>" class="action-link view">View</a>
          <a href="<?php echo APP_URL; ?>/clients/edit.php?id=<?php echo $c['id']; ?>" class="action-link edit">Edit</a>
          <form method="POST" action="<?php echo APP_URL; ?>/clients/delete.php" style="display:inline">
            <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>" />
            <input type="hidden" name="id"         value="<?php echo $c['id']; ?>" />
            <button type="submit" class="action-link danger"
                    data-confirm="Delete <?php echo h($c['first_name'] . ' ' . $c['last_name']); ?>? All their orders, invoices and tickets will also be removed.">
              Delete
            </button>
          </form>
        </td>
      </tr>
    <?php endforeach; else: ?>
      <tr>
        <td colspan="9">
          <div class="empty-state">
            <i class="fas fa-users"></i>
            <p>No clients found<?php echo $search ? " for \"$search\"" : ''; ?>.</p>
          </div>
        </td>
      </tr>
    <?php endif; ?>
    </tbody>
  </table>

  <?php echo paginate($total, $page, PER_PAGE, APP_URL . '/clients/?q=' . urlencode($search) . '&status=' . urlencode($status)); ?>
</div>

<script>
(function () {
  var selectAll = document.getElementById('selectAll');
  var rowChecks = Array.prototype.slice.call(document.querySelectorAll('.row-check'));
  var bar = document.getElementById('bulkBar');
  var countEl = document.getElementById('bulkCount');

  function selected() { return rowChecks.filter(function (c) { return c.checked; }); }

  function refresh() {
    var n = selected().length;
    bar.style.display = n ? 'flex' : 'none';
    countEl.textContent = n + ' client' + (n === 1 ? '' : 's') + ' selected';
    if (selectAll) selectAll.checked = n > 0 && n === rowChecks.length;
  }

  rowChecks.forEach(function (c) { c.addEventListener('change', refresh); });
  if (selectAll) {
    selectAll.addEventListener('change', function () {
      rowChecks.forEach(function (c) { c.checked = selectAll.checked; });
      refresh();
    });
  }

  document.getElementById('bulkClearBtn').addEventListener('click', function () {
    rowChecks.forEach(function (c) { c.checked = false; });
    refresh();
  });

  document.getElementById('bulkAnnounceBtn').addEventListener('click', function () {
    var ids = selected().map(function (c) { return c.value; });
    if (!ids.length) return;
    location.href = <?php echo json_encode(APP_URL . '/clients/announce.php'); ?> + '?ids=' + ids.join(',');
  });
})();
</script>

<?php require_once '../includes/footer.php'; ?>
