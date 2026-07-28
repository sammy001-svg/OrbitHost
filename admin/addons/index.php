<?php
/**
 * Orbit Cloud — Add-on services offered during the hosting order flow.
 *
 * These are the extras a client can tick alongside a hosting package on
 * portal/order/index.php (backups, dedicated IP, malware scanning, SSL,
 * migration…). Kept separate from the `services` plan catalogue because
 * an add-on is never bought on its own — it always rides on a hosting
 * order — and because it needs a category filter so "cPanel Migration"
 * doesn't get offered next to a VPS.
 */
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/Currency.php';
require_once '../includes/ServiceAddon.php';

auth_check();
$page_title = 'Add-on Services';
Currency::ensureSchema();

$schema_ok  = ServiceAddon::ensureSchema();
$categories = ['shared','vps','dedicated','cloud','wordpress','reseller','ssl','email','domain'];
$cycles     = ['monthly' => 'Monthly', 'annual' => 'Annual', 'one_time' => 'One-time'];

if ($schema_ok && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    // Add-ons are priced items on a client's invoice — same bar as the plan
    // catalogue itself.
    require_role('admin', APP_URL . '/addons/index.php');

    if (($_POST['action'] ?? '') === 'delete') {
        $id   = (int) ($_POST['id'] ?? 0);
        $stmt = db()->prepare('SELECT name FROM service_addons WHERE id = ?');
        $stmt->execute([$id]);
        $name = (string) $stmt->fetchColumn();
        if ($id && $name !== '') {
            db()->prepare('DELETE FROM service_addons WHERE id = ?')->execute([$id]);
            log_activity('addon_delete', 'addon', $id, $name);
            flash_set('success', 'Add-on "' . $name . '" deleted. Orders that already included it are unaffected.');
        }
        header('Location: ' . APP_URL . '/addons/index.php');
        exit;
    }

    $id        = (int) ($_POST['id'] ?? 0);
    $name      = trim($_POST['name'] ?? '');
    $desc      = trim($_POST['description'] ?? '');
    $cycle     = array_key_exists($_POST['billing_cycle'] ?? '', $cycles) ? $_POST['billing_cycle'] : 'monthly';
    $price     = max(0, (float) ($_POST['price'] ?? 0));
    $price_kes = max(0, (float) ($_POST['price_kes'] ?? 0));
    $active    = !empty($_POST['is_active']) ? 1 : 0;
    $preselect = !empty($_POST['is_preselected']) ? 1 : 0;
    $sort      = (int) ($_POST['sort_order'] ?? 100);
    // Empty selection = offered on every category.
    $applies   = array_values(array_intersect($categories, (array) ($_POST['categories'] ?? [])));
    $applies   = $applies ? implode(',', $applies) : null;

    if ($name === '') {
        flash_set('error', 'Add-on name is required.');
    } else {
        if ($id) {
            db()->prepare('UPDATE service_addons SET name=?, description=?, billing_cycle=?, price=?, price_kes=?,
                           categories=?, is_active=?, is_preselected=?, sort_order=? WHERE id=?')
                ->execute([$name, $desc ?: null, $cycle, $price, $price_kes, $applies, $active, $preselect, $sort, $id]);
        } else {
            db()->prepare('INSERT INTO service_addons (name, description, billing_cycle, price, price_kes,
                           categories, is_active, is_preselected, sort_order) VALUES (?,?,?,?,?,?,?,?,?)')
                ->execute([$name, $desc ?: null, $cycle, $price, $price_kes, $applies, $active, $preselect, $sort]);
            $id = (int) db()->lastInsertId();
        }
        log_activity('addon_save', 'addon', $id, $name);
        flash_set('success', 'Add-on "' . $name . '" saved.');
    }

    header('Location: ' . APP_URL . '/addons/index.php');
    exit;
}

$addons = $schema_ok
    ? db()->query('SELECT * FROM service_addons ORDER BY sort_order, name')->fetchAll()
    : [];

require_once '../includes/header.php';
?>

<div class="content-header">
  <div>
    <h1 class="content-title">Add-on Services</h1>
    <p class="page-subtitle">Extras offered alongside a hosting package during checkout. Clients tick these on the first step of the order flow.</p>
  </div>
  <div class="page-header-actions">
    <?php if (can('admin') && $schema_ok): ?>
    <button class="btn btn-primary addon-open" data-drawer-open="drawer-addon"
            data-addon='{"id":0,"name":"","description":"","billing_cycle":"monthly","price":"","price_kes":"","categories":"","is_active":1,"is_preselected":0,"sort_order":100}'>
      <i class="fas fa-plus"></i> Add Service
    </button>
    <?php endif; ?>
    <a href="<?php echo APP_URL; ?>/plans/index.php" class="btn btn-ghost"><i class="fas fa-tags"></i> Plans &amp; Packages</a>
  </div>
</div>

<?php if (!$schema_ok): ?>
  <div class="alert alert-danger"><i class="fas fa-triangle-exclamation"></i>
    Could not create the <code>service_addons</code> table automatically — check the database user's CREATE privilege and reload.
  </div>
<?php else: ?>

<div class="stat-grid" style="grid-template-columns:repeat(3,1fr)">
  <div class="stat-card">
    <div class="stat-icon navy"><i class="fas fa-puzzle-piece"></i></div>
    <div><div class="stat-label">Add-ons</div><div class="stat-value"><?php echo count($addons); ?></div></div>
  </div>
  <div class="stat-card">
    <div class="stat-icon green"><i class="fas fa-eye"></i></div>
    <div><div class="stat-label">Offered at checkout</div><div class="stat-value"><?php echo count(array_filter($addons, fn($a) => $a['is_active'])); ?></div></div>
  </div>
  <div class="stat-card">
    <div class="stat-icon orange"><i class="fas fa-check-double"></i></div>
    <div><div class="stat-label">Ticked by default</div><div class="stat-value"><?php echo count(array_filter($addons, fn($a) => $a['is_preselected'])); ?></div></div>
  </div>
</div>

<div class="table-wrap">
  <div class="table-toolbar">
    <span class="card-title">Add-on catalogue</span>
    <span class="table-count"><?php echo count($addons); ?> add-ons</span>
  </div>
  <div class="table-scroll">
  <table>
    <thead>
      <tr>
        <th>Add-on</th>
        <th>Cycle</th>
        <th>Price (USD)</th>
        <th>Price (KES)</th>
        <th>Offered with</th>
        <th>Status</th>
        <th style="text-align:right">Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php if (!$addons): ?>
        <tr><td colspan="7"><div class="empty-state"><i class="fas fa-puzzle-piece"></i><p>No add-ons yet. Anything you add here is offered on the first step of the hosting order flow.</p></div></td></tr>
      <?php else: foreach ($addons as $a):
        $cats = trim((string) $a['categories']) === '' ? [] : explode(',', $a['categories']);
        $json = htmlspecialchars(json_encode([
            'id' => (int) $a['id'], 'name' => $a['name'], 'description' => (string) $a['description'],
            'billing_cycle' => $a['billing_cycle'], 'price' => $a['price'], 'price_kes' => $a['price_kes'],
            'categories' => (string) $a['categories'], 'is_active' => (int) $a['is_active'],
            'is_preselected' => (int) $a['is_preselected'], 'sort_order' => (int) $a['sort_order'],
        ], JSON_UNESCAPED_SLASHES), ENT_QUOTES);
      ?>
        <tr>
          <td>
            <div class="td-name"><?php echo h($a['name']); ?></div>
            <?php if ($a['description']): ?><div class="td-sub"><?php echo h(mb_strimwidth($a['description'], 0, 70, '…')); ?></div><?php endif; ?>
          </td>
          <td><?php echo badge($a['billing_cycle']); ?></td>
          <td class="fw-600">$<?php echo number_format((float) $a['price'], 2); ?></td>
          <td class="fw-600">KSh <?php echo number_format((float) $a['price_kes'], 2); ?></td>
          <td>
            <?php if (!$cats): ?>
              <span class="text-muted" style="font-size:12px">All packages</span>
            <?php else: foreach ($cats as $c): ?>
              <span class="code-chip"><?php echo h(ucfirst($c)); ?></span>
            <?php endforeach; endif; ?>
          </td>
          <td>
            <?php echo $a['is_active'] ? '<span class="badge badge-success">Offered</span>' : '<span class="badge badge-secondary">Hidden</span>'; ?>
            <?php if ($a['is_preselected']): ?><span class="badge badge-warning" title="Ticked by default at checkout">Default</span><?php endif; ?>
          </td>
          <td>
            <div class="actions" style="justify-content:flex-end">
              <?php if (!can('admin')): ?>
                <span class="text-muted" style="font-size:12px">—</span>
              <?php else: ?>
              <button class="action-link edit addon-open" data-drawer-open="drawer-addon" data-addon="<?php echo $json; ?>"><i class="fas fa-pen"></i> Edit</button>
              <form method="POST" style="margin:0">
                <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>" />
                <input type="hidden" name="action" value="delete" />
                <input type="hidden" name="id" value="<?php echo (int) $a['id']; ?>" />
                <button type="submit" class="action-link danger" data-confirm="Delete add-on &quot;<?php echo h($a['name']); ?>&quot;? Orders that already included it keep their invoice lines."><i class="fas fa-trash"></i></button>
              </form>
              <?php endif; ?>
            </div>
          </td>
        </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>
  </div>
</div>

<?php if (can('admin')): ?>
<div class="drawer-scrim" id="drawer-addon-scrim"></div>
<div class="drawer" id="drawer-addon">
  <div class="drawer-head">
    <div><div style="font-weight:700" id="addonDrawerTitle">Add-on</div>
    <div class="text-muted" style="font-size:11.5px">Offered during hosting checkout</div></div>
    <button type="button" class="drawer-close" data-drawer-close>&times;</button>
  </div>
  <form method="POST" style="display:contents">
    <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>" />
    <input type="hidden" name="id" id="addonId" value="0" />
    <div class="drawer-body">
      <div class="form-group">
        <label class="form-label">Name <span class="req">*</span></label>
        <input type="text" name="name" id="addonName" class="form-control" required placeholder="e.g. Daily Off-site Backups" />
      </div>
      <div class="form-group">
        <label class="form-label">Description</label>
        <textarea name="description" id="addonDesc" class="form-control" rows="2" placeholder="One line explaining what the client gets."></textarea>
      </div>
      <div class="form-grid-2">
        <div class="form-group">
          <label class="form-label">Billing cycle</label>
          <select name="billing_cycle" id="addonCycle" class="form-select">
            <?php foreach ($cycles as $v => $l): ?><option value="<?php echo $v; ?>"><?php echo $l; ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Display order</label>
          <input type="number" step="1" name="sort_order" id="addonSort" class="form-control" value="100" />
        </div>
        <div class="form-group">
          <label class="form-label">Price (USD)</label>
          <input type="number" step="0.01" min="0" name="price" id="addonPrice" class="form-control" />
        </div>
        <div class="form-group">
          <label class="form-label">Price (KES)</label>
          <input type="number" step="0.01" min="0" name="price_kes" id="addonPriceKes" class="form-control" />
        </div>
      </div>

      <p class="form-section-title" style="margin-top:10px">Where it's offered</p>
      <div class="form-group">
        <label class="form-label">Package categories</label>
        <div style="display:flex;flex-wrap:wrap;gap:10px 16px">
          <?php foreach ($categories as $c): ?>
            <label class="switch" style="flex:0 0 auto">
              <input type="checkbox" name="categories[]" value="<?php echo $c; ?>" class="addon-cat" />
              <span class="track"></span><span><?php echo ucfirst($c); ?></span>
            </label>
          <?php endforeach; ?>
        </div>
        <small class="form-hint">Leave all unticked to offer this add-on with every package.</small>
      </div>

      <div class="form-group">
        <label class="switch">
          <input type="checkbox" name="is_active" id="addonActive" value="1" />
          <span class="track"></span><span>Offer this add-on at checkout</span>
        </label>
      </div>
      <div class="form-group">
        <label class="switch">
          <input type="checkbox" name="is_preselected" id="addonPreselect" value="1" />
          <span class="track"></span><span>Ticked by default</span>
        </label>
        <small class="form-hint">The client can still untick it — nothing is added silently.</small>
      </div>
    </div>
    <div class="drawer-foot">
      <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Add-on</button>
      <button type="button" class="btn btn-ghost" data-drawer-close>Cancel</button>
    </div>
  </form>
</div>

<script>
document.addEventListener('click', function (e) {
  var btn = e.target.closest ? e.target.closest('.addon-open') : null;
  if (!btn) return;
  var d;
  try { d = JSON.parse(btn.getAttribute('data-addon')); } catch (err) { return; }

  document.getElementById('addonDrawerTitle').textContent = d.id ? 'Edit: ' + d.name : 'Add Service';
  document.getElementById('addonId').value        = d.id || 0;
  document.getElementById('addonName').value      = d.name || '';
  document.getElementById('addonDesc').value      = d.description || '';
  document.getElementById('addonCycle').value     = d.billing_cycle || 'monthly';
  document.getElementById('addonPrice').value     = d.price || '';
  document.getElementById('addonPriceKes').value  = d.price_kes || '';
  document.getElementById('addonSort').value      = d.sort_order || 100;
  document.getElementById('addonActive').checked  = !!Number(d.is_active);
  document.getElementById('addonPreselect').checked = !!Number(d.is_preselected);

  var cats = (d.categories || '').split(',').filter(Boolean);
  document.querySelectorAll('.addon-cat').forEach(function (cb) {
    cb.checked = cats.indexOf(cb.value) !== -1;
  });
});
</script>
<?php endif; ?>

<?php endif; // $schema_ok ?>

<?php require_once '../includes/footer.php'; ?>
