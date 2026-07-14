<?php
/**
 * Orbit Cloud — payment reconciliation
 *
 * Run every 5–10 minutes via cPanel Cron Jobs (alongside reminders.php
 * and billing.php):
 *   /usr/local/bin/php /home/USERNAME/public_html/admin/cron/payments.php
 * or over HTTP with CRON_SECRET set in .env:
 *   https://yourdomain.com/admin/cron/payments.php?token=YOUR_SECRET
 *
 * Every payment confirmation in this app — checkout, service orders,
 * domain renewals/transfers, admin billing — ultimately depends on
 * either the client's browser landing back on a return URL, or a
 * gateway webhook firing. Both can fail to happen: closed tabs, a
 * dropped connection, an STK push approved 20 minutes later, a webhook
 * that never arrives. This is the safety net — it finds every payment
 * still "pending" and asks Automation::settlePayment() to check and,
 * if paid, fulfil it. That one function is exactly what the client's
 * own return-URL page and any gateway webhook already call, so a
 * client who never comes back still gets their order/renewal/transfer
 * completed, and no client is ever left stuck because they closed a tab.
 *
 * A grace window avoids racing a client who's mid-checkout right now:
 * only payments a few minutes old are touched. An upper bound (48h)
 * stops the query scanning ancient abandoned attempts forever — those
 * are surfaced to admins instead (see the "stale" count below) for a
 * manual look in Billing.
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/Notifier.php';
require_once __DIR__ . '/../includes/Automation.php';

$min_age_minutes = 3;
$max_age_hours   = 48;

$is_cli = PHP_SAPI === 'cli';
if (!$is_cli) {
    $secret = defined('CRON_SECRET') ? CRON_SECRET : '';
    if ($secret === '' || !hash_equals($secret, $_GET['token'] ?? '')) {
        http_response_code(403);
        exit("Forbidden. Set CRON_SECRET in .env and pass ?token=<secret>, or run via CLI.\n");
    }
    header('Content-Type: text/plain');
}

$pending = db()->query(
    "SELECT id FROM payments
     WHERE status = 'pending'
       AND gateway NOT IN ('bank_transfer', 'mpesa_manual', 'cheque')
       AND created_at <= DATE_SUB(NOW(), INTERVAL {$min_age_minutes} MINUTE)
       AND created_at >= DATE_SUB(NOW(), INTERVAL {$max_age_hours} HOUR)"
)->fetchAll(PDO::FETCH_COLUMN);

$settled = 0; $failed = 0; $stillPending = 0; $errors = [];

foreach ($pending as $payment_id) {
    try {
        $r = Automation::settlePayment((int) $payment_id);
        if ($r['status'] === 'completed') $settled++;
        elseif ($r['status'] === 'failed') $failed++;
        else $stillPending++;
    } catch (\Throwable $e) {
        $errors[] = "payment#$payment_id: " . $e->getMessage();
    }
}

// Genuinely stale attempts (older than the window, still pending) — these
// are past the point of expecting a late webhook/STK approval, so flag
// them for a human rather than keep silently retrying forever.
$stale = (int) db()->query(
    "SELECT COUNT(*) FROM payments
     WHERE status = 'pending'
       AND gateway NOT IN ('bank_transfer', 'mpesa_manual', 'cheque')
       AND created_at < DATE_SUB(NOW(), INTERVAL {$max_age_hours} HOUR)"
)->fetchColumn();
if ($stale > 0) {
    Notifier::sendToAllAdmins('order_new_admin', [
        'client_name' => 'System', 'item' => $stale . ' payment(s) still pending after ' . $max_age_hours . 'h — likely abandoned',
        'amount' => '—', 'gateway' => 'reconciliation',
        'link' => APP_URL . '/billing/',
    ]);
}

$summary = sprintf(
    "[%s] Payment reconciliation — checked: %d, settled: %d, failed: %d, still pending: %d, stale(>%dh): %d%s\n",
    date('Y-m-d H:i:s'), count($pending), $settled, $failed, $stillPending, $max_age_hours, $stale,
    $errors ? ' | ERRORS: ' . implode(' ; ', $errors) : ''
);
echo $summary;
