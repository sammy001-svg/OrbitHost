<?php
/**
 * Orbit Cloud — one SMS campaign: progress, delivery, recipients.
 */
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/Sms.php';

auth_check();
require_role('admin', APP_URL . '/dashboard.php');
Sms::ensureSchema();

$id = (int) ($_GET['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';
    if ($action === 'cancel') {
        $r = Sms::cancelCampaign($id);
        flash_set($r['ok'] ? 'success' : 'error', $r['message']);
    } elseif ($action === 'process') {
        $q = Sms::processQueue();
        $s = Sms::syncStatuses();
        flash_set('success', !empty($q['locked']) ? 'The queue is already being worked — refresh in a moment.'
            : sprintf('%d sent, %d failed, %d waiting to retry; %d delivery report(s) updated.', $q['sent'], $q['failed'], $q['retrying'], $s['delivered'] + $s['failed']));
    }
    header('Location: ' . APP_URL . '/sms/campaign.php?id=' . $id);
    exit;
}

$s = db()->prepare('SELECT c.*, a.name admin_name FROM sms_campaigns c LEFT JOIN admin_users a ON a.id = c.created_by WHERE c.id = ?');
$s->execute([$id]);
$cmp = $s->fetch();
if (!$cmp) {
    flash_set('error', 'Campaign not found.');
    header('Location: ' . APP_URL . '/sms/');
    exit;
}
$page_title = $cmp['name'];
$st   = Sms::campaignStats($id);
$done = $st['sent'] + $st['delivered'] + $st['failed'] + $st['cancelled'];
$pct  = $cmp['total'] ? (int) round($done / $cmp['total'] * 100) : 0;

$filter = in_array($_GET['status'] ?? '', ['queued', 'sent', 'delivered', 'failed', 'cancelled'], true) ? $_GET['status'] : '';
$q = db()->prepare("SELECT m.*, CONCAT(COALESCE(c.first_name,''),' ',COALESCE(c.last_name,'')) client_name
                    FROM sms_messages m LEFT JOIN clients c ON c.id = m.client_id
                    WHERE m.campaign_id = ?" . ($filter ? ' AND m.status = ?' : '') . ' ORDER BY m.id LIMIT 500');
$q->execute($filter ? [$id, $filter] : [$id]);
$rows = $q->fetchAll();

$badge = ['queued' => 'badge-warning', 'sent' => 'badge-primary', 'delivered' => 'badge-success', 'failed' => 'badge-danger', 'cancelled' => 'badge-secondary',
          'scheduled' => 'badge-warning', 'sending' => 'badge-primary', 'completed' => 'badge-success'];

require_once '../includes/header.php';
?>

<div class="content-header">
  <div>
    <div class="breadcrumb"><a href="<?php echo APP_URL; ?>/sms/">SMS</a><span class="breadcrumb-sep">›</span> Campaign</div>
    <h1 class="content-title"><?php echo h($cmp['name']); ?> <span class="badge <?php echo $badge[$cmp['status']]; ?>" style="vertical-align:middle"><?php echo ucfirst($cmp['status']); ?></span></h1>
  </div>
  <div style="display:flex;gap:8px">
    <?php if (in_array($cmp['status'], ['scheduled', 'sending'], true)): ?>
      <form method="POST" style="margin:0"><input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>" /><input type="hidden" name="action" value="process" />
        <button class="btn btn-ghost" title="Send the next batch now instead of waiting for the cron"><i class="fas fa-forward"></i> Send next batch</button></form>
      <form method="POST" style="margin:0"><input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>" /><input type="hidden" name="action" value="cancel" />
        <button class="btn btn-danger" data-confirm="Cancel this campaign? Messages already sent cannot be recalled; the rest will not go out."><i class="fas fa-ban"></i> Cancel</button></form>
    <?php else: ?>
      <form method="POST" style="margin:0"><input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>" /><input type="hidden" name="action" value="process" />
        <button class="btn btn-ghost"><i class="fas fa-rotate"></i> Refresh delivery</button></form>
    <?php endif; ?>
    <a href="<?php echo APP_URL; ?>/sms/" class="btn btn-ghost"><i class="fas fa-arrow-left"></i> Back</a>
  </div>
</div>

<div class="stat-grid">
  <div class="stat-card"><div class="stat-icon navy"><i class="fas fa-users"></i></div>
    <div><div class="stat-label">Recipients</div><div class="stat-value"><?php echo (int) $cmp['total']; ?></div></div></div>
  <div class="stat-card"><div class="stat-icon orange"><i class="fas fa-hourglass-half"></i></div>
    <div><div class="stat-label">Waiting</div><div class="stat-value"><?php echo $st['queued']; ?></div></div></div>
  <div class="stat-card"><div class="stat-icon green"><i class="fas fa-check-double"></i></div>
    <div><div class="stat-label">Sent / delivered</div><div class="stat-value"><?php echo $st['sent'] + $st['delivered']; ?><span style="font-size:13px;color:var(--text-muted)"> · <?php echo $st['delivered']; ?> confirmed</span></div></div></div>
  <div class="stat-card"><div class="stat-icon red"><i class="fas fa-circle-xmark"></i></div>
    <div><div class="stat-label">Failed</div><div class="stat-value"><?php echo $st['failed']; ?></div></div></div>
</div>

<div class="grid-2" style="align-items:start;margin-bottom:18px">
  <div class="card">
    <div class="card-header"><div class="card-title"><i class="fas fa-message"></i> Message</div></div>
    <div class="card-body">
      <?php $sid = ($pk = Sms::provider()) ? trim((string) (Provider::config($pk)['sender_id'] ?? '')) : ''; ?>
      <div class="sms-phone" style="max-width:none">
        <div class="sms-phone-top">
          <span class="sms-avatar"><i class="fas fa-comment-sms"></i></span>
          <div style="min-width:0">
            <div class="sms-sender"><?php echo h($sid !== '' ? $sid : 'Your sender ID'); ?></div>
            <div class="sms-sub">Text message<?php echo $cmp['personalized'] ? ' · personalised per client' : ''; ?></div>
          </div>
        </div>
        <div class="sms-thread" style="min-height:0">
          <div class="sms-bubble"><?php echo h($cmp['message']); ?></div>
          <div class="sms-time"><?php echo format_datetime($cmp['scheduled_at']); ?></div>
        </div>
      </div>
      <ul class="profile-meta" style="margin-top:14px">
        <li><span class="key">Audience</span><span class="val"><?php echo h(Sms::audiences()[$cmp['audience']] ?? $cmp['audience']); ?><?php echo $cmp['service_notice'] ? ' · service notice' : ''; ?></span></li>
        <li><span class="key">Sending</span><span class="val"><?php echo $cmp['personalized'] ? 'Personalised, one by one' : 'Bulk'; ?></span></li>
        <li><span class="key"><?php echo $cmp['status'] === 'scheduled' ? 'Scheduled for' : 'Started'; ?></span><span class="val"><?php echo format_datetime($cmp['scheduled_at']); ?></span></li>
        <?php if ($cmp['completed_at']): ?><li><span class="key">Finished</span><span class="val"><?php echo format_datetime($cmp['completed_at']); ?></span></li><?php endif; ?>
        <li><span class="key">Units used</span><span class="val"><?php echo number_format($st['units'], 2); ?></span></li>
        <li><span class="key">Created by</span><span class="val"><?php echo h($cmp['admin_name'] ?: '—'); ?></span></li>
      </ul>
    </div>
  </div>
  <div class="card">
    <div class="card-header"><div class="card-title"><i class="fas fa-bars-progress"></i> Progress</div></div>
    <div class="card-body">
      <div style="height:10px;border-radius:6px;background:var(--border);overflow:hidden">
        <div style="height:100%;width:<?php echo $pct; ?>%;background:var(--success)"></div>
      </div>
      <p class="text-muted" style="font-size:13px;margin-top:8px"><?php echo $pct; ?>% processed.
        <?php if ($cmp['status'] === 'sending'): ?>The cron sends the rest automatically — about <?php echo $cmp['personalized'] ? '50' : '8,000'; ?> a minute.<?php endif; ?>
        <?php if (!$cmp['personalized']): ?> Bulk messages show as <em>sent</em>; the platform doesn't report per-number delivery for bulk sends.<?php endif; ?>
      </p>
    </div>
  </div>
</div>

<div class="table-wrap">
  <div class="table-toolbar">
    <span class="card-title">Recipients</span>
    <div style="display:flex;gap:6px;flex-wrap:wrap">
      <?php foreach (['' => 'All', 'queued' => 'Waiting', 'sent' => 'Sent', 'delivered' => 'Delivered', 'failed' => 'Failed', 'cancelled' => 'Cancelled'] as $k => $l): ?>
        <a href="?id=<?php echo $id; ?><?php echo $k ? '&status=' . $k : ''; ?>" class="btn btn-xs <?php echo $filter === $k ? 'btn-primary' : 'btn-ghost'; ?>"><?php echo $l; ?></a>
      <?php endforeach; ?>
    </div>
  </div>
  <div class="table-scroll">
  <table>
    <thead><tr><th>Client</th><th>Phone</th><th>Status</th><th>Sent</th><th>Note</th></tr></thead>
    <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="5"><div class="empty-state"><i class="fas fa-inbox"></i><p>Nothing here.</p></div></td></tr>
      <?php else: foreach ($rows as $m): ?>
        <tr>
          <td><?php echo $m['client_id'] ? '<a href="' . APP_URL . '/clients/view.php?id=' . (int) $m['client_id'] . '">' . h(trim($m['client_name'])) . '</a>' : '—'; ?></td>
          <td class="mono" style="font-size:12px"><?php echo h($m['phone']); ?></td>
          <td><span class="badge <?php echo $badge[$m['status']]; ?>"><?php echo ucfirst($m['status']); ?></span></td>
          <td style="font-size:12px;color:var(--text-muted)"><?php echo $m['sent_at'] ? format_datetime($m['sent_at']) : '—'; ?></td>
          <td style="font-size:12px;color:var(--text-muted)"><?php echo h((string) $m['error']); ?></td>
        </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>
  </div>
</div>

<?php require_once '../includes/footer.php'; ?>
