<?php
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

auth_check();

$id = (int)($_GET['id'] ?? 0);
$stmt = db()->prepare('SELECT o.*, CONCAT(c.first_name," ",c.last_name) AS client_name FROM orders o JOIN clients c ON c.id=o.client_id WHERE o.id=?');
$stmt->execute([$id]);
$order = $stmt->fetch();

if (!$order) {
    flash_set('error', 'Order not found.');
    header('Location: ' . APP_URL . '/orders/');
    exit;
}

$page_title = 'Edit Order #' . $id;
$errors = [];
$data   = $order;

$services = db()->query('SELECT id, name, price, billing_cycle FROM services WHERE is_active=1 ORDER BY name')->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $data = array_merge($order, [
        'service_id'   => (int)($_POST['service_id'] ?? 0) ?: null,
        'domain'       => trim($_POST['domain']       ?? ''),
        'amount'       => $_POST['amount']            ?? '',
        'billing_cycle'=> $_POST['billing_cycle']     ?? 'monthly',
        'status'       => $_POST['status']            ?? 'active',
        'start_date'   => $_POST['start_date']        ?? '',
        'next_due'     => $_POST['next_due']          ?? '',
        'notes'        => trim($_POST['notes']        ?? ''),
    ]);

    $service_name = $order['service_name'];
    if ($data['service_id']) {
        $svc = db()->prepare('SELECT name FROM services WHERE id=?');
        $svc->execute([$data['service_id']]);
        $svc = $svc->fetch();
        if ($svc) $service_name = $svc['name'];
    }

    if (!is_numeric($data['amount']) || $data['amount'] <= 0) $errors[] = 'Please enter a valid amount.';

    if (!$errors) {
        db()->prepare('UPDATE orders SET service_id=?,service_name=?,domain=?,amount=?,billing_cycle=?,status=?,start_date=?,next_due=?,notes=? WHERE id=?')
            ->execute([
                $data['service_id'], $service_name, $data['domain'],
                $data['amount'], $data['billing_cycle'], $data['status'],
                $data['start_date'] ?: null, $data['next_due'] ?: null,
                $data['notes'], $id,
            ]);
        log_activity('update_order', 'order', $id, "Updated order #$id");
        flash_set('success', 'Order updated.');
        header('Location: ' . APP_URL . '/clients/view.php?id=' . $order['client_id'] . '#orders-tab');
        exit;
    }
}

require_once '../includes/header.php';
?>

<div class="page-header">
  <div>
    <div class="breadcrumb">
      <a href="<?php echo APP_URL; ?>/orders/">Orders</a><span class="breadcrumb-sep">›</span>
      <a href="<?php echo APP_URL; ?>/clients/view.php?id=<?php echo $order['client_id']; ?>"><?php echo h($order['client_name']); ?></a><span class="breadcrumb-sep">›</span>
      Edit Order #<?php echo $id; ?>
    </div>
    <h1>Edit Order #<?php echo $id; ?></h1>
  </div>
  <a href="<?php echo APP_URL; ?>/orders/" class="btn btn-ghost"><i class="fas fa-arrow-left"></i> Back</a>
</div>

<?php if ($errors): ?>
  <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?php echo h(implode(' ', $errors)); ?></div>
<?php endif; ?>

<form method="POST" action="">
  <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>" />
  <div class="form-wrap">
    <div style="background:var(--bg);border-radius:8px;padding:14px 18px;margin-bottom:20px;font-size:13.5px">
      <strong>Client:</strong>
      <a href="<?php echo APP_URL; ?>/clients/view.php?id=<?php echo $order['client_id']; ?>" style="color:var(--navy)">
        <?php echo h($order['client_name']); ?>
      </a>
      &nbsp;&nbsp; Created: <?php echo format_date($order['created_at']); ?>
    </div>

    <p class="form-section-title">Order Details</p>

    <div class="form-grid-2">
      <div class="form-group">
        <label class="form-label">Service / Plan</label>
        <select name="service_id" class="form-select">
          <option value="">— Custom / Keep current —</option>
          <?php foreach ($services as $s): ?>
            <option value="<?php echo $s['id']; ?>" <?php echo (int)$data['service_id']===$s['id']?'selected':''; ?>>
              <?php echo h($s['name']); ?>
            </option>
          <?php endforeach; ?>
        </select>
        <div class="form-hint">Current: <?php echo h($order['service_name'] ?: '—'); ?></div>
      </div>
      <div class="form-group">
        <label class="form-label">Domain</label>
        <input type="text" name="domain" class="form-control" placeholder="example.com"
               value="<?php echo h($data['domain']); ?>" />
      </div>
    </div>

    <div class="form-grid-2">
      <div class="form-group">
        <label class="form-label">Amount (<?php echo CURRENCY; ?>)</label>
        <input type="number" name="amount" class="form-control" step="0.01" min="0"
               value="<?php echo h($data['amount']); ?>" required />
      </div>
      <div class="form-group">
        <label class="form-label">Billing Cycle</label>
        <select name="billing_cycle" class="form-select">
          <option value="monthly"  <?php echo $data['billing_cycle']==='monthly'  ?'selected':''; ?>>Monthly</option>
          <option value="annual"   <?php echo $data['billing_cycle']==='annual'   ?'selected':''; ?>>Annual</option>
          <option value="one_time" <?php echo $data['billing_cycle']==='one_time' ?'selected':''; ?>>One Time</option>
        </select>
      </div>
    </div>

    <div class="form-grid-2">
      <div class="form-group">
        <label class="form-label">Status</label>
        <select name="status" class="form-select">
          <?php foreach (['active','pending','suspended','cancelled','expired'] as $s): ?>
            <option value="<?php echo $s; ?>" <?php echo $data['status']===$s?'selected':''; ?>><?php echo ucfirst($s); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label class="form-label">Next Due Date</label>
        <input type="date" name="next_due" class="form-control"
               value="<?php echo h($data['next_due'] ?? ''); ?>" />
      </div>
    </div>

    <div class="form-grid-2">
      <div class="form-group">
        <label class="form-label">Start Date</label>
        <input type="date" name="start_date" class="form-control"
               value="<?php echo h($data['start_date'] ?? ''); ?>" />
      </div>
    </div>

    <div class="form-group">
      <label class="form-label">Notes</label>
      <textarea name="notes" class="form-textarea"><?php echo h($data['notes']); ?></textarea>
    </div>

    <div class="form-actions">
      <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Changes</button>
      <a href="<?php echo APP_URL; ?>/clients/view.php?id=<?php echo $order['client_id']; ?>" class="btn btn-ghost">Cancel</a>
    </div>
  </div>
</form>

<?php require_once '../includes/footer.php'; ?>
