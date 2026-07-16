<?php
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/providers/Provider.php';
require_once '../includes/Notifier.php';

auth_check();

$id = (int)($_GET['id'] ?? 0);
$stmt = db()->prepare(
    'SELECT cs.*, c.first_name, c.last_name, c.email, c.phone, c.company
     FROM client_services cs LEFT JOIN clients c ON c.id = cs.client_id
     WHERE cs.id = ?'
);
$stmt->execute([$id]);
$svc = $stmt->fetch();

if (!$svc) {
    flash_set('error', 'Service not found.');
    header('Location: ' . APP_URL . '/services/index.php');
    exit;
}

$is_panel  = $svc['provider_category'] === 'panel' && $svc['provider_key'];
$acct_user = $svc['remote_id'] ?: $svc['username'];

function record_action(int $svc_id, string $action, bool $ok, string $message): void
{
    db()->prepare('INSERT INTO service_actions (service_id, admin_id, action, status, message) VALUES (?,?,?,?,?)')
        ->execute([$svc_id, current_admin()['id'], $action, $ok ? 'success' : 'failed', $message]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    try {
        if (!$is_panel && !in_array($action, ['set_status', 'sync'], true)) {
            throw new RuntimeException('This service is not linked to a hosting panel.');
        }
        $panel = $is_panel ? Provider::panel($svc['provider_key']) : null;

        switch ($action) {
            case 'provision':
                $u = trim($_POST['username'] ?? $acct_user);
                $p = $_POST['password'] ?? '';
                $pkg = trim($_POST['package'] ?? ($svc['package'] ?? 'default'));
                if (!$u || !$p || !$svc['domain']) throw new RuntimeException('Username, password and domain are required.');
                db()->prepare('UPDATE client_services SET status="provisioning" WHERE id=?')->execute([$id]);
                $r  = $panel->createAccount(['username'=>$u,'domain'=>$svc['domain'],'password'=>$p,'package'=>$pkg,'email'=>$svc['email']]);
                $ok = !empty($r['success']);
                $server_host = Provider::config($svc['provider_key'])['host'] ?? $svc['server_host'];
                db()->prepare('UPDATE client_services SET status=?, username=?, remote_id=?, package=?, server_host=? WHERE id=?')
                    ->execute([$ok ? 'active' : 'failed', $u, $r['username'] ?? $u, $pkg, $server_host, $id]);
                record_action($id, 'provision', $ok, $r['message'] ?? '');
                flash_set($ok ? 'success' : 'error', $ok ? 'Account provisioned.' : 'Provisioning failed: ' . ($r['message'] ?? ''));
                if ($ok && $svc['client_id']) {
                    Notifier::send('service_ready', (int) $svc['client_id'], [
                        'client_name'   => trim($svc['first_name'] . ' ' . $svc['last_name']),
                        'service_label' => $svc['label'],
                        'account_rows'  => Notifier::serviceAccountRows($svc['domain'] ?? '', $u, $p, (string) $server_host, $pkg),
                        'email'         => $svc['email'],
                        'link'          => portal_base_url() . '/services.php',
                    ]);
                }
                break;

            case 'suspend':
                $reason = trim($_POST['reason'] ?? 'Suspended by admin');
                $r = $panel->suspend($acct_user, $reason);
                if (!empty($r['success'])) db()->prepare('UPDATE client_services SET status="suspended" WHERE id=?')->execute([$id]);
                record_action($id, 'suspend', !empty($r['success']), $r['message'] ?? '');
                flash_set(!empty($r['success']) ? 'success' : 'error', $r['message'] ?? 'Done.');
                if (!empty($r['success']) && $svc['client_id']) {
                    Notifier::send('service_suspended', (int) $svc['client_id'], [
                        'client_name' => trim($svc['first_name'] . ' ' . $svc['last_name']),
                        'service_label' => $svc['label'], 'reason' => $reason,
                        'email' => $svc['email'], 'link' => portal_base_url() . '/services.php',
                    ]);
                }
                break;

            case 'unsuspend':
                $r = $panel->unsuspend($acct_user);
                if (!empty($r['success'])) db()->prepare('UPDATE client_services SET status="active" WHERE id=?')->execute([$id]);
                record_action($id, 'unsuspend', !empty($r['success']), $r['message'] ?? '');
                flash_set(!empty($r['success']) ? 'success' : 'error', $r['message'] ?? 'Done.');
                if (!empty($r['success']) && $svc['client_id']) {
                    Notifier::send('service_unsuspended', (int) $svc['client_id'], [
                        'client_name' => trim($svc['first_name'] . ' ' . $svc['last_name']),
                        'service_label' => $svc['label'],
                        'email' => $svc['email'], 'link' => portal_base_url() . '/services.php',
                    ]);
                }
                break;

            case 'terminate':
                $r = $panel->terminate($acct_user);
                if (!empty($r['success'])) db()->prepare('UPDATE client_services SET status="terminated" WHERE id=?')->execute([$id]);
                record_action($id, 'terminate', !empty($r['success']), $r['message'] ?? '');
                flash_set(!empty($r['success']) ? 'success' : 'error', $r['message'] ?? 'Done.');
                break;

            case 'password':
                $np = $_POST['new_password'] ?? '';
                if (!$np) throw new RuntimeException('Enter a new password.');
                $r = $panel->changePassword($acct_user, $np);
                record_action($id, 'password', !empty($r['success']), $r['message'] ?? '');
                flash_set(!empty($r['success']) ? 'success' : 'error', $r['message'] ?? 'Done.');
                break;

            case 'package':
                $pkg = trim($_POST['package'] ?? '');
                if (!$pkg) throw new RuntimeException('Enter a package name.');
                $r = $panel->changePackage($acct_user, $pkg);
                if (!empty($r['success'])) db()->prepare('UPDATE client_services SET package=? WHERE id=?')->execute([$pkg, $id]);
                record_action($id, 'change_package', !empty($r['success']), $r['message'] ?? '');
                flash_set(!empty($r['success']) ? 'success' : 'error', $r['message'] ?? 'Done.');
                break;

            case 'sync':
                if (!$is_panel) throw new RuntimeException('No panel to sync from.');
                $u = $panel->getUsage($acct_user);
                if (!empty($u['success'])) {
                    db()->prepare('UPDATE client_services SET disk_used_mb=?, disk_limit_mb=?, bw_used_mb=?, last_synced_at=NOW() WHERE id=?')
                        ->execute([$u['disk_used_mb'] ?? 0, $u['disk_limit_mb'] ?? 0, $u['bw_used_mb'] ?? 0, $id]);
                }
                record_action($id, 'sync', !empty($u['success']), $u['message'] ?? 'Usage synced.');
                flash_set(!empty($u['success']) ? 'success' : 'error', $u['message'] ?? 'Usage synced.');
                break;

            case 'set_status':
                $ns = $_POST['new_status'] ?? '';
                if (in_array($ns, ['pending','active','suspended','terminated','cancelled'], true)) {
                    db()->prepare('UPDATE client_services SET status=? WHERE id=?')->execute([$ns, $id]);
                    record_action($id, 'set_status', true, 'Status set to ' . $ns);
                    flash_set('success', 'Status updated to ' . $ns . '.');
                }
                break;
        }
    } catch (\Throwable $e) {
        record_action($id, $action, false, $e->getMessage());
        flash_set('error', $e->getMessage());
    }

    header('Location: ' . APP_URL . '/services/view.php?id=' . $id);
    exit;
}

// Audit trail
$log = db()->prepare('SELECT sa.*, a.name admin_name FROM service_actions sa LEFT JOIN admin_users a ON a.id = sa.admin_id WHERE sa.service_id = ? ORDER BY sa.created_at DESC LIMIT 40');
$log->execute([$id]);
$actions = $log->fetchAll();

$page_title = $svc['label'];
$pct = $svc['disk_limit_mb'] > 0 ? min(100, round($svc['disk_used_mb'] / $svc['disk_limit_mb'] * 100)) : 0;
$mcls = $pct > 90 ? 'crit' : ($pct > 70 ? 'warn' : '');
$reg  = ProviderRegistry::get($svc['provider_key'] ?? '');

require_once '../includes/header.php';
?>

<div class="breadcrumb"><a href="<?php echo APP_URL; ?>/services/">Services</a> <span class="breadcrumb-sep">/</span> <?php echo h($svc['label']); ?></div>

<div class="content-header">
  <div class="flex-gap">
    <?php if ($reg): ?><div class="provider-logo" style="width:44px;height:44px;background:<?php echo h($reg['color']); ?>"><i class="fas <?php echo h($reg['icon']); ?>"></i></div><?php endif; ?>
    <div>
      <h1 class="content-title" style="font-size:20px"><?php echo h($svc['label']); ?></h1>
      <div class="flex-gap" style="margin-top:4px">
        <?php
        $sm = ['active'=>'badge-success','pending'=>'badge-warning','provisioning'=>'badge-primary','suspended'=>'badge-danger','terminated'=>'badge-secondary','failed'=>'badge-danger','cancelled'=>'badge-secondary'];
        ?>
        <span class="badge <?php echo $sm[$svc['status']] ?? 'badge-secondary'; ?>"><?php echo ucfirst($svc['status']); ?></span>
        <?php if ($svc['domain']): ?><span class="text-muted mono" style="font-size:12.5px"><?php echo h($svc['domain']); ?></span><?php endif; ?>
      </div>
    </div>
  </div>
  <a href="<?php echo APP_URL; ?>/services/" class="btn btn-ghost"><i class="fas fa-arrow-left"></i> Back</a>
</div>

<div style="display:grid;grid-template-columns:1fr 340px;gap:18px;align-items:start">

  <!-- Left column -->
  <div style="display:flex;flex-direction:column;gap:18px">

    <!-- Lifecycle actions -->
    <div class="card">
      <div class="card-header"><span class="card-title"><i class="fas fa-bolt"></i> Lifecycle</span>
        <?php if ($is_panel): ?><span class="code-chip"><?php echo h($reg['name'] ?? $svc['provider_key']); ?></span><?php endif; ?>
      </div>
      <div class="card-body">
        <?php if (!$is_panel): ?>
          <div class="alert alert-info mb-16" style="margin:0 0 16px"><i class="fas fa-circle-info"></i> This is a manual service (no panel linked). You can still set its status below.</div>
        <?php endif; ?>

        <div class="flex-gap" style="flex-wrap:wrap;gap:8px">
          <?php if ($is_panel && in_array($svc['status'], ['pending','failed'], true)): ?>
            <button class="btn btn-primary btn-sm" data-drawer-open="drawer-provision"><i class="fas fa-rocket"></i> Provision now</button>
          <?php endif; ?>

          <?php if ($is_panel && $svc['status'] === 'active'): ?>
            <form method="POST" style="margin:0"><input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>"><input type="hidden" name="action" value="suspend">
              <button class="btn btn-ghost btn-sm" data-confirm="Suspend this account?"><i class="fas fa-pause"></i> Suspend</button></form>
          <?php endif; ?>

          <?php if ($is_panel && $svc['status'] === 'suspended'): ?>
            <form method="POST" style="margin:0"><input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>"><input type="hidden" name="action" value="unsuspend">
              <button class="btn btn-primary btn-sm"><i class="fas fa-play"></i> Unsuspend</button></form>
          <?php endif; ?>

          <?php if ($is_panel && in_array($svc['status'], ['active','suspended'], true)): ?>
            <button class="btn btn-ghost btn-sm" data-drawer-open="drawer-password"><i class="fas fa-key"></i> Change password</button>
            <button class="btn btn-ghost btn-sm" data-drawer-open="drawer-package"><i class="fas fa-box"></i> Change package</button>
            <form method="POST" style="margin:0"><input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>"><input type="hidden" name="action" value="sync">
              <button class="btn btn-ghost btn-sm"><i class="fas fa-arrows-rotate"></i> Sync usage</button></form>
          <?php endif; ?>

          <?php if ($is_panel && $svc['status'] !== 'terminated'): ?>
            <form method="POST" style="margin:0"><input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>"><input type="hidden" name="action" value="terminate">
              <button class="btn btn-danger btn-sm" data-confirm="Terminate this account? This permanently deletes it on the server."><i class="fas fa-trash"></i> Terminate</button></form>
          <?php endif; ?>

          <?php if (!$is_panel): ?>
            <form method="POST" class="flex-gap" style="margin:0;gap:8px">
              <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>"><input type="hidden" name="action" value="set_status">
              <select name="new_status" class="form-select" style="width:auto">
                <?php foreach (['pending','active','suspended','terminated','cancelled'] as $s): ?>
                  <option value="<?php echo $s; ?>" <?php echo $svc['status'] === $s ? 'selected' : ''; ?>><?php echo ucfirst($s); ?></option>
                <?php endforeach; ?>
              </select>
              <button class="btn btn-ghost btn-sm">Set status</button>
            </form>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- Usage -->
    <?php if ($is_panel): ?>
    <div class="card">
      <div class="card-header"><span class="card-title"><i class="fas fa-hard-drive"></i> Resource usage</span>
        <span class="text-muted" style="font-size:12px"><?php echo $svc['last_synced_at'] ? 'Synced ' . time_ago($svc['last_synced_at']) : 'Never synced'; ?></span>
      </div>
      <div class="card-body">
        <div class="meter-label"><span>Disk</span><span><?php echo number_format($svc['disk_used_mb']); ?> / <?php echo $svc['disk_limit_mb'] ? number_format($svc['disk_limit_mb']) . ' MB' : '∞'; ?></span></div>
        <div class="meter <?php echo $mcls; ?>" style="margin-bottom:16px"><span style="width:<?php echo $pct; ?>%"></span></div>
        <div class="meter-label"><span>Bandwidth used</span><span><?php echo number_format($svc['bw_used_mb']); ?> MB</span></div>
      </div>
    </div>
    <?php endif; ?>

    <!-- Audit trail -->
    <div class="card">
      <div class="card-header"><span class="card-title"><i class="fas fa-clock-rotate-left"></i> Activity</span></div>
      <div class="card-flush">
        <?php if (!$actions): ?>
          <div class="empty-state" style="padding:32px"><i class="fas fa-clock-rotate-left"></i><p>No actions recorded yet.</p></div>
        <?php else: ?>
          <div style="padding:6px 20px">
          <?php foreach ($actions as $a): ?>
            <div class="data-list"><div class="row">
              <div class="flex-gap">
                <span class="dot <?php echo $a['status'] === 'success' ? 'dot-green' : ($a['status'] === 'failed' ? 'dot-red' : 'dot-amber'); ?>"></span>
                <div>
                  <div class="fw-600" style="font-size:13px"><?php echo h(ucwords(str_replace('_', ' ', $a['action']))); ?></div>
                  <?php if ($a['message']): ?><div class="td-sub"><?php echo h($a['message']); ?></div><?php endif; ?>
                </div>
              </div>
              <div style="text-align:right;font-size:11.5px;color:var(--text-muted)">
                <?php echo time_ago($a['created_at']); ?><br><?php echo h($a['admin_name'] ?? 'system'); ?>
              </div>
            </div></div>
          <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>

  </div>

  <!-- Right column -->
  <div style="display:flex;flex-direction:column;gap:18px">
    <div class="card">
      <div class="card-header"><span class="card-title">Account</span></div>
      <div class="card-body">
        <div class="data-list">
          <div class="row"><span class="k">Client</span><span class="v"><?php echo $svc['first_name'] ? '<a href="' . APP_URL . '/clients/view.php?id=' . $svc['client_id'] . '">' . h($svc['first_name'] . ' ' . $svc['last_name']) . '</a>' : '—'; ?></span></div>
          <div class="row"><span class="k">Email</span><span class="v"><?php echo h($svc['email'] ?? '—'); ?></span></div>
          <div class="row"><span class="k">Category</span><span class="v"><?php echo ucfirst($svc['category']); ?></span></div>
          <?php if ($is_panel): ?>
          <div class="row"><span class="k">Panel</span><span class="v"><?php echo h($reg['name'] ?? $svc['provider_key']); ?></span></div>
          <div class="row"><span class="k">Username</span><span class="v mono"><?php echo h($acct_user ?: '—'); ?></span></div>
          <div class="row"><span class="k">Server</span><span class="v mono" style="font-size:12px"><?php echo h($svc['server_host'] ?: '—'); ?></span></div>
          <div class="row"><span class="k">Package</span><span class="v"><?php echo h($svc['package'] ?: '—'); ?></span></div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div class="card">
      <div class="card-header"><span class="card-title">Billing</span></div>
      <div class="card-body">
        <div class="data-list">
          <div class="row"><span class="k">Amount</span><span class="v"><?php echo format_money((float)$svc['amount']); ?></span></div>
          <div class="row"><span class="k">Cycle</span><span class="v"><?php echo ucfirst(str_replace('_', ' ', $svc['billing_cycle'])); ?></span></div>
          <div class="row"><span class="k">Started</span><span class="v"><?php echo format_date($svc['start_date']); ?></span></div>
          <div class="row"><span class="k">Next due</span><span class="v"><?php echo format_date($svc['next_due_date']); ?></span></div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- ── Action drawers ── -->
<?php if ($is_panel): ?>
  <div class="drawer-scrim" id="drawer-provision-scrim"></div>
  <div class="drawer" id="drawer-provision">
    <div class="drawer-head"><div style="font-weight:700">Provision account</div><button class="drawer-close" data-drawer-close>&times;</button></div>
    <form method="POST" style="display:contents">
      <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>"><input type="hidden" name="action" value="provision">
      <div class="drawer-body">
        <div class="form-group"><label class="form-label">Username <span class="req">*</span></label><input type="text" name="username" class="form-control mono" value="<?php echo h($acct_user); ?>" required></div>
        <div class="form-group"><label class="form-label">Password <span class="req">*</span></label>
          <div class="input-affix"><input type="text" id="provPass" name="password" class="form-control mono" style="padding-right:96px" required><button type="button" class="affix-btn" onclick="var c='abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789!@#$%',p='';for(var i=0;i<16;i++)p+=c[Math.floor(Math.random()*c.length)];document.getElementById('provPass').value=p;">Generate</button></div>
        </div>
        <div class="form-group"><label class="form-label">Package</label><input type="text" name="package" class="form-control" value="<?php echo h($svc['package'] ?: 'default'); ?>"></div>
        <div class="form-group"><label class="form-label">Domain</label><input type="text" class="form-control mono" value="<?php echo h($svc['domain']); ?>" disabled></div>
      </div>
      <div class="drawer-foot"><button class="btn btn-primary"><i class="fas fa-rocket"></i> Provision</button><button type="button" class="btn btn-ghost" data-drawer-close>Cancel</button></div>
    </form>
  </div>

  <div class="drawer-scrim" id="drawer-password-scrim"></div>
  <div class="drawer" id="drawer-password">
    <div class="drawer-head"><div style="font-weight:700">Change password</div><button class="drawer-close" data-drawer-close>&times;</button></div>
    <form method="POST" style="display:contents">
      <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>"><input type="hidden" name="action" value="password">
      <div class="drawer-body"><div class="form-group"><label class="form-label">New password <span class="req">*</span></label><input type="text" name="new_password" class="form-control mono" required></div></div>
      <div class="drawer-foot"><button class="btn btn-primary"><i class="fas fa-key"></i> Update password</button><button type="button" class="btn btn-ghost" data-drawer-close>Cancel</button></div>
    </form>
  </div>

  <div class="drawer-scrim" id="drawer-package-scrim"></div>
  <div class="drawer" id="drawer-package">
    <div class="drawer-head"><div style="font-weight:700">Change package</div><button class="drawer-close" data-drawer-close>&times;</button></div>
    <form method="POST" style="display:contents">
      <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>"><input type="hidden" name="action" value="package">
      <div class="drawer-body"><div class="form-group"><label class="form-label">Package name <span class="req">*</span></label><input type="text" name="package" class="form-control" value="<?php echo h($svc['package']); ?>" required></div></div>
      <div class="drawer-foot"><button class="btn btn-primary"><i class="fas fa-box"></i> Change package</button><button type="button" class="btn btn-ghost" data-drawer-close>Cancel</button></div>
    </form>
  </div>
<?php endif; ?>

<?php require_once '../includes/footer.php'; ?>
