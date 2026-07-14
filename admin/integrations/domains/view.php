<?php
require_once '../../includes/config.php';
require_once '../../includes/db.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
require_once '../../includes/DomainClient.php';

auth_check();

$id   = (int)($_GET['id'] ?? 0);
$stmt = db()->prepare(
    'SELECT dr.*, c.first_name, c.last_name, c.email
     FROM domain_registrations dr
     LEFT JOIN clients c ON c.id = dr.client_id
     WHERE dr.id=?'
);
$stmt->execute([$id]);
$dom = $stmt->fetch();

if (!$dom) {
    flash_set('error', 'Domain record not found.');
    header('Location: ' . APP_URL . '/integrations/domains/');
    exit;
}

$page_title = $dom['domain_name'];
$errors = [];
$live_info = null;

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'fetch_info') {
        try {
            $dc       = DomainClient::fromDB($dom['registrar']);
            $live_info = $dc->getInfo($dom['domain_name']);
        } catch (\Throwable $e) {
            $errors[] = $e->getMessage();
        }
    } elseif ($action === 'set_nameservers') {
        $ns = array_filter(array_map('trim', explode("\n", $_POST['nameservers'] ?? '')));
        try {
            $dc     = DomainClient::fromDB($dom['registrar']);
            $result = $dc->setNameservers($dom['domain_name'], $ns);
            if ($result['success'] ?? false) {
                db()->prepare('UPDATE domain_registrations SET nameservers=? WHERE id=?')
                    ->execute([json_encode(array_values($ns)), $id]);
                flash_set('success', 'Nameservers updated.');
            } else {
                $errors[] = 'Failed: ' . ($result['error'] ?? 'Unknown');
            }
        } catch (\Throwable $e) {
            $errors[] = $e->getMessage();
        }
        header('Location: ' . APP_URL . '/integrations/domains/view.php?id=' . $id);
        exit;
    } elseif ($action === 'renew') {
        $years = (int)($_POST['years'] ?? 1);
        try {
            $dc     = DomainClient::fromDB($dom['registrar']);
            $result = $dc->renew($dom['domain_name'], $years);
            if ($result['success'] ?? false) {
                $new_expiry = date('Y-m-d', strtotime($dom['expiry_date'] . " +{$years} years"));
                db()->prepare('UPDATE domain_registrations SET expiry_date=? WHERE id=?')
                    ->execute([$new_expiry, $id]);
                flash_set('success', "Domain renewed for {$years} year(s). New expiry: {$new_expiry}");
            } else {
                $errors[] = $result['error'] ?? 'Renewal failed.';
            }
        } catch (\Throwable $e) {
            $errors[] = $e->getMessage();
        }
        header('Location: ' . APP_URL . '/integrations/domains/view.php?id=' . $id);
        exit;
    }
}

$days_left = (int)ceil((strtotime($dom['expiry_date']) - time()) / 86400);
$ns_list   = json_decode($dom['nameservers'] ?? '[]', true) ?? [];

require_once '../../includes/header.php';
?>

<div class="content-header">
  <h1 class="content-title"><?php echo htmlspecialchars($dom['domain_name']); ?></h1>
  <a href="<?php echo APP_URL; ?>/integrations/domains/" class="btn btn-ghost"><i class="fas fa-arrow-left"></i> Back</a>
</div>

<?php if ($errors): ?>
  <div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($errors[0]); ?></div>
<?php endif; ?>

<div style="display:grid;grid-template-columns:1fr 280px;gap:20px;align-items:start">

  <div style="display:flex;flex-direction:column;gap:16px">

    <!-- Nameservers -->
    <div class="card">
      <div class="card-header"><span class="card-title">Nameservers</span></div>
      <div class="card-body">
        <form method="POST">
          <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>" />
          <input type="hidden" name="action"     value="set_nameservers" />
          <div class="form-group">
            <label class="form-label">Nameservers (one per line)</label>
            <textarea name="nameservers" class="form-control" rows="4"
                      placeholder="ns1.orbitcloud.co.ke&#10;ns2.orbitcloud.co.ke"><?php echo htmlspecialchars(implode("\n", $ns_list)); ?></textarea>
          </div>
          <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-save"></i> Update Nameservers</button>
        </form>
      </div>
    </div>

    <!-- Renew -->
    <div class="card">
      <div class="card-header"><span class="card-title">Renew Domain</span></div>
      <div class="card-body">
        <form method="POST" onsubmit="return confirm('Renew this domain? This will charge the registrar account.')">
          <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>" />
          <input type="hidden" name="action"     value="renew" />
          <div style="display:flex;gap:10px;align-items:center">
            <select name="years" class="form-control" style="width:140px">
              <?php for ($y=1;$y<=5;$y++): ?>
                <option value="<?php echo $y; ?>"><?php echo $y; ?> year<?php echo $y>1?'s':''; ?></option>
              <?php endfor; ?>
            </select>
            <button type="submit" class="btn btn-<?php echo $days_left <= 30 ? 'danger' : 'ghost'; ?>">
              <i class="fas fa-arrows-rotate"></i> Renew
            </button>
          </div>
        </form>
      </div>
    </div>

    <!-- Fetch live info -->
    <div class="card">
      <div class="card-header"><span class="card-title">Live WHOIS / Registry Info</span></div>
      <div class="card-body">
        <form method="POST">
          <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>" />
          <input type="hidden" name="action"     value="fetch_info" />
          <button type="submit" class="btn btn-ghost btn-sm"><i class="fas fa-cloud-arrow-down"></i> Fetch from Registrar</button>
        </form>
        <?php if ($live_info): ?>
          <pre style="margin-top:12px;font-size:12px;background:#f8fafc;padding:12px;border-radius:6px;overflow-x:auto"><?php echo htmlspecialchars(json_encode($live_info, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)); ?></pre>
        <?php endif; ?>
      </div>
    </div>

  </div>

  <!-- Sidebar info -->
  <div class="card">
    <div class="card-header"><span class="card-title">Domain Details</span></div>
    <div style="padding:0">
      <?php
      $rows = [
        ['Domain',     htmlspecialchars($dom['domain_name'])],
        ['Registrar',  ucfirst($dom['registrar'])],
        ['Status',     badge($dom['status'])],
        ['Registered', format_date($dom['created_at'])],
        ['Expires',    format_date($dom['expiry_date'])],
        ['Days Left',  "<span style=\"font-weight:700;color:" . ($days_left <= 30 ? 'var(--danger)' : ($days_left <= 60 ? 'var(--warning)' : 'var(--text)')) . "\">{$days_left} days</span>"],
        ['Auto Renew', $dom['auto_renew'] ? '<span class="badge badge-success">Yes</span>' : '<span class="badge" style="background:#6b728020;color:#6b7280">No</span>'],
        ['EPP Code',   $dom['epp_code'] ? '<code style="font-size:12px">' . htmlspecialchars($dom['epp_code']) . '</code>' : '<span style="color:var(--text-muted)">Not set</span>'],
      ];
      if ($dom['first_name']):
        $rows[] = ['Client', '<a href="' . APP_URL . '/clients/view.php?id=' . $dom['client_id'] . '">' . htmlspecialchars($dom['first_name'] . ' ' . $dom['last_name']) . '</a>'];
      endif;
      foreach ($rows as [$k, $v]):
      ?>
        <div style="display:flex;justify-content:space-between;align-items:center;padding:10px 16px;border-bottom:1px solid var(--border);font-size:13px">
          <span style="color:var(--text-muted)"><?php echo $k; ?></span>
          <span><?php echo $v; ?></span>
        </div>
      <?php endforeach; ?>
    </div>
  </div>

</div>

<?php require_once '../../includes/footer.php'; ?>
