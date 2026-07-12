<?php
require_once '../../includes/config.php';
require_once '../../includes/db.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
require_once '../../includes/WHMClient.php';

auth_check();
$page_title = 'WHM Accounts';

// Load WHM config
$whm_cfg = [];
$whm     = null;
$error   = null;
$accounts = [];
$server_info = [];

try {
    $raw = db()->query("SELECT settings FROM integration_settings WHERE provider='whm'")->fetchColumn();
    $whm_cfg = $raw ? (json_decode($raw, true) ?? []) : [];
} catch (\Throwable $e) {
    $error = 'Cannot read integration settings: ' . htmlspecialchars($e->getMessage())
           . ' — Make sure <code>schema_v2.sql</code> has been imported into your database.';
}

if (!$error && empty($whm_cfg['host'])) {
    $error = 'WHM host is not set. <a href="' . APP_URL . '/integrations/settings.php#whm">Add your WHM host</a>'
           . ' <small style="opacity:.6">(DB row ' . (empty($whm_cfg) ? 'missing — run schema_v2.sql' : 'exists but host is empty') . ')</small>';
} elseif (!$error && empty($whm_cfg['token'])) {
    $error = 'WHM API token is not set. <a href="' . APP_URL . '/integrations/settings.php#whm">Add your API token</a> — generate one in WHM › Development › Manage API Tokens.';
} else {
    try {
        $whm = new WHMClient(
            $whm_cfg['host'],
            $whm_cfg['user'] ?? 'root',
            $whm_cfg['token'],
            (bool)($whm_cfg['ssl_verify'] ?? false)
        );
        $accounts    = $whm->listAccounts();
        $server_info = $whm->getServerLoad();
    } catch (\Throwable $e) {
        $error = 'Connection failed: ' . htmlspecialchars($e->getMessage());
    }
}

// Handle sync action — update disk/bw usage in our DB
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'sync' && $whm) {
    csrf_verify();
    $synced = 0;
    foreach ($accounts as $acc) {
        $user = $acc['user'] ?? '';
        if (!$user) continue;
        $disk = $whm->getDiskInfo($user);
        $bw   = $whm->getBandwidth($user);
        $db_row = db()->prepare('SELECT id FROM whm_accounts WHERE cpanel_user=?');
        $db_row->execute([$user]);
        if ($row = $db_row->fetchColumn()) {
            db()->prepare('UPDATE whm_accounts SET disk_used_mb=?, disk_limit_mb=?, bw_used_mb=?, synced_at=NOW() WHERE id=?')
                ->execute([$disk['disk_used'] ?? 0, $disk['disk_limit'] ?? 0, $bw['bw_used'] ?? 0, $row]);
        }
        $synced++;
    }
    flash_set('success', "Synced {$synced} accounts.");
    header('Location: ' . APP_URL . '/integrations/whm/');
    exit;
}

require_once '../../includes/header.php';
?>

<div class="content-header">
  <h1 class="content-title">WHM Accounts</h1>
  <div style="display:flex;gap:8px">
    <?php if ($whm): ?>
    <form method="POST" style="display:inline">
      <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>" />
      <input type="hidden" name="action"     value="sync" />
      <button type="submit" class="btn btn-ghost"><i class="fas fa-arrows-rotate"></i> Sync Usage</button>
    </form>
    <?php endif; ?>
    <a href="<?php echo APP_URL; ?>/integrations/whm/provision.php" class="btn btn-primary"><i class="fas fa-plus"></i> Provision Account</a>
    <a href="<?php echo APP_URL; ?>/integrations/" class="btn btn-ghost"><i class="fas fa-arrow-left"></i> Back</a>
  </div>
</div>

<?php if ($error): ?>
  <div class="alert alert-danger"><i class="fas fa-triangle-exclamation"></i> <?php echo $error; ?></div>
<?php else: ?>

  <?php if (!empty($server_info)): ?>
  <div class="stat-grid" style="margin-bottom:20px">
    <div class="stat-card">
      <div class="stat-icon"><i class="fas fa-microchip"></i></div>
      <div>
        <div class="stat-label">Server Load</div>
        <div class="stat-value"><?php echo number_format($server_info['load1'] ?? 0, 2); ?></div>
      </div>
    </div>
    <div class="stat-card">
      <div class="stat-icon"><i class="fas fa-memory"></i></div>
      <div>
        <div class="stat-label">Memory Used</div>
        <div class="stat-value"><?php echo number_format(($server_info['mem_used'] ?? 0) / 1024, 0); ?> MB</div>
      </div>
    </div>
    <div class="stat-card">
      <div class="stat-icon"><i class="fas fa-users"></i></div>
      <div>
        <div class="stat-label">cPanel Accounts</div>
        <div class="stat-value"><?php echo count($accounts); ?></div>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <div class="card">
    <div class="card-header">
      <span class="card-title">All cPanel Accounts</span>
      <span style="font-size:12px;color:var(--text-muted)"><?php echo count($accounts); ?> accounts on server</span>
    </div>
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>Username</th>
            <th>Domain</th>
            <th>Package</th>
            <th>Disk</th>
            <th>Email</th>
            <th>Status</th>
            <th>Created</th>
          </tr>
        </thead>
        <tbody>
          <?php if ($accounts): foreach ($accounts as $acc): ?>
            <tr>
              <td><strong><?php echo htmlspecialchars($acc['user'] ?? '-'); ?></strong></td>
              <td><?php echo htmlspecialchars($acc['domain'] ?? '-'); ?></td>
              <td><?php echo htmlspecialchars($acc['plan'] ?? '-'); ?></td>
              <td>
                <?php
                $used  = $acc['diskused']  ?? 0;
                $limit = $acc['disklimit'] ?? 0;
                $pct   = $limit > 0 ? min(100, round($used / $limit * 100)) : 0;
                ?>
                <div style="font-size:12px;margin-bottom:3px"><?php echo $used; ?> / <?php echo $limit ?: '∞'; ?> MB</div>
                <?php if ($limit > 0): ?>
                <div style="background:#e5e7eb;border-radius:99px;height:5px;width:80px">
                  <div style="background:<?php echo $pct > 90 ? 'var(--danger)' : ($pct > 70 ? 'var(--warning)' : 'var(--success)'); ?>;height:5px;border-radius:99px;width:<?php echo $pct; ?>%"></div>
                </div>
                <?php endif; ?>
              </td>
              <td><?php echo htmlspecialchars($acc['email'] ?? '-'); ?></td>
              <td>
                <?php if (!empty($acc['suspended'])): ?>
                  <span class="badge badge-danger">Suspended</span>
                <?php else: ?>
                  <span class="badge badge-success">Active</span>
                <?php endif; ?>
              </td>
              <td style="font-size:12px;color:var(--text-muted)"><?php echo htmlspecialchars($acc['startdate'] ?? '-'); ?></td>
            </tr>
          <?php endforeach; else: ?>
            <tr><td colspan="7" style="text-align:center;padding:32px;color:var(--text-muted)">No accounts found on this server.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

<?php endif; ?>

<?php require_once '../../includes/footer.php'; ?>
