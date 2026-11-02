<?php
/**
 * Orbit Cloud — one-time sign-in codes (email + SMS).
 *
 * After a correct password, the admin panel and the client portal send a
 * 6-digit code to the account's email address AND phone number; the
 * session is only granted once that code is entered. Used by
 * auth_login() (admin/includes/auth.php) and portal_login()
 * (portal/includes/auth.php).
 *
 *   - Codes live 10 minutes, are stored only as a bcrypt hash, and die
 *     after 5 wrong guesses or first use.
 *   - Resend: 30 s cooldown, at most 5 codes per account per 15 minutes.
 *   - The SMS log records a masked message; no in-app notification is
 *     written — a code must never be readable from the database.
 *   - Accounts with authenticator-app 2FA keep that instead (stronger).
 *
 * Switched on per audience in Site Settings › Security. Emergency off
 * switch: LOGIN_OTP_DISABLE=true in .env (e.g. mail AND SMS are down and
 * staff can't get into the admin panel to fix them).
 */
require_once __DIR__ . '/db.php';

final class LoginOtp
{
    public const TTL_MINUTES   = 10;
    public const MAX_ATTEMPTS  = 5;
    public const RESEND_AFTER  = 30;   // seconds
    public const MAX_PER_15MIN = 5;

    public static function ensureSchema(): void
    {
        static $done = false;
        if ($done) return;
        $done = true;
        try {
            db()->exec("CREATE TABLE IF NOT EXISTS login_otps (
                id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                audience    ENUM('admin','client') NOT NULL,
                user_id     INT UNSIGNED NOT NULL,
                code_hash   VARCHAR(255) NOT NULL,
                channels    VARCHAR(60)  NOT NULL DEFAULT '',
                attempts    TINYINT UNSIGNED NOT NULL DEFAULT 0,
                expires_at  DATETIME NOT NULL,
                consumed_at DATETIME DEFAULT NULL,
                ip_address  VARCHAR(45) DEFAULT NULL,
                created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_otp_user (audience, user_id, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (\Throwable $e) {}
        try {
            if (!db()->query("SHOW COLUMNS FROM admin_users LIKE 'phone'")->fetch()) {
                db()->exec('ALTER TABLE admin_users ADD COLUMN phone VARCHAR(30) DEFAULT NULL');
            }
        } catch (\Throwable $e) {}
    }

    /** Is the sign-in code step switched on for this audience? */
    public static function enabled(string $audience): bool
    {
        if (defined('LOGIN_OTP_DISABLE') && filter_var(LOGIN_OTP_DISABLE, FILTER_VALIDATE_BOOLEAN)) return false;
        try {
            require_once __DIR__ . '/SiteSettings.php';
            $s = SiteSettings::get('security');
            return !empty($s[$audience === 'admin' ? 'otp_admins' : 'otp_clients']);
        } catch (\Throwable $e) {
            return false; // settings unreadable — never lock everyone out on a DB hiccup
        }
    }

    /**
     * Create a code and send it to the account's email and phone.
     * @param array $user ['id','email','phone','name']
     * @return array{ok:bool, message:string, sent_to?:string[], wait?:int}
     */
    public static function issue(string $audience, array $user): array
    {
        self::ensureSchema();
        $uid = (int) $user['id'];

        // Throttle: one code per 30 s, five per 15 min. Ages are worked out
        // in MySQL — PHP and MySQL often run in different time zones on
        // shared hosting, so mixing time() with NOW() would be hours off.
        $s = db()->prepare('SELECT TIMESTAMPDIFF(SECOND, created_at, NOW()) FROM login_otps WHERE audience = ? AND user_id = ? ORDER BY id DESC LIMIT 1');
        $s->execute([$audience, $uid]);
        $last = $s->fetchColumn();
        if ($last !== false) {
            $age = (int) $last;
            if ($age < self::RESEND_AFTER) {
                return ['ok' => false, 'wait' => self::RESEND_AFTER - $age,
                        'message' => 'Please wait ' . (self::RESEND_AFTER - $age) . ' seconds before requesting another code.'];
            }
        }
        $s = db()->prepare('SELECT COUNT(*) FROM login_otps WHERE audience = ? AND user_id = ? AND created_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)');
        $s->execute([$audience, $uid]);
        if ((int) $s->fetchColumn() >= self::MAX_PER_15MIN) {
            return ['ok' => false, 'message' => 'Too many codes requested. Please wait 15 minutes and sign in again.'];
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        // Any earlier code for this account is retired: only the newest works.
        db()->prepare('UPDATE login_otps SET consumed_at = NOW() WHERE audience = ? AND user_id = ? AND consumed_at IS NULL')
            ->execute([$audience, $uid]);

        $sent = [];
        $email = trim((string) ($user['email'] ?? ''));
        if ($email !== '' && self::sendEmail($email, (string) ($user['name'] ?? ''), $code, $audience)) {
            $sent[] = self::maskEmail($email);
        }
        $phone = trim((string) ($user['phone'] ?? ''));
        if ($phone !== '' && self::sendSms($phone, $code, $audience === 'client' ? $uid : null)) {
            $sent[] = self::maskPhone($phone);
        }

        if (!$sent) {
            return ['ok' => false, 'message' => 'We couldn\'t send your sign-in code by email or SMS. Please try again shortly, or contact support.'];
        }

        db()->prepare('INSERT INTO login_otps (audience, user_id, code_hash, channels, expires_at, ip_address)
                       VALUES (?,?,?,?, DATE_ADD(NOW(), INTERVAL ' . self::TTL_MINUTES . ' MINUTE), ?)')
            ->execute([$audience, $uid, password_hash($code, PASSWORD_BCRYPT), implode(',', $sent), $_SERVER['REMOTE_ADDR'] ?? null]);

        return ['ok' => true, 'sent_to' => $sent, 'message' => 'Code sent to ' . implode(' and ', $sent) . '.'];
    }

    /** @return array{ok:bool, message:string} */
    public static function verify(string $audience, int $uid, string $code): array
    {
        self::ensureSchema();
        $code = preg_replace('/\D/', '', $code);
        $s = db()->prepare('SELECT *, expires_at < NOW() AS expired FROM login_otps WHERE audience = ? AND user_id = ? AND consumed_at IS NULL ORDER BY id DESC LIMIT 1');
        $s->execute([$audience, $uid]);
        $row = $s->fetch();

        if (!$row) return ['ok' => false, 'message' => 'That code is no longer valid — request a new one.'];
        if ((int) $row['expired'] === 1) {
            return ['ok' => false, 'message' => 'That code has expired — request a new one.'];
        }
        if ((int) $row['attempts'] >= self::MAX_ATTEMPTS) {
            return ['ok' => false, 'message' => 'Too many wrong attempts — request a new code.'];
        }
        if (strlen($code) !== 6 || !password_verify($code, (string) $row['code_hash'])) {
            db()->prepare('UPDATE login_otps SET attempts = attempts + 1 WHERE id = ?')->execute([(int) $row['id']]);
            $left = self::MAX_ATTEMPTS - (int) $row['attempts'] - 1;
            if ($left <= 0) {
                db()->prepare('UPDATE login_otps SET consumed_at = NOW() WHERE id = ?')->execute([(int) $row['id']]);
                return ['ok' => false, 'message' => 'Too many wrong attempts — request a new code.'];
            }
            return ['ok' => false, 'message' => 'Incorrect code. ' . $left . ' attempt' . ($left === 1 ? '' : 's') . ' left.'];
        }
        // Single use: claim it so a replayed form can't reuse the code.
        $claim = db()->prepare('UPDATE login_otps SET consumed_at = NOW() WHERE id = ? AND consumed_at IS NULL');
        $claim->execute([(int) $row['id']]);
        if ($claim->rowCount() === 0) return ['ok' => false, 'message' => 'That code has already been used.'];
        return ['ok' => true, 'message' => 'Verified.'];
    }

    /** Where the latest code went, for the code-entry screen. */
    public static function lastChannels(string $audience, int $uid): array
    {
        self::ensureSchema();
        $s = db()->prepare('SELECT channels FROM login_otps WHERE audience = ? AND user_id = ? ORDER BY id DESC LIMIT 1');
        $s->execute([$audience, $uid]);
        return array_values(array_filter(explode(',', (string) $s->fetchColumn())));
    }

    public static function maskEmail(string $email): string
    {
        [$u, $d] = array_pad(explode('@', $email, 2), 2, '');
        return (strlen($u) <= 2 ? $u[0] . '•' : substr($u, 0, 2) . str_repeat('•', max(2, min(5, strlen($u) - 2)))) . '@' . $d;
    }

    public static function maskPhone(string $phone): string
    {
        $n = preg_replace('/\D+/', '', $phone);
        if (str_starts_with($n, '254') && strlen($n) === 12) $n = '0' . substr($n, 3);
        return strlen($n) >= 7 ? substr($n, 0, 4) . ' ••• ' . substr($n, -3) : '•••';
    }

    // ── Delivery ─────────────────────────────────────────────────

    private static function brand(): string
    {
        try { require_once __DIR__ . '/SiteSettings.php'; return SiteSettings::brandName() ?: 'Orbit Cloud'; }
        catch (\Throwable $e) { return 'Orbit Cloud'; }
    }

    private static function sendEmail(string $to, string $name, string $code, string $audience): bool
    {
        try {
            require_once __DIR__ . '/Mailer.php';
            $brand = htmlspecialchars(self::brand());
            $where = $audience === 'admin' ? 'admin panel' : 'client portal';
            $hello = $name !== '' ? 'Hi ' . htmlspecialchars(explode(' ', trim($name))[0]) . ',' : 'Hello,';
            $html = '<div style="font-family:Segoe UI,Arial,sans-serif;max-width:480px;margin:0 auto;padding:24px;color:#1b2739">'
                  . '<p style="font-size:15px">' . $hello . '</p>'
                  . '<p style="font-size:15px">Use this code to finish signing in to the ' . $brand . ' ' . $where . ':</p>'
                  . '<div style="font-size:34px;font-weight:800;letter-spacing:10px;text-align:center;background:#f1f5f9;border-radius:12px;padding:18px 0;margin:18px 0;font-family:Consolas,monospace">' . $code . '</div>'
                  . '<p style="font-size:13px;color:#64748b">It expires in ' . self::TTL_MINUTES . ' minutes and can only be used once. '
                  . 'If you didn\'t just try to sign in, someone may know your password — change it right away.</p>'
                  . '<p style="font-size:13px;color:#64748b">' . $brand . ' will never ask you for this code by phone.</p></div>';
            $r = Mailer::fromConfig()->send($to, $code . ' is your ' . self::brand() . ' sign-in code', $html);
            if (empty($r['success'])) error_log('LoginOtp email failed: ' . ($r['message'] ?? 'unknown'));
            return !empty($r['success']);
        } catch (\Throwable $e) {
            error_log('LoginOtp email error: ' . $e->getMessage());
            return false;
        }
    }

    /** Straight to the SMS gateway — the log keeps a MASKED copy only. */
    private static function sendSms(string $phone, string $code, ?int $client_id): bool
    {
        try {
            require_once __DIR__ . '/Sms.php';
            $client = Sms::client();
            $to = SmsClient::normalizePhone($phone);
            if (!$client || !$to) return false;
            $r = $client->send($to, self::brand() . ': ' . $code . ' is your sign-in code. It expires in ' . self::TTL_MINUTES . ' minutes. Never share it.');
            try {
                Sms::ensureSchema();
                db()->prepare("INSERT INTO sms_messages (client_id, phone, message, type, status, provider_msg_id, units, next_attempt_at, sent_at, error)
                               VALUES (?,?,?,'login_otp',?,?,?,NOW(),?,?)")
                    ->execute([$client_id, $to, self::brand() . ': •••••• is your sign-in code (masked).',
                               $r['success'] ? 'sent' : 'failed', $r['message_id'] ?? null, $r['units'] ?? null,
                               $r['success'] ? date('Y-m-d H:i:s') : null, $r['success'] ? null : mb_substr((string) ($r['message'] ?? ''), 0, 255)]);
            } catch (\Throwable $e) {}
            return !empty($r['success']);
        } catch (\Throwable $e) {
            error_log('LoginOtp SMS error: ' . $e->getMessage());
            return false;
        }
    }
}
