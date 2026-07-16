<?php
/**
 * Orbit Cloud — Notification dispatcher
 *
 * Single entry point for every in-app + email notification in the
 * platform. Reads type definitions from NotificationRegistry, inserts
 * an in-app row, and sends a branded email (via the existing Mailer /
 * SMTP config) when the type calls for it. Failures in email delivery
 * never block the in-app notification or the caller's own flow — this
 * is always best-effort, fire-and-forget, matching how the rest of the
 * app treats outbound mail.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/NotificationRegistry.php';
require_once __DIR__ . '/SiteSettings.php';
require_once __DIR__ . '/functions.php';

final class Notifier
{
    public static function ensureTables(): bool
    {
        try {
            db()->exec("CREATE TABLE IF NOT EXISTS notifications (
                id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                audience     ENUM('client','admin') NOT NULL,
                recipient_id INT UNSIGNED NOT NULL,
                type         VARCHAR(50)  NOT NULL,
                title        VARCHAR(255) NOT NULL,
                message      TEXT,
                link         VARCHAR(255) DEFAULT NULL,
                is_read      TINYINT(1)   NOT NULL DEFAULT 0,
                created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_notif_recipient (audience, recipient_id, is_read),
                INDEX idx_notif_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            db()->exec("CREATE TABLE IF NOT EXISTS reminder_log (
                id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                entity_type VARCHAR(30)  NOT NULL,
                entity_id   INT UNSIGNED NOT NULL,
                milestone   VARCHAR(20)  NOT NULL,
                sent_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_reminder (entity_type, entity_id, milestone)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (\Throwable $e) {
            return false;
        }
        // email_sent/email_error: Mailer already computes a specific reason for
        // every failure (bad credentials, blocked port, DNS failure, etc.) but
        // it was being thrown away — every email failed silently with nothing
        // to show for it. These columns give every notification a durable,
        // queryable delivery record. NULL = this type has no email; 1 = sent;
        // 0 = attempted and failed (see email_error).
        try {
            $col = db()->query("SHOW COLUMNS FROM notifications LIKE 'email_sent'")->fetch();
            if (!$col) {
                db()->exec("ALTER TABLE notifications
                    ADD COLUMN email_sent TINYINT(1) DEFAULT NULL,
                    ADD COLUMN email_error TEXT DEFAULT NULL");
            }
        } catch (\Throwable $e) { /* no ALTER privilege — email still sends, just without a delivery record */ }
        return true;
    }

    /**
     * Send a client- or admin-audience notification to ONE recipient.
     * $vars feeds the {placeholder} templates; 'link' (if present) is
     * stored as-is (not templated) as the notification's click target.
     */
    public static function send(string $type, int $recipientId, array $vars = []): void
    {
        $def = NotificationRegistry::get($type);
        if (!$def || !$recipientId) return;
        self::ensureTables();

        if ($def['audience'] === 'client' && !empty($def['category']) && self::clientOptedOut($recipientId, $def['category'])) {
            return; // renewal/expiry-heads-up or announcement — this client turned it off in Notification Preferences
        }

        $title   = self::render($def['title'], $vars);
        $message = self::render($def['message'], $vars);
        $link    = $vars['link'] ?? null;

        // Email first (if this type sends one) so its actual outcome — Mailer
        // already computes a specific reason on failure — rides along with
        // the in-app row instead of vanishing.
        $emailSent = null; $emailError = null;
        if (!empty($def['email'])) {
            $result = self::sendEmail($def, $recipientId, $vars);
            if ($result !== null) {
                $emailSent  = !empty($result['success']) ? 1 : 0;
                $emailError = !empty($result['success']) ? null : ($result['message'] ?? 'Unknown error');
                if (!$emailSent) {
                    error_log("Notifier: email failed for type={$type} recipient={$recipientId}: {$emailError}");
                }
            }
        }

        try {
            db()->prepare('INSERT INTO notifications (audience, recipient_id, type, title, message, link, email_sent, email_error) VALUES (?,?,?,?,?,?,?,?)')
                ->execute([$def['audience'], $recipientId, $type, $title, $message, $link, $emailSent, $emailError]);
        } catch (\Throwable $e) {
            // Older schema without the email_ columns (ALTER privilege missing) — fall back once.
            try {
                db()->prepare('INSERT INTO notifications (audience, recipient_id, type, title, message, link) VALUES (?,?,?,?,?,?)')
                    ->execute([$def['audience'], $recipientId, $type, $title, $message, $link]);
            } catch (\Throwable $e2) { /* in-app is best-effort too — never fatal the caller */ }
        }
    }

    /**
     * Send a client-facing invoice email (new / paid / overdue) that's an
     * actual copy of the invoice — status (Paid / Unpaid / Pending
     * Confirmation / Overdue) and every line item — instead of a bare
     * "you have an invoice" link. Centralized so every call site (billing
     * cron, manual invoice creation, payment confirmation, offline
     * reference flows) sends the same complete email; loads everything
     * it needs from just the invoice id so callers don't have to
     * assemble the vars themselves.
     */
    public static function sendInvoiceEmail(int $invoiceId, string $type, array $extra = []): void
    {
        $stmt = db()->prepare('SELECT i.*, c.first_name, c.last_name, c.email FROM invoices i JOIN clients c ON c.id = i.client_id WHERE i.id = ?');
        $stmt->execute([$invoiceId]);
        $inv = $stmt->fetch();
        if (!$inv || !$inv['client_id']) return;

        $currency = $inv['currency'] ?: 'USD';
        $status   = invoice_status_label($inv);

        self::send($type, (int) $inv['client_id'], array_merge([
            'client_name'    => trim($inv['first_name'] . ' ' . $inv['last_name']),
            'invoice_number' => $inv['invoice_number'],
            'amount'         => format_money((float) $inv['total'], $currency),
            'due_date'       => format_date($inv['due_date']),
            'status'         => $status,
            'status_color'   => invoice_status_color($status),
            'items_table'    => invoice_items_email_table($invoiceId, $currency),
            'email'          => $inv['email'],
            'link'           => portal_base_url() . '/invoices/view.php?id=' . $invoiceId,
        ], $extra));
    }

    /** Broadcast an admin-audience notification to every active admin user. */
    public static function sendToAllAdmins(string $type, array $vars = []): void
    {
        try {
            $ids = db()->query('SELECT id FROM admin_users')->fetchAll(PDO::FETCH_COLUMN);
        } catch (\Throwable $e) {
            $ids = [];
        }
        foreach ($ids as $id) {
            self::send($type, (int) $id, $vars);
        }
    }

    /** @return array{success:bool,message:string}|null null = no recipient address to even try */
    private static function sendEmail(array $def, int $recipientId, array $vars): ?array
    {
        $to = $vars['email'] ?? self::resolveEmail($def['audience'], $recipientId);
        if (!$to || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'message' => 'No valid recipient email address on file.'];
        }

        $subject = self::render($def['email_subject'] ?? $def['title'], $vars);
        $body    = self::render($def['email_body'] ?? $def['message'], $vars);

        try {
            require_once __DIR__ . '/Mailer.php';
            return Mailer::fromConfig()->send($to, $subject, self::emailShell($body));
        } catch (\Throwable $e) {
            // email is best-effort — never fatal the caller — but the reason
            // is still worth keeping, not discarding.
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    private const CATEGORY_COLUMNS = ['reminder' => 'notify_reminders', 'announcement' => 'notify_announcements'];

    private static function clientOptedOut(int $clientId, string $category): bool
    {
        $col = self::CATEGORY_COLUMNS[$category] ?? null;
        if (!$col) return false; // unknown category — fail open, never silently swallow a notification
        try {
            ensure_client_notification_prefs();
            $stmt = db()->prepare("SELECT {$col} FROM clients WHERE id = ?");
            $stmt->execute([$clientId]);
            $val = $stmt->fetchColumn();
            return $val !== false && (int) $val === 0;
        } catch (\Throwable $e) {
            return false; // column not migrated yet — fail open
        }
    }

    private static function resolveEmail(string $audience, int $recipientId): ?string
    {
        try {
            $table = $audience === 'admin' ? 'admin_users' : 'clients';
            $stmt  = db()->prepare("SELECT email FROM {$table} WHERE id = ?");
            $stmt->execute([$recipientId]);
            $email = $stmt->fetchColumn();
            return $email ?: null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Build the HTML credential rows for the one-time "your service is
     * ready" email ({account_rows} in the service_ready template). The
     * password is only ever used here, inside a transient email body —
     * it is never written to the notifications table (which stores
     * only the generic in-app title/message) — matching how every
     * hosting provider hands over new account credentials once by email.
     */
    public static function serviceAccountRows(string $domain, string $username, ?string $password, string $server, string $package): string
    {
        $rows = [['Domain', $domain], ['Username', $username]];
        if ($password) $rows[] = ['Password', $password];
        $rows[] = ['Server', $server ?: '—'];
        $rows[] = ['Package', $package ?: 'default'];
        $html = '';
        foreach ($rows as [$k, $v]) {
            $html .= '<tr><td style="padding:6px 0;color:#64748b">' . htmlspecialchars($k, ENT_QUOTES) . '</td>'
                   . '<td style="padding:6px 0;text-align:right;font-weight:700;font-family:monospace">' . htmlspecialchars((string) $v, ENT_QUOTES) . '</td></tr>';
        }
        return $html;
    }

    private static function render(string $tpl, array $vars): string
    {
        return preg_replace_callback('/\{(\w+)\}/', function ($m) use ($vars) {
            return array_key_exists($m[1], $vars) ? (string) $vars[$m[1]] : '';
        }, $tpl);
    }

    private static function emailShell(string $bodyHtml): string
    {
        $logo = SiteSettings::logoImgTag(44, 220);
        if ($logo) {
            $mark = $logo;
        } else {
            $b = SiteSettings::get('branding');
            $mark = '<span style="font-size:18px;font-weight:800;color:#fff">'
                  . htmlspecialchars($b['site_name_primary'] ?: 'Orbit')
                  . '<span style="color:#1A8A45">' . htmlspecialchars($b['site_name_accent'] ?: 'Cloud') . '</span></span>';
        }

        return '<div style="font-family:-apple-system,Segoe UI,Roboto,sans-serif;max-width:520px;margin:auto">'
             . '<div style="background:#0B1E3D;color:#fff;padding:20px 24px;border-radius:12px 12px 0 0">'
             . $mark . '</div>'
             . '<div style="border:1px solid #e3e8f0;border-top:none;border-radius:0 0 12px 12px;padding:24px;color:#334155;font-size:14px;line-height:1.6">'
             . $bodyHtml
             . '<p style="color:#94a3b8;font-size:11.5px;margin-top:20px;border-top:1px solid #f1f5f9;padding-top:14px">This is an automated notification from ' . htmlspecialchars(SiteSettings::brandName()) . '. If you have questions, just reply or open a support ticket from the client portal.</p>'
             . '</div></div>';
    }

    // ── Queries for the bell UI ────────────────────────────────
    public static function unreadCount(string $audience, int $recipientId): int
    {
        if (!self::ensureTables()) return 0;
        $stmt = db()->prepare('SELECT COUNT(*) FROM notifications WHERE audience = ? AND recipient_id = ? AND is_read = 0');
        $stmt->execute([$audience, $recipientId]);
        return (int) $stmt->fetchColumn();
    }

    public static function listFor(string $audience, int $recipientId, int $limit = 20, int $offset = 0): array
    {
        if (!self::ensureTables()) return [];
        $limit  = max(1, min(100, $limit));
        $offset = max(0, $offset);
        $stmt = db()->prepare("SELECT * FROM notifications WHERE audience = ? AND recipient_id = ? ORDER BY created_at DESC LIMIT {$limit} OFFSET {$offset}");
        $stmt->execute([$audience, $recipientId]);
        return $stmt->fetchAll();
    }

    public static function markRead(int $id, string $audience, int $recipientId): void
    {
        db()->prepare('UPDATE notifications SET is_read = 1 WHERE id = ? AND audience = ? AND recipient_id = ?')
            ->execute([$id, $audience, $recipientId]);
    }

    public static function markAllRead(string $audience, int $recipientId): void
    {
        db()->prepare('UPDATE notifications SET is_read = 1 WHERE audience = ? AND recipient_id = ?')
            ->execute([$audience, $recipientId]);
    }
}
