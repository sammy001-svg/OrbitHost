<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once dirname(__DIR__, 2) . '/admin/includes/functions.php';

portal_check();
$page_title = 'My Invoices';
$cid    = current_client()['id'];
$status = trim($_GET['status'] ?? '');
$page   = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * PER_PAGE;

$where  = "client_id = $cid";
if ($status) $where .= " AND status = " . db()->quote($status);

$total    = (int) db()->query("SELECT COUNT(*) FROM invoices WHERE $where")->fetchColumn();
$invoices = db()->query("SELECT * FROM invoices WHERE $where ORDER BY created_at DESC LIMIT $offset," . PER_PAGE)->fetchAll();

require_once '../includes/header.php';
?>

<div class="page-hero">
  <div class="container">
    <div><h1>Invoices</h1><p>All your billing history and payment records</p></div>
  </div>
</div>

<div class="page-body">
<div class="container">

  <?php portal_render_banners(); ?>

  <!-- Status filter tabs -->
  <div style="display:flex;gap:8px;margin-bottom:16px;flex-wrap:wrap">
    <?php
    $tabs = ['' => 'All', 'sent' => 'Unpaid', 'paid' => 'Paid', 'overdue' => 'Overdue', 'draft' => 'Draft'];
    foreach ($tabs as $val => $lbl):
    ?>
      <a href="?status=<?php echo $val; ?>" class="btn btn-sm <?php echo $status===$val?'btn-outline':'btn-ghost'; ?>">
        <?php echo $lbl; ?>
      </a>
    <?php endforeach; ?>
  </div>

  <div class="p-table-wrap">
    <table>
      <thead>
        <tr>
          <th>Invoice #</th>
          <th>Date</th>
          <th>Due Date</th>
          <th>Amount</th>
          <th>Status</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
      <?php if ($invoices): foreach ($invoices as $inv): ?>
        <tr>
          <td><a href="<?php echo PORTAL_URL; ?>/invoices/view.php?id=<?php echo $inv['id']; ?>" style="font-weight:700;color:var(--navy)"><?php echo htmlspecialchars($inv['invoice_number']); ?></a></td>
          <td><?php echo format_date($inv['created_at']); ?></td>
          <td><?php echo format_date($inv['due_date']); ?></td>
          <td><strong><?php echo format_money($inv['total'], $inv['currency'] ?? null); ?></strong></td>
          <td><?php echo badge($inv['status']); ?></td>
          <td>
            <a href="<?php echo PORTAL_URL; ?>/invoices/view.php?id=<?php echo $inv['id']; ?>" class="btn btn-ghost btn-sm">View</a>
            <?php if (in_array($inv['status'], ['sent','overdue'])): ?>
              <a href="<?php echo PORTAL_URL; ?>/invoices/view.php?id=<?php echo $inv['id']; ?>#pay" class="btn btn-primary btn-sm">Pay Now</a>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; else: ?>
        <tr><td colspan="6"><div class="empty-state"><i class="fas fa-file-invoice"></i><h3>No invoices found</h3><p>Your billing history will appear here.</p></div></td></tr>
      <?php endif; ?>
      </tbody>
    </table>
    <?php
    // Simple pagination
    if ($total > PER_PAGE):
      $pages = ceil($total / PER_PAGE);
      echo '<div style="display:flex;gap:6px;padding:14px 16px;border-top:1px solid var(--border)">';
      for ($i=1;$i<=$pages;$i++) {
        $cls = $i===$page ? 'btn-outline' : 'btn-ghost';
        echo "<a href=\"?status=$status&page=$i\" class=\"btn btn-sm $cls\">$i</a>";
      }
      echo '</div>';
    endif;
    ?>
  </div>

</div>
</div>

<?php require_once '../includes/footer.php'; ?>
