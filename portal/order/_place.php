<?php
/**
 * Orbit Cloud — turn a finished OrderCart into a real order + invoice.
 *
 * Called once, from step 4, after the client has an account. Everything
 * it writes comes from OrderCart::summary(), so the invoice lines are by
 * construction the lines the client was shown at every earlier step.
 *
 * The order is created as `pending`: it appears in the client's Services
 * list straight away, and Automation::invoicePaid() provisions it when
 * the invoice is settled — from whichever page takes the payment.
 */
require_once dirname(__DIR__, 2) . '/admin/includes/functions.php';
require_once dirname(__DIR__, 2) . '/admin/includes/Automation.php';
require_once dirname(__DIR__, 2) . '/admin/includes/Notifier.php';
require_once dirname(__DIR__, 2) . '/admin/includes/Currency.php';
require_once dirname(__DIR__) . '/includes/order_cart.php';

/**
 * Columns the website checkout needs on domain_registrations:
 *   order_mode    register|transfer — which one the client asked for, so
 *                 fulfilment knows whether to register or transfer.
 *   transfer_epp  the auth code, held only until the transfer is lodged
 *                 (Automation clears it on success).
 * Idempotent, and safe on a database user without ALTER — the caller
 * falls back to writing the row without them.
 */
function order_domain_columns(): bool
{
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
        $col = db()->query("SHOW COLUMNS FROM domain_registrations LIKE 'order_mode'")->fetch();
        if (!$col) {
            db()->exec("ALTER TABLE domain_registrations
                        ADD COLUMN order_mode   VARCHAR(20)  DEFAULT NULL,
                        ADD COLUMN transfer_epp VARCHAR(255) DEFAULT NULL");
        }
        return $ok = true;
    } catch (\Throwable $e) {
        return $ok = false;
    }
}

/**
 * @return array{order_id:int,invoice_id:int,invoice_number:string}
 * @throws RuntimeException when the cart no longer prices (plan withdrawn mid-checkout)
 */
function place_hosting_order(array $client, string $currency): array
{
    $plan = OrderCart::plan();
    if (!$plan) {
        throw new RuntimeException('That package is no longer available. Please start again.');
    }

    $sum = OrderCart::summary($currency);
    $cid = (int) $client['id'];

    Currency::ensureSchema();
    Automation::ensureSchema();   // guarantees invoices.order_id exists

    // ── The order ────────────────────────────────────────────────────
    // orders.amount is the RECURRING figure — what a renewal invoice will
    // be for — so it's the plan price alone, never the one-off checkout
    // total (which carries setup fees and the domain).
    $amt   = Currency::planAmount($plan, $currency);
    $cycle = (string) $plan['billing_cycle'];
    $next  = $cycle === 'monthly' ? date('Y-m-d', strtotime('+1 month'))
           : ($cycle === 'annual' ? date('Y-m-d', strtotime('+1 year')) : null);

    $domain = (string) OrderCart::get('domain_name', '');
    $mode   = (string) OrderCart::get('domain_mode', '');

    $notes = 'Ordered from the website checkout. Domain: ' . $domain . ' (' . $mode . ').';
    if (OrderCart::get('id_protection')) $notes .= ' ID Protection requested.';

    db()->prepare('INSERT INTO orders (client_id, service_id, service_name, domain_name, amount, billing_cycle, status, start_date, next_due, notes, currency)
                   VALUES (?,?,?,?,?,?,?,CURDATE(),?,?,?)')
        ->execute([$cid, (int) $plan['id'], $plan['name'], $domain ?: null, (float) $amt['price'], $cycle,
                   'pending', $next, $notes, $currency]);
    $order_id = (int) db()->lastInsertId();

    // ── The invoice ──────────────────────────────────────────────────
    $inv_no = generate_invoice_number();
    db()->prepare("INSERT INTO invoices (invoice_number, client_id, order_id, subtotal, tax_rate, tax_amount, total, status, due_date, currency)
                   VALUES (?,?,?,?,?,?,?,'sent',CURDATE(),?)")
        ->execute([$inv_no, $cid, $order_id, $sum['subtotal'], $sum['vat_rate'], $sum['vat'], $sum['total'], $currency]);
    $invoice_id = (int) db()->lastInsertId();

    $ins = db()->prepare('INSERT INTO invoice_items (invoice_id, description, quantity, unit_price, total) VALUES (?,?,?,?,?)');
    foreach ($sum['lines'] as $l) {
        $ins->execute([$invoice_id, $l['label'], 1, (float) $l['amount'], (float) $l['amount']]);
    }

    // ── Domain record ────────────────────────────────────────────────
    // Registering/transferring is recorded as pending now so the client
    // and the team both see it; it's actually submitted to the registrar
    // once the invoice is paid (Automation::fulfilOrderDomain).
    if ($domain !== '' && in_array($mode, ['register', 'transfer'], true)) {
        $years = OrderCart::domainYears();
        order_domain_columns();
        try {
            db()->prepare('INSERT INTO domain_registrations (client_id, domain_name, registrar, registration_date, expiry_date, status, auto_renew, order_mode, transfer_epp)
                           VALUES (?,?,?,CURDATE(),DATE_ADD(CURDATE(), INTERVAL ? YEAR),?,1,?,?)
                           ON DUPLICATE KEY UPDATE client_id = VALUES(client_id), order_mode = VALUES(order_mode), transfer_epp = VALUES(transfer_epp)')
                ->execute([$cid, $domain, 'manual', $years, 'pending', $mode,
                           $mode === 'transfer' ? (string) OrderCart::get('epp_code', '') : null]);
        } catch (\Throwable $e) {
            // Legacy schema without the two columns — still record the domain
            // so the client and the team see it; the order notes carry the rest.
            try {
                db()->prepare('INSERT INTO domain_registrations (client_id, domain_name, registrar, registration_date, expiry_date, status, auto_renew)
                               VALUES (?,?,?,CURDATE(),DATE_ADD(CURDATE(), INTERVAL ? YEAR),?,1)
                               ON DUPLICATE KEY UPDATE client_id = VALUES(client_id)')
                    ->execute([$cid, $domain, 'manual', $years, 'pending']);
            } catch (\Throwable $e2) { /* order and invoice already exist either way */ }
        }
    }

    // ── Tell the client (in-app + the invoice email) ─────────────────
    try {
        Notifier::sendInvoiceEmail($invoice_id, 'invoice_new', ['gateway' => 'Online']);
    } catch (\Throwable $e) {
        // A mail failure must not lose the order — the invoice is on their
        // portal either way, and admin/notifications logs the attempt.
    }

    $client_name = trim(($client['first_name'] ?? '') . ' ' . ($client['last_name'] ?? ''));
    try {
        Notifier::sendToAllAdmins('order_new_admin', [
            'client_name' => $client_name ?: ($client['email'] ?? 'A client'),
            'item'        => $plan['name'] . ' (' . $domain . ')',
            'amount'      => $currency . ' ' . number_format($sum['total'], 2),
            'gateway'     => 'Awaiting payment',
            'link'        => APP_URL . '/orders/index.php',
        ]);
    } catch (\Throwable $e) { /* non-fatal */ }

    log_activity('order_checkout', 'order', $order_id, 'Website checkout — ' . $plan['name'] . ' for ' . $domain);

    OrderCart::clear();

    return ['order_id' => $order_id, 'invoice_id' => $invoice_id, 'invoice_number' => $inv_no];
}
