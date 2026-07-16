<?php
/**
 * Orbit Cloud — Order services from inside the client portal.
 * Catalogue → choose plan → pay with any active gateway → order recorded
 * as pending for the team to provision. Uses the shared dp_* payment
 * helpers so redirect, STK-push and offline-instruction methods all work.
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once dirname(__DIR__) . '/admin/includes/functions.php';
require_once __DIR__ . '/includes/domain_payment.php';
require_once dirname(__DIR__) . '/admin/includes/Notifier.php';
require_once dirname(__DIR__) . '/admin/includes/Automation.php';
require_once dirname(__DIR__) . '/admin/includes/Currency.php';
require_once dirname(__DIR__) . '/admin/includes/Coupon.php';

/** Validate a coupon code against $plan/$subtotal/$currency; never throws. */
function order_apply_coupon(?array $plan, float $subtotal, string $currency, string $code): array
{
    $code = trim($code);
    if ($code === '' || !$plan) return ['coupon' => null, 'discount' => 0.0, 'error' => ''];
    $coupon = Coupon::find($code);
    if (!$coupon) return ['coupon' => null, 'discount' => 0.0, 'error' => 'That coupon code was not found.'];
    $check = Coupon::validate($coupon, $subtotal, $currency, $plan['category']);
    if (!$check['ok']) return ['coupon' => null, 'discount' => 0.0, 'error' => $check['message']];
    return ['coupon' => $coupon, 'discount' => Coupon::discountFor($coupon, $subtotal, $currency), 'error' => ''];
}

portal_check();
$page_title = 'Order Services';
$c   = current_client();
$cid = (int) $c['id'];
Currency::ensureSchema();

$client_row = db()->prepare('SELECT * FROM clients WHERE id = ?');
$client_row->execute([$cid]);
$client_row = $client_row->fetch();

$currency = Currency::current();
$gateways = dp_active_gateways($currency);

$cycle_label = ['monthly' => '/mo', 'annual' => '/yr', 'one_time' => ' one-time'];

// Catalogue (description/features columns may not be migrated yet)
try {
    $plans = db()->query('SELECT * FROM services WHERE is_active = 1 ORDER BY category, price')->fetchAll();
} catch (\Throwable $e) {
    $plans = [];
}
$has_details = $plans && array_key_exists('description', $plans[0] ?? []);
$by_cat = [];
foreach ($plans as $p) $by_cat[$p['category']][] = $p;

$cat_labels = ['shared'=>'Shared Hosting','vps'=>'VPS Hosting','dedicated'=>'Dedicated Servers','cloud'=>'Cloud Hosting',
               'wordpress'=>'WordPress Hosting','reseller'=>'Reseller Hosting','ssl'=>'SSL Certificates','email'=>'Email Hosting','domain'=>'Domain Services'];

$view = 'catalogue'; $error = ''; $push_msg = ''; $pay_id = 0; $sel_plan = null; $order_note = '';
$coupon_code = trim($_POST['coupon'] ?? $_GET['coupon'] ?? '');

// ── Verify a returning payment ──
// settlePayment() verifies with the gateway and, on success, creates +
// provisions the order from context stored at payment-creation time —
// not a page-local variable — so the reconciliation cron/webhook can
// finish this identically if the client never comes back to this tab.
if (isset($_GET['pay'])) {
    $pay_id = (int) $_GET['pay'];
    $stmt = db()->prepare('SELECT status FROM payments WHERE id = ? AND client_id = ?');
    $stmt->execute([$pay_id, $cid]);
    $pay_status = $stmt->fetchColumn();

    if ($pay_status === false) {
        $view = 'catalogue';
        $error = 'Payment record not found.';
    } elseif ($pay_status === 'completed') {
        $view = 'success';
    } elseif ($pay_status === 'failed') {
        $view = 'failed';
        $error = 'This payment attempt failed.';
    } else {
        $r = Automation::settlePayment($pay_id);
        if ($r['status'] === 'completed') {
            $view = 'success';
        } elseif ($r['status'] === 'failed') {
            $view = 'failed';
            $error = $r['message'];
        } else {
            $view = 'pending';
            $push_msg = $r['message'];
        }
    }
}

// ── Show the order form for one plan ──
if ($view === 'catalogue' && isset($_GET['plan'])) {
    $stmt = db()->prepare('SELECT * FROM services WHERE id = ? AND is_active = 1');
    $stmt->execute([(int) $_GET['plan']]);
    $sel_plan = $stmt->fetch();
    if ($sel_plan) $view = 'form';
}

// ── Start a payment ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'pay') {
    portal_csrf_verify();
    $stmt = db()->prepare('SELECT * FROM services WHERE id = ? AND is_active = 1');
    $stmt->execute([(int) ($_POST['plan_id'] ?? 0)]);
    $sel_plan = $stmt->fetch();
    $gw = $_POST['gateway'] ?? '';

    if (!$sel_plan) {
        $error = 'That plan is no longer available.';
    } elseif (!isset($gateways[$gw])) {
        $error = 'Please choose a payment method.';
        $view  = 'form';
    } else {
        $domain   = strtolower(trim($_POST['domain'] ?? ''));
        $amt      = Currency::planAmount($sel_plan, $currency);
        $subtotal = $amt['price'] + $amt['setup_fee'];
        $coupon_result = order_apply_coupon($sel_plan, $subtotal, $currency, $coupon_code);

        if ($domain !== '' && !preg_match('/^[a-z0-9][a-z0-9.-]{2,253}$/', $domain)) {
            $error = 'That domain name doesn\'t look right — use e.g. example.co.ke (or leave it blank).';
            $view  = 'form';
        } elseif ($coupon_code !== '' && $coupon_result['error']) {
            $error = $coupon_result['error'];
            $view  = 'form';
        } else {
            $discount = $coupon_result['discount'];
            $total    = $subtotal - $discount;
            $desc  = 'Service order: ' . $sel_plan['name']
                   . ' (' . str_replace('_', ' ', $sel_plan['billing_cycle']) . ')'
                   . ($amt['setup_fee'] > 0 ? ' + setup fee' : '');
            $invoice_id = dp_create_invoice($cid, $desc, $total, $currency, $subtotal, $coupon_result['coupon']['code'] ?? '');
            if ($coupon_result['coupon']) Coupon::redeem((int) $coupon_result['coupon']['id']);
            $return = PORTAL_URL . '/order.php';
            $start  = dp_start_payment(
                $cid, $invoice_id, $total, $currency, $gw,
                ['name' => trim($client_row['first_name'] . ' ' . $client_row['last_name']),
                 'email' => $client_row['email'],
                 'phone' => trim($_POST['mpesa_phone'] ?? '') ?: ($client_row['phone'] ?? '')],
                $return, $return,
                ['action' => 'order_service', 'service_id' => (int) $sel_plan['id'],
                 'plan_name' => $sel_plan['name'], 'domain' => $domain]
            );
            $pay_id = $start['pay_id'];
            $r = $start['result'];

            if (empty($r['success'])) {
                $error = $r['message'] ?? 'The payment gateway rejected the request.';
                $view  = 'form';
            } elseif (($r['mode'] ?? '') === 'redirect' && !empty($r['redirect_url'])) {
                header('Location: ' . $r['redirect_url']);
                exit;
            } else {
                $view = 'pending';
                $push_msg = $r['message'] ?? 'Payment started — complete it, then click Verify below.';
            }
        }
    }
}

require_once __DIR__ . '/includes/header.php';
?>

<div class="page-hero">
  <div class="container">
    <div>
      <h1>Order Services</h1>
      <p>Add hosting, SSL, email and more to your account — all inside your portal</p>
    </div>
    <a href="<?php echo PORTAL_URL; ?>/domain-search.php" class="btn btn-white"><i class="fas fa-globe"></i> Buy a Domain</a>
  </div>
</div>

<div class="page-body">
<div class="container">

  <?php portal_render_banners(); ?>

<?php if ($error): ?>
  <div class="p-alert p-alert-error" style="margin-bottom:18px"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<?php if ($view === 'success'): ?>
  <div class="p-card" style="max-width:560px;margin:0 auto;text-align:center;padding:40px">
    <i class="fas fa-circle-check" style="font-size:48px;color:var(--green)"></i>
    <h2 style="font-size:19px;margin:14px 0 8px">Order received — thank you!</h2>
    <p style="color:var(--text-muted);font-size:14px">Payment confirmed. Our team is provisioning your service now; you'll get an email the moment it's active.</p>
    <div style="display:flex;gap:10px;justify-content:center;margin-top:22px">
      <a href="<?php echo PORTAL_URL; ?>/services.php" class="btn btn-primary"><i class="fas fa-box"></i> My Services</a>
      <a href="<?php echo PORTAL_URL; ?>/order.php" class="btn btn-ghost" style="border:1px solid var(--border)">Order another</a>
    </div>
  </div>

<?php elseif ($view === 'pending'): ?>
  <div class="p-card" style="max-width:560px;margin:0 auto;padding:32px">
    <div class="p-alert p-alert-info" style="white-space:pre-line;text-align:left"><i class="fas fa-hourglass-half"></i> <?php echo htmlspecialchars($push_msg ?: 'Waiting for payment confirmation.'); ?></div>
    <a href="<?php echo PORTAL_URL; ?>/order.php?pay=<?php echo $pay_id; ?>" class="btn btn-primary" style="width:100%;justify-content:center;margin-top:10px"><i class="fas fa-rotate"></i> I've paid — check status</a>
  </div>

<?php elseif ($view === 'failed'): ?>
  <div class="p-card" style="max-width:560px;margin:0 auto;text-align:center;padding:40px">
    <i class="fas fa-circle-xmark" style="font-size:44px;color:var(--danger)"></i>
    <h2 style="font-size:18px;margin:12px 0 8px">Payment failed</h2>
    <p style="color:var(--text-muted);font-size:13.5px"><?php echo htmlspecialchars($error); ?></p>
    <a href="<?php echo PORTAL_URL; ?>/order.php" class="btn btn-primary" style="margin-top:18px"><i class="fas fa-rotate"></i> Try again</a>
  </div>

<?php elseif ($view === 'form' && $sel_plan):
  $view_amt      = Currency::planAmount($sel_plan, $currency);
  $view_subtotal = $view_amt['price'] + $view_amt['setup_fee'];
  $view_coupon   = order_apply_coupon($sel_plan, $view_subtotal, $currency, $coupon_code);
  $view_discount = $view_coupon['discount'];
  $view_total    = $view_subtotal - $view_discount;
?>
  <div style="display:grid;grid-template-columns:1fr 340px;gap:20px;align-items:start" class="order-grid">
    <div class="p-card" style="padding:26px">
      <h2 style="font-size:16px;font-weight:700;margin-bottom:16px"><i class="fas fa-credit-card" style="color:var(--green)"></i> Complete your order</h2>

      <form method="GET" style="display:flex;gap:8px;margin-bottom:8px">
        <input type="hidden" name="plan" value="<?php echo (int) $sel_plan['id']; ?>" />
        <input type="text" name="coupon" class="form-control" placeholder="Coupon code" value="<?php echo htmlspecialchars($coupon_code); ?>" style="flex:1;padding:10px;border:1px solid var(--border);border-radius:8px;text-transform:uppercase" />
        <button type="submit" class="btn btn-ghost" style="border:1px solid var(--border);white-space:nowrap">Apply</button>
      </form>
      <?php if ($coupon_code !== ''): ?>
        <?php if ($view_coupon['error']): ?>
          <div class="p-alert p-alert-error" style="padding:9px 12px;font-size:12.5px;margin-bottom:16px"><i class="fas fa-circle-exclamation"></i> <?php echo htmlspecialchars($view_coupon['error']); ?></div>
        <?php elseif ($view_coupon['coupon']): ?>
          <div class="p-alert p-alert-success" style="padding:9px 12px;font-size:12.5px;margin-bottom:16px"><i class="fas fa-circle-check"></i> Coupon "<?php echo htmlspecialchars($view_coupon['coupon']['code']); ?>" applied — <?php echo htmlspecialchars($currency); ?> <?php echo number_format($view_discount, 2); ?> off.</div>
        <?php endif; ?>
      <?php else: ?>
        <div style="margin-bottom:16px"></div>
      <?php endif; ?>

      <?php if (!$gateways): ?>
        <div class="p-alert p-alert-info"><i class="fas fa-circle-info"></i> Online payment is being set up. Please contact us to complete this order.</div>
      <?php else: ?>
      <form method="POST">
        <input type="hidden" name="csrf_token" value="<?php echo portal_csrf(); ?>" />
        <input type="hidden" name="action" value="pay" />
        <input type="hidden" name="plan_id" value="<?php echo (int) $sel_plan['id']; ?>" />

        <?php if (!in_array($sel_plan['category'], ['ssl', 'domain'], true)): ?>
          <label style="font-size:13px;font-weight:600;display:block;margin-bottom:6px">Domain for this service <span style="color:var(--text-muted);font-weight:400">(optional — you can tell us later)</span></label>
          <input type="text" name="domain" class="form-control" placeholder="example.co.ke" value="<?php echo htmlspecialchars($_POST['domain'] ?? ''); ?>" style="width:100%;padding:10px;border:1px solid var(--border);border-radius:8px;margin-bottom:16px" />
        <?php endif; ?>
        <input type="hidden" name="coupon" value="<?php echo htmlspecialchars($coupon_code); ?>" />

        <div style="font-size:13px;font-weight:600;margin-bottom:8px">Pay with</div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:14px">
          <?php foreach ($gateways as $key => $def): ?>
            <label style="display:flex;align-items:center;gap:10px;border:1px solid var(--border);border-radius:10px;padding:12px;cursor:pointer;font-weight:600;font-size:13px">
              <input type="radio" name="gateway" value="<?php echo htmlspecialchars($key); ?>" required data-gw="<?php echo htmlspecialchars($key); ?>" />
              <span style="width:30px;height:30px;border-radius:8px;display:flex;align-items:center;justify-content:center;color:#fff;font-size:13px;flex-shrink:0;background:<?php echo htmlspecialchars($def['color']); ?>"><i class="fas <?php echo htmlspecialchars($def['icon']); ?>"></i></span>
              <?php echo htmlspecialchars($def['name']); ?>
            </label>
          <?php endforeach; ?>
        </div>
        <div id="mpesaPhone" style="display:none;margin-bottom:14px">
          <label style="font-size:13px;font-weight:600;display:block;margin-bottom:6px">M-Pesa phone number</label>
          <input type="tel" name="mpesa_phone" class="form-control" placeholder="07XX XXX XXX" value="<?php echo htmlspecialchars($client_row['phone'] ?? ''); ?>" style="width:100%;padding:10px;border:1px solid var(--border);border-radius:8px" />
        </div>
        <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center;padding:13px;font-weight:700"><i class="fas fa-lock"></i> Pay <?php echo htmlspecialchars($currency); ?> <?php echo number_format($view_total, 2); ?></button>
      </form>
      <script>
        document.querySelectorAll('input[name="gateway"]').forEach(function (r) {
          r.addEventListener('change', function () {
            document.getElementById('mpesaPhone').style.display = r.dataset.gw === 'kopokopo' ? 'block' : 'none';
          });
        });
      </script>
      <?php endif; ?>
    </div>

    <div class="p-card" style="padding:24px">
      <div style="font-size:11px;color:var(--text-muted);font-weight:700;text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px"><?php echo htmlspecialchars($cat_labels[$sel_plan['category']] ?? ucfirst($sel_plan['category'])); ?></div>
      <div style="font-size:18px;font-weight:800;color:var(--navy)"><?php echo htmlspecialchars($sel_plan['name']); ?></div>
      <?php if (!empty($sel_plan['description'])): ?>
        <p style="font-size:13px;color:var(--text-muted);margin-top:8px;line-height:1.6"><?php echo htmlspecialchars($sel_plan['description']); ?></p>
      <?php endif; ?>
      <div style="border-top:1px solid var(--border);margin:14px 0;padding-top:14px;display:flex;justify-content:space-between;font-size:13.5px">
        <span><?php echo htmlspecialchars($sel_plan['name']); ?></span>
        <strong><?php echo htmlspecialchars($currency); ?> <?php echo number_format($view_amt['price'], 2); ?><?php echo $cycle_label[$sel_plan['billing_cycle']] ?? ''; ?></strong>
      </div>
      <?php if ($view_amt['setup_fee'] > 0): ?>
        <div style="display:flex;justify-content:space-between;font-size:13.5px;margin-bottom:6px">
          <span>Setup fee</span><strong><?php echo htmlspecialchars($currency); ?> <?php echo number_format($view_amt['setup_fee'], 2); ?></strong>
        </div>
      <?php endif; ?>
      <?php if ($view_coupon['coupon']): ?>
        <div style="display:flex;justify-content:space-between;font-size:13.5px;margin-bottom:6px;color:var(--green)">
          <span>Discount (<?php echo htmlspecialchars($view_coupon['coupon']['code']); ?>)</span><strong>-<?php echo htmlspecialchars($currency); ?> <?php echo number_format($view_discount, 2); ?></strong>
        </div>
      <?php endif; ?>
      <div style="display:flex;justify-content:space-between;border-top:2px solid var(--border);padding-top:12px;margin-top:8px;font-size:15.5px;font-weight:800;color:var(--navy)">
        <span>Due today</span><span><?php echo htmlspecialchars($currency); ?> <?php echo number_format($view_total, 2); ?></span>
      </div>
      <?php if (!empty($sel_plan['features'])): ?>
        <div style="border-top:1px solid var(--border);margin-top:14px;padding-top:12px">
          <?php foreach (array_filter(array_map('trim', explode("\n", $sel_plan['features']))) as $feat): ?>
            <div style="font-size:12.5px;padding:3px 0;color:var(--text)"><i class="fas fa-check" style="color:var(--green);margin-right:7px;font-size:11px"></i><?php echo htmlspecialchars($feat); ?></div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
      <a href="<?php echo PORTAL_URL; ?>/order.php" style="display:block;text-align:center;margin-top:14px;font-size:12.5px;color:var(--text-muted)">← Choose a different plan</a>
    </div>
  </div>
  <style>@media (max-width: 820px) { .order-grid { grid-template-columns: 1fr !important; } }</style>

<?php else: /* catalogue */ ?>

  <?php if (!$by_cat): ?>
    <div class="p-card"><div class="empty-state" style="padding:60px"><i class="fas fa-box-open"></i><h3>No plans available yet</h3><p>Please check back soon or contact us.</p></div></div>
  <?php endif; ?>

  <?php foreach ($by_cat as $cat => $cat_plans): ?>
    <h2 style="font-size:16px;font-weight:800;color:var(--navy);margin:26px 0 14px"><?php echo htmlspecialchars($cat_labels[$cat] ?? ucfirst($cat)); ?></h2>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(250px,1fr));gap:16px">
      <?php foreach ($cat_plans as $p): $p_amt = Currency::planAmount($p, $currency); ?>
        <div class="p-card" style="padding:22px;display:flex;flex-direction:column">
          <div style="font-size:15.5px;font-weight:800;color:var(--navy)"><?php echo htmlspecialchars($p['name']); ?></div>
          <div style="font-size:21px;font-weight:800;color:var(--green);margin:8px 0 2px">
            <?php echo htmlspecialchars($currency); ?> <?php echo number_format($p_amt['price'], 2); ?><span style="font-size:12px;color:var(--text-muted);font-weight:600"><?php echo $cycle_label[$p['billing_cycle']] ?? ''; ?></span>
          </div>
          <?php if ($p_amt['setup_fee'] > 0): ?>
            <div style="font-size:11.5px;color:var(--text-muted)">+ <?php echo htmlspecialchars($currency); ?> <?php echo number_format($p_amt['setup_fee'], 2); ?> setup</div>
          <?php endif; ?>
          <?php if (!empty($p['description'])): ?>
            <p style="font-size:12.5px;color:var(--text-muted);margin-top:8px;line-height:1.55"><?php echo htmlspecialchars(mb_strimwidth($p['description'], 0, 120, '…')); ?></p>
          <?php endif; ?>
          <?php if (!empty($p['features'])): $feats = array_filter(array_map('trim', explode("\n", $p['features']))); ?>
            <div style="margin-top:10px;flex:1">
              <?php foreach (array_slice($feats, 0, 4) as $feat): ?>
                <div style="font-size:12px;padding:2.5px 0"><i class="fas fa-check" style="color:var(--green);margin-right:6px;font-size:10.5px"></i><?php echo htmlspecialchars($feat); ?></div>
              <?php endforeach; ?>
              <?php if (count($feats) > 4): ?><div style="font-size:11.5px;color:var(--text-muted);padding:2.5px 0">+ <?php echo count($feats) - 4; ?> more</div><?php endif; ?>
            </div>
          <?php else: ?><div style="flex:1"></div><?php endif; ?>
          <a href="<?php echo PORTAL_URL; ?>/order.php?plan=<?php echo (int)$p['id']; ?>" class="btn btn-primary" style="margin-top:14px;justify-content:center"><i class="fas fa-cart-plus"></i> Order Now</a>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endforeach; ?>

<?php endif; ?>

</div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
