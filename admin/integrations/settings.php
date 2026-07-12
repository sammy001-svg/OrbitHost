<?php
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

auth_check();
can('admin');  // Only admins and super_admins may change integration credentials
$page_title = 'Integration Settings';

// Load current settings
$rows = db()->query('SELECT provider, settings FROM integration_settings')->fetchAll();
$cfg  = [];
foreach ($rows as $r) {
    $cfg[$r['provider']] = json_decode($r['settings'], true) ?? [];
}

function save_provider(string $provider, array $data): void {
    $json = json_encode($data, JSON_UNESCAPED_SLASHES);
    $stmt = db()->prepare('INSERT INTO integration_settings (provider, settings) VALUES (?,?) ON DUPLICATE KEY UPDATE settings=VALUES(settings), updated_at=NOW()');
    $stmt->execute([$provider, $json]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $section = $_POST['section'] ?? '';

    if ($section === 'whm') {
        save_provider('whm', [
            'host'       => trim($_POST['whm_host'] ?? ''),
            'user'       => trim($_POST['whm_user'] ?? 'root'),
            'token'      => trim($_POST['whm_token'] ?? ''),
            'port'       => (int)($_POST['whm_port'] ?? 2087),
            'ssl_verify' => !empty($_POST['whm_ssl_verify']),
        ]);
        flash_set('success', 'WHM settings saved.');
    } elseif ($section === 'namecheap') {
        save_provider('namecheap', [
            'api_user'   => trim($_POST['nc_api_user'] ?? ''),
            'api_key'    => trim($_POST['nc_api_key']  ?? ''),
            'client_ip'  => trim($_POST['nc_client_ip'] ?? ''),
            'sandbox'    => !empty($_POST['nc_sandbox']),
        ]);
        flash_set('success', 'Namecheap settings saved.');
    } elseif ($section === 'godaddy') {
        save_provider('godaddy', [
            'api_key'    => trim($_POST['gd_api_key']    ?? ''),
            'api_secret' => trim($_POST['gd_api_secret'] ?? ''),
            'sandbox'    => !empty($_POST['gd_sandbox']),
        ]);
        flash_set('success', 'GoDaddy settings saved.');
    } elseif ($section === 'smtp') {
        save_provider('smtp', [
            'host'       => trim($_POST['smtp_host']     ?? ''),
            'port'       => (int)($_POST['smtp_port']    ?? 587),
            'username'   => trim($_POST['smtp_username'] ?? ''),
            'password'   => trim($_POST['smtp_password'] ?? ''),
            'encryption' => $_POST['smtp_encryption']   ?? 'tls',
            'from_name'  => trim($_POST['smtp_from_name']  ?? 'OrbitHost'),
            'from_email' => trim($_POST['smtp_from_email'] ?? ''),
        ]);
        flash_set('success', 'SMTP settings saved.');
    }

    header('Location: ' . APP_URL . '/integrations/settings.php');
    exit;
}

// Refresh after save
$rows = db()->query('SELECT provider, settings FROM integration_settings')->fetchAll();
$cfg  = [];
foreach ($rows as $r) {
    $cfg[$r['provider']] = json_decode($r['settings'], true) ?? [];
}

function v(array $cfg, string $provider, string $key, $default = ''): string {
    return htmlspecialchars($cfg[$provider][$key] ?? $default);
}
function checked_if(bool $condition): string { return $condition ? 'checked' : ''; }

require_once '../includes/header.php';
?>

<div class="content-header">
  <h1 class="content-title">Integration Settings</h1>
  <a href="<?php echo APP_URL; ?>/admin/integrations/" class="btn btn-ghost"><i class="fas fa-arrow-left"></i> Back</a>
</div>

<div style="display:flex;flex-direction:column;gap:20px;max-width:740px">

  <!-- WHM -->
  <div class="card" id="whm">
    <div class="card-header">
      <span class="card-title"><i class="fas fa-server" style="color:var(--primary)"></i> WHM / cPanel</span>
      <span style="font-size:12px;color:var(--text-muted)">Web Host Manager API v1</span>
    </div>
    <div class="card-body">
      <form method="POST">
        <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>" />
        <input type="hidden" name="section"    value="whm" />
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
          <div class="form-group" style="grid-column:1/-1">
            <label class="form-label">WHM Host / IP <span class="req">*</span></label>
            <input type="text" name="whm_host" class="form-control" placeholder="e.g. server.orbithost.co.ke or 192.168.1.1"
                   value="<?php echo v($cfg, 'whm', 'host'); ?>" />
            <small class="form-hint">Without trailing slash. Port is set separately below.</small>
          </div>
          <div class="form-group">
            <label class="form-label">WHM Username</label>
            <input type="text" name="whm_user" class="form-control" placeholder="root" value="<?php echo v($cfg, 'whm', 'user', 'root'); ?>" />
          </div>
          <div class="form-group">
            <label class="form-label">Port</label>
            <input type="number" name="whm_port" class="form-control" value="<?php echo v($cfg, 'whm', 'port', '2087'); ?>" />
          </div>
          <div class="form-group" style="grid-column:1/-1">
            <label class="form-label">API Token <span class="req">*</span></label>
            <input type="password" name="whm_token" class="form-control" placeholder="WHM API token (from WHM > API Tokens)"
                   value="<?php echo v($cfg, 'whm', 'token'); ?>" autocomplete="new-password" />
            <small class="form-hint">Generate in WHM under Development > Manage API Tokens.</small>
          </div>
          <div class="form-group" style="grid-column:1/-1">
            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:13px">
              <input type="checkbox" name="whm_ssl_verify" <?php echo checked_if(!empty($cfg['whm']['ssl_verify'])); ?> />
              Verify SSL Certificate (disable for self-signed certs)
            </label>
          </div>
        </div>
        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save WHM Settings</button>
      </form>
    </div>
  </div>

  <!-- Namecheap -->
  <div class="card" id="namecheap">
    <div class="card-header">
      <span class="card-title"><i class="fas fa-globe" style="color:#d03801"></i> Namecheap</span>
      <span style="font-size:12px;color:var(--text-muted)">Domain Registrar API</span>
    </div>
    <div class="card-body">
      <form method="POST">
        <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>" />
        <input type="hidden" name="section"    value="namecheap" />
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
          <div class="form-group">
            <label class="form-label">API User <span class="req">*</span></label>
            <input type="text" name="nc_api_user" class="form-control" placeholder="Your Namecheap username"
                   value="<?php echo v($cfg, 'namecheap', 'api_user'); ?>" />
          </div>
          <div class="form-group">
            <label class="form-label">Client IP <span class="req">*</span></label>
            <input type="text" name="nc_client_ip" class="form-control" placeholder="Your server's public IP"
                   value="<?php echo v($cfg, 'namecheap', 'client_ip'); ?>" />
            <small class="form-hint">Must be whitelisted in Namecheap API settings.</small>
          </div>
          <div class="form-group" style="grid-column:1/-1">
            <label class="form-label">API Key <span class="req">*</span></label>
            <input type="password" name="nc_api_key" class="form-control" placeholder="Namecheap API key"
                   value="<?php echo v($cfg, 'namecheap', 'api_key'); ?>" autocomplete="new-password" />
          </div>
          <div class="form-group" style="grid-column:1/-1">
            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:13px">
              <input type="checkbox" name="nc_sandbox" <?php echo checked_if(!empty($cfg['namecheap']['sandbox'])); ?> />
              Use Sandbox (test environment — no real domains registered)
            </label>
          </div>
        </div>
        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Namecheap Settings</button>
      </form>
    </div>
  </div>

  <!-- GoDaddy -->
  <div class="card" id="godaddy">
    <div class="card-header">
      <span class="card-title"><i class="fas fa-globe" style="color:#1bab6b"></i> GoDaddy</span>
      <span style="font-size:12px;color:var(--text-muted)">Domain Registrar API</span>
    </div>
    <div class="card-body">
      <form method="POST">
        <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>" />
        <input type="hidden" name="section"    value="godaddy" />
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
          <div class="form-group">
            <label class="form-label">API Key <span class="req">*</span></label>
            <input type="password" name="gd_api_key" class="form-control" placeholder="GoDaddy API Key"
                   value="<?php echo v($cfg, 'godaddy', 'api_key'); ?>" autocomplete="new-password" />
          </div>
          <div class="form-group">
            <label class="form-label">API Secret <span class="req">*</span></label>
            <input type="password" name="gd_api_secret" class="form-control" placeholder="GoDaddy API Secret"
                   value="<?php echo v($cfg, 'godaddy', 'api_secret'); ?>" autocomplete="new-password" />
          </div>
          <div class="form-group" style="grid-column:1/-1">
            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:13px">
              <input type="checkbox" name="gd_sandbox" <?php echo checked_if(!empty($cfg['godaddy']['sandbox'])); ?> />
              Use OTE / Sandbox environment
            </label>
          </div>
        </div>
        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save GoDaddy Settings</button>
      </form>
    </div>
  </div>

  <!-- SMTP -->
  <div class="card" id="smtp">
    <div class="card-header">
      <span class="card-title"><i class="fas fa-envelope" style="color:var(--primary)"></i> SMTP Email</span>
      <span style="font-size:12px;color:var(--text-muted)">Outbound email configuration</span>
    </div>
    <div class="card-body">
      <div class="alert alert-info" style="margin-bottom:16px;font-size:13px">
        <i class="fas fa-info-circle"></i>
        These settings are stored for reference. To use SMTP, install <strong>PHPMailer</strong> and update the mail helper in <code>admin/includes/functions.php</code>.
      </div>
      <form method="POST">
        <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>" />
        <input type="hidden" name="section"    value="smtp" />
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
          <div class="form-group">
            <label class="form-label">SMTP Host <span class="req">*</span></label>
            <input type="text" name="smtp_host" class="form-control" placeholder="smtp.gmail.com"
                   value="<?php echo v($cfg, 'smtp', 'host'); ?>" />
          </div>
          <div class="form-group">
            <label class="form-label">Port</label>
            <input type="number" name="smtp_port" class="form-control" value="<?php echo v($cfg, 'smtp', 'port', '587'); ?>" />
          </div>
          <div class="form-group">
            <label class="form-label">Username</label>
            <input type="text" name="smtp_username" class="form-control" placeholder="you@gmail.com"
                   value="<?php echo v($cfg, 'smtp', 'username'); ?>" />
          </div>
          <div class="form-group">
            <label class="form-label">Password</label>
            <input type="password" name="smtp_password" class="form-control"
                   value="<?php echo v($cfg, 'smtp', 'password'); ?>" autocomplete="new-password" />
          </div>
          <div class="form-group">
            <label class="form-label">Encryption</label>
            <select name="smtp_encryption" class="form-control">
              <?php foreach (['tls' => 'TLS (STARTTLS)', 'ssl' => 'SSL', 'none' => 'None'] as $val => $lbl): ?>
                <option value="<?php echo $val; ?>" <?php echo ($cfg['smtp']['encryption'] ?? 'tls') === $val ? 'selected' : ''; ?>><?php echo $lbl; ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">From Name</label>
            <input type="text" name="smtp_from_name" class="form-control" value="<?php echo v($cfg, 'smtp', 'from_name', 'OrbitHost'); ?>" />
          </div>
          <div class="form-group" style="grid-column:1/-1">
            <label class="form-label">From Email</label>
            <input type="email" name="smtp_from_email" class="form-control" placeholder="noreply@orbithost.co.ke"
                   value="<?php echo v($cfg, 'smtp', 'from_email'); ?>" />
          </div>
        </div>
        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save SMTP Settings</button>
      </form>
    </div>
  </div>

</div>

<?php require_once '../includes/footer.php'; ?>
