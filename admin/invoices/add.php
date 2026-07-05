<?php
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

auth_check();
$page_title = 'New Invoice';

$preselect_client = (int)($_GET['client_id'] ?? 0);
$clients = db()->query('SELECT id, first_name, last_name, email FROM clients WHERE status="active" ORDER BY first_name, last_name')->fetchAll();

$errors = [];
$data   = [
    'client_id'      => $preselect_client,
    'status'         => 'draft',
    'due_date'       => date('Y-m-d', strtotime('+30 days')),
    'tax_rate'       => TAX_RATE,
    'payment_method' => '',
    'notes'          => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $data = [
        'client_id'      => (int)($_POST['client_id']      ?? 0),
        'status'         => $_POST['status']                ?? 'draft',
        'due_date'       => $_POST['due_date']              ?? '',
        'tax_rate'       => (float)($_POST['tax_rate']      ?? 0),
        'payment_method' => trim($_POST['payment_method']   ?? ''),
        'notes'          => trim($_POST['notes']            ?? ''),
        'subtotal'       => (float)($_POST['subtotal']      ?? 0),
        'tax_amount'     => (float)($_POST['tax_amount']    ?? 0),
        'total'          => (float)($_POST['total']         ?? 0),
    ];

    $items_raw = $_POST['items'] ?? [];

    if (!$data['client_id'])   $errors[] = 'Please select a client.';
    if (empty($items_raw))     $errors[] = 'At least one line item is required.';
    if ($data['total'] <= 0)   $errors[] = 'Invoice total must be greater than zero.';

    if (!$errors) {
        // Validate items
        $items = [];
        foreach ($items_raw as $item) {
            $desc  = trim($item['description'] ?? '');
            $qty   = (int)($item['quantity']   ?? 1);
            $price = (float)($item['unit_price'] ?? 0);
            if ($desc && $qty > 0) {
                $items[] = ['description' => $desc, 'quantity' => $qty, 'unit_price' => $price, 'total' => $qty * $price];
            }
        }

        if (empty($items)) { $errors[] = 'Please add at least one valid line item.'; }
        else {
            $inv_num = generate_invoice_number();
            $paid_date = $data['status'] === 'paid' ? date('Y-m-d') : null;

            db()->prepare('INSERT INTO invoices (invoice_number,client_id,subtotal,tax_rate,tax_amount,total,status,due_date,paid_date,payment_method,notes) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([
                    $inv_num, $data['client_id'],
                    $data['subtotal'], $data['tax_rate'], $data['tax_amount'], $data['total'],
                    $data['status'], $data['due_date'] ?: null, $paid_date,
                    $data['payment_method'], $data['notes'],
                ]);
            $iid = db()->lastInsertId();

            $ins = db()->prepare('INSERT INTO invoice_items (invoice_id,description,quantity,unit_price,total) VALUES (?,?,?,?,?)');
            foreach ($items as $item) {
                $ins->execute([$iid, $item['description'], $item['quantity'], $item['unit_price'], $item['total']]);
            }

            log_activity('create_invoice', 'invoice', $iid, "Created invoice $inv_num");
            flash_set('success', "Invoice $inv_num created.");
            header('Location: ' . APP_URL . '/invoices/view.php?id=' . $iid);
            exit;
        }
    }
}

require_once '../includes/header.php';
?>

<div class="page-header">
  <div>
    <div class="breadcrumb"><a href="<?php echo APP_URL; ?>/invoices/">Invoices</a><span class="breadcrumb-sep">›</span> New Invoice</div>
    <h1>Create Invoice</h1>
  </div>
  <a href="<?php echo APP_URL; ?>/invoices/" class="btn btn-ghost"><i class="fas fa-arrow-left"></i> Back</a>
</div>

<?php if ($errors): ?>
  <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?php echo h(implode(' ', $errors)); ?></div>
<?php endif; ?>

<form method="POST">
  <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>" />
  <input type="hidden" name="subtotal"   id="hiddenSubtotal"  value="0" />
  <input type="hidden" name="tax_amount" id="hiddenTaxAmount" value="0" />
  <input type="hidden" name="total"      id="hiddenTotal"     value="0" />

  <div style="display:grid;grid-template-columns:1fr 320px;gap:16px;align-items:start">

    <!-- Left: Line items -->
    <div class="form-wrap" style="max-width:none">
      <p class="form-section-title">Line Items</p>

      <div style="overflow-x:auto">
        <table class="items-table">
          <thead>
            <tr>
              <th>Description</th>
              <th style="width:80px">Qty</th>
              <th style="width:120px">Unit Price</th>
              <th style="width:110px;text-align:right">Total</th>
              <th style="width:40px"></th>
            </tr>
          </thead>
          <tbody id="itemsBody">
            <tr>
              <td><input type="text"   name="items[1][description]" class="form-control" placeholder="Service / Item description…" required /></td>
              <td><input type="number" name="items[1][quantity]"    class="form-control" value="1" min="1" /></td>
              <td><input type="number" name="items[1][unit_price]"  class="form-control item-price" step="0.01" placeholder="0.00" /></td>
              <td class="item-total" style="text-align:right;font-weight:600;padding-right:8px">0.00</td>
              <td></td>
            </tr>
          </tbody>
        </table>
      </div>

      <button type="button" id="addLineItem" class="btn btn-ghost btn-sm" style="margin-top:12px">
        <i class="fas fa-plus"></i> Add Line Item
      </button>

      <!-- Totals -->
      <div class="invoice-totals">
        <table class="totals-table">
          <tr>
            <td style="color:var(--text-muted)">Subtotal</td>
            <td style="text-align:right"><span id="displaySubtotal">0.00</span></td>
          </tr>
          <tr>
            <td style="color:var(--text-muted)">VAT / Tax</td>
            <td style="text-align:right"><span id="displayTax">0.00</span></td>
          </tr>
          <tr class="total-row">
            <td>Total</td>
            <td style="text-align:right;color:var(--navy)"><?php echo CURRENCY; ?> <span id="displayTotal">0.00</span></td>
          </tr>
        </table>
      </div>

      <div class="form-group" style="margin-top:20px">
        <label class="form-label">Notes / Payment Instructions</label>
        <textarea name="notes" class="form-textarea" placeholder="e.g. Please pay via M-Pesa Paybill…"><?php echo h($data['notes']); ?></textarea>
      </div>
    </div>

    <!-- Right: Invoice meta -->
    <div>
      <div class="card">
        <div class="card-header"><div class="card-title">Invoice Details</div></div>
        <div class="card-body">
          <div class="form-group">
            <label class="form-label">Client <span class="req">*</span></label>
            <select name="client_id" class="form-select" required>
              <option value="">— Select client —</option>
              <?php foreach ($clients as $c): ?>
                <option value="<?php echo $c['id']; ?>" <?php echo (int)$data['client_id']===$c['id']?'selected':''; ?>>
                  <?php echo h($c['first_name'] . ' ' . $c['last_name']); ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Status</label>
            <select name="status" class="form-select">
              <?php foreach (['draft','sent','paid','overdue'] as $s): ?>
                <option value="<?php echo $s; ?>" <?php echo $data['status']===$s?'selected':''; ?>><?php echo ucfirst($s); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Due Date</label>
            <input type="date" name="due_date" class="form-control" value="<?php echo h($data['due_date']); ?>" />
          </div>
          <div class="form-group">
            <label class="form-label">Tax / VAT Rate (%)</label>
            <input type="number" id="taxRate" name="tax_rate" class="form-control"
                   step="0.01" min="0" max="100"
                   value="<?php echo h($data['tax_rate']); ?>" />
          </div>
          <div class="form-group">
            <label class="form-label">Payment Method</label>
            <select name="payment_method" class="form-select">
              <option value="">— Select —</option>
              <?php foreach (get_payment_methods() as $pm): ?>
                <option value="<?php echo h($pm); ?>" <?php echo $data['payment_method']===$pm?'selected':''; ?>><?php echo h($pm); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <button type="submit" class="btn btn-primary" style="width:100%">
            <i class="fas fa-save"></i> Create Invoice
          </button>
        </div>
      </div>
    </div>

  </div>
</form>

<?php require_once '../includes/footer.php'; ?>
