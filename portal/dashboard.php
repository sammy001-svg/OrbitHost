<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once dirname(__DIR__) . '/admin/includes/functions.php';

portal_check();
$page_title = 'Dashboard';
$c   = current_client();
$cid = $c['id'];
$db  = db();

$cid = (int) $cid;

// Active services = legacy orders + provisioned services (client_services)
$active_orders = (int) $db->query("SELECT COUNT(*) FROM orders WHERE client_id=$cid AND status='active'")->fetchColumn();
$provisioned = [];
try {
    $stmt = $db->prepare('SELECT * FROM client_services WHERE client_id = ? ORDER BY status = "active" DESC, created_at DESC LIMIT 6');
    $stmt->execute([$cid]);
    $provisioned = $stmt->fetchAll();
    $active_orders += (int) $db->query("SELECT COUNT(*) FROM client_services WHERE client_id=$cid AND status='active'")->fetchColumn();
} catch (\Throwable $e) { /* client_services not migrated yet */ }

$domains_count = 0;
try {
    $domains_count = (int) $db->query("SELECT COUNT(*) FROM domain_registrations WHERE client_id=$cid AND status IN ('active','pending')")->fetchColumn();
} catch (\Throwable $e) { /* table missing */ }

$open_tickets    = (int) $db->query("SELECT COUNT(*) FROM tickets WHERE client_id=$cid AND status IN ('open','pending')")->fetchColumn();
$unpaid_invoices = (int) $db->query("SELECT COUNT(*) FROM invoices WHERE client_id=$cid AND status IN ('sent','overdue')")->fetchColumn();
$total_spent     = (float)$db->query("SELECT COALESCE(SUM(total),0) FROM invoices WHERE client_id=$cid AND status='paid'")->fetchColumn();

// Recent items
$recent_orders = $db->query("
    SELECT o.*, s.name svc_name FROM orders o
    LEFT JOIN services s ON s.id=o.service_id
    WHERE o.client_id=$cid ORDER BY o.created_at DESC LIMIT 4
")->fetchAll();

$recent_invoices = $db->query("
    SELECT * FROM invoices WHERE client_id=$cid ORDER BY created_at DESC LIMIT 4
")->fetchAll();

$recent_tickets = $db->query("
    SELECT * FROM tickets WHERE client_id=$cid ORDER BY updated_at DESC LIMIT 4
")->fetchAll();

// Next due orders
$due_soon = $db->query("
    SELECT o.*, s.name svc_name FROM orders o
    LEFT JOIN services s ON s.id=o.service_id
    WHERE o.client_id=$cid AND o.status='active' AND o.next_due <= DATE_ADD(NOW(), INTERVAL 7 DAY)
    ORDER BY o.next_due ASC LIMIT 3
")->fetchAll();

// Marketing banners (admin-managed; table may not exist yet)
$hero_banners = $side_banners = [];
try {
    foreach ($db->query("SELECT * FROM portal_banners WHERE is_active = 1 ORDER BY sort_order, id")->fetchAll() as $b) {
        if ($b['placement'] === 'side') $side_banners[] = $b; else $hero_banners[] = $b;
    }
} catch (\Throwable $e) { /* none configured */ }
$site_base = preg_replace('#/portal/?$#', '', PORTAL_URL);
$banner_img = function (?string $u) use ($site_base): string {
    if (!$u) return '';
    return preg_match('#^https?://#i', $u) ? $u : $site_base . '/' . ltrim($u, '/');
};

require_once __DIR__ . '/includes/header.php';
?>

<div class="page-hero">
  <div class="container">
    <div>
      <h1>Welcome back, <?php echo htmlspecialchars($c['name']); ?> 👋</h1>
      <p>Here's an overview of your account</p>
    </div>
    <a href="<?php echo PORTAL_URL; ?>/tickets/add.php" class="btn btn-white">
      <i class="fas fa-plus"></i> Open Support Ticket
    </a>
  </div>
</div>

<div class="page-body">
<div class="container">

  <?php if ($due_soon): ?>
  <div class="p-alert p-alert-info" style="margin-bottom:20px">
    <i class="fas fa-calendar-alt"></i>
    <strong><?php echo count($due_soon); ?> service<?php echo count($due_soon)>1?'s':''; ?> renewing within 7 days</strong> —
    <?php echo implode(', ', array_column($due_soon, 'svc_name')); ?>
  </div>
  <?php endif; ?>

  <!-- Stats -->
  <div class="p-stat-grid">
    <a href="<?php echo PORTAL_URL; ?>/services.php" class="p-stat">
      <div class="p-stat-icon green"><i class="fas fa-box"></i></div>
      <div><div class="p-stat-label">Active Services</div><div class="p-stat-value"><?php echo $active_orders; ?></div></div>
    </a>
    <a href="<?php echo PORTAL_URL; ?>/invoices/" class="p-stat">
      <div class="p-stat-icon <?php echo $unpaid_invoices ? 'orange' : 'navy'; ?>"><i class="fas fa-file-invoice"></i></div>
      <div>
        <div class="p-stat-label">Unpaid Invoices</div>
        <div class="p-stat-value"><?php echo $unpaid_invoices; ?></div>
      </div>
    </a>
    <a href="<?php echo PORTAL_URL; ?>/tickets/" class="p-stat">
      <div class="p-stat-icon <?php echo $open_tickets ? 'orange' : 'navy'; ?>"><i class="fas fa-comments"></i></div>
      <div><div class="p-stat-label">Open Tickets</div><div class="p-stat-value"><?php echo $open_tickets; ?></div></div>
    </a>
    <a href="<?php echo PORTAL_URL; ?>/domains.php" class="p-stat">
      <div class="p-stat-icon navy"><i class="fas fa-globe"></i></div>
      <div><div class="p-stat-label">Domains</div><div class="p-stat-value"><?php echo $domains_count; ?></div></div>
    </a>
  </div>

  <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px">

    <!-- Services -->
    <div class="p-table-wrap">
      <div class="p-table-head">
        <div class="p-table-title">Active Services</div>
        <a href="<?php echo PORTAL_URL; ?>/services.php" class="btn btn-ghost btn-sm">View All</a>
      </div>
      <table>
        <thead><tr><th>Service</th><th>Domain</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($provisioned as $svc):
            $cp = ($svc['provider_key'] ?? '') === 'whm' ? ($svc['username'] ?: $svc['remote_id']) : null; ?>
          <tr>
            <td><strong><?php echo htmlspecialchars($svc['label']); ?></strong></td>
            <td style="font-size:12.5px"><?php echo htmlspecialchars($svc['domain'] ?: '—'); ?></td>
            <td><?php echo badge($svc['status']); ?></td>
            <td style="text-align:right">
              <?php if ($cp && $svc['status'] === 'active'): ?>
                <a href="<?php echo PORTAL_URL; ?>/cpanel-sso.php?user=<?php echo urlencode($cp); ?>" class="btn btn-primary btn-sm" target="_blank" rel="noopener"><i class="fas fa-right-to-bracket"></i> cPanel</a>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if ($recent_orders): foreach ($recent_orders as $o): ?>
          <tr>
            <td><strong><?php echo htmlspecialchars($o['svc_name'] ?? $o['service_name'] ?? '—'); ?></strong></td>
            <td style="font-size:12.5px"><?php echo htmlspecialchars($o['domain_name'] ?? $o['domain'] ?? '—'); ?></td>
            <td><?php echo badge($o['status']); ?></td>
            <td style="text-align:right;font-size:12px;color:var(--text-muted)"><?php echo format_date($o['next_due']); ?></td>
          </tr>
        <?php endforeach; endif; ?>
        <?php if (!$provisioned && !$recent_orders): ?>
          <tr><td colspan="4"><div class="empty-state"><i class="fas fa-box"></i><p>No active services yet.</p></div></td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>

    <!-- Invoices -->
    <div class="p-table-wrap">
      <div class="p-table-head">
        <div class="p-table-title">Recent Invoices</div>
        <a href="<?php echo PORTAL_URL; ?>/invoices/" class="btn btn-ghost btn-sm">View All</a>
      </div>
      <table>
        <thead><tr><th>Invoice #</th><th>Amount</th><th>Due</th><th>Status</th></tr></thead>
        <tbody>
        <?php if ($recent_invoices): foreach ($recent_invoices as $inv): ?>
          <tr>
            <td><a href="<?php echo PORTAL_URL; ?>/invoices/view.php?id=<?php echo $inv['id']; ?>" style="color:var(--navy);font-weight:600"><?php echo htmlspecialchars($inv['invoice_number']); ?></a></td>
            <td><?php echo format_money($inv['total']); ?></td>
            <td><?php echo format_date($inv['due_date']); ?></td>
            <td><?php echo badge($inv['status']); ?></td>
          </tr>
        <?php endforeach; else: ?>
          <tr><td colspan="4"><div class="empty-state"><i class="fas fa-file-invoice"></i><p>No invoices yet.</p></div></td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>

  </div>

  <!-- Support tickets -->
  <div class="p-table-wrap" style="margin-top:20px">
    <div class="p-table-head">
      <div class="p-table-title">Recent Support Tickets</div>
      <div style="display:flex;gap:8px">
        <a href="<?php echo PORTAL_URL; ?>/tickets/add.php" class="btn btn-primary btn-sm"><i class="fas fa-plus"></i> New Ticket</a>
        <a href="<?php echo PORTAL_URL; ?>/tickets/" class="btn btn-ghost btn-sm">View All</a>
      </div>
    </div>
    <table>
      <thead><tr><th>Ticket #</th><th>Subject</th><th>Priority</th><th>Status</th><th>Updated</th></tr></thead>
      <tbody>
      <?php if ($recent_tickets): foreach ($recent_tickets as $t): ?>
        <tr>
          <td><a href="<?php echo PORTAL_URL; ?>/tickets/view.php?id=<?php echo $t['id']; ?>" style="color:var(--navy);font-weight:700;font-size:12px"><?php echo htmlspecialchars($t['ticket_number']); ?></a></td>
          <td><a href="<?php echo PORTAL_URL; ?>/tickets/view.php?id=<?php echo $t['id']; ?>" style="color:var(--text)"><?php echo htmlspecialchars(mb_strimwidth($t['subject'],0,48,'…')); ?></a></td>
          <td><?php echo badge($t['priority']); ?></td>
          <td><?php echo badge($t['status']); ?></td>
          <td><?php echo time_ago($t['updated_at']); ?></td>
        </tr>
      <?php endforeach; else: ?>
        <tr><td colspan="5"><div class="empty-state"><i class="fas fa-comments"></i><h3>All quiet!</h3><p>No support tickets yet.</p></div></td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>

</div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
