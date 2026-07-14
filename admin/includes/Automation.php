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
            db()->prepare('INSERT INTO client_services
                    (client_id, service_id, order_id, label, domain, category, provider_category, provider_key,
                     remote_id, username, package, billing_cycle, amount, status, start_date, next_due_date)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,"active",CURDATE(),?)')
                ->execute([$o['client_id'], $o['service_id'], $order_id,
                           $o['plan_name'] ?: ($o['service_name'] ?: 'Hosting'), $domain, 'hosting', 'panel', $panel_key,
                           $username, $username, $package, $o['billing_cycle'], $o['amount'], $o['next_due']]);
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
            $stmt = db()->prepare('SELECT order_id FROM invoices WHERE id = ?');
            $stmt->execute([$invoice_id]);
            $order_id = (int) $stmt->fetchColumn();
        } catch (\Throwable $e) {
            return ['status' => 'none', 'message' => 'No order link column.'];
        }
        if (!$order_id) return ['status' => 'none', 'message' => 'Invoice not linked to an order.'];

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
            'link'          => portal_base_url() . '/invoices/',
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
