<?php
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

auth_check();
$page_title = 'New Ticket';

$preselect_client = (int)($_GET['client_id'] ?? 0);
$clients = db()->query('SELECT id, first_name, last_name, email FROM clients ORDER BY first_name, last_name')->fetchAll();
$errors  = [];
$data    = [
    'client_id'  => $preselect_client,
    'subject'    => '',
    'department' => 'general',
    'priority'   => 'medium',
    'message'    => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $data = [
        'client_id'  => (int)($_POST['client_id']  ?? 0) ?: null,
        'subject'    => trim($_POST['subject']      ?? ''),
        'department' => $_POST['department']        ?? 'general',
        'priority'   => $_POST['priority']          ?? 'medium',
        'message'    => trim($_POST['message']      ?? ''),
    ];

    if (!$data['subject']) $errors[] = 'Subject is required.';
    if (!$data['message']) $errors[] = 'Initial message is required.';

    if (!$errors) {
        $num = generate_ticket_number();
        db()->prepare('INSERT INTO tickets (ticket_number,client_id,subject,department,priority,status) VALUES (?,?,?,?,?,?)')
            ->execute([$num, $data['client_id'], $data['subject'], $data['department'], $data['priority'], 'open']);
        $tid = db()->lastInsertId();

        db()->prepare('INSERT INTO ticket_replies (ticket_id,sender_type,sender_name,message) VALUES (?,?,?,?)')
            ->execute([$tid, 'admin', current_admin()['name'], $data['message']]);

        log_activity('create_ticket', 'ticket', $tid, "Created ticket $num");
        flash_set('success', "Ticket $num created.");
        header('Location: ' . APP_URL . '/tickets/view.php?id=' . $tid);
        exit;
    }
}

require_once '../includes/header.php';
?>

<div class="page-header">
  <div>
    <div class="breadcrumb"><a href="<?php echo APP_URL; ?>/tickets/">Tickets</a><span class="breadcrumb-sep">›</span> New Ticket</div>
    <h1>Create Support Ticket</h1>
  </div>
  <a href="<?php echo APP_URL; ?>/tickets/" class="btn btn-ghost"><i class="fas fa-arrow-left"></i> Back</a>
</div>

<?php if ($errors): ?>
  <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?php echo h(implode(' ', $errors)); ?></div>
<?php endif; ?>

<form method="POST">
  <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>" />
  <div class="form-wrap">
    <p class="form-section-title">Ticket Information</p>

    <div class="form-group">
      <label class="form-label">Client (optional)</label>
      <select name="client_id" class="form-select">
        <option value="">— Guest / No client —</option>
        <?php foreach ($clients as $c): ?>
          <option value="<?php echo $c['id']; ?>" <?php echo (int)$data['client_id']===$c['id']?'selected':''; ?>>
            <?php echo h($c['first_name'] . ' ' . $c['last_name'] . ' <' . $c['email'] . '>'); ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="form-group">
      <label class="form-label">Subject <span class="req">*</span></label>
      <input type="text" name="subject" class="form-control"
             value="<?php echo h($data['subject']); ?>" required placeholder="Brief description of the issue…" />
    </div>

    <div class="form-grid-2">
      <div class="form-group">
        <label class="form-label">Department</label>
        <select name="department" class="form-select">
          <?php foreach (['sales','billing','technical','general'] as $d): ?>
            <option value="<?php echo $d; ?>" <?php echo $data['department']===$d?'selected':''; ?>><?php echo ucfirst($d); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label class="form-label">Priority</label>
        <select name="priority" class="form-select">
          <?php foreach (['low','medium','high','urgent'] as $p): ?>
            <option value="<?php echo $p; ?>" <?php echo $data['priority']===$p?'selected':''; ?>><?php echo ucfirst($p); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <div class="form-group">
      <label class="form-label">Initial Message <span class="req">*</span></label>
      <textarea name="message" class="form-textarea" rows="6" required
                placeholder="Describe the issue in detail…"><?php echo h($data['message']); ?></textarea>
    </div>

    <div class="form-actions">
      <button type="submit" class="btn btn-primary"><i class="fas fa-ticket-alt"></i> Create Ticket</button>
      <a href="<?php echo APP_URL; ?>/tickets/" class="btn btn-ghost">Cancel</a>
    </div>
  </div>
</form>

<?php require_once '../includes/footer.php'; ?>
