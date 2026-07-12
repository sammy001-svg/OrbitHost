<?php
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

auth_check();
$page_title = 'Integration Settings';

// REPLACE INTO: deletes old row (if UNIQUE match) then inserts fresh — zero rowCount() ambiguity
function save_provider(string $provider, array $data): void
{
    $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    db()->prepare('REPLACE INTO integration_settings (provider, settings) VALUES (?, ?)')
        ->execute([$provider, $json]);
}

// Load current settings
function load_cfg(): array
{
    $rows = db()->query('SELECT provider, settings FROM integration_settings')->fetchAll();
    $cfg  = [];
    foreach ($rows as $r) {
        $cfg[$r['provider']] = json_decode($r['settings'], true) ?? [];
    }
    return $cfg;
}

$cfg     = load_cfg();
$error   = null;
$saved   = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $section = $_POST['section'] ?? '';

    try {
        if ($section === '_noop') {
            // Refresh button — reload only, no write
        } elseif ($section === 'whm') {
            save_provider('whm', [
                'host'       => trim($_POST['whm_host']  ?? ''),
                'user'       => trim($_POST['whm_user']  ?? 'root'),
                'token'      => trim($_POST['whm_token'] ?? ''),
                'port'       => (int)($_POST['whm_port'] ?? 2087),
                'ssl_verify' => !empty($_POST['whm_ssl_verify']),
            ]);
            $saved = 'whm';
        } elseif ($section === 'namecheap') {
            save_provider('namecheap', [
                'api_user'  => trim($_POST['nc_api_user']  ?? ''),
                'api_key'   => trim($_POST['nc_api_key']   ?? ''),
                'client_ip' => trim($_POST['nc_client_ip'] ?? ''),
                'sandbox'   => !empty($_POST['nc_sandbox']),
            ]);
            $saved = 'namecheap';
        } elseif ($section === 'godaddy') {
            save_provider('godaddy', [
                'api_key'    => trim($_POST['gd_api_key']    ?? ''),
                'api_secret' => trim($_POST['gd_api_secret'] ?? ''),
                'sandbox'    => !empty($_POST['gd_sandbox']),
            ]);
            $saved = 'godaddy';
        } elseif ($section === 'smtp') {
            save_provider('smtp', [
                'host'       => trim($_POST['smtp_host']       ?? ''),
                'port'       => (int)($_POST['smtp_port']      ?? 587),
                'username'   => trim($_POST['smtp_username']   ?? ''),
                'password'   => trim($_POST['smtp_password']   ?? ''),
                'encryption' => $_POST['smtp_encryption']      ?? 'tls',
                'from_name'  => trim($_POST['smtp_from_name']  ?? 'OrbitHost'),
                'from_email' => trim($_POST['smtp_from_email'] ?? ''),
            ]);
            $saved = 'smtp';
        }

        // Reload so the form shows what was just saved
        $cfg = load_cfg();

    } catch (\Throwable $e) {
        $error = 'Database error: ' . $e->getMessage()
               . ' — Check that schema_v2.sql has been imported and the integration_settings table exists.';
    }
}

function v(array $cfg, string $provider, string $key, string $default = ''): string
{
    return htmlspecialchars($cfg[$provider][$key] ?? $default);
}
function checked_if(bool $cond): string { return $cond ? 'checked' : ''; }
function saved_alert(string $section, ?string $saved): void
{
    if ($saved === $section) {
        echo '<div class="alert alert-success" style="margin-bottom:16px"><i class="fas fa-check-circle"></i> Settings saved successfully.</div>';
    }
}

require_once '../includes/header.php';
?>

<div class="content-header">
  <h1 class="content-title">Integration Settings</h1>
  <a href="<?php echo APP_URL; ?>/integrations/" class="btn btn-ghost"><i class="fas fa-arrow-left"></i> Back</a>
</div>

<?php if ($error): ?>
  <div class="alert alert-danger"><i class="fas fa-triangle-exclamation"></i> <?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<!-- DB status strip — lets you confirm what's actually persisted without guessing -->
<div class="card" style="margin-bottom:20px;max-width:740px">
  <div class="card-header">
    <span class="card-title" style="font-size:13px"><i class="fas fa-database" style="color:var(--primary)"></i> Currently saved in database</span>
    <form method="POST" style="display:inline">
      <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>" />
      <input type="hidden" name="section" value="_noop" />
      <button type="submit" class="btn btn-ghost btn-sm" style="font-size:11px"><i class="fas fa-refresh"></i> Refresh</button>
    </form>
  </div>
  <div style="padding:12px 16px;display:flex;flex-wrap:wrap;gap:8px">
    <?php
    $checks = [
      'whm'       => ['WHM Host', 'host'],
      'namecheap' => ['Namecheap API User', 'api_user'],
      'godaddy'   => ['GoDaddy Key', 'api_key'],
      'smtp'      => ['SMTP Host', 'host'],
    ];
    foreach ($checks as $prov => [$label, $key]):
        $val = $cfg[$prov][$key] ?? '';
        $ok  = !empty($val);
    ?>
      <span style="display:inline-flex;align-items:center;gap:5px;font-size:12px;padding:4px 10px;border-radius:99px;background:<?php echo $ok ? '#dcfce7' : '#fef2f2'; ?>;color:<?php echo $ok ? '#166534' : '#991b1b'; ?>">
        <i class="fas <?php echo $ok ? 'fa-check' : 'fa-xmark'; ?>"></i>
        <?php echo $label; ?>: <?php echo $ok ? '<strong>' . htmlspecialchars(substr($val, 0, 20) . (strlen($val) > 20 ? '…' : '')) . '</strong>' : 'not set'; ?>
      </span>
    <?php endforeach; ?>
  </div>
</div>

<div style="display:flex;flex-direction:column;gap:20px;max-width:740px">

  <!-- WHM -->
  <div class="card" id="whm">
    <div class="card-header">
      <span class="card-title"><i class="fas fa-server" style="color:var(--primary)"></i> WHM / cPanel</span>
      <span style="font-size:12px;color:var(--text-muted)">Web Host Manager API v1</span>
    </div>
    <div class="card-body">
      <?php saved_alert('whm', $saved); ?>
      <form method="POST">
        <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>" />
        <input type="hidden" name="section"    value="whm" />
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
          <div class="form-group" style="grid-column:1/-1">
            <label class="form-label">WHM Host / IP <span class="req">*</span></label>
            <input type="text" name="whm_host" class="form-control"
                   placeholder="e.g. server.orbithost.co.ke or 192.168.1.1"
                   value="<?php echo v($cfg, 'whm', 'host'); ?>" />
            <small class="form-hint">Without trailing slash. Port is set separately below.</small>
          </div>
          <div class="form-group">
            <label class="form-label">WHM Username</label>
            <input type="text" name="whm_user" class="form-control" placeholder="root"
                   value="<?php echo v($cfg, 'whm', 'user', 'root'); ?>" />
          </div>
          <div class="form-group">
            <label class="form-label">Port</label>
            <input type="number" name="whm_port" class="form-control"
                   value="<?php echo v($cfg, 'whm', 'port', '2087'); ?>" />
          </div>
          <div class="form-group" style="grid-column:1/-1">
            <label class="form-label">API Token <span class="req">*</span></label>
            <div style="position:relative">
              <input type="text" name="whm_token" id="whm_token" class="form-control"
                     placeholder="Paste your WHM API token here"
                     value="<?php echo v($cfg, 'whm', 'token'); ?>"
                     style="font-family:monospace;font-size:12px;padding-right:80px" />
              <button type="button" onclick="var f=document.getElementById('whm_token');f.type=f.type==='text'?'password':'text';this.textContent=f.type==='text'?'Hide':'Show'"
                      style="position:absolute;right:8px;top:50%;transform:translateY(-50%);background:none;border:none;color:var(--text-muted);cursor:pointer;font-size:12px">Hide</button>
            </div>
            <small class="form-hint">Generate in WHM › Development › Manage API Tokens. Paste the full token — it won't be truncated.</small>
          </div>
          <div class="form-group" style="grid-column:1/-1">
            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:13px">
              <input type="checkbox" name="whm_ssl_verify"
                     <?php echo checked_if(!empty($cfg['whm']['ssl_verify'])); ?> />
              Verify SSL Certificate <span style="color:var(--text-muted)">(leave unchecked for self-signed certs)</span>
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
      <?php saved_alert('namecheap', $saved); ?>
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
              <input type="checkbox" name="nc_sandbox"
                     <?php echo checked_if(!empty($cfg['namecheap']['sandbox'])); ?> />
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
      <?php saved_alert('godaddy', $saved); ?>
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
              <input type="checkbox" name="gd_sandbox"
                     <?php echo checked_if(!empty($cfg['godaddy']['sandbox'])); ?> />
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
      <?php saved_alert('smtp', $saved); ?>
      <div class="alert alert-info" style="margin-bottom:16px;font-size:13px">
        <i class="fas fa-info-circle"></i>
        Settings are stored here for reference. To send real emails, install <strong>PHPMailer</strong> via Composer and update the mail helper in <code>admin/includes/functions.php</code>.
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
            <input type="number" name="smtp_port" class="form-control"
                   value="<?php echo v($cfg, 'smtp', 'port', '587'); ?>" />
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
                <option value="<?php echo $val; ?>"
                  <?php echo ($cfg['smtp']['encryption'] ?? 'tls') === $val ? 'selected' : ''; ?>>
                  <?php echo $lbl; ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">From Name</label>
            <input type="text" name="smtp_from_name" class="form-control"
                   value="<?php echo v($cfg, 'smtp', 'from_name', 'OrbitHost'); ?>" />
          </div>
          <div class="form-group" style="grid-column:1/-1">
            <label class="form-label">From Email</label>
            <input type="email" name="smtp_from_email" class="form-control"
                   placeholder="noreply@orbithost.co.ke"
                   value="<?php echo v($cfg, 'smtp', 'from_email'); ?>" />
          </div>
        </div>
        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save SMTP Settings</button>
      </form>
    </div>
  </div>

</div>

<?php require_once '../includes/footer.php'; ?>
