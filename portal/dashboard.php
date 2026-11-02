<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once dirname(__DIR__) . '/admin/includes/functions.php';

portal_check();
$page_title = 'Dashboard';
$c   = current_client();
$cid = $c['id'];
$db  = db();

$cid = (int) $cid;

// Active services = legacy orders + provisioned services (client_services)
$active_orders = (int) $db->query("SELECT COUNT(*) FROM orders WHERE client_id=$cid AND status='active'")->fetchColumn();
$provisioned = [];
try {
    $stmt = $db->prepare('SELECT * FROM client_services WHERE client_id = ? ORDER BY status = "active" DESC, created_at DESC LIMIT 6');
    $stmt->execute([$cid]);
    $provisioned = $stmt->fetchAll();
    $active_orders += (int) $db->query("SELECT COUNT(*) FROM client_services WHERE client_id=$cid AND status='active'")->fetchColumn();
} catch (\Throwable $e) { /* client_services not migrated yet */ }

$domains_count = 0;
try {
    $domains_count = (int) $db->query("SELECT COUNT(*) FROM domain_registrations WHERE client_id=$cid AND status IN ('active','pending')")->fetchColumn();
} catch (\Throwable $e) { /* table missing */ }

// 'answered' is still open — it's waiting on the client.
$open_tickets    = (int) $db->query("SELECT COUNT(*) FROM tickets WHERE client_id=$cid AND status IN ('open','pending','answered')")->fetchColumn();
$unpaid_invoices = (int) $db->query("SELECT COUNT(*) FROM invoices WHERE client_id=$cid AND status IN ('sent','overdue')")->fetchColumn();
$total_spent     = (float)$db->query("SELECT COALESCE(SUM(total),0) FROM invoices WHERE client_id=$cid AND status='paid'")->fetchColumn();
$credit_balance  = 0.0;
try { $credit_balance = (float) $db->query("SELECT COALESCE(SUM(amount),0) FROM client_credits WHERE client_id=$cid")->fetchColumn(); } catch (\Throwable $e) {}

// Recent items
$recent_orders = $db->query("
    SELECT o.*, s.name svc_name FROM orders o
    LEFT JOIN services s ON s.id=o.service_id
    WHERE o.client_id=$cid ORDER BY o.created_at DESC LIMIT 4
")->fetchAll();

$recent_invoices = $db->query("
    SELECT * FROM invoices WHERE client_id=$cid ORDER BY created_at DESC LIMIT 4
")->fetchAll();

$recent_tickets = $db->query("
    SELECT * FROM tickets WHERE client_id=$cid ORDER BY updated_at DESC LIMIT 4
")->fetchAll();

// Next due orders
$due_soon = $db->query("
    SELECT o.*, s.name svc_name FROM orders o
    LEFT JOIN services s ON s.id=o.service_id
    WHERE o.client_id=$cid AND o.status='active' AND o.next_due <= DATE_ADD(NOW(), INTERVAL 7 DAY)
    ORDER BY o.next_due ASC LIMIT 3
")->fetchAll();

// ── Needs your attention ─────────────────────────────────────
// What a client should act on, worked out up front so the dashboard can
// lead with it instead of leaving it buried in table badges.

// Money owed: each unpaid invoice less anything already paid against it
// (a part payment), totalled per currency.
$owed_by_cur = []; $overdue = []; $first_unpaid = null;
foreach ($db->query("SELECT i.id, i.invoice_number, i.due_date, i.status, COALESCE(NULLIF(i.currency,''),'USD') cur,
                            i.total - COALESCE((SELECT SUM(p.amount) FROM payments p WHERE p.invoice_id = i.id AND p.status = 'completed'), 0) owed
                     FROM invoices i WHERE i.client_id = $cid AND i.status IN ('sent','overdue') ORDER BY i.due_date ASC")->fetchAll() as $r) {
    if ((float) $r['owed'] < 0.01) continue;
    $owed_by_cur[$r['cur']] = ($owed_by_cur[$r['cur']] ?? 0) + (float) $r['owed'];
    $first_unpaid = $first_unpaid ?? $r;
    if ($r['status'] === 'overdue' || ($r['due_date'] && $r['due_date'] < date('Y-m-d'))) $overdue[] = $r;
}

// Domains about to lapse. Every one needs the client to renew it: there
// is no automatic domain renewal billing yet, whatever auto_renew says.
$expiring_domains = [];
try {
    $expiring_domains = $db->query("SELECT id, domain_name, expiry_date FROM domain_registrations
                                    WHERE client_id = $cid AND status = 'active'
                                      AND expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
                                    ORDER BY expiry_date")->fetchAll();
} catch (\Throwable $e) {}

// Tickets where support has replied and is waiting on the client.
$awaiting_reply = $db->query("SELECT id, ticket_number, subject FROM tickets WHERE client_id = $cid AND status = 'answered' ORDER BY updated_at DESC")->fetchAll();

// Suspended services (usually an unpaid renewal).
$suspended = (int) $db->query("SELECT COUNT(*) FROM orders WHERE client_id = $cid AND status = 'suspended'")->fetchColumn();
try { $suspended += (int) $db->query("SELECT COUNT(*) FROM client_services WHERE client_id = $cid AND status = 'suspended'")->fetchColumn(); } catch (\Throwable $e) {}

// Side marketing banners (hero carousel renders via portal_render_banners())
$side_banners = [];
try {
    $side_banners = $db->query("SELECT * FROM portal_banners WHERE is_active = 1 AND placement = 'side' ORDER BY sort_order, id")->fetchAll();
} catch (\Throwable $e) { /* none configured */ }
$site_base = preg_replace('#/portal/?$#', '', PORTAL_URL);
$banner_img = function (?string $u) use ($site_base): string {
    if (!$u) return '';
    return preg_match('#^https?://#i', $u) ? $u : $site_base . '/' . ltrim($u, '/');
};

require_once __DIR__ . '/includes/header.php';
?>

<div class="page-hero">
  <div class="container">
    <div>
      <?php
      // First name only — "Welcome back, Grace" reads like a person wrote it;
      // the full legal name on the account does not.
      $_first = trim(explode(' ', trim($c['name']))[0]) ?: 'there';
      $_hour  = (int) date('G');
      $_greet = $_hour < 12 ? 'Good morning' : ($_hour < 18 ? 'Good afternoon' : 'Good evening');
      ?>
      <h1><?php echo $_greet; ?>, <?php echo htmlspecialchars($_first); ?></h1>
      <p>Here's where your account stands today</p>
      <div class="dash-hero-meta">
        <span><i class="fas fa-calendar-day"></i> <?php echo date('l, j F Y'); ?></span>
        <?php if ($active_orders): ?>
          <span><i class="fas fa-circle-check"></i> <?php echo (int) $active_orders; ?> active service<?php echo $active_orders == 1 ? '' : 's'; ?></span>
        <?php endif; ?>
        <?php if ($unpaid_invoices): ?>
          <span><i class="fas fa-file-invoice-dollar"></i> <?php echo (int) $unpaid_invoices; ?> invoice<?php echo $unpaid_invoices == 1 ? '' : 's'; ?> awaiting payment</span>
        <?php endif; ?>
      </div>
    </div>
    <a href="<?php echo PORTAL_URL; ?>/tickets/add.php" class="btn btn-white">
      <i class="fas fa-plus"></i> Open Support Ticket
    </a>
  </div>
</div>

<div class="page-body">
<div class="container">

  <?php portal_render_banners(); ?>

  <?php if ($owed_by_cur || $expiring_domains || $awaiting_reply || $suspended): ?>
  <div class="attn" aria-label="Needs your attention">
    <?php if ($owed_by_cur): ?>
      <div class="attn-item <?php echo $overdue ? 'danger' : 'warn'; ?>">
        <span class="attn-ico"><i class="fas <?php echo $overdue ? 'fa-triangle-exclamation' : 'fa-file-invoice-dollar'; ?>"></i></span>
        <div class="attn-body">
          <div class="attn-title">
            <?php echo implode(' + ', array_map(fn($cur, $amt) => htmlspecialchars(format_money($amt, $cur)), array_keys($owed_by_cur), $owed_by_cur)); ?> due
          </div>
          <div class="attn-text">
            <?php if ($overdue): ?>
              <?php echo count($overdue); ?> invoice<?php echo count($overdue) > 1 ? 's are' : ' is'; ?> overdue — pay now to avoid your service being suspended.
            <?php else: ?>
              <?php echo $unpaid_invoices; ?> invoice<?php echo $unpaid_invoices > 1 ? 's' : ''; ?> awaiting payment<?php echo $first_unpaid['due_date'] ? ', next due ' . format_date($first_unpaid['due_date']) : ''; ?>.
            <?php endif; ?>
          </div>
        </div>
        <a href="<?php echo PORTAL_URL; ?>/<?php echo count($owed_by_cur) === 1 && $unpaid_invoices === 1 ? 'invoices/view.php?id=' . (int) $first_unpaid['id'] . '#pay' : 'invoices/index.php?filter=unpaid'; ?>" class="btn btn-primary btn-sm attn-cta">
          <i class="fas fa-lock"></i> Pay now
        </a>
      </div>
    <?php endif; ?>

    <?php if ($suspended): ?>
      <div class="attn-item danger">
        <span class="attn-ico"><i class="fas fa-circle-pause"></i></span>
        <div class="attn-body">
          <div class="attn-title"><?php echo $suspended; ?> service<?php echo $suspended > 1 ? 's' : ''; ?> suspended</div>
          <div class="attn-text">Settle the outstanding invoice and it's switched back on automatically.</div>
        </div>
        <a href="<?php echo PORTAL_URL; ?>/services.php" class="btn btn-ghost btn-sm attn-cta">View services</a>
      </div>
    <?php endif; ?>

    <?php foreach ($expiring_domains as $d):
      $days = max(0, (int) floor((strtotime($d['expiry_date']) - strtotime(date('Y-m-d'))) / 86400)); ?>
      <div class="attn-item warn">
        <span class="attn-ico"><i class="fas fa-globe"></i></span>
        <div class="attn-body">
          <div class="attn-title"><?php echo htmlspecialchars($d['domain_name']); ?> expires in <?php echo $days; ?> day<?php echo $days === 1 ? '' : 's'; ?></div>
          <div class="attn-text">Renew it before <?php echo format_date($d['expiry_date']); ?> so your website and email keep working.</div>
        </div>
        <a href="<?php echo PORTAL_URL; ?>/domain-renew.php?id=<?php echo (int) $d['id']; ?>" class="btn btn-primary btn-sm attn-cta"><i class="fas fa-rotate"></i> Renew</a>
      </div>
    <?php endforeach; ?>

    <?php if ($awaiting_reply): $t = $awaiting_reply[0]; ?>
      <div class="attn-item info">
        <span class="attn-ico"><i class="fas fa-comment-dots"></i></span>
        <div class="attn-body">
          <div class="attn-title">Support replied<?php echo count($awaiting_reply) > 1 ? ' on ' . count($awaiting_reply) . ' tickets' : ''; ?></div>
          <div class="attn-text"><?php echo htmlspecialchars($t['ticket_number'] . ' — ' . $t['subject']); ?></div>
        </div>
        <a href="<?php echo PORTAL_URL; ?>/tickets/view.php?id=<?php echo (int) $t['id']; ?>" class="btn btn-ghost btn-sm attn-cta">View reply</a>
      </div>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <?php if ($due_soon): ?>
  <div class="p-alert p-alert-info" style="margin-bottom:20px">
    <i class="fas fa-calendar-alt"></i>
    <strong><?php echo count($due_soon); ?> service<?php echo count($due_soon)>1?'s':''; ?> renewing within 7 days</strong> —
    <?php echo implode(', ', array_column($due_soon, 'svc_name')); ?>
  </div>
  <?php endif; ?>

  <?php if ($credit_balance > 0): ?>
  <div class="p-alert p-alert-success" style="margin-bottom:20px">
    <i class="fas fa-wallet"></i>
    <strong>Account credit: <?php echo format_money($credit_balance); ?></strong> — apply it toward any unpaid invoice at checkout.
  </div>
  <?php endif; ?>

  <!-- Stats -->
  <div class="p-stat-grid">
    <a href="<?php echo PORTAL_URL; ?>/services.php" class="p-stat">
      <div class="p-stat-icon green"><i class="fas fa-box"></i></div>
      <div><div class="p-stat-label">Active Services</div><div class="p-stat-value"><?php echo $active_orders; ?></div></div>
    </a>
    <a href="<?php echo PORTAL_URL; ?>/invoices/" class="p-stat">
      <div class="p-stat-icon <?php echo $unpaid_invoices ? 'orange' : 'navy'; ?>"><i class="fas fa-file-invoice"></i></div>
      <div>
        <div class="p-stat-label">Unpaid Invoices</div>
        <div class="p-stat-value"><?php echo $unpaid_invoices; ?></div>
      </div>
    </a>
    <a href="<?php echo PORTAL_URL; ?>/tickets/" class="p-stat">
      <div class="p-stat-icon <?php echo $open_tickets ? 'orange' : 'navy'; ?>"><i class="fas fa-comments"></i></div>
      <div><div class="p-stat-label">Open Tickets</div><div class="p-stat-value"><?php echo $open_tickets; ?></div></div>
    </a>
    <a href="<?php echo PORTAL_URL; ?>/domains.php" class="p-stat">
      <div class="p-stat-icon navy"><i class="fas fa-globe"></i></div>
      <div><div class="p-stat-label">Domains</div><div class="p-stat-value"><?php echo $domains_count; ?></div></div>
    </a>
  </div>

  <div class="dash-two">

    <!-- Services -->
    <div class="p-table-wrap">
      <div class="p-table-head">
        <div class="p-table-title">Active Services</div>
        <a href="<?php echo PORTAL_URL; ?>/services.php" class="btn btn-ghost btn-sm">View All</a>
      </div>
      <table>
        <thead><tr><th>Service</th><th>Domain</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($provisioned as $svc):
            $cp = ($svc['provider_key'] ?? '') === 'whm' ? ($svc['username'] ?: $svc['remote_id']) : null; ?>
          <tr>
            <td><strong><?php echo htmlspecialchars($svc['label']); ?></strong></td>
            <td style="font-size:12.5px"><?php echo htmlspecialchars($svc['domain'] ?: '—'); ?></td>
            <td><?php echo badge($svc['status']); ?></td>
            <td style="text-align:right">
              <?php if ($cp && $svc['status'] === 'active'): ?>
                <a href="<?php echo PORTAL_URL; ?>/cpanel-sso.php?user=<?php echo urlencode($cp); ?>" class="btn btn-primary btn-sm" target="_blank" rel="noopener"><i class="fas fa-right-to-bracket"></i> cPanel</a>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if ($recent_orders): foreach ($recent_orders as $o): ?>
          <tr>
            <td><strong><?php echo htmlspecialchars($o['svc_name'] ?? $o['service_name'] ?? '—'); ?></strong></td>
            <td style="font-size:12.5px"><?php echo htmlspecialchars($o['domain_name'] ?? $o['domain'] ?? '—'); ?></td>
            <td><?php echo badge($o['status']); ?></td>
            <td style="text-align:right;font-size:12px;color:var(--text-muted)"><?php echo format_date($o['next_due']); ?></td>
          </tr>
        <?php endforeach; endif; ?>
        <?php if (!$provisioned && !$recent_orders): ?>
          <tr><td colspan="4"><div class="empty-state"><i class="fas fa-box"></i><p>No active services yet.</p></div></td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>

    <!-- Invoices -->
    <div class="p-table-wrap">
      <div class="p-table-head">
        <div class="p-table-title">Recent Invoices</div>
        <a href="<?php echo PORTAL_URL; ?>/invoices/" class="btn btn-ghost btn-sm">View All</a>
      </div>
      <table>
        <thead><tr><th>Invoice #</th><th>Amount</th><th>Due</th><th>Status</th></tr></thead>
        <tbody>
        <?php if ($recent_invoices): foreach ($recent_invoices as $inv): ?>
          <tr>
            <td><a href="<?php echo PORTAL_URL; ?>/invoices/view.php?id=<?php echo $inv['id']; ?>" style="color:var(--text);font-weight:600"><?php echo htmlspecialchars($inv['invoice_number']); ?></a></td>
            <td><?php echo format_money($inv['total'], $inv['currency'] ?? null); ?></td>
            <td><?php echo format_date($inv['due_date']); ?></td>
            <td style="white-space:nowrap"><?php echo badge($inv['status']); ?>
              <?php if (in_array($inv['status'], ['sent', 'overdue'], true)): ?>
                <a href="<?php echo PORTAL_URL; ?>/invoices/view.php?id=<?php echo (int) $inv['id']; ?>#pay" style="margin-left:6px;font-size:12px;font-weight:700;color:var(--green)">Pay</a>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; else: ?>
          <tr><td colspan="4"><div class="empty-state"><i class="fas fa-file-invoice"></i><p>No invoices yet.</p></div></td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>

  </div>

  <div style="margin-top:20px;display:grid;grid-template-columns:<?php echo $side_banners ? '1fr 280px' : '1fr'; ?>;gap:20px;align-items:start" class="bn-bottom-grid">
    <!-- Support tickets -->
    <div class="p-table-wrap">
      <div class="p-table-head">
        <div class="p-table-title">Recent Support Tickets</div>
        <div style="display:flex;gap:8px">
          <a href="<?php echo PORTAL_URL; ?>/tickets/add.php" class="btn btn-primary btn-sm"><i class="fas fa-plus"></i> New Ticket</a>
        <a href="<?php echo PORTAL_URL; ?>/tickets/" class="btn btn-ghost btn-sm">View All</a>
        </div>
      </div>
      <table>
        <thead><tr><th>Ticket #</th><th>Subject</th><th>Priority</th><th>Status</th><th>Updated</th></tr></thead>
        <tbody>
        <?php if ($recent_tickets): foreach ($recent_tickets as $t): ?>
          <tr>
            <td><a href="<?php echo PORTAL_URL; ?>/tickets/view.php?id=<?php echo $t['id']; ?>" style="color:var(--text);font-weight:700;font-size:12px"><?php echo htmlspecialchars($t['ticket_number']); ?></a></td>
            <td><a href="<?php echo PORTAL_URL; ?>/tickets/view.php?id=<?php echo $t['id']; ?>" style="color:var(--text)"><?php echo htmlspecialchars(mb_strimwidth($t['subject'],0,48,'…')); ?></a></td>
            <td><?php echo badge($t['priority']); ?></td>
            <td><?php echo badge($t['status']); ?></td>
            <td><?php echo time_ago($t['updated_at']); ?></td>
          </tr>
        <?php endforeach; else: ?>
          <tr><td colspan="5"><div class="empty-state"><i class="fas fa-comments"></i><h3>All quiet!</h3><p>No support tickets yet.</p></div></td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>

    <?php if ($side_banners): ?>
    <!-- Side banner carousel -->
    <div class="bn-side" id="bnSide">
      <?php foreach ($side_banners as $i => $b): ?>
        <a class="bn-side-slide<?php echo $i === 0 ? ' on' : ''; ?>" href="<?php echo htmlspecialchars($b['link_url'] ?: '#'); ?>"
           style="background:<?php echo htmlspecialchars($b['bg_color'] ?: 'var(--navy)'); ?><?php
             echo $b['image_url'] ? ' url(' . htmlspecialchars($banner_img($b['image_url'])) . ') center/cover no-repeat' : ''; ?>">
          <span class="bn-side-body">
            <span class="bn-side-title"><?php echo htmlspecialchars($b['title']); ?></span>
            <?php if ($b['subtitle']): ?><span class="bn-side-sub"><?php echo htmlspecialchars($b['subtitle']); ?></span><?php endif; ?>
            <?php if ($b['link_label']): ?><span class="bn-side-cta"><?php echo htmlspecialchars($b['link_label']); ?> <i class="fas fa-arrow-right" style="font-size:10px"></i></span><?php endif; ?>
          </span>
        </a>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>

</div>
</div>

<style>
.bn-side { position:relative; border-radius:12px; overflow:hidden; height:200px; box-shadow:0 4px 14px rgba(11,36,71,.10); }
.bn-side-slide { position:absolute; inset:0; opacity:0; transition:opacity .6s; display:flex; align-items:flex-end; text-decoration:none; }
.bn-side-slide.on { opacity:1; z-index:1; }
.bn-side-body { display:block; width:100%; padding:14px 16px; background:linear-gradient(transparent, rgba(4,14,30,.78)); }
.bn-side-title { display:block; color:#fff; font-size:14px; font-weight:800; line-height:1.3; }
.bn-side-sub { display:block; color:rgba(255,255,255,.82); font-size:11.5px; margin-top:3px; }
.bn-side-cta { display:inline-flex; align-items:center; gap:5px; color:#fff; font-size:11.5px; font-weight:700; margin-top:8px; border-bottom:1px solid rgba(255,255,255,.5); padding-bottom:1px; }
@media (max-width: 860px) { .bn-bottom-grid { grid-template-columns: 1fr !important; } }
</style>
<script>
(function () {
  var root = document.getElementById('bnSide');
  if (!root) return;
  var slides = root.querySelectorAll('.bn-side-slide');
  if (slides.length < 2) return;
  var cur = 0;
  setInterval(function () {
    slides[cur].classList.remove('on');
    cur = (cur + 1) % slides.length;
    slides[cur].classList.add('on');
  }, 5000);
})();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
