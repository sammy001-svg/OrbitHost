<?php
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

auth_check();

$id = (int)($_GET['id'] ?? 0);
$stmt = db()->prepare('SELECT * FROM clients WHERE id=?');
$stmt->execute([$id]);
$client = $stmt->fetch();

if (!$client) {
    flash_set('error', 'Client not found.');
    header('Location: ' . APP_URL . '/clients/');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    // Expire old unused invites
    db()->prepare('DELETE FROM portal_invites WHERE client_id=? AND accepted_at IS NULL')
        ->execute([$id]);

    $token   = bin2hex(random_bytes(32));
    $expires = date('Y-m-d H:i:s', strtotime('+48 hours'));

    db()->prepare('INSERT INTO portal_invites (client_id, token, expires_at) VALUES (?,?,?)')
        ->execute([$id, $token, $expires]);

    // Derive portal URL from admin URL
    $portal_url = rtrim(APP_URL, '/') . '/../portal';
    // Better: build relative to document root
    $prot = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $root = rtrim(str_replace('\\', '/', dirname(dirname(dirname(__DIR__)))), '/');
    $doc  = rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
    $rel  = str_replace($doc, '', $root);
    $portal_url = $prot . '://' . $host . $rel . '/portal';

    $invite_link = $portal_url . '/accept-invite.php?token=' . $token;
    $name        = $client['first_name'] . ' ' . $client['last_name'];

    $subject = 'You\'re invited to OrbitHost Client Portal';
    $body = "Dear {$client['first_name']},\n\n"
          . "Your account has been set up on the OrbitHost client portal.\n\n"
          . "Click the link below to activate your account and set your password (valid 48 hours):\n"
          . $invite_link . "\n\n"
          . "Through the portal you can:\n"
          . "  • View and manage your hosting services\n"
          . "  • Download and pay invoices\n"
          . "  • Submit and track support tickets\n\n"
          . "If you have any questions, reply to this email or contact sammyopiyo001@gmail.com.\n\n"
          . "Best regards,\nThe OrbitHost Team";
    $headers = "From: noreply@orbithost.co.ke\r\nReply-To: sammyopiyo001@gmail.com\r\nX-Mailer: PHP";

    @mail($client['email'], $subject, $body, $headers);

    log_activity('invite_sent', "Portal invite sent to client #{$id} ({$client['email']})");
    flash_set('success', "Invite sent to {$client['email']}. Link expires in 48 hours.");
    header('Location: ' . APP_URL . '/clients/view.php?id=' . $id);
    exit;
}

$page_title = 'Send Portal Invite';
require_once '../includes/header.php';
?>

<div class="content-header">
  <h1 class="content-title">Send Portal Invite</h1>
  <a href="<?php echo APP_URL; ?>/clients/view.php?id=<?php echo $id; ?>" class="btn btn-ghost"><i class="fas fa-arrow-left"></i> Back</a>
</div>

<div style="max-width:520px">
  <div class="card">
    <div class="card-body">
      <div style="display:flex;align-items:center;gap:16px;margin-bottom:24px">
        <div class="client-avatar" style="width:52px;height:52px;font-size:20px">
          <?php echo strtoupper(substr($client['first_name'], 0, 1) . substr($client['last_name'], 0, 1)); ?>
        </div>
        <div>
          <div style="font-weight:700;font-size:16px"><?php echo htmlspecialchars($client['first_name'] . ' ' . $client['last_name']); ?></div>
          <div style="font-size:13px;color:var(--text-muted)"><?php echo htmlspecialchars($client['email']); ?></div>
        </div>
      </div>

      <?php if ($client['portal_password']): ?>
        <div class="alert alert-info" style="margin-bottom:18px">
          <i class="fas fa-info-circle"></i>
          This client already has portal access. Sending a new invite will reset their login link (but not their existing password).
        </div>
      <?php endif; ?>

      <p style="font-size:14px;color:var(--text-muted);margin-bottom:20px;line-height:1.6">
        An invite email will be sent to <strong><?php echo htmlspecialchars($client['email']); ?></strong> with a unique link to activate their client portal account. The link expires in <strong>48 hours</strong>.
      </p>

      <form method="POST">
        <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>" />
        <div style="display:flex;gap:10px">
          <button type="submit" class="btn btn-primary"><i class="fas fa-envelope"></i> Send Invite Email</button>
          <a href="<?php echo APP_URL; ?>/clients/view.php?id=<?php echo $id; ?>" class="btn btn-ghost">Cancel</a>
        </div>
      </form>
    </div>
  </div>
</div>

<?php require_once '../includes/footer.php'; ?>
