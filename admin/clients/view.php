<?php
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

auth_check();

$id = (int)($_GET['id'] ?? 0);
$stmt = db()->prepare('SELECT * FROM clients WHERE id = ?');
$stmt->execute([$id]);
$client = $stmt->fetch();

if (!$client) {
    flash_set('error', 'Client not found.');
    header('Location: ' . APP_URL . '/clients/');
    exit;
}

$page_title = $client['first_name'] . ' ' . $client['last_name'];

// Orders
$orders = db()->prepare('SELECT o.*, s.name AS svc_name FROM orders o LEFT JOIN services s ON s.id=o.service_id WHERE o.client_id=? ORDER BY o.created_at DESC');
$orders->execute([$id]);
$orders = $orders->fetchAll();

// Invoices
$invoices = db()->prepare('SELECT * FROM invoices WHERE client_id=? ORDER BY created_at DESC');
$invoices->execute([$id]);
$invoices = $invoices->fetchAll();

// Tickets
$tickets = db()->prepare('SELECT * FROM tickets WHERE client_id=? ORDER BY updated_at DESC');
$tickets->execute([$id]);
$tickets = $tickets->fetchAll();

// Revenue total
$revenue = db()->prepare('SELECT COALESCE(SUM(total),0) FROM invoices WHERE client_id=? AND status="paid"');
$revenue->execute([$id]);
$revenue = (float) $revenue->fetchColumn();

require_once '../includes/header.php';
?>

<div class="page-header">
  <div>
    <div class="breadcrumb"><a href="<?php echo APP_URL; ?>/clients/">Clients</a><span class="breadcrumb-sep">›</span> <?php echo h($client['first_name'] . ' ' . $client['last_name']); ?></div>
    <h1><?php echo h($client['first_name'] . ' ' . $client['last_name']); ?></h1>
  </div>
  <div class="page-header-actions">
    <a href="<?php echo APP_URL; ?>/orders/add.php?client_id=<?php echo $id; ?>" class="btn btn-ghost btn-sm">
      <i class="fas fa-plus"></i> New Order
    </a>
    <a href="<?php echo APP_URL; ?>/invoices/add.php?client_id=<?php echo $id; ?>" class="btn btn-ghost btn-sm">
      <i class="fas fa-file-invoice"></i> New Invoice
    </a>
    <a href="<?php echo APP_URL; ?>/clients/invite.php?id=<?php echo $id; ?>" class="btn btn-ghost btn-sm" title="Send portal invite">
      <i class="fas fa-envelope"></i> Portal Invite
    </a>
    <a href="<?php echo APP_URL; ?>/clients/edit.php?id=<?php echo $id; ?>" class="btn btn-primary btn-sm">
      <i class="fas fa-edit"></i> Edit
    </a>
  </div>
</div>

<div class="profile-layout">

  <!-- Left: Profile card -->
  <div class="profile-card">
    <div class="profile-avatar"><?php echo strtoupper(substr($client['first_name'], 0, 1)); ?></div>
    <div class="profile-name"><?php echo h($client['first_name'] . ' ' . $client['last_name']); ?></div>
    <div class="profile-email"><?php echo h($client['email']); ?></div>
    <?php echo badge($client['status']); ?>

    <ul class="profile-meta">
      <li><span class="key">Phone</span>      <span class="val"><?php echo h($client['phone'] ?: '—'); ?></span></li>
      <li><span class="key">Company</span>    <span class="val"><?php echo h($client['company'] ?: '—'); ?></span></li>
      <li><span class="key">Country</span>    <span class="val"><?php echo h($client['country']); ?></span></li>
      <li><span class="key">Orders</span>     <span class="val"><?php echo count($orders); ?></span></li>
      <li><span class="key">Invoices</span>   <span class="val"><?php echo count($invoices); ?></span></li>
      <li><span class="key">Tickets</span>    <span class="val"><?php echo count($tickets); ?></span></li>
      <li><span class="key">Total Revenue</span><span class="val text-success"><?php echo format_money($revenue); ?></span></li>
      <li><span class="key">Client Since</span><span class="val"><?php echo format_date($client['created_at']); ?></span></li>
    </ul>

    <?php if ($client['notes']): ?>
      <div style="margin-top:16px;padding:12px;background:#f8fafc;border-radius:6px;font-size:12.5px;color:var(--text-muted);text-align:left">
        <strong style="display:block;margin-bottom:4px">Notes</strong>
        <?php echo nl2br(h($client['notes'])); ?>
      </div>
    <?php endif; ?>
  </div>

  <!-- Right: Tabbed content -->
  <div>
    <div class="tabs">
      <a href="#orders-tab"   class="tab-link active">Orders (<?php echo count($orders); ?>)</a>
      <a href="#invoices-tab" class="tab-link">Invoices (<?php echo count($invoices); ?>)</a>
      <a href="#tickets-tab"  class="tab-link">Tickets (<?php echo count($tickets); ?>)</a>
    </div>

    <!-- Orders -->
    <div id="orders-tab" class="tab-pane active">
      <div class="table-wrap">
        <div class="card-header">
          <div class="card-title">Orders</div>
          <a href="<?php echo APP_URL; ?>/orders/add.php?client_id=<?php echo $id; ?>" class="btn btn-primary btn-sm">
            <i class="fas fa-plus"></i> New Order
          </a>
        </div>
        <table>
          <thead><tr><th>Service</th><th>Domain</th><th>Amount</th><th>Billing</th><th>Status</th><th>Next Due</th><th></th></tr></thead>
          <tbody>
          <?php if ($orders): foreach ($orders as $o): ?>
            <tr>
              <td><?php echo h($o['service_name'] ?? $o['svc_name'] ?? '—'); ?></td>
              <td><?php echo h($o['domain'] ?: '—'); ?></td>
              <td><?php echo format_money($o['amount']); ?></td>
              <td><?php echo badge($o['billing_cycle']); ?></td>
              <td><?php echo badge($o['status']); ?></td>
              <td><?php echo format_date($o['next_due']); ?></td>
              <td class="actions">
                <a href="<?php echo APP_URL; ?>/orders/edit.php?id=<?php echo $o['id']; ?>" class="action-link edit">Edit</a>
              </td>
            </tr>
          <?php endforeach; else: ?>
            <tr><td colspan="7"><div class="empty-state"><i class="fas fa-box"></i><p>No orders yet.</p></div></td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Invoices -->
    <div id="invoices-tab" class="tab-pane">
      <div class="table-wrap">
        <div class="card-header">
          <div class="card-title">Invoices</div>
          <a href="<?php echo APP_URL; ?>/invoices/add.php?client_id=<?php echo $id; ?>" class="btn btn-primary btn-sm">
            <i class="fas fa-plus"></i> New Invoice
          </a>
        </div>
        <table>
          <thead><tr><th>Invoice #</th><th>Total</th><th>Status</th><th>Due Date</th><th>Paid Date</th><th></th></tr></thead>
          <tbody>
          <?php if ($invoices): foreach ($invoices as $inv): ?>
            <tr>
              <td><strong><?php echo h($inv['invoice_number']); ?></strong></td>
              <td><?php echo format_money($inv['total']); ?></td>
              <td><?php echo badge($inv['status']); ?></td>
              <td><?php echo format_date($inv['due_date']); ?></td>
              <td><?php echo format_date($inv['paid_date']); ?></td>
              <td class="actions">
                <a href="<?php echo APP_URL; ?>/invoices/view.php?id=<?php echo $inv['id']; ?>" class="action-link view">View</a>
              </td>
            </tr>
          <?php endforeach; else: ?>
            <tr><td colspan="6"><div class="empty-state"><i class="fas fa-file-invoice"></i><p>No invoices yet.</p></div></td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Tickets -->
    <div id="tickets-tab" class="tab-pane">
      <div class="table-wrap">
        <div class="card-header">
          <div class="card-title">Support Tickets</div>
          <a href="<?php echo APP_URL; ?>/tickets/add.php?client_id=<?php echo $id; ?>" class="btn btn-primary btn-sm">
            <i class="fas fa-plus"></i> New Ticket
          </a>
        </div>
        <table>
          <thead><tr><th>Ticket #</th><th>Subject</th><th>Department</th><th>Priority</th><th>Status</th><th>Updated</th></tr></thead>
          <tbody>
          <?php if ($tickets): foreach ($tickets as $t): ?>
            <tr>
              <td><a href="<?php echo APP_URL; ?>/tickets/view.php?id=<?php echo $t['id']; ?>" style="color:var(--navy);font-weight:600"><?php echo h($t['ticket_number']); ?></a></td>
              <td><?php echo h(mb_strimwidth($t['subject'], 0, 48, '…')); ?></td>
              <td><?php echo ucfirst($t['department']); ?></td>
              <td><?php echo badge($t['priority']); ?></td>
              <td><?php echo badge($t['status']); ?></td>
              <td><?php echo time_ago($t['updated_at']); ?></td>
            </tr>
          <?php endforeach; else: ?>
            <tr><td colspan="6"><div class="empty-state"><i class="fas fa-comments"></i><p>No tickets yet.</p></div></td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<?php require_once '../includes/footer.php'; ?>
