<?php
require_once dirname(__DIR__, 2) . '/admin/includes/functions.php';
require_once dirname(__DIR__, 2) . '/admin/includes/Notifier.php';
require_once dirname(__DIR__, 2) . '/admin/includes/SiteSettings.php';
require_once __DIR__ . '/banners.php';

portal_start();
$_c     = current_client();
$_flash = portal_flash_get();
$_cur   = basename($_SERVER['PHP_SELF']);
$_cdir  = basename(dirname($_SERVER['PHP_SELF']));
$_unread  = $_c['id'] ? Notifier::unreadCount('client', (int) $_c['id']) : 0;
$_notifs  = $_c['id'] ? Notifier::listFor('client', (int) $_c['id'], 8) : [];
$_portal_logo = SiteSettings::logoImgTag(36, 150);

$_email_unverified = false;
if ($_c['id']) {
    try {
        $stmt = db()->prepare('SELECT email_verified FROM clients WHERE id = ?');
        $stmt->execute([$_c['id']]);
        $_email_unverified = ((int) $stmt->fetchColumn()) === 0;
    } catch (\Throwable $e) { /* verify columns not migrated yet — treat as verified */ }
}

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
  <title><?php echo isset($page_title) ? htmlspecialchars($page_title, ENT_QUOTES) . ' — ' : ''; ?>Orbit Cloud Client Portal</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" crossorigin="anonymous" />
  <link rel="stylesheet" href="<?php echo PORTAL_URL; ?>/css/portal.css?v=<?php echo @filemtime(__DIR__ . '/../css/portal.css') ?: time(); ?>" />
</head>
<body>

<?php if (!empty($_SESSION['impersonated_by_admin_id'])): ?>
<div style="background:#7c2d12;color:#fff;padding:10px 20px;text-align:center;font-size:13.5px;font-weight:600;display:flex;align-items:center;justify-content:center;gap:14px;flex-wrap:wrap">
  <span><i class="fas fa-user-secret"></i> Viewing as <?php echo htmlspecialchars($_c['name']); ?> — impersonated by <?php echo htmlspecialchars($_SESSION['impersonated_by_admin_name'] ?? 'staff'); ?></span>
  <a href="<?php echo PORTAL_URL; ?>/end-impersonation.php" style="background:#fff;color:#7c2d12;padding:5px 14px;border-radius:6px;text-decoration:none;font-weight:700;font-size:12.5px;white-space:nowrap">Return to Admin</a>
</div>
<?php endif; ?>

<header class="portal-header">
  <div class="ph-inner">
    <a href="<?php echo PORTAL_URL; ?>/dashboard.php" class="portal-logo">
      <?php if ($_portal_logo): ?>
        <?php echo $_portal_logo; ?>
      <?php else: ?>
        <span class="logo-orb">O</span>
        <span>Orbit<strong>Cloud</strong></span>
      <?php endif; ?>
    </a>

    <button class="mobile-toggle" id="mobileToggle" aria-label="Menu">
      <i class="fas fa-bars"></i>
    </button>

    <nav class="portal-nav" id="portalNav">
      <?php pnav(PORTAL_URL . '/dashboard.php', 'Dashboard',  'dashboard.php'); ?>
      <?php pnav(PORTAL_URL . '/services.php',  'Services',   'services.php'); ?>
      <?php pnav(PORTAL_URL . '/domains.php',   'Domains',    'domains.php'); ?>
      <?php pnav(PORTAL_URL . '/order.php',     'Order',      'order.php'); ?>
      <?php pnav(PORTAL_URL . '/invoices/index.php', 'Invoices', 'index.php', 'invoices'); ?>
      <?php pnav(PORTAL_URL . '/tickets/index.php',  'Support',  'index.php', 'tickets'); ?>
      <div class="currency-toggle-mobile" role="group" aria-label="Currency">
        <button type="button" data-cur="USD">USD</button>
        <button type="button" data-cur="KES">KSh</button>
      </div>
    </nav>

    <div class="ph-right">
      <div class="currency-toggle" role="group" aria-label="Currency">
        <button type="button" data-cur="USD">USD</button>
        <button type="button" data-cur="KES">KSh</button>
      </div>
      <div class="notif-menu" id="notifMenu">
        <button type="button" class="notif-bell" id="notifToggle" title="Notifications">
          <i class="fas fa-bell"></i>
          <?php if ($_unread): ?><span class="dot"></span><?php endif; ?>
        </button>
        <div class="notif-dropdown" id="notifDropdown">
          <div class="notif-dd-head">
            <span style="display:flex;align-items:center;gap:8px">
              <?php $_notif_logo = SiteSettings::logoOnNavy(16, 70, '3px 6px'); if ($_notif_logo) echo $_notif_logo; ?>
              Notifications
            </span>
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

  <?php if ($_email_unverified): ?>
    <div class="container">
      <div class="p-alert p-alert-info" style="display:flex;align-items:center;justify-content:space-between;gap:14px;flex-wrap:wrap">
        <span><i class="fas fa-envelope-circle-check"></i> Please verify your email address (<?php echo htmlspecialchars($_c['email']); ?>) — check your inbox for the confirmation link.</span>
        <form method="POST" action="<?php echo PORTAL_URL; ?>/resend-verification.php" style="margin:0">
          <input type="hidden" name="csrf_token" value="<?php echo portal_csrf(); ?>" />
          <button type="submit" class="btn btn-ghost btn-sm" style="border:1px solid var(--border);white-space:nowrap">Resend email</button>
        </form>
      </div>
    </div>
  <?php endif; ?>
