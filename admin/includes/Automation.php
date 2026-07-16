<?php
/**
 * Orbit Cloud — service lifecycle automation
 *
 * The glue between billing and provisioning:
 *
 *   Automation::provisionOrder($order_id)
 *       Paid hosting order whose plan is linked to a panel package →
 *       creates the cPanel account, records it, notifies the client
 *       with credentials. Falls back to "manual provisioning" cleanly
 *       when the plan isn't linked / no panel / no domain.
 *
 *   Automation::invoicePaid($invoice_id)
 *       Call whenever an invoice flips to paid. If it belongs to an
 *       order: first payment → provision; renewal → advance next_due
 *       and unsuspend the account if it was suspended.
 *
 *   Automation::settlePayment($payment_id)
 *       THE single entry point for "a payment might be done — check and
 *       fulfil it if so." Used identically by: the interactive return-URL
 *       handlers (checkout.php/order.php, so a client watching the page
 *       gets an instant result), the reconciliation cron (for clients who
 *       never come back), and gateway webhooks (for near-instant
 *       confirmation without waiting for the cron). Verifies with the
 *       gateway, marks the payment/invoice, then dispatches fulfilment by
 *       payments.raw.context.action — every action stores everything it
 *       needs in that JSON, never in the PHP session, so any of these
 *       three callers can complete it identically. Fully idempotent: safe
 *       to call repeatedly on the same payment from all three places.
 *
 *   Automation::suspendOrder($order) / reactivateOrder($order)
 *       Used by the billing cron for overdue suspension and by
 *       invoicePaid for reactivation.
 *
 * All methods are defensive: a panel/API failure never blocks the
 * billing flow — it degrades to a note on the order + admin notice.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/Notifier.php';
require_once __DIR__ . '/providers/Provider.php';

final class Automation
{
    /** Generate renewal invoices this many days before next_due. */
    public const RENEW_LEAD_DAYS = 14;
    /** Suspend this many days after next_due if the renewal is unpaid. */
    public const GRACE_DAYS = 5;

    /** invoices.order_id links renewal invoices to the order they renew. */
    public static function ensureSchema(): void
    {
        try {
            $col = db()->query("SHOW COLUMNS FROM invoices LIKE 'order_id'")->fetch();
            if (!$col) {
                db()->exec('ALTER TABLE invoices ADD COLUMN order_id INT UNSIGNED DEFAULT NULL, ADD INDEX idx_inv_order (order_id)');
            }
        } catch (\Throwable $e) {
            // no ALTER privilege — automation still works, minus renewal linking
        }
        try {
            $col = db()->query("SHOW COLUMNS FROM invoices LIKE 'client_service_id'")->fetch();
            if (!$col) {
                db()->exec('ALTER TABLE invoices ADD COLUMN client_service_id INT UNSIGNED DEFAULT NULL, ADD INDEX idx_inv_cs (client_service_id)');
            }
        } catch (\Throwable $e) {
            // no ALTER privilege — renewal billing for order-less services just won't link
        }
    }

    // ─────────────────────────────────────────────────────────────
    /**
     * Provision the hosting account for a (paid) order.
     * @return array{status:string, message:string}  status: provisioned|already|manual|failed
     */
    public static function provisionOrder(int $order_id): array
    {
        $stmt = db()->prepare('SELECT o.*, c.first_name, c.last_name, c.email,
                                      s.name plan_name, s.panel_provider, s.panel_package
                               FROM orders o
                               JOIN clients c ON c.id = o.client_id
                               LEFT JOIN services s ON s.id = o.service_id
                               WHERE o.id = ?');
        $stmt->execute([$order_id]);
        $o = $stmt->fetch();
        if (!$o) return ['status' => 'failed', 'message' => 'Order not found.'];

        // Already provisioned?
        $has = db()->prepare('SELECT COUNT(*) FROM whm_accounts WHERE order_id = ?');
        $has->execute([$order_id]);
        if ((int) $has->fetchColumn() > 0) return ['status' => 'already', 'message' => 'Account already exists for this order.'];

        $domain    = trim((string) ($o['domain_name'] ?? ''));
        $package   = trim((string) ($o['panel_package'] ?? ''));
        $panel_key = Provider::activeFor('panel');

        if ($domain === '' || $package === '' || !$panel_key) {
            $why = $domain === '' ? 'no domain was given for the service'
                 : ($package === '' ? 'the plan is not linked to a panel package'
                 : 'no hosting panel integration is active');
            self::noteOrder($order_id, 'Auto-provisioning skipped: ' . $why . '.');
            Notifier::sendToAllAdmins('order_new_admin', [
                'client_name' => trim($o['first_name'] . ' ' . $o['last_name']),
                'item'    => ($o['plan_name'] ?: $o['service_name']) . ' — needs MANUAL provisioning (' . $why . ')',
                'amount'  => format_money((float) $o['amount']),
                'gateway' => 'auto-provision',
                'link'    => APP_URL . '/integrations/whm/provision.php?order_id=' . $order_id,
            ]);
            return ['status' => 'manual', 'message' => 'Left for manual provisioning: ' . $why . '.'];
        }

        require_once __DIR__ . '/WHMClient.php';
        $username = WHMClient::buildUsername($domain);
        $password = WHMClient::generatePassword();

        try {
            $r = Provider::panel($panel_key)->createAccount([
                'username' => $username,
                'domain'   => $domain,
                'password' => $password,
                'package'  => $package,
                'email'    => $o['email'],
            ]);
        } catch (\Throwable $e) {
            $r = ['success' => false, 'message' => $e->getMessage()];
        }

        if (empty($r['success'])) {
            $msg = $r['message'] ?? 'Panel error';
            self::noteOrder($order_id, 'Auto-provisioning FAILED: ' . $msg);
            Notifier::sendToAllAdmins('order_new_admin', [
                'client_name' => trim($o['first_name'] . ' ' . $o['last_name']),
                'item'    => ($o['plan_name'] ?: $o['service_name']) . ' — auto-provisioning FAILED: ' . mb_strimwidth($msg, 0, 140, '…'),
                'amount'  => format_money((float) $o['amount']),
                'gateway' => 'auto-provision',
                'link'    => APP_URL . '/integrations/whm/provision.php?order_id=' . $order_id,
            ]);
            return ['status' => 'failed', 'message' => $msg];
        }

        // Record everywhere the rest of the app looks
        db()->prepare('INSERT INTO whm_accounts (order_id, cpanel_user, domain) VALUES (?,?,?)')
            ->execute([$order_id, $username, $domain]);
        try {
            Currency::ensureSchema();
            db()->prepare('INSERT INTO client_services
                    (client_id, service_id, order_id, label, domain, category, provider_category, provider_key,
                     remote_id, username, package, billing_cycle, amount, currency, status, start_date, next_due_date)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,"active",CURDATE(),?)')
                ->execute([$o['client_id'], $o['service_id'], $order_id,
                           $o['plan_name'] ?: ($o['service_name'] ?: 'Hosting'), $domain, 'hosting', 'panel', $panel_key,
                           $username, $username, $package, $o['billing_cycle'], $o['amount'], $o['currency'] ?? 'USD', $o['next_due']]);
        } catch (\Throwable $e) { /* client_services not migrated — whm_accounts row is enough */ }
        db()->prepare("UPDATE orders SET status = 'active' WHERE id = ?")->execute([$order_id]);
        self::noteOrder($order_id, 'Auto-provisioned cPanel account ' . $username . ' on ' . $panel_key . '.');

        $rows = '<tr><td style="padding:6px 0;color:#64748b">Domain</td><td style="padding:6px 0;text-align:right;font-weight:700">' . htmlspecialchars($domain) . '</td></tr>'
              . '<tr><td style="padding:6px 0;color:#64748b">Username</td><td style="padding:6px 0;text-align:right;font-weight:700;font-family:monospace">' . htmlspecialchars($username) . '</td></tr>'
              . '<tr><td style="padding:6px 0;color:#64748b">Password</td><td style="padding:6px 0;text-align:right;font-weight:700;font-family:monospace">' . htmlspecialchars($password) . '</td></tr>'
              . '<tr><td style="padding:6px 0;color:#64748b">Control panel</td><td style="padding:6px 0;text-align:right"><a href="https://' . htmlspecialchars($domain) . ':2083">https://' . htmlspecialchars($domain) . ':2083</a></td></tr>';
        Notifier::send('service_ready', (int) $o['client_id'], [
            'client_name'   => trim($o['first_name'] . ' ' . $o['last_name']),
            'service_label' => $o['plan_name'] ?: ($o['service_name'] ?: 'Hosting'),
            'account_rows'  => $rows,
            'email'         => $o['email'],
            'link'          => portal_base_url() . '/services.php',
        ]);
        return ['status' => 'provisioned', 'message' => 'cPanel account ' . $username . ' created.'];
    }

    // ─────────────────────────────────────────────────────────────
    /** Hook: an invoice has just been marked paid. */
    public static function invoicePaid(int $invoice_id): array
    {
        self::ensureSchema();
        try {
            $stmt = db()->prepare('SELECT order_id, client_service_id FROM invoices WHERE id = ?');
            $stmt->execute([$invoice_id]);
            $row = $stmt->fetch();
            $order_id = (int) ($row['order_id'] ?? 0);
            $cs_id    = (int) ($row['client_service_id'] ?? 0);
        } catch (\Throwable $e) {
            return ['status' => 'none', 'message' => 'No order link column.'];
        }

        if (!$order_id) {
            return $cs_id ? self::servicePaid($cs_id) : ['status' => 'none', 'message' => 'Invoice not linked to an order or service.'];
        }

        $stmt = db()->prepare('SELECT * FROM orders WHERE id = ?');
        $stmt->execute([$order_id]);
        $o = $stmt->fetch();
        if (!$o) return ['status' => 'none', 'message' => 'Order missing.'];

        if ($o['status'] === 'pending') {
            return self::provisionOrder($order_id); // first payment → provision
        }

        // Renewal: advance the due date one cycle from whichever is later —
        // the old due date (paid early) or today (paid late).
        $interval = $o['billing_cycle'] === 'annual' ? '1 YEAR' : '1 MONTH';
        if ($o['billing_cycle'] !== 'one_time') {
            db()->prepare("UPDATE orders SET next_due = DATE_ADD(GREATEST(COALESCE(next_due, CURDATE()), CURDATE()), INTERVAL $interval) WHERE id = ?")
                ->execute([$order_id]);
            try {
                db()->prepare("UPDATE client_services SET next_due_date = DATE_ADD(GREATEST(COALESCE(next_due_date, CURDATE()), CURDATE()), INTERVAL $interval) WHERE order_id = ?")
                    ->execute([$order_id]);
            } catch (\Throwable $e) {}
        }

        if ($o['status'] === 'suspended') {
            return self::reactivateOrder($o);
        }
        return ['status' => 'renewed', 'message' => 'Next due date advanced.'];
    }

    /**
     * Renewal path for a standalone client_services row (billed directly
     * via invoices.client_service_id, no order behind it). Same advance/
     * reactivate logic as the order path in invoicePaid(), just keyed off
     * the service row directly.
     */
    private static function servicePaid(int $cs_id): array
    {
        $stmt = db()->prepare('SELECT cs.*, c.first_name, c.email FROM client_services cs JOIN clients c ON c.id = cs.client_id WHERE cs.id = ?');
        $stmt->execute([$cs_id]);
        $cs = $stmt->fetch();
        if (!$cs) return ['status' => 'none', 'message' => 'Service missing.'];

        $interval = $cs['billing_cycle'] === 'annual' ? '1 YEAR' : '1 MONTH';
        if ($cs['billing_cycle'] !== 'one_time') {
            db()->prepare("UPDATE client_services SET next_due_date = DATE_ADD(GREATEST(COALESCE(next_due_date, CURDATE()), CURDATE()), INTERVAL $interval) WHERE id = ?")
                ->execute([$cs_id]);
        }

        if ($cs['status'] === 'suspended') {
            return self::reactivateService($cs);
        }
        return ['status' => 'renewed', 'message' => 'Next due date advanced.'];
    }

    // ─────────────────────────────────────────────────────────────
    /**
     * Verify a payment with its gateway and, if paid, fulfil it — safe to
     * call any number of times from any context (browser return, cron,
     * webhook). See class docblock for the full contract.
     * @return array{status:string, message:string, ...}
     *   status: completed|already|failed|pending|not_found
     */
    public static function settlePayment(int $payment_id): array
    {
        $stmt = db()->prepare('SELECT * FROM payments WHERE id = ?');
        $stmt->execute([$payment_id]);
        $pay = $stmt->fetch();
        if (!$pay) return ['status' => 'not_found', 'message' => 'Payment not found.'];
        if ($pay['status'] === 'completed') return ['status' => 'already', 'message' => 'Already settled.'];
        if ($pay['status'] === 'failed') return ['status' => 'failed', 'message' => 'Already marked failed.'];

        try {
            $v = Provider::payment($pay['gateway'])->verify((string) ($pay['gateway_ref'] ?? ''));
        } catch (\Throwable $e) {
            return ['status' => 'pending', 'message' => $e->getMessage()];
        }

        if (empty($v['success'])) {
            if (($v['status'] ?? '') === 'failed') {
                db()->prepare("UPDATE payments SET status = 'failed' WHERE id = ?")->execute([$payment_id]);
                self::notifyPaymentOutcome($pay, false, $v['message'] ?? 'Payment failed.');
                return ['status' => 'failed', 'message' => $v['message'] ?? 'Payment failed.'];
            }
            return ['status' => 'pending', 'message' => $v['message'] ?? ($v['status'] ?? 'Not confirmed yet.')];
        }

        // Paid — mark it, then dispatch fulfilment by context. Order matters:
        // flip the payment to completed FIRST so a concurrent second caller
        // (cron overlapping a webhook, say) hits the "already" branch above
        // instead of double-fulfilling.
        db()->prepare("UPDATE payments SET status = 'completed' WHERE id = ?")->execute([$payment_id]);
        if ($pay['invoice_id']) {
            db()->prepare("UPDATE invoices SET status = 'paid', paid_date = CURDATE(), payment_method = ? WHERE id = ?")
                ->execute([$pay['gateway'], $pay['invoice_id']]);
        }

        $ctx = (json_decode($pay['raw'] ?? '', true) ?: [])['context'] ?? [];
        $result = ['status' => 'completed', 'message' => 'Payment settled.'];

        switch ($ctx['action'] ?? '') {
            case 'domain_checkout':
                $result['fulfilment'] = self::fulfilDomainCheckout($pay, $ctx);
                break;
            case 'order_service':
                $result['order'] = self::fulfilOrderService($pay, $ctx);
                break;
            case 'renew':
                $result['renewal'] = self::fulfilDomainRenewal($pay, $ctx);
                break;
            case 'transfer':
                $result['transfer'] = self::fulfilDomainTransfer($pay, $ctx);
                break;
            default:
                // Renewals, transfers, plain invoices — invoicePaid() already
                // knows how to advance/reactivate/provision from invoice_id.
                if ($pay['invoice_id']) $result['invoice'] = self::invoicePaid((int) $pay['invoice_id']);
        }
        return $result;
    }

    /**
     * Register every domain from a checkout's cart. $ctx['items'] is the
     * exact cart snapshot taken at payment-creation time (see
     * portal/checkout.php) — never the PHP session, so this runs
     * identically whether called from the client's own return request or
     * from the cron/webhook hours later after the session is long gone.
     */
    public static function fulfilDomainCheckout(array $pay, array $ctx): array
    {
        $raw = json_decode($pay['raw'] ?? '', true) ?: [];
        if (!empty($raw['fulfilment'])) return $raw['fulfilment']; // already done — idempotent

        $stmt = db()->prepare('SELECT * FROM clients WHERE id = ?');
        $stmt->execute([(int) $pay['client_id']]);
        $client = $stmt->fetch() ?: [];

        $reg_key = Provider::activeFor('registrar');
        $summary = [];
        foreach ($ctx['items'] ?? [] as $it) {
            $domain = $it['domain'];
            $years  = (int) ($it['years'] ?? 1);
            $ok = false; $note = '';
            if ($reg_key) {
                try {
                    $r = Provider::registrar($reg_key)->register($domain, [
                        'first_name'   => $client['first_name'] ?? '',
                        'last_name'    => $client['last_name'] ?? '',
                        'email'        => $client['email'] ?? '',
                        'phone'        => $client['phone'] ?: '+254700000000',
                        'company'      => $client['company'] ?? '',
                        'country_code' => self::isoCountry($client['country'] ?? 'Kenya'),
                    ], $years);
                    $ok   = !empty($r['success']);
                    $note = $r['message'] ?? '';
                } catch (\Throwable $e) {
                    $note = $e->getMessage();
                }
            } else {
                $note = 'No registrar active — to be registered manually by our team.';
            }

            try {
                db()->prepare('INSERT INTO domain_registrations (client_id, domain_name, registrar, registration_date, expiry_date, status, auto_renew)
                               VALUES (?,?,?,CURDATE(),DATE_ADD(CURDATE(), INTERVAL ? YEAR),?,1)
                               ON DUPLICATE KEY UPDATE status=VALUES(status), expiry_date=VALUES(expiry_date)')
                    ->execute([$pay['client_id'], $domain, $reg_key ?: 'manual', $years, $ok ? 'active' : 'pending']);
            } catch (\Throwable $e) {
                try {
                    db()->prepare('INSERT IGNORE INTO domain_registrations (client_id, domain_name, registrar, registration_date, expiry_date, status, auto_renew)
                                   VALUES (?,?,?,CURDATE(),DATE_ADD(CURDATE(), INTERVAL ? YEAR),?,1)')
                        ->execute([$pay['client_id'], $domain, 'manual', $years, $ok ? 'active' : 'pending']);
                } catch (\Throwable $e2) { /* legacy ENUM registrar column — payment is already recorded regardless */ }
            }
            $summary[] = ['domain' => $domain, 'registered' => $ok, 'note' => $note];
        }

        $raw['fulfilment'] = $summary;
        db()->prepare('UPDATE payments SET raw = ? WHERE id = ?')->execute([json_encode($raw), (int) $pay['id']]);

        $currency   = $pay['currency'] ?: (defined('CURRENCY') ? CURRENCY : 'USD');
        $registered = array_filter($summary, fn($d) => !empty($d['registered']));
        $item_desc  = $registered ? implode(', ', array_column($registered, 'domain')) : 'domain order';
        $client_name = trim(($client['first_name'] ?? '') . ' ' . ($client['last_name'] ?? ''));

        if ($pay['invoice_id']) {
            Notifier::sendInvoiceEmail((int) $pay['invoice_id'], 'invoice_paid', [
                'gateway' => ucfirst($pay['gateway']), 'link' => portal_base_url() . '/domains.php',
            ]);
        }
        Notifier::send('order_new', (int) $pay['client_id'], [
            'client_name' => $client_name, 'item' => $item_desc,
            'amount' => $currency . ' ' . number_format((float) $pay['amount'], 2),
            'note' => 'You can manage your domains any time from the client portal.',
            'email' => $client['email'] ?? '', 'link' => portal_base_url() . '/domains.php',
        ]);
        Notifier::sendToAllAdmins('order_new_admin', [
            'client_name' => $client_name, 'item' => $item_desc,
            'amount' => $currency . ' ' . number_format((float) $pay['amount'], 2),
            'gateway' => ucfirst($pay['gateway']),
            'link' => APP_URL . '/integrations/domains/index.php',
        ]);
        return $summary;
    }

    /**
     * Create (if not already) and provision the order for a portal
     * service purchase. Mirrors the old page-local order_fulfil() from
     * portal/order.php, moved here so the cron/webhook can complete an
     * order the client never came back to see confirmed.
     */
    public static function fulfilOrderService(array $pay, array $ctx): array
    {
        if (!empty($ctx['order_id'])) {
            return ['order_id' => (int) $ctx['order_id'], 'status' => 'already', 'message' => 'Already created.'];
        }

        $stmt = db()->prepare('SELECT * FROM services WHERE id = ?');
        $stmt->execute([(int) ($ctx['service_id'] ?? 0)]);
        $plan = $stmt->fetch();
        $name  = $plan['name'] ?? ($ctx['plan_name'] ?? 'Service');
        $cycle = $plan['billing_cycle'] ?? 'monthly';
        $next  = $cycle === 'monthly' ? date('Y-m-d', strtotime('+1 month'))
               : ($cycle === 'annual' ? date('Y-m-d', strtotime('+1 year')) : null);

        require_once __DIR__ . '/Currency.php';
        Currency::ensureSchema();
        db()->prepare('INSERT INTO orders (client_id, service_id, service_name, domain_name, amount, billing_cycle, status, start_date, next_due, notes, currency)
                       VALUES (?,?,?,?,?,?,?,CURDATE(),?,?,?)')
            ->execute([$pay['client_id'], $plan['id'] ?? null, $name, $ctx['domain'] ?: null, (float) $pay['amount'], $cycle,
                       'pending', $next, 'Ordered from client portal — invoice #' . $pay['invoice_id'], $pay['currency'] ?? 'USD']);
        $order_id = (int) db()->lastInsertId();

        // Remember we've created it, so a later call (cron overlapping the
        // client's own return) can't create a second order for this payment.
        $ctx['order_id'] = $order_id;
        $raw = json_decode($pay['raw'] ?? '', true) ?: [];
        $raw['context'] = $ctx;
        db()->prepare('UPDATE payments SET raw = ? WHERE id = ?')->execute([json_encode($raw), (int) $pay['id']]);

        self::ensureSchema();
        try {
            if ($pay['invoice_id']) {
                db()->prepare('UPDATE invoices SET order_id = ? WHERE id = ?')->execute([$order_id, (int) $pay['invoice_id']]);
            }
        } catch (\Throwable $e) {}
        $provision = self::provisionOrder($order_id);

        $stmt = db()->prepare('SELECT first_name, last_name, email FROM clients WHERE id = ?');
        $stmt->execute([(int) $pay['client_id']]);
        $client = $stmt->fetch() ?: [];
        $client_name = trim(($client['first_name'] ?? '') . ' ' . ($client['last_name'] ?? ''));
        $currency = $pay['currency'] ?: (defined('CURRENCY') ? CURRENCY : 'USD');

        Notifier::send('order_new', (int) $pay['client_id'], [
            'client_name' => $client_name, 'item' => $name,
            'amount' => $currency . ' ' . number_format((float) $pay['amount'], 2),
            'note' => 'Our team is setting up your service — you\'ll be notified the moment it\'s active.',
            'email' => $client['email'] ?? '', 'link' => portal_base_url() . '/services.php',
        ]);
        Notifier::sendToAllAdmins('order_new_admin', [
            'client_name' => $client_name, 'item' => $name,
            'amount' => $currency . ' ' . number_format((float) $pay['amount'], 2),
            'gateway' => ucfirst(str_replace('_', ' ', $pay['gateway'])),
            'link' => APP_URL . '/orders/index.php',
        ]);
        return ['order_id' => $order_id, 'status' => 'created', 'provision' => $provision];
    }

    /**
     * Extend a domain's registration after a paid renewal. $ctx['domain_id']
     * is domain_registrations.id, stored at payment-creation time — looking
     * the domain up fresh here (rather than trusting a page-local variable)
     * is what lets the cron/webhook complete this without the client.
     */
    public static function fulfilDomainRenewal(array $pay, array $ctx): array
    {
        $raw = json_decode($pay['raw'] ?? '', true) ?: [];
        if (isset($raw['renewal'])) return $raw['renewal']; // already done — idempotent

        $stmt = db()->prepare('SELECT * FROM domain_registrations WHERE id = ? AND client_id = ?');
        $stmt->execute([(int) ($ctx['domain_id'] ?? 0), (int) $pay['client_id']]);
        $dom = $stmt->fetch();
        $years = max(1, min(5, (int) ($ctx['years'] ?? 1)));

        if (!$dom) {
            $result = ['success' => false, 'message' => 'Domain record not found — payment recorded, needs manual follow-up.'];
        } else {
            try {
                $rr = Provider::registrar($dom['registrar'])->renew($dom['domain_name'], $years);
                $ok = !empty($rr['success']);
                if ($ok) {
                    db()->prepare('UPDATE domain_registrations SET expiry_date = DATE_ADD(expiry_date, INTERVAL ? YEAR), status = "active" WHERE id = ?')
                        ->execute([$years, $dom['id']]);
                } else {
                    self::noteDomain((int) $dom['id'], 'Renewal paid but registrar call failed: ' . ($rr['message'] ?? 'unknown'));
                }
                $result = ['success' => $ok, 'message' => $rr['message'] ?? ($ok ? 'Domain renewed.' : 'The registrar did not confirm the renewal.'), 'domain' => $dom['domain_name']];
            } catch (\Throwable $e) {
                self::noteDomain((int) $dom['id'], 'Renewal paid but errored: ' . $e->getMessage());
                $result = ['success' => false, 'message' => $e->getMessage(), 'domain' => $dom['domain_name']];
            }
        }

        $raw['renewal'] = $result;
        db()->prepare('UPDATE payments SET raw = ? WHERE id = ?')->execute([json_encode($raw), (int) $pay['id']]);

        $stmt = db()->prepare('SELECT first_name, last_name, email FROM clients WHERE id = ?');
        $stmt->execute([(int) $pay['client_id']]);
        $client = $stmt->fetch() ?: [];
        self::notifyDomainPaymentReceived($pay, $client, $dom['domain_name'] ?? ($ctx['domain'] ?? 'your domain'));
        return $result;
    }

    /**
     * Submit an inbound transfer request after a paid transfer. Unlike
     * renewal, the domain isn't in domain_registrations yet — everything
     * needed (domain, auth code, years) came from the cart-style context
     * stored at payment-creation time.
     */
    public static function fulfilDomainTransfer(array $pay, array $ctx): array
    {
        $raw = json_decode($pay['raw'] ?? '', true) ?: [];
        if (isset($raw['transfer'])) return $raw['transfer']; // already done — idempotent

        $domain = $ctx['domain'] ?? '';
        $code   = $ctx['auth_code'] ?? '';
        $years  = max(1, min(5, (int) ($ctx['years'] ?? 1)));
        $reg_key = Provider::activeFor('registrar');

        $stmt = db()->prepare('SELECT first_name, last_name, email, phone, company, country FROM clients WHERE id = ?');
        $stmt->execute([(int) $pay['client_id']]);
        $client = $stmt->fetch() ?: [];

        if (!$reg_key || $domain === '') {
            $result = ['success' => false, 'message' => 'No active registrar or missing domain — payment recorded, needs manual follow-up.', 'domain' => $domain];
        } else {
            try {
                $rr = Provider::registrar($reg_key)->transfer($domain, $code, [
                    'first_name' => $client['first_name'] ?? '', 'last_name' => $client['last_name'] ?? '',
                    'email' => $client['email'] ?? '', 'phone' => $client['phone'] ?: '+254700000000',
                    'company' => $client['company'] ?? '', 'country_code' => self::isoCountry($client['country'] ?? 'Kenya'),
                ], $years);
                $result = ['success' => !empty($rr['success']), 'message' => $rr['message'] ?? '', 'domain' => $domain];
            } catch (\Throwable $e) {
                $result = ['success' => false, 'message' => $e->getMessage(), 'domain' => $domain];
            }
            try {
                db()->prepare('INSERT INTO domain_registrations (client_id, domain_name, registrar, registration_date, status, auto_renew)
                               VALUES (?,?,?,CURDATE(),"pending",1) ON DUPLICATE KEY UPDATE status = VALUES(status)')
                    ->execute([$pay['client_id'], $domain, $reg_key]);
            } catch (\Throwable $e) { /* payment + transfer request already happened either way */ }
        }

        $raw['transfer'] = $result;
        db()->prepare('UPDATE payments SET raw = ? WHERE id = ?')->execute([json_encode($raw), (int) $pay['id']]);

        self::notifyDomainPaymentReceived($pay, $client, $domain);
        Notifier::sendToAllAdmins('order_new_admin', [
            'client_name' => trim(($client['first_name'] ?? '') . ' ' . ($client['last_name'] ?? '')),
            'item' => 'Domain transfer: ' . $domain,
            'amount' => ($pay['currency'] ?: 'USD') . ' ' . number_format((float) $pay['amount'], 2),
            'gateway' => ucfirst($pay['gateway']),
            'link' => APP_URL . '/integrations/domains/index.php',
        ]);
        return $result;
    }

    /** Shared "invoice_paid" client email for renewal/transfer (both bill through a plain invoice, no order). */
    private static function notifyDomainPaymentReceived(array $pay, array $client, string $domainLabel): void
    {
        if (!$pay['invoice_id']) return;
        Notifier::sendInvoiceEmail((int) $pay['invoice_id'], 'invoice_paid', [
            'gateway' => ucfirst($pay['gateway']), 'link' => portal_base_url() . '/domains.php',
        ]);
    }

    private static function noteDomain(int $domain_id, string $note): void
    {
        try {
            db()->prepare("UPDATE domain_registrations SET notes = CONCAT(COALESCE(notes,''), '\n', ?) WHERE id = ?")
                ->execute(['[' . date('Y-m-d H:i') . '] ' . $note, $domain_id]);
        } catch (\Throwable $e) {}
    }

    /** Client-facing failure notification, used by settlePayment()'s cron/webhook path. */
    private static function notifyPaymentOutcome(array $pay, bool $success, string $message): void
    {
        if ($success || !$pay['client_id']) return;
        $stmt = db()->prepare('SELECT first_name, last_name, email FROM clients WHERE id = ?');
        $stmt->execute([(int) $pay['client_id']]);
        $client = $stmt->fetch();
        if (!$client || !$client['email']) return;
        Notifier::send('payment_failed', (int) $pay['client_id'], [
            'client_name' => trim($client['first_name'] . ' ' . $client['last_name']),
            'amount' => ($pay['currency'] ?: 'USD') . ' ' . number_format((float) $pay['amount'], 2),
            'gateway' => ucfirst($pay['gateway']), 'reason' => $message,
            'email' => $client['email'], 'link' => portal_base_url() . '/invoices/index.php',
        ]);
    }

    /** Same country-name→ISO map used by portal/includes/domain_payment.php's dp_iso_country(). */
    private static function isoCountry(string $name): string
    {
        $map = ['Kenya'=>'KE','Uganda'=>'UG','Tanzania'=>'TZ','Rwanda'=>'RW','Ethiopia'=>'ET','Nigeria'=>'NG','Ghana'=>'GH',
                'South Africa'=>'ZA','Egypt'=>'EG','Morocco'=>'MA','USA'=>'US','United Kingdom'=>'GB','Canada'=>'CA',
                'Australia'=>'AU','Germany'=>'DE','France'=>'FR','India'=>'IN','China'=>'CN','UAE'=>'AE','Saudi Arabia'=>'SA'];
        return $map[$name] ?? 'KE';
    }

    // ─────────────────────────────────────────────────────────────
    /** Suspend an overdue order's hosting account (billing cron). */
    public static function suspendOrder(array $o, string $reason = 'Unpaid renewal invoice'): array
    {
        $user = self::panelUserForOrder((int) $o['id']);
        $panel_key = Provider::activeFor('panel');
        $did_panel = false;
        if ($user && $panel_key) {
            try {
                Provider::panel($panel_key)->suspend($user, $reason);
                $did_panel = true;
            } catch (\Throwable $e) {
                self::noteOrder((int) $o['id'], 'Suspension: panel call failed — ' . $e->getMessage());
            }
        }
        db()->prepare("UPDATE orders SET status = 'suspended' WHERE id = ?")->execute([(int) $o['id']]);
        try {
            db()->prepare("UPDATE client_services SET status = 'suspended' WHERE order_id = ?")->execute([(int) $o['id']]);
        } catch (\Throwable $e) {}
        self::noteOrder((int) $o['id'], 'Suspended (' . $reason . ')' . ($did_panel ? ' — panel account ' . $user . ' suspended.' : ' — no panel account to suspend.'));

        Notifier::send('service_suspended', (int) $o['client_id'], [
            'client_name'   => $o['first_name'] ?? '',
            'service_label' => $o['service_name'] ?: 'Your service',
            'reason'        => $reason . ' — pay the outstanding invoice to restore service.',
            'email'         => $o['email'] ?? '',
            'link'          => portal_base_url() . '/invoices/index.php',
        ]);
        return ['status' => 'suspended', 'message' => 'Order suspended' . ($did_panel ? ' (panel too)' : '') . '.'];
    }

    /** Reactivate a suspended order after payment. */
    public static function reactivateOrder(array $o): array
    {
        $user = self::panelUserForOrder((int) $o['id']);
        $panel_key = Provider::activeFor('panel');
        if ($user && $panel_key) {
            try {
                Provider::panel($panel_key)->unsuspend($user);
            } catch (\Throwable $e) {
                self::noteOrder((int) $o['id'], 'Reactivation: panel call failed — ' . $e->getMessage());
            }
        }
        db()->prepare("UPDATE orders SET status = 'active' WHERE id = ?")->execute([(int) $o['id']]);
        try {
            db()->prepare("UPDATE client_services SET status = 'active' WHERE order_id = ?")->execute([(int) $o['id']]);
        } catch (\Throwable $e) {}
        self::noteOrder((int) $o['id'], 'Reactivated after payment.');

        // Client row may not be joined on $o — fetch what the template needs
        $c = db()->prepare('SELECT first_name, email FROM clients WHERE id = ?');
        $c->execute([(int) $o['client_id']]);
        $c = $c->fetch() ?: ['first_name' => '', 'email' => ''];
        Notifier::send('service_unsuspended', (int) $o['client_id'], [
            'client_name'   => $c['first_name'],
            'service_label' => $o['service_name'] ?: 'Your service',
            'email'         => $c['email'],
            'link'          => portal_base_url() . '/services.php',
        ]);
        return ['status' => 'reactivated', 'message' => 'Order reactivated.'];
    }

    // ─────────────────────────────────────────────────────────────
    /**
     * Suspend a standalone client_services row (no order_id — created
     * directly via services/create.php) for an unpaid renewal. Mirrors
     * suspendOrder() but keys off the service row itself instead of an
     * order, since order-less services have no orders row to update and
     * their panel username is already stored right on client_services.
     */
    public static function suspendService(array $cs, string $reason = 'Unpaid renewal invoice'): array
    {
        $did_panel = false;
        if (!empty($cs['username']) && !empty($cs['provider_key']) && $cs['provider_category'] === 'panel') {
            try {
                Provider::panel($cs['provider_key'])->suspend($cs['username'], $reason);
                $did_panel = true;
            } catch (\Throwable $e) {
                self::noteService((int) $cs['id'], 'Suspension: panel call failed — ' . $e->getMessage());
            }
        }
        db()->prepare("UPDATE client_services SET status = 'suspended' WHERE id = ?")->execute([(int) $cs['id']]);
        self::noteService((int) $cs['id'], 'Suspended (' . $reason . ')' . ($did_panel ? ' — panel account ' . $cs['username'] . ' suspended.' : ' — no panel account to suspend.'));

        Notifier::send('service_suspended', (int) $cs['client_id'], [
            'client_name'   => $cs['first_name'] ?? '',
            'service_label' => $cs['label'] ?: 'Your service',
            'reason'        => $reason . ' — pay the outstanding invoice to restore service.',
            'email'         => $cs['email'] ?? '',
            'link'          => portal_base_url() . '/invoices/index.php',
        ]);
        return ['status' => 'suspended', 'message' => 'Service suspended' . ($did_panel ? ' (panel too)' : '') . '.'];
    }

    /** Reactivate a suspended standalone client_services row after payment. */
    public static function reactivateService(array $cs): array
    {
        if (!empty($cs['username']) && !empty($cs['provider_key']) && $cs['provider_category'] === 'panel') {
            try {
                Provider::panel($cs['provider_key'])->unsuspend($cs['username']);
            } catch (\Throwable $e) {
                self::noteService((int) $cs['id'], 'Reactivation: panel call failed — ' . $e->getMessage());
            }
        }
        db()->prepare("UPDATE client_services SET status = 'active' WHERE id = ?")->execute([(int) $cs['id']]);
        self::noteService((int) $cs['id'], 'Reactivated after payment.');

        $c = db()->prepare('SELECT first_name, email FROM clients WHERE id = ?');
        $c->execute([(int) $cs['client_id']]);
        $c = $c->fetch() ?: ['first_name' => '', 'email' => ''];
        Notifier::send('service_unsuspended', (int) $cs['client_id'], [
            'client_name'   => $c['first_name'],
            'service_label' => $cs['label'] ?: 'Your service',
            'email'         => $c['email'],
            'link'          => portal_base_url() . '/services.php',
        ]);
        return ['status' => 'reactivated', 'message' => 'Service reactivated.'];
    }

    private static function noteService(int $service_id, string $note): void
    {
        try {
            db()->prepare("UPDATE client_services SET notes = CONCAT(COALESCE(notes,''), ?, '\n') WHERE id = ?")
                ->execute(['[' . date('Y-m-d H:i') . '] ' . $note, $service_id]);
        } catch (\Throwable $e) {}
    }

    // ─────────────────────────────────────────────────────────────
    private static function panelUserForOrder(int $order_id): ?string
    {
        $stmt = db()->prepare('SELECT cpanel_user FROM whm_accounts WHERE order_id = ? ORDER BY id DESC LIMIT 1');
        $stmt->execute([$order_id]);
        $u = $stmt->fetchColumn();
        if ($u) return (string) $u;
        try {
            $stmt = db()->prepare('SELECT username FROM client_services WHERE order_id = ? AND username IS NOT NULL ORDER BY id DESC LIMIT 1');
            $stmt->execute([$order_id]);
            $u = $stmt->fetchColumn();
        } catch (\Throwable $e) {}
        return $u ? (string) $u : null;
    }

    private static function noteOrder(int $order_id, string $note): void
    {
        try {
            db()->prepare("UPDATE orders SET notes = CONCAT(COALESCE(notes,''), ?, '\n') WHERE id = ?")
                ->execute(['[' . date('Y-m-d H:i') . '] ' . $note, $order_id]);
        } catch (\Throwable $e) {}
    }
}
