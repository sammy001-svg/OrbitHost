<?php
/**
 * Orbit Cloud — bulk client announcement
 * Compose once, send to every selected client (in-app + email) — a
 * maintenance notice, a price change, a promo. Reuses the same
 * Notifier/NotificationRegistry path every other client email goes
 * through, via a generic 'admin_announcement' type whose whole content
 * is admin-composed rather than a fixed template.
 */
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/Notifier.php';

auth_check();
$page_title = 'Send Announcement';

function announce_client_ids(string $raw): array
{
    $ids = array_filter(array_map('intval', explode(',', $raw)));
    return array_values(array_unique($ids));
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $ids     = announce_client_ids($_POST['ids'] ?? '');
    $subject = trim($_POST['subject'] ?? '');
    $message = trim($_POST['message'] ?? '');

    if (!$ids)        $errors[] = 'No clients were selected.';
    if (!$subject)    $errors[] = 'Subject is required.';
    if (!$message)    $errors[] = 'Message is required.';

    if (!$errors) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = db()->prepare("SELECT id, first_name, last_name, email FROM clients WHERE id IN ($placeholders)");
        $stmt->execute($ids);
        $recipients = $stmt->fetchAll();

        $body_html = nl2br(h($message));
        $excerpt   = mb_strimwidth($message, 0, 140, '…');
        $sent = 0;
        foreach ($recipients as $r) {
            Notifier::send('admin_announcement', (int) $r['id'], [
                'client_name' => trim($r['first_name'] . ' ' . $r['last_name']),
                'subject'     => $subject,
                'excerpt'     => $excerpt,
                'body_html'   => $body_html,
                'email'       => $r['email'],
                'link'        => portal_base_url() . '/dashboard.php',
            ]);
            $sent++;
        }

        log_activity('bulk_announcement', 'client', 0, "Sent \"$subject\" to $sent client(s)");
        flash_set('success', "Announcement sent to $sent client(s).");
        header('Location: ' . APP_URL . '/clients/');
        exit;
    }
}

$ids = announce_client_ids($_GET['ids'] ?? $_POST['ids'] ?? '');
if (!$ids) {
    flash_set('error', 'No clients selected. Select at least one client from the list first.');
    header('Location: ' . APP_URL . '/clients/');
    exit;
}

$placeholders = implode(',', array_fill(0, count($ids), '?'));
$stmt = db()->prepare("SELECT id, first_name, last_name, email FROM clients WHERE id IN ($placeholders) ORDER BY first_name, last_name");
$stmt->execute($ids);
$recipients = $stmt->fetchAll();

require_once '../includes/header.php';
?>

<div class="content-header">
  <div>
    <h1 class="content-title">Send Announcement</h1>
    <p class="page-subtitle">One message, sent by email and in-app to every client selected below.</p>
  </div>
  <a href="<?php echo APP_URL; ?>/clients/" class="btn btn-ghost"><i class="fas fa-arrow-left"></i> Back</a>
</div>

<?php if ($errors): ?>
  <div class="alert alert-danger"><i class="fas fa-triangle-exclamation"></i> <?php echo h(implode(' ', $errors)); ?></div>
<?php endif; ?>

<div class="form-wrap" style="max-width:720px">
  <form method="POST">
    <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>" />
    <input type="hidden" name="ids" value="<?php echo h(implode(',', $ids)); ?>" />

    <p class="form-section-title">Recipients (<?php echo count($recipients); ?>)</p>
    <div style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:22px">
      <?php foreach ($recipients as $r): ?>
        <span class="code-chip"><?php echo h(trim($r['first_name'] . ' ' . $r['last_name'])); ?></span>
      <?php endforeach; ?>
    </div>

    <div class="form-group">
      <label class="form-label">Subject <span class="req">*</span></label>
      <input type="text" name="subject" class="form-control" placeholder="e.g. Scheduled maintenance this weekend" required value="<?php echo h($_POST['subject'] ?? ''); ?>" />
    </div>

    <div class="form-group">
      <label class="form-label">Message <span class="req">*</span></label>
      <textarea name="message" class="form-textarea" rows="8" required placeholder="Write your announcement…"><?php echo h($_POST['message'] ?? ''); ?></textarea>
      <small class="form-hint">Sent as plain text (line breaks preserved) — no need to add HTML.</small>
    </div>

    <div class="form-actions">
      <button type="submit" class="btn btn-primary" data-confirm="Send this announcement to <?php echo count($recipients); ?> client(s)? This emails them immediately and can't be undone.">
        <i class="fas fa-paper-plane"></i> Send to <?php echo count($recipients); ?> Client<?php echo count($recipients) === 1 ? '' : 's'; ?>
      </button>
      <a href="<?php echo APP_URL; ?>/clients/" class="btn btn-ghost">Cancel</a>
    </div>
  </form>
</div>

<?php require_once '../includes/footer.php'; ?>
