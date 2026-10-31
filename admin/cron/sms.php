<?php
/**
 * Orbit Cloud — SMS queue (Shanfix SMS)
 *
 * Sends queued campaign messages and retries failed notifications, then
 * pulls delivery reports. Run EVERY MINUTE via cPanel Cron Jobs:
 *   * * * * * /usr/local/bin/php /home/USERNAME/public_html/admin/cron/sms.php
 *
 * URL-only crons: set CRON_SECRET in .env and call
 *   https://yourdomain.com/admin/cron/sms.php?token=YOUR_SECRET
 *
 * Each run stays under the platform's per-minute limits (50 single sends,
 * 8 bulk calls), and a DB lock stops overlapping runs from double-sending.
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/Sms.php';

$is_cli = PHP_SAPI === 'cli';
if (!$is_cli) {
    $secret = defined('CRON_SECRET') ? CRON_SECRET : '';
    if ($secret === '' || !hash_equals($secret, $_GET['token'] ?? '')) {
        http_response_code(403);
        exit("Forbidden. Set CRON_SECRET in .env and pass ?token=<secret>, or run via CLI.\n");
    }
    header('Content-Type: text/plain');
}

if (!Sms::provider()) {
    echo "Shanfix SMS is not enabled — nothing to do.\n";
    exit(0);
}

$q = Sms::processQueue();
if (!empty($q['locked'])) {
    echo "Another run is working the queue — skipped.\n";
    exit(0);
}
$s = Sms::syncStatuses();

printf("[%s] sent %d, failed %d, retrying %d | delivery: %d delivered, %d failed\n",
    date('Y-m-d H:i:s'), $q['sent'], $q['failed'], $q['retrying'], $s['delivered'], $s['failed']);
