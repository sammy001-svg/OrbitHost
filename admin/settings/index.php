<?php
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/SiteSettings.php';

auth_check();
$page_title = 'Site Settings';

$table_ok = SiteSettings::ensureTable();
$sections = SiteSettings::sections();

if ($table_ok && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    require_role('admin', APP_URL . '/settings/');
    $section = $_POST['section'] ?? '';
    $def     = $sections[$section] ?? null;

    if (!$def) {
        flash_set('error', 'Unknown settings section.');
    } else {
        $current = SiteSettings::get($section);
        $data    = [];
        $errors  = [];

        foreach ($def['fields'] as $f) {
            $key = $f['key'];
            if ($f['type'] === 'toggle') {
                $data[$key] = !empty($_POST['f'][$key]);
            } elseif ($f['type'] === 'image') {
                $removed = !empty($_POST['remove_' . $key]);
                $err     = null;
                $uploaded = !empty($_FILES['f']['name'][$key])
                    ? SiteSettings::handleUpload([
                        'name'     => $_FILES['f']['name'][$key],
                        'type'     => $_FILES['f']['type'][$key],
                        'tmp_name' => $_FILES['f']['tmp_name'][$key],
                        'error'    => $_FILES['f']['error'][$key],
                        'size'     => $_FILES['f']['size'][$key],
                      ], $key, $err)
                    : null;

                if ($err) {
                    $errors[] = $err;
                    $data[$key] = $current[$key] ?? '';
                } elseif ($uploaded) {
                    SiteSettings::deleteUpload($current[$key] ?? null);
                    $data[$key] = $uploaded;
                } elseif ($removed) {
                    SiteSettings::deleteUpload($current[$key] ?? null);
                    $data[$key] = '';
                } else {
                    $data[$key] = $current[$key] ?? '';
                }
            } else {
                $data[$key] = trim($_POST['f'][$key] ?? '');
            }
        }

        if ($errors) {
            flash_set('error', implode(' ', $errors));
        } else {
            SiteSettings::save($section, $data);
            log_activity('site_settings_save', 'settings', 0, "Saved {$section}");
            flash_set('success', $def['label'] . ' settings saved.');
        }
    }
    header('Location: ' . APP_URL . '/settings/#sec-' . $section);
    exit;
}

// APP_URL already includes "/admin" — strip it to get the site root for
// linking to uploaded images (which are stored relative to the site root).
function site_root_url(): string
{
    return preg_replace('#/admin/?$#i', '', rtrim(APP_URL, '/'));
}

function ss_field(array $f, array $cfg): string
{
    $key   = $f['key'];
    $name  = 'f[' . $key . ']';
    $val   = $cfg[$key] ?? ($f['default'] ?? '');
    $label = h($f['label']);
    $hint  = !empty($f['hint']) ? '<small class="form-hint">' . h($f['hint']) . '</small>' : '';
    $ph    = h($f['placeholder'] ?? '');

    switch ($f['type']) {
        case 'toggle':
            return '<div class="form-group"><label class="switch"><input type="checkbox" name="' . $name . '" value="1" ' . (!empty($val) ? 'checked' : '') . ' />'
                 . '<span class="track"></span><span>' . $label . '</span></label>' . $hint . '</div>';

        case 'textarea':
            return '<div class="form-group"><label class="form-label">' . $label . '</label>'
                 . '<textarea class="form-textarea" name="' . $name . '" placeholder="' . $ph . '">' . h((string)$val) . '</textarea>' . $hint . '</div>';

        case 'image':
            $preview = $val ? '<div style="margin-bottom:8px"><img src="' . h(site_root_url() . $val) . '" alt="" style="max-height:56px;max-width:160px;border:1px solid var(--border);border-radius:8px;padding:6px;background:#fff" /></div>' : '';
            $remove  = $val ? '<label class="switch" style="margin-top:8px"><input type="checkbox" name="remove_' . $key . '" value="1" /><span class="track"></span><span>Remove current image</span></label>' : '';
            return '<div class="form-group"><label class="form-label">' . $label . '</label>' . $preview
                 . '<input type="file" name="f[' . $key . ']" accept="image/png,image/jpeg,image/gif,image/webp,image/x-icon,.ico" class="form-control" />' . $remove . $hint . '</div>';

        default:
            return '<div class="form-group"><label class="form-label">' . $label . '</label>'
                 . '<input type="text" class="form-control" name="' . $name . '" value="' . h((string)$val) . '" placeholder="' . $ph . '" />' . $hint . '</div>';
    }
}

require_once '../includes/header.php';
?>

<div class="content-header">
  <div>
    <h1 class="content-title">Site Settings</h1>
    <p class="page-subtitle">Manage your logo, favicon, header, footer and contact page — changes apply across the public website automatically.</p>
  </div>
  <a href="<?php echo preg_replace('#/admin/?$#', '', APP_URL); ?>/index.html" target="_blank" rel="noopener" class="btn btn-ghost"><i class="fas fa-arrow-up-right-from-square"></i> View Website</a>
</div>

<?php if (!$table_ok): ?>
  <div class="alert alert-danger"><i class="fas fa-triangle-exclamation"></i> Could not create the settings table automatically. Import <code>admin/install/schema_v7.sql</code> in phpMyAdmin, then reload this page.</div>
<?php else: ?>

<div class="provider-grid">
  <?php foreach ($sections as $key => $def): ?>
    <div class="provider-card" id="sec-<?php echo $key; ?>">
      <div class="provider-top">
        <div class="provider-logo" style="background:var(--navy)"><i class="fas <?php echo h($def['icon']); ?>"></i></div>
        <div style="flex:1;min-width:0"><div class="provider-name"><?php echo h($def['label']); ?></div></div>
      </div>
      <p class="provider-tagline"><?php echo h($def['hint']); ?></p>
      <div class="provider-foot">
        <button type="button" class="btn btn-primary btn-sm" data-drawer-open="drawer-<?php echo $key; ?>"><i class="fas fa-pen"></i> Edit</button>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<!-- ── Edit drawers ── -->
<?php foreach ($sections as $key => $def): $cfg = SiteSettings::get($key); ?>
  <div class="drawer-scrim" id="drawer-<?php echo $key; ?>-scrim"></div>
  <div class="drawer" id="drawer-<?php echo $key; ?>">
    <div class="drawer-head">
      <div class="provider-logo" style="width:38px;height:38px;font-size:16px;background:var(--navy)"><i class="fas <?php echo h($def['icon']); ?>"></i></div>
      <div><div style="font-weight:700"><?php echo h($def['label']); ?></div></div>
      <button type="button" class="drawer-close" data-drawer-close aria-label="Close">&times;</button>
    </div>
    <form method="POST" enctype="multipart/form-data" style="display:contents">
      <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>" />
      <input type="hidden" name="section" value="<?php echo $key; ?>" />
      <div class="drawer-body">
        <?php foreach ($def['fields'] as $f) { echo ss_field($f, $cfg); } ?>
      </div>
      <div class="drawer-foot">
        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save <?php echo h($def['label']); ?></button>
        <button type="button" class="btn btn-ghost" data-drawer-close>Cancel</button>
      </div>
    </form>
  </div>
<?php endforeach; ?>

<?php endif; ?>

<?php require_once '../includes/footer.php'; ?>
