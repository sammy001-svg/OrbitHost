<?php
/**
 * Orbit Cloud — the "check your phone" screen shown after an M-Pesa STK push.
 *
 * Polls portal/payment-status.php, so the client never has to click
 * "verify": the moment Kopo Kopo confirms (by webhook or by our check) the
 * page moves on to the flow's own success view. Failures show the real
 * reason with a one-tap resend; a prompt that never arrived can be resent,
 * optionally to a different number.
 *
 *   stk_wait_panel($pay, $return_tpl, $back_url)
 *     $pay        the payments row (gateway kopokopo)
 *     $return_tpl the flow's result URL with {pay} where the id goes,
 *                 e.g. PORTAL_URL . '/checkout.php?pay={pay}'
 *     $back_url   where "pay another way" goes
 *
 * Self-contained markup, CSS and JS — it is used both inside the portal
 * layout and on the standalone checkout pages.
 */
require_once dirname(__DIR__, 2) . '/admin/includes/providers/PaymentClient.php';

/**
 * One payment per click: any form that picks a gateway gets its submit
 * button locked on first submit, so a double tap can't fire two STK
 * prompts (or two card checkouts). Echo once per page.
 */
function stk_submit_guard(): void
{
    ?>
<script>
document.addEventListener('submit', function (e) {
  var f = e.target;
  if (!f.querySelector('[name="gateway"]')) return;
  if (f.dataset.sent === '1') { e.preventDefault(); return; }
  f.dataset.sent = '1';
  // Disable on the next tick: a button disabled DURING submit is dropped
  // from the submitted fields, losing its name/value.
  setTimeout(function () {
    f.querySelectorAll('button[type="submit"], button:not([type])').forEach(function (b) {
      b.disabled = true;
      b.dataset.label = b.innerHTML;
      b.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing…';
    });
  }, 0);
  // Back/forward cache: re-enable if the user comes back to this page.
  window.addEventListener('pageshow', function () {
    f.dataset.sent = '';
    f.querySelectorAll('button[data-label]').forEach(function (b) { b.disabled = false; b.innerHTML = b.dataset.label; });
  }, { once: true });
}, true);
</script>
    <?php
}

function stk_wait_panel(array $pay, string $return_tpl, string $back_url): void
{
    $raw   = json_decode($pay['raw'] ?? '', true) ?: [];
    $phone = (string) ($raw['checkout']['phone'] ?? '');
    if ($phone === '' && !empty($pay['client_id'])) {
        $s = db()->prepare('SELECT phone FROM clients WHERE id = ?');
        $s->execute([(int) $pay['client_id']]);
        $phone = (string) (PaymentClient::mpesaPhone((string) $s->fetchColumn()) ?? '');
    }
    $masked  = $phone !== '' ? PaymentClient::maskPhone($phone) : 'your phone';
    $amount  = 'KES ' . number_format((int) ceil(round((float) $pay['amount'], 2)));
    $age     = max(0, time() - strtotime((string) $pay['created_at']));
    $cfg = [
        'pay'      => (int) $pay['id'],
        'status'   => PORTAL_URL . '/payment-status.php',
        'ret'      => $return_tpl,
        'csrf'     => portal_csrf(),
        'age'      => $age,
    ];
    $now_url = str_replace('{pay}', (string) (int) $pay['id'], $return_tpl);
    ?>
<div class="stk" id="stkPanel" data-cfg="<?php echo htmlspecialchars(json_encode($cfg), ENT_QUOTES); ?>">
  <style>
    .stk { --stk-g: var(--green, #1A8A45); --stk-r: #dc2626; --stk-m: var(--text-muted, #64748b); text-align: center; color: var(--text, #0f172a); }
    .stk-icon { width: 76px; height: 76px; border-radius: 50%; margin: 4px auto 14px; display: flex; align-items: center; justify-content: center; font-size: 32px; background: #ecfdf5; color: var(--stk-g); position: relative; }
    .stk[data-state="pending"] .stk-icon::after { content: ""; position: absolute; inset: -6px; border-radius: 50%; border: 3px solid var(--stk-g); border-top-color: transparent; animation: stkspin 1.1s linear infinite; }
    .stk[data-state="failed"] .stk-icon { background: #fef2f2; color: var(--stk-r); }
    @keyframes stkspin { to { transform: rotate(360deg); } }
    @media (prefers-reduced-motion: reduce) { .stk[data-state="pending"] .stk-icon::after { animation: none; border-top-color: var(--stk-g); } }
    .stk h2 { font-size: 19px; margin: 0 0 6px; }
    .stk-lead { font-size: 14px; color: var(--stk-m); margin: 0 auto 16px; max-width: 420px; line-height: 1.5; }
    .stk-lead strong { color: var(--text, #0f172a); }
    .stk-steps { text-align: left; max-width: 360px; margin: 0 auto 16px; padding: 0; list-style: none; counter-reset: s; }
    .stk-steps li { counter-increment: s; display: flex; gap: 10px; align-items: flex-start; font-size: 13.5px; padding: 6px 0; }
    .stk-steps li::before { content: counter(s); flex: 0 0 22px; height: 22px; border-radius: 50%; background: #ecfdf5; color: var(--stk-g); font-weight: 700; font-size: 12px; display: flex; align-items: center; justify-content: center; }
    .stk-status { font-size: 13px; color: var(--stk-m); margin: 6px 0 16px; min-height: 18px; }
    .stk-msg { border-radius: 10px; padding: 11px 14px; font-size: 13.5px; margin: 0 0 16px; text-align: left; }
    .stk-msg.err { background: #fef2f2; color: #991b1b; }
    .stk-msg.ok  { background: #ecfdf5; color: #065f46; }
    .stk-msg.info{ background: #eff6ff; color: #1e40af; }
    .stk-actions { display: flex; flex-direction: column; gap: 10px; max-width: 360px; margin: 0 auto; }
    .stk-actions .btn { width: 100%; justify-content: center; }
    .stk-alt { display: flex; gap: 8px; margin-top: 2px; }
    .stk-alt input { flex: 1; min-width: 0; padding: 10px 12px; border: 1px solid var(--border, #e2e8f0); border-radius: 8px; font-size: 14px; }
    .stk-link { font-size: 12.5px; color: var(--stk-m); }
    .stk [hidden] { display: none !important; }
  </style>

  <div class="stk-icon" aria-hidden="true"><i class="fas fa-mobile-screen-button" id="stkIco"></i></div>
  <h2 id="stkTitle">Check your phone</h2>
  <p class="stk-lead" id="stkLead">We've sent an M-Pesa prompt for <strong><?php echo htmlspecialchars($amount); ?></strong> to <strong id="stkPhone"><?php echo htmlspecialchars($masked); ?></strong>.</p>

  <ol class="stk-steps" id="stkSteps">
    <li>Unlock your phone — the M-Pesa prompt appears on screen.</li>
    <li>Check the amount and enter your M-Pesa PIN.</li>
    <li>Stay on this page — it confirms automatically.</li>
  </ol>

  <div id="stkMsg" class="stk-msg" hidden role="status"></div>
  <div class="stk-status" id="stkStatus" aria-live="polite">Waiting for your approval…</div>

  <div class="stk-actions">
    <div id="stkResendBox" hidden>
      <button type="button" class="btn btn-primary" id="stkResend"><i class="fas fa-paper-plane"></i> Resend the prompt</button>
      <div class="stk-alt" style="margin-top:10px">
        <input type="tel" id="stkAltPhone" placeholder="Send to another number: 07XX XXX XXX" autocomplete="tel" />
      </div>
    </div>
    <a href="<?php echo htmlspecialchars($now_url); ?>" class="btn btn-ghost" id="stkCheck"><i class="fas fa-rotate"></i> I've paid — check now</a>
    <a href="<?php echo htmlspecialchars($back_url); ?>" class="stk-link" id="stkBack">Pay another way</a>
  </div>
</div>
<script>
(function () {
  var el = document.getElementById('stkPanel'); if (!el || !window.fetch) return;
  var cfg = JSON.parse(el.getAttribute('data-cfg'));
  var pay = cfg.pay, started = Date.now() - cfg.age * 1000, timer = null, busy = false, done = false;
  var $ = function (id) { return document.getElementById(id); };
  el.setAttribute('data-state', 'pending');

  function msg(text, kind) { var m = $('stkMsg'); if (!text) { m.hidden = true; return; } m.className = 'stk-msg ' + kind; m.textContent = text; m.hidden = false; }
  function secs() { return Math.floor((Date.now() - started) / 1000); }
  function goTo() { window.location.href = cfg.ret.replace('{pay}', pay); }

  function render(d) {
    if (d.status === 'completed' || d.status === 'underpaid') {
      done = true; clearTimeout(timer);
      el.setAttribute('data-state', 'done');
      $('stkIco').className = 'fas fa-circle-check';
      $('stkTitle').textContent = d.status === 'completed' ? 'Payment received' : 'Payment received — balance due';
      $('stkSteps').hidden = true; $('stkResendBox').hidden = true; $('stkBack').hidden = true;
      msg(d.message, d.status === 'completed' ? 'ok' : 'info');
      $('stkStatus').textContent = 'Taking you to your receipt…';
      setTimeout(goTo, 1600);
      return;
    }
    if (d.status === 'failed') {
      el.setAttribute('data-state', 'failed');
      $('stkIco').className = 'fas fa-circle-xmark';
      $('stkTitle').textContent = 'Payment not completed';
      $('stkSteps').hidden = true;
      msg(d.message || 'The M-Pesa payment was not completed.', 'err');
      $('stkStatus').textContent = 'You haven\'t been charged. You can try again below.';
      $('stkResend').innerHTML = '<i class="fas fa-rotate-right"></i> Try again';
      $('stkResendBox').hidden = false;
      return;
    }
    // pending
    var s = secs();
    if (s < 60)       $('stkStatus').textContent = 'Waiting for your approval… ' + (60 - s) + 's';
    else if (s < 180) $('stkStatus').textContent = 'Still waiting — if you\'ve entered your PIN, confirmation can take a minute.';
    else              $('stkStatus').textContent = 'No confirmation yet. If no prompt appeared, resend it below.';
    if (s >= 25) $('stkResendBox').hidden = false;
  }

  function poll() {
    if (done) return;
    fetch(cfg.status + '?pay=' + pay, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
      .then(function (r) { return r.json(); })
      .then(function (d) { if (d.status === 'error') { $('stkStatus').textContent = d.message; return; } render(d); })
      .catch(function () { $('stkStatus').textContent = 'Connection hiccup — retrying…'; })
      .then(function () {
        if (done || el.getAttribute('data-state') === 'failed') return;
        var s = secs();
        timer = setTimeout(poll, s < 180 ? 3000 : (s < 600 ? 10000 : 30000));
      });
  }

  $('stkResend').addEventListener('click', function () {
    if (busy) return; busy = true;
    var b = this, label = b.innerHTML;
    b.disabled = true; b.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending…';
    var body = new URLSearchParams({ action: 'resend', pay: pay, csrf_token: cfg.csrf, phone: $('stkAltPhone').value.trim() });
    fetch(cfg.status, { method: 'POST', credentials: 'same-origin', body: body, headers: { 'Accept': 'application/json' } })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (d.status === 'completed') { render(d); return; }
        if (d.status !== 'pending') { msg(d.message || 'Could not resend the prompt.', 'err'); return; }
        pay = d.pay; started = Date.now();
        el.setAttribute('data-state', 'pending');
        $('stkIco').className = 'fas fa-mobile-screen-button';
        $('stkTitle').textContent = 'Check your phone';
        $('stkSteps').hidden = false; $('stkResendBox').hidden = true;
        if (d.phone) $('stkPhone').textContent = d.phone;
        $('stkCheck').href = cfg.ret.replace('{pay}', pay);
        msg('New prompt sent to ' + (d.phone || 'your phone') + '.', 'info');
        try { history.replaceState(null, '', cfg.ret.replace('{pay}', pay)); } catch (e) {}
        clearTimeout(timer); poll();
      })
      .catch(function () { msg('Could not reach the server — check your connection and try again.', 'err'); })
      .then(function () { busy = false; b.disabled = false; b.innerHTML = label; });
  });

  render({ status: 'pending' });
  poll();
})();
</script>
    <?php
}
