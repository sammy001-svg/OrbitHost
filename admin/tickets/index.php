<?php
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

auth_check();
$page_title = 'Support Tickets';
$db = db();

$search     = trim($_GET['q']          ?? '');
$status     = trim($_GET['status']     ?? '');
$priority   = trim($_GET['priority']   ?? '');
$department = trim($_GET['department'] ?? '');
$page       = max(1, (int)($_GET['page'] ?? 1));
$offset     = ($page - 1) * PER_PAGE;

$where  = ['1=1'];
$params = [];

if ($search) {
    $where[]  = '(t.subject LIKE ? OR t.ticket_number LIKE ? OR c.first_name LIKE ? OR c.email LIKE ?)';
    $like     = "%$search%";
    array_push($params, $like, $like, $like, $like);
}
if ($status)     { $where[] = 't.status = ?';     $params[] = $status; }
if ($priority)   { $where[] = 't.priority = ?';   $params[] = $priority; }
if ($department) { $where[] = 't.department = ?'; $params[] = $department; }

$wql = implode(' AND ', $where);

$cnt = $db->prepare("SELECT COUNT(*) FROM tickets t LEFT JOIN clients c ON c.id=t.client_id WHERE $wql");
$cnt->execute($params);
$total = (int) $cnt->fetchColumn();

$stmt = $db->prepare("
    SELECT t.*,
           CONCAT(COALESCE(c.first_name,''),' ',COALESCE(c.last_name,'')) AS client_name,
           c.email AS client_email,
           au.name AS assigned_name
    FROM tickets t
    LEFT JOIN clients c    ON c.id  = t.client_id
    LEFT JOIN admin_users au ON au.id = t.assigned_to
    WHERE $wql
    ORDER BY
      FIELD(t.priority,'urgent','high','medium','low'),
      t.updated_at DESC
    LIMIT ? OFFSET ?
");
$stmt->execute(array_merge($params, [PER_PAGE, $offset]));
$tickets = $stmt->fetchAll();

// Counts for status bar
$counts = $db->query("SELECT status, COUNT(*) n FROM tickets GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);

require_once '../includes/header.php';
?>

<div class="page-header">
  <div>
    <div class="breadcrumb"><a href="<?php echo APP_URL; ?>/dashboard.php">Dashboard</a><span class="breadcrumb-sep">›</span> Support Tickets</div>
    <h1>Support Tickets <span style="font-size:15px;font-weight:400;color:var(--text-muted)">(<?php echo number_format($total); ?>)</span></h1>
  </div>
  <a href="<?php echo APP_URL; ?>/tickets/add.php" class="btn btn-primary">
    <i class="fas fa-plus"></i> New Ticket
  </a>
</div>

<!-- Quick status filters -->
<div style="display:flex;gap:8px;margin-bottom:16px;flex-wrap:wrap">
  <?php
  $qs = ['' => 'All', 'open' => 'Open', 'pending' => 'Pending', 'answered' => 'Answered', 'closed' => 'Closed'];
  foreach ($qs as $val => $lbl):
    $active = $status === $val ? 'btn-navy' : 'btn-ghost';
    $cnt_n  = $val ? ($counts[$val] ?? 0) : array_sum($counts);
  ?>
    <a href="<?php echo APP_URL; ?>/tickets/?status=<?php echo urlencode($val); ?>" class="btn btn-sm <?php echo $active; ?>">
      <?php echo $lbl; ?> <span style="opacity:.7;font-weight:400">(<?php echo $cnt_n; ?>)</span>
    </a>
  <?php endforeach; ?>
</div>

<div class="table-wrap">
  <div class="table-toolbar">
    <form method="GET" class="filter-form">
      <input type="hidden" name="status" value="<?php echo h($status); ?>" />
      <input type="text" name="q" class="search-input"
             placeholder="Search subject, ticket#, client…"
             value="<?php echo h($search); ?>" />
      <select name="priority" class="form-select" style="width:120px">
        <option value="">Priority</option>
        <?php foreach (['low','medium','high','urgent'] as $p): ?>
          <option value="<?php echo $p; ?>" <?php echo $priority===$p?'selected':''; ?>><?php echo ucfirst($p); ?></option>
        <?php endforeach; ?>
      </select>
      <select name="department" class="form-select" style="width:130px">
        <option value="">Department</option>
        <?php foreach (['sales','billing','technical','general'] as $d): ?>
          <option value="<?php echo $d; ?>" <?php echo $department===$d?'selected':''; ?>><?php echo ucfirst($d); ?></option>
        <?php endforeach; ?>
      </select>
      <button type="submit" class="btn btn-ghost btn-sm">Filter</button>
      <?php if ($search||$priority||$department): ?>
        <a href="<?php echo APP_URL; ?>/tickets/?status=<?php echo urlencode($status); ?>" class="btn btn-ghost btn-sm">Clear</a>
      <?php endif; ?>
    </form>
    <span class="table-count">Showing <?php echo count($tickets); ?> of <?php echo number_format($total); ?></span>
  </div>

  <table>
    <thead>
      <tr>
        <th>Ticket #</th>
        <th>Subject</th>
        <th>Client</th>
        <th>Dept</th>
        <th>Priority</th>
        <th>Status</th>
        <th>Assigned</th>
        <th>Updated</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
    <?php if ($tickets): foreach ($tickets as $t): ?>
      <tr>
        <td>
          <a href="<?php echo APP_URL; ?>/tickets/view.php?id=<?php echo $t['id']; ?>"
             style="font-weight:700;color:var(--navy);text-decoration:none;font-size:12px">
            <?php echo h($t['ticket_number']); ?>
          </a>
        </td>
        <td>
          <a href="<?php echo APP_URL; ?>/tickets/view.php?id=<?php echo $t['id']; ?>"
             style="color:var(--text);text-decoration:none;font-weight:500">
            <?php echo h(mb_strimwidth($t['subject'], 0, 52, '…')); ?>
          </a>
        </td>
        <td>
          <?php if ($t['client_id']): ?>
            <a href="<?php echo APP_URL; ?>/clients/view.php?id=<?php echo $t['client_id']; ?>" style="color:var(--navy);text-decoration:none">
              <?php echo h(trim($t['client_name'])); ?>
            </a>
          <?php else: ?>
            <span class="text-muted">Guest</span>
          <?php endif; ?>
        </td>
        <td><?php echo ucfirst($t['department']); ?></td>
        <td><?php echo badge($t['priority']); ?></td>
        <td><?php echo badge($t['status']); ?></td>
        <td><?php echo $t['assigned_name'] ? h($t['assigned_name']) : '<span class="text-muted">—</span>'; ?></td>
        <td><span title="<?php echo h($t['updated_at']); ?>"><?php echo time_ago($t['updated_at']); ?></span></td>
        <td>
          <a href="<?php echo APP_URL; ?>/tickets/view.php?id=<?php echo $t['id']; ?>" class="action-link view">Open</a>
        </td>
      </tr>
    <?php endforeach; else: ?>
      <tr><td colspan="9"><div class="empty-state"><i class="fas fa-comments"></i><p>No tickets found.</p></div></td></tr>
    <?php endif; ?>
    </tbody>
  </table>

  <?php echo paginate($total, $page, PER_PAGE, APP_URL . '/tickets/?status=' . urlencode($status) . '&q=' . urlencode($search) . '&priority=' . urlencode($priority)); ?>
</div>

<?php require_once '../includes/footer.php'; ?>
