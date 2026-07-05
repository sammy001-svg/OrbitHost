<?php
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

auth_check();

$page_title = 'Add Client';
$errors = [];
$data   = ['first_name'=>'','last_name'=>'','email'=>'','phone'=>'','company'=>'','country'=>'Kenya','status'=>'active','notes'=>''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $data = [
        'first_name' => trim($_POST['first_name'] ?? ''),
        'last_name'  => trim($_POST['last_name']  ?? ''),
        'email'      => trim($_POST['email']       ?? ''),
        'phone'      => trim($_POST['phone']       ?? ''),
        'company'    => trim($_POST['company']     ?? ''),
        'country'    => trim($_POST['country']     ?? 'Kenya'),
        'status'     => in_array($_POST['status'] ?? '', ['active','suspended','cancelled']) ? $_POST['status'] : 'active',
        'notes'      => trim($_POST['notes']       ?? ''),
    ];

    if (!$data['first_name']) $errors[] = 'First name is required.';
    if (!$data['last_name'])  $errors[] = 'Last name is required.';
    if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'A valid email address is required.';

    if (!$errors) {
        // Check duplicate email
        $check = db()->prepare('SELECT id FROM clients WHERE email = ?');
        $check->execute([$data['email']]);
        if ($check->fetch()) {
            $errors[] = 'A client with this email already exists.';
        } else {
            db()->prepare('INSERT INTO clients (first_name,last_name,email,phone,company,country,status,notes) VALUES (?,?,?,?,?,?,?,?)')
                ->execute(array_values($data));
            $id = db()->lastInsertId();
            log_activity('create_client', 'client', $id, "Created client {$data['first_name']} {$data['last_name']}");
            flash_set('success', 'Client added successfully.');
            header('Location: ' . APP_URL . '/clients/view.php?id=' . $id);
            exit;
        }
    }
}

require_once '../includes/header.php';
?>

<div class="page-header">
  <div>
    <div class="breadcrumb"><a href="<?php echo APP_URL; ?>/clients/">Clients</a><span class="breadcrumb-sep">›</span> Add Client</div>
    <h1>Add New Client</h1>
  </div>
  <a href="<?php echo APP_URL; ?>/clients/" class="btn btn-ghost"><i class="fas fa-arrow-left"></i> Back</a>
</div>

<?php if ($errors): ?>
  <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?php echo h(implode(' ', $errors)); ?></div>
<?php endif; ?>

<form method="POST" action="">
  <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>" />

  <div class="form-wrap">
    <p class="form-section-title">Personal Information</p>

    <div class="form-grid-2">
      <div class="form-group">
        <label class="form-label" for="first_name">First Name <span class="req">*</span></label>
        <input type="text" id="first_name" name="first_name" class="form-control"
               value="<?php echo h($data['first_name']); ?>" required autofocus />
      </div>
      <div class="form-group">
        <label class="form-label" for="last_name">Last Name <span class="req">*</span></label>
        <input type="text" id="last_name" name="last_name" class="form-control"
               value="<?php echo h($data['last_name']); ?>" required />
      </div>
    </div>

    <div class="form-grid-2">
      <div class="form-group">
        <label class="form-label" for="email">Email Address <span class="req">*</span></label>
        <input type="email" id="email" name="email" class="form-control"
               value="<?php echo h($data['email']); ?>" required />
      </div>
      <div class="form-group">
        <label class="form-label" for="phone">Phone Number</label>
        <input type="tel" id="phone" name="phone" class="form-control"
               value="<?php echo h($data['phone']); ?>" placeholder="+254 7XX XXX XXX" />
      </div>
    </div>

    <div class="form-grid-2">
      <div class="form-group">
        <label class="form-label" for="company">Company / Organisation</label>
        <input type="text" id="company" name="company" class="form-control"
               value="<?php echo h($data['company']); ?>" />
      </div>
      <div class="form-group">
        <label class="form-label" for="country">Country</label>
        <select id="country" name="country" class="form-select">
          <?php foreach (get_countries() as $c): ?>
            <option value="<?php echo h($c); ?>" <?php echo $data['country'] === $c ? 'selected' : ''; ?>><?php echo h($c); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <p class="form-section-title" style="margin-top:24px">Account Settings</p>

    <div class="form-grid-2">
      <div class="form-group">
        <label class="form-label" for="status">Status</label>
        <select id="status" name="status" class="form-select">
          <option value="active"    <?php echo $data['status']==='active'    ? 'selected':''?>  >Active</option>
          <option value="suspended" <?php echo $data['status']==='suspended' ? 'selected':''?>  >Suspended</option>
          <option value="cancelled" <?php echo $data['status']==='cancelled' ? 'selected':''?>  >Cancelled</option>
        </select>
      </div>
    </div>

    <div class="form-group">
      <label class="form-label" for="notes">Internal Notes</label>
      <textarea id="notes" name="notes" class="form-textarea" placeholder="Private notes visible only to admins…"><?php echo h($data['notes']); ?></textarea>
    </div>

    <div class="form-actions">
      <button type="submit" class="btn btn-primary"><i class="fas fa-check"></i> Create Client</button>
      <a href="<?php echo APP_URL; ?>/clients/" class="btn btn-ghost">Cancel</a>
    </div>
  </div>
</form>

<?php require_once '../includes/footer.php'; ?>
