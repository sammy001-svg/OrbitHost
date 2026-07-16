<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once dirname(__DIR__) . '/admin/includes/functions.php';
require_once dirname(__DIR__) . '/admin/includes/DomainClient.php';

portal_check();
$page_title = 'My Domains';
$cid = (int) current_client()['id'];

// ── Update nameservers ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'set_nameservers') {
    portal_csrf_verify();
    $id   = (int) ($_POST['id'] ?? 0);
    $stmt = db()->prepare('SELECT * FROM domain_registrations WHERE id = ? AND client_id = ?');
    $stmt->execute([$id, $cid]);
    $dom = $stmt->fetch();

    if (!$dom) {
        portal_flash_set('error', 'Domain not found.');
    } elseif ($dom['status'] !== 'active') {
        portal_flash_set('error', 'Nameservers can only be changed for active domains.');
    } else {
        $ns = array_values(array_filter(array_map('trim', explode("\n", $_POST['nameservers'] ?? ''))));
        $host_re = '/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)+$/i';

        if (count($ns) < 2) {
            portal_flash_set('error', 'Enter at least 2 nameservers.');
        } elseif (count($ns) > 12) {
            portal_flash_set('error', 'Too many nameservers — most registrars accept up to 12.');
        } elseif (array_filter($ns, fn($h) => !preg_match($host_re, $h))) {
            portal_flash_set('error', "One or more nameservers don't look like valid hostnames.");
        } else {
            try {
                $dc     = DomainClient::fromDB($dom['registrar']);
                $result = $dc->setNameservers($dom['domain_name'], $ns);
                if ($result['success'] ?? false) {
                    db()->prepare('UPDATE domain_registrations SET nameservers=? WHERE id=?')
                        ->execute([json_encode($ns), $id]);
                    portal_flash_set('success', 'Nameservers updated. DNS changes can take up to 24-48 hours to fully propagate.');
                } else {
                    portal_flash_set('error', 'Failed: ' . ($result['error'] ?? $result['message'] ?? 'Unknown error from the registrar.'));
                }
            } catch (\Throwable $e) {
                portal_flash_set('error', 'Failed: ' . $e->getMessage());
            }
        }
    }
    header('Location: ' . PORTAL_URL . '/domains.php');
    exit;
}

$stmt = db()->prepare('SELECT * FROM domain_registrations WHERE client_id = ? ORDER BY status = "active" DESC, expiry_date ASC');
$stmt->execute([$cid]);
$domains = $stmt->fetchAll();

require_once __DIR__ . '/includes/header.php';
?>

<div class="page-hero">
  <div class="container">
    <div>
      <h1>My Domains</h1>
      <p>Domain names registered with Orbit Cloud</p>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
      <a href="<?php echo PORTAL_URL; ?>/domain-transfer.php" class="btn btn-ghost" style="background:transparent;border:1px solid rgba(255,255,255,.3);color:#fff"><i class="fas fa-right-left"></i> Transfer a Domain</a>
      <a href="<?php echo PORTAL_URL; ?>/domain-search.php" class="btn btn-white"><i class="fas fa-plus"></i> Register a Domain</a>
    </div>
  </div>
</div>

<div class="page-body">
<div class="container">

  <?php portal_render_banners(); ?>

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
        <?php if ($d['status'] !== 'pending'): ?>
          <a href="<?php echo PORTAL_URL; ?>/domain-renew.php?id=<?php echo $d['id']; ?>" class="btn btn-primary btn-sm">
            <i class="fas fa-rotate"></i> Renew
          </a>
        <?php endif; ?>
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
        <?php if ($d['status'] === 'active'): ?>
          <button type="button" onclick="toggleNsForm(<?php echo (int) $d['id']; ?>)" style="margin-top:6px;background:none;border:none;padding:0;font-size:12px;color:var(--green);font-weight:600;cursor:pointer">
            <i class="fas fa-pen"></i> Edit
          </button>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($d['status'] === 'active'): ?>
    <div id="ns-form-<?php echo (int) $d['id']; ?>" style="display:none;border-top:1px solid var(--border);padding:16px 24px">
      <form method="POST">
        <input type="hidden" name="csrf_token" value="<?php echo portal_csrf(); ?>" />
        <input type="hidden" name="action" value="set_nameservers" />
        <input type="hidden" name="id" value="<?php echo (int) $d['id']; ?>" />
        <label style="font-size:12.5px;font-weight:600;display:block;margin-bottom:6px">Nameservers <span style="color:var(--text-muted);font-weight:400">(one per line, at least 2)</span></label>
        <textarea name="nameservers" rows="4" class="form-control" style="width:100%;font-family:ui-monospace,Menlo,monospace;font-size:12.5px;padding:10px;border:1px solid var(--border);border-radius:8px" placeholder="ns1.orbitcloud.co.ke&#10;ns2.orbitcloud.co.ke"><?php echo htmlspecialchars(implode("\n", $ns)); ?></textarea>
        <div style="font-size:11.5px;color:var(--text-muted);margin-top:6px">DNS changes can take up to 24-48 hours to propagate worldwide.</div>
        <div style="display:flex;gap:8px;margin-top:10px">
          <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-save"></i> Save Nameservers</button>
          <button type="button" class="btn btn-ghost btn-sm" style="border:1px solid var(--border)" onclick="toggleNsForm(<?php echo (int) $d['id']; ?>)">Cancel</button>
        </div>
      </form>
    </div>
    <?php endif; ?>
  </div>
<?php endforeach; else: ?>

  <div class="p-card" style="text-align:center;padding:56px 24px">
    <i class="fas fa-globe" style="font-size:42px;opacity:.2;display:block;margin-bottom:14px"></i>
    <h3 style="font-size:16px;margin-bottom:6px">No domains yet</h3>
    <p style="color:var(--text-muted);font-size:13.5px;margin-bottom:18px">Search for your perfect domain name and register it in minutes.</p>
    <a href="<?php echo PORTAL_URL; ?>/domain-search.php" class="btn btn-primary"><i class="fas fa-magnifying-glass"></i> Find a Domain</a>
  </div>

<?php endif; ?>

</div>
</div>

<script>
function toggleNsForm(id) {
  var el = document.getElementById('ns-form-' + id);
  if (el) el.style.display = el.style.display === 'none' ? 'block' : 'none';
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
