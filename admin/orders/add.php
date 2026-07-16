<?php
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

auth_check();
$page_title = 'New Order';

$preselect_client = (int)($_GET['client_id'] ?? 0);

$clients  = db()->query('SELECT id, first_name, last_name, email FROM clients WHERE status="active" ORDER BY first_name, last_name')->fetchAll();
$services = db()->query('SELECT id, name, price, billing_cycle, category FROM services WHERE is_active=1 ORDER BY category, name')->fetchAll();

$errors = [];
$data   = [
    'client_id'    => $preselect_client,
    'service_id'   => '',
    'domain'       => '',
    'amount'       => '',
    'billing_cycle'=> 'monthly',
    'status'       => 'active',
    'start_date'   => date('Y-m-d'),
    'next_due'     => date('Y-m-d', strtotime('+1 month')),
    'notes'        => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $data = [
        'client_id'    => (int)($_POST['client_id']    ?? 0),
        'service_id'   => (int)($_POST['service_id']   ?? 0) ?: null,
        'domain'       => trim($_POST['domain']         ?? ''),
        'amount'       => trim($_POST['amount']         ?? ''),
        'billing_cycle'=> $_POST['billing_cycle']       ?? 'monthly',
        'status'       => $_POST['status']              ?? 'active',
        'start_date'   => $_POST['start_date']          ?? date('Y-m-d'),
        'next_due'     => $_POST['next_due']            ?? '',
        'notes'        => trim($_POST['notes']          ?? ''),
    ];

    // Get service name
    $service_name = null;
    if ($data['service_id']) {
        $svc = db()->prepare('SELECT name, price, billing_cycle FROM services WHERE id=?');
        $svc->execute([$data['service_id']]);
        $svc = $svc->fetch();
        if ($svc) {
            $service_name = $svc['name'];
            if (!$data['amount']) $data['amount'] = $svc['price'];
            if (!$_POST['billing_cycle']) $data['billing_cycle'] = $svc['billing_cycle'];
        }
    }

    if (!$data['client_id']) $errors[] = 'Please select a client.';
    if (!is_numeric($data['amount']) || $data['amount'] <= 0) $errors[] = 'Please enter a valid amount.';

    if (!$errors) {
        db()->prepare('INSERT INTO orders (client_id,service_id,service_name,domain,amount,billing_cycle,status,start_date,next_due,notes) VALUES (?,?,?,?,?,?,?,?,?,?)')
            ->execute([
                $data['client_id'], $data['service_id'], $service_name,
                $data['domain'], $data['amount'], $data['billing_cycle'],
                $data['status'], $data['start_date'] ?: null,
                $data['next_due'] ?: null, $data['notes'],
            ]);
        $oid = db()->lastInsertId();
        log_activity('create_order', 'order', $oid, "Created order for client #{$data['client_id']}");
        flash_set('success', 'Order created successfully.');
        $redir = $data['client_id'] ? APP_URL . '/clients/view.php?id=' . $data['client_id'] . '#orders-tab' : APP_URL . '/orders/index.php';
        header('Location: ' . $redir);
        exit;
    }
}

require_once '../includes/header.php';
?>

<div class="page-header">
  <div>
    <div class="breadcrumb"><a href="<?php echo APP_URL; ?>/orders/">Orders</a><span class="breadcrumb-sep">›</span> New Order</div>
    <h1>Create New Order</h1>
  </div>
  <a href="<?php echo APP_URL; ?>/orders/" class="btn btn-ghost"><i class="fas fa-arrow-left"></i> Back</a>
</div>

<?php if ($errors): ?>
  <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?php echo h(implode(' ', $errors)); ?></div>
<?php endif; ?>

<form method="POST" action="">
  <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>" />
  <div class="form-wrap">
    <p class="form-section-title">Order Details</p>

    <div class="form-grid-2">
      <div class="form-group">
        <label class="form-label">Client <span class="req">*</span></label>
        <select name="client_id" class="form-select" required>
          <option value="">— Select client —</option>
          <?php foreach ($clients as $c): ?>
            <option value="<?php echo $c['id']; ?>" <?php echo (int)$data['client_id']===$c['id']?'selected':''; ?>>
              <?php echo h($c['first_name'] . ' ' . $c['last_name'] . ' <' . $c['email'] . '>'); ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="form-group">
        <label class="form-label">Service / Plan</label>
        <select name="service_id" id="serviceSelect" class="form-select">
          <option value="">— Select or enter manually —</option>
          <?php
          $cat = '';
          foreach ($services as $s):
            if ($s['category'] !== $cat) {
              if ($cat) echo '</optgroup>';
              echo '<optgroup label="' . ucfirst($s['category']) . '">';
              $cat = $s['category'];
            }
          ?>
            <option value="<?php echo $s['id']; ?>"
                    data-price="<?php echo $s['price']; ?>"
                    data-cycle="<?php echo $s['billing_cycle']; ?>"
                    <?php echo (int)$data['service_id']===$s['id']?'selected':''; ?>>
              <?php echo h($s['name']); ?> — <?php echo format_money($s['price']); ?>/<?php echo $s['billing_cycle']; ?>
            </option>
          <?php endforeach; if ($cat) echo '</optgroup>'; ?>
        </select>
      </div>
    </div>

    <div class="form-grid-2">
      <div class="form-group">
        <label class="form-label">Domain</label>
        <input type="text" name="domain" class="form-control" placeholder="example.com"
               value="<?php echo h($data['domain']); ?>" />
      </div>
      <div class="form-group">
        <label class="form-label">Amount (<?php echo CURRENCY; ?>) <span class="req">*</span></label>
        <input type="number" id="amountInput" name="amount" class="form-control" step="0.01" min="0"
               value="<?php echo h($data['amount']); ?>" placeholder="0.00" required />
      </div>
    </div>

    <div class="form-grid-2">
      <div class="form-group">
        <label class="form-label">Billing Cycle</label>
        <select name="billing_cycle" id="billingSelect" class="form-select">
          <option value="monthly" <?php echo $data['billing_cycle']==='monthly'?'selected':''; ?>>Monthly</option>
          <option value="annual"  <?php echo $data['billing_cycle']==='annual' ?'selected':''; ?>>Annual</option>
          <option value="one_time"<?php echo $data['billing_cycle']==='one_time'?'selected':''; ?>>One Time</option>
        </select>
      </div>
      <div class="form-group">
        <label class="form-label">Order Status</label>
        <select name="status" class="form-select">
          <?php foreach (['active','pending','suspended','cancelled','expired'] as $s): ?>
            <option value="<?php echo $s; ?>" <?php echo $data['status']===$s?'selected':''; ?>><?php echo ucfirst($s); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <div class="form-grid-2">
      <div class="form-group">
        <label class="form-label">Start Date</label>
        <input type="date" name="start_date" class="form-control" value="<?php echo h($data['start_date']); ?>" />
      </div>
      <div class="form-group">
        <label class="form-label">Next Due Date</label>
        <input type="date" name="next_due" class="form-control" value="<?php echo h($data['next_due']); ?>" />
      </div>
    </div>

    <div class="form-group">
      <label class="form-label">Notes</label>
      <textarea name="notes" class="form-textarea" placeholder="Internal notes…"><?php echo h($data['notes']); ?></textarea>
    </div>

    <div class="form-actions">
      <button type="submit" class="btn btn-primary"><i class="fas fa-check"></i> Create Order</button>
      <a href="<?php echo APP_URL; ?>/orders/" class="btn btn-ghost">Cancel</a>
    </div>
  </div>
</form>

<script>
document.getElementById('serviceSelect').addEventListener('change', function () {
  var opt = this.options[this.selectedIndex];
  var price = opt.getAttribute('data-price');
  var cycle = opt.getAttribute('data-cycle');
  if (price) document.getElementById('amountInput').value = price;
  if (cycle) document.getElementById('billingSelect').value = cycle;
});
</script>

<?php require_once '../includes/footer.php'; ?>
