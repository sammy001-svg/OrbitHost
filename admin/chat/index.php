<?php
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

auth_check();
$page_title = 'Live Chat';

// Tables are auto-created by api/chat.php on first visitor message; guard here.
try {
    db()->query('SELECT 1 FROM chat_conversations LIMIT 1');
    $chat_ready = true;
} catch (\Throwable $e) {
    $chat_ready = false;
}

$sel = (int)($_GET['id'] ?? 0);

// ── AJAX: live conversation list (inbox refresh without reload) ──
if ($chat_ready && (($_GET['ajax'] ?? '') === 'list')) {
    header('Content-Type: application/json');
    $rows = db()->query(
        'SELECT c.id, c.name, c.status, c.updated_at,
                (SELECT COUNT(*) FROM chat_messages m WHERE m.conversation_id = c.id AND m.sender = "visitor" AND m.admin_read = 0) unread,
                (SELECT message FROM chat_messages m WHERE m.conversation_id = c.id ORDER BY id DESC LIMIT 1) last_msg
         FROM chat_conversations c
         ORDER BY (c.status = "open") DESC, c.updated_at DESC LIMIT 100'
    )->fetchAll();
    $unread_total = 0;
    $out = array_map(function ($c) use (&$unread_total) {
        $unread_total += (int) $c['unread'];
        return ['id' => (int) $c['id'], 'name' => $c['name'] ?: ('Visitor #' . $c['id']),
                'unread' => (int) $c['unread'], 'status' => $c['status'],
                'last' => mb_strimwidth($c['last_msg'] ?? '', 0, 46, '…'), 'time' => time_ago($c['updated_at'])];
    }, $rows);
    echo json_encode(['ok' => true, 'unread' => $unread_total, 'conversations' => $out]);
    exit;
}

// ── AJAX: poll new messages for the open thread ──
if ($chat_ready && isset($_GET['ajax']) && $sel) {
    header('Content-Type: application/json');
    $after = (int)($_GET['after'] ?? 0);
    $stmt = db()->prepare('SELECT id, sender, sender_name, message, DATE_FORMAT(created_at, "%H:%i") t
                           FROM chat_messages WHERE conversation_id = ? AND id > ? ORDER BY id LIMIT 100');
    $stmt->execute([$sel, $after]);
    $msgs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    db()->prepare('UPDATE chat_messages SET admin_read = 1 WHERE conversation_id = ? AND sender = "visitor"')->execute([$sel]);
    echo json_encode(['ok' => true, 'messages' => $msgs]);
    exit;
}

// ── Actions: reply / close / reopen ──
if ($chat_ready && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';
    $cid    = (int)($_POST['conversation'] ?? 0);

    if ($action === 'reply' && $cid) {
        $msg = trim($_POST['message'] ?? '');
        if ($msg !== '') {
            db()->prepare('INSERT INTO chat_messages (conversation_id, sender, sender_name, message, admin_read) VALUES (?,?,?,?,1)')
                ->execute([$cid, 'admin', current_admin()['name'], $msg]);
            db()->prepare('UPDATE chat_conversations SET updated_at = NOW(), status = "open" WHERE id = ?')->execute([$cid]);
        }
        if (isset($_POST['ajax'])) { header('Content-Type: application/json'); echo json_encode(['ok' => true]); exit; }
    } elseif ($action === 'close' && $cid) {
        db()->prepare('UPDATE chat_conversations SET status = "closed" WHERE id = ?')->execute([$cid]);
        flash_set('success', 'Conversation closed.');
    } elseif ($action === 'reopen' && $cid) {
        db()->prepare('UPDATE chat_conversations SET status = "open" WHERE id = ?')->execute([$cid]);
    }
    header('Location: ' . APP_URL . '/chat/?id=' . $cid);
    exit;
}

$conversations = [];
$thread = null;
$messages = [];
if ($chat_ready) {
    $conversations = db()->query(
        'SELECT c.*,
                (SELECT COUNT(*) FROM chat_messages m WHERE m.conversation_id = c.id AND m.sender = "visitor" AND m.admin_read = 0) unread,
                (SELECT message FROM chat_messages m WHERE m.conversation_id = c.id ORDER BY id DESC LIMIT 1) last_msg
         FROM chat_conversations c
         ORDER BY (c.status = "open") DESC, c.updated_at DESC LIMIT 100'
    )->fetchAll();

    if ($sel) {
        $stmt = db()->prepare('SELECT * FROM chat_conversations WHERE id = ?');
        $stmt->execute([$sel]);
        $thread = $stmt->fetch();
        if ($thread) {
            $stmt = db()->prepare('SELECT *, DATE_FORMAT(created_at, "%b %e · %H:%i") t FROM chat_messages WHERE conversation_id = ? ORDER BY id');
            $stmt->execute([$sel]);
            $messages = $stmt->fetchAll();
            db()->prepare('UPDATE chat_messages SET admin_read = 1 WHERE conversation_id = ? AND sender = "visitor"')->execute([$sel]);
        }
    }
}

$open_unread = 0;
foreach ($conversations as $c) $open_unread += (int)$c['unread'];

require_once '../includes/header.php';
?>

<div class="content-header">
  <div>
    <h1 class="content-title">Live Chat</h1>
    <p class="page-subtitle">Conversations from the website chat widget. Replies appear in the visitor's chat within seconds.</p>
  </div>
  <?php if ($open_unread): ?><span class="badge badge-danger" style="font-size:13px"><?php echo $open_unread; ?> unread</span><?php endif; ?>
</div>

<?php if (!$chat_ready): ?>
  <div class="alert alert-info"><i class="fas fa-circle-info"></i> No chats yet — the chat tables are created automatically the first time a visitor sends a message from the website widget.</div>
<?php else: ?>

<div style="display:grid;grid-template-columns:320px 1fr;gap:18px;align-items:start;min-height:60vh">

  <!-- Conversation list (auto-refreshes) -->
  <div class="card card-flush" style="max-height:75vh;overflow-y:auto" id="convList" data-sel="<?php echo $sel; ?>">
    <?php if (!$conversations): ?>
      <div class="empty-state" style="padding:40px 16px"><i class="fas fa-comments"></i><p>No conversations yet.</p></div>
    <?php else: foreach ($conversations as $c): ?>
      <a href="?id=<?php echo $c['id']; ?>" style="display:block;padding:13px 16px;border-bottom:1px solid var(--surface-3);text-decoration:none;<?php echo $sel === (int)$c['id'] ? 'background:var(--green-light)' : ''; ?>">
        <div class="flex-between">
          <span class="fw-700" style="font-size:13.5px;color:var(--navy)">
            <?php echo h($c['name'] ?: 'Visitor #' . $c['id']); ?>
            <?php if ($c['unread']): ?><span class="badge badge-danger" style="margin-left:6px"><?php echo $c['unread']; ?></span><?php endif; ?>
          </span>
          <span style="font-size:11px;color:var(--text-muted)"><?php echo time_ago($c['updated_at']); ?></span>
        </div>
        <div style="font-size:12px;color:var(--text-muted);margin-top:3px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">
          <?php echo $c['status'] === 'closed' ? '<span class="badge badge-secondary" style="margin-right:5px">closed</span>' : ''; ?>
          <?php echo h(mb_strimwidth($c['last_msg'] ?? '', 0, 46, '…')); ?>
        </div>
      </a>
    <?php endforeach; endif; ?>
  </div>

  <!-- Thread -->
  <?php if (!$thread): ?>
    <div class="card"><div class="empty-state"><i class="fas fa-message"></i><p>Select a conversation to reply.</p></div></div>
  <?php else: ?>
    <div class="card" style="display:flex;flex-direction:column;max-height:75vh">
      <div class="card-header">
        <div>
          <span class="card-title"><?php echo h($thread['name'] ?: 'Visitor #' . $thread['id']); ?></span>
          <?php if ($thread['email']): ?><span class="text-muted" style="font-size:12px;margin-left:8px"><i class="fas fa-envelope"></i> <?php echo h($thread['email']); ?></span><?php endif; ?>
          <?php if ($thread['page']): ?><span class="code-chip" style="margin-left:8px;font-size:10.5px"><?php echo h(mb_strimwidth(preg_replace('#^https?://[^/]+#', '', $thread['page']), 0, 34, '…')); ?></span><?php endif; ?>
        </div>
        <form method="POST" style="margin:0">
          <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>" />
          <input type="hidden" name="conversation" value="<?php echo $thread['id']; ?>" />
          <input type="hidden" name="action" value="<?php echo $thread['status'] === 'open' ? 'close' : 'reopen'; ?>" />
          <button class="btn btn-ghost btn-sm"><?php echo $thread['status'] === 'open' ? '<i class="fas fa-check"></i> Close chat' : '<i class="fas fa-rotate-left"></i> Reopen'; ?></button>
        </form>
      </div>

      <div id="thread" style="flex:1;overflow-y:auto;padding:18px;display:flex;flex-direction:column;gap:10px;background:var(--surface-2)">
        <?php foreach ($messages as $m): $mine = $m['sender'] === 'admin'; ?>
          <div style="max-width:70%;<?php echo $mine ? 'align-self:flex-end' : ''; ?>">
            <div style="font-size:10.5px;color:var(--text-muted);margin:0 4px 2px;<?php echo $mine ? 'text-align:right' : ''; ?>">
              <?php echo h($m['sender_name'] ?: ($mine ? 'Admin' : 'Visitor')); ?> · <?php echo $m['t']; ?>
            </div>
            <div style="padding:9px 13px;border-radius:12px;font-size:13.5px;line-height:1.5;word-wrap:break-word;<?php
              echo $mine ? 'background:var(--green);color:#fff;border-bottom-right-radius:4px'
                         : 'background:#fff;border:1px solid var(--border);border-bottom-left-radius:4px'; ?>">
              <?php echo nl2br(h($m['message'])); ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>

      <form id="replyForm" style="display:flex;gap:8px;padding:14px;border-top:1px solid var(--border)">
        <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>" />
        <input type="hidden" name="conversation" value="<?php echo $thread['id']; ?>" />
        <input type="hidden" name="action" value="reply" />
        <input type="hidden" name="ajax" value="1" />
        <input type="text" name="message" id="replyText" class="form-control" placeholder="Type your reply…" autocomplete="off" autofocus />
        <button type="submit" class="btn btn-primary"><i class="fas fa-paper-plane"></i> Send</button>
      </form>
    </div>

    <script>
    (function () {
      var thread = document.getElementById('thread');
      var lastId = <?php echo $messages ? (int) end($messages)['id'] : 0; ?>;
      var adminName = <?php echo json_encode(current_admin()['name']); ?>;
      thread.scrollTop = thread.scrollHeight;

      function addMsg(m) {
        var mine = m.sender === 'admin';
        var wrap = document.createElement('div');
        wrap.style.cssText = 'max-width:70%;' + (mine ? 'align-self:flex-end' : '');
        var meta = document.createElement('div');
        meta.style.cssText = 'font-size:10.5px;color:var(--text-muted);margin:0 4px 2px;' + (mine ? 'text-align:right' : '');
        meta.textContent = (m.sender_name || (mine ? 'Admin' : 'Visitor')) + (m.t ? ' · ' + m.t : '');
        var bub = document.createElement('div');
        bub.style.cssText = 'padding:9px 13px;border-radius:12px;font-size:13.5px;line-height:1.5;word-wrap:break-word;' +
          (mine ? 'background:var(--green);color:#fff;border-bottom-right-radius:4px'
                : 'background:#fff;border:1px solid var(--border);border-bottom-left-radius:4px');
        bub.textContent = m.message;
        wrap.appendChild(meta); wrap.appendChild(bub);
        thread.appendChild(wrap);
        thread.scrollTop = thread.scrollHeight;
      }

      // Adaptive polling: quick while the tab is visible, relaxed when
      // hidden, and an immediate check the moment the tab regains focus —
      // replies show up without any refresh.
      var pollTimer = null;
      function pollThread() {
        fetch('?id=<?php echo $thread['id']; ?>&ajax=1&after=' + lastId)
          .then(function (r) { return r.json(); })
          .then(function (d) {
            if (!d.ok) return;
            d.messages.forEach(function (m) {
              lastId = Math.max(lastId, Number(m.id));
              if (m.sender === 'visitor') addMsg(m);
            });
          }).catch(function () {})
          .finally(scheduleThread);
      }
      function scheduleThread() {
        clearTimeout(pollTimer);
        pollTimer = setTimeout(pollThread, document.hidden ? 12000 : 2500);
      }
      document.addEventListener('visibilitychange', function () {
        if (!document.hidden) { clearTimeout(pollTimer); pollThread(); }
      });
      scheduleThread();

      document.getElementById('replyForm').addEventListener('submit', function (e) {
        e.preventDefault();
        var input = document.getElementById('replyText');
        var msg = input.value.trim();
        if (!msg) return;
        input.value = '';
        addMsg({ sender: 'admin', sender_name: adminName, message: msg, t: '' });
        var fd = new FormData(e.target);
        fd.set('message', msg);
        fetch('?id=<?php echo $thread['id']; ?>', { method: 'POST', body: fd })
          .then(function () { clearTimeout(pollTimer); pollThread(); })
          .catch(function () {});
      });
    })();
    </script>
  <?php endif; ?>
</div>

<script>
// ── Live inbox: refresh the conversation list + unread count without reload ──
(function () {
  var list = document.getElementById('convList');
  if (!list) return;
  var selId = Number(list.getAttribute('data-sel')) || 0;
  var baseTitle = document.title.replace(/^\(\d+\)\s*/, '');
  var timer = null;

  function render(convs) {
    if (!convs.length) return;
    list.innerHTML = '';
    convs.forEach(function (c) {
      var a = document.createElement('a');
      a.href = '?id=' + c.id;
      a.style.cssText = 'display:block;padding:13px 16px;border-bottom:1px solid var(--surface-3);text-decoration:none;' +
        (selId === c.id ? 'background:var(--green-light)' : '');
      var top = document.createElement('div');
      top.className = 'flex-between';
      var nm = document.createElement('span');
      nm.className = 'fw-700';
      nm.style.cssText = 'font-size:13.5px;color:var(--navy)';
      nm.textContent = c.name;
      if (c.unread) {
        var b = document.createElement('span');
        b.className = 'badge badge-danger';
        b.style.marginLeft = '6px';
        b.textContent = c.unread;
        nm.appendChild(b);
      }
      var tm = document.createElement('span');
      tm.style.cssText = 'font-size:11px;color:var(--text-muted)';
      tm.textContent = c.time;
      top.appendChild(nm); top.appendChild(tm);
      var sub = document.createElement('div');
      sub.style.cssText = 'font-size:12px;color:var(--text-muted);margin-top:3px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis';
      if (c.status === 'closed') {
        var cl = document.createElement('span');
        cl.className = 'badge badge-secondary';
        cl.style.marginRight = '5px';
        cl.textContent = 'closed';
        sub.appendChild(cl);
      }
      sub.appendChild(document.createTextNode(c.last));
      a.appendChild(top); a.appendChild(sub);
      list.appendChild(a);
    });
  }

  function refresh() {
    fetch('?ajax=list')
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d.ok) return;
        render(d.conversations);
        document.title = (d.unread > 0 ? '(' + d.unread + ') ' : '') + baseTitle;
      })
      .catch(function () {})
      .finally(function () {
        clearTimeout(timer);
        timer = setTimeout(refresh, document.hidden ? 20000 : 5000);
      });
  }
  document.addEventListener('visibilitychange', function () {
    if (!document.hidden) { clearTimeout(timer); refresh(); }
  });
  timer = setTimeout(refresh, 5000);
})();
</script>

<?php endif; ?>

<?php require_once '../includes/footer.php'; ?>
