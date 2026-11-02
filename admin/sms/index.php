<?php
/**
 * Orbit Cloud — SMS overview: balance, campaigns and the message log.
 */
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/Sms.php';

auth_check();
require_role('admin', APP_URL . '/dashboard.php');
Sms::ensureSchema();
$page_title = 'SMS';

// ── Setup actions: sender ID, enable/disable, send queue now ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['save_sender', 'enable', 'disable', 'process'], true)) {
    csrf_verify();
    $act = $_POST['action'];
    $row = db()->query("SELECT settings, is_active FROM integration_settings WHERE provider = 'shanfix_sms'")->fetch();
    $cfg = $row ? (json_decode((string) $row['settings'], true) ?: []) : [];

    if ($act === 'save_sender') {
        // Sender IDs are up to 11 letters/digits on Kenyan networks.
        $sid = strtoupper(preg_replace('/[^A-Za-z0-9 _.\-]/', '', trim((string) ($_POST['sender_id'] ?? ''))));
        if ($sid !== '' && (strlen($sid) < 3 || strlen($sid) > 11)) {
            flash_set('error', 'A sender ID is 3–11 letters or digits, exactly as approved on your Shanfix SMS account.');
        } else {
            $cfg['sender_id'] = $sid;
            db()->prepare("INSERT INTO integration_settings (provider, settings, is_active) VALUES ('shanfix_sms', ?, ?)
                           ON DUPLICATE KEY UPDATE settings = VALUES(settings)")
                ->execute([json_encode($cfg, JSON_UNESCAPED_SLASHES), (int) ($row['is_active'] ?? 0)]);
            log_activity('sms_sender_id', 'integration', 0, $sid !== '' ? 'Sender ID set to ' . $sid : 'Sender ID cleared (account default)');
            flash_set('success', $sid !== '' ? 'Sender ID saved: ' . $sid . '. New messages go out under it.' : 'Sender ID cleared — your Shanfix account default will be used.');
        }
    } elseif ($act === 'enable' || $act === 'disable') {
        if ($act === 'enable' && !Provider::isConfigured('shanfix_sms')) {
            flash_set('error', 'Add the Client ID and API key first (Providers › Shanfix SMS › Configure), then enable.');
        } else {
            db()->prepare("UPDATE integration_settings SET is_active = ? WHERE provider = 'shanfix_sms'")->execute([$act === 'enable' ? 1 : 0]);
            log_activity('provider_toggle', 'integration', 0, 'Shanfix SMS ' . $act . 'd from the SMS page');
            flash_set('success', 'Shanfix SMS ' . ($act === 'enable' ? 'enabled — queued messages will start going out.' : 'disabled.'));
        }
    } elseif ($act === 'process') {
        // Everything queued and due, now — including notifications waiting
        // on a retry — rather than waiting for the cron.
        db()->exec("UPDATE sms_messages SET next_attempt_at = NOW() WHERE status = 'queued' AND next_attempt_at > NOW()
                    AND (campaign_id IS NULL OR campaign_id IN (SELECT id FROM sms_campaigns WHERE status IN ('sending','completed')))");
        $q = Sms::processQueue(Sms::SINGLE_PER_RUN, Sms::BULK_PER_RUN);
        if (!empty($q['message'])) flash_set('error', 'Nothing sent: ' . $q['message']);
        elseif (!empty($q['locked'])) flash_set('info', 'The queue is being worked right now — refresh in a moment.');
        else {
            $left = (int) db()->query("SELECT COUNT(*) FROM sms_messages WHERE status = 'queued'")->fetchColumn();
            $err  = (string) db()->query("SELECT error FROM sms_messages WHERE error IS NOT NULL AND status IN ('queued','failed') ORDER BY id DESC LIMIT 1")->fetchColumn();
            flash_set($q['failed'] && !$q['sent'] ? 'error' : 'success',
                sprintf('%d sent, %d failed, %d will retry. %d still queued.', $q['sent'], $q['failed'], $q['retrying'], $left)
                . ($q['failed'] && $err !== '' ? ' Shanfix said: ' . $err : ''));
        }
    }
    header('Location: ' . APP_URL . '/sms/index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'retry') {
    csrf_verify();
    $mid = (int) ($_POST['id'] ?? 0);
    db()->prepare("UPDATE sms_messages SET status = 'queued', attempts = 0, error = NULL, next_attempt_at = NOW() WHERE id = ? AND status = 'failed'")->execute([$mid]);
    $q = Sms::processQueue();
    flash_set('info', 'Retried — ' . $q['sent'] . ' sent, ' . $q['failed'] . ' failed.');
    header('Location: ' . APP_URL . '/sms/index.php#log');
    exit;
}

$provider = Sms::provider();
$balance  = null; $balance_err = '';
if ($provider) {
    $b = Sms::client()->balance();
    $b['success'] ? $balance = $b['units'] : $balance_err = $b['message'];
}

$today = db()->query("SELECT COUNT(*) n, COALESCE(SUM(status IN ('sent','delivered')),0) ok, COALESCE(SUM(status='failed'),0) bad
                      FROM sms_messages WHERE created_at >= CURDATE()")->fetch();
$queued = (int) db()->query("SELECT COUNT(*) FROM sms_messages WHERE status = 'queued'")->fetchColumn();

// ── Setup & delivery health ──
$prow       = db()->query("SELECT settings, is_active FROM integration_settings WHERE provider = 'shanfix_sms'")->fetch();
$pcfg       = $prow ? (json_decode((string) $prow['settings'], true) ?: []) : [];
$configured = Provider::isConfigured('shanfix_sms');
$enabled    = $prow && (int) $prow['is_active'] === 1;
$sender_id  = trim((string) ($pcfg['sender_id'] ?? ''));
$cron_ago   = Sms::cronMinutesAgo();
$q_info     = db()->query("SELECT COUNT(*) n,
                                  COALESCE(MAX(TIMESTAMPDIFF(MINUTE, created_at, NOW())), 0) oldest,
                                  COALESCE(SUM(next_attempt_at > NOW()), 0) waiting
                           FROM sms_messages WHERE status = 'queued'")->fetch();
$last_error = (string) db()->query("SELECT error FROM sms_messages WHERE error IS NOT NULL AND status IN ('queued','failed')
                                    AND created_at > DATE_SUB(NOW(), INTERVAL 2 DAY) ORDER BY id DESC LIMIT 1")->fetchColumn();
// PHP and MySQL must agree on the time, or the queue misjudges what's due.
$clock_skew = (int) db()->query("SELECT TIMESTAMPDIFF(MINUTE, NOW(), '" . date('Y-m-d H:i:s') . "')")->fetchColumn();

$campaigns = db()->query("SELECT c.*,
        (SELECT COUNT(*) FROM sms_messages m WHERE m.campaign_id = c.id AND m.status IN ('sent','delivered')) ok,
        (SELECT COUNT(*) FROM sms_messages m WHERE m.campaign_id = c.id AND m.status = 'failed') bad
    FROM sms_campaigns c ORDER BY c.id DESC LIMIT 25")->fetchAll();

// Message log
$f_status = in_array($_GET['status'] ?? '', ['queued', 'sent', 'delivered', 'failed', 'cancelled'], true) ? $_GET['status'] : '';
$f_kind   = in_array($_GET['kind'] ?? '', ['notify', 'campaign'], true) ? $_GET['kind'] : '';
$f_q      = trim((string) ($_GET['q'] ?? ''));
$where = []; $params = [];
if ($f_status) { $where[] = 'm.status = ?'; $params[] = $f_status; }
if ($f_kind === 'notify')   $where[] = "m.type LIKE 'notify:%'";
if ($f_kind === 'campaign') $where[] = 'm.campaign_id IS NOT NULL';
if ($f_q !== '') { $where[] = '(m.phone LIKE ? OR c.first_name LIKE ? OR c.last_name LIKE ? OR m.message LIKE ?)'; array_push($params, "%$f_q%", "%$f_q%", "%$f_q%", "%$f_q%"); }
$log = db()->prepare("SELECT m.*, CONCAT(COALESCE(c.first_name,''),' ',COALESCE(c.last_name,'')) client_name, sc.name campaign_name
                      FROM sms_messages m
                      LEFT JOIN clients c ON c.id = m.client_id
                      LEFT JOIN sms_campaigns sc ON sc.id = m.campaign_id"
                    . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY m.id DESC LIMIT 100');
$log->execute($params);
$log = $log->fetchAll();

$badge = ['queued' => 'badge-warning', 'sent' => 'badge-primary', 'delivered' => 'badge-success', 'failed' => 'badge-danger', 'cancelled' => 'badge-secondary',
          'scheduled' => 'badge-warning', 'sending' => 'badge-primary', 'completed' => 'badge-success'];
$kind_label = function (array $m): string {
    if ($m['campaign_id']) return 'Campaign';
    if (str_starts_with($m['type'], 'notify:')) return ucwords(str_replace('_', ' ', substr($m['type'], 7)));
    return ucfirst($m['type']);
};

require_once '../includes/header.php';
?>

<div class="content-header">
  <div>
    <h1 class="content-title">SMS</h1>
    <p class="page-subtitle">Text-message notifications and campaigns to clients through Shanfix SMS.</p>
  </div>
  <div style="display:flex;gap:8px">
    <a href="<?php echo APP_URL; ?>/integrations/#prov-shanfix_sms" class="btn btn-ghost"><i class="fas fa-plug"></i> Settings</a>
    <a href="<?php echo APP_URL; ?>/sms/compose.php" class="btn btn-primary"><i class="fas fa-plus"></i> New campaign</a>
  </div>
</div>

<?php if (!$provider): ?>
  <div class="alert alert-warning"><i class="fas fa-triangle-exclamation"></i>
    Shanfix SMS is not enabled. Add your Client ID and API key in <a href="<?php echo APP_URL; ?>/integrations/#prov-shanfix_sms" style="font-weight:600">Providers</a>, then enable it.</div>
<?php elseif ($balance_err): ?>
  <div class="alert alert-error"><i class="fas fa-circle-exclamation"></i> Could not reach Shanfix SMS: <?php echo h($balance_err); ?></div>
<?php endif; ?>

<div class="stat-grid">
  <div class="stat-card"><div class="stat-icon green"><i class="fas fa-coins"></i></div>
    <div><div class="stat-label">SMS balance</div><div class="stat-value" style="font-size:22px"><?php echo $balance !== null ? number_format($balance, 2) . ' <span style="font-size:13px;color:var(--text-muted)">units</span>' : '—'; ?></div></div></div>
  <div class="stat-card"><div class="stat-icon navy"><i class="fas fa-paper-plane"></i></div>
    <div><div class="stat-label">Sent today</div><div class="stat-value"><?php echo (int) $today['ok']; ?></div></div></div>
  <div class="stat-card"><div class="stat-icon red"><i class="fas fa-circle-xmark"></i></div>
    <div><div class="stat-label">Failed today</div><div class="stat-value"><?php echo (int) $today['bad']; ?></div></div></div>
  <div class="stat-card"><div class="stat-icon orange"><i class="fas fa-hourglass-half"></i></div>
    <div><div class="stat-label">In queue</div><div class="stat-value"><?php echo $queued; ?></div></div></div>
</div>

<?php
  $cron_cmd = '/usr/local/bin/php ' . (realpath(__DIR__ . '/../cron/sms.php') ?: 'admin/cron/sms.php');
  // [state ok|warn|bad, label, detail]
  $checks = [];
  $checks[] = !$configured ? ['bad', 'Provider', 'Client ID / API key missing — add them in Providers › Shanfix SMS › Configure.']
            : (!$enabled ? ['bad', 'Provider', 'Configured but switched OFF — nothing is sent until you enable it.']
            : ['ok', 'Provider', 'Shanfix SMS is enabled.']);
  $checks[] = $sender_id !== '' ? ['ok', 'Sender ID', $sender_id]
            : ['warn', 'Sender ID', 'Not set — messages use your Shanfix account\'s default sender ID. If the account has none approved, every send fails.'];
  $checks[] = $balance_err !== '' ? ['bad', 'Connection', $balance_err]
            : ($balance === null ? ['warn', 'Connection', 'Not checked (provider not enabled).']
            : ($balance <= 0 ? ['bad', 'Balance', 'No SMS units left — top up your Shanfix account.'] : ['ok', 'Balance', number_format($balance, 2) . ' units']));
  $checks[] = $cron_ago === null ? ['bad', 'SMS cron', 'Has never run. Campaigns and retries only go out when it runs every minute.']
            : ($cron_ago > 5 ? ['warn', 'SMS cron', 'Last ran ' . $cron_ago . ' minutes ago — it should run every minute.'] : ['ok', 'SMS cron', 'Ran ' . ($cron_ago === 0 ? 'less than a minute' : $cron_ago . ' min') . ' ago.']);
  if ((int) $q_info['n'] > 0) {
    $checks[] = [(int) $q_info['oldest'] > 10 ? 'warn' : 'ok', 'Queue',
                 (int) $q_info['n'] . ' queued, oldest ' . (int) $q_info['oldest'] . ' min' . ((int) $q_info['waiting'] ? ' · ' . (int) $q_info['waiting'] . ' waiting to retry' : '')];
  }
  if (abs($clock_skew) > 2) {
    $checks[] = ['bad', 'Server clock', 'PHP and MySQL disagree by ' . abs($clock_skew) . ' minutes — set APP_TIMEZONE in .env.'];
  }
  if ($last_error !== '') $checks[] = ['warn', 'Last error', $last_error];
  $icon = ['ok' => ['fa-circle-check', 'var(--green)'], 'warn' => ['fa-triangle-exclamation', 'var(--warning)'], 'bad' => ['fa-circle-xmark', 'var(--danger)']];
?>
<div class="grid-2" style="align-items:start;margin-bottom:20px">
  <div class="card">
    <div class="card-header"><div class="card-title"><i class="fas fa-stethoscope"></i> SMS setup &amp; delivery</div></div>
    <div class="card-body">
      <?php foreach ($checks as [$st, $lbl, $txt]): ?>
        <div style="display:flex;gap:10px;align-items:flex-start;padding:8px 0;border-bottom:1px solid var(--border)">
          <i class="fas <?php echo $icon[$st][0]; ?>" style="color:<?php echo $icon[$st][1]; ?>;margin-top:3px;width:16px"></i>
          <div style="flex:1;min-width:0">
            <div style="font-weight:700;font-size:13px;color:var(--text)"><?php echo h($lbl); ?></div>
            <div style="font-size:12.5px;color:var(--text-muted);word-break:break-word"><?php echo h($txt); ?></div>
          </div>
        </div>
      <?php endforeach; ?>
      <?php if ($cron_ago === null || $cron_ago > 5): ?>
        <div style="font-size:12px;color:var(--text-muted);margin-top:10px">
          cPanel › Cron Jobs › Once Per Minute, command:
          <code class="code-chip" style="display:block;margin-top:6px;white-space:normal;word-break:break-all"><?php echo h($cron_cmd); ?></code>
        </div>
      <?php endif; ?>
      <form method="POST" style="margin-top:14px">
        <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>" />
        <input type="hidden" name="action" value="process" />
        <button class="btn btn-primary" <?php echo $enabled ? '' : 'disabled'; ?>><i class="fas fa-paper-plane"></i> Send queued messages now</button>
      </form>
    </div>
  </div>

  <div class="card">
    <div class="card-header"><div class="card-title"><i class="fas fa-id-badge"></i> Sender ID</div></div>
    <div class="card-body">
      <p style="font-size:13px;color:var(--text-muted);margin-bottom:12px">
        The name clients see as the sender (e.g. <strong>ORBITCLOUD</strong>). It must be <strong>approved on your Shanfix SMS account</strong> first —
        an unapproved sender ID makes every message fail.
      </p>
      <form method="POST" style="display:flex;gap:8px;flex-wrap:wrap">
        <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>" />
        <input type="hidden" name="action" value="save_sender" />
        <input type="text" name="sender_id" class="form-control" style="flex:1;min-width:160px;text-transform:uppercase;font-family:var(--mono, monospace)"
               maxlength="11" value="<?php echo h($sender_id); ?>" placeholder="ORBITCLOUD" />
        <button class="btn btn-primary"><i class="fas fa-save"></i> Save</button>
      </form>
      <div style="font-size:12px;color:var(--text-muted);margin-top:6px">3–11 letters or digits. Leave blank to use your account's default sender ID.</div>

      <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;margin-top:18px;padding-top:14px;border-top:1px solid var(--border);flex-wrap:wrap">
        <div style="font-size:13px">Status:
          <?php if ($enabled): ?><span class="badge badge-success">Enabled</span><?php else: ?><span class="badge badge-secondary">Disabled</span><?php endif; ?>
        </div>
        <div style="display:flex;gap:8px">
          <a href="<?php echo APP_URL; ?>/integrations/#prov-shanfix_sms" class="btn btn-ghost btn-sm"><i class="fas fa-sliders"></i> Keys &amp; URL</a>
          <form method="POST" style="margin:0">
            <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>" />
            <input type="hidden" name="action" value="<?php echo $enabled ? 'disable' : 'enable'; ?>" />
            <button class="btn btn-sm <?php echo $enabled ? 'btn-ghost' : 'btn-primary'; ?>" <?php echo $enabled ? 'data-confirm="Disable SMS? Notifications and campaigns stop sending."' : ''; ?>>
              <i class="fas <?php echo $enabled ? 'fa-power-off' : 'fa-toggle-on'; ?>"></i> <?php echo $enabled ? 'Disable' : 'Enable SMS'; ?>
            </button>
          </form>
        </div>
      </div>
    </div>
  </div>
</div>

<div class="table-wrap" style="margin-bottom:20px">
  <div class="table-toolbar"><span class="card-title">Campaigns</span><span class="table-count"><?php echo count($campaigns); ?> shown</span></div>
  <div class="table-scroll">
  <table>
    <thead><tr><th>Campaign</th><th>Audience</th><th>Recipients</th><th>Sent</th><th>Failed</th><th>Status</th><th>When</th></tr></thead>
    <tbody>
      <?php if (!$campaigns): ?>
        <tr><td colspan="7"><div class="empty-state"><i class="fas fa-bullhorn"></i><p>No campaigns yet. <a href="<?php echo APP_URL; ?>/sms/compose.php">Create one</a>.</p></div></td></tr>
      <?php else: foreach ($campaigns as $c): ?>
        <tr>
          <td><a href="<?php echo APP_URL; ?>/sms/campaign.php?id=<?php echo (int) $c['id']; ?>" class="td-name"><?php echo h($c['name']); ?></a>
            <div class="td-sub"><?php echo h(mb_strimwidth($c['message'], 0, 60, '…')); ?></div></td>
          <td style="font-size:12.5px"><?php echo h(Sms::audiences()[$c['audience']] ?? $c['audience']); ?></td>
          <td><?php echo (int) $c['total']; ?></td>
          <td><?php echo (int) $c['ok']; ?></td>
          <td><?php echo (int) $c['bad']; ?></td>
          <td><span class="badge <?php echo $badge[$c['status']]; ?>"><?php echo ucfirst($c['status']); ?></span></td>
          <td style="font-size:12px;color:var(--text-muted)"><?php echo format_datetime($c['scheduled_at']); ?></td>
        </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>
  </div>
</div>

<div class="table-wrap" id="log">
  <div class="table-toolbar">
    <span class="card-title">Message log</span>
    <form method="GET" style="display:flex;gap:6px;flex-wrap:wrap;margin:0">
      <input type="search" name="q" class="form-control" style="width:180px" placeholder="Phone, name or text" value="<?php echo h($f_q); ?>" />
      <select name="kind" class="form-control" style="width:auto">
        <option value="">All types</option>
        <option value="notify" <?php echo $f_kind === 'notify' ? 'selected' : ''; ?>>Notifications</option>
        <option value="campaign" <?php echo $f_kind === 'campaign' ? 'selected' : ''; ?>>Campaigns</option>
      </select>
      <select name="status" class="form-control" style="width:auto">
        <option value="">Any status</option>
        <?php foreach (['queued', 'sent', 'delivered', 'failed', 'cancelled'] as $s): ?>
          <option value="<?php echo $s; ?>" <?php echo $f_status === $s ? 'selected' : ''; ?>><?php echo ucfirst($s); ?></option>
        <?php endforeach; ?>
      </select>
      <button class="btn btn-ghost btn-sm"><i class="fas fa-filter"></i></button>
    </form>
  </div>
  <div class="table-scroll">
  <table>
    <thead><tr><th>To</th><th>Message</th><th>Type</th><th>Status</th><th>When</th><th></th></tr></thead>
    <tbody>
      <?php if (!$log): ?>
        <tr><td colspan="6"><div class="empty-state"><i class="fas fa-comment-slash"></i><p>No messages yet.</p></div></td></tr>
      <?php else: foreach ($log as $m): ?>
        <tr>
          <td>
            <div class="td-name"><?php echo trim((string) $m['client_name']) !== '' ? h($m['client_name']) : '—'; ?></div>
            <div class="td-sub mono"><?php echo h($m['phone']); ?></div>
          </td>
          <td style="max-width:380px;font-size:12.5px"><?php echo h(mb_strimwidth($m['message'], 0, 140, '…')); ?>
            <?php if ($m['error']): ?><div class="td-sub" style="color:var(--danger)"><?php echo h($m['error']); ?></div><?php endif; ?></td>
          <td style="font-size:12px"><?php echo $m['campaign_id']
              ? '<a href="' . APP_URL . '/sms/campaign.php?id=' . (int) $m['campaign_id'] . '">' . h($m['campaign_name']) . '</a>'
              : h($kind_label($m)); ?></td>
          <td><span class="badge <?php echo $badge[$m['status']]; ?>"><?php echo ucfirst($m['status']); ?></span></td>
          <td style="font-size:12px;color:var(--text-muted)"><?php echo time_ago($m['created_at']); ?></td>
          <td style="text-align:right">
            <?php if ($m['status'] === 'failed' && $provider): ?>
              <form method="POST" style="margin:0"><input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>" />
                <input type="hidden" name="action" value="retry" /><input type="hidden" name="id" value="<?php echo (int) $m['id']; ?>" />
                <button class="btn btn-ghost btn-xs"><i class="fas fa-rotate-right"></i> Retry</button></form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>
  </div>
</div>

<?php require_once '../includes/footer.php'; ?>
