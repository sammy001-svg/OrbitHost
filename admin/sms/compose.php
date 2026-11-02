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
    $first = $res['recipients'][0] ?? null;
    echo json_encode(['count' => count($res['recipients']), 'no_phone' => $res['no_phone'], 'opted_out' => $res['opted_out'],
                      'sample' => array_map(fn($r) => trim($r['first_name'] . ' ' . $r['last_name']), array_slice($res['recipients'], 0, 5)),
                      // The first real recipient, so the phone preview shows
                      // exactly what one client will receive.
                      'first' => $first ? ['first_name' => trim((string) $first['first_name']), 'last_name' => trim((string) $first['last_name']),
                                           'company' => trim((string) ($first['company'] ?? ''))] : null]);
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
// Shown at the top of the phone preview — what the client's phone shows as the sender.
$sender_id = '';
if ($pk = Sms::provider()) $sender_id = trim((string) (Provider::config($pk)['sender_id'] ?? ''));
$sender_id = $sender_id !== '' ? $sender_id : 'Your sender ID';

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
<div class="sms-compose-grid">
  <div style="display:grid;gap:18px;min-width:0">
    <div class="card">
      <div class="card-header"><div class="card-title"><i class="fas fa-pen"></i> Message</div></div>
      <div class="card-body">
        <div class="form-group">
          <label class="form-label" for="name">Campaign name <span class="text-muted">(internal — clients never see it)</span></label>
          <input type="text" id="name" name="name" class="form-control" maxlength="150" required value="<?php echo h($form['name']); ?>" placeholder="e.g. December hosting promo" />
        </div>
        <div class="form-group" style="margin-bottom:0">
          <label class="form-label" for="message">Message</label>
          <textarea id="message" name="message" class="form-control" rows="7" maxlength="918" required
                    placeholder="Hi {first_name}, …"><?php echo h($form['message']); ?></textarea>
          <div class="sms-chips">
            <span>Personalise:</span>
            <?php foreach (Sms::PLACEHOLDERS as $p): ?>
              <button type="button" class="sms-chip" data-insert="<?php echo h($p); ?>" title="Insert <?php echo h($p); ?>"><?php echo h($p); ?></button>
            <?php endforeach; ?>
          </div>
          <div class="sms-meter" id="smsMeter">
            <div class="sms-meter-bar"><span id="segBar"></span></div>
            <div class="sms-stats">
              <span><strong id="msgChars">0</strong> / <span id="msgCap">160</span> characters</span>
              <span><strong id="msgParts">0</strong> SMS per recipient</span>
              <span id="msgEnc">Standard text</span>
            </div>
          </div>
          <div class="sms-note" id="encNote" hidden></div>
          <div class="sms-note" id="personalNote"></div>
        </div>
      </div>
    </div>

    <div class="card">
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
  </div>

  <div class="sms-side">
    <div class="card">
      <div class="card-header"><div class="card-title"><i class="fas fa-mobile-screen"></i> Preview</div></div>
      <div class="card-body">
        <div class="sms-phone" aria-label="Message preview">
          <div class="sms-phone-top">
            <span class="sms-avatar"><i class="fas fa-comment-sms"></i></span>
            <div style="min-width:0">
              <div class="sms-sender"><?php echo h($sender_id); ?></div>
              <div class="sms-sub">Text message</div>
            </div>
          </div>
          <div class="sms-thread">
            <div class="sms-day">Today</div>
            <div class="sms-bubble empty" id="msgPreview">Your message will appear here.</div>
            <div class="sms-time" id="msgTime"></div>
          </div>
        </div>
        <div class="sms-note" id="previewFor" style="text-align:center"></div>
      </div>
    </div>

    <div class="card">
      <div class="card-header"><div class="card-title"><i class="fas fa-paper-plane"></i> Send</div></div>
      <div class="card-body">
        <label style="display:flex;gap:8px;align-items:center;margin-bottom:8px;font-weight:400">
          <input type="radio" name="when" value="now" <?php echo $form['when'] === 'now' ? 'checked' : ''; ?> /> Send now
        </label>
        <label style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;font-weight:400">
          <input type="radio" name="when" value="later" <?php echo $form['when'] === 'later' ? 'checked' : ''; ?> /> Schedule for
          <input type="datetime-local" name="scheduled_at" class="form-control" style="max-width:220px" value="<?php echo h($form['scheduled_at']); ?>" />
        </label>
        <div class="data-list" style="margin:14px 0">
          <div class="row"><span class="k">Recipients</span><span class="v" id="sumRecipients">—</span></div>
          <div class="row"><span class="k">SMS per recipient</span><span class="v" id="sumParts">—</span></div>
          <div class="row"><span class="k">Estimated units</span><span class="v" id="unitEstimate">—</span></div>
          <?php if ($balance !== null): ?>
            <div class="row"><span class="k">Your balance</span><span class="v" id="sumBalance" data-balance="<?php echo h((string) $balance); ?>"><?php echo number_format($balance, 2); ?> units</span></div>
          <?php endif; ?>
        </div>
        <div class="sms-note warn" id="balanceWarn" hidden></div>
        <button type="submit" class="btn btn-primary" id="sendBtn" style="width:100%;justify-content:center;margin-top:10px" data-confirm="Send this SMS campaign? Texts cannot be recalled once sent.">
          <i class="fas fa-paper-plane"></i> Create campaign
        </button>
        <p class="text-muted" style="font-size:11.5px;margin-top:8px;text-align:center">1 unit per SMS part; your Shanfix rate applies.</p>
      </div>
    </div>
  </div>
</div>
</form>

<script>
(function () {
  var f = document.getElementById('smsForm'), msg = document.getElementById('message');
  var $ = function (id) { return document.getElementById(id); };
  // GSM-7: anything outside this set forces Unicode (70 chars per SMS instead of 160).
  var gsm = "@£$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !\"#¤%&'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà";
  var ext = "^{}\\[~]|€";
  var MAX = 918;
  var recipients = 0, lastQuery = '';
  var who = { first_name: 'Jane', last_name: 'Wanjiku', company: 'Acme Ltd', real: false };

  function measure(t) {
    var len = 0, bad = [], chars = Array.from(t);
    chars.forEach(function (c) {
      if (gsm.indexOf(c) >= 0) len++;
      else if (ext.indexOf(c) >= 0) len += 2;
      else if (bad.indexOf(c) < 0) bad.push(c);
    });
    if (bad.length) {
      len = chars.length;
      var seg = len === 0 ? 0 : (len <= 70 ? 1 : Math.ceil(len / 67));
      return { len: len, seg: seg, cap: seg <= 1 ? 70 : seg * 67, uni: true, bad: bad };
    }
    var s = len === 0 ? 0 : (len <= 160 ? 1 : Math.ceil(len / 153));
    return { len: len, seg: s, cap: s <= 1 ? 160 : s * 153, uni: false, bad: [] };
  }
  // Same substitution the server does (Sms::personalize): no company → first name.
  function personalize(t) {
    var full = (who.first_name + ' ' + who.last_name).trim();
    return t.replace(/\{first_name\}/g, who.first_name).replace(/\{last_name\}/g, who.last_name)
            .replace(/\{full_name\}/g, full).replace(/\{company\}/g, who.company || who.first_name);
  }
  function update() {
    var raw = msg.value, t = personalize(raw), m = measure(t);
    var bubble = $('msgPreview');
    if (raw.trim() === '') { bubble.textContent = 'Your message will appear here.'; bubble.classList.add('empty'); }
    else { bubble.textContent = t; bubble.classList.remove('empty'); }
    var now = new Date();
    $('msgTime').textContent = raw.trim() ? now.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) : '';

    $('msgChars').textContent = m.len;
    $('msgCap').textContent = m.cap;
    $('msgParts').textContent = m.seg;
    $('msgEnc').textContent = m.uni ? 'Unicode (70 per SMS)' : 'Standard text';
    $('segBar').style.width = (m.cap ? Math.min(100, m.len / m.cap * 100) : 0) + '%';
    var meter = $('smsMeter');
    meter.classList.toggle('warn', m.seg >= 2 && raw.length <= MAX - 60);
    meter.classList.toggle('over', raw.length > MAX - 60);

    var enc = $('encNote');
    if (m.uni) {
      enc.hidden = false; enc.className = 'sms-note warn';
      enc.textContent = 'Special characters ' + m.bad.slice(0, 5).map(function (c) { return '“' + c + '”'; }).join(' ')
        + ' switch this message to Unicode, so each SMS holds 70 characters instead of 160. Replace them (e.g. curly quotes with straight ones) to save units.';
    } else { enc.hidden = true; }

    var personal = /\{(first_name|last_name|full_name|company)\}/.test(raw);
    $('personalNote').textContent = personal
      ? 'Personalised — each client gets their own text (sent about 50 a minute, with delivery reports).'
      : 'Same text for everyone — sent in bulk, up to 1,000 at a time.';
    $('previewFor').textContent = personal
      ? (who.real ? 'Showing what ' + (who.first_name + ' ' + who.last_name).trim() + ' will receive.' : 'Showing example details — pick recipients to preview a real client.')
      : '';

    var parts = Math.max(m.seg, 1), units = recipients * parts;
    $('sumRecipients').textContent = recipients ? recipients.toLocaleString() : '—';
    $('sumParts').textContent = raw.trim() ? m.seg : '—';
    $('unitEstimate').textContent = recipients && raw.trim() ? '~' + units.toLocaleString() : '—';
    var bal = $('sumBalance'), warn = $('balanceWarn');
    if (bal && recipients && raw.trim() && units > parseFloat(bal.dataset.balance)) {
      warn.hidden = false;
      warn.textContent = 'This needs about ' + units.toLocaleString() + ' units but your balance is ' + parseFloat(bal.dataset.balance).toLocaleString() + '. Top up Shanfix SMS first, or the rest will fail.';
    } else if (warn) { warn.hidden = true; }
  }
  function audience() {
    var a = f.querySelector('input[name=audience]:checked');
    $('pickRow').style.display = a && a.value === 'selected' ? '' : 'none';
    var ids = Array.from($('clientPick').selectedOptions).map(function (o) { return o.value; }).join(',');
    var q = 'preview=1&audience=' + encodeURIComponent(a ? a.value : '') + '&ids=' + ids + '&notice=' + (f.service_notice.checked ? 1 : '');
    if (q === lastQuery) return; lastQuery = q;
    fetch('compose.php?' + q, { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (d) {
      recipients = d.count;
      who = d.first ? { first_name: d.first.first_name || '', last_name: d.first.last_name || '', company: d.first.company || '', real: true }
                    : { first_name: 'Jane', last_name: 'Wanjiku', company: 'Acme Ltd', real: false };
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
