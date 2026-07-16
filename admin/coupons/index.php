<?php
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/Coupon.php';

auth_check();
$page_title = 'Coupons';
Coupon::ensureTable();
Coupon::ensureInvoiceColumns();

$categories = ['shared','vps','dedicated','cloud','wordpress','reseller','ssl','email','domain'];
$cat_labels = ['shared'=>'Shared','vps'=>'VPS','dedicated'=>'Dedicated','cloud'=>'Cloud','wordpress'=>'WordPress',
               'reseller'=>'Reseller','ssl'=>'SSL','email'=>'Email','domain'=>'Domain'];

// ── Save (add / edit / delete) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    if (($_POST['action'] ?? '') === 'delete') {
        require_role('admin', APP_URL . '/coupons/index.php');
        $id   = (int) ($_POST['id'] ?? 0);
        $code = (string) db()->query('SELECT code FROM coupons WHERE id = ' . $id)->fetchColumn();
        if ($id && $code !== '') {
            db()->prepare('DELETE FROM coupons WHERE id = ?')->execute([$id]);
            log_activity('coupon_delete', 'coupon', $id, "Deleted coupon {$code}");
            flash_set('success', "Coupon \"{$code}\" deleted.");
        }
        header('Location: ' . APP_URL . '/coupons/index.php');
        exit;
    }

    require_role('admin', APP_URL . '/coupons/index.php');

    $id           = (int) ($_POST['id'] ?? 0);
    $code         = strtoupper(trim($_POST['code'] ?? ''));
    $type         = ($_POST['type'] ?? '') === 'fixed' ? 'fixed' : 'percent';
    $amount       = (float) ($_POST['amount'] ?? 0);
    $amount_kes   = (float) ($_POST['amount_kes'] ?? 0);
    $min_order    = (float) ($_POST['min_order'] ?? 0);
    $min_order_kes = (float) ($_POST['min_order_kes'] ?? 0);
    $max_uses     = trim($_POST['max_uses'] ?? '') !== '' ? max(1, (int) $_POST['max_uses']) : null;
    $expires_at   = trim($_POST['expires_at'] ?? '') !== '' ? $_POST['expires_at'] : null;
    $is_active    = !empty($_POST['is_active']) ? 1 : 0;
    $sel_cats     = array_intersect((array) ($_POST['categories'] ?? []), $categories);
    $cats_str     = $sel_cats ? implode(',', $sel_cats) : null;

    if ($code === '' || !preg_match('/^[A-Z0-9_-]+$/', $code)) {
        flash_set('error', 'Coupon code must contain only letters, numbers, dashes and underscores.');
    } elseif ($type === 'percent' && ($amount <= 0 || $amount > 100)) {
        flash_set('error', 'Percent-off must be between 1 and 100.');
    } elseif ($type === 'fixed' && $amount <= 0 && $amount_kes <= 0) {
        flash_set('error', 'Enter a fixed amount off in USD, KES, or both.');
    } else {
        $dupe = db()->prepare('SELECT id FROM coupons WHERE UPPER(code) = ? AND id != ?');
        $dupe->execute([$code, $id]);
        if ($dupe->fetch()) {
            flash_set('error', "Coupon code \"{$code}\" is already in use.");
        } else {
            $cols = ['code','type','amount','amount_kes','min_order','min_order_kes','categories','max_uses','expires_at','is_active'];
            $vals = [$code,$type,$amount,$amount_kes,$min_order,$min_order_kes,$cats_str,$max_uses,$expires_at,$is_active];
            if ($id) {
                $sets = array_map(fn($c) => "$c=?", $cols);
                db()->prepare('UPDATE coupons SET ' . implode(', ', $sets) . ' WHERE id=?')->execute([...$vals, $id]);
            } else {
                db()->prepare('INSERT INTO coupons (' . implode(', ', $cols) . ') VALUES (' . rtrim(str_repeat('?,', count($cols)), ',') . ')')
                    ->execute($vals);
                $id = (int) db()->lastInsertId();
            }
            log_activity('coupon_save', 'coupon', $id, "Saved coupon {$code}");
            flash_set('success', "Coupon \"{$code}\" saved.");
        }
    }

    header('Location: ' . APP_URL . '/coupons/index.php');
    exit;
}

$coupons = db()->query('SELECT * FROM coupons ORDER BY created_at DESC')->fetchAll();

require_once '../includes/header.php';
?>

<div class="content-header">
  <div>
    <h1 class="content-title">Coupons</h1>
    <p class="page-subtitle">Discount codes clients can enter at checkout when ordering hosting, VPS, or other services.</p>
  </div>
  <div class="page-header-actions">
    <?php if (can('admin')): ?>
    <button class="btn btn-primary coupon-open" data-drawer-open="drawer-coupon"
            data-coupon='{"id":0,"code":"","type":"percent","amount":"","amount_kes":"","min_order":"0","min_order_kes":"0","categories":[],"max_uses":"","expires_at":"","is_active":1}'>
      <i class="fas fa-plus"></i> Add Coupon
    </button>
    <?php endif; ?>
  </div>
</div>

<div class="stat-grid" style="grid-template-columns:repeat(3,1fr)">
  <div class="stat-card">
    <div class="stat-icon navy"><i class="fas fa-tags"></i></div>
    <div><div class="stat-label">Total coupons</div><div class="stat-value"><?php echo count($coupons); ?></div></div>
  </div>
  <div class="stat-card">
    <div class="stat-icon green"><i class="fas fa-circle-check"></i></div>
    <div><div class="stat-label">Active</div><div class="stat-value"><?php echo count(array_filter($coupons, fn($c) => $c['is_active'])); ?></div></div>
  </div>
  <div class="stat-card">
    <div class="stat-icon orange"><i class="fas fa-receipt"></i></div>
    <div><div class="stat-label">Total redemptions</div><div class="stat-value"><?php echo array_sum(array_column($coupons, 'used_count')); ?></div></div>
  </div>
</div>

<div class="table-wrap">
  <div class="table-toolbar">
    <span class="card-title">All coupons</span>
    <span class="table-count"><?php echo count($coupons); ?> coupons</span>
  </div>
  <div class="table-scroll">
  <table>
    <thead>
      <tr>
        <th>Code</th>
        <th>Discount</th>
        <th>Min. order</th>
        <th>Categories</th>
        <th>Uses</th>
        <th>Expires</th>
        <th>Status</th>
        <th style="text-align:right">Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php if (!$coupons): ?>
        <tr><td colspan="8"><div class="empty-state"><i class="fas fa-tags"></i><p>No coupons yet. Add your first discount code.</p></div></td></tr>
      <?php else: foreach ($coupons as $c):
        $expired = $c['expires_at'] && strtotime($c['expires_at']) < strtotime(date('Y-m-d'));
        $exhausted = $c['max_uses'] !== null && (int) $c['used_count'] >= (int) $c['max_uses'];
        $cats = $c['categories'] ? explode(',', $c['categories']) : [];
        $json = htmlspecialchars(json_encode([
            'id' => (int) $c['id'], 'code' => $c['code'], 'type' => $c['type'],
            'amount' => $c['amount'], 'amount_kes' => $c['amount_kes'],
            'min_order' => $c['min_order'], 'min_order_kes' => $c['min_order_kes'],
            'categories' => $cats, 'max_uses' => $c['max_uses'], 'expires_at' => $c['expires_at'],
            'is_active' => (int) $c['is_active'],
        ], JSON_UNESCAPED_SLASHES), ENT_QUOTES);
      ?>
        <tr>
          <td><span class="code-chip"><?php echo h($c['code']); ?></span></td>
          <td class="fw-600">
            <?php echo $c['type'] === 'percent'
                ? number_format((float) $c['amount'], 0) . '% off'
                : '$' . number_format((float) $c['amount'], 2) . ' / KSh ' . number_format((float) $c['amount_kes'], 0) . ' off'; ?>
          </td>
          <td>
            <?php if ((float) $c['min_order'] > 0 || (float) $c['min_order_kes'] > 0): ?>
              $<?php echo number_format((float) $c['min_order'], 2); ?> / KSh <?php echo number_format((float) $c['min_order_kes'], 0); ?>
            <?php else: ?><span class="text-muted">None</span><?php endif; ?>
          </td>
          <td style="font-size:12px"><?php echo $cats ? h(implode(', ', array_map(fn($k) => $cat_labels[$k] ?? $k, $cats))) : '<span class="text-muted">All plans</span>'; ?></td>
          <td><?php echo (int) $c['used_count']; ?><?php echo $c['max_uses'] !== null ? ' / ' . (int) $c['max_uses'] : ''; ?></td>
          <td><?php echo $c['expires_at'] ? format_date($c['expires_at']) : '<span class="text-muted">Never</span>'; ?></td>
          <td>
            <?php if (!$c['is_active']): ?><span class="badge badge-secondary">Disabled</span>
            <?php elseif ($expired): ?><span class="badge badge-secondary">Expired</span>
            <?php elseif ($exhausted): ?><span class="badge badge-secondary">Exhausted</span>
            <?php else: ?><span class="badge badge-success">Active</span><?php endif; ?>
          </td>
          <td>
            <div class="actions" style="justify-content:flex-end">
              <?php if (can('admin')): ?>
              <button class="action-link edit coupon-open" data-drawer-open="drawer-coupon" data-coupon="<?php echo $json; ?>"><i class="fas fa-pen"></i> Edit</button>
              <form method="POST" style="margin:0">
                <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>" />
                <input type="hidden" name="action" value="delete" />
                <input type="hidden" name="id" value="<?php echo (int) $c['id']; ?>" />
                <button type="submit" class="action-link danger" data-confirm="Delete coupon &quot;<?php echo h($c['code']); ?>&quot;? Invoices that already used it keep their recorded discount."><i class="fas fa-trash"></i></button>
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

<!-- ── Single add/edit drawer, populated by JS ── -->
<div class="drawer-scrim" id="drawer-coupon-scrim"></div>
<div class="drawer" id="drawer-coupon">
  <div class="drawer-head">
    <div><div style="font-weight:700" id="couponDrawerTitle">Coupon</div>
    <div class="text-muted" style="font-size:11.5px">Checkout discount code</div></div>
    <button type="button" class="drawer-close" data-drawer-close>&times;</button>
  </div>
  <form method="POST" style="display:contents">
    <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>" />
    <input type="hidden" name="id" id="couponId" value="0" />
    <div class="drawer-body">
      <div class="form-group">
        <label class="form-label">Code <span class="req">*</span></label>
        <input type="text" name="code" id="couponCode" class="form-control mono" placeholder="LAUNCH20" style="text-transform:uppercase" required />
        <small class="form-hint">Letters, numbers, dashes and underscores only — clients enter this exactly at checkout.</small>
      </div>
      <div class="form-group">
        <label class="form-label">Discount type</label>
        <select name="type" id="couponType" class="form-select">
          <option value="percent">Percent off</option>
          <option value="fixed">Fixed amount off</option>
        </select>
      </div>
      <div class="form-grid-2">
        <div class="form-group">
          <label class="form-label" id="couponAmountLabel">Percent off (%)</label>
          <input type="number" step="0.01" min="0" name="amount" id="couponAmount" class="form-control" />
        </div>
        <div class="form-group" id="couponAmountKesWrap" style="display:none">
          <label class="form-label">Fixed amount off (KES)</label>
          <input type="number" step="0.01" min="0" name="amount_kes" id="couponAmountKes" class="form-control" />
        </div>
      </div>
      <p class="form-section-title" style="margin-top:10px">Minimum order (optional)</p>
      <div class="form-grid-2">
        <div class="form-group">
          <label class="form-label">Minimum order (USD)</label>
          <input type="number" step="0.01" min="0" name="min_order" id="couponMinOrder" class="form-control" />
        </div>
        <div class="form-group">
          <label class="form-label">Minimum order (KES)</label>
          <input type="number" step="0.01" min="0" name="min_order_kes" id="couponMinOrderKes" class="form-control" />
        </div>
      </div>
      <div class="form-group">
        <label class="form-label">Restrict to plan categories <span class="text-muted" style="font-weight:400">(none selected = all plans)</span></label>
        <div style="display:flex;flex-wrap:wrap;gap:10px" id="couponCategories">
          <?php foreach ($categories as $cat): ?>
            <label style="display:flex;align-items:center;gap:6px;font-size:13px;font-weight:500">
              <input type="checkbox" name="categories[]" value="<?php echo $cat; ?>" /> <?php echo $cat_labels[$cat]; ?>
            </label>
          <?php endforeach; ?>
        </div>
      </div>
      <p class="form-section-title" style="margin-top:10px">Limits (optional)</p>
      <div class="form-grid-2">
        <div class="form-group">
          <label class="form-label">Max redemptions</label>
          <input type="number" step="1" min="1" name="max_uses" id="couponMaxUses" class="form-control" placeholder="Unlimited" />
        </div>
        <div class="form-group">
          <label class="form-label">Expires on</label>
          <input type="date" name="expires_at" id="couponExpires" class="form-control" />
        </div>
      </div>
      <div class="form-group">
        <label class="switch">
          <input type="checkbox" name="is_active" id="couponActive" value="1" />
          <span class="track"></span><span>Coupon is active</span>
        </label>
      </div>
    </div>
    <div class="drawer-foot">
      <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Coupon</button>
      <button type="button" class="btn btn-ghost" data-drawer-close>Cancel</button>
    </div>
  </form>
</div>

<script>
function couponToggleType() {
  var isFixed = document.getElementById('couponType').value === 'fixed';
  document.getElementById('couponAmountLabel').textContent = isFixed ? 'Fixed amount off (USD)' : 'Percent off (%)';
  document.getElementById('couponAmountKesWrap').style.display = isFixed ? 'block' : 'none';
}
document.getElementById('couponType').addEventListener('change', couponToggleType);

document.addEventListener('click', function (e) {
  var btn = e.target.closest ? e.target.closest('.coupon-open') : null;
  if (!btn) return;
  var d;
  try { d = JSON.parse(btn.getAttribute('data-coupon')); } catch (err) { return; }

  document.getElementById('couponDrawerTitle').textContent = d.id ? 'Edit: ' + d.code : 'Add Coupon';
  document.getElementById('couponId').value = d.id || 0;
  document.getElementById('couponCode').value = d.code || '';
  document.getElementById('couponType').value = d.type || 'percent';
  document.getElementById('couponAmount').value = d.amount || '';
  document.getElementById('couponAmountKes').value = d.amount_kes || '';
  document.getElementById('couponMinOrder').value = d.min_order || 0;
  document.getElementById('couponMinOrderKes').value = d.min_order_kes || 0;
  document.getElementById('couponMaxUses').value = d.max_uses || '';
  document.getElementById('couponExpires').value = d.expires_at || '';
  document.getElementById('couponActive').checked = !!Number(d.is_active);

  document.querySelectorAll('#couponCategories input[type="checkbox"]').forEach(function (cb) {
    cb.checked = (d.categories || []).indexOf(cb.value) !== -1;
  });

  couponToggleType();
});
</script>

<?php require_once '../includes/footer.php'; ?>
