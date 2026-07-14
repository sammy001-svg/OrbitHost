<?php
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

auth_check();
$page_title = 'Services';

$pending_changes = 0;
try {
    $pending_changes = (int) db()->query("SELECT COUNT(*) FROM service_change_requests WHERE status = 'pending'")->fetchColumn();
} catch (\Throwable $e) { /* table not migrated yet */ }

$status_filter = $_GET['status'] ?? '';
$search        = trim($_GET['q'] ?? '');
$page          = max(1, (int)($_GET['page'] ?? 1));
$per           = defined('PER_PAGE') ? PER_PAGE : 20;

$where = [];
$args  = [];
if ($status_filter) { $where[] = 'cs.status = ?'; $args[] = $status_filter; }
if ($search) {
    $where[] = '(cs.label LIKE ? OR cs.domain LIKE ? OR c.first_name LIKE ? OR c.last_name LIKE ? OR c.email LIKE ?)';
    $like = "%$search%";
    array_push($args, $like, $like, $like, $like, $like);
}
$wsql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$stmt = db()->prepare("SELECT COUNT(*) FROM client_services cs LEFT JOIN clients c ON c.id = cs.client_id $wsql");
$stmt->execute($args);
$total = (int) $stmt->fetchColumn();

$offset = ($page - 1) * $per;
$sql = "SELECT cs.*, c.first_name, c.last_name, c.email
        FROM client_services cs
        LEFT JOIN clients c ON c.id = cs.client_id
        $wsql
        ORDER BY cs.created_at DESC
        LIMIT $per OFFSET $offset";
$stmt = db()->prepare($sql);
$stmt->execute($args);
$rows = $stmt->fetchAll();

// Status counts for the filter chips
$countsRaw = db()->query("SELECT status, COUNT(*) c FROM client_services GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);
$svcCount = fn($s) => (int)($countsRaw[$s] ?? 0);

function svc_status_badge(string $s): string
{
    $map = [
        'active'       => ['badge-success', 'Active'],
        'pending'      => ['badge-warning', 'Pending'],
        'provisioning' => ['badge-primary', 'Provisioning'],
        'suspended'    => ['badge-danger',  'Suspended'],
        'terminated'   => ['badge-secondary','Terminated'],
        'failed'       => ['badge-danger',  'Failed'],
        'cancelled'    => ['badge-secondary','Cancelled'],
    ];
    [$cls, $lbl] = $map[$s] ?? ['badge-secondary', ucfirst($s)];
    return '<span class="badge ' . $cls . '">' . $lbl . '</span>';
}

require_once '../includes/header.php';
?>

<div class="content-header">
  <div>
    <h1 class="content-title">Services</h1>
    <p class="page-subtitle">Provisioned hosting, VPS, reseller and other client services across all providers.</p>
  </div>
  <div class="page-header-actions">
    <a href="<?php echo APP_URL; ?>/services/change-requests.php" class="btn btn-ghost">
      <i class="fas fa-arrows-up-down"></i> Change Requests
      <?php if ($pending_changes): ?><span class="badge badge-warning" style="margin-left:6px"><?php echo $pending_changes; ?></span><?php endif; ?>
    </a>
    <a href="<?php echo APP_URL; ?>/services/create.php" class="btn btn-primary"><i class="fas fa-plus"></i> Create Service</a>
  </div>
</div>

<div class="segmented" style="margin-bottom:18px">
  <a href="?" class="<?php echo $status_filter === '' ? 'active' : ''; ?>">All <span class="text-muted"><?php echo array_sum($countsRaw ?: []); ?></span></a>
  <a href="?status=active"    class="<?php echo $status_filter === 'active' ? 'active' : ''; ?>">Active <?php echo $svcCount('active'); ?></a>
  <a href="?status=pending"   class="<?php echo $status_filter === 'pending' ? 'active' : ''; ?>">Pending <?php echo $svcCount('pending'); ?></a>
  <a href="?status=suspended" class="<?php echo $status_filter === 'suspended' ? 'active' : ''; ?>">Suspended <?php echo $svcCount('suspended'); ?></a>
  <a href="?status=terminated" class="<?php echo $status_filter === 'terminated' ? 'active' : ''; ?>">Terminated <?php echo $svcCount('terminated'); ?></a>
</div>

<div class="table-wrap">
  <div class="table-toolbar">
    <form class="filter-form" method="GET">
      <?php if ($status_filter): ?><input type="hidden" name="status" value="<?php echo h($status_filter); ?>" /><?php endif; ?>
      <input type="text" name="q" class="search-input" placeholder="Search services, domains, clients…" value="<?php echo h($search); ?>" />
      <button type="submit" class="btn btn-ghost btn-sm">Search</button>
    </form>
    <span class="table-count"><?php echo number_format($total); ?> service<?php echo $total === 1 ? '' : 's'; ?></span>
  </div>

  <div class="table-scroll">
  <table>
    <thead>
      <tr>
        <th>Service</th>
        <th>Client</th>
        <th>Provider</th>
        <th>Disk usage</th>
        <th>Status</th>
        <th>Next due</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="7">
          <div class="empty-state">
            <i class="fas fa-layer-group"></i>
            <p>No services yet. <a href="<?php echo APP_URL; ?>/services/create.php" style="color:var(--green);font-weight:600">Create your first service</a>.</p>
          </div>
        </td></tr>
      <?php else: foreach ($rows as $r):
        $pct = $r['disk_limit_mb'] > 0 ? min(100, round($r['disk_used_mb'] / $r['disk_limit_mb'] * 100)) : 0;
        $mcls = $pct > 90 ? 'crit' : ($pct > 70 ? 'warn' : '');
      ?>
        <tr onclick="location.href='<?php echo APP_URL; ?>/services/view.php?id=<?php echo $r['id']; ?>'" style="cursor:pointer">
          <td>
            <div class="td-name"><?php echo h($r['label']); ?></div>
            <div class="td-sub"><?php echo $r['domain'] ? h($r['domain']) : ucfirst($r['category']); ?></div>
          </td>
          <td>
            <?php if ($r['first_name']): ?>
              <div><?php echo h($r['first_name'] . ' ' . $r['last_name']); ?></div>
              <div class="td-sub"><?php echo h($r['email']); ?></div>
            <?php else: ?><span class="text-muted">—</span><?php endif; ?>
          </td>
          <td>
            <?php if ($r['provider_key']): ?>
              <span class="code-chip"><?php echo h($r['provider_key']); ?></span>
              <?php if ($r['username']): ?><div class="td-sub mono"><?php echo h($r['username']); ?></div><?php endif; ?>
            <?php else: ?><span class="text-muted">Manual</span><?php endif; ?>
          </td>
          <td style="min-width:120px">
            <?php if ($r['disk_limit_mb'] > 0): ?>
              <div class="meter-label"><span><?php echo number_format($r['disk_used_mb']); ?> MB</span><span><?php echo $pct; ?>%</span></div>
              <div class="meter <?php echo $mcls; ?>"><span style="width:<?php echo $pct; ?>%"></span></div>
            <?php else: ?><span class="text-muted" style="font-size:12px">—</span><?php endif; ?>
          </td>
          <td><?php echo svc_status_badge($r['status']); ?></td>
          <td style="font-size:12.5px"><?php echo $r['next_due_date'] ? format_date($r['next_due_date']) : '<span class="text-muted">—</span>'; ?></td>
          <td><i class="fas fa-chevron-right text-muted" style="font-size:11px"></i></td>
        </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>
  </div>
  <?php echo paginate($total, $page, $per, '?' . http_build_query(array_filter(['status' => $status_filter, 'q' => $search]))); ?>
</div>

<?php require_once '../includes/footer.php'; ?>
