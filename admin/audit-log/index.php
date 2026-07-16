<?php
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

auth_check();
$page_title = 'Audit Log';
$db = db();

$search      = trim($_GET['q']      ?? '');
$admin_id    = (int) ($_GET['admin_id'] ?? 0);
$entity_type = trim($_GET['entity_type'] ?? '');
$from        = trim($_GET['from'] ?? '');
$to          = trim($_GET['to']   ?? '');
$page        = max(1, (int) ($_GET['page'] ?? 1));
$offset      = ($page - 1) * PER_PAGE;

$where  = ['1=1'];
$params = [];

if ($search) {
    $where[]  = '(al.description LIKE ? OR al.action LIKE ?)';
    $like     = "%$search%";
    array_push($params, $like, $like);
}
if ($admin_id) {
    $where[]  = 'al.admin_id = ?';
    $params[] = $admin_id;
}
if ($entity_type) {
    $where[]  = 'al.entity_type = ?';
    $params[] = $entity_type;
}
if ($from) {
    $where[]  = 'al.created_at >= ?';
    $params[] = $from . ' 00:00:00';
}
if ($to) {
    $where[]  = 'al.created_at <= ?';
    $params[] = $to . ' 23:59:59';
}

$wql = implode(' AND ', $where);

$cnt = $db->prepare("SELECT COUNT(*) FROM activity_log al WHERE $wql");
$cnt->execute($params);
$total = (int) $cnt->fetchColumn();

$stmt = $db->prepare("
    SELECT al.*, a.name AS admin_name
    FROM activity_log al
    LEFT JOIN admin_users a ON a.id = al.admin_id
    WHERE $wql
    ORDER BY al.created_at DESC
    LIMIT ? OFFSET ?
");
$stmt->execute(array_merge($params, [PER_PAGE, $offset]));
$rows = $stmt->fetchAll();

$admins = $db->query('SELECT id, name FROM admin_users ORDER BY name')->fetchAll();
$entity_types = $db->query('SELECT DISTINCT entity_type FROM activity_log WHERE entity_type IS NOT NULL ORDER BY entity_type')->fetchAll(PDO::FETCH_COLUMN);

function audit_qs(array $overrides = []): string
{
    $base = ['q' => $_GET['q'] ?? '', 'admin_id' => $_GET['admin_id'] ?? '', 'entity_type' => $_GET['entity_type'] ?? '', 'from' => $_GET['from'] ?? '', 'to' => $_GET['to'] ?? ''];
    return http_build_query(array_merge($base, $overrides));
}

function audit_icon(string $action): string
{
    return match (true) {
        str_contains($action, 'delete')  => 'fa-trash',
        str_contains($action, 'create')  => 'fa-circle-plus',
        str_contains($action, 'reject')  || str_contains($action, 'decline') => 'fa-circle-xmark',
        str_contains($action, 'approve') => 'fa-circle-check',
        str_contains($action, 'payment') => 'fa-credit-card',
        str_contains($action, 'password') => 'fa-key',
        str_contains($action, 'impersonate') => 'fa-user-secret',
        default => 'fa-pen',
    };
}

require_once '../includes/header.php';
?>

<div class="page-header">
  <div>
    <div class="breadcrumb"><a href="<?php echo APP_URL; ?>/dashboard.php">Dashboard</a><span class="breadcrumb-sep">›</span> Audit Log</div>
    <h1>Audit Log <span style="font-size:15px;font-weight:400;color:var(--text-muted)">(<?php echo number_format($total); ?>)</span></h1>
    <p class="page-subtitle">Every action taken by every admin account — who did what, and when.</p>
  </div>
</div>

<div class="table-wrap">
  <div class="table-toolbar" style="flex-wrap:wrap">
    <form method="GET" class="filter-form">
      <input type="text" name="q" class="search-input" placeholder="Search description or action…"
             value="<?php echo h($search); ?>" />
      <select name="admin_id" class="form-select" style="width:160px">
        <option value="">All Admins</option>
        <?php foreach ($admins as $a): ?>
          <option value="<?php echo $a['id']; ?>" <?php echo $admin_id === (int) $a['id'] ? 'selected' : ''; ?>><?php echo h($a['name']); ?></option>
        <?php endforeach; ?>
      </select>
      <select name="entity_type" class="form-select" style="width:150px">
        <option value="">All Types</option>
        <?php foreach ($entity_types as $et): ?>
          <option value="<?php echo h($et); ?>" <?php echo $entity_type === $et ? 'selected' : ''; ?>><?php echo h(ucfirst(str_replace('_', ' ', $et))); ?></option>
        <?php endforeach; ?>
      </select>
      <input type="date" name="from" class="form-control" style="width:150px" value="<?php echo h($from); ?>" title="From date" />
      <input type="date" name="to" class="form-control" style="width:150px" value="<?php echo h($to); ?>" title="To date" />
      <button type="submit" class="btn btn-ghost btn-sm">Filter</button>
      <?php if ($search || $admin_id || $entity_type || $from || $to): ?>
        <a href="<?php echo APP_URL; ?>/audit-log/" class="btn btn-ghost btn-sm">Clear</a>
      <?php endif; ?>
    </form>
    <span class="table-count">Showing <?php echo count($rows); ?> of <?php echo number_format($total); ?></span>
  </div>

  <div class="table-scroll">
  <table>
    <thead>
      <tr>
        <th>When</th>
        <th>Admin</th>
        <th>Action</th>
        <th>Entity</th>
        <th>Description</th>
        <th>IP</th>
      </tr>
    </thead>
    <tbody>
    <?php if ($rows): foreach ($rows as $r): ?>
      <tr>
        <td style="white-space:nowrap"><span title="<?php echo h(format_datetime($r['created_at'])); ?>"><?php echo h(time_ago($r['created_at'])); ?></span></td>
        <td><?php echo h($r['admin_name'] ?: 'System'); ?></td>
        <td><span class="flex-gap"><i class="fas <?php echo audit_icon($r['action']); ?>" style="color:var(--text-muted);width:14px;text-align:center"></i> <?php echo h(str_replace('_', ' ', $r['action'])); ?></span></td>
        <td>
          <?php if ($r['entity_type']): ?>
            <span class="code-chip"><?php echo h(str_replace('_', ' ', $r['entity_type'])); ?><?php echo $r['entity_id'] ? ' #' . (int) $r['entity_id'] : ''; ?></span>
          <?php else: ?><span class="text-muted">—</span><?php endif; ?>
        </td>
        <td style="max-width:360px"><?php echo h($r['description'] ?: '—'); ?></td>
        <td class="mono" style="font-size:12px;color:var(--text-muted)"><?php echo h($r['ip_address'] ?: '—'); ?></td>
      </tr>
    <?php endforeach; else: ?>
      <tr>
        <td colspan="6">
          <div class="empty-state">
            <i class="fas fa-clock-rotate-left"></i>
            <p>No matching activity<?php echo $search ? " for \"$search\"" : ''; ?>.</p>
          </div>
        </td>
      </tr>
    <?php endif; ?>
    </tbody>
  </table>
  </div>

  <?php echo paginate($total, $page, PER_PAGE, APP_URL . '/audit-log/?' . audit_qs()); ?>
</div>

<?php require_once '../includes/footer.php'; ?>
