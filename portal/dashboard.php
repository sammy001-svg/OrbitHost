<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once dirname(__DIR__) . '/admin/includes/functions.php';

portal_check();
$page_title = 'Dashboard';
$c   = current_client();
$cid = $c['id'];
$db  = db();

$active_orders   = (int) $db->prepare('SELECT COUNT(*) FROM orders WHERE client_id=? AND status="active"')->execute([$cid]) ? $db->query("SELECT COUNT(*) FROM orders WHERE client_id=$cid AND status='active'")->fetchColumn() : 0;
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
    <div class="p-stat">
      <div class="p-stat-icon green"><i class="fas fa-dollar-sign"></i></div>
      <div><div class="p-stat-label">Total Spent</div><div class="p-stat-value"><?php echo format_money($total_spent); ?></div></div>
    </div>
  </div>

  <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px">

    <!-- Services -->
    <div class="p-table-wrap">
      <div class="p-table-head">
        <div class="p-table-title">Active Services</div>
        <a href="<?php echo PORTAL_URL; ?>/services.php" class="btn btn-ghost btn-sm">View All</a>
      </div>
      <table>
        <thead><tr><th>Service</th><th>Domain</th><th>Next Due</th><th>Status</th></tr></thead>
        <tbody>
        <?php if ($recent_orders): foreach ($recent_orders as $o): ?>
          <tr>
            <td><strong><?php echo htmlspecialchars($o['svc_name'] ?? $o['service_name'] ?? '—'); ?></strong></td>
            <td><?php echo htmlspecialchars($o['domain'] ?: '—'); ?></td>
            <td><?php echo format_date($o['next_due']); ?></td>
            <td><?php echo badge($o['status']); ?></td>
          </tr>
        <?php endforeach; else: ?>
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
