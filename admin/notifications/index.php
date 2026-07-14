<?php
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/Notifier.php';

auth_check();
$page_title = 'Notifications';
$admin_id = (int) current_admin()['id'];
Notifier::ensureTables();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    if (($_POST['action'] ?? '') === 'mark_all_read') {
        Notifier::markAllRead('admin', $admin_id);
        flash_set('success', 'All notifications marked as read.');
    }
    header('Location: ' . APP_URL . '/notifications/');
    exit;
}

// A bell-dropdown click lands here with ?open=ID: mark read, then go to its link (if any)
if (isset($_GET['open'])) {
    $id = (int) $_GET['open'];
    Notifier::markRead($id, 'admin', $admin_id);
    $stmt = db()->prepare('SELECT link FROM notifications WHERE id = ? AND audience = "admin" AND recipient_id = ?');
    $stmt->execute([$id, $admin_id]);
    $link = $stmt->fetchColumn();
    if ($link) { header('Location: ' . $link); exit; }
    header('Location: ' . APP_URL . '/notifications/');
    exit;
}

$page = max(1, (int) ($_GET['page'] ?? 1));
$per  = 30;
$stmt = db()->prepare('SELECT COUNT(*) FROM notifications WHERE audience = "admin" AND recipient_id = ?');
$stmt->execute([$admin_id]);
$total = (int) $stmt->fetchColumn();

$items = Notifier::listFor('admin', $admin_id, $per, ($page - 1) * $per);

$icon_map = [];
foreach (NotificationRegistry::types() as $key => $def) { $icon_map[$key] = $def; }

// ── Email delivery log (all audiences) — surfaces what Notifier::send()
// now records for every notification that includes an email, so a bad
// SMTP config or a bounced address shows up here instead of vanishing.
$email_rows = [];
$email_failed_count = 0;
try {
    $email_rows = db()->query(
        "SELECT * FROM notifications WHERE email_sent IS NOT NULL ORDER BY created_at DESC LIMIT 50"
    )->fetchAll();
    $email_failed_count = (int) db()->query(
        "SELECT COUNT(*) FROM notifications WHERE email_sent = 0 AND created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)"
    )->fetchColumn();
} catch (\Throwable $e) { /* email_sent column not migrated yet */ }

function notif_recipient_label(array $n): string
{
    try {
        if ($n['audience'] === 'admin') {
            $stmt = db()->prepare('SELECT name, email FROM admin_users WHERE id = ?');
        } else {
            $stmt = db()->prepare('SELECT CONCAT(first_name," ",last_name) AS name, email FROM clients WHERE id = ?');
        }
        $stmt->execute([$n['recipient_id']]);
        $r = $stmt->fetch();
        return $r ? trim($r['name']) . ' <' . $r['email'] . '>' : '—';
    } catch (\Throwable $e) {
        return '—';
    }
}

require_once '../includes/header.php';
?>

<div class="content-header">
  <div>
    <h1 class="content-title">Notifications</h1>
    <p class="page-subtitle">Every ticket, billing, and account event that needs your attention — plus whether the email actually sent.</p>
  </div>
  <form method="POST" style="margin:0">
    <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>" />
    <input type="hidden" name="action" value="mark_all_read" />
    <button type="submit" class="btn btn-ghost"><i class="fas fa-check-double"></i> Mark all read</button>
  </form>
</div>

<div class="tabs">
  <a href="#inbox" class="tab-link active" data-tab="inbox">Inbox</a>
  <a href="#delivery" class="tab-link" data-tab="delivery">
    Email Delivery
    <?php if ($email_failed_count): ?><span class="badge badge-danger" style="margin-left:6px"><?php echo $email_failed_count; ?> failed (7d)</span><?php endif; ?>
  </a>
</div>

<div class="tab-pane active" id="pane-inbox">
  <div class="table-wrap">
    <?php if (!$items): ?>
      <div class="empty-state"><i class="fas fa-bell-slash"></i><p>No notifications yet.</p></div>
    <?php else: foreach ($items as $n):
        $def = $icon_map[$n['type']] ?? ['icon' => 'fa-bell', 'color' => '#64748b'];
    ?>
      <a href="?open=<?php echo (int) $n['id']; ?>" style="display:flex;gap:14px;align-items:flex-start;padding:16px 20px;border-bottom:1px solid var(--surface-3);text-decoration:none;<?php echo $n['is_read'] ? '' : 'background:rgba(26,138,69,.04)'; ?>">
        <div class="stat-icon" style="width:38px;height:38px;font-size:14px;background:<?php echo h($def['color']); ?>22;color:<?php echo h($def['color']); ?>;flex-shrink:0">
          <i class="fas <?php echo h($def['icon']); ?>"></i>
        </div>
        <div style="flex:1;min-width:0">
          <div class="fw-700" style="font-size:13.5px;color:var(--navy)"><?php echo h($n['title']); ?></div>
          <div style="font-size:12.5px;color:var(--text-muted);margin-top:2px"><?php echo h($n['message']); ?></div>
        </div>
        <div style="font-size:11.5px;color:var(--text-faint);white-space:nowrap"><?php echo time_ago($n['created_at']); ?></div>
        <?php if (!$n['is_read']): ?><span class="dot dot-green" style="margin-top:5px"></span><?php endif; ?>
      </a>
    <?php endforeach; endif; ?>
    <?php echo paginate($total, $page, $per, '?'); ?>
  </div>
</div>

<div class="tab-pane" id="pane-delivery">
  <div class="table-wrap">
    <div class="table-toolbar">
      <span class="card-title">Recent email attempts</span>
      <span class="table-count">Last 50 · <?php echo $email_failed_count; ?> failed in the last 7 days</span>
    </div>
    <div class="table-scroll">
    <table>
      <thead><tr><th>Type</th><th>To</th><th>Status</th><th>Error</th><th>Sent</th></tr></thead>
      <tbody>
        <?php if (!$email_rows): ?>
          <tr><td colspan="5"><div class="empty-state"><i class="fas fa-envelope-circle-check"></i><p>No email attempts recorded yet.</p></div></td></tr>
        <?php else: foreach ($email_rows as $n): ?>
          <tr>
            <td><span class="code-chip"><?php echo h($n['type']); ?></span></td>
            <td style="font-size:12.5px"><?php echo h(notif_recipient_label($n)); ?></td>
            <td><?php echo $n['email_sent'] ? '<span class="badge badge-success">Sent</span>' : '<span class="badge badge-danger">Failed</span>'; ?></td>
            <td style="font-size:12px;color:var(--text-muted);max-width:320px"><?php echo $n['email_error'] ? h($n['email_error']) : '—'; ?></td>
            <td style="font-size:12px;color:var(--text-muted);white-space:nowrap"><?php echo time_ago($n['created_at']); ?></td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
    </div>
  </div>
  <?php if ($email_failed_count): ?>
    <div class="alert alert-info" style="margin-top:16px"><i class="fas fa-circle-info"></i> If emails keep failing, check your SMTP settings in <a href="<?php echo APP_URL; ?>/integrations/#prov-smtp" style="font-weight:600">Providers</a> — the error column above usually says exactly why (bad credentials, blocked port, DNS failure, etc.).</div>
  <?php endif; ?>
</div>

<script>
function showTab(name) {
  var tab = document.querySelector('.tab-link[data-tab="' + name + '"]');
  var pane = document.getElementById('pane-' + name);
  if (!tab || !pane) return;
  document.querySelectorAll('.tab-link').forEach(function (t) { t.classList.remove('active'); });
  document.querySelectorAll('.tab-pane').forEach(function (p) { p.classList.remove('active'); });
  tab.classList.add('active');
  pane.classList.add('active');
}
document.querySelectorAll('.tab-link').forEach(function (tab) {
  tab.addEventListener('click', function (e) {
    e.preventDefault();
    showTab(tab.dataset.tab);
  });
});
if (location.hash === '#delivery') showTab('delivery');
</script>

<?php require_once '../includes/footer.php'; ?>
