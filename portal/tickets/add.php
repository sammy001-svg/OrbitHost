<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once dirname(__DIR__, 2) . '/admin/includes/functions.php';
require_once dirname(__DIR__, 2) . '/admin/includes/Notifier.php';
require_once dirname(__DIR__, 2) . '/admin/includes/TicketAttachment.php';

portal_check();
$page_title = 'New Support Ticket';
$cid = current_client()['id'];

$prefill_subject = htmlspecialchars($_GET['subject'] ?? '');

$errors = [];
$data   = ['subject' => $prefill_subject, 'department' => 'technical', 'priority' => 'medium', 'message' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    portal_csrf_verify();
    $data = [
        'subject'    => trim($_POST['subject']    ?? ''),
        'department' => $_POST['department']      ?? 'general',
        'priority'   => $_POST['priority']        ?? 'medium',
        'message'    => trim($_POST['message']    ?? ''),
    ];

    if (!$data['subject']) $errors[] = 'Subject is required.';
    if (!$data['message']) $errors[] = 'Please describe your issue.';

    if (!$errors) {
        $num = generate_ticket_number();
        db()->prepare('INSERT INTO tickets (ticket_number,client_id,subject,department,priority,status) VALUES (?,?,?,?,?,?)')
            ->execute([$num, $cid, $data['subject'], $data['department'], $data['priority'], 'open']);
        $tid = db()->lastInsertId();

        $name = current_client()['name'];
        db()->prepare('INSERT INTO ticket_replies (ticket_id,sender_type,sender_name,message) VALUES (?,?,?,?)')
            ->execute([$tid, 'client', $name, $data['message']]);
        $reply_id = (int) db()->lastInsertId();

        $upload = TicketAttachment::store($_FILES['attachment'] ?? [], (int) $tid, $reply_id);

        Notifier::sendToAllAdmins('ticket_opened_admin', [
            'client_name'    => $name,
            'subject'        => $data['subject'],
            'ticket_number'  => $num,
            'priority'       => ucfirst($data['priority']),
            'link'           => APP_URL . '/tickets/view.php?id=' . $tid,
        ]);

        portal_flash_set(
            $upload['ok'] ? 'success' : 'error',
            $upload['ok'] ? "Ticket $num submitted. We'll respond within 24 hours." : "Ticket $num submitted, but the attachment didn't upload: " . $upload['message']
        );
        header('Location: ' . PORTAL_URL . '/tickets/view.php?id=' . $tid);
        exit;
    }
}

require_once '../includes/header.php';
?>

<div class="page-hero">
  <div class="container"><div><h1>Open a Support Ticket</h1><p>Describe your issue and we'll get back to you as soon as possible</p></div></div>
</div>

<div class="page-body">
<div class="container" style="max-width:680px">

  <?php if ($errors): ?><div class="p-alert p-alert-error"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars(implode(' ',$errors)); ?></div><?php endif; ?>

  <div class="p-form-card" style="margin-bottom:16px">
    <label class="form-label"><i class="fas fa-book" style="color:var(--green)"></i> Search help articles first — you might get your answer instantly</label>
    <input type="text" id="kbSearchBox" class="form-control" placeholder="e.g. renew a domain, cPanel login, invoice payment…" autocomplete="off" autofocus />
    <div id="kbSearchResults" style="margin-top:10px;display:none"></div>
  </div>

  <div class="p-form-card">
    <form method="POST" enctype="multipart/form-data">
      <input type="hidden" name="csrf_token" value="<?php echo portal_csrf(); ?>" />

      <div class="form-group">
        <label class="form-label">Subject <span class="req">*</span></label>
        <input type="text" name="subject" class="form-control"
               value="<?php echo htmlspecialchars($data['subject']); ?>"
               placeholder="Briefly describe your issue…" required />
      </div>

      <div class="form-grid-2">
        <div class="form-group">
          <label class="form-label">Department</label>
          <select name="department" class="form-select">
            <option value="technical" <?php echo $data['department']==='technical'?'selected':''; ?>>Technical Support</option>
            <option value="billing"   <?php echo $data['department']==='billing'  ?'selected':''; ?>>Billing & Payments</option>
            <option value="sales"     <?php echo $data['department']==='sales'    ?'selected':''; ?>>Sales & Upgrades</option>
            <option value="general"   <?php echo $data['department']==='general'  ?'selected':''; ?>>General Enquiry</option>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Priority</label>
          <select name="priority" class="form-select">
            <option value="low"    <?php echo $data['priority']==='low'   ?'selected':''; ?>>Low — General question</option>
            <option value="medium" <?php echo $data['priority']==='medium'?'selected':''; ?>>Medium — Service affected</option>
            <option value="high"   <?php echo $data['priority']==='high'  ?'selected':''; ?>>High — Service down</option>
            <option value="urgent" <?php echo $data['priority']==='urgent'?'selected':''; ?>>Urgent — Critical issue</option>
          </select>
        </div>
      </div>

      <div class="form-group">
        <label class="form-label">Message <span class="req">*</span></label>
        <textarea name="message" class="form-textarea" rows="7" required
                  placeholder="Describe your issue in detail. Include any error messages, domain names, or other relevant information…"><?php echo htmlspecialchars($data['message']); ?></textarea>
      </div>

      <div class="form-group">
        <label class="form-label">Attachment <span style="color:var(--text-muted);font-weight:400">(optional — screenshot, log file, etc.)</span></label>
        <input type="file" name="attachment" class="form-control" accept=".png,.jpg,.jpeg,.gif,.webp,.pdf,.txt,.zip" />
        <small class="form-hint">Images, PDF, TXT or ZIP — up to 8 MB.</small>
      </div>

      <div class="form-actions">
        <button type="submit" class="btn btn-primary"><i class="fas fa-paper-plane"></i> Submit Ticket</button>
        <a href="<?php echo PORTAL_URL; ?>/tickets/" class="btn btn-ghost">Cancel</a>
      </div>
    </form>
  </div>

  <div style="margin-top:16px;padding:16px;background:var(--green-light);border-radius:8px;font-size:13px">
    <strong><i class="fas fa-clock" style="color:var(--green)"></i> Response Times:</strong>
    Low: within 24h &nbsp;·&nbsp; Medium: within 8h &nbsp;·&nbsp; High: within 2h &nbsp;·&nbsp; Urgent: within 1h
  </div>
</div>
</div>

<script>
(function () {
  var API = <?php echo json_encode(preg_replace('#/portal/?$#', '', PORTAL_URL) . '/api/kb-search.php'); ?>;
  var KB  = <?php echo json_encode(preg_replace('#/portal/?$#', '', PORTAL_URL) . '/kb/article.php'); ?>;
  var box = document.getElementById('kbSearchBox');
  var out = document.getElementById('kbSearchResults');
  var timer = null;

  function esc(s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; }

  box.addEventListener('input', function () {
    clearTimeout(timer);
    var term = box.value.trim();
    if (term.length < 2) { out.style.display = 'none'; return; }
    timer = setTimeout(function () {
      fetch(API + '?q=' + encodeURIComponent(term))
        .then(function (r) { return r.json(); })
        .then(function (d) {
          if (!d.ok || !d.results.length) { out.style.display = 'none'; return; }
          out.style.display = 'block';
          out.innerHTML = d.results.map(function (a) {
            return '<a href="' + KB + '?slug=' + encodeURIComponent(a.slug) + '" target="_blank" rel="noopener" ' +
              'style="display:block;padding:9px 12px;border:1px solid var(--border);border-radius:8px;margin-top:6px;text-decoration:none">' +
              '<span style="font-weight:700;color:var(--navy);font-size:13.5px"><i class="fas fa-file-lines" style="font-size:11px;margin-right:6px;color:var(--green)"></i>' + esc(a.title) + '</span>' +
              (a.excerpt ? '<div style="font-size:12px;color:var(--text-muted);margin-top:2px">' + esc(a.excerpt) + '</div>' : '') +
              '</a>';
          }).join('');
        })
        .catch(function () { out.style.display = 'none'; });
    }, 300);
  });
})();
</script>

<?php require_once '../includes/footer.php'; ?>
