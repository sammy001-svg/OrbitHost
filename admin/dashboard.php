<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

auth_check();
$page_title = 'Dashboard';
$db = db();

// Resilient scalar query — returns default if a table isn't migrated yet
function dq($sql, $default = 0)
{
    try { return db()->query($sql)->fetchColumn(); }
    catch (\Throwable $e) { return $default; }
}
function dqa($sql)
{
    try { return db()->query($sql)->fetchAll(); }
    catch (\Throwable $e) { return []; }
}

// ── Key metrics ────────────────────────────────────────────
$active_services = (int) dq('SELECT COUNT(*) FROM client_services WHERE status="active"');
$total_clients   = (int) $db->query('SELECT COUNT(*) FROM clients WHERE status="active"')->fetchColumn();
$open_tickets    = (int) $db->query('SELECT COUNT(*) FROM tickets WHERE status IN ("open","pending")')->fetchColumn();
$month_revenue   = (float) $db->query('SELECT COALESCE(SUM(total),0) FROM invoices WHERE status="paid" AND YEAR(paid_date)=YEAR(NOW()) AND MONTH(paid_date)=MONTH(NOW())')->fetchColumn();
$active_providers= (int) dq('SELECT COUNT(*) FROM integration_settings WHERE is_active=1');

// MRR from active services (annualised /12)
$mrr = (float) dq('SELECT COALESCE(SUM(CASE WHEN billing_cycle="annual" THEN amount/12 WHEN billing_cycle="monthly" THEN amount ELSE 0 END),0) FROM client_services WHERE status="active"');

// Attention items
$pending_services = (int) dq('SELECT COUNT(*) FROM client_services WHERE status IN ("pending","failed")');
$overdue_invoices = (int) $db->query('SELECT COUNT(*) FROM invoices WHERE status="overdue"')->fetchColumn();
$suspended_services = (int) dq('SELECT COUNT(*) FROM client_services WHERE status="suspended"');

// Services by status
$svc_status = dqa('SELECT status, COUNT(*) n FROM client_services GROUP BY status');
$svc_labels = $svc_status ? array_map(fn($r) => ucfirst($r['status']), $svc_status) : ['No services'];
$svc_values = $svc_status ? array_column($svc_status, 'n') : [0];

// Recent services
$recent_services = dqa('SELECT cs.id, cs.label, cs.domain, cs.status, cs.provider_key, CONCAT(c.first_name," ",c.last_name) client_name
                        FROM client_services cs LEFT JOIN clients c ON c.id=cs.client_id ORDER BY cs.created_at DESC LIMIT 6');

// Recent tickets
$recent_tickets = $db->query('SELECT t.*, CONCAT(COALESCE(c.first_name,"")," ",COALESCE(c.last_name,"")) client_name
                              FROM tickets t LEFT JOIN clients c ON c.id=t.client_id ORDER BY t.updated_at DESC LIMIT 6')->fetchAll();

// Revenue last 6 months
$rev_labels = []; $rev_amounts = [];
for ($i = 5; $i >= 0; $i--) {
    $ts = strtotime("-$i months"); $ym = date('Y-m', $ts);
    $stmt = $db->prepare('SELECT COALESCE(SUM(total),0) FROM invoices WHERE status="paid" AND DATE_FORMAT(paid_date,"%Y-%m")=?');
    $stmt->execute([$ym]);
    $rev_labels[]  = date('M', $ts);
    $rev_amounts[] = round((float)$stmt->fetchColumn(), 2);
}

function svc_dot(string $s): string
{
    $m = ['active'=>'dot-green','pending'=>'dot-amber','provisioning'=>'dot-amber','suspended'=>'dot-red','failed'=>'dot-red','terminated'=>'dot-grey','cancelled'=>'dot-grey'];
    return $m[$s] ?? 'dot-grey';
}

require_once __DIR__ . '/includes/header.php';
?>

<div class="content-header">
  <div>
    <h1 class="content-title">Dashboard</h1>
    <p class="page-subtitle">Welcome back, <?php echo h(current_admin()['name']); ?>. Here's your platform at a glance.</p>
  </div>
  <div class="page-header-actions">
    <a href="<?php echo APP_URL; ?>/services/create.php" class="btn btn-primary"><i class="fas fa-plus"></i> Create Service</a>
  </div>
</div>

<div class="stat-grid">
  <div class="stat-card">
    <div class="stat-icon green"><i class="fas fa-layer-group"></i></div>
    <div><div class="stat-label">Active Services</div><div class="stat-value"><?php echo number_format($active_services); ?></div>
      <div class="stat-sub"><?php echo $pending_services; ?> pending provisioning</div></div>
  </div>
  <div class="stat-card">
    <div class="stat-icon navy"><i class="fas fa-users"></i></div>
    <div><div class="stat-label">Active Clients</div><div class="stat-value"><?php echo number_format($total_clients); ?></div>
      <div class="stat-sub">Registered accounts</div></div>
  </div>
  <div class="stat-card">
    <div class="stat-icon green"><i class="fas fa-arrow-trend-up"></i></div>
    <div><div class="stat-label">MRR</div><div class="stat-value" style="font-size:22px"><?php echo format_money($mrr); ?></div>
      <div class="stat-sub">Recurring / month</div></div>
  </div>
  <div class="stat-card">
    <div class="stat-icon purple"><i class="fas fa-plug"></i></div>
    <div><div class="stat-label">Active Providers</div><div class="stat-value"><?php echo number_format($active_providers); ?></div>
      <div class="stat-sub"><a href="<?php echo APP_URL; ?>/integrations/" style="color:var(--green)">Manage</a></div></div>
  </div>
</div>

<?php if ($pending_services || $overdue_invoices || $suspended_services): ?>
<div class="alert alert-warning" style="margin-bottom:20px">
  <i class="fas fa-triangle-exclamation"></i>
  <?php
    $notes = [];
    if ($pending_services)   $notes[] = "<strong>$pending_services</strong> service(s) need provisioning";
    if ($suspended_services) $notes[] = "<strong>$suspended_services</strong> suspended service(s)";
    if ($overdue_invoices)   $notes[] = "<strong>$overdue_invoices</strong> overdue invoice(s)";
    echo implode(' &nbsp;·&nbsp; ', $notes);
  ?>
</div>
<?php endif; ?>

<div class="charts-grid">
  <div class="card">
    <div class="card-header">
      <div class="card-title"><i class="fas fa-chart-line" style="color:var(--green)"></i> Revenue — last 6 months</div>
      <a href="<?php echo APP_URL; ?>/invoices/" class="btn btn-ghost btn-sm">Invoices</a>
    </div>
    <div class="card-body"><canvas id="revenueChart" height="92"></canvas></div>
  </div>
  <div class="card">
    <div class="card-header"><div class="card-title"><i class="fas fa-chart-pie" style="color:var(--navy)"></i> Services by status</div></div>
    <div class="card-body"><canvas id="svcChart" height="180"></canvas></div>
  </div>
</div>

<div class="gap-grid">
  <div class="table-wrap">
    <div class="card-header"><div class="card-title">Recent Services</div><a href="<?php echo APP_URL; ?>/services/" class="btn btn-ghost btn-sm">View All</a></div>
    <table>
      <thead><tr><th>Service</th><th>Client</th><th>Status</th></tr></thead>
      <tbody>
      <?php if ($recent_services): foreach ($recent_services as $s): ?>
        <tr onclick="location.href='<?php echo APP_URL; ?>/services/view.php?id=<?php echo $s['id']; ?>'" style="cursor:pointer">
          <td><div class="td-name"><?php echo h($s['label']); ?></div><div class="td-sub"><?php echo $s['domain'] ? h($s['domain']) : ($s['provider_key'] ? h($s['provider_key']) : '—'); ?></div></td>
          <td><?php echo h(trim($s['client_name']) ?: '—'); ?></td>
          <td><span class="flex-gap"><span class="dot <?php echo svc_dot($s['status']); ?>"></span> <?php echo ucfirst($s['status']); ?></span></td>
        </tr>
      <?php endforeach; else: ?>
        <tr><td colspan="3"><div class="empty-state" style="padding:28px"><i class="fas fa-layer-group"></i><p>No services yet</p></div></td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>

  <div class="table-wrap">
    <div class="card-header"><div class="card-title">Recent Tickets</div><a href="<?php echo APP_URL; ?>/tickets/" class="btn btn-ghost btn-sm">View All</a></div>
    <table>
      <thead><tr><th>Subject</th><th>Priority</th><th>Status</th></tr></thead>
      <tbody>
      <?php if ($recent_tickets): foreach ($recent_tickets as $t): ?>
        <tr>
          <td><a href="<?php echo APP_URL; ?>/tickets/view.php?id=<?php echo $t['id']; ?>" style="font-weight:600;color:var(--navy);text-decoration:none;display:block"><?php echo h(mb_strimwidth($t['subject'], 0, 42, '…')); ?></a>
            <div class="td-sub"><?php echo h(trim($t['client_name']) ?: 'Guest'); ?> · <?php echo time_ago($t['updated_at']); ?></div></td>
          <td><?php echo badge($t['priority']); ?></td>
          <td><?php echo badge($t['status']); ?></td>
        </tr>
      <?php endforeach; else: ?>
        <tr><td colspan="3"><div class="empty-state" style="padding:28px"><i class="fas fa-comments"></i><p>No tickets yet</p></div></td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.0/chart.umd.min.js" crossorigin="anonymous"></script>
<script>
if (window.Chart) {
  Chart.defaults.font.family = "-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif";
  Chart.defaults.font.size = 12;

  new Chart(document.getElementById('revenueChart'), {
    type: 'line',
    data: { labels: <?php echo json_encode($rev_labels); ?>, datasets: [{
      label: 'Revenue', data: <?php echo json_encode($rev_amounts); ?>,
      borderColor: '#1A8A45', backgroundColor: 'rgba(26,138,69,.08)', tension: .35, fill: true,
      pointBackgroundColor: '#1A8A45', pointRadius: 3, pointHoverRadius: 6, borderWidth: 2 }] },
    options: { responsive: true, plugins: { legend: { display: false } },
      scales: { y: { beginAtZero: true, grid: { color: '#eef1f6' }, ticks: { callback: v => v.toLocaleString() } }, x: { grid: { display: false } } } }
  });

  new Chart(document.getElementById('svcChart'), {
    type: 'doughnut',
    data: { labels: <?php echo json_encode($svc_labels); ?>, datasets: [{
      data: <?php echo json_encode($svc_values); ?>,
      backgroundColor: ['#1A8A45','#d97706','#2563eb','#dc2626','#64748b','#0B1E3D'], borderWidth: 0, hoverOffset: 6 }] },
    options: { responsive: true, cutout: '66%', plugins: { legend: { position: 'bottom', labels: { boxWidth: 12, padding: 14 } } } }
  });
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
