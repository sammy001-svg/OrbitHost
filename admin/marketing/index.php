<?php
/**
 * Orbit Cloud — Client-portal marketing banners.
 * Two placements: 'hero' (large carousel at the top of the client dashboard)
 * and 'side' (small rotating card beside the dashboard tables). Images can
 * be uploaded here or referenced by URL.
 */
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

auth_check();
$page_title = 'Portal Banners';

// ── Table (auto-migration) ──
$schema_ok = true;
try {
    db()->exec("CREATE TABLE IF NOT EXISTS portal_banners (
        id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        placement  ENUM('hero','side') NOT NULL DEFAULT 'hero',
        title      VARCHAR(150) NOT NULL,
        subtitle   VARCHAR(255) DEFAULT NULL,
        image_url  VARCHAR(500) DEFAULT NULL,
        link_url   VARCHAR(500) DEFAULT NULL,
        link_label VARCHAR(80)  DEFAULT NULL,
        bg_color   VARCHAR(20)  DEFAULT NULL,
        sort_order INT NOT NULL DEFAULT 100,
        is_active  TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (\Throwable $e) {
    $schema_ok = false;
}

$upload_dir_fs  = dirname(__DIR__, 2) . '/uploads/banners';
$upload_dir_url = 'uploads/banners'; // site-root relative

/** Store an uploaded banner image; returns site-relative path or throws. */
function banner_store_upload(array $file, string $dir_fs, string $dir_url): string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Image upload failed (error ' . $file['error'] . ').');
    }
    if ($file['size'] > 3 * 1024 * 1024) throw new RuntimeException('Image too large — keep it under 3 MB.');
    $info = @getimagesize($file['tmp_name']);
    if (!$info) throw new RuntimeException('The uploaded file is not a valid image.');
    $ext = image_type_to_extension($info[2], false);
    if (!in_array($ext, ['jpeg', 'jpg', 'png', 'gif', 'webp'], true)) {
        throw new RuntimeException('Use a JPG, PNG, GIF or WebP image.');
    }
    if (!is_dir($dir_fs) && !@mkdir($dir_fs, 0755, true)) {
        throw new RuntimeException('Could not create the uploads/banners directory — check permissions.');
    }
    $name = 'bn_' . bin2hex(random_bytes(8)) . '.' . ($ext === 'jpeg' ? 'jpg' : $ext);
    if (!move_uploaded_file($file['tmp_name'], $dir_fs . '/' . $name)) {
        throw new RuntimeException('Could not save the uploaded image.');
    }
    return $dir_url . '/' . $name;
}

// ── Actions ──
if ($schema_ok && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'save') {
            $id        = (int)($_POST['id'] ?? 0);
            $placement = ($_POST['placement'] ?? 'hero') === 'side' ? 'side' : 'hero';
            $title     = trim($_POST['title'] ?? '');
            if ($title === '') throw new RuntimeException('A banner title is required.');

            $image = trim($_POST['image_url'] ?? '');
            if (!empty($_FILES['image_file']['name'])) {
                $image = banner_store_upload($_FILES['image_file'], $upload_dir_fs, $upload_dir_url);
            }

            $vals = [
                $placement, $title,
                trim($_POST['subtitle'] ?? '') ?: null,
                $image ?: null,
                trim($_POST['link_url'] ?? '') ?: null,
                trim($_POST['link_label'] ?? '') ?: null,
                trim($_POST['bg_color'] ?? '') ?: null,
                (int)($_POST['sort_order'] ?? 100),
                !empty($_POST['is_active']) ? 1 : 0,
            ];
            if ($id) {
                db()->prepare('UPDATE portal_banners SET placement=?, title=?, subtitle=?, image_url=?, link_url=?, link_label=?, bg_color=?, sort_order=?, is_active=? WHERE id=?')
                    ->execute([...$vals, $id]);
            } else {
                db()->prepare('INSERT INTO portal_banners (placement, title, subtitle, image_url, link_url, link_label, bg_color, sort_order, is_active) VALUES (?,?,?,?,?,?,?,?,?)')
                    ->execute($vals);
                $id = (int) db()->lastInsertId();
            }
            log_activity('banner_save', 'marketing', $id, $title);
            flash_set('success', 'Banner saved.');

        } elseif ($action === 'delete') {
            db()->prepare('DELETE FROM portal_banners WHERE id=?')->execute([(int)($_POST['id'] ?? 0)]);
            flash_set('success', 'Banner deleted.');

        } elseif ($action === 'toggle') {
            db()->prepare('UPDATE portal_banners SET is_active = 1 - is_active WHERE id=?')->execute([(int)($_POST['id'] ?? 0)]);
        }
    } catch (\Throwable $e) {
        flash_set('error', $e->getMessage());
    }
    header('Location: ' . APP_URL . '/marketing/');
    exit;
}

$banners = $schema_ok ? db()->query('SELECT * FROM portal_banners ORDER BY placement, sort_order, id')->fetchAll() : [];
$site_base = preg_replace('#/admin/?$#', '', APP_URL);
$img_src = fn(?string $u) => $u ? (preg_match('#^https?://#i', $u) ? $u : $site_base . '/' . ltrim($u, '/')) : '';

require_once '../includes/header.php';
?>

<div class="content-header">
  <div>
    <h1 class="content-title">Portal Banners</h1>
    <p class="page-subtitle">Marketing carousels shown to clients in their portal — a large hero rotation on the dashboard plus a small side banner.</p>
  </div>
  <button class="btn btn-primary banner-open" data-drawer-open="drawer-banner"
          data-banner='{"id":0,"placement":"hero","title":"","subtitle":"","image_url":"","link_url":"","link_label":"","bg_color":"","sort_order":100,"is_active":1}'>
    <i class="fas fa-plus"></i> Add Banner
  </button>
</div>

<?php if (!$schema_ok): ?>
  <div class="alert alert-danger"><i class="fas fa-triangle-exclamation"></i> Could not create the banners table automatically — check DB privileges and reload.</div>
<?php else: ?>

<?php foreach (['hero' => 'Hero carousel (dashboard top)', 'side' => 'Side banner (small card)'] as $pl => $label):
    $group = array_values(array_filter($banners, fn($b) => $b['placement'] === $pl)); ?>
  <div class="flex-gap" style="margin:22px 0 12px">
    <i class="fas <?php echo $pl === 'hero' ? 'fa-panorama' : 'fa-rectangle-ad'; ?>" style="color:var(--navy)"></i>
    <span style="font-weight:700;font-size:14.5px;color:var(--navy)"><?php echo $label; ?></span>
    <span class="text-muted" style="font-size:12px"><?php echo count($group); ?> banner<?php echo count($group) === 1 ? '' : 's'; ?></span>
  </div>

  <?php if (!$group): ?>
    <div class="card"><div class="card-body"><span class="text-muted" style="font-size:13px">No <?php echo $pl; ?> banners yet.</span></div></div>
  <?php else: ?>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:14px">
      <?php foreach ($group as $b):
        $json = htmlspecialchars(json_encode([
            'id'=>(int)$b['id'],'placement'=>$b['placement'],'title'=>$b['title'],'subtitle'=>(string)$b['subtitle'],
            'image_url'=>(string)$b['image_url'],'link_url'=>(string)$b['link_url'],'link_label'=>(string)$b['link_label'],
            'bg_color'=>(string)$b['bg_color'],'sort_order'=>(int)$b['sort_order'],'is_active'=>(int)$b['is_active'],
        ], JSON_UNESCAPED_SLASHES), ENT_QUOTES); ?>
        <div class="card" style="overflow:hidden">
          <div style="height:110px;background:<?php echo h($b['bg_color'] ?: 'var(--navy)'); ?> center/cover no-repeat<?php
            echo $b['image_url'] ? ' url(' . h($img_src($b['image_url'])) . ')' : ''; ?>;position:relative">
            <?php if (!$b['is_active']): ?><span class="badge badge-secondary" style="position:absolute;top:8px;left:8px">Hidden</span><?php endif; ?>
          </div>
          <div class="card-body" style="padding:12px 14px">
            <div style="font-weight:700;font-size:13.5px;color:var(--navy)"><?php echo h($b['title']); ?></div>
            <?php if ($b['subtitle']): ?><div class="text-muted" style="font-size:12px;margin-top:2px"><?php echo h(mb_strimwidth($b['subtitle'], 0, 60, '…')); ?></div><?php endif; ?>
            <div class="flex-gap" style="margin-top:10px;justify-content:space-between">
              <span class="text-muted" style="font-size:11px">Order: <?php echo (int)$b['sort_order']; ?></span>
              <div class="actions">
                <form method="POST" style="margin:0">
                  <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>" />
                  <input type="hidden" name="action" value="toggle" />
                  <input type="hidden" name="id" value="<?php echo (int)$b['id']; ?>" />
                  <button type="submit" class="action-link" title="<?php echo $b['is_active'] ? 'Hide' : 'Show'; ?>"><i class="fas <?php echo $b['is_active'] ? 'fa-eye-slash' : 'fa-eye'; ?>"></i></button>
                </form>
                <button class="action-link edit banner-open" data-drawer-open="drawer-banner" data-banner="<?php echo $json; ?>"><i class="fas fa-pen"></i></button>
                <form method="POST" style="margin:0">
                  <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>" />
                  <input type="hidden" name="action" value="delete" />
                  <input type="hidden" name="id" value="<?php echo (int)$b['id']; ?>" />
                  <button type="submit" class="action-link danger" data-confirm="Delete this banner?"><i class="fas fa-trash"></i></button>
                </form>
              </div>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
<?php endforeach; ?>

<!-- ── Add/edit drawer ── -->
<div class="drawer-scrim" id="drawer-banner-scrim"></div>
<div class="drawer" id="drawer-banner">
  <div class="drawer-head">
    <div><div style="font-weight:700" id="bannerDrawerTitle">Banner</div>
    <div class="text-muted" style="font-size:11.5px">Client-portal marketing</div></div>
    <button type="button" class="drawer-close" data-drawer-close>&times;</button>
  </div>
  <form method="POST" enctype="multipart/form-data" style="display:contents">
    <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>" />
    <input type="hidden" name="action" value="save" />
    <input type="hidden" name="id" id="bnId" value="0" />
    <div class="drawer-body">
      <div class="form-group">
        <label class="form-label">Placement</label>
        <select name="placement" id="bnPlacement" class="form-select">
          <option value="hero">Hero carousel — large, dashboard top</option>
          <option value="side">Side banner — small card</option>
        </select>
      </div>
      <div class="form-group">
        <label class="form-label">Title <span class="req">*</span></label>
        <input type="text" name="title" id="bnTitle" class="form-control" required placeholder="e.g. 50% off .co.ke domains this month" />
      </div>
      <div class="form-group">
        <label class="form-label">Subtitle</label>
        <input type="text" name="subtitle" id="bnSubtitle" class="form-control" placeholder="Short supporting line (optional)" />
      </div>
      <div class="form-group">
        <label class="form-label">Image</label>
        <input type="file" name="image_file" class="form-control" accept="image/*" />
        <small class="form-hint">JPG/PNG/WebP, max 3 MB. Recommended: 1200×360 for hero, 600×400 for side.</small>
      </div>
      <div class="form-group">
        <label class="form-label">…or image URL</label>
        <input type="text" name="image_url" id="bnImage" class="form-control mono" placeholder="https://… or uploads/banners/…" />
        <small class="form-hint">Leave both empty to show a solid colour banner with just the text.</small>
      </div>
      <div class="form-grid-2" style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
        <div class="form-group">
          <label class="form-label">Background colour</label>
          <input type="text" name="bg_color" id="bnColor" class="form-control mono" placeholder="#0b2447" />
        </div>
        <div class="form-group">
          <label class="form-label">Sort order</label>
          <input type="number" name="sort_order" id="bnSort" class="form-control" value="100" />
        </div>
      </div>
      <div class="form-group">
        <label class="form-label">Link URL</label>
        <input type="text" name="link_url" id="bnLink" class="form-control mono" placeholder="https://… or /portal/order.php" />
      </div>
      <div class="form-group">
        <label class="form-label">Button label</label>
        <input type="text" name="link_label" id="bnLinkLabel" class="form-control" placeholder="e.g. Claim offer" />
      </div>
      <div class="form-group">
        <label class="switch">
          <input type="checkbox" name="is_active" id="bnActive" value="1" checked />
          <span class="track"></span><span>Visible to clients</span>
        </label>
      </div>
    </div>
    <div class="drawer-foot">
      <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Banner</button>
      <button type="button" class="btn btn-ghost" data-drawer-close>Cancel</button>
    </div>
  </form>
</div>

<script>
document.addEventListener('click', function (e) {
  var btn = e.target.closest ? e.target.closest('.banner-open') : null;
  if (!btn) return;
  var d;
  try { d = JSON.parse(btn.getAttribute('data-banner')); } catch (err) { return; }
  document.getElementById('bannerDrawerTitle').textContent = d.id ? 'Edit: ' + d.title : 'Add Banner';
  document.getElementById('bnId').value        = d.id || 0;
  document.getElementById('bnPlacement').value = d.placement || 'hero';
  document.getElementById('bnTitle').value     = d.title || '';
  document.getElementById('bnSubtitle').value  = d.subtitle || '';
  document.getElementById('bnImage').value     = d.image_url || '';
  document.getElementById('bnColor').value     = d.bg_color || '';
  document.getElementById('bnSort').value      = d.sort_order || 100;
  document.getElementById('bnLink').value      = d.link_url || '';
  document.getElementById('bnLinkLabel').value = d.link_label || '';
  document.getElementById('bnActive').checked  = !!Number(d.is_active);
});
</script>

<?php endif; ?>

<?php require_once '../includes/footer.php'; ?>
