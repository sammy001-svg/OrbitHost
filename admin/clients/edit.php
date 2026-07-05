<?php
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

auth_check();

$id = (int)($_GET['id'] ?? 0);
$stmt = db()->prepare('SELECT * FROM clients WHERE id = ?');
$stmt->execute([$id]);
$client = $stmt->fetch();

if (!$client) {
    flash_set('error', 'Client not found.');
    header('Location: ' . APP_URL . '/clients/');
    exit;
}

$page_title = 'Edit Client';
$errors = [];
$data   = $client;

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
        $dup = db()->prepare('SELECT id FROM clients WHERE email = ? AND id != ?');
        $dup->execute([$data['email'], $id]);
        if ($dup->fetch()) {
            $errors[] = 'Another client is using this email address.';
        } else {
            db()->prepare('UPDATE clients SET first_name=?,last_name=?,email=?,phone=?,company=?,country=?,status=?,notes=? WHERE id=?')
                ->execute([...array_values($data), $id]);
            log_activity('update_client', 'client', $id, "Updated client {$data['first_name']} {$data['last_name']}");
            flash_set('success', 'Client updated successfully.');
            header('Location: ' . APP_URL . '/clients/view.php?id=' . $id);
            exit;
        }
    }
}

require_once '../includes/header.php';
?>

<div class="page-header">
  <div>
    <div class="breadcrumb">
      <a href="<?php echo APP_URL; ?>/clients/">Clients</a><span class="breadcrumb-sep">›</span>
      <a href="<?php echo APP_URL; ?>/clients/view.php?id=<?php echo $id; ?>"><?php echo h($client['first_name'] . ' ' . $client['last_name']); ?></a><span class="breadcrumb-sep">›</span>
      Edit
    </div>
    <h1>Edit Client</h1>
  </div>
  <a href="<?php echo APP_URL; ?>/clients/view.php?id=<?php echo $id; ?>" class="btn btn-ghost">
    <i class="fas fa-arrow-left"></i> Back
  </a>
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
        <label class="form-label">First Name <span class="req">*</span></label>
        <input type="text" name="first_name" class="form-control" value="<?php echo h($data['first_name']); ?>" required />
      </div>
      <div class="form-group">
        <label class="form-label">Last Name <span class="req">*</span></label>
        <input type="text" name="last_name" class="form-control" value="<?php echo h($data['last_name']); ?>" required />
      </div>
    </div>

    <div class="form-grid-2">
      <div class="form-group">
        <label class="form-label">Email Address <span class="req">*</span></label>
        <input type="email" name="email" class="form-control" value="<?php echo h($data['email']); ?>" required />
      </div>
      <div class="form-group">
        <label class="form-label">Phone Number</label>
        <input type="tel" name="phone" class="form-control" value="<?php echo h($data['phone']); ?>" />
      </div>
    </div>

    <div class="form-grid-2">
      <div class="form-group">
        <label class="form-label">Company</label>
        <input type="text" name="company" class="form-control" value="<?php echo h($data['company']); ?>" />
      </div>
      <div class="form-group">
        <label class="form-label">Country</label>
        <select name="country" class="form-select">
          <?php foreach (get_countries() as $c): ?>
            <option value="<?php echo h($c); ?>" <?php echo $data['country'] === $c ? 'selected' : ''; ?>><?php echo h($c); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <p class="form-section-title" style="margin-top:24px">Account Settings</p>

    <div class="form-grid-2">
      <div class="form-group">
        <label class="form-label">Status</label>
        <select name="status" class="form-select">
          <option value="active"    <?php echo $data['status']==='active'    ?'selected':''; ?>>Active</option>
          <option value="suspended" <?php echo $data['status']==='suspended' ?'selected':''; ?>>Suspended</option>
          <option value="cancelled" <?php echo $data['status']==='cancelled' ?'selected':''; ?>>Cancelled</option>
        </select>
      </div>
    </div>

    <div class="form-group">
      <label class="form-label">Internal Notes</label>
      <textarea name="notes" class="form-textarea"><?php echo h($data['notes']); ?></textarea>
    </div>

    <div class="form-actions">
      <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Changes</button>
      <a href="<?php echo APP_URL; ?>/clients/view.php?id=<?php echo $id; ?>" class="btn btn-ghost">Cancel</a>
    </div>
  </div>
</form>

<?php require_once '../includes/footer.php'; ?>
