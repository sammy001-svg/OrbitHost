<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/providers/Provider.php';
require_once __DIR__ . '/includes/Currency.php';

auth_check();
$page_title = 'Dashboard';
$db = db();
Currency::ensureSchema();
// Every aggregate below is scoped to USD-or-legacy rows only — now that
// orders/client_services/invoices can be billed in KES, a blind SUM(total)
// would otherwise silently blend two currencies into one meaningless
// number. KES figures aren't folded in here yet; see $kes_revenue_month
// below for a separate, un-mixed KES total.
$CUR_USD = "(currency = 'USD' OR currency IS NULL)";

// Resilient scalar/rowset query — returns default if a table isn't migrated yet
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
$month_revenue   = (float) dq("SELECT COALESCE(SUM(total),0) FROM invoices WHERE status='paid' AND $CUR_USD AND YEAR(paid_date)=YEAR(NOW()) AND MONTH(paid_date)=MONTH(NOW())");
$kes_revenue_month = (float) dq("SELECT COALESCE(SUM(total),0) FROM invoices WHERE status='paid' AND currency='KES' AND YEAR(paid_date)=YEAR(NOW()) AND MONTH(paid_date)=MONTH(NOW())");

// MRR from active services (annualised /12) — USD-denominated services only, see $CUR_USD note above
$mrr = (float) dq("SELECT COALESCE(SUM(CASE WHEN billing_cycle='annual' THEN amount/12 WHEN billing_cycle='monthly' THEN amount ELSE 0 END),0) FROM client_services WHERE status='active' AND $CUR_USD");

// Trend context for the KPI row
$new_clients_month = (int) dq('SELECT COUNT(*) FROM clients WHERE YEAR(created_at)=YEAR(CURDATE()) AND MONTH(created_at)=MONTH(CURDATE())');
$urgent_tickets     = (int) dq('SELECT COUNT(*) FROM tickets WHERE status IN ("open","pending") AND priority IN ("urgent","high")');
$last_month_revenue = (float) dq("SELECT COALESCE(SUM(total),0) FROM invoices WHERE status='paid' AND $CUR_USD AND YEAR(paid_date)=YEAR(CURDATE() - INTERVAL 1 MONTH) AND MONTH(paid_date)=MONTH(CURDATE() - INTERVAL 1 MONTH)");
$revenue_delta = $last_month_revenue > 0 ? round((($month_revenue - $last_month_revenue) / $last_month_revenue) * 100) : null;

// Attention items
$pending_services   = (int) dq('SELECT COUNT(*) FROM client_services WHERE status="pending"');
$failed_services    = (int) dq('SELECT COUNT(*) FROM client_services WHERE status="failed"');
$overdue_invoices   = (int) $db->query('SELECT COUNT(*) FROM invoices WHERE status="overdue"')->fetchColumn();
$suspended_services = (int) dq('SELECT COUNT(*) FROM client_services WHERE status="suspended"');
$failed_emails_7d   = (int) dq("SELECT COUNT(*) FROM notifications WHERE email_sent = 0 AND created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)");

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

// Recent activity (admin audit trail — logged everywhere via log_activity(), never surfaced until now)
$recent_activity = dqa('SELECT al.action, al.entity_type, al.description, al.created_at, au.name admin_name
                        FROM activity_log al LEFT JOIN admin_users au ON au.id = al.admin_id
                        ORDER BY al.created_at DESC LIMIT 8');

// Platform health — providers currently switched on, by registry metadata
require_once __DIR__ . '/includes/providers/ProviderRegistry.php';
$health_rows = [];
foreach (dqa('SELECT provider FROM integration_settings WHERE is_active = 1') as $row) {
    $def = ProviderRegistry::get($row['provider']);
    if (!$def) continue;
    $health_rows[] = [
        'name'       => $def['name'],
        'icon'       => $def['icon'],
        'color'      => $def['color'],
        'configured' => Provider::isConfigured($row['provider']),
    ];
}

// Revenue last 6 months (USD-denominated invoices only, see $CUR_USD note above)
$rev_labels = []; $rev_amounts = [];
for ($i = 5; $i >= 0; $i--) {
    $ts = strtotime("-$i months"); $ym = date('Y-m', $ts);
    $stmt = $db->prepare("SELECT COALESCE(SUM(total),0) FROM invoices WHERE status='paid' AND $CUR_USD AND DATE_FORMAT(paid_date,'%Y-%m')=?");
    $stmt->execute([$ym]);
    $rev_labels[]  = date('M', $ts);
    $rev_amounts[] = round((float)$stmt->fetchColumn(), 2);
}

function svc_dot(string $s): string
{
    $m = ['active'=>'dot-green','pending'=>'dot-amber','provisioning'=>'dot-amber','suspended'=>'dot-red','failed'=>'dot-red','terminated'=>'dot-grey','cancelled'=>'dot-grey'];
    return $m[$s] ?? 'dot-grey';
}

function activity_icon(string $entity): string
{
    $m = ['service'=>'fa-layer-group','invoice'=>'fa-file-invoice','ticket'=>'fa-comments','client'=>'fa-user',
          'payment'=>'fa-credit-card','order'=>'fa-cart-shopping','provider'=>'fa-plug'];
    return $m[$entity] ?? 'fa-circle-info';
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
      <div class="stat-sub"><?php echo $new_clients_month; ?> new this month</div></div>
  </div>
  <div class="stat-card">
    <div class="stat-icon green"><i class="fas fa-arrow-trend-up"></i></div>
    <div><div class="stat-label">MRR</div><div class="stat-value" style="font-size:22px"><?php echo format_money($mrr); ?></div>
      <div class="stat-sub">Recurring / month</div></div>
  </div>
  <div class="stat-card">
    <div class="stat-icon orange"><i class="fas fa-sack-dollar"></i></div>
    <div><div class="stat-label">Revenue This Month</div><div class="stat-value" style="font-size:22px"><?php echo format_money($month_revenue); ?></div>
      <div class="stat-sub">
        <?php if ($revenue_delta === null): ?>
          vs last month
        <?php else: ?>
          <span class="stat-delta <?php echo $revenue_delta >= 0 ? 'up' : 'down'; ?>"><i class="fas fa-arrow-<?php echo $revenue_delta >= 0 ? 'up' : 'down'; ?>"></i> <?php echo abs($revenue_delta); ?>%</span> vs last month
        <?php endif; ?>
        <?php if ($kes_revenue_month > 0): ?><br />+ KSh <?php echo number_format($kes_revenue_month, 2); ?> (KES, shown separately)<?php endif; ?>
      </div></div>
  </div>
  <div class="stat-card">
    <div class="stat-icon purple"><i class="fas fa-headset"></i></div>
    <div><div class="stat-label">Open Tickets</div><div class="stat-value"><?php echo number_format($open_tickets); ?></div>
      <div class="stat-sub"><?php echo $urgent_tickets ? $urgent_tickets . ' urgent / high priority' : 'None urgent'; ?></div></div>
  </div>
</div>

<?php if ($pending_services || $failed_services || $overdue_invoices || $suspended_services || $failed_emails_7d): ?>
<div class="alert alert-warning" style="margin-bottom:20px; flex-wrap: wrap">
  <i class="fas fa-triangle-exclamation"></i>
  <span style="display:flex;gap:6px;flex-wrap:wrap;align-items:center">
    <?php if ($pending_services): ?><a href="<?php echo APP_URL; ?>/services/?status=pending" style="color:inherit;font-weight:700;text-decoration:underline"><?php echo $pending_services; ?> service(s) need provisioning</a><?php endif; ?>
    <?php if ($failed_services): ?><span>&nbsp;·&nbsp;</span><a href="<?php echo APP_URL; ?>/services/?status=failed" style="color:inherit;font-weight:700;text-decoration:underline"><?php echo $failed_services; ?> failed provisioning</a><?php endif; ?>
    <?php if ($suspended_services): ?><span>&nbsp;·&nbsp;</span><a href="<?php echo APP_URL; ?>/services/?status=suspended" style="color:inherit;font-weight:700;text-decoration:underline"><?php echo $suspended_services; ?> suspended service(s)</a><?php endif; ?>
    <?php if ($overdue_invoices): ?><span>&nbsp;·&nbsp;</span><a href="<?php echo APP_URL; ?>/invoices/?status=overdue" style="color:inherit;font-weight:700;text-decoration:underline"><?php echo $overdue_invoices; ?> overdue invoice(s)</a><?php endif; ?>
    <?php if ($failed_emails_7d): ?><span>&nbsp;·&nbsp;</span><a href="<?php echo APP_URL; ?>/notifications/#delivery" style="color:inherit;font-weight:700;text-decoration:underline"><?php echo $failed_emails_7d; ?> email(s) failed to send (7d)</a><?php endif; ?>
  </span>
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

<div class="grid-2" style="margin-bottom:20px">
  <div class="card">
    <div class="card-header"><div class="card-title"><i class="fas fa-bolt" style="color:var(--green)"></i> Quick Actions</div></div>
    <div class="card-body">
      <div class="qa-grid">
        <a class="qa-tile" href="<?php echo APP_URL; ?>/services/create.php"><i class="fas fa-layer-group"></i> New Service</a>
        <a class="qa-tile" href="<?php echo APP_URL; ?>/clients/add.php"><i class="fas fa-user-plus"></i> New Client</a>
        <a class="qa-tile" href="<?php echo APP_URL; ?>/invoices/add.php"><i class="fas fa-file-invoice"></i> New Invoice</a>
        <a class="qa-tile" href="<?php echo APP_URL; ?>/tickets/" ><i class="fas fa-headset"></i> Support Queue</a>
        <a class="qa-tile" href="<?php echo APP_URL; ?>/integrations/"><i class="fas fa-plug"></i> Providers</a>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="card-header">
      <div class="card-title"><i class="fas fa-heart-pulse" style="color:var(--green)"></i> Platform Health</div>
      <a href="<?php echo APP_URL; ?>/integrations/" class="btn btn-ghost btn-sm">Manage</a>
    </div>
    <div class="card-body">
      <?php if ($health_rows): ?>
        <div class="data-list">
          <?php foreach ($health_rows as $h): ?>
            <div class="row">
              <div class="k"><i class="fas <?php echo h($h['icon']); ?>" style="color:<?php echo h($h['color']); ?>;margin-right:8px;width:16px;text-align:center"></i><?php echo h($h['name']); ?></div>
              <div class="v"><span class="status-pill <?php echo $h['configured'] ? 'on' : 'err'; ?>"><?php echo $h['configured'] ? 'Connected' : 'Needs setup'; ?></span></div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php else: ?>
        <div class="empty-state" style="padding:20px">
          <i class="fas fa-plug"></i>
          <p>No providers active yet.</p>
          <a href="<?php echo APP_URL; ?>/integrations/" class="btn btn-primary btn-sm" style="margin-top:10px">Connect a Provider</a>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<div class="grid-3" style="margin-bottom:0">
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

  <div class="card">
    <div class="card-header"><div class="card-title">Recent Activity</div></div>
    <div class="card-body">
      <?php if ($recent_activity): ?>
        <div class="activity-list">
          <?php foreach ($recent_activity as $a): ?>
            <div class="activity-item">
              <div class="activity-icon"><i class="fas <?php echo activity_icon($a['entity_type'] ?: '') ; ?>"></i></div>
              <div>
                <div class="activity-text"><strong><?php echo h($a['admin_name'] ?: 'System'); ?></strong> <?php echo h($a['description'] ?: str_replace('_', ' ', $a['action'])); ?></div>
                <div class="activity-time"><?php echo time_ago($a['created_at']); ?></div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php else: ?>
        <div class="empty-state" style="padding:28px"><i class="fas fa-clock-rotate-left"></i><p>No activity recorded yet</p></div>
      <?php endif; ?>
    </div>
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
