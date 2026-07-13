<?php
/**
 * OrbitHost — Domain cart
 * Session-based; works before login (checkout is where sign-in happens).
 *   ?add=domain.tld    add a domain (from the website search)
 *   ?remove=domain.tld remove
 *   ?years[domain]=N   POST — update registration years
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';

portal_start();
if (!isset($_SESSION['cart_domains']) || !is_array($_SESSION['cart_domains'])) {
    $_SESSION['cart_domains'] = [];
}

// Load sellable TLDs (price lookups + validation)
$tld_rows = [];
try {
    foreach (db()->query('SELECT tld, currency, register_price, renew_price FROM domain_tlds WHERE is_active = 1')->fetchAll() as $r) {
        $tld_rows[$r['tld']] = $r;
    }
} catch (\Throwable $e) {
    // table missing — cart will show a friendly message
}

$notice = '';

// ── Add ──
if (isset($_GET['add'])) {
    $domain = strtolower(preg_replace('/[^a-z0-9.-]/', '', trim($_GET['add'])));
    $parts  = explode('.', $domain, 2);
    $tld    = $parts[1] ?? '';
    if ($domain && $tld && isset($tld_rows[$tld]) && preg_match('/^[a-z0-9-]{2,63}\./', $domain)) {
        $_SESSION['cart_domains'][$domain] = ['tld' => $tld, 'years' => $_SESSION['cart_domains'][$domain]['years'] ?? 1];
        header('Location: ' . PORTAL_URL . '/cart.php');
        exit;
    }
    $notice = 'That domain extension is not available for online ordering. Contact us and we\'ll help.';
}

// ── Remove ──
if (isset($_GET['remove'])) {
    unset($_SESSION['cart_domains'][strtolower(trim($_GET['remove']))]);
    header('Location: ' . PORTAL_URL . '/cart.php');
    exit;
}

// ── Update years ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['years'])) {
    foreach ((array)$_POST['years'] as $d => $y) {
        $d = strtolower(trim($d));
        if (isset($_SESSION['cart_domains'][$d])) {
            $_SESSION['cart_domains'][$d]['years'] = max(1, min(5, (int)$y));
        }
    }
    header('Location: ' . PORTAL_URL . '/cart.php');
    exit;
}

$cart = $_SESSION['cart_domains'];
$total = 0.0;
$currency = defined('CURRENCY') ? CURRENCY : 'USD';
$items = [];
foreach ($cart as $domain => $it) {
    $p = $tld_rows[$it['tld']] ?? null;
    if (!$p) { unset($_SESSION['cart_domains'][$domain]); continue; }
    $line = (float)$p['register_price'] * $it['years'];
    $total += $line;
    $currency = $p['currency'];
    $items[] = ['domain' => $domain, 'years' => $it['years'], 'unit' => (float)$p['register_price'], 'line' => $line, 'currency' => $p['currency']];
}
$logged_in = !empty($_SESSION['client_id']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Your Cart — OrbitHost</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" crossorigin="anonymous" />
  <link rel="stylesheet" href="<?php echo PORTAL_URL; ?>/css/portal.css" />
  <style>
    body { background: var(--navy); min-height: 100vh; padding: 32px 16px; }
    .cart-wrap { max-width: 640px; margin: 0 auto; }
    .cart-brand { text-align: center; margin-bottom: 24px; }
    .cart-orb { width: 50px; height: 50px; background: var(--green); border-radius: 14px; display: flex; align-items: center; justify-content: center; font-size: 22px; font-weight: 800; color: #fff; margin: 0 auto 12px; }
    .cart-brand h1 { color: #fff; font-size: 20px; font-weight: 700; }
    .cart-card { background: #fff; border-radius: 16px; padding: 28px; box-shadow: 0 20px 60px rgba(0,0,0,.3); }
    .cart-row { display: flex; align-items: center; gap: 12px; padding: 14px 0; border-bottom: 1px solid var(--border); }
    .cart-row:last-of-type { border-bottom: none; }
    .cart-domain { font-weight: 700; color: var(--navy); font-family: ui-monospace, Menlo, monospace; font-size: 14px; flex: 1; min-width: 0; word-break: break-all; }
    .cart-price { font-weight: 700; white-space: nowrap; }
    .cart-total { display: flex; justify-content: space-between; align-items: center; padding-top: 16px; margin-top: 6px; border-top: 2px solid var(--border); font-size: 17px; font-weight: 800; color: var(--navy); }
    .years-select { padding: 6px 8px; border: 1px solid var(--border); border-radius: 7px; font-size: 13px; }
    .cart-actions { display: flex; gap: 10px; margin-top: 20px; flex-wrap: wrap; }
    .btn-checkout { flex: 1; justify-content: center; padding: 12px; font-size: 14.5px; font-weight: 700; }
    .back-link { display: block; text-align: center; margin-top: 16px; font-size: 12.5px; color: rgba(255,255,255,.45); }
    .empty-cart { text-align: center; padding: 30px 0; color: var(--text-muted); }
    .empty-cart i { font-size: 40px; opacity: .25; display: block; margin-bottom: 12px; }
    .notice { background: #fffbeb; color: #92400e; border: 1px solid #fcd34d; padding: 11px 14px; border-radius: 8px; font-size: 13px; margin-bottom: 16px; }
  </style>
</head>
<body>
<div class="cart-wrap">
  <div class="cart-brand">
    <div class="cart-orb">O</div>
    <h1>Your Domain Cart</h1>
  </div>

  <div class="cart-card">
    <?php if ($notice): ?><div class="notice"><i class="fas fa-circle-info"></i> <?php echo htmlspecialchars($notice); ?></div><?php endif; ?>

    <?php if (!$items): ?>
      <div class="empty-cart">
        <i class="fas fa-cart-shopping"></i>
        <p>Your cart is empty.</p>
        <a href="<?php echo $logged_in ? PORTAL_URL . '/domain-search.php' : '../domains.html'; ?>" class="btn btn-primary" style="margin-top:14px;display:inline-flex"><i class="fas fa-magnifying-glass"></i> Search for a domain</a>
      </div>
    <?php else: ?>
      <form method="POST">
        <?php foreach ($items as $it): ?>
          <div class="cart-row">
            <span class="cart-domain"><?php echo htmlspecialchars($it['domain']); ?></span>
            <select class="years-select" name="years[<?php echo htmlspecialchars($it['domain']); ?>]" onchange="this.form.submit()">
              <?php for ($y = 1; $y <= 5; $y++): ?>
                <option value="<?php echo $y; ?>" <?php echo $it['years'] === $y ? 'selected' : ''; ?>><?php echo $y; ?> yr<?php echo $y > 1 ? 's' : ''; ?></option>
              <?php endfor; ?>
            </select>
            <span class="cart-price"><?php echo htmlspecialchars($it['currency']); ?> <?php echo number_format($it['line'], 2); ?></span>
            <a href="?remove=<?php echo urlencode($it['domain']); ?>" title="Remove" style="color:var(--danger)"><i class="fas fa-xmark"></i></a>
          </div>
        <?php endforeach; ?>
      </form>

      <div class="cart-total">
        <span>Total</span>
        <span><?php echo htmlspecialchars($currency); ?> <?php echo number_format($total, 2); ?></span>
      </div>

      <div class="cart-actions">
        <a href="<?php echo $logged_in ? PORTAL_URL . '/domain-search.php' : '../domains.html'; ?>" class="btn btn-ghost" style="border:1px solid var(--border)"><i class="fas fa-plus"></i> Add another</a>
        <a href="<?php echo PORTAL_URL; ?>/checkout.php" class="btn btn-primary btn-checkout">
          <i class="fas fa-lock"></i> <?php echo $logged_in ? 'Continue to Checkout' : 'Checkout — sign in or create account'; ?>
        </a>
      </div>
    <?php endif; ?>
  </div>

  <a href="../index.html" class="back-link">← Back to OrbitHost website</a>
</div>
</body>
</html>
