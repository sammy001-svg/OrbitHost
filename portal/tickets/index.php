<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once dirname(__DIR__, 2) . '/admin/includes/functions.php';

portal_check();
$page_title = 'Support Tickets';
$cid    = current_client()['id'];
$status = trim($_GET['status'] ?? '');

$where  = "client_id = $cid";
if ($status) $where .= " AND status = " . db()->quote($status);

$tickets = db()->query("SELECT * FROM tickets WHERE $where ORDER BY updated_at DESC")->fetchAll();

require_once '../includes/header.php';
?>

<div class="page-hero">
  <div class="container">
    <div><h1>Support Tickets</h1><p>Track your support requests and get help</p></div>
    <a href="<?php echo PORTAL_URL; ?>/tickets/add.php" class="btn btn-white"><i class="fas fa-plus"></i> New Ticket</a>
  </div>
</div>

<div class="page-body">
<div class="container">

  <?php portal_render_banners(); ?>

  <div style="display:flex;gap:8px;margin-bottom:16px;flex-wrap:wrap">
    <?php foreach (['' => 'All', 'open' => 'Open', 'pending' => 'Pending', 'answered' => 'Answered', 'closed' => 'Closed'] as $v => $l): ?>
      <a href="?status=<?php echo $v; ?>" class="btn btn-sm <?php echo $status===$v?'btn-outline':'btn-ghost'; ?>"><?php echo $l; ?></a>
    <?php endforeach; ?>
  </div>

  <div class="p-table-wrap">
    <table>
      <thead><tr><th>Ticket #</th><th>Subject</th><th>Department</th><th>Priority</th><th>Status</th><th>Last Updated</th><th></th></tr></thead>
      <tbody>
      <?php if ($tickets): foreach ($tickets as $t): ?>
        <tr>
          <td><a href="<?php echo PORTAL_URL; ?>/tickets/view.php?id=<?php echo $t['id']; ?>" style="font-weight:700;color:var(--navy);font-size:12px"><?php echo htmlspecialchars($t['ticket_number']); ?></a></td>
          <td><a href="<?php echo PORTAL_URL; ?>/tickets/view.php?id=<?php echo $t['id']; ?>" style="color:var(--text)"><?php echo htmlspecialchars(mb_strimwidth($t['subject'],0,52,'…')); ?></a></td>
          <td><?php echo ucfirst($t['department']); ?></td>
          <td><?php echo badge($t['priority']); ?></td>
          <td><?php echo badge($t['status']); ?></td>
          <td><?php echo time_ago($t['updated_at']); ?></td>
          <td><a href="<?php echo PORTAL_URL; ?>/tickets/view.php?id=<?php echo $t['id']; ?>" class="btn btn-ghost btn-sm">Open</a></td>
        </tr>
      <?php endforeach; else: ?>
        <tr><td colspan="7">
          <div class="empty-state">
            <i class="fas fa-comments"></i>
            <h3>No tickets yet</h3>
            <p>Need help? Our support team is ready.</p>
            <a href="<?php echo PORTAL_URL; ?>/tickets/add.php" class="btn btn-primary" style="margin-top:14px"><i class="fas fa-plus"></i> Open a Ticket</a>
          </div>
        </td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>

</div>
</div>

<?php require_once '../includes/footer.php'; ?>
