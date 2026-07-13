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

require_once '../includes/header.php';
?>

<div class="content-header">
  <div>
    <h1 class="content-title">Notifications</h1>
    <p class="page-subtitle">Every ticket, billing, and account event that needs your attention.</p>
  </div>
  <form method="POST" style="margin:0">
    <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>" />
    <input type="hidden" name="action" value="mark_all_read" />
    <button type="submit" class="btn btn-ghost"><i class="fas fa-check-double"></i> Mark all read</button>
  </form>
</div>

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

<?php require_once '../includes/footer.php'; ?>
