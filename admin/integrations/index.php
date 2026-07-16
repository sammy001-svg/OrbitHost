<?php
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/providers/Provider.php';

auth_check();
$page_title = 'Providers';

// ── Persist provider config (REPLACE INTO — version-safe, preserves is_active) ──
function save_provider_config(string $provider, array $data): void
{
    $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $active = (int) (db()->query("SELECT is_active FROM integration_settings WHERE provider = " . db()->quote($provider))->fetchColumn() ?: 0);
    db()->prepare('REPLACE INTO integration_settings (provider, settings, is_active) VALUES (?, ?, ?)')
        ->execute([$provider, $json, $active]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action   = $_POST['action']   ?? '';
    $provider = $_POST['provider'] ?? '';
    $def      = ProviderRegistry::get($provider);

    if (!$def) {
        flash_set('error', 'Unknown provider.');
        header('Location: ' . APP_URL . '/integrations/');
        exit;
    }

    try {
        if (in_array($action, ['save', 'toggle'], true)) {
            require_role('admin', APP_URL . '/integrations/');
        }
        if ($action === 'save') {
            $data = [];
            foreach ($def['fields'] as $f) {
                if (($f['type'] ?? '') === 'toggle') {
                    $data[$f['key']] = !empty($_POST['f'][$f['key']]);
                } elseif (($f['type'] ?? '') === 'number') {
                    $data[$f['key']] = (int)($_POST['f'][$f['key']] ?? ($f['default'] ?? 0));
                } else {
                    $data[$f['key']] = trim($_POST['f'][$f['key']] ?? '');
                }
            }
            save_provider_config($provider, $data);
            log_activity('provider_config', 'integration', 0, "Saved config for {$provider}");
            flash_set('success', $def['name'] . ' configuration saved.');

        } elseif ($action === 'toggle') {
            $enable = (int)!empty($_POST['enable']);
            if ($enable && !Provider::isConfigured($provider)) {
                flash_set('error', 'Fill in the required fields before enabling ' . $def['name'] . '.');
            } else {
                db()->prepare('UPDATE integration_settings SET is_active = ? WHERE provider = ?')->execute([$enable, $provider]);
                flash_set('success', $def['name'] . ($enable ? ' enabled.' : ' disabled.'));
            }

        } elseif ($action === 'test') {
            if ($def['category'] === 'email') {
                require_once '../includes/Mailer.php';
                $to = trim($_POST['test_to'] ?? '') ?: current_admin()['email'];
                $result = Mailer::fromConfig()->test($to);
            } else {
                $result = match ($def['category']) {
                    'panel'     => Provider::panel($provider)->testConnection(),
                    'registrar' => Provider::registrar($provider)->testConnection(),
                    'payment'   => Provider::payment($provider)->testConnection(),
                    default     => ['success' => false, 'message' => 'No connection test for this provider type.'],
                };
            }
            flash_set($result['success'] ? 'success' : 'error',
                ($result['success'] ? '✓ ' : '✗ ') . $def['name'] . ': ' . ($result['message'] ?? ($result['success'] ? 'Connected.' : 'Failed.')));
        }
    } catch (\Throwable $e) {
        flash_set('error', $def['name'] . ' error: ' . $e->getMessage());
    }

    header('Location: ' . APP_URL . '/integrations/#prov-' . $provider);
    exit;
}

// ── Render one config field from the registry schema ──
function render_field(array $f, array $cfg): string
{
    $key   = $f['key'];
    $name  = 'f[' . $key . ']';
    $val   = $cfg[$key] ?? ($f['default'] ?? '');
    $label = h($f['label']);
    $req   = !empty($f['required']) ? ' <span class="req">*</span>' : '';
    $hint  = !empty($f['hint']) ? '<small class="form-hint">' . h($f['hint']) . '</small>' : '';
    $ph    = h($f['placeholder'] ?? '');

    switch ($f['type']) {
        case 'toggle':
            return '<div class="form-group"><label class="switch"><input type="checkbox" name="' . $name . '" value="1" ' . (!empty($val) ? 'checked' : '') . ' />'
                 . '<span class="track"></span><span>' . $label . '</span></label>' . $hint . '</div>';

        case 'secret':
            return '<div class="form-group"><label class="form-label">' . $label . $req . '</label>'
                 . '<div class="input-affix">'
                 . '<input type="password" class="form-control form-mono" name="' . $name . '" value="' . h((string)$val) . '" placeholder="' . $ph . '" autocomplete="new-password" style="padding-right:64px" />'
                 . '<button type="button" class="affix-btn" data-toggle-secret>Show</button></div>' . $hint . '</div>';

        case 'select':
            $opts = '';
            foreach (($f['options'] ?? []) as $ov => $ol) {
                $opts .= '<option value="' . h($ov) . '" ' . ((string)$val === (string)$ov ? 'selected' : '') . '>' . h($ol) . '</option>';
            }
            return '<div class="form-group"><label class="form-label">' . $label . $req . '</label><select class="form-select" name="' . $name . '">' . $opts . '</select>' . $hint . '</div>';

        case 'number':
            return '<div class="form-group"><label class="form-label">' . $label . $req . '</label>'
                 . '<input type="number" class="form-control" name="' . $name . '" value="' . h((string)$val) . '" placeholder="' . $ph . '" />' . $hint . '</div>';

        case 'textarea':
            return '<div class="form-group"><label class="form-label">' . $label . $req . '</label>'
                 . '<textarea class="form-control" name="' . $name . '" rows="3" placeholder="' . $ph . '">' . h((string)$val) . '</textarea>' . $hint . '</div>';

        default:
            return '<div class="form-group"><label class="form-label">' . $label . $req . '</label>'
                 . '<input type="text" class="form-control" name="' . $name . '" value="' . h((string)$val) . '" placeholder="' . $ph . '" />' . $hint . '</div>';
    }
}

// Precompute status for every provider
$registry = ProviderRegistry::all();
$status   = [];
foreach ($registry as $key => $def) {
    $status[$key] = ['configured' => Provider::isConfigured($key), 'active' => Provider::isActive($key)];
}
$counts = [
    'active'     => count(array_filter($status, fn($s) => $s['active'])),
    'configured' => count(array_filter($status, fn($s) => $s['configured'])),
    'total'      => count($registry),
];

require_once '../includes/header.php';
?>

<div class="content-header">
  <div>
    <h1 class="content-title">Provider Integrations</h1>
    <p class="page-subtitle">Connect hosting panels, domain registrars and payment gateways. These power service provisioning across the platform.</p>
  </div>
</div>

<div class="stat-grid" style="grid-template-columns:repeat(3,1fr)">
  <div class="stat-card">
    <div class="stat-icon green"><i class="fas fa-circle-check"></i></div>
    <div><div class="stat-label">Active</div><div class="stat-value"><?php echo $counts['active']; ?></div></div>
  </div>
  <div class="stat-card">
    <div class="stat-icon navy"><i class="fas fa-sliders"></i></div>
    <div><div class="stat-label">Configured</div><div class="stat-value"><?php echo $counts['configured']; ?></div></div>
  </div>
  <div class="stat-card">
    <div class="stat-icon purple"><i class="fas fa-plug"></i></div>
    <div><div class="stat-label">Available</div><div class="stat-value"><?php echo $counts['total']; ?></div></div>
  </div>
</div>

<?php foreach (ProviderRegistry::categories() as $cat => $meta):
    $providers = ProviderRegistry::byCategory($cat);
    if (!$providers) continue;
?>
  <div class="flex-gap" style="margin:26px 0 14px">
    <i class="fas <?php echo $meta['icon']; ?>" style="color:var(--navy)"></i>
    <span style="font-weight:700;font-size:15px;color:var(--navy)"><?php echo h($meta['label']); ?></span>
    <span class="text-muted" style="font-size:12.5px"><?php echo h($meta['hint']); ?></span>
  </div>

  <div class="provider-grid">
    <?php foreach ($providers as $key => $def): $st = $status[$key]; ?>
      <div class="provider-card <?php echo $st['active'] ? 'is-active' : ''; ?>" id="prov-<?php echo $key; ?>">
        <div class="provider-top">
          <div class="provider-logo" style="background:<?php echo h($def['color']); ?>"><i class="fas <?php echo h($def['icon']); ?>"></i></div>
          <div style="flex:1;min-width:0">
            <div class="provider-name"><?php echo h($def['name']); ?></div>
            <div class="provider-cat"><?php echo h($cat); ?></div>
          </div>
          <?php if ($st['active']): ?>
            <span class="status-pill on"><span class="dot dot-green dot-live"></span> Active</span>
          <?php elseif ($st['configured']): ?>
            <span class="status-pill off"><span class="dot dot-amber"></span> Ready</span>
          <?php else: ?>
            <span class="status-pill off"><span class="dot dot-grey"></span> Not set</span>
          <?php endif; ?>
        </div>

        <p class="provider-tagline"><?php echo h($def['tagline']); ?></p>

        <div class="provider-foot">
          <button type="button" class="btn btn-ghost btn-sm" data-drawer-open="drawer-<?php echo $key; ?>"><i class="fas fa-sliders"></i> Configure</button>
          <?php if ($def['client']): ?>
            <form method="POST" style="margin:0">
              <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>" />
              <input type="hidden" name="action" value="test" />
              <input type="hidden" name="provider" value="<?php echo $key; ?>" />
              <button type="submit" class="btn btn-outline btn-sm" <?php echo $st['configured'] ? '' : 'disabled title="Configure required fields first"'; ?>><i class="fas fa-bolt"></i> Test</button>
            </form>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
<?php endforeach; ?>

<!-- ── Config drawers (one per provider) ── -->
<?php foreach ($registry as $key => $def):
    $cfg = Provider::config($key);
    $st  = $status[$key];
?>
  <div class="drawer-scrim" id="drawer-<?php echo $key; ?>-scrim"></div>
  <div class="drawer" id="drawer-<?php echo $key; ?>">
    <div class="drawer-head">
      <div class="provider-logo" style="width:38px;height:38px;font-size:17px;background:<?php echo h($def['color']); ?>"><i class="fas <?php echo h($def['icon']); ?>"></i></div>
      <div>
        <div style="font-weight:700"><?php echo h($def['name']); ?></div>
        <div class="text-muted" style="font-size:11.5px;text-transform:uppercase;letter-spacing:.5px"><?php echo h($def['category']); ?></div>
      </div>
      <button type="button" class="drawer-close" data-drawer-close aria-label="Close">&times;</button>
    </div>

    <form method="POST" style="display:contents">
      <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>" />
      <input type="hidden" name="action" value="save" />
      <input type="hidden" name="provider" value="<?php echo $key; ?>" />
      <div class="drawer-body">
        <?php if (!empty($def['docs'])): ?>
          <a href="<?php echo h($def['docs']); ?>" target="_blank" rel="noopener" class="code-chip" style="text-decoration:none;display:inline-flex;gap:6px;align-items:center;margin-bottom:16px"><i class="fas fa-book"></i> API documentation</a>
        <?php endif; ?>

        <?php if (!empty($def['ip_whitelist_note'])): ?>
          <div class="alert alert-info" style="font-size:12.5px;margin-bottom:16px">
            <i class="fas fa-shield-halved"></i>
            <div style="flex:1">
              This registrar rejects requests from IP addresses that aren't whitelisted in your account's API settings.
              <div style="margin-top:8px;display:flex;align-items:center;gap:8px;flex-wrap:wrap">
                <button type="button" class="btn btn-ghost btn-sm ip-detect-btn" data-target="ip-result-<?php echo $key; ?>"><i class="fas fa-magnifying-glass-location"></i> Detect my server IP</button>
                <span id="ip-result-<?php echo $key; ?>" class="code-chip" style="display:none"></span>
              </div>
            </div>
          </div>
        <?php endif; ?>

        <?php foreach ($def['fields'] as $f) { echo render_field($f, $cfg); } ?>
      </div>
      <div class="drawer-foot">
        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Configuration</button>
        <button type="button" class="btn btn-ghost" data-drawer-close>Cancel</button>
      </div>
    </form>

    <div class="drawer-foot" style="border-top:none;padding-top:0">
      <form method="POST" style="margin:0;width:100%">
        <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>" />
        <input type="hidden" name="action" value="toggle" />
        <input type="hidden" name="provider" value="<?php echo $key; ?>" />
        <input type="hidden" name="enable" value="<?php echo $st['active'] ? '0' : '1'; ?>" />
        <button type="submit" class="btn <?php echo $st['active'] ? 'btn-danger' : 'btn-navy'; ?> btn-block">
          <i class="fas <?php echo $st['active'] ? 'fa-power-off' : 'fa-toggle-on'; ?>"></i>
          <?php echo $st['active'] ? 'Disable this provider' : 'Enable this provider'; ?>
        </button>
      </form>
    </div>

    <?php if ($def['category'] === 'email'): ?>
    <div class="drawer-foot" style="flex-direction:column;align-items:stretch;gap:8px">
      <label class="form-label" style="margin:0">Send a test email</label>
      <form method="POST" style="margin:0;display:flex;gap:8px">
        <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>" />
        <input type="hidden" name="action" value="test" />
        <input type="hidden" name="provider" value="<?php echo $key; ?>" />
        <input type="email" name="test_to" class="form-control" required
               placeholder="recipient@example.com" value="<?php echo h(current_admin()['email']); ?>" />
        <button type="submit" class="btn btn-outline" style="white-space:nowrap"><i class="fas fa-paper-plane"></i> Send</button>
      </form>
      <small class="form-hint">Save your settings first — the test uses what's stored in the database.</small>
    </div>
    <?php endif; ?>
  </div>
<?php endforeach; ?>

<?php require_once '../includes/footer.php'; ?>
