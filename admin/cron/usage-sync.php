<?php
/**
 * Orbit Cloud — automated hosting usage sync (disk / bandwidth)
 *
 * Run daily via cPanel Cron Jobs (alongside reminders.php, billing.php,
 * payments.php, backup.php):
 *   /usr/local/bin/php /home/USERNAME/public_html/admin/cron/usage-sync.php
 * or over HTTP with CRON_SECRET set in .env:
 *   https://yourdomain.com/admin/cron/usage-sync.php?token=YOUR_SECRET
 *
 * client_services.disk_used_mb/disk_limit_mb/bw_used_mb already power
 * real usage meters in both admin/services and the client portal — but
 * until now the only way to refresh them was an admin clicking "Sync"
 * on one service at a time from admin/services/view.php. This walks
 * every active panel-backed service and does the same sync automatically.
 *
 * A short usleep() between calls avoids hammering a single WHM/Plesk
 * server with a tight loop of API requests — there's no rate-limiting
 * in PanelClient itself, and a handful of accounts sharing one server
 * could otherwise trigger the panel's own throttling.
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/providers/Provider.php';

$is_cli = PHP_SAPI === 'cli';
if (!$is_cli) {
    $secret = defined('CRON_SECRET') ? CRON_SECRET : '';
    if ($secret === '' || !hash_equals($secret, $_GET['token'] ?? '')) {
        http_response_code(403);
        exit("Forbidden. Set CRON_SECRET in .env and pass ?token=<secret>, or run via CLI.\n");
    }
    header('Content-Type: text/plain');
}

$synced = 0; $failed = 0; $errors = [];

try {
    $services = db()->query(
        "SELECT id, provider_key, remote_id, username
         FROM client_services
         WHERE status = 'active' AND provider_category = 'panel' AND provider_key IS NOT NULL
           AND (remote_id IS NOT NULL OR username IS NOT NULL)"
    )->fetchAll();
} catch (\Throwable $e) {
    $services = [];
    $errors[] = 'Could not load services: ' . $e->getMessage();
}

foreach ($services as $svc) {
    try {
        $acct_user = $svc['remote_id'] ?: $svc['username'];
        $u = Provider::panel($svc['provider_key'])->getUsage($acct_user);

        if (!empty($u['success'])) {
            db()->prepare('UPDATE client_services SET disk_used_mb=?, disk_limit_mb=?, bw_used_mb=?, last_synced_at=NOW() WHERE id=?')
                ->execute([$u['disk_used_mb'] ?? 0, $u['disk_limit_mb'] ?? 0, $u['bw_used_mb'] ?? 0, $svc['id']]);
            db()->prepare('INSERT INTO service_actions (service_id, admin_id, action, status, message) VALUES (?,NULL,?,?,?)')
                ->execute([$svc['id'], 'sync', 'success', 'Auto-synced by usage-sync cron.']);
            $synced++;
        } else {
            db()->prepare('INSERT INTO service_actions (service_id, admin_id, action, status, message) VALUES (?,NULL,?,?,?)')
                ->execute([$svc['id'], 'sync', 'failed', $u['message'] ?? 'Usage sync failed.']);
            $errors[] = 'service#' . $svc['id'] . ': ' . ($u['message'] ?? 'unknown error');
            $failed++;
        }
    } catch (\Throwable $e) {
        $errors[] = 'service#' . $svc['id'] . ': ' . $e->getMessage();
        $failed++;
    }
    usleep(300000); // 300ms — spread requests out instead of hammering one panel server
}

$summary = sprintf(
    "[%s] Usage sync complete — synced: %d, failed: %d%s\n",
    date('Y-m-d H:i:s'), $synced, $failed,
    $errors ? ' | ERRORS: ' . implode(' ; ', array_slice($errors, 0, 10)) : ''
);
echo $summary;
