<?php
/**
 * Orbit Cloud — compose an SMS campaign (or a one-off text to chosen clients).
 */
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/Sms.php';

auth_check();
require_role('admin', APP_URL . '/dashboard.php');
Sms::ensureSchema();
$page_title = 'New SMS Campaign';

// ── Audience preview (AJAX) ──
if (($_GET['preview'] ?? '') === '1') {
    header('Content-Type: application/json');
    $res = Sms::resolveAudience((string) ($_GET['audience'] ?? ''), ['client_ids' => explode(',', (string) ($_GET['ids'] ?? ''))], !empty($_GET['notice']));
    echo json_encode(['count' => count($res['recipients']), 'no_phone' => $res['no_phone'], 'opted_out' => $res['opted_out'],
                      'sample' => array_map(fn($r) => trim($r['first_name'] . ' ' . $r['last_name']), array_slice($res['recipients'], 0, 5))]);
    exit;
}

$error = '';
$form = [
    'name'           => '',
    'message'        => '',
    'audience'       => 'all',
    'client_ids'     => array_filter([(int) ($_GET['client_id'] ?? 0)]),
    'service_notice' => false,
    'when'           => 'now',
    'scheduled_at'   => '',
];
if ($form['client_ids']) {
    $form['audience'] = 'selected';
    $form['service_notice'] = true; // a direct text to one client is not marketing
    $form['name'] = 'Direct message';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $form = [
        'name'           => trim((string) ($_POST['name'] ?? '')),
        'message'        => (string) ($_POST['message'] ?? ''),
        'audience'       => (string) ($_POST['audience'] ?? ''),
        'client_ids'     => array_map('intval', (array) ($_POST['client_ids'] ?? [])),
        'service_notice' => !empty($_POST['service_notice']),
        'when'           => ($_POST['when'] ?? '') === 'later' ? 'later' : 'now',
        'scheduled_at'   => trim((string) ($_POST['scheduled_at'] ?? '')),
    ];
    if (!Sms::provider()) {
        $error = 'Enable and configure Shanfix SMS under Integrations › Providers first.';
    } elseif ($form['when'] === 'later' && $form['scheduled_at'] === '') {
        $error = 'Pick when to send it.';
    } else {
        $r = Sms::createCampaign([
            'name' => $form['name'], 'message' => $form['message'], 'audience' => $form['audience'],
            'client_ids' => $form['client_ids'], 'service_notice' => $form['service_notice'],
            'scheduled_at' => $form['when'] === 'later' ? $form['scheduled_at'] : '',
        ], (int) current_admin()['id']);
        if ($r['ok']) {
            if ($form['when'] === 'now') {
                // Send the first batch right away; cron/sms.php carries on with the rest.
                $q = Sms::processQueue();
                $r['message'] .= sprintf(' %d sent so far%s.', $q['sent'],
                    $q['sent'] + $q['failed'] < $r['total'] ? ' — the rest go out over the next few minutes' : '');
            }
            flash_set('success', $r['message']);
            header('Location: ' . APP_URL . '/sms/campaign.php?id=' . $r['campaign_id']);
            exit;
        }
        $error = $r['message'];
    }
}

$clients = db()->query("SELECT id, first_name, last_name, email, phone, status FROM clients ORDER BY first_name, last_name")->fetchAll();
$balance = null;
if ($sc = Sms::client()) { $b = $sc->balance(); $balance = $b['success'] ? $b['units'] : null; }

require_once '../includes/header.php';
?>

<div class="content-header">
  <div>
    <div class="breadcrumb"><a href="<?php echo APP_URL; ?>/sms/">SMS</a><span class="breadcrumb-sep">›</span> New campaign</div>
    <h1 class="content-title">New SMS Campaign</h1>
  </div>
  <a href="<?php echo APP_URL; ?>/sms/" class="btn btn-ghost"><i class="fas fa-arrow-left"></i> Back</a>
</div>

<?php if (!Sms::provider()): ?>
  <div class="alert alert-warning"><i class="fas fa-triangle-exclamation"></i>
    Shanfix SMS isn't enabled yet. <a href="<?php echo APP_URL; ?>/integrations/#prov-shanfix_sms" style="font-weight:600">Configure it in Providers</a> to send.</div>
<?php endif; ?>
<?php if ($error): ?><div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?php echo h($error); ?></div><?php endif; ?>

<form method="POST" id="smsForm">
<input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>" />
<div class="grid-2" style="align-items:start">
  <div class="card">
    <div class="card-header"><div class="card-title"><i class="fas fa-pen"></i> Message</div></div>
    <div class="card-body">
      <div class="form-group">
        <label class="form-label" for="name">Campaign name <span class="text-muted">(internal)</span></label>
        <input type="text" id="name" name="name" class="form-control" maxlength="150" required value="<?php echo h($form['name']); ?>" placeholder="e.g. December hosting promo" />
      </div>
      <div class="form-group">
        <label class="form-label" for="message">Message</label>
        <textarea id="message" name="message" class="form-control" rows="6" maxlength="918" required
                  placeholder="Hi {first_name}, ..."><?php echo h($form['message']); ?></textarea>
        <div style="display:flex;justify-content:space-between;gap:10px;margin-top:6px;font-size:12px" class="text-muted">
          <span>Insert:
            <?php foreach (Sms::PLACEHOLDERS as $p): ?>
              <button type="button" class="code-chip" data-insert="<?php echo h($p); ?>" style="border:0;cursor:pointer"><?php echo h($p); ?></button>
            <?php endforeach; ?>
          </span>
          <span id="msgCount">0 chars · 0 SMS</span>
        </div>
        <div class="text-muted" style="font-size:12px;margin-top:6px" id="personalNote"></div>
      </div>
      <div class="form-group">
        <label class="form-label">Preview</label>
        <div id="msgPreview" style="background:var(--bg-subtle, #f1f5f9);border-radius:14px;padding:12px 14px;white-space:pre-wrap;font-size:14px;min-height:48px;color:var(--text)"></div>
      </div>
    </div>
  </div>

  <div>
    <div class="card" style="margin-bottom:18px">
      <div class="card-header"><div class="card-title"><i class="fas fa-users"></i> Recipients</div></div>
      <div class="card-body">
        <div class="form-group">
          <?php foreach (Sms::audiences() as $k => $label): ?>
            <label style="display:flex;gap:8px;align-items:center;margin-bottom:6px;font-weight:400">
              <input type="radio" name="audience" value="<?php echo h($k); ?>" <?php echo $form['audience'] === $k ? 'checked' : ''; ?> /> <?php echo h($label); ?>
            </label>
          <?php endforeach; ?>
        </div>
        <div class="form-group" id="pickRow">
          <label class="form-label" for="clientPick">Clients</label>
          <select id="clientPick" name="client_ids[]" class="form-control" multiple size="8">
            <?php foreach ($clients as $c): ?>
              <option value="<?php echo (int) $c['id']; ?>" <?php echo in_array((int) $c['id'], $form['client_ids'], true) ? 'selected' : ''; ?>>
                <?php echo h($c['first_name'] . ' ' . $c['last_name'] . ' — ' . ($c['phone'] ?: 'no phone') . ($c['status'] !== 'active' ? ' (' . $c['status'] . ')' : '')); ?>
              </option>
            <?php endforeach; ?>
          </select>
          <div class="text-muted" style="font-size:12px;margin-top:4px">Ctrl/Cmd-click to choose several.</div>
        </div>
        <label style="display:flex;gap:8px;align-items:flex-start;font-weight:400">
          <input type="checkbox" name="service_notice" value="1" <?php echo $form['service_notice'] ? 'checked' : ''; ?> style="margin-top:3px" />
          <span>Service notice — send even to clients who opted out of SMS offers<br>
            <span class="text-muted" style="font-size:12px">Only for account or service information (outages, maintenance, billing). Never for promotions.</span></span>
        </label>
        <div id="audienceSummary" class="alert alert-info" style="margin-top:14px;margin-bottom:0"><i class="fas fa-circle-info"></i> <span>Counting…</span></div>
      </div>
    </div>

    <div class="card">
      <div class="card-header"><div class="card-title"><i class="fas fa-clock"></i> Send</div></div>
      <div class="card-body">
        <label style="display:flex;gap:8px;align-items:center;margin-bottom:6px;font-weight:400">
          <input type="radio" name="when" value="now" <?php echo $form['when'] === 'now' ? 'checked' : ''; ?> /> Send now
        </label>
        <label style="display:flex;gap:8px;align-items:center;font-weight:400">
          <input type="radio" name="when" value="later" <?php echo $form['when'] === 'later' ? 'checked' : ''; ?> /> Schedule for
          <input type="datetime-local" name="scheduled_at" class="form-control" style="max-width:220px" value="<?php echo h($form['scheduled_at']); ?>" />
        </label>
        <p class="text-muted" style="font-size:12px;margin:12px 0">
          <?php if ($balance !== null): ?>Account balance: <strong><?php echo number_format($balance, 2); ?> units</strong>. <?php endif; ?>
          Estimated cost: <strong id="unitEstimate">—</strong> (1 unit per SMS segment; your Shanfix rate applies).
        </p>
        <button type="submit" class="btn btn-primary" id="sendBtn" data-confirm="Send this SMS campaign? Texts cannot be recalled once sent.">
          <i class="fas fa-paper-plane"></i> Create campaign
        </button>
      </div>
    </div>
  </div>
</div>
</form>

<script>
(function () {
  var f = document.getElementById('smsForm'), msg = document.getElementById('message');
  var gsm = "@£$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !\"#¤%&'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà";
  var ext = "^{}\\[~]|€";
  var recipients = 0, lastQuery = '';
  function segments(t) {
    var len = 0, uni = false, chars = Array.from(t);
    for (var i = 0; i < chars.length; i++) {
      if (gsm.indexOf(chars[i]) >= 0) len++; else if (ext.indexOf(chars[i]) >= 0) len += 2; else { uni = true; break; }
    }
    if (uni) { len = chars.length; return { len: len, seg: len <= 70 ? 1 : Math.ceil(len / 67), uni: true }; }
    return { len: len, seg: t === '' ? 0 : (len <= 160 ? 1 : Math.ceil(len / 153)), uni: false };
  }
  function sample(t) {
    return t.replace(/\{first_name\}/g, 'Jane').replace(/\{last_name\}/g, 'Wanjiku')
            .replace(/\{full_name\}/g, 'Jane Wanjiku').replace(/\{company\}/g, 'Acme Ltd');
  }
  function update() {
    var t = sample(msg.value), s = segments(t);
    document.getElementById('msgCount').textContent = s.len + ' chars · ' + s.seg + ' SMS' + (s.uni ? ' (unicode)' : '');
    document.getElementById('msgPreview').textContent = t || ' ';
    var personal = /\{(first_name|last_name|full_name|company)\}/.test(msg.value);
    document.getElementById('personalNote').textContent = personal
      ? 'Personalised: each client gets their own text, sent about 50 a minute.'
      : 'Same text for everyone: sent in bulk, up to 1,000 at a time. Delivery reports are tracked only for personalised messages.';
    document.getElementById('unitEstimate').textContent = recipients ? '~' + (recipients * Math.max(s.seg, 1)) + ' units' : '—';
  }
  function audience() {
    var a = f.querySelector('input[name=audience]:checked');
    var pick = document.getElementById('pickRow');
    pick.style.display = a && a.value === 'selected' ? '' : 'none';
    var ids = Array.from(document.getElementById('clientPick').selectedOptions).map(function (o) { return o.value; }).join(',');
    var q = 'preview=1&audience=' + encodeURIComponent(a ? a.value : '') + '&ids=' + ids + '&notice=' + (f.service_notice.checked ? 1 : '');
    if (q === lastQuery) return; lastQuery = q;
    fetch('compose.php?' + q, { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (d) {
      recipients = d.count;
      var parts = ['<strong>' + d.count + '</strong> client' + (d.count === 1 ? '' : 's') + ' will receive this'];
      if (d.no_phone) parts.push(d.no_phone + ' skipped (no valid phone)');
      if (d.opted_out) parts.push(d.opted_out + ' skipped (opted out of SMS offers)');
      document.querySelector('#audienceSummary span').innerHTML = parts.join(' · ');
      update();
    }).catch(function () {});
  }
  document.querySelectorAll('[data-insert]').forEach(function (b) {
    b.addEventListener('click', function () {
      var p = b.getAttribute('data-insert'), s = msg.selectionStart, e = msg.selectionEnd;
      msg.value = msg.value.slice(0, s) + p + msg.value.slice(e);
      msg.focus(); msg.selectionStart = msg.selectionEnd = s + p.length; update();
    });
  });
  msg.addEventListener('input', update);
  f.addEventListener('change', audience);
  update(); audience();
})();
</script>

<?php require_once '../includes/footer.php'; ?>
