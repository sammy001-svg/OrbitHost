<?php
/**
 * Orbit Cloud — automated renewal billing + overdue suspension
 *
 * Run once daily via cPanel Cron Jobs (alongside reminders.php):
 *   /usr/local/bin/php /home/USERNAME/public_html/admin/cron/billing.php
 * or over HTTP with CRON_SECRET set in .env:
 *   https://yourdomain.com/admin/cron/billing.php?token=YOUR_SECRET
 *
 * What it does, idempotently:
 *   1. RENEWAL INVOICES — every active recurring order whose next_due is
 *      within RENEW_LEAD_DAYS gets one invoice (due on next_due), linked
 *      via invoices.order_id. Re-runs never duplicate: one invoice per
 *      (order, due_date). The same happens for client_services created
 *      directly (services/create.php, no order behind them) via
 *      invoices.client_service_id — otherwise those services would never
 *      be billed automatically at all.
 *   2. SUSPENSION — orders (or order-less services) more than GRACE_DAYS
 *      past next_due with that renewal invoice still unpaid are suspended
 *      (panel account too, when one exists) and the client is notified.
 *
 * Reactivation is NOT done here — it happens the moment the invoice is
 * paid (Automation::invoicePaid is hooked into every payment path), so
 * a manually-suspended account can never be un-suspended by this cron.
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/Notifier.php';
require_once __DIR__ . '/../includes/Automation.php';

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
Automation::ensureSchema();

$made = 0; $suspended = 0; $errors = [];

// ── 1. Generate renewal invoices ─────────────────────────────
$due = db()->query(
    "SELECT o.*, c.first_name, c.email
     FROM orders o JOIN clients c ON c.id = o.client_id
     WHERE o.status = 'active'
       AND o.billing_cycle IN ('monthly','annual')
       AND o.next_due IS NOT NULL
       AND o.next_due <= DATE_ADD(CURDATE(), INTERVAL " . Automation::RENEW_LEAD_DAYS . " DAY)
       AND o.amount > 0
       AND NOT EXISTS (SELECT 1 FROM invoices i WHERE i.order_id = o.id AND i.due_date = o.next_due)"
)->fetchAll();

foreach ($due as $o) {
    try {
        $inv_no = generate_invoice_number();
        db()->prepare("INSERT INTO invoices (invoice_number, client_id, order_id, subtotal, tax_rate, tax_amount, total, status, due_date, currency)
                       VALUES (?,?,?,?,0,0,?, 'sent', ?,?)")
            ->execute([$inv_no, $o['client_id'], $o['id'], $o['amount'], $o['amount'], $o['next_due'], $o['currency'] ?? 'USD']);
        $invoice_id = (int) db()->lastInsertId();

        $desc = 'Renewal: ' . ($o['service_name'] ?: 'Service')
              . ($o['domain_name'] ? ' — ' . $o['domain_name'] : '')
              . ' (' . str_replace('_', ' ', $o['billing_cycle']) . ')';
        db()->prepare('INSERT INTO invoice_items (invoice_id, description, quantity, unit_price, total) VALUES (?,?,1,?,?)')
            ->execute([$invoice_id, $desc, $o['amount'], $o['amount']]);

        Notifier::sendInvoiceEmail($invoice_id, 'invoice_new');
        $made++;
    } catch (\Throwable $e) {
        $errors[] = 'invoice order#' . $o['id'] . ': ' . $e->getMessage();
    }
}

// ── 2. Suspend overdue orders ────────────────────────────────
$overdue = db()->query(
    "SELECT o.*, c.first_name, c.email
     FROM orders o JOIN clients c ON c.id = o.client_id
     WHERE o.status = 'active'
       AND o.billing_cycle IN ('monthly','annual')
       AND o.next_due IS NOT NULL
       AND o.next_due < DATE_SUB(CURDATE(), INTERVAL " . Automation::GRACE_DAYS . " DAY)
       AND EXISTS (SELECT 1 FROM invoices i WHERE i.order_id = o.id AND i.status IN ('sent','overdue'))"
)->fetchAll();

foreach ($overdue as $o) {
    try {
        Automation::suspendOrder($o);
        $suspended++;
    } catch (\Throwable $e) {
        $errors[] = 'suspend order#' . $o['id'] . ': ' . $e->getMessage();
    }
}

// ── 3. Renewal invoices + suspension for order-less services ────
// client_services created directly via services/create.php (no
// order_id) were previously invisible to both of the steps above —
// they'd renew forever unbilled, or run unpaid forever unsuspended.
try {
    $due_cs = db()->query(
        "SELECT cs.*, c.first_name, c.email
         FROM client_services cs JOIN clients c ON c.id = cs.client_id
         WHERE cs.order_id IS NULL
           AND cs.status = 'active'
           AND cs.billing_cycle IN ('monthly','annual')
           AND cs.next_due_date IS NOT NULL
           AND cs.next_due_date <= DATE_ADD(CURDATE(), INTERVAL " . Automation::RENEW_LEAD_DAYS . " DAY)
           AND cs.amount > 0
           AND NOT EXISTS (SELECT 1 FROM invoices i WHERE i.client_service_id = cs.id AND i.due_date = cs.next_due_date)"
    )->fetchAll();
} catch (\Throwable $e) {
    $due_cs = []; // client_service_id column not migrated yet (needs ALTER privilege)
}

foreach ($due_cs as $cs) {
    try {
        $inv_no = generate_invoice_number();
        db()->prepare("INSERT INTO invoices (invoice_number, client_id, client_service_id, subtotal, tax_rate, tax_amount, total, status, due_date, currency)
                       VALUES (?,?,?,?,0,0,?, 'sent', ?,?)")
            ->execute([$inv_no, $cs['client_id'], $cs['id'], $cs['amount'], $cs['amount'], $cs['next_due_date'], $cs['currency'] ?? 'USD']);
        $invoice_id = (int) db()->lastInsertId();

        $desc = 'Renewal: ' . ($cs['label'] ?: 'Service')
              . ($cs['domain'] ? ' — ' . $cs['domain'] : '')
              . ' (' . str_replace('_', ' ', $cs['billing_cycle']) . ')';
        db()->prepare('INSERT INTO invoice_items (invoice_id, description, quantity, unit_price, total) VALUES (?,?,1,?,?)')
            ->execute([$invoice_id, $desc, $cs['amount'], $cs['amount']]);

        Notifier::sendInvoiceEmail($invoice_id, 'invoice_new');
        $made++;
    } catch (\Throwable $e) {
        $errors[] = 'invoice service#' . $cs['id'] . ': ' . $e->getMessage();
    }
}

try {
    $overdue_cs = db()->query(
        "SELECT cs.*, c.first_name, c.email
         FROM client_services cs JOIN clients c ON c.id = cs.client_id
         WHERE cs.order_id IS NULL
           AND cs.status = 'active'
           AND cs.billing_cycle IN ('monthly','annual')
           AND cs.next_due_date IS NOT NULL
           AND cs.next_due_date < DATE_SUB(CURDATE(), INTERVAL " . Automation::GRACE_DAYS . " DAY)
           AND EXISTS (SELECT 1 FROM invoices i WHERE i.client_service_id = cs.id AND i.status IN ('sent','overdue'))"
    )->fetchAll();
} catch (\Throwable $e) {
    $overdue_cs = [];
}

foreach ($overdue_cs as $cs) {
    try {
        Automation::suspendService($cs);
        $suspended++;
    } catch (\Throwable $e) {
        $errors[] = 'suspend service#' . $cs['id'] . ': ' . $e->getMessage();
    }
}

$summary = sprintf(
    "[%s] Billing run complete — renewal invoices: %d, suspended: %d%s\n",
    date('Y-m-d H:i:s'), $made, $suspended,
    $errors ? ' | ERRORS: ' . implode(' ; ', $errors) : ''
);
echo $summary;
