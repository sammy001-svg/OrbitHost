<?php
require_once '../../includes/config.php';
require_once '../../includes/db.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
require_once '../../includes/WHMClient.php';

auth_check();
$page_title = 'WHM Packages';

// Load WHM config
$whm_cfg = db()->query("SELECT settings FROM integration_settings WHERE provider='whm'")->fetchColumn();
$whm_cfg = $whm_cfg ? json_decode($whm_cfg, true) : [];

$whm      = null;
$error    = null;
$packages = [];

if (empty($whm_cfg['host']) || empty($whm_cfg['token'])) {
    $error = 'WHM is not configured. <a href="' . APP_URL . '/integrations/#prov-whm">Configure it in Providers</a>.';
} else {
    $whm = new WHMClient(
        $whm_cfg['host'],
        $whm_cfg['user'] ?? 'root',
        $whm_cfg['token'],
        (bool)($whm_cfg['ssl_verify'] ?? false)
    );
}

// Limit fields shared by the create/edit forms (WHM addpkg/editpkg params)
$limit_fields = [
    'quota'    => ['Disk quota',        'MB'],
    'bwlimit'  => ['Bandwidth',         'MB / month'],
    'maxpop'   => ['Email accounts',    ''],
    'maxsql'   => ['MySQL databases',   ''],
    'maxsub'   => ['Subdomains',        ''],
    'maxaddon' => ['Addon domains',     ''],
    'maxpark'  => ['Parked domains',    ''],
    'maxftp'   => ['FTP accounts',      ''],
];

if ($whm && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'create' || $action === 'edit') {
            $name = trim($_POST['name'] ?? '');
            if ($name === '') throw new RuntimeException('Package name is required.');
            $opts = ['name' => $name];
            foreach (array_keys($limit_fields) as $f) {
                $v = trim($_POST[$f] ?? '');
                $opts[$f] = ($v === '' ? 'unlimited' : (string)(int)$v);
            }
            $r  = $action === 'create' ? $whm->addPackage($opts) : $whm->editPackage($opts);
            $ok = (int)($r['metadata']['result'] ?? 0) === 1;
            $msg = $r['metadata']['reason'] ?? ($ok ? 'Done.' : 'WHM rejected the request.');
            flash_set($ok ? 'success' : 'error', ($action === 'create' ? 'Create package: ' : 'Update package: ') . $msg);
            if ($ok) log_activity('whm_pkg_' . $action, 'integration', 0, $name);

        } elseif ($action === 'delete') {
            $name = trim($_POST['name'] ?? '');
            $r  = $whm->deletePackage($name);
            $ok = (int)($r['metadata']['result'] ?? 0) === 1;
            flash_set($ok ? 'success' : 'error', 'Delete package: ' . ($r['metadata']['reason'] ?? ($ok ? 'Deleted.' : 'Failed.')));
            if ($ok) log_activity('whm_pkg_delete', 'integration', 0, $name);
        }
    } catch (\Throwable $e) {
        flash_set('error', $e->getMessage());
    }

    header('Location: ' . APP_URL . '/integrations/whm/packages.php');
    exit;
}

if ($whm && !$error) {
    try {
        $packages = $whm->listPackages();
    } catch (\Throwable $e) {
        $error = 'Connection failed: ' . h($e->getMessage());
        $whm = null;
    }
}

// Display helper: WHM sends numbers or the string "unlimited"
function pkg_val($v, string $unit = ''): string
{
    if ($v === null || $v === '') return '—';
    if (strtolower((string)$v) === 'unlimited') return '∞';
    return number_format((float)$v) . ($unit ? ' ' . $unit : '');
}
// Form helper: blank means unlimited
function pkg_form_val($v): string
{
    return ($v === null || strtolower((string)$v) === 'unlimited') ? '' : (string)(int)$v;
}

require_once '../../includes/header.php';
?>

<div class="breadcrumb"><a href="<?php echo APP_URL; ?>/integrations/whm/">WHM / Servers</a> <span class="breadcrumb-sep">/</span> Packages</div>

<div class="content-header">
  <div>
    <h1 class="content-title">Hosting Packages</h1>
    <p class="page-subtitle">Packages on your WHM server. Link them to your website plans in <a href="<?php echo APP_URL; ?>/plans/" style="color:var(--green);font-weight:600">Plans &amp; Packages</a> for seamless provisioning.</p>
  </div>
  <div class="page-header-actions">
    <?php if ($whm): ?><button class="btn btn-primary" data-drawer-open="drawer-pkg-new"><i class="fas fa-plus"></i> Create Package</button><?php endif; ?>
    <a href="<?php echo APP_URL; ?>/integrations/whm/" class="btn btn-ghost"><i class="fas fa-arrow-left"></i> Back</a>
  </div>
</div>

<?php if ($error): ?>
  <div class="alert alert-danger"><i class="fas fa-triangle-exclamation"></i> <?php echo $error; ?></div>
<?php else: ?>

<div class="table-wrap">
  <div class="table-toolbar">
    <span class="card-title">Server packages</span>
    <span class="table-count"><?php echo count($packages); ?> package<?php echo count($packages) === 1 ? '' : 's'; ?></span>
  </div>
  <div class="table-scroll">
  <table>
    <thead>
      <tr>
        <th>Package</th>
        <th>Disk</th>
        <th>Bandwidth</th>
        <th>Email</th>
        <th>Databases</th>
        <th>Subdomains</th>
        <th>Addon</th>
        <th>FTP</th>
        <th style="text-align:right">Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php if (!$packages): ?>
        <tr><td colspan="9"><div class="empty-state"><i class="fas fa-cubes"></i><p>No packages on this server yet. Create your first one.</p></div></td></tr>
      <?php else: foreach ($packages as $i => $p): ?>
        <tr>
          <td><div class="td-name"><i class="fas fa-cube" style="color:var(--green);margin-right:6px"></i><?php echo h($p['name'] ?? '-'); ?></div></td>
          <td><?php echo pkg_val($p['QUOTA']   ?? null, 'MB'); ?></td>
          <td><?php echo pkg_val($p['BWLIMIT'] ?? null, 'MB'); ?></td>
          <td><?php echo pkg_val($p['MAXPOP']  ?? null); ?></td>
          <td><?php echo pkg_val($p['MAXSQL']  ?? null); ?></td>
          <td><?php echo pkg_val($p['MAXSUB']  ?? null); ?></td>
          <td><?php echo pkg_val($p['MAXADDON'] ?? null); ?></td>
          <td><?php echo pkg_val($p['MAXFTP']  ?? null); ?></td>
          <td>
            <div class="actions" style="justify-content:flex-end">
              <button class="action-link edit" data-drawer-open="drawer-pkg-<?php echo $i; ?>"><i class="fas fa-pen"></i> Edit</button>
              <form method="POST" style="margin:0">
                <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>" />
                <input type="hidden" name="action" value="delete" />
                <input type="hidden" name="name" value="<?php echo h($p['name'] ?? ''); ?>" />
                <button type="submit" class="action-link danger" data-confirm="Delete package '<?php echo h($p['name'] ?? ''); ?>' from WHM? Accounts using it are not deleted."><i class="fas fa-trash"></i></button>
              </form>
            </div>
          </td>
        </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>
  </div>
</div>

<!-- Edit drawers (one per package) -->
<?php foreach ($packages as $i => $p): ?>
  <div class="drawer-scrim" id="drawer-pkg-<?php echo $i; ?>-scrim"></div>
  <div class="drawer" id="drawer-pkg-<?php echo $i; ?>">
    <div class="drawer-head">
      <div><div style="font-weight:700"><?php echo h($p['name'] ?? ''); ?></div>
      <div class="text-muted" style="font-size:11.5px">Edit WHM package</div></div>
      <button type="button" class="drawer-close" data-drawer-close>&times;</button>
    </div>
    <form method="POST" style="display:contents">
      <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>" />
      <input type="hidden" name="action" value="edit" />
      <input type="hidden" name="name" value="<?php echo h($p['name'] ?? ''); ?>" />
      <div class="drawer-body">
        <div class="alert alert-info" style="margin-bottom:16px;font-size:12.5px"><i class="fas fa-circle-info"></i> Leave a field blank for unlimited.</div>
        <?php
        $vals = ['quota'=>$p['QUOTA'] ?? '', 'bwlimit'=>$p['BWLIMIT'] ?? '', 'maxpop'=>$p['MAXPOP'] ?? '', 'maxsql'=>$p['MAXSQL'] ?? '',
                 'maxsub'=>$p['MAXSUB'] ?? '', 'maxaddon'=>$p['MAXADDON'] ?? '', 'maxpark'=>$p['MAXPARK'] ?? '', 'maxftp'=>$p['MAXFTP'] ?? ''];
        foreach ($limit_fields as $f => [$label, $unit]): ?>
          <div class="form-group">
            <label class="form-label"><?php echo $label; ?><?php echo $unit ? ' <span class="text-muted" style="font-weight:400">(' . $unit . ')</span>' : ''; ?></label>
            <input type="number" min="0" name="<?php echo $f; ?>" class="form-control" placeholder="Unlimited" value="<?php echo pkg_form_val($vals[$f]); ?>" />
          </div>
        <?php endforeach; ?>
      </div>
      <div class="drawer-foot">
        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Package</button>
        <button type="button" class="btn btn-ghost" data-drawer-close>Cancel</button>
      </div>
    </form>
  </div>
<?php endforeach; ?>

<!-- Create drawer -->
<div class="drawer-scrim" id="drawer-pkg-new-scrim"></div>
<div class="drawer" id="drawer-pkg-new">
  <div class="drawer-head">
    <div><div style="font-weight:700">Create Package</div>
    <div class="text-muted" style="font-size:11.5px">Added directly to your WHM server</div></div>
    <button type="button" class="drawer-close" data-drawer-close>&times;</button>
  </div>
  <form method="POST" style="display:contents">
    <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>" />
    <input type="hidden" name="action" value="create" />
    <div class="drawer-body">
      <div class="form-group">
        <label class="form-label">Package name <span class="req">*</span></label>
        <input type="text" name="name" class="form-control" placeholder="e.g. orbit_business" required />
        <small class="form-hint">Letters, numbers and underscores. This is the name used when creating cPanel accounts.</small>
      </div>
      <div class="alert alert-info" style="margin-bottom:16px;font-size:12.5px"><i class="fas fa-circle-info"></i> Leave a field blank for unlimited.</div>
      <?php foreach ($limit_fields as $f => [$label, $unit]): ?>
        <div class="form-group">
          <label class="form-label"><?php echo $label; ?><?php echo $unit ? ' <span class="text-muted" style="font-weight:400">(' . $unit . ')</span>' : ''; ?></label>
          <input type="number" min="0" name="<?php echo $f; ?>" class="form-control" placeholder="Unlimited" />
        </div>
      <?php endforeach; ?>
    </div>
    <div class="drawer-foot">
      <button type="submit" class="btn btn-primary"><i class="fas fa-plus"></i> Create on WHM</button>
      <button type="button" class="btn btn-ghost" data-drawer-close>Cancel</button>
    </div>
  </form>
</div>

<?php endif; ?>

<?php require_once '../../includes/footer.php'; ?>
