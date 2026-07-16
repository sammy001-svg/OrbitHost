<?php
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/providers/Provider.php';
require_once '../includes/Currency.php';

auth_check();
$page_title = 'Plans & Packages';
Currency::ensureSchema();

// ── Ensure the plan↔panel linking columns exist (auto-migration) ──
$link_ok = true;
try {
    $col = db()->query("SHOW COLUMNS FROM services LIKE 'panel_package'")->fetch();
    if (!$col) {
        db()->exec("ALTER TABLE services
            ADD COLUMN panel_provider VARCHAR(50)  DEFAULT NULL,
            ADD COLUMN panel_package  VARCHAR(100) DEFAULT NULL");
    }
} catch (\Throwable $e) {
    $link_ok = false; // no ALTER privilege — page still works without linking
}

// ── Plan details columns: description + feature list (auto-migration) ──
$details_ok = true;
try {
    $col = db()->query("SHOW COLUMNS FROM services LIKE 'description'")->fetch();
    if (!$col) {
        db()->exec("ALTER TABLE services
            ADD COLUMN description TEXT DEFAULT NULL,
            ADD COLUMN features    TEXT DEFAULT NULL");
    }
} catch (\Throwable $e) {
    $details_ok = false;
}

// ── Active hosting panel + its live package list ──
$panel_key = null; $panel_packages = []; $panel_err = null;
try {
    $panel_key = Provider::activeFor('panel');
    if ($panel_key) {
        $res = Provider::panel($panel_key)->listPackages();
        if (!empty($res['success'])) {
            foreach (($res['packages'] ?? []) as $p) {
                if (!empty($p['name'])) $panel_packages[] = $p['name'];
            }
        } else {
            $panel_err = $res['message'] ?? 'Could not load packages.';
        }
    }
} catch (\Throwable $e) {
    $panel_err = $e->getMessage();
}

$categories = ['shared','vps','dedicated','cloud','wordpress','reseller','ssl','email','domain'];
$cycles     = ['monthly' => 'Monthly', 'annual' => 'Annual', 'one_time' => 'One-time'];

// ── Save (add / edit / delete) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    if (($_POST['action'] ?? '') === 'delete') {
        $id   = (int)($_POST['id'] ?? 0);
        $name = (string) db()->query('SELECT name FROM services WHERE id = ' . $id)->fetchColumn();
        if ($id && $name !== '') {
            // orders/client_services reference plans with ON DELETE SET NULL,
            // so existing client services keep working — the plan just leaves
            // the catalogue.
            db()->prepare('DELETE FROM services WHERE id = ?')->execute([$id]);
            log_activity('plan_delete', 'service', $id, $name);
            flash_set('success', 'Plan "' . $name . '" deleted. Existing client services on it are unaffected.');
        }
        header('Location: ' . APP_URL . '/plans/index.php');
        exit;
    }

    $id       = (int)($_POST['id'] ?? 0);
    $name     = trim($_POST['name'] ?? '');
    $category = in_array($_POST['category'] ?? '', $categories, true) ? $_POST['category'] : 'shared';
    $cycle    = array_key_exists($_POST['billing_cycle'] ?? '', $cycles) ? $_POST['billing_cycle'] : 'monthly';
    $price    = (float)($_POST['price'] ?? 0);
    $setup    = (float)($_POST['setup_fee'] ?? 0);
    $price_kes = (float)($_POST['price_kes'] ?? 0);
    $setup_kes = (float)($_POST['setup_fee_kes'] ?? 0);
    $active   = !empty($_POST['is_active']) ? 1 : 0;
    $package  = trim($_POST['panel_package'] ?? '');
    $desc     = trim($_POST['description'] ?? '');
    // features: one per line, cleaned
    $features = implode("\n", array_filter(array_map('trim', explode("\n", $_POST['features'] ?? ''))));

    if ($name === '') {
        flash_set('error', 'Plan name is required.');
    } else {
        $sets   = ['name=?', 'category=?', 'billing_cycle=?', 'price=?', 'setup_fee=?', 'price_kes=?', 'setup_fee_kes=?', 'is_active=?'];
        $vals   = [$name, $category, $cycle, $price, $setup, $price_kes, $setup_kes, $active];
        $cols   = ['name', 'category', 'billing_cycle', 'price', 'setup_fee', 'price_kes', 'setup_fee_kes', 'is_active'];
        if ($link_ok) {
            $sets[] = 'panel_provider=?'; $sets[] = 'panel_package=?';
            $cols[] = 'panel_provider';   $cols[] = 'panel_package';
            $vals[] = $package !== '' ? ($panel_key ?: 'whm') : null;
            $vals[] = $package ?: null;
        }
        if ($details_ok) {
            $sets[] = 'description=?'; $sets[] = 'features=?';
            $cols[] = 'description';   $cols[] = 'features';
            $vals[] = $desc ?: null;
            $vals[] = $features ?: null;
        }
        if ($id) {
            db()->prepare('UPDATE services SET ' . implode(', ', $sets) . ' WHERE id=?')->execute([...$vals, $id]);
        } else {
            db()->prepare('INSERT INTO services (' . implode(', ', $cols) . ') VALUES (' . rtrim(str_repeat('?,', count($cols)), ',') . ')')->execute($vals);
            $id = (int) db()->lastInsertId();
        }
        log_activity('plan_save', 'service', $id, $name);
        flash_set('success', 'Plan "' . $name . '" saved.');
    }

    header('Location: ' . APP_URL . '/plans/index.php');
    exit;
}

// ── Load catalogue ──
$sel = 'SELECT id, name, category, billing_cycle, price, setup_fee, price_kes, setup_fee_kes, is_active'
     . ($link_ok    ? ', panel_provider, panel_package' : ', NULL AS panel_provider, NULL AS panel_package')
     . ($details_ok ? ', description, features'         : ', NULL AS description, NULL AS features')
     . ' FROM services ORDER BY category, price';
$plans = db()->query($sel)->fetchAll();

$linked = count(array_filter($plans, fn($p) => !empty($p['panel_package'])));

require_once '../includes/header.php';
?>

<div class="content-header">
  <div>
    <h1 class="content-title">Plans &amp; Packages</h1>
    <p class="page-subtitle">Your website's plan catalogue. Link each plan to a WHM package so creating a service provisions the right cPanel account automatically.</p>
  </div>
  <div class="page-header-actions">
    <a href="<?php echo APP_URL; ?>/integrations/whm/packages.php" class="btn btn-ghost"><i class="fas fa-cubes"></i> WHM Packages</a>
    <button class="btn btn-primary plan-open" data-drawer-open="drawer-plan"
            data-plan='{"id":0,"name":"","category":"shared","billing_cycle":"monthly","price":"","setup_fee":"0","price_kes":"","setup_fee_kes":"0","is_active":1,"panel_package":"","description":"","features":""}'>
      <i class="fas fa-plus"></i> Add Plan
    </button>
  </div>
</div>

<?php if (!$link_ok): ?>
  <div class="alert alert-warning"><i class="fas fa-triangle-exclamation"></i>
    Could not add the package-link columns automatically. Import <code>admin/install/schema_v4.sql</code> in phpMyAdmin to enable WHM package linking. Plan editing still works.
  </div>
<?php endif; ?>
<?php if ($panel_key && $panel_err): ?>
  <div class="alert alert-warning"><i class="fas fa-triangle-exclamation"></i>
    Could not load packages from your hosting panel: <?php echo h($panel_err); ?> — you can still type a package name manually.
  </div>
<?php elseif (!$panel_key): ?>
  <div class="alert alert-info"><i class="fas fa-circle-info"></i>
    No hosting panel is active. Enable WHM in <a href="<?php echo APP_URL; ?>/integrations/#prov-whm" style="font-weight:600">Providers</a> to pick packages from a live list.
  </div>
<?php endif; ?>

<div class="stat-grid" style="grid-template-columns:repeat(3,1fr)">
  <div class="stat-card">
    <div class="stat-icon navy"><i class="fas fa-tags"></i></div>
    <div><div class="stat-label">Website plans</div><div class="stat-value"><?php echo count($plans); ?></div></div>
  </div>
  <div class="stat-card">
    <div class="stat-icon green"><i class="fas fa-link"></i></div>
    <div><div class="stat-label">Linked to WHM</div><div class="stat-value"><?php echo $linked; ?></div></div>
  </div>
  <div class="stat-card">
    <div class="stat-icon orange"><i class="fas fa-cubes"></i></div>
    <div><div class="stat-label">Packages on server</div><div class="stat-value"><?php echo count($panel_packages); ?></div></div>
  </div>
</div>

<div class="table-wrap">
  <div class="table-toolbar">
    <span class="card-title">Plan catalogue</span>
    <span class="table-count"><?php echo count($plans); ?> plans</span>
  </div>
  <div class="table-scroll">
  <table>
    <thead>
      <tr>
        <th>Plan</th>
        <th>Cycle</th>
        <th>Price (USD)</th>
        <th>Price (KES)</th>
        <th>Setup fee</th>
        <th>WHM package</th>
        <th>Status</th>
        <th style="text-align:right">Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php if (!$plans): ?>
        <tr><td colspan="8"><div class="empty-state"><i class="fas fa-tags"></i><p>No plans yet. Add your first plan.</p></div></td></tr>
      <?php else: foreach ($plans as $p):
        $pkg      = $p['panel_package'] ?? '';
        $stale    = $pkg && $panel_packages && !in_array($pkg, $panel_packages, true);
        $json     = htmlspecialchars(json_encode([
            'id' => (int)$p['id'], 'name' => $p['name'], 'category' => $p['category'],
            'billing_cycle' => $p['billing_cycle'], 'price' => $p['price'], 'setup_fee' => $p['setup_fee'],
            'price_kes' => $p['price_kes'], 'setup_fee_kes' => $p['setup_fee_kes'],
            'is_active' => (int)$p['is_active'], 'panel_package' => $pkg,
            'description' => (string)($p['description'] ?? ''), 'features' => (string)($p['features'] ?? ''),
        ], JSON_UNESCAPED_SLASHES), ENT_QUOTES);
      ?>
        <tr>
          <td>
            <div class="td-name"><?php echo h($p['name']); ?></div>
            <div class="td-sub"><?php echo ucfirst($p['category']); ?></div>
          </td>
          <td><?php echo badge($p['billing_cycle']); ?></td>
          <td class="fw-600">$<?php echo number_format((float)$p['price'], 2); ?></td>
          <td class="fw-600">KSh <?php echo number_format((float)($p['price_kes'] ?? 0), 2); ?></td>
          <td><?php echo (float)$p['setup_fee'] > 0 ? format_money((float)$p['setup_fee']) : '<span class="text-muted">—</span>'; ?></td>
          <td>
            <?php if ($pkg): ?>
              <span class="code-chip"><i class="fas fa-link" style="font-size:10px"></i> <?php echo h($pkg); ?></span>
              <?php if ($stale): ?><i class="fas fa-triangle-exclamation" style="color:var(--warning)" title="This package no longer exists on the WHM server"></i><?php endif; ?>
            <?php else: ?>
              <span class="text-muted" style="font-size:12px">Not linked</span>
            <?php endif; ?>
          </td>
          <td><?php echo $p['is_active'] ? '<span class="badge badge-success">Active</span>' : '<span class="badge badge-secondary">Hidden</span>'; ?></td>
          <td>
            <div class="actions" style="justify-content:flex-end">
              <button class="action-link edit plan-open" data-drawer-open="drawer-plan" data-plan="<?php echo $json; ?>"><i class="fas fa-pen"></i> Edit</button>
              <form method="POST" style="margin:0">
                <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>" />
                <input type="hidden" name="action" value="delete" />
                <input type="hidden" name="id" value="<?php echo (int)$p['id']; ?>" />
                <button type="submit" class="action-link danger" data-confirm="Delete plan &quot;<?php echo h($p['name']); ?>&quot; from the catalogue? Existing client services keep running."><i class="fas fa-trash"></i></button>
              </form>
            </div>
          </td>
        </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>
  </div>
</div>

<!-- ── Single add/edit drawer, populated by JS ── -->
<div class="drawer-scrim" id="drawer-plan-scrim"></div>
<div class="drawer" id="drawer-plan">
  <div class="drawer-head">
    <div><div style="font-weight:700" id="planDrawerTitle">Plan</div>
    <div class="text-muted" style="font-size:11.5px">Website plan catalogue</div></div>
    <button type="button" class="drawer-close" data-drawer-close>&times;</button>
  </div>
  <form method="POST" style="display:contents">
    <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>" />
    <input type="hidden" name="id" id="planId" value="0" />
    <div class="drawer-body">
      <div class="form-group">
        <label class="form-label">Plan name <span class="req">*</span></label>
        <input type="text" name="name" id="planName" class="form-control" required />
      </div>
      <div class="form-grid-2">
        <div class="form-group">
          <label class="form-label">Category</label>
          <select name="category" id="planCategory" class="form-select">
            <?php foreach ($categories as $c): ?><option value="<?php echo $c; ?>"><?php echo ucfirst($c); ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Billing cycle</label>
          <select name="billing_cycle" id="planCycle" class="form-select">
            <?php foreach ($cycles as $v => $l): ?><option value="<?php echo $v; ?>"><?php echo $l; ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Price (USD)</label>
          <input type="number" step="0.01" min="0" name="price" id="planPrice" class="form-control" />
        </div>
        <div class="form-group">
          <label class="form-label">Price (KES)</label>
          <input type="number" step="0.01" min="0" name="price_kes" id="planPriceKes" class="form-control" />
          <small class="form-hint">Shown to visitors detected in Kenya, or who switch the site to KSh.</small>
        </div>
        <div class="form-group">
          <label class="form-label">Setup fee (USD)</label>
          <input type="number" step="0.01" min="0" name="setup_fee" id="planSetup" class="form-control" />
        </div>
        <div class="form-group">
          <label class="form-label">Setup fee (KES)</label>
          <input type="number" step="0.01" min="0" name="setup_fee_kes" id="planSetupKes" class="form-control" />
        </div>
      </div>

      <p class="form-section-title" style="margin-top:10px">Plan details <?php echo $details_ok ? '' : '<span class="text-muted" style="font-weight:400">(unavailable — could not add columns; import schema in phpMyAdmin)</span>'; ?></p>
      <div class="form-group">
        <label class="form-label">Short description</label>
        <textarea name="description" id="planDesc" class="form-control" rows="2" placeholder="One or two sentences shown to clients when ordering." <?php echo $details_ok ? '' : 'disabled'; ?>></textarea>
      </div>
      <div class="form-group">
        <label class="form-label">Features <span class="text-muted" style="font-weight:400">(one per line)</span></label>
        <textarea name="features" id="planFeatures" class="form-control mono" rows="5" placeholder="10 GB NVMe storage&#10;Unlimited bandwidth&#10;Free SSL certificate&#10;Daily backups" <?php echo $details_ok ? '' : 'disabled'; ?>></textarea>
        <small class="form-hint">Shown as a checklist on the client order page.</small>
      </div>

      <p class="form-section-title" style="margin-top:10px">WHM package link</p>
      <div class="form-group">
        <label class="form-label">Hosting-panel package</label>
        <?php if ($panel_packages): ?>
          <select name="panel_package" id="planPackage" class="form-select">
            <option value="">— Not linked —</option>
            <?php foreach ($panel_packages as $pk): ?><option value="<?php echo h($pk); ?>"><?php echo h($pk); ?></option><?php endforeach; ?>
          </select>
          <small class="form-hint">Live list from <?php echo h($panel_key ?: 'your panel'); ?>. When a service uses this plan, this package is applied to the cPanel account automatically.</small>
        <?php else: ?>
          <input type="text" name="panel_package" id="planPackage" class="form-control mono" placeholder="e.g. orbit_business" <?php echo $link_ok ? '' : 'disabled'; ?> />
          <small class="form-hint">Type the exact WHM package name<?php echo $link_ok ? '' : ' (unavailable until schema_v4.sql is imported)'; ?>.</small>
        <?php endif; ?>
      </div>

      <div class="form-group">
        <label class="switch">
          <input type="checkbox" name="is_active" id="planActive" value="1" />
          <span class="track"></span><span>Plan is active (available for new services and orders)</span>
        </label>
      </div>
    </div>
    <div class="drawer-foot">
      <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Plan</button>
      <button type="button" class="btn btn-ghost" data-drawer-close>Cancel</button>
    </div>
  </form>
</div>

<script>
document.addEventListener('click', function (e) {
  var btn = e.target.closest ? e.target.closest('.plan-open') : null;
  if (!btn) return;
  var d;
  try { d = JSON.parse(btn.getAttribute('data-plan')); } catch (err) { return; }

  document.getElementById('planDrawerTitle').textContent = d.id ? 'Edit: ' + d.name : 'Add Plan';
  document.getElementById('planId').value       = d.id || 0;
  document.getElementById('planName').value     = d.name || '';
  document.getElementById('planCategory').value = d.category || 'shared';
  document.getElementById('planCycle').value    = d.billing_cycle || 'monthly';
  document.getElementById('planPrice').value    = d.price || '';
  document.getElementById('planSetup').value    = d.setup_fee || 0;
  document.getElementById('planPriceKes').value = d.price_kes || '';
  document.getElementById('planSetupKes').value = d.setup_fee_kes || 0;
  document.getElementById('planActive').checked = !!Number(d.is_active);
  var pd = document.getElementById('planDesc');     if (pd) pd.value = d.description || '';
  var pf = document.getElementById('planFeatures'); if (pf) pf.value = d.features || '';

  var pkg = document.getElementById('planPackage');
  if (pkg) {
    var val = d.panel_package || '';
    if (pkg.tagName === 'SELECT' && val && ![].some.call(pkg.options, function (o) { return o.value === val; })) {
      var opt = document.createElement('option');
      opt.value = val; opt.textContent = val + ' (missing on server)';
      pkg.appendChild(opt);
    }
    pkg.value = val;
  }
});
</script>

<?php require_once '../includes/footer.php'; ?>
