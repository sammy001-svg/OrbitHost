<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once dirname(__DIR__) . '/admin/includes/functions.php';
require_once dirname(__DIR__) . '/admin/includes/Notifier.php';
require_once dirname(__DIR__) . '/admin/includes/NotificationRegistry.php';

portal_check();
$page_title = 'Notifications';
$client_id = (int) current_client()['id'];
Notifier::ensureTables();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    portal_csrf_verify();
    if (($_POST['action'] ?? '') === 'mark_all_read') {
        Notifier::markAllRead('client', $client_id);
        portal_flash_set('success', 'All notifications marked as read.');
    }
    header('Location: ' . PORTAL_URL . '/notifications.php');
    exit;
}

if (isset($_GET['open'])) {
    $id = (int) $_GET['open'];
    Notifier::markRead($id, 'client', $client_id);
    $stmt = db()->prepare('SELECT link FROM notifications WHERE id = ? AND audience = "client" AND recipient_id = ?');
    $stmt->execute([$id, $client_id]);
    $link = $stmt->fetchColumn();
    if ($link) {
        // Some notifications (older rows, or a bare-directory link that
        // depended on Apache's DirectoryIndex resolving it) may point at a
        // directory with no filename — normalize to its index.php so the
        // redirect always lands on a real page instead of risking a 404.
        if (substr($link, -1) === '/') {
            $link = rtrim($link, '/') . '/index.php';
        }
        header('Location: ' . $link);
        exit;
    }
    header('Location: ' . PORTAL_URL . '/notifications.php');
    exit;
}

$page = max(1, (int) ($_GET['page'] ?? 1));
$per  = 30;
$stmt = db()->prepare('SELECT COUNT(*) FROM notifications WHERE audience = "client" AND recipient_id = ?');
$stmt->execute([$client_id]);
$total = (int) $stmt->fetchColumn();

$items = Notifier::listFor('client', $client_id, $per, ($page - 1) * $per);
$icon_map = NotificationRegistry::types();

require_once __DIR__ . '/includes/header.php';
?>

<div class="page-hero">
  <div class="container">
    <div><h1>Notifications</h1><p>Updates about your services, invoices and support tickets</p></div>
    <form method="POST" style="margin:0">
      <input type="hidden" name="csrf_token" value="<?php echo portal_csrf(); ?>" />
      <input type="hidden" name="action" value="mark_all_read" />
      <button type="submit" class="btn btn-white"><i class="fas fa-check-double"></i> Mark all read</button>
    </form>
  </div>
</div>

<div class="page-body">
<div class="container" style="max-width:760px">

  <div class="p-table-wrap">
    <?php if (!$items): ?>
      <div class="empty-state"><i class="fas fa-bell-slash"></i><p>No notifications yet.</p></div>
    <?php else: foreach ($items as $n):
        $def = $icon_map[$n['type']] ?? ['icon' => 'fa-bell', 'color' => '#64748b'];
    ?>
      <a href="?open=<?php echo (int) $n['id']; ?>" style="display:flex;gap:14px;align-items:flex-start;padding:16px 20px;border-bottom:1px solid #f1f5f9;text-decoration:none;<?php echo $n['is_read'] ? '' : 'background:rgba(26,138,69,.04)'; ?>">
        <div class="p-stat-icon" style="width:38px;height:38px;font-size:14px;background:<?php echo htmlspecialchars($def['color']); ?>22;color:<?php echo htmlspecialchars($def['color']); ?>;flex-shrink:0">
          <i class="fas <?php echo htmlspecialchars($def['icon']); ?>"></i>
        </div>
        <div style="flex:1;min-width:0">
          <div style="font-weight:700;font-size:13.5px;color:var(--navy)"><?php echo htmlspecialchars($n['title']); ?></div>
          <div style="font-size:12.5px;color:var(--text-muted);margin-top:2px"><?php echo htmlspecialchars($n['message']); ?></div>
        </div>
        <div style="font-size:11.5px;color:#94a3b8;white-space:nowrap"><?php echo time_ago($n['created_at']); ?></div>
      </a>
    <?php endforeach; endif; ?>
    <?php echo paginate($total, $page, $per, '?'); ?>
  </div>

</div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
