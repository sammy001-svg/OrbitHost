<?php
/**
 * Orbit Cloud — approve/decline self-service plan change requests
 * submitted from the client portal (portal/service-change.php).
 */
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/Notifier.php';
require_once '../includes/providers/Provider.php';

auth_check();
$page_title = 'Service Change Requests';

$schema_ok = true;
try {
    db()->exec("CREATE TABLE IF NOT EXISTS service_change_requests (
        id                INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        client_id         INT UNSIGNED NOT NULL,
        client_service_id INT UNSIGNED NOT NULL,
        current_label     VARCHAR(150) NOT NULL,
        requested_plan_id INT UNSIGNED NOT NULL,
        direction         ENUM('upgrade','downgrade') NOT NULL,
        note              TEXT,
        status            ENUM('pending','approved','declined') NOT NULL DEFAULT 'pending',
        admin_note        TEXT,
        created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_scr_service (client_service_id),
        FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
        FOREIGN KEY (requested_plan_id) REFERENCES services(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (\Throwable $e) {
    $schema_ok = false;
}

if ($schema_ok && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $id     = (int)($_POST['id'] ?? 0);
    $action = $_POST['action'] ?? '';

    $stmt = db()->prepare('SELECT r.*, cs.provider_key, cs.username, cs.label, c.first_name, c.email
                            FROM service_change_requests r
                            JOIN client_services cs ON cs.id = r.client_service_id
                            JOIN clients c ON c.id = r.client_id
                            WHERE r.id = ? AND r.status = "pending"');
    $stmt->execute([$id]);
    $req = $stmt->fetch();

    if (!$req) {
        flash_set('error', 'Request not found or already actioned.');
    } elseif ($action === 'approve') {
        $stmt = db()->prepare('SELECT * FROM services WHERE id = ?');
        $stmt->execute([$req['requested_plan_id']]);
        $plan = $stmt->fetch();

        if (!$plan) {
            flash_set('error', 'The requested plan no longer exists.');
        } else {
            $panel_note = '';
            if ($req['provider_key'] && $req['username'] && !empty($plan['panel_package'])) {
                try {
                    $r = Provider::panel($req['provider_key'])->changePackage($req['username'], $plan['panel_package']);
                    $panel_note = !empty($r['success']) ? ' Panel package changed to ' . $plan['panel_package'] . '.' : ' (Panel package change failed: ' . ($r['message'] ?? 'unknown') . ' — check manually.)';
                } catch (\Throwable $e) {
                    $panel_note = ' (Panel package change errored: ' . $e->getMessage() . ' — check manually.)';
                }
            }
            db()->prepare('UPDATE client_services SET service_id=?, package=?, amount=?, billing_cycle=? WHERE id=?')
                ->execute([$plan['id'], $plan['panel_package'] ?: $plan['name'], $plan['price'], $plan['billing_cycle'], $req['client_service_id']]);
            db()->prepare('UPDATE service_change_requests SET status="approved", admin_note=? WHERE id=?')
                ->execute(['Applied.' . $panel_note, $id]);
            log_activity('service_change_approve', 'client_service', $req['client_service_id'], $req['direction'] . ' to ' . $plan['name']);

            Notifier::send('service_change_approved', (int) $req['client_id'], [
                'client_name' => $req['first_name'], 'direction_lc' => $req['direction'],
                'service_label' => $req['label'], 'plan_name' => $plan['name'],
                'email' => $req['email'], 'link' => portal_base_url() . '/services.php',
            ]);
            flash_set('success', 'Request approved — ' . $req['label'] . ' moved to ' . $plan['name'] . '.' . $panel_note);
        }
    } elseif ($action === 'decline') {
        $reason = trim($_POST['reason'] ?? '') ?: 'Not specified.';
        db()->prepare('UPDATE service_change_requests SET status="declined", admin_note=? WHERE id=?')->execute([$reason, $id]);
        log_activity('service_change_decline', 'client_service', $req['client_service_id'], $reason);

        Notifier::send('service_change_declined', (int) $req['client_id'], [
            'client_name' => $req['first_name'], 'direction' => ucfirst($req['direction']), 'direction_lc' => $req['direction'],
            'service_label' => $req['label'], 'reason' => $reason,
            'email' => $req['email'], 'link' => portal_base_url() . '/tickets/add.php',
        ]);
        flash_set('success', 'Request declined and client notified.');
    }
    header('Location: ' . APP_URL . '/services/change-requests.php');
    exit;
}

$requests = $schema_ok ? db()->query(
    "SELECT r.*, cs.label AS service_label, cs.package AS current_package,
            s.name AS plan_name, s.price AS plan_price, s.billing_cycle AS plan_cycle,
            c.first_name, c.last_name
     FROM service_change_requests r
     JOIN client_services cs ON cs.id = r.client_service_id
     JOIN services s ON s.id = r.requested_plan_id
     JOIN clients c ON c.id = r.client_id
     ORDER BY (r.status = 'pending') DESC, r.created_at DESC
     LIMIT 100"
)->fetchAll() : [];
$pending_count = count(array_filter($requests, fn($r) => $r['status'] === 'pending'));

require_once '../includes/header.php';
?>

<div class="content-header">
  <div>
    <h1 class="content-title">Service Change Requests</h1>
    <p class="page-subtitle">Plan upgrade/downgrade requests submitted by clients from the portal.</p>
  </div>
  <a href="<?php echo APP_URL; ?>/services/" class="btn btn-ghost"><i class="fas fa-arrow-left"></i> Back to Services</a>
</div>

<?php if (!$schema_ok): ?>
  <div class="alert alert-danger"><i class="fas fa-triangle-exclamation"></i> Could not create the change-requests table automatically — check DB privileges and reload.</div>
<?php else: ?>

<div class="stat-grid" style="grid-template-columns:repeat(2,1fr)">
  <div class="stat-card"><div class="stat-icon orange"><i class="fas fa-clock"></i></div><div><div class="stat-label">Pending</div><div class="stat-value"><?php echo $pending_count; ?></div></div></div>
  <div class="stat-card"><div class="stat-icon navy"><i class="fas fa-arrows-up-down"></i></div><div><div class="stat-label">Total requests</div><div class="stat-value"><?php echo count($requests); ?></div></div></div>
</div>

<div class="table-wrap" style="margin-top:20px">
  <div class="table-scroll">
  <table>
    <thead><tr><th>Client</th><th>Service</th><th>Change</th><th>Note</th><th>Status</th><th>Requested</th><th style="text-align:right">Actions</th></tr></thead>
    <tbody>
      <?php if (!$requests): ?>
        <tr><td colspan="7"><div class="empty-state"><i class="fas fa-arrows-up-down"></i><p>No change requests yet.</p></div></td></tr>
      <?php else: foreach ($requests as $r): ?>
        <tr>
          <td><?php echo h($r['first_name'] . ' ' . $r['last_name']); ?></td>
          <td><?php echo h($r['service_label']); ?><div class="td-sub"><?php echo h($r['current_package'] ?: '—'); ?></div></td>
          <td>
            <span class="badge <?php echo $r['direction'] === 'upgrade' ? 'badge-success' : 'badge-secondary'; ?>"><?php echo ucfirst($r['direction']); ?></span>
            → <strong><?php echo h($r['plan_name']); ?></strong>
            <div class="td-sub"><?php echo format_money($r['plan_price']); ?>/<?php echo str_replace('_',' ',$r['plan_cycle']); ?></div>
          </td>
          <td style="max-width:200px"><?php echo $r['note'] ? h(mb_strimwidth($r['note'], 0, 80, '…')) : '<span class="text-muted">—</span>'; ?></td>
          <td>
            <?php echo badge($r['status']); ?>
            <?php if ($r['status'] !== 'pending' && $r['admin_note']): ?><div class="td-sub" style="max-width:180px"><?php echo h(mb_strimwidth($r['admin_note'], 0, 60, '…')); ?></div><?php endif; ?>
          </td>
          <td><?php echo time_ago($r['created_at']); ?></td>
          <td>
            <?php if ($r['status'] === 'pending'): ?>
              <div class="actions" style="justify-content:flex-end">
                <form method="POST" style="margin:0">
                  <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>" />
                  <input type="hidden" name="action" value="approve" />
                  <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>" />
                  <button type="submit" class="btn btn-primary btn-xs" data-confirm="Approve this <?php echo h($r['direction']); ?> to <?php echo h($r['plan_name']); ?>? This applies the change immediately."><i class="fas fa-check"></i> Approve</button>
                </form>
                <button type="button" class="btn btn-ghost btn-xs decline-open" data-id="<?php echo (int)$r['id']; ?>">Decline</button>
              </div>
            <?php else: ?>
              <span class="text-muted" style="font-size:12px">—</span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>
  </div>
</div>

<!-- Decline reason drawer -->
<div class="drawer-scrim" id="drawer-decline-scrim"></div>
<div class="drawer" id="drawer-decline">
  <div class="drawer-head">
    <div style="font-weight:700">Decline Request</div>
    <button type="button" class="drawer-close" data-drawer-close>&times;</button>
  </div>
  <form method="POST" style="display:contents">
    <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>" />
    <input type="hidden" name="action" value="decline" />
    <input type="hidden" name="id" id="declineId" value="0" />
    <div class="drawer-body">
      <div class="form-group">
        <label class="form-label">Reason <span class="text-muted" style="font-weight:400">(the client sees this)</span></label>
        <textarea name="reason" class="form-control" rows="4" placeholder="e.g. Downgrading isn't available while storage usage exceeds the target plan's limit." required></textarea>
      </div>
    </div>
    <div class="drawer-foot">
      <button type="submit" class="btn btn-danger"><i class="fas fa-xmark"></i> Decline &amp; Notify Client</button>
      <button type="button" class="btn btn-ghost" data-drawer-close>Cancel</button>
    </div>
  </form>
</div>
<script>
document.addEventListener('click', function (e) {
  var btn = e.target.closest ? e.target.closest('.decline-open') : null;
  if (!btn) return;
  document.getElementById('declineId').value = btn.getAttribute('data-id');
  document.getElementById('drawer-decline').classList.add('open');
  document.getElementById('drawer-decline-scrim').classList.add('open');
  document.body.style.overflow = 'hidden';
});
</script>

<?php endif; ?>

<?php require_once '../includes/footer.php'; ?>
