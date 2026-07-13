<?php
require_once '../../includes/config.php';
require_once '../../includes/db.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

auth_check();
$page_title = 'Domain Registrations';

$page   = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * PER_PAGE;

$total   = (int) db()->query("SELECT COUNT(*) FROM domain_registrations")->fetchColumn();
$domains = db()->query(
    "SELECT dr.*, c.first_name, c.last_name, c.email
     FROM domain_registrations dr
     LEFT JOIN clients c ON c.id = dr.client_id
     ORDER BY dr.expiry_date ASC
     LIMIT {$offset}, " . PER_PAGE
)->fetchAll();

require_once '../../includes/header.php';
?>

<div class="content-header">
  <h1 class="content-title">Domain Registrations</h1>
  <div style="display:flex;gap:8px">
    <a href="<?php echo APP_URL; ?>/integrations/domains/tlds.php" class="btn btn-primary"><i class="fas fa-tags"></i> TLD Pricing</a>
    <a href="<?php echo APP_URL; ?>/integrations/domains/check.php" class="btn btn-ghost"><i class="fas fa-magnifying-glass"></i> Check Domain</a>
    <a href="<?php echo APP_URL; ?>/integrations/" class="btn btn-ghost"><i class="fas fa-arrow-left"></i> Back</a>
  </div>
</div>

<div class="card">
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>Domain</th>
          <th>Registrar</th>
          <th>Client</th>
          <th>Expiry</th>
          <th>Auto-Renew</th>
          <th>Status</th>
          <th>Nameservers</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php if ($domains): foreach ($domains as $d):
          $days_left = (int)ceil((strtotime($d['expiry_date']) - time()) / 86400);
          $expiry_class = $days_left <= 30 ? 'color:var(--danger);font-weight:700' : ($days_left <= 60 ? 'color:var(--warning)' : '');
        ?>
          <tr>
            <td><strong><?php echo htmlspecialchars($d['domain_name']); ?></strong></td>
            <td><?php echo ucfirst($d['registrar']); ?></td>
            <td>
              <?php if ($d['first_name']): ?>
                <a href="<?php echo APP_URL; ?>/clients/view.php?id=<?php echo $d['client_id']; ?>"><?php echo htmlspecialchars($d['first_name'] . ' ' . $d['last_name']); ?></a>
              <?php else: ?>
                <span style="color:var(--text-muted)">—</span>
              <?php endif; ?>
            </td>
            <td>
              <span style="<?php echo $expiry_class; ?>"><?php echo format_date($d['expiry_date']); ?></span>
              <br /><span style="font-size:11px;color:var(--text-muted)"><?php echo $days_left; ?>d left</span>
            </td>
            <td style="text-align:center">
              <?php echo $d['auto_renew'] ? '<i class="fas fa-check" style="color:var(--success)"></i>' : '<i class="fas fa-xmark" style="color:var(--text-muted)"></i>'; ?>
            </td>
            <td><?php echo badge($d['status']); ?></td>
            <td style="font-size:11px;color:var(--text-muted)">
              <?php
              $ns = json_decode($d['nameservers'] ?? '[]', true) ?? [];
              echo htmlspecialchars(implode('<br>', $ns));
              ?>
            </td>
            <td>
              <a href="view.php?id=<?php echo $d['id']; ?>" class="btn btn-ghost btn-sm">Manage</a>
            </td>
          </tr>
        <?php endforeach; else: ?>
          <tr>
            <td colspan="8">
              <div class="empty-state">
                <i class="fas fa-globe"></i>
                <h3>No Domain Registrations</h3>
                <p>Domains registered through the system will appear here.</p>
                <a href="check.php" class="btn btn-primary" style="margin-top:12px"><i class="fas fa-magnifying-glass"></i> Check a Domain</a>
              </div>
            </td>
          </tr>
        <?php endif; ?>
      </tbody>
    </table>
    <?php if ($total > PER_PAGE): ?>
      <div style="padding:14px 16px;border-top:1px solid var(--border);display:flex;gap:6px">
        <?php echo paginate($total, $page, PER_PAGE, '?page=%d'); ?>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php require_once '../../includes/footer.php'; ?>
