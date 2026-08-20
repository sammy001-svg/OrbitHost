<?php
/**
 * Orbit Cloud — shared shell for the four-step hosting checkout.
 *
 * Standalone rather than portal/includes/header.php because the whole
 * funnel runs before sign-in — the visitor only gets an account at step 4.
 * Same reasoning (and the same look) as the domain cart page.
 */
require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/admin/includes/functions.php';
require_once dirname(__DIR__, 2) . '/admin/includes/SiteSettings.php';
require_once dirname(__DIR__, 2) . '/admin/includes/Currency.php';
require_once dirname(__DIR__) . '/includes/order_cart.php';

/** The funnel, in order. */
function checkout_steps(): array
{
    return [
        1 => ['label' => 'Domain',          'file' => 'index.php'],
        2 => ['label' => 'Configure',       'file' => 'configure.php'],
        3 => ['label' => 'Domain Setup',    'file' => 'domain-config.php'],
        4 => ['label' => 'Review & Checkout','file' => 'review.php'],
    ];
}

function checkout_url(string $file): string
{
    return PORTAL_URL . '/order/' . $file;
}

/**
 * Bounce back to the earliest step that still needs answering, so a stale
 * tab or a pasted link can never reach checkout with an incomplete order.
 */
function checkout_require(string $step): void
{
    if (OrderCart::readyFor($step)) return;
    if (OrderCart::plan()) {
        header('Location: ' . checkout_url('index.php'));
        exit;
    }
    header('Location: ' . checkout_no_plan_url());
    exit;
}

/**
 * Where to send someone whose session has no package in it. A signed-in
 * client gets the portal catalogue; a guest gets the public pricing page,
 * because portal/order.php would only bounce them to a login screen.
 */
function checkout_no_plan_url(): string
{
    return !empty($_SESSION['client_id'])
        ? PORTAL_URL . '/order.php'
        : SiteSettings::siteRoot() . '/hosting/shared.html#plans';
}

function checkout_money(float $amount, string $currency): string
{
    return ($currency === 'KES' ? 'KSh ' : '$') . number_format($amount, 2);
}

function checkout_head(string $title, int $active): void
{
    $brand = SiteSettings::brandName();
    $logo  = SiteSettings::logoImgTag(30, 130);
    ?><!DOCTYPE html>
<html lang="en"<?php $_t = $_COOKIE['orbit_portal_theme'] ?? ''; echo in_array($_t, ['light','dark'], true) ? ' data-theme="' . $_t . '"' : ''; ?>>
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta name="color-scheme" content="light dark" />
  <meta name="robots" content="noindex" />
  <title><?php echo h($title); ?> — <?php echo h($brand); ?></title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" crossorigin="anonymous" />
  <link rel="stylesheet" href="<?php echo PORTAL_URL; ?>/css/portal.css?v=<?php echo @filemtime(dirname(__DIR__) . '/css/portal.css') ?: time(); ?>" />
  <style>
    body { background: var(--bg); color: var(--text); }
    .co-bar { background: var(--header-bg); padding: 14px 0; }
    .co-bar-in { max-width: 1080px; margin: 0 auto; padding: 0 18px; display: flex; align-items: center; justify-content: space-between; gap: 14px; }
    .co-brand { display: flex; align-items: center; gap: 10px; color: #fff; font-weight: 700; font-size: 15px; text-decoration: none; }
    .co-orb { width: 32px; height: 32px; background: var(--green); border-radius: 9px; display: flex; align-items: center; justify-content: center; font-weight: 800; color: #fff; }
    .co-secure { color: rgba(255,255,255,.7); font-size: 12.5px; display: flex; align-items: center; gap: 7px; }
    .co-wrap { max-width: 1080px; margin: 0 auto; padding: 26px 18px 60px; }

    .co-steps { display: flex; align-items: center; gap: 6px; margin-bottom: 24px; flex-wrap: wrap; }
    .co-step { display: flex; align-items: center; gap: 9px; font-size: 12.5px; color: var(--text-muted); font-weight: 600; }
    .co-step .n { width: 25px; height: 25px; border-radius: 50%; background: var(--surface); border: 2px solid var(--border-2); display: flex; align-items: center; justify-content: center; font-size: 11.5px; font-weight: 800; }
    .co-step.done .n { background: var(--green); border-color: var(--green); color: #fff; }
    .co-step.active { color: var(--text); }
    .co-step.active .n { background: var(--green); border-color: var(--green); color: #fff; }
    .co-sep { flex: 1 1 18px; min-width: 14px; height: 2px; background: var(--border-2); border-radius: 2px; }

    .co-grid { display: grid; grid-template-columns: 1fr 330px; gap: 20px; align-items: start; }
    .co-panel { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius-lg); overflow: hidden; }
    .co-panel + .co-panel { margin-top: 16px; }
    .co-panel-head { padding: 15px 20px; border-bottom: 1px solid var(--border); }
    .co-panel-head h2 { font-size: 15px; color: var(--text); font-weight: 700; margin: 0; }
    .co-panel-head p { font-size: 12.5px; color: var(--text-muted); margin: 4px 0 0; }
    .co-panel-body { padding: 20px; }

    .co-opt { display: flex; gap: 12px; align-items: flex-start; border: 2px solid var(--border); border-radius: var(--radius); padding: 14px 16px; cursor: pointer; transition: border-color .15s, background .15s; }
    .co-opt + .co-opt { margin-top: 10px; }
    .co-opt:hover { border-color: var(--border-2); }
    .co-opt.sel { border-color: var(--green); background: var(--green-light); }
    .co-opt input { margin-top: 3px; flex: 0 0 auto; }
    .co-opt-t { font-weight: 700; font-size: 13.5px; color: var(--text); }
    .co-opt-d { font-size: 12.5px; color: var(--text-muted); margin-top: 2px; }

    .co-sum { position: sticky; top: 18px; }
    .co-sum-line { display: flex; justify-content: space-between; gap: 12px; font-size: 13px; padding: 8px 0; border-bottom: 1px dashed var(--border); }
    .co-sum-line:last-of-type { border-bottom: none; }
    .co-sum-line .l { color: var(--text-2); min-width: 0; }
    .co-sum-line .v { font-weight: 700; white-space: nowrap; color: var(--text); }
    .co-sum-sub { display: flex; justify-content: space-between; font-size: 13px; padding: 7px 0; }
    .co-sum-total { display: flex; justify-content: space-between; align-items: baseline; padding-top: 12px; margin-top: 6px; border-top: 2px solid var(--border); font-size: 17px; font-weight: 800; color: var(--text); }

    .co-actions { display: flex; gap: 10px; justify-content: space-between; align-items: center; margin-top: 18px; flex-wrap: wrap; }
    .co-back { font-size: 13px; color: var(--text-muted); text-decoration: none; }
    .co-back:hover { color: var(--text); }

    @media (max-width: 900px) {
      .co-grid { grid-template-columns: 1fr; }
      .co-sum { position: static; }
      .co-step .lbl { display: none; }
    }
  </style>
</head>
<body>
<div class="co-bar">
  <div class="co-bar-in">
    <a href="<?php echo SiteSettings::siteRoot(); ?>/index.html" class="co-brand">
      <?php if ($logo): ?><?php echo $logo; ?><?php else: ?>
        <span class="co-orb">O</span><span><?php echo h($brand); ?></span>
      <?php endif; ?>
    </a>
    <span class="co-secure"><i class="fas fa-lock"></i> Secure checkout</span>
  </div>
</div>

<div class="co-wrap">
  <div class="co-steps">
    <?php $steps = checkout_steps(); $last = array_key_last($steps); ?>
    <?php foreach ($steps as $n => $s): ?>
      <div class="co-step <?php echo $n < $active ? 'done' : ($n === $active ? 'active' : ''); ?>">
        <span class="n"><?php echo $n < $active ? '<i class="fas fa-check"></i>' : $n; ?></span>
        <span class="lbl"><?php echo h($s['label']); ?></span>
      </div>
      <?php if ($n !== $last): ?><span class="co-sep"></span><?php endif; ?>
    <?php endforeach; ?>
  </div>
<?php
}

/** The order summary card shown beside every step. */
function checkout_summary(array $sum, string $currency, string $cta = '', string $ctaLabel = ''): void
{
    ?>
  <div class="co-sum">
    <div class="co-panel">
      <div class="co-panel-head"><h2>Order Summary</h2></div>
      <div class="co-panel-body">
        <?php if (!$sum['lines']): ?>
          <p style="font-size:13px;color:var(--text-muted);margin:0">Nothing in your order yet.</p>
        <?php else: ?>
          <?php foreach ($sum['lines'] as $l): ?>
            <div class="co-sum-line">
              <span class="l"><?php echo h($l['label']); ?></span>
              <span class="v"><?php echo $l['amount'] > 0 ? h(checkout_money((float) $l['amount'], $currency)) : 'Free'; ?></span>
            </div>
          <?php endforeach; ?>

          <div style="margin-top:12px;padding-top:10px;border-top:1px solid var(--border)">
            <div class="co-sum-sub"><span>Subtotal</span><span><?php echo h(checkout_money($sum['subtotal'], $currency)); ?></span></div>
            <?php if ($sum['vat_rate'] > 0): ?>
              <div class="co-sum-sub"><span>VAT (<?php echo rtrim(rtrim(number_format($sum['vat_rate'], 2, '.', ''), '0'), '.'); ?>%)</span><span><?php echo h(checkout_money($sum['vat'], $currency)); ?></span></div>
            <?php endif; ?>
            <div class="co-sum-total"><span>Total</span><span><?php echo h(checkout_money($sum['total'], $currency)); ?></span></div>
          </div>
        <?php endif; ?>

        <?php if ($cta): ?>
          <button type="submit" form="<?php echo h($cta); ?>" class="btn btn-primary" style="width:100%;justify-content:center;margin-top:16px">
            <?php echo h($ctaLabel ?: 'Continue'); ?> <i class="fas fa-arrow-right"></i>
          </button>
        <?php endif; ?>
      </div>
    </div>
  </div>
    <?php
}

function checkout_foot(): void
{
    ?>
</div>
</body>
</html>
    <?php
}
