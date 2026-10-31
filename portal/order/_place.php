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

/** Stamp the promo code on the invoice and count its use. Never throws. */
function invoice_apply_promo(int $invoice_id, array $sum): void
{
    foreach ($sum['lines'] as $l) {
        if (($l['type'] ?? '') !== 'discount') continue;
        try {
            require_once dirname(__DIR__, 2) . '/admin/includes/Coupon.php';
            Coupon::ensureInvoiceColumns();
            // Code only: the discount is already an invoice line, and the
            // invoice pages print discount_amount as a second "Discount" row.
            db()->prepare('UPDATE invoices SET coupon_code = ? WHERE id = ?')->execute([$l['code'], $invoice_id]);
            Coupon::redeem((int) $l['coupon_id']);
        } catch (\Throwable $e) { /* the discount line is on the invoice regardless */ }
    }
}

/**
 * Record every cart domain on an invoice.
 * @param array $order_for_domain [domain => order id] for domains a hosting package uses
 */
function place_cart_domains(array $sum, int $cid, int $invoice_id, array $order_for_domain = []): array
{
    $names = [];
    $idp = [];
    foreach ($sum['lines'] as $l) {
        if (($l['type'] ?? '') === 'id_protection' && !empty($l['domain'])) $idp[$l['domain']] = true;
    }
    foreach ($sum['lines'] as $l) {
        if (($l['type'] ?? '') !== 'cart_domain') continue;
        record_pending_domain($cid, $l['domain'], $l['mode'], (int) $l['years'], $invoice_id,
            $order_for_domain[$l['domain']] ?? null, (string) $l['epp'], !empty($idp[$l['domain']]));
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
    invoice_apply_promo($invoice_id, $sum);

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
 * Turn the whole cart into ONE invoice: one order per hosting package
 * (each provisioned on its own once paid) plus every domain item.
 *
 * @return array{order_id:int,invoice_id:int,invoice_number:string}
 * @throws RuntimeException when the cart no longer prices (plan withdrawn mid-checkout)
 */
function place_hosting_order(array $client, string $currency): array
{
    if (!OrderCart::checkoutReady()) {
        throw new RuntimeException('Your cart is empty or incomplete. Please check it and try again.');
    }
    foreach (OrderCart::hostingItems() as $it) {
        if (!OrderCart::planById((int) ($it['plan_id'] ?? 0))) {
            throw new RuntimeException('One of the hosting packages in your cart is no longer available. Remove it from your cart and try again.');
        }
    }

    $packages = OrderCart::allHosting();
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
    foreach ($packages as $pk) {
        if ($pk['domain_mode'] === 'register' && OrderCart::domainTaken($pk['domain_name']) === true) $taken[] = $pk['domain_name'];
    }
    if ($taken) {
        throw new RuntimeException('Sorry — ' . implode(', ', $taken) . (count($taken) === 1 ? ' was' : ' were')
            . ' registered by someone else a moment ago. Remove ' . (count($taken) === 1 ? 'it' : 'them') . ' from your cart and choose another name.');
    }

    Currency::ensureSchema();
    Automation::ensureSchema();   // invoices.order_id, orders.checkout_invoice_id, domain_registrations.invoice_id …

    if (!$packages) {
        return place_domain_only_order($client, $currency, $sum);
    }

    // ── Invoice + orders, all or nothing ─────────────────────────────
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $inv_no = generate_invoice_number();
        $pdo->prepare("INSERT INTO invoices (invoice_number, client_id, subtotal, tax_rate, tax_amount, total, status, due_date, currency)
                       VALUES (?,?,?,?,?,?,'sent',CURDATE(),?)")
            ->execute([$inv_no, $cid, $sum['subtotal'], $sum['vat_rate'], $sum['vat'], $sum['total'], $currency]);
        $invoice_id = (int) $pdo->lastInsertId();

        $ins = $pdo->prepare('INSERT INTO invoice_items (invoice_id, description, quantity, unit_price, total) VALUES (?,?,?,?,?)');
        foreach ($sum['lines'] as $l) {
            $ins->execute([$invoice_id, $l['label'], 1, (float) $l['amount'], (float) $l['amount']]);
        }

        // One order per package. orders.amount is the RECURRING figure —
        // what a renewal invoice will be for — so the plan price alone,
        // never setup fees or the domain.
        $order_ids = [];   // uid => order id
        $order_for_domain = [];
        $has_link = true;
        foreach ($packages as $uid => $pk) {
            $plan  = $pk['plan'];
            $amt   = Currency::planAmount($plan, $currency);
            $cycle = (string) $plan['billing_cycle'];
            $next  = $cycle === 'monthly' ? date('Y-m-d', strtotime('+1 month'))
                   : ($cycle === 'annual' ? date('Y-m-d', strtotime('+1 year')) : null);
            $notes = 'Ordered from the website checkout (invoice ' . $inv_no . '). Domain: ' . $pk['domain_name'] . ' (' . $pk['domain_mode'] . ').'
                   . (!empty($pk['id_protection']) ? ' ID Protection requested.' : '');
            $vals = [$cid, (int) $plan['id'], $plan['name'], $pk['domain_name'] ?: null, (float) $amt['price'], $cycle, 'pending', $next, $notes, $currency];
            if ($has_link) {
                // checkout_invoice_id is how paying this invoice finds every
                // package on it — invoices.order_id can only name one.
                try {
                    $pdo->prepare('INSERT INTO orders (client_id, service_id, service_name, domain_name, amount, billing_cycle, status, start_date, next_due, notes, currency, checkout_invoice_id)
                                   VALUES (?,?,?,?,?,?,?,CURDATE(),?,?,?,?)')->execute(array_merge($vals, [$invoice_id]));
                } catch (\PDOException $e) {
                    if (!str_contains($e->getMessage(), 'checkout_invoice_id')) throw $e;
                    $has_link = false; // column not migrated: only the first package auto-provisions
                }
            }
            if (!$has_link) {
                $pdo->prepare('INSERT INTO orders (client_id, service_id, service_name, domain_name, amount, billing_cycle, status, start_date, next_due, notes, currency)
                               VALUES (?,?,?,?,?,?,?,CURDATE(),?,?,?)')->execute($vals);
            }
            $order_ids[$uid] = (int) $pdo->lastInsertId();
            if ($pk['domain_name'] !== '') $order_for_domain[$pk['domain_name']] = $order_ids[$uid];
        }
        $first_order = (int) reset($order_ids);
        $pdo->prepare('UPDATE invoices SET order_id = ? WHERE id = ?')->execute([$first_order, $invoice_id]);
        $pdo->commit();
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('place_hosting_order: ' . $e->getMessage());
        throw new RuntimeException('We couldn\'t place your order just now — nothing was charged. Please try again in a moment.');
    }
    invoice_apply_promo($invoice_id, $sum);

    // ─────────────────────────────────────────────────────────────────
    // COMMITTED. The invoice and its orders now exist, so from here on
    // nothing may throw out of this function: the caller reports a
    // failure by re-rendering the checkout, and the client clicking
    // "Complete Order" again would place the whole order a second time.
    // ─────────────────────────────────────────────────────────────────
    try {
        // Subscribed add-ons — what makes a recurring add-on recur
        // (admin/cron/billing.php renews from these). Snapshotted so a
        // later catalogue edit never silently reprices a client.
        if (ServiceAddon::ensureOrderSchema()) {
            $addIns = db()->prepare('INSERT INTO order_addons (order_id, addon_id, name, billing_cycle, amount, currency) VALUES (?,?,?,?,?,?)');
            foreach ($sum['lines'] as $l) {
                if (($l['type'] ?? '') !== 'addon' || !isset($order_ids[$l['item'] ?? ''])) continue;
                $addIns->execute([$order_ids[$l['item']], $l['addon_id'] ?? null, $l['name'] ?? $l['label'],
                                  $l['cycle'] ?? 'monthly', (float) $l['amount'], $currency]);
            }
        }

        // Domains: cart items (incl. ones a package uses), then each
        // package's own registration/transfer. Submitted once paid.
        place_cart_domains($sum, $cid, $invoice_id, $order_for_domain);
        order_domain_columns();
        foreach ($packages as $uid => $pk) {
            if (!in_array($pk['domain_mode'], ['register', 'transfer'], true)) continue;
            record_pending_domain($cid, $pk['domain_name'], $pk['domain_mode'], max(1, min(10, (int) ($pk['domain_years'] ?? 1))),
                $invoice_id, $order_ids[$uid], $pk['domain_mode'] === 'transfer' ? (string) ($pk['epp_code'] ?? '') : '', !empty($pk['id_protection']));
        }

        foreach ($packages as $uid => $pk) {
            log_activity('order_checkout', 'order', $order_ids[$uid], 'Website checkout — ' . $pk['plan']['name'] . ' for ' . $pk['domain_name'] . ' (invoice ' . $inv_no . ')');
        }
        if (!$has_link && count($order_ids) > 1) {
            Notifier::sendToAllAdmins('order_new_admin', [
                'client_name' => trim(($client['first_name'] ?? '') . ' ' . ($client['last_name'] ?? '')),
                'item' => 'Multi-package order ' . $inv_no . ' — only the first package will auto-provision (database is missing orders.checkout_invoice_id); provision the rest by hand',
                'amount' => '—', 'gateway' => 'checkout', 'link' => APP_URL . '/orders/index.php',
            ]);
        }
    } catch (\Throwable $e) {
        error_log('place_hosting_order: post-commit step failed for invoice ' . $invoice_id . ' — ' . $e->getMessage());
    }

    OrderCart::clear();

    $names = array_map(fn($pk) => $pk['plan']['name'] . ' (' . $pk['domain_name'] . ')', $packages);
    $extra = array_values(array_filter(array_map(fn($l) => ($l['type'] ?? '') === 'cart_domain' && !isset($order_for_domain[$l['domain']]) ? $l['domain'] : null, $sum['lines'])));
    $first = reset($packages);
    return [
        'order_id' => $first_order, 'invoice_id' => $invoice_id, 'invoice_number' => $inv_no,
        'plan_name' => (string) $first['plan']['name'], 'domain' => (string) $first['domain_name'], 'total' => $sum['total'],
        'item' => implode(' + ', $names) . ($extra ? ' + ' . implode(', ', $extra) : ''),
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
