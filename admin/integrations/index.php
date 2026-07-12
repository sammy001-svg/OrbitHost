<?php
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

auth_check();
$page_title = 'Integrations';

// Load all settings rows
$settings_rows = db()->query('SELECT provider, settings, updated_at FROM integration_settings ORDER BY provider')->fetchAll();
$settings = [];
foreach ($settings_rows as $row) {
    $settings[$row['provider']] = [
        'data'       => json_decode($row['settings'], true) ?? [],
        'updated_at' => $row['updated_at'],
    ];
}

// Quick connectivity tests
$statuses = [];

// WHM ping
if (!empty($settings['whm']['data']['host']) && !empty($settings['whm']['data']['token'])) {
    try {
        require_once '../includes/WHMClient.php';
        $whm = new WHMClient(
            $settings['whm']['data']['host'],
            $settings['whm']['data']['user'] ?? 'root',
            $settings['whm']['data']['token'],
            (bool)($settings['whm']['data']['ssl_verify'] ?? false)
        );
        $ping = $whm->ping();
        $statuses['whm'] = $ping ? 'connected' : 'error';
    } catch (\Throwable $e) {
        $statuses['whm'] = 'error';
    }
} else {
    $statuses['whm'] = 'not_configured';
}

// Domain providers — just check if credentials exist
foreach (['namecheap', 'godaddy'] as $prov) {
    if (!empty($settings[$prov]['data'])) {
        $d = $settings[$prov]['data'];
        $has_creds = $prov === 'namecheap'
            ? !empty($d['api_user']) && !empty($d['api_key'])
            : !empty($d['api_key']) && !empty($d['api_secret']);
        $statuses[$prov] = $has_creds ? 'configured' : 'not_configured';
    } else {
        $statuses[$prov] = 'not_configured';
    }
}

// SMTP
$statuses['smtp'] = !empty($settings['smtp']['data']['host']) ? 'configured' : 'not_configured';

require_once '../includes/header.php';

function status_badge(string $s): string {
    $map = [
        'connected'      => ['green',  'fa-circle-check',     'Connected'],
        'configured'     => ['blue',   'fa-circle-check',     'Configured'],
        'not_configured' => ['gray',   'fa-circle',           'Not Configured'],
        'error'          => ['red',    'fa-triangle-exclamation', 'Connection Error'],
    ];
    [$color, $icon, $label] = $map[$s] ?? ['gray', 'fa-circle', ucfirst($s)];
    $hex = ['green' => '16a34a', 'blue' => '2563eb', 'red' => 'dc2626', 'gray' => '6b7280'][$color] ?? '6b7280';
    return "<span class=\"badge\" style=\"background:#{$hex}20;color:#{$hex};border:1px solid currentColor\"><i class=\"fas {$icon}\" style=\"font-size:10px\"></i> {$label}</span>";
}
?>

<div class="content-header">
  <h1 class="content-title">Integrations</h1>
  <a href="<?php echo APP_URL; ?>/admin/integrations/settings.php" class="btn btn-primary"><i class="fas fa-gear"></i> Settings</a>
</div>

<div class="stat-grid" style="margin-bottom:28px">
  <?php
  $cards = [
    ['WHM / cPanel', 'fa-server',   $statuses['whm'],       APP_URL . '/integrations/whm/'],
    ['Namecheap',   'fa-globe',    $statuses['namecheap'], APP_URL . '/integrations/domains/'],
    ['GoDaddy',     'fa-globe',    $statuses['godaddy'],   APP_URL . '/integrations/domains/'],
    ['SMTP Email',  'fa-envelope', $statuses['smtp'],      APP_URL . '/integrations/settings.php#smtp'],
  ];
  foreach ($cards as [$title, $icon, $status, $link]):
  ?>
    <a href="<?php echo $link; ?>" class="stat-card" style="text-decoration:none">
      <div class="stat-icon"><i class="fas <?php echo $icon; ?>"></i></div>
      <div>
        <div class="stat-label"><?php echo $title; ?></div>
        <div class="stat-value" style="font-size:14px;margin-top:4px"><?php echo status_badge($status); ?></div>
      </div>
    </a>
  <?php endforeach; ?>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px">

  <!-- WHM -->
  <div class="card">
    <div class="card-header">
      <span class="card-title"><i class="fas fa-server"></i> WHM / cPanel</span>
      <a href="<?php echo APP_URL; ?>/integrations/whm/" class="btn btn-ghost btn-sm">Manage</a>
    </div>
    <div class="card-body" style="padding:0">
      <?php if (!empty($settings['whm']['data']['host'])): $w = $settings['whm']['data']; ?>
        <?php $rows = [['Host', $w['host']], ['User', $w['user'] ?? 'root'], ['SSL Verify', empty($w['ssl_verify']) ? 'Off' : 'On']]; ?>
        <?php foreach ($rows as [$k, $v]): ?>
          <div style="display:flex;justify-content:space-between;padding:10px 16px;border-bottom:1px solid var(--border);font-size:13px">
            <span style="color:var(--text-muted)"><?php echo $k; ?></span>
            <span style="font-weight:500"><?php echo htmlspecialchars($v); ?></span>
          </div>
        <?php endforeach; ?>
        <div style="padding:12px 16px">
          <span class="badge badge-info" style="font-size:11px">Last updated <?php echo time_ago($settings['whm']['updated_at']); ?></span>
        </div>
      <?php else: ?>
        <div style="padding:24px;text-align:center;color:var(--text-muted);font-size:14px">
          <i class="fas fa-plug" style="font-size:24px;margin-bottom:8px;display:block"></i>
          Not configured. <a href="<?php echo APP_URL; ?>/integrations/settings.php#whm">Configure WHM</a>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Domain providers -->
  <div class="card">
    <div class="card-header">
      <span class="card-title"><i class="fas fa-globe"></i> Domain Registrars</span>
      <a href="<?php echo APP_URL; ?>/integrations/domains/" class="btn btn-ghost btn-sm">Manage</a>
    </div>
    <div class="card-body" style="padding:0">
      <?php foreach (['namecheap' => 'Namecheap', 'godaddy' => 'GoDaddy'] as $prov => $label): ?>
        <div style="display:flex;justify-content:space-between;align-items:center;padding:12px 16px;border-bottom:1px solid var(--border);font-size:13px">
          <span style="font-weight:500"><?php echo $label; ?></span>
          <?php echo status_badge($statuses[$prov]); ?>
        </div>
      <?php endforeach; ?>
      <div style="padding:12px 16px">
        <a href="<?php echo APP_URL; ?>/integrations/domains/check.php" class="btn btn-ghost btn-sm"><i class="fas fa-magnifying-glass"></i> Check Domain</a>
      </div>
    </div>
  </div>

</div>

<?php require_once '../includes/footer.php'; ?>
