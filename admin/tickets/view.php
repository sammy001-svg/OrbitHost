<?php
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/Notifier.php';
require_once '../includes/TicketAttachment.php';

auth_check();

$id = (int)($_GET['id'] ?? 0);
$stmt = db()->prepare('
    SELECT t.*,
           CONCAT(COALESCE(c.first_name,"")," ",COALESCE(c.last_name,"")) AS client_name,
           c.email AS client_email, c.phone AS client_phone
    FROM tickets t
    LEFT JOIN clients c ON c.id = t.client_id
    WHERE t.id = ?
');
$stmt->execute([$id]);
$ticket = $stmt->fetch();

if (!$ticket) {
    flash_set('error', 'Ticket not found.');
    header('Location: ' . APP_URL . '/tickets/');
    exit;
}

// Guest contact-form tickets have no client_id — fall back to the
// guest_* columns captured at submission time (columns are absent
// until schema_v9 runs, hence the ?? '' guards).
$display_name  = trim($ticket['client_name']) ?: ($ticket['guest_name'] ?? '');
$display_email = $ticket['client_email'] ?: ($ticket['guest_email'] ?? '');
$display_phone = $ticket['client_phone'] ?: ($ticket['guest_phone'] ?? '');
$is_guest      = empty($ticket['client_id']);

$page_title = $ticket['ticket_number'] . ' — ' . mb_strimwidth($ticket['subject'], 0, 40, '…');

/**
 * Guest (client_id NULL) tickets have no portal account to notify
 * in-app, so a reply is emailed directly with the message included —
 * there's nowhere else for them to go read it.
 */
function email_guest_reply(string $email, string $name, string $ticketNumber, string $subject, string $message, bool $closed): void
{
    if (!$email) return;
    require_once '../includes/Mailer.php';
    $heading = $closed ? 'Your enquiry has been resolved' : 'Reply to your enquiry';
    $body = '<p>Hi ' . h($name ?: 'there') . ',</p>'
          . '<p>' . ($closed ? 'Our team has replied and marked your enquiry as resolved:' : 'Our team has replied to your enquiry:') . '</p>'
          . '<blockquote style="margin:16px 0;padding:12px 16px;background:#f7f9fc;border-left:3px solid #1A8A45;border-radius:6px;color:#334155">' . nl2br(h($message)) . '</blockquote>'
          . '<p style="color:#94a3b8;font-size:12px">Reference: ' . h($ticketNumber) . ' — ' . h($subject) . '. Reply to this email if you have more questions.</p>';
    Mailer::fromConfig()->send($email, $heading . ' [' . $ticketNumber . ']', $body);
}

// Handle reply
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reply'])) {
    csrf_verify();
    $message    = trim($_POST['message'] ?? '');
    $new_status = $_POST['new_status'] ?? $ticket['status'];

    if ($message) {
        db()->prepare('INSERT INTO ticket_replies (ticket_id,sender_type,sender_name,message) VALUES (?,?,?,?)')
            ->execute([$id, 'admin', current_admin()['name'], $message]);
        $reply_id = (int) db()->lastInsertId();
        $upload = TicketAttachment::store($_FILES['attachment'] ?? [], $id, $reply_id);

        $update_status = in_array($new_status, ['open','pending','answered','closed']) ? $new_status : 'answered';
        db()->prepare('UPDATE tickets SET status=?, updated_at=NOW() WHERE id=?')
            ->execute([$update_status, $id]);

        if ($ticket['client_id']) {
            $notify_vars = [
                'client_name'   => $display_name,
                'admin_name'    => current_admin()['name'],
                'subject'       => $ticket['subject'],
                'ticket_number' => $ticket['ticket_number'],
                'reply_excerpt' => mb_strimwidth($message, 0, 220, '…'),
                'email'         => $display_email,
                'link'          => portal_base_url() . '/tickets/view.php?id=' . $id,
            ];
            Notifier::send($update_status === 'closed' ? 'ticket_closed' : 'ticket_replied', (int) $ticket['client_id'], $notify_vars);
        } elseif ($display_email) {
            email_guest_reply($display_email, $display_name, $ticket['ticket_number'], $ticket['subject'], $message, $update_status === 'closed');
        }

        log_activity('reply_ticket', 'ticket', $id, "Replied to ticket {$ticket['ticket_number']}");
        flash_set($upload['ok'] ? 'success' : 'error', $upload['ok'] ? 'Reply sent.' : 'Reply sent, but the attachment failed: ' . $upload['message']);
        header('Location: ' . APP_URL . '/tickets/view.php?id=' . $id);
        exit;
    }
}

// Handle quick status update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    csrf_verify();
    $ns = $_POST['quick_status'] ?? '';
    if (in_array($ns, ['open','pending','answered','closed'])) {
        $was_closed = $ticket['status'] === 'closed';
        db()->prepare('UPDATE tickets SET status=?, updated_at=NOW() WHERE id=?')->execute([$ns, $id]);
        if ($ns === 'closed' && !$was_closed && $ticket['client_id']) {
            Notifier::send('ticket_closed', (int) $ticket['client_id'], [
                'client_name'   => trim($ticket['client_name']),
                'subject'       => $ticket['subject'],
                'ticket_number' => $ticket['ticket_number'],
                'email'         => $ticket['client_email'],
                'link'          => portal_base_url() . '/tickets/view.php?id=' . $id,
            ]);
        }
        flash_set('success', 'Status updated to ' . ucfirst($ns) . '.');
        header('Location: ' . APP_URL . '/tickets/view.php?id=' . $id);
        exit;
    }
}

// Load replies
$replies = db()->prepare('SELECT * FROM ticket_replies WHERE ticket_id=? ORDER BY created_at ASC');
$replies->execute([$id]);
$replies = $replies->fetchAll();

// Admins for assignment
$admins = db()->query('SELECT id, name FROM admin_users ORDER BY name')->fetchAll();

require_once '../includes/header.php';
?>

<div class="page-header">
  <div>
    <div class="breadcrumb">
      <a href="<?php echo APP_URL; ?>/tickets/">Tickets</a><span class="breadcrumb-sep">›</span>
      <?php echo h($ticket['ticket_number']); ?>
    </div>
    <h1 style="font-size:17px"><?php echo h($ticket['subject']); ?></h1>
  </div>
  <div class="page-header-actions">
    <?php echo badge($ticket['priority']); ?>
    <?php echo badge($ticket['status']); ?>

    <!-- Quick status -->
    <form method="POST" style="display:inline">
      <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>" />
      <input type="hidden" name="update_status" value="1" />
      <select name="quick_status" class="form-select" style="width:130px;display:inline-block" onchange="this.form.submit()">
        <?php foreach (['open','pending','answered','closed'] as $s): ?>
          <option value="<?php echo $s; ?>" <?php echo $ticket['status']===$s?'selected':''; ?>><?php echo ucfirst($s); ?></option>
        <?php endforeach; ?>
      </select>
    </form>

    <a href="<?php echo APP_URL; ?>/tickets/" class="btn btn-ghost btn-sm"><i class="fas fa-arrow-left"></i> Back</a>
  </div>
</div>

<div style="display:grid;grid-template-columns:1fr 300px;gap:16px;align-items:start">

  <!-- Thread + reply form -->
  <div>
    <!-- Thread -->
    <div class="ticket-thread" style="margin-bottom:20px">
      <?php if ($replies): foreach ($replies as $r):
        $is_admin = $r['sender_type'] === 'admin';
      ?>
        <div class="ticket-msg <?php echo $is_admin ? 'from-admin' : 'from-client'; ?>">
          <div class="msg-meta">
            <div class="msg-avatar <?php echo $is_admin ? 'admin' : 'client'; ?>">
              <?php echo strtoupper(substr($r['sender_name'] ?? 'C', 0, 1)); ?>
            </div>
            <span class="msg-sender"><?php echo h($r['sender_name'] ?: 'Client'); ?></span>
            <?php if ($is_admin): ?><span style="font-size:11px;background:var(--green);color:#fff;padding:1px 7px;border-radius:4px">Admin</span><?php endif; ?>
            <span class="msg-time"><?php echo format_datetime($r['created_at']); ?></span>
          </div>
          <div class="msg-body"><?php echo nl2br(h($r['message'])); ?></div>
          <?php foreach (TicketAttachment::forReply((int) $r['id']) as $a): ?>
            <a href="<?php echo APP_URL; ?>/tickets/attachment.php?id=<?php echo (int) $a['id']; ?>" target="_blank" rel="noopener"
               style="display:inline-flex;align-items:center;gap:7px;margin-top:8px;padding:6px 11px;border:1px solid var(--border);border-radius:8px;font-size:12.5px;font-weight:600;color:var(--navy);text-decoration:none;background:#fff">
              <i class="fas <?php echo TicketAttachment::icon($a['mime_type']); ?>" style="color:var(--green)"></i>
              <?php echo h($a['original_name']); ?>
              <span style="color:var(--text-muted);font-weight:400"><?php echo format_bytes((int) $a['size_bytes']); ?></span>
            </a>
          <?php endforeach; ?>
        </div>
      <?php endforeach; else: ?>
        <div class="empty-state"><i class="fas fa-comment-slash"></i><p>No messages yet.</p></div>
      <?php endif; ?>
    </div>

    <!-- Reply form -->
    <div class="card">
      <div class="card-header"><div class="card-title"><i class="fas fa-reply" style="color:var(--green);margin-right:6px"></i>Send Reply</div></div>
      <div class="card-body">
        <form method="POST" enctype="multipart/form-data">
          <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>" />
          <input type="hidden" name="reply"      value="1" />
          <div class="form-group">
            <textarea name="message" class="form-textarea" rows="5"
                      placeholder="Type your reply here…" required></textarea>
          </div>
          <div class="form-group">
            <input type="file" name="attachment" class="form-control" accept=".png,.jpg,.jpeg,.gif,.webp,.pdf,.txt,.zip" style="max-width:320px" />
            <small class="form-hint">Optional — images, PDF, TXT or ZIP, up to 8 MB.</small>
          </div>
          <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
            <button type="submit" class="btn btn-primary"><i class="fas fa-paper-plane"></i> Send Reply</button>
            <label class="form-label mb-0" style="margin:0">After reply, set status to:</label>
            <select name="new_status" class="form-select" style="width:140px">
              <option value="answered">Answered</option>
              <option value="pending">Pending</option>
              <option value="closed">Closed</option>
              <option value="open">Open</option>
            </select>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- Ticket info sidebar -->
  <div>
    <!-- Details -->
    <div class="card" style="margin-bottom:14px">
      <div class="card-header"><div class="card-title">Ticket Details</div></div>
      <div class="card-body" style="padding:0">
        <table style="width:100%">
          <?php
          $rows = [
            'Ticket #'   => h($ticket['ticket_number']),
            'Department' => ucfirst($ticket['department']),
            'Priority'   => badge($ticket['priority']),
            'Status'     => badge($ticket['status']),
            'Created'    => format_datetime($ticket['created_at']),
            'Updated'    => format_datetime($ticket['updated_at']),
          ];
          foreach ($rows as $k => $v):
          ?>
          <tr>
            <td style="padding:10px 16px;color:var(--text-muted);font-size:12px;white-space:nowrap;border-bottom:1px solid #f1f5f9;font-weight:500"><?php echo $k; ?></td>
            <td style="padding:10px 16px;font-size:13px;border-bottom:1px solid #f1f5f9"><?php echo $v; ?></td>
          </tr>
          <?php endforeach; ?>
        </table>
      </div>
    </div>

    <!-- Client / Guest -->
    <?php if ($display_name || $display_email): ?>
    <div class="card" style="margin-bottom:14px">
      <div class="card-header">
        <div class="card-title"><?php echo $is_guest ? 'Guest (no account)' : 'Client'; ?></div>
        <?php if (!$is_guest): ?>
          <a href="<?php echo APP_URL; ?>/clients/view.php?id=<?php echo $ticket['client_id']; ?>" class="btn btn-ghost btn-xs">View</a>
        <?php else: ?>
          <span class="badge badge-secondary">Website contact form</span>
        <?php endif; ?>
      </div>
      <div class="card-body">
        <div style="font-weight:600"><?php echo h($display_name); ?></div>
        <div style="font-size:12.5px;color:var(--text-muted);margin-top:3px"><?php echo h($display_email); ?></div>
        <?php if ($display_phone): ?>
          <div style="font-size:12.5px;color:var(--text-muted)"><?php echo h($display_phone); ?></div>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- Assignment -->
    <div class="card">
      <div class="card-header"><div class="card-title">Assign Ticket</div></div>
      <div class="card-body">
        <form method="POST" action="<?php echo APP_URL; ?>/tickets/assign.php">
          <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>" />
          <input type="hidden" name="id"         value="<?php echo $id; ?>" />
          <select name="assigned_to" class="form-select" style="margin-bottom:10px">
            <option value="">— Unassigned —</option>
            <?php foreach ($admins as $a): ?>
              <option value="<?php echo $a['id']; ?>" <?php echo (int)$ticket['assigned_to']===$a['id']?'selected':''; ?>>
                <?php echo h($a['name']); ?>
              </option>
            <?php endforeach; ?>
          </select>
          <button type="submit" class="btn btn-ghost btn-sm" style="width:100%">Update Assignment</button>
        </form>
      </div>
    </div>
  </div>

</div>

<?php require_once '../includes/footer.php'; ?>
