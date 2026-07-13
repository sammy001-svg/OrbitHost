<?php
require_once '../../includes/config.php';
require_once '../../includes/db.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
require_once '../../includes/WHMClient.php';

auth_check();
$page_title = 'WHM Accounts';

// Load WHM config
$whm_cfg = [];
$whm     = null;
$error   = null;
$accounts = [];
$server_info = [];

try {
    $raw = db()->query("SELECT settings FROM integration_settings WHERE provider='whm'")->fetchColumn();
    $whm_cfg = $raw ? (json_decode($raw, true) ?? []) : [];
} catch (\Throwable $e) {
    $error = 'Cannot read integration settings: ' . htmlspecialchars($e->getMessage())
           . ' — Make sure <code>schema_v2.sql</code> has been imported into your database.';
}

if (!$error && empty($whm_cfg['host'])) {
    $error = 'WHM host is not set. <a href="' . APP_URL . '/integrations/#prov-whm">Add your WHM host</a>'
           . ' <small style="opacity:.6">(DB row ' . (empty($whm_cfg) ? 'missing — run schema_v2.sql' : 'exists but host is empty') . ')</small>';
} elseif (!$error && empty($whm_cfg['token'])) {
    $error = 'WHM API token is not set. <a href="' . APP_URL . '/integrations/#prov-whm">Add your API token</a> — generate one in WHM › Development › Manage API Tokens.';
} else {
    try {
        $whm = new WHMClient(
            $whm_cfg['host'],
            $whm_cfg['user'] ?? 'root',
            $whm_cfg['token'],
            (bool)($whm_cfg['ssl_verify'] ?? false)
        );
        $accounts    = $whm->listAccounts();
        $server_info = $whm->getServerLoad();
    } catch (\Throwable $e) {
        $error = 'Connection failed: ' . htmlspecialchars($e->getMessage());
    }
}

// Handle sync action — update disk/bw usage in our DB
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'sync' && $whm) {
    csrf_verify();
    $synced = 0;
    foreach ($accounts as $acc) {
        $user = $acc['user'] ?? '';
        if (!$user) continue;
        $disk = $whm->getDiskInfo($user);
        $bw   = $whm->getBandwidth($user);
        $db_row = db()->prepare('SELECT id FROM whm_accounts WHERE cpanel_user=?');
        $db_row->execute([$user]);
        if ($row = $db_row->fetchColumn()) {
            db()->prepare('UPDATE whm_accounts SET disk_used_mb=?, disk_limit_mb=?, bw_used_mb=?, synced_at=NOW() WHERE id=?')
                ->execute([$disk['disk_used'] ?? 0, $disk['disk_limit'] ?? 0, $bw['bw_used'] ?? 0, $row]);
        }
        $synced++;
    }
    flash_set('success', "Synced {$synced} accounts.");
    header('Location: ' . APP_URL . '/integrations/whm/');
    exit;
}

// ── Link an existing cPanel account to a client + portal invite ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'link') {
    csrf_verify();
    $cp_user = trim($_POST['cp_user'] ?? '');
    $domain  = trim($_POST['domain'] ?? '');
    $package = trim($_POST['package'] ?? '');
    $email   = trim($_POST['email'] ?? '');
    $first   = trim($_POST['first_name'] ?? '');
    $last    = trim($_POST['last_name'] ?? '');
    $invite  = !empty($_POST['send_invite']);

    try {
        if (!$cp_user) throw new RuntimeException('Missing cPanel username.');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('A valid client email is required.');

        // Find or create the client
        $stmt = db()->prepare('SELECT id, first_name FROM clients WHERE email = ?');
        $stmt->execute([$email]);
        $client = $stmt->fetch();
        if ($client) {
            $client_id = (int)$client['id'];
            $first = $first ?: $client['first_name'];
        } else {
            db()->prepare('INSERT INTO clients (first_name, last_name, email, status) VALUES (?,?,?,"active")')
                ->execute([$first ?: 'Client', $last ?: $cp_user, $email]);
            $client_id = (int) db()->lastInsertId();
        }

        // Service record (skip when this cPanel account is already linked)
        $dup = db()->prepare('SELECT id FROM client_services WHERE provider_key = "whm" AND (username = ? OR remote_id = ?)');
        $dup->execute([$cp_user, $cp_user]);
        if (!$dup->fetchColumn()) {
            db()->prepare('INSERT INTO client_services
                (client_id, label, domain, category, provider_category, provider_key, remote_id, username, server_host, package, billing_cycle, amount, status, start_date)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,CURDATE())')
                ->execute([
                    $client_id, 'cPanel Hosting — ' . ($domain ?: $cp_user), $domain ?: null,
                    'hosting', 'panel', 'whm', $cp_user, $cp_user,
                    $whm_cfg['host'] ?? null, $package ?: null, 'monthly', 0, 'active',
                ]);
        }

        // Portal invite (so the client can set a password and log in)
        $invite_note = '';
        if ($invite) {
            db()->prepare('DELETE FROM portal_invites WHERE client_id = ? AND accepted_at IS NULL')->execute([$client_id]);
            $token = bin2hex(random_bytes(32));
            db()->prepare('INSERT INTO portal_invites (client_id, token, expires_at) VALUES (?,?,DATE_ADD(NOW(), INTERVAL 48 HOUR))')
                ->execute([$client_id, $token]);

            $prot = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $hostn = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $root = rtrim(str_replace('\\', '/', dirname(__DIR__, 3)), '/');
            $doc  = rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
            $rel  = ($doc && str_starts_with($root, $doc)) ? substr($root, strlen($doc)) : '';
            $link = $prot . '://' . $hostn . $rel . '/portal/accept-invite.php?token=' . $token;

            require_once '../../includes/Mailer.php';
            $r = Mailer::fromConfig()->send($email, 'Your OrbitHost client portal access',
                '<p>Hello ' . h($first ?: 'there') . ',</p>'
              . '<p>Your hosting account <strong>' . h($domain ?: $cp_user) . '</strong> can now be managed in the OrbitHost client portal — invoices, support and one-click cPanel login.</p>'
              . '<p><a href="' . h($link) . '" style="background:#1A8A45;color:#fff;padding:10px 18px;border-radius:8px;text-decoration:none;display:inline-block">Activate your account</a></p>'
              . '<p style="color:#64748b;font-size:13px">Or copy this link (valid for 48 hours):<br>' . h($link) . '</p>');
            $invite_note = !empty($r['success'])
                ? ' Invite email sent to ' . $email . '.'
                : ' Invite email failed (' . ($r['message'] ?? 'mail error') . ') — resend from the client\'s page.';
        }

        log_activity('whm_link_client', 'client', $client_id, "Linked cPanel {$cp_user}");
        flash_set('success', "cPanel account '{$cp_user}' linked to client." . $invite_note);
    } catch (\Throwable $e) {
        flash_set('error', 'Link failed: ' . $e->getMessage());
    }
    header('Location: ' . APP_URL . '/integrations/whm/');
    exit;
}

// Map cPanel usernames → linked clients (for the table)
$linked = [];
try {
    foreach (db()->query("SELECT cs.username u, cs.remote_id r, cs.client_id, CONCAT(c.first_name,' ',c.last_name) cname
                          FROM client_services cs JOIN clients c ON c.id = cs.client_id
                          WHERE cs.provider_key = 'whm'")->fetchAll() as $row) {
        if ($row['u']) $linked[$row['u']] = $row;
        if ($row['r']) $linked[$row['r']] = $row;
    }
} catch (\Throwable $e) {
    // client_services not migrated yet — table shows Link buttons but saving needs schema_v3
}

require_once '../../includes/header.php';
?>

<div class="content-header">
  <h1 class="content-title">WHM Accounts</h1>
  <div style="display:flex;gap:8px">
    <?php if ($whm): ?>
    <form method="POST" style="display:inline">
      <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>" />
      <input type="hidden" name="action"     value="sync" />
      <button type="submit" class="btn btn-ghost"><i class="fas fa-arrows-rotate"></i> Sync Usage</button>
    </form>
    <?php endif; ?>
    <a href="<?php echo APP_URL; ?>/integrations/whm/packages.php" class="btn btn-ghost"><i class="fas fa-cubes"></i> Packages</a>
    <a href="<?php echo APP_URL; ?>/integrations/whm/provision.php" class="btn btn-primary"><i class="fas fa-plus"></i> Provision Account</a>
    <a href="<?php echo APP_URL; ?>/integrations/" class="btn btn-ghost"><i class="fas fa-arrow-left"></i> Back</a>
  </div>
</div>

<?php if ($error): ?>
  <div class="alert alert-danger"><i class="fas fa-triangle-exclamation"></i> <?php echo $error; ?></div>
<?php else: ?>

  <?php if (!empty($server_info)): ?>
  <div class="stat-grid" style="margin-bottom:20px;grid-template-columns:repeat(3,1fr)">
    <div class="stat-card">
      <div class="stat-icon navy"><i class="fas fa-microchip"></i></div>
      <div>
        <div class="stat-label">Load (1 min)</div>
        <div class="stat-value"><?php echo number_format((float)($server_info['one'] ?? 0), 2); ?></div>
      </div>
    </div>
    <div class="stat-card">
      <div class="stat-icon orange"><i class="fas fa-gauge"></i></div>
      <div>
        <div class="stat-label">Load (15 min)</div>
        <div class="stat-value"><?php echo number_format((float)($server_info['fifteen'] ?? 0), 2); ?></div>
      </div>
    </div>
    <div class="stat-card">
      <div class="stat-icon green"><i class="fas fa-users"></i></div>
      <div>
        <div class="stat-label">cPanel Accounts</div>
        <div class="stat-value"><?php echo count($accounts); ?></div>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <div class="card">
    <div class="card-header">
      <span class="card-title">All cPanel Accounts</span>
      <span style="font-size:12px;color:var(--text-muted)"><?php echo count($accounts); ?> accounts on server</span>
    </div>
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>Username</th>
            <th>Domain</th>
            <th>Package</th>
            <th>Disk</th>
            <th>Email</th>
            <th>Status</th>
            <th>Client</th>
            <th>Created</th>
          </tr>
        </thead>
        <tbody>
          <?php if ($accounts): foreach ($accounts as $i => $acc): ?>
            <tr>
              <td><strong><?php echo htmlspecialchars($acc['user'] ?? '-'); ?></strong></td>
              <td><?php echo htmlspecialchars($acc['domain'] ?? '-'); ?></td>
              <td><?php echo htmlspecialchars($acc['plan'] ?? '-'); ?></td>
              <td>
                <?php
                // WHM sends values like "1234", "1234M" or "unlimited" — keep digits only
                $toMb  = fn($v) => ($n = preg_replace('/[^0-9.]/', '', (string)($v ?? ''))) === '' ? 0.0 : (float)$n;
                $used  = $toMb($acc['diskused']  ?? 0);
                $limit = $toMb($acc['disklimit'] ?? 0);
                $pct   = $limit > 0 ? min(100, (int)round($used / $limit * 100)) : 0;
                ?>
                <div style="font-size:12px;margin-bottom:3px"><?php echo number_format($used); ?> / <?php echo $limit > 0 ? number_format($limit) . ' MB' : '∞'; ?></div>
                <?php if ($limit > 0): ?>
                <div style="background:#e5e7eb;border-radius:99px;height:5px;width:80px">
                  <div style="background:<?php echo $pct > 90 ? 'var(--danger)' : ($pct > 70 ? 'var(--warning)' : 'var(--success)'); ?>;height:5px;border-radius:99px;width:<?php echo $pct; ?>%"></div>
                </div>
                <?php endif; ?>
              </td>
              <td><?php echo htmlspecialchars($acc['email'] ?? '-'); ?></td>
              <td>
                <?php if (!empty($acc['suspended'])): ?>
                  <span class="badge badge-danger">Suspended</span>
                <?php else: ?>
                  <span class="badge badge-success">Active</span>
                <?php endif; ?>
              </td>
              <td>
                <?php $lk = $linked[$acc['user'] ?? ''] ?? null; ?>
                <?php if ($lk): ?>
                  <a href="<?php echo APP_URL; ?>/clients/view.php?id=<?php echo (int)$lk['client_id']; ?>" class="badge badge-success" style="text-decoration:none"><i class="fas fa-user-check"></i> <?php echo htmlspecialchars($lk['cname']); ?></a>
                <?php else: ?>
                  <button type="button" class="btn btn-ghost btn-xs" data-drawer-open="drawer-link-<?php echo $i; ?>"><i class="fas fa-user-plus"></i> Link to Client</button>
                <?php endif; ?>
              </td>
              <td style="font-size:12px;color:var(--text-muted)"><?php echo htmlspecialchars($acc['startdate'] ?? '-'); ?></td>
            </tr>
          <?php endforeach; else: ?>
            <tr><td colspan="8" style="text-align:center;padding:32px;color:var(--text-muted)">No accounts found on this server.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

<!-- Link-to-client drawers -->
<?php foreach ($accounts as $i => $acc): if (isset($linked[$acc['user'] ?? ''])) continue; ?>
  <div class="drawer-scrim" id="drawer-link-<?php echo $i; ?>-scrim"></div>
  <div class="drawer" id="drawer-link-<?php echo $i; ?>">
    <div class="drawer-head">
      <div>
        <div style="font-weight:700">Link <?php echo htmlspecialchars($acc['user'] ?? ''); ?> to a client</div>
        <div class="text-muted" style="font-size:11.5px"><?php echo htmlspecialchars($acc['domain'] ?? ''); ?></div>
      </div>
      <button type="button" class="drawer-close" data-drawer-close>&times;</button>
    </div>
    <form method="POST" style="display:contents">
      <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>" />
      <input type="hidden" name="action"  value="link" />
      <input type="hidden" name="cp_user" value="<?php echo htmlspecialchars($acc['user'] ?? ''); ?>" />
      <input type="hidden" name="domain"  value="<?php echo htmlspecialchars($acc['domain'] ?? ''); ?>" />
      <input type="hidden" name="package" value="<?php echo htmlspecialchars($acc['plan'] ?? ''); ?>" />
      <div class="drawer-body">
        <div class="alert alert-info" style="margin-bottom:16px;font-size:12.5px">
          <i class="fas fa-circle-info"></i> Creates (or finds) the client, attaches this cPanel account as a service, and emails a portal activation link.
        </div>
        <div class="form-group">
          <label class="form-label">Client email <span class="req">*</span></label>
          <input type="email" name="email" class="form-control" required
                 value="<?php echo htmlspecialchars($acc['email'] ?? ''); ?>" placeholder="client@example.com" />
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
          <div class="form-group"><label class="form-label">First name</label><input type="text" name="first_name" class="form-control" /></div>
          <div class="form-group"><label class="form-label">Last name</label><input type="text" name="last_name" class="form-control" /></div>
        </div>
        <div class="form-group">
          <label class="switch"><input type="checkbox" name="send_invite" value="1" checked /><span class="track"></span><span>Email a portal activation invite (valid 48 h)</span></label>
        </div>
      </div>
      <div class="drawer-foot">
        <button type="submit" class="btn btn-primary"><i class="fas fa-user-plus"></i> Link &amp; Invite</button>
        <button type="button" class="btn btn-ghost" data-drawer-close>Cancel</button>
      </div>
    </form>
  </div>
<?php endforeach; ?>

<?php endif; ?>

<?php require_once '../../includes/footer.php'; ?>
