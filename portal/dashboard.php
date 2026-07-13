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

$open_tickets    = (int) $db->query("SELECT COUNT(*) FROM tickets WHERE client_id=$cid AND status IN ('open','pending')")->fetchColumn();
$unpaid_invoices = (int) $db->query("SELECT COUNT(*) FROM invoices WHERE client_id=$cid AND status IN ('sent','overdue')")->fetchColumn();
$total_spent     = (float)$db->query("SELECT COALESCE(SUM(total),0) FROM invoices WHERE client_id=$cid AND status='paid'")->fetchColumn();

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

// Marketing banners (admin-managed; table may not exist yet)
$hero_banners = $side_banners = [];
try {
    foreach ($db->query("SELECT * FROM portal_banners WHERE is_active = 1 ORDER BY sort_order, id")->fetchAll() as $b) {
        if ($b['placement'] === 'side') $side_banners[] = $b; else $hero_banners[] = $b;
    }
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
      <h1>Welcome back, <?php echo htmlspecialchars($c['name']); ?> 👋</h1>
      <p>Here's an overview of your account</p>
    </div>
    <a href="<?php echo PORTAL_URL; ?>/tickets/add.php" class="btn btn-white">
      <i class="fas fa-plus"></i> Open Support Ticket
    </a>
  </div>
</div>

<div class="page-body">
<div class="container">

  <?php if ($hero_banners): ?>
  <!-- Marketing hero carousel -->
  <div class="bn-hero" id="bnHero">
    <?php foreach ($hero_banners as $i => $b): ?>
      <div class="bn-slide<?php echo $i === 0 ? ' on' : ''; ?>"
           style="background:<?php echo htmlspecialchars($b['bg_color'] ?: 'var(--navy)'); ?><?php
             echo $b['image_url'] ? ' url(' . htmlspecialchars($banner_img($b['image_url'])) . ') center/cover no-repeat' : ''; ?>">
        <div class="bn-slide-inner">
          <div class="bn-title"><?php echo htmlspecialchars($b['title']); ?></div>
          <?php if ($b['subtitle']): ?><div class="bn-sub"><?php echo htmlspecialchars($b['subtitle']); ?></div><?php endif; ?>
          <?php if ($b['link_url']): ?>
            <a href="<?php echo htmlspecialchars($b['link_url']); ?>" class="btn btn-white btn-sm" style="margin-top:12px;display:inline-flex">
              <?php echo htmlspecialchars($b['link_label'] ?: 'Learn more'); ?> <i class="fas fa-arrow-right" style="font-size:11px"></i>
            </a>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
    <?php if (count($hero_banners) > 1): ?>
      <div class="bn-dots">
        <?php foreach ($hero_banners as $i => $b): ?><button type="button" class="bn-dot<?php echo $i === 0 ? ' on' : ''; ?>" data-slide="<?php echo $i; ?>" aria-label="Banner <?php echo $i + 1; ?>"></button><?php endforeach; ?>
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

  <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px">

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
            <td><a href="<?php echo PORTAL_URL; ?>/invoices/view.php?id=<?php echo $inv['id']; ?>" style="color:var(--navy);font-weight:600"><?php echo htmlspecialchars($inv['invoice_number']); ?></a></td>
            <td><?php echo format_money($inv['total']); ?></td>
            <td><?php echo format_date($inv['due_date']); ?></td>
            <td><?php echo badge($inv['status']); ?></td>
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
            <td><a href="<?php echo PORTAL_URL; ?>/tickets/view.php?id=<?php echo $t['id']; ?>" style="color:var(--navy);font-weight:700;font-size:12px"><?php echo htmlspecialchars($t['ticket_number']); ?></a></td>
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
.bn-hero { position:relative; border-radius:14px; overflow:hidden; margin-bottom:20px; height:180px; box-shadow:0 6px 20px rgba(11,36,71,.12); }
.bn-slide { position:absolute; inset:0; opacity:0; transition:opacity .6s; display:flex; align-items:center; }
.bn-slide.on { opacity:1; z-index:1; }
.bn-slide-inner { padding:28px 34px; max-width:640px; background:linear-gradient(90deg, rgba(4,14,30,.62) 0%, rgba(4,14,30,.25) 70%, transparent 100%); height:100%; display:flex; flex-direction:column; justify-content:center; align-items:flex-start; }
.bn-title { color:#fff; font-size:21px; font-weight:800; line-height:1.25; text-shadow:0 1px 4px rgba(0,0,0,.3); }
.bn-sub { color:rgba(255,255,255,.85); font-size:13.5px; margin-top:6px; text-shadow:0 1px 3px rgba(0,0,0,.3); }
.bn-dots { position:absolute; bottom:10px; right:16px; display:flex; gap:6px; z-index:2; }
.bn-dot { width:9px; height:9px; border-radius:50%; border:none; background:rgba(255,255,255,.45); cursor:pointer; padding:0; }
.bn-dot.on { background:#fff; }
.bn-side { position:relative; border-radius:12px; overflow:hidden; height:200px; box-shadow:0 4px 14px rgba(11,36,71,.10); }
.bn-side-slide { position:absolute; inset:0; opacity:0; transition:opacity .6s; display:flex; align-items:flex-end; text-decoration:none; }
.bn-side-slide.on { opacity:1; z-index:1; }
.bn-side-body { display:block; width:100%; padding:14px 16px; background:linear-gradient(transparent, rgba(4,14,30,.78)); }
.bn-side-title { display:block; color:#fff; font-size:14px; font-weight:800; line-height:1.3; }
.bn-side-sub { display:block; color:rgba(255,255,255,.82); font-size:11.5px; margin-top:3px; }
.bn-side-cta { display:inline-flex; align-items:center; gap:5px; color:#fff; font-size:11.5px; font-weight:700; margin-top:8px; border-bottom:1px solid rgba(255,255,255,.5); padding-bottom:1px; }
@media (max-width: 860px) { .bn-bottom-grid { grid-template-columns: 1fr !important; } .bn-hero { height:150px; } .bn-title { font-size:17px; } }
</style>
<script>
(function () {
  function rotate(rootId, slideSel, dotSel, ms) {
    var root = document.getElementById(rootId);
    if (!root) return;
    var slides = root.querySelectorAll(slideSel);
    if (slides.length < 2) return;
    var dots = dotSel ? root.querySelectorAll(dotSel) : [];
    var cur = 0, timer;
    function show(i) {
      slides[cur].classList.remove('on');
      if (dots[cur]) dots[cur].classList.remove('on');
      cur = (i + slides.length) % slides.length;
      slides[cur].classList.add('on');
      if (dots[cur]) dots[cur].classList.add('on');
    }
    function start() { timer = setInterval(function () { show(cur + 1); }, ms); }
    dots.forEach(function (d, i) {
      d.addEventListener('click', function () { clearInterval(timer); show(i); start(); });
    });
    start();
  }
  rotate('bnHero', '.bn-slide', '.bn-dot', 6000);
  rotate('bnSide', '.bn-side-slide', null, 5000);
})();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
