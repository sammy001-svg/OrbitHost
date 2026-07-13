</main><!-- /.portal-main -->

<footer class="portal-footer">
  <div class="container">
    <div class="pf-inner">
      <span>© <?php echo date('Y'); ?> OrbitHost. All rights reserved.</span>
      <div class="pf-links">
        <a href="../index.html">Main Website</a>
        <a href="<?php echo PORTAL_URL; ?>/domains.php">My Domains</a>
        <a href="<?php echo PORTAL_URL; ?>/tickets/add.php">Get Support</a>
      </div>
    </div>
  </div>
</footer>

<?php if (!empty($_SESSION['client_id'])): ?>
<!-- Live chat (shares the website chat backend; identity prefilled) -->
<button type="button" class="pchat-btn" id="pchatBtn" aria-label="Live chat"><i class="fas fa-comments"></i></button>
<div class="pchat-panel" id="pchatPanel">
  <div class="pchat-head">
    <i class="fas fa-headset"></i>
    <div style="flex:1">
      <div class="t">OrbitHost Support</div>
      <div class="s">We reply as soon as we can</div>
    </div>
    <button type="button" id="pchatClose" aria-label="Close">&times;</button>
  </div>
  <div class="pchat-body" id="pchatBody">
    <div class="pchat-bubble">&#128075; Hi <?php echo htmlspecialchars(explode(' ', current_client()['name'])[0] ?? ''); ?>! How can we help?</div>
  </div>
  <div class="pchat-foot">
    <input type="text" id="pchatText" placeholder="Type a message…" maxlength="2000" />
    <button type="button" id="pchatSend" aria-label="Send"><i class="fas fa-paper-plane"></i></button>
  </div>
</div>
<script>
(function () {
  var API = <?php echo json_encode(preg_replace('#/portal/?$#', '', PORTAL_URL) . '/api/chat.php'); ?>;
  var NAME = <?php echo json_encode(current_client()['name']); ?>;
  var EMAIL = <?php echo json_encode(current_client()['email']); ?>;
  var conv = localStorage.getItem('oh_chat_conv'), token = localStorage.getItem('oh_chat_token');
  var lastId = 0, timer = null;
  var body = document.getElementById('pchatBody'), text = document.getElementById('pchatText');
  var panel = document.getElementById('pchatPanel');

  function bubble(msg, mine, who) {
    var d = document.createElement('div');
    d.className = 'pchat-bubble' + (mine ? ' me' : '');
    d.textContent = msg;
    if (!mine && who) d.setAttribute('data-who', who);
    body.appendChild(d);
    body.scrollTop = body.scrollHeight;
  }
  function poll() {
    if (!conv) return;
    fetch(API + '?action=poll&conversation=' + conv + '&token=' + encodeURIComponent(token) + '&after=' + lastId)
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d.ok) return;
        d.messages.forEach(function (m) {
          lastId = Math.max(lastId, Number(m.id));
          if (m.sender === 'admin') bubble(m.message, false, m.sender_name || 'Support');
        });
      }).catch(function () {});
  }
  function startPolling() { if (!timer) { poll(); timer = setInterval(poll, 4000); } }
  function send() {
    var msg = text.value.trim();
    if (!msg) return;
    text.value = '';
    bubble(msg, true);
    var fd = new FormData();
    if (!conv) {
      fd.append('action', 'start'); fd.append('message', msg);
      fd.append('name', NAME); fd.append('email', EMAIL);
    } else {
      fd.append('action', 'send'); fd.append('conversation', conv);
      fd.append('token', token); fd.append('message', msg);
    }
    fetch(API, { method: 'POST', body: fd })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (d.ok && d.conversation) {
          conv = d.conversation; token = d.token;
          localStorage.setItem('oh_chat_conv', conv);
          localStorage.setItem('oh_chat_token', token);
        }
        if (d.ok) startPolling();
        else bubble(d.error || 'Could not send — try again.', false, 'System');
      })
      .catch(function () { bubble('Connection problem — try again.', false, 'System'); });
  }
  document.getElementById('pchatBtn').addEventListener('click', function () {
    panel.classList.toggle('open');
    if (panel.classList.contains('open')) { if (conv) startPolling(); text.focus(); }
  });
  document.getElementById('pchatClose').addEventListener('click', function () { panel.classList.remove('open'); });
  document.getElementById('pchatSend').addEventListener('click', send);
  text.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); send(); } });
  if (conv) startPolling();
})();
</script>
<?php endif; ?>

<script src="<?php echo PORTAL_URL; ?>/js/portal.js?v=<?php echo @filemtime(__DIR__ . '/../js/portal.js') ?: time(); ?>"></script>
</body>
</html>
