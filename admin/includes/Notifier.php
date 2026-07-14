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
            return true;
        } catch (\Throwable $e) {
            return false;
        }
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

        $title   = self::render($def['title'], $vars);
        $message = self::render($def['message'], $vars);
        $link    = $vars['link'] ?? null;

        try {
            db()->prepare('INSERT INTO notifications (audience, recipient_id, type, title, message, link) VALUES (?,?,?,?,?,?)')
                ->execute([$def['audience'], $recipientId, $type, $title, $message, $link]);
        } catch (\Throwable $e) { /* in-app is best-effort too — never fatal the caller */ }

        if (!empty($def['email'])) {
            self::sendEmail($def, $recipientId, $vars);
        }
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

    private static function sendEmail(array $def, int $recipientId, array $vars): void
    {
        $to = $vars['email'] ?? self::resolveEmail($def['audience'], $recipientId);
        if (!$to || !filter_var($to, FILTER_VALIDATE_EMAIL)) return;

        $subject = self::render($def['email_subject'] ?? $def['title'], $vars);
        $body    = self::render($def['email_body'] ?? $def['message'], $vars);

        try {
            require_once __DIR__ . '/Mailer.php';
            Mailer::fromConfig()->send($to, $subject, self::emailShell($body));
        } catch (\Throwable $e) { /* email is best-effort — never fatal the caller */ }
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
