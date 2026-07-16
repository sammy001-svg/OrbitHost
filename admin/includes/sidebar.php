<?php
require_once __DIR__ . '/SiteSettings.php';
$_cur_page = basename($_SERVER['PHP_SELF']);
$_cur_dir  = basename(dirname($_SERVER['PHP_SELF']));
$_cur_dir2 = basename(dirname(dirname($_SERVER['PHP_SELF'])));
$_sidebar_logo = SiteSettings::logoImgTag(40, 168);

function _nav(string $href, string $icon, string $label, string $dir = '', string $file = '', string $dir2 = '', string $tag = '', string $tagClass = ''): void
{
    global $_cur_page, $_cur_dir, $_cur_dir2;
    $active = ($dir && $_cur_dir === $dir)
           || ($file && $_cur_page === $file)
           || ($dir2 && $_cur_dir2 === $dir2)
        ? ' active' : '';
    $tagHtml = $tag ? sprintf('<span class="nav-tag %s">%s</span>', $tagClass, $tag) : '';
    printf(
        '<a href="%s" class="nav-link%s"><i class="fas %s nav-icon"></i><span>%s</span>%s</a>',
        $href, $active, $icon, $label, $tagHtml
    );
}
?>
<aside class="sidebar" id="sidebar">
  <a href="<?php echo APP_URL; ?>/dashboard.php" class="sidebar-brand">
    <?php if ($_sidebar_logo): ?>
      <?php echo $_sidebar_logo; ?>
    <?php else: ?>
      <div class="brand-orb">O</div>
      <span class="brand-text">Orbit<strong>Cloud</strong></span>
    <?php endif; ?>
    <span class="brand-badge">Console</span>
  </a>

  <nav class="sidebar-nav">
    <div class="nav-label">Overview</div>
    <?php _nav(APP_URL . '/dashboard.php', 'fa-gauge-high', 'Dashboard', '', 'dashboard.php'); ?>

    <div class="nav-label">Website</div>
    <?php _nav(APP_URL . '/settings/index.php',  'fa-swatchbook', 'Site Settings', 'settings'); ?>
    <?php _nav(APP_URL . '/marketing/index.php', 'fa-bullhorn',   'Portal Banners', 'marketing'); ?>
    <?php if (can('super_admin')): ?>
      <?php _nav(APP_URL . '/staff/index.php', 'fa-users-gear', 'Staff Accounts', 'staff'); ?>
    <?php endif; ?>

    <div class="nav-label">Operations</div>
    <?php _nav(APP_URL . '/services/index.php',  'fa-layer-group',  'Services',  'services'); ?>
    <?php _nav(APP_URL . '/plans/index.php',     'fa-tags',         'Plans & Packages', 'plans'); ?>
    <?php _nav(APP_URL . '/clients/index.php',   'fa-users',        'Clients',   'clients'); ?>
    <?php _nav(APP_URL . '/orders/index.php',    'fa-box',          'Orders',    'orders'); ?>

    <div class="nav-label">Billing</div>
    <?php _nav(APP_URL . '/invoices/index.php',  'fa-file-invoice', 'Invoices',  'invoices'); ?>
    <?php _nav(APP_URL . '/billing/index.php',   'fa-credit-card',  'Payments',  'billing'); ?>

    <div class="nav-label">Support</div>
    <?php _nav(APP_URL . '/tickets/index.php',       'fa-ticket',   'Tickets',        'tickets'); ?>
    <?php _nav(APP_URL . '/chat/index.php',          'fa-comments', 'Live Chat',      'chat'); ?>
    <?php _nav(APP_URL . '/kb/index.php',            'fa-book',     'Knowledge Base', 'kb'); ?>
    <?php _nav(APP_URL . '/notifications/index.php', 'fa-bell',     'Notifications',  'notifications'); ?>
    <?php _nav(APP_URL . '/audit-log/index.php',     'fa-clock-rotate-left', 'Audit Log', 'audit-log'); ?>

    <div class="nav-label">Integrations</div>
    <?php _nav(APP_URL . '/integrations/index.php',         'fa-plug',   'Providers',    'integrations', 'index.php'); ?>
    <?php _nav(APP_URL . '/integrations/whm/index.php',     'fa-server', 'WHM / Servers', 'whm', '', 'integrations'); ?>
    <?php _nav(APP_URL . '/integrations/domains/index.php', 'fa-globe',  'Domains',       'domains', '', 'integrations'); ?>
  </nav>

  <div class="sidebar-footer">
    <a href="<?php echo APP_URL; ?>/profile.php" class="admin-mini" title="My Account" style="text-decoration:none">
      <div class="admin-mini-avatar"><?php echo strtoupper(substr(current_admin()['name'], 0, 1)); ?></div>
      <div class="admin-mini-info">
        <div class="admin-mini-name"><?php echo h(current_admin()['name']); ?></div>
        <div class="admin-mini-role"><?php echo ucfirst(str_replace('_', ' ', current_admin()['role'])); ?></div>
      </div>
    </a>
    <a href="<?php echo APP_URL; ?>/logout.php" class="logout-link" title="Sign Out">
      <i class="fas fa-sign-out-alt"></i>
    </a>
  </div>
</aside>
