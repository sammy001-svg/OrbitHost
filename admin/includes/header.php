<?php
auth_start();
require_once __DIR__ . '/Notifier.php';
$_flash = flash_get();
$_admin = current_admin();
$_unread = Notifier::unreadCount('admin', (int) $_admin['id']);
$_notifs = Notifier::listFor('admin', (int) $_admin['id'], 8);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title><?php echo isset($page_title) ? h($page_title) . ' — ' : ''; ?><?php echo APP_NAME; ?></title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" crossorigin="anonymous" />
  <link rel="stylesheet" href="<?php echo APP_URL; ?>/css/admin.css?v=<?php echo @filemtime(__DIR__ . '/../css/admin.css') ?: time(); ?>" />
</head>
<body>

<?php require_once __DIR__ . '/sidebar.php'; ?>

<div class="main-wrap">

  <header class="topbar">
    <button class="sidebar-toggle" id="sidebarToggle" aria-label="Toggle sidebar">
      <i class="fas fa-bars"></i>
    </button>
    <div class="topbar-title"><?php echo isset($page_title) ? h($page_title) : ''; ?></div>
    <div class="topbar-right">
      <a href="<?php echo APP_URL; ?>/tickets/" class="topbar-icon" title="Support Tickets">
        <i class="fas fa-comments"></i>
      </a>

      <div class="notif-menu" id="notifMenu">
        <button type="button" class="topbar-icon" id="notifToggle" title="Notifications">
          <i class="fas fa-bell"></i>
          <?php if ($_unread): ?><span class="dot"></span><?php endif; ?>
        </button>
        <div class="notif-dropdown" id="notifDropdown">
          <div class="notif-dd-head">
            <span>Notifications</span>
            <?php if ($_unread): ?>
              <form method="POST" action="<?php echo APP_URL; ?>/notifications/index.php" style="margin:0">
                <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>" />
                <input type="hidden" name="action" value="mark_all_read" />
                <button type="submit" class="notif-markall">Mark all read</button>
              </form>
            <?php endif; ?>
          </div>
          <div class="notif-dd-list">
            <?php if (!$_notifs): ?>
              <div class="notif-empty"><i class="fas fa-bell-slash"></i><p>No notifications yet.</p></div>
            <?php else: foreach ($_notifs as $n): ?>
              <a href="<?php echo APP_URL; ?>/notifications/index.php?open=<?php echo (int) $n['id']; ?>" class="notif-item<?php echo $n['is_read'] ? '' : ' unread'; ?>">
                <span class="notif-item-title"><?php echo h($n['title']); ?></span>
                <span class="notif-item-msg"><?php echo h($n['message']); ?></span>
                <span class="notif-item-time"><?php echo time_ago($n['created_at']); ?></span>
              </a>
            <?php endforeach; endif; ?>
          </div>
          <a href="<?php echo APP_URL; ?>/notifications/" class="notif-dd-foot">View all notifications</a>
        </div>
      </div>

      <div class="topbar-divider"></div>
      <div class="topbar-user">
        <div class="topbar-avatar"><?php echo strtoupper(substr($_admin['name'], 0, 1)); ?></div>
        <div>
          <div class="topbar-name"><?php echo h($_admin['name']); ?></div>
          <div class="topbar-role"><?php echo ucfirst(str_replace('_', ' ', $_admin['role'])); ?></div>
        </div>
      </div>
    </div>
  </header>

  <main class="page-content">
    <?php if ($_flash): ?>
      <div class="alert alert-<?php echo h($_flash['type']); ?>" role="alert">
        <i class="fas <?php echo $_flash['type'] === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'; ?>"></i>
        <?php echo h($_flash['message']); ?>
      </div>
    <?php endif; ?>
