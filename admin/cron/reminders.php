<?php
/**
 * Orbit Cloud — scheduled reminders (renewal, expiry, overdue invoices)
 *
 * Run once daily via cPanel Cron Jobs:
 *   /usr/local/bin/php /home/USERNAME/public_html/admin/cron/reminders.php
 *
 * If your host's cron can only hit a URL (curl/wget instead of running
 * php directly), set CRON_SECRET in .env and call:
 *   https://yourdomain.com/admin/cron/reminders.php?token=YOUR_SECRET
 * Without CRON_SECRET set, HTTP access is refused entirely — CLI always
 * works with no token needed.
 *
 * Idempotent: reminder_log (entity_type, entity_id, milestone) is
 * UNIQUE, so re-running the same day (or after a missed day) never
 * sends the same milestone twice — it only sends what's newly due.
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/Notifier.php';

$is_cli = PHP_SAPI === 'cli';
if (!$is_cli) {
    $secret = defined('CRON_SECRET') ? CRON_SECRET : '';
    if ($secret === '' || !hash_equals($secret, $_GET['token'] ?? '')) {
        http_response_code(403);
        exit("Forbidden. Set CRON_SECRET in .env and pass ?token=<secret>, or run via CLI.\n");
    }
    header('Content-Type: text/plain');
}

Notifier::ensureTables();

$counts = ['renewal' => 0, 'expiry' => 0, 'overdue' => 0];

// Descending so a cron that missed several days still "catches up" on
// every milestone crossed since the last run, instead of skipping them.
$milestones = [
    30 => ['type' => 'service_renewal_reminder', 'key' => '30d'],
    14 => ['type' => 'service_renewal_reminder', 'key' => '14d'],
    7  => ['type' => 'service_expiry_reminder',  'key' => '7d'],
    3  => ['type' => 'service_expiry_reminder',  'key' => '3d'],
    1  => ['type' => 'service_expiry_reminder',  'key' => '1d'],
    0  => ['type' => 'service_expiry_reminder',  'key' => '0d'],
];

/** Claim a milestone (returns true only the first time it's claimed). */
function claim_milestone(string $entityType, int $entityId, string $milestone): bool
{
    try {
        db()->prepare('INSERT INTO reminder_log (entity_type, entity_id, milestone) VALUES (?,?,?)')
            ->execute([$entityType, $entityId, $milestone]);
        return true;
    } catch (\Throwable $e) {
        return false; // already sent (UNIQUE constraint) or a transient error
    }
}

function process_expiry(string $entityType, int $entityId, int $daysLeft, array $vars, array $milestones, array &$counts): void
{
    foreach ($milestones as $days => $m) {
        if ($daysLeft > $days) continue;
        if (!claim_milestone($entityType, $entityId, $m['key'])) continue;
        Notifier::send($m['type'], (int) $vars['client_id'], array_merge($vars, [
            'days_left' => max(0, $daysLeft),
        ]));
        $counts[$m['type'] === 'service_renewal_reminder' ? 'renewal' : 'expiry']++;
        break; // one reminder per run per entity — the largest newly-crossed milestone
    }
}

// ── Domains ──
$domains = db()->query(
    "SELECT dr.id, dr.domain_name, dr.expiry_date, dr.client_id, c.first_name, c.email
     FROM domain_registrations dr JOIN clients c ON c.id = dr.client_id
     WHERE dr.status = 'active' AND dr.expiry_date IS NOT NULL
       AND dr.expiry_date BETWEEN DATE_SUB(CURDATE(), INTERVAL 3 DAY) AND DATE_ADD(CURDATE(), INTERVAL 31 DAY)"
)->fetchAll();
foreach ($domains as $d) {
    $days_left = (int) ceil((strtotime($d['expiry_date']) - strtotime(date('Y-m-d'))) / 86400);
    process_expiry('domain', (int) $d['id'], $days_left, [
        'client_id' => $d['client_id'], 'client_name' => $d['first_name'],
        'item' => $d['domain_name'], 'expiry_date' => format_date($d['expiry_date']),
        'email' => $d['email'], 'link' => portal_base_url() . '/domains.php',
    ], $milestones, $counts);
}

// ── Hosting / other services (client_services.next_due_date) ──
try {
    $services = db()->query(
        "SELECT cs.id, cs.label, cs.next_due_date, cs.client_id, c.first_name, c.email
         FROM client_services cs JOIN clients c ON c.id = cs.client_id
         WHERE cs.status = 'active' AND cs.next_due_date IS NOT NULL
           AND cs.next_due_date BETWEEN DATE_SUB(CURDATE(), INTERVAL 3 DAY) AND DATE_ADD(CURDATE(), INTERVAL 31 DAY)"
    )->fetchAll();
} catch (\Throwable $e) {
    $services = []; // client_services not migrated yet
}
foreach ($services as $s) {
    $days_left = (int) ceil((strtotime($s['next_due_date']) - strtotime(date('Y-m-d'))) / 86400);
    process_expiry('service', (int) $s['id'], $days_left, [
        'client_id' => $s['client_id'], 'client_name' => $s['first_name'],
        'item' => $s['label'], 'expiry_date' => format_date($s['next_due_date']),
        'email' => $s['email'], 'link' => portal_base_url() . '/services.php',
    ], $milestones, $counts);
}

// ── Overdue invoices (fires once per invoice, not daily) ──
$overdue = db()->query(
    "SELECT i.id, i.invoice_number, i.total, i.due_date, i.client_id, c.first_name, c.email
     FROM invoices i JOIN clients c ON c.id = i.client_id
     WHERE i.status = 'sent' AND i.due_date < CURDATE()"
)->fetchAll();
foreach ($overdue as $inv) {
    db()->prepare("UPDATE invoices SET status = 'overdue' WHERE id = ? AND status = 'sent'")->execute([$inv['id']]);
    if (!claim_milestone('invoice', (int) $inv['id'], 'overdue')) continue;
    Notifier::send('invoice_overdue', (int) $inv['client_id'], [
        'client_name' => $inv['first_name'], 'invoice_number' => $inv['invoice_number'],
        'amount' => format_money((float) $inv['total']), 'due_date' => format_date($inv['due_date']),
        'email' => $inv['email'], 'link' => portal_base_url() . '/invoices/',
    ]);
    $counts['overdue']++;
}

$summary = sprintf(
    "[%s] Reminders run complete — renewal: %d, expiry: %d, overdue invoices: %d\n",
    date('Y-m-d H:i:s'), $counts['renewal'], $counts['expiry'], $counts['overdue']
);
echo $summary;
