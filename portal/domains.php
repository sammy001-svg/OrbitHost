<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once dirname(__DIR__) . '/admin/includes/functions.php';

portal_check();
$page_title = 'My Domains';
$cid = (int) current_client()['id'];

$stmt = db()->prepare('SELECT * FROM domain_registrations WHERE client_id = ? ORDER BY status = "active" DESC, expiry_date ASC');
$stmt->execute([$cid]);
$domains = $stmt->fetchAll();

require_once __DIR__ . '/includes/header.php';
?>

<div class="page-hero">
  <div class="container">
    <div>
      <h1>My Domains</h1>
      <p>Domain names registered with OrbitHost</p>
    </div>
    <a href="../domains.html" class="btn btn-white"><i class="fas fa-plus"></i> Register a Domain</a>
  </div>
</div>

<div class="page-body">
<div class="container">

<?php if ($domains): foreach ($domains as $d):
    $days_left = $d['expiry_date'] ? (int) ceil((strtotime($d['expiry_date']) - time()) / 86400) : null;
    $ns = json_decode($d['nameservers'] ?? '[]', true) ?: [];
?>
  <div class="p-card" style="margin-bottom:16px">
    <div style="padding:20px 24px;display:flex;align-items:flex-start;justify-content:space-between;gap:16px;flex-wrap:wrap">
      <div style="display:flex;align-items:center;gap:14px">
        <div style="width:44px;height:44px;background:var(--green-light);border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:18px;color:var(--green)">
          <i class="fas fa-globe"></i>
        </div>
        <div>
          <div style="font-size:16px;font-weight:700;color:var(--navy);font-family:ui-monospace,Menlo,monospace"><?php echo htmlspecialchars($d['domain_name']); ?></div>
          <div style="font-size:12.5px;color:var(--text-muted);margin-top:2px">
            Registered <?php echo format_date($d['registration_date']); ?>
          </div>
        </div>
      </div>
      <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
        <?php echo badge($d['status']); ?>
        <?php if ($d['auto_renew']): ?><span class="badge badge-success"><i class="fas fa-rotate"></i> Auto-renew</span><?php endif; ?>
        <a href="<?php echo PORTAL_URL; ?>/tickets/add.php?subject=<?php echo urlencode('Domain help: ' . $d['domain_name']); ?>" class="btn btn-ghost btn-sm">
          <i class="fas fa-life-ring"></i> Get Help
        </a>
      </div>
    </div>

    <div style="border-top:1px solid var(--border);padding:16px 24px;display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px">
      <div>
        <div style="font-size:11px;color:var(--text-muted);font-weight:600;text-transform:uppercase;letter-spacing:.4px;margin-bottom:3px">Expires</div>
        <div style="font-weight:700;color:<?php echo ($days_left !== null && $days_left <= 30) ? 'var(--danger)' : 'var(--navy)'; ?>">
          <?php echo format_date($d['expiry_date']); ?>
          <?php if ($days_left !== null): ?><span style="font-weight:500;font-size:12px;color:var(--text-muted)">(<?php echo max(0, $days_left); ?> days)</span><?php endif; ?>
        </div>
      </div>
      <div>
        <div style="font-size:11px;color:var(--text-muted);font-weight:600;text-transform:uppercase;letter-spacing:.4px;margin-bottom:3px">Status</div>
        <div style="font-weight:700;color:var(--navy)"><?php echo ucfirst($d['status']); ?></div>
      </div>
      <div>
        <div style="font-size:11px;color:var(--text-muted);font-weight:600;text-transform:uppercase;letter-spacing:.4px;margin-bottom:3px">Nameservers</div>
        <div style="font-size:12.5px;font-family:ui-monospace,Menlo,monospace;color:var(--navy)">
          <?php echo $ns ? htmlspecialchars(implode('<br>', array_slice($ns, 0, 2)) ?: '') : '<span style="color:var(--text-muted)">Default</span>'; ?>
        </div>
      </div>
    </div>
  </div>
<?php endforeach; else: ?>

  <div class="p-card" style="text-align:center;padding:56px 24px">
    <i class="fas fa-globe" style="font-size:42px;opacity:.2;display:block;margin-bottom:14px"></i>
    <h3 style="font-size:16px;margin-bottom:6px">No domains yet</h3>
    <p style="color:var(--text-muted);font-size:13.5px;margin-bottom:18px">Search for your perfect domain name and register it in minutes.</p>
    <a href="../domains.html" class="btn btn-primary"><i class="fas fa-magnifying-glass"></i> Find a Domain</a>
  </div>

<?php endif; ?>

</div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
