<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once dirname(__DIR__) . '/admin/includes/functions.php';

portal_check();
$page_title = 'My Services';
$cid = current_client()['id'];

$orders = db()->query("
    SELECT o.*, s.name svc_name, s.category,
           w.cpanel_user, w.status AS whm_status, w.disk_used_mb, w.disk_limit_mb
    FROM orders o
    LEFT JOIN services s ON s.id = o.service_id
    LEFT JOIN whm_accounts w ON w.order_id = o.id
    WHERE o.client_id = $cid
    ORDER BY o.status = 'active' DESC, o.created_at DESC
")->fetchAll();

require_once __DIR__ . '/includes/header.php';
?>

<div class="page-hero">
  <div class="container">
    <div>
      <h1>My Services</h1>
      <p>All your hosting services and subscriptions</p>
    </div>
    <a href="<?php echo PORTAL_URL; ?>/tickets/add.php?subject=Upgrade+my+service" class="btn btn-white">
      <i class="fas fa-arrow-up"></i> Upgrade Service
    </a>
  </div>
</div>

<div class="page-body">
<div class="container">

<?php if ($orders): foreach ($orders as $o): ?>

  <div class="p-card" style="margin-bottom:16px">
    <div style="padding:20px 24px;display:flex;align-items:flex-start;justify-content:space-between;gap:16px;flex-wrap:wrap">
      <div style="display:flex;align-items:center;gap:14px">
        <div style="width:44px;height:44px;background:var(--green-light);border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:18px;color:var(--green)">
          <i class="fas <?php
            $icons = ['shared'=>'fa-cloud','vps'=>'fa-server','dedicated'=>'fa-hdd','cloud'=>'fa-cloud-upload-alt','wordpress'=>'fa-wordpress','reseller'=>'fa-users','ssl'=>'fa-lock','email'=>'fa-envelope','domain'=>'fa-globe'];
            echo $icons[$o['category'] ?? ''] ?? 'fa-box';
          ?>"></i>
        </div>
        <div>
          <div style="font-size:16px;font-weight:700;color:var(--navy)"><?php echo htmlspecialchars($o['svc_name'] ?? $o['service_name'] ?? 'Custom Service'); ?></div>
          <?php if ($o['domain']): ?>
            <div style="font-size:13px;color:var(--text-muted);margin-top:2px"><i class="fas fa-globe" style="font-size:11px"></i> <?php echo htmlspecialchars($o['domain']); ?></div>
          <?php endif; ?>
        </div>
      </div>
      <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
        <?php echo badge($o['status']); ?>
        <?php if ($o['cpanel_user']): ?>
          <span class="badge badge-primary"><i class="fas fa-server"></i> cPanel: <?php echo htmlspecialchars($o['cpanel_user']); ?></span>
        <?php endif; ?>
        <a href="<?php echo PORTAL_URL; ?>/tickets/add.php?subject=Help+with+<?php echo urlencode($o['svc_name'] ?? 'service'); ?>" class="btn btn-ghost btn-sm">
          <i class="fas fa-life-ring"></i> Get Help
        </a>
      </div>
    </div>

    <div style="border-top:1px solid var(--border);padding:16px 24px;display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px">
      <div>
        <div style="font-size:11px;color:var(--text-muted);font-weight:600;text-transform:uppercase;letter-spacing:.4px;margin-bottom:3px">Amount</div>
        <div style="font-weight:700;color:var(--navy)"><?php echo format_money($o['amount']); ?> / <?php echo str_replace('_',' ',$o['billing_cycle']); ?></div>
      </div>
      <div>
        <div style="font-size:11px;color:var(--text-muted);font-weight:600;text-transform:uppercase;letter-spacing:.4px;margin-bottom:3px">Start Date</div>
        <div><?php echo format_date($o['start_date']); ?></div>
      </div>
      <div>
        <div style="font-size:11px;color:var(--text-muted);font-weight:600;text-transform:uppercase;letter-spacing:.4px;margin-bottom:3px">Next Renewal</div>
        <div style="<?php echo $o['next_due'] && $o['next_due'] <= date('Y-m-d', strtotime('+7 days')) ? 'color:var(--danger);font-weight:600' : ''; ?>">
          <?php echo format_date($o['next_due']); ?>
        </div>
      </div>
      <?php if ($o['cpanel_user'] && $o['disk_limit_mb']): ?>
      <div>
        <div style="font-size:11px;color:var(--text-muted);font-weight:600;text-transform:uppercase;letter-spacing:.4px;margin-bottom:3px">Disk Usage</div>
        <div>
          <?php
          $pct = $o['disk_limit_mb'] ? round($o['disk_used_mb'] / $o['disk_limit_mb'] * 100) : 0;
          echo $o['disk_used_mb'] . ' MB / ' . $o['disk_limit_mb'] . ' MB';
          ?>
          <div style="height:4px;background:#f1f5f9;border-radius:2px;margin-top:5px">
            <div style="height:100%;width:<?php echo $pct; ?>%;background:<?php echo $pct>85?'var(--danger)':'var(--green)'; ?>;border-radius:2px"></div>
          </div>
        </div>
      </div>
      <?php endif; ?>
    </div>
  </div>

<?php endforeach; else: ?>
  <div class="p-card">
    <div class="empty-state" style="padding:60px">
      <i class="fas fa-box-open"></i>
      <h3>No services yet</h3>
      <p>Browse our hosting plans to get started.</p>
      <a href="../hosting/shared.html" class="btn btn-primary" style="margin-top:16px"><i class="fas fa-rocket"></i> View Hosting Plans</a>
    </div>
  </div>
<?php endif; ?>

</div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
