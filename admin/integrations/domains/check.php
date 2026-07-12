<?php
require_once '../../includes/config.php';
require_once '../../includes/db.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
require_once '../../includes/DomainClient.php';

auth_check();
$page_title = 'Check Domain Availability';

$results  = null;
$domain   = trim($_POST['domain'] ?? $_GET['domain'] ?? '');
$provider = trim($_POST['provider'] ?? 'namecheap');
$errors   = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $domain) {
    csrf_verify();

    // Strip www / protocol
    $domain = preg_replace('/^(https?:\/\/)?(www\.)?/', '', strtolower($domain));
    $domain = explode('/', $domain)[0]; // remove any path

    try {
        $client  = DomainClient::fromDB($provider);
        $results = $client->checkMultiple($domain, ['com', 'net', 'org', 'co.ke', 'ke', 'io']);
    } catch (\Throwable $e) {
        $errors[] = $e->getMessage();
    }
}

require_once '../../includes/header.php';
?>

<div class="content-header">
  <h1 class="content-title">Domain Availability Check</h1>
  <a href="<?php echo APP_URL; ?>/integrations/domains/" class="btn btn-ghost"><i class="fas fa-arrow-left"></i> Back</a>
</div>

<div style="max-width:700px">

  <div class="card" style="margin-bottom:20px">
    <div class="card-body">
      <?php if ($errors): ?>
        <div class="alert alert-danger"><i class="fas fa-triangle-exclamation"></i> <?php echo htmlspecialchars($errors[0]); ?></div>
      <?php endif; ?>

      <form method="POST">
        <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>" />
        <div style="display:grid;grid-template-columns:1fr auto auto;gap:12px;align-items:end">
          <div class="form-group" style="margin:0">
            <label class="form-label">Domain Name</label>
            <input type="text" name="domain" class="form-control" placeholder="example.com"
                   value="<?php echo htmlspecialchars($domain); ?>" required autofocus />
          </div>
          <div class="form-group" style="margin:0">
            <label class="form-label">Registrar</label>
            <select name="provider" class="form-control">
              <option value="namecheap" <?php echo $provider==='namecheap'?'selected':''; ?>>Namecheap</option>
              <option value="godaddy"   <?php echo $provider==='godaddy'  ?'selected':''; ?>>GoDaddy</option>
            </select>
          </div>
          <button type="submit" class="btn btn-primary" style="white-space:nowrap"><i class="fas fa-magnifying-glass"></i> Check</button>
        </div>
      </form>
    </div>
  </div>

  <?php if ($results): ?>
    <div class="card">
      <div class="card-header">
        <span class="card-title">Results for <strong><?php echo htmlspecialchars($domain); ?></strong></span>
      </div>
      <div class="table-wrap">
        <table>
          <thead>
            <tr><th>Domain</th><th>Availability</th><th>Price</th><th></th></tr>
          </thead>
          <tbody>
            <?php foreach ($results as $tld => $info):
              $available = $info['available'] ?? false;
              $price     = $info['price'] ?? null;
              $full_domain = strpos($domain, '.') !== false && $tld === '' ? $domain : ($domain . '.' . $tld);
              // If checkMultiple returns TLD as key, domain_name is the full domain
              $full_domain = $info['domain'] ?? $full_domain;
            ?>
              <tr>
                <td><strong><?php echo htmlspecialchars($full_domain); ?></strong></td>
                <td>
                  <?php if ($available): ?>
                    <span class="badge badge-success"><i class="fas fa-circle-check"></i> Available</span>
                  <?php else: ?>
                    <span class="badge badge-danger"><i class="fas fa-xmark"></i> Taken</span>
                  <?php endif; ?>
                </td>
                <td>
                  <?php echo $price ? '$' . number_format((float)$price, 2) . '/yr' : '<span style="color:var(--text-muted)">—</span>'; ?>
                </td>
                <td>
                  <?php if ($available): ?>
                    <a href="register.php?domain=<?php echo urlencode($full_domain); ?>&provider=<?php echo urlencode($provider); ?>"
                       class="btn btn-primary btn-sm"><i class="fas fa-cart-plus"></i> Register</a>
                  <?php else: ?>
                    <span style="font-size:12px;color:var(--text-muted)">Unavailable</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php endif; ?>

</div>

<?php require_once '../../includes/footer.php'; ?>
