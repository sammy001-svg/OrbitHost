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

<?php if ($queued > 0): ?>
  <div class="alert alert-info"><i class="fas fa-circle-info"></i>
    Queued messages are sent by the SMS cron. If they're not moving, add this to cPanel › Cron Jobs (every minute):
    <code class="code-chip">php <?php echo h(realpath(__DIR__ . '/../cron/sms.php') ?: 'admin/cron/sms.php'); ?></code></div>
<?php endif; ?>

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
