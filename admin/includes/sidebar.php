<?php
$_cur_page = basename($_SERVER['PHP_SELF']);
$_cur_dir  = basename(dirname($_SERVER['PHP_SELF']));

function _nav(string $href, string $icon, string $label, string $dir = '', string $file = ''): void
{
    global $_cur_page, $_cur_dir;
    $active = ($dir && $_cur_dir === $dir) || ($file && $_cur_page === $file) ? ' active' : '';
    printf(
        '<a href="%s" class="nav-link%s"><i class="fas %s nav-icon"></i><span>%s</span></a>',
        $href, $active, $icon, $label
    );
}
?>
<aside class="sidebar" id="sidebar">
  <div class="sidebar-brand">
    <div class="brand-orb">O</div>
    <span class="brand-text">Orbit<strong>Host</strong></span>
  </div>

  <nav class="sidebar-nav">
    <div class="nav-label">Overview</div>
    <?php _nav(APP_URL . '/dashboard.php', 'fa-chart-line', 'Dashboard', '', 'dashboard.php'); ?>

    <div class="nav-label">Management</div>
    <?php _nav(APP_URL . '/clients/',  'fa-users',          'Clients',  'clients'); ?>
    <?php _nav(APP_URL . '/orders/',   'fa-box',            'Orders',   'orders'); ?>
    <?php _nav(APP_URL . '/invoices/', 'fa-file-invoice',   'Invoices', 'invoices'); ?>
    <?php _nav(APP_URL . '/tickets/',  'fa-comments',       'Support Tickets', 'tickets'); ?>
  </nav>

  <div class="sidebar-footer">
    <div class="admin-mini">
      <div class="admin-mini-avatar"><?php echo strtoupper(substr(current_admin()['name'], 0, 1)); ?></div>
      <div class="admin-mini-info">
        <div class="admin-mini-name"><?php echo h(current_admin()['name']); ?></div>
        <div class="admin-mini-role"><?php echo ucfirst(str_replace('_', ' ', current_admin()['role'])); ?></div>
      </div>
    </div>
    <a href="<?php echo APP_URL; ?>/logout.php" class="logout-link" title="Sign Out">
      <i class="fas fa-sign-out-alt"></i>
    </a>
  </div>
</aside>
