<?php
/**
 * Orbit Cloud — self-service plan upgrade/downgrade request.
 * Client picks another plan in the same category as their current
 * service; the request goes to admin for approval (admin/services/
 * change-requests.php), which applies it (and the panel package change,
 * if bound to one) once approved.
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once dirname(__DIR__) . '/admin/includes/functions.php';
require_once dirname(__DIR__) . '/admin/includes/Notifier.php';
require_once dirname(__DIR__) . '/admin/includes/Currency.php';

portal_check();
Currency::ensureSchema();
$c   = current_client();
$cid = (int) $c['id'];
$currency = Currency::current();

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
} catch (\Throwable $e) { /* best-effort — errors surface below if it truly failed */ }

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$stmt = db()->prepare('SELECT * FROM client_services WHERE id = ? AND client_id = ?');
$stmt->execute([$id, $cid]);
$svc = $stmt->fetch();

if (!$svc) {
    portal_flash_set('error', 'Service not found.');
    header('Location: ' . PORTAL_URL . '/services.php');
    exit;
}

// Already has a pending request? Don't allow a second one.
$pend = db()->prepare("SELECT id FROM service_change_requests WHERE client_service_id = ? AND status = 'pending'");
$pend->execute([$id]);
if ($pend->fetch()) {
    portal_flash_set('error', 'You already have a pending change request for this service.');
    header('Location: ' . PORTAL_URL . '/services.php');
    exit;
}

// client_services.category is a coarser enum than the plan catalogue's
// (e.g. "hosting" covers shared/wordpress/cloud/dedicated there), so the
// real catalogue category comes from the linked plan when one exists —
// only falling back to the coarse mapping for manually-created services
// with no catalogue link.
$current_price = (float) $svc['amount'];
$catalogue_category = null;
if ($svc['service_id']) {
    $stmt = db()->prepare('SELECT price, category FROM services WHERE id = ?');
    $stmt->execute([$svc['service_id']]);
    if ($row = $stmt->fetch()) {
        $current_price = (float) $row['price'];
        $catalogue_category = $row['category'];
    }
}
if (!$catalogue_category) {
    $catalogue_category = $svc['category'] === 'hosting' ? 'shared' : $svc['category'];
}

$plans = db()->prepare("SELECT * FROM services WHERE category = ? AND is_active = 1 AND id != ? ORDER BY price");
$plans->execute([$catalogue_category, $svc['service_id'] ?: 0]);
$plans = $plans->fetchAll();

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    portal_csrf_verify();
    $planId = (int) ($_POST['plan_id'] ?? 0);
    $note   = trim($_POST['note'] ?? '');

    $stmt = db()->prepare('SELECT * FROM services WHERE id = ? AND is_active = 1');
    $stmt->execute([$planId]);
    $plan = $stmt->fetch();

    if (!$plan) {
        $error = 'Please choose a plan.';
    } else {
        $direction = (float) $plan['price'] >= $current_price ? 'upgrade' : 'downgrade';
        db()->prepare('INSERT INTO service_change_requests (client_id, client_service_id, current_label, requested_plan_id, direction, note) VALUES (?,?,?,?,?,?)')
            ->execute([$cid, $id, $svc['label'], $planId, $direction, $note ?: null]);

        Notifier::send('service_change_requested', $cid, [
            'client_name' => $c['name'], 'direction' => ucfirst($direction), 'direction_lc' => $direction,
            'service_label' => $svc['label'], 'plan_name' => $plan['name'],
            'email' => $c['email'], 'link' => PORTAL_URL . '/services.php',
        ]);
        Notifier::sendToAllAdmins('service_change_requested_admin', [
            'client_name' => $c['name'], 'direction' => ucfirst($direction), 'direction_lc' => $direction,
            'service_label' => $svc['label'], 'plan_name' => $plan['name'],
            'link' => APP_URL . '/services/change-requests.php',
        ]);

        portal_flash_set('success', 'Your ' . $direction . ' request has been submitted — we\'ll confirm it shortly.');
        header('Location: ' . PORTAL_URL . '/services.php');
        exit;
    }
}

$page_title = 'Change Plan';
require_once __DIR__ . '/includes/header.php';
?>

<div class="page-hero">
  <div class="container"><div><h1>Upgrade or Downgrade</h1><p><?php echo htmlspecialchars($svc['label']); ?></p></div></div>
</div>

<div class="page-body">
<div class="container" style="max-width:680px">

  <?php if ($error): ?><div class="p-alert p-alert-error"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?></div><?php endif; ?>

  <div class="p-form-card">
    <p style="font-size:13.5px;color:var(--text-muted);margin-bottom:18px">
      Current plan: <strong style="color:var(--navy)"><?php echo htmlspecialchars($svc['package'] ?: $svc['label']); ?></strong>
      (<?php echo Currency::format($current_price, $svc['currency'] ?? 'USD'); ?>/<?php echo str_replace('_', ' ', $svc['billing_cycle']); ?>)
    </p>

    <?php if (!$plans): ?>
      <div class="p-alert p-alert-info"><i class="fas fa-circle-info"></i> No other plans are available in this category right now. <a href="<?php echo PORTAL_URL; ?>/tickets/add.php?subject=<?php echo urlencode('Change plan for ' . $svc['label']); ?>">Open a ticket</a> and our team will help directly.</div>
    <?php else: ?>
      <form method="POST">
        <input type="hidden" name="csrf_token" value="<?php echo portal_csrf(); ?>" />
        <input type="hidden" name="id" value="<?php echo $id; ?>" />

        <div style="display:flex;flex-direction:column;gap:10px;margin-bottom:18px">
          <?php foreach ($plans as $p): $dir = (float)$p['price'] >= $current_price ? 'upgrade' : 'downgrade'; $p_amt = Currency::planAmount($p, $currency); ?>
            <label style="display:flex;align-items:center;gap:12px;border:1px solid var(--border);border-radius:10px;padding:14px;cursor:pointer">
              <input type="radio" name="plan_id" value="<?php echo (int)$p['id']; ?>" required />
              <span style="flex:1">
                <span style="font-weight:700;color:var(--navy);display:block"><?php echo htmlspecialchars($p['name']); ?></span>
                <span style="font-size:12px;color:var(--text-muted)"><?php echo Currency::format($p_amt['price'], $currency); ?>/<?php echo str_replace('_',' ',$p['billing_cycle']); ?><?php echo !empty($p['description']) ? ' — ' . htmlspecialchars(mb_strimwidth($p['description'], 0, 80, '…')) : ''; ?></span>
              </span>
              <span class="badge <?php echo $dir === 'upgrade' ? 'badge-success' : 'badge-secondary'; ?>"><?php echo ucfirst($dir); ?></span>
            </label>
          <?php endforeach; ?>
        </div>

        <div class="form-group">
          <label class="form-label">Note <span class="text-muted" style="font-weight:400">(optional)</span></label>
          <textarea name="note" class="form-textarea" rows="3" placeholder="Anything our team should know?"></textarea>
        </div>

        <div class="form-actions">
          <button type="submit" class="btn btn-primary"><i class="fas fa-paper-plane"></i> Submit Request</button>
          <a href="<?php echo PORTAL_URL; ?>/services.php" class="btn btn-ghost">Cancel</a>
        </div>
      </form>
    <?php endif; ?>
  </div>
</div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
