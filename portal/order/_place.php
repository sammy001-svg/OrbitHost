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
require_once dirname(__DIR__, 2) . '/admin/includes/ServiceAddon.php';

/**
 * One column the website checkout adds to domain_registrations:
 *   order_mode  register|transfer — which one the client asked for, so
 *               fulfilment knows whether to register or transfer.
 *
 * The auth code goes in the table's existing `epp_code` column rather
 * than a new one: that's the field Integrations → Domains already shows
 * the team, so a transfer ordered here reads the same as one entered by
 * hand instead of showing "EPP Code: Not set" beside a code we do hold.
 *
 * Idempotent, and safe on a database user without ALTER — the caller
 * falls back to writing the row without it.
 */
function order_domain_columns(): bool
{
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
        $col = db()->query("SHOW COLUMNS FROM domain_registrations LIKE 'order_mode'")->fetch();
        if (!$col) {
            db()->exec("ALTER TABLE domain_registrations ADD COLUMN order_mode VARCHAR(20) DEFAULT NULL");
        }
        return $ok = true;
    } catch (\Throwable $e) {
        return $ok = false;
    }
}

/**
 * Record a domain as bought-but-not-yet-registered, tied to the invoice
 * that pays for it. Automation::invoicePaid() submits it once paid.
 *
 * domain_name is UNIQUE. An existing row is only ever taken over when it
 * is this client's own, or an abandoned pending/expired/cancelled one —
 * never someone else's live domain. That case is left alone and flagged
 * for the team rather than silently reassigned.
 */
function record_pending_domain(int $cid, string $domain, string $mode, int $years, int $invoice_id, ?int $order_id, string $epp, bool $idp): void
{
    Automation::ensureSchema();
    $s = db()->prepare('SELECT id, client_id, status FROM domain_registrations WHERE domain_name = ?');
    $s->execute([$domain]);
    $row = $s->fetch();

    if ($row && (int) $row['client_id'] !== $cid && in_array($row['status'], ['active', 'transferred'], true)) {
        log_activity('order_domain_conflict', 'invoice', $invoice_id,
            $domain . ' is already on client #' . $row['client_id'] . ' — not reassigned; check before registering.');
        try {
            Notifier::sendToAllAdmins('order_new_admin', [
                'client_name' => 'Client #' . $cid, 'item' => 'Domain conflict: ' . $domain . ' is already on another client — review invoice before fulfilling',
                'amount' => '—', 'gateway' => 'checkout', 'link' => APP_URL . '/integrations/domains/index.php',
            ]);
        } catch (\Throwable $e) {}
        return;
    }

    $vals = [$cid, $order_id, $invoice_id, $years, $mode, $mode === 'transfer' ? $epp : null, $idp ? 1 : 0];
    try {
        if ($row) {
            db()->prepare("UPDATE domain_registrations SET client_id = ?, order_id = ?, invoice_id = ?, registrar = 'manual',
                               registration_date = CURDATE(), expiry_date = DATE_ADD(CURDATE(), INTERVAL ? YEAR),
                               status = 'pending', order_mode = ?, epp_code = ?, id_protection = ?, lodged_at = NULL WHERE id = ?")
                ->execute(array_merge($vals, [(int) $row['id']]));
        } else {
            db()->prepare("INSERT INTO domain_registrations (client_id, order_id, invoice_id, domain_name, registrar, registration_date, expiry_date,
                                                             status, auto_renew, order_mode, epp_code, id_protection)
                           VALUES (?,?,?,?, 'manual', CURDATE(), DATE_ADD(CURDATE(), INTERVAL ? YEAR), 'pending', 1, ?,?,?)")
                ->execute([$cid, $order_id, $invoice_id, $domain, $years, $mode, $mode === 'transfer' ? $epp : null, $idp ? 1 : 0]);
        }
    } catch (\Throwable $e) {
        // Columns not migrated (no ALTER) — keep the bare record so the
        // domain is at least visible; the invoice lines carry the rest.
        try {
            db()->prepare("INSERT IGNORE INTO domain_registrations (client_id, domain_name, registrar, registration_date, expiry_date, status, auto_renew)
                           VALUES (?,?, 'manual', CURDATE(), DATE_ADD(CURDATE(), INTERVAL ? YEAR), 'pending', 1)")
                ->execute([$cid, $domain, $years]);
        } catch (\Throwable $e2) {}
        error_log('record_pending_domain(' . $domain . '): ' . $e->getMessage());
    }
}

/** Record every cart domain on an invoice. */
function place_cart_domains(array $sum, int $cid, int $invoice_id, string $hosting_domain = '', ?int $order_id = null): array
{
    $names = [];
    $idp = [];
    foreach ($sum['lines'] as $l) {
        if (($l['type'] ?? '') === 'id_protection' && !empty($l['domain'])) $idp[$l['domain']] = true;
    }
    foreach ($sum['lines'] as $l) {
        if (($l['type'] ?? '') !== 'cart_domain') continue;
        record_pending_domain($cid, $l['domain'], $l['mode'], (int) $l['years'], $invoice_id,
            $l['domain'] === $hosting_domain ? $order_id : null, (string) $l['epp'], !empty($idp[$l['domain']]));
        $names[] = $l['domain'];
    }
    return $names;
}

/** Domains with no hosting: one invoice, fulfilled by Automation::invoicePaid(). */
function place_domain_only_order(array $client, string $currency, array $sum): array
{
    $cid    = (int) $client['id'];
    $inv_no = generate_invoice_number();
    db()->prepare("INSERT INTO invoices (invoice_number, client_id, subtotal, tax_rate, tax_amount, total, status, due_date, currency)
                   VALUES (?,?,?,?,?,?,'sent',CURDATE(),?)")
        ->execute([$inv_no, $cid, $sum['subtotal'], $sum['vat_rate'], $sum['vat'], $sum['total'], $currency]);
    $invoice_id = (int) db()->lastInsertId();
    $ins = db()->prepare('INSERT INTO invoice_items (invoice_id, description, quantity, unit_price, total) VALUES (?,?,?,?,?)');
    foreach ($sum['lines'] as $l) {
        $ins->execute([$invoice_id, $l['label'], 1, (float) $l['amount'], (float) $l['amount']]);
    }

    // Committed — nothing below may throw (see place_hosting_order()).
    $names = [];
    try {
        $names = place_cart_domains($sum, $cid, $invoice_id);
        log_activity('order_checkout', 'invoice', $invoice_id, 'Website checkout — domains: ' . implode(', ', $names));
    } catch (\Throwable $e) {
        error_log('place_domain_only_order: post-commit step failed for invoice ' . $invoice_id . ' — ' . $e->getMessage());
    }

    OrderCart::clear();
    return ['order_id' => 0, 'invoice_id' => $invoice_id, 'invoice_number' => $inv_no,
            'plan_name' => '', 'domain' => implode(', ', $names), 'total' => $sum['total'],
            'item' => (count($names) === 1 ? 'Domain ' : 'Domains ') . implode(', ', $names)];
}

/**
 * @return array{order_id:int,invoice_id:int,invoice_number:string}
 * @throws RuntimeException when the cart no longer prices (plan withdrawn mid-checkout)
 */
function place_hosting_order(array $client, string $currency): array
{
    if (!OrderCart::checkoutReady()) {
        throw new RuntimeException('Your cart is empty or incomplete. Please check it and try again.');
    }
    $plan = OrderCart::plan();
    if ((int) OrderCart::get('plan_id', 0) && !$plan) {
        throw new RuntimeException('That package is no longer available. Please start again.');
    }

    $sum = OrderCart::summary($currency);
    $cid = (int) $client['id'];

    // Last word on availability, right before anything is written: a name
    // can be registered by someone else between the search and checkout.
    $taken = [];
    foreach ($sum['lines'] as $l) {
        if (($l['type'] ?? '') === 'cart_domain' && $l['mode'] === 'register' && OrderCart::domainTaken($l['domain']) === true) {
            $taken[] = $l['domain'];
        }
    }
    if ($taken) {
        throw new RuntimeException('Sorry — ' . implode(', ', $taken) . (count($taken) === 1 ? ' was' : ' were')
            . ' registered by someone else a moment ago. Remove ' . (count($taken) === 1 ? 'it' : 'them') . ' from your cart and choose another name.');
    }

    Currency::ensureSchema();
    Automation::ensureSchema();   // invoices.order_id, domain_registrations.invoice_id …

    if (!$plan) {
        return place_domain_only_order($client, $currency, $sum);
    }

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

    // ── Subscribed add-ons ───────────────────────────────────────────
    // The invoice lines above bill them once. These rows are what makes a
    // recurring add-on actually recur: admin/cron/billing.php reads them
    // when it raises the renewal invoice, and the client sees (and can
    // cancel) them on their service. Name/price/cycle are snapshotted so
    // a later catalogue edit never silently reprices an existing client.
    if (ServiceAddon::ensureOrderSchema()) {
        $addIns = db()->prepare('INSERT INTO order_addons (order_id, addon_id, name, billing_cycle, amount, currency)
                                 VALUES (?,?,?,?,?,?)');
        foreach ($sum['lines'] as $l) {
            if (($l['type'] ?? '') !== 'addon') continue;
            $addIns->execute([
                $order_id, $l['addon_id'] ?? null, $l['name'] ?? $l['label'],
                $l['cycle'] ?? 'monthly', (float) $l['amount'], $currency,
            ]);
        }
    }

    // ─────────────────────────────────────────────────────────────────
    // COMMITTED. The order and its invoice now exist, so from here on
    // nothing may throw out of this function: the caller reports a
    // failure by re-rendering the review page, and the client clicking
    // "Complete Order" again would place the whole order a second time.
    // Everything below is a side effect — record it, but never let it
    // turn a successful sale into a duplicate one.
    // ─────────────────────────────────────────────────────────────────
    try {

    // ── Domain record ────────────────────────────────────────────────
    // Registering/transferring is recorded as pending now so the client
    // and the team both see it; it's actually submitted to the registrar
    // once the invoice is paid (Automation::fulfilOrderDomain).
    // Domains bought alongside the package (including the one the hosting
    // uses, when it came from the cart).
    place_cart_domains($sum, $cid, $invoice_id, $domain, $order_id);

    if ($domain !== '' && in_array($mode, ['register', 'transfer'], true)) {
        order_domain_columns();
        record_pending_domain($cid, $domain, $mode, OrderCart::domainYears(), $invoice_id, $order_id,
            $mode === 'transfer' ? (string) OrderCart::get('epp_code', '') : '', (bool) OrderCart::get('id_protection'));
    }

    log_activity('order_checkout', 'order', $order_id, 'Website checkout — ' . $plan['name'] . ' for ' . $domain);

    } catch (\Throwable $e) {
        error_log('place_hosting_order: post-commit step failed for order '
                  . $order_id . ' — ' . $e->getMessage());
    }

    OrderCart::clear();

    $extra = array_values(array_filter(array_map(fn($l) => ($l['type'] ?? '') === 'cart_domain' && $l['domain'] !== $domain ? $l['domain'] : null, $sum['lines'])));
    return [
        'order_id' => $order_id, 'invoice_id' => $invoice_id, 'invoice_number' => $inv_no,
        'plan_name' => (string) $plan['name'], 'domain' => $domain, 'total' => $sum['total'],
        'item' => $plan['name'] . ' (' . $domain . ')' . ($extra ? ' + ' . implode(', ', $extra) : ''),
    ];
}

/**
 * Email the invoice to the client and alert the team.
 *
 * Deliberately NOT part of place_hosting_order(): talking to an SMTP
 * server takes seconds, and two sends can outrun PHP's max_execution_time
 * on a slow or unreachable mail host. That's a fatal, not an exception —
 * no try/catch can save the request — and it would strand the client on an
 * error page for an order that had in fact gone through.
 *
 * So the caller redirects first and calls this afterwards: on FPM the
 * response is already delivered, and mail happens on borrowed time with
 * the client's browser long gone.
 */
function notify_hosting_order(array $placed, array $client, string $currency): void
{
    if (function_exists('fastcgi_finish_request')) {
        @fastcgi_finish_request();   // client has their redirect; keep working
    }
    ignore_user_abort(true);

    try {
        Notifier::sendInvoiceEmail((int) $placed['invoice_id'], 'invoice_new', ['gateway' => 'Online']);
    } catch (\Throwable $e) {
        // The invoice is on their portal regardless, and every attempt is
        // logged in admin → Notifications.
        error_log('notify_hosting_order: invoice email — ' . $e->getMessage());
    }

    $name = trim(($client['first_name'] ?? '') . ' ' . ($client['last_name'] ?? ''));
    try {
        Notifier::sendToAllAdmins('order_new_admin', [
            'client_name' => $name ?: ($client['email'] ?? 'A client'),
            'item'        => $placed['item'] ?? ($placed['plan_name'] . ' (' . $placed['domain'] . ')'),
            'amount'      => $currency . ' ' . number_format((float) $placed['total'], 2),
            'gateway'     => 'Awaiting payment',
            'link'        => APP_URL . '/orders/index.php',
        ]);
    } catch (\Throwable $e) {
        error_log('notify_hosting_order: admin alert — ' . $e->getMessage());
    }
}
