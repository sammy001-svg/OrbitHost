<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

auth_check();

$page_title = 'Dashboard';
$db = db();

// ── Key metrics ────────────────────────────────────────────
$total_clients   = (int) $db->query('SELECT COUNT(*) FROM clients WHERE status = "active"')->fetchColumn();
$total_orders    = (int) $db->query('SELECT COUNT(*) FROM orders  WHERE status = "active"')->fetchColumn();
$open_tickets    = (int) $db->query('SELECT COUNT(*) FROM tickets WHERE status IN ("open","pending")')->fetchColumn();
$month_revenue   = (float) $db->query(
    'SELECT COALESCE(SUM(total),0) FROM invoices WHERE status="paid" AND YEAR(paid_date)=YEAR(NOW()) AND MONTH(paid_date)=MONTH(NOW())'
)->fetchColumn();

// Pending items
$pending_orders  = (int) $db->query('SELECT COUNT(*) FROM orders WHERE status = "pending"')->fetchColumn();
$overdue_invoices= (int) $db->query('SELECT COUNT(*) FROM invoices WHERE status = "overdue"')->fetchColumn();

// ── Recent orders (6) ──────────────────────────────────────
$recent_orders = $db->query('
    SELECT o.*, CONCAT(c.first_name," ",c.last_name) AS client_name
    FROM orders o
    JOIN clients c ON c.id = o.client_id
    ORDER BY o.created_at DESC
    LIMIT 6
')->fetchAll();

// ── Recent tickets (5) ─────────────────────────────────────
$recent_tickets = $db->query('
    SELECT t.*, CONCAT(COALESCE(c.first_name,""), " ", COALESCE(c.last_name,"")) AS client_name
    FROM tickets t
    LEFT JOIN clients c ON c.id = t.client_id
    ORDER BY t.updated_at DESC
    LIMIT 5
')->fetchAll();

// ── Revenue: last 6 months ─────────────────────────────────
$rev_labels  = [];
$rev_amounts = [];
for ($i = 5; $i >= 0; $i--) {
    $ts   = strtotime("-$i months");
    $ym   = date('Y-m', $ts);
    $stmt = $db->prepare('SELECT COALESCE(SUM(total),0) FROM invoices WHERE status="paid" AND DATE_FORMAT(paid_date,"%Y-%m")=?');
    $stmt->execute([$ym]);
    $rev_labels[]  = date('M', $ts);
    $rev_amounts[] = round((float)$stmt->fetchColumn(), 2);
}

// ── Ticket status breakdown ────────────────────────────────
$tkt_rows = $db->query('SELECT status, COUNT(*) n FROM tickets GROUP BY status')->fetchAll();
$tkt_labels  = $tkt_rows ? array_column($tkt_rows, 'status') : ['No tickets'];
$tkt_values  = $tkt_rows ? array_column($tkt_rows, 'n')      : [0];

require_once __DIR__ . '/includes/header.php';
?>

<!-- Stat Cards -->
<div class="stat-grid">
  <div class="stat-card">
    <div class="stat-icon green"><i class="fas fa-users"></i></div>
    <div>
      <div class="stat-label">Active Clients</div>
      <div class="stat-value"><?php echo number_format($total_clients); ?></div>
      <div class="stat-sub">Registered accounts</div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-icon navy"><i class="fas fa-box"></i></div>
    <div>
      <div class="stat-label">Active Orders</div>
      <div class="stat-value"><?php echo number_format($total_orders); ?></div>
      <div class="stat-sub"><?php echo $pending_orders; ?> pending activation</div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-icon orange"><i class="fas fa-comments"></i></div>
    <div>
      <div class="stat-label">Open Tickets</div>
      <div class="stat-value"><?php echo number_format($open_tickets); ?></div>
      <div class="stat-sub">Awaiting response</div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-icon green"><i class="fas fa-dollar-sign"></i></div>
    <div>
      <div class="stat-label">Revenue This Month</div>
      <div class="stat-value"><?php echo format_money($month_revenue); ?></div>
      <div class="stat-sub"><?php echo $overdue_invoices; ?> overdue invoice<?php echo $overdue_invoices !== 1 ? 's' : ''; ?></div>
    </div>
  </div>
</div>

<?php if ($pending_orders || $overdue_invoices || $open_tickets > 5): ?>
<div class="alert alert-info" style="margin-bottom:20px">
  <i class="fas fa-bell"></i>
  <?php
    $notes = [];
    if ($pending_orders)   $notes[] = "<strong>$pending_orders</strong> order(s) pending activation";
    if ($overdue_invoices) $notes[] = "<strong>$overdue_invoices</strong> overdue invoice(s)";
    if ($open_tickets > 5) $notes[] = "<strong>$open_tickets</strong> open support tickets";
    echo implode(' &nbsp;·&nbsp; ', $notes);
  ?>
</div>
<?php endif; ?>

<!-- Charts -->
<div class="charts-grid">
  <div class="card">
    <div class="card-header">
      <div class="card-title"><i class="fas fa-chart-line" style="color:var(--green);margin-right:8px"></i>Revenue — Last 6 Months</div>
      <a href="<?php echo APP_URL; ?>/invoices/" class="btn btn-ghost btn-sm">All Invoices</a>
    </div>
    <div class="card-body">
      <canvas id="revenueChart" height="90"></canvas>
    </div>
  </div>
  <div class="card">
    <div class="card-header">
      <div class="card-title"><i class="fas fa-chart-pie" style="color:var(--navy);margin-right:8px"></i>Tickets by Status</div>
    </div>
    <div class="card-body">
      <canvas id="ticketsChart" height="180"></canvas>
    </div>
  </div>
</div>

<!-- Recent activity row -->
<div class="gap-grid">

  <!-- Recent Orders -->
  <div class="table-wrap">
    <div class="card-header">
      <div class="card-title">Recent Orders</div>
      <a href="<?php echo APP_URL; ?>/orders/" class="btn btn-ghost btn-sm">View All</a>
    </div>
    <table>
      <thead><tr>
        <th>Client / Service</th>
        <th>Amount</th>
        <th>Status</th>
      </tr></thead>
      <tbody>
      <?php if ($recent_orders): foreach ($recent_orders as $o): ?>
        <tr>
          <td>
            <div class="td-name"><?php echo h($o['client_name']); ?></div>
            <div class="td-sub"><?php echo h($o['service_name'] ?? '—'); ?></div>
          </td>
          <td><?php echo format_money($o['amount']); ?></td>
          <td><?php echo badge($o['status']); ?></td>
        </tr>
      <?php endforeach; else: ?>
        <tr><td colspan="3" class="empty-state" style="padding:32px;text-align:center;color:var(--text-muted)">No orders yet</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>

  <!-- Recent Tickets -->
  <div class="table-wrap">
    <div class="card-header">
      <div class="card-title">Recent Support Tickets</div>
      <a href="<?php echo APP_URL; ?>/tickets/" class="btn btn-ghost btn-sm">View All</a>
    </div>
    <table>
      <thead><tr>
        <th>Subject</th>
        <th>Priority</th>
        <th>Status</th>
      </tr></thead>
      <tbody>
      <?php if ($recent_tickets): foreach ($recent_tickets as $t): ?>
        <tr>
          <td>
            <a href="<?php echo APP_URL; ?>/tickets/view.php?id=<?php echo $t['id']; ?>"
               style="font-weight:500;color:var(--navy);text-decoration:none;display:block">
              <?php echo h(mb_strimwidth($t['subject'], 0, 42, '…')); ?>
            </a>
            <div class="td-sub"><?php echo h(trim($t['client_name']) ?: 'Guest'); ?> · <?php echo time_ago($t['updated_at']); ?></div>
          </td>
          <td><?php echo badge($t['priority']); ?></td>
          <td><?php echo badge($t['status']); ?></td>
        </tr>
      <?php endforeach; else: ?>
        <tr><td colspan="3" class="empty-state" style="padding:32px;text-align:center;color:var(--text-muted)">No tickets yet</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>

</div>

<!-- Chart.js only on dashboard -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.0/chart.umd.min.js" crossorigin="anonymous"></script>
<script>
Chart.defaults.font.family = "-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif";
Chart.defaults.font.size   = 12;

new Chart(document.getElementById('revenueChart').getContext('2d'), {
  type: 'line',
  data: {
    labels: <?php echo json_encode($rev_labels); ?>,
    datasets: [{
      label: 'Revenue (<?php echo CURRENCY; ?>)',
      data: <?php echo json_encode($rev_amounts); ?>,
      borderColor: '#1A8A45',
      backgroundColor: 'rgba(26,138,69,.07)',
      tension: .35,
      fill: true,
      pointBackgroundColor: '#1A8A45',
      pointRadius: 4,
      pointHoverRadius: 6,
      borderWidth: 2,
    }]
  },
  options: {
    responsive: true,
    plugins: { legend: { display: false } },
    scales: {
      y: {
        beginAtZero: true,
        grid: { color: '#f1f5f9' },
        ticks: { callback: v => '$' + v.toLocaleString() }
      },
      x: { grid: { display: false } }
    }
  }
});

new Chart(document.getElementById('ticketsChart').getContext('2d'), {
  type: 'doughnut',
  data: {
    labels: <?php echo json_encode($tkt_labels); ?>,
    datasets: [{
      data: <?php echo json_encode($tkt_values); ?>,
      backgroundColor: ['#0B1E3D','#1A8A45','#d97706','#dc2626','#64748b'],
      borderWidth: 0,
      hoverOffset: 6,
    }]
  },
  options: {
    responsive: true,
    cutout: '65%',
    plugins: {
      legend: {
        position: 'bottom',
        labels: { boxWidth: 12, padding: 14 }
      }
    }
  }
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
