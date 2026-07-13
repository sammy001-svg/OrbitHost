<?php
require_once dirname(__DIR__, 2) . '/admin/includes/functions.php';
require_once dirname(__DIR__, 2) . '/admin/includes/Notifier.php';

portal_start();
$_c     = current_client();
$_flash = portal_flash_get();
$_cur   = basename($_SERVER['PHP_SELF']);
$_cdir  = basename(dirname($_SERVER['PHP_SELF']));
$_unread  = $_c['id'] ? Notifier::unreadCount('client', (int) $_c['id']) : 0;
$_notifs  = $_c['id'] ? Notifier::listFor('client', (int) $_c['id'], 8) : [];

function pnav(string $href, string $label, string $file = '', string $dir = ''): void {
    global $_cur, $_cdir;
    $active = ($file && $_cur === $file) || ($dir && $_cdir === $dir) ? ' active' : '';
    echo "<a href=\"$href\" class=\"nav-link$active\">$label</a>";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title><?php echo isset($page_title) ? htmlspecialchars($page_title, ENT_QUOTES) . ' — ' : ''; ?>OrbitHost Client Portal</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" crossorigin="anonymous" />
  <link rel="stylesheet" href="<?php echo PORTAL_URL; ?>/css/portal.css?v=<?php echo @filemtime(__DIR__ . '/../css/portal.css') ?: time(); ?>" />
</head>
<body>

<header class="portal-header">
  <div class="ph-inner">
    <a href="<?php echo PORTAL_URL; ?>/dashboard.php" class="portal-logo">
      <span class="logo-orb">O</span>
      <span>Orbit<strong>Host</strong></span>
    </a>

    <button class="mobile-toggle" id="mobileToggle" aria-label="Menu">
      <i class="fas fa-bars"></i>
    </button>

    <nav class="portal-nav" id="portalNav">
      <?php pnav(PORTAL_URL . '/dashboard.php', 'Dashboard',  'dashboard.php'); ?>
      <?php pnav(PORTAL_URL . '/services.php',  'Services',   'services.php'); ?>
      <?php pnav(PORTAL_URL . '/domains.php',   'Domains',    'domains.php'); ?>
      <?php pnav(PORTAL_URL . '/invoices/',      'Invoices',   'index.php', 'invoices'); ?>
      <?php pnav(PORTAL_URL . '/tickets/',       'Support',    'index.php', 'tickets'); ?>
    </nav>

    <div class="ph-right">
      <div class="notif-menu" id="notifMenu">
        <button type="button" class="notif-bell" id="notifToggle" title="Notifications">
          <i class="fas fa-bell"></i>
          <?php if ($_unread): ?><span class="dot"></span><?php endif; ?>
        </button>
        <div class="notif-dropdown" id="notifDropdown">
          <div class="notif-dd-head">
            <span>Notifications</span>
            <?php if ($_unread): ?>
              <form method="POST" action="<?php echo PORTAL_URL; ?>/notifications.php" style="margin:0">
                <input type="hidden" name="csrf_token" value="<?php echo portal_csrf(); ?>" />
                <input type="hidden" name="action" value="mark_all_read" />
                <button type="submit" class="notif-markall">Mark all read</button>
              </form>
            <?php endif; ?>
          </div>
          <div class="notif-dd-list">
            <?php if (!$_notifs): ?>
              <div class="notif-empty"><i class="fas fa-bell-slash"></i><p>No notifications yet.</p></div>
            <?php else: foreach ($_notifs as $n): ?>
              <a href="<?php echo PORTAL_URL; ?>/notifications.php?open=<?php echo (int) $n['id']; ?>" class="notif-item<?php echo $n['is_read'] ? '' : ' unread'; ?>">
                <span class="notif-item-title"><?php echo htmlspecialchars($n['title']); ?></span>
                <span class="notif-item-msg"><?php echo htmlspecialchars($n['message']); ?></span>
                <span class="notif-item-time"><?php echo time_ago($n['created_at']); ?></span>
              </a>
            <?php endforeach; endif; ?>
          </div>
          <a href="<?php echo PORTAL_URL; ?>/notifications.php" class="notif-dd-foot">View all notifications</a>
        </div>
      </div>

      <div class="client-menu">
        <button class="client-trigger" id="clientTrigger">
          <span class="client-avatar"><?php echo strtoupper(substr($_c['name'], 0, 1)); ?></span>
          <span class="client-name"><?php echo htmlspecialchars($_c['name']); ?></span>
          <i class="fas fa-chevron-down" style="font-size:10px"></i>
        </button>
        <div class="client-dropdown" id="clientDropdown">
          <div class="dropdown-header">
            <div class="dropdown-name"><?php echo htmlspecialchars($_c['name']); ?></div>
            <div class="dropdown-email"><?php echo htmlspecialchars($_c['email']); ?></div>
          </div>
          <a href="<?php echo PORTAL_URL; ?>/profile.php"><i class="fas fa-user"></i> My Profile</a>
          <a href="<?php echo PORTAL_URL; ?>/notifications.php"><i class="fas fa-bell"></i> Notifications</a>
          <a href="<?php echo PORTAL_URL; ?>/logout.php" style="color:var(--danger)"><i class="fas fa-sign-out-alt"></i> Sign Out</a>
        </div>
      </div>
    </div>
  </div>
</header>

<main class="portal-main">
  <?php if ($_flash): ?>
    <div class="container">
      <div class="p-alert p-alert-<?php echo $_flash['type']; ?>">
        <i class="fas <?php echo $_flash['type'] === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'; ?>"></i>
        <?php echo htmlspecialchars($_flash['msg']); ?>
      </div>
    </div>
  <?php endif; ?>
