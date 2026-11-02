<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once dirname(__DIR__, 2) . '/admin/includes/functions.php';

portal_check();
$page_title = 'My Invoices';
$cid    = current_client()['id'];
$cid    = (int) $cid;
// "Unpaid" means everything still owed — sent AND overdue. (It used to
// be 'sent' only, which hid exactly the invoices that mattered most.)
$filters = [
    ''        => ['All',     "1"],
    'unpaid'  => ['Unpaid',  "status IN ('sent','overdue')"],
    'overdue' => ['Overdue', "(status = 'overdue' OR (status = 'sent' AND due_date < CURDATE()))"],
    'paid'    => ['Paid',    "status = 'paid'"],
];
$status = (string) ($_GET['status'] ?? $_GET['filter'] ?? '');
if (!isset($filters[$status])) $status = '';
$page   = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * PER_PAGE;

$where  = "client_id = $cid AND " . $filters[$status][1];

$total    = (int) db()->query("SELECT COUNT(*) FROM invoices WHERE $where")->fetchColumn();
$invoices = db()->query("SELECT * FROM invoices WHERE $where ORDER BY created_at DESC LIMIT $offset," . PER_PAGE)->fetchAll();

$counts = [];
foreach ($filters as $k => [$lbl, $cond]) {
    $counts[$k] = (int) db()->query("SELECT COUNT(*) FROM invoices WHERE client_id = $cid AND $cond")->fetchColumn();
}

// Outstanding: unpaid totals less any part payments, per currency.
$owed = []; $owed_count = 0; $only_id = 0;
foreach (db()->query("SELECT i.id, COALESCE(NULLIF(i.currency,''),'USD') cur,
                             i.total - COALESCE((SELECT SUM(p.amount) FROM payments p WHERE p.invoice_id = i.id AND p.status = 'completed'), 0) owed
                      FROM invoices i WHERE i.client_id = $cid AND i.status IN ('sent','overdue')")->fetchAll() as $r) {
    if ((float) $r['owed'] < 0.01) continue;
    $owed[$r['cur']] = ($owed[$r['cur']] ?? 0) + (float) $r['owed'];
    $owed_count++; $only_id = (int) $r['id'];
}

require_once '../includes/header.php';
?>

<div class="page-hero">
  <div class="container">
    <div><h1>Invoices</h1><p>All your billing history and payment records</p></div>
    <?php if ($owed): ?>
      <div class="hero-figure">
        <div class="lbl">Outstanding</div>
        <div class="val">
          <?php echo implode(' + ', array_map(fn($c, $a) => htmlspecialchars(format_money($a, $c)), array_keys($owed), $owed)); ?>
        </div>
        <a href="<?php echo PORTAL_URL; ?>/invoices/<?php echo $owed_count === 1 ? 'view.php?id=' . $only_id . '#pay' : 'index.php?status=unpaid'; ?>" class="btn btn-white btn-sm">
          <i class="fas fa-lock"></i> <?php echo $owed_count === 1 ? 'Pay now' : 'Pay ' . $owed_count . ' invoices'; ?>
        </a>
      </div>
    <?php endif; ?>
  </div>
</div>

<div class="page-body">
<div class="container">

  <?php portal_render_banners(); ?>

  <!-- Status filter tabs -->
  <div style="display:flex;gap:8px;margin-bottom:16px;flex-wrap:wrap">
    <?php foreach ($filters as $val => [$lbl]): ?>
      <a href="?status=<?php echo $val; ?>" class="btn btn-sm <?php echo $status === $val ? 'btn-outline' : 'btn-ghost'; ?>">
        <?php echo $lbl; ?>
        <span style="margin-left:4px;font-size:11px;opacity:.75">(<?php echo $counts[$val]; ?>)</span>
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
          <td><a href="<?php echo PORTAL_URL; ?>/invoices/view.php?id=<?php echo $inv['id']; ?>" style="font-weight:700;color:var(--text)"><?php echo htmlspecialchars($inv['invoice_number']); ?></a></td>
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
