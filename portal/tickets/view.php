<?php
require_once '../../includes/config.php';
require_once '../../includes/auth.php';
require_once dirname(dirname(dirname(__DIR__))) . '/admin/includes/functions.php';

portal_check();
$cid = current_client()['id'];

$id = (int)($_GET['id'] ?? 0);
$stmt = db()->prepare('SELECT * FROM tickets WHERE id=? AND client_id=?');
$stmt->execute([$id, $cid]);
$ticket = $stmt->fetch();

if (!$ticket) {
    portal_flash_set('error', 'Ticket not found.');
    header('Location: ' . PORTAL_URL . '/tickets/');
    exit;
}

$page_title = $ticket['ticket_number'];

// Handle client reply
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    portal_csrf_verify();
    $msg = trim($_POST['message'] ?? '');
    if ($msg) {
        $name = current_client()['name'];
        db()->prepare('INSERT INTO ticket_replies (ticket_id,sender_type,sender_name,message) VALUES (?,?,?,?)')
            ->execute([$id, 'client', $name, $msg]);
        db()->prepare('UPDATE tickets SET status="pending", updated_at=NOW() WHERE id=?')
            ->execute([$id]);
        portal_flash_set('success', 'Your reply has been sent.');
        header('Location: ' . PORTAL_URL . '/tickets/view.php?id=' . $id);
        exit;
    }
}

$replies = db()->prepare('SELECT * FROM ticket_replies WHERE ticket_id=? ORDER BY created_at ASC');
$replies->execute([$id]);
$replies = $replies->fetchAll();

require_once '../../includes/header.php';
?>

<div class="page-hero">
  <div class="container">
    <div>
      <div style="font-size:12px;color:rgba(255,255,255,.5);margin-bottom:4px">
        <a href="<?php echo PORTAL_URL; ?>/tickets/" style="color:rgba(255,255,255,.5)">Tickets</a> › <?php echo htmlspecialchars($ticket['ticket_number']); ?>
      </div>
      <h1 style="font-size:18px"><?php echo htmlspecialchars($ticket['subject']); ?></h1>
    </div>
    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
      <?php echo badge($ticket['priority']); ?>
      <?php echo badge($ticket['status']); ?>
    </div>
  </div>
</div>

<div class="page-body">
<div class="container" style="max-width:820px">
  <div style="display:grid;grid-template-columns:1fr 240px;gap:20px;align-items:start">

    <!-- Thread -->
    <div>
      <div class="thread" style="margin-bottom:20px">
        <?php foreach ($replies as $r):
          $is_admin = $r['sender_type'] === 'admin';
        ?>
          <div class="thread-msg <?php echo $is_admin ? 'from-admin' : 'from-client'; ?>">
            <div class="msg-meta">
              <span class="msg-sender"><?php echo htmlspecialchars($r['sender_name'] ?: ($is_admin ? 'OrbitHost Support' : 'You')); ?></span>
              <?php if ($is_admin): ?><span class="msg-badge admin">Support Team</span><?php else: ?><span class="msg-badge client">You</span><?php endif; ?>
              <span class="msg-time"><?php echo format_datetime($r['created_at']); ?></span>
            </div>
            <div class="msg-body"><?php echo htmlspecialchars($r['message']); ?></div>
          </div>
        <?php endforeach; ?>
      </div>

      <!-- Reply form (only if not closed) -->
      <?php if ($ticket['status'] !== 'closed'): ?>
      <div class="p-card">
        <div class="p-card-header"><div class="p-card-title"><i class="fas fa-reply" style="color:var(--green);margin-right:7px"></i>Send a Reply</div></div>
        <div class="p-card-body">
          <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo portal_csrf(); ?>" />
            <div class="form-group">
              <textarea name="message" class="form-textarea" rows="5"
                        placeholder="Add more details or follow up…" required></textarea>
            </div>
            <button type="submit" class="btn btn-primary"><i class="fas fa-paper-plane"></i> Send Reply</button>
          </form>
        </div>
      </div>
      <?php else: ?>
        <div class="p-alert p-alert-info">
          <i class="fas fa-info-circle"></i>
          This ticket is closed. <a href="<?php echo PORTAL_URL; ?>/tickets/add.php" style="color:var(--navy);font-weight:600">Open a new ticket</a> if you need further assistance.
        </div>
      <?php endif; ?>
    </div>

    <!-- Ticket info -->
    <div class="p-card">
      <div class="p-card-header"><div class="p-card-title">Ticket Details</div></div>
      <div class="p-card-body" style="padding:0">
        <?php
        $rows = [
          ['Ticket #',   htmlspecialchars($ticket['ticket_number'])],
          ['Status',     badge($ticket['status'])],
          ['Priority',   badge($ticket['priority'])],
          ['Department', ucfirst($ticket['department'])],
          ['Opened',     format_date($ticket['created_at'])],
          ['Last Reply', time_ago($ticket['updated_at'])],
        ];
        foreach ($rows as [$k, $v]):
        ?>
          <div style="display:flex;justify-content:space-between;align-items:center;padding:10px 16px;border-bottom:1px solid #f1f5f9;font-size:13px">
            <span style="color:var(--text-muted);font-weight:500"><?php echo $k; ?></span>
            <span><?php echo $v; ?></span>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

  </div>
</div>
</div>

<?php require_once '../../includes/footer.php'; ?>
